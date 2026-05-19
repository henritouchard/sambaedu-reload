<?php

declare(strict_types=1);

/**
 * Story 16.12 — D10 / AC7.3.
 *
 * Configuration du domaine `App\ScriptsOs` :
 *  - rétention archive
 *  - paths storage
 *  - limites stdout/stderr application-side
 *  - anti-replay sur l'endpoint d'ingestion
 *  - cache TTL des stats bandeau d'indicateurs
 *
 * Tous les paramètres sont env-overridable.
 */
return [

    // Nombre de jours pendant lesquels les rows sont gardées en DB. Au-delà,
    // la commande `script-logs:archive:rotate` (daily 03:30) les archive
    // dans des fichiers JSONL gzip mensuels puis purge la DB.
    'retention_days' => (int) env('SCRIPTSOS_RETENTION_DAYS', 90),

    'archive' => [
        // Dossier de stockage des archives gzip JSONL mensuelles. On utilise
        // `?:` plutôt que le 2ᵉ argument de env() : si la variable existe
        // mais vide (cas `SCRIPTSOS_ARCHIVE_PATH=` dans `.env`), env() renvoie
        // `''` au lieu du default, ce qui ferait écrire à la racine du FS.
        'path' => env('SCRIPTSOS_ARCHIVE_PATH') ?: storage_path('archives'),

        // Pattern de nommage. {YYYY} et {MM} sont substitués au runtime.
        'filename_pattern' => 'script-execution-logs-{YYYY}-{MM}.jsonl.gz',
    ],

    // Limites applicatives sur stdout/stderr (truncation côté model + wrapper).
    // La colonne DB est `text` (illimité côté pgsql) — c'est l'app qui plafonne.
    'stdout_max_bytes' => 8192,
    'stderr_max_bytes' => 8192,

    // Anti-replay + anti-clock-skew sur le champ `started_at` côté endpoint
    // d'ingestion. Un poste avec une horloge décalée de > 5 min vers le futur
    // ou > 7 jours vers le passé voit son POST rejeté en 422.
    'started_at_skew_seconds_future' => (int) env('SCRIPTSOS_SKEW_FUTURE', 300),
    'started_at_skew_seconds_past' => (int) env('SCRIPTSOS_SKEW_PAST', 7 * 86400),

    // TTL du cache des stats bandeau d'indicateurs (UI Livewire index).
    // Compromis fraîcheur quasi-RT / coût query GROUP BY.
    'stats_cache_ttl' => (int) env('SCRIPTSOS_STATS_CACHE_TTL', 60),

];
