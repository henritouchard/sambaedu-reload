<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb;

use RuntimeException;

/**
 * Story 57.1 — **LE SEUL CANAL DE CONFIGURATION SE5 → EXTENSION.**
 *
 * Une extension SE5 ne connaît l'instance qui l'héberge que par SEPT variables
 * d'environnement, posées 0600 root:root par le helper root dans
 * `/etc/sambaedu/extensions/bbb.env` et lues par systemd AVANT le renoncement
 * aux privilèges :
 *
 * ```
 * SE5_EXT_KEY=bbb
 * SE5_EXT_BASE_PATH=/ext/bbb
 * SE5_EXT_PORT=<8600-8699, alloué par SE5 — jamais déclaré par l'extension>
 * SE5_OIDC_ISSUER=<sans slash final>
 * SE5_OIDC_CLIENT_ID=<hex>
 * SE5_OIDC_CLIENT_SECRET=<hex>
 * SE5_OIDC_REDIRECT_URI=<un CHEMIN, ex. /ext/bbb/oidc/callback>
 * ```
 *
 * Plus une huitième variable, posée par **systemd** et non par SE5 :
 * `STATE_DIRECTORY` (directive `StateDirectory=` de l'unité). C'est le seul
 * répertoire inscriptible de l'extension.
 *
 * ⚠️ **`SE5_EXT_BASE_PATH` peut être vide** (développement hors proxy, où l'on
 * sert directement `php -S`). Rien dans le code ne doit supposer le contraire —
 * voir {@see Url}.
 *
 * ⚠️ **`SE5_OIDC_REDIRECT_URI` est un CHEMIN, pas une URL absolue.** Il se
 * répète VERBATIM à l'autorisation et à l'échange du code : le fournisseur
 * compare en égalité stricte. Ne jamais le reconstruire, ne jamais le rendre
 * absolu.
 */
final class Env
{
    /** Repli de développement lorsque `STATE_DIRECTORY` est absent. */
    private const DEV_STATE_DIR = '/var';

    public function __construct(
        public readonly string $key,
        public readonly string $basePath,
        public readonly int $port,
        public readonly string $issuer,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $redirectUri,
        public readonly string $stateDirectory,
    ) {
    }

    /**
     * Lit l'environnement du processus.
     *
     * Aucune variable n'est OBLIGATOIRE ici, et c'est délibéré : `GET /` est la
     * sonde de santé de l'extension (elle doit répondre même mal configurée —
     * voir {@see Http\Router}). Les manques se signalent au moment où ils
     * comptent : `hasOidc()` garde le parcours de connexion.
     *
     * @param  array<string, mixed>|null  $source  Injection pour les tests ;
     *                                             l'environnement réel par défaut.
     */
    public static function capture(?array $source = null): self
    {
        $read = static function (string $name) use ($source): string {
            if ($source !== null) {
                return isset($source[$name]) && is_scalar($source[$name]) ? trim((string) $source[$name]) : '';
            }

            $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

            return is_scalar($value) && $value !== false ? trim((string) $value) : '';
        };

        $basePath = rtrim($read('SE5_EXT_BASE_PATH'), '/');
        if ($basePath !== '' && ! str_starts_with($basePath, '/')) {
            $basePath = '/' . $basePath;
        }

        return new self(
            key: $read('SE5_EXT_KEY') !== '' ? $read('SE5_EXT_KEY') : 'bbb',
            basePath: $basePath,
            port: (int) $read('SE5_EXT_PORT'),
            issuer: rtrim($read('SE5_OIDC_ISSUER'), '/'),
            clientId: $read('SE5_OIDC_CLIENT_ID'),
            clientSecret: $read('SE5_OIDC_CLIENT_SECRET'),
            // VERBATIM : ni rtrim, ni normalisation, ni préfixage.
            redirectUri: $read('SE5_OIDC_REDIRECT_URI'),
            stateDirectory: self::resolveStateDirectory($read('STATE_DIRECTORY')),
        );
    }

    /**
     * Le contrat OIDC est-il complet ? Une extension non encore installée par le
     * canal standard n'a pas de credentials : le parcours de connexion doit le
     * dire, pas planter.
     */
    public function hasOidc(): bool
    {
        return $this->issuer !== ''
            && $this->clientId !== ''
            && $this->clientSecret !== ''
            && $this->redirectUri !== '';
    }

    /** Chemin du fichier SQLite de l'extension. */
    public function databasePath(): string
    {
        return $this->stateDirectory . '/database.sqlite';
    }

    /**
     * `StateDirectory=` de systemd peut valoir une LISTE séparée par `:` : on
     * ne retient que la première entrée. Absente (développement hors systemd),
     * on retombe sur `extensions/bbb/var/`, créé 0700 à la demande.
     */
    private static function resolveStateDirectory(string $raw): string
    {
        $first = $raw === '' ? '' : trim(explode(':', $raw)[0]);

        if ($first !== '') {
            return rtrim($first, '/');
        }

        $fallback = dirname(__DIR__) . self::DEV_STATE_DIR;

        if (! is_dir($fallback) && ! @mkdir($fallback, 0700, true) && ! is_dir($fallback)) {
            throw new RuntimeException('Répertoire d\'état inaccessible : ' . $fallback);
        }

        return $fallback;
    }
}
