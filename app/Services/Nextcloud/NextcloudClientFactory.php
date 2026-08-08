<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

use App\Exceptions\Nextcloud\NextcloudConfigurationException;
use App\Services\ServiceCredentials;

/**
 * Story 61.1 — LA RÉSOLUTION DE CONFIGURATION, EN UN SEUL ENDROIT.
 *
 * Trois appelants ont besoin d'un client, et **deux comportements de défaut** :
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
 *
 * ---------------------------------------------------------------------------
 * **UN SEUL CLIENT, UN SEUL CREDENTIAL** (recadrage du 2026-08-08). La story 61.2
 * avait ajouté ici une seconde famille — le client « délégué » du compte porteur —
 * et un aiguillage par mode. Le mode délégué a été supprimé : SE5 exige un compte
 * administrateur de l'instance. Il ne reste donc plus rien à aiguiller, et
 * `make()` est le seul chemin vers l'instance.
 * ---------------------------------------------------------------------------
 */
final class NextcloudClientFactory
{
    public function __construct(private readonly ServiceCredentials $credentials)
    {
    }

    /**
     * Client d'ADMINISTRATION prêt, ou refus nommant ce qui manque.
     *
     * @throws NextcloudConfigurationException
     */
    public function make(): NextcloudAdminClient
    {
        return new NextcloudAdminClient(NextcloudConnectionConfig::current($this->credentials));
    }

    /**
     * Client d'administration prêt, ou `null` si la capacité est éteinte ou la
     * configuration incomplète. Aucun appel HTTP n'est émis dans ces cas : une
     * instance qui n'utilise pas Nextcloud ne doit pas voir ses créations
     * d'utilisateurs échouer.
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
