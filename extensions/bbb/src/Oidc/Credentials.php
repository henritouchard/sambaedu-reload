<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Oidc;

use SambaEdu\ExtBbb\Env;

/**
 * Story 57.1 — Les credentials OIDC de l'extension, tels que SE5 les a posés.
 *
 * Rien n'est dérivé, rien n'est reconstruit : ce sont les valeurs verbatim du
 * fichier d'environnement. En particulier `redirectUri` est un **CHEMIN**
 * (`/ext/bbb/oidc/callback`), comparé en égalité stricte par le fournisseur à
 * l'autorisation ET à l'échange — le rendre absolu casserait le SSO.
 */
final class Credentials
{
    public function __construct(
        public readonly string $issuer,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $redirectUri,
    ) {
    }

    public static function fromEnv(Env $env): self
    {
        return new self(
            issuer: $env->issuer,
            clientId: $env->clientId,
            clientSecret: $env->clientSecret,
            redirectUri: $env->redirectUri,
        );
    }

    public function isComplete(): bool
    {
        return $this->issuer !== ''
            && $this->clientId !== ''
            && $this->clientSecret !== ''
            && $this->redirectUri !== '';
    }
}
