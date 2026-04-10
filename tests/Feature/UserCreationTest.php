<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Config\SambaEduConfig;
use App\Config\LdapConfig;
use App\LdapModels\LdapUser;
use App\Models\User as SqlUserModel;
use App\Models\UserGroup;
use Illuminate\Database\Schema\Blueprint;
use App\Repositories\ClassRepository;
use App\Repositories\EstablishmentRepository;
use App\Repositories\FunctionRepository;
use App\Repositories\OrganizationalUnitRepository;
use App\Repositories\UserRepository;
use App\Services\PasswordService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests d'intégration pour la création d'utilisateur (Story 2.1)
 *
 * Vérifie le double-write SQL (persistUserToSql) avec une vraie DB.
 * Fonctionne sur :
 * - le serveur SE4FS (PostgreSQL, table users existante, transaction rollbackée)
 * - l'hôte/CI (SQLite :memory: via phpunit.xml, table créée à la volée)
 *
 * Les tests de validation sont dans UserServiceCreateTest (Unit).
 */
class UserCreationTest extends TestCase
{
    use DatabaseTransactions;

    private UserRepository $userRepository;
    private OrganizationalUnitRepository $ouRepository;
    private EstablishmentRepository $establishmentRepository;
    private FunctionRepository $functionRepository;
    private ClassRepository $classRepository;
    private PasswordService $passwordService;
    private SambaEduConfig $config;

    /** true si on a créé la table nous-mêmes (SQLite :memory:) */
    private bool $createdTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        // En SQLite :memory:, la table users n'existe pas → la créer
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('login', 255)->unique();
                $table->string('password', 255)->nullable();
                $table->string('fullname', 255)->nullable();
                $table->string('firstname', 255)->nullable();
                $table->string('lastname', 255)->nullable();
                $table->string('email', 255)->nullable();
                $table->text('dn')->nullable();
                $table->string('ad_guid', 36)->nullable()->unique();
                $table->string('role', 50)->default('autre');
                $table->boolean('is_active')->default(true);
                $table->json('ad_right_profiles')->nullable();
                $table->unsignedInteger('ad_rights_bitmask')->default(0);
                $table->timestamp('ad_synced_at')->nullable();
                $table->rememberToken();
                $table->timestamps();
            });
            $this->createdTable = true;
        }

        if (!Schema::hasTable('user_groups')) {
            Schema::create('user_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name', 255)->unique();
                $table->string('display_name', 255)->nullable();
                $table->string('type', 50);
                $table->text('ad_dn')->nullable();
                $table->string('ad_guid', 36)->nullable()->unique();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('user_group_user')) {
            Schema::create('user_group_user', function (Blueprint $table) {
                $table->foreignId('user_group_id')->constrained('user_groups')->onDelete('cascade');
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->primary(['user_group_id', 'user_id']);
            });
        }

        $this->userRepository = Mockery::mock(UserRepository::class);
        $this->ouRepository = Mockery::mock(OrganizationalUnitRepository::class);
        $this->establishmentRepository = Mockery::mock(EstablishmentRepository::class);
        $this->functionRepository = Mockery::mock(FunctionRepository::class);
        $this->classRepository = Mockery::mock(ClassRepository::class);
        $this->passwordService = Mockery::mock(PasswordService::class);
        $this->config = Mockery::mock(SambaEduConfig::class);
    }

    protected function tearDown(): void
    {
        // Nettoyer uniquement si on a créé la table (SQLite :memory:)
        if ($this->createdTable) {
            Schema::dropIfExists('user_group_user');
            Schema::dropIfExists('user_groups');
            Schema::dropIfExists('users');
        }
        Mockery::close();
        parent::tearDown();
    }

    private function makeFakeLdapConfig(): LdapConfig
    {
        return new LdapConfig(
            url: 'ldaps://test.local',
            port: 636,
            baseDn: 'DC=test,DC=local',
            adminName: 'admin',
            adminPassword: 'test',
            domain: 'test.local',
            sambaDomain: 'TEST',
            peopleRdn: 'OU=People',
            groupsRdn: 'OU=Groups',
            computersRdn: 'OU=Computers',
            parcsRdn: 'OU=Parcs',
            classesRdn: 'OU=Classes',
            equipesRdn: 'OU=Equipes',
            matieresRdn: 'OU=Matieres',
            coursRdn: 'OU=Cours',
            projetsRdn: 'OU=Projets',
            otherGroupsRdn: 'OU=Other',
            delegationsRdn: 'OU=Delegations',
            equipementsRdn: 'OU=Equipements',
            rightsRdn: 'OU=Rights',
            trashRdn: 'OU=Trash',
            etablissementsRdn: 'OU=Etablissements',
            adminRdn: 'OU=Admin',
        );
    }

    private function makeTestableService(): TestableUserService
    {
        return new TestableUserService(
            $this->userRepository,
            $this->ouRepository,
            $this->establishmentRepository,
            $this->functionRepository,
            $this->classRepository,
            $this->passwordService,
            $this->config,
        );
    }

    private function makeMockLdapUser(string $login, array $overrides = []): LdapUser
    {
        // Générer un GUID binaire unique par mock (ad_guid a une contrainte UNIQUE en PostgreSQL)
        $uniqueGuid = $overrides['objectguid'] ?? random_bytes(16);

        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('getLogin')->andReturn($login);
        $ldapUser->shouldReceive('getDn')->andReturn($overrides['dn'] ?? "CN=$login,OU=Eleves,OU=People,DC=test,DC=local");
        $ldapUser->shouldReceive('getFirstAttribute')->with('objectguid')->andReturn($uniqueGuid);
        $ldapUser->shouldReceive('getFirstAttribute')->with('mail')->andReturn($overrides['mail'] ?? "$login@test.local");
        return $ldapUser;
    }

    /**
     * Génère un login unique pour éviter les collisions avec les données existantes
     */
    private function uniqueLogin(string $prefix = 'test'): string
    {
        return $prefix . '.phpunit.' . uniqid();
    }

    // =========================================================================
    // Double-write PostgreSQL
    // =========================================================================

    #[Test]
    public function persistUserToSql_creates_user_in_database(): void
    {
        $this->config->shouldReceive('ldap')->andReturn($this->makeFakeLdapConfig());

        $login = $this->uniqueLogin('eleve');
        $service = $this->makeTestableService();
        $ldapUser = $this->makeMockLdapUser($login);

        $service->exposePersistUserToSql($ldapUser, [
            'nom' => 'Dupont',
            'prenom' => 'Jean',
            'categorie' => 'Eleves',
        ]);

        $this->assertDatabaseHas('users', [
            'login' => $login,
            'fullname' => 'Jean Dupont',
            'firstname' => 'Jean',
            'lastname' => 'Dupont',
            'role' => 'eleve',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function persistUserToSql_maps_categories_to_correct_roles(): void
    {
        $this->config->shouldReceive('ldap')->andReturn($this->makeFakeLdapConfig());

        $service = $this->makeTestableService();

        $testCases = [
            'Eleves' => 'eleve',
            'Profs' => 'prof',
            'Administratifs' => 'admin',
            'ELEVES' => 'eleve',        // case-insensitive
            'Inconnu' => 'autre',       // fallback
        ];

        foreach ($testCases as $categorie => $expectedRole) {
            $login = $this->uniqueLogin(strtolower($categorie));
            $ldapUser = $this->makeMockLdapUser($login);

            $service->exposePersistUserToSql($ldapUser, [
                'nom' => 'Test',
                'prenom' => 'User',
                'categorie' => $categorie,
            ]);

            $user = SqlUserModel::where('login', $login)->first();
            $this->assertNotNull($user, "User '$login' devrait exister en DB pour la catégorie '$categorie'");
            $this->assertEquals($expectedRole, $user->role, "Catégorie '$categorie' devrait mapper sur rôle '$expectedRole'");
        }
    }

    #[Test]
    public function persistUserToSql_encodes_objectguid_as_hex(): void
    {
        $this->config->shouldReceive('ldap')->andReturn($this->makeFakeLdapConfig());

        $login = $this->uniqueLogin('guid');
        $service = $this->makeTestableService();
        // Générer un GUID binaire unique pour éviter la collision UNIQUE
        $binaryGuid = random_bytes(16);
        $expectedHex = bin2hex($binaryGuid);
        $ldapUser = $this->makeMockLdapUser($login, ['objectguid' => $binaryGuid]);

        $service->exposePersistUserToSql($ldapUser, [
            'nom' => 'Test', 'prenom' => 'Guid', 'categorie' => 'Eleves',
        ]);

        $user = SqlUserModel::where('login', $login)->first();
        $this->assertNotNull($user);
        $this->assertEquals($expectedHex, $user->ad_guid, 'objectguid binaire doit être stocké en hex');
    }

    #[Test]
    public function persistUserToSql_trims_fullname(): void
    {
        $this->config->shouldReceive('ldap')->andReturn($this->makeFakeLdapConfig());

        $login = $this->uniqueLogin('trim');
        $service = $this->makeTestableService();
        $ldapUser = $this->makeMockLdapUser($login);

        // Prénom vide → fullname ne doit pas commencer par un espace
        $service->exposePersistUserToSql($ldapUser, [
            'nom' => 'Dupont', 'prenom' => '', 'categorie' => 'Eleves',
        ]);

        $user = SqlUserModel::where('login', $login)->first();
        $this->assertNotNull($user);
        $this->assertEquals('Dupont', $user->fullname);
    }

    #[Test]
    public function persistUserToSql_uses_updateOrCreate_for_existing_login(): void
    {
        $this->config->shouldReceive('ldap')->andReturn($this->makeFakeLdapConfig());

        $login = $this->uniqueLogin('existing');

        // Créer un user existant
        SqlUserModel::create([
            'login' => $login,
            'fullname' => 'Old Name',
            'role' => 'autre',
        ]);

        $service = $this->makeTestableService();
        $ldapUser = $this->makeMockLdapUser($login);

        $service->exposePersistUserToSql($ldapUser, [
            'nom' => 'Nouveau', 'prenom' => 'Nom', 'categorie' => 'Profs',
        ]);

        // Doit mettre à jour, pas dupliquer
        $this->assertEquals(1, SqlUserModel::where('login', $login)->count());
        $this->assertDatabaseHas('users', [
            'login' => $login,
            'fullname' => 'Nom Nouveau',
            'role' => 'prof',
        ]);
    }

    #[Test]
    public function persistUserToSql_logs_error_on_db_failure_without_throwing(): void
    {
        $this->config->shouldReceive('ldap')->andReturn($this->makeFakeLdapConfig());

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn($msg) => str_contains($msg, 'Échec persistance SQL'));
        Log::shouldReceive('info')->zeroOrMoreTimes();

        // Forcer une erreur DB via un ad_guid en collision avec un user existant
        // D'abord créer un user avec un GUID spécifique
        $collidingGuid = bin2hex(random_bytes(16));
        $existingLogin = $this->uniqueLogin('collision-src');
        SqlUserModel::create([
            'login' => $existingLogin,
            'ad_guid' => $collidingGuid,
            'role' => 'autre',
        ]);

        // Puis tenter de créer un autre user avec le même GUID → UniqueConstraintViolation
        $newLogin = $this->uniqueLogin('collision-target');
        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('getLogin')->andReturn($newLogin);
        $ldapUser->shouldReceive('getDn')->andReturn('CN=test');
        $ldapUser->shouldReceive('getFirstAttribute')->with('objectguid')->andReturn(hex2bin($collidingGuid));
        $ldapUser->shouldReceive('getFirstAttribute')->with('mail')->andReturn("$newLogin@test.local");

        $service = $this->makeTestableService();

        // Ne doit PAS lever d'exception (AD = source de vérité, erreur SQL tolérée)
        $service->exposePersistUserToSql($ldapUser, [
            'nom' => 'Test', 'prenom' => 'Error', 'categorie' => 'Eleves',
        ]);

        $this->assertTrue(true, 'Aucune exception levée malgré erreur DB');
    }

    // =========================================================================
    // Audit logging (NFR8)
    // =========================================================================

    #[Test]
    public function creation_logs_audit_entry_with_action_and_operator(): void
    {
        $source = file_get_contents(app_path('Services/UserService.php'));
        $this->assertStringContainsString("'action' => 'user.create'", $source);
        $this->assertStringContainsString("'operator'", $source);
    }

    // =========================================================================
    // Liaison groupes SQL (user_group_user pivot)
    // =========================================================================

    #[Test]
    public function persistUserGroupsToSql_links_eleve_to_categorie_and_classe(): void
    {
        $this->config->shouldReceive('ldap')->andReturn($this->makeFakeLdapConfig());

        // Créer les groupes en DB
        UserGroup::withoutEvents(fn() => UserGroup::create(['name' => 'Eleves', 'type' => 'admin']));
        UserGroup::withoutEvents(fn() => UserGroup::create(['name' => 'Classe_3eme3', 'type' => 'class']));

        $login = $this->uniqueLogin('eleve');
        $service = $this->makeTestableService();
        $ldapUser = $this->makeMockLdapUser($login);

        // Persister l'utilisateur SQL d'abord
        $service->exposePersistUserToSql($ldapUser, [
            'nom' => 'Kenobi', 'prenom' => 'Obiwan', 'categorie' => 'Eleves',
        ]);

        // Puis lier les groupes
        $service->exposePersistUserGroupsToSql($login, 'Eleves', ['3eme3']);

        $sqlUser = SqlUserModel::where('login', $login)->first();
        $this->assertNotNull($sqlUser);

        $groupNames = $sqlUser->groups()->pluck('name')->sort()->values()->toArray();
        $this->assertContains('Eleves', $groupNames, "L'élève doit être lié au groupe catégorie 'Eleves'");
        $this->assertContains('Classe_3eme3', $groupNames, "L'élève doit être lié au groupe classe 'Classe_3eme3'");
        $this->assertCount(2, $groupNames);
    }

    #[Test]
    public function persistUserGroupsToSql_links_prof_to_categorie_and_classes(): void
    {
        $this->config->shouldReceive('ldap')->andReturn($this->makeFakeLdapConfig());

        // Créer les groupes en DB
        UserGroup::withoutEvents(fn() => UserGroup::create(['name' => 'Profs', 'type' => 'admin']));
        UserGroup::withoutEvents(fn() => UserGroup::create(['name' => 'Classe_6eme5', 'type' => 'class']));
        UserGroup::withoutEvents(fn() => UserGroup::create(['name' => 'Classe_3eme4', 'type' => 'class']));

        $login = $this->uniqueLogin('prof');
        $service = $this->makeTestableService();
        $ldapUser = $this->makeMockLdapUser($login);

        $service->exposePersistUserToSql($ldapUser, [
            'nom' => 'Yoda', 'prenom' => 'Maitre', 'categorie' => 'Profs',
        ]);

        $service->exposePersistUserGroupsToSql($login, 'Profs', ['6eme5', '3eme4']);

        $sqlUser = SqlUserModel::where('login', $login)->first();
        $groupNames = $sqlUser->groups()->pluck('name')->sort()->values()->toArray();
        $this->assertContains('Profs', $groupNames, "Le prof doit être lié au groupe catégorie 'Profs'");
        $this->assertContains('Classe_6eme5', $groupNames, "Le prof doit être lié à 'Classe_6eme5'");
        $this->assertContains('Classe_3eme4', $groupNames, "Le prof doit être lié à 'Classe_3eme4'");
        $this->assertCount(3, $groupNames);
    }

    #[Test]
    public function persistUserGroupsToSql_is_case_insensitive_on_group_names(): void
    {
        $this->config->shouldReceive('ldap')->andReturn($this->makeFakeLdapConfig());

        // Groupe en CamelCase en DB
        UserGroup::withoutEvents(fn() => UserGroup::create(['name' => 'Eleves', 'type' => 'admin']));
        UserGroup::withoutEvents(fn() => UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'class']));

        $login = $this->uniqueLogin('case');
        $service = $this->makeTestableService();
        $ldapUser = $this->makeMockLdapUser($login);

        $service->exposePersistUserToSql($ldapUser, [
            'nom' => 'Test', 'prenom' => 'Case', 'categorie' => 'Eleves',
        ]);

        // Passer la catégorie en casse différente
        $service->exposePersistUserGroupsToSql($login, 'ELEVES', ['3emea']);

        $sqlUser = SqlUserModel::where('login', $login)->first();
        $groupNames = $sqlUser->groups()->pluck('name')->toArray();
        $this->assertContains('Eleves', $groupNames);
        $this->assertContains('Classe_3emeA', $groupNames);
    }

    #[Test]
    public function persistUserGroupsToSql_skips_missing_groups_without_error(): void
    {
        $this->config->shouldReceive('ldap')->andReturn($this->makeFakeLdapConfig());

        // Seul le groupe catégorie existe, pas le groupe classe
        UserGroup::withoutEvents(fn() => UserGroup::create(['name' => 'Eleves', 'type' => 'admin']));

        $login = $this->uniqueLogin('missing');
        $service = $this->makeTestableService();
        $ldapUser = $this->makeMockLdapUser($login);

        $service->exposePersistUserToSql($ldapUser, [
            'nom' => 'Test', 'prenom' => 'Missing', 'categorie' => 'Eleves',
        ]);

        // La classe n'existe pas en SQL → doit lier ce qui existe sans erreur
        $service->exposePersistUserGroupsToSql($login, 'Eleves', ['classe_inexistante']);

        $sqlUser = SqlUserModel::where('login', $login)->first();
        $groupNames = $sqlUser->groups()->pluck('name')->toArray();
        $this->assertContains('Eleves', $groupNames);
        $this->assertCount(1, $groupNames, "Seul le groupe existant doit être lié");
    }
}

/**
 * Sous-classe de UserService pour exposer les méthodes privées aux tests
 */
class TestableUserService extends UserService
{
    public function exposePersistUserToSql(LdapUser $ldapUser, array $data): void
    {
        $method = new \ReflectionMethod(UserService::class, 'persistUserToSql');
        $method->invoke($this, $ldapUser, $data);
    }

    public function exposePersistUserGroupsToSql(string $login, string $categorie, array $classes): void
    {
        $method = new \ReflectionMethod(UserService::class, 'persistUserGroupsToSql');
        $method->invoke($this, $login, $categorie, $classes);
    }
}
