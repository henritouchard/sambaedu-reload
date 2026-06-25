<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Models\QuotaAuditLog;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Services\Filesystem\AclService;
use App\Services\Filesystem\ShareService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 5.2 — Tests Unit ShareService.
 *
 * Stratégie : `Process::fake()` mocks `setfacl`/`mkdir`/`mv`/`chown`/`chgrp`/`rm`.
 * AclService réel (DI) — on ne le mocke pas car il est lui-même testé.
 *
 * Attention : `is_dir()` est un appel système réel. Pour tester l'idempotence
 * et l'archivage, on override `ShareService::$classesRoot` vers un tempdir
 * réel auquel on prépare les pré-conditions FS (D13).
 */
class ShareServiceTest extends TestCase
{
    use CreatesPermissionSchema;

    private ShareService $service;
    private AclService $aclService;
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPermissionSchema();

        // Désactive l'Observer UserGroup qui dispatche des jobs AdSync à
        // chaque save Eloquent — hors scope ShareService, perturbe les tests.
        UserGroupObserver::disableSync();
        Queue::fake();

        // Vrai tempdir pour `is_dir()` checks (Process::fake ne couvre pas le
        // syscall is_dir). On créera des dossiers fictifs pour simuler les
        // états FS attendus.
        $this->tempRoot = sys_get_temp_dir() . '/share-svc-' . uniqid();
        @mkdir($this->tempRoot, 0o755, true);

        AclService::$classesRoot = $this->tempRoot;
        ShareService::$classesRoot = $this->tempRoot;

        $this->aclService = new AclService();
        $this->service = new ShareService($this->aclService);

        // Process::fake par défaut : tout réussit (exit code 0).
        Process::fake([
            '*' => Process::result(output: '', exitCode: 0),
        ]);
    }

    protected function tearDown(): void
    {
        // Cleanup tempdir.
        if (is_dir($this->tempRoot)) {
            $this->rrmdir($this->tempRoot);
        }
        AclService::$classesRoot = '/var/sambaedu/Classes';
        ShareService::$classesRoot = '/var/sambaedu/Classes';

        UserGroupObserver::enableSync();

        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . '/' . $item;
            if (is_dir($full) && ! is_link($full)) {
                $this->rrmdir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }

    private function makeClasse(string $name = '6A'): UserGroup
    {
        return UserGroup::create([
            'name' => $name,
            'display_name' => "Classe $name",
            'type' => 'classe',
        ]);
    }

    private function makeEleve(string $login = 'alice'): User
    {
        return User::create([
            'login' => $login,
            'role' => 'eleve',
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // resolveClassPath / escapeAclClassName
    // =========================================================================

    #[Test]
    public function it_rejects_non_classe_user_group(): void
    {
        $group = UserGroup::create(['name' => 'Profs', 'type' => 'role']);
        $this->assertNull($this->service->resolveClassPath($group));
    }

    #[Test]
    public function it_resolves_class_path_under_classes_root(): void
    {
        $group = $this->makeClasse('6A');
        $path = $this->service->resolveClassPath($group);
        $this->assertSame($this->tempRoot . '/Classe_6A', $path);
    }

    #[Test]
    public function it_lowercases_acl_class_name_and_rejects_spaces(): void
    {
        $this->assertSame('6a', $this->service->escapeAclClassName('6A'));
        // Review 5.2 #15 — refus des espaces (cohérence avec validatePath).
        $this->assertNull($this->service->escapeAclClassName('Seconde B'));
        $this->assertNull($this->service->escapeAclClassName('Classe;rm -rf'));
    }

    #[Test]
    public function it_strips_classe_prefix_before_lowercasing_for_acl(): void
    {
        // Le SER stocke le CN brut AD `Classe_3eme3` dans user_groups.name.
        // L'ACL doit cibler `classe_3eme3` (pas `classe_classe_3eme3`).
        $this->assertSame('3eme3', $this->service->escapeAclClassName('Classe_3eme3'));
        // Préfixe case-insensitive (cohérent regex /^Classe_/i).
        $this->assertSame('3eme3', $this->service->escapeAclClassName('classe_3eme3'));
        // Pas de préfixe : pass-through (compat legacy ancien).
        $this->assertSame('6a', $this->service->escapeAclClassName('6A'));
    }

    #[Test]
    public function bare_class_name_preserves_case_and_strips_prefix(): void
    {
        $this->assertSame('3emeA', $this->service->bareClassName('Classe_3emeA'));
        $this->assertSame('3emeA', $this->service->bareClassName('3emeA'));
        $this->assertNull($this->service->bareClassName('.cachee'));
        // Préfixe `Classe_` requalifie le 1er char de la partie restante :
        // un nom comme `Classe_.x` est dépréfixé en `.x`, refusé (1er char `.`).
        $this->assertNull($this->service->bareClassName('Classe_.x'));
    }

    #[Test]
    public function it_resolves_class_path_without_double_prefix_when_name_starts_with_classe(): void
    {
        // Régression : avant le fix, name="Classe_3eme3" produisait
        // `/var/sambaedu/Classes/Classe_Classe_3eme3` (double préfixe).
        $group = $this->makeClasse('Classe_3eme3');
        $path = $this->service->resolveClassPath($group);
        $this->assertSame($this->tempRoot . '/Classe_3eme3', $path);
    }

    public static function rejectedClassNameProvider(): array
    {
        return [
            'starts with dot'        => ['.x'],
            'all dots'               => ['..'],
            'hidden classic'         => ['.hidden'],
            'space in middle'        => ['Seconde B'],
            'shell metachar semi'    => ['Classe;rm'],
            'shell metachar pipe'    => ['Classe|cat'],
            'backtick'               => ['Classe`whoami`'],
            'dollar'                 => ['Classe$IFS'],
            'slash'                  => ['Classe/etc'],
            'null byte'              => ["Classe\0"],
            'empty'                  => [''],
        ];
    }

    /**
     * Review 5.2 #12 + #15 — durcissement preventif `escapeAclClassName`.
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedClassNameProvider')]
    public function it_rejects_invalid_or_dangerous_class_names(string $name): void
    {
        $this->assertNull(
            $this->service->escapeAclClassName($name),
            "Le nom '$name' devrait être refusé"
        );
    }

    // =========================================================================
    // Suffixe établissement (AD fédéré) — establishmentSuffix / aclGroupLocalPart
    // =========================================================================

    #[Test]
    public function it_derives_establishment_suffix_from_uai_ou_in_dn(): void
    {
        // OU UAI présente → suffixe legacy "-" . substr(uai, 3) lowercase.
        $this->assertSame(
            '-1229y',
            $this->service->establishmentSuffix('CN=Classe_3SB,OU=classes,OU=0991229y,OU=Groups,DC=lab1,DC=irundo,DC=fr')
        );
        // UAI déjà en minuscules / casse mixte → normalisé.
        $this->assertSame(
            '-1229y',
            $this->service->establishmentSuffix('CN=Equipe_3SB,OU=equipes,OU=0991229Y,OU=Groups,DC=x')
        );
    }

    #[Test]
    public function it_returns_empty_suffix_when_no_uai_ou_present(): void
    {
        // Standalone : pas d'OU au format UAI → pas de suffixe (cohérent legacy).
        $this->assertSame('', $this->service->establishmentSuffix('CN=Classe_6A,OU=classes,OU=Groups,DC=x'));
        $this->assertSame('', $this->service->establishmentSuffix(null));
        $this->assertSame('', $this->service->establishmentSuffix(''));
        // Une OU "presque UAI" mais hors format ne déclenche pas le suffixe.
        $this->assertSame('', $this->service->establishmentSuffix('CN=g,OU=12345,OU=Groups,DC=x'));
    }

    #[Test]
    public function acl_group_local_part_appends_federated_suffix(): void
    {
        $group = UserGroup::create([
            'name' => '3SB',
            'display_name' => 'Classe Simon Bolivar',
            'type' => 'classe',
            'ad_dn' => 'CN=Classe_3SB,OU=classes,OU=0991229y,OU=Groups,DC=lab1,DC=irundo,DC=fr',
        ]);
        // Nom court foldé + suffixe établissement → matche le groupe Unix réel.
        $this->assertSame('3sb-1229y', $this->service->aclGroupLocalPart($group));

        // Sans ad_dn → pas de suffixe (rétrocompat standalone).
        $bare = $this->makeClasse('6A');
        $this->assertSame('6a', $this->service->aclGroupLocalPart($bare));
    }

    #[Test]
    public function it_creates_class_share_with_federated_group_suffix(): void
    {
        $group = UserGroup::create([
            'name' => '3SB',
            'display_name' => 'Classe Simon Bolivar',
            'type' => 'classe',
            'ad_dn' => 'CN=Classe_3SB,OU=classes,OU=0991229y,OU=Groups,DC=lab1,DC=irundo,DC=fr',
        ]);

        $ok = $this->service->createClassShare($group, performedBy: 'admin');
        $this->assertTrue($ok);

        // L'ACL prof cible le vrai groupe suffixé `equipe_3sb-1229y`.
        Process::assertRan(function ($p) {
            return str_contains($p->command, 'setfacl')
                && str_contains($p->command, 'group:equipe_3sb-1229y:rwx');
        });
        // Et JAMAIS la forme nue non résolvable `equipe_3sb:` (sans suffixe).
        Process::assertNotRan(function ($p) {
            return str_contains($p->command, 'group:equipe_3sb:rwx');
        });
        // Le dossier, lui, reste sans suffixe (Classe_3SB).
        Process::assertRan(function ($p) {
            return str_contains($p->command, 'Classe_3SB')
                && ! str_contains($p->command, 'Classe_3SB-1229y');
        });
    }

    // =========================================================================
    // ACL builders — décalque legacy
    // =========================================================================

    #[Test]
    public function it_creates_class_share_with_canonical_acls(): void
    {
        $group = $this->makeClasse('6A');

        $ok = $this->service->createClassShare($group, performedBy: 'admin');
        $this->assertTrue($ok);

        // setfacl --set ou -m a été ran avec les ACLs canoniques équipe pédagogique.
        Process::assertRan(function ($p) {
            return str_contains($p->command, 'setfacl')
                && str_contains($p->command, 'group:equipe_6a:rwx');
        });
        // group:domain\040admins est bien posé (legacy escape espace).
        Process::assertRan(function ($p) {
            return str_contains($p->command, 'setfacl')
                && str_contains($p->command, 'group:domain\\040admins');
        });
        // Ajustement -m racine retire le w via group:equipe_6a:rx.
        Process::assertRan(function ($p) {
            return str_contains($p->command, 'setfacl  -m')
                && str_contains($p->command, 'group:equipe_6a:rx');
        });
    }

    #[Test]
    public function it_creates_subdirs_travail_profs_echange(): void
    {
        $group = $this->makeClasse('6A');
        $this->service->createClassShare($group, performedBy: 'admin');

        // mkdir doit être ran sur les 3 sous-dirs (sudo mkdir -p).
        Process::assertRan(fn ($p) => str_contains($p->command, "mkdir -p")
            && str_contains($p->command, 'Classe_6A/_travail'));
        Process::assertRan(fn ($p) => str_contains($p->command, "mkdir -p")
            && str_contains($p->command, 'Classe_6A/_profs'));
        Process::assertRan(fn ($p) => str_contains($p->command, "mkdir -p")
            && str_contains($p->command, 'Classe_6A/_echange'));
    }

    #[Test]
    public function it_activates_echange_by_default_at_creation_d6(): void
    {
        $group = $this->makeClasse('6A');
        $this->service->createClassShare($group, performedBy: 'admin');

        // _echange doit recevoir group:classe_6a:rwx (D6=A activé par défaut).
        Process::assertRan(function ($p) {
            return str_contains($p->command, 'setfacl')
                && str_contains($p->command, 'group:classe_6a:rwx')
                && str_contains($p->command, '_echange');
        });
    }

    #[Test]
    public function it_writes_acls_with_bare_name_when_group_name_is_prefixed(): void
    {
        // Régression critique : avec name="Classe_3eme3", l'ACL doit cibler
        // `classe_3eme3` (groupe AD réel) et NON `classe_classe_3eme3`.
        $group = $this->makeClasse('Classe_3eme3');
        $this->service->createClassShare($group, performedBy: 'admin');

        Process::assertRan(fn ($p) => str_contains($p->command, 'setfacl')
            && str_contains($p->command, 'group:equipe_3eme3:rwx'));
        Process::assertRan(fn ($p) => str_contains($p->command, 'setfacl')
            && str_contains($p->command, 'group:classe_3eme3:rwx')
            && str_contains($p->command, '_echange'));
        Process::assertNotRan(fn ($p) => str_contains($p->command, 'classe_classe_'));
        Process::assertNotRan(fn ($p) => str_contains($p->command, 'Classe_Classe_'));
    }

    #[Test]
    public function it_applies_eleve_acls_for_each_member(): void
    {
        $group = $this->makeClasse('6A');
        $alice = $this->makeEleve('alice');
        $bob = $this->makeEleve('bob');

        $group->users()->attach([$alice->id, $bob->id]);

        $this->service->createClassShare($group, performedBy: 'admin');

        // Chaque élève reçoit ses ACLs perso.
        Process::assertRan(fn ($p) => str_contains($p->command, 'setfacl')
            && str_contains($p->command, 'user:alice:rwx'));
        Process::assertRan(fn ($p) => str_contains($p->command, 'setfacl')
            && str_contains($p->command, 'user:bob:rwx'));
    }

    #[Test]
    public function it_writes_quota_audit_log_with_target_type_share_d10(): void
    {
        $group = $this->makeClasse('6A');
        $this->service->createClassShare($group, performedBy: 'admin');

        $log = QuotaAuditLog::query()->where('action', 'create_share')->first();
        $this->assertNotNull($log);
        $this->assertSame('share', $log->target_type);
        $this->assertSame('6A', $log->target_name);
        $this->assertSame('admin', $log->performed_by);
        $this->assertSame('/var/sambaedu', $log->partition);
    }

    #[Test]
    public function it_is_idempotent_on_recreate(): void
    {
        $group = $this->makeClasse('6A');

        // 1ère création.
        $this->service->createClassShare($group, performedBy: 'admin');

        // Pré-créer le dossier sur le tempdir pour simuler un état "déjà créé".
        @mkdir($this->tempRoot . '/Classe_6A', 0o755, true);
        @mkdir($this->tempRoot . '/Classe_6A/_travail', 0o755, true);

        // 2ème appel — le tout doit toujours retourner true et ne pas exploser.
        $ok = $this->service->createClassShare($group, performedBy: 'admin');
        $this->assertTrue($ok);

        // 2 audits écrits (un par appel).
        $this->assertSame(2, QuotaAuditLog::query()->where('action', 'create_share')->count());
    }

    // =========================================================================
    // toggleEchange (AC 5)
    // =========================================================================

    #[Test]
    public function it_toggles_echange_acls_to_inactive(): void
    {
        $group = $this->makeClasse('6A');
        @mkdir($this->tempRoot . '/Classe_6A/_echange', 0o755, true);

        $ok = $this->service->toggleEchange($group, active: false);
        $this->assertTrue($ok);

        Process::assertRan(fn ($p) => str_contains($p->command, 'setfacl')
            && str_contains($p->command, 'group:classe_6a:---'));

        $log = QuotaAuditLog::query()->where('action', 'toggle_echange')->first();
        $this->assertNotNull($log);
        $this->assertSame('share', $log->target_type);
    }

    #[Test]
    public function it_toggles_echange_acls_to_active(): void
    {
        $group = $this->makeClasse('6A');
        @mkdir($this->tempRoot . '/Classe_6A/_echange', 0o755, true);

        $ok = $this->service->toggleEchange($group, active: true);
        $this->assertTrue($ok);

        Process::assertRan(fn ($p) => str_contains($p->command, 'setfacl')
            && str_contains($p->command, 'group:classe_6a:rwx'));
    }

    // =========================================================================
    // syncUserClassMemberships (AC 4 + D3)
    // =========================================================================

    #[Test]
    public function it_creates_eleve_dir_when_user_is_added_to_a_class(): void
    {
        $oldGroup = $this->makeClasse('5B');
        $newGroup = $this->makeClasse('6A');
        $alice = $this->makeEleve('alice');

        $ok = $this->service->syncUserClassMemberships(
            $alice,
            oldClassIds: [],
            newClassIds: [$newGroup->id]
        );
        $this->assertTrue($ok);

        Process::assertRan(fn ($p) => str_contains($p->command, "mkdir -p")
            && str_contains($p->command, 'Classe_6A/alice'));
        Process::assertRan(fn ($p) => str_contains($p->command, 'setfacl')
            && str_contains($p->command, 'user:alice:rwx'));
    }

    #[Test]
    public function it_archives_eleve_dir_when_changing_class_d3(): void
    {
        $oldGroup = $this->makeClasse('5B');
        $newGroup = $this->makeClasse('6A');
        $alice = $this->makeEleve('alice');

        // Pré-condition : dossier élève dans l'ancienne classe.
        @mkdir($this->tempRoot . '/Classe_5B/alice', 0o755, true);
        @mkdir($this->tempRoot . '/Classe_6A', 0o755, true);

        $ok = $this->service->syncUserClassMemberships(
            $alice,
            oldClassIds: [$oldGroup->id],
            newClassIds: [$newGroup->id]
        );
        $this->assertTrue($ok);

        // Le mv vers Classe_6A/alice/Archives a été ran.
        Process::assertRan(function ($p) {
            return str_contains($p->command, 'sudo mv')
                && str_contains($p->command, '/Classe_5B/alice')
                && str_contains($p->command, '/Classe_6A/alice/Archives');
        });

        $log = QuotaAuditLog::query()->where('action', 'sync_user')->first();
        $this->assertNotNull($log);
        $this->assertSame('alice', $log->target_name);
    }

    #[Test]
    public function it_removes_user_acl_when_class_removed_with_no_replacement(): void
    {
        $oldGroup = $this->makeClasse('5B');
        $alice = $this->makeEleve('alice');

        $ok = $this->service->syncUserClassMemberships(
            $alice,
            oldClassIds: [$oldGroup->id],
            newClassIds: []
        );
        $this->assertTrue($ok);

        // setfacl -x user:alice ran sur la racine de l'ancienne classe.
        Process::assertRan(function ($p) {
            return str_contains($p->command, 'setfacl')
                && str_contains($p->command, '-x')
                && str_contains($p->command, 'user:alice')
                && str_contains($p->command, 'Classe_5B');
        });
    }

    #[Test]
    public function it_rejects_malicious_login_in_sync(): void
    {
        $newGroup = $this->makeClasse('6A');
        $bad = User::create(['login' => '../etc', 'role' => 'eleve', 'is_active' => true]);

        $ok = $this->service->syncUserClassMemberships(
            $bad,
            oldClassIds: [],
            newClassIds: [$newGroup->id]
        );
        $this->assertFalse($ok);
    }

    // =========================================================================
    // archiveClassShare (D4)
    // =========================================================================

    #[Test]
    public function it_archives_class_share_via_mv(): void
    {
        $group = $this->makeClasse('6A');
        @mkdir($this->tempRoot . '/Classe_6A', 0o755, true);

        $ok = $this->service->archiveClassShare($group);
        $this->assertTrue($ok);

        Process::assertRan(function ($p) {
            return str_contains($p->command, 'sudo mv')
                && str_contains($p->command, '/Classe_6A')
                && str_contains($p->command, '/.Classe_6A');
        });

        $log = QuotaAuditLog::query()->where('action', 'archive_share')->first();
        $this->assertNotNull($log);
    }

    /**
     * Story 5.2 review #11 Q2 — décalque legacy strict + log warning.
     */
    #[Test]
    public function it_logs_warning_when_archive_target_already_exists(): void
    {
        $group = $this->makeClasse('6A');
        @mkdir($this->tempRoot . '/Classe_6A', 0o755, true);
        @mkdir($this->tempRoot . '/.Classe_6A', 0o755, true); // archive cible déjà présente

        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->with('ShareService: archiveClassShare cible déjà existante, mv refusé', \Mockery::on(function ($ctx) {
                return ($ctx['classe'] ?? null) === '6A'
                    && str_contains((string) ($ctx['target'] ?? ''), '/.Classe_6A');
            }));
        // Tolère d'autres logs (createClassShare, etc.).
        \Illuminate\Support\Facades\Log::shouldReceive('info')->zeroOrMoreTimes();
        \Illuminate\Support\Facades\Log::shouldReceive('error')->zeroOrMoreTimes();

        $ok = $this->service->archiveClassShare($group);
        $this->assertFalse($ok);

        // Aucun mv ne doit être ran.
        Process::assertNotRan(fn ($p) => str_contains($p->command, 'sudo mv'));
    }

    #[Test]
    public function archive_class_share_is_idempotent_when_path_missing(): void
    {
        $group = $this->makeClasse('6A');
        // Pas de dossier sur le FS.

        $ok = $this->service->archiveClassShare($group);
        $this->assertTrue($ok); // idempotent.
        Process::assertNotRan(fn ($p) => str_contains($p->command, 'sudo mv'));
    }

    // =========================================================================
    // Fail-soft (AC 10)
    // =========================================================================

    #[Test]
    public function it_returns_false_on_setfacl_failure(): void
    {
        // Process::fake() en setUp utilise un handler `*` => success qui ne
        // peut pas être supplanté par un override en test (Laravel fusionne
        // sans précédence — le `*` premier capturé gagne). On utilise donc
        // un callable closure pour discriminer setfacl vs autres.
        Process::fake(function ($process) {
            if (str_contains($process->command, 'setfacl')) {
                return Process::result(output: '', errorOutput: 'sudoers refused', exitCode: 1);
            }
            return Process::result(output: '', exitCode: 0);
        });

        $group = $this->makeClasse('6A');
        $ok = $this->service->createClassShare($group, performedBy: 'admin');
        $this->assertFalse($ok);

        // L'audit est tout de même écrit avec success=false.
        $log = QuotaAuditLog::query()->where('action', 'create_share')->first();
        $this->assertNotNull($log);
        $newValues = $log->new_values;
        $this->assertSame(false, $newValues['success']);
    }

    // =========================================================================
    // getStatus
    // =========================================================================

    #[Test]
    public function get_status_returns_subdirs_state(): void
    {
        $group = $this->makeClasse('6A');
        @mkdir($this->tempRoot . '/Classe_6A/_travail', 0o755, true);
        @mkdir($this->tempRoot . '/Classe_6A/_profs', 0o755, true);

        $status = $this->service->getStatus($group);
        $this->assertTrue($status['exists']);
        $this->assertTrue($status['subdirs']['_travail']);
        $this->assertTrue($status['subdirs']['_profs']);
        $this->assertFalse($status['subdirs']['_echange']);
    }

    #[Test]
    public function get_status_for_non_classe_returns_zero(): void
    {
        $group = UserGroup::create(['name' => 'Profs', 'type' => 'role']);
        $status = $this->service->getStatus($group);
        $this->assertFalse($status['exists']);
        $this->assertNull($status['path']);
    }
}
