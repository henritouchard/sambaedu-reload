<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ControlHubLabelMode;
use App\Enums\ControlHubLinkState;
use App\Exceptions\ControlHub\LabelAssignmentException;
use App\Models\Application;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Models\ControlHubContractLabel;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Services\ControlHub\WorkstationGroupLabelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 30.2 — Mapping refnum d'un label de contrat amont → WorkstationGroup.
 *
 * Couvre les 10 AC :
 * - #1 migration (colonne controlhub_label présente)
 * - #2 assign d'un label free (groupe existant)
 * - #3 création d'un groupe portant un label free (page Livewire)
 * - #4 invariant « 1 label max » (2e label refusé / même label idempotent)
 * - #5 label reserved refusé, base inchangée
 * - #6 label inconnu / hors contrat actif refusé
 * - #7 détachement → null
 * - #8 Gate update-workstationGroup scopé (refus avant écriture)
 * - #9 UI Livewire (select free-only, save persiste, label hors contrat → toast)
 * - #10 standalone (sans contrat actif, comportement inchangé) + R3 (introspection)
 *
 * ⚠️ Tests sur HÔTE (php8.4 + pdo_sqlite) — JAMAIS sur la VM.
 * ⚠️ Invariant « 1 max » testé PAR COMPORTEMENT (refus du 2e label), jamais par
 *    une contrainte DB (pièges SQLite : varchar non appliqué, NULL ≠ NULL).
 */
class WorkstationGroupLabelTest extends TestCase
{
    use RefreshDatabase;

    // Création ET édition passent par la MÊME modale réutilisable : `open()` sans
    // argument = création, `open($id)` = édition.
    private const MODAL_COMPONENT = 'pages::parc.groups._partials.group-form-modal';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // L'observer WorkstationGroup dispatche un job de sync AD à chaque write :
        // on le neutralise (pas de LDAP en test).
        Queue::fake();
    }

    private function service(): WorkstationGroupLabelService
    {
        return new WorkstationGroupLabelService();
    }

    /** Crée un contrat amont actif avec les labels fournis (nom => mode). */
    private function activeContractWithLabels(array $labels = []): ControlHubContract
    {
        $contract = ControlHubContract::factory()->create([
            'link_state' => ControlHubLinkState::Active,
        ]);

        foreach ($labels as $name => $mode) {
            ControlHubContractLabel::factory()->create([
                'controlhub_contract_id' => $contract->id,
                'name' => $name,
                'mode' => $mode,
            ]);
        }

        return $contract;
    }

    /** Utilisateur agissant + autorisation de la gate parc (sans permissions Spatie). */
    private function actingAsRefnum(): User
    {
        $user = User::query()->create(['login' => 'refnum', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($user);
        // `computer.install` est la garde de la modale de création/édition ; les
        // deux abilities `*-workstationGroup` restent celles du service et des policies.
        Gate::before(fn ($u, string $ability) => in_array($ability, ['update-workstationGroup', 'create-workstationGroup', 'computer.install'], true) ? true : null);

        return $user;
    }

    // ── AC #1 — Migration ────────────────────────────────────────────────────

    #[Test]
    public function migration_adds_controlhub_label_column(): void
    {
        self::assertTrue(Schema::hasColumn('workstation_groups', 'controlhub_label'));
    }

    // ── AC #2 — Assignation d'un label free (groupe existant) ─────────────────

    #[Test]
    public function assigns_a_free_label_to_an_existing_group(): void
    {
        $this->activeContractWithLabels(['salle-info' => ControlHubLabelMode::Free]);
        $group = WorkstationGroup::factory()->create(['controlhub_label' => null]);

        $this->service()->assignLabel($group, 'salle-info');

        self::assertSame('salle-info', $group->refresh()->controlhub_label);
        self::assertTrue($group->hasControlHubLabel());
        self::assertSame('salle-info', $group->controlHubLabel());
    }

    #[Test]
    public function scope_carrying_controlhub_label_resolves_groups_by_name(): void
    {
        $this->activeContractWithLabels(['nomade' => ControlHubLabelMode::Free]);
        $a = WorkstationGroup::factory()->create(['controlhub_label' => 'nomade']);
        WorkstationGroup::factory()->create(['controlhub_label' => null]);

        $found = WorkstationGroup::carryingControlHubLabel('nomade')->pluck('id')->all();

        self::assertSame([$a->id], $found);
    }

    // ── AC #3 — Création d'un groupe portant un label free (page Livewire) ────

    #[Test]
    public function creates_a_group_carrying_a_free_label(): void
    {
        $this->actingAsRefnum();
        $this->activeContractWithLabels(['salle-info' => ControlHubLabelMode::Free]);

        Livewire::test(self::MODAL_COMPONENT)
            ->call('open')
            ->set('display_name', 'parc-nouveau')
            ->set('is_physical', false)
            ->set('controlhubLabel', 'salle-info')
            ->call('save')
            ->assertRedirect(); // chemin de SUCCÈS → redirect (pas le retour-édition d'échec).

        // Le nom technique est désormais auto-généré (slug) depuis le nom affiché ;
        // on localise le groupe par son nom affiché, pas par le slug.
        $group = WorkstationGroup::where('display_name', 'parc-nouveau')->first();
        self::assertNotNull($group);
        self::assertSame('salle-info', $group->controlhub_label);
        // Le redirect de succès pointe la fiche du groupe, pas la page d'édition (refus label).
        Livewire::test(self::MODAL_COMPONENT)
            ->call('open')
            ->set('display_name', 'parc-bis')
            ->set('is_physical', false)
            ->set('controlhubLabel', 'salle-info')
            ->call('save')
            ->assertRedirect(route('app.parc.groups.show', WorkstationGroup::where('display_name', 'parc-bis')->first()->id));
    }

    #[Test]
    public function gate_refuses_group_creation_for_unauthorized_user(): void
    {
        // M5 — le Gate `create-workstationGroup` protège aussi le chemin de création.
        $this->activeContractWithLabels(['salle-info' => ControlHubLabelMode::Free]);
        $user = User::query()->create(['login' => 'sans-droit-new', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($user);

        self::assertTrue(Gate::forUser($user)->denies('create-workstationGroup'));

        // La modale refuse à l'OUVERTURE, avant tout formulaire.
        Livewire::test(self::MODAL_COMPONENT)
            ->call('open')
            ->assertForbidden();

        // Et une fois de plus à l'enregistrement : un client qui forgerait
        // l'état du composant sans passer par `open()` reste refusé.
        Livewire::test(self::MODAL_COMPONENT)
            ->set('display_name', 'parc-interdit')
            ->set('is_physical', false)
            ->set('controlhubLabel', 'salle-info')
            ->call('save')
            ->assertForbidden();

        self::assertNull(WorkstationGroup::where('display_name', 'parc-interdit')->first());
    }

    // ── AC #4 — Invariant « 1 label max » + idempotence ──────────────────────

    #[Test]
    public function refuses_a_second_different_label_and_writes_nothing(): void
    {
        $this->activeContractWithLabels([
            'salle-info' => ControlHubLabelMode::Free,
            'nomade' => ControlHubLabelMode::Free,
        ]);
        $group = WorkstationGroup::factory()->create(['controlhub_label' => 'salle-info']);

        try {
            $this->service()->assignLabel($group, 'nomade');
            self::fail('LabelAssignmentException attendue pour un 2e label différent.');
        } catch (LabelAssignmentException $e) {
            // attendu
        }

        self::assertSame('salle-info', $group->refresh()->controlhub_label);
    }

    #[Test]
    public function reassigning_the_same_label_is_idempotent(): void
    {
        $this->activeContractWithLabels(['salle-info' => ControlHubLabelMode::Free]);
        $group = WorkstationGroup::factory()->create(['controlhub_label' => 'salle-info']);

        // Ne lève PAS d'exception (no-op idempotent).
        $this->service()->assignLabel($group, 'salle-info');

        self::assertSame('salle-info', $group->refresh()->controlhub_label);
    }

    // ── AC #5 — Label reserved non attribuable ───────────────────────────────

    #[Test]
    public function refuses_a_reserved_label(): void
    {
        $this->activeContractWithLabels(['direction' => ControlHubLabelMode::Reserved]);
        $group = WorkstationGroup::factory()->create(['controlhub_label' => null]);

        try {
            $this->service()->assignLabel($group, 'direction');
            self::fail('LabelAssignmentException attendue pour un label réservé.');
        } catch (LabelAssignmentException $e) {
            self::assertStringContainsString('réservé', $e->getMessage());
        }

        self::assertNull($group->refresh()->controlhub_label);
    }

    // ── AC #6 — Label inconnu / hors contrat actif refusé ────────────────────

    #[Test]
    public function refuses_an_unknown_label(): void
    {
        $this->activeContractWithLabels(['salle-info' => ControlHubLabelMode::Free]);
        $group = WorkstationGroup::factory()->create(['controlhub_label' => null]);

        $this->expectException(LabelAssignmentException::class);

        try {
            $this->service()->assignLabel($group, 'fantome');
        } finally {
            self::assertNull($group->refresh()->controlhub_label);
        }
    }

    // ── AC #7 — Détachement ──────────────────────────────────────────────────

    #[Test]
    public function detaches_a_label_back_to_null(): void
    {
        $this->activeContractWithLabels(['salle-info' => ControlHubLabelMode::Free]);
        $group = WorkstationGroup::factory()->create(['controlhub_label' => 'salle-info']);

        $this->service()->detachLabel($group);

        self::assertNull($group->refresh()->controlhub_label);
    }

    #[Test]
    public function detaching_a_reserved_label_is_refused_by_service(): void
    {
        // Review 30.2 finding #2 — un label réservé porté par un groupe (imposé,
        // relève de 30.3) ne doit PAS pouvoir être détaché par le refnum, y compris
        // via une requête Livewire forgée. Le garde-fou est au niveau SERVICE.
        $this->activeContractWithLabels(['direction' => ControlHubLabelMode::Reserved]);
        $group = WorkstationGroup::factory()->create(['controlhub_label' => 'direction']);

        try {
            $this->service()->detachLabel($group);
            self::fail('LabelAssignmentException attendue : un label réservé n\'est pas détachable.');
        } catch (LabelAssignmentException $e) {
            self::assertStringContainsString('réservé', $e->getMessage());
        }

        self::assertSame('direction', $group->refresh()->controlhub_label);
    }

    // ── AC #8 — Gate scopé (refus avant écriture) ────────────────────────────

    #[Test]
    public function gate_refuses_assignment_for_unauthorized_user(): void
    {
        $this->activeContractWithLabels(['salle-info' => ControlHubLabelMode::Free]);
        $group = WorkstationGroup::factory()->create(['controlhub_label' => null]);

        // Utilisateur sans droit computer.install → la policy update() refuse.
        $user = User::query()->create(['login' => 'sans-droit', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($user);

        self::assertTrue(Gate::forUser($user)->denies('update-workstationGroup', $group));

        Livewire::test(self::MODAL_COMPONENT)
            ->call('open', $group->id)
            ->assertForbidden();

        Livewire::test(self::MODAL_COMPONENT)
            ->set('editingId', $group->id)
            ->set('display_name', $group->display_name_or_name)
            ->set('controlhubLabel', 'salle-info')
            ->call('save')
            ->assertForbidden();

        self::assertNull($group->refresh()->controlhub_label);
    }

    // ── AC #9 — UI Livewire (select free-only, save, toast d'erreur) ─────────

    #[Test]
    public function edit_page_lists_only_free_labels_and_saves(): void
    {
        $this->actingAsRefnum();
        $this->activeContractWithLabels([
            'salle-info' => ControlHubLabelMode::Free,
            'direction' => ControlHubLabelMode::Reserved,
        ]);
        $group = WorkstationGroup::factory()->create(['controlhub_label' => null]);

        Livewire::test(self::MODAL_COMPONENT)
            ->call('open', $group->id)
            ->assertSet('hasActiveContract', true)
            ->assertSet('freeLabelNames', ['salle-info'])
            ->set('controlhubLabel', 'salle-info')
            ->call('save');

        self::assertSame('salle-info', $group->refresh()->controlhub_label);
    }

    #[Test]
    public function edit_page_shows_error_toast_for_label_out_of_contract(): void
    {
        $this->actingAsRefnum();
        $this->activeContractWithLabels(['salle-info' => ControlHubLabelMode::Free]);
        $group = WorkstationGroup::factory()->create(['controlhub_label' => null]);

        Livewire::test(self::MODAL_COMPONENT)
            ->call('open', $group->id)
            ->set('controlhubLabel', 'fantome')
            ->call('save')
            ->assertDispatched('toastMagic', fn ($event, $params) => ($params['status'] ?? null) === 'error')
            ->assertNoRedirect();

        self::assertNull($group->refresh()->controlhub_label);
    }

    // ── AC #10 — Standalone (sans contrat actif) + R3 ────────────────────────

    #[Test]
    public function standalone_without_active_contract_proposes_no_label(): void
    {
        self::assertNull(ControlHubContract::active());

        $this->actingAsRefnum();
        $group = WorkstationGroup::factory()->create(['controlhub_label' => null]);

        Livewire::test(self::MODAL_COMPONENT)
            ->call('open', $group->id)
            ->assertSet('hasActiveContract', false)
            ->assertSet('freeLabelNames', [])
            ->call('save'); // comportement parc inchangé, aucune contrainte ajoutée

        self::assertNull($group->refresh()->controlhub_label);
    }

    #[Test]
    public function standalone_assign_without_contract_is_refused(): void
    {
        // Un contrat severed n'est pas « actif » : aucune assignation possible.
        ControlHubContract::factory()->severed()->create();
        $group = WorkstationGroup::factory()->create(['controlhub_label' => null]);

        $this->expectException(LabelAssignmentException::class);

        $this->service()->assignLabel($group, 'salle-info');
    }

    // ── Review 30.2 — régressions corrigées (findings #1/#2) ─────────────────

    #[Test]
    public function editing_a_group_holding_a_reserved_label_saves_other_fields_without_error(): void
    {
        // Finding #1 — un groupe portant un label réservé (cas normal dès 30.3, ou
        // via réconciliation 28.2 transformant un free porté en reserved). Éditer un
        // AUTRE champ (le nom) ne doit PAS lever de fausse erreur : ré-affirmer le
        // label déjà porté est un no-op idempotent (vérifié EN PREMIER dans le service).
        $this->actingAsRefnum();
        $this->activeContractWithLabels(['direction' => ControlHubLabelMode::Reserved]);
        $group = WorkstationGroup::factory()->create(['controlhub_label' => 'direction', 'name' => 'avant']);

        Livewire::test(self::MODAL_COMPONENT)
            ->call('open', $group->id)
            ->assertSet('reservedLabelHeld', 'direction') // affiché en lecture seule
            ->set('display_name', 'apres')
            ->call('save')
            ->assertRedirect(route('app.parc.groups.show', $group->id)); // succès, pas de retour-erreur

        $group->refresh();
        self::assertSame('apres', $group->display_name);
        self::assertSame('avant', $group->name); // nom technique immuable
        self::assertSame('direction', $group->controlhub_label); // label réservé préservé
    }

    #[Test]
    public function editing_a_group_holding_a_dangling_label_saves_other_fields_without_error(): void
    {
        // Finding #1 (variante dangling) — le label porté a disparu du contrat actif
        // (prune réconciliation 28.2). Éditer le groupe reste possible : no-op idempotent.
        $this->actingAsRefnum();
        $this->activeContractWithLabels(['salle-info' => ControlHubLabelMode::Free]);
        $group = WorkstationGroup::factory()->create(['controlhub_label' => 'label-disparu', 'name' => 'avant']);

        Livewire::test(self::MODAL_COMPONENT)
            ->call('open', $group->id)
            ->assertSet('reservedLabelHeld', 'label-disparu')
            ->set('display_name', 'apres')
            ->call('save')
            ->assertRedirect(route('app.parc.groups.show', $group->id));

        $group->refresh();
        self::assertSame('apres', $group->display_name);
        self::assertSame('avant', $group->name); // nom technique immuable
        self::assertSame('label-disparu', $group->controlhub_label);
    }

    // ── Le label posé ATTEINT le parc, sans attendre la réception suivante ───

    #[Test]
    public function assigning_a_label_immediately_applies_what_the_contract_destines_to_it(): void
    {
        $contract = $this->activeContractWithLabels(['modelibre' => ControlHubLabelMode::Free]);
        $app = Application::create(['app_id' => 'bkchem', 'name' => 'BKChem']);
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => Application::TYPE_APPLICATIONS,
            'key' => 'bkchem',
            'target_type' => 'label',
            'target_label' => 'modelibre',
        ]);

        $group = WorkstationGroup::factory()->create(['controlhub_label' => null]);

        $this->service()->assignLabel($group, 'modelibre');

        // Sans la passe rejouée, l'item restait « pending — aucun parc ne porte
        // le label » jusqu'à la réception suivante du contrat.
        self::assertSame(
            [$group->id],
            DB::table('application_workstation_group')->pluck('workstation_group_id')->all(),
        );
    }

    #[Test]
    public function detaching_a_label_withdraws_what_it_had_brought(): void
    {
        $contract = $this->activeContractWithLabels(['modelibre' => ControlHubLabelMode::Free]);
        Application::create(['app_id' => 'bkchem', 'name' => 'BKChem']);
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => Application::TYPE_APPLICATIONS,
            'key' => 'bkchem',
            'target_type' => 'label',
            'target_label' => 'modelibre',
        ]);

        $group = WorkstationGroup::factory()->create(['controlhub_label' => null]);
        $this->service()->assignLabel($group, 'modelibre');
        self::assertNotEmpty(DB::table('application_workstation_group')->get());

        $this->service()->detachLabel($group);

        self::assertEmpty(DB::table('application_workstation_group')->get());
    }

    #[Test]
    public function r3_no_delivered_identifier_contains_central(): void
    {
        $deliveredFqcns = [
            WorkstationGroupLabelService::class,
            LabelAssignmentException::class,
        ];

        foreach ($deliveredFqcns as $fqcn) {
            self::assertStringNotContainsStringIgnoringCase('central', $fqcn);

            $reflection = new \ReflectionClass($fqcn);
            foreach ($reflection->getMethods() as $method) {
                self::assertStringNotContainsStringIgnoringCase('central', $method->getName(), "Méthode {$fqcn}::{$method->getName()}");
            }
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('central', $property->getName());
            }
        }

        // Colonne livrée : aucun « central ».
        self::assertStringNotContainsStringIgnoringCase('central', 'controlhub_label');
    }
}
