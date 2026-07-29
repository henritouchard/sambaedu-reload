<?php

declare(strict_types=1);

namespace App\OidcWitness\Support;

use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;

/**
 * Story 55.3 — La configuration que le témoin a REÇUE, et sa seule source.
 *
 * Un `client_id`, un `client_secret`, un `issuer`, une `redirect_uri` : c'est
 * tout ce qu'une extension possède du fournisseur. Elle ne va PAS chercher son
 * client dans `oidc_clients` (FR24) — elle lit le fichier que l'opérateur lui a
 * posé, exactement comme l'Epic 56 le posera automatiquement à l'installation
 * d'une extension `app`.
 *
 * **Fail-closed** : fichier absent, illisible, JSON invalide ou champ manquant
 * ⇒ `null`. Le témoin affiche alors une erreur EXPLICITE (503), jamais un
 * contournement, jamais une valeur devinée depuis la configuration du serveur.
 *
 * **NFR3** : le fichier est écrit en 0600 sous `umask(0077)` (aucune fenêtre
 * pendant laquelle le secret serait lisible par un autre processus local), et
 * le secret n'est ni affiché, ni journalisé, ni sérialisé ailleurs.
 *
 * ⚠️ **0600 impose de fixer le PROPRIÉTAIRE** : `oidc:witness:enable` est une
 * commande d'exploitation, donc lancée en root neuf fois sur dix. Un fichier
 * 0600 `root:root` est ILLISIBLE par PHP-FPM (`www-admin`) — `load()` renvoie
 * `null` et le témoin part en 503 `witness.credentials_unreadable`, alors que
 * la commande vient d'annoncer un succès. C'est un incident CONSTATÉ, pas une
 * hypothèse. La propriété est donc alignée à l'écriture, patron littéral de
 * `OidcKeyManager::applyWebOwnership()` / `CaInitializer::applyWebOwnership()`.
 */
final class WitnessCredentials
{
    /** Champs obligatoires du fichier — la liste EST le contrat. */
    private const REQUIRED = ['client_id', 'client_secret', 'issuer', 'redirect_uri'];

    public function __construct(
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $issuer,
        public readonly string $redirectUri,
    ) {
    }

    public static function path(): string
    {
        $path = (string) config('oidc.witness.credentials_path', '');

        if ($path === '') {
            throw new RuntimeException('oidc.witness.credentials_path non configuré (config/oidc.php)');
        }

        return $path;
    }

    public static function isProvisioned(): bool
    {
        return is_file(self::path());
    }

    /**
     * Charge les credentials. `null` ⇒ non provisionné OU fichier inexploitable
     * — les deux mènent au même refus explicite côté témoin ; la distinction
     * n'a de valeur que pour l'exploitant, elle est portée par les commandes.
     */
    public static function load(): ?self
    {
        $path = self::path();

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        foreach (self::REQUIRED as $field) {
            if (! isset($decoded[$field]) || ! is_string($decoded[$field]) || trim($decoded[$field]) === '') {
                return null;
            }
        }

        return new self(
            clientId: trim((string) $decoded['client_id']),
            clientSecret: trim((string) $decoded['client_secret']),
            issuer: rtrim(trim((string) $decoded['issuer']), '/'),
            redirectUri: trim((string) $decoded['redirect_uri']),
        );
    }

    /**
     * Écrit le fichier en 0600, propriété alignée sur le runtime web. Le
     * dossier est créé si nécessaire.
     *
     * ⚠️ Le SECRET ne transite par aucun canal de plus que le fichier
     * lui-même. Seuls les échecs d'alignement de propriété sont journalisés
     * (chemin + owner attendu, jamais le contenu) : un chown silencieux qui
     * échoue redonnerait exactement le 503 opaque que ce code corrige.
     */
    public function write(): void
    {
        $path = self::path();
        $dir = dirname($path);

        // Le dossier créé ici hérite lui aussi de l'identité de l'appelant :
        // un `storage/app/` 0700 root:root rendrait le fichier inatteignable
        // même correctement chowné (traversée impossible). On l'aligne donc
        // dès qu'on est celui qui l'a créé.
        $createdDir = false;

        if (! is_dir($dir)) {
            $createdDir = @mkdir($dir, 0700, true);

            if (! $createdDir && ! is_dir($dir)) {
                throw new RuntimeException('Création du dossier de credentials du témoin impossible : ' . $dir);
            }
        }

        $payload = json_encode([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'issuer' => $this->issuer,
            'redirect_uri' => $this->redirectUri,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException('Sérialisation des credentials du témoin impossible');
        }

        // umask AVANT l'écriture — patron OidcKeyManager::writeSecureFile().
        $previousUmask = umask(0077);

        try {
            if (file_put_contents($path, $payload . "\n") === false) {
                throw new RuntimeException('Écriture des credentials du témoin impossible : ' . $path);
            }
            @chmod($path, 0600);
        } finally {
            umask($previousUmask);
        }

        if ($createdDir) {
            self::applyWebOwnership($dir);
        }

        self::applyWebOwnership($path);
    }

    /**
     * Aligne la propriété sur l'utilisateur runtime du serveur web
     * (`oidc.web_owner` — même notion, même clé que les clés du fournisseur :
     * il n'y a qu'UN runtime web sur l'instance).
     *
     * No-op silencieux quand la question ne se pose pas : owner non configuré,
     * environnement de test, ou posix indisponible (dev macOS/Windows). No-op
     * BRUYANT quand elle se pose et qu'on échoue — un `chown` raté en root est
     * précisément le cas qui casse le témoin sans rien laisser voir.
     *
     * Lancée en `www-admin`, la commande passe ici avec un fichier DÉJÀ au bon
     * propriétaire : `chown` vers soi-même réussit, aucun bruit.
     */
    private static function applyWebOwnership(string $path): void
    {
        $webOwner = (string) config('oidc.web_owner', '');

        // ⚠️ `config('app.env')` et NON le helper d'environnement du
        // conteneur employé par le patron copié : la quarantaine FR24 interdit
        // toute résolution par le conteneur depuis `app/OidcWitness/` (règle
        // « résolution par le conteneur », `ExtensionIsolationTest`) — et elle
        // scanne le TEXTE, commentaires compris, donc le nom même de ce helper
        // ne peut pas figurer ici. Même sémantique, sans atteindre le
        // conteneur de SE5.
        if ($webOwner === '' || (string) config('app.env') === 'testing' || ! function_exists('posix_getpwnam')) {
            return;
        }

        $info = @posix_getpwnam($webOwner);

        if ($info === false) {
            Log::channel('oidc')->warning('[WitnessCredentials] web_owner inconnu — chown ignoré', [
                'action_type' => 'oidc.witness.chown_skipped',
                'web_owner' => $webOwner,
                'path' => $path,
            ]);

            return;
        }

        if (! @chown($path, $info['uid']) || ! @chgrp($path, $info['gid'])) {
            Log::channel('oidc')->warning('[WitnessCredentials] chown vers web_owner échoué', [
                'action_type' => 'oidc.witness.chown_failed',
                'web_owner' => $webOwner,
                'path' => $path,
            ]);
        }
    }

    /** Supprime le fichier. `false` ⇒ il n'existait pas (no-op). */
    public static function forget(): bool
    {
        $path = self::path();

        if (! is_file($path)) {
            return false;
        }

        return @unlink($path);
    }
}
