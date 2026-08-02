<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Constants\Ldap\MainGroups;
use App\LdapModels\LdapUser;
use App\Models\User as UserModel;
use App\Services\UserSyncService;
use App\Types\User as AdUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 49.3 (AC1 / AC6 / AC10-9) — `users.is_active` est un MIROIR de l'AD.
 *
 * Avant cette story, `upsertUser` posait `true` en dur à la CRÉATION et ne
 * touchait pas la colonne à l'UPDATE. Conséquences, toutes deux vérifiées ici
 * en non-régression :
 *   - un compte revenu dans l'annuaire restait inactif en base à vie ;
 *   - et l'« évidence » qui consistait à poser `true` à l'update aurait
 *     ressuscité toutes les 5 minutes les comptes désactivés à la main (le
 *     compte AD désactivé reste membre de ses groupes, donc présent au
 *     balayage) — d'où le miroir de `useraccountcontrol`, et rien d'autre.
 *
 * Pattern de test : réflexion sur `ldapUserToAdData` / `upsertUser`, comme
 * `UserSyncServicePasswordChangedAtTest` (le mocking de la suite est
 * service-level, il n'y a pas de `DirectoryEmulator`).
 */
class UserSyncServiceIsActiveMirrorTest extends TestCase
{
    use DatabaseTransactions;

    private UserSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new UserSyncService();
        $this->createUsersTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    // =========================================================================
    // ldapUserToAdData — transmission de useraccountcontrol au DTO
    // =========================================================================

    #[Test]
    public function ldap_user_to_ad_data_transmits_the_active_account_flag(): void
    {
        foreach ([true, false] as $isActive) {
            $ldapUser = $this->mockLdapUser('dto-' . var_export($isActive, true), $isActive);

            /** @var AdUser $adUser */
            $adUser = $this->invokePrivate($this->service, 'ldapUserToAdData', [$ldapUser, MainGroups::ELEVES]);

            self::assertSame(
                $isActive,
                $adUser->isActive,
                'Le DTO doit porter l\'état RÉEL du compte AD (useraccountcontrol).'
            );
        }
    }

    // =========================================================================
    // upsertUser — branche CRÉATION
    // =========================================================================

    #[Test]
    public function creation_writes_is_active_from_the_dto(): void
    {
        $this->upsert(new AdUser(login: 'nouveau-actif', fullname: 'Actif', isActive: true, role: 'eleve'));
        $this->upsert(new AdUser(login: 'nouveau-inactif', fullname: 'Inactif', isActive: false, role: 'eleve'));

        self::assertTrue((bool) UserModel::query()->where('login', 'nouveau-actif')->firstOrFail()->is_active);
        self::assertFalse((bool) UserModel::query()->where('login', 'nouveau-inactif')->firstOrFail()->is_active);
    }

    // =========================================================================
    // upsertUser — branche UPDATE (le trou de FR-R4)
    // =========================================================================

    #[Test]
    public function update_mirrors_a_disabled_ad_account(): void
    {
        $this->existingUser('bascule-off', isActive: true);

        $reactivated = $this->upsert(new AdUser(login: 'bascule-off', fullname: 'X', isActive: false, role: 'eleve'));

        self::assertFalse((bool) UserModel::query()->where('login', 'bascule-off')->firstOrFail()->is_active);
        self::assertFalse($reactivated);
    }

    #[Test]
    public function update_never_resurrects_a_manually_disabled_account(): void
    {
        // `UserService::disableUser()` fait un double-write : AD `uac=514` +
        // SQL `is_active=false`. Le compte reste membre de ses groupes AD, donc
        // PRÉSENT au balayage : la sync le revoit toutes les 5 minutes.
        $this->existingUser('desactive-main', isActive: false);

        for ($tick = 0; $tick < 3; $tick++) {
            $reactivated = $this->upsert(
                new AdUser(login: 'desactive-main', fullname: 'X', isActive: false, role: 'eleve')
            );

            self::assertFalse($reactivated);
            self::assertFalse(
                (bool) UserModel::query()->where('login', 'desactive-main')->firstOrFail()->is_active,
                'Le geste admin ne doit pas être annulé par la sync.'
            );
        }
    }

    #[Test]
    public function update_reactivates_a_returning_account_and_flags_it(): void
    {
        $this->existingUser('revenant', isActive: false);

        $reactivated = $this->upsert(new AdUser(login: 'revenant', fullname: 'X', isActive: true, role: 'prof'));

        self::assertTrue($reactivated, 'La transition false → true est comptée comme une réactivation.');
        $row = UserModel::query()->where('login', 'revenant')->firstOrFail();
        self::assertTrue((bool) $row->is_active);
        self::assertSame('prof', $row->role, 'Le rôle est re-posé depuis le groupe principal.');
    }

    #[Test]
    public function an_already_active_account_is_not_counted_as_reactivated(): void
    {
        $this->existingUser('stable', isActive: true);

        $reactivated = $this->upsert(new AdUser(login: 'stable', fullname: 'X', isActive: true, role: 'eleve'));

        self::assertFalse($reactivated);
        self::assertTrue((bool) UserModel::query()->where('login', 'stable')->firstOrFail()->is_active);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function upsert(AdUser $adUser): bool
    {
        $reactivated = false;

        $reflection = new \ReflectionMethod($this->service, 'upsertUser');
        $reflection->setAccessible(true);
        $reflection->invokeArgs($this->service, [$adUser, &$reactivated]);

        return $reactivated;
    }

    private function existingUser(string $login, bool $isActive): UserModel
    {
        return UserModel::query()->create([
            'login' => $login,
            'fullname' => $login,
            'role' => 'eleve',
            'is_active' => $isActive,
        ]);
    }

    private function mockLdapUser(string $login, bool $isActive): LdapUser
    {
        $ldapUser = $this->getMockBuilder(LdapUser::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getLogin', 'getDn', 'getAttribute', 'getFirstAttribute', 'toBusinessObject'])
            ->getMock();

        $ldapUser->method('getLogin')->willReturn($login);
        $ldapUser->method('getDn')->willReturn("CN={$login},DC=test,DC=local");
        $ldapUser->method('getAttribute')->willReturnCallback(
            static fn(string $attribute) => $attribute === 'memberof' ? [] : null
        );
        $ldapUser->method('getFirstAttribute')->willReturn(null);
        $ldapUser->method('toBusinessObject')->willReturn(new AdUser(
            login: $login,
            fullname: $login,
            isActive: $isActive,
        ));

        return $ldapUser;
    }

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
            $table->string('source', 16)->default('ad');
            $table->string('school_code')->nullable();
            $table->string('school_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('ad_right_profiles')->nullable();
            $table->unsignedInteger('ad_rights_bitmask')->default(0);
            $table->timestamp('ad_synced_at')->nullable();
            $table->timestamp('pwd_reset_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->json('quota_snapshot')->nullable();
            $table->timestamps();
        });
    }
}
