<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Enums\ControlHubLinkState;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 29.4 (AC #1, #2, #3, #4, #6) — Tri-état (verrouillé/permissif/local) sur
 * l'onglet « Options / Capacités » d'un WorkstationGroup.
 *
 * Couvre :
 *   - Badge « Verrouillé » (data-testid upstream-locked) + boutons masqués (locked) ;
 *   - Badge « Modifiable » (data-testid upstream-permissive) + boutons actifs + note
 *     « Votre override s'applique à ce parc » (permissive) ;
 *   - Badge « Local » (data-testid upstream-local) sans contrainte amont — contrat actif ;
 *   - Badge permissif dans le picker (picker-permissive) ;
 *   - Standalone (aucun contrat) → AUCUN badge (NFR3 AC #6) ;
 *   - Contrat severed → AUCUN badge (AC #6, même traitement que standalone).
 *
 * Corrections post-review :
 *   - #2 : assertions sur le tooltip permissif (relaxabilité + assertDontSee 'valeur amont') ;
 *   - #3 : badges gatés sur hasActiveContract() — standalone = zéro badge, y compris Local ;
 *   - #6 : cas severed ajouté ;
 *   - #7 : compteur de requêtes items (zero en standalone, NFR3 au point d'usage réel) ;
 *   - #9 : assertSee génériques remplacés par assertSeeHtml contextuels.
 *
 * Non-régression 29.2 : badge locked + masquage (asserté ici en parallèle).
 * Non-régression 29.3 : les capacités permissives RESTENT addables.
 */
class CapabilitiesTabStatusBadgeTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::parc.groups._partials.capabilities-tab';

    private WorkstationGroup $parc;

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

        $this->parc = WorkstationGroup::factory()->logical()->create();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        Mockery::close();
        parent::tearDown();
    }

    private function actAsCustomizer(): void
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

    /** Insère un override pour le parc courant. */
    private function addOverride(Capability $cap, string $value = 'off'): void
    {
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->parc->id,
            'value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    // ── Badges dans la table des overrides ────────────────────────────────────

    #[Test]
    public function locked_capability_in_overrides_renders_locked_badge_and_hides_buttons(): void
    {
        $this->actAsCustomizer();
        $cap = $this->capabilityWithKey('remote_desktop', 'HKCU', 'Software\\RD', 'Enabled');
        $this->addOverride($cap);
        $this->lockUpstream('HKCU', 'Software\\RD', 'Enabled');

        $component = Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id]);

        // AC #1 — badge verrouillé présent (non-régression 29.2).
        $component->assertSeeHtml('data-testid="upstream-locked-'.$cap->id.'"');
        // #9 : assertSeeHtml contextuel (évite faux-positifs sur "Verrouillé" ailleurs).
        $component->assertSeeHtml('</i> Verrouillé');
        // Boutons masqués (29.2 : « Imposé par contrat amont »).
        $component->assertSeeHtml('Imposé par contrat amont');
        $component->assertDontSeeHtml('data-testid="edit-override-'.$cap->id.'"');
        $component->assertDontSeeHtml('data-testid="remove-override-'.$cap->id.'"');
        // Pas de badge permissif ni local.
        $component->assertDontSeeHtml('data-testid="upstream-permissive-'.$cap->id.'"');
        $component->assertDontSeeHtml('data-testid="upstream-local-'.$cap->id.'"');
    }

    #[Test]
    public function permissive_capability_in_overrides_renders_permissive_badge_and_keeps_buttons_active(): void
    {
        $this->actAsCustomizer();
        $cap = $this->capabilityWithKey('show_hidden_files', 'HKCU', 'Software\\SHF', 'Hidden');
        $this->addOverride($cap);
        $this->permissiveUpstream('HKCU', 'Software\\SHF', 'Hidden');

        $component = Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id]);

        // AC #2 — badge permissif « Modifiable » présent.
        $component->assertSeeHtml('data-testid="upstream-permissive-'.$cap->id.'"');
        // #9 : assertSeeHtml contextuel (évite faux-positifs sur "Modifiable" ailleurs).
        $component->assertSeeHtml('</i> Modifiable');
        // #2 : vérité du libellé permissif — tooltip dit la RELAXABILITÉ, pas « valeur amont s'applique ».
        $component->assertSee('votre réglage local prévaut');
        $component->assertDontSee('valeur amont');
        // Boutons actifs (un permissif n'est pas bloqué — 29.3 / FR4).
        $component->assertSeeHtml('data-testid="edit-override-'.$cap->id.'"');
        $component->assertSeeHtml('data-testid="remove-override-'.$cap->id.'"');
        // Explication FR8 : « Votre override s'applique à ce parc ».
        $component->assertSeeHtml('data-testid="upstream-permissive-note-'.$cap->id.'"');
        $component->assertSeeHtml("Votre override s'applique à ce parc");
        // Pas de badge verrouillé ni local.
        $component->assertDontSeeHtml('data-testid="upstream-locked-'.$cap->id.'"');
        $component->assertDontSeeHtml('data-testid="upstream-local-'.$cap->id.'"');
    }

    #[Test]
    public function local_capability_in_overrides_with_active_contract_renders_local_badge(): void
    {
        // AC #3 — contrat actif sans item pour cette capacité → statut Local.
        // #3 : le badge « Local » n'est visible QUE si un contrat est actif.
        $this->actAsCustomizer();
        $cap = $this->capabilityWithKey('show_extensions', 'HKCU', 'Software\\Ext', 'ShowExt');
        $this->addOverride($cap);
        // Créer un contrat actif sans item matchant → la capacité est « Local ».
        ControlHubContract::factory()->create();

        $component = Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id]);

        // AC #3 — marqueur local visible (contrat actif mais pas d'item pour cette capacité).
        $component->assertSeeHtml('data-testid="upstream-local-'.$cap->id.'"');
        // #9 : assertSeeHtml contextuel.
        $component->assertSeeHtml('</i> Local');
        // Boutons actifs.
        $component->assertSeeHtml('data-testid="edit-override-'.$cap->id.'"');
        $component->assertSeeHtml('data-testid="remove-override-'.$cap->id.'"');
        // Pas de badge amont.
        $component->assertDontSeeHtml('data-testid="upstream-locked-'.$cap->id.'"');
        $component->assertDontSeeHtml('data-testid="upstream-permissive-'.$cap->id.'"');
    }

    #[Test]
    public function standalone_no_contract_renders_no_upstream_badges(): void
    {
        // #3 — en standalone (aucun contrat actif), AUCUN badge n'est rendu
        // (pas même « Local ») → UI byte-identique à 27.12 (NFR3 AC #6).
        $this->actAsCustomizer();
        $cap = $this->capabilityWithKey('uac_enabled', 'HKLM', 'Software\\UAC', 'EnableLUA');
        $this->addOverride($cap);
        // Aucun contrat actif.

        $component = Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id]);

        // AC #6 — aucun badge amont (UI identique à 27.12).
        $component->assertDontSeeHtml('upstream-locked-');
        $component->assertDontSeeHtml('upstream-permissive-');
        // #3 : badge « Local » absent en standalone (zéro badge).
        $component->assertDontSeeHtml('upstream-local-');
    }

    // ── Cas severed (#6) ───────────────────────────────────────────────────────

    #[Test]
    public function severed_contract_renders_no_upstream_badges(): void
    {
        // AC #6 / #3 — contrat severed = lien coupé → traité comme standalone.
        // AUCUN badge rendu (pas même « Local »).
        $this->actAsCustomizer();
        $cap = $this->capabilityWithKey('severed_cap', 'HKCU', 'Software\\Sev', 'SevVal');
        $this->addOverride($cap);
        // Créer un item (et son contrat) puis severer le contrat.
        $item = ControlHubContractItem::factory()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => 'HKCU|Software\\Sev|SevVal|REG_DWORD',
        ]);
        $item->contract->update(['link_state' => ControlHubLinkState::Severed->value]);

        $component = Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id]);

        $component->assertDontSeeHtml('upstream-locked-');
        $component->assertDontSeeHtml('upstream-permissive-');
        $component->assertDontSeeHtml('upstream-local-');
    }

    // ── Compteur de requêtes NFR3 (#7) ────────────────────────────────────────

    #[Test]
    public function standalone_no_contract_emits_zero_items_queries_on_render(): void
    {
        // #7 — NFR3 au point d'usage réel (rendu Livewire) : en standalone,
        // AUCUNE requête controlhub_contract_items ne doit être émise.
        $this->actAsCustomizer();
        $cap = $this->capabilityWithKey('qry_count_cap', 'HKCU', 'Software\\QC', 'QCVal');
        $this->addOverride($cap);
        // Aucun contrat actif → court-circuit NFR3.

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id]);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $itemQueries = count(array_filter($log, fn (array $q): bool => str_contains($q['query'], 'controlhub_contract_items')));
        self::assertSame(0, $itemQueries, 'aucune requête controlhub_contract_items en standalone (NFR3 au rendu Livewire)');
    }

    // ── Badge dans le picker d'ajout ───────────────────────────────────────────

    #[Test]
    public function permissive_capability_in_picker_shows_modifiable_badge(): void
    {
        $this->actAsCustomizer();
        $cap = $this->capabilityWithKey('printer_default', 'HKCU', 'Software\\Print', 'Default');
        // Pas d'override existant → visible dans le picker.
        $this->permissiveUpstream('HKCU', 'Software\\Print', 'Default');

        $component = Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id]);

        // La capacité est addable (non-régression 29.3 : permissif ≠ verrou).
        $addableIds = array_column($component->instance()->addableCapabilities(), 'id');
        self::assertContains($cap->id, $addableIds, 'capacité permissive reste addable');

        // Badge « Modifiable » dans le picker.
        $component->assertSeeHtml('data-testid="picker-permissive-'.$cap->id.'"');
    }

    #[Test]
    public function locked_capability_absent_from_picker(): void
    {
        $this->actAsCustomizer();
        $cap = $this->capabilityWithKey('wallpaper_lock', 'HKLM', 'Software\\WP', 'Lock');
        $this->lockUpstream('HKLM', 'Software\\WP', 'Lock');

        $component = Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id]);

        // Non-régression 29.2 : capacité verrouillée absente du picker.
        $addableIds = array_column($component->instance()->addableCapabilities(), 'id');
        self::assertNotContains($cap->id, $addableIds, 'capacité verrouillée absente du picker');
        $component->assertDontSeeHtml('data-testid="picker-permissive-'.$cap->id.'"');
    }

    // ── Précédence verrouillé > permissif ─────────────────────────────────────

    #[Test]
    public function locked_badge_takes_precedence_over_permissive_for_multi_key_capability(): void
    {
        // Une capacité avec deux clés : l'une verrouillée, l'autre permissive.
        // → statut = 'locked' (AC #4). Un seul badge rendu.
        $this->actAsCustomizer();
        $cap = Capability::factory()->create(['key' => 'multi_key_cap', 'default_value' => 'on']);
        CapabilityProjection::factory()->for($cap)->keys([
            ['hive' => 'HKCU', 'path' => 'Software\\MK', 'name' => 'LKey', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
            ['hive' => 'HKCU', 'path' => 'Software\\MK', 'name' => 'PKey', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
        ])->create();
        $this->addOverride($cap);

        $lockedItem = ControlHubContractItem::factory()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => 'HKCU|Software\\MK|LKey|REG_DWORD',
        ]);
        ControlHubContractItem::factory()->permissive()->create([
            'controlhub_contract_id' => $lockedItem->controlhub_contract_id,
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => 'HKCU|Software\\MK|PKey|REG_DWORD',
        ]);

        $component = Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id]);

        // Badge verrouillé présent, badge permissif absent (précédence AC #4).
        $component->assertSeeHtml('data-testid="upstream-locked-'.$cap->id.'"');
        $component->assertDontSeeHtml('data-testid="upstream-permissive-'.$cap->id.'"');
        $component->assertDontSeeHtml('data-testid="upstream-local-'.$cap->id.'"');
    }
}
