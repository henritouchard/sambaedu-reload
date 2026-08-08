<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Nextcloud;

use App\Models\User;
use App\Models\UserGroup;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanSubject;
use App\Services\Filesystem\ShareService;

/**
 * Story 61.3 — LA TRADUCTION DES SUJETS, DANS LES DEUX SENS, ET LE SEUL ENDROIT OÙ
 * LES DEUX VOCABULAIRES SE RENCONTRENT.
 *
 * Même rôle que son homologue du serveur de fichiers historique, et pour la même
 * raison : au-dessus de la ligne, personne n'a besoin de savoir ce qu'est un
 * identifiant de compte distant.
 *
 * ---------------------------------------------------------------------------
 * **L'IDENTITÉ D'UN COMPTE VIENT DU CACHE, ET DE RIEN D'AUTRE.**
 *
 * `users.nextcloud_user_id` est la SEULE source. Pas d'autocomplétion, pas de
 * « c'est sûrement le login », pas de résolution à la volée : la revue de 61.1 a
 * tranché qu'on n'adopte qu'un HOMONYME, et un rattachement non vérifié rouvre
 * l'écrasement du mot de passe d'un tiers. Cache vide ⇒ le nœud le DIT, en nommant
 * l'utilisateur ET la commande qui répare. Un octroi qu'on n'écrit pas doit se voir.
 *
 * **Le `sub` d'un jeton OIDC N'EST PAS UNE CLÉ DE JOINTURE.** L'Epic 55 publie
 * `sub = login` : c'est un choix de CLAIM, révocable, pas un contrat de jointure.
 * La vérité de liaison est ce cache — reconstructible, vérifié à distance, et
 * porteur d'une garde d'unicité. Aucun code de ce backend ne référence un claim ni
 * le vocabulaire de la fédération, et un test d'architecture l'épingle.
 *
 * ---------------------------------------------------------------------------
 * **LES GROUPES SONT FABRIQUÉS PAR SE5, DONC LEUR NOM EST CALCULÉ, JAMAIS LU.**
 *
 * Forme canonique : `se5_<nom court>` pour un sujet de groupe nu, et
 * `se5_<nom court>_<rôle d'arête>` pour « les membres qui portent ce rôle ». Le nom
 * court est celui que le dépôt dérive DÉJÀ — suffixe d'établissement compris. En
 * écrire une seconde dérivation ferait diverger deux normalisations du même objet,
 * et la divergence ne se verrait qu'au moment où elle coûte le plus cher.
 *
 * **La reprojection inverse RECALCULE les noms attendus depuis le plan ; elle ne
 * découpe jamais un nom observé.** Un découpage se casse sur le premier nom court
 * qui contient un souligné — et tous en contiennent. Ce qui ne se retrouve pas dans
 * la table des noms attendus n'est pas deviné : il est SIGNALÉ comme étranger.
 *
 * **L'appartenance est EXACTE, pas un surensemble.** Le backend du serveur de
 * fichiers historique recopie un trio de groupes d'annuaire qu'il n'a pas fabriqué,
 * et assume donc des surensembles. Ici, le groupe est FABRIQUÉ : le rôle d'arête se
 * filtre exactement, et le faire est gratuit.
 */
final class NextcloudSubjectProjector
{
    /**
     * Préfixe des groupes COMPILÉS par SE5 sur l'instance.
     *
     * Il est court, minuscule, et il tient lieu de discriminant : un groupe qui ne
     * le porte pas n'a pas été fabriqué par SE5, donc la relecture n'a rien à en
     * dire — et surtout, la révocation n'a pas à y toucher.
     */
    public const GROUP_PREFIX = 'se5_';

    /**
     * Le groupe STRUCTUREL du compte d'administration.
     *
     * **Pourquoi il existe, et pourquoi il n'est pas un octroi.** Le canal des
     * règles passe par l'espace de fichiers du compte d'administration : sans
     * appartenance à un groupe du dossier d'équipe, ce compte ne voit pas le
     * dossier, et ni la création des sous-dossiers ni la pose des règles ne sont
     * possibles. C'est un contrat de STRUCTURE du dossier, exactement comme le
     * groupe d'administration d'annuaire l'est pour un répertoire du serveur de
     * fichiers historique — lequel est déjà, et depuis la story 60.4, écarté des
     * octrois observés. On reprend cette frontière à l'identique : deux frontières
     * qui divergeraient feraient de l'une un octroi observé et de l'autre pas.
     */
    public const ADMIN_GROUP = self::GROUP_PREFIX . 'administration';

    public function __construct(private readonly ShareService $shareService)
    {
    }

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
    public function nextcloudUserIdFor(PlanSubject $subject): ?string
    {
        if ($subject->type !== PlanSubject::TYPE_USER) {
            return null;
        }

        $user = User::find($subject->id);
        if (! $user instanceof User) {
            return null;
        }

        $cached = trim((string) ($user->nextcloud_user_id ?? ''));

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
     * neutralité des messages de rapport, et elle est délibérée : sans la commande,
     * l'administrateur sait qu'il manque quelque chose sans savoir quoi faire.
     */
    public function identityRemediation(string $login): string
    {
        return sprintf(
            'aucune identité Nextcloud connue pour « %s » : son octroi n\'a pas été écrit. Provisionnez '
            . 'le compte (nextcloud:provision) ou rattachez-le explicitement '
            . '(nextcloud:identity %s --set=<identifiant>).',
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
            // d'arête ne coûte rien et évite le surensemble assumé du backend
            // POSIX (qui, lui, recopie des groupes d'annuaire existants).
            $query = $query->wherePivot('role', $subject->edgeRole);
        }

        $members = [];
        $missing = [];

        foreach ($query->get() as $user) {
            $cached = trim((string) ($user->nextcloud_user_id ?? ''));
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
     * Index INVERSE, construit par RECALCUL des noms attendus depuis le plan.
     *
     * Les sujets de groupe donnent leur nom compilé ; les sujets d'utilisateur
     * donnent leur identifiant de compte caché. Rien n'est découpé, rien n'est
     * deviné : ce qui n'est pas dans cette table est étranger au plan, et
     * l'observation le DIT au lieu de l'omettre.
     *
     * @return array{groups: array<string, PlanSubject>, users: array<string, PlanSubject>}
     */
    public function reverseIndex(FilePlan $plan): array
    {
        $groups = [];
        $users = [];

        foreach ($this->subjectsOf($plan) as $subject) {
            if ($subject->type === PlanSubject::TYPE_USER) {
                $id = $this->nextcloudUserIdFor($subject);
                if ($id !== null && ! array_key_exists($id, $users)) {
                    $users[$id] = $subject;
                }

                continue;
            }

            $name = $this->groupNameFor($subject);
            if ($name !== null && ! array_key_exists($name, $groups)) {
                $groups[$name] = $subject;
            }
        }

        return ['groups' => $groups, 'users' => $users];
    }

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
