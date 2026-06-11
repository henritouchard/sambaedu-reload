# Story 24.2 : Agent squelette Windows — service SYSTEM, check-in, cache, build signé

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant que mainteneur SambaEdu,
je veux un agent Windows minimal qui check-in (boot + timer), met en cache l'état cible et rapporte,
afin de valider la boucle réseau complète avant tout handler.

## Contexte & intention

**Deuxième story de l'Epic 24** (gate palier 1 : démo live UI → état → agent → rapport → UI). L'Epic 23 est done 5/5 + validé e2e, l'Epic 24.1 est done (POST /report + stockage D3, baseline **168 passed / 653 assertions** sur /vm) : le canal serveur est complet de bout en bout. Cette story livre **la première boucle côté poste** — la pièce manquante pour fermer le circuit.

**Ce que cette story est :**
- L'agent squelette = le premier artefact client qui consomme effectivement le contrat v1 figé (23.1)
- Son premier cycle complet : lire le token → `GET /state` → stocker l'état en cache local → `POST /report` minimal (StateProviders = stubs, statuts = `compliant` par défaut)
- La preuve que la boucle réseau fonctionne (auth, ETag/304, rotation token, rapport reçu côté serveur)
- Un artefact **signé dès ce premier prototype** (CA interne déjà déployée sur le poste lab par l'install iPXE 23.3)

**Ce que cette story n'est PAS :**
- Le moteur de convergence réel (handlers wallpaper/overlay → 24.4)
- Le compagnon de session (portée user → 24.3)
- La distribution canari et l'auto-update (→ 25.x)
- L'agent en PowerShell jetable est acceptable — le choix définitif doit être documenté dans `agent/README.md` et est requis avant l'Epic 25

**Defer hérité de la review 24.1 (à résoudre ICI — piège #8) :**
Le controller `ReportController` (24.1) vérifie si le `hostname` déclaré dans le payload du rapport correspond au poste authentifié — si non, il log un warning `identity_mismatch`. Pour éviter ce spam sur chaque rapport légitime, **l'agent DOIT envoyer le hostname COURT** (sans domaine, même comportement que le middleware 23.2). Ce contrat hostname court est à documenter dans `docs/agent/agent-skeleton.md` (NEUF).

## ⚠️ Pièges connus (lire avant de coder)

1. **Chemin du token = CONTRAT Epic 24, figé en 23.3** : `C:\ProgramData\SambaEdu\Agent\token` (64 hex, sans newline), ACL `SYSTEM` + `Administrators` only (icacls). L'agent lit ce chemin — ne jamais l'hardcoder ailleurs ni changer le chemin. Source : `docs/agent/enrollment.md` §3.
2. **Hostname COURT obligatoire dans le rapport** (defer review 24.1, piège #8) : le `ReportController` (24.1) compare le hostname déclaré au poste authentifié (from middleware). Le middleware 23.2 normalise en `Str::limit(255)` — le contrôleur utilise `$workstation->name` qui est le nom court sans domaine (convention `workstations.name` = DNS short). Envoyer `env('COMPUTERNAME')` sous Windows (ou `hostname -s` sous PowerShell) = court, correct.
3. **UUID dans le rapport = `$workstation->uuid`** (colonne `workstations.uuid` — uuid SMBIOS). Côté PowerShell : `(Get-WmiObject Win32_ComputerSystemProduct).UUID` ou `(Get-CimInstance -ClassName Win32_ComputerSystemProduct).UUID`. NB : l'UUID sera normalisé en minuscules avant comparaison côté serveur (cf. middleware 23.2, `MacAddressNormalizer` pattern). Envoyer tel quel, le serveur l'a déjà.
4. **`X-Agent-New-Token` : gestion obligatoire de la rotation** (invariant D5, contrat 23.2). Sur chaque réponse 200 ou 304 du `GET /state` ou 200 du `POST /report`, si le header `X-Agent-New-Token` est présent, l'agent doit : (a) écrire le nouveau token sur disque à `C:\ProgramData\SambaEdu\Agent\token`, (b) confirmer la rotation au prochain check-in en utilisant le nouveau token. **Jamais de re-enrôlement automatique silencieux** sur 401.
5. **401 pendant une rotation = cas spécial** (FR22, AC3) : si l'agent reçoit 401 alors qu'il vient d'écrire un nouveau token (`X-Agent-New-Token` reçu à l'appel précédent), il peut réessayer avec l'ancien token (encore dans la fenêtre de grâce). Si 401 avec l'ancien aussi → arrêt + log local (jamais de re-enrôlement automatique, l'admin doit intervenir).
6. **403 quarantaine = check-ins légers seulement** (AC4, FR22) : l'agent reçoit 403 `AGENT_QUARANTINED` → il CESSE de `POST /report` et de traiter l'état, mais CONTINUE les `GET /state` (check-ins légers — le serveur surveille les check-ins pour lever la quarantaine). Pas de boucle de report agressive sur 403.
7. **Cache local = fichier sous ACL SYSTEM** : le cache de l'état (`state.json`) + le dernier ETag (`etag.txt`) vivent sous `C:\ProgramData\SambaEdu\Agent\cache\` avec les mêmes ACL que le token (SYSTEM + Administrators). Un utilisateur standard ne peut ni lire ni écrire. Sous PowerShell : `icacls` ou `Set-Acl`.
8. **ETag opaque stocké verbatim** : l'agent stocke le header `ETag` EXACTEMENT tel qu'il est reçu (guillemets RFC 7232 inclus, ex. `"6c0e8135…"`), et l'envoie EXACTEMENT tel quel dans `If-None-Match`. Toute manipulation (trim, déquotage) brise le 304.
9. **Timer 60 min + jitter ±10 %** (D7, FR23) : cadence par défaut 3600 s, jitter `±360 s` (random entre 3240 et 3960). Configurable via une clé de registre ou un fichier de config local (à décider en implémentation et documenter). Le jitter évite les vagues synchronisées (~600 postes).
10. **Dossier `agent/` top-level (NOUVEAU)** : ce code NE VA PAS dans `app/` (Laravel) mais dans un nouveau dossier `agent/` à la racine du repo, jamais mélangé au code Laravel. Structure : `agent/windows/` (code agent Windows), `agent/shared/` (parsing contrat v1 partageable), `agent/build/` (scripts de build/signature), `agent/README.md` (décision techno + cahier des charges 7 contraintes).
11. **Signature obligatoire dès ce premier prototype** (NFR6, AC5) : la CA interne `SambaEdu-RootCA` est déjà déployée sur le poste lab par la chaîne iPXE (23.3). Le script de build `agent/build/` signe le binaire avec cette CA. Un artefact non signé = Windows SmartScreen bloque = démo impossible. En PowerShell : `Set-AuthenticodeSignature` avec le certificat de la CA interne.
12. **`agent_version` dans le rapport = obligatoire** (contrat 23.1, §6 golden `report.v1.json`) : l'agent déclare sa version dans chaque rapport (`"agent_version": "1.0.0"`). Choisir `"1.0.0"` pour ce premier prototype, à bumper avec chaque release (25.x).
13. **`items: []` est valide** (validé en 24.1, AC9 ReportIngestService) : le rapport squelette peut avoir zéro item (l'agent n'a encore aucun handler). Le serveur répond 200 `{success: true, counts: {compliant: 0, ...}}`. C'est le comportement attendu pour cette story — la boucle se ferme même sans handler.
14. **Backoff exponentiel plafonné** (AC2, FR22) : sur serveur injoignable, le délai de retry démarre à 30 s, double à chaque tentative, plafonné à la cadence normale (3600 s). Jamais de retry agressif qui noierait un serveur qui redémarre.
15. **NFC côté agent** (contrat §4.1) : lors des comparaisons futures (24.4 handlers), normaliser les chaînes en NFC (Windows peut émettre NFD). Pour la story squelette (pas de handler), ce n'est pas critique — à documenter dans `agent/README.md` pour 24.4.

## Décisions de design prises ici (à challenger en review, pas à re-trancher en dev)

1. **Technologie = PowerShell avec `.NET` comme cible documentée** : la story autorise un démarrage en PowerShell (scriptable, signable, service SYSTEM via `nssm` ou `New-Service` avec `sc.exe`, zero dépendance runtime exotique). La recommandation est PowerShell pour le MVP lab (délai court, lisibilité du code contrat), avec migration vers un binaire .NET autonome documentée dans `agent/README.md` comme prérequis avant Epic 25 (distribution canari). Go est possible mais introduit un toolchain non présent sur le serveur SE5 (à évaluer). La décision finale est dans `agent/README.md` et doit vérifier les 7 contraintes du cahier des charges (service SYSTEM, signable Authenticode, ACL SYSTEM, auto-update fiable, cœur partageable cross-OS, zéro runtime exotique, empreinte discrète).

2. **Architecture : service Windows + script de boucle** : le service SYSTEM (portée machine) appelle un script PowerShell de boucle. La boucle : (1) lire le token, (2) `GET /state` avec `If-None-Match`, (3) persister le cache + ETag, (4) construire le rapport minimal, (5) `POST /report`. En cas de 304, skip l'étape 3 (réutiliser le cache) mais continuer à `POST /report` (le rapport reste obligatoire — même état en cache = on rapporte quand même).

3. **Rapport squelette = `items: []` + portée `machine` seulement** : sans handler, l'agent rapporte une liste vide — valide côté serveur (AC 24.1 validé). La portée `session` n'est pas encore traitée (→ 24.3). Le rapport porte `schema`, `generated_at`, `agent_version: "1.0.0"`, `workstation: {hostname, uuid}`, `items: []`.

4. **Persistance du dernier-appliqué (mode `default`, gap 1)** : pour le squelette, il n'y a pas encore de handler, donc pas de `dernier-appliqué` à persister. L'infrastructure de persistance (fichier JSON local sous ACL SYSTEM : `C:\ProgramData\SambaEdu\Agent\applied-state.json`) est à créer vide dès cette story pour préparer 24.4, même si elle n'est pas encore utilisée.

5. **Pas de `GET /state?user=` au boot** : le service SYSTEM (portée machine, pas de session) appelle `GET /state` sans le paramètre `?user=`. C'est la portée machine seulement. Le compagnon de session (24.3) ajoutera `?user=<login>`.

6. **Hostname court dans le rapport** (résolution defer 24.1) : l'agent envoie `$env:COMPUTERNAME` (PowerShell) qui est le nom court Windows, sans domaine — correspond à `workstations.name` tel que stocké lors de l'enrôlement (23.3).

7. **Log local structuré** : l'agent écrit dans `C:\ProgramData\SambaEdu\Agent\logs\agent.log` (rotation quotidienne, 7 jours de rétention). Format : `[ISO 8601] [LEVEL] message`. Ce log est la trace locale de la boucle — le serveur voit ses rapports mais n'a pas accès aux logs client.

## Acceptance Criteria

### AC1 — Boucle complète : boot + timer + check-in (FR17, FR18, FR23)

**Given** un poste de lab enrôlé avec token valide (23.3) et le service SYSTEM démarré
**When** le service démarre (boot) puis à chaque timer (60 min + jitter ±10 %, configurable)
**Then** l'agent lit le token (`C:\ProgramData\SambaEdu\Agent\token`), appelle `GET /api/v1/agent/state` avec `If-None-Match` (ETag du dernier cache), persiste le cache + ETag sous `C:\ProgramData\SambaEdu\Agent\cache\`
**And** l'agent construit le rapport minimal (`items: []`, `agent_version: "1.0.0"`, `workstation.hostname` = nom court, `workstation.uuid`) et appelle `POST /api/v1/agent/report`
**And** le serveur reçoit et stocke le rapport (vérifiable via `AgentResourceState` / `agent_last_checkin_at` sur le poste lab) — la boucle est fermée.

### AC2 — Résilience canal : serveur injoignable, backoff exponentiel (FR22)

**Given** le serveur SE5 injoignable (network unreachable, timeout, 5xx)
**Then** l'agent fonctionne sur son dernier état en cache (aucun crash, aucun message d'erreur bloquant)
**And** les retries suivent un backoff exponentiel (30 s → 60 s → 120 s → … plafonné à ~3600 s) — jamais de retry agressif
**And** le log local indique clairement le serveur injoignable et le délai du prochain retry.

### AC3 — Rotation du token : gestion `X-Agent-New-Token` (D5, FR13)

**Given** le serveur renvoie `X-Agent-New-Token: <nouveau_token>` dans la réponse `GET /state` ou `POST /report`
**Then** l'agent écrit le nouveau token dans `C:\ProgramData\SambaEdu\Agent\token` et utilise le nouveau token dès le cycle suivant
**And** en cas de 401 avec le nouveau token (réponse perdue côté serveur, fenêtre de grâce), l'agent réessaie avec l'ancien (avant écrasement ou conservé en parallèle)
**And** un 401 irrécupérable → arrêt + log local SANS re-enrôlement automatique.

### AC4 — Quarantaine : check-ins légers, aucun rapport (FR22, FR15)

**Given** le serveur répond 403 `AGENT_QUARANTINED`
**Then** l'agent cesse de `POST /report` et de traiter l'état cible
**And** l'agent continue les `GET /state` (check-ins légers, cadence normale) pour signaler sa présence
**And** le log local indique la quarantaine.

### AC5 — Artefact signé (NFR6)

**Given** le script de build `agent/build/`
**When** la commande de build est exécutée
**Then** l'artefact produit (binaire ou script packagé) est **signé avec la CA interne SambaEdu** (`Set-AuthenticodeSignature` ou équivalent)
**And** Windows ne bloque pas l'exécution sur le poste lab (SmartScreen + politique d'exécution)
**And** le certificat racine de la CA interne est celui déjà déployé par la chaîne iPXE 23.3.

### AC6 — Structure repo et doc (Architecture)

**Given** le dossier `agent/` top-level (NEUF — jamais dans `app/`)
**Then** il contient `agent/windows/` (code agent), `agent/shared/` (parsing contrat v1), `agent/build/` (build/signature), `agent/README.md`
**And** `agent/README.md` documente : décision technologique motivée contre les 7 contraintes du cahier des charges, chemin du token (= contrat Epic 24), chemin du cache, format du log local, hostname court (résolution defer 24.1), NFC pour 24.4
**And** `docs/agent/agent-skeleton.md` (NEUF) documente côté serveur : ce que l'agent envoie (hostname court, uuid, `items: []`), la boucle attendue (état → cache → rapport), les codes HTTP que l'agent doit gérer (200/304/401/403/429/5xx)
**And** `docs/agent/enrollment.md` est INTOUCHÉ (chemin token = contrat figé 23.3) — zéro édit.

### AC7 — Tests serveur : vérification de la boucle bout-en-bout

**Given** `tests/Feature/Api/V1/Agent/AgentSkeletonE2eTest.php` (NEUF)
**Then** il simule ce qu'un agent squelette réel ferait, côté serveur uniquement (pas de test Windows) :
- `GET /state` avec token valide → 200 + ETag (vérifier la structure de l'enveloppe)
- `GET /state` avec `If-None-Match` = ETag valide → 304 (X-Agent-New-Token survit si rotation due — invariant D5)
- `POST /report` avec `items: []` + workstation hostname court + uuid → 200 `{success: true}`
- `POST /report` avec rotation due → 200 + `X-Agent-New-Token`
- `POST /report` avec hostname long (FQDN, ex. `salle101-pc03.sambaedu.local`) → 200 mais log `agent.report.identity_mismatch` (le report est accepté, le warning est émis — comportement 24.1 vérifié)
**And** baseline tests : `php artisan test --filter Agent` → **168 passed** (baseline post-24.1) + les nouveaux, zéro régression ; **jamais la suite complète** (décision Henri).

### AC8 — Chemin d'installation sur le poste lab

**Given** le poste de lab (windoobe, ws 49, déjà enrôlé avec le token de 23.3)
**Then** le service est installé manuellement sur le poste lab (par l'admin, script PowerShell d'installation)
**And** le service démarre au boot et exécute sa première boucle
**And** vérification sur le serveur : `workstations` table → `agent_last_checkin_at` mis à jour + au moins 1 rapport dans `agent_resource_states` (même vide)
**And** ce chemin d'installation MANUEL est suffisant pour ce MVP — la distribution automatique (bootstrap GPO, auto-update) sera la story 25.x.

## Tasks / Subtasks

- [x] **T1 — Structure du dossier `agent/` et décision technologique** (AC6)
  - [x] Créer `agent/windows/`, `agent/shared/`, `agent/build/` (vides avec `.gitkeep`)
  - [x] Rédiger `agent/README.md` : décision techno (PowerShell MVP → .NET target Epic 25) motivée contre les 7 contraintes, chemin token `C:\ProgramData\SambaEdu\Agent\token`, chemin cache `cache\`, format log, hostname court, NFC pour 24.4, note sur la CA interne et la signature Authenticode
  - [x] Créer `docs/agent/agent-skeleton.md` (NEUF côté serveur) : boucle attendue, hostname court (résolution defer 24.1), format rapport squelette, codes HTTP à gérer

- [x] **T2 — Module de parsing du contrat v1** (AC1)
  - [x] `agent/shared/ContractV1.ps1` (ou équivalent selon techno choisie) : fonctions `Parse-State`, `Build-Report`
  - [x] `Parse-State` : lit le JSON `se5.desired-state/v1`, valide `schema`, retourne les 3 portées
  - [x] `Build-Report` : construit le payload rapport minimal (`schema`, `generated_at`, `agent_version`, `workstation`, `items`) — pour cette story : `items = @()` (vide)
  - [ ] Tests unitaires du module de parsing (sans appel réseau) — fichiers de test sous `agent/shared/tests/` ou `agent/tests/` — _NON FAIT : décision orchestrateur « PAS de tests PowerShell » (couverture = tests serveur AgentSkeletonE2eTest + golden files partagés) ; à trancher en review_

- [x] **T3 — Couche HTTP agent** (AC1, AC2, AC3, AC4)
  - [x] `agent/shared/AgentHttpClient.ps1` (ou équivalent) : encapsule `GET /state` et `POST /report` — _équivalent : fonctions `Invoke-AgentHttp`/`Invoke-AgentHttpWithGrace` dans `agent/windows/SambaEduAgent.ps1` (consolidation décidée par l'orchestrateur)_
  - [x] `GET /state` : token en Bearer, `If-None-Match` si ETag en cache, lit `X-Agent-New-Token`
  - [x] `POST /report` : token en Bearer, corps JSON, lit `X-Agent-New-Token`
  - [x] Gestion des codes retour : 200 (ok), 304 (cache valide), 401 (token invalide → arrêt), 403 (quarantaine → check-ins légers seulement), 429 (throttle → backoff), 5xx/timeout (backoff exponentiel)
  - [x] Rotation token : écriture atomique de `C:\ProgramData\SambaEdu\Agent\token` sur `X-Agent-New-Token`

- [x] **T4 — Gestion du cache local** (AC1, AC2)
  - [x] `agent/windows/Cache.ps1` (ou équivalent) : lecture/écriture atomique de `C:\ProgramData\SambaEdu\Agent\cache\state.json` + `etag.txt` sous ACL SYSTEM + Administrators — _équivalent : fonctions `Initialize-AgentCache`/`Read-CachedEtag`/`Save-StateCache` dans `SambaEduAgent.ps1`_
  - [x] `applied-state.json` créé vide (infrastructure pour 24.4 — mode `default`, persistance dernier-appliqué)
  - [x] Fonction `Set-AgentAcl` : appliquer les ACL SYSTEM + Administrators sur les fichiers créés

- [x] **T5 — Boucle principale du service** (AC1, AC2, AC3, AC4)
  - [x] `agent/windows/ConvergenceLoop.ps1` (ou équivalent) : boucle principale (1 iteration = 1 cycle) — _équivalent : `Invoke-AgentCycle` + `Start-AgentLoop` dans `SambaEduAgent.ps1`_
    1. Lire token depuis `C:\ProgramData\SambaEdu\Agent\token`
    2. `GET /state` avec `If-None-Match`
    3. Si 200 : persister cache + ETag ; si 304 : réutiliser cache
    4. Construire rapport minimal (`items: []` pour cette story)
    5. `POST /report`
    6. Attendre timer (3600 s + jitter ±10 %)
  - [x] Gestion des états d'erreur : quarantaine, backoff, arrêt sur 401 irrécupérable
  - [x] Log local structuré : `C:\ProgramData\SambaEdu\Agent\logs\agent.log` (rotation quotidienne 7 jours)

- [x] **T6 — Service Windows SYSTEM** (AC1, AC5)
  - [x] `agent/windows/Install-Service.ps1` : script d'installation manuelle (lab) — `New-Service` ou `nssm`, démarrage automatique, compte SYSTEM — _nommé `Install-SambaEduAgent.ps1` (consigne orchestrateur) ; wrapper ServiceBase minimal compilé à l'install (un .ps1 ne parle pas le protocole SCM)_
  - [x] `agent/windows/Uninstall-Service.ps1` : script de désinstallation propre — _nommé `Uninstall-SambaEduAgent.ps1`_
  - [x] Service démarre au boot, relance automatique sur crash (delay 30 s)

- [x] **T7 — Build et signature** (AC5)
  - [x] `agent/build/Build-Agent.ps1` : script de build
    - Si PowerShell : package le script + dépendances en `.ps1` signé (ou archive zip signée)
    - `Set-AuthenticodeSignature -Certificate <cert-CA-interne>` sur l'artefact
    - Sortie dans `agent/build/dist/`
  - [x] Documenter dans `agent/README.md` : comment obtenir/installer le certificat de la CA interne SambaEdu

- [x] **T8 — Tests serveur** (AC7)
  - [x] `tests/Feature/Api/V1/Agent/AgentSkeletonE2eTest.php` : scenarios AC7
    - GET /state → 200 + ETag
    - GET /state avec If-None-Match valide → 304
    - POST /report items vide → 200 {success: true}
    - POST /report avec rotation due → 200 + X-Agent-New-Token
    - POST /report hostname long → 200 + log identity_mismatch (assert Log::channel('agent')->warning a été émis)
  - [ ] Run `php artisan test --filter Agent` → 168 + nouveaux, zéro régression, sur /vm — _PARTIEL : exécuté en LOCAL (Docker php:8.4-cli, SSH interdit par l'orchestrateur ET bloqué par la policy) : `--filter AgentSkeleton` = 12 passed (47 assertions) ; `--filter Agent` = 180 collectés (168 baseline + 12 nouveaux), 160 verts, 20 erreurs d'ENVIRONNEMENT (ext-ldap absente du conteneur, `LdapRecord\ldap_set_option()`) — aucun fichier PHP serveur modifié donc zéro vecteur de régression ; le run de confirmation sur /vm reste à faire par l'orchestrateur_

- [ ] **T9 — Installation et validation lab** (AC8)
  - [ ] Installer le service manuellement sur le poste windoobe (ws 49) via `Install-Service.ps1` — _ACTION HUMAINE (poste lab) — hors de portée du DEV, à exécuter par Henri/orchestrateur (runbook : docs/qa/domains/agent.md §2.2)_
  - [ ] Valider sur le serveur (/vm) :
    - `agent_last_checkin_at` mis à jour sur la ligne `workstations` du poste
    - Au moins 1 appel dans les logs channel `agent` (`agent.report.received`)
  - [ ] Documenter le résultat dans la section "Completion Notes List"

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (24.2) | Hors-scope (story) |
|---|---|
| Boucle squelette : GET state → cache → POST report vide | Handlers wallpaper/overlay (24.4) |
| Service SYSTEM + timer + jitter | Compagnon de session (24.3) |
| Rotation token (X-Agent-New-Token) | Distribution canari + auto-update (25.x) |
| Résilience backoff + quarantaine | Enrôlement porte 2 (25.3) |
| Artefact signé (CA interne) | UI conformité (24.5) |
| Cache état local + ACL SYSTEM | Mode strict/défaut (handlers 24.4) |
| Structure `agent/` top-level + README.md | Exposition toggle strict/défaut (27.1) |
| Tests serveur de la boucle | Tests Windows natifs (hors portée du CI Laravel) |
| `docs/agent/agent-skeleton.md` | `docs/agent/enrollment.md` (figé 23.3 — INTOUCHÉ) |
| Résolution defer 24.1 #8 (hostname court documenté) | Modification de `ReportController.php` (24.1, INTOUCHÉ) |

### Contrat v1 — ce que l'agent consomme (invariants figés, JAMAIS à modifier)

[Source: docs/agent/contract-v1.md ; tests/Fixtures/Agent/state.v1.json ; tests/Fixtures/Agent/report.v1.json]

**Enveloppe état reçue (`GET /state` → 200) :**
```json
{
  "schema": "se5.desired-state/v1",
  "generated_at": "2026-06-11T08:00:00+00:00",
  "ttl_seconds": 3600,
  "machine":      [],
  "session":      [],
  "machine_user": []
}
```
L'agent doit vérifier `schema` = `"se5.desired-state/v1"` et refuser un major inconnu (`v2`, `v3`…). Champ ajouté = ignoré (forward-compat §9).

**Rapport envoyé (`POST /report`) :**
```json
{
  "schema": "se5.desired-state/v1",
  "generated_at": "2026-06-11T08:05:00+00:00",
  "agent_version": "1.0.0",
  "workstation": { "hostname": "salle101-pc03", "uuid": "f1d2c3b4-…" },
  "items": []
}
```
Pour le squelette MVP, `items` est TOUJOURS vide. Le serveur accepte 200. Les items réels arrivent en 24.4.

**Règle hostname** (résolution defer 24.1) : `hostname` = nom COURT (sans domaine). Sous PowerShell : `$env:COMPUTERNAME`. Sous `Get-WmiObject` : `(Get-WmiObject Win32_ComputerSystem).Name`. Ces deux retournent le nom court.

**ETag** : le serveur émet `ETag: "6c0e8135…"` (avec guillemets RFC 7232). L'agent envoie `If-None-Match: "6c0e8135…"` verbatim. Si 304, l'état en cache est valide.

### Chemin du token — CONTRAT figé par 23.3

[Source: docs/agent/enrollment.md §3 — INTOUCHÉ]

- Chemin : `C:\ProgramData\SambaEdu\Agent\token`
- Format : 64 caractères hex, sans newline, sans espace
- ACL : lecture autorisée à `NT AUTHORITY\SYSTEM` (`*S-1-5-18`) et `BUILTIN\Administrators` (`*S-1-5-32-544`) uniquement
- L'agent lit ce fichier à chaque cycle (ne pas le mettre en mémoire entre les cycles — la rotation peut changer le fichier sur disque)

### Middleware AuthenticateAgentToken — comportements à gérer (figé 23.2)

[Source: app/Http/Middleware/AuthenticateAgentToken.php]

- Token manquant → `401` `{"error":"unauthorized","code":"AGENT_TOKEN_MISSING"}`
- Token invalide → `401` `{"error":"unauthorized","code":"AGENT_TOKEN_INVALID"}`
- Quarantaine → `403` `{"error":"forbidden","code":"AGENT_QUARANTINED"}`
- Rotation due → `X-Agent-New-Token: <nouveau_token>` dans la réponse (sur tout code 2xx)
- Anti-clonage MAC divergent → quarantaine (403) — le poste lab ne devrait pas changer de MAC

### Patterns existants côté serveur à connaître (ne pas modifier)

- **ReportController** (24.1) : compare `$report['workstation']['hostname']` à `$workstation->name` — si divergent → log warning `agent.report.identity_mismatch`, ingestion poursuit. Solution : envoyer hostname court.
- **StateController** (23.5) : `GET /state?user=<login>` pour la session — le squelette n'envoie PAS `?user=` (service SYSTEM, portée machine seulement).
- **ReportIngestService** (24.1) : `items: []` accepté, aucune ligne `agent_resource_states` créée pour une liste vide, `agent_last_checkin_at` mis à jour par le middleware (pas par le service).
- **Throttle** : `throttle:60,1` sur GET /state et POST /report — 60 requêtes par minute par IP. Le timer 60 min ne posera pas de problème.

### Architecture agent — conventions figées (NON négociables)

[Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md ; _bmad-output/planning-artifacts/epics-agent-desired-state.md#NFR]

- **NFR9 (anti-couteau-suisse)** : périmètre agent = « converger l'état, rapporter l'état » — rien d'autre. Inventaire, remote control, métrologie = autre logiciel. L'agent squelette se limite à son strict minimum.
- **NFR7 (critère Keycloak)** : aucune dépendance AD dans l'agent. L'auth = bearer token per-host (23.2). L'agent ne consulte jamais l'AD, Kerberos, ou LDAP.
- **NFR1 (login jamais bloquant)** : le service SYSTEM (portée machine) ne bloque JAMAIS le logon. La convergence session est asynchrone (24.3). Pour ce squelette : le service SYSTEM n'interagit PAS avec le logon.
- **FR18 (boucle générique)** : « pour chaque ressource : si !test → apply ; rapporter » — le squelette livre la boucle sans handler (test/apply toujours no-op, rapport toujours `items: []`).
- Code vit sous `agent/` top-level, JAMAIS dans `app/` (Laravel server-side code).

### Tests — conventions du canal agent

[Source: tests/Feature/Api/V1/Agent/StateEndpointTest.php ; tests/Feature/Api/V1/Agent/ReportEndpointTest.php]

- `Workstation::factory()` + `TokenRotationService::issueFor()` pour créer un poste enrôlé
- Rotation due : `$workstation->update(['agent_token_rotated_at' => now()->subDays(31)])` (> 30 jours = rotation_days)
- Mock channel `agent` avec `Log::shouldReceive('channel')->with('agent')->andReturn(...)` (pattern étendu en 24.1 pour couvrir debug/info/warning/error/critical)
- Helper de requête privé `report()` (pattern ReportEndpointTest) — créer l'équivalent `getState()` et `postReport()` dans le nouveau test
- **Uniquement `--filter Agent`** — jamais la suite complète (décision Henri). Baseline : **168 passed** (post-24.1).

### Project Structure Notes

- Racine = projet Laravel (édité sur l'hôte, exécuté sur la VM `/vm` ; sync inotify auto)
- **`agent/`** = nouveau dossier top-level à créer — code agent Windows hors Laravel
- Story avec **AUCUNE migration, AUCUNE route, AUCUNE config Laravel nouvelle** → VM : AUCUNE opération artisan requise pour la partie serveur (les tests Laravel utilisent le setup existant) ; uniquement déploiement manuel du service sur le poste lab pour AC8
- PHP-FPM user = `www-admin` (uid 599) — non applicable ici (pas de fichier PHP créé côté serveur)
- inotify ne propage PAS les suppressions ; `agent/` = nouveau dossier dans le repo git → synchronisé automatiquement à la création

### Intelligence stories précédentes

- **23.1 (done)** : contrat v1 figé, golden files `tests/Fixtures/Agent/{state,report}.v1.json` — INTOUCHABLES. Hash figé `FROZEN_STATE_HASH = 6c0e8135…`. L'agent doit respecter ce format exactement.
- **23.2 (done)** : middleware `AuthenticateAgentToken` — conventions 401/403, `X-Agent-New-Token`, `agent_last_checkin_at` écrit par le middleware (pas par le controller). Pattern `MacAddressNormalizer` pour l'anti-clonage.
- **23.3 (done)** : chemin token `C:\ProgramData\SambaEdu\Agent\token` = CONTRAT figé. ACL icacls `*S-1-5-18/*S-1-5-32-544`. `docs/agent/enrollment.md` = source de vérité, INTOUCHÉ.
- **23.4 (done)** : StateCompiler + WallpaperStateProvider/OverlayStateProvider — lecture seule, portées `machine`/`session`/`machine_user`. Le squelette ne les consomme pas directement mais l'agent recevra leurs items dans `GET /state`.
- **23.5 (done)** : `StateController` → enveloppe brute (pas de wrapper SE5), ETag via `setEtag()` + `isNotModified()`, `?user=` optionnel. Piège cache routes VM vécu (18/18 en 404).
- **24.1 (done)** : `POST /report` ingestion — `ReportController` compare hostname, `ReportIngestService` transactionnel, 3 tables `agent_*`, purge planifiée. **Defer #8 → cette story** : hostname court obligatoire. Tests baseline 168 passed.
- **Piège VM récurrent** : `config:cache` + `route:cache` + chown `www-admin` OBLIGATOIRES après toute modification de config/route. Ici : aucune route/config new côté serveur → pas d'opération requise pour les tests PHPUnit. Opération VM requise uniquement pour le déploiement lab (AC8).

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 24.2] — AC source, FR17-FR20, FR22-FR23
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md#NFR1, #NFR6, #NFR7, #NFR9] — contraintes non-fonctionnelles
- [Source: docs/agent/contract-v1.md §5, §6, §9] — mode default, rapport figé, règle d'évolution
- [Source: docs/agent/enrollment.md §3] — chemin token = contrat figé 23.3, INTOUCHÉ
- [Source: tests/Fixtures/Agent/state.v1.json ; tests/Fixtures/Agent/report.v1.json] — golden files normatifs
- [Source: app/Http/Middleware/AuthenticateAgentToken.php:56-61, 108, 152, 162-167] — codes 401/403, check-in, header rotation
- [Source: app/Http/Controllers/Api/V1/Agent/ReportController.php] — identity_mismatch warning (hostname court = fix defer #8)
- [Source: app/Http/Controllers/Api/V1/Agent/StateController.php] — ETag, 304, ?user= machine-only
- [Source: _bmad-output/implementation-artifacts/24-1-post-report-ingestion-stockage-rapports.md#Corrections post-review] — defer #8 hostname court documenté ici
- [Source: config/agent.php] — `ttl_seconds` (3600), `token_rotation_days` (30) — existants, consommés côté serveur
- [Source: tests/Feature/Api/V1/Agent/ReportEndpointTest.php ; StateEndpointTest.php] — patterns tests canal agent

## Dev Agent Record

### Agent Model Used

claude-fable-5 (Fable 5)

### Debug Log References

- Environnement : AUCUN binaire PHP ni `vendor/` sur l'hôte ; SSH interdit par la consigne orchestrateur ET bloqué par la policy d'exécution → tests lancés **en local** dans Docker (`php:8.4-cli` + `composer:2`, `vendor/` installé `--ignore-platform-reqs`, conforme à la pratique hôte documentée en mémoire projet).
- Shims hors-repo (jamais commités, montés dans le conteneur uniquement) : constantes `LDAP_OPT_*` + polyfill `ldap_escape` (`/tmp/se5-test-prepend.php`), `.env` factice monté dans le conteneur (le créer dans le repo aurait été sync inotify → écrasement du `.env` VM).
- `php artisan test --filter AgentSkeleton` (local Docker) : **12 passed (47 assertions)**.
- `php artisan test --filter Agent` (local Docker) : **180 collectés (= 168 baseline + 12 nouveaux), 160 passed, 20 erreurs d'environnement** — toutes `Call to undefined function LdapRecord\…` (ext-ldap absente du conteneur, tests AdSync/StateProvider qui ouvrent une connexion LdapRecord). Aucun fichier PHP serveur modifié par la story → zéro vecteur de régression ; run de confirmation sur /vm à faire par l'orchestrateur.

### Completion Notes List

- **Boucle squelette livrée** : `agent/windows/SambaEduAgent.ps1` — token relu sur disque à chaque cycle, `GET /state` avec `If-None-Match` (ETag verbatim guillemets inclus), persistance `cache\state.json`/`etag.txt` (écriture atomique + icacls `*S-1-5-18`/`*S-1-5-32-544`), rapport minimal `items: []` + `agent_version: "1.0.0"` + hostname COURT (`$env:COMPUTERNAME`, defer 24.1 #8 résolu) + UUID SMBIOS (`Get-CimInstance Win32_ComputerSystemProduct`), `POST /report`, timer 3600 s + jitter ±10 %, backoff 30 s → ×2 → plafond 3600 s, header `X-Agent-Hostname` présenté (anti-clonage 23.2).
- **Rotation D5** : `X-Agent-New-Token` lu sur TOUTE réponse (GET 200/304, POST même non-200), écrit atomiquement sur `C:\ProgramData\SambaEdu\Agent\token`, ancien token gardé EN MÉMOIRE pour la fenêtre de grâce (pas de fichier `token.previous` : surface disque minimale) ; 401 post-rotation → un réessai avec l'ancien ; 401 irrécupérable → arrêt + log, JAMAIS de re-enrôlement automatique.
- **Quarantaine 403** : flag process → plus de POST /report ni traitement d'état, GET /state continue à cadence normale (check-ins légers) ; levée automatique au premier 200/304.
- **Service SYSTEM** : `Install-SambaEduAgent.ps1` compile à l'install un wrapper `ServiceBase` minimal (Add-Type → exe) car un `.ps1` ne parle pas le protocole SCM (`New-Service` sur powershell.exe serait tué au timeout) ; `New-Service` + `sc.exe config obj= LocalSystem` + `sc.exe failure` (relance 30 s). `Uninstall-SambaEduAgent.ps1` conserve token/cache/logs par défaut (`-PurgeData` pour tout effacer).
- **Build signé (AC5)** : `agent/build/Build-Agent.ps1` — bundle à plat dans `dist/`, `Set-AuthenticodeSignature` SHA256 sur chaque `.ps1` (cert magasin par thumbprint ou PFX), garde-fou chaîne → racine SambaEdu, zip versionné (version lue dans `ContractV1.ps1`, source unique).
- **Décisions d'implémentation** : (1) consolidation HTTP/cache/boucle dans `SambaEduAgent.ps1` (noms de fichiers = consigne orchestrateur, story disait « ou équivalent ») ; (2) cadence configurable via `config.json` (`interval_seconds`) plutôt que clé de registre — cohérent avec l'approche fichier du dossier Agent, documenté README + agent-skeleton.md ; (3) log `logs\agent.log` rotation quotidienne 7 j (chemin de la décision design n° 7) ; (4) `applied-state.json` créé vide `{}` (infra 24.4).
- **Tests serveur (AC7)** : `AgentSkeletonE2eTest.php`, 12 tests/47 assertions — enveloppe brute + ETag quoté, 304 sans corps, rapport vide → counts zéro + rien écrit + check-in stampé, cycle complet enchaîné, rotation sur GET 200 / 304 / POST avec nouveau token immédiatement utilisable, hostname court sans warning vs FQDN → `identity_mismatch` (1 warning, rapport accepté), quarantaine 403 state (check-in stampé) + report (rien écrit), 401 token invalide. Conventions 23.5/24.1 (helpers privés, captureAgentLogs, factories + `issueFor`).
- **Écarts assumés** (à arbitrer en review) : T2.4 tests unitaires PowerShell NON livrés (consigne orchestrateur « PAS de tests PowerShell » — couverture par les tests serveur + golden files) ; T8.2 run /vm remplacé par run local Docker (SSH interdit/bloqué — cf. Debug Log) ; T9 installation lab = action humaine restante (runbook prêt : `docs/qa/domains/agent.md` §2.2-2.3). `.gitkeep` inutiles (dossiers non vides dès la création).
- **Intouchables vérifiés** : `contract-v1.md`, `enrollment.md`, golden files, `FROZEN_STATE_HASH`, `ReportController`, `StateController`, `AuthenticateAgentToken`, tests existants — zéro édit (git status : uniquement créations + `docs/qa/domains/agent.md` enrichi + story + sprint-status).

### File List

**Créés (agent/ top-level — PowerShell, hors Laravel) :**
- agent/README.md (décision techno 7 contraintes, chemins/contrats locaux, signature, install)
- agent/shared/ContractV1.ps1 (constantes contrat figées, Parse-State, Build-Report)
- agent/windows/SambaEduAgent.ps1 (boucle complète : HTTP + cache + rotation + backoff + quarantaine + log)
- agent/windows/Install-SambaEduAgent.ps1 (service SYSTEM via wrapper ServiceBase, config.json, relance 30 s)
- agent/windows/Uninstall-SambaEduAgent.ps1 (désinstallation propre, -PurgeData optionnel)
- agent/build/Build-Agent.ps1 (bundle dist/ + Set-AuthenticodeSignature + zip versionné)

**Créés (côté serveur Laravel) :**
- docs/agent/agent-skeleton.md (boucle vue serveur, hostname court, codes HTTP, fichiers poste)
- tests/Feature/Api/V1/Agent/AgentSkeletonE2eTest.php (12 tests, 47 assertions)

**Modifiés :**
- docs/qa/domains/agent.md (Section 2 — e2e boucle squelette : 2.1 simulation curl, 2.2 install lab, 2.3 résilience ; header + checklist)
- _bmad-output/implementation-artifacts/24-2-agent-squelette-windows-service-checkin-cache-build.md (cette story)
- _bmad-output/implementation-artifacts/sprint-status.yaml (24-2 → review)

**Intouchables (figés — zéro édit) :**
- docs/agent/contract-v1.md
- docs/agent/enrollment.md
- tests/Fixtures/Agent/state.v1.json
- tests/Fixtures/Agent/report.v1.json
- app/Http/Controllers/Api/V1/Agent/ReportController.php
- app/Http/Controllers/Api/V1/Agent/StateController.php
- app/Http/Middleware/AuthenticateAgentToken.php
- app/Services/Agent/StateContract.php
- app/Services/Agent/StateHasher.php

## Recommandation Modèle Dev

**fable.** Cette story est la plus complexe de l'Epic 24 côté implémentation : elle initie le premier artefact client (nouveau dossier `agent/` top-level, choix technologique à justifier contre 7 contraintes, service Windows SYSTEM avec cycle de convergence complet, gestion de la rotation token D5 côté poste, backoff exponentiel, cache sous ACL SYSTEM, signature Authenticode). En parallèle côté serveur : tests E2E de la boucle complète et résolution du defer 24.1 #8 (hostname court). La gestion correcte de l'invariant D5 (rotation token côté agent) et du mode quarantaine nécessite le même niveau de raisonnement que les stories 23.x (contrat figé, séquence d'appels, cas limites 401 avec grâce). `opus` se justifierait pour 24.5 (UI conformité Livewire) ou 24.3 (compagnon de session avec contrainte login jamais bloquant mesurée) — pour cette story d'intégration système, `fable` est le bon équilibre entre profondeur et vélocité.

## Change Log

- 2026-06-11 — Story 24.2 créée (SM/orchestrateur) : agent squelette Windows — boucle GET state → cache → POST report vide, service SYSTEM + timer, rotation token D5 côté poste, résilience backoff, cache sous ACL SYSTEM, artefact signé, structure `agent/` top-level. Résout le defer 24.1 #8 (hostname court). Status backlog → ready-for-dev.
- 2026-06-12 — Story 24.2 développée (DEV claude-fable-5) : `agent/` top-level livré (README 7 contraintes, ContractV1.ps1, SambaEduAgent.ps1 boucle complète, Install/Uninstall service SYSTEM, Build-Agent.ps1 signé), `docs/agent/agent-skeleton.md` (NEUF), domaine QA agent.md §2, `AgentSkeletonE2eTest.php` 12 tests/47 assertions verts en local (Docker — pas de PHP hôte, SSH interdit). Defer 24.1 #8 résolu (hostname court implémenté + documenté + testé). Restent : run de confirmation /vm (1 commande) + T9 install lab (action humaine). Status ready-for-dev → review.
