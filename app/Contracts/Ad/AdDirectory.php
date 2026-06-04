<?php

declare(strict_types=1);

namespace App\Contracts\Ad;

use App\LdapModels\LdapUser;

/**
 * Point d'injection de la RÉSOLUTION d'identité AD (canal A, Story 21.2, DP-AUTH).
 *
 * Le chemin d'auth résout l'utilisateur LDAP à deux moments :
 *  - au login, via {@see \App\Services\AuthenticationService::validatePassword()}
 *    (→ `UserRepository::findLdapModelByLogin()`), pour récupérer le DN à binder ;
 *  - à CHAQUE requête suivante, via {@see \App\Http\Middleware\Auth\SambaEduAuthGuard}
 *    (→ `UserRepository::findByLogin()`), pour revérifier que l'utilisateur
 *    existe toujours et est actif.
 *
 * Ces deux call-sites passaient en dur par `LdapUser::findByLogin()` (statique →
 * LdapRecord Container). On les route désormais via cette interface :
 *  - {@see \App\Ldap\Real\RealAdDirectory} = délègue à `LdapUser::findByLogin()`
 *    (comportement inchangé, bindé par défaut partout — AC5) ;
 *  - {@see \App\Ldap\Fakes\FakeAdDirectory} = sert l'utilisateur seedé en
 *    Postgres sans aucune requête LDAP (bindé uniquement en `e2e` — AC4).
 *
 * Couvre ainsi les sous-chemins (a) résolution login et (c) revérif par requête
 * d'un seul tenant. Le sous-chemin (b) validation du mot de passe est couvert
 * par {@see AdCredentialValidator}.
 */
interface AdDirectory
{
    /**
     * Résout un utilisateur AD par son login (`cn`/`samAccountName`).
     *
     * @return LdapUser|null  Le modèle LdapRecord (réel ou hydraté en mémoire
     *                        par le fake), ou null si introuvable.
     */
    public function findUserByLogin(string $login): ?LdapUser;
}
