# QA Manuel — Agent desired-state

**Domaine** : canal agent desired-state (Epics 23/24) — enrôlement/token per-poste, état cible compilé, rapports de conformité, stockage D3, purges.

**Stories couvertes** : 24.1 (`POST /api/v1/agent/report` — ingestion et stockage des rapports), 24.2 (agent squelette Windows — boucle check-in/cache/report). _L'Epic 23 (contrat, token, enrôlement, GET /state) a été validé e2e par Henri le 2026-06-11 (curl/jq + install iPXE réelle) — ses scénarios seront rapatriés ici au fil des stories 24.x si besoin de re-jeu._

**Code de référence** :
- `app/Http/Controllers/Api/V1/Agent/ReportController.php` — controller mince POST /report
- `app/Http/Requests/Api/V1/Agent/ReportRequest.php` — validation (422 avant toute écriture)
- `app/Services/Agent/Reporting/ReportIngestService.php` — upsert état + journal + history
- `app/Console/Commands/PruneAgentReportsCommand.php` — purge `agent:reports:prune`
- `docs/agent/contract-v1.md` (FIGÉ) + `docs/agent/report-endpoint.md` — contrat & transport
- `tests/Fixtures/Agent/report.v1.json` — golden file normatif du rapport
- `agent/` (top-level, hors Laravel) — agent Windows squelette : `windows/SambaEduAgent.ps1` (boucle), `shared/ContractV1.ps1` (contrat), `build/Build-Agent.ps1` (signature) ; contrats locaux dans `agent/README.md`
- `docs/agent/agent-skeleton.md` — boucle attendue vue serveur (hostname court, codes HTTP, fichiers du poste)

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

## Section 2 — Boucle agent squelette (Story 24.2)

> Les invariants serveur de la boucle sont déjà couverts en CI par
> `tests/Feature/Api/V1/Agent/AgentSkeletonE2eTest.php` (`php artisan test
> --filter AgentSkeleton`). Cette section est le test e2e **avec le vrai
> agent** sur le poste lab (windoobe, ws 49) — AC8.

### Scénario 2.1 — Simulation de la boucle en curl (sans poste)

Reproduit à la main ce que fait `SambaEduAgent.ps1` (utile pour isoler un
problème serveur vs agent) :

1. `GET /state` + capture ETag :
   ```bash
   curl -sS -D /tmp/h -H "Authorization: Bearer $TOKEN" http://localhost/api/v1/agent/state -o /tmp/state.json
   ETAG=$(grep -i '^etag:' /tmp/h | awk '{print $2}' | tr -d '\r')
   ```
2. Re-call conditionnel : `curl -sS -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $TOKEN" -H "If-None-Match: $ETAG" http://localhost/api/v1/agent/state` → **304**.
3. Rapport squelette vide (hostname **COURT** = `workstations.name`, uuid = `workstations.uuid` du poste) :
   ```bash
   curl -sS -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
     -d '{"schema":"se5.desired-state/v1","generated_at":"'"$(date -u +%FT%TZ)"'","agent_version":"1.0.0","workstation":{"hostname":"<NOM-COURT>","uuid":"<UUID>"},"items":[]}' \
     http://localhost/api/v1/agent/report | jq .
   ```

**Attendu** : 200 `{"success":true,"counts":{"compliant":0,"drift":0,"drifted_allowed":0,"error":0}}` ; `agent_last_checkin_at` rafraîchi ; **aucune** ligne `agent_resource_states` (items vides = normal) ; log `agent.report.received` sans `identity_mismatch`. Avec un hostname FQDN à la place du nom court → toujours 200 mais warning `agent.report.identity_mismatch` (c'est le spam que le contrat hostname court évite).

### Scénario 2.2 — Installation du service sur le poste lab (AC8)

1. Builder l'artefact signé (poste de build avec le certificat code-signing SambaEdu — cf. `agent/README.md` §Signature) : `agent\build\Build-Agent.ps1 -CertificateThumbprint <hex>`.
2. Copier `agent/build/dist/` sur le poste lab (déjà enrôlé 23.3), puis en admin :
   ```powershell
   .\Install-SambaEduAgent.ps1 -ServerUrl 'http://<serveur-se5>'
   ```
3. Vérifier sur le poste : `Get-Service SambaEduAgent` → Running ; `Get-Content C:\ProgramData\SambaEdu\Agent\logs\agent.log` → `GET /state -> 200` puis `POST /report -> 200 : rapport accepte, boucle fermee.` ; `cache\state.json` + `cache\etag.txt` + `applied-state.json` présents, ACL SYSTEM+Administrators (`icacls`).
4. Vérifier sur le serveur (/vm) :
   ```bash
   php artisan tinker --execute="
     \$ws = App\Models\Workstation::where('name', '<NOM-COURT>')->first();
     echo \$ws->agent_last_checkin_at;"
   ```
   et channel agent : `grep agent.report.received storage/logs/agent*.log | tail`.

**Attendu** : check-in posé, `agent.report.received` avec counts à zéro, **zéro** `identity_mismatch`, signature des `.ps1` valide (`Get-AuthenticodeSignature ...\SambaEduAgent.ps1` → `Valid`), SmartScreen/ExecutionPolicy ne bloquent pas.

### Scénario 2.3 — Résilience (backoff, quarantaine, rotation)

1. **Backoff** : couper le réseau du poste (ou arrêter Apache) → `agent.log` montre `Serveur injoignable ... Prochain essai dans 30 s` puis 60, 120… plafonné 3600 ; aucun crash, reprise propre au retour du serveur.
2. **Quarantaine** : en Tinker `app(App\Services\Agent\Enrollment\TokenRotationService::class)->quarantine($ws, 'qa')` → au cycle suivant `agent.log` indique le passage en check-ins légers ; plus aucun `agent.report.received` côté serveur mais `agent_last_checkin_at` continue d'avancer. Lever (`agent_quarantined_at = null`) → reprise du cycle complet au check-in suivant.
3. **Rotation D5** : en SQL, vieillir `agent_token_rotated_at` de 31 jours → au cycle suivant, `agent.log` montre `Rotation token recue` ; le fichier `token` a changé (64 hex sans newline, ACL intactes) ; le cycle d'après s'authentifie avec le nouveau token sans 401.

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
- [ ] 2.1 — boucle simulée curl : 200+ETag → 304 → report vide 200, counts zéro
- [ ] 2.2 — service installé poste lab : boucle fermée, check-in + received, signature Valid
- [ ] 2.3 — backoff / quarantaine check-ins légers / rotation D5 côté poste
