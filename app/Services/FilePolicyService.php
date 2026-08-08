<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SystemSetting;

/**
 * Politique de gestion des fichiers — réglage GLOBAL d'instance UNIQUEMENT
 * (décision Henri 2026-07-17). TROIS CAPACITÉS INDÉPENDANTES (pas un mode
 * exclusif, PAS d'override par parc) :
 *  - `home`      : monter le home perso (K:).
 *  - `shares`    : monter les partages serveur (classes H: + répertoires gérés).
 *  - `nextcloud` : « Accès Nextcloud » — l'instance monte les partages SMB
 *                  existants en stockage externe et SE5 provisionne ce montage et
 *                  les comptes (story 61.1).
 *
 * « Web uniquement » n'est PAS une option : c'est l'état nul (tout à `false`) —
 * l'utilisateur passe par le navigateur, rien n'est monté ni provisionné.
 *
 * Persisté dans `SystemSetting` clé `files.policy` (JSON), édité sur
 * `/admin/settings/files`. Défaut `home✓ shares✓ nextcloud✗` (comportement
 * historique : K:/H: montés). Consommé par le
 * {@see \App\Services\Agent\Providers\DrivesStateProvider} (`home`→K:,
 * `shares`→H:+répertoires gérés), résolu PAR CAPACITÉ indépendamment.
 *
 * ---------------------------------------------------------------------------
 * **Story 61.1 — les réglages de connexion NON SECRETS vivent ici.** L'URL, le
 * compte admin, le nom du serveur SMB à monter et la vérification TLS sont du
 * réglage : ils vont dans ce JSON, lisible et diffable. **L'app password admin
 * n'y est PAS** — il vit chiffré dans `service_credentials` sous le nom
 * {@see \App\Services\Nextcloud\NextcloudConnectionConfig::CREDENTIAL_NAME}.
 * `files.policy` est stocké en clair ; y mettre un secret le rendrait lisible à
 * quiconque lit la table des réglages, et à tout export de configuration.
 * ---------------------------------------------------------------------------
 */
final class FilePolicyService
{
    /** Clé SystemSetting du réglage d'instance. */
    public const SETTING_KEY = 'files.policy';

    /**
     * Config globale par défaut : home & partages actifs (historique), accès
     * Nextcloud désactivé, connexion vide.
     *
     * `nextcloud_verify_tls` vaut **`true`** : le chemin legacy désactivait la
     * vérification du certificat en dur dans le code (`getNextcloudAppPassword`),
     * ce qui rendait la faiblesse invisible à l'exploitant. Ici, l'assouplissement
     * est un choix visible, coché sur l'écran et persisté.
     *
     * @return array{home: bool, shares: bool, nextcloud: bool, nextcloud_server_url: string, nextcloud_admin_user: string, nextcloud_smb_host: string, nextcloud_verify_tls: bool}
     */
    public static function defaults(): array
    {
        return [
            'home' => true,
            'shares' => true,
            'nextcloud' => false,
            'nextcloud_server_url' => '',
            'nextcloud_admin_user' => '',
            // Vide = « le serveur de fichiers connu de l'instance ». Le défaut
            // effectif est DÉRIVÉ (`sambaedu.se4fs_name`) au moment du
            // provisionnement, jamais recopié ici : recopier figerait une valeur
            // qui doit suivre la configuration de l'instance.
            'nextcloud_smb_host' => '',
            'nextcloud_verify_tls' => true,
        ];
    }

    /**
     * Config globale persistée, fusionnée avec les défauts (tolère un JSON partiel
     * ou un ancien payload `{mode:...}` : les clés inconnues sont ignorées, on
     * retombe proprement sur les défauts).
     *
     * @return array{home: bool, shares: bool, nextcloud: bool, nextcloud_server_url: string, nextcloud_admin_user: string, nextcloud_smb_host: string, nextcloud_verify_tls: bool}
     */
    public static function globalConfig(): array
    {
        $stored = SystemSetting::get(self::SETTING_KEY);
        $defaults = self::defaults();
        if (! is_array($stored)) {
            return $defaults;
        }

        return [
            'home' => array_key_exists('home', $stored) ? (bool) $stored['home'] : $defaults['home'],
            'shares' => array_key_exists('shares', $stored) ? (bool) $stored['shares'] : $defaults['shares'],
            'nextcloud' => array_key_exists('nextcloud', $stored) ? (bool) $stored['nextcloud'] : $defaults['nextcloud'],
            'nextcloud_server_url' => is_string($stored['nextcloud_server_url'] ?? null)
                ? $stored['nextcloud_server_url']
                : $defaults['nextcloud_server_url'],
            'nextcloud_admin_user' => is_string($stored['nextcloud_admin_user'] ?? null)
                ? $stored['nextcloud_admin_user']
                : $defaults['nextcloud_admin_user'],
            'nextcloud_smb_host' => is_string($stored['nextcloud_smb_host'] ?? null)
                ? $stored['nextcloud_smb_host']
                : $defaults['nextcloud_smb_host'],
            'nextcloud_verify_tls' => array_key_exists('nextcloud_verify_tls', $stored)
                ? (bool) $stored['nextcloud_verify_tls']
                : $defaults['nextcloud_verify_tls'],
        ];
    }

    /**
     * Les trois capacités effectives (sans l'URL) — consommées par le gating des
     * lecteurs.
     *
     * @return array{home: bool, shares: bool, nextcloud: bool}
     */
    public static function capabilities(): array
    {
        $config = self::globalConfig();

        return [
            'home' => $config['home'],
            'shares' => $config['shares'],
            'nextcloud' => $config['nextcloud'],
        ];
    }

    /**
     * Persiste la config globale (upsert SystemSetting). Normalise l'URL.
     *
     * **Les trois derniers paramètres, laissés à `null`, CONSERVENT la valeur
     * persistée.** Ce n'est pas une commodité : les appelants antérieurs à la story
     * 61.1 ne les connaissent pas, et un défaut « chaîne vide » leur ferait effacer
     * la configuration de connexion à chaque bascule de capacité — une perte
     * silencieuse dont personne ne verrait la cause.
     */
    public static function setGlobal(
        bool $home,
        bool $shares,
        bool $nextcloud,
        string $nextcloudServerUrl = '',
        ?string $nextcloudAdminUser = null,
        ?string $nextcloudSmbHost = null,
        ?bool $nextcloudVerifyTls = null,
    ): void {
        $current = self::globalConfig();

        SystemSetting::set(self::SETTING_KEY, [
            'home' => $home,
            'shares' => $shares,
            'nextcloud' => $nextcloud,
            'nextcloud_server_url' => trim($nextcloudServerUrl),
            'nextcloud_admin_user' => trim($nextcloudAdminUser ?? $current['nextcloud_admin_user']),
            'nextcloud_smb_host' => trim($nextcloudSmbHost ?? $current['nextcloud_smb_host']),
            'nextcloud_verify_tls' => $nextcloudVerifyTls ?? $current['nextcloud_verify_tls'],
        ]);
    }
}
