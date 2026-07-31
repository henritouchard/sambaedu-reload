<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Oidc;

use Throwable;

/**
 * Story 57.1 — Le transport HTTP réel, **borné de partout**.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  POURQUOI LES BORNES SONT ICI LA GARDE DE CONCEPTION
 *
 *  L'extension est servie par le serveur HTTP intégré de PHP (décision D2),
 *  mono-processus par défaut — `PHP_CLI_SERVER_WORKERS=4` n'en atténue l'effet
 *  que d'un facteur 4. **Aucun appel sortant sans borne** : un fournisseur
 *  lent, un JWKS qui pend, et c'est toute l'extension qui gèle, y compris sa
 *  sonde de santé. Connexion 5 s, total 15 s, redirections REFUSÉES, corps
 *  borné à 256 Kio, protocoles limités à http/https.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `allow_redirects = false` n'est pas une commodité : une 3xx sur un endpoint de
 * protocole est une anomalie, pas un chemin alternatif. La suivre exposerait à
 * une redirection vers un hôte interne (SSRF).
 */
final class CurlHttpClient implements JsonHttpClient
{
    public const CONNECT_TIMEOUT_SECONDS = 5;

    public const TOTAL_TIMEOUT_SECONDS = 15;

    /** 256 Kio : un document de découverte ou un JWKS pèse quelques kilo-octets. */
    public const MAX_BODY_BYTES = 262144;

    public function getJson(string $url): array
    {
        [$status, $body] = $this->send($url, null, '', '');

        if ($status !== 200) {
            throw OidcException::of(
                ErrorCodes::DISCOVERY_UNAVAILABLE,
                sprintf('GET %s a répondu %d', $url, $status),
            );
        }

        return $this->decode($url, $body);
    }

    public function postForm(string $url, array $fields, string $basicUser, string $basicPassword): array
    {
        [$status, $body] = $this->send($url, http_build_query($fields, '', '&', PHP_QUERY_RFC3986), $basicUser, $basicPassword);

        // Le statut est rendu à l'appelant : un `400 {"error":"invalid_grant"}`
        // est une réponse de protocole parfaitement exploitable, pas une panne
        // de transport.
        return ['status' => $status, 'body' => $this->decodeTolerant($body) ?? []];
    }

    /**
     * @return array{0: int, 1: string}
     *
     * @throws OidcException
     */
    private function send(string $url, ?string $postBody, string $basicUser, string $basicPassword): array
    {
        $handle = curl_init();

        if ($handle === false) {
            throw OidcException::of(ErrorCodes::DISCOVERY_UNAVAILABLE, 'cURL indisponible');
        }

        $received = '';

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION => static function ($_handle, string $chunk) use (&$received): int {
                $received .= $chunk;

                // Rendre moins que la taille reçue interrompt le transfert :
                // c'est la borne de taille, appliquée pendant la lecture et non
                // après (sinon la borne ne borne rien).
                return strlen($received) > self::MAX_BODY_BYTES ? 0 : strlen($chunk);
            },
        ];

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        } elseif (defined('CURLPROTO_HTTP')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        if ($postBody !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $postBody;
            $options[CURLOPT_HTTPHEADER] = [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ];
        }

        if ($basicUser !== '') {
            // RFC 6749 §2.3.1 : les deux composantes sont urlencodées AVANT
            // l'encodage base64. Le fournisseur les `urldecode` symétriquement.
            $options[CURLOPT_HTTPHEADER][] = 'Authorization: Basic ' . base64_encode(
                rawurlencode($basicUser) . ':' . rawurlencode($basicPassword)
            );
        }

        curl_setopt_array($handle, $options);

        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($ok === false && $status === 0) {
            throw OidcException::of(
                ErrorCodes::DISCOVERY_UNAVAILABLE,
                sprintf('appel HTTP impossible vers %s : %s', $url, $error),
            );
        }

        return [$status, $received];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws OidcException
     */
    private function decode(string $url, string $body): array
    {
        $decoded = $this->decodeTolerant($body);

        if ($decoded === null) {
            throw OidcException::of(ErrorCodes::DISCOVERY_UNAVAILABLE, sprintf('GET %s : réponse non-JSON', $url));
        }

        return $decoded;
    }

    /** @return array<string, mixed>|null */
    private function decodeTolerant(string $body): ?array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
