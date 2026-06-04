<?php

declare(strict_types=1);

namespace App\SystemStatus;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Suivi d'état des exécutions de scripts d'install distro.
 *
 * **Store `file` explicite (fix review F1)** : l'état est écrit par le queue
 * worker et lu par les process PHP-FPM (`wire:poll`). Le store par défaut
 * (`CACHE_DRIVER`, fallback `apc`) est per-process avec APCu — l'état du
 * worker y serait invisible du web (« En cours… » à vie). Le store `file`
 * est partagé entre tous les process du serveur, sans dépendance externe.
 *
 * **Lock anti double-dispatch (fix review F2)** : `tryAcquireLock()` est
 * appelé par la page AVANT le dispatch (pattern WineImageQueuer) ;
 * {@see Jobs\RunDistroInstallScriptJob} relâche en fin d'exécution via
 * `releaseLock()` (forceRelease — le worker n'est pas l'owner du lock).
 *
 * États : `running` | `done` | `failed` (+ `detail`, `started_at`,
 * `finished_at`, `notified` — fix review F13 : un toast de fin n'est émis
 * qu'une fois).
 */
class DistroInstallTracker
{
    private const KEY_PREFIX = 'system-status:distro-install:';

    private const LOCK_PREFIX = 'system-status:distro-install-lock:';

    /** TTL des états (24h) — un install ISO peut être long, et l'issue doit rester visible. */
    private const TTL_SECONDS = 86400;

    /** TTL du lock — aligné sur le timeout du job (2h) + marge. */
    private const LOCK_TTL_SECONDS = 7800;

    /**
     * Tente d'acquérir le lock d'install (non bloquant). À appeler AVANT
     * `start()` + dispatch ; `false` = un install est déjà en cours.
     */
    public function tryAcquireLock(Distro $distro): bool
    {
        return $this->store()->lock(self::LOCK_PREFIX . $distro->value, self::LOCK_TTL_SECONDS)->get();
    }

    /**
     * Relâche le lock depuis le job (process différent de l'acquéreur →
     * forceRelease).
     */
    public function releaseLock(Distro $distro): void
    {
        $this->store()->lock(self::LOCK_PREFIX . $distro->value, self::LOCK_TTL_SECONDS)->forceRelease();
    }

    public function start(Distro $distro): void
    {
        $this->store()->put(self::key($distro), [
            'status' => 'running',
            'detail' => null,
            'started_at' => Carbon::now()->toIso8601String(),
            'finished_at' => null,
            'notified' => false,
        ], self::TTL_SECONDS);
    }

    public function finish(Distro $distro): void
    {
        $this->terminate($distro, 'done', null);
    }

    public function fail(Distro $distro, string $detail): void
    {
        // Sanitize avant stockage (fix review F6) : le detail peut être du
        // stderr brut de script root — on retire les chars de contrôle et on
        // tronque court ; le stderr complet reste dans les logs du job.
        $clean = (string) preg_replace('/[^\P{C}\n]+/u', ' ', $detail);
        $clean = trim((string) preg_replace('/\s+/', ' ', $clean));

        $this->terminate($distro, 'failed', substr($clean, 0, 300));
    }

    /**
     * Marque l'issue comme notifiée (toast émis) — idempotence des toasts
     * à travers les polls / rechargements de page.
     */
    public function markNotified(Distro $distro): void
    {
        $state = $this->stateFor($distro);
        if ($state === null) {
            return;
        }
        $state['notified'] = true;
        $this->store()->put(self::key($distro), $state, self::TTL_SECONDS);
    }

    /**
     * Efface l'état (tests / remise à zéro).
     */
    public function reset(Distro $distro): void
    {
        $this->store()->forget(self::key($distro));
        $this->releaseLock($distro);
    }

    /**
     * @return array{status: string, detail: ?string, started_at: ?string, finished_at: ?string, notified?: bool}|null
     */
    public function stateFor(Distro $distro): ?array
    {
        $state = $this->store()->get(self::key($distro));

        return is_array($state) ? $state : null;
    }

    public function isRunning(Distro $distro): bool
    {
        return ($this->stateFor($distro)['status'] ?? null) === 'running';
    }

    /**
     * Au moins un install en cours (pilote le wire:poll de la page).
     */
    public function anyRunning(): bool
    {
        foreach (Distro::cases() as $distro) {
            if ($this->isRunning($distro)) {
                return true;
            }
        }

        return false;
    }

    private function terminate(Distro $distro, string $status, ?string $detail): void
    {
        $state = $this->stateFor($distro) ?? [
            'status' => $status,
            'detail' => null,
            'started_at' => null,
            'finished_at' => null,
            'notified' => false,
        ];
        $state['status'] = $status;
        $state['detail'] = $detail;
        $state['finished_at'] = Carbon::now()->toIso8601String();
        $state['notified'] = false;

        $this->store()->put(self::key($distro), $state, self::TTL_SECONDS);
    }

    /**
     * Store PARTAGÉ entre queue worker et PHP-FPM — ne jamais retomber sur
     * le store par défaut ici (cf. docblock classe). En environnement de
     * test le store `file` fonctionne aussi (storage/framework/cache),
     * les tests resettent leurs clés en setUp.
     */
    private function store(): Repository
    {
        return Cache::store('file');
    }

    private static function key(Distro $distro): string
    {
        return self::KEY_PREFIX . $distro->value;
    }
}
