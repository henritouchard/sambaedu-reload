<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Canal agent desired-state (Epic 23)
|--------------------------------------------------------------------------
|
| Story 23.2 — config minimale : seule l'échéance de rotation du token
| agent vit ici. Complété en 23.5 : ttl_seconds, report_history, rétentions.
| Story 23.3 — TTL du ticket d'enrôlement one-time (porte 1 iPXE).
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

];
