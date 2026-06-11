# Story 24.3 : Compagnon de session — portée user, login jamais bloquant

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant que prof ou élève,
je veux que ma session s'ouvre instantanément, réseau ou pas,
afin que la convergence ne se fasse jamais sentir.

## Contexte & intention

**Troisième story de l'Epic 24** (gate palier 1 : démo live UI → état → agent → rapport → UI). État du canal : Epic 23 done 5/5 + validé e2e ; 24.1 done (POST /report, baseline **168 passed** sur /vm) ; **24.2 en review** (agent squelette PowerShell livré : `agent/` top-level, service SYSTEM, boucle GET state → cache → POST report `items: []`, rotation D5, backoff, quarantaine — +12 tests `AgentSkeletonE2eTest`, run de confirmation /vm + install lab T9 restants).

**Ce que cette story est :**
- Le deuxième processus de l'architecture agent (FR17) : la **portée user** — au logon, l'état `f(poste, user)` est tiré (`GET /state?user=<login>`, câblage du `?user=` livré en 23.5) et les portées `session` + `machine_user` sont traitées **dans le contexte de la session**, de façon **asynchrone après ouverture** (NFR1, sabotage #26)
- La matérialisation de la **frontière de confiance** (NFR5) : le compagnon tourne avec les droits de la session — il ne peut ni modifier les fichiers de l'agent, ni lire le token, ni usurper l'identité d'un autre user
- La preuve mesurée du **KPI du brief** : temps d'ouverture de session identique avec et sans serveur joignable

**Ce que cette story n'est PAS :**
- Les handlers (wallpaper/overlay → 24.4) : le compagnon 24.3 parse, partitionne par portée et journalise — il n'APPLIQUE encore rien (iso-approche squelette 24.2)
- L'UI conformité / forcer la synchro (→ 24.5)
- La distribution/auto-update (→ 25.x)
- L'agent définitif : on reste dans le **MVP PowerShell jetable** de 24.2 (décision techno `agent/README.md` — binaire .NET/Go requis avant Epic 25) ; aucune sur-ingénierie d'IPC pour un artefact temporaire

**Le nœud de design de la story (à lire avant tout) :** le token est sous ACL `SYSTEM + Administrators` **uniquement** — c'est le **contrat FIGÉ 23.3** (`docs/agent/enrollment.md` §3, INTOUCHÉ), et NFR5 exige que les fichiers agent restent hors de portée de l'élève. **Le compagnon (droits user) ne peut donc PAS porter le bearer ni appeler le serveur lui-même.** La résolution retenue (décision n° 1 ci-dessous) : le canal réseau reste 100 % SYSTEM ; le « compagnon de session » est un sous-système en deux moitiés — fetch SYSTEM déclenché au logon + processus user qui consomme un cache per-user en lecture seule. L'AC epic « le compagnon appelle GET /state avec le user de la session » est honoré au niveau du sous-système : un logon **provoque** l'appel `GET /state?user=<login>` (observable côté serveur), et le processus user reçoit les 3 portées et ne traite que `session` + `machine_user`.

## ⚠️ Pièges connus (lire avant de coder)

1. **Token = ACL SYSTEM+Administrators, contrat FIGÉ 23.3 — le processus user ne peut PAS le lire.** Ne jamais relâcher l'ACL du token, ne jamais en copier la valeur dans un emplacement lisible user, ne jamais le passer en argument/env d'un processus user (lisible via WMI `Win32_Process.CommandLine`). Tout le HTTP reste côté SYSTEM. C'est LE piège qui structure la story.
2. **Un ETag par couple (poste, user)** (`docs/agent/state-endpoint.md`) : le contexte machine-only (service 24.2) et chaque contexte `?user=<login>` ont chacun LEUR ETag. Réutiliser `cache\etag.txt` (machine) pour un fetch `?user=` casse la revalidation (faux 200/304). Un fichier `etag.txt` **par répertoire de session** (décision n° 3), stocké VERBATIM (guillemets RFC 7232 inclus) comme en 24.2.
3. **`?user=` = login COURT, jamais d'erreur serveur** : lookup case-insensitive ; login inconnu ou compte local (admin local hors SE5 — cas légitime) → compilation machine-only + log `agent.state.unknown_user`, **200 quand même** (`StateController::resolveUser`). Le compagnon doit traiter ce cas sans bruit : une session locale reçoit un état (broadcast possibles), elle ne plante pas. L'énumération de session Windows donne `DOMAIN\user` → **strip du domaine** avant `?user=`.
4. **`session`/`machine_user` ne sont PAS « vides sans user »** : le scope est déclaré **par type** (ex. `wallpaper` = scope `session`), pas par maille — un wallpaper broadcast sort en portée `session` même en machine-only (state-endpoint.md ⚠️). La partition est : service SYSTEM → portée `machine` SEULEMENT ; compagnon → portées `session` + `machine_user` SEULEMENT. Jamais de recouvrement, jamais « tout le monde traite tout ».
5. **Course de rotation D5 à DEUX acteurs réseau** (nouveau risque introduit ici) : le service (cycle) et le fetch de session (logon) partagent le même token. Si A reçoit `X-Agent-New-Token`, l'écrit, puis USE le nouveau (fenêtre de grâce fermée côté serveur), un appel en vol de B avec l'ancien → 401, et le `PreviousToken` **en mémoire de B est null** (B n'a pas vu la rotation) → B conclurait à tort « 401 irrécupérable → arrêt ». **Durcissement obligatoire** (décision n° 5) : sur 401, avant de déclarer l'irrécupérable, **relire le token sur disque** — s'il diffère de celui utilisé, réessayer UNE fois avec. L'écriture du token est déjà atomique (tmp + Move-Item, 24.2).
6. **Le déclencheur de tâche planifiée « At log on » ne transmet PAS le nom du user déclenchant** à une action SYSTEM. Ne pas parser `quser` (sortie LOCALISÉE, fragile) : énumérer les sessions interactives via CIM (`Win32_LogonSession` LogonType 2/10/11 + association `Win32_LoggedOnUser`), qui donne nom + domaine + autorité, et résoudre le **SID** (clé des ACL et du répertoire de cache — jamais de nom localisé dans icacls, convention 24.2 `*S-1-5-18`).
7. **Les scripts exécutés par le user doivent être LISIBLES par le user** : ils vivent sous `C:\Program Files\SambaEdu\Agent\` (ACL par défaut : Users = read+execute) — c'est déjà le répertoire d'install de 24.2. **JAMAIS** sous `C:\ProgramData\SambaEdu\Agent\` (ACL SYSTEM+Admins : le user ne peut pas les lire). Les scripts sont signés (Build-Agent.ps1, AC5 24.2 reconduit).
8. **Le compagnon n'écrit RIEN sous `C:\ProgramData\SambaEdu\Agent\`** : ses logs vont dans `%LOCALAPPDATA%\SambaEdu\Agent\companion.log` (profil user, aucune élévation). Le cache per-user est en **lecture seule** pour le user (ACL posée par le fetch SYSTEM). Vérification QA explicite : une tentative d'écriture user sous l'arborescence agent → Access Denied.
9. **NFR1 au sens strict : RIEN dans le chemin synchrone du logon.** Pas de script Winlogon/Userinit, pas de GPO logon script, pas d'attente réseau dans une étape bloquante. Les tâches planifiées à déclencheur logon s'exécutent **en parallèle** de l'ouverture — c'est le mécanisme retenu. L'attente bornée DANS le compagnon (poll du cache) est asynchrone à l'ouverture, donc licite.
10. **Le rapport v1 n'a PAS de dimension user** (contrat §6 FIGÉ : `{schema, generated_at, agent_version, workstation, items}`) : les statuts sont par type, au niveau poste. En 24.3 le compagnon ne rapporte rien (aucun handler) — le service continue ses rapports `items: []` inchangés. La remontée des résultats session vers le rapport est un problème de **24.4** (design à y résoudre SANS toucher le contrat).
11. **Quarantaine 403** : sémantique 24.2 reconduite — quarantaine = plus AUCUN traitement d'état ; les check-ins légers restent le `GET /state` machine du service. **Pas de fetch de session en quarantaine** (inutile : l'état ne sera pas traité ; et pas de double check-in léger).
12. **Baseline tests : `--filter Agent` UNIQUEMENT, jamais la suite complète** (décision Henri). Baseline attendue post-24.2 : **180** (168 post-24.1 + 12 `AgentSkeletonE2eTest`) — le run de confirmation /vm de 24.2 est peut-être encore dû : le constater au premier run et le noter.
13. **24.2 est en review, pas done** : cette story réutilise ses fonctions (`Invoke-AgentHttp*`, `Update-TokenIfRotated`, `Parse-State`, `Set-AgentAcl`, `Read-AgentConfig`…). Si la review 24.2 modifie `SambaEduAgent.ps1`/`ContractV1.ps1`, rebaser avant merge. Ne pas dupliquer ces fonctions — les factoriser/dot-sourcer.
14. **Fichiers FIGÉS — zéro édit** : `docs/agent/contract-v1.md`, `docs/agent/enrollment.md`, golden files `tests/Fixtures/Agent/*.v1.json`, `FROZEN_STATE_HASH`, `StateController.php`, `ReportController.php`, `AuthenticateAgentToken.php`. **Aucun code serveur Laravel n'est modifié par cette story** (le `?user=` existe depuis 23.5) — seuls des TESTS serveur sont ajoutés.

## Décisions de design prises ici (à challenger en review, pas à re-trancher en dev)

1. **Le canal réseau reste 100 % SYSTEM — le compagnon ne touche jamais le token.** Conséquence directe du contrat figé 23.3 + NFR5 (cf. nœud de design). Le « compagnon de session » = sous-système en deux tâches planifiées, enregistrées par l'installeur :
   - **`SambaEduAgent-SessionFetch`** — compte SYSTEM, déclencheur « At log on » (any user) : énumère les sessions interactives (CIM, piège n° 6), et pour chaque session appelle `GET /state?user=<login_court>` avec le `If-None-Match` du contexte, puis écrit le cache per-user (décision n° 3). Réutilise les fonctions HTTP/rotation de 24.2.
   - **`SambaEduAgent-SessionCompanion`** — principal `BUILTIN\Users`, déclencheur « At log on » : s'exécute **dans la session du user qui ouvre**, avec SES droits. Attente bornée du cache frais (poll ~2 s, timeout ~60 s ; sinon dernier cache existant ; sinon sortie silencieuse — le prochain cycle convergera), `Parse-State`, partition des portées (piège n° 4), traitement no-op journalisé (aucun handler en 24.3), log `%LOCALAPPDATA%`.
   Une alternative named-pipe (service broker IPC) a été écartée pour le MVP PowerShell (boucle mono-thread 24.2, artefact temporaire — mémoire projet : l'agent définitif sera en Go) ; elle est la voie naturelle du binaire définitif, à documenter dans `agent/README.md`.
2. **L'identité de session est résolue côté SYSTEM, jamais déclarée par le user.** Le fetch SYSTEM détermine qui est connecté (énumération CIM) — le processus user ne transmet RIEN au serveur ni au fetch. Anti-usurpation par construction : un élève ne peut pas demander l'état d'un autre login (cohérent avec state-endpoint.md : « le poste (SYSTEM) est l'autorité sur QUI est dans SA session »).
3. **Cache per-user : `C:\ProgramData\SambaEdu\Agent\cache\sessions\<SID>\{state.json,etag.txt}`.** Écrit par le fetch SYSTEM (écriture atomique tmp + Move-Item, iso 24.2). ACL posée à la création du répertoire : `/inheritance:r`, `*S-1-5-18:(OI)(CI)F`, `*S-1-5-32-544:(OI)(CI)F`, `<SID-du-user>:(OI)(CI)R` — le user LIT son état, n'écrit rien, ne lit pas celui des autres. Clé = SID (stable, non localisé) ; `etag.txt` par répertoire = ETag par contexte (piège n° 2). Pas de purge automatique en 24.3 (volume borné par les users du poste) — noté pour plus tard.
4. **Rafraîchissement mid-session : le cycle du service rafraîchit aussi les caches de session.** À chaque itération de `Invoke-AgentCycle` (60 min + jitter), après la portée machine, le service énumère les sessions actives et rafraîchit chaque cache per-user (mêmes fonctions que le fetch logon — un seul code). Fraîcheur laxe NFR3 respectée (logon + timer, pas de push). Le processus compagnon, lui, ne s'exécute qu'au logon en 24.3 — la réexécution périodique côté user arrive avec les handlers (24.4).
5. **Durcissement 401 deux-acteurs (piège n° 5), implémenté UNE fois** dans la couche HTTP partagée : ordre de traitement d'un 401 = (a) réessai fenêtre de grâce avec `PreviousToken` si présent (logique 24.2 inchangée) ; (b) **relire le token sur disque** — s'il diffère du token utilisé, réessayer UNE fois avec ; (c) sinon irrécupérable → arrêt + log, jamais de re-enrôlement automatique. Bénéficie aussi au service machine (le fetch logon peut rotater pendant un cycle).
6. **Le compagnon ne rapporte rien en 24.3.** Aucun handler → aucun statut à remonter ; le rapport reste l'affaire du service (machine, `items: []`). Le câblage des résultats session dans le rapport (sans dimension user au contrat — piège n° 10) est une décision de design de 24.4.
7. **KPI logon mesuré, procédure outillée simple** : sur le poste lab, comparer le temps « début de logon → démarrage du shell » (events Winlogon/heure de création du processus `explorer.exe` de la session) sur 3 ouvertures serveur joignable vs 3 ouvertures câble débranché. Critère : écart moyen dans le bruit de mesure (< ~1 s), aucune corrélation au réseau. Procédure écrite dans `docs/qa/domains/agent.md` §3 (exécution lab = action humaine, iso T9 24.2).
8. **Logs compagnon iso-format 24.2** (`[ISO 8601] [LEVEL] message`) dans `%LOCALAPPDATA%\SambaEdu\Agent\companion.log`, rotation quotidienne, rétention 7 jours — même implémentation, chemin différent (profil user). Le fetch SYSTEM logue dans le `agent.log` existant.

## Acceptance Criteria

### AC1 — Login jamais bloquant (NFR1, FR17 — KPI du brief)

**Given** un logon utilisateur sur le poste lab
**When** le sous-système compagnon démarre (tâches à déclencheur logon)
**Then** l'ouverture de session n'attend RIEN du réseau : aucun composant dans le chemin synchrone du logon (pas de script Winlogon/Userinit/GPO, tâches planifiées asynchrones uniquement — piège n° 9), la convergence `session`/`machine_user` démarre APRÈS ouverture
**And** la procédure de mesure du KPI est écrite (`docs/qa/domains/agent.md` §3, décision n° 7) : temps d'ouverture identique avec et sans serveur joignable (écart dans le bruit de mesure), exécutée sur le poste lab (action humaine tracée en Completion Notes).

### AC2 — Check-in de session : `GET /state?user=`, portées partitionnées (FR17, FR23)

**Given** un logon d'un user du domaine sur un poste enrôlé
**Then** un appel `GET /api/v1/agent/state?user=<login_court>` part (déclenché par le logon, porté par le fetch SYSTEM — décisions n° 1-2), avec le `If-None-Match` du contexte (poste, user), et l'enveloppe v1 est écrite dans le cache per-user (`cache\sessions\<SID>\`, décision n° 3)
**And** le processus compagnon (droits user) lit ce cache, valide le schéma (`Parse-State`), reçoit les 3 portées et ne traite que `session` + `machine_user` (traitement 24.3 = parse + partition + log, aucun handler) ; la portée `machine` reste exclusivement au service SYSTEM (piège n° 4)
**And** le rafraîchissement mid-session est assuré par le cycle du service (décision n° 4)
**And** un login inconnu / compte local → enveloppe machine-only reçue et traitée sans erreur ni bruit (piège n° 3).

### AC3 — Serveur injoignable au logon : la session vit sur le cache (FR22, NFR2)

**Given** le serveur SE5 injoignable au moment du logon
**Then** la session s'ouvre normalement ; le compagnon utilise le dernier cache per-user s'il existe, sinon sort silencieusement (log local) — aucun message d'erreur visible, aucun crash, aucune attente bloquante au-delà du poll borné (décision n° 1)
**And** le fetch raté est rattrapé au prochain cycle du service (backoff/cadence 24.2 inchangés)
**And** le temps d'ouverture est identique au cas serveur joignable (KPI AC1).

### AC4 — Frontière de confiance (NFR5)

**Given** le compagnon s'exécutant avec les droits de la session (jamais SYSTEM)
**Then** il ne peut ni lire le token (ACL 23.3 intacte — piège n° 1), ni modifier les fichiers de l'agent (binaire, config, caches : tentative d'écriture user sous `C:\ProgramData\SambaEdu\Agent\` et `C:\Program Files\SambaEdu\Agent\` → Access Denied, scénario QA), ni lire le cache de session d'un AUTRE user (ACL par SID, décision n° 3)
**And** le user ne peut pas usurper un autre login : l'identité de session est résolue côté SYSTEM (décision n° 2), le processus user ne déclare jamais son identité
**And** les écritures du compagnon se limitent à `%LOCALAPPDATA%\SambaEdu\Agent\` (décision n° 8)
**And** aucun appel AD/Kerberos/LDAP dans tout le sous-système (critère Keycloak NFR7, grep en review).

### AC5 — Résilience & invariants canal (D5, FR22)

**Given** une rotation `X-Agent-New-Token` reçue sur un fetch de session (y compris sur 304)
**Then** elle est traitée comme en 24.2 (écriture atomique, fenêtre de grâce)
**And** la course deux-acteurs est couverte : sur 401, relecture du token sur disque avant de déclarer l'irrécupérable (décision n° 5 — implémentée une fois, partagée service + fetch)
**And** poste en quarantaine (403) → aucun fetch de session, aucun traitement d'état ; les check-ins légers restent ceux du service machine (piège n° 11)
**And** serveur injoignable pendant un fetch de session → échec silencieux loggé, jamais de retry agressif (le rattrapage = cycle du service).

### AC6 — Installation, artefacts, build (NFR6)

**Given** `Install-SambaEduAgent.ps1` exécuté sur le poste lab
**Then** il enregistre les deux tâches planifiées (`SambaEduAgent-SessionFetch` SYSTEM at-logon ; `SambaEduAgent-SessionCompanion` principal Users at-logon, décision n° 1) en plus du service 24.2 ; `Uninstall-SambaEduAgent.ps1` les supprime proprement
**And** les nouveaux scripts vivent sous `C:\Program Files\SambaEdu\Agent\` (lisibles user — piège n° 7) et sont inclus dans le bundle signé de `Build-Agent.ps1` (signature Authenticode CA interne, AC5 24.2 reconduit)
**And** une réinstallation (tâches déjà présentes) est idempotente (suppression + recréation, iso-pattern service 24.2).

### AC7 — Tests serveur : le contrat du compagnon vérifié côté serveur

**Given** `tests/Feature/Api/V1/Agent/SessionCompanionE2eTest.php` (NEUF — aucun code serveur modifié, on fige le comportement consommé)
**Then** il couvre, conventions 23.5/24.2 (factories, `TokenRotationService::issueFor()`, helpers privés, captureAgentLogs) :
- `GET /state?user=<login connu>` → 200, enveloppe v1, ETag présent ; le hash diffère de l'ETag machine-only quand une règle user-ciblée existe (un ETag PAR contexte)
- `If-None-Match` du contexte user → 304 ; le même ETag envoyé sur le contexte machine-only → 200 (pas de cross-contexte)
- `?user=` inconnu → 200 machine-only + log `agent.state.unknown_user` ; `?user=` case-insensitive → même état/ETag que le login canonique
- rotation due + `GET /state?user=` → `X-Agent-New-Token` présent sur 200 ET sur 304 (invariant D5 sur le chemin compagnon)
- quarantaine → 403 `AGENT_QUARANTINED` sur `GET /state?user=`
**And** `php artisan test --filter Agent` sur /vm : baseline attendue **180** (piège n° 12) + les nouveaux, zéro régression — **jamais la suite complète**.

### AC8 — Documentation

**Then** `docs/agent/session-companion.md` (NEUF, vue côté serveur iso `agent-skeleton.md`) : séquence logon → fetch SYSTEM `?user=` → cache per-user → compagnon ; partition des portées ; frontière de confiance (qui détient le token, qui lit quoi) ; ETag par contexte ; ce que 24.4 branchera dessus (handlers session + remontée rapport)
**And** `agent/README.md` enrichi : section compagnon (deux tâches, chemins per-user, log `%LOCALAPPDATA%`, durcissement 401 deux-acteurs, alternative named-pipe notée pour l'agent définitif)
**And** `docs/qa/domains/agent.md` Section 3 (append-only) : scénarios logon nominal, logon hors-ligne, KPI (décision n° 7), frontière de confiance (token illisible, écritures refusées, cache d'un autre user illisible)
**And** `contract-v1.md`, `enrollment.md`, golden files INTOUCHÉS (piège n° 14).

## Tasks / Subtasks

- [x] **T1 — Énumération de sessions + cache per-user (fonctions partagées)** (AC2, AC4)
  - [x] Dans `agent/windows/SambaEduAgent.ps1` (ou module extrait si la review 24.2 le réorganise — piège n° 13) : `Get-InteractiveSessions` (CIM `Win32_LogonSession` LogonType 2/10/11 + `Win32_LoggedOnUser`, retourne login court + SID — piège n° 6, dédoublonné par SID)
  - [x] `Save-SessionStateCache` / `Read-SessionEtag` : répertoire `cache\sessions\<SID>\`, écriture atomique tmp+Move, ACL à la création (`/inheritance:r`, SYSTEM F, Administrators F, `<SID>:R` — décision n° 3)
- [x] **T2 — Fetch de session côté SYSTEM** (AC2, AC5)
  - [x] `Invoke-SessionStateFetch` : pour chaque session active → `GET /state?user=<login_court>` avec `If-None-Match` du contexte, `Update-TokenIfRotated` (réutilisé), 200 → cache per-user, 304 → cache valide, 403 → skip (quarantaine, piège n° 11), erreur réseau → log + skip (pas de backoff propre : rattrapage au cycle)
  - [x] Point d'entrée tâche planifiée : `agent/windows/SessionStateFetch.ps1` (mince : dot-source + appel)
  - [x] Intégration au cycle : `Invoke-AgentCycle` appelle `Invoke-SessionStateFetch` après la portée machine (décision n° 4), sans casser les retours `ok|backoff|stop` existants
- [x] **T3 — Durcissement 401 deux-acteurs** (AC5, décision n° 5)
  - [x] Dans `Invoke-AgentHttpWithGrace` : étape (b) relecture du token sur disque sur 401 (après la grâce mémoire, avant l'irrécupérable), un seul réessai, logs explicites
- [x] **T4 — Compagnon côté user** (AC1, AC2, AC3, AC4)
  - [x] `agent/windows/SessionCompanion.ps1` : résolution de SON SID (`[System.Security.Principal.WindowsIdentity]::GetCurrent()`), attente bornée du cache frais (poll ~2 s / timeout ~60 s, fallback dernier cache, sinon sortie silencieuse — décision n° 1), `Parse-State` (réutilisé, lisible user — piège n° 7), partition `session`+`machine_user` (piège n° 4), traitement no-op journalisé par item (type, scope, mode)
  - [x] Log `%LOCALAPPDATA%\SambaEdu\Agent\companion.log`, format/rotation iso 24.2 (décision n° 8) ; AUCUNE écriture hors profil user
- [x] **T5 — Installeur, désinstalleur, build** (AC6)
  - [x] `Install-SambaEduAgent.ps1` : copie des nouveaux scripts dans `C:\Program Files\SambaEdu\Agent\`, enregistrement des 2 tâches (`Register-ScheduledTask` : SessionFetch principal SYSTEM trigger AtLogOn ; SessionCompanion principal `BUILTIN\Users` GroupId trigger AtLogOn), idempotent (Unregister si présentes)
  - [x] `Uninstall-SambaEduAgent.ps1` : suppression des 2 tâches
  - [x] `Build-Agent.ps1` : inclure les nouveaux `.ps1` dans le bundle signé (vérifier la signature sur chaque artefact)
- [x] **T6 — Tests serveur** (AC7)
  - [x] `tests/Feature/Api/V1/Agent/SessionCompanionE2eTest.php` : scénarios AC7 (ETag par contexte, 304 par contexte, unknown_user, case-insensitive, rotation sur `?user=` 200+304, 403 quarantaine)
  - [x] Run `/vm` : `php artisan test --filter Agent` — constater la baseline réelle (180 attendus, piège n° 12), zéro régression
- [x] **T7 — Documentation** (AC8)
  - [x] `docs/agent/session-companion.md` (NEUF), `agent/README.md` (section compagnon), `docs/qa/domains/agent.md` §3 (logon nominal/hors-ligne, KPI, frontière de confiance) + checklist rapide mise à jour
- [ ] **T8 — Validation lab** (AC1, AC3, AC4 — ACTION HUMAINE, iso T9 24.2)
  - [ ] Réinstaller sur le poste windoobe (ws 49), ouvrir une session domaine : vérifier `agent.state.compiled` avec `user` côté serveur + cache `sessions\<SID>\` + log compagnon
  - [ ] Logon câble débranché : session normale, compagnon sur cache
  - [ ] Mesure KPI (décision n° 7) : 3 logons réseau ON vs 3 OFF, résultats tracés en Completion Notes
  - [ ] Frontière de confiance : depuis la session user, lecture token + écriture arborescence agent → Denied

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (24.3) | Hors-scope (story) |
|---|---|
| Sous-système compagnon : fetch SYSTEM logon + processus user | Handlers session (wallpaper user, overlay…) → 24.4 |
| `GET /state?user=` consommé (câblé serveur en 23.5 — zéro code serveur ici) | Remontée des résultats session dans le rapport → 24.4 (piège n° 10) |
| Cache per-user SID + ETag par contexte | Réexécution périodique du compagnon mid-session → 24.4 |
| Durcissement 401 deux-acteurs (profite aussi au service) | IPC named-pipe (voie de l'agent définitif Go/.NET, documentée seulement) |
| Tâches planifiées install/uninstall + bundle signé | Distribution auto / auto-update → 25.x |
| KPI logon mesuré + scénarios QA frontière de confiance | UI conformité / forcer la synchro → 24.5 |
| `docs/agent/session-companion.md` + tests serveur du chemin `?user=` | Tout édit des fichiers figés (piège n° 14) |

### Contrat & serveur — ce que le compagnon consomme (FIGÉ, ne jamais modifier)

[Source: docs/agent/contract-v1.md ; docs/agent/state-endpoint.md ; app/Http/Controllers/Api/V1/Agent/StateController.php]

- `GET /state?user=<login>` : user **optionnel** ; absent → machine-only ; connu → mailles user contribuent ; inconnu/local → machine-only + `agent.state.unknown_user`, **jamais d'erreur**. Lookup case-insensitive (`User::findByLogin`).
- **Un ETag par couple (poste, user)** — « cache par contexte de requête, pas global (Epic 24) » : c'est écrit noir sur blanc dans state-endpoint.md, la story l'implémente.
- `X-Agent-New-Token` sur TOUTE réponse, 304 compris (invariant D5) — déjà géré par `Update-TokenIfRotated` (24.2), à brancher sur le chemin fetch session.
- Les 3 portées sont des **listes** ; type absent ≠ liste vide (§8) ; portées nommées = `machine|session|machine_user` (`Get-ContractScopes`, ContractV1.ps1).
- Le rapport §6 n'a pas de champ user — ne PAS l'« enrichir » (contrat figé).

### Code 24.2 à réutiliser (ne PAS réinventer)

[Source: agent/windows/SambaEduAgent.ps1 ; agent/shared/ContractV1.ps1 ; agent/windows/Install-SambaEduAgent.ps1]

- `Invoke-AgentHttp` / `Invoke-AgentHttpWithGrace` (HttpWebRequest, jamais Invoke-WebRequest — PS 5.1 lève sur 304/4xx), `Update-TokenIfRotated`, `Read-AgentToken`/`Save-AgentToken` (atomique), `Set-AgentAcl` (SIDs, jamais de noms localisés), `Write-AgentLog` (format + rotation), `Read-AgentConfig` (`server_url`, `interval_seconds`)
- `Parse-State` (refus major inconnu, portées toujours listes) — `shared/ContractV1.ps1`, AUCUNE dépendance Windows, lisible user une fois copié dans Program Files
- Installeur : pattern idempotence (stop/delete/attente/recréation), `#Requires -RunAsAdministrator`, copie vers `C:\Program Files\SambaEdu\Agent\`
- `X-Agent-Hostname` envoyé sur chaque requête (anti-clonage 23.2) ; `X-Agent-Mac` volontairement absent (multi-NIC, commentaire dans le code — ne pas l'ajouter)

### Project Structure Notes

- Racine = projet Laravel (hôte → VM par inotify) ; `agent/` = top-level hors Laravel, jamais dans `app/`
- **AUCUNE migration, AUCUNE route, AUCUNE config Laravel** → aucune opération artisan VM requise (pas de config:cache/route:cache) ; seuls les tests `--filter Agent` se lancent sur /vm
- Nouveaux fichiers : `agent/windows/SessionStateFetch.ps1`, `agent/windows/SessionCompanion.ps1`, `docs/agent/session-companion.md`, `tests/Feature/Api/V1/Agent/SessionCompanionE2eTest.php` ; modifiés : `SambaEduAgent.ps1`, `Install/Uninstall-SambaEduAgent.ps1`, `Build-Agent.ps1`, `agent/README.md`, `docs/qa/domains/agent.md`
- Pas de tests PowerShell (décision orchestrateur 24.2 reconduite — couverture = tests serveur + validation lab) ; si la review 24.2 en décide autrement, s'aligner

### Intelligence stories précédentes

- **23.5 (done)** : `?user=` résolu serveur, machine-only jamais en erreur, ETag par contexte EXPLICITE dans state-endpoint.md ; piège cache routes VM (sans objet ici : zéro route nouvelle)
- **24.1 (done)** : hostname COURT dans tout ce qui est déclaré (defer #8 résolu en 24.2) ; rapport sans dimension user
- **24.2 (review)** : toute la boîte à outils PowerShell (HTTP, rotation, ACL, logs, atomicité) ; ETag VERBATIM guillemets inclus ; `PreviousToken` en mémoire process — d'où la course deux-acteurs (piège n° 5) que cette story introduit ET couvre ; service mono-thread (pas d'IPC à y greffer) ; install lab T9 encore dû — T8 de cette story peut s'enchaîner avec
- **Piège récurrent VM** : inotify ne propage pas les suppressions ; ne jamais SSH depuis un worktree

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 24.3] — AC source, FR17, NFR1
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md#Authentication & Security ; #Process Patterns ; #Architectural Boundaries] — frontière de confiance #12, résilience #26/#28, D5
- [Source: docs/agent/state-endpoint.md#?user= ; #ETag] — contrat HTTP du compagnon (un ETag par contexte, unknown_user)
- [Source: docs/agent/enrollment.md §3 — FIGÉ, INTOUCHÉ] — ACL token = la contrainte fondatrice du design
- [Source: docs/agent/contract-v1.md §2, §6, §8 — FIGÉ] — portées, rapport sans user, listes
- [Source: agent/windows/SambaEduAgent.ps1 ; agent/shared/ContractV1.ps1 ; agent/windows/Install-SambaEduAgent.ps1] — fonctions et patterns 24.2 à réutiliser
- [Source: app/Http/Controllers/Api/V1/Agent/StateController.php#resolveUser] — comportement serveur consommé (aucun édit)
- [Source: tests/Feature/Api/V1/Agent/StateEndpointTest.php ; AgentSkeletonE2eTest.php] — conventions tests du canal
- [Source: _bmad-output/implementation-artifacts/24-2-agent-squelette-windows-service-checkin-cache-build.md] — décisions design 24.2, pièges 1-15, statut review

## Dev Agent Record

### Agent Model Used

claude-fable-5 (workflow dev-story, branche main, 2026-06-12)

### Debug Log References

- Baseline /vm AVANT story : `php artisan test --filter Agent` → **180 passed (700 assertions)** — le run de confirmation /vm encore dû par 24.2 (piège n° 12) est CONSTATÉ ici : la baseline réelle = la baseline attendue.
- Après story : `php artisan test --filter Agent` → **188 passed (740 assertions)** (180 + 8 `SessionCompanionE2eTest`), zéro régression. `--filter SessionCompanion` seul : 8 passed (40 assertions).
- Après corrections post-review (2e avis fable) : `php artisan test --filter Agent` → **189 passed (745 assertions)** (+1 test `?user=` vide), zéro régression.
- PowerShell : AUCUNE exécution possible (pas de Windows, pas de pwsh sur l'hôte) — validation par revue statique soigneuse + cohérence stricte avec les patterns 24.2 (StrictMode, PSObject.Properties, @() anti-déroulement scalaire, écriture atomique, SIDs jamais de noms localisés). Iso-décision 24.2 : pas de tests PowerShell.

### Completion Notes List

- **Canal réseau 100 % SYSTEM respecté** (nœud de design) : `SessionCompanion.ps1` ne dot-source QUE `ContractV1.ps1`, ne lit jamais le token, n'a aucun code HTTP ; tout le réseau vit dans `SambaEduAgent.ps1` (SYSTEM). Le compagnon n'écrit que dans `%LOCALAPPDATA%\SambaEdu\Agent\` (log réimplémenté localement — `Write-AgentLog` de 24.2 écrit sous ProgramData, inutilisable côté user ; duplication assumée, décision n° 8 « même implémentation, chemin différent »).
- **Get-InteractiveSessions** : CIM LogonType 2/10/11 + `Win32_LoggedOnUser` → `Win32_Account.Name` (login court structurel, jamais de parsing `DOMAIN\user`) + SID. **Filtre ajouté non prévu par la story** : exclusion des pseudo-sessions DWM (`S-1-5-90-*`) et UMFD (`S-1-5-96-*`) qui SONT en LogonType 2 sur tout poste Windows moderne — sans ce filtre, chaque cycle ferait 2-3 fetchs `?user=DWM-1` parasites (200 machine-only + spam `unknown_user` serveur).
- **ACL cache per-user** : posée À LA CRÉATION du répertoire `<SID>` (`/inheritance:r`, SYSTEM F, Admins F, `<SID>:(OI)(CI)R`) ; les FICHIERS héritent — volontairement PAS de `Set-AgentAcl` sur les tmp (contrairement au cache machine 24.2) : un ré-ACL SYSTEM+Admins retirerait le R du user. Le tmp naît dans le répertoire cible (hérite, et Move-Item conserve). Lecture user à travers des parents SYSTEM-only = bypass traverse checking (SeChangeNotifyPrivilege, défaut Windows) — documenté dans session-companion.md §4.
- **Durcissement 401 deux-acteurs** : implémenté UNE fois dans `Invoke-AgentHttpWithGrace` (étape (b) : relecture disque si le 401 persiste après la grâce mémoire, réessai UNIQUE si le disque diffère des tokens déjà essayés, adoption + purge de la grâce mémoire sur succès). Profite au service ET aux deux chemins de fetch session.
- **Intégration cycle** : `Invoke-SessionStateFetch` appelé après la portée machine, encapsulé try/catch — une erreur de fetch session ne change JAMAIS le retour `ok|backoff|stop` du cycle machine. Un 403 pendant le fetch pose `$script:Quarantined` → le rapport du cycle en cours est aussi coupé (sémantique quarantaine 24.2).
- **Quarantaine + tâche at-logon** : le processus `SessionStateFetch.ps1` (neuf à chaque logon) ne connaît pas la quarantaine → il tente UN fetch, prend le 403 et s'arrête (logué). En-cycle, le flag mémoire saute le fetch entièrement (piège n° 11). Nuance documentée (session-companion.md §7).
- **Tâches planifiées** : principals par SID (`S-1-5-18` pour SessionFetch ; `S-1-5-32-545` traduit en nom local via `SecurityIdentifier.Translate` pour `-GroupId` — jamais de nom localisé en dur), trigger AtLogOn, `-MultipleInstances IgnoreNew`, `ExecutionTimeLimit` 10 min, idempotence Unregister+Register (iso-pattern service). Uninstall supprime les 2 tâches (logs compagnon des profils users non touchés — hors ProgramData).
- **Tests serveur (AC7)** : 8 tests / 40 assertions — enveloppe + ETag du contexte ≠ ETag machine, revalidation par contexte + JAMAIS cross-contexte (les 4 combinaisons), unknown_user (200 machine-only + log info, même ETag), case-insensitive (même ETag + 304 à travers la casse), rotation D5 sur `?user=` 200 ET 304 (nouveau token immédiatement utilisable), 403 quarantaine, séquence complète deux contextes côte à côte. Aucun code serveur modifié (vérifié : zéro diff hors agent/, docs/, tests/Feature/Api/V1/Agent/SessionCompanionE2eTest.php).
- **Fichiers figés** : `contract-v1.md`, `enrollment.md`, goldens, `StateController.php`, `ReportController.php`, `AuthenticateAgentToken.php` — **zéro édit** (piège n° 14).
- **T8 (validation lab) NON exécutée** : ACTION HUMAINE (install poste windoobe ws 49, logons domaine/hors-ligne, mesure KPI 3×ON vs 3×OFF, frontière de confiance) — runbook prêt : `docs/qa/domains/agent.md` §3 (scénarios 3.2 à 3.5), enchaînable avec le T9 de 24.2 encore dû. Les mesures KPI seront à tracer ici même.
- **Piège n° 13 (24.2 en review)** : la review 24.2 n'avait modifié ni `SambaEduAgent.ps1` ni `ContractV1.ps1` au moment du dev — aucun rebase nécessaire ; à re-vérifier si la review 24.2 livre des correctifs après coup.

### File List

- `agent/windows/SambaEduAgent.ps1` (modifié — T1/T2/T3 : `Get-InteractiveSessions`, `Initialize-SessionCacheDir`, `Read-SessionEtag`, `Save-SessionStateCache`, `Invoke-SessionStateFetch`, durcissement 401 deux-acteurs dans `Invoke-AgentHttpWithGrace`, appel session-fetch dans `Invoke-AgentCycle`, `$script:SessionCacheRoot`)
- `agent/windows/SessionStateFetch.ps1` (NEUF — point d'entrée tâche SYSTEM at-logon, mince : dot-source + appel)
- `agent/windows/SessionCompanion.ps1` (NEUF — processus user : poll borné du cache per-SID, Parse-State, partition session+machine_user, no-op journalisé, log %LOCALAPPDATA%)
- `agent/windows/Install-SambaEduAgent.ps1` (modifié — copie des 2 nouveaux scripts + enregistrement idempotent des 2 tâches planifiées at-logon)
- `agent/windows/Uninstall-SambaEduAgent.ps1` (modifié — suppression des 2 tâches)
- `agent/build/Build-Agent.ps1` (modifié — 2 nouveaux .ps1 dans le bundle signé)
- `agent/README.md` (modifié — arborescence, contrats locaux (cache sessions, companion.log), section compagnon, durcissement 401, note named-pipe agent définitif)
- `docs/agent/session-companion.md` (NEUF — vue serveur du sous-système : séquence logon, identité côté SYSTEM, cache/ETag par contexte, partition, frontière de confiance, résilience, KPI, hooks 24.4)
- `docs/qa/domains/agent.md` (modifié — APPEND-ONLY : Section 3 (scénarios 3.1-3.5), en-tête stories/code de référence, checklist rapide étendue ; numérotation existante intacte)
- `tests/Feature/Api/V1/Agent/SessionCompanionE2eTest.php` (NEUF — 8 tests / 40 assertions, AC7)

## Recommandation Modèle Dev

**fable.** La note de 24.2 envisageait `opus` pour 24.3, mais l'analyse de création révèle une story plus profonde qu'estimée : le cœur n'est pas le scheduling Windows, c'est la **sécurité de la frontière de confiance** sous une contrainte figée (ACL token 23.3) qui interdit la lecture du contrat epic au pied de la lettre et impose un design broker (fetch SYSTEM + processus user, identité résolue côté SYSTEM, ACL per-SID) ; s'y ajoutent la **course de rotation D5 à deux acteurs réseau** (un raisonnement à fenêtre de grâce + relecture disque dont une erreur bloque des postes en « 401 irrécupérable »), la partition stricte des portées (piège contre-intuitif `session` non-vide sans user), et des tests serveur qui figent un comportement cross-contexte (ETag par couple poste/user). Erreur ici = soit un trou de sécurité (token lisible élève), soit des agents qui s'arrêtent en parc. `opus` reste le bon choix pour 24.5 (UI conformité Livewire).

## Change Log

- 2026-06-12 — Corrections post-review (review adversariale opus 10 findings + 2e avis fable) : **#1** `Get-InteractiveSessions` passe de la liste noire DWM/UMFD à la liste BLANCHE `^S-1-5-21-` + garde login vide (comptes virtuels `S-1-5-80/82-` et `Name` non résolus ne produisent plus de fetch `?user=` vide ni de cache parasite) ; **#3** tmp d'écriture atomique suffixé `$PID` sur le cache de session ET sur le token (les deux chemins à deux écrivains SYSTEM depuis 24.3 — le cache machine, mono-écrivain, est inchangé) ; **#2/#4** commentaires durcis (`$script:Quarantined` process-local — piège 24.4 ; « frais » = récent < 5 min, course lecture/rename assumée via try/catch) ; **#8** `ExecutionTimeLimit` par tâche (10 min fetch / 2 min compagnon) ; **#9** +1 test `empty_user_param_yields_the_machine_context_without_unknown_user_noise` (garde-fou serveur du cas #1) ; **#6/#10** docs (note SID double-source pour le portage Go §10, `agent.state.compiled` référencé comme héritage 23.5 §9) + entrée Post-correctifs QA. `/vm` : **189 passed (745 assertions)**, zéro régression. Décision user : la review conclut SANS T8 (validation lab = action humaine tracée, runbook §3.2-3.5 prêt).
- 2026-06-12 — Story 24.3 DÉVELOPPÉE (DEV claude-fable-5) : T1→T7 livrés — fonctions partagées (énumération CIM + filtre DWM/UMFD, cache per-SID ACL read-only à la création, fetch `?user=` avec ETag par contexte), durcissement 401 deux-acteurs dans la couche HTTP partagée, compagnon user (poll borné, partition session+machine_user, no-op journalisé, log %LOCALAPPDATA%), 2 tâches planifiées at-logon install/uninstall idempotents + bundle signé étendu, 8 tests `SessionCompanionE2eTest` (/vm : 180 baseline constatée → 188 passed, zéro régression), docs session-companion.md + README agent + QA §3. T8 (lab humain : install windoobe, logons ON/OFF, KPI, frontière de confiance) RESTANT — runbook §3.2-3.5 prêt. Status ready-for-dev → review.
- 2026-06-12 — Story 24.3 créée (SM/orchestrateur) : compagnon de session — sous-système deux tâches (fetch SYSTEM at-logon + processus user), token jamais lisible user (contrat 23.3 figé = contrainte fondatrice), cache per-user par SID + ETag par contexte, durcissement 401 deux-acteurs, KPI logon mesuré, login jamais bloquant par construction (rien dans le chemin synchrone). Aucun code serveur modifié (le `?user=` existe depuis 23.5) — tests serveur SessionCompanionE2eTest seulement. Status (création) → ready-for-dev.
