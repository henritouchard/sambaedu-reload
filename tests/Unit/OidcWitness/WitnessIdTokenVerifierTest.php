<?php

declare(strict_types=1);

namespace Tests\Unit\OidcWitness;

use App\OidcWitness\Jwt\Exceptions\InvalidWitnessIdTokenException;
use App\OidcWitness\Jwt\WitnessIdTokenVerifier;
use App\OidcWitness\Jwt\WitnessJtiReplayGuard;
use App\OidcWitness\Support\WitnessCredentials;
use App\OidcWitness\Support\WitnessErrorCodes;
use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 55.3 — **LA SUITE D'ATTAQUE CLIENTE (NFR1 : chaque cas = UN test).**
 *
 * Patron littéral de `tests/Unit/Auth/Federated/FederatedJwtVerifierTest.php`
 * (Epic 20). Le vecteur d'attaque est nommé dans le nom du test, et chaque refus
 * assert le CODE d'erreur attendu — un refus « pour la mauvaise raison » est une
 * régression silencieuse.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  LE CONTRÔLE POSITIF EST LA CONDITION DE VALIDITÉ DE TOUS LES REFUS
 *
 *  {@see self::a_nominal_id_token_is_accepted()} ouvre le fichier. Sans lui,
 *  chacun des refus ci-dessous pourrait n'être que le symptôme d'une plomberie
 *  cassée (mauvais PEM, mauvais `kid`, JWKS mal reconstruit) et ne
 *  démontrerait RIEN. C'est la leçon la plus chère de cet epic.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── TRAÇABILITÉ NFR1 (cas de l'AC d'epic → où il est prouvé) ───────────────
 *
 * | Cas | Prouvé par |
 * |---|---|
 * | `alg: none` | `alg_none_is_rejected` (ici) |
 * | Confusion d'algorithme symétrique (HS256 + clé publique en secret) | `hs256_algorithm_confusion_with_the_public_key_as_secret_is_rejected` (ici) |
 * | `aud` d'un autre client | `an_audience_of_another_client_is_rejected` (ici) |
 * | `iss` d'une autre instance | `an_issuer_of_another_instance_is_rejected` (ici) |
 * | Clé d'une autre instance | `a_token_signed_by_a_foreign_key_is_rejected` (ici) |
 * | `kid` inconnu du JWKS | `an_unknown_kid_is_rejected` (ici) |
 * | Jeton expiré / marge de tolérance | `an_expired_id_token_beyond_the_leeway_is_rejected` + `an_expiry_within_the_leeway_is_still_accepted` (ici) |
 * | `nbf` futur | `a_nbf_in_the_future_beyond_the_leeway_is_rejected` (ici) |
 * | `jti` rejoué | `a_replayed_id_token_is_rejected_on_second_use` (ici) |
 * | `nonce` absent / altéré | `a_diverging_nonce_is_rejected`, `a_missing_nonce_is_rejected_when_one_was_sent` (ici) |
 * | Chaîne malformée | `a_garbage_string_is_rejected_as_malformed` (ici) |
 * | `state` altéré au retour | `Tests\Feature\OidcWitness\WitnessFlowTest` (c'est un cas de CALLBACK, pas de jeton) |
 * | **`redirect_uri` altérée** | **DÉJÀ COUVERT 55.1** — `OidcAuthorizeRefusalsTest` (`an_undeclared_redirect_uri_gets_a_local_400…`, `a_redirect_uri_matching_only_by_prefix_is_refused`) et `OidcAuthorizationFlowTest::a_redirect_uri_differing_from_the_one_bound_to_the_code_is_refused`. **Non dupliqué ici** : c'est un refus SERVEUR. |
 * | **PKCE absent / `plain` / verifier faux** | **DÉJÀ COUVERT 55.1** — `OidcAuthorizeRefusalsTest`, `OidcAuthorizationFlowTest::a_wrong_code_verifier_is_refused_and_burns_the_code`. **Non dupliqué.** |
 * | **Usage unique du code d'autorisation** | **DÉJÀ COUVERT 55.1** — `replaying_a_consumed_code_is_refused`. **Non dupliqué.** |
 * | **Secret client faux, client révoqué, scope inconnu, compte désactivé** | **DÉJÀ COUVERT 55.1/55.2** (108 tests OIDC). **Non dupliqué.** |
 *
 * Re-tester ici les refus du fournisseur ne prouverait rien de plus et
 * fabriquerait une seconde source de vérité à maintenir.
 */
class WitnessIdTokenVerifierTest extends TestCase
{
    /** `kid` publié par « notre » instance. */
    private const KID = 'test-oidc-kid';

    private const ISSUER = 'https://se5.test';

    private const CLIENT_ID = 'witness-client-id';

    private const NONCE = 'nonce-du-temoin';

    /** Paire RSA d'une AUTRE instance SambaEdu, générée à la volée. */
    private static ?string $foreignPrivatePem = null;

    private static ?string $foreignPublicPem = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'oidc.leeway' => 60,
            // Le store d'anti-rejeu du témoin : `array` en test, `file` en prod
            // (patron `federated_auth.replay.cache_store`).
            'oidc.witness.replay_cache_store' => 'array',
            'oidc.witness.replay_cache_prefix' => 'oidc-witness-test:jti:',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function verifier(): WitnessIdTokenVerifier
    {
        return new WitnessIdTokenVerifier(new WitnessJtiReplayGuard());
    }

    private function credentials(?string $issuer = null, ?string $clientId = null): WitnessCredentials
    {
        return new WitnessCredentials(
            clientId: $clientId ?? self::CLIENT_ID,
            clientSecret: 'peu-importe-ici',
            issuer: $issuer ?? self::ISSUER,
            redirectUri: '/sso-demo/callback',
        );
    }

    private function privatePem(): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/auth-v1/private.pem'));
    }

    private function publicPem(): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/auth-v1/public.pem'));
    }

    /**
     * Paire RSA d'une « autre instance » — générée UNE fois (une RSA 2048 coûte
     * des centaines de ms). Patron `IssuesFederatedJwt::ensureFederatedIdpKeyPair()`.
     *
     * @return array{private: string, public: string}
     */
    private function foreignKeyPair(): array
    {
        if (self::$foreignPrivatePem === null || self::$foreignPublicPem === null) {
            $resource = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            self::assertNotFalse($resource, 'ext-openssl requis pour forger la clé d\'une autre instance');

            $private = '';
            openssl_pkey_export($resource, $private);
            $details = openssl_pkey_get_details($resource);

            self::$foreignPrivatePem = $private;
            self::$foreignPublicPem = (string) ($details['key'] ?? '');
        }

        return ['private' => self::$foreignPrivatePem, 'public' => self::$foreignPublicPem];
    }

    /**
     * Le JWKS que le témoin récupérerait par HTTP, bâti depuis un PEM public —
     * exactement la forme publiée par `OidcKeyManager::jwks()`.
     *
     * @return array<string, mixed>
     */
    private function jwk(string $publicPem, string $kid): array
    {
        $resource = openssl_pkey_get_public($publicPem);
        self::assertNotFalse($resource);

        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n' => self::base64UrlEncode((string) $details['rsa']['n']),
            'e' => self::base64UrlEncode((string) $details['rsa']['e']),
        ];
    }

    /** Le JWKS nominal de « notre » instance. @return list<array<string, mixed>> */
    private function jwks(): array
    {
        return [$this->jwk($this->publicPem(), self::KID)];
    }

    /**
     * Claims nominaux d'un id_token émis par SE5 (structure figée par 55.1,
     * claims métier par 55.2).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        $now = Carbon::now()->getTimestamp();

        return array_merge([
            'iss' => self::ISSUER,
            'sub' => 'prof.dupont',
            'aud' => self::CLIENT_ID,
            'iat' => $now,
            'exp' => $now + 300,
            'jti' => 'jti-' . bin2hex(random_bytes(8)),
            'nonce' => self::NONCE,
            'name' => 'Professeur Dupont',
            'role' => 'prof',
            'groups' => ['3A', '4B'],
        ], $overrides);
    }

    /**
     * Signe un id_token. `$algorithm` et `$key` permettent de forger les
     * attaques ; `alg: none` est monté à la main (la bibliothèque refuse de
     * l'émettre — c'est déjà une bonne nouvelle, mais on doit quand même
     * prouver que le VÉRIFICATEUR le rejette).
     *
     * @param  array<string, mixed>  $claims
     */
    private function sign(array $claims, string $algorithm = 'RS256', ?string $key = null, string $kid = self::KID): string
    {
        if ($algorithm === 'none') {
            $header = self::base64UrlEncode((string) json_encode(['alg' => 'none', 'typ' => 'JWT', 'kid' => $kid]));
            $payload = self::base64UrlEncode((string) json_encode($claims));

            return $header . '.' . $payload . '.';
        }

        return JWT::encode($claims, $key ?? $this->privatePem(), $algorithm, $kid);
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @param  list<array<string, mixed>>|null  $jwks
     */
    private function assertRejected(
        string $token,
        string $expectedCode,
        ?array $jwks = null,
        ?WitnessCredentials $credentials = null,
        string $nonce = self::NONCE,
    ): void {
        try {
            $this->verifier()->verify($token, $credentials ?? $this->credentials(), $jwks ?? $this->jwks(), $nonce);
            self::fail('Le jeton aurait dû être refusé (' . $expectedCode . ')');
        } catch (InvalidWitnessIdTokenException $e) {
            self::assertSame($expectedCode, $e->errorCode);
        }
    }

    // =====================================================================
    // LE CONTRÔLE POSITIF — sans lui, aucun refus ci-dessous ne prouve rien
    // =====================================================================

    #[Test]
    public function a_nominal_id_token_is_accepted(): void
    {
        $claims = $this->verifier()->verify(
            $this->sign($this->claims()),
            $this->credentials(),
            $this->jwks(),
            self::NONCE,
        );

        self::assertSame('prof.dupont', $claims['sub']);
        self::assertSame('Professeur Dupont', $claims['name']);
        self::assertSame('prof', $claims['role']);
        self::assertSame(['3A', '4B'], (array) $claims['groups']);
        self::assertSame(self::CLIENT_ID, $claims['aud']);
        self::assertSame(self::ISSUER, $claims['iss']);
    }

    // =====================================================================
    // Famille 1 — la signature : algorithme, clé, kid
    // =====================================================================

    #[Test]
    public function alg_none_is_rejected(): void
    {
        // L'attaque canonique : « je te dis que ce jeton n'est pas signé, et tu
        // me crois ». La key-map ne contient QUE du RS256 : la bibliothèque
        // refuse avant toute opération cryptographique.
        $token = $this->sign($this->claims(), 'none');

        $this->assertRejected($token, WitnessErrorCodes::ID_TOKEN_SIGNATURE_INVALID);
    }

    #[Test]
    public function hs256_algorithm_confusion_with_the_public_key_as_secret_is_rejected(): void
    {
        // Confusion d'algorithme : la clé PUBLIQUE est connue de tous (elle est
        // au JWKS). Un vérificateur qui déduirait l'algorithme du header
        // l'utiliserait comme secret HMAC — et signerait n'importe quel jeton
        // pour n'importe quel utilisateur.
        $token = $this->sign($this->claims(), 'HS256', $this->publicPem());

        $this->assertRejected($token, WitnessErrorCodes::ID_TOKEN_SIGNATURE_INVALID);
    }

    #[Test]
    public function a_token_signed_by_a_foreign_key_is_rejected(): void
    {
        // « L'autre instance » complète : le collège voisin signe un jeton dont
        // TOUS les claims sont ceux que nous attendons. Seule la clé diffère.
        $foreign = $this->foreignKeyPair();

        $token = $this->sign($this->claims(), 'RS256', $foreign['private']);

        $this->assertRejected($token, WitnessErrorCodes::ID_TOKEN_SIGNATURE_INVALID);
    }

    #[Test]
    public function a_foreign_key_published_under_our_kid_still_fails(): void
    {
        // Variante plus vicieuse : l'attaquant publierait sa clé sous NOTRE
        // `kid`. Contrôle positif implicite — le JWKS servi est ici celui de
        // l'attaquant, donc la seule chose qui protège est que le témoin lit le
        // JWKS de SON issuer, jamais un JWKS fourni avec le jeton.
        $foreign = $this->foreignKeyPair();

        $token = $this->sign($this->claims(), 'RS256', $foreign['private']);

        // Notre JWKS (clé légitime) ⇒ refus.
        $this->assertRejected($token, WitnessErrorCodes::ID_TOKEN_SIGNATURE_INVALID);

        // Et si le JWKS était CELUI DE L'ATTAQUANT, le jeton passerait — ce qui
        // démontre que la sécurité repose sur la PROVENANCE du JWKS
        // (`{issuer}/.well-known/...` en HTTP), pas sur le jeton.
        $claims = $this->verifier()->verify(
            $token,
            $this->credentials(),
            [$this->jwk($foreign['public'], self::KID)],
            self::NONCE,
        );
        self::assertSame('prof.dupont', $claims['sub']);
    }

    #[Test]
    public function an_unknown_kid_is_rejected(): void
    {
        $token = $this->sign($this->claims(), 'RS256', null, 'kid-inconnu');

        $this->assertRejected($token, WitnessErrorCodes::ID_TOKEN_SIGNATURE_INVALID);
    }

    #[Test]
    public function an_empty_jwks_rejects_everything(): void
    {
        // Fail-closed : ne rien pouvoir vérifier n'est pas une raison
        // d'accepter. Le jeton est pourtant PARFAITEMENT valide (contrôle
        // positif en tête de fichier).
        $this->assertRejected(
            $this->sign($this->claims()),
            WitnessErrorCodes::JWKS_UNUSABLE,
            jwks: [],
        );
    }

    #[Test]
    public function a_jwks_entry_announcing_a_symmetric_algorithm_is_ignored(): void
    {
        // Une clé annoncée `HS256` au JWKS n'est pas « supportée » : elle est
        // ignorée. Le témoin n'accepte QUE RS256, quoi que le fournisseur
        // publie.
        $jwk = $this->jwk($this->publicPem(), self::KID);
        $jwk['alg'] = 'HS256';

        $this->assertRejected(
            $this->sign($this->claims()),
            WitnessErrorCodes::JWKS_UNUSABLE,
            jwks: [$jwk],
        );
    }

    #[Test]
    public function a_garbage_string_is_rejected_as_malformed(): void
    {
        $this->assertRejected('ceci n\'est pas un jeton', WitnessErrorCodes::ID_TOKEN_MALFORMED);
    }

    #[Test]
    public function a_truncated_jwt_is_rejected(): void
    {
        $token = $this->sign($this->claims());
        $truncated = substr($token, 0, (int) (strlen($token) * 0.6));

        try {
            $this->verifier()->verify($truncated, $this->credentials(), $this->jwks(), self::NONCE);
            self::fail('Un JWT tronqué doit être refusé');
        } catch (InvalidWitnessIdTokenException $e) {
            self::assertContains($e->errorCode, [
                WitnessErrorCodes::ID_TOKEN_MALFORMED,
                WitnessErrorCodes::ID_TOKEN_SIGNATURE_INVALID,
            ]);
        }
    }

    // =====================================================================
    // Famille 2 — l'instance : `aud`, `iss`
    // =====================================================================

    #[Test]
    public function an_audience_of_another_client_is_rejected(): void
    {
        // Même instance, même clé, mais jeton émis pour une AUTRE extension.
        // Sans ce contrôle, une extension malveillante rejouerait chez sa
        // voisine le jeton qu'elle a légitimement reçu.
        $token = $this->sign($this->claims(['aud' => 'un-autre-client-de-la-meme-instance']));

        $this->assertRejected($token, WitnessErrorCodes::AUD_MISMATCH);
    }

    #[Test]
    public function an_issuer_of_another_instance_is_rejected(): void
    {
        // Le collège voisin : `iss` différent, alors même que l'`aud`
        // coïnciderait par hasard.
        $token = $this->sign($this->claims(['iss' => 'https://se5.college-voisin.test']));

        $this->assertRejected($token, WitnessErrorCodes::ISS_MISMATCH);
    }

    #[Test]
    public function an_aud_array_containing_our_client_id_is_accepted(): void
    {
        // RFC 7519 : `aud` peut être un tableau. Contrôle POSITIF de la
        // tolérance — sans lui, le refus ci-dessus pourrait n'être que le
        // symptôme d'un `aud` tableau systématiquement rejeté.
        $token = $this->sign($this->claims(['aud' => ['un-autre-client', self::CLIENT_ID]]));

        $claims = $this->verifier()->verify($token, $this->credentials(), $this->jwks(), self::NONCE);

        self::assertSame(['un-autre-client', self::CLIENT_ID], (array) $claims['aud']);
    }

    #[Test]
    public function an_aud_array_without_our_client_id_is_rejected(): void
    {
        $token = $this->sign($this->claims(['aud' => ['client-a', 'client-b']]));

        $this->assertRejected($token, WitnessErrorCodes::AUD_MISMATCH);
    }

    // =====================================================================
    // Famille 3 — le temps : `exp`, `nbf`, et la marge de tolérance
    // =====================================================================

    #[Test]
    public function an_expired_id_token_beyond_the_leeway_is_rejected(): void
    {
        $now = Carbon::now()->getTimestamp();

        $token = $this->sign($this->claims([
            'iat' => $now - 1800,
            'exp' => $now - 600,
        ]));

        $this->assertRejected($token, WitnessErrorCodes::ID_TOKEN_EXPIRED);
    }

    #[Test]
    public function an_expiry_within_the_leeway_is_still_accepted(): void
    {
        // CONTRÔLE POSITIF de la tolérance d'horloge (60 s) : sans lui, le refus
        // ci-dessus prouverait seulement qu'on rejette tout ce qui est passé —
        // et la moindre dérive d'horloge casserait le SSO en production.
        $now = Carbon::now()->getTimestamp();

        $token = $this->sign($this->claims([
            'iat' => $now - 600,
            'exp' => $now - 30,
        ]));

        $claims = $this->verifier()->verify($token, $this->credentials(), $this->jwks(), self::NONCE);

        self::assertSame('prof.dupont', $claims['sub']);
    }

    #[Test]
    public function a_nbf_in_the_future_beyond_the_leeway_is_rejected(): void
    {
        $now = Carbon::now()->getTimestamp();

        $token = $this->sign($this->claims([
            'nbf' => $now + 600,
            'exp' => $now + 1200,
        ]));

        $this->assertRejected($token, WitnessErrorCodes::ID_TOKEN_NOT_YET_VALID);
    }

    #[Test]
    public function a_token_without_exp_is_rejected(): void
    {
        // Un id_token sans expiration serait éternel. On ne « suppose » aucune
        // durée par défaut.
        $claims = $this->claims();
        unset($claims['exp']);

        $this->assertRejected($this->sign($claims), WitnessErrorCodes::MISSING_CLAIM);
    }

    // =====================================================================
    // Famille 4 — les claims requis
    // =====================================================================

    #[Test]
    public function a_token_without_sub_is_rejected(): void
    {
        $claims = $this->claims();
        unset($claims['sub']);

        $this->assertRejected($this->sign($claims), WitnessErrorCodes::MISSING_CLAIM);
    }

    #[Test]
    public function a_token_without_jti_is_rejected(): void
    {
        // Sans `jti`, l'usage unique serait impossible : accepter le jeton
        // reviendrait à renoncer silencieusement à l'anti-rejeu.
        $claims = $this->claims();
        unset($claims['jti']);

        $this->assertRejected($this->sign($claims), WitnessErrorCodes::MISSING_CLAIM);
    }

    #[Test]
    public function a_token_without_aud_is_rejected_as_missing_not_as_mismatch(): void
    {
        // Code d'erreur FIDÈLE : « absent » et « ne correspond pas » sont deux
        // diagnostics différents pour l'exploitant.
        $claims = $this->claims();
        unset($claims['aud']);

        $this->assertRejected($this->sign($claims), WitnessErrorCodes::MISSING_CLAIM);
    }

    // =====================================================================
    // Famille 5 — le `nonce` : lier le jeton à CETTE demande
    // =====================================================================

    #[Test]
    public function a_diverging_nonce_is_rejected(): void
    {
        // Le jeton est authentique, non expiré, pour le bon client — mais il
        // répond à une AUTRE demande d'autorisation. C'est exactement ce que le
        // `nonce` doit attraper.
        $token = $this->sign($this->claims(['nonce' => 'nonce-d-une-autre-demande']));

        $this->assertRejected($token, WitnessErrorCodes::NONCE_MISMATCH);
    }

    #[Test]
    public function a_missing_nonce_is_rejected_when_one_was_sent(): void
    {
        $claims = $this->claims();
        unset($claims['nonce']);

        $this->assertRejected($this->sign($claims), WitnessErrorCodes::NONCE_MISMATCH);
    }

    #[Test]
    public function the_nonce_check_is_the_only_thing_that_distinguishes_two_otherwise_identical_tokens(): void
    {
        // Contrôle POSITIF adossé au refus ci-dessus : le MÊME jeton passe dès
        // lors que le `nonce` attendu est celui qu'il porte. Sans cette
        // symétrie, `a_diverging_nonce_is_rejected` pourrait passer parce que
        // TOUT est rejeté.
        $token = $this->sign($this->claims(['nonce' => 'nonce-alternatif']));

        $claims = $this->verifier()->verify($token, $this->credentials(), $this->jwks(), 'nonce-alternatif');

        self::assertSame('nonce-alternatif', $claims['nonce']);
    }

    // =====================================================================
    // Famille 6 — l'usage unique du `jti` (l'anti-rejeu CONSTRUIT par 55.3)
    // =====================================================================

    #[Test]
    public function a_replayed_id_token_is_rejected_on_second_use(): void
    {
        $token = $this->sign($this->claims(['jti' => 'jti-a-usage-unique']));

        // Contrôle POSITIF : le PREMIER usage réussit.
        $first = $this->verifier()->verify($token, $this->credentials(), $this->jwks(), self::NONCE);
        self::assertSame('prof.dupont', $first['sub']);

        // Et le second — même jeton, mêmes claims, même signature — est refusé.
        $this->assertRejected($token, WitnessErrorCodes::JTI_REPLAYED);
    }

    #[Test]
    public function two_distinct_tokens_do_not_interfere(): void
    {
        // Sans ce contrôle, un anti-rejeu qui refuserait TOUT après le premier
        // jeton passerait le test précédent.
        $verifier = $this->verifier();

        $verifier->verify($this->sign($this->claims(['jti' => 'jti-un'])), $this->credentials(), $this->jwks(), self::NONCE);
        $second = $verifier->verify($this->sign($this->claims(['jti' => 'jti-deux'])), $this->credentials(), $this->jwks(), self::NONCE);

        self::assertSame('prof.dupont', $second['sub']);
    }

    #[Test]
    public function an_invalid_token_never_consumes_its_jti(): void
    {
        // DÉCISION (miroir de M1 de l'Epic 20) : la consommation du `jti` est le
        // DERNIER geste de la vérification. Sinon un attaquant brûlerait à
        // l'avance le `jti` d'un jeton légitime en présentant un contrefait
        // portant le même identifiant — un déni de service silencieux sur le
        // SSO d'un utilisateur précis.
        $jti = 'jti-partage-entre-les-deux-jetons';

        // Le contrefait (signé par une autre instance) est refusé…
        $this->assertRejected(
            $this->sign($this->claims(['jti' => $jti]), 'RS256', $this->foreignKeyPair()['private']),
            WitnessErrorCodes::ID_TOKEN_SIGNATURE_INVALID,
        );

        // …et le légitime, portant le MÊME `jti`, passe encore.
        $claims = $this->verifier()->verify(
            $this->sign($this->claims(['jti' => $jti])),
            $this->credentials(),
            $this->jwks(),
            self::NONCE,
        );

        self::assertSame($jti, $claims['jti']);
    }

    #[Test]
    public function the_replay_guard_fails_closed_when_its_store_is_unreachable(): void
    {
        // Doctrine D-6 : un jeton d'entrée humain ne s'accepte pas dans le
        // doute. Store inexistant ⇒ refus, jamais « on laisse passer, on
        // verra ».
        config(['oidc.witness.replay_cache_store' => 'un-store-qui-n-existe-pas']);

        $this->assertRejected($this->sign($this->claims()), WitnessErrorCodes::JTI_REPLAYED);
    }

    #[Test]
    public function the_replay_guard_consumes_a_jti_once_then_refuses_it(): void
    {
        // Le mécanisme, testé en isolation (patron
        // `FederatedJwtVerifierTest::replay_checker_consumes_jti_once_then_rejects`).
        $guard = new WitnessJtiReplayGuard();
        $exp = Carbon::now()->addMinutes(5)->getTimestamp();

        self::assertTrue($guard->consumeOnce('jti-isole', $exp), '1er usage');
        self::assertFalse($guard->consumeOnce('jti-isole', $exp), 'rejeu');
    }

    #[Test]
    public function the_replay_guard_refuses_an_already_expired_token(): void
    {
        // TTL nul ⇒ rien à mémoriser ⇒ refus (fail-closed). Un `add()` avec un
        // TTL négatif ou nul serait un no-op silencieux qui rendrait le rejeu
        // possible indéfiniment.
        $guard = new WitnessJtiReplayGuard();

        self::assertFalse($guard->consumeOnce('jti-perime', Carbon::now()->subMinutes(10)->getTimestamp()));
    }

    #[Test]
    public function the_replay_guard_never_stores_the_raw_jti_as_a_cache_key(): void
    {
        // Le `jti` est un identifiant de jeton : il n'a rien à faire en clair
        // dans une clé de cache partagée avec le reste de l'application.
        $guard = new WitnessJtiReplayGuard();

        self::assertStringNotContainsString('jti-en-clair', $guard->cacheKey('jti-en-clair'));
        self::assertStringStartsWith('oidc-witness-test:jti:', $guard->cacheKey('jti-en-clair'));
    }
}
