<?php

/**
 * Stubs pour les dépendances manquantes des includes GPO (story 1bis.18a).
 *
 * Ces fonctions sont appelées par les 4 fichiers GPO core mais définies
 * dans des fichiers non chargés par le bootstrap :
 * - guid()                    → printers.inc.php (appelée par delegations.inc.php)
 * - roaming_profiles_stats()  → partages.inc.php (appelée par gpo_ui.inc.php)
 * - search_parcs()            → ldap.inc.php legacy (appelée par delegations.inc.php)
 *
 * Chaque stub est protégé par function_exists() pour être remplacé
 * transparentement quand le module correspondant sera chargé.
 */

if (!function_exists('guid')) {
    /**
     * Génère un GUID au format Microsoft avec accolades et majuscules.
     * Reproduit à l'identique le format de printers.inc.php (story 1bis.15),
     * utilisé par delegations.inc.php comme cn LDAP et dans des DN.
     * Format : {XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX}
     */
    function guid(): string
    {
        $charid = strtoupper(md5(uniqid((string) rand(), true)));
        $hyphen = chr(45); // "-"
        return chr(123) // "{"
            . substr($charid, 0, 8) . $hyphen
            . substr($charid, 8, 4) . $hyphen
            . substr($charid, 12, 4) . $hyphen
            . substr($charid, 16, 4) . $hyphen
            . substr($charid, 20, 12)
            . chr(125); // "}"
    }
}

if (!function_exists('roaming_profiles_stats')) {
    /**
     * Retourne les statistiques des profils itinérants.
     * Utilisé par gpo_ui.inc.php (table_roam_stats, table_roam_stats_user).
     * Définie normalement dans partages.inc.php.
     *
     * @return array Tableau vide — les stats ne sont pas disponibles sans le module partages.
     */
    function roaming_profiles_stats(): array
    {
        return [];
    }
}

if (!function_exists('search_parcs')) {
    /**
     * Recherche dans les parcs/salles (OUs machines).
     * Utilisé par delegations.inc.php pour retrouver l'OU d'une salle.
     * Définie normalement dans sambaedu/includes/ldap.inc.php (non chargé — le shim ne l'inclut pas).
     *
     * Ce stub logue l'appel et retourne un tableau vide.
     * Il sera remplacé quand le module parcs (1bis.13) sera chargé.
     *
     * @param array  $config  Configuration legacy
     * @param string $search  Nom de la salle/parc
     * @param string $type    Type de recherche (salle, parc, all)
     * @return array Résultats de recherche au format LDAP legacy
     */
    function search_parcs(array $config, string $search, string $type = 'all'): array
    {
        if (function_exists('app') && app()->bound(\App\Services\ErrorLoggerService::class)) {
            app(\App\Services\ErrorLoggerService::class)->log(
                'legacy',
                "search_parcs() stub appelé — module parcs non chargé [search=$search, type=$type]"
            );
        }
        return [];
    }
}
