<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Support;

use App\Ipxe\Support\PreseedPlaceholders;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 3.4 — AC1.3 / T1.3.
 *
 * Tests unitaires du helper {@see PreseedPlaceholders} (sanitization +
 * interpolation des fragments preseed).
 */
class PreseedPlaceholdersTest extends TestCase
{
    #[Test]
    public function it_provides_catalog_with_required_placeholders(): void
    {
        $catalog = PreseedPlaceholders::catalog();

        // Catalogue ne contient PAS HOSTNAME ni UUID (par-poste, injectés
        // par LinuxPreseedService::generate()).
        self::assertArrayNotHasKey('HOSTNAME', $catalog);
        self::assertArrayNotHasKey('UUID', $catalog);

        // Mais contient les clés critiques de sambaedu.cfg + debian.cfg.
        $requiredKeys = [
            'LINUX_LOCALE', 'LINUX_KEYBOARD', 'VERSION_DEBIAN',
            'DOMAIN', 'SE4AD_IP', 'SE4FS_IP',
            'LDAP_PORT', 'LDAP_ADMIN_PASSWD', 'LDAP_BASE_DN',
            'ADMIN_PASSWD', 'ADMINSE_PASSWD',
            'SAMBA_DOMAIN', 'TOKEN', 'DEPOT_TYPE',
        ];
        foreach ($requiredKeys as $key) {
            self::assertArrayHasKey($key, $catalog, "Catalogue manque la clé {$key}");
        }
    }

    #[Test]
    public function it_sanitizes_empty_and_null(): void
    {
        self::assertSame('', PreseedPlaceholders::sanitize(null));
        self::assertSame('', PreseedPlaceholders::sanitize(''));
    }

    #[Test]
    public function it_sanitizes_newline_to_space(): void
    {
        self::assertSame('PC-101 ROOT_PASSWD=evil', PreseedPlaceholders::sanitize("PC-101\nROOT_PASSWD=evil"));
        self::assertSame('PC-101 ROOT_PASSWD=evil', PreseedPlaceholders::sanitize("PC-101\r\nROOT_PASSWD=evil"));
    }

    #[Test]
    public function it_sanitizes_non_ascii_to_question_mark(): void
    {
        self::assertSame('PC-?', PreseedPlaceholders::sanitize("PC-é"));
        self::assertSame('?', PreseedPlaceholders::sanitize("ñ"));
    }

    #[Test]
    public function it_sanitizes_command_injection_payloads(): void
    {
        // Path traversal — sanitize ne strip pas `..` ni `/` (chars légitimes
        // dans certaines valeurs), mais on s'assure que les newlines sont
        // remplacées (impossible d'injecter une ligne supplémentaire).
        $payload = "valid\n#malicious\nd-i passwd/root-password password evil";
        $sanitized = PreseedPlaceholders::sanitize($payload);
        self::assertStringNotContainsString("\n", $sanitized);
    }

    #[Test]
    public function it_sanitizes_null_byte(): void
    {
        $sanitized = PreseedPlaceholders::sanitize("PC-101\0evil");
        // Le null byte (0x00) est hors plage 0x20-0x7E + non TAB.
        self::assertStringNotContainsString("\0", $sanitized);
    }

    #[Test]
    public function it_interpolates_known_placeholder(): void
    {
        $template = "d-i netcfg/get_hostname string ###_HOSTNAME_###\n";
        $result = PreseedPlaceholders::interpolate($template, ['hostname' => 'pc-101']);
        self::assertSame("d-i netcfg/get_hostname string pc-101\n", $result);
    }

    #[Test]
    public function it_interpolates_case_insensitive_keys(): void
    {
        $template = "domain=###_DOMAIN_###\n";
        // Clé en lowercase fournie côté values.
        $result = PreseedPlaceholders::interpolate($template, ['domain' => 'example.org']);
        self::assertSame("domain=example.org\n", $result);
    }

    #[Test]
    public function it_strips_orphan_placeholders(): void
    {
        $template = "value=###_UNKNOWN_KEY_###\n";
        $result = PreseedPlaceholders::interpolate($template, []);
        self::assertSame("value=\n", $result);
    }

    #[Test]
    public function it_interpolates_sanitizes_value_with_newline(): void
    {
        $template = "hostname=###_HOSTNAME_###\n";
        $result = PreseedPlaceholders::interpolate($template, [
            'hostname' => "PC-101\nROOT_PASSWD=evil",
        ]);
        // Le newline injecté entre PC-101 et ROOT_PASSWD DOIT être strippé
        // (sinon une ligne preseed supplémentaire serait injectée). Le `\n`
        // final du template reste légitime.
        self::assertStringNotContainsString("PC-101\nROOT_PASSWD", $result);
        // Tout est sur la même ligne avec un espace au milieu.
        self::assertStringContainsString('hostname=PC-101 ROOT_PASSWD=evil', $result);
    }

    #[Test]
    public function it_extracts_placeholder_keys(): void
    {
        $template = "a=###_KEY_A_###\nb=###_KEY_B_###\nc=###_KEY_A_###";
        $keys = PreseedPlaceholders::extractKeys($template);
        sort($keys);
        self::assertSame(['KEY_A', 'KEY_B'], $keys);
    }

    #[Test]
    public function it_interpolates_multiple_placeholders(): void
    {
        $template = "host=###_HOSTNAME_###\nuuid=###_UUID_###\ndomain=###_DOMAIN_###\n";
        $result = PreseedPlaceholders::interpolate($template, [
            'hostname' => 'pc-101',
            'uuid' => 'abc-def-123',
            'domain' => 'example.org',
        ]);
        self::assertStringContainsString('host=pc-101', $result);
        self::assertStringContainsString('uuid=abc-def-123', $result);
        self::assertStringContainsString('domain=example.org', $result);
    }
}
