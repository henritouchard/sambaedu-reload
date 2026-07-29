<?php

declare(strict_types=1);

namespace App\Services\Extensions;

use SodiumException;

/**
 * Story 56.1 (NFR2) — Vérification de la **signature détachée Ed25519** d'un
 * catalogue distant.
 *
 * Service **PUR** : ni filesystem, ni base, ni HTTP, ni journalisation. Il
 * reçoit trois chaînes et rend un booléen. C'est ce qui le rend exhaustivement
 * testable (paires fabriquées à la volée par `sodium_crypto_sign_keypair()` —
 * aucune fixture binaire n'est commitée dans le dépôt).
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  POURQUOI Ed25519 / libsodium — ET PAS RSA
 *
 *  1. **Zéro dépendance nouvelle** : sodium est compilé dans le core PHP depuis
 *     7.2 et activé par défaut (paquets Debian `phpX.Y-common`). La dépendance
 *     est néanmoins EXPLICITÉE dans `composer.json` (`"ext-sodium": "*"`,
 *     comme `ext-apcu`) : une dépendance implicite est une panne différée.
 *  2. **Format trivial et non ambigu** : clé publique 32 octets, signature
 *     64 octets, un `base64_decode` strict. Aucun PEM, aucun ASN.1, aucun
 *     padding à parser — et surtout **aucun algorithme négociable** : la classe
 *     de vulnérabilités « `alg: none` / confusion d'algorithme » n'existe pas
 *     ici PAR CONSTRUCTION. Il n'y a rien à replier.
 *  3. **Côté éditeur tiers** : signer un dépôt tient en trois lignes de PHP
 *     (`sodium_crypto_sign_keypair` + `sodium_crypto_sign_detached`), ou en un
 *     `minisign`/`signify`. C'est le patron standard des dépôts statiques
 *     signés (l'outillage de publication est la Story 58.2).
 *  4. **Pourquoi PAS la paire RSA du fournisseur OIDC** ({@see \App\Auth\Oidc\Keys\OidcKeyManager}) :
 *     domaine de confiance DIFFÉRENT. Ici la clé appartient à la **source**
 *     (chaque éditeur a la sienne, SE5 ne fait que la pinner) ; là-bas elle
 *     appartient à l'instance SE5 qui signe ses propres jetons. Mélanger les
 *     deux infrastructures mélangerait deux contrats et rendrait une rotation
 *     de clé OIDC capable de casser la vérification des catalogues.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Fail-closed sans exception** : toute anomalie (base64 invalide, longueur
 * inattendue, chaîne vide, extension sodium absente, exception interne) rend
 * `false`. Jamais une 500, jamais un « on tente quand même ». L'appelant
 * ({@see RemoteCatalogSyncService}) traduit ce `false` en `sync_status = error`
 * et n'interprète RIEN du contenu.
 *
 * ⚠️ Les octets vérifiés doivent être les octets **verbatim** téléchargés —
 * jamais un JSON re-sérialisé, jamais une chaîne « nettoyée ». La signature
 * couvre des octets, pas une structure.
 */
class CatalogSignatureVerifier
{
    /** Longueur d'une clé publique Ed25519, en octets (`SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES`). */
    public const PUBLIC_KEY_BYTES = 32;

    /** Longueur d'une signature détachée Ed25519, en octets (`SODIUM_CRYPTO_SIGN_BYTES`). */
    public const SIGNATURE_BYTES = 64;

    /**
     * La signature détachée `$signatureB64` couvre-t-elle bien `$indexBytes`
     * pour la clé publique pinnée `$publicKeyB64` ?
     *
     * @param  string  $indexBytes    octets EXACTS du fichier téléchargé (jamais re-sérialisés)
     * @param  string  $signatureB64  contenu de `index.json.sig` (base64, blancs tolérés autour)
     * @param  string  $publicKeyB64  clé PINNÉE en base (base64), jamais re-téléchargée
     */
    public function verify(string $indexBytes, string $signatureB64, string $publicKeyB64): bool
    {
        $signature = self::decodeFixedLength($signatureB64, self::SIGNATURE_BYTES);
        $publicKey = self::decodeFixedLength($publicKeyB64, self::PUBLIC_KEY_BYTES);

        if ($signature === null || $publicKey === null) {
            return false;
        }

        // Défense en profondeur : une instance PHP compilée sans sodium ne doit
        // pas produire une erreur fatale mais un refus — le catalogue passera
        // simplement en `error`, comportement fail-closed attendu.
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $indexBytes, $publicKey);
        } catch (SodiumException) {
            // Longueurs déjà validées plus haut : ce chemin ne devrait pas être
            // atteint. S'il l'est (build exotique), le refus reste la seule
            // réponse acceptable — surtout pas une 500 sur une page admin.
            return false;
        }
    }

    /**
     * `$publicKeyB64` est-il une clé publique Ed25519 base64 exploitable ?
     *
     * Utilisé à l'AJOUT d'une source ({@see ExtensionSourceService::add()}) —
     * qu'elle soit collée par l'admin ou lue sur `<url>/source.pub` — pour
     * refuser tout de suite une clé qui ne pourra jamais vérifier quoi que ce
     * soit, plutôt que de créer une source condamnée à l'erreur.
     */
    public static function isValidPublicKey(string $publicKeyB64): bool
    {
        return self::decodeFixedLength($publicKeyB64, self::PUBLIC_KEY_BYTES) !== null;
    }

    /**
     * Décodage base64 **STRICT** d'une valeur de longueur ATTENDUE.
     *
     * - `base64_decode(..., true)` : mode strict — un caractère hors alphabet
     *   base64 rend `false` au lieu d'être « silencieusement ignoré ». Sans ce
     *   `true`, `"!!!!"` décoderait en chaîne vide au lieu d'être refusé.
     * - `trim()` : un fichier `.sig` ou `.pub` publié par un dépôt statique
     *   finit presque toujours par un `\n` (tout éditeur en ajoute un). Tolérer
     *   les blancs AUTOUR est nécessaire ; tolérer un caractère invalide DEDANS
     *   ne l'est pas.
     * - Longueur vérifiée : une signature de 63 octets ou une clé de 31 n'est
     *   pas « presque bonne », elle est invalide. La contrôler ici évite de
     *   confier à libsodium une entrée hors contrat.
     *
     * @return string|null  les octets décodés, ou `null` si l'entrée n'est pas
     *                      exactement `$expectedBytes` octets de base64 valide
     */
    private static function decodeFixedLength(string $value, int $expectedBytes): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $raw = base64_decode($trimmed, true);
        if ($raw === false) {
            return null;
        }

        return strlen($raw) === $expectedBytes ? $raw : null;
    }
}
