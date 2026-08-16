<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Nextcloud\NextcloudProvisioningService;
use Illuminate\Console\Command;

/**
 * Story 61.1 — LE GESTE D'EXPLOITATION, en commande.
 *
 * Doctrine du dépôt : les opérations multi-instance sont des COMMANDES, jamais des
 * procédures manuelles à rejouer. Le bouton de `/admin/settings/files` n'est pas un
 * second chemin — il enfile un traitement qui exécute ce même service.
 *
 * **Codes de sortie, et ils veulent dire quelque chose :**
 *  - `0` — convergé : les deux montages sont en place, le stock est adopté ;
 *  - `1` — échecs PARTIELS : quelque chose a abouti et quelque chose a échoué. Les
 *    compteurs disent quoi. Un run qui a monté les stockages mais échoué sur les
 *    comptes tombe ici, et il le DIT ;
 *  - `2` — rien n'a été tenté : configuration incomplète, capacité éteinte,
 *    instance injoignable, privilège insuffisant, ou provisionnement concurrent.
 *
 * `--dry-run` n'émet AUCUNE écriture : ni montage, ni compte, ni cache d'identité.
 * Il LIT (l'état de l'instance et l'existence des comptes) parce qu'un aperçu qui
 * n'interrogerait rien n'apprendrait rien.
 *
 * ---------------------------------------------------------------------------
 * **GESTE DÉCONSEILLÉ — la confirmation est délibérée.** Ce provisionnement
 * repose sur des montages `files_external` en SMB vers le serveur de fichiers,
 * et ce chemin d'accès n'est PAS acquis : le partage SMB est appelé à
 * disparaître. Ce qui est monté aujourd'hui devra être démonté et remplacé, et
 * chaque instance provisionnée est un état de plus à défaire. La commande
 * continue de fonctionner — elle reste la seule façon d'aligner les comptes,
 * et le backend `nextcloud` y renvoie encore quand une identité manque — mais
 * elle demande confirmation avant d'écrire quoi que ce soit.
 *
 * La confirmation porte sur les MONTAGES, parce que c'est d'eux que parle
 * l'avertissement. Elle est donc sautée pour `--dry-run` (qui n'écrit rien) et
 * pour `--users-only` (qui n'en pose aucun : il adopte des comptes et écrit le
 * cache d'identité). Elle est levée par `--force` ; en mode non interactif sans
 * `--force`, la commande refuse et sort en `2` : rien n'a été tenté.
 * ---------------------------------------------------------------------------
 */
class NextcloudProvisionCommand extends Command
{
    protected $signature = 'nextcloud:provision
        {--dry-run : Dit ce qui serait fait, sans émettre la moindre écriture}
        {--users-only : Ne traite que les comptes utilisateurs}
        {--mounts-only : Ne traite que les montages external storage}
        {--force : Passe outre la confirmation (usage scripté)}';

    protected $description = 'Provisionne l\'accès Nextcloud : comptes utilisateurs, et montages external storage SMB — ces derniers DÉCONSEILLÉS, avec confirmation';

    protected $help = <<<'HELP'
    <comment>Poser les montages est DÉCONSEILLÉ.</comment> Ils relient l'instance aux partages du
    serveur de fichiers par du SMB, et ce chemin d'accès n'est pas acquis : le partage
    SMB est appelé à disparaître. Ce qui est monté aujourd'hui devra être démonté
    demain, et chaque instance provisionnée est un état de plus à défaire. La commande
    fonctionne toujours, mais elle demande confirmation avant de créer ou de modifier
    un montage.

    Le volet <comment>comptes</comment> n'est pas concerné : il n'écrit aucun montage et reste la seule
    façon d'aligner les comptes de l'instance. <comment>--users-only</comment> ne demande donc rien.

    Provisionne l'accès Nextcloud : les montages de stockage externe vers les partages
    du serveur, et les comptes des utilisateurs.

      <info>php artisan nextcloud:provision --dry-run</info>      ce qui serait fait, sans aucune écriture
      <info>php artisan nextcloud:provision</info>
      <info>php artisan nextcloud:provision --users-only</info>
      <info>php artisan nextcloud:provision --mounts-only</info>

    <comment>--dry-run</comment> n'émet AUCUNE écriture — ni montage, ni compte, ni mise en cache
    d'identité. Il lit l'état de l'instance, car un aperçu qui ne regarde pas la
    réalité ne vaut rien. Il ne demande donc aucune confirmation.

    <comment>--force</comment> passe outre la confirmation, pour un usage scripté. Sans lui et hors
    terminal interactif, un passage touchant aux montages refuse et sort en <info>2</info> :
    rien n'a été tenté.

    <comment>Les codes de retour portent une information, lisez-les :</comment>

      <info>0</info>  convergé — les montages sont en place et les comptes adoptés ;
      <info>1</info>  échecs PARTIELS — quelque chose a abouti, autre chose a échoué ; les
         compteurs affichés disent quoi. Un passage qui monte les stockages mais
         échoue sur les comptes tombe ici, et le dit ;
      <info>2</info>  rien n'a été tenté — configuration incomplète, fonction désactivée,
         instance injoignable, privilèges insuffisants, ou provisionnement déjà en
         cours.

    Le bouton de la page de gestion des fichiers met en file ce même traitement : ce
    ne sont pas deux chemins différents.
    HELP;

    public function handle(NextcloudProvisioningService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $usersOnly = (bool) $this->option('users-only');
        $mountsOnly = (bool) $this->option('mounts-only');

        if ($usersOnly && $mountsOnly) {
            $this->error('--users-only et --mounts-only s\'excluent : choisissez lequel des deux.');

            return 2;
        }

        // La confirmation porte sur ce que l'avertissement décrit : les montages.
        // `--users-only` n'en pose aucun — le faire confirmer par un message qui
        // parle de SMB serait un avertissement à côté du geste.
        if (! $dryRun && ! $usersOnly && ! $this->confirmDiscouragedWrite()) {
            $this->comment('Rien n\'a été tenté.');

            return 2;
        }

        $report = $service->run(
            dryRun: $dryRun,
            mounts: ! $usersOnly,
            withUsers: ! $mountsOnly,
        );

        $this->render($report);

        return $report->exitCode();
    }

    /**
     * L'avertissement, puis la question. Le défaut est NON : un exploitant qui
     * valide sans lire ne provisionne rien, ce qui est le bon accident.
     *
     * `--force` lève la question — sans lui, un appel non interactif retombe sur
     * le défaut et refuse, ce qui est également volontaire : une planification qui
     * poserait ces montages toutes les nuits est exactement ce qu'on ne veut plus.
     */
    private function confirmDiscouragedWrite(): bool
    {
        if ((bool) $this->option('force')) {
            return true;
        }

        $this->warn('Poser les montages est DÉCONSEILLÉ.');
        $this->line(
            'Ils relient l\'instance aux partages du serveur de fichiers par du SMB, et ce chemin d\'accès '
            . 'n\'est pas acquis : le partage SMB est appelé à disparaître. Ce qui sera monté ici devra être '
            . 'démonté ensuite, et chaque instance provisionnée est un état de plus à défaire.'
        );
        $this->line('');
        $this->line('Pour voir ce qui serait fait sans rien écrire : <info>--dry-run</info>.');
        $this->line('Pour n\'aligner que les comptes, sans toucher aux montages : <info>--users-only</info>.');
        $this->line('');

        return $this->confirm('Provisionner malgré tout ?', false);
    }

    private function render(\App\Services\Nextcloud\NextcloudProvisioningReport $report): void
    {
        if ($report->dryRun) {
            $this->comment('SIMULATION — aucune écriture n\'a été émise.');
        }

        $refusal = $report->refusal();
        if ($refusal !== null) {
            $this->error($refusal);

            return;
        }

        $connection = $report->toArray()['connection'] ?? null;
        if (is_array($connection) && $connection['ok'] !== true) {
            $this->error((string) $connection['message']);

            return;
        }

        $mounts = $report->mounts();
        if ($mounts !== []) {
            $this->line('');
            $this->line('<options=bold>Montages external storage</>');
            $this->table(
                ['Montage', 'État', 'Détail'],
                array_map(
                    static fn (array $m): array => [$m['name'], $m['label'], $m['detail']],
                    $mounts,
                ),
            );
        }

        $counters = $report->userCounters();
        $this->line('');
        $this->line('<options=bold>Comptes utilisateurs</>');
        $this->table(
            ['Créés', 'Adoptés', 'Introuvables', 'Échecs', 'Hors périmètre'],
            [[
                $counters['crees'],
                $counters['adoptes'],
                $counters['introuvables'],
                $counters['echecs'],
                $counters['exclus'],
            ]],
        );

        // Correction de revue 61.3 #1 — le plafond qu'on n'a PAS écrit se dit. Un
        // profil indéterminable n'est pas un échec (le compte est adopté), mais le
        // taire ferait croire à un plafond posé.
        $unresolved = (int) ($counters['quotas_indetermines'] ?? 0);
        if ($unresolved > 0) {
            $sample = $report->quotaUnresolvedLogins();
            $this->line('');
            $this->warn(sprintf(
                'Plafonds NON écrits — profil de quota indéterminable pour %d compte(s) : l\'annuaire n\'a pas '
                . 'répondu pour eux. SE5 ne devine PAS un profil (un plafond faux s\'applique, un plafond absent '
                . 'se voit).%s',
                $unresolved,
                $sample === [] ? '' : ' Exemples : ' . implode(', ', $sample)
                    . ($unresolved > count($sample) ? ', …' : '') . '.',
            ));
        }

        $issues = $report->userIssues();
        if ($issues !== []) {
            $this->line('');
            $this->line('<options=bold>Comptes demandant un geste</>');
            foreach ($issues as $issue) {
                $this->line(sprintf('  · %s — %s : %s', $issue['login'], $issue['issue'], $issue['detail']));
            }
        }
    }
}
