<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Oidc;

use Throwable;

/**
 * Story 57.1 — La découverte du fournisseur, vue d'un client honnête.
 *
 * L'extension ne connaît qu'une chose de SE5 : son `issuer`. Tout le reste —
 * l'URL d'autorisation, celle du token endpoint, celle du JWKS — se DÉCOUVRE par
 * HTTP à `{issuer}/.well-known/openid-configuration`. Aucun chemin `/oidc/...`
 * n'est écrit en dur : le jour où le fournisseur serait remplacé (Keycloak,
 * NFR12), l'extension suivrait sans être modifiée.
 *
 * **Contrôle du contrat, pas seulement de la disponibilité** : l'`issuer`
 * ANNONCÉ par le document doit être celui qu'on est allé interroger. Un document
 * qui prétend parler pour un autre émetteur est refusé.
 *
 * **Cache court sur disque** (`STATE_DIRECTORY`, TTL 5 min), pour deux raisons :
 * le serveur intégré de PHP ne partage rien entre requêtes, et la découverte
 * suivie du JWKS ferait deux appels réseau à chaque connexion. Le cache est un
 * cache : son absence, sa corruption ou son expiration n'ont d'autre effet qu'un
 * appel réseau de plus.
 *
 * ⚠️ **`GET /` n'appelle jamais cette classe** — la racine est la sonde de santé
 * (contrat §8) et ne doit dépendre d'aucun tiers.
 */
final class ProviderMetadata
{
    public const CACHE_TTL_SECONDS = 300;

    /** @var array<string, array<string, mixed>> */
    private array $discoveryMemo = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $jwksMemo = [];

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly ?string $cacheFile = null,
    ) {
    }

    /**
     * Document de découverte de l'`issuer` donné.
     *
     * @return array<string, mixed>
     *
     * @throws OidcException
     */
    public function discovery(string $issuer): array
    {
        $issuer = rtrim($issuer, '/');

        if (isset($this->discoveryMemo[$issuer])) {
            return $this->discoveryMemo[$issuer];
        }

        $cacheKey = 'discovery:' . $issuer;
        $cached = $this->readCache($cacheKey);

        if ($cached !== null) {
            return $this->discoveryMemo[$issuer] = $cached;
        }

        $document = $this->http->getJson($issuer . '/.well-known/openid-configuration');

        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $required) {
            if (! isset($document[$required]) || ! is_string($document[$required]) || $document[$required] === '') {
                throw OidcException::of(
                    ErrorCodes::DISCOVERY_UNAVAILABLE,
                    'découverte incomplète : champ « ' . $required . ' » absent',
                );
            }
        }

        if (rtrim((string) $document['issuer'], '/') !== $issuer) {
            throw OidcException::of(
                ErrorCodes::DISCOVERY_UNAVAILABLE,
                'découverte incohérente : l\'issuer annoncé ne correspond pas à celui interrogé',
            );
        }

        $this->writeCache($cacheKey, $document);

        return $this->discoveryMemo[$issuer] = $document;
    }

    /**
     * Les clés publiées par le JWKS, telles quelles (RFC 7517). Leur conversion
     * en clés vérifiables appartient au vérificateur.
     *
     * @return list<array<string, mixed>>
     *
     * @throws OidcException
     */
    public function jwks(string $jwksUri): array
    {
        if (isset($this->jwksMemo[$jwksUri])) {
            return $this->jwksMemo[$jwksUri];
        }

        $cacheKey = 'jwks:' . $jwksUri;
        $cached = $this->readCache($cacheKey);

        if ($cached !== null && isset($cached['keys']) && is_array($cached['keys'])) {
            return $this->jwksMemo[$jwksUri] = self::normalizeKeys($cached['keys']);
        }

        $document = $this->http->getJson($jwksUri);
        $keys = $document['keys'] ?? null;

        if (! is_array($keys) || $keys === []) {
            // Fail-closed : un JWKS vide n'autorise RIEN. Le laisser passer
            // reviendrait à accepter un jeton qu'on n'a pas su vérifier.
            throw OidcException::of(ErrorCodes::JWKS_UNUSABLE, 'JWKS vide ou malformé');
        }

        $this->writeCache($cacheKey, ['keys' => $keys]);

        return $this->jwksMemo[$jwksUri] = self::normalizeKeys($keys);
    }

    // =====================================================================

    /**
     * @param  array<mixed>  $keys
     * @return list<array<string, mixed>>
     */
    private static function normalizeKeys(array $keys): array
    {
        $normalized = [];

        foreach ($keys as $key) {
            if (is_array($key)) {
                /** @var array<string, mixed> $key */
                $normalized[] = $key;
            }
        }

        return $normalized;
    }

    /** @return array<string, mixed>|null */
    private function readCache(string $key): ?array
    {
        if ($this->cacheFile === null || ! is_file($this->cacheFile)) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode((string) @file_get_contents($this->cacheFile), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decoded) || ! isset($decoded[$key]) || ! is_array($decoded[$key])) {
            return null;
        }

        $entry = $decoded[$key];
        $fetchedAt = isset($entry['fetched_at']) && is_numeric($entry['fetched_at']) ? (int) $entry['fetched_at'] : 0;

        if ($fetchedAt <= 0 || $fetchedAt + self::CACHE_TTL_SECONDS < time() || ! isset($entry['document'])) {
            return null;
        }

        return is_array($entry['document']) ? $entry['document'] : null;
    }

    /** @param array<string, mixed> $document */
    private function writeCache(string $key, array $document): void
    {
        if ($this->cacheFile === null) {
            return;
        }

        $current = [];

        if (is_file($this->cacheFile)) {
            try {
                /** @var mixed $decoded */
                $decoded = json_decode((string) @file_get_contents($this->cacheFile), true, 64, JSON_THROW_ON_ERROR);
                $current = is_array($decoded) ? $decoded : [];
            } catch (Throwable) {
                $current = [];
            }
        }

        $current[$key] = ['fetched_at' => time(), 'document' => $document];

        $encoded = json_encode($current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            return;
        }

        // Écriture best-effort : le cache n'est jamais une condition de
        // fonctionnement. Un répertoire d'état en lecture seule dégrade la
        // performance, il ne casse pas la connexion.
        if (@file_put_contents($this->cacheFile, $encoded, LOCK_EX) !== false) {
            @chmod($this->cacheFile, 0600);
        }
    }
}
