<?php

declare(strict_types=1);

namespace Tests\Unit\Extensions;

use App\Services\Extensions\SudoExtensionHelperRunner;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 56.2 — Le seam privilégié RÉEL : composition de la commande et
 * plomberie `proc_open`.
 *
 * Deux moitiés, testées séparément parce qu'aucun `sudo` n'est configuré pour
 * ce helper sur l'hôte de développement :
 *
 *  1. **La composition** (`buildCommand`) est assertée telle quelle : `sudo -n`
 *     (non interactif — sans tty, un sudoers manquant échoue immédiatement au
 *     lieu de bloquer le worker sur un prompt invisible), chemin du helper issu
 *     de la configuration, et `escapeshellarg` sur CHAQUE argument.
 *  2. **Le transport** (stdin écrit puis FERMÉ avant lecture, flux séparés,
 *     code retour) est éprouvé pour de vrai, en substituant la commande par un
 *     binaire local sans privilège. C'est la partie où un bug coûterait cher :
 *     un stdin non fermé interbloquerait le worker PHP-FPM avec le helper.
 */
class SudoExtensionHelperRunnerTest extends TestCase
{
    // =====================================================================
    // 1. Composition de la commande
    // =====================================================================

    #[Test]
    public function the_command_goes_through_sudo_non_interactive_and_the_configured_helper(): void
    {
        Config::set('extensions.install.helper_path', '/usr/share/sambaedu/sbin/sambaedu-ext-helper.sh');

        $command = (new SudoExtensionHelperRunner())->buildCommand(['write-env', 'hello']);

        self::assertStringContainsString('sudo -n ', $command);
        self::assertStringContainsString("'/usr/share/sambaedu/sbin/sambaedu-ext-helper.sh'", $command);
        self::assertStringContainsString("'write-env'", $command);
        self::assertStringContainsString("'hello'", $command);
    }

    #[Test]
    public function every_argument_is_shell_escaped(): void
    {
        Config::set('extensions.install.helper_path', '/opt/helper.sh');

        $payload = "hello'; rm -rf /";
        $command = (new SudoExtensionHelperRunner())->buildCommand(['write-fragment', $payload, '8600']);

        // La charge utile apparaît UNIQUEMENT sous sa forme échappée : le
        // point-virgule reste dans un token quoté, il n'ouvre aucune seconde
        // commande. On l'affirme sur la forme exacte plutôt que par une
        // heuristique de sous-chaîne, qui passerait au vert pour de mauvaises
        // raisons.
        self::assertStringContainsString(escapeshellarg($payload), $command);
        self::assertStringNotContainsString($payload, str_replace(escapeshellarg($payload), '', $command));
        self::assertStringContainsString("'8600'", $command);

        // Preuve d'exécution : le shell voit UN seul argument, il n'exécute rien.
        $result = $this->localRunner('sh -c \'printf "%s" "$1"\' _ '.escapeshellarg($payload))->run([]);
        self::assertSame([$payload], $result['stdout']);
    }

    #[Test]
    public function no_argument_is_ever_interpolated_unquoted(): void
    {
        Config::set('extensions.install.helper_path', '/opt/helper.sh');

        $command = (new SudoExtensionHelperRunner())->buildCommand(['enable-service', '$(id)']);

        self::assertStringNotContainsString(' $(id)', $command);
        self::assertStringContainsString("'\$(id)'", $command);
    }

    // =====================================================================
    // 2. Transport : stdin, flux, code retour
    // =====================================================================

    /** Runner dont la commande est remplacée par un binaire local sans privilège. */
    private function localRunner(string $command): SudoExtensionHelperRunner
    {
        return new class($command) extends SudoExtensionHelperRunner
        {
            public function __construct(private readonly string $local)
            {
            }

            public function buildCommand(array $args): string
            {
                return $this->local;
            }
        };
    }

    #[Test]
    public function stdin_is_transmitted_and_the_pipe_is_closed(): void
    {
        // `cat` ne rend la main QUE sur EOF : si le tube d'entrée n'était pas
        // refermé avant la lecture des sorties, ce test s'interbloquerait.
        $result = $this->localRunner('cat')->run(['write-env', 'hello'], "SE5_EXT_KEY=hello\nSE5_EXT_PORT=8600\n");

        self::assertSame(0, $result['exitCode']);
        self::assertSame(['SE5_EXT_KEY=hello', 'SE5_EXT_PORT=8600'], $result['stdout']);
        self::assertSame([], $result['stderr']);
    }

    #[Test]
    public function stdout_and_stderr_are_captured_separately(): void
    {
        $result = $this->localRunner('sh -c \'echo sortie; echo erreur >&2\'')->run(['reload-apache']);

        self::assertSame(['sortie'], $result['stdout']);
        self::assertSame(['erreur'], $result['stderr']);
        self::assertSame(0, $result['exitCode']);
    }

    #[Test]
    public function a_non_zero_exit_code_is_reported_verbatim(): void
    {
        $result = $this->localRunner('sh -c \'exit 42\'')->run(['install-package']);

        self::assertSame(42, $result['exitCode']);
    }

    #[Test]
    public function an_absent_stdin_does_not_hang(): void
    {
        $result = $this->localRunner('cat')->run(['remove-env', 'hello'], null);

        self::assertSame(0, $result['exitCode']);
        self::assertSame([], $result['stdout']);
    }
}
