<?php

declare(strict_types=1);

namespace Tests\Feature\Oidc\Concerns;

/**
 * Story 55.1 — Pointe `config('oidc.*')` sur la paire RS256 de test.
 *
 * On **réutilise** `tests/fixtures/auth-v1/{private,public}.pem` (paire TEST
 * ONLY déjà commitée) plutôt que de générer une clé au `setUp()` : la
 * génération d'une RSA 2048 coûte des centaines de millisecondes, multipliées
 * par chaque test. Seul `OidcKeysInitCommandTest` génère réellement une paire,
 * puisque c'est son objet.
 *
 * ⚠️ Ces fixtures sont refusées par `OidcKeyManager` hors environnement
 * `testing`/`local` (garde-fou `forbid_test_keys_in_production`) : leur clé
 * privée est publique de fait, puisqu'elle est commitée au dépôt.
 */
trait UsesOidcTestKeys
{
    /** `kid` utilisé par les tests — arbitraire, mais stable et assertable. */
    protected string $testKid = 'test-oidc-kid';

    /** Issuer utilisé par les tests. */
    protected string $testIssuer = 'https://se5.test';

    protected function useOidcTestKeys(): void
    {
        config([
            'oidc.issuer' => $this->testIssuer,
            'oidc.active_kid' => $this->testKid,
            'oidc.keys' => [
                $this->testKid => [
                    'private' => base_path('tests/fixtures/auth-v1/private.pem'),
                    'public' => base_path('tests/fixtures/auth-v1/public.pem'),
                ],
            ],
        ]);
    }

    /** Contenu PEM de la clé publique de test (vérification des id_token). */
    protected function testPublicKeyPem(): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/auth-v1/public.pem'));
    }
}
