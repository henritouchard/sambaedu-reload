<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Queue;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use App\Jobs\ReconcileNetworkShareJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Story 60.5 — L'OUVRIER MORT, constaté A POSTERIORI.
 *
 * Depuis que la mise en place des droits est ENFILÉE (story 60.4), un ouvrier de
 * file arrêté ne se manifeste par AUCUN symptôme : les écrans disent « engagé »,
 * les travaux s'empilent, et rien n'échoue jamais. C'est la forme la plus pure du
 * défaut que cet epic traque — un signal qui n'atteint pas son destinataire — et
 * elle est d'autant plus insidieuse qu'elle se lit comme un succès.
 *
 * **Le seuil porte sur l'ANCIENNETÉ, pas sur le volume.** Une file chargée mais
 * vivante est un système en bonne santé qui travaille : alerter dessus
 * apprendrait à l'exploitant à ignorer ce contrôle, ce qui est pire que de ne pas
 * l'avoir. Ce qui est anormal, c'est qu'un travail DISPONIBLE attende sans que
 * personne ne le prenne. Un seul travail vieux de vingt minutes est donc un
 * signal ; mille travaux posés il y a dix secondes n'en sont pas un.
 *
 * **Ce contrôle ne double PAS le runbook de déploiement.** Celui-ci vérifie que le
 * service d'ouvrier est en place au moment où on l'installe ; celui-ci constate,
 * en exploitation, qu'il a cessé de travailler. Deux moments, deux destinataires.
 *
 * Le seuil est une CONSTANTE, pas un réglage : un contrôle dont la sensibilité
 * s'ajuste finit toujours par être ajusté jusqu'au silence.
 */
final class QueueBacklogCheck implements EnvironmentCheck
{
    /**
     * Ancienneté au-delà de laquelle un travail DISPONIBLE et non pris signale un
     * ouvrier arrêté.
     *
     * Quinze minutes : très au-dessus de la durée d'un passage ordinaire (une
     * seconde) et même d'un gros passage (quelques secondes), assez court pour
     * qu'un incident soit vu dans la journée. Entre les deux, il n'y a aucune
     * situation normale à protéger.
     */
    private const STALE_SECONDS = 900;

    /** Table des travaux enfilés du pilote de file adossé à la base. */
    private const JOBS_TABLE = 'jobs';

    public function tag(): string
    {
        return 'queue';
    }

    public function name(): string
    {
        return 'File des travaux (ouvrier vivant)';
    }

    public function run(): CheckResult
    {
        // LA CONNEXION SURVEILLÉE EST CELLE DU TRAVAIL, PAS CELLE PAR DÉFAUT.
        //
        // La réconciliation des répertoires épingle sa connexion (elle doit
        // survivre à un redémarrage), et elle le fait indépendamment du réglage
        // par défaut de l'application. Interroger le réglage par défaut faisait
        // rendre « rien à signaler » à ce contrôle dès que l'application pointait
        // ailleurs — pendant que les travaux continuaient de s'empiler dans la
        // table qu'il ne regardait plus. Un contrôle qui rate exactement ce pour
        // quoi il existe est pire que pas de contrôle : il rassure.
        $connection = ReconcileNetworkShareJob::CONNECTION;
        $driver = (string) config('queue.connections.' . $connection . '.driver', '');

        // Le pilote synchrone exécute dans le processus appelant : il n'y a pas de
        // file, donc pas d'ouvrier à surveiller. Le dire est plus utile que de
        // rendre « ok » sur une question qui ne se pose pas.
        if ($driver === 'sync') {
            return CheckResult::ok('Les travaux s\'exécutent à la volée (aucune file) — rien à surveiller.');
        }

        if ($driver !== 'database') {
            return CheckResult::ok(sprintf(
                'La file « %s » est pilotée par « %s » : ce contrôle ne sait lire que celle adossée à la base.',
                $connection,
                $driver === '' ? 'non renseigné' : $driver,
            ));
        }

        try {
            if (! Schema::hasTable(self::JOBS_TABLE)) {
                return CheckResult::warn(
                    'La table des travaux enfilés est absente.',
                    'Créer la table de file : `php artisan queue:table` puis `php artisan migrate`.',
                );
            }

            $threshold = now()->subSeconds(self::STALE_SECONDS)->getTimestamp();

            // DISPONIBLES et non pris : `reserved_at` nul. Un travail réservé est
            // en cours de traitement — c'est un ouvrier qui travaille, pas un
            // ouvrier mort.
            $stale = (int) DB::table(self::JOBS_TABLE)
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $threshold)
                ->count();

            $total = (int) DB::table(self::JOBS_TABLE)->count();
        } catch (Throwable $e) {
            return CheckResult::warn(
                sprintf('Impossible de lire la file des travaux : %s', $e->getMessage()),
                'Vérifier la connexion à la base et la présence de la table des travaux enfilés.',
            );
        }

        if ($stale === 0) {
            return CheckResult::ok(sprintf(
                '%d travail(aux) en file, aucun en attente depuis plus de %d minutes.',
                $total,
                (int) (self::STALE_SECONDS / 60),
            ));
        }

        return CheckResult::warn(
            sprintf(
                '%d travail(aux) attendent depuis plus de %d minutes sans être pris (%d en file au total). '
                . 'La mise en place des droits des répertoires réseau et des arbres de classe est enfilée : '
                . 'tant que rien ne dépile, les écrans annoncent « engagé » et rien ne se produit sur le serveur.',
                $stale,
                (int) (self::STALE_SECONDS / 60),
                $total,
            ),
            'Vérifier que l\'ouvrier de file tourne (`systemctl status sambaedu-worker`, ou '
            . '`php artisan queue:work` en dépannage), puis relancer la réconciliation depuis la fiche '
            . 'du répertoire concerné.',
        );
    }
}
