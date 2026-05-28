# Story 17.1 : Audit des scripts packagés Windows & Linux

Status: done

> **Story de fondation Epic 17 (post-RESET 2026-05-14)** — pré-requis bloquant pour
> toutes les stories 17.2 → 17.4.
> Aucune capacité utilisateur livrée, aucun code applicatif, aucun modèle Eloquent,
> aucune migration, aucun endpoint, aucune UI. **Livrable unique** : un document
> markdown d'audit `_bmad-output/planning-artifacts/audit-applications-scripts.md`
> qui guidera le découpage technique des stories 17.2 → 17.4 et la stratégie de
> compatibilité runtime des scripts packagés avec Epic 15 (WPKG natif) + Epic 16
> (GPO natives).

---

## Story

As a **développeur SER**,
I want disposer d'un audit exhaustif des **~80 scripts Windows et Linux** livrés
par le package Debian `sambaedu` (chemins `usr/share/sambaedu/applications/<app>/<action>.{windows,linux}`,
`var/sambaedu/unattended/install/wpkg/wpkg.cmd` et `wpkg-client.vbs`, couche
surcharges `/etc/sambaedu/applications/`), de leurs placeholders `###_PARAMETRE_###`,
des endpoints HTTP qu'ils invoquent et de leur flux d'invocation côté poste,
So que les stories 17.2 (portage moteur `applications.php` natif), 17.3 (compat
GPO orchestratrice `se4_applications`) et 17.4 (tests d'intégration runtime VM)
puissent s'appuyer sur une cartographie claire et qu'aucune décision de portage
ne soit prise à l'aveugle face au risque de rupture parc-wide au croisement
Epic 15 + Epic 16.

---

## Contexte

### Reset Epic 17 (2026-05-14)

Le précédent cadrage Epic 17 (4 stories : fondations Eloquent + éditeur Monaco
+ association UI + logs d'erreurs) était basé sur une **mauvaise compréhension
du domaine** : les scripts n'étaient pas du contenu utilisateur éditable mais du
contenu **versionné par le package Debian `sambaedu`** (canal release upstream).
Le persona cible (tech école) n'édite pas le code des scripts — c'est l'équipe
sambaedu qui les fait évoluer via les releases du paquet.

Le scope d'Epic 17 est donc devenu purement de **compatibilité de migration runtime** :
garantir que les scripts livrés par le package continuent de fonctionner sur les
postes du parc après le remplacement du legacy par les fondations natives Epic 15
(WPKG) et Epic 16 (GPO). Pas de nouvelle fonctionnalité, pas d'UI, pas d'éditeur.

> **Source de vérité scripts** : `/home/htouchard/code/irundo/se4/sources/`
> (repo upstream du package Debian, séparé du repo de travail `irundo/codebase/`).
> L'audit peut explorer ce repo pour orienter la cartographie ; il n'est pas une
> dépendance applicative du repo `sambaedu-reload`.

### Frontière avec autres epics

| Epic | Périmètre | Frontière vs Epic 17 |
|------|-----------|----------------------|
| Epic 15 (WPKG natif) | `hosts.xml` / `profiles.xml` / `/apply-now` / ingestion rapports clients | Epic 17 vérifie que les scripts (notamment `wpkg.cmd` + `wpkg-client.vbs`) consomment correctement les nouveaux endpoints natifs. |
| Epic 16 (GPO natives) | Création / liaison / propagation de GPOs (registre Windows) | Epic 17 vérifie que la GPO orchestratrice `se4_applications` (qui appelle `curl applications.php`) reste fonctionnelle quand Epic 16 reprend la main sur les GPOs en natif. |
| Story 16.6 (DONE) | Hook GPO → `wpkg.js` côté client | **Hors scope Epic 17** : sa chaîne de déploiement est déjà traitée. L'audit doit explicitement le confirmer et documenter la jonction. |
| Story 16.7 (REVIEW) | Endpoint Laravel natif `gpo/applications.php` | **Référence forte** : c'est l'endpoint serveur qui sert les scripts assemblés. Story 17.2 va le compléter / le valider. L'audit doit croiser systématiquement. |

### Position dans l'Epic

Cette story 17.1 livre **uniquement** un document markdown d'audit. Pattern strict
décalqué de Story 16.1 (`audit-gpo-legacy.md` livré 2026-05-11, validé par Henri,
qui a guidé tout Epic 16). Le dev doit reproduire la forme de cet audit — il sert
de **template structurel**.

---

## Dépendances

| Story / Epic | Titre | Status | Détail |
|---|---|---|---|
| Epic 4 | Workstation, WorkstationGroup, AppProfile | done (2026-04-22) | Modèles Eloquent disponibles ; identité des postes côté natif. |
| 16.1 | Fondations GPO + audit legacy | review | **Référence de structure pour l'audit livrable.** Pattern à reproduire (sections A-G, fiches par fichier, conclusion de découpage). |
| 16.7 | Portage natif `applications.php` | review | Endpoint serveur Laravel qui sert les scripts assemblés — point central de l'audit (section C). |
| 16.6 | Hook GPO ↔ `wpkg.js` | DONE | Sa frontière vs Epic 17 doit être explicitement documentée par l'audit. |
| 15.2 | Generators `hosts.xml` / `profiles.xml` | done | Endpoints natifs WPKG consommés par `wpkg-client.vbs`. L'audit doit recenser ces consommations. |
| 15.5 | Pipeline / rapports / dashboard | review | Endpoint `/apply-now` éventuellement consommé. |

**Conclusion dépendances :** toutes satisfaites. L'audit peut être réalisé immédiatement.

---

## Acceptance Criteria

> AC organisées en **2 volets** : (1) périmètre & qualité du livrable audit,
> (2) sections obligatoires du document (parité avec `audit-gpo-legacy.md`).
> Comme pour 16.1, ce livrable est **bloquant validation Henri** avant marquage
> de la story en `review` et avant lancement de 17.2.

### Volet 1 — Périmètre & qualité du livrable

**AC1.1 — Livrable unique markdown**
**Given** la fin de l'audit
**When** la story est marquée prête pour review
**Then** **un seul fichier** est créé :
`_bmad-output/planning-artifacts/audit-applications-scripts.md`
**And** aucun fichier sous `app/`, `routes/`, `database/migrations/`, `resources/`,
`config/`, `tests/` n'est créé ou modifié (pas de code applicatif — strict).

**AC1.2 — Exhaustivité de cartographie**
**Given** les chemins du package Debian `sambaedu`
**When** la section 6.A « Cartographie fichier-par-fichier » est rédigée
**Then** elle couvre **a minima** :
- **100% des scripts** sous `usr/share/sambaedu/applications/<app>/<action>.{windows,linux}` (≈80 scripts attendus, ~28 apps × 1-6 actions)
- **100% des `scripts.json`** présents dans les apps (≈5 attendus selon inventaire `firefox/`, `glpi/`, etc.)
- Les **2 scripts critiques WPKG** : `var/sambaedu/unattended/install/wpkg/wpkg.cmd` + `var/sambaedu/unattended/install/wpkg/wpkg-client.vbs`
- Les ~**25 scripts d'installation OS** sous `var/sambaedu/unattended/install/os/SambaEdu/*.{ps1,cmd}` — **avec une décision in/out scope justifiée** (cf. AC2.A point 4)

**And** chaque fiche présente **au minimum 9 champs** (type, OS, app rattachée, interpréteur, déclencheur GPO, placeholders consommés, endpoints HTTP invoqués, statut shim/natif, recommandation porte 17.2/17.3/17.4).

**AC1.3 — Source de vérité documentée**
**Given** le repo upstream du package `/home/htouchard/code/irundo/se4/sources/`
**When** l'audit cite un script
**Then** chaque référence inclut le **chemin absolu** vers `se4/sources/...`
**And** la date / commit upstream de la version analysée est consigné en en-tête de l'audit
(ou « inventaire de référence : <date> » si le repo n'a pas de commit ID directement exploitable).

**AC1.4 — Validation bloquante Henri**
**Given** le livrable rédigé
**When** le dev a terminé l'audit
**Then** la story **ne peut pas** être marquée `review` automatiquement
**And** elle attend la validation explicite de Henri (parité 16.1, cf. tâche T6.8 16.1).
**And** une note est ajoutée dans « Completion Notes » indiquant clairement « audit livré, en attente de validation Henri ».

**AC1.5 — Aucune écriture de code applicatif**
**Given** le scope strict d'audit
**When** la story est en cours
**Then** **aucun** fichier PHP, Blade, JS, migration, test, route, config Laravel n'est créé ou modifié
**And** **aucune** capacité utilisateur n'est livrée
**And** seuls peuvent être modifiés : le fichier audit lui-même + `sprint-status.yaml` (status update).

### Volet 2 — Sections obligatoires du document `audit-applications-scripts.md`

> Reproduire **fidèlement** la forme de `audit-gpo-legacy.md` (Story 16.1).
> Sections A à G obligatoires + en-tête + synthèse exécutive.

#### AC2.A — Section A : Cartographie fichier-par-fichier

Pour **chaque script** du périmètre (cf. AC1.2), une fiche avec **a minima** :

1. **Fichier source** (chemin absolu `se4/sources/...` + nombre de lignes)
2. **Type d'action** parmi `logon` / `startup` / `shutdown` / `logoff` / `logon-system` / `install` / `install-os` / autre (à préciser)
3. **OS cible** : `windows` / `linux` / `windows+linux`
4. **App rattachée** (firefox, thunderbird, wpkg, veyon, wallpaper, shortcuts, etc.) — ou « install OS » pour les scripts `install/os/SambaEdu/*`
5. **Interpréteur** : `cmd.exe` / `powershell.exe` / `cscript.exe` (VBS) / `bash` / `sh` / autre
6. **Déclencheur côté poste** : GPO orchestratrice (`se4_applications` ?) / iPXE install / WPKG / autre
7. **Placeholders `###_PARAMETRE_###` consommés** dans le contenu (liste extraite par grep — voir AC2.B pour le catalogue agrégé)
8. **Endpoints HTTP appelés** (curl, wget, Invoke-WebRequest, etc.) — voir AC2.C
9. **Statut runtime côté Epic 15/16** :
   - `OK iso-legacy` (le script fonctionne tel quel sans rien faire)
   - `À adapter 17.2` (le script appelle `applications.php` — dépend du portage natif)
   - `À adapter 17.3` (le script est invoqué par la GPO orchestratrice — dépend de la compat GPO native)
   - `Déjà couvert 16.6` (cas `wpkg.cmd` / `wpkg-client.vbs` / `wpkg.js`)
   - `Hors scope Epic 17` (justification)
10. **Surcharges admin locales connues** (couche `/etc/sambaedu/applications/<app>/`) : « observée » / « non observée » / « non rencontrée à l'audit »
11. **Risques / pièges** identifiés (encodage CP1252/UTF-8, chemins UNC, droits sudo, dépendance APCu serveur, etc.)

**Spécifiquement pour les ~25 scripts `install/os/SambaEdu/*.{ps1,cmd}`** :
- Une **décision in-scope / out-of-scope Epic 17 justifiée** doit être prise.
- Hypothèse de cadrage SM par défaut : **out-of-scope** (exécutés par iPXE pendant l'install poste, pas au boot/logon runtime). Mais l'audit doit le confirmer ou le démentir en lisant 3-5 scripts représentatifs (`install.ps1`, `sysprep.ps1`, `winget-install.ps1`, `SetWallpaper.ps1`, `wpkg.cmd`).

#### AC2.B — Section B : Catalogue des placeholders `###_PARAMETRE_###`

Tableau exhaustif des placeholders consommés par les scripts, avec pour chacun :
- **Nom du placeholder** (ex. `###_SE4FS_NAME_###`, `###_DOMAIN_###`, `###_NETLOGON_PATH_###`)
- **Liste des scripts qui le consomment** (références aux fiches 6.A)
- **Source dans la config legacy** : nom de la clé `config[xxx]` (legacy `sambaedu/config/`) ou autre source
- **Source équivalente côté natif** : clé `config('sambaedu.xxx')` ou via Service Laravel (croisement avec Story 16.7 si déjà identifié)
- **Risque d'oubli au portage natif 17.2** : oui/non + justification

> Référence forte : whitelist `config/sambaedu.gpo.applications.substitutions.php`
> introduite par Story 16.7 (8 clés : SE4FS_NAME / DOMAIN / UAI / NETLOGON_PATH /
> WPKG_URL / SAMBA_DOMAIN / TMP_DIR / CLOUD_PERSO_NAME). L'audit doit confirmer
> ou compléter cette whitelist face à la réalité des scripts.

#### AC2.C — Section C : Catalogue des endpoints HTTP serveur invoqués

Tableau des endpoints HTTP appelés depuis les scripts (curl/wget/Invoke-WebRequest/cscript), avec :
- **URL appelée** (relative ou absolue, depuis le script)
- **Méthode HTTP** (GET/POST)
- **Paramètres** envoyés (query string ou body)
- **Côté legacy** : route servie par `sambaedu/gpo/applications.php` / `sambaedu/wpkg/*` / autre
- **Côté natif** : route servie par
  - Epic 15 : `hosts.xml` / `profiles.xml` / `/apply-now` (Story 15.2/15.5)
  - Epic 16.7 : endpoint Laravel `gpo/applications.php` (route `gpo.applications.legacy` throttle 300/min)
  - autre
- **Statut compat runtime** : OK / À ajuster / Non couvert
- **Script(s) appelant(s)** : ref vers fiches 6.A

#### AC2.D — Section D : Flux d'invocation côté poste

Description séquentielle du flux de boot/logon/shutdown/logoff côté poste Windows, depuis le moment où une GPO orchestratrice (`se4_applications`) déclenche un script jusqu'à l'exécution complète :

1. GPO `se4_applications` déposée par le legacy / Epic 16 → quel `.cmd` orchestrateur dépose-t-elle dans `%windir%` (`applications-startup.cmd` / `applications-logon.cmd` / etc.) ?
2. Le `.cmd` orchestrateur appelle `curl` (ou équivalent) sur `gpo/applications.php` avec quels paramètres (`os`, `action`, `user`, `machine`, `id`) ?
3. Le serveur (legacy ou Epic 16.7 natif) répond avec quel `.cmd`/`.sh` assemblé (concaténation de fragments retenus selon includes/excludes) ?
4. Le poste exécute le `.cmd`/`.sh` reçu ; chaque fragment correspond à un fichier `usr/share/sambaedu/applications/<app>/<action>.<os>` du package.
5. Cas spécial WPKG : `wpkg.cmd` au startup → `cscript wpkg-client.vbs` → invocations Epic 15 endpoints.

Inclure un **diagramme ASCII / mermaid** de ce flux pour faciliter la transmission au dev de 17.2/17.3.

#### AC2.E — Section E : Couche surcharges admin locales `/etc/sambaedu/applications/`

- Existence et fréquence d'usage **observée chez les clients** (à demander à Henri si pas d'info terrain — note explicite dans l'audit).
- Mécanisme legacy de priorité `/etc/sambaedu/applications/<app>/` > `/usr/share/sambaedu/applications/<app>/`.
- Confirmation que **Story 16.7 préserve déjà ce mécanisme** (croisement avec `ApplicationTemplatesScanner` qui scanne les 2 chemins).
- Recommandation pour 17.2 : conserver cette priorité dans le moteur natif.

#### AC2.F — Section F : Risques de rupture au croisement Epic 15 + Epic 16

Liste des risques de rupture **au niveau parc-wide** que l'audit identifie :

- Risque par script
- Cause racine (endpoint disparu / placeholder non substitué / GPO orchestratrice non préservée / encodage CP1252 cassé / etc.)
- Épopée concernée (Epic 15 / Epic 16 / 16.6 / 16.7 / autre)
- Story de mitigation recommandée (17.2 / 17.3 / 17.4)
- Sévérité (bloquante / dégradée / mineure)

#### AC2.G — Section G : Recommandations de découpage pour 17.2, 17.3, 17.4

Synthèse finale type « la story 17.2 doit couvrir tels endpoints », « la story 17.3 doit garantir tel comportement de la GPO orchestratrice », « la story 17.4 doit tester tels scénarios sur la VM ».

**Doit inclure explicitement** :
- Confirmation ou redéfinition du périmètre de chacune des 3 stories aval
- Liste minimale des **5 scripts critiques** à couvrir par les tests d'intégration 17.4 (`wpkg.cmd` startup, `wallpaper/logon.windows`, `shortcuts/logon.windows`, `firefox/logon.windows`, un cas Linux équivalent — cf. cadrage epics.md 17.4)
- Si des découvertes nécessitent un découpage différent (ex. split de 17.2 en 17.2a/b, ajout d'une story 17.5), le proposer explicitement avec justification.

#### AC2.H — Vérifications transverses obligatoires (à intégrer dans l'audit)

L'audit doit **explicitement répondre** aux 3 questions suivantes (issues du cadrage SM 2026-05-14) :

1. **Frontière `wpkg.js` / Story 16.6** : confirmer que `wpkg.js` (côté client Windows, invoqué par `cscript wpkg-client.vbs` ou directement) est **hors scope Epic 17**. Sa jonction avec les scripts du package doit être documentée mais pas portée dans l'Epic 17. Décision attendue : oui/non + ref dossier où vit `wpkg.js`.
2. **Statut couche surcharges `/etc/sambaedu/applications/`** : fréquence d'usage chez les clients (Section E). Si l'audit ne peut pas trancher en autonomie, formuler la question pour Henri.
3. **Statut `install/os/SambaEdu/*.{ps1,cmd}`** : in-scope ou out-of-scope du **runtime** Epic 17 ? (Section A point 4.) Décision attendue justifiée.

---

## Tasks / Subtasks

> Story d'audit : essentiellement lecture/synthèse. Pas de code applicatif.
> Estimation 1-2 jours de travail dev.

### Phase T0 — Cadrage & lectures préalables

- [x] **T0.1** Lire `_bmad-output/implementation-artifacts/16-1-fondations-gpo-natives-audit-legacy.md` en entier (référence de structure pour le livrable audit). (AC: 1.1, 2.A→G)
- [x] **T0.2** Lire `_bmad-output/planning-artifacts/audit-gpo-legacy.md` en entier — **template structurel obligatoire** à reproduire pour l'audit Story 17.1. (AC: 2.A→G)
- [x] **T0.3** Lire la fiche cadrage Epic 17 + Story 17.1 dans `_bmad-output/planning-artifacts/epics.md` lignes 3350-3387.
- [x] **T0.4** Lire `_bmad-output/implementation-artifacts/16-7-portage-natif-applications-php.md` (endpoint serveur Laravel qui sert les scripts assemblés — point central de croisement).
- [x] **T0.5** Lire `_bmad-output/implementation-artifacts/16-6-hook-gpo-invocation-wpkgjs-cote-client.md` pour confirmer la frontière hors-scope.

### Phase T1 — Exploration repo upstream `se4/sources/`

- [x] **T1.1** Lister tous les fichiers `usr/share/sambaedu/applications/<app>/<action>.{windows,linux}` (≈80 fichiers attendus) — utiliser `find /home/htouchard/code/irundo/se4/sources/usr/share/sambaedu/applications/ -maxdepth 2 -name "*.windows" -o -name "*.linux"`. (AC: 1.2)
- [x] **T1.2** Lister tous les `scripts.json` présents dans les apps + en lire 2-3 représentatifs (firefox, thunderbird, glpi) pour comprendre le format déclaratif (`file`, `os`, `action`, `interpreter`, `context`, `remote`, `includes`, `excludes`, `includes_apps`, `excludes_apps`). (AC: 1.2)
- [x] **T1.3** Lire intégralement les 2 scripts critiques WPKG : `var/sambaedu/unattended/install/wpkg/wpkg.cmd` + `var/sambaedu/unattended/install/wpkg/wpkg-client.vbs`. (AC: 1.2)
- [x] **T1.4** Échantillonner 3-5 scripts représentatifs sous `var/sambaedu/unattended/install/os/SambaEdu/*` (`install.ps1`, `sysprep.ps1`, `winget-install.ps1`, `SetWallpaper.ps1`, `wpkg.cmd`) pour trancher in/out scope runtime Epic 17. (AC: 2.A point 4, AC: 2.H point 3)
- [x] **T1.5** Lire les scripts les plus critiques runtime parc-wide (sur l'inventaire mentionné par le cadrage 17.4) : `wallpaper/logon.windows`, `shortcuts/logon.windows`, `firefox/logon.windows`, `firefox/logon.linux`. Identifier exhaustivement leurs placeholders + endpoints HTTP. (AC: 2.B, 2.C)

### Phase T2 — Croisement avec le legacy `applications.php` et Story 16.7 native

- [x] **T2.1** Lire ou retrouver `sambaedu/gpo/applications.php` legacy (peut être consulté via `legacy/modules/gpo/applications.php` du shim 1bis-18e ou via `se4/sources/...`) — comprendre le contrat HTTP (paramètres `os`, `action`, `user`, `machine`, `id`) et la logique de filtrage `includes/excludes`. (AC: 2.D)
- [x] **T2.2** Croiser avec la liste des `action_type` et placeholders gérés par Story 16.7 (`ApplicationScriptsGenerator`, `ApplicationScriptsAssembler`, `config/sambaedu.gpo.applications.substitutions.php` whitelist 8 clés). Identifier les éventuels écarts entre la réalité des scripts et le portage natif déjà fait. (AC: 2.B, 2.F)
- [x] **T2.3** Identifier les endpoints HTTP côté serveur consommés par les scripts : `applications.php`, `hosts.xml`, `profiles.xml`, `apply-now`, `network_out.php`, `veyon_out.php`, `firefox_out.php`, `thunderbird_out.php`, autres. (AC: 2.C)

### Phase T3 — Cartographie GPO orchestratrice `se4_applications`

- [x] **T3.1** Identifier comment la GPO `se4_applications` (legacy) dépose les `.cmd` orchestrateurs (`applications-startup.cmd` / `applications-logon.cmd` / etc.) dans `%windir%` côté poste. Lire le `.cmd` qui fait `curl` vers `applications.php`. (AC: 2.D)
- [x] **T3.2** Croiser avec l'état actuel d'Epic 16 (`audit-gpo-legacy.md` + stories 16.x déjà DONE/review) pour identifier quelle story d'Epic 16 va reprendre la main sur la création/modification de la GPO `se4_applications` en natif. (AC: 2.F, 2.G)
- [x] **T3.3** Vérifier le comportement attendu de la GPO native par 17.3 : « préservation à l'identique » vs « recréation native avec contenu effectif identique côté SYSVOL ». Proposer une recommandation. (AC: 2.G)

### Phase T4 — Rédaction de l'audit `audit-applications-scripts.md`

- [x] **T4.1** Créer `_bmad-output/planning-artifacts/audit-applications-scripts.md` avec en-tête (date, auteur, statut draft, version analysée du repo upstream). (AC: 1.1, 1.3)
- [x] **T4.2** Rédiger la **synthèse exécutive** (5-10 bullets) en tête : volumétrie réelle, recommandation de découpage, risques majeurs. (AC: 2.G)
- [x] **T4.3** Rédiger **Section A — Cartographie fichier-par-fichier** : 11 champs × ≈80 scripts. Pour les scripts manifestement identiques (boilerplate), grouper proprement avec une note synthétique mais lister les chemins. (AC: 2.A)
- [x] **T4.4** Rédiger **Section B — Catalogue placeholders** : tableau exhaustif extrait par grep `'###_'` sur tous les scripts. Croiser avec whitelist 16.7. (AC: 2.B)
- [x] **T4.5** Rédiger **Section C — Catalogue endpoints HTTP** : tableau exhaustif des appels HTTP sortants des scripts. (AC: 2.C)
- [x] **T4.6** Rédiger **Section D — Flux d'invocation côté poste** avec diagramme ASCII/mermaid (séquence GPO → orchestrateur `.cmd` → curl → serveur → réponse `.cmd` assemblée → exécution fragments). (AC: 2.D)
- [x] **T4.7** Rédiger **Section E — Surcharges admin `/etc/sambaedu/applications/`** : confirmer mécanisme priorité, croiser avec 16.7, formuler question Henri si fréquence d'usage inconnue. (AC: 2.E, 2.H point 2)
- [x] **T4.8** Rédiger **Section F — Risques de rupture** au croisement Epic 15 + Epic 16 : liste structurée avec sévérité. (AC: 2.F)
- [x] **T4.9** Rédiger **Section G — Recommandations de découpage** pour 17.2, 17.3, 17.4. Confirmer ou redéfinir le périmètre des 3 stories aval. Lister les 5 scripts critiques 17.4. (AC: 2.G)
- [x] **T4.10** Intégrer la **réponse explicite aux 3 vérifications transverses** : frontière `wpkg.js`/16.6, fréquence surcharges, in/out scope install/os/SambaEdu. (AC: 2.H)

### Phase T5 — Validation & finalisation

- [x] **T5.1** Relire l'audit en mode auto-critique : compter le nombre de fiches en Section A (doit ≥ 80) et le nombre de placeholders en Section B. Vérifier que chaque vérification AC2.H a une réponse explicite. (AC: 1.2, 2.H)
- [x] **T5.2** Ajouter une **liste de questions ouvertes pour Henri** en fin d'audit (parité 16.1 « Discrepances ouvertes »).
- [x] **T5.3** **Soumettre l'audit à Henri pour validation avant de marquer la story `review`**. C'est le deliverable structurant. (AC: 1.4)
- [x] **T5.4** Mettre à jour `sprint-status.yaml` : `17-1-audit-scripts-windows-linux: ready-for-dev → review` (réalisé par dev après validation Henri).

---

## File List prévisionnelle

### Fichiers créés

```
_bmad-output/planning-artifacts/audit-applications-scripts.md   ← LIVRABLE UNIQUE
```

### Fichiers modifiés

```
_bmad-output/implementation-artifacts/sprint-status.yaml   (status update : ready-for-dev → review en fin de dev)
```

> **Note critique :** **aucun** fichier sous `app/`, `routes/`, `database/`, `resources/`, `config/`, `tests/`. Story d'audit pure. Toute modification de code applicatif est hors scope et doit être déférée à 17.2/17.3/17.4.

---

## Test Strategy

Story d'audit — pas de tests automatisés. La « validation » est :

| Niveau | Périmètre | Méthode |
|---|---|---|
| **Auto-relecture dev** | Couverture des AC1.1-AC2.H | Checklist par le dev avant soumission à Henri |
| **Validation Henri (bloquante)** | Pertinence métier, cohérence Epic 15/16/17, complétude vs réalité du parc | Lecture par Henri, retours intégrés au document |
| **Tests d'usage aval** | L'audit guide effectivement 17.2/17.3/17.4 | Vérifié rétrospectivement quand 17.2 démarrera |

---

## Dev Notes — Contraintes & décisions cadrage SM

### Décisions de cadrage prises par le SM (2026-05-14)

| # | Décision | Justification |
|---|---|---|
| D1 | **Pattern strict décalqué de 16.1** (audit livré dans `_bmad-output/planning-artifacts/audit-*.md`, sections A-G, validation Henri bloquante) | Parité de structure attendue par Henri. 16.1 a fonctionné, on reproduit. |
| D2 | **Aucun code applicatif** (pas de modèle Eloquent, pas de migration, pas d'endpoint, pas d'UI, pas de test) | Cadrage Epic 17 post-RESET 2026-05-14 explicite : 17.1 = audit pur. Tout le reste vit dans 17.2/17.3/17.4. |
| D3 | **Source de vérité = repo upstream `/home/htouchard/code/irundo/se4/sources/`** | Confirmé par Henri. Le repo de travail `sambaedu-reload` ne contient pas les scripts. |
| D4 | **5 scripts critiques 17.4 figés en avance** dans le cadrage epics.md (wpkg.cmd, wallpaper logon, shortcuts logon, firefox logon win, firefox logon linux) | Permet au dev audit de prioriser leur lecture exhaustive (placeholders + endpoints). |
| D5 | **Frontière `wpkg.js` (16.6 DONE) hors scope Epic 17** par défaut, à confirmer par l'audit | Cadrage epics.md explicite. L'audit doit explicitement le valider. |
| D6 | **Validation Henri bloquante** avant marquage `review` (parité 16.1 T6.8) | Le livrable structure les 3 stories aval. Une erreur ici se propage. |
| D7 | **Décision in/out scope `install/os/SambaEdu/*` déléguée à l'audit** (hypothèse SM par défaut : out-of-scope runtime) | Pas assez d'info au cadrage SM. Le dev échantillonne 3-5 scripts puis tranche avec justification. |
| D8 | **Pas de subagent / pas de slash command** — l'audit est rédigé directement par le dev sur l'environnement principal | Cohérence avec consigne user. |

### Discrepances connues à trancher pendant l'audit

| Item | Note SM |
|---|---|
| Fréquence d'usage couche surcharges `/etc/sambaedu/applications/` chez les clients | L'audit doit l'estimer ; si pas d'info, formuler la question pour Henri dans la section « questions ouvertes ». |
| GPO native qui reprendra la main sur `se4_applications` (Epic 16) | À identifier en croisant avec stories 16.x déjà cadrées. Cible probable : 16.5 (liaison) ou nouvelle story 16.9 si rien ne matche. À proposer dans Section G. |
| Périmètre des `*.linux` (LTSP, postes Linux nomades, etc.) | Le cadrage parle surtout du parc Windows mais les scripts `.linux` existent. Identifier leur usage réel (≥ 1 cas testé en 17.4 selon epics.md). |

### Pièges identifiés (anticipation)

1. **Encodage scripts** : CP1252 / Windows-1252 sur les `.cmd`, UTF-8 sur les `.sh`. Story 16.7 le gère côté Controller (`charset CP1252 windows / UTF-8 linux`). L'audit doit le mentionner en Section F.
2. **Placeholders avec ou sans valeur définie** : un script peut référencer un `###_PLACEHOLDER_###` qui n'est plus dans la whitelist 16.7. L'audit doit le détecter en Section B.
3. **`scripts.json` peut redéfinir l'interpréteur ou le contexte** : ne pas se fier uniquement à l'extension de fichier — lire `scripts.json` quand il existe pour confirmer les champs `interpreter`, `context`, `remote`.
4. **Endpoints HTTP en dur dans les scripts** vs **substitués via `###_SE4FS_NAME_###`** : Story 16.6 a documenté que la URL serveur est implicite via `SE4FS_NAME`. L'audit doit confirmer si tous les scripts respectent cette convention ou si certains ont une URL en dur (risque rupture multi-établissement).
5. **Différence package version** : la version du repo upstream peut différer légèrement de ce qui tourne en VM dev. Mentionner explicitement la version analysée (commit / date) en en-tête de l'audit.

### Références codebase pour le dev

- **Référence de structure obligatoire** : `_bmad-output/planning-artifacts/audit-gpo-legacy.md` (livrable Story 16.1).
- **Repo upstream scripts** : `/home/htouchard/code/irundo/se4/sources/` (en particulier `usr/share/sambaedu/applications/` et `var/sambaedu/unattended/install/`).
- **Endpoint Laravel natif consommateur des scripts** : `app/Http/Controllers/Gpo/ApplicationsScriptsController.php` + `app/Gpo/Services/ApplicationScriptsGenerator.php` + `app/Gpo/Services/ApplicationScriptsAssembler.php` + `app/Gpo/Services/ApplicationTemplatesScanner.php` (Story 16.7).
- **Whitelist placeholders 16.7** : `config/sambaedu.gpo.applications.substitutions.php` (8 clés).
- **Shim legacy** : `legacy/modules/gpo/applications.php` (shim 1bis-18e), pour relire le contrat HTTP legacy si besoin.
- **Story dépendance forte aval** : `_bmad-output/implementation-artifacts/16-7-portage-natif-applications-php.md` — toute incohérence détectée par l'audit doit y être référencée.

---

## Project Structure Notes

### Alignement structure projet

- **Aucun namespace impacté** — pas de code applicatif.
- **Aucune route impactée**.
- **Aucune table impactée**.
- **Convention BMAD** : livrable d'audit dans `_bmad-output/planning-artifacts/audit-*.md` (parité 16.1).

### Conflits / variances détectés

Aucun. Story purement documentaire.

---

## References

- `_bmad-output/planning-artifacts/epics.md` lignes 3350-3387 — Epic 17 cadrage post-RESET 2026-05-14
- `_bmad-output/planning-artifacts/audit-gpo-legacy.md` — **template structurel obligatoire**
- `_bmad-output/implementation-artifacts/16-1-fondations-gpo-natives-audit-legacy.md` — référence de pattern (story d'audit)
- `_bmad-output/implementation-artifacts/16-7-portage-natif-applications-php.md` — endpoint Laravel consommateur des scripts (croisement obligatoire)
- `_bmad-output/implementation-artifacts/16-6-hook-gpo-invocation-wpkgjs-cote-client.md` — frontière `wpkg.js` hors scope à confirmer
- `_bmad-output/implementation-artifacts/15-1-fondations-pipeline-deploiement-wpkg.md` — pattern foundation Epic 15
- `/home/htouchard/code/irundo/se4/sources/` — repo upstream du package Debian sambaedu (source de vérité scripts)
- `/home/htouchard/code/irundo/se4/sources/usr/share/sambaedu/applications/` — ≈28 dossiers app × scripts `.windows`/`.linux`
- `/home/htouchard/code/irundo/se4/sources/var/sambaedu/unattended/install/wpkg/` — `wpkg.cmd` + `wpkg-client.vbs` critiques
- `/home/htouchard/code/irundo/se4/sources/var/sambaedu/unattended/install/os/SambaEdu/` — ~25 scripts d'install OS (in/out scope à trancher)
- `config/sambaedu.gpo.applications.substitutions.php` (sur la VM, livré par 16.7) — whitelist 8 placeholders
- `app/Gpo/Services/ApplicationTemplatesScanner.php` (sur la VM, livré par 16.7) — scanner FS prioritisant `/etc/sambaedu/applications/` puis `/usr/share/sambaedu/applications/`

---

## Dev Agent Record

### Agent Model Used

- **Dev initial de l'audit** (T0-T4) : claude-opus-4-7, 2026-05-14 (rédaction `audit-applications-scripts.md` 1614 lignes — 7 sections A-G + section bonus I logs + annexes A-C + 8 questions ouvertes Q1-Q8).
- **Patch post-Epic-16-done + clôture BMAD** (T5 + validation Henri + tracking) : claude-opus-4-7[1m] orchestrateur dev-cycle, 2026-05-21.

### Debug Log References

Aucun (story d'audit pur — pas de tests automatisés, pas de logs applicatifs).

### Completion Notes List

1. **Livrable principal** : `_bmad-output/planning-artifacts/audit-applications-scripts.md` créé 2026-05-14, patché 2026-05-21.
2. **Volumétrie effective** : 54 fragments `.windows`/`.linux` cartographiés (et non ~80 estimés initialement) + 11 `scripts.json` + 5 `redirects.json` + 2 scripts WPKG critiques + 29 scripts `install/os/SambaEdu/*` échantillonnés.
3. **Validation Henri 2026-05-21** : audit + Section G + Q1-Q8 résolus dans la conversation de clôture. Tracé dans la section « Réponses Henri » de l'audit.
4. **Recadrage post-Epic-16-done** :
   - 16.4 cancelled 2026-05-18 → Stratégie B GPO native abandonnée, Stratégie A confirmée par Henri (modèle « download GPO repo + set direct à l'install »).
   - 16.12 done → infra logs livrée, 17.5 réduit à intégration `WrapperScriptRenderer` (~1j au lieu de 1-2j).
   - 16.13 done → 8 des 10 endpoints `*_out.php` portés sous `/api/v1/workstation-config/*`, 17.6 réduit aux 2 endpoints orphelins `wpkg/{linux,winget}_out.php` (~2.5-3j au lieu de 3-5j).
5. **Recadrage Epic 17 final** : 6 stories conservées (17.1 → 17.6), ~7-9j au total (au lieu de 10-14j initialement estimés), économie ~30-40 % grâce aux livrables Epic 16.
6. **Q2 et Q3 déférées** au dev de 17.2 (sources whitelist) et 17.4 (cas Linux testés) respectivement — pattern par défaut `config('sambaedu.<key>')` + fallback `env('<KEY>')`.
7. **Q4 décision Henri** : porter les 2 endpoints orphelins `wpkg/{linux,winget}_out.php` dans 17.6 en réutilisant le code legacy comme base de portage, mais en remplaçant les requêtes LDAP par des requêtes Eloquent sur les modèles natifs (`Workstation`, `Application`, `WorkstationGroup`, `AppProfile`).
8. **Pas de code applicatif** dans cette story (AC1.5 respecté). Aucun fichier sous `app/`, `routes/`, `database/migrations/`, `resources/`, `config/`, `tests/` modifié.

### File List

```
_bmad-output/planning-artifacts/audit-applications-scripts.md   ← LIVRABLE UNIQUE (créé 2026-05-14, patché 2026-05-21)
_bmad-output/planning-artifacts/epics.md                        ← Recadrage Epic 17 stories 17.1-17.6 (2026-05-21)
_bmad-output/implementation-artifacts/17-1-audit-scripts-windows-linux.md  ← cette story (status, tasks, dev agent record)
_bmad-output/implementation-artifacts/sprint-status.yaml        ← status 17.1 + renommage clé
_bmad-output/backlog.html                                       ← synchronisation
```

### Change Log

| Date       | Auteur                              | Changement                                                            |
|------------|-------------------------------------|-----------------------------------------------------------------------|
| 2026-05-14 | claude-opus-4-7 (SM)                | Story créée post-RESET Epic 17. Status `backlog` → `ready-for-dev`. Epic 17 `backlog` → `in-progress` (première story Epic 17 post-RESET). |
| 2026-05-14 | claude-opus-4-7 (Dev T0-T4)         | Audit `audit-applications-scripts.md` produit (1614 lignes, 7 sections A-G + section I logs + annexes). 8 questions ouvertes Q1-Q8 pour Henri. |
| 2026-05-21 | claude-opus-4-7[1m] (orchestrateur) | Patch post-Epic-16-done : Q1-Q8 résolus (4 produit + 4 techniques investigués par agent Explore), Section G recadrée (estimations 17.3/17.5/17.6 révisées), validation Henri actée. Status `ready-for-dev` → `done`. Sprint-status clé renommée `17-1-fondations-scripts-windows` → `17-1-audit-scripts-windows-linux`. epics.md Epic 17 cadrage 6 stories. |

---

## Recommandation Modèle Dev

**Modèle recommandé : opus**

Raison :

1. **Précédent direct** — l'audit Story 16.1 (`audit-gpo-legacy.md`, ~23 fichiers legacy cartographiés, 7 sections A-G) a été livré par **opus** et validé par Henri sans correction structurelle majeure. Pattern qui fonctionne, on le reproduit.
2. **Volume de lecture/synthèse important** — ~80 scripts du package + 2 scripts WPKG critiques + ~25 scripts install OS à échantillonner + croisement avec les artefacts Story 16.7 (10 fichiers métier) + repo upstream `se4/sources/` (arborescence séparée). C'est un travail de raisonnement structuré, pas de code mécanique.
3. **Croisement multi-artefacts à risque** — la valeur de l'audit dépend de la qualité du croisement entre (a) la réalité des scripts upstream, (b) le portage natif déjà fait par 16.7, (c) la frontière 16.6 (`wpkg.js`), (d) les endpoints Epic 15. Une fausse cohérence ici se propage sur tout Epic 17 (3 stories aval). Sonnet risque de manquer une incohérence subtile (ex. placeholder consommé par un script mais absent de la whitelist 16.7).
4. **Décisions de cadrage à prendre pendant la rédaction** — au moins 3 décisions justifiées attendues (in/out scope `install/os/SambaEdu`, frontière `wpkg.js`, comportement attendu de la GPO native pour 17.3). Méta-raisonnement mieux servi par opus.
5. **Pas de productivité gagnée par sonnet** — la story est intrinsèquement lente (lecture + synthèse rigoureuse). L'enjeu n'est pas la vitesse mais la complétude / la justesse. Coût opus vs sonnet largement amorti par la qualité du document structurant tout Epic 17.

Le dev sonnet serait viable pour produire une cartographie « plate » des 80 scripts (Section A), mais **risqué** pour les sections B (catalogue placeholders croisé whitelist), C (catalogue endpoints croisé Epic 15/16/16.7), F (risques de rupture), G (recommandations de découpage) et la décision D7 (install/os/SambaEdu in/out scope).

**Cadrage objectif** : 1-2 jours d'audit pur. Recadrage 2-3 jours si la lecture des scripts révèle des écarts majeurs avec la whitelist 16.7 ou si la GPO orchestratrice `se4_applications` doit faire l'objet d'une nouvelle story Epic 16 (impact sur Section G).
