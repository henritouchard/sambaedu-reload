<?php

declare(strict_types=1);

namespace App\Services\Filesystem;

use App\Enums\PlanNodeNature;
use App\Exceptions\Filesystem\PlanResolutionException;
use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\GroupNameNormalizer;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;

/**
 * Story 60.3 — un répertoire réseau PLAT, projeté en PLAN neutre.
 *
 * C'est le pont qui rend l'aperçu possible AVANT que la chaîne recette→arbre ne
 * soit accrochée. Un partage 34.x n'a pas d'arborescence : il EST sa racine,
 * avec des assignations. Le projeter donne donc un plan à UN nœud — et c'est
 * précisément pour ça que la racine devait devenir un nœud de première classe.
 *
 * **Ce que le projecteur ne fait PAS, et c'est le piège du chantier.** Il ne dérive
 * AUCUN nom système : ni nom de groupe Unix, ni login d'exécution, ni ligne de
 * permission. La coupe de neutralité passe AVANT la dérivation des permissions, y
 * compris ici. Les sujets sont des IDENTITÉS INTERNES (`users.id`,
 * `user_groups.id`) — la traduction vers ce que le backend connaît est le travail
 * du backend.
 *
 * **Il ne charge même pas les cibles.** Le type polymorphe et l'identifiant du
 * pivot suffisent à fabriquer un sujet : une seule requête, aucune jointure. Le
 * corollaire est une DIVERGENCE ASSUMÉE avec la dérivation de permissions
 * historique, qui SAUTE (en journalisant) une assignation dont le compte est
 * introuvable ou le nom indérivable, tandis que le plan la PORTE. Les deux
 * comportements sont défendables — l'un protège une exécution, l'autre décrit une
 * intention — et les départager demande d'avoir la référence de permissions en
 * face. C'est un legs NOMMÉ à la story 60.4.
 *
 * **Nature `contenu_libre` pour la racine**, et pas autre chose : le plan gouverne
 * les DROITS de la racine, jamais l'existence de son contenu. Un partage plat est
 * exactement cela — un dépôt dont personne ne modélise les enfants. Toute autre
 * nature ferait des fichiers déposés par les utilisateurs un écart à réconcilier.
 *
 * **Clôture VIDE, et c'est cohérent** : la clôture d'un nœud est l'ensemble des
 * rôles de la RECETTE sans octroi ici. Un partage plat n'a pas de recette et donc
 * pas de rôles ; il n'y a rien à refermer. Ce n'est pas un raccourci — c'est le
 * même calcul, appliqué à un ensemble de rôles vide.
 */
final class SharePlanProjector
{
    /**
     * Clé de recette RÉSERVÉE des plans de partage plat, hors catalogue de
     * recettes. Elle porte le « @ » pour la même raison que le jeton nominatif
     * des nœuds par membre : aucune recette réelle ne peut porter cette clé, donc
     * aucune collision n'est possible.
     */
    public const TEMPLATE_KEY = '@partage';

    /**
     * Rôle RÉSERVÉ porté par les octrois d'un partage plat.
     *
     * Un octroi doit référencer un rôle ; un partage plat n'en a aucun. Le jeton
     * en tient lieu sans jamais devenir un rôle de recette — un rôle réel
     * apparaîtrait dans les clôtures des autres nœuds et fabriquerait une
     * clôture sur un plan qui n'en a pas.
     */
    public const ASSIGNMENT_ROLE = '@assignation';

    /**
     * Le plan d'un partage plat : une racine, ses octrois.
     *
     * @throws PlanResolutionException si le nom de répertoire n'est pas projetable
     */
    public function project(NetworkShare $share): FilePlan
    {
        $directory = (string) $share->directory_name;

        // Échec EXPLICITE, jamais un plan partiel ou une racine bricolée : un plan
        // amputé se comparerait « conforme » à un état incomplet, et la détection
        // d'écart validerait silencieusement une fuite.
        if (! GroupNameNormalizer::isSafeSegment($directory)) {
            throw PlanResolutionException::make(sprintf(
                'le répertoire « %s » du partage « %s » n\'est pas un segment de chemin sûr : '
                . 'il ne peut pas servir de racine de plan.',
                $directory,
                (string) $share->name,
            ));
        }

        return new FilePlan(
            self::TEMPLATE_KEY,
            $directory,
            [],
            [
                new PlanNode(
                    PlanNode::ROOT_PATH,
                    (string) $share->name,
                    PlanNodeNature::ContenuLibre,
                    $this->grantsOf($share),
                ),
            ],
        );
    }

    /**
     * Story 60.5 — SURCHARGE D'INSTANCE : les assignations d'un partage issu d'une
     * recette ajoutent des octrois SUR SA RACINE.
     *
     * Un partage d'arbre tire ses audiences de sa recette. Mais l'administrateur
     * doit pouvoir en ouvrir l'accès à quelqu'un d'autre — un professeur
     * documentaliste, une direction — sans pour autant modifier la recette de
     * TOUTES les classes. C'est exactement ce que les assignations font déjà pour
     * les partages ordinaires : on les réemploie, telles quelles, sur la racine.
     *
     * **Union AU PLUS PERMISSIF, et rien d'autre.** Deux octrois qui visent le même
     * sujet se fondent en un seul, au niveau le plus élevé des deux. C'est la règle
     * additive de l'epic — jamais un sous-ensemble silencieux — et c'est aussi ce
     * qui empêche une même audience d'être écrite deux fois : un doublon d'entrée
     * ferait relire l'état comme non conforme à chaque passage, et le partage se
     * réécrirait indéfiniment sans jamais converger.
     *
     * Le rôle d'origine de l'octroi conservé est celui du PLAN quand il existe : la
     * recette est la description de référence, l'assignation ne fait que l'élargir.
     */
    public function withInstanceGrants(FilePlan $plan, NetworkShare $share): FilePlan
    {
        $extra = $this->grantsOf($share);
        if ($extra === []) {
            return $plan;
        }

        $root = $plan->node(PlanNode::ROOT_PATH);
        if ($root === null) {
            // Un plan sans racine ne peut pas recevoir de surcharge de racine. Ce
            // n'est pas un état atteignable par les recettes du dépôt ; on le dit
            // en ne faisant rien, plutôt qu'en fabriquant un nœud que la recette
            // n'a pas décrit.
            return $plan;
        }

        /** @var array<string, PlanGrant> $merged */
        $merged = [];
        foreach ([...$root->grants, ...$extra] as $grant) {
            $key = $grant->subject->sortKey();
            $kept = $merged[$key] ?? null;

            if ($kept === null) {
                $merged[$key] = $grant;

                continue;
            }
            // Story 62.4 — l'union est celle des ENSEMBLES DE VERBES. Le niveau
            // n'est plus une échelle à deux barreaux qu'on pouvait comparer : deux
            // octrois peuvent être incomparables (« lire+éditer » et
            // « lire+créer »), et le plus permissif des deux est leur RÉUNION.
            // C'est la règle additive de l'epic, dite dans le vocabulaire qui la
            // rend exacte.
            $union = array_values(array_unique([...$kept->verbs, ...$grant->verbs]));
            if ($union !== $kept->verbs) {
                $merged[$key] = new PlanGrant($kept->roleKey, $kept->subject, $union, $kept->suspendable);
            }
        }

        $nodes = [];
        foreach ($plan->nodes as $node) {
            $nodes[] = $node->path === PlanNode::ROOT_PATH
                ? new PlanNode(
                    $node->path,
                    $node->label,
                    $node->nature,
                    array_values($merged),
                    $node->active,
                    $node->plafond,
                    $node->closure,
                )
                : $node;
        }

        return new FilePlan($plan->templateKey, $plan->rootPath, $plan->roles, $nodes, $plan->anchor);
    }

    /**
     * Les octrois de la racine, dérivés des assignations.
     *
     * **Une assignation de parc ne produit AUCUN octroi** — invariant du modèle à
     * deux axes (décision Henri, 2026-06-29) : un parc rend le lecteur VISIBLE, il
     * ne donne aucun accès. L'exprimer comme un octroi serait faux dans le plan
     * comme sur le disque, et un backend distant, lui, l'appliquerait pour de bon.
     *
     * @return list<PlanGrant>
     */
    private function grantsOf(NetworkShare $share): array
    {
        $grants = [];

        foreach ($share->assignments as $assignment) {
            $subject = $this->subjectOf($assignment);
            if ($subject === null) {
                continue;
            }

            $grants[] = new PlanGrant(
                self::ASSIGNMENT_ROLE,
                $subject,
                self::verbsOf($assignment),
            );
        }

        return $grants;
    }

    /**
     * Story 62.4 — LA TRADUCTION DU BORD : une assignation BINAIRE devient une
     * liste de VERBES.
     *
     * **Les assignations restent binaires, et ce n'est pas un retard.** Le pivot
     * `network_share_assignables` décrit l'autre axe du modèle — le MONTAGE d'un
     * répertoire réseau chez ses destinataires — et son écran ne propose que deux
     * niveaux, « Lire » et « Modifier ». Y faire entrer quatre verbes reviendrait à
     * demander à l'administrateur de composer une matrice là où il choisit un
     * montage. La finesse appartient au PLAN, qui décrit un arbre de dossiers ;
     * elle se règlera à l'écran des recettes (story 62.6).
     *
     * La frontière a donc DEUX bords, et la traduction vit sur chacun :
     *  - ici, assignation → plan : le mappage Q3, celui qui ne retire rien —
     *    « Modifier » donne les QUATRE verbes, « Lire » donne `lire` seul ;
     *  - à l'autre bord ({@see \App\Services\Filesystem\DirectoryTemplateService}),
     *    recette → assignation : une liste de verbes redevient « Modifier » dès
     *    qu'elle porte un verbe de mutation.
     *
     * Les deux sont TOTALES : toute valeur d'un côté a une image de l'autre.
     *
     * @return list<string>
     */
    private static function verbsOf(NetworkShareAssignable $assignment): array
    {
        return $assignment->isWritable() ? PlanGrant::VERBS : [PlanGrant::VERB_LIRE];
    }

    /**
     * Le SUJET d'une assignation — identité interne, sans jamais charger la cible.
     *
     * `null` pour les mailles qui n'octroient rien (le parc), et pour tout type
     * inattendu : le pivot est polymorphe et n'a pas de clé étrangère, donc une
     * ligne portant un type hors vocabulaire est possible en base. On l'ignore
     * plutôt que de deviner ce qu'elle voulait dire.
     */
    private function subjectOf(NetworkShareAssignable $assignment): ?PlanSubject
    {
        $id = (int) $assignment->assignable_id;
        if ($id <= 0) {
            return null;
        }

        return match ((string) $assignment->assignable_type) {
            User::class => PlanSubject::user($id),
            UserGroup::class => PlanSubject::group($id),
            default => null,
        };
    }
}
