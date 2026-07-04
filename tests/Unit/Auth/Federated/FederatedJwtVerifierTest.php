<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Federated;

use App\Auth\Federated\Jwt\Exceptions\InvalidFederatedJwtException;
use App\Auth\Federated\Jwt\FederatedJwtReplayChecker;
use App\Auth\Federated\Jwt\FederatedJwtVerifier;
use App\Auth\Federated\Support\FederatedJwtErrorCodes;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesFederatedJwt;
use Tests\TestCase;

/**
 * Story 20.1 — AC2-10, AC16. Tests de sécurité du `FederatedJwtVerifier`.
 *
 * Couvre H1/H2/M4 : RS256 pinné, rejet `alg:none` + confusion d'algo,
 * validation iss/aud/tier/exp/nbf, claims requis, anti-rejeu jti, leeway ±60s.
 */
class FederatedJwtVerifierTest extends TestCase
{
    use IssuesFederatedJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureFederatedAuth();
        $this->ensureFederatedTables();
    }

    private function makeVerifier(): FederatedJwtVerifier
    {
        // Le verifier ne consomme plus le jti (anti-rejeu = controller, M1).
        return new FederatedJwtVerifier();
    }

    private function assertRejected(string $token, ?string $expectedCode = null): void
    {
        try {
            $this->makeVerifier()->verify($token);
            $this->fail('Expected InvalidFederatedJwtException');
        } catch (InvalidFederatedJwtException $e) {
            if ($expectedCode !== null) {
                $this->assertSame($expectedCode, $e->errorCode);
            }
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function valid_rs256_jwt_returns_claims(): void
    {
        $emitted = $this->issueFederatedJwt(['sub' => 'ext-sub-1', 'role' => 'technicien']);

        $claims = $this->makeVerifier()->verify($emitted['token']);

        $this->assertSame('ext-sub-1', $claims->sub);
        $this->assertSame('technicien', $claims->role);
        $this->assertSame($this->federatedTestIss, $claims->iss);
        $this->assertSame($this->federatedTestAud, $claims->aud);
        $this->assertSame('federated-user', $claims->tier);
    }

    #[Test]
    public function alg_none_is_rejected(): void
    {
        $now = Carbon::now()->getTimestamp();
        $token = $this->signFederatedJwt([
            'iss' => $this->federatedTestIss,
            'aud' => $this->federatedTestAud,
            'sub' => 'x', 'jti' => 'j', 'kid' => $this->federatedTestKid,
            'tier' => 'federated-user', 'role' => 'technicien', 'login' => 'l',
            'iat' => $now, 'exp' => $now + 600,
        ], 'none', null, $this->federatedTestKid);

        $this->assertRejected($token, FederatedJwtErrorCodes::JWT_SIGNATURE_INVALID);
    }

    #[Test]
    public function hs256_algorithm_confusion_is_rejected(): void
    {
        // Attaque classique : signer en HS256 en utilisant la clé PUBLIQUE
        // (connue de l'attaquant) comme secret HMAC.
        $now = Carbon::now()->getTimestamp();
        $publicKey = (string) file_get_contents($this->federatedPublicKeyPath());

        $token = $this->signFederatedJwt([
            'iss' => $this->federatedTestIss,
            'aud' => $this->federatedTestAud,
            'sub' => 'x', 'jti' => 'j', 'kid' => $this->federatedTestKid,
            'tier' => 'federated-user', 'role' => 'technicien', 'login' => 'l',
            'iat' => $now, 'exp' => $now + 600,
        ], 'HS256', $publicKey, $this->federatedTestKid);

        $this->assertRejected($token, FederatedJwtErrorCodes::JWT_SIGNATURE_INVALID);
    }

    #[Test]
    public function expired_jwt_beyond_leeway_is_rejected(): void
    {
        $emitted = $this->issueFederatedJwt([
            'iat' => Carbon::now()->subMinutes(30)->getTimestamp(),
            'exp' => Carbon::now()->subMinutes(10)->getTimestamp(),
        ]);

        $this->assertRejected($emitted['token'], FederatedJwtErrorCodes::JWT_EXPIRED);
    }

    #[Test]
    public function nbf_in_future_beyond_leeway_is_rejected(): void
    {
        $emitted = $this->issueFederatedJwt([
            'nbf' => Carbon::now()->addMinutes(10)->getTimestamp(),
            'exp' => Carbon::now()->addMinutes(20)->getTimestamp(),
        ]);

        $this->assertRejected($emitted['token'], FederatedJwtErrorCodes::JWT_NOT_YET_VALID);
    }

    #[Test]
    public function wrong_audience_is_rejected(): void
    {
        $emitted = $this->issueFederatedJwt(['aud' => 'se5-some-other-college']);

        $this->assertRejected($emitted['token'], FederatedJwtErrorCodes::AUD_MISMATCH);
    }

    #[Test]
    public function unknown_issuer_is_rejected(): void
    {
        $emitted = $this->issueFederatedJwt(['iss' => 'rogue-idp']);

        $this->assertRejected($emitted['token'], FederatedJwtErrorCodes::ISS_MISMATCH);
    }

    #[Test]
    public function unknown_kid_is_rejected(): void
    {
        // kid absent de la key-map → la lib ne trouve pas la clé → rejet
        // signature (D9).
        $emitted = $this->issueFederatedJwt(['kid' => 'unknown-kid']);

        $this->assertRejected($emitted['token'], FederatedJwtErrorCodes::JWT_SIGNATURE_INVALID);
    }

    #[Test]
    public function wrong_tier_is_rejected(): void
    {
        $emitted = $this->issueFederatedJwt(['tier' => 'workstation']);

        $this->assertRejected($emitted['token'], FederatedJwtErrorCodes::WRONG_TIER);
    }

    #[Test]
    public function missing_required_claim_is_rejected(): void
    {
        // role manquant → missing_claim.
        $now = Carbon::now()->getTimestamp();
        $token = $this->signFederatedJwt([
            'iss' => $this->federatedTestIss,
            'aud' => $this->federatedTestAud,
            'sub' => 'x', 'jti' => 'j', 'kid' => $this->federatedTestKid,
            'tier' => 'federated-user', 'login' => 'l',
            'iat' => $now, 'exp' => $now + 600,
        ], 'RS256', null, $this->federatedTestKid);

        $this->assertRejected($token, FederatedJwtErrorCodes::MISSING_CLAIM);
    }

    #[Test]
    public function missing_exp_claim_is_rejected(): void
    {
        // exp absent → branche dédiée `exp === 0` (couverture AC8, #5).
        $now = Carbon::now()->getTimestamp();
        $token = $this->signFederatedJwt([
            'iss' => $this->federatedTestIss,
            'aud' => $this->federatedTestAud,
            'sub' => 'x', 'jti' => 'j-no-exp', 'kid' => $this->federatedTestKid,
            'tier' => 'federated-user', 'role' => 'technicien', 'login' => 'l',
            'iat' => $now,
        ], 'RS256', null, $this->federatedTestKid);

        $this->assertRejected($token, FederatedJwtErrorCodes::MISSING_CLAIM);
    }

    #[Test]
    public function missing_aud_claim_is_rejected_as_missing_not_mismatch(): void
    {
        // aud totalement absent → missing_claim (branche dédiée, #5).
        $now = Carbon::now()->getTimestamp();
        $token = $this->signFederatedJwt([
            'iss' => $this->federatedTestIss,
            'sub' => 'x', 'jti' => 'j-no-aud', 'kid' => $this->federatedTestKid,
            'tier' => 'federated-user', 'role' => 'technicien', 'login' => 'l',
            'iat' => $now, 'exp' => $now + 600,
        ], 'RS256', null, $this->federatedTestKid);

        $this->assertRejected($token, FederatedJwtErrorCodes::MISSING_CLAIM);
    }

    #[Test]
    public function aud_array_without_match_is_rejected_as_aud_mismatch(): void
    {
        // aud PRÉSENT (array) mais aucune valeur ne matche → aud_mismatch
        // (et NON missing_claim) : code d'erreur fidèle (#3).
        $emitted = $this->issueFederatedJwt([
            'aud' => ['se5-other-a', 'se5-other-b'],
        ]);

        $this->assertRejected($emitted['token'], FederatedJwtErrorCodes::AUD_MISMATCH);
    }

    #[Test]
    public function aud_array_with_match_is_accepted(): void
    {
        // aud array contenant l'audience attendue → accepté (RFC 7519).
        $emitted = $this->issueFederatedJwt([
            'aud' => ['se5-other-a', $this->federatedTestAud],
        ]);

        $claims = $this->makeVerifier()->verify($emitted['token']);
        $this->assertSame($this->federatedTestAud, $claims->aud);
    }

    #[Test]
    public function replay_checker_consumes_jti_once_then_rejects(): void
    {
        // M1 : la consommation jti est désormais portée par le replay-checker
        // (appelé par le controller après provisioning), plus par le verifier.
        // On teste donc directement le mécanisme d'usage unique.
        $checker = new FederatedJwtReplayChecker();
        $exp = Carbon::now()->addMinutes(10)->getTimestamp();

        $this->assertTrue($checker->consumeOnce('unique-jti-123', $exp), '1er usage doit réussir');
        $this->assertFalse($checker->consumeOnce('unique-jti-123', $exp), 'rejeu doit être refusé');
    }

    #[Test]
    public function verifier_does_not_consume_jti(): void
    {
        // Le même jeton vérifié deux fois reste valide côté verifier : la
        // consommation n'est plus de sa responsabilité (M1).
        $emitted = $this->issueFederatedJwt(['jti' => 'verify-twice-jti']);

        $first = $this->makeVerifier()->verify($emitted['token']);
        $second = $this->makeVerifier()->verify($emitted['token']);

        $this->assertSame('verify-twice-jti', $first->jti);
        $this->assertSame('verify-twice-jti', $second->jti);
    }

    #[Test]
    public function clock_skew_within_60s_is_accepted(): void
    {
        // exp dépassé de 30s mais dans le leeway ±60s → accepté.
        $emitted = $this->issueFederatedJwt([
            'iat' => Carbon::now()->subMinutes(10)->getTimestamp(),
            'exp' => Carbon::now()->subSeconds(30)->getTimestamp(),
        ]);

        $claims = $this->makeVerifier()->verify($emitted['token']);
        $this->assertSame($this->federatedTestIss, $claims->iss);
    }

    #[Test]
    public function garbage_string_is_rejected_as_malformed(): void
    {
        $this->assertRejected('not a jwt at all', FederatedJwtErrorCodes::JWT_MALFORMED);
    }

    #[Test]
    public function no_keys_configured_rejects_all(): void
    {
        config(['federated_auth.jwt.keys' => []]);
        $emitted = $this->issueFederatedJwt();

        $this->assertRejected($emitted['token'], FederatedJwtErrorCodes::JWT_SIGNATURE_INVALID);
    }

    // ---------------------------------------------------------------------
    // Story 39.3 — bridge IdP « du handshake » (DB) → vérificateur JWT (canal ⑤)
    // ---------------------------------------------------------------------

    #[Test]
    public function handshake_idp_jwt_is_accepted_from_db_without_env_config(): void
    {
        // AC3 — preuve end-to-end : un JWT signé par l'IdP réellement provisionné
        // au handshake (clé/kid/iss stockés en base) est accepté SANS aucun
        // réglage env. On VIDE explicitement les 3 sources config du chemin
        // « repli » pour prouver que c'est bien la DB qui répond.
        config([
            'federated_auth.jwt.keys' => [],
            'federated_auth.expected_iss' => '',
            'federated_auth.expected_aud' => '',
            'controlHub.se4fs.instance_id' => 'se5-instance-uuid-39-3',
        ]);

        $this->seedFederatedIdpConnection();

        $token = $this->issueHandshakeIdpJwt('se5-instance-uuid-39-3', ['sub' => 'ext-handshake']);

        $claims = $this->makeVerifier()->verify($token);

        $this->assertSame('ext-handshake', $claims->sub);
        $this->assertSame($this->federatedIdpIss, $claims->iss);
        $this->assertSame('se5-instance-uuid-39-3', $claims->aud);
        $this->assertSame($this->federatedIdpKid, $claims->kid);
        $this->assertSame('federated-user', $claims->tier);
    }

    #[Test]
    public function handshake_idp_jwt_signed_with_wrong_key_is_rejected(): void
    {
        // Corollaire sécurité AC3/AC6 : la clé DB est bien la clé pivot. Un jeton
        // qui prétend le bon kid/iss/aud mais signé par la paire CONFIG (≠ paire
        // du handshake) est rejeté en signature — RS256 pinné, pas de confusion
        // de source.
        config([
            'federated_auth.jwt.keys' => [],
            'federated_auth.expected_iss' => '',
            'federated_auth.expected_aud' => '',
            'controlHub.se4fs.instance_id' => 'se5-instance-uuid-39-3',
        ]);

        $this->seedFederatedIdpConnection();

        // Signé avec la clé PRIVÉE des fixtures config, mais présenté avec le kid
        // du handshake → la clé publique DB ne valide pas la signature.
        $now = Carbon::now()->getTimestamp();
        $forged = $this->signFederatedJwt([
            'iss' => $this->federatedIdpIss,
            'aud' => 'se5-instance-uuid-39-3',
            'sub' => 'x', 'jti' => 'j-forged', 'kid' => $this->federatedIdpKid,
            'tier' => 'federated-user', 'role' => 'technicien', 'login' => 'l',
            'iat' => $now, 'nbf' => $now, 'exp' => $now + 600,
        ], 'RS256', null, $this->federatedIdpKid);

        $this->assertRejected($forged, FederatedJwtErrorCodes::JWT_SIGNATURE_INVALID);
    }

    #[Test]
    public function db_precedence_wins_over_a_conflicting_non_empty_config(): void
    {
        // Review 39.3 #1 — preuve de précédence RÉELLE (pas seulement « la DB marche
        // quand la config est vide »). La config reste PLEINE (clés/iss du setUp,
        // différentes de la DB) PENDANT que la ControlHubConnection est active. Un
        // jeton signé avec la paire CONFIG (kid=federatedTestKid, iss=federatedTestIss)
        // — qui serait accepté en l'ABSENCE de DB (cf. db_absent_falls_back_to_config)
        // — doit être REJETÉ : le verifier ne connaît QUE la clé DB (key-map à 1 entrée,
        // aucune fusion DB+config). Si une régression future réintroduisait un merge,
        // ce test le détecterait.
        $this->seedFederatedIdpConnection();
        $connection = \App\Models\ControlHubConnection::current();
        $this->assertTrue($connection->hasFederatedIdp(), 'précondition : IdP DB provisionné');

        $emitted = $this->issueFederatedJwt(['sub' => 'ext-config-should-lose']);

        $this->assertRejected($emitted['token']);
    }

    #[Test]
    public function db_absent_falls_back_to_config(): void
    {
        // AC4 — repli : aucune ControlHubConnection active. Résolution 100%
        // config, strictement identique à l'existant → JWT config accepté.
        $this->assertSame(0, \App\Models\ControlHubConnection::query()->count());

        $emitted = $this->issueFederatedJwt(['sub' => 'ext-config-path']);

        $claims = $this->makeVerifier()->verify($emitted['token']);

        $this->assertSame('ext-config-path', $claims->sub);
        $this->assertSame($this->federatedTestIss, $claims->iss);
        $this->assertSame($this->federatedTestAud, $claims->aud);
    }

    #[Test]
    public function incomplete_db_connection_falls_back_to_config(): void
    {
        // AC4 — DB présente mais INCOMPLÈTE (`hasFederatedIdp() === false`, ici
        // `idp_kid` null) → repli config, pas de crash, JWT config accepté.
        $this->seedFederatedIdpConnection(['idp_kid' => null]);

        $connection = \App\Models\ControlHubConnection::current();
        $this->assertNotNull($connection);
        $this->assertFalse($connection->hasFederatedIdp());

        $emitted = $this->issueFederatedJwt(['sub' => 'ext-incomplete-db']);

        $claims = $this->makeVerifier()->verify($emitted['token']);

        $this->assertSame('ext-incomplete-db', $claims->sub);
        $this->assertSame($this->federatedTestIss, $claims->iss);
    }

    #[Test]
    public function aud_falls_back_to_instance_id_when_expected_aud_not_set(): void
    {
        // AC2 — indépendant du bridge clé/kid/iss : DB absente, `expected_aud`
        // NON configuré → le repli `aud` porte sur l'uuid d'instance
        // (`controlHub.se4fs.instance_id`), pas sur `sambaedu.se4fs_name`.
        config([
            'federated_auth.expected_aud' => '',
            'sambaedu.se4fs_name' => 'legacy-se4fs-name-should-not-be-used',
            'controlHub.se4fs.instance_id' => 'se5-instance-aud-uuid',
        ]);

        $emitted = $this->issueFederatedJwt(['sub' => 'ext-aud', 'aud' => 'se5-instance-aud-uuid']);

        $claims = $this->makeVerifier()->verify($emitted['token']);
        $this->assertSame('se5-instance-aud-uuid', $claims->aud);
    }

    #[Test]
    public function aud_bound_to_se4fs_name_is_rejected_after_instance_id_switch(): void
    {
        // AC2 — preuve du changement de fallback : un jeton dont `aud` vaut
        // l'ANCIEN identifiant (`sambaedu.se4fs_name`) n'est PLUS accepté ; seul
        // l'uuid d'instance l'est désormais.
        config([
            'federated_auth.expected_aud' => '',
            'sambaedu.se4fs_name' => 'legacy-se4fs-name',
            'controlHub.se4fs.instance_id' => 'se5-instance-aud-uuid',
        ]);

        $emitted = $this->issueFederatedJwt(['aud' => 'legacy-se4fs-name']);

        $this->assertRejected($emitted['token'], FederatedJwtErrorCodes::AUD_MISMATCH);
    }
}
