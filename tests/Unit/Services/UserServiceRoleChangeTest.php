<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Config\LdapConfig;
use App\Config\SambaEduConfig;
use App\LdapModels\LdapUser;
use App\LdapModels\SambaEduGroup;
use App\Models\User as SqlUserModel;
use App\Repositories\ClassRepository;
use App\Repositories\EstablishmentRepository;
use App\Repositories\FunctionRepository;
use App\Repositories\OrganizationalUnitRepository;
use App\Repositories\UserRepository;
use App\Services\PasswordService;
use App\Services\UserService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;
use Tests\Traits\MocksAdminUser;

class UserServiceRoleChangeTest extends TestCase
{
    use MocksAdminUser;

    private UserService $service;
    private UserRepository $userRepository;
    private OrganizationalUnitRepository $ouRepository;
    private EstablishmentRepository $establishmentRepository;
    private FunctionRepository $functionRepository;
    private ClassRepository $classRepository;
    private PasswordService $passwordService;
    private SambaEduConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = Mockery::mock(UserRepository::class);
        $this->ouRepository = Mockery::mock(OrganizationalUnitRepository::class);
        $this->establishmentRepository = Mockery::mock(EstablishmentRepository::class);
        $this->functionRepository = Mockery::mock(FunctionRepository::class);
        $this->classRepository = Mockery::mock(ClassRepository::class);
        $this->passwordService = Mockery::mock(PasswordService::class);
        $this->config = Mockery::mock(SambaEduConfig::class);

        $this->service = new UserService(
            $this->userRepository,
            $this->ouRepository,
            $this->establishmentRepository,
            $this->functionRepository,
            $this->classRepository,
            $this->passwordService,
            $this->config
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // Tests changeUserRole() — Permissions
    // =========================================================================

    /** @test */
    public function changeUserRole_rejects_when_no_permission(): void
    {
        // Sans utilisateur authentifié, la policy refuse
        $result = $this->service->changeUserRole('testuser', 'Administratifs', 'Agent', 0);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('droits', $result['message']);
    }

    // =========================================================================
    // Tests changeUserRole() — Validation
    // =========================================================================

    /** @test */
    public function changeUserRole_rejects_administratif_without_fonction(): void
    {
        $this->actAsAdmin();

        $result = $this->service->changeUserRole('testuser', 'Administratifs', '', 0);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('fonction', strtolower($result['message']));
    }

    /** @test */
    public function changeUserRole_rejects_when_user_not_found(): void
    {
        $this->actAsAdmin();

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('unknown')
            ->andReturn(null);

        $result = $this->service->changeUserRole('unknown', 'Profs', '', 0);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('introuvable', $result['message']);
    }

    // =========================================================================
    // Tests moveUserDn() — Pas de move si inchangé
    // =========================================================================

    /** @test */
    public function moveUserDn_returns_success_when_no_change_needed(): void
    {
        $oldDn = 'CN=jean.dupont,OU=Profs,OU=Utilisateurs,DC=test,DC=local';

        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('getLogin')->andReturn('jean.dupont');
        $ldapUser->shouldReceive('getDn')->andReturn($oldDn);

        // buildUserDn retournera le même DN → pas de move
        $this->config->shouldReceive('ldap')->andReturn($this->makeLdapConfig());

        $this->establishmentRepository->shouldReceive('toUai')
            ->with(0)
            ->andReturn('0');

        $result = $this->service->moveUserDn($ldapUser, 'Profs', '', 0);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Aucun déplacement', $result['message']);
    }

    // =========================================================================
    // Tests moveUserDn() — Déplacement effectif
    // =========================================================================

    /** @test */
    public function moveUserDn_calls_ensureOUsExist_and_attempts_ldap_rename(): void
    {
        $oldDn = 'CN=jean.dupont,OU=Profs,OU=Utilisateurs,DC=test,DC=local';

        $mockLdapConnection = Mockery::mock(\LdapRecord\LdapInterface::class);
        $mockConnection = Mockery::mock(\LdapRecord\Connection::class);
        $mockConnection->shouldReceive('getLdapConnection')->andReturn($mockLdapConnection);

        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('getLogin')->andReturn('jean.dupont');
        $ldapUser->shouldReceive('getDn')->andReturn($oldDn);
        $ldapUser->shouldReceive('getConnection')->andReturn($mockConnection);

        $this->config->shouldReceive('ldap')->andReturn($this->makeLdapConfig());

        $this->establishmentRepository->shouldReceive('toUai')
            ->with(0)
            ->andReturn('0');

        // Vérifier que ensureUserOUsExist est appelé avec les bons paramètres
        $this->ouRepository->shouldReceive('ensureUserOUsExist')
            ->with('Administratifs', 'Agent', 0)
            ->once();

        // ldap_rename échouera (fake connection) — on vérifie la préparation
        $result = $this->service->moveUserDn($ldapUser, 'Administratifs', 'Agent', 0);

        // ldap_rename échoue sur fake connection, c'est attendu
        $this->assertFalse($result['success']);
        // Mockery vérifie que ensureUserOUsExist a été appelé une fois avec les bons params
    }

    // =========================================================================
    // Tests cas spéciaux Documentaliste/AESH
    // =========================================================================

    /** @test */
    public function moveUserDn_places_documentaliste_under_profs(): void
    {
        $oldDn = 'CN=jean.dupont,OU=Agent,OU=Administratifs,OU=Utilisateurs,DC=test,DC=local';

        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('getLogin')->andReturn('jean.dupont');
        $ldapUser->shouldReceive('getDn')->andReturn($oldDn);

        $this->config->shouldReceive('ldap')->andReturn($this->makeLdapConfig());

        $this->establishmentRepository->shouldReceive('toUai')
            ->with(0)
            ->andReturn('0');

        // Documentaliste doit être créé sous Profs, pas sous Administratifs
        $this->ouRepository->shouldReceive('ensureUserOUsExist')
            ->with('Profs', 'Documentaliste', 0)
            ->once();

        $mockLdapConnection = Mockery::mock(\LdapRecord\LdapInterface::class);
        $mockConnection = Mockery::mock(\LdapRecord\Connection::class);
        $mockConnection->shouldReceive('getLdapConnection')->andReturn($mockLdapConnection);
        $ldapUser->shouldReceive('getConnection')->andReturn($mockConnection);

        // ldap_rename échouera car fake connection, mais on vérifie que ensureUserOUsExist
        // est appelé avec 'Profs' et non 'Administratifs'
        $result = $this->service->moveUserDn($ldapUser, 'Administratifs', 'Documentaliste', 0);

        // Le test passe si ensureUserOUsExist a été appelé avec 'Profs' (vérifié par Mockery)
        $this->assertFalse($result['success']); // échoue car fake connection, c'est attendu
    }

    /** @test */
    public function moveUserDn_places_aesh_under_profs(): void
    {
        $oldDn = 'CN=marie.martin,OU=Agent,OU=Administratifs,OU=Utilisateurs,DC=test,DC=local';

        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('getLogin')->andReturn('marie.martin');
        $ldapUser->shouldReceive('getDn')->andReturn($oldDn);

        $this->config->shouldReceive('ldap')->andReturn($this->makeLdapConfig());

        $this->establishmentRepository->shouldReceive('toUai')
            ->with(0)
            ->andReturn('0');

        // AESH doit être créé sous Profs
        $this->ouRepository->shouldReceive('ensureUserOUsExist')
            ->with('Profs', 'AESH', 0)
            ->once();

        $mockLdapConnection = Mockery::mock(\LdapRecord\LdapInterface::class);
        $mockConnection = Mockery::mock(\LdapRecord\Connection::class);
        $mockConnection->shouldReceive('getLdapConnection')->andReturn($mockLdapConnection);
        $ldapUser->shouldReceive('getConnection')->andReturn($mockConnection);

        $result = $this->service->moveUserDn($ldapUser, 'Administratifs', 'AESH', 0);

        // ensureUserOUsExist appelé avec 'Profs' vérifié par Mockery
        $this->assertFalse($result['success']); // fake connection
    }

    // =========================================================================
    // Tests syncRoleGroups()
    // =========================================================================

    /** @test */
    public function syncRoleGroups_removes_old_category_group_and_adds_new(): void
    {
        // syncRoleGroups utilise des appels statiques (SambaEduGroup::findMainGroup(),
        // SambaEduGroup::query()) qui ne peuvent pas être mockés sans refactoring
        // de l'injection de dépendances ou un serveur LDAP réel.
        // Ce test nécessite un test d'intégration sur la VM.
        $this->markTestIncomplete(
            'syncRoleGroups repose sur des appels statiques SambaEduGroup — '
            . 'nécessite un test d\'intégration avec serveur LDAP réel.'
        );
    }

    private function makeLdapConfig(): LdapConfig
    {
        return new LdapConfig(
            url: 'ldaps://test.local',
            port: 636,
            baseDn: 'DC=test,DC=local',
            adminName: 'admin',
            adminPassword: 'password',
            domain: 'test.local',
            sambaDomain: 'TEST',
            peopleRdn: 'OU=Utilisateurs',
            groupsRdn: 'OU=Groupes',
            computersRdn: 'OU=Computers',
            parcsRdn: 'OU=Parcs',
            classesRdn: 'OU=Classes',
            equipesRdn: 'OU=Equipes',
            matieresRdn: 'OU=Matieres',
            coursRdn: 'OU=Cours',
            projetsRdn: 'OU=Projets',
            otherGroupsRdn: 'OU=Autres',
            delegationsRdn: 'OU=Delegations',
            equipementsRdn: 'OU=Equipements',
            rightsRdn: 'OU=Rights',
            trashRdn: 'OU=Corbeille',
            etablissementsRdn: 'OU=Etablissements',
            adminRdn: 'OU=Admin',
            strictLocalAd: false,
        );
    }
}
