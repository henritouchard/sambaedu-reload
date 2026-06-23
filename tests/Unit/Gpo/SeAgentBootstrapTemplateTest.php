<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Support\GpoTemplateRegistry;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 25.4 (Tâche 4) + Story 27.16 (renommage SE5) — conformité du template
 * GPO-dispatcher figée `SE_agent_bootstrap` (ex-`se4_agent_bootstrap`, source
 * dans le repo sous `resources/gpo/`, déployable vers `/usr/share/sambaedu/gpo/`)
 * à {@see GpoTemplateRegistry}.
 *
 * On valide que :
 *  - le template est RECONNU comme publiable (préfixe SE5 `se_`, `GPT.INI` avec
 *    `[CSE]` machine) ;
 *  - le `GPT.INI` porte bien le displayName SE5 `SE_agent_bootstrap` ;
 *  - le `startup.cmd` est générique (CA + binaire stable + `agent.exe install`
 *    + tâche de refresh) et SANS logique métier (piège n° 16) ;
 *  - les scripts SYSVOL sont en CRLF + pur ASCII (piège n° 12).
 */
final class SeAgentBootstrapTemplateTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = base_path('resources/gpo/SE_agent_bootstrap');
    }

    #[Test]
    public function template_is_recognized_as_publishable_by_the_registry(): void
    {
        // Layout attendu par la registry (forme répertoire) :
        // <dir>/sambaedu-gpo/<name>/GPT.INI.
        $dir = storage_path('framework/testing/gpo-' . uniqid());
        File::ensureDirectoryExists($dir . '/sambaedu-gpo');
        File::copyDirectory($this->source, $dir . '/sambaedu-gpo/SE_agent_bootstrap');
        config(['sambaedu.gpo.templates_dir' => $dir]);

        $registry = new GpoTemplateRegistry();

        self::assertTrue(
            $registry->isPublishable('SE_agent_bootstrap'),
            'Le template SE_agent_bootstrap doit être reconnu publiable (préfixe se_, GPT.INI [CSE]).',
        );

        File::deleteDirectory($dir);
    }

    #[Test]
    public function gpt_ini_carries_a_cse_section_with_machine_extensions(): void
    {
        $gptIni = (string) file_get_contents($this->source . '/GPT.INI');
        self::assertStringContainsString('[CSE]', $gptIni);
        // Clé en MINUSCULES : le parseur legacy `get_gpo_template_info` teste
        // `isset($gptini['CSE']['gpcmachineextensionnames'])` (parse_ini_string
        // préserve la casse). Une clé `gPCMachineExtensionNames` ferait échouer
        // la reconnaissance → « gpo invalide ».
        self::assertStringContainsString('gpcmachineextensionnames=', $gptIni);
        self::assertStringNotContainsString('gPCMachineExtensionNames', $gptIni);
        // displayName SE5 (renommé 27.16) — l'ancien préfixe se4_ a disparu.
        self::assertStringContainsString('displayName=SE_agent_bootstrap', $gptIni);
        self::assertStringNotContainsString('se4_agent_bootstrap', $gptIni);
    }

    #[Test]
    public function startup_script_is_a_generic_dispatcher_without_business_logic(): void
    {
        $cmd = (string) file_get_contents($this->source . '/Machine/Scripts/Startup/startup.cmd');

        // (a) CA, (b) binaire stable, (c) install/réparation, (d) tâche refresh.
        self::assertStringContainsString('/api/v1/agent/ca', $cmd);
        self::assertStringContainsString('certutil', $cmd);
        self::assertStringContainsString('Root', $cmd);
        self::assertStringContainsString('/api/v1/agent/stable/download', $cmd);
        self::assertStringContainsString('agent.exe', $cmd);
        self::assertStringContainsString('install -server-url', $cmd);
        self::assertStringContainsString('schtasks', $cmd);
        // Spécialisation figée : nom serveur uniquement (piège n° 16).
        self::assertStringContainsString('###_SE4FS_NAME_###', $cmd);
        // AUCUNE logique métier : pas d'appel au canal de config legacy
        // (applications.php), pas de Registry.pol.
        self::assertStringNotContainsString('applications.php', $cmd);
        self::assertStringNotContainsString('Registry.pol', $cmd);
        // L'en-tête renommé SE5 ne doit plus mentionner l'ancien nom.
        self::assertStringNotContainsString('se4_agent_bootstrap', $cmd);
    }

    #[Test]
    public function sysvol_scripts_use_crlf_line_endings_and_pure_ascii(): void
    {
        // Piège n° 12 : tout .cmd/.bat déposé dans SYSVOL doit finir en \r\n —
        // LF seul échoue silencieusement. + pur ASCII (Story 27.16).
        foreach (['Machine/Scripts/Startup/startup.cmd', 'Machine/Scripts/scripts.ini', 'GPT.INI'] as $rel) {
            $content = (string) file_get_contents($this->source . '/' . $rel);

            self::assertStringContainsString("\r\n", $content, "{$rel} doit contenir des CRLF.");
            // Aucun LF orphelin (chaque \n est précédé d'un \r).
            self::assertSame(
                substr_count($content, "\n"),
                substr_count($content, "\r\n"),
                "{$rel} doit être intégralement en CRLF (aucun LF orphelin).",
            );
            // Pur ASCII (aucun octet > 0x7F).
            self::assertSame(
                1,
                preg_match('/^[\x00-\x7F]*$/', $content),
                "{$rel} doit être en pur ASCII (aucun octet non-ASCII).",
            );
        }
    }
}
