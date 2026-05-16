<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\V1\Services;

use App\Auth\V1\Services\LegacyBootstrapTokenValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.10 — AC4.2 / T5.7.
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
}
