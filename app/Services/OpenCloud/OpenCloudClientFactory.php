<?php

declare(strict_types=1);

namespace App\Services\OpenCloud;

use App\Exceptions\OpenCloud\OpenCloudConfigurationException;
use App\Services\ServiceCredentials;

/**
 * LA RÉSOLUTION DE CONFIGURATION, EN UN SEUL ENDROIT.
 *
 * Deux comportements de défaut, côte à côte, pour que le choix soit EXPLICITE au
 * point d'appel plutôt que reproduit :
 *  - le backend et la commande veulent un refus EXPLICITE quand la configuration
 *    est incomplète (fail-closed) ;
 *  - l'écran et la commande de déploiement veulent une absence SILENCIEUSE quand
 *    la capacité est éteinte — une instance qu'on vient de déployer n'a pas
 *    encore de capacité active, et c'est l'état NORMAL (l'activation est un geste
 *    explicite de l'administrateur, jamais une conséquence du déploiement).
 */
final class OpenCloudClientFactory
{
    public function __construct(private readonly ServiceCredentials $credentials) {}

    /**
     * Le transport HTTP prêt, ou refus nommant ce qui manque.
     *
     * @throws OpenCloudConfigurationException
     */
    public function transport(): OpenCloudGraphTransport
    {
        return new OpenCloudGraphTransport(OpenCloudConnectionConfig::current($this->credentials));
    }

    /**
     * Le client d'administration prêt, ou refus nommant ce qui manque.
     *
     * @throws OpenCloudConfigurationException
     */
    public function make(): OpenCloudAdminClient
    {
        return new OpenCloudAdminClient($this->transport());
    }

    /**
     * Client prêt, ou `null` si la capacité est éteinte ou la configuration
     * incomplète. **Aucun appel HTTP n'est émis dans ces cas.**
     */
    public function makeOrNull(): ?OpenCloudAdminClient
    {
        try {
            return $this->make();
        } catch (OpenCloudConfigurationException) {
            return null;
        }
    }
}
