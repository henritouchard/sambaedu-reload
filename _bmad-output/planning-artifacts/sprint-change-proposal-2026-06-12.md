---
type: sprint-change-proposal
date: 2026-06-12
author: SM (correct-course) + Henri
scope: 'Agent desired-state — bascule PowerShell → Go (Epic 24)'
trigger: 'Trou de séquencement — aucune story ne porte le rewrite de l'agent prototype PowerShell vers le binaire Go décidé'
status: approved  # validé par Henri 2026-06-12, appliqué le jour même (epics, architecture, sprint-status, backlog)
related:
  - '_bmad-output/planning-artifacts/epics-agent-desired-state.md'
  - '_bmad-output/planning-artifacts/architecture-agent-desired-state.md'
  - '_bmad-output/implementation-artifacts/sprint-status.yaml'
  - 'memory/project_agent_runtime_go.md'
  - 'memory/project_no_legacy_transition_state.md'
---

# Sprint Change Proposal — Bascule PowerShell → Go de l'agent (Epic 24)

## Section 1 — Résumé du problème (Issue Summary)

**Déclencheur.** Pas une story en échec : un **trou de séquencement** révélé à froid lors d'une revue de cadrage produit.

Le découpage en stories de l'agent desired-state (`epics-agent-desired-state.md`) a été figé avec la techno de l'agent côté poste **explicitement « décision reportée, gate = PoC P2 »**. La story 24.2 énonce : *« un démarrage PowerShell jetable est admis, le choix définitif étant requis avant l'Epic 25 »*.

La décision de techno est tombée **le même jour** que le découpage (2026-06-11, brainstorm techno → mémoire `project_agent_runtime_go.md`) : **l'agent est écrit en Go**. Cette décision **n'a jamais été réinjectée dans le découpage**. Conséquence :

- Tout l'Epic 24 prototype l'agent en **PowerShell jetable** (24.2 squelette, 24.3 compagnon, 24.4 handlers — les trois en statut `review`).
- L'Epic 25 (story 25.1) **présuppose un binaire signé existant** dans `storage/agent/releases/`.
- **Aucune story ne porte la réécriture PowerShell → Go.** Le binaire Go est présupposé exister sans être produit par aucune story — un angle mort entre la fin de l'Epic 24 et le début de l'Epic 25.

**Évidence.**
- `epics-agent-desired-state.md` story 24.2 : *« un démarrage PowerShell jetable est admis »*.
- `epics-agent-desired-state.md` story 25.1 : *« un binaire signé déposé dans storage/agent/releases/ »* (présupposé, jamais produit).
- `sprint-status.yaml` : `24-2`, `24-3`, `24-4` tous en `review` (prototype PS écrit de bout en bout) ; aucune story de rewrite Go.
- Mémoire `project_agent_runtime_go.md` (2026-06-11) : Go acté, l'agent doit reproduire `StateHasher` à l'identique et être testé contre les golden files de 23.1.
- Décision Henri (2026-06-12) : le binaire Go doit **tout embarquer** (squelette + compagnon + handlers wallpaper/overlay = toute la boucle validée), pas un portage fragmentaire.

**Type d'issue.** *Planning gap* (décision prise après le découpage, jamais répercutée). Ni limitation technique, ni pivot stratégique, ni malentendu d'exigence.

---

## Section 2 — Analyse d'impact (Impact Analysis)

### Impact epics

| Epic | Impact | Verdict |
|------|--------|---------|
| **23** (contrat, token, StateCompiler) | Aucun — `done`. Le contrat v1 + golden files restent la cible de conformité du binaire Go. | ✅ inchangé |
| **24** (boucle lab) | **Cœur de l'impact.** Le prototype PS (24.2/24.3/24.4) a rempli sa fonction de dérisquage (boucle réseau + modèle 2-process validés). Mais son code n'est **pas le livrable de production** : il sera réécrit en Go. Stories à requalifier + ajouter. | ⚠️ restructuré |
| **25** (gestion de flotte) | 25.1 présupposait le binaire signé : dépendance **désormais satisfaite en amont** par les nouvelles stories Go de l'Epic 24. Aucune réécriture de 25.x — juste une note de dépendance. | ✅ débloqué |
| **26** (WorkstationEnvironment) | Aucun (parallélisable, consomme le contrat). | ✅ inchangé |
| **27** (parité du simple au dur) | Aucun (les futurs handlers s'écriront directement en Go — bénéfice indirect : plus de double-écriture PS→Go à l'avenir). | ✅ inchangé |

### Impact stories (détail Epic 24)

- **24.1** (POST /report, serveur Laravel) — `done`, **inchangé** (indépendant de la techno agent).
- **24.2** (squelette PS, service SYSTEM) — `review` → **superseded** : spike de dérisquage, remplacé par la story Go 24.5.
- **24.3** (compagnon PS) — `review` → **superseded** : remplacé par la story Go 24.6.
- **24.4** (handlers PS wallpaper/overlay) — `review` → **superseded** : remplacé par la story Go 24.6.
- **24.5 (UI conformité, doc)** — renumérotée **24.7** : story serveur (Livewire), contenu inchangé, reste le **gate palier 1** (dernier maillon « → UI » de la démo).
- **24.5 (NEW)** — Agent Go : core de convergence + service SYSTEM + build signé.
- **24.6 (NEW)** — Agent Go : compagnon de session + handlers wallpaper/overlay + parité démo.

### Conflits d'artefacts

- **Architecture** (`architecture-agent-desired-state.md`) : pas un conflit, une **résolution**. Le step-03 laisse la techno « reportée, gate PoC P2 » et liste déjà le cahier des **7 contraintes** — il faut **acter Go** par un addendum (sans réécrire l'historique). Les 7 contraintes deviennent les AC du build.
- **PRD / brief** : MVP (palier 1) **non affecté** — il s'arrête à la démo lab, désormais jouée sur le binaire Go réel (plus honnête : frontière de confiance + signature vraies). Le rewrite est un prérequis du **palier 2** (hors-lab), pas du MVP.
- **sprint-status.yaml** : requalification 24.2/24.3/24.4 + ajout 24.5/24.6 Go + renumérotation UI → 24.7.

### Impact technique

- Code PowerShell `agent/windows/SambaEduAgent.ps1`, `agent/windows/SessionCompanion.ps1`, `agent/shared/ConvergenceEngine.ps1`, `agent/windows/handlers/*` : **retirés dès que leur équivalent Go passe les tests** (décision Henri — pas d'état transitoire, cf. `project_no_legacy_transition_state`). L'historique git conserve la trace.
- Toolchain neuf : `agent/build/` produit un binaire Go statique unique, cross-compilé Windows, **signé Authenticode** (CA interne d'abord). Aucune dépendance runtime exotique (NFR6).
- `StateHasher` Go : reproduction à l'identique de l'algorithme PHP de 23.1 (ksort SORT_STRING récursif, NFC, zéro float, `generated_at` exclu), validé contre les golden files `tests/Fixtures/Agent/` — **tests croisés** serveur/agent.

---

## Section 3 — Approche recommandée (Recommended Approach)

**Chemin retenu : Direct Adjustment (ajout + requalification de stories), Plan 2 « bascule Go maintenant ».**

Options évaluées et écartées :
- **Rollback** : non — rien à annuler, le prototype PS a livré sa valeur de dérisquage.
- **Revue MVP** : non — le MVP (démo palier 1) reste atteignable, sur un meilleur artefact.
- **Plan 1 (finir le PowerShell d'abord)** : écarté — les 24.2/24.3/24.4 étant déjà en `review`, le PS a déjà validé la boucle ; committer ce chemin comme livrable permanent + bâtir l'UI dessus = double-écriture des handlers (la partie la moins transférable PS→Go) et démo non représentative de la frontière de confiance (script en clair). Contraire à « pas d'état transitoire ».

**Justification de Plan 2 :**
1. Le risque que le PS devait couvrir (boucle réseau + 2-process login non bloquant) est **déjà couvert** (24.2/24.3 en review).
2. Les handlers (24.4) sont le maillon **le moins transférable** PS→Go (Win32 `SystemParametersInfo` vs FFI Go) — les écrire deux fois n'apprend rien.
3. La démo palier 1 sur le binaire Go réel est **plus honnête** : signature de code + ACL SYSTEM (NFR5/NFR6) effectives, pas simulées.
4. Aligné avec la règle projet **« pas d'état transitoire »** (`project_no_legacy_transition_state`) : une seule implémentation au repo.

**Coût assumé :** la démo d'évangélisation arrive après la mise en place du build Go signé. Mitigé : l'adhésion du mainteneur est déjà acquise (Epic 23 validé e2e, mémoire `project_epic23_e2e_validated`).

**Effort :** élevé (réécriture complète multi-composant). **Risque :** moyen — comportement déjà prouvé en PS + golden files 23.1 comme filet de conformité. **Timeline :** absorbe la fin de l'Epic 24 (gate palier 1 décalé du temps du toolchain Go) ; Epic 25 **débloqué** (binaire produit en amont).

---

## Section 4 — Propositions de changement détaillées (Detailed Change Proposals)

### 4.A — Nouvelles stories (epics-agent-desired-state.md, Epic 24)

#### Story 24.5 (NEW) : Agent Go — core de convergence, service SYSTEM, build signé

> En tant que mainteneur SambaEdu,
> je veux le cœur de l'agent réécrit en Go (boucle, hash, cache, résilience, build signé) tournant en service SYSTEM,
> afin de disposer de l'artefact de production réel, signable et déployable, en lieu et place du prototype PowerShell.

**Acceptance Criteria :**

**Given** le repo `agent/`
**Then** le code Go vit sous `agent/` (`shared/` = boucle test/apply/report + parsing du contrat + `StateHasher` Go ; `windows/` = service SYSTEM), jamais dans `app/`
**And** les `.ps1` de 24.2 (squelette, compagnon) sont **retirés** une fois leur équivalent Go vert — aucune double implémentation au repo (l'historique git garde la trace).

**Given** un poste de lab enrôlé (token de 23.3)
**When** le service SYSTEM Go démarre (boot) puis à chaque timer (60 min + jitter ±10 %, configurable)
**Then** il appelle `GET /state` avec `If-None-Match`, met en cache local le dernier état connu (fichiers sous ACL SYSTEM), envoie son rapport (portée machine).

**Given** `StateHasher`
**Then** l'agent Go reproduit l'algorithme de 23.1 **à l'identique** (ksort SORT_STRING récursif, NFC, zéro float, `generated_at` exclu) et le valide contre les golden files `tests/Fixtures/Agent/` — tests croisés serveur/agent ; l'agent compare des hashes opaques, il ne recalcule jamais depuis sa propre sérialisation.

**Given** le serveur injoignable
**Then** l'agent fonctionne sur son dernier état en cache, backoff exponentiel plafonné au timer.

**Given** un 401 pendant une rotation
**Then** tentative avec l'ancien token ; sinon arrêt + log local (jamais de re-enrôlement silencieux).
**Given** un 403 quarantaine
**Then** l'agent cesse de converger mais poursuit des check-ins légers.

**Given** le build
**Then** `agent/build/` produit un **binaire Go statique unique**, cross-compilé Windows, **signé Authenticode** (CA interne, racine déployée par l'install), zéro dépendance runtime exotique (NFR6)
**And** `agent/README.md` documente la décision **Go** contre le cahier des **7 contraintes** (service SYSTEM + compagnon, artefact signable, ACL SYSTEM, auto-update fiable, cœur partageable, zéro dépendance exotique, empreinte discrète) — gate techno (ex-PoC P2) **résolu**.

---

#### Story 24.6 (NEW) : Agent Go — compagnon de session, handlers wallpaper/overlay, parité démo

> En tant qu'admin d'établissement (et prof/élève côté session),
> je veux le compagnon de session et les handlers wallpaper/overlay en Go, convergents et idempotents,
> afin que la boucle complète tourne sur le binaire de production et que la démo palier 1 soit jouée pour de vrai.

**Acceptance Criteria :**

**Given** un logon utilisateur
**When** le compagnon de session Go démarre
**Then** l'ouverture de session n'attend RIEN du réseau (convergence `session`/`machine_user` asynchrone après ouverture) ; le compagnon tourne aux droits de la session (pas SYSTEM) et ne peut pas modifier les fichiers de l'agent (frontière de confiance) ; le `.ps1` du compagnon de 24.3 est retiré une fois ce handler vert.

**Given** l'état cible contient un item `wallpaper` (biblio d'assets, maille résolue)
**When** la boucle exécute `test` puis `apply` si écart
**Then** le fond d'écran correspond à l'asset cible (`SystemParametersInfo` via FFI Win32, ou shell-out PowerShell documenté si un cas le justifie), `apply` idempotent, statut rapporté.

**Given** l'item `overlay`
**When** le handler s'exécute
**Then** l'agent Go écrit `overlay.json` local (il DEVIENT le fetch du POC) — Rainmeter/Conky inchangés, l'overlay affiche identité user + parc.

**Given** un item en mode `default` dont l'état réel a été modifié par un humain (réel ≠ cible ∧ dernier-appliqué = cible)
**Then** le handler ne réapplique PAS et rapporte `drifted_allowed` (persistance du dernier état appliqué par item, contrat 23.1).

**Given** un handler qui échoue
**Then** statut `error` + détail rapportés, les autres handlers et le rapport continuent (isolation), exécution séquentielle dans l'ordre du payload serveur.

**Given** l'agent Go complet (24.5 + 24.6)
**Then** la convergence est démontrable de bout en bout (changer le wallpaper d'un parc dans l'UI → le poste de lab converge → le rapport remonte, vérifiable curl/jq iso-Epic 23) — le bouclage visuel UI complet est scellé par 24.7 (gate palier 1).

---

### 4.B — Story renumérotée (epics-agent-desired-state.md)

**Story 24.5 « Conformité visible » → renumérotée 24.7.** Contenu **inchangé** (UI Livewire conformité dans les pages parc + bouton « forcer la synchro » + critère de complétude = démo live répétable). Reste le **gate palier 1** (dernier maillon « → UI »). Seul le numéro change : 24.5 → 24.7.

### 4.C — Ajustements de texte (epics-agent-desired-state.md)

**Story 24.2 — phrase de décision techno**

```
OLD:
Then le choix (binaire autonome, .NET, PowerShell…) est documenté dans
`agent/README.md` contre le cahier des charges des 7 contraintes — un
démarrage PowerShell jetable est admis, le choix définitif étant requis
avant l'Epic 25 (premier déploiement hors lab).

NEW:
[Story 24.2 requalifiée — spike de dérisquage PowerShell, superseded par 24.5
(Go). Le démarrage PowerShell a validé la boucle réseau ; le choix définitif
est acté : **Go** (mémoire project_agent_runtime_go, 2026-06-11), implémenté
en 24.5/24.6. Le `.ps1` est retiré à la bascule Go.]
```

**Epic 24 — intro (mention de la techno)** : ajouter une note de cadrage :

```
NEW (note ajoutée sous l'intro de l'Epic 24):
> **Note bascule Go (course-correction 2026-06-12)** : le prototype PowerShell
> (24.2/24.3/24.4, superseded) a validé la boucle et le modèle 2-process.
> L'agent de production est en **Go** : core+service SYSTEM (24.5), compagnon+
> handlers (24.6). La démo palier 1 (24.7) est jouée sur le binaire Go signé —
> frontière de confiance et signature réelles. Aucune double implémentation
> conservée (pas d'état transitoire).
```

**Story 25.1 — note de dépendance** :

```
NEW (note ajoutée à 25.1):
> **Dépendance amont** : le binaire signé déposé dans storage/agent/releases/
> est produit par les stories 24.5 (build signé Authenticode) + 24.6 (binaire
> complet). 25.1 le distribue, ne le crée pas.
```

### 4.D — Addendum architecture (architecture-agent-desired-state.md)

Ajouter, sous la section *« Côté client — techno agent : décision d'implémentation REPORTÉE »*, un addendum daté (sans réécrire l'historique) :

```
NEW (addendum):
> **Addendum 2026-06-12 — gate techno résolu : Go.** La décision reportée
> (PoC P2) est tranchée : l'agent est écrit en **Go** (binaire statique unique,
> cross-compile Windows/Linux, signé Authenticode). Rationale : Windows iso-legacy
> = Win32 plat (registre + SystemParametersInfo + spawn process) où Go n'a aucun
> handicap ; mainteneurs non-devs = un seul binaire lisible ; le vrai argument =
> binaire sans runtime sur parc hétérogène non managé. Réévaluer Rust uniquement
> si l'Epic 27 entre dans COM/WinRT (IShellLink, Task Scheduler COM, PackageManager
> WinRT) — échappatoire intermédiaire = shell-out PowerShell depuis l'agent Go.
> Implémenté en stories 24.5/24.6. Réf. mémoire project_agent_runtime_go.
```

### 4.E — FR Coverage Map (epics-agent-desired-state.md) — précisions

- FR17 (service SYSTEM + compagnon, cache) : Epic 24 → **24.5 (SYSTEM/Go) + 24.6 (compagnon/Go)**
- FR18 (boucle générique) : **24.5 (moteur Go)**
- FR19 (mode default → drifted_allowed) : **24.6 (Go)**
- FR20 (handlers wallpaper + overlay) : **24.6 (Go)**
- FR22/FR23 (résilience / cadence) : **24.5 (Go)**

---

## Section 5 — Handoff d'implémentation (Implementation Handoff)

**Classification du changement : Moderate** — réorganisation de backlog (requalification + ajout + renumérotation de stories), pas de replan fondamental, pas de changement d'architecture (la techno était déjà décidée hors-doc).

**Mise à jour `sprint-status.yaml` (development_status) :**

```yaml
  epic-24: in-progress
  24-1-post-report-ingestion-stockage-rapports: done            # inchangé
  24-2-agent-squelette-windows-service-checkin-cache-build: superseded   # PS spike → Go 24-5 ; .ps1 retiré à la bascule
  24-3-compagnon-session-portee-user-login-jamais-bloquant: superseded   # PS spike → Go 24-6 ; .ps1 retiré
  24-4-handlers-wallpaper-overlay-convergence-reelle: superseded         # PS spike → Go 24-6 ; .ps1/handlers retirés
  24-5-agent-go-core-service-systeme-build-signe: backlog        # NEW (ready-for-dev après création story)
  24-6-agent-go-compagnon-handlers-parite-demo: backlog          # NEW
  24-7-conformite-ui-parc-forcer-synchro: backlog                # ex-24.5 doc (UI serveur) = gate palier 1
```

**Recettes / responsabilités :**
- **SM / orchestrateur (Henri)** : valider ce proposal ; créer les fichiers de story 24.5 et 24.6 (via `bmad-create-story` ou dev-cycle) ; appliquer la requalification 24.2/24.3/24.4 + renumérotation 24.7.
- **Dev (reco fable, décision projet Epic 24)** : implémenter 24.5 puis 24.6 (Go) ; retirer les `.ps1` à mesure que les équivalents Go passent les tests ; build signé dans `agent/build/`.
- **PM / Architecte** : acter l'addendum techno Go dans `architecture-agent-desired-state.md`.

**Critères de succès :**
- Binaire Go statique signé Authenticode produit par `agent/build/`, déployable.
- `StateHasher` Go validé contre les golden files 23.1 (tests croisés verts).
- Démo palier 1 répétable sur le binaire Go (UI → état → agent Go → rapport → UI).
- Aucun `.ps1` agent résiduel au repo (pas d'état transitoire).
- Epic 25 (25.1) consomme le binaire produit en amont — dépendance close.

**Hors-scope de ce proposal :** réécriture des stories 25.x (juste une note de dépendance) ; handlers palier 2 (Epic 27, écrits nativement en Go) ; agent Linux.
