<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\OpenCloud;

use App\Models\User;
use App\Models\UserGroup;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanSubject;
use App\Services\Filesystem\ShareService;

/**
 * LA TRADUCTION DES SUJETS, DANS LES DEUX SENS, ET LE SEUL ENDROIT OÙ LES DEUX
 * VOCABULAIRES SE RENCONTRENT.
 *
 * Même rôle que ses homologues du serveur de fichiers historique et de l'autre
 * produit, et pour la même raison : au-dessus de la ligne, personne n'a besoin de
 * savoir ce qu'est un identifiant de compte distant.
 *
 * ---------------------------------------------------------------------------
 * **L'IDENTITÉ D'UN COMPTE VIENT DU CACHE, ET DE RIEN D'AUTRE.**
 *
 * `users.opencloud_user_id` est la SEULE source. Pas d'autocomplétion, pas de
 * « c'est sûrement le login », pas de résolution à la volée. Ici la règle n'est
 * même pas seulement doctrinale, elle est MESURÉE : l'API refuse de filtrer les
 * comptes sur leur identifiant de connexion
 * (`{"error":{"code":"generalException","message":"unsupported filter"}}`), de
 * sorte qu'une résolution « à la volée » signifierait énumérer tout l'annuaire à
 * chaque nœud — et apparier sur un homonyme. Cache vide ⇒ le nœud le DIT, en
 * nommant l'utilisateur ET la remédiation. Un octroi qu'on n'écrit pas doit se
 * voir.
 *
 * **LE `sub` D'UN JETON OIDC N'EST PAS UNE CLÉ DE JOINTURE, et la tentation est
 * PLUS FORTE ICI QU'AILLEURS.** SE5 est le fournisseur d'identité de l'instance
 * qu'il vient lui-même de déployer : il serait trivial de « savoir » que le
 * compte distant porte le login publié en revendication. Ce serait un choix de
 * CLAIM, révocable, pas un contrat de jointure — et il marcherait jusqu'au jour
 * où quelqu'un change la revendication, où l'accès d'un élève irait chez un
 * autre. La vérité de liaison est ce cache : reconstructible, vérifié à distance,
 * porteur d'une garde d'unicité en base. Aucun code de ce backend ne référence un
 * claim ni le vocabulaire de la fédération, et un test d'architecture l'épingle.
 *
 * ---------------------------------------------------------------------------
 * **LES GROUPES SONT FABRIQUÉS PAR SE5, DONC LEUR NOM EST CALCULÉ, JAMAIS LU.**
 *
 * Forme canonique : `se5_<nom court>` pour un sujet de groupe nu, et
 * `se5_<nom court>_<rôle d'arête>` pour « les membres qui portent ce rôle ». Le
 * nom court est celui que le dépôt dérive DÉJÀ — suffixe d'établissement
 * compris. En écrire une seconde dérivation ferait diverger deux normalisations
 * du même objet, et la divergence ne se verrait qu'au moment où elle coûte le
 * plus cher.
 *
 * **Un nom n'est PAS un identifiant, ici** : mesuré, un groupe OpenCloud est
 * désigné par un UUID, et son nom d'affichage n'est qu'un attribut. Le nom
 * calculé sert donc à RETROUVER le groupe dans l'annuaire relu, jamais à
 * l'adresser. Cette indirection est la raison pour laquelle la projection
 * conserve les deux.
 *
 * **La reprojection inverse RECALCULE les noms attendus depuis le plan ; elle ne
 * découpe jamais un nom observé.** Un découpage se casse sur le premier nom court
 * qui contient un souligné — et tous en contiennent. Ce qui ne se retrouve pas
 * dans la table des noms attendus n'est pas deviné : il est SIGNALÉ comme
 * étranger.
 */
final class OpenCloudSubjectProjector
{
    /**
     * Préfixe des groupes COMPILÉS par SE5 sur l'instance.
     *
     * Il tient lieu de discriminant : un groupe qui ne le porte pas n'a pas été
     * fabriqué par SE5, donc la relecture n'a rien à en dire — et surtout, la
     * révocation n'a pas à y toucher.
     */
    public const GROUP_PREFIX = 'se5_';

    public function __construct(private readonly ShareService $shareService) {}

    // =========================================================================
    // Projection AVANT (sujet de plan → principal distant)
    // =========================================================================

    /**
     * Le nom de groupe distant d'un sujet de type groupe, ou `null` s'il n'est pas
     * dérivable (nom refusé par le durcissement, groupe disparu).
     */
    public function groupNameFor(PlanSubject $subject): ?string
    {
        if ($subject->type !== PlanSubject::TYPE_USER_GROUP) {
            return null;
        }

        $group = UserGroup::find($subject->id);
        if (! $group instanceof UserGroup) {
            return null;
        }

        return $this->groupNameForModel($group, $subject->edgeRole);
    }

    /** Même dérivation, depuis le modèle déjà chargé. */
    public function groupNameForModel(UserGroup $group, ?string $edgeRole): ?string
    {
        $local = $this->shareService->aclGroupLocalPart($group);

        if ($local === null) {
            $fallback = strtolower(trim((string) $group->name));
            $local = preg_match('/^[a-z0-9._-]+$/', $fallback) === 1 ? $fallback : null;
        }

        if ($local === null || $local === '') {
            return null;
        }

        $name = self::GROUP_PREFIX . strtolower($local);

        return $edgeRole === null ? $name : $name . '_' . strtolower($edgeRole);
    }

    /**
     * L'identifiant de compte distant d'un sujet de type utilisateur.
     *
     * Rend `null` quand le cache est vide : l'appelant DOIT alors nommer
     * l'utilisateur et la remédiation ({@see identityRemediation()}), jamais
     * résoudre à la volée.
     */
    public function openCloudUserIdFor(PlanSubject $subject): ?string
    {
        if ($subject->type !== PlanSubject::TYPE_USER) {
            return null;
        }

        $user = User::find($subject->id);
        if (! $user instanceof User) {
            return null;
        }

        $cached = trim((string) ($user->opencloud_user_id ?? ''));

        return $cached === '' ? null : $cached;
    }

    /** Le login SE5 d'un sujet utilisateur, pour les messages — jamais pour joindre. */
    public function loginFor(PlanSubject $subject): string
    {
        $user = $subject->type === PlanSubject::TYPE_USER ? User::find($subject->id) : null;

        return $user instanceof User ? (string) $user->login : ('#' . $subject->id);
    }

    /**
     * La phrase qui rend l'incident réparable. C'est la seule dérogation à la
     * neutralité des messages de rapport, et elle est délibérée : sans le geste,
     * l'administrateur sait qu'il manque quelque chose sans savoir quoi faire.
     */
    public function identityRemediation(string $login): string
    {
        return sprintf(
            'aucune identité OpenCloud connue pour « %s » : son octroi n\'a pas été écrit. Rattachez son '
            . 'compte (opencloud:identity %s --set=<identifiant>), puis relancez la réconciliation de ce '
            . 'répertoire.',
            $login,
            $login,
        );
    }

    /**
     * Les identifiants de comptes distants que ce sujet de groupe DÉSIGNE, et les
     * logins qui n'en ont pas.
     *
     * @return array{members: list<string>, missing: list<string>}
     */
    public function membersFor(PlanSubject $subject): array
    {
        if ($subject->type !== PlanSubject::TYPE_USER_GROUP) {
            return ['members' => [], 'missing' => []];
        }

        $group = UserGroup::find($subject->id);
        if (! $group instanceof UserGroup) {
            return ['members' => [], 'missing' => []];
        }

        $query = $group->users();
        if ($subject->edgeRole !== null) {
            // Appartenance EXACTE : le groupe est fabriqué ici, filtrer le rôle
            // d'arête ne coûte rien et évite le surensemble assumé du backend du
            // serveur de fichiers historique (qui, lui, recopie des groupes
            // d'annuaire existants).
            $query = $query->wherePivot('role', $subject->edgeRole);
        }

        $members = [];
        $missing = [];

        foreach ($query->get() as $user) {
            $cached = trim((string) ($user->opencloud_user_id ?? ''));
            if ($cached === '') {
                $missing[] = (string) $user->login;

                continue;
            }
            $members[] = $cached;
        }

        $members = array_values(array_unique($members));
        $missing = array_values(array_unique($missing));
        sort($members, SORT_STRING);
        sort($missing, SORT_STRING);

        return ['members' => $members, 'missing' => $missing];
    }

    // =========================================================================
    // Projection INVERSE (principal distant → sujet de plan)
    // =========================================================================

    /**
     * TOUS les sujets que le plan exprime : ceux de ses octrois, et ceux que ses
     * rôles clos désignent. Les seconds comptent autant que les premiers — c'est
     * sur eux que la clôture se referme.
     *
     * @return list<PlanSubject>
     */
    public function subjectsOf(FilePlan $plan): array
    {
        $seen = [];
        $subjects = [];

        $remember = static function (PlanSubject $subject) use (&$seen, &$subjects): void {
            $key = $subject->sortKey();
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $subjects[] = $subject;
            }
        };

        foreach ($plan->nodes as $node) {
            foreach ($node->grants as $grant) {
                $remember($grant->subject);
            }
            foreach ($node->closure as $role) {
                foreach ($plan->roles[$role] ?? [] as $subject) {
                    $remember($subject);
                }
            }
        }

        return $subjects;
    }
}
