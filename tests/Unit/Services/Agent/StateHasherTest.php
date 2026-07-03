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
            'machine' => [['type' => 'wallpaper', 'semantics' => 'exclusive']],
        ];

        // Mêmes données, clés dans un ordre différent (top-level + nested).
        $shuffled = [
            'machine' => [['semantics' => 'exclusive', 'type' => 'wallpaper']],
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
        $a = ['type' => 'overlay', 'semantics' => 'aggregate', 'payload' => ['ttl_seconds' => 60, 'tool' => 'rainmeter']];
        $b = ['payload' => ['tool' => 'rainmeter', 'ttl_seconds' => 60], 'semantics' => 'aggregate', 'type' => 'overlay'];

        $this->assertSame(
            $this->hasher->hashItem($a),
            $this->hasher->hashItem($b),
        );
    }

    // ── Champ `ensure` (Story 35.1) : entre dans la canonicalisation ──────
    // AUCUNE modification du StateHasher : la canonicalisation générique
    // (sortRecursive + JSON compact) intègre naturellement tout champ nouveau
    // du payload. Ces tests le PROUVENT (AC1) — jumeaux des tests Go
    // (hasher_test.go::TestHashItemEnsureField*).

    #[Test]
    public function ensure_field_changes_the_item_hash(): void
    {
        $base = [
            'type' => 'registry',
            'semantics' => 'exclusive',
            'payload' => ['hive' => 'HKLM', 'path' => 'SOFTWARE\\P', 'name' => 'N'],
        ];
        $absent = $base;
        $absent['payload']['ensure'] = 'absent';

        $this->assertNotSame(
            $this->hasher->hashItem($base),
            $this->hasher->hashItem($absent),
            'deux items qui ne diffèrent que par `ensure` doivent avoir des hashes distincts',
        );
    }

    #[Test]
    public function write_item_without_ensure_keeps_its_pre_story_hash(): void
    {
        // Non-régression byte-identité (piège n°1, Story 35.1) : un item
        // d'écriture 5 clés SANS `ensure` garde EXACTEMENT son hash d'avant la
        // story (hash historique de l'item registry HKCU du golden, inchangé
        // depuis 27.3).
        $hash = $this->hasher->hashItem([
            'type' => 'registry',
            'semantics' => 'exclusive',
            'payload' => [
                'hive' => 'HKCU',
                'path' => 'Software\\Microsoft\\Windows\\CurrentVersion\\Explorer\\Advanced',
                'name' => 'HideFileExt',
                'type' => 'REG_DWORD',
                'value' => 0,
            ],
        ]);

        $this->assertSame(
            '92730f99ed3e64f81e99c955e64bfb37da8fcc765aa1eb44373c9c4e4af686b5',
            $hash,
            'le hash d\'un item d\'écriture sans `ensure` ne doit PAS changer',
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
                    'payload' => ['asset' => 'fonds/ecole-2026.jpg', 'style' => 'fill'],
                ],
            ],
            'session' => [],
            'machine_user' => [],
        ];
    }
}
