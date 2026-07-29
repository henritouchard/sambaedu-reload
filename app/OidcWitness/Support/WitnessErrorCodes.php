<?php

declare(strict_types=1);

namespace App\OidcWitness\Support;

/**
 * Story 55.3 — Codes d'erreur INTERNES de l'app-témoin.
 *
 * Calque du catalogue du fournisseur (`OidcErrorCodes`), mais **côté client** :
 * ces codes ne décrivent jamais ce que le fournisseur a refusé, seulement ce que
 * LE TÉMOIN a refusé d'accepter. Ils partent au journal (channel `oidc`,
 * `action_type` `oidc.witness.*`) et sont rappelés — seuls — sur la page
 * d'erreur sobre, pour qu'un exploitant puisse corréler sans qu'un visiteur
 * apprenne quoi que ce soit du registre.
 *
 * ⚠️ Aucun de ces codes ne doit jamais transporter de PII ni de valeur de
 * jeton.
 */
final class WitnessErrorCodes
{
    // ── Provisioning / transport ──────────────────────────────────────────

    /** Aucun fichier de credentials : `php artisan oidc:witness:enable`. */
    public const NOT_PROVISIONED = 'witness.not_provisioned';

    /** Fichier de credentials illisible ou incomplet. */
    public const CREDENTIALS_UNREADABLE = 'witness.credentials_unreadable';

    /** Discovery injoignable, non-JSON, ou incomplète. */
    public const DISCOVERY_UNAVAILABLE = 'witness.discovery_unavailable';

    /** JWKS injoignable, vide, ou inexploitable → aucune vérification possible. */
    public const JWKS_UNUSABLE = 'witness.jwks_unusable';

    /** L'échange du code au token endpoint a échoué (client révoqué, code brûlé…). */
    public const TOKEN_EXCHANGE_FAILED = 'witness.token_exchange_failed';

    /** Réponse du token endpoint sans `id_token`. */
    public const ID_TOKEN_MISSING = 'witness.id_token_missing';

    // ── État du témoin entre `start` et `callback` ────────────────────────

    /** Cookie d'état absent, illisible, hors délai, ou démesuré. */
    public const STATE_MISSING = 'witness.state_missing';

    /** `state` reçu ≠ `state` mémorisé — le retour ne correspond à aucun départ. */
    public const STATE_MISMATCH = 'witness.state_mismatch';

    /** Retour sans `code` (ou porteur d'une `error` OAuth du fournisseur). */
    public const CODE_MISSING = 'witness.code_missing';

    // ── Vérification de l'id_token (la suite d'attaque NFR1) ──────────────

    public const ID_TOKEN_MALFORMED = 'witness.id_token.malformed';

    /**
     * Couvre TOUT ce que la key-map RS256 pinnée ferme d'un bloc : `alg: none`,
     * confusion d'algorithme symétrique, `kid` inconnu, signature d'une clé
     * étrangère. Un seul code : de l'extérieur, ce sont quatre façons de ne pas
     * savoir signer.
     */
    public const ID_TOKEN_SIGNATURE_INVALID = 'witness.id_token.signature_invalid';

    public const ID_TOKEN_EXPIRED = 'witness.id_token.expired';

    public const ID_TOKEN_NOT_YET_VALID = 'witness.id_token.not_yet_valid';

    public const ISS_MISMATCH = 'witness.id_token.iss_mismatch';

    public const AUD_MISMATCH = 'witness.id_token.aud_mismatch';

    public const MISSING_CLAIM = 'witness.id_token.missing_claim';

    public const NONCE_MISMATCH = 'witness.id_token.nonce_mismatch';

    public const JTI_REPLAYED = 'witness.id_token.jti_replayed';
}
