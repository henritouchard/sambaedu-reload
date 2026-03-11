<?php

namespace App\Services\ControlHub\Data;

readonly class ApiResponse
{
    public function __construct(
        public bool $success,
        public ?array $data = null,
        public ?string $message = null,
        public int $httpStatus = 200
    ) {}

    public static function success(array $data = [], int $httpStatus = 200): self
    {
        return new self(
            success: true,
            data: $data,
            httpStatus: $httpStatus
        );
    }

    public static function failed(string $message, int $httpStatus = 500): self
    {
        return new self(
            success: false,
            message: $message,
            httpStatus: $httpStatus
        );
    }

    public function isSuccessful(): bool
    {
        return $this->success && $this->httpStatus >= 200 && $this->httpStatus < 300;
    }
}

