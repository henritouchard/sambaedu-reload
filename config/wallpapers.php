<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuration wallpapers — Story 4.7
|--------------------------------------------------------------------------
| Constantes design du bandeau inférieur (Option C — refonte 2026), chemins
| legacy et flags fonctionnels. Cf. AC 5 bis.
*/

return [
    /*
     | Bibliothèque de wallpapers (nouveau modèle asset+assignation) : racine
     | où sont stockés les fichiers content-addressés `<checksum>.<ext>`.
     | Sous `storage/` → sauvegardable / redéployable sans perte. Relocatable
     | par cette seule clé (les assets ne stockent que le filename).
     */
    'library_path' => env('WALLPAPER_LIBRARY_PATH', storage_path('app/wallpaper')),

    /*
     | Storage legacy : emplacement historique des fichiers `<type>@<key>.jpg`
     | `/etc/sambaedu/applications/wallpaper/`. Conservé UNIQUEMENT comme source
     | de la migration de données (backfill_wallpaper_assets) qui rapatrie ces
     | fichiers vers `library_path`. Plus aucun code de runtime ne le lit.
     */
    'storage_path' => env('WALLPAPER_STORAGE_PATH', '/etc/sambaedu/applications/wallpaper'),

    /*
     | Chemin du default système fourni par le paquet SE (fallback ultime).
     */
    'system_default_path' => env(
        'WALLPAPER_SYSTEM_DEFAULT',
        '/usr/share/sambaedu/applications/wallpaper/default.jpg',
    ),

    /*
     | Logo établissement (lockscreen uniquement).
     */
    'logo_etab_path' => env(
        'WALLPAPER_LOGO_ETAB',
        '/etc/sambaedu/applications/wallpaper/logo.png',
    ),

    /*
     | Logo sambaedu (lockscreen uniquement).
     */
    'logo_sambaedu_path' => env(
        'WALLPAPER_LOGO_SAMBAEDU',
        '/usr/share/sambaedu/applications/wallpaper/sambaedu.png',
    ),

    /*
     | Limite upload (10 Mo).
     */
    'max_upload_size' => env('WALLPAPER_MAX_UPLOAD_SIZE', 10_485_760),

    /*
     | Extensions autorisées à l'upload.
     */
    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'],

    /*
     | Mode minimal : masque la zone gauche (identité user/machine), garde
     | uniquement les badges critiques + cartouches alertes.
     */
    'minimal_mode' => env('WALLPAPER_MINIMAL', false),

    /*
     | Autorise l'UI per-user (onglet visible sur /users/{login}).
     */
    'allow_per_user' => env('WALLPAPER_ALLOW_PER_USER', true),

    /*
     | Support perso wallpaper : /home/<user>/Photos/wallpaper.jpg (priorité
     | absolue dans le Resolver legacy). Mis à false par défaut : équivalent
     | `$config['perso_wallpaper']` côté legacy. Activable par établissement.
     */
    'perso_wallpaper' => env('WALLPAPER_PERSO', false),

    /*
     | Base path des homes utilisateurs — utilisé pour construire le chemin
     | `<base>/<login>/Photos/wallpaper.jpg` du niveau 7 du Resolver.
     | Default legacy : `/home`. Configurable pour permettre des tests
     | end-to-end (post-review #8).
     */
    'personal_base_path' => env('WALLPAPER_PERSONAL_BASE_PATH', '/home'),

    /*
     | Types principaux (UserGroup) utilisés au niveau 4 de résolution.
     | Premier match wins (iter l'ordre).
     */
    'main_types' => ['Profs', 'Eleves', 'Administratifs'],

    /*
     | Badges iconographiques (chemins relatifs à resource_path()).
     */
    'badges' => [
        'admin' => 'assets/wallpaper-icons/badge-admin.png',
        'veyon' => 'assets/wallpaper-icons/badge-veyon.png',
        'quota-warning' => 'assets/wallpaper-icons/badge-quota-warning.png',
        'multi-session' => 'assets/wallpaper-icons/badge-multi-session.png',
    ],

    /*
     | Fonts (priorité haut → bas, première présente gagne).
     */
    'fonts' => [
        'bold' => [
            'resources/fonts/Atkinson-Hyperlegible-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        ],
        'regular' => [
            'resources/fonts/Atkinson-Hyperlegible-Regular.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ],
    ],

    /*
     | Constantes design du bandeau inférieur (AC 5 bis).
     */
    'banner_height' => 120,
    'banner_gradient_top' => 'rgba(0,0,0,0)',
    'banner_gradient_bottom' => 'rgba(0,0,0,0.65)',
    'banner_padding_horizontal' => 64,
    'banner_padding_vertical' => 20,
    'title_font_size' => 28,
    'subtitle_font_size' => 18,
    'alert_font_size' => 36,
    'badge_size' => 48,
    'badge_gap' => 16,

    /*
     | Messages d'alerte (surcouchables par config('sambaedu.veyon_message')).
     */
    'messages' => [
        'veyon' => 'Prise de contrôle à distance en cours',
        'multi_session' => 'Sessions détectées sur : ',
        'wait' => 'En cours de connexion…',
        'quota_title' => 'Stockage saturé',
        'quota_body' => "Libérez de l'espace et reconnectez-vous.",
    ],
];
