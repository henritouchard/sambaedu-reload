# Sprint Change Proposal — 2026-06-09

**Type :** Direct Adjustment (ajout de stories au backlog, non destructif)
**Scope :** Moderate (réorganisation backlog — nouvel Epic 22 + 3 stories)
**Auteur :** session debug windaube (Henri + claude-opus-4-8)
**Rattachement :** product-brief `product-brief-gpo-successor-2026-06-08.md`

---

## 1. Issue Summary

Pendant le debug du poste `windaube` (raccourcis + wallpaper absents), on a découvert que le **port natif des raccourcis** (`ShortcutCompilerService`) avait **perdu la logique legacy local/réseau du bureau** : il figeait le bureau en `%userprofile%\Bureau` (branche `port_perdir`) alors que le défaut legacy est le bureau **réseau** `\\%se4fs%\users\%username%\Bureau`. Sur un poste partagé à bureau redirigé réseau, `%userprofile%\Bureau` n'existe pas → `curl (23)` → aucun raccourci posé (**Bug C**, pansement livré en commit `4e5a152` : défaut → réseau).

L'analyse métier de `port_perdir` a révélé un **concept manquant** dans le refactor : la **nature de poste**.
- `port_perdir` / `portables` = postes **personnels et/ou nomades** (PERDIR = personnels de direction ; portables) → on garde leur **environnement en local** au lieu de le rediriger vers le partage SMB.
- Ça ne s'applique pas « par fichier » mais à **tout ce que le modèle partagé redirige vers le serveur** : bureau (shortcuts.inc.php) + profils navigateur Chrome/Edge/OpenBoard (`redirects.json` excludes).
- « Local » ≠ perdu : c'est le **profil itinérant** (cache local + sync au logon/logoff). Pour un vrai nomade hors-réseau, il faut une stratégie de **sync offline** (Offline Files/CSC) — absente aujourd'hui.

## 2. Impact Analysis

- **Epic Impact :** nouvel **Epic 22 — Successeur GPO : Environnement poste & tags domain-first** (rattaché au product-brief gpo-successor). Touche le périmètre Epic 16/17 (GPO / scripts applications) sans les rouvrir.
- **Story Impact :** 3 nouvelles stories backlog (22-1/22-2/22-3). Le pansement Bug C (`4e5a152`) est **temporaire** et sera remplacé par 22-1.
- **Code (à venir, pas maintenant) :** `App\Enums\WorkstationEnvironment`, colonne sur `workstation_groups`, résolution dans `ApplicationScriptsGenerator::resolveInfo`, consommateurs `ShortcutCompilerService` / redirections navigateur / `clean_profiles`, UI parc-settings.
- **Domain-first :** la résolution lit **Postgres** (pas AD) — fiable car la sync AD→PG est le 1er traitement à l'install SE5.

## 3. Recommended Approach

**Direct Adjustment** : ajouter les 3 stories au backlog en statut `backlog` (le PM les proposera ; aucune implémentation immédiate). Le pansement Bug C reste en place jusqu'à 22-1.

## 4. Detailed Change Proposals

### Epic 22 (nouveau) — `epics.md` + `sprint-status.yaml`

```yaml
epic-22: backlog
22-1-workstation-environment-enum: backlog
22-2-sync-donnees-nomade-offline-files: backlog
22-3-tags-list-domain-first-postgres: backlog
```

- **22-1 — WorkstationEnvironment enum.** Enum `App\Enums\WorkstationEnvironment` (`shared_local` / `personal_local` / `nomade`) porté par `WorkstationGroup` (colonne Postgres ; applicable groupe logique OU physique car résolu côté serveur, pas via GPO/OU). Résolution par machine dans `ApplicationScriptsGenerator::resolveInfo` avec précédence **`nomade` > `personal_local` > `shared_local`** (défaut `shared_local`). Consommée par : (a) chemin du bureau dans `ShortcutCompilerService` — **remplace le pansement Bug C** (`4e5a152`) ; (b) exclusions de redirection des profils navigateur Chrome/Edge/OpenBoard ; (c) gating du `clean_profiles`. + UI de sélection dans parc-settings.
  - Sémantique : `shared_local` = défaut partagé (bureau réseau) ; `personal_local` = modèle perdir (raccourcis bureau local, données sur le home SambaEdu réseau) ; `nomade` = tout local (cf. 22-2 pour la sync).
- **22-2 — Sync données nomade.** Stratégie offline pour les dossiers user en mode `nomade` (**Folder Redirection + Offline Files/CSC** recommandé ; sinon rclone/robocopy) + **désactivation du `clean_profiles`**. Objectif : disponible **offline** ET **resynchronisé** au retour réseau (sinon donnée prisonnière du portable).
- **22-3 — Tags domain-first depuis Postgres.** Sourcer les tags de matching depuis les relations Postgres : `list_u`→`User.groups`, `list_m`→`Workstation.groups` ; **supprimer les clés mortes** `list_m` et `list_ue` (aucun consommateur ; `list_ue`==`list_u` car sam=cn) ; **garder** `list` (union) + `list_u` (load-bearing : matching includes/excludes + wallpaper) ; renommer `tagsUser`/`tagsMachine` pour lisibilité ; reproduire le matching includes/excludes depuis les relations en préservant la parité de nommage. Pré-requis acté : sync AD→PG fiable (1er à l'install).

## 5. Implementation Handoff

- **Scope :** Moderate → backlog (PO/SM). Les 3 stories sont en `backlog`, à cadrer ultérieurement via `bmad-create-story` (ACs complètes au moment du dev).
- **Priorité suggérée :** 22-1 (remplace le pansement + débloque la conf poste), puis 22-3 (dette lisibilité/domain-first), puis 22-2 (feature offline nomade).
- **Bugs déjà traités hors backlog (cette session) :** Bug C (pansement `4e5a152`), Bug D (passthrough migration, checkpoint `b11ca00`), profil temporaire Windows réparé, helpers `%PROGRAMFILES%\SambaEdu` déployés sur windaube.
