<?php

declare(strict_types=1);

namespace App\Auth\Oidc\Support;

/**
 * Story 55.1 — Catalogue des codes d'erreur INTERNES du fournisseur OIDC
 * (calque de {@see \App\Auth\V1\Support\JwtErrorCodes}).
 *
 * ⚠️ **Ces codes ne sortent JAMAIS dans une réponse HTTP.** Ils vont dans le
 * journal (channel `oidc`), et rien d'autre. Le contrat public rendu au client
 * est celui d'OAuth 2.0 (RFC 6749 §5.2 / OIDC Core §3.1.2.6) :
 * `invalid_request`, `invalid_client`, `invalid_grant`, `invalid_scope`,
 * `unsupported_response_type`, `unsupported_grant_type`.
 *
 * La séparation est délibérée : renvoyer « code expiré » plutôt que « code
 * inconnu » indiquerait à un attaquant qu'il a trouvé un code valide mais
 * périmé. Côté exploitation en revanche, distinguer les deux est indispensable
 * pour diagnostiquer une intégration qui échoue — d'où ce catalogue fin, réservé
 * aux logs.
 *
 * On garde des constantes `string` (et pas un enum) pour l'usage direct en
 * contexte de log, sans `->value`.
 */
final class OidcErrorCodes
{
    // --- Refus NON redirigeables (règle OAuth : on ne redirige jamais vers une
    //     `redirect_uri` non validée — sinon SE5 devient un open-redirector et
    //     le refus lui-même fuit vers l'attaquant). Page d'erreur locale 400.
    public const CLIENT_UNKNOWN = 'oidc.client_unknown';
    public const CLIENT_DISABLED = 'oidc.client_disabled';
    public const REDIRECT_URI_MISSING = 'oidc.redirect_uri_missing';
    public const REDIRECT_URI_MISMATCH = 'oidc.redirect_uri_mismatch';

    // --- Refus REDIRIGEABLES (client et URI validés : le client mérite une
    //     réponse OAuth normalisée sur sa propre `redirect_uri`).
    public const UNSUPPORTED_RESPONSE_TYPE = 'oidc.unsupported_response_type';
    public const SCOPE_MISSING_OPENID = 'oidc.scope_missing_openid';
    public const PKCE_MISSING = 'oidc.pkce_missing';
    public const PKCE_METHOD_UNSUPPORTED = 'oidc.pkce_method_unsupported';

    // --- Token endpoint.
    public const CLIENT_AUTH_FAILED = 'oidc.client_auth_failed';
    public const UNSUPPORTED_GRANT_TYPE = 'oidc.unsupported_grant_type';
    public const CODE_MISSING = 'oidc.code_missing';
    public const CODE_INVALID = 'oidc.code_invalid';
    public const CODE_EXPIRED = 'oidc.code_expired';
    public const CODE_CONSUMED = 'oidc.code_consumed';
    public const CODE_CLIENT_MISMATCH = 'oidc.code_client_mismatch';
    public const CODE_VERIFIER_MISSING = 'oidc.code_verifier_missing';
    public const CODE_VERIFIER_MISMATCH = 'oidc.code_verifier_mismatch';

    // --- Session / configuration.
    public const NO_SESSION = 'oidc.no_session';
    public const KEYS_UNAVAILABLE = 'oidc.keys_unavailable';

    /**
     * Liste exhaustive (tests d'invariance + audits).
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::CLIENT_UNKNOWN,
            self::CLIENT_DISABLED,
            self::REDIRECT_URI_MISSING,
            self::REDIRECT_URI_MISMATCH,
            self::UNSUPPORTED_RESPONSE_TYPE,
            self::SCOPE_MISSING_OPENID,
            self::PKCE_MISSING,
            self::PKCE_METHOD_UNSUPPORTED,
            self::CLIENT_AUTH_FAILED,
            self::UNSUPPORTED_GRANT_TYPE,
            self::CODE_MISSING,
            self::CODE_INVALID,
            self::CODE_EXPIRED,
            self::CODE_CONSUMED,
            self::CODE_CLIENT_MISMATCH,
            self::CODE_VERIFIER_MISSING,
            self::CODE_VERIFIER_MISMATCH,
            self::NO_SESSION,
            self::KEYS_UNAVAILABLE,
        ];
    }
}
