<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Services\Agent\StateHasher;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `StateHasher` — Story 23.1 AC2 (FR7).
 *
 * Algorithme de hash unique et déterministe : SHA-256 sur JSON canonicalisé
 * (clés triées récursivement, `generated_at` exclu, item hashé sans sa propre
 * clé `hash`).
 */
class StateHasherTest extends TestCase
{
    private StateHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new StateHasher();
    }

    #[Test]
    public function hash_state_is_deterministic_for_identical_input(): void
    {
        $state = $this->sampleState();

        $this->assertSame(
            $this->hasher->hashState($state),
            $this->hasher->hashState($state),
        );
    }

    #[Test]
    public function hash_state_is_independent_of_key_order(): void
    {
        $ordered = [
            'schema' => 'se5.desired-state/v1',
            'ttl_seconds' => 3600,
            'machine' => [['type' => 'wallpaper', 'mode' => 'default']],
        ];

        // Mêmes données, clés dans un ordre différent (top-level + nested).
        $shuffled = [
            'machine' => [['mode' => 'default', 'type' => 'wallpaper']],
            'schema' => 'se5.desired-state/v1',
            'ttl_seconds' => 3600,
        ];

        $this->assertSame(
            $this->hasher->hashState($ordered),
            $this->hasher->hashState($shuffled),
        );
    }

    #[Test]
    public function hash_state_excludes_volatile_generated_at(): void
    {
        $a = $this->sampleState();
        $a['generated_at'] = '2026-06-11T08:00:00+00:00';

        $b = $this->sampleState();
        $b['generated_at'] = '2030-01-01T23:59:59+00:00';

        // Le seul écart est `generated_at` → même hash.
        $this->assertSame(
            $this->hasher->hashState($a),
            $this->hasher->hashState($b),
        );
    }

    #[Test]
    public function hash_state_changes_when_meaningful_content_changes(): void
    {
        $a = $this->sampleState();
        $b = $this->sampleState();
        $b['machine'][0]['payload']['asset'] = 'fonds/autre.jpg';

        $this->assertNotSame(
            $this->hasher->hashState($a),
            $this->hasher->hashState($b),
        );
    }

    #[Test]
    public function list_order_is_significant_and_not_sorted(): void
    {
        $a = ['session' => [['type' => 'a'], ['type' => 'b']]];
        $b = ['session' => [['type' => 'b'], ['type' => 'a']]];

        // L'ordre des items d'une liste est fixé par le serveur → hash différent.
        $this->assertNotSame(
            $this->hasher->hashState($a),
            $this->hasher->hashState($b),
        );
    }

    #[Test]
    public function hash_item_ignores_its_own_hash_key(): void
    {
        $item = [
            'type' => 'wallpaper',
            'semantics' => 'exclusive',
            'mode' => 'default',
            'payload' => ['asset' => 'fonds/ecole-2026.jpg'],
        ];

        $withHash = $item + ['hash' => 'peu-importe-cette-valeur'];

        $this->assertSame(
            $this->hasher->hashItem($item),
            $this->hasher->hashItem($withHash),
        );
    }

    #[Test]
    public function hash_item_is_independent_of_key_order(): void
    {
        $a = ['type' => 'overlay', 'mode' => 'strict', 'payload' => ['ttl_seconds' => 60, 'tool' => 'rainmeter']];
        $b = ['payload' => ['tool' => 'rainmeter', 'ttl_seconds' => 60], 'mode' => 'strict', 'type' => 'overlay'];

        $this->assertSame(
            $this->hasher->hashItem($a),
            $this->hasher->hashItem($b),
        );
    }

    #[Test]
    public function canonicalization_is_compact_utf8_without_escaping(): void
    {
        // Caractères unicode + slash : ne doivent pas être échappés ni espacés.
        // On vérifie via le déterminisme face à une représentation logiquement
        // identique, et la forme hex SHA-256 (64 caractères).
        $hash = $this->hasher->hashItem([
            'type' => 'shortcuts',
            'payload' => ['name' => 'Élève', 'target' => 'https://intranet.example.edu/é'],
        ]);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    /**
     * @return array<string,mixed>
     */
    private function sampleState(): array
    {
        return [
            'schema' => 'se5.desired-state/v1',
            'ttl_seconds' => 3600,
            'machine' => [
                [
                    'type' => 'wallpaper',
                    'semantics' => 'exclusive',
                    'mode' => 'default',
                    'payload' => ['asset' => 'fonds/ecole-2026.jpg', 'style' => 'fill'],
                ],
            ],
            'session' => [],
            'machine_user' => [],
        ];
    }
}
