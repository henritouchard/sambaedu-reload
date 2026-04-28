<?php

declare(strict_types=1);

namespace App\Services\Print;

use App\Services\Print\Contracts\CommandRunner;
use App\Services\Print\Exceptions\CupsCommandException;
use App\Services\Print\Exceptions\CupsDaemonDownException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Story 6.1 — Service d'encapsulation des commandes CUPS.
 *
 * Wrappe les binaires `lpstat`, `lpadmin`, `cupsenable`, `cupsdisable`, `lpinfo`,
 * `smbcontrol` derrière une API typée. Tous les arguments user-controlled sont
 * `escapeshellarg`-és + revalidés via regex strictes (defense in depth, AC5/AC8).
 *
 * Pattern shellout aligné sur `App\Services\Filesystem\XfsQuotaService` (Story 5.1a) :
 *  - `escapeshellarg()` systématique avant `commandRunner->run()`.
 *  - Capture stdout / stderr / returnCode → `CupsCommandException` structurée.
 *  - Préfixe logs `CupsPrinterService:` (grep opérateurs).
 *  - `LC_ALL=C` centralisé dans `RealCommandRunner::run()` — la VM dev est en
 *    français ; sans `LC_ALL=C` les chaînes `is now printing` deviennent
 *    `imprime maintenant`, etc. (fix #14).
 *
 * Comportement CUPS-down : `listPrinters()` lève `CupsDaemonDownException` si
 * `lpstat -s` échoue, pour permettre aux appelants de distinguer « CUPS down »
 * de « liste vide » (fix #12). Le `PrintersSyncCommand` l'attrape pour éviter
 * de marquer tous les rows SER comme orphelins.
 */
class CupsPrinterService
{
    /** Longueur max d'un nom CUPS (cohérent legacy `config_printer.php:132`). */
    public const MAX_NAME_LENGTH = 15;

    /** Regex stricte du nom CUPS — pas de regex `/.../` côté `validate` Livewire. */
    public const NAME_REGEX = '/^[a-zA-Z0-9_-]{1,15}$/';

    /** Regex URI CUPS (socket / ipp / ipps / lpd / http / https). */
    public const URI_REGEX = '#^(socket|ipp|ipps|lpd|http|https)://[^\s\'"`$;|&<>\\\\]+$#';

    public function __construct(
        private readonly CommandRunner $commandRunner,
    ) {
    }

    // ========================================================================
    // VALIDATION (defense in depth)
    // ========================================================================

    /**
     * @throws InvalidArgumentException si nom non conforme.
     */
    public function validateName(string $name): void
    {
        if (preg_match(self::NAME_REGEX, $name) !== 1) {
            Log::warning('CupsPrinterService: nom imprimante invalide', ['name' => $name]);
            throw new InvalidArgumentException("Nom d'imprimante invalide : seuls les lettres/chiffres/_/- sont autorisés (max 15 caractères).");
        }
    }

    /**
     * @throws InvalidArgumentException si URI non conforme.
     */
    public function validateUri(string $uri): void
    {
        if (preg_match(self::URI_REGEX, $uri) !== 1) {
            Log::warning('CupsPrinterService: URI imprimante invalide (regex)', ['uri' => $uri]);
            throw new InvalidArgumentException('URI invalide. Formats acceptés : socket://, ipp://, ipps://, lpd://, http://, https://.');
        }

        // Double-validation structurelle via parse_url (defense in depth, fix #3).
        $parsed = parse_url($uri);
        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            Log::warning('CupsPrinterService: URI invalide (parse_url)', ['uri' => $uri]);
            throw new InvalidArgumentException('URI structurellement invalide (hôte ou schéma manquant).');
        }
    }

    // ========================================================================
    // SANTÉ CUPS
    // ========================================================================

    /**
     * Vérifie que le daemon CUPS répond via `lpstat -r`.
     *
     * Retourne `false` sans lever d'exception — les appelants décident quoi faire.
     */
    public function isHealthy(): bool
    {
        $result = $this->runQuiet('lpstat -r');
        return $result['returnCode'] === 0;
    }

    // ========================================================================
    // LISTAGE (lpstat)
    // ========================================================================

    /**
     * Liste toutes les imprimantes CUPS avec leur état + métadata.
     *
     * @throws CupsDaemonDownException si `lpstat -s` échoue (CUPS injoignable).
     *   Les appelants distinguent ainsi « CUPS down » de « 0 imprimante ».
     *
     * @return array<int, array{name:string,uri:string,state:string,description:?string,location:?string,model:?string,jobs_count:int}>
     */
    public function listPrinters(): array
    {
        $sList = $this->runQuiet('lpstat -s');
        if ($sList['returnCode'] !== 0) {
            Log::error('CupsPrinterService: lpstat -s échoué — daemon CUPS injoignable', [
                'stderr' => $sList['stderr'],
                'returnCode' => $sList['returnCode'],
            ]);
            throw new CupsDaemonDownException(
                'Le daemon CUPS est injoignable (lpstat -s → returnCode ' . $sList['returnCode'] . ').'
            );
        }

        $names = $this->parseLpstatS($sList['stdout']);
        if (empty($names)) {
            return [];
        }

        $details = $this->runQuiet('lpstat -l -p');
        if ($details['returnCode'] !== 0) {
            Log::warning('CupsPrinterService: lpstat -l -p échoué', [
                'stderr' => $details['stderr'],
                'returnCode' => $details['returnCode'],
            ]);
            return [];
        }

        $byName = $this->parseLpstatLp($details['stdout']);

        // Un seul appel `lpstat -o` pour tous les jobs (fix #2 N+1).
        $jobsCounts = $this->getAllJobsCounts(array_keys($names));

        $result = [];
        foreach ($names as $name => $uri) {
            $info = $byName[$name] ?? [];
            $result[] = [
                'name' => $name,
                'uri' => $uri,
                'state' => $info['state'] ?? 'unknown',
                'description' => $info['description'] ?? null,
                'location' => $info['location'] ?? null,
                'model' => $info['model'] ?? null,
                'jobs_count' => $jobsCounts[$name] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Récupère une imprimante précise (ou null si absente).
     *
     * @return array{name:string,uri:string,state:string,description:?string,location:?string,model:?string,jobs_count:int}|null
     * @throws CupsDaemonDownException (propagée depuis listPrinters)
     */
    public function getPrinter(string $name): ?array
    {
        $this->validateName($name);
        foreach ($this->listPrinters() as $printer) {
            if ($printer['name'] === $name) {
                return $printer;
            }
        }
        return null;
    }

    /**
     * Compte les jobs en attente d'une imprimante précise (`lpstat -o <name>`).
     * Méthode standalone conservée pour usage external ; `listPrinters()` utilise
     * `getAllJobsCounts()` (batch) pour éviter le N+1 (fix #2).
     */
    public function getJobsCount(string $name): int
    {
        $this->validateName($name);
        $result = $this->runQuiet('lpstat -o ' . escapeshellarg($name));
        if ($result['returnCode'] !== 0) {
            return 0;
        }
        return count(array_filter($result['stdout'], fn(string $l) => trim($l) !== ''));
    }

    /**
     * Liste les drivers PPD disponibles via `lpinfo -m`.
     *
     * @return array<int, array{ppd:string,model:string}>
     */
    public function listAvailableDrivers(): array
    {
        $result = $this->runQuiet('lpinfo -m');
        if ($result['returnCode'] !== 0) {
            Log::warning('CupsPrinterService: lpinfo -m échoué', [
                'stderr' => $result['stderr'],
                'returnCode' => $result['returnCode'],
            ]);
            return [];
        }

        $drivers = [];
        foreach ($result['stdout'] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 2);
            if (!is_array($parts) || count($parts) !== 2) {
                continue;
            }
            $drivers[] = [
                'ppd' => $parts[0],
                'model' => $parts[1],
            ];
        }
        return $drivers;
    }

    // ========================================================================
    // MUTATIONS (lpadmin, cupsenable, cupsdisable)
    // ========================================================================

    /**
     * Ajoute une imprimante via `lpadmin -p <name> -E -v <uri> [-D] [-L] [-m]`.
     *
     * Retourne le résultat du reload Samba best-effort (fix #15 : l'appelant
     * peut montrer un toast d'avertissement si le reload échoue).
     *
     * @throws InvalidArgumentException
     * @throws CupsCommandException
     */
    public function addPrinter(
        string $name,
        string $uri,
        ?string $description = null,
        ?string $location = null,
        ?string $ppd = null,
    ): bool {
        $this->validateName($name);
        $this->validateUri($uri);

        $command = 'sudo lpadmin -p ' . escapeshellarg($name)
            . ' -E -v ' . escapeshellarg($uri);

        if ($description !== null && $description !== '') {
            $command .= ' -D ' . escapeshellarg($description);
        }
        if ($location !== null && $location !== '') {
            $command .= ' -L ' . escapeshellarg($location);
        }
        if ($ppd !== null && $ppd !== '') {
            $command .= ' -m ' . escapeshellarg($ppd);
        }

        $this->runOrThrow($command, 'ajout imprimante', ['name' => $name]);
        return $this->reloadSamba();
    }

    /**
     * Met à jour la configuration CUPS d'une imprimante existante.
     *
     * Les clés acceptées dans `$changes` : `uri`, `description`, `location`, `ppd`.
     * Retourne le résultat du reload Samba (fix #15).
     *
     * @param  array{uri?:string,description?:string,location?:string,ppd?:string}  $changes
     * @throws InvalidArgumentException
     * @throws CupsCommandException
     */
    public function updatePrinter(string $name, array $changes): bool
    {
        $this->validateName($name);

        if (empty($changes)) {
            return true;
        }

        $command = 'sudo lpadmin -p ' . escapeshellarg($name);

        if (isset($changes['uri'])) {
            $this->validateUri((string) $changes['uri']);
            $command .= ' -v ' . escapeshellarg((string) $changes['uri']);
        }
        if (isset($changes['description'])) {
            $command .= ' -D ' . escapeshellarg((string) $changes['description']);
        }
        if (isset($changes['location'])) {
            $command .= ' -L ' . escapeshellarg((string) $changes['location']);
        }
        if (isset($changes['ppd']) && $changes['ppd'] !== '') {
            $command .= ' -m ' . escapeshellarg((string) $changes['ppd']);
        }

        $this->runOrThrow($command, 'modification imprimante', ['name' => $name]);
        return $this->reloadSamba();
    }

    /**
     * Supprime une imprimante CUPS via `lpadmin -x <name>`.
     *
     * Retourne le résultat du reload Samba (fix #15).
     *
     * @throws InvalidArgumentException
     * @throws CupsCommandException
     */
    public function deletePrinter(string $name): bool
    {
        $this->validateName($name);
        $command = 'sudo lpadmin -x ' . escapeshellarg($name);
        $this->runOrThrow($command, 'suppression imprimante', ['name' => $name]);
        return $this->reloadSamba();
    }

    /**
     * Active une imprimante (`cupsenable <name>`).
     *
     * @throws InvalidArgumentException
     * @throws CupsCommandException
     */
    public function enablePrinter(string $name): bool
    {
        $this->validateName($name);
        $command = 'sudo /usr/sbin/cupsenable ' . escapeshellarg($name);
        $this->runOrThrow($command, 'activation imprimante', ['name' => $name]);
        return true;
    }

    /**
     * Désactive une imprimante (`cupsdisable <name>`).
     *
     * @throws InvalidArgumentException
     * @throws CupsCommandException
     */
    public function disablePrinter(string $name): bool
    {
        $this->validateName($name);
        $command = 'sudo /usr/sbin/cupsdisable ' . escapeshellarg($name);
        $this->runOrThrow($command, 'désactivation imprimante', ['name' => $name]);
        return true;
    }

    // ========================================================================
    // PARSING privé
    // ========================================================================

    /**
     * Parse `lpstat -s` (LC_ALL=C) :
     *   "system default destination: PDF"
     *   "device for PDF: cups-pdf:/"
     *   "device for imp1: socket://192.0.2.10:9100"
     *
     * @param  string[]  $lines
     * @return array<string,string>  name => uri
     */
    private function parseLpstatS(array $lines): array
    {
        $names = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^device for ([^:]+):\s*(.+)$/', $line, $m)) {
                $names[$m[1]] = trim($m[2]);
            }
        }
        return $names;
    }

    /**
     * Parse `lpstat -l -p` (LC_ALL=C) — bloc multi-lignes par imprimante.
     *
     * @param  string[]  $lines
     * @return array<string, array{state:string,description:?string,location:?string,model:?string}>
     */
    private function parseLpstatLp(array $lines): array
    {
        $printers = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^printer (\S+) (.+)$/', $line, $m)) {
                $current = $m[1];
                $printers[$current] = [
                    'state' => $this->parseStateFromHeader($m[2]),
                    'description' => null,
                    'location' => null,
                    'model' => null,
                ];
                continue;
            }

            if ($current === null) {
                continue;
            }

            $trimmed = ltrim($line);
            if (preg_match('/^Description:\s*(.*)$/', $trimmed, $m)) {
                $printers[$current]['description'] = trim($m[1]) ?: null;
            } elseif (preg_match('/^Location:\s*(.*)$/', $trimmed, $m)) {
                $printers[$current]['location'] = trim($m[1]) ?: null;
            } elseif (preg_match('/^Interface:\s*(.*)$/', $trimmed, $m)) {
                $path = trim($m[1]);
                if ($path !== '' && preg_match('#/([^/]+)\.ppd$#i', $path, $mm)) {
                    $printers[$current]['model'] = $mm[1];
                }
            }
        }

        return $printers;
    }

    /**
     * Détermine l'état CUPS depuis la première ligne d'un bloc `lpstat -l -p`.
     */
    private function parseStateFromHeader(string $statusLine): string
    {
        $lower = strtolower($statusLine);
        if (str_contains($lower, 'disabled') || str_contains($lower, 'stopped')) {
            return 'disabled';
        }
        if (str_contains($lower, 'printing') || str_contains($lower, 'is now printing')) {
            return 'printing';
        }
        if (str_contains($lower, 'idle')) {
            return 'idle';
        }
        return 'unknown';
    }

    /**
     * Batch : compte les jobs de toutes les imprimantes en un seul `lpstat -o`.
     *
     * Chaque ligne stdout est un job : `<printer-name>-<job-id> <owner> <size> ...`.
     * Les noms sont triés par longueur décroissante pour matcher le plus long d'abord
     * (ex. `imp-a` avant `imp` si les deux coexistent).
     *
     * @param  string[]  $printerNames
     * @return array<string, int>  name => count
     */
    private function getAllJobsCounts(array $printerNames): array
    {
        $counts = array_fill_keys($printerNames, 0);

        if (empty($printerNames)) {
            return $counts;
        }

        $result = $this->runQuiet('lpstat -o');
        if ($result['returnCode'] !== 0) {
            return $counts;
        }

        $sorted = $printerNames;
        usort($sorted, fn($a, $b) => strlen($b) - strlen($a));

        foreach ($result['stdout'] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            foreach ($sorted as $name) {
                if (preg_match('/^' . preg_quote($name, '/') . '-\d+\b/', $line)) {
                    $counts[$name]++;
                    break;
                }
            }
        }

        return $counts;
    }

    // ========================================================================
    // PRIMITIVES Internal
    // ========================================================================

    /**
     * Run a shell command + log silencieux. `LC_ALL=C` est injecté par
     * `RealCommandRunner::run()` — ne pas le répéter ici.
     *
     * @return array{stdout: string[], stderr: string[], returnCode: int}
     */
    private function runQuiet(string $command): array
    {
        Log::debug('CupsPrinterService: exec quiet', ['command' => $command]);
        return $this->commandRunner->run($command);
    }

    /**
     * Run + throw `CupsCommandException` si returnCode != 0.
     *
     * @param  string  $action  Action humaine (ex. "ajout imprimante") pour log.
     * @param  array<string,mixed>  $context  Contexte additionnel pour log.
     * @throws CupsCommandException
     */
    private function runOrThrow(string $command, string $action, array $context = []): void
    {
        $result = $this->commandRunner->run($command);

        if ($result['returnCode'] !== 0) {
            Log::error("CupsPrinterService: échec {$action}", array_merge($context, [
                'command' => $command,
                'stderr' => $result['stderr'],
                'returnCode' => $result['returnCode'],
            ]));
            throw new CupsCommandException(
                "Échec {$action} : " . ($result['stderr'][0] ?? 'erreur inconnue'),
                $command,
                $result['stderr'],
                $result['returnCode'],
            );
        }

        Log::info("CupsPrinterService: {$action} OK", $context);
    }

    /**
     * Notifie Samba que la liste d'imprimantes a changé. Best-effort : retourne
     * `false` sans lever d'exception si le reload échoue, permettant à l'appelant
     * d'afficher un toast d'avertissement (fix #15).
     */
    private function reloadSamba(): bool
    {
        $result = $this->commandRunner->run('sudo /usr/bin/smbcontrol smbd reload-printers');
        if ($result['returnCode'] !== 0) {
            Log::warning('CupsPrinterService: reload smbd échoué (non-bloquant)', [
                'stderr' => $result['stderr'],
                'returnCode' => $result['returnCode'],
            ]);
            return false;
        }
        return true;
    }
}
