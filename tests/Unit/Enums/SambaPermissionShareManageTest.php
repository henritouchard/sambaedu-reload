<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\LegacyRight;
use App\Enums\SambaPermission;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 5.2 — verrouille le mapping de la nouvelle permission `share.manage`
 * vers le bit legacy `SE_SHARE_REFRESH` (D2=A) + son label + son exclusion
 * du bitmask import (secondary bit, partagée avec `share.refresh`).
 *
 * Garde aussi la non-régression sur `share.view` et `share.refresh`.
 */
class SambaPermissionShareManageTest extends TestCase
{
    #[Test]
    public function it_maps_share_manage_to_legacy_share_refresh(): void
    {
        // D2=A : ShareManage partage le bit `SE_SHARE_REFRESH` avec ShareRefresh.
        $this->assertSame(
            LegacyRight::ShareRefresh,
            SambaPermission::ShareManage->legacyRight(),
            'share.manage doit pointer sur LegacyRight::ShareRefresh (D2=A)'
        );

        // Garde non-régression : ShareView/ShareRefresh inchangés.
        $this->assertSame(LegacyRight::ShareView, SambaPermission::ShareView->legacyRight());
        $this->assertSame(LegacyRight::ShareRefresh, SambaPermission::ShareRefresh->legacyRight());
    }

    #[Test]
    public function share_manage_has_human_label_and_share_category(): void
    {
        $this->assertSame('Gérer les partages de classe (ACLs POSIX)', SambaPermission::ShareManage->label());
        $this->assertSame('share', SambaPermission::ShareManage->category());
    }

    #[Test]
    public function share_manage_is_excluded_from_bitmask_mapping(): void
    {
        // ShareManage partage le bit avec ShareRefresh — elle est secondaire.
        // Le mapping doit pointer le bit `SE_SHARE_REFRESH` sur `share.refresh`
        // (canonique), pas sur `share.manage`.
        $mapping = SambaPermission::bitmaskMapping();
        $bit = LegacyRight::ShareRefresh->value;

        $this->assertArrayHasKey($bit, $mapping);
        $this->assertSame(
            'share.refresh',
            $mapping[$bit],
            'Le bit SE_SHARE_REFRESH doit rester mappé sur share.refresh (canonique). '
            . 'share.manage est secondary — attribuée explicitement par le seeder.'
        );
    }

    #[Test]
    public function from_bitmask_does_not_grant_share_manage(): void
    {
        // Un user avec le bit `SE_SHARE_REFRESH` (legacy ShareAdmin) ne doit
        // PAS recevoir `share.manage` automatiquement via fromBitmask().
        $perms = SambaPermission::fromBitmask(LegacyRight::ShareRefresh->value);

        $this->assertContains('share.refresh', $perms);
        $this->assertNotContains(
            'share.manage',
            $perms,
            'fromBitmask ne doit pas sur-attribuer share.manage (secondary bit). '
            . 'Le seeder l\'ajoute explicitement aux rôles ShareAdmin/UserAdmin/SuperAdmin.'
        );
    }
}
