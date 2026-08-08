<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

use App\Exceptions\Nextcloud\NextcloudConfigurationException;
use App\Services\ServiceCredentials;

/**
 * Story 61.1 — LA RÉSOLUTION DE CONFIGURATION, EN UN SEUL ENDROIT.
 *
 * Trois appelants ont besoin du client, et **deux modes de défaut différents** :
 *  - la commande et le traitement en file veulent un refus EXPLICITE quand la
 *    configuration est incomplète (fail-closed, code de sortie 2) ;
 *  - les crochets du cycle de vie utilisateur (création, changement de mot de
 *    passe) veulent une absence SILENCIEUSE quand la capacité est éteinte : une
 *    instance qui n'utilise pas Nextcloud ne doit pas voir ses créations
 *    d'utilisateurs échouer.
 *
 * Les deux vivent ici, côte à côte, pour que le choix soit explicite au point
 * d'appel plutôt que reproduit — c'est ce qui évite qu'un des trois oublie de
 * vérifier la capacité.
 */
final class NextcloudClientFactory
{
    public function __construct(private readonly ServiceCredentials $credentials)
    {
    }

    /**
     * Client prêt, ou refus nommant ce qui manque.
     *
     * @throws NextcloudConfigurationException
     */
    public function make(): NextcloudAdminClient
    {
        return new NextcloudAdminClient(NextcloudConnectionConfig::current($this->credentials));
    }

    /**
     * Client prêt, ou `null` si la capacité est éteinte / la configuration
     * incomplète. **Aucun appel HTTP n'est émis** dans ce cas : c'est ce qui rend
     * vrai « capacité off ⇒ aucun appel nulle part ».
     */
    public function makeOrNull(): ?NextcloudAdminClient
    {
        try {
            return $this->make();
        } catch (NextcloudConfigurationException) {
            return null;
        }
    }
}
