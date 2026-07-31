<?php

declare(strict_types=1);

namespace Tests\Feature\Extensions;

use App\Enums\ExtensionType;
use App\Enums\SambaRole;
use App\Models\Extension;
use App\Models\ExtensionSource;
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
            // Story 56.2 (AR3) : une `app` DOIT déclarer `/ext/<id>` — c'est le
            // chemin que l'installation provisionne (ProxyPass).
            'entry_url' => $type === ExtensionType::App->value ? '/ext/'.$key : '/'.$key,
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
        // Type `app` artificiellement `integrated` (factory) SANS port : rien
        // n'a été provisionné derrière `/ext/<clé>`, la tuile mènerait à un
        // 404 — fail-closed testé explicitement. Story 56.2 : c'est le
        // `installed_port` qui distingue une installation réelle d'une ligne
        // fabriquée, pas le type.
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

    // ── Story 56.1 — l'état de la SOURCE ne retire jamais une tuile ────────
    //
    // Décision tranchée en 56.1 (report explicite de 54.3) : une extension
    // INTÉGRÉE garde sa tuile quel que soit l'état de sa source. Doctrine
    // « rupture = figer l'état » + invariant 54.1 #4 (jamais de dé-intégration
    // silencieuse). Faire disparaître une tuile parce qu'un dépôt distant est
    // tombé transformerait un incident de catalogue en panne visible pour les
    // profs et les élèves — l'exact contraire de NFR7.

    #[Test]
    public function an_integrated_extension_of_a_disabled_source_keeps_its_tile(): void
    {
        $source = ExtensionSource::factory()->remote()->disabled()->create();
        $extension = $this->integratedLink('agenda', 'Agenda', ['admin']);
        $extension->extension_source_id = $source->id;
        $extension->save();

        $admin = $this->makeUser('autre');
        $admin->assignRole(SambaRole::SuperAdmin->value);

        $this->assertSame(['agenda'], array_column($this->service->tilesFor($admin), 'key'));
    }

    #[Test]
    public function an_integrated_extension_of_a_source_in_signature_error_keeps_its_tile(): void
    {
        $source = ExtensionSource::factory()->remote()->syncError()->create();
        $extension = $this->integratedLink('agenda', 'Agenda', ['admin']);
        $extension->extension_source_id = $source->id;
        $extension->save();

        $admin = $this->makeUser('autre');
        $admin->assignRole(SambaRole::SuperAdmin->value);

        $this->assertSame(['agenda'], array_column($this->service->tilesFor($admin), 'key'));
    }

    #[Test]
    public function an_available_extension_of_an_active_source_never_becomes_a_tile(): void
    {
        // Contre-épreuve de la décision ci-dessus : « intégrée » reste la SEULE
        // condition d'apparition. (Déjà couvert par les tests d'état 54.3 ; on
        // le REDIT ici avec une source explicitement active, pour que la
        // décision 56.1 soit lisible d'un seul tenant.)
        $source = ExtensionSource::factory()->remote()->create();
        Extension::factory()
            ->link('/agenda')
            ->create([
                'key' => 'agenda',
                'extension_source_id' => $source->id,
                'manifest' => $this->manifestFor('agenda', ExtensionType::Link->value, ['admin']),
            ]);

        $admin = $this->makeUser('autre');
        $admin->assignRole(SambaRole::SuperAdmin->value);

        $this->assertSame([], $this->service->tilesFor($admin));
    }

    // =====================================================================
    // Story 56.2 — une `app` RÉELLEMENT installée obtient sa tuile
    // =====================================================================

    #[Test]
    public function an_installed_app_gets_a_tile_pointing_at_its_provisioned_path(): void
    {
        // Levée MAÎTRISÉE du filtre `type = link` de 54.3 : le moteur
        // d'installation existe désormais, et `installed_port` atteste que
        // l'exposition `/ext/<clé>` a bien été provisionnée.
        Extension::factory()->app()->installed(8600)->create([
            'key' => 'hello',
            'name' => 'Hello',
            'manifest' => $this->manifestFor('hello', ExtensionType::App->value, ['prof']),
        ]);

        $prof = $this->makeUser('prof');
        $tiles = $this->service->tilesFor($prof);

        $this->assertSame(['hello'], array_column($tiles, 'key'));
        // Le chemin de manifest est résolu contre la racine de l'INSTANCE
        // (`absoluteEntryUrl()`, régression lab1 sous-chemin) : la tuile porte
        // l'URL réellement cliquable, pas la chaîne brute du manifest.
        $this->assertSame(url('/ext/hello'), $tiles[0]['entry_url']);
    }

    #[Test]
    public function a_removed_app_loses_its_tile(): void
    {
        $extension = Extension::factory()->app()->installed(8600)->create([
            'key' => 'hello',
            'name' => 'Hello',
            'manifest' => $this->manifestFor('hello', ExtensionType::App->value, ['prof']),
        ]);

        $prof = $this->makeUser('prof');
        $this->assertSame(['hello'], array_column($this->service->tilesFor($prof), 'key'));

        // Exactement ce que fait `markAppRemoved()` : statut ET colonnes
        // d'installation remis à zéro.
        app(\App\Services\Extensions\ExtensionLifecycleService::class)->markAppRemoved($extension->id, null);

        $this->assertSame([], $this->service->tilesFor($prof));
    }

    #[Test]
    public function an_installed_app_still_respects_role_visibility(): void
    {
        Extension::factory()->app()->installed(8600)->create([
            'key' => 'hello',
            'name' => 'Hello',
            'manifest' => $this->manifestFor('hello', ExtensionType::App->value, ['admin']),
        ]);

        $this->assertSame([], $this->service->tilesFor($this->makeUser('eleve')));
    }

    // =====================================================================
    // Story 56.5 (AC2, FR35) — la tuile PORTE l'état de santé, LU
    // =====================================================================
    //
    // ⚠️ Ajouts en FIN de fichier, aucune assertion existante retouchée : le
    // socle 54.3/56.1/56.2 doit rester vrai verbatim.

    /** Une `app` installée sondée injoignable RÉCEMMENT est marquée. */
    #[Test]
    public function a_freshly_unreachable_installed_app_is_marked_unavailable(): void
    {
        Extension::factory()->app()->installed(8600)->unreachable()->create([
            'key' => 'hello',
            'name' => 'Hello',
            'manifest' => $this->manifestFor('hello', ExtensionType::App->value, ['prof']),
        ]);

        $tiles = $this->service->tilesFor($this->makeUser('prof'));

        $this->assertSame(['hello'], array_column($tiles, 'key'));
        $this->assertTrue($tiles[0]['unavailable']);
    }

    /**
     * Un état PÉRIMÉ ne se signale pas : un scheduler arrêté n'est pas une
     * extension morte, et badger 30 tuiles pour cette raison serait pire que le
     * silence (c'est le doctor qui porte CE diagnostic).
     */
    #[Test]
    public function a_stale_unreachable_state_is_not_marked(): void
    {
        Extension::factory()
            ->app()
            ->installed(8600)
            ->unreachable(now()->subHours(3))
            ->create([
                'key' => 'hello',
                'name' => 'Hello',
                'manifest' => $this->manifestFor('hello', ExtensionType::App->value, ['prof']),
            ]);

        $tiles = $this->service->tilesFor($this->makeUser('prof'));

        $this->assertSame(['hello'], array_column($tiles, 'key'), 'la tuile RESTE : la santé ne retire jamais une tuile');
        $this->assertFalse($tiles[0]['unavailable']);
    }

    /** Jamais sondée ⇒ on ne SAIT pas ⇒ aucun signal. */
    #[Test]
    public function a_never_probed_app_is_not_marked(): void
    {
        Extension::factory()->app()->installed(8600)->create([
            'key' => 'hello',
            'name' => 'Hello',
            'manifest' => $this->manifestFor('hello', ExtensionType::App->value, ['prof']),
        ]);

        $this->assertFalse($this->service->tilesFor($this->makeUser('prof'))[0]['unavailable']);
    }

    /** CONTRE-ÉPREUVE : un backend joignable n'est jamais marqué. */
    #[Test]
    public function a_healthy_installed_app_is_not_marked(): void
    {
        Extension::factory()->app()->installed(8600)->healthy()->create([
            'key' => 'hello',
            'name' => 'Hello',
            'manifest' => $this->manifestFor('hello', ExtensionType::App->value, ['prof']),
        ]);

        $this->assertFalse($this->service->tilesFor($this->makeUser('prof'))[0]['unavailable']);
    }

    /**
     * Une `link` ne peut PAS être marquée, même si des colonnes de santé
     * traînaient sur sa ligne : elle n'a aucun backend. La règle unique
     * (`isFlaggedUnreachable()`) exige `isHealthMonitored()`.
     */
    #[Test]
    public function a_link_extension_is_never_marked_unavailable(): void
    {
        $extension = $this->integratedLink('doc', 'Documentation', ['prof']);
        $extension->health_status = \App\Models\Extension::HEALTH_UNREACHABLE;
        $extension->health_checked_at = now();
        $extension->save();

        $this->assertFalse($this->service->tilesFor($this->makeUser('prof'))[0]['unavailable']);
    }

    #[Test]
    public function every_tile_carries_the_unavailable_key(): void
    {
        $this->integratedLink('doc', 'Documentation', ['prof']);

        foreach ($this->service->tilesFor($this->makeUser('prof')) as $tile) {
            $this->assertArrayHasKey('unavailable', $tile);
            $this->assertIsBool($tile['unavailable']);
        }
    }
}
