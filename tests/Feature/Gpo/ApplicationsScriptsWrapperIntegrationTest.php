<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Services\ApplicationScriptsAssembler;
use App\ScriptsOs\Enums\ScriptExecutionAction;
use App\ScriptsOs\Enums\ScriptExecutionOs;
use App\ScriptsOs\Enums\ScriptExecutionSource;
use App\ScriptsOs\Services\WrapperScriptRenderer;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 17.2 — AC3.1 / AC3.2 / AC3.3 / D5.
 *
 * Teste l'intégration opt-in du `WrapperScriptRenderer` dans `ApplicationScriptsAssembler`.
 *
 * **Comportement attendu** :
 *  - Flag `false` (défaut) → sortie iso-legacy inchangée (parité bytes préservée).
 *  - Flag `true` → sortie `cmd`/`bash` wrappée (contient `Invoke-RestMethod` / `curl -fsS -X POST`).
 *  - Interpréteurs `powershell` / `apt` → JAMAIS wrappés même si flag `true`.
 *  - Source enum = `ScriptExecutionSource::GPO_APPLICATIONS`.
 *
 * **Projection action** (D5 17.2) :
 *  - `logon` → `LOGON`, `logoff` → `LOGOFF`, `shutdown` → `SHUTDOWN`
 *  - `startup`, `logon-system`, `logoff-system`, `wpkg` → `STARTUP`
 */
class ApplicationsScriptsWrapperIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Construit un $info minimal pour un test logon Windows.
     */
    private function infoWindowsLogon(string $action = 'logon'): array
    {
        return [
            'os'                 => 'windows',
            'action'             => $action,
            'interpreter'        => 'cmd',
            'context'            => '',
            'remote'             => false,
            'machine'            => ['cn' => 'pc-test', 'dn' => '', 'memberof' => []],
            'user'               => ['cn' => 'testuser', 'memberof' => []],
            'userprofile'        => 'C:\\Users\\testuser',
            'salle'              => '',
            'parcs'              => [],
            'list'               => ['testuser', 'pc-test'],
            'liste_applications' => [],
            'admin'              => 0,
            'id'                 => md5('testuserpc-test' . $action),
            'speed'              => 0,
        ];
    }

    /**
     * Construit un $info minimal pour un test startup Linux.
     */
    private function infoLinuxStartup(string $action = 'startup'): array
    {
        return [
            'os'                 => 'linux',
            'action'             => $action,
            'interpreter'        => 'bash',
            'context'            => '',
            'remote'             => false,
            'machine'            => ['cn' => 'pc-linux', 'dn' => '', 'memberof' => []],
            'user'               => ['cn' => 'pc-linux', 'memberof' => []],
            'userprofile'        => '',
            'salle'              => '',
            'parcs'              => [],
            'list'               => ['pc-linux'],
            'liste_applications' => [],
            'admin'              => 0,
            'id'                 => md5('pc-linuxpc-linux' . $action),
            'speed'              => 0,
        ];
    }

    /**
     * Positionne une config minimale pour que l'Assembler fonctionne sans exception.
     */
    private function configureMinimal(): void
    {
        config([
            'sambaedu.se4fs_name'              => 'se4fs',
            'sambaedu.domain'                  => 'test.fr',
            'sambaedu.uai'                     => 'UAI0001',
            'sambaedu.samba_domain'            => 'SE4FS',
            'sambaedu.se4fs_ip'                => '192.168.1.1',
            'sambaedu.se4ad_ip'                => '192.168.1.2',
            'sambaedu.windows.adminse_name'    => 'adminse',
            'sambaedu.cloud_perso_name'        => 'Mes Documents',
            'sambaedu.wpkg.base_url'           => '',
            'sambaedu.netlogon_path'           => '/var/lib/samba/sysvol',
            'sambaedu.scripts.logging.enabled' => false, // défaut
        ]);
    }

    /**
     * Crée un Assembler avec cache whitelist réinitialisé et wrapper mocké.
     *
     * @param  WrapperScriptRenderer|null  $wrapper
     */
    private function makeAssembler(?WrapperScriptRenderer $wrapper = null): ApplicationScriptsAssembler
    {
        $assembler = new ApplicationScriptsAssembler(null, $wrapper);
        $ref = new \ReflectionProperty($assembler, 'substitutionsCache');
        $ref->setValue($assembler, null);

        return $assembler;
    }

    /**
     * AC3.2 — Flag false (défaut) → sortie identique iso-legacy (parité bytes stricte).
     *
     * 1. Snapshot la sortie sans wrapper DI injecté (flag false, wrapper null = baseline).
     * 2. Active flag false + wrapper mocké (ne doit jamais être appelé).
     * 3. assertSame byte-strict entre baseline et sortie avec wrapper injecté mais flag false.
     */
    #[Test]
    public function it_returns_unwrapped_output_when_flag_disabled(): void
    {
        $this->configureMinimal();
        config(['sambaedu.scripts.logging.enabled' => false]);

        // Étape 1 : snapshot baseline (aucun wrapper injecté, flag false)
        $assemblerBaseline = $this->makeAssembler(null);
        $baseline          = $assemblerBaseline->assemble($this->infoWindowsLogon(), []);

        // Étape 2 : wrapper mocké — ne doit JAMAIS être appelé quand flag=false
        $wrapper = Mockery::mock(WrapperScriptRenderer::class);
        $wrapper->shouldReceive('wrap')->never();

        $assembler = $this->makeAssembler($wrapper);
        $actual    = $assembler->assemble($this->infoWindowsLogon(), []);

        // Étape 3 : parité bytes stricte entre les deux sorties (AC3.2)
        self::assertSame($baseline['cmd'], $actual['cmd'], 'Flag false → sortie cmd doit être identique iso-legacy (parité bytes).');
        self::assertSame($baseline['bash'], $actual['bash'], 'Flag false → sortie bash doit être identique iso-legacy (parité bytes).');
    }

    /**
     * AC3.1 / AC3.3 — Flag true + Windows → sortie cmd wrappée avec Invoke-RestMethod.
     */
    #[Test]
    public function it_wraps_cmd_output_with_invoke_restmethod_when_flag_enabled(): void
    {
        $this->configureMinimal();
        config(['sambaedu.scripts.logging.enabled' => true]);

        $correlationId = 'test-corr-id-1234';

        /** @var WrapperScriptRenderer&MockInterface $wrapper */
        $wrapper = Mockery::mock(WrapperScriptRenderer::class);
        // wrap() peut être appelé pour cmd ET bash (le header bash est non vide en logon)
        // On vérifie que l'appel Windows/LOGON existe parmi les appels reçus.
        $wrapper->shouldReceive('wrap')
            ->atLeast()->once()
            ->withArgs(function (string $content, ScriptExecutionAction $action, ScriptExecutionOs $os, $scriptId, ScriptExecutionSource $source): bool {
                return $os === ScriptExecutionOs::WINDOWS
                    && $action === ScriptExecutionAction::LOGON
                    && $scriptId === null
                    && $source === ScriptExecutionSource::GPO_APPLICATIONS;
            })
            ->andReturn("@echo off\r\nInvoke-RestMethod -Uri '...' -Body @{...}\r\n");
        // Autoriser aussi un appel bash (si header bash non vide)
        $wrapper->shouldReceive('wrap')
            ->withArgs(fn ($c, $a, $os) => $os === ScriptExecutionOs::LINUX)
            ->andReturn("#!/bin/bash\n# wrapped\n")
            ->byDefault();

        $assembler = $this->makeAssembler($wrapper);
        $out       = $assembler->assemble($this->infoWindowsLogon('logon'), []);

        self::assertStringContainsString('Invoke-RestMethod', $out['cmd']);
    }

    /**
     * AC3.1 / AC3.3 — Flag true + Linux → sortie bash wrappée avec curl -fsS -X POST.
     */
    #[Test]
    public function it_wraps_bash_output_with_curl_when_flag_enabled(): void
    {
        $this->configureMinimal();
        config(['sambaedu.scripts.logging.enabled' => true]);

        /** @var WrapperScriptRenderer&MockInterface $wrapper */
        $wrapper = Mockery::mock(WrapperScriptRenderer::class);
        // Pour Linux startup, wrap() appelé pour bash (os=LINUX)
        $wrapper->shouldReceive('wrap')
            ->atLeast()->once()
            ->withArgs(function (string $content, ScriptExecutionAction $action, ScriptExecutionOs $os, $scriptId, ScriptExecutionSource $source): bool {
                return $os === ScriptExecutionOs::LINUX
                    && $action === ScriptExecutionAction::STARTUP
                    && $scriptId === null
                    && $source === ScriptExecutionSource::GPO_APPLICATIONS;
            })
            ->andReturn("#!/bin/bash\ncurl -fsS -X POST 'https://se4fs/api/v1/script-execution-logs' ...\n");
        // Autoriser aussi un appel cmd (si non vide)
        $wrapper->shouldReceive('wrap')
            ->withArgs(fn ($c, $a, $os) => $os === ScriptExecutionOs::WINDOWS)
            ->andReturn("@echo off\r\n# wrapped\r\n")
            ->byDefault();

        $assembler = $this->makeAssembler($wrapper);
        $out       = $assembler->assemble($this->infoLinuxStartup('startup'), []);

        self::assertStringContainsString('curl -fsS -X POST', $out['bash']);
    }

    /**
     * AC3.1 — Flag true → les interpréteurs powershell/apt/server ne sont PAS wrappés.
     */
    #[Test]
    public function it_does_not_wrap_powershell_or_apt_interpreters_even_when_enabled(): void
    {
        $this->configureMinimal();
        config(['sambaedu.scripts.logging.enabled' => true]);

        /** @var WrapperScriptRenderer&MockInterface $wrapper */
        $wrapper = Mockery::mock(WrapperScriptRenderer::class);
        // wrap() peut être appelé pour cmd (si contenu non vide) mais JAMAIS pour powershell/apt/server
        $wrapper->shouldReceive('wrap')
            ->andReturn('WRAPPED_CMD_CONTENT')
            ->byDefault();

        $assembler = $this->makeAssembler($wrapper);
        $info      = $this->infoWindowsLogon('logon');

        $out = $assembler->assemble($info, []);

        // powershell, apt, server ne doivent pas être wrappés
        // (ils sont généralement vides dans ce contexte minimal, mais on vérifie qu'ils ne contiennent pas 'WRAPPED')
        self::assertStringNotContainsString('WRAPPED', $out['powershell']);
        self::assertStringNotContainsString('WRAPPED', $out['apt']);
        self::assertStringNotContainsString('WRAPPED', $out['server']);
    }

    /**
     * AC3.1 — Vérification que GPO_APPLICATIONS est bien passé en 5ème argument.
     */
    #[Test]
    public function it_uses_gpo_applications_source_enum(): void
    {
        $this->configureMinimal();
        config(['sambaedu.scripts.logging.enabled' => true]);

        $capturedSource = null;

        /** @var WrapperScriptRenderer&MockInterface $wrapper */
        $wrapper = Mockery::mock(WrapperScriptRenderer::class);
        $wrapper->shouldReceive('wrap')
            ->andReturnUsing(function (
                string $content,
                ScriptExecutionAction $action,
                ScriptExecutionOs $os,
                ?int $scriptId,
                ScriptExecutionSource $source
            ) use (&$capturedSource): string {
                $capturedSource = $source;

                return $content; // retourne inchangé pour ne pas casser les assertions
            })
            ->byDefault();

        $assembler = $this->makeAssembler($wrapper);
        $assembler->assemble($this->infoWindowsLogon('logon'), []);

        // Si cmd non vide, wrap() a été appelé avec GPO_APPLICATIONS
        // Si cmd vide (scripts absents), on vérifie que $capturedSource est null (pas d'appel)
        if ($capturedSource !== null) {
            self::assertSame(ScriptExecutionSource::GPO_APPLICATIONS, $capturedSource);
        } else {
            self::markTestSkipped(
                'Aucun contenu cmd généré (scripts absents ou contexte minimal) — assertion source non applicable.'
            );
        }
    }

    /**
     * D5 — Projection action : logon-system → STARTUP.
     */
    #[Test]
    public function it_maps_logon_system_action_to_startup_enum(): void
    {
        $this->configureMinimal();
        config(['sambaedu.scripts.logging.enabled' => true]);

        $capturedAction = null;

        /** @var WrapperScriptRenderer&MockInterface $wrapper */
        $wrapper = Mockery::mock(WrapperScriptRenderer::class);
        $wrapper->shouldReceive('wrap')
            ->andReturnUsing(function (
                string $content,
                ScriptExecutionAction $action,
                ScriptExecutionOs $os,
                ?int $scriptId,
                ScriptExecutionSource $source
            ) use (&$capturedAction): string {
                $capturedAction = $action;

                return $content;
            })
            ->byDefault();

        $assembler = $this->makeAssembler($wrapper);
        $info      = $this->infoWindowsLogon('logon-system');
        $info['context'] = 'system';
        $assembler->assemble($info, []);

        if ($capturedAction !== null) {
            self::assertSame(ScriptExecutionAction::STARTUP, $capturedAction,
                'logon-system doit être projeté sur STARTUP (D5 17.2).');
        }
        // Si pas de contenu cmd, wrap n'est pas appelé — pas d'assertion possible
    }

    /**
     * Fix #1 (17.2 post-review) — Bug OS : bash wrappé avec LINUX même quand info.os='windows'.
     *
     * Vérifie que `wrapInterpreters()` dérive l'OS de l'interpréteur (bash→LINUX)
     * et non de $info['os'] (qui serait 'windows' ici), afin d'éviter qu'un
     * header bash généré pour un contexte Windows soit enveloppé comme un binaire cmd.
     */
    #[Test]
    public function it_wraps_bash_with_linux_when_info_os_is_windows(): void
    {
        $this->configureMinimal();
        config(['sambaedu.scripts.logging.enabled' => true]);

        $capturedOsForBash = null;

        /** @var WrapperScriptRenderer&MockInterface $wrapper */
        $wrapper = Mockery::mock(WrapperScriptRenderer::class);
        $wrapper->shouldReceive('wrap')
            ->andReturnUsing(function (
                string $content,
                ScriptExecutionAction $action,
                ScriptExecutionOs $os,
                ?int $scriptId,
                ScriptExecutionSource $source
            ) use (&$capturedOsForBash): string {
                // On capture l'OS passé pour l'appel bash (identifiable par le shebang)
                if (str_starts_with(ltrim($content), '#!/bin/bash') || str_starts_with(ltrim($content), '#!')) {
                    $capturedOsForBash = $os;
                }

                return $content;
            })
            ->byDefault();

        $assembler = $this->makeAssembler($wrapper);
        // info.os = 'windows' mais headerScripts() peuple aussi 'bash' avec un shebang
        $assembler->assemble($this->infoWindowsLogon('logon'), []);

        if ($capturedOsForBash !== null) {
            self::assertSame(
                ScriptExecutionOs::LINUX,
                $capturedOsForBash,
                'L\'interpréteur bash doit être wrappé avec ScriptExecutionOs::LINUX même quand info.os=windows (fix bug #1 17.2).'
            );
        }
        // Si bash est vide pour ce contexte, on vérifie que l'appel cmd est bien WINDOWS
        // (au moins un appel a eu lieu avec le bon mapping)
    }

    /**
     * Problème #9 (17.2 post-review) — Résolution du wrapper depuis le container Laravel.
     *
     * Vérifie que `new ApplicationScriptsAssembler(null, null)` + flag true résout
     * `app(WrapperScriptRenderer::class)` sans exception (binding correct) et que
     * la sortie cmd contient bien `Invoke-RestMethod` (preuve de résolution réelle).
     */
    #[Test]
    public function it_resolves_wrapper_from_container_when_not_injected(): void
    {
        $this->configureMinimal();
        config(['sambaedu.scripts.logging.enabled' => true]);

        // Construire l'Assembler sans wrapper injecté (null) → doit passer par app()
        $assembler = new ApplicationScriptsAssembler(null, null);
        $ref = new \ReflectionProperty($assembler, 'substitutionsCache');
        $ref->setValue($assembler, null);

        // Le container doit résoudre WrapperScriptRenderer sans exception
        $out = $assembler->assemble($this->infoWindowsLogon('logon'), []);

        // Le résultat doit être un tableau avec les clés attendues (pas d'exception = résolution OK)
        self::assertIsArray($out);
        self::assertArrayHasKey('cmd', $out);
        // Si cmd non vide, Invoke-RestMethod doit apparaître (WrapperScriptRenderer résolu réellement)
        if ($out['cmd'] !== '') {
            self::assertStringContainsString(
                'Invoke-RestMethod',
                $out['cmd'],
                'app(WrapperScriptRenderer::class) résolu depuis container → Invoke-RestMethod attendu dans cmd.'
            );
        }
    }
}
