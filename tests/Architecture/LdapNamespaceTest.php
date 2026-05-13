<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Garde-fou architectural Story 16.7 (AC7.4).
 *
 * Vérifie que `app/Ldap/*` :
 *
 *  1. Utilise `SambaToolRunner` mode array — pas de concaténation shell
 *     (parité 16.3b `AdUserManager`, étendu à 16.7 `AdMachineManager`).
 *  2. N'invoque pas `exec`/`shell_exec`/`passthru`/`proc_open` directs.
 *  3. Ne contient pas de string contenant `samba-tool ` suivie d'une variable
 *     interpolée (`samba-tool $foo`) — détection par grep défensif.
 */
class LdapNamespaceTest extends TestCase
{
    /**
     * Patterns interdits dans `app/Ldap/*.php` (commentaires strippés).
     *
     * @var list<array{pattern: string, label: string}>
     */
    private const FORBIDDEN_EVERYWHERE = [
        ['pattern' => '/\bexec\s*\(/i',        'label' => 'exec()'],
        ['pattern' => '/\bshell_exec\s*\(/i',  'label' => 'shell_exec()'],
        ['pattern' => '/\bpassthru\s*\(/i',    'label' => 'passthru()'],
        ['pattern' => '/\bproc_open\s*\(/i',   'label' => 'proc_open()'],
        // Process facade interdite : passer par SambaToolRunner.
        ['pattern' => '/Illuminate\\\\Support\\\\Facades\\\\Process/i', 'label' => 'facade Process'],
    ];

    #[Test]
    public function no_direct_shell_execution_in_ldap_namespace(): void
    {
        $namespaceRoot = realpath(__DIR__ . '/../../app/Ldap');
        if ($namespaceRoot === false) {
            self::markTestSkipped('app/Ldap absent');
            return;
        }
        $finder = (new Finder())->files()->in($namespaceRoot)->name('*.php');

        $violations = [];
        foreach ($finder as $file) {
            $code = $file->getContents();
            $stripped = preg_replace('!/\*.*?\*/!s', '', $code) ?? $code;
            $stripped = preg_replace('/^\s*\/\/.*$/m', '', $stripped) ?? $stripped;

            foreach (self::FORBIDDEN_EVERYWHERE as $rule) {
                if (preg_match($rule['pattern'], $stripped) === 1) {
                    $violations[] = sprintf(
                        '%s utilise %s — interdit dans app/Ldap (passer par App\\Gpo\\Support\\SambaToolRunner)',
                        $file->getRelativePathname(),
                        $rule['label'],
                    );
                }
            }
        }

        self::assertSame([], $violations, "Violations garde-fou Story 16.7 (shell exec hors SambaToolRunner) :\n  - " . implode("\n  - ", $violations));
    }

    #[Test]
    public function no_concat_shell_with_samba_tool_string(): void
    {
        $namespaceRoot = realpath(__DIR__ . '/../../app/Ldap');
        if ($namespaceRoot === false) {
            self::markTestSkipped('app/Ldap absent');
            return;
        }
        $finder = (new Finder())->files()->in($namespaceRoot)->name('*.php');

        $violations = [];
        foreach ($finder as $file) {
            $code = $file->getContents();
            $stripped = preg_replace('!/\*.*?\*/!s', '', $code) ?? $code;
            $stripped = preg_replace('/^\s*\/\/.*$/m', '', $stripped) ?? $stripped;

            // Pattern interdit : `'samba-tool ' . $var` ou `"samba-tool $var"`.
            if (preg_match('/["\']samba-tool[^"\']*["\']\s*\.\s*\$/i', $stripped) === 1) {
                $violations[] = sprintf(
                    '%s contient une concaténation string `samba-tool` + variable — utiliser mode array',
                    $file->getRelativePathname(),
                );
            }
            if (preg_match('/"samba-tool\b[^"]*\$/i', $stripped) === 1) {
                $violations[] = sprintf(
                    '%s contient une interpolation string `samba-tool $var` — utiliser mode array',
                    $file->getRelativePathname(),
                );
            }
        }

        self::assertSame([], $violations, "Violations garde-fou Story 16.7 (concaténation shell `samba-tool`) :\n  - " . implode("\n  - ", $violations));
    }

    /**
     * AC7.4 : aucun fichier sous `app/Gpo/Services/Application*` n'importe
     * `LdapRecord` directement — il passe par AdMachineManager ou WorkstationRepository.
     */
    #[Test]
    public function application_services_do_not_import_ldap_record(): void
    {
        $gpoServices = realpath(__DIR__ . '/../../app/Gpo/Services');
        if ($gpoServices === false) {
            self::markTestSkipped('app/Gpo/Services absent');
            return;
        }
        $finder = (new Finder())->files()->in($gpoServices)->name('Application*.php');

        $violations = [];
        foreach ($finder as $file) {
            $code = $file->getContents();
            if (preg_match('/use\s+LdapRecord\\\\/', $code) === 1) {
                $violations[] = sprintf(
                    '%s importe LdapRecord directement — passer par AdMachineManager / WorkstationRepository',
                    $file->getRelativePathname(),
                );
            }
        }

        self::assertSame([], $violations, "Violations AC7.4 :\n  - " . implode("\n  - ", $violations));
    }

    /**
     * AC7.4 : aucun fichier sous `app/Gpo/Services/Application*` n'écrit
     * hors `/tmp/applications-*` (grep défensif sur `file_put_contents`).
     */
    #[Test]
    public function application_services_do_not_write_outside_tmp_applications(): void
    {
        $gpoServices = realpath(__DIR__ . '/../../app/Gpo/Services');
        if ($gpoServices === false) {
            self::markTestSkipped('app/Gpo/Services absent');
            return;
        }
        $finder = (new Finder())->files()->in($gpoServices)->name('Application*.php');

        $violations = [];
        foreach ($finder as $file) {
            $code = $file->getContents();
            // Strip commentaires pour limiter faux positifs.
            $stripped = preg_replace('!/\*.*?\*/!s', '', $code) ?? $code;
            $stripped = preg_replace('/^\s*\/\/.*$/m', '', $stripped) ?? $stripped;

            // Détecte tout `file_put_contents` (interdit dans les services
            // applicatifs — délégué au Controller debug `/tmp/applications-*`).
            if (preg_match('/\bfile_put_contents\s*\(/i', $stripped) === 1) {
                $violations[] = sprintf(
                    '%s utilise file_put_contents — réservé au Controller debug /tmp/applications-*',
                    $file->getRelativePathname(),
                );
            }
        }

        self::assertSame([], $violations, "Violations AC7.4 :\n  - " . implode("\n  - ", $violations));
    }
}
