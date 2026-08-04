<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Posix;

use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\GroupNameNormalizer;
use App\Services\Filesystem\Plan\PlanNode;

/**
 * Story 60.4 — la GARDE DE CHEMIN du serveur de fichiers historique, descendue
 * sous la ligne de contrat.
 *
 * **Pourquoi elle descend.** Un chemin absolu est un savoir de backend : le plan
 * ne porte que des chemins RELATIFS et une racine logique. Tant que la garde
 * vivait dans l'orchestrateur, celui-ci connaissait `/var/sambaedu/Partages` — et
 * la coupe passait donc APRÈS la dérivation des chemins concrets, c'est-à-dire au
 * mauvais endroit. Le code est repris à l'identique de la garde 34.1
 * (`NetworkShareService::validateSharePath`, elle-même calquée 1:1 sur
 * `AclService::validatePath`) : **triple garde conservée** — motif anti-traversal
 * ici, `escapeshellarg` chez l'exécutant, liste blanche `sudo` côté système.
 *
 * **La seule chose qui change, et pourquoi.** La profondeur maximale autorisée
 * suivait `<directory_name>` + un niveau de réserve (`MAX_DEPTH = 2`). Un plan
 * d'arbre porte des nœuds à deux niveaux sous sa racine (`_travail/devoirs`), ce
 * qui donne trois segments sous la racine gérée : l'ancienne constante les
 * REFUSERAIT. La borne suit donc désormais la profondeur du PLAN
 * ({@see MAX_PLAN_DEPTH}), et elle reste une borne — pas une porte ouverte : au
 * delà, le chemin est refusé comme avant. L'ajustement vit ici, sous la ligne,
 * parce que c'est ici que la racine réelle est connue.
 *
 * **Ancrage.** Tous les plans de cette story sont ancrés sous la racine des
 * répertoires réseau gérés. L'ancrage des arbres de classe (racine historique
 * distincte) est un savoir de backend à ÉTENDRE en 60.5 — pas à contourner ici.
 */
final class PosixPathGuard
{
    /**
     * Racine canonique des répertoires réseau gérés. Surchargeable en tests, iso
     * la propriété statique historique ; `config('filesystem.shares_root')` la
     * surcharge si définie.
     */
    public static string $sharesRoot = '/var/sambaedu/Partages';

    /**
     * Profondeur maximale d'un chemin de NŒUD sous la racine du plan.
     *
     * Trois niveaux : de quoi accueillir `_travail/devoirs` (deux) et un niveau
     * de réserve, sans jamais autoriser un parcours d'arbre non maîtrisé.
     */
    public const MAX_PLAN_DEPTH = 3;

    /**
     * Profondeur maximale sous {@see sharesRoot()} : un segment pour la racine du
     * plan, plus la profondeur du plan lui-même.
     */
    private const MAX_DEPTH = 1 + self::MAX_PLAN_DEPTH;

    /** Nom du répertoire d'archivage hors de l'espace exposé. */
    public const TRASH_SEGMENT = '.trash';

    public function sharesRoot(): string
    {
        return rtrim((string) config('filesystem.shares_root', static::$sharesRoot), '/');
    }

    /**
     * Garde durcie : chemin absolu, sous la racine, jeu de caractères clos, aucun
     * segment `.` ou `..`, profondeur bornée. Aucun `realpath()` — le chemin peut
     * ne pas exister au moment de la création.
     */
    public function isValidPath(string $path): bool
    {
        $root = $this->sharesRoot();

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
        $path = $this->sharesRoot() . '/' . $plan->rootPath;

        return $this->isValidPath($path) ? $path : null;
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

        return $this->isValidPath($path) ? $path : null;
    }

    /** Chemin absolu du répertoire d'archivage. */
    public function trashRoot(): string
    {
        return $this->sharesRoot() . '/' . self::TRASH_SEGMENT;
    }

    /**
     * Cible d'archivage d'un plan déprovisionné, ou `null` si refusée.
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

        $target = $this->trashRoot() . '/' . $plan->rootPath . '-' . $stamp;

        return $this->isValidPath($target) ? $target : null;
    }
}
