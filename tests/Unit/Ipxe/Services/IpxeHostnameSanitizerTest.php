<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Services;

use App\Ipxe\Services\IpxeHostnameSanitizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 3.3 — AC1.1 / T1.2.
 *
 * Tests unitaires du sanitizer hostname iPXE :
 *
 *  - {@see IpxeHostnameSanitizer::sanitize()}      — port iso-legacy `q2a()`.
 *  - {@see IpxeHostnameSanitizer::applyHostnameSuffix()} — port iso-legacy
 *    `add_hostname_suffix()` (`ldap.inc.php:380-394`).
 *  - {@see IpxeHostnameSanitizer::isValidHostname()} — regex anti-injection.
 *  - {@see IpxeHostnameSanitizer::isSpecialServerName()} — détection
 *    serveurs internes SE4FS/SE4AD (parité `enregistrement.php:39`).
 *
 * **Tests anti-injection (≥6 cas)** : couvre les vecteurs shell/iPXE
 * classiques pour garantir qu'aucun input malformé ne percole vers
 * `samba-tool`.
 */
class IpxeHostnameSanitizerTest extends TestCase
{
    private IpxeHostnameSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sambaedu.legacy_ldap.suffix', '');
        $this->sanitizer = new IpxeHostnameSanitizer();
    }

    #[Test]
    public function it_lowercases_input_in_sanitize(): void
    {
        self::assertSame('pc-001', $this->sanitizer->sanitize('PC-001'));
    }

    #[Test]
    public function it_trims_input_in_sanitize(): void
    {
        self::assertSame('pc-001', $this->sanitizer->sanitize("  PC-001  \n"));
    }

    #[Test]
    public function it_no_op_translates_in_legacy_platform(): void
    {
        // Parité iso-legacy `q2a()` — `if (false) { translation }` est dead code,
        // donc la string est inchangée (sauf lowercase+trim).
        $out = $this->sanitizer->sanitize('PC-CLAVIER-FR', 'legacy');
        self::assertSame('pc-clavier-fr', $out);
    }

    #[Test]
    public function it_truncates_to_15_chars_without_suffix(): void
    {
        config()->set('sambaedu.legacy_ldap.suffix', '');
        $long = str_repeat('a', 30);
        $out = $this->sanitizer->applyHostnameSuffix($long);
        self::assertSame(15, strlen($out));
        self::assertSame(str_repeat('a', 15), $out);
    }

    #[Test]
    public function it_appends_suffix_and_truncates_to_9_chars_when_suffix_set(): void
    {
        // Suffix `-x123abc` (8 chars) → name tronqué à 9 + suffix = 17 chars total.
        // Pas de cap 15 dans la branche suffix (parité legacy stricte).
        $out = $this->sanitizer->applyHostnameSuffix('mylongmachine', '-x123abc');
        self::assertSame('mylongmac-x123abc', $out);
    }

    #[Test]
    public function it_keeps_name_intact_when_already_suffixed(): void
    {
        // Idempotence iso-legacy ligne 387 : `if (! preg_match("/$suffix$/i", $name))`.
        $name = 'pc-001-suffix';
        $out = $this->sanitizer->applyHostnameSuffix($name, '-suffix');
        // Pas de re-tronquage : retour identité (lowercase).
        self::assertSame('pc-001-suffix', $out);
    }

    #[Test]
    public function it_reads_suffix_from_config_when_null_passed(): void
    {
        config()->set('sambaedu.legacy_ldap.suffix', '-test');
        $out = $this->sanitizer->applyHostnameSuffix('mylongmachine');
        self::assertSame('mylongmac-test', $out);
    }

    #[Test]
    public function it_validates_normal_hostname(): void
    {
        self::assertTrue($this->sanitizer->isValidHostname('pc-001-test'));
        self::assertTrue($this->sanitizer->isValidHostname('lab-pc-7'));
        self::assertTrue($this->sanitizer->isValidHostname('pc001$'));
    }

    #[Test]
    public function it_rejects_hostname_with_shell_metachar_semicolon(): void
    {
        self::assertFalse($this->sanitizer->isValidHostname('pc; rm -rf /'));
    }

    #[Test]
    public function it_rejects_hostname_with_space(): void
    {
        self::assertFalse($this->sanitizer->isValidHostname('pc 001'));
    }

    #[Test]
    public function it_rejects_hostname_with_pipe(): void
    {
        self::assertFalse($this->sanitizer->isValidHostname('pc|cat'));
    }

    #[Test]
    public function it_rejects_hostname_with_ampersand(): void
    {
        self::assertFalse($this->sanitizer->isValidHostname('pc&dropdb'));
    }

    #[Test]
    public function it_rejects_hostname_with_backtick(): void
    {
        self::assertFalse($this->sanitizer->isValidHostname('pc`whoami`'));
    }

    #[Test]
    public function it_rejects_hostname_with_newline(): void
    {
        self::assertFalse($this->sanitizer->isValidHostname("pc\nkernel http://evil"));
    }

    #[Test]
    public function it_rejects_hostname_with_quote(): void
    {
        self::assertFalse($this->sanitizer->isValidHostname("pc'inj"));
        self::assertFalse($this->sanitizer->isValidHostname('pc"inj'));
    }

    #[Test]
    public function it_rejects_hostname_with_uppercase(): void
    {
        // Post-sanitize on impose strict lowercase iso-legacy `add_hostname_suffix`.
        self::assertFalse($this->sanitizer->isValidHostname('PC-001'));
    }

    #[Test]
    public function it_rejects_empty_and_oversize_hostname(): void
    {
        self::assertFalse($this->sanitizer->isValidHostname(''));
        // Q4 (review 3.3) : cap relevé à 32 pour couvrir applyHostnameSuffix
        // avec suffix legacy_ldap. 33 chars → trop long.
        self::assertFalse($this->sanitizer->isValidHostname(str_repeat('a', 33)));
    }

    #[Test]
    public function it_accepts_hostname_up_to_32_chars(): void
    {
        // Q4 (review 3.3) : cap NetBIOS de 15 trop strict — un nom 16-32 chars
        // doit passer (couverture suffixes legacy_ldap longs).
        self::assertTrue($this->sanitizer->isValidHostname(str_repeat('a', 16)));
        self::assertTrue($this->sanitizer->isValidHostname(str_repeat('a', 32)));
    }

    /**
     * Q4 (review 3.3) — non-régression : un suffix legacy_ldap long appliqué
     * via applyHostnameSuffix() produit un nom > 15 chars qui DOIT rester
     * valide pour isValidHostname (sinon enrollment échoue silencieusement).
     */
    #[Test]
    public function it_validates_hostname_produced_by_apply_suffix_with_long_legacy_suffix(): void
    {
        $suffix = '.collegehugo';      // 12 chars (collège + nom)
        $sanitized = $this->sanitizer->sanitize('pc-salle-3-007');
        $withSuffix = $this->sanitizer->applyHostnameSuffix($sanitized, $suffix);

        // substr(<sanitized>,0,9) . $suffix = 'pc-salle-' . '.collegehugo' = 21 chars
        self::assertSame('pc-salle-.collegehugo', $withSuffix);
        self::assertTrue($this->sanitizer->isValidHostname($withSuffix));
    }

    #[Test]
    public function it_detects_se4fs_server_name(): void
    {
        self::assertTrue($this->sanitizer->isSpecialServerName('se4fs'));
        self::assertTrue($this->sanitizer->isSpecialServerName('SE4FS-MASTER'));
        self::assertTrue($this->sanitizer->isSpecialServerName('se4ad-1234567a'));
    }

    #[Test]
    public function it_does_not_detect_normal_pc_as_server_name(): void
    {
        self::assertFalse($this->sanitizer->isSpecialServerName('pc-001'));
        self::assertFalse($this->sanitizer->isSpecialServerName('se4-pc'));
        self::assertFalse($this->sanitizer->isSpecialServerName(''));
    }
}
