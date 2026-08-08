<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

use App\Enums\NextcloudInstanceMode;
use App\Exceptions\Nextcloud\NextcloudConfigurationException;
use App\Services\FilePolicyService;
use App\Services\ServiceCredentials;

/**
 * Story 61.1 — LA RÉSOLUTION DE CONFIGURATION, EN UN SEUL ENDROIT.
 * Story 61.2 — ET ELLE EST DÉSORMAIS **PAR MODE**.
 *
 * Trois appelants ont besoin d'un client, et **deux modes de défaut différents** :
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
 * **DEUX FAMILLES DE CLIENTS, DEUX CREDENTIALS, AUCUN CROISEMENT POSSIBLE.**
 * `make()` rend le client d'ADMINISTRATION, construit sur le credential admin ;
 * `makeDelegate()` rend le client DÉLÉGUÉ, construit sur le credential du porteur.
 * Chacun refuse quand le mode déclaré n'est pas le sien
 * ({@see NextcloudConfigurationException::wrongMode()}), **avant tout appel HTTP**.
 *
 * Le croisement n'est donc pas seulement interdit : il n'a pas de chemin. Un client
 * d'administration ne peut pas obtenir le secret du porteur (sa configuration ne
 * lit pas ce nom de credential), et réciproquement.
 * ---------------------------------------------------------------------------
 */
final class NextcloudClientFactory
{
    public function __construct(private readonly ServiceCredentials $credentials)
    {
    }

    /**
     * Le mode d'administration déclaré. Lecture unique, tolérante à une valeur
     * hors vocabulaire (repli journalisé).
     */
    public function mode(): NextcloudInstanceMode
    {
        return FilePolicyService::nextcloudMode();
    }

    /**
     * Client d'ADMINISTRATION prêt, ou refus nommant ce qui manque — y compris
     * « le mode déclaré ne porte pas cette opération » (61.2, AC5).
     *
     * @throws NextcloudConfigurationException
     */
    public function make(): NextcloudAdminClient
    {
        return new NextcloudAdminClient(NextcloudConnectionConfig::current($this->credentials));
    }

    /**
     * Client d'administration prêt, ou `null` si la capacité est éteinte, la
     * configuration incomplète, **ou le mode déclaré délégué**. Aucun appel HTTP
     * n'est émis dans ces cas : c'est ce qui rend vrai « mode délégué ⇒ zéro
     * requête de gestion de comptes ».
     *
     * L'appelant qui a besoin de DISTINGUER ces trois causes (pour tracer un état
     * configuré légitime plutôt qu'une panne) lit {@see mode()} : `null` seul ne
     * dit pas pourquoi.
     */
    public function makeOrNull(): ?NextcloudAdminClient
    {
        try {
            return $this->make();
        } catch (NextcloudConfigurationException) {
            return null;
        }
    }

    /**
     * Client DÉLÉGUÉ prêt, ou refus nommant ce qui manque.
     *
     * @throws NextcloudConfigurationException
     */
    public function makeDelegate(): NextcloudDelegateClient
    {
        return new NextcloudDelegateClient(NextcloudDelegateConfig::current($this->credentials));
    }

    /** Client délégué prêt, ou `null` — même contrat silencieux que `makeOrNull()`. */
    public function makeDelegateOrNull(): ?NextcloudDelegateClient
    {
        try {
            return $this->makeDelegate();
        } catch (NextcloudConfigurationException) {
            return null;
        }
    }
}
