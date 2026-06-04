<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuration e2e (Story 21.2 — fake AD/Samba)
|--------------------------------------------------------------------------
|
| Clés consommées UNIQUEMENT par le doublage AD/auth fake quand
| `APP_ENV === 'e2e'`. Inertes hors e2e (les fakes ne sont jamais bindés).
| Le fichier est versionné mais ne contient AUCUN secret : les valeurs réelles
| viennent de `.env.e2e` (sur la VM), jamais commité.
|
*/

return [
    /*
    | Mot de passe e2e PARTAGÉ par défaut des utilisateurs seedés. Utilisé par
    | FakeE2eAdCredentialValidator quand aucun override par login n'est défini.
    | Le seed de référence (Story 21.3) fournira les users ; ce mot de passe est
    | le credential « connu » des parcours Playwright.
    */
    'fake_ad_password' => env('E2E_FAKE_AD_PASSWORD'),

    /*
    | Overrides par login (map `login => mot de passe`). Optionnel : permet des
    | mots de passe distincts par rôle/utilisateur si un parcours l'exige. Vide
    | par défaut → tous les users partagent `fake_ad_password`.
    |
    | Renseigné par le seed/`.env.e2e` (ex. via un provider de seed 21.3) — pas
    | de syntaxe `.env` native pour une map, donc laissé programmatique ici.
    */
    'fake_ad_passwords' => [],
];
