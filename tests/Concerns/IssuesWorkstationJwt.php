<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Auth\V1\Jwt\WorkstationJwtIssuer;
use App\Auth\V1\Jwt\WorkstationJwtVerifier;
use App\Auth\V1\Models\WorkstationJwtRevocation;
use App\Auth\V1\Models\WorkstationRefreshToken;
use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Story 16.10 — helper de tests.
 *
 * Trait à inclure dans les tests qui ont besoin d'émettre un JWT
 * (Feature tests `PingControllerTest`, `RefreshControllerTest`, etc.).
 *
 * Fournit :
 *
 *  - `configureTestKeyPair()` : pointe config('auth_v1.jwt.keys') vers les
 *    fixtures `tests/fixtures/auth-v1/*.pem`. À appeler dans `setUp()`.
 *  - `issueTestJwt(array $overrides = [])` : émet un JWT signé avec la
 *    paire de tests, paramètres personnalisables (`sub`, `tier`, `exp`,
 *    `jti`, `iat`).
 *  - `ensureAuthV1Tables()` : crée les tables `workstation_refresh_tokens`
 *    + `workstation_jwt_revocations` en SQLite :memory: (iso pattern
 *    `CreatesPermissionSchema`/`CreatesDhcpSchema`).
 */
trait IssuesWorkstationJwt
{
    protected string $authV1TestKid = 'test-kid-2026-05-16';

    /**
     * Configure auth_v1 pour pointer vers les fixtures test. Cache driver = array.
     */
    protected function configureTestKeyPair(): void
    {
        $privPath = __DIR__ . '/../fixtures/auth-v1/private.pem';
        $pubPath = __DIR__ . '/../fixtures/auth-v1/public.pem';

        if (! is_file($privPath) || ! is_file($pubPath)) {
            self::fail(
                'auth-v1 test fixtures missing : '
                . 'tests/fixtures/auth-v1/{private,public}.pem'
                . ' — run helper from README.'
            );
        }

        config([
            'auth_v1.jwt.algorithm' => 'RS256',
            'auth_v1.jwt.access_ttl' => 86400,
            'auth_v1.jwt.refresh_ttl' => 2592000,
            'auth_v1.jwt.active_kid' => $this->authV1TestKid,
            'auth_v1.jwt.keys' => [
                $this->authV1TestKid => [
                    'private' => $privPath,
                    'public' => $pubPath,
                ],
            ],
            'auth_v1.jwt.issuer' => 'sambaedu-test',
            'auth_v1.revocation.cache_store' => 'array',
            'auth_v1.revocation.cache_ttl' => 60,
            'auth_v1.revocation.cache_prefix' => 'jwt:revoked:',
            'auth_v1.safety.forbid_test_keys_in_production' => false,
        ]);
    }

    /**
     * Émet un JWT poste signé avec la paire de tests.
     *
     * @param array<string,mixed> $overrides Surcharges des claims (`sub`,
     *                                       `tier`, `exp`, `iat`, `jti`,
     *                                       `iss`, `kid`).
     *
     * @return array{token: string, jti: string, sub: string, exp: int, kid: string}
     */
    protected function issueTestJwt(array $overrides = []): array
    {
        $kid = (string) ($overrides['kid'] ?? $this->authV1TestKid);
        $sub = (string) ($overrides['sub'] ?? Str::uuid());
        $tier = (string) ($overrides['tier'] ?? 'workstation');
        $iss = (string) ($overrides['iss'] ?? 'sambaedu-test');
        $iat = (int) ($overrides['iat'] ?? Carbon::now()->getTimestamp());
        $exp = (int) ($overrides['exp'] ?? Carbon::now()->addSeconds(86400)->getTimestamp());
        $jti = (string) ($overrides['jti'] ?? Str::uuid());

        $privPath = (string) config('auth_v1.jwt.keys.' . $this->authV1TestKid . '.private', '');
        $privKey = file_get_contents($privPath);
        if ($privKey === false) {
            self::fail('Cannot read test private key : ' . $privPath);
        }

        $payload = [
            'iss' => $iss,
            'sub' => $sub,
            'iat' => $iat,
            'exp' => $exp,
            'jti' => $jti,
            'tier' => $tier,
            'kid' => $kid,
        ];
        $token = JWT::encode($payload, $privKey, 'RS256', $kid);

        return [
            'token' => $token,
            'jti' => $jti,
            'sub' => $sub,
            'exp' => $exp,
            'kid' => $kid,
        ];
    }

    /**
     * Crée les tables `workstation_refresh_tokens` + `workstation_jwt_revocations`
     * en SQLite :memory: si elles n'existent pas. Iso-pattern
     * `CreatesPermissionSchema` (Sambaedu).
     */
    protected function ensureAuthV1Tables(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        if (! Schema::hasTable('workstation_refresh_tokens')) {
            Schema::create('workstation_refresh_tokens', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workstation_uuid')->index();
                $table->string('refresh_token_hash', 64)->unique();
                $table->timestamp('issued_at');
                $table->timestamp('expires_at')->index();
                $table->timestamp('revoked_at')->nullable()->index();
                $table->string('revocation_reason', 64)->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->json('client_meta')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('workstation_jwt_revocations')) {
            Schema::create('workstation_jwt_revocations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('jti')->unique();
                $table->uuid('workstation_uuid')->index();
                $table->timestamp('revoked_at');
                $table->string('reason', 128);
                $table->string('revoked_by')->nullable();
                $table->timestamp('expires_at')->index();
                $table->timestamps();
            });
        }

        // Story 16.11 — tables migration auto-bootstrap.
        if (! Schema::hasTable('workstations_migration_status')) {
            Schema::create('workstations_migration_status', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('workstation_uuid', 36)->unique();
                $table->timestamp('migrated_at');
                $table->string('access_token_emitted_jti', 36)->nullable();
                $table->string('bootstrap_token_hash_prefix', 16)->nullable();
                $table->string('os', 16);
                $table->string('se4fs_name', 255)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('workstation_migration_attempts')) {
            Schema::create('workstation_migration_attempts', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('workstation_uuid', 36)->nullable()->index();
                $table->timestamp('started_at');
                $table->timestamp('finished_at')->nullable();
                $table->string('status', 16);
                $table->string('error_code', 64)->nullable();
                $table->text('error_message')->nullable();
                $table->string('client_ip', 45);
                $table->text('user_agent')->nullable();
                $table->string('os', 16)->nullable();
                $table->timestamps();
            });
        }
    }
}
