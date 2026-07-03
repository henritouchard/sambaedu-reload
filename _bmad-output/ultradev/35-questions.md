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
- **Réponse retenue** : _(en attente)_

