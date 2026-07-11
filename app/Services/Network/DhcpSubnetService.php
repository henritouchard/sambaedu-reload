<?php

declare(strict_types=1);

namespace App\Services\Network;

use App\Config\SambaEduConfig;
use App\Models\DhcpReservation;
use App\Models\DhcpSubnet;
use App\Services\Network\Exceptions\DhcpCommandException;
use App\Services\Network\Exceptions\DhcpValidationException;
use App\Support\AtomicFileWriter;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Story 8.3 — Service métier de gestion des sous-réseaux DHCP (VLAN).
 *
 * Encapsule :
 *  - validations pures et testables (CIDR, n° VLAN 1..999 + unicité,
 *    passerelle/plages ⊂ réseau, begin ≤ end, non-chevauchement inter-VLAN ET
 *    vs sous-réseau par défaut, aucune plage ne recouvre l'IP d'une
 *    réservation existante) ;
 *  - CRUD sur la table `dhcp_subnets` (mutation SQL → export fichier de params
 *    → reload service) ;
 *  - rendu pur du fichier `dhcp-subnets.conf` (snapshotable) ;
 *  - lecture seule du sous-réseau par défaut (via `SambaEduConfig`).
 *
 * Réutilisation OBLIGATOIRE (story 8.1, ne rien réinventer) :
 *  - `DhcpService::reloadService()` : LE reload (shellout sudo `make_dhcpd_conf.sh`) ;
 *  - `Cache::lock('dhcp.reload', 30)->block(15)` : MÊME clé que 8.1 pour
 *    sérialiser sous-réseaux ET réservations sur le même verrou ;
 *  - `AtomicFileWriter` : écriture atomique du fichier de params ;
 *  - `DhcpValidationException` / `DhcpCommandException` : mêmes types de toasts.
 *
 * Mode dégradé (AC5, pattern AC6 de la 8.1) : un échec de reload N'annule PAS
 * la mutation SQL ni l'export fichier — `DhcpCommandException` remonte à l'UI
 * qui affiche un toast warning invitant au reload manuel.
 */
class DhcpSubnetService
{
    /** Clé du lock applicatif — MÊME que `DhcpService` (sérialise subnets+réservations). */
    private const RELOAD_LOCK_KEY = 'dhcp.reload';

    public function __construct(
        private readonly DhcpService $dhcpService,
        private readonly SambaEduConfig $config,
    ) {
    }

    // ========================================================================
    // VALIDATIONS PURES (utilisables côté UI Livewire ET service)
    // ========================================================================

    /**
     * Valide + décompose un CIDR IPv4 (`A.B.C.D/P`).
     *
     * @return array{network:string, prefix:int, base:int, mask:int, broadcast:int}
     *         `network` = adresse réseau normalisée (base/prefix, host bits à zéro).
     *
     * @throws DhcpValidationException
     */
    public function validateCidr(string $cidr): array
    {
        $cidr = trim($cidr);
        if (!str_contains($cidr, '/')) {
            throw new DhcpValidationException('Réseau invalide : notation CIDR attendue (ex. 192.168.20.0/24).');
        }

        [$ip, $prefixRaw] = explode('/', $cidr, 2);
        $ip = trim($ip);
        $prefixRaw = trim($prefixRaw);

        if ($prefixRaw === '' || !ctype_digit($prefixRaw)) {
            throw new DhcpValidationException('Préfixe CIDR invalide (attendu : entier de 1 à 32).');
        }
        $prefix = (int) $prefixRaw;
        if ($prefix < 1 || $prefix > 32) {
            throw new DhcpValidationException('Préfixe CIDR hors bornes (1..32).');
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new DhcpValidationException("Adresse réseau invalide (IPv4 attendu, ex. 192.168.20.0).");
        }

        $mask = $this->maskFromPrefix($prefix);
        $ipLong = $this->ipToLong($ip);
        $base = $ipLong & $mask;
        $broadcast = $base | (~$mask & 0xFFFFFFFF);

        // Normalisation : on stocke la base (host bits à zéro) — évite les
        // ambiguïtés (192.168.20.5/24 → 192.168.20.0/24).
        $network = long2ip($base) . '/' . $prefix;

        return [
            'network' => $network,
            'prefix' => $prefix,
            'base' => $base,
            'mask' => $mask,
            'broadcast' => $broadcast,
        ];
    }

    /**
     * Valide le n° de VLAN (1..999) et son unicité.
     *
     * @throws DhcpValidationException
     */
    public function validateVlanId(int $vlanId, ?int $excludeId = null): void
    {
        if ($vlanId < DhcpSubnet::VLAN_MIN || $vlanId > DhcpSubnet::VLAN_MAX) {
            throw new DhcpValidationException(
                sprintf('N° de VLAN hors bornes (%d..%d).', DhcpSubnet::VLAN_MIN, DhcpSubnet::VLAN_MAX)
            );
        }

        $query = DhcpSubnet::query()->where('vlan_id', $vlanId);
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }
        if ($query->exists()) {
            throw new DhcpValidationException("Le VLAN {$vlanId} est déjà défini.");
        }
    }

    /**
     * Valide une IPv4 (passerelle, borne de plage).
     *
     * @throws DhcpValidationException
     */
    public function validateIpv4(string $ip, string $label): string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new DhcpValidationException("{$label} invalide (IPv4 attendu).");
        }
        return $ip;
    }

    /**
     * Valide `extra_option` : chemin absolu strict (liste blanche).
     *
     * SÉCURITÉ (review 8.3 #1) — la valeur est rendue telle quelle dans
     * `dhcp-subnets.conf` (`dhcp_extra_option_N = "<valeur>"`), fichier ensuite
     * sourcé sur la VM par `config.inc.sh::get_config()` qui réinjecte la valeur
     * entre apostrophes simples avant un `eval` exécuté en root (sudo
     * `make_dhcpd_conf.sh`). Un simple filtre « pas d'espace / pas de guillemet
     * double » laisse passer l'apostrophe simple ET l'expansion par accolades
     * bash (`{touch,/x}` — deux mots sans aucun espace) → RCE root. On impose
     * donc une liste blanche stricte de chemin absolu, seule protection correcte
     * (aucune valeur INI avec espace de toute façon, cf. D5).
     *
     * @throws DhcpValidationException
     */
    public function validateExtraOption(?string $extraOption): ?string
    {
        if ($extraOption === null) {
            return null;
        }
        $extraOption = trim($extraOption);
        if ($extraOption === '') {
            return null;
        }
        // Liste blanche : chemin absolu, caractères de chemin sûrs uniquement.
        // Rejette espaces, apostrophes ('), guillemets ("), `;`, `{`, `}`,
        // backticks, `$`, `&`, `|`, etc. — tout ce qui pourrait rompre
        // l'échappement du parseur shell côté VM.
        if (preg_match('#^/[A-Za-z0-9._/-]+$#', $extraOption) !== 1) {
            throw new DhcpValidationException(
                "Le fichier d'option supplémentaire doit être un chemin absolu "
                . "sans espace ni caractère spécial (ex. /etc/dhcp/vlan20.conf)."
            );
        }
        return $extraOption;
    }

    // ========================================================================
    // NORMALISATION + VALIDATION COMPOSITE
    // ========================================================================

    /**
     * Normalise + valide l'ensemble des attributs d'un sous-réseau et retourne
     * le payload prêt à persister. Aucune écriture ici (defense in depth +
     * message d'erreur ciblé avant les contraintes SGBD).
     *
     * @param  array<string,mixed>  $attrs
     * @return array{vlan_id:int,network:string,gateway:string,ranges:array<int,array{begin:string,end:string}>,extra_option:?string,description:?string}
     *
     * @throws DhcpValidationException
     */
    public function normalizeAndValidate(array $attrs, ?DhcpSubnet $current = null): array
    {
        $vlanId = (int) ($attrs['vlan_id'] ?? $current->vlan_id ?? 0);
        $this->validateVlanId($vlanId, $current?->id);

        $cidrInput = isset($attrs['network']) ? (string) $attrs['network'] : (string) ($current->network ?? '');
        $cidr = $this->validateCidr($cidrInput);

        $gatewayInput = isset($attrs['gateway']) ? (string) $attrs['gateway'] : (string) ($current->gateway ?? '');
        $gateway = $this->validateIpv4($gatewayInput, 'Passerelle');
        if (!$this->ipInNetwork($this->ipToLong($gateway), $cidr['base'], $cidr['mask'])) {
            throw new DhcpValidationException("La passerelle {$gateway} n'appartient pas au réseau {$cidr['network']}.");
        }

        $rawRanges = $attrs['ranges'] ?? ($current?->ranges ?? []);
        $ranges = $this->normalizeRanges($rawRanges, $cidr);

        $extraOption = $this->validateExtraOption(
            array_key_exists('extra_option', $attrs)
                ? ($attrs['extra_option'] !== null ? (string) $attrs['extra_option'] : null)
                : ($current->extra_option ?? null)
        );

        $description = array_key_exists('description', $attrs)
            ? ($attrs['description'] !== null && trim((string) $attrs['description']) !== '' ? trim((string) $attrs['description']) : null)
            : ($current->description ?? null);

        // Chevauchements : inter-VLAN + vs sous-réseau par défaut.
        $this->assertNoSubnetOverlap($cidr, $current?->id);

        // Aucune plage ne doit recouvrir l'IP d'une réservation DHCP existante.
        $this->assertNoRangeCoversReservation($ranges);

        return [
            'vlan_id' => $vlanId,
            'network' => $cidr['network'],
            'gateway' => $gateway,
            'ranges' => $ranges,
            'extra_option' => $extraOption,
            'description' => $description,
        ];
    }

    /**
     * Normalise + valide les plages dynamiques (min 1, begin ≤ end, ⊂ réseau).
     *
     * @param  mixed  $rawRanges
     * @param  array{network:string,base:int,mask:int,broadcast:int}  $cidr
     * @return array<int,array{begin:string,end:string}>
     *
     * @throws DhcpValidationException
     */
    private function normalizeRanges(mixed $rawRanges, array $cidr): array
    {
        if (!is_array($rawRanges)) {
            $rawRanges = [];
        }

        $ranges = [];
        foreach ($rawRanges as $range) {
            if (!is_array($range)) {
                continue;
            }
            $begin = trim((string) ($range['begin'] ?? ''));
            $end = trim((string) ($range['end'] ?? ''));
            // Ligne entièrement vide → ignorée (l'UI ajoute des lignes vides).
            if ($begin === '' && $end === '') {
                continue;
            }

            $begin = $this->validateIpv4($begin, 'Début de plage');
            $end = $this->validateIpv4($end, 'Fin de plage');

            $beginLong = $this->ipToLong($begin);
            $endLong = $this->ipToLong($end);

            if (!$this->ipInNetwork($beginLong, $cidr['base'], $cidr['mask'])) {
                throw new DhcpValidationException("Le début de plage {$begin} n'appartient pas au réseau {$cidr['network']}.");
            }
            if (!$this->ipInNetwork($endLong, $cidr['base'], $cidr['mask'])) {
                throw new DhcpValidationException("La fin de plage {$end} n'appartient pas au réseau {$cidr['network']}.");
            }
            if ($beginLong > $endLong) {
                throw new DhcpValidationException("Plage invalide : le début {$begin} est supérieur à la fin {$end}.");
            }

            $ranges[] = ['begin' => $begin, 'end' => $end];
        }

        if ($ranges === []) {
            throw new DhcpValidationException('Au moins une plage dynamique valide est requise.');
        }

        return array_values($ranges);
    }

    /**
     * Vérifie qu'aucun autre sous-réseau (VLAN géré OU sous-réseau par défaut)
     * ne chevauche l'espace d'adressage du réseau candidat.
     *
     * @param  array{network:string,base:int,broadcast:int}  $cidr
     *
     * @throws DhcpValidationException
     */
    private function assertNoSubnetOverlap(array $cidr, ?int $excludeId): void
    {
        $aStart = $cidr['base'];
        $aEnd = $cidr['broadcast'];

        // Autres VLAN gérés.
        $others = DhcpSubnet::query()
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get(['id', 'vlan_id', 'network']);

        foreach ($others as $other) {
            try {
                $o = $this->cidrParts((string) $other->network);
            } catch (DhcpValidationException) {
                // Ligne corrompue en base (ne devrait jamais arriver — seul écrivain).
                // On l'ignore pour ne pas bloquer la mutation, MAIS on trace :
                // sinon un VLAN au réseau illisible échapperait silencieusement au
                // contrôle de chevauchement (review 8.3 #6).
                Log::channel($this->logChannel())->warning(
                    'DhcpSubnetService: réseau illisible ignoré au contrôle de chevauchement',
                    ['id' => $other->id, 'vlan_id' => $other->vlan_id, 'network' => $other->network]
                );
                continue;
            }
            if ($aStart <= $o['broadcast'] && $o['base'] <= $aEnd) {
                throw new DhcpValidationException(
                    "Le réseau {$cidr['network']} chevauche celui du VLAN {$other->vlan_id} ({$other->network})."
                );
            }
        }

        // Sous-réseau par défaut (lu depuis SambaEduConfig).
        $default = $this->defaultSubnet();
        if ($default['network'] !== '' && $default['netmask'] !== ''
            && filter_var($default['network'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && filter_var($default['netmask'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        ) {
            $mask = $this->ipToLong($default['netmask']);
            $base = $this->ipToLong($default['network']) & $mask;
            $broadcast = $base | (~$mask & 0xFFFFFFFF);
            if ($aStart <= $broadcast && $base <= $aEnd) {
                throw new DhcpValidationException(
                    "Le réseau {$cidr['network']} chevauche le sous-réseau par défaut ({$default['network']}/{$default['netmask']})."
                );
            }
        }
    }

    /**
     * Vérifie qu'aucune plage dynamique ne recouvre l'IP d'une réservation
     * DHCP existante (une IP réservée ne doit pas être distribuable).
     *
     * @param  array<int,array{begin:string,end:string}>  $ranges
     *
     * @throws DhcpValidationException
     */
    private function assertNoRangeCoversReservation(array $ranges): void
    {
        $reservedIps = DhcpReservation::query()->pluck('ip')->all();
        if ($reservedIps === []) {
            return;
        }

        foreach ($reservedIps as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }
            $ipLong = $this->ipToLong((string) $ip);
            foreach ($ranges as $range) {
                $beginLong = $this->ipToLong($range['begin']);
                $endLong = $this->ipToLong($range['end']);
                if ($ipLong >= $beginLong && $ipLong <= $endLong) {
                    throw new DhcpValidationException(
                        "La plage {$range['begin']}–{$range['end']} recouvre l'IP réservée {$ip}."
                    );
                }
            }
        }
    }

    // ========================================================================
    // CRUD (mutation = SQL en transaction PUIS export fichier + reload sous lock)
    // ========================================================================

    /**
     * @param  array<string,mixed>  $attrs
     *
     * @throws DhcpValidationException|DhcpCommandException
     */
    public function createSubnet(array $attrs): DhcpSubnet
    {
        return $this->withReloadLock('création', function () use ($attrs): DhcpSubnet {
            $payload = $this->normalizeAndValidate($attrs, null);

            return DB::transaction(fn () => DhcpSubnet::create($payload));
        });
    }

    /**
     * @param  array<string,mixed>  $attrs
     *
     * @throws DhcpValidationException|DhcpCommandException
     */
    public function updateSubnet(DhcpSubnet $subnet, array $attrs): DhcpSubnet
    {
        return $this->withReloadLock('modification', function () use ($subnet, $attrs): DhcpSubnet {
            $payload = $this->normalizeAndValidate($attrs, $subnet);

            DB::transaction(fn () => $subnet->update($payload));

            return $subnet->refresh();
        });
    }

    /**
     * @throws DhcpCommandException
     */
    public function deleteSubnet(DhcpSubnet $subnet): void
    {
        $vlanId = $subnet->vlan_id;

        $this->withReloadLock('suppression', function () use ($subnet): void {
            DB::transaction(fn () => $subnet->delete());
        }, ['vlan_id' => $vlanId]);
    }

    /**
     * Section critique unique sous le lock `dhcp.reload` : validation + écriture
     * SQL + export fichier + reload.
     *
     * CONCURRENCE (review 8.3 #3) — le lock est acquis AVANT la validation
     * (contrôle de chevauchement inter-VLAN, unicité `vlan_id`, non-recouvrement
     * des réservations) et non plus seulement autour de l'export/reload : deux
     * mutations concurrentes de VLAN chevauchants (ou de même `vlan_id`) ne
     * peuvent plus toutes deux franchir la validation puis s'insérer (TOCTOU).
     * MÊME clé que la 8.1 → sérialise aussi vis-à-vis des réservations.
     *
     * Mode dégradé (AC5) — la mutation SQL n'est PAS rollbackée si le reload
     * échoue (ne jamais perdre la saisie) : `DhcpCommandException` est propagée
     * à l'appelant après commit + export.
     *
     * @template TResult
     * @param  \Closure():TResult  $mutate  validation + écriture SQL (retourne le sous-réseau ou void)
     * @param  array<string,mixed>  $extraLogCtx
     * @return TResult
     *
     * @throws DhcpValidationException|DhcpCommandException
     */
    private function withReloadLock(string $action, \Closure $mutate, array $extraLogCtx = []): mixed
    {
        $lock = Cache::lock(self::RELOAD_LOCK_KEY, 30);

        // `block($timeout)` lève `LockTimeoutException` après expiration — il ne
        // retourne jamais `false`. On wrappe pour un message métier clair
        // (pattern exact DhcpService::reloadAfterMutation).
        try {
            $lock->block(15);
        } catch (LockTimeoutException $e) {
            throw new DhcpCommandException(
                'Verrou DHCP toujours détenu après 15s — opération concurrente en cours.',
                self::RELOAD_LOCK_KEY,
                [],
                -1,
                $e,
            );
        }

        try {
            // Validation + écriture SQL DANS la section critique (ferme le TOCTOU).
            $result = $mutate();

            // Export + reload (le reload peut échouer → mode dégradé AC5).
            $this->exportSubnetsFile();
            $this->dhcpService->reloadService();

            $subnet = $result instanceof DhcpSubnet ? $result : null;
            Log::channel($this->logChannel())->info('DhcpSubnetService: ' . $action . ' OK', array_merge([
                'action' => $action,
                'vlan_id' => $subnet?->vlan_id,
                'network' => $subnet?->network,
            ], $extraLogCtx));

            return $result;
        } finally {
            optional($lock)->release();
        }
    }

    // ========================================================================
    // EXPORT / RENDER
    // ========================================================================

    /**
     * Écrit atomiquement le fichier de params des sous-réseaux gérés.
     *
     * @throws DhcpCommandException si l'écriture échoue.
     */
    public function exportSubnetsFile(): void
    {
        $path = (string) config('sambaedu.dhcp.subnets_file', '/etc/sambaedu/sambaedu.conf.d/dhcp-subnets.conf');
        $content = $this->renderSubnetsConfFile(DhcpSubnet::query()->orderBy('vlan_id')->get());

        if (!AtomicFileWriter::write($path, $content)) {
            Log::channel($this->logChannel())->error('DhcpSubnetService: échec écriture dhcp-subnets.conf', ['path' => $path]);
            throw new DhcpCommandException(
                "Impossible d'écrire le fichier des sous-réseaux DHCP ({$path}).",
                'AtomicFileWriter::write',
                ['écriture refusée — vérifier les droits sur ' . $path],
                -1,
            );
        }
    }

    /**
     * Rend le contenu du fichier `dhcp-subnets.conf` (sans I/O, pur).
     * Format INI strict `clé = "valeur"` (D5 — parsé par `config.inc.sh`,
     * word-split : aucune valeur avec espace). Trié par n° de VLAN.
     *
     * Pour chaque VLAN N :
     *  - `dhcp_reseau_N`      (adresse réseau, host bits à zéro)
     *  - `dhcp_masque_N`      (masque décimal dérivé du CIDR)
     *  - `dhcp_gateway_N`
     *  - `dhcp_begin_range_N` / `dhcp_end_range_N`      (1re plage)
     *  - `dhcp_begin_range_N_j` / `dhcp_end_range_N_j`  (plages 2+, j contigu dès 1)
     *  - `dhcp_extra_option_N` (si présent)
     *
     * @param  iterable<DhcpSubnet>  $subnets
     */
    public function renderSubnetsConfFile(iterable $subnets): string
    {
        $lines = [];
        $lines[] = '# /etc/sambaedu/sambaedu.conf.d/dhcp-subnets.conf';
        $lines[] = '# Fichier généré automatiquement par SambaEdu-Reload (Story 8.3).';
        $lines[] = '# NE PAS éditer manuellement — écrasé à chaque mutation VLAN';
        $lines[] = '# depuis /app/network/dhcp (onglet Sous-réseaux).';
        $lines[] = '# Source de vérité : table SQL dhcp_subnets.';
        $lines[] = '';

        // Tri stable par vlan_id (indépendant de l'ordre de la collection).
        $sorted = collect($subnets)->sortBy(fn (DhcpSubnet $s) => (int) $s->vlan_id)->values();

        foreach ($sorted as $subnet) {
            $n = (int) $subnet->vlan_id;

            try {
                $parts = $this->cidrParts((string) $subnet->network);
            } catch (DhcpValidationException) {
                // Ligne corrompue — on ne casse pas tout l'export pour autant, MAIS
                // on trace : le VLAN disparaîtrait sinon silencieusement du
                // dhcpd.conf généré, postes plus servis (review 8.3 #6).
                Log::channel($this->logChannel())->warning(
                    'DhcpSubnetService: réseau illisible exclu de l\'export dhcp-subnets.conf',
                    ['id' => $subnet->id, 'vlan_id' => $subnet->vlan_id, 'network' => $subnet->network]
                );
                continue;
            }

            $lines[] = sprintf('dhcp_reseau_%d = "%s"', $n, long2ip($parts['base']));
            $lines[] = sprintf('dhcp_masque_%d = "%s"', $n, long2ip($parts['mask']));
            $lines[] = sprintf('dhcp_gateway_%d = "%s"', $n, (string) $subnet->gateway);

            $ranges = is_array($subnet->ranges) ? array_values($subnet->ranges) : [];
            if (isset($ranges[0]['begin'], $ranges[0]['end'])) {
                $lines[] = sprintf('dhcp_begin_range_%d = "%s"', $n, (string) $ranges[0]['begin']);
                $lines[] = sprintf('dhcp_end_range_%d = "%s"', $n, (string) $ranges[0]['end']);
            }
            $count = count($ranges);
            for ($j = 1; $j < $count; $j++) {
                if (!isset($ranges[$j]['begin'], $ranges[$j]['end'])) {
                    continue;
                }
                $lines[] = sprintf('dhcp_begin_range_%d_%d = "%s"', $n, $j, (string) $ranges[$j]['begin']);
                $lines[] = sprintf('dhcp_end_range_%d_%d = "%s"', $n, $j, (string) $ranges[$j]['end']);
            }

            $extra = $subnet->extra_option;
            if ($extra !== null && trim((string) $extra) !== '') {
                $lines[] = sprintf('dhcp_extra_option_%d = "%s"', $n, (string) $extra);
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    // ========================================================================
    // SOUS-RÉSEAU PAR DÉFAUT (LECTURE SEULE — décision D3)
    // ========================================================================

    /**
     * Retourne le sous-réseau par défaut (VLAN 0) en lecture seule, lu depuis
     * `sambaedu.conf` / `dhcp.conf` via `SambaEduConfig`. Jamais de parse_ini
     * maison. Ses clés vivent hors table — géré par l'autoconf serveur.
     *
     * @return array{network:string,netmask:string,gateway:string,begin_range:string,end_range:string}
     */
    public function defaultSubnet(): array
    {
        return [
            'network' => (string) ($this->config->get('dhcp_reseau', '') ?? ''),
            'netmask' => (string) ($this->config->get('dhcp_masque', '') ?? ''),
            'gateway' => (string) ($this->config->get('dhcp_gateway', '') ?? ''),
            'begin_range' => (string) ($this->config->get('dhcp_begin_range', '') ?? ''),
            'end_range' => (string) ($this->config->get('dhcp_end_range', '') ?? ''),
        ];
    }

    // ========================================================================
    // HELPERS internes
    // ========================================================================

    /**
     * Décompose un CIDR déjà normalisé (`base/prefix`) sans normalisation
     * (usage interne render/overlap). Réutilise `validateCidr` pour la robustesse.
     *
     * @return array{network:string,prefix:int,base:int,mask:int,broadcast:int}
     *
     * @throws DhcpValidationException
     */
    private function cidrParts(string $cidr): array
    {
        return $this->validateCidr($cidr);
    }

    private function maskFromPrefix(int $prefix): int
    {
        if ($prefix <= 0) {
            return 0;
        }
        return (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
    }

    /**
     * `ip2long` renvoie un entier signé sur certaines plateformes 32 bits —
     * on masque en non-signé 32 bits pour des comparaisons fiables.
     */
    private function ipToLong(string $ip): int
    {
        return ip2long($ip) & 0xFFFFFFFF;
    }

    private function ipInNetwork(int $ipLong, int $base, int $mask): bool
    {
        return ($ipLong & $mask) === $base;
    }

    private function logChannel(): string
    {
        return config('logging.channels.network') !== null ? 'network' : config('logging.default', 'single');
    }
}
