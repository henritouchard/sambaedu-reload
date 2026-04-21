<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuration personnalisation applicative — Story 4.8
|--------------------------------------------------------------------------
| Paramètres pour AppCustomizationService + FirefoxPolicyAdapter + ThunderbirdPolicyAdapter
| + FirefoxExtensionResolver (SSRF guard).
*/

return [
    /*
     | Export FS on save : si true, écrit `/etc/sambaedu/applications/{kind}/<key>.json`
     | à chaque `AppCustomizationService::savePolicies()`. Filet de sécurité rollback :
     | si la route Laravel est désactivée, les clients legacy retombent sur
     | les fichiers écrits ici (sans passer par Laravel).
     */
    'export_fs_on_save' => env('APP_CUSTOMIZATIONS_EXPORT_FS', true),

    /*
     | Chemin de base des overrides scope-level (format legacy).
     | Le nom de fichier est `<key>.json` avec :
     |   - `default` si scope global (NULL/NULL, is_default=true)
     |   - `<owner->login>` pour User
     |   - `<owner->name>` pour UserGroup / WorkstationGroup
     */
    'fs_base_path' => env('APP_CUSTOMIZATIONS_FS_BASE', '/etc/sambaedu/applications'),

    /*
     | Chemins des templates système par AppKind (fallback dev/CI inclus).
     | Premier chemin existant = gagnant.
     */
    'template_paths' => [
        'firefox' => [
            env('APP_CUSTOMIZATIONS_FIREFOX_TEMPLATE', '/usr/share/sambaedu/applications/firefox/default.json'),
            storage_path('app/app-customizations/firefox/template.json'),
        ],
        'thunderbird' => [
            env('APP_CUSTOMIZATIONS_THUNDERBIRD_TEMPLATE', '/usr/share/sambaedu/applications/thunderbird/default.json'),
            storage_path('app/app-customizations/thunderbird/template.json'),
        ],
    ],

    /*
     | Taille max en octets des policies_json (défense en profondeur côté service).
     | Vérifiée dans `validatePolicies` par chaque adapter.
     */
    'max_policies_size' => env('APP_CUSTOMIZATIONS_MAX_SIZE', 262144), // 256 Ko

    /*
     | Configuration système pour `applyAuto` (proxy, DNS, popups).
     | Les valeurs par défaut tombent sur le réseau local (proxy_type=aucun).
     | En prod, ces champs sont remplis depuis `config/sambaedu.php` ou env.
     */
    'system_config' => [
        'se4_url' => env('SE4_URL', 'http://se4fs'),
        'se4fs_name' => env('SE4FS_NAME', 'se4fs'),
        'proxy_type' => env('SAMBAEDU_PROXY_TYPE', 'aucun'),
        'proxy_address' => env('SAMBAEDU_PROXY_ADDRESS', ''),
        'proxy_port' => env('SAMBAEDU_PROXY_PORT', ''),
        'proxy_url' => env('SAMBAEDU_PROXY_URL', ''),
    ],

    /*
     | Firefox — FirefoxExtensionResolver (addons custom XPI, SSRF durci)
     | + FirefoxAddonResolver (API publique AMO, chemin privilégié).
     */
    'firefox' => [
        // Addons AMO — chemin privilégié (API JSON, pas de download XPI)
        'addon_resolver' => [
            'timeout' => (int) env('APP_CUSTOMIZATIONS_FIREFOX_AMO_TIMEOUT', 5),
            'connect_timeout' => (int) env('APP_CUSTOMIZATIONS_FIREFOX_AMO_CONNECT_TIMEOUT', 3),
        ],
        // XPI custom (addons maison hors AMO — fallback)
        'extension_resolver' => [
            'allowed_domains' => array_filter(explode(
                ',',
                (string) env('APP_CUSTOMIZATIONS_FIREFOX_EXT_DOMAINS', 'addons.mozilla.org'),
            )),
            'timeout' => (int) env('APP_CUSTOMIZATIONS_FIREFOX_EXT_TIMEOUT', 5),
            'connect_timeout' => (int) env('APP_CUSTOMIZATIONS_FIREFOX_EXT_CONNECT_TIMEOUT', 3),
            'max_size' => (int) env('APP_CUSTOMIZATIONS_FIREFOX_EXT_MAX_SIZE', 10_485_760),
            'dns_rebinding_guard' => (bool) env('APP_CUSTOMIZATIONS_FIREFOX_EXT_DNS_GUARD', true),
        ],
    ],
];
