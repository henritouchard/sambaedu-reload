<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe\Concerns;

/**
 * Story 3.8 — Q-3 helper config fixture parité (cf. _README.md).
 *
 * Surcharge `config()` avec les valeurs interpolées dans les fixtures
 * legacy capturées via curl direct sur VM `192.168.122.50` :
 *
 *  - se4install_name = `se4install`
 *  - adminse_name    = `admin`
 *  - se4fs_name      = `se4fs`
 *  - domain          = `localdev.fr`
 *  - se4install_passwd / adminse_passwd = valeurs de test fixées.
 *
 * Les mots de passe correspondent à ceux observés dans les fixtures (`Deux+
 * Chapeau0`) — ces valeurs reproduisent le body legacy bit-pour-bit.
 *
 * **Usage** : à inclure via trait dans les tests Feature parité legacy.
 *
 * **Important** : `Deux+Chapeau0` contient `+` qui passe sanitizeBatPlaceholder
 * (whitelist ASCII printable hors injection chars). OK.
 */
trait UsesLegacyFixtureConfig
{
    /**
     * Set les configs SE5 pour reproduire bit-équivalence avec fixtures.
     */
    protected function applyLegacyFixtureConfig(): void
    {
        config([
            'sambaedu.se4install_name' => 'se4install',
            'sambaedu.se4install_passwd' => 'Deux+Chapeau0',
            'sambaedu.windows.adminse_name' => 'admin',
            'sambaedu.windows.adminse_passwd' => 'Deux+Chapeau0',
            'sambaedu.domain' => 'localdev.fr',
            'sambaedu.se4fs_name' => 'se4fs',
        ]);
    }
}
