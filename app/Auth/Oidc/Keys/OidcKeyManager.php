<?php

declare(strict_types=1);

namespace App\Auth\Oidc\Keys;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Story 55.1 — Gestion de la paire de signature RS256 **dédiée à OIDC**.
 *
 * Trois responsabilités, et rien d'autre :
 *
 *  1. **Génération idempotente** de la paire (`initIfMissing()` / `forceRegen()`),
 *     appelée par `php artisan oidc:keys:init` — fonctions `openssl_*` NATIVES,
 *     zéro shell (patron {@see \App\Auth\V1\Pki\CaInitializer}).
 *  2. **Lecture** de la clé privée (signature) et publique (JWKS), avec le
 *     garde-fou « pas de fixture de test en production » appliqué
 *     SYMÉTRIQUEMENT aux deux — calque `WorkstationJwtIssuer::loadPrivateKey()`
 *     ET `WorkstationJwtVerifier::buildKeyMap()`.
 *  3. **Export JWKS** (RFC 7517) : `kty`/`n`/`e`/`kid`/`use`/`alg`, base64url
 *     sans padding.
 *
 * **Pourquoi une paire DÉDIÉE et pas celle d'`auth_v1`** : la clé workstation
 * n'est publiée nulle part ; le JWKS OIDC est PUBLIC. Publier la clé
 * workstation élargirait sa surface pour rien, et les rotations des deux mondes
 * sont indépendantes.
 *
 * **Fail-closed** : clé absente ⇒ `RuntimeException` explicite mentionnant
 * `php artisan oidc:keys:init`. JAMAIS d'émission dégradée (pas de repli sur
 * une autre clé, pas de génération à la volée dans un chemin web).
 *
 * **Permissions** : dossier 0700, clé privée 0600, clé publique 0644. Le
 * fichier privé est écrit sous `umask(0077)` pour qu'il n'existe jamais, même
 * quelques µs, avec des permissions plus larges.
 *
 * Aucun secret n'est jamais loggé (channel `oidc`, `action_type` `oidc.keys.*`).
 */
class OidcKeyManager
{
    /** Mode du dossier contenant la clé privée (rwx propriétaire seul). */
    private const DIR_MODE = 0700;

    /** Mode du fichier de clé privée. */
    private const PRIVATE_KEY_MODE = 0600;

    /** Mode du fichier de clé publique (lisible — elle est publiée au JWKS). */
    private const PUBLIC_MODE = 0644;

    /** Identifiant de la clé active (header `kid` du JWT + entrée du JWKS). */
    public function activeKid(): string
    {
        $kid = (string) config('oidc.active_kid', '');
        if ($kid === '') {
            throw new RuntimeException('oidc.active_kid non configuré (config/oidc.php)');
        }

        return $kid;
    }

    public function privateKeyPath(?string $kid = null): string
    {
        return $this->keyPath('private', $kid);
    }

    public function publicKeyPath(?string $kid = null): string
    {
        return $this->keyPath('public', $kid);
    }

    /** La paire de la clé ACTIVE est-elle présente sur le disque ? */
    public function isInitialized(): bool
    {
        return is_file($this->privateKeyPath()) && is_file($this->publicKeyPath());
    }

    /**
     * Génère la paire si elle est absente. IDEMPOTENT : une paire déjà présente
     * n'est jamais écrasée (écraser invaliderait silencieusement tous les
     * id_tokens en circulation et toutes les intégrations d'extensions).
     *
     * @return array{status: 'initialized'|'already_initialized', kid: string, files: array<string, string>}
     */
    public function initIfMissing(): array
    {
        $kid = $this->activeKid();

        if ($this->isInitialized()) {
            $this->logEvent('oidc.keys.init.skipped', ['kid' => $kid, 'reason' => 'already_initialized']);

            return [
                'status' => 'already_initialized',
                'kid' => $kid,
                'files' => $this->managedFiles(),
            ];
        }

        $this->logEvent('oidc.keys.init.start', ['kid' => $kid, 'mode' => 'init_if_missing']);
        $this->generateKeypair();
        $this->logEvent('oidc.keys.init.success', ['kid' => $kid]);

        return [
            'status' => 'initialized',
            'kid' => $kid,
            'files' => $this->managedFiles(),
        ];
    }

    /**
     * Régénère la paire. Les fichiers existants sont SAUVEGARDÉS
     * (`.bak-YYYYMMDDHHMMSS`) — patron `CaInitializer::forceRegen()`.
     *
     * ⚠️ Tous les id_tokens signés par l'ancienne clé deviennent invérifiables
     * dès que les clients rafraîchissent le JWKS.
     *
     * @return array{status: 'force_regenerated', kid: string, files: array<string, string>}
     */
    public function forceRegen(): array
    {
        $kid = $this->activeKid();
        $this->logEvent('oidc.keys.init.start', ['kid' => $kid, 'mode' => 'force']);

        $stamp = date('YmdHis');
        $this->backupIfExists($this->privateKeyPath(), $stamp);
        $this->backupIfExists($this->publicKeyPath(), $stamp);

        $this->generateKeypair();
        $this->logEvent('oidc.keys.init.success', ['kid' => $kid, 'mode' => 'force']);

        return [
            'status' => 'force_regenerated',
            'kid' => $kid,
            'files' => $this->managedFiles(),
        ];
    }

    /**
     * Contenu PEM de la clé PRIVÉE active — utilisé UNIQUEMENT par
     * {@see \App\Auth\Oidc\Jwt\OidcIdTokenIssuer} pour signer.
     *
     * @throws RuntimeException Si la clé est absente, illisible, ou si c'est une
     *                          fixture de test dans un environnement réel.
     */
    public function loadPrivateKey(): string
    {
        return $this->readKey($this->privateKeyPath(), 'privée');
    }

    /** Contenu PEM de la clé PUBLIQUE active (JWKS, vérification locale). */
    public function loadPublicKey(): string
    {
        return $this->readKey($this->publicKeyPath(), 'publique');
    }

    /**
     * JWKS (RFC 7517) de la clé active.
     *
     * @return array{keys: list<array<string, string>>}
     *
     * @throws RuntimeException Si la clé publique est absente ou illisible.
     */
    public function jwks(): array
    {
        $pem = $this->loadPublicKey();

        $resource = openssl_pkey_get_public($pem);
        if ($resource === false) {
            throw new RuntimeException('Clé publique OIDC illisible (PEM invalide) : ' . $this->publicKeyPath());
        }

        $details = openssl_pkey_get_details($resource);
        if ($details === false || ! isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new RuntimeException('Clé publique OIDC non-RSA ou détails indisponibles');
        }

        return [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'kid' => $this->activeKid(),
                    'n' => self::base64UrlEncode((string) $details['rsa']['n']),
                    'e' => self::base64UrlEncode((string) $details['rsa']['e']),
                ],
            ],
        ];
    }

    /**
     * Chemins gérés par la commande (affichés dans son rapport).
     *
     * @return array<string, string>
     */
    public function managedFiles(): array
    {
        return [
            'private' => $this->privateKeyPath(),
            'public' => $this->publicKeyPath(),
        ];
    }

    /** Encodage base64url SANS padding (RFC 7515 §2). */
    public static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    // =========================================================================
    // Interne
    // =========================================================================

    private function keyPath(string $which, ?string $kid = null): string
    {
        $kid ??= $this->activeKid();
        $path = (string) config('oidc.keys.' . $kid . '.' . $which, '');

        if ($path === '') {
            throw new RuntimeException(
                'Chemin de clé OIDC ' . $which . ' non configuré pour kid=' . $kid
                . ' (config/oidc.php — clé `keys`)'
            );
        }

        return $path;
    }

    /**
     * Lit un fichier de clé en appliquant le garde-fou fixtures-en-prod.
     */
    private function readKey(string $path, string $label): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(
                'Clé OIDC ' . $label . ' introuvable ou illisible : ' . $path
                . ' — lancer `php artisan oidc:keys:init`'
            );
        }

        if ($this->blocksTestFixtures() && str_contains($path, '/tests/fixtures/')) {
            Log::channel('oidc')->critical('[OidcKeyManager] SECURITY : fixture de test référencée hors testing', [
                'action_type' => 'oidc.keys.test_fixture_blocked',
                'path' => $path,
                'env' => app()->environment(),
            ]);

            throw new RuntimeException(
                'SÉCURITÉ : la clé OIDC ' . $label . ' pointe sur une fixture de test '
                . 'dans un environnement non-testing : ' . $path
            );
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Lecture impossible de la clé OIDC ' . $label . ' : ' . $path);
        }

        return $contents;
    }

    private function blocksTestFixtures(): bool
    {
        if (! (bool) config('oidc.safety.forbid_test_keys_in_production', true)) {
            return false;
        }

        $env = app()->environment();

        return $env !== 'testing' && $env !== 'local';
    }

    private function generateKeypair(): void
    {
        $bits = (int) config('oidc.key_bits', 2048);

        $privateKey = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($privateKey === false) {
            throw new RuntimeException('openssl_pkey_new a échoué (OIDC) : ' . $this->lastOpensslError());
        }

        $privatePem = '';
        if (! openssl_pkey_export($privateKey, $privatePem)) {
            throw new RuntimeException('openssl_pkey_export a échoué (OIDC) : ' . $this->lastOpensslError());
        }

        $details = openssl_pkey_get_details($privateKey);
        if ($details === false || ! isset($details['key'])) {
            throw new RuntimeException('openssl_pkey_get_details a échoué (OIDC) : ' . $this->lastOpensslError());
        }

        $this->writeSecureFile($this->privateKeyPath(), $privatePem, self::PRIVATE_KEY_MODE);
        $this->writeSecureFile($this->publicKeyPath(), (string) $details['key'], self::PUBLIC_MODE);
    }

    private function writeSecureFile(string $path, string $contents, int $mode): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (! @mkdir($dir, self::DIR_MODE, true) && ! is_dir($dir)) {
                throw new RuntimeException('Création du dossier de clés impossible : ' . $dir);
            }
            @chmod($dir, self::DIR_MODE);
            $this->applyWebOwnership($dir);
        }

        // Fichier privé : umask AVANT l'écriture — pas de fenêtre pendant
        // laquelle la clé serait lisible par d'autres process locaux.
        $isPrivate = ($mode & 0077) === 0;
        $previousUmask = $isPrivate ? umask(0077) : null;

        try {
            if (file_put_contents($path, $contents) === false) {
                throw new RuntimeException('Écriture impossible : ' . $path);
            }
            if (! @chmod($path, $mode)) {
                Log::channel('oidc')->warning('[OidcKeyManager] chmod échoué', [
                    'action_type' => 'oidc.keys.chmod_failed',
                    'path' => $path,
                    'mode' => sprintf('%04o', $mode),
                ]);
            }
        } finally {
            if ($previousUmask !== null) {
                umask($previousUmask);
            }
        }

        $this->applyWebOwnership($path);
    }

    /**
     * Aligne la propriété sur l'utilisateur runtime du serveur web. Sans cela,
     * une commande lancée en root produit une clé 0600 root:root que PHP-FPM ne
     * peut pas lire (iso `CaInitializer::applyWebOwnership`).
     */
    private function applyWebOwnership(string $path): void
    {
        $webOwner = (string) config('oidc.web_owner', '');
        if ($webOwner === '' || app()->environment('testing') || ! function_exists('posix_getpwnam')) {
            return;
        }

        $info = @posix_getpwnam($webOwner);
        if ($info === false) {
            Log::channel('oidc')->warning('[OidcKeyManager] web_owner inconnu — chown ignoré', [
                'action_type' => 'oidc.keys.chown_skipped',
                'web_owner' => $webOwner,
                'path' => $path,
            ]);

            return;
        }

        if (! @chown($path, $info['uid']) || ! @chgrp($path, $info['gid'])) {
            Log::channel('oidc')->warning('[OidcKeyManager] chown vers web_owner échoué', [
                'action_type' => 'oidc.keys.chown_failed',
                'web_owner' => $webOwner,
                'path' => $path,
            ]);
        }
    }

    private function backupIfExists(string $path, string $stamp): void
    {
        if ($path === '' || ! is_file($path)) {
            return;
        }

        $backup = $path . '.bak-' . $stamp;
        if (@rename($path, $backup)) {
            Log::channel('oidc')->info('[OidcKeyManager] sauvegarde avant régénération', [
                'action_type' => 'oidc.keys.backed_up',
                'original' => $path,
                'backup' => $backup,
            ]);
        }
    }

    private function lastOpensslError(): string
    {
        $errors = [];
        while (($e = openssl_error_string()) !== false) {
            $errors[] = $e;
        }

        return implode(' | ', $errors) ?: 'erreur openssl inconnue';
    }

    /** @param array<string, mixed> $context */
    private function logEvent(string $actionType, array $context): void
    {
        Log::channel('oidc')->info('[OidcKeyManager] ' . $actionType, array_merge([
            'action_type' => $actionType,
        ], $context));
    }
}
