<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\ControlHubConnection;
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

    // Story 39.3 — IdP « du handshake » : paire RS256 DÉDIÉE, DISTINCTE des
    // fixtures `tests/fixtures/auth-v1/*.pem` (celles-ci servent le chemin
    // CONFIG). Une paire distincte prouve, sans ambiguïté, qu'un JWT accepté
    // via le bridge DB tient à la clé stockée en base (`controlhub_connection`)
    // et non à la config env.
    protected string $federatedIdpKid = 'idp-handshake-kid';

    protected string $federatedIdpIss = 'https://idp.handshake.test/realms/se5';

    protected ?string $federatedIdpPrivateKeyPem = null;

    protected ?string $federatedIdpPublicKeyPem = null;

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

        // Story 20.4 — journal d'audit dénormalisé des actions externes.
        if (! Schema::hasTable('external_action_audit_logs')) {
            Schema::create('external_action_audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->string('actor_login');
                $table->string('actor_external_sub')->nullable();
                $table->string('actor_name')->nullable();
                $table->string('actor_role')->nullable();
                $table->string('source', 32)->default('federated');
                $table->string('http_method', 10);
                $table->string('route_name')->nullable();
                $table->string('path');
                $table->string('action_label')->nullable();
                $table->integer('status_code');
                $table->timestamp('occurred_at');
                $table->unsignedBigInteger('external_identity_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->index('actor_login');
                $table->index('actor_external_sub');
                $table->index('source');
                $table->index('occurred_at');
                // Iso-prod (migration 2026_06_03_120000) : index de corrélation FK.
                $table->index('external_identity_id');
                $table->index('user_id');
            });
        }

        // Story 39.3 — table de connexion controlHub (IdP fédéré du handshake).
        // PRÉREQUIS BLOQUANT : `FederatedJwtVerifier` appelle désormais
        // `ControlHubConnection::current()` dans `buildKeyMap()`/`expectedIss()`.
        // Sans cette table, TOUTES les suites fédérées (qui bâtissent leur
        // schéma SQLite à la main, sans migrations) crasheraient sur « no such
        // table » dès le 1er `verify()`, y compris les tests hors bridge.
        // Colonnes calquées sur la migration de base + `2026_06_04_130000…`
        // (idp_*). `base_url`/`api_token`/`se4fs_api_token` rendus nullable ici
        // (le seed n'a pas à les fournir), le reste reprend les défauts réels.
        if (! Schema::hasTable('controlhub_connection')) {
            Schema::create('controlhub_connection', function (Blueprint $table): void {
                $table->id();
                $table->string('base_url', 512)->nullable();
                $table->text('api_token')->nullable();
                $table->string('se4fs_api_token', 64)->nullable();
                // Bloc idp_federated (migration 2026_06_04_130000).
                $table->text('idp_public_key')->nullable();
                $table->string('idp_kid', 100)->nullable();
                $table->string('idp_iss', 512)->nullable();
                $table->integer('heartbeat_interval')->default(300);
                $table->boolean('heartbeat_enabled')->default(true);
                $table->integer('heartbeat_failures')->default(0);
                $table->string('status', 20)->default('unknown');
                $table->string('error_type', 100)->nullable();
                $table->timestamp('last_handshake_at')->nullable();
                $table->timestamp('last_heartbeat_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
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

    /**
     * Story 39.3 — génère (paresseusement) la paire RS256 dédiée à l'IdP « du
     * handshake » et la mémorise sur l'instance de test. Distincte des fixtures
     * `auth-v1` : c'est ce qui rend les tests du bridge discriminants.
     */
    protected function ensureFederatedIdpKeyPair(): void
    {
        if ($this->federatedIdpPrivateKeyPem !== null && $this->federatedIdpPublicKeyPem !== null) {
            return;
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false) {
            self::fail('openssl_pkey_new failed: ext-openssl requis pour les tests du bridge IdP fédéré');
        }

        $privPem = '';
        openssl_pkey_export($resource, $privPem);
        $details = openssl_pkey_get_details($resource);

        $this->federatedIdpPrivateKeyPem = $privPem;
        $this->federatedIdpPublicKeyPem = (string) ($details['key'] ?? '');
    }

    /**
     * Story 39.3 — seed une `ControlHubConnection` active portant l'IdP fédéré
     * reçu au handshake (clé publique PEM en clair + kid + iss). Réutilise le
     * vrai chemin d'écriture `ControlHubConnection::createOrUpdate()`.
     *
     * Passer `['idp_kid' => null]` (ou `idp_public_key`/`idp_iss` = null) permet
     * de simuler une ligne active INCOMPLÈTE (`hasFederatedIdp() === false`),
     * qui doit retomber sur le repli config.
     *
     * @param array<string,mixed> $overrides
     */
    protected function seedFederatedIdpConnection(array $overrides = []): ControlHubConnection
    {
        $this->ensureFederatedIdpKeyPair();

        return ControlHubConnection::createOrUpdate(array_merge([
            'se4fs_api_token' => 'seed-se4fs-token',
            'base_url' => 'https://handshake.test',
            'idp_public_key' => $this->federatedIdpPublicKeyPem,
            'idp_kid' => $this->federatedIdpKid,
            'idp_iss' => $this->federatedIdpIss,
        ], $overrides));
    }

    /**
     * Story 39.3 — émet un JWT signé par la clé PRIVÉE de l'IdP « du handshake »
     * (celle dont la publique est stockée en base par `seedFederatedIdpConnection`).
     * `iss`/`kid` défaut = ceux de la connexion ; `aud` = uuid d'instance passé
     * (= `config('controlHub.se4fs.instance_id')` côté vérificateur).
     *
     * @param array<string,mixed> $overrides
     */
    protected function issueHandshakeIdpJwt(string $instanceId, array $overrides = []): string
    {
        $this->ensureFederatedIdpKeyPair();

        $now = Carbon::now()->getTimestamp();
        $payload = array_merge([
            'iss' => $this->federatedIdpIss,
            'aud' => $instanceId,
            'sub' => (string) Str::uuid(),
            'jti' => (string) Str::uuid(),
            'kid' => $this->federatedIdpKid,
            'tier' => 'federated-user',
            'role' => 'technicien',
            'login' => 'tech.externe',
            'name' => 'Tech Externe',
            'email' => 'tech@example.org',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 600,
        ], $overrides);

        return $this->signFederatedJwt(
            $payload,
            'RS256',
            $this->federatedIdpPrivateKeyPem,
            (string) ($payload['kid'] ?? $this->federatedIdpKid),
        );
    }
}
