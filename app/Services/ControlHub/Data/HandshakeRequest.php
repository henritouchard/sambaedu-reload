<?php

namespace App\Services\ControlHub\Data;

readonly class HandshakeRequest
{
    public function __construct(
        public string $registrationKey,
        public string $instanceApiKey,
        public array $clientInstance,
        public array $capabilities = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'registration_key' => $this->registrationKey,
            'instance_api_key' => $this->instanceApiKey,
            'client_instance' => $this->clientInstance,
            'capabilities' => $this->capabilities
        ];
    }

    public static function create(
        string $registrationKey,
        string $instanceApiKey,
        string $instanceId,
        string $instanceName,
        string $url,
        array $capabilities = [],
        ?string $uai = null,
        ?string $academy = null,
        ?string $type = null
    ): self {
        return new self(
            registrationKey: $registrationKey,
            instanceApiKey: $instanceApiKey,
            clientInstance: [
                'id' => $instanceId,
                'name' => $instanceName,
                'url' => $url,
                'version' => '1.0.0',
                'uai' => $uai,
                'academy' => $academy,
                'type' => $type
            ],
            capabilities: $capabilities
        );
    }
}

