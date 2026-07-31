<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\SambaRole;
use App\Models\Extension;
use App\Models\ExtensionSource;
use App\Models\User;
use App\Services\Extensions\ExtensionLifecycleService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Story 54.3 (AC1, AC2, AC3, NFR9, FR14) — composant Livewire du lanceur
 * `app-launcher` : tuiles filtrées par rôle métier, ensemble exact des
 * intégrées de type `link`, état vide propre, et la frontière NFR9 (1
 * requête SQL, zéro HTTP).
 *
 * ⚠️ Leçon 54.2 : le DOM contient des textes partagés — on asserte sur les
 * `data-testid` (`assertSeeHtml`/`assertDontSeeHtml`), jamais sur du texte.
 */
class AppLauncherTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'components::organisms.app-launcher';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        (new PermissionSeeder())->run();
    }

    // ── Helpers ───────────────────────────────────────────────────────────

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
                'manifest' => $this->manifestFor($key, \App\Enums\ExtensionType::Link->value, $roles),
            ]);
    }

    // ── AC1 — filtrage par rôle métier ──────────────────────────────────────

    #[Test]
    public function a_prof_sees_the_prof_tile_but_not_the_admin_tile(): void
    {
        $this->integratedLink('for-prof', 'Prof only', ['prof']);
        $this->integratedLink('for-admin', 'Admin only', ['admin']);

        $this->actingAs($this->makeUser('prof'));

        Livewire::test(self::COMPONENT)
            ->assertSeeHtml('data-testid="launcher-tile-for-prof"')
            ->assertDontSeeHtml('data-testid="launcher-tile-for-admin"');
    }

    #[Test]
    public function an_eleve_sees_the_eleve_tile_but_not_the_prof_tile(): void
    {
        $this->integratedLink('for-eleve', 'Eleve only', ['eleve']);
        $this->integratedLink('for-prof', 'Prof only', ['prof']);

        $this->actingAs($this->makeUser('eleve'));

        Livewire::test(self::COMPONENT)
            ->assertSeeHtml('data-testid="launcher-tile-for-eleve"')
            ->assertDontSeeHtml('data-testid="launcher-tile-for-prof"');
    }

    #[Test]
    public function a_super_admin_sees_the_admin_tile(): void
    {
        $this->integratedLink('for-admin', 'Admin only', ['admin']);

        $admin = $this->makeUser('autre');
        $admin->assignRole(SambaRole::SuperAdmin->value);
        $this->actingAs($admin);

        Livewire::test(self::COMPONENT)
            ->assertSeeHtml('data-testid="launcher-tile-for-admin"');
    }

    #[Test]
    public function a_role_autre_user_with_no_spatie_role_sees_the_empty_state(): void
    {
        $this->integratedLink('for-prof', 'Prof only', ['prof']);

        $this->actingAs($this->makeUser('autre'));

        $html = Livewire::test(self::COMPONENT)
            ->assertDontSeeHtml('data-testid="launcher-tile-for-prof"')
            ->html();

        self::assertStringNotContainsString('hidden', $this->emptyBlockTag($html));
    }

    #[Test]
    public function an_administratif_sees_the_documentation_tile(): void
    {
        // Review 54.3 #5 : `businessRoles()` mappe administratif|administratifs
        // |admin → 'administratif', mais le manifest livré ne visait que
        // admin/prof/eleve — une population réelle, écrite telle quelle par la
        // sync, ouvrait donc une gaufre SYSTÉMATIQUEMENT vide le jour de la
        // clôture de l'epic. La documentation est le contre-exemple parfait
        // d'une application réservée aux enseignants et aux élèves : le rôle a
        // été ajouté au manifest (une chaîne, aucun code).
        $this->integratedLink('doc', 'Documentation', ['admin', 'prof', 'eleve', 'administratif']);

        $this->actingAs($this->makeUser('administratif'));

        Livewire::test(self::COMPONENT)
            ->assertSeeHtml('data-testid="launcher-tile-doc"');
    }

    // ── AC2 — ensemble exact des intégrées de type link ─────────────────────

    #[Test]
    public function an_available_extension_never_appears_even_if_visible(): void
    {
        Extension::factory()->link('/avail')->create([
            'key' => 'avail',
            'manifest' => $this->manifestFor('avail', \App\Enums\ExtensionType::Link->value, ['prof']),
        ]);

        $this->actingAs($this->makeUser('prof'));

        $html = Livewire::test(self::COMPONENT)
            ->assertDontSeeHtml('data-testid="launcher-tile-avail"')
            ->html();

        self::assertStringNotContainsString('hidden', $this->emptyBlockTag($html));
    }

    #[Test]
    public function an_integrated_app_type_extension_never_appears_fail_closed(): void
    {
        Extension::factory()->integrated()->create([
            'key' => 'app-ext',
            'type' => \App\Enums\ExtensionType::App,
            'manifest' => $this->manifestFor('app-ext', \App\Enums\ExtensionType::App->value, ['prof']),
        ]);

        $this->actingAs($this->makeUser('prof'));

        $html = Livewire::test(self::COMPONENT)
            ->assertDontSeeHtml('data-testid="launcher-tile-app-ext"')
            ->html();

        self::assertStringNotContainsString('hidden', $this->emptyBlockTag($html));
    }

    #[Test]
    public function tile_link_opens_in_a_new_tab(): void
    {
        $this->integratedLink('doc', 'Documentation', ['prof']);

        $this->actingAs($this->makeUser('prof'));

        $component = Livewire::test(self::COMPONENT);
        $component->assertSeeHtml('data-testid="launcher-tile-doc"');

        $html = $component->html();
        self::assertMatchesRegularExpression(
            '/<a\b[^>]*data-testid="launcher-tile-doc"[^>]*>/s',
            $html,
            'la tuile launcher-tile-doc doit exister',
        );

        preg_match('/<a\b[^>]*data-testid="launcher-tile-doc"[^>]*>/s', $html, $matches);
        $tileTag = $matches[0] ?? '';

        self::assertStringContainsString('target="_blank"', $tileTag, 'la tuile doit ouvrir dans un nouvel onglet (FR16)');
        self::assertStringContainsString('rel="noopener"', $tileTag, 'la tuile doit porter rel="noopener"');
    }

    #[Test]
    public function integrate_then_uninstall_toggles_the_tile_across_renders(): void
    {
        // Solde l'AC d'epic 54.2 : « sa tuile disparaît du lanceur ».
        $source = ExtensionSource::factory()->bundled()->create();
        $extension = Extension::factory()->link('/doc')->create([
            'extension_source_id' => $source->id,
            'key' => 'doc',
            'name' => 'Documentation',
            'manifest' => $this->manifestFor('doc', \App\Enums\ExtensionType::Link->value, ['prof']),
        ]);

        $prof = $this->makeUser('prof');
        $this->actingAs($prof);

        // Pas encore intégrée : absente, message d'état vide VISIBLE.
        $html = Livewire::test(self::COMPONENT)
            ->assertDontSeeHtml('data-testid="launcher-tile-doc"')
            ->html();
        self::assertStringNotContainsString('hidden', $this->emptyBlockTag($html));

        app(ExtensionLifecycleService::class)->integrate($extension->id, $prof);

        // Intégrée : visible au rendu suivant, message d'état vide MASQUÉ.
        $html = Livewire::test(self::COMPONENT)
            ->assertSeeHtml('data-testid="launcher-tile-doc"')
            ->html();
        self::assertStringContainsString('hidden', $this->emptyBlockTag($html));

        app(ExtensionLifecycleService::class)->uninstall($extension->id, $prof);

        // Désinstallée : de nouveau absente, message d'état vide re-VISIBLE.
        $html = Livewire::test(self::COMPONENT)
            ->assertDontSeeHtml('data-testid="launcher-tile-doc"')
            ->html();
        self::assertStringNotContainsString('hidden', $this->emptyBlockTag($html));
    }

    // ── AC3 — état vide propre ───────────────────────────────────────────────

    /**
     * Extrait la balise ouvrante du bloc d'état vide.
     *
     * ⚠️ Le bloc est rendu INCONDITIONNELLEMENT (c'est l'astuce qui évite un
     * `@if` de premier niveau dans un SFC omniprésent) : seule sa classe
     * `hidden` bascule. Asserter la seule PRÉSENCE de `data-testid` était donc
     * tautologique — l'assertion passait dans les deux états et n'affirmait
     * rien de plus que « le composant s'est rendu ». C'est la CLASSE qu'il faut
     * regarder.
     */
    /**
     * Extrait la balise RACINE du composant rendu.
     *
     * ⚠️ Livewire injecte ses attributs (`wire:key`, `wire:snapshot`, `wire:id`,
     * `wire:name`…) DANS la balise racine : le HTML ne commence donc jamais par
     * `<div class="…"`. C'est la balise entière qu'il faut inspecter.
     */
    private function rootTag(string $html): string
    {
        self::assertSame(
            1,
            preg_match('/^<div\b[^>]*>/s', trim($html), $m),
            'le composant doit rendre une balise racine <div>',
        );

        return $m[0];
    }

    private function emptyBlockTag(string $html): string
    {
        self::assertSame(
            1,
            preg_match('/<div\b[^>]*data-testid="launcher-empty"[^>]*>/s', $html, $m),
            'le bloc d\'état vide doit être présent dans le DOM (rendu inconditionnel)',
        );

        return $m[0];
    }

    #[Test]
    public function empty_registry_shows_the_empty_state_block_visible(): void
    {
        $this->actingAs($this->makeUser('prof'));

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringNotContainsString(
            'hidden',
            $this->emptyBlockTag($html),
            'registre vide ⇒ le message d\'état vide est VISIBLE',
        );
    }

    #[Test]
    public function the_empty_state_message_is_hidden_when_tiles_exist(): void
    {
        // CONTRE-ÉPREUVE indispensable, totalement absente jusqu'ici : sans
        // elle, supprimer le ternaire `hidden` — donc afficher « Aucune
        // application disponible. » SOUS les tuiles de tous les utilisateurs
        // qui en ont — laissait la suite entièrement verte.
        $this->actingAs($this->makeUser('prof'));
        $this->integratedLink('doc', 'Documentation', ['prof']);

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringContainsString('data-testid="launcher-tile-doc"', $html);
        self::assertStringContainsString(
            'hidden',
            $this->emptyBlockTag($html),
            'des tuiles existent ⇒ le message d\'état vide est MASQUÉ',
        );
    }

    #[Test]
    public function unresolved_user_yields_a_clean_empty_state_without_error(): void
    {
        // Aucun actingAs() : auth()->user() est null au mount().
        $html = Livewire::test(self::COMPONENT)->assertOk()->html();

        self::assertStringNotContainsString('hidden', $this->emptyBlockTag($html));
    }

    #[Test]
    public function the_component_root_stays_stable_across_states(): void
    {
        // ⚠️ Les classes attendues suivent le DESIGN en vigueur (passées de
        // `dropdown-end` à `dropdown-start` au redesign de la navbar) : ce
        // test ne verrouille pas une esthétique, il verrouille une STRUCTURE.
        //
        // ⚠️ Asserter la seule présence de la chaîne « dropdown dropdown-start »
        // ne détectait PAS l'anti-patron visé : un template écrit
        // `@if (...) <div class="dropdown dropdown-start">…@else <div
        // class="dropdown dropdown-start">…@endif` — soit exactement le `@if` de
        // premier niveau qui provoque un 500 au re-render du parent — passait
        // dans les deux états. C'est la STRUCTURE qu'il faut vérifier.
        $this->actingAs($this->makeUser('prof'));
        $emptyHtml = Livewire::test(self::COMPONENT)->html();

        $this->integratedLink('doc', 'Documentation', ['prof']);
        $filledHtml = Livewire::test(self::COMPONENT)->html();

        foreach (['vide' => $emptyHtml, 'rempli' => $filledHtml] as $state => $html) {
            self::assertStringContainsString(
                'class="dropdown dropdown-start"',
                $this->rootTag($html),
                "état {$state} : la RACINE elle-même porte les classes attendues",
            );
            self::assertSame(
                1,
                substr_count($html, 'class="dropdown dropdown-start"'),
                "état {$state} : une seule racine dans tout le rendu",
            );
        }
    }

    // ── NFR9 — 1 requête SQL, zéro HTTP ──────────────────────────────────────

    #[Test]
    public function rendering_the_launcher_emits_exactly_one_extensions_query_and_no_http(): void
    {
        $this->integratedLink('doc', 'Documentation', ['prof']);
        $this->integratedLink('for-admin', 'Admin only', ['admin']);

        $this->actingAs($this->makeUser('prof'));

        Http::preventStrayRequests();

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test(self::COMPONENT);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        Http::assertNothingSent();

        // ⚠️ Needles QUOTÉS : "extensions" nu matcherait AUSSI
        // "extension_sources" et "extension_audit_logs" (patron
        // UpstreamCatalogBoundaryTest).
        $extensionsHits = array_filter(
            $log,
            static fn (array $q): bool => str_contains((string) $q['query'], '"extensions"'),
        );
        $sourcesHits = array_filter(
            $log,
            static fn (array $q): bool => str_contains((string) $q['query'], '"extension_sources"'),
        );
        $auditHits = array_filter(
            $log,
            static fn (array $q): bool => str_contains((string) $q['query'], '"extension_audit_logs"'),
        );

        self::assertCount(1, $extensionsHits, 'exactement 1 requête SQL sur "extensions" par rendu (NFR9)');
        self::assertCount(0, $sourcesHits, 'zéro requête sur "extension_sources" (pas de with(source))');
        self::assertCount(0, $auditHits, 'zéro requête sur "extension_audit_logs" (lecture seule)');
    }

    // ── NFR6 — dégradation gracieuse : le lanceur ne fait jamais tomber SE5 ──

    #[Test]
    public function an_unreadable_registry_degrades_to_the_empty_state_instead_of_500ing(): void
    {
        // ⚠️ LE test le plus important de la story. La navbar est rendue par
        // `layouts::app` ET `layouts::legacy-embed` : sur TOUTE page
        // authentifiée du produit. Sans garde, une table `extensions` absente
        // faisait tomber l'INTÉGRALITÉ de SE5 en 500, y compris des pages sans
        // aucun rapport avec les extensions.
        //
        // Ce n'est pas théorique : `scripts/update.sh` sert le code neuf pendant
        // tout composer + npm + build VitePress AVANT de lancer
        // `migrate --force`. La release qui livre l'Epic 54 traverse donc
        // forcément une fenêtre de plusieurs minutes où la table n'existe pas.
        //
        // C'est ce test — pas une table hand-rollée recopiée dans les tests de
        // page — qui prouve NFR6.
        $this->actingAs($this->makeUser('prof'));

        Schema::drop('extensions');

        $html = Livewire::test(self::COMPONENT)->assertOk()->html();

        self::assertStringNotContainsString(
            'hidden',
            $this->emptyBlockTag($html),
            'registre illisible ⇒ état vide propre, jamais une 500',
        );
        self::assertStringContainsString('class="dropdown dropdown-start"', $this->rootTag($html));
    }

    // ── FR14 — aucune route, aucun middleware, aucune garde ──────────────────

    #[Test]
    public function the_launcher_registers_no_route_of_its_own(): void
    {
        // ⚠️ Ce test inspectait auparavant `git diff`/`git status` sur les
        // fichiers de routes. Trois défauts : il testait l'ARBRE DE TRAVAIL du
        // développeur (toute story future touchant légitimement web.php faisait
        // échouer le test du lanceur — un piège pour l'équipe entière) ; il
        // était VACUOUS là où il aurait compté (`@shell_exec` + `2>/dev/null` :
        // sans `.git` — la VM n'en a pas — ou avec `shell_exec` désactivé, il
        // passait sans rien vérifier) ; et il ne démontrait pas FR14.
        // On assert désormais l'objet réel : la table de routage.
        $extensionRoutes = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($r): string => (string) $r->uri())
            ->filter(static fn (string $uri): bool => str_contains($uri, 'extension'))
            ->values();

        self::assertNotEmpty($extensionRoutes, 'les routes 54.1 existent bien');
        foreach ($extensionRoutes as $uri) {
            self::assertStringStartsWith(
                'admin/extensions',
                $uri,
                'le lanceur n\'enregistre aucune route : seules celles de 54.1 existent',
            );
        }
    }

    #[Test]
    public function masking_a_tile_is_not_a_protection_the_target_stays_reachable(): void
    {
        // FR14 littéral : « la tuile est un affichage, l'autorisation réelle
        // reste vérifiée côté extension ». Le seul test qui le DÉMONTRE : un
        // utilisateur hors visibilité n'a pas la tuile, et atteint pourtant la
        // cible — SE5 n'a posé aucune garde devant `entry_url`. Si un jour
        // quelqu'un « sécurise » le lanceur en ajoutant un middleware sur la
        // cible, ce test tombera et rappellera que ce n'est PAS le contrat.
        $extension = $this->integratedLink('for-prof', 'Prof only', ['prof']);
        $eleve = $this->makeUser('eleve');
        $this->actingAs($eleve);

        // Pas de tuile pour cet élève…
        Livewire::test(self::COMPONENT)
            ->assertDontSeeHtml('data-testid="launcher-tile-for-prof"');

        // …et pourtant AUCUNE route SE5 ne garde la cible du manifest.
        $entryUrl = $extension->entryUrl();
        $matching = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($r): bool => trim((string) $r->uri(), '/') === trim($entryUrl, '/'));

        self::assertTrue(
            $matching->isEmpty(),
            'SE5 n\'enregistre aucune route (donc aucune garde) devant la cible d\'une tuile — masquer n\'est pas protéger',
        );
    }

    #[Test]
    public function the_component_declares_no_public_action_method_beyond_the_livewire_lifecycle(): void
    {
        $this->actingAs($this->makeUser('prof'));

        $instance = Livewire::test(self::COMPONENT)->instance();
        $reflection = new ReflectionClass($instance);

        // Livewire lifecycle : seules ces méthodes peuvent être déclarées
        // directement sur la classe anonyme du composant (aucune action).
        $allowedLifecycleMethods = ['mount', 'boot', 'booted', 'render'];

        $ownMethods = array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $reflection->getName(),
        );

        $ownMethodNames = array_map(static fn (ReflectionMethod $m): string => $m->getName(), $ownMethods);

        foreach ($ownMethodNames as $name) {
            self::assertContains(
                $name,
                $allowedLifecycleMethods,
                "Le lanceur ne doit déclarer AUCUNE méthode d'action publique — trouvé : {$name}",
            );
        }
    }

    // =====================================================================
    // Story 56.5 (AC2, FR35) — badge « Indisponible », et NFR9 RENFORCÉ
    // =====================================================================
    //
    // ⚠️ Ajouts en FIN de fichier. Les tests 54.3 — dont
    // `rendering_the_launcher_emits_exactly_one_extensions_query_and_no_http`
    // et `an_unreadable_registry_degrades_to_the_empty_state_instead_of_500ing`
    // — restent VERBATIM : aucune assertion existante n'a été retouchée ni
    // relâchée. Ce que 56.5 ajoute, ce sont des tests PLUS forts, pas des
    // assouplissements.

    /** Une `app` installée dont l'état persisté dit `unreachable` (frais). */
    private function unreachableApp(string $key, string $name, array $roles, ?\Illuminate\Support\Carbon $at = null): Extension
    {
        $manifest = $this->manifestFor($key, \App\Enums\ExtensionType::App->value, $roles);
        $manifest['entry_url'] = '/ext/'.$key;

        return Extension::factory()
            ->fromBundled()
            ->app()
            ->installed(8600)
            ->unreachable($at)
            ->create(['key' => $key, 'name' => $name, 'manifest' => $manifest]);
    }

    #[Test]
    public function an_unreachable_tile_carries_the_unavailable_badge(): void
    {
        $this->unreachableApp('hello', 'Hello', ['prof']);
        $this->actingAs($this->makeUser('prof'));

        Livewire::test(self::COMPONENT)
            ->assertSeeHtml('data-testid="launcher-tile-hello"')
            ->assertSeeHtml('data-testid="launcher-tile-unavailable-hello"')
            ->assertSeeHtml('title="Indisponible actuellement"');
    }

    /**
     * ⚠️ FR14 — un badge n'est PAS une garde. L'état peut dater de 5 minutes, et
     * bloquer transformerait un AFFICHAGE en AUTORISATION : la tuile marquée
     * reste un `<a href>` qui pointe la cible provisionnée.
     */
    #[Test]
    public function an_unreachable_tile_stays_a_clickable_link_to_its_target(): void
    {
        $this->unreachableApp('hello', 'Hello', ['prof']);
        $this->actingAs($this->makeUser('prof'));

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertSame(
            1,
            preg_match('/<a\b[^>]*data-testid="launcher-tile-hello"[^>]*>/s', $html, $m),
            'la tuile indisponible doit rester une balise <a>',
        );
        // L'URL est résolue contre la racine de l'instance depuis le fix
        // « extensions bad url » de main (une instance servie sous un
        // sous-chemin perdait son préfixe). Ce que ce test verrouille est
        // inchangé : la tuile reste un lien vers sa cible, un badge n'est pas
        // une garde (FR14).
        self::assertStringContainsString('href="'.url('/ext/hello').'"', $m[0], 'la cible reste atteignable (FR14)');
    }

    #[Test]
    public function a_healthy_tile_carries_no_badge(): void
    {
        $manifest = $this->manifestFor('hello', \App\Enums\ExtensionType::App->value, ['prof']);
        $manifest['entry_url'] = '/ext/hello';

        Extension::factory()->fromBundled()->app()->installed(8600)->healthy()->create([
            'key' => 'hello',
            'name' => 'Hello',
            'manifest' => $manifest,
        ]);

        $this->actingAs($this->makeUser('prof'));

        Livewire::test(self::COMPONENT)
            ->assertSeeHtml('data-testid="launcher-tile-hello"')
            ->assertDontSeeHtml('data-testid="launcher-tile-unavailable-hello"');
    }

    /** Périmé ⇒ pas de badge : on ne signale que ce qu'on SAIT. */
    #[Test]
    public function a_stale_unreachable_state_shows_no_badge(): void
    {
        $this->unreachableApp('hello', 'Hello', ['prof'], now()->subHours(3));
        $this->actingAs($this->makeUser('prof'));

        Livewire::test(self::COMPONENT)
            ->assertSeeHtml('data-testid="launcher-tile-hello"')
            ->assertDontSeeHtml('data-testid="launcher-tile-unavailable-hello"');
    }

    /**
     * Le non-admin voit « Indisponible » et RIEN d'autre : ni catégorie
     * d'incident, ni port, ni horodatage. Un élève n'a pas à lire un diagnostic
     * système.
     */
    #[Test]
    public function a_non_admin_sees_no_technical_detail_on_a_marked_tile(): void
    {
        $extension = $this->unreachableApp('hello', 'Hello', ['eleve']);
        $extension->health_last_incident_detail = 'backend injoignable (connexion refusée ou expirée)';
        $extension->save();

        $this->actingAs($this->makeUser('eleve'));

        $html = Livewire::test(self::COMPONENT)->html();

        self::assertStringContainsString('data-testid="launcher-tile-unavailable-hello"', $html);
        self::assertStringNotContainsString('connexion refusée', $html);
        self::assertStringNotContainsString('8600', $html);
        self::assertStringNotContainsString('127.0.0.1', $html);
    }

    /**
     * NFR9 RENFORCÉ — la contrainte centrale de la story : l'état de santé est
     * LU dans la MÊME requête unique, et le rendu n'émet toujours AUCUNE requête
     * HTTP. C'est ce test qui interdit à quiconque de « juste sonder au rendu ».
     *
     * Test NOUVEAU plutôt que modification de celui de 54.3 : le socle reste
     * intact et cette version-ci est strictement plus forte (elle rend une tuile
     * BADGÉE, donc elle traverse le chemin de code qui aurait besoin de sonder).
     */
    #[Test]
    public function rendering_a_launcher_with_a_marked_tile_still_emits_one_query_and_zero_http(): void
    {
        $this->unreachableApp('hello', 'Hello', ['prof']);
        $this->integratedLink('doc', 'Documentation', ['prof']);

        $this->actingAs($this->makeUser('prof'));

        Http::preventStrayRequests();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $component = Livewire::test(self::COMPONENT);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // La preuve doit porter sur un rendu qui AFFICHE réellement le badge.
        $component->assertSeeHtml('data-testid="launcher-tile-unavailable-hello"');

        Http::assertNothingSent();

        // Needles QUOTÉS (même discipline que le test 54.3).
        $extensionsHits = array_filter(
            $log,
            static fn (array $q): bool => str_contains((string) $q['query'], '"extensions"'),
        );

        self::assertCount(
            1,
            $extensionsHits,
            'l\'état de santé est LU dans la requête unique — jamais mesuré au rendu (NFR9)',
        );
    }

    /**
     * La fenêtre `update.sh` : le code neuf est servi plusieurs minutes AVANT
     * `migrate --force`. Les colonnes `health_*` sont alors ABSENTES — et le
     * lanceur, rendu sur toute page authentifiée, doit continuer de fonctionner
     * (pas seulement de ne pas planter : afficher ses tuiles).
     *
     * C'est la raison pour laquelle `tilesFor()` fait un `SELECT *` et ne nomme
     * AUCUNE colonne : un `->select([...])` ici échouerait en SQL pendant toute
     * la fenêtre.
     */
    #[Test]
    public function the_launcher_survives_the_pre_migration_window_without_the_health_columns(): void
    {
        $this->integratedLink('doc', 'Documentation', ['prof']);
        $this->actingAs($this->makeUser('prof'));

        Schema::table('extensions', function ($table): void {
            $table->dropColumn([
                'health_status',
                'health_checked_at',
                'health_last_incident_at',
                'health_last_incident_detail',
            ]);
        });

        $html = Livewire::test(self::COMPONENT)
            ->assertSeeHtml('data-testid="launcher-tile-doc"')
            ->assertDontSeeHtml('data-testid="launcher-tile-unavailable-doc"')
            ->html();

        // Le message d'état vide reste MASQUÉ : les tuiles sont bien rendues,
        // ce n'est pas une dégradation silencieuse.
        self::assertStringContainsString('hidden', $this->emptyBlockTag($html));
    }
}
