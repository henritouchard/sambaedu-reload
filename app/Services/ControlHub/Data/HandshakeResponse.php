<?php

namespace App\Services\ControlHub\Data;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

readonly class HandshakeResponse
{
    public function __construct(
        public bool $success,
        public ?string $apiToken = null,
        public ?int $heartbeatInterval = null,
        public ?Carbon $expiresAt = null,
        public ?string $message = null,
        public ?array $instance = null,
        /** Bloc SSO fédéré validé : ['public_key' => PEM, 'kid' => string, 'iss' => string], ou null si absent/invalide */
        public ?array $idpFederated = null
    ) {}

    public static function fromApiResponse(ApiResponse $apiResponse): self
    {
        if (!$apiResponse->isSuccessful()) {
            return self::failed($apiResponse->message ?? 'API request failed');
        }

        $data = $apiResponse->data['data'] ?? [];

        if (!isset($data['instance']['api_token'])) {
            return self::failed('Invalid response from ControlHub: missing api_token');
        }

        $expiresAt = null;
        if (isset($data['instance']['expires_at'])) {
            $expiresAt = Carbon::parse($data['instance']['expires_at']);
        }

        return new self(
            success: true,
            apiToken: $data['instance']['api_token'],
            heartbeatInterval: $data['instance']['heartbeat_interval'] ?? null,
            expiresAt: $expiresAt,
            message: $data['message'] ?? null,
            instance: $data['instance'] ?? null,
            idpFederated: self::extractIdpFederated($data)
        );
    }

    public static function fromArray(array $data): self
    {
        $expiresAt = null;
        if (isset($data['instance']['expires_at'])) {
            $expiresAt = Carbon::parse($data['instance']['expires_at']);
        }

        return new self(
            success: $data['success'] ?? true, // Si la requête HTTP réussit, on considère success = true
            apiToken: $data['instance']['api_token'] ?? null,
            heartbeatInterval: $data['instance']['heartbeat_interval'] ?? null,
            expiresAt: $expiresAt,
            message: $data['message'] ?? null,
            instance: $data['instance'] ?? null,
            idpFederated: self::extractIdpFederated($data)
        );
    }

    public static function failed(string $message): self
    {
        return new self(
            success: false,
            message: $message
        );
    }

    /**
     * Extrait et valide le bloc idp_federated de la réponse handshake.
     *
     * Cherché dans instance.idp_federated puis en fallback dans data.idp_federated
     * (frère du bloc instance — PAS la racine HTTP du body, qui ne contient que data).
     * Validation non bloquante : un bloc absent ou invalide retourne null
     * (warning loggé) — le handshake réussit, le SSO fédéré est juste indisponible.
     */
    private static function extractIdpFederated(array $data): ?array
    {
        $block = $data['instance']['idp_federated'] ?? $data['idp_federated'] ?? null;

        if ($block === null) {
            return null;
        }

        if (!is_array($block)) {
            Log::warning('ControlHub handshake: bloc idp_federated invalide (pas un objet), SSO fédéré ignoré');
            return null;
        }

        $publicKey = $block['public_key'] ?? null;
        $kid = is_string($block['kid'] ?? null) ? trim($block['kid']) : null;
        $iss = is_string($block['iss'] ?? null) ? trim($block['iss']) : null;

        $errors = [];
        if (!is_string($publicKey) || !str_contains($publicKey, '-----BEGIN PUBLIC KEY-----')) {
            $errors[] = 'public_key absente ou non PEM';
        } elseif (openssl_pkey_get_public($publicKey) === false) {
            // Vérification cryptographique réelle : un PEM tronqué/corrompu serait
            // persisté puis échouerait de façon opaque à la vérification JWT.
            $errors[] = 'public_key PEM invalide (rejetée par openssl)';
        }
        if ($kid === null || $kid === '') {
            $errors[] = 'kid absent ou vide';
        }
        if ($iss === null || !filter_var($iss, FILTER_VALIDATE_URL)) {
            $errors[] = 'iss absent ou URL invalide';
        }

        if ($errors !== []) {
            Log::warning('ControlHub handshake: bloc idp_federated invalide, SSO fédéré ignoré', [
                'errors' => $errors,
            ]);
            return null;
        }

        return [
            'public_key' => $publicKey,
            // iss stocké verbatim (trim espaces uniquement) : la vérification JWT
            // comparera le claim iss à cette valeur exacte, ne pas normaliser ici.
            'kid' => $kid,
            'iss' => $iss,
        ];
    }
}
