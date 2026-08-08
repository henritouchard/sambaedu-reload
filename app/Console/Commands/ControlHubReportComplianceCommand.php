<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ControlHubReportComplianceJob;
use App\Models\ControlHubConnection;
use App\Models\ControlHubContract;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Story 39.2 (canal ③) — Tick de l'émetteur de conformité amont.
 *
 * Planifiée `everyMinute()` dans le Kernel, la commande gère ELLE-MÊME sa cadence
 * (intervalle fixe `config('controlHub.compliance.interval')`, défaut 15 min) via un
 * simple watermark en cache — PAS de colonne BDD dédiée, PAS de piggyback heartbeat
 * (cf. Dev Notes Q3). Elle court-circuite AVANT de dispatcher si aucun contrat actif
 * ou aucune connexion valide n'existe (évite d'empiler des jobs inutiles en queue),
 * puis dispatche {@see ControlHubReportComplianceJob} (retry/queue).
 *
 * Patron de garde/log : {@see ControlHubHeartbeatCommand}.
 */
class ControlHubReportComplianceCommand extends Command
{
    protected $signature = 'controlhub:report-compliance';

    protected $description = 'Émet le rapport de conformité état-intégral vers l\'autorité amont (contrat managé, canal ③)';

    protected $help = <<<'HELP'
    Émet vers l'autorité amont le rapport de conformité décrivant l'état intégral de
    cette instance.

    Planifiée toutes les minutes, la commande gère elle-même sa cadence réelle :
    elle n'émet qu'une fois l'intervalle configuré écoulé (un quart d'heure par
    défaut). Elle s'arrête avant même de mettre un travail en file s'il n'existe
    aucun contrat actif ou aucune connexion valide — de quoi ne rien empiler
    inutilement sur une instance autonome.

    L'émission proprement dite est confiée à la file d'attente : la commande rend la
    main tout de suite, et les tentatives en cas d'échec relèvent du travail en file.
    HELP;

    /** Watermark de dernière émission (throttle cadence fixe, sans colonne BDD). */
    private const LAST_RUN_CACHE_KEY = 'controlHub_compliance_last_run';

    public function handle(): int
    {
        if (! config('controlHub.compliance.enabled', true)) {
            $this->info('ControlHub Compliance : émission désactivée via configuration');

            return self::SUCCESS;
        }

        // Court-circuit AVANT dispatch : aucun contrat actif → rien à rapporter (NFR-A1).
        if (ControlHubContract::active() === null) {
            $this->info('ControlHub Compliance ignoré : aucun contrat amont actif');

            return self::SUCCESS;
        }

        // Court-circuit AVANT dispatch : pas de connexion valide → pas d'émission possible.
        $connection = ControlHubConnection::current();
        if ($connection === null || ! $connection->isValid()) {
            $this->info('ControlHub Compliance ignoré : aucune connexion amont valide');

            return self::SUCCESS;
        }

        // Cadence fixe : n'émettre que si l'intervalle configuré est écoulé.
        if (! $this->intervalElapsed()) {
            $this->info('ControlHub Compliance ignoré : intervalle non écoulé');

            return self::SUCCESS;
        }

        ControlHubReportComplianceJob::dispatch();
        Cache::put(self::LAST_RUN_CACHE_KEY, now()->toIso8601String());

        $this->info('ControlHub Compliance : job d\'émission dispatché');

        return self::SUCCESS;
    }

    /**
     * L'intervalle configuré s'est-il écoulé depuis la dernière émission dispatchée ?
     * Premier passage (cache vide) : toujours vrai.
     */
    private function intervalElapsed(): bool
    {
        $last = Cache::get(self::LAST_RUN_CACHE_KEY);

        if ($last === null) {
            return true;
        }

        $interval = (int) config('controlHub.compliance.interval', 15);

        return Carbon::parse($last)->addMinutes($interval)->lessThanOrEqualTo(now());
    }
}
