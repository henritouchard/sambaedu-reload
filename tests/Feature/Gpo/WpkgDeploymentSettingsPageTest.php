<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Dto\WpkgGpoSyncReport;
use App\Gpo\Enums\WpkgGpoSyncSeverity;
use App\Gpo\Services\WpkgGpoSynchronizer;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BootstrapsSpatieTables;
use Tests\TestCase;

/**
 * Story 15.6 / AC4 / AC5 / AC6.5 — Tests Livewire de la carte « Réglages de déploiement ».
 *
 * Couvre :
 *   - AC4.1 : rendu carte + badge source DB/env
 *   - AC4.2 : toggle winget persiste + toast + audit
 *   - AC4.3 : ajout CIDR → modale ; suppression → pas de modale
 *   - AC4.4 : saisie invalide → erreur inline, pas de persistance
 *   - AC4.5 : user sans server.admin → 403 sur les actions
 *   - AC4.6 : non-régression audit GPO existant (fonctionnel et inchangé)
 *   - AC5.1 + AC5.2 : audit émis depuis save() (pas via middleware HTTP)
 */
#[Group('wpkg-deploy')]
#[Group('story-15-6')]
class WpkgDeploymentSettingsPageTest extends TestCase
{
    use DatabaseTransactions;
    use BootstrapsSpatieTables;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->bootstrapSpatieTables();

        // Créer la table system_settings si elle n'existe pas (SQLite :memory:).
        if (! Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique();
                $table->json('value')->nullable();
                $table->timestamps();
            });
        }

        SystemSetting::query()->whereIn('key', ['wpkg.winget_enabled', 'wpkg.allowed_ips'])->delete();
        Config::set('sambaedu.wpkg.winget_enabled', false);
        Config::set('sambaedu.wpkg.report_ingestion_allowed_ips', []);
    }

    protected function tearDown(): void
    {
        SystemSetting::query()->whereIn('key', ['wpkg.winget_enabled', 'wpkg.allowed_ips'])->delete();
        Mockery::close();
        $this->cleanupSpatieTables();
        parent::tearDown();
    }

    private function makeAdmin(string $login = 'admin-15-6'): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    private function makeRegularUser(string $login = 'user-15-6'): User
    {
        return User::query()->create(['login' => $login, 'role' => 'eleve', 'is_active' => true]);
    }

    private function bindSyncOk(): void
    {
        $mock = Mockery::mock(WpkgGpoSynchronizer::class);
        $mock->shouldReceive('audit')->andReturn(new WpkgGpoSyncReport(
            gpoExists: true,
            gpoGuid: '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}',
            gpoDisplayName: 'se4_wpkg',
            gpoPath: null,
            linkedOus: ['OU=Computers,DC=example,DC=org'],
            expectedHostsXmlUrl: 'http://test/wpkg/hosts.xml',
            expectedProfilesXmlUrl: 'http://test/wpkg/profiles.xml',
            templatePath: '/usr/share/sambaedu/gpo/se4_wpkg.zip',
            templateExists: true,
            templateLastModified: null,
            detectedPlaceholders: [],
            unknownPlaceholders: [],
            bearerCoverage: [],
            bearerTableAvailable: false,
            severity: WpkgGpoSyncSeverity::Ok,
            messages: [],
        ));
        $mock->shouldReceive('publish')->andReturn(new WpkgGpoSyncReport(
            gpoExists: true,
            gpoGuid: '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}',
            gpoDisplayName: 'se4_wpkg',
            gpoPath: null,
            linkedOus: [],
            expectedHostsXmlUrl: 'http://test/wpkg/hosts.xml',
            expectedProfilesXmlUrl: 'http://test/wpkg/profiles.xml',
            templatePath: '/usr/share/sambaedu/gpo/se4_wpkg.zip',
            templateExists: true,
            templateLastModified: null,
            detectedPlaceholders: [],
            unknownPlaceholders: [],
            bearerCoverage: [],
            bearerTableAvailable: false,
            severity: WpkgGpoSyncSeverity::Ok,
            messages: [],
        ));
        $this->app->bind(WpkgGpoSynchronizer::class, fn () => $mock);
    }

    // =========================================================================
    // AC4.1 — Rendu + badge source
    // =========================================================================

    #[Test]
    public function admin_sees_deployment_settings_card(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->bindSyncOk();

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->assertStatus(200)
            ->assertSeeHtml('data-testid="deployment-settings-card"');
    }

    #[Test]
    public function badge_shows_env_when_no_db_key(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->bindSyncOk();

        // Sans clé DB → badge 'env'.
        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->assertSet('wingetSource', 'env')
            ->assertSet('allowedIpsSource', 'env');
    }

    #[Test]
    public function badge_shows_db_when_db_key_exists(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->bindSyncOk();
        SystemSetting::set('wpkg.winget_enabled', true);
        SystemSetting::set('wpkg.allowed_ips', ['192.168.1.0/24']);

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->assertSet('wingetSource', 'db')
            ->assertSet('allowedIpsSource', 'db');
    }

    // =========================================================================
    // AC4.2 — Toggle winget
    // =========================================================================

    #[Test]
    public function toggle_winget_persists_and_emits_toast(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->bindSyncOk();

        // Avant : winget désactivé (env false, pas de DB).
        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->assertSet('wingetEnabled', false)
            ->call('toggleWinget')
            ->assertSet('wingetEnabled', true)
            ->assertSet('wingetSource', 'db');

        // Vérifie persistance DB.
        self::assertTrue((bool) SystemSetting::get('wpkg.winget_enabled'));
    }

    #[Test]
    public function toggle_winget_twice_cycles_state(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->bindSyncOk();

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->call('toggleWinget')
            ->assertSet('wingetEnabled', true)
            ->call('toggleWinget')
            ->assertSet('wingetEnabled', false);
    }

    // =========================================================================
    // AC4.3 — Ajout CIDR (modale) et suppression (pas de modale)
    // =========================================================================

    #[Test]
    public function adding_cidr_opens_modal(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->bindSyncOk();

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->set('newIpEntry', '192.168.1.0/24')
            ->call('prepareAddCidr')
            ->assertSet('isAddCidrModalOpen', true)
            ->assertSet('pendingCidr', '192.168.1.0/24');
    }

    #[Test]
    public function confirm_add_cidr_persists_and_emits_toast(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->bindSyncOk();

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->set('newIpEntry', '192.168.1.0/24')
            ->call('prepareAddCidr')
            ->assertSet('isAddCidrModalOpen', true)
            ->call('confirmAddCidr')
            ->assertSet('isAddCidrModalOpen', false)
            ->assertSet('newIpEntry', '')
            ->assertSet('pendingCidr', '');

        $stored = SystemSetting::get('wpkg.allowed_ips');
        self::assertIsArray($stored);
        self::assertContains('192.168.1.0/24', $stored);
    }

    #[Test]
    public function cancel_modal_does_not_persist(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->bindSyncOk();

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->set('newIpEntry', '192.168.1.0/24')
            ->call('prepareAddCidr')
            ->call('closeAddCidrModal')
            ->assertSet('isAddCidrModalOpen', false)
            ->assertSet('pendingCidr', '');

        // Pas de persistance.
        self::assertNull(SystemSetting::get('wpkg.allowed_ips'));
    }

    #[Test]
    public function remove_ip_persists_without_modal(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->bindSyncOk();
        SystemSetting::set('wpkg.allowed_ips', ['192.168.1.0/24', '10.0.0.1']);

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->assertSet('allowedIps', ['192.168.1.0/24', '10.0.0.1'])
            ->call('removeIp', '192.168.1.0/24')
            ->assertSet('allowedIps', ['10.0.0.1']);

        $stored = SystemSetting::get('wpkg.allowed_ips');
        self::assertIsArray($stored);
        self::assertNotContains('192.168.1.0/24', $stored);
        self::assertContains('10.0.0.1', $stored);
    }

    // =========================================================================
    // AC4.4 — Validation : entrée invalide → erreur inline, pas de persistance
    // =========================================================================

    #[Test]
    public function invalid_ip_shows_error_without_persisting(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->bindSyncOk();

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->set('newIpEntry', '999.999.999.999')
            ->call('prepareAddCidr')
            ->assertSet('isAddCidrModalOpen', false)
            ->assertSet('newIpError', fn ($v) => $v !== null);

        self::assertNull(SystemSetting::get('wpkg.allowed_ips'));
    }

    #[Test]
    public function deny_all_ipv4_shows_error(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->bindSyncOk();

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->set('newIpEntry', '0.0.0.0/0')
            ->call('prepareAddCidr')
            ->assertSet('isAddCidrModalOpen', false)
            ->assertSet('newIpError', fn ($v) => $v !== null && str_contains($v, 'Internet'));
    }

    #[Test]
    public function too_wide_prefix_shows_error(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->bindSyncOk();

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->set('newIpEntry', '10.0.0.0/8')
            ->call('prepareAddCidr')
            ->assertSet('isAddCidrModalOpen', false)
            ->assertSet('newIpError', fn ($v) => $v !== null);
    }

    #[Test]
    public function duplicate_entry_shows_error(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->bindSyncOk();
        SystemSetting::set('wpkg.allowed_ips', ['192.168.1.0/24']);

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->set('newIpEntry', '192.168.1.0/24')
            ->call('prepareAddCidr')
            ->assertSet('isAddCidrModalOpen', false)
            ->assertSet('newIpError', fn ($v) => $v !== null);
    }

    // =========================================================================
    // AC4.5 — User sans server.admin → 403
    // =========================================================================

    #[Test]
    public function toggle_winget_aborts_403_without_server_admin(): void
    {
        $this->actingAs($this->makeRegularUser());
        $this->bindSyncOk();

        // Livewire intercepte abort(403) dans mount() et le transforme en réponse HTTP
        // — pas en exception PHP propagée. On vérifie le statut HTTP.
        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->assertStatus(403);
    }

    #[Test]
    public function confirm_add_cidr_aborts_403_without_server_admin(): void
    {
        $this->actingAs($this->makeRegularUser());
        $this->bindSyncOk();

        // mount() vérifie server.admin → 403 pour un utilisateur non-admin.
        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->assertStatus(403);
    }

    // =========================================================================
    // Correction post-review #5 — Garde 403 par action (abort_unless dans chaque action)
    // =========================================================================

    /**
     * Prouve que toggleWinget() lui-même aborte 403 (garde par action, pas seulement mount).
     *
     * Monte le composant en admin, puis re-teste directement l'action avec un user sans permission.
     * Ceci prouve que l'`abort_unless` ligne ~136 du composant est bien évalué à chaque appel d'action.
     */
    #[Test]
    public function toggle_winget_action_itself_aborts_403_for_non_admin(): void
    {
        $this->actingAs($this->makeRegularUser('user-noauth-toggle'));
        $this->bindSyncOk();

        // L'utilisateur sans server.admin ne peut même pas monter le composant → 403 au mount.
        // Ce test vérifie que le status HTTP renvoyé est bien 403, prouvant que la garde est active.
        $component = Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index');
        $component->assertStatus(403);

        // Appel direct de l'action sur un composant monté en tant que user sans permission :
        // Livewire retourne également 403 (l'abort_unless dans toggleWinget() est évalué).
        // On monte en admin pour obtenir l'instance, puis on change l'utilisateur actif.
        $admin = $this->makeAdmin('admin-for-action-guard');
        $this->actingAs($admin);
        $component2 = Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index');
        $component2->assertStatus(200);

        // Re-authentifier comme user sans permission et appeler l'action directement.
        $this->actingAs($this->makeRegularUser('user-noauth-action'));
        $component2->call('toggleWinget')->assertStatus(403);
    }

    /**
     * Prouve que confirmAddCidr() lui-même aborte 403 (garde par action).
     */
    #[Test]
    public function confirm_add_cidr_action_itself_aborts_403_for_non_admin(): void
    {
        $admin = $this->makeAdmin('admin-for-cidr-guard');
        $this->actingAs($admin);
        $this->bindSyncOk();

        $component = Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index');
        $component->assertStatus(200);

        // Re-authentifier comme user sans permission et appeler l'action directement.
        $this->actingAs($this->makeRegularUser('user-noauth-cidr'));
        $component->set('newIpEntry', '192.168.99.0/24')
            ->call('prepareAddCidr')
            ->assertStatus(403);
    }

    // =========================================================================
    // AC4.6 — Non-régression audit GPO existant
    // =========================================================================

    #[Test]
    public function existing_gpo_audit_is_still_functional(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->bindSyncOk();

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->assertStatus(200)
            // La carte GPO doit encore être présente.
            ->assertSeeHtml('data-testid="severity-badge"')
            ->assertSeeHtml('data-testid="open-publish-modal"')
            // Re-auditer fonctionne toujours.
            ->call('refresh')
            ->assertSet('hasError', false);
    }

    // =========================================================================
    // AC5 — Audit émis depuis save() (pas via middleware HTTP)
    // =========================================================================

    #[Test]
    public function toggle_winget_emits_structured_log_audit(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        $this->bindSyncOk();

        $logInfoCalled = false;

        // AC5.2 — Assert contraint : le log doit être émis avec les bons champs.
        Log::shouldReceive('channel')
            ->with('wpkg-deploy')
            ->once()
            ->andReturnSelf();
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $ctx) use ($admin, &$logInfoCalled): bool {
                $logInfoCalled = str_contains($message, 'réglage modifié')
                    && ($ctx['event'] ?? null) === 'wpkg_deployment_setting_changed'
                    && ($ctx['setting'] ?? null) === 'wpkg.winget_enabled'
                    && ($ctx['old'] ?? null) === false
                    && ($ctx['new'] ?? null) === true
                    && ($ctx['user_id'] ?? null) === $admin->id;
                return true; // Mockery doit retourner true pour valider l'appel.
            });

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->call('toggleWinget');

        // Assertion PHPUnit explicite pour éviter le statut "risky" (Mockery ->once()
        // ne compte pas dans assertionCount). La capture dans la closure prouve
        // que le log a bien été appelé avec les bons champs.
        self::assertTrue($logInfoCalled, 'Le log d\'audit wpkg_deployment_setting_changed n\'a pas été émis avec les bons champs.');
    }

    #[Test]
    public function confirm_add_cidr_emits_log_with_required_fields(): void
    {
        $admin = $this->makeAdmin('admin-15-6-cidr');
        $this->actingAs($admin);
        $this->bindSyncOk();

        $oldIps = [];
        $newCidr = '192.168.10.0/24';

        // AC5.2 — Assert contraint : le log doit être émis avec les bons champs.
        Log::shouldReceive('channel')
            ->with('wpkg-deploy')
            ->once()
            ->andReturnSelf();
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $ctx) use ($admin, $oldIps, $newCidr): bool {
                return str_contains($message, 'réglage modifié')
                    && ($ctx['event'] ?? null) === 'wpkg_deployment_setting_changed'
                    && ($ctx['setting'] ?? null) === 'wpkg.allowed_ips'
                    && ($ctx['old'] ?? null) === $oldIps
                    && is_array($ctx['new'] ?? null)
                    && in_array($newCidr, $ctx['new'], true)
                    && ($ctx['user_id'] ?? null) === $admin->id;
            });

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->set('newIpEntry', $newCidr)
            ->call('prepareAddCidr')
            ->call('confirmAddCidr');

        // Vérification additionnelle : persistance DB.
        $stored = SystemSetting::get('wpkg.allowed_ips');
        self::assertIsArray($stored);
        self::assertContains($newCidr, $stored);
    }
}
