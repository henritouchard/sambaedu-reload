<?php

declare(strict_types=1);

namespace App\Services\Print;

use App\Models\PrinterDriver;
use App\Services\Print\Contracts\CommandRunner;
use App\Services\Print\Exceptions\KerberosTicketException;
use App\Services\Print\Exceptions\PrintDriverException;
use App\Services\Print\Exceptions\SambaUnavailableException;
use App\Services\Print\Exceptions\WindowsPivotUnreachableException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Story 6.2 — Service d'encapsulation des commandes Samba `rpcclient` /
 * `smbclient` pour la gestion des pilotes Windows publiés sur le share
 * `[print$]` du serveur SE4FS.
 *
 * Pattern shellout aligné sur {@see CupsPrinterService} (Story 6.1) :
 *  - `escapeshellarg()` systématique avant `commandRunner->run()`.
 *  - Capture stdout / stderr / returnCode → `PrintDriverException`
 *    structurée.
 *  - Préfixe logs `PrintDriverService:` (grep opérateurs).
 *  - `LC_ALL=C` centralisé dans `RealCommandRunner::run()` — la VM dev
 *    est en français ; sans `LC_ALL=C` la sortie de `smbclient` /
 *    `rpcclient` peut diverger.
 *
 * Pré-requis sécurité (defense in depth, AC9) :
 *  - regex stricte côté Livewire validation,
 *  - re-validation regex stricte ICI (Service) avant `escapeshellarg`,
 *  - `basename()` PHP forcé sur tout nom de fichier driver avant
 *    insertion dans un chemin destination (anti path-traversal),
 *  - chemin destination en CONSTANTE (`/var/lib/samba/printers/x64/`).
 *
 * Pré-requis auth (D4) :
 *  - tous les `rpcclient`/`smbclient` passent `--use-kerberos=required`
 *    (centralisé `buildRpcclientCommand` / `buildSmbclientCommand`),
 *  - pas de fallback NTLM (protection contre downgrade).
 *
 * Comportement Samba-down ({@see SambaUnavailableException}) :
 *  {@see PrinterDriversSyncCommand} l'attrape pour skip orphan-marking
 *  (décalque fix #12 6.1 sur CUPS).
 */
class PrintDriverService
{
    /** Longueur max du nom de driver Samba (`Generic / Generic PostScript Printer`…). */
    public const MAX_DRIVER_NAME_LENGTH = 255;

    /**
     * Regex nom driver Samba — lettres / chiffres / espace / `._-()` /
     * `/` (séparateur usuel ex. `HP / LaserJet 4`).
     *
     * Note : le slash `/` est inclus volontairement pour les noms de
     * drivers Samba canoniques (ex. `Generic / Generic PostScript Printer`).
     * Pas de risque shell : la chaîne est toujours passée via
     * `escapeshellarg()` côté `buildRpcclientCommand`.
     */
    public const DRIVER_NAME_REGEX = '/^[a-zA-Z0-9 ._\-()\/]{1,255}$/';

    /**
     * Regex hostname NetBIOS — first char alphanum + 0-14 alphanum/dash.
     * (Conforme RFC 1035 / NetBIOS — 15 chars max.)
     */
    public const HOSTNAME_REGEX = '/^[a-zA-Z0-9][a-zA-Z0-9-]{0,14}$/';

    /**
     * Regex nom d'imprimante CUPS — alignée sur
     * {@see CupsPrinterService::NAME_REGEX} (6.1). Autorise l'underscore
     * (`imp_salle_a`), contrairement au strict NetBIOS.
     */
    public const CUPS_NAME_REGEX = '/^[a-zA-Z0-9_-]{1,15}$/';

    /**
     * Regex nom de fichier driver (`pscript5.dll`, `PSCRIPT.PPD`…).
     * Pas de `/`, `..`, ni null byte (anti path-traversal).
     */
    public const FILE_NAME_REGEX = '/^[a-zA-Z0-9._\-]{1,255}$/';

    /** Architectures supportées en 6.2 (D5 : `x64` uniquement). */
    public const ARCHITECTURE_ALLOWED = ['x64'];

    /**
     * Mapping interne SER → étiquette `rpcclient adddriver` (D5/legacy
     * `printers.inc.php:111` : `"Windows x64"` exactement).
     */
    public const ARCHITECTURE_LABEL = [
        'x64' => 'Windows x64',
    ];

    /** Répertoire destination des fichiers driver côté serveur SE4FS. */
    public const DRIVERS_DIR_X64 = '/var/lib/samba/printers/x64';

    /** Marqueur stderr typique d'un échec Kerberos (KRB5_*). */
    private const KRB_ERROR_MARKERS = [
        'KRB5_KT_NOTFOUND',
        'KRB5KRB_AP_ERR_TKT_EXPIRED',
        'KRB5_KDC_UNREACH',
        'Cannot find KDC',
        'cannot get a ticket',
        'no credentials cache',
        'no credentials found',
        // Variante CIFS-side d'un KDC injoignable — sans cela l'erreur
        // tombe dans `PrintDriverException` générique au lieu de
        // `KerberosTicketException` (UX dégradée).
        'NT_STATUS_NO_LOGON_SERVERS',
    ];

    /**
     * Marqueurs stderr typiques d'un pivot W10 injoignable
     * (cf. man smbclient + retours observés).
     */
    private const PIVOT_UNREACHABLE_MARKERS = [
        'NT_STATUS_HOST_UNREACHABLE',
        'NT_STATUS_BAD_NETWORK_NAME',
        'NT_STATUS_CONNECTION_REFUSED',
        'NT_STATUS_IO_TIMEOUT',
        'Connection to ',
        'Connection refused',
        'Name resolution failed',
        'session setup failed',
    ];

    public function __construct(
        private readonly CommandRunner $commandRunner,
    ) {
    }

    // ========================================================================
    // CONFIG / CONSTRUCTION DE COMMANDES
    // ========================================================================

    /**
     * Nom du serveur SE4FS (cible des appels `rpcclient` pour les drivers
     * publiés sur `[print$]`). Lu via `config('sambaedu.se4fs_name')`
     * — équivalent `$config['se4fs_name']` legacy.
     *
     * Privé pour éviter qu'un Livewire ou un autre service en construise
     * la valeur lui-même (anti-pattern). Pour lire la définition d'un
     * driver côté SE4FS, utiliser {@see getDriverDefinitionFromSe4fs}.
     */
    private function getServerName(): string
    {
        $name = (string) config('sambaedu.se4fs_name', 'se4fs');
        // Pré-flight de sécurité — refuser un nom de serveur invalide
        // configuré côté admin (pas censé arriver, mais defense in depth).
        if (preg_match(self::HOSTNAME_REGEX, $name) !== 1) {
            Log::error('PrintDriverService: config sambaedu.se4fs_name invalide', ['value' => $name]);
            throw new InvalidArgumentException('Configuration `sambaedu.se4fs_name` invalide : hostname NetBIOS attendu.');
        }
        return $name;
    }

    /**
     * Helper publique : lit la définition d'un driver côté serveur SE4FS
     * (auto-résout le serveur via la config). Sucre syntaxique pour le
     * Livewire — évite d'exposer `getServerName()`.
     *
     * @return array{
     *   "Driver Name": string,
     *   "Driver Path": string,
     *   "Datafile": string,
     *   "Configfile": string,
     *   "Helpfile": string,
     *   "Dependentfiles": string[],
     *   "Architecture": string,
     * }
     * @throws SambaUnavailableException|PrintDriverException
     */
    public function getDriverDefinitionFromSe4fs(string $driverName): array
    {
        return $this->getDriverDefinition($this->getServerName(), $driverName);
    }

    /**
     * Construit une commande `sudo rpcclient` avec :
     *  - `--use-kerberos=required` (D4 strict),
     *  - `escapeshellarg` sur le serveur cible,
     *  - `escapeshellarg` sur la commande `-c '...'` interne.
     *
     * @param  string  $cmd  Commande rpcclient interne (ex. `enumdrivers`,
     *                       `adddriver "..."`). Déjà construite, NON escape.
     *                       Le caller est responsable de la sanitization des
     *                       champs internes (regex + concat ASCII safe).
     */
    private function buildRpcclientCommand(string $cmd, string $server): string
    {
        return 'sudo /usr/bin/rpcclient '
            . escapeshellarg($server)
            . ' --use-kerberos=required -c '
            . escapeshellarg($cmd);
    }

    /**
     * Construit une commande `sudo smbclient //server/print$` avec
     * `--use-kerberos=required` (D4) + escapeshellarg sur le UNC + escape
     * sur la sous-commande SMB `-c '...'`.
     */
    private function buildSmbclientCommand(string $serverPivot, string $smbCmd): string
    {
        // UNC `//pivot/print$` — pivot validé hostname strict avant escape.
        $unc = '//' . $serverPivot . '/print$';
        return 'sudo /usr/bin/smbclient '
            . escapeshellarg($unc)
            . ' --use-kerberos=required -c '
            . escapeshellarg($smbCmd);
    }

    // ========================================================================
    // VALIDATION (defense in depth, AC9)
    // ========================================================================

    /**
     * Valide le nom canonique d'un driver Samba.
     *
     * @throws InvalidArgumentException
     */
    public function validateDriverName(string $name): void
    {
        if (preg_match(self::DRIVER_NAME_REGEX, $name) !== 1) {
            Log::warning('PrintDriverService: nom de driver invalide', ['name' => $name]);
            throw new InvalidArgumentException(
                'Nom de driver invalide : seuls lettres / chiffres / espace / `._-()/` sont autorisés (max 255 caractères).'
            );
        }
        if (str_contains($name, '..')) {
            Log::warning('PrintDriverService: nom de driver path-traversal détecté', ['name' => $name]);
            throw new InvalidArgumentException(
                'Nom de driver invalide : séquence `..` interdite (path traversal).'
            );
        }
    }

    /**
     * Valide un hostname NetBIOS (pivot W10 ou serveur SE4FS).
     *
     * @throws InvalidArgumentException
     */
    public function validatePivotHostname(string $hostname): void
    {
        if (preg_match(self::HOSTNAME_REGEX, $hostname) !== 1) {
            Log::warning('PrintDriverService: hostname pivot invalide', ['hostname' => $hostname]);
            throw new InvalidArgumentException(
                'Hostname pivot invalide : 1-15 caractères, alphanum + tiret, premier caractère alphanum.'
            );
        }
    }

    /**
     * Valide un nom d'imprimante CUPS. Plus permissif que
     * `validatePivotHostname` (autorise l'underscore — cohérent
     * {@see CupsPrinterService::NAME_REGEX} 6.1).
     *
     * @throws InvalidArgumentException
     */
    public function validateCupsName(string $cupsName): void
    {
        if (preg_match(self::CUPS_NAME_REGEX, $cupsName) !== 1) {
            Log::warning('PrintDriverService: nom d\'imprimante CUPS invalide', ['cups_name' => $cupsName]);
            throw new InvalidArgumentException(
                'Nom d\'imprimante CUPS invalide : 1-15 caractères, alphanum + `_` + `-`.'
            );
        }
    }

    /**
     * Valide l'architecture (D5 : `x64` uniquement en 6.2).
     *
     * @throws InvalidArgumentException
     */
    public function validateArchitecture(string $arch): void
    {
        if (!in_array($arch, self::ARCHITECTURE_ALLOWED, true)) {
            Log::warning('PrintDriverService: architecture non supportée', ['arch' => $arch]);
            throw new InvalidArgumentException(
                'Architecture non supportée : seul `x64` est autorisé en 6.2 (cf. D5).'
            );
        }
    }

    /**
     * Valide un nom de fichier driver (anti path-traversal).
     *
     * @throws InvalidArgumentException
     */
    public function validateFileName(string $fileName): void
    {
        if (preg_match(self::FILE_NAME_REGEX, $fileName) !== 1) {
            Log::warning('PrintDriverService: nom de fichier driver invalide', ['file' => $fileName]);
            throw new InvalidArgumentException(
                'Nom de fichier driver invalide : seuls lettres / chiffres / `._-` sont autorisés (max 255 caractères).'
            );
        }
        // Defense in depth — `basename` doit retourner le nom inchangé.
        if (basename($fileName) !== $fileName) {
            Log::warning('PrintDriverService: nom de fichier path-traversal détecté', ['file' => $fileName]);
            throw new InvalidArgumentException(
                'Nom de fichier driver invalide : path traversal détecté.'
            );
        }
    }

    // ========================================================================
    // SANTÉ
    // ========================================================================

    /**
     * Vérifie que `rpcclient srvinfo <se4fs>` répond. Retourne `false`
     * sans lever d'exception — les appelants décident (sync command
     * skip orphan-marking si false, cohérent fix #12 6.1).
     */
    public function isSambaHealthy(): bool
    {
        $cmd = $this->buildRpcclientCommand('srvinfo', $this->getServerName());
        $result = $this->runQuiet($cmd);
        if ($result['returnCode'] !== 0) {
            if ($this->isKerberosFailure($result['stderr'])) {
                Log::warning('PrintDriverService: Kerberos ticket KO sur srvinfo', [
                    'stderr' => $result['stderr'],
                ]);
            } else {
                Log::warning('PrintDriverService: Samba injoignable (srvinfo RC != 0)', [
                    'returnCode' => $result['returnCode'],
                    'stderr' => $result['stderr'],
                ]);
            }
            return false;
        }
        return true;
    }

    /**
     * Vérifie qu'un poste pivot W10 répond (`smbclient -L //pivot`).
     */
    public function isPivotReachable(string $serverPivot): bool
    {
        $this->validatePivotHostname($serverPivot);
        $cmd = 'sudo /usr/bin/smbclient -L ' . escapeshellarg($serverPivot) . ' --use-kerberos=required';
        $result = $this->runQuiet($cmd);
        return $result['returnCode'] === 0;
    }

    // ========================================================================
    // LECTURE (rpcclient enumdrivers / getdriver / enumprinters / getprinter)
    // ========================================================================

    /**
     * Liste tous les drivers publiés sur le serveur SE4FS via
     * `rpcclient enumdrivers`. Parse les lignes `Driver Name: [<name>]`
     * (legacy `printers.inc.php:478` regex).
     *
     * @return array<int, array{driver_name:string,architecture:string}>
     * @throws SambaUnavailableException
     */
    public function listAllDrivers(): array
    {
        $server = $this->getServerName();
        $cmd = $this->buildRpcclientCommand('enumdrivers', $server);
        $result = $this->runQuiet($cmd);

        if ($result['returnCode'] !== 0) {
            $this->throwForRpcclientFailure($cmd, $result, 'listage drivers Samba');
        }

        $drivers = [];
        $seen = [];
        foreach ($result['stdout'] as $line) {
            // Legacy printers.inc.php:478 : `/^.*Driver Name: \[(.*)\]$/`
            if (preg_match('/^\s*Driver Name:\s*\[(.+)\]\s*$/', $line, $m) === 1) {
                $name = $m[1];
                $key = $name . '|x64';
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $drivers[] = [
                    'driver_name' => $name,
                    'architecture' => 'x64', // D5 — 6.2 ne supporte que x64
                ];
            }
        }
        return $drivers;
    }

    /**
     * Lit la définition complète d'un driver via `rpcclient getdriver
     * "<name>"` (sur le serveur cible — soit SE4FS pour un driver déjà
     * publié, soit un pivot W10 pour récupérer la définition d'un
     * driver installé localement).
     *
     * Parse legacy `printers.inc.php:47-58` :
     *  - Lignes `\s*<Key>: [<...\3\>?<value>]`,
     *  - Cas spécial `Dependentfiles` : ligne multiple → array.
     *  - Cas spécial `(null)` ou vide → string `"NULL"` (compat `adddriver`).
     *
     * @return array{
     *   "Driver Name": string,
     *   "Driver Path": string,
     *   "Datafile": string,
     *   "Configfile": string,
     *   "Helpfile": string,
     *   "Dependentfiles": string[],
     *   "Architecture": string,
     * }
     * @throws SambaUnavailableException|WindowsPivotUnreachableException|PrintDriverException
     */
    public function getDriverDefinition(string $serverPivot, string $driverName): array
    {
        $this->validatePivotHostname($serverPivot);
        $this->validateDriverName($driverName);

        $cmd = $this->buildRpcclientCommand(
            sprintf('getdriver "%s"', $driverName),
            $serverPivot,
        );
        $result = $this->runQuiet($cmd);

        if ($result['returnCode'] !== 0) {
            $this->throwForRpcclientFailure($cmd, $result, "lecture définition driver \"{$driverName}\"", $serverPivot);
        }

        $driver = [
            'Driver Name' => '',
            'Driver Path' => 'NULL',
            'Datafile' => 'NULL',
            'Configfile' => 'NULL',
            'Helpfile' => 'NULL',
            'Dependentfiles' => [],
            'Architecture' => 'Windows x64', // D5
        ];

        foreach ($result['stdout'] as $line) {
            // Legacy printers.inc.php:48 regex :
            // /^\s*(.*): \[((.*\\3\\)?(.*))\]/
            if (preg_match('/^\s*(.+?):\s*\[((.*\\\\3\\\\)?(.*))\]\s*$/', $line, $m) !== 1) {
                continue;
            }
            $key = trim($m[1]);
            $value = $m[4] ?? '';
            if ($key === 'Dependentfiles') {
                if ($value !== '' && $value !== '(null)') {
                    $driver['Dependentfiles'][] = $value;
                }
                continue;
            }
            if ($value === '' || $value === '(null)') {
                // legacy printers.inc.php:53 — NULL string littéral pour
                // les champs vides (compat `rpcclient adddriver`).
                if (array_key_exists($key, $driver)) {
                    $driver[$key] = 'NULL';
                }
                continue;
            }
            if (array_key_exists($key, $driver)) {
                $driver[$key] = $value;
            }
        }

        if ($driver['Driver Name'] === '') {
            // Sortie inattendue / driver absent côté pivot — on traite
            // comme un échec service même si RC=0 (parsing vide).
            Log::warning('PrintDriverService: getdriver retourne RC=0 mais aucun champ Driver Name', [
                'server' => $serverPivot,
                'driver' => $driverName,
                'stdout_lines' => count($result['stdout']),
            ]);
            throw new PrintDriverException(
                "Définition driver \"{$driverName}\" introuvable côté {$serverPivot}.",
                $cmd,
                $result['stderr'],
                0,
            );
        }

        return $driver;
    }

    /**
     * Liste les imprimantes partagées sur un poste pivot W10 via
     * `rpcclient enumprinters <pivot>`. Sert au workflow upload (l'admin
     * choisit ensuite l'imprimante / le driver à téléverser).
     *
     * Parse legacy `printers.inc.php:572-583` :
     *  `/^\s*description:\[.*\\(.+),(.+),(.+)\]$/`
     *
     * @return array<int, array{smb_name:string,smb_driver:string,smb_comment:string}>
     * @throws SambaUnavailableException|WindowsPivotUnreachableException
     */
    public function listPrintersOnPivot(string $serverPivot): array
    {
        $this->validatePivotHostname($serverPivot);
        $cmd = $this->buildRpcclientCommand('enumprinters', $serverPivot);
        $result = $this->runQuiet($cmd);

        if ($result['returnCode'] !== 0) {
            $this->throwForRpcclientFailure($cmd, $result, 'listage imprimantes pivot', $serverPivot);
        }

        $printers = [];
        foreach ($result['stdout'] as $line) {
            // Non-greedy : Samba sépare strictement les 3 champs par
            // virgule. Si un champ contient une virgule (ex.
            // `Acme, Inc Printer`), le legacy ne le parse pas non plus.
            if (preg_match('/^\s*description:\s*\[.*\\\\([^,]+),([^,]*),([^,]*)\]\s*$/', $line, $m) === 1) {
                $printers[] = [
                    'smb_name' => trim($m[1]),
                    'smb_driver' => trim($m[2]),
                    'smb_comment' => trim($m[3]),
                ];
            }
        }
        return $printers;
    }

    /**
     * Énumère les imprimantes CUPS publiées côté SE4FS avec leur driver
     * Samba associé (auto-résout le serveur via la config). Sucre
     * syntaxique pour la commande de sync — évite d'exposer
     * `getServerName()`.
     *
     * @return array<int, array{smb_name:string,smb_driver:string,smb_comment:string}>
     * @throws SambaUnavailableException
     */
    public function listPrintersOnSe4fs(): array
    {
        return $this->listPrintersOnPivot($this->getServerName());
    }

    /**
     * Lit l'association printer→driver côté Samba via `rpcclient
     * getprinter "<name>"` sur SE4FS.
     *
     * Parse legacy `printers.inc.php:542-547`.
     *
     * @return array{smb_name:string,smb_driver:string,smb_comment:string}|null
     * @throws SambaUnavailableException
     */
    public function getDriverForPrinter(string $cupsName): ?array
    {
        $this->validateCupsName($cupsName);
        $server = $this->getServerName();
        $cmd = $this->buildRpcclientCommand(
            sprintf('getprinter "%s"', $cupsName),
            $server,
        );
        $result = $this->runQuiet($cmd);

        if ($result['returnCode'] !== 0) {
            // Cas particulier : imprimante absente côté Samba — pas une
            // erreur de service, juste un état (`null`).
            if ($this->stderrSuggestsPrinterMissing($result['stderr'])) {
                return null;
            }
            $this->throwForRpcclientFailure($cmd, $result, "lecture association printer \"{$cupsName}\"");
        }

        foreach ($result['stdout'] as $line) {
            // Non-greedy : cf. note `listPrintersOnPivot`.
            if (preg_match('/^\s*description:\s*\[([^,]+),([^,]*),([^,]*)\]\s*$/', $line, $m) === 1) {
                return [
                    'smb_name' => trim($m[1]),
                    'smb_driver' => trim($m[2]),
                    'smb_comment' => trim($m[3]),
                ];
            }
        }
        return null;
    }

    /**
     * Combine la vue Samba (driver effectivement attaché à l'imprimante
     * runtime) avec les rangées SER (audit + provenance + notes) pour
     * une imprimante donnée. Utilisé par la modale édit (AC1).
     *
     * @return array{
     *   samba: array{smb_name:string,smb_driver:string,smb_comment:string}|null,
     *   ser: list<array{driver_name:string,architecture:string,source:string,orphan:bool,notes:?string,created_at:?string,created_by_user_id:?int}>,
     * }
     * @throws SambaUnavailableException
     */
    public function listDriversForPrinter(string $cupsName): array
    {
        $samba = $this->getDriverForPrinter($cupsName);

        $ser = PrinterDriver::query()
            ->where('printer_cups_name', $cupsName)
            ->orderBy('architecture')
            ->orderBy('driver_name')
            ->get()
            ->map(fn(PrinterDriver $d) => [
                'driver_name' => $d->driver_name,
                'architecture' => $d->architecture,
                'source' => $d->source,
                'orphan' => $d->orphan,
                'notes' => $d->notes,
                'created_at' => $d->created_at?->toIso8601String(),
                'created_by_user_id' => $d->created_by_user_id,
            ])
            ->values()
            ->all();

        return [
            'samba' => $samba,
            'ser' => $ser,
        ];
    }

    // ========================================================================
    // MUTATION (smbclient copy / rpcclient adddriver / setdriver / deldriver)
    // ========================================================================

    /**
     * Récupère un fichier driver depuis le partage `[print$]` du pivot
     * W10 vers `/var/lib/samba/printers/x64/<file>`, puis pose le
     * propriétaire `www-admin:www-admin` (sudo path-restricted, D11).
     *
     * Workflow legacy `printers.inc.php:69,81`. Différences 6.2 :
     *  - escape complet du serveur et de la sous-commande SMB,
     *  - validation regex stricte du fileName (anti path-traversal),
     *  - `basename($fileName)` forcé en pré-flight,
     *  - chown post-copy obligatoire (pas legacy mais nécessaire pour
     *    `unlink` ultérieur via sudoers path-restricted D11).
     *
     * @throws InvalidArgumentException|WindowsPivotUnreachableException|PrintDriverException
     */
    public function copyDriverFile(string $serverPivot, string $fileName, string $destDir = self::DRIVERS_DIR_X64): bool
    {
        $this->validatePivotHostname($serverPivot);
        $this->validateFileName($fileName);
        $safeName = basename($fileName); // ceinture + bretelles
        if ($safeName !== $fileName) {
            throw new InvalidArgumentException('Nom de fichier driver invalide (basename divergent).');
        }

        $destPath = rtrim($destDir, '/') . '/' . $safeName;

        // Sous-commande SMB : `cd x64\3;get <orig> <dest>` (legacy l. 81).
        // Le path destination passe directement dans la sous-commande
        // SMB — pas d'escapeshellarg interne (déjà encapsulé par le `-c`
        // global). On reste donc en path constant + basename.
        $smbCmd = sprintf('cd x64\3;get %s %s', $safeName, $destPath);
        $cmd = $this->buildSmbclientCommand($serverPivot, $smbCmd);

        $result = $this->runQuiet($cmd);
        if ($result['returnCode'] !== 0) {
            $this->throwForSmbclientFailure($cmd, $result, "copie fichier driver \"{$safeName}\" depuis pivot", $serverPivot);
        }

        // Pose proprio post-copy (D11 path-restricted, exigée pour les
        // `unlink` ultérieurs et la lisibilité par www-admin/PHP).
        $chownCmd = 'sudo /bin/chown www-admin:www-admin ' . escapeshellarg($destPath);
        $chownResult = $this->runQuiet($chownCmd);
        if ($chownResult['returnCode'] !== 0) {
            Log::warning('PrintDriverService: chown post-copy échoué (non-bloquant)', [
                'file' => $safeName,
                'returnCode' => $chownResult['returnCode'],
                'stderr' => $chownResult['stderr'],
            ]);
        }

        Log::info('PrintDriverService: fichier driver copié', [
            'pivot' => $serverPivot,
            'file' => $safeName,
        ]);
        return true;
    }

    /**
     * Enregistre un driver auprès de Samba via `rpcclient adddriver`.
     *
     * Format strict legacy `printers.inc.php:110-112` :
     * ```
     * adddriver "Windows x64" "<DriverName>:<DriverPath>:<DataFile>:<ConfigFile>:<HelpFile>:NULL:NULL:<deps>" "3"
     * ```
     *
     * Les champs `(null)` ou vides sont passés en string littéral `"NULL"`
     * (pas `null` PHP — `rpcclient` exige littéralement la chaîne `NULL`
     * pour les fields Monitor/DefaultDataType).
     *
     * @param  array{
     *   "Driver Name": string,
     *   "Driver Path": string,
     *   "Datafile": string,
     *   "Configfile"?: string,
     *   "Helpfile"?: string,
     *   "Dependentfiles"?: string[],
     *   "Architecture"?: string,
     * }  $driverDef
     * @throws InvalidArgumentException|SambaUnavailableException|PrintDriverException
     */
    public function registerDriver(array $driverDef): bool
    {
        // Validation field-par-field (defense in depth).
        $name = $driverDef['Driver Name'] ?? '';
        $this->validateDriverName($name);

        // L'archi `Windows x64` est la SEULE valeur acceptée en 6.2 (D5).
        $arch = $driverDef['Architecture'] ?? self::ARCHITECTURE_LABEL['x64'];
        if ($arch !== self::ARCHITECTURE_LABEL['x64']) {
            Log::warning('PrintDriverService: architecture non x64 rejetée', ['arch' => $arch]);
            throw new InvalidArgumentException('Architecture non supportée — seul "Windows x64" est autorisé en 6.2.');
        }

        // Chaque fichier driver doit être validé avant insertion dans le
        // payload `adddriver` (anti path-traversal + injection).
        $driverPath = (string) ($driverDef['Driver Path'] ?? 'NULL');
        $dataFile = (string) ($driverDef['Datafile'] ?? 'NULL');
        $configFile = (string) ($driverDef['Configfile'] ?? 'NULL');
        $helpFile = (string) ($driverDef['Helpfile'] ?? 'NULL');

        foreach (['Driver Path' => $driverPath, 'Datafile' => $dataFile, 'Configfile' => $configFile, 'Helpfile' => $helpFile] as $label => $val) {
            if ($val !== 'NULL') {
                $this->validateFileName($val);
            }
        }

        $deps = $driverDef['Dependentfiles'] ?? [];
        foreach ($deps as $dep) {
            if ($dep === '' || $dep === 'NULL') {
                continue;
            }
            $this->validateFileName($dep);
        }
        $depsCsv = implode(',', array_filter($deps, fn($d) => $d !== '' && $d !== 'NULL'));

        // Format payload strict (legacy printers.inc.php:111-112).
        $payload = sprintf(
            '%s:%s:%s:%s:%s:NULL:NULL:%s',
            $name,
            $driverPath,
            $dataFile,
            $configFile,
            $helpFile,
            $depsCsv,
        );

        // Construction du `adddriver "Windows x64" "<payload>" "3"`.
        // Les guillemets DOUBLES font partie du protocole rpcclient — ils
        // sont inclus DANS la sous-commande, puis encapsulés par le
        // `escapeshellarg` global du `buildRpcclientCommand` (couche
        // shell qui escape les single-quotes).
        $rpcCmd = sprintf('adddriver "%s" "%s" "3"', $arch, $payload);
        $cmd = $this->buildRpcclientCommand($rpcCmd, $this->getServerName());

        $result = $this->commandRunner->run($cmd);
        if ($result['returnCode'] !== 0) {
            Log::error('PrintDriverService: échec enregistrement driver', [
                'driver' => $name,
                'command' => $cmd,
                'stderr' => $result['stderr'],
                'returnCode' => $result['returnCode'],
            ]);
            $this->throwForRpcclientFailure($cmd, $result, "enregistrement driver \"{$name}\"");
        }

        Log::info('PrintDriverService: driver enregistré', ['driver' => $name]);
        return true;
    }

    /**
     * Associe un driver Samba à une imprimante CUPS via `rpcclient
     * setdriver "<printer>" "<driver>"`. Legacy `printers.inc.php:436`.
     *
     * @throws InvalidArgumentException|SambaUnavailableException|PrintDriverException
     */
    public function attachDriverToPrinter(string $cupsName, string $driverName): bool
    {
        $this->validateCupsName($cupsName);
        $this->validateDriverName($driverName);

        $rpcCmd = sprintf('setdriver "%s" "%s"', $cupsName, $driverName);
        $cmd = $this->buildRpcclientCommand($rpcCmd, $this->getServerName());

        $result = $this->commandRunner->run($cmd);
        if ($result['returnCode'] !== 0) {
            $this->throwForRpcclientFailure($cmd, $result, "association driver \"{$driverName}\" à imprimante \"{$cupsName}\"");
        }

        Log::info('PrintDriverService: driver associé à imprimante', [
            'cups_name' => $cupsName,
            'driver' => $driverName,
        ]);
        return true;
    }

    /**
     * Détache le driver d'une imprimante : `rpcclient setdriver
     * "<printer>" ""` (reset à empty — sémantique Samba documentée).
     *
     * @throws InvalidArgumentException|SambaUnavailableException|PrintDriverException
     */
    public function detachDriverFromPrinter(string $cupsName): bool
    {
        $this->validateCupsName($cupsName);

        $rpcCmd = sprintf('setdriver "%s" ""', $cupsName);
        $cmd = $this->buildRpcclientCommand($rpcCmd, $this->getServerName());

        $result = $this->commandRunner->run($cmd);
        if ($result['returnCode'] !== 0) {
            $this->throwForRpcclientFailure($cmd, $result, "détachement driver de imprimante \"{$cupsName}\"");
        }

        Log::info('PrintDriverService: driver détaché de imprimante', ['cups_name' => $cupsName]);
        return true;
    }

    /**
     * Supprime un driver de Samba : `rpcclient deldriver "<name>"` + unlink
     * sudo path-restricted des fichiers physiques associés (D11).
     *
     * **Pré-condition** : l'appelant DOIT vérifier que le driver n'est
     * plus rattaché à aucune imprimante CUPS (D8 — refus côté UI sinon).
     *
     * @param  string[]  $associatedFiles  Liste de noms de fichiers (sans
     *   path) à supprimer post-deldriver (déjà extraits via
     *   `getDriverDefinition` côté appelant). Chaque nom est revalidé
     *   regex stricte ici (defense in depth).
     * @throws InvalidArgumentException|SambaUnavailableException|PrintDriverException
     */
    public function deleteDriver(string $driverName, string $architecture = 'x64', array $associatedFiles = []): bool
    {
        $this->validateDriverName($driverName);
        $this->validateArchitecture($architecture);

        $rpcCmd = sprintf('deldriver "%s"', $driverName);
        $cmd = $this->buildRpcclientCommand($rpcCmd, $this->getServerName());

        $result = $this->commandRunner->run($cmd);
        if ($result['returnCode'] !== 0) {
            $this->throwForRpcclientFailure($cmd, $result, "suppression driver \"{$driverName}\"");
        }

        // deldriver OK → on peut unlink les fichiers locaux.
        foreach ($associatedFiles as $file) {
            if ($file === '' || $file === 'NULL') {
                continue;
            }
            try {
                $this->validateFileName($file);
            } catch (InvalidArgumentException $e) {
                Log::warning('PrintDriverService: fichier driver associé refusé pour suppression (regex)', [
                    'file' => $file,
                    'driver' => $driverName,
                ]);
                continue;
            }
            $safeName = basename($file);
            $path = self::DRIVERS_DIR_X64 . '/' . $safeName;
            $rmCmd = 'sudo /bin/rm ' . escapeshellarg($path);
            $rmResult = $this->runQuiet($rmCmd);
            if ($rmResult['returnCode'] !== 0) {
                Log::warning('PrintDriverService: suppression fichier driver échouée (non-bloquant)', [
                    'file' => $safeName,
                    'driver' => $driverName,
                    'returnCode' => $rmResult['returnCode'],
                    'stderr' => $rmResult['stderr'],
                ]);
            }
        }

        Log::info('PrintDriverService: driver supprimé', [
            'driver' => $driverName,
            'architecture' => $architecture,
            'files_count' => count($associatedFiles),
        ]);
        return true;
    }

    /**
     * Supprime des fichiers driver déposés dans `/var/lib/samba/printers/x64/`
     * sans toucher à Samba (`rpcclient deldriver`). Utilisé par le rollback
     * D9 quand un upload échoue après `copyDriverFile` mais avant
     * `registerDriver` — il faut nettoyer les fichiers orphelins.
     *
     * Chaque nom est re-validé via `validateFileName` (defense in depth).
     * Best-effort : log warning sur échec, ne lève pas.
     *
     * @param  string[]  $fileNames  Liste de noms de fichiers (sans path).
     * @return array{removed: string[], failed: string[]}
     */
    public function unlinkDriverFiles(array $fileNames): array
    {
        $removed = [];
        $failed = [];
        foreach ($fileNames as $file) {
            if (!is_string($file) || $file === '' || $file === 'NULL') {
                continue;
            }
            try {
                $this->validateFileName($file);
            } catch (InvalidArgumentException $e) {
                Log::warning('PrintDriverService: nom fichier rejeté pour unlink (regex)', [
                    'file' => $file,
                ]);
                $failed[] = $file;
                continue;
            }
            $safeName = basename($file);
            $path = self::DRIVERS_DIR_X64 . '/' . $safeName;
            $rmCmd = 'sudo /bin/rm ' . escapeshellarg($path);
            $rmResult = $this->runQuiet($rmCmd);
            if ($rmResult['returnCode'] !== 0) {
                Log::warning('PrintDriverService: unlink fichier driver échoué', [
                    'file' => $safeName,
                    'returnCode' => $rmResult['returnCode'],
                    'stderr' => $rmResult['stderr'],
                ]);
                $failed[] = $safeName;
            } else {
                $removed[] = $safeName;
            }
        }
        if (!empty($removed) || !empty($failed)) {
            Log::info('PrintDriverService: rollback fichiers orphelins', [
                'removed' => $removed,
                'failed' => $failed,
            ]);
        }
        return ['removed' => $removed, 'failed' => $failed];
    }

    // ========================================================================
    // INTERNAL HELPERS
    // ========================================================================

    /**
     * Run + log silencieux (debug). `LC_ALL=C` injecté par RealCommandRunner.
     *
     * @return array{stdout: string[], stderr: string[], returnCode: int}
     */
    private function runQuiet(string $command): array
    {
        Log::debug('PrintDriverService: exec quiet', ['command' => $command]);
        return $this->commandRunner->run($command);
    }

    /**
     * @param  array{stdout: string[], stderr: string[], returnCode: int}  $result
     */
    private function throwForRpcclientFailure(
        string $cmd,
        array $result,
        string $action,
        ?string $pivot = null,
    ): never {
        $stderr = $result['stderr'];
        if ($this->isKerberosFailure($stderr)) {
            Log::error('PrintDriverService: échec Kerberos sur ' . $action, [
                'command' => $cmd,
                'stderr' => $stderr,
                'returnCode' => $result['returnCode'],
            ]);
            throw new KerberosTicketException(
                'Authentification Samba expirée — contacter l\'admin système.',
            );
        }
        if ($pivot !== null && $this->isPivotUnreachable($stderr)) {
            Log::error('PrintDriverService: pivot W10 injoignable sur ' . $action, [
                'pivot' => $pivot,
                'command' => $cmd,
                'stderr' => $stderr,
                'returnCode' => $result['returnCode'],
            ]);
            throw new WindowsPivotUnreachableException(
                "Poste pivot {$pivot} injoignable — vérifier qu'il est allumé.",
                $cmd,
                $stderr,
                $result['returnCode'],
            );
        }
        if ($this->isSambaUnavailable($stderr, $result['returnCode'])) {
            Log::error('PrintDriverService: Samba injoignable sur ' . $action, [
                'command' => $cmd,
                'stderr' => $stderr,
                'returnCode' => $result['returnCode'],
            ]);
            throw new SambaUnavailableException(
                'Service Samba injoignable — synchronisation drivers indisponible.',
            );
        }

        Log::error('PrintDriverService: échec ' . $action, [
            'command' => $cmd,
            'stderr' => $stderr,
            'returnCode' => $result['returnCode'],
        ]);
        throw new PrintDriverException(
            "Échec {$action} : " . ($stderr[0] ?? 'erreur inconnue'),
            $cmd,
            $stderr,
            $result['returnCode'],
        );
    }

    /**
     * @param  array{stdout: string[], stderr: string[], returnCode: int}  $result
     */
    private function throwForSmbclientFailure(
        string $cmd,
        array $result,
        string $action,
        string $pivot,
    ): never {
        $stderr = $result['stderr'];
        if ($this->isKerberosFailure($stderr)) {
            Log::error('PrintDriverService: échec Kerberos sur ' . $action, [
                'pivot' => $pivot,
                'command' => $cmd,
                'stderr' => $stderr,
                'returnCode' => $result['returnCode'],
            ]);
            throw new KerberosTicketException(
                'Authentification Samba expirée — contacter l\'admin système.',
            );
        }
        if ($this->isPivotUnreachable($stderr)) {
            Log::error('PrintDriverService: pivot W10 injoignable sur ' . $action, [
                'pivot' => $pivot,
                'command' => $cmd,
                'stderr' => $stderr,
                'returnCode' => $result['returnCode'],
            ]);
            throw new WindowsPivotUnreachableException(
                "Poste pivot {$pivot} injoignable — vérifier qu'il est allumé.",
                $cmd,
                $stderr,
                $result['returnCode'],
            );
        }
        Log::error('PrintDriverService: échec ' . $action, [
            'command' => $cmd,
            'stderr' => $stderr,
            'returnCode' => $result['returnCode'],
        ]);
        throw new PrintDriverException(
            "Échec {$action} : " . ($stderr[0] ?? 'erreur inconnue'),
            $cmd,
            $stderr,
            $result['returnCode'],
        );
    }

    /**
     * @param  string[]  $stderrLines
     */
    private function isKerberosFailure(array $stderrLines): bool
    {
        $haystack = implode("\n", $stderrLines);
        foreach (self::KRB_ERROR_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param  string[]  $stderrLines
     */
    private function isPivotUnreachable(array $stderrLines): bool
    {
        $haystack = implode("\n", $stderrLines);
        foreach (self::PIVOT_UNREACHABLE_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Heuristique « Samba injoignable » : pas de réponse claire sur le
     * serveur cible (binding refused, daemon not running…). Plus
     * conservative que les markers Kerberos / pivot.
     *
     * @param  string[]  $stderrLines
     */
    private function isSambaUnavailable(array $stderrLines, int $returnCode): bool
    {
        // returnCode == 1 + stderr vide = échec rpcclient générique →
        // on reste sur PrintDriverException (pas une indisponibilité).
        if (empty($stderrLines)) {
            return false;
        }
        $haystack = implode("\n", $stderrLines);
        $sambaMarkers = [
            'NT_STATUS_PIPE_NOT_AVAILABLE',
            'NT_STATUS_LOGON_FAILURE',
            'Cannot connect to server',
            'rpcclient: connect failed',
        ];
        foreach ($sambaMarkers as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Heuristique « imprimante absente côté Samba » sur `getprinter`.
     *
     * @param  string[]  $stderrLines
     */
    private function stderrSuggestsPrinterMissing(array $stderrLines): bool
    {
        $haystack = implode("\n", $stderrLines);
        return str_contains($haystack, 'WERR_INVALID_PRINTER_NAME')
            || str_contains($haystack, 'WERR_BADFID')
            || str_contains($haystack, 'NT_STATUS_OBJECT_NAME_NOT_FOUND');
    }
}
