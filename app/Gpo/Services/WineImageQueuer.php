<?php

declare(strict_types=1);

namespace App\Gpo\Services;

use App\Gpo\Jobs\GenerateWineImageJob;
use App\Gpo\Support\GpoLogger;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Service métier — Met en queue la génération de l'image Wine partagée.
 *
 * Sépare la logique "dispatch + log + lock idempotence" du Job lui-même
 * (Controllers fins, Services métier, Jobs simples). Iso pattern 16.3b
 * (`NetworkScriptGenerator`).
 *
 * Story 16.3c — AC1.3, AC2.1, AC5.2.
 *
 * Idempotence (SM discrepance (a) tranchement 2026-05-12) :
 * - `Cache::lock('gpo:wine:generate-image:{application}', 1800)` non-bloquant
 *   AVANT le push. Si un Job est déjà en queue / en cours pour la même
 *   application, on rejette le second dispatch avec une exception métier
 *   (`WineImageAlreadyQueuedException`) que le Livewire SFC convertit en toast
 *   warning "Une génération est déjà en cours pour ce conteneur".
 * - Lock libéré dans `GenerateWineImageJob::handle()` / `failed()` (release
 *   forcé pour gérer le cas dispatcher ≠ worker queue).
 */
final class WineImageQueuer
{
    /**
     * Regex stricte d'application name (whitelist audit §6.F F7).
     * Chaîne vide autorisée pour le conteneur par défaut `.wine`.
     */
    public const APPLICATION_REGEX = '/^[a-zA-Z0-9._\-]*$/';

    /**
     * TTL du lock idempotence (s) — aligné sur le timeout Job pour éviter
     * qu'un Job timeout laisse un lock zombie. Volontairement plus court que
     * le timeout Job (1800s) — si le Job se termine plus tôt, le lock est
     * libéré par le Job lui-même via `forceRelease`.
     */
    public const LOCK_TTL = 1800;

    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly WinePrefixScanner $scanner,
    ) {}

    /**
     * Dispatche un `GenerateWineImageJob` après validation regex + check
     * idempotence. Retourne l'`operation_id` UUID propagé aux logs.
     *
     * @throws \InvalidArgumentException Application name invalide (regex / pas dans scan FS).
     * @throws WineImageAlreadyQueuedException Lock déjà détenu — Job déjà en queue.
     */
    public function dispatch(string $application): string
    {
        // AC1.3 / AC5.2 — Validation regex stricte.
        if (preg_match(self::APPLICATION_REGEX, $application) !== 1) {
            throw new \InvalidArgumentException(
                "WineImageQueuer: application name '{$application}' viole regex " . self::APPLICATION_REGEX,
            );
        }

        // AC1.3 — Validation que le préfixe existe (sauf chaîne vide = défaut).
        if (! $this->scanner->exists($application)) {
            throw new \InvalidArgumentException(
                "WineImageQueuer: prefix '{$application}' introuvable dans le scan FS",
            );
        }

        $lockKey = $this->lockKey($application);
        $operationId = (string) Str::uuid();

        $log = GpoLogger::action('gpo.wine.image.generate', $operationId, [
            'application' => $application,
            'sub' => 'queuer.dispatch',
        ]);

        // Lock non-bloquant 1800s (TTL = timeout Job). Si déjà détenu,
        // on rejette explicitement pour signaler à l'UI.
        $lock = Cache::lock($lockKey, self::LOCK_TTL);
        if (! $lock->get()) {
            $log->failure(new WineImageAlreadyQueuedException(
                "Une génération est déjà en cours pour le conteneur '{$application}'."
            ));
            throw new WineImageAlreadyQueuedException(
                "Une génération est déjà en cours pour le conteneur '{$application}'."
            );
        }

        try {
            $log->step('queued', [
                'lock_key' => $lockKey,
                'lock_ttl' => self::LOCK_TTL,
            ]);

            $this->dispatcher->dispatch(new GenerateWineImageJob($application, $operationId));

            $log->success([
                'job_class' => GenerateWineImageJob::class,
            ]);

            return $operationId;
        } catch (\Throwable $e) {
            // Si le dispatch lui-même échoue, on libère le lock pour
            // permettre un retry immédiat côté admin.
            Cache::lock($lockKey)->forceRelease();
            $log->failure($e);
            throw $e;
        }
    }

    /**
     * Clé lock idempotence — partagée avec `GenerateWineImageJob::lockKey()`.
     */
    public function lockKey(string $application): string
    {
        return 'gpo:wine:generate-image:' . ($application === '' ? '__default__' : $application);
    }
}
