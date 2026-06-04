<?php

declare(strict_types=1);

namespace App\Ldap\Fakes;

use App\Contracts\Ad\AdDirectory;
use App\LdapModels\LdapUser;
use App\Models\User as UserModel;

/**
 * Annuaire AD FAKE e2e (Story 21.2, T4 — canal A) : résout les utilisateurs
 * SEEDÉS EN POSTGRES sans aucune requête LDAP réelle.
 *
 * Bindé sur {@see AdDirectory} UNIQUEMENT en `e2e` (cf. `AppServiceProvider`).
 * Couvre les sous-chemins d'auth (a) résolution login (`findLdapModelByLogin`)
 * et (c) revérif par requête (`findByLogin` du guard) en hydratant un
 * {@see LdapUser} in-memory à partir de la row `users`.
 *
 * Le LdapUser est construit avec :
 *  - `cn` = login, `displayname`/`sn`/`givenname`/`mail` depuis la row SQL ;
 *  - un `dn` CANONIQUE `CN=<login>,<base>` — utilisé comme DN de bind par
 *    {@see FakeE2eAdCredentialValidator}, qui en ré-extrait le login ;
 *  - `useraccountcontrol` = 512 (actif) / 514 (inactif) selon `users.is_active` ;
 *  - `pwdlastset` = 1 (jamais 0 → on n'emprunte pas le « hack » legacy
 *    `setAttribute('pwdlastset', -1)->save()` qui taperait LDAP).
 *
 * Aucune écriture LDAP. La capture des écritures passe par {@see FakeAdRecorder}.
 */
class FakeAdDirectory implements AdDirectory
{
    /** Base DN synthétique e2e (cohérence d'un DN parsable). */
    public const FAKE_BASE_DN = 'OU=e2e,DC=e2e,DC=local';

    public function findUserByLogin(string $login): ?LdapUser
    {
        $user = UserModel::findByLogin($login);
        if ($user === null) {
            return null;
        }

        return $this->hydrate($user);
    }

    /**
     * Construit le DN de bind canonique d'un login e2e.
     */
    public static function dnForLogin(string $login): string
    {
        return 'CN=' . $login . ',' . self::FAKE_BASE_DN;
    }

    /**
     * Ré-extrait le login (`CN=...`) d'un DN produit par {@see dnForLogin()}.
     */
    public static function loginFromDn(string $dn): ?string
    {
        if (preg_match('/^CN=([^,]+),/i', $dn, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Hydrate un {@see LdapUser} in-memory depuis la row Postgres, sans toucher
     * la connexion LdapRecord (`setRawAttributes` + `setDn`). Le modèle reste
     * exploitable par `toBusinessObject()` et par la lecture de `pwdlastset`.
     */
    private function hydrate(UserModel $user): LdapUser
    {
        $login = (string) $user->login;
        $dn = (string) ($user->dn ?: self::dnForLogin($login));

        $uac = ((bool) ($user->is_active ?? true)) ? 512 : 514;

        $ldap = new LdapUser();
        $ldap->setRawAttributes([
            'cn' => [$login],
            'displayname' => [(string) ($user->fullname ?? $login)],
            'givenname' => [(string) ($user->firstname ?? '')],
            'sn' => [(string) ($user->lastname ?? '')],
            'mail' => [(string) ($user->email ?? '')],
            'useraccountcontrol' => [(string) $uac],
            // Jamais 0 : évite la branche « changement de mdp obligatoire » qui
            // ferait un save() LDAP. Les users seedés ont un mdp valide.
            'pwdlastset' => ['1'],
            'memberof' => $this->resolveMemberOf($user),
        ]);
        $ldap->setDn($dn);

        return $ldap;
    }

    /**
     * memberOf synthétique minimal dérivé du rôle SQL — suffisant pour que
     * `toBusinessObject()` calcule le rôle. Le seed de référence (21.3) pourra
     * enrichir cette projection si un parcours l'exige.
     *
     * @return list<string>
     */
    private function resolveMemberOf(UserModel $user): array
    {
        $role = strtolower((string) ($user->role ?? 'autre'));

        $groupCn = match ($role) {
            'eleves', 'eleve' => 'Eleves',
            'profs', 'prof' => 'Profs',
            'administratifs', 'administratif', 'admin' => 'Administratifs',
            default => null,
        };

        if ($groupCn === null) {
            return [];
        }

        return ['CN=' . $groupCn . ',' . self::FAKE_BASE_DN];
    }
}
