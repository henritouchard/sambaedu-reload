<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Services;

use App\Ipxe\Services\WorkstationLocator;
use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.1 — AC2.1 / T2.5.
 *
 * Tests unitaires de la résolution `WorkstationLocator::locate()`.
 *
 * Couvre les 10 cas spécifiés par AC2.1 :
 *
 *  - Match UUID
 *  - Fallback MAC
 *  - UUID empty (handshake géré en amont — ici on teste juste fallback MAC)
 *  - MAC empty avec UUID seul
 *  - MAC malformée → fallback ignoré
 *  - UUID uppercase normalisé
 *  - Transformation `product` vide (fixture iso-legacy)
 *  - Pas de transformation quand product fourni
 *  - Poste introuvable → null
 *  - Poste trouvé eager-load relations
 */
class WorkstationLocatorTest extends TestCase
{
    private WorkstationLocator $locator;

    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        $this->locator = new WorkstationLocator();
    }

    private function makeWorkstation(array $attrs = []): Workstation
    {
        return Workstation::create(array_merge([
            'name' => 'PC-TEST-01',
            'status' => 'active',
        ], $attrs));
    }

    #[Test]
    public function it_resolves_by_uuid_when_uuid_matches(): void
    {
        $ws = $this->makeWorkstation([
            'uuid' => '12345678-1234-1234-1234-123456789abc',
            'mac' => 'aa:bb:cc:dd:ee:ff',
        ]);

        $found = $this->locator->locate(
            mac: '00:00:00:00:00:01',  // MAC différente (non-matchante)
            uuid: '12345678-1234-1234-1234-123456789abc',
            product: 'OptiPlex 3050',  // product non vide → pas de transformation
        );

        self::assertNotNull($found);
        self::assertSame($ws->id, $found->id);
    }

    #[Test]
    public function it_falls_back_to_mac_when_uuid_does_not_match(): void
    {
        $ws = $this->makeWorkstation([
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'mac' => 'aa:bb:cc:dd:ee:ff',
        ]);

        $found = $this->locator->locate(
            mac: 'aa:bb:cc:dd:ee:ff',
            uuid: '99999999-9999-9999-9999-999999999999',  // UUID inconnu
            product: 'HP 280 G2 SFF',  // product non vide
        );

        self::assertNotNull($found);
        self::assertSame($ws->id, $found->id);
    }

    #[Test]
    public function it_returns_null_when_both_unknown(): void
    {
        $this->makeWorkstation([
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'mac' => 'aa:bb:cc:dd:ee:ff',
        ]);

        $found = $this->locator->locate(
            mac: '00:00:00:00:00:00',
            uuid: '99999999-9999-9999-9999-999999999999',
            product: 'Unknown Product',
        );

        self::assertNull($found);
    }

    #[Test]
    public function it_normalises_mac_with_dash_separator_variant(): void
    {
        $ws = $this->makeWorkstation([
            'mac' => 'aa:bb:cc:dd:ee:ff',
        ]);

        $found = $this->locator->locate(
            mac: 'AA-BB-CC-DD-EE-FF',  // séparateur dash + uppercase
            uuid: null,
            product: 'OptiPlex 3050',  // product non vide pour éviter transformation
        );

        self::assertNotNull($found);
        self::assertSame($ws->id, $found->id);
    }

    #[Test]
    public function it_normalises_mac_with_no_separator_variant(): void
    {
        $ws = $this->makeWorkstation([
            'mac' => 'aa:bb:cc:dd:ee:ff',
        ]);

        $found = $this->locator->locate(
            mac: 'aabbccddeeff',
            uuid: null,
            product: 'OptiPlex 3050',
        );

        self::assertNotNull($found);
        self::assertSame($ws->id, $found->id);
    }

    #[Test]
    public function it_returns_null_when_mac_format_is_invalid(): void
    {
        $this->makeWorkstation([
            'mac' => 'aa:bb:cc:dd:ee:ff',
        ]);

        $found = $this->locator->locate(
            mac: 'not-a-mac-address',
            uuid: null,
            product: 'OptiPlex 3050',
        );

        self::assertNull($found);
    }

    #[Test]
    public function it_normalises_uuid_to_lowercase(): void
    {
        $ws = $this->makeWorkstation([
            'uuid' => '12345678-1234-1234-1234-123456789abc',
        ]);

        $found = $this->locator->locate(
            mac: null,
            uuid: '12345678-1234-1234-1234-123456789ABC',
            product: 'OptiPlex 3050',
        );

        self::assertNotNull($found);
        self::assertSame($ws->id, $found->id);
    }

    #[Test]
    public function it_returns_null_when_uuid_and_mac_both_empty(): void
    {
        $this->makeWorkstation([
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'mac' => 'aa:bb:cc:dd:ee:ff',
        ]);

        $found = $this->locator->locate('', '', '');

        self::assertNull($found);
    }

    #[Test]
    public function it_applies_legacy_product_empty_transformation(): void
    {
        // Fixture iso-legacy `boot.php:36-41` :
        // - mac = "aa:bb:cc:dd:ee:ff" → "aabbccddeeff" → hexdec = 187723572702975
        //   → dechex = "aabbccddeeff"
        // - uuid = "11111111-2222-3333-4444-555555555555"
        //   → reconstruit = "11111111-2222-3333-4444-aabbccddeeff"
        // → On stocke en DB le résultat de la transformation, et on s'attend
        //   à matcher quand product est vide.
        $reconstructed = '11111111-2222-3333-4444-' . dechex((int) hexdec('aabbccddeeff'));
        $ws = $this->makeWorkstation([
            'uuid' => $reconstructed,
        ]);

        $found = $this->locator->locate(
            mac: 'aa:bb:cc:dd:ee:ff',
            uuid: '11111111-2222-3333-4444-555555555555',  // UUID original
            product: '',  // product vide → transformation appliquée
        );

        self::assertNotNull(
            $found,
            'La transformation product-empty iso-legacy doit reconstruire l\'UUID '
            . 'composite et matcher : attendu ' . $reconstructed,
        );
        self::assertSame($ws->id, $found->id);
    }

    #[Test]
    public function it_applies_legacy_transformation_for_max_value_mac_ffffffffffff(): void
    {
        // Fix review B2 — vérification anti-overflow `hexdec` pour la MAC
        // limite haute `ff:ff:ff:ff:ff:ff`. Valeur décimale = 281474976710655
        // (< PHP_INT_MAX 64-bit = 9223372036854775807, donc OK), mais le legacy
        // PHP utilise `dechex(hexdec(...))` direct (float si overflow 32-bit)
        // alors qu'on fait `(int) hexdec(...)`. Sur 64-bit la sortie doit être
        // strictement identique. Ce test garantit la parité iso-legacy pour
        // la valeur extrême.
        $legacyExpected = dechex((int) hexdec('ffffffffffff'));  // = 'ffffffffffff'
        $reconstructed = '11111111-2222-3333-4444-' . $legacyExpected;

        // Sanity check : valeur attendue.
        self::assertSame('ffffffffffff', $legacyExpected, 'Sur PHP 64-bit, hexdec(ffffffffffff) doit rester un int sans overflow.');

        $ws = $this->makeWorkstation([
            'uuid' => $reconstructed,
            'mac' => 'ff:ff:ff:ff:ff:ff',
        ]);

        $found = $this->locator->locate(
            mac: 'ff:ff:ff:ff:ff:ff',
            uuid: '11111111-2222-3333-4444-555555555555',
            product: '',
        );

        self::assertNotNull(
            $found,
            'La transformation product-empty doit produire un UUID composite identique '
            . 'au legacy même pour MAC ff:ff:ff:ff:ff:ff (valeur hexdec max).',
        );
        self::assertSame($ws->id, $found->id);
    }

    #[Test]
    public function it_does_not_apply_legacy_transformation_when_product_provided(): void
    {
        $reconstructed = '11111111-2222-3333-4444-' . dechex((int) hexdec('aabbccddeeff'));
        $this->makeWorkstation([
            'uuid' => $reconstructed,
        ]);

        $found = $this->locator->locate(
            mac: 'aa:bb:cc:dd:ee:ff',
            uuid: '11111111-2222-3333-4444-555555555555',
            product: 'OptiPlex 3050',  // product fourni → PAS de transformation
        );

        self::assertNull(
            $found,
            'Quand product est fourni, la transformation legacy ne doit PAS '
            . 'être appliquée — l\'UUID original (non-reconstructé) est utilisé.',
        );
    }

    #[Test]
    public function it_eager_loads_relations_when_workstation_found(): void
    {
        $ws = $this->makeWorkstation([
            'uuid' => 'abcdef12-3456-7890-abcd-ef1234567890',
            'mac' => 'aa:bb:cc:dd:ee:ff',
        ]);

        $found = $this->locator->locate(
            mac: 'aa:bb:cc:dd:ee:ff',
            uuid: 'abcdef12-3456-7890-abcd-ef1234567890',
            product: 'OptiPlex 3050',
        );

        self::assertNotNull($found);
        self::assertTrue($found->relationLoaded('physicalRoom'));
        self::assertTrue($found->relationLoaded('groups'));
        self::assertTrue($found->relationLoaded('appProfiles'));
    }

    #[Test]
    public function it_prioritises_uuid_over_mac_when_both_match_different_workstations(): void
    {
        // Edge case : 2 workstations distincts, l'un match par UUID, l'autre
        // par MAC. Le locator doit prioriser l'UUID (D4 — iso-legacy
        // get_action() qui priorise l'UUID composite).
        $wsByUuid = $this->makeWorkstation([
            'name' => 'PC-UUID-MATCH',
            'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'mac' => '00:00:00:00:00:01',
        ]);
        $wsByMac = $this->makeWorkstation([
            'name' => 'PC-MAC-MATCH',
            'uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'mac' => 'aa:bb:cc:dd:ee:ff',
        ]);

        $found = $this->locator->locate(
            mac: 'aa:bb:cc:dd:ee:ff',
            uuid: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            product: 'OptiPlex 3050',
        );

        self::assertNotNull($found);
        self::assertSame($wsByUuid->id, $found->id, 'Priorité UUID sur MAC attendue');
        self::assertSame('PC-UUID-MATCH', $found->name);
    }
}
