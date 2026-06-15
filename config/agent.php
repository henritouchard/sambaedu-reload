<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Canal agent desired-state (Epic 23)
|--------------------------------------------------------------------------
|
| Story 23.2 — config minimale : seule l'échéance de rotation du token
| agent vit ici.
| Story 23.3 — TTL du ticket d'enrôlement one-time (porte 1 iPXE).
| Story 23.5 — complétion (gap 4 architecture, valeurs fixées) :
| ttl_seconds, report_history, rétentions events/history. Les planchers
| max(1, …) sont évalués au config:cache : une env mal renseignée (0,
| négatif, vide → null casté 0) ne produit jamais de valeur dégénérée.
|
*/

return [

    // Échéance de rotation glissante du token agent (D5, FR13) : au premier
    // check-in passé ce délai, le serveur ré-émet un token via le header
    // X-Agent-New-Token ; l'ancien reste valide jusqu'au premier usage du
    // nouveau (fenêtre de grâce — cf. docs/agent/token-lifecycle.md).
    'token_rotation_days' => (int) env('AGENT_TOKEN_ROTATION_DAYS', 30),

    // Durée de vie du ticket d'enrôlement one-time (porte 1 — Story 23.3) :
    // émis à la génération de l'unattend.xml, échangé contre le token au
    // premier logon. 240 min couvre une install lente (miroir froid, poste
    // poussif) sans laisser traîner un secret actif des jours — le ticket
    // est de toute façon consommé au premier usage
    // (cf. docs/agent/enrollment.md).
    'enroll_ticket_ttl_minutes' => (int) env('AGENT_ENROLL_TICKET_TTL_MINUTES', 240),

    // Cadence de poll conseillée à l'agent (Story 23.5) : champ `ttl_seconds`
    // de l'enveloppe se5.desired-state/v1 servie par GET /api/v1/agent/state.
    // 3600 s = 60 min (D7), aligné sur le golden file du contrat. Indicatif :
    // le serveur ne refuse pas un poll plus fréquent (throttle à part).
    'ttl_seconds' => max(1, (int) env('AGENT_STATE_TTL_SECONDS', 3600)),

    // Historique de débogage des rapports agent (flag D3, consommé en 24.1) :
    // off par défaut — seuls le dernier état rapporté et le journal des
    // changements sont conservés en fonctionnement nominal.
    'report_history' => (bool) env('AGENT_REPORT_HISTORY', false),

    // Rétention du journal des changements rapportés par les agents
    // (« rétention courte » D3, purge consommée en 24.1).
    'report_events_retention_days' => max(1, (int) env('AGENT_REPORT_EVENTS_RETENTION_DAYS', 14)),

    // Rétention de l'historique de débogage (si report_history est activé) :
    // purge automatique, l'historique ne grossit jamais sans borne.
    'report_history_retention_days' => max(1, (int) env('AGENT_REPORT_HISTORY_RETENTION_DAYS', 30)),

    // Répertoire des binaires de release de l'agent (D6, Story 25.1 —
    // distribution canari par rings). Dépôt direct sur le serveur (hors
    // git/inotify, convention storage), lisible www-admin (uid 599) sinon
    // hash_file()/serving échouent silencieusement. Surchargé en test vers
    // un répertoire temporaire (jamais d'écriture dans le vrai storage/).
    'releases_path' => env('AGENT_RELEASES_PATH', storage_path('agent/releases')),

    // Répertoire des artefacts d'OUTILS DE RENDU posés par l'agent au
    // bootstrap (Story 27.1bis, D8 — aujourd'hui : l'archive PORTABLE de
    // Rainmeter). DÉLIBÉRÉMENT distinct de `releases_path` : `agent_releases`
    // est réservé au binaire agent + auto-update (25.2), un outil tiers vit
    // ailleurs. Dépôt direct sur le serveur (hors git/inotify, convention
    // storage), lisible www-admin (uid 599) sinon le serving échoue
    // silencieusement (404). Surchargé en test vers un répertoire temporaire.
    'tools_path' => env('AGENT_TOOLS_PATH', storage_path('agent/tools')),

];
