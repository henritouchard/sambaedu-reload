# Story 27.20: Staging des outils WPKG partagés (`%Z%\wpkg\tools\`) sur le poste

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an **administrateur d'établissement SE5**,
I want **que les outils WPKG partagés (`7za.exe`, `nircmd.exe`, …) soient présents sur le poste à l'emplacement attendu (`%Z%\wpkg\tools\` = `c:\windows\install\wpkg\tools\`)**,
so that **les recettes qui les invoquent (extraction d'archive, création de raccourcis) s'exécutent réellement — aujourd'hui elles échouent car les outils ne sont jamais déposés sur le poste**.

## Contexte & cadrage (suite e2e 27.19, 2026-06-24)

L'e2e de 27.19 sur la VM a révélé un gap résiduel. 27.19 (puis son extension `%Z%\packages\`) livre désormais en HTTP les **payloads par-app**. Mais une partie des recettes dépend aussi d'**outils PARTAGÉS** appelés via `%Z%\wpkg\tools\…` :

- `%Z%\wpkg\tools\7za.exe` — archiveur (extraction des `.zip`/`.7z` côté poste, ex. recette `adnarn`) ;
- `%Z%\wpkg\tools\nircmd.exe` — création de raccourcis (`NirCmd.exe shortcut …`) ;
- (présents serveur, pas encore référencés mais du même canal) `md5sum.exe`, `wintail.exe`, `tooltip/wpkg-msg.exe`, `tooltip/tooltip.exe`.

`%Z%` = `c:\windows\install` ; `%Z%\wpkg\tools\` = `c:\windows\install\wpkg\tools\` — **vide en SE5** (le montage SMB legacy qui le peuplait est débranché, cf. [[project_wpkg_native_bundle_payload_gap]]). Ce ne sont **PAS** des payloads par-app : ils n'ont **aucun `<download saveto>`** dans les recettes → 27.19 ne les couvre pas (à dessein — réécriture chirurgicale `%Z%\packages\` uniquement, cf. [[project_wpkg_zvar_packages_and_tools_gap]]).

Constat e2e (`C:\Windows\wpkg.log`, poste `testenrol`) : `adnarn` → `Exit code (1) on command '%ComSpec% /C %Z%\wpkg\tools\7za.exe e … %Z%\packages\adnarn\adnarn.zip'` — même après livraison du payload (`%TEMP%\adnarn\adnarn.zip`), l'outil `7za.exe` absent fait échouer l'extraction.

**Décision de cadrage à acter** : les outils sont « pareils pour tous » (config d'instance, petits, stables) — comme les scripts du bundle. La direction naturelle = **les déposer UNE FOIS sur le poste** à `c:\windows\install\wpkg\tools\` (l'emplacement que `%Z%\wpkg\tools\` résout), de sorte que **les recettes restent inchangées** (aucune réécriture `%Z%\wpkg\tools\` — contrairement aux payloads). Source serveur : `/var/sambaedu/unattended/install/wpkg/tools/` (déjà peuplé).

### Hypothèses de cadrage (à valider)
1. **Staging local one-shot, pas de re-download par recette** : déposer les outils sur le poste (≠ télécharger dans `%TEMP%` à chaque install) — ils sont partagés et réutilisés par de nombreuses recettes.
2. **Recettes INCHANGÉES** : on ne réécrit PAS `%Z%\wpkg\tools\…` ; on rend le chemin valide en peuplant le dossier. (Alternative rejetée : réécrire chaque appel d'outil en `%TEMP%` = re-téléchargements redondants + recettes plus fragiles.)
3. **Transport HTTP** (cohérent 27.19) : les outils sont servis par Apache et récupérés par le poste (pas de retour SMB).

## Acceptance Criteria

**Transport HTTP des outils**
1. Les outils WPKG partagés sont accessibles en HTTP depuis le serveur (ex. alias `/wpkg/tools` → `/var/sambaedu/unattended/install/wpkg/tools`), `-Indexes`, `Require all granted`, même garde-fou sécurité que `/wpkg/files` (jamais l'arbre `install` entier, jamais `storage/keys/pki`). Alias câblé dans `scripts/setupApache.sh` + test de complétude `update.sh` (modèle `/wpkg/files`, story 27.19).

**Dépôt sur le poste**
2. Au déclenchement WPKG (ou au bootstrap), le poste dépose/rafraîchit les outils sous `%WinDir%\install\wpkg\tools\` (= `%Z%\wpkg\tools\`), en préservant la sous-arborescence (`tooltip/…`). Idempotent (GET conditionnel / skip si déjà à jour). Le mécanisme s'inscrit dans le canal existant (`wpkg.cmd`/`wpkg-client.vbs`/bundle), agent Go **inchangé**.
3. Les recettes restent **inchangées** : `%Z%\wpkg\tools\7za.exe` et `%Z%\wpkg\tools\nircmd.exe` résolvent vers des fichiers présents → extraction et raccourcis fonctionnent.

**Non-régression & e2e**
4. `adnarn` (recette de référence du gap) s'installe de bout en bout : payload livré (27.19) + outil `7za.exe` présent → extraction OK → `<check>` OK → rapport compliant.
5. Les payloads livrés par 27.19 et la purge `%TEMP%` ne sont pas affectés ; les outils déposés ne sont PAS purgés (ils persistent pour les recettes suivantes).
6. Inventaire serveur des outils servis world-readable (664) sous le canal, comme les payloads.

## Tasks / Subtasks

- [ ] **T1 — Alias Apache `/wpkg/tools`** (AC: 1) — modèle `/wpkg/files` ; `scripts/setupApache.sh` + ajout au test de complétude `update_apache()` de `update.sh`.
- [ ] **T2 — Dépôt des outils sur le poste** (AC: 2,3) — étendre `wpkg.cmd` (ou un bootstrap équivalent) pour fetch HTTP des outils et les déposer sous `%WinDir%\install\wpkg\tools\` (sous-arborescence préservée, idempotent). Décider du manifeste (liste d'outils) vs miroir de répertoire.
- [ ] **T3 — Provisioning serveur** (AC: 6) — s'assurer que les outils sont servis (perms 664/www-admin), `ensure_*` idempotent dans `update.sh` si nécessaire (modèle `ensure_wpkg_bundle`/`ensure_wpkg_smb_client`).
- [ ] **T4 — Tests + doc** (AC: 4,5) — tests HÔTE du mécanisme (alias, manifeste/dépôt) ; runbook `docs/qa/domains/wpkg-deploy.md` (append, scénario adnarn e2e).

## Dev Notes

- Outils présents serveur : `/var/sambaedu/unattended/install/wpkg/tools/{7za.exe, nircmd.exe, md5sum.exe, wintail.exe, README.TXT, tooltip/{wpkg-msg.exe, tooltip.exe, tooltip.au3, wpkg-msg.au3}}`.
- Référencés aujourd'hui par le catalogue : `%Z%\wpkg\tools\7za.exe`, `%Z%\wpkg\tools\nircmd.exe` (casse variable `nircmd`/`NirCmd`).
- `%Z%` = `c:\windows\install` (posé par `wpkg-client.vbs` `MapZ()`), donc cible de dépôt = `c:\windows\install\wpkg\tools\`.
- Cohérence 27.19 : transport HTTP, jamais SMB ; agent Go inchangé (le poste récupère via le canal bundle/wpkg.cmd).
- **À trancher** : (a) déposer via `wpkg.cmd` (au déclenchement, contexte du moteur) vs bootstrap GPO ; (b) manifeste d'outils explicite vs miroir du répertoire serveur ; (c) fréquence de rafraîchissement (chaque run vs versionné).

## Dépendances
- 27.19 (livraison WPKG full HTTP — payloads + extension `%Z%\packages`) — review/done. Cette story complète le volet OUTILS laissé en suivi.

## Recommandation Modèle Dev

**opus** — touche le canal de déclenchement poste (`wpkg.cmd`/VBS), un alias Apache sécurisé, un mécanisme de dépôt idempotent, et exige une validation e2e poste (adnarn). Décisions de cadrage (manifeste vs miroir, dépôt vs bootstrap) à arbitrer.
