<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\OpenCloud;

use App\Services\OpenCloud\OpenCloudGraphTransport;
use App\Services\OpenCloud\OpenCloudResult;

/**
 * LE CANAL DES ESPACES, DE LEUR ARBORESCENCE ET DE LEURS OCTROIS — surface FERMÉE.
 *
 * Ce client est le SEUL écrivain légitime des zones, et il n'a **aucune méthode**
 * pour ce qu'il ne doit pas faire : ni création de compte, ni quota de compte
 * (frontière D8 : la recette plafonne des ZONES, les règles de quota budgètent des
 * PERSONNES), ni suppression d'espace en production. Une méthode absente ne
 * s'appelle pas par distraction, et un test épingle la liste.
 *
 * ---------------------------------------------------------------------------
 * **LES TROIS PIÈGES DE ROUTAGE MESURÉS LE 2026-08-13, refermés ici.**
 *
 *  1. **Deux versions d'API cohabitent.** Les espaces vivent en `v1.0`, les
 *     octrois d'items en `v1beta1`. Se tromper rend `404 page not found`, qui
 *     ressemble à « la cible n'existe pas ». Les chemins sont écrits en entier,
 *     jamais recomposés.
 *  2. **La racine ne s'adresse pas comme un item.** Passer l'identifiant de
 *     l'espace en identifiant d'item rend `400 « invalid itemID »` sur les
 *     permissions. La racine a son propre segment (`/root/…`) — et, à l'inverse,
 *     c'est bien l'identifiant de l'espace qui sert d'identifiant d'item pour
 *     LISTER les enfants de la racine. Les deux formes sont employées telles que
 *     mesurées, chacune là où elle marche.
 *  3. **La création d'un dossier n'est pas dans l'API Graph.** Elle passe par le
 *     protocole d'édition distante ({@see OpenCloudGraphTransport::sendRaw()}),
 *     seul geste pour lequel Graph ne suffit pas.
 *
 * **{@see deleteSpace()} n'existe pas, et son absence est le point.** Révoquer,
 * c'est retirer les octrois ; détruire une zone n'est le geste d'aucune
 * réconciliation (D9). Le test d'intégration nettoie ce qu'il crée en retirant ses
 * octrois et en laissant l'espace : un espace vide et sans octroi est inoffensif,
 * là où une méthode de suppression dans le code de production serait une arme
 * chargée posée sur la table.
 */
final class OpenCloudSpaceClient
{
    private const SPACES = 'graph/v1.0/drives';

    private const ITEMS = 'graph/v1beta1/drives';

    public function __construct(private readonly OpenCloudGraphTransport $transport) {}

    // =========================================================================
    // Les espaces
    // =========================================================================

    /** L'inventaire des espaces, tel que l'instance le REND. */
    public function listSpaces(): OpenCloudResult
    {
        return $this->transport->get(self::SPACES, 'lecture de l\'inventaire des espaces');
    }

    /**
     * Crée un espace de projet.
     *
     * ⚠️ **AUCUNE IDEMPOTENCE NATIVE — c'est le piège le plus cher du produit.**
     * Mesuré : deux créations du même nom rendent deux fois `201` et produisent
     * DEUX espaces distincts. L'appelant DOIT donc adopter sur l'inventaire RELU
     * avant d'appeler ceci ; créer « au cas où » fabriquerait un espace de plus à
     * chaque passage, et le second serait invisible à l'usage tout en consommant
     * du disque.
     */
    public function createSpace(string $name, string $description): OpenCloudResult
    {
        return $this->transport->post(
            self::SPACES,
            ['name' => $name, 'description' => $description],
            sprintf('création de l\'espace « %s »', $name),
        );
    }

    /** Fixe le plafond d'un espace, en octets. */
    public function setSpaceQuota(string $spaceId, int $bytes): OpenCloudResult
    {
        return $this->transport->patch(
            self::SPACES . '/' . $spaceId,
            ['quota' => ['total' => $bytes]],
            'plafond de l\'espace',
        );
    }

    /** Relit UN espace (le plafond relu est la seule preuve qu'il a pris). */
    public function readSpace(string $spaceId): OpenCloudResult
    {
        return $this->transport->get(self::SPACES . '/' . $spaceId, 'relecture de l\'espace');
    }

    // =========================================================================
    // L'arborescence
    // =========================================================================

    /**
     * Les enfants d'un item.
     *
     * Pour la RACINE, l'identifiant d'item à passer est celui de l'ESPACE — c'est
     * la forme mesurée, et c'est l'exception à la règle du point 2 du docblock.
     */
    public function listChildren(string $spaceId, string $itemId): OpenCloudResult
    {
        return $this->transport->get(
            self::SPACES . '/' . $spaceId . '/items/' . $itemId . '/children',
            'lecture du contenu d\'un dossier',
        );
    }

    /**
     * Crée un dossier — **le seul geste que l'API Graph ne sait pas faire**, et le
     * seul endroit du dépôt où le verbe de création de collection distante est
     * prononcé pour ce produit (une garde d'architecture l'y confine).
     *
     * Deux codes ne veulent pas dire ce qu'ils ont l'air de dire, et ils sont
     * MESURÉS : `405` = le dossier existe déjà, donc CONFORME ; `409` = le dossier
     * parent n'existe pas encore, donc échec nommé — et c'est ce `409` qui impose
     * l'ordre de création parents d'abord.
     */
    public function makeFolder(string $spaceId, string $relativePath): OpenCloudResult
    {
        return $this->transport->sendRaw(
            'MKCOL',
            'dav/spaces/' . $spaceId . '/' . ltrim($relativePath, '/'),
            sprintf('création du dossier « %s »', $relativePath),
            [405],
            [409],
            sprintf('Création du dossier « %s » : il existe déjà.', $relativePath),
            sprintf(
                'Création du dossier « %s » : son dossier parent n\'existe pas encore — les niveaux se '
                . 'créent du plus haut au plus bas.',
                $relativePath,
            ),
        );
    }

    // =========================================================================
    // Les octrois
    // =========================================================================

    /** Les octrois posés sur la RACINE de l'espace. */
    public function listRootPermissions(string $spaceId): OpenCloudResult
    {
        return $this->transport->get(
            self::ITEMS . '/' . $spaceId . '/root/permissions',
            'lecture des octrois de la racine',
        );
    }

    /** Les octrois posés sur un item. */
    public function listItemPermissions(string $spaceId, string $itemId): OpenCloudResult
    {
        return $this->transport->get(
            self::ITEMS . '/' . $spaceId . '/items/' . $itemId . '/permissions',
            'lecture des octrois d\'un dossier',
        );
    }

    /**
     * Pose un octroi sur la RACINE de l'espace, avec un rôle de la famille
     * « espace ». Un rôle de la famille « sous-dossier » y rend
     * `400 « role not applicable to this resource »`.
     */
    public function inviteOnRoot(string $spaceId, string $principalType, string $principalId, string $roleId): OpenCloudResult
    {
        return $this->transport->post(
            self::ITEMS . '/' . $spaceId . '/root/invite',
            $this->invitation($principalType, $principalId, $roleId),
            'pose d\'un octroi sur la racine',
            'Pose d\'un octroi sur la racine : cet octroi existe déjà.',
        );
    }

    /** Pose un octroi sur un item, avec un rôle de la famille « sous-dossier ». */
    public function inviteOnItem(
        string $spaceId,
        string $itemId,
        string $principalType,
        string $principalId,
        string $roleId,
    ): OpenCloudResult {
        return $this->transport->post(
            self::ITEMS . '/' . $spaceId . '/items/' . $itemId . '/invite',
            $this->invitation($principalType, $principalId, $roleId),
            'pose d\'un octroi sur un dossier',
            'Pose d\'un octroi sur un dossier : cet octroi existe déjà.',
        );
    }

    /**
     * Change le rôle d'un octroi EXISTANT.
     *
     * **C'est le SEUL chemin de modification**, et c'est mesuré : rejouer une
     * invitation avec un autre rôle rend `409 « already exists »` sans rien
     * changer. Un backend qui se contenterait de re-inviter rapporterait « posé »
     * sur un octroi resté ce qu'il était.
     */
    public function updateRootPermission(string $spaceId, string $permissionId, string $roleId): OpenCloudResult
    {
        return $this->transport->patch(
            self::ITEMS . '/' . $spaceId . '/root/permissions/' . $permissionId,
            ['roles' => [$roleId]],
            'modification d\'un octroi de la racine',
        );
    }

    public function updateItemPermission(
        string $spaceId,
        string $itemId,
        string $permissionId,
        string $roleId,
    ): OpenCloudResult {
        return $this->transport->patch(
            self::ITEMS . '/' . $spaceId . '/items/' . $itemId . '/permissions/' . $permissionId,
            ['roles' => [$roleId]],
            'modification d\'un octroi de dossier',
        );
    }

    /** Retire un octroi de la racine. Un octroi déjà absent est CONFORME. */
    public function deleteRootPermission(string $spaceId, string $permissionId): OpenCloudResult
    {
        return $this->transport->delete(
            self::ITEMS . '/' . $spaceId . '/root/permissions/' . $permissionId,
            'retrait d\'un octroi de la racine',
        );
    }

    /** Retire un octroi d'un item. Un octroi déjà absent est CONFORME. */
    public function deleteItemPermission(string $spaceId, string $itemId, string $permissionId): OpenCloudResult
    {
        return $this->transport->delete(
            self::ITEMS . '/' . $spaceId . '/items/' . $itemId . '/permissions/' . $permissionId,
            'retrait d\'un octroi de dossier',
        );
    }

    /** L'adresse d'affichage de l'instance — jamais le secret. */
    public function baseUrl(): string
    {
        return $this->transport->baseUrl();
    }

    /**
     * @return array<string, mixed>
     */
    private function invitation(string $principalType, string $principalId, string $roleId): array
    {
        return [
            'recipients' => [[
                'objectId' => $principalId,
                '@libre.graph.recipient.type' => $principalType,
            ]],
            'roles' => [$roleId],
        ];
    }
}
