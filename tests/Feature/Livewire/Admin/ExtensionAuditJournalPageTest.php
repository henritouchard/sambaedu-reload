<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\Extension;
use App\Models\ExtensionAuditLog;
use App\Models\ExtensionSource;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 56.5 (AC5, AC6) — `/admin/extensions/journal` : le journal d'audit FR36,
 * enfin LISIBLE.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  LES QUATRE PROPRIÉTÉS QUI COMPTENT
 *
 *  1. Le journal affiche des lignes de TOUTES les époques (54.2 → 56.4) et de
 *     toutes les FORMES : cible extension, cible source, acteur `system`,
 *     `details` vide, FK nulle après suppression de la cible.
 *  2. Une action INCONNUE du mapping s'affiche telle quelle, badge neutre — la
 *     page verra un jour des actions écrites par une story future.
 *  3. Aucune URL de source, aucun secret : la page ne rend QUE les colonnes du
 *     journal.
 *  4. Le bandeau « journal peut-être incomplet » (legs review 56.3 #4) et son
 *     acquittement — qui n'écrit AUCUNE ligne d'audit.
 * ══════════════════════════════════════════════════════════════════════════
 */
class ExtensionAuditJournalPageTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'pages::admin.extensions.journal.index';

    /** URL piégeuse : un dépôt privé peut porter son jeton dans l'URL. */
    private const TRAP_URL = 'https://depot.example.test/extensions?private_token=SECRET-DU-DEPOT';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Cache FICHIER : un marqueur laissé par un autre test survivrait à
        // `RefreshDatabase`.
        ExtensionAuditLog::acknowledgeWriteFailure();

        $this->admin = User::query()->create([
            'login' => 'extension-journal-admin',
            'role' => 'autre',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        ExtensionAuditLog::acknowledgeWriteFailure();

        parent::tearDown();
    }

    /** @param list<string> $abilities */
    private function grant(array $abilities): void
    {
        Gate::before(fn ($user, string $ability) => in_array($ability, $abilities, true) ? true : null);
    }

    /**
     * Un jeu de lignes couvrant toutes les FORMES réelles du journal.
     *
     * @return array{extension: Extension, source: ExtensionSource}
     */
    private function seedHeterogeneousJournal(): array
    {
        $source = ExtensionSource::factory()->remote(self::TRAP_URL)->create(['key' => 'depot-tiers']);

        $extension = Extension::factory()
            ->for($source, 'source')
            ->app()
            ->withInstallBlock()
            ->installed(9300)
            ->create(['key' => 'hello', 'name' => 'Hello']);

        // 54.2 — acte humain sur une extension.
        ExtensionAuditLog::log(
            $extension->id,
            (string) $extension->key,
            (string) $extension->name,
            ExtensionAuditLog::ACTION_INTEGRATE,
            $this->admin->id,
            $this->admin->login,
        );

        // 56.1 — acte de SOURCE, colonnes d'extension vides, acteur planifié.
        ExtensionAuditLog::logSource(
            $source->id,
            (string) $source->key,
            ExtensionAuditLog::ACTION_SOURCE_SYNC_FAILED,
            null,
            ExtensionAuditLog::ACTOR_SYSTEM,
        );

        // 56.2 — échec avec catégorie courte.
        ExtensionAuditLog::log(
            $extension->id,
            (string) $extension->key,
            (string) $extension->name,
            ExtensionAuditLog::ACTION_INSTALL_FAILED,
            $this->admin->id,
            $this->admin->login,
            'sha256 non concordant',
        );

        // 56.3 / 56.4.
        ExtensionAuditLog::log(
            $extension->id,
            (string) $extension->key,
            (string) $extension->name,
            ExtensionAuditLog::ACTION_UPDATE,
            $this->admin->id,
            $this->admin->login,
        );
        ExtensionAuditLog::log(
            $extension->id,
            (string) $extension->key,
            (string) $extension->name,
            ExtensionAuditLog::ACTION_SCOPE_REVOKE,
            $this->admin->id,
            $this->admin->login,
            'groups',
        );

        return ['extension' => $extension, 'source' => $source];
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC5 — le journal se lit
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function the_journal_lists_entries_of_every_epoch_and_every_shape(): void
    {
        $this->grant(['server.admin']);
        $this->seedHeterogeneousJournal();

        Livewire::test(self::PAGE)
            ->assertSeeHtml('data-testid="journal-table"')
            ->assertSee('Intégration')
            ->assertSee('Catalogue de source refusé')
            ->assertSee('Installation en échec')
            ->assertSee('Mise à jour')
            ->assertSee('Autorisation révoquée')
            ->assertSee('sha256 non concordant')
            ->assertSee('depot-tiers')
            ->assertSee('extension-journal-admin')
            ->assertSee('system');
    }

    #[Test]
    public function an_empty_journal_shows_a_clean_empty_state(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::PAGE)
            ->assertSeeHtml('data-testid="journal-empty"')
            ->assertDontSeeHtml('data-testid="journal-table"');
    }

    #[Test]
    public function entries_are_ordered_newest_first_and_paginated_by_25(): void
    {
        $this->grant(['server.admin']);

        $extension = Extension::factory()->fromBundled()->link('/doc')->integrated()->create(['key' => 'doc']);

        for ($i = 1; $i <= 30; $i++) {
            ExtensionAuditLog::log(
                $extension->id,
                'doc',
                'Doc '.$i,
                ExtensionAuditLog::ACTION_INTEGRATE,
                $this->admin->id,
                $this->admin->login,
                'entrée '.$i,
            );
        }

        $component = Livewire::test(self::PAGE);

        $rows = $component->get('rows');
        self::assertCount(25, $rows->items());
        self::assertSame(30, $rows->total());
        // Tri `id` DESC : la table est append-only, l'id EST l'ordre.
        self::assertSame('entrée 30', $rows->items()[0]['details']);

        $component->call('gotoPage', 2);
        self::assertCount(5, $component->get('rows')->items());
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC5 — filtres
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function the_action_filter_narrows_the_list(): void
    {
        $this->grant(['server.admin']);
        $this->seedHeterogeneousJournal();

        $component = Livewire::test(self::PAGE)
            ->set('action', ExtensionAuditLog::ACTION_INSTALL_FAILED);

        $rows = $component->get('rows');
        self::assertSame(1, $rows->total());
        self::assertSame(ExtensionAuditLog::ACTION_INSTALL_FAILED, $rows->items()[0]['action']);
    }

    #[Test]
    public function the_extension_filter_narrows_the_list_and_excludes_source_entries(): void
    {
        $this->grant(['server.admin']);
        $this->seedHeterogeneousJournal();

        $rows = Livewire::test(self::PAGE)->set('ext', 'hello')->get('rows');

        self::assertSame(4, $rows->total());
        foreach ($rows->items() as $row) {
            self::assertSame('extension', $row['target_kind']);
        }
    }

    #[Test]
    public function both_filters_combine(): void
    {
        $this->grant(['server.admin']);
        $this->seedHeterogeneousJournal();

        $rows = Livewire::test(self::PAGE)
            ->set('ext', 'hello')
            ->set('action', ExtensionAuditLog::ACTION_UPDATE)
            ->get('rows');

        self::assertSame(1, $rows->total());

        // Combinaison sans intersection ⇒ vide, jamais une erreur.
        $empty = Livewire::test(self::PAGE)
            ->set('ext', 'hello')
            ->set('action', ExtensionAuditLog::ACTION_SOURCE_SYNC_FAILED)
            ->get('rows');

        self::assertSame(0, $empty->total());
    }

    #[Test]
    public function the_extension_filter_offers_the_keys_present_in_the_journal(): void
    {
        $this->grant(['server.admin']);
        $this->seedHeterogeneousJournal();

        $component = Livewire::test(self::PAGE);

        self::assertSame(['hello'], $component->get('extensionKeys'));
        $component->assertSeeHtml('data-testid="journal-filter-extension"')
            ->assertSeeHtml('data-testid="journal-filter-action"');
    }

    #[Test]
    public function resetting_filters_clears_both(): void
    {
        $this->grant(['server.admin']);
        $this->seedHeterogeneousJournal();

        Livewire::test(self::PAGE)
            ->set('ext', 'hello')
            ->set('action', ExtensionAuditLog::ACTION_UPDATE)
            ->call('resetFilters')
            ->assertSet('ext', '')
            ->assertSet('action', '');
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC5 — RENDU TOLÉRANT
    // ══════════════════════════════════════════════════════════════════════

    /**
     * LE test de tolérance. `action` est un string libre par construction : une
     * action écrite par une story future doit s'AFFICHER, pas faire tomber la
     * page ni disparaître de l'écran.
     */
    #[Test]
    public function an_unknown_action_is_rendered_verbatim_with_a_neutral_badge(): void
    {
        $this->grant(['server.admin']);

        $row = ExtensionAuditLog::create([
            'extension_id' => null,
            'extension_key' => 'futur',
            'extension_name' => 'Extension du futur',
            'action' => 'action_future_inconnue',
            'details' => '',
            'actor_user_id' => null,
            'actor_login' => 'root',
            'created_at' => now(),
        ]);

        $component = Livewire::test(self::PAGE);

        $rendered = $component->get('rows')->items()[0];
        self::assertFalse($rendered['action_known']);
        self::assertSame('action_future_inconnue', $rendered['action_label']);
        self::assertSame('badge-neutral', $rendered['action_badge']);

        $component->assertSee('action_future_inconnue')
            ->assertSeeHtml('data-testid="journal-row-'.$row->id.'"');
    }

    /**
     * Dénormalisations : la ligne reste LISIBLE après suppression de sa cible
     * (les FK sont `nullOnDelete`).
     */
    #[Test]
    public function an_entry_stays_readable_after_its_extension_has_been_deleted(): void
    {
        $this->grant(['server.admin']);
        $seed = $this->seedHeterogeneousJournal();

        $seed['extension']->delete();

        $component = Livewire::test(self::PAGE);

        $component->assertSee('Hello')->assertSee('hello');
        foreach ($component->get('rows')->items() as $row) {
            self::assertNotSame('', $row['target_label']);
        }
    }

    #[Test]
    public function an_entry_without_details_renders_a_dash_rather_than_an_empty_cell(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->link('/doc')->integrated()->create(['key' => 'doc']);

        ExtensionAuditLog::log(
            $extension->id,
            'doc',
            'Doc',
            ExtensionAuditLog::ACTION_INTEGRATE,
            $this->admin->id,
            $this->admin->login,
        );

        self::assertSame('', Livewire::test(self::PAGE)->get('rows')->items()[0]['details']);
        Livewire::test(self::PAGE)->assertSee('—');
    }

    // ══════════════════════════════════════════════════════════════════════
    // Sécurité — aucune fuite, trois couches
    // ══════════════════════════════════════════════════════════════════════

    /**
     * L'URL du dépôt est en base, avec un jeton dedans. Elle ne doit apparaître
     * NULLE PART sur cette page : le journal ne rend que ses propres colonnes,
     * jamais `source->url`.
     */
    #[Test]
    public function the_page_never_renders_a_source_url_nor_a_token(): void
    {
        $this->grant(['server.admin']);
        $this->seedHeterogeneousJournal();

        $html = Livewire::test(self::PAGE)->html();

        self::assertStringNotContainsString('SECRET-DU-DEPOT', $html);
        self::assertStringNotContainsString('private_token', $html);
        self::assertStringNotContainsString('depot.example.test', $html);
    }

    #[Test]
    public function the_page_never_renders_an_installed_sha256(): void
    {
        $this->grant(['server.admin']);
        $seed = $this->seedHeterogeneousJournal();

        $sha = (string) $seed['extension']->installed_sha256;
        self::assertNotSame('', $sha);

        self::assertStringNotContainsString($sha, Livewire::test(self::PAGE)->html());
    }

    #[Test]
    public function mounting_without_server_admin_is_forbidden(): void
    {
        Livewire::test(self::PAGE)->assertForbidden();
    }

    #[Test]
    public function every_action_is_forbidden_without_server_admin(): void
    {
        foreach (['resetFilters', 'askAcknowledge', 'confirmAcknowledge'] as $method) {
            // Composant NEUF par action : un 403 invalide le snapshot Livewire.
            $this->grant(['server.admin']);
            $component = Livewire::test(self::PAGE);

            $this->app->forgetInstance(GateContract::class);
            Gate::clearResolvedInstances();

            $component->call($method)->assertForbidden();
        }
    }

    /** CONTRE-ÉPREUVE : avec le droit, les mêmes appels passent. */
    #[Test]
    public function the_same_actions_succeed_with_server_admin(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::PAGE)
            ->call('askAcknowledge')
            ->assertSet('isAcknowledgeOpen', true)
            ->call('closeAcknowledge')
            ->assertSet('isAcknowledgeOpen', false)
            ->call('resetFilters')
            ->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC6 — bandeau et acquittement (legs review 56.3 #4)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function no_banner_is_shown_without_a_marker(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::PAGE)
            ->assertSet('writeFailure', null)
            ->assertDontSeeHtml('data-testid="audit-write-failure-banner"');
    }

    #[Test]
    public function a_marker_shows_the_banner_with_its_count(): void
    {
        $this->grant(['server.admin']);
        ExtensionAuditLog::recordWriteFailure();
        ExtensionAuditLog::recordWriteFailure();

        $component = Livewire::test(self::PAGE);

        self::assertSame(2, $component->get('writeFailure')['count']);
        $component->assertSeeHtml('data-testid="audit-write-failure-banner"')
            ->assertSeeHtml('data-testid="acknowledge-audit-failure"')
            ->assertSee('INCOMPLET');
    }

    #[Test]
    public function acknowledging_clears_the_marker_the_banner_and_writes_no_audit_line(): void
    {
        $this->grant(['server.admin']);
        ExtensionAuditLog::recordWriteFailure();

        $before = ExtensionAuditLog::query()->count();

        Livewire::test(self::PAGE)
            ->call('askAcknowledge')
            ->assertSet('isAcknowledgeOpen', true)
            ->call('confirmAcknowledge')
            ->assertSet('isAcknowledgeOpen', false)
            ->assertSet('writeFailure', null)
            ->assertDontSeeHtml('data-testid="audit-write-failure-banner"');

        self::assertNull(ExtensionAuditLog::writeFailureMarker());
        self::assertSame(
            $before,
            ExtensionAuditLog::query()->count(),
            'l\'acquittement n\'est PAS un acte de conformité : l\'auditer créerait une boucle',
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // NFR6 — une table illisible ne rend pas une 500
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function an_unreadable_journal_degrades_to_an_empty_page_instead_of_500ing(): void
    {
        $this->grant(['server.admin']);
        \Illuminate\Support\Facades\Schema::drop('extension_audit_logs');

        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertSeeHtml('data-testid="journal-empty"');
    }
}
