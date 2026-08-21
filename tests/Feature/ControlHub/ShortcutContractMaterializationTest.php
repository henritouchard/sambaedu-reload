<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ControlHubEnforcementState;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Models\Shortcut;
use App\Services\ControlHub\ShortcutContractReconciler;
use App\Services\Shortcuts\ShortcutIconAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Matérialisation en bibliothèque des raccourcis imposés par le contrat amont.
 *
 * Ce que ces tests protègent en priorité : le PRUNE. Il supprime des lignes, et son
 * périmètre doit rester borné aux raccourcis que le contrat a lui-même créés — un
 * raccourci local ou posé par le canal de tâches historique n'est jamais candidat.
 *
 * ⚠️ Tests sur HÔTE (php8.4 + pdo_sqlite) — JAMAIS sur la VM.
 */
class ShortcutContractMaterializationTest extends TestCase
{
    use RefreshDatabase;

    private function reconciler(): ShortcutContractReconciler
    {
        return app(ShortcutContractReconciler::class);
    }

    /**
     * @param  array<string, mixed>|null  $spec
     */
    private function imposedShortcut(
        ControlHubContract $contract,
        string $key,
        ?array $spec = null,
        ?string $value = null,
        ?string $iconChecksum = null,
    ): ControlHubContractItem {
        return ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => Shortcut::TYPE_SHORTCUTS,
            'key' => $key,
            'value' => $value,
            'spec' => $spec,
            'artifact_checksum' => $iconChecksum,
        ]);
    }

    #[Test]
    public function materializes_an_imposed_shortcut_from_its_spec(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->imposedShortcut($contract, 'firefox', [
            'name' => 'Navigateur',
            'place' => Shortcut::PLACE_DESKTOP,
            'windows_link' => 'C:\\Program Files\\Mozilla Firefox\\firefox.exe',
            'windows_args' => '-private',
            'category' => 'Internet',
        ]);

        $result = $this->reconciler()->reconcile();

        $shortcut = Shortcut::where('controlhub_contract_key', 'firefox')->firstOrFail();
        self::assertSame('Navigateur', $shortcut->name);
        self::assertSame(Shortcut::PLACE_DESKTOP, $shortcut->place);
        self::assertSame('C:\\Program Files\\Mozilla Firefox\\firefox.exe', $shortcut->windows_link);
        self::assertSame('-private', $shortcut->windows_args);
        self::assertSame('Internet', $shortcut->category);
        self::assertTrue($shortcut->is_active);
        self::assertSame(1, $result->created);
    }

    #[Test]
    public function falls_back_to_the_item_value_when_the_spec_carries_no_target(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->imposedShortcut($contract, 'portail', value: 'https://portail.exemple.fr');

        $this->reconciler()->reconcile();

        $shortcut = Shortcut::where('controlhub_contract_key', 'portail')->firstOrFail();
        self::assertSame('https://portail.exemple.fr', $shortcut->windows_link);
        // Sans `spec.name`, la clé de l'item fait office de nom.
        self::assertSame('portail', $shortcut->name);
        self::assertSame(Shortcut::PLACE_DESKTOP, $shortcut->place);
    }

    #[Test]
    public function a_targetless_item_materializes_nothing(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->imposedShortcut($contract, 'sans-cible');

        $result = $this->reconciler()->reconcile();

        self::assertSame(1, $result->skipped);
        self::assertSame(0, $result->created);
        self::assertDatabaseCount('shortcuts', 0);
    }

    #[Test]
    public function a_second_identical_reception_writes_nothing(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->imposedShortcut($contract, 'firefox', ['windows_link' => 'firefox.exe']);

        $this->reconciler()->reconcile();
        $result = $this->reconciler()->reconcile();

        self::assertSame(0, $result->created);
        self::assertSame(0, $result->updated);
        self::assertSame(1, $result->unchanged);
        self::assertDatabaseCount('shortcuts', 1);
    }

    #[Test]
    public function realigns_a_shortcut_whose_contract_changed(): void
    {
        $contract = ControlHubContract::factory()->create();
        $item = $this->imposedShortcut($contract, 'firefox', ['windows_link' => 'firefox.exe']);

        $this->reconciler()->reconcile();

        $item->update(['spec' => ['windows_link' => 'firefox.exe', 'name' => 'Firefox ESR']]);
        $result = $this->reconciler()->reconcile();

        self::assertSame(1, $result->updated);
        self::assertSame('Firefox ESR', Shortcut::where('controlhub_contract_key', 'firefox')->firstOrFail()->name);
    }

    #[Test]
    public function removes_a_shortcut_the_contract_no_longer_imposes(): void
    {
        $contract = ControlHubContract::factory()->create();
        $item = $this->imposedShortcut($contract, 'firefox', ['windows_link' => 'firefox.exe']);

        $this->reconciler()->reconcile();
        self::assertDatabaseCount('shortcuts', 1);

        $item->delete();
        $result = $this->reconciler()->reconcile();

        self::assertSame(1, $result->removed);
        self::assertDatabaseCount('shortcuts', 0);
    }

    #[Test]
    public function an_absent_item_is_not_materialized(): void
    {
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => Shortcut::TYPE_SHORTCUTS,
            'key' => 'retire',
            'spec' => ['windows_link' => 'x.exe'],
            'enforcement_state' => ControlHubEnforcementState::Absent,
        ]);

        $this->reconciler()->reconcile();

        self::assertDatabaseCount('shortcuts', 0);
    }

    // ── Le prune ne déborde jamais sur ce qui ne vient pas du contrat ────────

    #[Test]
    public function never_prunes_a_local_or_legacy_channel_shortcut(): void
    {
        $local = Shortcut::create([
            'key' => 'raccourci-local',
            'name' => 'Local',
            'place' => Shortcut::PLACE_DESKTOP,
            'windows_link' => 'local.exe',
            'is_active' => true,
        ]);
        $legacy = Shortcut::create([
            'key' => 'raccourci-legacy',
            'name' => 'Legacy',
            'place' => Shortcut::PLACE_DESKTOP,
            'windows_link' => 'legacy.exe',
            'is_active' => true,
            'is_global' => true,
            'controlhub_id' => 'uuid-du-canal-de-taches',
        ]);

        // Contrat actif sans aucun item `shortcuts` : le désir d'état est « aucun
        // raccourci imposé », ce qui ne dit rien des raccourcis d'autres origines.
        ControlHubContract::factory()->create();
        $result = $this->reconciler()->reconcile();

        self::assertSame(0, $result->removed);
        self::assertModelExists($local);
        self::assertModelExists($legacy);
    }

    #[Test]
    public function does_not_steal_the_library_key_of_a_local_homonym(): void
    {
        $local = Shortcut::create([
            'key' => 'firefox',
            'name' => 'Firefox local',
            'place' => Shortcut::PLACE_DESKTOP,
            'windows_link' => 'local.exe',
            'is_active' => true,
        ]);

        $contract = ControlHubContract::factory()->create();
        $this->imposedShortcut($contract, 'firefox', ['windows_link' => 'impose.exe']);

        $this->reconciler()->reconcile();

        $imposed = Shortcut::where('controlhub_contract_key', 'firefox')->firstOrFail();
        self::assertSame('controlhub-firefox', $imposed->key);
        self::assertSame('local.exe', $local->refresh()->windows_link, 'Le raccourci local ne doit pas être écrasé.');
    }

    #[Test]
    public function is_a_total_noop_without_an_active_contract(): void
    {
        $result = $this->reconciler()->reconcile();

        self::assertSame([], $result->toArray()['errors']);
        self::assertSame(0, $result->created);
        self::assertSame(0, $result->removed);
    }

    // ── Icône ────────────────────────────────────────────────────────────────

    #[Test]
    public function adopts_an_icon_already_content_addressed_on_disk(): void
    {
        $checksum = str_repeat('a', 64);
        $dir = app(ShortcutIconAssetService::class)->servedDir();
        @mkdir($dir, 0o755, true);
        $path = $dir.DIRECTORY_SEPARATOR.$checksum.'.ico';
        file_put_contents($path, 'ico');

        try {
            $contract = ControlHubContract::factory()->create();
            $this->imposedShortcut($contract, 'firefox', ['windows_link' => 'firefox.exe'], iconChecksum: $checksum);

            $this->reconciler()->reconcile();

            $shortcut = Shortcut::where('controlhub_contract_key', 'firefox')->firstOrFail();
            self::assertSame($checksum.'.ico', $shortcut->icon_asset);
            self::assertSame($checksum, $shortcut->icon_checksum);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function leaves_the_icon_empty_until_the_pull_lands(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->imposedShortcut(
            $contract,
            'firefox',
            ['windows_link' => 'firefox.exe'],
            iconChecksum: str_repeat('b', 64),
        );

        $this->reconciler()->reconcile();

        $shortcut = Shortcut::where('controlhub_contract_key', 'firefox')->firstOrFail();
        self::assertNull($shortcut->icon_asset);
    }
}
