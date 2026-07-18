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
 *  - `nextcloud` : provisionner le client Nextcloud Desktop (natif — consommé par
 *                  le provisioning à venir ; l'URL serveur est stockée dès maintenant).
 *
 * « Web uniquement » n'est PAS une option : c'est l'état nul (tout à `false`) —
 * l'utilisateur passe par le navigateur, rien n'est monté ni provisionné.
 *
 * Persisté dans `SystemSetting` clé `files.policy` (JSON), édité sur
 * `/admin/settings/files`. Défaut `home✓ shares✓ nextcloud✗` (comportement
 * historique : K:/H: montés). Consommé par le
 * {@see \App\Services\Agent\Providers\DrivesStateProvider} (`home`→K:,
 * `shares`→H:+répertoires gérés), résolu PAR CAPACITÉ indépendamment.
 */
final class FilePolicyService
{
    /** Clé SystemSetting du réglage d'instance. */
    public const SETTING_KEY = 'files.policy';

    /**
     * Config globale par défaut : home & partages actifs (historique), Nextcloud
     * natif désactivé, URL serveur vide.
     *
     * @return array{home: bool, shares: bool, nextcloud: bool, nextcloud_server_url: string}
     */
    public static function defaults(): array
    {
        return [
            'home' => true,
            'shares' => true,
            'nextcloud' => false,
            'nextcloud_server_url' => '',
        ];
    }

    /**
     * Config globale persistée, fusionnée avec les défauts (tolère un JSON partiel
     * ou un ancien payload `{mode:...}` : les clés inconnues sont ignorées, on
     * retombe proprement sur les défauts).
     *
     * @return array{home: bool, shares: bool, nextcloud: bool, nextcloud_server_url: string}
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

    /** Persiste la config globale (upsert SystemSetting). Normalise l'URL. */
    public static function setGlobal(bool $home, bool $shares, bool $nextcloud, string $nextcloudServerUrl = ''): void
    {
        SystemSetting::set(self::SETTING_KEY, [
            'home' => $home,
            'shares' => $shares,
            'nextcloud' => $nextcloud,
            'nextcloud_server_url' => trim($nextcloudServerUrl),
        ]);
    }
}
