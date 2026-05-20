<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Enums\GpoHealthStatus;
use App\Gpo\Support\GpoExportSerializer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires — GpoExportSerializer (Story 16.14 AC7.2 / D3).
 *
 * Purement unitaires : pas de bootstrap Laravel, pas d'accès DB ni session.
 */
class GpoExportSerializerTest extends TestCase
{
    private function makeGpoArray(
        string $name,
        string $displayName,
        ?int $versionNumber = 65539,
        ?string $path = null,
    ): array {
        return [
            'name'          => $name,
            'displayName'   => $displayName,
            'versionNumber' => $versionNumber,
            'dn'            => null,
            'path'          => $path,
        ];
    }

    #[Test]
    public function csv_headers_match_schema(): void
    {
        $headers = GpoExportSerializer::csvHeaders();

        // Story 16.14 Q5 — colonne `version_status` ajoutée entre version_minor et path_sysvol.
        self::assertSame([
            'display_name',
            'guid',
            'version_major',
            'version_minor',
            'version_status',
            'path_sysvol',
            'ou_links_count',
            'health_status',
            'native_sections_count',
        ], $headers);
    }

    #[Test]
    public function to_csv_rows_serializes_unicode_display_names(): void
    {
        $gpos = collect([
            $this->makeGpoArray(
                '{AAAA-0001-BBBB-CCCC-DDDDDDDDDDD1}',
                'GPO Profils Itinérants (Façon SE4)', // Accents + ç
                65539,
                '\\\\domain\\sysvol\\domain\\Policies\\{AAAA...}',
            ),
        ]);

        $rows = GpoExportSerializer::toCsvRows($gpos);

        self::assertCount(1, $rows);
        $row = $rows[0];

        // display_name préservé (Unicode)
        self::assertSame('GPO Profils Itinérants (Façon SE4)', $row[0]);

        // GUID sans accolades
        self::assertSame('AAAA-0001-BBBB-CCCC-DDDDDDDDDDD1', $row[1]);

        // Version major/minor découpés
        self::assertSame(1, $row[2]);  // 65539 >> 16 = 1
        self::assertSame(3, $row[3]);  // 65539 & 0xFFFF = 3
    }

    #[Test]
    public function to_json_array_pretty_print_with_unicode(): void
    {
        $gpos = collect([
            $this->makeGpoArray(
                '{BBBB-0002-CCCC-DDDD-EEEEEEEEEEEE}',
                'GPO Réseau — Salle Pédagogique',
                131072, // version 2.0
            ),
        ]);

        $json = GpoExportSerializer::toJsonString($gpos);

        // Vérifier que c'est du JSON valide
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded);

        $item = $decoded[0];
        self::assertSame('GPO Réseau — Salle Pédagogique', $item['display_name']);
        self::assertSame('BBBB-0002-CCCC-DDDD-EEEEEEEEEEEE', $item['guid']);
        self::assertSame(2, $item['version_major']);
        self::assertSame(0, $item['version_minor']);

        // Vérifier pretty-print (JSON_PRETTY_PRINT)
        self::assertStringContainsString("\n", $json, 'Le JSON doit être pretty-printed (JSON_PRETTY_PRINT).');

        // Vérifier que l'accents sont préservés (JSON_UNESCAPED_UNICODE)
        self::assertStringContainsString('Réseau', $json, 'Les caractères Unicode ne doivent pas être échappés.');
        self::assertStringNotContainsString('\\u', $json, 'Les caractères Unicode ne doivent pas être encodés en \\uXXXX.');
    }

    #[Test]
    public function to_csv_string_starts_with_utf8_bom(): void
    {
        $gpos = collect([
            $this->makeGpoArray('{TEST-GUID}', 'Test GPO', 0),
        ]);

        $csv = GpoExportSerializer::toCsvString($gpos);

        // BOM UTF-8 = "\xEF\xBB\xBF"
        self::assertStringStartsWith(
            "\xEF\xBB\xBF",
            $csv,
            'Le CSV doit commencer par le BOM UTF-8 (pour ouverture Excel sans corruption — AC2.4).'
        );
    }

    #[Test]
    public function to_csv_rows_uses_health_status_value(): void
    {
        $gpos = collect([
            $this->makeGpoArray('{ORPHAN-001}', 'GPO Orpheline', 65539),
        ]);

        $rows = GpoExportSerializer::toCsvRows(
            $gpos,
            linksCountByGuid: ['{ORPHAN-001}' => 0],
            healthStatusByGuid: ['{ORPHAN-001}' => GpoHealthStatus::Orphaned],
        );

        // Story 16.14 Q5 — décalage de colonnes après ajout de version_status (index 4).
        // [0]=display_name, [1]=guid, [2]=major, [3]=minor, [4]=version_status,
        // [5]=path, [6]=ou_links_count, [7]=health_status, [8]=native_sections_count
        self::assertCount(1, $rows);
        self::assertSame('orphaned', $rows[0][7], 'La colonne health_status doit contenir la valeur enum.');
        self::assertSame(0, $rows[0][6], 'La colonne ou_links_count doit refléter le nombre de liens.');
    }

    #[Test]
    public function to_json_array_includes_all_schema_keys(): void
    {
        $gpos = collect([
            $this->makeGpoArray('{FULL-0001}', 'Full GPO', 65539, '\\\\domain\\sysvol\\...'),
        ]);

        $items = GpoExportSerializer::toJsonArray($gpos);

        self::assertCount(1, $items);
        $keys = array_keys($items[0]);
        // Story 16.14 Q5 — clé `version_status` ajoutée.
        self::assertSame([
            'display_name',
            'guid',
            'version_major',
            'version_minor',
            'version_status',
            'path_sysvol',
            'ou_links_count',
            'health_status',
            'native_sections_count',
        ], $keys);
    }

    #[Test]
    public function to_csv_rows_counts_native_sections(): void
    {
        // GPO avec displayName matchant NativeSectionResolver (wallpaper)
        $gpos = collect([
            $this->makeGpoArray('{WALLPAPER-001}', 'se4_wallpaper_config', 65539),
        ]);

        $rows = GpoExportSerializer::toCsvRows($gpos);

        // Story 16.14 Q5 — native_sections_count = index 8 (décalé après version_status).
        self::assertCount(1, $rows);
        self::assertGreaterThanOrEqual(1, $rows[0][8], 'native_sections_count doit être >= 1 pour une GPO wallpaper.');
    }

    // -------------------------------------------------------------------------
    // Story 16.14 Q5 — `version_status` reflète la connaissance du cache.
    // -------------------------------------------------------------------------

    #[Test]
    public function version_status_is_known_when_version_number_present(): void
    {
        $gpos = collect([
            $this->makeGpoArray('{KNOWN-001}', 'GPO Active', 65539),
        ]);

        $rows = GpoExportSerializer::toCsvRows($gpos);

        // Index 4 = version_status
        self::assertSame('known', $rows[0][4]);
        self::assertSame(1, $rows[0][2]); // major
        self::assertSame(3, $rows[0][3]); // minor
    }

    #[Test]
    public function version_status_is_unknown_when_version_number_null(): void
    {
        // versionNumber = null simule un cache miss + svc null (finding #23).
        $gpos = collect([
            $this->makeGpoArray('{UNKNOWN-001}', 'GPO Inconnue', null),
        ]);

        $rows = GpoExportSerializer::toCsvRows($gpos);

        self::assertSame('unknown', $rows[0][4], 'version_status doit être "unknown" si versionNumber est null.');
        self::assertSame(0, $rows[0][2], 'version_major fallback = 0 quand inconnu.');
        self::assertSame(0, $rows[0][3], 'version_minor fallback = 0 quand inconnu.');
    }

    #[Test]
    public function json_includes_version_status_unknown_for_null_version(): void
    {
        $gpos = collect([
            $this->makeGpoArray('{UNKNOWN-002}', 'GPO Inconnue', null),
        ]);

        $items = GpoExportSerializer::toJsonArray($gpos);

        self::assertSame('unknown', $items[0]['version_status']);
        self::assertSame(0, $items[0]['version_major']);
        self::assertSame(0, $items[0]['version_minor']);
    }

    #[Test]
    public function csv_injection_formula_prefix_equal_is_escaped(): void
    {
        // displayName commençant par "=" — injection classique Excel (=CMD|'/C calc'!A0)
        $gpos = collect([
            $this->makeGpoArray('{INJECT-001}', '=CMD|\'calc\'', 65539),
        ]);

        $rows = GpoExportSerializer::toCsvRows($gpos);

        self::assertCount(1, $rows);
        // La valeur doit être préfixée par une apostrophe
        self::assertStringStartsWith("'", (string) $rows[0][0], 'Une cellule commençant par "=" doit être préfixée par une apostrophe.');
    }

    #[Test]
    public function csv_injection_formula_prefix_plus_is_escaped(): void
    {
        // displayName commençant par "+" — injection Excel (+1+2+3)
        $gpos = collect([
            $this->makeGpoArray('{INJECT-002}', '+SUM(A1:A10)', 0),
        ]);

        $rows = GpoExportSerializer::toCsvRows($gpos);

        self::assertCount(1, $rows);
        self::assertStringStartsWith("'", (string) $rows[0][0], 'Une cellule commençant par "+" doit être préfixée par une apostrophe.');
    }

    #[Test]
    public function csv_injection_formula_prefix_minus_is_escaped(): void
    {
        // displayName commençant par "-" — injection
        $gpos = collect([
            $this->makeGpoArray('{INJECT-003}', '-RDP exploit', 0),
        ]);

        $rows = GpoExportSerializer::toCsvRows($gpos);

        self::assertCount(1, $rows);
        self::assertStringStartsWith("'", (string) $rows[0][0], 'Une cellule commençant par "-" doit être préfixée par une apostrophe.');
    }

    #[Test]
    public function csv_injection_formula_prefix_at_is_escaped(): void
    {
        // displayName commençant par "@" — injection Sheets (@SUM)
        $gpos = collect([
            $this->makeGpoArray('{INJECT-004}', '@SUM(A1)', 0),
        ]);

        $rows = GpoExportSerializer::toCsvRows($gpos);

        self::assertCount(1, $rows);
        self::assertStringStartsWith("'", (string) $rows[0][0], 'Une cellule commençant par "@" doit être préfixée par une apostrophe.');
    }

    #[Test]
    public function csv_injection_safe_values_are_not_modified(): void
    {
        // Un displayName normal ne doit pas être altéré
        $gpos = collect([
            $this->makeGpoArray('{SAFE-001}', 'GPO Normale', 65539),
        ]);

        $rows = GpoExportSerializer::toCsvRows($gpos);

        self::assertCount(1, $rows);
        self::assertSame('GPO Normale', $rows[0][0], 'Un displayName normal ne doit pas être modifié.');
    }
}
