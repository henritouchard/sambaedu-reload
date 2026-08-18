<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend;

use App\Enums\FileBackendName;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;

/**
 * Story 60.3 — LA LIGNE DE CONTRAT du plan de fichiers.
 *
 * Au-dessus : le plan, neutre, comparable, portable. En dessous : une autorité
 * d'écriture qui traduit ce plan dans SON modèle de permissions. Le contrat existe
 * pour que l'autorité soit une implémentation PARMI D'AUTRES — c'est ce qui rendra
 * le retrait du serveur de fichiers historique bon marché le jour venu (décision
 * Q-D, 2026-08-04) : basculer une valeur de colonne et lancer une migration
 * explicite, pas réécrire le domaine.
 *
 * ---------------------------------------------------------------------------
 * **FORME DISTANTE, DÈS LE PREMIER JOUR.** Aucune méthode ne rend `bool` ni
 * `void`. Le premier backend est local et synchrone ; les suivants sont des API
 * distantes, partiellement asynchrones, avec des réussites partielles. Un contrat
 * dessiné sur le cas local se casse au premier backend distant — et il se casse
 * SILENCIEUSEMENT, parce qu'un `true` peut être rendu par une API qui n'a rien
 * fait. C'est le patron déjà en service dans le dépôt pour l'état désiré du parc :
 * un statut PAR RESSOURCE, jamais un verdict global.
 *
 * ---------------------------------------------------------------------------
 * **LES CINQ CONTRAINTES MESURÉES**, chacune adossée au test qui la tient — aucune
 * n'est une promesse de commentaire :
 *
 *  1. **Un statut PAR NŒUD, incluant « non exprimable ».** Mesuré : un octroi posé
 *     sur un ancêtre propage au sous-arbre ; l'instruction de retrait sur le
 *     dossier privé des enseignants est acceptée `200 OK` sans effet ; la relecture
 *     rend un accès là où on demandait zéro. Un verdict global n'aurait jamais vu
 *     cette fuite. → {@see ReconciliationReport} (aucun booléen primaire,
 *     complétude validée à la construction) ;
 *     test `\Tests\Unit\Services\Filesystem\Backend\FakePropagatingBackendTest`.
 *
 *  2. **La relecture BALAIE les nœuds du plan, racine comprise.** Mesuré : une
 *     lecture unique de sous-arbre rend les enfants mais pas la racine.
 *     → {@see InspectionReport::covering()} et {@see \App\Services\Filesystem\Plan\PlanNode::ROOT_PATH} ;
 *     mêmes tests.
 *
 *  3. **L'adaptateur NORMALISE l'idempotence.** Mesuré : trois sémantiques natives
 *     différentes pour « c'était déjà fait ». Aucune ne remonte : « déjà conforme »
 *     est un ÉTAT ({@see \App\Enums\FileBackendOutcome::Conforme}), et le
 *     vocabulaire ne contient aucun code de transport — règle d'architecture
 *     `\Tests\Architecture\PlanNamespaceIsolationTest`. La normalisation n'avale
 *     aucun échec net : les erreurs distinguables rendent `echec` avec leur cause.
 *
 *  4. **Le plafond sait se DÉCLINER sans échouer**, et il a DEUX raisons de
 *     décliner — voir ci-dessous. → {@see quota()}.
 *
 *  5. **La CLÔTURE du plan traverse la ligne intacte.** Un nœud porte les rôles qui
 *     n'ont RIEN reçu ici ; le serveur de fichiers historique l'ignorera (il
 *     n'écrit rien pour elle), un backend à propagation la matérialisera en règle
 *     de masque. Rien, au passage du contrat, ne la recalcule, ne la filtre ni ne
 *     la résume — le plan est transmis tel quel, et `\Tests\Unit\Services\Filesystem\Backend\FakePropagatingBackendTest`
 *     vérifie qu'un backend la retrouve ET retrouve les sujets du rôle clos via
 *     {@see FilePlan::$roles}.
 *
 * ---------------------------------------------------------------------------
 * **NON SUPPORTÉ ≠ NON IMPLÉMENTÉ** (correction Henri, 2026-08-04). Le serveur de
 * fichiers historique SAIT plafonner une arborescence — s'il ne le fait pas, c'est
 * que NOUS ne l'avons pas branché, et la story qui le ferait est suspendue. C'est
 * une dette de notre code (`non_implemente`, temporaire), pas une limite de son
 * modèle (`non_exprimable`, permanent). Écrire « non supporté » dans les deux cas
 * mettrait une contre-vérité dans le code, et l'UI ne saurait plus quoi montrer à
 * l'administrateur. Le vocabulaire porte la nuance
 * ({@see \App\Enums\FileBackendOutcome}), les deux prédicats la rendent
 * interrogeable, et l'affichage les distingue.
 *
 * ---------------------------------------------------------------------------
 * **CE QUE CE CONTRAT N'EST PAS.** Ce n'est pas un contrat de fichiers. Les
 * abstractions de système de fichiers du framework couvrent les OPÉRATIONS sur les
 * fichiers (lire, écrire, lister) et n'abstraient PAS les permissions — or c'est
 * la partie difficile, et SE5 ne crée aucun fichier : il provisionne une STRUCTURE
 * et des DROITS. S'y brancher aurait donné une dépendance qui ne sert à rien et une
 * fausse impression de portabilité. Une règle d'architecture NOMMÉE l'interdit.
 *
 * **Pas de déclaration de capacités non plus.** La nuance vit dans le vocabulaire
 * de résultat, où elle est ADOSSÉE à un fait constaté, pas dans une table
 * déclarative qu'un backend pourrait remplir de travers sans que rien ne le
 * contredise.
 *
 * {@see rendering()} n'est pas cette table, et la différence est exactement celle
 * qui vient d'être posée : elle n'ÉNONCE rien, elle fait TOURNER la traduction de
 * verbes du backend sur un octroi donné. Sa réponse ne peut donc pas s'écarter de
 * ce qui sera écrit sans que l'écriture elle-même ait changé.
 *
 * **Pas d'auteur de l'action.** L'audit applicatif est un savoir d'implémentation.
 * Le contrat dit l'état désiré et ce qu'il en est advenu, pas la traçabilité.
 */
interface FileBackend
{
    /** Le nom sous lequel ce backend est enregistré et stocké en colonne. */
    public function name(): FileBackendName;

    /**
     * Fait converger le backend vers le plan, et rend un INSTANTANÉ par nœud.
     *
     * Ce n'est pas un fait accompli : le rapport peut contenir des entrées
     * `en_attente` (réconciliation engagée, pas achevée), des déclins et des
     * échecs, tous voisins d'entrées appliquées. Le rapport couvre EXACTEMENT les
     * nœuds du plan — l'omission d'un nœud est impossible par construction.
     *
     * Idempotence attendue : un second passage sur un backend déjà conforme rend
     * `conforme` partout, sans écriture et sans erreur.
     *
     * **CONVENTION DE PRÉCÉDENCE — un nœud, une entrée, plusieurs gestes.**
     * Un seul nœud du plan se traduit, chez la plupart des backends, en PLUSIEURS
     * gestes natifs (créer le dossier, poser un octroi, en refermer un autre…).
     * L'entrée de rapport est unique : le backend doit donc *effondrer* la séquence
     * en un état, et l'ordre dans lequel il le fait est ce qui rend deux backends
     * comparables. Le contrat ne l'IMPOSE pas — les backends n'ont ni la même
     * granularité de gestes ni les mêmes modes d'échec, et une classe de base
     * commune serait une fausse abstraction. Mais il l'ÉNONCE, parce que deux
     * backends qui choisiraient chacun le leur rapporteraient différemment la même
     * situation composite, et le premier à s'en apercevoir serait l'admin :
     *
     *     `echec`  >  `non_exprimable`  >  `non_implemente`  >  `applique`  >  `conforme`
     *
     * Ce qui se lit : un échec ne se laisse jamais masquer par un succès partiel ;
     * ce que le modèle du backend ne sait pas dire prime sur ce qu'on n'a pas
     * codé ; et « j'ai changé quelque chose » prime sur « il n'y avait rien à
     * faire ». `en_attente` sort de cette échelle — c'est un état du geste, pas de
     * son résultat : un nœud dont un geste reste en cours est `en_attente` tant
     * qu'aucun geste n'a échoué.
     */
    public function provision(FilePlan $plan): ReconciliationReport;

    /**
     * Révoque les octrois du plan et sort sa structure de l'espace exposé.
     *
     * **Ce que cette méthode ne fait PAS : détruire des données.** C'est une
     * contrainte d'epic (aucune suppression implicite n'est exprimable), et elle
     * n'est tenue ici par personne : le backend d'aperçu n'exécute rien, et aucun
     * backend n'exécute quoi que ce soit dans cette story. Ce docblock DÉCRIT donc
     * l'obligation que la story 60.4 devra tenir en implémentant le premier
     * backend réel — il ne prétend pas qu'elle est déjà garantie.
     *
     * Comme {@see provision()} : rapport par nœud, périmètre = les nœuds du plan.
     */
    public function deprovision(FilePlan $plan): ReconciliationReport;

    /**
     * RELIT l'état du backend, un nœud du plan après l'autre, racine comprise.
     *
     * Rend ce qui EST, en vocabulaire de plan (sujets par identité interne, accès
     * `ro|rw`) — jamais un nom système, jamais un identifiant produit par le
     * backend. La comparaison avec le désiré n'est pas de son ressort.
     */
    public function inspect(FilePlan $plan): InspectionReport;

    /**
     * Applique les PLAFONDS DE ZONE portés par les nœuds du plan.
     *
     * Périmètre : les seuls nœuds à plafond. Un plan sans plafond donne un rapport
     * VIDE et valide.
     *
     * **Décliner n'est PAS échouer, et il y a deux façons de décliner** — c'est
     * exactement la nuance qu'un implémenteur pressé écrase en « pas supporté »
     * pour les deux, et c'est la correction que cette story porte :
     *
     *  - `non_exprimable` — le MODÈLE du backend n'a pas le concept de plafond de
     *    zone. Cas mesuré au sondage : un backend distant dont le quota est par
     *    UTILISATEUR, pas par dossier (relecture : quota illimité sur un compte
     *    élève). Permanent. Aucune story ne le rendra possible ; l'administrateur
     *    doit choisir un autre backend pour ce besoin. L'UI MASQUE le réglage.
     *  - `non_implemente` — le mécanisme EXISTE côté backend et SE5 ne le pilote
     *    pas. Cas d'aujourd'hui : le serveur de fichiers historique sait plafonner
     *    une arborescence (quotas de projet, volume monté et vérifié en ouverture
     *    d'epic), la story qui le brancherait est SUSPENDUE. Temporaire, propriété
     *    de notre code. L'UI GRISE le réglage : indisponible pour l'instant.
     *
     * Le backend d'aperçu, lui, répond `non_execute` : ce n'est ni une limite de
     * modèle ni une dette de code, ne rien faire est sa fonction.
     *
     * **Aucune infrastructure de quota n'entre dans cette story** — le plafond est
     * STRUCTURANT dans le contrat, et exécuté par personne ici.
     */
    public function quota(FilePlan $plan): ReconciliationReport;

    /**
     * Story 60.5 — OÙ ce plan vit, dans les termes de ce backend, POUR AFFICHAGE.
     *
     * **Pourquoi le contrat porte cette question, et pas l'orchestrateur.** Depuis
     * que SE5 gouverne deux zones disjointes, « où est mon partage ? » est devenu
     * une vraie question d'administration : c'est l'endroit où l'on va vérifier les
     * droits à la main, et l'endroit qu'il faut autoriser dans la liste blanche du
     * système. Or seul un backend sait répondre — l'orchestrateur, lui, ne connaît
     * qu'une zone logique et un chemin relatif, et c'est exactement ce qui le rend
     * portable. Faire remonter la réponse par le contrat est donc la seule façon de
     * l'afficher sans redescendre la ligne de coupe d'un cran.
     *
     * **C'est une chaîne d'AFFICHAGE, pas une adresse à réutiliser.** Rien du
     * domaine ne doit la consommer pour agir : un backend distant y répondra une
     * adresse qui n'a aucun sens pour un système de fichiers local. `null` quand le
     * plan n'a pas d'emplacement — refusé par la garde du backend, ou backend qui
     * n'écrit nulle part.
     */
    public function location(FilePlan $plan): ?string;

    /**
     * Ce que ce backend RENDRAIT de cet octroi, posé sur ce nœud — sans rien
     * écrire, ni rien lire de l'instance.
     *
     * **À quoi ça sert, et pourquoi ça ne pouvait pas rester au-dessus de la
     * ligne.** L'écran qui compose une recette doit dire à l'administrateur ce que
     * son octroi produira. Tant qu'une seule autorité exécutait des arborescences,
     * l'écran pouvait interroger sa déclaration par son nom de classe ; dès la
     * seconde, cette question devient celle du contrat — sinon l'écran annonce les
     * limites de POSIX sur un arbre servi ailleurs, c'est-à-dire des dégradations
     * qui n'auront pas lieu et des combinaisons refusées à la saisie alors qu'elles
     * s'écriraient exactement.
     *
     * **Le NŒUD entier, pas seulement l'octroi.** Ce qu'un backend rend d'un octroi
     * peut dépendre de ses VOISINS : un mécanisme de nœud posé pour approcher une
     * découpe vaut pour tout le monde et devient impossible dès qu'un autre octroi
     * porterait le verbe qu'il retire. Passer l'octroi seul obligerait l'appelant à
     * rejouer cette condition — donc à la recopier, donc à l'oublier.
     *
     * **Aucun effet de bord, aucun appel distant.** C'est une question posée à un
     * modèle de permissions, pas à une instance : elle doit rester appelable à
     * chaque frappe d'un formulaire.
     */
    public function rendering(PlanNode $node, PlanGrant $grant): GrantRendering;
}
