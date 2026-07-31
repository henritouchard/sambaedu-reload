<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Oidc;

/**
 * Story 57.1 — Codes d'erreur INTERNES du client OIDC de l'extension.
 *
 * Portés du témoin SSO de SE5 (Story 55.3). Ils ne décrivent jamais ce que le
 * fournisseur a refusé, seulement ce que L'EXTENSION a refusé d'accepter. Ils
 * partent au journal du service et sont rappelés — seuls, sans détail — sur la
 * page d'erreur, pour qu'un exploitant puisse corréler.
 *
 * ⚠️ **La règle que toute extension DOIT reprendre du témoin** (et la seule) :
 * tous les échecs de signature sont fusionnés dans
 * {@see self::ID_TOKEN_SIGNATURE_INVALID}. `alg: none`, confusion d'algorithme
 * symétrique, clé étrangère et `kid` inconnu sont, vus de l'extérieur, quatre
 * façons de ne pas savoir signer : les distinguer offrirait un oracle gratuit.
 *
 * ⚠️ Aucun de ces codes ne transporte de PII ni de valeur de jeton.
 */
final class ErrorCodes
{
    // ── Transport / provisioning ─────────────────────────────────────────

    /** Les 7 variables d'environnement ne portent pas de credentials OIDC. */
    public const NOT_PROVISIONED = 'bbb.oidc.not_provisioned';

    /** Découverte injoignable, non-JSON, incomplète ou incohérente. */
    public const DISCOVERY_UNAVAILABLE = 'bbb.oidc.discovery_unavailable';

    /** JWKS injoignable, vide ou inexploitable ⇒ aucune vérification possible. */
    public const JWKS_UNUSABLE = 'bbb.oidc.jwks_unusable';

    /** L'échange du code au token endpoint a échoué. */
    public const TOKEN_EXCHANGE_FAILED = 'bbb.oidc.token_exchange_failed';

    /** Réponse du token endpoint sans `id_token`. */
    public const ID_TOKEN_MISSING = 'bbb.oidc.id_token_missing';

    // ── État entre l'autorisation et le retour ───────────────────────────

    public const STATE_MISSING = 'bbb.oidc.state_missing';

    public const STATE_MISMATCH = 'bbb.oidc.state_mismatch';

    public const CODE_MISSING = 'bbb.oidc.code_missing';

    // ── Vérification de l'id_token (la suite d'attaque portée de 55.3) ───

    public const ID_TOKEN_MALFORMED = 'bbb.id_token.malformed';

    /** Le bucket FUSIONNÉ : alg none, HS256, clé étrangère, `kid` inconnu. */
    public const ID_TOKEN_SIGNATURE_INVALID = 'bbb.id_token.signature_invalid';

    public const ID_TOKEN_EXPIRED = 'bbb.id_token.expired';

    public const ID_TOKEN_NOT_YET_VALID = 'bbb.id_token.not_yet_valid';

    public const ISS_MISMATCH = 'bbb.id_token.iss_mismatch';

    public const AUD_MISMATCH = 'bbb.id_token.aud_mismatch';

    public const MISSING_CLAIM = 'bbb.id_token.missing_claim';

    public const NONCE_MISMATCH = 'bbb.id_token.nonce_mismatch';

    public const JTI_REPLAYED = 'bbb.id_token.jti_replayed';

    // ── Contrat métier v1 ────────────────────────────────────────────────

    /**
     * Claim `role` absent ou hors du vocabulaire FERMÉ
     * `prof|eleve|administratif|admin`.
     *
     * Le contrat 55.2 est explicite : « non résoluble ⇒ clé ABSENTE » — jamais
     * `null`, jamais `""`, jamais `"autre"`. Il n'existe donc aucun cas où une
     * valeur inconnue mériterait un repli : l'extension REFUSE, sans ouvrir de
     * session, et surtout sans retomber sur un rôle privilégié.
     */
    public const ROLE_UNSUPPORTED = 'bbb.claims.role_unsupported';
}
