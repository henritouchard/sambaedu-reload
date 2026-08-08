<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Jobs\ReconcileNetworkShareJob;
use App\Exceptions\Filesystem\InvalidTreeSpecException;
use App\Models\DirectoryTemplate;
use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Filesystem\DirectoryTemplateService;
use App\Services\Filesystem\TemplateMaterializationResult;
use Database\Seeders\DirectoryTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 34.3 — Tests Unit `DirectoryTemplateService::materialize` (T2/T4, AC2).
 *
 * Process::fake() : AUCUN accès FS réel. Les 4 recettes sont seedées depuis la DB
 * (Q3 option B — la recette est LUE en base, pas un enum en dur).
 */
class DirectoryTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    private DirectoryTemplateService $service;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        Queue::fake();
        Process::fake();

        $this->tempRoot = sys_get_temp_dir() . '/netshare-tpl-' . uniqid();
        @mkdir($this->tempRoot, 0o755, true);
        config(['filesystem.shares_root' => $this->tempRoot]);

        (new DirectoryTemplateSeeder())->run();

        $this->service = app(DirectoryTemplateService::class);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempRoot)) {
            @rmdir($this->tempRoot);
        }
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function template(string $key): DirectoryTemplate
    {
        return DirectoryTemplate::where('key', $key)->firstOrFail();
    }

    // =========================================================================
    // Review 62.4 #1 — une recette NON MIGRÉE est refusée, et elle est refusée ICI
    // =========================================================================

    #[Test]
    public function materializing_a_recipe_still_on_the_old_access_vocabulary_is_refused_loudly(): void
    {
        // Le défaut : `assignmentAccessOf()` lisait `roles_spec[].verbs` et
        // retombait sur le plancher « lire » quand la clé était absente. Une ligne
        // restée sur l'ancien vocabulaire (`access`) — restauration d'une
        // sauvegarde antérieure à la migration sans la rejouer, écriture SQL
        // directe — se matérialisait donc SANS erreur, en transformant un rôle en
        // ÉCRITURE en rôle en LECTURE. Silencieusement : exactement ce que la
        // story dit traquer, et ce que son runbook promet bruyant.
        $direction = UserGroup::create(['name' => 'direction', 'type' => 'equipe']);
        $classe = UserGroup::create(['name' => '6eA', 'type' => 'classe']);

        $template = $this->template(DirectoryTemplate::KEY_DIRECTION_TO_ALL);

        // On remet UN rôle sur l'ancienne clé, sans toucher au reste.
        $roles = $template->roles_spec;
        $roles[0]['access'] = 'rw';
        unset($roles[0]['verbs']);
        // Écriture directe : le hook `saving` ne garde que les recettes accrochées,
        // et c'est précisément le trou qu'on épingle.
        DB::table('directory_templates')
            ->where('id', $template->id)
            ->update(['roles_spec' => json_encode($roles)]);

        $this->expectException(InvalidTreeSpecException::class);

        $this->service->materialize($template->fresh(), [
            'name' => 'Publication direction',
            'directory_name' => 'pub_direction',
            'letter' => 'P:',
            'roles' => [
                'source' => [$direction->id],
                'destinataires' => [$classe->id],
            ],
        ]);
    }

    #[Test]
    public function a_refused_recipe_writes_nothing_at_all(): void
    {
        $direction = UserGroup::create(['name' => 'direction', 'type' => 'equipe']);
        $classe = UserGroup::create(['name' => '6eA', 'type' => 'classe']);

        $template = $this->template(DirectoryTemplate::KEY_DIRECTION_TO_ALL);
        $roles = $template->roles_spec;
        $roles[0]['access'] = 'rw';
        unset($roles[0]['verbs']);
        DB::table('directory_templates')
            ->where('id', $template->id)
            ->update(['roles_spec' => json_encode($roles)]);

        try {
            $this->service->materialize($template->fresh(), [
                'name' => 'Publication direction',
                'directory_name' => 'pub_direction',
                'letter' => 'P:',
                'roles' => [
                    'source' => [$direction->id],
                    'destinataires' => [$classe->id],
                ],
            ]);
            self::fail('La matérialisation aurait dû être refusée.');
        } catch (InvalidTreeSpecException) {
            // Le refus tombe AVANT toute écriture : c'est ce qui le rend sûr.
            self::assertSame(0, NetworkShare::where('directory_name', 'pub_direction')->count());
        }
    }

    // =========================================================================
    // Matérialisation par template — assignations + access corrects
    // =========================================================================

    #[Test]
    public function direction_to_all_grants_source_rw_and_destinataires_ro(): void
    {
        $direction = UserGroup::create(['name' => 'direction', 'type' => 'equipe']);
        $classeA = UserGroup::create(['name' => '6eA', 'type' => 'classe']);
        $classeB = UserGroup::create(['name' => '6eB', 'type' => 'classe']);

        $result = $this->service->materialize($this->template(DirectoryTemplate::KEY_DIRECTION_TO_ALL), [
            'name' => 'Publication direction',
            'directory_name' => 'pub_direction',
            'letter' => 'P:',
            'roles' => [
                'source' => [$direction->id],
                'destinataires' => [$classeA->id, $classeB->id],
            ],
        ]);

        $share = $result->share;
        $this->assertSame('pub_direction', $share->directory_name);
        $this->assertAssignment($share, UserGroup::class, $direction->id, 'rw');
        $this->assertAssignment($share, UserGroup::class, $classeA->id, 'ro');
        $this->assertAssignment($share, UserGroup::class, $classeB->id, 'ro');
        $this->assertSame(3, $share->assignments()->count());
    }

    /**
     * Story 60.5 — « profs → élèves » a changé de FLUX, parce qu'elle avait cessé
     * de fonctionner.
     *
     * Elle demandait un groupe de type « équipe » pour son rôle enseignant. Ce type
     * n'est plus produit depuis le repliement 4.13 : le sélecteur était vide, et la
     * recette était impossible à matérialiser — pendant cinq semaines, sans que
     * rien ne le dise. L'équipe enseignante est désormais un RÔLE SUR L'ARÊTE du
     * groupe classe, et la recette se résout à partir d'UN seul groupe.
     *
     * La ligne créée porte donc son ORIGINE (recette + groupe) : ses octrois
     * viendront de la recette, pas de ses assignations. L'assignation restante
     * porte l'autre axe — la VISIBILITÉ du lecteur.
     */
    #[Test]
    public function profs_to_eleves_materializes_from_a_single_class_group(): void
    {
        $classe = UserGroup::create(['name' => '6eB', 'type' => 'classe']);

        $result = $this->service->materialize($this->template(DirectoryTemplate::KEY_PROFS_TO_ELEVES), [
            'name' => 'Devoirs 6eB',
            'directory_name' => 'devoirs_6eb',
            'letter' => 'Q:',
            'group_id' => $classe->id,
        ]);

        $share = $result->share->fresh();

        $this->assertSame($this->template(DirectoryTemplate::KEY_PROFS_TO_ELEVES)->id, $share->directory_template_id);
        $this->assertSame($classe->id, $share->user_group_id);
        $this->assertTrue($share->hasRecipeOrigin());

        // La visibilité : le groupe classe, dont les enseignants sont membres.
        $this->assertAssignment($share, UserGroup::class, $classe->id, 'ro');
        $this->assertCount(1, $share->assignments);
    }

    /** Un groupe du mauvais type est refusé AVANT toute écriture. */
    #[Test]
    public function profs_to_eleves_refuses_a_materialization_group_of_the_wrong_type(): void
    {
        $projet = UserGroup::create(['name' => 'Robotique', 'type' => 'projet']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/type « classe »/u');

        $this->service->materialize($this->template(DirectoryTemplate::KEY_PROFS_TO_ELEVES), [
            'name' => 'Devoirs',
            'directory_name' => 'devoirs_ko',
            'group_id' => $projet->id,
        ]);
    }

    /** Et l'absence de groupe le dit, plutôt que de créer un partage vide. */
    #[Test]
    public function an_auto_resolvable_recipe_asks_for_its_materialization_group(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Choisissez le groupe/u');

        $this->service->materialize($this->template(DirectoryTemplate::KEY_PROFS_TO_ELEVES), [
            'name' => 'Devoirs',
            'directory_name' => 'devoirs_sans_groupe',
        ]);
    }

    #[Test]
    public function user_to_user_grants_both_users_rw(): void
    {
        $a = User::factory()->create(['login' => 'alice']);
        $b = User::factory()->create(['login' => 'bob']);

        $result = $this->service->materialize($this->template(DirectoryTemplate::KEY_USER_TO_USER), [
            'name' => 'Échange Alice/Bob',
            'directory_name' => 'echange_ab',
            'letter' => 'R:',
            'roles' => [
                'user_a' => [$a->id],
                'user_b' => [$b->id],
            ],
        ]);

        $this->assertAssignment($result->share, User::class, $a->id, 'rw');
        $this->assertAssignment($result->share, User::class, $b->id, 'rw');
    }

    #[Test]
    public function group_space_grants_group_rw(): void
    {
        $group = UserGroup::create(['name' => 'cdi', 'type' => 'autre']);

        $result = $this->service->materialize($this->template(DirectoryTemplate::KEY_GROUP_SPACE), [
            'name' => 'Espace CDI',
            'directory_name' => 'espace_cdi',
            'letter' => 'S:',
            'roles' => ['group' => [$group->id]],
        ]);

        $this->assertAssignment($result->share, UserGroup::class, $group->id, 'rw');
        $this->assertSame(1, $result->share->assignments()->count());
    }

    #[Test]
    public function materialize_provisions_after_commit(): void
    {
        $group = UserGroup::create(['name' => 'cdi', 'type' => 'autre']);

        $result = $this->service->materialize($this->template(DirectoryTemplate::KEY_GROUP_SPACE), [
            'name' => 'Espace CDI',
            'directory_name' => 'espace_cdi',
            'roles' => ['group' => [$group->id]],
        ]);

        $this->assertSame(TemplateMaterializationResult::PROVISIONING_APPLIED, $result->provisioning);
        $this->assertFalse($result->isFailure());
        Process::assertRan(fn ($p): bool => str_contains($p->command, 'mkdir -p') && str_contains($p->command, 'espace_cdi'));
    }

    /**
     * Story 60.4 — appelée depuis un écran, la matérialisation ENFILE la pose des
     * droits et le DIT. Aucune écriture n'a lieu dans le cycle de la requête, et le
     * résultat n'affirme pas un provisionnement accompli qui ne l'est pas.
     */
    #[Test]
    public function materialize_from_a_screen_queues_the_provisioning_and_says_so(): void
    {
        $group = UserGroup::create(['name' => 'cdi', 'type' => 'autre']);

        $result = $this->service->materialize($this->template(DirectoryTemplate::KEY_GROUP_SPACE), [
            'name' => 'Espace CDI',
            'directory_name' => 'espace_cdi',
            'roles' => ['group' => [$group->id]],
        ], deferProvisioning: true);

        $this->assertSame(TemplateMaterializationResult::PROVISIONING_QUEUED, $result->provisioning);
        $this->assertFalse($result->isFailure());
        Queue::assertPushed(ReconcileNetworkShareJob::class);
        Process::assertNotRan(fn ($p): bool => str_contains($p->command, 'mkdir'));
    }

    // =========================================================================
    // Validation AVANT écriture + collision (rollback)
    // =========================================================================

    #[Test]
    public function reserved_letter_is_refused_before_any_write(): void
    {
        $group = UserGroup::create(['name' => 'cdi', 'type' => 'autre']);

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->service->materialize($this->template(DirectoryTemplate::KEY_GROUP_SPACE), [
                'name' => 'Pirate',
                'directory_name' => 'pirate',
                'letter' => 'K:', // réservée (home)
                'roles' => ['group' => [$group->id]],
            ]);
        } finally {
            $this->assertDatabaseCount('network_shares', 0);
            Process::assertNothingRan();
        }
    }

    #[Test]
    public function invalid_directory_name_is_refused_before_any_write(): void
    {
        $group = UserGroup::create(['name' => 'cdi', 'type' => 'autre']);

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->service->materialize($this->template(DirectoryTemplate::KEY_GROUP_SPACE), [
                'name' => 'Bad',
                'directory_name' => '../escape',
                'roles' => ['group' => [$group->id]],
            ]);
        } finally {
            $this->assertDatabaseCount('network_shares', 0);
            Process::assertNothingRan();
        }
    }

    #[Test]
    public function letter_collision_rolls_back_share_and_pivot(): void
    {
        // Répertoire existant P: avec une audience qui recouvrira la nouvelle.
        $group = UserGroup::create(['name' => 'cdi', 'type' => 'autre']);
        $existing = NetworkShare::factory()->create(['directory_name' => 'existant', 'letter' => 'P:']);
        NetworkShareAssignable::create([
            'network_share_id' => $existing->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'access' => 'ro',
        ]);

        $sharesBefore = NetworkShare::count();
        $pivotBefore = NetworkShareAssignable::count();

        try {
            $this->service->materialize($this->template(DirectoryTemplate::KEY_GROUP_SPACE), [
                'name' => 'Collision',
                'directory_name' => 'collision',
                'letter' => 'P:', // MÊME lettre, même groupe → collision
                'roles' => ['group' => [$group->id]],
            ]);
            $this->fail('Une collision de lettre aurait dû être levée.');
        } catch (\App\Exceptions\Filesystem\NetworkShareLetterCollisionException $e) {
            // attendu
        }

        // Rollback complet : aucune ligne créée.
        $this->assertSame($sharesBefore, NetworkShare::count());
        $this->assertSame($pivotBefore, NetworkShareAssignable::count());
        $this->assertDatabaseMissing('network_shares', ['directory_name' => 'collision']);
    }

    // =========================================================================
    // Cardinalité / typage des cibles
    // =========================================================================

    #[Test]
    public function cardinality_one_requires_exactly_one_target(): void
    {
        $a = User::factory()->create(['login' => 'alice']);

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->service->materialize($this->template(DirectoryTemplate::KEY_USER_TO_USER), [
                'name' => 'X',
                'directory_name' => 'xx',
                'roles' => [
                    'user_a' => [$a->id],
                    'user_b' => [], // manquant
                ],
            ]);
        } finally {
            $this->assertDatabaseCount('network_shares', 0);
        }
    }

    #[Test]
    public function group_type_mismatch_is_refused(): void
    {
        // profs_to_eleves attend un `equipe` pour 'profs' et `classe' pour 'eleves'.
        $equipe = UserGroup::create(['name' => 'profs', 'type' => 'equipe']);
        $notAClasse = UserGroup::create(['name' => 'admins', 'type' => 'autre']);

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->service->materialize($this->template(DirectoryTemplate::KEY_PROFS_TO_ELEVES), [
                'name' => 'X',
                'directory_name' => 'xx',
                'roles' => [
                    'profs' => [$equipe->id],
                    'eleves' => [$notAClasse->id], // mauvais type
                ],
            ]);
        } finally {
            $this->assertDatabaseCount('network_shares', 0);
        }
    }

    #[Test]
    public function missing_target_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->materialize($this->template(DirectoryTemplate::KEY_GROUP_SPACE), [
            'name' => 'X',
            'directory_name' => 'xx',
            'roles' => ['group' => [999999]], // inexistant
        ]);
    }

    // =========================================================================
    // Invariant WG-montage-seul — aucune recette ne grant un parc
    // =========================================================================

    #[Test]
    public function no_seeded_recipe_grants_a_workstation_group(): void
    {
        foreach (DirectoryTemplate::all() as $tpl) {
            $this->assertTrue(
                $tpl->respectsMountOnlyInvariant(),
                "La recette {$tpl->key} ne doit porter aucune maille WorkstationGroup.",
            );
            foreach ($tpl->roles() as $role) {
                $this->assertContains($role['maille'], DirectoryTemplate::ALLOWED_ROLE_MAILLES);
            }
        }
    }

    private function assertAssignment(NetworkShare $share, string $type, int $id, string $access): void
    {
        $this->assertDatabaseHas('network_share_assignables', [
            'network_share_id' => $share->id,
            'assignable_type' => $type,
            'assignable_id' => $id,
            'access' => $access,
        ]);
    }
}
