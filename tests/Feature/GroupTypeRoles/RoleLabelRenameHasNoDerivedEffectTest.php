<?php

declare(strict_types=1);

namespace Tests\Feature\GroupTypeRoles;

use App\Models\DirectoryTemplate;
use App\Models\GroupTypeRole;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\Filesystem\Plan\PlanResolver;
use App\Support\RoleCatalog;
use Database\Seeders\GroupRoleSeeder;
use Database\Seeders\GroupTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Services\Filesystem\Plan\ClassTreeRecipe;

/**
 * Story 62.3 — AC9 : renommer un libellé LOCAL ne touche AUCUNE donnée dérivée.
 *
 * C'est le pendant exact de `GroupTypeRenameHasNoDerivedEffectTest` (62.2) et de
 * son homologue 62.1, et il ferme la boucle sur le dernier objet administrable de
 * l'epic. On renomme `classe`×`manager` « Enseignant » → « Professeur », et on
 * constate que le plan de fichiers résolu est identique OCTET POUR OCTET : la
 * résolution ne lit que des CLÉS, jamais un libellé.
 *
 * Si un jour quelqu'un fait dériver un chemin, un nom de groupe d'annuaire ou une
 * entrée de droit du libellé local, c'est ce test qui tombe.
 */
class RoleLabelRenameHasNoDerivedEffectTest extends TestCase
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
    public function renaming_a_local_label_leaves_edges_templates_and_the_resolved_plan_untouched(): void
    {
        $this->seed(\Database\Seeders\DirectoryTemplateSeeder::class);

        $group = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $user = User::create(['login' => 'alecoz', 'role' => 'prof', 'is_active' => true]);
        DB::table('user_group_user')->insert([
            'user_id' => $user->id,
            'user_group_id' => $group->id,
            'role' => 'manager',
        ]);

        $edgesBefore = DB::table('user_group_user')->orderBy('user_id')->get()->toJson();
        $templatesBefore = DirectoryTemplate::orderBy('key')
            ->get(['key', 'attached_group_type', 'roles_spec', 'nodes_spec', 'path_pattern'])->toJson();

        $resolver = app(PlanResolver::class);
        $planBefore = $resolver->resolve($this->classTreeTemplate(), $this->classTreeContext())->toJson();

        // LE renommage local.
        GroupTypeRole::where('group_type_key', 'classe')
            ->where('group_role_key', 'manager')
            ->firstOrFail()
            ->update(['label' => 'Professeur']);

        $this->assertSame('Professeur', RoleCatalog::label('classe', 'manager'));

        $this->assertSame(
            $edgesBefore,
            DB::table('user_group_user')->orderBy('user_id')->get()->toJson(),
            'un renommage de libellé local a modifié une appartenance',
        );
        $this->assertSame(
            $templatesBefore,
            DirectoryTemplate::orderBy('key')
                ->get(['key', 'attached_group_type', 'roles_spec', 'nodes_spec', 'path_pattern'])->toJson(),
            'un renommage de libellé local a modifié une recette',
        );

        RoleCatalog::flush();
        $planAfter = $resolver->resolve($this->classTreeTemplate(), $this->classTreeContext())->toJson();

        $this->assertSame(
            $planBefore,
            $planAfter,
            'le plan résolu doit être identique OCTET POUR OCTET : seule la clé est dérivée, jamais le libellé',
        );
    }

    /**
     * Déclarer ou retirer une déclaration ne touche pas davantage le plan : la
     * déclaration décrit ce qui est ATTRIBUABLE, pas ce qui est résolu.
     */
    #[Test]
    public function declaring_or_undeclaring_a_role_leaves_the_resolved_plan_identical(): void
    {
        $resolver = app(PlanResolver::class);
        $planBefore = $resolver->resolve($this->classTreeTemplate(), $this->classTreeContext())->toJson();

        GroupTypeRole::where('group_type_key', 'classe')
            ->where('group_role_key', 'owner')
            ->firstOrFail()
            ->delete();

        RoleCatalog::flush();

        $this->assertSame(['member', 'manager'], RoleCatalog::assignableKeys('classe'));
        $this->assertSame(
            $planBefore,
            $resolver->resolve($this->classTreeTemplate(), $this->classTreeContext())->toJson(),
            'la déclaration ne doit jamais entrer dans la résolution de plan',
        );
    }
}
