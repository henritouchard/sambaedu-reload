<?php

declare(strict_types=1);

namespace Tests\Feature\GroupRoles;

use App\Models\Pivot\UserGroupUserPivot;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\Filesystem\ShareService;
use Database\Seeders\GroupRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 62.1 — LA PARITÉ SUR CE QUI EST RENDU, pas seulement sur la fonction.
 *
 * `RoleCatalogParityTest` épingle les libellés à la SORTIE du point de lecture.
 * Celui-ci les épingle à l'ÉCRAN : la suppression de la table de libellés a traversé
 * trois vues, et une substitution correcte dans une fonction qui n'atteindrait pas
 * le gabarit serait exactement le genre de régression qu'un test unitaire laisse
 * passer.
 *
 * Deux écrans sont couverts, parce qu'ils ne lisent pas la même chose :
 *  - la page d'un GROUPE de type `classe` (colonne « Rôle » de la table des
 *    membres + liste de choix) ;
 *  - la page d'un UTILISATEUR, où un membre simple ne porte AUCUN badge — cas par
 *    défaut à préserver exactement.
 */
class RenderedRoleLabelsParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->seed(PermissionSeeder::class);
        $this->seed(GroupRoleSeeder::class);

        // Le full-render de la page groupe passe par le partage de classe : le
        // système de fichiers est absent de l'hôte de test.
        $this->app->bind(ShareService::class, function () {
            $mock = Mockery::mock(ShareService::class);
            $mock->shouldReceive('getStatus')->andReturn(['exists' => false]);

            return $mock;
        });

        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        Mockery::close();
        parent::tearDown();
    }

    /** @return array{0:UserGroup,1:User,2:User,3:User} */
    private function makeClasse(): array
    {
        $group = UserGroup::create(['name' => '3A', 'type' => 'classe', 'display_name' => 'Classe 3A']);
        $pp = User::create(['login' => 'prof.pp', 'role' => 'prof', 'fullname' => 'Alice Pp', 'is_active' => true]);
        $prof = User::create(['login' => 'prof.simple', 'role' => 'prof', 'fullname' => 'Bob Simple', 'is_active' => true]);
        $eleve = User::create(['login' => 'eleve.un', 'role' => 'eleve', 'fullname' => 'Chloe Eleve', 'is_active' => true]);

        $group->users()->sync([
            $pp->id => ['role' => UserGroupUserPivot::ROLE_OWNER],
            $prof->id => ['role' => UserGroupUserPivot::ROLE_MANAGER],
            $eleve->id => ['role' => UserGroupUserPivot::ROLE_MEMBER],
        ]);

        return [$group, $pp, $prof, $eleve];
    }

    private function makeAdmin(): User
    {
        $user = User::create(['login' => 'labels-admin', 'role' => 'admin', 'is_active' => true]);
        foreach (['user.read', 'user.modify'] as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    #[Test]
    public function the_class_page_renders_the_school_labels_verbatim(): void
    {
        [$group] = $this->makeClasse();
        $this->actingAs($this->makeAdmin());

        Livewire::test('pages::users.groups.[id].index', ['id' => $group->id])
            ->assertOk()
            ->assertSee('Élève')
            ->assertSee('Enseignant')
            ->assertSee('Professeur principal')
            // Aucune valeur technique rendue comme texte visible.
            ->assertDontSee('>member<', false)
            ->assertDontSee('>manager<', false);
    }

    #[Test]
    public function the_view_model_carries_the_catalog_labels(): void
    {
        [$group, $pp, $prof, $eleve] = $this->makeClasse();
        $this->actingAs($this->makeAdmin());

        // Le view-model est un computed : on le relit sur l'instance plutôt que
        // dans le HTML, pour épingler la valeur EXACTE et pas une sous-chaîne.
        $component = Livewire::test('pages::users.groups.[id].index', ['id' => $group->id]);
        $labels = collect($component->instance()->members)
            ->pluck('edge_role_label', 'login')
            ->all();

        $this->assertSame('Professeur principal', $labels[$pp->login]);
        $this->assertSame('Enseignant', $labels[$prof->login]);
        $this->assertSame('Élève', $labels[$eleve->login]);
    }

    /**
     * PIÈGE NOMMÉ : sur la page d'un utilisateur, un membre SIMPLE ne porte pas
     * de badge — c'est le cas par défaut, le signaler noierait les rôles qui,
     * eux, sont informatifs.
     */
    #[Test]
    public function a_plain_member_still_carries_no_badge_on_the_user_page(): void
    {
        [, $pp, , $eleve] = $this->makeClasse();
        $this->actingAs($this->makeAdmin());

        $component = Livewire::test('pages::users.[login].index', ['login' => $eleve->login]);
        $details = collect($component->instance()->groupDetails);

        $this->assertNotEmpty($details);
        $this->assertNull($details->firstWhere('type', 'classe')['edge_role_label']);

        // Et un rôle qui n'est PAS « membre simple » porte bien son libellé.
        $ppDetails = collect(
            Livewire::test('pages::users.[login].index', ['login' => $pp->login])->instance()->groupDetails
        );
        $this->assertSame('Professeur principal', $ppDetails->firstWhere('type', 'classe')['edge_role_label']);
    }
}
