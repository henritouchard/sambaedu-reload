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
 * Tests Feature Livewire — Filtres Audit « Quota dépassé » et « Mot de passe par défaut »
 * sur le listing /users.
 *
 * Story 14.4 — AC4, AC5, AC6, AC7, AC8, AC10, AC11, AC12
 */
class UsersIndexPageAuditFiltersTest extends TestCase
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

        // Désactiver la branche « Externe » pour simplifier les fixtures
        $seMock = Mockery::mock(SambaEduConfig::class);
        $seMock->shouldReceive('getCurrentEstablishmentCode')->andReturn(null);
        $this->app->instance(SambaEduConfig::class, $seMock);
    }

    protected function tearDown(): void
    {
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeUserOverSoftHome(string $login): User
    {
        return User::query()->create([
            'login' => $login,
            'firstname' => 'First',
            'lastname' => 'Last',
            'role' => 'eleve',
            'is_active' => true,
            'quota_snapshot' => [
                'home' => [
                    'used_kb' => 95000,
                    'soft_kb' => 100000,
                    'hard_kb' => 120000,
                    'used_mb' => 93,
                    'soft_mb' => 98,
                    'hard_mb' => 117,
                    'percent' => 95,
                    'is_over_soft' => true,
                    'is_over_hard' => false,
                    'grace_days' => null,
                ],
                'sambaedu' => [
                    'used_kb' => 100,
                    'soft_kb' => 100000,
                    'hard_kb' => 120000,
                    'used_mb' => 0,
                    'soft_mb' => 98,
                    'hard_mb' => 117,
                    'percent' => 0,
                    'is_over_soft' => false,
                    'is_over_hard' => false,
                    'grace_days' => null,
                ],
                'captured_at' => '2026-04-23T03:00:00+02:00',
            ],
        ]);
    }

    private function makeUserOverHardSambaedu(string $login): User
    {
        return User::query()->create([
            'login' => $login,
            'firstname' => 'First',
            'lastname' => 'Last',
            'role' => 'eleve',
            'is_active' => true,
            'quota_snapshot' => [
                'home' => [
                    'used_kb' => 100,
                    'soft_kb' => 100000,
                    'hard_kb' => 120000,
                    'used_mb' => 0,
                    'soft_mb' => 98,
                    'hard_mb' => 117,
                    'percent' => 0,
                    'is_over_soft' => false,
                    'is_over_hard' => false,
                    'grace_days' => null,
                ],
                'sambaedu' => [
                    'used_kb' => 115000,
                    'soft_kb' => 100000,
                    'hard_kb' => 120000,
                    'used_mb' => 112,
                    'soft_mb' => 98,
                    'hard_mb' => 117,
                    'percent' => 96,
                    'is_over_soft' => true,
                    'is_over_hard' => true,
                    'grace_days' => null,
                ],
                'captured_at' => '2026-04-23T03:00:00+02:00',
            ],
        ]);
    }

    private function makeUserNotOverQuota(string $login): User
    {
        return User::query()->create([
            'login' => $login,
            'firstname' => 'First',
            'lastname' => 'Last',
            'role' => 'eleve',
            'is_active' => true,
            'quota_snapshot' => [
                'home' => [
                    'used_kb' => 1000,
                    'soft_kb' => 100000,
                    'hard_kb' => 120000,
                    'used_mb' => 1,
                    'soft_mb' => 98,
                    'hard_mb' => 117,
                    'percent' => 1,
                    'is_over_soft' => false,
                    'is_over_hard' => false,
                    'grace_days' => null,
                ],
                'sambaedu' => [
                    'used_kb' => 1000,
                    'soft_kb' => 100000,
                    'hard_kb' => 120000,
                    'used_mb' => 1,
                    'soft_mb' => 98,
                    'hard_mb' => 117,
                    'percent' => 1,
                    'is_over_soft' => false,
                    'is_over_hard' => false,
                    'grace_days' => null,
                ],
                'captured_at' => '2026-04-23T03:00:00+02:00',
            ],
        ]);
    }

    private function makeUserNullSnapshot(string $login): User
    {
        return User::query()->create([
            'login' => $login,
            'firstname' => 'First',
            'lastname' => 'Last',
            'role' => 'eleve',
            'is_active' => true,
            'quota_snapshot' => null,
        ]);
    }

    // =========================================================================
    // AC10 — Filtre quota dépassé
    // =========================================================================

    /**
     * AC10 — Tâche 6.1 test 1 :
     * Un user avec is_over_soft=true sur home apparaît avec le filtre actif.
     */
    public function test_quota_overflow_filter_includes_over_soft_users(): void
    {
        $alice = $this->makeUserOverSoftHome('alice-over-soft');
        $carol = $this->makeUserNotOverQuota('carol-ok-quota');

        Livewire::test('pages::users.index')
            ->set('quotaOverflow', true)
            ->assertSee('alice-over-soft')
            ->assertDontSee('carol-ok-quota');
    }

    /**
     * AC10 — Tâche 6.1 test 2 :
     * Un user avec is_over_hard=true sur sambaedu apparaît avec le filtre actif.
     */
    public function test_quota_overflow_filter_includes_over_hard_sambaedu_users(): void
    {
        $bob = $this->makeUserOverHardSambaedu('bob-over-hard-sambaedu');
        $carol = $this->makeUserNotOverQuota('carol-ok-quota2');

        Livewire::test('pages::users.index')
            ->set('quotaOverflow', true)
            ->assertSee('bob-over-hard-sambaedu')
            ->assertDontSee('carol-ok-quota2');
    }

    /**
     * AC10 / D2 — Tâche 6.1 test 3 :
     * Un user avec quota_snapshot NULL est exclu du filtre quota (D2).
     */
    public function test_quota_overflow_filter_excludes_null_snapshot_users(): void
    {
        $dave = $this->makeUserNullSnapshot('dave-no-snapshot');

        Livewire::test('pages::users.index')
            ->set('quotaOverflow', true)
            ->assertDontSee('dave-no-snapshot');
    }

    // =========================================================================
    // AC11 — Filtre mot de passe par défaut
    // =========================================================================

    /**
     * AC11 / D3 — Tâche 6.1 test 4 :
     * Un user avec password_changed_at=NULL apparaît avec le filtre mdp actif.
     * Un user avec une date définie est exclu.
     */
    public function test_password_default_filter_includes_null_users(): void
    {
        $eve = User::query()->create([
            'login' => 'eve-mdp-null',
            'firstname' => 'Eve',
            'lastname' => 'Test',
            'role' => 'eleve',
            'is_active' => true,
            'password_changed_at' => null,
        ]);

        $frank = User::query()->create([
            'login' => 'frank-mdp-set',
            'firstname' => 'Frank',
            'lastname' => 'Test',
            'role' => 'eleve',
            'is_active' => true,
            'password_changed_at' => '2026-01-01 00:00:00',
        ]);

        $grace = User::query()->create([
            'login' => 'grace-mdp-old',
            'firstname' => 'Grace',
            'lastname' => 'Test',
            'role' => 'eleve',
            'is_active' => true,
            'password_changed_at' => '2025-06-15 12:00:00',
        ]);

        Livewire::test('pages::users.index')
            ->set('passwordDefault', true)
            ->assertSee('eve-mdp-null')
            ->assertDontSee('frank-mdp-set')
            ->assertDontSee('grace-mdp-old');
    }

    // =========================================================================
    // AC12 — Combinaison des deux filtres (AND strict — D8)
    // =========================================================================

    /**
     * AC12 / D8 — Tâche 6.1 test 5 :
     * Seul u1 (over-soft + password_changed_at NULL) apparaît quand les 2 filtres
     * sont actifs simultanément.
     */
    public function test_combined_audit_filters_apply_and_strict(): void
    {
        // u1 : over-soft home + password_changed_at = null → doit apparaître
        $u1 = User::query()->create([
            'login' => 'u1-both',
            'firstname' => 'U1',
            'lastname' => 'Both',
            'role' => 'eleve',
            'is_active' => true,
            'password_changed_at' => null,
            'quota_snapshot' => [
                'home' => [
                    'used_kb' => 95000,
                    'soft_kb' => 100000,
                    'hard_kb' => 120000,
                    'used_mb' => 93,
                    'soft_mb' => 98,
                    'hard_mb' => 117,
                    'percent' => 95,
                    'is_over_soft' => true,
                    'is_over_hard' => false,
                    'grace_days' => null,
                ],
                'sambaedu' => [
                    'used_kb' => 100,
                    'soft_kb' => 100000,
                    'hard_kb' => 120000,
                    'used_mb' => 0,
                    'soft_mb' => 98,
                    'hard_mb' => 117,
                    'percent' => 0,
                    'is_over_soft' => false,
                    'is_over_hard' => false,
                    'grace_days' => null,
                ],
                'captured_at' => '2026-04-23T03:00:00+02:00',
            ],
        ]);

        // u2 : over-soft home + password_changed_at défini → filtré par mdp
        $u2 = User::query()->create([
            'login' => 'u2-quota-only',
            'firstname' => 'U2',
            'lastname' => 'QuotaOnly',
            'role' => 'eleve',
            'is_active' => true,
            'password_changed_at' => '2026-01-01 00:00:00',
            'quota_snapshot' => [
                'home' => [
                    'used_kb' => 95000,
                    'soft_kb' => 100000,
                    'hard_kb' => 120000,
                    'used_mb' => 93,
                    'soft_mb' => 98,
                    'hard_mb' => 117,
                    'percent' => 95,
                    'is_over_soft' => true,
                    'is_over_hard' => false,
                    'grace_days' => null,
                ],
                'sambaedu' => [
                    'used_kb' => 100,
                    'soft_kb' => 100000,
                    'hard_kb' => 120000,
                    'used_mb' => 0,
                    'soft_mb' => 98,
                    'hard_mb' => 117,
                    'percent' => 0,
                    'is_over_soft' => false,
                    'is_over_hard' => false,
                    'grace_days' => null,
                ],
                'captured_at' => '2026-04-23T03:00:00+02:00',
            ],
        ]);

        // u3 : pas over-quota + password_changed_at NULL → filtré par quota
        $u3 = User::query()->create([
            'login' => 'u3-mdp-only',
            'firstname' => 'U3',
            'lastname' => 'MdpOnly',
            'role' => 'eleve',
            'is_active' => true,
            'password_changed_at' => null,
            'quota_snapshot' => [
                'home' => [
                    'used_kb' => 100,
                    'soft_kb' => 100000,
                    'hard_kb' => 120000,
                    'used_mb' => 0,
                    'soft_mb' => 98,
                    'hard_mb' => 117,
                    'percent' => 0,
                    'is_over_soft' => false,
                    'is_over_hard' => false,
                    'grace_days' => null,
                ],
                'sambaedu' => [
                    'used_kb' => 100,
                    'soft_kb' => 100000,
                    'hard_kb' => 120000,
                    'used_mb' => 0,
                    'soft_mb' => 98,
                    'hard_mb' => 117,
                    'percent' => 0,
                    'is_over_soft' => false,
                    'is_over_hard' => false,
                    'grace_days' => null,
                ],
                'captured_at' => '2026-04-23T03:00:00+02:00',
            ],
        ]);

        // u4 : pas over-quota + password_changed_at défini → filtré par les deux
        $u4 = User::query()->create([
            'login' => 'u4-neither',
            'firstname' => 'U4',
            'lastname' => 'Neither',
            'role' => 'eleve',
            'is_active' => true,
            'password_changed_at' => '2026-01-01 00:00:00',
            'quota_snapshot' => null,
        ]);

        Livewire::test('pages::users.index')
            ->set('quotaOverflow', true)
            ->set('passwordDefault', true)
            ->assertSee('u1-both')
            ->assertDontSee('u2-quota-only')
            ->assertDontSee('u3-mdp-only')
            ->assertDontSee('u4-neither');
    }

    // =========================================================================
    // AC8 — Reset filtres
    // =========================================================================

    /**
     * AC8 — Tâche 5.3 :
     * resetFilters() remet quotaOverflow et passwordDefault à false.
     */
    public function test_reset_filters_clears_audit_filters(): void
    {
        $this->makeUserOverSoftHome('alice-reset-test');

        Livewire::test('pages::users.index')
            ->set('quotaOverflow', true)
            ->set('passwordDefault', true)
            ->call('resetFilters')
            ->assertSet('quotaOverflow', false)
            ->assertSet('passwordDefault', false);
    }

    // =========================================================================
    // Post-review #3 — Reset selectedUsers lors d'un changement de filtre audit
    // (parité avec updatedRole/Status/Group existants).
    // =========================================================================

    /**
     * Post-review #3 :
     * Activer/désactiver le filtre quota doit reset selectedUsers pour éviter
     * d'agir en bulk sur des logins qui ne sont plus dans la liste filtrée.
     */
    public function test_it_resets_selected_users_when_quota_filter_changes(): void
    {
        $this->makeUserOverSoftHome('alice-quota-bulk');

        Livewire::test('pages::users.index')
            ->set('selectedUsers', ['alice-quota-bulk', 'bob-other'])
            ->set('quotaOverflow', true)
            ->assertSet('selectedUsers', []);
    }

    /**
     * Post-review #3 : idem pour le filtre mdp par défaut.
     */
    public function test_it_resets_selected_users_when_password_filter_changes(): void
    {
        User::query()->create([
            'login' => 'eve-mdp-bulk',
            'firstname' => 'Eve',
            'lastname' => 'Bulk',
            'role' => 'eleve',
            'is_active' => true,
            'password_changed_at' => null,
        ]);

        Livewire::test('pages::users.index')
            ->set('selectedUsers', ['eve-mdp-bulk', 'frank-other'])
            ->set('passwordDefault', true)
            ->assertSet('selectedUsers', []);
    }

    /**
     * Post-review #3 : retrait individuel d'un chip audit (remove*Filter) doit
     * aussi reset selectedUsers (parité avec changement par toggle).
     */
    public function test_it_resets_selected_users_when_removing_audit_filter(): void
    {
        $this->makeUserOverSoftHome('alice-remove-bulk');

        Livewire::test('pages::users.index')
            ->set('quotaOverflow', true)
            ->set('passwordDefault', true)
            ->set('selectedUsers', ['alice-remove-bulk'])
            ->call('removeQuotaOverflowFilter')
            ->assertSet('selectedUsers', [])
            ->set('selectedUsers', ['alice-remove-bulk'])
            ->call('removePasswordDefaultFilter')
            ->assertSet('selectedUsers', []);
    }

    // =========================================================================
    // AC7 — Combinaison avec filtres existants (AND strict)
    // =========================================================================

    /**
     * AC7 / D8 :
     * Combinaison filtre role + quotaOverflow (AND strict).
     * Seul le user prof avec quota dépassé apparaît.
     */
    public function test_combined_with_existing_role_filter_and_strict(): void
    {
        // Prof avec quota dépassé → doit apparaître
        $profOver = User::query()->create([
            'login' => 'prof-over-quota',
            'firstname' => 'Prof',
            'lastname' => 'Over',
            'role' => 'prof',
            'is_active' => true,
            'quota_snapshot' => [
                'home' => [
                    'used_kb' => 95000,
                    'soft_kb' => 100000,
                    'hard_kb' => 120000,
                    'used_mb' => 93,
                    'soft_mb' => 98,
                    'hard_mb' => 117,
                    'percent' => 95,
                    'is_over_soft' => true,
                    'is_over_hard' => false,
                    'grace_days' => null,
                ],
                'sambaedu' => [
                    'used_kb' => 100,
                    'soft_kb' => 100000,
                    'hard_kb' => 120000,
                    'used_mb' => 0,
                    'soft_mb' => 98,
                    'hard_mb' => 117,
                    'percent' => 0,
                    'is_over_soft' => false,
                    'is_over_hard' => false,
                    'grace_days' => null,
                ],
                'captured_at' => '2026-04-23T03:00:00+02:00',
            ],
        ]);

        // Elève avec quota dépassé → filtré par role=prof
        $eleveOver = $this->makeUserOverSoftHome('eleve-over-quota-combined');

        // Prof sans quota dépassé → filtré par quotaOverflow
        $profOk = $this->makeUserNotOverQuota('prof-ok-quota');
        $profOk->update(['role' => 'prof']);

        Livewire::test('pages::users.index')
            ->set('role', ['prof'])
            ->set('quotaOverflow', true)
            ->assertSee('prof-over-quota')
            ->assertDontSee('eleve-over-quota-combined')
            ->assertDontSee('prof-ok-quota');
    }
}
