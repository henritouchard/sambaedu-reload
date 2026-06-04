<?php

declare(strict_types=1);

namespace App\Ldap\Fakes;

use App\Contracts\Ad\AdCredentialValidator;

/**
 * Validation de credentials AD FAKE e2e (Story 21.2, T4 — canal B).
 *
 * Bindée sur {@see AdCredentialValidator} UNIQUEMENT en `e2e`. Remplace le
 * `ldap_bind` réel par une COMPARAISON au mot de passe seedé — aucun bind LDAP
 * ne sort vers `samba-ad-dc` (AC4).
 *
 * Résolution du mot de passe attendu (par login, ré-extrait du DN de bind
 * produit par {@see FakeAdDirectory::dnForLogin()}) :
 *  1. override par login dans `config('e2e.fake_ad_passwords')` (map login→mdp) ;
 *  2. sinon mot de passe e2e partagé `config('e2e.fake_ad_password')`.
 *
 * Le seed de référence (Story 21.3) fournira les users + le(s) mot(s) de passe
 * connu(s) via ces clés de config (alimentées par `.env.e2e`). 21.2 ne pose que
 * le mécanisme.
 *
 * Comparaison en temps constant (`hash_equals`) ; le mot de passe n'est jamais
 * loggué.
 */
class FakeE2eAdCredentialValidator implements AdCredentialValidator
{
    public function attemptBind(string $userDn, string $password): bool
    {
        if ($password === '') {
            return false;
        }

        $login = FakeAdDirectory::loginFromDn($userDn);
        if ($login === null) {
            return false;
        }

        $expected = $this->expectedPasswordFor($login);
        if ($expected === null || $expected === '') {
            return false;
        }

        return hash_equals($expected, $password);
    }

    /**
     * Mot de passe e2e attendu pour ce login (override par login, repli partagé).
     */
    private function expectedPasswordFor(string $login): ?string
    {
        $overrides = (array) config('e2e.fake_ad_passwords', []);

        // Clés de map insensibles à la casse (AD est case-insensitive sur le login).
        foreach ($overrides as $key => $value) {
            if (strcasecmp((string) $key, $login) === 0) {
                return (string) $value;
            }
        }

        $shared = config('e2e.fake_ad_password');

        return $shared === null ? null : (string) $shared;
    }
}
