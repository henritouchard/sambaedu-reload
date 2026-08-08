<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Posix;

use App\Enums\FileBackendName;
use App\Enums\FileBackendOutcome;
use App\Services\Filesystem\Acl\AclFormat;
use App\Services\Filesystem\Backend\FileBackend;
use App\Services\Filesystem\Backend\InspectionReport;
use App\Services\Filesystem\Backend\NodeObservation;
use App\Services\Filesystem\Backend\NodeReconciliation;
use App\Services\Filesystem\Backend\ObservedGrant;
use App\Services\Filesystem\Backend\ReconciliationReport;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Story 60.4 — LE PREMIER BACKEND RÉEL : le serveur de fichiers historique,
 * derrière la ligne de contrat.
 *
 * Rien ici n'est neuf. Le jeu canonique d'entrées, la séquence de pose, la
 * séquence de révocation data-safe, la lecture de l'état effectif viennent tous
 * du provisionnement 34.1 et n'ont pas été réécrits : la valeur de cette story
 * est dans la RETENUE. Ce qui change, c'est qui les appelle et ce qu'ils
 * rendent — un état PAR NŒUD, en vocabulaire de plan, au lieu d'un booléen.
 *
 * ---------------------------------------------------------------------------
 * **LA CONVENTION DE PRÉCÉDENCE, TENUE ICI POUR LA PREMIÈRE FOIS.**
 *
 * Un nœud, une entrée de rapport, plusieurs gestes (créer, purger, poser N
 * entrées, donner propriétaire et groupe). Le contrat ÉNONCE l'ordre
 * d'effondrement sans l'imposer ; ce backend le CHOISIT et le dit :
 *
 *     `echec` > `non_exprimable` > `non_implemente` > `applique` > `conforme`
 *
 * Concrètement : un changement de propriétaire qui échoue rend le nœud en échec
 * même si toutes ses entrées sont posées — un succès partiel ne masque jamais un
 * échec ; un octroi que SE5 ne sait pas projeter rend le nœud en dette même si le
 * dossier vient d'être créé — la dette prime sur « j'ai changé quelque chose ».
 * `en_attente` n'est jamais produit ici : ce backend est synchrone — c'est
 * l'orchestrateur qui, au-dessus, dit « engagé, pas achevé » quand il enfile la
 * réconciliation.
 *
 * **`non_exprimable` EST produit ici depuis la story 62.4.** La phrase inverse a
 * figuré à cette place tant que les octrois étaient binaires ; elle est devenue
 * FAUSSE le jour où ils sont devenus combinables, et une garantie périmée dans un
 * docblock est pire qu'une garantie absente. Deux cas, tous deux des limites de
 * MODÈLE — permanentes, propriété du système de fichiers, jamais une dette de
 * notre code :
 *  - un octroi qui demande de SUPPRIMER sans pouvoir CRÉER : les deux verbes
 *    passent par le même levier, et l'accorder donnerait la création, un verbe que
 *    la recette n'écrit pas. Le verbe n'est pas rendu, le reste l'est, le nœud le
 *    dit ;
 *  - un octroi « déposer sans effacer » posé sur un nœud où un AUTRE octroi actif
 *    porte la suppression : la restriction qui approcherait la nuance retirerait à
 *    celui-là l'effacement du travail des autres. On ne la pose pas, et là encore
 *    le nœud le dit.
 *
 * Aucun des deux ne se confond avec `non_implemente`, qui reste réservé à ce que
 * le système SAIT faire et que SE5 ne pilote pas : le plafond de zone, et les rôles
 * d'arête que la projection de sujets ne sait pas encore rendre. Un test aligne les
 * deux CÔTE À CÔTE, précisément parce que les écraser l'un sur l'autre est la
 * simplification la plus tentante du dépôt.
 *
 * ---------------------------------------------------------------------------
 * **L'IDEMPOTENCE EST DEVENUE VRAIE, ET C'EST LE SEUL CHANGEMENT DE COMPORTEMENT.**
 *
 * La séquence historique purgeait puis reposait TOUJOURS, y compris sur un
 * répertoire déjà conforme. Le contrat exige qu'un second passage sur un backend
 * déjà conforme rende `conforme` SANS ÉCRITURE. On lit donc l'état effectif AVANT
 * d'écrire, et on n'écrit que s'il diffère. L'état final est identique — c'est ce
 * qui rend vraie la promesse « aucune entrée ne bouge sur une instance en
 * place » — et le passage à vide n'émet plus rien.
 *
 * **La limite de lecture est celle d'hier, volontairement.** On lit l'état du
 * répertoire de TÊTE, pas une descente récursive. C'est la limite assumée de
 * l'audit de dérive depuis l'Epic 34, elle suffit à détecter une dérive de
 * contrat, et l'élargir en passant aurait fait de cette story une story de
 * performance sans mesure. Corollaire honnête : une dérive introduite en
 * profondeur (sur un seul sous-dossier) n'est pas vue, et le nœud est déclaré
 * conforme. La reconvergence forcée reste disponible.
 *
 * **Ce que « conforme » n'a PAS regardé** : le propriétaire et le groupe. Ils ne
 * sont (re)posés que quand on écrit. Les lire coûterait un geste de plus à chaque
 * passage pour un écart que rien n'a jamais produit en exploitation ; le jour où
 * ce serait le cas, c'est une lecture à ajouter ici, pas une garantie à corriger
 * ailleurs.
 */
final class PosixFileBackend implements FileBackend
{
    /**
     * Verrou de passage, repris du provisionnement 34.1 — et pour la même raison :
     * la mémoire cache par défaut ne verrouille pas entre processus, il faut le
     * magasin fichier. Il protège désormais AUSSI deux traitements enfilés qui se
     * croiseraient.
     *
     * La clé est indexée sur la RACINE DU PLAN, pas sur un identifiant de ligne :
     * le plan ne porte pas d'identifiant, et la racine est unique par répertoire
     * géré — la protection est au moins aussi forte.
     */
    private const LOCK_PREFIX = 'network-shares:provision:';

    private const LOCK_SECONDS = 60;

    /**
     * Attente maximale de la RÉVOCATION avant de renoncer. Dimensionnée sur la
     * pose : un passage ordinaire tient dans la seconde, un gros passage dans
     * quelques secondes ({@see \App\Services\Filesystem\Backend\Posix\PosixAclCompiler}
     * refuse au-delà de 200 entrées nominatives, mesurées à 0,32 s).
     */
    private const REVOKE_WAIT_SECONDS = 10;

    /**
     * Sujets d'entrée STRUCTURELS : ils appartiennent au contrat de base d'un
     * répertoire géré, pas à son audience. Même frontière que l'inspection
     * d'import — deux frontières qui divergeraient feraient de l'une un octroi
     * observé et de l'autre pas.
     */
    private const STRUCTURAL_NAMED = ['domain\040admins', 'domain admins'];

    /**
     * Story 62.5 — la forme CANONIQUE d'un couloir d'accès dérivé, telle qu'elle se
     * relit.
     *
     * Elle est ici, et surtout PAS dans la table de reprojection {@see verbsOf()} :
     * un couloir n'est pas un verbe, aucune observation ne sait le dire
     * ({@see \App\Services\Filesystem\Backend\ObservedGrant} valide contre le
     * vocabulaire fermé du plan), et l'ajouter à la table percerait la ligne que la
     * story 62.4 a fermée à dessein. Un couloir se FILTRE, il ne se traduit pas.
     */
    private const TRAVERSAL_MODE = '--x';

    public function __construct(
        private readonly PosixPathGuard $guard,
        private readonly PosixAclCompiler $compiler,
        private readonly PosixSubjectProjector $projector,
        private readonly PosixExecutor $executor,
        private readonly PosixTraversalPlanner $traversalPlanner = new PosixTraversalPlanner(),
    ) {
    }

    public function name(): FileBackendName
    {
        return FileBackendName::Posix;
    }

    // =========================================================================
    // provision
    // =========================================================================

    public function provision(FilePlan $plan): ReconciliationReport
    {
        $lock = Cache::store('file')->lock(self::LOCK_PREFIX . $plan->rootPath, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return $this->everyNode($plan, static fn (string $path): NodeReconciliation => NodeReconciliation::echec(
                $path,
                'une autre réconciliation est en cours sur ce répertoire : ce passage n\'a rien écrit.',
            ));
        }

        try {
            $entries = [];
            foreach ($plan->nodes as $node) {
                $entries[] = $this->provisionNode($plan, $node);
            }

            return ReconciliationReport::covering($this->name(), $plan, $entries);
        } finally {
            $lock->release();
        }
    }

    private function provisionNode(FilePlan $plan, PlanNode $node): NodeReconciliation
    {
        $path = $this->guard->resolve($plan, $node->path);
        if ($path === null) {
            return NodeReconciliation::echec(
                $node->path,
                'le chemin de ce nœud est refusé par la garde du serveur de fichiers : rien n\'a été écrit.',
            );
        }

        // Story 62.5 — les COULOIRS d'accès dérivés de ce nœud, calculés par le
        // planificateur et par lui seul. La relecture appelle le MÊME calcul : deux
        // dérivations qui divergeraient donneraient soit une repose à chaque
        // passage, soit une dérive que personne ne verrait.
        $compiled = $this->compiler->compile($node, $this->traversalPlanner->forNode($plan, $node));

        // Garde-fou d'échelle : le nœud entier est refusé, aucun geste n'est tenté.
        if ($compiled->isBlocked()) {
            return NodeReconciliation::echec($node->path, implode(' ', $compiled->refusalDetails()));
        }

        /** @var list<FileBackendOutcome> $outcomes */
        $outcomes = $compiled->refusalOutcomes();
        $details = $compiled->refusalDetails();

        $created = false;
        if (! $this->executor->directoryExists($path)) {
            $made = $this->executor->makeDirectory($path);
            if (! $made->ok) {
                $outcomes[] = FileBackendOutcome::Echec;
                $details[] = 'création du répertoire impossible : ' . $this->trim($made->error);

                return $this->collapse($node->path, $outcomes, $details);
            }
            $created = true;
        }

        // L'état effectif de TÊTE, lu UNE fois : il sert à la fois à décider de la
        // conformité et à savoir s'il reste une restriction de suppression à
        // retirer. Le relire deux fois donnerait deux vérités possibles.
        $effective = $created ? null : $this->readState($path);

        if ($effective !== null && $this->matches($effective, $compiled)) {
            $outcomes[] = FileBackendOutcome::Conforme;

            return $this->collapse($node->path, $outcomes, $details);
        }

        $wiped = $this->executor->wipeAcls($path);
        if (! $wiped->ok) {
            $outcomes[] = FileBackendOutcome::Echec;
            $details[] = 'purge des droits étendus impossible : ' . $this->trim($wiped->error);

            return $this->collapse($node->path, $outcomes, $details);
        }

        $failed = 0;
        $firstError = '';
        $record = function (PosixCommandOutcome $applied) use (&$failed, &$firstError): void {
            if (! $applied->ok) {
                $failed++;
                if ($firstError === '') {
                    $firstError = $this->trim($applied->error);
                }
            }
        };

        if ($compiled->isDifferentiated()) {
            // Story 62.4 — dossiers et fichiers n'attendent pas la même chose : on
            // pose en DEUX passages ciblés. Un passage unique aurait forcément
            // accordé un verbe de trop d'un côté ou de l'autre.
            foreach ($compiled->acls as $acl) {
                $record($this->executor->applyAclToDirectories($path, $acl));
            }
            foreach ($compiled->fileAcls as $acl) {
                $record($this->executor->applyAclToFiles($path, $acl));
            }
        } else {
            foreach ($compiled->acls as $acl) {
                $record($this->executor->applyAcl($path, $acl));
            }
        }

        // Story 62.5 — LES COULOIRS, POSÉS EN DERNIER ET SUR LA TÊTE SEULE.
        //
        // En dernier parce que la purge et les poses de nœud viennent d'écrire tout
        // le reste ; sur la tête seule parce qu'un couloir est un attribut de CE
        // répertoire — le diffuser plus bas donnerait la traversée à du contenu que
        // le plan ne gouverne même pas.
        foreach ($compiled->traversalAcls as $acl) {
            $record($this->executor->applyAclToHead($path, $acl));
        }

        // La restriction de suppression est un mode du DOSSIER : elle se pose (ou
        // se retire) à part, et seulement quand l'état voulu diffère du constaté.
        if ($compiled->restrictsDeletion) {
            $record($this->executor->restrictDeletionToOwner($path));
        } elseif ($effective !== null && $effective['restricted']) {
            $record($this->executor->releaseDeletionRestriction($path));
        }

        if ($failed > 0) {
            $outcomes[] = FileBackendOutcome::Echec;
            $details[] = sprintf('%d droit(s) n\'ont pas pu être posés : %s', $failed, $firstError);
        }

        $owner = $this->executor->changeOwner($path);
        $group = $this->executor->changeGroup($path);
        if (! $owner->ok || ! $group->ok) {
            $outcomes[] = FileBackendOutcome::Echec;
            $details[] = 'propriétaire ou groupe propriétaire non appliqué : '
                . $this->trim($owner->ok ? $group->error : $owner->error);
        }

        $outcomes[] = FileBackendOutcome::Applique;

        return $this->collapse($node->path, $outcomes, $details);
    }

    /**
     * L'état effectif du répertoire de TÊTE, ou `null` si on n'a pas pu le lire.
     *
     * Une lecture en échec vaut « je ne sais pas », et « je ne sais pas » n'est
     * jamais « conforme » : on préfère réécrire un état déjà bon que de déclarer
     * conforme ce qu'on n'a pas pu lire.
     *
     * @return array{acls: list<string>, restricted: bool}|null
     */
    private function readState(string $path): ?array
    {
        $read = $this->executor->readAcl($path);
        if (! $read->ok) {
            return null;
        }

        return [
            'acls' => AclFormat::normalizeSet(preg_split('/\R/', $read->output) ?: []),
            'restricted' => self::readsAsRestricted($read->output),
        ];
    }

    /**
     * L'état lu est-il déjà celui que le plan décrit ?
     *
     * La comparaison des entrées est SÉMANTIQUE : la forme d'entrée abrégée et la
     * forme de sortie canonique sont ramenées l'une à l'autre avant d'être
     * comparées, sans quoi un état parfaitement conforme se lirait comme une dérive
     * à chaque passage.
     *
     * **La restriction de suppression entre dans la comparaison, et il le fallait.**
     * Sans elle, un nœud qui la demande se serait relu « conforme » avant qu'elle
     * soit posée — ou, l'ayant posée, se serait vu la reposer à chaque passage :
     * dans les deux cas l'idempotence promise par le contrat aurait été fausse.
     *
     * **Story 62.5 — les COULOIRS aussi, et pour exactement la même raison.** Ils
     * sont dans l'état de tête relu ; les omettre de l'ensemble comparé aurait fait
     * relire « dérivé » un nœud parfaitement conforme, donc reposer à chaque
     * passage. Et la réciproque tient toute seule : un couloir devenu caduc — l'octroi
     * profond a disparu — n'est plus attendu, l'ensemble diffère, et la purge qui
     * ouvre la repose l'emporte.
     *
     * @param  array{acls: list<string>, restricted: bool}  $effective
     */
    private function matches(array $effective, CompiledNodeAcl $compiled): bool
    {
        return $effective['acls'] === AclFormat::normalizeSet($compiled->headAcls())
            && $effective['restricted'] === $compiled->restrictsDeletion;
    }

    /**
     * La restriction de suppression est-elle posée, d'après l'EN-TÊTE de la
     * relecture ?
     *
     * C'est le seul endroit où l'outil la dit — et c'est exactement pour cela que
     * l'option qui supprimait l'en-tête a été retirée de la commande de lecture.
     * L'en-tête n'est émis que si un drapeau est effectivement posé : son absence
     * est donc une réponse, pas une ignorance.
     */
    private static function readsAsRestricted(string $raw): bool
    {
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if (! str_starts_with($line, '# flags:')) {
                continue;
            }

            return str_contains(substr($line, strlen('# flags:')), 't');
        }

        return false;
    }

    // =========================================================================
    // deprovision
    // =========================================================================

    /**
     * Révoque les droits et sort la structure de l'espace exposé — SANS DÉTRUIRE
     * DE DONNÉES.
     *
     * C'est l'obligation que le contrat décrivait sans que personne ne la tienne :
     * elle est tenue ici. La séquence est celle de l'Epic 34, à l'identique :
     *  1. purge des droits étendus (retire tous les octrois) ;
     *  2. resserrage du mode de base (sinon la purge laisse « les autres » entrer
     *     par la permission de base) ;
     *  3. DÉPLACEMENT vers une poubelle hors de l'espace exporté — jamais une
     *     suppression. Un dossier « supprimé » côté base mais laissé sur le disque
     *     avec ses droits reste atteignable par tous ceux qui y avaient accès :
     *     la suppression de la ligne ne suffit pas.
     *
     * **Un seul geste pour tout l'arbre, et un état par nœud quand même.** Les
     * trois commandes sont récursives et le déplacement emporte la racine avec
     * tout son contenu : les nœuds descendants quittent l'espace exposé par le
     * même geste. Chacun le DIT, plutôt que d'être omis du rapport — un rapport
     * amputé se lit « tout va bien » sur le nœud dont personne n'a parlé.
     *
     * Idempotent : une racine déjà absente rend `conforme` partout.
     */
    public function deprovision(FilePlan $plan): ReconciliationReport
    {
        $lock = Cache::store('file')->lock(self::LOCK_PREFIX . $plan->rootPath, self::LOCK_SECONDS);

        // La révocation ATTEND son tour, là où la mise en place renonce.
        //
        // L'asymétrie est délibérée. Renoncer à une mise en place est sans
        // conséquence : l'écart reste, un passage ultérieur le résorbera. Renoncer
        // à une révocation, en revanche, laisse un répertoire pleinement accordé
        // pendant que son appelant s'apprête à en supprimer la ligne — plus aucun
        // écran, plus aucun contrôle automatique ne le verrait ensuite. Depuis que
        // la mise en place est enfilée, la fenêtre où un passage est « en cours »
        // ne se limite plus à la durée d'une requête : elle mérite qu'on l'attende.
        try {
            $lock->block(self::REVOKE_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            return $this->everyNode($plan, static fn (string $path): NodeReconciliation => NodeReconciliation::echec(
                $path,
                'une autre réconciliation est en cours sur ce répertoire et ne s\'est pas achevée à temps : '
                . 'ce passage n\'a rien révoqué.',
            ));
        }

        try {
            $root = $this->guard->planRoot($plan);
            if ($root === null) {
                return $this->everyNode($plan, static fn (string $p): NodeReconciliation => NodeReconciliation::echec(
                    $p,
                    'la racine de ce plan est refusée par la garde du serveur de fichiers : rien n\'a été révoqué.',
                ));
            }

            if (! $this->executor->directoryExists($root)) {
                return $this->everyNode($plan, static fn (string $p): NodeReconciliation => NodeReconciliation::conforme(
                    $p,
                    'déjà hors de l\'espace exposé : rien à révoquer.',
                ));
            }

            $failure = $this->revoke($plan, $root);
            if ($failure !== null) {
                return $this->everyNode($plan, static fn (string $p): NodeReconciliation => NodeReconciliation::echec($p, $failure));
            }

            return $this->everyNode($plan, static fn (string $p): NodeReconciliation => NodeReconciliation::applique(
                $p,
                $p === PlanNode::ROOT_PATH
                    ? 'droits révoqués, contenu archivé hors de l\'espace exposé (aucune donnée détruite).'
                    : 'sorti de l\'espace exposé avec la racine du plan (aucune donnée détruite).',
            ));
        } finally {
            $lock->release();
        }
    }

    /** `null` si la séquence a réussi, sinon la cause. */
    private function revoke(FilePlan $plan, string $root): ?string
    {
        $wiped = $this->executor->wipeAcls($root);
        if (! $wiped->ok) {
            return 'purge des droits étendus impossible : ' . $this->trim($wiped->error);
        }

        $restricted = $this->executor->restrictMode($root);
        if (! $restricted->ok) {
            return 'resserrage du mode de base impossible : ' . $this->trim($restricted->error);
        }

        $target = $this->guard->trashTarget($plan, now()->format('Ymd-His'));
        if ($target === null) {
            return 'la cible d\'archivage est refusée par la garde du serveur de fichiers.';
        }

        // La corbeille est celle de la ZONE du plan (story 60.5) : un arbre de
        // classe ne s'archive jamais dans l'espace exposé des répertoires réseau.
        $trash = $this->executor->makeTrashRoot($this->guard->trashRoot($plan->anchor));
        if (! $trash->ok) {
            return 'création de l\'espace d\'archivage impossible : ' . $this->trim($trash->error);
        }

        $moved = $this->executor->move($root, $target);
        if (! $moved->ok) {
            return 'archivage impossible : ' . $this->trim($moved->error);
        }

        return null;
    }

    // =========================================================================
    // inspect
    // =========================================================================

    /**
     * RELIT l'état, un nœud après l'autre, racine comprise, et le REPROJETTE en
     * vocabulaire de plan.
     *
     * **Aucun nom système ne remonte.** Un nom d'ouverture de session relu devient
     * l'identité interne du compte ; un nom de groupe système devient l'identité
     * interne du groupe, retrouvée par projection EN AVANT (on projette les
     * candidats et on indexe), jamais par découpage du nom relu.
     *
     * **Ce qui ne se reprojette pas n'est ni inventé ni tu.** Un compte supprimé,
     * un groupe étranger : l'entrée est COMPTÉE dans le détail de l'observation,
     * en nombre et sans nom système, et la comparaison la traitera comme un écart.
     *
     * **Une entrée VIDE est une observation, pas une absence.** Une entrée présente
     * sans aucun droit se relit en LISTE DE VERBES VIDE — c'est la forme
     * matérialisée d'un octroi suspendu, et la confondre avec l'absence d'entrée
     * referait au niveau du vocabulaire l'erreur que le modèle a démontée. Le plan,
     * lui, ne dit jamais « aucun » : un octroi y porte toujours au moins un verbe.
     * L'asymétrie est voulue, et c'est elle qui rend la suspension observable.
     *
     * **Le plafond n'est pas regardé** (`plafondObserve = false` partout) : SE5 ne
     * pilote pas les plafonds de zone, la story qui le ferait est suspendue. Dette
     * datée et visible, pas limite de modèle.
     */
    public function inspect(FilePlan $plan): InspectionReport
    {
        $index = $this->projector->reverseIndex($plan);

        $observations = [];
        foreach ($plan->nodes as $node) {
            $observations[] = $this->inspectNode($plan, $node, $index);
        }

        return InspectionReport::covering($this->name(), $plan, $observations);
    }

    /**
     * @param  array{groups: array<string, PlanSubject>, logins: array<string, PlanSubject>}  $index
     */
    private function inspectNode(FilePlan $plan, PlanNode $node, array $index): NodeObservation
    {
        $path = $this->guard->resolve($plan, $node->path);
        if ($path === null) {
            return NodeObservation::echec(
                $node->path,
                'le chemin de ce nœud est refusé par la garde du serveur de fichiers : rien n\'a été relu.',
            );
        }

        if (! $this->executor->directoryExists($path)) {
            return NodeObservation::absent($node->path);
        }

        $read = $this->executor->readAcl($path);
        if (! $read->ok) {
            return NodeObservation::echec(
                $node->path,
                'relecture impossible : ' . $this->trim($read->error),
            );
        }

        $grants = [];
        $unmapped = 0;
        $restricted = self::readsAsRestricted($read->output);

        // Story 62.5 — les couloirs ATTENDUS ici, par le MÊME planificateur que la
        // pose. Ils ne sont PAS des octrois observés : le plan n'attend rien de ces
        // sujets sur ce nœud, et les compter en ferait des « entrées en trop » à
        // chaque comparaison — un bruit de dérive perpétuel sur chaque instance.
        /** @var array<string, PosixTraversal> $expectedTraversals */
        $expectedTraversals = [];
        foreach ($this->traversalPlanner->forNode($plan, $node) as $traversal) {
            $expectedTraversals[$traversal->key()] = $traversal;
        }
        $seenTraversals = [];

        foreach (AclFormat::parseEntries($read->output) as $entry) {
            // Les miroirs d'héritage ne sont pas des octrois : ils décrivent ce
            // que le contenu à venir héritera, pas ce que quelqu'un a aujourd'hui.
            if ($entry['default']) {
                continue;
            }

            $qualifier = $entry['qualifier'];
            $type = $entry['type'];

            // Entrées de base et masque : le contrat structurel du répertoire.
            if ($qualifier === null || $type === 'other' || $type === 'mask') {
                continue;
            }
            if ($type === 'group' && in_array(strtolower($qualifier), self::STRUCTURAL_NAMED, true)) {
                continue;
            }

            // Une entrée de traversée ATTENDUE est STRUCTURELLE au même titre que le
            // jeu de base : elle appartient au contrat du répertoire, pas à son
            // audience. Le filtre porte sur le SUJET ATTENDU **et** sur la forme
            // exacte du couloir — une traversée étrangère, ou un couloir attendu
            // écrit autrement, reste un écart. Sans cette précision, « tout ce qui
            // ressemble à un couloir » deviendrait absous.
            if (AclFormat::normalizeMode($entry['mode']) === self::TRAVERSAL_MODE) {
                $subject = $this->subjectOf($type, $qualifier, $index);
                $key = $subject instanceof PlanSubject ? $subject->sortKey() : null;

                if ($key !== null && isset($expectedTraversals[$key])) {
                    $seenTraversals[$key] = true;

                    continue;
                }

                $unmapped++;

                continue;
            }

            $verbs = $this->verbsOf($entry['mode'], $restricted);
            if ($verbs === null) {
                $unmapped++;

                continue;
            }

            $subject = $this->subjectOf($type, $qualifier, $index);

            if (! $subject instanceof PlanSubject) {
                $unmapped++;

                continue;
            }

            $grants[] = new ObservedGrant($subject, $verbs);
        }

        $missing = array_values(array_filter(
            $expectedTraversals,
            static fn (PosixTraversal $t): bool => ! isset($seenTraversals[$t->key()]),
        ));

        $details = [];
        if ($unmapped > 0) {
            $details[] = sprintf(
                '%d entrée(s) relue(s) ne correspondent à aucune identité connue de SE5 : elles sont '
                . 'comptées comme écart, pas ignorées.',
                $unmapped,
            );
        }
        if ($missing !== []) {
            $details[] = self::missingTraversalDetail($missing);
        }

        return NodeObservation::observed(
            $node->path,
            $grants,
            null,
            false,
            $details === [] ? null : implode(' ', $details),
        );
    }

    /**
     * Le sujet de plan d'une entrée relue, ou `null` si rien de connu ne lui
     * correspond.
     *
     * Extrait de la boucle de relecture par la story 62.5 : elle a désormais DEUX
     * endroits qui doivent traduire un qualifier (l'octroi observé, le couloir
     * attendu), et deux traductions qui divergeraient feraient qu'une même entrée
     * serait reconnue d'un côté et comptée en écart de l'autre.
     *
     * @param  array{groups: array<string, PlanSubject>, logins: array<string, PlanSubject>}  $index
     */
    private function subjectOf(string $type, string $qualifier, array $index): ?PlanSubject
    {
        return $type === 'user'
            ? $this->projector->subjectForLogin(AclFormat::unescape($qualifier), $index['logins'])
            : ($index['groups'][strtolower(AclFormat::unescape($qualifier))] ?? null);
    }

    /**
     * Story 62.5 — la phrase d'un COULOIR ATTENDU QUI MANQUE, en vocabulaire de
     * plan.
     *
     * Elle ne dit ni mode, ni bit, ni commande : elle dit qu'un passage manque, vers
     * combien de dossiers plus profonds, et pour quels rôles. C'est ce qui suffit à
     * l'administrateur — et c'est ce `detail` non vide qui fait classer le nœud en
     * ÉCART par le comparateur d'état, sans qu'une seule de ses lignes ait eu besoin
     * de changer.
     *
     * @param  list<PosixTraversal>  $missing
     */
    private static function missingTraversalDetail(array $missing): string
    {
        $roles = [];
        $paths = [];
        foreach ($missing as $traversal) {
            foreach ($traversal->roleKeys as $role) {
                $roles[$role] = true;
            }
            foreach ($traversal->nodePaths as $path) {
                $paths[$path] = true;
            }
        }
        $roleNames = array_keys($roles);
        sort($roleNames, SORT_STRING);

        return sprintf(
            'le couloir d\'accès dérivé vers %d dossier(s) plus profond(s) n\'est pas en place pour %d '
            . 'rôle(s) (%s) : sans lui, ce qui leur est accordé plus bas reste hors d\'atteinte.',
            count($paths),
            count($missing),
            implode(', ', array_map(static fn (string $r): string => '« ' . $r . ' »', $roleNames)),
        );
    }

    /**
     * Story 62.4 — LES VERBES qu'un mode relu représente, ou `null` si ce mode ne
     * se réduit à aucune combinaison honnête.
     *
     * **La table est FERMÉE, et courte, parce que la lecture est celle du
     * répertoire de TÊTE.** Le mode d'un dossier dit ce qu'on peut y faire ; il ne
     * dit rien de ce qu'on peut faire au CONTENU des fichiers qu'il contient. Une
     * relecture qui prétendrait distinguer « lire » de « lire + éditer » depuis le
     * seul dossier inventerait la moitié de sa réponse.
     *
     *  | mode relu | restriction | verbes rendus                      |
     *  |-----------|-------------|------------------------------------|
     *  | vide      | —           | AUCUN (suspension matérialisée)    |
     *  | lecture   | —           | lire                               |
     *  | complet   | non         | les quatre                         |
     *  | complet   | oui         | lire, éditer, créer                |
     *  | autre     | —           | `null` — compté en ÉCART           |
     *
     * **Ce que cette table ne sait pas faire, et qu'elle ne fait donc pas
     * semblant de faire.** Les combinaisons où fichiers et dossiers reçoivent des
     * niveaux différents (« lire + éditer », « lire + créer + supprimer »…) se
     * relisent ici de façon APPROCHÉE, et la comparaison les rapporte donc en
     * ÉCART — jamais en conforme. C'est le comportement voulu : un écart de trop
     * se voit et se discute, une conformité de trop est une fuite silencieuse. La
     * relecture fine du contenu demanderait une descente récursive que ce backend
     * ne fait pas, par la même décision qu'en 60.4.
     *
     * **Story 62.5 — la table N'A PAS BOUGÉ, et c'est un choix.** Les couloirs
     * d'accès dérivés produisent une forme d'entrée que cette table rend `null`,
     * donc « écart ». La tentation était d'y ajouter une ligne ; elle est refusée :
     * un couloir n'exprime AUCUN verbe, et le déclarer comme tel ferait remonter en
     * observation quelque chose que le plan n'a jamais écrit. Les couloirs ATTENDUS
     * sont donc écartés en amont, comme les entrées structurelles ; ceux qui ne sont
     * pas attendus tombent ici, en écart — exactement comme avant.
     *
     * **Sur une instance en place, aucun bruit** : les recettes migrées ne portent
     * que « lire » seul et les quatre verbes, deux lignes de la table qui se
     * relisent EXACTEMENT. Les combinaisons approchées n'entrent qu'avec l'écran de
     * composition (62.6), qui saura griser ce qu'un backend ne sait pas rendre.
     *
     * **Review 62.4 #3 — la reprojection peut SUR-DÉCLARER « éditer ».** Un dossier
     * en `rwx` avec restriction se relit `{lire, editer, creer}`, que l'octroi ait
     * demandé `editer` ou non : le mode d'un dossier ne dit rien du droit d'écrire
     * dans les fichiers qu'il contient, et cette relecture porte sur le répertoire
     * de TÊTE. Un octroi `{lire, creer}` sera donc rapporté en écart avec « éditer
     * observé en trop » — écart réel (le désir n'est pas rendu tel quel), mais dont
     * le DÉTAIL nomme un droit qui n'existe sur aucun fichier. Conséquence pour
     * 62.6, qui misera sur l'écran de dérive : ne pas présenter ce détail comme la
     * preuve qu'un droit a été accordé. Aucune recette d'aujourd'hui n'atteint ce
     * cas — les deux combinaisons produites par la migration Q3 ferment la boucle
     * exactement.
     *
     * @return list<string>|null
     */
    private function verbsOf(string $mode, bool $restricted): ?array
    {
        return match (AclFormat::normalizeMode($mode)) {
            '---' => [],
            'r-x' => [PlanGrant::VERB_LIRE],
            'rwx' => $restricted
                ? [PlanGrant::VERB_LIRE, PlanGrant::VERB_EDITER, PlanGrant::VERB_CREER]
                : PlanGrant::VERBS,
            default => null,
        };
    }

    // =========================================================================
    // quota
    // =========================================================================

    /**
     * DÉCLINE, honnêtement, et n'ouvre aucune infrastructure.
     *
     * Le système de fichiers SAIT plafonner une arborescence — le mécanisme de
     * quota de projet existe, il a été monté et vérifié en ouverture d'epic. S'il
     * ne plafonne rien, c'est que SE5 ne le pilote pas : la story qui le
     * brancherait est SUSPENDUE. C'est donc une dette de notre code, temporaire, et
     * l'affichage doit la GRISER — pas une limite du modèle, qui serait permanente
     * et se masquerait. Écrire « non supporté » ici mettrait une contre-vérité
     * dans le code.
     *
     * Un plan sans plafond donne un rapport VIDE et parfaitement valide.
     */
    public function quota(FilePlan $plan): ReconciliationReport
    {
        return ReconciliationReport::coveringCapped(
            $this->name(),
            $plan,
            array_map(
                static fn (string $path): NodeReconciliation => NodeReconciliation::nonImplemente(
                    $path,
                    'le mécanisme de plafond de zone existe côté système de fichiers ; SE5 ne le pilote pas — '
                    . 'la story qui le brancherait est suspendue.',
                ),
                $plan->cappedNodePaths(),
            ),
        );
    }

    // =========================================================================
    // Effondrement et utilitaires
    // =========================================================================

    /**
     * Effondre les états des gestes d'un nœud en UN état, selon la convention de
     * précédence énoncée au docblock de classe.
     *
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

        $detail = implode(' ', array_filter($details, static fn (string $d): bool => trim($d) !== ''));

        if ($winner->requiresDetail() && trim($detail) === '') {
            $detail = 'cause non détaillée par le serveur de fichiers.';
        }

        return new NodeReconciliation($path, $winner, $detail === '' ? null : $detail);
    }

    /**
     * @param  callable(string): NodeReconciliation  $factory
     */
    private function everyNode(FilePlan $plan, callable $factory): ReconciliationReport
    {
        return ReconciliationReport::covering(
            $this->name(),
            $plan,
            array_map($factory, $plan->nodePaths()),
        );
    }

    /**
     * Sortie système NEUTRALISÉE avant d'entrer dans un rapport — la phrase est
     * gardée, le vocabulaire du backend est jeté ({@see PosixDiagnostic}).
     */
    private function trim(string $error): string
    {
        return PosixDiagnostic::neutralize($error);
    }

    /**
     * Story 60.5 — l'emplacement RÉEL de la racine du plan, ou `null` si la garde
     * la refuse. C'est là que l'exploitant ira lire les droits à la main, et c'est
     * ce chemin que la liste blanche du système doit couvrir.
     */
    public function location(FilePlan $plan): ?string
    {
        return $this->guard->planRoot($plan);
    }
}
