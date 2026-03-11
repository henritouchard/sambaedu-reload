<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Configuration du proxy
 */
final readonly class ProxyConfig
{
    public function __construct(
        /** Type de proxy (aucun, squid, etc.) */
        public string $type,

        /** Adresse du serveur proxy */
        public string $address,

        /** Port du proxy */
        public int $port,
    ) {
    }

    /**
     * Indique si un proxy est configuré
     */
    public function isEnabled(): bool
    {
        return $this->type !== 'aucun' && !empty($this->address);
    }

    /**
     * Retourne l'URL complète du proxy
     */
    public function getUrl(): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }
        return sprintf('http://%s:%d', $this->address, $this->port);
    }
}
