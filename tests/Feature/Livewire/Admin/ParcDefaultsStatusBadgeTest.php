<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Enums\ControlHubLinkState;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tri-état (verrouillé/permissif/local) sur
 * l'onglet « Registre / capacités » de /admin/settings/parc-defaults.
 *
 * Couvre :
 *   - Badge « Verrouillé » + bouton « Éditer le défaut » masqué + toggle désactivé
 *  (locked — non-régression) ;
 *   - Badge « Modifiable » + bouton « Éditer le défaut » actif + note (permissive) ;
 *   - Badge « Local » sans contrainte amont — contrat actif ;
 *   - Standalone (aucun contrat) → AUCUN badge ;
 *   - Contrat severed → AUCUN badge, même traitement que standalone.
 *
 * Le Gate `server.admin` est autorisé via un `Gate::before` CIBLÉ (null pour les
 * autres abilities) afin que `modify-capability` soit évalué réellement.
 */
class ParcDefaultsStatusBadgeTest extends TestCase
{
    use RefreshDatabase;

    private const REGISTRY_TAB = 'pages::admin.settings.parc-defaults._partials.registry-tab';

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        $this->withoutVite();
        WorkstationGroupObserver::disableSync();

        DB::table('capability_assignments')->delete();
        DB::table('capability_projections')->delete();
        DB::table('capabilities')->delete();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        Mockery::close();
        parent::tearDown();
    }

    private function actAsAdmin(): void
    {
        $user = Mockery::mock(
            \Illuminate\Contracts\Auth\Authenticatable::class,
            \Illuminate\Contracts\Auth\Access\Authorizable::class,
        );
        $user->shouldReceive('can')->with('app.customize')->andReturn(true);
        $user->shouldReceive('can')->andReturn(true);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $user->shouldReceive('getAuthIdentifierName')->andReturn('id');
        $user->shouldReceive('getAuthPassword')->andReturn('');
        $user->shouldReceive('getRememberToken')->andReturn('');
        $user->shouldReceive('setRememberToken');
        $user->shouldReceive('getRememberTokenName')->andReturn('');
        $this->actingAs($user);
        Gate::before(fn ($u, string $ability) => $ability === 'server.admin' ? true : null);
    }

    private function capabilityWithKey(string $key, string $hive, string $path, string $name): Capability
    {
        $cap = Capability::factory()->create([
            'key' => $key,
            'label' => ucfirst(str_replace('_', ' ', $key)),
            'default_value' => 'on',
        ]);
        CapabilityProjection::factory()->for($cap)->keys([
            ['hive' => $hive, 'path' => $path, 'name' => $name, 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
        ])->create();

        return $cap;
    }

    private function lockUpstream(string $hive, string $path, string $name): void
    {
        ControlHubContractItem::factory()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => "{$hive}|{$path}|{$name}|REG_DWORD",
        ]);
    }

    private function permissiveUpstream(string $hive, string $path, string $name): void
    {
        ControlHubContractItem::factory()->permissive()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => "{$hive}|{$path}|{$name}|REG_DWORD",
        ]);
    }

    #[Test]
    public function locked_capability_renders_locked_badge_and_hides_edit_button(): void
    {
        $this->actAsAdmin();
        $cap = $this->capabilityWithKey('remote_desktop', 'HKCU', 'Software\\RD', 'Enabled');
        $this->lockUpstream('HKCU', 'Software\\RD', 'Enabled');

        $component = Livewire::test(self::REGISTRY_TAB);

        // Badge verrouillé (non-régression).
        $component->assertSeeHtml('data-testid="upstream-locked-'.$cap->id.'"');
        // assertSeeHtml contextuel : évite les faux positifs sur « Verrouillé » ailleurs.
        $component->assertSeeHtml('</i> Verrouillé');
        // Bouton masqué, « Imposé par contrat amont » affiché.
        $component->assertSeeHtml('Imposé par contrat amont');
        $component->assertDontSeeHtml('data-testid="edit-default-'.$cap->id.'"');
        // Pas de badge permissif ni local.
        $component->assertDontSeeHtml('data-testid="upstream-permissive-'.$cap->id.'"');
        $component->assertDontSeeHtml('data-testid="upstream-local-'.$cap->id.'"');
    }

    #[Test]
    public function permissive_capability_renders_modifiable_badge_and_keeps_edit_button(): void
    {
        $this->actAsAdmin();
        $cap = $this->capabilityWithKey('show_hidden_files', 'HKCU', 'Software\\SHF', 'Hidden');
        $this->permissiveUpstream('HKCU', 'Software\\SHF', 'Hidden');

        $component = Livewire::test(self::REGISTRY_TAB);

        // Badge permissif « Modifiable ».
        $component->assertSeeHtml('data-testid="upstream-permissive-'.$cap->id.'"');
        // assertSeeHtml contextuel.
        $component->assertSeeHtml('</i> Modifiable');
        // Le tooltip permissif dit la RELAXABILITÉ, pas « valeur amont s'applique ».
        $component->assertSee('votre réglage local prévaut');
        $component->assertDontSee('valeur amont');
        // Bouton « Éditer le défaut » actif (un permissif n'est pas bloqué).
        $component->assertSeeHtml('data-testid="edit-default-'.$cap->id.'"');
        // Note : « Votre réglage local s'applique ».
        $component->assertSeeHtml('data-testid="upstream-permissive-note-'.$cap->id.'"');
        $component->assertSeeHtml("Votre réglage local s'applique");
        // Pas de badge verrouillé ni local.
        $component->assertDontSeeHtml('data-testid="upstream-locked-'.$cap->id.'"');
        $component->assertDontSeeHtml('data-testid="upstream-local-'.$cap->id.'"');
    }

    #[Test]
    public function local_capability_with_active_contract_renders_local_badge(): void
    {
        // Contrat actif sans item pour cette capacité → statut Local. Le badge
        // « Local » n'est visible QUE si un contrat est actif, et son tooltip dit
        // « Défaut diffusé — aucune contrainte amont. » : la surface Broadcast,
        // là où capabilities-tab dit « Réglage propre à ce parc/groupe. ».
        $this->actAsAdmin();
        $cap = $this->capabilityWithKey('show_extensions', 'HKCU', 'Software\\Ext', 'ShowExt');
        // Créer un contrat actif sans item matchant → la capacité est « Local ».
        ControlHubContract::factory()->create();

        $component = Livewire::test(self::REGISTRY_TAB);

        // Marqueur local visible (contrat actif mais pas d'item pour cette capacité).
        $component->assertSeeHtml('data-testid="upstream-local-'.$cap->id.'"');
        // assertSeeHtml contextuel.
        $component->assertSeeHtml('</i> Local');
        // Tooltip différencié : la surface est le défaut diffusé, pas un parc ni un groupe.
        $component->assertSeeHtml('Défaut diffusé — aucune contrainte amont.');
        // Bouton « Éditer le défaut » actif.
        $component->assertSeeHtml('data-testid="edit-default-'.$cap->id.'"');
        // Pas de badge amont.
        $component->assertDontSeeHtml('data-testid="upstream-locked-'.$cap->id.'"');
        $component->assertDontSeeHtml('data-testid="upstream-permissive-'.$cap->id.'"');
    }

    #[Test]
    public function standalone_no_contract_renders_no_upstream_badges(): void
    {
        // En standalone (aucun contrat actif), AUCUN badge n'est rendu
        // (pas même « Local ») → UI byte-identique.
        $this->actAsAdmin();
        $cap = $this->capabilityWithKey('uac_enabled', 'HKLM', 'Software\\UAC', 'EnableLUA');
        // Aucun contrat actif.

        $component = Livewire::test(self::REGISTRY_TAB);

        // UI identique sans contrat.
        $component->assertDontSeeHtml('upstream-locked-');
        $component->assertDontSeeHtml('upstream-permissive-');
        // Badge « Local » absent en standalone (zéro badge).
        $component->assertDontSeeHtml('upstream-local-');
    }

    #[Test]
    public function severed_contract_renders_no_upstream_badges(): void
    {
        // Contrat severed = lien coupé → traité comme standalone.
        // AUCUN badge rendu (pas même « Local »).
        $this->actAsAdmin();
        $cap = $this->capabilityWithKey('severed_cap', 'HKCU', 'Software\\Sev', 'SevVal');
        // Créer un item (et son contrat) puis severer le contrat.
        $item = ControlHubContractItem::factory()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => 'HKCU|Software\\Sev|SevVal|REG_DWORD',
        ]);
        $item->contract->update(['link_state' => ControlHubLinkState::Severed->value]);

        $component = Livewire::test(self::REGISTRY_TAB);

        $component->assertDontSeeHtml('upstream-locked-');
        $component->assertDontSeeHtml('upstream-permissive-');
        $component->assertDontSeeHtml('upstream-local-');
    }

    #[Test]
    public function standalone_no_contract_emits_zero_items_queries_on_render(): void
    {
        // Au point d'usage réel (le rendu Livewire) : en standalone, AUCUNE
        // requête controlhub_contract_items ne doit être émise.
        $this->actAsAdmin();
        $this->capabilityWithKey('qry_count_cap', 'HKCU', 'Software\\QC', 'QCVal');
        // Aucun contrat actif → court-circuit.

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(self::REGISTRY_TAB);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $itemQueries = count(array_filter($log, fn (array $q): bool => str_contains($q['query'], 'controlhub_contract_items')));
        self::assertSame(0, $itemQueries, 'aucune requête controlhub_contract_items en standalone (NFR3 au rendu Livewire)');
    }

    #[Test]
    public function locked_badge_takes_precedence_over_permissive_for_multi_key_capability(): void
    {
        // Une capacité avec deux clés, l'une locked, l'autre permissive.
        // → un seul badge : verrouillé (précédence).
        $this->actAsAdmin();
        $cap = Capability::factory()->create(['key' => 'mk_cap', 'default_value' => 'on']);
        CapabilityProjection::factory()->for($cap)->keys([
            ['hive' => 'HKCU', 'path' => 'Software\\MK', 'name' => 'LKey', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
            ['hive' => 'HKCU', 'path' => 'Software\\MK', 'name' => 'PKey', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
        ])->create();

        $lockedItem = ControlHubContractItem::factory()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => 'HKCU|Software\\MK|LKey|REG_DWORD',
        ]);
        ControlHubContractItem::factory()->permissive()->create([
            'controlhub_contract_id' => $lockedItem->controlhub_contract_id,
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => 'HKCU|Software\\MK|PKey|REG_DWORD',
        ]);

        $component = Livewire::test(self::REGISTRY_TAB);

        $component->assertSeeHtml('data-testid="upstream-locked-'.$cap->id.'"');
        $component->assertDontSeeHtml('data-testid="upstream-permissive-'.$cap->id.'"');
        $component->assertDontSeeHtml('data-testid="upstream-local-'.$cap->id.'"');
    }
}
