<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Repositories\GroupRepository;
use App\Repositories\RightRepository;
use App\Repositories\UserGroupRepository;
use App\Services\UserGroupService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 49.1 — la colonne `user_groups.rights_profile_id`, et le fait qu'elle
 * reste VIDE tant qu'un administrateur n'en décide pas autrement.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Ce fichier a remplacé `GroupRightsProfileSeedTest` (2026-08-03, décision
 * Henri) : il vérifiait un seed `Profs`→`prof` / `Eleves`→`eleve`, en deux
 * volets — migration de données pour les parcs existants, défaut à la création
 * du groupe à l'import pour les installations neuves. Les deux ont été
 * SUPPRIMÉS. Les tests sont donc devenus leur propre inverse : ils verrouillent
 * désormais l'ABSENCE de seed.
 *
 * Les deux raisons du retrait, à connaître avant de « rétablir » quoi que ce
 * soit ici :
 *
 *  1. **Aucune installation ne le justifiait.** Le produit ne tourne que sur les
 *     environnements de développement et de lab ; le geste d'amorçage y a été
 *     fait à la main. Embarquer à perpétuité, dans l'histoire du schéma, une
 *     migration de données qui ne s'appliquera jamais nulle part est une dette
 *     gratuite.
 *  2. **`Profs` et `Eleves` sont des noms du vertical scolaire.** Le défaut à
 *     l'import était le dernier littéral scolaire câblé dans un service du
 *     produit : dans une administration, ces groupes n'existent pas, et un
 *     groupe qui porterait par hasard l'un de ces noms recevrait des droits que
 *     personne n'a demandés.
 *
 * Le geste d'amorçage est celui du produit : onglet Profils de
 * `/app/rights-management` → « Donner des permissions à un groupe », qui pose le
 * lien ET re-projette les membres dans le même geste (`setProfile()`).
 */
class GroupRightsProfileMigrationTest extends TestCase
{
    use CreatesPermissionSchema;

    private const MIGRATION = 'database/migrations/2026_08_01_100000_add_rights_profile_to_user_groups_table.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPermissionSchema();
        Queue::fake();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        UserGroupUserPivotObserver::disableProfileReconcile();
    }

    protected function tearDown(): void
    {
        UserGroupUserPivotObserver::enableProfileReconcile();
        UserGroupUserPivotObserver::enableSync();
        UserGroupObserver::enableSync();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function runMigration(): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->up();
    }

    private function makeGroup(string $name, ?int $roleId = null): UserGroup
    {
        return UserGroup::create([
            'name' => $name,
            'display_name' => $name,
            'type' => 'role',
            'rights_profile_id' => $roleId,
        ]);
    }

    private function adGroup(string $cn): array
    {
        return [
            'cn' => $cn,
            'dn' => "CN={$cn},OU=Groups,DC=example,DC=local",
            'description' => $cn,
        ];
    }

    private function makeImportService(Collection $adGroups): UserGroupService
    {
        $groupRepository = $this->createMock(GroupRepository::class);
        $groupRepository->method('getGroupsWithMemberCount')->willReturn($adGroups);
        $groupRepository->method('getGroupMembers')->willReturn(collect());

        $rightRepository = $this->createMock(RightRepository::class);
        $rightRepository->method('getAllRightsValues')->willReturn([]);

        return new UserGroupService(
            new UserGroupRepository(),
            $groupRepository,
            $rightRepository,
        );
    }

    // ========================================================================
    // La migration est PUREMENT structurelle
    // ========================================================================

    #[Test]
    public function the_migration_adds_a_nullable_restrict_on_delete_column(): void
    {
        $source = file_get_contents(base_path(self::MIGRATION));

        self::assertStringContainsString("constrained('roles')", $source);
        self::assertStringContainsString('restrictOnDelete()', $source);
        self::assertStringContainsString('nullable()', $source);
    }

    /**
     * LE test de ce fichier : même dans la configuration où l'ancien seed
     * mordait — groupes `Profs`/`Eleves` présents ET rôles `prof`/`eleve`
     * existants —, la migration ne pose plus rien.
     */
    #[Test]
    public function the_migration_never_links_any_group_to_a_profile(): void
    {
        Role::create(['name' => 'prof', 'guard_name' => 'web']);
        Role::create(['name' => 'eleve', 'guard_name' => 'web']);

        $profs = $this->makeGroup('Profs');
        $eleves = $this->makeGroup('Eleves');
        $administratifs = $this->makeGroup('Administratifs');

        $this->runMigration();

        self::assertNull(UserGroup::find($profs->id)->rights_profile_id);
        self::assertNull(UserGroup::find($eleves->id)->rights_profile_id);
        self::assertNull(UserGroup::find($administratifs->id)->rights_profile_id);
        self::assertSame(
            0,
            UserGroup::whereNotNull('rights_profile_id')->count(),
            'la migration est structurelle : elle ne pose AUCUN profil'
        );
    }

    #[Test]
    public function the_migration_leaves_an_administrator_choice_untouched(): void
    {
        $gestionnaire = Role::create(['name' => 'gestionnaire', 'guard_name' => 'web']);
        $compta = $this->makeGroup('Comptabilite', $gestionnaire->id);

        $this->runMigration();

        self::assertSame(
            $gestionnaire->id,
            UserGroup::find($compta->id)->rights_profile_id,
            'un lien posé par un administrateur survit à un rejeu de la migration'
        );
    }

    #[Test]
    public function the_migration_is_guarded_against_a_double_run(): void
    {
        $this->runMigration();
        $this->runMigration();

        self::assertTrue(Schema::hasColumn('user_groups', 'rights_profile_id'));
        self::assertSame(0, DB::table('user_groups')->count());
    }

    // ========================================================================
    // L'import AD ne pose aucun profil
    // ========================================================================

    #[Test]
    public function importing_profs_and_eleves_never_poses_a_profile(): void
    {
        Role::create(['name' => 'prof', 'guard_name' => 'web']);
        Role::create(['name' => 'eleve', 'guard_name' => 'web']);

        $this->makeImportService(collect([
            $this->adGroup('Profs'),
            $this->adGroup('Eleves'),
            $this->adGroup('Administratifs'),
        ]))->syncFromAd();

        self::assertNull(UserGroup::where('name', 'Profs')->value('rights_profile_id'));
        self::assertNull(UserGroup::where('name', 'Eleves')->value('rights_profile_id'));
        self::assertNull(UserGroup::where('name', 'Administratifs')->value('rights_profile_id'));
    }

    #[Test]
    public function a_reimport_never_touches_a_profile_posed_by_an_administrator(): void
    {
        $prof = Role::create(['name' => 'prof', 'guard_name' => 'web']);
        $service = $this->makeImportService(collect([$this->adGroup('Profs')]));

        $service->syncFromAd();
        // L'administrateur pose le profil depuis l'onglet Profils.
        UserGroup::where('name', 'Profs')->update(['rights_profile_id' => $prof->id]);

        $service->syncFromAd();

        self::assertSame(
            $prof->id,
            UserGroup::where('name', 'Profs')->value('rights_profile_id'),
            'la branche update de l\'import ne touche jamais le profil porté'
        );
    }

    // ========================================================================
    // Verrou anti-réintroduction
    // ========================================================================

    /**
     * Le vrai risque n'est pas qu'on rétablisse le seed sciemment — c'est qu'un
     * « défaut bien intentionné » revienne se loger dans le service d'import,
     * là où il était. Ce verrou échoue au premier littéral scolaire réintroduit
     * dans du code exécutable.
     */
    #[Test]
    public function no_school_literal_maps_a_group_name_to_a_rights_profile(): void
    {
        $sources = [
            'UserGroupService' => file_get_contents(app_path('Services/UserGroupService.php')),
            'migration' => file_get_contents(base_path(self::MIGRATION)),
        ];

        foreach ($sources as $label => $source) {
            // On retire les commentaires : ils EXPLIQUENT le retrait et citent
            // donc légitimement les noms scolaires.
            $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);

            foreach (["=> 'prof'", "=> 'eleve'", "'profs' =>", "'eleves' =>", "'Profs' =>", "'Eleves' =>"] as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $code,
                    "{$label} : correspondance nom-de-groupe → profil réintroduite ({$needle}). "
                        . 'Un profil de droits s\'attribue depuis l\'onglet Profils, jamais par défaut automatique.'
                );
            }
        }
    }
}
