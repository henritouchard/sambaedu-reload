# QA Manuel — Agent desired-state

**Domaine** : canal agent desired-state (Epics 23/24) — enrôlement/token per-poste, état cible compilé, rapports de conformité, stockage D3, purges.

**Stories couvertes** : 24.1 (`POST /api/v1/agent/report` — ingestion et stockage des rapports). _L'Epic 23 (contrat, token, enrôlement, GET /state) a été validé e2e par Henri le 2026-06-11 (curl/jq + install iPXE réelle) — ses scénarios seront rapatriés ici au fil des stories 24.x si besoin de re-jeu._

**Code de référence** :
- `app/Http/Controllers/Api/V1/Agent/ReportController.php` — controller mince POST /report
- `app/Http/Requests/Api/V1/Agent/ReportRequest.php` — validation (422 avant toute écriture)
- `app/Services/Agent/Reporting/ReportIngestService.php` — upsert état + journal + history
- `app/Console/Commands/PruneAgentReportsCommand.php` — purge `agent:reports:prune`
- `docs/agent/contract-v1.md` (FIGÉ) + `docs/agent/report-endpoint.md` — contrat & transport
- `tests/Fixtures/Agent/report.v1.json` — golden file normatif du rapport

---

## Pré-requis communs

- VM accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`, projet `/var/www/sambaedu-reload`
- Migrations à jour : `php artisan migrate` (tables `agent_resource_states`, `agent_report_events`, `agent_report_history`)
- Caches rafraîchis après déploiement : `php artisan config:cache && php artisan route:cache`
- **Un poste enrôlé** (Epic 23) : token agent 64 hex disponible — soit via une install iPXE réelle (fichier `C:\ProgramData\SambaEdu\Agent\token` du poste), soit en Tinker :
  ```bash
  php artisan tinker --execute="
    \$ws = App\Models\Workstation::first();
    echo app(App\Services\Agent\Enrollment\TokenRotationService::class)->issueFor(\$ws);"
  ```
- Exporter : `TOKEN=<64hex>` ; base URL locale `http://localhost` (Apache VM n'écoute qu'en :80 en lab).

---

## Section 1 — Ingestion des rapports (Story 24.1)

### Scénario 1.1 — Golden file → 200 + état en base

1. Depuis la VM :
   ```bash
   curl -sS -X POST -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     --data @tests/Fixtures/Agent/report.v1.json \
     http://localhost/api/v1/agent/report | jq .
   ```
2. Vérifier en SQL/Tinker : `App\Models\AgentResourceState::where('workstation_id', $ws->id)->get()`.

**Attendu** :
- 200, corps `{"success": true, "counts": {"compliant":1, "drift":1, "drifted_allowed":1, "error":1}}`.
- 4 lignes `agent_resource_states` (wallpaper/overlay/shortcuts/printers), `reported_at` ≈ maintenant.
- `printers` porte `detail` = « service Spooler indisponible… ».
- 3 lignes `agent_report_events` (drift, drifted_allowed, error — PAS le compliant initial).
- `workstations.agent_last_checkin_at` mis à jour (middleware).
- Logs channel agent : 1 `agent.report.received` (info) + 3 `agent.report.drift` (warning).

### Scénario 1.2 — Rapport identique → aucun événement, fraîcheur rafraîchie

1. Rejouer exactement le même curl que 1.1.

**Attendu** :
- 200 ; toujours 4 lignes d'état (volume borné — pas de doublon) ;
- `reported_at` rafraîchi ;
- **aucun** nouvel `agent_report_events` (toujours 3) ; pas de nouveau log `agent.report.drift`.

### Scénario 1.3 — Transition d'état → événement journalisé

1. Éditer une copie du golden : passer `wallpaper` de `compliant` à `drift` (même hash). POSTer.

**Attendu** :
- 200 ; ligne d'état `wallpaper` → `drift` ;
- 1 nouvel événement `wallpaper` avec `previous_status = compliant` ;
- log warning `agent.report.drift` (contexte `workstation_id` + `type`).

### Scénario 1.4 — Rapport malformé → 422, rien n'est écrit

1. POSTer une copie avec `status: "broken"` (hors enum) ou `type: "inconnu"` ou `hash: "xyz"`.
2. Compter les lignes avant/après.

**Attendu** : 422 `{message, errors}` détaillé ; comptes `agent_resource_states`/`agent_report_events`/`agent_report_history` inchangés. Un body JSON volontairement invalide (`--data '{broken'`) → 422, jamais 500.

### Scénario 1.5 — Sans token → 401

```bash
curl -sS -X POST http://localhost/api/v1/agent/report | jq .
```

**Attendu** : 401 `{"error":"unauthorized","code":"AGENT_TOKEN_MISSING"}` (format middleware 23.2). Token bidon (64 « f ») → 401 `AGENT_TOKEN_INVALID`.

### Scénario 1.6 — Flag AGENT_REPORT_HISTORY

1. Flag off (défaut) : POSTer le golden → vérifier `App\Models\AgentReportHistory::count()` = 0.
2. Ajouter `AGENT_REPORT_HISTORY=true` dans `.env`, `php artisan config:cache`, re-POSTer.
3. Vérifier 1 ligne `agent_report_history` avec le payload brut complet.
4. **Nettoyage** : retirer la variable, `php artisan config:cache`.

**Attendu** : history écrit uniquement flag on ; `agent:reports:prune` (lançable à la main) purge events > 14 j et history > 30 j.

---

## Post-correctifs & non-régressions

- **Defer review 23.1 (résolu en 24.1)** : le scénario 1.4 (body forgé → 4xx jamais 500) existe parce qu'un `StateHasher` appelé sur l'entrée agent pouvait lever une `JsonException` non catchée (UTF-8 invalide / NAN / INF). L'ingestion ne hashe JAMAIS le payload agent.

---

## Checklist rapide

- [ ] 1.1 — golden → 200, 4 états, 3 événements, check-in stampé
- [ ] 1.2 — identique → 0 événement, reported_at rafraîchi
- [ ] 1.3 — transition → événement + log drift
- [ ] 1.4 — malformé → 422 sans écriture, jamais 500
- [ ] 1.5 — sans token → 401 JSON middleware
- [ ] 1.6 — flag history off/on + purge
