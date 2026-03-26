<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * Tests d'intégration pour la modification d'utilisateur (Story 2.2)
 *
 * Vérifie le double-write SQL après update LDAP.
 */
class UserUpdateTest extends TestCase
{
    use DatabaseTransactions;

    private UserService $service;
    private UserRepository $userRepository;
    private OrganizationalUnitRepository $ouRepository;
    private EstablishmentRepository $establishmentRepository;
    private FunctionRepository $functionRepository;
    private ClassRepository $classRepository;
    private PasswordService $passwordService;
    private SambaEduConfig $config;

    private bool $createdTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('login', 255)->unique();
                $table->string('password', 255)->nullable();
                $table->string('fullname', 255)->nullable();
                $table->string('firstname', 255)->nullable();
                $table->string('lastname', 255)->nullable();
                $table->string('email', 255)->nullable();
                $table->string('phone', 20)->nullable();
                $table->text('description')->nullable();
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

        Gate::define('update-user', fn () => true);
    }

    protected function tearDown(): void
    {
        if ($this->createdTable) {
            Schema::dropIfExists('users');
        }
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // E2E: updatePersonalInfo — double-write SQL
    // =========================================================================

    /** @test */
    public function updatePersonalInfo_persists_to_sql_after_ldap(): void
    {
        SqlUserModel::create([
            'login' => 'j.dupont',
            'firstname' => 'Jean',
            'lastname' => 'Dupont',
            'fullname' => 'Jean Dupont',
            'email' => 'jean@test.fr',
            'role' => 'eleve',
            'is_active' => true,
        ]);

        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('__set')->withAnyArgs();
        $ldapUser->shouldReceive('save')->once();

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('j.dupont')
            ->andReturn($ldapUser);
        $this->userRepository->shouldReceive('invalidateCache')
            ->with('j.dupont');

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $result = $this->service->updatePersonalInfo('j.dupont', [
            'prenom' => 'Marie',
            'nom' => 'Martin',
            'email' => 'marie@test.fr',
            'phone' => '0601020304',
            'description' => 'Changement de nom',
        ]);

        $this->assertTrue($result['success']);

        $sqlUser = SqlUserModel::where('login', 'j.dupont')->first();
        $this->assertNotNull($sqlUser);
        $this->assertEquals('Marie', $sqlUser->firstname);
        $this->assertEquals('Martin', $sqlUser->lastname);
        $this->assertEquals('Marie Martin', $sqlUser->fullname);
        $this->assertEquals('marie@test.fr', $sqlUser->email);
        $this->assertEquals('0601020304', $sqlUser->phone);
        $this->assertEquals('Changement de nom', $sqlUser->description);
    }

    /** @test */
    public function updatePersonalInfo_creates_sql_user_if_not_exists(): void
    {
        $ldapUser = Mockery::mock(LdapUser::class);
        $ldapUser->shouldReceive('__set')->withAnyArgs();
        $ldapUser->shouldReceive('save')->once();

        $this->userRepository->shouldReceive('findLdapModelByLogin')
            ->with('newuser')
            ->andReturn($ldapUser);
        $this->userRepository->shouldReceive('invalidateCache')
            ->with('newuser');

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $result = $this->service->updatePersonalInfo('newuser', [
            'prenom' => 'Alice',
            'nom' => 'Wonderland',
            'email' => 'alice@test.fr',
        ]);

        $this->assertTrue($result['success']);

        $sqlUser = SqlUserModel::where('login', 'newuser')->first();
        $this->assertNotNull($sqlUser);
        $this->assertEquals('Alice', $sqlUser->firstname);
        $this->assertEquals('Wonderland', $sqlUser->lastname);
    }

    // =========================================================================
    // E2E: Validation — messages d'erreur
    // =========================================================================

    /** @test */
    public function updatePersonalInfo_validation_errors_are_user_friendly(): void
    {
        $result = $this->service->updatePersonalInfo('testuser', [
            'prenom' => '',
            'nom' => '',
            'email' => 'bad-email',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('prénom', $result['message']);
        $this->assertStringContainsString('nom', $result['message']);
        $this->assertStringContainsString('email', $result['message']);
    }
}
