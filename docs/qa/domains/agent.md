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

## Post-correctifs & non-régressions

- **Defer review 23.1 (résolu en 24.1)** : le scénario 1.4 (body forgé → 4xx jamais 500) existe parce qu'un `StateHasher` appelé sur l'entrée agent pouvait lever une `JsonException` non catchée (UTF-8 invalide / NAN / INF). L'ingestion ne hashe JAMAIS le payload agent.
- **Review 24.3 #1 (corrigé)** : `Get-InteractiveSessions` filtrait les pseudo-sessions par liste NOIRE (`S-1-5-90/96-`) — comptes virtuels (`S-1-5-80/82-`) et `Win32_Account.Name` vides passaient → fetchs `?user=` (vide) + caches `sessions\<SID-service>\` parasites. Corrigé en liste BLANCHE `^S-1-5-21-` + garde login vide. Angle de test à conserver : sur le poste lab (scénario 3.2), vérifier qu'AUCUN répertoire `cache\sessions\` ne correspond à un SID hors `S-1-5-21-*` ; côté serveur, `?user=` VIDE = 200 machine-only SANS `agent.state.unknown_user` (figé par `SessionCompanionE2eTest::empty_user_param_…`).
- **Incident terrain T12 ws 49 n° 2 (corrigé en 2.1.2)** : la tâche `SambaEduAgent-SessionCompanion` (binaire CONSOLE lancé dans la session interactive) laissait une **fenêtre console visible et résidente** toute la session — fermable par le user (= compagnon tué), et un clic dedans (quick-edit) gelait stdout. Corrigé : `FreeConsole` au démarrage du compagnon (bref flash au logon, assumé). Angle de test à conserver (scénario 6.2) : après logon, AUCUNE fenêtre console résiduelle ; `agent.exe companion` visible dans le Gestionnaire des tâches uniquement.
- **Incident terrain T12 ws 49 (corrigé en 2.1.1)** : `agent.exe install` échouait en `Accès refusé` sur le rename atomique de `config.json` — `setAgentACL` posait les flags d'héritage `(OI)(CI)` sur les FICHIERS tmp de `writeAtomic` ; via icacls sur un fichier, ces ACE deviennent inertes pour l'accès au fichier lui-même → DACL effective vide, plus personne (pas même SYSTEM) n'a DELETE, le rename échoue. Invisible des 122 tests hôte (icacls = Windows réel uniquement) — détecté à la PREMIÈRE exécution Windows du binaire. Corrigé : `setAgentACL` distingue répertoire (`(OI)(CI)F`) / fichier (`F` plat). Angle de test à conserver (scénario 6.1) : après install, `icacls C:\ProgramData\SambaEdu\Agent\config.json` doit montrer des ACE SANS flags `(OI)(CI)`. Méthode de diagnostic qui a tranché : reproduction manuelle A/B de la séquence writeAtomic (`Set-Content` tmp → `icacls /inheritance:r /grant` avec puis sans flags → `Rename-Item`). Nettoyage d'un poste touché : supprimer `cache\state.json`/`etag.txt` et `applied-state.json` écrits par un binaire ≤ 2.1.0 (DACL inerte = irremplaçables par le service), JAMAIS le `token`.

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
