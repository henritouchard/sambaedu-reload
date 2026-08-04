<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Plan;

use App\Exceptions\Filesystem\PlanResolutionException;

/**
 * Story 60.1 — SUJET d'un octroi de plan, désigné par son IDENTITÉ INTERNE.
 *
 * **La règle qui tient tout le reste** : un sujet est `(type, id)` où `id` est la
 * clé primaire SE5 (`users.id` ou `user_groups.id`). Jamais un login, jamais un
 * nom de groupe d'annuaire, jamais un nom de groupe Unix, jamais le `sub` d'un
 * jeton OIDC (documenté comme n'étant PAS une clé de jointure). Un login peut
 * apparaître dans un plan, mais uniquement comme SEGMENT DE CHEMIN d'un nœud par
 * membre — c'est un schéma de nommage hérité, pas un sujet d'octroi.
 *
 * **Le rôle d'arête, et pourquoi il vit ICI.** Un sujet de type groupe peut porter
 * un rôle d'arête (`member|manager|owner`) : le sujet est alors « les membres de
 * ce groupe qui portent ce rôle ». C'est une ABSTRACTION que le backend compilera
 * comme il voudra — la mesure faite en ouverture d'epic a tranché qu'un tel
 * ensemble se compile en groupe dérivé, jamais en énumération nominative de ses
 * membres (une pose d'ACL récursive est quadratique : 7,2 s à 1 000 entrées
 * nominatives, 63 s à 3 000, contre 0,35 s pour un groupe dérivé, et une limite
 * dure au-delà). Le plan dit QUI, le backend décide COMMENT.
 *
 * L'énumération nominative reste légitime pour les nœuds par membre : une entrée
 * par nœud, jamais une audience entière sur un même nœud.
 */
final class PlanSubject
{
    /** Un utilisateur, par `users.id`. */
    public const TYPE_USER = 'user';

    /** Un groupe d'utilisateurs, par `user_groups.id`. */
    public const TYPE_USER_GROUP = 'user_group';

    /** @var list<string> */
    public const TYPES = [self::TYPE_USER, self::TYPE_USER_GROUP];

    public readonly string $type;

    public readonly int $id;

    /** Rôle d'arête, uniquement pour un sujet de type groupe. */
    public readonly ?string $edgeRole;

    public function __construct(string $type, int $id, ?string $edgeRole = null)
    {
        if (! in_array($type, self::TYPES, true)) {
            throw PlanResolutionException::make(sprintf(
                'type de sujet inconnu « %s » (attendu : %s).',
                $type,
                implode('|', self::TYPES),
            ));
        }
        if ($id <= 0) {
            throw PlanResolutionException::make('un sujet d\'octroi doit porter une identité interne positive.');
        }
        if ($edgeRole !== null && $type !== self::TYPE_USER_GROUP) {
            throw PlanResolutionException::make(
                'un rôle d\'arête ne qualifie qu\'un sujet de type groupe (« les membres qui portent ce rôle »).'
            );
        }
        if ($edgeRole !== null && ! GroupNameNormalizer::isKnownEdgeRole($edgeRole)) {
            throw PlanResolutionException::make(sprintf(
                'rôle d\'arête inconnu « %s » (attendu : %s).',
                $edgeRole,
                implode('|', GroupNameNormalizer::EDGE_ROLES),
            ));
        }

        $this->type = $type;
        $this->id = $id;
        $this->edgeRole = $edgeRole;
    }

    public static function user(int $id): self
    {
        return new self(self::TYPE_USER, $id);
    }

    public static function group(int $id, ?string $edgeRole = null): self
    {
        return new self(self::TYPE_USER_GROUP, $id, $edgeRole);
    }

    /**
     * Clé de tri STABLE : (type, id, rôle d'arête).
     *
     * Le séparateur est l'octet nul — un séparateur imprimable ferait passer
     * `user_group` AVANT `user` (le souligné précède la barre verticale), ce qui
     * est correct mais contre-intuitif à la relecture d'un plan. La clé n'est
     * jamais sérialisée : elle ne sert qu'à ordonner.
     */
    public function sortKey(): string
    {
        return sprintf("%s\0%010d\0%s", $this->type, $this->id, $this->edgeRole ?? '');
    }

    /** @return array{type:string,id:int,edge_role:string|null} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'edge_role' => $this->edgeRole,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $edgeRole = $data['edge_role'] ?? null;

        return new self(
            (string) ($data['type'] ?? ''),
            (int) ($data['id'] ?? 0),
            is_string($edgeRole) && $edgeRole !== '' ? $edgeRole : null,
        );
    }
}
