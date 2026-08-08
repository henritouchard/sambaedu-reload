<?php

declare(strict_types=1);

namespace Tests\Feature\GroupTypes;

use App\Models\DirectoryTemplate;
use App\Models\GroupType;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\Filesystem\Plan\PlanResolver;
use App\Support\GroupTypeCatalog;
use Database\Seeders\GroupRoleSeeder;
use Database\Seeders\GroupTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Services\Filesystem\Plan\ClassTreeRecipe;

/**
 * Story 62.2 — AC8 : un renommage de libellé ou d'icône ne touche AUCUNE donnée
 * dérivée.
 *
 * C'est la matérialisation exécutable de « la clé est immuable, et seule elle est
 * référencée ». On renomme `classe` en « Division », on lui change son icône, et
 * on constate que le plan de fichiers résolu d'un groupe de ce type est identique
 * OCTET POUR OCTET — parce que la résolution passe par `group->type` et
 * `attachedTo()`, qui ne lisent que la clé.
 *
 * Si un jour quelqu'un fait dériver un chemin, un nom de groupe d'annuaire ou une
 * entrée de droit du LIBELLÉ plutôt que de la clé, c'est ce test qui tombe.
 */
class GroupTypeRenameHasNoDerivedEffectTest extends TestCase
{
    use ClassTreeRecipe;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GroupTypeSeeder::class);
        $this->seed(GroupRoleSeeder::class);

        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    #[Test]
    public function renaming_a_type_leaves_groups_templates_and_the_resolved_plan_untouched(): void
    {
        $this->seed(\Database\Seeders\DirectoryTemplateSeeder::class);

        $group = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $user = User::create(['login' => 'alecoz', 'role' => 'prof', 'is_active' => true]);
        DB::table('user_group_user')->insert([
            'user_id' => $user->id,
            'user_group_id' => $group->id,
            'role' => 'manager',
        ]);

        $groupsBefore = DB::table('user_groups')->orderBy('id')->get()->toJson();
        $templatesBefore = DirectoryTemplate::orderBy('key')
            ->get(['key', 'attached_group_type', 'roles_spec', 'nodes_spec', 'path_pattern'])->toJson();

        $resolver = app(PlanResolver::class);
        $planBefore = $resolver->resolve($this->classTreeTemplate(), $this->classTreeContext())->toJson();

        // LE renommage — libellé ET icône.
        $classe = GroupType::where('key', 'classe')->firstOrFail();
        $classe->label = 'Division';
        $classe->icon = 'fa-solid fa-school';
        $classe->save();

        $this->assertSame('Division', GroupTypeCatalog::label('classe'));
        $this->assertSame('fa-solid fa-school', GroupTypeCatalog::icon('classe'));
        $this->assertSame('classe', $classe->fresh()->key);

        // Rien d'autre n'a bougé.
        $this->assertSame(
            $groupsBefore,
            DB::table('user_groups')->orderBy('id')->get()->toJson(),
            'un renommage de libellé a modifié un groupe',
        );
        $this->assertSame(
            $templatesBefore,
            DirectoryTemplate::orderBy('key')
                ->get(['key', 'attached_group_type', 'roles_spec', 'nodes_spec', 'path_pattern'])->toJson(),
            'un renommage de libellé a modifié une recette',
        );

        GroupTypeCatalog::flush();
        $planAfter = $resolver->resolve($this->classTreeTemplate(), $this->classTreeContext())->toJson();

        $this->assertSame(
            $planBefore,
            $planAfter,
            'le plan résolu doit être identique OCTET POUR OCTET : seule la clé est dérivée, jamais le libellé',
        );
    }

    /**
     * L'accrochage continue de s'apparier après renommage : c'est la CLÉ qui
     * apparie, et elle n'a pas bougé.
     */
    #[Test]
    public function the_attachment_still_matches_after_a_rename(): void
    {
        $this->seed(\Database\Seeders\DirectoryTemplateSeeder::class);

        $attachedBefore = DirectoryTemplate::attachedTo('classe');
        $this->assertNotNull($attachedBefore, 'le décor doit comporter une recette d\'arbre accrochée à `classe`');

        GroupType::where('key', 'classe')->firstOrFail()->update(['label' => 'Division']);

        $this->assertSame((string) $attachedBefore->key, (string) DirectoryTemplate::attachedTo('classe')->key);
    }
}
