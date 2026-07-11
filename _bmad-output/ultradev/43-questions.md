# Ultradev — Epic 43 : registre des questions

Run du 2026-07-11 (branche `ultradev/epic-43`, worktree dédié — main non touché).

## Questions tranchées par l'orchestrateur (traçabilité, pas de blocage)

### 1. Validation lab de `policy_broadcast` impossible depuis ce contexte — story 43.1

**Contexte** : l'epic demandait de « valider au lab l'efficacité réelle de policy_broadcast sur
RestrictRun/DisallowRun » au create-story. Aucun accès lab/VM depuis un worktree (règle projet).

**Décision** : l'échelle implémente les 3 gestes quoi qu'il arrive ; la validation lab est déportée en
action QA manuelle (runbook `docs/qa/domains/agent.md`, scénarios 43.1.x) ; le choix du hint par capacité
appartient à la 43.2, qui a retrofité SANS `explorer_restart` (conservateur) — l'ajustement post-lab est un
simple UPDATE de seed, pas du code.

### 2. Throttle anti-thrash sur `explorer_restart` — story 43.1 (finding review 🟠)

**Contexte** : en drift récurrent externe, le restart d'Explorer serait parti à chaque passe (~30-60 s) —
session cassée en boucle. La review demandait : throttle en 43.1 ou délégation à 43.2 ?

**Décision** : corrigé en 43.1 (le runtime ne vit qu'ici ; NFR-A1 « jamais de session cassée » rend la règle
dérivable) — au plus un `explorer_restart` par 10 min par instance compagnon, sinon dégradation
`policy_broadcast` + warning.

### 3. Critère TTL court V1 — story 43.3

**Décision (create-story)** : existence d'un `capability_assignment` à value non-null d'un slug ∈
`config('agent.ttl_sensitive_capabilities')` (défaut `['restrict_run']`) sur une maille du contexte —
miroir exact de `resolveOverrides()`. Ni table ni migration ; le flag examen 41.3 se branchera sans code.
Défaut global 3600 s inchangé (abaissement = action opérateur documentée au runbook).

## Question soumise à l'utilisateur (non bloquante pour l'epic 43)

### ❓ 1. Contrat de déflag imposé à la future story 41.3 — story 43.3

**Contexte** : le critère TTL court repose sur « assignment à value non-null ». La convention projet
« off en UI ⇒ off écrit une vraie valeur » ferait qu'un déflag examen écrit en `off` laisserait les postes
à 90 s de poll À VIE (mode d'échec du piège `internet_access`).

**Enjeu** : cohérence de la cadence de check-in du parc après un examen.

**Options** :
| Option | Conséquences |
|--------|--------------|
| A. 41.3 déflague par SUPPRESSION de l'assignment (DELETE) | Critère 43.3 exact, zéro code de plus ; contrainte déjà documentée (config/agent.php + notes de coordination de l'epic) |
| B. 41.3 écrit `off` et 43.3 affine son critère (interprétation de la valeur « active ») | Critère par-capacité plus complexe, contredit D2 (pas d'interprétation de valeur), sur-conception V1 |

**Recommandation** : A — la contrainte est documentée de façon contraignante aux 3 endroits ; le
create-story de la 41.3 la lira. Réponse : _(en attente — à ratifier, n'empêche rien pour l'epic 43)_.
