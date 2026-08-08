<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\GroupRole;
use App\Models\Pivot\UserGroupUserPivot;
use App\Support\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 42.1 → 62.1 — vocabulaire + helpers du pivot d'arête.
 *
 * SQLite ne borne pas les varchar : la garde applicative `assertValidRole` est la
 * SEULE frontière du vocabulaire côté SE5 (AC4 de 42.1). Ce qui a changé en 62.1,
 * c'est la SOURCE : le vocabulaire n'est plus une constante fermée, c'est le
 * catalogue `group_roles` — mais il contient TOUJOURS au moins les trois clés
 * historiques, quel que soit l'état de la base.
 *
 * Le défaut au rattachement, lui, ne bouge pas : il dérive du rôle GLOBAL (AC5 de
 * 42.1) et écrit des littéraux.
 */
class UserGroupUserPivotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\GroupRoleSeeder::class);
    }

    #[Test]
    public function the_vocabulary_is_read_from_the_catalog(): void
    {
        $this->assertSame(['member', 'manager', 'owner'], UserGroupUserPivot::roles());
        $this->assertSame('member', UserGroupUserPivot::ROLE_MEMBER);
        $this->assertSame('manager', UserGroupUserPivot::ROLE_MANAGER);
        $this->assertSame('owner', UserGroupUserPivot::ROLE_OWNER);
    }

    /**
     * Le point de bascule de la story : le vocabulaire n'est plus fermé.
     */
    #[Test]
    public function a_role_added_to_the_catalog_becomes_valid_on_an_edge(): void
    {
        GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);

        $this->assertContains('tuteur', UserGroupUserPivot::roles());
        UserGroupUserPivot::assertValidRole('tuteur');
        $this->addToAssertionCount(1); // aucune exception levée
    }

    #[Test]
    public function assert_valid_role_accepts_every_catalogued_role(): void
    {
        foreach (UserGroupUserPivot::roles() as $role) {
            UserGroupUserPivot::assertValidRole($role);
        }
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function assert_valid_role_rejects_an_arbitrary_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UserGroupUserPivot::assertValidRole('superadmin');
    }

    #[Test]
    public function assert_valid_role_is_case_sensitive(): void
    {
        // Le vocabulaire est en minuscules stricte (aligné SQL/AD).
        $this->expectException(InvalidArgumentException::class);
        UserGroupUserPivot::assertValidRole('Owner');
    }

    /**
     * LE PLANCHER : une base vidée de son catalogue ne fait pas disparaître le
     * vocabulaire minimal. `defaultRoleForGlobalRole()` écrit `member`/`manager`
     * en littéral — si la garde les refusait, tout rattachement casserait.
     */
    #[Test]
    public function the_three_historical_keys_survive_an_empty_catalog(): void
    {
        GroupRole::query()->delete();
        RoleCatalog::flush();

        $this->assertSame(['member', 'manager', 'owner'], UserGroupUserPivot::roles());

        foreach (['member', 'manager', 'owner'] as $role) {
            UserGroupUserPivot::assertValidRole($role);
        }
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function default_role_maps_prof_to_manager_and_everything_else_to_member(): void
    {
        $this->assertSame('manager', UserGroupUserPivot::defaultRoleForGlobalRole('prof'));
        $this->assertSame('member', UserGroupUserPivot::defaultRoleForGlobalRole('eleve'));
        $this->assertSame('member', UserGroupUserPivot::defaultRoleForGlobalRole('admin'));
        $this->assertSame('member', UserGroupUserPivot::defaultRoleForGlobalRole('autre'));
        $this->assertSame('member', UserGroupUserPivot::defaultRoleForGlobalRole(null));
    }

    #[Test]
    public function default_role_never_returns_owner(): void
    {
        // owner est une désignation explicite (PP), jamais un défaut.
        foreach (['prof', 'eleve', 'admin', 'autre', null, ''] as $globalRole) {
            $this->assertNotSame('owner', UserGroupUserPivot::defaultRoleForGlobalRole($globalRole));
        }
    }
}
