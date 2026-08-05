<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Posix;

use App\Enums\PlanAnchor;
use App\Exceptions\Filesystem\PlanResolutionException;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\GroupNameNormalizer;
use App\Services\Filesystem\Plan\PlanNode;

/**
 * Story 60.4 → 60.5 — la GARDE DE CHEMIN du serveur de fichiers historique,
 * descendue sous la ligne de contrat.
 *
 * **Pourquoi elle vit ici.** Un chemin absolu est un savoir de backend : le plan
 * ne porte que des chemins RELATIFS et une ZONE logique. Tant que la garde vivait
 * dans l'orchestrateur, celui-ci connaissait la racine réelle — et la coupe passait
 * donc APRÈS la dérivation des chemins concrets, c'est-à-dire au mauvais endroit.
 * Le code est repris à l'identique de la garde 34.1
 * (`NetworkShareService::validateSharePath`, elle-même calquée 1:1 sur
 * `AclService::validatePath`) : **triple garde conservée** — motif anti-traversal
 * ici, `escapeshellarg` chez l'exécutant, liste blanche `sudo` côté système.
 *
 * **La borne de profondeur suit le PLAN.** Un plan d'arbre porte des nœuds à deux
 * niveaux sous sa racine (`_travail/devoirs`), ce qui donne trois segments sous la
 * racine gérée : l'ancienne constante les refusait. La borne suit donc la
 * profondeur du plan ({@see MAX_PLAN_DEPTH}), et elle reste une borne — pas une
 * porte ouverte : au delà, le chemin est refusé comme avant.
 *
 * ---------------------------------------------------------------------------
 * **STORY 60.5 — DEUX ANCRES, UNE SEULE TABLE, ET AUCUN CHEMIN EN DEHORS.**
 *
 * SE5 gouverne désormais deux zones disjointes : les répertoires réseau nommés et
 * les arbres de classe de la chaîne générique. La garde connaît une table FERMÉE
 * {@see ROOT_CONFIG_KEYS} qui traduit le jeton de zone porté par le plan en racine
 * réelle — et RIEN d'autre ne fabrique de racine. C'est ce qui rend vraie, par
 * construction et non par précaution, la promesse centrale de la story :
 *
 *   **aucune combinaison d'entrées ne fait produire ici un chemin sous l'arbre de
 *   classe HISTORIQUE.** Cette racine-là n'a pas de jeton ; elle n'est donc pas
 *   atteignable, quelles que soient la racine de plan, le chemin de nœud ou
 *   l'estampille d'archivage fournis.
 *
 * Chaque garde vaut pour CHAQUE ancre : anti-traversée, jeu de caractères clos,
 * chemin hors racine, profondeur bornée, racine de plan mono-segment. L'archivage
 * suit la même règle — la corbeille d'un plan est celle de SA zone, jamais celle
 * d'une autre.
 */
final class PosixPathGuard
{
    /**
     * Racine canonique des répertoires réseau gérés. Surchargeable en tests, iso
     * la propriété statique historique ; la clé de configuration la surcharge si
     * elle est définie.
     */
    public static string $sharesRoot = '/var/sambaedu/Partages';

    /**
     * Racine canonique des arbres de classe de la chaîne générique (story 60.5).
     *
     * Voisine de l'arbre historique et strictement DISTINCTE de lui. Le repli
     * statique recopie le défaut de la configuration, pour la même raison qu'au
     * dessus : une garde qui ne saurait pas répondre sans fichier de configuration
     * refuserait tout sur une instance dont la configuration a été amputée.
     */
    public static string $classTreesRoot = '/var/sambaedu/ClassesSE5';

    /**
     * L'arbre de classes HISTORIQUE — cité ici pour être REFUSÉ, jamais servi.
     *
     * Il n'est pas une zone de cette garde et ne le sera pas : c'est le seul
     * emplacement que la chaîne générique n'a pas le droit d'écrire. Le nommer en
     * dur est délibéré — le lire depuis le service historique ferait dépendre la
     * garde de ce qu'elle protège contre.
     */
    private const LEGACY_CLASS_TREE_ROOT = '/var/sambaedu/Classes';

    /**
     * TABLE FERMÉE des ancres logiques : jeton de zone → clé de configuration.
     *
     * C'est le seul endroit du dépôt où une zone devient un chemin. Une ancre hors
     * de cette table n'existe pas — l'énumération la refuse déjà à la construction
     * du plan, et la garde la refuserait une seconde fois.
     *
     * @var array<string, string>
     */
    private const ROOT_CONFIG_KEYS = [
        PlanAnchor::Reseau->value => 'filesystem.shares_root',
        PlanAnchor::Classes->value => 'filesystem.class_trees_root',
    ];

    /**
     * Profondeur maximale d'un chemin de NŒUD sous la racine du plan.
     *
     * Trois niveaux : de quoi accueillir `_travail/devoirs` (deux) et un niveau
     * de réserve, sans jamais autoriser un parcours d'arbre non maîtrisé.
     */
    public const MAX_PLAN_DEPTH = 3;

    /**
     * Profondeur maximale sous la racine d'une zone : un segment pour la racine du
     * plan, plus la profondeur du plan lui-même.
     */
    private const MAX_DEPTH = 1 + self::MAX_PLAN_DEPTH;

    /** Nom du répertoire d'archivage hors de l'espace exposé. */
    public const TRASH_SEGMENT = '.trash';

    /**
     * Racine réelle d'une ZONE. Unique fabrique de racine de toute la classe.
     */
    public function rootFor(PlanAnchor $anchor): string
    {
        $key = self::ROOT_CONFIG_KEYS[$anchor->value];

        $fallback = match ($anchor) {
            PlanAnchor::Reseau => static::$sharesRoot,
            PlanAnchor::Classes => static::$classTreesRoot,
        };

        $root = rtrim((string) config($key, $fallback), '/');

        $this->assertRootIsUsable($anchor, $root);

        return $root;
    }

    /**
     * LA PROMESSE « PAR CONSTRUCTION » NE TIENT QU'AUTANT QUE LES ZONES SONT
     * DISJOINTES — et l'une d'elles est réglable par variable d'environnement.
     *
     * Toute la sûreté de cette séparation repose sur le fait que la zone des
     * arbres de classe ne désigne ni l'arbre historique ni la zone des
     * répertoires réseau. Cette propriété est vérifiée par les tests sur la
     * valeur LIVRÉE — ce qui ne dit rien de la valeur qu'une instance porte
     * réellement. Un copier-coller malheureux dans le fichier d'environnement
     * suffirait à faire écrire SE5 dans l'arbre historique, en silence, alors que
     * la story entière repose sur l'idée qu'il n'existe aucun chemin pour cela.
     *
     * On refuse donc de servir une racine qui coïncide, avec un message qui dit
     * quoi corriger. Bruyant vaut mieux que faux.
     *
     * @throws \App\Exceptions\Filesystem\PlanResolutionException
     */
    private function assertRootIsUsable(PlanAnchor $anchor, string $root): void
    {
        if ($root === '') {
            throw PlanResolutionException::make(sprintf(
                'la zone « %s » n\'a pas d\'emplacement configuré (clé « %s »).',
                $anchor->value,
                self::ROOT_CONFIG_KEYS[$anchor->value],
            ));
        }

        if ($anchor !== PlanAnchor::Classes) {
            return;
        }

        $legacy = rtrim(self::LEGACY_CLASS_TREE_ROOT, '/');
        $network = rtrim((string) config(self::ROOT_CONFIG_KEYS[PlanAnchor::Reseau->value], static::$sharesRoot), '/');

        foreach ([
            $legacy => 'l\'arbre de classes historique, que SE5 ne doit jamais écrire',
            $network => 'la zone des répertoires réseau, exposée en partage',
        ] as $forbidden => $why) {
            if ($root === $forbidden || str_starts_with($root . '/', $forbidden . '/')) {
                throw PlanResolutionException::make(sprintf(
                    'la zone des arbres de classe est configurée sur « %s », qui est ou contient %s. '
                    . 'Corrigez la clé « %s » (ou la variable d\'environnement correspondante) : '
                    . 'les deux zones doivent être disjointes.',
                    $root,
                    $why,
                    self::ROOT_CONFIG_KEYS[PlanAnchor::Classes->value],
                ));
            }
        }
    }

    /**
     * Racine des répertoires réseau gérés — conservée pour les appelants
     * historiques, et strictement équivalente à la zone par défaut.
     */
    public function sharesRoot(): string
    {
        return $this->rootFor(PlanAnchor::Reseau);
    }

    /**
     * Garde durcie, appliquée DANS UNE ZONE : chemin absolu, sous la racine de
     * cette zone, jeu de caractères clos, aucun segment `.` ou `..`, profondeur
     * bornée. Aucun `realpath()` — le chemin peut ne pas exister au moment de la
     * création.
     *
     * La zone est un paramètre OBLIGATOIRE : un chemin n'est pas valide « dans
     * l'absolu », il est valide dans une zone. Un chemin parfaitement formé de la
     * zone des classes reste refusé quand on interroge la zone du réseau — et
     * c'est exactement la propriété qui empêche une zone d'écrire chez une autre.
     */
    public function isValidPath(PlanAnchor $anchor, string $path): bool
    {
        $root = $this->rootFor($anchor);

        if ($path === '' || $path[0] !== '/') {
            return false;
        }
        if (! str_starts_with($path, $root . '/') && $path !== $root) {
            return false;
        }
        if (! preg_match('#^/[A-Za-z0-9_./-]+$#', $path)) {
            return false;
        }

        $segments = $path === $root
            ? []
            : explode('/', trim(substr($path, strlen($root) + 1), '/'));
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '..' || $seg === '.') {
                return false;
            }
        }

        return count($segments) <= self::MAX_DEPTH;
    }

    /** Chemin absolu de la RACINE d'un plan, ou `null` si refusé par la garde. */
    public function planRoot(FilePlan $plan): ?string
    {
        if (! GroupNameNormalizer::isSafeSegment($plan->rootPath)) {
            return null;
        }
        $path = $this->rootFor($plan->anchor) . '/' . $plan->rootPath;

        return $this->isValidPath($plan->anchor, $path) ? $path : null;
    }

    /**
     * Chemin absolu d'un NŒUD du plan, ou `null` si refusé.
     *
     * Le jeton racine (`.`) désigne la racine du plan elle-même : il ne se
     * concatène pas, il s'efface. C'est exactement pourquoi il est un jeton et
     * non un segment.
     */
    public function resolve(FilePlan $plan, string $nodePath): ?string
    {
        $root = $this->planRoot($plan);
        if ($root === null) {
            return null;
        }
        if ($nodePath === PlanNode::ROOT_PATH) {
            return $root;
        }
        if (! GroupNameNormalizer::isSafeRelativePath($nodePath)) {
            return null;
        }
        if (count(explode('/', $nodePath)) > self::MAX_PLAN_DEPTH) {
            return null;
        }

        $path = $root . '/' . $nodePath;

        return $this->isValidPath($plan->anchor, $path) ? $path : null;
    }

    /** Chemin absolu du répertoire d'archivage D'UNE ZONE. */
    public function trashRoot(PlanAnchor $anchor): string
    {
        return $this->rootFor($anchor) . '/' . self::TRASH_SEGMENT;
    }

    /**
     * Cible d'archivage d'un plan déprovisionné, ou `null` si refusée.
     *
     * **Chaque zone a sa corbeille.** Archiver un arbre de classe dans la
     * corbeille des répertoires réseau le ferait réapparaître dans un espace
     * exposé en SMB — sortir une arborescence de l'espace exposé en la déposant
     * dans un autre serait le contraire de ce que la révocation existe pour faire.
     *
     * **Le suffixe est une DATE, et pas l'identifiant de la ligne SQL.** La
     * séquence historique suffixait par l'identifiant du partage ; cet
     * identifiant n'appartient pas au plan, et l'y faire entrer casserait la
     * portabilité que tout l'epic construit. La date d'archivage rend la cible
     * unique, lisible pour l'exploitant, et n'imbrique pas deux archivages
     * successifs l'un dans l'autre — ce que le suffixe stable, lui, faisait.
     */
    public function trashTarget(FilePlan $plan, string $stamp): ?string
    {
        if (! GroupNameNormalizer::isSafeSegment($plan->rootPath)) {
            return null;
        }
        if (preg_match('/^[0-9]{8}-[0-9]{6}$/', $stamp) !== 1) {
            return null;
        }

        $target = $this->trashRoot($plan->anchor) . '/' . $plan->rootPath . '-' . $stamp;

        return $this->isValidPath($plan->anchor, $target) ? $target : null;
    }
}
