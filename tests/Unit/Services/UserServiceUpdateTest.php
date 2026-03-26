<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Config\SambaEduConfig;
use App\LdapModels\LdapUser;
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

class UserServiceUpdateTest extends TestCase
{
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

        // Par défaut, l'utilisateur a les droits de modification
        Gate::define('update-user', fn () => true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // Tests updatePersonalInfo() — Permissions (D1)
    // =========================================================================

    /** @test */
    public function updatePersonalInfo_rejects_when_no_permission(): void
    {
        Gate::define('update-user', fn () => false);

        $result = $this->service->updatePersonalInfo('testuser', [
            'prenom' => 'Jean',
            'nom' => 'Dupont',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('droits', $result['message']);
    }

    // =========================================================================
    // Tests updatePersonalInfo() — Validation
    // =========================================================================

    /** @test */
    public function updatePersonalInfo_requires_prenom(): void
    {
        $result = $this->service->updatePersonalInfo('testuser', [
            'prenom' => '',
            'nom' => 'Dupont',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('prénom est requis', $result['message']);
    }

    /** @test */
    public function updatePersonalInfo_requires_nom(): void
    {
        $result = $this->service->updatePersonalInfo('testuser', [
            'prenom' => 'Jean',
            'nom' => '',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('nom est requis', $result['message']);
    }

    /** @test */
    public function updatePersonalInfo_rejects_prenom_over_64_chars(): void
    {
        $result = $this->service->updatePersonalInfo('testuser', [
            'prenom' => str_repeat('A', 65),
            'nom' => 'Dupont',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('prénom', $result['message']);
        $this->assertStringContainsString('64', $result['message']);
    }

    /** @test */
    public function updatePersonalInfo_rejects_nom_over_64_chars(): void
    {
        $result = $this->service->updatePersonalInfo('testuser', [
            'prenom' => 'Jean',
            'nom' => str_repeat('A', 65),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('nom', $result['message']);
        $this->assertStringContainsString('64', $result['message']);
    }

    /** @test */
    public function updatePersonalInfo_rejects_whitespace_only_prenom(): void
    {
        $result = $this->service->updatePersonalInfo('testuser', [
            'prenom' => '   ',
            'nom' => 'Dupont',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('prénom est requis', $result['message']);
    }

    /** @test */
    public function updatePersonalInfo_rejects_invalid_email(): void
    {
        $result = $this->service->updatePersonalInfo('testuser', [
            'prenom' => 'Jean',
            'nom' => 'Dupont',
            'email' => 'not-an-email',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('email', $result['message']);
    }

    /** @test */
    public function updatePersonalInfo_accepts_empty_email(): void
    {
        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('save')->once();
        $ldapUser->shouldReceive('__set')->withAnyArgs();

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('testuser')
            ->andReturn($ldapUser);
        $this->userRepository->shouldReceive('invalidateCache')
            ->with('testuser');

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $result = $this->service->updatePersonalInfo('testuser', [
            'prenom' => 'Jean',
            'nom' => 'Dupont',
            'email' => '',
        ]);

        $this->assertTrue($result['success']);
    }

    /** @test */
    public function updatePersonalInfo_rejects_phone_over_20_chars(): void
    {
        $result = $this->service->updatePersonalInfo('testuser', [
            'prenom' => 'Jean',
            'nom' => 'Dupont',
            'phone' => str_repeat('1', 21),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('téléphone', $result['message']);
    }

    /** @test */
    public function updatePersonalInfo_rejects_description_over_1000_chars(): void
    {
        $result = $this->service->updatePersonalInfo('testuser', [
            'prenom' => 'Jean',
            'nom' => 'Dupont',
            'description' => str_repeat('a', 1001),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('description', $result['message']);
    }

    // =========================================================================
    // Tests updatePersonalInfo() — LDAP attributes mapping
    // =========================================================================

    /** @test */
    public function updatePersonalInfo_sets_ldap_attributes_correctly(): void
    {
        $ldapUser = Mockery::mock(LdapUser::class);

        $ldapUser->shouldReceive('__set')->with('givenname', 'Marie')->once();
        $ldapUser->shouldReceive('__set')->with('sn', 'Martin')->once();
        $ldapUser->shouldReceive('__set')->with('displayname', 'Marie Martin')->once();
        $ldapUser->shouldReceive('__set')->with('mail', 'marie@test.fr')->once();
        $ldapUser->shouldReceive('__set')->with('telephonenumber', '0601020304')->once();
        $ldapUser->shouldReceive('__set')->with('description', 'Prof maths')->once();
        $ldapUser->shouldReceive('save')->once();

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('m.martin')
            ->andReturn($ldapUser);
        $this->userRepository->shouldReceive('invalidateCache')
            ->with('m.martin');

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $result = $this->service->updatePersonalInfo('m.martin', [
            'prenom' => 'Marie',
            'nom' => 'Martin',
            'email' => 'marie@test.fr',
            'phone' => '0601020304',
            'description' => 'Prof maths',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('Informations mises à jour.', $result['message']);
    }

    /** @test */
    public function updatePersonalInfo_returns_error_when_user_not_found(): void
    {
        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('unknown')
            ->andReturn(null);

        $result = $this->service->updatePersonalInfo('unknown', [
            'prenom' => 'Jean',
            'nom' => 'Dupont',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('introuvable', $result['message']);
    }

    /** @test */
    public function updatePersonalInfo_invalidates_cache_after_save(): void
    {
        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('__set')->withAnyArgs();
        $ldapUser->shouldReceive('save')->once();

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('testuser')
            ->andReturn($ldapUser);
        $this->userRepository->shouldReceive('invalidateCache')
            ->with('testuser')
            ->once();

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $result = $this->service->updatePersonalInfo('testuser', [
            'prenom' => 'Jean',
            'nom' => 'Dupont',
        ]);

        $this->assertTrue($result['success']);
    }

    /** @test */
    public function updatePersonalInfo_handles_ldap_save_exception(): void
    {
        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('__set')->withAnyArgs();
        $ldapUser->shouldReceive('save')->andThrow(new \Exception('LDAP connection failed'));

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('testuser')
            ->andReturn($ldapUser);

        Log::shouldReceive('error')->atLeast()->once();

        $result = $this->service->updatePersonalInfo('testuser', [
            'prenom' => 'Jean',
            'nom' => 'Dupont',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('erreur', strtolower($result['message']));
        // P2: le message ne doit pas exposer les détails internes
        $this->assertStringNotContainsString('LDAP connection failed', $result['message']);
    }

    /** @test */
    public function updatePersonalInfo_logs_action(): void
    {
        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('__set')->withAnyArgs();
        $ldapUser->shouldReceive('save')->once();

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('testuser')
            ->andReturn($ldapUser);
        $this->userRepository->shouldReceive('invalidateCache')
            ->with('testuser');

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($msg, $ctx) {
                return $msg === 'User personal info updated'
                    && $ctx['login'] === 'testuser'
                    && is_array($ctx['fields'])
                    && array_key_exists('sql_synced', $ctx);
            });
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $this->service->updatePersonalInfo('testuser', [
            'prenom' => 'Jean',
            'nom' => 'Dupont',
        ]);
    }
}
