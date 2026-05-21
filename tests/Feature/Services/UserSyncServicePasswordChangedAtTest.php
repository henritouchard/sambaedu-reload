<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Constants\Ldap\MainGroups;
use App\LdapModels\LdapUser;
use App\Models\User as UserModel;
use App\Services\UserSyncService;
use App\Types\User as AdUser;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Feature — synchro password_changed_at dans UserSyncService.
 *
 * Couvre AC14 (Tâche 4.4) :
 *   - ldapUserToAdData lit pwdlastset et le convertit en ?Carbon dans le DTO
 *   - upsertUser écrit password_changed_at pour les cas FILETIME : 0, valide, -1
 *
 * Story 14.4 — AC3 / AC14
 */
class UserSyncServicePasswordChangedAtTest extends TestCase
{
    use DatabaseTransactions;

    private UserSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new UserSyncService();
        $this->createUsersTable();
        $this->createSyncCursorsTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sync_cursors');
        parent::tearDown();
    }

    // =========================================================================
    // ldapUserToAdData — lecture pwdLastSet dans le DTO
    // =========================================================================

    #[Test]
    public function ldap_user_to_ad_data_sets_password_changed_at_null_when_pwdlastset_zero(): void
    {
        $ldapUser = $this->mockLdapUser('testuser-zero', 'CN=testuser-zero,DC=test,DC=local', [], [
            'pwdlastset' => '0',
        ]);

        /** @var AdUser $adUser */
        $adUser = $this->invokePrivate($this->service, 'ldapUserToAdData', [$ldapUser, MainGroups::ELEVES]);

        $this->assertNull($adUser->passwordChangedAt, 'pwdlastset=0 doit donner passwordChangedAt NULL');
    }

    #[Test]
    public function ldap_user_to_ad_data_sets_password_changed_at_carbon_when_pwdlastset_valid(): void
    {
        // 133000000000000000 → 2022-06-18
        $ldapUser = $this->mockLdapUser('testuser-filetime', 'CN=testuser-filetime,DC=test,DC=local', [], [
            'pwdlastset' => '133000000000000000',
        ]);

        /** @var AdUser $adUser */
        $adUser = $this->invokePrivate($this->service, 'ldapUserToAdData', [$ldapUser, MainGroups::ELEVES]);

        $this->assertNotNull($adUser->passwordChangedAt);
        $this->assertInstanceOf(Carbon::class, $adUser->passwordChangedAt);
        $this->assertSame('2022-06-18', $adUser->passwordChangedAt->format('Y-m-d'));
    }

    #[Test]
    public function ldap_user_to_ad_data_sets_password_changed_at_approx_now_when_minus_one(): void
    {
        $before = Carbon::now()->subSecond();

        $ldapUser = $this->mockLdapUser('testuser-minus-one', 'CN=testuser-minus-one,DC=test,DC=local', [], [
            'pwdlastset' => '-1',
        ]);

        /** @var AdUser $adUser */
        $adUser = $this->invokePrivate($this->service, 'ldapUserToAdData', [$ldapUser, MainGroups::ELEVES]);

        $after = Carbon::now()->addSecond();

        $this->assertNotNull($adUser->passwordChangedAt);
        $this->assertTrue($adUser->passwordChangedAt->gte($before));
        $this->assertTrue($adUser->passwordChangedAt->lte($after));
    }

    #[Test]
    public function ldap_user_to_ad_data_sets_password_changed_at_null_when_pwdlastset_absent(): void
    {
        $ldapUser = $this->mockLdapUser('testuser-absent', 'CN=testuser-absent,DC=test,DC=local', [], [
            // Pas de pwdlastset dans les attributs
        ]);

        /** @var AdUser $adUser */
        $adUser = $this->invokePrivate($this->service, 'ldapUserToAdData', [$ldapUser, MainGroups::ELEVES]);

        $this->assertNull($adUser->passwordChangedAt, 'pwdlastset absent doit donner passwordChangedAt NULL');
    }

    /**
     * Post-review #1 / #5 — Cas Carbon (LdapRecord auto-cast) :
     * pwdlastset retourné comme Carbon → mapping vers -1 → now() best-effort.
     * Vérifie le pipeline complet ldapUserToAdData → DTO Carbon non NULL.
     */
    #[Test]
    public function ldap_user_to_ad_data_handles_carbon_pwdlastset_as_now(): void
    {
        $before = Carbon::now()->subSeconds(5);

        $ldapUser = $this->mockLdapUser('testuser-carbon', 'CN=testuser-carbon,DC=test,DC=local', [], [
            'pwdlastset' => Carbon::parse('2022-06-18'),
        ]);

        /** @var AdUser $adUser */
        $adUser = $this->invokePrivate($this->service, 'ldapUserToAdData', [$ldapUser, MainGroups::ELEVES]);

        $after = Carbon::now()->addSeconds(5);

        $this->assertNotNull(
            $adUser->passwordChangedAt,
            'Carbon doit produire un passwordChangedAt non-NULL (bug review #1 corrigé)'
        );
        $this->assertTrue($adUser->passwordChangedAt->gte($before));
        $this->assertTrue($adUser->passwordChangedAt->lte($after));
    }

    // =========================================================================
    // upsertUser — écriture password_changed_at en BDD
    // =========================================================================

    #[Test]
    public function upsert_user_writes_password_changed_at_null_when_zero(): void
    {
        $adUser = new AdUser(
            login: 'upsert-zero',
            fullname: 'Test Zero',
            role: 'eleve',
            passwordChangedAt: null,
        );

        $this->invokePrivate($this->service, 'upsertUser', [$adUser]);

        $row = UserModel::query()->where('login', 'upsert-zero')->firstOrFail();
        $this->assertNull($row->password_changed_at);
    }

    #[Test]
    public function upsert_user_writes_password_changed_at_carbon_when_valid(): void
    {
        $expectedCarbon = Carbon::parse('2022-06-18 08:00:00', 'UTC');

        $adUser = new AdUser(
            login: 'upsert-filetime',
            fullname: 'Test Filetime',
            role: 'eleve',
            passwordChangedAt: $expectedCarbon,
        );

        $this->invokePrivate($this->service, 'upsertUser', [$adUser]);

        $row = UserModel::query()->where('login', 'upsert-filetime')->firstOrFail();
        $this->assertNotNull($row->password_changed_at);
        $this->assertSame('2022-06-18', $row->password_changed_at->format('Y-m-d'));
    }

    #[Test]
    public function upsert_user_updates_existing_password_changed_at(): void
    {
        // Créer le user avec une ancienne date
        UserModel::query()->create([
            'login' => 'upsert-update',
            'fullname' => 'Old Name',
            'role' => 'eleve',
            'is_active' => true,
            'password_changed_at' => Carbon::parse('2020-01-01'),
        ]);

        $newCarbon = Carbon::parse('2022-06-18 08:00:00', 'UTC');

        $adUser = new AdUser(
            login: 'upsert-update',
            fullname: 'Updated Name',
            role: 'eleve',
            passwordChangedAt: $newCarbon,
        );

        $this->invokePrivate($this->service, 'upsertUser', [$adUser]);

        $row = UserModel::query()->where('login', 'upsert-update')->firstOrFail();
        $this->assertSame('2022-06-18', $row->password_changed_at->format('Y-m-d'));
    }

    /**
     * Post-review #2 — Bug critique : upsertUser ne doit PAS écraser la date SQL
     * existante avec NULL si l'AD répond null (ex: pwdLastSet absent/filtré).
     *
     * Scénario reproduit : user s'est loggé hier (date SQL persistée par
     * AuthenticationService::persistPasswordChangedAt), puis sync AD batch
     * arrive avec passwordChangedAt=null → l'ancienne date doit être préservée.
     */
    #[Test]
    public function upsert_user_keeps_existing_password_changed_at_when_ad_returns_null(): void
    {
        $existingDate = Carbon::parse('2026-05-20 10:00:00', 'UTC');

        UserModel::query()->create([
            'login' => 'upsert-preserve',
            'fullname' => 'Login Persisted',
            'role' => 'eleve',
            'is_active' => true,
            'password_changed_at' => $existingDate,
        ]);

        // AD répond null (pwdLastSet absent, ACL, replica filtré, etc.)
        $adUser = new AdUser(
            login: 'upsert-preserve',
            fullname: 'Login Persisted',
            role: 'eleve',
            passwordChangedAt: null,
        );

        $this->invokePrivate($this->service, 'upsertUser', [$adUser]);

        $row = UserModel::query()->where('login', 'upsert-preserve')->firstOrFail();
        $this->assertNotNull(
            $row->password_changed_at,
            'La date SQL existante ne doit PAS être écrasée par un null transitoire de sync AD (review #2)'
        );
        $this->assertSame(
            $existingDate->format('Y-m-d H:i:s'),
            $row->password_changed_at->format('Y-m-d H:i:s')
        );
    }

    /**
     * Post-review #2 — corollaire : si l'AD répond une vraie valeur, elle
     * écrase bien la date existante (sync = source de vérité D6 quand AD répond).
     */
    #[Test]
    public function upsert_user_overwrites_password_changed_at_when_ad_returns_value(): void
    {
        $oldDate = Carbon::parse('2020-01-01', 'UTC');
        $newDate = Carbon::parse('2026-05-21 12:00:00', 'UTC');

        UserModel::query()->create([
            'login' => 'upsert-overwrite',
            'fullname' => 'Old Name',
            'role' => 'eleve',
            'is_active' => true,
            'password_changed_at' => $oldDate,
        ]);

        $adUser = new AdUser(
            login: 'upsert-overwrite',
            fullname: 'New Name',
            role: 'eleve',
            passwordChangedAt: $newDate,
        );

        $this->invokePrivate($this->service, 'upsertUser', [$adUser]);

        $row = UserModel::query()->where('login', 'upsert-overwrite')->firstOrFail();
        $this->assertSame(
            $newDate->format('Y-m-d H:i:s'),
            $row->password_changed_at->format('Y-m-d H:i:s'),
            'La valeur AD doit écraser la date SQL quand elle est non-null (D6)'
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param array<int,string> $memberOf
     * @param array<string,string|null> $attributes
     */
    private function mockLdapUser(string $login, string $dn, array $memberOf, array $attributes = []): LdapUser
    {
        $ldapUser = $this->getMockBuilder(LdapUser::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getLogin', 'getDn', 'getAttribute', 'getFirstAttribute', 'toBusinessObject'])
            ->getMock();

        $ldapUser->method('getLogin')->willReturn($login);
        $ldapUser->method('getDn')->willReturn($dn);
        $ldapUser->method('getAttribute')->willReturnCallback(
            static fn(string $attribute) => $attribute === 'memberof' ? $memberOf : ($attributes[$attribute] ?? null)
        );
        $ldapUser->method('getFirstAttribute')->willReturnCallback(
            static fn(string $attribute) => $attributes[$attribute] ?? null
        );

        // Stub toBusinessObject() → objet minimal avec etabCode/etabName
        $ldapUser->method('toBusinessObject')->willReturn(new class {
            public ?string $etabCode = null;
            public ?string $etabName = null;
        });

        return $ldapUser;
    }

    /**
     * @param array<int,mixed> $arguments
     */
    private function invokePrivate(object $instance, string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($instance, $arguments);
    }

    private function createUsersTable(): void
    {
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('login')->unique();
            $table->string('password')->nullable();
            $table->string('fullname')->nullable();
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('email')->nullable();
            $table->text('dn')->nullable();
            $table->string('ad_guid')->nullable();
            $table->string('role')->default('autre');
            $table->string('school_code')->nullable();
            $table->string('school_name')->nullable();
            $table->string('phone')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('ad_right_profiles')->nullable();
            $table->unsignedInteger('ad_rights_bitmask')->default(0);
            $table->timestamp('ad_synced_at')->nullable();
            $table->timestamp('pwd_reset_at')->nullable();
            // Story 14.4 — AC1
            $table->timestamp('password_changed_at')->nullable();
            $table->json('quota_snapshot')->nullable();
            $table->timestamps();
        });
    }

    private function createSyncCursorsTable(): void
    {
        Schema::dropIfExists('sync_cursors');

        Schema::create('sync_cursors', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('cursor_value');
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
