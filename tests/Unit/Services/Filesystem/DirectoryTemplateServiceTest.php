<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Models\DirectoryTemplate;
use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Filesystem\DirectoryTemplateService;
use Database\Seeders\DirectoryTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    #[Test]
    public function profs_to_eleves_grants_equipe_rw_and_classe_ro(): void
    {
        $equipe = UserGroup::create(['name' => 'profs6eB', 'type' => 'equipe']);
        $classe = UserGroup::create(['name' => '6eB', 'type' => 'classe']);

        $result = $this->service->materialize($this->template(DirectoryTemplate::KEY_PROFS_TO_ELEVES), [
            'name' => 'Devoirs 6eB',
            'directory_name' => 'devoirs_6eb',
            'letter' => 'Q:',
            'roles' => [
                'profs' => [$equipe->id],
                'eleves' => [$classe->id],
            ],
        ]);

        $this->assertAssignment($result->share, UserGroup::class, $equipe->id, 'rw');
        $this->assertAssignment($result->share, UserGroup::class, $classe->id, 'ro');
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

        $this->assertTrue($result->provisioned);
        Process::assertRan(fn ($p): bool => str_contains($p->command, 'mkdir -p') && str_contains($p->command, 'espace_cdi'));
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
