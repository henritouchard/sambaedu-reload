<?php

declare(strict_types=1);

namespace App\Auth\Oidc\Support;

use App\Models\User;

/**
 * Story 55.2 — **LE CONTRAT DE CLAIMS MÉTIER v1, ET SON POINT UNIQUE DE
 * RÉSOLUTION.**
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  ⚠️ CONTRAT PUBLIC GELÉ (NFR11)
 *
 *  À partir de la première extension intégrée (55.3), ce qui sort d'ici est
 *  consommé par du code que nous n'écrivons pas et que nous ne redéployons
 *  pas. La règle est asymétrique :
 *
 *    • on peut AJOUTER un claim ou un scope (évolution additive) ;
 *    • on ne peut JAMAIS retirer un claim, le renommer, ni changer son TYPE
 *      (`role` scalaire ⇄ tableau, `groups` liste ⇄ objet).
 *
 *  Corollaire pratique : **une clé de claim en trop est une dette
 *  permanente**. On n'émet QUE ce qui a été décidé, et la liste EXACTE des
 *  clés émises est verrouillée par test
 *  ({@see \Tests\Feature\Oidc\OidcIdTokenClaimsTest}) — pas seulement par des
 *  `assertArrayNotHasKey`, qui n'attrapent que ce à quoi on a pensé.
 * ══════════════════════════════════════════════════════════════════════════
 *
 *  Scope       | Claims produits | Source (SQL UNIQUEMENT, zéro LDAP)
 *  ------------|-----------------|-------------------------------------------
 *  `openid`    | `sub`           | {@see OidcSubjectResolver::for()} — PAS ICI
 *  `profile`   | `name`, `role`  | `display_name` ; `businessRoles()[0]`
 *  `groups`    | `groups`        | `user_groups` types `classe` + `equipe`
 *
 * **`sub` ne se résout JAMAIS ici.** Il a son point unique
 * ({@see OidcSubjectResolver}) et cette classe ne lit ni `login`, ni `ad_guid`,
 * ni `users.id` — pas même pour un repli. Un jour où `sub` changerait de
 * nature, il n'y aurait rien à chercher dans ce fichier.
 *
 * **Vocabulaire fermé de `role`** : `prof`, `eleve`, `administratif`, `admin`
 * — exactement la sortie normalisée de {@see User::businessRoles()}. Jamais
 * `autre`, jamais `federated`, jamais un nom de rôle Spatie brut.
 *
 * **`role` est un SCALAIRE**, premier élément de `businessRoles()`. Le profil
 * métier prime sur `admin` par construction de la méthode (le profil est
 * poussé avant le rôle Spatie) : un prof délégué super-admin reste `prof` pour
 * l'extension pédagogique. Un ensemble vide ⇒ **clé absente**, jamais une
 * valeur inventée ni une sentinelle : un claim absent est plus honnête, et
 * tout client OIDC sait tester l'absence. Le besoin éventuel de l'ensemble
 * complet justifiera un claim `roles` ADDITIF, jamais un changement de type.
 *
 * **Pas d'`email`, même sous `profile`** — dérogation ASSUMÉE au scope OIDC
 * standard. La population contient des élèves mineurs et NFR5 dit « identité,
 * rôle, groupes du contexte — rien d'autre ». Ni `email`, ni `given_name` /
 * `family_name` (le `display_name` suffit à « Bonjour {name} »), ni aucun
 * attribut d'annuaire (`ad_guid`, `dn`, `memberOf`), ni aucune permission
 * Spatie. Un intégrateur qui cherche `email` doit comprendre que c'est un
 * CHOIX, pas un oubli.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  UN CLAIM EST UNE **DONNÉE**, PAS UNE AUTORISATION
 *
 *  `role=prof` n'ouvre aucun droit — ni dans SE5, ni dans l'extension. C'est
 *  la même distinction que la tuile du lanceur (54.3 / FR14 : afficher n'est
 *  pas protéger). L'autorisation réelle reste côté extension, sur la base de
 *  SES règles. Une extension qui traiterait la présence d'un claim comme une
 *  permission se tromperait de contrat.
 * ══════════════════════════════════════════════════════════════════════════
 */
final class OidcClaimsResolver
{
    /**
     * Source UNIQUE du mapping scope → claims. Consommée par la validation des
     * scopes ({@see \App\Auth\Oidc\Services\OidcAuthorizationService}) ET par
     * la discovery : deux listes divergentes annonceraient un contrat que
     * l'implémentation ne tient pas.
     *
     * `openid` n'y figure pas : il ne produit pas de claim métier, il produit
     * `sub` — qui est émis inconditionnellement par l'émetteur.
     *
     * @var array<string, list<string>>
     */
    public const CLAIMS_BY_SCOPE = [
        'profile' => ['name', 'role'],
        'groups' => ['groups'],
    ];

    /**
     * Types de `user_groups` qui constituent « les groupes du contexte »
     * (NFR5) : la classe et l'équipe pédagogique — exactement ce dont un salon
     * BBB par classe a besoin.
     *
     * ⚠️ Les types `custom` / `role` / `function` sont des artefacts
     * d'administration INTERNE : les publier serait une fuite gratuite.
     *
     * @var list<string>
     */
    public const GROUP_TYPES = ['classe', 'equipe'];

    /**
     * Vocabulaire complet et fermé du claim `role` (documentation exécutable :
     * la discovery et les tests s'y adossent).
     *
     * @var list<string>
     */
    public const ROLE_VOCABULARY = ['prof', 'eleve', 'administratif', 'admin'];

    /**
     * Scopes supportés — ensemble FERMÉ. Tout ce qui n'est pas là est refusé à
     * l'autorisation (`invalid_scope`, fail-closed) : un scope qui n'existe pas
     * ne peut pas être « accordé silencieusement », donc ne peut rien produire.
     *
     * @return list<string>
     */
    public static function supportedScopes(): array
    {
        return array_merge(['openid'], array_keys(self::CLAIMS_BY_SCOPE));
    }

    /**
     * Découpe une chaîne de scopes OAuth (séparateur : l'espace, RFC 6749
     * §3.3). Même patron que `validateAuthorizeRequest()` — `str_contains()`
     * ferait passer « openidx » pour « openid ».
     *
     * @return list<string>
     */
    public static function parseScope(string $scope): array
    {
        $parts = preg_split('/\s+/', trim($scope)) ?: [];

        return array_values(array_filter($parts, static fn (string $s): bool => $s !== ''));
    }

    /**
     * Claims MÉTIER d'un utilisateur pour un scope donné.
     *
     * Ne contient JAMAIS `sub` ni aucun claim standard : ceux-là appartiennent
     * à l'émetteur, qui les impose par-dessus ce tableau
     * ({@see \App\Auth\Oidc\Jwt\OidcIdTokenIssuer::issueIdToken()}).
     *
     * Un scope non demandé ne produit RIEN — c'est la minimisation NFR5, et
     * c'est vérifié par la liste exacte des clés en test.
     *
     * @return array<string, mixed>
     */
    public static function claimsFor(User $user, string $scope): array
    {
        $requested = self::parseScope($scope);
        $claims = [];

        if (in_array('profile', $requested, true)) {
            // `display_name` = `fullname ?? login` (accessor du modèle). On ne
            // lit PAS `login` ici : l'accessor porte déjà le repli, et
            // dupliquer la règle ferait apparaître un second endroit où
            // l'identité d'un utilisateur se décide.
            $claims['name'] = (string) $user->display_name;

            // Ensemble vide (`users.role` = `autre`/`federated`, identité
            // fédérée sans délégation Spatie) ⇒ **pas de clé `role`**.
            // Fail-closed : l'extension qui n'obtient pas de rôle n'habilite
            // pas. Jamais `"autre"`, jamais `null`, jamais `""`.
            $role = $user->businessRoles()[0] ?? null;

            if ($role !== null) {
                $claims['role'] = $role;
            }
        }

        if (in_array('groups', $requested, true)) {
            $claims['groups'] = self::groupNames($user);
        }

        return $claims;
    }

    /**
     * Noms NUS des classes et équipes de l'utilisateur — UNE requête SQL.
     *
     * Calque élargi de {@see User::classGroupNames()} (post-fold 4.13, une
     * classe est UNE ligne au nom nu : prof et élèves y sont co-membres — le
     * claim d'un prof porte donc ses classes, celui d'un élève la sienne).
     *
     * ⚠️ **Jamais `ldapBusinessObject()`** : la fiche projet « isProf/isEleve
     * LDAP-first » documente le coût — un round-trip annuaire PAR utilisateur.
     * Tout vient du pivot `user_group_user`.
     *
     * **Trié** pour que deux appels successifs, ou l'id_token et `/userinfo`,
     * donnent le MÊME tableau : un ordre instable obligerait chaque client à
     * trier avant de comparer.
     *
     * **Dédupliqué en défense en profondeur seulement** : `user_groups.name`
     * ET le pivot `(user_group_id, user_id)` sont UNIQUE en base, donc un
     * doublon est aujourd'hui structurellement impossible (les deux
     * contraintes sont verrouillées par test). Le `unique()` reste pour le
     * jour où l'une d'elles bougerait — un client qui itère sur `groups`
     * créerait sinon deux salons.
     *
     * Le scope accordé émet la clé même vide : « aucun groupe » est une
     * donnée, pas une absence de réponse.
     *
     * @return list<string>
     */
    private static function groupNames(User $user): array
    {
        return $user->userGroups()
            ->whereIn('type', self::GROUP_TYPES)
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
