<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ControlHubContractApplyStatus;
use App\Enums\ControlHubEnforcementState;
use App\Models\Application;
use App\Models\Capability;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Models\Shortcut;
use App\Models\Wallpaper;
use App\Models\WallpaperAsset;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\ControlHub\ContractAssignmentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pose des assignations réclamées par le contrat amont sur les parcs porteurs de label.
 *
 * L'invariant que ces tests protègent d'abord : le PRUNE ne touche QUE ce que le
 * contrat a lui-même posé. Une assignation faite à la main par l'administrateur doit
 * survivre à une réception qui ne la mentionne pas — sinon une diffusion de contrat
 * effacerait silencieusement du travail local.
 *
 * ⚠️ Tests sur HÔTE (php8.4 + pdo_sqlite) — JAMAIS sur la VM.
 */
class ContractAssignmentReconcilerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
    }

    private function reconciler(): ContractAssignmentReconciler
    {
        return app(ContractAssignmentReconciler::class);
    }

    private function labeledGroup(string $label, string $name = 'parc-cible'): WorkstationGroup
    {
        return WorkstationGroup::factory()->create([
            'name' => $name,
            'controlhub_label' => $label,
        ]);
    }

    /** La capacité est semée par migration : on la réutilise, on ne la recrée pas. */
    private function capability(string $key): Capability
    {
        $capability = Capability::query()->firstOrCreate(
            ['key' => $key],
            [
                'label' => $key,
                'value_type' => 'toggle',
                'default_value' => 'off',
                'is_active' => true,
                'applies_to_os' => 'windows',
            ],
        );

        $capability->forceFill(['default_value' => 'off'])->save();

        return $capability;
    }

    private function item(ControlHubContract $contract, string $type, string $key, array $attrs = []): ControlHubContractItem
    {
        return ControlHubContractItem::factory()->create(array_merge([
            'controlhub_contract_id' => $contract->id,
            'type' => $type,
            'key' => $key,
        ], $attrs));
    }

    // ── Applications ─────────────────────────────────────────────────────────

    #[Test]
    public function assigns_an_ordered_application_to_every_group_carrying_the_label(): void
    {
        $contract = ControlHubContract::factory()->create();
        $app = Application::create(['app_id' => 'ccleaner', 'name' => 'CCleaner']);
        $a = $this->labeledGroup('CDIX', 'parc-a');
        $b = $this->labeledGroup('CDIX', 'parc-b');
        $this->labeledGroup('AUTRE', 'parc-c');

        $this->item($contract, Application::TYPE_APPLICATIONS, 'ccleaner')
            ->update(['target_type' => 'label', 'target_label' => 'CDIX']);

        $result = $this->reconciler()->reconcile();

        self::assertSame(2, $result->attached);
        self::assertEqualsCanonicalizing(
            [$a->id, $b->id],
            DB::table('application_workstation_group')->pluck('workstation_group_id')->all(),
        );
    }

    #[Test]
    public function an_instance_wide_application_becomes_a_parc_default(): void
    {
        $contract = ControlHubContract::factory()->create();
        $app = Application::create(['app_id' => '7za', 'name' => '7-Zip', 'managed_by_control_hub' => true]);

        $this->item($contract, Application::TYPE_APPLICATIONS, '7za');

        $this->reconciler()->reconcile();

        self::assertTrue((bool) $app->refresh()->is_parc_default);
    }

    #[Test]
    public function an_application_missing_from_the_inventory_is_counted_not_swallowed(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->labeledGroup('CDIX');
        $this->item($contract, Application::TYPE_APPLICATIONS, 'jamais-vue')
            ->update(['target_type' => 'label', 'target_label' => 'CDIX']);

        $result = $this->reconciler()->reconcile();

        self::assertSame(1, $result->unresolved);
        self::assertSame(0, $result->attached);
    }

    #[Test]
    public function a_label_no_group_carries_is_counted_not_swallowed(): void
    {
        $contract = ControlHubContract::factory()->create();
        Application::create(['app_id' => 'ccleaner', 'name' => 'CCleaner']);
        $this->item($contract, Application::TYPE_APPLICATIONS, 'ccleaner')
            ->update(['target_type' => 'label', 'target_label' => 'PERSONNE']);

        $result = $this->reconciler()->reconcile();

        self::assertSame(1, $result->unresolved);
        self::assertDatabaseCount('application_workstation_group', 0);
    }

    // ── Capacités ────────────────────────────────────────────────────────────

    #[Test]
    public function assigns_an_imposed_capability_to_the_labeled_group(): void
    {
        $contract = ControlHubContract::factory()->create();
        $capability = $this->capability('onedrive_hidden');
        $group = $this->labeledGroup('CDIX');

        $this->item($contract, 'capabilities', 'onedrive_hidden', ['value' => 'on'])
            ->update(['target_type' => 'label', 'target_label' => 'CDIX']);

        $this->reconciler()->reconcile();

        self::assertDatabaseHas('capability_assignments', [
            'capability_id' => $capability->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $group->id,
            'value' => 'on',
            'managed_by_control_hub' => true,
        ]);
    }

    #[Test]
    public function an_instance_wide_capability_moves_the_broadcast_default(): void
    {
        $contract = ControlHubContract::factory()->create();
        $capability = $this->capability('onedrive_hidden');

        $this->item($contract, 'capabilities', 'onedrive_hidden', ['value' => 'on']);

        $this->reconciler()->reconcile();

        self::assertSame('on', $capability->refresh()->default_value);
    }

    // ── Fonds d'écran ────────────────────────────────────────────────────────

    #[Test]
    public function assigns_an_imposed_wallpaper_to_the_labeled_group(): void
    {
        $contract = ControlHubContract::factory()->create();
        $asset = WallpaperAsset::create([
            'filename' => str_repeat('c', 64).'.jpg',
            'checksum' => str_repeat('c', 64),
            'byte_size' => 100,
        ]);
        $group = $this->labeledGroup('CDIX');

        $this->item($contract, 'wallpapers', 'fond-cdix', ['artifact_checksum' => str_repeat('c', 64)])
            ->update(['target_type' => 'label', 'target_label' => 'CDIX']);

        $this->reconciler()->reconcile();

        self::assertDatabaseHas('wallpapers', [
            'owner_type' => WorkstationGroup::class,
            'owner_id' => $group->id,
            'type' => Wallpaper::TYPE_WALLPAPER,
            'asset_id' => $asset->id,
            'managed_by_control_hub' => true,
        ]);
    }

    #[Test]
    public function an_instance_wide_wallpaper_becomes_the_etab_default(): void
    {
        $contract = ControlHubContract::factory()->create();
        $asset = WallpaperAsset::create([
            'filename' => str_repeat('d', 64).'.jpg',
            'checksum' => str_repeat('d', 64),
            'byte_size' => 100,
        ]);

        $this->item($contract, 'wallpapers', 'fond-etab', ['artifact_checksum' => str_repeat('d', 64)]);

        $this->reconciler()->reconcile();

        self::assertDatabaseHas('wallpapers', [
            'owner_id' => null,
            'type' => Wallpaper::TYPE_WALLPAPER,
            'asset_id' => $asset->id,
            'is_default' => true,
        ]);
    }

    #[Test]
    public function a_wallpaper_whose_image_is_not_pulled_yet_is_counted_not_swallowed(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->labeledGroup('CDIX');
        $this->item($contract, 'wallpapers', 'pas-encore', ['artifact_checksum' => str_repeat('e', 64)])
            ->update(['target_type' => 'label', 'target_label' => 'CDIX']);

        $result = $this->reconciler()->reconcile();

        self::assertSame(1, $result->unresolved);
        self::assertDatabaseCount('wallpapers', 0);
    }

    // ── Le prune ne déborde jamais ───────────────────────────────────────────

    #[Test]
    public function never_removes_an_assignment_the_administrator_made(): void
    {
        $contract = ControlHubContract::factory()->create();
        $app = Application::create(['app_id' => 'locale', 'name' => 'App locale']);
        $group = $this->labeledGroup('CDIX');

        // Assignation POSÉE À LA MAIN : pas de marqueur d'origine amont.
        $group->applications()->attach($app->id);

        // Le contrat ne mentionne pas cette application.
        $result = $this->reconciler()->reconcile();

        self::assertSame(0, $result->detached);
        self::assertDatabaseHas('application_workstation_group', [
            'application_id' => $app->id,
            'workstation_group_id' => $group->id,
        ]);
    }

    #[Test]
    public function removes_its_own_assignment_when_the_contract_drops_it(): void
    {
        $contract = ControlHubContract::factory()->create();
        Application::create(['app_id' => 'ccleaner', 'name' => 'CCleaner']);
        $this->labeledGroup('CDIX');

        $item = $this->item($contract, Application::TYPE_APPLICATIONS, 'ccleaner');
        $item->update(['target_type' => 'label', 'target_label' => 'CDIX']);

        $this->reconciler()->reconcile();
        self::assertDatabaseCount('application_workstation_group', 1);

        $item->delete();
        $result = $this->reconciler()->reconcile();

        self::assertSame(1, $result->detached);
        self::assertDatabaseCount('application_workstation_group', 0);
    }

    #[Test]
    public function adopts_rather_than_duplicates_an_existing_manual_assignment(): void
    {
        $contract = ControlHubContract::factory()->create();
        $app = Application::create(['app_id' => 'ccleaner', 'name' => 'CCleaner']);
        $group = $this->labeledGroup('CDIX');
        $group->applications()->attach($app->id);

        $this->item($contract, Application::TYPE_APPLICATIONS, 'ccleaner')
            ->update(['target_type' => 'label', 'target_label' => 'CDIX']);

        $this->reconciler()->reconcile();

        self::assertDatabaseCount('application_workstation_group', 1);
        self::assertDatabaseHas('application_workstation_group', [
            'application_id' => $app->id,
            'workstation_group_id' => $group->id,
            'managed_by_control_hub' => true,
        ]);
    }

    #[Test]
    public function a_second_identical_reception_changes_nothing(): void
    {
        $contract = ControlHubContract::factory()->create();
        Application::create(['app_id' => 'ccleaner', 'name' => 'CCleaner']);
        $this->labeledGroup('CDIX');
        $this->item($contract, Application::TYPE_APPLICATIONS, 'ccleaner')
            ->update(['target_type' => 'label', 'target_label' => 'CDIX']);

        $this->reconciler()->reconcile();
        $second = $this->reconciler()->reconcile();

        self::assertSame(0, $second->attached);
        self::assertSame(0, $second->detached);
        self::assertDatabaseCount('application_workstation_group', 1);
    }

    #[Test]
    public function an_absent_item_assigns_nothing(): void
    {
        $contract = ControlHubContract::factory()->create();
        Application::create(['app_id' => 'ccleaner', 'name' => 'CCleaner']);
        $this->labeledGroup('CDIX');
        $this->item($contract, Application::TYPE_APPLICATIONS, 'ccleaner', [
            'enforcement_state' => ControlHubEnforcementState::Absent,
        ])->update(['target_type' => 'label', 'target_label' => 'CDIX']);

        $this->reconciler()->reconcile();

        self::assertDatabaseCount('application_workstation_group', 0);
    }

    #[Test]
    public function a_capability_already_at_the_right_value_is_not_re_posed(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->capability('onedrive_hidden');
        $this->labeledGroup('CDIX');
        $this->item($contract, 'capabilities', 'onedrive_hidden', ['value' => 'on'])
            ->update(['target_type' => 'label', 'target_label' => 'CDIX']);

        $this->reconciler()->reconcile();
        $second = $this->reconciler()->reconcile();

        self::assertSame(0, $second->attached, 'une passe sans changement ne pose rien');
        self::assertSame(0, $second->detached);
    }

    #[Test]
    public function a_capability_whose_imposed_value_changes_is_re_posed_once(): void
    {
        $contract = ControlHubContract::factory()->create();
        $capability = $this->capability('onedrive_hidden');
        $group = $this->labeledGroup('CDIX');
        $item = $this->item($contract, 'capabilities', 'onedrive_hidden', ['value' => 'on']);
        $item->update(['target_type' => 'label', 'target_label' => 'CDIX']);

        $this->reconciler()->reconcile();
        $item->update(['value' => 'off']);
        $second = $this->reconciler()->reconcile();

        self::assertSame(1, $second->attached);
        self::assertDatabaseHas('capability_assignments', [
            'capability_id' => $capability->id,
            'assignable_id' => $group->id,
            'value' => 'off',
        ]);
    }

    #[Test]
    public function a_permissive_capability_leaves_the_value_the_administrator_chose(): void
    {
        $contract = ControlHubContract::factory()->create();
        $capability = $this->capability('onedrive_hidden');
        $group = $this->labeledGroup('CDIX');
        $item = $this->item($contract, Capability::TYPE_CAPABILITIES, 'onedrive_hidden', [
            'value' => 'on',
            'enforcement_state' => 'permissive',
        ]);
        $item->update(['target_type' => 'label', 'target_label' => 'CDIX']);

        $this->reconciler()->reconcile();

        // L'administrateur surcharge et reprend la ligne à son compte — c'est ce que
        // fait l'onglet « Options / Capacités » du parc.
        DB::table('capability_assignments')
            ->where('capability_id', $capability->id)
            ->where('assignable_id', $group->id)
            ->update(['value' => 'off', 'managed_by_control_hub' => false]);

        $second = $this->reconciler()->reconcile();

        self::assertSame(0, $second->attached, 'un item permissif ne repose rien sur une ligne reprise');
        self::assertSame(0, $second->detached, 'et ne la retire pas non plus');
        self::assertDatabaseHas('capability_assignments', [
            'capability_id' => $capability->id,
            'assignable_id' => $group->id,
            'value' => 'off',
            'managed_by_control_hub' => false,
        ]);
    }

    #[Test]
    public function a_contract_hardening_to_locked_takes_back_an_overridden_capability(): void
    {
        $contract = ControlHubContract::factory()->create();
        $capability = $this->capability('onedrive_hidden');
        $group = $this->labeledGroup('CDIX');
        $item = $this->item($contract, Capability::TYPE_CAPABILITIES, 'onedrive_hidden', [
            'value' => 'on',
            'enforcement_state' => 'permissive',
        ]);
        $item->update(['target_type' => 'label', 'target_label' => 'CDIX']);

        $this->reconciler()->reconcile();
        DB::table('capability_assignments')
            ->where('capability_id', $capability->id)
            ->where('assignable_id', $group->id)
            ->update(['value' => 'off', 'managed_by_control_hub' => false]);

        $item->update(['enforcement_state' => 'locked']);
        $second = $this->reconciler()->reconcile();

        self::assertSame(1, $second->attached);
        self::assertDatabaseHas('capability_assignments', [
            'capability_id' => $capability->id,
            'assignable_id' => $group->id,
            'value' => 'on',
            'managed_by_control_hub' => true,
        ]);
    }

    // ── Le verdict rendu au canal ③ ──────────────────────────────────────────

    #[Test]
    public function stamps_applied_on_an_item_it_could_place(): void
    {
        $contract = ControlHubContract::factory()->create();
        Application::create(['app_id' => 'ccleaner', 'name' => 'CCleaner']);
        $this->labeledGroup('CDIX');
        $item = $this->item($contract, Application::TYPE_APPLICATIONS, 'ccleaner');
        $item->update(['target_type' => 'label', 'target_label' => 'CDIX']);

        $this->reconciler()->reconcile();

        self::assertSame(ControlHubContractApplyStatus::Applied, $item->refresh()->apply_status);
        self::assertNull($item->apply_detail);
    }

    #[Test]
    public function stamps_pending_when_no_group_carries_the_label(): void
    {
        $contract = ControlHubContract::factory()->create();
        Application::create(['app_id' => 'ccleaner', 'name' => 'CCleaner']);
        $item = $this->item($contract, Application::TYPE_APPLICATIONS, 'ccleaner');
        $item->update(['target_type' => 'label', 'target_label' => 'PERSONNE']);

        $this->reconciler()->reconcile();

        $item->refresh();
        self::assertSame(ControlHubContractApplyStatus::Pending, $item->apply_status);
        self::assertStringContainsString('PERSONNE', (string) $item->apply_detail);
    }

    #[Test]
    public function stamps_pending_on_an_application_missing_from_the_inventory(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->labeledGroup('CDIX');
        $item = $this->item($contract, Application::TYPE_APPLICATIONS, 'jamais-vue');
        $item->update(['target_type' => 'label', 'target_label' => 'CDIX']);

        $this->reconciler()->reconcile();

        self::assertSame(ControlHubContractApplyStatus::Pending, $item->refresh()->apply_status);
    }

    #[Test]
    public function stamps_error_on_a_capability_se5_does_not_know(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->labeledGroup('CDIX');
        $item = $this->item($contract, 'capabilities', 'capacite-inventee', ['value' => 'on']);
        $item->update(['target_type' => 'label', 'target_label' => 'CDIX']);

        $this->reconciler()->reconcile();

        $item->refresh();
        self::assertSame(ControlHubContractApplyStatus::Error, $item->apply_status);
        self::assertStringContainsString('capacite-inventee', (string) $item->apply_detail);
    }

    #[Test]
    public function stamps_error_on_a_wallpaper_the_contract_never_described(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->labeledGroup('CDIX');
        $item = $this->item($contract, 'wallpapers', 'fond-sans-image', ['artifact_checksum' => null]);
        $item->update(['target_type' => 'label', 'target_label' => 'CDIX']);

        $this->reconciler()->reconcile();

        $item->refresh();
        self::assertSame(ControlHubContractApplyStatus::Error, $item->apply_status);
        self::assertStringContainsString('artifact', (string) $item->apply_detail);
    }

    #[Test]
    public function stamps_pending_on_a_wallpaper_whose_image_has_not_landed_yet(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->labeledGroup('CDIX');
        $item = $this->item($contract, 'wallpapers', 'fond-en-vol', ['artifact_checksum' => str_repeat('f', 64)]);
        $item->update(['target_type' => 'label', 'target_label' => 'CDIX']);

        $this->reconciler()->reconcile();

        self::assertSame(ControlHubContractApplyStatus::Pending, $item->refresh()->apply_status);
    }

    #[Test]
    public function stamps_error_and_names_the_gap_on_a_shortcut_without_a_target(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->labeledGroup('CDIX');
        $item = $this->item($contract, Shortcut::TYPE_SHORTCUTS, 'racc-nu', ['value' => null]);
        $item->update(['target_type' => 'label', 'target_label' => 'CDIX']);

        $this->reconciler()->reconcile();

        $item->refresh();
        self::assertSame(ControlHubContractApplyStatus::Error, $item->apply_status);
        self::assertStringContainsString('sans cible', (string) $item->apply_detail);
    }

    #[Test]
    public function leaves_an_unclaimed_type_unstamped(): void
    {
        $contract = ControlHubContract::factory()->create();
        $item = $this->item($contract, 'agent_tools', 'outil');

        $this->reconciler()->reconcile();

        self::assertNull($item->refresh()->apply_status);
    }

    #[Test]
    public function a_verdict_is_revised_when_the_local_state_catches_up(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->labeledGroup('CDIX');
        $item = $this->item($contract, Application::TYPE_APPLICATIONS, 'ccleaner');
        $item->update(['target_type' => 'label', 'target_label' => 'CDIX']);

        $this->reconciler()->reconcile();
        self::assertSame(ControlHubContractApplyStatus::Pending, $item->refresh()->apply_status);

        Application::create(['app_id' => 'ccleaner', 'name' => 'CCleaner']);
        $this->reconciler()->reconcile();

        $item->refresh();
        self::assertSame(ControlHubContractApplyStatus::Applied, $item->apply_status);
        self::assertNull($item->apply_detail);
    }

    #[Test]
    public function is_a_total_noop_without_an_active_contract(): void
    {
        $result = $this->reconciler()->reconcile();

        self::assertSame(0, $result->attached);
        self::assertSame(0, $result->detached);
        self::assertSame([], $result->errors);
    }
}
