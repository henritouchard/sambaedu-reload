<?php

declare(strict_types=1);

namespace Tests\Feature\Oidc;

use App\Auth\Oidc\Jwt\OidcIdTokenIssuer;
use App\Auth\Oidc\Support\OidcClaimsResolver;
use App\Models\OidcClient;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Oidc\Concerns\UsesOidcTestKeys;
use Tests\TestCase;

/**
 * Story 55.1 — **AC2** : discovery et JWKS.
 *
 * Ces deux documents sont un **CONTRAT PUBLIC gelé à la première
 * publication** : une extension déployée lit la discovery une fois et met en
 * cache. Ce fichier verrouille donc leur forme.
 *
 * **Story 55.2** — l'assertion négative `userinfo_endpoint` absent (garde
 * « pas avant l'heure » de 55.1) est devenue son inverse : l'endpoint existe,
 * il doit être annoncé. La protection qu'elle portait n'est pas perdue, elle
 * est remontée d'un cran — `the_discovery_evolved_additively_from_the_55_1_contract()`
 * fige désormais CHAQUE clé publiée en 55.1 (nom ET valeur) : c'est une
 * exigence plus forte que celle qu'elle remplace (NFR11).
 *
 * Le test du JWKS ne se contente pas de comparer des chaînes : il
 * **reconstruit une clé publique RSA depuis les seuls `n` et `e` publiés**, et
 * s'en sert pour vérifier la signature d'un id_token RÉELLEMENT ÉMIS. C'est
 * exactement ce que fait un client OIDC — et la seule façon de prouver que le
 * JWKS est exploitable plutôt que bien formé.
 */
class OidcDiscoveryTest extends TestCase
{
    use RefreshDatabase;
    use UsesOidcTestKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useOidcTestKeys();
    }

    // ── AC2 — discovery ───────────────────────────────────────────────────

    #[Test]
    public function the_discovery_document_is_public_and_carries_the_expected_contract(): void
    {
        // Aucune authentification : un client OIDC standard doit pouvoir la lire.
        $response = $this->get('/.well-known/openid-configuration');

        $response->assertOk();
        $body = $response->json();

        self::assertSame($this->testIssuer, $body['issuer']);
        self::assertSame(route('oidc.authorize'), $body['authorization_endpoint']);
        self::assertSame(route('oidc.token'), $body['token_endpoint']);
        self::assertSame(route('oidc.jwks'), $body['jwks_uri']);

        self::assertSame(['code'], $body['response_types_supported']);
        self::assertSame(['authorization_code'], $body['grant_types_supported']);
        self::assertSame(['RS256'], $body['id_token_signing_alg_values_supported']);

        // PKCE : S256 SEUL. Annoncer `plain` inciterait des clients à
        // l'utiliser — et `plain` ne protège de rien.
        self::assertSame(['S256'], $body['code_challenge_methods_supported']);

        self::assertSame(
            ['client_secret_basic', 'client_secret_post'],
            $body['token_endpoint_auth_methods_supported'],
        );
    }

    /**
     * Story 55.2 — **ÉVOLUTION** de l'assertion 55.1
     * `the_discovery_document_does_not_yet_announce_userinfo`.
     *
     * Elle assertait l'ABSENCE de `userinfo_endpoint` : c'était une garde
     * « pas avant l'heure » (annoncer un endpoint inexistant fait échouer tout
     * client qui suit la discovery à la lettre). L'heure est arrivée —
     * l'endpoint existe, il DOIT être annoncé, sans quoi aucun client standard
     * ne le trouverait. La garde ne disparaît pas : elle devient l'assertion
     * INVERSE, et l'exigence de non-régression est reprise, plus forte, par
     * {@see self::the_discovery_evolved_additively_from_the_55_1_contract()}.
     */
    #[Test]
    public function the_discovery_document_announces_the_userinfo_endpoint_and_the_closed_scope_set(): void
    {
        $body = $this->get('/.well-known/openid-configuration')->assertOk()->json();

        self::assertSame(route('oidc.userinfo'), $body['userinfo_endpoint'] ?? null);

        // Ensemble FERMÉ : ce qui est annoncé est exactement ce qui est
        // accepté à l'autorisation (test miroir dans OidcAuthorizeRefusalsTest).
        self::assertSame(['openid', 'profile', 'groups'], $body['scopes_supported']);
        self::assertSame(
            OidcClaimsResolver::supportedScopes(),
            $body['scopes_supported'],
            'la discovery et le validateur de scopes lisent la MÊME source',
        );

        self::assertSame(
            ['iss', 'sub', 'aud', 'exp', 'iat', 'jti', 'nonce', 'name', 'role', 'groups'],
            $body['claims_supported'],
        );

        // NFR5 — la liste de claims annoncée est FERMÉE elle aussi : rien qui
        // ressemble à de la PII hors contrat ne doit y apparaître, même
        // « juste » comme métadonnée (un intégrateur lirait la discovery et
        // demanderait le claim).
        foreach (['email', 'given_name', 'family_name', 'picture', 'locale', 'ad_guid', 'dn'] as $forbidden) {
            self::assertNotContains($forbidden, $body['claims_supported'], 'claim hors contrat : '.$forbidden);
        }
    }

    /**
     * Story 55.2 — **NFR11 vérifié, pas seulement affirmé** : la discovery ne
     * peut évoluer QU'ADDITIVEMENT.
     *
     * Une extension déployée lit ce document une fois et le met en cache. Ce
     * test fige, clé par clé et valeur par valeur, ce que 55.1 a publié : tout
     * retrait, tout renommage et toute modification de valeur le fait échouer.
     * Les ajouts, eux, passent — c'est exactement l'asymétrie du contrat.
     */
    #[Test]
    public function the_discovery_evolved_additively_from_the_55_1_contract(): void
    {
        $body = $this->get('/.well-known/openid-configuration')->assertOk()->json();

        // Le document TEL QUE 55.1 l'a publié — recopié ici volontairement,
        // pas dérivé du code : un test qui lit la même source que
        // l'implémentation ne prouverait rien.
        $contract55_1 = [
            'issuer' => $this->testIssuer,
            'authorization_endpoint' => route('oidc.authorize'),
            'token_endpoint' => route('oidc.token'),
            'jwks_uri' => route('oidc.jwks'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'response_modes_supported' => ['query'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
        ];

        foreach ($contract55_1 as $key => $value) {
            self::assertArrayHasKey($key, $body, sprintf('clé 55.1 « %s » RETIRÉE du contrat public', $key));
            self::assertSame($value, $body[$key], sprintf('valeur 55.1 de « %s » modifiée', $key));
        }

        // `scopes_supported` et `claims_supported` sont les deux seules clés de
        // 55.1 dont la VALEUR change : elles ne peuvent que S'ÉTENDRE.
        self::assertSame(['openid'], array_values(array_intersect(['openid'], $body['scopes_supported'])));
        foreach (['iss', 'sub', 'aud', 'exp', 'iat', 'jti', 'nonce'] as $claim) {
            self::assertContains($claim, $body['claims_supported'], 'claim 55.1 retiré : '.$claim);
        }
    }

    // ── AC2 — JWKS ────────────────────────────────────────────────────────

    #[Test]
    public function the_jwks_exposes_the_public_key_in_rfc_7517_form(): void
    {
        $response = $this->get('/oidc/jwks');

        $response->assertOk();
        $keys = $response->json('keys');

        self::assertCount(1, $keys);
        $jwk = $keys[0];

        self::assertSame('RSA', $jwk['kty']);
        self::assertSame('sig', $jwk['use']);
        self::assertSame('RS256', $jwk['alg']);
        self::assertSame($this->testKid, $jwk['kid']);
        self::assertNotEmpty($jwk['n']);
        self::assertNotEmpty($jwk['e']);

        // base64url SANS padding (RFC 7515 §2) : un `=` ou un `+` casserait
        // les clients stricts.
        foreach (['n', 'e'] as $field) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $jwk[$field], 'champ '.$field);
        }

        // ⚠️ Le JWKS ne publie QUE du matériel public.
        self::assertArrayNotHasKey('d', $jwk, 'jamais l\'exposant privé');
        self::assertStringNotContainsString('PRIVATE KEY', (string) $response->getContent());
    }

    #[Test]
    public function a_key_rebuilt_from_the_published_n_and_e_verifies_a_real_id_token(): void
    {
        $client = OidcClient::factory()->create();

        // Un id_token RÉELLEMENT émis par le service (pas une fixture figée).
        $issued = app(OidcIdTokenIssuer::class)->issueIdToken($client, 'prof.dupont', 'nonce-abc');

        $jwk = $this->get('/oidc/jwks')->assertOk()->json('keys.0');

        // Reconstruction de la clé publique exactement comme le ferait un
        // client OIDC : depuis `n` et `e`, et rien d'autre.
        $pem = self::pemFromJwk((string) $jwk['n'], (string) $jwk['e']);

        $claims = (array) JWT::decode($issued['token'], new Key($pem, 'RS256'));

        self::assertSame('prof.dupont', $claims['sub']);
        self::assertSame($client->client_id, $claims['aud']);
        self::assertSame($this->testIssuer, $claims['iss']);
        self::assertSame($issued['jti'], $claims['jti']);
    }

    #[Test]
    public function a_key_rebuilt_from_a_tampered_n_does_not_verify(): void
    {
        $client = OidcClient::factory()->create();
        $issued = app(OidcIdTokenIssuer::class)->issueIdToken($client, 'prof.dupont');

        $jwk = $this->get('/oidc/jwks')->assertOk()->json('keys.0');

        // Contrôle NÉGATIF : sans lui, le test précédent pourrait passer avec
        // une reconstruction fantaisiste si la vérification ne vérifiait rien.
        $tamperedN = substr((string) $jwk['n'], 0, -4).'AAAA';
        $pem = self::pemFromJwk($tamperedN, (string) $jwk['e']);

        $this->expectException(\Firebase\JWT\SignatureInvalidException::class);
        JWT::decode($issued['token'], new Key($pem, 'RS256'));
    }

    #[Test]
    public function the_jwks_fails_closed_when_the_signing_key_is_missing(): void
    {
        // Fail-closed : un `{"keys": []}` servi en 200 serait mis en cache par
        // les clients et casserait toutes les vérifications bien après que la
        // clé ait été générée.
        config(['oidc.keys' => [$this->testKid => [
            'private' => storage_path('keys/oidc/inexistant-private.pem'),
            'public' => storage_path('keys/oidc/inexistant-public.pem'),
        ]]]);

        $response = $this->get('/oidc/jwks');

        $response->assertStatus(503);
        self::assertSame('server_error', $response->json('error'));
    }

    // ── Reconstruction d'une clé publique RSA depuis un JWK ───────────────

    /**
     * Construit un PEM `SubjectPublicKeyInfo` depuis les composantes `n`/`e`
     * d'un JWK RSA. C'est ce que fait toute bibliothèque cliente OIDC ; on le
     * refait ici à la main pour n'introduire aucune dépendance.
     */
    private static function pemFromJwk(string $n, string $e): string
    {
        $modulus = self::asn1Integer(self::base64UrlDecode($n));
        $exponent = self::asn1Integer(self::base64UrlDecode($e));

        // RSAPublicKey ::= SEQUENCE { modulus INTEGER, publicExponent INTEGER }
        $rsaPublicKey = self::asn1(0x30, $modulus.$exponent);

        // AlgorithmIdentifier ::= SEQUENCE { OID rsaEncryption, NULL }
        $algorithm = (string) hex2bin('300d06092a864886f70d0101010500');

        // SubjectPublicKeyInfo ::= SEQUENCE { algorithm, subjectPublicKey BIT STRING }
        $spki = self::asn1(0x30, $algorithm.self::asn1(0x03, "\x00".$rsaPublicKey));

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($spki), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    private static function asn1Integer(string $bytes): string
    {
        // Un INTEGER DER est signé : un premier octet ≥ 0x80 exige un 0x00 de
        // tête, sinon la valeur serait lue comme négative.
        if ($bytes !== '' && ord($bytes[0]) > 0x7F) {
            $bytes = "\x00".$bytes;
        }

        return self::asn1(0x02, $bytes);
    }

    private static function asn1(int $tag, string $value): string
    {
        return chr($tag).self::asn1Length(strlen($value)).$value;
    }

    private static function asn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF).$bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private static function base64UrlDecode(string $value): string
    {
        $padded = str_pad(strtr($value, '-_', '+/'), (int) (ceil(strlen($value) / 4) * 4), '=');

        return (string) base64_decode($padded, true);
    }
}
