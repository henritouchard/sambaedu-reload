<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Config\SambaEduConfig;
use App\LdapModels\LdapUser;
use App\Models\User as SqlUserModel;
use App\Repositories\ClassRepository;
use App\Repositories\EstablishmentRepository;
use App\Repositories\FunctionRepository;
use App\Repositories\OrganizationalUnitRepository;
use App\Repositories\UserRepository;
use App\Services\PasswordService;
use App\Services\UserService;
use App\Types\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;
use Tests\Traits\MocksAdminUser;
use PHPUnit\Framework\Attributes\Test;

class UserServiceDisableDeleteTest extends TestCase
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
    // Tests disableUser() — Permissions
    // =========================================================================

    #[Test]
    public function disableUser_rejects_when_no_permission(): void
    {
        $result = $this->service->disableUser('testuser');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('droits', $result['message']);
    }

    // =========================================================================
    // Tests disableUser() — Comptes système (D-3)
    // =========================================================================

    #[Test]
    public function disableUser_rejects_system_account(): void
    {
        $this->actAsAdmin();

        $result = $this->service->disableUser('Administrator');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('système', $result['message']);
    }

    #[Test]
    public function disableUser_rejects_system_account_by_pattern(): void
    {
        $this->actAsAdmin();

        $result = $this->service->disableUser('admin');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('système', $result['message']);
    }

    // =========================================================================
    // Tests disableUser() — Fonctionnel
    // =========================================================================

    #[Test]
    public function disableUser_sets_uac_to_514_and_returns_success(): void
    {
        $this->actAsAdmin();
        Log::spy();

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
            ->with('dupont')
            ->andReturn($ldapUser);
        $this->userRepository->shouldReceive('invalidateCache')
            ->with('dupont')
            ->once();

        $result = $this->service->disableUser('dupont');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('désactivé', $result['message']);
        $this->assertEquals(User::UAC_DISABLED, $setAttributes['useraccountcontrol']);
    }

    #[Test]
    public function disableUser_returns_error_when_user_not_found(): void
    {
        $this->actAsAdmin();

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('unknown')
            ->andReturn(null);

        $result = $this->service->disableUser('unknown');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('introuvable', $result['message']);
    }

    #[Test]
    public function disableUser_logs_action_with_archive_status(): void
    {
        $this->actAsAdmin();
        Log::spy();

        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('setAttribute')->withAnyArgs()->andReturnSelf();
        $ldapUser->shouldReceive('save')->once();

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('dupont')
            ->andReturn($ldapUser);
        $this->userRepository->shouldReceive('invalidateCache')
            ->with('dupont');

        $this->service->disableUser('dupont');

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($msg, $ctx) =>
                str_contains($msg, 'disabled')
                && $ctx['login'] === 'dupont'
                && array_key_exists('home_archived', $ctx)
            )
            ->once();
        $this->addToAssertionCount(1);
    }

    // =========================================================================
    // Tests enableUser() — Permissions
    // =========================================================================

    #[Test]
    public function enableUser_rejects_when_no_permission(): void
    {
        $result = $this->service->enableUser('dupont');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('droits', $result['message']);
    }

    // =========================================================================
    // Tests enableUser() — Comptes système (D-3)
    // =========================================================================

    #[Test]
    public function enableUser_rejects_system_account(): void
    {
        $this->actAsAdmin();

        $result = $this->service->enableUser('Administrator');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('système', $result['message']);
    }

    // =========================================================================
    // Tests enableUser() — Fonctionnel
    // =========================================================================

    #[Test]
    public function enableUser_sets_uac_to_512_and_returns_success(): void
    {
        $this->actAsAdmin();
        Log::spy();

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
            ->with('dupont')
            ->andReturn($ldapUser);
        $this->userRepository->shouldReceive('invalidateCache')
            ->with('dupont')
            ->once();

        $result = $this->service->enableUser('dupont');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('réactivé', $result['message']);
        $this->assertEquals(User::UAC_ACTIVE, $setAttributes['useraccountcontrol']);
    }

    #[Test]
    public function enableUser_returns_error_when_user_not_found(): void
    {
        $this->actAsAdmin();

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('unknown')
            ->andReturn(null);

        $result = $this->service->enableUser('unknown');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('introuvable', $result['message']);
    }

    #[Test]
    public function enableUser_logs_action(): void
    {
        $this->actAsAdmin();
        Log::spy();

        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('setAttribute')->withAnyArgs()->andReturnSelf();
        $ldapUser->shouldReceive('save')->once();

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('dupont')
            ->andReturn($ldapUser);
        $this->userRepository->shouldReceive('invalidateCache')
            ->with('dupont');

        $this->service->enableUser('dupont');

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($msg, $ctx) =>
                str_contains($msg, 'enabled')
                && $ctx['login'] === 'dupont'
            )
            ->once();
        $this->addToAssertionCount(1);
    }

    // =========================================================================
    // Tests deleteUserPermanently() — Permissions
    // =========================================================================

    #[Test]
    public function deleteUserPermanently_rejects_when_no_permission(): void
    {
        $result = $this->service->deleteUserPermanently('dupont');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('droits', $result['message']);
    }

    // =========================================================================
    // Tests deleteUserPermanently() — Comptes système (D-3)
    // =========================================================================

    #[Test]
    public function deleteUserPermanently_rejects_system_account(): void
    {
        $this->actAsAdmin();

        $result = $this->service->deleteUserPermanently('Administrator');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('système', $result['message']);
    }

    // =========================================================================
    // Tests deleteUserPermanently() — Suppression en deux temps
    // =========================================================================

    #[Test]
    public function deleteUserPermanently_rejects_active_account(): void
    {
        $this->actAsAdmin();

        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('getFirstAttribute')
            ->with('useraccountcontrol')
            ->andReturn(User::UAC_ACTIVE);

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('dupont')
            ->andReturn($ldapUser);

        $result = $this->service->deleteUserPermanently('dupont');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('désactiver', $result['message']);
    }

    #[Test]
    public function deleteUserPermanently_rejects_when_ldap_user_not_found(): void
    {
        $this->actAsAdmin();

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('ghost')
            ->andReturn(null);

        $result = $this->service->deleteUserPermanently('ghost');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('introuvable', $result['message']);
    }

    #[Test]
    public function deleteUserPermanently_succeeds_when_account_disabled(): void
    {
        $this->actAsAdmin();
        Log::spy();

        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('getFirstAttribute')
            ->with('useraccountcontrol')
            ->andReturn(User::UAC_DISABLED);
        $ldapUser->shouldReceive('delete')->once()->andReturn(true);

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('dupont')
            ->andReturn($ldapUser);
        $this->userRepository->shouldReceive('invalidateCache')
            ->with('dupont')
            ->once();

        $result = $this->service->deleteUserPermanently('dupont');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('supprimé', $result['message']);
    }

    #[Test]
    public function deleteUserPermanently_fails_when_ad_delete_fails(): void
    {
        $this->actAsAdmin();

        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('getFirstAttribute')
            ->with('useraccountcontrol')
            ->andReturn(User::UAC_DISABLED);
        $ldapUser->shouldReceive('delete')->once()->andThrow(new \RuntimeException('LDAP delete failed'));

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('dupont')
            ->andReturn($ldapUser);

        $result = $this->service->deleteUserPermanently('dupont');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('suppression', $result['message']);
    }

    #[Test]
    public function deleteUserPermanently_logs_with_timestamp(): void
    {
        $this->actAsAdmin();
        Log::spy();

        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('getFirstAttribute')
            ->with('useraccountcontrol')
            ->andReturn(User::UAC_DISABLED);
        $ldapUser->shouldReceive('delete')->once()->andReturn(true);

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('dupont')
            ->andReturn($ldapUser);
        $this->userRepository->shouldReceive('invalidateCache')
            ->with('dupont');

        $this->service->deleteUserPermanently('dupont');

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($msg, $ctx) =>
                str_contains($msg, 'permanently deleted')
                && $ctx['login'] === 'dupont'
                && array_key_exists('timestamp', $ctx)
            )
            ->once();
        $this->addToAssertionCount(1);
    }
}
