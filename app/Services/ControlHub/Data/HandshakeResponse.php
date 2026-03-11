<?php

namespace App\Services\ControlHub\Data;

use Carbon\Carbon;

readonly class HandshakeResponse
{
    public function __construct(
        public bool $success,
        public ?string $apiToken = null,
        public ?int $heartbeatInterval = null,
        public ?Carbon $expiresAt = null,
        public ?string $message = null,
        public ?array $instance = null
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
            instance: $data['instance'] ?? null
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
            instance: $data['instance'] ?? null
        );
    }

    public static function failed(string $message): self
    {
        return new self(
            success: false,
            message: $message
        );
    }
}

