<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ControlHubEnforcementState;
use App\Enums\ControlHubLinkState;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Models\Shortcut;
use App\Models\User;
use App\Models\WorkstationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Un raccourci que le contrat amont VERROUILLE ne doit être ni modifié, ni supprimé,
 * ni ré-assigné localement — la réception suivante défaisant le geste en silence.
 *
 * Ce que ces tests protègent : la SOURCE du verdict. Les gardes historiques lisaient
 * `is_global`, qui marque le canal de tâches, pas le contrat ; un raccourci imposé
 * porte `controlhub_contract_key` et `is_global` à false, et passait donc partout.
 * Chaque test vérifie l'ÉTAT PERSISTÉ après le geste, jamais l'affichage.
 *
 * Un item `permissive` reste surchargeable : c'est la contrepartie, testée ici aussi
 * pour qu'un durcissement du verrou ne fige pas ce qui doit rester ouvert.
 *
 * ⚠️ Tests sur HÔTE (php8.4 + pdo_sqlite) — JAMAIS sur la VM.
 */
class UpstreamLockedShortcutWriteGuardTest extends TestCase
{
    use RefreshDatabase;

    private const LIBRARY_COMPONENT = 'pages::parc-settings._partials.shortcuts-tab';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();

        $admin = User::query()->create(['login' => 'refnum', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($admin);
        Gate::before(fn (): bool => true);
    }

    /** Contrat actif imposant un raccourci `$key` dans l'état d'enforcement demandé. */
    private function imposedShortcut(
        string $key,
        ControlHubEnforcementState $state = ControlHubEnforcementState::Locked,
    ): Shortcut {
        $contract = ControlHubContract::factory()->create([
            'link_state' => ControlHubLinkState::Active,
        ]);

        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => Shortcut::TYPE_SHORTCUTS,
            'key' => $key,
            'enforcement_state' => $state,
        ]);

        return Shortcut::query()->create([
            'key' => $key,
            'name' => $key,
            'place' => Shortcut::PLACE_DESKTOP,
            'windows_link' => 'C:\\imposed.exe',
            // Le canal du contrat n'a JAMAIS posé is_global : c'est toute l'affaire.
            'is_global' => false,
            'controlhub_contract_key' => $key,
        ]);
    }

    #[Test]
    public function a_locked_shortcut_is_reported_as_locked_despite_is_global_being_false(): void
    {
        $shortcut = $this->imposedShortcut('libre-max');

        self::assertFalse($shortcut->is_global);
        self::assertTrue($shortcut->isUpstreamLocked());
    }

    #[Test]
    public function a_permissive_shortcut_stays_open_to_local_changes(): void
    {
        $shortcut = $this->imposedShortcut('souple', ControlHubEnforcementState::Permissive);

        self::assertFalse($shortcut->isUpstreamLocked());
    }

    #[Test]
    public function a_purely_local_shortcut_is_never_locked(): void
    {
        $this->imposedShortcut('imposé');

        $local = Shortcut::query()->create([
            'key' => 'local',
            'name' => 'Local',
            'place' => Shortcut::PLACE_DESKTOP,
            'is_global' => false,
        ]);

        self::assertFalse($local->isUpstreamLocked());
    }

    #[Test]
    public function without_an_active_contract_nothing_is_locked(): void
    {
        $orphan = Shortcut::query()->create([
            'key' => 'orphelin',
            'name' => 'Orphelin',
            'place' => Shortcut::PLACE_DESKTOP,
            'is_global' => false,
            // La clé subsiste alors que le contrat a disparu : SE5 redevient autonome.
            'controlhub_contract_key' => 'orphelin',
        ]);

        self::assertNull(ControlHubContract::active());
        self::assertFalse($orphan->isUpstreamLocked());
    }

    // ── Bibliothèque des raccourcis ──────────────────────────────────────────

    #[Test]
    public function the_library_refuses_to_delete_a_locked_shortcut(): void
    {
        $shortcut = $this->imposedShortcut('libre-max');

        Livewire::test(self::LIBRARY_COMPONENT)
            ->call('deleteShortcut', 'libre-max')
            ->assertDispatched('toastMagic', fn ($event, $params) => ($params['status'] ?? null) === 'error');

        self::assertNotNull(Shortcut::query()->find($shortcut->id));
    }

    #[Test]
    public function a_bulk_delete_spares_the_locked_shortcut_and_removes_the_others(): void
    {
        $locked = $this->imposedShortcut('libre-max');
        $local = Shortcut::query()->create([
            'key' => 'local',
            'name' => 'Local',
            'place' => Shortcut::PLACE_DESKTOP,
            'is_global' => false,
        ]);

        Livewire::test(self::LIBRARY_COMPONENT)
            ->set('selectedShortcuts', ['libre-max', 'local'])
            ->call('bulkDelete');

        self::assertNotNull(Shortcut::query()->find($locked->id), 'le raccourci imposé survit');
        self::assertNull(Shortcut::query()->find($local->id), 'le raccourci local est bien supprimé');
    }

    #[Test]
    public function a_bulk_assignment_skips_the_locked_shortcut(): void
    {
        $locked = $this->imposedShortcut('libre-max');
        $group = WorkstationGroup::factory()->create();

        Livewire::test(self::LIBRARY_COMPONENT)
            ->set('selectedShortcuts', ['libre-max'])
            ->call('onAssignmentsConfirmed', [$group->id], [], [], []);

        self::assertCount(0, $locked->refresh()->workstationGroups);
    }

    #[Test]
    public function the_library_marks_the_locked_shortcut_and_leaves_the_local_one_plain(): void
    {
        $this->imposedShortcut('libre-max');
        Shortcut::query()->create([
            'key' => 'local',
            'name' => 'Raccourci local',
            'place' => Shortcut::PLACE_DESKTOP,
            'is_global' => false,
        ]);

        $html = Livewire::test(self::LIBRARY_COMPONENT)->html();

        self::assertStringContainsString('Imposé', $html);
        self::assertStringContainsString("verrouillé par l'autorité amont", $html);
        self::assertStringNotContainsString('>Global', $html, 'le badge du canal historique ne doit pas apparaître');
    }

    // ── Fiche d'un raccourci ─────────────────────────────────────────────────

    #[Test]
    public function the_detail_page_refuses_to_save_a_locked_shortcut(): void
    {
        $shortcut = $this->imposedShortcut('libre-max');

        Livewire::test('pages::shortcuts.[id].index', ['id' => 'libre-max'])
            ->assertSet('isUpstreamLocked', true)
            ->set('name', 'renommé à la main')
            ->call('save')
            ->assertDispatched('toastMagic', fn ($event, $params) => ($params['status'] ?? null) === 'error');

        self::assertSame('libre-max', $shortcut->refresh()->name);
    }

    #[Test]
    public function the_detail_page_refuses_to_delete_a_locked_shortcut(): void
    {
        $shortcut = $this->imposedShortcut('libre-max');

        Livewire::test('pages::shortcuts.[id].index', ['id' => 'libre-max'])
            ->call('delete')
            ->assertNoRedirect();

        self::assertNotNull(Shortcut::query()->find($shortcut->id));
    }

    // ── Onglet raccourcis d'un parc ──────────────────────────────────────────

    #[Test]
    public function a_parc_cannot_detach_a_locked_shortcut(): void
    {
        $locked = $this->imposedShortcut('libre-max');
        $group = WorkstationGroup::factory()->create();
        $group->shortcuts()->attach($locked->id);

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('detachShortcut', $locked->id);

        self::assertCount(1, $group->refresh()->shortcuts, 'le contrat garde son raccourci sur le parc');
    }

    #[Test]
    public function a_parc_cannot_attach_a_locked_shortcut(): void
    {
        $locked = $this->imposedShortcut('libre-max');
        $group = WorkstationGroup::factory()->create();

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->set('selectedShortcutIdsToAdd', [$locked->id])
            ->call('attachShortcuts');

        self::assertCount(0, $group->refresh()->shortcuts);
    }
}
