<?php

declare(strict_types=1);

namespace Tests\Feature\Guide;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Story 40.1 — Guide des fonctionnalités (AC8).
 *
 * Couvre :
 *  - accès du hub (authentifié sans droit = 200 ; invité = redirect login) ;
 *  - non-masquage : le domaine « Utilisateurs » liste TOUJOURS 6 fonctionnalités ;
 *  - gating par rôle : eleve 0/6, prof 2/6, super-admin 6/6 (badge « Verrouillé »
 *    présent, ciblé via data-testid) ;
 *  - compteur cohérent par domaine sur le hub (prof → Utilisateurs « 2 / 6 »).
 */
class GuideTest extends TestCase
{
    use RefreshDatabase;

    private const HUB = 'pages::guide.index';
    private const USERS = 'pages::guide.utilisateurs.index';

    protected function setUp(): void
    {
        parent::setUp();

        // Seed idempotent des permissions + rôles Spatie (source = enums).
        (new PermissionSeeder())->run();
        $this->forgetPermissionCache();
    }

    private function forgetPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeUser(string $login, ?string $role = null): User
    {
        $user = User::create([
            'login' => $login . '-' . uniqid(),
            'role' => $role ?? 'autre',
            'is_active' => true,
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        // Après toute assignation, on vide le cache Spatie pour que `can()`
        // reflète immédiatement les permissions dans le même process de test.
        $this->forgetPermissionCache();

        return $user;
    }

    // ========================================================================
    // Accès du hub (AC1 / AC8)
    // ========================================================================

    #[Test]
    public function hub_is_accessible_to_an_authenticated_user_without_any_permission(): void
    {
        // Un élève n'a AUCUNE permission : le hub doit tout de même s'afficher
        // (aucun guard bloquant dans mount()).
        $this->actingAs($this->makeUser('eleve-hub', 'eleve'));

        Livewire::test(self::HUB)->assertOk();
    }

    #[Test]
    public function hub_redirects_a_guest_to_login(): void
    {
        // Invité non authentifié → le middleware sambaedu.auth redirige.
        $this->get(route('app.guide'))->assertRedirect(route('auth.login'));
    }

    #[Test]
    public function users_domain_redirects_a_guest_to_login(): void
    {
        // La page domaine est dans le même groupe middleware (sambaedu.auth) :
        // couverture de régression si elle en sortait par erreur.
        $this->get(route('app.guide.utilisateurs'))->assertRedirect(route('auth.login'));
    }

    // ========================================================================
    // Non-masquage : le domaine « Utilisateurs » liste toujours 6 features
    // ========================================================================

    #[Test]
    public function users_domain_lists_exactly_six_features_regardless_of_role(): void
    {
        foreach (['eleve', 'prof', 'super-admin'] as $role) {
            $this->actingAs($this->makeUser("count-{$role}", $role));

            $html = Livewire::test(self::USERS)->assertOk()->html();

            // Un `data-testid="feature-user.*"` par fonctionnalité rendue.
            $this->assertSame(
                6,
                substr_count($html, 'data-testid="feature-user.'),
                "Le domaine Utilisateurs doit lister 6 fonctionnalités pour le rôle {$role} (aucune masquée)."
            );
        }
    }

    // ========================================================================
    // Gating par rôle (AC5 / AC8)
    // ========================================================================

    #[Test]
    public function prof_sees_two_unlocked_and_four_locked_features(): void
    {
        // prof → user.read + user.password.init (2 déverrouillées, 4 verrouillées).
        $this->actingAs($this->makeUser('prof-gate', 'prof'));

        $html = Livewire::test(self::USERS)->assertOk()->assertSee('Verrouillé')->html();

        $this->assertSame(4, $this->lockCount($html), 'prof doit voir 4 fonctionnalités verrouillées.');

        // Les deux permissions du prof ne portent PAS de cadenas.
        $this->assertStringNotContainsString('data-testid="feature-lock-user.read"', $html);
        $this->assertStringNotContainsString('data-testid="feature-lock-user.password.init"', $html);
        // Une permission qu'il n'a pas EST verrouillée.
        $this->assertStringContainsString('data-testid="feature-lock-user.modify"', $html);
    }

    #[Test]
    public function eleve_sees_all_six_features_locked(): void
    {
        $this->actingAs($this->makeUser('eleve-gate', 'eleve'));

        $html = Livewire::test(self::USERS)->assertOk()->html();

        $this->assertSame(6, $this->lockCount($html), 'un élève (0 permission) doit voir 6 fonctionnalités verrouillées.');
    }

    #[Test]
    public function super_admin_sees_all_six_features_unlocked(): void
    {
        $this->actingAs($this->makeUser('sa-gate', 'super-admin'));

        $html = Livewire::test(self::USERS)->assertOk()->html();

        $this->assertSame(0, $this->lockCount($html), 'un super-admin doit voir 0 fonctionnalité verrouillée.');
        // Sanity : les 6 features sont bien rendues.
        $this->assertSame(6, substr_count($html, 'data-testid="feature-user.'));
    }

    // ========================================================================
    // Compteur du hub par domaine (AC3 / AC8)
    // ========================================================================

    #[Test]
    public function hub_shows_a_coherent_counter_for_the_users_domain(): void
    {
        // prof → 2 permissions du domaine Utilisateurs sur 6.
        $this->actingAs($this->makeUser('prof-hub', 'prof'));

        Livewire::test(self::HUB)
            ->assertOk()
            ->assertSeeHtml('data-testid="domain-count-user"')
            ->assertSee('2 / 6 accessibles');
    }

    #[Test]
    public function hub_users_domain_is_clickable_and_others_are_coming_soon(): void
    {
        $this->actingAs($this->makeUser('sa-hub', 'super-admin'));

        Livewire::test(self::HUB)
            ->assertOk()
            // Domaine pilote cliquable : la carte `user` DOIT porter un lien réel
            // vers la page domaine (prouve qu'elle n'est pas « Bientôt disponible »
            // — le compteur seul ne le distingue pas des cartes grisées).
            ->assertSeeHtml('data-testid="domain-user"')
            ->assertSeeHtml('href="' . route('app.guide.utilisateurs') . '"')
            ->assertSee('6 / 6 accessibles')
            // Domaines non documentés présents mais « Bientôt disponible ».
            ->assertSeeHtml('data-testid="domain-computer"')
            ->assertSee('Bientôt disponible');
    }

    /** Compte les fonctionnalités verrouillées via leur data-testid dédié. */
    private function lockCount(string $html): int
    {
        return substr_count($html, 'data-testid="feature-lock-user.');
    }
}
