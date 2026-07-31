<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SambaEdu\ExtBbb\Oidc\Credentials;
use SambaEdu\ExtBbb\Oidc\ErrorCodes;
use SambaEdu\ExtBbb\Oidc\IdTokenVerifier;
use SambaEdu\ExtBbb\Oidc\InvalidIdTokenException;
use SambaEdu\ExtBbb\Tests\Support\InMemoryReplayGuard;
use SambaEdu\ExtBbb\Tests\Support\JwtFactory;

/**
 * Story 57.1 — **LA SUITE D'ATTAQUE CLIENTE, PORTÉE DE LA STORY 55.3.**
 *
 * Un vecteur = un test NOMMÉ, et chaque refus affirme le CODE d'erreur attendu :
 * un refus « pour la mauvaise raison » est une régression silencieuse.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  LE CONTRÔLE POSITIF EST LA CONDITION DE VALIDITÉ DE TOUS LES REFUS
 *
 *  {@see self::a_nominal_id_token_is_accepted()} ouvre le fichier. Sans lui,
 *  chacun des refus ci-dessous pourrait n'être que le symptôme d'une plomberie
 *  cassée (mauvais PEM, mauvais `kid`, JWKS mal reconstruit) et ne
 *  démontrerait RIEN.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── Traçabilité (vecteur → test) ──────────────────────────────────────────
 *
 * | Vecteur | Prouvé par |
 * |---|---|
 * | `alg: none` | `alg_none_is_rejected` |
 * | Confusion HS256 avec la clé publique en secret | `hs256_algorithm_confusion_with_the_public_key_as_secret_is_rejected` |
 * | Clé d'une autre instance | `a_token_signed_by_a_foreign_key_is_rejected` |
 * | `kid` inconnu | `an_unknown_kid_is_rejected` |
 * | JWKS vide / symétrique | `an_empty_jwks_rejects_everything`, `a_jwks_entry_announcing_a_symmetric_algorithm_is_ignored` |
 * | `aud` / `iss` | `an_audience_of_another_client_is_rejected`, `an_issuer_of_another_instance_is_rejected` |
 * | `exp` / `nbf` / tolérance | `an_expired_id_token_beyond_the_leeway_is_rejected`, `an_expiry_within_the_leeway_is_still_accepted`, `a_nbf_in_the_future_beyond_the_leeway_is_rejected` |
 * | Claims manquants | `a_token_without_exp_is_rejected`, `..._sub_...`, `..._jti_...`, `..._aud_...` |
 * | `nonce` | `a_diverging_nonce_is_rejected`, `a_missing_nonce_is_rejected_when_one_was_sent` |
 * | Rejeu `jti` | `a_replayed_id_token_is_rejected_on_second_use` |
 * | Chaîne malformée | `a_garbage_string_is_rejected_as_malformed`, `a_truncated_jwt_is_rejected` |
 *
 * Les refus qui appartiennent au FOURNISSEUR (`redirect_uri` altérée, PKCE
 * absent ou `plain`, usage unique du code, secret client faux) sont couverts par
 * les suites OIDC du core : les re-tester ici fabriquerait une seconde source de
 * vérité sans rien prouver de plus.
 */
final class IdTokenVerifierTest extends TestCase
{
    private const KID = 'test-oidc-kid';

    private const ISSUER = 'https://se5.test';

    private const CLIENT_ID = 'bbb-client-id';

    private const NONCE = 'nonce-de-l-extension';

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function verifier(bool $replayAvailable = true): IdTokenVerifier
    {
        return new IdTokenVerifier(new InMemoryReplayGuard($replayAvailable));
    }

    private function credentials(?string $issuer = null, ?string $clientId = null): Credentials
    {
        return new Credentials(
            issuer: $issuer ?? self::ISSUER,
            clientId: $clientId ?? self::CLIENT_ID,
            clientSecret: 'peu-importe-ici',
            redirectUri: '/ext/bbb/oidc/callback',
        );
    }

    /** @return list<array<string, mixed>> */
    private function jwks(): array
    {
        return [JwtFactory::jwk(JwtFactory::keyPair()['public'], self::KID)];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        $now = time();

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
     * @param  list<array<string, mixed>>|null  $jwks
     */
    private function assertRejected(
        string $token,
        string $expectedCode,
        ?array $jwks = null,
        ?Credentials $credentials = null,
        string $nonce = self::NONCE,
        ?IdTokenVerifier $verifier = null,
    ): void {
        try {
            ($verifier ?? $this->verifier())->verify(
                $token,
                $credentials ?? $this->credentials(),
                $jwks ?? $this->jwks(),
                $nonce,
            );
            self::fail('Le jeton aurait dû être refusé (' . $expectedCode . ')');
        } catch (InvalidIdTokenException $e) {
            self::assertSame($expectedCode, $e->errorCode);
        }
    }

    // =====================================================================
    // LE CONTRÔLE POSITIF
    // =====================================================================

    #[Test]
    public function a_nominal_id_token_is_accepted(): void
    {
        $claims = $this->verifier()->verify(
            JwtFactory::sign($this->claims()),
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
        // me crois ». L'algorithme de vérification est écrit en dur ; l'`alg` du
        // header ne sert jamais à choisir comment vérifier.
        $this->assertRejected(
            JwtFactory::sign($this->claims(), 'none'),
            ErrorCodes::ID_TOKEN_SIGNATURE_INVALID,
        );
    }

    #[Test]
    public function hs256_algorithm_confusion_with_the_public_key_as_secret_is_rejected(): void
    {
        // La clé publique est connue de tous — elle est AU JWKS. Un vérificateur
        // qui déduirait l'algorithme du header l'utiliserait comme secret HMAC,
        // et signerait n'importe quel jeton pour n'importe quel utilisateur.
        $this->assertRejected(
            JwtFactory::sign($this->claims(), 'HS256', JwtFactory::keyPair()['public']),
            ErrorCodes::ID_TOKEN_SIGNATURE_INVALID,
        );
    }

    #[Test]
    public function a_token_signed_by_a_foreign_key_is_rejected(): void
    {
        // Le collège voisin signe un jeton dont TOUS les claims sont ceux que
        // nous attendons. Seule la clé diffère.
        $this->assertRejected(
            JwtFactory::sign($this->claims(), 'RS256', JwtFactory::keyPair('foreign')['private']),
            ErrorCodes::ID_TOKEN_SIGNATURE_INVALID,
        );
    }

    #[Test]
    public function a_foreign_key_published_under_our_kid_still_fails(): void
    {
        // Variante plus vicieuse : l'attaquant publie sa clé sous NOTRE `kid`.
        $foreign = JwtFactory::keyPair('foreign');
        $token = JwtFactory::sign($this->claims(), 'RS256', $foreign['private']);

        $this->assertRejected($token, ErrorCodes::ID_TOKEN_SIGNATURE_INVALID);

        // Et si le JWKS était CELUI DE L'ATTAQUANT, le jeton passerait — ce qui
        // démontre que la sécurité repose sur la PROVENANCE du JWKS
        // (`{issuer}/.well-known/...`, en HTTP direct), jamais sur le jeton.
        $claims = $this->verifier()->verify(
            $token,
            $this->credentials(),
            [JwtFactory::jwk($foreign['public'], self::KID)],
            self::NONCE,
        );

        self::assertSame('prof.dupont', $claims['sub']);
    }

    #[Test]
    public function an_unknown_kid_is_rejected(): void
    {
        $this->assertRejected(
            JwtFactory::sign($this->claims(), 'RS256', null, 'kid-inconnu'),
            ErrorCodes::ID_TOKEN_SIGNATURE_INVALID,
        );
    }

    #[Test]
    public function an_empty_jwks_rejects_everything(): void
    {
        // Fail-closed : ne rien pouvoir vérifier n'est pas une raison
        // d'accepter. Le jeton est pourtant PARFAITEMENT valide.
        $this->assertRejected(
            JwtFactory::sign($this->claims()),
            ErrorCodes::JWKS_UNUSABLE,
            jwks: [],
        );
    }

    #[Test]
    public function a_jwks_entry_announcing_a_symmetric_algorithm_is_ignored(): void
    {
        $jwk = JwtFactory::jwk(JwtFactory::keyPair()['public'], self::KID);
        $jwk['alg'] = 'HS256';

        $this->assertRejected(
            JwtFactory::sign($this->claims()),
            ErrorCodes::JWKS_UNUSABLE,
            jwks: [$jwk],
        );
    }

    #[Test]
    public function a_garbage_string_is_rejected_as_malformed(): void
    {
        $this->assertRejected('ceci n\'est pas un jeton', ErrorCodes::ID_TOKEN_MALFORMED);
    }

    #[Test]
    public function a_truncated_jwt_is_rejected(): void
    {
        $token = JwtFactory::sign($this->claims());
        $truncated = substr($token, 0, (int) (strlen($token) * 0.6));

        try {
            $this->verifier()->verify($truncated, $this->credentials(), $this->jwks(), self::NONCE);
            self::fail('Un JWT tronqué doit être refusé');
        } catch (InvalidIdTokenException $e) {
            // SEUL test du fichier à accepter deux codes, et c'est délibéré : le
            // point de troncature à 60 % tombe AVANT ou APRÈS le second point
            // selon la longueur des claims. Figer un code unique exigerait de
            // figer la longueur du payload. La latitude reste étroite — un
            // basculement vers `MISSING_CLAIM`, `EXPIRED` ou une acceptation
            // ferait toujours rougir ce test.
            self::assertContains($e->errorCode, [
                ErrorCodes::ID_TOKEN_MALFORMED,
                ErrorCodes::ID_TOKEN_SIGNATURE_INVALID,
            ]);
        }
    }

    #[Test]
    public function every_signature_failure_reports_the_same_error_code(): void
    {
        // LA règle du témoin qu'une vraie extension DOIT reprendre : `alg: none`,
        // confusion d'algorithme, clé étrangère et `kid` inconnu sont, vus de
        // l'extérieur, quatre façons de ne pas savoir signer. Les distinguer
        // offrirait un oracle gratuit à qui tâtonne.
        $codes = [];

        foreach ([
            JwtFactory::sign($this->claims(), 'none'),
            JwtFactory::sign($this->claims(), 'HS256', JwtFactory::keyPair()['public']),
            JwtFactory::sign($this->claims(), 'RS256', JwtFactory::keyPair('foreign')['private']),
            JwtFactory::sign($this->claims(), 'RS256', null, 'kid-inconnu'),
        ] as $token) {
            try {
                $this->verifier()->verify($token, $this->credentials(), $this->jwks(), self::NONCE);
                self::fail('Ce jeton aurait dû être refusé');
            } catch (InvalidIdTokenException $e) {
                $codes[] = $e->errorCode;
            }
        }

        self::assertSame([ErrorCodes::ID_TOKEN_SIGNATURE_INVALID], array_values(array_unique($codes)));
    }

    // =====================================================================
    // Famille 2 — l'instance : `aud`, `iss`
    // =====================================================================

    #[Test]
    public function an_audience_of_another_client_is_rejected(): void
    {
        // Même instance, même clé, mais jeton émis pour une AUTRE extension.
        // Sans ce contrôle, une extension rejouerait chez sa voisine le jeton
        // qu'elle a légitimement reçu.
        $this->assertRejected(
            JwtFactory::sign($this->claims(['aud' => 'un-autre-client-de-la-meme-instance'])),
            ErrorCodes::AUD_MISMATCH,
        );
    }

    #[Test]
    public function an_issuer_of_another_instance_is_rejected(): void
    {
        $this->assertRejected(
            JwtFactory::sign($this->claims(['iss' => 'https://se5.college-voisin.test'])),
            ErrorCodes::ISS_MISMATCH,
        );
    }

    #[Test]
    public function an_aud_array_containing_our_client_id_is_accepted(): void
    {
        // RFC 7519 : `aud` peut être un tableau. Contrôle POSITIF de la
        // tolérance — sans lui, le refus ci-dessus pourrait n'être que le
        // symptôme d'un `aud` tableau systématiquement rejeté.
        $claims = $this->verifier()->verify(
            JwtFactory::sign($this->claims(['aud' => ['un-autre-client', self::CLIENT_ID]])),
            $this->credentials(),
            $this->jwks(),
            self::NONCE,
        );

        self::assertSame(['un-autre-client', self::CLIENT_ID], (array) $claims['aud']);
    }

    #[Test]
    public function an_aud_array_without_our_client_id_is_rejected(): void
    {
        $this->assertRejected(
            JwtFactory::sign($this->claims(['aud' => ['client-a', 'client-b']])),
            ErrorCodes::AUD_MISMATCH,
        );
    }

    #[Test]
    public function an_empty_client_id_never_makes_the_audience_check_permissive(): void
    {
        // Défense en profondeur : `hash_equals('', '')` vaut `true`. Si l'
        // environnement livrait un `client_id` vide, l'audience deviendrait
        // silencieusement universelle.
        $this->assertRejected(
            JwtFactory::sign($this->claims()),
            ErrorCodes::ID_TOKEN_MALFORMED,
            credentials: $this->credentials(clientId: ''),
        );
    }

    // =====================================================================
    // Famille 3 — le temps
    // =====================================================================

    #[Test]
    public function an_expired_id_token_beyond_the_leeway_is_rejected(): void
    {
        $now = time();

        $this->assertRejected(
            JwtFactory::sign($this->claims(['iat' => $now - 1800, 'exp' => $now - 600])),
            ErrorCodes::ID_TOKEN_EXPIRED,
        );
    }

    #[Test]
    public function an_expiry_within_the_leeway_is_still_accepted(): void
    {
        // CONTRÔLE POSITIF de la tolérance d'horloge (60 s) : sans lui, le refus
        // ci-dessus prouverait seulement qu'on rejette tout ce qui est passé, et
        // la moindre dérive d'horloge casserait le SSO en production.
        $now = time();

        $claims = $this->verifier()->verify(
            JwtFactory::sign($this->claims(['iat' => $now - 600, 'exp' => $now - 30])),
            $this->credentials(),
            $this->jwks(),
            self::NONCE,
        );

        self::assertSame('prof.dupont', $claims['sub']);
    }

    #[Test]
    public function a_nbf_in_the_future_beyond_the_leeway_is_rejected(): void
    {
        $now = time();

        $this->assertRejected(
            JwtFactory::sign($this->claims(['nbf' => $now + 600, 'exp' => $now + 1200])),
            ErrorCodes::ID_TOKEN_NOT_YET_VALID,
        );
    }

    #[Test]
    public function a_token_without_exp_is_rejected(): void
    {
        // Un id_token sans expiration serait éternel. Aucune durée par défaut
        // n'est supposée.
        $claims = $this->claims();
        unset($claims['exp']);

        $this->assertRejected(JwtFactory::sign($claims), ErrorCodes::MISSING_CLAIM);
    }

    // =====================================================================
    // Famille 4 — les claims requis
    // =====================================================================

    #[Test]
    public function a_token_without_sub_is_rejected(): void
    {
        $claims = $this->claims();
        unset($claims['sub']);

        $this->assertRejected(JwtFactory::sign($claims), ErrorCodes::MISSING_CLAIM);
    }

    #[Test]
    public function a_token_without_jti_is_rejected(): void
    {
        // Sans `jti`, l'usage unique serait impossible : accepter reviendrait à
        // renoncer silencieusement à l'anti-rejeu.
        $claims = $this->claims();
        unset($claims['jti']);

        $this->assertRejected(JwtFactory::sign($claims), ErrorCodes::MISSING_CLAIM);
    }

    #[Test]
    public function a_token_without_aud_is_rejected_as_missing_not_as_mismatch(): void
    {
        // Code d'erreur FIDÈLE : « absent » et « ne correspond pas » sont deux
        // diagnostics différents pour l'exploitant.
        $claims = $this->claims();
        unset($claims['aud']);

        $this->assertRejected(JwtFactory::sign($claims), ErrorCodes::MISSING_CLAIM);
    }

    // =====================================================================
    // Famille 5 — le `nonce`
    // =====================================================================

    #[Test]
    public function a_diverging_nonce_is_rejected(): void
    {
        // Le jeton est authentique, non expiré, pour le bon client — mais il
        // répond à une AUTRE demande d'autorisation.
        $this->assertRejected(
            JwtFactory::sign($this->claims(['nonce' => 'nonce-d-une-autre-demande'])),
            ErrorCodes::NONCE_MISMATCH,
        );
    }

    #[Test]
    public function a_missing_nonce_is_rejected_when_one_was_sent(): void
    {
        $claims = $this->claims();
        unset($claims['nonce']);

        $this->assertRejected(JwtFactory::sign($claims), ErrorCodes::NONCE_MISMATCH);
    }

    #[Test]
    public function the_nonce_check_is_the_only_thing_that_distinguishes_two_otherwise_identical_tokens(): void
    {
        // Contrôle POSITIF adossé au refus ci-dessus : le MÊME jeton passe dès
        // lors que le `nonce` attendu est celui qu'il porte.
        $claims = $this->verifier()->verify(
            JwtFactory::sign($this->claims(['nonce' => 'nonce-alternatif'])),
            $this->credentials(),
            $this->jwks(),
            'nonce-alternatif',
        );

        self::assertSame('nonce-alternatif', $claims['nonce']);
    }

    // =====================================================================
    // Famille 6 — l'usage unique du `jti`
    // =====================================================================

    #[Test]
    public function a_replayed_id_token_is_rejected_on_second_use(): void
    {
        $verifier = $this->verifier();
        $token = JwtFactory::sign($this->claims(['jti' => 'jti-a-usage-unique']));

        // Contrôle POSITIF : le PREMIER usage réussit.
        $first = $verifier->verify($token, $this->credentials(), $this->jwks(), self::NONCE);
        self::assertSame('prof.dupont', $first['sub']);

        // Et le second — même jeton, mêmes claims, même signature — est refusé.
        $this->assertRejected($token, ErrorCodes::JTI_REPLAYED, verifier: $verifier);
    }

    #[Test]
    public function two_distinct_tokens_do_not_interfere(): void
    {
        // Sans ce contrôle, un anti-rejeu qui refuserait TOUT après le premier
        // jeton passerait le test précédent.
        $verifier = $this->verifier();

        $verifier->verify(JwtFactory::sign($this->claims(['jti' => 'jti-un'])), $this->credentials(), $this->jwks(), self::NONCE);
        $second = $verifier->verify(JwtFactory::sign($this->claims(['jti' => 'jti-deux'])), $this->credentials(), $this->jwks(), self::NONCE);

        self::assertSame('prof.dupont', $second['sub']);
    }

    #[Test]
    public function an_invalid_token_never_consumes_its_jti(): void
    {
        // La consommation du `jti` est le DERNIER geste de la vérification.
        // Sinon un attaquant brûlerait à l'avance le `jti` d'un jeton légitime
        // en présentant un contrefait portant le même identifiant — un déni de
        // service silencieux sur la connexion d'un utilisateur précis.
        $verifier = $this->verifier();
        $jti = 'jti-partage-entre-les-deux-jetons';

        $this->assertRejected(
            JwtFactory::sign($this->claims(['jti' => $jti]), 'RS256', JwtFactory::keyPair('foreign')['private']),
            ErrorCodes::ID_TOKEN_SIGNATURE_INVALID,
            verifier: $verifier,
        );

        $claims = $verifier->verify(
            JwtFactory::sign($this->claims(['jti' => $jti])),
            $this->credentials(),
            $this->jwks(),
            self::NONCE,
        );

        self::assertSame($jti, $claims['jti']);
    }

    #[Test]
    public function the_replay_guard_fails_closed_when_its_store_is_unreachable(): void
    {
        // Un jeton d'entrée humain ne s'accepte pas dans le doute : magasin
        // indisponible ⇒ refus, jamais « on laisse passer, on verra ».
        $this->assertRejected(
            JwtFactory::sign($this->claims()),
            ErrorCodes::JTI_REPLAYED,
            verifier: $this->verifier(replayAvailable: false),
        );
    }
}
