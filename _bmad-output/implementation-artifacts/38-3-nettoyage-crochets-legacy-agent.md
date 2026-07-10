# Story 38.3 : Nettoyage des crochets legacy des postes via l'agent

Status: review

<!-- Source d'autorité : _bmad-output/planning-artifacts/epics-extinction-se4.md#Story-38.3
     + Overview pt 3 (crochets clients, diagnostic « ffdiag v2 ») + D2 (nettoyage par canal
     authentifié agent, jamais par code servi en HTTP) + Q1 TRANCHÉE (tombstones purs +
     nettoyage agent) + Q5 TRANCHÉE (option (a) VANILLA, Henri 2026-07-10).
     Diagnostic ffdiag v2 DÉPOUILLÉ en création de story (2026-07-10, lecture seule /vm) —
     résultat consigné ci-dessous, section « Diagnostic ffdiag v2 ». -->

## Story

En tant que responsable du parc,
je veux que l'agent retire des postes les crochets clients SE4 (curl applications,
déclencheurs WPKG legacy, helpers obsolètes),
afin que les postes migrés cessent d'appeler le canal legacy — par le canal authentifié,
pas par du code servi en HTTP (D2).

## Contexte & intention

Les postes (y compris des postes DÉJÀ migrés à l'agent, ex. `.103`) appellent encore
`gpo/applications.php` / `gpo/shortcuts_out.php` au logon : c'est le mécanisme exact de
l'incident Firefox du 2026-07-03 (`project_firefox_profile_forced_no_dir_trap`). La 38.2
rend ces appels inertes (tombstones) ; la 38.3 fait cesser les appels côté poste en
retirant les artefacts legacy LOCAUX, via l'agent (canal authentifié, testé, idempotent,
rapporté — D2/Q1). Elle couvre aussi le nettoyage d'état laissé par le canal : les paires
`profiles.ini`/`installs.ini` Mozilla forcées `sambaedu.default` (Q5, traitement VANILLA).

**Ce que la story livre** — la chaîne complète d'un nouveau mécanisme, patron EXACT des
stories 36.1 (`fs_acl`) / 36.2 (`firewall`) / 35.6 (`privilege`) :

1. **Contrat v1** : type `legacy_cleanup` additif (`semantics: exclusive`, portée
   **Machine**), payload minimal `{mozilla: "vanilla"}` (enum fermé, seule valeur v1 —
   encode la décision Q5 dans le contrat). Golden + doc contrat bumpés (hashes jumeaux
   PHP↔Go).
2. **Capacité de gating** : `legacy_hooks_cleanup` (toggle `unmanaged`/`on`, défaut
   Broadcast `unmanaged` = inactif), projection os=windows mécanisme `legacy_cleanup`,
   override par parc via `capability_assignments` (patron « défaut Broadcast + override
   parc », registre 27.3ter). Provider `LegacyCleanupCapabilityProvider` (scope Machine),
   `StateCompiler` INTOUCHÉ.
3. **Handler Go `legacy_cleanup`** (service SYSTEM seul) : scan + suppression idempotente
   du catalogue d'artefacts legacy (section « Inventaire » ci-dessous). SANS store
   dernier-appliqué (artefacts énumérables par scan, iso `firewall`/`privilege` — PAS
   `fs_acl`).
4. **Reporting standard** : item `compliant` quand le poste est sain (aucune écriture,
   aucun événement serveur nouveau — dédup par hash), `drift` + `Detail` listant les
   artefacts supprimés lors d'une passe de nettoyage.
5. Bump `agent/shared/version.go` **2.8.0 → 2.9.0** + note de publication manuelle.

## Diagnostic ffdiag v2 — RÉSULTAT (dépouillé le 2026-07-10, lecture seule /vm)

La surcharge VM `/etc/sambaedu/applications/firefox/logon.windows` (v2) écrit son
inventaire dans `\\%SE4FS%\users\%username%\ffdiag.txt`. **Résultat TROUVÉ** :
`/home/nicolasquipai.louis/ffdiag.txt` sur la VM (logon du 2026-07-06 17:06, poste migré,
agent + tâche `\SambaEduAgent-SessionCompanion` présents).

**Constaté sur le poste (état local)** :

- **Tâches planifiées** : AUCUNE tâche legacy (pas de `wpkg4`, pas de `logon-system`) —
  seulement `\SambaEduAgent-SessionCompanion` (la nôtre) et les tâches Microsoft.
- **HKLM Run** : `SecurityHealth` seul. **HKCU Run** : `OneDrive` seul. **Userinit** :
  `C:\Windows\system32\userinit.exe,` (propre). → Aucun crochet Run/Userinit.
- **GPO locale** : `C:\Windows\System32\GroupPolicy\{User,Machine}\Scripts\scripts.ini`
  ABSENTS (pas de GPO locale à scripts). Le cache
  `GroupPolicy\DataStore\0\sysvol\...` ne contient QUE `{A5B9AB83-…}` =
  **SE_agent_bootstrap** (notre GPO SE5, à ne jamais toucher).
- **`%ProgramFiles%\SambaEdu`** : uniquement `Agent\agent.exe` (pas de helpers legacy sur
  CE poste — mais ils existent sur les postes installés par SE4, cf. inventaire).
- **`%TEMP%` (per-user)** : `applications-logon.cmd` (10 915 o) et
  `applications-logoff.cmd` (911 o) écrits À 17:06 = à l'instant du logon → **le crochet
  a tiré à ce logon**.

**Origine du tir identifiée (investigation complémentaire, DC `se4ad.localdev.fr`)** :
le déclencheur sur /vm N'EST PAS local au poste. C'est la **GPO de domaine
« applications » `{D418994B-0F25-4C3D-8627-4EB4F913BC12}`**, liée à la **racine du
domaine** (`DC=localdev,DC=fr`), dont le SYSVOL est PLEIN (constat 2026-07-10 —
**contredit l'affirmation « coquille vide sur /vm » de l'Overview d'epic**) :

- `User\Scripts\Logon\logon.cmd` / `Logoff\logoff.cmd` : le patron exact
  `curl.exe -o "%temp%\applications-logon.cmd" -F "os=windows" -F "action=logon" … "http://%SE4FS%.localdev.fr/gpo/applications.php"` puis `call`.
- `Machine\Scripts\Startup\startup.cmd` / `Shutdown\shutdown.cmd` : idem
  `action=startup|shutdown`.
- `Machine\Preferences\ScheduledTasks\ScheduledTasks.xml` (GPP, `removePolicy=0`) :
  4 tâches `logon-system`, `logoff-system`, `remote-logon-system`, `remote-logoff-system`
  (curl `action=logon-system` → `%windir%\temp\applications-logon-system.cmd` en SYSTEM,
  etc.). Ces tâches ne sont PAS présentes sur le poste diag (GPP non appliquée là), mais
  `removePolicy=0` signifie qu'un poste qui les a reçues LES GARDE même GPO retirée.

**Conséquences pour la story** (cf. Piège #1) : les scripts GPO de domaine s'exécutent
depuis SYSVOL à chaque logon — l'agent ne peut PAS les retirer (objet AD, pas artefact
local). Le périmètre 38.3 = artefacts LOCAUX (Q1/D2). La neutralisation de la GPO domaine
« applications » est une opération serveur/AD hors agent (voir Piège #1 pour l'e2e). Sur
le lab, les GPO legacy sont des coquilles vides (`project_lab_gpo_wpkg_sysvol_empty`,
`project_migration_passthrough_gpo_lab`) → là-bas, les appels résiduels viennent bien des
crochets locaux, que cette story nettoie.

## Inventaire des crochets à nettoyer (catalogue agent — versionné dans le code Go)

Sources : ffdiag v2 (ci-dessus), GPO « applications » lue sur le DC, et repo legacy
`/home/htouchard/code/irundo/codebase/sambaedu/` (`gpo/applications.php:3-9`,
`includes/applications.inc.php:378-381,445-478,506-516,732-748`,
`ipxe/Win10/action.php:83-88,88,94,216-217,253-258,306-313,390-391`,
`ipxe/Win10/unattend.xml:96-103,154-170`).

**A — Blobs et marqueurs du canal applications** :
- `%windir%\applications-*.cmd` (patron ancien, blob posé dans `%windir%`) ;
- `C:\Users\<profil>\AppData\Local\Temp\applications-*.cmd`, `applications-*.ps1`,
  `shortcuts.cmd` (blobs per-user constatés au ffdiag) ;
- `%windir%\Temp\applications-logon-system*.cmd` (tâches GPP SYSTEM) ;
- marqueurs « once » `%windir%\*.md5` (écrits par `applications.inc.php:506-516`) —
  **garde** : ne supprimer que si le contenu est exactement 32 caractères hexadécimaux
  (± fin de ligne), signature du marqueur legacy.

**B — Tâches planifiées legacy** (racine du Task Scheduler) : `wpkg4`, `logon-system`,
`logoff-system`, `remote-logon-system`, `remote-logoff-system` — **garde** : nom exact
ET l'action de la tâche référence `gpo/applications.php` ou `wpkg` (lecture XML de la
tâche) ; nom qui matche mais action inconnue → NE PAS supprimer, rapporter en détail.

**C — Scripts GPO LOCALE curl-ant le legacy** (absents du poste diag, possibles sur des
postes plus anciens) : fichiers sous `C:\Windows\System32\GroupPolicy\{User,Machine}\Scripts\`
dont le CONTENU matche `curl` + `gpo/applications.php|gpo/shortcuts_out.php` → supprimer
le fichier + purger l'entrée `scripts.ini` correspondante. **INTERDIT** : toucher
`GroupPolicy\DataStore\` (cache des GPO de DOMAINE, propriété du CSE Windows — contient
notre SE_agent_bootstrap).

**D — Déclencheurs et résidus WPKG legacy + canal install** :
- `%WinDir%\wpkg-client.vbs`, `%windir%\wpkg-gpo.txt` ;
- jonctions `%WinDir%\install` et `%WinDir%\rapports` → **UNIQUEMENT si reparse point**
  (jonction/lien vers `\\<serveur>\…`) ; un vrai dossier = provisionné par le module natif
  27.20, NE PAS TOUCHER (cf. Piège #3). **INTERDIT** : `%SystemRoot%\wpkg.xml` (base
  locale WPKG, encore utilisée par le canal natif) ;
- `%windir%\action.cmd`, `%windir%\autorun.cmd`, `%windir%\gpo.txt`, `C:\Netinst\`
  (staging install legacy, `RemoveAll` autorisé sur CE chemin précis) ;
- valeur registre `HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run\action`
  (relance autorun legacy, `action.php:88`) ;
- autologon résiduel `se4install` : sous
  `HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon`, **si et seulement si**
  `DefaultUserName == se4install` → supprimer `DefaultPassword`, `AutoAdminLogon`,
  `AutoLogonCount`, `DefaultUserName`, `DefaultDomainName` (mot de passe en CLAIR
  résiduel = hygiène sécurité ; la garde interdit de casser un autologon légitime).

**E — Helpers `%ProgramFiles%\SambaEdu` obsolètes** (liste blanche NOMMÉE, jamais de
suppression du dossier) : `powershellTask.ps1`, `driversAuto.ps1`, `winget-install.ps1`,
`SetWallpaper.ps1`, `Nettoyage WPKG.cmd` (variantes de casse `SambaEdu`/`Sambaedu`) +
`%WINDIR%\Web\SE4\SetWallpaper.ps1` (et le dossier `Web\SE4` s'il est vide après).
**INTERDIT ABSOLU** : `%ProgramFiles%\SambaEdu\Agent\**` (l'agent lui-même) et tout
répertoire d'outils/overlay provisionné par SE5.

**F — Paires Mozilla forcées (Q5, traitement VANILLA)** : pour chaque profil réel sous
`C:\Users\*` (dossiers réels seulement — sauter reparse points, `Public`, `Default`,
`Default User`, `All Users`) :
- `AppData\Roaming\Mozilla\Firefox\` : si `profiles.ini` référence `sambaedu.default`
  (section `[InstallXXXX]` `Default=sambaedu.default` — hash constaté
  `308046B0AF4A39CB` — ou `[ProfileN]` `Path=sambaedu.default`) → supprimer la PAIRE
  `profiles.ini` + `installs.ini`. **NE JAMAIS supprimer le dossier `sambaedu.default`**
  (données utilisateur potentielles). Un `profiles.ini` qui ne référence PAS
  `sambaedu.default` = géré par l'utilisateur → NE PAS TOUCHER.
- `AppData\Roaming\Thunderbird\` : même logique, même garde.
- **PAS de profil forcé posé par l'agent** (option (a) vanilla tranchée par Henri le
  2026-07-10) : Firefox/Thunderbird recréent et gèrent leur profil localement au
  prochain lancement.

**Hors périmètre explicite** : la variable d'environnement machine `SE4FS` (posée par
`SETX /m` legacy — une variable n'est pas un crochet, et d'autres chemins peuvent encore
la lire) ; les `.lnk` de bureau legacy (le module raccourcis natif gère le bureau) ; la
GPO de domaine « applications » et son cache DataStore (voir Piège #1).

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **Piège #1 — le déclencheur sur /vm est une GPO de DOMAINE, pas un crochet local.**
   Constat de création de story (contredit l'Overview d'epic) : la GPO « applications »
   `{D418994B-…}` est PLEINE sur le DC dev et liée à la racine. Tant qu'elle est active,
   `logon.cmd` re-curl-e à CHAQUE logon depuis SYSVOL et recrée les blobs `%TEMP%` — le
   nettoyage local ne peut donc PAS produire « zéro hit `gpo/*.php` » sur /vm à lui seul.
   Ce n'est PAS un échec du module : l'idempotence signifie re-nettoyage des blobs à
   chaque passe, sans erreur. L'e2e « zéro hit » (AC7) se joue sur un poste LAB migré
   (GPO legacy = coquilles vides là-bas) OU sur /vm APRÈS neutralisation de la GPO
   domaine (opération dev : VIDER `{D418994B-…}` — coquille + bump GPT.INI, patron des shells se4_*.zip legacy, cf. lab1 —, JAMAIS la délier ni la supprimer : GPO globale multi-étabs. Vider ses scripts
   avec bump `GPT.INI` — `project_gpo_template_edit_needs_version_bump`). Sur lab1
   (AD fédéré, 75 étabs) : ne JAMAIS toucher les GPO racine
   (`project_ad_federated_root_gpos`). **À REMONTER à Henri / à la 38.2-38.6** : les hits
   tombstones pilotés par cette GPO domaine ne s'éteindront pas sans action AD — le
   critère GO de la 38.6 doit en tenir compte.
2. **Piège #2 — nouveau TYPE : le binaire antérieur IGNORE EN SILENCE (contrat §8).**
   Un agent ≤ 2.8.0 qui reçoit `legacy_cleanup` n'émet AUCUN statut. La release
   **2.9.0 DOIT être publiée MANUELLEMENT** (update.sh ne publie JAMAIS —
   `project_agent_selfupdate_validated_publish_gap`, piège « handler absent du binaire
   publié »). Vérifier au passage l'état de publication des 2.6.0/2.7.0/2.8.0 (non
   publiées à la création de 35.6) : publier 2.9.0 livre alors fs_acl + firewall +
   privilege + legacy_cleanup d'un coup — à consigner au Dev Agent Record.
3. **Piège #3 — jonctions `%WinDir%\install` : ne pas casser le provisioning natif.**
   Le module `provision` (27.20) matérialise `%WinDir%\install\wpkg\tools` en dossiers
   RÉELS et retire déjà un reparse-point SMB legacy (`provision_windows.go`). Supprimer
   la jonction SEULEMENT si c'est un reparse point ; réutiliser/imiter la détection
   existante. Un vrai dossier `install` = natif, INTOUCHABLE.
4. **Piège #4 — suppression = liste blanche, jamais de récursif large.** `os.Remove`
   ciblé par chemin/glob précis ; `os.RemoveAll` UNIQUEMENT sur `C:\Netinst` et
   `%WINDIR%\Web\SE4` (chemins exclusivement legacy). JAMAIS de suppression du dossier
   `%ProgramFiles%\SambaEdu` ni de `GroupPolicy\DataStore`. Chaque catégorie porte sa
   garde (contenu 32-hex pour les `.md5`, contenu curl pour les scripts GPO locale,
   action XML pour les tâches, `DefaultUserName==se4install` pour Winlogon, référence
   `sambaedu.default` pour Mozilla).
5. **Piège #5 — Mozilla : la paire `.ini`, RIEN d'autre.** Supprimer
   `profiles.ini`+`installs.ini` UNIQUEMENT quand ils référencent `sambaedu.default` ;
   jamais le dossier de profil (perte de données) ; jamais un `profiles.ini` sain
   (poste perso, profil géré par l'utilisateur). C'est LE nettoyage qui évite le
   « profil manquant ou inaccessible » DÉFINITIF post-extinction (incident 2026-07-03) —
   le fragment de l'incident créait le dossier ; une fois le canal éteint (38.2), plus
   rien ne recrée ni le dossier ni les `.ini` : sans 38.3, tout compte n'ayant pas ouvert
   de session pendant la fenêtre du fix /etc reste cassé.
6. **Piège #6 — « poste sain ne rapporte rien » = sémantique engine existante.**
   Interprétation contractuelle : `Test` → compliant → aucune écriture disque, item
   `compliant` sans `Detail`, rapport identique au précédent → dédup serveur par hash →
   ZÉRO événement `agent_report_events`. Ne PAS inventer un mécanisme « omettre l'item »
   (le type présent au state émet toujours son statut — iso tous les handlers).
7. **Piège #7 — gating : `unmanaged`/`on` SEULEMENT, pas de `off`.** Le nettoyage est
   one-way (on ne « restaure » pas des crochets legacy) : un `off` n'aurait aucune
   sémantique opératoire. La règle « off écrit une vraie valeur »
   (`project_capability_value_map_symmetric_rule`) s'applique aux maps registre
   symétriques, pas ici — le consigner dans le seed pour préempter la review.
   `unmanaged` (défaut Broadcast) = aucun item émis = agent inactif sur ce type.
8. **Piège #8 — PAS de store dernier-appliqué.** Les artefacts sont énumérables par scan
   à chaque passe (iso `firewall`/`privilege`, PAS `fs_acl`). Un store serait une seconde
   source de vérité inutile (`feedback_no_overengineered_choices`).
9. **Piège #9 — worktree/VM.** Story développée dans le worktree `ultradev/38-3` : ne
   jamais interagir avec la VM depuis le worktree pour tester du code (lecture seule OK) ;
   tests Go/PHP sur l'HÔTE (`project_phpunit_test_env_host_vs_vm`, Go toolchain
   `~/go-toolchain/go/bin` hors PATH). E2e = après merge + publication.

## Décisions de design (tranchées en création de story)

- **D1 — Handler d'engine, pas module `provision/`.** L'AC d'epic dit « module de type
  Resource/Reconcile, patron tools-manifest 27.20 » : l'esprit (Test/Apply convergent,
  idempotent) est celui du patron **Handler `shared.Engine`** — `provision/` est un
  téléchargeur de fichiers piloté par manifeste statique, SANS reporting ni gating
  serveur, inadapté ici. Le handler `legacy_cleanup` suit le patron de `FirewallHandler`
  (réconciliation par scan, sans store) avec une interface `LegacyCleanupOps`
  (impl Windows séparée, fake en test).
- **D2 — Portée Machine, SYSTEM seul.** HKLM, schtasks, `C:\Users\*` : tout est
  accessible en SYSTEM ; aucun volet compagnon session.
- **D3 — Catalogue d'artefacts versionné DANS l'agent.** C'est de la connaissance
  legacy figée (chemins Windows), pas du paramétrage métier : le serveur gate (capacité),
  l'agent sait QUOI nettoyer. Payload contrat minimal `{mozilla: "vanilla"}` (enum fermé
  1 valeur — trace contractuelle de Q5(a), extensible si (b)/(c) revenait un jour).
- **D4 — Chaque suppression est individuellement gardée et rapportée.** Échec partiel
  (fichier verrouillé, accès refusé) = item `error` avec détail des échecs, les autres
  suppressions restent acquises ; la passe suivante retente (level-triggered).
- **D5 — Q5 VANILLA strict** : suppression des paires `.ini` référençant
  `sambaedu.default`, aucun profil forcé posé, dossiers de profil préservés.

## Acceptance Criteria

### AC1 — Contrat v1 : type `legacy_cleanup` publié

**Given** le contrat agent v1 (`app/Services/Agent/StateContract.php` +
`agent/shared/contract.go`)
**When** le type additif `legacy_cleanup` est ajouté (`semantics: exclusive`, portée
Machine, payload `{mozilla}` enum fermé `["vanilla"]`)
**Then** golden fixtures jumelles mises à jour (+1 item machine,
`tests/Fixtures/Agent/*.v1.json`), `FROZEN_STATE_HASH` PHP == `frozenStateHash` Go
recalculés croisés (baseline post-privilege), `report.v1.json` inchangé (justifié), doc
contrat (§7.x) bumpée.

### AC2 — Gating serveur : capacité `legacy_hooks_cleanup` (défaut Broadcast + override parc)

**Given** la capacité seedée `legacy_hooks_cleanup` (toggle `unmanaged`/`on`,
`default_value='unmanaged'`, libellé convention sujet+état — ex. « Crochets legacy SE4 » :
`unmanaged` « Non géré » / `on` « Nettoyés » —, `applies_to_os=[windows]`, projection
os=windows mécanisme `legacy_cleanup`)
**When** la valeur effective d'un poste est `on` (Broadcast OU override
`capability_assignments` sur un parc, précédence standard du compilateur)
**Then** le provider `LegacyCleanupCapabilityProvider` (scope Machine) émet UN item
`legacy_cleanup` pour ce poste ; en `unmanaged`, AUCUN item n'est émis (agent inactif) ;
`StateCompiler` INTOUCHÉ ; la capacité apparaît dans l'UI capacités existante sans
travail UI dédié.

### AC3 — Handler Go : nettoyage idempotent du catalogue (crochets, WPKG, install, helpers)

**Given** un poste enrôlé dont l'état local contient des artefacts des catégories A à E
de l'inventaire (blobs `applications-*.cmd`/`.ps1`/`shortcuts.cmd`, marqueurs `.md5`
32-hex, tâches `wpkg4`/`*-system` dont l'action référence le legacy, scripts GPO locale
curl-ant `gpo/*.php` + entrées `scripts.ini`, `wpkg-client.vbs`, jonctions
`install`/`rapports` si reparse points, `action.cmd`/`autorun.cmd`/`gpo.txt`/
`wpkg-gpo.txt`/`C:\Netinst`, valeur Run `action`, autologon `se4install` gardé,
helpers `%ProgramFiles%\SambaEdu` en liste blanche + `%WINDIR%\Web\SE4`)
**When** l'agent converge (item `legacy_cleanup` présent au state)
**Then** chaque artefact TROUVÉ est supprimé avec sa garde propre (Piège #4) ; une
seconde passe ne trouve plus rien et n'écrit rien (idempotence prouvée par test —
2 `RunPass`, 2e = compliant) ; les INTERDITS (agent, `wpkg.xml`, DataStore, dossier
`SambaEdu`, dossiers `sambaedu.default`, vrais dossiers `install`) ne sont JAMAIS
touchés (tests négatifs explicites).

### AC4 — Nettoyage Mozilla VANILLA (Q5-a)

**Given** des profils Windows sous `C:\Users\*` contenant des paires
`profiles.ini`/`installs.ini` Firefox et/ou Thunderbird référençant `sambaedu.default`
**When** l'agent converge
**Then** ces paires sont supprimées (les DEUX fichiers), le dossier `sambaedu.default`
et tout autre contenu du profil sont PRÉSERVÉS, un `profiles.ini` ne référençant pas
`sambaedu.default` est INTOUCHÉ, les répertoires spéciaux/jonctions de `C:\Users` sont
sautés, et AUCUN profil forcé n'est posé par l'agent.

### AC5 — Reporting standard, poste sain silencieux

**Given** le reporting agent standard (`shared.ReportItem`, `POST /api/v1/agent/report`)
**When** une passe a supprimé des artefacts
**Then** l'item `legacy_cleanup` est rapporté `drift` avec `Detail` listant les artefacts
supprimés (borné 2000 runes) ; échec partiel → `error` avec détail des échecs
**And** un poste sain rapporte `compliant` sans `Detail` : aucune écriture disque,
aucun nouvel événement serveur (dédup par hash) — Piège #6.

### AC6 — Version agent + publication manuelle

**Given** `agent/shared/version.go` à 2.8.0
**When** la story est livrée
**Then** version bumpée **2.9.0**, et le Dev Agent Record rappelle EXPLICITEMENT :
publication MANUELLE obligatoire (update.sh ne publie jamais ; binaire ≤ 2.8.0 ignore le
type EN SILENCE), publier AVANT de jouer la migration seed sur la cible
(`project_epic35 : publier AVANT migrate`), état de publication des 2.6.0→2.8.0 vérifié
et consigné.

### AC7 — E2e lab : plus aucun hit `gpo/*.php`

**Given** un poste lab MIGRÉ portant des artefacts legacy, la release 2.9.0 publiée, la
migration seed jouée, la capacité `on` sur le parc du poste
**When** convergence + reboot + logon
**Then** plus AUCUN hit `gpo/*.php` de ce poste dans les logs serveur, Firefox démarre et
recrée un profil local sain (pas de « profil manquant ou inaccessible »), le rapport
agent montre la passe `drift` (nettoyage) puis `compliant` (stable)
**And** la limite d'environnement /vm (GPO domaine pleine — Piège #1) est documentée dans
le protocole QA : sur /vm l'e2e « zéro hit » exige la neutralisation AD préalable.

## Tasks / Subtasks

- [x] **T1 — Contrat + golden (AC1)**
  - [x] `StateContract.php` : type `legacy_cleanup` (exclusive, Machine, payload
        `{mozilla}` enum `["vanilla"]`), validation payload
  - [x] `agent/shared/contract.go` : constante type + comptage ResourceTypes
  - [x] Golden : +1 item machine dans les fixtures jumelles, recalcul croisé
        `FROZEN_STATE_HASH`/`frozenStateHash` (`fc8a5324…8738b`), justification
        `report.v1.json` inchangé
  - [x] Doc contrat §7.10 (nouveau type, payload, sémantique) + liste des
        identifiants §7 + exemple d'évolution §9
- [x] **T2 — Capacité + provider (AC2)**
  - [x] `CapabilityProjection` : constante `MECHANISM_LEGACY_CLEANUP`
  - [x] Migration seed `legacy_hooks_cleanup` (patron seed fs_acl/firewall :
        idempotente, réversible ; justification « pas de off » consignée en
        commentaire — Piège #7)
  - [x] `LegacyCleanupCapabilityProvider` (scope Machine, exclusiveKey fixe
        `legacy_cleanup`, hive `''`) + enregistrement `AgentServiceProvider`
  - [x] Tests PHP : `CapabilityLegacyCleanup{Provider,Compilation,Seed}Test` (émission
        `on` broadcast/override parc, silence `unmanaged`, précédence, payload exact,
        byte-identité du hash compilé avec le golden) +
        non-régression `ContractV1|StateHasher|StateCompiler` (47 passed)
- [x] **T3 — Handler Go + Ops Windows (AC3, AC4)**
  - [x] `agent/shared/handler_legacy_cleanup.go` : `LegacyCleanupHandler{Ops, Log}`,
        `Test` (scan → compliant si zéro artefact), `Apply` (suppression gardée par
        catégorie, collecte des détails/échecs), types de findings
  - [x] Interface `LegacyCleanupOps` : opérations de scan/suppression par catégorie
        (fichiers/globs, tâches planifiées avec lecture d'action, registre via
        `RegistryOps.Delete` réutilisé, reparse points, énumération `C:\Users`,
        parsing `profiles.ini`)
  - [x] `agent/windows/handler_legacy_cleanup_windows.go` : impl Windows
        (réutilise `runPowershell`/`psQuote` de `tasks_windows.go` pour les tâches,
        `golang.org/x/sys/windows/registry` via le patron `registryOps`, la détection
        de reparse point du patron `provision_windows.go`)
  - [x] Enregistrement `MachineEngine` (`agent/windows/main_windows.go`) — machine
        SEULEMENT, pas le compagnon
  - [x] Tests Go (`handler_legacy_cleanup_test.go`, fake Ops en mémoire) : chaque
        catégorie A-F, chaque GARDE (md5 non-hex intouché, tâche au nom connu mais
        action inconnue intouchée+rapportée, `profiles.ini` sain intouché, vrai dossier
        `install` intouché, `DefaultUserName≠se4install` intouché), idempotence
        (2 RunPass, zéro op à la 2e), échec partiel → error + acquis conservés,
        scripts.ini UTF-16 round-trip
- [x] **T4 — Reporting (AC5)** : Detail borné 2000 runes via l'interface additive
      `DetailReporter` du moteur (`engine.go`), format stable des identifiants
      d'artefacts (`task:wpkg4`, `file:C:\…`, `reg:HKLM\…\Run\action`,
      `mozilla:C:\Users\x\…\profiles.ini`) ; test du silence poste sain
- [x] **T5 — Version + publication (AC6)** : bump 2.9.0 + note publication manuelle au
      Dev Agent Record (+ état 2.6.0→2.8.0 : jamais publiées)
- [x] **T6 — QA/e2e (AC7)** : protocole dans `docs/qa/domains/agent.md` (append-only,
      scénarios 38.3.1→38.3.5 + check-list) : publication → migrate → capacité `on`
      parc pilote → convergence → reboot+logon → grep logs `gpo/*.php` → vérif Firefox
      vanilla ; limite /vm (Piège #1) documentée ; remontée 38.6 (hits GPO domaine)
      consignée

## Dev Notes

### Fichiers à toucher (prévu)

- `agent/shared/contract.go`, `agent/shared/version.go` (2.9.0),
  `agent/shared/handler_legacy_cleanup.go` (+ `_test.go`)
- `agent/windows/handler_legacy_cleanup_windows.go`, `agent/windows/main_windows.go`
- `app/Services/Agent/StateContract.php`,
  `app/Services/Agent/Providers/LegacyCleanupCapabilityProvider.php`,
  `app/Providers/AgentServiceProvider.php`, `app/Models/CapabilityProjection.php`
- `database/migrations/2026_07_XX_XXXXXX_seed_capability_legacy_hooks_cleanup.php`
- `tests/Fixtures/Agent/*.v1.json`, tests PHP `tests/Feature/Agent/…`, doc contrat +
  `docs/qa/domains/agent.md`

### Patterns existants à imiter (chemins worktree)

- **Handler sans store, réconciliation par scan** : `agent/shared/handler_fs_acl.go`
  (structure Ops/StatePath/Log — SANS reprendre le store) et surtout le patron « conteneur
  sans store » de firewall/privilege (35.6/36.2, cf. leurs stories).
- **Ops registre** : `RegistryOps.Delete` (`agent/shared/handler_registry.go:139`),
  impl `agent/windows/handler_registry_windows.go`.
- **Tâches planifiées** : `agent/windows/tasks_windows.go` (`runPowershell`, `psQuote`,
  Unregister idempotent).
- **Reparse point / matérialisation** : `agent/provision/provision_windows.go`.
- **Provider capacité non-registre** : `FirewallCapabilityProvider` /
  `PrivilegeCapabilityProvider` (scope Machine, hive `''`, exclusiveKey fixe,
  `StateCompiler` intouché).
- **Seed capacité** : migration
  `database/migrations/2026_06_18_1001*` + patron seed 36.x (idempotent, réversible,
  libellés sujet+état).
- **Tests handler** : `agent/shared/handler_fs_acl_test.go` /
  `handler_privilege_test.go` (fake Ops, `Engine.RunPass` ×2 pour l'idempotence).

### Rappels transverses (garde-fous epic + projet)

- Story agent Go : bump `agent/shared/version.go` + publication manuelle (garde-fou
  d'epic, `project_agent_handler_not_in_published_binary`).
- Éditer `agent/**` ⇒ bump version (`feedback_agent_edit_bump_version`).
- Tests : HÔTE uniquement (php 8.4 + sqlite ; `go test ./...` +
  `GOOS=windows go build/vet` ; toolchain Go `~/go-toolchain/go/bin`).
- VM : migrations jamais auto-jouées ; lecture seule depuis le worktree ; inotify ne
  sync pas les deletes.
- Jamais `rm -rf` (utiliser `trash` côté ops si besoin) — côté agent, suppressions
  ciblées uniquement (Piège #4).

### Project Structure Notes

- Aucune route web/API nouvelle, aucune UI nouvelle (la capacité s'affiche dans l'UI
  capacités existante). Aucun conflit attendu avec 38.1/38.2/38.4/38.5 (elles ne
  touchent ni `agent/` ni les providers de capacités) ; 38.3 est la seule story agent
  de l'epic.

### References

- [Source: _bmad-output/planning-artifacts/epics-extinction-se4.md#Story-38.3 + Overview
  pt 3 + D2 + Q1 + Q5]
- [Source: diagnostic ffdiag v2 — /vm `/home/nicolasquipai.louis/ffdiag.txt` (2026-07-06)
  + GPO `{D418994B-0F25-4C3D-8627-4EB4F913BC12}` lue sur `se4ad.localdev.fr` (2026-07-10)]
- [Source: repo legacy `/home/htouchard/code/irundo/codebase/sambaedu/` —
  `gpo/applications.php`, `includes/applications.inc.php`, `ipxe/Win10/action.php`,
  `ipxe/Win10/unattend.xml`]
- [Source: stories 35.6 / 36.1 / 36.2 (patron mécanisme complet), story 27.20
  (module provision)]

## Dépendances

- **Pour le DEV : AUCUNE** — indépendante des autres 38.x (fichiers disjoints,
  mécanisme auto-porté). Peut se développer en parallèle de 38.1/38.2/38.4.
- **Pour l'e2e FINAL « zéro hit gpo/*.php »** : suppose la **38.2** (tombstones) livrée
  pour que les appels résiduels des postes non encore nettoyés soient inertes et
  mesurables, et — sur /vm uniquement — la neutralisation AD de la GPO domaine
  « applications » (Piège #1). Ces conditions ne bloquent PAS le développement ni les
  tests hôte ; l'e2e lab de l'AC7 est réalisable dès publication (GPO lab vides).
- Aval : la **38.6** consomme l'observabilité (extinction du troupeau) ; la remontée du
  Piège #1 (hits pilotés par la GPO domaine) doit être prise en compte dans son
  critère GO.

## Recommandation Modèle Dev

**FABLE** — prescription EXPLICITE de l'epic (garde-fous : « Reco dev : fable pour 38.3
(agent Go) ») : handler Go Windows avec gardes de sûreté nombreuses (suppressions sur
postes réels, autologon/mots de passe résiduels, Mozilla données utilisateur), patron
mécanisme complet contrat+golden+provider+handler. Review adversariale par le modèle
opposé (opus) recommandée — criticité : code qui SUPPRIME des fichiers sur tout le parc.

## Dev Agent Record

### Agent Model Used

claude-fable-5 (prescription epic) — développement en DEUX reprises : un premier
agent fable a livré T1 (contrat+golden+jumeaux), T3 (handler Go+Ops Windows+
tests), T4 (DetailReporter moteur) et T5 (bump 2.9.0), puis a été coupé (erreurs
réseau) ; un second agent fable a repris et terminé T2 (capacité+provider+seed+
3 tests PHP), la doc contrat §7.10 (sous-tâche T1 restante), T6 (runbook QA) et
la clôture de story. Aucune perte : l'état intermédiaire a été vérifié fichier
par fichier avant reprise.

### Debug Log References

- `php artisan test --filter='CapabilityLegacyCleanup|ContractV1Test'` →
  **22 passed (183 assertions)** (hôte php 8.4 + sqlite).
- `php artisan test --filter='ContractV1|StateHasher|StateCompiler'` →
  **47 passed (268 assertions)** (non-régression).
- `go -C agent test ./...` → **ok** (`sambaedu/agent/shared`,
  `sambaedu/agent/provision` ; `agent/windows` sans fichiers de test).
- `GOOS=windows go -C agent build ./...` + `vet ./...` → OK.
- `gofmt -l` : aucun fichier de la story listé (le drift gofmt résiduel du
  dépôt concerne des fichiers hors périmètre, non touchés).

### Completion Notes List

- **⚠️ PUBLICATION MANUELLE OBLIGATOIRE (AC6, piège #2)** : la release
  **2.9.0** doit être publiée À LA MAIN (update.sh ne publie JAMAIS) — un
  binaire ≤ 2.8.0 IGNORE le type `legacy_cleanup` EN SILENCE (« poste jamais
  nettoyé, zéro erreur »). Publier AVANT de jouer la migration seed sur la
  cible et d'armer la capacité. **État de publication vérifié** (en-tête
  `version.go`) : les **2.6.0 (fs_acl), 2.7.0 (firewall) et 2.8.0 (privilege)
  n'ont JAMAIS été publiées** → publier la 2.9.0 livre les QUATRE mécanismes
  hors-registre d'un coup.
- **Sémantique compilateur consignée** (test dédié + note QA 38.3.4) : la
  discipline UNMANAGED commune aux providers de capacités fait qu'un override
  parc `unmanaged` N'ÉMET PAS de candidat — il ne masque donc PAS un défaut
  Broadcast `on`. Le désarmement global passe par le default_value Broadcast ;
  sans conséquence opératoire ici (nettoyage one-way idempotent, le handler ne
  pose rien).
- **Remontée 38.6 (piège #1)** : sur /vm, la GPO de DOMAINE « applications »
  `{D418994B-…}` est PLEINE et liée à la racine — les hits `gpo/*.php` pilotés
  par elle ne s'éteindront PAS par le nettoyage local seul ; le critère GO de
  la 38.6 doit intégrer la neutralisation (VIDER la GPO + bump GPT.INI — jamais délier/supprimer, elle est globale multi-étabs ; c'est le mécanisme standard des coquilles se4_*.zip du paquet legacy, absent de la VM dev d'où la GPO restée pleine).
  Documenté au runbook QA (limite /vm).
- **E2e lab (AC7) RESTANT après merge** : publication 2.9.0 → migrate → armer
  parc pilote → scénarios 38.3.1→38.3.5 du runbook `docs/qa/domains/agent.md`.
  Non réalisable depuis le worktree (piège #9).
- `report.v1.json` INCHANGÉ (justifié §9 doc contrat) : aucun champ de rapport
  nouveau — `detail` existait ; seule l'interface Go ADDITIVE `DetailReporter`
  (moteur) l'alimente désormais sur les chemins de succès, bornée 2000 runes.

### File List

**Agent Go (nouveau mécanisme)**
- `agent/shared/contract.go` (M — type `legacy_cleanup`, comptage)
- `agent/shared/contract_test.go` (M)
- `agent/shared/engine.go` (M — interface additive `DetailReporter` + `withDetail`)
- `agent/shared/handler_legacy_cleanup.go` (A — handler + `LegacyCleanupOps`)
- `agent/shared/handler_legacy_cleanup_test.go` (A — fake Ops, gardes A-F,
  idempotence, échec partiel, silence, detail borné, scripts.ini UTF-16)
- `agent/shared/hasher_test.go` (M — `frozenStateHash` recalculé)
- `agent/shared/version.go` (M — 2.9.0 + bloc de release)
- `agent/windows/handler_legacy_cleanup_windows.go` (A — impl Windows des Ops)
- `agent/windows/main_windows.go` (M — enregistrement MachineEngine seul)

**Serveur PHP (contrat + capacité)**
- `app/Services/Agent/StateContract.php` (M — type + validation payload)
- `app/Models/CapabilityProjection.php` (M — `MECHANISM_LEGACY_CLEANUP`)
- `app/Services/Agent/Providers/LegacyCleanupCapabilityProvider.php` (A)
- `app/Providers/AgentServiceProvider.php` (M — enregistrement provider)
- `database/migrations/2026_07_10_100000_seed_capability_legacy_hooks_cleanup.php` (A)

**Tests PHP**
- `tests/Fixtures/Agent/state.v1.json` (M — +1 item machine, golden)
- `tests/Unit/Services/Agent/ContractV1Test.php` (M — hash figé + justification)
- `tests/Unit/Services/Agent/CapabilityLegacyCleanupProviderTest.php` (A)
- `tests/Unit/Services/Agent/CapabilityLegacyCleanupCompilationTest.php` (A)
- `tests/Feature/Migrations/CapabilityLegacyCleanupSeedTest.php` (A)

**Docs**
- `docs/agent/contract-v1.md` (M — §7.10 + liste identifiants + exemple §9)
- `docs/qa/domains/agent.md` (M — append-only, section Story 38.3)

**Suivi**
- `_bmad-output/implementation-artifacts/38-3-nettoyage-crochets-legacy-agent.md` (A)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (M — 38-3 → review)
