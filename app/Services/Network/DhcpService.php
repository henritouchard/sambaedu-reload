<?php

declare(strict_types=1);

namespace App\Services\Network;

use App\Models\DhcpReservation;
use App\Models\Workstation;
use App\Services\Network\Exceptions\DhcpCommandException;
use App\Services\Network\Exceptions\DhcpDaemonDownException;
use App\Services\Network\Exceptions\DhcpValidationException;
use App\Services\Print\Contracts\CommandRunner;
use App\Support\AtomicFileWriter;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Story 8.1 — Service métier de gestion DHCP (FR20 + FR22).
 *
 * Encapsule :
 *  - validations (MAC, IP, name) ;
 *  - CRUD sur la table `dhcp_reservations` (avec sync `reservations.inc` +
 *    reload service) ;
 *  - parsing `/var/lib/dhcp/dhcpd.leases` ;
 *  - statut du service `isc-dhcp-server` ;
 *  - import depuis le fichier legacy `reservations.inc` (T8b / AC9).
 *
 * Pattern shellout aligné `App\Services\Print\CupsPrinterService` (Story 6.1)
 * et `App\Services\Filesystem\XfsQuotaService` (Story 5.1a) :
 *  - `escapeshellarg()` systématique sur tout input user-controlled ;
 *  - capture stdout / stderr / returnCode → `DhcpCommandException` typée ;
 *  - préfixe logs `DhcpService:` (grep opérateurs), channel `network`.
 *
 * Concurrence : `Cache::lock('dhcp.reload', 30)` autour de la séquence
 * `exportReservationsFile + reloadService` pour neutraliser R2 (deux
 * mutations simultanées → fichier `reservations.inc` corrompu).
 *
 * Mode dégradé (AC6) : un échec de reload N'annule PAS la mutation DB —
 * `createReservation` capture `DhcpCommandException` après commit et la
 * propage à l'appelant qui choisit le toast (le service ne rollback pas la
 * réservation, elle reste persistée pour reload manuel ultérieur).
 */
class DhcpService
{
    /** Regex stricte du nom (= `cn` legacy). 1..63 chars, alphanum + `_-`. */
    public const NAME_REGEX = '/^[a-zA-Z0-9][a-zA-Z0-9_\-]{0,62}$/';

    /** Regex de la MAC NORMALISÉE (post-normalisation). */
    public const MAC_REGEX_NORMALIZED = '/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/';

    public function __construct(
        private readonly CommandRunner $commandRunner,
    ) {
    }

    // ========================================================================
    // VALIDATION (defense in depth — utilisable côté UI Livewire ET service)
    // ========================================================================

    /**
     * @throws DhcpValidationException si nom non conforme.
     */
    public function validateName(string $name): void
    {
        if (preg_match(self::NAME_REGEX, $name) !== 1) {
            Log::channel($this->logChannel())->warning('DhcpService: nom invalide', ['name' => $name]);
            throw new DhcpValidationException('Nom invalide : 1..63 caractères alphanumériques, `_` ou `-` autorisés (doit commencer par lettre/chiffre).');
        }
    }

    /**
     * Normalise une MAC vers `xx:xx:xx:xx:xx:xx` lowercase.
     *
     * Formats acceptés en entrée :
     *  - `xx:xx:xx:xx:xx:xx` (canonique)
     *  - `xx-xx-xx-xx-xx-xx`
     *  - `xxxxxxxxxxxx` (12 hex sans séparateur)
     *  - `xxxx.xxxx.xxxx` (notation Cisco)
     *
     * @throws DhcpValidationException si la MAC ne contient pas 12 hex
     */
    public function validateMac(string $mac): string
    {
        $clean = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $mac) ?? '');

        if (strlen($clean) !== 12) {
            Log::channel($this->logChannel())->warning('DhcpService: MAC invalide', ['mac' => $mac]);
            throw new DhcpValidationException('Format MAC invalide (12 caractères hexadécimaux attendus).');
        }

        $normalized = implode(':', str_split($clean, 2));

        if (preg_match(self::MAC_REGEX_NORMALIZED, $normalized) !== 1) {
            // Devrait être impossible après le strlen=12, mais defense in depth.
            throw new DhcpValidationException('Format MAC invalide après normalisation.');
        }

        return $normalized;
    }

    /**
     * @throws DhcpValidationException si IP non IPv4 valide.
     */
    public function validateIp(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            Log::channel($this->logChannel())->warning('DhcpService: IP invalide', ['ip' => $ip]);
            throw new DhcpValidationException('Format IP invalide (IPv4 attendu, ex. 10.0.0.50).');
        }
    }

    // ========================================================================
    // CRUD réservation (mutation = lock + DB + export fichier + reload)
    // ========================================================================

    /**
     * @param  array{name:string,mac:string,ip:string,workstation_id?:int|null,description?:string|null,source?:string}  $attrs
     */
    public function createReservation(array $attrs): DhcpReservation
    {
        $payload = $this->normalizeAttrs($attrs, isUpdate: false);

        $reservation = DB::transaction(function () use ($payload) {
            return DhcpReservation::create($payload);
        });

        $this->reloadAfterMutation('création', $reservation);

        return $reservation;
    }

    /**
     * @param  array{name?:string,mac?:string,ip?:string,workstation_id?:int|null,description?:string|null}  $attrs
     */
    public function updateReservation(DhcpReservation $reservation, array $attrs): DhcpReservation
    {
        $payload = $this->normalizeAttrs($attrs, isUpdate: true, current: $reservation);

        DB::transaction(function () use ($reservation, $payload) {
            $reservation->update($payload);
        });

        $reservation->refresh();
        $this->reloadAfterMutation('modification', $reservation);

        return $reservation;
    }

    public function deleteReservation(DhcpReservation $reservation): void
    {
        $id = $reservation->id;
        DB::transaction(function () use ($reservation) {
            $reservation->delete();
        });

        $this->reloadAfterMutation('suppression', null, ['id' => $id]);
    }

    /**
     * Normalisation + validation + détection des doublons SQL « préventive »
     * (ne remplace pas les contraintes UNIQUE — defense in depth + message
     * d'erreur ciblé).
     *
     * @param  array<string,mixed>  $attrs
     * @return array<string,mixed>
     */
    private function normalizeAttrs(array $attrs, bool $isUpdate, ?DhcpReservation $current = null): array
    {
        $name = isset($attrs['name']) ? trim((string) $attrs['name']) : ($current->name ?? '');
        $mac = isset($attrs['mac']) ? trim((string) $attrs['mac']) : ($current->mac ?? '');
        $ip = isset($attrs['ip']) ? trim((string) $attrs['ip']) : ($current->ip ?? '');

        $this->validateName($name);
        $mac = $this->validateMac($mac);
        $this->validateIp($ip);

        // Doublon préventif (message ciblé). Le SGBD fera de toute façon
        // respecter l'unicité.
        $this->assertNoDuplicate('name', $name, $current?->id);
        $this->assertNoDuplicate('mac', $mac, $current?->id);
        $this->assertNoDuplicate('ip', $ip, $current?->id);

        $source = $attrs['source'] ?? ($current->source ?? DhcpReservation::SOURCE_MANUAL);
        if (!in_array($source, DhcpReservation::SOURCES, true)) {
            $source = DhcpReservation::SOURCE_MANUAL;
        }

        $payload = [
            'name' => $name,
            'mac' => $mac,
            'ip' => $ip,
            'description' => array_key_exists('description', $attrs)
                ? ($attrs['description'] !== null ? trim((string) $attrs['description']) : null)
                : ($current->description ?? null),
            'workstation_id' => array_key_exists('workstation_id', $attrs)
                ? ($attrs['workstation_id'] !== null && $attrs['workstation_id'] !== '' ? (int) $attrs['workstation_id'] : null)
                : ($current->workstation_id ?? null),
            'source' => $source,
        ];

        return $payload;
    }

    /**
     * @throws DhcpValidationException si la valeur existe déjà sur une autre ligne.
     */
    private function assertNoDuplicate(string $column, string $value, ?int $excludeId): void
    {
        $query = DhcpReservation::query()->where($column, $value);
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }
        $existing = $query->first(['id', 'name']);
        if ($existing !== null) {
            $label = match ($column) {
                'name' => 'Ce nom est déjà utilisé',
                'mac' => 'Cette MAC est déjà réservée pour la machine ' . $existing->name,
                'ip' => 'Cette IP est déjà réservée pour la machine ' . $existing->name,
                default => 'Doublon détecté sur ' . $column,
            };
            throw new DhcpValidationException($label);
        }
    }

    /**
     * Pipeline post-mutation : export atomique du fichier conf + reload.
     * Capture l'éventuel `DhcpCommandException` et le rapropage à l'appelant
     * (UI Livewire) — la mutation DB n'est PAS rollbackée (AC6 : ne jamais
     * perdre une réservation).
     *
     * @param  array<string,mixed>  $extraLogCtx
     */
    private function reloadAfterMutation(string $action, ?DhcpReservation $reservation, array $extraLogCtx = []): void
    {
        $lock = Cache::lock($this->reloadLockKey(), 30);

        // `block($timeout)` lève `LockTimeoutException` après expiration —
        // il ne retourne jamais `false`. On wrappe pour remonter une
        // exception métier claire (cf. review code 8.1 #1).
        try {
            $lock->block(15);
        } catch (LockTimeoutException $e) {
            throw new DhcpCommandException(
                'Verrou DHCP toujours détenu après 15s — opération concurrente en cours.',
                $this->reloadLockKey(),
                [],
                -1,
                $e,
            );
        }

        try {
            $this->exportReservationsFile();
            $this->reloadService();
            $ctx = array_merge([
                'action' => $action,
                'name' => $reservation?->name,
                'mac' => $reservation?->mac,
                'ip' => $reservation?->ip,
            ], $extraLogCtx);
            Log::channel($this->logChannel())->info('DhcpService: ' . $action . ' OK', $ctx);
        } finally {
            optional($lock)->release();
        }
    }

    // ========================================================================
    // EXPORT / RELOAD
    // ========================================================================

    /**
     * Génère et écrit atomiquement `/etc/sambaedu/reservations.inc` à partir
     * de l'ensemble des réservations en base. Ordre stable (par `name`) pour
     * faciliter le diff manuel et un éventuel suivi git.
     */
    public function exportReservationsFile(): void
    {
        $path = (string) config('sambaedu.dhcp.reservations_file', '/etc/sambaedu/reservations.inc');
        $content = $this->renderReservationsFile(DhcpReservation::query()->orderBy('name')->get());

        $ok = AtomicFileWriter::write($path, $content);
        if (!$ok) {
            Log::channel($this->logChannel())->error('DhcpService: échec écriture reservations.inc', [
                'path' => $path,
            ]);
            throw new DhcpCommandException(
                "Impossible d'écrire le fichier des réservations DHCP ({$path}).",
                'AtomicFileWriter::write',
                ['écriture refusée — vérifier les droits sur ' . $path],
                -1,
            );
        }
    }

    /**
     * Rend le contenu du fichier `reservations.inc` (sans I/O, pure).
     * Utilisable pour les tests « snapshot » de génération.
     *
     * @param  EloquentCollection<int, DhcpReservation>|iterable<DhcpReservation>  $reservations
     */
    public function renderReservationsFile(iterable $reservations): string
    {
        $lines = [];
        $lines[] = '# /etc/sambaedu/reservations.inc';
        $lines[] = '# Fichier généré automatiquement par SambaEdu-Reload (Story 8.1).';
        $lines[] = '# NE PAS éditer manuellement — toute modification sera écrasée au prochain';
        $lines[] = '# reload (mutation depuis /app/network/dhcp).';
        $lines[] = '# Source de vérité : table SQL dhcp_reservations.';
        $lines[] = '';

        foreach ($reservations as $r) {
            // Defensive : on n'écrit que des lignes dont MAC + IP + name sont
            // au format normalisé (la validation upstream l'assure déjà).
            $name = (string) $r->name;
            $mac = (string) $r->mac;
            $ip = (string) $r->ip;
            $lines[] = sprintf('host %s { hardware ethernet %s; fixed-address %s; }', $name, $mac, $ip);
        }
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Exécute le script de régénération + reload du service DHCP.
     *
     * @throws DhcpCommandException si le script retourne != 0.
     */
    public function reloadService(): void
    {
        $script = (string) config('sambaedu.dhcp.reload_command', '/usr/share/sambaedu/sbin/make_dhcpd_conf.sh');
        $command = 'sudo ' . escapeshellarg($script);

        Log::channel($this->logChannel())->debug('DhcpService: reload service', ['command' => $command]);
        $result = $this->commandRunner->run($command);

        if ($result['returnCode'] !== 0) {
            Log::channel($this->logChannel())->error('DhcpService: reload service échoué', [
                'command' => $command,
                'stderr' => $result['stderr'],
                'returnCode' => $result['returnCode'],
            ]);
            throw new DhcpCommandException(
                "Le service DHCP n'a pas pu être rechargé : " . ($result['stderr'][0] ?? 'erreur inconnue'),
                $command,
                $result['stderr'],
                $result['returnCode'],
            );
        }
    }

    /**
     * Retourne l'état du service `isc-dhcp-server.service` via `systemctl
     * is-active`.
     *
     * Best-effort : ne lève jamais — l'appelant utilise `active=false` pour
     * afficher la bannière de mode dégradé (AC6).
     *
     * @return array{active:bool, details:string}
     */
    public function serviceStatus(): array
    {
        // Cache 15s pour éviter de spammer `sudo systemctl is-active` à
        // chaque cycle Livewire (`rendering()` est appelé sur tout update —
        // recherche debounced, ouverture modale, etc.). Cf. review code 8.1 #4.
        return Cache::remember('dhcp.service_status', 15, function (): array {
            $service = (string) config('sambaedu.dhcp.service_name', 'isc-dhcp-server.service');
            $command = 'sudo systemctl is-active ' . escapeshellarg($service);

            $result = $this->commandRunner->run($command);
            $stdoutFirst = trim($result['stdout'][0] ?? '');
            $active = $result['returnCode'] === 0 && $stdoutFirst === 'active';

            return [
                'active' => $active,
                'details' => $active ? 'Service actif' : ($stdoutFirst !== '' ? $stdoutFirst : 'Service injoignable'),
            ];
        });
    }

    /**
     * Lève `DhcpDaemonDownException` si le service est inactif. Utile pour
     * basculer rapidement en mode dégradé sans tenter le reload.
     *
     * @throws DhcpDaemonDownException
     */
    public function assertServiceUp(): void
    {
        $status = $this->serviceStatus();
        if (!$status['active']) {
            throw new DhcpDaemonDownException(
                "Le service DHCP est inactif (systemctl is-active : {$status['details']})."
            );
        }
    }

    // ========================================================================
    // PARSING LEASES
    // ========================================================================

    /**
     * Parse `/var/lib/dhcp/dhcpd.leases` (format ISC DHCP standard).
     *
     * Filtres reproduits du legacy (`ldap.inc.php:4987 import_dhcp_leases`) :
     *  - binding state ∈ {`active`, `free`} uniquement ;
     *  - déduplication : on garde le DERNIER bloc rencontré par IP ;
     *  - exclusion : on retire les baux dont l'IP est déjà réservée (pour
     *    éviter d'afficher comme « bail dynamique » une IP de réservation —
     *    cf. `list_dhcp_leases` legacy ldap.inc.php:5044).
     *
     * Tolérance : si le fichier est introuvable / illisible, retourne une
     * collection vide (l'UI affiche « Lecture des baux indisponible »
     * — AC6 mode dégradé). Aucune exception n'est levée pour ne pas
     * empêcher l'affichage de la page.
     *
     * @return Collection<int,array{ip:string,mac:string,hostname:?string,state:string,ends_at:?string}>
     */
    public function listActiveLeases(): Collection
    {
        $path = (string) config('sambaedu.dhcp.leases_file', '/var/lib/dhcp/dhcpd.leases');

        if (!is_file($path) || !is_readable($path)) {
            Log::channel($this->logChannel())->info('DhcpService: leases file inaccessible', ['path' => $path]);
            return collect();
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            Log::channel($this->logChannel())->warning('DhcpService: leases file unreadable', ['path' => $path]);
            return collect();
        }

        $leases = $this->parseLeasesContent($content);

        // Exclusion des baux qui matchent une réservation existante.
        $reservedIps = DhcpReservation::query()->pluck('ip')->all();
        $reservedSet = array_flip($reservedIps);

        return collect($leases)
            ->reject(fn (array $l) => isset($reservedSet[$l['ip']]))
            ->values();
    }

    /**
     * Parsing pur du contenu d'un fichier `dhcpd.leases`. Exposé public pour
     * faciliter le test snapshot avec fixture.
     *
     * @return array<int,array{ip:string,mac:string,hostname:?string,state:string,ends_at:?string}>
     */
    public function parseLeasesContent(string $content): array
    {
        $blocks = [];
        $offset = 0;
        $length = strlen($content);

        while ($offset < $length) {
            // Cherche `lease X.X.X.X {`
            if (!preg_match('/lease\s+([\d.]+)\s*\{/', $content, $m, PREG_OFFSET_CAPTURE, $offset)) {
                break;
            }
            $ip = $m[1][0];
            $openBracePos = $m[0][1] + strlen($m[0][0]) - 1; // position du `{`

            // Review code 8.1 #10 — scan caractère par caractère avec compteur
            // d'imbrication pour trouver la `}` fermante au niveau 0. Le simple
            // `strpos('}', ...)` casse dès qu'une option ISC dans le body
            // contient des accolades (rare mais possible : option blocks).
            $depth = 0;
            $closeBracePos = false;
            for ($i = $openBracePos; $i < $length; $i++) {
                $c = $content[$i];
                if ($c === '{') {
                    $depth++;
                } elseif ($c === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $closeBracePos = $i;
                        break;
                    }
                }
            }
            if ($closeBracePos === false) {
                break;
            }
            $body = substr($content, $openBracePos + 1, $closeBracePos - $openBracePos - 1);
            $offset = $closeBracePos + 1;

            $state = null;
            if (preg_match('/binding\s+state\s+(\w+)/', $body, $sm)) {
                $state = strtolower($sm[1]);
            }
            if (!in_array($state, ['active', 'free'], true)) {
                continue;
            }

            $mac = null;
            if (preg_match('/hardware\s+ethernet\s+([0-9a-fA-F:]+)\s*;/', $body, $hm)) {
                try {
                    $mac = $this->validateMac($hm[1]);
                } catch (DhcpValidationException $e) {
                    $mac = null;
                }
            }
            if ($mac === null) {
                continue;
            }

            $hostname = null;
            if (preg_match('/client-hostname\s+"([^"]*)"\s*;/', $body, $cm)) {
                $hostname = $cm[1] !== '' ? $cm[1] : null;
            }

            $endsAt = null;
            if (preg_match('/ends\s+\d+\s+([\d\/]+\s+[\d:]+)\s*;/', $body, $em)) {
                $endsAt = $em[1];
            }

            // Dédup : on conserve le dernier (overwrite).
            $blocks[$ip] = [
                'ip' => $ip,
                'mac' => $mac,
                'hostname' => $hostname,
                'state' => $state,
                'ends_at' => $endsAt,
            ];
        }

        return array_values($blocks);
    }

    // ========================================================================
    // MIGRATION LEGACY — T8b / AC9
    // ========================================================================

    /**
     * Story 8.1 / T8b — Importe le fichier legacy `/etc/sambaedu/reservations.inc`
     * dans la table `dhcp_reservations` (greffé sur l'étape 10 de `/sync-from-ad`).
     *
     * Sémantique :
     *  - lecture seule du fichier (aucun reload DHCP déclenché) ;
     *  - upsert par MAC en priorité, sinon par name ;
     *  - `source='legacy-migration'` uniquement à la création ; les mises à
     *    jour préservent la source d'origine (AC9 — ne pas écraser
     *    `manual`/`import` plus spécifiques) ;
     *  - tolérance : commentaires `#` / `//`, lignes vides, blocs mal formés
     *    sont ignorés / comptabilisés en erreurs sans avorter l'import ;
     *  - lien `workstation_id` : `Workstation::where('name', $cn)->first()`
     *    si présent, sinon NULL.
     *
     * @param  callable(string $level, string $message): void  $logger
     * @return array{parsed:int,created:int,updated:int,skipped:int,errors:array<int,array{line:int,reason:string}>}
     */
    public function importFromLegacyFile(string $path, callable $logger): array
    {
        if (!is_file($path)) {
            $logger('error', "Fichier introuvable : {$path}");
            throw new \RuntimeException("Fichier de réservations legacy introuvable : {$path}");
        }
        if (!is_readable($path)) {
            $logger('error', "Fichier illisible (vérifier les droits www-data) : {$path}");
            throw new \RuntimeException("Fichier de réservations legacy illisible : {$path}");
        }

        $content = (string) file_get_contents($path);
        $logger('info', "Lecture de {$path} (" . strlen($content) . " octets)");

        $stats = ['parsed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        // Préfiltrage : retrait des commentaires (`#…` et `//…`) en début de
        // ligne. On garde la numérotation d'origine via marqueurs.
        $rawLines = preg_split('/\r?\n/', $content) ?: [];
        $cleanLines = [];
        foreach ($rawLines as $idx => $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '//')) {
                continue;
            }
            $cleanLines[$idx + 1] = $line;
        }

        // On reconstruit un buffer global mais on garde un mapping
        // « offset char → ligne d'origine » pour les messages d'erreur.
        $buffer = '';
        $lineMap = [];   // start offset (inclusive) → line number
        foreach ($cleanLines as $lineNo => $line) {
            $lineMap[strlen($buffer)] = $lineNo;
            $buffer .= $line . "\n";
        }

        // Regex tolérante aux espaces / tabs / sauts de ligne entre tokens.
        $pattern = '/host\s+(\S+)\s*\{\s*hardware\s+ethernet\s+([0-9a-fA-F:]+)\s*;\s*fixed-address\s+([0-9.]+)\s*;\s*\}/m';

        if (preg_match_all($pattern, $buffer, $matches, PREG_OFFSET_CAPTURE) === false || empty($matches[0])) {
            $logger('info', 'Aucun bloc `host {…}` valide trouvé');
        }

        // AC9 / review code 8.1 #5 : second pass pour détecter les `host <cn> {`
        // ORPHELINS (sans bloc complet correspondant — accolade fermante
        // manquante, point-virgule oublié, etc.) afin de les pousser en
        // `errors[]` plutôt que de les skipper silencieusement.
        $consumedHostStarts = [];
        foreach (($matches[0] ?? []) as $m) {
            // Position du `host` (début du match complet) → marqueur consommé.
            $consumedHostStarts[$m[1]] = true;
        }
        if (preg_match_all('/host\s+(\S+)\s*\{/', $buffer, $hostStarts, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($hostStarts[0] as $i => $hostMatch) {
                $offset = $hostMatch[1];
                if (isset($consumedHostStarts[$offset])) {
                    continue;
                }
                $name = $hostStarts[1][$i][0] ?? '?';
                $approxLine = $this->resolveLineFromOffset($lineMap, $offset);
                $stats['errors'][] = [
                    'line' => $approxLine,
                    'reason' => "Bloc `host {$name}` malformé : accolade fermante manquante ou syntaxe invalide.",
                ];
            }
        }

        $seenMacsThisRun = [];
        $matchCount = count($matches[0] ?? []);
        for ($i = 0; $i < $matchCount; $i++) {
            $stats['parsed']++;
            $matchOffset = $matches[0][$i][1];
            $approxLine = $this->resolveLineFromOffset($lineMap, $matchOffset);

            $name = $matches[1][$i][0];
            $rawMac = $matches[2][$i][0];
            $ip = $matches[3][$i][0];

            try {
                $this->validateName($name);
            } catch (DhcpValidationException $e) {
                $stats['errors'][] = ['line' => $approxLine, 'reason' => "Nom invalide '{$name}' : " . $e->getMessage()];
                continue;
            }

            try {
                $mac = $this->validateMac($rawMac);
            } catch (DhcpValidationException $e) {
                $stats['errors'][] = ['line' => $approxLine, 'reason' => "MAC invalide '{$rawMac}' : " . $e->getMessage()];
                continue;
            }

            try {
                $this->validateIp($ip);
            } catch (DhcpValidationException $e) {
                $stats['errors'][] = ['line' => $approxLine, 'reason' => "IP invalide '{$ip}' : " . $e->getMessage()];
                continue;
            }

            // Dédup intra-fichier (deux blocs avec la même MAC → on garde le
            // premier, on signale les suivants comme `skipped`).
            if (isset($seenMacsThisRun[$mac])) {
                $stats['skipped']++;
                $logger('warning', "Ligne {$approxLine} : MAC dupliquée dans le fichier ({$mac}) — ignorée");
                continue;
            }
            $seenMacsThisRun[$mac] = true;

            // Lookup workstation par name (`cn` legacy).
            $workstationId = Workstation::query()->where('name', $name)->value('id');

            // Upsert : recherche séquentielle déterministe — MAC d'abord
            // (clé physique stable), puis name (cn legacy unique). Pas d'IP
            // ici : l'unicité legacy est MAC + name. Cf. review code 8.1 #3.
            $existing = DhcpReservation::query()->where('mac', $mac)->first()
                ?? DhcpReservation::query()->where('name', $name)->first();

            try {
                if ($existing === null) {
                    DhcpReservation::create([
                        'name' => $name,
                        'mac' => $mac,
                        'ip' => $ip,
                        'workstation_id' => $workstationId !== null ? (int) $workstationId : null,
                        'description' => null,
                        'source' => DhcpReservation::SOURCE_LEGACY_MIGRATION,
                    ]);
                    $stats['created']++;
                } else {
                    // Préservation de la source d'origine (AC9).
                    $existing->fill([
                        'name' => $name,
                        'mac' => $mac,
                        'ip' => $ip,
                        'workstation_id' => $workstationId !== null
                            ? (int) $workstationId
                            : $existing->workstation_id,
                    ])->save();
                    $stats['updated']++;
                }
            } catch (\Throwable $e) {
                $stats['errors'][] = [
                    'line' => $approxLine,
                    'reason' => "Erreur SQL upsert ({$name}) : " . $e->getMessage(),
                ];
            }
        }

        $logger('info', "Bilan : parsed={$stats['parsed']}, created={$stats['created']}, updated={$stats['updated']}, skipped={$stats['skipped']}, errors=" . count($stats['errors']));

        return $stats;
    }

    /**
     * @param  array<int,int>  $lineMap
     */
    private function resolveLineFromOffset(array $lineMap, int $offset): int
    {
        $line = 0;
        foreach ($lineMap as $start => $lineNo) {
            if ($start > $offset) {
                break;
            }
            $line = $lineNo;
        }
        return $line ?: 1;
    }

    // ========================================================================
    // HELPERS internes
    // ========================================================================

    private function logChannel(): string
    {
        // Channel `network` dédié (Story 8.1 T4). On laisse Laravel se
        // rabattre sur le default si le channel n'existe pas (cohérent avec
        // les tests SQLite qui n'ont pas forcément config/logging à jour).
        return config('logging.channels.network') !== null ? 'network' : config('logging.default', 'single');
    }

    private function reloadLockKey(): string
    {
        return 'dhcp.reload';
    }
}
