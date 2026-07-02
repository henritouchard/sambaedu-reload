<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Services\Filesystem\AclService;
use App\Services\Filesystem\ShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Epic 34 (durcissement resync) — Pré-check de résolution des groupes système.
 *
 * Couvre le cas des **classes déchets** (nom `classe_473` dont le vrai groupe AD
 * est `classe_classe_473`) : `ShareService` cible `equipe_473`/`classe_473` qui
 * ne se résolvent pas → `setfacl` échouerait. On vérifie que le service refuse
 * AVANT tout side-effect FS (fail-closed).
 *
 * `Process::fake()` est appelé UNE fois par test (pas dans setUp) — un 2ᵉ appel
 * sur un fake déjà actif est ignoré (cf. NetworkShareServiceTest).
 */
class ShareServiceGroupResolutionTest extends TestCase
{
    use RefreshDatabase;

    private ShareService $service;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        UserGroupObserver::disableSync();
        Queue::fake();

        $this->tempRoot = sys_get_temp_dir() . '/share-grp-' . uniqid();
        @mkdir($this->tempRoot, 0o755, true);
        AclService::$classesRoot = $this->tempRoot;
        ShareService::$classesRoot = $this->tempRoot;

        $this->service = new ShareService(new AclService());
    }

    protected function tearDown(): void
    {
        AclService::$classesRoot = '/var/sambaedu/Classes';
        ShareService::$classesRoot = '/var/sambaedu/Classes';
        @rmdir($this->tempRoot);
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function makeClasse(string $name): UserGroup
    {
        return UserGroup::create([
            'name' => $name,
            'display_name' => "Classe {$name}",
            'type' => 'classe',
        ]);
    }

    #[Test]
    public function returns_empty_when_groups_resolve(): void
    {
        Process::fake(['*' => Process::result(output: '', exitCode: 0)]); // getent OK
        self::assertSame([], $this->service->unresolvedClassGroups($this->makeClasse('6A')));
    }

    #[Test]
    public function lists_missing_groups_when_getent_fails(): void
    {
        Process::fake([
            'getent group *' => Process::result(output: '', exitCode: 2), // non résolu
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        // Classe `473` → ShareService cible equipe_473 / classe_473.
        self::assertSame(
            ['equipe_473', 'classe_473'],
            $this->service->unresolvedClassGroups($this->makeClasse('473')),
        );
    }

    #[Test]
    public function create_class_share_is_fail_closed_when_groups_unresolved(): void
    {
        Process::fake([
            'getent group *' => Process::result(output: '', exitCode: 2),
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        // Refuse sans aucun mkdir/setfacl (pas d'ACL partielle sur une classe fantôme).
        self::assertFalse($this->service->createClassShare($this->makeClasse('473'), performedBy: 'admin'));
        Process::assertNotRan(fn ($p) => str_contains($p->command, 'mkdir'));
        Process::assertNotRan(fn ($p) => str_contains($p->command, 'setfacl'));
    }
}
