<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\OpenCloud;

use App\Enums\FileBackendName;
use App\Enums\FileBackendOutcome;
use App\Exceptions\OpenCloud\OpenCloudConfigurationException;
use App\Services\Filesystem\Backend\FileBackend;
use App\Services\Filesystem\Backend\GrantRendering;
use App\Services\Filesystem\Backend\InspectionReport;
use App\Services\Filesystem\Backend\NodeObservation;
use App\Services\Filesystem\Backend\NodeReconciliation;
use App\Services\Filesystem\Backend\ObservedGrant;
use App\Services\Filesystem\Backend\ReconciliationReport;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\OpenCloud\OpenCloudConnectionConfig;
use App\Services\OpenCloud\OpenCloudGraphTransport;
use App\Services\OpenCloud\OpenCloudResult;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * LE TROISIÈME BACKEND RÉEL : un espace de projet OpenCloud, derrière la MÊME
 * ligne de contrat.
 *
 * Le modèle d'implémentation est celui des deux backends existants, à
 * l'identique : mêmes signatures, MÊME ORDRE D'EFFONDREMENT, lecture avant
 * écriture, verrou de passage, rapport couvrant exactement les nœuds du plan.
 *
 * ---------------------------------------------------------------------------
 * **LA CONVENTION DE PRÉCÉDENCE — le legs nommé du contrat, tenu ici aussi.**
 *
 *     `echec` > `non_exprimable` > `non_implemente` > `applique` > `conforme`
 *
 * Trois backends, un seul ordre : c'est ce qui rend trois rapports comparables. Un
 * succès partiel ne masque jamais un échec ; ce que le modèle ne sait pas dire
 * prime sur ce qu'on n'a pas codé ; « j'ai changé quelque chose » prime sur « il
 * n'y avait rien à faire ». `en_attente` ne sort JAMAIS d'ici : ce backend est
 * synchrone — c'est l'orchestrateur qui, au-dessus, dit « engagé, pas achevé ».
 *
 * ---------------------------------------------------------------------------
 * **L'ARCHITECTURE DE CLÔTURE : ON N'OUVRE PAS, PLUTÔT QUE DE REFERMER.**
 *
 * Relevé du 2026-08-13 contre l'instance réelle, en quatre points :
 *
 *  1. un octroi posé sur la racine PROPAGE à tout le sous-arbre (le destinataire
 *     obtient `207` partout) ;
 *  2. cette propagation n'est **pas nommée** à la relecture des descendants : leur
 *     liste d'octrois est VIDE ;
 *  3. on ne peut **pas** la refermer : l'action de refus rend
 *     `400 « resharing not supported »`, et le rôle de refus de l'autre fork rend
 *     `400 … 'available_role' tag` ;
 *  4. mais **sans octroi à la racine**, un octroi posé sur le seul `_travail`
 *     donne `207` sur `_travail` et **`404` sur la racine comme sur les dossiers
 *     voisins** — le destinataire ne les voit même pas, et son client les trouve
 *     par le lecteur virtuel de partages.
 *
 * D'où l'architecture retenue : **n'octroyer qu'aux nœuds**. Ce n'est pas une
 * version dégradée de la clôture par masque de l'autre produit : c'est un refus
 * PAR CONSTRUCTION, et il est plus fort — il n'y a rien à soustraire, donc rien
 * qui puisse survivre à la soustraction.
 *
 * **La contrepartie est dite, pas cachée.** Si le plan octroie sur un ANCÊTRE
 * d'un nœud et referme un rôle sur ce nœud, la clôture n'est pas obtenable : le
 * nœud rend `non_exprimable` en nommant les principaux dont l'accès survit et
 * l'ancêtre d'où il vient. C'est un RÉSULTAT acceptable de ce backend, pas un
 * défaut — ce qui ne le serait pas, c'est un cloisonnement affiché qui n'existe
 * pas.
 *
 * **La propagation appartient à l'OCTROI POSÉ SUR UN ITEM, pas à la racine.** Le
 * relevé le dit dans sa propre colonne de preuve : `PROPFIND …/_travail` rend
 * `207` avec les hrefs `_travail/` **et** `_travail/devoirs/`. La racine n'est
 * qu'un cas particulier — le plus large, parce que son sous-arbre est le plan
 * entier. Toute vérification de clôture relit donc CHAQUE ancêtre présent au
 * plan, et non la seule racine : à la profondeur 2, la différence est un
 * cloisonnement affiché qui n'existe pas.
 *
 * ---------------------------------------------------------------------------
 * **L'ORDRE DES GESTES EST UN ORDRE DE SÛRETÉ, pas seulement de dépendance.**
 *
 *  1. adopter ou créer l'espace — **sur l'inventaire RELU**, jamais en créant à
 *     l'aveugle : mesuré, deux créations du même nom produisent DEUX espaces ;
 *  2. assurer les groupes du plan et **RETIRER** les appartenances périmées
 *     (geste qui RESTREINT) ;
 *  3. créer l'arborescence, un niveau à la fois, du plus haut au plus bas (une
 *     création dont le parent manque rend `409`) ;
 *  4. réconcilier les octrois des nœuds NON RACINE, et pour chacun : retirer
 *     d'abord, poser ensuite ;
 *  5. réconcilier les octrois de la RACINE — **le geste le plus large du plan**,
 *     puisque lui seul propage à tout le sous-arbre ;
 *  6. **en DERNIER**, AJOUTER les appartenances manquantes. Le seul geste qui
 *     élargit sans rien restreindre est le dernier : si la séquence s'interrompt,
 *     elle s'interrompt du côté fermé.
 *
 * ---------------------------------------------------------------------------
 * **L'IDEMPOTENCE EST VRAIE, ET ELLE SE JOUE SUR LE RELU.** Chaque écriture est
 * précédée d'une lecture, et la comparaison porte sur les valeurs RELUES en
 * IGNORANT les champs que le serveur ajoute ({@see ObservedPermission}). Cet epic
 * a rencontré le piège trois fois sur l'autre produit ; comparer l'envoyé au relu
 * produirait une dérive permanente avec tous les doubles au vert.
 *
 * **AUCUN OUTIL EN LIGNE DE COMMANDE, AUCUN CONTENEUR.** Ce backend est 100 %
 * HTTP. L'instance est hébergée chez nous aujourd'hui, et c'est précisément la
 * raison pour laquelle la tentation existe : un backend qui se replierait sur
 * l'outil local deviendrait inexécutable contre une instance distante, et cela ne
 * se verrait qu'en production. Un test d'architecture l'épingle.
 */
final class OpenCloudFileBackend implements FileBackend
{
    private const LOCK_PREFIX = 'network-shares:provision:opencloud:';

    private const LOCK_SECONDS = 180;

    /** La révocation ATTEND son tour, là où la mise en place renonce (iso 60.4). */
    private const REVOKE_WAIT_SECONDS = 15;

    /** Marqueur d'espace gouverné par SE5, posé en description à la création. */
    private const SPACE_MARKER = 'Répertoire géré par SambaEdu.';

    public function __construct(private readonly OpenCloudSubjectProjector $projector) {}

    public function name(): FileBackendName
    {
        return FileBackendName::OpenCloud;
    }

    // =========================================================================
    // provision
    // =========================================================================

    public function provision(FilePlan $plan): ReconciliationReport
    {
        $transport = $this->transport();

        if ($transport instanceof OpenCloudConfigurationException) {
            // FAIL-CLOSED sur la configuration, AVANT le premier appel : capacité
            // éteinte, réglage manquant, secret absent. Le refus nomme ce qui
            // manque plutôt que de partir écrire au hasard.
            $message = $transport->getMessage();

            return $this->everyNode($plan, static fn (string $p): NodeReconciliation => NodeReconciliation::echec($p, $message));
        }

        $lock = Cache::store('file')->lock(self::LOCK_PREFIX . $plan->rootPath, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return $this->everyNode($plan, static fn (string $p): NodeReconciliation => NodeReconciliation::echec(
                $p,
                'une autre réconciliation est en cours sur ce répertoire : ce passage n\'a rien écrit.',
            ));
        }

        try {
            return $this->converge($plan, new OpenCloudSpaceClient($transport), new OpenCloudDirectoryClient($transport));
        } finally {
            $lock->release();
        }
    }

    private function converge(
        FilePlan $plan,
        OpenCloudSpaceClient $spaces,
        OpenCloudDirectoryClient $directory,
    ): ReconciliationReport {
        $projection = OpenCloudPlanProjection::compile($plan, $this->projector);

        /** @var array<string, list<FileBackendOutcome>> $outcomes */
        $outcomes = [];
        /** @var array<string, list<string>> $details */
        $details = [];
        foreach ($plan->nodePaths() as $path) {
            $outcomes[$path] = [];
            $details[$path] = $projection->nodeDetails[$path] ?? [];
            if ($details[$path] !== []) {
                $outcomes[$path][] = FileBackendOutcome::Echec;
            }
        }

        // --- 1. L'espace : ADOPTÉ sur l'inventaire RELU, créé sinon -----------
        $space = $this->resolveSpace($spaces, $plan->rootPath);

        if ($space['error'] !== null) {
            $cause = $space['error'];

            return $this->everyNode($plan, static fn (string $p): NodeReconciliation => NodeReconciliation::echec($p, $cause));
        }

        $spaceId = $space['id'];
        if ($space['created']) {
            $outcomes[PlanNode::ROOT_PATH][] = FileBackendOutcome::Applique;
        }

        // --- 2. Les groupes, et le RETRAIT des appartenances périmées ---------
        //
        // **DEUX NATURES D'ÉCHEC, DEUX PORTÉES.** Un annuaire ILLISIBLE prive de
        // tout : sans index, aucun octroi de groupe n'est reprojetable et plus
        // rien n'est vérifiable — l'échec porte alors sur TOUS les nœuds, et c'est
        // juste. Un échec sur UN groupe, lui, ne dit rien des nœuds où ce groupe
        // n'apparaît pas : le projeter partout peindrait en rouge des dossiers
        // parfaitement convergés, et l'exploitant cesserait de lire des rouges qui
        // ne disent rien. Le défaut penche du bon côté dans les deux cas : jamais
        // faussement vert.
        /** @var list<string> $blanketFailures */
        $blanketFailures = [];
        /** @var array<string, list<string>> $groupFailures */
        $groupFailures = [];
        $groupIndex = $this->resolveGroups($directory, $projection, $blanketFailures, $groupFailures);
        $pendingAdditions = [];

        foreach ($projection->groups as $name => $spec) {
            $groupId = $groupIndex[$name] ?? null;
            if ($groupId === null) {
                continue;
            }

            $members = $directory->groupMembers($groupId);
            if ($members->isFailure()) {
                $groupFailures[$name][] = sprintf(
                    'l\'appartenance du groupe « %s » n\'a pas pu être relue : %s',
                    $name,
                    $members->message,
                );

                continue;
            }

            $current = $this->memberIdsOf($members);
            $wanted = $spec['members'];

            // RESTREINDRE d'abord.
            foreach (array_diff($current, $wanted) as $userId) {
                $removed = $directory->removeUserFromGroup($groupId, (string) $userId);
                if ($removed->isFailure()) {
                    $groupFailures[$name][] = sprintf(
                        'un compte n\'a pas pu quitter le groupe « %s » : %s',
                        $name,
                        $removed->message,
                    );
                }
            }

            // ÉLARGIR en dernier : on ne fait que les NOTER ici.
            foreach (array_diff($wanted, $current) as $userId) {
                $pendingAdditions[] = [$groupId, (string) $userId, $name];
            }
        }

        // --- 3. L'arborescence, un niveau à la fois --------------------------
        //
        // **LECTURE AVANT ÉCRITURE, ICI AUSSI.** On relit l'arborescence d'abord et
        // on ne crée QUE ce qui manque. S'en remettre à l'idempotence native (« un
        // dossier déjà là rend 405, donc rejouons ») rendrait le second passage
        // bavard : il émettrait une écriture par nœud, à chaque réconciliation de
        // chaque répertoire, et la promesse « second passage, zéro écriture »
        // serait fausse sans que rien ne la contredise.
        $items = $this->resolveItems($spaces, $spaceId, $plan, $treeFailures);
        $treeTouched = false;

        foreach (OpenCloudPlanProjection::creationOrder($plan) as $path) {
            if (in_array(FileBackendOutcome::Echec, $outcomes[$path], true)) {
                continue;
            }
            if (array_key_exists($path, $items)) {
                continue;
            }

            $made = $spaces->makeFolder($spaceId, $path);
            if ($made->isFailure()) {
                $outcomes[$path][] = FileBackendOutcome::Echec;
                $details[$path][] = 'création du dossier impossible : ' . $made->message;

                continue;
            }

            if ($made->alreadyConforming) {
                // **« IL EXISTE DÉJÀ » N'EST PAS « JE L'AI CRÉÉ ».** Le dossier
                // manque à l'index parce que sa lecture a échoué, pas parce qu'il
                // manque à l'instance. L'enregistrer en `applique` annoncerait une
                // création qui n'a pas eu lieu ET déclencherait une relecture
                // complète pour rien. On le dit conforme, et le nœud échouera plus
                // bas si son identifiant reste introuvable — fail-closed.
                $outcomes[$path][] = FileBackendOutcome::Conforme;

                continue;
            }

            $outcomes[$path][] = FileBackendOutcome::Applique;
            $treeTouched = true;
        }

        if ($treeTouched) {
            $items = $this->resolveItems($spaces, $spaceId, $plan, $treeFailures);
        }

        // Ce que la lecture n'a pas pu voir se DIT sur les nœuds concernés : sans
        // cela, l'échec du nœud dirait « dossier introuvable » sans sa cause.
        foreach ($treeFailures as $failure) {
            foreach ($plan->nodePaths() as $path) {
                if ($path === PlanNode::ROOT_PATH || array_key_exists($path, $items)) {
                    continue;
                }
                $details[$path][] = $failure;
            }
        }

        // --- 4 & 5. Les octrois : les nœuds d'abord, la RACINE en dernier ------
        $order = [...OpenCloudPlanProjection::creationOrder($plan), PlanNode::ROOT_PATH];

        foreach ($order as $path) {
            $node = $plan->node($path);
            if ($node === null || in_array(FileBackendOutcome::Echec, $outcomes[$path], true)) {
                continue;
            }

            $this->reconcileNode(
                $spaces,
                $spaceId,
                $items,
                $groupIndex,
                $projection,
                $node,
                $outcomes[$path],
                $details[$path],
            );
        }

        // --- 5bis. CONSTATER la clôture, une fois TOUT posé -------------------
        //
        // **La relecture vient APRÈS la pose, y compris celle de la racine.** La
        // vérifier au fil des nœuds la ferait porter sur un état incomplet — la
        // racine étant réconciliée en DERNIER par sûreté, un nœud contrôlé avant
        // elle conclurait « cloisonné » sur une racine encore vide, et le
        // cloisonnement affiché serait faux dès le passage suivant.
        $this->assertClosures($spaces, $spaceId, $items, $groupIndex, $projection, $plan, $outcomes, $details);

        // --- 6. Les appartenances AJOUTÉES, en tout dernier -------------------
        foreach ($pendingAdditions as [$groupId, $userId, $name]) {
            $added = $directory->addUserToGroup($groupId, $userId);
            if ($added->isFailure()) {
                $groupFailures[$name][] = sprintf(
                    'un compte n\'a pas pu rejoindre le groupe « %s » : %s',
                    $name,
                    $added->message,
                );
            }
        }

        // L'annuaire illisible : plus rien n'est reprojetable, donc TOUS les nœuds.
        foreach ($blanketFailures as $message) {
            foreach ($plan->nodePaths() as $path) {
                $outcomes[$path][] = FileBackendOutcome::Echec;
                $details[$path][] = $message;
            }
        }

        // L'échec d'UN groupe : seulement les nœuds où ce groupe est attendu.
        foreach ($groupFailures as $name => $messages) {
            $key = ObservedPermission::TYPE_GROUP . ':' . $name;

            foreach ($plan->nodePaths() as $path) {
                if (! array_key_exists($key, $projection->desired[$path] ?? [])) {
                    continue;
                }
                foreach ($messages as $message) {
                    $outcomes[$path][] = FileBackendOutcome::Echec;
                    $details[$path][] = $message;
                }
            }
        }

        // Les CONSTATS : ils n'empêchent rien et ne changent aucun état — ils
        // disent ce que l'exploitant doit savoir (des personnes sans compte
        // rattaché, donc sans accès effectif).
        foreach ($projection->notices as $notice) {
            $details[PlanNode::ROOT_PATH][] = $notice;
        }

        $entries = [];
        foreach ($plan->nodePaths() as $path) {
            $entries[] = $this->collapse($path, $outcomes[$path], $details[$path]);
        }

        return ReconciliationReport::covering($this->name(), $plan, $entries);
    }

    /**
     * L'espace de ce plan : ADOPTÉ s'il en existe déjà un du même nom, créé sinon.
     *
     * **La reconnaissance porte sur l'inventaire RELU, et c'est vital ici.**
     * Mesuré le 2026-08-13 : deux créations du même nom rendent DEUX `201` et
     * produisent DEUX espaces distincts — il n'y a aucune idempotence native. Un
     * backend qui créerait « au cas où » fabriquerait un espace de plus à chaque
     * passage, dont le second serait invisible à l'usage tout en consommant du
     * disque. On lit, on adopte, et on ne crée que ce qui manque vraiment.
     *
     * Un espace HOMONYME est donc le MÊME objet : c'est la doctrine d'adoption de
     * l'epic, et elle vaut ici comme ailleurs.
     *
     * @return array{id:string, created:bool, error:?string}
     */
    private function resolveSpace(OpenCloudSpaceClient $spaces, string $rootPath): array
    {
        $listed = $spaces->listSpaces();
        if ($listed->isFailure()) {
            return ['id' => '', 'created' => false, 'error' => $listed->message];
        }

        $existing = $this->findSpace($listed, $rootPath);
        if ($existing !== null) {
            return ['id' => $existing, 'created' => false, 'error' => null];
        }

        $created = $spaces->createSpace($rootPath, self::SPACE_MARKER);
        if ($created->isFailure()) {
            return ['id' => '', 'created' => false, 'error' => $created->message];
        }

        // On RELIT : l'identifiant rendu à la création est une commodité, la
        // vérité est l'inventaire.
        $listed = $spaces->listSpaces();
        if ($listed->isFailure()) {
            return ['id' => '', 'created' => false, 'error' => $listed->message];
        }

        $existing = $this->findSpace($listed, $rootPath);
        if ($existing === null) {
            return [
                'id' => '',
                'created' => false,
                'error' => 'l\'espace a été demandé mais ne figure pas dans l\'inventaire relu : rien ne '
                    . 'prouve qu\'il a été créé.',
            ];
        }

        return ['id' => $existing, 'created' => true, 'error' => null];
    }

    /**
     * L'espace de projet portant ce nom, dans l'inventaire relu.
     *
     * Le nom est comparé APRÈS le rognage que le serveur applique (mesuré : il
     * rogne les espaces de bord mais ne touche pas aux espaces internes). Les
     * espaces PERSONNELS et le lecteur virtuel de partages sont écartés : ils
     * portent des noms de personnes et ne sont jamais des zones du plan.
     */
    private function findSpace(OpenCloudResult $listed, string $rootPath): ?string
    {
        foreach ($listed->entries() as $space) {
            if (($space['driveType'] ?? '') !== 'project') {
                continue;
            }
            if (trim((string) ($space['name'] ?? '')) !== $rootPath) {
                continue;
            }
            $id = (string) ($space['id'] ?? '');
            if ($id !== '') {
                return $id;
            }
        }

        return null;
    }

    /**
     * Assure l'existence des groupes du plan et rend l'index nom → identifiant.
     *
     * **Un nom n'est pas un identifiant** : mesuré, un groupe est adressé par un
     * UUID, et le nom calculé par SE5 ne sert qu'à le RETROUVER dans l'annuaire
     * relu. C'est pourquoi la création est suivie d'une relecture plutôt que de la
     * lecture de son corps de réponse.
     *
     * **Les échecs sortent par DEUX canaux, et la distinction est la portée du
     * rouge** : l'annuaire illisible ({@see $blanket}) prive de tout ; l'échec sur
     * UN groupe ({@see $perGroup}) ne concerne que les nœuds qui l'attendent.
     *
     * @param  list<string>|null  $blanket  échecs qui empêchent TOUTE reprojection
     * @param  array<string, list<string>>|null  $perGroup  nom de groupe => échecs
     * @return array<string, string>
     */
    private function resolveGroups(
        OpenCloudDirectoryClient $directory,
        OpenCloudPlanProjection $projection,
        ?array &$blanket,
        ?array &$perGroup,
    ): array {
        $blanket = [];
        $perGroup = [];

        if ($projection->groups === []) {
            return [];
        }

        $listed = $directory->listGroups();
        if ($listed->isFailure()) {
            $blanket[] = 'l\'annuaire des groupes n\'a pas pu être lu : ' . $listed->message;

            return [];
        }

        $index = $this->groupIndexOf($listed);
        $created = false;

        foreach (array_keys($projection->groups) as $name) {
            if (isset($index[$name])) {
                continue;
            }

            // LECTURE AVANT ÉCRITURE : on ne crée que ce qui manque réellement.
            $result = $directory->createGroup($name);
            if ($result->isFailure()) {
                $perGroup[$name][] = sprintf('le groupe « %s » n\'a pas pu être assuré : %s', $name, $result->message);

                continue;
            }
            $created = true;
        }

        if ($created) {
            $listed = $directory->listGroups();
            if ($listed->isFailure()) {
                $blanket[] = 'l\'annuaire des groupes n\'a pas pu être relu : ' . $listed->message;

                return $index;
            }
            $index = $this->groupIndexOf($listed);
        }

        foreach (array_keys($projection->groups) as $name) {
            if (! isset($index[$name])) {
                $perGroup[$name][] = sprintf(
                    'le groupe « %s » a été demandé mais ne figure pas dans l\'annuaire relu : rien ne prouve '
                    . 'qu\'il existe.',
                    $name,
                );
            }
        }

        return $index;
    }

    /** @return array<string, string> nom d'affichage => identifiant */
    private function groupIndexOf(OpenCloudResult $listed): array
    {
        $index = [];
        foreach ($listed->entries() as $group) {
            $name = trim((string) ($group['displayName'] ?? ''));
            $id = (string) ($group['id'] ?? '');
            if ($name !== '' && $id !== '') {
                $index[$name] = $id;
            }
        }

        return $index;
    }

    /** @return list<string> */
    private function memberIdsOf(OpenCloudResult $group): array
    {
        $members = [];
        foreach (is_array($group->value('members')) ? $group->value('members') : [] as $member) {
            if (is_array($member) && is_string($member['id'] ?? null) && $member['id'] !== '') {
                $members[] = (string) $member['id'];
            }
        }
        sort($members, SORT_STRING);

        return $members;
    }

    /**
     * L'index chemin de nœud → identifiant d'item, construit en DESCENDANT
     * l'arborescence.
     *
     * Il n'y a pas d'accès par chemin dans cette API (l'adressage `root:/chemin`
     * rend `404`) : un item se trouve en listant son parent. On descend donc
     * niveau par niveau, ce qui a un effet secondaire utile — un nœud dont le
     * parent manque est simplement ABSENT de l'index, et l'appelant le constate au
     * lieu de fabriquer un identifiant.
     *
     * ---------------------------------------------------------------------------
     * **UNE LECTURE QUI ÉCHOUE N'EST PAS UN DOSSIER QUI MANQUE — et confondre les
     * deux est un fail-OPEN.** Un `5xx` transitoire sur UNE requête suffirait
     * sinon à faire disparaître tout un sous-arbre de l'index ; la révocation en
     * conclurait « ce dossier n'existe pas : rien à révoquer » sur des octrois
     * parfaitement intacts, et l'inspection le rendrait `absent` alors qu'on n'en
     * sait rien. Les échecs de lecture REMONTENT donc à l'appelant, qui décide en
     * fail-CLOSED.
     * ---------------------------------------------------------------------------
     *
     * @param  list<string>|null  $failures  échecs de lecture, rendus à l'appelant
     * @return array<string, string>
     */
    private function resolveItems(
        OpenCloudSpaceClient $spaces,
        string $spaceId,
        FilePlan $plan,
        ?array &$failures = null,
    ): array {
        // La RACINE s'adresse par le segment dédié, jamais par un identifiant
        // d'item : passer celui de l'espace rend `400 invalid itemID`.
        $items = [];
        $failures = [];
        $byParent = [PlanNode::ROOT_PATH => $spaceId];
        $listedParents = [];

        foreach (OpenCloudPlanProjection::creationOrder($plan) as $path) {
            $parent = dirname($path);
            $parentPath = ($parent === '.' || $parent === '' || $parent === '/') ? PlanNode::ROOT_PATH : $parent;

            $parentItem = $byParent[$parentPath] ?? null;
            if ($parentItem === null) {
                continue;
            }
            if (isset($listedParents[$parentPath])) {
                continue;
            }
            $listedParents[$parentPath] = true;

            $listed = $spaces->listChildren($spaceId, $parentItem);
            if ($listed->isFailure()) {
                $failures[] = sprintf(
                    'le contenu du dossier « %s » n\'a pas pu être relu : %s',
                    $parentPath,
                    $listed->message,
                );

                continue;
            }

            foreach ($listed->entries() as $child) {
                $name = (string) ($child['name'] ?? '');
                $id = (string) ($child['id'] ?? '');
                if ($name === '' || $id === '') {
                    continue;
                }
                $childPath = $parentPath === PlanNode::ROOT_PATH ? $name : $parentPath . '/' . $name;
                $byParent[$childPath] = $id;
                $items[$childPath] = $id;
            }
        }

        return $items;
    }

    /**
     * Réconcilie les octrois d'un nœud : on LIT, on retire, puis on pose.
     *
     * @param  array<string, string>  $items
     * @param  array<string, string>  $groupIndex
     * @param  list<FileBackendOutcome>  $outcomes
     * @param  list<string>  $details
     */
    private function reconcileNode(
        OpenCloudSpaceClient $spaces,
        string $spaceId,
        array $items,
        array $groupIndex,
        OpenCloudPlanProjection $projection,
        PlanNode $node,
        array &$outcomes,
        array &$details,
    ): void {
        $isRoot = $node->path === PlanNode::ROOT_PATH;
        $itemId = $isRoot ? null : ($items[$node->path] ?? null);

        if (! $isRoot && $itemId === null) {
            $outcomes[] = FileBackendOutcome::Echec;
            $details[] = 'le dossier n\'a pas été retrouvé dans l\'arborescence relue : aucun octroi n\'a été '
                . 'posé pour ce nœud.';

            return;
        }

        // LECTURE AVANT ÉCRITURE : un passage sur un état conforme n'émet rien.
        $read = $isRoot
            ? $spaces->listRootPermissions($spaceId)
            : $spaces->listItemPermissions($spaceId, (string) $itemId);

        if ($read->isFailure()) {
            $outcomes[] = FileBackendOutcome::Echec;
            $details[] = 'relecture des octrois impossible avant écriture : ' . $read->message;

            return;
        }

        $observed = $this->observedByKey($read, $groupIndex);
        $family = $isRoot ? OpenCloudRoleTable::FAMILY_SPACE : OpenCloudRoleTable::FAMILY_ITEM;
        $desired = $projection->desired[$node->path] ?? [];
        $touched = false;

        // --- (a) RETIRER : ce que le plan ne veut plus, et qu'on a posé --------
        foreach ($observed as $key => $permission) {
            $wanted = $desired[$key] ?? null;

            if ($wanted !== null && $wanted !== []) {
                continue; // il reste voulu — traité plus bas
            }

            if (! array_key_exists($key, $projection->principals)) {
                // HORS DU PLAN, HORS DU GESTE. Un octroi posé à la main, ou celui
                // que l'instance donne d'office au compte qui a créé l'espace, ne
                // nous appartient pas. Il est COMPTÉ dans le détail — sous drift
                // STRICT, c'est un écart que la comparaison doit voir — mais il
                // n'est jamais retiré.
                continue;
            }

            $removed = $this->deletePermission($spaces, $spaceId, $itemId, $permission->permissionId);
            if ($removed->isFailure()) {
                $outcomes[] = FileBackendOutcome::Echec;
                $details[] = 'retrait d\'un octroi impossible : ' . $removed->message;

                continue;
            }
            if (! $removed->alreadyConforming) {
                $touched = true;
            }
            unset($observed[$key]);
        }

        // --- (b) POSER : ce que le plan veut ---------------------------------
        foreach ($desired as $key => $verbs) {
            $principal = $projection->principals[$key] ?? null;
            if ($principal === null) {
                continue;
            }

            if ($projection->isSuspended($node->path, $key) && $verbs === []) {
                // La suspension n'est PAS exprimable : mesuré, un octroi
                // explicitement vide est refusé, et le minimum acceptable rend le
                // dossier VISIBLE chez son destinataire. L'octroi a donc été
                // retiré (effet juste), et la DISTINCTION entre « suspendu » et
                // « jamais accordé » est perdue — on le DIT.
                $outcomes[] = FileBackendOutcome::NonExprimable;
                $details[] = 'un octroi SUSPENDU de ce nœud ne peut pas être matérialisé : ce modèle '
                    . 'n\'accepte aucun octroi explicitement vide. L\'accès est bien retiré, mais la '
                    . 'suspension ne se distingue pas d\'une absence d\'octroi.';

                continue;
            }

            if ($verbs === []) {
                continue;
            }

            $remoteId = $this->remoteIdOf($principal, $groupIndex);
            if ($remoteId === null) {
                $outcomes[] = FileBackendOutcome::Echec;
                $details[] = sprintf(
                    'le groupe « %s » n\'a pas d\'identifiant connu sur l\'instance : son octroi n\'a pas '
                    . 'été écrit.',
                    $principal['id'],
                );

                continue;
            }

            $role = OpenCloudRoleTable::resolve($verbs, $family);
            if ($role === null) {
                $outcomes[] = FileBackendOutcome::NonExprimable;
                $details[] = sprintf(
                    'aucun rôle de ce modèle ne correspond aux verbes demandés (%s) sans accorder un droit '
                    . 'qu\'aucun verbe ne décrit : l\'octroi n\'a pas été posé.',
                    implode(', ', $verbs),
                );

                continue;
            }

            $existing = $observed[$key] ?? null;

            if ($existing !== null && $existing->carriesExactly($role['id'])) {
                $outcomes[] = FileBackendOutcome::Conforme;
            } elseif ($existing !== null) {
                // Rejouer une invitation rend `409` SANS RIEN CHANGER : la
                // modification passe obligatoirement par la permission existante.
                $updated = $this->updatePermission($spaces, $spaceId, $itemId, $existing->permissionId, $role['id']);
                if ($updated->isFailure()) {
                    $outcomes[] = FileBackendOutcome::Echec;
                    $details[] = 'modification d\'un octroi impossible : ' . $updated->message;

                    continue;
                }
                $touched = true;
            } else {
                $posed = $this->invite($spaces, $spaceId, $itemId, $principal['type'], $remoteId, $role['id']);
                if ($posed->isFailure()) {
                    $outcomes[] = FileBackendOutcome::Echec;
                    $details[] = 'pose d\'un octroi impossible : ' . $posed->message;

                    continue;
                }
                if (! $posed->alreadyConforming) {
                    $touched = true;
                }
            }

            if ($role['missing'] !== []) {
                // LE CONSTAT, jamais l'arrondi : le rôle le plus riche compatible
                // ne transmet pas tout ce que le plan demande, et le dire est la
                // seule réponse honnête.
                $outcomes[] = FileBackendOutcome::NonExprimable;
                $details[] = sprintf(
                    'le rôle « %s » est le plus permissif que ce modèle propose SANS accorder de droit '
                    . 'qu\'aucun verbe ne décrit : le(s) verbe(s) « %s » ne sont pas transmis sur ce nœud.',
                    $role['label'],
                    implode(', ', $role['missing']),
                );
            }
        }

        // --- (c) SIGNALER ce qui est là et que le plan ne décrit pas ----------
        $foreign = 0;
        $unmodelled = 0;
        foreach ($observed as $key => $permission) {
            if (! array_key_exists($key, $projection->principals)) {
                $foreign++;
            }
            if ($permission->isUnmodelled()) {
                $unmodelled++;
            }
        }

        if ($foreign > 0) {
            $details[] = sprintf(
                '%d octroi(s) relu(s) désignent un principal que le plan ne décrit pas : ils sont comptés '
                . 'comme écart, jamais retirés (hors du plan, hors du geste).',
                $foreign,
            );
        }
        if ($unmodelled > 0) {
            $details[] = sprintf(
                '%d octroi(s) relu(s) portent un rôle que le plan ne sait pas décrire (administration des '
                . 'membres) : SE5 ne l\'accorde jamais et ne sait pas le gouverner.',
                $unmodelled,
            );
        }

        if ($touched) {
            $outcomes[] = FileBackendOutcome::Applique;
        }
    }

    /**
     * CONSTATE la clôture d'un nœud, sur ce que l'instance rend APRÈS écriture.
     *
     * **Jamais `applique` sur la foi d'une enveloppe favorable.** La clôture est
     * obtenue par CONSTRUCTION (aucun octroi ⇒ aucun accès, et le dossier est même
     * invisible), et le seul cas où elle échoue est mesuré : un octroi posé sur un
     * ANCÊTRE propage à tout son sous-arbre, sans être nommé chez les descendants
     * et sans qu'on puisse le refermer.
     *
     * ---------------------------------------------------------------------------
     * **LA PROPAGATION EST UNE PROPRIÉTÉ DE L'OCTROI SUR UN ITEM, PAS DE LA
     * RACINE.** Le relevé le dit dans sa propre colonne de preuve : un octroi posé
     * sur le seul `_travail` rend `207` sur `_travail` **et sur son enfant
     * `devoirs`**. Ne relire que la racine laisserait donc, dès la profondeur 2,
     * un nœud rendre `conforme` sur un cloisonnement qui n'existe pas — le seul
     * résultat que l'AC5 déclare inacceptable. On relit donc les octrois de
     * CHAQUE ancêtre présent au plan. Le coût est une lecture par ancêtre, et elle
     * est mutualisée entre les nœuds qui partagent le même ancêtre.
     *
     * **TOUT survivant est constaté, y compris étranger au plan.** Un espace
     * homonyme ADOPTÉ peut porter des octrois posés à la main : le plan ne les
     * décrit pas, on ne les retire donc jamais (drift STRICT), mais ils propagent
     * ici et le taire afficherait un cloisonnement faux. Seule exception, et elle
     * est MESURÉE : l'instance donne d'office le rôle d'administration de l'espace
     * au compte qui l'a créé — c'est un octroi nominatif d'administration, déjà
     * compté comme écart sur la racine elle-même, et le répéter sur chaque nœud
     * refermé ferait crier la garde sur 100 % des zones, ce qui reviendrait à ne
     * plus rien dire.
     * ---------------------------------------------------------------------------
     *
     * @param  array<string, string>  $items
     * @param  array<string, string>  $groupIndex
     * @param  array<string, list<FileBackendOutcome>>  $outcomes
     * @param  array<string, list<string>>  $details
     */
    private function assertClosures(
        OpenCloudSpaceClient $spaces,
        string $spaceId,
        array $items,
        array $groupIndex,
        OpenCloudPlanProjection $projection,
        FilePlan $plan,
        array &$outcomes,
        array &$details,
    ): void {
        /** @var array<string, array<string, ObservedPermission>> $cache */
        $cache = [];
        /** @var array<string, string> $unreadable */
        $unreadable = [];

        foreach ($plan->nodePaths() as $path) {
            if ($path === PlanNode::ROOT_PATH || ($projection->closedSubjects[$path] ?? []) === []) {
                continue;
            }

            $closed = $projection->closedSubjects[$path];
            $survivors = [];
            $foreign = [];
            $blind = [];

            foreach (self::planAncestorsOf($path, $plan) as $ancestor) {
                $observed = $this->ancestorPermissions(
                    $spaces,
                    $spaceId,
                    $items,
                    $groupIndex,
                    $ancestor,
                    $cache,
                    $unreadable,
                );

                if ($observed === null) {
                    $blind[$ancestor] = $unreadable[$ancestor] ?? '';

                    continue;
                }

                foreach ($observed as $key => $permission) {
                    if ($permission->roleIds === [] && $permission->actions === []) {
                        continue;
                    }

                    // La comparaison porte sur la CLÉ DE PLAN de l'octroi relu,
                    // celle que l'index des groupes a reconstituée — jamais sur
                    // l'identifiant distant brut, qui ne ressemble à rien de ce
                    // que le plan connaît.
                    if (array_key_exists($key, $closed)) {
                        $principal = $projection->principals[$key] ?? null;
                        if ($principal !== null) {
                            $survivors[(string) $principal['id']] = self::ancestorLabel($ancestor);
                        }

                        continue;
                    }

                    if (array_key_exists($key, $projection->principals)) {
                        continue; // décrit par le plan, et voulu là où il est.
                    }

                    if (self::isSpaceOwnerGrant($ancestor, $permission)) {
                        continue; // l'administration que l'instance donne d'office.
                    }

                    $foreign[$key] = self::ancestorLabel($ancestor);
                }
            }

            if ($blind !== []) {
                $outcomes[$path][] = FileBackendOutcome::Echec;
                $details[$path][] = sprintf(
                    'les octrois de %s n\'ont pas pu être relus : rien ne prouve que le cloisonnement de ce '
                    . 'dossier est effectif. %s',
                    implode(', ', array_map(self::ancestorLabel(...), array_keys($blind))),
                    trim(implode(' ', array_unique(array_values($blind)))),
                );
            }

            if ($survivors !== []) {
                ksort($survivors, SORT_STRING);

                $outcomes[$path][] = FileBackendOutcome::NonExprimable;
                $details[$path][] = sprintf(
                    'cloisonnement non obtenu : le plan octroie sur un ANCÊTRE de ce dossier, et un octroi '
                    . 'd\'ancêtre propage à tout son sous-arbre sans qu\'aucun mécanisme de ce modèle puisse '
                    . 'le refermer plus bas. L\'accès subsiste ici pour %d principal(aux) que le plan referme '
                    . '(%s). Ce dossier N\'EST PAS cloisonné pour eux.',
                    count($survivors),
                    implode(', ', array_map(
                        static fn (string $id, string $where): string => '« ' . $id .' » (depuis ' . $where . ')',
                        array_keys($survivors),
                        array_values($survivors),
                    )),
                );
            }

            if ($foreign !== []) {
                ksort($foreign, SORT_STRING);

                $outcomes[$path][] = FileBackendOutcome::NonExprimable;
                $details[$path][] = sprintf(
                    'cloisonnement non obtenu : %d octroi(s) qu\'aucun plan ne décrit sont posés sur un '
                    . 'ANCÊTRE de ce dossier (%s) et y propagent. SE5 ne les retire pas — hors du plan, hors '
                    . 'du geste — mais leurs destinataires accèdent ici, et le taire afficherait un '
                    . 'cloisonnement qui n\'existe pas.',
                    count($foreign),
                    implode(', ', array_map(
                        static fn (string $key, string $where): string => '« ' . $key . ' » (sur ' . $where . ')',
                        array_keys($foreign),
                        array_values($foreign),
                    )),
                );
            }
        }
    }

    /**
     * Les ancêtres de ce nœud PRÉSENTS AU PLAN, du plus proche à la racine.
     *
     * @return list<string>
     */
    private static function planAncestorsOf(string $path, FilePlan $plan): array
    {
        if ($path === PlanNode::ROOT_PATH) {
            return [];
        }

        $ancestors = [];
        $current = $path;

        while (true) {
            $parent = dirname($current);
            $parentPath = ($parent === '.' || $parent === '' || $parent === '/') ? PlanNode::ROOT_PATH : $parent;

            if ($parentPath === PlanNode::ROOT_PATH) {
                $ancestors[] = PlanNode::ROOT_PATH;
                break;
            }

            if ($plan->node($parentPath) !== null) {
                $ancestors[] = $parentPath;
            }

            $current = $parentPath;
        }

        return $ancestors;
    }

    private static function ancestorLabel(string $ancestor): string
    {
        return $ancestor === PlanNode::ROOT_PATH ? 'la RACINE de la zone' : 'le dossier « ' . $ancestor . ' »';
    }

    /**
     * L'octroi que l'instance donne D'OFFICE au compte qui a créé l'espace.
     *
     * Mesuré : la racine d'un espace neuf porte un octroi nominatif au créateur,
     * avec le rôle d'administration. Il est déjà compté comme écart sur la racine ;
     * le compter aussi dans chaque clôture ferait rendre `non_exprimable` à
     * 100 % des zones, et une garde qui crie toujours ne dit plus rien.
     */
    private static function isSpaceOwnerGrant(string $ancestor, ObservedPermission $permission): bool
    {
        return $ancestor === PlanNode::ROOT_PATH
            && $permission->principalType === ObservedPermission::TYPE_USER
            && $permission->roleIds === [OpenCloudRoleTable::MANAGE_ROLE_ID];
    }

    /**
     * Les octrois relus d'un ancêtre, MUTUALISÉS entre les nœuds qui le partagent.
     *
     * Rend `null` quand la lecture a échoué — jamais un tableau vide, qui se
     * lirait « cet ancêtre n'octroie rien » et refermerait une clôture qu'on n'a
     * pas vérifiée.
     *
     * @param  array<string, string>  $items
     * @param  array<string, string>  $groupIndex
     * @param  array<string, array<string, ObservedPermission>>  $cache
     * @param  array<string, string>  $unreadable
     * @return array<string, ObservedPermission>|null
     */
    private function ancestorPermissions(
        OpenCloudSpaceClient $spaces,
        string $spaceId,
        array $items,
        array $groupIndex,
        string $ancestor,
        array &$cache,
        array &$unreadable,
    ): ?array {
        if (array_key_exists($ancestor, $cache)) {
            return $cache[$ancestor];
        }
        if (array_key_exists($ancestor, $unreadable)) {
            return null;
        }

        if ($ancestor === PlanNode::ROOT_PATH) {
            $read = $spaces->listRootPermissions($spaceId);
        } else {
            $itemId = $items[$ancestor] ?? null;
            if ($itemId === null) {
                // L'ancêtre n'a pas d'identifiant : soit il n'existe pas encore
                // (il ne porte alors aucun octroi), soit sa lecture a échoué — et
                // ce second cas est déjà rapporté sur le nœud par la remontée des
                // échecs de {@see resolveItems()}.
                return $cache[$ancestor] = [];
            }

            $read = $spaces->listItemPermissions($spaceId, $itemId);
        }

        if ($read->isFailure()) {
            $unreadable[$ancestor] = $read->message;

            return null;
        }

        // L'index des groupes est INDISPENSABLE ici : sans lui, un octroi relu
        // garderait sa clé d'identifiant distant et ne se comparerait jamais à la
        // clé de plan — la garde serait verte en ne regardant rien.
        return $cache[$ancestor] = $this->observedByKey($read, $groupIndex);
    }

    /**
     * Les octrois relus, indexés par CLÉ DE PLAN.
     *
     * Un octroi de groupe est relu par identifiant ; l'index des groupes le
     * ramène à son nom calculé, c'est-à-dire à la clé que la projection emploie.
     * Ce qui ne s'y ramène pas garde sa clé brute et sera compté comme ÉTRANGER —
     * jamais deviné, jamais omis.
     *
     * @param  array<string, string>  $groupIndex  nom => identifiant
     * @return array<string, ObservedPermission>
     */
    private function observedByKey(OpenCloudResult $read, array $groupIndex): array
    {
        $byId = array_flip($groupIndex);
        $observed = [];

        foreach ($read->entries() as $raw) {
            $permission = ObservedPermission::fromArray($raw);
            if ($permission === null) {
                continue;
            }

            $key = $permission->principalKey();
            if ($permission->principalType === ObservedPermission::TYPE_GROUP
                && isset($byId[$permission->principalId])) {
                $key = ObservedPermission::TYPE_GROUP . ':' . $byId[$permission->principalId];
            }

            $observed[$key] = $permission;
        }

        return $observed;
    }

    /**
     * @param  array{type:string,id:string,subject:\App\Services\Filesystem\Plan\PlanSubject}  $principal
     * @param  array<string, string>  $groupIndex
     */
    private function remoteIdOf(array $principal, array $groupIndex): ?string
    {
        if ($principal['type'] === ObservedPermission::TYPE_USER) {
            // L'identifiant distant d'un compte EST la valeur du cache : elle a
            // été résolue à la compilation, jamais devinée.
            return (string) $principal['id'];
        }

        return $groupIndex[$principal['id']] ?? null;
    }

    private function invite(
        OpenCloudSpaceClient $spaces,
        string $spaceId,
        ?string $itemId,
        string $type,
        string $principalId,
        string $roleId,
    ): OpenCloudResult {
        return $itemId === null
            ? $spaces->inviteOnRoot($spaceId, $type, $principalId, $roleId)
            : $spaces->inviteOnItem($spaceId, $itemId, $type, $principalId, $roleId);
    }

    private function updatePermission(
        OpenCloudSpaceClient $spaces,
        string $spaceId,
        ?string $itemId,
        string $permissionId,
        string $roleId,
    ): OpenCloudResult {
        return $itemId === null
            ? $spaces->updateRootPermission($spaceId, $permissionId, $roleId)
            : $spaces->updateItemPermission($spaceId, $itemId, $permissionId, $roleId);
    }

    private function deletePermission(
        OpenCloudSpaceClient $spaces,
        string $spaceId,
        ?string $itemId,
        string $permissionId,
    ): OpenCloudResult {
        return $itemId === null
            ? $spaces->deleteRootPermission($spaceId, $permissionId)
            : $spaces->deleteItemPermission($spaceId, $itemId, $permissionId);
    }

    // =========================================================================
    // deprovision
    // =========================================================================

    /**
     * RÉVOQUE les octrois — SANS DÉTRUIRE NI L'ESPACE NI SON CONTENU.
     *
     * **Aucun chemin de production ne supprime un espace** (D9, épinglé par test),
     * et le client n'a même pas de méthode pour le faire. La séquence est : retirer
     * les octrois que SE5 a posés sur les nœuds du plan. L'espace reste, ses
     * données restent, et le compte d'administration y garde l'accès que
     * l'instance lui donne d'office.
     *
     * Ce qui n'a jamais été posé par SE5 n'est pas touché : hors du plan, hors du
     * geste.
     */
    public function deprovision(FilePlan $plan): ReconciliationReport
    {
        $transport = $this->transport();

        if ($transport instanceof OpenCloudConfigurationException) {
            $message = $transport->getMessage();

            return $this->everyNode($plan, static fn (string $p): NodeReconciliation => NodeReconciliation::echec($p, $message));
        }

        $lock = Cache::store('file')->lock(self::LOCK_PREFIX . $plan->rootPath, self::LOCK_SECONDS);

        try {
            $lock->block(self::REVOKE_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            return $this->everyNode($plan, static fn (string $p): NodeReconciliation => NodeReconciliation::echec(
                $p,
                'une autre réconciliation est en cours sur ce répertoire et ne s\'est pas achevée à temps : '
                . 'ce passage n\'a rien révoqué.',
            ));
        }

        try {
            $spaces = new OpenCloudSpaceClient($transport);
            $directory = new OpenCloudDirectoryClient($transport);

            $listed = $spaces->listSpaces();
            if ($listed->isFailure()) {
                $cause = $listed->message;

                return $this->everyNode($plan, static fn (string $p): NodeReconciliation => NodeReconciliation::echec($p, $cause));
            }

            $spaceId = $this->findSpace($listed, $plan->rootPath);
            if ($spaceId === null) {
                return $this->everyNode($plan, static fn (string $p): NodeReconciliation => NodeReconciliation::conforme(
                    $p,
                    'aucun espace ne porte ce plan : rien à révoquer.',
                ));
            }

            $projection = OpenCloudPlanProjection::compile($plan, $this->projector);

            // SANS l'annuaire, un octroi de groupe relu ne se ramène pas à sa clé
            // de plan : il passerait pour ÉTRANGER, ne serait pas révoqué, et le
            // rapport dirait « rien à révoquer » sur des accès toujours en place.
            // Un fail-OPEN sur une révocation est le pire des deux sens.
            $groups = $directory->listGroups();
            if ($groups->isFailure()) {
                $cause = 'l\'annuaire des groupes n\'a pas pu être lu : rien n\'a été révoqué, faute de '
                    . 'pouvoir reconnaître les octrois posés par SE5. ' . $groups->message;

                return $this->everyNode($plan, static fn (string $p): NodeReconciliation => NodeReconciliation::echec($p, $cause));
            }

            $groupIndex = $this->groupIndexOf($groups);

            // MÊME FAIL-CLOSED, UN CRAN PLUS BAS. Un `5xx` transitoire sur UNE
            // lecture d'arborescence ferait disparaître tout un sous-arbre de
            // l'index ; conclure « ce dossier n'existe pas : rien à révoquer »
            // serait alors un fail-OPEN sur une révocation — le pire des deux
            // sens, et le docblock ci-dessus l'interdit nommément.
            $items = $this->resolveItems($spaces, $spaceId, $plan, $treeFailures);

            $entries = [];
            foreach ($plan->nodes as $node) {
                $entries[] = $this->revokeNode($spaces, $spaceId, $items, $treeFailures, $groupIndex, $projection, $node);
            }

            return ReconciliationReport::covering($this->name(), $plan, $entries);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, string>  $items
     * @param  list<string>  $treeFailures
     * @param  array<string, string>  $groupIndex
     */
    private function revokeNode(
        OpenCloudSpaceClient $spaces,
        string $spaceId,
        array $items,
        array $treeFailures,
        array $groupIndex,
        OpenCloudPlanProjection $projection,
        PlanNode $node,
    ): NodeReconciliation {
        $isRoot = $node->path === PlanNode::ROOT_PATH;
        $itemId = $isRoot ? null : ($items[$node->path] ?? null);

        if (! $isRoot && $itemId === null) {
            // **« JE NE L'AI PAS TROUVÉ » N'EST « IL N'EXISTE PAS » QUE SI LA
            // LECTURE A ABOUTI.** Sinon, ses octrois sont peut-être intacts, et
            // rendre `conforme` — « rien à révoquer » — serait un fail-OPEN.
            if ($treeFailures !== []) {
                return NodeReconciliation::echec(
                    $node->path,
                    'l\'arborescence n\'a pas pu être relue : RIEN n\'a été révoqué ici, et rien ne prouve '
                    . 'que ce dossier soit absent. ' . implode(' ', $treeFailures),
                );
            }

            return NodeReconciliation::conforme(
                $node->path,
                'ce dossier n\'existe pas sur l\'instance : rien à révoquer.',
            );
        }

        $read = $isRoot
            ? $spaces->listRootPermissions($spaceId)
            : $spaces->listItemPermissions($spaceId, (string) $itemId);

        if ($read->isFailure()) {
            return NodeReconciliation::echec($node->path, 'relecture des octrois impossible : ' . $read->message);
        }

        $observed = $this->observedByKey($read, $groupIndex);
        $removed = 0;

        foreach ($observed as $key => $permission) {
            // SEULEMENT ce que SE5 a posé : un octroi étranger reste en place.
            if (! array_key_exists($key, $projection->principals)) {
                continue;
            }

            $result = $this->deletePermission($spaces, $spaceId, $itemId, $permission->permissionId);
            if ($result->isFailure()) {
                return NodeReconciliation::echec($node->path, 'retrait d\'un octroi impossible : ' . $result->message);
            }
            if (! $result->alreadyConforming) {
                $removed++;
            }
        }

        return $removed === 0
            ? NodeReconciliation::conforme($node->path, 'aucun octroi de ce plan n\'était en place.')
            : NodeReconciliation::applique(
                $node->path,
                'octrois retirés ; le dossier et son contenu restent (aucune donnée détruite).',
            );
    }

    // =========================================================================
    // inspect
    // =========================================================================

    /**
     * RELIT l'état, nœud par nœud, RACINE COMPRISE, et le REPROJETTE en vocabulaire
     * de plan.
     *
     * **Aucun identifiant distant ne remonte.** Un nom de groupe compilé redevient
     * l'identité interne qui l'a produit — par RECALCUL des noms attendus depuis le
     * plan, jamais par découpage d'un nom relu. Un identifiant de compte redevient
     * `users.id` par le cache d'identité.
     *
     * **Ce qui ne se reprojette pas n'est ni inventé ni tu.** Un principal étranger
     * au plan est COMPTÉ dans le détail de l'observation : sous drift STRICT, c'est
     * un écart que la comparaison doit voir, pas un trou dans la reprojection.
     *
     * **« Conforme » et « non mesurable » ne se confondent jamais.** Ce qu'on n'a
     * pas pu lire rend une observation en échec — jamais une observation VIDE, qui
     * se lirait « tout va bien ». Un nœud cherché et absent rend `absent`, qui est
     * un FAIT constaté.
     *
     * **La clôture EST observable ici, et c'est une propriété du modèle.** Comme
     * rien ne propage hors d'un octroi posé sur un item, un sujet refermé est
     * effectivement clos dès qu'il n'a d'octroi ni sur ce nœud, **ni sur aucun de
     * ses ancêtres** — la racine étant le plus large d'entre eux, jamais le seul.
     * L'observation rend donc la LISTE des sujets refermés — pas `null`. Ce qui
     * n'est **structurellement** pas observable — la perception effective d'un
     * élève — n'est pas déduit des octrois relus : il est dit au runbook et MESURÉ
     * par le test d'intégration avec des comptes jetables. Un octroi relu prouve un
     * octroi, pas une perception.
     */
    public function inspect(FilePlan $plan): InspectionReport
    {
        $transport = $this->transport();

        if ($transport instanceof OpenCloudConfigurationException) {
            $message = $transport->getMessage();

            return $this->everyObservation($plan, static fn (string $p): NodeObservation => NodeObservation::echec($p, $message));
        }

        $spaces = new OpenCloudSpaceClient($transport);
        $directory = new OpenCloudDirectoryClient($transport);
        $projection = OpenCloudPlanProjection::compile($plan, $this->projector);

        $listed = $spaces->listSpaces();
        if ($listed->isFailure()) {
            $message = $listed->message;

            return $this->everyObservation($plan, static fn (string $p): NodeObservation => NodeObservation::echec($p, $message));
        }

        $spaceId = $this->findSpace($listed, $plan->rootPath);
        if ($spaceId === null) {
            // Un FAIT constaté : l'inventaire a répondu et ne porte pas ce plan.
            return $this->everyObservation($plan, static fn (string $p): NodeObservation => NodeObservation::absent($p));
        }

        // MÊME FAIL-CLOSED QU'À LA RÉVOCATION, et pour une raison plus grave
        // encore : sans l'annuaire, un octroi de groupe relu ne se ramène pas à sa
        // clé de plan, le sujet paraîtrait n'avoir RIEN reçu, et la clôture serait
        // rapportée EFFECTIVE sur un dossier parfaitement ouvert. Une observation
        // qu'on ne peut pas faire se dit ; elle ne se devine pas.
        $groups = $directory->listGroups();
        if ($groups->isFailure()) {
            $message = 'l\'annuaire des groupes n\'a pas pu être lu : aucun octroi de groupe n\'est '
                . 'reprojetable, et rien ne peut être affirmé de la clôture. ' . $groups->message;

            return $this->everyObservation($plan, static fn (string $p): NodeObservation => NodeObservation::echec($p, $message));
        }

        $groupIndex = $this->groupIndexOf($groups);
        $items = $this->resolveItems($spaces, $spaceId, $plan, $treeFailures);

        /** @var array<string, array<string, ObservedPermission>> $cache */
        $cache = [];
        /** @var array<string, string> $unreadable */
        $unreadable = [];

        $observations = [];
        foreach ($plan->nodes as $node) {
            $observations[] = $this->inspectNode(
                $spaces,
                $spaceId,
                $items,
                $treeFailures,
                $groupIndex,
                $projection,
                $plan,
                $node,
                $cache,
                $unreadable,
                $listed,
            );
        }

        return InspectionReport::covering($this->name(), $plan, $observations);
    }

    /**
     * @param  array<string, string>  $items
     * @param  list<string>  $treeFailures
     * @param  array<string, string>  $groupIndex
     * @param  array<string, array<string, ObservedPermission>>  $cache
     * @param  array<string, string>  $unreadable
     */
    private function inspectNode(
        OpenCloudSpaceClient $spaces,
        string $spaceId,
        array $items,
        array $treeFailures,
        array $groupIndex,
        OpenCloudPlanProjection $projection,
        FilePlan $plan,
        PlanNode $node,
        array &$cache,
        array &$unreadable,
        OpenCloudResult $spaceList,
    ): NodeObservation {
        $isRoot = $node->path === PlanNode::ROOT_PATH;

        if (! $isRoot && ! array_key_exists($node->path, $items)) {
            // **`absent` EST UN FAIT, PAS UN DÉFAUT DE LECTURE.** Il ne se
            // prononce que si l'arborescence a bien été relue ; sinon, on ne sait
            // rien de ce nœud, et « on ne sait pas » a son propre mot.
            return $treeFailures === []
                ? NodeObservation::absent($node->path)
                : NodeObservation::nonObservable(
                    $node->path,
                    'l\'arborescence n\'a pas pu être relue : ce nœud n\'a pas été observé, et rien ne '
                    . 'permet d\'affirmer qu\'il est absent. ' . implode(' ', $treeFailures),
                );
        }

        if ($isRoot) {
            $observed = $this->ancestorPermissions(
                $spaces,
                $spaceId,
                $items,
                $groupIndex,
                PlanNode::ROOT_PATH,
                $cache,
                $unreadable,
            );

            if ($observed === null) {
                return NodeObservation::echec(
                    $node->path,
                    'relecture impossible : ' . ($unreadable[PlanNode::ROOT_PATH] ?? ''),
                );
            }
        } else {
            $read = $spaces->listItemPermissions($spaceId, $items[$node->path]);
            if ($read->isFailure()) {
                return NodeObservation::echec($node->path, 'relecture impossible : ' . $read->message);
            }
            $observed = $this->observedByKey($read, $groupIndex);
            $cache[$node->path] = $observed;
        }

        $grants = [];
        $closure = [];
        $foreign = 0;
        $unmodelled = 0;

        foreach ($observed as $key => $permission) {
            $principal = $projection->principals[$key] ?? null;

            if ($principal === null) {
                $foreign++;

                continue;
            }

            if ($permission->isUnmodelled()) {
                $unmodelled++;

                continue;
            }

            $grants[] = new ObservedGrant($principal['subject'], $permission->verbs());
        }

        // La CLÔTURE observée : un sujet que le plan referme ici est effectivement
        // clos s'il n'a d'octroi ni sur ce nœud, **ni sur AUCUN de ses ancêtres** —
        // la propagation est une propriété de l'octroi posé sur un item, pas de la
        // seule racine (mesuré : un octroi sur `_travail` rend son enfant
        // `devoirs` navigable).
        $closureBlind = false;
        $ancestorObserved = [];

        if (($projection->closedSubjects[$node->path] ?? []) !== []) {
            foreach (self::planAncestorsOf($node->path, $plan) as $ancestor) {
                $seen = $this->ancestorPermissions(
                    $spaces,
                    $spaceId,
                    $items,
                    $groupIndex,
                    $ancestor,
                    $cache,
                    $unreadable,
                );

                if ($seen === null) {
                    $closureBlind = true;

                    continue;
                }

                $ancestorObserved += $seen;
            }
        }

        foreach ($projection->closedSubjects[$node->path] ?? [] as $key => $subject) {
            if (array_key_exists($key, $observed)) {
                continue;
            }
            if (array_key_exists($key, $ancestorObserved)) {
                continue;
            }
            $closure[] = $subject;
        }

        $details = [];
        if ($foreign > 0) {
            $details[] = sprintf(
                '%d octroi(s) relu(s) ne correspondent à aucune identité connue de SE5 : ils sont comptés '
                . 'comme écart, pas ignorés.',
                $foreign,
            );
        }
        if ($unmodelled > 0) {
            $details[] = sprintf(
                '%d octroi(s) relu(s) portent un rôle que le plan ne sait pas décrire : SE5 ne l\'accorde '
                . 'jamais et ne sait pas le gouverner.',
                $unmodelled,
            );
        }
        if ($closureBlind) {
            $details[] = 'les octrois d\'un ancêtre de ce nœud n\'ont pas pu être relus : la clôture n\'est '
                . 'donc pas affirmée.';
        }

        // Le plafond : LU sur l'espace, et seulement pour le nœud RACINE — c'est
        // le seul endroit où ce modèle en porte un.
        //
        // ⚠️ **CE PRODUIT POSE UN PLAFOND PAR DÉFAUT À LA CRÉATION** (relevé du
        // 2026-08-13 : 1 000 000 000 octets sur un espace neuf). On le rapporte tel
        // qu'il est — dire « aucun » serait faux. Mais quiconque branchera un jour
        // la comparaison du plafond doit le savoir : un plan SANS plafond ne se
        // comparera pas à « rien », il se comparera à ce défaut, et le lira comme
        // un écart permanent sur chaque zone. Le comparateur d'aujourd'hui ne
        // compare pas le plafond ; la note est ici pour le jour où il le fera.
        $plafond = null;
        $plafondObserve = false;
        if ($isRoot) {
            $plafondObserve = true;
            $plafond = $this->observedQuota($spaceList, $spaceId);
        }

        return NodeObservation::observed(
            $node->path,
            $grants,
            $plafond,
            $plafondObserve,
            $details === [] ? null : implode(' ', $details),
            // La clôture est OBSERVÉE, donc comparable — et elle se pose dans le
            // champ qui existe déjà. Rien n'est ajouté à la structure
            // d'observation, et le comparateur n'est pas touché. `null` ne sort
            // que lorsqu'on ne SAIT rien : un ancêtre illisible.
            $closureBlind ? null : $closure,
        );
    }

    private function observedQuota(OpenCloudResult $spaceList, string $spaceId): ?int
    {
        foreach ($spaceList->entries() as $space) {
            if ((string) ($space['id'] ?? '') !== $spaceId) {
                continue;
            }
            $quota = $space['quota'] ?? null;
            $total = is_array($quota) ? (int) ($quota['total'] ?? 0) : 0;

            return $total > 0 ? $total : null;
        }

        return null;
    }

    // =========================================================================
    // quota
    // =========================================================================

    /**
     * LE PLAFOND D'UNE ZONE, ET RIEN D'AUTRE.
     *
     * Mesuré le 2026-08-13 : un plafond se pose sur un ESPACE
     * (`PATCH /drives/{id}` avec `{"quota":{"total":…}}` → `200`, relu exact), et
     * **pas** sur un sous-dossier (`405`, puis `400 « id does not belong to a
     * share jail »` sur l'autre version d'API). Le plafond du nœud RACINE s'y
     * projette donc exactement, et un plafond porté par un SOUS-nœud est
     * `non_exprimable` — une limite du MODÈLE, permanente, pas une dette de notre
     * code. L'affichage MASQUE ce réglage là où il ne veut rien dire.
     *
     * **Ce n'est PAS le quota d'une personne** (frontière D8) : budgéter un compte
     * est l'affaire de l'annuaire, et ce backend n'a aucun chemin vers les
     * comptes — un test l'épingle des deux côtés.
     *
     * Un plan sans plafond rend un rapport VIDE et parfaitement valide.
     */
    public function quota(FilePlan $plan): ReconciliationReport
    {
        $capped = $plan->cappedNodePaths();
        if ($capped === []) {
            return ReconciliationReport::coveringCapped($this->name(), $plan, []);
        }

        $transport = $this->transport();

        if ($transport instanceof OpenCloudConfigurationException) {
            $message = $transport->getMessage();

            return ReconciliationReport::coveringCapped($this->name(), $plan, array_map(
                static fn (string $path): NodeReconciliation => NodeReconciliation::echec($path, $message),
                $capped,
            ));
        }

        $spaces = new OpenCloudSpaceClient($transport);
        $listed = $spaces->listSpaces();
        $spaceId = $listed->isFailure() ? null : $this->findSpace($listed, $plan->rootPath);

        $entries = [];
        foreach ($capped as $path) {
            if ($path !== PlanNode::ROOT_PATH) {
                $entries[] = NodeReconciliation::nonExprimable(
                    $path,
                    'le plafond porte sur l\'ESPACE ENTIER dans ce modèle : un plafond de sous-dossier n\'y '
                    . 'est pas exprimable.',
                );

                continue;
            }

            if ($listed->isFailure()) {
                $entries[] = NodeReconciliation::echec($path, $listed->message);

                continue;
            }

            if ($spaceId === null) {
                $entries[] = NodeReconciliation::echec(
                    $path,
                    'aucun espace ne porte ce plan : le plafond n\'a pas été posé.',
                );

                continue;
            }

            $entries[] = $this->applyRootQuota($spaces, $spaceId, $plan, $path);
        }

        return ReconciliationReport::coveringCapped($this->name(), $plan, $entries);
    }

    private function applyRootQuota(OpenCloudSpaceClient $spaces, string $spaceId, FilePlan $plan, string $path): NodeReconciliation
    {
        $wanted = $plan->node($path)?->plafond;
        if ($wanted === null) {
            return NodeReconciliation::conforme($path, 'aucun plafond à poser.');
        }

        // LECTURE AVANT ÉCRITURE : un second passage n'émet rien.
        $before = $spaces->readSpace($spaceId);
        if (! $before->isFailure() && $this->quotaOf($before) === $wanted) {
            return NodeReconciliation::conforme($path, 'plafond déjà conforme.');
        }

        $set = $spaces->setSpaceQuota($spaceId, $wanted);
        if ($set->isFailure()) {
            return NodeReconciliation::echec($path, 'plafond non posé : ' . $set->message);
        }

        // COMPARER SUR LE RELU : c'est la seule preuve que la valeur a pris.
        $after = $spaces->readSpace($spaceId);
        if ($after->isFailure()) {
            return NodeReconciliation::echec(
                $path,
                'plafond envoyé, mais la relecture a échoué : rien ne prouve qu\'il a pris. ' . $after->message,
            );
        }

        return $this->quotaOf($after) === $wanted
            ? NodeReconciliation::applique($path, 'plafond posé et relu.')
            : NodeReconciliation::echec(
                $path,
                'le plafond relu diffère de celui demandé : l\'instance ne l\'a pas retenu.',
            );
    }

    private function quotaOf(OpenCloudResult $space): ?int
    {
        $quota = $space->value('quota');
        if (! is_array($quota)) {
            return null;
        }
        $total = (int) ($quota['total'] ?? 0);

        return $total > 0 ? $total : null;
    }

    // =========================================================================
    // location
    // =========================================================================

    /**
     * OÙ ce plan vit, POUR AFFICHAGE.
     *
     * Une phrase, pas une adresse à réutiliser : elle dit l'instance et le nom de
     * l'espace, c'est-à-dire ce qu'il faut savoir pour aller vérifier à la main.
     * `null` si la configuration ne permet pas de le dire.
     */
    public function location(FilePlan $plan): ?string
    {
        $transport = $this->transport();

        if ($transport instanceof OpenCloudConfigurationException) {
            return null;
        }

        return sprintf('%s — espace de projet « %s »', $transport->baseUrl(), $plan->rootPath);
    }

    // =========================================================================
    // Effondrement et utilitaires
    // =========================================================================

    /**
     * Le transport HTTP, ou l'exception de configuration à rendre telle quelle.
     *
     * Rendre l'exception plutôt que la lever garde le FAIL-CLOSED lisible au point
     * d'appel : chaque méthode du contrat en fait un rapport COMPLET (une entrée
     * par nœud), là où une exception traverserait la ligne et priverait
     * l'orchestrateur de son périmètre.
     */
    private function transport(): OpenCloudGraphTransport|OpenCloudConfigurationException
    {
        try {
            return new OpenCloudGraphTransport(OpenCloudConnectionConfig::current());
        } catch (OpenCloudConfigurationException $e) {
            return $e;
        }
    }

    /**
     * @param  list<FileBackendOutcome>  $outcomes
     * @param  list<string>  $details
     */
    private function collapse(string $path, array $outcomes, array $details): NodeReconciliation
    {
        $order = [
            FileBackendOutcome::Echec,
            FileBackendOutcome::NonExprimable,
            FileBackendOutcome::NonImplemente,
            FileBackendOutcome::Applique,
            FileBackendOutcome::Conforme,
        ];

        $winner = FileBackendOutcome::Conforme;
        foreach ($order as $candidate) {
            if (in_array($candidate, $outcomes, true)) {
                $winner = $candidate;
                break;
            }
        }

        $detail = implode(' ', array_filter(array_unique($details), static fn (string $d): bool => trim($d) !== ''));

        if ($winner->requiresDetail() && trim($detail) === '') {
            $detail = 'cause non détaillée par l\'instance.';
        }

        return new NodeReconciliation($path, $winner, $detail === '' ? null : $detail);
    }

    /**
     * @param  callable(string): NodeReconciliation  $factory
     */
    private function everyNode(FilePlan $plan, callable $factory): ReconciliationReport
    {
        return ReconciliationReport::covering($this->name(), $plan, array_map($factory, $plan->nodePaths()));
    }

    /**
     * @param  callable(string): NodeObservation  $factory
     */
    private function everyObservation(FilePlan $plan, callable $factory): InspectionReport
    {
        return InspectionReport::covering($this->name(), $plan, array_map($factory, $plan->nodePaths()));
    }

    /**
     * Le catalogue de rôles, interrogé exactement comme à l'écriture — famille
     * comprise : la racine du plan est un ESPACE, tout le reste est un élément, et
     * les deux familles n'offrent pas les mêmes rôles.
     *
     * Aucun rôle compatible = l'octroi n'est pas posable, et rien n'y remédiera :
     * ce modèle n'a pas de mécanisme de nœud qui rattraperait la découpe.
     */
    public function rendering(PlanNode $node, PlanGrant $grant): GrantRendering
    {
        $family = $node->path === PlanNode::ROOT_PATH
            ? OpenCloudRoleTable::FAMILY_SPACE
            : OpenCloudRoleTable::FAMILY_ITEM;

        $role = OpenCloudRoleTable::resolve($grant->verbs, $family);
        $rendered = $role['verbs'] ?? [];

        $inexpressible = [];
        foreach (array_diff($grant->verbs, $rendered) as $verb) {
            $inexpressible[$verb] = 'Aucun rôle de ce cloud ne transmet ce verbe sans en accorder un autre '
                . 'que vous n\'avez pas donné.';
        }

        return GrantRendering::of($grant->verbs, $rendered, $inexpressible);
    }
}
