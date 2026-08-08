<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ControlHubLinkAuditLog;
use App\Services\ControlHub\ControlHubContractSeveranceService;
use Illuminate\Console\Command;

/**
 * Story 32.1 (FR7 + NFR5) — Réception MANUELLE du signal de rupture du lien amont
 * (controlHub) via la ligne de commande.
 *
 * Point d'invocation EXPLICITE et IDEMPOTENT (un re-jeu sur un contrat déjà
 * `severed` ou en standalone est un no-op). Partage le service UNIQUE
 * {@see ControlHubContractSeveranceService} avec l'endpoint controlHub authentifié
 * (Q4). Délègue toute la logique (transition + matérialisation + audit + event) au
 * service ; n'affiche que le récapitulatif.
 *
 * NFR3 — sans contrat amont actif : message standalone + exit 0, rien d'écrit.
 *
 * ⚠️ GARDE-FOU R3 : vocabulaire « amont » exclusivement, terme prohibé proscrit.
 * [Source: prd-contrat-manage-se5.md#R3]
 */
class SeverControlHubLink extends Command
{
    protected $signature = 'controlhub:sever-link
        {--actor= : Acteur/origine du signal (login refnum, opérateur…) tracé dans l\'audit}
        {--reason= : Motif optionnel de la rupture, tracé dans l\'audit}';

    protected $description = 'Reçoit le signal de rupture du lien amont (controlHub) : passe le contrat actif en severed (lève les verrous + bornage catalogue), conserve les valeurs effectives et trace la transition (idempotent, re-jouable).';

    protected $help = <<<'HELP'
    Rompt le lien avec l'autorité amont. C'est un acte DÉLIBÉRÉ, à ne pas confondre
    avec une panne : un amont injoignable ne libère rien, seul ce signal le fait.

    Conséquences immédiates :

      · les verrous posés par le contrat tombent — les capacités concernées
        redeviennent modifiables localement ;
      · le bornage du catalogue d'applications est levé ;
      · les valeurs actuellement en vigueur sont CONSERVÉES telles quelles : rien ne
        change sur les postes, l'état effectif est simplement déverrouillé ;
      · la transition est tracée au journal d'audit.

      <info>php artisan controlhub:sever-link --actor=henri --reason="fin de convention"</info>

    <comment>--actor</comment> et <comment>--reason</comment> alimentent la trace d'audit — renseignez-les, c'est
    tout ce qui restera pour expliquer la rupture plus tard.

    Rejouable : sur un lien déjà rompu, ou sur une instance autonome, elle ne fait rien.
    HELP;

    public function handle(ControlHubContractSeveranceService $severanceService): int
    {
        $actor = $this->option('actor');
        $reason = $this->option('reason');

        $result = $severanceService->sever(
            origin: ControlHubLinkAuditLog::ORIGIN_COMMAND,
            actorLabel: is_string($actor) && $actor !== '' ? $actor : 'cli',
            reason: is_string($reason) && $reason !== '' ? $reason : null,
        );

        if (! $result->severed) {
            $this->info('Aucun contrat amont actif — rupture ignorée (comportement standalone, rien écrit).');

            return self::SUCCESS;
        }

        $this->info('Lien amont rompu (active → severed) — verrous et bornage catalogue levés :');
        $this->line("  Contrat rompu          : #{$result->contractId}");
        $this->line("  Items imposés levés    : {$result->itemsLifted}");
        $this->line("  Apps conservées        : {$result->appsPreserved}");
        $this->line("  Valeurs matérialisées  : {$result->valuesMaterialized}");
        $this->line("  Affectations app/parc  : {$result->applicationsAssigned}");

        return self::SUCCESS;
    }
}
