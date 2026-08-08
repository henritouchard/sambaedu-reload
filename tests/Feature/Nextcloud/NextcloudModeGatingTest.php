<?php

declare(strict_types=1);

namespace Tests\Feature\Nextcloud;

use App\Enums\NextcloudInstanceMode;
use App\Jobs\ProvisionNextcloudJob;
use App\Models\User;
use App\Services\FilePolicyService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\Nextcloud\NextcloudDelegateConfig;
use App\Services\Nextcloud\NextcloudProvisioningService;
use App\Services\Nextcloud\NextcloudUserProvisioner;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 61.2 — AC5 : LE MODE DÉLÉGUÉ COUPE LA MACHINERIE D'ADMINISTRATION DE 61.1,
 * en la nommant, **avant tout appel HTTP**.
 *
 * Le test pivot est {@see self::the_user_hooks_send_nothing_at_all_in_delegated_mode()} :
 * un compte porteur n'a pas la gestion des comptes, et tenter l'appel pour le voir
 * échouer coûterait un aller-retour réseau par utilisateur — à la rentrée, un par
 * élève.
 */
class NextcloudModeGatingTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_SECRET = 'AppPasswordAdmin';

    private const DELEGATE_SECRET = 'AppPasswordPorteur';

    protected function setUp(): void
    {
        parent::setUp();

        $credentials = app(ServiceCredentials::class);
        $credentials->put(NextcloudConnectionConfig::CREDENTIAL_NAME, self::ADMIN_SECRET);
        $credentials->put(NextcloudDelegateConfig::CREDENTIAL_NAME, self::DELEGATE_SECRET);
    }

    private function configure(NextcloudInstanceMode $mode): void
    {
        FilePolicyService::setGlobal(
            true, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', true,
            $mode, 'se5porteur',
        );
    }

    // =====================================================================
    // La commande et le traitement en file
    // =====================================================================

    #[Test]
    public function the_provision_command_exits_with_code_2_naming_the_mode(): void
    {
        $this->configure(NextcloudInstanceMode::Delegue);
        Http::fake();

        $this->artisan('nextcloud:provision')
            ->expectsOutputToContain('opérations d\'administration')
            ->assertExitCode(2);

        // **AVANT tout appel HTTP** : c'est la seule façon d'être certain qu'aucune
        // opération d'administration n'est tentée avec un compte qui ne l'est pas.
        Http::assertNothingSent();
    }

    #[Test]
    public function the_refusal_names_the_declared_mode_and_never_the_secret(): void
    {
        $this->configure(NextcloudInstanceMode::Delegue);
        Http::fake();

        $report = app(NextcloudProvisioningService::class)->run();

        self::assertSame(2, $report->exitCode());
        self::assertNotNull($report->refusal());
        self::assertStringContainsString('Compte porteur (délégué)', (string) $report->refusal());
        self::assertStringNotContainsString(self::ADMIN_SECRET, (string) $report->refusal());
        self::assertStringNotContainsString(self::DELEGATE_SECRET, (string) $report->refusal());
        Http::assertNothingSent();
    }

    /** Le refus DIT aussi que rien n'est défait (D9). */
    #[Test]
    public function the_refusal_says_the_existing_mounts_are_untouched(): void
    {
        $this->configure(NextcloudInstanceMode::Delegue);
        Http::fake();

        $report = app(NextcloudProvisioningService::class)->run();

        self::assertStringContainsString('restent en place', (string) $report->refusal());
        self::assertSame([], $report->mounts(), 'aucun montage n\'est ni créé, ni supprimé, ni même lu');
    }

    #[Test]
    public function the_queued_job_is_refused_the_same_way(): void
    {
        $this->configure(NextcloudInstanceMode::Delegue);
        Http::fake();

        (new ProvisionNextcloudJob('operateur'))->handle(app(NextcloudProvisioningService::class));

        Http::assertNothingSent();

        $report = app(NextcloudProvisioningService::class)->lastReport();
        self::assertIsArray($report);
        self::assertSame(2, $report['exit_code']);
    }

    // =====================================================================
    // Les crochets du cycle de vie utilisateur
    // =====================================================================

    #[Test]
    public function the_user_hooks_send_nothing_at_all_in_delegated_mode(): void
    {
        $this->configure(NextcloudInstanceMode::Delegue);
        Http::fake();

        User::query()->create([
            'login' => 'p.durand',
            'role' => 'prof',
            'is_active' => true,
            'source' => 'ad',
        ])->forceFill(['nextcloud_user_id' => 'p.durand'])->saveQuietly();

        $provisioner = app(NextcloudUserProvisioner::class);
        $provisioner->ensureAccountAtCreation('p.durand', 'MotDePasse1!');
        $provisioner->propagatePassword('p.durand', 'MotDePasse2!');

        Http::assertNothingSent();
    }

    /**
     * …et la trace est en `debug`, PAS en warning : capacité active + mode délégué
     * est un état CONFIGURÉ et LÉGITIME. Un avertissement par utilisateur serait la
     * pollution que le finding #3 de la revue 61.1 a déjà fait corriger.
     */
    #[Test]
    public function the_delegated_skip_is_traced_in_debug_and_never_as_a_warning(): void
    {
        $this->configure(NextcloudInstanceMode::Delegue);
        Http::fake();

        $records = [];
        Log::listen(function ($message) use (&$records): void {
            $records[] = [$message->level, $message->message];
        });

        app(NextcloudUserProvisioner::class)->ensureAccountAtCreation('p.durand', 'MotDePasse1!');

        self::assertContains(['debug', 'nextcloud.user.create.delegated_mode'], $records);

        foreach ($records as [$level, $event]) {
            self::assertNotSame('warning', $level, 'un état configuré légitime ne crie pas : ' . $event);
            self::assertNotSame('error', $level, 'un état configuré légitime ne crie pas : ' . $event);
        }
    }

    /**
     * CORRECTION DE REVUE #5 — LA TRACE NE RELIT PLUS `files.policy`.
     *
     * `SystemSetting::get()` fait un `SELECT` direct, sans cache. Chaque compte
     * sauté en mode délégué relisait la politique une SECONDE fois, uniquement pour
     * produire une ligne de `debug` — alors que la configuration venait d'être lue
     * par le refus lui-même. Sur un import de rentrée, c'était un doublement des
     * requêtes sans bénéfice. Le mode voyage désormais dans le refus.
     */
    #[Test]
    public function a_skipped_account_reads_the_policy_only_once(): void
    {
        $this->configure(NextcloudInstanceMode::Delegue);
        Http::fake();

        $reads = 0;
        DB::listen(function ($query) use (&$reads): void {
            if (str_contains($query->sql, 'system_settings')) {
                $reads++;
            }
        });

        $provisioner = app(NextcloudUserProvisioner::class);

        foreach (['a.un', 'b.deux', 'c.trois'] as $login) {
            $provisioner->ensureAccountAtCreation($login, 'MotDePasse1!');
        }

        self::assertSame(3, $reads, 'un compte sauté ne doit coûter qu\'UNE lecture de la politique');
        Http::assertNothingSent();
    }

    /** Capacité éteinte : aucun appel ET aucune trace — ce n'est pas un mode, c'est une absence. */
    #[Test]
    public function a_disabled_capability_traces_nothing(): void
    {
        FilePolicyService::setGlobal(
            true, true, false, 'https://cloud.etab.fr', 'admin', 'se4fs', true,
            NextcloudInstanceMode::Delegue, 'se5porteur',
        );
        Http::fake();

        $records = [];
        Log::listen(function ($message) use (&$records): void {
            $records[] = $message->message;
        });

        app(NextcloudUserProvisioner::class)->ensureAccountAtCreation('p.durand', 'MotDePasse1!');

        Http::assertNothingSent();
        self::assertNotContains('nextcloud.user.create.delegated_mode', $records);
    }

    // =====================================================================
    // Le retour en mode administré restitue 61.1 À L'IDENTIQUE
    // =====================================================================

    #[Test]
    public function switching_back_to_the_administered_mode_restores_the_61_1_behaviour(): void
    {
        $this->configure(NextcloudInstanceMode::Delegue);
        Http::fake();

        app(NextcloudUserProvisioner::class)->ensureAccountAtCreation('p.durand', 'MotDePasse1!');
        Http::assertNothingSent();

        // Aucune reconfiguration : on ne change QUE le mode.
        $this->configure(NextcloudInstanceMode::Admin);

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*/ocs/v1.php/cloud/users*' => Http::response([
            'ocs' => ['meta' => ['status' => 'ok', 'statuscode' => 100, 'message' => 'OK'], 'data' => []],
        ], 200)]);

        app(NextcloudUserProvisioner::class)->ensureAccountAtCreation('p.durand', 'MotDePasse1!');

        Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), 'ocs/v1.php/cloud/users')
            && $r->method() === 'POST');
    }

    /**
     * **LE CROISEMENT DE CREDENTIALS EST IMPOSSIBLE, SUR LE FIL.** En mode
     * administré, aucun appel ne porte l'auth du porteur ; en mode délégué, aucun
     * appel n'est émis du tout par la machinerie d'administration — donc a fortiori
     * aucun avec l'auth admin.
     */
    #[Test]
    public function no_call_ever_carries_the_credentials_of_the_other_mode(): void
    {
        $this->configure(NextcloudInstanceMode::Admin);

        Http::fake(['*' => Http::response([
            'ocs' => ['meta' => ['status' => 'ok', 'statuscode' => 100, 'message' => 'OK'], 'data' => []],
        ], 200)]);

        app(NextcloudUserProvisioner::class)->ensureAccountAtCreation('p.durand', 'MotDePasse1!');

        Http::assertSent(static fn (Request $r): bool => $r->hasHeader(
            'Authorization',
            'Basic ' . base64_encode('admin:' . self::ADMIN_SECRET),
        ));
        Http::assertNotSent(static fn (Request $r): bool => $r->hasHeader(
            'Authorization',
            'Basic ' . base64_encode('se5porteur:' . self::DELEGATE_SECRET),
        ));
    }
}
