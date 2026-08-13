<?php

declare(strict_types=1);

namespace App\Services\OpenCloud;

/**
 * LE CLIENT D'ADMINISTRATION : une SURFACE FERMÉE, et elle n'écrit RIEN.
 *
 * Ce client existe pour une seule chose : dire si la connexion déclarée par
 * l'administrateur tient debout. Il ne crée aucun espace, aucun groupe, aucun
 * compte, ne pose aucun octroi et ne plafonne rien — tout cela appartient au
 * BACKEND, sous la ligne de contrat, et un test d'architecture épingle sa liste
 * de méthodes publiques pour que l'ajout d'un verbe d'écriture soit un GESTE
 * plutôt qu'une dérive.
 *
 * La séparation n'est pas cosmétique : le jour où l'écran voudrait « juste créer
 * un petit espace pour tester », il aurait un second écrivain sur une zone dont
 * le backend est l'autorité — et le garde-fou d'epic (« une seule autorité
 * d'écriture par zone ») serait franchi par l'écran des réglages.
 */
final class OpenCloudAdminClient
{
    public function __construct(private readonly OpenCloudGraphTransport $transport) {}

    /**
     * Sonde la connexion en TROIS lectures, dans l'ordre où elles échouent le
     * plus utilement.
     *
     *  1. l'identité du compte connecté (`/graph/v1.0/me`) — sépare « injoignable »
     *     de « pas authentifié » ;
     *  2. l'inventaire des espaces — la lecture que le backend fera en premier ;
     *  3. l'annuaire des comptes — mesuré comme réservé à l'administration
     *     (`403 accessDenied` pour un compte ordinaire), donc c'est LUI qui
     *     tranche la question du privilège.
     *
     * Aucune écriture, à aucune étape.
     */
    public function probe(): OpenCloudConnectionProbe
    {
        $me = $this->transport->get('graph/v1.0/me', 'lecture du compte connecté');

        if ($me->isFailure()) {
            if ($me->failure === OpenCloudFailure::Injoignable) {
                return OpenCloudConnectionProbe::unreachable($me->message);
            }
            if ($me->isPrivilegeFailure()) {
                return OpenCloudConnectionProbe::notAuthenticated(
                    'L\'instance a répondu mais n\'a pas authentifié ce compte : identifiant inconnu, mot de '
                    . 'passe erroné, ou authentification basique désactivée sur l\'instance.',
                    $me->httpStatus,
                );
            }

            return OpenCloudConnectionProbe::rejected($me->message, $me->httpStatus);
        }

        $account = is_string($me->value('onPremisesSamAccountName'))
            ? (string) $me->value('onPremisesSamAccountName')
            : $this->transport->adminUser();

        $spaces = $this->transport->get('graph/v1.0/drives', 'lecture de l\'inventaire des espaces');
        if ($spaces->isFailure()) {
            return $spaces->isPrivilegeFailure()
                ? OpenCloudConnectionProbe::notAdministrator(
                    sprintf(
                        'Le compte « %s » est authentifié mais ne peut pas lire l\'inventaire des espaces : '
                        . 'SE5 exige un compte ADMINISTRATEUR de l\'instance.',
                        $account,
                    ),
                    $spaces->httpStatus,
                    $account,
                )
                : OpenCloudConnectionProbe::rejected($spaces->message, $spaces->httpStatus);
        }

        $directory = $this->transport->get('graph/v1.0/groups', 'lecture de l\'annuaire des groupes');
        if ($directory->isFailure()) {
            return $directory->isPrivilegeFailure()
                ? OpenCloudConnectionProbe::notAdministrator(
                    sprintf(
                        'Le compte « %s » est authentifié mais ne peut pas lire l\'annuaire des groupes : '
                        . 'SE5 exige un compte ADMINISTRATEUR de l\'instance, faute de quoi aucune audience '
                        . 'du plan ne pourra être compilée.',
                        $account,
                    ),
                    $directory->httpStatus,
                    $account,
                )
                : OpenCloudConnectionProbe::rejected($directory->message, $directory->httpStatus);
        }

        return OpenCloudConnectionProbe::ok($account);
    }
}
