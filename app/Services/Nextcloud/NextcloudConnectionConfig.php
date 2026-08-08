<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

use App\Exceptions\Nextcloud\NextcloudConfigurationException;
use App\Services\FilePolicyService;
use App\Services\ServiceCredentials;

/**
 * Story 61.1 — LA CONFIGURATION DE CONNEXION, VALIDÉE UNE FOIS POUR TOUTES.
 *
 * Un objet de configuration existe ici pour une raison précise : la complétude se
 * vérifie **avant** la première écriture, en un seul endroit, et le refus nomme ce
 * qui manque ({@see NextcloudConfigurationException}). Sans lui, chaque appelant
 * (la commande, le traitement en file, l'écran, le crochet de création
 * d'utilisateur) referait sa propre vérification — et l'un d'eux l'oublierait.
 *
 * **Où vivent les valeurs, et pourquoi elles ne vivent pas au même endroit.**
 *  - L'URL, l'identifiant admin, l'hôte SMB et la vérification TLS sont des
 *    réglages NON SECRETS : ils vont dans le payload `files.policy`
 *    ({@see FilePolicyService}), lisible, exportable, diffable.
 *  - L'app password admin est un SECRET : il vit dans `service_credentials` sous
 *    le nom {@see self::CREDENTIAL_NAME}, chiffré at-rest par le cast `encrypted`
 *    du modèle. Jamais dans `files.policy`, qui est un JSON en clair.
 *
 * **Le secret ne sort pas de cet objet par accident.** Il est privé, il n'est
 * lisible que par {@see adminPassword()}, et {@see __debugInfo()} le remplace par
 * un masque — ce qui couvre `var_dump`, `dd()`, et les traces d'exception qui
 * sérialisent leurs arguments. Un test l'épingle sur le rendu d'erreur.
 */
final class NextcloudConnectionConfig
{
    /**
     * Nom du credential admin dans `service_credentials`.
     *
     * Choisi pour être lisible dans un `SELECT name FROM service_credentials` à
     * côté de `se4install` : il dit le compte, pas l'usage. Le jour où 61.2 ajoute
     * un compte porteur délégué, il s'appellera autrement et les deux
     * cohabiteront sans ambiguïté.
     */
    public const CREDENTIAL_NAME = 'nextcloud_admin';

    private function __construct(
        /** URL de base NORMALISÉE : schéma présent, aucun slash final. */
        public readonly string $baseUrl,
        public readonly string $adminUser,
        private readonly string $adminPassword,
        /**
         * Hôte SMB que Nextcloud contactera. Ce n'est PAS l'hôte de Nextcloud :
         * c'est le serveur de fichiers, celui que l'agent substitue au jeton
         * `<se4fs>` dans les UNC des lecteurs.
         *
         * **Le seul des quatre réglages qui peut être vide** — et le vide n'est
         * pas une absence : il veut dire « le serveur de fichiers connu de
         * l'instance », dérivé de `sambaedu.se4fs_name` au moment du
         * PROVISIONNEMENT DES MONTAGES ({@see NextcloudProvisioningService::smbHost()}),
         * là où il est consommé. Ce n'est donc pas une donnée de CONNEXION : on
         * parle à l'instance sans lui, et l'écran le déclare `nullable` sans
         * astérisque. Le rendre obligatoire ici rendrait muets la création de
         * compte (AC5) et la propagation de mot de passe (AC7), que
         * {@see NextcloudClientFactory::makeOrNull()} avale par conception.
         */
        public readonly string $smbHost,
        /**
         * Vérification du certificat TLS. **Vraie par défaut**, et son
         * assouplissement est un choix VISIBLE (case à cocher sur l'écran, clé
         * persistée) — le chemin legacy, lui, désactivait la vérification en dur
         * dans le code, ce qui rendait la faiblesse invisible à l'exploitant.
         */
        public readonly bool $verifyTls,
    ) {
    }

    /**
     * Fabrique depuis l'état persisté. **Lève** si la capacité est éteinte ou si
     * un réglage manque — c'est le point fail-closed de la story.
     *
     * @throws NextcloudConfigurationException
     */
    public static function current(?ServiceCredentials $credentials = null): self
    {
        $policy = FilePolicyService::globalConfig();

        if (! $policy['nextcloud']) {
            throw NextcloudConfigurationException::capabilityDisabled();
        }

        $credentials ??= app(ServiceCredentials::class);
        $secret = (string) ($credentials->password(self::CREDENTIAL_NAME) ?? '');

        return self::fromValues(
            (string) $policy['nextcloud_server_url'],
            (string) $policy['nextcloud_admin_user'],
            $secret,
            (string) $policy['nextcloud_smb_host'],
            (bool) $policy['nextcloud_verify_tls'],
        );
    }

    /**
     * Même fabrique, sans lecture d'état — utilisée par les tests et par le test
     * d'intégration contre l'instance de sondage.
     *
     * @throws NextcloudConfigurationException
     */
    public static function fromValues(
        string $baseUrl,
        string $adminUser,
        string $adminPassword,
        string $smbHost,
        bool $verifyTls = true,
    ): self {
        $baseUrl = trim($baseUrl);
        $adminUser = trim($adminUser);
        $smbHost = trim($smbHost);

        $missing = [];
        if ($baseUrl === '') {
            $missing[] = 'l\'URL du serveur Nextcloud';
        }
        if ($adminUser === '') {
            $missing[] = 'l\'identifiant du compte admin Nextcloud';
        }
        if ($adminPassword === '') {
            $missing[] = 'l\'app password admin Nextcloud';
        }

        // L'hôte SMB n'est VOLONTAIREMENT pas dans ce critère : il n'est pas une
        // donnée de connexion (voir le docblock de la propriété), son vide est un
        // état valide dont le défaut se dérive au provisionnement des montages.
        if ($missing !== []) {
            throw NextcloudConfigurationException::incomplete($missing);
        }

        if (preg_match('#^https?://[^/\s]+#i', $baseUrl) !== 1) {
            throw NextcloudConfigurationException::malformedUrl($baseUrl);
        }

        return new self(
            rtrim($baseUrl, '/'),
            $adminUser,
            $adminPassword,
            $smbHost,
            $verifyTls,
        );
    }

    /**
     * Le secret, lisible UNIQUEMENT par le client HTTP. Aucun autre appelant n'a
     * de raison de l'obtenir : ni l'écran, ni le rapport, ni un message.
     */
    public function adminPassword(): string
    {
        return $this->adminPassword;
    }

    /** Concatène l'URL de base et un chemin, sans double slash ni slash manquant. */
    public function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    /**
     * Vue de débogage MASQUÉE. `var_dump`/`dd` d'un objet de configuration est le
     * chemin le plus court vers un secret dans un log ; on le ferme ici plutôt que
     * d'espérer que personne ne l'écrive.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'adminUser' => $this->adminUser,
            'adminPassword' => '***',
            'smbHost' => $this->smbHost,
            'verifyTls' => $this->verifyTls,
        ];
    }
}
