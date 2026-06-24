# Story 27.18: Overlay par défaut du parc (header variables + messages conditionnels + broadcast)

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an **administrateur d'établissement SE5**,
I want **configurer l'overlay par défaut de tous les postes — quelles variables afficher dans l'en-tête, des messages conditionnels (ex. quota dépassé → avertissement), et un message broadcast daté — et déplacer l'édition des messages ciblés sur la page du groupe de postes concerné**,
so that **l'overlay communique automatiquement la bonne information contextuelle au bon périmètre, sans configuration par poste**.

## Contexte & cadrage

- **Extraite de la story 27.17** (onglet Overlay sorti pour isoler le net-new lourd). 27.17 livre la page « Configuration par défaut du parc » + les onglets Wallpaper/Lockscreen/Registre/Apps/Outils agent. 27.18 **ajoute l'onglet Overlay** à cette page existante.
- Mécanisme de précédence inchangé : l'overlay par défaut = couche `StateMaille::Broadcast` (plancher), overridable par maille plus spécifique. `StateCompiler` et `specificity()` **non modifiés**. ([Source: app/Services/Agent/StateCompiler.php:383-393])
- Providers concernés : `OverlayStateProvider` (session) et `OverlayMachineStateProvider` (machine) ; signaux `overlay_signals` ; composition overlay côté serveur. ([Source: app/Services/Agent/Providers/OverlayStateProvider.php:42-160 ; app/Services/Overlay/OverlayService.php:32-150])

## Acceptance Criteria

**Onglet Overlay sur la page « config par défaut »**
1. Un onglet **Overlay** est ajouté à `resources/views/pages/admin/settings/parc-defaults/index.blade.php` (livré en 27.17), protégé `server.admin` (cohérent 27.17). Partial Livewire SFC dédié.

**Section (a) — Variables d'en-tête**
2. L'admin paramètre les **variables affichées dans l'en-tête overlay** parmi : nom, prénom, login, poste, workstationGroup, type de session (ex. « examen »). La sélection est persistée (stockage à définir — cf. Dev Notes) et lue par la **composition overlay** (défaut Broadcast). ⚠️ NET-NEW : la composition overlay est aujourd'hui figée.
3. Le payload overlay produit reste **conforme au contrat agent** (pas de rupture du golden/hash sans bump explicite) — vérifier l'impact sur la composition et le contrat. ([Source: contrat/golden agent — voir tests ContractV1/golden])

**Section (b) — Messages conditionnels**
4. L'admin définit des **règles « SI condition ALORS message[type] »** (ex. *si quota dépassé → message warning*). Modèle de règles persisté + évaluation au calcul du state (émission d'un signal/overlay conditionnel quand la condition est vraie pour le contexte). ⚠️ NET-NEW le plus lourd — nécessite une **source de données** (quotas…) ; si la source n'est pas disponible, livrer le cadre de règles avec les conditions réalisables et **documenter les conditions différées**.
5. Les types de message respectent la sévérité overlay existante (info/warning/…) cohérente avec `OverlaySignal`. ([Source: app/Models/OverlaySignal.php])

**Section (c) — Message broadcast à expiration**
6. L'admin poste un **message broadcast** (tous les postes) avec **expiration** (`expires_at`), via l'existant `OverlayService::postSignal()` ciblé broadcast (`workstation_uuid/workstation_group_id/user_login` NULL). ([Source: app/Services/Overlay/OverlayService.php:115-141])

**Migration des messages ciblés**
7. L'édition **ciblée** des messages overlay (aujourd'hui sur `/app/parc-settings/overlay-messages`) est **déplacée vers un onglet de la page d'un workstationGroup** (édition par-groupe). L'ancienne page est retirée/redirigée ; le ciblage user/poste/groupe reste fonctionnel via `OverlayService::postSignal()`. ([Source: resources/views/pages/parc-settings/overlay-messages/index.blade.php:21-147 ; resources/views/pages/parc/groups/_partials/ (modèle d'onglet WG)])

**Non-régression**
8. `OverlayStateProvider`/`OverlayMachineStateProvider` continuent d'émettre correctement le broadcast existant ; tests de non-régression overlay + compilation Broadcast.

## Tasks / Subtasks

- [ ] **T1 — Onglet Overlay** (AC: 1) — partial SFC + branchement dans la page 27.17, gate `server.admin`
- [ ] **T2 — Variables d'en-tête** (AC: 2,3) — stockage de la sélection + lecture par la composition overlay + preuve d'invariance contrat (ou bump assumé)
- [ ] **T3 — Messages conditionnels** (AC: 4,5) — modèle de règles + évaluation au state ; cadrer la/les source(s) (quotas) ; documenter les conditions différées si source absente
- [ ] **T4 — Message broadcast à expiration** (AC: 6) — réutiliser `OverlayService::postSignal` (broadcast)
- [ ] **T5 — Migration overlay-messages → onglet WG** (AC: 7) — nouvel onglet sur la page workstationGroup, retrait/redirection de l'ancienne page
- [ ] **T6 — Tests** (AC: 8) — non-régression providers overlay + compilation ; tests page/onglet (accès server.admin) ; tests règles conditionnelles. Exécution HÔTE (php+sqlite+vendor).

## Dev Notes

### Anchors
- `OverlayStateProvider` (session, Broadcast) : [Source: app/Services/Agent/Providers/OverlayStateProvider.php:42-160]
- `OverlayMachineStateProvider` (machine) : [Source: app/Services/Agent/Providers/OverlayMachineStateProvider.php]
- `OverlayService::postSignal()` : [Source: app/Services/Overlay/OverlayService.php:115-141]
- `OverlaySignal` (kind/severity/title/text/workstation_uuid/workstation_group_id/user_login/expires_at) : [Source: app/Models/OverlaySignal.php:28-104]
- Page ciblée actuelle à migrer : [Source: resources/views/pages/parc-settings/overlay-messages/index.blade.php:21-147]
- Modèle d'onglet WG (où migrer le ciblé) : [Source: resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php]

### Points d'attention
- **Variables d'en-tête = composition overlay** : aujourd'hui figée ; identifier le point de composition (côté serveur, cf. OverlayMachineStateProvider/préchargement identité machine 27.10) et y injecter la sélection de variables. Attention au **contrat agent / golden / FROZEN hash** : si le payload overlay change de forme, c'est un bump explicite (sinon prouver l'invariance).
- **Messages conditionnels = le gros morceau** : modèle de règles (condition + type + texte), source de données de la condition (quotas — d'où viennent-ils ? à cadrer), évaluation par contexte au calcul du state. Si la source quotas n'existe pas encore, livrer le cadre + conditions réalisables et différer le reste (documenté).
- **Migration overlay-messages** : ne PAS casser le ciblage existant (user/poste/groupe) ; juste déplacer la surface d'édition vers l'onglet WG. Vérifier les liens/menus.
- **Précédence inchangée** : l'overlay par défaut = Broadcast ; un signal ciblé (maille plus spécifique) override. Ne pas toucher `StateCompiler`.

### Conventions projet
- Filesystem router + Livewire SFC + onglets `#[Url] public string $tab` + `WithToasts` + modale réutilisable (idem 27.17). Tests sur HÔTE.

### References
- [Source: app/Services/Agent/StateCompiler.php:383-393] — maille Broadcast / specificity
- [Source: app/Services/Agent/Providers/OverlayStateProvider.php:42-160]
- [Source: app/Services/Overlay/OverlayService.php:32-150]
- [Source: _bmad-output/implementation-artifacts/27-17-page-config-defaut-parc.md] — story mère (page + onglets)

## Dev Agent Record

### Agent Model Used

(reco : opus — net-new lourd, contrat overlay, règles conditionnelles)

### Debug Log References

### Completion Notes List

### File List
