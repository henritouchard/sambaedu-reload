<?php

declare(strict_types=1);

namespace App\Services\Network;

use App\Models\DhcpReservation;
use App\Services\Network\Data\ImportReport;
use App\Services\Network\Data\ImportReportRow;
use App\Services\Network\Exceptions\DhcpCommandException;
use App\Services\Network\Exceptions\DhcpValidationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Story 8.1 — Import CSV des réservations DHCP (FR22).
 *
 * Pattern aligné Story 2.6 (`BulkResetListingService`) pour la persistance
 * du rapport :
 *  - rapport stocké en cache Redis 24h sous `dhcp.import.report.<uuid>` ;
 *  - URL d'accès `/app/network/dhcp/import/<uuid>` (UUID v4 sans signature
 *    additionnelle — le contenu n'est pas sensible, juste un rapport
 *    d'erreurs ; les mots de passe legacy bulk-reset sont chiffrés mais
 *    ici ce sont des données réseau publiques côté serveur).
 *
 * Comportement atomique sur reload (AC5) :
 *  - toutes les insertions / updates DB sont faites en boucle ;
 *  - 1 SEUL appel `exportReservationsFile()` + `reloadService()` à la fin,
 *    après le commit DB de toutes les lignes valides ;
 *  - une ligne en erreur n'avorte JAMAIS l'import (collecte exhaustive) ;
 *  - un échec de reload est capturé et ajouté au rapport comme « erreur de
 *    reload final » mais n'invalide pas les lignes déjà persistées (AC6).
 *
 * Format CSV (figé § Décisions SM #5) :
 *  - header obligatoire `name,mac,ip,description`
 *  - séparateur `,`
 *  - colonnes 1..3 obligatoires, 4 optionnelle
 *  - tolérance : lignes vides + lignes commentaires `#` ignorées
 */
class DhcpImportService
{
    public const CACHE_PREFIX = 'dhcp.import.report.';
    public const CACHE_TTL_SECONDS = 86_400; // 24h

    public function __construct(
        private readonly DhcpService $dhcpService,
    ) {
    }

    /**
     * Importe un fichier CSV téléversé. Retourne le rapport complet (déjà
     * persisté en cache, accessible par UUID).
     */
    public function importFromCsv(UploadedFile $file): ImportReport
    {
        $path = $file->getRealPath();
        if ($path === false || !is_readable($path)) {
            throw new \RuntimeException("Fichier CSV inaccessible.");
        }

        $rows = $this->parseCsv($path);

        return $this->processRows($rows);
    }

    /**
     * Variante test-friendly : parse un contenu CSV brut et retourne les
     * résultats. Identique à `importFromCsv()` mais sans `UploadedFile`.
     */
    public function importFromCsvContent(string $content): ImportReport
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dhcp_import_');
        if ($tmp === false) {
            throw new \RuntimeException('Impossible de créer un fichier temporaire pour l\'import.');
        }
        file_put_contents($tmp, $content);
        try {
            $rows = $this->parseCsv($tmp);
            return $this->processRows($rows);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @return array<int,array{line:int,raw:array<int,string>}>
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Impossible d'ouvrir le fichier CSV.");
        }

        $rows = [];
        $lineNumber = 0;
        try {
            while (($cols = fgetcsv($handle, 4096, ',')) !== false) {
                $lineNumber++;
                // fgetcsv renvoie [null] pour une ligne vide.
                if ($cols === [null] || $cols === false) {
                    continue;
                }
                $rows[] = ['line' => $lineNumber, 'raw' => $cols];
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * @param  array<int,array{line:int,raw:array<int,string>}>  $rows
     */
    private function processRows(array $rows): ImportReport
    {
        if (empty($rows)) {
            return $this->finalize([], total: 0, ok: 0, updated: 0, errors: 0, skipped: 0);
        }

        // Première ligne = header obligatoire.
        $header = array_map(static fn ($v) => strtolower(trim((string) $v)), $rows[0]['raw']);
        $expectedHeader = ['name', 'mac', 'ip', 'description'];
        if (array_slice($header, 0, 3) !== ['name', 'mac', 'ip']) {
            $errRow = ImportReportRow::error(
                $rows[0]['line'],
                null,
                null,
                null,
                "Header CSV invalide. Attendu : '" . implode(',', $expectedHeader) . "'. Reçu : '" . implode(',', $header) . "'",
            );
            return $this->finalize([$errRow], total: 0, ok: 0, updated: 0, errors: 1, skipped: 0);
        }

        $hasDescription = (count($header) >= 4 && $header[3] === 'description');

        $reportRows = [];
        $ok = 0;
        $updated = 0;
        $errors = 0;
        $skipped = 0;
        $total = 0;

        $touchedAny = false;
        $seenMacsThisRun = [];
        $seenIpsThisRun = [];
        $seenNamesThisRun = [];

        for ($i = 1; $i < count($rows); $i++) {
            $line = $rows[$i]['line'];
            $cols = $rows[$i]['raw'];

            // Ligne vide ou commentaire.
            if (count($cols) === 1 && trim((string) $cols[0]) === '') {
                $skipped++;
                $reportRows[] = ImportReportRow::skipped($line, 'Ligne vide');
                continue;
            }
            if (str_starts_with(ltrim((string) ($cols[0] ?? '')), '#')) {
                $skipped++;
                $reportRows[] = ImportReportRow::skipped($line, 'Ligne commentée');
                continue;
            }

            $total++;

            if (count($cols) < 3) {
                $errors++;
                $reportRows[] = ImportReportRow::error($line, null, null, null, 'Colonnes insuffisantes (3 minimum attendues : name, mac, ip)');
                continue;
            }

            $name = trim((string) $cols[0]);
            $rawMac = trim((string) $cols[1]);
            $ip = trim((string) $cols[2]);
            $description = $hasDescription && isset($cols[3]) ? trim((string) $cols[3]) : null;
            if ($description === '') {
                $description = null;
            }

            // Validations
            try {
                $this->dhcpService->validateName($name);
            } catch (DhcpValidationException $e) {
                $errors++;
                $reportRows[] = ImportReportRow::error($line, $name, $rawMac, $ip, 'Nom invalide : ' . $e->getMessage());
                continue;
            }

            try {
                $mac = $this->dhcpService->validateMac($rawMac);
            } catch (DhcpValidationException $e) {
                $errors++;
                $reportRows[] = ImportReportRow::error($line, $name, $rawMac, $ip, 'MAC invalide : ' . $e->getMessage());
                continue;
            }

            try {
                $this->dhcpService->validateIp($ip);
            } catch (DhcpValidationException $e) {
                $errors++;
                $reportRows[] = ImportReportRow::error($line, $name, $rawMac, $ip, 'IP invalide : ' . $e->getMessage());
                continue;
            }

            // Doublons intra-fichier (entre deux lignes du CSV).
            if (isset($seenMacsThisRun[$mac])) {
                $errors++;
                $reportRows[] = ImportReportRow::error($line, $name, $mac, $ip, "MAC déjà rencontrée à la ligne {$seenMacsThisRun[$mac]} du même fichier");
                continue;
            }
            if (isset($seenIpsThisRun[$ip])) {
                $errors++;
                $reportRows[] = ImportReportRow::error($line, $name, $mac, $ip, "IP déjà rencontrée à la ligne {$seenIpsThisRun[$ip]} du même fichier");
                continue;
            }
            if (isset($seenNamesThisRun[$name])) {
                $errors++;
                $reportRows[] = ImportReportRow::error($line, $name, $mac, $ip, "Nom déjà rencontré à la ligne {$seenNamesThisRun[$name]} du même fichier");
                continue;
            }

            // Upsert (par MAC en priorité, sinon name, sinon IP — fallback
            // séquentiel déterministe pour respecter D-CSV-UPSERT et éviter
            // les écrasements de ligne arbitraires. Cf. review code 8.1 #3).
            try {
                $action = DB::transaction(function () use ($name, $mac, $ip, $description) {
                    $existing = DhcpReservation::query()->where('mac', $mac)->first()
                        ?? DhcpReservation::query()->where('name', $name)->first()
                        ?? DhcpReservation::query()->where('ip', $ip)->first();

                    if ($existing === null) {
                        DhcpReservation::create([
                            'name' => $name,
                            'mac' => $mac,
                            'ip' => $ip,
                            'description' => $description,
                            'source' => DhcpReservation::SOURCE_IMPORT,
                        ]);
                        return 'created';
                    }

                    $existing->fill([
                        'name' => $name,
                        'mac' => $mac,
                        'ip' => $ip,
                        'description' => $description,
                    ])->save();
                    return 'updated';
                });
            } catch (\Throwable $e) {
                $errors++;
                $reportRows[] = ImportReportRow::error($line, $name, $mac, $ip, 'Erreur SQL : ' . $e->getMessage());
                continue;
            }

            $seenMacsThisRun[$mac] = $line;
            $seenIpsThisRun[$ip] = $line;
            $seenNamesThisRun[$name] = $line;

            if ($action === 'created') {
                $ok++;
                $reportRows[] = ImportReportRow::ok($line, $name, $mac, $ip, 'created');
            } else {
                $updated++;
                $reportRows[] = ImportReportRow::ok($line, $name, $mac, $ip, 'updated');
            }
            $touchedAny = true;
        }

        // Un seul reload à la fin (AC5).
        if ($touchedAny) {
            try {
                $this->dhcpService->exportReservationsFile();
                $this->dhcpService->reloadService();
            } catch (DhcpCommandException $e) {
                Log::channel($this->logChannel())->warning('DhcpImportService: reload post-import a échoué — réservations persistées', [
                    'error' => $e->getMessage(),
                ]);
                $reportRows[] = ImportReportRow::error(
                    0,
                    null,
                    null,
                    null,
                    'Reload service DHCP échoué après import — les réservations sont en base mais le service doit être rechargé manuellement : ' . $e->firstStderrLine(),
                );
                $errors++;
            }
        }

        return $this->finalize($reportRows, total: $total, ok: $ok, updated: $updated, errors: $errors, skipped: $skipped);
    }

    /**
     * @param  ImportReportRow[]  $rows
     */
    private function finalize(array $rows, int $total, int $ok, int $updated, int $errors, int $skipped): ImportReport
    {
        $uuid = (string) Str::uuid();
        $report = new ImportReport(
            uuid: $uuid,
            total: $total,
            ok: $ok,
            updated: $updated,
            errors: $errors,
            skipped: $skipped,
            rows: $rows,
            createdAt: now()->toIso8601String(),
        );

        Cache::put(self::CACHE_PREFIX . $uuid, $report->toArray(), self::CACHE_TTL_SECONDS);

        Log::channel($this->logChannel())->info('DhcpImportService: import terminé', [
            'uuid' => $uuid,
            'total' => $total,
            'ok' => $ok,
            'updated' => $updated,
            'errors' => $errors,
            'skipped' => $skipped,
        ]);

        return $report;
    }

    /**
     * Récupère un rapport persisté en cache (ou null si expiré / introuvable).
     */
    public function fetchReport(string $uuid): ?ImportReport
    {
        $raw = Cache::get(self::CACHE_PREFIX . $uuid);
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw)) {
            return null;
        }
        return ImportReport::fromArray($raw);
    }

    private function logChannel(): string
    {
        return config('logging.channels.network') !== null ? 'network' : config('logging.default', 'single');
    }
}
