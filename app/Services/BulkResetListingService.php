<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Service de stockage temporaire du listing post-bulk-reset.
 *
 * Contraintes sécurité :
 *   - le listing contient des mots de passe en clair (nécessaires au téléchargement
 *     PDF/CSV par l'opérateur), donc il ne doit JAMAIS être stocké en session PHP
 *     (cf. feedback_session_leak_tests.md) ni sur disque.
 *   - stocké chiffré at-rest via {@see Crypt::encrypt()} dans le cache (Redis
 *     en prod, array en test) avec TTL 1200 s (20 min) strict.
 *   - un seul listing actif par opérateur — toute nouvelle réinitialisation
 *     purge le listing précédent (garantie UX + surface d'exposition minimale).
 *   - accès uniquement via URL signée Laravel ({@see URL::temporarySignedRoute()}).
 */
class BulkResetListingService
{
    public const TTL_SECONDS = 1200; // 20 minutes

    private const KEY_PREFIX = 'pwd_reset_listing';
    private const OPERATOR_INDEX_PREFIX = 'pwd_reset_listing_operator';

    /**
     * Stocke le listing chiffré + retourne le token (UUID v4) à embarquer
     * dans l'URL signée. Purge systématiquement le listing précédent de
     * l'opérateur avant de stocker le nouveau.
     *
     * @param array<int, array{login: string, new_password: string, ...}> $listing
     */
    public function storeListing(int $operatorId, array $listing, array $meta = []): string
    {
        $this->purgePreviousForOperator($operatorId);

        $uuid = (string) Str::uuid();
        $cacheKey = $this->cacheKey($operatorId, $uuid);
        $payload = [
            'operator_id' => $operatorId,
            'listing' => $listing,
            'meta' => $meta,
            'created_at' => now()->toIso8601String(),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->toIso8601String(),
            'count' => count($listing),
        ];

        Cache::put($cacheKey, Crypt::encrypt(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), self::TTL_SECONDS);
        Cache::put($this->operatorIndexKey($operatorId), $uuid, self::TTL_SECONDS);
        $this->registerTokenIndex($operatorId, $uuid);

        return $uuid;
    }

    /**
     * Récupère le listing depuis un token. Le token embarque l'UUID, on
     * retrouve l'opérateur via l'index cache.
     *
     * Retourne null si :
     *   - le token n'existe pas / est invalide
     *   - la TTL a expiré
     *   - le cache a été purgé explicitement
     *
     * @return array{listing: array, meta: array, operator_id: int, created_at: string, expires_at: string, count: int}|null
     */
    public function fetchListing(string $token): ?array
    {
        // On scanne via l'index opérateur si possible, sinon fallback sur la clé directe.
        // En pratique le middleware signed valide la signature; on retrouve le listing
        // par le token (UUID unique par listing).
        $match = $this->findByToken($token);
        if ($match === null) {
            return null;
        }

        [$operatorId, $cacheKey] = $match;

        $raw = Cache::get($cacheKey);
        if ($raw === null) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decrypt($raw), true);
        } catch (\Throwable $e) {
            Log::warning('BulkResetListingService: déchiffrement impossible', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!is_array($payload) || !isset($payload['listing'])) {
            return null;
        }

        return $payload;
    }

    /**
     * Purge explicitement le listing (token) d'un opérateur.
     */
    public function purgeListing(int $operatorId, string $token): void
    {
        Cache::forget($this->cacheKey($operatorId, $token));
        Cache::forget($this->tokenIndexKey($token));

        $currentToken = Cache::get($this->operatorIndexKey($operatorId));
        if ($currentToken === $token) {
            Cache::forget($this->operatorIndexKey($operatorId));
        }
    }

    /**
     * Purge le listing précédent de l'opérateur (utilisé avant un nouveau storeListing).
     */
    public function purgePreviousForOperator(int $operatorId): void
    {
        $currentToken = Cache::get($this->operatorIndexKey($operatorId));
        if ($currentToken) {
            Cache::forget($this->cacheKey($operatorId, $currentToken));
            Cache::forget($this->tokenIndexKey($currentToken));
            Cache::forget($this->operatorIndexKey($operatorId));
        }
    }

    /**
     * Indique si l'opérateur dispose actuellement d'un listing actif non expiré.
     */
    public function hasActiveListingForOperator(int $operatorId): bool
    {
        $token = Cache::get($this->operatorIndexKey($operatorId));
        if (!$token) {
            return false;
        }

        return Cache::has($this->cacheKey($operatorId, $token));
    }

    /**
     * Métadonnées du listing actif (sans exposer les mots de passe).
     * Utilisé par le bandeau persistant /users pour afficher TTL restant + nb + liens.
     *
     * @return array{token: string, ttl_remaining: int, count: int, pdf_url: string, csv_url: string}|null
     */
    public function getActiveListingMeta(int $operatorId): ?array
    {
        $token = Cache::get($this->operatorIndexKey($operatorId));
        if (!$token) {
            return null;
        }

        $cacheKey = $this->cacheKey($operatorId, $token);
        $raw = Cache::get($cacheKey);
        if ($raw === null) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decrypt($raw), true);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($payload) || !isset($payload['expires_at'])) {
            return null;
        }

        $expiresAt = \Carbon\Carbon::parse($payload['expires_at']);
        $ttlRemaining = max(0, $expiresAt->diffInSeconds(now(), absolute: false) * -1);

        return [
            'token' => $token,
            'ttl_remaining' => (int) $ttlRemaining,
            'count' => (int) ($payload['count'] ?? 0),
            'pdf_url' => $this->buildSignedUrl($token, 'pdf', $expiresAt),
            'csv_url' => $this->buildSignedUrl($token, 'csv', $expiresAt),
        ];
    }

    /**
     * Construit l'URL signée de téléchargement pour le token courant.
     */
    public function buildSignedUrl(string $token, string $format, ?\DateTimeInterface $expiresAt = null): string
    {
        $routeName = $format === 'csv' ? 'app.users.password-reset.csv' : 'app.users.password-reset.pdf';
        $expiresAt ??= now()->addSeconds(self::TTL_SECONDS);

        return URL::temporarySignedRoute($routeName, $expiresAt, ['token' => $token]);
    }

    /**
     * @return array{0:int,1:string}|null [operatorId, cacheKey] ou null si non trouvé
     */
    private function findByToken(string $token): ?array
    {
        // La clé embarque l'operator_id; pour la retrouver depuis le seul token, on
        // stocke la décryption et la structure porte l'operator_id. Stratégie :
        // on utilise un index token→operator_id pour accélérer le lookup.
        $operatorId = Cache::get($this->tokenIndexKey($token));
        if ($operatorId === null) {
            return null;
        }

        $cacheKey = $this->cacheKey((int) $operatorId, $token);
        if (!Cache::has($cacheKey)) {
            Cache::forget($this->tokenIndexKey($token));
            return null;
        }

        return [(int) $operatorId, $cacheKey];
    }

    private function cacheKey(int $operatorId, string $token): string
    {
        return self::KEY_PREFIX . ":{$operatorId}:{$token}";
    }

    private function operatorIndexKey(int $operatorId): string
    {
        return self::OPERATOR_INDEX_PREFIX . ":{$operatorId}";
    }

    private function tokenIndexKey(string $token): string
    {
        return self::KEY_PREFIX . "_token:{$token}";
    }

    /**
     * Hook interne appelé par storeListing (via surcharge du put).
     * On recentralise la création d'index token → operator pour éviter de la
     * dupliquer dans storeListing.
     */
    public function registerTokenIndex(int $operatorId, string $token): void
    {
        Cache::put($this->tokenIndexKey($token), $operatorId, self::TTL_SECONDS);
    }
}
