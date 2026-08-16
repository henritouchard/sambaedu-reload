<?php

declare(strict_types=1);

namespace Tests\Feature\Nextcloud;

use App\Jobs\ProvisionNextcloudJob;
use App\Services\FilePolicyService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\Nextcloud\NextcloudProvisioningService;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 61.1 — la commande, ses codes de sortie, et le traitement en file.
 */
class NextcloudProvisionCommandTest extends TestCase
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

    private function configure(bool $enabled = true): void
    {
        FilePolicyService::setGlobal(true, true, $enabled, self::URL, 'admin', 'se4fs', true);
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'sekret');
    }

    /** @param array<string, mixed> $data */
    private static function ocs(int $code, array $data = []): array
    {
        return ['ocs' => ['meta' => ['status' => 'ok', 'statuscode' => $code, 'message' => 'OK'], 'data' => $data]];
    }

    private static function remoteMount(int $id, string $mountPoint, string $share, string $root): array
    {
        return [
            'id' => $id,
            'mountPoint' => '/' . $mountPoint,
            'backend' => 'smb',
            'authMechanism' => 'password::sessioncredentials',
            'backendOptions' => ['host' => 'se4fs', 'share' => $share, 'root' => $root],
            'status' => 4,
            'statusMessage' => 'Storage unauthorized. Session unavailable',
        ];
    }

    #[Test]
    public function an_invalid_configuration_exits_with_code_two(): void
    {
        Http::fake();
        $this->configure(enabled: false);

        $this->artisan('nextcloud:provision --force')->assertExitCode(2);

        Http::assertNothingSent();
    }

    #[Test]
    public function a_converged_instance_exits_with_code_zero(): void
    {
        $this->configure();
        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response([
                self::remoteMount(1, 'Partages', 'partages', ''),
                self::remoteMount(2, 'Documents', 'users', '$user'),
            ], 200),
        ]);

        $this->artisan('nextcloud:provision --mounts-only --force')->assertExitCode(0);
    }

    #[Test]
    public function a_partial_failure_exits_with_code_one(): void
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

        $this->artisan('nextcloud:provision --mounts-only --force')->assertExitCode(1);
    }

    #[Test]
    public function the_two_exclusive_scopes_are_refused_together(): void
    {
        Http::fake();

        $this->artisan('nextcloud:provision --users-only --mounts-only')->assertExitCode(2);

        Http::assertNothingSent();
    }

    #[Test]
    public function the_dry_run_option_emits_no_write(): void
    {
        $this->configure();
        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response([], 200),
        ]);

        $this->artisan('nextcloud:provision --dry-run --mounts-only')->assertExitCode(0);

        Http::assertNotSent(static fn (Request $r): bool => in_array($r->method(), ['POST', 'PUT', 'DELETE'], true));
    }

    // =====================================================================
    // Le garde-fou : un geste déconseillé se confirme
    // =====================================================================

    /**
     * Le montage SMB est un chemin d'accès qui n'est pas acquis : la commande
     * prévient et demande. Un refus ne doit rien laisser derrière lui — donc
     * AUCUN appel réseau, pas même la sonde de connexion.
     */
    #[Test]
    public function a_declined_confirmation_writes_nothing_and_exits_with_code_two(): void
    {
        $this->configure();
        Http::fake();

        $this->artisan('nextcloud:provision --mounts-only')
            ->expectsConfirmation('Provisionner malgré tout ?', 'no')
            ->assertExitCode(2);

        Http::assertNothingSent();
    }

    #[Test]
    public function a_confirmed_run_proceeds(): void
    {
        $this->configure();
        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response([
                self::remoteMount(1, 'Partages', 'partages', ''),
                self::remoteMount(2, 'Documents', 'users', '$user'),
            ], 200),
        ]);

        $this->artisan('nextcloud:provision --mounts-only')
            ->expectsConfirmation('Provisionner malgré tout ?', 'yes')
            ->assertExitCode(0);
    }

    /**
     * L'avertissement parle des montages SMB. `--users-only` n'en pose aucun : le
     * faire confirmer par ce message serait un avertissement à côté du geste.
     */
    #[Test]
    public function the_users_only_scope_asks_no_confirmation(): void
    {
        $this->configure();
        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            // La sonde de connexion LIT les montages pour vérifier le privilège
            // d'administration — c'est une lecture, pas un geste de montage.
            '*/globalstorages*' => Http::response([], 200),
        ]);

        $this->artisan('nextcloud:provision --users-only')
            ->doesntExpectOutputToContain('Provisionner malgré tout ?')
            ->assertExitCode(0);

        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), 'globalstorages')
            && in_array($r->method(), ['POST', 'PUT', 'DELETE'], true));
    }

    /**
     * L'aperçu n'écrit rien : le faire confirmer découragerait le seul geste qu'on
     * souhaite encourager. Ce test échoue si une confirmation apparaît.
     */
    #[Test]
    public function the_dry_run_asks_no_confirmation(): void
    {
        $this->configure();
        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response([], 200),
        ]);

        $this->artisan('nextcloud:provision --dry-run --mounts-only')
            ->doesntExpectOutputToContain('Provisionner malgré tout ?')
            ->assertExitCode(0);
    }

    // =====================================================================
    // Le traitement en file
    // =====================================================================

    /**
     * La charge utile ne porte QUE des identifiants. Un rapport ou une
     * configuration s'y trouvant serait périmé au moment de l'exécution — et un
     * secret y serait lisible en clair dans la table des travaux.
     */
    #[Test]
    public function the_job_payload_carries_identifiers_and_nothing_else(): void
    {
        $job = new ProvisionNextcloudJob('bob');

        // Les propriétés promues du constructeur SONT la charge utile déclarée
        // par cette classe (les autres viennent des traits de mise en file).
        $promoted = array_values(array_filter(
            (new \ReflectionClass($job))->getConstructor()?->getParameters() ?? [],
            static fn (\ReflectionParameter $p): bool => $p->isPromoted(),
        ));

        self::assertCount(1, $promoted);
        self::assertSame('performedBy', $promoted[0]->getName());
        self::assertSame('?string', (string) $promoted[0]->getType());
        self::assertSame('bob', $job->performedBy);

        // Et il se sérialise : la file de traitement l'exige.
        self::assertIsString(serialize($job));
    }

    #[Test]
    public function the_job_runs_the_same_service_as_the_command(): void
    {
        $this->configure();
        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response([
                self::remoteMount(1, 'Partages', 'partages', ''),
                self::remoteMount(2, 'Documents', 'users', '$user'),
            ], 200),
        ]);

        (new ProvisionNextcloudJob('bob'))->handle(app(NextcloudProvisioningService::class));

        $report = app(NextcloudProvisioningService::class)->lastReport();

        self::assertIsArray($report);
        self::assertCount(2, $report['mounts']);
    }

    /**
     * Revue #4 — TEST DE GARDE : le délai maximal du traitement doit rester
     * INFÉRIEUR au TTL du verrou.
     *
     * Les unités des ouvriers lancent `queue:work` sans `--timeout` : sans
     * déclaration explicite, le délai par travail serait celui du framework
     * (60 s), et un balayage de grande population serait tué par SIGKILL — que
     * PHP ne peut pas intercepter, donc sans que le `finally` relâche le verrou.
     * Le verrou resterait posé jusqu'à l'expiration de son TTL, et la commande
     * comme le bouton répondraient « déjà en cours » pendant tout ce temps.
     *
     * Ce test casse si quelqu'un désaligne les deux valeurs, dans un sens ou dans
     * l'autre.
     */
    #[Test]
    public function the_job_declares_a_timeout_strictly_below_the_lock_ttl(): void
    {
        $job = new ProvisionNextcloudJob('bob');

        self::assertGreaterThan(60, $job->timeout, 'le défaut du framework ne suffit pas à un balayage complet');
        self::assertLessThan(
            NextcloudProvisioningService::LOCK_SECONDS,
            $job->timeout,
            'le verrou doit survivre à l\'exécution la plus longue permise, jamais l\'inverse',
        );

        // Une seule tentative : un provisionnement rejoué en boucle sur une
        // instance en panne repaie le balayage entier à chaque fois.
        self::assertSame(1, $job->tries);
    }
}
