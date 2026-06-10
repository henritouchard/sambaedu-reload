<?php

declare(strict_types=1);

namespace Tests\Unit\Wpkg\Deployment\Rules;

use App\Wpkg\Deployment\Rules\SafeIpCidrRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 15.6 / AC3 / AC6.4 — Tests unit de SafeIpCidrRule.
 *
 * Couvre tous les cas du Volet 3 :
 *   - AC3.1 : IP v4/v6 valides ou CIDR valides → acceptés
 *   - AC3.2 : 0.0.0.0/0 et ::/0 → rejetés
 *   - AC3.3 : préfixes trop larges (IPv4 < /16, IPv6 < /32) → rejetés
 *   - AC3.4 : entrées syntaxiquement invalides → rejetées
 *   - AC3.5 : entrées vides → traitées (rejetées car non-string/vide)
 */
#[Group('wpkg-deploy')]
#[Group('story-15-6')]
class SafeIpCidrRuleTest extends TestCase
{
    private SafeIpCidrRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new SafeIpCidrRule();
    }

    /**
     * Valide l'entrée et retourne le message d'erreur ou null si valide.
     */
    private function validate(mixed $value): ?string
    {
        $error = null;
        $this->rule->validate('ip', $value, function (string $msg) use (&$error): void {
            $error = $msg;
        });
        return $error;
    }

    // =========================================================================
    // AC3.1 — Entrées valides (IP v4/v6 simples ou CIDR)
    // =========================================================================

    /**
     * @return array<string, array{string}>
     */
    public static function validEntriesProvider(): array
    {
        return [
            'IPv4 simple'              => ['192.168.1.1'],
            'IPv4 loopback'            => ['127.0.0.1'],
            'IPv4 CIDR /16'            => ['192.168.0.0/16'],
            'IPv4 CIDR /24'            => ['192.168.1.0/24'],
            'IPv4 CIDR /32'            => ['10.0.0.1/32'],
            'IPv6 simple'              => ['::1'],
            'IPv6 full'                => ['2001:db8::1'],
            'IPv6 CIDR /32'            => ['2001:db8::/32'],
            'IPv6 CIDR /64'            => ['2001:db8::/64'],
            'IPv6 CIDR /128'           => ['2001:db8::1/128'],
            'IPv4 CIDR /16 boundary'   => ['10.0.0.0/16'],
            'IPv4 CIDR /17'            => ['192.168.128.0/17'],
            'IPv6 CIDR /32 boundary'   => ['fd00::/32'],
        ];
    }

    #[Test]
    #[DataProvider('validEntriesProvider')]
    public function valid_entries_are_accepted(string $entry): void
    {
        self::assertNull($this->validate($entry), "Expected '$entry' to be valid, got error.");
    }

    // =========================================================================
    // AC3.2 — Rejet dur des wildcards Internet
    // =========================================================================

    #[Test]
    public function deny_all_ipv4_is_rejected(): void
    {
        $error = $this->validate('0.0.0.0/0');

        self::assertNotNull($error);
        self::assertStringContainsString('Internet', $error);
    }

    #[Test]
    public function deny_all_ipv6_is_rejected(): void
    {
        $error = $this->validate('::/0');

        self::assertNotNull($error);
        self::assertStringContainsString('Internet', $error);
    }

    // =========================================================================
    // AC3.3 — Préfixes trop larges
    // =========================================================================

    /**
     * @return array<string, array{string, string}>
     */
    public static function tooWidePrefixProvider(): array
    {
        return [
            'IPv4 /0'    => ['10.0.0.0/0', 'trop large'],
            'IPv4 /8'    => ['10.0.0.0/8', 'trop large'],
            'IPv4 /15'   => ['192.168.0.0/15', 'trop large'],
            'IPv6 /0'    => ['2001::/0', 'trop large'],
            'IPv6 /16'   => ['2001:db8::/16', 'trop large'],
            'IPv6 /31'   => ['fd00::/31', 'trop large'],
        ];
    }

    #[Test]
    #[DataProvider('tooWidePrefixProvider')]
    public function too_wide_prefix_is_rejected(string $entry, string $expectedFragment): void
    {
        $error = $this->validate($entry);

        self::assertNotNull($error, "Expected '$entry' to be rejected.");
        self::assertStringContainsString($expectedFragment, $error);
    }

    // =========================================================================
    // AC3.4 — Syntaxe invalide
    // =========================================================================

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidSyntaxProvider(): array
    {
        return [
            'IP invalide 999.1.1.1'      => ['999.1.1.1'],
            'Texte quelconque'            => ['abc'],
            'Domaine'                     => ['example.com'],
            'CIDR trop grand /40'         => ['192.168.0.0/40'],
            'CIDR IPv6 trop grand /129'   => ['2001::/129'],
            'CIDR mauvaise IP'            => ['999.0.0.0/24'],
            'Underscore'                  => ['192.168.1_1'],
            'Chaîne vide'                 => [''],
        ];
    }

    #[Test]
    #[DataProvider('invalidSyntaxProvider')]
    public function invalid_syntax_is_rejected(mixed $entry): void
    {
        $error = $this->validate($entry);

        self::assertNotNull($error, "Expected '$entry' to be rejected as invalid.");
    }

    // =========================================================================
    // Cas limites
    // =========================================================================

    #[Test]
    public function non_string_value_is_rejected(): void
    {
        self::assertNotNull($this->validate(null));
        self::assertNotNull($this->validate(123));
        self::assertNotNull($this->validate(true));
        self::assertNotNull($this->validate([]));
    }

    #[Test]
    public function ipv4_cidr_prefix_16_is_allowed(): void
    {
        // Le seuil /16 est la limite inclusive (=16 est autorisé).
        self::assertNull($this->validate('192.168.0.0/16'));
    }

    #[Test]
    public function ipv6_cidr_prefix_32_is_allowed(): void
    {
        // Le seuil /32 est la limite inclusive (=32 est autorisé).
        self::assertNull($this->validate('fd00::/32'));
    }

    #[Test]
    public function ipv4_cidr_prefix_15_is_rejected(): void
    {
        // /15 < /16 → trop large.
        $error = $this->validate('192.168.0.0/15');
        self::assertNotNull($error);
        self::assertStringContainsString('trop large', $error);
    }

    #[Test]
    public function ipv6_cidr_prefix_31_is_rejected(): void
    {
        // /31 < /32 → trop large.
        $error = $this->validate('fd00::/31');
        self::assertNotNull($error);
        self::assertStringContainsString('trop large', $error);
    }
}
