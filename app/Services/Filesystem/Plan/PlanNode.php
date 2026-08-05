<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Plan;

use App\Enums\PlanNodeNature;
use App\Exceptions\Filesystem\PlanResolutionException;

/**
 * Story 60.1 — NŒUD d'un plan de fichiers résolu.
 *
 * Le chemin est RELATIF à la racine du plan (`_travail`, `_travail/devoirs`,
 * `dupontj`). Aucun chemin absolu n'entre jamais dans un plan : la racine réelle
 * est un savoir de backend, et un plan qui la porterait ne serait pas portable.
 *
 * **La clôture, et pourquoi elle existe.** Un nœud porte, EN PLUS de ses octrois,
 * la liste des rôles de la recette qui n'ont AUCUN octroi ici. Cette liste est
 * entièrement DÉRIVÉE (rôles de la recette moins rôles octroyés sur ce nœud) :
 * elle n'est ni saisie dans la recette, ni exposée à son auteur, ni modifiable
 * autrement qu'en écrivant ou en retirant un octroi.
 *
 * En POSIX, l'implicite suffit : pas d'entrée, pas d'accès — et c'est exactement
 * ainsi que le dossier privé des enseignants se dit aujourd'hui. Mais l'implicite
 * est FAUX sur d'autres plans de fichiers. Le sondage mené en ouverture d'epic
 * l'a mesuré contre une instance réelle : un partage posé sur un ANCÊTRE propage
 * à tout le sous-arbre, l'instruction de retrait est acceptée en `200 OK` SANS
 * EFFET, et la relecture d'état rend ensuite un accès en lecture là où on
 * demandait zéro. Un plan qui ne dit que ce qui est ACCORDÉ fabrique donc, sur un
 * backend à propagation, une fuite de confidentialité SILENCIEUSE sur le dossier
 * privé des enseignants — que même la vérification ne rattrape pas.
 *
 * La clôture rend ce silence explicite sans rien ajouter à ce que l'auteur écrit.
 * **Ce n'est pas une interdiction** : elle ne dit pas « interdire à X », elle
 * constate « X n'a rien reçu ici ». Elle est une conséquence des octrois, jamais
 * une saisie concurrente, et n'ouvre aucun degré de liberté nouveau. Aucun backend
 * ne l'exécute dans cette story : POSIX l'ignorera (il n'écrit rien), un backend à
 * propagation la matérialisera. Ici, on se contente de la PORTER.
 */
final class PlanNode
{
    /**
     * Story 60.3 — chemin du nœud RACINE : la racine du plan, vue comme un nœud.
     *
     * **Pourquoi la racine devient un nœud de première classe.** Le sondage
     * d'ouverture d'epic a mesuré qu'une relecture d'état « avec les sous-chemins »
     * rend les enfants mais PAS la racine. Sans nœud racine, chaque backend doit
     * réinventer ce cas, et l'un d'eux l'omettra — l'omission est le mode de
     * rupture MESURÉ, pas une hypothèse. Un partage plat, lui, EST sa racine : le
     * projecteur de partage n'aurait littéralement aucun nœud à produire sans elle.
     *
     * Alias de {@see GroupNameNormalizer::ROOT_NODE_PATH} — une seule valeur, un
     * seul endroit où elle vit.
     *
     * **Story 60.5 — le vocabulaire de recette l'accepte désormais aussi.** Il ne
     * l'acceptait pas en 60.3 : ouvrir les deux d'un coup aurait mêlé une
     * contrainte de contrat (mesurée) à un choix de langage (non tranché). Le choix
     * est fait — sans nœud racine, la racine d'un arbre n'a aucun octroi exprimable,
     * et le partage de classe historique n'est tout simplement pas dicible. Hors de
     * la position racine, « . » reste interdit partout : le motif de segment le
     * refuse, et c'est ce qui garde le jeton d'être un segment.
     */
    public const ROOT_PATH = GroupNameNormalizer::ROOT_NODE_PATH;

    /**
     * Chemin RELATIF à la racine du plan, segments déjà substitués et validés, ou
     * {@see ROOT_PATH} pour la racine elle-même.
     */
    public readonly string $path;

    public readonly string $label;

    public readonly PlanNodeNature $nature;

    /** @var list<PlanGrant> triés par clé de tri stable */
    public readonly array $grants;

    /**
     * État du nœud. `false` = nœud ACTIVABLE désactivé : le nœud EXISTE toujours,
     * ses octrois suspendables sont suspendus, les données restent. Jamais une
     * variation structurelle du plan, jamais une suppression.
     */
    public readonly bool $active;

    /** Plafond en octets, ou `null`. Porté dès maintenant, exécuté plus tard. */
    public readonly ?int $plafond;

    /** @var list<string> rôles de la recette sans octroi ici, triés */
    public readonly array $closure;

    /**
     * @param  list<PlanGrant>  $grants
     * @param  list<string>  $closure
     */
    public function __construct(
        string $path,
        string $label,
        PlanNodeNature $nature,
        array $grants = [],
        bool $active = true,
        ?int $plafond = null,
        array $closure = [],
    ) {
        if (! GroupNameNormalizer::isSafeNodePath($path)) {
            throw PlanResolutionException::make(sprintf(
                'chemin de nœud non sûr « %s » (le jeton racine « %s », ou un chemin relatif dont chaque '
                . 'segment est alphanumérique + « . _ - » et ne commence pas par « . »).',
                $path,
                self::ROOT_PATH,
            ));
        }
        if ($plafond !== null && $plafond <= 0) {
            throw PlanResolutionException::make('un plafond doit être un nombre d\'octets strictement positif (ou absent).');
        }
        if (! $active && $nature !== PlanNodeNature::Activable) {
            throw PlanResolutionException::make(sprintf(
                'seul un nœud de nature « %s » peut être inactif (nœud « %s »).',
                PlanNodeNature::Activable->value,
                $path,
            ));
        }
        // Un octroi suspendable sur une nature qui n'a rien à suspendre est une
        // contradiction. La recette la refuse déjà à l'écriture ; on la refuse
        // AUSSI ici, parce qu'un plan peut arriver par désérialisation (60.3/60.4
        // reliront des plans persistés) sans repasser par la validation de recette.
        // Un invariant qui ne tient qu'à une seule frontière ne tient pas.
        if (! $nature->acceptsSuspendableGrants()) {
            foreach ($grants as $grant) {
                if ($grant->suspendable) {
                    throw PlanResolutionException::make(sprintf(
                        'un octroi suspendable ne peut pas vivre sur un nœud de nature « %s » (nœud « %s ») : rien ne pourrait le suspendre.',
                        $nature->value,
                        $path,
                    ));
                }
            }
        }

        usort($grants, static fn (PlanGrant $a, PlanGrant $b): int => strcmp($a->sortKey(), $b->sortKey()));

        $closure = array_values(array_unique(array_map(strval(...), $closure)));
        sort($closure, SORT_STRING);

        $this->path = $path;
        $this->label = $label;
        $this->nature = $nature;
        $this->grants = array_values($grants);
        $this->active = $active;
        $this->plafond = $plafond;
        $this->closure = $closure;
    }

    /**
     * `false` uniquement pour un nœud à contenu libre : les enfants de ce nœud ne
     * sont pas gouvernés par le plan, donc leur présence n'est pas un écart.
     */
    public function governsChildren(): bool
    {
        return $this->nature->governsChildren();
    }

    /** @return list<PlanGrant> */
    public function activeGrants(): array
    {
        return array_values(array_filter($this->grants, static fn (PlanGrant $g): bool => $g->isActive()));
    }

    /** @return list<PlanGrant> */
    public function suspendedGrants(): array
    {
        return array_values(array_filter($this->grants, static fn (PlanGrant $g): bool => ! $g->isActive()));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'label' => $this->label,
            'nature' => $this->nature->value,
            'active' => $this->active,
            'governs_children' => $this->governsChildren(),
            'plafond' => $this->plafond,
            'grants' => array_map(static fn (PlanGrant $g): array => $g->toArray(), $this->grants),
            'closure' => $this->closure,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $nature = PlanNodeNature::tryFrom((string) ($data['nature'] ?? ''));
        if ($nature === null) {
            throw PlanResolutionException::make(sprintf(
                'nature de nœud inconnue « %s » (attendu : %s).',
                (string) ($data['nature'] ?? ''),
                implode('|', PlanNodeNature::values()),
            ));
        }

        $plafond = $data['plafond'] ?? null;

        return new self(
            (string) ($data['path'] ?? ''),
            (string) ($data['label'] ?? ''),
            $nature,
            array_map(
                static fn (array $g): PlanGrant => PlanGrant::fromArray($g),
                array_values((array) ($data['grants'] ?? [])),
            ),
            (bool) ($data['active'] ?? true),
            $plafond === null ? null : (int) $plafond,
            array_values((array) ($data['closure'] ?? [])),
        );
    }
}
