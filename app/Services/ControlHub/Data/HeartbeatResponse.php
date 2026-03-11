<?php

namespace App\Services\ControlHub\Data;

readonly class HeartbeatResponse
{
    public function __construct(
        public bool $success,
        public ?string $message = null,
        public ?array $data = null
    ) {}

    public static function fromApiResponse(ApiResponse $apiResponse): self
    {
        if (!$apiResponse->isSuccessful()) {
            return self::failed($apiResponse->message ?? 'Heartbeat request failed');
        }

        $data = $apiResponse->data['data'] ?? $apiResponse->data;

        return new self(
            success: true,
            message: $data['message'] ?? null,
            data: $data
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            success: $data['success'] ?? true,
            message: $data['message'] ?? null,
            data: $data
        );
    }

    public static function success(): self
    {
        return new self(success: true);
    }

    public static function failed(string $message): self
    {
        return new self(
            success: false,
            message: $message
        );
    }
}

