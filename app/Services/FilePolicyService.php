<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FilePolicyMode;
use App\Models\SystemSetting;
use App\Services\Agent\TargetContext;
use Illuminate\Support\Facades\DB;

/**
 * Politique de gestion des fichiers (décision Henri 2026-07-17).
 *
 * Résout la config effective « défaut global surchargé par parc » :
 *  - **Défaut d'instance** : `SystemSetting` clé `files.policy` (JSON), édité sur
 *    la page `/admin/settings/files`. Absent ⇒ défaut `Partages` (historique).
 *  - **Override par parc** : colonnes `files_policy_mode` /
 *    `files_nextcloud_*` sur `workstation_groups` (null = hérite du global).
 *
 * Précédence (iso capacités) : override d'un parc **logique** > **physique** >
 * défaut global. Lecture Postgres PURE (colonnes des WG déjà résolus du
 * {@see TargetContext}) — zéro AD, cohérent avec {@see \App\Services\Agent\Providers\DrivesStateProvider}.
 *
 * Consommée MAINTENANT par le `DrivesStateProvider` (gating des lecteurs via
 * {@see FilePolicyMode::drivesEnabled()}). La config Nextcloud (URLs) est stockée
 * dès à présent mais consommée par le provisioning du client Desktop (à venir).
 */
final class FilePolicyService
{
    /** Clé SystemSetting du défaut d'instance. */
    public const SETTING_KEY = 'files.policy';

    /**
     * Config globale par défaut si aucune n'est persistée — mode `Partages`
     * (comportement historique) et URLs Nextcloud vides.
     *
     * @return array{mode: string, nextcloud: array{server_url: string, web_url: string}}
     */
    public static function defaults(): array
    {
        return [
            'mode' => FilePolicyMode::Partages->value,
            'nextcloud' => ['server_url' => '', 'web_url' => ''],
        ];
    }

    /**
     * Config globale persistée (fusionnée avec les défauts pour tolérer un JSON
     * partiel).
     *
     * @return array{mode: string, nextcloud: array{server_url: string, web_url: string}}
     */
    public static function globalConfig(): array
    {
        $stored = SystemSetting::get(self::SETTING_KEY);
        if (! is_array($stored)) {
            return self::defaults();
        }

        $defaults = self::defaults();

        return [
            'mode' => is_string($stored['mode'] ?? null) ? $stored['mode'] : $defaults['mode'],
            'nextcloud' => [
                'server_url' => (string) ($stored['nextcloud']['server_url'] ?? ''),
                'web_url' => (string) ($stored['nextcloud']['web_url'] ?? ''),
            ],
        ];
    }

    /** Mode global d'instance (repli `Partages` si la valeur stockée est invalide). */
    public static function globalMode(): FilePolicyMode
    {
        return FilePolicyMode::tryFrom(self::globalConfig()['mode']) ?? FilePolicyMode::Partages;
    }

    /** Persiste la config globale (upsert SystemSetting). Normalise les champs connus. */
    public static function setGlobal(FilePolicyMode $mode, string $nextcloudServerUrl = '', string $nextcloudWebUrl = ''): void
    {
        SystemSetting::set(self::SETTING_KEY, [
            'mode' => $mode->value,
            'nextcloud' => [
                'server_url' => trim($nextcloudServerUrl),
                'web_url' => trim($nextcloudWebUrl),
            ],
        ]);
    }

    /**
     * Mode EFFECTIF pour un contexte poste/session : override par parc (logique >
     * physique) sinon défaut global. Utilisé par le `DrivesStateProvider` pour
     * décider d'émettre ou non les lecteurs.
     */
    public static function effectiveMode(TargetContext $ctx): FilePolicyMode
    {
        $override = self::overrideModeAmong($ctx->logicalGroupIds)
            ?? self::overrideModeAmong($ctx->physicalGroupIds);

        return $override ?? self::globalMode();
    }

    /**
     * Première surcharge de mode non-nulle parmi un jeu de WG (ordre `id` asc,
     * déterministe), ou null si aucun de ces parcs ne surcharge le mode.
     *
     * @param  list<int>  $wgIds
     */
    private static function overrideModeAmong(array $wgIds): ?FilePolicyMode
    {
        if ($wgIds === []) {
            return null;
        }

        $value = DB::table('workstation_groups')
            ->whereIn('id', $wgIds)
            ->whereNotNull('files_policy_mode')
            ->orderBy('id')
            ->value('files_policy_mode');

        return is_string($value) ? FilePolicyMode::tryFrom($value) : null;
    }
}
