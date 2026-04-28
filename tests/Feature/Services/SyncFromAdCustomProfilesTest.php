<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Services\PermissionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 7.2 — AC4.
 *
 * Rapatriement non-destructif des profils LDAP custom via
 * `PermissionService::importCustomProfilesFromAd()`.
 *
 * Scénarios :
 *  - profil custom inédit → création + permissions via bitmask
 *  - profil custom déjà en base → jamais ré-écrit
 *  - profil seedé (`RefNum`, `se3_is_admin`, …) → ignoré (géré par seeder)
 *  - profil historique (`sovajon_is_admin`, `annu_can_read`) → mappé vers rôle Spatie
 */
class SyncFromAdCustomProfilesTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;

    protected function setUp(): void
    {
        parent::setUp();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createPermissionSchema();
        (new PermissionSeeder())->run();
    }

    protected function tearDown(): void
    {
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function service(): PermissionService
    {
        return app(PermissionService::class);
    }

    public function test_imports_custom_profile_with_permissions_from_bitmask(): void
    {
        $fetcher = fn() => [
            'Animateur CDI' => 0x302, // ComputerView (0x100) + ComputerControl (0x200) + UserRead (0x02)
        ];

        $stats = $this->service()->importCustomProfilesFromAd(null, $fetcher);

        $this->assertEquals(1, $stats['scanned']);
        $this->assertEquals(1, $stats['custom_new']);

        $role = Role::where('name', 'Animateur CDI')->first();
        $this->assertNotNull($role);
        $permsNames = $role->permissions->pluck('name')->toArray();
        $this->assertContains('computer.view', $permsNames);
        $this->assertContains('computer.control', $permsNames);
        $this->assertContains('user.read', $permsNames);
    }

    public function test_skips_seeded_profiles_entirely(): void
    {
        $fetcher = fn() => [
            'se3_is_admin'     => 0xFFFF,
            'computer_is_admin' => 0xEF00,
            'Annu_is_admin'    => 0xFF,
            'password_is_admin' => 0x01,
            'RefNum'            => 0x90B,
        ];

        $stats = $this->service()->importCustomProfilesFromAd(null, $fetcher);

        $this->assertEquals(5, $stats['seeded_skipped']);
        $this->assertEquals(0, $stats['custom_new']);
    }

    public function test_lock_prevents_second_import_from_touching_existing_profiles(): void
    {
        // 1er passage : crée le rôle avec un bitmask précis et pose le verrou.
        $fetcher1 = fn() => ['Animateur CDI' => 0x100]; // ComputerView
        $stats1 = $this->service()->importCustomProfilesFromAd(null, $fetcher1);
        $this->assertEquals(1, $stats1['custom_new']);
        $this->assertFalse($stats1['already_imported']);

        $role = Role::where('name', 'Animateur CDI')->first();
        $this->assertEquals(1, $role->permissions->count());

        // Entretemps, un admin édite le rôle côté UI.
        $role->syncPermissions([
            SambaPermission::UserModify->value,
            SambaPermission::UserRead->value,
        ]);

        // 2ᵉ passage : bitmask différent dans l'AD → no-op total via verrou.
        $fetcher2 = fn() => ['Animateur CDI' => 0xFF00];
        $stats2 = $this->service()->importCustomProfilesFromAd(null, $fetcher2);

        $this->assertTrue($stats2['already_imported']);
        $this->assertEquals(0, $stats2['scanned']);
        $this->assertEquals(0, $stats2['custom_new']);
        $this->assertEquals(0, $stats2['custom_unchanged']);

        $role->refresh();
        // Les permissions éditées par l'admin sont préservées.
        $this->assertEquals(2, $role->permissions->count());
        $permsNames = $role->permissions->pluck('name')->toArray();
        $this->assertContains('user.modify', $permsNames);
        $this->assertContains('user.read', $permsNames);
    }

    public function test_lock_prevents_renamed_profile_from_being_recreated(): void
    {
        // Reproduit le scénario utilisateur : import → rename UI → réimport AD
        // ne doit PAS recréer un rôle avec l'ancien nom.
        $fetcher = fn() => ['Animateur CDI' => 0x100];
        $this->service()->importCustomProfilesFromAd(null, $fetcher);

        $role = Role::where('name', 'Animateur CDI')->firstOrFail();
        $role->name = 'Animateur Documentaliste';
        $role->save();

        $stats = $this->service()->importCustomProfilesFromAd(null, $fetcher);

        $this->assertTrue($stats['already_imported']);
        $this->assertNull(Role::where('name', 'Animateur CDI')->first());
        $this->assertNotNull(Role::where('name', 'Animateur Documentaliste')->first());
    }

    public function test_historic_sovajon_is_admin_maps_to_eleve_admin_role(): void
    {
        // Supprime le rôle `eleve-admin` seed pour simuler une première sync.
        Role::where('name', SambaRole::EleveAdmin->value)->delete();

        $fetcher = fn() => ['sovajon_is_admin' => 0x07];
        $stats = $this->service()->importCustomProfilesFromAd(null, $fetcher);

        $this->assertEquals(1, $stats['historic_mapped']);
        $role = Role::where('name', SambaRole::EleveAdmin->value)->first();
        $this->assertNotNull($role, 'Le rôle `eleve-admin` doit être créé via le mapping historique');
        // Permissions canoniques du seed `EleveAdmin`.
        $this->assertEquals(
            count(SambaRole::EleveAdmin->permissionNames()),
            $role->permissions->count()
        );
    }

    public function test_subsequent_runs_are_noop(): void
    {
        $fetcher = fn() => [
            'Animateur CDI' => 0x300,
            'Référent RGPD' => 0x40,
            'se3_is_admin' => 0xFFFF, // seedé — ignoré
        ];

        $stats1 = $this->service()->importCustomProfilesFromAd(null, $fetcher);
        $stats2 = $this->service()->importCustomProfilesFromAd(null, $fetcher);
        $stats3 = $this->service()->importCustomProfilesFromAd(null, $fetcher);

        $this->assertEquals(2, $stats1['custom_new']);
        $this->assertFalse($stats1['already_imported']);

        $this->assertTrue($stats2['already_imported']);
        $this->assertEquals(0, $stats2['scanned']);
        $this->assertTrue($stats3['already_imported']);
        $this->assertEquals(0, $stats3['scanned']);

        $this->assertNotNull(Role::where('name', 'Animateur CDI')->first());
        $this->assertNotNull(Role::where('name', 'Référent RGPD')->first());
    }

    public function test_fetcher_error_does_not_crash(): void
    {
        $fetcher = function () {
            throw new \RuntimeException('LDAP indisponible');
        };

        $stats = $this->service()->importCustomProfilesFromAd(null, $fetcher);

        $this->assertEquals(1, $stats['errors']);
        $this->assertEquals(0, $stats['scanned']);
    }

    /**
     * Review 7.2 #2 — Un profil custom avec bit 0x800 (ComputerInstall) sans
     * la totalité du composite SE_COMPUTER_ADMIN ne doit PAS recevoir
     * `app.customize`. Sinon, un profil partiel "parc light" recevrait à tort
     * le droit de personnaliser les apps (Firefox/Thunderbird).
     */
    public function test_custom_profile_with_partial_computer_bits_does_not_get_app_customize(): void
    {
        // 0x900 = ComputerView (0x100) + ComputerInstall (0x800) — sans Control/Elevate/WpkgAssign/WpkgAdd.
        $fetcher = fn() => ['Parc Light' => 0x900];

        $stats = $this->service()->importCustomProfilesFromAd(null, $fetcher);

        $this->assertEquals(1, $stats['custom_new']);
        $role = Role::where('name', 'Parc Light')->first();
        $this->assertNotNull($role);
        $permsNames = $role->permissions->pluck('name')->toArray();

        $this->assertContains(SambaPermission::ComputerView->value, $permsNames);
        $this->assertContains(SambaPermission::ComputerInstall->value, $permsNames);
        $this->assertNotContains(
            SambaPermission::AppCustomize->value,
            $permsNames,
            'Sans le composite ComputerAdmin complet, app.customize ne doit pas être accordée (review 7.2 #2)'
        );
    }

    /**
     * Review 7.2 #2 — Pendant de ci-dessus : un profil avec le composite
     * ComputerAdmin complet (0xEF00) doit bien recevoir `app.customize`
     * (iso-convention matrice §11 pour SE_COMPUTER_ADMIN).
     */
    public function test_custom_profile_with_full_computer_admin_gets_app_customize(): void
    {
        $fetcher = fn() => ['Custom Parc Admin' => \App\Enums\LegacyRight::computerAdmin()];

        $stats = $this->service()->importCustomProfilesFromAd(null, $fetcher);

        $this->assertEquals(1, $stats['custom_new']);
        $role = Role::where('name', 'Custom Parc Admin')->first();
        $this->assertNotNull($role);
        $permsNames = $role->permissions->pluck('name')->toArray();

        $this->assertContains(SambaPermission::AppCustomize->value, $permsNames);
        $this->assertContains(SambaPermission::ComputerView->value, $permsNames);
        $this->assertContains(SambaPermission::ComputerInstall->value, $permsNames);
    }
}
