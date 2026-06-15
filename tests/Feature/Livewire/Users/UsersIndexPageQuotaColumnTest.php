<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Users;

use App\Config\SambaEduConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Tests Feature Livewire de la colonne Utilisation sur le listing /users
 * (story 5.1b — AC 9 cas 1-3).
 *
 * Couvre les 3 seuils de coloration :
 *   1. percent < 70 → badge-success (vert)
 *   2. 70 <= percent < 90 → badge-warning (orange)
 *   3. percent >= 90 ou is_over_soft → badge-error (rouge)
 */
class UsersIndexPageQuotaColumnTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;

    protected function setUp(): void
    {
        parent::setUp();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        // Le trait crée le schéma users + user_groups + workstation_groups +
        // les tables Spatie (roles, permissions, …) nécessaires au rendu du
        // <livewire:pages::users._partials.rights-drawer /> dont le mount()
        // interroge Role::where('guard_name', 'web').
        $this->createPermissionSchema();

        // Mock SEConfig — retourne null (pas de code établissement) pour
        // désactiver la branche "Externe" qui complexifierait le test.
        $seMock = Mockery::mock(SambaEduConfig::class);
        $seMock->shouldReceive('getCurrentEstablishmentCode')->andReturn(null);
        $this->app->instance(SambaEduConfig::class, $seMock);
    }

    protected function tearDown(): void
    {
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function makeUserWithPercent(string $login, ?int $percent, bool $overSoft = false): User
    {
        $snapshot = $percent === null ? null : [
            'home' => [
                'used_kb' => 10000,
                'soft_kb' => 100000,
                'hard_kb' => 120000,
                'used_mb' => 10,
                'soft_mb' => 98,
                'hard_mb' => 117,
                'percent' => $percent,
                'is_over_soft' => $overSoft,
                'is_over_hard' => false,
                'grace_days' => null,
            ],
            'captured_at' => '2026-04-23T03:00:00+02:00',
        ];

        return User::query()->create([
            'login' => $login,
            'firstname' => 'First',
            'lastname' => 'Last',
            'role' => 'eleve',
            'is_active' => true,
            'quota_snapshot' => $snapshot,
        ]);
    }

    public function test_it_shows_quota_percent_badge_success_below_70(): void
    {
        $this->makeUserWithPercent('alice-low', 40);

        Livewire::test('pages::users.index')
            ->assertSee('40%')
            ->assertSeeHtml('badge-success');
    }

    public function test_it_shows_badge_warning_between_70_and_90(): void
    {
        $this->makeUserWithPercent('bob-mid', 85);

        Livewire::test('pages::users.index')
            ->assertSee('85%')
            ->assertSeeHtml('badge-warning');
    }

    public function test_it_shows_badge_error_above_90(): void
    {
        $this->makeUserWithPercent('carol-high', 95);

        Livewire::test('pages::users.index')
            ->assertSee('95%')
            ->assertSeeHtml('badge-error');
    }

    public function test_it_shows_dash_for_user_without_snapshot(): void
    {
        $this->makeUserWithPercent('no-snap', null);

        Livewire::test('pages::users.index')
            ->assertSee('no-snap')
            // Marqueur explicite "aucun snapshot" rendu dans la cellule Utilisation
            // (les classes badge-warning/error apparaissent ailleurs sur la page —
            // modale de délégation, badges "Externe"/"Inactif" — donc pas d'assert global).
            ->assertSeeHtml('title="Aucun snapshot disponible"');
    }

    /**
     * Story 26.3 — AC #2 : pastille « profil itinérant volumineux » au-delà du
     * seuil. La valeur provient EXCLUSIVEMENT du cache (colonne profile_snapshot).
     */
    public function test_it_shows_large_profile_badge_above_threshold(): void
    {
        $user = User::query()->create([
            'login' => 'big-profile',
            'firstname' => 'Big',
            'lastname' => 'Profile',
            'role' => 'eleve',
            'is_active' => true,
            'profile_snapshot' => [
                'size_bytes' => 314572800,
                'size_mb' => 300.0, // > seuil 200 Mo
                'dir' => 'big-profile.V6',
                'captured_at' => '2026-06-15T04:30:00+02:00',
            ],
        ]);

        Livewire::test('pages::users.index')
            ->assertSee('big-profile')
            ->assertSeeHtml('Profil itinérant volumineux')
            ->assertSee('300 Mo');
    }

    public function test_it_hides_large_profile_badge_below_threshold(): void
    {
        User::query()->create([
            'login' => 'small-profile',
            'firstname' => 'Small',
            'lastname' => 'Profile',
            'role' => 'eleve',
            'is_active' => true,
            'profile_snapshot' => [
                'size_bytes' => 52428800,
                'size_mb' => 50.0, // < seuil 200 Mo
                'dir' => 'small-profile.V1',
                'captured_at' => '2026-06-15T04:30:00+02:00',
            ],
        ]);

        Livewire::test('pages::users.index')
            ->assertSee('small-profile')
            ->assertDontSeeHtml('Profil itinérant volumineux');
    }

    /**
     * AC #2 : un user SANS entrée de cache (`profile_snapshot = null`) n'affiche
     * AUCUN badge profil (ni erreur). Verrouille le chemin NULL explicitement
     * (review 26.3 #7) — distinct du cas « sous le seuil ».
     */
    public function test_it_shows_no_profile_badge_when_snapshot_null(): void
    {
        User::query()->create([
            'login' => 'no-profile-snap',
            'firstname' => 'No',
            'lastname' => 'Snap',
            'role' => 'eleve',
            'is_active' => true,
            'profile_snapshot' => null,
        ]);

        Livewire::test('pages::users.index')
            ->assertSee('no-profile-snap')
            ->assertDontSeeHtml('Profil itinérant volumineux');
    }
}
