<?php

declare(strict_types=1);

namespace Tests\Feature\Nextcloud;

use App\Models\User;
use App\Services\FilePolicyService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\Nextcloud\NextcloudMountAction;
use App\Services\Nextcloud\NextcloudProvisioningService;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 61.1 — le provisionnement, sans réseau.
 *
 * Les doubles rejouent les corps RÉELS mesurés sur l'instance de sondage le
 * 2026-08-08 — **slash initial du point de montage compris**. C'est le seul moyen
 * d'éprouver l'idempotence : un double qui rendrait ce que SE5 envoie laisserait
 * passer la divergence permanente que la normalisation existe pour fermer.
 */
class NextcloudProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://cloud.etab.fr';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('file')->forget(NextcloudProvisioningService::LOCK_KEY);
    }

    protected function tearDown(): void
    {
        Cache::store('file')->forget(NextcloudProvisioningService::LOCK_KEY);
        parent::tearDown();
    }

    private function configure(
        bool $enabled = true,
        string $adminUser = 'admin',
        string $secret = 'sekret',
        string $smbHost = 'se4fs',
    ): void {
        FilePolicyService::setGlobal(true, true, $enabled, self::URL, $adminUser, $smbHost, true);

        if ($secret !== '') {
            app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, $secret);
        }
    }

    private function service(): NextcloudProvisioningService
    {
        return app(NextcloudProvisioningService::class);
    }

    /** @param array<string, mixed> $data */
    private static function ocs(int $code, array $data = [], string $message = 'OK'): array
    {
        return ['ocs' => [
            'meta' => ['status' => $code < 300 ? 'ok' : 'failure', 'statuscode' => $code, 'message' => $message],
            'data' => $data,
        ]];
    }

    /** Corps RÉEL d'un montage, tel que l'instance le rend (slash initial compris). */
    private static function remoteMount(int $id, string $mountPoint, string $share, string $root): array
    {
        return [
            'id' => $id,
            'mountPoint' => '/' . $mountPoint,
            'backend' => 'smb',
            'authMechanism' => 'password::sessioncredentials',
            'backendOptions' => ['host' => 'se4fs', 'share' => $share, 'root' => $root],
            'priority' => 100,
            'mountOptions' => ['enable_sharing' => false],
            'status' => 4,
            'statusMessage' => 'Storage unauthorized. Session unavailable',
            'userProvided' => false,
            'type' => 'system',
        ];
    }

    // =====================================================================
    // AC1 — fail-closed sur la configuration
    // =====================================================================

    #[Test]
    public function a_disabled_capability_refuses_by_name_and_emits_no_call_at_all(): void
    {
        Http::fake();
        $this->configure(enabled: false);

        $report = $this->service()->run();

        self::assertSame(2, $report->exitCode());
        self::assertStringContainsString('Accès Nextcloud', (string) $report->refusal());
        Http::assertNothingSent();
    }

    #[Test]
    public function a_missing_secret_refuses_by_name_and_emits_no_call_at_all(): void
    {
        Http::fake();
        FilePolicyService::setGlobal(true, true, true, self::URL, 'admin', 'se4fs', true);

        $report = $this->service()->run();

        self::assertSame(2, $report->exitCode());
        self::assertStringContainsString('app password admin', (string) $report->refusal());
        Http::assertNothingSent();
    }

    #[Test]
    public function an_unreachable_instance_stops_before_the_first_write(): void
    {
        $this->configure();
        Http::fake(['*' => Http::response('nope', 403)]);

        $report = $this->service()->run();

        self::assertSame(2, $report->exitCode());
        self::assertSame([], $report->mounts());
        Http::assertSentCount(2); // capabilities + lecture des montages : aucune écriture
    }

    // =====================================================================
    // AC3 — les montages, idempotents par signature
    // =====================================================================

    #[Test]
    public function it_creates_the_two_canonical_mounts_on_a_bare_instance(): void
    {
        $this->configure();
        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            self::URL . '/index.php/apps/files_external/globalstorages' => Http::sequence()
                ->push([], 200)          // probe
                ->push([], 200)          // lecture avant écriture
                ->push(['id' => 1], 201) // Partages
                ->push(['id' => 2], 201), // Documents
        ]);

        $report = $this->service()->run(withUsers: false);

        self::assertSame(0, $report->exitCode());
        self::assertSame(
            [NextcloudMountAction::Cree->value, NextcloudMountAction::Cree->value],
            array_column($report->mounts(), 'action'),
        );
        self::assertSame(['Partages', 'Documents'], array_column($report->mounts(), 'name'));
    }

    /** LE test pivot : rejouer ne crée AUCUN doublon. */
    #[Test]
    public function replaying_creates_no_duplicate_and_reports_conforming(): void
    {
        $this->configure();

        $existing = [
            self::remoteMount(1, 'Partages', 'partages', ''),
            self::remoteMount(2, 'Documents', 'users', '$user'),
        ];

        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response($existing, 200),
        ]);

        $report = $this->service()->run(withUsers: false);

        self::assertSame(0, $report->exitCode());
        self::assertSame(
            [NextcloudMountAction::Conforme->value, NextcloudMountAction::Conforme->value],
            array_column($report->mounts(), 'action'),
        );

        Http::assertNotSent(static fn (Request $r): bool => in_array($r->method(), ['POST', 'PUT', 'DELETE'], true));
    }

    #[Test]
    public function a_diverging_mount_point_is_updated_in_place(): void
    {
        $this->configure();

        $existing = [
            self::remoteMount(1, 'Ancien nom', 'partages', ''),
            self::remoteMount(2, 'Documents', 'users', '$user'),
        ];

        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            self::URL . '/index.php/apps/files_external/globalstorages' => Http::sequence()
                ->push($existing, 200)
                ->push($existing, 200),
            self::URL . '/index.php/apps/files_external/globalstorages/1' => Http::response(['id' => 1], 200),
        ]);

        $report = $this->service()->run(withUsers: false);

        self::assertSame(NextcloudMountAction::MisAJour->value, $report->mounts()[0]['action']);
        self::assertSame(NextcloudMountAction::Conforme->value, $report->mounts()[1]['action']);

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/globalstorages/1'));
    }

    /** Un montage créé à la main par l'administrateur n'est NI supprimé NI modifié. */
    #[Test]
    public function a_foreign_mount_is_left_strictly_alone(): void
    {
        $this->configure();

        $existing = [
            self::remoteMount(1, 'Partages', 'partages', ''),
            self::remoteMount(2, 'Documents', 'users', '$user'),
            [
                'id' => 99,
                'mountPoint' => '/Archives',
                'backend' => 'smb',
                'authMechanism' => 'password::password',
                'backendOptions' => ['host' => 'autre', 'share' => 'archives', 'root' => ''],
            ],
        ];

        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response($existing, 200),
        ]);

        $report = $this->service()->run(withUsers: false);

        self::assertCount(2, $report->mounts(), 'SE5 ne rapporte que ce qu\'il a déclaré');
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), '/globalstorages/99'));
        Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'DELETE');
    }

    /**
     * Revue #1 — HÔTE SMB VIDE : le provisionnement ne refuse RIEN, il dérive le
     * défaut là où il est consommé (`sambaedu.se4fs_name`), exactement comme
     * l'agent substitue le jeton `<se4fs>` dans les UNC des lecteurs.
     */
    #[Test]
    public function an_empty_smb_host_falls_back_to_the_known_file_server(): void
    {
        config(['sambaedu.se4fs_name' => 'srv-fichiers']);
        $this->configure(smbHost: '');

        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            self::URL . '/index.php/apps/files_external/globalstorages' => Http::sequence()
                ->push([], 200)
                ->push([], 200)
                ->push(['id' => 1], 201)
                ->push(['id' => 2], 201),
        ]);

        $report = $this->service()->run(withUsers: false);

        self::assertSame(0, $report->exitCode(), 'un hôte SMB vide n\'est pas une configuration incomplète');
        self::assertStringContainsString('//srv-fichiers/partages', $report->mounts()[0]['detail']);

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
            && ($r['backendOptions']['host'] ?? null) === 'srv-fichiers');
    }

    /** Le quatrième diagnostic remonte tel quel dans le rapport, remédiation comprise. */
    #[Test]
    public function a_missing_smb_backend_is_reported_with_its_remediation(): void
    {
        $this->configure();
        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            self::URL . '/index.php/apps/files_external/globalstorages' => Http::sequence()
                ->push([], 200)
                ->push([], 200)
                ->push(['message' => 'Invalid storage backend "smb"'], 422)
                ->push(['message' => 'Invalid storage backend "smb"'], 422),
        ]);

        $report = $this->service()->run(withUsers: false);

        self::assertSame(1, $report->exitCode(), 'échec partiel, pas un refus amont');
        self::assertSame(NextcloudMountAction::Echec->value, $report->mounts()[0]['action']);
        self::assertStringContainsString('smbclient', $report->mounts()[0]['detail']);
    }

    // =====================================================================
    // AC8 — le dry-run n'écrit RIEN
    // =====================================================================

    #[Test]
    public function a_dry_run_emits_no_write_at_all(): void
    {
        $this->configure();
        User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true, 'source' => 'ad']);

        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response([], 200),
            '*/ocs/v1.php/cloud/users/*' => Http::response(self::ocs(100, ['id' => 'alice']), 200),
        ]);

        $report = $this->service()->run(dryRun: true);

        self::assertTrue($report->dryRun);
        self::assertSame(NextcloudMountAction::Simule->value, $report->mounts()[0]['action']);

        Http::assertNotSent(static fn (Request $r): bool => in_array($r->method(), ['POST', 'PUT', 'DELETE'], true));
        self::assertNull(
            User::query()->where('login', 'alice')->value('nextcloud_user_id'),
            'la simulation ne persiste même pas le cache d\'identité',
        );
    }

    // =====================================================================
    // AC5 / AC6 — le stock : adoption, introuvables, exclusions
    // =====================================================================

    #[Test]
    public function existing_users_are_adopted_and_their_identity_cached(): void
    {
        $this->configure();
        User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true, 'source' => 'ad']);

        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response([], 200),
            '*/ocs/v1.php/cloud/users/alice*' => Http::response(self::ocs(100, ['id' => 'alice-nc']), 200),
        ]);

        $report = $this->service()->run(mounts: false);

        self::assertSame(1, $report->userCounters()['adoptes']);
        self::assertSame('alice-nc', User::query()->where('login', 'alice')->value('nextcloud_user_id'));
    }

    /** **JAMAIS de mot de passe inventé** : l'absent est rapporté, pas fabriqué. */
    #[Test]
    public function a_user_absent_from_nextcloud_is_reported_never_created(): void
    {
        $this->configure();
        User::query()->create(['login' => 'bob', 'role' => 'eleve', 'is_active' => true, 'source' => 'ad']);

        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response([], 200),
            '*/ocs/v1.php/cloud/users/bob*' => Http::response(self::ocs(998, [], 'not found'), 200),
            '*/ocs/v2.php/core/autocomplete/get*' => Http::response(self::ocs(200, []), 200),
        ]);

        $report = $this->service()->run(mounts: false);

        self::assertSame(1, $report->userCounters()['introuvables']);
        self::assertSame(0, $report->userCounters()['crees']);
        self::assertSame('introuvable', $report->userIssues()[0]['issue']);
        self::assertNull(User::query()->where('login', 'bob')->value('nextcloud_user_id'));

        Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'POST'
            && str_contains($r->url(), '/cloud/users'));
    }

    /**
     * Revue #2 — L'AUTOCOMPLÉTION N'ADOPTE QUE L'HOMONYME.
     *
     * Elle cherche par SOUS-CHAÎNE : un candidat unique n'est pas une preuve
     * d'identité. L'adopter le graverait dans `users.nextcloud_user_id`, d'où
     * `propagatePassword()` écraserait ensuite le mot de passe d'un compte tiers.
     * Un non-homonyme est donc un INTROUVABLE — et le rapport nomme quand même les
     * candidats écartés, pour que l'exploitant sache où regarder.
     */
    #[Test]
    public function autocomplete_never_adopts_a_candidate_that_is_not_the_homonym(): void
    {
        $this->configure();
        User::query()->create(['login' => 'carol', 'role' => 'prof', 'is_active' => true, 'source' => 'ad']);

        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response([], 200),
            '*/ocs/v1.php/cloud/users/carol*' => Http::response(self::ocs(998, [], 'not found'), 200),
            '*/ocs/v2.php/core/autocomplete/get*' => Http::response(self::ocs(200, [
                ['id' => 'b5f1-uuid-carol', 'source' => 'users'],
            ]), 200),
        ]);

        $report = $this->service()->run(mounts: false);

        self::assertNull(
            User::query()->where('login', 'carol')->value('nextcloud_user_id'),
            'un candidat non homonyme ne devient JAMAIS une identité cachée',
        );
        self::assertSame(1, $report->userCounters()['introuvables']);
        self::assertSame(0, $report->userCounters()['adoptes']);
        self::assertSame('introuvable', $report->userIssues()[0]['issue']);
        self::assertStringContainsString('b5f1-uuid-carol', $report->userIssues()[0]['detail']);
        self::assertStringContainsString('non adoptés', $report->userIssues()[0]['detail']);
    }

    /** L'homonyme, lui, reste adopté — casse comprise. C'est le seul cas retenu. */
    #[Test]
    public function autocomplete_adopts_the_homonym_regardless_of_case(): void
    {
        $this->configure();
        User::query()->create(['login' => 'carol', 'role' => 'prof', 'is_active' => true, 'source' => 'ad']);

        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response([], 200),
            '*/ocs/v1.php/cloud/users/carol*' => Http::response(self::ocs(998, [], 'not found'), 200),
            '*/ocs/v2.php/core/autocomplete/get*' => Http::response(self::ocs(200, [
                ['id' => 'carol-homonyme', 'source' => 'users'],
                ['id' => 'CAROL', 'source' => 'users'],
            ]), 200),
        ]);

        $report = $this->service()->run(mounts: false);

        self::assertSame('CAROL', User::query()->where('login', 'carol')->value('nextcloud_user_id'));
        self::assertSame(1, $report->userCounters()['adoptes']);
    }

    /** AC6 — l'identité déjà cachée n'est JAMAIS re-résolue. */
    #[Test]
    public function a_cached_identity_costs_no_call_on_the_second_pass(): void
    {
        $this->configure();
        $user = User::query()->create(['login' => 'dave', 'role' => 'prof', 'is_active' => true, 'source' => 'ad']);
        $user->nextcloud_user_id = 'dave';
        $user->saveQuietly();

        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response([], 200),
        ]);

        $report = $this->service()->run(mounts: false);

        self::assertSame(1, $report->userCounters()['adoptes']);
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), '/cloud/users/'));
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), 'autocomplete'));
    }

    #[Test]
    public function federated_identities_are_excluded_and_counted(): void
    {
        $this->configure();
        // `source` est hors `$fillable` : la forcer est le seul moyen honnête de
        // fabriquer une identité fédérée (le `create()` la laisserait à « ad »,
        // et le test passerait pour la mauvaise raison).
        (new User())->forceFill([
            'login' => 'ext:tech', 'role' => 'autre', 'is_active' => true, 'source' => 'federated',
        ])->save();

        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response([], 200),
        ]);

        $report = $this->service()->run(mounts: false);

        self::assertSame(1, $report->userCounters()['exclus']);
        self::assertSame(0, $report->userCounters()['adoptes']);
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), 'ext%3Atech')
            || str_contains($r->url(), 'ext:tech'));
    }

    /** AC9 — une dégradation partielle se DIT : compteurs et code 1. */
    #[Test]
    public function a_privilege_refusal_mid_run_is_reported_partially_with_exit_code_one(): void
    {
        $this->configure();
        User::query()->create(['login' => 'erin', 'role' => 'prof', 'is_active' => true, 'source' => 'ad']);

        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response([
                self::remoteMount(1, 'Partages', 'partages', ''),
                self::remoteMount(2, 'Documents', 'users', '$user'),
            ], 200),
            '*/ocs/v1.php/cloud/users/erin*' => Http::response('forbidden', 403),
        ]);

        $report = $this->service()->run();

        self::assertSame(1, $report->exitCode());
        self::assertSame(NextcloudMountAction::Conforme->value, $report->mounts()[0]['action']);
        self::assertSame(1, $report->userCounters()['echecs']);
        self::assertStringContainsString('privilège requis', $report->userIssues()[0]['detail']);
    }

    // =====================================================================
    // AC8 — verrou et dernier rapport
    // =====================================================================

    #[Test]
    public function a_concurrent_run_is_refused_by_the_file_lock(): void
    {
        $this->configure();
        Http::fake();

        $lock = Cache::store('file')->lock(NextcloudProvisioningService::LOCK_KEY, 60);
        self::assertTrue($lock->get());

        try {
            $report = $this->service()->run();

            self::assertSame(2, $report->exitCode());
            self::assertStringContainsString('déjà en cours', (string) $report->refusal());
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    /**
     * Revue #4 — LE MARQUEUR D'EXÉCUTION EN COURS.
     *
     * Le rapport n'est mis en cache qu'à la FIN : une exécution interrompue (job
     * tué par la file) ne laisserait rien à voir. Le marqueur est donc posé AVANT
     * le balayage — observable en plein vol — et cède la place au rapport final.
     */
    #[Test]
    public function a_running_marker_is_posted_before_the_sweep_and_cleared_at_the_end(): void
    {
        $this->configure();

        $service = $this->service();
        $seenDuringRun = null;

        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => function () use ($service, &$seenDuringRun) {
                $seenDuringRun ??= $service->runningSince();

                return Http::response([
                    self::remoteMount(1, 'Partages', 'partages', ''),
                    self::remoteMount(2, 'Documents', 'users', '$user'),
                ], 200);
            },
        ]);

        self::assertNull($service->runningSince(), 'aucun marqueur avant le départ');

        $service->run(withUsers: false);

        self::assertIsArray($seenDuringRun, 'le marqueur doit être lisible PENDANT l\'exécution');
        self::assertNotSame('', $seenDuringRun['started_at']);
        self::assertFalse($seenDuringRun['dry_run']);

        self::assertNull($service->runningSince(), 'le marqueur cède la place au rapport final');
        self::assertIsArray($service->lastReport());
    }

    #[Test]
    public function the_last_report_is_cached_as_an_array_for_the_screen(): void
    {
        $this->configure();
        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response([
                self::remoteMount(1, 'Partages', 'partages', ''),
                self::remoteMount(2, 'Documents', 'users', '$user'),
            ], 200),
        ]);

        $this->service()->run(withUsers: false);

        $cached = $this->service()->lastReport();

        self::assertIsArray($cached);
        self::assertSame(0, $cached['exit_code']);
        self::assertCount(2, $cached['mounts']);
    }
}
