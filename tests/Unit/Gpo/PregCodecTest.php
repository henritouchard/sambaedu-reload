<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Support\PregCodec;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 38.4 (T2.1) — codec `Registry.pol` (PReg) natif.
 *
 * Vérifie la byte-stabilité (decode→encode idempotent) et les helpers
 * get/set de clé, sur des entrées construites en octets (golden bytes),
 * sans dépendre d'un SYSVOL réel.
 */
final class PregCodecTest extends TestCase
{
    private PregCodec $codec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->codec = new PregCodec();
    }

    /** Construit un blob PReg golden avec une entrée REG_SZ multi-valeurs. */
    private function goldenBlob(): string
    {
        // Une seule entrée REG_SZ : value="ExcludeProfileDirs", data="A;B".
        return $this->codec->encode([[
            'key' => 'Software\\Policies',
            'value' => 'ExcludeProfileDirs',
            'type' => PregCodec::REG_SZ,
            'data' => 'AppData/Local;AppData/Roaming',
        ]]);
    }

    #[Test]
    public function decode_then_encode_is_byte_stable(): void
    {
        $blob = $this->goldenBlob();

        $decoded = $this->codec->decode($blob);
        $reencoded = $this->codec->encode($decoded);

        self::assertSame(
            bin2hex($blob),
            bin2hex($reencoded),
            'Un decode→encode sans modification doit reproduire les octets EXACTS.',
        );
    }

    #[Test]
    public function encoded_blob_starts_with_preg_signature(): void
    {
        $blob = $this->goldenBlob();
        self::assertStringStartsWith("\x50\x52\x65\x67\x01\x00\x00\x00", $blob);
    }

    #[Test]
    public function get_key_values_splits_on_semicolon(): void
    {
        $entries = $this->codec->decode($this->goldenBlob());

        self::assertSame(
            ['AppData/Local', 'AppData/Roaming'],
            $this->codec->getKeyValues($entries, 'ExcludeProfileDirs'),
        );
        self::assertSame([], $this->codec->getKeyValues($entries, 'Absente'));
    }

    #[Test]
    public function set_key_values_replaces_in_place(): void
    {
        $entries = $this->codec->decode($this->goldenBlob());

        $this->codec->setKeyValues($entries, 'ExcludeProfileDirs', ['X', 'Y', 'Z']);

        self::assertSame(
            ['X', 'Y', 'Z'],
            $this->codec->getKeyValues($entries, 'ExcludeProfileDirs'),
        );
    }

    #[Test]
    public function reg_dword_round_trips(): void
    {
        $blob = $this->codec->encode([[
            'key' => 'K',
            'value' => 'Flag',
            'type' => PregCodec::REG_DWORD,
            'data' => 305419896, // 0x12345678
        ]]);

        $decoded = $this->codec->decode($blob);

        self::assertSame(305419896, $decoded[0]['data']);
        self::assertSame(bin2hex($blob), bin2hex($this->codec->encode($decoded)));
    }

    #[Test]
    public function empty_and_json_inputs_are_tolerated(): void
    {
        self::assertSame([], $this->codec->decode(''));
        // Représentation JSON (parité legacy read_pol) : tableau retourné tel quel.
        self::assertSame([['value' => 'x']], $this->codec->decode(json_encode([['value' => 'x']])));
    }
}
