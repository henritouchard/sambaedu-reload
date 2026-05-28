# Sprint Change Proposal — 2026-04-16

**Auteur :** John (PM), sous pilotage d'Henri
**Statut :** Validé
**Portée :** Retrait de l'Epic 10 "Supervision de Flotte controlHub/irundoo" + renumérotation

---

## Déclencheur

Constat d'Henri :

> L'Epic 10 "Supervision de Flotte controlHub/irundoo" est hors sujet pour SER. La supervision de flotte est un concern exclusif du controlHub (irundoo) et les hooks nécessaires côté SER sont déjà implémentés (MassActions pattern, `ControlHubTask`, API controlHub).

## Décisions

1. **Retrait intégral de l'Epic 10** (ex : "Supervision de Flotte controlHub/irundoo") du backlog SER.
2. **Suppression des FRs** associés : FR30, FR31, FR32, FR32b.
3. **Renumérotation en cascade** :
   - Epic List : Epic 11 → Epic 10, Epic 12 → Epic 11.
   - Sprint-status / backlog : idem (11→10, 12→11, stories `11-X`→`10-X`, `12-X`→`11-X`).
   - Sections détaillées d'`epics.md` (qui utilisaient déjà un offset historique différent) : Epic 9→retiré, Epic 10→Epic 9, Epic 11→Epic 10, stories `10.X`→`9.X`, `11.X`→`10.X`.

## Rationale

| Fact | Conséquence |
|---|---|
| Les trois stories Epic 10 (Dashboard, Navigation SSO, MassActions) visent une UI consommée par l'admin irundoo, pas par un responsable SER | Rien à livrer dans le SFC SER |
| Story 9.3 (MassActions) était marquée ✅ — l'infrastructure `ControlHubTask` existe déjà côté SER | Pas de dette technique SER |
| Story 9.2 (SSO) dépendait de Keycloak (Phase 2) — décision d'architecture côté controlHub | Pas de code SER bloquant à écrire maintenant |
| Story 9.1 (Dashboard) consomme l'API SER existante | Pas d'endpoint SER à ajouter |

## Fichiers modifiés

- `_bmad-output/planning-artifacts/epics.md` — suppression section FR30-32b, retrait Epic 10 de la liste, renumérotation Epic List + sections détaillées, mise à jour note module central (ligne 1149)
- `_bmad-output/planning-artifacts/prd.md` — suppression section "Supervision Multi-Instances (irundoo)" (FR30-32)
- `_bmad-output/planning-artifacts/architecture.md` — suppression row table domaines FR, suppression bullet FR31 "Fonctionnalités Nouvelles", ajout note de périmètre
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — suppression bloc `epic-10`, renumérotation `epic-11`/`epic-12` → `epic-10`/`epic-11`, mise à jour note module central + changelog `last_updated`
- `_bmad-output/backlog.html` — suppression bloc num 10, renumérotation num 11/12 → 10/11

## Harmonisation complète d'`epics.md`

À la demande d'Henri ("virer tout et refaire la numérotation pour invalider la divergence"), le document `epics.md` a été entièrement réaligné :

- **Ajout de la section détaillée Epic 3 : Système iPXE** (7 stories placeholders 3.1–3.7, acceptance criteria à produire au moment de la création de chaque story via `bmad-create-story`)
- **Renumérotation en cascade des sections détaillées** pour absorber l'ajout d'Epic 3 : Machines 3→4, Fichiers 4→5, Impression 5→6, Délégations 6→7, Réseau 7→8, Windows 8→9 + sous-stories 8.2.X→9.2.X, Intégrations Académiques 9→10, Établissements+GPEI 10→11
- **Resynchronisation des descriptions Epic List** pour Epic 10 (apps autorisées SER, FR39 uniquement) et Epic 11 (établissements, itinérants + GPEI dispatch irundoo, FR33–38)
- **Correction FR Coverage Map** : FR6 réassigné à Epic 11 (GPEI), FR3b corrigé Epic 6→Epic 7 (bug préexistant : délégations ≠ impression)
- **Ajout Story 9.5 détaillée** (parsing logs WPKG et affichage erreurs — était dans sprint-status mais absent des détails)
- **Mise à jour des renvois internes** : Story 4.2→5.2, 4.1→5.1, 3.2→4.2, 7.1→8.1, 8.4→9.4, "Epic 4"(ex-Fichiers)→"Epic 5", "Epic 9"(ex-controlHub cluster)→note hors scope SER

## Résultat

Une seule numérotation valide partout :
- `epics.md` Epic List = sections détaillées = `sprint-status.yaml` = `backlog.html` = fichiers d'implémentation
- Plus de décalage iPXE, plus de deux "Epic 10" coexistants

## Points d'attention résiduels

1. **Label historique `epic-8-2`** : les 6 stories de "Installation d'applications depuis le dépôt" conservent l'identifiant `8-2.X` bien qu'elles correspondent désormais logiquement à Story 9.2.X. Les fichiers d'implémentation (`_bmad-output/implementation-artifacts/8-2.X-*.md`) et code reviews sont stables et déjà référencés — pas de renommage pour éviter de casser la traçabilité. Note ajoutée dans `sprint-status.yaml`.

2. **Story 2.5 manquante côté détail** : `sprint-status.yaml` contient `2-5-changement-role-fonction-deplacement-dn: done` mais la section détaillée Epic 2 d'`epics.md` n'a que les stories 2.1–2.4. Inconsistance préexistante **non corrigée ici** (work déjà done, scope de cette proposition centré sur la numérotation).

3. **Historique gelé non modifié** : les rapports `implementation-readiness-report-*.md` et les sessions `brainstorming/*.md` contiennent encore des références à l'ancienne Epic 10 / FR30-32 — c'est normal, ce sont des snapshots historiques.

## Impact sprint

Aucun — aucune story Epic 10 n'avait été démarrée (statut `backlog` partout). Aucun fichier d'implementation-artifacts n'était créé pour `10-1`, `10-2`, `10-3`.
