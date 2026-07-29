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

];
