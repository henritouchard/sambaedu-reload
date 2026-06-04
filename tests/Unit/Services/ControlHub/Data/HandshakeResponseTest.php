<?php

namespace Tests\Unit\Services\ControlHub\Data;

use App\Services\ControlHub\Data\ApiResponse;
use App\Services\ControlHub\Data\HandshakeResponse;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Parsing de la réponse handshake controlHub, en particulier le bloc
 * optionnel idp_federated (clé publique SSO fédéré).
 *
 * Invariant : un bloc absent ou invalide ne fait JAMAIS échouer le handshake
 * (un controlHub pas encore à jour ne doit rien casser) — il est juste
 * ignoré (idpFederated = null) avec un warning loggé.
 */
class HandshakeResponseTest extends TestCase
{
    /** Vraie clé publique RSA 2048 (jetable, générée pour les tests) : la validation passe par openssl_pkey_get_public() */
    private const PEM = "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAsdU1yPY6GRH7C6I4CvHG\n13pg6mlvDKHEfjFK/DgTxrOO9bDaCiZVfnHbHegUXmvG+C9pc4JqW4rz4s05nCBO\n0VzWwgNsx33Y7wgmdLqwjSjyfaEeUB+FR1gLN2bLvqbV/WJBZtCn2c67mUL3AhKk\nkY/u2+sbRgHQAztEkR9RcKFw7MTT6GVAhJn55ZHaLZsDhtkYjenuOa5cP8moXnsd\n8lDkgjrNOesM+/wEZ4GxeTvM3zy0iwkxjTwMx0p8Gszu+uaOltU6i+4YhDQN255c\nMfgA3ANcMmndSuq6l+Qr8e9jxau01s+jLfG9fJI9IZr6W65uWMuBn0TPaQkLmabt\nqwIDAQAB\n-----END PUBLIC KEY-----\n";

    private function validIdpBlock(): array
    {
        return [
            'public_key' => self::PEM,
            'kid' => 'irundo-federated-key-1',
            'iss' => 'https://central.exemple.fr',
        ];
    }

    private function apiResponse(array $instance, array $extraData = []): ApiResponse
    {
        return ApiResponse::success([
            'data' => array_merge(['instance' => $instance], $extraData),
        ]);
    }

    #[Test]
    public function parse_le_bloc_idp_federated_dans_instance(): void
    {
        $response = HandshakeResponse::fromApiResponse($this->apiResponse([
            'api_token' => 'tok_123',
            'heartbeat_interval' => 120,
            'idp_federated' => $this->validIdpBlock(),
        ]));

        $this->assertTrue($response->success);
        $this->assertNotNull($response->idpFederated);
        $this->assertSame(self::PEM, $response->idpFederated['public_key']);
        $this->assertSame('irundo-federated-key-1', $response->idpFederated['kid']);
        $this->assertSame('https://central.exemple.fr', $response->idpFederated['iss']);
    }

    #[Test]
    public function parse_le_bloc_idp_federated_au_niveau_data_en_fallback(): void
    {
        // Tolérance sur l'emplacement : le contrat controlHub peut placer le bloc
        // dans data.idp_federated (frère d'instance) plutôt que data.instance.
        // NB : la racine HTTP du body (hors data) n'est PAS couverte.
        $response = HandshakeResponse::fromApiResponse($this->apiResponse(
            ['api_token' => 'tok_123'],
            ['idp_federated' => $this->validIdpBlock()]
        ));

        $this->assertTrue($response->success);
        $this->assertNotNull($response->idpFederated);
        $this->assertSame('irundo-federated-key-1', $response->idpFederated['kid']);
    }

    #[Test]
    public function handshake_reussit_sans_bloc_idp_federated(): void
    {
        // controlHub pas encore à jour : pas de bloc → SSO indisponible, handshake OK
        $response = HandshakeResponse::fromApiResponse($this->apiResponse([
            'api_token' => 'tok_123',
        ]));

        $this->assertTrue($response->success);
        $this->assertNull($response->idpFederated);
    }

    #[Test]
    public function bloc_avec_public_key_non_pem_est_ignore_sans_faire_echouer_le_handshake(): void
    {
        Log::shouldReceive('warning')->once();

        $response = HandshakeResponse::fromApiResponse($this->apiResponse([
            'api_token' => 'tok_123',
            'idp_federated' => array_merge($this->validIdpBlock(), [
                'public_key' => 'pas-un-pem',
            ]),
        ]));

        $this->assertTrue($response->success);
        $this->assertNull($response->idpFederated);
    }

    #[Test]
    public function bloc_avec_pem_corrompu_est_ignore_meme_avec_le_bon_en_tete(): void
    {
        Log::shouldReceive('warning')->once();

        // En-tête PEM correct mais corps base64 invalide : seul openssl peut le détecter
        $response = HandshakeResponse::fromApiResponse($this->apiResponse([
            'api_token' => 'tok_123',
            'idp_federated' => array_merge($this->validIdpBlock(), [
                'public_key' => "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA...\n-----END PUBLIC KEY-----\n",
            ]),
        ]));

        $this->assertTrue($response->success);
        $this->assertNull($response->idpFederated);
    }

    #[Test]
    public function bloc_avec_kid_vide_est_ignore(): void
    {
        Log::shouldReceive('warning')->once();

        $response = HandshakeResponse::fromApiResponse($this->apiResponse([
            'api_token' => 'tok_123',
            'idp_federated' => array_merge($this->validIdpBlock(), ['kid' => '  ']),
        ]));

        $this->assertTrue($response->success);
        $this->assertNull($response->idpFederated);
    }

    #[Test]
    public function bloc_avec_iss_non_url_est_ignore(): void
    {
        Log::shouldReceive('warning')->once();

        $response = HandshakeResponse::fromApiResponse($this->apiResponse([
            'api_token' => 'tok_123',
            'idp_federated' => array_merge($this->validIdpBlock(), ['iss' => 'central']),
        ]));

        $this->assertTrue($response->success);
        $this->assertNull($response->idpFederated);
    }

    #[Test]
    public function bloc_qui_nest_pas_un_objet_est_ignore(): void
    {
        Log::shouldReceive('warning')->once();

        $response = HandshakeResponse::fromApiResponse($this->apiResponse([
            'api_token' => 'tok_123',
            'idp_federated' => 'oui',
        ]));

        $this->assertTrue($response->success);
        $this->assertNull($response->idpFederated);
    }

    #[Test]
    public function from_array_parse_aussi_le_bloc_idp_federated(): void
    {
        $response = HandshakeResponse::fromArray([
            'instance' => [
                'api_token' => 'tok_123',
                'idp_federated' => $this->validIdpBlock(),
            ],
        ]);

        $this->assertTrue($response->success);
        $this->assertNotNull($response->idpFederated);
        $this->assertSame('https://central.exemple.fr', $response->idpFederated['iss']);
    }

    #[Test]
    public function reponse_api_en_echec_reste_un_handshake_echoue(): void
    {
        $response = HandshakeResponse::fromApiResponse(
            ApiResponse::failed('Connection refused', 502)
        );

        $this->assertFalse($response->success);
        $this->assertNull($response->idpFederated);
    }

    #[Test]
    public function kid_et_iss_sont_trimmes_mais_iss_nest_pas_normalise(): void
    {
        $response = HandshakeResponse::fromApiResponse($this->apiResponse([
            'api_token' => 'tok_123',
            'idp_federated' => [
                'public_key' => self::PEM,
                'kid' => '  irundo-federated-key-1  ',
                // Slash final conservé : la vérification JWT comparera le claim
                // iss à cette valeur exacte, on ne normalise pas.
                'iss' => ' https://central.exemple.fr/ ',
            ],
        ]));

        $this->assertSame('irundo-federated-key-1', $response->idpFederated['kid']);
        $this->assertSame('https://central.exemple.fr/', $response->idpFederated['iss']);
    }
}
