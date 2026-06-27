<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Service dédié à la détection des sessions utilisateur actives (multi-machines).
 *
 * Domaine : runtime samba/winbind (vs. UserService qui gère la persistance LDAP).
 * Ce n'est pas la même responsabilité — on garde les services séparés.
 *
 * Source de données (post-review #A) :
 *   Parse le fichier `/tmp/smbstatus` (rempli par un cron samba) pour trouver
 *   les sessions actives d'un login donné. Équivalent fonctionnel du legacy
 *   `get_smbmachine()` (sambaedu/includes/fonc_parc.inc.php:512) sans recopier
 *   le cache APCu legacy partagé (on utilise le cache Laravel local au service).
 *
 * Dégradation gracieuse : si le fichier est absent ou non lisible, retourne
 * un tableau vide — le Composer désactive alors silencieusement le badge
 * multi-session.
 *
 * Cache : 30s via le repo Laravel (ne touche pas au cache APCu partagé).
 *
 * Consommé par :
 *   - `OverlaySignalBuilder` (signal `multi-session`) pour afficher le cartouche
 *     orange + badge bleu quand l'utilisateur a une session sur une autre
 *     machine que la machine courante.
 */
class UserSessionsService
{
    /**
     * Chemin par défaut du fichier smbstatus produit par samba en cron.
     * Override possible via `config('wallpapers.smbstatus_path')`.
     */
    private const DEFAULT_SMBSTATUS_PATH = '/tmp/smbstatus';

    /** TTL cache (secondes). Court — le statut de session change vite. */
    private const CACHE_TTL = 30;

    public function __construct(
        private readonly ?CacheRepository $cache = null,
        private readonly ?string $smbstatusPath = null,
    ) {}

    /**
     * Retourne les machines où l'utilisateur a une session active.
     *
     * @return array<int, array{machine: string, since: ?string}>
     */
    public function getActiveSessions(string $login): array
    {
        if ($login === '') {
            return [];
        }

        $cacheKey = 'user_sessions:' . $login;
        $cache = $this->cache ?? Cache::getFacadeRoot();

        try {
            /** @var array<int, array{machine: string, since: ?string}> $sessions */
            $sessions = $cache->remember(
                $cacheKey,
                self::CACHE_TTL,
                fn() => $this->readSessionsFromSource($login),
            );
            return $sessions;
        } catch (\Throwable $e) {
            Log::warning('[UserSessionsService] cache/read failed', [
                'login' => $login,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Convenience wrapper : sessions sur d'autres machines que la machine courante.
     * Retourne uniquement les noms de machines (pour affichage concis dans le cartouche).
     *
     * @return list<string>
     */
    public function getOtherMachines(string $login, string $currentMachine): array
    {
        $sessions = $this->getActiveSessions($login);
        $currentLower = strtolower(trim($currentMachine));
        $others = [];
        foreach ($sessions as $s) {
            $machine = (string) ($s['machine'] ?? '');
            if ($machine === '') {
                continue;
            }
            if (strtolower($machine) === $currentLower) {
                continue;
            }
            $others[] = $machine;
        }
        return array_values(array_unique($others));
    }

    /**
     * Lecture brute du fichier smbstatus.
     *
     * Format legacy (cf. `sambaedu/includes/fonc_parc.inc.php::smbstatus`) :
     *   Lignes "connexions" : `<pid> <user> <machine> (<info>)`
     *   Lignes "locks"      : `<pid> <user> WRONLY|RDWR LEASE(...)`
     * Une session est "active" si un lock existe avec le même (pid, user).
     *
     * @return array<int, array{machine: string, since: ?string}>
     */
    private function readSessionsFromSource(string $login): array
    {
        $path = $this->smbstatusPath ?? (string) config('wallpapers.smbstatus_path', self::DEFAULT_SMBSTATUS_PATH);
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || $lines === []) {
            return [];
        }

        // Première passe : collecter connexions (pid, user → [machine, …])
        $connexions = [];
        $lockLines = [];
        foreach ($lines as $line) {
            if (preg_match('/^(\d+)\s+([a-z0-9._\-]+)\s+([a-z0-9._\-]+)\s+\(.*$/i', $line, $m)) {
                $pid = (int) $m[1];
                $user = (string) $m[2];
                $machine = (string) $m[3];
                if ($user === $login) {
                    $connexions[] = ['pid' => $pid, 'machine' => $machine];
                }
            } elseif (preg_match('/^(\d+)\s+([a-z0-9._\-]+)\s+(WRONLY|RDWR)\s+LEASE\(/i', $line, $m)) {
                $lockLines[] = ['pid' => (int) $m[1], 'user' => (string) $m[2]];
            }
        }

        if ($connexions === []) {
            return [];
        }

        // Deuxième passe : on ne garde que les connexions "actives" (présence
        // d'un lock) — évite de compter les sessions stalles.
        $activeKeys = [];
        foreach ($lockLines as $lock) {
            if ($lock['user'] === $login) {
                $activeKeys[$lock['pid']] = true;
            }
        }

        $result = [];
        $seenMachines = [];
        foreach ($connexions as $cnx) {
            // Si on n'a vu aucun lock du tout pour ce user, on reste tolérant
            // (le format smbstatus peut varier) et on retourne toutes les cnx.
            if ($activeKeys !== [] && ! isset($activeKeys[$cnx['pid']])) {
                continue;
            }
            if (isset($seenMachines[$cnx['machine']])) {
                continue;
            }
            $seenMachines[$cnx['machine']] = true;
            $result[] = [
                'machine' => $cnx['machine'],
                'since' => null, // non disponible depuis smbstatus brut
            ];
        }

        return $result;
    }
}
