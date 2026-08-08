<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

use App\Enums\NextcloudInstanceMode;
use App\Exceptions\Nextcloud\NextcloudConfigurationException;
use App\Services\FilePolicyService;
use App\Services\ServiceCredentials;

/**
 * Story 61.2 — LA CONFIGURATION DU COMPTE PORTEUR, SÉPARÉE DE CELLE DE L'ADMIN.
 *
 * ---------------------------------------------------------------------------
 * **POURQUOI UNE CLASSE À PART PLUTÔT QU'UN CHAMP « mode » DANS LA CONFIGURATION
 * DE 61.1.** L'AC4 exige qu'« aucune opération d'administration ne soit jamais
 * émise avec le credential porteur, ni l'inverse ». Un objet unique portant les
 * deux jeux d'identifiants — ou un seul jeu dont le sens dépend d'un champ `mode`
 * — rend ce croisement possible par une simple erreur d'aiguillage, et le test qui
 * le verrouille devient le seul rempart.
 *
 * Ici, le croisement est **impossible par typage** : le client d'administration
 * ({@see NextcloudAdminClient}) ne se construit QUE sur
 * {@see NextcloudConnectionConfig}, le client délégué
 * ({@see NextcloudDelegateClient}) QUE sur cette classe-ci, et chacune ne sait lire
 * qu'un seul nom de credential. Une opération d'administration ne peut pas porter
 * l'auth du porteur : elle n'a aucun chemin pour l'obtenir.
 * ---------------------------------------------------------------------------
 *
 * **Le secret vit dans `service_credentials`**, sous un nom DISTINCT
 * ({@see self::CREDENTIAL_NAME}) : les deux comptes cohabitent, et un aller-retour
 * de mode ne perd aucune configuration. Il est privé, {@see __debugInfo()} le
 * masque, et il n'entre dans aucun message.
 *
 * **Ce que ce compte peut faire, mesuré le 2026-08-08 sur `nc-spike` (Nextcloud
 * 34.0.2), en compte ORDINAIRE** : lire son propre espace WebDAV (`207`), y créer
 * des dossiers (`MKCOL` → `201`, rejeu → `405`, parent manquant → `409`), émettre
 * un partage par UTILISATEUR (`ok 200`, dédoublonné à la réémission). Ce qu'il ne
 * peut PAS : les montages globaux et la gestion des comptes (`403`), et le partage
 * par GROUPE (« Please specify a valid group ») — d'où l'octroi par utilisateur.
 */
final class NextcloudDelegateConfig
{
    /**
     * Nom du credential porteur dans `service_credentials`.
     *
     * Il nomme le COMPTE, pas l'usage — à côté de `nextcloud_admin` et de
     * `se4install`, un `SELECT name FROM service_credentials` reste lisible et les
     * deux comptes Nextcloud ne se confondent pas.
     */
    public const CREDENTIAL_NAME = 'nextcloud_delegue';

    private function __construct(
        /** URL de base NORMALISÉE : schéma présent, aucun slash final. */
        public readonly string $baseUrl,
        /** Identifiant du compte porteur — non secret, il vit dans `files.policy`. */
        public readonly string $delegateUser,
        private readonly string $delegatePassword,
        public readonly bool $verifyTls,
    ) {
    }

    /**
     * Fabrique depuis l'état persisté.
     *
     * **Lève** si la capacité est éteinte, si le mode déclaré n'est pas le mode
     * délégué, ou si un réglage manque : le fail-closed porte ici sur le fait
     * d'émettre un appel de porteur alors que l'instance n'est pas déclarée
     * déléguée.
     *
     * @throws NextcloudConfigurationException
     */
    public static function current(?ServiceCredentials $credentials = null): self
    {
        $policy = FilePolicyService::globalConfig();

        if (! $policy['nextcloud']) {
            throw NextcloudConfigurationException::capabilityDisabled();
        }

        $mode = NextcloudInstanceMode::fromStored($policy['nextcloud_mode']);

        if ($mode !== NextcloudInstanceMode::Delegue) {
            throw NextcloudConfigurationException::wrongMode($mode, NextcloudInstanceMode::Delegue);
        }

        $credentials ??= app(ServiceCredentials::class);

        return self::fromValues(
            (string) $policy['nextcloud_server_url'],
            (string) $policy['nextcloud_delegue_user'],
            (string) ($credentials->password(self::CREDENTIAL_NAME) ?? ''),
            (bool) $policy['nextcloud_verify_tls'],
        );
    }

    /**
     * Même fabrique, sans lecture d'état — la sonde-garde de l'AC2 doit pouvoir
     * éprouver des valeurs **pas encore persistées** : c'est tout le principe du
     * fail-closed à la sélection.
     *
     * @throws NextcloudConfigurationException
     */
    public static function fromValues(
        string $baseUrl,
        string $delegateUser,
        string $delegatePassword,
        bool $verifyTls = true,
    ): self {
        $baseUrl = trim($baseUrl);
        $delegateUser = trim($delegateUser);

        $missing = [];
        if ($baseUrl === '') {
            $missing[] = 'l\'URL du serveur Nextcloud';
        }
        if ($delegateUser === '') {
            $missing[] = 'l\'identifiant du compte porteur';
        }
        if ($delegatePassword === '') {
            $missing[] = 'l\'app password du compte porteur';
        }

        if ($missing !== []) {
            throw NextcloudConfigurationException::incomplete($missing);
        }

        if (preg_match('#^https?://[^/\s]+#i', $baseUrl) !== 1) {
            throw NextcloudConfigurationException::malformedUrl($baseUrl);
        }

        return new self(rtrim($baseUrl, '/'), $delegateUser, $delegatePassword, $verifyTls);
    }

    /** Le secret, lisible UNIQUEMENT par le client délégué. */
    public function delegatePassword(): string
    {
        return $this->delegatePassword;
    }

    /** Concatène l'URL de base et un chemin, sans double slash ni slash manquant. */
    public function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    /**
     * Racine WebDAV du compte porteur — l'espace dans lequel, et SEULEMENT dans
     * lequel, ce compte peut agir.
     */
    public function davRoot(): string
    {
        return $this->url('remote.php/dav/files/' . rawurlencode($this->delegateUser) . '/');
    }

    /** Le mode auquel cette configuration appartient. Un seul, et il est fermé. */
    public function mode(): NextcloudInstanceMode
    {
        return NextcloudInstanceMode::Delegue;
    }

    /**
     * Vue de débogage MASQUÉE — `dd()` d'une configuration est le chemin le plus
     * court vers un secret dans un journal.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'delegateUser' => $this->delegateUser,
            'delegatePassword' => '***',
            'verifyTls' => $this->verifyTls,
        ];
    }
}
