<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuration des icônes de raccourcis — Story 27.7
|--------------------------------------------------------------------------
| Dossier où sont déposées les icônes UPLOADÉES content-addressed
| (`<sha256>.ico`), servies EN DIRECT par un Alias Apache scopé (PAS par
| Laravel/FPM, décision n° 1). L'agent Go fait un GET HTTP simple sur
| `<server_url>/<route_path>/<sha>.ico`, vérifie le checksum, écrit local.
|
| GARDE-FOU SÉCURITÉ (décision n° 3, piège n° 1) : `served_path` pointe
| EXACTEMENT sur ce sous-dossier dédié, JAMAIS sur `storage/` entier
| (`storage/keys/pki/` = PFX code-signing + clés CA). L'Alias Apache
| (config/apache/sambaedu.conf) reproduit ce scope. Seuls des blobs
| public-safe (`.ico`) entrent ici.
|
| Sous `storage/` → exception assumée « non-versionné mais client-facing »
| (project_storage_convention_non_versioned, piège n° 11). À chown
| www-admin (uid 599, project_php_fpm_user_www_admin) sur la VM.
*/

return [
    /*
     | Dossier physique servi (content-addressed). Relocatable par cette seule
     | clé : les colonnes shortcuts.icon_asset ne stockent que le filename.
     */
    'served_path' => env('SHORTCUT_ICONS_PATH', storage_path('app/shortcut-icons')),

    /*
     | Chemin de la route statique (Alias Apache). L'agent dérive l'URL :
     | `server_url + '/' + route_path + '/' + icon_asset` (décision n° 4,
     | « pas de champ url » iso 24.4). Doit matcher l'Alias Apache.
     */
    'route_path' => env('SHORTCUT_ICONS_ROUTE', 'assets/shortcut-icons'),

    /*
     | Dossier legacy name-addressed (`<name>.ico`) — source du backfill
     | (shortcuts:backfill-icons). Aligné sur ShortcutsService::$iconsPath.
     | Conservé tel quel (les fichiers sont COPIÉS, jamais supprimés).
     */
    'legacy_path' => env('SHORTCUT_ICONS_LEGACY_PATH', '/etc/sambaedu/applications/shortcuts'),
];
