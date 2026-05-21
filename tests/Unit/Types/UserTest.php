<?php

declare(strict_types=1);

namespace Tests\Unit\Types;

use App\Types\User as AdUser;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires du DTO App\Types\User (Wireable).
 *
 * Story 14.4 — Post-review #11 :
 * Vérifie que passwordChangedAt round-trip correctement à travers le pipeline
 * Livewire toArray() / fromLivewire() (sérialisation ISO8601 + parsing miroir).
 */
class UserTest extends TestCase
{
    #[Test]
    public function it_round_trips_password_changed_at_through_livewire(): void
    {
        $date = Carbon::parse('2026-05-20 10:30:00', 'UTC');

        $original = new AdUser(
            login: 'roundtrip-user',
            fullname: 'Round Trip',
            passwordChangedAt: $date,
        );

        $serialized = $original->toArray();

        // toArray() doit exposer la clé passwordChangedAt en ISO8601 (non null).
        $this->assertArrayHasKey('passwordChangedAt', $serialized);
        $this->assertIsString($serialized['passwordChangedAt']);
        $this->assertSame($date->toIso8601String(), $serialized['passwordChangedAt']);

        // fromLivewire() doit reconstruire un Carbon équivalent.
        $rebuilt = AdUser::fromLivewire($serialized);

        $this->assertNotNull($rebuilt->passwordChangedAt);
        $this->assertInstanceOf(Carbon::class, $rebuilt->passwordChangedAt);
        $this->assertSame(
            $date->getTimestamp(),
            $rebuilt->passwordChangedAt->getTimestamp(),
            'Le timestamp doit être préservé à la seconde près'
        );
    }

    #[Test]
    public function it_serializes_null_password_changed_at_as_null(): void
    {
        $original = new AdUser(
            login: 'null-user',
            fullname: 'Null Date',
            passwordChangedAt: null,
        );

        $serialized = $original->toArray();

        $this->assertArrayHasKey('passwordChangedAt', $serialized);
        $this->assertNull($serialized['passwordChangedAt']);

        $rebuilt = AdUser::fromLivewire($serialized);
        $this->assertNull($rebuilt->passwordChangedAt);
    }

    #[Test]
    public function it_handles_missing_password_changed_at_key_in_fromLivewire(): void
    {
        // Compat ascendante : un payload Livewire sans la clé passwordChangedAt
        // (ex: serialisation antérieure à 14.4) doit produire null sans planter.
        $payload = [
            'login' => 'legacy-user',
            'fullname' => 'Legacy',
        ];

        $rebuilt = AdUser::fromLivewire($payload);

        $this->assertNull($rebuilt->passwordChangedAt);
    }
}
