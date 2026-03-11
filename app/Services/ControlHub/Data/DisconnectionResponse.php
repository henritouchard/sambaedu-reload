<?php

namespace App\Services\ControlHub\Data;

readonly class DisconnectionResponse
{
    public function __construct(
        public bool $success,
        public ?string $message = null
    ) {}

    public static function fromApiResponse(ApiResponse $apiResponse): self
    {
        if (!$apiResponse->isSuccessful()) {
            return self::failed($apiResponse->message ?? 'Disconnection notification failed');
        }

        $data = $apiResponse->data['data'] ?? $apiResponse->data;

        return new self(
            success: true,
            message: $data['message'] ?? 'Instance disconnected successfully'
        );
    }

    public static function success(string $message = 'Instance disconnected successfully'): self
    {
        return new self(
            success: true,
            message: $message
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

