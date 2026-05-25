<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Support;

use App\Ipxe\Support\WindowsXmlPlaceholders;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 3.5 — T1.4 / AC1.3.
 *
 * Tests du helper {@see WindowsXmlPlaceholders} :
 *  - Catalogue.
 *  - Sanitization XML (escape special chars, anti-injection XML).
 *  - Sanitization shell-arg (anti-injection cmd.exe / batch).
 *  - Interpolation `###_<KEY>_###`.
 */
class WindowsXmlPlaceholdersTest extends TestCase
{
    /* ----------------------------- catalog ---------------------------- */

    /**
     * Post-review code-review #7 : le catalog mappe UNIQUEMENT les
     * placeholders config-based (lus via `config(...)`). Les placeholders
     * par-poste (`###_NAME_###`) sont gérés par
     * {@see WindowsUnattendBuilder} qui lit le modèle Workstation.
     *
     * Cf. docstring `WindowsXmlPlaceholders::catalog()` pour le détail.
     */
    #[Test]
    public function it_lists_the_2_config_placeholder_keys(): void
    {
        $catalog = WindowsXmlPlaceholders::catalog();
        self::assertCount(2, $catalog);
        self::assertArrayHasKey('ADMINSE_NAME', $catalog);
        self::assertArrayHasKey('SE4FS_NAME', $catalog);
        self::assertSame('sambaedu.windows.adminse_name', $catalog['ADMINSE_NAME']);
        self::assertSame('sambaedu.se4fs_name', $catalog['SE4FS_NAME']);
        // `###_NAME_###` n'est PAS dans ce catalog — géré par le builder.
        self::assertArrayNotHasKey('NAME', $catalog);
    }

    /* ---------------------------- sanitize ---------------------------- */

    #[Test]
    public function it_passes_through_simple_ascii_values(): void
    {
        self::assertSame('PC-101', WindowsXmlPlaceholders::sanitize('PC-101'));
        self::assertSame('adminse', WindowsXmlPlaceholders::sanitize('adminse'));
    }

    #[Test]
    public function it_escapes_xml_special_chars(): void
    {
        // Anti-injection XML : <, >, &, ", '.
        self::assertSame('&lt;EVIL&gt;', WindowsXmlPlaceholders::sanitize('<EVIL>'));
        self::assertSame('PC-101&amp;', WindowsXmlPlaceholders::sanitize('PC-101&'));
        self::assertSame('&quot;injection&quot;', WindowsXmlPlaceholders::sanitize('"injection"'));
        self::assertSame('&apos;test&apos;', WindowsXmlPlaceholders::sanitize("'test'"));
    }

    #[Test]
    public function it_replaces_newlines_with_space(): void
    {
        // \n + \r → espace (anti-injection ligne XML).
        self::assertSame('foo bar', WindowsXmlPlaceholders::sanitize("foo\nbar"));
        self::assertSame('foo bar', WindowsXmlPlaceholders::sanitize("foo\rbar"));
        self::assertSame('foo bar', WindowsXmlPlaceholders::sanitize("foo\r\nbar"));
    }

    #[Test]
    public function it_replaces_control_chars_with_space(): void
    {
        // \x00 (null byte), \x01-\x08, \x0B, \x0C, \x0E-\x1F → espace.
        self::assertSame('foo bar', WindowsXmlPlaceholders::sanitize("foo\x00bar"));
        self::assertSame('foo bar', WindowsXmlPlaceholders::sanitize("foo\x01bar"));
        self::assertSame('foo bar', WindowsXmlPlaceholders::sanitize("foo\x1Fbar"));
    }

    #[Test]
    public function it_handles_null_and_int_inputs(): void
    {
        self::assertSame('', WindowsXmlPlaceholders::sanitize(null));
        self::assertSame('42', WindowsXmlPlaceholders::sanitize(42));
        self::assertSame('0', WindowsXmlPlaceholders::sanitize(0));
    }

    #[Test]
    public function it_blocks_cdata_injection(): void
    {
        // L'attaquant tente d'injecter un CDATA. Le `]]>` est neutralisé via
        // escape XML (le `]` reste mais le `>` devient `&gt;`).
        $result = WindowsXmlPlaceholders::sanitize(']]><script>evil</script>');
        self::assertStringContainsString('&lt;script&gt;', $result);
        self::assertStringNotContainsString('<script>', $result);
    }

    /* -------------------------- shell-arg ---------------------------- */

    #[Test]
    public function it_blocks_shell_injection_via_sanitize_shell_arg(): void
    {
        // Anti-injection cmd.exe / batch.
        self::assertSame('foo_bar', WindowsXmlPlaceholders::sanitizeShellArg("foo;bar"));
        self::assertSame('foo_bar', WindowsXmlPlaceholders::sanitizeShellArg('foo&bar'));
        self::assertSame('foo_bar', WindowsXmlPlaceholders::sanitizeShellArg('foo|bar'));
        self::assertSame('foo__bar__', WindowsXmlPlaceholders::sanitizeShellArg('foo`$bar`$'));
        self::assertSame('foo_bar', WindowsXmlPlaceholders::sanitizeShellArg('foo"bar'));
        self::assertSame('foo_bar', WindowsXmlPlaceholders::sanitizeShellArg("foo'bar"));
    }

    #[Test]
    public function it_blocks_newlines_in_shell_arg(): void
    {
        // \n / \r → `_` (éviter de terminer la commande courante).
        self::assertSame('foo_bar', WindowsXmlPlaceholders::sanitizeShellArg("foo\nbar"));
        self::assertSame('foo_bar', WindowsXmlPlaceholders::sanitizeShellArg("foo\rbar"));
        self::assertSame('foo__bar', WindowsXmlPlaceholders::sanitizeShellArg("foo\r\nbar"));
    }

    #[Test]
    public function it_blocks_redirection_chars_in_shell_arg(): void
    {
        self::assertSame('foo_bar', WindowsXmlPlaceholders::sanitizeShellArg('foo>bar'));
        self::assertSame('foo_bar', WindowsXmlPlaceholders::sanitizeShellArg('foo<bar'));
        self::assertSame('foo_bar', WindowsXmlPlaceholders::sanitizeShellArg('foo^bar'));
    }

    #[Test]
    public function it_passes_through_normal_shell_safe_values(): void
    {
        self::assertSame('PC-101', WindowsXmlPlaceholders::sanitizeShellArg('PC-101'));
        self::assertSame('adminse', WindowsXmlPlaceholders::sanitizeShellArg('adminse'));
        self::assertSame('192.168.122.50', WindowsXmlPlaceholders::sanitizeShellArg('192.168.122.50'));
        self::assertSame('se4fs.lan', WindowsXmlPlaceholders::sanitizeShellArg('se4fs.lan'));
    }

    #[Test]
    public function it_blocks_null_byte_in_shell_arg(): void
    {
        self::assertSame('foo_bar', WindowsXmlPlaceholders::sanitizeShellArg("foo\x00bar"));
    }

    /* -------------------------- interpolate -------------------------- */

    #[Test]
    public function it_interpolates_placeholders(): void
    {
        $template = 'curl http://###_SE4FS_NAME_###/ipxe -u ###_ADMINSE_NAME_###';
        $result = WindowsXmlPlaceholders::interpolate($template, [
            'SE4FS_NAME' => 'se4fs.lan',
            'ADMINSE_NAME' => 'adminse',
        ]);
        self::assertSame('curl http://se4fs.lan/ipxe -u adminse', $result);
    }

    #[Test]
    public function it_interpolates_case_insensitive_keys(): void
    {
        $template = '###_NAME_### foo';
        $result = WindowsXmlPlaceholders::interpolate($template, ['name' => 'PC-101']);
        self::assertSame('PC-101 foo', $result);
    }

    #[Test]
    public function it_cleans_orphan_placeholders(): void
    {
        // Cleanup `###_FOO_###` non résolu → '' iso-legacy.
        $template = 'foo ###_NOT_RESOLVED_### bar ###_NAME_### baz';
        $result = WindowsXmlPlaceholders::interpolate($template, ['NAME' => 'PC-101']);
        self::assertSame('foo  bar PC-101 baz', $result);
    }

    #[Test]
    public function it_sanitizes_interpolated_values(): void
    {
        $template = '<ComputerName>###_NAME_###</ComputerName>';
        $result = WindowsXmlPlaceholders::interpolate($template, ['NAME' => 'PC-101&<EVIL>']);
        self::assertStringContainsString('PC-101&amp;&lt;EVIL&gt;', $result);
        self::assertStringNotContainsString('<EVIL>', $result);
    }

    /* ------------------------------------------------------------------
     * Post-review code-review #3 — sanitizeForTextContent.
     *
     * Variante de sanitize() destinée à `DOMNode::textContent =` qui escape
     * déjà nativement les XML chars lors de la sérialisation. Appliquer
     * htmlspecialchars avant textContent provoque un double-escape.
     * ------------------------------------------------------------------ */

    #[Test]
    public function sanitize_for_text_content_does_not_html_escape(): void
    {
        // Les caractères XML doivent rester bruts — DOMDocument escape natif.
        self::assertSame('A & B <evil>', WindowsXmlPlaceholders::sanitizeForTextContent('A & B <evil>'));
        self::assertSame('foo"bar', WindowsXmlPlaceholders::sanitizeForTextContent('foo"bar'));
    }

    #[Test]
    public function sanitize_for_text_content_removes_newlines(): void
    {
        self::assertSame('A B C', WindowsXmlPlaceholders::sanitizeForTextContent("A\nB\nC"));
        self::assertSame('A B C', WindowsXmlPlaceholders::sanitizeForTextContent("A\r\nB\rC"));
    }

    #[Test]
    public function sanitize_for_text_content_removes_non_printables(): void
    {
        // Char \x00 + \x07 (BEL) remplacés par espace.
        $input = "PC\x00-\x07101";
        // \x00 → ' ', '-' reste, \x07 → ' ', '101' reste → "PC - 101"
        self::assertSame('PC - 101', WindowsXmlPlaceholders::sanitizeForTextContent($input));
    }

    #[Test]
    public function sanitize_for_text_content_handles_null_and_int(): void
    {
        self::assertSame('', WindowsXmlPlaceholders::sanitizeForTextContent(null));
        self::assertSame('42', WindowsXmlPlaceholders::sanitizeForTextContent(42));
    }

    /* ------------------------------------------------------------------
     * Story 3.8 — D9 / AC8.1 / AC8.3 — sanitizeBatPlaceholder (0-trust).
     *
     * Stratégie 0-trust : tout char d'injection cmd.exe lève
     * `BatPlaceholderInjectionException`. Couvre les vecteurs RCE poste
     * Windows en SYSTEM via les .cmd batch.
     * ------------------------------------------------------------------ */

    #[Test]
    public function bat_placeholder_passes_through_safe_ascii_values(): void
    {
        self::assertSame('PC-101', WindowsXmlPlaceholders::sanitizeBatPlaceholder('PC-101'));
        self::assertSame('adminse', WindowsXmlPlaceholders::sanitizeBatPlaceholder('adminse'));
        self::assertSame('pc-techno-25', WindowsXmlPlaceholders::sanitizeBatPlaceholder('pc-techno-25'));
        self::assertSame('192.168.122.50', WindowsXmlPlaceholders::sanitizeBatPlaceholder('192.168.122.50'));
        self::assertSame('localdev.fr', WindowsXmlPlaceholders::sanitizeBatPlaceholder('localdev.fr'));
        // Letters + digits + dash + dot + underscore + plus etc. = OK.
        self::assertSame('PC_TEST-2026.local', WindowsXmlPlaceholders::sanitizeBatPlaceholder('PC_TEST-2026.local'));
    }

    #[Test]
    public function bat_placeholder_trims_whitespace_safely(): void
    {
        self::assertSame('PC-101', WindowsXmlPlaceholders::sanitizeBatPlaceholder('  PC-101  '));
        self::assertSame('PC-101', WindowsXmlPlaceholders::sanitizeBatPlaceholder("\tPC-101\t"));
    }

    #[Test]
    public function bat_placeholder_returns_empty_for_empty_or_whitespace(): void
    {
        self::assertSame('', WindowsXmlPlaceholders::sanitizeBatPlaceholder(''));
        self::assertSame('', WindowsXmlPlaceholders::sanitizeBatPlaceholder('  '));
        self::assertSame('', WindowsXmlPlaceholders::sanitizeBatPlaceholder("\t\t"));
    }

    /**
     * Data provider : 10+ vecteurs d'injection cmd.exe (AC8.3).
     *
     * @return array<string, array{string, string}>
     */
    public static function injectionVectorsProvider(): array
    {
        return [
            'semicolon command sep' => [';calc.exe', 'semicolon'],
            'ampersand command sep' => ['&dir', 'ampersand'],
            'pipe' => ['foo|bar', 'pipe'],
            'backtick PowerShell sub' => ['`whoami`', 'backtick'],
            'dollar PowerShell var' => ['$env:PATH', 'dollar'],
            'percent cmd var' => ['%COMSPEC%', 'percent'],
            'double quote' => ['"injection"', 'double quote'],
            'single quote' => ["'injection'", 'single quote'],
            'backslash' => ['foo\\bar', 'backslash'],
            'newline' => ["foo\nbar", 'newline'],
            'CR newline' => ["foo\r\nbar", 'CR newline'],
            'null byte' => ["foo\x00bar", 'null byte'],
            'BEL char' => ["foo\x07bar", 'BEL'],
            'DEL char' => ["foo\x7Fbar", 'DEL'],
            'composite RCE' => ['";calc.exe;rem', 'composite injection'],
            'composite mixed shell' => ['foo;bar|baz&qux', 'composite chain'],
        ];
    }

    /**
     * @param  string  $payload  Vecteur d'injection à tester.
     * @param  string  $label    Description du vecteur (pour message d'erreur).
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('injectionVectorsProvider')]
    public function bat_placeholder_rejects_cmd_injection_vector(string $payload, string $label): void
    {
        $this->expectException(\App\Ipxe\Exceptions\BatPlaceholderInjectionException::class);
        WindowsXmlPlaceholders::sanitizeBatPlaceholder($payload);
    }

    #[Test]
    public function bat_placeholder_exception_message_is_informative(): void
    {
        try {
            WindowsXmlPlaceholders::sanitizeBatPlaceholder(';calc.exe');
            self::fail('Expected BatPlaceholderInjectionException not thrown');
        } catch (\App\Ipxe\Exceptions\BatPlaceholderInjectionException $e) {
            self::assertStringContainsString('cmd.exe', $e->getMessage());
            self::assertStringContainsString('0-trust', $e->getMessage());
        }
    }
}
