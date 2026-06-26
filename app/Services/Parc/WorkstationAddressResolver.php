<?php

declare(strict_types=1);

namespace App\Services\Parc;

use App\Models\Workstation;
use App\Services\Network\DhcpService;
use Illuminate\Support\Facades\Log;

/**
 * Résout l'adresse IP COURANTE d'un poste au moment d'une action power.
 *
 * Pourquoi ce service existe (vs lire `workstations.ip`) :
 * la colonne `workstations.ip` est peuplée UNE seule fois depuis l'attribut AD
 * `iphostnumber` (= une réservation DHCP figée) et n'est jamais rafraîchie. Les
 * postes sans réservation (enrôlés via iPXE, p.ex. `post-neofut`) reçoivent une
 * IP DYNAMIQUE du pool : leur `workstations.ip` est NULL, et toute action qui
 * dépend de cette colonne casse (broadcast WOL incalculable, ping sur hostname
 * non résolu → « déjà éteint » mensonger).
 *
 * Stratégie en cascade (source la plus fiable d'abord) :
 *   1. Bail DHCP actif matché par MAC dans `/var/lib/dhcp/dhcpd.leases` — la
 *      vérité terrain pour une IP dynamique.
 *   2. Résolution DNS du hostname (si dhcpd fait des updates DDNS).
 *   3. Fallback : `workstations.ip` stockée (réservation importée de l'AD).
 *
 * Retourne null si aucune source ne donne d'IPv4 utilisable — l'appelant doit
 * alors remonter une erreur explicite plutôt que cibler le hostname à l'aveugle.
 */
class WorkstationAddressResolver
{
    public function __construct(
        private DhcpService $dhcpService,
    ) {}

    /**
     * @return string|null IPv4 joignable, ou null si introuvable.
     */
    public function resolve(Workstation $workstation): ?string
    {
        // 1. Bail DHCP actif par MAC — source de vérité en environnement dynamique.
        $ip = $this->fromActiveLease($workstation);
        if ($ip !== null) {
            return $ip;
        }

        // 2. DNS (DDNS renseigné par dhcpd).
        $ip = $this->fromDns($workstation);
        if ($ip !== null) {
            return $ip;
        }

        // 3. Fallback : IP réservée figée importée de l'AD.
        $stored = (string) ($workstation->ip ?? '');
        if ($this->isUsableIp($stored)) {
            return $stored;
        }

        return null;
    }

    /**
     * Cherche le bail actif dont la MAC correspond à celle du poste.
     * En cas de baux multiples (renouvellements), garde le plus récent (ends_at).
     */
    private function fromActiveLease(Workstation $workstation): ?string
    {
        $mac = $this->normalizeMac((string) ($workstation->mac ?? ''));
        if ($mac === '') {
            return null;
        }

        try {
            $leases = $this->dhcpService->listActiveLeases();
        } catch (\Throwable $e) {
            // Mode dégradé : fichier leases illisible / service down → on laisse
            // la cascade tenter le DNS puis la réservation stockée.
            Log::warning('WorkstationAddressResolver: lecture des baux DHCP échouée', [
                'workstation' => $workstation->name,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $best = $leases
            ->filter(fn (array $l) => ($l['state'] ?? null) === 'active'
                && $this->normalizeMac((string) ($l['mac'] ?? '')) === $mac)
            // ISC formate ends_at en "YYYY/MM/DD HH:MM:SS" → tri lexical == chronologique.
            ->sortByDesc(fn (array $l) => (string) ($l['ends_at'] ?? ''))
            ->first();

        $ip = (string) ($best['ip'] ?? '');

        return $this->isUsableIp($ip) ? $ip : null;
    }

    /**
     * Résout le hostname via DNS. `gethostbyname` renvoie l'argument inchangé en
     * cas d'échec — on traite ce cas comme « non résolu ».
     */
    private function fromDns(Workstation $workstation): ?string
    {
        $name = trim((string) ($workstation->name ?? ''));
        if ($name === '') {
            return null;
        }

        $resolved = $this->lookupHostname($name);
        if ($resolved === $name || ! $this->isUsableIp($resolved)) {
            return null;
        }

        return $resolved;
    }

    /**
     * Appel DNS isolé (overridable en test). `gethostbyname` renvoie l'argument
     * inchangé en cas d'échec de résolution.
     */
    protected function lookupHostname(string $name): string
    {
        return @gethostbyname($name);
    }

    private function isUsableIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        // 0.0.0.0 et loopback ne sont pas des cibles joignables pour une action.
        return $ip !== '0.0.0.0' && ! str_starts_with($ip, '127.');
    }

    /**
     * Normalise une MAC en hexadécimal minuscule sans séparateur, pour comparer
     * `workstations.mac` (format AD) et la MAC d'un bail (format ISC `aa:bb:...`).
     */
    private function normalizeMac(string $mac): string
    {
        return strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $mac));
    }
}
