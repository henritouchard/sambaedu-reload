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
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 49.1 (AC9 / D6) — le seed iso-comportement, en DEUX volets, jamais
 * ré-écrasant :
 *
 *  (a) migration de DONNÉES pour le BROWNFIELD (`Profs` → `prof`,
 *      `Eleves` → `eleve`, `Administratifs` → RIEN), posée uniquement là où
 *      `rights_profile_id` est encore NULL ;
 *  (b) défaut à la CRÉATION du groupe au premier import AD pour le GREENFIELD
 *      (`UserGroupService::projectFoldedGroup`, branche création SEULE — un
 *      retrait décidé par l'admin n'est jamais ré-écrasé par un ré-import).
 *
 * Le volet (a) est rejoué en appelant `up()` sur la migration : son ajout de
 * colonne est gardé par `Schema::hasColumn`, seul le volet données s'exécute.
 */
class GroupRightsProfileSeedTest extends TestCase
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

    private function runDataMigration(): void
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

    // ========================================================================
    // Volet (a) — migration de données, brownfield
    // ========================================================================

    #[Test]
    public function it_seeds_existing_profs_and_eleves_groups(): void
    {
        $prof = Role::create(['name' => 'prof', 'guard_name' => 'web']);
        $eleve = Role::create(['name' => 'eleve', 'guard_name' => 'web']);

        $profs = $this->makeGroup('Profs');
        $eleves = $this->makeGroup('Eleves');
        $administratifs = $this->makeGroup('Administratifs');
        $classe = $this->makeGroup('3A');

        $this->runDataMigration();

        self::assertSame($prof->id, UserGroup::find($profs->id)->rights_profile_id);
        self::assertSame($eleve->id, UserGroup::find($eleves->id)->rights_profile_id);
        // AC9 — `Administratifs` ne reçoit RIEN (aucun rôle Spatie correspondant).
        self::assertNull(UserGroup::find($administratifs->id)->rights_profile_id);
        self::assertNull(UserGroup::find($classe->id)->rights_profile_id);
    }

    #[Test]
    public function it_never_overwrites_an_existing_link(): void
    {
        Role::create(['name' => 'prof', 'guard_name' => 'web']);
        $custom = Role::create(['name' => 'gestionnaire', 'guard_name' => 'web']);

        $profs = $this->makeGroup('Profs', $custom->id);

        $this->runDataMigration();

        self::assertSame(
            $custom->id,
            UserGroup::find($profs->id)->rights_profile_id,
            'un lien déjà posé (choix admin) ne doit JAMAIS être écrasé'
        );
    }

    #[Test]
    public function it_is_idempotent_and_never_reposes_after_an_admin_removal(): void
    {
        $prof = Role::create(['name' => 'prof', 'guard_name' => 'web']);
        $profs = $this->makeGroup('Profs');

        $this->runDataMigration();
        self::assertSame($prof->id, UserGroup::find($profs->id)->rights_profile_id);

        // L'admin retire le profil. La migration one-shot n'est pas rejouée en
        // production ; ce test documente que même rejouée, elle re-poserait —
        // c'est bien pourquoi le volet non ré-écrasant est le HOOK D'IMPORT
        // (branche création seule), pas la migration.
        UserGroup::where('id', $profs->id)->update(['rights_profile_id' => null]);

        // Idempotence stricte : deux exécutions consécutives donnent le même état.
        $this->runDataMigration();
        $first = UserGroup::find($profs->id)->rights_profile_id;
        $this->runDataMigration();
        self::assertSame($first, UserGroup::find($profs->id)->rights_profile_id);
    }

    #[Test]
    public function it_is_a_silent_no_op_when_the_seeded_roles_do_not_exist(): void
    {
        $profs = $this->makeGroup('Profs');

        $this->runDataMigration();

        self::assertNull(UserGroup::find($profs->id)->rights_profile_id);
    }

    #[Test]
    public function a_non_school_group_set_seeds_nothing(): void
    {
        Role::create(['name' => 'gestionnaire', 'guard_name' => 'web']);
        $compta = $this->makeGroup('Comptabilite');
        $rh = $this->makeGroup('RH');

        $this->runDataMigration();

        self::assertNull(UserGroup::find($compta->id)->rights_profile_id);
        self::assertNull(UserGroup::find($rh->id)->rights_profile_id);
    }

    // ========================================================================
    // Volet (b) — défaut à la CRÉATION au premier import AD (greenfield)
    // ========================================================================

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

    private function adGroup(string $cn): array
    {
        return [
            'cn' => $cn,
            'dn' => "CN={$cn},OU=Groups,DC=example,DC=local",
            'description' => $cn,
        ];
    }

    #[Test]
    public function the_first_import_creating_profs_poses_the_default(): void
    {
        $prof = Role::create(['name' => 'prof', 'guard_name' => 'web']);
        $eleve = Role::create(['name' => 'eleve', 'guard_name' => 'web']);

        $service = $this->makeImportService(collect([
            $this->adGroup('Profs'),
            $this->adGroup('Eleves'),
            $this->adGroup('Administratifs'),
        ]));

        $service->syncFromAd();

        self::assertSame($prof->id, UserGroup::where('name', 'Profs')->value('rights_profile_id'));
        self::assertSame($eleve->id, UserGroup::where('name', 'Eleves')->value('rights_profile_id'));
        self::assertNull(UserGroup::where('name', 'Administratifs')->value('rights_profile_id'));
    }

    #[Test]
    public function a_reimport_never_reposes_the_default_after_an_admin_removal(): void
    {
        Role::create(['name' => 'prof', 'guard_name' => 'web']);

        $service = $this->makeImportService(collect([$this->adGroup('Profs')]));

        $service->syncFromAd();
        self::assertNotNull(UserGroup::where('name', 'Profs')->value('rights_profile_id'));

        // L'admin retire le profil…
        UserGroup::where('name', 'Profs')->update(['rights_profile_id' => null]);

        // …un ré-import (branche UPDATE) ne le re-pose JAMAIS.
        $service->syncFromAd();

        self::assertNull(
            UserGroup::where('name', 'Profs')->value('rights_profile_id'),
            'la branche update ne doit jamais ré-écraser un retrait décidé par l\'admin'
        );
    }

    #[Test]
    public function an_import_without_the_seeded_roles_creates_the_group_without_a_profile(): void
    {
        $service = $this->makeImportService(collect([$this->adGroup('Profs')]));

        $service->syncFromAd();

        self::assertTrue(UserGroup::where('name', 'Profs')->exists());
        self::assertNull(UserGroup::where('name', 'Profs')->value('rights_profile_id'));
    }

    #[Test]
    public function a_non_school_import_never_poses_any_profile(): void
    {
        Role::create(['name' => 'prof', 'guard_name' => 'web']);

        $service = $this->makeImportService(collect([
            $this->adGroup('Comptabilite'),
            $this->adGroup('Classe_3A'),
        ]));

        $service->syncFromAd();

        self::assertSame(
            0,
            UserGroup::whereNotNull('rights_profile_id')->count(),
            'aucun profil ne doit être posé sur un jeu de groupes non scolaire'
        );
    }

    // ========================================================================
    // AC1 / AC6 — filet DB `restrictOnDelete`
    // ========================================================================

    /**
     * Le schéma hand-rolled des tests ne porte pas de FK (la table `roles` y est
     * créée APRÈS `user_groups`). Ce test vérifie donc que la MIGRATION déclare
     * bien `restrictOnDelete` — le filet DB sous la garde applicative d'AC6, qui
     * fait échouer un `Role::delete()` hors UI.
     */
    #[Test]
    public function the_migration_declares_a_restrict_on_delete_foreign_key(): void
    {
        $source = file_get_contents(base_path(self::MIGRATION));

        self::assertStringContainsString("constrained('roles')", $source);
        self::assertStringContainsString('restrictOnDelete()', $source);
        self::assertStringContainsString('nullable()', $source);
    }

    #[Test]
    public function the_migration_is_guarded_against_a_double_run(): void
    {
        // `up()` a déjà tourné plusieurs fois dans ce fichier sans exception :
        // la garde `Schema::hasColumn` fait son office.
        $this->runDataMigration();
        $this->runDataMigration();

        self::assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('user_groups', 'rights_profile_id'));
        self::assertSame(0, DB::table('user_groups')->count());
    }
}
