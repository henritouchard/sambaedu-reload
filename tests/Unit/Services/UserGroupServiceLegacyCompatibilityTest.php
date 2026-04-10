<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Facades\SEConfig;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Repositories\GroupRepository;
use App\Repositories\RightRepository;
use App\Repositories\UserGroupRepository;
use App\Services\UserGroupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class UserGroupServiceLegacyCompatibilityTest extends TestCase
{
    use DatabaseTransactions;

    /** true si on a créé les tables nous-mêmes (SQLite :memory:) */
    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestTables();
        UserGroupObserver::disableSync();
    }

    protected function tearDown(): void
    {
        // Nettoyer uniquement si on a créé les tables (SQLite :memory:)
        if ($this->createdTables) {
            Schema::dropIfExists('user_group_user');
            Schema::dropIfExists('user_groups');
            Schema::dropIfExists('users');
        }
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    #[Test]
    public function it_creates_three_sql_groups_for_classe_like_legacy(): void
    {
        $service = $this->makeService(
            collect([
                [
                    'cn' => 'Classe_3emeA',
                    'dn' => 'CN=Classe_3emeA,OU=Classes,OU=Groups,DC=example,DC=local',
                    'description' => '3ème A',
                ],
                [
                    'cn' => 'Equipe_3emeA',
                    'dn' => 'CN=Equipe_3emeA,OU=Equipes,OU=Groups,DC=example,DC=local',
                    'description' => 'Equipe pédagogique de 3ème A',
                ],
                [
                    'cn' => 'PP_3emeA',
                    'dn' => 'CN=PP_3emeA,OU=Equipes,OU=Groups,DC=example,DC=local',
                    'description' => 'Profs principaux de 3ème A',
                ],
            ]),
            [],
            [
                'Classe_3emeA' => [
                    ['cn' => 'alice', 'dn' => 'CN=alice,OU=Users,DC=example,DC=local'],
                ],
                'Equipe_3emeA' => [],
                'PP_3emeA' => [],
            ],
        );

        $user = User::query()->create([
            'login' => 'alice',
            'role' => 'eleve',
            'is_active' => true,
        ]);

        $primary = $service->createGroup([
            'name' => '3emeA',
            'display_name' => '3ème A',
            'type' => 'classe',
            'user_ids' => [$user->id],
        ]);

        $this->assertSame('Classe_3emeA', $primary->name);

        $names = UserGroup::query()->orderBy('name')->pluck('name')->all();
        $this->assertSame(
            ['Classe_3emeA', 'Equipe_3emeA', 'PP_3emeA'],
            $names
        );

        $this->assertSame(1, UserGroup::query()->where('name', 'Classe_3emeA')->firstOrFail()->users()->count());
        $this->assertSame(0, UserGroup::query()->where('name', 'Equipe_3emeA')->firstOrFail()->users()->count());
        $this->assertSame(0, UserGroup::query()->where('name', 'PP_3emeA')->firstOrFail()->users()->count());
    }

    #[Test]
    public function it_creates_two_sql_groups_for_cours_like_legacy(): void
    {
        $service = $this->makeService(
            collect([
                [
                    'cn' => 'Cours_Maths5A',
                    'dn' => 'CN=Cours_Maths5A,OU=Cours,OU=Groups,DC=example,DC=local',
                    'description' => 'Cours de Maths 5A',
                ],
                [
                    'cn' => 'Equipe_Maths5A',
                    'dn' => 'CN=Equipe_Maths5A,OU=Equipes,OU=Groups,DC=example,DC=local',
                    'description' => 'Equipe pédagogique de Maths 5A',
                ],
            ]),
            [],
        );

        $service->createGroup([
            'name' => 'Maths5A',
            'display_name' => 'Maths 5A',
            'type' => 'cours',
        ]);

        $names = UserGroup::query()->orderBy('name')->pluck('name')->all();
        $this->assertSame(['Cours_Maths5A', 'Equipe_Maths5A'], $names);
    }

    #[Test]
    public function it_creates_matiere_classe_group_with_legacy_naming(): void
    {
        $service = $this->makeService(
            collect([
                [
                    'cn' => 'Matiere_Math@3emeA',
                    'dn' => 'CN=Matiere_Math@3emeA,OU=Equipes,OU=Groups,DC=example,DC=local',
                    'description' => 'Equipe pédagogique de la matière Math 3ème A',
                ],
            ]),
            [],
        );

        $group = $service->createGroup([
            'name' => 'Math@3emeA',
            'display_name' => 'Math 3ème A',
            'type' => 'matiere_classe',
        ]);

        $this->assertSame('Matiere_Math@3emeA', $group->name);
        $this->assertSame('matiere_classe', $group->type);
    }

    #[Test]
    public function it_imports_ad_groups_with_legacy_type_detection_and_rights_exclusion(): void
    {
        $groupRows = collect([
            [
                'cn' => 'Cours_Histoire4A',
                'dn' => 'CN=Cours_Histoire4A,OU=Cours,OU=Groups,DC=example,DC=local',
            ],
            [
                'cn' => 'Matiere_Math@3emeA',
                'dn' => 'CN=Matiere_Math@3emeA,OU=Equipes,OU=Groups,DC=example,DC=local',
            ],
            [
                'cn' => 'sovajon_is_admin',
                'dn' => 'CN=sovajon_is_admin,OU=Rights,OU=Groups,DC=example,DC=local',
            ],
        ]);

        $service = $this->makeService(
            $groupRows,
            ['RefNum' => 'x'],
            [
                'Cours_Histoire4A' => [
                    ['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local'],
                ],
                'Matiere_Math@3emeA' => [],
            ],
        );

        SEConfig::shouldReceive('get')
            ->andReturnUsing(static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'rights_rdn' => 'OU=Rights',
                    'delegations_rdn' => 'OU=Delegations',
                    'groups_rdn' => 'OU=Groups',
                    default => $default,
                };
            });

        $user = User::query()->create([
            'login' => 'bob',
            'role' => 'prof',
            'is_active' => true,
        ]);

        $stats = $service->importFromUsersAdGroups();

        $this->assertSame(2, $stats['created']);
        $this->assertFalse(UserGroup::query()->where('name', 'RefNum')->exists());
        $this->assertFalse(UserGroup::query()->where('name', 'sovajon_is_admin')->exists());

        $this->assertSame('cours', UserGroup::query()->where('name', 'Cours_Histoire4A')->firstOrFail()->type);
        $this->assertSame('matiere_classe', UserGroup::query()->where('name', 'Matiere_Math@3emeA')->firstOrFail()->type);

        $this->assertSame(1, UserGroup::query()->where('name', 'Cours_Histoire4A')->firstOrFail()->users()->count());
        $this->assertSame($user->id, UserGroup::query()->where('name', 'Cours_Histoire4A')->firstOrFail()->users()->firstOrFail()->id);
        $this->assertFalse(UserGroup::query()->where('name', 'Classe_3emeA')->exists());
    }

    /**
     * @param array<string,array<int,array{cn:string,dn:string}>> $groupMembersByCn
     */
    private function makeService(Collection $groupsWithMemberCount, array $rights, array $groupMembersByCn = []): UserGroupService
    {
        $groupRepository = $this->createMock(GroupRepository::class);
        $groupRepository->method('getGroupsWithMemberCount')->willReturn($groupsWithMemberCount);
        $groupRepository->method('createGroup')->willReturn(true);
        $groupRepository->method('deleteGroup')->willReturn(true);
        $groupRepository->method('updateGroupDescription')->willReturn(true);
        $groupRepository->method('addMember')->willReturn(true);
        $groupRepository->method('removeMember')->willReturn(true);
        $groupRepository->method('getGroupMembers')->willReturnCallback(
            static fn(string $cn): Collection => collect($groupMembersByCn[$cn] ?? [])
        );

        $rightRepository = $this->createMock(RightRepository::class);
        $rightRepository->method('getAllRightsValues')->willReturn($rights);

        return new UserGroupService(
            new UserGroupRepository(),
            $groupRepository,
            $rightRepository,
        );
    }

    private function createTestTables(): void
    {
        // En SQLite :memory:, les tables n'existent pas → les créer
        // Sur PostgreSQL (VM), les tables existent déjà via les migrations → ne pas y toucher
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('login')->unique();
                $table->string('password')->nullable();
                $table->string('fullname')->nullable();
                $table->string('firstname')->nullable();
                $table->string('lastname')->nullable();
                $table->string('email')->nullable();
                $table->text('dn')->nullable();
                $table->string('role')->default('autre');
                $table->boolean('is_active')->default(true);
                $table->json('ad_right_profiles')->nullable();
                $table->integer('ad_rights_bitmask')->default(0);
                $table->timestamp('ad_synced_at')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('user_groups')) {
            Schema::create('user_groups', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->unique();
                $table->string('display_name')->nullable();
                $table->string('type');
                $table->text('ad_dn')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('user_group_user')) {
            Schema::create('user_group_user', function (Blueprint $table): void {
                $table->unsignedBigInteger('user_group_id');
                $table->unsignedBigInteger('user_id');
                $table->primary(['user_group_id', 'user_id']);
            });
            $this->createdTables = true;
        }
    }
}
