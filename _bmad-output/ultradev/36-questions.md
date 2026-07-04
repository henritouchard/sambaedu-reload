# Ultradev — Epic 36 : questions & décisions

Date : 2026-07-04
Source : `epics-mecanismes-hors-registre.md` § « Questions ouvertes Henri (avant dev) »

## Décisions actées avant lancement

| # | Question | Options | Décision Henri | Impact |
|---|----------|---------|----------------|--------|
| Q1 | 36.1 — Configurabilité des jetons d'audience `@eleves/@profs/@personnels` | (a) vocabulaire figé + mapping en admin ; (b) audiences 100% CRUD admin ; (c) **tout en dur v1 minimal** | **(c) Tout en dur (v1 minimal)** | 3 jetons fixes en enum code + résolution par convention d'établissement, aucune UI d'admin. Groupe arbitraire = formulaire 36.4 (picker SQL). |
| Q2 | 36.1 — Combos deny interdits (racines protégées × héritage) | liste proposée / ajuster | **Liste proposée telle quelle** | Deny à héritage descendant REFUSÉ sur `C:\`, `C:\Windows`, `C:\Program Files`, `C:\Program Files (x86)`, `C:\ProgramData`. Deny `list_folder folder_only` reste autorisé. |
| Q3 | 36.2 — Refus `action:block` sur `remote_scope: lan\|any` | oui refusé / autoriser avec garde | **Oui, refusé** | Refus serveur ET agent (défense en profondeur). Échappatoire = `explicit` avec adresses hors RFC1918. |
| Q4 | 36.2 — Ciblage « couper Internet » par parc/salle | parc/salle suffit / besoin per-user | **Par parc/salle suffit** | Per-utilisateur pare-feu hors scope v1 (limitation Windows assumée). |

## Question accumulée (review 36.2, en attente Henri)

| # | Question | Contexte | Reco orchestrateur |
|---|----------|----------|--------------------|
| Q5 | 36.2 — Le mécanisme `firewall` doit-il garder `action: allow` totalement libre, ou poser un garde-fou minimal ? | Q3 ne restreint que `block` (couper Internet sans couper le LAN). `allow` n'est validé NI serveur NI agent : une future capacité (ou une donnée insérée par migration Query Builder contournant l'observer) `{in, allow, explicit, 0.0.0.0/0, tcp, 3389}` ouvrirait RDP à tout Internet sans garde-fou. Aucune capacité `allow` n'est seedée en v1 (seul `internet_access`/`block` livré, entièrement gardé) — l'angle mort ne concerne que de FUTURES capacités `allow`. | **DÉCISION HENRI (2026-07-04) = garde-fou minimal + doc** (option recommandée). Implémenté au commit `775cbf7` : `FirewallAuthoringGuard` exige un `warning` non vide sur toute règle `allow`+`in` couvrant l'Internet ouvert (`/0` par intervalles, miroir deny fs_acl). Agent server-only (warning = métadonnée capacité, jamais au payload — invariant 27.12). Réserve : union de plages étroites couvrant `/0` non détectée (cohérent Q3 per-plage). |

## Notes d'orchestration

- Q1 : Henri a d'abord demandé si les jetons pouvaient être définissables en admin, puis a tranché pour le design D6 d'origine (tout en dur, v1 minimal). La résolution jeton → groupe reste conventionnelle (donnée SQL du `TargetContext`), sans écran de réglage.
- Ces décisions figent la validation d'authoring de 36.1 (Q1/Q2) et 36.2 (Q3/Q4), réutilisée par le formulaire 36.4.
