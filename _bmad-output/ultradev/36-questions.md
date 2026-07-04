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

## Notes d'orchestration

- Q1 : Henri a d'abord demandé si les jetons pouvaient être définissables en admin, puis a tranché pour le design D6 d'origine (tout en dur, v1 minimal). La résolution jeton → groupe reste conventionnelle (donnée SQL du `TargetContext`), sans écran de réglage.
- Ces décisions figent la validation d'authoring de 36.1 (Q1/Q2) et 36.2 (Q3/Q4), réutilisée par le formulaire 36.4.
