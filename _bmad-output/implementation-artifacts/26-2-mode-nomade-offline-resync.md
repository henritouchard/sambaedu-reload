# Story 26.2 : Mode nomade — données accessibles offline et resynchronisées au retour

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'**utilisateur d'un portable nomade**,
je veux **accéder à mes fichiers hors établissement et les retrouver synchronisés au retour**,
afin que **mes données ne soient jamais prisonnières du poste** (ni perdues par une purge de profil).

## Acceptance Criteria

1. **Stratégie offline documentée et décidée.** Pour un poste résolu `nomade` (via `WorkstationEnvironmentResolver`, story 26.1), la **source de vérité reste le serveur** (home SambaEdu) avec **cache local + resynchronisation au retour**. La stratégie recommandée est **Folder Redirection + Offline Files (CSC)**, posée comme un **template GPO server-side + runbook manuel Administrator** (pattern figé en 25.4 : `resources/gpo/...` versionné + `docs/runbooks/...`, publication SYSVOL manuelle, AUCUNE automatisation applicative SYSVOL dans cette story). L'**alternative `rclone`/`robocopy`** est documentée comme fork de repli si CSC est disqualifié au smoke lab — avec critères de bascule explicites.

2. **Accès offline sans erreur (serveur injoignable).** La documentation/template garantit qu'un poste nomade hors établissement (SE_FS injoignable) accède à ses dossiers redirigés **en local** sans message d'erreur Windows. Le runbook décrit la vérification au smoke lab (déconnexion réseau → ouverture des dossiers OK).

3. **Resynchronisation au retour LAN.** De retour sur le LAN, les modifications locales **remontent au serveur** ; la **politique de conflit** suit le comportement documenté de la stratégie retenue (CSC : « conserver les deux versions » / arbitrage Sync Center ; rclone : règle documentée). Le runbook décrit la vérification (modif offline → reconnexion → présence côté serveur).

4. **`clean_profiles` désactivé pour les postes nomades — piloté par `WorkstationEnvironment`, PAS par un flag ad-hoc.** La purge de profil itinérant (`del-roam.sh` → `RoamingProfileService::generatePurgeScript()`, route `admin.gpo.del-roam-script`) ne doit **jamais** s'appliquer à un poste nomade (sinon perte des données en attente de sync). La décision d'exclusion **lit l'environnement via le resolver 26.1** (`resolveForGroupIds` / `resolve`). Le **fork d'implémentation** (où s'insère le gate : neutralisation du script servi pour un poste nomade vs garde dans la doc/template, selon ce que le faisceau d'identification permet sur cet endpoint) est documenté et tranché dans la story — voir Dev Notes §« Fork clean_profiles ».

5. **Aucun retrofit legacy.** `ApplicationScriptsGenerator`, `ShortcutCompilerService`, le pansement Bug C (`4e5a152`), ainsi que la mécanique GPO `redirections` legacy (clé `ExcludeProfileDirs`) ne sont **PAS** réarchitecturés. Le gate `clean_profiles` est une garde **additive** branchée sur le resolver 26.1, jamais une réécriture du canal de génération de scripts. Le service de résolution n'est **pas** câblé à un chemin legacy.

6. **Discipline NFR7 / NFR12.** Toute lecture de la nature du poste passe par `WorkstationEnvironmentResolver` (Postgres-only) — **jamais** d'AD/LdapRecord/APCu pour décider du mode nomade. Les identifiants d'environnement (`nomade`, …) restent les valeurs figées de l'enum (NFR12).

7. **Tests + QA.** Si du code Laravel est touché (gate `clean_profiles`, AC4) : tests ciblés verts (poste nomade → purge neutralisée ; poste `shared_local`/`personal_local` → purge inchangée, non-régression byte-fidèle du script existant) + non-régression `--filter Gpo`/`--filter Agent`. Section QA append-only ajoutée (`docs/qa/domains/gpo.md` pour la purge, et/ou `parc.md`). La partie **GPO offline** (template + runbook) est vérifiée par **smoke lab manuel** documenté (pas de test automatisé Windows).

**Note de transition (CRITIQUE) :** comme en 26.1, AUCUN retrofit dans le canal legacy. Le canal `redirections`/`ExcludeProfileDirs` legacy meurt avec son canal (Epic 27.4 reprend la redirection des profils navigateur selon `WorkstationEnvironment`). Ici on **garde** (gate `clean_profiles` sur le resolver) et on **documente** (template/runbook offline), on ne réécrit rien.

## Tasks / Subtasks

- [ ] **T1 — Décider et documenter la stratégie offline (Folder Redirection + Offline Files / CSC)** (AC: #1, #2, #3)
  - [ ] Rédiger le **runbook** `docs/runbooks/gpo-nomade-offline-files.md` (style `docs/runbooks/gpo-se4-agent-bootstrap.md`) : pré-requis serveur (home SambaEdu, partage Samba), Folder Redirection des dossiers user (Documents/Desktop/AppData ciblés — documenter le périmètre), activation CSC (`Offline Files`), politique de cache, **politique de conflit** au retour LAN, et la **section smoke lab** (déconnexion → accès local OK ; modif offline → reconnexion → resync serveur OK).
  - [ ] Documenter le **workaround Administrator / SYSVOL** (bloqueur de droits `www-sambaedu` READ-only, cf. 25.4 §3) et l'attribut CSE `gPCUserExtensionNames` (Folder Redirection est une **User CSE** → piège symétrique au §3bis de 25.4).
  - [ ] **Décision D1** : trancher template versionné `resources/gpo/se4_nomade_offline/` (skeleton GPT.INI + fdeploy.ini Folder Redirection) **OU** runbook seul (la Folder Redirection s'édite via GPMC, peu « templatable » en fichiers bruts). Justifier le choix dans la story (Completion Notes).
  - [ ] **Fork rclone/robocopy** : section dédiée du runbook décrivant l'alternative + **critères de disqualification CSC** (ex : CSC instable sur AppData volumineux, conflits ingérables, version Windows) qui déclencheraient la bascule. Ne PAS implémenter l'alternative ; la documenter comme plan B.

- [ ] **T2 — Gate `clean_profiles` pour les postes nomades (piloté par le resolver 26.1)** (AC: #4, #5, #6)
  - [ ] Analyser le **point d'insertion** : `RoamingProfileService::generatePurgeScript()` est servi par `RoamingProfileController::delRoamScript` (route `admin.gpo.del-roam-script`, middleware `AllowSe4FsScript`). Le script est rendu par **username** (`/home/profiles/${username}/...`), PAS par poste → résoudre le **fork** (cf. Dev Notes §« Fork clean_profiles » : quel signal permet de savoir que le poste appelant est nomade — IP/hostname dans la requête, ou liste d'exclusion de postes nomades, ou neutralisation conditionnelle).
  - [ ] Implémenter la garde **additive** : si le poste appelant (ou le contexte résolu) est `nomade` selon `WorkstationEnvironmentResolver`, **neutraliser** la purge (script vide commenté « purge désactivée — poste nomade (26.2) ») au lieu de générer les `rm -fr`. AUCUNE réécriture de la logique de génération existante (les branches `shared_local`/`personal_local` restent **byte-fidèles** au comportement actuel).
  - [ ] Injecter le resolver via DI (singleton déjà enregistré `AgentServiceProvider`). Lecture Postgres-only, JAMAIS d'AD. Ne PAS toucher `setExclusions`/`getExclusions` (canal GPO `redirections` legacy intouché).
  - [ ] Si le **fork** conclut que l'endpoint ne dispose pas d'un faisceau d'identification suffisant pour résoudre le poste de façon fiable (per-username only), **documenter la garde au niveau GPO/runbook** (la GPO `redirections` qui déclenche le logon script n'est PAS liée aux OU/postes nomades) plutôt que d'inventer un signal fragile — et marquer T2 comme « décision tranchée, implémentation = runbook ». **Trancher avec lucidité, ne pas sur-implémenter.**

- [ ] **T3 — Documentation sémantique (consommation de l'environnement par le mode nomade)** (AC: #1, #4, #6)
  - [ ] Append à `docs/agent/state-providers.md` (section « Environnement de poste » existante, 26.1) : sous-section « Mode nomade (26.2) » — stratégie offline retenue, lien vers le runbook, gate `clean_profiles`, note de transition (pas de retrofit legacy ; redirection profils navigateur → 27.4).
  - [ ] Lier depuis `docs/qa/README.md` si un nouveau runbook GPO est créé.

- [ ] **T4 — Tests (si code Laravel touché) + QA append-only** (AC: #7)
  - [ ] Si T2 implémente une garde côté `RoamingProfileService`/controller : test ciblé `tests/Feature/Gpo/...` ou `tests/Unit/Services/RoamingProfile...` : poste nomade → script neutralisé ; poste non-nomade → script **inchangé** (assertion byte-fidèle vs comportement actuel) ; défaut (poste non résolu) → comportement historique préservé. Mocker/seeder le resolver (Postgres-only, pas d'AD).
  - [ ] Non-régression : `php artisan test --filter Gpo` + `--filter Agent` verts (ne pas casser `ApplicationsScriptsByteParityTest`, `RoamingProfileService` existant).
  - [ ] QA append-only : `docs/qa/domains/gpo.md` Section dédiée « Mode nomade — purge profil désactivée + offline files (Story 26.2) » (scénarios : sonde du script servi pour un poste nomade vs partagé ; smoke lab CSC : déconnexion/resync). Numérotation stable (append).

## Dev Notes

### Architecture, patterns et contraintes

- **Code à la RACINE** : `app/`, `resources/`, `database/`, `docs/`, `tests/` — PAS sous `laravel/`.
- **Pattern GPO du projet (figé en 25.4)** : un artefact GPO = **template versionné** dans `resources/gpo/<nom>/` + **runbook manuel** dans `docs/runbooks/<nom>.md`. Publication SYSVOL **manuelle en Administrator** (le compte `www-sambaedu` n'a que READ → faux succès silencieux ; cf. `docs/runbooks/gpo-se4-agent-bootstrap.md` §3). **Aucune automatisation applicative** de la publication SYSVOL n'est attendue (ROI nul + bloqueur de droits). Folder Redirection est une **User CSE** → l'attribut LDAP critique est `gPCUserExtensionNames` (symétrique au `gPCMachineExtensionNames` du §3bis de 25.4 ; sans lui la GPO est inerte). CRLF obligatoire pour tout `.ini`/`.cmd` (mémoire `project_migration_passthrough_gpo_lab`).
- **`clean_profiles` = `del-roam.sh`** : la purge des profils itinérants est un **bash rendu server-side** par `RoamingProfileService::generatePurgeScript()` (`app/Services/RoamingProfileService.php:319`), servi en text/plain par `RoamingProfileController::delRoamScript` (`app/Http/Controllers/Admin/RoamingProfileController.php:22`), route `admin/gpo/del-roam.sh` (`routes/web.php:600`, name `admin.gpo.del-roam-script`), middleware `AllowSe4FsScript` (auth IP `se4fs_ip` OU `se4_key`). Le script produit des `rm -fr "/home/profiles/${username}/<exclusion>"` — **clé = username** (interpolation shell côté logon Windows), PAS le poste. Les exclusions viennent de la GPO `redirections` legacy (`ExcludeProfileDirs`, `setExclusions`/`getExclusions`). **CE canal legacy ne se réécrit pas.**
- **Resolver 26.1 (à consommer)** : `App\Services\Agent\WorkstationEnvironmentResolver` (`final readonly`, singleton dans `AgentServiceProvider::register()`). API : `resolve(Workstation $ws): WorkstationEnvironment` et `resolveForGroupIds(array $ids): WorkstationEnvironment`. Précédence `nomade > personal_local > shared_local`, défaut `shared_local`, **Postgres-only** (`WorkstationGroup::whereIn('id', …)->whereNotNull('environment')->pluck('environment')`), JAMAIS d'AD/APCu. C'est le **seul** point d'entrée autorisé pour la nature d'un poste (AC6).
- **Enum figé (NFR12)** : `App\Enums\WorkstationEnvironment::Nomade = 'nomade'`. Ne pas renommer, ne pas dupliquer la précédence (elle vit dans le resolver seul — décision D1 de 26.1).
- **Lecture Postgres exclusive** : iso `TargetContext` (« Hydratation exclusivement Postgres … jamais d'APCu ni de LdapRecord, NFR7 »). La décision « ce poste est-il nomade ? » suit la même règle absolue.

### Fork clean_profiles (DÉCISION À TRANCHER — la plus structurante de la story)

Le `del-roam.sh` est rendu **par username** et déclenché par un **logon script Windows** (canal GPO `redirections` legacy). Trois options, par ordre de propreté décroissante :

- **Option A — garde au niveau du déclenchement GPO (recommandée par défaut, zéro code Laravel).** Les postes nomades ne reçoivent simplement pas le logon script de purge : la GPO `redirections` (qui appelle `del-roam.sh`) **n'est pas liée** aux OU/postes nomades, ou un WMI/Item-Level Targeting exclut les postes nomades. Conforme « pas de retrofit » (on ne touche pas le code de génération) et « piloté par l'environnement » (l'admin marque le parc `nomade` en 26.1, puis applique l'exclusion GPO décrite au runbook). **Limite** : la garde n'est pas portée par le resolver au runtime mais par la topologie GPO — à assumer/documenter explicitement.
- **Option B — neutralisation conditionnelle côté endpoint (si un signal poste fiable existe).** Si la requête HTTP vers `del-roam.sh` peut être rattachée à un poste (IP source → `Workstation`, ou param hostname ajouté au logon script), résoudre `WorkstationEnvironmentResolver::resolve($ws)` et, si `nomade`, servir un script **neutralisé** (commentaire « purge désactivée poste nomade 26.2 »), sans toucher la branche non-nomade (byte-fidèle). **Risque** : le faisceau IP→poste sur cet endpoint peut être fragile (NAT, baux DHCP) ; ne PAS inventer un signal non fiable.
- **Option C — exclusion par liste de postes/parcs nomades injectée dans le script (rejetée a priori).** Mélange domaine + script, fragile. À écarter sauf contre-indication d'Henri.

**Recommandation SM** : trancher A vs B au lancement selon ce que le faisceau d'identification de l'endpoint permet réellement (le dev DOIT inspecter `AllowSe4FsScript` + le logon script appelant avant de coder B). Par défaut **A** (runbook), **B** seulement si un signal poste fiable est confirmé. **Ne pas sur-implémenter.** Question pour Henri ci-dessous.

### Scope tranché

| Couche | Touchée ? | Pourquoi |
|---|---|---|
| **Agent Go (`agent/`)** | **NON** | L'offline (CSC/Folder Redirection) est 100 % GPO/Windows natif (idée #14 « mécanismes enterprise natifs ») ; aucun handler Go. Golden files / frontière `agent_*` / `FROZEN_STATE_HASH` **intouchés**. |
| **Laravel — resolver 26.1** | Lecture seule | Consommé par la garde `clean_profiles` (si option B). Pas de modif du resolver. |
| **Laravel — `clean_profiles`** | Garde additive **OU** runbook (fork A/B) | Selon décision T2. Jamais de réécriture de `generatePurgeScript`. |
| **Template GPO + runbook** | **OUI** (cœur de la story) | Folder Redirection + Offline Files = template/runbook (pattern 25.4). |
| **Canal legacy (`ApplicationScriptsGenerator`, `ShortcutCompilerService`, GPO `redirections`/`ExcludeProfileDirs`)** | **NON** | Meurt avec son canal (Epic 27.4 pour la redirection des profils). Note de transition. |

### Project Structure Notes

- Runbook → `docs/runbooks/gpo-nomade-offline-files.md` (+ ligne dans `docs/qa/README.md` / `docs/agent/state-providers.md`).
- Template GPO (si D1 = template) → `resources/gpo/se4_nomade_offline/` (skeleton GPT.INI + Folder Redirection `fdeploy.ini` sous `User/`), cible serveur hors-git `/usr/share/sambaedu/gpo/` (convention `project_storage_convention_non_versioned`, cf. 25.4 §1).
- Garde `clean_profiles` (si option B) → `app/Services/RoamingProfileService.php` (injection resolver + branche nomade neutralisée) et/ou `app/Http/Controllers/Admin/RoamingProfileController.php`. Aucune autre modif legacy.
- Doc → `docs/agent/state-providers.md` (append à la section 26.1), `docs/qa/domains/gpo.md` (append).
- Tests → `tests/Feature/Gpo/` ou `tests/Unit/Services/` (si option B).

### Hors-scope (NE PAS faire)

- **Redirection des profils navigateur** (Chrome/Edge/OpenBoard) selon `WorkstationEnvironment` = **Story 27.4** (`app_config` handler). 26.2 ne fait QUE le mode nomade fichiers user + le gate `clean_profiles`.
- **Handler raccourcis / Bug C** = **Story 27.1**. Ne pas y toucher.
- **Réécriture du canal `redirections`/`ExcludeProfileDirs`** legacy, de `ApplicationScriptsGenerator`, `ShortcutCompilerService`, pansement `4e5a152`, tags `list_*` (ex-22.3 annulée).
- **Automatisation applicative de la publication SYSVOL** (ROI nul + bloqueur de droits — runbook manuel Administrator, 25.4).
- **Implémentation de l'alternative rclone/robocopy** (documentée comme plan B seulement).
- Toucher l'**agent Go**, les **golden files**, `FROZEN_STATE_HASH`, `contract-v1.md`, la frontière `agent_*`.

### Dépendances

| Story | Rôle pour 26.2 | Statut (sprint-status.yaml) | Bloquant ? |
|-------|----------------|------------------------------|------------|
| **26.1 — Enum WorkstationEnvironment + resolver + colonne** | Fournit `WorkstationEnvironment`, `WorkstationEnvironmentResolver`, la colonne `workstation_groups.environment` et l'UI parc-settings qui marque un parc `nomade`. **Socle direct de 26.2.** | **`review`** (développée par DEV opus, pas encore `done`) | **Non bloquant strict, mais prérequis.** Henri a autorisé le dev de 26.2 sur la 26.1 en review, **avec rebase si des correctifs de review de 26.1 modifient le resolver/enum** (vérifier la signature `resolve`/`resolveForGroupIds` au démarrage). |
| 25.4 — GPO-dispatcher figée + runbook | Fournit le **pattern GPO** (template versionné + runbook manuel Administrator, bloqueur SYSVOL, attribut CSE) que 26.2 réutilise pour l'offline. | `review` | Non (référence de style) |
| 1bis.18f — RoamingProfileService / del-roam.sh | Fournit le `clean_profiles` existant à **garder** (gate nomade), jamais à réécrire. | `done` | Non |
| 27.4 — app_config (profils navigateur) | **Consomme** plus tard `WorkstationEnvironment` pour la redirection des profils navigateur. 26.2 ne fait que le terrain fichiers user. | backlog | Non |

> **Note rebase 26.1** : la 26.1 est en `review`. Si la review modifie la signature ou le comportement du resolver (peu probable — surface minimale), rebaser 26.2 sur les correctifs. Vérifier en début de dev : `resolve(Workstation)` et `resolveForGroupIds(array): WorkstationEnvironment` existent toujours, précédence inchangée.

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 26.2] — AC d'origine (offline/resync, désactivation clean_profiles).
- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#FR29] — Folder Redirection + Offline Files recommandé, désactivation clean_profiles nomades.
- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 27.4] — redirection profils navigateur selon WorkstationEnvironment (hors-scope ici).
- [Source: _bmad-output/implementation-artifacts/26-1-enum-workstation-environment.md] — socle enum + resolver + colonne + discipline (D1 précédence hors enum, D2 null vs default, NFR7/NFR12, note transition legacy).
- [Source: app/Services/Agent/WorkstationEnvironmentResolver.php] — `resolve`/`resolveForGroupIds`, précédence, Postgres-only, singleton.
- [Source: app/Enums/WorkstationEnvironment.php] — `Nomade = 'nomade'`, NFR12.
- [Source: app/Services/RoamingProfileService.php#generatePurgeScript] — clean_profiles = del-roam.sh, rendu par username, exclusions `ExcludeProfileDirs`.
- [Source: app/Http/Controllers/Admin/RoamingProfileController.php] — endpoint del-roam.sh.
- [Source: routes/web.php:588-602] — route `admin.gpo.del-roam-script` + middleware AllowSe4FsScript.
- [Source: app/Http/Middleware/AllowSe4FsScript.php] — auth IP/clé des scripts d'exploitation (signal d'identification disponible côté endpoint — à inspecter pour le fork B).
- [Source: docs/runbooks/gpo-se4-agent-bootstrap.md] — pattern GPO (template versionné + runbook manuel Administrator, bloqueur SYSVOL §3, attribut CSE §3bis).
- [Source: resources/gpo/se4_agent_bootstrap/] — structure de template GPO de référence (GPT.INI + Machine/Scripts/...). Pour 26.2 : User CSE (Folder Redirection).
- [Source: docs/agent/state-providers.md#Environnement de poste (WorkstationEnvironment) — Story 26.1] — section à étendre.
- [Source: docs/qa/domains/parc.md#Section 3] — modèle de scénarios QA append-only.

## Questions pour Henri (à trancher avant/pendant le dev)

1. **Fork `clean_profiles` (le plus structurant)** : garde au niveau **topologie GPO** (option A, zéro code Laravel — la GPO `redirections` n'est pas liée aux postes nomades, runbook) OU **neutralisation conditionnelle de l'endpoint** `del-roam.sh` (option B, si un faisceau IP→poste fiable existe) ? Recommandation SM : A par défaut, B seulement si signal poste fiable confirmé.
2. **Stratégie offline** : confirme-t-on **Folder Redirection + Offline Files (CSC)** comme cible, ou anticipe-t-on déjà une bascule **rclone/robocopy** (contexte parc connu d'Henri : versions Windows, volumétrie AppData) ?
3. **Périmètre Folder Redirection** : quels dossiers user redirige-t-on (Documents/Desktop seulement, ou aussi AppData — risque CSC sur gros AppData) ?
4. **Template GPO vs runbook seul (D1)** : versionne-t-on un skeleton `resources/gpo/se4_nomade_offline/` (Folder Redirection peu « templatable » en fichiers bruts) ou se contente-t-on d'un runbook GPMC ?

## Dev Agent Record

### Agent Model Used

{{agent_model_name_version}}

### Debug Log References

### Completion Notes List

### File List

## Recommandation Modèle Dev

**Recommandation : `opus`.**

Justification : story **multi-domaine et à forte charge de décision architecturale**, pas un simple CRUD. Elle combine (a) du **GPO/Windows** non trivial (Folder Redirection + Offline Files/CSC, User CSE `gPCUserExtensionNames`, bloqueur SYSVOL, politique de conflit au retour), (b) du **Laravel** sur un canal **legacy sensible** (`clean_profiles`/`del-roam.sh`) où le piège majeur est exactement le **retrofit interdit** — un modèle moins rigoureux risque de réécrire `generatePurgeScript` ou de câbler le resolver au legacy au lieu d'une garde additive, et (c) un **fork d'implémentation structurant** (A topologie GPO vs B endpoint conditionnel) qui exige d'inspecter le faisceau d'identification réel de l'endpoint avant de coder, et de **trancher avec lucidité sans sur-implémenter**. La discipline NFR7 (Postgres-only via le resolver, jamais l'AD), la frontière `agent_*`/golden files à ne pas franchir, et la projection vers 27.4 justifient `opus`.
