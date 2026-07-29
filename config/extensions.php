<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Système d'extensions SE5 (Epic 54)
|--------------------------------------------------------------------------
|
| Story 54.1 — socle : bibliothèque admin et fiches d'extension.
|
| Aucun réglage métier ici : les paramètres pilotables par l'admin passent par
| `SystemSetting` (AR14), et 54.1 n'en introduit AUCUN. Ce fichier ne porte que
| le chemin de DÉCOUVERTE de la source embarquée — surchargeable pour tester le
| cas « découverte vide » ou un jeu de manifests de test sans dépendre du
| contenu réel du dépôt (patron `config('agent.tools_embedded_path')`, cf.
| App\Console\Commands\AgentToolsRegisterDefaultsCommand).
|
*/

return [

    // Racine des manifests EMBARQUÉS dans le dépôt SE5 : un dossier par
    // extension, chacun portant un `manifest.json`
    // (`resources/extensions/<id>/manifest.json`). Le dossier laisse la place
    // à des assets par extension plus tard (icônes, ressources statiques).
    'bundled_path' => env('EXTENSIONS_BUNDLED_PATH', base_path('resources/extensions')),

    /*
    |--------------------------------------------------------------------------
    | Sources DISTANTES (Story 56.1)
    |--------------------------------------------------------------------------
    |
    | Bornes de la récupération HTTP d'un catalogue distant. Ce sont des bornes
    | de SÉCURITÉ et de robustesse, pas des réglages métier : elles n'ont donc
    | rien à faire dans `SystemSetting` (AR14 — on n'invente pas un réglage
    | admin là où il n'y en a pas).
    |
    | ⚠️ Les redirections ne sont JAMAIS suivies (`allow_redirects => false`
    | posé dans le service) : toute 3xx compte comme dépôt injoignable. C'est
    | plus simple et plus sûr qu'une liste blanche d'hôtes — un dépôt ne peut
    | pas emmener SE5 vers un autre serveur.
    |
    | ⚠️ Les bornes de taille sont vérifiées APRÈS téléchargement, sur les
    | octets réellement reçus : ni un sha256 ni une signature ne bornent une
    | taille (leçon `ArtifactPullService`, review 39.4 #3).
    |
    */
    'remote' => [

        // Établissement de la connexion (DNS + TCP + TLS).
        'connect_timeout' => (int) env('EXTENSIONS_REMOTE_CONNECT_TIMEOUT', 5),

        // Durée totale d'une requête.
        'timeout' => (int) env('EXTENSIONS_REMOTE_TIMEOUT', 15),

        // Taille maximale d'un `index.json` (1 MiB : un catalogue est du texte,
        // quelques dizaines de manifests au plus).
        'index_max_bytes' => (int) env('EXTENSIONS_REMOTE_INDEX_MAX_BYTES', 1_048_576),
    ],

    /*
    |--------------------------------------------------------------------------
    | Moteur d'INSTALLATION des extensions `app` (Story 56.2)
    |--------------------------------------------------------------------------
    |
    | Bornes de SÉCURITÉ et chemins de déploiement du moteur
    | `ext:install` / `ext:remove`. Comme le bloc `remote`, ce ne sont PAS des
    | réglages métier : rien ici n'a sa place dans `SystemSetting` (AR14 — on
    | n'invente pas un réglage admin là où il n'y en a pas). Un opérateur qui
    | doit vraiment déplacer le staging ou la plage de ports le fait par `.env`,
    | en connaissance de cause.
    |
    */
    'install' => [

        // Staging des paquets téléchargés, CONTENT-ADDRESSED
        // (`<staging>/<key>/<sha256>.deb`). Un paquet vérifié y survit à un
        // échec d'installation : la relance ne re-télécharge pas (NFR8). Le
        // helper root REFUSE d'installer un `.deb` situé hors de ce répertoire.
        'staging_path' => env('EXTENSIONS_INSTALL_STAGING_PATH', storage_path('app/extensions/packages')),

        // Borne DURE de la taille d'un paquet (256 MiB). Appliquée à la LECTURE,
        // pas après coup : une borne vérifiée quand les octets sont déjà arrivés
        // ne borne rien, elle déplace juste l'épuisement de la RAM vers le
        // disque (leçon review 56.1 #2).
        'package_max_bytes' => (int) env('EXTENSIONS_INSTALL_PACKAGE_MAX_BYTES', 268_435_456),

        // Durée totale du téléchargement d'un paquet (un `.deb` de plusieurs
        // dizaines de Mo sur une liaison d'établissement).
        'download_timeout' => (int) env('EXTENSIONS_INSTALL_DOWNLOAD_TIMEOUT', 300),

        // Établissement de connexion — même valeur que le bloc `remote` :
        // un dépôt injoignable doit le rester vite.
        'connect_timeout' => (int) env('EXTENSIONS_INSTALL_CONNECT_TIMEOUT', 5),

        // LE seul binaire privilégié du moteur : déployé par
        // `ensure_extension_engine` (install.sh / update.sh) et autorisé par
        // une ligne de `/etc/sudoers.d/sambaedu-ext`. Toutes les validations de
        // sécurité vivent DEDANS, côté root — jamais dans l'appelant PHP.
        //
        // Sudoers attendu sur la VM :
        //   www-admin ALL=(root) NOPASSWD: /usr/share/sambaedu/sbin/sambaedu-ext-helper.sh
        'helper_path' => env('EXTENSIONS_INSTALL_HELPER_PATH', '/usr/share/sambaedu/sbin/sambaedu-ext-helper.sh'),

        // Plage de ports de boucle locale ASSIGNÉS par SE5 aux backends
        // d'extensions (jamais déclarés par un manifest — décision 56.2 #1).
        // Le premier libre est pris sous le verrou global d'installation.
        'port_range' => [
            (int) env('EXTENSIONS_INSTALL_PORT_MIN', 8600),
            (int) env('EXTENSIONS_INSTALL_PORT_MAX', 8699),
        ],
    ],

];
