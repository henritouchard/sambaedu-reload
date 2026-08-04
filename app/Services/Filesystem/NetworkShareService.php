<?php

declare(strict_types=1);

namespace App\Services\Filesystem;

use App\Enums\FileBackendOutcome;
use App\Jobs\ReconcileNetworkShareJob;
use App\Models\NetworkShare;
use App\Models\QuotaAuditLog;
use App\Models\QuotaRule;
use App\Services\Filesystem\Backend\FileBackendRegistry;
use App\Services\Filesystem\Backend\InspectionReport;
use App\Services\Filesystem\Backend\NodeReconciliation;
use App\Services\Filesystem\Backend\ReconciliationReport;
use App\Services\Filesystem\Plan\FilePlan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Story 34.1 → 60.4 — l'ORCHESTRATEUR des répertoires réseau gérés, AU-DESSUS de
 * la ligne de contrat.
 *
 * **Ce service ne sait plus rien du serveur de fichiers.** Il ne dérive aucun nom
 * de groupe système, ne construit aucun chemin absolu, n'exécute aucune commande.
 * Son travail tient en quatre gestes : valider le nom de répertoire (une règle de
 * NOMMAGE, neutre), projeter le répertoire en PLAN, résoudre l'autorité d'écriture
 * PAR LA COLONNE, et déléguer. Tout le reste est descendu dans
 * {@see \App\Services\Filesystem\Backend\Posix\PosixFileBackend} — c'est la coupe
 * de l'epic, et le piège du chantier était précisément de déplacer la dérivation
 * des permissions en laissant ses appelants au-dessus.
 *
 * Une règle d'architecture nommée le VÉRIFIE : aucun marqueur du serveur de
 * fichiers (commande, mode de permission, entrée de liste d'accès, chemin absolu,
 * nom d'exécution) n'a le droit d'apparaître dans ce fichier.
 *
 * ---------------------------------------------------------------------------
 * **DEUX RÉGIMES D'EXÉCUTION, ET UNE SEULE RAISON DE LES SÉPARER.**
 *
 * La pose de droits est QUADRATIQUE en nombre d'entrées nominatives (mesuré :
 * 0,32 s à 200 entrées, 7,16 s à 1 000, 63 s à 3 000). La faire dans le cycle
 * d'une requête d'écran, c'est faire attendre l'administrateur sans rien lui
 * apprendre. Les écrans ENFILENT donc ({@see queueReconciliation()}) et affichent
 * « engagé » ; les commandes et le traitement enfilé lui-même exécutent EN DIRECT
 * ({@see provision()}) — ils sont déjà hors requête, et leur code retour doit
 * garder son sens.
 *
 * **Le traitement enfilé transporte des IDENTIFIANTS, jamais un plan ni un
 * rapport.** La source autoritaire est la base : un plan sérialisé dans une file
 * serait un instantané périmé au moment de son exécution, et une assignation
 * ajoutée entre-temps serait ÉCRASÉE par le rejeu. La projection se refait dans le
 * traitement. Quant aux rapports, ils REFUSENT la sérialisation native (garde de
 * la story 60.3) : le dernier passage voyage en tableau, dans le cache.
 *
 * ---------------------------------------------------------------------------
 * **L'AUDIT RESTE ICI, ET C'EST DÉLIBÉRÉ.** Le contrat de backend exclut
 * explicitement l'auteur de l'action : il dit l'état désiré et ce qu'il en est
 * advenu, pas la traçabilité. Or la ligne d'audit est indexée sur le RÉPERTOIRE
 * (une entité de base que le backend ne reçoit pas) et sur l'AUTEUR (que le
 * contrat refuse de porter). L'écrire sous la ligne aurait donc perdu en silence
 * l'auteur passé par les commandes — un signal qui n'atteint plus son
 * destinataire, exactement le défaut que cet epic traque. Elle est donc écrite
 * ici, où les deux informations existent.
 *
 * **Aucun booléen ne vient d'un rapport.** Les adaptations `bool` conservées pour
 * les appelants historiques sont CALCULÉES depuis les listes du rapport.
 */
class NetworkShareService
{
    /**
     * Motif d'un `directory_name` valide (segment de chemin sûr) : alphanumérique
     * + `._-`, premier caractère différent de `.`. Source de vérité UNIQUE du
     * format — consommée par la garde de provisionnement ET par les règles de
     * validation des formulaires de création/édition.
     *
     * C'est une règle de NOMMAGE, pas une règle de système de fichiers : elle
     * reste donc au-dessus de la ligne, et la garde de chemin absolu, elle, est
     * descendue.
     */
    public const DIRECTORY_NAME_PATTERN = '/^[A-Za-z0-9_-][A-Za-z0-9_.-]*$/';

    /** Préfixe de cache du DERNIER rapport de réconciliation, par répertoire. */
    private const REPORT_CACHE_PREFIX = 'network-share-report:';

    private const FAILURE_CACHE_PREFIX = 'network-share-failure:';

    private const REPORT_CACHE_MINUTES = 60 * 24 * 7;

    public function __construct(
        private readonly SharePlanProjector $projector,
        private readonly FileBackendRegistry $registry,
        private readonly PlanStateComparator $comparator,
    ) {
    }

    // =========================================================================
    // Nommage
    // =========================================================================

    /**
     * Valide un `directory_name` : alphanumérique + `._-`, premier caractère
     * différent de `.`. Aucune espace, aucun métacaractère.
     */
    public function isValidDirectoryName(?string $name): bool
    {
        return $name !== null
            && $name !== ''
            && preg_match(self::DIRECTORY_NAME_PATTERN, $name) === 1;
    }

    // =========================================================================
    // Réconciliation
    // =========================================================================

    /**
     * Réconciliation SYNCHRONE — commandes hors requête et traitement enfilé.
     *
     * Rend `true` si TOUS les nœuds sont dans l'état voulu à l'issue du passage.
     * Le booléen est calculé depuis les listes du rapport : il n'en est jamais un
     * champ.
     */
    public function provision(NetworkShare $share, ?string $performedBy = null): bool
    {
        $report = $this->reconcile($share, $performedBy);

        return $report !== null && count($report->converged()) === $report->count();
    }

    /**
     * Réconciliation SYNCHRONE, rapport complet. `null` si le répertoire n'est
     * même pas projetable en plan (nom illisible, autorité d'écriture inconnue) —
     * un échec EXPLICITE, jamais un plan partiel.
     */
    public function reconcile(NetworkShare $share, ?string $performedBy = null): ?ReconciliationReport
    {
        $performedBy = $this->actor($performedBy);

        try {
            $plan = $this->planFor($share);
            $report = $this->registry->forShare($share)->provision($plan);
        } catch (Throwable $e) {
            Log::error('NetworkShareService: réconciliation refusée', [
                'share_id' => $share->id,
                'directory_name' => $share->directory_name,
                'error' => $e->getMessage(),
            ]);

            // Le rapport « en attente » posé à l'enfilage doit CÉDER LA PLACE.
            // Le laisser en cache ferait dire à l'écran « réconciliation engagée »
            // pour toujours : le geste a échoué, personne ne lève d'exception
            // au-dessus (ce bloc l'absorbe), donc la file ne réessaie ni ne
            // consigne rien. C'est la forme exacte du défaut que cet epic
            // traque — un signal qui n'atteint pas son destinataire.
            $this->rememberFailure($share, $e->getMessage());

            return null;
        }

        $this->forgetFailure($share);
        $this->rememberReport($share, $report);

        $converged = count($report->converged()) === $report->count();

        $this->writeAudit('provision_share', $performedBy, $share, [
            'directory_name' => $share->directory_name,
            'nodes' => $report->count(),
            'converged' => count($report->converged()),
            'failures' => count($report->failures()),
            'declines' => count($report->declines()),
            'success' => $converged,
        ]);

        Log::info('NetworkShareService: réconciliation terminée', [
            'share_id' => $share->id,
            'directory_name' => $share->directory_name,
            'nodes' => $report->count(),
            'success' => $converged,
        ]);

        return $report;
    }

    /**
     * Réconciliation ENFILÉE — le chemin des ÉCRANS.
     *
     * Le dernier rapport connu est immédiatement remplacé par un rapport
     * « en attente » couvrant les mêmes nœuds : l'écran dit donc « engagé », pas
     * « accompli », et ne ment pas en attendant.
     *
     * **Pas de suivi de progression, et c'est un choix.** Un seul geste passe par
     * ici, et la boucle de rétroaction existe déjà : l'encart de conformité relit
     * le disque à la demande. Une machinerie de suivi (interrogation périodique,
     * diffusion d'événements) serait de l'infrastructure construite avant son
     * besoin. L'administrateur voit le résultat au rafraîchissement suivant, ou en
     * relançant l'audit.
     *
     * Rend `false` si le répertoire n'est pas projetable — rien n'est enfilé.
     */
    public function queueReconciliation(NetworkShare $share, ?string $performedBy = null): bool
    {
        try {
            $plan = $this->planFor($share);
            $backend = $this->registry->forShare($share);
            // Le rapport « en attente » passe par la MÊME fabrique que les autres :
            // son périmètre est donc confronté au plan, exactement comme celui
            // d'un vrai passage. Le fabriquer à la main aurait contourné la seule
            // garantie que ces rapports portent.
            $this->rememberReport($share, ReconciliationReport::covering(
                $backend->name(),
                $plan,
                array_map(
                    static fn (string $path): NodeReconciliation => new NodeReconciliation(
                        $path,
                        FileBackendOutcome::EnAttente,
                    ),
                    $plan->nodePaths(),
                ),
            ));
        } catch (Throwable $e) {
            Log::error('NetworkShareService: réconciliation non enfilée', [
                'share_id' => $share->id,
                'directory_name' => $share->directory_name,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        ReconcileNetworkShareJob::dispatch((int) $share->id, $this->actor($performedBy));

        return true;
    }

    /**
     * DÉPROVISIONNE : révoque les octrois et sort la structure de l'espace exposé.
     *
     * Reste SYNCHRONE, contrairement au provisionnement. La raison est de sûreté :
     * l'appelant supprime la ligne juste après, et un répertoire encore exposé
     * pendant que sa ligne disparaît est atteignable par tous ceux qui y avaient
     * accès. Enfiler ce geste ouvrirait exactement la fenêtre que la séquence
     * existe pour fermer.
     */
    public function deprovision(NetworkShare $share, ?string $performedBy = null): bool
    {
        $performedBy = $this->actor($performedBy);

        try {
            $plan = $this->planFor($share);
            $report = $this->registry->forShare($share)->deprovision($plan);
        } catch (Throwable $e) {
            Log::error('NetworkShareService: déprovisionnement refusé', [
                'share_id' => $share->id,
                'directory_name' => $share->directory_name,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $revoked = count($report->converged()) === $report->count();

        $this->writeAudit('deprovision_share', $performedBy, $share, [
            'directory_name' => $share->directory_name,
            'nodes' => $report->count(),
            'converged' => count($report->converged()),
            'failures' => count($report->failures()),
            'success' => $revoked,
        ]);

        Cache::forget(self::REPORT_CACHE_PREFIX . $share->id);
        $this->forgetFailure($share);

        Log::info('NetworkShareService: déprovisionnement terminé', [
            'share_id' => $share->id,
            'directory_name' => $share->directory_name,
            'success' => $revoked,
        ]);

        return $revoked;
    }

    // =========================================================================
    // Relecture et écart
    // =========================================================================

    /** RELIT l'état, sans rien écrire. */
    public function inspect(NetworkShare $share): InspectionReport
    {
        return $this->registry->forShare($share)->inspect($this->planFor($share));
    }

    /**
     * ÉCART entre le désiré et le constaté, en vocabulaire de plan.
     *
     * Remplace l'audit de dérive de l'Epic 34 : les quatre statuts agrégés sont
     * conservés (un contrôleur d'environnement les consomme), mais le détail n'est
     * plus une liste de lignes de permission — c'est, par nœud, la liste des
     * sujets dont l'accès attendu et l'accès constaté diffèrent.
     *
     * Ne lève jamais : un répertoire illisible est un état, pas une exception à
     * faire remonter jusqu'à un écran.
     *
     * @return array{status:string,nodes:list<array<string,mixed>>,detail?:string}
     */
    public function computeDrift(NetworkShare $share): array
    {
        try {
            $plan = $this->planFor($share);

            return $this->comparator->compare($plan, $this->registry->forShare($share)->inspect($plan));
        } catch (Throwable $e) {
            Log::warning('NetworkShareService: audit d\'écart impossible', [
                'share_id' => $share->id,
                'directory_name' => $share->directory_name,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => PlanStateComparator::STATUS_ERROR,
                'nodes' => [],
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * Dernier rapport de réconciliation connu, en TABLEAU.
     *
     * Un rapport ne se reconstruit pas depuis un tableau sans repasser par sa
     * fabrique et son plan — c'est ce que la garde de la story 60.3 protège. Tant
     * qu'il s'agit de l'AFFICHER, le tableau suffit et personne n'a besoin de
     * l'objet.
     *
     * @return array{backend:string,scope:string,nodes:list<array<string,mixed>>}|null
     */
    public function lastReport(NetworkShare $share): ?array
    {
        $data = Cache::get(self::REPORT_CACHE_PREFIX . $share->id);

        return is_array($data) ? $data : null;
    }

    // =========================================================================
    // Interne
    // =========================================================================

    /** @throws \App\Exceptions\Filesystem\PlanResolutionException */
    private function planFor(NetworkShare $share): FilePlan
    {
        $share->loadMissing('assignments');

        return $this->projector->project($share);
    }

    private function rememberReport(NetworkShare $share, ReconciliationReport $report): void
    {
        $this->rememberArray($share, $report->toArray());
    }

    /**
     * Consigne un ÉCHEC DE PRÉPARATION — et surtout, ne le déguise pas en rapport.
     *
     * Fabriquer ici un rapport « tout rouge » serait plus commode pour l'écran,
     * mais il n'aurait été confronté à aucun plan : c'est précisément le
     * contournement de fabrique que la story 60.3 a fermé. La vérité de ce
     * moment, c'est « il n'y a pas de rapport, et voici pourquoi ». On l'écrit
     * telle quelle, et le rapport périmé est retiré.
     */
    private function rememberFailure(NetworkShare $share, string $reason): void
    {
        Cache::forget(self::REPORT_CACHE_PREFIX . $share->id);
        Cache::put(
            self::FAILURE_CACHE_PREFIX . $share->id,
            $reason,
            now()->addMinutes(self::REPORT_CACHE_MINUTES),
        );
    }

    private function forgetFailure(NetworkShare $share): void
    {
        Cache::forget(self::FAILURE_CACHE_PREFIX . $share->id);
    }

    /**
     * Raison du dernier échec de préparation, ou `null` s'il n'y en a pas eu
     * depuis la dernière réconciliation aboutie.
     */
    public function lastFailure(NetworkShare $share): ?string
    {
        $reason = Cache::get(self::FAILURE_CACHE_PREFIX . $share->id);

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /** @param array<string,mixed> $data */
    private function rememberArray(NetworkShare $share, array $data): void
    {
        Cache::put(self::REPORT_CACHE_PREFIX . $share->id, $data, now()->addMinutes(self::REPORT_CACHE_MINUTES));
    }

    private function actor(?string $performedBy): string
    {
        return $performedBy ?? (string) (auth()->user()?->getAuthIdentifier() ?? 'system');
    }

    /**
     * Trace applicative du geste. Best-effort : une trace qui échoue ne fait pas
     * échouer le geste qu'elle trace.
     *
     * @param  array<string, mixed>  $newValues
     */
    public function writeAudit(string $action, string $performedBy, NetworkShare $share, array $newValues): void
    {
        try {
            QuotaAuditLog::log(
                action: $action,
                performedBy: $performedBy,
                targetType: 'share',
                targetName: $share->directory_name,
                partition: QuotaRule::PARTITION_SAMBAEDU,
                oldValues: null,
                newValues: $newValues,
                quotaRuleId: null,
                fsApplied: true,
            );
        } catch (Throwable $e) {
            Log::error('NetworkShareService: écriture de la trace applicative échouée', [
                'action' => $action,
                'share_id' => $share->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
