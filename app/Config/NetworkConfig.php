<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Configuration réseau du serveur SambaEdu
 */
final readonly class NetworkConfig
{
    public function __construct(
        /** Adresse IP du serveur SE4FS */
        public string $se4fsIp,

        /** Nom du serveur SE4FS */
        public string $se4fsName,

        /** Adresse IP du serveur SE4AD */
        public string $se4adIp,

        /** Nom du serveur SE4AD */
        public string $se4adName,

        /** Masque de sous-réseau SE4AD */
        public string $se4adMask,

        /** Passerelle SE4AD */
        public string $se4adGateway,

        /** Interface réseau principale */
        public string $interface,

        /** Adresse IP du serveur */
        public string $address,

        /** Masque de sous-réseau */
        public string $mask,

        /** Adresse réseau */
        public string $network,

        /** Passerelle par défaut */
        public string $gateway,

        /** Serveur DNS */
        public string $nameserver,

        /** URL publique SE4 */
        public string $se4Url,
    ) {
    }

    /**
     * Retourne l'adresse CIDR du réseau
     */
    public function getCidr(): string
    {
        $bits = 0;
        foreach (explode('.', $this->mask) as $octet) {
            $bits += substr_count(decbin((int) $octet), '1');
        }
        return $this->network . '/' . $bits;
    }
}
