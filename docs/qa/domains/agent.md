# QA Manuel — Agent desired-state

**Domaine** : canal agent desired-state (Epics 23/24) — enrôlement/token per-poste, état cible compilé, rapports de conformité, stockage D3, purges.

**Stories couvertes** : 24.1 (`POST /api/v1/agent/report` — ingestion et stockage des rapports), 24.2 (agent squelette Windows — boucle check-in/cache/report), 24.3 (compagnon de session — fetch SYSTEM `?user=` + processus user, login jamais bloquant), 24.4 (handlers wallpaper + overlay — route assets, convergence réelle, mode default, drop session ; **la démo live du gate palier 1**), 24.5 (agent **Go** — core de convergence, service SYSTEM natif, build signé Authenticode ; remplace le service PS de 24.2 → Section 5). _L'Epic 23 (contrat, token, enrôlement, GET /state) a été validé e2e par Henri le 2026-06-11 (curl/jq + install iPXE réelle) — ses scénarios seront rapatriés ici au fil des stories 24.x si besoin de re-jeu._

> **Bascule Go (24.5)** : les scénarios 2.2/2.3 (service PowerShell) sont
> **historiques** depuis le retrait des `.ps1` de 24.2 — leur équivalent sur
> le binaire Go est la **Section 5**. Les scénarios 3.x/4.x (compagnon PS,
> handlers) restent joués sur les artefacts PS 24.3/24.4 encore au repo, mais
> le chemin de session PS est **cassé en lab** depuis 24.5 (dot-source de
> `SambaEduAgent.ps1` retiré) — casse temporaire assumée jusqu'au portage Go
> 24.6 (décision story 24.5, pas d'état transitoire).

**Code de référence** :
- `app/Http/Controllers/Api/V1/Agent/ReportController.php` — controller mince POST /report
- `app/Http/Requests/Api/V1/Agent/ReportRequest.php` — validation (422 avant toute écriture)
- `app/Services/Agent/Reporting/ReportIngestService.php` — upsert état + journal + history
- `app/Console/Commands/PruneAgentReportsCommand.php` — purge `agent:reports:prune`
- `docs/agent/contract-v1.md` (FIGÉ) + `docs/agent/report-endpoint.md` — contrat & transport
- `tests/Fixtures/Agent/report.v1.json` — golden file normatif du rapport
- `agent/` (top-level, hors Laravel) — agent Windows squelette : `windows/SambaEduAgent.ps1` (boucle + fonctions partagées compagnon), `windows/SessionStateFetch.ps1` (fetch SYSTEM at-logon), `windows/SessionCompanion.ps1` (processus user), `shared/ContractV1.ps1` (contrat), `build/Build-Agent.ps1` (signature) ; contrats locaux dans `agent/README.md`
- `docs/agent/agent-skeleton.md` — boucle attendue vue serveur (hostname court, codes HTTP, fichiers du poste)
- `docs/agent/session-companion.md` — sous-système compagnon vu serveur (séquence logon, cache per-SID, ETag par contexte, frontière de confiance)
- `app/Http/Controllers/Api/V1/Agent/AssetController.php` — serving binaire des assets wallpaper (24.4, route `agent.v1.assets.wallpaper`)
- `agent/shared/ConvergenceEngine.ps1` + `agent/windows/handlers/{Wallpaper,Overlay}.ps1` — moteur de convergence (mode default §5) + handlers session 24.4
- `docs/agent/handlers-wallpaper-overlay.md` — handlers/assets/drop vus serveur (conventions de hash, limitations MVP)
- `agent/{go.mod,shared/,windows/,build/build.sh}` — agent **Go** 24.5 : cœur OS-agnostique (StateHasher croisé golden files, rotation D5/grâce/quarantaine/backoff, cache atomique), service SYSTEM `x/sys/windows/svc` (sous-commandes `install`/`uninstall`/`run`/`version`), build statique signé osslsigncode — décision techno et contrats locaux dans `agent/README.md`

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

## Section 3 — Compagnon de session (Story 24.3)

> Les invariants serveur du chemin `?user=` sont couverts en CI par
> `tests/Feature/Api/V1/Agent/SessionCompanionE2eTest.php` (`php artisan
> test --filter SessionCompanion`). Cette section est le test e2e **avec le
> vrai sous-système** sur le poste lab (windoobe, ws 49) — AC1/AC3/AC4.
> Pré-requis : install 24.3 (`Install-SambaEduAgent.ps1` ré-exécuté — il
> enregistre les tâches `SambaEduAgent-SessionFetch` et
> `SambaEduAgent-SessionCompanion`), poste joint au domaine, un compte user
> du domaine SE5 (« élève ») disponible.

### Scénario 3.1 — Simulation du chemin `?user=` en curl (sans poste)

Reproduit à la main ce que fait `SessionStateFetch.ps1` (isoler un problème
serveur vs agent) :

1. Contexte machine puis contexte user — deux ETags :
   ```bash
   curl -sS -D /tmp/hm -H "Authorization: Bearer $TOKEN" http://localhost/api/v1/agent/state -o /dev/null
   curl -sS -D /tmp/hu -H "Authorization: Bearer $TOKEN" "http://localhost/api/v1/agent/state?user=<login-court>" -o /tmp/state-user.json
   grep -i '^etag:' /tmp/hm /tmp/hu
   ```
2. Revalidation PAR contexte : renvoyer l'ETag user sur le contexte user → `304` ; le même ETag user sur le contexte machine → `200` (et inversement).
3. Login inconnu : `...?user=compte-local-bidon` → `200` + même ETag que machine-only ; côté serveur `grep agent.state.unknown_user storage/logs/agent*.log | tail`.
4. Casse : `?user=<LOGIN-EN-MAJUSCULES>` → même ETag que le login canonique.

**Attendu** : deux ETags distincts dès qu'une règle user-ciblée existe (ex. wallpaper du user) ; jamais de 304 cross-contexte ; `unknown_user` en info, jamais d'erreur HTTP.

### Scénario 3.2 — Logon nominal : le logon provoque le check-in de session

1. Réinstaller l'agent 24.3 sur le poste lab (bundle signé — cf. 2.2), vérifier `Get-ScheduledTask SambaEduAgent-Session*` → 2 tâches `Ready`.
2. Ouvrir une session **user du domaine** sur le poste.
3. Sur le poste : `dir C:\ProgramData\SambaEdu\Agent\cache\sessions\` (en admin) → un répertoire `<SID>` avec `state.json` + `etag.txt` ; `icacls` du répertoire → SYSTEM F, Administrators F, `<SID>` R, pas d'héritage.
4. Dans la session user : `Get-Content $env:LOCALAPPDATA\SambaEdu\Agent\companion.log` → démarrage, items no-op `type=... scope=session|machine_user mode=...`, passe terminée.
5. Sur le serveur (/vm) : `grep 'agent.state' storage/logs/agent*.log | tail` → `agent.state.compiled` (ou `not_modified`) avec `"user":"<login>"`.

**Attendu** : cache per-SID créé avec la bonne ACL, log compagnon dans le profil user, check-in `?user=` visible serveur. Aucun rapport supplémentaire (le compagnon ne rapporte rien en 24.3). Re-logon immédiat → `agent.state.not_modified` (304, ETag du contexte).

### Scénario 3.3 — Logon hors-ligne : la session vit sur le cache

1. Débrancher le câble réseau du poste (ou couper Apache côté serveur après avoir vérifié qu'un cache de session existe — 3.2).
2. Ouvrir la session du même user.

**Attendu** : session normale, **aucun** message d'erreur visible ; `companion.log` montre « Pas de cache frais dans le délai … traitement sur le DERNIER cache connu » puis la passe sur le cache ; `agent.log` montre l'échec réseau du fetch loggé puis silence (pas de retry agressif — rattrapage au cycle du service). Premier logon d'un user SANS cache hors-ligne → sortie silencieuse loggée, rien de visible.

### Scénario 3.4 — KPI : temps d'ouverture identique avec et sans serveur (décision n° 7)

1. Sur le poste lab, faire **3 ouvertures de session** serveur joignable et **3 ouvertures** câble débranché (même compte, poste redémarré entre chaque pour neutraliser les caches Windows).
2. Pour chaque logon, mesurer « début de logon → démarrage du shell » : Event Viewer `Microsoft-Windows-Winlogon/Operational` (événements 7001/811) ou heure de création du processus `explorer.exe` de la session (`Get-Process explorer | Select StartTime`) moins l'heure de l'événement de logon (Security 4624 type 2/11).
3. Comparer les moyennes ON vs OFF.

**Attendu** : écart moyen **dans le bruit de mesure (< ~1 s)**, aucune corrélation au réseau — rien du sous-système n'est dans le chemin synchrone du logon (tâches planifiées asynchrones uniquement). Tracer les 6 mesures dans les Completion Notes de la story.

### Scénario 3.5 — Frontière de confiance (NFR5)

Depuis la **session user** (non admin) du poste :

1. `Get-Content C:\ProgramData\SambaEdu\Agent\token` → **Access Denied** (ACL 23.3 intacte).
2. `Set-Content C:\ProgramData\SambaEdu\Agent\test.txt 'x'` et `Set-Content 'C:\Program Files\SambaEdu\Agent\test.txt' 'x'` → **Access Denied** (les deux arborescences).
3. `Get-Content C:\ProgramData\SambaEdu\Agent\cache\sessions\<SID-d-un-AUTRE-user>\state.json` → **Access Denied** ; son propre `state.json` → lisible.
4. Tentative d'écriture sur son propre cache : `Set-Content ...\sessions\<SON-SID>\state.json 'x'` → **Access Denied** (lecture SEULE).
5. `Get-AuthenticodeSignature 'C:\Program Files\SambaEdu\Agent\SessionCompanion.ps1'` → `Valid` (et lisible — c'est le script que la session exécute).

**Attendu** : 4 refus + 1 lecture légitime + signature valide. Bonus review : `grep -ri 'kerberos\|ldap' agent/` → rien (NFR7).

---

## Section 4 — Handlers wallpaper + overlay : LA démo palier 1 (Story 24.4)

> Les invariants serveur (route assets, item identity, boucle state→report
> avec items réels) sont couverts en CI par
> `tests/Feature/Api/V1/Agent/{AssetEndpointTest,HandlersE2eTest}.php`
> (`php artisan test --filter Agent`). Cette section est la **démo live
> répétable** du gate palier 1 — UI → état → agent → rapport → base — sur le
> poste lab (windoobe, ws 49). Pré-requis : install 24.4
> (`Install-SambaEduAgent.ps1` ré-exécuté — copie les handlers + le moteur,
> crée `assets\` + `reports\sessions\`, passe la tâche compagnon en
> `ExecutionTimeLimit` illimité), session user du domaine disponible.
>
> **Pré-requis Rainmeter (MANUEL, temporaire — décision Henri 2026-06-12)** :
> Rainmeter n'est pas installé d'office sur les postes. Pour le volet
> overlay de la démo, installer sur ws 49 : `Rainmeter-x.y.z.exe /S
> /AUTOSTARTUP=1 /DESKTOPSHORTCUT=0` (NSIS silencieux, cf.
> `resources/overlay/README.md`), puis déployer la skin
> `resources/overlay/rainmeter/SambaEduOverlay/` (elle pointe sur le
> fichier per-user `%LOCALAPPDATA%\SambaEdu\Agent\overlay.json`). SANS
> Rainmeter, le scénario 4.2 reste valide côté fichier/rapport (comportement
> gracieux — jamais `error` du seul fait de son absence) : seul le rendu
> visuel manque. La livraison automatisée sera intégrée au workflow
> d'install des postes (hors-scope 24.4).

### Scénario 4.1 — Route assets simulée en curl (sans poste)

1. Créer/identifier un wallpaper avec asset dans l'UI (bibliothèque), noter son `filename` (64 hex + extension) :
   ```bash
   php artisan tinker --execute="echo App\Models\WallpaperAsset::first()->filename;"
   ```
2. Depuis la VM :
   ```bash
   curl -sS -o /tmp/asset.jpg -w '%{http_code}\n' -H "Authorization: Bearer $TOKEN" \
     http://localhost/api/v1/agent/assets/wallpaper/<FILENAME>
   sha256sum /tmp/asset.jpg   # = la partie hex du filename
   ```
3. Cas d'erreur : filename inconnu bien formé (64 « a » + `.jpg`) → 404 ; filename malformé (`../../etc/passwd`, `abc.jpg`) → 404 ; sans token → 401.

**Attendu** : 200 binaire dont le SHA-256 = checksum du filename ; logs channel agent `agent.asset.served` (200) / `agent.asset.not_found` (404) ; jamais de contenu hors bibliothèque.

### Scénario 4.2 — Convergence wallpaper UI → poste (AC1)

1. Dans l'UI, assigner un wallpaper (avec asset) au parc/salle du poste ws 49 (ou en défaut d'établissement).
2. Forcer un cycle (redémarrer le service `SambaEduAgent` — le « forcer la synchro » UI arrive en 24.5) ou attendre le cycle.
3. Sur le poste (admin) : `dir C:\ProgramData\SambaEdu\Agent\assets\` → l'asset content-addressed présent ; `agent.log` montre `Asset wallpaper <filename> telecharge et verifie (SHA-256 ok)`.
4. Dans la session user (logon ou attendre la boucle résidente ≤ 5 min) : le fond d'écran change ; `companion.log` montre `Convergence 'wallpaper' (mode=default) : drift` (premier passage) puis `compliant` aux passes suivantes.
5. Sur la VM après le cycle suivant :
   ```bash
   php artisan tinker --execute="
     \$ws = App\Models\Workstation::where('name','<NOM-COURT>')->first();
     App\Models\AgentResourceState::where('workstation_id', \$ws->id)->get(['type','status','hash'])->each(fn(\$s) => print(\$s->type.' '.\$s->status->value.PHP_EOL));"
   ```

**Attendu** : fond appliqué (style fill), `wallpaper compliant` en base, ZÉRO écriture au re-jeu (idempotence : deux passes stables = compliant sans ré-application), `%LOCALAPPDATA%\SambaEdu\Agent\applied-state.json` porte le hash de l'item.

### Scénario 4.3 — Overlay : identité + signal posté visibles (AC2)

1. Vérifier le pré-requis Rainmeter (encadré ci-dessus) + skin déployée.
2. Ouvrir une session user du domaine → `%LOCALAPPDATA%\SambaEdu\Agent\overlay.json` existe et contient `identity.fullname` du user, `machine.name` = nom du poste, `machine.room` = la salle (WG physique).
3. Poster un signal overlay (Tinker) :
   ```bash
   php artisan tinker --execute="
     app(App\Services\Overlay\OverlayService::class)->postSignal(
       'notice', 'warning', 'Maintenance', 'Sauvegardez avant 18h');"
   ```
4. Attendre le rafraîchissement (cycle service ou ≤ 60 s de poll après écriture du cache de session) → l'alerte apparaît dans `overlay.json` ET dans l'overlay Rainmeter à l'écran (identité + salle + carte d'alerte).
5. Supprimer Rainmeter (ou tester sur un poste sans) : `companion.log` montre `rainmeter absent, overlay non rendu` en INFO ; le statut `overlay` en base reste `compliant`/`drift` — JAMAIS `error` de ce seul fait.

**Attendu** : overlay affiché avec identité user + parc, fichier per-user, statut rapporté ; absence de Rainmeter = gracieux.

### Scénario 4.4 — Mode default : la dérive humaine est respectée (AC3)

1. Après un 4.2 convergé (`compliant` en base), dans la session user : changer le fond d'écran à la main (Paramètres Windows → Personnalisation).
2. Attendre le re-test périodique du compagnon (≤ 5 min) — ou re-logon.
3. Observer `companion.log` : `Convergence 'wallpaper' (mode=default) : drifted_allowed` — et le fond N'EST PAS réappliqué (le choix du user est respecté).
4. Au cycle suivant, en base : `wallpaper drifted_allowed` + 1 événement `agent_report_events` (transition compliant → drifted_allowed).
5. Changer la cible côté UI (autre asset) → au cycle/passe suivants : l'agent APPLIQUE la nouvelle cible → `drift` puis `compliant` (dernier-appliqué ≠ nouvelle cible : ce n'est plus une dérive humaine).

**Attendu** : la règle §5 verbatim — dérive humaine tolérée, nouvelle cible serveur appliquée.

### Scénario 4.5 — Erreur isolée + rapport en base (AC4/AC5)

1. Provoquer une erreur sur UN type : supprimer l'asset du cache poste (`del C:\ProgramData\SambaEdu\Agent\assets\<filename>` en admin) PUIS changer le fond à la main en mode strict simulé — ou plus simple : assigner côté UI un wallpaper dont l'asset vient d'être créé (le download n'est pas encore passé) et déclencher une passe compagnon immédiatement (logon).
2. `companion.log` : `Convergence 'wallpaper' en echec : asset wallpaper absent du cache local…` — ET la passe continue (`overlay` est traité normalement).
3. Drop : `type C:\ProgramData\SambaEdu\Agent\reports\sessions\<SID>\session-report.json` (admin) → item `wallpaper` `status=error` avec `detail` non vide + item `overlay` au statut normal.
4. Au cycle suivant : `agent_resource_states` porte `wallpaper error` (detail visible) ; le passage suivant (asset téléchargé) résorbe en `drift`/`compliant`.
5. Frontière de confiance : depuis la session user, forger le drop (`Set-Content ...\<SON-SID>\session-report.json '{"items":[{"type":"printers","status":"compliant","hash":"zzz"}]}'`) → au cycle, `agent.log` montre `entree invalide ignoree (hash non hex-64)` — rien n'entre en base pour `printers` ; un drop d'un AUTRE SID est inaccessible en écriture (Access Denied).

**Attendu** : isolation par item (un échec ne bloque ni les autres handlers ni le rapport), validation stricte des drops, impact d'un forge borné aux statuts session du poste.

### Scénario 4.6 — Boucle fermée : rapport identique = zéro événement (AC5)

1. État stable (tout compliant), noter `select count(*) from agent_report_events where workstation_id = <id>`.
2. Laisser passer 2 cycles sans rien changer.

**Attendu** : `reported_at` des lignes d'état avance à chaque cycle, le compte d'événements N'AVANCE PAS (rapport identique), les hashes en base sont stables (wallpaper = hash d'item de l'état ; overlay = empreinte d'agrégat hex-64 opaque pour le serveur).

---

## Section 5 — Boucle agent Go : core, service SYSTEM, build signé (Story 24.5)

> Les invariants serveur sont INCHANGÉS par la bascule Go (mêmes tests CI
> `AgentSkeletonE2eTest`, `--filter Agent` = 206 passed). Côté agent, le gate
> CI local est `go test ./...` (hôte) : StateHasher croisé golden files (hash
> figé `6c0e8135…`), rotation D5/grâce/quarantaine/backoff sur `httptest`.
> Cette section est le e2e **avec le vrai binaire** sur le poste lab
> (windoobe, ws 49). Pré-requis : poste enrôlé 23.3, binaire produit par
> `agent/build/build.sh` (signé — cf. `agent/README.md` §Signature).

### Scénario 5.1 — Build signé reproductible (hôte)

1. Sur l'hôte : `cd agent && go test ./...` puis
   `CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build ./...` → tout vert.
2. Build de production :
   ```bash
   CODESIGN_PFX=<sambaedu-codesign.pfx> CODESIGN_CA=<ca-root.crt> agent/build/build.sh
   ```
3. Observer la sortie : build statique → signature osslsigncode →
   `osslsigncode verify` intégré.

**Attendu** : `Signature verification: ok` + chaîne remontant à la CA interne
SambaEdu ; artefact `agent/build/dist/sambaedu-agent-<version>.exe` (non
versionné) ; le build REFUSE de produire sans `CODESIGN_PFX` (sauf
`ALLOW_UNSIGNED=1`, jamais déployable).

### Scénario 5.2 — Installation du service Go sur le poste lab

1. Si le service PS du spike est encore installé : le retirer
   (`Uninstall-SambaEduAgent.ps1` du bundle 24.2-24.4 — supprime service +
   tâches planifiées, conserve token/cache).
2. Copier le binaire signé vers `C:\Program Files\SambaEdu\Agent\agent.exe`,
   puis en admin :
   ```powershell
   & 'C:\Program Files\SambaEdu\Agent\agent.exe' install -server-url 'http://<serveur-se5>'
   ```
3. Vérifier sur le poste : `Get-Service SambaEduAgent` → Running (compte
   LocalSystem, démarrage automatique, relance 30 s — `sc.exe qfailure
   SambaEduAgent`) ; `Get-AuthenticodeSignature 'C:\Program
   Files\SambaEdu\Agent\agent.exe'` → **Valid** ; aucun blocage
   SmartScreen/politique d'exécution ; `agent.exe version` → `2.0.0`.
4. Vérifier `C:\ProgramData\SambaEdu\Agent\` : `config.json` posé,
   `cache\state.json` + `cache\etag.txt` + `applied-state.json` (`{}`)
   présents après le 1er cycle, ACL SYSTEM+Administrators sans héritage
   (`icacls`), log `logs\agent.log` au format `[ISO 8601] [LEVEL]`.

**Attendu** : service SYSTEM natif (AUCUN wrapper compilé, aucun
powershell.exe résident), boucle fermée dans le log (`GET /state -> 200` puis
`POST /report -> 200 : rapport accepté, boucle fermée.`).

### Scénario 5.3 — Check-in visible serveur (rapport Go discernable)

1. Sur la VM après le 1er cycle :
   ```bash
   php artisan tinker --execute="
     \$ws = App\Models\Workstation::where('name', '<NOM-COURT>')->first();
     echo \$ws->agent_last_checkin_at;"
   grep agent.report.received storage/logs/agent*.log | tail
   ```

**Attendu** : `agent_last_checkin_at` rafraîchi ; `agent.report.received`
avec counts à zéro (items `[]` jusqu'à 24.6) ; **zéro**
`identity_mismatch` (hostname court) ; le payload rapporté porte
`agent_version: 2.0.0` (lignée Go — un `1.x` = vieux spike PS encore actif
quelque part).

### Scénario 5.4 — Résilience du binaire Go (backoff, quarantaine, rotation)

1. **Backoff** : couper le réseau du poste (ou arrêter Apache) → `agent.log`
   montre `Serveur injoignable … Prochain essai dans 30 s` puis 60, 120…
   plafonné 3600 ; aucun crash (`Get-Service` reste Running), reprise propre
   au retour du serveur.
2. **Quarantaine** : en Tinker
   `app(App\Services\Agent\Enrollment\TokenRotationService::class)->quarantine($ws, 'qa')`
   → au cycle suivant `agent.log` indique le passage en check-ins légers ;
   plus aucun `agent.report.received` côté serveur mais `agent_last_checkin_at`
   continue d'avancer. Lever (`agent_quarantined_at = null`) → `Quarantaine
   levée par le serveur` + reprise du rapport au check-in suivant.
3. **Rotation D5** : en SQL, vieillir `agent_token_rotated_at` de 31 jours →
   au cycle suivant `agent.log` montre `Rotation token reçue` ; le fichier
   `token` a changé (64 hex sans newline, ACL intactes) ; le cycle d'après
   s'authentifie sans 401.
4. **401 irrécupérable** : révoquer le token (UI 23.x ou SQL) → `agent.log`
   trace `401 irrécupérable … ARRÊT du service` ; le service s'arrête
   PROPREMENT (pas de boucle de relance SCM — la relance 30 s ne vaut que
   pour les crashs) ; **jamais de re-enrôlement automatique**.

**Attendu** : les quatre comportements ci-dessus, identiques au contrat
24.2 — la bascule Go est invisible du serveur.

### Scénario 5.5 — Désinstallation conservatrice

1. `agent.exe uninstall` → service supprimé, `C:\ProgramData\SambaEdu\Agent\`
   intact (token/cache/logs).
2. Réinstaller (`agent.exe install …`) → reprise immédiate sans re-enrôlement
   (ETag du cache réutilisé : `agent.state.not_modified` possible côté
   serveur).
3. (Optionnel, destructif) `agent.exe uninstall -purge` → données effacées,
   re-enrôlement iPXE requis.

**Attendu** : par défaut les données d'enrôlement survivent à la
désinstallation (iso-24.2).

---

## Section 6 — Compagnon + handlers Go : parité démo (Story 24.6)

> Le binaire Go est désormais COMPLET : compagnon de session (sous-commandes
> `session-fetch` SYSTEM + `companion` user résident), handlers
> wallpaper/overlay, moteur §5, sync assets, drop per-SID → rapport items
> réels. **Zéro code PHP modifié** (`--filter Agent` = 206 passed inchangé) ;
> les scénarios serveur des Sections 3 et 4 restent valides tels quels — la
> Section 6 rejoue la parité côté POSTE sur le binaire Go signé
> (`agent_version 2.1.0`). Pré-requis : poste lab enrôlé (windoobe ws 49),
> binaire re-buildé signé CA réelle côté serveur (`scripts/build-agent.sh
> [--force]`), Rainmeter installé MANUELLEMENT pour la démo overlay (NSIS
> `/S`, cf. `resources/overlay/README.md` — le handler n'installe jamais
> rien). Gate CI hôte : `go test ./...` (machine d'états §5 table-driven,
> golden overlay byte-compatible, validation des drops, fetch/assets
> httptest) + `go vet` (linux et GOOS=windows) + cross-compile.

### Scénario 6.1 — Install : service + 2 tâches at-logon, reprise du spike

1. Sur le poste (admin) : copier `sambaedu-agent-2.1.0.exe` vers
   `C:\Program Files\SambaEdu\Agent\agent.exe` puis
   `agent.exe install -server-url 'http://<serveur-se5>'`.
2. Vérifier : `Get-Service SambaEduAgent` → Running ; `Get-ScheduledTask
   SambaEduAgent-SessionFetch, SambaEduAgent-SessionCompanion` → présentes,
   déclencheur At log on ; la tâche SessionFetch tourne en SYSTEM (limite
   10 min), la tâche Companion en groupe Users **sans limite d'exécution**
   (résident délibéré) avec `MultipleInstances IgnoreNew`.
3. Si le poste portait encore les tâches PS du spike (ws 49) : vérifier
   qu'elles ont été REMPLACÉES (les actions pointent sur `agent.exe
   session-fetch` / `agent.exe companion`, plus aucun `powershell.exe -File`).
4. `agent.exe version` → `2.1.0` ; `agent.exe uninstall` (en fin de
   campagne) supprime service ET tâches, données conservées.

**Attendu** : install idempotente (rejouable), zéro `.ps1` agent sur le
poste comme au repo, `C:\ProgramData\SambaEdu\Agent\assets\` créé avec ACL
SYSTEM/Admins F + Users R (`icacls`).

### Scénario 6.2 — Logon nominal : fetch SYSTEM + compagnon résident

1. Ouvrir une session d'un user du domaine sur le poste.
2. L'ouverture ne marque AUCUNE attente (tâches asynchrones — NFR1) ;
   vérifier ensuite : `cache\sessions\<SID>\{state.json,etag.txt}` écrits
   (ACL `/inheritance:r`, SYSTEM F, Admins F, `<SID>:R`),
   `reports\sessions\<SID>\` créé (ACL `<SID>:M`),
   `%LOCALAPPDATA%\SambaEdu\Agent\companion.log` démarré.
3. Côté serveur : `agent.state.compiled` (ou `not_modified`) avec
   `user=<login>` dans le channel agent.
4. Le processus `agent.exe companion` reste RÉSIDENT dans la session
   (Gestionnaire des tâches) ; il meurt au logoff.

**Attendu** : séquence iso-24.3 (`docs/agent/session-companion.md` §2) sur
le binaire Go ; aucun répertoire `cache\sessions\` hors SID `S-1-5-21-*`.

### Scénario 6.3 — Logon hors-ligne : jamais bloquant, dernier cache

1. Couper le serveur (ou le réseau du poste), ouvrir une session déjà connue.
2. La session s'ouvre normalement ; `companion.log` trace « pas de cache
   frais … convergence sur le DERNIER cache connu » ; aucun message visible.
3. **KPI logon (action humaine, reprise 24.3 §3.4)** : 3 logons serveur ON
   vs 3 logons serveur OFF (événements Winlogon / création `explorer.exe`) —
   écart dans le bruit (< ~1 s), mesures tracées dans la story.

**Attendu** : le « jamais bloquant » est garanti par construction (mêmes
tâches at-logon asynchrones) — la mesure le confirme sur le binaire Go.

### Scénario 6.4 — Frontière de confiance (NFR5) sur le binaire Go

Dans la session user (non admin) :

1. `type C:\ProgramData\SambaEdu\Agent\token` → **Access Denied**.
2. Écrire sous `C:\ProgramData\SambaEdu\Agent\` ou `C:\Program
   Files\SambaEdu\Agent\` → Access Denied — SAUF
   `reports\sessions\<SON SID>\` (écriture OK : c'est SON drop).
3. Lire `cache\sessions\<SID d'un AUTRE user>\state.json` → Access Denied ;
   le sien → lisible.
4. `companion.log` ne contient AUCUN appel réseau (le compagnon n'a ni
   client HTTP ni token — grep `GET /state` n'apparaît que dans
   `logs\agent.log` côté SYSTEM).

**Attendu** : iso-scénario 3.5, inchangé par le portage.

### Scénario 6.5 — Convergence wallpaper UI → poste → rapport (LA démo)

1. UI : associer une règle wallpaper (biblio d'assets) au parc du poste.
2. Au cycle suivant (ou redémarrage du service pour accélérer) :
   `agent.asset.served` côté serveur, `assets\<sha256>.<ext>` sur le poste
   (SHA-256 = nom = checksum), fond d'écran appliqué dans la session
   (HKCU `WallPaper` + rafraîchissement immédiat).
3. Boucle fermée curl/jq iso-Epic 23 :
   ```bash
   php artisan tinker --execute="
     \$ws = App\Models\Workstation::where('name','<NOM-COURT>')->first();
     \$ws->agentResourceStates->each(fn(\$s) => print(\$s->resource_type.' '.\$s->status.' '.\$s->reported_at.PHP_EOL));"
   ```
   → ligne `wallpaper compliant` (après la passe suivant l'apply).
4. Re-jouer le cycle sur état stable → statut inchangé, **zéro nouvel
   événement** `agent_report_events` (idempotence + conventions de hash
   stables — `agent_version: 2.1.0` au rapport).

**Attendu** : chaîne complète UI → état → agent Go → rapport, latence
≤ 1 cycle (NFR3) — le bouclage visuel UI = 24.7.

### Scénario 6.6 — Mode default : dérive humaine respectée (Go)

1. Avec la règle wallpaper en mode `default` convergée (6.5) : changer le
   fond À LA MAIN dans la session.
2. Attendre le re-test périodique du compagnon (~5 min) puis le cycle de
   rapport.

**Attendu** : le fond N'EST PAS réappliqué ; `agent_resource_states` passe
`wallpaper → drifted_allowed` ; changer ensuite la CIBLE dans l'UI →
réapplication + `drift` (la cible a bougé). Jamais de `drifted_allowed` au
premier passage d'un poste vierge.

### Scénario 6.7 — Overlay : identité + signal, Rainmeter gracieux

1. Rainmeter + skin installés manuellement (prérequis démo) ; poster un
   signal overlay depuis l'UI.
2. Vérifier `%LOCALAPPDATA%\SambaEdu\Agent\overlay.json` : fullname/login +
   `machine.name` local + room + `alerts[]` — format à structure fixe
   (`": "` simple, UTF-8 brut) ; l'overlay AFFICHE identité + alerte.
3. Supprimer/renommer `Rainmeter.exe` (simuler l'absence) → la passe
   suivante écrit toujours `overlay.json`, statut overlay reste
   compliant/drift NORMAL (jamais `error` du seul fait de l'absence),
   `companion.log` trace « rainmeter absent, overlay non rendu ».
4. Modifier `overlay.json` à la main → mode `strict` : réécrit à la passe
   suivante + `drift` au rapport.

**Attendu** : l'agent Go EST le fetch du POC ; `resources/overlay/`
inchangé (la skin pointe déjà sur le fichier per-user).

### Scénario 6.8 — Erreur isolée + drop forgé (frontière du rapport)

1. Provoquer un échec d'UN handler (ex. supprimer l'asset du cache local
   APRÈS l'avoir référencé, avant une passe) → l'item `wallpaper` remonte
   `error` + detail explicite, l'overlay continue d'être traité (isolation).
2. Dans la session user, forger `reports\sessions\<SON SID>\
   session-report.json` (type hors liste, status inventé, hash non hex-64,
   `error` sans detail, fichier > 256 KiB) → au cycle, `logs\agent.log`
   trace le rejet entrée par entrée ; le rapport part SANS les entrées
   forgées (jamais de 422 serveur causé par un drop user).

**Attendu** : iso-scénario 4.5 — impact d'un user forgeur borné à SON poste.

---

## Section 7 — Conformité UI + forcer la synchro (Story 24.7)

> **Gate palier 1 de l'Epic 24** : cette section ferme la boucle « → UI ».
> Tout ce qui précède (état cible compilé, token, ingestion 24.1, binaire Go
> 2.1.x sur ws 49) tourne ; ici le serveur **MONTRE** ce qu'il sait dans les
> pages parc, et l'admin peut **forcer une resynchro**. La conformité est
> intégrée aux pages parc EXISTANTES (pas de page « postes » à part) :
> compteurs + badge + filtre sur `app/parc` (onglet machines), table par type
> + événements + bouton sur `app/parc/machines/{id}`, panneau règles →
> exceptions sur `app/parc/groups/{id}`.
>
> **Aucune modification de l'agent Go** (binaire 2.1.x figé) : le « forcer la
> synchro » est du **PULL pur** — l'UI pose une demande
> (`workstations.agent_sync_requested_at`), `GET /state` bypasse le 304 tant
> qu'elle est pendante (corps complet re-servi, MÊME ETag), le premier
> `POST /report` la solde. Latence assumée : servie au prochain contact
> (timer ≤ 60 min + jitter, ou boot/login immédiats).

### Pré-requis Section 7

- Tous les pré-requis communs + l'agent Go installé et fonctionnel sur ws 49
  (Section 6 verte).
- Migration appliquée : `php artisan migrate:status | grep agent_sync` →
  `add_agent_sync_requested_at_to_workstations` = Ran. Sinon
  `php artisan migrate` (+ `chown www-admin:www-admin` si `bootstrap/cache/`
  régénéré).
- Au moins un poste enrôlé visible dans `app/parc` (ws 49) ; idéalement un
  parc logique le contenant pour la vue groupe.

### Scénario 7.1 — Smoke serveur : bypass 304 par une demande pendante (curl/Tinker)

1. Repérer le token d'un poste de test enrôlé (ou en émettre un en Tinker :
   `app(\App\Services\Agent\Enrollment\TokenRotationService::class)->issueFor($ws)`).
2. `GET /api/v1/agent/state` avec `Authorization: Bearer <token>` → noter
   l'`ETag`. Le rejouer avec `If-None-Match: <etag>` → **304** (nominal).
3. Poser une demande en Tinker — la colonne est volontairement hors
   `$fillable`, donc PAS de `update([...])` (jeté silencieusement) :
   `app(\App\Services\Agent\SyncRequestService::class)->request(\App\Models\Workstation::find($id));`
   (logge `agent.sync.requested` au passage ; ou via l'UI, scénario 7.4).
4. Rejouer le `GET` avec le MÊME `If-None-Match: <etag>` → **200 corps
   complet**, MÊME `ETag` (enveloppe brute inchangée). `logs/agent/agent.log`
   trace `agent.sync.state_forced`.
5. `POST /api/v1/agent/report` (payload minimal valide) → 200 ; en base
   `agent_sync_requested_at` est **null** (soldée) ;
   `agent.sync.fulfilled` loggé.

**Attendu** : le bypass ne change QUE le respect de `If-None-Match` (jamais le
hash, jamais l'enveloppe) ; le report solde la demande ; un poste en
quarantaine renvoie 403 AVANT toute logique (demande JAMAIS soldée).

### Scénario 7.2 — Compteurs + badge + filtre sur la page parc (AC1)

1. Ouvrir `app/parc`, onglet **Postes**. Sous les stats-cards classiques, une
   2e rangée « conformité » apparaît dès qu'au moins un poste est enrôlé :
   **En écart** (drift+error), **Dérive tolérée**, **Muets / jamais
   rapporté**, **Conformes**, **Postes enrôlés** — calculés sur les postes
   ENRÔLÉS, en requêtes agrégées.
2. Chaque ligne poste enrôlé porte un **badge conformité** (worst-status :
   error > drift > drifted_allowed > compliant ; muet et jamais-rapporté
   distincts) ; un poste non enrôlé affiche `—` neutre.
3. Sélectionner le filtre **Conformité : En écart** → seuls les postes en
   drift/error restent ; idem **Dérive tolérée**, **Muets**, **Conformes**.
   Le bouton « gomme » réinitialise TOUS les filtres (dont la conformité).

**Attendu** : zéro page « postes » dédiée ; tout vit dans la page parc ;
aucune dégradation de perf perceptible (le badge = 1 requête agrégée par
page, pas une requête par ligne).

### Scénario 7.3 — Détail poste : état par type + événements + états dérivés (AC2)

1. Ouvrir `app/parc/machines/{id}` d'un poste enrôlé ayant rapporté. Dans la
   card **Agent** (étendue, pas dupliquée), une table « État rapporté par
   type » : type, badge statut (4 statuts + `drifted_allowed` visuellement
   distinct), date `reported_at` (relative), `detail` tronqué, hash opaque
   tronqué (jamais interprété).
2. Sous-section « Derniers événements » : jusqu'à 10 `agent_report_events`
   datés, transition `previous_status → status`, detail.
3. États dérivés : un poste enrôlé SANS aucune ligne d'état affiche « Jamais
   rapporté » ; un poste enrôlé dont le dernier check-in dépasse
   2 × `config('agent.ttl_seconds')` affiche un bandeau « Poste muet ». Un
   poste non enrôlé garde l'affichage neutre existant (card Agent « Jamais
   enrôlé »).

**Attendu** : les écarts sont DATÉS (l'événement de dérive donne le début, la
ligne d'état donne la fraîcheur).

### Scénario 7.4 — Forcer la synchro depuis le détail poste (AC5, LA démo)

1. Sur `app/parc/machines/{id}` d'un poste enrôlé non quarantaine, cliquer
   **Forcer la synchro** → confirmation `wire:confirm` → toast succès,
   bandeau « Synchro demandée le … — en attente du prochain check-in », le
   bouton devient « Synchro demandée » (désactivé). `agent.sync.requested`
   loggé avec l'admin.
2. Le bouton est **désactivé avec tooltip** pour un poste non enrôlé ou en
   quarantaine (piège 6).
3. Au prochain cycle de l'agent (ws 49 ; pour accélérer la démo : relancer le
   service ou attendre le timer), le poste re-télécharge l'état complet
   (200 forcé) et POST son rapport → le bandeau « demandée » DISPARAÎT
   (soldée), la table d'état se rafraîchit (`wire:poll.15s` borné au bloc).

**Attendu** : reconvergence complète + trace + feedback UI ; mécanisme
**pull** (pas de push WoL/WinRM).

### Scénario 7.5 — Démo live répétable : wallpaper UI → poste → écart → résorption (gate palier 1)

> **LE scénario de la démo live** (action humaine Henri, gate de l'epic).

1. Dans l'UI, changer le **wallpaper d'un parc** contenant ws 49 (page
   wallpaper du groupe).
2. Sur `app/parc/groups/{id}` (onglet **Général**), le panneau **Conformité
   agent** montre, par type (`wallpaper`), « n/N conformes » + la liste des
   SEULS postes en exception, datés et cliquables. Tant que ws 49 n'a pas
   convergé, il apparaît en `drift` (écart visible à l'écran).
3. (Optionnel pour accélérer) cliquer **Forcer la synchro du groupe** → tous
   les membres enrôlés non quarantaine reçoivent une demande (toast
   récapitulatif demandés / ignorés).
4. ws 49 converge (handler wallpaper réapplique), POST son rapport → le
   panneau (wire:poll.15s) repasse le poste en `compliant` **sans action
   admin** (AC4) : l'exception disparaît de la liste.

**Attendu** : l'écart se VOIT puis se RÉSORBE à l'écran ; aucun poste conforme
n'est jamais listé (le poste n'apparaît qu'en exception).

### Scénario 7.6 — Retour auto à compliant (AC4), sans forcer

1. Sur un poste en `drift` (par n'importe quel moyen), attendre le passage
   naturel suivant de l'agent (convergence) SANS cliquer « forcer ».
2. La fiche poste et le panneau groupe (tous deux `wire:poll.15s` bornés)
   repassent en `compliant` d'eux-mêmes — la donnée est relue de
   `agent_resource_states` (l'upsert 24.1 fait foi, `reported_at` rafraîchi).

**Attendu** : aucune intervention requise ; le bouton « forcer » n'est qu'un
raccourci de latence, pas un prérequis du retour à compliant.

---

## Section 8 — Distribution des releases (Story 25.1)

Moitié serveur de la distribution canari (D6/FR24) : `agent_releases` +
`agent_release_rings` (un ring = un WorkstationGroup), commandes artisan
`agent:release:{create,target,promote}`, `GET /api/v1/agent/release`
(manifest résolu par ring) + `GET /api/v1/agent/releases/{filename}`
(binaire). Référence : `docs/agent/release-distribution.md`. Pré-requis :
`storage/agent/releases/` existant et chown www-admin ; un poste enrôlé
(token 23.3 — ws 49 dispo) ; binaire signé dans `agent/build/dist/`
(24.5/24.6 — un binaire factice convient pour les scénarios serveur).

### Scénario 8.1 — Créer une release : hash OK / hash KO (AC1)

1. Sur la VM, déposer le binaire :
   `install -o www-admin -g www-admin agent/build/dist/sambaedu-agent-<v>.exe storage/agent/releases/`.
2. **KO d'abord** : `php artisan agent:release:create <v> sambaedu-agent-<v>.exe --hash=$(printf '0%.0s' {1..64})`
   → sortie « Release refusée (hash_mismatch) », **exit ≠ 0**, table
   `agent_releases` VIDE (`psql` ou tinker), log `agent.release.rejected`
   (warning, raison) dans le channel agent.
3. **OK ensuite** : re-créer avec
   `--hash=$(sha256sum storage/agent/releases/sambaedu-agent-<v>.exe | cut -d' ' -f1) --stable`
   → exit 0, UNE ligne `agent_releases` (hash = sha256sum, `is_stable`
   true), log `agent.release.created`.
4. Variantes refus (toutes exit ≠ 0, zéro ligne ajoutée) : fichier absent
   (`file_missing`), version re-soumise (`duplicate_version`), filename
   `agent.exe` (`invalid_filename`).

**Attendu** : un artefact incohérent est IMPUBLIABLE — aucun refus ne laisse
de ligne en base.

### Scénario 8.2 — Manifest depuis un poste enrôlé ; sans ring → stable (AC2, AC3)

1. Récupérer le token du poste lab (ws 49) :
   `C:\ProgramData\SambaEdu\Agent\token` (poste) — jamais loggé côté serveur.
2. Sans token : `curl -i http://<serveur>/api/v1/agent/release` → **401**
   JSON `AGENT_TOKEN_MISSING` (route vivante derrière le cache).
3. Avec token, poste SANS ring :
   `curl -s -H "Authorization: Bearer <token>" http://<serveur>/api/v1/agent/release`
   → 200 `{success: true, version, hash, url}` = la **stable** (jamais une
   canari par accident), `url` **absolue** (vérifier le host = `APP_URL`).
4. Supprimer toute stable (tinker `is_stable = false`) et re-curler → **404**
   `{error: "no_release"}` — pas de 500, pas de 200 vide. Restaurer ensuite.

**Attendu** : la résolution suit ring → stable → 404 ; le contrat wire est
conforme au golden `tests/Fixtures/Agent/release-manifest.v1.json`.

### Scénario 8.3 — Canari : ring d'1 poste de lab (AC2)

1. Publier une seconde version (8.1) SANS `--stable` (la canari).
2. Créer/choisir un WorkstationGroup contenant UNIQUEMENT le poste lab
   (salle ou parc — indifférent), puis :
   `php artisan agent:release:target <v-canari> <nom-du-groupe>`
   → log `agent.release.targeted`.
3. curl manifest avec le token du poste lab → `version` = **canari**.
4. curl manifest avec le token d'un AUTRE poste enrôlé (hors groupe) →
   `version` = **stable** : la canari n'a pas fui.
5. Si le poste appartient AUSSI à un parc ciblé : vérifier le warning
   `agent.release.ring_conflict` (workstation_id + group_ids) et que le
   ciblage le plus RÉCENT gagne.

**Attendu** : une release atteint 1 poste de lab avant le parc — le ring
borne exactement la diffusion.

### Scénario 8.4 — Rollback : re-ciblage stable (récence) (AC2)

1. Poste lab sous canari (8.3). Re-cibler le MÊME groupe sur la version
   stable : `php artisan agent:release:target <v-stable> <nom-du-groupe>`.
2. curl manifest → `version` = stable : le re-ciblage, posé APRÈS, gagne
   par récence (`updated_at` du ring rafraîchi même à version identique).
3. Variante pointeur : `php artisan agent:release:promote <v-stable>` →
   les postes SANS ring rebasculent ; `agent_releases` n'a qu'UNE ligne
   `is_stable` true (log `agent.release.promoted`).

**Attendu** : le rollback est un re-ciblage/une promotion — aucune
suppression nécessaire, effet au prochain check-in.

### Scénario 8.5 — Download binaire authentifié + sha256sum (AC4, AC6)

1. Depuis le manifest 8.2, extraire `url`, puis :
   `curl -s -H "Authorization: Bearer <token>" -o /tmp/agent.exe "<url>"`
   → 200 ; `sha256sum /tmp/agent.exe` = le `hash` du manifest, à l'octet.
2. Sans token sur la même `url` → **401** ; token forgé → 401
   `AGENT_TOKEN_INVALID` ; poste en quarantaine → **403**.
3. 404 **indistinct** `{error: "not_found"}` pour : filename forgé
   `sambaedu-agent-9.9.9.exe` (inconnu DB), traversal
   `..%5C..%5Cpasswd`, et un binaire orphelin déposé dans
   `storage/agent/releases/` SANS ligne `agent_releases` (jamais servi).
   Les trois réponses sont identiques (aucun oracle de présence) ; logs
   `download_served`/`download_not_found` côté serveur.
4. Rotation : reculer `agent_token_rotated_at` du poste (tinker) au-delà de
   l'échéance, re-curler le manifest → 200 avec header `X-Agent-New-Token`
   (invariant D5 — le recouvrement survit au canal release).

**Attendu** : seul un binaire PUBLIÉ est servi, à un poste AUTHENTIFIÉ, et
le corps reçu est exactement l'artefact vérifié à la création.

---

## Section 9 — Auto-update de l'agent (Story 25.2)

L'agent consomme le manifest 25.1 et se met à jour **seul**, sans tournée des
salles, **sans jamais briquer le parc** (NFR8). Double vérification (SHA-256
avant écriture, Authenticode avant swap), swap atomique anti-brique, report
d'échec via item `agent_update`, version rapportée = preuve de succès.

### Pré-requis Section 9

- La **matrice de tests automatique** (≥ 20 cas, `agent/shared/update_test.go`)
  tourne sur l'hôte : `cd agent && PATH="$HOME/go-toolchain/go/bin:$PATH" go test ./...`
  → vert. Elle couvre tout le flux décision/download/hash/orchestration avec
  les primitives Windows STUBÉES (nominal, 404, version égale, hash KO,
  signature KO, download KO, tronqué, 401, 403 release = update sauté SANS
  quarantaine (M4), report d'échec, version rapportée, single-download, survie
  token). Le **cœur anti-brique** (`shared/swap.go` `PerformSwap`) est désormais
  RÉELLEMENT testé sur Linux (`shared/swap_test.go`) : swap nominal +
  triggerRestart appelé, staged absent (aucune mutation), hash `.new` divergent
  (M2), échec du rename final → **ROLLBACK vérifié** (ancien binaire restauré,
  triggerRestart JAMAIS appelé). **Restent au smoke uniquement les spécificités
  OS** : Authenticode réel (WinVerifyTrust), `os.Exit` + recovery SCM, ACL du
  staging — `go test` ne les couvre pas (build tag Windows).
- VM serveur 25.1 disponible (release `2.1.2` cert TEST publiée stable) ; un
  **poste de lab Windows** enrôlé (ws 49), avec la **CA interne SE5 ajoutée au
  magasin de confiance machine** (sinon la vérif Authenticode rejette le binaire
  pourtant signé — c'est le comportement attendu, pas un bug).
- Un binaire `vN+1` **signé** (cert TEST chaînant vers cette CA) produit par
  `agent/build/build.sh` (`VERSION=2.2.1` p.ex.) et déposé dans
  `storage/agent/releases/` (chown www-admin).

### Scénario 9.1 — Convergence par ring : l'agent passe à vN+1 (AC1, AC2, AC4)

1. Publier `vN+1` signé : `php artisan agent:release:create 2.2.1 sambaedu-agent-2.2.1.exe --hash=<sha256>`
   puis cibler le ring d'1 poste de lab : `php artisan agent:release:target 2.2.1 <WorkstationGroup-du-lab>`.
   Les autres postes restent sur la stable (`2.1.2`).
2. Sur le poste de lab, attendre un cycle (ou `agent.exe run` en console admin
   pour observer). Suivre `C:\ProgramData\SambaEdu\Agent\logs\agent.log` :
   `Auto-update : version cible 2.2.1 annoncée…` → `signature Authenticode …
   valide` → `swap 2.1.2→2.2.1 réussi …, sortie volontaire (code 42) pour
   relance par la recovery SCM`.
3. **Restart attendu = relance par la recovery SCM après une sortie
   NON-GRACIEUSE** (Option A, review 25.2) : l'agent ne fait PLUS de stop+start
   in-process ; il sort par `os.Exit(42)` et la **recovery SCM** (ServiceRestart
   ×3, configurée à l'install) relance le service avec le binaire vN+1.
   - Dans le **journal d'événements Windows** (Service Control Manager), on
     observe une entrée de **terminaison anormale** du service `SambaEduAgent`
     (code de sortie 42) immédiatement suivie d'un redémarrage automatique :
     c'est un **plantage volontaire ATTENDU**, pas un incident (le log local
     `sortie volontaire … pour relance SCM` le confirme).
   - Vérifier le swap : `C:\Program Files\SambaEdu\Agent\agent.exe` est le neuf
     (`agent.exe version` → `2.2.1`), `agent.exe.old` nettoyé au boot suivant
     (best-effort). `Get-Service SambaEduAgent` → Running (relancé < ~30 s).
4. Côté serveur (page parc / tinker) : la **version rapportée** du poste passe à
   `2.2.1` au check-in suivant — **c'est la trace de déploiement qui fait foi**
   (`download_served` reste du debug). Les autres postes : toujours `2.1.2`.

**Attendu** : la boucle de déploiement par ring est observable de bout en bout
(canari 1 poste → version rapportée vN+1) ; les postes hors ring inchangés.

### Scénario 9.2 — Binaire corrompu : rejeté, jamais installé (AC1, AC3)

1. Publier une release dont le **hash déclaré ≠ contenu** est impossible (la
   création 25.1 le refuse). Pour simuler côté agent : déposer dans
   `storage/agent/releases/` un binaire **corrompu** sous le filename d'une
   release existante APRÈS création (le serveur sert alors un corps dont le
   SHA-256 ≠ `hash` du manifest). Cibler le ring du lab.
2. Sur le poste : le log montre `SHA-256 du binaire téléchargé (…) != hash
   manifest (…) — binaire JETÉ (rien écrit)`. **Aucun fichier** sous
   `C:\ProgramData\SambaEdu\Agent\update\`. **Aucun swap** :
   `agent.exe version` inchangé.
3. Le check-in suivant rapporte un item `agent_update` `status: error` (visible
   page parc / `agent_resource_states` côté serveur). Le poste **continue de
   rapporter son état réel** (le cycle n'est pas cassé).

**Attendu** : un binaire corrompu n'atteint jamais le swap (porte 1) ; l'agent
reste fonctionnel ; l'échec remonte sans casser le cycle.

### Scénario 9.3 — Binaire non signé / signature non de confiance : rejeté (AC1, AC3)

1. Produire un binaire `vN+1` **non signé** (`ALLOW_UNSIGNED=1 agent/build/build.sh`)
   ou signé par une CA **hors magasin de confiance machine** du poste. Le
   déposer (avec son SHA-256 réel, donc la **porte 1 hash passe**) et cibler le
   ring du lab.
2. Sur le poste : le log montre `signature Authenticode invalide sur le binaire
   stagé : … — binaire JETÉ, AUCUN swap`. Le binaire est bien **stagé** sous
   `update\` (hash OK) mais **jamais swappé** (porte 2). `agent.exe version`
   inchangé.
3. Item `agent_update` `status: error` au check-in suivant.

**Attendu** : la vérif Authenticode (WinVerifyTrust) est la dernière porte avant
exécution ; un binaire non signé / non de confiance ne tourne JAMAIS sur le parc.

### Scénario 9.4 — Anti-brique : un swap interrompu laisse l'agent en place (AC3, NFR8)

1. Reproduire un échec de dépose : pendant un cycle d'update (binaire valide
   stagé), verrouiller temporairement `C:\Program Files\SambaEdu\Agent\` en
   écriture (ou couper l'alimentation entre les renames — test destructif
   encadré). 
2. Vérifier qu'à AUCUN instant `agent.exe` n'est absent : soit `agent.exe`
   (vN intact, rollback fait), soit `agent.exe` (vN+1) + `agent.exe.old`. Jamais
   de chemin sans binaire valide. Le swap re-hashe le `.new` à sa position
   finale (M2) avant le rename : un `.new` corrompu par la copie est rejeté
   AVANT toute étape destructive (ancien binaire intact).
3. Si le swap a échoué : aucune sortie `os.Exit` (triggerRestart n'est appelé
   qu'après un swap réussi), le service continue de tourner sur vN, l'échec
   figure en `agent_update` error au check-in suivant. Si le swap a réussi mais
   que la recovery SCM était saturée (3 échecs en 24 h) : vN+1 en place mais
   service arrêté → le prochain boot ou le bootstrap GPO figé (25.4) le relance.

**Attendu** : jamais d'état « ni ancien ni nouveau » ; l'agent en place reste
fonctionnel ; retry au prochain check-in (cadence `ttl_seconds`). Le rollback et
le « triggerRestart jamais appelé sur échec » sont désormais **réellement testés
sur Linux** (`shared/swap_test.go`, `PerformSwap`) — le smoke ne valide plus que
les spécificités OS (ACL, `os.Exit` réel, recovery SCM, Authenticode).

### Scénario 9.5 — Token/cache survivent à l'update (AC2, piège n° 5)

1. Après une convergence réussie (9.1), vérifier sur le poste que
   `C:\ProgramData\SambaEdu\Agent\token` est **intact** (même valeur), que le
   cache d'état (`cache\state.json`) et `applied-state.json` sont relus par
   l'image vN+1 **sans ré-enrôlement**. Le check-in vN+1 ne déclenche aucun
   `agent.report.identity_mismatch` ni 401.
2. Vérifier que la rotation D5 fonctionne toujours après le swap (un
   `X-Agent-New-Token` est honoré par l'image vN+1).

**Attendu** : le swap ne touche QUE `agent.exe` (Program Files) ; les données
sous ProgramData survivent par construction.

### Scénario 9.6 — 403 release : update sauté, le poste reste visible (M4, Option 1)

1. Geler le ring du lab côté serveur de façon à ce que le **canal release**
   (`GET /api/v1/agent/release` ou le download) réponde **403** au poste, alors
   que le **canal principal** `/state` répond toujours `200`/`304`.
2. Sur le poste : le log montre `GET /release -> 403 (canal release refusé) :
   update sauté ce cycle — PAS de quarantaine globale`. L'update est **sauté**.
3. Vérifier que le poste **N'EST PAS** en quarantaine globale : le `POST /report`
   du cycle **a bien lieu** (le poste reste visible sur sa conformité côté page
   parc / `agent_resource_states`), avec un item `agent_update` `status: error`
   (raison = 403 release). La version rapportée reste vN (inchangée).
4. Contre-épreuve : un `403` sur le canal **principal** `/state` met, lui, le
   poste en **quarantaine globale** (check-ins légers, plus de `POST /report`) —
   comportement INCHANGÉ (la quarantaine globale reste réservée à `/state`).

**Attendu** : un ring gelé (403 release) ne rend JAMAIS le poste muet sur sa
conformité ; seule l'update est sautée. La quarantaine globale reste l'apanage du
403 du canal principal.

---

## Section 10 — Porte 2 : enrôlement des postes migrés, approbation un-clic (Story 25.3)

**Objet** : un poste migré (existant, sans ticket — agent posé par la GPO 25.4)
qui rejoue `POST /api/v1/agent/enrollment` ne reçoit plus un 403 sec : une
**demande d'enrôlement** est créée (`agent_enrollment_requests`), visible et
approuvable dans l'UI (`/app/parc-settings/agent`). Le poste reste 403
(indistinct) tant qu'il n'est pas approuvé ; le token naît à son prochain
check-in (jamais via l'UI). Mode **campagne** = auto-approbation bornée des
postes connus concordants, **anti-usurpation jamais débrayé**.

### Pré-requis Section 10

- VM à jour : `php artisan migrate` (table `agent_enrollment_requests`).
- Un poste **connu** en base avec MAC + hostname renseignés (importé AD/legacy).
- `curl` depuis le LAN (le endpoint est derrière `local.request`).
- Réinitialiser entre scénarios : `DELETE FROM agent_enrollment_requests;` et
  vider le réglage campagne (UI « Désactiver » ou
  `SystemSetting::forget('agent_enroll_campaign_until')`).

### Scénario 10.1 — Poste migré sans ticket → demande pending visible (AC1)

1. `curl -s -o /dev/null -w '%{http_code}' -X POST http://<se4fs>/api/v1/agent/enrollment -H 'Content-Type: application/json' -d '{"mac":"<MAC poste connu>","hostname":"<hostname poste connu>","uuid":"x"}'`.
2. Attendu : **403** (corps `AGENT_ENROLL_NOT_ALLOWED`, indistinct).
3. UI `/app/parc-settings/agent` : la demande apparaît dans la liste pending,
   faisceau affiché (hostname/MAC/uuid), badge **rapproché** + nom du poste.
4. Log channel `agent` : `agent.enroll.requested` (jamais de token/hash).

**Attendu** : la branche d'échec crée bien une demande, sans changer la réponse.

### Scénario 10.2 — Idempotence : re-check-in ne duplique pas (AC1)

1. Rejouer le `curl` du 10.1 deux ou trois fois.
2. UI : **une seule** ligne (pas de doublon), `Vu` rafraîchi à chaque appel.
3. Base : `SELECT count(*) FROM agent_enrollment_requests` = 1 pour ce faisceau.

**Attendu** : `updateOrCreate` sur la MAC normalisée → 1 demande, `last_seen_at`
qui avance.

### Scénario 10.3 — Approbation un-clic → token au prochain check-in (AC2)

1. Depuis l'UI, cliquer **« Approuver »** sur la demande (confirmer).
2. Toast de succès ; la demande disparaît de la liste pending (status approved).
   Log `agent.enroll.approved` avec `resolved_by`.
3. Rejouer le `curl` (le poste re-check-in) : attendu **200** `{success, token}`.
4. Base : la demande est **consommée** (supprimée) ; le poste a un
   `agent_token_hash`. Le token n'a JAMAIS transité par l'UI.

**Attendu** : approuver arme la demande ; le token naît au redeem suivant.

### Scénario 10.4 — Campagne ON, poste connu concordant → auto-approbation (AC3)

1. UI : activer le **mode campagne** (ex. 7 jours).
2. `curl` POST avec le faisceau d'un **autre** poste connu (MAC + hostname
   cohérents, non enrôlé) : attendu **403**.
3. Base/UI : la demande est `approved` avec `auto_approved = true` (badge auto) ;
   log `agent.enroll.auto_approved`.
4. Re-`curl` : **200** + token, demande consommée.

**Attendu** : auto-approbation sans clic, mais le token naît toujours au redeem.

### Scénario 10.5 — Campagne ON, poste divergent/inconnu → manuel (AC3, invariant)

1. Campagne toujours active. `curl` POST avec :
   - (a) MAC d'un poste connu **mais hostname différent** ;
   - (b) une MAC **inconnue** (aucun poste) ;
   - (c) une MAC partagée par **2 postes** (multi-candidats).
2. Attendu pour les trois : **403** + demande **pending** (`auto_approved =
   false`), **jamais** auto-approuvée — même campagne active.

**Attendu** : l'anti-usurpation ne se débraye jamais. Invariant non négociable.

### Scénario 10.6 — Conflit : poste connu déjà enrôlé (AC4, piège n° 4)

1. Sur un poste déjà enrôlé (`agent_token_hash` non nul), `curl` POST sans
   ticket en présentant **sa MAC**.
2. Attendu : **409** `AGENT_ENROLL_CONFLICT`, token courant **intact**,
   **aucune** demande pending créée.

**Attendu** : un ré-enrôlement/clone d'un poste enrôlé est un conflit, jamais une
demande d'enrôlement. Le conflit se fonde sur la **seule MAC** (ancre, review
#M2/#M3) — voir 10.11/10.12.

### Scénario 10.11 — Conflit sous MAC partagée (review #M2)

1. Deux fiches partagent une MAC ; l'**une** est enrôlée, l'autre non.
2. `curl` POST sans ticket avec cette MAC.
3. Attendu : **409** quel que soit l'ordre des fiches en base (détection par
   `exists()` d'un enrôlé partageant la MAC, pas par « la première trouvée »).

### Scénario 10.12 — Pas d'oracle via l'UUID (review #M3, AC6)

1. `curl` POST sans ticket en présentant l'**UUID** d'un poste enrôlé mais une
   **MAC étrangère** (ou aucune MAC).
2. Attendu : **403** indistinct (jamais 409) + demande pending non
   auto-approuvable — l'UUID seul ne révèle pas la présence d'un poste enrôlé.

### Scénario 10.7 — Rejet → poste hors système, pas de ré-ouverture (AC4)

1. Sur une demande pending, cliquer **« Rejeter »** : la modale réutilisable
   affiche les preuves ; confirmer.
2. Toast ; status `rejected` ; log `agent.enroll.rejected` `reason=manual_reject`.
3. Rejouer le `curl` : **403**, **aucun** token, la demande **N'EST PAS**
   ré-ouverte (reste `rejected`, pas de nouvelle ligne pending).

**Attendu** : un poste rejeté reste dehors ; l'admin garde la main pour re-armer.

### Scénario 10.8 — Non-régression porte 1 (AC6)

1. Générer un unattend pour un poste (porte 1) puis échanger son ticket valide.
2. Attendu : **200** + token direct (flux §2 inchangé), **aucune** ligne
   `agent_enrollment_requests` créée.

**Attendu** : la greffe porte 2 ne touche pas le flux ticket.

### Scénario 10.9 — Demande « inconnu » : approbation non actionnable (review #3)

1. Provoquer une demande pending dont le faisceau ne rapproche aucun poste connu
   (MAC absente de `workstations`) → badge **« inconnu »** dans l'UI.
2. Attendu : le bouton **« Approuver »** est **désactivé** (tooltip
   « rapprochement requis »). Si l'action est forcée (appel direct), le service
   la refuse (toast d'erreur), la demande reste `pending` — jamais d'approbation
   sans cible (qui laisserait le poste 403 indéfiniment + demande invisible).

### Scénario 10.10 — Autorisation des actions d'approbation (review #4)

1. Connecté avec un compte **sans** la permission `computer.install`, tenter
   `approve` / `confirmReject` / `enableCampaign` (idéalement via un appel direct
   `/livewire/update`, pas seulement via la page).
2. Attendu : **403** (Gate), aucune mutation — la demande reste `pending`, la
   campagne inchangée. Avec `computer.install`, l'action passe et `resolved_by`
   porte l'identifiant de l'admin (trace d'audit).

---

## Section 11 — Les deux chemins d'installation : GPO-dispatcher figée + dépôt iPXE (Story 25.4)

> **Append-only.** Smokes e2e exécutés sur VM/poste de lab (publication GPO
> Administrator + install WinPE), pas depuis dev-cycle. Pré-requis serveur :
> `php artisan auth:ca:init` (sinon `/api/v1/agent/ca` → 503) + une release
> **stable** publiée (`agent:release:publish … --stable`). Sur la VM, une
> release `2.1.2` (cert TEST) est laissée stable par 25.1/25.2.

### 11.0 — Endpoints d'amorçage LAN (serveur, automatisable)

1. `curl http://<se5>/api/v1/agent/stable` depuis le LAN → `{success, version,
   hash, url}`, `url` absolue se terminant par `/api/v1/agent/stable/download`.
2. `curl -o agent.exe http://<se5>/api/v1/agent/stable/download` → binaire,
   `sha256sum agent.exe` = `hash` du manifest.
3. `curl http://<se5>/api/v1/agent/ca` → PEM `-----BEGIN CERTIFICATE-----`,
   `Content-Type: text/plain`.
4. Contre-épreuves : depuis une IP **hors LAN** → **403** (`local.request`) ;
   CA non initialisée → **503** (jamais 500) ; aucune stable → `/stable` 404
   `no_release` et `/stable/download` 404 indistinct.

### 11.1 — Poste neuf (iPXE/WinPE) : binaire + token + CA, sans GPO (AC3)

1. Réinstaller un poste par la chaîne iPXE/WinPE.
2. Attendu, au FirstLogon : Order 1 obtient le **token**, Order 3 déploie la
   **CA** (`certutil -store Root` la liste), télécharge le **binaire stable**
   vers `C:\Program Files\SambaEdu\Agent\agent.exe`, et `agent.exe install`
   enregistre le service SYSTEM.
3. Le poste finit avec un agent **vivant et déjà enrôlé** (token présent →
   convergence immédiate) — **aucune** GPO requise.
4. Contre-épreuve : couper le réseau au FirstLogon → l'install agent échoue
   **sans bloquer** l'install Windows (exit 0) ; le poste est rattrapé par le
   filet GPO au boot suivant.

### 11.2 — Poste migré : la GPO-dispatcher figée installe l'agent (AC1)

1. Publier le template `se4_agent_bootstrap` vers SYSVOL (runbook
   `docs/runbooks/gpo-se4-agent-bootstrap.md`, workaround Administrator) et
   lier la GPO à la racine du domaine (ou OU Parcs).
2. Sur un poste migré **sans agent**, `gpupdate /force` puis reboot.
3. Attendu : le `startup.cmd` déploie la CA, télécharge le binaire stable,
   `agent.exe install` enregistre le service, et une **tâche de refresh**
   `SambaEduAgent-Bootstrap-Refresh` (SYSTEM, 240 min) est créée.
4. L'agent posé **demande son enrôlement** (porte 2) : côté UI, une demande
   `pending` apparaît ; après **approbation un-clic** (ou campagne), le poste
   converge au check-in suivant (cf. Section 10).

### 11.3 — Le filet éternel : la GPO répare un agent briqué/supprimé (AC2, #27)

1. Sur un poste enrôlé, supprimer le service (`agent.exe uninstall`) ou
   corrompre `agent.exe`.
2. Repasser la GPO (reboot **ou** attendre la tâche de refresh).
3. Attendu : `agent.exe install` **réinstalle** au même emplacement ; le
   **token survit** (hors périmètre install) → l'agent repart **directement** en
   convergence, **sans** ré-enrôlement.
4. Auto-réparation : supprimer la tâche `SambaEduAgent-Bootstrap-Refresh` puis
   repasser le startup → la tâche est **recréée**.

### 11.4 — Auto-enroll de l'agent Go sans token, sans brique (AC5)

1. Installer l'agent sur un poste **sans token** (`agent.exe install
   -server-url http://<se5>` sans token présent — la garde est relâchée).
2. Lancer `agent.exe run` (console) : à chaque cycle « token absent », l'agent
   poste sa demande porte 2 (`POST /v1/agent/enrollment`, **sans** Authorization,
   faisceau `{uuid, mac, hostname}`).
3. Attendu : **403** → check-ins légers à cadence normale (jamais de spin) ;
   après approbation, **200 {token}** → token écrit, bascule en convergence au
   cycle suivant ; un poste dont la MAC matche un enrôlé → **409** + log conflit,
   **jamais** de ré-enrôlement auto.
4. Contre-épreuve `rejected` : rejeter la demande → l'agent boucle dans le vide
   (403), **aucune** escalade, **aucun** brick. Non-régression : un poste avec
   token converge normalement (GET /state / POST /report / rotation D5 intacts).

### 11.5 — Frontière (review grep)

1. `grep -rE 'ldap|kerberos|samba-tool' app/Http/Controllers/Api/V1/Agent/BootstrapController.php app/Services/Agent/Releases/ReleaseManifestService.php` → **zéro** match.
2. Les endpoints d'amorçage ne **lisent** que `agent_releases` + le `.crt` PKI,
   n'écrivent rien. Golden files figés (state/report/release-manifest/contract-v1)
   intouchés.

---

## Section 12 — Console de pilotage de la flotte : UI parc-settings/agent (Story 25.5)

La page `parc-settings/agent/` (route `parc-settings.agent`, `can:computer.install`) devient la **console de pilotage de la flotte** : 3 surfaces sur une seule page (releases & rings, progression du déploiement, enrôlements en attente). C'est de la **plomberie de surface** — tous les moteurs existent (25.1-25.4), l'UI est une 2ᵉ façade sur les **mêmes services** (`ReleaseCreationService` seul écrivain, `EnrollmentService::approveManually`). Greffe back unique : la version rapportée par l'agent est désormais **persistée** (`workstations.agent_reported_version`).

### Scénario 12.1 — Cibler un ring sur une version (promotion canari)

1. Sur la VM, s'assurer qu'au moins deux releases existent (ex. `2.1.2` stable + une autre publiée via `agent:release:create`).
2. Ouvrir `parc-settings/agent/`, section « Releases publiées » → bouton **« Cibler un ring »**.
3. Dans la modale, choisir un groupe (salle physique OU parc logique) + une version, valider.
4. **Attendu** : toast de succès ; une ligne apparaît dans « Rings ciblés » (groupe → version, « dernier ciblage » = à l'instant). Côté logs `agent` : un `agent.release.targeted` (PAS `promoted`).
5. Vérifier qu'AUCUNE écriture directe n'a eu lieu hors du service : `agent_release_rings` n'a qu'une ligne par groupe (UNIQUE), `updated_at` rafraîchi.

### Scénario 12.2 — Définir / rollback la stable par défaut (`promote`)

1. Section « Releases publiées » → sur une release non-stable, bouton **« Définir stable »** → confirmer dans la modale.
2. **Attendu** : toast de succès ; le badge « stable par défaut » se déplace sur la nouvelle version ; **au plus une** release stable (invariant transactionnel). Log `agent.release.promoted`.
3. Rollback du défaut parc : re-« Définir stable » sur la version antérieure → les postes **sans ring** reconvergent à leur prochain check-in.

### Scénario 12.3 — Rollback d'un ring raté

1. Sur un ring ciblé sur une version canari, bouton **« Rollback »** (`wire:confirm`).
2. **Attendu** : le ring est re-ciblé sur la **stable par défaut** (c'est un `target()`, pas une suppression de ligne) ; toast ; log `agent.release.targeted`. Les postes du groupe reconvergent vers la stable.
3. Cas dégradé : aucune stable publiée → toast d'erreur explicite, aucune écriture.

### Scénario 12.4 — Progression du déploiement par ring

1. Après avoir ciblé un ring, laisser des postes du groupe poster un rapport (boucle agent / `curl` POST report — cf. scénario 2.1) avec leur `agent_version`.
2. Section « Progression du déploiement » : par ring, version ciblée vs comptes **à jour / en retard / jamais vus**, + « dernier rapport ».
3. **Attendu** : un poste rapportant la version ciblée passe « à jour » ; un poste sur une autre version = « en retard » ; un poste qui n'a jamais rapporté = « jamais vu ». La fraîcheur (`agent_reported_version_at`) avance à chaque report. Surface **lecture seule** (aucune écriture, zéro AD).
4. Greffe back à vérifier : `workstations.agent_reported_version` se peuple au fil des reports (avant 25.5, la colonne n'existait pas — la version était jetée).
5. **Ring effectif / poste multi-rings** (angle de test, multi-appartenance fréquente : physique + logiques) : prendre un poste membre d'un groupe physique **et** d'un groupe logique, les **deux** ciblés sur des versions différentes. Le manifest ne sert qu'**une** version = celle du ring **le plus récemment ciblé** (récence FR4, iso `ReleaseManifestService`). **Attendu** : le poste est compté **une seule fois**, dans le ring le plus récent, et n'apparaît **jamais « en retard »** dans l'autre ring (qui ne gouverne pas sa version). Re-cibler l'autre ring plus récemment doit basculer le poste vers ce ring au prochain calcul. Couvert par `DeploymentProgressSurfaceTest::a_multi_ring_workstation_is_counted_once_in_its_most_recently_targeted_ring`.

### Scénario 12.5 — Approbation d'un poste « inconnu » par sélection de cible (extension 25.3)

1. Provoquer une demande d'enrôlement `pending` **sans rapprochement** (badge « inconnu » — poste non rapproché en DB).
2. Section « Enrôlements en attente » : le bouton **« Approuver… »** est désormais **actif** (en 25.3 il était désactivé, renvoyé à 25.5).
3. Cliquer → modale de **sélection de cible** : rechercher (nom/MAC) un poste **non enrôlé**, le choisir (radio), valider.
4. **Attendu** : `EnrollmentService::approveManually($req, admin, $target)` arme la demande sur **la cible choisie** ; toast ; la demande passe `approved` ; le **token ne transite jamais par l'UI** (il naîtra au prochain `redeem()` du poste).
5. **Anti-usurpation** (à conserver comme angle de test) : aucune **auto-sélection** silencieuse — sans cible choisie, la demande reste `pending` ; les postes déjà enrôlés n'apparaissent **pas** dans la liste des candidats.

### Scénario 12.6 — Frontière, autorisation, golden files

1. Vérifier qu'aucune action de la page n'écrit hors `agent_*` ni n'appelle d'AD : `grep -rE 'ldap|kerberos|samba-tool'` sur les partials 25.5 = vide.
2. Adressabilité `/livewire/update` : un utilisateur sans `computer.install` qui appelle directement une action mutante (cibler / promote / rollback / approuver-cible) reçoit **403** (`Gate::authorize`), aucune mutation. Les `#[Computed]` de lecture restent accessibles via l'accès page.
3. Golden files **intouchés** (state/report/release-manifest/contract-v1) : le contrat de report ne change pas — la colonne `agent_reported_version` ne modifie pas le payload. Le golden `report.v1.json` passe verbatim (`ReportedVersionPersistenceTest`).

### Scénario 12.7 — Anti-usurpation : la sélection de cible refuse une demande déjà rapprochée (review #2)

1. Provoquer une demande `pending` **avec** rapprochement (`matched_workstation_id` renseigné — poste connu/concordant).
2. Dans l'UI, cette demande propose l'approbation un-clic (scénario 10.3), **pas** la modale de sélection de cible (réservée aux « inconnus », scénario 12.5).
3. **Attendu (garde à l'ouverture)** : forcer `openTargetSelect` sur cette demande (appel direct `/livewire/update`) → toast d'erreur « déjà rapproché », la modale ne s'ouvre pas.
4. **Attendu (défense en profondeur)** : forcer `confirmApproveWithTarget` avec une autre cible → refusé ; la demande reste `pending` ET son `matched_workstation_id` d'origine est **intact** (aucun override). Couvert par `EnrollmentRequestsSurfaceTest::selecting_a_target_is_refused_for_an_already_matched_request`.

---

## Section 13 — Handler raccourcis : le bureau converge selon la nature du poste (Story 27.1)

> Premier type de l'Epic 27 (parité de compétences) : provider `shortcuts`
> serveur + handler agent Go + golden + **première exposition du toggle
> strict/default** (rétroactif wallpaper/overlay). Corrige **définitivement le
> Bug C** (bureau réseau figé en dur sur poste partagé → `curl(23)`) par le bon
> modèle (donnée du domaine, pas branche `.cmd` legacy). Prérequis : Section 12
> (UI agent), domaine `parc` Story 26.1 (environnement de poste).

### Scénario 13.1 — Convergence du bureau RÉSEAU sur poste partagé (`shared_local`)

1. Parc du poste = `shared_local` (défaut). Créer un raccourci `place=desktop`
   (UI `/app/shortcuts/new`), l'assigner au parc (ou au poste).
2. Sur le poste lab : ouvrir une session. **Attendu** : le `.lnk` apparaît au
   bureau **réseau** (`\\<se4fs>\users\<user>\Bureau\`) — plus aucun `curl(23)`.
3. Vérifier le payload servi : `GET /api/v1/agent/state?user=<login>` →
   item `shortcuts` (portée `machine_user`) avec
   `desktop_path` = `\\<se4fs>\users\<user>\Bureau\` (tokens substitués côté
   poste, pas dans le JSON serveur).

### Scénario 13.2 — Convergence du bureau LOCAL sur parc personnel/nomade

1. Basculer le parc en `personal_local` (onglet Environnement, Story 26.1).
2. Forcer une synchro (Section 7) puis ré-ouvrir la session.
3. **Attendu** : le même raccourci est désormais posé au bureau **local**
   (`%USERPROFILE%\Desktop\`) — c'est la donnée du domaine (et non une branche
   figée) qui dicte le chemin. Le pansement legacy `4e5a152` est **intouché**
   (il meurt avec son canal en 27.6).

### Scénario 13.3 — Union multi-mailles, sans doublon

1. Assigner UN MÊME raccourci au parc ET au poste, plus un autre raccourci au
   groupe de l'utilisateur.
2. **Attendu** : le bureau reçoit l'**union** (2 raccourcis distincts), le
   doublon parc+poste n'apparaît **qu'une fois** (dédup par contenu côté
   compilateur). Le hash d'agrégat du rapport est stable (deux relevés
   identiques = zéro événement).

### Scénario 13.4 — Suppression level-triggered (jamais d'accumulation)

1. Retirer un raccourci des règles (ou le passer `is_active=false`).
2. Au passage suivant de l'agent : **attendu** le `.lnk` géré **disparaît** du
   poste (convergence, pas cumul historique — contraste avec le `shortcuts.txt`
   legacy).
3. **Garde-fou** : créer manuellement un `.lnk` utilisateur au bureau (ex.
   `MesNotes.lnk`). Après convergence, il est **toujours là** — l'agent ne
   supprime QUE les raccourcis portant son marqueur de gestion (champ
   Description = `SambaEdu desired-state managed shortcut`).

### Scénario 13.5 — Toggle strict/default + `drifted_allowed` (FR26, 1re exposition UI)

1. Sur un raccourci en mode **strict** : supprimer le `.lnk` à la main →
   **attendu** il est **recréé** au passage suivant (`drift`), la cible fait loi.
2. Basculer le raccourci en mode **souple** (toggle UI `/app/shortcuts/{id}`).
   Une fois la cible appliquée, supprimer le `.lnk` à la main → **attendu** il
   **n'est PAS recréé** ; le rapport (`POST /report`) porte `drifted_allowed`
   pour le type `shortcuts` (la machine d'états §5 du moteur, non réimplémentée,
   distingue dérive humaine d'une cible qui a bougé).
3. **Toggle rétroactif** : vérifier que le toggle strict/souple est aussi exposé
   sur les **fonds d'écran** (`/app/parc-settings/wallpapers`, carte wallpaper) et
   les **overlays** (`/app/parc-settings/overlay-messages`, sélecteur Application).
   Non-régression : sans bascule, wallpaper reste `default` et overlay `strict`
   (comportement avant 27.1 préservé — `mode` null en base = défaut du provider).

### Scénario 13.6 — Lecture seule + ZÉRO AD (NFR7, critère Keycloak)

1. `grep -rE 'ldap|apcu|get_apps|samba-tool|ad_users|ad_user_groups'` sur
   `app/Services/Agent/Providers/ShortcutsStateProvider.php` → **aucun appel**
   (les seules occurrences sont des commentaires documentant l'interdit).
2. Un raccourci ciblé **uniquement** par `ad_users`/`ad_user_groups` (CN AD
   legacy) **ne produit aucun item** servi par l'agent (ciblage MVP pivot SQL
   seulement). Le ciblage poste/parc/user/groupes user via le pivot
   `shortcut_assignables` fonctionne.

### Scénario 13.7 — Golden file & cohérence serveur/agent (NFR13)

1. Le golden `tests/Fixtures/Agent/state.v1.json` porte le payload `shortcuts`
   v1 RÉEL (`{name, target, args, icon, place, desktop_path}`) ; le hash figé
   `ContractV1Test::FROZEN_STATE_HASH` a été bumpé **sciemment** (évolution
   mineure §9, documentée). `php artisan test --filter ContractV1` vert.
2. Côté agent : `go test ./...` vert — le test croisé `hasher_test.go`
   (`frozenStateHash`) prouve que le hasher Go produit le **même** hash que le
   StateHasher PHP sur le nouveau payload (frontière de contrat respectée).

## Post-correctifs & non-régressions

- **Defer review 23.1 (résolu en 24.1)** : le scénario 1.4 (body forgé → 4xx jamais 500) existe parce qu'un `StateHasher` appelé sur l'entrée agent pouvait lever une `JsonException` non catchée (UTF-8 invalide / NAN / INF). L'ingestion ne hashe JAMAIS le payload agent.
- **Review 24.3 #1 (corrigé)** : `Get-InteractiveSessions` filtrait les pseudo-sessions par liste NOIRE (`S-1-5-90/96-`) — comptes virtuels (`S-1-5-80/82-`) et `Win32_Account.Name` vides passaient → fetchs `?user=` (vide) + caches `sessions\<SID-service>\` parasites. Corrigé en liste BLANCHE `^S-1-5-21-` + garde login vide. Angle de test à conserver : sur le poste lab (scénario 3.2), vérifier qu'AUCUN répertoire `cache\sessions\` ne correspond à un SID hors `S-1-5-21-*` ; côté serveur, `?user=` VIDE = 200 machine-only SANS `agent.state.unknown_user` (figé par `SessionCompanionE2eTest::empty_user_param_…`).
- **Incident terrain T12 ws 49 n° 2 (corrigé en 2.1.2)** : la tâche `SambaEduAgent-SessionCompanion` (binaire CONSOLE lancé dans la session interactive) laissait une **fenêtre console visible et résidente** toute la session — fermable par le user (= compagnon tué), et un clic dedans (quick-edit) gelait stdout. Corrigé : `FreeConsole` au démarrage du compagnon (bref flash au logon, assumé). Angle de test à conserver (scénario 6.2) : après logon, AUCUNE fenêtre console résiduelle ; `agent.exe companion` visible dans le Gestionnaire des tâches uniquement.
- **Review 25.3 #3/#4 (corrigés avant merge)** : deux angles que les tests unitaires n'attrapaient pas mais qu'un test manuel révèle. (a) **Approuver un poste « inconnu »** (sans rapprochement DB) menait à une impasse : la demande passait `approved` mais l'étape 2 du redeem exige une cible → poste 403 éternel + demande sortie du scope pending (invisible). Corrigé : le bouton « Approuver » est **désactivé** (tooltip « rapprochement requis ») pour une demande sans `matched_workstation_id`, et le service le refuse. Angle de test à conserver (scénario 10.9) : une demande badge « inconnu » ne propose PAS d'approbation actionnable. (b) **Actions Livewire non gardées** : `approve/reject/campagne` ne reposaient que sur le middleware de page — un appel direct `/livewire/update` les exposait. Corrigé : `Gate::authorize('computer.install')` sur chaque action mutante. Angle de test (scénario 10.10) : un utilisateur sans `computer.install` reçoit 403 sur l'action, la demande reste `pending`. Bonus observabilité : un log `agent.enroll.stale_approval` (warning) signale désormais une approbation qui ne se matérialise pas (poste enrôlé entre-temps / cible nulle).
- **Review 25.3 #M2/#M3 (arbitrage Henri, corrigés)** : le conflit 409 se fondait sur `resolveByIdentity()` (uuid puis MAC, `.first()`). Deux trous : (a) **oracle** — présenter l'UUID seul d'un poste enrôlé donnait 409 (≠403), révélant sa présence via une preuve faible/spoofable ; (b) **MAC partagée** — `.first()` pouvait tomber sur un clone non-enrôlé et rater le conflit. Corrigé : conflit fondé sur la **seule MAC** via `Workstation::where('mac')->whereNotNull('agent_token_hash')->exists()` ; `resolveByIdentity()` supprimée. **Changement de contrat propagé à la porte 1** (le 409 par uuid-seul disparaît). Angles de test à conserver : scénarios 10.11 (MAC partagée → 409 indépendant de l'ordre) et 10.12 (uuid seul → 403 sans oracle).
- **Review 25.5 #2 (corrigé avant merge)** : la modale de **sélection de cible** (approbation d'un poste « inconnu », scénario 12.5) ne vérifiait que `matched_workstation_id === null` côté *template* (affichage du bouton). Les méthodes Livewire `openTargetSelect` / `confirmApproveWithTarget` ne gardaient pas l'invariant : via un appel direct `/livewire/update` sur une demande **déjà rapprochée**, un admin pouvait écraser silencieusement le rapprochement par une autre cible (`approveManually` donne priorité au `$target`) — contournement de l'anti-usurpation. Corrigé : garde `matched_workstation_id !== null → toastError` dans les **deux** méthodes (ouverture + confirmation, défense en profondeur). Angle de test à conserver (scénario 12.7) : une demande **rapprochée** ne propose pas/refuse la sélection de cible ; un appel forcé laisse le rapprochement d'origine intact, demande `pending`.
- **Incident terrain T12 ws 49 (corrigé en 2.1.1)** : `agent.exe install` échouait en `Accès refusé` sur le rename atomique de `config.json` — `setAgentACL` posait les flags d'héritage `(OI)(CI)` sur les FICHIERS tmp de `writeAtomic` ; via icacls sur un fichier, ces ACE deviennent inertes pour l'accès au fichier lui-même → DACL effective vide, plus personne (pas même SYSTEM) n'a DELETE, le rename échoue. Invisible des 122 tests hôte (icacls = Windows réel uniquement) — détecté à la PREMIÈRE exécution Windows du binaire. Corrigé : `setAgentACL` distingue répertoire (`(OI)(CI)F`) / fichier (`F` plat). Angle de test à conserver (scénario 6.1) : après install, `icacls C:\ProgramData\SambaEdu\Agent\config.json` doit montrer des ACE SANS flags `(OI)(CI)`. Méthode de diagnostic qui a tranché : reproduction manuelle A/B de la séquence writeAtomic (`Set-Content` tmp → `icacls /inheritance:r /grant` avec puis sans flags → `Rename-Item`). Nettoyage d'un poste touché : supprimer `cache\state.json`/`etag.txt` et `applied-state.json` écrits par un binaire ≤ 2.1.0 (DACL inerte = irremplaçables par le service), JAMAIS le `token`.
- **Bump du hash figé `FROZEN_STATE_HASH` (Story 27.1, intentionnel)** : le golden `state.v1.json` portait pour `shortcuts` un payload SQUELETTE illustratif (`{name, target, location}`) ; il est passé au payload v1 RÉEL (`{name, target, args, icon, place, desktop_path}`) owné par `ShortcutsStateProvider`. C'est une **évolution mineure** du contrat (§9, champ/payload ajouté, forward-compatible) — PAS un bump de major. La constante a été mise à jour **sciemment** dans `ContractV1Test.php` ET dans `agent/shared/hasher_test.go` (test croisé NFR13 : le hasher Go DOIT reproduire le hash PHP). Toute future divergence de ces deux valeurs = régression de canonicalisation, jamais à « corriger » en alignant aveuglément.

### Story 27.1 — bugs manqués par les tests unitaires, détectables en manuel (corrigés post-review)

Ces trois incidents passaient les tests unitaires initiaux mais se révèlent à l'usage. Tableau incident → scénario de non-régression (couvert depuis par les tests `handler_shortcuts_test.go` #6/#7 et `OverlayStateProviderTest`/`WallpaperStateProviderTest`).

| # | Incident (symptôme terrain) | Cause | Scénario de non-régression |
|---|------------------------------|-------|----------------------------|
| 1 (homonyme) | Un prof crée « Intranet.lnk » sur son bureau (même nom qu'un raccourci géré) → **AUCUN raccourci SambaEdu** ne se pose plus sur ce poste, en silence (le type entier passe `error`). | `Matches()` Windows retournait une **erreur** sur un `.lnk` non géré au chemin d'une cible ; le moteur propage toute erreur de `test` en `{status: error}` pour le TYPE entier. | Sur le lab : créer manuellement un `.lnk` homonyme d'une cible sur le bureau, puis déclencher une convergence. Attendu : le fichier user reste **intact** (jamais écrasé/supprimé), les AUTRES raccourcis se posent quand même, le type n'est PAS en `error`. (`handler_shortcuts_test.go::TestShortcutsUserHomonymOnDesiredPathIsIgnored`.) |
| 2 (cross-placement) | On retire TOUTES les règles `desktop` mais on garde une règle `startup` → le `.lnk` Bureau géré reste **orphelin pour toujours** (jamais nettoyé). | `managedDirs()` ne balayait que les emplacements présents dans le `desired` courant ; un emplacement vidé de ses règles n'était plus balayé. | Sur le lab : poser un raccourci desktop + un startup, puis retirer la règle desktop (garder startup) et reconverger. Attendu : le `.lnk` desktop géré **disparaît** au passage suivant ; les `.lnk` user (sans marqueur) ne sont JAMAIS touchés. (`handler_shortcuts_test.go::TestShortcutsCrossPlacementOrphanRemoved`.) |
| 5 / M1 (toggle/UI honnête) | (5) Le toggle overlay strict↔default paraît **inopérant** : dès qu'un user est en session, l'overlay reste `strict` quoi que choisisse l'admin. (M1) La carte wallpaper surligne « Strict » alors que le poste applique réellement `default`. | (5) Le candidat synthétique `identity` (sentinel `sourceId=0`, mode null → défaut provider `strict`) pesait dans l'agrégation du mode du type `overlay`. (M1) L'UI affichait `?? 'strict'` en dur au lieu du défaut RÉEL du provider wallpaper (`default`). | (5) Poster des messages overlay tous en `default` AVEC un user en session → l'item `overlay` du `GET /state` doit porter `mode: default`. (`OverlayStateProviderTest::overlay_aggregates_to_default_when_all_real_signals_are_default_despite_identity`.) (M1) Ouvrir une carte wallpaper d'une règle sans `mode` en base → le bouton **« Souple »** (default) doit être surligné, pas « Strict » ; le `GET /state` confirme `mode: default`. (`WallpaperStateProviderTest::rule_without_mode_compiles_to_provider_default_not_strict`.) |

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
- [ ] 3.1 — chemin `?user=` simulé curl : 2 ETags par contexte, jamais de 304 cross-contexte, unknown_user info
- [ ] 3.2 — logon nominal lab : cache per-SID + ACL, companion.log, `agent.state.compiled` avec user
- [ ] 3.3 — logon hors-ligne : session normale, compagnon sur dernier cache, zéro message visible
- [ ] 3.4 — KPI : 3 logons ON vs 3 OFF, écart dans le bruit (< ~1 s), mesures tracées
- [ ] 3.5 — frontière de confiance : token/écritures/cache d'autrui refusés, son cache lisible, signature Valid
- [ ] 4.1 — route assets curl : 200 binaire SHA-256 ok, 404 inconnu/malformé, 401 sans token, logs served/not_found
- [ ] 4.2 — wallpaper UI → poste : asset téléchargé+vérifié, fond appliqué, compliant en base, idempotent
- [ ] 4.3 — overlay : identité+salle+signal posté visibles (Rainmeter installé manuellement — prérequis encadré), gracieux sans Rainmeter
- [ ] 4.4 — mode default : fond changé à la main → drifted_allowed NON réappliqué ; nouvelle cible UI → drift appliqué
- [ ] 4.5 — erreur isolée : error+detail pour le type en échec, les autres continuent ; drop forgé rejeté + validation stricte
- [ ] 4.6 — boucle stable : reported_at avance, zéro événement sur rapports identiques
- [ ] 5.1 — build Go signé : go test + cross-compile verts, osslsigncode verify ok, refus sans PFX
- [ ] 5.2 — service Go installé poste lab : SYSTEM natif, signature Valid, boucle fermée, ACL/contrats locaux intacts
- [ ] 5.3 — check-in serveur : agent_last_checkin_at + received counts zéro, agent_version 2.0.0, zéro identity_mismatch
- [ ] 5.4 — résilience Go : backoff 30→3600, quarantaine check-ins légers + levée auto, rotation D5, 401 = arrêt propre
- [ ] 5.5 — uninstall conservateur (données gardées), -purge destructif explicite
- [ ] 6.1 — install Go complet : service + 2 tâches at-logon (SYSTEM borné / Users résident), tâches PS remplacées, version 2.1.0
- [ ] 6.2 — logon nominal : cache per-SID + drop dir + companion.log, compagnon résident, `agent.state.compiled` avec user
- [ ] 6.3 — logon hors-ligne : session normale sur dernier cache ; KPI 3×ON/3×OFF rejoué sur le binaire Go
- [ ] 6.4 — frontière de confiance Go : token illisible, écritures refusées (sauf SON drop), zéro réseau compagnon
- [ ] 6.5 — démo wallpaper UI→poste→rapport : asset vérifié, fond appliqué, compliant en base, zéro événement sur stable, agent_version 2.1.0
- [ ] 6.6 — mode default Go : dérive humaine → drifted_allowed non réappliqué ; cible changée → drift appliqué
- [ ] 6.7 — overlay Go : identité+signal affichés, sérialiseur fixe, Rainmeter absent gracieux, strict réécrit + drift
- [ ] 6.8 — erreur isolée + drops forgés rejetés entrée par entrée (validation stricte au cycle)
- [ ] 7.1 — smoke serveur : demande pendante → GET /state 200 forcé (même ETag) ; report → soldée (null) ; quarantaine → 403 sans solde
- [ ] 7.2 — page parc : compteurs conformité (enrôlés), badge worst-status par ligne, filtre conformité + reset
- [ ] 7.3 — détail poste : état par type daté + 10 événements + dérivés (jamais rapporté / muet), non enrôlé neutre
- [ ] 7.4 — forcer la synchro poste : demande posée + toast + log requested, bouton désactivé non-enrôlé/quarantaine, solde visible
- [ ] 7.5 — démo live (ws 49) : wallpaper UI → parc → écart visible (panneau groupe règles→exceptions) → résorption ; forcer synchro groupe
- [ ] 7.6 — retour auto à compliant sans forcer (wire:poll borné, relecture agent_resource_states)
- [ ] 8.1 — créer release : hash KO refusé exit ≠ 0 zéro ligne, hash OK + stable, variantes refus
- [ ] 8.2 — manifest poste enrôlé : 401 sans token, 200 stable sans ring (url absolue = APP_URL), 404 no_release sans stable
- [ ] 8.3 — canari ring 1 poste lab : canari servie au lab, stable aux autres, ring_conflict si multi-rings
- [ ] 8.4 — rollback : re-ciblage stable gagne par récence ; promote = une seule stable
- [ ] 8.5 — download : sha256sum = hash manifest, 401/403, 404 indistinct (forgé/traversal/orphelin), X-Agent-New-Token sur 200
- [ ] 9.0 — matrice auto `go test ./...` verte (≥ 20 cas, primitives Windows stubées)
- [ ] 9.1 — convergence par ring : canari vN+1 lab → version rapportée passe à vN+1, autres postes inchangés
- [ ] 9.2 — binaire corrompu : hash KO → jeté, rien écrit, aucun swap, item agent_update error
- [ ] 9.3 — binaire non signé / hors confiance : hash OK mais Authenticode KO → stagé mais jamais swappé
- [ ] 9.4 — anti-brique : swap interrompu → agent en place fonctionnel, jamais « ni ancien ni nouveau » ; restart = recovery SCM après sortie non-gracieuse (plantage volontaire attendu code 42)
- [ ] 9.5 — token/cache survivent au swap : pas de ré-enrôlement, rotation D5 OK après update
- [ ] 9.6 — 403 release : update sauté SANS quarantaine globale, le POST /report a bien lieu (contre-épreuve : 403 /state met bien en quarantaine)
- [ ] 10.1 — poste migré sans ticket → 403 + demande pending visible UI, badge rapproché, log requested
- [ ] 10.2 — idempotence : re-check-in → 1 seule demande, last_seen_at rafraîchi
- [ ] 10.3 — approbation un-clic → log approved ; prochain check-in → 200 token + demande consommée (token jamais dans l'UI)
- [ ] 10.4 — campagne ON + poste connu concordant → auto_approved ; re-check-in → 200 token
- [ ] 10.5 — campagne ON + divergent/inconnu/multi-candidat → reste manuel pending (invariant anti-usurpation)
- [ ] 10.6 — poste connu déjà enrôlé → 409 conflit, token intact, aucune demande pending
- [ ] 10.7 — rejet → 403 au re-check-in, aucun token, pas de ré-ouverture auto
- [ ] 10.8 — non-régression porte 1 : ticket valide → 200 token direct, aucune demande créée
- [ ] 10.9 — demande « inconnu » : bouton Approuver désactivé, service refuse, reste pending (review #3)
- [ ] 10.10 — autorisation : sans `computer.install` → 403 sur l'action, aucune mutation ; avec → resolved_by renseigné (review #4)
- [ ] 10.11 — conflit sous MAC partagée : 409 quel que soit l'ordre des fiches (review #M2)
- [ ] 10.12 — pas d'oracle UUID : uuid d'un enrôlé + MAC étrangère → 403 indistinct, jamais 409 (review #M3)
- [ ] 13.1 — bureau RÉSEAU sur `shared_local` : `.lnk` posé à `\\<se4fs>\users\<user>\Bureau\`, fini le curl(23)
- [ ] 13.2 — bureau LOCAL sur `personal_local`/`nomade` : `.lnk` à `%USERPROFILE%\Desktop\` (donnée du domaine, pas branche figée)
- [ ] 13.3 — union multi-mailles sans doublon, hash d'agrégat stable
- [ ] 13.4 — suppression level-triggered (raccourci sorti des règles disparaît) ; raccourci UTILISATEUR jamais supprimé
- [ ] 13.5 — toggle strict/default : strict recrée, souple → `drifted_allowed` non recréé ; toggle visible aussi wallpaper + overlay
- [ ] 13.6 — lecture seule + zéro AD (grep vide ; ciblage AD-CN seul = aucun item)
- [ ] 13.7 — golden v1 + hash figé bumpé sciemment ; `go test` croisé serveur/agent vert (NFR13)
