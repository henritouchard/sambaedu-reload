<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Services\ApplicationScriptsAssembler;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\PermissionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Story 16.7 post-review #4 (2026-05-13) — câblage `localAdminScripts` aux
 * services Spatie natifs Epic 7.
 *
 * Couvre la branche `os=windows && userprofile !== ''` (logon/logoff) ainsi
 * que la branche Linux iso-legacy `/etc/sudoers.d/<user>`. Mock du
 * `PermissionService` pour isoler la logique de génération du script.
 */
class ApplicationScriptsAssemblerTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        // Domaine Samba prévisible pour les assertions iso-bytes.
        config(['sambaedu.samba_domain' => 'SE4FS']);
        // Désactive la synchro AD déclenchée par `WorkstationGroupObserver::created`
        // (test isolé du backend LDAP — pattern réutilisable).
        WorkstationGroupObserver::disableSync();
        $this->createTablesIfNeeded();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        WorkstationGroupObserver::enableSync();
        if ($this->createdTables) {
            Schema::dropIfExists('workstation_group_workstation');
            Schema::dropIfExists('workstations');
            Schema::dropIfExists('delegations');
            Schema::dropIfExists('model_has_permissions');
            Schema::dropIfExists('model_has_roles');
            Schema::dropIfExists('role_has_permissions');
            Schema::dropIfExists('permissions');
            Schema::dropIfExists('roles');
            Schema::dropIfExists('workstation_groups');
            Schema::dropIfExists('users');
        }
        parent::tearDown();
    }

    private function createTablesIfNeeded(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('login', 255)->unique();
                $table->string('password', 255)->nullable();
                $table->string('fullname', 255)->nullable();
                $table->string('role', 50)->default('autre');
                $table->boolean('is_active')->default(true);
                $table->timestamp('ad_synced_at')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('workstation_groups')) {
            Schema::create('workstation_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->boolean('is_physical')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->string('locked')->nullable();
                $table->text('description')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['permission_id', 'model_id', 'model_type'], 'mhp_primary');
            });
            $this->createdTables = true;
        }
        if (! Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type'], 'mhr_primary');
            });
            $this->createdTables = true;
        }
        if (! Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('delegations')) {
            Schema::create('delegations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('workstation_group_id');
                $table->unsignedBigInteger('permission_id');
                $table->boolean('is_negative')->default(false);
                $table->unsignedBigInteger('granted_by')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->unique(
                    ['user_id', 'workstation_group_id', 'permission_id', 'is_negative'],
                    'delegations_unique'
                );
            });
            $this->createdTables = true;
        }

        Permission::firstOrCreate(['name' => 'computer.elevate', 'guard_name' => 'web']);
    }

    private function makeUser(string $login): User
    {
        return User::create(['login' => $login, 'role' => 'prof', 'is_active' => true]);
    }

    private function makeGroup(string $name): WorkstationGroup
    {
        return WorkstationGroup::create([
            'name' => $name,
            'is_physical' => true,
            'is_active' => true,
        ]);
    }

    private function infoLogonWindows(string $user = 'alice', string $machine = 'pc01'): array
    {
        return [
            'os' => 'windows',
            'action' => 'logon',
            'user' => ['cn' => $user],
            'machine' => ['cn' => $machine],
            'userprofile' => 'C:\\Users\\' . $user,
            'parcs' => [],
        ];
    }

    /**
     * Invocation directe de la méthode privée `localAdminScripts` via réflexion
     * (parité tests Generator).
     */
    private function invokeLocalAdmin(ApplicationScriptsAssembler $a, array $info): array
    {
        $ref = new \ReflectionMethod($a, 'localAdminScripts');
        return $ref->invoke($a, $info);
    }

    #[Test]
    public function it_generates_add_at_logon_when_user_has_global_computer_elevate(): void
    {
        $user = $this->makeUser('alice');
        $user->givePermissionTo('computer.elevate');

        $perm = Mockery::mock(PermissionService::class);
        $perm->shouldReceive('canOnWorkstationGroup')->andReturn(false)->byDefault();

        $assembler = new ApplicationScriptsAssembler($perm);
        $info = $this->infoLogonWindows('alice', 'pc01');

        $result = $this->invokeLocalAdmin($assembler, $info);

        self::assertSame('cmd', $result['interpreter']);
        $script = implode('', $result['script']);
        self::assertStringContainsString('net localgroup administrateurs "SE4FS\\alice" /add', $script);
        self::assertStringContainsString('set admin=1', $script);
    }

    #[Test]
    public function it_generates_add_at_logon_when_user_has_scoped_delegation(): void
    {
        $user = $this->makeUser('bob');
        $group = $this->makeGroup('salle-A');

        $perm = Mockery::mock(PermissionService::class);
        $perm->shouldReceive('canOnWorkstationGroup')
            ->withArgs(fn ($u, $p, $g) => $u->id === $user->id && $p === 'computer.elevate' && $g->id === $group->id)
            ->andReturn(true);
        $perm->shouldReceive('canOnWorkstationGroup')->andReturn(false)->byDefault();

        $assembler = new ApplicationScriptsAssembler($perm);
        $info = $this->infoLogonWindows('bob', 'pc02');
        // Le générateur peuple `parcs` depuis le memberof LDAP — on simule.
        $info['parcs'] = [$group->name];

        $result = $this->invokeLocalAdmin($assembler, $info);
        $script = implode('', $result['script']);

        self::assertStringContainsString('net localgroup administrateurs "SE4FS\\bob" /add', $script);
        self::assertStringContainsString('set admin=1', $script);
    }

    #[Test]
    public function it_produces_empty_script_at_logon_when_user_has_no_rights(): void
    {
        $this->makeUser('eve');

        $perm = Mockery::mock(PermissionService::class);
        $perm->shouldReceive('canOnWorkstationGroup')->andReturn(false)->byDefault();

        $assembler = new ApplicationScriptsAssembler($perm);
        $info = $this->infoLogonWindows('eve', 'pc03');

        $result = $this->invokeLocalAdmin($assembler, $info);
        $script = implode('', $result['script']);

        self::assertSame('cmd', $result['interpreter']);
        self::assertSame('', $script, 'Aucun script ne doit être émis pour un user sans droit.');
    }

    #[Test]
    public function it_always_emits_delete_at_logoff_regardless_of_rights(): void
    {
        $this->makeUser('alice');

        $perm = Mockery::mock(PermissionService::class);
        $perm->shouldReceive('canOnWorkstationGroup')->andReturn(false)->byDefault();

        $assembler = new ApplicationScriptsAssembler($perm);
        $info = $this->infoLogonWindows('alice', 'pc01');
        $info['action'] = 'logoff';

        $result = $this->invokeLocalAdmin($assembler, $info);
        $script = implode('', $result['script']);

        self::assertSame('cmd', $result['interpreter']);
        self::assertStringContainsString('net localgroup administrateurs "SE4FS\\alice" /delete', $script);
    }

    #[Test]
    public function it_generates_sudoers_d_at_logon_on_linux_when_elevated(): void
    {
        $user = $this->makeUser('charlie.dupont');
        $user->givePermissionTo('computer.elevate');

        $perm = Mockery::mock(PermissionService::class);
        $perm->shouldReceive('canOnWorkstationGroup')->andReturn(false)->byDefault();

        $assembler = new ApplicationScriptsAssembler($perm);
        $info = $this->infoLogonWindows('charlie.dupont', 'pc-lin');
        $info['os'] = 'linux';

        $result = $this->invokeLocalAdmin($assembler, $info);
        $script = implode('', $result['script']);

        self::assertSame('bash', $result['interpreter']);
        // Le `.` est remplacé par `_` dans le nom de fichier sudoers (parité legacy).
        self::assertStringContainsString('/etc/sudoers.d/charlie_dupont', $script);
        self::assertStringContainsString('charlie.dupont ALL=(ALL:ALL) ALL', $script);
        self::assertStringContainsString('chmod 0440', $script);
    }

    #[Test]
    public function it_removes_sudoers_d_at_logoff_on_linux_inconditionally(): void
    {
        $this->makeUser('charlie.dupont');

        $perm = Mockery::mock(PermissionService::class);
        $perm->shouldReceive('canOnWorkstationGroup')->andReturn(false)->byDefault();

        $assembler = new ApplicationScriptsAssembler($perm);
        $info = $this->infoLogonWindows('charlie.dupont', 'pc-lin');
        $info['os'] = 'linux';
        $info['action'] = 'logoff';

        $result = $this->invokeLocalAdmin($assembler, $info);
        $script = implode('', $result['script']);

        self::assertStringContainsString('rm -f /etc/sudoers.d/charlie_dupont', $script);
    }

    #[Test]
    public function resolve_local_admin_right_returns_false_when_user_unknown(): void
    {
        $perm = Mockery::mock(PermissionService::class);
        $perm->shouldReceive('canOnWorkstationGroup')->andReturn(false)->byDefault();

        $assembler = new ApplicationScriptsAssembler($perm);
        $info = $this->infoLogonWindows('ghost', 'pc99');

        self::assertFalse($assembler->resolveLocalAdminRight($info));
    }

    #[Test]
    public function resolve_local_admin_right_returns_false_when_cn_missing(): void
    {
        $perm = Mockery::mock(PermissionService::class);
        $assembler = new ApplicationScriptsAssembler($perm);

        self::assertFalse($assembler->resolveLocalAdminRight([
            'user' => ['cn' => ''],
            'machine' => ['cn' => 'pc01'],
        ]));
        self::assertFalse($assembler->resolveLocalAdminRight([
            'user' => ['cn' => 'alice'],
            'machine' => ['cn' => ''],
        ]));
    }

    // ───────────────────────── Story 17.2 — Tests whitelist étendue ───────────

    /**
     * AC1.3 — Les 8 nouvelles clés whitelist sont substituées via config().
     */
    #[Test]
    public function it_substitutes_all_8_new_whitelist_keys_via_config(): void
    {
        // Positionne les 8 nouvelles clés en config.
        config(['sambaedu.windows.adminse_name' => 'ADMINSE_TEST']);
        config(['sambaedu.dhcp_masque' => '255.255.255.0']);
        config(['sambaedu.dhcp_reseau' => '192.168.1.0']);
        config(['sambaedu.glpi_url' => 'https://glpi.example.com']);
        config(['sambaedu.no_internet' => 'pasInternet']);
        config(['sambaedu.se4ad_ip' => '192.168.122.60']);
        config(['sambaedu.se4fs_ip' => '192.168.1.10']);
        config(['sambaedu.se4install_name' => 'se4install']);

        $assembler = new ApplicationScriptsAssembler();
        // Réinitialise le cache whitelist de l'instance pour prendre les config() à jour.
        $this->resetAssemblerCache($assembler);

        $template = '###_ADMINSE_NAME_###|###_DHCP_MASQUE_###|###_DHCP_RESEAU_###'
            . '|###_GLPI_URL_###|###_NO_INTERNET_###|###_SE4AD_IP_###'
            . '|###_SE4FS_IP_###|###_SE4INSTALL_NAME_###';

        $result = $assembler->applySubstitutions($template);

        self::assertSame(
            'ADMINSE_TEST|255.255.255.0|192.168.1.0|https://glpi.example.com|pasInternet|192.168.122.60|192.168.1.10|se4install',
            $result,
        );
    }

    /**
     * AC1.3 chemin (b) — fallback env quand config retourne null/vide.
     */
    #[Test]
    public function it_falls_back_to_env_when_config_null(): void
    {
        // Efface la valeur config pour forcer le fallback env.
        config(['sambaedu.glpi_url' => null]);
        config(['sambaedu.no_internet' => null]);
        config(['sambaedu.dhcp_reseau' => null]);
        config(['sambaedu.dhcp_masque' => null]);

        // Reset le repository statique de l'Env Laravel pour qu'il relise getenv()
        // sans interférence avec le cache immutable (fix review 17.2 #4).
        \Illuminate\Support\Env::enablePutenv();

        // EnvConstAdapter ($_ENV) et ServerConstAdapter ($_SERVER) ont priorité
        // sur PutenvAdapter dans le repository Laravel. Si le `.env` chargé au
        // bootstrap a positionné ces clés (même vides), $_ENV='' shadow le
        // putenv() ci-dessous → on les neutralise pour vraiment exercer le
        // fallback env du resolver.
        foreach (['SAMBAEDU_GLPI_URL', 'SAMBAEDU_NO_INTERNET', 'SAMBAEDU_DHCP_RESEAU', 'SAMBAEDU_DHCP_MASQUE'] as $k) {
            unset($_ENV[$k], $_SERVER[$k]);
        }

        putenv('SAMBAEDU_GLPI_URL=https://glpi-env.example.com');
        putenv('SAMBAEDU_NO_INTERNET=pasInternetEnv');
        putenv('SAMBAEDU_DHCP_RESEAU=10.0.0.0');
        putenv('SAMBAEDU_DHCP_MASQUE=255.0.0.0');

        $assembler = new ApplicationScriptsAssembler();
        $this->resetAssemblerCache($assembler);

        $template = '###_GLPI_URL_###|###_NO_INTERNET_###|###_DHCP_RESEAU_###|###_DHCP_MASQUE_###';
        $result = $assembler->applySubstitutions($template);

        // Nettoie les env après test et reset le repository.
        putenv('SAMBAEDU_GLPI_URL');
        putenv('SAMBAEDU_NO_INTERNET');
        putenv('SAMBAEDU_DHCP_RESEAU');
        putenv('SAMBAEDU_DHCP_MASQUE');
        \Illuminate\Support\Env::enablePutenv(); // reset repository → prochain test repart propre

        self::assertSame(
            'https://glpi-env.example.com|pasInternetEnv|10.0.0.0|255.0.0.0',
            $result,
        );
    }

    /**
     * AC1.3 chemin (c) — default fallback quand config ET env sont null.
     */
    #[Test]
    public function it_falls_back_to_default_when_config_and_env_null(): void
    {
        // Force config null pour les clés avec default.
        config(['sambaedu.windows.adminse_name' => null]);
        config(['sambaedu.dhcp_reseau' => null]);
        config(['sambaedu.glpi_url' => null]);
        config(['sambaedu.no_internet' => null]);
        config(['sambaedu.dhcp_masque' => null]);

        // Reset le repository + s'assure qu'aucun putenv résiduel ne masque le défaut.
        \Illuminate\Support\Env::enablePutenv();
        putenv('SAMBAEDU_ADMINSE_NAME');
        putenv('SAMBAEDU_DHCP_RESEAU');
        putenv('SAMBAEDU_GLPI_URL');
        putenv('SAMBAEDU_NO_INTERNET');
        putenv('SAMBAEDU_DHCP_MASQUE');

        $assembler = new ApplicationScriptsAssembler();
        $this->resetAssemblerCache($assembler);

        // ADMINSE_NAME → default 'adminse', DHCP_RESEAU → default '', GLPI_URL → default '', NO_INTERNET → default '', DHCP_MASQUE → default ''.
        $template = '###_ADMINSE_NAME_###|###_DHCP_RESEAU_###';
        $result = $assembler->applySubstitutions($template);

        self::assertSame('adminse|', $result);
    }

    /**
     * AC4.1 — Un placeholder hors whitelist reste inchangé + warning log.
     */
    #[Test]
    public function it_keeps_unknown_placeholders_unchanged_and_logs_warning(): void
    {
        \Illuminate\Support\Facades\Log::shouldReceive('channel')
            ->with('daily')
            ->once()
            ->andReturnSelf();
        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg, $ctx) {
                return str_contains($msg, 'unwhitelisted substitution keys ignored')
                    && in_array('INVENTE', $ctx['keys'] ?? [], true);
            });

        $assembler = new ApplicationScriptsAssembler();
        $this->resetAssemblerCache($assembler);

        $result = $assembler->applySubstitutions('avant###_INVENTE_###apres');

        self::assertSame('avant###_INVENTE_###apres', $result);
    }

    /**
     * AC4.2 — Les 11 placeholders connus n'émettent AUCUN warning log.
     */
    #[Test]
    public function it_does_not_warn_on_the_11_known_placeholders(): void
    {
        // Configure toutes les 11 clés connues.
        config([
            'sambaedu.se4fs_name' => 'se4fs.local',
            'sambaedu.domain' => 'etablissement.fr',
            'sambaedu.uai' => 'UAI0001',
            'sambaedu.windows.adminse_name' => 'adminse',
            'sambaedu.dhcp_masque' => '255.255.0.0',
            'sambaedu.dhcp_reseau' => '192.168.0.0',
            'sambaedu.glpi_url' => 'https://glpi.local',
            'sambaedu.no_internet' => '',
            'sambaedu.se4ad_ip' => '192.168.0.1',
            'sambaedu.se4fs_ip' => '192.168.0.2',
            'sambaedu.se4install_name' => 'se4install',
        ]);

        // S'assure que warning n'est JAMAIS appelé.
        \Illuminate\Support\Facades\Log::shouldReceive('channel')->andReturnSelf()->byDefault();
        \Illuminate\Support\Facades\Log::shouldReceive('warning')->never();

        $assembler = new ApplicationScriptsAssembler();
        $this->resetAssemblerCache($assembler);

        $template = '###_SE4FS_NAME_###|###_DOMAIN_###|###_UAI_###'
            . '|###_ADMINSE_NAME_###|###_DHCP_MASQUE_###|###_DHCP_RESEAU_###'
            . '|###_GLPI_URL_###|###_NO_INTERNET_###|###_SE4AD_IP_###'
            . '|###_SE4FS_IP_###|###_SE4INSTALL_NAME_###';

        $result = $assembler->applySubstitutions($template);

        // Vérifie également que les placeholders sont bien substitués (pas de ###_ résiduel)
        self::assertStringNotContainsString('###_', $result, 'Tous les 11 placeholders connus doivent être substitués.');
    }

    /**
     * Fix 17.2 #5 — `localAdminScripts` retourne `script: []` (tableau vide) quand
     * l'utilisateur n'a pas les droits, et non `['']` qui produirait un séparateur vide
     * dans `addScripts()` (bug latent 16.7 — parité legacy).
     *
     * Distinction critique : `implode('', [''])` === '' (masqué par le test précédent),
     * mais `addScripts()` itère sur le tableau et pourrait insérer un séparateur parasite
     * si l'array n'est pas strictement vide.
     */
    #[Test]
    public function it_returns_empty_script_array_when_user_has_no_admin_rights(): void
    {
        $this->makeUser('noright');

        $perm = Mockery::mock(PermissionService::class);
        $perm->shouldReceive('canOnWorkstationGroup')->andReturn(false)->byDefault();

        $assembler = new ApplicationScriptsAssembler($perm);
        $info = $this->infoLogonWindows('noright', 'pc-noright');

        $result = $this->invokeLocalAdmin($assembler, $info);

        self::assertSame([], $result['script'],
            'localAdminScripts doit retourner [] (pas [\'\']), pour que addScripts() skip le séparateur (fix bug 16.7 latent, 17.2).'
        );
    }

    // ───────────────────────── Story 17.3 — Whitelist APPLICATIONS_SCRIPTS_URL ───
    //
    // Couvre la nouvelle clé `APPLICATIONS_SCRIPTS_URL` (17.3 D4 option A.2)
    // résolue dynamiquement via `URL::route('agent.v1.config.applications-scripts',
    // [], absolute: true)` quand config et env sont vides — pattern callable
    // sérialisable (paire `[Classe::class, 'method']`) compatible config:cache.

    /**
     * AC2.2 — La résolution du `default` callable est exécutée par
     * `resolveSubstitutionValue` quand config et env sont vides. URL::route
     * matérialise l'URL native du endpoint Story 16.13.
     */
    #[Test]
    public function it_substitutes_applications_scripts_url_via_route_fallback(): void
    {
        // Force config + env vides pour tomber sur le default callable.
        config(['sambaedu.gpo.applications_scripts_url' => null]);
        \Illuminate\Support\Env::enablePutenv();
        putenv('SAMBAEDU_APPLICATIONS_SCRIPTS_URL');

        $assembler = new ApplicationScriptsAssembler();
        $this->resetAssemblerCache($assembler);

        $template = 'cmd-url=###_APPLICATIONS_SCRIPTS_URL_###';
        $result = $assembler->applySubstitutions($template);

        $expected = 'cmd-url=' . \Illuminate\Support\Facades\URL::route(
            'agent.v1.config.applications-scripts',
            [],
            absolute: true,
        );
        self::assertSame($expected, $result,
            'APPLICATIONS_SCRIPTS_URL doit être résolu via URL::route() (default callable).');
        self::assertStringContainsString('/api/v1/workstation-config/applications-scripts', $result);
    }

    /**
     * AC2.2 chemin (b) — l'env `SAMBAEDU_APPLICATIONS_SCRIPTS_URL` override le
     * default callable. Cas testing/CI ou bascule manuelle vers un proxy.
     */
    #[Test]
    public function it_overrides_applications_scripts_url_via_env(): void
    {
        config(['sambaedu.gpo.applications_scripts_url' => null]);

        // Si le `.env` runtime pré-set la variable (même à chaîne vide),
        // phpdotenv la fixe dans $_ENV/$_SERVER au boot → l'adapter immutable
        // retourne Some('') et court-circuite le PutenvAdapter activé ci-dessous.
        $serverBackup = $_SERVER['SAMBAEDU_APPLICATIONS_SCRIPTS_URL'] ?? null;
        $envBackup = $_ENV['SAMBAEDU_APPLICATIONS_SCRIPTS_URL'] ?? null;
        unset($_SERVER['SAMBAEDU_APPLICATIONS_SCRIPTS_URL'], $_ENV['SAMBAEDU_APPLICATIONS_SCRIPTS_URL']);

        \Illuminate\Support\Env::enablePutenv();
        putenv('SAMBAEDU_APPLICATIONS_SCRIPTS_URL=https://proxy.example.test/v1/apps');

        $assembler = new ApplicationScriptsAssembler();
        $this->resetAssemblerCache($assembler);

        $result = $assembler->applySubstitutions('###_APPLICATIONS_SCRIPTS_URL_###');

        // Cleanup env pour ne pas polluer les tests suivants.
        putenv('SAMBAEDU_APPLICATIONS_SCRIPTS_URL');
        if ($serverBackup !== null) {
            $_SERVER['SAMBAEDU_APPLICATIONS_SCRIPTS_URL'] = $serverBackup;
        }
        if ($envBackup !== null) {
            $_ENV['SAMBAEDU_APPLICATIONS_SCRIPTS_URL'] = $envBackup;
        }

        self::assertSame('https://proxy.example.test/v1/apps', $result,
            'L\'env SAMBAEDU_APPLICATIONS_SCRIPTS_URL doit override le default callable.');
    }

    /**
     * AC2.2 chemin (a) — `config('sambaedu.gpo.applications_scripts_url')`
     * override l'env et le default callable.
     */
    #[Test]
    public function it_overrides_applications_scripts_url_via_config(): void
    {
        config(['sambaedu.gpo.applications_scripts_url' => 'https://from-config.example.test/v1']);
        \Illuminate\Support\Env::enablePutenv();
        putenv('SAMBAEDU_APPLICATIONS_SCRIPTS_URL=https://proxy.example.test/v1');

        $assembler = new ApplicationScriptsAssembler();
        $this->resetAssemblerCache($assembler);

        $result = $assembler->applySubstitutions('###_APPLICATIONS_SCRIPTS_URL_###');

        putenv('SAMBAEDU_APPLICATIONS_SCRIPTS_URL');

        self::assertSame('https://from-config.example.test/v1', $result,
            'config() doit gagner sur env() et default — chaîne court-circuit 16.7 D3.');
    }

    /**
     * AC2.2 — La modification 17.3 `is_callable($value)` dans
     * `resolveSubstitutionValue` doit aussi fonctionner avec une closure
     * inline (pas seulement la paire array callable utilisée dans la
     * config). Cas générique pour permettre futures specs custom.
     */
    #[Test]
    public function it_resolves_callable_default_in_substitution_whitelist(): void
    {
        // Mock une whitelist custom avec un default closure inline.
        config([
            'sambaedu.gpo.applications.substitutions.whitelist' => [
                'CUSTOM_DYNAMIC' => [
                    'config' => 'sambaedu.nonexistent_config_key',
                    'env' => 'SAMBAEDU_NONEXISTENT_ENV_VAR',
                    'default' => static fn (): string => 'resolved-at-runtime-' . PHP_VERSION_ID,
                ],
            ],
        ]);

        $assembler = new ApplicationScriptsAssembler();
        $this->resetAssemblerCache($assembler);

        $result = $assembler->applySubstitutions('value=###_CUSTOM_DYNAMIC_###');

        self::assertSame('value=resolved-at-runtime-' . PHP_VERSION_ID, $result,
            'Un default callable (closure ou array callable) doit être exécuté par resolveSubstitutionValue.');
    }

    /**
     * Réinitialise le cache de whitelist interne de l'Assembler via réflexion.
     * Nécessaire car `substitutionsCache` est mémoïsé (null → chargé une fois).
     */
    private function resetAssemblerCache(ApplicationScriptsAssembler $assembler): void
    {
        $ref = new \ReflectionProperty($assembler, 'substitutionsCache');
        $ref->setValue($assembler, null);
    }
}
