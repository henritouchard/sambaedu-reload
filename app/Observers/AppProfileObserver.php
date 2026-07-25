<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AppProfile;

/**
 * Observer des AppProfile (profils WPKG).
 *
 * Story 38.7 — `OU=Parcs` est en LECTURE SEULE : un `AppProfile` n'a plus AUCUNE
 * représentation écrite dans l'AD. Les dispatches de `AppProfileAdSyncJob`
 * (create / rename / delete du `CN` dans `OU=Parcs`) ont été RETIRÉS, ainsi que
 * le service d'écriture `AppProfileAdSyncService`. Les seuls lecteurs du `CN`
 * d'un profil étaient l'importeur de migration (qui LIT `OU=Parcs`) et un badge
 * de synchronisation devenu sans objet — tous deux traités par la story.
 *
 * Le calcul des applications WPKG (`profiles.xml`) se fait intégralement depuis
 * SQL. Cet observer ne conserve que le drapeau de désactivation utilisé par
 * l'importeur pour ses écritures en masse.
 */
class AppProfileObserver
{
    /**
     * Indique si une éventuelle synchronisation est activée. Conservé pour la
     * compatibilité de l'importeur ({@see \App\Services\AppProfile\AppProfileAdImporter}) ;
     * n'ordonne plus aucune écriture AD depuis 38.7.
     */
    public static bool $syncEnabled = true;

    public static function disableSync(): void
    {
        self::$syncEnabled = false;
    }

    public static function enableSync(): void
    {
        self::$syncEnabled = true;
    }

    /**
     * Exécute un callback sans synchronisation AD.
     */
    public static function withoutSync(callable $callback): mixed
    {
        $wasEnabled = self::$syncEnabled;
        self::$syncEnabled = false;

        try {
            return $callback();
        } finally {
            self::$syncEnabled = $wasEnabled;
        }
    }
}
