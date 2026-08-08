<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Nextcloud;

/**
 * Story 61.3 — UNE RÈGLE DE PERMISSION AVANCÉE, telle qu'elle s'écrit et telle
 * qu'elle se relit.
 *
 * Quatre champs, et QUATRE SEULEMENT, parce que ce sont les quatre que l'écriture
 * porte : le type de principal, son identifiant, le masque (quels bits cette règle
 * gouverne) et les permissions (la valeur de ces bits).
 *
 * ---------------------------------------------------------------------------
 * **LE SERVEUR AJOUTE UN CINQUIÈME CHAMP À LA RELECTURE, ET IL NE COMPTE PAS.**
 *
 * Une règle relue revient augmentée d'un libellé d'affichage du principal, que
 * personne n'a écrit et que personne ne contrôle. C'est la TROISIÈME occurrence du
 * même piège dans cet epic — un point de montage qui gagnait une barre oblique, un
 * booléen `false` qui se relisait `true` — et c'est toujours le même remède :
 * **comparer sur les valeurs RELUES, en ignorant les champs que le serveur
 * ajoute**. {@see equals()} porte cette règle, et rien d'autre ne compare deux
 * règles. Sans elle, chaque passage verrait une dérive, réécrirait, et relirait une
 * dérive : un drift permanent avec tous les doubles verts.
 */
final class NextcloudAclRule
{
    public const TYPE_GROUP = 'group';

    public const TYPE_USER = 'user';

    public function __construct(
        public readonly string $mappingType,
        public readonly string $mappingId,
        public readonly int $mask,
        public readonly int $permissions,
    ) {
    }

    /** Une règle qui gouverne un principal de type groupe. */
    public static function forGroup(string $groupId, int $mask, int $permissions): self
    {
        return new self(self::TYPE_GROUP, $groupId, $mask, $permissions);
    }

    /** Une règle qui gouverne un principal de type utilisateur. */
    public static function forUser(string $userId, int $mask, int $permissions): self
    {
        return new self(self::TYPE_USER, $userId, $mask, $permissions);
    }

    /**
     * Clé d'identité d'une règle : un principal ne porte qu'une règle par chemin.
     * Le masque et les permissions sont la VALEUR, pas l'identité.
     */
    public function principalKey(): string
    {
        return $this->mappingType . ':' . $this->mappingId;
    }

    /**
     * Égalité SUR LES QUATRE CHAMPS ÉCRITS. Le libellé ajouté par le serveur n'est
     * pas un champ de cet objet : il ne peut donc pas entrer dans la comparaison,
     * même par distraction.
     */
    public function equals(self $other): bool
    {
        return $this->mappingType === $other->mappingType
            && $this->mappingId === $other->mappingId
            && $this->mask === $other->mask
            && $this->permissions === $other->permissions;
    }

    /** Cette règle referme-t-elle tout accès du principal sur ce chemin ? */
    public function closesEverything(): bool
    {
        return $this->permissions === 0;
    }

    /**
     * Le fragment XML d'une règle, tel que l'écriture l'attend.
     *
     * Les valeurs sont échappées : un identifiant de groupe est dérivé et contraint
     * en amont, mais un XML construit par concaténation sans échappement est une
     * dette qu'on paie une seule fois, très cher.
     */
    public function toXmlFragment(): string
    {
        return sprintf(
            '<nc:acl><nc:acl-mapping-type>%s</nc:acl-mapping-type>'
            . '<nc:acl-mapping-id>%s</nc:acl-mapping-id>'
            . '<nc:acl-mask>%d</nc:acl-mask>'
            . '<nc:acl-permissions>%d</nc:acl-permissions></nc:acl>',
            htmlspecialchars($this->mappingType, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->mappingId, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
            $this->mask,
            $this->permissions,
        );
    }

    /**
     * Le corps complet d'une écriture de liste de règles.
     *
     * Une liste VIDE est licite et signifie « aucune règle sur ce chemin » : c'est
     * ainsi que la révocation retire les règles, en une écriture, sans avoir à les
     * énumérer pour les supprimer une à une.
     *
     * @param  list<self>  $rules
     */
    public static function propertyUpdateBody(array $rules): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<d:propertyupdate xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">'
            . '<d:set><d:prop><nc:acl-list>'
            . implode('', array_map(static fn (self $r): string => $r->toXmlFragment(), $rules))
            . '</nc:acl-list></d:prop></d:set></d:propertyupdate>';
    }

    /** Forme journalisable — aucun secret n'entre ici par construction. */
    public function toArray(): array
    {
        return [
            'mapping_type' => $this->mappingType,
            'mapping_id' => $this->mappingId,
            'mask' => $this->mask,
            'permissions' => $this->permissions,
        ];
    }
}
