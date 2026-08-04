<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Plan;

use App\Exceptions\Filesystem\PlanResolutionException;

/**
 * Story 60.1 — PLAN de fichiers résolu : ce que la recette dit, une fois appliquée
 * à un groupe et à ses appartenances.
 *
 * **Neutre.** Le plan ne contient ni mode POSIX, ni ligne d'ACL, ni nom de groupe
 * Unix, ni chemin absolu. Il dit QUOI (des chemins relatifs, des sujets par
 * identité interne, un accès `ro|rw`, des plafonds, une clôture) ; il ne dit
 * jamais COMMENT. C'est la ligne de coupe de l'epic, et elle passe AVANT la
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
     */
    public const VERSION = 1;

    /** Clé stable de la recette d'origine. */
    public readonly string $templateKey;

    /** Racine RELATIVE résolue, ex. `Classes/Classe_3emeA`. */
    public readonly string $rootPath;

    /** @var array<string, list<PlanSubject>> rôle de recette => sujets résolus (clés triées) */
    public readonly array $roles;

    /** @var list<PlanNode> triés par chemin résolu */
    public readonly array $nodes;

    /**
     * @param  array<string, list<PlanSubject>>  $roles
     * @param  list<PlanNode>  $nodes
     */
    public function __construct(string $templateKey, string $rootPath, array $roles = [], array $nodes = [])
    {
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

        usort($nodes, static fn (PlanNode $a, PlanNode $b): int => strcmp($a->path, $b->path));

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
        $this->rootPath = $rootPath;
        $this->roles = $roles;
        $this->nodes = array_values($nodes);
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

        return new self(
            (string) ($data['template'] ?? ''),
            (string) ($data['root'] ?? ''),
            $roles,
            array_map(
                static fn (array $n): PlanNode => PlanNode::fromArray($n),
                array_values((array) ($data['nodes'] ?? [])),
            ),
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
