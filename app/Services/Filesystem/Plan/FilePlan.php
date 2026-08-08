<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Plan;

use App\Enums\PlanAnchor;
use App\Exceptions\Filesystem\PlanResolutionException;

/**
 * Story 60.1 — PLAN de fichiers résolu : ce que la recette dit, une fois appliquée
 * à un groupe et à ses appartenances.
 *
 * **Neutre.** Le plan ne contient ni mode POSIX, ni ligne d'ACL, ni nom de groupe
 * Unix, ni chemin absolu. Il dit QUOI (une ZONE logique, des chemins relatifs, des
 * sujets par identité interne, un accès `ro|rw`, des plafonds, une clôture) ; il ne
 * dit jamais COMMENT. C'est la ligne de coupe de l'epic, et elle passe AVANT la
 * dérivation des ACL. Deux gardes la tiennent : un test d'architecture scanne les
 * imports de ce namespace, un test de garde scanne la sérialisation. Le premier
 * est un scan textuel — il attrape l'étourderie (un `use` de commodité), pas une
 * dépendance construite à l'exécution pour la contourner ; la limite est énoncée
 * là où il vit, plutôt que promise plus large ici.
 *
 * **Comparable.** Deux résolutions du même état produisent la MÊME sérialisation,
 * octet pour octet : nœuds triés par chemin résolu, octrois triés par (type de
 * sujet, identité, rôle d'arête, accès), clôtures et rôles triés. Sans ce
 * déterminisme, la détection d'écart par comparaison (story 60.4) serait
 * mort-née.
 *
 * **`roles`** porte, pour chaque rôle de la recette, ses SUJETS résolus. Sans
 * cette table, la clôture d'un nœud (« ce rôle n'a rien reçu ici ») serait
 * illisible pour un backend qui doit la matérialiser : il connaîtrait le nom du
 * rôle sans savoir sur qui refermer. Elle est dérivée du contexte de résolution,
 * jamais d'une saisie.
 */
final class FilePlan
{
    /**
     * Version du FORMAT de plan. Sérialisée : un plan relu par une version
     * ultérieure doit pouvoir se reconnaître avant de se comparer.
     *
     * **Elle ne bouge PAS pour l'ancre de la story 60.5**, et c'est un choix. La
     * clé `anchor` est ADDITIVE et son absence a un sens EXACT — la zone par
     * défaut, celle de tous les plans écrits jusqu'ici. Un plan sérialisé avant
     * 60.5 se relit donc sans perte et signifie exactement ce qu'il signifiait.
     * Bumper la version aurait rendu illisibles des rapports en cache qui sont
     * parfaitement valides, pour ne rien protéger.
     *
     * **Story 62.4 — elle passe à 2, et c'est une RUPTURE ASSUMÉE.** Les octrois
     * ne portent plus un niveau d'accès scalaire (`access`) mais une LISTE DE
     * VERBES (`verbs`). Contrairement à l'ancre de 60.5, l'absence de la nouvelle
     * clé n'a aucun sens exact : un plan de version 1 décrit des accès dont la
     * traduction en verbes est une DÉCISION (Q3), pas une lecture. La faire à la
     * désérialisation la disséminerait dans le temps ; elle est jouée une fois, à
     * la migration des recettes stockées. Un plan de version 1 est donc refusé, et
     * la voie de sortie est de le re-résoudre depuis la source SQL — ce qui ne
     * coûte rien : aucun plan n'est persisté, seuls des RAPPORTS le sont (et un
     * rapport ne porte pas de vocabulaire d'accès, vérifié story 62.4).
     */
    public const VERSION = 2;

    /** Clé stable de la recette d'origine. */
    public readonly string $templateKey;

    /**
     * Story 60.5 — ZONE logique du plan : un jeton NEUTRE d'un vocabulaire fermé,
     * jamais un chemin. Seule la garde de chemin du backend sait le traduire.
     */
    public readonly PlanAnchor $anchor;

    /** Racine RELATIVE résolue, ex. `Classe_3emeA`. */
    public readonly string $rootPath;

    /** @var array<string, list<PlanSubject>> rôle de recette => sujets résolus (clés triées) */
    public readonly array $roles;

    /** @var list<PlanNode> triés par chemin résolu */
    public readonly array $nodes;

    /**
     * @param  array<string, list<PlanSubject>>  $roles
     * @param  list<PlanNode>  $nodes
     */
    public function __construct(
        string $templateKey,
        string $rootPath,
        array $roles = [],
        array $nodes = [],
        PlanAnchor $anchor = PlanAnchor::Reseau,
    ) {
        if (! GroupNameNormalizer::isSafeRelativePath($rootPath)) {
            throw PlanResolutionException::make(sprintf(
                'racine de plan non sûre « %s » (un plan ne porte que des chemins relatifs).',
                $rootPath,
            ));
        }

        ksort($roles, SORT_STRING);
        foreach ($roles as $key => $subjects) {
            usort($subjects, static fn (PlanSubject $a, PlanSubject $b): int => strcmp($a->sortKey(), $b->sortKey()));
            $roles[$key] = array_values($subjects);
        }

        usort($nodes, self::nodeOrder(...));

        $seen = [];
        foreach ($nodes as $node) {
            if (isset($seen[$node->path])) {
                throw PlanResolutionException::make(sprintf(
                    'deux nœuds résolvent au même chemin « %s » — un plan ne peut pas décrire deux fois le même dossier.',
                    $node->path,
                ));
            }
            $seen[$node->path] = true;
        }

        $this->templateKey = $templateKey;
        $this->anchor = $anchor;
        $this->rootPath = $rootPath;
        $this->roles = $roles;
        $this->nodes = array_values($nodes);
    }

    /**
     * Story 60.3 — ORDRE CANONIQUE des nœuds : la racine d'abord, le reste par
     * chemin.
     *
     * Le tri par chemin seul mettrait presque toujours la racine en tête (« . »
     * précède les lettres et les chiffres), mais PAS toujours : un nœud dont le
     * nom commence par un tiret la précéderait, le tiret étant un premier
     * caractère de segment légitime. « Presque toujours » n'est pas une propriété.
     * On l'écrit donc, plutôt que d'espérer que l'encodage la donne.
     */
    private static function nodeOrder(PlanNode $a, PlanNode $b): int
    {
        if ($a->path === $b->path) {
            return 0;
        }
        if ($a->path === PlanNode::ROOT_PATH) {
            return -1;
        }
        if ($b->path === PlanNode::ROOT_PATH) {
            return 1;
        }

        return strcmp($a->path, $b->path);
    }

    public function node(string $path): ?PlanNode
    {
        foreach ($this->nodes as $node) {
            if ($node->path === $path) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Story 60.3 — chemins de TOUS les nœuds, dans l'ordre canonique.
     *
     * C'est le PÉRIMÈTRE contre lequel un rapport de backend se valide : couvrir
     * exactement ces chemins, ni plus ni moins.
     *
     * @return list<string>
     */
    public function nodePaths(): array
    {
        return array_map(static fn (PlanNode $n): string => $n->path, $this->nodes);
    }

    /**
     * Story 60.3 — chemins des nœuds PORTANT UN PLAFOND, dans l'ordre canonique.
     *
     * Périmètre de la réponse au plafond : un plan sans plafond donne une liste
     * vide, et un rapport vide y est parfaitement VALIDE — il n'y avait rien à
     * plafonner, ce n'est ni un échec ni un oubli.
     *
     * @return list<string>
     */
    public function cappedNodePaths(): array
    {
        return array_values(array_map(
            static fn (PlanNode $n): string => $n->path,
            array_filter($this->nodes, static fn (PlanNode $n): bool => $n->plafond !== null),
        ));
    }

    /** @return list<string> */
    public function roleKeys(): array
    {
        return array_keys($this->roles);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $roles = [];
        foreach ($this->roles as $key => $subjects) {
            $roles[$key] = array_map(static fn (PlanSubject $s): array => $s->toArray(), $subjects);
        }

        return [
            'version' => self::VERSION,
            'template' => $this->templateKey,
            'anchor' => $this->anchor->value,
            'root' => $this->rootPath,
            'roles' => $roles,
            'nodes' => array_map(static fn (PlanNode $n): array => $n->toArray(), $this->nodes),
        ];
    }

    /**
     * Sérialisation CANONIQUE. C'est cette chaîne qui se compare octet pour octet
     * d'une résolution à l'autre.
     */
    public function toJson(): string
    {
        return (string) json_encode(
            $this->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $version = (int) ($data['version'] ?? 0);
        if ($version !== self::VERSION) {
            throw PlanResolutionException::make(sprintf(
                'version de plan inconnue « %d » (attendue : %d).',
                $version,
                self::VERSION,
            ));
        }

        $roles = [];
        foreach ((array) ($data['roles'] ?? []) as $key => $subjects) {
            $roles[(string) $key] = array_map(
                static fn (array $s): PlanSubject => PlanSubject::fromArray($s),
                array_values((array) $subjects),
            );
        }

        // L'ancre est ADDITIVE : absente, elle vaut la zone par défaut (le sens
        // exact de tous les plans écrits avant la story 60.5). PRÉSENTE mais hors
        // vocabulaire, elle est REFUSÉE — se rabattre sur le défaut ferait
        // silencieusement relire un plan dans la mauvaise zone, c'est-à-dire au
        // mauvais endroit du disque.
        $anchorRaw = $data['anchor'] ?? null;
        if ($anchorRaw === null) {
            $anchor = PlanAnchor::default();
        } else {
            $anchor = PlanAnchor::isKnown($anchorRaw) ? PlanAnchor::from((string) $anchorRaw) : null;
            if ($anchor === null) {
                throw PlanResolutionException::make(sprintf(
                    'zone logique inconnue « %s » (attendu : %s).',
                    is_scalar($anchorRaw) ? (string) $anchorRaw : gettype($anchorRaw),
                    implode('|', PlanAnchor::values()),
                ));
            }
        }

        return new self(
            (string) ($data['template'] ?? ''),
            (string) ($data['root'] ?? ''),
            $roles,
            array_map(
                static fn (array $n): PlanNode => PlanNode::fromArray($n),
                array_values((array) ($data['nodes'] ?? [])),
            ),
            $anchor,
        );
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw PlanResolutionException::make('plan sérialisé illisible.');
        }

        return self::fromArray($decoded);
    }
}
