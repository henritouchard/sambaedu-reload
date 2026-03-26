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
use Tests\Traits\MocksAdminUser;

class UserServiceUpdateTest extends TestCase
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
    // Tests updatePersonalInfo() — Permissions (D1)
    // =========================================================================

    /** @test */
    public function updatePersonalInfo_rejects_when_no_permission(): void
    {
        // Sans utilisateur authentifié, la policy refuse

        $result = $this->service->updatePersonalInfo('testuser', [
            'prenom' => 'Jean',
            'nom' => 'Dupont',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('droits', $result['message']);
    }

    // =========================================================================
    // Tests validatePersonalInfo() — Validation (public, découplée)
    // =========================================================================

    /** @test */
    public function validatePersonalInfo_requires_prenom(): void
    {
        $errors = $this->service->validatePersonalInfo(['prenom' => '', 'nom' => 'Dupont']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('prénom est requis', $errors[0]);
    }

    /** @test */
    public function validatePersonalInfo_requires_nom(): void
    {
        $errors = $this->service->validatePersonalInfo(['prenom' => 'Jean', 'nom' => '']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('nom est requis', $errors[0]);
    }

    /** @test */
    public function validatePersonalInfo_rejects_prenom_over_64_chars(): void
    {
        $errors = $this->service->validatePersonalInfo(['prenom' => str_repeat('A', 65), 'nom' => 'Dupont']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('prénom', $errors[0]);
        $this->assertStringContainsString('64', $errors[0]);
    }

    /** @test */
    public function validatePersonalInfo_rejects_nom_over_64_chars(): void
    {
        $errors = $this->service->validatePersonalInfo(['prenom' => 'Jean', 'nom' => str_repeat('A', 65)]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('nom', $errors[0]);
        $this->assertStringContainsString('64', $errors[0]);
    }

    /** @test */
    public function validatePersonalInfo_rejects_whitespace_only_prenom(): void
    {
        $errors = $this->service->validatePersonalInfo(['prenom' => '   ', 'nom' => 'Dupont']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('prénom est requis', $errors[0]);
    }

    /** @test */
    public function validatePersonalInfo_rejects_invalid_email(): void
    {
        $errors = $this->service->validatePersonalInfo(['prenom' => 'Jean', 'nom' => 'Dupont', 'email' => 'not-an-email']);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('email', $errors[0]);
    }

    /** @test */
    public function validatePersonalInfo_accepts_empty_email(): void
    {
        $errors = $this->service->validatePersonalInfo(['prenom' => 'Jean', 'nom' => 'Dupont', 'email' => '']);
        $this->assertEmpty($errors);
    }

    /** @test */
    public function validatePersonalInfo_rejects_phone_over_20_chars(): void
    {
        $errors = $this->service->validatePersonalInfo(['prenom' => 'Jean', 'nom' => 'Dupont', 'phone' => str_repeat('1', 21)]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('téléphone', $errors[0]);
    }

    /** @test */
    public function validatePersonalInfo_rejects_description_over_1000_chars(): void
    {
        $errors = $this->service->validatePersonalInfo(['prenom' => 'Jean', 'nom' => 'Dupont', 'description' => str_repeat('a', 1001)]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('description', $errors[0]);
    }

    // =========================================================================
    // Tests updatePersonalInfo() — LDAP attributes mapping
    // =========================================================================

    /** @test */
    public function updatePersonalInfo_sets_ldap_attributes_correctly(): void
    {
        $this->actAsAdmin();

        $setAttributes = [];
        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('setAttribute')
            ->withAnyArgs()
            ->andReturnUsing(function (string $key, $value) use (&$setAttributes, $ldapUser) {
                $setAttributes[$key] = $value;
                return $ldapUser;
            });
        $ldapUser->shouldReceive('save')->once();

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('m.martin')
            ->andReturn($ldapUser);
        $this->userRepository->shouldReceive('invalidateCache')
            ->with('m.martin');

        $result = $this->service->updatePersonalInfo('m.martin', [
            'prenom' => 'Marie',
            'nom' => 'Martin',
            'email' => 'marie@test.fr',
            'phone' => '0601020304',
            'description' => 'Prof maths',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('Informations mises à jour.', $result['message']);

        // Vérifie le mapping LDAP : __set → setAttribute sur LdapRecord
        $this->assertEquals('Marie', $setAttributes['givenname']);
        $this->assertEquals('Martin', $setAttributes['sn']);
        $this->assertEquals('Marie Martin', $setAttributes['displayname']);
        $this->assertEquals('marie@test.fr', $setAttributes['mail']);
        $this->assertEquals('0601020304', $setAttributes['telephonenumber']);
        $this->assertEquals('Prof maths', $setAttributes['description']);
    }

    /** @test */
    public function updatePersonalInfo_returns_error_when_user_not_found(): void
    {
        $this->actAsAdmin();

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
        $this->actAsAdmin();

        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('setAttribute')->withAnyArgs()->andReturnSelf();
        $ldapUser->shouldReceive('__set')->withAnyArgs();
        $ldapUser->shouldReceive('save')->once();

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('testuser')
            ->andReturn($ldapUser);
        $this->userRepository->shouldReceive('invalidateCache')
            ->with('testuser')
            ->once();

        $result = $this->service->updatePersonalInfo('testuser', [
            'prenom' => 'Jean',
            'nom' => 'Dupont',
        ]);

        $this->assertTrue($result['success']);
    }

    /** @test */
    public function updatePersonalInfo_handles_ldap_save_exception(): void
    {
        $this->actAsAdmin();

        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('setAttribute')->withAnyArgs()->andReturnSelf();
        $ldapUser->shouldReceive('__set')->withAnyArgs();
        $ldapUser->shouldReceive('save')->andThrow(new \Exception('LDAP connection failed'));

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('testuser')
            ->andReturn($ldapUser);

        $result = $this->service->updatePersonalInfo('testuser', [
            'prenom' => 'Jean',
            'nom' => 'Dupont',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('erreur', strtolower($result['message']));
        $this->assertStringNotContainsString('LDAP connection failed', $result['message']);
    }

    /** @test */
    public function updatePersonalInfo_logs_action_with_sql_synced(): void
    {
        $this->actAsAdmin();

        Log::spy();

        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('setAttribute')->withAnyArgs()->andReturnSelf();
        $ldapUser->shouldReceive('save')->once();

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('testuser')
            ->andReturn($ldapUser);
        $this->userRepository->shouldReceive('invalidateCache')
            ->with('testuser');

        $result = $this->service->updatePersonalInfo('testuser', [
            'prenom' => 'Jean',
            'nom' => 'Dupont',
        ]);

        $this->assertTrue($result['success']);

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($msg, $ctx) =>
                str_contains($msg, 'updated')
                && $ctx['login'] === 'testuser'
                && array_key_exists('sql_synced', $ctx)
            )
            ->once();
    }
}
