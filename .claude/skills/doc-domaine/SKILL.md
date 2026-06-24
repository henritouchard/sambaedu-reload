---
name: doc-domaine
description: Rédige ou refond la documentation d'un domaine fonctionnel SE5 selon le gabarit éprouvé (index + métier + technique + runbook), ancré sur le code et conforme aux règles de rédaction du projet. Utiliser quand l'utilisateur dit "documente le domaine X", "rédige la doc de X", "industrialise la doc", "crée/refais la doc de X", ou veut nettoyer des fiches existantes au standard.
---

# doc-domaine

Industrialise la rédaction de documentation par **domaine fonctionnel** (agent,
identité/AD, postes/iPXE, config, WPKG…). Reproduit le process validé sur le
domaine **agent** — qui sert d'**implémentation de référence** : avant de
rédiger, lire `docs/agent/README.md` + `docs/agent/metier.md` +
`docs/agent/token-lifecycle.md` comme étalons concrets.

## Principe

La doc **sert la reprise et l'onboarding** de mainteneurs et d'admins. Elle ne
duplique pas ce qui vit déjà (code, mémoire auto, backlog) : elle **distille et
relie**. Et elle **suit le code** (cf. mémoire `feedback_doc_follows_code`) : on
ne documente que le **livré et stable**, jamais le spéculatif.

## Règles de rédaction — NON NÉGOCIABLES

(Source : mémoire `feedback_doc_writing_style`.) À appliquer à chaque fiche.

1. **Framing SE4 → agent.** Le lecteur voit le monde **legacy (SE4/GPO) → état
   actuel**, jamais l'évolution interne de SE5. Un gain s'énonce *vs le legacy*,
   jamais « avant on faisait X en SE5, maintenant Y ».
2. **Aucune notion née d'un état SE5 disparu** (ex. « drift toléré / mode
   strict-default ») : pour le dev la convergence est inconditionnelle, point.
3. **Jamais de numéros de story / epic.**
4. **Jamais d'exigences internes opaques** (`FR12`, `NFR7`, labels `D1`/`D2`…) :
   énoncer la règle en clair, pas son code de pilotage.
5. **Jamais de décisions techniques abandonnées** ni leurs résidus.
6. **Précis et concis** avant tout.
7. **Mermaid quand c'est pertinent** (flux, séquence, architecture) plutôt qu'un
   paragraphe. Ne pas forcer.

## Le gabarit (triptyque)

Domaine riche → un dossier `docs/<domaine>/` ; petit domaine → un fichier unique
`docs/domains/<domaine>.md`.

- **`README.md`** — la porte d'entrée. Sections : *en une phrase* · *le pourquoi*
  (résumé) · *parcours de lecture par audience* (mainteneur / exploitant / soi) ·
  *carte des fiches* (tag axe métier/technique/processus) · *invariants à ne
  jamais casser* · *manques connus* · *carte du code* (ancrage `fichier:ligne`).
- **`metier.md`** — le *pourquoi*, en **ADR** : une décision = contexte → décision
  → conséquences, cadrée *vs legacy*.
- **fiches techniques** — le *comment*, une par sujet. En tête : blockquote de
  cadrage (sujet + orthogonalité aux fiches voisines).
- **runbook** dans `docs/runbooks/<sujet>.md` — la *procédure d'exploitation*
  pas-à-pas (gestes, commandes), distincte de la fiche de référence.

## Étapes

### 1. Cartographier le code — TOUJOURS en premier

Lancer un subagent `Explore` pour relever les faits ancrés (`fichier:ligne`) :
points d'entrée, services, contrôleurs, routes, modèles, tables, tests. **Ne
jamais rédiger depuis la seule mémoire** : elle peut être périmée (sur le domaine
agent, 3 faits mémorisés l'étaient — version, purge, couverture). Le code fait
foi. Signaler au passage les manques *de code* repérés (pas que de doc).

### 2. Index `README.md`

Le livrable le plus structurant et le plus réutilisable : il **relie** sans
dupliquer. C'est aussi le gabarit. S'aligner sur `docs/agent/README.md`.

### 3. `metier.md`

**D'abord, confirmer l'intention métier auprès de l'utilisateur** (cf. mémoire
`feedback_understand_business_before_design`). Le code montre *ce qui est*, pas
*ce qui est voulu* : avant de figer des ADRs, valider la **trajectoire** —
qu'est-ce qui est **cible**, qu'est-ce qui est **transitoire** (migration,
amorçage), qu'est-ce qui est **en voie d'extinction** ? Un mécanisme qui « marche
aujourd'hui » dans le code peut n'avoir vocation à ne plus servir une fois la
bascule faite (ex. l'import AD = outil de migration, cf.
`project_sync_from_ad_transitional`). Ces nuances ne sont **jamais** dans le code
— les demander, puis les graver dans l'ADR et en mémoire (`project_*`).

Ensuite, distiller les décisions (souvent déjà en mémoire `project_*`) en ADRs
durables, chacun cadré *vs legacy*. S'aligner sur `docs/agent/metier.md`.

### 4. Fiches techniques + runbook

Rédiger le neuf ; pour le runbook, **lire les scripts réels** avant (la doc suit
le code — pas de procédure approximative).

### 5. Nettoyer l'existant (si des fiches existent déjà)

Fan-out de subagents `general-purpose`, **un par fiche**, chacun avec : les 7
règles ci-dessus, l'étalon `docs/agent/token-lifecycle.md`, et la consigne
« préserver TOUTE la substance technique, ne purger que le framing, n'inventer
RIEN ». Lots sensibles (notion disparue, jargon de pilotage) : se les réserver et
les relire soi-même.

### 6. Passe de vérification

Après rédaction/nettoyage, vérifier mécaniquement depuis `docs/` :

```bash
# Motifs de pilotage / notions interdites résiduels (vide = OK) :
grep -rnE 'Story|Epic |NFR[0-9]|FR[0-9]{1,2}\b|drifted_allowed|mode strict|drift toléré' \
  <domaine>/ 2>/dev/null | grep -viE 'agent_|history'

# Liens .md cassés (cible inexistante) :
for f in <domaine>/*.md; do dir=$(dirname "$f"); \
  grep -oE '\]\(([^)]+\.md)\)' "$f" | sed -E 's/\]\(([^)]+)\)/\1/' | \
  while read -r l; do [[ "$l" =~ ^http ]] && continue; \
  [[ -f "$dir/${l%%#*}" ]] || echo "  $f → $l INTROUVABLE"; done; done
```

Relire soi-même la fiche la plus sensible. Raccorder l'index (retirer des
« manques » ce qui vient d'être produit).

## Garde-fous

- **Ne pas dupliquer** code, mémoire, backlog : distiller et lier.
- **Ne pas documenter le spéculatif** : seulement le livré et stable.
- **Ne pas laisser un subagent strip de la substance technique** : il purge le
  framing, jamais les tables/codes/chemins/exemples ; relire les lots sensibles.
- **Ne pas inventer** : tout fait technique vient du code (étape 1).
- Mettre à jour la mémoire (`feedback_doc_*`) si l'utilisateur affine une règle.
