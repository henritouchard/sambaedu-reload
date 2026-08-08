<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Nextcloud;

use App\Enums\FileBackendName;
use App\Enums\FileBackendOutcome;
use App\Exceptions\Nextcloud\NextcloudConfigurationException;
use App\Services\Filesystem\Backend\FileBackend;
use App\Services\Filesystem\Backend\InspectionReport;
use App\Services\Filesystem\Backend\NodeObservation;
use App\Services\Filesystem\Backend\NodeReconciliation;
use App\Services\Filesystem\Backend\ObservedGrant;
use App\Services\Filesystem\Backend\ReconciliationReport;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Story 61.3 — LE SECOND BACKEND RÉEL : un dossier d'équipe Nextcloud, derrière la
 * MÊME ligne de contrat.
 *
 * C'est ici que l'AUTORITÉ BASCULE. La story 61.1 avait ajouté un chemin d'accès
 * sans toucher à qui écrit les droits ; 61.2 avait connecté et sondé l'instance
 * sans rien exécuter. Ce fichier traverse la ligne : un partage peut désormais
 * vivre entièrement dans le cloud, sans aucun chemin SMB (D7 — une impossibilité
 * vérifiée, pas une coupe de périmètre).
 *
 * Le modèle d'implémentation est le backend du serveur de fichiers historique, à
 * l'identique : mêmes signatures, MÊME ORDRE D'EFFONDREMENT, lecture avant
 * écriture, verrou de passage, rapport couvrant exactement les nœuds du plan.
 *
 * ---------------------------------------------------------------------------
 * **LA CONVENTION DE PRÉCÉDENCE — le legs nommé du contrat, tenu ici aussi.**
 *
 *     `echec` > `non_exprimable` > `non_implemente` > `applique` > `conforme`
 *
 * Deux backends, un seul ordre : c'est ce qui rend deux rapports comparables. Un
 * succès partiel ne masque jamais un échec ; ce que le modèle ne sait pas dire prime
 * sur ce qu'on n'a pas codé ; « j'ai changé quelque chose » prime sur « il n'y avait
 * rien à faire ». `en_attente` ne sort JAMAIS d'ici : ce backend est synchrone
 * (HTTP séquencé) — c'est l'orchestrateur qui, au-dessus, dit « engagé, pas achevé »
 * quand il enfile.
 *
 * ---------------------------------------------------------------------------
 * **LA CLÔTURE EST EFFECTIVE, ET C'EST LA RAISON D'ÊTRE DE CE BACKEND.**
 *
 * Le sondage d'ouverture d'epic a mesuré, sur le mécanisme de PARTAGE, la fuite qui
 * a fait naître {@see \App\Services\Filesystem\Plan\PlanNode::$closure} : un octroi
 * posé sur un ancêtre propage au sous-arbre, l'instruction de retrait est acceptée
 * sans effet, et la relecture rend un accès là où on demandait zéro. Ce backend
 * n'emploie donc AUCUN partage : il emploie un dossier d'équipe et ses permissions
 * avancées, seul mécanisme mesuré capable d'exprimer une fermeture — après quoi un
 * élève obtient un refus sur le dossier privé des enseignants, qui DISPARAÎT même
 * de son listing.
 *
 * **Et là où elle ne peut pas l'être, on le DIT.** « Non exprimable » se CONSTATE :
 * on pose, puis on RELIT, et si l'accès d'un rôle clos survit à la relecture, le
 * nœud rend `non_exprimable` en nommant le rôle. Jamais `applique` sur la foi d'une
 * enveloppe verte — l'enveloppe de ce protocole ne conclut rien par construction.
 *
 * ---------------------------------------------------------------------------
 * **L'ORDRE DES GESTES EST UN ORDRE DE SÛRETÉ, pas seulement de dépendance.**
 *
 *  1. adopter ou créer le dossier d'équipe (reconnaissance sur le point de montage
 *     RELU, jamais sur celui qu'on a envoyé) ;
 *  2. assurer le groupe STRUCTUREL d'administration — sans lui, le compte qui
 *     écrit ne voit pas le dossier et aucune règle n'est posable ;
 *  3. ACTIVER les permissions avancées — **avant** que le moindre groupe du plan
 *     reçoive un plafond. Sans cet interrupteur, les règles sont acceptées et
 *     ignorées : les activer plus tard ouvrirait une fenêtre où les plafonds
 *     existent et où rien ne les rabaisse ;
 *  4. assurer les groupes du plan et converger leur appartenance ;
 *  5. créer l'arborescence, UN NIVEAU À LA FOIS, du plus haut au plus bas ;
 *  6. poser les règles, nœud par nœud, et RELIRE ;
 *  7. **en DERNIER**, relever les plafonds de la carte des groupes. Le seul geste
 *     qui élargit est le dernier : si la séquence s'interrompt, elle s'interrompt
 *     du côté fermé.
 *
 * ---------------------------------------------------------------------------
 * **L'IDEMPOTENCE EST VRAIE, ET ELLE SE JOUE SUR LE RELU.** Chaque écriture est
 * précédée d'une lecture, et la comparaison porte sur les valeurs RELUES en
 * IGNORANT les champs que le serveur ajoute (le libellé d'affichage d'un principal,
 * la barre oblique d'un point de montage). Cet epic a rencontré trois fois le même
 * piège ; comparer l'envoyé au relu produirait une dérive permanente avec tous les
 * doubles verts.
 *
 * **AUCUN OUTIL EN LIGNE DE COMMANDE.** Le sondage 60.0 posait sa clôture avec
 * l'outil d'administration de l'instance : c'est un accès système AU SERVEUR
 * NEXTCLOUD, qu'on n'a pas sur une instance distante ou tierce. Ce backend est
 * 100 % HTTP, et un test d'architecture l'épingle.
 */
final class NextcloudFileBackend implements FileBackend
{
    private const LOCK_PREFIX = 'network-shares:provision:nextcloud:';

    private const LOCK_SECONDS = 120;

    /** La révocation ATTEND son tour, là où la mise en place renonce (iso 60.4). */
    private const REVOKE_WAIT_SECONDS = 15;

    public function __construct(private readonly NextcloudSubjectProjector $projector)
    {
    }

    public function name(): FileBackendName
    {
        return FileBackendName::Nextcloud;
    }

    // =========================================================================
    // provision
    // =========================================================================

    public function provision(FilePlan $plan): ReconciliationReport
    {
        try {
            $config = NextcloudConnectionConfig::current();
        } catch (NextcloudConfigurationException $e) {
            // FAIL-CLOSED sur la configuration, AVANT le premier appel : capacité
            // éteinte, réglage manquant, secret absent. Le refus nomme ce qui
            // manque plutôt que de partir écrire au hasard.
            return $this->everyNode($plan, fn (string $path): NodeReconciliation => NodeReconciliation::echec(
                $path,
                $e->getMessage(),
            ));
        }

        $lock = Cache::store('file')->lock(self::LOCK_PREFIX . $plan->rootPath, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return $this->everyNode($plan, static fn (string $path): NodeReconciliation => NodeReconciliation::echec(
                $path,
                'une autre réconciliation est en cours sur ce répertoire : ce passage n\'a rien écrit.',
            ));
        }

        try {
            return $this->converge($plan, new NextcloudTeamFolderClient($config), new NextcloudDavClient($config), $config);
        } finally {
            $lock->release();
        }
    }

    private function converge(
        FilePlan $plan,
        NextcloudTeamFolderClient $folders,
        NextcloudDavClient $dav,
        NextcloudConnectionConfig $config,
    ): ReconciliationReport {
        $projection = NextcloudPlanProjection::compile($plan, $this->projector);

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

        // --- 1. Le dossier d'équipe : adopté sur le point de montage RELU -----
        $folder = $this->resolveFolder($folders, $plan->rootPath);

        if ($folder['error'] !== null) {
            return $this->everyNode($plan, static fn (string $p): NodeReconciliation => NodeReconciliation::echec(
                $p,
                $folder['error'],
            ));
        }

        $folderId = $folder['id'];
        $rootTouched = $folder['created'];

        // --- 2. Le groupe STRUCTUREL d'administration -------------------------
        $structural = $this->ensureStructuralAccess($folders, $folderId, $config, $folder['groups'], $rootTouched);
        if ($structural !== null) {
            return $this->everyNode($plan, static fn (string $p): NodeReconciliation => NodeReconciliation::echec($p, $structural));
        }

        // --- 3. L'interrupteur des permissions avancées, AVANT les plafonds ---
        if (! ($folder['aclEnabled'] ?? false)) {
            $toggled = $folders->enableAdvancedPermissions($folderId);
            if ($toggled->isFailure()) {
                return $this->everyNode($plan, static fn (string $p): NodeReconciliation => NodeReconciliation::echec(
                    $p,
                    'les permissions avancées du dossier d\'équipe n\'ont pas pu être activées : sans elles, '
                    . 'les règles de cloisonnement sont acceptées SANS AUCUN EFFET. ' . $toggled->message,
                ));
            }
            $rootTouched = true;
        }

        // --- 4. Les groupes du plan et leur appartenance ----------------------
        $groupFailures = $this->convergeGroups($folders, $projection, $config, $rootTouched);

        // --- 5 & 6. L'arborescence PUIS les règles, un niveau à la fois --------
        //
        // **LECTURE AVANT ÉCRITURE, ICI AUSSI.** On relit l'état du nœud d'abord :
        // il dit à la fois si le dossier existe et quelles règles y sont posées. Un
        // second passage sur un état conforme n'émet donc AUCUNE écriture — pas même
        // une création rejouée dont l'instance se contenterait de refuser la méthode.
        //
        // L'ordre est celui des NIVEAUX, du plus haut au plus bas : ce protocole ne
        // crée pas les parents, et une règle se pose sur un chemin qui existe.
        foreach ([PlanNode::ROOT_PATH, ...NextcloudPlanProjection::creationOrder($plan)] as $path) {
            $node = $plan->node($path);
            if ($node === null || in_array(FileBackendOutcome::Echec, $outcomes[$path], true)) {
                continue;
            }

            $this->reconcileNode($dav, $plan, $node, $projection, $outcomes[$path], $details[$path]);
        }

        // --- 7. Les plafonds, EN DERNIER (le seul geste qui élargit) -----------
        $ceilingFailures = $this->convergeCeilings($folders, $folderId, $folder['groups'], $projection);

        foreach ([$groupFailures, $ceilingFailures] as $failures) {
            foreach ($failures as $message) {
                foreach ($plan->nodePaths() as $path) {
                    $outcomes[$path][] = FileBackendOutcome::Echec;
                    $details[$path][] = $message;
                }
            }
        }

        if ($rootTouched) {
            $outcomes[PlanNode::ROOT_PATH][] = FileBackendOutcome::Applique;
        }

        // Les CONSTATS : ils n'empêchent rien et ne changent aucun état — ils
        // disent ce que l'exploitant doit savoir (des personnes sans compte, donc
        // sans accès effectif). Les taire ferait croire à un octroi qui atteint tout
        // le monde ; les rendre bloquants peindrait en rouge une zone parfaitement
        // provisionnée.
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
     * Le dossier d'équipe de ce plan : ADOPTÉ s'il existe déjà au même point de
     * montage, créé sinon.
     *
     * **La reconnaissance porte sur le point de montage RELU.** L'instance rend
     * parfois une barre oblique de tête que personne n'a écrite ; comparer sur ce
     * qu'on a envoyé ferait créer un second dossier à chaque passage, ou déclarer
     * absent un dossier parfaitement en place.
     *
     * @return array{id:int, created:bool, groups:array<string,int>, aclEnabled:bool, error:?string}
     */
    private function resolveFolder(NextcloudTeamFolderClient $folders, string $rootPath): array
    {
        $listed = $folders->listFolders();
        if ($listed->isFailure()) {
            return ['id' => 0, 'created' => false, 'groups' => [], 'aclEnabled' => false, 'error' => $listed->message];
        }

        $existing = $this->findFolder($listed->value('folders', []), $rootPath);
        if ($existing !== null) {
            return $existing + ['created' => false, 'error' => null];
        }

        $created = $folders->createFolder($rootPath);
        if ($created->isFailure()) {
            return ['id' => 0, 'created' => false, 'groups' => [], 'aclEnabled' => false, 'error' => $created->message];
        }

        // On RELIT : l'identifiant rendu à la création est une commodité, la vérité
        // est l'inventaire — et c'est lui qui porte le point de montage normalisé.
        $listed = $folders->listFolders();
        if ($listed->isFailure()) {
            return ['id' => 0, 'created' => false, 'groups' => [], 'aclEnabled' => false, 'error' => $listed->message];
        }

        $existing = $this->findFolder($listed->value('folders', []), $rootPath);
        if ($existing === null) {
            return [
                'id' => 0,
                'created' => false,
                'groups' => [],
                'aclEnabled' => false,
                'error' => 'le dossier d\'équipe a été demandé mais ne figure pas dans l\'inventaire relu : '
                    . 'rien ne prouve qu\'il a été créé.',
            ];
        }

        return $existing + ['created' => true, 'error' => null];
    }

    /**
     * @param  mixed  $folders
     * @return array{id:int, groups:array<string,int>, aclEnabled:bool}|null
     */
    private function findFolder(mixed $folders, string $rootPath): ?array
    {
        foreach (is_array($folders) ? $folders : [] as $folder) {
            if (! is_array($folder)) {
                continue;
            }
            if (NextcloudTeamFolderClient::mountPointOf($folder) !== $rootPath) {
                continue;
            }

            $groups = [];
            foreach (is_array($folder['groups'] ?? null) ? $folder['groups'] : [] as $name => $permissions) {
                // Selon la version, la carte est `nom => permissions` ou une liste
                // d'objets. On accepte les deux plutôt que d'en présumer une.
                if (is_array($permissions)) {
                    $groups[(string) ($permissions['group_id'] ?? $name)] = (int) ($permissions['permissions'] ?? 0);

                    continue;
                }
                $groups[(string) $name] = (int) $permissions;
            }

            return [
                'id' => (int) ($folder['id'] ?? 0),
                'groups' => $groups,
                'aclEnabled' => (bool) ($folder['acl'] ?? false),
            ];
        }

        return null;
    }

    /**
     * Le groupe STRUCTUREL d'administration : il n'est pas un octroi du plan, il est
     * le contrat de structure qui rend le dossier ATTEIGNABLE par le compte qui
     * écrit. Sans lui, ni les sous-dossiers ni les règles ne sont posables.
     *
     * Rend `null` si tout va bien, la cause sinon.
     */
    /**
     * @param  array<string, int>  $observed  carte des groupes RELUE du dossier
     */
    private function ensureStructuralAccess(
        NextcloudTeamFolderClient $folders,
        int $folderId,
        NextcloudConnectionConfig $config,
        array $observed,
        bool &$touched,
    ): ?string {
        $group = NextcloudSubjectProjector::ADMIN_GROUP;

        // LECTURE AVANT ÉCRITURE : on ne crée que ce qui manque.
        $members = $folders->groupMembers($group);

        if ($members->isFailure()) {
            $created = $folders->ensureGroup($group);
            if ($created->isFailure()) {
                return 'le groupe d\'administration du dossier d\'équipe n\'a pas pu être assuré : ' . $created->message;
            }
            $touched = true;
            $members = $folders->groupMembers($group);
            if ($members->isFailure()) {
                return 'l\'appartenance au groupe d\'administration n\'a pas pu être relue : ' . $members->message;
            }
        }

        if (! in_array($config->adminUser, (array) $members->value('members', []), true)) {
            $added = $folders->addUserToGroup($config->adminUser, $group);
            if ($added->isFailure()) {
                return 'le compte d\'administration n\'a pas pu être rattaché au groupe d\'administration : '
                    . $added->message;
            }
            $touched = true;
        }

        if (($observed[$group] ?? null) === NextcloudPermissionBits::ALL_MODELLED) {
            return null;
        }

        if (! array_key_exists($group, $observed)) {
            $attached = $folders->addGroup($folderId, $group);
            if ($attached->isFailure() && ! $attached->alreadyConforming) {
                return 'le groupe d\'administration n\'a pas pu être ajouté au dossier d\'équipe : ' . $attached->message;
            }
        }

        $permissions = $folders->setGroupPermissions($folderId, $group, NextcloudPermissionBits::ALL_MODELLED);
        $touched = true;

        return $permissions->isFailure()
            ? 'les permissions du groupe d\'administration n\'ont pas pu être posées : ' . $permissions->message
            : null;
    }

    /**
     * Assure l'existence des groupes du plan et CONVERGE leur appartenance.
     *
     * @return list<string> les causes, vides si tout a convergé
     */
    private function convergeGroups(
        NextcloudTeamFolderClient $folders,
        NextcloudPlanProjection $projection,
        NextcloudConnectionConfig $config,
        bool &$touched,
    ): array {
        $failures = [];

        foreach ($projection->groups as $name => $spec) {
            // LECTURE AVANT ÉCRITURE : on ne crée le groupe que s'il manque
            // réellement. Le créer à chaque passage « puisque c'est idempotent »
            // rendrait fausse la promesse « second passage, zéro écriture ».
            $read = $folders->groupMembers($name);

            if ($read->isFailure()) {
                $created = $folders->ensureGroup($name);
                if ($created->isFailure()) {
                    $failures[] = sprintf('le groupe « %s » n\'a pas pu être assuré : %s', $name, $created->message);

                    continue;
                }
                $touched = true;

                $read = $folders->groupMembers($name);
                if ($read->isFailure()) {
                    $failures[] = sprintf(
                        'l\'appartenance du groupe « %s » n\'a pas pu être relue : %s',
                        $name,
                        $read->message,
                    );

                    continue;
                }
            }

            /** @var list<string> $current */
            $current = (array) $read->value('members', []);
            $wanted = $spec['members'];

            foreach (array_diff($wanted, $current) as $userId) {
                $added = $folders->addUserToGroup($userId, $name);
                if ($added->isFailure()) {
                    $failures[] = sprintf('un compte n\'a pas pu rejoindre le groupe « %s » : %s', $name, $added->message);

                    continue;
                }
                $touched = true;
            }

            foreach (array_diff($current, $wanted) as $userId) {
                // ---------------------------------------------------------------
                // CORRECTION DE REVUE 61.3 #4 — LA GARDE EXISTE MAINTENANT.
                //
                // Ce commentaire décrivait une protection que la boucle n'appliquait
                // PAS : le retrait était inconditionnel. Le compte d'administration
                // n'appartient pas aux groupes du plan ; s'il s'y trouve, c'est un
                // état voulu par l'exploitant, pas une dérive à corriger — et le
                // retirer lui ferait perdre l'accès qu'il s'est donné. C'est la même
                // doctrine que celle appliquée aux groupes ÉTRANGERS quelques lignes
                // plus bas : hors du plan, hors du geste.
                //
                // La classe de défaut est vicieuse : le retrait réussit, le passage
                // est vert, et l'effet ne se manifeste qu'au passage SUIVANT, sous la
                // forme d'un dossier soudain inatteignable.
                //
                // **Comparaison insensible à la casse, à dessein.** Les deux erreurs
                // ne coûtent pas le même prix : sauter un retrait laisse une
                // appartenance périmée, qui se voit ; retirer à tort retire l'accès
                // du compte qui écrit, ce qui ne se voit pas avant le passage
                // suivant. On protège plus large que strictement nécessaire.
                // ---------------------------------------------------------------
                if (mb_strtolower((string) $userId) === mb_strtolower($config->adminUser)) {
                    continue;
                }

                $removed = $folders->removeUserFromGroup($userId, $name);
                if ($removed->isFailure()) {
                    $failures[] = sprintf('un compte n\'a pas pu quitter le groupe « %s » : %s', $name, $removed->message);

                    continue;
                }
                $touched = true;
            }
        }

        return $failures;
    }

    /**
     * Relève (ou rabaisse) les plafonds de la carte des groupes, et retire de cette
     * carte les groupes COMPILÉS PAR SE5 que le plan n'exprime plus.
     *
     * **Un groupe étranger n'est jamais touché** : hors du plan, hors du geste. La
     * révocation ne porte que sur ce que SE5 a posé, reconnaissable à son préfixe.
     *
     * @param  array<string, int>  $observed
     * @return list<string>
     */
    private function convergeCeilings(
        NextcloudTeamFolderClient $folders,
        int $folderId,
        array $observed,
        NextcloudPlanProjection $projection,
    ): array {
        $failures = [];

        foreach ($projection->ceilings as $name => $bits) {
            if (($observed[$name] ?? null) === $bits) {
                continue;
            }

            if (! array_key_exists($name, $observed)) {
                $attached = $folders->addGroup($folderId, $name);
                if ($attached->isFailure() && ! $attached->alreadyConforming) {
                    $failures[] = sprintf(
                        'le groupe « %s » n\'a pas pu être ajouté au dossier d\'équipe : %s',
                        $name,
                        $attached->message,
                    );

                    continue;
                }
            }

            $set = $folders->setGroupPermissions($folderId, $name, $bits);
            if ($set->isFailure()) {
                $failures[] = sprintf(
                    'le plafond du groupe « %s » n\'a pas pu être posé : %s',
                    $name,
                    $set->message,
                );
            }
        }

        foreach (array_keys($observed) as $name) {
            if (array_key_exists($name, $projection->ceilings)) {
                continue;
            }
            if ($name === NextcloudSubjectProjector::ADMIN_GROUP) {
                continue;
            }
            if (! str_starts_with($name, NextcloudSubjectProjector::GROUP_PREFIX)) {
                continue;
            }

            $removed = $folders->removeGroup($folderId, $name);
            if ($removed->isFailure()) {
                $failures[] = sprintf(
                    'le groupe « %s », que le plan n\'exprime plus, n\'a pas pu être retiré : %s',
                    $name,
                    $removed->message,
                );
            }
        }

        return $failures;
    }

    /**
     * Pose les règles d'un nœud, puis RELIT pour constater ce qui a réellement pris.
     *
     * **La relecture n'est pas une politesse.** C'est elle qui distingue `applique`
     * de `non_exprimable` : un rôle clos dont l'accès SURVIT à la pose est un
     * cloisonnement affiché mais inexistant, et le taire referait exactement la
     * fuite que ce backend existe pour fermer.
     *
     * @param  list<FileBackendOutcome>  $outcomes
     * @param  list<string>  $details
     */
    private function reconcileNode(
        NextcloudDavClient $dav,
        FilePlan $plan,
        PlanNode $node,
        NextcloudPlanProjection $projection,
        array &$outcomes,
        array &$details,
    ): void {
        $remote = $this->remotePath($plan, $node->path);
        $wanted = $projection->rulesFor($node->path);

        // LECTURE AVANT ÉCRITURE : un passage sur un état conforme n'émet rien.
        $before = $dav->readAcl($remote);

        if (! $before->readable) {
            $outcomes[] = FileBackendOutcome::Echec;
            $details[] = 'relecture des règles impossible avant écriture : ' . (string) $before->error;

            return;
        }

        if (! $before->exists) {
            // Le dossier manque : on le crée, UN NIVEAU à la fois. La racine, elle,
            // naît avec le dossier d'équipe — si elle manque ici, c'est un vrai
            // défaut, pas une étape.
            if ($node->path === PlanNode::ROOT_PATH) {
                $outcomes[] = FileBackendOutcome::Echec;
                $details[] = 'la racine du dossier d\'équipe n\'est pas atteignable par le compte '
                    . 'd\'administration : aucune règle n\'a été posée.';

                return;
            }

            $created = $dav->makeCollection($remote);
            if (! $created->ok) {
                $outcomes[] = FileBackendOutcome::Echec;
                $details[] = 'création du dossier impossible : ' . (string) $created->error;

                return;
            }

            $outcomes[] = FileBackendOutcome::Applique;

            $before = $dav->readAcl($remote);
            if (! $before->readable || ! $before->exists) {
                $outcomes[] = FileBackendOutcome::Echec;
                $details[] = 'le dossier vient d\'être créé mais ne se relit pas : rien ne prouve qu\'il est là. '
                    . (string) $before->error;

                return;
            }
        }

        $state = $before;

        if (! $before->carriesExactly($wanted)) {
            $written = $dav->writeAcl($remote, $wanted);
            if (! $written->ok) {
                $outcomes[] = FileBackendOutcome::Echec;
                $details[] = 'pose des règles de cloisonnement impossible : ' . (string) $written->error;

                return;
            }

            $outcomes[] = FileBackendOutcome::Applique;

            // CONSTATER, jamais présumer : on relit ce que l'instance a retenu.
            $state = $dav->readAcl($remote);
            if (! $state->readable) {
                $outcomes[] = FileBackendOutcome::Echec;
                $details[] = 'relecture des règles impossible après écriture : rien ne prouve qu\'elles ont pris. '
                    . (string) $state->error;

                return;
            }
        } else {
            $outcomes[] = FileBackendOutcome::Conforme;
        }

        $survivors = $this->survivingClosures($node, $projection, $state);

        if ($survivors !== []) {
            $outcomes[] = FileBackendOutcome::NonExprimable;
            $details[] = sprintf(
                'cloisonnement non obtenu : après pose ET relecture, l\'accès subsiste pour %d principal(aux) '
                . 'que le plan referme ici (%s). Ce dossier N\'EST PAS cloisonné pour eux.',
                count($survivors),
                implode(', ', array_map(static fn (string $s): string => '« ' . $s . ' »', $survivors)),
            );
        }
    }

    /**
     * Les principaux que le plan referme sur ce nœud et dont l'accès SURVIT à la
     * relecture.
     *
     * L'effectif se lit dans l'ordre du modèle : la règle posée ICI l'emporte, à
     * défaut celle qui DESCEND de l'ancêtre, à défaut le plafond de la carte des
     * groupes. C'est exactement la distinction que la clôture existe pour trancher,
     * et l'instance la nomme elle-même.
     *
     * @return list<string>
     */
    private function survivingClosures(PlanNode $node, NextcloudPlanProjection $projection, NextcloudAclState $state): array
    {
        $survivors = [];

        foreach ($projection->desired[$node->path] ?? [] as $key => $bits) {
            if ($bits !== 0) {
                continue;
            }

            if ($this->effectiveBits($key, $projection, $state) !== 0) {
                $survivors[] = $projection->principals[$key]['id'];
            }
        }

        sort($survivors, SORT_STRING);

        return $survivors;
    }

    /**
     * @param  array<string, int>|null  $ceilings  plafonds OBSERVÉS, `null` = ceux du plan
     */
    private function effectiveBits(
        string $principalKey,
        NextcloudPlanProjection $projection,
        NextcloudAclState $state,
        ?array $ceilings = null,
    ): int {
        $own = $state->ruleFor($principalKey);
        if ($own !== null) {
            return $own->permissions;
        }

        $inherited = $state->inheritedRuleFor($principalKey);
        if ($inherited !== null) {
            return $inherited->permissions;
        }

        if ($ceilings === null) {
            return $projection->effectiveCeilings[$principalKey] ?? 0;
        }

        return $this->observedCeilingFor($principalKey, $projection, $ceilings);
    }

    /**
     * Le plafond OBSERVÉ d'un principal : le sien s'il est un groupe, celui des
     * groupes qui le portent s'il est un compte.
     *
     * @param  array<string, int>  $ceilings
     */
    private function observedCeilingFor(string $principalKey, NextcloudPlanProjection $projection, array $ceilings): int
    {
        $principal = $projection->principals[$principalKey] ?? null;
        if ($principal === null) {
            return 0;
        }

        if ($principal['type'] === NextcloudAclRule::TYPE_GROUP) {
            return $ceilings[$principal['id']] ?? 0;
        }

        $bits = 0;
        foreach ($projection->groups as $name => $spec) {
            if (in_array($principal['id'], $spec['members'], true)) {
                $bits |= $ceilings[$name] ?? 0;
            }
        }

        return $bits;
    }

    // =========================================================================
    // deprovision
    // =========================================================================

    /**
     * RÉVOQUE les octrois — SANS DÉTRUIRE NI LE DOSSIER NI SON CONTENU.
     *
     * **Aucun chemin de production ne supprime un dossier d'équipe** (D9, épinglé
     * par test). La séquence est : retirer les règles posées sur les nœuds du plan,
     * puis retirer de la carte du dossier les groupes que SE5 y a mis. Le dossier
     * reste, ses données restent, et le compte d'administration y garde accès —
     * exactement comme le groupe d'administration d'annuaire survit à la révocation
     * d'un répertoire du serveur de fichiers historique.
     *
     * Ce qui n'a jamais été posé par SE5 n'est pas touché : hors du plan, hors du
     * geste.
     */
    public function deprovision(FilePlan $plan): ReconciliationReport
    {
        try {
            $config = NextcloudConnectionConfig::current();
        } catch (NextcloudConfigurationException $e) {
            return $this->everyNode($plan, fn (string $path): NodeReconciliation => NodeReconciliation::echec(
                $path,
                $e->getMessage(),
            ));
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
            $folders = new NextcloudTeamFolderClient($config);
            $dav = new NextcloudDavClient($config);

            $listed = $folders->listFolders();
            if ($listed->isFailure()) {
                return $this->everyNode($plan, static fn (string $p): NodeReconciliation => NodeReconciliation::echec(
                    $p,
                    $listed->message,
                ));
            }

            $folder = $this->findFolder($listed->value('folders', []), $plan->rootPath);
            if ($folder === null) {
                return $this->everyNode($plan, static fn (string $p): NodeReconciliation => NodeReconciliation::conforme(
                    $p,
                    'aucun dossier d\'équipe ne porte ce plan : rien à révoquer.',
                ));
            }

            $entries = [];
            foreach ($plan->nodes as $node) {
                $cleared = $dav->writeAcl($this->remotePath($plan, $node->path), []);

                $entries[] = $cleared->ok
                    ? NodeReconciliation::applique(
                        $node->path,
                        'règles retirées ; le dossier et son contenu restent (aucune donnée détruite).',
                    )
                    : NodeReconciliation::echec(
                        $node->path,
                        'retrait des règles impossible : ' . (string) $cleared->error,
                    );
            }

            // Les groupes du plan quittent la carte du dossier : plus personne ne
            // le monte, et rien n'a été détruit.
            $failures = [];
            foreach (array_keys($folder['groups']) as $name) {
                if ($name === NextcloudSubjectProjector::ADMIN_GROUP) {
                    continue;
                }
                if (! str_starts_with((string) $name, NextcloudSubjectProjector::GROUP_PREFIX)) {
                    continue;
                }
                $removed = $folders->removeGroup($folder['id'], (string) $name);
                if ($removed->isFailure()) {
                    $failures[] = sprintf('le groupe « %s » n\'a pas pu être retiré : %s', $name, $removed->message);
                }
            }

            if ($failures !== []) {
                $cause = implode(' ', $failures);

                return $this->everyNode($plan, static fn (string $p): NodeReconciliation => NodeReconciliation::echec($p, $cause));
            }

            return ReconciliationReport::covering($this->name(), $plan, $entries);
        } finally {
            $lock->release();
        }
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
     * **« Conforme » et « non mesurable » ne se confondent jamais.** Ce qu'on n'a pas
     * pu lire rend une observation non mesurable ou en échec — jamais une observation
     * VIDE, qui se lirait « tout va bien ».
     *
     * **Ce qui n'est structurellement PAS observable par le serveur** — la
     * perception EFFECTIVE d'un élève, c'est-à-dire le refus qu'il obtient et
     * l'absence du dossier de son listing — n'est pas déduit des règles relues. Il
     * est dit au runbook et MESURÉ par le test d'intégration avec des comptes
     * jetables. Une règle relue prouve une règle, pas une perception.
     */
    public function inspect(FilePlan $plan): InspectionReport
    {
        try {
            $config = NextcloudConnectionConfig::current();
        } catch (NextcloudConfigurationException $e) {
            return $this->everyObservation($plan, fn (string $p): NodeObservation => NodeObservation::echec($p, $e->getMessage()));
        }

        $folders = new NextcloudTeamFolderClient($config);
        $dav = new NextcloudDavClient($config);
        $projection = NextcloudPlanProjection::compile($plan, $this->projector);

        $listed = $folders->listFolders();
        if ($listed->isFailure()) {
            return $this->everyObservation($plan, fn (string $p): NodeObservation => NodeObservation::echec($p, $listed->message));
        }

        $folder = $this->findFolder($listed->value('folders', []), $plan->rootPath);
        if ($folder === null) {
            // Un FAIT constaté : l'inventaire a répondu et ne porte pas ce plan.
            return $this->everyObservation($plan, static fn (string $p): NodeObservation => NodeObservation::absent($p));
        }

        $observations = [];
        foreach ($plan->nodes as $node) {
            $observations[] = $this->inspectNode($dav, $plan, $node, $projection, $folder);
        }

        return InspectionReport::covering($this->name(), $plan, $observations);
    }

    /**
     * @param  array{id:int, groups:array<string,int>, aclEnabled:bool}  $folder
     */
    private function inspectNode(
        NextcloudDavClient $dav,
        FilePlan $plan,
        PlanNode $node,
        NextcloudPlanProjection $projection,
        array $folder,
    ): NodeObservation {
        $state = $dav->readAcl($this->remotePath($plan, $node->path));

        if (! $state->readable) {
            return NodeObservation::echec($node->path, 'relecture impossible : ' . (string) $state->error);
        }

        if (! $state->exists) {
            return NodeObservation::absent($node->path);
        }

        $index = $projection->principals;
        $ceilings = $folder['groups'];

        $grants = [];
        $closure = [];
        $foreign = 0;
        $unmodelled = 0;

        // Les principaux à REGARDER : ceux que le plan exprime ici, et ceux qu'une
        // règle posée sur ce nœud désigne (une règle ajoutée à la main est un écart,
        // pas un détail).
        $keys = array_keys($projection->desired[$node->path] ?? []);
        foreach ($state->rules as $rule) {
            $keys[] = $rule->principalKey();
        }
        if ($node->path === PlanNode::ROOT_PATH) {
            foreach (array_keys($ceilings) as $name) {
                $keys[] = NextcloudAclRule::TYPE_GROUP . ':' . $name;
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        foreach ($keys as $key) {
            $principal = $index[$key] ?? null;

            if ($principal === null) {
                // Le groupe STRUCTUREL d'administration appartient au contrat du
                // dossier, pas à son audience — même frontière que le groupe
                // d'administration d'annuaire côté serveur de fichiers historique.
                if ($key === NextcloudAclRule::TYPE_GROUP . ':' . NextcloudSubjectProjector::ADMIN_GROUP) {
                    continue;
                }
                $foreign++;

                continue;
            }

            $bits = $this->effectiveBits($key, $projection, $state, $ceilings);

            if (NextcloudPermissionBits::hasUnmodelledBits($bits)) {
                $unmodelled++;
            }

            $desired = $projection->desiredBits($node->path, $key);
            $isClosed = $desired === 0 && $this->isClosedHere($node, $projection, $key);

            if ($isClosed && $bits === 0) {
                $closure[] = $principal['subject'];

                continue;
            }

            if ($desired === null && $bits === 0) {
                // Le plan ne dit rien de lui ici, et il n'a rien : il n'y a
                // strictement rien à observer.
                continue;
            }

            $grants[] = new ObservedGrant($principal['subject'], NextcloudPermissionBits::toVerbs($bits));
        }

        $details = [];
        if ($foreign > 0) {
            $details[] = sprintf(
                '%d principal(aux) relu(s) ne correspondent à aucune identité connue de SE5 : ils sont '
                . 'comptés comme écart, pas ignorés.',
                $foreign,
            );
        }
        if ($unmodelled > 0) {
            $details[] = sprintf(
                '%d permission(s) relue(s) sortent de ce que le plan sait décrire (re-partage) : SE5 ne les '
                . 'accorde jamais et ne sait pas les gouverner.',
                $unmodelled,
            );
        }

        return NodeObservation::observed(
            $node->path,
            $grants,
            null,
            false,
            $details === [] ? null : implode(' ', $details),
            // ÉVOLUTION ADDITIVE : la clôture devient OBSERVÉE, donc comparable.
            $closure,
        );
    }

    /** Ce principal est-il refermé ICI par le plan (et non pas simplement suspendu) ? */
    private function isClosedHere(PlanNode $node, NextcloudPlanProjection $projection, string $key): bool
    {
        foreach ($projection->closedSubjects[$node->path] ?? [] as $subject) {
            if (($projection->keyBySubject[$subject->sortKey()] ?? null) === $key) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // quota
    // =========================================================================

    /**
     * Story 61.3 — LE PREMIER BACKEND QUI SAIT PLAFONNER UNE ZONE.
     *
     * Le plafond d'un dossier d'équipe porte sur LE DOSSIER ENTIER : le plafond du
     * nœud RACINE s'y projette exactement, et un plafond porté par un SOUS-nœud est
     * `non_exprimable` — c'est une limite du MODÈLE, permanente, pas une dette de
     * notre code. L'affichage MASQUE ce réglage là où il ne veut rien dire.
     *
     * **Ce n'est PAS le quota d'une personne** (frontière D8) : budgéter un compte
     * est l'affaire du provisionnement des utilisateurs, et ce backend n'a aucun
     * chemin vers les comptes — un test l'épingle des deux côtés.
     *
     * Un plan sans plafond rend un rapport VIDE et parfaitement valide.
     */
    public function quota(FilePlan $plan): ReconciliationReport
    {
        $capped = $plan->cappedNodePaths();
        if ($capped === []) {
            return ReconciliationReport::coveringCapped($this->name(), $plan, []);
        }

        try {
            $config = NextcloudConnectionConfig::current();
        } catch (NextcloudConfigurationException $e) {
            return ReconciliationReport::coveringCapped($this->name(), $plan, array_map(
                static fn (string $path): NodeReconciliation => NodeReconciliation::echec($path, $e->getMessage()),
                $capped,
            ));
        }

        $folders = new NextcloudTeamFolderClient($config);
        $listed = $folders->listFolders();
        $folder = $listed->isFailure()
            ? null
            : $this->findFolder($listed->value('folders', []), $plan->rootPath);

        $entries = [];
        foreach ($capped as $path) {
            if ($path !== PlanNode::ROOT_PATH) {
                $entries[] = NodeReconciliation::nonExprimable(
                    $path,
                    'le plafond d\'un dossier d\'équipe porte sur le dossier ENTIER : un plafond de '
                    . 'sous-dossier n\'est pas exprimable dans ce modèle.',
                );

                continue;
            }

            if ($listed->isFailure()) {
                $entries[] = NodeReconciliation::echec($path, $listed->message);

                continue;
            }

            if ($folder === null) {
                $entries[] = NodeReconciliation::echec(
                    $path,
                    'aucun dossier d\'équipe ne porte ce plan : le plafond n\'a pas été posé.',
                );

                continue;
            }

            $entries[] = $this->applyRootQuota($folders, $folder['id'], $plan, $path);
        }

        return ReconciliationReport::coveringCapped($this->name(), $plan, $entries);
    }

    private function applyRootQuota(NextcloudTeamFolderClient $folders, int $folderId, FilePlan $plan, string $path): NodeReconciliation
    {
        $wanted = $plan->node($path)?->plafond;
        if ($wanted === null) {
            return NodeReconciliation::conforme($path, 'aucun plafond à poser.');
        }

        $set = $folders->setQuota($folderId, $wanted);
        if ($set->isFailure()) {
            return NodeReconciliation::echec($path, 'plafond non posé : ' . $set->message);
        }

        // COMPARER SUR LE RELU : c'est la seule preuve que la valeur a pris.
        $listed = $folders->listFolders();
        if ($listed->isFailure()) {
            return NodeReconciliation::echec(
                $path,
                'plafond envoyé, mais la relecture a échoué : rien ne prouve qu\'il a pris. ' . $listed->message,
            );
        }

        foreach ((array) $listed->value('folders', []) as $folder) {
            if (! is_array($folder) || (int) ($folder['id'] ?? 0) !== $folderId) {
                continue;
            }

            return (int) ($folder['quota'] ?? -1) === $wanted
                ? NodeReconciliation::applique($path, 'plafond posé et relu.')
                : NodeReconciliation::echec(
                    $path,
                    'le plafond relu diffère de celui demandé : l\'instance ne l\'a pas retenu.',
                );
        }

        return NodeReconciliation::echec($path, 'le dossier d\'équipe a disparu de l\'inventaire entre la pose et la relecture.');
    }

    // =========================================================================
    // location
    // =========================================================================

    /**
     * Story 60.5 — OÙ ce plan vit, POUR AFFICHAGE.
     *
     * Une phrase, pas une adresse à réutiliser : elle dit l'instance et le nom du
     * dossier d'équipe, c'est-à-dire ce qu'il faut savoir pour aller vérifier à la
     * main. `null` si la configuration ne permet pas de le dire.
     */
    public function location(FilePlan $plan): ?string
    {
        try {
            $config = NextcloudConnectionConfig::current();
        } catch (NextcloudConfigurationException) {
            return null;
        }

        return sprintf('%s — dossier d\'équipe « %s »', $config->baseUrl, $plan->rootPath);
    }

    // =========================================================================
    // Effondrement et utilitaires
    // =========================================================================

    private function remotePath(FilePlan $plan, string $nodePath): string
    {
        return $nodePath === PlanNode::ROOT_PATH
            ? $plan->rootPath
            : $plan->rootPath . '/' . $nodePath;
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
}
