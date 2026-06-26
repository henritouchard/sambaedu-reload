<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Policies\WorkstationGroupPolicy;
use App\Services\PermissionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 29.1 — Tests de WorkstationGroupPolicy::assignWpkg (Gate scopé WPKG).
 *
 * Couvre :
 *  - AC #1 : délégué positif actif sur A autorisé sur A, refusé sur B ;
 *  - AC #2 : admin global `wpkg.assign` autorisé partout (+ scope null) ;
 *  - AC #3 : exclusion négative active prévaut même sur le droit global ;
 *  - AC #4 : délégation expirée → refus ;
 *  - groupe logique (is_physical=false) → fallback global UNIQUEMENT ;
 *  - enregistrement du Gate `assign-wpkg-workstationGroup` (RegistersGates).
 *
 * Piège SQLite (mémoire projet) : on teste des DÉCISIONS d'autorisation
 * (booléens), pas des bornes de colonnes ; l'expiration utilise une date
 * passée explicite (now()->subDay()), jamais une longueur.
 */
class WorkstationGroupPolicyWpkgTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;

    private WorkstationGroupPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        Queue::fake();
        WorkstationGroupObserver::disableSync();

        $this->createPermissionSchema();
        (new PermissionSeeder())->run();

        $this->policy = new WorkstationGroupPolicy();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function makeUser(string $login, array $perms = []): User
    {
        $user = User::create(['login' => $login, 'role' => 'autre', 'is_active' => true]);
        foreach ($perms as $p) {
            $user->givePermissionTo($p);
        }
        return $user;
    }

    private function makeGroup(string $name, bool $physical = true): WorkstationGroup
    {
        return WorkstationGroup::create([
            'name' => $name,
            'is_physical' => $physical,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function delegate_with_positive_grant_on_a_is_allowed_on_a_and_denied_on_b(): void
    {
        $delegate = $this->makeUser('delegate');
        $salleA = $this->makeGroup('salle_a');
        $salleB = $this->makeGroup('salle_b');

        app(PermissionService::class)->grantDelegation($delegate, 'wpkg.assign', $salleA);

        $this->assertTrue($this->policy->assignWpkg($delegate, $salleA), 'AC#1 : autorisé sur sa salle');
        $this->assertFalse($this->policy->assignWpkg($delegate, $salleB), 'AC#1 : refusé hors périmètre');
    }

    #[Test]
    public function global_admin_is_allowed_everywhere_including_null_scope(): void
    {
        $admin = $this->makeUser('admin', ['wpkg.assign']);
        $salleA = $this->makeGroup('salle_a');
        $salleB = $this->makeGroup('salle_b');

        $this->assertTrue($this->policy->assignWpkg($admin, $salleA), 'AC#2 : global → A');
        $this->assertTrue($this->policy->assignWpkg($admin, $salleB), 'AC#2 : global → B');
        $this->assertTrue($this->policy->assignWpkg($admin, null), 'AC#2 : global → scope null (poste nomade)');
    }

    #[Test]
    public function negative_exclusion_prevails_even_over_global_right(): void
    {
        $user = $this->makeUser('mixte', ['wpkg.assign']);
        $salleA = $this->makeGroup('salle_a');

        app(PermissionService::class)->negateDelegation($user, 'wpkg.assign', $salleA);

        $this->assertFalse(
            $this->policy->assignWpkg($user, $salleA),
            'AC#3 : exclusion négative active prévaut même sur le droit global'
        );
    }

    #[Test]
    public function expired_delegation_is_denied(): void
    {
        $delegate = $this->makeUser('delegate');
        $salleA = $this->makeGroup('salle_a');

        app(PermissionService::class)->grantDelegation(
            $delegate,
            'wpkg.assign',
            $salleA,
            null,
            now()->subDay()
        );

        $this->assertFalse(
            $this->policy->assignWpkg($delegate, $salleA),
            'AC#4 : la délégation expirée (scope ->active()) ne donne aucun droit'
        );
    }

    #[Test]
    public function user_without_anything_is_denied(): void
    {
        $lambda = $this->makeUser('lambda');
        $salleA = $this->makeGroup('salle_a');

        $this->assertFalse($this->policy->assignWpkg($lambda, $salleA));
        $this->assertFalse($this->policy->assignWpkg($lambda, null));
    }

    #[Test]
    public function logical_group_falls_back_to_global_only(): void
    {
        // Groupe logique (is_physical=false) : canCheckDelegation() refuse la
        // voie déléguée → seul le droit global compte.
        $logical = $this->makeGroup('groupe_logique', physical: false);

        $admin = $this->makeUser('admin', ['wpkg.assign']);
        $this->assertTrue($this->policy->assignWpkg($admin, $logical), 'global → autorisé sur groupe logique');

        $delegate = $this->makeUser('delegate');
        // Même une "délégation" posée sur ce groupe logique reste inopérante :
        // la voie déléguée n'est jamais empruntée pour un groupe non physique.
        app(PermissionService::class)->grantDelegation($delegate, 'wpkg.assign', $logical);
        $this->assertFalse(
            $this->policy->assignWpkg($delegate, $logical),
            'délégué sans droit global → refusé sur groupe logique (pas de délégation scopée)'
        );
    }

    #[Test]
    public function registered_gate_resolves_through_facade(): void
    {
        // Prouve que l'entrée $gates + RegistersGates (AuthServiceProvider) câble
        // bien le Gate nommé que les pages/partials et AppProfileService invoquent.
        $delegate = $this->makeUser('delegate');
        $admin = $this->makeUser('admin', ['wpkg.assign']);
        $salleA = $this->makeGroup('salle_a');
        $salleB = $this->makeGroup('salle_b');

        app(PermissionService::class)->grantDelegation($delegate, 'wpkg.assign', $salleA);

        $this->assertTrue(Gate::forUser($delegate)->allows('assign-wpkg-workstationGroup', $salleA));
        $this->assertFalse(Gate::forUser($delegate)->allows('assign-wpkg-workstationGroup', $salleB));
        $this->assertTrue(Gate::forUser($admin)->allows('assign-wpkg-workstationGroup', $salleB));
        $this->assertTrue(Gate::forUser($admin)->allows('assign-wpkg-workstationGroup', null));
    }
}
