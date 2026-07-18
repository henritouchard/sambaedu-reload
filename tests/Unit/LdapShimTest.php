<?php

namespace Tests\Unit;

use App\Models\ErrorLog;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\ErrorLoggerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests unitaires pour legacy/ldap.inc.php (shim LDAP → Eloquent).
 *
 * Vérifie que chaque fonction LDAP shimmée retourne un résultat cohérent
 * avec le format attendu par le code legacy.
 */
class LdapShimTest extends TestCase
{
    private array $config = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Neutraliser les observers AD (host sans LDAP) — la création de
        // UserGroup/User déclenche sinon un job AdSync inline.
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();

        // Créer les tables nécessaires en mémoire
        $this->createTestTables();

        // Configurer la config Laravel
        Config::set('sambaedu.legacy_ldap.base_dn', 'DC=ecole,DC=local');
        Config::set('sambaedu.etab_ou', '');

        // Charger le shim
        require_once base_path('legacy/bootstrap.php');

        // Construire un $config minimal pour les tests
        $this->config = [
            'ldap_base_dn' => 'DC=ecole,DC=local',
            'domain' => 'ecole.local',
            'etab_ou' => '',
            'bind' => new \LdapShimConnection(),
            'dn' => [
                'people' => 'OU=people,DC=ecole,DC=local',
                'groups' => 'OU=groups,DC=ecole,DC=local',
                'computers' => 'OU=computers,DC=ecole,DC=local',
            ],
        ];
    }

    protected function tearDown(): void
    {
        User::query()->delete();
        UserGroup::query()->delete();
        Workstation::query()->delete();
        ErrorLog::query()->delete();
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    private function createTestTables(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('login')->unique();
                $table->string('password')->nullable();
                $table->string('fullname')->nullable();
                $table->string('firstname')->nullable();
                $table->string('lastname')->nullable();
                $table->string('email')->nullable();
                $table->string('dn')->nullable();
                $table->string('ad_guid')->nullable();
                $table->string('role')->default('eleve');
                $table->boolean('is_active')->default(true);
                $table->json('ad_right_profiles')->nullable();
                $table->integer('ad_rights_bitmask')->default(0);
                $table->timestamp('ad_synced_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('user_groups')) {
            Schema::create('user_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('display_name')->nullable();
                $table->string('type')->default('groupe');
                $table->string('ad_dn')->nullable();
                $table->string('ad_guid')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('user_group_user')) {
            Schema::create('user_group_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id');
                $table->foreignId('user_group_id');
                // Colonne pivot lue par la relation User↔UserGroup (PP sur l'arête).
                $table->boolean('is_head_teacher')->default(false);
                // Story 42.1 — rôle sur l'arête, lu par withPivot('role').
                $table->string('role', 20)->default('member');
            });
        }

        if (!Schema::hasTable('workstations')) {
            Schema::create('workstations', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('os')->nullable();
                $table->string('ip')->nullable();
                $table->string('mac')->nullable();
                $table->string('uuid')->nullable();
                $table->string('status')->default('active');
                $table->timestamp('last_report_at')->nullable();
                $table->string('report_sha')->nullable();
                $table->string('log_path')->nullable();
                $table->string('report_path')->nullable();
                $table->string('ad_dn')->nullable();
                $table->string('ad_guid')->nullable();
                $table->boolean('managed_by_control_hub')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('error_logs')) {
            Schema::create('error_logs', function (Blueprint $table) {
                $table->id();
                $table->string('source', 10);
                $table->text('message');
                $table->timestamp('created_at');
            });
        }
    }

    // ─── Tests search_ad (type=user) ────────────────────────────────────────

    /**
     * AC3 — search_ad(type=user) retourne les utilisateurs depuis Eloquent.
     */
    public function test_search_ad_user_returns_users_from_eloquent(): void
    {
        User::create([
            'login' => 'jdupont',
            'fullname' => 'Jean Dupont',
            'firstname' => 'Jean',
            'lastname' => 'Dupont',
            'email' => 'jean@ecole.local',
            'role' => 'prof',
            'is_active' => true,
        ]);

        $result = search_ad($this->config, 'jdupont', 'user');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('count', $result);
        $this->assertEquals(1, $result['count']);
        $this->assertEquals('jdupont', $result[0]['cn']);
        $this->assertEquals('Jean Dupont', $result[0]['fullname']);
        $this->assertEquals('Dupont', $result[0]['nom']);
        $this->assertEquals('Jean', $result[0]['prenom']);
        $this->assertEquals('jean@ecole.local', $result[0]['email']);
    }

    /**
     * AC3 — search_ad(type=user, name=*) retourne tous les utilisateurs.
     */
    public function test_search_ad_user_wildcard_returns_all(): void
    {
        User::create(['login' => 'user1', 'role' => 'eleve', 'is_active' => true]);
        User::create(['login' => 'user2', 'role' => 'prof', 'is_active' => true]);

        $result = search_ad($this->config, '*', 'user');

        $this->assertEquals(2, $result['count']);
    }

    /**
     * AC4 — Le format de retour est cohérent avec le format LDAP (tableau indexé + count).
     */
    public function test_result_format_matches_ldap_format(): void
    {
        User::create(['login' => 'test', 'role' => 'eleve', 'is_active' => true]);

        $result = search_ad($this->config, 'test', 'user');

        // Vérifier la structure du format LDAP
        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey(0, $result);

        // Vérifier les attributs LDAP attendus
        $entry = $result[0];
        $this->assertArrayHasKey('cn', $entry);
        $this->assertArrayHasKey('dn', $entry);
        $this->assertArrayHasKey('displayname', $entry);
        $this->assertArrayHasKey('fullname', $entry);
        $this->assertArrayHasKey('nom', $entry);
        $this->assertArrayHasKey('prenom', $entry);
        $this->assertArrayHasKey('email', $entry);
        $this->assertArrayHasKey('memberof', $entry);
        $this->assertArrayHasKey('useraccountcontrol', $entry);
    }

    // ─── Tests search_ad (type=group) ───────────────────────────────────────

    /**
     * AC3 — search_ad(type=group) retourne les groupes depuis Eloquent.
     */
    public function test_search_ad_group_returns_groups(): void
    {
        UserGroup::create(['name' => '3emeA', 'display_name' => 'Classe 3ème A', 'type' => 'classe']);

        $result = search_ad($this->config, '3emeA', 'group');

        $this->assertEquals(1, $result['count']);
        $this->assertEquals('3emeA', $result[0]['cn']);
        $this->assertEquals('Classe 3ème A', $result[0]['displayname']);
        $this->assertArrayHasKey('member', $result[0]);
    }

    // ─── Tests search_ad (type=machine) ─────────────────────────────────────

    /**
     * AC3 — search_ad(type=machine) retourne les workstations.
     */
    public function test_search_ad_machine_returns_workstations(): void
    {
        Workstation::create([
            'name' => 'PC-SALLE1-01',
            'os' => 'Windows 10',
            'ip' => '192.168.1.100',
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'status' => 'active',
        ]);

        $result = search_ad($this->config, 'PC-SALLE1-01', 'machine');

        $this->assertEquals(1, $result['count']);
        $this->assertEquals('PC-SALLE1-01', $result[0]['cn']);
        $this->assertEquals('192.168.1.100', $result[0]['ip']);
    }

    // ─── Tests search_user / search_group / search_machine ──────────────────

    /**
     * AC3 — search_user est un alias de search_ad(type=user).
     */
    public function test_search_user_delegates_to_search_ad(): void
    {
        User::create(['login' => 'mmartin', 'fullname' => 'Marie Martin', 'role' => 'prof', 'is_active' => true]);

        $result = search_user($this->config, 'mmartin');

        $this->assertEquals(1, $result['count']);
        $this->assertEquals('mmartin', $result[0]['cn']);
    }

    /**
     * AC3 — search_machine avec $ip=true cherche par IP.
     */
    public function test_search_machine_by_ip(): void
    {
        Workstation::create(['name' => 'PC-01', 'ip' => '10.0.0.5', 'status' => 'active']);

        $result = search_machine($this->config, '10.0.0.5', true);

        $this->assertNotEmpty($result);
        $this->assertEquals('PC-01', $result['cn']);
    }

    // ─── Tests list_* functions ──────────────────────────────────────────────

    /**
     * AC3 — list_groups retourne les groupes d'un utilisateur.
     */
    public function test_list_groups_returns_user_groups(): void
    {
        $user = User::create(['login' => 'eleve1', 'role' => 'eleve', 'is_active' => true]);
        $group = UserGroup::create(['name' => '3emeB', 'type' => 'classe']);
        $user->groups()->attach($group->id);

        $result = list_groups($this->config, 'eleve1');

        $this->assertEquals(1, $result['count']);
        $this->assertEquals('3emeB', $result[0]['cn']);
    }

    /**
     * AC3 — list_members_group retourne les membres d'un groupe.
     */
    public function test_list_members_group_returns_members(): void
    {
        $group = UserGroup::create(['name' => 'Profs', 'type' => 'equipe']);
        $user = User::create(['login' => 'prof1', 'fullname' => 'Prof Un', 'role' => 'prof', 'is_active' => true]);
        $group->users()->attach($user->id);

        $result = list_members_group($this->config, 'Profs');

        $this->assertEquals(1, $result['count']);
        $this->assertEquals('prof1', $result[0]['cn']);
    }

    // ─── Tests fonctions non shimmées → erreur explicite ────────────────────

    /**
     * AC5 — Une fonction non shimmée logge une erreur via ErrorLoggerService.
     */
    public function test_unshimmed_function_logs_error(): void
    {
        // modify_ad n'est pas shimmée (lecture seule)
        $result = modify_ad($this->config, 'test', 'user', ['attr' => 'val']);

        $this->assertFalse($result);

        $this->assertDatabaseHas('error_logs', [
            'source' => 'legacy',
        ]);

        $log = ErrorLog::latest('id')->first();
        $this->assertStringContainsString('Fonction LDAP non shimmée', $log->message);
        $this->assertStringContainsString('modify_ad', $log->message);
    }

    /**
     * AC5 — delete_ad non shimmée logge une erreur.
     */
    public function test_delete_ad_logs_error(): void
    {
        $result = delete_ad($this->config, 'test');

        $this->assertFalse($result);
        $this->assertDatabaseHas('error_logs', [
            'source' => 'legacy',
        ]);
    }

    /**
     * AC5 — search_ad avec type non supporté logge une erreur.
     */
    public function test_search_ad_unsupported_type_logs_error(): void
    {
        $result = search_ad($this->config, 'test', 'delegation');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
        $this->assertDatabaseHas('error_logs', [
            'source' => 'legacy',
        ]);
    }

    // ─── Tests fonctions utilitaires DN ──────────────────────────────────────

    /**
     * AC3 — ldap_dn2cn extrait le CN d'un DN.
     */
    public function test_ldap_dn2cn_extracts_cn(): void
    {
        $this->assertEquals('jdupont', ldap_dn2cn('CN=jdupont,OU=people,DC=ecole,DC=local'));
    }

    /**
     * AC3 — ldap_dn2ou extrait la première OU d'un DN.
     */
    public function test_ldap_dn2ou_extracts_ou(): void
    {
        $this->assertEquals('people', ldap_dn2ou('CN=jdupont,OU=people,DC=ecole,DC=local'));
    }

    /**
     * AC3 — ldap_dn2parent retourne le DN parent.
     */
    public function test_ldap_dn2parent_returns_parent(): void
    {
        $this->assertEquals(
            'OU=people,DC=ecole,DC=local',
            ldap_dn2parent('CN=jdupont,OU=people,DC=ecole,DC=local')
        );
    }

    /**
     * AC3 — ldap_dn2uai extrait l'UAI d'un DN.
     */
    public function test_ldap_dn2uai_extracts_uai(): void
    {
        $this->assertEquals('0991229Y', ldap_dn2uai('CN=jdupont,OU=0991229Y,OU=people,DC=ecole,DC=local'));
        $this->assertEquals('', ldap_dn2uai('CN=jdupont,OU=people,DC=ecole,DC=local'));
    }

    // ─── Tests get_config shim ──────────────────────────────────────────────

    /**
     * AC3 — get_config retourne un config avec bind factice.
     */
    public function test_get_config_returns_config_with_fake_bind(): void
    {
        $result = get_config($this->config);

        $this->assertArrayHasKey('bind', $result);
        $this->assertInstanceOf(\LdapShimConnection::class, $result['bind']);
        $this->assertTrue($result['bind']->connected);
    }

    // ─── Tests filter_* functions ───────────────────────────────────────────

    /**
     * AC3 — filter_group_classes retourne les groupes de type classe.
     */
    public function test_filter_group_classes_returns_classes(): void
    {
        UserGroup::create(['name' => '3emeA', 'type' => 'classe']);
        UserGroup::create(['name' => 'Profs', 'type' => 'equipe']);

        $result = filter_group_classes($this->config);

        $this->assertEquals(1, $result['count']);
        $this->assertEquals('3emeA', $result[0]['cn']);
    }

    // ─── Tests comparaison functions ────────────────────────────────────────

    /**
     * AC3 — cmp_fullname compare correctement.
     */
    public function test_cmp_fullname_compares_correctly(): void
    {
        $a = ['fullname' => 'Alice'];
        $b = ['fullname' => 'Bob'];

        $this->assertLessThan(0, cmp_fullname($a, $b));
        $this->assertGreaterThan(0, cmp_fullname($b, $a));
        $this->assertEquals(0, cmp_fullname($a, $a));
    }

    // ─── Test useraccountcontrol ─────────────────────────────────────────────

    /**
     * AC3 — Un utilisateur actif a useraccountcontrol=512, inactif=514.
     */
    public function test_user_account_control_reflects_active_status(): void
    {
        User::create(['login' => 'active', 'is_active' => true, 'role' => 'eleve']);
        User::create(['login' => 'inactive', 'is_active' => false, 'role' => 'eleve']);

        $resultActive = search_ad($this->config, 'active', 'user');
        $resultInactive = search_ad($this->config, 'inactive', 'user');

        $this->assertEquals('512', $resultActive[0]['useraccountcontrol']);
        $this->assertEquals('514', $resultInactive[0]['useraccountcontrol']);
    }

    // ─── Tests ad_url ──────────────────────────────────────────────────────

    public function test_ad_url_dns_mode_returns_bare_fqdn(): void
    {
        $config = ['se4ad_name' => 'se4ad', 'domain' => 'ecole.local'];
        $this->assertEquals('se4ad.ecole.local', ad_url($config, 'dns'));
    }

    public function test_ad_url_sambatool_mode_returns_host_option(): void
    {
        $config = ['se4ad_name' => 'se4ad', 'domain' => 'ecole.local'];
        $this->assertEquals('-H ldap://se4ad.ecole.local ', ad_url($config, 'sambatool'));
    }

    public function test_ad_url_ldap_mode_returns_url_with_port(): void
    {
        $config = ['se4ad_name' => 'se4ad', 'domain' => 'ecole.local'];
        $this->assertEquals('ldap://se4ad.ecole.local:389', ad_url($config, 'ldap'));
        $this->assertEquals('ldaps://se4ad.ecole.local:636', ad_url($config, 'ldaps'));
    }
}
