# Story 23.3: Enrôlement porte 1 — le token naît à l'install iPXE

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'**admin d'établissement**,
je veux **que les postes installés par la chaîne iPXE soient enrôlés automatiquement**,
afin qu'**aucune action manuelle ne soit nécessaire pour les postes neufs**.

## Contexte & intention

Troisième story de l'Epic 23. La 23.1 (done) a figé le contrat ; la 23.2 (**in-progress au moment de la création de cette story — DÉPENDANCE DURE, ne pas démarrer le dev avant qu'elle soit done**) livre le cycle de vie du token (`TokenRotationService`, middleware, colonnes `agent_*`). Cette story branche la **naissance du token sur la chaîne d'install iPXE Windows** (porte 1 — l'admin est déjà authentifié au menu iPXE, story 4.10) : un **ticket d'enrôlement one-time** est émis côté serveur à la génération de l'`unattend.xml`, le poste l'échange contre son token au premier logon via un **nouvel endpoint** `POST /api/v1/agent/enrollment`, et le token est déposé sous **ACL SYSTEM** — c'est le fichier que l'agent (Epic 24) lira.

Ce qui vient APRÈS et qui consomme cette story :

- l'agent côté poste qui lit le fichier token → **Epic 24** (le chemin du fichier devient un CONTRAT, à documenter)
- `GET /api/v1/agent/state` derrière `agent.token` → **Story 23.5**
- porte 2 (postes migrés, sans ticket, approbation un-clic) → **Story 25.3** (réutilisera ce même endpoint avec un autre mode)
- dépôt du binaire agent via le dépôt iPXE → **Story 25.4** (ici on ne dépose QUE le token)

## ⚠️ Cinq pièges découverts à l'analyse (lire avant de coder)

1. **`config/agent.php` existe DÉJÀ** — le dev 23.2 l'a créé par anticipation (clé `token_rotation_days` seule, remarque explicite d'Henri à la création de cette story). L'**étendre** (ajouter `enroll_ticket_ttl_minutes`), ne JAMAIS le recréer ni écraser le commentaire « complété en 23.5 ». Tout changement sous `config/` ⇒ `php artisan config:cache` + chown www-admin sur la VM.
2. **Collision de route** : `POST /api/v1/agent/enroll` + nom `agent.v1.enroll` sont **PRIS** par le canal JWT legacy-migration (`routes/api.php:184-186`, middleware `auth.v1.bootstrap`) — piège n° 1 documenté en 23.2. Résolution actée ici : nouvelle route `POST /api/v1/agent/enrollment` (nom `agent.v1.enrollment`), canal legacy **intouché** (frontière architecture). Quand le canal legacy s'éteindra (Epic 27), `/enroll` se libérera — sans impact : seuls la chaîne d'install et le bootstrap GPO appellent cet endpoint, jamais l'agent en routine.
3. **« Déposé à l'install WinPE » (AC epic) est littéralement impossible** : `setup.exe` formate le disque cible (`DiskConfiguration` de l'unattend) et **ne rend jamais la main** (constats 3.5/3.8 — tout fichier écrit en phase WinPE est détruit). Le dépôt se fait donc au **premier logon** via `FirstLogonCommands` (unattend.xml), et **AVANT** les deux commandes existantes (le `curl etape=oobe` récupère un `action.cmd` qui peut rebooter en ~5 s — ordre 1-2 pour l'enrôlement, les commandes actuelles glissent en 3-4).
4. **Fixtures de parité legacy** : les templates `resources/views/ipxe/windows/cmd/*.blade.php` sont verrouillés par des tests de parité byte-équivalente (story 3.8, `tests/fixtures/ipxe/legacy-cmd-action/`). La purge token dans `sysprep.blade.php` (piège n° 5) est une **divergence assumée** : mettre à jour la fixture + commenter la divergence dans le template (pattern des divergences 3.5 déjà documentées).
5. **Clonage sysprep = bombe anti-clone** : le token est déposé au premier logon du master ; si l'image est capturée avec, **N clones présenteront le token du master** → `clone_detected` + quarantaine en masse (mécanique 23.2). Le cmd `sysprep` DOIT purger `C:\ProgramData\SambaEdu\Agent\` avant generalize.

## Acceptance Criteria

### AC1 — Le ticket d'enrôlement naît à la génération de l'install

**Given** un poste dont la chaîne iPXE génère l'`unattend.xml` (`IpxeWindowsUnattendController::handle`, workstation déjà résolu — créé en amont par `WorkstationEnrollmentService`)
**When** l'unattend est servi
**Then** un ticket one-time est émis (`EnrollmentService::openTicket()`) : 64 hex aléatoires, **hash SHA-256 en DB** (`agent_enroll_ticket_hash` + `agent_enroll_ticket_expires_at` = now + `config('agent.enroll_ticket_ttl_minutes')`), clair interpolé dans l'unattend uniquement
**And** une re-génération (re-fetch WinPE) remplace simplement le ticket précédent (colonne écrasée, pas d'erreur)
**And** émission loggée `agent.enroll.ticket_opened` (channel `agent`, contexte `workstation_id`, jamais le ticket en clair).

### AC2 — Réinstallation = révocation immédiate (FR14, AC epic n° 2)

**Given** un poste **déjà enrôlé** (`agent_token_hash` non null) qui repasse par la chaîne d'install
**When** son ticket est émis (AC1)
**Then** l'ancien token est révoqué immédiatement via `TokenRotationService::revokeFor($ws, 'reinstall')` (l'API 23.2 prévue pour ça) — le clone éventuel du poste meurt au début de la réinstall, pas à la fin
**And** log `agent.enroll.reinstall_revoked`.

### AC3 — L'échange ticket → token (le token naît haché en DB)

**Given** le nouvel endpoint `POST /api/v1/agent/enrollment` (controller `App\Http\Controllers\Api\V1\Agent\EnrollController`, middleware `local.request` + `throttle:10,1` — PAS `agent.token` : le poste n'a pas encore de token)
**When** le poste poste `{ticket, uuid, mac, hostname}` avec un ticket valide (résolution par **hash du ticket**, pas par uuid — le ticket EST l'identité)
**Then** 200, le token naît via `TokenRotationService::issueFor()` (haché en DB, clair renvoyé **une seule fois** dans la réponse JSON format SE5 `{success, token}`), le ticket est consommé (colonnes effacées)
**And** la réponse porte `Cache-Control: no-store` (réutiliser `auth.v1.secure-headers` — hygiène HTTP, pas une dépendance au canal JWT)
**And** uuid/mac/hostname reçus sont confrontés à la fiche pour log de cohérence (`agent.enroll.identity_mismatch` en warning si divergence — pas de blocage : la fiche peut être en avance sur le poste en cours d'install)
**And** log `agent.enroll.enrolled`.

### AC4 — Conflit → 409, rien n'est écrasé silencieusement

**Given** une demande SANS ticket valide (absent, inconnu, expiré ou déjà consommé)
**Then** si le poste visé (résolu par uuid, à défaut mac) est **déjà enrôlé** → **409** (`code: AGENT_ENROLL_CONFLICT`), son token actuel reste intact
**And** sinon (poste non enrôlé ou inconnu) → **403** (`code: AGENT_ENROLL_NOT_ALLOWED`) — c'est le futur point d'accueil de la porte 2 (25.3), sans oracle distinguant inconnu/expiré/consommé
**And** tentatives loggées `agent.enroll.rejected` (warning, avec raison interne).

### AC5 — Dépôt sur le poste sous ACL SYSTEM

**Given** l'`unattend.xml` généré (template + `WindowsUnattendBuilder`)
**Then** les `FirstLogonCommands` **ordres 1-2** (avant le curl `etape=oobe` existant, qui glisse en 3-4) : (1) échange du ticket et écriture du token dans `C:\ProgramData\SambaEdu\Agent\token` (**décision Henri 2026-06-11** : ProgramData retenu plutôt que `%PROGRAMFILES%\SambaEdu` — Program Files est lisible par les Users standard par défaut, l'élève pourrait lire le token ; curl/PowerShell, URL **absolue** `http://###_SE4FS_NAME_###/api/v1/agent/enrollment`), (2) verrouillage `icacls` : héritage coupé (`/inheritance:r`), accès **SYSTEM + Administrators uniquement** (frontière de confiance architecture)
**And** le ticket est interpolé via le mécanisme de placeholders existant (`###_AGENT_ENROLL_TICKET_###`, sanitization iso `WindowsXmlPlaceholders`)
**And** un échec d'enrôlement ne bloque PAS la suite de l'install (commande non bloquante, l'install continue — le poste retombera sur la porte 2 / bootstrap GPO)
**And** aucun credential **durable** en clair dans les templates : le ticket résiduel dans `C:\Windows\Panther\unattend.xml` est single-use déjà consommé (risque assumé, iso-legacy — l'unattend y laisse déjà les mots de passe AutoLogon ; à documenter).

### AC6 — Hygiène clonage (piège n° 5)

**Given** le template `sysprep.blade.php`
**Then** la purge `C:\ProgramData\SambaEdu\Agent\` est exécutée AVANT le generalize (divergence parité assumée, fixture mise à jour)
**And** la doc explique : les clones déployés repassent par OOBE → sans ticket valide → porte 2 (25.3) ; en attendant, un clone sans token est simplement non-enrôlé (pas de quarantaine de masse).

### AC7 — Transversal : zéro AD + contrat figé + logs

**Then** aucun appel AD/LdapRecord dans `app/Services/Agent/` ni `EnrollController` (critère Keycloak, grep en review)
**And** `docs/agent/contract-v1.md` est **intouché** (le ticket et le token sont du transport)
**And** toutes les transitions loggées channel `agent` : `agent.enroll.ticket_opened`, `agent.enroll.reinstall_revoked`, `agent.enroll.enrolled`, `agent.enroll.identity_mismatch`, `agent.enroll.rejected` — jamais de ticket/token en clair.

## Tasks / Subtasks

- [x] **T1 — Migration colonnes ticket** (AC1)
  - [x] `agent_enroll_ticket_hash` string(64) nullable + index unique ; `agent_enroll_ticket_expires_at` timestamp nullable — sur `workstations`, idempotence `Schema::hasColumn()`, iso `2026_06_11_120000_add_agent_token_columns_to_workstations.php`.
- [x] **T2 — Étendre `config/agent.php`** (AC1, piège n° 1)
  - [x] Ajouter `enroll_ticket_ttl_minutes => (int) env('AGENT_ENROLL_TICKET_TTL_MINUTES', 240)` (4 h couvre une install lente ; one-time de toute façon). Ne pas toucher `token_rotation_days`. `.env.example` à compléter.
- [x] **T3 — `App\Services\Agent\Enrollment\EnrollmentService`** (AC1-AC4) — emplacement prévu par l'arbre architecture, à côté de `TokenRotationService`
  - [x] `openTicket(Workstation): string` — révoque l'ancien token si enrôlé (AC2), génère `bin2hex(random_bytes(32))`, stocke hash + expiry, logs.
  - [x] `redeem(string $ticket, array $identity): EnrollmentResult` — lookup par `hash('sha256', $ticket)` + non-expiré ; consomme le ticket ; `issueFor()` ; retourne le token clair. Cas d'échec → résultat typé (conflit/refus) pour que le controller mappe 409/403 (logique métier dans le service, pas le controller — règle architecture).
  - [x] `declare(strict_types=1)`, classe pure injectable, singleton dans `AgentServiceProvider` (créé en 23.2).
- [x] **T4 — Endpoint** (AC3, AC4, piège n° 2)
  - [x] `App\Http\Controllers\Api\V1\Agent\EnrollController` (mince) + FormRequest (`ticket` requis string, `uuid`/`mac`/`hostname` optionnels) ; route dans `routes/api.php`, **groupe NEUF commenté** (canal agent desired-state), URI `/v1/agent/enrollment`, nom `agent.v1.enrollment`, middlewares `local.request` + `auth.v1.secure-headers` + `throttle:10,1`, `withoutMiddleware(['web'])` si pattern requis (vérifier : api.php n'a pas le groupe web).
  - [x] Réponses : 200 `{success: true, token}`, 409/403 format SE5 `{error, message, code}` iso `EnsureWorkstationJwt`.
- [x] **T5 — Génération unattend** (AC1, AC5)
  - [x] `IpxeWindowsUnattendController::handle` (ou le service qu'il délègue) : appel `openTicket()` une fois le workstation résolu — uniquement si la story 23.2 est migrée (colonnes présentes ; pas de feature flag dédié, la chaîne Windows a déjà son toggle `ipxe.windows.*`).
  - [x] `resources/ipxe/windows/unattend.xml` : 2 nouvelles `SynchronousCommand` ordres 1-2 (échange + icacls), commandes existantes ré-ordonnées 3-4 ; placeholder `###_AGENT_ENROLL_TICKET_###`.
  - [x] `WindowsUnattendBuilder` : interpolation du placeholder (mécanisme existant `###_KEY_###`, sanitization `WindowsXmlPlaceholders`) ; tests builder mis à jour.
  - [x] Suggestion d'implémentation dépôt (à affiner en dev) : `powershell -Command "$r = Invoke-RestMethod -Method Post -Uri 'http://###_SE4FS_NAME_###/api/v1/agent/enrollment' -Body @{ticket='###_AGENT_ENROLL_TICKET_###'; uuid=$uuid; ...}; New-Item -Force -ItemType Directory 'C:\ProgramData\SambaEdu\Agent' | Out-Null; Set-Content -NoNewline 'C:\ProgramData\SambaEdu\Agent\token' $r.token"` puis `icacls "C:\ProgramData\SambaEdu\Agent" /inheritance:r /grant:r "SYSTEM:(OI)(CI)F" "Administrators:(OI)(CI)F"`. Échec non bloquant (pas de `--fail` qui casse la chaîne).
- [x] **T6 — Purge clonage** (AC6, pièges n° 4-5)
  - [x] `sysprep.blade.php` : `if exist "C:\ProgramData\SambaEdu\Agent" (RD /S /Q "C:\ProgramData\SambaEdu\Agent")` avant le generalize ; CRLF strict ; fixture parité `tests/fixtures/ipxe/legacy-cmd-action/` mise à jour + commentaire divergence.
- [x] **T7 — Documentation** (AC5, AC7)
  - [x] `docs/agent/enrollment.md` (nouveau) : flux porte 1 complet (ticket → échange → dépôt), **chemin du fichier token = contrat avec l'Epic 24** (`C:\ProgramData\SambaEdu\Agent\token`), codes 409/403, TTL, risque Panther assumé, purge sysprep, renvoi porte 2 → 25.3.
  - [x] `docs/agent/token-lifecycle.md` (23.2) : remplacer le renvoi « naissance → 23.3 » par un lien vers `enrollment.md` (seul changement).
- [x] **T8 — Tests** (tous AC)
  - [x] `tests/Unit/Services/Agent/EnrollmentServiceTest.php` : ticket 64 hex, hash stocké ≠ clair, openTicket révoque si enrôlé / n'explose pas sinon, re-open écrase, redeem consomme + issue, redeem expiré/inconnu/déjà-consommé → résultats d'échec typés.
  - [x] `tests/Feature/Api/V1/Agent/EnrollmentEndpointTest.php` (`RefreshDatabase` + `Workstation::factory()`, iso `AuthenticateAgentTokenTest` 23.2) : 200 + token utilisable derrière `agent.token` (route éphémère) + ticket consommé + no-store ; rejouer le même ticket → 403 ; expiré → 403 ; sans ticket + poste enrôlé (uuid fourni) → 409 + token intact ; sans ticket + poste inconnu → 403 ; réinstall complète (enrôlé → openTicket → ancien token 401 → redeem → nouveau token 200) ; token/ticket jamais dans les logs (assertion sur le contexte loggé).
  - [x] Tests unattend : placeholder interpolé + sanitizé, ordre des FirstLogonCommands (enrôlement avant le curl oobe), URLs absolues.
- [x] **T9 — Vérifications finales**
  - [x] `php -l` sur tous les fichiers créés/modifiés.
  - [x] `php artisan test --filter Agent` sur `/vm` (les tests 23.1 + 23.2 restent verts) + suite iPXE (`--filter Ipxe`) sans régression hors divergence sysprep assumée.
  - [x] Grep critère Keycloak (AC7) → vide.
  - [x] Sur la VM : `php artisan migrate` + `php artisan config:cache` + chown www-admin sur `bootstrap/cache/config.php`.

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (23.3) | Hors-scope (story) |
|---|---|
| Colonnes `agent_enroll_ticket_*` + migration | Porte 2 postes migrés, approbation un-clic, levée de quarantaine → **25.3** |
| `EnrollmentService` (openTicket/redeem) | `GET /state`, ETag, autres clés `config/agent.php` → **23.5** |
| `POST /api/v1/agent/enrollment` + `EnrollController` | Dépôt du **binaire** agent (ici : token seul) → **25.4** / Epic 24 |
| Ticket dans l'unattend + dépôt token ACL SYSTEM | Chaîne d'install **Linux** (pourra appeler le même endpoint plus tard — l'agent Linux n'existe pas avant l'adaptateur Epic 24+) |
| Purge sysprep + fixture parité | Toute modif des routes/canal JWT legacy (`agent.v1.enroll` etc.) — intouché |
| `docs/agent/enrollment.md` | UI (le bloc Agent page machine, livré en 23.2, couvre déjà l'état enrôlé) |

### Décisions de design prises ici (à challenger en review, pas à re-trancher en dev)

1. **Ticket one-time plutôt que token interpolé dans les scripts** : le token directement dans l'unattend/action.cmd serait un credential durable en clair (résidus `%windir%\action.cmd`, Panther) — violation AC epic. Le ticket, lui, est consommé au premier usage ; ce qui traîne sur le disque est inerte. C'est aussi ce qui donne un sens au 409 (l'endpoint existe, AC epic le nomme).
2. **Résolution par hash du ticket, pas par uuid** : l'uuid est spoofable sur le LAN (l'endpoint est `local.request` sans autre auth) ; le ticket est le secret. uuid/mac servent au log de cohérence et au choix 409/403, jamais à l'autorisation.
3. **Révocation à `openTicket()` et non à `redeem()`** : « réinstall = événement de révocation » (AC epic) — révoquer au début de la réinstall ferme la fenêtre où un clone de l'ancien token vivrait pendant que le disque est formaté.
4. **Dépôt au premier logon, ordres 1-2** : seul moment où (réseau up + disque définitif + contexte admin élevé + exécution garantie avant un reboot déclenché par `action.cmd`). « Install WinPE » de l'AC epic = la chaîne d'install au sens large ; divergence littérale documentée (piège n° 3).
5. **409 vs 403** : 409 réservé au cas « poste identifiable ET déjà enrôlé » (conflit réel, rien d'écrasé) ; tout le reste → 403 indistinct (pas d'oracle sur l'état des tickets). La porte 2 transformera le 403 en « demande d'approbation » sans casser ce contrat.
6. **TTL ticket 240 min** : couvre les installs lentes (miroir froid, poste poussif) sans laisser traîner un secret actif des jours. Configurable.

### Patterns existants à imiter (NE PAS réinventer)

- **Hash + lookup one-time** : `TokenRotationService::issueFor()` (23.2) — `bin2hex(random_bytes(32))`, `hash('sha256', …)`, jamais de clair persisté/loggé. Le ticket suit exactement le même pattern.
- **Réponses d'erreur** : `EnsureWorkstationJwt` → `{error, message, code}` ; codes 409 `AGENT_ENROLL_CONFLICT` / 403 `AGENT_ENROLL_NOT_ALLOWED`.
- **Route poste sans JWT** : endpoints WPKG 17.6 (`local.request` + throttle, pas de bearer) — même profil de consommateur (poste en install).
- **Placeholders unattend** : `WindowsUnattendBuilder::…` interpolation `###_KEY_###` dans les `CommandLine` (lignes ~561-600) + `WindowsXmlPlaceholders::sanitizeBatPlaceholder`.
- **CRLF strict templates cmd** : builder 3.8, re-write post-render + fixtures parité.
- **Logging channel `agent`** : conventions posées en 23.2 (`config/logging.php:173`, actions namespacées, contexte `workstation_id`).
- **Migration idempotente** : `2026_06_11_120000_add_agent_token_columns_to_workstations.php`.
- **Factory + RefreshDatabase** : `tests/Feature/Api/V1/Agent/AuthenticateAgentTokenTest.php` (23.2) — NE PAS utiliser le trait `SeedsWorkstationConfig` (table manuelle sans colonnes `agent_*`).

### Architecture — conventions figées applicables (NON négociables)

[Source: architecture-agent-desired-state.md#API & Communication / #Naming / #Boundaries / #Integration Points]

- Endpoint agent sous `/api/v1/agent/*` exclusivement ; controller `App\Http\Controllers\Api\V1\Agent\EnrollController` ; service `App\Services\Agent\Enrollment\EnrollmentService` (arbre architecture).
- Codes du canal : 200 / 401 / 403 / **409 (conflit d'enrôlement)** — figés au contrat.
- Le canal agent n'écrit QUE dans les colonnes/tables `agent_*`. Aucune écriture AD (critère Keycloak), aucune logique métier dans le controller.
- Intégration iPXE = « dépose le token à l'install (porte 1) — touche les templates iPXE existants » : c'est LE point d'intégration prévu, pas une dérive de périmètre.
- Frontière de confiance : binaire + config agent sous ACL SYSTEM côté poste.

### Chaîne d'install Windows — repères précis (analysés sur le code)

- `IpxeWindowsUnattendController::handle` (route `ipxe.windows.unattend`, lan-only, `routes/web.php:962-967`) sert l'unattend per-poste ; le workstation est résolu en amont (créé par `WorkstationEnrollmentService::enrollName`, `app/Ipxe/Services/WorkstationEnrollmentService.php:192` pour le path create).
- `unattend.xml` (`resources/ipxe/windows/unattend.xml:107-120`) : 2 `FirstLogonCommands` existantes — curl `etape=oobe` → `%windir%\action.cmd` puis exécution. L'`action.cmd` retourné peut contenir `shutdown -r -t 5` (cf. `join.blade.php:22`) → d'où l'obligation d'insérer l'enrôlement AVANT.
- L'AutoLogon OOBE tourne en admin local avec `EnableLUA=false` → écriture ProgramData + `icacls` OK sans élévation supplémentaire.
- `curl.exe` est présent nativement (Win10 1803+) ; `Invoke-RestMethod` dispo partout — au choix du dev (PowerShell recommandé pour parser le JSON proprement).
- Étapes post-OOBE (`/ipxe/windows/action`, state machine 3.8) : ne PAS y brancher l'enrôlement (chemins multiples selon type/role — perso/join/post/wpkg — le FirstLogon est le seul point de passage universel).

### Project Structure Notes

- **Racine = projet Laravel** ; code édité sur l'hôte, exécuté sur la VM `/vm` (`ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`, `/var/www/sambaedu-reload`) ; sync inotify auto — jamais de sync manuel ; si non-synchro → notifier Henri et attendre.
- ⚠️ `config/agent.php` modifié → sur la VM : `php artisan config:cache` + chown www-admin `bootstrap/cache/config.php` (sinon clé `null` en HTTP, tests verts mais install KO) + `php artisan migrate` (colonnes ticket).
- inotify ne propage pas les suppressions — aucun fichier supprimé prévu ici.
- ⚠️ **Pré-requis bloquant : story 23.2 done** (TokenRotationService/middleware/migration déjà présents sur le disque au moment de la création de cette story, mais la 23.2 est in-progress — vérifier son statut dans sprint-status.yaml avant de démarrer, et reprendre ses Completion Notes comme intelligence fraîche).

### Testing standards

- PHPUnit, référence `/vm` ; SQLite `:memory:` en test — colonnes simples, pas de piège varchar.
- `--filter Agent` doit rester intégralement vert (23.1 : 19 tests ; 23.2 : viser son compte final) + les nouveaux.
- Suite `--filter Ipxe` : la divergence sysprep est la SEULE rupture de parité attendue (fixture mise à jour) ; tout autre rouge = régression.
- Tests unattend : vérifier l'ORDRE des SynchronousCommand (l'enrôlement avant le curl oobe) — c'est le bug le plus probable de cette story.

### Intelligence stories précédentes

- **23.1 (done)** : contrat figé — ne pas toucher `contract-v1.md` ni les golden files ; `declare(strict_types=1)` partout, classes pures injectables, doc des décisions dans `docs/agent/`.
- **23.2 (in-progress)** : API consommée ici = `issueFor()` / `revokeFor()` (signatures vérifiées sur le disque : `app/Services/Agent/Enrollment/TokenRotationService.php:46,106`). `AgentServiceProvider` existe (y enregistrer `EnrollmentService`). Le channel log `agent` et `config/agent.php` existent. Pièges 23.2 hérités : pas de Sanctum effectif (custom-colonne), routes legacy `agent.v1.*` intouchées, anti-clone MAC=quarantaine. **Reprendre les Completion Notes de la 23.2 à son passage done** — si le dev 23.2 a dévié (noms, signatures), cette story suit le code livré, pas le papier.

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 23.3] — ACs source, FR16 porte 1.
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md#Authentication & Security / #API & Communication Patterns / #Integration Points] — deux portes, 409, intégration iPXE/WinPE.
- [Source: _bmad-output/implementation-artifacts/23-2-cycle-vie-token-agent.md] — pièges n° 1-3 hérités, API TokenRotationService, conventions log/erreurs.
- [Source: routes/api.php:178-200] — collision `agent.v1.enroll` (canal JWT legacy, intouché).
- [Source: resources/ipxe/windows/unattend.xml:107-120 ; app/Ipxe/Services/WindowsUnattendBuilder.php:561-600] — FirstLogonCommands + placeholders.
- [Source: app/Ipxe/Services/WindowsInstallBatBuilder.php:120-131] — setup.exe ne rend jamais la main (dépôt WinPE impossible).
- [Source: resources/views/ipxe/windows/cmd/{join,post,sysprep}.blade.php] — state machine post-OOBE, parité CRLF, point de purge sysprep.
- [Source: docs/agent/token-lifecycle.md] — naissance du token (renvoi à mettre à jour T7).

## Dev Agent Record

### Agent Model Used

claude-fable-5 (Fable 5 — décision Henri pour tout l'Epic 23). Workflow dev-story, branche main, 2026-06-11. GO explicite d'Henri malgré 23-2 en `review` (passage à done en cours) — le code consommé (`issueFor`/`revokeFor`) suit le disque, conforme au papier.

### Debug Log References

- Suite complète VM, 1er run : 3 failed — `ScriptsOsNamespaceTest::route_is_protected_by_workstation_jwt_middleware` cassé par le bloc route 23.3 initialement inséré ENTRE le groupe legacy `v1/agent` et le bloc 16.12 (le test inspecte les 1500 chars précédant `script-execution-logs` dans `routes/api.php` et y attend `auth.v1.workstation` ; mon commentaire de ~1500 chars l'avait poussé hors fenêtre). Fix : bloc 23.3 déplacé APRÈS le groupe 16.12. Les 2 autres failures = préexistantes hors scope (cf. notes).
- Smoke HTTP réel sur VM (post route:cache) : `POST /api/v1/agent/enrollment` ticket inconnu → `403 {"error":"forbidden","code":"AGENT_ENROLL_NOT_ALLOWED"}` + entrée `agent.enroll.rejected` dans `storage/logs/agent/agent.log` (reason interne, jamais le ticket).

### Completion Notes List

- **AC1-AC7 livrés.** Ticket one-time : 64 hex, SHA-256 en DB (`agent_enroll_ticket_hash` unique + `agent_enroll_ticket_expires_at`), TTL `agent.enroll_ticket_ttl_minutes` (défaut 240 min, plancher serveur 1 min iso plancher rotation 23.2). `openTicket()` révoque l'ancien token si poste enrôlé (AC2, `revokeFor($ws,'reinstall')`) ; re-fetch WinPE = écrasement sans erreur. `redeem()` résout par hash du ticket, **consommation atomique** (UPDATE conditionnel sur le hash — deux redeems concurrents : un seul gagne, le perdant retombe sur le chemin 409/403 sans oracle), puis `issueFor()`. Résultat typé `EnrollmentResult` (enrolled/conflict/notAllowed) — le controller ne fait que mapper HTTP.
- **Divergence assumée vs T4** : `ticket` est `nullable` dans le FormRequest (pas `required`) — l'AC4 et T8 exigent qu'une demande SANS ticket suive le chemin métier 409/403, pas un 422 de validation. Documenté dans le FormRequest.
- **Endpoint** : `POST /api/v1/agent/enrollment` (nom `agent.v1.enrollment`), middlewares `local.request` + `auth.v1.secure-headers` (no-store) + `throttle:10,1`. Canal legacy `agent.v1.enroll/refresh/ping` **intouché** (vérifié par `DualModeCoexistenceTest`, vert). Pas de `withoutMiddleware(['web'])` nécessaire (api.php = groupe `api` seul).
- **Unattend** : 2 `SynchronousCommand` ordres 1-2 avant le curl oobe (glissé 3-4). Cmd 1 = PowerShell `Invoke-RestMethod` (URL **absolue**), retry réseau 30×10 s mais **arrêt immédiat sur réponse HTTP d'erreur** (`$_.Exception.Response` → break : un 403 ne retarde pas l'install de 5 min), écrit `C:\ProgramData\SambaEdu\Agent\token` (`Set-Content -NoNewline`), échec non bloquant (`exit 0`). Cmd 2 = `icacls /inheritance:r` avec **SID** (`*S-1-5-18` SYSTEM, `*S-1-5-32-544` Administrators) — insensible à la locale Windows (un `"Administrators"` littéral échouerait sur un Windows FR).
- **Guard migration** : `IpxeWindowsUnattendController::openEnrollTicket()` garde `Schema::hasColumn('workstations','agent_enroll_ticket_hash')` — sans la migration 23.3, l'unattend est servi avec ticket vide (POST → 403 non bloquant) + log warning `ipxe.windows.unattend.enroll_ticket_skipped`, la chaîne d'install ne casse jamais.
- **Sysprep (AC6)** : purge `RD /S /Q "C:\ProgramData\SambaEdu\Agent"` insérée AVANT `sysprep.exe /generalize`, commentaire divergence dans le template. **Aucune fixture parité à mettre à jour** : `sysprep.txt` n'a jamais existé (legacy ne sert jamais ce body — test parité skipped depuis 3.8, cf. `_README.md` fixtures) ; couverture par test structurel neuf `it_purges_agent_token_directory_before_generalize` (ordre purge < generalize).
- **⚠️ Gap connu (hors tasks, à arbitrer en review)** : le flux `:nosysprep` (clonage sans sysprep) ne purge PAS le dossier agent — un clone capturé par ce chemin porterait le token du master (quarantaine au 1er check-in divergent, PAS de masse immédiate). Documenté `enrollment.md` §5, renvoi 25.3.
- **Tests** : `--filter Agent` sur /vm : **69 passed (266 assertions)** — 19 (23.1) + 42 (23.2) restés verts + 13 unit `EnrollmentServiceTest` + 8 feature `EnrollmentEndpointTest` (incl. cycle réinstall complet enrôlé→openTicket→ancien token 401→redeem→nouveau 200, et canaries ticket/token/hash jamais loggés) + tests unattend (ordre des FirstLogonCommands, interpolation/sanitization ticket, ticket vide sans migration) + 3 feature endpoint unattend (naissance/écrasement/révocation). `--filter Ipxe` : 878 passed / 2 skipped préexistants. Suite complète /vm : **4013 passed / 2 failed PRÉEXISTANTS hors scope** (`WpkgReportApiTest::post_from_non_local_ip` — cassé par 15.6, et `GpoIndexExportTest` advanced-filters-panel — iso constat 23.2).
- **Piège route cache (mémoire projet)** : `EnrollmentEndpointTest` repart d'une `RouteCollection` vierge et **re-déclare la route iso `routes/api.php`** (URI/middlewares/nom) + route écho éphémère `agent.token` — un cache `routes-v7.php` stale sur la VM ne contiendrait pas la route fraîche. Conséquence ops : **`php artisan route:cache` requis sur la VM** après cette story (fait).
- **Bonus mineur** : `.env.example` — section AGENT ajoutée avec `AGENT_ENROLL_TICKET_TTL_MINUTES` (T2) **et** `AGENT_TOKEN_ROTATION_DAYS` (manquait depuis 23.2, même section).
- **Bootstrapper test étendu** : `IpxeSchemaBootstrapper` provisionne désormais les 7 colonnes `agent_*` (idempotent, iso pattern `progress`) — la génération unattend ouvre un ticket dans tous les feature tests iPXE.
- **VM (faits)** : `php artisan migrate` (62 ms, colonnes ticket) + `config:cache` (clé = 240 vérifiée) + `route:cache` (`agent.v1.enrollment` vérifiée) + chown www-admin sur `config.php` et `routes-v7.php`. Smoke HTTP 403 OK, log channel agent OK.
- **AC7** : grep ldap/samba-tool/kerberos sur `app/Services/Agent/` + `app/Http/Controllers/Api/V1/Agent/` → vide. `docs/agent/contract-v1.md` intouché.

### File List

**Nouveaux :**
- database/migrations/2026_06_11_130000_add_agent_enroll_ticket_columns_to_workstations.php
- app/Services/Agent/Enrollment/EnrollmentService.php
- app/Services/Agent/Enrollment/EnrollmentResult.php
- app/Http/Controllers/Api/V1/Agent/EnrollController.php
- app/Http/Requests/Api/V1/Agent/EnrollmentRequest.php
- docs/agent/enrollment.md
- tests/Unit/Services/Agent/EnrollmentServiceTest.php
- tests/Feature/Api/V1/Agent/EnrollmentEndpointTest.php

**Modifiés :**
- config/agent.php (clé `enroll_ticket_ttl_minutes` — `token_rotation_days` intouché)
- .env.example (section AGENT)
- app/Providers/AgentServiceProvider.php (singleton `EnrollmentService`)
- routes/api.php (route `agent.v1.enrollment`, après le groupe 16.12)
- resources/ipxe/windows/unattend.xml (FirstLogonCommands 1-2 + réordonnancement 3-4)
- app/Ipxe/Services/WindowsUnattendBuilder.php (placeholder `###_AGENT_ENROLL_TICKET_###`)
- app/Ipxe/Http/Controllers/IpxeWindowsUnattendController.php (`openEnrollTicket()` + injection `EnrollmentService`)
- app/Models/Workstation.php (cast + @property colonnes ticket)
- resources/views/ipxe/windows/cmd/sysprep.blade.php (purge AC6, étendue au fallback :nosysprep en review)
- resources/views/ipxe/windows/cmd/nosysprep.blade.php (purge AC6 — ajouté en review : template principal du clonage sans sysprep)
- docs/agent/token-lifecycle.md (renvoi §9 → enrollment.md)
- tests/Support/IpxeSchemaBootstrapper.php (colonnes `agent_*`)
- tests/Unit/Ipxe/Services/WindowsUnattendBuilderTest.php (5 tests 23.3)
- tests/Unit/Ipxe/Services/WindowsActionCmdBuilderTest.php (test purge sysprep)
- tests/Feature/Ipxe/IpxeWindowsUnattendEndpointTest.php (3 tests AC1/AC2)

## Code Review (2026-06-11)

Review adversariale 3 couches (Blind Hunter / Edge Case Hunter / Acceptance Auditor). Verdict : AC1-AC7 conformes et testés ; 6 findings patch, 3 defer, 6 rejetés. **Arbitrages Henri** : points soumis tranchés (nullable validé, nosysprep corrigé ici), passe de correction approuvée et appliquée.

### Corrigé dans cette passe

- **#1 Purge `:nosysprep` (AC6)** — la review a révélé que le gap était DOUBLE : le fallback `:nosysprep` de `sysprep.blade.php` ET surtout `nosysprep.blade.php` (template principal du clonage sans sysprep, servi pour `etape=sysprep&type=clonage`). Purge ajoutée sur les deux chemins avant le reboot de capture + 2 tests structurels. Aucune fixture parité concernée (test parité nosysprep skipped depuis 3.8). `enrollment.md` §5 mis à jour (3 chemins purgés, limite connue supprimée).
- **#2 Retry discriminé (unattend ordre 1)** — l'ancien `catch` cassait la boucle sur TOUTE réponse HTTP (y compris 5xx/429 transitoires → postes silencieusement non-enrôlés en déploiement de masse). Désormais : seul un 4xx définitif (hors 429) arrête ; 5xx/429 retentés 30×10 s.
- **#3 Verrouillage AVANT écriture (unattend ordre 1)** — le dossier Agent est créé + verrouillé (`icacls /inheritance:r`, SID) AVANT l'échange ; si le verrouillage échoue (`$LASTEXITCODE`), abandon SANS consommer le ticket (→ porte 2). Le token n'existe plus jamais sous ACL héritées Users-readable. Écart à la lettre d'AC5 (ordre 1 = écriture, 2 = icacls) validé par Henri — l'ordre 2 devient ceinture-et-bretelles.
- **#4 Invariant hex strict du ticket (builder)** — `hexTicketOrEmpty()` : tout ticket non-hex (impossible via `openTicket`, défense en profondeur — le ticket atterrit entre quotes simples PowerShell et `sanitizeForTextContent` ne neutralise que les newlines) est vidé au lieu d'être interpolé. Test `it_drops_non_hex_enroll_ticket_instead_of_interpolating` (4 payloads forgés).
- **#5 Transaction `openTicket()`** — révocation + écriture ticket atomiques (`DB::transaction`) : un échec entre les deux ne laisse plus le poste sans token NI ticket.

### Rejeté (avec justification)

- **#6 « mismatch uuid quand fiche uuid NULL »** — décision Henri : une fiche sans uuid qui reçoit un uuid n'est pas une divergence, c'est une info nouvelle (fiche en retard sur le poste, cas attendu en install). Logger ça en `identity_mismatch` serait du bruit.
- **Oracle 409/403 (Blind Hunter)** — le « sans oracle » du contrat porte sur l'état des tickets (inconnu/expiré/consommé → 403 indistinct, respecté) ; le 409 sur poste enrôlé est une feature d'AC4, endpoint LAN-only. Propriété résiduelle assumée.
- **throttle:10,1 NAT** — non applicable (pas de NAT poste↔SE5 sur le LAN) ; le retry discriminé (#2) tolère désormais les 429 de toute façon.

### Defer (notés, non traités)

- TOCTOU étroit `redeem()` : `refresh()` post-claim pourrait recharger un poste re-openTicketé concurrent — fenêtre minuscule.
- `Schema::hasColumn` par requête unattend (introspection non cachée) — memoïzable, coût uniquement pendant les installs.
- `RD /S /Q` sans contrôle d'échec — style iso-legacy du template.

### Vérifications post-correctifs (VM)

`--filter Agent` : 71 passed (273 assertions). `--filter Ipxe` : 880 passed / 2 skipped préexistants (+2 tests structurels purge). XML unattend valide. Aucun changement config/routes → pas de re-cache nécessaire.

## Change Log

- 2026-06-11 — Story 23.3 développée (claude-fable-5, dev-story) : enrôlement porte 1 complet — ticket one-time à la génération unattend (révocation réinstall AC2), endpoint `POST /api/v1/agent/enrollment` (409/403 sans oracle), dépôt token ACL SYSTEM via FirstLogonCommands ordres 1-2, purge sysprep anti-clonage, doc contrat Epic 24. Statut → review.
- 2026-06-11 — Code review 3 couches + passe de correction (Opus 4.8, bmad-code-review) : purge clonage étendue aux 2 chemins nosysprep (le gap était double — template principal `nosysprep.blade.php` inclus), verrouillage ACL avant écriture du token, retry 5xx/429 discriminé, invariant hex ticket, transaction openTicket. 6 rejets justifiés, 3 defers notés. Tests VM verts (71 Agent / 880 Ipxe).
