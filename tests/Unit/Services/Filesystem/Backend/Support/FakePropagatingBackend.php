<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend\Support;

use App\Enums\FileBackendName;
use App\Services\Filesystem\Backend\FileBackend;
use App\Services\Filesystem\Backend\InspectionReport;
use App\Services\Filesystem\Backend\NodeObservation;
use App\Services\Filesystem\Backend\NodeReconciliation;
use App\Services\Filesystem\Backend\ObservedGrant;
use App\Services\Filesystem\Backend\ReconciliationReport;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;

/**
 * Story 60.3 — LE DOUBLE À PROPAGATION : la transcription, en code exécutable,
 * de ce qui a été MESURÉ contre une instance réelle en ouverture d'epic.
 *
 * **Il n'invente rien.** Chaque comportement ci-dessous est référencé à sa mesure
 * dans `_bmad-output/ultradev/60-0-spike-nextcloud.md`. Là où le sondage n'a pas
 * mesuré, ce double ne dit rien.
 *
 * **Pourquoi il existe à côté du backend d'aperçu.** Le backend d'aperçu
 * n'exécutant rien, il satisfait n'importe quel contrat — y compris un mauvais. Il
 * ne peut donc pas prouver que les cinq contraintes du sondage sont TENUES. Ce
 * double, lui, propage comme le modèle mesuré propage, accepte sans effet ce que
 * le modèle mesuré accepte sans effet, et relit ce que le modèle mesuré relit. Il
 * tourne partout, en intégration continue comprise.
 *
 * **Il n'est pas non plus le squelette jetable.** Celui-là parle à l'instance
 * réelle et prouve que les signatures sont IMPLÉMENTABLES ; il vit hors de la
 * suite par défaut. Les deux se complètent, aucun ne remplace l'autre.
 *
 * **Sur son nom.** Il emprunte un nom du vocabulaire fermé, faute d'en avoir un à
 * lui : un double de test n'a pas à ouvrir la colonne `backend`. Ce qu'il
 * transcrit, ce sont des COMPORTEMENTS mesurés, pas l'identité d'un produit — et
 * l'identité, elle, arrivera par code en Epic 61.
 *
 * ---------------------------------------------------------------------------
 * LES MESURES TRANSCRITES (référence : `60-0-spike-nextcloud.md`)
 *
 *  - §1, l. 29-45 — Un octroi posé sur un ANCÊTRE propage à tout le sous-arbre.
 *    L'instruction de retrait sur le dossier privé est acceptée `200 OK` et n'a
 *    AUCUN effet ; la relecture rend ensuite un accès en lecture là où on
 *    demandait zéro. ⇒ un nœud dont la clôture nomme un rôle octroyé plus haut est
 *    `non_exprimable`, jamais `conforme` ni `applique`.
 *  - §2, l. 56-60 — Une lecture unique du sous-arbre rend les sous-chemins MAIS
 *    PAS la racine. ⇒ la relecture BALAIE les nœuds du plan, racine comprise.
 *  - §3, l. 64-73 — Trois sémantiques natives pour « c'était déjà fait » (création
 *    de dossier rejouée, création de groupe existant, partage identique réémis),
 *    et des échecs NETS et distinguables (cible inconnue, chemin inexistant).
 *    ⇒ les trois se normalisent en `conforme`, les échecs restent des échecs.
 *  - §4, l. 101-103 — Le quota du modèle mesuré est PAR UTILISATEUR, pas par
 *    dossier. ⇒ le plafond de zone est `non_exprimable` : une limite de MODÈLE,
 *    permanente, pas une dette de notre code.
 */
final class FakePropagatingBackend implements FileBackend
{
    /** Création d'un dossier rejouée — mesuré : `405 Method Not Allowed`. */
    public const NATIVE_DIRECTORY_EXISTS = 'directory_exists';

    /** Création d'un groupe existant — mesuré : statut natif « group exists ». */
    public const NATIVE_GROUP_EXISTS = 'group_exists';

    /** Partage identique réémis — mesuré : succès avec le MÊME identifiant. */
    public const NATIVE_SHARE_DEDUPLICATED = 'share_deduplicated';

    /** Échec net mesuré : compte cible inconnu. */
    public const CAUSE_UNKNOWN_ACCOUNT = 'compte cible introuvable côté backend';

    /** Échec net mesuré : groupe cible inconnu. */
    public const CAUSE_UNKNOWN_GROUP = 'groupe cible introuvable côté backend';

    /** Échec net mesuré : chemin inexistant. */
    public const CAUSE_MISSING_PATH = 'chemin inexistant côté backend';

    /** @var array<string,bool> chemins déjà écrits par ce double */
    private array $written = [];

    /**
     * Sémantiques natives effectivement RENCONTRÉES au dernier rejeu, par chemin.
     * Exposé pour que le test puisse vérifier que trois sémantiques DIFFÉRENTES
     * ont bien été traversées avant de constater qu'elles rendent un seul état.
     *
     * @var array<string,string>
     */
    public array $nativeSemanticsSeen = [];

    /**
     * @param  array<string,string>  $replaySemantics  chemin => sémantique native au rejeu
     * @param  array<string,string>  $failures  chemin => cause d'un échec NET
     */
    public function __construct(
        private readonly array $replaySemantics = [],
        private readonly array $failures = [],
    ) {}

    public function name(): FileBackendName
    {
        return FileBackendName::Preview;
    }

    public function provision(FilePlan $plan): ReconciliationReport
    {
        $this->nativeSemanticsSeen = [];
        $entries = [];

        foreach ($plan->nodes as $node) {
            $entries[] = $this->reconcile($plan, $node);
        }

        return ReconciliationReport::covering($this->name(), $plan, $entries);
    }

    public function deprovision(FilePlan $plan): ReconciliationReport
    {
        $entries = [];

        foreach ($plan->nodes as $node) {
            $cause = $this->failures[$node->path] ?? null;
            if ($cause !== null) {
                $entries[] = NodeReconciliation::echec($node->path, $cause);

                continue;
            }

            $entries[] = array_key_exists($node->path, $this->written)
                ? NodeReconciliation::applique($node->path, 'Octrois révoqués ; les données restent en place.')
                : NodeReconciliation::conforme($node->path, 'Rien à révoquer.');

            unset($this->written[$node->path]);
        }

        return ReconciliationReport::covering($this->name(), $plan, $entries);
    }

    /**
     * BALAYAGE — un nœud du plan après l'autre, RACINE COMPRISE.
     *
     * La relecture rend les octrois du nœud ET ceux hérités de ses ancêtres :
     * c'est la propagation mesurée, et c'est ce qui fait apparaître, sur le
     * dossier privé, un accès que le plan n'y a jamais écrit.
     */
    public function inspect(FilePlan $plan): InspectionReport
    {
        $observations = [];

        foreach ($plan->nodes as $node) {
            $cause = $this->failures[$node->path] ?? null;
            if ($cause !== null) {
                $observations[] = NodeObservation::echec($node->path, $cause);

                continue;
            }

            $grants = [];
            foreach ($this->effectiveGrantsOf($plan, $node) as $grant) {
                $grants[] = new ObservedGrant($grant->subject, $grant->access);
            }

            $observations[] = NodeObservation::observed(
                $node->path,
                $grants,
                // Plafond : le modèle mesuré ne sait pas plafonner un DOSSIER, il
                // n'y a donc rien à regarder — et le double ne prétend pas l'avoir
                // regardé. L'interprétation (permanent) se lit sur `quota()`.
                null,
                false,
            );
        }

        return InspectionReport::covering($this->name(), $plan, $observations);
    }

    /**
     * Le plafond de zone n'existe PAS dans le modèle mesuré : son quota est par
     * UTILISATEUR. Déclin PERMANENT — le modèle n'a pas le concept.
     */
    public function quota(FilePlan $plan): ReconciliationReport
    {
        return ReconciliationReport::coveringCapped(
            $this->name(),
            $plan,
            array_map(
                static fn (string $path): NodeReconciliation => NodeReconciliation::nonExprimable(
                    $path,
                    'Le quota de ce backend est par utilisateur, jamais par dossier : un plafond de zone '
                    . 'n\'est pas exprimable dans son modèle.',
                ),
                $plan->cappedNodePaths(),
            ),
        );
    }

    // =========================================================================
    // Transcription des mesures
    // =========================================================================

    private function reconcile(FilePlan $plan, PlanNode $node): NodeReconciliation
    {
        $cause = $this->failures[$node->path] ?? null;
        if ($cause !== null) {
            // La normalisation de l'idempotence n'avale AUCUN échec net : les
            // erreurs distinguables mesurées restent des échecs, avec leur cause.
            return NodeReconciliation::echec($node->path, $cause);
        }

        $leaked = $this->unclosableRoles($plan, $node);
        if ($leaked !== []) {
            return NodeReconciliation::nonExprimable(
                $node->path,
                sprintf(
                    'Octroi hérité d\'un ancêtre, non refermable : le rôle « %s » n\'a aucun octroi ici, '
                    . 'mais son accès se propage depuis un dossier parent. L\'instruction de retrait est '
                    . 'acceptée sans effet par ce backend.',
                    implode(', ', $leaked),
                ),
            );
        }

        if (array_key_exists($node->path, $this->written)) {
            // REJEU. Trois sémantiques natives, un seul état : « déjà conforme »
            // est un état du contrat, pas un code du backend.
            $semantic = $this->replaySemantics[$node->path] ?? self::NATIVE_DIRECTORY_EXISTS;
            $this->nativeSemanticsSeen[$node->path] = $semantic;

            return NodeReconciliation::conforme($node->path);
        }

        $this->written[$node->path] = true;

        return NodeReconciliation::applique($node->path);
    }

    /**
     * Les rôles que ce nœud CLÔT mais qu'un ancêtre octroie : ceux dont l'accès
     * fuit, et que ce backend ne sait pas refermer.
     *
     * @return list<string>
     */
    private function unclosableRoles(FilePlan $plan, PlanNode $node): array
    {
        if ($node->closure === []) {
            return [];
        }

        $inherited = [];
        foreach ($this->ancestorsOf($plan, $node) as $ancestor) {
            foreach ($ancestor->activeGrants() as $grant) {
                $inherited[$grant->roleKey] = true;
            }
        }

        return array_values(array_filter(
            $node->closure,
            static fn (string $role): bool => isset($inherited[$role]),
        ));
    }

    /**
     * Octrois EFFECTIFS d'un nœud : les siens, plus ceux hérités des ancêtres.
     *
     * @return list<PlanGrant>
     */
    private function effectiveGrantsOf(FilePlan $plan, PlanNode $node): array
    {
        $grants = $node->activeGrants();

        foreach ($this->ancestorsOf($plan, $node) as $ancestor) {
            foreach ($ancestor->activeGrants() as $grant) {
                $grants[] = $grant;
            }
        }

        return array_values($grants);
    }

    /**
     * Les nœuds ANCÊTRES de `$node` dans le plan, du plus proche à la racine.
     *
     * @return list<PlanNode>
     */
    private function ancestorsOf(FilePlan $plan, PlanNode $node): array
    {
        if ($node->path === PlanNode::ROOT_PATH) {
            return [];
        }

        $ancestors = [];
        $segments = explode('/', $node->path);
        array_pop($segments);

        while ($segments !== []) {
            $ancestor = $plan->node(implode('/', $segments));
            if ($ancestor !== null) {
                $ancestors[] = $ancestor;
            }
            array_pop($segments);
        }

        $root = $plan->node(PlanNode::ROOT_PATH);
        if ($root !== null) {
            $ancestors[] = $root;
        }

        return $ancestors;
    }
}
