# Sprint Change Proposal — 2026-03-25

## 1. Résumé du problème

**Déclencheur :** Constat en cours d'implémentation des Epics 1-2 — la stratégie de réécriture native module par module est trop lente. Le volume de modules legacy (wpkg 50 fichiers, iPXE 72 fichiers, imprimantes 11 fichiers…) rend la vérification anti-régression prohibitive.

**Décision :** Ajouter un epic intermédiaire **Epic 1bis — Cloisonnement Legacy** entre Epic 1 et Epic 2. Les modules PHP legacy sont intégrés via des shims (LDAP→Eloquent, MySQL→Eloquent) dans un sous-dossier `legacy/`, permettant à l'équipe de se concentrer sur la refonte UX et la gestion utilisateurs/machines/groupes. Les epics de réécriture native (3-12) restent au backlog — le cloisonnement apporte du confort, pas un report.

**Ajout complémentaire :** Un error logger local (toutes erreurs legacy + Laravel) intégré au dashboard admin, disponible dès le début de l'epic comme outil de dev. Complémentaire à GlitchTip (prévu à terme), pas nécessairement conservé.

---

## 2. Analyse d'impact

### Impact sur les epics

| Epic | Impact |
|---|---|
| Epic 1 (Fondations) | Aucun — le catchall (story 1.2) sert de base au cloisonnement |
| Epics 2→12 | **Aucun** — ni renumérotation, ni modification de contenu |

### Impact sur les artifacts

| Artifact | Modification nécessaire |
|---|---|
| `epics.md` | Ajout du nouvel Epic 1bis entre Epic 1 et Epic 2 |
| `architecture.md` | Ajout d'une section décrivant le mécanisme de cloisonnement (bootstrap, shims, structure `legacy/`, error logger) |
| `prd.md` | Ajout d'un paragraphe dans Additional Requirements mentionnant la stratégie de cloisonnement |
| `sprint-status.yaml` | Ajout epic-1bis + stories 1bis.1→1bis.6 |

### Impact technique

- Aucun impact sur le code déjà livré ni sur les stories existantes
- Zéro renumérotation

---

## 3. Approche recommandée

**Approche : Ajustement direct — ajout d'un epic sans modification de l'existant.**

**Justification :**
- Le catchall legacy (story 1.2 done) fournit déjà le mécanisme de proxy — le cloisonnement s'appuie dessus
- Les modules Tier 1 (0 LDAP, 0 exec) valident le shim rapidement avec un risque quasi nul
- Le error logger disponible dès le début sert d'outil de dev pour tout le reste du projet
- Aucun epic existant n'est modifié — zéro risque de régression sur le plan
- Le cloisonnement permet de livrer les modules legacy fonctionnels aux utilisateurs pendant que la réécriture native avance sereinement

**Effort estimé :** Moyen — les shims LDAP (~20 fonctions) et SQL (principalement wpkg) sont le gros du travail. Les intégrations Tier 1 sont des quick wins.

**Risque :** Faible — le legacy tourne déjà, on ne fait que changer son point d'entrée et sa couche d'accès données.

**Alternatives écartées :**
- Rollback : non applicable (ajout pur)
- Réduction MVP : non nécessaire (le scope ne change pas)

---

## 4. Propositions de changement détaillées

### Epic 1bis — Cloisonnement Legacy

*Les modules PHP legacy sont intégrés dans un sous-dossier `legacy/` de SER, avec des shims LDAP→Eloquent et MySQL→Eloquent. Un error logger unifié capture toutes les erreurs (legacy + Laravel) pour faciliter le développement. Les utilisateurs accèdent aux modules non encore réécrits via l'interface Laravel sans rupture fonctionnelle.*

**FRs couverts :** aucune FR produit — infrastructure de transition et outil de dev

**Prérequis :** Epic 1 (catchall story 1.2, import données story 1.1)

---

#### Story 1bis.1 — Error Logger & Module Dashboard

Handler global qui capture toutes les erreurs (legacy PHP + exceptions Laravel), les log en DB (datetime + message, sans stack trace), et les affiche dans un module du dashboard admin.

#### Story 1bis.2 — Bootstrap & Shim LDAP→Eloquent

`bootstrap.php` (init session Laravel + autoload), `config.inc.php` (pont vers config Laravel), `ldap.inc.php` shim (~20 fonctions wrapper → Eloquent). Tests PHPUnit sur données réelles pour chaque fonction shim — couverture critique.

#### Story 1bis.3 — Shim SQL MySQL→Eloquent

Remplacement des appels `mysqli_*` par des appels Eloquent dans les modules concernés, en s'appuyant sur les modèles Laravel existants (`Application`, `Depot`, `Workstation`…). Principalement `wpkg_libsql.php`.

#### Story 1bis.4 — Intégration modules Tier 1

display, oauth2, sso, cas, api, user, dossier_echange — copier dans `legacy/`, brancher sur le bootstrap + shims. Validation fonctionnelle de chaque module.

#### Story 1bis.5 — Intégration modules Tier 2

wpkg, annu2, parcs2, partages, acls, ipxe — vérification des exec et du SQL legacy post-shim.

#### Story 1bis.6 — Intégration modules Tier 3

printers, dhcp, bbb, gpo, central, infos — shim LDAP complet nécessaire, validation des exec système (`lpadmin`, `samba-tool`, `df`…).

---

## 5. Handoff d'implémentation

**Classification du changement : Modéré**

Ajout d'un epic complet avec 6 stories, modifications mineures sur 3 artifacts (epics.md, architecture.md, prd.md) + sprint-status.yaml.

### Responsabilités

| Responsable | Action |
|---|---|
| PM (John) | Mettre à jour `epics.md` : ajout Epic 1bis entre Epic 1 et Epic 2, avec les 6 stories détaillées |
| PM (John) | Mettre à jour `prd.md` : ajout paragraphe cloisonnement dans Additional Requirements |
| Architecte (Winston) | Mettre à jour `architecture.md` : section cloisonnement (bootstrap, shims, structure legacy/, error logger) |
| SM (Bob) | Mettre à jour `sprint-status.yaml` : ajout epic-1bis + stories 1bis.1→1bis.6 |
| SM (Bob) | Créer la première story (1bis.1 Error Logger) via `bmad-create-story` |

### Critères de succès

- Les 3 artifacts sont mis à jour et cohérents entre eux
- Le sprint-status.yaml reflète le nouvel epic
- La story 1bis.1 est prête pour le dev

---

*Proposal approuvé par Henri le 2026-03-25.*
