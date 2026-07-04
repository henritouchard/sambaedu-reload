<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ControlHubArtifactPullStatus;
use App\Events\ControlHubContractChanged;
use App\Jobs\ControlHub\PullContractArtifactJob;
use App\Models\AgentTool;
use App\Models\WallpaperAsset;
use App\Services\ControlHub\ControlHubContractIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Story 39.4 — Canal ④ : ingestion + persistance ADDITIVE des champs `delivery_mode` / `artifact`
 * (items) et `executable` (catalog_apps), + déclenchement du pull (précédence locale, dispatch).
 *
 * Couverture ciblée :
 * - AC4  : persistance additive des nouveaux champs, no-op à 0 binaire strictement préservé.
 * - AC5  : PIÈGE D'IDEMPOTENCE — ré-ingestion à URL différente / checksum identique → mutated=false,
 *          aucun événement, AUCUN nouveau job de pull (LE garde-fou de non-régression de la story).
 * - AC6  : `delivery_mode` inconnu accepté sans rejet, stocké tel quel.
 * - AC7  : `executable` persisté (checksum/filename/size), AUCUN pull déclenché pour catalog_apps.
 * - AC8  : dispatch conditionnel à la précédence locale (asset absent → pending + job ;
 *          asset présent → aucun job).
 *
 * ⚠️ Tests HÔTE (php8.4 + pdo_sqlite). QUEUE_CONNECTION=sync ⇒ Bus::fake() OBLIGATOIRE pour
 *    intercepter le dispatch du job (sinon il s'exécuterait et tenterait un vrai HTTP).
 */
class UpstreamArtifactIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ControlHubContractIngestionService
    {
        return new ControlHubContractIngestionService();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'items' => [
                ['type' => 'capabilities', 'key' => 'cap_show_ext', 'value' => 'on', 'enforcement_state' => 'locked', 'target_type' => 'instance'],
            ],
            'labels' => [],
            'imposed_groups' => [],
            'catalog_apps' => [],
        ], $overrides);
    }

    // ── AC4 — persistance additive + no-op à 0 binaire ────────────────────────

    public function test_delivery_mode_and_artifact_persisted_on_wallpaper_item(): void
    {
        Bus::fake();

        $this->service()->ingest($this->payload([
            'items' => [
                [
                    'type' => 'wallpapers',
                    'key' => 'wp_default',
                    'value' => 'corp.jpg',
                    'enforcement_state' => 'locked',
                    'target_type' => 'instance',
                    'delivery_mode' => 'download_direct',
                    'artifact' => [
                        'url' => 'https://cdn.example/signed/AAA?sig=1',
                        'checksum' => str_repeat('a', 64),
                        'filename' => 'corporate-wallpaper.png',
                        'size' => 12345,
                    ],
                ],
            ],
        ]));

        $this->assertDatabaseHas('controlhub_contract_items', [
            'type' => 'wallpapers',
            'key' => 'wp_default',
            'delivery_mode' => 'download_direct',
            'artifact_checksum' => str_repeat('a', 64),
            'artifact_filename' => 'corporate-wallpaper.png',
            'artifact_size' => 12345,
            // AC2/AC5 : l'URL n'est PAS une colonne (aucune assertion possible dessus).
            'pull_status' => ControlHubArtifactPullStatus::Pending->value,
        ]);

        // Asset absent localement ⇒ un job de pull est dispatché avec l'URL EN ARGUMENT.
        Bus::assertDispatched(PullContractArtifactJob::class, function (PullContractArtifactJob $job): bool {
            return $job->type === 'wallpapers'
                && $job->key === 'wp_default'
                && $job->checksum === str_repeat('a', 64)
                && $job->url === 'https://cdn.example/signed/AAA?sig=1'
                && $job->filename === 'corporate-wallpaper.png'
                && $job->size === 12345;
        });
    }

    public function test_payload_without_artifact_is_unchanged_and_dispatches_no_pull(): void
    {
        Bus::fake();

        // Un item wallpapers SANS bloc artifact : comportement byte-identique à l'existant.
        $result = $this->service()->ingest($this->payload([
            'items' => [
                ['type' => 'wallpapers', 'key' => 'wp_default', 'value' => 'corp.jpg', 'enforcement_state' => 'locked', 'target_type' => 'instance'],
            ],
        ]));

        $this->assertTrue($result->mutated);
        $this->assertDatabaseHas('controlhub_contract_items', [
            'key' => 'wp_default',
            'delivery_mode' => null,
            'artifact_checksum' => null,
            'artifact_filename' => null,
            'artifact_size' => null,
            'pull_status' => null,
        ]);

        // Aucun artefact ⇒ AUCUN job de pull (comportement à 0 binaire strictement inchangé — AC10).
        Bus::assertNotDispatched(PullContractArtifactJob::class);
    }

    public function test_empty_string_artifact_fields_are_normalized_to_null(): void
    {
        Bus::fake();

        $this->service()->ingest($this->payload([
            'items' => [
                [
                    'type' => 'wallpapers', 'key' => 'wp_default', 'value' => 'corp.jpg',
                    'enforcement_state' => 'locked', 'target_type' => 'instance',
                    'delivery_mode' => '',
                    'artifact' => ['url' => '', 'checksum' => '', 'filename' => '', 'size' => null],
                ],
            ],
        ]));

        $this->assertDatabaseHas('controlhub_contract_items', [
            'key' => 'wp_default',
            'delivery_mode' => null,
            'artifact_checksum' => null,
            'artifact_filename' => null,
            'artifact_size' => null,
        ]);

        // Checksum/url vides ⇒ artefact incomplet ⇒ pas de pull.
        Bus::assertNotDispatched(PullContractArtifactJob::class);
    }

    // ── AC5 — LE piège d'idempotence : URL différente, checksum identique = no-op ──

    public function test_reingest_same_checksum_different_url_is_noop_and_dispatches_no_new_pull(): void
    {
        Bus::fake();
        Event::fake();

        $withUrl = fn (string $url): array => $this->payload([
            'items' => [
                [
                    'type' => 'wallpapers', 'key' => 'wp_default', 'value' => 'corp.jpg',
                    'enforcement_state' => 'locked', 'target_type' => 'instance',
                    'artifact' => [
                        'url' => $url,
                        'checksum' => str_repeat('b', 64), // IDENTIQUE entre les deux réceptions
                        'filename' => 'corp.png',
                        'size' => 999,
                    ],
                ],
            ],
        ]);

        // 1re réception : mutation + 1 job dispatché.
        $first = $this->service()->ingest($withUrl('https://cdn.example/signed/URL-A?sig=aaa'));
        $this->assertTrue($first->mutated);

        // 2e réception : SEULE l'URL signée diffère (régénérée à l'émission) ; checksum identique.
        $second = $this->service()->ingest($withUrl('https://cdn.example/signed/URL-B?sig=bbb'));

        // Le garde-fou : aucune mutation, aucun événement, AUCUN nouveau job de pull.
        $this->assertFalse($second->mutated, 'Une URL régénérée ne doit JAMAIS provoquer de mutation (AC5).');
        Event::assertDispatchedTimes(ControlHubContractChanged::class, 1);      // 1re réception uniquement
        Bus::assertDispatchedTimes(PullContractArtifactJob::class, 1);           // 1re réception uniquement

        $this->assertDatabaseCount('controlhub_contract_items', 1);
    }

    // ── AC6 — delivery_mode inconnu accepté, non arbitré ──────────────────────

    public function test_unknown_delivery_mode_is_accepted_and_stored(): void
    {
        Bus::fake();

        $result = $this->service()->ingest($this->payload([
            'items' => [
                [
                    'type' => 'capabilities', 'key' => 'cap_x', 'value' => 'on',
                    'enforcement_state' => 'locked', 'target_type' => 'instance',
                    'delivery_mode' => 'un-mode-totalement-inconnu',
                ],
            ],
        ]));

        $this->assertTrue($result->mutated);
        $this->assertDatabaseHas('controlhub_contract_items', [
            'key' => 'cap_x',
            'delivery_mode' => 'un-mode-totalement-inconnu',
        ]);
    }

    // ── AC7 — executable persisté SANS pull ───────────────────────────────────

    public function test_catalog_app_executable_is_persisted_without_any_pull(): void
    {
        Bus::fake();

        $this->service()->ingest($this->payload([
            'catalog_apps' => [
                [
                    'app_key' => 'firefox',
                    'display_name' => 'Firefox',
                    'executable' => [
                        'url' => 'https://cdn.example/signed/ff.exe?sig=1',
                        'checksum' => str_repeat('c', 64),
                        'filename' => 'firefox-setup.exe',
                        'size' => 55555,
                    ],
                ],
            ],
        ]));

        $this->assertDatabaseHas('controlhub_contract_catalog_apps', [
            'app_key' => 'firefox',
            'executable_checksum' => str_repeat('c', 64),
            'executable_filename' => 'firefox-setup.exe',
            'executable_size' => 55555,
        ]);

        // AC7 : persistance SEULE — aucun job de pull pour catalog_apps.executable.
        Bus::assertNotDispatched(PullContractArtifactJob::class);
    }

    // ── AC8 — précédence locale : asset déjà présent ⇒ aucun pull ─────────────

    public function test_local_wallpaper_present_by_checksum_dispatches_no_pull(): void
    {
        Bus::fake();

        // Asset déjà en bibliothèque (par checksum) : le contrat ne doit RIEN tirer.
        WallpaperAsset::query()->create([
            'filename' => str_repeat('d', 64) . '.jpg',
            'checksum' => str_repeat('d', 64),
            'byte_size' => 10,
            'uploaded_by' => null,
        ]);

        $this->service()->ingest($this->payload([
            'items' => [
                [
                    'type' => 'wallpapers', 'key' => 'wp_default', 'value' => 'corp.jpg',
                    'enforcement_state' => 'locked', 'target_type' => 'instance',
                    'artifact' => ['url' => 'https://cdn.example/x?sig=1', 'checksum' => str_repeat('d', 64), 'filename' => 'x.png', 'size' => 10],
                ],
            ],
        ]));

        Bus::assertNotDispatched(PullContractArtifactJob::class);
        // Présent localement ⇒ pull_status laissé null (rien à faire).
        $this->assertDatabaseHas('controlhub_contract_items', ['key' => 'wp_default', 'pull_status' => null]);
    }

    public function test_local_agent_tool_present_by_key_dispatches_no_pull(): void
    {
        Bus::fake();

        AgentTool::query()->create([
            'key' => 'rainmeter',
            'name' => 'Rainmeter',
            'filename' => 'sambaedu-rainmeter-1.0.zip',
            'sha256' => str_repeat('e', 64),
            'size' => 20,
            'enabled' => false,
            'uploaded_at' => now(),
            'uploaded_by' => null,
        ]);

        $this->service()->ingest($this->payload([
            'items' => [
                [
                    'type' => 'agent_tools', 'key' => 'rainmeter', 'value' => null,
                    'enforcement_state' => 'locked', 'target_type' => 'instance',
                    // Checksum DIFFÉRENT de l'outil local : la précédence par-clé prime quand même.
                    'artifact' => ['url' => 'https://cdn.example/rm?sig=1', 'checksum' => str_repeat('f', 64), 'filename' => 'rm.zip', 'size' => 20],
                ],
            ],
        ]));

        Bus::assertNotDispatched(PullContractArtifactJob::class);
        $this->assertDatabaseHas('controlhub_contract_items', ['key' => 'rainmeter', 'pull_status' => null]);
    }
}
