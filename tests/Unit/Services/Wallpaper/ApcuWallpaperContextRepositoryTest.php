<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Wallpaper;

use App\Dto\Wallpaper\WallpaperContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests unit parsing APCu → WallpaperContext.
 *
 * Story 4.7 — AC 3, correction post-review #1 : la structure réelle en prod
 * est `'user' => [...]` / `'machine' => [...]` arrays LDAP, pas des strings.
 */
class ApcuWallpaperContextRepositoryTest extends TestCase
{
    #[Test]
    public function parses_real_apcu_structure_user_and_machine_as_arrays(): void
    {
        // Structure réelle posée par applications.inc.php::get_apps() :
        //   $info['user'] = search_user(...)  (array LDAP avec cn, fullname, …)
        //   $info['machine'] = search_machine(...)  (array LDAP avec cn, …)
        //   $info['salle'] = ldap_dn2cn(...)  (string)
        $apcu = [
            'user' => [
                'cn' => 'jdoe',
                'fullname' => 'John Doe',
                'dn' => 'cn=jdoe,ou=users,dc=example',
            ],
            'machine' => [
                'cn' => 'PC-001',
                'dn' => 'cn=PC-001,ou=machines,dc=example',
            ],
            'salle' => 'salle_42',
            'admin' => true,
            'list_u' => ['Profs', 'classe_6A'],
            'os' => 'linux',
            'time' => 1_700_000_000,
        ];

        $ctx = WallpaperContext::fromApcuArray($apcu);

        $this->assertSame('jdoe', $ctx->userLogin);
        $this->assertSame('John Doe', $ctx->userFullname);
        $this->assertSame('PC-001', $ctx->machineName);
        $this->assertSame('salle_42', $ctx->salleName);
        $this->assertTrue($ctx->userIsAdmin);
        $this->assertSame(['Profs', 'classe_6A'], $ctx->groupsUser);
        $this->assertSame('Profs', $ctx->mainUserType);
        $this->assertSame('linux', $ctx->os);
        $this->assertSame(1_700_000_000, $ctx->timestamp);
    }

    #[Test]
    public function falls_back_to_cn_when_fullname_absent(): void
    {
        $ctx = WallpaperContext::fromApcuArray([
            'user' => ['cn' => 'alice'],
            'machine' => ['cn' => 'PC-7'],
            'salle' => '',
        ]);

        $this->assertSame('alice', $ctx->userLogin);
        $this->assertSame('alice', $ctx->userFullname);
        $this->assertSame('PC-7', $ctx->machineName);
    }

    #[Test]
    public function tolerates_legacy_string_user_and_machine(): void
    {
        // Compat pour tests synthétiques ou contextes anciens où la valeur
        // serait sérialisée en string (jamais en prod mais défensif).
        $ctx = WallpaperContext::fromApcuArray([
            'user' => 'bob',
            'fullname' => 'Bob Martin',
            'machine' => 'LAPTOP-3',
            'salle' => '',
        ]);

        $this->assertSame('bob', $ctx->userLogin);
        $this->assertSame('Bob Martin', $ctx->userFullname);
        $this->assertSame('LAPTOP-3', $ctx->machineName);
    }

    #[Test]
    public function returns_empty_strings_when_structures_missing(): void
    {
        $ctx = WallpaperContext::fromApcuArray([]);

        $this->assertSame('', $ctx->userLogin);
        $this->assertSame('', $ctx->userFullname);
        $this->assertSame('', $ctx->machineName);
        $this->assertSame('', $ctx->salleName);
        $this->assertSame([], $ctx->groupsUser);
        $this->assertNull($ctx->mainUserType);
    }

    #[Test]
    public function does_not_cast_array_to_string_anymore(): void
    {
        // Garde-fou anti-régression : avant le fix, `(string)$array` donnait
        // "Array" en prod. Vérifie explicitement que ce piège ne peut pas revenir.
        $ctx = WallpaperContext::fromApcuArray([
            'user' => ['cn' => 'foo', 'fullname' => 'Foo Bar'],
            'machine' => ['cn' => 'BAR-01'],
        ]);

        $this->assertNotSame('Array', $ctx->userLogin);
        $this->assertNotSame('Array', $ctx->machineName);
    }
}
