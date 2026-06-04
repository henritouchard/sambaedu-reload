<?php

declare(strict_types=1);

namespace App\Contracts\Ad;

/**
 * Point d'injection de la validation de credentials AD (Story 21.2, DP-AUTH = Option A).
 *
 * Extrait le `ldap_bind` brut (canal B) de {@see \App\Services\AuthenticationService}
 * derrière une interface injectable, exactement comme le repo le fait déjà pour
 * `AuthGuardInterface` et `Print\Contracts\CommandRunner` :
 *
 *  - implémentation RÉELLE ({@see \App\Services\Auth\RealAdCredentialValidator})
 *    = le `ldap_connect()` + `@ldap_bind()` historique. Bindée PAR DÉFAUT dans
 *    tous les environnements (dev/prod/testing). Comportement strictement
 *    inchangé (AC5).
 *  - implémentation FAKE e2e ({@see \App\Ldap\Fakes\FakeE2eAdCredentialValidator})
 *    = comparaison au mot de passe seedé en Postgres. Bindée UNIQUEMENT si
 *    `APP_ENV === 'e2e'`. Aucun bind LDAP réel (AC4).
 *
 * Contrat : `attemptBind()` reçoit le DN de bind (résolu en amont à partir du
 * login) et le mot de passe clair. Il NE DOIT JAMAIS logger le mot de passe.
 */
interface AdCredentialValidator
{
    /**
     * Tente une authentification AD pour ce couple (DN, mot de passe).
     *
     * @param  string  $userDn    DN LDAP de l'utilisateur (ex. `CN=jdoe,OU=...`).
     * @param  string  $password  Mot de passe clair (jamais loggué).
     * @return bool  true si l'authentification réussit, false sinon.
     */
    public function attemptBind(string $userDn, string $password): bool;
}
