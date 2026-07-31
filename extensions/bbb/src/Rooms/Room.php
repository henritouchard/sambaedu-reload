<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Rooms;

use SambaEdu\ExtBbb\Identity;

/**
 * Story 57.2 — **UN SALON, ET LA DÉCISION D'ACCÈS QUI VA AVEC.**
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CET OBJET N'A PAS DE MOT DE PASSE, ET C'EST LE POINT
 *
 *  Les deux mots de passe BigBlueButton vivent dans la table, et ne se lisent
 *  que par {@see \SambaEdu\ExtBbb\Store::roomSecrets()} — appelée au seul
 *  moment où l'on fabrique une URL de jonction signée. L'objet qui traverse
 *  les contrôleurs et les vues, lui, ne les a JAMAIS eus : il ne peut donc pas
 *  les laisser fuir dans une page, un champ caché ou un journal, même par
 *  distraction.
 *
 *  C'est la traduction structurelle du défaut §9.1 de la carte du legacy :
 *  SE4 postait `meetingId`, `attendedPW` **et** `moderatorPW` en champs cachés
 *  dans le HTML servi à tout le monde.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **`token` est l'identifiant PUBLIC**, tiré de `random_bytes` : il ne se
 * devine pas, ne s'énumère pas, et ne dit rien de son propriétaire. Le legacy
 * exposait le login du créateur en clair dans l'URL invité (défaut §9.5).
 */
final class Room
{
    public const VISIBILITY_ETAB = 'etab';

    public const VISIBILITY_CLASSE = 'classe';

    public const VISIBILITY_PRIVATE = 'private';

    /**
     * Vocabulaire FERMÉ, et contraint jusque dans le schéma (`CHECK`).
     *
     * Le `world` du legacy — « tous les établissements » — n'est pas porté :
     * une instance SambaEdu 5 sert un établissement.
     */
    public const VISIBILITIES = [self::VISIBILITY_ETAB, self::VISIBILITY_CLASSE, self::VISIBILITY_PRIVATE];

    /**
     * @param  list<string>  $groups
     */
    public function __construct(
        public readonly int $id,
        public readonly string $token,
        public readonly string $name,
        public readonly string $ownerSub,
        public readonly string $ownerName,
        public readonly string $visibility,
        public readonly array $groups = [],
        public readonly ?int $serverId = null,
        public readonly ?string $lastStartedAt = null,
        public readonly string $createdAt = '',
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $groups
     */
    public static function fromRow(array $row, array $groups = []): self
    {
        return new self(
            id: (int) $row['id'],
            token: (string) $row['token'],
            name: (string) $row['name'],
            ownerSub: (string) $row['owner_sub'],
            ownerName: (string) $row['owner_name'],
            visibility: (string) $row['visibility'],
            groups: $groups,
            serverId: isset($row['server_id']) && $row['server_id'] !== null ? (int) $row['server_id'] : null,
            lastStartedAt: isset($row['last_started_at']) && $row['last_started_at'] !== null
                ? (string) $row['last_started_at']
                : null,
            createdAt: (string) ($row['created_at'] ?? ''),
        );
    }

    public function isOwnedBy(string $sub): bool
    {
        return $sub !== '' && $this->ownerSub === $sub;
    }

    /**
     * **LA décision d'accès.** Elle se rejoue à chaque requête, à partir de la
     * table et des claims — jamais d'un champ de formulaire.
     *
     * - le créateur voit toujours son salon, quelle que soit sa visibilité ;
     * - `etab` : toute personne authentifiée de l'instance ;
     * - `classe` : intersection non vide entre les groupes du salon et ceux de
     *   l'identité. **TOUTES** les entrées du claim comptent — SE4 ne comparait
     *   que la PREMIÈRE classe de l'élève (`list_classes(...)[0]`), et excluait
     *   donc à tort les élèves multi-classes ;
     * - `private` : personne d'autre que le créateur. Le `private` du legacy
     *   signifiait « tous les personnels » — encore un accès large implicite.
     */
    public function isVisibleTo(Identity $identity): bool
    {
        if ($this->isOwnedBy($identity->sub)) {
            return true;
        }

        return match ($this->visibility) {
            self::VISIBILITY_ETAB => true,
            self::VISIBILITY_CLASSE => $this->sharesAGroupWith($identity->groups),
            default => false,
        };
    }

    /** @param  list<string>  $groups */
    private function sharesAGroupWith(array $groups): bool
    {
        foreach ($this->groups as $group) {
            if (in_array($group, $groups, true)) {
                return true;
            }
        }

        return false;
    }

    /** Libellé d'affichage de la visibilité — jamais une décision, seulement un mot. */
    public function visibilityLabel(): string
    {
        return match ($this->visibility) {
            self::VISIBILITY_ETAB => 'Tout l\'établissement',
            self::VISIBILITY_CLASSE => $this->groups === [] ? 'Classes' : implode(', ', $this->groups),
            default => 'Privé',
        };
    }
}
