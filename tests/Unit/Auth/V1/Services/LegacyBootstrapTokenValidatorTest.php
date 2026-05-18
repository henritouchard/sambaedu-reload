<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\V1\Services;

use App\Auth\V1\Services\LegacyBootstrapTokenValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.10 — AC4.2 / T5.7.
 * Story 16.11 — AC1.1 (durcissement couple token↔UUID).
 *
 * Tests `LegacyBootstrapTokenValidator`.
 *
 * Stratégie : si APCu est disponible (cf. phpunit.xml `apc.enable_cli=1`),
 * on teste le happy path en posant manuellement une clé `apps.<md5>` et
 * vérifie la détection. Sinon, on saute le test et on couvre uniquement
 * les cas d'invalidation format.
 */
class LegacyBootstrapTokenValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'auth_v1.bootstrap_token.apcu_prefix' => 'apps.',
            'auth_v1.bootstrap_token.token_regex' => '/^[a-f0-9]{32}$/i',
        ]);
    }

    private function apcuAvailable(): bool
    {
        return function_exists('apcu_store')
            && function_exists('apcu_enabled')
            && apcu_enabled();
    }

    #[Test]
    public function invalid_format_is_rejected(): void
    {
        $validator = new LegacyBootstrapTokenValidator();
        $this->assertFalse($validator->isValid(''));
        $this->assertFalse($validator->isValid('not-md5'));
        $this->assertFalse($validator->isValid('zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz'));
        // 31 chars = pas md5 valide
        $this->assertFalse($validator->isValid(str_repeat('a', 31)));
        // 33 chars = trop long
        $this->assertFalse($validator->isValid(str_repeat('a', 33)));
    }

    #[Test]
    public function valid_md5_in_apcu_returns_true(): void
    {
        if (! $this->apcuAvailable()) {
            $this->markTestSkipped('APCu not available — skipping live APCu test');
        }

        $token = md5('test-bootstrap-' . random_int(0, PHP_INT_MAX));
        apcu_store('apps.' . $token, ['mock' => 'context'], 60);

        $this->assertTrue((new LegacyBootstrapTokenValidator())->isValid($token));

        @apcu_delete('apps.' . $token);
    }

    #[Test]
    public function valid_format_but_not_in_apcu_returns_false(): void
    {
        if (! $this->apcuAvailable()) {
            $this->markTestSkipped('APCu not available — skipping live APCu test');
        }

        $token = md5('not-in-cache-' . random_int(0, PHP_INT_MAX));
        @apcu_delete('apps.' . $token); // pour être sûr

        $this->assertFalse((new LegacyBootstrapTokenValidator())->isValid($token));
    }

    // =================================================================
    // Story 16.11 — durcissement couple token↔UUID (AC1.1)
    // =================================================================

    #[Test]
    public function backward_compat_isvalid_without_uuid_arg_works(): void
    {
        if (! $this->apcuAvailable()) {
            $this->markTestSkipped('APCu not available');
        }

        $token = md5('bc-test-' . random_int(0, PHP_INT_MAX));
        apcu_store('apps.' . $token, ['uuid' => '11111111-1111-4111-8111-111111111111'], 60);

        // Appelant 16.10 (sans 2e arg) doit continuer à fonctionner.
        $this->assertTrue((new LegacyBootstrapTokenValidator())->isValid($token));

        @apcu_delete('apps.' . $token);
    }

    #[Test]
    public function isvalid_with_uuid_match_returns_true(): void
    {
        if (! $this->apcuAvailable()) {
            $this->markTestSkipped('APCu not available');
        }

        $uuid = '22222222-2222-4222-8222-222222222222';
        $token = md5('match-test-' . random_int(0, PHP_INT_MAX));
        apcu_store('apps.' . $token, ['uuid' => $uuid, 'other' => 'data'], 60);

        $this->assertTrue(
            (new LegacyBootstrapTokenValidator())->isValid($token, $uuid),
        );

        @apcu_delete('apps.' . $token);
    }

    #[Test]
    public function isvalid_with_uuid_mismatch_returns_false(): void
    {
        if (! $this->apcuAvailable()) {
            $this->markTestSkipped('APCu not available');
        }

        $stored = '33333333-3333-4333-8333-333333333333';
        $declared = '99999999-9999-4999-8999-999999999999';
        $token = md5('mismatch-test-' . random_int(0, PHP_INT_MAX));
        apcu_store('apps.' . $token, ['uuid' => $stored], 60);

        $this->assertFalse(
            (new LegacyBootstrapTokenValidator())->isValid($token, $declared),
        );

        @apcu_delete('apps.' . $token);
    }

    #[Test]
    public function isvalid_with_payload_missing_uuid_returns_false_fail_closed(): void
    {
        if (! $this->apcuAvailable()) {
            $this->markTestSkipped('APCu not available');
        }

        $token = md5('no-uuid-test-' . random_int(0, PHP_INT_MAX));
        // Payload SANS clé `uuid` — cas marginal mais doit fail-closed.
        apcu_store('apps.' . $token, ['user' => 'foo', 'machine' => 'bar'], 60);

        $this->assertFalse(
            (new LegacyBootstrapTokenValidator())->isValid($token, '11111111-1111-4111-8111-111111111111'),
        );

        @apcu_delete('apps.' . $token);
    }

    #[Test]
    public function checkmismatch_returns_true_only_on_real_mismatch(): void
    {
        if (! $this->apcuAvailable()) {
            $this->markTestSkipped('APCu not available');
        }

        $stored = '44444444-4444-4444-8444-444444444444';
        $declared = '55555555-5555-4555-8555-555555555555';
        $token = md5('check-mismatch-' . random_int(0, PHP_INT_MAX));
        apcu_store('apps.' . $token, ['uuid' => $stored], 60);

        $validator = new LegacyBootstrapTokenValidator();
        $this->assertTrue($validator->checkMismatch($token, $declared));
        // Match → checkMismatch retourne false.
        $this->assertFalse($validator->checkMismatch($token, $stored));

        @apcu_delete('apps.' . $token);
    }

    #[Test]
    public function checkmismatch_returns_false_when_token_invalid(): void
    {
        // Pas d'APCu requis — quand le token est invalide format, le check
        // doit retourner false (pas de mismatch à détecter sur un token
        // déjà invalide).
        $validator = new LegacyBootstrapTokenValidator();

        $this->assertFalse($validator->checkMismatch('not-md5', '11111111-1111-4111-8111-111111111111'));
    }

    #[Test]
    public function isvalid_with_uuid_when_token_not_in_apcu_returns_false(): void
    {
        if (! $this->apcuAvailable()) {
            $this->markTestSkipped('APCu not available');
        }

        $token = md5('absent-test-' . random_int(0, PHP_INT_MAX));
        @apcu_delete('apps.' . $token);

        $this->assertFalse(
            (new LegacyBootstrapTokenValidator())->isValid(
                $token,
                '11111111-1111-4111-8111-111111111111',
            ),
        );
    }

    // =================================================================
    // Story 16.11 — correction #3 + Opus-C : strtolower symétrique UUID
    // =================================================================

    #[Test]
    public function it_matches_uppercase_declared_uuid_against_lowercase_context_uuid(): void
    {
        if (! $this->apcuAvailable()) {
            $this->markTestSkipped('APCu not available');
        }

        // Contexte APCu : uuid stocké en lowercase (iso-legacy).
        $uuidLower = 'aabbccdd-1111-4111-8111-aabbccddeeff';
        $uuidUpper = strtoupper($uuidLower);

        $token = md5('case-upper-' . random_int(0, PHP_INT_MAX));
        apcu_store('apps.' . $token, ['uuid' => $uuidLower], 60);

        // Le poste déclare l'UUID en UPPERCASE → doit matcher quand même.
        $this->assertTrue(
            (new LegacyBootstrapTokenValidator())->isValid($token, $uuidUpper),
            'isValid doit être case-insensitive : UUID uppercase déclaré doit matcher UUID lowercase en APCu.',
        );

        @apcu_delete('apps.' . $token);
    }

    #[Test]
    public function it_matches_lowercase_declared_uuid_against_uppercase_context_uuid(): void
    {
        if (! $this->apcuAvailable()) {
            $this->markTestSkipped('APCu not available');
        }

        // Contexte APCu : uuid stocké en UPPERCASE (cas exotique mais possible).
        $uuidUpper = 'AABBCCDD-1111-4111-8111-AABBCCDDEEFF';
        $uuidLower = strtolower($uuidUpper);

        $token = md5('case-lower-' . random_int(0, PHP_INT_MAX));
        apcu_store('apps.' . $token, ['uuid' => $uuidUpper], 60);

        // Le poste déclare l'UUID en lowercase → doit matcher quand même.
        $this->assertTrue(
            (new LegacyBootstrapTokenValidator())->isValid($token, $uuidLower),
            'isValid doit être case-insensitive : UUID lowercase déclaré doit matcher UUID uppercase en APCu.',
        );

        @apcu_delete('apps.' . $token);
    }
}
