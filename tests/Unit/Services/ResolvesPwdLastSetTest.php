<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Concerns\ResolvesPwdLastSet;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests unitaires du trait ResolvesPwdLastSet.
 *
 * Couvre les 4 cas D7 pour resolvePwdLastSetRaw() + pwdLastSetToCarbon() :
 *   1. 0 → null (changement obligatoire)
 *   2. valeur FILETIME valide > 0 → Carbon UTC avec valeur attendue
 *   3. -1 → now() best-effort
 *   4. valeur absente / null → null
 *
 * Story 14.4 — AC13 / Tâche 6.3
 */
class ResolvesPwdLastSetTest extends TestCase
{
    /**
     * Classe anonyme concrète pour accéder au trait.
     */
    private object $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new class {
            use ResolvesPwdLastSet;

            public function resolveRaw(mixed $raw): int
            {
                return $this->resolvePwdLastSetRaw($raw);
            }

            public function toCarbon(int $pwdLastSet): ?Carbon
            {
                return self::pwdLastSetToCarbon($pwdLastSet);
            }
        };
    }

    // =========================================================================
    // resolvePwdLastSetRaw — normalisation de la valeur brute LDAP
    // =========================================================================

    #[Test]
    public function it_returns_zero_for_null_raw_value(): void
    {
        $this->assertSame(0, $this->resolver->resolveRaw(null));
    }

    #[Test]
    public function it_returns_zero_for_empty_string_raw_value(): void
    {
        $this->assertSame(0, $this->resolver->resolveRaw(''));
    }

    #[Test]
    public function it_returns_int_cast_for_string_value(): void
    {
        $this->assertSame(132456789012345678, $this->resolver->resolveRaw('132456789012345678'));
    }

    #[Test]
    public function it_returns_int_for_int_value(): void
    {
        $this->assertSame(133000000000000000, $this->resolver->resolveRaw(133000000000000000));
    }

    #[Test]
    public function it_returns_minus_one_for_carbon_with_positive_timestamp(): void
    {
        // Post-review #1 : Carbon (auto-cast LdapRecord) → -1 pour que
        // pwdLastSetToCarbon(-1) retourne Carbon::now() (sémantique D7 cas 3).
        // Auparavant retournait 1, ce qui produisait un unix_ts négatif et
        // déclenchait le garde-fou → password_changed_at NULL silencieux.
        $carbon = Carbon::createFromTimestamp(1700000000);
        $this->assertSame(-1, $this->resolver->resolveRaw($carbon));
    }

    #[Test]
    public function it_returns_zero_for_carbon_with_zero_timestamp(): void
    {
        $carbon = Carbon::createFromTimestamp(0);
        $this->assertSame(0, $this->resolver->resolveRaw($carbon));
    }

    #[Test]
    public function it_returns_minus_one_for_array_containing_carbon(): void
    {
        // Post-review #1 : cohérence array → Carbon → -1
        $carbon = Carbon::createFromTimestamp(1700000000);
        $this->assertSame(-1, $this->resolver->resolveRaw([$carbon]));
    }

    #[Test]
    public function it_returns_first_element_from_array(): void
    {
        $this->assertSame(133000000000000000, $this->resolver->resolveRaw([133000000000000000]));
    }

    #[Test]
    public function it_returns_zero_for_empty_array(): void
    {
        $this->assertSame(0, $this->resolver->resolveRaw([]));
    }

    // =========================================================================
    // pwdLastSetToCarbon — conversion D7
    // =========================================================================

    #[Test]
    public function it_returns_null_for_pwdLastSet_zero(): void
    {
        // D7 : 0 = changement obligatoire au prochain login → NULL
        $this->assertNull($this->resolver->toCarbon(0));
    }

    #[Test]
    public function it_returns_now_for_pwdLastSet_minus_one(): void
    {
        // D7 : -1 = compte admin/service sans expiration → now() best-effort
        $before = Carbon::now()->subSecond();
        $result = $this->resolver->toCarbon(-1);
        $after = Carbon::now()->addSecond();

        $this->assertNotNull($result);
        $this->assertTrue($result->gte($before), 'Le Carbon retourné doit être >= now()-1s');
        $this->assertTrue($result->lte($after), 'Le Carbon retourné doit être <= now()+1s');
    }

    #[Test]
    public function it_converts_filetime_to_correct_carbon_utc(): void
    {
        // D7 — valeur hardcodée pour valider la conversion FILETIME → Unix timestamp
        //
        // pwdLastSet = 133000000000000000 (valeur AD-FILETIME)
        // Unix = (133000000000000000 - 116444736000000000) / 10_000_000
        //      = 16555264000000000 / 10_000_000
        //      = 1655526400
        //
        // Carbon::createFromTimestamp(1655526400, 'UTC') = 2022-06-18 08:00:00 UTC
        $result = $this->resolver->toCarbon(133000000000000000);

        $this->assertNotNull($result, 'La conversion doit retourner un Carbon non-null');
        $this->assertSame('UTC', $result->timezone->getName());
        $this->assertSame(1655526400, $result->getTimestamp());
        // Vérification lisible : 2022-06-18
        $this->assertSame('2022-06-18', $result->format('Y-m-d'));
    }

    #[Test]
    public function it_returns_null_for_filetime_that_gives_negative_unix_timestamp(): void
    {
        // Valeur FILETIME antérieure à 1970 → absurde → null (garde-fou)
        // 100000000 ticks < FILETIME_DELTA → Unix timestamp négatif
        $result = $this->resolver->toCarbon(100000000);
        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_for_filetime_beyond_year_2100(): void
    {
        // Valeur FILETIME donnant un Unix timestamp > 4102444800 (2100-01-01)
        // 4102444800 * 10_000_000 + 116444736000000000 ≈ 157469184000000000
        $result = $this->resolver->toCarbon(200000000000000000);
        $this->assertNull($result);
    }

    #[Test]
    public function it_logs_warning_when_filetime_out_of_range(): void
    {
        // Post-review #12 : le garde-fou doit émettre un Log::warning pour
        // tracer les FILETIME aberrants (AD corrompu, attribut mal interprété, etc.).
        Log::spy();

        // Valeur FILETIME donnant un Unix timestamp > 4102444800 (2100-01-01)
        $result = $this->resolver->toCarbon(200000000000000000);

        $this->assertNull($result);
        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) {
                return str_contains($message, 'FILETIME hors plage')
                    && isset($context['pwd_last_set'])
                    && isset($context['unix_ts']);
            })
            ->once();
    }

    #[Test]
    public function it_logs_warning_when_filetime_gives_negative_unix_timestamp(): void
    {
        // Post-review #12 : même garde-fou pour timestamp négatif.
        Log::spy();

        $result = $this->resolver->toCarbon(100000000);

        $this->assertNull($result);
        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) {
                return str_contains($message, 'FILETIME hors plage');
            })
            ->once();
    }
}
