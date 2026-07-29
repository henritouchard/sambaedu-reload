<?php

declare(strict_types=1);

namespace App\OidcWitness\Support;

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
     * Écrit le fichier en 0600. Le dossier est créé si nécessaire.
     *
     * ⚠️ Ne retourne rien et n'écrit RIEN au journal : le secret ne doit pas
     * transiter par un canal de plus que le fichier lui-même.
     */
    public function write(): void
    {
        $path = self::path();
        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Création du dossier de credentials du témoin impossible : ' . $dir);
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
