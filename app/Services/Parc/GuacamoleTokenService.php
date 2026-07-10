<?php

declare(strict_types=1);

namespace App\Services\Parc;

use App\Config\SambaEduConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Génération des tokens d'accès distant Guacamole — port natif 1:1 des
 * fonctions legacy `remote.inc.php` (`create_remote_token`,
 * `create_remote_json_connection`, `encrypt_json_token`,
 * `get_guacamole_auth_token`, `guacamole_url`).
 *
 * Story 38.4 (AC2) — sortie du `require` FS legacy de {@see RemoteAccessService}.
 * `feedback_guacamole_scope` : **porter, pas refondre** — le token JSON chiffré
 * (extension guacamole-auth-json du fork interne `sambaedu-guacamole` 1.6.0) est
 * le CONTRAT, reproduit à l'identique :
 *  - JSON `{username, expires(ms), connections}` construit par type de connexion ;
 *  - chiffrement AES-128-CBC, IV nul (16 octets à zéro), clé `guac_priv_key`
 *    (hex → binaire), HMAC-SHA256 préfixé, base64 (iso `encrypt_json_token`) ;
 *  - POST `<guacamole_url>/api/tokens` (form-param `data`) → `authToken` ;
 *  - URL finale `<guacamole_url>#/?token=<authToken>`.
 *
 * Config lue via {@see SambaEduConfig} (`/etc/sambaedu/sambaedu.conf.d/guacamole.conf`
 * + `sambaedu.conf`) — plus AUCUN `get_config()` legacy.
 */
class GuacamoleTokenService
{
    /** IV constant de 16 octets à zéro (parité bash/legacy `NULL_IV_HEX`). */
    private const NULL_IV_HEX = '00000000000000000000000000000000';

    public function __construct(
        private readonly SambaEduConfig $config,
    ) {}

    /** L'accès distant Guacamole est-il configuré ? (parité isRemoteAccessAvailable) */
    public function isAvailable(): bool
    {
        $url = (string) $this->config->get('guacamole_url', '');

        return $url !== '' && file_exists('/etc/sambaedu/sambaedu.conf.d/guacamole.conf');
    }

    /**
     * Génère l'URL de connexion Guacamole pour une machine — port
     * `create_remote_token`. Retourne `null` en cas d'échec (jamais d'exception
     * propagée : parité du wrapping legacy côté RemoteAccessService).
     *
     * @param  array<string,mixed>  $machine  Objet machine legacy-like
     *         (au minimum `cn`, optionnellement `etab`, `open`, `user`).
     * @param  string  $type  rdp|ssh|veyon|master|vnc
     */
    public function createRemoteToken(array $machine, string $type, string $user, string $password = '', int $timeout = 7200): ?string
    {
        $guacamoleUrl = (string) $this->config->get('guacamole_url', '');
        if ($guacamoleUrl === '' || ! isset($machine['cn'])) {
            return null;
        }

        try {
            $connections = $this->buildConnections($machine, $user, $password, $type);
            $token = [
                'username' => $user,
                'expires' => (time() + $timeout) * 1000,
                'connections' => $connections,
            ];
            $json = json_encode($token);
            if ($json === false) {
                return null;
            }

            $encrypted = $this->encryptJsonToken($json);
            if ($encrypted === null) {
                return null;
            }

            $authToken = $this->requestAuthToken($encrypted, (string) ($machine['etab'] ?? ''));
            if ($authToken === null) {
                return null;
            }

            return $this->guacamoleBaseUrl((string) ($machine['etab'] ?? '')) . '#/?token=' . $authToken;
        } catch (\Throwable $e) {
            Log::error('[GuacamoleTokenService] Échec génération token', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Construit le tableau de connexions Guacamole selon le type — port
     * `create_remote_json_connection` (paramètres iso-legacy).
     *
     * @param  array<string,mixed>  $machine
     * @return array<string, array{protocol:string, parameters:array<string,mixed>}>
     */
    private function buildConnections(array $machine, string $user, string $password, string $type): array
    {
        $cn = (string) ($machine['cn'] ?? '');
        $sambaDomain = (string) $this->config->get('samba_domain', '');
        $vncPassword = (string) $this->config->get('vnc_password', '');

        // Résolution du type effective (parité legacy).
        if (preg_match('/se4[fs|ad]/', $cn) === 1) {
            $type = 'ssh';
        } elseif ($vncPassword !== '' && empty($machine['open'])
            && ! empty($machine['user'][0]['name']) && $machine['user'][0]['name'] !== 'wpkg') {
            $type = 'vnc';
        }

        $rdpParams = [
            'hostname' => $cn,
            'port' => 3389,
            'password' => $password,
            'username' => $user,
            'domain' => $sambaDomain,
            'server-layout' => 'fr-fr-azerty',
            'ignore-cert' => true,
            'enable-font-smoothing' => true,
            'resize-method' => 'display-update',
            'enable-printing' => true,
            'printer-name' => 'Guacamole',
        ];

        switch ($type) {
            case 'ssh':
                if (! file_exists('/etc/sambaedu/id_rsa.pem') && file_exists('/etc/sambaedu/id_rsa')) {
                    @copy('/etc/sambaedu/id_rsa', '/etc/sambaedu/id_rsa.pem');
                    @exec('sudo ssh-keygen -p -N "" -m PEM -f /etc/sambaedu/id_rsa.pem');
                }

                return ['ssh root sur ' . $cn => [
                    'protocol' => 'ssh',
                    'parameters' => [
                        'hostname' => $cn,
                        'port' => 22,
                        'username' => 'root',
                        'private-key' => @file_get_contents('/etc/sambaedu/id_rsa.pem') ?: '',
                    ],
                ]];

            case 'vnc':
                return ['vnc sur ' . $cn => [
                    'protocol' => 'vnc',
                    'parameters' => [
                        'hostname' => $cn,
                        'port' => 5900,
                        'password' => $vncPassword,
                    ],
                ]];

            case 'master':
                return ['master sur ' . $cn => [
                    'protocol' => 'rdp',
                    'parameters' => $rdpParams + ['remote-app' => '||Veyon Master'],
                ]];

            case 'veyon':
                return ['veyon sur ' . $cn => [
                    'protocol' => 'rdp',
                    'parameters' => $rdpParams + ['remote-app' => '||Veyon Poste'],
                ]];

            case 'rdp':
            default:
                return ['rdp sur ' . $cn => [
                    'protocol' => 'rdp',
                    'parameters' => $rdpParams,
                ]];
        }
    }

    /**
     * Chiffre le JSON pour l'extension guacamole-auth-json — port
     * `encrypt_json_token` (AES-128-CBC, IV nul, HMAC-SHA256 préfixé, base64).
     * Retourne `null` si la clé est invalide.
     */
    private function encryptJsonToken(string $json): ?string
    {
        $privKeyHex = (string) $this->config->get('guac_priv_key', '');
        if ($privKeyHex === '') {
            // Legacy générait la clé si absente ; ici on refuse proprement
            // (la génération est une opération d'admin serveur, hors token).
            Log::warning('[GuacamoleTokenService] guac_priv_key absente — token non chiffrable.');

            return null;
        }

        $key = @hex2bin($privKeyHex);
        $iv = @hex2bin(self::NULL_IV_HEX);
        if ($key === false || $iv === false) {
            Log::error('[GuacamoleTokenService] guac_priv_key/IV invalide (hex attendu).');

            return null;
        }

        // Tronque à 16 octets si nécessaire (parité comportement legacy AES-128).
        if (strlen($key) > 16) {
            $key = substr($key, 0, 16);
        }

        $hmac = hash_hmac('sha256', $json, $key, true);
        $signed = $hmac . $json;

        $cipher = openssl_encrypt($signed, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            Log::error('[GuacamoleTokenService] chiffrement AES échoué.');

            return null;
        }

        return base64_encode($cipher);
    }

    /**
     * POST `<guacamole_url>/api/tokens` avec le token chiffré — port
     * `get_guacamole_auth_token`. Retourne l'`authToken` ou `null`.
     */
    private function requestAuthToken(string $data, string $etab): ?string
    {
        $baseUrl = $this->guacamoleBaseUrl($etab);

        try {
            $response = Http::asForm()
                ->withOptions(['cookies' => true])
                ->post(rtrim($baseUrl, '/') . '/api/tokens', ['data' => $data]);

            $body = $response->json();

            return is_array($body) && isset($body['authToken']) ? (string) $body['authToken'] : null;
        } catch (\Throwable $e) {
            Log::error('[GuacamoleTokenService] appel /api/tokens échoué', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * URL de base Guacamole selon l'origine (étab) — port `guacamole_url`.
     */
    private function guacamoleBaseUrl(string $etab): string
    {
        $url = (string) $this->config->get('guacamole_url', '');
        $etabOu = (string) $this->config->get('etab_ou', '');

        if (($etabOu !== '' && $etab !== $etabOu) || ($etab !== '' && ! str_contains($url, $etab))) {
            $url = (string) preg_replace('#/guacamole/#', '/' . $etab . '/guacamole/', $url);

            return $url;
        }

        return str_ends_with($url, '/') ? $url : $url . '/';
    }
}
