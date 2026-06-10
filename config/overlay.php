<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuration overlay poste (POC successeur bandeau wallpaper)
|--------------------------------------------------------------------------
| Contrat JSON consommé par l'overlay client (Rainmeter / Conky) via
| `GET /api/v1/workstation-config/overlay`. Cf. spike
| `_bmad-output/planning-artifacts/spike-wallpaper-overlay-tools-2026-06-09.md`.
|
| ⚠️ Après modification de ce fichier sur la VM : `php artisan config:cache`
| (+ chown www-admin) sinon les nouvelles clés ressortent null.
*/

return [
    /*
     | Version du schéma du payload. L'overlay client refuse un major inconnu.
     */
    'schema' => 'se5.wallpaper-overlay/v1',

    /*
     | Cadence de poll conseillée (secondes), exposée au client via
     | `ttl_seconds`. À aligner sur l'intervalle « refresh » du GPO-dispatcher.
     */
    'ttl_seconds' => (int) env('OVERLAY_TTL_SECONDS', 60),
];
