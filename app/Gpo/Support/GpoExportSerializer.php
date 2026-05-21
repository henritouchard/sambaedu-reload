<?php

declare(strict_types=1);

namespace App\Gpo\Support;

use App\Gpo\Dto\GpoSummary;
use App\Gpo\Enums\GpoHealthStatus;
use Illuminate\Support\Collection;

/**
 * Sérialiseur CSV/JSON pour l'export du listing GPO — Story 16.14 D3.
 *
 * Classe stateless — méthodes statiques pures.
 *
 * Schéma colonnes (D3 + Q5 arbitré Henri 2026-05-20) :
 *   display_name, guid, version_major, version_minor, version_status,
 *   path_sysvol, ou_links_count, health_status, native_sections_count.
 *
 * **Story 16.14 Q5** : la colonne `version_status` indique si la version
 * exportée est `known` (valeur réelle issue du cache) ou `unknown` (cache miss
 * + svc null → version_major/minor = 0/0 par fallback).
 */
final class GpoExportSerializer
{
    /** @return list<string> */
    public static function csvHeaders(): array
    {
        return [
            'display_name',
            'guid',
            'version_major',
            'version_minor',
            'version_status',
            'path_sysvol',
            'ou_links_count',
            'health_status',
            'native_sections_count',
        ];
    }

    /**
     * Échappe une cellule CSV pour éviter les injections de formules (CSV injection).
     *
     * Préfixe par une apostrophe toute valeur string commençant par =, +, -, @, tabulation ou CR.
     * Les valeurs null sont converties en chaîne vide.
     */
    private static function escapeCsvCell(mixed $value): mixed
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            return $value;
        }
        if ($value !== '' && str_contains('=+-@' . "\t" . "\r", $value[0])) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * Normalise un item GPO (DTO ou array) en tableau associatif uniforme.
     * Permet aux helpers d'accepter `Collection<GpoSummary>` (services natifs)
     * comme `Collection<array>` (état Livewire sérialisé) sans duplication.
     *
     * @return array{name:string,displayName:string,versionNumber:?int,path:?string}
     */
    private static function normalizeItem(mixed $gpo): array
    {
        if ($gpo instanceof GpoSummary) {
            return [
                'name' => $gpo->name,
                'displayName' => $gpo->displayName,
                'versionNumber' => $gpo->versionNumber,
                'path' => $gpo->path,
            ];
        }

        return [
            'name' => $gpo['name'] ?? '',
            'displayName' => $gpo['displayName'] ?? '',
            'versionNumber' => $gpo['versionNumber'] ?? null,
            'path' => $gpo['path'] ?? null,
        ];
    }

    /**
     * Sérialise une collection de GPOs en tableau de lignes CSV.
     *
     * @param  Collection<int,array{name:string,displayName:string,versionNumber:?int,dn:?string,path:?string}>  $gpos
     * @param  array<string,int>  $linksCountByGuid  Nombre de liaisons OU par GUID.
     * @param  array<string,GpoHealthStatus>  $healthStatusByGuid  Statut santé par GUID.
     * @return list<list<mixed>>  Tableau de lignes (sans header).
     */
    public static function toCsvRows(
        Collection $gpos,
        array $linksCountByGuid = [],
        array $healthStatusByGuid = [],
    ): array {
        $rows = [];

        foreach ($gpos as $gpo) {
            $item = self::normalizeItem($gpo);
            $guid = $item['name'];
            $rawVersion = $item['versionNumber'];
            // Story 16.14 Q5 — version_status reflète si la valeur est connue.
            if ($rawVersion === null) {
                $major = 0;
                $minor = 0;
                $versionStatus = 'unknown';
            } else {
                $major = $rawVersion >> 16;
                $minor = $rawVersion & 0xFFFF;
                $versionStatus = 'known';
            }
            $sections = NativeSectionResolver::resolve($item['displayName']);
            $status = $healthStatusByGuid[$guid] ?? GpoHealthStatus::Healthy;

            $rows[] = [
                self::escapeCsvCell($item['displayName']),
                self::escapeCsvCell(trim($guid, '{}')),
                $major,
                $minor,
                $versionStatus,
                self::escapeCsvCell($item['path'] ?? ''),
                $linksCountByGuid[$guid] ?? 0,
                self::escapeCsvCell($status->value),
                count($sections),
            ];
        }

        return $rows;
    }

    /**
     * Sérialise une collection de GPOs en tableau JSON (same schema snake_case).
     *
     * @param  Collection<int,array>  $gpos
     * @param  array<string,int>  $linksCountByGuid
     * @param  array<string,GpoHealthStatus>  $healthStatusByGuid
     * @return list<array<string,mixed>>
     */
    public static function toJsonArray(
        Collection $gpos,
        array $linksCountByGuid = [],
        array $healthStatusByGuid = [],
    ): array {
        $items = [];

        foreach ($gpos as $gpo) {
            $item = self::normalizeItem($gpo);
            $guid = $item['name'];
            $rawVersion = $item['versionNumber'];
            // Story 16.14 Q5 — version_status reflète si la valeur est connue.
            if ($rawVersion === null) {
                $major = 0;
                $minor = 0;
                $versionStatus = 'unknown';
            } else {
                $major = $rawVersion >> 16;
                $minor = $rawVersion & 0xFFFF;
                $versionStatus = 'known';
            }
            $sections = NativeSectionResolver::resolve($item['displayName']);
            $status = $healthStatusByGuid[$guid] ?? GpoHealthStatus::Healthy;

            $items[] = [
                'display_name'          => $item['displayName'],
                'guid'                  => trim($guid, '{}'),
                'version_major'         => $major,
                'version_minor'         => $minor,
                'version_status'        => $versionStatus,
                'path_sysvol'           => $item['path'] ?? '',
                'ou_links_count'        => $linksCountByGuid[$guid] ?? 0,
                'health_status'         => $status->value,
                'native_sections_count' => count($sections),
            ];
        }

        return $items;
    }

    /**
     * Génère la chaîne CSV complète (avec BOM UTF-8 + header).
     *
     * @param  Collection<int,array>  $gpos
     * @param  array<string,int>  $linksCountByGuid
     * @param  array<string,GpoHealthStatus>  $healthStatusByGuid
     */
    public static function toCsvString(
        Collection $gpos,
        array $linksCountByGuid = [],
        array $healthStatusByGuid = [],
    ): string {
        $rows = self::toCsvRows($gpos, $linksCountByGuid, $healthStatusByGuid);

        $output = '';
        $buffer = fopen('php://memory', 'w');
        if ($buffer === false) {
            return '';
        }

        // BOM UTF-8 pour Excel
        fwrite($buffer, "\xEF\xBB\xBF");

        // Header
        fputcsv($buffer, self::csvHeaders());

        // Lignes
        foreach ($rows as $row) {
            fputcsv($buffer, $row);
        }

        rewind($buffer);
        $content = stream_get_contents($buffer);
        fclose($buffer);

        return $content !== false ? $content : '';
    }

    /**
     * Génère la chaîne JSON complète (pretty-printed).
     *
     * @param  Collection<int,array>  $gpos
     * @param  array<string,int>  $linksCountByGuid
     * @param  array<string,GpoHealthStatus>  $healthStatusByGuid
     */
    public static function toJsonString(
        Collection $gpos,
        array $linksCountByGuid = [],
        array $healthStatusByGuid = [],
    ): string {
        $data = self::toJsonArray($gpos, $linksCountByGuid, $healthStatusByGuid);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return $json !== false ? $json : '[]';
    }
}
