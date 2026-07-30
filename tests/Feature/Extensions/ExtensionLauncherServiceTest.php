<?php

declare(strict_types=1);

namespace Tests\Feature\Extensions;

use App\Enums\ExtensionType;
use App\Enums\SambaRole;
use App\Models\Extension;
use App\Models\User;
use App\Services\Extensions\ExtensionLauncherService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 54.3 (AC1, AC2) — `ExtensionLauncherService::tilesFor()` : les
 * tuiles d'un utilisateur, filtrées par intersection `visibility.roles` ∩
 * `businessRoles()`.
 */
class ExtensionLauncherServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExtensionLauncherService $service;

    protected function setUp(): void
    {
        parent::setUp();
        (new PermissionSeeder())->run();
        $this->service = app(ExtensionLauncherService::class);
    }

    private function makeUser(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /** @param list<string> $roles */
    private function manifestFor(string $key, string $type, array $roles): array
    {
        return [
            'manifest_version' => 1,
            'id' => $key,
            'type' => $type,
            'name' => $key,
            'version' => '1.0.0',
            'entry_url' => '/'.$key,
            'icon' => 'fa-solid fa-puzzle-piece',
            'publisher' => 'SambaEdu',
            'description' => 'Extension de test.',
            'scopes' => [],
            'dependencies' => [],
            'visibility' => ['roles' => $roles],
        ];
    }

    /** @param list<string> $roles */
    private function integratedLink(string $key, string $name, array $roles): Extension
    {
        return Extension::factory()
            ->link('/'.$key)
            ->integrated()
            ->create([
                'key' => $key,
                'name' => $name,
                'manifest' => $this->manifestFor($key, ExtensionType::Link->value, $roles),
            ]);
    }

    #[Test]
    public function a_prof_sees_only_tiles_visible_to_prof(): void
    {
        $this->integratedLink('for-admin', 'Admin only', ['admin']);
        $this->integratedLink('for-prof', 'Prof only', ['prof']);
        $this->integratedLink('for-both', 'Prof and eleve', ['prof', 'eleve']);

        $prof = $this->makeUser('prof');

        $keys = array_column($this->service->tilesFor($prof), 'key');

        $this->assertEqualsCanonicalizing(['for-prof', 'for-both'], $keys);
    }

    #[Test]
    public function an_eleve_sees_only_tiles_visible_to_eleve(): void
    {
        $this->integratedLink('for-admin', 'Admin only', ['admin']);
        $this->integratedLink('for-eleve', 'Eleve only', ['eleve']);
        $this->integratedLink('for-both', 'Prof and eleve', ['prof', 'eleve']);

        $eleve = $this->makeUser('eleve');

        $keys = array_column($this->service->tilesFor($eleve), 'key');

        $this->assertEqualsCanonicalizing(['for-eleve', 'for-both'], $keys);
    }

    #[Test]
    public function a_super_admin_sees_admin_tiles(): void
    {
        $this->integratedLink('for-admin', 'Admin only', ['admin']);
        $this->integratedLink('for-prof', 'Prof only', ['prof']);

        $admin = $this->makeUser('autre');
        $admin->assignRole(SambaRole::SuperAdmin->value);

        $keys = array_column($this->service->tilesFor($admin), 'key');

        $this->assertEqualsCanonicalizing(['for-admin'], $keys);
    }

    #[Test]
    public function a_user_with_role_autre_and_no_spatie_role_sees_nothing(): void
    {
        $this->integratedLink('for-admin', 'Admin only', ['admin']);
        $this->integratedLink('for-prof', 'Prof only', ['prof']);
        $this->integratedLink('for-eleve', 'Eleve only', ['eleve']);

        $user = $this->makeUser('autre');

        $this->assertSame([], $this->service->tilesFor($user));
    }

    #[Test]
    public function available_extensions_are_never_returned_even_if_visible(): void
    {
        Extension::factory()->link('/avail')->create([
            'key' => 'avail',
            'manifest' => $this->manifestFor('avail', ExtensionType::Link->value, ['prof']),
        ]);

        $prof = $this->makeUser('prof');

        $this->assertSame([], $this->service->tilesFor($prof));
    }

    #[Test]
    public function an_integrated_app_type_extension_is_never_returned_fail_closed(): void
    {
        // Type `app` artificiellement `integrated` (factory) : AUCUN moteur
        // `app` n'existe avant l'Epic 56 — fail-closed testé explicitement.
        Extension::factory()->integrated()->create([
            'key' => 'app-ext',
            'type' => ExtensionType::App,
            'manifest' => $this->manifestFor('app-ext', ExtensionType::App->value, ['prof']),
        ]);

        $prof = $this->makeUser('prof');

        $this->assertSame([], $this->service->tilesFor($prof));
    }

    #[Test]
    public function tiles_are_ordered_by_name(): void
    {
        $this->integratedLink('z-ext', 'Zebra', ['prof']);
        $this->integratedLink('a-ext', 'Alpha', ['prof']);
        $this->integratedLink('m-ext', 'Milieu', ['prof']);

        $prof = $this->makeUser('prof');

        $names = array_column($this->service->tilesFor($prof), 'name');

        $this->assertSame(['Alpha', 'Milieu', 'Zebra'], $names);
    }

    #[Test]
    public function returned_tiles_are_flat_arrays_not_eloquent_instances(): void
    {
        $this->integratedLink('doc', 'Documentation', ['prof']);

        $prof = $this->makeUser('prof');

        $tiles = $this->service->tilesFor($prof);

        $this->assertNotEmpty($tiles);
        foreach ($tiles as $tile) {
            $this->assertIsArray($tile);
            $this->assertArrayHasKey('key', $tile);
            $this->assertArrayHasKey('name', $tile);
            $this->assertArrayHasKey('icon', $tile);
            $this->assertArrayHasKey('entry_url', $tile);
        }
    }

    #[Test]
    public function empty_intersection_yields_empty_array(): void
    {
        $this->integratedLink('for-admin', 'Admin only', ['admin']);

        $eleve = $this->makeUser('eleve');

        $this->assertSame([], $this->service->tilesFor($eleve));
    }

    // ── Instances servies sous un sous-chemin (reverse proxy) ─────────────

    /**
     * Régression terrain (lab1, `APP_URL=https://lab1.sambaedu.org/0991229y`) :
     * la tuile portait le chemin de manifest BRUT (`/sso-demo`), que le
     * navigateur résolvait contre la racine du host — le clic partait sur
     * `https://lab1.sambaedu.org/sso-demo`, sans le préfixe d'instance, en 404.
     * Le préfixe est porté par `URL::forceRootUrl()`, donc par `url()`, jamais
     * par une chaîne recopiée dans un `href`.
     */
    #[Test]
    public function a_manifest_path_is_resolved_against_the_instance_root_url(): void
    {
        // Les DEUX appels que fait `AppServiceProvider` quand `APP_URL` porte un
        // chemin : `forceRootUrl` ne transporte pas le schéma.
        \Illuminate\Support\Facades\URL::forceRootUrl('https://lab1.sambaedu.org/0991229y');
        \Illuminate\Support\Facades\URL::forceScheme('https');

        $this->integratedLink('sso-demo', 'Démo SSO', ['prof']);

        $tiles = $this->service->tilesFor($this->makeUser('prof'));

        $this->assertSame(
            'https://lab1.sambaedu.org/0991229y/sso-demo',
            $tiles[0]['entry_url'],
        );
    }

    /**
     * Symétrique du test ci-dessus : une extension hébergée AILLEURS déclare
     * une URL absolue. La préfixer la casserait — elle doit traverser telle
     * quelle. Les deux cas sont exhaustifs, `ExtensionManifestValidator`
     * n'acceptant que `/…` ou `http(s)://` (review 54.3).
     */
    #[Test]
    public function an_absolute_http_entry_url_is_left_untouched(): void
    {
        \Illuminate\Support\Facades\URL::forceRootUrl('https://lab1.sambaedu.org/0991229y');

        $manifest = $this->manifestFor('bbb', ExtensionType::Link->value, ['prof']);
        $manifest['entry_url'] = 'https://bbb.example.org/rooms';

        Extension::factory()
            ->link('https://bbb.example.org/rooms')
            ->integrated()
            ->create(['key' => 'bbb', 'name' => 'BigBlueButton', 'manifest' => $manifest]);

        $tiles = $this->service->tilesFor($this->makeUser('prof'));

        $this->assertSame('https://bbb.example.org/rooms', $tiles[0]['entry_url']);
    }
}
