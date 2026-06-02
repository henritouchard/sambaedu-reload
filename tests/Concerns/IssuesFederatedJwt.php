<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Story 20.1 — helper de tests pour l'auth fédérée.
 *
 * Calqué sur {@see IssuesWorkstationJwt}. Fournit :
 *
 *  - `configureFederatedAuth()` : pointe `config('federated_auth')` vers les
 *    fixtures RS256 `tests/fixtures/auth-v1/*.pem` (réutilisées — même algo),
 *    fixe `expected_iss`/`expected_aud`/`expected_tier`, cache replay en
 *    `array`. À appeler dans `setUp()`.
 *  - `issueFederatedJwt(array $overrides = [])` : émet un JWT fédéré signé.
 *  - `signFederatedJwt(array $payload, string $alg, ?string $key, ?string $kid)`
 *    : variante bas-niveau pour forger des jetons d'attaque (alg:none, HS256).
 *  - `ensureFederatedTables()` : crée les tables nécessaires en SQLite.
 */
trait IssuesFederatedJwt
{
    protected string $federatedTestKid = 'federated-test-kid';

    protected string $federatedTestIss = 'idp-test';

    protected string $federatedTestAud = 'se5-instance-test';

    protected function federatedPrivateKeyPath(): string
    {
        return __DIR__ . '/../fixtures/auth-v1/private.pem';
    }

    protected function federatedPublicKeyPath(): string
    {
        return __DIR__ . '/../fixtures/auth-v1/public.pem';
    }

    protected function configureFederatedAuth(array $overrides = []): void
    {
        $priv = $this->federatedPrivateKeyPath();
        $pub = $this->federatedPublicKeyPath();

        if (! is_file($priv) || ! is_file($pub)) {
            self::fail('federated test fixtures missing: tests/fixtures/auth-v1/{private,public}.pem');
        }

        config(array_merge([
            'federated_auth.jwt.algorithm' => 'RS256',
            'federated_auth.jwt.active_kid' => $this->federatedTestKid,
            'federated_auth.jwt.keys' => [
                $this->federatedTestKid => ['public' => $pub],
            ],
            'federated_auth.jwt.leeway' => 60,
            'federated_auth.expected_iss' => $this->federatedTestIss,
            'federated_auth.expected_aud' => $this->federatedTestAud,
            'federated_auth.expected_tier' => 'federated-user',
            'federated_auth.role_map' => ['technicien' => 'technicien'],
            'federated_auth.replay.cache_store' => 'array',
            'federated_auth.replay.cache_ttl' => 900,
            'federated_auth.replay.cache_prefix' => 'federated:jti:',
            'federated_auth.safety.forbid_test_keys_in_production' => false,
        ], $overrides));
    }

    /**
     * Émet un JWT fédéré signé RS256 avec la paire de tests.
     *
     * @param array<string,mixed> $overrides
     * @return array{token: string, jti: string, sub: string, exp: int}
     */
    protected function issueFederatedJwt(array $overrides = []): array
    {
        $now = Carbon::now()->getTimestamp();
        $jti = (string) ($overrides['jti'] ?? Str::uuid());
        $sub = (string) ($overrides['sub'] ?? Str::uuid());
        $exp = (int) ($overrides['exp'] ?? ($now + 600));

        $payload = array_merge([
            'iss' => $this->federatedTestIss,
            'aud' => $this->federatedTestAud,
            'sub' => $sub,
            'jti' => $jti,
            'kid' => $this->federatedTestKid,
            'tier' => 'federated-user',
            'role' => 'technicien',
            'login' => 'tech.externe',
            'name' => 'Tech Externe',
            'email' => 'tech@example.org',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $exp,
        ], $overrides);

        $token = $this->signFederatedJwt($payload, 'RS256', null, $payload['kid'] ?? $this->federatedTestKid);

        return ['token' => $token, 'jti' => (string) $payload['jti'], 'sub' => (string) $payload['sub'], 'exp' => (int) $payload['exp']];
    }

    /**
     * Signe un payload arbitraire (forge de jetons d'attaque inclus).
     *
     * @param array<string,mixed> $payload
     */
    protected function signFederatedJwt(array $payload, string $alg = 'RS256', ?string $key = null, ?string $kid = null): string
    {
        if ($alg === 'none') {
            // Forge manuelle d'un jeton alg:none (firebase/php-jwt refuse de
            // l'encoder). header.payload. (signature vide).
            $header = ['typ' => 'JWT', 'alg' => 'none'];
            if ($kid !== null) {
                $header['kid'] = $kid;
            }
            $segments = [
                $this->b64url(json_encode($header, JSON_THROW_ON_ERROR)),
                $this->b64url(json_encode($payload, JSON_THROW_ON_ERROR)),
                '',
            ];

            return implode('.', $segments);
        }

        if ($alg === 'RS256' && $key === null) {
            $key = (string) file_get_contents($this->federatedPrivateKeyPath());
        }

        // Pour HS256 (confusion d'algo) le caller passe la clé PUBLIQUE comme secret.
        return JWT::encode($payload, (string) $key, $alg, $kid);
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Crée les tables nécessaires aux tests fédérés en SQLite :memory:.
     */
    protected function ensureFederatedTables(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('login')->unique();
                $table->string('password')->nullable();
                $table->string('fullname')->nullable();
                $table->string('firstname')->nullable();
                $table->string('lastname')->nullable();
                $table->string('email')->nullable();
                $table->string('dn')->nullable();
                $table->string('ad_guid')->nullable();
                $table->string('role')->default('autre');
                $table->string('source', 16)->default('ad');
                $table->unsignedBigInteger('external_identity_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('ad_synced_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('external_identities')) {
            Schema::create('external_identities', function (Blueprint $table): void {
                $table->id();
                $table->string('external_sub')->unique();
                $table->string('issuer');
                $table->string('login')->nullable();
                $table->string('name')->nullable();
                $table->string('email')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_login_at')->nullable();
                // Story 20.2 — colonnes de cycle de vie / rétention RGPD.
                $table->timestamp('anonymized_at')->nullable();
                $table->string('deactivated_reason')->nullable();
                $table->string('deleted_reason')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('federated_jwt_consumptions')) {
            Schema::create('federated_jwt_consumptions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('jti')->unique();
                $table->string('iss')->nullable();
                $table->timestamp('consumed_at');
                $table->timestamp('expires_at')->index();
                $table->timestamps();
            });
        }

        // Tables Spatie minimales.
        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }
        if (! Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $table): void {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['permission_id', 'model_id', 'model_type']);
            });
        }
        if (! Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table): void {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type']);
            });
        }
        if (! Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $table): void {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
            });
        }
    }
}
