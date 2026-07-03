# Ultradev — Epic 35 : questions bloquantes

Traçabilité des questions soumises à Henri pendant l'orchestration ultradev de l'Epic 35
(Capacités v2 — verbe delete, listes indexées, ruche HKU, ciblage par groupe).

## Q1 — Gate D6 : ouvrir ou non la story 35.6 (mécanisme `privilege` LSA SeDeny*)

- **Story** : 35.6 (GATED dès le cadrage epic — décision D6)
- **Date** : ouverte 2026-07-03, soumise en synthèse d'epic
- **Contexte** : le mécanisme `privilege` (handler Go secedit/LSA, type contrat additif,
  validation SeDeny*-only) n'a qu'UN consommateur connu : « les élèves ne peuvent pas
  ouvrir de session RDP » (GPO Blocages_eleves).
- **Options** :
  - A. Ne pas ouvrir 35.6 — couvrir le besoin RDP élèves par `remote_desktop_enabled=off`
    par parc (déjà livré) et/ou attendre le futur mécanisme `localgroup` (Remote Desktop
    Users). Coût nul, pas de nouveau handler.
  - B. Ouvrir 35.6 — payer le mécanisme `privilege` complet (contrat + provider +
    handler Go LSA + seed `rdp_denied_for_group`). Couvre le ciblage par groupe
    d'utilisateurs (le per-parc ne distingue pas élèves/profs sur un même poste).
- **Recommandation** : A tant que le besoin « RDP interdit aux élèves mais autorisé aux
  profs sur le même parc » n'est pas confirmé sur le terrain ; sinon B.
- **Réponse retenue** : **A — Ne pas ouvrir** (Henri, 2026-07-03, synthèse ultradev).
  RDP-élèves couvert par `remote_desktop_enabled=off` par parc (livré) ; la story reste
  au backlog, réouvrable si le besoin « RDP interdit aux élèves mais autorisé aux profs
  sur le MÊME parc » se confirme sur le terrain.

## D1 — Décision d'orchestration (non bloquante, révocable) : support `name=""` et flip `photo_viewer_restored`

- **Stories** : 35.5 → 35.2 | **Date** : 2026-07-03
- **Contexte** : le create-story 35.5 a découvert que « zéro évolution moteur » (epic) est
  faux pour 2 des 4 clés de la visionneuse : elles écrivent la VALEUR PAR DÉFAUT de la clé
  (`name=""` dans le Registry.xml de la GPO source), que `parseRegistrySpec` (agent Go)
  rejette comme enveloppe invalide. Armer la capacité en l'état poserait les 2 Clsid sans
  les commandes (app à moitié enregistrée — pire que rien).
- **Décision orchestrateur** : (a) la 35.5 livre son fail-safe tel que cadré par la story
  (seed complet fidèle, `is_active=false`) ; (b) le support `name=""` (~3 lignes de parse +
  doc §7.1 + tests) est AJOUTÉ AU SCOPE de la 35.2, qui possède déjà toute la surface
  (parseRegistrySpec, doc contrat, golden, bump 2.4.0, publication) ; (c) le flip
  `is_active=true` est fait à l'intégration de la vague 2 par migration dédiée, une fois
  le support prouvé par les tests Go de la 35.2.
- **Justification** : chemin sûr qui ne forclôt rien — si Henri préfère laisser la
  capacité inactive, le flip se révoque par une migration d'une ligne.
- **Statut final (2026-07-03)** : EXÉCUTÉ intégralement — support `name=""` livré et
  prouvé par la 35.2 (agent 2.4.0, tests Go dédiés) ; flip par migration
  `2026_07_03_150000` (is_active=true + description réécrite) à l'intégration de la
  vague 2 ; révocable par `down()` (inverse exact).

