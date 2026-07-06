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
 * Story 40.2 — Domaine Guide « Machines » (catégorie `computer`, AC6).
 *
 * Couvre :
 *  - accès de la page domaine (authentifié sans droit = 200 ; invité = redirect login) ;
 *  - non-masquage : la page liste TOUJOURS 5 fonctionnalités `computer` ;
 *  - gating GLOBAL par rôle : eleve 0/5, technicien 2/5, computer-admin 5/5
 *    (cadenas ciblés via data-testid) ;
 *  - hub : carte « Machines » cliquable (href vers la page domaine) + compteur
 *    « 2 / 5 accessibles » pour un technicien, sans régression de la carte
 *    « Utilisateurs » (40.1).
 *
 * Réutilise le pattern de seeding Spatie + forgetCachedPermissions de GuideTest.
 */
class GuideMachinesTest extends TestCase
{
    use RefreshDatabase;

    private const HUB = 'pages::guide.index';
    private const MACHINES = 'pages::guide.machines.index';

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

    /**
     * Compte les fonctionnalités `computer` rendues via leur data-testid.
     *
     * Attention au double-comptage : `data-testid="feature-lock-computer.…"`
     * contient bien `feature-` mais PAS `feature-computer.` — on cible donc le
     * préfixe exact `feature-computer.` (une occurrence par carte).
     */
    private function featureCount(string $html): int
    {
        return substr_count($html, 'data-testid="feature-computer.');
    }

    /** Compte les fonctionnalités `computer` verrouillées via leur data-testid dédié. */
    private function lockCount(string $html): int
    {
        return substr_count($html, 'data-testid="feature-lock-computer.');
    }

    // ========================================================================
    // Accès de la page domaine (AC6)
    // ========================================================================

    #[Test]
    public function machines_domain_is_accessible_to_an_authenticated_user_without_any_permission(): void
    {
        // Un élève n'a AUCUNE permission : la page doit tout de même s'afficher
        // (aucun guard bloquant dans mount()).
        $this->actingAs($this->makeUser('eleve-machines', 'eleve'));

        Livewire::test(self::MACHINES)->assertOk();
    }

    #[Test]
    public function machines_domain_redirects_a_guest_to_login(): void
    {
        // La page domaine est dans le groupe middleware sambaedu.auth : un invité
        // est redirigé vers la connexion.
        $this->get(route('app.guide.machines'))->assertRedirect(route('auth.login'));
    }

    // ========================================================================
    // Non-masquage : la page liste toujours 5 features `computer`
    // ========================================================================

    #[Test]
    public function machines_domain_lists_exactly_five_features_regardless_of_role(): void
    {
        foreach (['eleve', 'technicien', 'computer-admin'] as $role) {
            $this->actingAs($this->makeUser("count-{$role}", $role));

            $html = Livewire::test(self::MACHINES)->assertOk()->html();

            $this->assertSame(
                5,
                $this->featureCount($html),
                "Le domaine Machines doit lister 5 fonctionnalités pour le rôle {$role} (aucune masquée)."
            );
        }
    }

    // ========================================================================
    // Gating GLOBAL par rôle (AC5 / AC6)
    // ========================================================================

    #[Test]
    public function technicien_sees_two_unlocked_and_three_locked_features(): void
    {
        // technicien → computer.view + computer.control (2 déverrouillées,
        // 3 verrouillées : elevate, install, remote.rdp).
        $this->actingAs($this->makeUser('tech-gate', 'technicien'));

        $html = Livewire::test(self::MACHINES)->assertOk()->assertSee('Verrouillé')->html();

        $this->assertSame(3, $this->lockCount($html), 'un technicien doit voir 3 fonctionnalités verrouillées.');

        // Les deux permissions du technicien ne portent PAS de cadenas.
        $this->assertStringNotContainsString('data-testid="feature-lock-computer.view"', $html);
        $this->assertStringNotContainsString('data-testid="feature-lock-computer.control"', $html);
        // Les trois permissions qu'il n'a pas SONT verrouillées.
        $this->assertStringContainsString('data-testid="feature-lock-computer.elevate"', $html);
        $this->assertStringContainsString('data-testid="feature-lock-computer.install"', $html);
        $this->assertStringContainsString('data-testid="feature-lock-computer.remote.rdp"', $html);
    }

    #[Test]
    public function computer_admin_sees_all_five_features_unlocked(): void
    {
        // computer-admin → les 5 permissions `computer.*` (view, control, elevate,
        // install, remote.rdp) → 0 verrouillée.
        $this->actingAs($this->makeUser('cadmin-gate', 'computer-admin'));

        $html = Livewire::test(self::MACHINES)->assertOk()->html();

        $this->assertSame(0, $this->lockCount($html), 'un computer-admin doit voir 0 fonctionnalité verrouillée.');
        // Sanity : les 5 features sont bien rendues.
        $this->assertSame(5, $this->featureCount($html));
    }

    #[Test]
    public function eleve_sees_all_five_features_locked(): void
    {
        $this->actingAs($this->makeUser('eleve-gate', 'eleve'));

        $html = Livewire::test(self::MACHINES)->assertOk()->html();

        $this->assertSame(5, $this->lockCount($html), 'un élève (0 permission) doit voir 5 fonctionnalités verrouillées.');
    }

    // ========================================================================
    // Hub : carte « Machines » cliquable + non-régression « Utilisateurs »
    // ========================================================================

    #[Test]
    public function hub_machines_domain_is_clickable_for_a_technicien(): void
    {
        // technicien → 2 permissions du domaine Machines sur 5.
        $this->actingAs($this->makeUser('tech-hub', 'technicien'));

        Livewire::test(self::HUB)
            ->assertOk()
            // Carte Machines cliquable : porte un lien réel vers la page domaine
            // (prouve qu'elle n'est PLUS « Bientôt disponible »).
            ->assertSeeHtml('data-testid="domain-computer"')
            ->assertSeeHtml('href="' . route('app.guide.machines') . '"')
            ->assertSeeHtml('data-testid="domain-count-computer"')
            ->assertSee('2 / 5 accessibles')
            // Non-régression 40.1 : la carte Utilisateurs reste cliquable.
            ->assertSeeHtml('data-testid="domain-user"')
            ->assertSeeHtml('href="' . route('app.guide.utilisateurs') . '"');
    }
}
