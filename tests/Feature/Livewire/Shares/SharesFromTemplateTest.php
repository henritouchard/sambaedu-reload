<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Shares;

use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\DirectoryTemplate;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use Database\Seeders\DirectoryTemplateSeeder;
use App\Jobs\ReconcileNetworkShareJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\Traits\InstallsCollegeRoleProfile;

/**
 * Story 34.3 — Tests Feature Livewire de la modale « Créer depuis un template »
 * (T3/T4, AC3) : formulaire dynamique, aperçu, matérialisation, gating policy.
 */
class SharesFromTemplateTest extends TestCase
{
    use InstallsCollegeRoleProfile;
    use RefreshDatabase;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        Queue::fake();
        Process::fake();

        $this->tempRoot = sys_get_temp_dir() . '/netshare-tplui-' . uniqid();
        @mkdir($this->tempRoot, 0o755, true);
        config(['filesystem.shares_root' => $this->tempRoot]);

        foreach (['networkshare.view', 'networkshare.manage'] as $p) {
            Permission::findOrCreate($p, 'web');
        }

        (new DirectoryTemplateSeeder())->run();
    }

    protected function tearDown(): void
    {
        @rmdir($this->tempRoot);
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private const PAGE = 'pages::admin.shares.index';

    private function manager(): User
    {
        $u = User::create(['login' => 'mgr-' . uniqid(), 'role' => 'autre', 'is_active' => true]);
        $u->givePermissionTo('networkshare.view');
        $u->givePermissionTo('networkshare.manage');

        return $u;
    }

    private function viewerOnly(): User
    {
        $u = User::create(['login' => 'view-' . uniqid(), 'role' => 'autre', 'is_active' => true]);
        $u->givePermissionTo('networkshare.view');

        return $u;
    }

    #[Test]
    public function open_template_prefills_letter_and_lists_templates(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->assertSet('isTemplateOpen', true)
            ->assertSet('templateLetter', 'M:')
            ->assertSee('Profs → élèves');
    }

    #[Test]
    public function dynamic_form_shows_roles_of_selected_template(): void
    {
        UserGroup::create(['name' => 'direction', 'type' => 'equipe']);
        UserGroup::create(['name' => '6eB', 'type' => 'classe']);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'direction_to_all')
            ->assertSee('Source (direction / équipe qui publie)')
            ->assertSee('Destinataires');
    }

    /**
     * Story 60.5 — « profs → élèves » ne demande plus qu'UN groupe : ses deux rôles
     * s'en déduisent. L'aperçu dit les AUDIENCES résolues, pas la saisie.
     */
    #[Test]
    public function an_auto_resolvable_recipe_asks_for_a_single_group_and_previews_resolved_audiences(): void
    {
        // Story 62.3 — l'aperçu lit le vocabulaire DÉCLARÉ du type. « Enseignant »
        // n'est plus posé par la migration : c'est un profil qu'on installe. Ce
        // test l'installe donc, faute de quoi il mesurerait le régime de repli
        // (« Gestionnaire ») au lieu de ce qu'il prétend vérifier.
        $this->installCollegeRoleProfile();

        $classe = UserGroup::create(['name' => '6eB', 'type' => 'classe']);
        $this->actingAs($this->manager());

        $component = Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'profs_to_eleves')
            ->assertSee('Groupe de matérialisation')
            ->set('materializationGroupId', $classe->id);

        $preview = $component->instance()->templatePreview();

        $this->assertCount(2, $preview);
        $labels = array_column($preview, 'label');
        // Story 62.3 — DIVERGENCE NOMMÉE ET ASSUMÉE (AC6). L'aperçu disait
        // « encadrants » : un `match` local, écrit dans cette vue seule, qui
        // ignorait le type du groupe et fondait tout rôle inconnu dans
        // « membres ». Il lit désormais le vocabulaire DÉCLARÉ du type — sur une
        // classe, `manager` se dit « Enseignant », comme partout ailleurs dans
        // l'application, et comme l'administrateur peut le renommer.
        $this->assertContains('6eB — Enseignant', $labels, 'l\'audience enseignante est un RÔLE D\'ARÊTE, dit comme le type le déclare');
        $this->assertContains('6eB', $labels);
    }

    #[Test]
    public function preview_reflects_selected_targets(): void
    {
        $direction = UserGroup::create(['name' => 'direction', 'type' => 'equipe']);
        $classe = UserGroup::create(['name' => '6eB', 'type' => 'classe']);
        $this->actingAs($this->manager());

        $component = Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'direction_to_all')
            ->set('roleSelections.source', $direction->id)
            ->set('roleSelections.destinataires', [$classe->id]);

        $preview = $component->instance()->templatePreview();
        $this->assertCount(2, $preview);
        $labels = array_column($preview, 'label');
        $this->assertContains('direction', $labels);
        $this->assertContains('6eB', $labels);
    }

    #[Test]
    public function materializes_a_share_with_assignments_and_provisions(): void
    {
        $classe = UserGroup::create(['name' => '6eB', 'type' => 'classe']);
        $admin = $this->manager();
        $this->actingAs($admin);

        Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'profs_to_eleves')
            ->set('templateName', 'Devoirs 6eB')
            ->set('templateDirectoryName', 'devoirs_6eb')
            ->set('templateLetter', 'P:')
            ->set('materializationGroupId', $classe->id)
            ->call('createFromTemplate')
            ->assertHasNoErrors();

        $share = NetworkShare::where('directory_name', 'devoirs_6eb')->first();
        $this->assertNotNull($share);
        $this->assertSame($admin->id, $share->created_by_user_id);
        // Story 60.5 — le partage porte son ORIGINE : ses octrois viendront de la
        // recette, l'assignation ne porte que la visibilité du lecteur.
        $this->assertTrue($share->hasRecipeOrigin());
        $this->assertSame($classe->id, $share->user_group_id);
        $this->assertDatabaseHas('network_share_assignables', [
            'network_share_id' => $share->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $classe->id,
            'access' => 'ro',
        ]);
        // Story 60.4 — la matérialisation depuis un écran ENFILE la pose des
        // droits : rien n'est écrit dans le cycle de la requête.
        Process::assertNothingRan();
        Queue::assertPushed(
            ReconcileNetworkShareJob::class,
            fn (ReconcileNetworkShareJob $job): bool => $job->shareId === (int) $share->id,
        );
    }

    #[Test]
    public function user_to_user_materializes_two_rw_grants(): void
    {
        $a = User::create(['login' => 'alice', 'role' => 'prof', 'is_active' => true]);
        $b = User::create(['login' => 'bob', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'user_to_user')
            ->set('templateName', 'Échange AB')
            ->set('templateDirectoryName', 'echange_ab')
            ->set('roleSelections.user_a', $a->id)
            ->set('roleSelections.user_b', $b->id)
            ->call('createFromTemplate')
            ->assertHasNoErrors();

        $share = NetworkShare::where('directory_name', 'echange_ab')->firstOrFail();
        $this->assertSame(2, NetworkShareAssignable::where('network_share_id', $share->id)->where('access', 'rw')->count());
    }

    #[Test]
    public function rejects_reserved_letter(): void
    {
        $group = UserGroup::create(['name' => 'cdi', 'type' => 'autre']);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'group_space')
            ->set('templateName', 'Pirate')
            ->set('templateDirectoryName', 'pirate')
            ->set('templateLetter', 'K:')
            ->set('roleSelections.group', $group->id)
            ->call('createFromTemplate')
            ->assertHasErrors(['templateLetter']);

        $this->assertDatabaseCount('network_shares', 0);
    }

    #[Test]
    public function rejects_malformed_directory_name(): void
    {
        $group = UserGroup::create(['name' => 'cdi', 'type' => 'autre']);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'group_space')
            ->set('templateName', 'Bad')
            ->set('templateDirectoryName', 'bad name/../x')
            ->set('roleSelections.group', $group->id)
            ->call('createFromTemplate')
            ->assertHasErrors(['templateDirectoryName']);

        $this->assertDatabaseCount('network_shares', 0);
    }

    #[Test]
    public function rejects_duplicate_directory_name_with_clear_message(): void
    {
        NetworkShare::factory()->create(['directory_name' => 'devoirs_6eb']);
        $group = UserGroup::create(['name' => 'cdi', 'type' => 'autre']);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'group_space')
            ->set('templateName', 'Dup')
            ->set('templateDirectoryName', 'devoirs_6eb')
            ->set('roleSelections.group', $group->id)
            ->call('createFromTemplate')
            ->assertHasErrors(['templateDirectoryName']);
    }

    #[Test]
    public function letter_collision_surfaces_toast_error_and_creates_nothing(): void
    {
        $group = UserGroup::create(['name' => 'cdi', 'type' => 'autre']);
        $existing = NetworkShare::factory()->create(['directory_name' => 'existant', 'letter' => 'P:']);
        NetworkShareAssignable::create([
            'network_share_id' => $existing->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'access' => 'ro',
        ]);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'group_space')
            ->set('templateName', 'Collision')
            ->set('templateDirectoryName', 'collision')
            ->set('templateLetter', 'P:')
            ->set('roleSelections.group', $group->id)
            ->call('createFromTemplate')
            ->assertDispatched('toastMagic');

        $this->assertDatabaseMissing('network_shares', ['directory_name' => 'collision']);
    }

    #[Test]
    public function missing_required_target_surfaces_toast_error_and_creates_nothing(): void
    {
        // Finding #2 (review) — la cardinalité des rôles est validée côté service
        // (pas de règle Livewire field-level) : un rôle requis non renseigné
        // remonte en toast erreur (InvalidArgumentException → toastMagic), AUCUN
        // share/pivot n'est créé (refus AVANT transaction).
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'group_space')
            ->set('templateName', 'Sans cible')
            ->set('templateDirectoryName', 'sans_cible')
            // roleSelections.group volontairement NON renseigné
            ->call('createFromTemplate')
            ->assertHasNoErrors()
            ->assertDispatched('toastMagic');

        $this->assertDatabaseCount('network_shares', 0);
        $this->assertDatabaseCount('network_share_assignables', 0);
    }

    #[Test]
    public function viewer_cannot_open_or_materialize(): void
    {
        $group = UserGroup::create(['name' => 'cdi', 'type' => 'autre']);
        $this->actingAs($this->viewerOnly());

        Livewire::test(self::PAGE)
            ->set('selectedTemplateKey', 'group_space')
            ->set('templateName', 'Nope')
            ->set('templateDirectoryName', 'nope')
            ->set('roleSelections.group', $group->id)
            ->call('createFromTemplate')
            ->assertStatus(403);

        $this->assertDatabaseCount('network_shares', 0);
    }

    // =========================================================================
    // Story 60.5 — UN SÉLECTEUR VIDE DOIT LE DIRE
    // =========================================================================

    /**
     * **Le cas régressif, celui qui a coûté cinq semaines.** Une recette dont un
     * rôle contraint un type de groupe SANS AUCUNE occurrence affichait un
     * sélecteur vide et muet : ni message, ni bouton désactivé, ni journal. On ne
     * pouvait ni matérialiser la recette, ni comprendre pourquoi.
     */
    #[Test]
    public function an_empty_role_picker_says_why_and_disables_the_materialization(): void
    {
        // Aucun groupe d'aucun type : le rôle « source » de la publication
        // descendante n'a rien à proposer.
        $this->actingAs($this->manager());

        $component = Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'direction_to_all');

        $notices = $component->instance()->emptyPickerNotices();

        $this->assertArrayHasKey('source', $notices);
        $this->assertStringContainsString('Aucun', $notices['source']);
        $this->assertTrue($component->instance()->materializationBlocked());

        $component->assertSee('Aucun candidat éligible pour ce rôle');
    }

    /**
     * Le cas EXACT du défaut : un rôle qui attend un type de groupe disparu. Le
     * message NOMME le type attendu — c'est ce qui rend le diagnostic possible sans
     * lire le code du seeder.
     */
    #[Test]
    public function a_role_constrained_to_a_vanished_group_type_names_that_type(): void
    {
        $template = \App\Models\DirectoryTemplate::where('key', 'direction_to_all')->firstOrFail();
        $roles = $template->roles_spec;
        $roles[0]['group_type'] = 'equipe'; // type que l'import ne produit plus
        $template->roles_spec = $roles;
        $template->save();

        UserGroup::create(['name' => '6eB', 'type' => 'classe']);
        $this->actingAs($this->manager());

        $component = Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'direction_to_all');

        $notices = $component->instance()->emptyPickerNotices();

        $this->assertArrayHasKey('source', $notices);
        $this->assertStringContainsString('equipe', $notices['source']);
    }

    /** Une matérialisation bloquée est REFUSÉE, même si le geste est déclenché. */
    #[Test]
    public function a_blocked_materialization_is_refused_server_side_too(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'direction_to_all')
            ->set('templateName', 'Publication')
            ->set('templateDirectoryName', 'publication')
            ->call('createFromTemplate');

        $this->assertDatabaseCount('network_shares', 0);
    }

    /** Et quand tout va bien, aucun message ne s'affiche : la garde ne crie pas. */
    #[Test]
    public function a_populated_picker_stays_silent(): void
    {
        UserGroup::create(['name' => 'direction', 'type' => 'equipe']);
        UserGroup::create(['name' => '6eB', 'type' => 'classe']);
        $this->actingAs($this->manager());

        $component = Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'direction_to_all');

        $this->assertSame([], $component->instance()->emptyPickerNotices());
        $this->assertFalse($component->instance()->materializationBlocked());
    }

    /**
     * Le sélecteur de groupe de matérialisation obéit à la MÊME règle : sans classe
     * sur l'instance, il le dit.
     */
    #[Test]
    public function an_empty_materialization_picker_says_why_too(): void
    {
        $this->actingAs($this->manager());

        $component = Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'profs_to_eleves');

        $notices = $component->instance()->emptyPickerNotices();

        $this->assertArrayHasKey('@groupe', $notices);
        $this->assertStringContainsString('classe', $notices['@groupe']);
        $this->assertTrue($component->instance()->materializationBlocked());
    }

    // =========================================================================
    // L'écran manuel ne propose QUE ce qu'il sait faire naître
    // =========================================================================

    /**
     * Une recette d'arbre tient son nom et son emplacement de son groupe ; cet
     * écran, lui, fait naître un partage à partir d'un nom saisi et d'une lettre.
     * Les mélanger produisait deux issues, toutes deux fausses : l'unicité de la
     * ligne cassait en erreur non rattrapée quand l'arbre existait déjà, ou l'écran
     * fabriquait un partage au nom arbitraire ET AVEC UNE LETTRE, alors qu'un arbre
     * naît sans lettre.
     */
    #[Test]
    public function the_manual_picker_never_offers_a_self_materialising_recipe(): void
    {
        $this->actingAs($this->manager());

        $component = Livewire::test(self::PAGE)->call('openTemplate');

        $keys = array_column($component->instance()->templates(), 'key');

        $this->assertNotContains(DirectoryTemplate::KEY_CLASSE_SE4, $keys);
        $this->assertContains('profs_to_eleves', $keys, 'les recettes manuelles restent proposées');
    }

    /**
     * La clé arrive du navigateur : filtrer la liste rendue ne suffit pas. Une
     * garde qui ne vit que dans l'affichage protège l'étourderie, pas la requête
     * forgée — et c'est le chemin par lequel un partage fantôme letré naissait.
     */
    #[Test]
    public function a_forged_key_for_a_self_materialising_recipe_resolves_to_nothing(): void
    {
        $this->actingAs($this->manager());

        $component = Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', DirectoryTemplate::KEY_CLASSE_SE4);

        $this->assertNull($component->instance()->selectedTemplate());
        $this->assertSame([], $component->instance()->materializationCandidates());
    }
}
