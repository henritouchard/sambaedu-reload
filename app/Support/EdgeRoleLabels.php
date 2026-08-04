<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Pivot\UserGroupUserPivot;

/**
 * Story 60.2 — LIBELLÉ D'AFFICHAGE du rôle d'arête, par type de groupe.
 *
 * **Les valeurs stockées ne bougent pas.** `member`, `manager`, `owner` restent
 * exactement ce qu'elles sont en base : le renommage en `owner`/`contributor`/
 * `reader` a été examiné et ÉCARTÉ. Le rôle d'arête n'est pas un niveau d'accès —
 * nos propres recettes donnent l'écriture à des `member` — et « contributeur » /
 * « lecteur » existent déjà sous le nom d'accès (`ro|rw`), qui est l'autre côté du
 * mappage. Confondre les deux ferait croire qu'un rôle d'arête détermine un droit,
 * alors qu'il ne fait que qualifier une appartenance.
 *
 * **Ce qui change, c'est la LECTURE, et elle dépend du type de groupe.** Le même
 * `manager` se lit « Enseignant » dans une classe, « Porteur » dans un projet,
 * « Référent » dans une équipe. Avant cette story, trois écrans portaient chacun
 * leur propre `match` — tous les trois écrits pour le cas scolaire, et donc tous
 * les trois faux hors de la classe : un porteur de projet s'y affichait « Prof ».
 * Cette table est la source UNIQUE, et les trois écrans la consomment.
 *
 * **Repli générique, pas d'exception.** Un type sans entrée dédiée prend « Membre »
 * / « Gestionnaire » / « Propriétaire » : neutre, exact, et jamais une valeur
 * technique rendue comme texte visible. Affiner un type de plus est une DONNÉE à
 * ajouter ici, pas un chantier.
 *
 * Classe PURE : aucune requête, aucun état, aucune dépendance de framework.
 */
final class EdgeRoleLabels
{
    /**
     * Libellés SPÉCIFIQUES par type de groupe. Une entrée absente retombe sur le
     * repli — inutile de recopier les trois rôles quand un seul se dit autrement.
     *
     * @var array<string, array<string, string>>
     */
    private const BY_GROUP_TYPE = [
        'classe' => [
            UserGroupUserPivot::ROLE_MEMBER => 'Élève',
            UserGroupUserPivot::ROLE_MANAGER => 'Enseignant',
            UserGroupUserPivot::ROLE_OWNER => 'Professeur principal',
        ],
        'projet' => [
            UserGroupUserPivot::ROLE_MANAGER => 'Porteur',
        ],
        'equipe' => [
            UserGroupUserPivot::ROLE_MANAGER => 'Référent',
        ],
    ];

    /**
     * Repli, valable pour tout type non tranché (cours, matière, custom…).
     *
     * @var array<string, string>
     */
    private const GENERIC = [
        UserGroupUserPivot::ROLE_MEMBER => 'Membre',
        UserGroupUserPivot::ROLE_MANAGER => 'Gestionnaire',
        UserGroupUserPivot::ROLE_OWNER => 'Propriétaire',
    ];

    /**
     * Libellé FR d'un rôle d'arête dans un groupe de ce type.
     *
     * Un rôle vide ou hors vocabulaire (donnée héritée) est lu comme `member` —
     * même normalisation que les écrans de groupes depuis la story 42.3 : on
     * affiche le rôle le moins doté plutôt qu'une valeur technique ou un vide.
     */
    public static function label(?string $groupType, ?string $edgeRole): string
    {
        $role = in_array($edgeRole, UserGroupUserPivot::ROLES, true)
            ? (string) $edgeRole
            : UserGroupUserPivot::ROLE_MEMBER;

        $type = $groupType === null ? '' : mb_strtolower(trim($groupType));

        return self::BY_GROUP_TYPE[$type][$role] ?? self::GENERIC[$role];
    }

    /**
     * Les trois rôles d'arête et leur libellé pour ce type, dans l'ordre du
     * vocabulaire stocké (du moins doté au plus doté).
     *
     * Destiné aux listes de choix : sans cette forme, chaque écran recopierait
     * l'ordre et retomberait dans le `match` local qu'on vient de supprimer.
     *
     * @return array<string, string> valeur stockée => libellé
     */
    public static function options(?string $groupType): array
    {
        $options = [];
        foreach (UserGroupUserPivot::ROLES as $role) {
            $options[$role] = self::label($groupType, $role);
        }

        return $options;
    }
}
