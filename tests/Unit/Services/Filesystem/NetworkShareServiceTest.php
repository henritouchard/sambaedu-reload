<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\QuotaAuditLog;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Filesystem\NetworkShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 34.1 — Tests Unit `NetworkShareService`.
 *
 * Stratégie : `Process::fake()` mocke `mkdir`/`setfacl`/`chown`/`chgrp` — AUCUN
 * accès FS réel. `sharesRoot()` est pointé vers un tempdir réel (via config)
 * pour les checks `is_dir()` d'idempotence.
 *
 * IMPORTANT : `Process::fake()` ne peut configurer ses handlers qu'au PREMIER
 * appel (un 2ᵉ appel sur un fake déjà actif est ignoré). On ne fake donc PAS
 * dans `setUp()` : chaque test appelle `Process::fake(...)` UNE fois, avec ses
 * propres handlers (la passe par défaut = succès pour les commandes non
 * matchées).
 */
class NetworkShareServiceTest extends TestCase
{
    use RefreshDatabase;

    private NetworkShareService $service;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        Queue::fake();

        $this->tempRoot = sys_get_temp_dir() . '/netshare-svc-' . uniqid();
        @mkdir($this->tempRoot, 0o755, true);
        config(['filesystem.shares_root' => $this->tempRoot]);

        $this->service = app(NetworkShareService::class);
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

    private function assign(NetworkShare $share, string $type, int $id, string $access = 'ro'): void
    {
        NetworkShareAssignable::create([
            'network_share_id' => $share->id,
            'assignable_type' => $type,
            'assignable_id' => $id,
            'access' => $access,
        ]);
    }

    // =========================================================================
    // Path / nommage
    // =========================================================================

    #[Test]
    public function resolves_a_valid_share_path_under_the_dedicated_root(): void
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'direction']);
        self::assertSame($this->tempRoot . '/direction', $this->service->resolveSharePath($share));
    }

    #[Test]
    public function rejects_traversal_and_metacharacters_in_directory_name(): void
    {
        foreach (['../etc', 'a/b', 'foo bar', 'evil;rm', '.hidden', 'a$b'] as $bad) {
            $share = new NetworkShare(['name' => 'x', 'directory_name' => $bad]);
            self::assertNull($this->service->resolveSharePath($share), "should reject: {$bad}");
        }
    }

    #[Test]
    public function path_guard_rejects_paths_outside_the_root_and_excessive_depth(): void
    {
        self::assertFalse($this->service->validateSharePath('/etc/passwd'));
        self::assertFalse($this->service->validateSharePath($this->tempRoot . '/../escape'));
        self::assertFalse($this->service->validateSharePath($this->tempRoot . '/a/b/c')); // depth 3 > MAX_DEPTH 2
        self::assertTrue($this->service->validateSharePath($this->tempRoot . '/ok'));
        self::assertTrue($this->service->validateSharePath($this->tempRoot . '/a/b')); // depth 2 OK
    }

    #[Test]
    public function provision_refused_for_invalid_directory_name_runs_no_process(): void
    {
        Process::fake();
        $share = NetworkShare::factory()->create(['directory_name' => 'valid']);
        // Force un nom invalide en mémoire (contourne l'unicité DB).
        $share->directory_name = '../escape';

        self::assertFalse($this->service->provision($share));
        Process::assertNothingRan();
    }

    // =========================================================================
    // Provisioning + ACL
    // =========================================================================

    #[Test]
    public function provision_creates_directory_and_applies_canonical_acls(): void
    {
        Process::fake();
        $share = NetworkShare::factory()->create(['directory_name' => 'commun']);

        self::assertTrue($this->service->provision($share, 'qa'));

        Process::assertRan(fn ($p): bool => str_contains($p->command, 'mkdir -p')
            && str_contains($p->command, 'commun'));
        Process::assertRan(fn ($p): bool => str_contains($p->command, 'setfacl')
            && str_contains($p->command, '-b')); // wipe avant batch
        Process::assertRan(fn ($p): bool => str_contains($p->command, 'chown www-admin'));
        Process::assertRan(fn ($p): bool => str_contains($p->command, "chgrp 'domain admins'"));
    }

    #[Test]
    public function user_assignment_grants_rx_for_ro_and_rwx_for_rw(): void
    {
        Process::fake();
        $alice = User::factory()->create(['login' => 'alice']);
        $bob = User::factory()->create(['login' => 'bob']);
        $share = NetworkShare::factory()->create(['directory_name' => 'mix']);
        $this->assign($share, User::class, $alice->id, 'ro');
        $this->assign($share, User::class, $bob->id, 'rw');

        $this->service->provision($share);

        Process::assertRan(fn ($p): bool => str_contains($p->command, 'setfacl')
            && str_contains($p->command, 'user:alice:rx'));
        Process::assertRan(fn ($p): bool => str_contains($p->command, 'setfacl')
            && str_contains($p->command, 'user:bob:rwx'));
        // Défauts miroir pour l'héritage.
        Process::assertRan(fn ($p): bool => str_contains($p->command, 'default:user:alice:rx'));
    }

    #[Test]
    public function user_group_classe_assignment_grants_classe_unix_group(): void
    {
        Process::fake();
        $group = UserGroup::create(['name' => '3emeA', 'type' => 'classe']);
        $share = NetworkShare::factory()->create(['directory_name' => 'classe3a']);
        $this->assign($share, UserGroup::class, $group->id, 'rw');

        $this->service->provision($share);

        Process::assertRan(fn ($p): bool => str_contains($p->command, 'group:classe_3emea:rwx'));
    }

    #[Test]
    public function user_group_equipe_assignment_grants_equipe_unix_group(): void
    {
        Process::fake();
        $group = UserGroup::create(['name' => 'profs', 'type' => 'equipe']);
        $share = NetworkShare::factory()->create(['directory_name' => 'salledesprofs']);
        $this->assign($share, UserGroup::class, $group->id, 'ro');

        $this->service->provision($share);

        Process::assertRan(fn ($p): bool => str_contains($p->command, 'group:equipe_profs:rx'));
    }

    #[Test]
    public function workstation_group_assignment_contributes_no_acl(): void
    {
        $wg = WorkstationGroup::factory()->logical()->create();
        $share = NetworkShare::factory()->create(['directory_name' => 'mountonly']);
        $this->assign($share, WorkstationGroup::class, $wg->id, 'rw');
        $share->load('assignments');

        // L'ACL bâtie pour un share assigné UNIQUEMENT à un WG = le set canonique
        // de base SANS aucune ligne user:<login>/group:<nom> spécifique (montage-
        // seul). On vérifie sur le builder pur (déterministe).
        $acls = $this->service->buildAcls($share);

        $canonical = [
            'user::rwx',
            'group::---',
            'group:domain\\040admins:rwx',
            'mask::rwx',
            'other::---',
            'default:user::rwx',
            'default:group::---',
            'default:group:domain\\040admins:rwx',
            'default:mask::rwx',
            'default:other::---',
        ];
        self::assertSame($canonical, $acls);
    }

    #[Test]
    public function provision_is_idempotent_skips_mkdir_when_dir_exists(): void
    {
        Process::fake();
        $share = NetworkShare::factory()->create(['directory_name' => 'idem']);
        @mkdir($this->tempRoot . '/idem', 0o755, true);

        self::assertTrue($this->service->provision($share));

        // Le dossier existe déjà → aucun mkdir n'est lancé (is_dir court-circuite).
        Process::assertNotRan(fn ($p): bool => str_contains($p->command, 'mkdir'));
        // Mais les ACLs sont RÉ-appliquées (wipe + batch) → idempotence ACL.
        Process::assertRan(fn ($p): bool => str_contains($p->command, 'setfacl') && str_contains($p->command, '-b'));

        @rmdir($this->tempRoot . '/idem');
    }

    // =========================================================================
    // Audit + fail-soft
    // =========================================================================

    #[Test]
    public function provision_writes_an_audit_row(): void
    {
        Process::fake();
        $share = NetworkShare::factory()->create(['directory_name' => 'audite']);
        $this->service->provision($share, 'qa-runbook');

        $row = QuotaAuditLog::where('target_type', 'share')
            ->where('target_name', 'audite')
            ->latest('id')
            ->first();

        self::assertNotNull($row);
        self::assertSame('provision_share', $row->action);
        self::assertSame('/var/sambaedu', $row->partition);
        self::assertSame('qa-runbook', $row->performed_by);
    }

    #[Test]
    public function provision_is_fail_soft_when_setfacl_fails(): void
    {
        // PREMIER (et seul) appel à Process::fake de ce test : `setfacl` échoue,
        // tout le reste réussit. (Un 2ᵉ Process::fake serait ignoré — cf. note
        // de classe ; on ne fake PAS dans setUp pour cette raison.)
        Process::fake([
            'sudo setfacl*' => Process::result(output: 'boom', exitCode: 1),
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        $share = NetworkShare::factory()->create(['directory_name' => 'failsoft']);
        $this->assign($share, User::class, User::factory()->create(['login' => 'eve'])->id);

        // Aucune exception propagée ; retour false (échec partiel).
        self::assertFalse($this->service->provision($share));
        // L'audit est tout de même écrit (trace de la tentative).
        self::assertNotNull(QuotaAuditLog::where('target_name', 'failsoft')->first());
    }

    #[Test]
    public function get_status_reports_existence_without_side_effects(): void
    {
        Process::fake();
        $share = NetworkShare::factory()->create(['directory_name' => 'statut']);
        $sam = User::factory()->create(['login' => 'sam']);
        $this->assign($share, User::class, $sam->id);

        $status = $this->service->getStatus($share);
        self::assertFalse($status['exists']); // dir non créé
        self::assertSame($this->tempRoot . '/statut', $status['path']);
        self::assertSame(1, $status['assignments_count']);
        Process::assertNothingRan();
    }
}
