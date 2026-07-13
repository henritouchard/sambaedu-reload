<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Pivot\UserGroupUserPivot;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 42.1 — Vocabulaire borné + helpers du pivot d'arête.
 *
 * SQLite ne borne pas les varchar : la garde applicative `assertValidRole` est
 * la SEULE frontière du vocabulaire côté SE5 (AC4). Le défaut au rattachement
 * dérive du rôle GLOBAL (AC5).
 */
class UserGroupUserPivotTest extends TestCase
{
    #[Test]
    public function roles_constant_holds_the_bounded_vocabulary(): void
    {
        $this->assertSame(['member', 'manager', 'owner'], UserGroupUserPivot::ROLES);
        $this->assertSame('member', UserGroupUserPivot::ROLE_MEMBER);
        $this->assertSame('manager', UserGroupUserPivot::ROLE_MANAGER);
        $this->assertSame('owner', UserGroupUserPivot::ROLE_OWNER);
    }

    #[Test]
    public function assert_valid_role_accepts_the_three_roles(): void
    {
        foreach (UserGroupUserPivot::ROLES as $role) {
            UserGroupUserPivot::assertValidRole($role);
        }
        $this->addToAssertionCount(1); // aucune exception levée
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
