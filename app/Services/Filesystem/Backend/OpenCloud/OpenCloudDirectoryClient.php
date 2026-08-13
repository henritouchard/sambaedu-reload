<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\OpenCloud;

use App\Services\OpenCloud\OpenCloudGraphTransport;
use App\Services\OpenCloud\OpenCloudResult;

/**
 * LE CANAL DES GROUPES DE L'INSTANCE — surface FERMÉE.
 *
 * Les groupes sont l'ARTEFACT COMPILÉ d'une audience du plan : SE5 les fabrique,
 * les nomme et converge leur appartenance. C'est l'arbitrage tranché par la
 * mesure M5 — l'annuaire interne de l'instance est en ÉCRITURE par l'API, donc
 * l'octroi se fait par groupe, et un changement de rôle coûte une écriture
 * d'appartenance au lieu de N écritures d'octroi.
 *
 * ---------------------------------------------------------------------------
 * **CE QUE CE CLIENT N'A PAS, ET NE DOIT PAS AVOIR.**
 *
 * Aucune méthode de création de COMPTE, et aucune méthode de quota de compte. La
 * frontière D8 est nette : la recette plafonne des ZONES, les règles de quota
 * budgètent des PERSONNES. Un backend de plan de fichiers qui saurait créer un
 * compte finirait par en créer un « à la volée » le jour où le cache d'identité
 * serait vide — et la règle de l'homonyme, payée cher sur l'autre produit, serait
 * rouverte par cette porte-là.
 *
 * La lecture de l'annuaire des comptes ({@see listUsers()}) est présente, et elle
 * est en LECTURE SEULE : elle sert au rattachement d'identité et à la sonde, et
 * elle est indispensable parce que — mesuré — l'API **refuse de filtrer** sur
 * l'identifiant de connexion (`unsupported filter`). Retrouver un compte par son
 * login n'a donc pas d'autre chemin que l'énumération.
 * ---------------------------------------------------------------------------
 *
 * **Aucun retrait de groupe.** Un groupe que le plan n'exprime plus perd ses
 * octrois (c'est la révocation) ; le détruire supprimerait un objet d'annuaire
 * que d'autres zones peuvent employer, et rien dans le plan ne dit qu'il est à
 * nous seuls. Hors du plan, hors du geste.
 */
final class OpenCloudDirectoryClient
{
    private const GROUPS = 'graph/v1.0/groups';

    private const USERS = 'graph/v1.0/users';

    public function __construct(private readonly OpenCloudGraphTransport $transport) {}

    /** L'annuaire des groupes, tel que l'instance le REND. */
    public function listGroups(): OpenCloudResult
    {
        return $this->transport->get(self::GROUPS, 'lecture de l\'annuaire des groupes');
    }

    /**
     * Crée un groupe. **« Existe déjà » est une idempotence, pas une erreur** :
     * mesuré, l'instance rend `409 nameAlreadyExists`, et le transport le
     * normalise en résultat CONFORME.
     */
    public function createGroup(string $displayName): OpenCloudResult
    {
        return $this->transport->post(
            self::GROUPS,
            ['displayName' => $displayName],
            sprintf('création du groupe « %s »', $displayName),
            sprintf('Le groupe « %s » existe déjà : adopté en l\'état.', $displayName),
        );
    }

    /**
     * Les membres d'un groupe.
     *
     * L'appartenance n'est PAS rendue par la liste des groupes : il faut la
     * demander explicitement, sans quoi la convergence comparerait à une liste
     * vide et rattacherait tout le monde à chaque passage.
     */
    public function groupMembers(string $groupId): OpenCloudResult
    {
        return $this->transport->get(
            self::GROUPS . '/' . $groupId,
            sprintf('lecture des membres du groupe « %s »', $groupId),
            ['$expand' => 'members'],
        );
    }

    /**
     * Rattache un compte à un groupe.
     *
     * La référence est une URL absolue vers le compte : c'est la forme mesurée
     * (`204` en retour), et elle se construit sur l'URL de base de l'instance
     * elle-même.
     */
    public function addUserToGroup(string $groupId, string $userId): OpenCloudResult
    {
        return $this->transport->post(
            self::GROUPS . '/' . $groupId . '/members/$ref',
            ['@odata.id' => $this->transport->baseUrl() . '/' . self::USERS . '/' . $userId],
            'rattachement d\'un compte à un groupe',
            'Ce compte est déjà membre du groupe.',
        );
    }

    /** Détache un compte d'un groupe. Un compte déjà absent est CONFORME. */
    public function removeUserFromGroup(string $groupId, string $userId): OpenCloudResult
    {
        return $this->transport->delete(
            self::GROUPS . '/' . $groupId . '/members/' . $userId . '/$ref',
            'détachement d\'un compte d\'un groupe',
        );
    }

    /** L'annuaire des comptes — LECTURE SEULE (voir le docblock de classe). */
    public function listUsers(): OpenCloudResult
    {
        return $this->transport->get(self::USERS, 'lecture de l\'annuaire des comptes');
    }
}
