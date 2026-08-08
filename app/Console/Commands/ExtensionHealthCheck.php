<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Extension;
use App\Services\Extensions\ExtensionHealthService;
use Illuminate\Console\Command;

/**
 * Story 56.5 (AC1, FR34) — `php artisan ext:health:check {key?}`.
 *
 * Sonde le backend de chaque extension `app` installée
 * (`http://127.0.0.1:<installed_port>/`) et PERSISTE ce qu'elle observe. C'est
 * le SEUL chemin automatique de mesure : la navbar, la bibliothèque et la fiche
 * LISENT l'état persisté (NFR9 — aucune requête sortante au rendu d'une page).
 *
 * Planifiée toutes les 5 minutes (`routes/console.php`).
 *
 * **Code retour 0 même si un backend est mort.** La commande CONSTATE, elle ne
 * porte pas de verdict : un `exit 1` toutes les 5 minutes remplirait la
 * supervision d'alertes pour un état que l'admin voit déjà sur la tuile et sur
 * la fiche. C'est `sambaedu:doctor --tag=extensions` qui rend un verdict — c'est
 * son métier. (Divergence ASSUMÉE avec `ext:sources:sync`, qui rend `1` sur une
 * source en échec : une synchro quotidienne qui échoue est un incident
 * d'approvisionnement, alors qu'un backend arrêté est un état courant du parc —
 * l'admin peut avoir stoppé le service exprès.)
 *
 * Le seul code retour non nul possible est celui d'une INVOCATION fautive (clé
 * inconnue) : là, l'opérateur s'est trompé, et un silence l'induirait en erreur.
 *
 * Aucun acteur : la santé n'écrit RIEN au journal d'audit (télémétrie, pas un
 * acte — décision n° 2 de la story).
 */
class ExtensionHealthCheck extends Command
{
    /** @var string */
    protected $signature = 'ext:health:check
        {key? : Clé de l\'extension à sonder (par défaut : toutes les `app` installées)}';

    /** @var string */
    protected $description = "Sonde les backends des extensions `app` installées et persiste leur état de santé.";

    /** @var string */
    protected $help = <<<'HELP'
    Sonde le backend de chaque extension applicative installée et ENREGISTRE ce
    qu'elle observe.

    C'est le seul chemin de mesure : les pastilles de la barre de navigation, la
    bibliothèque et la fiche d'une extension LISENT cet état enregistré. Aucune page
    n'interroge un backend au moment de son affichage.

      <info>php artisan ext:health:check</info>          toutes les extensions installées
      <info>php artisan ext:health:check monoutil</info>  une seule

    Planifiée toutes les cinq minutes.

    <comment>Un backend arrêté n'est PAS une erreur de cette commande</comment> : elle constate,
    elle ne juge pas — un service peut avoir été stoppé volontairement. Elle sort donc
    normalement même si tout est mort. Le seul échec possible est une clé d'extension
    inconnue, c'est-à-dire une faute de frappe de votre part. Pour un verdict, c'est
    <info>sambaedu:doctor --tag=extensions</info> qu'il faut interroger.
    HELP;

    public function handle(ExtensionHealthService $health): int
    {
        $key = $this->argument('key');

        return $key === null
            ? $this->checkAll($health)
            : $this->checkOne($health, (string) $key);
    }

    private function checkAll(ExtensionHealthService $health): int
    {
        $result = $health->checkAll();

        if ($result['checked'] === 0) {
            $this->line('Aucune extension `app` installée — rien à sonder.');
        } else {
            $this->line(sprintf(
                '%d extension(s) sondée(s) — %d injoignable(s).',
                $result['checked'],
                $result['unreachable'],
            ));
        }

        if (($result['failed'] ?? 0) > 0) {
            // Une extension dont l'état n'a pas pu être PERSISTÉ : les autres ont
            // été mesurées quand même (NFR6). Le détail est dans les logs.
            $this->warn(sprintf(
                '%d extension(s) n\'ont pas pu être enregistrées — voir storage/logs (« Sonde de santé NON PERSISTÉE »).',
                $result['failed'],
            ));
        }

        if ($result['unreachable_keys'] !== []) {
            $this->warn('Injoignable(s) : '.implode(', ', $result['unreachable_keys']));
            $this->line('Diagnostic : systemctl status sambaedu-ext-<clé>');
        }

        if ($result['reset'] > 0) {
            $this->line(sprintf(
                '%d état(s) de santé remis à zéro (extensions plus installées).',
                $result['reset'],
            ));
        }

        return self::SUCCESS;
    }

    private function checkOne(ExtensionHealthService $health, string $key): int
    {
        /** @var Extension|null $extension */
        $extension = Extension::query()->where('key', $key)->first();

        if ($extension === null) {
            $this->error(sprintf('Extension inconnue : %s', $key));

            return self::FAILURE;
        }

        $result = $health->checkOne($extension);

        if (! $result['monitored']) {
            $this->line(sprintf(
                '%s n\'a pas de backend à sonder (extension `link`, ou `app` non installée) — aucun état écrit.',
                $key,
            ));

            return self::SUCCESS;
        }

        if ($result['reachable']) {
            $this->info(sprintf('%s : joignable.', $key));

            return self::SUCCESS;
        }

        $this->warn(sprintf('%s : INJOIGNABLE — %s', $key, $result['category']));
        $this->line(sprintf('Diagnostic : systemctl status sambaedu-ext-%s', $key));

        return self::SUCCESS;
    }
}
