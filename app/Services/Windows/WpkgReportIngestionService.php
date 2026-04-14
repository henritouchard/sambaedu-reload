<?php

declare(strict_types=1);

namespace App\Services\Windows;

use App\Models\Application;
use App\Models\Workstation;
use App\Models\WorkstationApplicationStatus;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service d'ingestion des rapports WPKG.
 *
 * Reproduit le comportement du shim wpkg_libsql.php (lignes 931-1050) :
 *   1. Calcul SHA256 du rapport brut → idempotence (IngestionResult::unchanged si identique)
 *   2. Mise à jour des colonnes workstations (IP, MAC, OS, date, SHA, paths)
 *   3. delete + bulk-insert de WorkstationApplicationStatus (transaction DB)
 *
 * Verrou Cache::lock("wpkg-report:{hostname}", 60) pour serialiser l'ingestion
 * par hostname (permet l'ingestion concurrente de postes différents).
 */
class WpkgReportIngestionService
{
    /**
     * Point d'entrée unique : parse + persiste le rapport.
     *
     * @param  string  $hostname  Nom du poste (doit exister dans workstations.name)
     * @param  string  $rawReport Contenu brut du fichier rapport
     * @return IngestionResult
     */
    public function ingest(string $hostname, string $rawReport): IngestionResult
    {
        $sha256 = hash('sha256', $rawReport);

        $lock = Cache::lock("wpkg-report:{$hostname}", 60);

        // block(5) : attend jusqu'à 5s la libération du verrou.
        // Lance LockTimeoutException si non acquis → 500 acceptable en concurrence.
        return $lock->block(5, function () use ($hostname, $rawReport, $sha256): IngestionResult {
            $workstation = Workstation::where('name', $hostname)->first();

            if (!$workstation) {
                return IngestionResult::notFound($hostname);
            }

            // Idempotence : skip si le SHA est identique
            if ($workstation->report_sha === $sha256) {
                return IngestionResult::unchanged($hostname);
            }

            // Parser le contenu
            $parsed = $this->parseReport($rawReport);

            if ($parsed === null) {
                return IngestionResult::parseFailed($hostname);
            }

            // Persister
            $this->updateWorkstationReport($workstation, $parsed, $sha256);

            return IngestionResult::processed($hostname, count($parsed['packages']));
        });
    }

    /**
     * Parse le format texte legacy du rapport WPKG.
     *
     * Format attendu :
     * ```
     * DATE TIME HOSTNAME MAC_ADDRESS [IP]
     * ID: application-id
     * Revision: version
     * Reboot: true|false
     * Status: Installed|Not Installed
     * ---
     * ```
     *
     * @return array{header: array, packages: array}|null  null si le format est invalide
     */
    private function parseReport(string $content): ?array
    {
        // Strip BOM UTF-8 éventuel (postes Windows)
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        // Avertir si l'encodage n'est pas UTF-8 valide (Windows-1252 possible)
        if (!mb_check_encoding($content, 'UTF-8')) {
            Log::warning('wpkg: report content is not valid UTF-8, parsing may be unreliable', [
                'detected' => mb_detect_encoding($content, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true),
            ]);
        }

        $lines = explode("\n", str_replace("\r\n", "\n", trim($content)));

        if (count($lines) < 1) {
            return null;
        }

        // Ligne 1 : métadonnées
        $headerLine = trim($lines[0]);
        $header = $this->parseHeaderLine($headerLine);

        // Valider le header : la date doit être parsable et l'hostname non vide
        if (empty($header['hostname']) || strtotime($header['date']) === false) {
            return null;
        }

        // Détecter l'OS depuis la première ligne (logique legacy wpkg_rapport.php)
        $header['os'] = $this->detectOs($headerLine);

        // Blocs packages séparés par ---
        $rawBlocks = explode('---', implode("\n", array_slice($lines, 1)));
        $packages = [];

        foreach ($rawBlocks as $block) {
            $block = trim($block);
            if (empty($block)) {
                continue;
            }

            $pkg = $this->parsePackageBlock($block);
            if ($pkg !== null) {
                $packages[] = $pkg;
            }
        }

        return [
            'header'   => $header,
            'packages' => $packages,
        ];
    }

    /**
     * Parse la ligne d'en-tête du rapport.
     * Format : "DATE TIME HOSTNAME MAC_ADDRESS [IP]"
     */
    private function parseHeaderLine(string $line): array
    {
        // Exemple : "2024-01-15 08:30:00 PC-SALLE-01 AA:BB:CC:DD:EE:FF [10.0.0.50]"
        $parts = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);

        $header = [
            'date'        => ($parts[0] ?? '') . ' ' . ($parts[1] ?? ''),
            'hostname'    => $parts[2] ?? '',
            'mac_address' => $parts[3] ?? '',
            'ip'          => isset($parts[4]) ? trim($parts[4], '[]') : null,
        ];

        return $header;
    }

    /**
     * Parse un bloc package (entre deux "---").
     *
     * @return array{id: string, revision: string, reboot: bool, status: string}|null
     */
    private function parsePackageBlock(string $block): ?array
    {
        $lines = explode("\n", $block);
        $data = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $data[trim($key)] = trim($value);
            }
        }

        if (empty($data['ID'])) {
            return null;
        }

        return [
            'id'       => $data['ID'],
            'revision' => $data['Revision'] ?? '',
            'reboot'   => filter_var($data['Reboot'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
            'status'   => $this->mapStatus($data['Status'] ?? 'Not Installed'),
        ];
    }

    /**
     * Détecte la version Windows depuis la première ligne du rapport (logique legacy wpkg_rapport.php:79-86).
     *
     * La ligne d'en-tête contient le résultat de `systeminfo` ou équivalent ; les postes
     * Windows 10/11 incluent la chaîne "Windows 10"/"Windows 11" dans ce champ.
     */
    private function detectOs(string $firstLine): string
    {
        $lower = strtolower($firstLine);

        if (substr_count($lower, 'windows 7') > 0) {
            return 'Windows 7';
        }
        if (substr_count($lower, 'windows 11') > 0) {
            return 'Windows 11';
        }
        if (substr_count($lower, 'windows 10') > 0) {
            return 'Windows 10';
        }
        if (substr_count($lower, 'winxp') > 0) {
            return 'Windows XP';
        }

        return 'Autre';
    }

    /**
     * Mappe le statut texte legacy vers le statut PostgreSQL.
     */
    private function mapStatus(string $legacyStatus): string
    {
        return match (strtolower(trim($legacyStatus))) {
            'installed'     => 'installed',
            'not installed' => 'not-installed',
            'error'         => 'error',
            'upgrading'     => 'upgrading',
            default         => 'not-installed',
        };
    }

    /**
     * Met à jour la workstation et les statuts d'application.
     *
     * Pattern exact du shim :
     *   update_poste_info_wpkg → delete_info_app_poste → insert_mass_info_app_poste
     * Le tout dans une transaction DB.
     */
    private function updateWorkstationReport(
        Workstation $workstation,
        array $parsedData,
        string $sha256
    ): void {
        $header = $parsedData['header'];

        // Dériver log_path et report_path depuis le hostname (convention legacy)
        $hostname   = $workstation->name;
        $logPath    = "{$hostname}.log";
        $reportPath = "{$hostname}.txt";

        DB::transaction(function () use ($workstation, $parsedData, $header, $sha256, $logPath, $reportPath): void {
            // 1. Mise à jour du poste (AC #4 : os, log_path, report_path inclus)
            $workstation->update([
                'ip'             => $header['ip'] ?? $workstation->ip,
                'mac'            => $header['mac_address'] ?: $workstation->mac,
                'os'             => $header['os'] ?? $workstation->os,
                'log_path'       => $logPath,
                'report_path'    => $reportPath,
                'last_report_at' => now(),
                'report_sha'     => $sha256,
            ]);

            // 2. Suppression des anciens statuts
            WorkstationApplicationStatus::where('workstation_id', $workstation->id)->delete();

            // 3. Bulk-insert des nouveaux statuts
            if (empty($parsedData['packages'])) {
                return;
            }

            // Déduplication par id (garde le dernier bloc si doublon dans le rapport client)
            $packages = array_values(array_column($parsedData['packages'], null, 'id'));

            // Pré-charger la map app_id → id pour éviter N+1
            $appIds = array_column($packages, 'id');
            $appMap = Application::whereIn('app_id', $appIds)
                ->pluck('id', 'app_id')
                ->toArray();

            $now = now();
            $rows = [];
            $unknownApps = [];
            foreach ($packages as $pkg) {
                if (!isset($appMap[$pkg['id']])) {
                    // Application non trouvée en base → log + skip (FK NOT NULL)
                    $unknownApps[] = $pkg['id'];
                    continue;
                }

                $rows[] = [
                    'workstation_id'    => $workstation->id,
                    'application_id'    => $appMap[$pkg['id']],
                    'installed_version' => $pkg['revision'],
                    'status'            => $pkg['status'],
                    'reboot_required'   => $pkg['reboot'],
                    'reported_at'       => $now,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ];
            }

            if (!empty($unknownApps)) {
                Log::warning('wpkg: applications inconnues ignorées', [
                    'hostname'     => $workstation->name,
                    'unknown_apps' => $unknownApps,
                ]);
            }

            if (empty($rows)) {
                return;
            }

            // Insertion par chunks de 500 pour éviter les limites SQL
            foreach (array_chunk($rows, 500) as $chunk) {
                WorkstationApplicationStatus::insert($chunk);
            }
        });

        Log::info('wpkg: rapport ingéré', [
            'hostname'        => $workstation->name,
            'packages_count'  => count($parsedData['packages']),
        ]);
    }
}
