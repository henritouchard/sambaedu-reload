<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ControlHubLinkState;
use App\Events\ControlHubContractChanged;
use App\Exceptions\ControlHub\InvalidUpstreamContractException;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Services\ControlHub\ControlHubContractIngestionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Story 28.2 — Ingestion idempotente du contrat amont (controlHub).
 *
 * Couverture des AC #1 à #7 :
 * - #1 1re réception : persistance + lien actif + received_at + enfants exacts.
 * - #2 réception identique : no-op (mutated=false, comptes & timestamps inchangés, aucun event).
 * - #3 réception modifiée : upsert + prune, aucune violation d'unicité, event émis 1×.
 * - #4 normalisation null→'' de target_label (test RÉVÉLATEUR du finding 28.1 #1) + idempotence.
 * - #5 singleton : au plus un contrat actif par instance.
 * - #6 enum hors domaine / incohérence cible : rejet + aucune écriture partielle (rollback).
 * - #7c R3 : aucun identifiant livré ne contient « central ».
 *
 * ⚠️ Tests sur HÔTE (php8.4 + pdo_sqlite) — JAMAIS sur la VM (sans pdo_sqlite).
 * ⚠️ Idempotence mesurée par comptage de lignes + `mutated` + dispatch d'event
 *    (PAS par contrainte de chaîne / NULL — pièges SQLite, findings 28.1 #1/#2).
 */
class ControlHubContractIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ControlHubContractIngestionService
    {
        return new ControlHubContractIngestionService();
    }

    /**
     * Payload de référence : 2 items (1 instance, 1 label), 2 labels, 1 groupe imposé, 2 apps.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'items' => [
                ['type' => 'capabilities', 'key' => 'cap_show_ext', 'value' => 'on', 'enforcement_state' => 'locked', 'target_type' => 'instance'],
                ['type' => 'wallpapers', 'key' => 'wp_default', 'value' => 'corp.jpg', 'enforcement_state' => 'permissive', 'target_type' => 'label', 'target_label' => 'salle-info'],
            ],
            'labels' => [
                ['name' => 'salle-info', 'mode' => 'reserved'],
                ['name' => 'nomade', 'mode' => 'free'],
            ],
            'imposed_groups' => [
                ['name' => 'parc-terminales', 'label_name' => 'salle-info'],
            ],
            'catalog_apps' => [
                ['app_key' => 'firefox', 'display_name' => 'Firefox'],
                ['app_key' => 'libreoffice', 'display_name' => 'LibreOffice'],
            ],
        ], $overrides);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC #1 — Première réception
    // ──────────────────────────────────────────────────────────────────────────

    public function test_first_reception_persists_contract_and_activates_link(): void
    {
        $result = $this->service()->ingest($this->payload());

        $this->assertTrue($result->contractCreated);
        $this->assertTrue($result->mutated);

        $this->assertDatabaseCount('controlhub_contracts', 1);
        $this->assertDatabaseCount('controlhub_contract_items', 2);
        $this->assertDatabaseCount('controlhub_contract_labels', 2);
        $this->assertDatabaseCount('controlhub_contract_imposed_groups', 1);
        $this->assertDatabaseCount('controlhub_contract_catalog_apps', 2);

        $contract = ControlHubContract::firstOrFail();
        $this->assertSame(ControlHubLinkState::Active, $contract->link_state);
        $this->assertNotNull($contract->received_at);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC #2 — Réception identique = no-op (aucune écriture, aucun event)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_identical_reception_is_noop(): void
    {
        $this->service()->ingest($this->payload());

        $before = ControlHubContract::firstOrFail();
        $receivedAtBefore = $before->received_at->toISOString();
        $updatedAtBefore = $before->updated_at->toISOString();

        // Avancer le temps : si une écriture avait lieu, les timestamps changeraient.
        Carbon::setTestNow(now()->addHour());

        Event::fake();
        $result = $this->service()->ingest($this->payload());

        $this->assertFalse($result->mutated, 'Une réception identique doit être un no-op.');
        Event::assertNotDispatched(ControlHubContractChanged::class);

        // Comptes inchangés
        $this->assertDatabaseCount('controlhub_contracts', 1);
        $this->assertDatabaseCount('controlhub_contract_items', 2);
        $this->assertDatabaseCount('controlhub_contract_labels', 2);
        $this->assertDatabaseCount('controlhub_contract_imposed_groups', 1);
        $this->assertDatabaseCount('controlhub_contract_catalog_apps', 2);

        // Timestamps fonctionnels du contrat inchangés
        $after = ControlHubContract::firstOrFail();
        $this->assertSame($receivedAtBefore, $after->received_at->toISOString());
        $this->assertSame($updatedAtBefore, $after->updated_at->toISOString());

        Carbon::setTestNow();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC #3 — Réception modifiée : upsert + prune, event émis 1×
    // ──────────────────────────────────────────────────────────────────────────

    public function test_changed_reception_reconciles_and_emits_event(): void
    {
        $this->service()->ingest($this->payload());

        Event::fake();

        $changed = $this->payload([
            'items' => [
                // cap_show_ext : valeur modifiée 'on' → 'off' (UPDATE, même clé naturelle)
                ['type' => 'capabilities', 'key' => 'cap_show_ext', 'value' => 'off', 'enforcement_state' => 'locked', 'target_type' => 'instance'],
                // nouvel item (CREATE) ; l'item wallpaper/label disparaît (PRUNE)
                ['type' => 'capabilities', 'key' => 'cap_rdp', 'value' => 'off', 'enforcement_state' => 'locked', 'target_type' => 'instance'],
            ],
            'labels' => [
                ['name' => 'salle-info', 'mode' => 'reserved'], // inchangé
                ['name' => 'labo', 'mode' => 'free'],            // CREATE ; 'nomade' PRUNE
            ],
            'imposed_groups' => [
                ['name' => 'parc-terminales', 'label_name' => 'labo'], // UPDATE label_name
            ],
            'catalog_apps' => [
                ['app_key' => 'firefox', 'display_name' => 'Firefox'], // inchangé ; libreoffice PRUNE
            ],
        ]);

        $result = $this->service()->ingest($changed);

        $this->assertTrue($result->mutated);
        Event::assertDispatchedTimes(ControlHubContractChanged::class, 1);

        // Aucune violation d'unicité (le test aurait jeté une QueryException sinon)
        $this->assertDatabaseCount('controlhub_contracts', 1);
        $this->assertDatabaseCount('controlhub_contract_items', 2);
        $this->assertDatabaseCount('controlhub_contract_labels', 2);
        $this->assertDatabaseCount('controlhub_contract_imposed_groups', 1);
        $this->assertDatabaseCount('controlhub_contract_catalog_apps', 1);

        // upsert effectif
        $this->assertDatabaseHas('controlhub_contract_items', ['key' => 'cap_show_ext', 'value' => 'off']);
        $this->assertDatabaseHas('controlhub_contract_items', ['key' => 'cap_rdp']);
        $this->assertDatabaseMissing('controlhub_contract_items', ['key' => 'wp_default']);
        $this->assertDatabaseHas('controlhub_contract_labels', ['name' => 'labo']);
        $this->assertDatabaseMissing('controlhub_contract_labels', ['name' => 'nomade']);
        $this->assertDatabaseHas('controlhub_contract_imposed_groups', ['name' => 'parc-terminales', 'label_name' => 'labo']);
        $this->assertDatabaseMissing('controlhub_contract_catalog_apps', ['app_key' => 'libreoffice']);

        // Compteurs de réconciliation — les 4 agrégats vérifiés (F3 review : couverture complète).
        $this->assertSame(1, $result->items['created']);
        $this->assertSame(1, $result->items['updated']);
        $this->assertSame(1, $result->items['deleted']);
        $this->assertSame(1, $result->labels['created']);   // 'labo'
        $this->assertSame(0, $result->labels['updated']);    // 'salle-info' inchangé
        $this->assertSame(1, $result->labels['deleted']);    // 'nomade'
        $this->assertSame(0, $result->imposedGroups['created']);
        $this->assertSame(1, $result->imposedGroups['updated']); // label_name salle-info → labo
        $this->assertSame(0, $result->imposedGroups['deleted']);
        $this->assertSame(0, $result->catalogApps['created']);
        $this->assertSame(0, $result->catalogApps['updated']);   // 'firefox' inchangé
        $this->assertSame(1, $result->catalogApps['deleted']);   // 'libreoffice'
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC #4 — Normalisation null→'' + idempotence (test RÉVÉLATEUR finding 28.1 #1)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_target_label_null_is_normalized_and_idempotent(): void
    {
        // 3 items instance dont le target_label est exprimé null / absent / '' .
        $first = [
            'items' => [
                ['type' => 'capabilities', 'key' => 'a', 'value' => 'on', 'enforcement_state' => 'locked', 'target_type' => 'instance', 'target_label' => null],
                ['type' => 'capabilities', 'key' => 'b', 'value' => 'on', 'enforcement_state' => 'locked', 'target_type' => 'instance'], // absent
                ['type' => 'capabilities', 'key' => 'c', 'value' => 'on', 'enforcement_state' => 'locked', 'target_type' => 'instance', 'target_label' => ''],
            ],
        ];

        $result = $this->service()->ingest($first);
        $this->assertTrue($result->mutated);
        $this->assertDatabaseCount('controlhub_contract_items', 3);

        // Tous persistés avec target_label = '' (chaîne vide), JAMAIS null.
        foreach (ControlHubContractItem::all() as $item) {
            $this->assertSame('', $item->target_label);
        }

        // Seconde réception : MÊME contrat, target_label tantôt '', tantôt null, tantôt absent.
        // Sans normalisation null→'', ce serait un churn (doublons/violation) → ce test échouerait.
        $second = [
            'items' => [
                ['type' => 'capabilities', 'key' => 'a', 'value' => 'on', 'enforcement_state' => 'locked', 'target_type' => 'instance', 'target_label' => ''],
                ['type' => 'capabilities', 'key' => 'b', 'value' => 'on', 'enforcement_state' => 'locked', 'target_type' => 'instance', 'target_label' => null],
                ['type' => 'capabilities', 'key' => 'c', 'value' => 'on', 'enforcement_state' => 'locked', 'target_type' => 'instance'], // absent
            ],
        ];

        Event::fake();
        $result2 = $this->service()->ingest($second);

        $this->assertFalse($result2->mutated, 'La normalisation null→\'\' doit préserver le no-op.');
        Event::assertNotDispatched(ControlHubContractChanged::class);
        $this->assertDatabaseCount('controlhub_contract_items', 3);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC #5 — Singleton : au plus un contrat actif
    // ──────────────────────────────────────────────────────────────────────────

    public function test_singleton_active_contract(): void
    {
        // 1re réception → crée l'unique contrat actif.
        $first = $this->service()->ingest($this->payload());
        $this->assertTrue($first->contractCreated);

        // 2e réception au contenu MODIFIÉ → réutilise le contrat actif, n'en crée pas un 2e
        // (modèle mono-autorité : le singleton est tenu par link_state, sans référence d'émetteur).
        Event::fake();
        $second = $this->service()->ingest($this->payload([
            'items' => [
                ['type' => 'capabilities', 'key' => 'cap_show_ext', 'value' => 'off', 'enforcement_state' => 'locked', 'target_type' => 'instance'],
            ],
        ]));

        $this->assertFalse($second->contractCreated, 'La 2e réception réutilise le contrat actif.');
        $this->assertTrue($second->mutated);
        Event::assertDispatchedTimes(ControlHubContractChanged::class, 1);

        $this->assertSame(
            1,
            ControlHubContract::query()->where('link_state', ControlHubLinkState::Active->value)->count(),
        );
        $this->assertDatabaseCount('controlhub_contracts', 1);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC #6 — Validation + rollback total
    // ──────────────────────────────────────────────────────────────────────────

    public function test_invalid_enum_rejected_and_no_partial_write(): void
    {
        $invalidPayloads = [
            'enforcement_state hors domaine' => $this->payload([
                'items' => [['type' => 'capabilities', 'key' => 'x', 'value' => 'on', 'enforcement_state' => 'bogus', 'target_type' => 'instance']],
            ]),
            'target_type hors domaine' => $this->payload([
                'items' => [['type' => 'capabilities', 'key' => 'x', 'value' => 'on', 'enforcement_state' => 'locked', 'target_type' => 'bogus']],
            ]),
            'label mode hors domaine' => $this->payload([
                'labels' => [['name' => 'salle-info', 'mode' => 'bogus']],
            ]),
            'incohérence label sans target_label' => $this->payload([
                'items' => [['type' => 'capabilities', 'key' => 'x', 'value' => 'on', 'enforcement_state' => 'locked', 'target_type' => 'label', 'target_label' => '']],
            ]),
            'incohérence instance avec target_label' => $this->payload([
                'items' => [['type' => 'capabilities', 'key' => 'x', 'value' => 'on', 'enforcement_state' => 'locked', 'target_type' => 'instance', 'target_label' => 'salle-info']],
            ]),
        ];

        foreach ($invalidPayloads as $label => $payload) {
            $threw = false;
            try {
                $this->service()->ingest($payload);
            } catch (InvalidUpstreamContractException $e) {
                $threw = true;
            }
            $this->assertTrue($threw, "Le payload « {$label} » aurait dû être rejeté.");
        }

        // Aucune écriture partielle : les 5 tables restent strictement vides (F4 review : 5/5).
        $this->assertDatabaseCount('controlhub_contracts', 0);
        $this->assertDatabaseCount('controlhub_contract_items', 0);
        $this->assertDatabaseCount('controlhub_contract_labels', 0);
        $this->assertDatabaseCount('controlhub_contract_imposed_groups', 0);
        $this->assertDatabaseCount('controlhub_contract_catalog_apps', 0);
    }

    public function test_invalid_payload_leaves_existing_state_unchanged(): void
    {
        // Un contrat valide préexiste.
        $this->service()->ingest($this->payload());
        $this->assertDatabaseCount('controlhub_contract_items', 2);

        $before = ControlHubContract::firstOrFail();
        $updatedAtBefore = $before->updated_at->toISOString();
        Carbon::setTestNow(now()->addHour());

        // Payload invalide reçu → rollback total, aucune mutation.
        try {
            $this->service()->ingest($this->payload([
                'items' => [['type' => 'capabilities', 'key' => 'cap_show_ext', 'value' => 'on', 'enforcement_state' => 'bogus', 'target_type' => 'instance']],
            ]));
            $this->fail('Une exception InvalidUpstreamContractException était attendue.');
        } catch (InvalidUpstreamContractException $e) {
            // attendu
        }

        $this->assertDatabaseCount('controlhub_contracts', 1);
        $this->assertDatabaseCount('controlhub_contract_items', 2);
        $this->assertSame($updatedAtBefore, ControlHubContract::firstOrFail()->updated_at->toISOString());

        Carbon::setTestNow();
    }

    public function test_empty_aggregates_prune_to_zero_children(): void
    {
        $this->service()->ingest($this->payload());
        $this->assertDatabaseCount('controlhub_contract_items', 2);

        // Réception d'un contrat dénué de tout enfant → prune complet, mais contrat conservé.
        $result = $this->service()->ingest([
            'items' => [],
            'labels' => [],
            'imposed_groups' => [],
            'catalog_apps' => [],
        ]);

        $this->assertTrue($result->mutated);
        $this->assertDatabaseCount('controlhub_contracts', 1);
        $this->assertDatabaseCount('controlhub_contract_items', 0);
        $this->assertDatabaseCount('controlhub_contract_labels', 0);
        $this->assertDatabaseCount('controlhub_contract_imposed_groups', 0);
        $this->assertDatabaseCount('controlhub_contract_catalog_apps', 0);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC #7c — Garde-fou R3 : aucun identifiant livré ne contient « central »
    // ──────────────────────────────────────────────────────────────────────────

    public function test_r3_no_central_identifier(): void
    {
        $deliveredFqcns = [
            ControlHubContractIngestionService::class,
            \App\Services\ControlHub\Data\ContractIngestionResult::class,
            ControlHubContractChanged::class,
            InvalidUpstreamContractException::class,
        ];

        foreach ($deliveredFqcns as $fqcn) {
            $this->assertStringNotContainsStringIgnoringCase('central', $fqcn, "FQCN « {$fqcn} » ne doit pas contenir « central ».");

            $reflection = new \ReflectionClass($fqcn);
            foreach ($reflection->getMethods() as $method) {
                $this->assertStringNotContainsStringIgnoringCase('central', $method->getName(), "Méthode {$fqcn}::{$method->getName()} ne doit pas contenir « central ».");
            }
            foreach ($reflection->getProperties() as $property) {
                $this->assertStringNotContainsStringIgnoringCase('central', $property->getName());
            }
            foreach ($reflection->getConstants() as $constName => $constValue) {
                $this->assertStringNotContainsStringIgnoringCase('central', (string) $constName);
            }
        }
    }

    /**
     * Garde-fou : la réception ne lève jamais de QueryException d'unicité (clé naturelle respectée).
     */
    public function test_no_unique_violation_on_repeated_ingestion(): void
    {
        try {
            $this->service()->ingest($this->payload());
            $this->service()->ingest($this->payload());
            $this->service()->ingest($this->payload());
        } catch (QueryException $e) {
            $this->fail('Aucune QueryException ne doit survenir sur réception répétée : '.$e->getMessage());
        }

        $this->assertDatabaseCount('controlhub_contract_items', 2);
    }
}
