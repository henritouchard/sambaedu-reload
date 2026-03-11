<?php

namespace App\Services\ControlHub\Data;

use Carbon\Carbon;

readonly class TokenRenewalResponse
{
    public function __construct(
        public bool $success,
        public ?string $newToken = null,
        public ?Carbon $previousTokenExpiresAt = null,
        public ?string $message = null
    ) {}

    public static function fromApiResponse(ApiResponse $apiResponse): self
    {
        if (!$apiResponse->isSuccessful()) {
            return self::failed($apiResponse->message ?? 'Token renewal request failed');
        }

        $data = $apiResponse->data['data'] ?? $apiResponse->data;

        $previousTokenExpiresAt = null;
        if (isset($data['previous_token_expires_at'])) {
            $previousTokenExpiresAt = Carbon::parse($data['previous_token_expires_at']);
        }

        return new self(
            success: $data['success'] ?? true,
            newToken: $data['new_token'] ?? null,
            previousTokenExpiresAt: $previousTokenExpiresAt,
            message: $data['message'] ?? null
        );
    }

    public static function fromArray(array $data): self
    {
        $previousTokenExpiresAt = null;
        if (isset($data['previous_token_expires_at'])) {
            $previousTokenExpiresAt = Carbon::parse($data['previous_token_expires_at']);
        }

        return new self(
            success: $data['success'] ?? false,
            newToken: $data['new_token'] ?? null,
            previousTokenExpiresAt: $previousTokenExpiresAt,
            message: $data['message'] ?? null
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

