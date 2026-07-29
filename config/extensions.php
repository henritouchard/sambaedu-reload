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

];
