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

    #[Test]
    public function it_lists_the_3_placeholder_keys(): void
    {
        $catalog = WindowsXmlPlaceholders::catalog();
        self::assertArrayHasKey('ADMINSE_NAME', $catalog);
        self::assertArrayHasKey('SE4FS_NAME', $catalog);
        self::assertSame('sambaedu.windows.adminse_name', $catalog['ADMINSE_NAME']);
        self::assertSame('sambaedu.se4fs_name', $catalog['SE4FS_NAME']);
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
        self::assertSame('PC -101', WindowsXmlPlaceholders::sanitizeForTextContent($input)
        );
    }

    #[Test]
    public function sanitize_for_text_content_handles_null_and_int(): void
    {
        self::assertSame('', WindowsXmlPlaceholders::sanitizeForTextContent(null));
        self::assertSame('42', WindowsXmlPlaceholders::sanitizeForTextContent(42));
    }
}
