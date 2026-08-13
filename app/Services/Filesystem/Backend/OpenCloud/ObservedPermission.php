<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\OpenCloud;

/**
 * UN OCTROI RELU, RÉDUIT À CE QUI FAIT FOI.
 *
 * ---------------------------------------------------------------------------
 * **L'ÉGALITÉ IGNORE LES CHAMPS QUE LE SERVEUR AJOUTE — et cette classe n'existe
 * que pour ça.**
 *
 * Mesuré le 2026-08-13, la relecture d'un octroi rend, en plus de ce qu'on a
 * écrit : `createdDateTime`, `invitation.invitedBy.user.{id,displayName,
 * @libre.graph.userType}`, et le `displayName` du principal. Comparer l'objet
 * relu à l'objet envoyé produirait donc une différence à CHAQUE passage — une
 * dérive permanente, avec tous les doubles au vert et un écran d'administration
 * qui ne sert plus à rien. Le piège a déjà été rencontré trois fois sur l'autre
 * produit ; ici il est refermé à la source, en ne gardant que deux faits :
 *
 *  - **QUI** — le type de principal et son identifiant (jamais son nom
 *    d'affichage, que le serveur remplit et que l'annuaire peut changer) ;
 *  - **QUOI** — l'identifiant du rôle.
 *
 * L'identifiant de la PERMISSION elle-même est conservé à part : il ne participe
 * pas à l'égalité (il est attribué par le serveur), mais il est indispensable
 * pour modifier ou retirer l'octroi — et c'est la seule façon de le faire,
 * puisqu'un `invite` rejoué rend `409`.
 *
 * **Attention aux séparateurs, mesurés incohérents** : un identifiant d'item
 * emploie `$` et `!`, un identifiant de permission emploie `:`. Les deux
 * traversent cette classe sans jamais être découpés ni recomposés.
 */
final class ObservedPermission
{
    public const TYPE_USER = 'user';

    public const TYPE_GROUP = 'group';

    private function __construct(
        /** Identifiant de la PERMISSION, attribué par le serveur. Hors égalité. */
        public readonly string $permissionId,
        /** {@see TYPE_USER} ou {@see TYPE_GROUP}. */
        public readonly string $principalType,
        /** Identifiant du principal côté instance (un UUID). */
        public readonly string $principalId,
        /** Identifiants de rôles portés par cet octroi, triés. @var list<string> */
        public readonly array $roleIds,
        /**
         * Actions brutes, quand l'octroi a été posé par actions plutôt que par
         * rôle. Un octroi que SE5 n'a pas écrit peut en porter ; les garder
         * permet de le SIGNALER au lieu de le perdre.
         *
         * @var list<string>
         */
        public readonly array $actions,
    ) {
    }

    /**
     * Lit une entrée de `GET …/permissions`. Rend `null` sur une forme que le
     * protocole ne promet pas — jamais un objet à moitié rempli.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): ?self
    {
        $granted = $raw['grantedToV2'] ?? null;
        if (! is_array($granted)) {
            return null;
        }

        $type = null;
        $id = null;
        foreach ([self::TYPE_USER, self::TYPE_GROUP] as $candidate) {
            $entry = $granted[$candidate] ?? null;
            if (is_array($entry) && is_string($entry['id'] ?? null) && $entry['id'] !== '') {
                $type = $candidate;
                $id = (string) $entry['id'];
                break;
            }
        }

        if ($type === null || $id === null) {
            return null;
        }

        $roles = [];
        foreach (is_array($raw['roles'] ?? null) ? $raw['roles'] : [] as $role) {
            if (is_string($role) && $role !== '') {
                $roles[] = $role;
            }
        }
        sort($roles, SORT_STRING);

        $actions = [];
        foreach (is_array($raw['@libre.graph.permissions.actions'] ?? null)
            ? $raw['@libre.graph.permissions.actions']
            : [] as $action) {
            if (is_string($action) && $action !== '') {
                $actions[] = $action;
            }
        }
        sort($actions, SORT_STRING);

        return new self(
            is_string($raw['id'] ?? null) ? (string) $raw['id'] : '',
            $type,
            $id,
            $roles,
            $actions,
        );
    }

    /** Clé de principal, dans la forme employée par la projection du plan. */
    public function principalKey(): string
    {
        return $this->principalType . ':' . $this->principalId;
    }

    /** Cet octroi porte-t-il EXACTEMENT ce rôle, et rien d'autre ? */
    public function carriesExactly(string $roleId): bool
    {
        return $this->roleIds === [$roleId] && $this->actions === [];
    }

    /** L'octroi porte-t-il un rôle que SE5 ne sait pas décrire ? */
    public function isUnmodelled(): bool
    {
        if ($this->actions !== []) {
            // Un octroi posé par ACTIONS n'a jamais été écrit par SE5 : la
            // traduction ne pose que des rôles.
            return true;
        }

        foreach ($this->roleIds as $roleId) {
            if (! OpenCloudRoleTable::isKnown($roleId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les verbes du plan que cet octroi exprime, en ordre canonique.
     *
     * @return list<string>
     */
    public function verbs(): array
    {
        $verbs = [];
        foreach ($this->roleIds as $roleId) {
            foreach (OpenCloudRoleTable::verbsOf($roleId) as $verb) {
                $verbs[$verb] = true;
            }
        }

        return array_values(array_filter(
            \App\Services\Filesystem\Plan\PlanGrant::VERBS,
            static fn (string $v): bool => isset($verbs[$v]),
        ));
    }
}
