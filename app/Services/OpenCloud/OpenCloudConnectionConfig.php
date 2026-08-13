<?php

declare(strict_types=1);

namespace App\Services\OpenCloud;

use App\Exceptions\OpenCloud\OpenCloudConfigurationException;
use App\Services\FilePolicyService;
use App\Services\ServiceCredentials;

/**
 * LA CONFIGURATION DE CONNEXION, VALIDÉE UNE FOIS POUR TOUTES.
 *
 * Un objet de configuration existe ici pour une raison précise : la complétude se
 * vérifie **avant** la première écriture, en un seul endroit, et le refus nomme ce
 * qui manque ({@see OpenCloudConfigurationException}). Sans lui, chaque appelant
 * (la commande de déploiement, le backend, l'écran, la sonde) referait sa propre
 * vérification — et l'un d'eux l'oublierait.
 *
 * **Où vivent les valeurs, et pourquoi elles ne vivent pas au même endroit.**
 *  - L'URL, l'identifiant d'administration et la vérification TLS sont des
 *    réglages NON SECRETS : ils vont dans le payload `files.policy`
 *    ({@see FilePolicyService}), lisible, exportable, diffable.
 *  - Le mot de passe d'administration est un SECRET : il vit dans
 *    `service_credentials` sous le nom {@see self::CREDENTIAL_NAME}, chiffré
 *    at-rest par le cast `encrypted` du modèle. Jamais dans `files.policy`, qui
 *    est un JSON en clair.
 *
 * **Le secret ne sort pas de cet objet par accident.** Il est privé, il n'est
 * lisible que par {@see adminPassword()}, et {@see __debugInfo()} le remplace par
 * un masque — ce qui couvre `var_dump`, `dd()`, et les traces d'exception qui
 * sérialisent leurs arguments. Un test l'épingle sur le rendu d'erreur.
 *
 * ---------------------------------------------------------------------------
 * **L'AUTHENTIFICATION EST BASIQUE, ET C'EST UNE MESURE, PAS UN DÉFAUT.**
 *
 * Relevé du 2026-08-13 contre l'instance déployée : l'authentification basique
 * est le seul canal serveur→serveur qu'OpenCloud 7.2.3 accepte sans flux OIDC
 * d'utilisateur. Elle s'active par une variable d'environnement de la composition
 * (`PROXY_ENABLE_BASIC_AUTH`), le produit la journalise comme réservée au
 * développement, et un compte de service (`OC_SERVICE_ACCOUNT_ID/SECRET`) rend
 * `401`. Il n'y a **ni jeton, ni durée de vie, ni renouvellement** à gérer — donc
 * rien à mettre en cache et rien à faire expirer. Le jour où le produit ouvrira
 * un flux d'identifiants client, ce sera un changement DANS CE FICHIER et dans le
 * client, pas dans le backend.
 * ---------------------------------------------------------------------------
 */
final class OpenCloudConnectionConfig
{
    /**
     * Nom du credential d'administration dans `service_credentials`.
     *
     * Choisi pour être lisible dans un `SELECT name FROM service_credentials` à
     * côté de `nextcloud_admin` et de `se4install` : il dit le compte, pas
     * l'usage. **C'est le SEUL credential OpenCloud** — le déploiement le
     * génère, l'écran le remplace, personne d'autre ne l'écrit.
     */
    public const CREDENTIAL_NAME = 'opencloud_admin';

    private function __construct(
        /** URL de base NORMALISÉE : schéma présent, aucun slash final. */
        public readonly string $baseUrl,
        public readonly string $adminUser,
        private readonly string $adminPassword,
        /**
         * Vérification du certificat TLS. **Vraie par défaut**, et son
         * assouplissement est un choix VISIBLE (case à cocher sur l'écran, clé
         * persistée) — jamais un défaut caché dans le code.
         */
        public readonly bool $verifyTls,
    ) {
    }

    /**
     * Fabrique depuis l'état persisté. **Lève** si la capacité est éteinte ou si
     * un réglage manque — c'est le point fail-closed du backend.
     *
     * @throws OpenCloudConfigurationException
     */
    public static function current(?ServiceCredentials $credentials = null): self
    {
        $policy = FilePolicyService::globalConfig();

        if (! $policy['opencloud']) {
            throw OpenCloudConfigurationException::capabilityDisabled();
        }

        $credentials ??= app(ServiceCredentials::class);
        $secret = (string) ($credentials->password(self::CREDENTIAL_NAME) ?? '');

        return self::fromValues(
            (string) $policy['opencloud_server_url'],
            (string) $policy['opencloud_admin_user'],
            $secret,
            (bool) $policy['opencloud_verify_tls'],
        );
    }

    /**
     * Même fabrique, sans lecture d'état — utilisée par les tests et par le test
     * d'intégration contre l'instance réelle.
     *
     * @throws OpenCloudConfigurationException
     */
    public static function fromValues(
        string $baseUrl,
        string $adminUser,
        string $adminPassword,
        bool $verifyTls = true,
    ): self {
        $baseUrl = trim($baseUrl);
        $adminUser = trim($adminUser);

        $missing = [];
        if ($baseUrl === '') {
            $missing[] = 'l\'URL de l\'instance OpenCloud';
        }
        if ($adminUser === '') {
            $missing[] = 'l\'identifiant du compte d\'administration OpenCloud';
        }
        if ($adminPassword === '') {
            $missing[] = 'le mot de passe du compte d\'administration OpenCloud';
        }

        if ($missing !== []) {
            throw OpenCloudConfigurationException::incomplete($missing);
        }

        if (preg_match('#^https?://[^/\s]+#i', $baseUrl) !== 1) {
            throw OpenCloudConfigurationException::malformedUrl($baseUrl);
        }

        return new self(rtrim($baseUrl, '/'), $adminUser, $adminPassword, $verifyTls);
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
     * chemin le plus court vers un secret dans un journal ; on le ferme ici plutôt
     * que d'espérer que personne ne l'écrive.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'adminUser' => $this->adminUser,
            'adminPassword' => '***',
            'verifyTls' => $this->verifyTls,
        ];
    }
}
