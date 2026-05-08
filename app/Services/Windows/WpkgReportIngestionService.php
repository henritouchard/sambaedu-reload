<?php

declare(strict_types=1);

namespace App\Services\Windows;

use App\Models\AppProfile;
use App\Models\Application;
use App\Models\Workstation;
use App\Models\WorkstationApplicationStatus;
use App\Models\WorkstationGroup;
use App\Wpkg\Deployment\Models\WpkgDeployment;
use App\Wpkg\Deployment\Models\WpkgDeploymentWorkstationStatus;
use App\Wpkg\Deployment\Queries\ActiveDeploymentForWorkstationQuery;
use App\Wpkg\Deployment\Services\WpkgReportArchiver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service d'ingestion des rapports WPKG.
 *
 * Story 9.4 — pipeline texte legacy : SHA → idempotence → parser →
 * persist `workstation_application_status`.
 *
 * Story 15.5 — extensions :
 *   - Archivage brut via `WpkgReportArchiver` AVANT parsing (best-effort).
 *   - Parser durci graceful : capture `Duration:`/`ErrorCode:` quand
 *     présents, retourne un statut `unknown` plutôt qu'un parse_failed
 *     pour les formats inhabituels (best-effort).
 *   - Corrélation `wpkg_deployments` après persistance via
 *     `ActiveDeploymentForWorkstationQuery` → upsert `wpkg_deployment_workstation_status`.
 *   - Recalcul `wpkg_deployments.summary` + transition status (pending/running →
 *     running/completed) à chaque ingestion corrélée.
 *   - Logs structurés `wpkg-deploy` (event, workstation_id, deployment_id,
 *     packages_count, client_status, sha256, archive_path).
 *
 * Verrou Cache::lock("wpkg-report:{hostname}", 60) sérialise l'ingestion
 * par hostname (concurrence postes différents OK).
 */
class WpkgReportIngestionService
{
    public function __construct(
        private readonly WpkgReportArchiver $archiver,
        private readonly ActiveDeploymentForWorkstationQuery $activeDeploymentQuery,
    ) {
    }

    /**
     * Point d'entrée unique : parse + persiste le rapport.
     */
    public function ingest(string $hostname, string $rawReport): IngestionResult
    {
        $sha256 = hash('sha256', $rawReport);

        $lock = Cache::lock("wpkg-report:{$hostname}", 60);

        return $lock->block(5, function () use ($hostname, $rawReport, $sha256): IngestionResult {
            $workstation = Workstation::where('name', $hostname)->first();

            if (! $workstation) {
                return IngestionResult::notFound($hostname);
            }

            // Idempotence : skip si le SHA est identique (pas de ré-archivage).
            if ($workstation->report_sha === $sha256) {
                return IngestionResult::unchanged($hostname);
            }

            // Story 15.5 / AC1.3 — archivage brut AVANT parsing (best-effort).
            // Si l'archive échoue, on continue : la BDD reste source de vérité.
            $archivePath = $this->archiver->archive($hostname, $rawReport, $sha256);

            // Parser le contenu — Story 15.5 / AC2.3 : graceful unknown
            // plutôt que parse_failed pour formats inhabituels.
            $parsed = $this->parseReport($rawReport, $workstation->id, $hostname);
            $parserWarning = false;

            if ($parsed === null) {
                // Format vraiment incompréhensible (header invalide) : on
                // préserve le 422 de 9.4 pour les vraies erreurs (test régression).
                Log::channel('wpkg-deploy')->warning('[WpkgReportIngestionService] format inconnu', [
                    'event' => 'wpkg_report_parser_warning',
                    'hostname' => $hostname,
                    'unknown_pattern' => 'header_invalid',
                ]);

                return IngestionResult::parseFailed($hostname);
            }

            // Détecte un parser warning (sentinelle posée par parseReport).
            if (! empty($parsed['_parser_warnings'])) {
                $parserWarning = true;
                Log::channel('wpkg-deploy')->warning('[WpkgReportIngestionService] parser warnings', [
                    'event' => 'wpkg_report_parser_warning',
                    'hostname' => $hostname,
                    'warnings' => $parsed['_parser_warnings'],
                ]);
            }

            // Persister le statut par-app (9.4 — inchangé).
            $this->updateWorkstationReport($workstation->fresh(), $parsed, $sha256);
            $workstation->refresh();

            // Story 15.5 / AC1.4 — corrélation deployment_id post-persistance.
            // La table wpkg_deployments est mandatoire (migration 15.1 déployée).
            $deploymentId = null;
            $clientStatus = $this->aggregateClientStatus($parsed['packages'], $parserWarning);
            $deployment = $this->activeDeploymentQuery->find($workstation->id);
            if ($deployment !== null) {
                $this->upsertDeploymentWorkstationStatus(
                    $deployment,
                    $workstation->id,
                    $clientStatus,
                    $parsed,
                    $archivePath,
                );
                $deploymentId = $deployment->id;
                $this->recomputeDeploymentSummary($deployment->fresh());
            }

            // Log structuré final.
            Log::channel('wpkg-deploy')->info('[WpkgReportIngestionService] rapport ingéré', [
                'event' => 'wpkg_report_ingested',
                'workstation_id' => $workstation->id,
                'hostname' => $workstation->name,
                'deployment_id' => $deploymentId,
                'packages_count' => count($parsed['packages']),
                'client_status' => $clientStatus,
                'sha256' => $sha256,
                'archive_path' => $archivePath,
            ]);

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
     * Status: Installed|Not Installed|Error
     * [Duration: <ms>]      // Story 15.5 / AC2.4
     * [ErrorCode: <code>]   // Story 15.5 / AC2.4
     * [ErrorMessage: ...]   // Story 15.5 / AC2.4
     * ---
     * ```
     *
     * Story 15.5 / AC2.3 : graceful unknown — si une ligne d'un bloc package
     * est inconnue, elle est conservée dans `_parser_warnings` (sentinelle
     * interne) sans bloquer l'ingestion.
     *
     * @return array{header: array, packages: array, _parser_warnings?: list<string>}|null
     */
    private function parseReport(string $content, ?int $workstationId = null, ?string $hostname = null): ?array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (! mb_check_encoding($content, 'UTF-8')) {
            Log::channel('wpkg-deploy')->warning('[WpkgReportIngestionService] report content is not valid UTF-8, parsing may be unreliable', [
                'event' => 'wpkg_report_invalid_utf8',
                'workstation_id' => $workstationId,
                'hostname' => $hostname,
                'detected' => mb_detect_encoding($content, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true),
            ]);
        }

        $lines = explode("\n", str_replace("\r\n", "\n", trim($content)));

        if (count($lines) < 1) {
            return null;
        }

        $headerLine = trim($lines[0]);
        $header = $this->parseHeaderLine($headerLine);

        if (empty($header['hostname']) || strtotime($header['date']) === false) {
            return null;
        }

        $header['os'] = $this->detectOs($headerLine);

        $rawBlocks = explode('---', implode("\n", array_slice($lines, 1)));
        $packages = [];
        $warnings = [];

        foreach ($rawBlocks as $block) {
            $block = trim($block);
            if (empty($block)) {
                continue;
            }

            $pkg = $this->parsePackageBlock($block, $warnings);
            if ($pkg !== null) {
                $packages[] = $pkg;
            }
        }

        $result = [
            'header'   => $header,
            'packages' => $packages,
        ];

        if (! empty($warnings)) {
            $result['_parser_warnings'] = $warnings;
        }

        return $result;
    }

    private function parseHeaderLine(string $line): array
    {
        $parts = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);

        return [
            'date'        => ($parts[0] ?? '') . ' ' . ($parts[1] ?? ''),
            'hostname'    => $parts[2] ?? '',
            'mac_address' => $parts[3] ?? '',
            'ip'          => isset($parts[4]) ? trim($parts[4], '[]') : null,
        ];
    }

    /**
     * Story 15.5 / AC2.3-AC2.4 — Parse un bloc package, capture les champs
     * additionnels Duration/ErrorCode/ErrorMessage, log les clés inconnues.
     *
     * @param  list<string>  $warnings  ref accumulator
     * @return array{id: string, revision: string, reboot: bool, status: string, duration_ms?: int, error_code?: string, error_message?: string}|null
     */
    private function parsePackageBlock(string $block, array &$warnings): ?array
    {
        $lines = explode("\n", $block);
        $data = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            if (! str_contains($line, ':')) {
                $warnings[] = 'unknown_line:' . substr($line, 0, 80);
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $data[trim($key)] = trim($value);
        }

        if (empty($data['ID'])) {
            return null;
        }

        $pkg = [
            'id'       => $data['ID'],
            'revision' => $data['Revision'] ?? '',
            'reboot'   => filter_var($data['Reboot'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
            'status'   => $this->mapStatus($data['Status'] ?? 'Not Installed'),
        ];

        // Story 15.5 / AC2.4 — champs additionnels (extraits si présents,
        // pas de schema rigide ; persistés dans `details` JSON de
        // wpkg_deployment_workstation_status uniquement).
        if (isset($data['Duration']) && is_numeric($data['Duration'])) {
            $pkg['duration_ms'] = (int) $data['Duration'];
        }
        if (isset($data['ErrorCode']) && $data['ErrorCode'] !== '') {
            $pkg['error_code'] = $data['ErrorCode'];
        }
        if (isset($data['ErrorMessage']) && $data['ErrorMessage'] !== '') {
            $pkg['error_message'] = $data['ErrorMessage'];
        }

        // Détecte les clés non standard pour le warning graceful.
        $known = ['ID', 'Revision', 'Reboot', 'Status', 'Duration', 'ErrorCode', 'ErrorMessage'];
        foreach (array_keys($data) as $key) {
            if (! in_array($key, $known, true)) {
                $warnings[] = 'unknown_key:' . substr($key, 0, 32);
            }
        }

        return $pkg;
    }

    private function detectOs(string $firstLine): string
    {
        $lower = strtolower($firstLine);

        if (str_contains($lower, 'windows 7')) {
            return 'Windows 7';
        }
        if (str_contains($lower, 'windows 11')) {
            return 'Windows 11';
        }
        if (str_contains($lower, 'windows 10')) {
            return 'Windows 10';
        }
        if (str_contains($lower, 'winxp')) {
            return 'Windows XP';
        }

        return 'Autre';
    }

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

    private function updateWorkstationReport(
        Workstation $workstation,
        array $parsedData,
        string $sha256,
    ): void {
        $header = $parsedData['header'];

        $hostname   = $workstation->name;
        $logPath    = "{$hostname}.log";
        $reportPath = "{$hostname}.txt";

        DB::transaction(function () use ($workstation, $parsedData, $header, $sha256, $logPath, $reportPath): void {
            $workstation->update([
                'ip'             => $header['ip'] ?? $workstation->ip,
                'mac'            => $header['mac_address'] ?: $workstation->mac,
                'os'             => $header['os'] ?? $workstation->os,
                'log_path'       => $logPath,
                'report_path'    => $reportPath,
                'last_report_at' => now(),
                'report_sha'     => $sha256,
            ]);

            WorkstationApplicationStatus::where('workstation_id', $workstation->id)->delete();

            if (empty($parsedData['packages'])) {
                return;
            }

            $packages = array_values(array_column($parsedData['packages'], null, 'id'));

            $appIds = array_column($packages, 'id');
            $appMap = Application::whereIn('app_id', $appIds)
                ->pluck('id', 'app_id')
                ->toArray();

            $now = now();
            $rows = [];
            $unknownApps = [];
            foreach ($packages as $pkg) {
                if (! isset($appMap[$pkg['id']])) {
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

            if (! empty($unknownApps)) {
                Log::channel('wpkg-deploy')->warning('[WpkgReportIngestionService] applications inconnues ignorées', [
                    'event' => 'wpkg_report_unknown_apps_ignored',
                    'workstation_id' => $workstation->id,
                    'hostname' => $workstation->name,
                    'unknown_app_ids' => $unknownApps,
                ]);
            }

            if (empty($rows)) {
                return;
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                WorkstationApplicationStatus::insert($chunk);
            }
        });
    }

    /**
     * Story 15.5 / AC1.4 — Agrège le `client_status` à partir des packages parsés.
     */
    private function aggregateClientStatus(array $packages, bool $parserWarning): string
    {
        if ($parserWarning) {
            return WpkgDeploymentWorkstationStatus::STATUS_UNKNOWN;
        }

        if (empty($packages)) {
            return WpkgDeploymentWorkstationStatus::STATUS_UNKNOWN;
        }

        $errors = 0;
        $oks = 0;

        foreach ($packages as $pkg) {
            if (($pkg['status'] ?? '') === 'error') {
                $errors++;
            } else {
                $oks++;
            }
        }

        if ($errors === 0) {
            return WpkgDeploymentWorkstationStatus::STATUS_SUCCESS;
        }

        if ($oks === 0) {
            return WpkgDeploymentWorkstationStatus::STATUS_FAILED;
        }

        return WpkgDeploymentWorkstationStatus::STATUS_PARTIAL;
    }

    /**
     * Story 15.5 / AC1.4 — Upsert d'une ligne `wpkg_deployment_workstation_status`
     * pour le déploiement matché.
     */
    private function upsertDeploymentWorkstationStatus(
        WpkgDeployment $deployment,
        int $workstationId,
        string $clientStatus,
        array $parsed,
        ?string $archivePath,
    ): void {
        $packages = $parsed['packages'] ?? [];

        $counters = [
            'total' => count($packages),
            'success' => 0,
            'failed' => 0,
            'reboot' => 0,
        ];
        $firstError = null;

        foreach ($packages as $pkg) {
            if (($pkg['status'] ?? '') === 'error') {
                $counters['failed']++;
                if ($firstError === null && isset($pkg['error_message'])) {
                    $firstError = (string) $pkg['error_message'];
                } elseif ($firstError === null && isset($pkg['error_code'])) {
                    $firstError = 'error_code=' . $pkg['error_code'];
                }
            } else {
                $counters['success']++;
            }
            if (! empty($pkg['reboot'])) {
                $counters['reboot']++;
            }
        }

        $details = [
            'counters' => $counters,
            'report_archive_path' => $archivePath,
            'packages' => array_map(static fn (array $p): array => [
                'id' => $p['id'] ?? null,
                'status' => $p['status'] ?? null,
                'duration_ms' => $p['duration_ms'] ?? null,
                'error_code' => $p['error_code'] ?? null,
            ], $packages),
        ];

        if (! empty($parsed['_parser_warnings'])) {
            $details['parser_warnings'] = $parsed['_parser_warnings'];
        }

        $existing = WpkgDeploymentWorkstationStatus::where('deployment_id', $deployment->id)
            ->where('workstation_id', $workstationId)
            ->first();

        if ($existing !== null) {
            $existing->update([
                'client_reported_at' => now(),
                'client_status' => $clientStatus,
                'details' => $details,
                'error_message' => $firstError,
            ]);
        } else {
            WpkgDeploymentWorkstationStatus::create([
                'deployment_id' => $deployment->id,
                'workstation_id' => $workstationId,
                'client_reported_at' => now(),
                'client_status' => $clientStatus,
                'details' => $details,
                'error_message' => $firstError,
            ]);
        }
    }

    /**
     * Story 15.5 / AC1.4 — Recalcule `summary` + transition status d'un
     * déploiement après ingestion d'un rapport corrélé.
     *
     * Sémantique :
     *   - `pending → running` au premier rapport reçu.
     *   - `running → completed` si reported >= total_targets.
     *   - `running → partial` si timeout > 24h sans complétion (jugement
     *     reporté à un job/cron — ici on calcule juste les compteurs).
     */
    private function recomputeDeploymentSummary(WpkgDeployment $deployment): void
    {
        $statuses = WpkgDeploymentWorkstationStatus::where('deployment_id', $deployment->id)
            ->get(['client_status']);

        $reported = $statuses->count();
        $success = $statuses->where('client_status', WpkgDeploymentWorkstationStatus::STATUS_SUCCESS)->count();
        $partial = $statuses->where('client_status', WpkgDeploymentWorkstationStatus::STATUS_PARTIAL)->count();
        $failed = $statuses->where('client_status', WpkgDeploymentWorkstationStatus::STATUS_FAILED)->count();

        $previousSummary = is_array($deployment->summary) ? $deployment->summary : [];
        $totalTargets = (int) ($previousSummary['total_targets'] ?? max($reported, $this->guessTotalTargets($deployment)));

        $newSummary = array_merge($previousSummary, [
            'total_targets' => $totalTargets,
            'reported' => $reported,
            'success' => $success,
            'partial' => $partial,
            'failed' => $failed,
            'last_recomputed_at' => now()->toIso8601String(),
        ]);

        $newStatus = $deployment->status;
        if ($deployment->status === WpkgDeployment::STATUS_PENDING && $reported > 0) {
            $newStatus = WpkgDeployment::STATUS_RUNNING;
        }
        if ($deployment->status !== WpkgDeployment::STATUS_COMPLETED
            && $totalTargets > 0
            && $reported >= $totalTargets) {
            $newStatus = WpkgDeployment::STATUS_COMPLETED;
        }

        $deployment->update([
            'summary' => $newSummary,
            'status' => $newStatus,
        ]);
    }

    /**
     * Calcule le total_targets exact d'un déploiement à partir de son
     * `target_scope` lorsque la valeur n'est pas pré-calculée dans `summary`.
     *
     * Fanout DB :
     *   - `workstation_ids` : count direct.
     *   - `group_ids` : union des workstations rattachées à chaque groupe.
     *   - `profile_ids` : union des workstations rattachées (lien direct
     *     `app_profile_workstation` + héritage via `workstationGroups`).
     *
     * Les 3 ensembles sont unionés (déduplication par workstation_id).
     * Edge cases : `target_scope` mal formé → 0. Arrays vides → ignorés.
     */
    private function guessTotalTargets(WpkgDeployment $deployment): int
    {
        $scope = is_array($deployment->target_scope) ? $deployment->target_scope : [];

        $workstationIds = [];

        $directIds = $scope['workstation_ids'] ?? [];
        if (is_array($directIds)) {
            foreach ($directIds as $id) {
                if (is_int($id) || ctype_digit((string) $id)) {
                    $workstationIds[(int) $id] = true;
                }
            }
        }

        $groupIds = $scope['group_ids'] ?? [];
        if (is_array($groupIds) && ! empty($groupIds)) {
            $groups = WorkstationGroup::with('workstations:id')
                ->whereIn('id', $groupIds)
                ->get();

            foreach ($groups as $group) {
                foreach ($group->workstations as $ws) {
                    $workstationIds[(int) $ws->id] = true;
                }
            }
        }

        $profileIds = $scope['profile_ids'] ?? [];
        if (is_array($profileIds) && ! empty($profileIds)) {
            $profiles = AppProfile::with([
                'workstations:id',
                'workstationGroups.workstations:id',
            ])
                ->whereIn('id', $profileIds)
                ->get();

            foreach ($profiles as $profile) {
                foreach ($profile->workstations as $ws) {
                    $workstationIds[(int) $ws->id] = true;
                }
                foreach ($profile->workstationGroups as $group) {
                    foreach ($group->workstations as $ws) {
                        $workstationIds[(int) $ws->id] = true;
                    }
                }
            }
        }

        return count($workstationIds);
    }
}
