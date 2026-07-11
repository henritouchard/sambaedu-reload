---
stepsCompleted: [scoping]
inputDocuments:
  - planning-artifacts/epics-mode-examen.md
  - planning-artifacts/epics-capacites-v2.md
  - docs/agent/contract-v1.md
---
# Application immédiate des réglages - Epic Breakdown (Epic 43)

## Overview

Supprimer le « double logon » : aujourd'hui, une modification serveur détectée au logon est bien
écrite par le compagnon (HKCU), mais **après** le démarrage d'Explorer — qui lit ses policies au
démarrage. L'utilisateur doit se déloguer/reloguer pour voir l'effet. L'epic dote SE5 d'un
**rafraîchissement post-apply déclaratif** (le geste minimal qui rend un réglage effectif en
session courante) et d'une **cadence de propagation** adaptée aux postes déjà allumés.

Constats d'analyse (2026-07-10) — ancrés code :

- **La course au logon est structurelle** : Explorer démarre et lit ses policies HKCU avant que le
  compagnon ne converge (session-fetch at-logon → cache per-SID → `WaitForCache` jusqu'à ~60 s →
  écriture HKCU). Toute policy shell posée par le compagnon rate le premier logon.
- **Le patron du fix existe déjà en embryon** : hook optionnel `registryNotifier.NotifyShellChanged()`
  → `SHChangeNotify(SHCNE_ASSOCCHANGED)` après écriture HKCU **effective**
  (`agent/shared/handler_registry.go:404-408`, `agent/windows/handler_registry_windows.go:299-326`),
  gated sur `changed` — jamais au régime stable. Le wallpaper fait de même via
  `SystemParametersInfoW(SPI_SETDESKWALLPAPER)`. Il manque les gestes plus forts
  (`WM_SETTINGCHANGE "Policy"`, restart Explorer) et leur pilotage déclaratif.
- **Aucun hint d'application n'est modélisé** : ni `capabilities`, ni `capability_projections`, ni
  le contrat n'ont de champ `refresh`/`apply_mode`. La sémantique « effet au prochain logon » est
  implicite et invisible pour l'admin.
- **Le transport borne tout** : 100 % polling, TTL **global** 3600 s (`config/agent.php:40`,
  `StateCompiler.php:74`), réveil (`RequestWake`) au logon uniquement. Un poste allumé avec session
  ouverte peut mettre 1 h à *recevoir* une bascule. Or l'agent honore **déjà** un TTL par réponse
  (`EffectiveInterval`, `agent/shared/loop.go:582-596`, clampé 60 s..86400 s) : le levier de cadence
  est serveur-only.
- **gpupdate/GPO hors-jeu** : SE5 écrit la registry en direct (pas de registry.pol) ; le broadcast
  `WM_SETTINGCHANGE` lParam `"Policy"` émis par le moteur GPO après application est en revanche
  reproductible par le compagnon, et beaucoup de composants (dont Explorer) re-lisent leurs clés
  `Policies` sur ce signal.

Périmètre = SE5 uniquement. **Consommateur immédiat : Epic 41 (mode examen)** — `restrict_run`
(41.2, HKCU) passe d'« effet au logon suivant » à « effectif en session courante en ~2 s » ;
`internet_access` (firewall, machine) est déjà live. Le lot Explorer existant en profite aussi.

## Requirements Inventory

### Functional Requirements
- **FR-A1** — Après une passe de convergence où des items ont **effectivement changé**, le compagnon
  exécute **une fois** le geste de rafraîchissement minimal requis pour rendre les réglages
  effectifs en session courante (échelle : notification shell → broadcast policy → restart Explorer).
- **FR-A2** — Le geste requis est **déclaré par projection** (champ `refresh` dans le `spec`),
  vocabulaire **fermé** validé à l'authoring ; défaut absent = « effet au prochain logon » (aucun
  changement de comportement pour l'existant).
- **FR-A3** — L'admin **voit** la temporalité d'effet d'une capacité dans l'UI (« immédiat » /
  « après rafraîchissement du bureau » / « au prochain logon »).
- **FR-A4** — La cadence de check-in est **pilotée par le serveur** (TTL par réponse), abaissable
  globalement et par poste, pour borner la latence de propagation d'une bascule (examen notamment).

### NonFunctional Requirements
- **NFR-A1** — **Jamais de session tuée, jamais d'appli fermée** : le geste le plus fort est le
  restart d'`explorer.exe` (shell seul, ~2 s, applis intactes). Le logout n'est jamais un geste.
- **NFR-A2** — **Idempotence** : aucun geste au régime stable (gate sur `changed`, patron
  `shellRefresh` existant) ; au plus **un** geste (le plus fort requis) par passe, agrégé en fin de
  `RunPass`, pas par item.
- **NFR-A3** — **Vocabulaire fermé, pas de scripts** : gestes typés implémentés dans l'agent
  (cohérent avec la doctrine mécanismes typés) ; pas de `service:<nom>` arbitraire — liste blanche
  si le besoin apparaît (hors V1).
- **NFR-A4** — **Rollout additif** : agent publié AVANT les seeders (gate Epic 35) ; un agent
  antérieur ignore le champ `refresh` du payload sans erreur ; le hint entrant dans le `hash` des
  items, un drift ponctuel de re-application à la première compilation est attendu et bénin.
- **NFR-A5** — La fenêtre résiduelle au logon (~10-60 s entre démarrage du shell et convergence du
  compagnon) est **assumée** en V1 ; l'apply synchrone pré-shell (équivalent GPO synchrone) est
  explicitement hors-scope.

### FR Coverage Map
- FR-A1 : Story 43.1 (échelle de gestes compagnon)
- FR-A2 : Story 43.1 (lecture payload) + 43.2 (spec + AuthoringGuard + recopie provider)
- FR-A3 : Story 43.2 (affichage UI catalogue/formulaires)
- FR-A4 : Story 43.3 (TTL dynamique)
- NFR-A1..A5 : transverses, ancrées 43.1→43.3

## Epic 43: Application immédiate des réglages

Rendre les réglages effectifs en session courante sans logout : échelle de rafraîchissement dans le
compagnon pilotée par un hint `refresh` déclaré par projection, plus cadence de check-in pilotée
serveur. Ordre recommandé : **43.1 → 43.2**, 43.3 indépendante (parallélisable).
**FRs covered:** FR-A1..FR-A4, NFR-A1..NFR-A5

---

### Story 43.1: Agent — échelle de rafraîchissement du compagnon (hint `refresh` du payload)

**Intention.** Doter le compagnon des gestes de rafraîchissement et de leur orchestration : chaque
item de payload peut porter `refresh` ∈ `shell_notify | policy_broadcast | explorer_restart` ; en
fin de passe, si des items ont changé, le compagnon exécute **une fois** le geste le plus fort
requis par les items changés.

**AC-skeleton (à figer au create-story) :**
- Gestes Windows (FFI `NewLazySystemDLL`, style wallpaper/registry, pas de cgo) :
  `shell_notify` = `SHChangeNotify` (existant, à raccorder) ; `policy_broadcast` =
  `SendMessageTimeout(HWND_BROADCAST, WM_SETTINGCHANGE, 0, "Policy", SMTO_ABORTIFHUNG, timeout)` ;
  `explorer_restart` = terminer + relancer `explorer.exe` **dans la session du compagnon**
  (droits user, jamais SYSTEM).
- Agrégation **centralisée en fin de `Companion.RunPass`** (`agent/shared/companion.go`) : les
  handlers remontent le besoin de refresh des items **effectivement changés** ; un seul geste par
  passe (le plus fort) ; zéro geste si passe stable (NFR-A2). Le moteur (`engine.go`) reste intouché.
- Le fan-out HKU côté SYSTEM ne déclenche **jamais** de geste (session 0, piège existant n°9) —
  périmètre compagnon uniquement.
- Champ `refresh` inconnu ou absent dans le payload → comportement actuel (aucun geste au-delà de
  l'existant). Additif sûr.
- Migration du `SHChangeNotify` actuel (post-apply registry HKCU inconditionnel) vers l'échelle,
  sans régression du lot vues Explorer (Hidden, HideFileExt).
- Tests portables hôte (fake ops enregistrant les gestes) : agrégation, gate `changed`, un-seul-geste.
- **Bump `agent/shared/version.go` + publication de la release AVANT tout seeder 43.2** (gate Epic 35).
- **Tâche** : valider au lab l'efficacité réelle de `policy_broadcast` sur `RestrictRun`/`DisallowRun`
  (si suffisant, `explorer_restart` devient l'exception, pas la règle) — trancher au create-story.

**Dépendances.** Aucune amont. Bloquant pour 43.2. **Reco dev** : fable (agent Go, cf.
`feedback_epic23_model_fable5`) — à confirmer au create-story.

---

### Story 43.2: Serveur — hint `refresh` déclaré par projection + affichage temporalité d'effet

**Intention.** Déclarer le geste dans le `spec` JSON des projections (zéro migration de schéma),
le valider à l'authoring, le recopier dans le payload des items compilés, l'afficher à l'admin,
et retrofitter les capacités qui en bénéficient.

**AC-skeleton (à figer au create-story) :**
- Convention `spec.refresh` (projections `registry`/`registry_list`) : vocabulaire fermé
  `shell_notify | policy_broadcast | explorer_restart`, validé par l'`AuthoringGuard` du mécanisme ;
  valeur inconnue = rejet à l'authoring, jamais au runtime.
- `AbstractCapabilityStateProvider` recopie le hint dans le payload des items émis (portées
  session/machine_user uniquement — jamais sur les items machine/HKU). Golden files PHP↔Go mis à
  jour ; le `hash` changeant, documenter le drift ponctuel de re-application (NFR-A4).
- Retrofit du **lot Explorer** existant + capacités `Policies\Explorer` (dont `blocked_executables`)
  avec le geste validé en 43.1 ; les capacités à effet naturellement live (firewall, HKLM lu à
  chaud) restent sans hint.
- **UI** : le catalogue (`/admin/settings/capabilities`) et les formulaires d'assignation affichent
  la temporalité d'effet dérivée du hint (« immédiat » / « après rafraîchissement du bureau » /
  « au prochain logon ») — FR-A3, désamorce le « j'ai appliqué, rien ne se passe ».
- Prêt pour 41.2 : la story `restrict_run` n'a plus qu'à poser son hint dans son seed.
- **Tâche** : formulation UI exacte des trois temporalités (wording court, pas de jargon) — trancher
  au create-story.

**Dépendances.** Amont : 43.1 **publiée** (ordre de rollout NFR-A4). **Reco dev** : sonnet
(provider + seeders + UI générique) — à confirmer au create-story.

---

### Story 43.3: Serveur — cadence de propagation pilotée (ttl_seconds dynamique)

**Intention.** Exploiter le mécanisme agent existant (`EffectiveInterval` honore le `ttl_seconds`
de chaque réponse `/state`) pour borner la latence de propagation : TTL calculé **par poste** dans
l'enveloppe, et abaissement raisonné du défaut global.

**AC-skeleton (à figer au create-story) :**
- `StateCompiler::compile()` calcule le `ttl_seconds` depuis le `TargetContext` (aujourd'hui
  constante `config('agent.ttl_seconds')`, `StateCompiler.php:74`) : TTL court (60-120 s) pour un
  poste dont le parc est en **bascule sensible** (flag examen 41.3 en tête), défaut sinon.
- Abaissement du défaut global envisagé (ex. 300-600 s) : mesurer la charge réelle des 304 (ETag)
  avant de trancher — un GET conditionnel 304 est quasi gratuit, le POST /report reste cadencé.
- **Limite documentée honnêtement** : le TTL court n'atteint l'agent qu'à son **prochain** check-in
  — la première bascule reste bornée par l'ANCIEN TTL. C'est le défaut global qui borne le pire
  cas ; le TTL court sert les bascules **suivantes** (flag/déflag examen dans la journée) et le
  retour à la normale. Le vrai temps réel (canal wake serveur→agent) est explicitement hors-scope,
  à ouvrir seulement si le besoin résiduel le justifie.
- Zéro code agent, zéro bump (mécanisme déjà livré, clamp 60 s..86400 s côté agent).
- Tests : TTL par contexte, clamp, non-régression enveloppe (ETag/hash insensibles au TTL — vérifier
  que `ttl_seconds` n'entre pas dans le `StateHasher`, sinon un TTL dynamique invaliderait le cache
  à chaque bascule).
- **Tâche** : critère exact du TTL court (flag examen seul en V1 vs notion générique « bascule en
  attente ») — éviter la sur-conception, trancher au create-story.

**Dépendances.** Aucune (parallélisable avec 43.1/43.2). Consommée par 41.3/41.4 pour le badge
« salle en examen » réactif. **Reco dev** : sonnet (compiler + config + tests) — à confirmer au
create-story.

## Notes de coordination / évolutions (hors V1)

- **Epic 41 (mode examen)** : 41.2 (`restrict_run`) pose son hint `explorer_restart` (ou
  `policy_broadcast` si validé au lab) dans son seed — l'FR-E3 « au logon suivant » devient
  « en session courante » pour les sessions déjà ouvertes. La fenêtre au logon (~10-60 s) reste
  assumée (NFR-A5, cohérente avec NFR-E1 « contournable assumé »).
- **`service:<nom>` whitelisté** (restart de service après réglage HKLM, ex. lockscreen) : geste
  machine côté SYSTEM, hors V1 — à ouvrir si un cas concret l'exige.
- **Apply synchrone pré-shell** (équivalent traitement GPO synchrone au logon) : seul moyen de
  fermer la fenêtre résiduelle au logon ; lourd (retarder le shell), à n'ouvrir que si le besoin
  anti-triche V2 (AppLocker) ne la couvre pas déjà.
- **Fermeture des applis hors liste à l'entrée en examen** : le restart Explorer contraint les
  nouveaux lancements, pas les process déjà ouverts — territoire V2 anti-triche (AppLocker/WDAC),
  pas un geste de rafraîchissement.
