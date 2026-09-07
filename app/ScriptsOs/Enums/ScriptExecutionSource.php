<?php

declare(strict_types=1);

namespace App\ScriptsOs\Enums;

/**
 * Origine logique du script exécuté. Permet de distinguer dans l'UI
 * scripts managés (17.x) vs scripts legacy.
 *
 *  - `managed_script` — script résolu via la chaîne (WindowsScript / LinuxScript)
 *  - `gpo_applications`  — script issu de la GPO legacy `applications.php` (sans gestion fine 17.x)
 *  - `wpkg_post`         — script post-install lancé par WPKG côté poste
 *  - `manual`            — exécution ad-hoc (debug admin)
 */
enum ScriptExecutionSource: string
{
    case MANAGED_SCRIPT = 'managed_script';
    case GPO_APPLICATIONS = 'gpo_applications';
    case WPKG_POST = 'wpkg_post';
    case MANUAL = 'manual';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
