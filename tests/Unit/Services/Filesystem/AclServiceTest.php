<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Services\Filesystem\AclService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 5.2 — Tests Unit AclService.
 *
 * Stratégie (D12=A) : `Process::fake()` mocks `setfacl`/`getfacl`. Pas de
 * vraie commande shell exécutée — robuste CI / dev macOS / e5-partages
 * worktree non syncé VM.
 *
 * Couvre AC 12 (anti-injection path) via DataProvider 8 patterns malicieux.
 */
class AclServiceTest extends TestCase
{
    private AclService $service;

    private string $previousRoot;

    protected function setUp(): void
    {
        parent::setUp();
        // Override la racine en tests pour éviter de toucher au FS réel.
        $this->previousRoot = AclService::$classesRoot;
        AclService::$classesRoot = '/var/sambaedu/Classes';
        $this->service = new AclService();
    }

    protected function tearDown(): void
    {
        AclService::$classesRoot = $this->previousRoot;
        parent::tearDown();
    }

    // =========================================================================
    // setAcls — wipe + batch
    // =========================================================================

    #[Test]
    public function it_sets_acls_with_recurse_flag(): void
    {
        Process::fake([
            'sudo setfacl *' => Process::result(output: '', exitCode: 0),
        ]);

        $ok = $this->service->setAcls(
            '/var/sambaedu/Classes/Classe_6A',
            ['user::rwx', 'group::---', 'mask::rwx', 'other::---'],
            recurse: true
        );

        $this->assertTrue($ok);

        // Le wipe `setfacl -R -P -b` doit être ran (`-P` anti symlink traversal).
        Process::assertRan(fn ($p) => str_contains($p->command, 'setfacl -R -P -b'));
        // Chaque ACL doit être ran avec `setfacl -R -P -m`.
        Process::assertRan(fn ($p) => str_contains($p->command, "setfacl -R -P -m") && str_contains($p->command, 'user::rwx'));
        Process::assertRan(fn ($p) => str_contains($p->command, "setfacl -R -P -m") && str_contains($p->command, 'mask::rwx'));
    }

    /**
     * Story 5.2 review #3 — anti-régression sécurité : toute commande `setfacl`
     * récursive DOIT être préfixée `-P` pour refuser de suivre les symlinks
     * plantés par un attaquant dans un dossier élève (`Classe_X/eleve/evil` →
     * `/etc/`).
     */
    #[Test]
    public function it_uses_dash_p_to_prevent_symlink_traversal_when_recursive(): void
    {
        Process::fake([
            'sudo setfacl *' => Process::result(output: '', exitCode: 0),
        ]);

        // setAcls récursif
        $this->service->setAcls(
            '/var/sambaedu/Classes/Classe_6A',
            ['user::rwx'],
            recurse: true
        );

        // addAcl récursif
        $this->service->addAcl('/var/sambaedu/Classes/Classe_6A', 'group:Classe_6a:rx', recurse: true);

        // removeAcl récursif
        $this->service->removeAcl('/var/sambaedu/Classes/Classe_6A', 'user:alice', recurse: true);

        // Toutes les commandes setfacl exécutées avec -R doivent contenir -P.
        Process::assertRan(function ($p) {
            // Si la commande contient `setfacl -R`, elle doit contenir `setfacl -R -P`.
            if (! str_contains($p->command, 'setfacl ')) {
                return true;
            }
            return ! str_contains($p->command, ' -R ') || str_contains($p->command, ' -R -P ');
        });

        // Vérifications spécifiques par méthode.
        Process::assertRan(fn ($p) => str_contains($p->command, 'setfacl -R -P -b')); // wipe
        Process::assertRan(fn ($p) => str_contains($p->command, 'setfacl -R -P -m') && str_contains($p->command, 'group:Classe_6a:rx'));
        Process::assertRan(fn ($p) => str_contains($p->command, 'setfacl -R -P -x') && str_contains($p->command, 'user:alice'));
    }

    #[Test]
    public function it_sets_acls_without_recurse(): void
    {
        Process::fake([
            'sudo setfacl *' => Process::result(output: '', exitCode: 0),
        ]);

        $ok = $this->service->setAcls(
            '/var/sambaedu/Classes/Classe_6A',
            ['user::rwx'],
            recurse: false
        );

        $this->assertTrue($ok);
        // En mode non-récursif, pas de `-R`.
        Process::assertRan(fn ($p) => str_contains($p->command, 'setfacl  -b'));
    }

    #[Test]
    public function it_returns_false_when_setfacl_wipe_fails(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with('AclService: setAcls échec wipe', \Mockery::on(fn ($ctx) => str_contains($ctx['command'], 'setfacl')));

        Process::fake([
            'sudo setfacl *' => Process::result(output: '', errorOutput: 'sudoers refused', exitCode: 1),
        ]);

        $ok = $this->service->setAcls(
            '/var/sambaedu/Classes/Classe_6A',
            ['user::rwx'],
        );

        $this->assertFalse($ok);
    }

    #[Test]
    public function it_logs_error_on_partial_setfacl_failure_and_returns_false(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with('AclService: setAcls échec ACL', \Mockery::on(fn ($ctx) => $ctx['acl'] === 'group:bogus:xyz'));

        // wipe OK, batch échoue sur la 2e ACL uniquement.
        $callCount = 0;
        Process::fake(function ($process) use (&$callCount) {
            $callCount++;
            // 1er call = wipe → succès
            // 2e call = ACL valide → succès
            // 3e call = ACL bogus → échec
            if ($callCount === 3) {
                return Process::result(output: '', errorOutput: 'invalid ACL', exitCode: 1);
            }
            return Process::result(output: '', exitCode: 0);
        });

        $ok = $this->service->setAcls(
            '/var/sambaedu/Classes/Classe_6A',
            ['user::rwx', 'group:bogus:xyz']
        );

        $this->assertFalse($ok);
    }

    // =========================================================================
    // addAcl / removeAcl
    // =========================================================================

    #[Test]
    public function it_adds_single_acl_with_setfacl_m(): void
    {
        Process::fake([
            'sudo setfacl *' => Process::result(output: '', exitCode: 0),
        ]);

        $ok = $this->service->addAcl('/var/sambaedu/Classes/Classe_6A', 'group:Classe_6a:rx', recurse: true);
        $this->assertTrue($ok);

        Process::assertRan(fn ($p) => str_contains($p->command, 'setfacl -R -P -m')
            && str_contains($p->command, 'group:Classe_6a:rx'));
    }

    #[Test]
    public function it_removes_single_acl_with_setfacl_x(): void
    {
        Process::fake([
            'sudo setfacl *' => Process::result(output: '', exitCode: 0),
        ]);

        $ok = $this->service->removeAcl('/var/sambaedu/Classes/Classe_6A', 'user:alice:rwx', recurse: false);
        $this->assertTrue($ok);

        Process::assertRan(fn ($p) => str_contains($p->command, 'setfacl  -x')
            && str_contains($p->command, 'user:alice:rwx'));
    }

    // =========================================================================
    // getFacl — parsing
    // =========================================================================

    #[Test]
    public function it_gets_facl_and_parses_named_groups(): void
    {
        $sample = <<<TXT
user::rwx
group::---
group:equipe_6a:r-x
group:Classe_6a:r-x
mask::rwx
other::---
default:user::rwx
default:group::---
default:group:equipe_6a:r-x
default:mask::rwx
default:other::---
TXT;
        Process::fake([
            'sudo getfacl *' => Process::result(output: $sample, exitCode: 0),
        ]);

        $acl = $this->service->getFacl('/var/sambaedu/Classes/Classe_6A');

        $this->assertIsArray($acl);
        $this->assertArrayHasKey('equipe_6a', $acl);
        $this->assertSame('group', $acl['equipe_6a']['type']);
        $this->assertSame('r-x', $acl['equipe_6a']['mode']);
        $this->assertSame('r-x', $acl['equipe_6a']['default_mode']);
        $this->assertArrayHasKey('Classe_6a', $acl);
    }

    #[Test]
    public function get_facl_returns_false_when_command_fails(): void
    {
        Process::fake([
            'sudo getfacl *' => Process::result(output: '', errorOutput: 'no such file', exitCode: 1),
        ]);

        $this->assertFalse($this->service->getFacl('/var/sambaedu/Classes/Classe_NoExist'));
    }

    // =========================================================================
    // checkAcls
    // =========================================================================

    #[Test]
    public function check_acls_returns_false_when_path_not_dir(): void
    {
        Process::fake();

        // Le path est syntaxiquement valide mais le dossier n'existe pas
        // (on n'a rien créé sur le filesystem).
        $this->assertFalse($this->service->checkAcls(
            '/var/sambaedu/Classes/Classe_NoExist',
            ['user::rwx']
        ));
    }

    // =========================================================================
    // validatePath — anti-injection (AC 12)
    // =========================================================================

    #[Test]
    public function it_validates_legitimate_paths_inside_classes_root(): void
    {
        $this->assertTrue($this->service->validatePath('/var/sambaedu/Classes/Classe_6A'));
        $this->assertTrue($this->service->validatePath('/var/sambaedu/Classes/Classe_6A/_travail'));
        $this->assertTrue($this->service->validatePath('/var/sambaedu/Classes/Classe_6A/alice'));
        $this->assertTrue($this->service->validatePath('/var/sambaedu/Classes/Classe_6A/alice/Archives'));
    }

    public static function maliciousPathProvider(): array
    {
        return [
            'path traversal dotdot'    => ['/var/sambaedu/Classes/../etc/passwd'],
            'absolute outside root'    => ['/etc/passwd'],
            'command injection semi'   => ['/var/sambaedu/Classes/Classe_6A; rm -rf /'],
            'command pipe'             => ['/var/sambaedu/Classes/Classe_6A|cat /etc/shadow'],
            'backtick injection'       => ['/var/sambaedu/Classes/Classe_`whoami`'],
            'dollar expansion'         => ['/var/sambaedu/Classes/$(id)'],
            'space in segment'         => ['/var/sambaedu/Classes/Classe 6A'],
            'null byte'                => ["/var/sambaedu/Classes/Classe_6A\0"],
            'too deep'                 => ['/var/sambaedu/Classes/Classe_6A/a/b/c/d/e'],
            'empty path'               => [''],
            'relative path'            => ['Classe_6A'],
            'newline injection'        => ["/var/sambaedu/Classes/Classe_6A\nrm -rf /"],
            'glob expansion'           => ['/var/sambaedu/Classes/Classe_*'],
            'double dot in middle'     => ['/var/sambaedu/Classes/Classe_6A/../etc'],
        ];
    }

    #[Test]
    #[DataProvider('maliciousPathProvider')]
    public function it_rejects_paths_outside_classes_root_or_with_injection(string $path): void
    {
        $this->assertFalse(
            $this->service->validatePath($path),
            "Path malicieux accepté : {$path}"
        );
    }

    #[Test]
    public function set_acls_rejects_invalid_path_without_running_setfacl(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with('AclService: setAcls path invalide', \Mockery::any());

        Process::fake();

        $ok = $this->service->setAcls(
            '/etc/passwd',
            ['user::rwx']
        );

        $this->assertFalse($ok);
        // Aucune commande shell n'a été exécutée — la garde regex prime sur tout.
        Process::assertNothingRan();
    }

    // =========================================================================
    // Override classesRoot en tests (D13)
    // =========================================================================

    #[Test]
    public function it_supports_classes_root_override_via_static_property(): void
    {
        AclService::$classesRoot = '/tmp/test-classes-' . uniqid();

        // Path aligné sur la nouvelle racine = valide.
        $this->assertTrue($this->service->validatePath(AclService::$classesRoot . '/Classe_X'));
        // Path sous l'ancienne racine = refusé.
        $this->assertFalse($this->service->validatePath('/var/sambaedu/Classes/Classe_X'));
    }
}
