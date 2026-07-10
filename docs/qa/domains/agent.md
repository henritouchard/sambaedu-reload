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

> **⚠️ ABROGÉ par Story 27.8.** Le mécanisme `mode` strict/default est retiré :
> la convergence est STRICT inconditionnelle (toute dérive humaine est TOUJOURS
> corrigée, `drifted_allowed` n'existe plus). Scénario conservé pour l'historique.
> Remplacé par la Section 19 (Story 27.8).

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

> **⚠️ ABROGÉ par Story 27.8.** Le mécanisme `mode` strict/default est retiré :
> la convergence est STRICT inconditionnelle (`drifted_allowed` n'existe plus).
> Scénario conservé pour l'historique. Remplacé par la Section 19 (Story 27.8).

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

> **⚠️ ABROGÉ par Story 27.8.** Le toggle strict/default et le statut
> `drifted_allowed` sont retirés (convergence STRICT inconditionnelle — un
> raccourci supprimé est TOUJOURS recréé). Scénario conservé pour l'historique.
> Remplacé par la Section 19 (Story 27.8).

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

## Section 14 — Handlers lecteurs & imprimantes (Story 27.2)

> Deuxième story de l'Epic 27 : reconduit le pattern 27.1 pour **deux types**
> d'un coup — `drives` (lecteurs réseau, projection MVP-A des classes) et
> `printers` (imprimantes, défaut réglé par WG). Providers serveur lecture seule
> + handlers agent Go (winspool/mpr natifs) + golden bumpé. **L'imprimante de la
> salle devient un item d'état comme les autres** (Vérité #9). Prérequis :
> Section 13 (handler raccourcis), domaine `printers` (imprimantes CUPS/SER),
> domaine `filesystem` (partages de classe). **Migration VM** : jouer
> `php artisan migrate` (colonne pivot `is_default`) — `migrate:status` d'abord
> (les migrations ne sont pas auto-jouées sur la VM).

### Scénario 14.1 — Convergence lecteurs + imprimantes UI → poste

1. Rattacher une imprimante à la **salle** du poste (onglet « Imprimantes » du
   groupe). Rattacher l'utilisateur du poste à une **classe** (`UserGroup
   type='classe'`) ayant un partage de classe.
2. Sur le poste lab : ouvrir une session. **Attendu** : l'imprimante de la salle
   est **installée** (connexion `\\<se4fs>\<cups_name>`) et le jeu standard de
   lecteurs est **monté** : `K:` → `\\<se4fs>\users\<login>\` (home) et `H:` →
   `\\<se4fs>\classes\` (racine du partage classes).
3. Vérifier le payload servi : `GET /api/v1/agent/state?user=<login>` → items
   `printers` et `drives` (portée `session`). `printers.connection` =
   `\\<se4fs>\<cups_name>` (connexion LOGIQUE, **jamais** l'URI back-end CUPS
   `socket://…`) ; `drives` = deux items `{K: \\<se4fs>\users\<user>\,
   H: \\<se4fs>\classes\}` (tokens `<se4fs>`/`<user>` substitués côté poste, pas
   dans le JSON serveur).

### Scénario 14.2 — Union multi-mailles (salle physique + parc logique)

1. Rattacher une imprimante à la **salle physique** du poste et une autre au
   **parc logique**. Rattacher la même imprimante aux deux mailles.
2. **Attendu** : le poste reçoit l'**union** des imprimantes des deux mailles ;
   l'imprimante commune n'apparaît **qu'une fois** (dédup par contenu côté
   compilateur, réutilisée de 27.1). Le hash d'agrégat du rapport est stable.

### Scénario 14.3 — Imprimante par défaut = réglée par WG (physique > logique)

1. Cocher « Par défaut » sur une imprimante de la **salle physique** (toggle
   `printers-list.blade.php` du groupe) et sur une AUTRE imprimante du **parc
   logique** du même poste.
2. **Attendu** : **une seule** imprimante porte `is_default: true` dans le
   payload — celle de la salle **physique** (qui l'emporte sur le parc logique,
   décision n° 5). L'agent pose `SetDefaultPrinter` sur cette imprimante.
3. Cocher deux défauts sur la **même** maille → départage déterministe
   `cups_name` asc (la plus petite alphabétiquement gagne). Le toggle est valable
   pour un WG **physique comme logique** (réglage explicite, pas d'auto-dérivation
   « la salle »).

### Scénario 14.4 — Suppression level-triggered (mapping retiré → démonté)

1. Retirer une imprimante des règles (détacher du WG). Au passage suivant :
   **attendu** l'imprimante gérée est **désinstallée** du poste.
2. Retirer l'utilisateur d'une classe. Au passage suivant : **attendu** le
   lecteur géré (`K:`) est **démonté** (convergence, pas accumulation).
3. **Garde-fou périmètre** : l'utilisateur installe manuellement une imprimante
   (autre serveur) et monte un lecteur perso (ex. `K:` vers `\\autreserveur\…`).
   Après convergence, ils sont **toujours là** — l'agent ne gère QUE les
   connexions/montages vers le serveur SambaEdu (`<se4fs>`), jamais ceux de
   l'utilisateur.

### Scénario 14.5 — Isolation serveur d'impression down → `error`, le reste converge

1. Rendre le serveur d'impression injoignable (arrêter le spooler / couper le
   partage Samba imprimante) avec une règle `printers` active.
2. **Attendu** : le rapport (`POST /report`) porte `status: error` + `detail`
   exploitable pour le SEUL type `printers` ; le type `drives` (et les autres :
   shortcuts/wallpaper/overlay) **converge quand même** (isolation `engine.go
   RunPass` §5, réutilisée). L'agent **retente** au cycle suivant
   (level-triggered).

### Scénario 14.6 — Lecture seule + ZÉRO AD (NFR7, critère Keycloak)

1. `grep -rE 'ldap|apcu|get_apps|samba-tool|ad_users|ad_user_groups'` sur
   `app/Services/Agent/Providers/{Printers,Drives}StateProvider.php` →
   **aucun appel** (les seules occurrences sont des commentaires documentant
   l'interdit). `CupsPrinterService` et `ShareService` sont **lus** (métadonnée /
   projection des classes), **jamais modifiés** ni câblés au canal legacy.
2. Le ciblage `printers` est par maille POSTE (salle/parc) — il n'existe aucune
   relation `UserGroup → Printer`. Le ciblage `drives` est par les classes du
   user (pivot SQL `user_group_user`), jamais par CN AD.

### Scénario 14.7 — Golden file & cohérence serveur/agent (NFR13)

1. Le golden `tests/Fixtures/Agent/state.v1.json` porte les payloads `printers`
   v1 (`{cups_name, connection, description, location, is_default}`) et `drives`
   v1 (`{letter, unc, label}`) en portée `session` ; le hash figé
   `ContractV1Test::FROZEN_STATE_HASH` a été bumpé **sciemment** (évolution
   mineure §9). `php artisan test --filter ContractV1` vert.
2. Côté agent : `go test ./...` vert — le test croisé `hasher_test.go`
   (`frozenStateHash`) prouve que le hasher Go produit le **même** hash que le
   StateHasher PHP sur les nouveaux payloads (frontière de contrat respectée).
3. Le golden `report.v1.json` illustre l'isolation : un item `printers` en
   `error` (avec `detail`) coexiste avec les autres statuts.

## Section 15 — Rendu overlay VERROUILLÉ (Rainmeter) — Story 27.1bis

> Accélérateur de démo rattaché à 27.1. Le handler de **données** overlay existe
> déjà (24.6 : `overlay.json` per-user). Cette story ajoute le **RENDU
> VERROUILLÉ** en 3 volets : (1) **provisioning** Rainmeter PORTABLE par l'agent
> au bootstrap (download vérifié SHA-256 + extraction ACL, install-if-absent) ;
> (2) le **SERVICE SYSTEM écrit `overlay.json` au logon** (session-change), le
> fichier est possédé SYSTEM avec ACL `<SID>:R` (infalsifiable, NFR5) — l'overlay
> a QUITTÉ la map du compagnon (D1) ; (3) **verrouillage** : skin posée
> **UTF-16 LE + BOM** (sinon mojibake `Â·`), `Rainmeter.ini` durci
> (`TrayIcon=0` / `Draggable=0` / `ClickThrough=1` / `KeepOnScreen=1`) sous
> `C:\ProgramData\SambaEdu\Rainmeter\` en ACL Users:R, **watchdog** compagnon qui
> relance `Rainmeter.exe` s'il est tué. **Q1 = logon-only** (alertes figées pour
> la session, pas de re-write périodique — assumé). **PAS d'obfuscation de
> process** (D7). **Aucun bump du golden** (composition réutilisée à l'identique).
>
> **Prérequis** : Section 3 (cache de session per-SID), Section 6 (compagnon Go),
> Section 4.3 (overlay de données). **Dépôt VM** : l'artefact portable Rainmeter
> `sambaedu-rainmeter-<version>-portable.zip` sous `storage/agent/tools/`
> (**chown www-admin** sinon serving en 404 silencieux) ; figer son SHA-256 dans
> la constante `RainmeterToolChecksum` (`agent/shared/rainmeter.go`) — tant
> qu'elle est vide, le provisioning est volontairement INERTE (Rainmeter absent
> reste gracieux). **`config:cache`** /vm après ajout de la clé `agent.tools_path`.

### Scénario 15.1 — Pose du portable Rainmeter au bootstrap (install-if-absent)

1. Déposer `sambaedu-rainmeter-<version>-portable.zip` sous `storage/agent/tools/`
   (chown www-admin) et renseigner son SHA-256 dans `RainmeterToolChecksum` ;
   rebâtir/déployer l'agent.
2. Sur le poste lab (service SYSTEM démarré, Rainmeter ABSENT) : au cycle de
   bootstrap, **attendu** l'agent télécharge l'artefact via
   `GET /api/v1/agent/tools/<filename>` (route **dédiée**, authentifiée agent),
   **vérifie le SHA-256 AVANT extraction**, et l'extrait sous
   `C:\ProgramData\SambaEdu\Rainmeter\app\` (`Rainmeter.exe` présent).
3. **Idempotence** : au cycle suivant, Rainmeter déjà posé → **no-op** (aucun
   re-téléchargement). **Zéro registre, zéro MSI/NSIS/winget** ; aucun handler
   runtime n'a installé Rainmeter (« handler jamais installeur »).
4. **Hash KO** : altérer l'artefact sur la VM (sans changer la constante) →
   l'agent **rejette** (SHA-256 divergent), **n'extrait rien**, retente au cycle
   suivant. (`agent.log` : « SHA-256 téléchargé … != attendu ».)
5. **Artefact absent (404)** ou constante vide → provisioning **sauté
   gracieusement**, `overlay.json` continue d'être écrit (Rainmeter absent =
   gracieux, invariant 24.4/24.6).

### Scénario 15.2 — ACL de la config verrouillée (Users:R, SYSTEM/Admins full)

1. Après provisioning : `icacls C:\ProgramData\SambaEdu\Rainmeter` →
   **attendu** `BUILTIN\Users:(OI)(CI)(R)` + `NT AUTHORITY\SYSTEM:(F)` +
   `BUILTIN\Administrators:(F)`, héritage retiré.
2. En **utilisateur standard** (non-admin), tenter de modifier
   `C:\ProgramData\SambaEdu\Rainmeter\Rainmeter.ini` (ex. remettre `TrayIcon=1`,
   `Draggable=1`) → **accès refusé**. La config est inaltérable par l'élève.

### Scénario 15.3 — `overlay.json` écrit par SYSTEM au logon, ACL `<SID>:R`

1. Ouvrir une session utilisateur. **Attendu** : `overlay.json` apparaît sous
   `%LOCALAPPDATA%\SambaEdu\Agent\overlay.json` (chemin per-user conservé, D2),
   composé par le SERVICE SYSTEM au logon (event-driven, **pas de polling**).
2. `icacls %LOCALAPPDATA%\SambaEdu\Agent\overlay.json` → **attendu** propriétaire
   `NT AUTHORITY\SYSTEM`, ACE `<SID-de-l'user>:(R)` (Read **seulement**), SYSTEM +
   Administrators full, héritage retiré, **PAS** de flags `(OI)(CI)` (c'est un
   FICHIER).
3. En tant que l'utilisateur de la session, tenter d'**éditer** `overlay.json`
   (modifier une alerte, une salle) → **accès refusé** : l'élève **lit** mais ne
   **falsifie jamais** la donnée affichée (NFR5).
4. **D1** : `overlay.json` n'est **plus** écrit par le compagnon (`companion.log`
   ne mentionne plus de handler `overlay`) ; wallpaper / shortcuts / printers /
   drives restent gérés par le compagnon (non-régression).
5. **Multi-session** : ouvrir une 2e session (autre user) → chaque user a SON
   `overlay.json` sous SON `%LOCALAPPDATA%`, ACL à SON SID. (Fallback documenté
   D2/Q2 : profil non résoluble sous SYSTEM → `%ProgramData%\SambaEdu\overlay.json`
   commun + warning `agent.log` ; perte assumée du per-user — à ne PAS confondre
   avec le chemin nominal.)

### Scénario 15.4 — Rendu verrouillé : non déplaçable, non masquable, épinglé, pas de tray

1. Rainmeter rend la skin SambaEduOverlay au logon : panneau identité + machine +
   1re alerte, épinglé en haut-droite.
2. **Non déplaçable** : tenter de glisser le panneau à la souris → il ne bouge
   pas (`Draggable=0`). **ClickThrough** : un clic dans le panneau passe à la
   fenêtre dessous (pas de menu contextuel skin, pas de focus). **Épinglé** :
   `KeepOnScreen=1` — jamais hors zone visible.
3. **Pas d'icône de tray** : aucune icône Rainmeter dans la zone de notification
   (`TrayIcon=0`) — l'élève ne pilote/masque rien depuis le tray.
4. **Pas d'obfuscation** (D7) : `Rainmeter.exe` est **visible** dans le
   Gestionnaire des tâches (assumé) — la défense est l'ACL + le watchdog, pas le
   masquage.

### Scénario 15.5 — UTF-16 LE + BOM : caractères accentués sans mojibake

1. `Get-Content C:\ProgramData\SambaEdu\Rainmeter\Skins\SambaEduOverlay\SambaEduOverlay.ini -Encoding Byte -TotalCount 2`
   → **attendu** `255 254` (BOM `FF FE` UTF-16 LE).
2. Composer une identité / une salle avec accents (ex. « Salle B-12 · élève
   éàü ») : à l'écran, **aucun `Â·` ni mojibake** — les accents s'affichent
   correctement (sans la conversion UTF-16, le `·` deviendrait `Â·`).
3. **Idempotence** : reconverger (relancer le cycle) → la skin n'est **pas
   réécrite** si elle est déjà conforme au hash attendu (pose test/apply).

### Scénario 15.6 — Watchdog : Rainmeter relancé s'il est tué (D5)

1. Rainmeter rendu. **Tuer** `Rainmeter.exe` (Gestionnaire des tâches, ou
   `taskkill /IM Rainmeter.exe /F`).
2. **Attendu** : au tick suivant de la boucle résidente du compagnon (≤ ~60 s),
   le **watchdog** relance `Rainmeter.exe` pointant la config verrouillée
   ProgramData — l'overlay réapparaît. (`companion.log` : « Watchdog Rainmeter :
   process absent → relancé ».)
3. **Borné** : tuer Rainmeter en boucle ne provoque pas de relance serrée — un
   minimum (~30 s) sépare deux tentatives. **Logoff** : le compagnon (et donc le
   watchdog) meurt avec la session (pas de session = rien à rendre) — non
   accumulation, non visible.

### Scénario 15.7 — Route /tools dédiée + golden overlay intact (NFR13)

1. `GET /api/v1/agent/tools/<filename>` (curl, token agent) : 200 binaire ;
   404 indistinct pour filename hors pattern (`sambaedu-rainmeter-…\.zip`),
   inconnu ou `agent.tools_path` illisible ; 401 sans token ; 403 quarantaine.
   **La route est SÉPARÉE de `/releases`** (réservé au binaire agent / 25.2) —
   un `sambaedu-agent-*.exe` est rejeté en 404 par `/tools` et réciproquement.
2. **Golden inchangé** : `go test ./shared/ -run
   TestComposeOverlayDocumentGoldenByteCompatible` vert — `overlay.json` reste
   **byte-identique** au format 24.6 (la composition `ComposeOverlayDocument` est
   réutilisée à l'identique côté SYSTEM ; aucun bump). Toute divergence d'un octet
   = bug, jamais un bump à acter.

## Section 16 — Drift policy PAR ASSIGNATION : le mode suit la cible (Story 27.3)

> **⚠️ SECTION ENTIÈREMENT ABROGÉE par Story 27.8.** Le mécanisme `mode`
> strict/default (introduit par 27.1, déplacé par 27.3) est **entièrement
> retiré** : la convergence est STRICT inconditionnelle, il n'y a plus de toggle,
> plus de `shortcut_assignables.mode`, plus de `drifted_allowed`. Tous les
> scénarios 16.1–16.4 ci-dessous sont **sans objet**. Conservés pour
> l'historique. Voir la Section 19 (Story 27.8).

> **Révision de 27.1.** En 27.1, le mode `strict|default` (drift policy) était posé
> sur la **règle**. 27.3 le rend **par assignation** : un même raccourci peut être
> `strict` (verrouillé) sur un parc et `default` (dérive humaine tolérée) sur un
> autre. **Asymétrie structurelle (piège central)** : seul `shortcuts` a une vraie
> table pivot N-à-M (`shortcut_assignables`) → le `mode` y est **déplacé**
> (`shortcuts.mode` → `shortcut_assignables.mode`). `wallpapers` (owner sur la
> table) et `overlay_signals` (ciblage colonne sur la table) ont « règle =
> assignation » (1 cible/règle) → le `mode` **reste sur leur table**, déjà « par
> cible » (Option A, aucun pivot créé). **Objectif clé : contrat agent + golden
> INTACTS** — le `mode` n'a JAMAIS été émis au payload v1 (résolu au compilateur),
> donc `state.v1.json`/`report.v1.json` et `FROZEN_STATE_HASH`/`frozenStateHash`
> ne bougent PAS du fait de cette story (un bump dû au mode = régression).
>
> **Actions /vm** : `migrate:status` puis `php artisan migrate --force` (2
> migrations : add `mode` sur `shortcut_assignables` + drop `mode` de `shortcuts`).
> Pas de `config:cache`/`route:cache` (aucun config/route ajouté).

### Scénario 16.1 — Un même raccourci, strict sur un parc et default sur un autre (lab Windows)

1. Côté serveur : créer un raccourci (ex. « Pronote »), l'assigner à DEUX parcs
   via la modale d'assignation — toggle « autoriser l'utilisateur à modifier »
   **décoché** (strict) pour le parc A, **coché** (default) pour le parc B.
2. Sur un poste du parc A (mode `strict`) : un prof supprime le `.lnk` Pronote →
   au passage suivant l'agent le **recrée** (la cible fait loi).
3. Sur un poste du parc B (mode `default`) : un prof supprime le `.lnk` Pronote →
   l'agent **ne le recrée pas** et rapporte `drifted_allowed` (dérive tolérée).
4. **Preuve serveur** : `shortcut_assignables.mode` = `strict` pour le lien parc A,
   `default` pour le lien parc B (le même `shortcut_id` porte deux modes).

### Scénario 16.2 — Défaut strict quand l'assignation ne déclare pas de mode

1. Assigner un raccourci sans toucher le toggle (ou via une assignation pré-27.3
   restée `mode = null`).
2. **Attendu** : le compilateur retombe sur `StateProvider::mode()` = `strict`
   (la cible fait loi). `null` sur le lien = « non déclaré », jamais un default SQL.

### Scénario 16.3 — Le toggle a quitté le formulaire de règle

1. Ouvrir l'édition d'un raccourci : **aucun** champ « Application sur le poste »
   (mode) n'est présent dans le formulaire de règle (déplacé vers l'assignation).
2. Le mode se règle **uniquement** au geste d'assignation (modale raccourcis ;
   carte wallpaper ; création overlay — pour ces deux derniers, déjà « par cible »).

### Scénario 16.4 — Golden + hash inchangés (non-régression contrat)

1. `git diff tests/Fixtures/Agent/state.v1.json report.v1.json` (hors changements
   d'autres stories) **vide** pour ce qui touche le `mode`.
2. `ContractV1Test::state_hash_is_frozen_regression_guard` VERT sans bump ; Go
   `go test ./shared/ -run TestHash` VERT (le `mode` n'a jamais fui au payload).

## Section 17 — Icône UPLOADÉE → asset statique servi par Apache (Story 27.7)

> **Le trou de parité fermé** : une icône uploadée (`windows_icon` = nom NU,
> ex. `Calculatrice`) s'affichait en « feuille blanche » côté poste car le
> provider émettait le nom nu brut comme `IconLocation`. 27.7 livre le bon
> modèle : asset content-addressed servi en STATIQUE + GET HTTP simple agent +
> IconLocation locale. À dérouler en lab Windows AVANT l'extinction legacy
> (27.6).

**Pré-requis VM (ACTION HUMAINE)** :
- Migration jouée : `php artisan migrate` (colonnes `shortcuts.icon_asset`,
  `icon_checksum`) — `migrate:status` doit montrer `add_icon_asset_to_shortcuts`
  `Ran`.
- Alias Apache appliqué : reporter le bloc `/assets/shortcut-icons` de
  `config/apache/sambaedu.conf` (ou `scripts/setupApache.sh`) dans
  `/etc/apache2/sites-enabled/sambaedu.conf` (hors-git, inotify ne le sync pas),
  puis `systemctl reload apache2`.
- Dossier servi créé + lisible Apache :
  `mkdir -p storage/app/shortcut-icons && chown -R www-admin storage/app/shortcut-icons`.
- Backfill joué : `php artisan shortcuts:backfill-icons`.

### Scénario 17.1 — Icône uploadée s'affiche réellement (plus de feuille blanche)

1. Côté serveur : uploader une icône pour un raccourci (UI raccourcis,
   `icon_file`) — ex. `Calculatrice`. Vérifier en base :
   `shortcuts.icon_asset = <sha>.ico`, `icon_checksum = <sha>` ; le `<sha>.ico`
   est présent dans `storage/app/shortcut-icons/`.
2. `GET /api/v1/agent/state?user=<login>` (poste ciblé) : l'item `shortcuts`
   porte `{icon_asset, icon_checksum}` à côté de `icon` (nom nu), **PAS** de
   champ `url`.
3. Sur le poste lab : déclencher une convergence (logon + cycle). Le `.lnk`
   s'affiche avec **son icône réelle** (plus de feuille blanche). L'icône a été
   déposée dans `C:\ProgramData\SambaEdu\Agent\icons\<sha>.ico` et
   l'`IconLocation` du `.lnk` pointe dessus.

### Scénario 17.2 — Garde-fou sécurité de l'Alias (CRITIQUE)

1. `curl <server_url>/assets/shortcut-icons/<sha>.ico` → **200** binaire (servi
   en direct, hors FPM).
2. `curl <server_url>/assets/shortcut-icons/` → **403/404**, JAMAIS un listing
   (Options `-Indexes`).
3. `curl <server_url>/assets/shortcut-icons/../keys/pki/...` (toute tentative de
   remontée) → **JAMAIS** servi : l'Alias pointe EXACTEMENT sur le sous-dossier,
   `storage/keys/pki/` (PFX code-signing + clés CA) reste inaccessible. Vérifier
   le vhost : `grep -A2 'Alias /assets/shortcut-icons'` ne contient ni
   `storage/$` ni un parent de `keys/pki`.

### Scénario 17.3 — Chemin réel inchangé (régression zéro)

1. Un raccourci à icône RÉELLE (`windows_icon = firefox.exe,0`) : l'item `state`
   porte `icon` brut, **AUCUN** `icon_asset`/`icon_checksum`. Le poste pose
   l'icône via `ParseIconLocation` (comportement 2.2.1, inchangé).

### Scénario 17.4 — Convergence gracieuse si asset indisponible

1. Référencer un `icon_asset` dont le `.ico` n'est PAS (encore) servi (ex.
   supprimer le `<sha>.ico` du dossier servi) → le download agent renvoie 404 :
   l'icône n'entre pas dans le cache local.
2. Le raccourci est quand même posé **sans IconLocation cassée** (icône défaut),
   reporté `drift`, JAMAIS une « feuille blanche » ni une erreur bloquant les
   AUTRES raccourcis. Au cycle suivant (asset re-servi) → l'icône converge.
3. Checksum KO (servir un contenu divergent du `<sha>` attendu) : l'agent
   **rejette AVANT écriture**, retry au cycle suivant — un contenu corrompu
   n'entre jamais dans le cache.

### Scénario 17.5 — Backfill name→content-addressed

1. Des icônes legacy `/etc/sambaedu/applications/shortcuts/<name>.ico` existent,
   référencées par des raccourcis `windows_icon = <name>` SANS `icon_asset`.
2. `php artisan shortcuts:backfill-icons` → résumé `{assets, linked, missing}` ;
   chaque `<name>.ico` est COPIÉ en `<sha>.ico` (legacy jamais supprimé), les
   colonnes renseignées. Un raccourci dont le `.ico` legacy est absent →
   `missing`, jamais d'échec.
3. Re-run **idempotent** : `linked` identique, `assets` dédupliqué par checksum,
   aucun fichier servi en double.

### Scénario 17.6 — Transport statique vs token'd + golden bumpé sciemment

1. Le download icône est un **GET HTTP simple** (le serveur statique répond
   même sans `Authorization`) — distinct du canal wallpaper token'd
   (`AssetController`, inchangé).
2. `ContractV1Test::state_hash_is_frozen_regression_guard` VERT avec le hash
   bumpé `a43e8aad…` ; Go `go test ./shared/ -run TestHash` VERT à la MÊME
   valeur (test croisé NFR13). Le bump (payload `shortcuts` +
   `{icon_asset, icon_checksum}`) est documenté = évolution mineure §9.

## Section 18 — Catalogue de tools : portable Rainmeter uploadé + skin servie + toggle (Story 25.6)

> **Le trou de parité fermé** : 27.1bis livrait le portable Rainmeter déposé
> MANUELLEMENT sur la VM (hash figé en dur dans le binaire Go) et la skin
> EMBARQUÉE par `go:embed` (recompilation à chaque retouche). 25.6 fait du
> portable ET de la skin des **assets gérés serveur, toggleables depuis l'UI** :
> upload validé (SHA-256 calculé serveur), manifest dédié, serving authentifié
> de la skin, embed retiré. **Le rendu verrouillé (ACL/watchdog/overlay.json
> SYSTEM) et le golden overlay sont INTOUCHÉS.**

**Pré-requis VM (ACTION HUMAINE)** :
- Migration jouée : `php artisan migrate` (table `agent_tools`) —
  `migrate:status` doit montrer `create_agent_tools_table` `Ran`.
- Clés config appliquées : `php artisan config:cache` + chown www-admin (clés
  `agent.tool_max_upload_bytes`, `agent.overlay_skin_path`).
- Routes neuves : `php artisan route:cache` (`agent.v1.tools.manifest`,
  `agent.v1.tools.skin`).
- Skin canonique provisionnée sous `storage/assets/overlay/rainmeter/SambaEduOverlay.ini`
  (copie idempotente depuis `resources/overlay/...` au 1er serving ;
  **`chown www-admin storage/assets/overlay/rainmeter`** sinon `hash_file()` →
  false → 404 silencieux).
- Dossier outils lisible/écrivable www-admin : `storage/agent/tools/`.

### Scénario 18.1 — Upload portable : validation structure ZIP + SHA-256 serveur

1. UI `parc-settings/agent/` → section « Outils du parc ». Importer l'archive
   `.zip` du portable Rainmeter (version `4.5.18`). En base : `agent_tools` a une
   ligne `key=rainmeter`, `filename=sambaedu-rainmeter-4.5.18.zip`,
   `sha256 = hash_file('sha256')` du fichier stocké (jamais un hash client),
   `enabled=false` (premier upload désactivé par défaut).
2. **Refus** (chacun → `toastError`, jamais 500, ZÉRO ligne écrite, aucun
   orphelin) : extension non-`.zip` ; MIME hostile ; archive > borne config ;
   ZIP SANS `Rainmeter.exe` à la racine ; ZIP SANS dossier `Skins/` ; ZIP
   corrompu ; version malformée (`../evil`).
3. **Anti-traversal** : un nom de fichier client hostile (`../../etc/passwd.zip`)
   est IGNORÉ — le filename est DÉRIVÉ serveur de la version
   (`sambaedu-rainmeter-<version>.zip`, matchant la regex `ToolController`).

### Scénario 18.2 — Toggle on/off → déploiement / no-op sans désinstaller (D3, D4)

1. Activer le toggle → `agent_tools.enabled=true`. `GET /api/v1/agent/tools-manifest`
   (poste enrôlé) expose `tool: {key, filename, sha256, size}`. Sur le poste
   lab : prochain check-in → l'agent télécharge le portable (route `/tools/{filename}`),
   vérifie le SHA-256 = `tool.sha256` AVANT extraction, pose Rainmeter, overlay
   rendu au logon.
2. Désactiver le toggle → `enabled=false`. Le manifest renvoie `tool: null`.
   Sur le poste : l'agent **ne (re)provisionne plus** (no-op gracieux), MAIS
   **Rainmeter déjà posé reste en place** (D4 — pas de désinstallation).
3. Aucun tool dans le catalogue → `tool: null` → no-op (jamais d'`error` du seul
   fait de l'absence ; Rainmeter absent reste gracieux, invariant 24.4/24.6).

### Scénario 18.3 — Skin SERVIE authentifiée, embed retiré (Volet A, D1, D7)

1. `GET /api/v1/agent/overlay-skin` SANS `Authorization` → **401**. Avec token
   d'un poste en quarantaine → **403**. Avec token valide → **200** binaire
   (`BinaryFileResponse`), PAS d'alias Apache public (la skin n'est pas
   client-facing comme SYSVOL).
2. **Intégrité** : le SHA-256 servi = le `skin.sha256` du manifest = le hash de
   la canonique `resources/overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini`
   (le provisioner réaligne la cible servie sur la canonique — autorité).
3. Sur le poste : l'agent télécharge la skin, vérifie le SHA-256 AVANT écriture,
   la convertit **UTF-16 LE + BOM** (`ToUTF16LEWithBOM` inchangé) et la pose ;
   accents corrects (pas de `Â·`). Hash divergent → rejetée AVANT écriture,
   retry au cycle suivant — jamais une skin corrompue posée.
4. **Embed retiré** : `agent/shared/rainmeter_embed.go`, `embedded/`,
   `rainmeter_embed_test.go` SUPPRIMÉS ; `grep -rn 'go:embed embedded/\|RainmeterSkinSource'`
   sur `agent/` = VIDE (hors commentaires) ; `GOOS=windows go build ./...` VERT.
5. **Sans recompilation** : retoucher la skin canonique → re-provisionnée au
   prochain serving SANS rebuild de l'agent (c'est le cœur de la story).

### Scénario 18.4 — Golden overlay INCHANGÉ + manifest hors items desired-state (D8b)

1. Le manifest tool/skin est un **endpoint DÉDIÉ** (iso `release-manifest` 25.1),
   PAS un item desired-state : `agent/shared/testdata/overlay.golden.json` reste
   **byte-identique** (aucun bump) ; `ContractV1Test` VERT à la même valeur.
2. `go test ./shared/ -run 'Rainmeter|ParseRainmeter'` VERT (provisioning piloté
   par manifest, vérif hash avant extraction/écriture, no-op gracieux, parsing
   strict du filename/hash).

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
- [x] 4.4 — ~~mode default : fond changé à la main → drifted_allowed NON réappliqué~~ **ABROGÉ par Story 27.8** (mode retiré — STRICT inconditionnel ; voir Section 19)
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
- [x] 6.6 — ~~mode default Go : dérive humaine → drifted_allowed non réappliqué~~ **ABROGÉ par Story 27.8** (mode retiré — STRICT inconditionnel ; voir Section 19)
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
- [x] 13.5 — ~~toggle strict/default : strict recrée, souple → `drifted_allowed` non recréé~~ **ABROGÉ par Story 27.8** (toggle retiré — STRICT inconditionnel ; voir Section 19)
- [ ] 13.6 — lecture seule + zéro AD (grep vide ; ciblage AD-CN seul = aucun item)
- [ ] 13.7 — golden v1 + hash figé bumpé sciemment ; `go test` croisé serveur/agent vert (NFR13)
- [ ] 15.1 — portable posé au bootstrap (hash vérifié avant extraction), idempotent ; hash KO rejeté ; 404/constante vide = gracieux
- [ ] 15.2 — ACL config Rainmeter Users:R / SYSTEM+Admins full ; non-admin ne peut pas modifier Rainmeter.ini
- [ ] 15.3 — overlay.json écrit par SYSTEM au logon, ACL `<SID>:R`, non éditable par l'user ; overlay sorti de la map compagnon (D1) ; multi-session per-user
- [ ] 15.4 — rendu verrouillé : non déplaçable / ClickThrough / épinglé / pas de tray ; process visible (pas d'obfuscation D7)
- [ ] 15.5 — skin UTF-16 LE + BOM (FF FE), accents corrects (pas de `Â·`), pose idempotente
- [ ] 15.6 — watchdog : kill Rainmeter → relancé ≤ ~60 s, borné (~30 s), meurt au logoff
- [ ] 15.7 — route /tools dédiée (séparée de /releases) ; golden overlay byte-identique (aucun bump, NFR13)
- [ ] 18.1 — upload portable : SHA-256 calculé serveur, structure ZIP (`Rainmeter.exe` + `Skins/`) ; refus extension/MIME/taille/structure/version → toastError zéro ligne ; filename dérivé serveur (anti-traversal)
- [ ] 18.2 — toggle ON → manifest `tool` actif, agent déploie (hash vérifié avant extraction) ; toggle OFF → `tool: null`, no-op SANS désinstaller (D4) ; absent → gracieux
- [ ] 18.3 — skin servie 401/403/200, intégrité SHA-256 = canonique ; agent télécharge + UTF-16 LE+BOM (pas de `Â·`), hash KO rejeté ; embed retiré (grep vide, build windows vert) ; retouche skin sans rebuild
- [ ] 18.4 — manifest dédié hors items desired-state ; golden overlay byte-identique (aucun bump) ; `go test` Rainmeter croisé vert

## Section 19 — Retrait du mode strict/default : STRICT partout (Story 27.8)

> **Nature de la story.** Aucune ressource ajoutée : **DÉMONTAGE** du mécanisme
> `mode ∈ {strict, default}` de la drift policy (introduit par 27.1, déplacé par
> 27.3). La review 27.3 (Q1/#6) a établi que le grain réel du mode était
> `type × poste`, pas `item × cible` — promesse « par assignation » creuse au
> niveau agent. Henri a tranché : **STRICT inconditionnel partout**. Cette
> section ABROGE les scénarios 4.4, 6.6, 13.5 et toute la Section 16.
>
> Schéma → providers → compilateur → UI → AGENT Go → contrat/golden : item du
> contrat 5 clés → **4** (`type`, `semantics`, `payload`, `hash`), statut
> `drifted_allowed` **retiré** (3 statuts), `FROZEN_STATE_HASH` PHP + Go
> **bumpés croisés** (`4d0c2c94…`), colonnes `mode` des 3 tables **droppées**,
> enum `StateMode` + `StateCandidate::$mode` + `StateProvider::mode()` +
> `StateCompiler::aggregateMode()` **supprimés**, 3 toggles UI **retirés**.

### Scénario 19.1 — Convergence STRICT partout : la suppression humaine est TOUJOURS corrigée (lab Windows — ACTION HUMAINE Henri)

1. Sur un poste enrôlé, pour **chaque** type géré (wallpaper, raccourci,
   overlay) : laisser converger (`compliant` en base), puis **modifier/supprimer
   à la main** la ressource gérée dans la session (changer le fond, supprimer un
   `.lnk` géré, etc.).
2. Attendre le re-test périodique du compagnon (≤ 5 min) ou re-logon.
3. **Attendu** : la cible est **réappliquée** à CHAQUE fois — le fond revient, le
   `.lnk` est recréé — sans exception. Le rapport (`POST /report`) porte
   `drift` (jamais `drifted_allowed` — ce statut n'existe plus). C'est le
   comportement UNIQUE (l'ancien « strict », rendu inconditionnel).
4. **Contre-épreuve** : aucun toggle « Souple/Strict » n'est présent dans l'UI
   (modale d'assignation raccourcis, carte wallpaper, création overlay).

### Scénario 19.2 — Le contrat a bougé : item à 4 clés, report à 3 statuts (curl + base)

1. `GET /api/v1/agent/state` (poste enrôlé) → **attendu** chaque item porte
   **exactement** 4 clés `{type, semantics, payload, hash}` — **aucune** clé
   `mode`.
2. Vérifier que `tests/Fixtures/Agent/state.v1.json` (golden) hash d'état =
   `4d0c2c9406c448c8febb05807f33bb8c53af17aec0c9051ca7a4d4fddbf93579` (figé,
   identique PHP `ContractV1Test::FROZEN_STATE_HASH` et Go
   `hasher_test.go::frozenStateHash` — test croisé NFR13).
3. `POST /api/v1/agent/report` avec un item `status: "drifted_allowed"` →
   **attendu** rejet validation (422), le statut n'existe plus
   (`AgentResourceStatus` = `compliant` | `drift` | `error`).
4. Page parc : plus de compteur ni de filtre « Dérive tolérée » ; le badge de
   conformité n'a plus l'état `drifted_allowed`.

### Scénario 19.3 — Schéma : colonnes `mode` droppées, réversibles (action `/vm`)

1. `php artisan migrate:status` → 3 migrations `2026_06_16_11020x_drop_mode_from_*`
   Pending.
2. `php artisan migrate --force` → **attendu** les colonnes `mode` de
   `shortcut_assignables`, `wallpapers`, `overlay_signals` sont **droppées** ;
   `shortcuts.mode` **non touchée** (déjà droppée par 27.3).
3. Idempotence : re-jouer `migrate` (ou `migrate:rollback` puis `migrate`) ne
   casse rien (`Schema::hasColumn` en garde des deux côtés ; `down()` RE-CRÉE la
   colonne nullable).

## Section 20 — Réveil de l'agent au logon : cycle desired-state (Story 27.9)

> **Nature de la story.** RUNTIME/boucle agent, **PAS** un handler de ressource :
> aucun contrat v1, aucun golden, aucun `FROZEN_STATE_HASH`, aucune migration,
> aucune route, **zéro impact serveur SE5**. L'agent Go réutilise le hook SCM
> **existant** `WTS_SESSION_LOGON` (déjà abonné `AcceptSessionChange` pour
> `overlay.json`, Story 27.1bis) pour **réveiller la boucle de convergence** : au
> logon, un **cycle complet** (`RunCycle` : `/state` + portée session + assets +
> icônes + Rainmeter + self-update + `/report`) part **immédiatement** au lieu
> d'attendre le prochain tick de polling (jusqu'à ~1 h, voire 24 h de TTL
> serveur). Mécanisme = canal `wake` bufferisé 1 + `RequestWake()` non-bloquant
> (coalescé) ; garde-fou anti-martèlement = **debounce min-interval (60 s)** côté
> boucle. Le réveil **s'ajoute** à l'écriture overlay 27.1bis, il ne la remplace
> pas.
>
> **Périmètre testé en automatique (hôte Linux)** : toute la logique (réveil,
> debounce, coalescence, nil-safe, non-régression `ctx`) vit dans `agent/shared`
> et est couverte par `go test ./shared/...` (6 nouveaux tests). Le câblage
> `service_windows.go` (`//go:build windows`) est couvert par cross-compile +
> `go vet`. **Les scénarios ci-dessous valident le comportement OBSERVABLE sur un
> poste Windows réel — ce sont des ACTIONS HUMAINES (Henri), post-merge** : ils
> ne sont pas automatisables dans le worktree (zéro interaction VM, aucun poste).

### Scénario 20.1 — Logon pendant la sieste → cycle frais observé dans les logs (lab Windows — ACTION HUMAINE Henri)

1. Sur un poste enrôlé, service agent installé et démarré. Attendre que le **1er
   cycle de boot** soit passé (log `POST /report -> 200 : rapport accepté…`) — la
   boucle est désormais en **sieste nominale** (jusqu'à `ttl_seconds` serveur, p.
   ex. ~1 h).
2. **Sans redémarrer le service**, ouvrir une **session interactive** (logon
   Windows d'un utilisateur du domaine) plus de 60 s après le dernier cycle.
3. **Attendu** dans `C:\ProgramData\SambaEdu\Agent\logs\…` (quasi immédiatement,
   pas après l'heure de sieste) :
   - une ligne `Réveil au logon : cycle de convergence frais lancé (… s depuis le
     dernier cycle).` ;
   - puis le cycle complet : `GET /state -> 200/304…`, fetch session, sync
     assets/icônes/Rainmeter, self-update, `POST /report -> 200…`.
4. **Effet métier** : raccourcis / lecteurs / imprimantes / wallpaper / overlay
   convergent **dès l'ouverture de session**, sans le « il ne se passe rien
   pendant un moment » au logon.

### Scénario 20.2 — Debounce : logons rapprochés ⇒ AU PLUS un cycle dans la fenêtre min-interval (lab Windows — ACTION HUMAINE Henri)

1. Provoquer un cycle (logon ou boot), noter l'horodatage du cycle dans les logs.
2. **Dans les 60 s** qui suivent ce cycle, déclencher **plusieurs logons
   rapprochés** (fast user switching, ouverture/fermeture rapide, sessions RDP
   multiples qui claquent).
3. **Attendu** : **aucun** nouveau cycle complet ne part immédiatement à chaque
   logon. Les logs montrent au plus une ligne de coalescence
   (`Réveil au logon … coalescé (debounce)…` / `… ignoré (debounce)…`), puis **au
   plus UN** cycle frais à l'expiration du min-interval (ou au tick nominal si
   celui-ci arrive avant). **Jamais** de rafale de cycles (`POST /report`) qui
   martèlerait le serveur.
4. **Contre-épreuve réseau** : le backoff exponentiel n'est **pas** réinitialisé
   par un réveil — si le serveur était injoignable, un logon n'efface pas le
   garde-fou anti-martèlement (FR22), il ne fait qu'écourter la sieste sous
   debounce.

### Scénario 20.3 — Non-régression overlay 27.1bis : `overlay.json` toujours réécrit au logon (lab Windows — ACTION HUMAINE Henri)

1. Au logon (Scénario 20.1), vérifier que `overlay.json` de la session
   (`…\<profil utilisateur>\…\SambaEdu\Agent\overlay.json`, possédé SYSTEM, ACL
   `<SID>:R`) est **réécrit** comme avant 27.9 — l'overlay Rainmeter verrouillé
   s'affiche.
2. **Attendu** : le réveil de la boucle **s'ajoute** à l'écriture overlay, il ne
   la remplace ni ne l'ordonne. Les deux sont best-effort **indépendants** : si la
   composition overlay panique (rattrapée par le `recover()` existant, log
   `Écriture overlay au logon en échec (panique rattrapée)…`), le **réveil de la
   boucle a quand même lieu** — et inversement.
3. **Contre-épreuve** : aucune régression du comportement overlay logon-only
   (pas de réécriture sur logoff/lock/unlock).

### Scénario 20.4 — Aucun logon : la cadence nominale est strictement inchangée (lab Windows — ACTION HUMAINE Henri)

1. Laisser le poste **sans ouverture de session** (ou console verrouillée sans
   nouveau logon) pendant plusieurs cadences.
2. **Attendu** : le ticker / jitter ±10 % / TTL serveur / backoff exponentiel se
   comportent **exactement** comme avant 27.9 (cadence nominale, clamps
   `[60 s, 86400 s]`, sieste, sortie propre sur stop SCM / 401 `OutcomeStop`). Le
   réveil au logon n'altère **aucun** calcul de cadence quand il n'y a pas de
   logon.
3. **Console de debug / plateforme sans sessions** : le mécanisme est **inerte**
   (canal nil, no-op) — aucun panic, aucune dépendance Windows tirée dans
   `agent/shared`.

### Post-correctifs & non-régressions (Section 20)

- **Send non-bloquant obligatoire** : `RequestWake()` poste en `select … default`
  sur un canal bufferisé 1 → ne **gèle jamais** le thread de contrôle du service
  (`Execute`), même boucle occupée (cycle en vol, HTTP lent) ou réveil déjà en
  attente. Un send bloquant aurait rendu le service insensible aux
  `Stop`/`Interrogate`.
- **Canal initialisé à la construction** (`newAgent` → `InitWake()`), **avant**
  `go agent.Run(ctx)` : jamais de `RequestWake` sur un canal nil au moment du
  premier logon (qui bloquerait pour toujours). `RequestWake()` reste nil-safe par
  défense.
- **Debounce côté boucle (thread unique)** : la fenêtre min-interval se mesure
  dans `Run` (propriétaire de `lastCycleStart`), pas côté SCM → aucune course sur
  un état partagé. Le SCM ne fait que poster un signal ; la boucle décide.

### Checklist rapide (Section 20)

- [ ] 20.1 — Logon pendant la sieste : log `Réveil au logon : cycle … frais lancé`
      + cycle complet immédiat (pas après l'heure de sieste).
- [ ] 20.2 — Logons rapprochés (< 60 s) : au plus UN cycle dans la fenêtre,
      lignes de coalescence dans les logs, pas de rafale `POST /report`.
- [ ] 20.3 — `overlay.json` toujours réécrit au logon (27.1bis non régressé,
      overlay et réveil indépendants).
- [ ] 20.4 — Sans logon : cadence/jitter/TTL/backoff strictement inchangés ;
      mécanisme inerte sur console de debug.

## Section 21 — Rainmeter MODE INSTALLÉ : settings per-user writable (Story 27.1ter)

Le verrouillage « Rainmeter.ini read-only sous ProgramData » de 27.1bis cassait
l'e2e sur un **user standard non-admin** : modales « Rainmeter.ini is not
writable » + « Safe Start » (Rainmeter ne peut écrire ses settings / son marqueur
d'arrêt dans un `.ini` RX). 27.1ter passe Rainmeter en **mode installé** : les
settings (`Rainmeter.ini`) partent en `%APPDATA%\Rainmeter\` (writable, écrit par
le compagnon en droits user) ; les **skins restent verrouillées** RX en
ProgramData, pointées par `SkinPath`.

### Scénario 21.1 — Logon user standard non-admin : AUCUNE modale, skin chargée (lab Windows — ACTION HUMAINE Henri)

1. Poste avec l'agent 2.2.9+ provisionné (service SYSTEM : portable Rainmeter
   posé, skin verrouillée, AUCUN `Rainmeter.ini` sous `C:\ProgramData\SambaEdu\Rainmeter\`).
2. Ouvrir une session avec un compte **élève non-admin** (pas Administrator).
3. **Attendu** :
   - **AUCUNE modale** Rainmeter — ni « Rainmeter.ini is not writable. Settings
     will not be saved », ni « Rainmeter Safe Start » proposant de charger les
     skins par défaut.
   - L'overlay affiche bien la skin **SambaEduOverlay** (PAS les défauts illustro
     de Rainmeter).
   - `%APPDATA%\Rainmeter\Rainmeter.ini` **présent** (écrit par le compagnon au
     logon) et **writable** (l'user en est propriétaire — `icacls` montre un droit
     d'écriture, aucune ACL read-only posée par nous).
   - `C:\ProgramData\SambaEdu\Rainmeter\` ne contient **aucun** `Rainmeter.ini`
     (mode installé garanti) ; l'arbre reste RX (skins lisibles, non modifiables).
4. **Comparaison admin/élève** : le bug 27.1bis ne touchait que l'élève (admin
   avait `Administrators:F`). Vérifier que **l'élève** ne voit plus rien.

### Scénario 21.2 — Idempotence + réimposition du durci (lab Windows — ACTION HUMAINE Henri)

1. En session élève, ouvrir `%APPDATA%\Rainmeter\Rainmeter.ini` et le modifier
   (ou le supprimer).
2. Fermer/rouvrir la session (ou laisser le compagnon redémarrer).
3. **Attendu** : au logon suivant, le compagnon **réimpose** le `.ini` durci
   (TrayIcon=0, SkinPath, section `[SambaEduOverlay]` verrouillée) — écriture
   idempotente (réécrit seulement si absent ou divergent). L'élève peut au pire
   masquer l'overlay pour sa session courante (récupérable), jamais afficher de
   fausse donnée (`overlay.json` reste SYSTEM read-only).

### Scénario 21.3 — Non-régression `overlay.json` SYSTEM (NFR5) (lab Windows — ACTION HUMAINE Henri)

1. **Attendu** : `overlay.json` reste composé ET écrit par le **SERVICE SYSTEM**
   au logon, ACL `<SID>:R` (read-only pour l'user). La donnée affichée n'est
   jamais falsifiable par l'élève — seul le `Rainmeter.ini` de présentation est
   writable. Vérifier qu'un élève ne peut pas écrire `overlay.json`.

### Post-correctifs & non-régressions (Section 21)

- **Mode portable vs installé = présence d'un `Rainmeter.ini` à côté de
  `Rainmeter.exe`.** Le provisioning SYSTEM **supprime** désormais tout
  `Rainmeter.ini` résiduel de l'arbre ProgramData (celui embarqué par le zip
  portable + ancien durci d'une install 27.1bis) — sinon Rainmeter repasserait en
  mode portable et les modales reviendraient. Suppression idempotente
  (`os.Remove` ignorant `ErrNotExist`).
- **Ordre d'écriture** : le compagnon écrit `%APPDATA%\Rainmeter\Rainmeter.ini`
  **AVANT** le `Watchdog.Tick()` anticipé (levier A) — sinon Rainmeter lirait un
  `.ini` absent au 1er lancement (Safe Start). Échec d'écriture = **gracieux**
  (log warning, le watchdog lance quand même — NFR1).
- **Skins inchangées** RX en ProgramData, pointées par
  `SkinPath=C:\ProgramData\SambaEdu\Rainmeter\Skins\` dans `[Rainmeter]`.

### Checklist rapide (Section 21)

- [ ] 21.1 — Logon élève non-admin : aucune modale (not-writable / Safe Start),
      skin SambaEduOverlay chargée, `%APPDATA%\Rainmeter\Rainmeter.ini` présent et
      writable, aucun `.ini` sous ProgramData.
- [ ] 21.2 — `.ini` per-user édité/supprimé → réimposé au logon (idempotent).
- [ ] 21.3 — `overlay.json` reste SYSTEM read-only (NFR5 non régressé).

## Section 22 — Préchargement de l'identité MACHINE de l'overlay : salle en portée machine (Story 27.10)

**Intention.** Au logon, l'overlay tardait car poste/salle/login venaient
ENTIÈREMENT du fetch per-user (`GET /state?user=`), qui peut tarder ou échouer
en tout début de session. Deux champs sont STABLES par poste : le nom
(`machine.name`, déjà local via `COMPUTERNAME`) et la **salle** (`machine.room`).
La story 27.10 bascule la salle de la portée **session** (ancien item `identity`)
vers la portée **machine** (cache persistant `cache/state.json`, rempli par le
cycle service + réveil-logon 27.9). L'agent compose alors **poste + salle dès le
logon** depuis le cache machine, sans attendre le fetch per-user ;
`identity{login, fullname}` se remplit ensuite avec le cache session per-SID.

**Mécanique.**
- Serveur : nouvel `OverlayMachineStateProvider` (`scope()==Machine`) émet
  `{kind:"machine", room}` (room = `workstation.physicalRooms[0].name`, null →
  vide), **même en machine-only** (`GET /state` sans user). `OverlayStateProvider`
  (identity, session) ne porte plus que `{kind, login, fullname}` — `room` retiré
  (source UNIQUE = machine, D1).
- Agent : `ComposeOverlayDocument` extrait `room` de l'item `kind:"machine"` et
  `login`/`fullname` de `kind:"identity"` ; `machine.name` reste local.
  `OverlayDocumentForSession` lit le cache MACHINE **ET** le cache session per-SID.
  Byte-format d'`overlay.json` INCHANGÉ.
- Contrat : item overlay machine-scope ajouté au golden `state.v1.json`, item
  identity session sans `room` (6 items) ; 2 hashes figés croisés bumpés à
  l'identique (`8174042c…`). Version agent → 2.2.10.

### Scénario 22.1 — Préchargement : poste + salle affichés au logon même si le per-user tarde (lab Windows — ACTION HUMAINE Henri)

1. Cache machine frais (la salle est connue), provoquer un logon élève alors que
   le serveur **tarde/échoue** sur `GET /state?user=` (cache session absent/périmé).
2. Attendu : `overlay.json` (écrit SYSTEM) porte `machine.name` (local) **et**
   `machine.room` (depuis le cache machine persistant) renseignés DÈS le logon ;
   `identity.login`/`identity.fullname` sont VIDES (clés présentes, valeurs `""`).
3. Quand le fetch per-user aboutit (prochain compose), `identity.login/fullname`
   se remplissent ; `machine.room` reste identique (la salle ne change pas).

### Scénario 22.2 — La salle survit au reboot sans session (cache machine persistant)

1. Reboot du poste, AUCUN logon encore. Le cycle service (boot/réveil) peuple le
   cache machine.
2. Attendu : au premier logon, la salle est déjà présente côté cache machine →
   `machine.room` composé sans aucun aller-retour per-user.

### Scénario 22.3 — Non-régression byte-format + NFR5 + partition des portées

1. `overlay.json` reste byte-compatible (sérialiseur figé, `": "` simple, UTF-8
   brut, pas de `\n` final) — golden Go inchangé.
2. `overlay.json` reste écrit par SYSTEM, ACL `<SID>:R` (NFR5) — l'élève lit, ne
   falsifie pas.
3. Le COMPAGNON (droits user) ne lit JAMAIS la portée machine : seul le compose
   au logon (SYSTEM) lit les DEUX caches. Aucune fuite de la portée machine vers
   le compagnon.

### Checklist rapide (Section 22)

- [ ] 22.1 — Logon avec per-user lent/KO → poste + salle affichés immédiatement,
      login/fullname vides puis remplis au fetch suivant.
- [ ] 22.2 — Salle présente au 1er logon post-reboot sans dépendance per-user.
- [ ] 22.3 — `overlay.json` byte-format + SYSTEM read-only (NFR5) + partition des
      portées intacts.

## Story 27.3 — Réglages registre par parc (catalogue)

Le canal agent gagne le type `registry` : un **catalogue** de réglages de
registre Windows prédéterminés, activables **par parc** dans l'onglet
« Réglages registre » de la page d'un WorkstationGroup
(`/app/parc/groups/{id}?tab=registry` — gate `app.customize`), appliqués et
réimposés par l'agent (successeur natif du canal Registry.pol/GPO). DEUX
providers serveur (HKLM →
service SYSTEM, HKCU → compagnon), UN handler Go générique. Exclusive PAR
IDENTITÉ DE CLÉ avec précédence **logique > physique** (D-Q3, inversion globale).

**Catalogue initial (3 réglages, vérifiables en `regedit`) :**

| Libellé UI | hive | clé | valeur cible |
|---|---|---|---|
| Afficher les extensions de fichiers | HKCU | `Software\Microsoft\Windows\CurrentVersion\Explorer\Advanced` \ `HideFileExt` | `REG_DWORD` = `0` |
| Afficher les fichiers cachés | HKCU | `Software\Microsoft\Windows\CurrentVersion\Explorer\Advanced` \ `Hidden` | `REG_DWORD` = `1` |
| Désactiver l'UAC | HKLM | `SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System` \ `EnableLUA` | `REG_DWORD` = `0` |

### Scénario 27.3.1 — Réglage HKLM appliqué par parc (SYSTEM) (lab Windows — ACTION HUMAINE Henri)

1. Activer « Désactiver l'UAC » (HKLM) sur le parc d'un poste, dans l'onglet
   « Réglages registre » de la page du parc (`parc/groups/{id}?tab=registry`).
2. Attendre un cycle agent (ou forcer la synchro).
3. **Attendu** : `regedit` → `HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System`
   `EnableLUA = 0`. La convergence machine est faite par le **service SYSTEM**
   (log service : « Convergence machine terminée »).

### Scénario 27.3.2 — Réglage HKCU appliqué au logon (compagnon) (lab Windows — ACTION HUMAINE Henri)

1. Activer « Afficher les extensions de fichiers » (HKCU) sur le parc.
2. Ouvrir une session sur un poste du parc.
3. **Attendu** : `regedit` → `HKCU\…\Explorer\Advanced` `HideFileExt = 0` ;
   l'Explorateur affiche les extensions. Appliqué par le **compagnon** (droits
   user, ruche de la session).

### Scénario 27.3.3 — Drift STRICT : une valeur modifiée à la main est réimposée (lab Windows — ACTION HUMAINE Henri)

1. Avec un réglage actif sur le parc, modifier la valeur à la main dans `regedit`
   (ex. remettre `HideFileExt = 1`).
2. Attendre un cycle (ou logon pour HKCU).
3. **Attendu** : la valeur est **réimposée** à la cible (statut `drift` au rapport
   puis convergence). La dérive humaine est toujours corrigée (STRICT 27.8).

### Scénario 27.3.4 — Exclusive par clé entre parcs, logique > physique (lab Windows — ACTION HUMAINE Henri)

1. Assigner la MÊME clé à deux mailles d'un poste avec des valeurs différentes :
   une sur la **salle physique**, une sur le **parc logique**.
2. **Attendu** : le poste reçoit la valeur de la maille la plus spécifique —
   **le parc LOGIQUE l'emporte sur la salle PHYSIQUE** (D-Q3). Les clés
   DISTINCTES s'accumulent toutes (aucune ne se perd).

### Scénario 27.3.5 — Désactiver = cesser de gérer (lab Windows — ACTION HUMAINE Henri)

1. Retirer un réglage actif d'un parc (toggle off).
2. **Attendu** : l'agent **ne touche plus** la clé. La valeur déjà appliquée
   **reste en place** (PAS de retour automatique à la valeur Windows d'origine).
   C'est une limite connue (pas de reset OFF explicite en v1).

### Post-correctifs & non-régressions (Story 27.3)

- **UI = onglet de la page d'un WorkstationGroup** (correctif post-livraison) : le
  réglage registre s'appliquant PAR groupe, le geste vit dans un onglet « Réglages
  registre » de `parc/groups/{id}` (composant Livewire
  `pages::parc.groups._partials.registry-tab`, monté `:group-id`, gate `app.customize`),
  PAS dans une page parc-settings globale à sélecteur de parc (page standalone +
  route `parc-settings.registry-settings` supprimées). L'onglet n'apparaît que pour
  `app.customize`.
- **Inversion D-Q3 GLOBALE** : `logique > physique` touche AUSSI le défaut
  `printers` (27.2) et l'exclusivité `wallpaper`. Les tests 27.1/27.2 dépendants
  ont été MIS À JOUR (pas non-régressés) : `default_logical_wins_over_physical`,
  chaîne de spécificité.
- **Rapport unique-type** : `registry` arrive de DEUX portées (HKLM machine +
  HKCU session). L'agent fusionne par type (pire statut gagne) avant le report —
  sinon l'ingestion serveur (`updateOrCreate` sur `(workstation, type)`) en
  écraserait un.
- **Invariant central** : `curl GET /state` ne doit JAMAIS faire apparaître un
  `setting_id`/`key` de catalogue dans un payload `registry` (uniquement
  `{hive, path, name, type, value}`).

### Checklist rapide (Story 27.3)

- [ ] 27.3.1 — HKLM (UAC) appliqué par le service SYSTEM (`EnableLUA=0` en regedit).
- [ ] 27.3.2 — HKCU (extensions) appliqué au logon par le compagnon.
- [ ] 27.3.3 — Valeur modifiée à la main → réimposée (drift STRICT).
- [ ] 27.3.4 — Même clé sur 2 parcs → valeur de la maille la plus spécifique
      (logique > physique) ; clés distinctes toutes présentes.
- [ ] 27.3.5 — Désactiver un réglage = la clé garde sa valeur (pas de reset OFF).
- [ ] 27.3.6 — Payload `registry` concret, jamais d'id de catalogue (`curl /state`).

## Story 27.3bis — Associations par défaut (UserChoice)

Le canal agent gagne le type `associations` : un **catalogue** d'associations de
fichiers/protocoles par défaut (`.pdf` → Acrobat, `http` → Firefox), activables
**par parc** dans l'onglet « Associations » de la page d'un WorkstationGroup
(`/app/parc/groups/{id}?tab=associations` — gate `app.customize`), appliquées et
réimposées par l'agent **au logon** (HKCU UserChoice, par le **compagnon**). Successeur natif
du volet poste `associations.ps1`/`SFTA.ps1` (canal legacy `associations_out.php`
intouché, meurt en 27.6). Exclusive **PAR IDENTIFIANT**, précédence
**logique > physique** (D-Q3). **Cœur de risque** : le hash anti-tamper UserChoice
(MD5 UTF-16LE + dérivation à constantes), calculé **100 % côté agent** (jamais au
payload — dépend du SID/temps/GUID du poste) et **verrouillé par tests vectoriels**.

**Catalogue initial reproduit du legacy (baseline figée, parse `default.xml` si présent VM) :**

| Libellé UI | identifier | type | ProgId cible |
|---|---|---|---|
| Pages HTML → Firefox | `.html` | file | `FirefoxHTML` |
| Pages HTM → Firefox | `.htm` | file | `FirefoxHTML` |
| Protocole HTTP → Firefox | `http` | protocol | `FirefoxURL` |
| Protocole HTTPS → Firefox | `https` | protocol | `FirefoxURL` |
| Images JPG → Visionneuse | `.jpg` | file | `WindowsPhotoViewer` |

### Scénario 27.3bis.1 — Association appliquée par parc au logon (compagnon) (lab Windows — ACTION HUMAINE Henri)

1. Activer une association (ex. « Pages HTML → Firefox ») sur le parc d'un poste,
   dans l'onglet « Associations » de la page du parc
   (`parc/groups/{id}?tab=associations`). S'assurer que Firefox (ProgId
   `FirefoxHTML`) est installé sur le poste.
2. Ouvrir une session sur un poste du parc.
3. **Attendu** : `regedit` →
   `HKCU\Software\Microsoft\Windows\CurrentVersion\Explorer\FileExts\.html\UserChoice`
   porte `ProgId = FirefoxHTML` **et** un `Hash` valide ; double-cliquer un `.html`
   ouvre Firefox (Windows n'a PAS réinitialisé l'association → preuve que le hash
   est bon). Appliqué par le **compagnon** (droits user, ruche de session).

### Scénario 27.3bis.2 — Hash UserChoice réimposé au drift STRICT (lab Windows — ACTION HUMAINE Henri)

1. Avec une association active sur le parc, changer le programme par défaut **à la
   main** (Paramètres Windows → Applications par défaut, ou un autre navigateur).
2. Rouvrir une session (le compagnon réapplique au logon).
3. **Attendu** : l'association est **réimposée** à la cible (statut `drift` au
   rapport puis convergence) — le ProgId ET le Hash sont réécrits. La dérive
   humaine est toujours corrigée (STRICT 27.8). L'ancienne clé UserChoice (ACL
   hérité) est **supprimée avant réécriture** (sinon Windows refuserait le Hash).

### Scénario 27.3bis.3 — Per-user au logon (HKCU) (lab Windows — ACTION HUMAINE Henri)

1. Deux utilisateurs différents ouvrent une session sur le même poste du parc.
2. **Attendu** : chacun reçoit l'association sous **SON** `HKCU` (le hash dépend du
   SID — il est recalculé per-user par le compagnon de chaque session). Aucun
   partage de la clé UserChoice entre utilisateurs.

### Scénario 27.3bis.4 — ProgId absent → choix utilisateur conservé, error non fatal (lab Windows — ACTION HUMAINE Henri)

1. Activer une association dont le **ProgId cible n'est PAS installé** sur le poste
   (ex. `.pdf → Acrobat.Document.DC` sans Acrobat). L'utilisateur a déjà un autre
   lecteur PDF par défaut.
2. Ouvrir une session.
3. **Attendu** : l'agent **NE touche PAS** la clé UserChoice existante — le choix
   de l'utilisateur (son lecteur PDF) est **PRÉSERVÉ** (pas de clobber, pas de
   suppression-avant-réécriture). Les autres associations (ProgId présent) sont
   quand même **appliquées en interne** (`Apply` best-effort).
4. **⚠️ Granularité du statut = PAR TYPE (grain §5 figé 27.8), pas par item.** Le
   rapport porte `associations: error` **non fatal** (`detail` = « ProgId X non
   enregistré, choix utilisateur conservé ») tant qu'AU MOINS un item résiste : le
   type entier reste `error` et **n'est pas persisté** → re-convergence à chaque
   cycle (pas de 304). « Non fatal » = ne tue pas les autres TYPES (wallpaper,
   printers…), **pas** « les autres associations passent en `compliant` ». Avec la
   non-intersection WPKG (D-Henri n°3), un défaut ciblant une app non installée
   maintient `associations: error` à chaque cycle — c'est **attendu**, vérifier
   seulement que la clé utilisateur n'est jamais réécrite.

### Scénario 27.3bis.5 — Parcs différents → défauts différents (lab Windows — ACTION HUMAINE Henri)

1. Sur deux parcs distincts, activer des associations différentes pour le même
   identifiant (ex. parc A : `.html → FirefoxHTML` ; parc B : `.html → ChromeHTML`).
2. Ouvrir une session sur un poste de chaque parc.
3. **Attendu** : chaque poste reçoit le ProgId de SON parc. Si un poste appartient
   aux deux mailles, la plus spécifique l'emporte (logique > physique, D-Q3) ; les
   identifiants distincts s'accumulent.

### Scénario 27.3bis.6bis — Validation prédictive par parc dans l'UI (navigateur, hors lab) — D-Henri n°7

> Étend l'onglet « Associations » de la page d'un parc
> (`parc/groups/{id}?tab=associations`). Le warning n'est plus générique
> (« si le ProgId n'est pas installé… ») mais EXACT par paquet et par parc.

1. Ouvrir un parc **sans** Firefox déployé. Une association `wpkg` (ex.
   `http → FirefoxURL`, paquet `firefox`) s'affiche avec un **badge « indisponible »
   rouge + icône warning** ; le tooltip nomme le paquet : « `firefox` n'est pas
   déployé sur ce parc → cette association échouera ici (l'agent rapportera une
   erreur, le choix utilisateur reste préservé) ».
2. Activer cette association → **toast d'avertissement EXACT** nommant `firefox`
   (pas le toast de succès simple).
3. Déployer Firefox sur ce parc (rattacher l'app `firefox` au parc ou via un app
   profile), recharger : l'association passe **`applicable`** (plus de badge/warning).
4. Une association **`native`** (ex. `.txt → txtfile`, `.jpg → WindowsPhotoViewer`)
   est **toujours `applicable`** quel que soit le parc (aucune dépendance de paquet) ;
   l'activer émet un **toast de succès simple** (« Association activée pour le parc. »).
5. **Invariant** : le statut prédictif est calculé **côté serveur** (group-level
   Eloquent PG-pur, sans APCu) ; `curl GET /state` ne fait JAMAIS apparaître
   `source`/`wpkg_package` dans un payload `associations` (toujours `{identifier,
   progid, type}`). L'agent reste le dernier rempart (`ProgIDRegistered`) sur un
   poste où l'app est absente malgré le « déployé » serveur.

### Post-correctifs & non-régressions (Story 27.3bis)

- **UI = onglet de la page d'un WorkstationGroup** (correctif post-livraison) :
  l'association par défaut s'appliquant PAR groupe, le geste vit dans un onglet
  « Associations » de `parc/groups/{id}` (composant Livewire
  `pages::parc.groups._partials.associations-tab`, monté `:group-id`, gate
  `app.customize`), PAS dans une page parc-settings globale à sélecteur de parc
  (page standalone + route `parc-settings.file-associations` supprimées). La
  validation prédictive WPKG est calculée pour CE groupe. L'onglet n'apparaît que
  pour `app.customize`.
- **Zéro modification du `StateCompiler`** : `AssociationsStateProvider` réutilise
  le marqueur `KeyedExclusiveProvider` (exclusiveKey = `identifier`) déjà câblé par
  27.3 — non-régression `registry`/`wallpaper`/`printers` vérifiée (tests
  compilateur).
- **Hash UserChoice — fidélité par tests vectoriels** : le portage Go est verrouillé
  contre un portage **indépendant** (référence Python en arithmétique exacte) sur
  des triplets figés. Une constante fausse = hash rejeté par Windows = association
  non appliquée (bug silencieux) — d'où l'obligation vectorielle.
- **Bump de hash croisé** : item `associations` ajouté au golden → `FROZEN_STATE_HASH`
  (PHP `ContractV1Test`) ET `frozenStateHash` (Go `hasher_test.go`) bumpés à la même
  valeur (`77fb548a…f3fa5e3bd`), 8 items au golden, session 6 items.
- **Invariant central** : `curl GET /state` ne doit JAMAIS faire apparaître un
  `key`/`id` de catalogue NI un `hash`/`sid` dans un payload `associations`
  (uniquement `{identifier, progid, type}`).
- **Extension WPKG-aware (D-Henri n°7)** : catalogue tagué `native`/`wpkg`
  (`source` + `wpkg_package` SERVEUR-only) ; validation prédictive UI par parc
  (native → applicable ; wpkg déployé → applicable ; wpkg non déployé →
  indisponible, warning EXACT + toast nommant le paquet). Calcul des paquets
  déployés = group-level Eloquent PG-pur (sans cache APCu). **Provider INCHANGÉ**
  (PG-pur, émet toujours, grep `ldap|apcu|wpkg` VIDE). **Golden/hash/agent
  INTOUCHÉS** (`source`/`wpkg_package` ne fuient jamais au payload — l'agent ne
  connaît pas native/wpkg). Clé de jointure VÉRIFIÉE : `<package id>` du reader =
  `Application::$app_id` (= racine `<package id>` du `$app->xml`).

### Checklist rapide (Story 27.3bis)

- [ ] 27.3bis.1 — Association appliquée par parc au logon (compagnon), `.html`
      ouvre Firefox (hash UserChoice valide, non réinitialisé par Windows).
- [ ] 27.3bis.2 — Programme par défaut changé à la main → réimposé au logon (drift
      STRICT, clé supprimée-avant-réécriture).
- [ ] 27.3bis.3 — Per-user : chaque session reçoit l'association sous son HKCU
      (hash dépendant du SID).
- [ ] 27.3bis.4 — ProgId absent → choix utilisateur **conservé** (pas de clobber),
      pas de boucle ; statut `associations: error` **type-level** (tout le type tant
      qu'un item résiste, non persisté) — attendu, vérifier seulement le non-clobber.
- [ ] 27.3bis.5 — Parcs différents → défauts différents par poste (logique >
      physique sur maille partagée).
- [ ] 27.3bis.6 — Payload `associations` concret `{identifier, progid, type}`,
      jamais d'id de catalogue NI de hash/SID (`curl /state`).
- [ ] 27.3bis.7 — UI onglet « Associations » du parc (`parc/groups/{id}?tab=associations`, navigateur, hors lab) :
      validation PRÉDICTIVE par parc (D-Henri n°7). Une association `wpkg` dont le
      paquet n'est **pas déployé** sur le parc affiche un **badge « indisponible » +
      warning rouge + tooltip nommant le paquet** ; l'activer émet un **toast
      d'avertissement EXACT** nommant ce paquet. Le même paquet **déployé** → la
      ligne passe `applicable` (plus de warning). Une association **`native`**
      (`.txt`, `.jpg`) est toujours `applicable` (toast de succès simple).

## Story 27.3ter — Registre : valeur par défaut diffusée + override par parc

> **Note de migration sémantique.** Le modèle 27.3 « activer / cesser de gérer »
> est REMPLACÉ : `registry_settings.value` devient la **valeur par défaut diffusée
> à TOUTE la flotte** (maille Broadcast) ; le pivot porte un **override de valeur
> par parc** (`registry_setting_assignables.value`). « Retirer un override » =
> **revenir à la valeur par défaut** (l'agent re-converge), PAS « cesser de gérer ».
> **Contrat & agent INCHANGÉS** (5 clés payload), **golden/hash INTACTS** (la
> fixture `state.v1.json` est hand-authored, jamais compilée d'un seed → la
> diffusion Broadcast ne la touche pas). Aucun fichier Go modifié. Le canal legacy
> Registry.pol/GPO reste intouché (meurt en 27.6).

### Scénario 27.3ter.1 — Défaut appliqué PARTOUT, sans aucun override (lab Windows — ACTION HUMAINE Henri)

1. Un réglage actif du catalogue (ex. `HideFileExt` défaut `0`), AUCUN override sur
   le parc du poste.
2. **Attendu** : le poste applique la **valeur par défaut** (Broadcast) — la clé est
   gérée sur toute la flotte sans aucune assignation. (Un parc neuf hérite des
   défauts sans pivot ; l'observer `WorkstationGroupObserver` n'est PAS modifié, zéro
   matérialisation à la création — D1.)

### Scénario 27.3ter.2 — Override de parc applique la déviation (lab Windows — ACTION HUMAINE Henri)

1. Onglet « Registre » de la page du parc → **Ajouter un réglage** → choisir
   `HideFileExt`, saisir la valeur déviée (ex. `1`) via le contrôle adapté au type,
   confirmer (encart `warning` si présent).
2. **Attendu** : les postes du parc reçoivent la **valeur d'override** (`1`) ; les
   postes hors parc gardent le **défaut** (`0`). L'override bat le défaut Broadcast
   pour cette clé (précédence existante, exclusive par clé inchangée).

### Scénario 27.3ter.3 — Retirer un override → re-convergence au DÉFAUT (lab Windows — ACTION HUMAINE Henri)

1. Sur un parc avec un override actif, cliquer **Retirer** dans l'onglet « Registre ».
2. **Attendu** : la ligne de pivot disparaît → au cycle suivant l'agent **réapplique
   la valeur par défaut** (Broadcast) sur le poste. **PAS** de valeur figée sur la
   dernière valeur subie (D3, inverse exact du `detach` 27.3).

### Scénario 27.3ter.4 — Logique > physique sur un override de même clé (lab Windows — ACTION HUMAINE Henri)

1. Même clé déviée sur la **salle physique** (valeur A) ET le **parc logique**
   (valeur B) d'un même poste.
2. **Attendu** : le **parc LOGIQUE l'emporte** (D-Q3). Le défaut Broadcast n'est servi
   que si aucune maille du poste ne porte d'override.

### Scénario 27.3ter.5 — Posture UAC sûre par défaut + warning (navigateur + lab — ACTION HUMAINE Henri)

1. Vérifier que le défaut de `EnableLUA` est **`1`** (UAC ACTIVÉ) — diffusé partout
   (posture sûre, D6). « Désactiver l'UAC » devient un **override de parc** délibéré.
2. À l'ajout/édition de cet override (onglet parc) OU à l'édition de son défaut
   (`/admin/settings/registry`), un **encart de warning** s'affiche et exige une
   **confirmation explicite** avant persistance (D7). `warning = null` ⇒ pas d'encart.

### Comportement validé (2026-06-17) — latence d'application au logon (HKCU)

Boucle complète serveur → contrat → agent → registre **testée et validée e2e** sur
poste : ajout/édition d'override appliqué, et **retrait → re-convergence à la valeur
par défaut diffusée (Broadcast)** confirmés (scénarios 27.3ter.1 à .3 ; cas reproduit
avec les défauts seedés `HideFileExt=0` afficher / `Hidden=1` afficher).

**Latence à connaître pour les scénarios HKCU.** Les réglages de la ruche **HKCU**
servis au logon par le compagnon (clés Explorer Advanced `Hidden` / `HideFileExt`)
ne deviennent **visibles qu'au LOGON SUIVANT**, pas dans la session en cours :
l'Explorateur lit ces clés per-user à l'ouverture de session. Un changement — ou un
retrait d'override (retour au défaut) — appliqué pendant/à un logon donné s'observe
donc au logon d'après. **Ne pas conclure à un échec du handler si l'effet n'apparaît
pas immédiatement** : re-logon, puis vérifier. Pour un effet intra-session il faudrait
un rafraîchissement explicite de l'Explorateur (`SHChangeNotify`), hors périmètre
actuel.

### Réglages serveur — édition du défaut (navigateur, hors lab)

- **Page `/admin/settings/registry`** (gate `server.admin`) : l'admin fixe la
  **valeur par défaut** de chaque réglage (`registry_settings.value` — diffusée à
  toute la flotte), via le **même contrôle adapté au type** + **validation serveur**
  + confirmation si `warning`. Toggle `is_active` (un réglage inactif n'est plus
  diffusé). N'édite QUE le catalogue existant — **pas d'éditeur de clés brutes** (v2).
- **Validation** (onglet parc ET serveur) : DWORD/QWORD = entier borné, SZ/EXPAND_SZ
  = chaîne non vide, MULTI_SZ = liste, et si `options` présent → valeur ∈ valeurs
  autorisées. Rejet propre (erreur Livewire, jamais d'exception au render).

### Checklist rapide (Story 27.3ter)

- [ ] 27.3ter.1 — Poste SANS override applique le défaut diffusé (Broadcast).
- [ ] 27.3ter.2 — Override de parc applique la déviation ; hors parc = défaut.
- [ ] 27.3ter.3 — Retirer l'override → re-convergence au défaut (pas de valeur figée).
- [ ] 27.3ter.4 — Même clé sur salle + parc logique → logique gagne (D-Q3).
- [ ] 27.3ter.5 — `EnableLUA` défaut `1` (UAC activé) ; désactiver = override + warning confirmé.
- [ ] 27.3ter.6 — `/admin/settings/registry` (navigateur) : édition du défaut + validation + warning + Gate admin.
- [ ] 27.3ter.7 — Onglet « Registre » du parc n'affiche QUE les overrides ; payload `registry` reste `{hive,path,name,type,value}` (`curl /state`).
- [ ] 27.3ter.8 — Effet d'une clé HKCU (`Hidden`/`HideFileExt`) visible au LOGON SUIVANT, pas dans la session courante (latence assumée, pas un bug du handler).

## Story 27.4 — Config d'app déclarative (policies.json Firefox/Thunderbird)

Le canal agent gagne le type `app_config` : la **configuration des navigateurs
configurables par policies natives** (Firefox, Thunderbird) suit l'état cible du
**parc**, appliquée et maintenue par l'agent via le **SEUL mécanisme enterprise
natif `policies.json`** (fichier au chemin d'install de l'app, écriture atomique).
Successeur natif du canal export-FS des policies (`exportToFs` →
`/etc/sambaedu/applications/{kind}/*.json`, GPO/WPKG — le canal legacy reste
intouché, meurt en 27.6). **Aucune nouvelle UI** : l'édition des policies par
scope existe déjà (story 4.8, `parc-settings/app-customizations`).

**Deux mécanismes legacy distincts (correctif post-review 2026-06-17, #1).** Le
legacy traite Firefox via (A) **config** = `policies.json` (machine-wide, écrit
sous `%ProgramFiles%\…\distribution\` en contexte admin/SYSTEM, **PAR-PARC**) —
**c'est 27.4** ; (B) **profil user** = jonctions/redirection du dossier profil
(roaming) — **HORS 27.4** (story roaming de suivi). Le par-user de Firefox = le
profil (Mécanisme B), PAS `policies.json`. La config d'app via `policies.json` est
donc appliquée par le **moteur SYSTEM** (portée `machine`), résolue **PAR PARC**
(niveaux 1-4, `$user = null`) — un compagnon user prendrait ACCESS_DENIED sous
Program Files.

**Pas de table neuve.** Contrairement à 27.3/27.3bis (catalogues créés),
`app_config` LIT la table métier existante `app_customizations` (4.8) via
`AppCustomizationService::resolvePoliciesForMachine($wg, null, $kind, 'windows')`
(résolution hiérarchique niveaux 1-4 : template → auto proxy/DNS/popup → défaut
étab → WG). **PG + config-pur** (NFR7, critère Keycloak — grep
`Cache::|apcu|Ldap|samba-tool` vide sur le provider). Le payload porte les
policies **CONCRÈTES** `{app_kind, policies}`, jamais un id de scope. Aggregate
PAR `app_kind` (un item par app), scope **machine** (`policies.json` machine-wide,
écrit par le service SYSTEM).

**Recadrage périmètre (2026-06-17).** UNIQUEMENT Firefox/Thunderbird
`policies.json`. **Chrome/Edge RETIRÉS** (le legacy ne gère aucune policy) ;
**redirection de profil navigateur RETIRÉE** (Mécanisme B, sujet roaming serveur,
renvoyé au domaine roaming/`WorkstationEnvironment` — 26.x / story de suivi).

### Scénario 27.4.1 — Config navigateur appliquée PAR PARC via policies.json (lab Windows — ACTION HUMAINE Henri)

1. Éditer les policies Firefox au niveau **parc** (`WorkstationGroup`) dans l'UI
   4.8 (`parc-settings/app-customizations`) — ex. parc impose une `Homepage` et
   `DisableTelemetry`. S'assurer que Firefox est installé.
2. Démarrer un poste du parc (le moteur SYSTEM converge au bootstrap / au
   réveil ; pas besoin de session interactive).
3. **Attendu** : le **service SYSTEM** écrit
   `…\Mozilla Firefox\distribution\policies.json` (écriture atomique) avec la
   **résolution PAR PARC niveaux 1-4** (template + auto + défaut étab + WG gagnant,
   `resolvePoliciesForMachine($wg, null, …)`). Vérifier dans `about:policies`
   (Firefox) que le réglage de parc est actif. Le fichier porte la clé
   `_sambaedu_managed: true` (marqueur de périmètre, inerte côté Firefox).
4. Un second `policies.json` n'est posé pour **Thunderbird** que si une règle
   Thunderbird existe (un item par app — type/clé absente = non géré, §8).
5. **Pas de par-user** : un override Firefox au niveau **utilisateur** (UserGroup
   ou User) dans l'UI 4.8 n'a **aucun effet** sur `policies.json` (niveaux 5-6 non
   résolus en portée machine). Le par-user de Firefox = le **profil** (Mécanisme B
   / roaming, hors 27.4).

### Scénario 27.4.2 — Par-parc stable inter-sessions (lab — ACTION HUMAINE Henri)

1. Sur le même poste/parc, ouvrir des sessions avec **deux utilisateurs
   différents**.
2. **Attendu** : le `policies.json` est **identique** quel que soit l'utilisateur
   (config par-parc, écrite par SYSTEM, indépendante de la session). `curl /state`
   (token agent) montre l'item `app_config` dans la portée **`machine`**, un par
   `app_kind`, avec policies concrètes, **jamais** un `customization_id`/scope.
3. Un poste appartenant à **deux parcs logiques** avec des policies Firefox
   différentes : seul le parc gagnant (précédence `logique > physique`, puis plus
   petit id) est appliqué — le 2ᵉ parc logique est **silencieusement ignoré**
   (limite connue, review #2 : `policies.json` machine-wide ne porte qu'une config
   par install).

### Scénario 27.4.3 — Level-triggered : policy retirée → dé-appliquée (lab — ACTION HUMAINE Henri)

1. Désassigner toutes les règles `app_config` d'une app (ex. retirer la
   customization Firefox du parc et de l'étab) → l'app n'a plus aucun item au
   `/state`.
2. Relancer un cycle (réveil / reboot — le moteur SYSTEM converge).
3. **Attendu** : le `policies.json` **GÉRÉ** (marqueur `_sambaedu_managed`) de
   cette app est **retiré** (convergence, pas accumulation). Un `policies.json`
   posé **hors SambaEdu** (autre outil, admin — sans marqueur) au même chemin
   n'est **JAMAIS** supprimé.

### Scénario 27.4.4 — Drift STRICT : policy modifiée à la main réimposée (lab — ACTION HUMAINE Henri)

1. Modifier à la main le `policies.json` posé par l'agent (changer une URL, vider
   une clé).
2. Relancer une session (ou attendre le cycle).
3. **Attendu** : le réel ≠ cible → **drift** + réécriture à l'octet près de la
   cible résolue serveur (STRICT inconditionnel, story 27.8 — pas de tolérance de
   dérive). Statut `drift` au rapport. Deux passes sur état stable = `compliant`,
   zéro écriture (idempotence).

### Scénario 27.4.5 — App butée = non géré documenté (« match nul » assumé) (revue de code + lab)

1. Une app qui n'expose **aucun mécanisme enterprise natif** (`policies.json`)
   pour un réglage demandé.
2. **Attendu** : l'agent **ne force RIEN et n'invente RIEN** (pas de patch de
   config user bricolé, pas de hook). Le réglage est documenté comme **limite
   connue** (`docs/agent/state-providers.md`, `contract-v1.md` §7.3). Invariant :
   un handler n'écrit que via un mécanisme enterprise documenté. À ce stade, les
   apps gérées (`knownAppKinds` = firefox, thunderbird) ont toutes un
   `policies.json` → pas d'app butée active, mais l'invariant est posé.

### Scénario 27.4.6 — Isolation des erreurs : chemin verrouillé/app absente → `error`, le reste continue (lab — ACTION HUMAINE Henri)

1. Rendre le `policies.json` d'une app non écrivable (chemin verrouillé, ou
   dossier `distribution\` absent / app non installée).
2. **Attendu** : statut `error` + `detail` exploitable pour le type `app_config`
   ; **les autres types** (shortcuts/wallpaper/registry/associations/printers/
   drives) **continuent** (isolation `engine.go::RunPass`, jamais réimplémentée).
   En interne, l'app saine du même type converge quand même (effort maximal) ;
   retry au cycle suivant (level-triggered). **Couplage installation** (limite
   connue) : pour que la policy ait un effet, l'app doit être installée → 27.5.

### Scénario 27.4.7 — Conflit hors-périmètre : policies.json étranger → `error`, jamais écrasé (lab — ACTION HUMAINE Henri)

1. Poser à la main un `policies.json` (sans clé `_sambaedu_managed`) au chemin
   natif d'une app, AVANT toute règle agent.
2. Activer une règle `app_config` pour cette app, relancer un cycle (réveil /
   reboot).
3. **Attendu (correctif post-review #7)** : l'agent **détecte** le fichier hors
   périmètre et **ne l'écrase JAMAIS** (non-ingérence préservée ; sa suppression
   level-triggered ne le touche pas non plus). Comme la policy agent n'est alors
   **pas active**, l'item de cette app est rapporté **`error`** (détail :
   « policies.json hors-périmètre présent, policy agent non appliquée ») et **non**
   `compliant` (qui masquerait le conflit). Les autres apps/types convergent
   (isolation). _(Défaut = signaler sans écraser ; une « prise de possession »
   SYSTEM pourra être décidée plus tard.)_

### Checklist rapide (Story 27.4)

- [ ] 27.4.1 — `policies.json` posé PAR PARC par le moteur SYSTEM (niveaux 1-4),
      visible dans `about:policies`, marqueur `_sambaedu_managed` présent ; un
      override user n'a aucun effet (par-user = profil, hors 27.4).
- [ ] 27.4.2 — Config par-parc identique inter-sessions ; item `app_config` en
      portée **`machine`**, payload concret `{app_kind, policies}`, jamais d'id de
      scope (`curl /state`) ; 2 parcs logiques → seul le gagnant appliqué (limite).
- [ ] 27.4.3 — Policy retirée des règles → `policies.json` géré **dé-appliqué** ;
      fichier hors périmètre jamais supprimé.
- [ ] 27.4.4 — Policy modifiée à la main **réimposée** (drift STRICT) ;
      idempotent (2 passes = compliant, zéro écriture).
- [ ] 27.4.5 — App butée sans mécanisme enterprise = **non géré documenté**, zéro
      bricolage (invariant posé).
- [ ] 27.4.6 — Chemin verrouillé/app absente → `error` isolé, les autres types
      convergent (couplage install 27.5 documenté).
- [ ] 27.4.7 — `policies.json` étranger → **`error` de conflit** (jamais écrasé ni
      supprimé ; jamais `compliant` trompeur).
- [ ] 27.4.8 — Aucune nouvelle UI (édition policies 4.8 inchangée) ; Chrome/Edge
      + redirection de profil **hors scope** (roaming → 26.x).

## Story 27.5 — Applications : l'agent déclenche WPKG (un tuyau, deux outils)

Le canal agent gagne le type `applications` : les **installations d'applications**
passent par le **canal agent** (déclencheur) à la place de la GPO `se4_wpkg`.
**« Un tuyau, deux outils »** : l'agent unifie le **transport** (déclenche au
cycle), PAS le moteur. **WPKG reste le moteur déclaratif** (résolution de
dépendances, `<check>/<install>/<upgrade>`, versions) — **non absorbé**. Le
handler `applications` (MachineEngine SYSTEM — WPKG installe machine-wide) **donne
l'URL** du bundle (Apache statique) + **dépose** localement le profil par-hôte
(`profiles.xml`/`hosts.xml` dans `%ProgramData%\SambaEdu\wpkg`, D9) + **déclenche**
`wpkg-client.vbs /NOTempo`, puis **lit `wpkg.xml`** pour l'état par paquet.

**Livraison NATIVE SE5 (D6).** Shim WPKG legacy supprimé (`/wpkg/hosts.xml`,
`/wpkg/profiles.xml`). SE5 **génère** le bundle pré-substitué (`php artisan
wpkg:bundle` : scripts versionnés `resources/wpkg/*` patchés + catalogue
`packages.xml`, `SE4FS_NAME` résolu) servi en **statique par Apache**
(`config('agent.wpkg_bundle_path')`, PAS via Laravel). Le **client** télécharge
(zéro charge Laravel — D7) ; installeurs sur SMB inchangés (D11). GPO `se4_wpkg`
**plus publiée** par SE5 (l'agent est le seul déclencheur — D2).

**Provider PG-pur (NFR7).** `ApplicationsStateProvider` projette l'ensemble cible
via `WorkstationPackagesResolver::computePackages` (méthode **NON CACHÉE**, jamais
le wrapper `resolve()`/APCu). Payload concret `{app_id, name}`, aggregate / scope
`machine`, maille Broadcast (résolution déjà finale — D4). **Inventaire PAR APP**
rapporté (champ additif `inventory`, AC4) → `agent_application_inventory`
(fondation des licences à pool, sans UI) ; le verdict du type reste PAR TYPE
(grain 27.8 intact).

### Scénario 27.5.1 — App affectée à un parc installée par l'agent (lab Windows — ACTION HUMAINE Henri)

1. Affecter une application WPKG à un **parc** (`WorkstationGroup`) — ex. VLC.
2. Générer le bundle sur le serveur : `php artisan wpkg:bundle` (puis chown
   www-admin sur le sous-dossier).
3. Sur un poste du parc : relancer un cycle agent (réveil au logon / reboot /
   bouton forcer).
4. **Attendu** : l'item `applications` apparaît dans `GET /api/v1/agent/state`
   (portée `machine`, payload `{app_id, name}`) ; le handler **dépose**
   `profiles.xml`/`hosts.xml` dans `%ProgramData%\SambaEdu\wpkg` puis **déclenche**
   `wpkg-client.vbs` ; WPKG installe VLC (+ ses dépendances) ; `wpkg.xml` liste le
   paquet ; le rapport remonte `applications` `drift` (puis `compliant` au cycle
   suivant) + l'inventaire (`agent_application_inventory` : VLC `compliant`).

### Scénario 27.5.2 — Convergence level-triggered (poste hors ligne) (lab — ACTION HUMAINE Henri)

1. Affecter une app à un poste **hors ligne** pendant la fenêtre d'installation.
2. Rallumer/reconnecter le poste plus tard.
3. **Attendu** : le poste converge à son **prochain cycle** (boot/login/timer/
   forcer) — l'app s'installe. Fini le poste joint qui n'installe rien : le
   déclencheur n'est plus l'événement GPO ponctuel au boot mais le **cycle agent
   répété** (« programmé aujourd'hui, effectif demain »).

### Scénario 27.5.3 — Idempotence : poste déjà convergé → pas de re-déclenchement (lab — ACTION HUMAINE Henri)

1. Sur un poste où l'ensemble cible est **déjà entièrement installé** (désiré ⊆
   installé dans `wpkg.xml`), relancer un cycle.
2. **Attendu** : `Test` est vrai → statut `applications` **`compliant`**, WPKG
   **n'est PAS re-déclenché** (level-triggered, pas d'effet cumulatif). Aucune
   ré-installation.

### Scénario 27.5.4 — App retirée des affectations → libère son siège (lab + base — ACTION HUMAINE Henri)

1. Retirer une app des affectations d'un poste, relancer un cycle.
2. **Attendu** : l'app disparaît de l'ensemble cible (état) ; l'agent ne l'exige
   plus installée et la **ligne d'inventaire** `agent_application_inventory` est
   **nettoyée** (level-triggered — siège libéré). L'agent ne **désinstalle pas**
   l'app de lui-même (c'est WPKG qui le ferait via `<remove>`).

### Scénario 27.5.5 — Échec d'install → `error`, jamais un faux `compliant` (lab — ACTION HUMAINE Henri)

1. Affecter une app dont l'installeur échoue (ex. code 1603), relancer un cycle.
2. **Attendu** : WPKG est déclenché mais l'app reste absente de `wpkg.xml` après
   le run → l'agent rapporte le type `applications` en **`error`** + `detail`
   (jamais un `compliant` optimiste — leçon 🟠 27.4 #7) ; l'inventaire marque
   cette app `error` (siège non occupé). Les apps saines du même cycle convergent
   (effort maximal) ; les autres types convergent (isolation).

### Scénario 27.5.6 — Livraison native : shim supprimé, bundle Apache statique (navigateur + lab — ACTION HUMAINE Henri)

1. Vérifier que `/wpkg/hosts.xml` et `/wpkg/profiles.xml` ne sont **plus servis**
   (routes supprimées).
2. `php artisan wpkg:bundle` génère le sous-dossier (scripts + `packages.xml` avec
   `SE4FS_NAME` substitué) ; Apache le sert en **statique** ; `wpkg.cmd` (patché)
   le télécharge en **HTTP** (plus de XCOPY SMB) ; `wpkg-se4.js` (patché) lit le
   catalogue HTTP + les profils **locaux**.
3. **Attendu** : un poste télécharge le bundle depuis Apache (zéro charge Laravel),
   lit le profil déposé par l'agent, installe. La GPO `se4_wpkg` **n'est plus
   publiée** par SE5 (page wpkg-deployment : action de re-publication retirée,
   no-op informatif). Délier la GPO résiduelle côté lab (action Henri, hors
   worktree).

### Scénario 27.5.7 — Provider PG-pur, NFR7 (revue de code + curl)

1. Grep garde sur `app/Services/Agent/Providers/ApplicationsStateProvider.php` :
   `ldap|apcu|samba-tool|Cache::|LdapRecord|PackagesXml` → **vide** (commentaires
   exceptés).
2. **Attendu** : le provider lit `computePackages` (NON CACHÉE), jamais
   `resolve()` (APCu). Payload `{app_id, name}` concret, jamais un id de
   catalogue/pivot/scope, jamais de recette d'install. Item d'état à **4 clés**
   (`type, semantics, payload, hash`).

### Checklist rapide (Story 27.5)

- [ ] 27.5.1 — App de parc installée par l'agent (déclenchement WPKG) ; item
      `applications` machine dans `/state` ; inventaire en base.
- [ ] 27.5.2 — Poste hors ligne → converge au prochain cycle (level-triggered).
- [ ] 27.5.3 — Poste déjà convergé → `compliant`, **zéro re-déclenchement**.
- [ ] 27.5.4 — App retirée → ligne d'inventaire nettoyée (siège libéré) ; pas de
      désinstallation auto.
- [ ] 27.5.5 — Échec d'install → `error` + detail (jamais faux `compliant`),
      inventaire `error` ; isolation des autres types.
- [ ] 27.5.6 — Shim legacy supprimé ; bundle Apache statique (`wpkg:bundle`,
      `SE4FS_NAME` substitué) ; `wpkg.cmd`/`wpkg-se4.js` patchés ; GPO `se4_wpkg`
      plus publiée.
- [ ] 27.5.7 — Provider NFR7 (grep vide), payload 4 clés concret, jamais
      `resolve()`/APCu ; golden + hashes PHP⇄Go re-bumpés à l'identique.

## Story 27.11 — Composer d'associations par défaut (extension libre + app par nom)

> V2 de l'UI d'associations 27.3bis : on passe du **catalogue figé** (toggles) à un
> **composer** — l'admin SAISIT une extension/protocole et CHOISIT l'application
> PAR SON NOM. Le serveur (`AssociationResolver`) traduit en cible technique :
> ProgId **riche** si le paquet le déclare pour l'extension, sinon **générique**
> `Applications\<exe>`. Le canal agent (provider/compilateur/handler/hash) de 27.3bis
> est **réutilisé tel quel** (golden/contrat INTOUCHÉS, payload `{identifier, progid,
> type}` inchangé). La seule donnée neuve = le chemin de l'exe
> (`applications.executable` + table `native_applications`).
>
> **GATE empirique AC1 — DÉJÀ VALIDÉ par Henri (2026-06-18), à re-vérifier en
> non-régression** : (1) un `UserChoice` vers `HKCU\Software\Classes\Applications\<exe>`
> est **honoré par le shell** sans `SupportedTypes` (double-clic `.clclcc` → VLC après
> reboot ✅) ; (2) `getHash` produit un hash valide pour un ProgId contenant `\`
> (`Applications\vlc.exe` → `Gk3UMH/Rm+A=` via SFTA.ps1 ✅). Le vecteur de test
> `.clclcc → Applications\vlc.exe` (`5q6eG+3TpdI=` sur les inputs figés) verrouille la
> fidélité du portage Go pour le cas `\`.

### Scénario 27.11.1 — Composer une association WPKG RICHE (navigateur + lab Windows — ACTION HUMAINE Henri)

1. Ouvrir l'onglet « Associations » d'un parc (`parc/groups/{id}?tab=associations`,
   gate `app.customize`). Le bloc « Ajouter une association » propose une **saisie
   extension/protocole** + un **dropdown d'apps par nom** (WPKG installées + natives
   Win32 curées).
2. Saisir `.html`, choisir **Firefox** (app WPKG). Valider → une ligne
   `.html → FirefoxHTML` est créée (`source=wpkg`, `wpkg_package=firefox`) et attachée
   au parc. Le ProgId **riche** `FirefoxHTML` est déclaré par `packages.xml` pour `.html`
   (pas un générique).
3. **Lab** : sur un poste du parc avec Firefox déployé, au logon suivant, double-cliquer
   un `.html` ouvre Firefox (UserChoice + hash appliqués par le compagnon, non
   réinitialisé par Windows).

### Scénario 27.11.2 — Composer une association GÉNÉRIQUE custom `.clclcc → Applications\<exe>` (navigateur + lab Windows — ACTION HUMAINE Henri)

1. Saisir une extension arbitraire `.clclcc`, choisir une app WPKG **sans handler
   déclaré pour `.clclcc`** mais avec un exécutable connu (ex. **VLC**,
   `executable = C:\Program Files\VideoLAN\VLC\vlc.exe`). Valider.
2. La ligne créée porte un **ProgId générique fabriqué** `Applications\vlc.exe` (badge
   « générique »), `source=wpkg`, `wpkg_package=vlc`.
3. **Lab** : au logon, le **compagnon** (droits user) auto-enregistre PER-USER
   `HKCU\Software\Classes\Applications\vlc.exe\shell\open\command = "C:\…\vlc.exe" "%1"`
   (chemin résolu sur le poste via App Paths/PATH — JAMAIS reçu du serveur) AVANT
   d'imposer UserChoice. Double-cliquer un `.clclcc` ouvre VLC (validé empiriquement
   2026-06-18). AUCUNE écriture HKLM/admin.

### Scénario 27.11.3 — Prédictif « indisponible » : paquet WPKG non déployé (navigateur, hors lab)

1. Sur un parc **sans** le paquet déployé, composer/afficher une association `wpkg`
   (ex. `.html → FirefoxHTML`, paquet `firefox`). La ligne affiche un **badge
   « indisponible » + icône warning** ; le tooltip nomme le paquet.
2. À la composition d'une telle association, un **toast d'avertissement EXACT** nomme
   le paquet (« `firefox` n'est pas déployé sur ce parc → … »). Une association `native`
   (`.txt → txtfile`) ou un `wpkg` déployé → **toast de succès simple**.
3. **Invariant** : le statut prédictif est calculé **côté serveur** (group-level
   Eloquent PG-pur, SANS APCu). `curl GET /state` ne fait JAMAIS apparaître
   `source`/`wpkg_package` (payload toujours `{identifier, progid, type}`).

### Scénario 27.11.4 — Garde-fou exe manquant : pas de générique sans exe (navigateur, hors lab)

1. Choisir une app **sans ProgId riche pour l'extension ET sans `executable`** (ex.
   Firefox + `.clclcc`, Firefox n'ayant pas d'exe renseigné). Valider.
2. La composition est **refusée** : **toast d'erreur** (« Cette application n'a pas
   d'exécutable connu… »), AUCUNE ligne `file_associations` créée (piège n°4 : pas de
   générique sans exe). Le `%1` est obligatoire dans la commande générée.

### Scénario 27.11.5 — Liste éditable / désactivable (navigateur, hors lab)

1. La liste « Associations par défaut du parc » n'affiche que les associations
   **attachées à CE parc** (défauts legacy seedés 27.3bis inclus), comme lignes
   **éditables/désactivables**.
2. « Retirer » détache l'association du parc = **cesser de la gérer** (iso 27.3bis :
   le choix déjà appliqué sur le poste reste, PAS de reset OFF — l'item disparaît du
   `/state`).

### Post-correctifs & non-régressions (Story 27.11)

- **AC7 — invariance aval PROUVÉE** : `git diff --stat` **VIDE** sur
  `AssociationsStateProvider`, `StateCompiler`, `tests/Fixtures/Agent/state.v1.json`,
  `ContractV1Test.php`, `agent/shared/hasher_test.go`. Le seul code agent touché est
  `agent/shared/handler_associations.go` (raffinement `ProgIDRegistered` POUR LE CAS
  GÉNÉRIQUE + auto-enregistrement AC6), son test (vecteur `\` + auto-enregistrement) et
  l'impl Windows. Le payload reste `{identifier, progid, type}` ; le hash UserChoice
  (`getHash`/`WriteUserChoice`) est **réutilisé tel quel** (zéro régression).
- **NFR7** : `AssociationResolver` et `AssociationsStateProvider` PG-purs (grep
  `apcu_`/`LdapRecord`/`samba-tool` VIDE — seules des mentions documentaires « Aucun…
  APCu »). La lecture `packages.xml` du resolver est un geste d'ADMINISTRATION (hors
  chemin desired-state, iso `FileAssociationSeeder`). Le croisement WPKG prédictif vit
  dans l'UI (group-level Eloquent, sans le cache APCu de `WorkstationPackagesResolver`).
- **ProgIDRegistered raffiné CAS GÉNÉRIQUE uniquement** : pour `Applications\<exe>`, on
  vérifie la sous-clé `shell\open\command` (valeur par défaut non vide), pas seulement
  la présence du nœud — sinon on croit l'asso applicable alors que Windows ouvrirait
  « Comment voulez-vous ouvrir… ». Les ProgId riches restent inchangés (présence du nœud).
- **Exe résolu sur le POSTE** : le chemin complet de `<exe>` n'est JAMAIS dans le
  payload (invariant) ; le compagnon le résout via `App Paths` (HKCU puis HKLM) puis le
  PATH. Introuvable → abstention D-Henri n°5 (error non fatal, choix préservé).

**Correctifs de review (2026-06-18) — angles détectables en manuel mais hors tests unitaires :**

| Incident | Correctif | Angle de test |
|----------|-----------|---------------|
| M1/C5 — recomposer une paire depuis un parc réactivait `is_active` pour TOUS les parcs (kill-switch global contourné) | `is_active` posé uniquement à la création (`firstOrNew`), jamais réécrit sur ligne existante | Scénario 27.11.7 |
| C4/Q2 — composer 2 apps pour la même extension : la 2e était silencieusement ignorée par l'agent (règle exclusive) sans signal UI | **Remplacement AUTOMATIQUE** (décision Henri Q2, 2026-06-18) : l'ancienne association du même `identifier` (progid ≠) est détachée du parc, la nouvelle la remplace + toast | Scénario 27.11.8 |
| C2 — générique d'une native curée affiché « applicable » à tort (faux positif prédictif) | Tout ProgId générique (`Applications\<exe>`) → badge **« best-effort »** indépendamment de `source` (AC5) | Scénario 27.11.3 étendu |
| C1/Q1 — Visionneuse de photos seedée avec `rundll32.exe` (générique structurellement inopérant) | Entrée retirée du catalogue natif ; **WordPad AUSSI retiré** (supprimé de Win11 24H2 — décision Henri Q1) → reste Bloc-notes + Paint | Vérif seed `native_applications` |

### Scénario 27.11.7 — `is_active` n'est PAS réactivé en recomposant depuis un autre parc (navigateur, hors lab)

- **Given** une paire `(identifier, progid)` partagée, globalement désactivée (`is_active=false`).
- **When** un admin recompose la même paire `(extension, app)` depuis un parc différent.
- **Then** la ligne `file_associations` **reste** `is_active=false` (la recomposition n'écrit `is_active` qu'à la création) → la paire reste coupée côté provider pour tous les parcs. Vérif SQL : `is_active` inchangé après `compose()`.

### Scénario 27.11.8 — Remplacement automatique sur extension déjà associée (navigateur, hors lab)

- **Given** un parc a déjà `.html → FirefoxHTML` attaché.
- **When** l'admin compose `.html → Applications\chrome.exe` (progid différent) sur le même parc.
- **Then** l'ancienne association (`FirefoxHTML`) est **automatiquement détachée du parc** (décision Henri Q2) ; une **seule** association `.html` reste attachée (la nouvelle) ; un **toast** signale le remplacement de l'association précédente. La ligne `file_associations` de l'ancienne n'est PAS supprimée (elle peut rester attachée à d'autres parcs) ; le choix déjà appliqué côté poste reste (piège n°5).

### Checklist rapide (Story 27.11)

- [ ] 27.11.1 — Composer une asso WPKG riche (`.html → FirefoxHTML`), appliquée au logon.
- [ ] 27.11.2 — Composer une asso générique custom (`.clclcc → Applications\vlc.exe`),
      auto-enregistrement per-user + ouverture VLC au double-clic (lab).
- [ ] 27.11.3 — Prédictif « indisponible » : paquet WPKG non déployé → badge + toast
      nommant le paquet ; `native`/déployé → succès.
- [ ] 27.11.4 — Garde-fou exe manquant : générique refusé (toast d'erreur), rien créé.
- [ ] 27.11.5 — Liste du parc éditable : « Retirer » = cesser de gérer (item absent du `/state`).
- [ ] 27.11.6 — AC1 (déjà validé 2026-06-18) : UserChoice → `Applications\<exe>` honoré
      sans `SupportedTypes` ; hash valide pour ProgId avec `\`.
- [ ] 27.11.7 — Recomposer une paire globalement désactivée depuis un autre parc ne la
      réactive PAS (`is_active` reste false). [correctif review M1/C5]
- [ ] 27.11.8 — Composer une 2e app pour une extension déjà associée → l'ancienne est
      détachée du parc, la nouvelle la remplace (une seule reste) + toast. [Q2 / C4]

## Story 27.12 — Config en CAPACITÉS : registre repensé (capability-first)

**Contexte.** Rewrite du modèle 27.3/27.3ter : l'admin gère désormais des
**capacités** (intention métier — « Afficher les extensions », « Bureau à
distance », « MAJ Windows gérées »…), jamais une clé de registre. La table
centrale d'authoring est `capabilities` ; le registre est une **projection**
(`capability_projections.mechanism = registry`). **Contrat, `StateCompiler`,
golden et handler Go INCHANGÉS** (D3) : l'item registry reste `{hive, path, name,
type, value}`. Les anciennes tables/providers/UI registry sont retirées.

> ⚠️ **Pré-requis VM** : `php artisan migrate` (crée `capabilities` /
> `capability_projections` / `capability_assignments`, seede le lot iso, **droppe**
> `registry_settings`/`registry_setting_assignables`) ; `php artisan route:cache` +
> `chown www-admin` (routes UI capacités modifiées). Pas de `config:cache`.

### Scénario 27.12.1 — Bascule serveur : le registre est une projection (revue de code + curl)

1. Vérifier `app/Providers/AgentServiceProvider.php` : `Registry{Machine,User}CapabilityProvider`
   enregistrés **à la place** de `Registry{Machine,User}StateProvider` (retirés).
2. `grep -rn "RegistrySetting" app/` → uniquement docblocks (modèle/tables droppés).
3. `GET /state` d'un poste enrôlé : l'enveloppe contient toujours des items `type:registry`
   au payload `{hive,path,name,type,value}` — **aucun** `capability_id`/`key`/`label`/`spec`.

### Scénario 27.12.2 — Capacité diffusée par défaut (lab Windows — ACTION HUMAINE Henri)

1. Sans aucun override de parc, une capacité active du lot iso (ex. `show_file_extensions`
   défaut `on`) est diffusée à TOUTE la flotte : le poste reçoit l'item registry
   correspondant (HideFileExt=0) et l'Explorateur affiche les extensions (au logon suivant
   pour les clés HKCU Explorer).

### Scénario 27.12.3 — Override de parc applique la déviation (lab Windows — ACTION HUMAINE Henri)

1. Onglet « Options / Capacités » d'un parc → « Ajouter une capacité » → choisir
   `show_file_extensions`, valeur `off` (Masquer) → Enregistrer.
2. Sur un poste du parc : au cycle suivant, HideFileExt=1 (override `off` bat le défaut
   Broadcast `on` pour cette clé) ; les postes hors du parc gardent le défaut.

### Scénario 27.12.4 — Retirer un override → re-convergence au DÉFAUT (lab Windows — ACTION HUMAINE Henri)

1. Sur le parc du scénario 27.12.3 : action « Retirer » sur l'override.
2. Au cycle suivant le poste RE-CONVERGE vers le défaut (HideFileExt=0) — « retirer »
   ≠ « cesser de gérer » (D4). La capacité reste diffusée.

### Scénario 27.12.5 — Capacité on-only : override `off` cesse de gérer la clé (lab Windows — ACTION HUMAINE Henri)

1. Pour une capacité on-only (map `{"on":…}` sans `off`, ex. `windows_copilot_off`),
   un override de parc vers `off` n'émet AUCUNE clé pour ce parc (cesser de gérer) — la
   valeur en place côté poste n'est plus réimposée par l'agent.

### Scénario 27.12.6 — Page serveur : valeur par défaut + gel (navigateur — ACTION HUMAINE Henri)

1. `/admin/settings/capabilities` (Gate `server.admin`) : éditer le défaut diffusé d'une
   capacité (le contrôle s'adapte au `value_type` ; validation serveur des valeurs ;
   confirmation explicite si `warning`, ex. UAC). Le défaut s'applique à tous les parcs
   sans override. Le toggle « Gelé » bloque l'ajout de NOUVEAUX overrides sans couper la
   diffusion. La page liste le catalogue complet ; mention « les capacités non listées
   appliquent leur valeur par défaut ».

### Scénario 27.12.7 — Posture UAC sûre + warning conservé (navigateur + lab — ACTION HUMAINE Henri)

1. `uac_enabled` défaut `on` (EnableLUA=1, UAC ACTIVÉ) ; éditer le défaut OU poser un
   override `off` exige de cocher la confirmation du warning (sécurité). Désactiver l'UAC
   est un geste délibéré (override de parc), jamais le défaut diffusé.

### Scénario 27.12.8 — Bundle WindowsUpdate à compléter (revue de code — ACTION HUMAINE Henri)

1. La capacité `windows_updates_managed` est seedée avec une transcription **PARTIELLE**
   des clés Windows Update / AU (la source autoritaire `se4_windows-update-ON/Machine/Registry.pol`
   est sur la VM, inaccessible au worktree). **Compléter le bundle** (≈34 clés) dans la
   `spec` de la projection (`capability_projections`) ou via une migration de données
   additionnelle, depuis la source VM. Le seed est idempotent (`updateOrInsert`).

### Checklist rapide (Story 27.12)

- [ ] 27.12.1 — Providers capability-first enregistrés ; modèle/tables registry droppés ;
      `/state` n'émet QUE `{hive,path,name,type,value}` (zéro id/key/spec).
- [ ] 27.12.2 — Capacité active diffusée par défaut (Broadcast) sur toute la flotte.
- [ ] 27.12.3 — Override de parc bat le défaut pour la clé (override de VALEUR de capacité).
- [ ] 27.12.4 — « Retirer » l'override → re-convergence au défaut (pas « cesser de gérer »).
- [ ] 27.12.5 — Capacité on-only + override `off` → clé non émise (cesser de gérer).
- [ ] 27.12.6 — Page serveur `/admin/settings/capabilities` : défaut + gel + validation +
      warning (Gate `server.admin`).
- [ ] 27.12.7 — UAC défaut `on` (posture sûre) + warning à confirmer.
- [ ] 27.12.8 — Bundle WindowsUpdate complété depuis la source VM (transcription partielle
      au seed).
- [ ] Contrat & agent INCHANGÉS : `ContractV1Test` vert sans modif ; `go test ./shared/...`
      + cross-compile `GOOS=windows` verts ; aucun fichier Go modifié.

## Story 35.1 — Verbe `ensure` : suppression de valeurs registre (socle delete)

Le contrat `registry` gagne le champ optionnel `ensure ∈ present|absent` (§7.1, additif D1 :
absence = `present`, items d'écriture byte-identiques). Un item 4 clés
`{hive, path, name, ensure:"absent"}` fait SUPPRIMER la valeur nommée par l'agent (2.3.0)
— jamais la clé-conteneur. Marqueur d'authoring `'off' => {"$ensure": "absent"}` dans les
maps de `spec` ; retrofit des deux capacités on-only (`llmnr_disabled`,
`windows_updates_managed`) qui exposent désormais un vrai « off » par suppression
(migration `2026_07_03_100000`). Trois régimes coexistent : écrire / supprimer / ne pas
gérer (sentinelle UNMANAGED intacte).

### Scénario 35.1.1 — Migration de retrofit sur /vm (VM — ACTION HUMAINE Henri)

1. `php artisan migrate` sur /vm (les migrations ne sont PAS auto-appliquées).
2. `/admin/settings/capabilities` : `llmnr_disabled` et `windows_updates_managed` proposent
   désormais « Désactivé (clés supprimées) » en plus de leur état géré — le libellé n'est
   PAS « Non géré » (réservé à la sentinelle des capacités opt-in).

### Scénario 35.1.2 — Payload `/state` : item de suppression 4 clés (curl VM — ACTION HUMAINE Henri)

1. Poser un override `off` sur `llmnr_disabled` pour un parc de test.
2. `GET /api/v1/agent/state` (token d'un poste du parc) : la portée `machine` porte pour
   `EnableMulticast` et `NodeType` des items `{"hive","path","name","ensure":"absent"}` —
   EXACTEMENT 4 clés, ni `type` ni `value`, aucun id de capacité.
3. Les items d'écriture restent EXACTEMENT 5 clés — `ensure:"present"` n'apparaît JAMAIS
   dans le payload (byte-identité anti-drift de flotte).

### Scénario 35.1.3 — Convergence delete + re-drift STRICT (lab Windows — ACTION HUMAINE Henri)

1. Sur un poste du parc avec l'agent **2.3.0 publié** : au cycle suivant, les valeurs
   `EnableMulticast` (HKLM\SOFTWARE\Policies\Microsoft\Windows NT\DNSClient) et `NodeType`
   (HKLM\SYSTEM\CurrentControlSet\Services\NetBT\Parameters) sont SUPPRIMÉES ; le rapport
   passe `drift` (suppression appliquée) puis `compliant` aux cycles suivants.
2. La clé-conteneur (`DNSClient`, `Parameters`) existe toujours — seules les valeurs
   nommées disparaissent (les valeurs voisines non gérées sont intactes).
3. Recréer manuellement `EnableMulticast` (regedit) : au cycle suivant, re-`drift` +
   re-suppression (policy STRICT, pas de tolérance).
4. Repasser l'override à l'état géré (`on`) : les valeurs sont réécrites (l'item
   d'écriture bat l'item absent par la précédence de compilation existante).

### Scénario 35.1.4 — Suppression HKCU : rafraîchissement shell (lab Windows — ACTION HUMAINE Henri)

1. Sur une capacité HKCU à marqueur (post-35.1, ex. futur retrofit session) : une
   suppression EFFECTIVE d'une valeur HKCU déclenche SHChangeNotify (même gate que
   l'écriture — changement effectif seulement, pas de « flicker » au régime stable).

### Scénario 35.1.5 — Piège binaire antérieur (lab Windows — ACTION HUMAINE Henri)

1. Sur un poste resté en agent ≤ 2.2.20 : un item `ensure:"absent"` fait échouer le parse
   du type `registry` → rapport `{status: error}` ISOLÉ sur ce type (les autres types
   convergent). Ce n'est PAS un bug : publier la release 2.3.0 (update.sh ne publie
   jamais seul — amorçage manuel).

### Checklist rapide (Story 35.1)

- [ ] 35.1.1 — Migration retrofit jouée sur /vm ; les 2 capacités exposent le off
      « Désactivé (clés supprimées) » (jamais « Non géré »).
- [ ] 35.1.2 — `/state` : item absent = 4 clés exactes ; items d'écriture = 5 clés,
      jamais `ensure:"present"`.
- [ ] 35.1.3 — Delete effectif au poste, clé-conteneur intacte, re-drift STRICT à la
      réapparition, retour `on` = réécriture.
- [ ] 35.1.4 — Suppression HKCU effective ⇒ rafraîchissement shell (0 op = 0 notification).
- [ ] 35.1.5 — Agent antérieur : `{status: error}` isolé sur `registry` → publier 2.3.0.
- [ ] Golden : `state.v1.json` +1 item absent machine, hashes figés JUMEAUX PHP↔Go
      recalculés ; `report.v1.json` INCHANGÉ (le rapport ne porte pas de payload).

## Story 35.2 — Type `registry_list` : listes à sous-clés indexées `\N`

**Périmètre** : nouveau type de contrat `registry_list` (§7.6, ajout ADDITIF D1) — listes
registre à sous-valeurs indexées `\1..\N`. L'agent POSSÈDE la clé-conteneur (D3) : il
écrit les valeurs `1..N` dans l'ordre et supprime toute autre valeur AU NOM NUMÉRIQUE
(canon strconv strict, `"01" ≠ "1"`) — jamais les valeurs non numériques, jamais la
clé-conteneur ; liste vide = purge. Deux providers serveur (HKLM→machine, HKCU→session),
`exclusiveKey = {hive|path}` : la maille la plus spécifique gagne le conteneur ENTIER
(jamais d'union), StateCompiler INTOUCHÉ (D2). Seed : `pix_extension_forced` (Forcelist
Chrome/Edge, machine) + `blocked_executables` (première capacité BI-PROJECTION D5 : flag
`DisallowRun` registry + entrées registry_list, session, cible override UserGroup élèves).
Bonus contrat : `name: ""` accepté sur les items `registry` (valeur PAR DÉFAUT d'une clé,
besoin 35.5). Agent bumpé **2.4.0**.

### Scénario 35.2.1 — Migration seed sur /vm (VM — ACTION HUMAINE Henri)

1. `php artisan migrate` sur /vm (migration `2026_07_03_110000_seed_capabilities_registry_list_lot`,
   jamais auto-appliquée).
2. `/admin/settings/capabilities` : `pix_extension_forced` (Non géré / Forcée) et
   `blocked_executables` (Non géré / Activé / Désactivé (valeurs supprimées)) apparaissent —
   opt-in (défaut « Non géré » = rien en broadcast).

### Scénario 35.2.2 — Payload `/state` : conteneur 4 clés (curl VM — ACTION HUMAINE Henri)

1. Poser un override `on` sur `pix_extension_forced` pour un parc de test.
2. `GET /api/v1/agent/state` (token d'un poste du parc) : la portée `machine` porte DEUX
   items `registry_list` `{"hive","path","entry_type","values"}` — EXACTEMENT 4 clés,
   jamais de `name`, jamais d'id de capacité :
   - Chrome `SOFTWARE\Policies\Google\Chrome\ExtensionInstallForcelist`,
     `values = ["pgpjajcmfbfdmcgjlbiengidaknopaok"]` (id SEUL, iso-GPO CD95) ;
   - Edge `…\Microsoft\Edge\ExtensionInstallForcelist`,
     `values = ["pgpjajcmfbfdmcgjlbiengidaknopaok;https://clients2.google.com/service/update2/crx"]`.
3. Retirer l'override : les items disparaissent au state suivant (sentinelle unmanaged).

### Scénario 35.2.3 — Convergence de conteneur + réconciliation D3 (lab Windows — ACTION HUMAINE Henri)

1. Poste avec agent **2.4.0 publié**, override pix `on` : au cycle suivant, les valeurs
   `1` apparaissent sous les deux clés Forcelist (HKLM) ; rapport `drift` puis `compliant`.
2. Ajouter à la main (regedit) une valeur `2 = "rogue"` et une valeur `01 = "dup"` sous la
   clé Chrome : au cycle suivant, les DEUX sont supprimées (noms numériques hors canon),
   re-`drift` puis `compliant` — policy STRICT.
3. Ajouter une valeur NON numérique (ex. `Comment = "x"`) dans la même clé : elle SURVIT
   à tous les cycles (jamais touchée) ; la clé-conteneur n'est jamais supprimée.
4. Chrome/Edge : l'extension Pix s'installe de force (chrome://extensions — « installée
   par votre administrateur »).

### Scénario 35.2.4 — blocked_executables bi-projection : on/off élèves (lab Windows — ACTION HUMAINE Henri)

1. Armer `blocked_executables = on` en override sur un UserGroup de test (donnée
   `capability_assignments` — le geste UI arrive en 35.4).
2. Session d'un membre du groupe : le compagnon écrit le flag
   `HKCU\Software\Microsoft\Windows\CurrentVersion\Policies\Explorer!DisallowRun = 1`
   (type `registry`) ET les entrées `…\Policies\Explorer\DisallowRun\1..5`
   (powershell.exe, powershell_ise.exe, pwsh.exe, mstsc.exe, cmd.exe — type `registry_list`).
3. ⚠️ Effet au LOGON SUIVANT (DisallowRun est lu par l'Explorer au logon — mémoire
   projet) : après relogon, cmd.exe/powershell.exe lancés depuis l'Explorer sont refusés
   (« restrictions en vigueur ») ; les scripts .bat restent exécutables (iso-intention
   CD95 : cmd.exe remplace DisableCMD).
4. Passer l'override à `off` : flag SUPPRIMÉ (ensure:absent) + entrées 1..5 PURGÉES
   (values: []) — après relogon, tout relance normalement. `unmanaged` : plus rien n'est
   émis, l'état en place n'est plus géré.

### Scénario 35.2.5 — Piège binaire antérieur : SILENCE (lab Windows — ACTION HUMAINE Henri)

1. Sur un poste resté en agent ≤ 2.3.0 : un item `registry_list` est ignoré EN SILENCE
   (contrat §8 — AUCUN statut au rapport, AUCUNE erreur visible). Symptôme : « réglage
   sans effet, zéro erreur » — plus sournois que 35.1 (qui rendait un `error` visible).
2. Publier la release **2.4.0** (update.sh ne publie JAMAIS seul) puis re-vérifier : le
   type apparaît au rapport (`agent_resource_states(poste, 'registry_list')`, une ligne —
   dual-scope fusionné par type, pire statut).

### Scénario 35.2.6 — Garde-fou d'authoring + name "" (revue de code + tests)

1. `CapabilitySpecCollisionGuard` : une clé-conteneur registry_list ciblée AUSSI par un
   scalaire registry ⇒ violation explicite (capacités + conteneur nommés) — invariant
   `no_container_is_targeted_by_both_registry_scalar_and_registry_list` sur les données
   seedées ; le couple parent/enfant de blocked_executables est prouvé NON-collision.
2. `name: ""` (valeur par défaut d'une clé, §7.1) : accepté par `parseRegistrySpec`
   (la clé `name` doit être PRÉSENTE ; absence = enveloppe invalide) et par le provider
   PHP — Get/Set/DeleteValue("") ciblent nativement `(Default)`. Consommé par 35.5.

### Checklist rapide (Story 35.2)

- [ ] 35.2.1 — Migration seed jouée sur /vm ; les 2 capacités visibles, opt-in.
- [ ] 35.2.2 — `/state` : conteneurs 4 clés exactes (Chrome id seul, Edge id;url).
- [ ] 35.2.3 — Convergence 1..N ordonnée ; surnuméraires + hors-canon supprimés ;
      non-numériques et clé-conteneur INTACTES ; extension Pix forcée.
- [ ] 35.2.4 — Bi-projection on/off élèves : flag + 5 entrées ; effet au relogon ;
      off = flag supprimé + entrées purgées.
- [ ] 35.2.5 — Binaire ≤ 2.3.0 : SILENCE (pas d'erreur) → release 2.4.0 publiée.
- [ ] 35.2.6 — Garde-fou collision vert sur le catalogue ; name "" accepté bout en bout.
- [ ] Golden : `state.v1.json` +1 item registry_list machine, hashes figés JUMEAUX
      PHP↔Go recalculés (`fe8eb6ea…`) ; `report.v1.json` INCHANGÉ (aucun payload au
      rapport ; type accepté via Rule::in(RESOURCE_TYPES)).

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- Story 35.5 — Capacité `photo_viewer_restored` (seed GATÉ inactif)        -->
<!-- ══════════════════════════════════════════════════════════════════════ -->

## Story 35.5 — Visionneuse de photos Windows (`photo_viewer_restored`)

Dernière brique de la GPO CD95 « Ajustement_Photo » : le RÉENREGISTREMENT de la
Visionneuse de photos Windows (rendre l'app existante invocable) devient une capacité
`registry` pure — 4 clés HKCR routées `HKCU\Software\Classes` (portée Session), iso-GPO à
l'octet près (migration `2026_07_03_130000`). **Zéro évolution moteur, zéro UI.**

**GATE D'HONNÊTETÉ — la capacité est seedée `is_active = false`.** Les deux clés
`…\shell\open\command` et `…\shell\print\command` écrivent la valeur PAR DÉFAUT de la clé
(`name == ""`, iso `Registry.xml` source) — c'est ce que lit le shell Windows. Or l'agent
actuel (`parseRegistrySpec`) rejette `name == ""` (garde AVANT la branche `ensure`, pour
l'écriture comme la suppression). Une capacité ARMÉE écrirait donc les 2 `Clsid` mais pas
les 2 `command` → nœud à moitié enregistré, PIRE que rien. La donnée est donc seedée
COMPLÈTE et FIDÈLE mais INACTIVE : invisible des onglets d'armement, grisée dans les
réglages parc-defaults, ignorée par le provider (`where('is_active', true)`) → **rien n'est
émis, golden files strictement intacts.** L'activation est gated par une micro-évolution
agent hors story (« `name: ""` = valeur par défaut de la clé »).

> ⚠️ **Tant que le gate est posé, il n'y a RIEN d'armable à scénariser au poste.** Les
> scénarios ci-dessous sont donc (a) une vérification de la DONNÉE seedée côté serveur
> (jouables tout de suite) et (b) le scénario poste DIFFÉRÉ à la story d'activation
> (`is_active = true`) — reproduits ici pour que l'activation soit « QA-ready » sans
> re-cadrage.

### Scénario 35.5.1 — Migration de seed sur /vm (VM — ACTION HUMAINE Henri)

1. `php artisan migrate` sur /vm (les migrations ne sont PAS auto-appliquées ; AUCUNE
   release agent à publier — zéro modif `agent/**`).
2. `/admin/settings/capabilities` (ou parc-defaults) : `photo_viewer_restored` apparaît
   **grisée / inactive** (opacity-50), ABSENTE des onglets d'armement des parcs — c'est le
   comportement attendu du gate (mécanique `is_active` existante, aucun code neuf).
3. Sa `description` énonce les 3 idées : réenregistre la visionneuse, ne choisit PAS l'app
   par extension (Associations), inactive tant que l'agent ne sait pas écrire la valeur par
   défaut d'une clé.

### Scénario 35.5.2 — Gate prouvé : rien n'est émis même armé (serveur — jouable tout de suite)

1. Poser (en base) un override de parc `on` sur `photo_viewer_restored` pour un poste de
   test, puis `GET /api/v1/agent/state` (token du poste) : **AUCUN item** pour cette
   capacité dans la portée `session` (le filtre `is_active` du provider est le gate).
2. En broadcast (défaut `unmanaged`), rien non plus. Golden `state.v1.json` / `report.v1.json`
   et `FROZEN_STATE_HASH` INCHANGÉS. Couvert automatiquement par
   `photo_viewer_restored_is_gated_inactive_until_agent_supports_default_value_names`.

### Scénario 35.5.3 — Donnée iso-GPO (serveur — jouable tout de suite)

1. La projection `windows`/`registry` porte EXACTEMENT 4 clés HKCU :
   - `…\shell\open\command` et `…\shell\print\command` : `name = ""` (valeur par défaut),
     `REG_EXPAND_SZ`, commande `…rundll32.exe "…PhotoViewer.dll", ImageView_Fullscreen %1`
     (quirk GPO : `ImageView_Fullscreen` sur print AUSSI, PAS `ImageView_PrintTo`) ;
   - `…\shell\open\DropTarget` et `…\shell\print\DropTarget` : `name = "Clsid"`, `REG_SZ`,
     deux GUID **DISTINCTS** (open `{FFE2A43C-…}` ≠ print `{60fd46de-…}`).
2. Chaque clé porte `'off' => {"$ensure":"absent"}` (vrai off par suppression, marqueur
   35.1). Couvert par `photo_viewer_restored_is_seeded_iso_gpo_cd95_with_four_hkcr_keys_routed_hkcu`
   et `photo_viewer_restored_emits_session_items_via_the_real_provider_once_activated`
   (ce dernier SIMULE le flip `is_active=true` pour prouver la chaîne provider de bout en
   bout : `on` → 4 écritures HKCU 5 clés ; `off` → 4 suppressions 4 clés ; provider machine
   muet — aucune clé HKLM).

### Scénario 35.5.4 — Post-activation au poste (lab Windows — DIFFÉRÉ à la story d'activation)

> À NE JOUER QU'APRÈS le flip `is_active=true` ET la micro-évolution agent « `name:""` =
> valeur par défaut » publiée (nouvelle release agent). Tant que le gate est posé, ce
> scénario n'est PAS applicable.

1. Poste avec l'agent supportant `name == ""` + capacité activée + override parc `on` : au
   logon suivant, la Visionneuse de photos Windows réapparaît dans « Ouvrir avec » avec des
   commandes open/print FONCTIONNELLES (les 4 clés HKCU\Software\Classes sont écrites).
2. Override `off` : les 4 valeurs sont supprimées (désenregistrement), Windows reprend son
   état ; la clé-conteneur reste, seules les valeurs nommées disparaissent.
3. **Limite de périmètre à vérifier** : la capacité RÉENREGISTRE la visionneuse mais ne
   modifie PAS `UserChoice` — le choix effectif de l'app par extension (`.jpg`, `.png`)
   reste géré par le composer d'associations (27.11), HORS de cette capacité. La visionneuse
   reste EXCLUE du catalogue `NativeApplicationSeeder` (curation inchangée).

### Checklist rapide (Story 35.5)

- [ ] 35.5.1 — Migration seed jouée sur /vm ; capacité VISIBLE mais grisée/inactive,
      absente des onglets d'armement ; description = 3 idées.
- [ ] 35.5.2 — Armée `on` par override, `/state` n'émet RIEN (gate `is_active`) ; golden
      + `FROZEN_STATE_HASH` intacts.
- [ ] 35.5.3 — Donnée iso-GPO : 4 clés HKCU, 2 command `name=""` REG_EXPAND_SZ
      (`ImageView_Fullscreen` × 2), 2 Clsid REG_SZ DISTINCTS ; off = marqueur `$ensure`.
- [ ] 35.5.4 — (DIFFÉRÉ activation) réenregistrement fonctionnel au logon, off = suppression,
      `UserChoice` NON touché (limite 27.11), exclusion `NativeApplicationSeeder` tenue.

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- Story 35.3 — Ruche HKU : écriture SYSTEM des ruches utilisateur          -->
<!-- ══════════════════════════════════════════════════════════════════════ -->

## Story 35.3 — Ruche `HKU` : écriture SYSTEM des ruches utilisateur

**Périmètre** : `HKU` devient la TROISIÈME valeur admise du champ `hive` des items
`registry` (contrat §7.1 — une VALEUR de domaine, pas un champ ni un type : golden et
hashes figés INCHANGÉS). Un item `hive: 'HKU'` est émis par le provider MACHINE seul
(appliqué par le service SYSTEM) : le handler le FAN-OUT en interne vers `HKU\.DEFAULT`
(le profil lu par l'écran de logon) + chaque ruche utilisateur CHARGÉE (`HKU\<SID>`,
`S-1-5-21-*` hors jumelles `_Classes`), énumérées à CHAQUE cycle via le nouvel op requis
`RegistryOps.UserHives`. Drift AGRÉGÉ (une ruche divergente ⇒ `drift` du type),
idempotence PAR CIBLE, `ensure:"absent"` supprime dans TOUTES les ruches. Pas de ciblage
par utilisateur (structurel : le service fetch sans `?user`). `registry_list` sur HKU
HORS scope (refusé par le guard). Migration de complément : `numlock_on_logon` gagne la
clé HKU miroir (le numlock vaut aussi à l'écran de logon — exclusion du palier A levée).
Agent bumpé **2.5.0**.

### Scénario 35.3.1 — ⚠️ ORDRE DE DÉPLOIEMENT : release AVANT migration (VM — ACTION HUMAINE Henri)

1. **PUBLIER d'abord la release agent 2.5.0** (update.sh ne publie JAMAIS seul) et
   attendre que la flotte l'ait remontée (version rapportée au check-in fait foi).
2. **PUIS** `php artisan migrate` sur /vm (migration
   `2026_07_03_160000_retrofit_numlock_hku_logon_screen`, jamais auto-appliquée).
3. POURQUOI cet ordre est NON NÉGOCIABLE : `numlock_on_logon` est en broadcast `on` →
   l'item HKU part à la flotte ENTIÈRE dès la migration. Un binaire ≤ 2.4.1 PARSE l'item
   HKU puis `rootKey()` le refuse à la première lecture → `Test` en erreur →
   `{status: error}` pour le type `registry` machine ENTIER, SANS Apply : TOUTES les clés
   HKLM du poste cessent de converger (dérives non corrigées) tant que l'item est au
   state. Symptôme : `agent_resource_states(poste, 'registry') = error`, detail « ruche
   de registre non supportée: "HKU" ». Remède : publier 2.5.0 (l'item redevient
   applicable au cycle suivant, aucune donnée à corriger).

### Scénario 35.3.2 — Payload `/state` : item HKU en portée machine (curl VM — ACTION HUMAINE Henri)

1. `GET /api/v1/agent/state` (token machine, SANS `?user`) : la portée `machine` porte
   l'item 5 clés `{"hive":"HKU","path":"Control Panel\\Keyboard","name":
   "InitialKeyboardIndicators","type":"REG_SZ","value":"2"}` — path SANS préfixe
   `.DEFAULT\` (c'est le handler qui préfixe chaque cible), aucun id de capacité.
2. La portée `session` (`?user=<login>`) porte toujours l'item HKCU jumeau (`'2'`) —
   les deux identités `{hive|path|name}` coexistent, valeurs CONSISTANTES.
3. Le hash de l'item HKU est STABLE quel que soit le nombre de sessions ouvertes sur le
   poste (le fan-out est interne à l'agent, invisible du wire).

### Scénario 35.3.3 — Numlock à l'écran de logon + fan-out (lab Windows — ACTION HUMAINE Henri)

1. Poste avec agent **2.5.0 publié** + migration jouée : au cycle suivant, regedit
   affiche `HKEY_USERS\.DEFAULT\Control Panel\Keyboard\InitialKeyboardIndicators = "2"`
   ET la même valeur sous chaque `HKEY_USERS\S-1-5-21-…` de session ouverte (PAS sous
   les jumelles `_Classes`, PAS sous S-1-5-18/19/20).
2. Redémarrer : le pavé numérique est ACTIF à l'écran de logon (LogonUI lit `.DEFAULT`
   à chaque affichage — c'est l'effet visé, exclu du palier A).
3. Modifier à la main la valeur dans UNE ruche `S-1-5-21-…` (regedit) : au cycle
   suivant, rapport `drift` (agrégé) puis re-`compliant` — SEULE la ruche modifiée est
   réécrite (idempotence par cible, vérifiable au log agent).

### Scénario 35.3.4 — Session ouverte après coup = cycle suivant (lab Windows — ACTION HUMAINE Henri)

1. Poste convergé (compliant), AUCUNE session ouverte : seule `.DEFAULT` porte la valeur.
2. Ouvrir une session (la ruche `HKU\<SID>` se charge) : au CYCLE SUIVANT (pas
   instantanément — level-triggered, énumération par appel), rapport `drift` puis la
   valeur apparaît dans la ruche du user connecté ; re-`compliant`.
3. Fermer la session (ruche déchargée) : aucun drift fantôme au cycle suivant (la cible
   s'évapore de l'énumération) ; si le logoff tombe ENTRE l'énumération et l'op, l'erreur
   est isolée et bénigne (re-résolue au cycle suivant).

### Scénario 35.3.5 — Garde-fous d'authoring (revue de code + tests)

1. `CapabilitySpecCollisionGuard` borne les ruches : `registry` ∈ {HKLM, HKCU, HKU} ;
   `registry_list` ∈ {HKLM, HKCU} — un conteneur `hive: HKU` = violation NOMMÉE (hors
   scope 35.3) ; une ruche inconnue (`HKX`) = refusée (clé silencieusement morte sinon).
   Invariant vert sur le catalogue réellement seedé.
2. La double-clé HKU + HKCU sur le même `{path|name}` (numlock) est prouvée
   NON-violation — cas nominal. Discipline documentée (guard + §7.1) : pas de ciblage
   user sur une capacité à clé HKU, maps jumelles VALEUR-CONSISTANTES (sinon compagnon
   et SYSTEM se réécrivent mutuellement à chaque cycle).
3. « Pas de ciblage par utilisateur » : test de compilation machine-only — un override
   UserGroup posé en base n'atteint JAMAIS l'item HKU (contexte machine sans user).

### Checklist rapide (Story 35.3)

- [ ] 35.3.1 — Release 2.5.0 publiée AVANT la migration (ordre vérifié) ; migration
      jouée sur /vm ; aucun poste en `error` registry.
- [ ] 35.3.2 — `/state` machine : item HKU 5 clés, path sans `.DEFAULT`, hash stable ;
      session : item HKCU jumeau consistant.
- [ ] 35.3.3 — Numlock actif à l'écran de logon ; fan-out visible dans regedit
      (`.DEFAULT` + SID chargés, jamais `_Classes` ni SID de service) ; drift agrégé +
      réécriture de la seule ruche divergente.
- [ ] 35.3.4 — Session ouverte après coup couverte au cycle suivant ; session fermée
      sans drift fantôme.
- [ ] 35.3.5 — Guard : HKU refusé en registry_list, HKX refusé en registry, double-clé
      numlock non-violation ; override UserGroup sans effet sur l'item HKU.
- [ ] Golden : `state.v1.json`, `report.v1.json` et hashes figés jumeaux PHP↔Go
      INCHANGÉS (HKU = valeur de `hive`, pas un champ — rien à figer).

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- Story 36.3 — Lot registre pures Explorateur (zéro moteur)               -->
<!-- ══════════════════════════════════════════════════════════════════════ -->

## Story 36.3 — Lot bibliothèque n°2 : capacités registre pures Explorateur

**Témoin de doctrine de l'Epic 36** : le mécanisme `registry` étant payé (27.12 + Epic 35),
4 capacités supplémentaires (épuration du volet de navigation de l'Explorateur) sont de la
DONNÉE PURE — migration `2026_07_04_100000_seed_capabilities_explorer_lot.php`, **zéro
évolution moteur, zéro UI, zéro bump agent**. Toutes **opt-in** (`default_value = unmanaged`)
⇒ rien n'est émis en broadcast tant qu'aucun override de parc n'est posé (golden/`FROZEN_STATE_HASH`
intacts par construction).

| Capacité | Portée | Clés |
|---|---|---|
| `explorer_sidebar_pins_hidden` | Machine (HKLM) — écart D3 vs « Session » du cadrage epic | 6 × `ThisPCPolicy` (FolderDescriptions par dossier) |
| `quick_access_hidden` | Mixte HKLM+HKCU | `HubMode` (HKLM) + `LaunchTo` + CLSID Accueil Win11 (HKCU) |
| `explorer_gallery_hidden` | Session (HKCU) | CLSID Galerie Win11 |
| `quick_access_history_hidden` | Session (HKCU) | `ShowRecent` + `ShowFrequent` |

> ⚠️ **GATE DE VÉRACITÉ DES CLÉS — BLOQUANT AVANT `migrate` /vm.** Les GUID/paths/valeurs
> ci-dessus sont des **candidates issues du décodage documentaire** (patron `onedrive_hidden`
> + tweaks Windows documentés), **PAS d'une vérification sur poste** : le dev n'a aucun accès
> à un poste Windows lab. Le protocole ci-dessous DOIT être déroulé par Henri (ou l'e2e lab)
> AVANT `php artisan migrate` sur /vm ; toute clé invalidée est retirée de la migration avant
> merge (jamais de clé « au cas où ») ; si toutes les clés d'une capacité tombent, la capacité
> sort du lot.

### Scénario 36.3.1 — Protocole de vérification lab (poste Windows — ACTION HUMAINE Henri, GATE bloquant)

Pour CHAQUE capacité : poser les clés « on », fermer/rouvrir la session (clés HKCU) ou
redémarrer Explorer (`taskkill /f /im explorer.exe & start explorer.exe`), vérifier l'effet ;
poser les valeurs « off », re-vérifier le retour au comportement par défaut. **Les deux faces
doivent être prouvées** (maps symétriques, aucun `$ensure` dans ce lot) :

1. `explorer_sidebar_pins_hidden` — `reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\FolderDescriptions\{GUID}\PropertyBag" /v ThisPCPolicy /t REG_SZ /d Hide /f`
   pour chacun des 6 GUID (Documents `{f42ee2d3-909f-4907-8871-4c22fc0bf756}`, Images
   `{0ddd015d-b06c-45d5-8c4c-f59713854639}`, Musique `{a0c69a99-21c8-4671-8703-7934162fcf1d}`,
   Vidéos `{35286a68-3c57-41a1-bbb1-0eae73d76c95}`, Téléchargements
   `{7d83ee9b-2244-4e70-b1f5-5393042af1e4}`, Bureau `{B4BFCC3A-DB2C-424C-B029-7FE99A87C641}`) :
   le dossier disparaît de « Ce PC » ET du volet ; `/d Show` le réaffiche. Vérifier AUSSI que
   chaque GUID candidat correspond bien au dossier annoncé (`reg query …\{GUID} /v Name` ou
   contenu du `PropertyBag`).
2. `quick_access_hidden` — `reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer" /v HubMode /t REG_DWORD /d 1 /f`
   + `reg add "HKCU\Software\Microsoft\Windows\CurrentVersion\Explorer\Advanced" /v LaunchTo /t REG_DWORD /d 1 /f`
   + `reg add "HKCU\Software\Classes\CLSID\{f874310e-b6b7-47dc-bc84-b9e6b38f5903}" /v System.IsPinnedToNameSpaceTree /t REG_DWORD /d 0 /f` :
   Win10 « Accès rapide » absent du volet, Win11 « Accueil » absent, Explorateur ouvre sur
   « Ce PC ». `off` (0/2/1) restaure — vérifier en particulier que `HubMode=0` restaure
   vraiment (pas la seule suppression de la valeur).
   ⚠️ **PARI LE PLUS FRAGILE DU LOT (review 36.3 #1)** : `HubMode` est très
   largement documenté comme clé **per-user (HKCU)**, or il est seedé ici en
   **HKLM** (face Machine D4). Test DÉTERMINANT : poser `HubMode` en **HKLM
   SEUL** (sans la variante HKCU) et confirmer que « Accès rapide » disparaît
   bien. Si SEUL `HKCU\…\Explorer\Advanced\HubMode` a de l'effet ⇒ **déplacer la
   clé en HKCU dans la migration AVANT merge** (elle bascule alors côté provider
   Session ; le split-provider AC4 reste valide). Ne pas armer la capacité tant
   que ce point n'est pas tranché.
3. `explorer_gallery_hidden` — `reg add "HKCU\Software\Classes\CLSID\{e88865ea-0e1c-4e20-9aa6-edcd0212c87c}" /v System.IsPinnedToNameSpaceTree /t REG_DWORD /d 0 /f` :
   Win11 « Galerie » absente du volet ; `off` (1) la réaffiche ; Win10 aucun effet (assumé).
4. `quick_access_history_hidden` — `reg add "HKCU\Software\Microsoft\Windows\CurrentVersion\Explorer" /v ShowRecent /t REG_DWORD /d 0 /f`
   + idem `ShowFrequent` : Accès rapide/Accueil ne liste plus fichiers récents ni dossiers
   fréquents ; `off` (1/1) restaure.

Issues possibles à consigner : GUID/valeur divergente selon build Windows (corriger la
migration avant merge) ; besoin `Wow6432Node` pour les dialogues 32 bits (extension future) ;
`ShowCloudFilesInQuickAccess` (Win11, extension future) — HORS seed v1.

### Scénario 36.3.2 — Après le gate : migration + armement par override de parc (serveur — jouable après migrate /vm)

1. `php artisan migrate` sur /vm — **UNIQUEMENT après le gate 36.3.1** (jamais auto-appliquée ;
   aucune release agent à publier, l'agent ≥ 2.5.0 sait déjà appliquer ces clés).
2. Les 4 capacités apparaissent dans les onglets d'armement (parc-defaults/`registry-tab`)
   avec l'option par défaut « Non géré » — aucun poste n'est affecté tant qu'aucun override
   n'est posé.
3. Poser un override `on` sur `quick_access_hidden` pour un parc de test → `GET
   /api/v1/agent/state` (token d'un poste du parc) fait apparaître `HubMode` en portée
   `machine` et `LaunchTo` + CLSID Accueil en portée `session`. Retirer l'override → retour au
   silence (défaut `unmanaged`, PAS un `$ensure`).

### Checklist rapide (Story 36.3)

- [ ] 36.3.1 — GATE LAB déroulé par Henri : les 4 capacités × leurs clés vérifiées sur poste
      Windows réel, deux faces on/off prouvées ; toute clé invalidée retirée avant merge.
- [ ] 36.3.2 — Migration jouée sur /vm APRÈS le gate ; capacités visibles dans les onglets
      d'armement, défaut « Non géré » ; override de parc `on`/`off` sur `quick_access_hidden`
      compilé côté Machine (HubMode) ET Session (LaunchTo + CLSID Accueil).
- [ ] Golden : `state.v1.json`, `report.v1.json` et `FROZEN_STATE_HASH`/`frozenStateHash`
      jumeaux PHP↔Go INCHANGÉS (tout opt-in `unmanaged` ⇒ rien en broadcast).
- [ ] Anti-collision : le CLSID OneDrive `{018D5C66-4533-4307-9B53-224DE2ED1FE6}`
      (`onedrive_hidden`) n'apparaît dans AUCUNE clé du lot — verrouillé par test structurel.

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- Story 36.1 — Mécanisme fs_acl : ACE NTFS gérées sur le poste            -->
<!-- ══════════════════════════════════════════════════════════════════════ -->

## Story 36.1 — Mécanisme `fs_acl` : ACE NTFS gérées sur le poste

**Ce que la story livre** : un NOUVEAU type de contrat `fs_acl` (§7.7) + handler
Go `FsAclHandler` (portée MACHINE / service SYSTEM), qui gère des ACE NTFS
explicites par CHIRURGIE DACL (merge `SetNamedSecurityInfo` DACL-only, jamais de
réécriture ; owner/SACL/héritées/tierces intacts). Store « dernier appliqué »
(`C:\ProgramData\SambaEdu\Agent\fsacl-state.json`). Résolution SID par LSA sur le
poste joint. Capacité de preuve : `program_files_browse_denied`.

⚠️ **Résolution SID + jetons + poste joint au domaine = e2e MANUEL** (impossible
à simuler hors lab). Les tests hôte (Go fake + PHPUnit sqlite) couvrent la
convergence, la précédence, le store, les refus et le seed ; les DEUX faces
métier (masquage + lancement) exigent un poste réel.

### Scénario 36.1.1 — ⚠️ Publication AVANT armement (VM — ACTION HUMAINE Henri)

Un binaire ≤ 2.5.0 IGNORE le type `fs_acl` EN SILENCE (contrat §8 — aucun statut,
aucune erreur : « réglage sans effet »). L'ordre publication/migration n'est PAS
critique ici (pas d'effet de bord inter-types, contrairement à HKU 35.3), MAIS
sans publication la capacité est inerte.

1. Publier la release agent **2.6.0** (build + `update.sh` de publication — jamais
   automatique). Vérifier que le manifeste de release expose 2.6.0.
2. Rejouer la migration de seed sur /vm : `php artisan migrate` (jamais
   auto-appliquée — `php artisan migrate:status` d'abord). La capacité
   `program_files_browse_denied` apparaît, `default_value = unmanaged` (inerte
   tant qu'aucun override/valeur n'est armé).

### Scénario 36.1.2 — Payload `/state` : item fs_acl en portée machine (curl VM)

Armer la capacité (valeur `eleves` en broadcast OU override de parc), puis
`curl` le `/state` du poste cible : la portée `machine` porte les items
`{"type":"fs_acl","payload":{"path":"C:\\Program Files","trustee":"Eleves",
"ace_type":"deny","rights":"list_folder","applies_to":"folder_only",
"ensure":"present"}}` (2 chemins). Le `trustee` est un NOM (jamais un SID, jamais
un jeton `@…` brut). Vérifier que `Program Files (x86)` est présent aussi.

### Scénario 36.1.3 — LES DEUX FACES au poste joint (lab Windows — ACTION HUMAINE)

Sur un poste joint au domaine, agent 2.6.0, capacité armée `eleves` :

1. **Masquage** — se connecter en ÉLÈVE, ouvrir l'Explorateur sur
   `C:\Program Files` : le contenu N'EST PLUS énumérable (accès refusé en
   listing). Idem `C:\Program Files (x86)`.
2. **Lancement PRÉSERVÉ** — toujours en élève, lancer une application installée
   sous Program Files via son raccourci (l'exe se lance : traverse/execute
   intact). L'appli se lance AUSSI pour un PROF (le deny ne vise que le trustee
   `Eleves`).
3. **Retrait propre** — basculer la valeur sur `off` (« Parcours autorisé ») :
   au cycle suivant, les ACE gérées sont RETIRÉES, le parcours de Program Files
   est restauré pour l'élève. ⚠️ Ne PAS utiliser « Non géré » (`unmanaged`) pour
   retirer : la sentinelle cesse d'émettre l'item → l'ACE survivrait jusqu'à ce
   qu'un autre item fs_acl déclenche la réconciliation d'orphelins.

### Scénario 36.1.4 — Changement d'audience sans ACE orpheline (lab Windows)

Capacité armée `eleves` (ACE deny Eleves posée), puis bascule vers `tous`
(trustee `Domain Users`) : au cycle suivant, l'ACE Eleves (orpheline du store —
identité différente) est RETIRÉE et l'ACE Domain Users posée. Vérifier dans
l'onglet Sécurité qu'il ne reste PAS deux ACE deny cumulées de SambaEdu.

### Scénario 36.1.5 — Défense en profondeur & refus (revue + lab)

- Le `FsAclAuthoringGuard` refuse à l'authoring : deny sur SYSTEM/Administrators/
  TrustedInstaller/Everyone/Authenticated Users ; deny à héritage descendant sur
  `C:\`, `C:\Windows`, `C:\Program Files`, `C:\Program Files (x86)`,
  `C:\ProgramData` ; enums hors domaine ; path non absolu ; jeton inconnu ; deny
  sans warning. Le `deny list_folder folder_only` sur Program Files reste
  AUTORISÉ (prouvé par test).
- L'agent (défense en profondeur, INDÉPENDANT du serveur) refuse : deny sur SID
  well-known système ⇒ erreur d'item ; chemin inexistant ⇒ erreur (jamais de
  mkdir) ; trustee irrésoluble LSA ⇒ erreur. Les autres items convergent ;
  l'erreur remonte (verdict `error` du type).
- Policy STRICT : ACE gérée supprimée à la main ⇒ `drift` + re-pose au cycle.

### Scénario 36.1.6 — Binaire antérieur silencieux (lab — régression)

Sur un poste resté en 2.5.0, armer la capacité : AUCUN statut fs_acl au rapport,
aucune erreur, aucune ACE posée (« réglage sans effet »). Confirme la nécessité
de publier 2.6.0.

### Checklist rapide (Story 36.1)

- [ ] 36.1.1 — Release 2.6.0 publiée AVANT armement ; migration de seed rejouée
      sur /vm (`migrate:status` d'abord).
- [ ] 36.1.2 — `/state` porte les items fs_acl machine (trustee = NOM, 2 chemins).
- [ ] 36.1.3 — Élève : Program Files non énumérable ET appli lançable ; prof :
      appli lançable ; `off` restaure le parcours.
- [ ] 36.1.4 — Changement d'audience : ancienne ACE retirée, pas de cumul.
- [ ] 36.1.5 — Guard (Q2 + principals système) et refus agent (SID système /
      chemin inexistant / trustee irrésoluble) ; STRICT re-drift.
- [ ] 36.1.6 — Binaire 2.5.0 : type ignoré en silence.
- [ ] Golden : `state.v1.json` +1 item fs_acl machine, `FROZEN_STATE_HASH` PHP =
      `frozenStateHash` Go recalculés à l'identique ; `report.v1.json` INCHANGÉ.

## Story 36.2 — Mécanisme `firewall` (règles pare-feu possédées par groupe)

Deuxième mécanisme HORS-REGISTRE. Capacité de preuve `internet_access` (enum
`unmanaged`/`on`/`off`). `off` ⇒ règle `block out internet any` dans le conteneur
`SambaEdu-Agent`. **Publier la release 2.7.0 AVANT d'armer** (un binaire ≤ 2.6.0
IGNORE le type EN SILENCE) — cette publication livre AUSSI la 2.6.0 fs_acl jamais
publiée. Migration de seed **à rejouer sur /vm** (`migrate:status` d'abord).

### Scénario 36.2.1 — Coupure Internet + LAN préservé (lab Windows, poste joint)

1. Armer `internet_access = off` sur le parc de la salle d'examen. Au cycle
   suivant : `wf.msc` montre la règle **`SambaEdu-Agent: internet-block`** (groupe
   `SambaEdu-Agent`, direction sortante, action bloquer, portée = plages Internet).
2. Depuis le poste : `ping 8.8.8.8` **KO**, HTTP externe **KO** (IPv4, et IPv6 si
   le lab en a). `nslookup`/navigation Internet KO.
3. **Le réseau local RESTE ouvert** : check-in agent OK (le poste rapporte), UI
   SE5 joignable, partages SMB montés OK, DNS local (le DC) répond. C'est le cœur
   de Q3 : couper Internet ne coupe JAMAIS le poste de son serveur.

### Scénario 36.2.2 — Retour `on` sans reboot (lab)

Basculer la valeur sur `on` (« Autorisé ») : au cycle suivant, la règle est
RETIRÉE (même `rule_id` en `ensure:absent`), le groupe `SambaEdu-Agent` est VIDE,
Internet est restauré **SANS reboot**. ⚠️ Ne PAS utiliser « Non géré »
(`unmanaged`) pour rétablir : la sentinelle cesse d'émettre l'item → la règle
`block` survivrait et la salle resterait coupée (le handler n'est plus invoqué).
Remède manuel de secours : supprimer la règle du groupe `SambaEdu-Agent` dans
`wf.msc`.

### Scénario 36.2.3 — 2 cycles stables ⇒ zéro op (anti drift-loop, lab)

Poste armé `off`, stable. Sur DEUX cycles consécutifs : verdict `compliant`,
**zéro op** (aucune règle re-posée). Attrape toute normalisation d'écho imprévue
(le service pare-feu relit un CIDR IPv4 en `adresse/masque` pointé ; la
comparaison canonique par intervalles doit rendre les deux formes équivalentes).

### Scénario 36.2.4 — Règle étrangère au groupe purgée (lab)

Ajouter à la main une règle quelconque **dans le groupe `SambaEdu-Agent`** (le
groupe nous appartient EN ENTIER, D4) : au cycle suivant, elle est SUPPRIMÉE
(`drift` puis purge). À l'inverse, une règle **hors groupe** (Core Networking,
règles Windows, applis tierces) n'est JAMAIS touchée. La politique par défaut et
le service MpsSvc ne sont jamais modifiés.

### Scénario 36.2.5 — Défense en profondeur & refus Q3 (revue + lab)

- Le `FirewallAuthoringGuard` refuse à l'authoring : `block` `explicit` couvrant
  RFC1918/loopback/link-local/ULA ou `/0` — par INTERSECTION mathématique
  (`192.168.0.0/16`, `192.160.0.0/12`, `0.0.0.0/0`, `::/0` refusés) ; enums hors
  domaine ; slug invalide ; `explicit` sans adresses / `internet` avec adresses ;
  `ports` avec `any` ; port hors 1-65535 ; `block` sans warning. `block internet`
  reste AUTORISÉ ; `block explicit` sur adresses PUBLIQUES aussi (échappatoire).
- L'agent (défense en profondeur, INDÉPENDANT du serveur) applique les MÊMES
  plages protégées dans `Test` ET `Apply` : un `block explicit` dangereux ⇒ erreur
  d'item isolée (jamais posée), les autres items convergent.
- **Garde-fou Q5 (`allow` entrant ouvert ⇒ `warning`, décision Henri)** : le
  `FirewallAuthoringGuard` refuse à l'authoring une règle `action: allow` +
  `direction: in` COUVRANT l'Internet ouvert (`remote_scope: internet`, ou
  `explicit` englobant `0.0.0.0/0` / `::/0` — détecté par INTERSECTION, jamais
  textuel) SANS `warning` non vide. Un `allow` entrant sur une plage ÉTROITE
  (host public, /24 privé) ou un `allow out` ne sont PAS concernés. **SERVEUR-only
  (asymétrie assumée vs le refus Q3 `block`)** : le `warning` est une métadonnée
  d'authoring qui n'atteint jamais le payload (invariant 27.12) — l'agent ne le
  voit pas, donc un refus agent miroir est INEXPRIMABLE (il casserait les `allow`
  ouverts légitimes, ceux qui ONT un warning authoré). Le refus Q3 `block`, lui,
  reste duplicable côté agent car il ne dépend QUE des adresses du payload.

### Scénario 36.2.6 — Binaire antérieur silencieux (lab — régression)

Sur un poste resté en ≤ 2.6.0, armer `internet_access = off` : AUCUN statut
firewall au rapport, aucune règle posée, Internet toujours accessible (« salle
coupée sans effet »). Confirme la nécessité de publier 2.7.0.

### Note IPv6 lab

Si le lab n'a pas d'IPv6, le volet IPv6 de la traduction `internet` (`2000::/3`)
reste validé par le test Go (`TestFirewallInternetTranslationIsFrozen`, chaîne
EXACTE) : le poste posera quand même la plage IPv6, inerte faute de trafic v6.

### Checklist rapide (Story 36.2)

- [ ] 36.2.0 — Release 2.7.0 publiée AVANT armement (livre AUSSI 2.6.0 fs_acl) ;
      migration de seed rejouée sur /vm (`migrate:status` d'abord).
- [ ] 36.2.1 — `off` : ping/HTTP externes KO (IPv4, IPv6 si dispo) ; check-in +
      SE5 + partages SMB + DNS local OK ; `wf.msc` montre `SambaEdu-Agent:
      internet-block`.
- [ ] 36.2.2 — Retour `on` : Internet restauré SANS reboot, groupe vide ; jamais
      via `unmanaged`.
- [ ] 36.2.3 — 2 cycles stables ⇒ `compliant`, zéro op (écho normalisé).
- [ ] 36.2.4 — Règle étrangère AU groupe purgée ; règles hors groupe + politique
      par défaut + service intacts.
- [ ] 36.2.5 — Guard Q3 (intersection) + refus agent Test/Apply ; block internet
      et block explicit public autorisés. Guard Q5 : `allow in` ouvert sur
      Internet (internet ou explicit englobant /0) sans warning REFUSÉ (SERVEUR-only,
      pas de miroir agent) ; allow in étroit + allow out non concernés.
- [ ] 36.2.6 — Binaire ≤ 2.6.0 : type ignoré en silence.
- [ ] Golden : `state.v1.json` +1 item firewall machine, `FROZEN_STATE_HASH` PHP =
      `frozenStateHash` Go recalculés à l'identique ; `report.v1.json` INCHANGÉ.
## Story 36.4 — Règles d'accès aux dossiers (formulaire, D8)

Seconde surface d'authoring du mécanisme `fs_acl` (36.1) : le référent numérique
crée des règles « interdire/autoriser CE dossier à CE groupe » via un formulaire
100 % métier (`/app/folder-rules`). AUCUN changement agent/contrat/golden (la
release 2.6.0 de 36.1 porte déjà le handler). Provider `fs_acl` **bi-alimenté**
(capacités + règles, UN seul provider compilé — arbitrage règle↔capacité par le
compilateur).

### Prérequis d'exploitation (/vm, AVANT le lab)

- **Migrations à rejouer** (`migrate:status` d'abord — mémoire
  `vm_migrations_not_auto_applied`) : `folder_access_rules` +
  `folder_access_rule_assignables` + `folder_access_rule_audit_logs`.
- **Reseed `PermissionSeeder`** sur /vm (sinon **403 même pour un refnum** : les
  permissions `folderrule.view`/`folderrule.manage` n'existent pas). Le refnum et
  l'admin machines les reçoivent ; superadmin auto.
- **`route:cache`** après ajout des routes réelles `/app/folder-rules[/{id}]`
  (mémoire `route_cache_vm_ephemeral_test_routes`).
- **AUCUNE publication agent** (2.6.0 de 36.1 suffit) — MAIS sans release 2.6.0
  publiée, les items `fs_acl` sont ignorés EN SILENCE par un binaire ≤ 2.5.0.

### Scénario 36.4.1 — Règle deny sur un dossier arbitraire (lab, poste joint)

1. En UI, créer une règle « Interdire » sur `D:\Ressources` (niveau Parcourir,
   portée « Ce dossier seul ») pour un GROUPE RÉEL (une classe). Acquitter
   l'encart d'implications (obligatoire pour un deny).
2. Sur la page de la règle, assigner le PARC du poste de test, puis activer.
3. Sur un poste joint au domaine, au prochain cycle : l'Explorateur REFUSE
   l'ouverture de `D:\Ressources` à un MEMBRE du groupe ; l'accès reste INTACT
   pour les autres (un prof, un autre élève hors groupe).
4. Vérifier au passage la **résolution du trustee dérivé (D9)** : le payload
   `trustee` doit être le CN AD du groupe (`Classe_<nom>`), PAS le nom nu folded —
   sinon l'agent tombe en erreur d'item tracée (jamais silencieux).

### Scénario 36.4.2 — Off réel puis suppression (retrait honnête, D3)

1. **Désactiver** la règle (bouton « Désactiver ») : au prochain cycle, l'ACE est
   RETIRÉE (la règle émet toujours ses items, en `ensure:absent`) — l'accès est
   restauré. Ce n'est PAS un simple oubli : le type reste dans le state, le
   handler retire proprement.
2. La suppression d'une règle ACTIVE est REFUSÉE (toast : « Désactivez d'abord la
   règle… »). Une fois INACTIVE, la règle est supprimable (cascade pivot).

### Scénario 36.4.3 — Recouvrement de capacité (avertissement non bloquant)

Créer une règle dont l'identité `{path|trustee|ace_type}` recouvre une capacité
catalogue ACTIVE (ex. `program_files_browse_denied` avec `@eleves`) : un
`toastWarning` NON bloquant nomme la capacité. La création reste possible ; en cas
de conflit réel, la maille la plus spécifique / la plus récente gagne (arbitrage
compilateur, un seul provider).

### Scénario 36.4.4 — Délégation scopée par parc (anti-piège Gate global)

Un délégué disposant de `folderrule.manage` UNIQUEMENT sur le parc A (délégation
positive scopée, sans droit global) : il PEUT assigner le parc A à une règle, mais
l'assignation du parc B est REFUSÉE (`canOnWorkstationGroup` par parc) ; le picker
de parcs ne propose que ses parcs autorisés. L'admin global voit tout. Chaque
create/update/delete (activation/désactivation et (dé)assignation comprises) écrit
une ligne d'audit append-only (`folder_access_rule_audit_logs`) avec acteur +
snapshots.

### Checklist rapide (Story 36.4)

- [ ] 36.4.1 — Migrations + `PermissionSeeder` rejoués + `route:cache` sur /vm.
- [ ] 36.4.2 — Règle deny sur `D:\Ressources` pour une classe → Explorer refuse
      au membre, intact pour les autres ; trustee = CN AD (pas nom nu).
- [ ] 36.4.3 — Désactivation → ACE retirée (off réel) ; suppression active
      refusée, inactive OK.
- [ ] 36.4.4 — Recouvrement capacité → toastWarning ; groupe sans ad_dn →
      avertissement.
- [ ] 36.4.5 — Délégation scopée : parc A OK, parc B refusé ; audit tracé.
- [ ] Golden : `FROZEN_STATE_HASH` INCHANGÉ (aucune règle en base = byte-identité).

## Story 35.6 — Mécanisme `privilege` (droits de logon LSA `SeDeny*`)

Troisième mécanisme HORS-REGISTRE : le serveur émet des items
`{privilege, accounts}` (contrat §7.9), l'agent SYSTEM réconcilie la liste de
titulaires du privilège EN ENTIER via la LSA (accorde les manquants, révoque
les surnuméraires — CONTENEUR SANS store, iso `firewall`). Capacité de preuve :
`rdp_denied_for_group` (`SeDenyRemoteInteractiveLogonRight`, jeton `@eleves`) —
« RDP refusé aux élèves, autorisé aux profs, sur le MÊME poste ». Enum FERMÉ
SeDeny*-only : tout droit *grant* est refusé serveur ET agent (un grant possédé
en liste entière verrouillerait la machine).

### Prérequis d'exploitation (/vm, AVANT le lab)

- **Migration à rejouer** (`migrate:status` d'abord — mémoire
  `vm_migrations_not_auto_applied`) :
  `2026_07_04_140000_seed_capability_rdp_denied_for_group`.
- **Release agent 2.8.0 à PUBLIER MANUELLEMENT** (update.sh ne publie jamais
  seul) : un binaire ≤ 2.7.0 IGNORE le type `privilege` EN SILENCE (§8 — aucun
  statut, aucune erreur : « RDP toujours ouvert aux élèves, zéro erreur »).
  ⚠️ Les 2.6.0 (`fs_acl`) et 2.7.0 (`firewall`) n'ayant jamais été publiées,
  la 2.8.0 livre les TROIS mécanismes d'un coup.
- Le groupe `Eleves` doit exister dans `user_groups` (sinon le jeton `@eleves`
  est irrésoluble → item non émis + warning serveur, capacité inerte).
- Poste de test JOINT AU DOMAINE (résolution SID + logon RDP impossibles à
  simuler ailleurs), RDP activé (`remote_desktop_enabled` per-parc PAS à off).

### Scénario 35.6.1 — RDP refusé à l'élève, autorisé au prof, sur le MÊME poste (e2e lab)

1. En UI (défauts du parc du poste de test), passer « Ouverture de session RDP
   (droit de logon) » à **« RDP refusé aux élèves »**. Acquitter le warning.
2. Attendre un cycle agent (ou forcer la synchro). Sur le poste, vérifier dans
   `secpol.msc` → Stratégies locales → Attribution des droits utilisateur →
   « Interdire l'ouverture de session par les services Bureau à distance » :
   le groupe `Eleves` y figure.
3. Depuis un AUTRE poste, `mstsc` vers le poste de test avec un compte MEMBRE
   du groupe élèves : la connexion est REFUSÉE (« The connection was denied
   because the user account is not authorized for remote login » /
   « Votre compte n'est pas autorisé… »).
4. Même `mstsc`, compte PROF (hors liste) : la session RDP s'OUVRE normalement,
   sur le MÊME poste. C'est LE critère de la story (per-parc
   `remote_desktop_enabled=off` ne sait pas faire ça).
5. Rapport agent : type `privilege` en `compliant` sur la fiche poste.

### Scénario 35.6.2 — Effet au LOGON SUIVANT (pas de session tuée)

1. Ouvrir une session RDP ÉLÈVE sur le poste AVANT d'armer la capacité.
2. Armer « RDP refusé aux élèves » (35.6.1) : la session élève EN COURS n'est
   PAS coupée (sémantique Windows : les droits de logon sont évalués à
   l'ouverture de session).
3. Fermer la session élève, retenter `mstsc` : REFUSÉ cette fois.

### Scénario 35.6.3 — `off` réel : le droit est retiré, RDP rétabli

1. Passer la capacité à **« RDP autorisé (droit retiré) »** (PAS « Non géré »).
2. Au cycle suivant : `secpol.msc` montre le privilège VIDE (le groupe `Eleves`
   a disparu de la liste). L'élève ré-ouvre une session RDP au logon suivant.
3. Contre-épreuve piège #6 : repasser par « armé » puis « Non géré »
   (sentinelle) — le privilège RESTE peuplé (orphelin assumé, le handler n'est
   plus invoqué) : le retrait propre passe TOUJOURS par « RDP autorisé ».
   Remède manuel : retirer le groupe dans `secpol.msc`.

### Scénario 35.6.4 — Réconciliation de conteneur : titulaire manuel révoqué

1. Capacité armée (`eleves`). Dans `secpol.msc`, ajouter À LA MAIN un autre
   groupe (ex. `Invites`) au privilège « Interdire l'ouverture de session par
   les services Bureau à distance ».
2. Au cycle suivant : le groupe ajouté à la main est RÉVOQUÉ (l'agent possède
   la liste ENTIÈRE — D4), `Eleves` reste seul titulaire ; rapport `drift` au
   cycle de correction puis `compliant`.

### Scénario 35.6.5 — Compte irrésoluble ⇒ item `error`, jamais de deny partiel

1. Fabriquer (SQL, table `capability_projections`) une projection `privilege`
   dont `accounts` contient un compte inexistant (`FANTOME`) À CÔTÉ d'un compte
   valide, l'armer sur le poste de test.
2. Au cycle : le type `privilege` remonte en **`error`** avec le compte fautif
   au détail ; AUCUN des comptes de CE privilège n'est appliqué (pas de deny à
   trous silencieux — piège #8). Les AUTRES privilèges (autres items)
   convergent.

### Scénario 35.6.6 — Binaire antérieur : silence total (piège #1)

1. Sur un poste resté en agent ≤ 2.7.0, armer la capacité : AUCUN statut
   `privilege` au rapport, aucune erreur — RDP toujours ouvert aux élèves.
2. Publier la 2.8.0, laisser l'agent s'auto-mettre à jour : le type apparaît
   au rapport et converge.

### Scénario 35.6.7 — Compte à LARGE PORTÉE refusé (borne portée, post-review #1)

Objet : une SeDeny* **légitime** (donc hors du filet allowlist) posée sur un
principal universel verrouillerait le poste — bornée des DEUX côtés.

1. **Serveur** : tenter de créer (UI future / SQL via observer) une projection
   `privilege` `{privilege: SeDenyInteractiveLogonRight, accounts: ['Domain Users']}`
   (ou `Everyone`, `Authenticated Users`, `Users`, `Administrators`, `SYSTEM`,
   `Interactive`, un SID `S-1-1-0` / RID `…-513`). Attendu : **refus** à
   l'authoring (`PrivilegeAuthoringException`), message « poste VERROUILLÉ ». Un
   groupe métier nommé (`Eleves`, `SE4\Eleves`, jeton `@eleves`) reste accepté.
2. **Agent (défense en profondeur)** : fabriquer malgré tout (SQL brut,
   contournant l'observer) une projection ciblant un principal large, l'armer
   sur le poste. Attendu : le type `privilege` remonte en **`error`** (le SID
   résolu est large) ; AUCUNE application partielle, la SeDeny* n'est jamais
   posée — le poste reste ouvrable.

### Checklist rapide (Story 35.6)

- [ ] 35.6.0 — Migration seed rejouée sur /vm + release 2.8.0 publiée + groupe
      `Eleves` présent dans `user_groups`.
- [ ] 35.6.1 — RDP élève REFUSÉ / prof OK sur le MÊME poste ; `secpol.msc`
      montre `Eleves` au privilège ; rapport `compliant`.
- [ ] 35.6.2 — Session élève en cours PAS coupée ; refus au logon suivant.
- [ ] 35.6.3 — `off` vide le privilège (RDP rétabli) ; `unmanaged` n'y touche
      PAS (orphelin assumé, retrait propre = off).
- [ ] 35.6.4 — Titulaire ajouté à la main révoqué (liste entière, sans store).
- [ ] 35.6.5 — Compte irrésoluble ⇒ `error` nominatif, zéro application
      partielle ; autres items convergent.
- [ ] 35.6.6 — Binaire ≤ 2.7.0 : type ignoré en silence ; 2.8.0 le réveille.
- [ ] 35.6.7 — Principal large (`Domain Users`/`Everyone`/SID well-known)
      REFUSÉ à l'authoring ET en `error` côté agent ; groupe métier accepté.
- [ ] Golden : `state.v1.json` +1 item privilege machine, `FROZEN_STATE_HASH`
      PHP = `frozenStateHash` Go recalculés à l'identique (`e87fed16…`) ;
      `report.v1.json` INCHANGÉ.

## Story 38.3 — Nettoyage des crochets legacy SE4 (`legacy_cleanup`)

Nouveau mécanisme HORS-REGISTRE (quatrième, patron 35.6/36.1/36.2) : type
contrat `legacy_cleanup` (§7.10 — exclusive à identité FIXE, portée Machine,
payload `{mozilla: "vanilla"}` enum fermé Q5-a), capacité de gating
`legacy_hooks_cleanup` (toggle `unmanaged`/`on`, défaut Broadcast `unmanaged`
— PAS de `off` : nettoyage one-way), handler Go `LegacyCleanupHandler` (SYSTEM
seul, scan SANS store iso firewall/privilege). Le CATALOGUE d'artefacts est
versionné DANS l'agent (D3). Agent **2.9.0** — publication MANUELLE obligatoire
(un binaire ≤ 2.8.0 ignore le type EN SILENCE ; les 2.6.0/2.7.0/2.8.0 n'ayant
jamais été publiées, la 2.9.0 livre fs_acl + firewall + privilege +
legacy_cleanup d'un coup).

### ⚠️ Limite d'environnement /vm (piège #1 — GPO de DOMAINE pleine)

Sur /vm, le déclencheur des appels `gpo/*.php` N'EST PAS local au poste : la
GPO de domaine « applications » `{D418994B-0F25-4C3D-8627-4EB4F913BC12}` est
PLEINE sur le DC dev (`se4ad.localdev.fr`) et liée à la RACINE — ses
`logon.cmd`/`startup.cmd` re-curl-ent à CHAQUE logon depuis SYSVOL et recréent
les blobs `%TEMP%`. Le nettoyage local ne peut donc PAS produire « zéro hit »
sur /vm à lui seul (re-nettoyage idempotent des blobs à chaque passe, sans
erreur — ce n'est PAS un échec du module). L'e2e « zéro hit » exige :
**un poste LAB migré** (GPO legacy = coquilles vides là-bas), OU sur /vm la
**neutralisation AD préalable** de `{D418994B-…}` (délier de la racine ou vider
ses scripts avec bump `GPT.INI` Version). Sur lab1 (AD fédéré 75 étabs) : ne
JAMAIS toucher les GPO racine. **REMONTÉE 38.6** : les hits tombstones pilotés
par cette GPO domaine ne s'éteindront pas sans action AD — le critère GO de
l'extinction doit en tenir compte.

### Scénario 38.3.1 — Chaîne complète e2e lab (AC7)

Ordre STRICT (piège #2 — publier AVANT migrate/armement) :

1. **Publier** la release 2.9.0 (manuelle — `php artisan agent:release` +
   publication ; update.sh ne publie jamais). Vérifier le check-in : la version
   RAPPORTÉE par le poste pilote passe à 2.9.0.
2. **Migrer** : `php artisan migrate` sur la cible (seed
   `legacy_hooks_cleanup`, défaut Broadcast `unmanaged` = inactif partout).
3. **Armer** : capacité `on` en override sur le PARC PILOTE (UI capacités du
   groupe — patron défaut Broadcast + override parc), postes porteurs
   d'artefacts legacy (poste installé par SE4 ou migré).
4. **Convergence** : au cycle suivant, rapport `drift` avec `detail` listant
   les artefacts supprimés (`file:…`, `task:…`, `reg:…`, `mozilla:…`).
5. **Reboot + logon** d'un compte élève : plus AUCUN hit `gpo/applications.php`
   / `gpo/shortcuts_out.php` de ce poste dans les logs serveur
   (`grep 'gpo/.*\.php' /var/log/apache2/access.log` filtré sur l'IP du poste).
6. **Firefox vanilla** : lancer Firefox avec un compte dont la paire
   `profiles.ini`/`installs.ini` référençait `sambaedu.default` → il recrée un
   profil LOCAL sain (PAS de « profil manquant ou inaccessible ») ; le dossier
   `sambaedu.default` est toujours là (données préservées). Idem Thunderbird.
7. **Stabilité** : cycle suivant sans changement local → rapport `compliant`
   SANS detail, AUCUN nouvel événement `agent_report_events` (dédup par hash).

### Scénario 38.3.2 — Gardes de sûreté (ne JAMAIS toucher)

Sur le poste de test, vérifier après convergence que sont INTACTS :

1. `%ProgramFiles%\SambaEdu\Agent\**` (l'agent lui-même) et tout dossier
   d'outils/overlay provisionné par SE5.
2. `%SystemRoot%\wpkg.xml` (base locale WPKG du canal natif).
3. `GroupPolicy\DataStore\**` (cache GPO de DOMAINE — contient
   SE_agent_bootstrap `{A5B9AB83-…}`).
4. Un VRAI dossier `%WinDir%\install` (module provision 27.20) — seule une
   JONCTION (reparse point vers `\\<serveur>\…`) est supprimée.
5. Un fichier `.md5` de `%windir%` dont le contenu N'EST PAS 32-hex.
6. Une tâche nommée `wpkg4`/`logon-system` dont l'ACTION ne référence NI
   `gpo/applications.php` NI wpkg : conservée + rapportée en detail (suspect).
7. Un `profiles.ini` ne référençant PAS `sambaedu.default` (profil géré par
   l'utilisateur) ; le dossier `sambaedu.default` lui-même (jamais supprimé).
8. Autologon Winlogon dont `DefaultUserName ≠ se4install` : intouché (la
   purge des 5 valeurs n'a lieu QUE si `DefaultUserName == se4install`).

### Scénario 38.3.3 — Poste sain silencieux (AC5)

1. Poste déjà nettoyé (ou jamais installé par SE4), capacité `on`.
2. Cycles successifs : item `compliant` SANS detail, zéro écriture disque,
   rapport identique ⇒ dédup serveur ⇒ ZÉRO événement nouveau.

### Scénario 38.3.4 — Retrait du gating (piège #7, one-way)

1. Parc repassé à `unmanaged` (ou capacité désactivée) : le type disparaît du
   state, l'agent cesse de scanner — RIEN n'est « restauré » (le handler ne
   pose rien, il n'y a pas d'orphelin possible). Pas de valeur `off` : c'est
   VOULU (la règle « off écrit une vraie valeur » vaut pour les maps registre
   symétriques).
2. NOTE sémantique compilateur (discipline UNMANAGED commune) : un override
   parc `unmanaged` N'ÉMET PAS de candidat — il ne masque donc PAS un défaut
   Broadcast `on`. Pour désarmer globalement, repasser le DÉFAUT Broadcast à
   `unmanaged` (les parcs pilotes restant armés par leur override `on`).

### Scénario 38.3.5 — Binaire antérieur : silence total (piège #2)

1. Sur un poste resté en agent ≤ 2.8.0, armer la capacité : AUCUN statut
   `legacy_cleanup` au rapport, aucune erreur — poste jamais nettoyé.
2. Publier la 2.9.0, laisser l'agent s'auto-mettre à jour : le type apparaît
   au rapport et converge.

### Check-list

- [ ] 38.3.1 — Chaîne publier → migrer → armer parc pilote → drift+detail →
      reboot+logon → zéro hit `gpo/*.php` → Firefox/Thunderbird vanilla →
      compliant stable.
- [ ] 38.3.2 — Les 8 gardes/interdits vérifiés intacts après convergence.
- [ ] 38.3.3 — Poste sain : compliant sans detail, zéro événement (dédup).
- [ ] 38.3.4 — `unmanaged` cesse de scanner, ne restaure rien ; désarmement
      global par le défaut Broadcast.
- [ ] 38.3.5 — Binaire ≤ 2.8.0 : type ignoré en silence ; 2.9.0 le réveille.
- [ ] /vm : e2e « zéro hit » UNIQUEMENT après neutralisation AD de
      `{D418994B-…}` (sinon blobs %TEMP% recréés à chaque logon — attendu).
- [ ] Golden : `state.v1.json` +1 item legacy_cleanup machine,
      `FROZEN_STATE_HASH` PHP = `frozenStateHash` Go recalculés à l'identique
      (`fc8a5324…`) ; `report.v1.json` INCHANGÉ.
