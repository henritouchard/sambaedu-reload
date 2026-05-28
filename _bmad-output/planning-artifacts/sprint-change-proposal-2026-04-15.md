---
workflow: bmad-correct-course
date: 2026-04-15
author: John (PM agent)
user: henri
status: draft-for-approval
scope: Moderate
related_epic: epic-1bis
triggering_story: 1bis-18b-module-gpo-gestion-import-export
impacted_stories: [1bis-18b, 1bis-18c, 1bis-18d, 1bis-18e, 1bis-18f]
impacted_epics: [epic-1bis, epic-9]
output_artifacts:
  - _bmad-output/implementation-artifacts/1bis-18g-module-gpo-shims-ldap-sysvol.md
  - _bmad-output/implementation-artifacts/sprint-status.yaml (updated)
---

# Sprint Change Proposal — 1bis-18g : Shims GPO LDAP/AD + sysvol

## Section 1 — Issue Summary

### Problem Statement

Le flux d'import/export de GPO (module `legacy/modules/gpo/`) ne fonctionne pas end-to-end sur la VM cible, malgré des tests Feature verts côté host et une intégration catchall opérationnelle.

**Cause racine** : le shim `legacy/ldap.inc.php::search_ad()` ne gère que les types `user`, `group`, `machine`, `member`, `filter` (ligne 317–435). Tous les autres types — dont **`gpo`**, **`site`**, **`subnet`** requis par `import_gpo()`, `export_gpo()`, `read_gpo_sysvol()`, `gpogetlink()` — tombent dans le `default:` ligne 437 qui log `_shim_log_unimplemented` et retourne `[]`.

En parallèle, **aucun wrapper** n'existe dans `legacy/` pour :
- `modify_ad(type='gpo')` (met à jour `versionnumber`, `gpcuserextensionnames`, `gpcmachineextensionnames`, `gpcfunctionalityversion`)
- `gpolistcontainers`, `gpogetlink`, `gposetlink`, `gpodellink` (liens GPO → OU/containers)
- `sysvol_put`, `read_gpo_sysvol`, `update_gpo_sysvol`, `sysvol_acl_reset` (accès au SYSVOL via smbclient/smbcacls avec bridge Kerberos)

### Discovery Context

Gap identifié par Henri durant un test d'intégration VM de la story **1bis-18b** (mergée le 2026-04-15, commit `3b382f2`). Les 524 tests Feature/Unit du host passent en vert, mais le scénario manuel **"/gpo/gpo-maj.php → cocher une GPO initiale (Wallpaper) → Valider"** produit :

1. Au 1ᵉʳ submit : `search_ad($config, 'Wallpaper', 'gpo')` retourne `[]` → `import_gpo()` conclut que la GPO n'existe pas → `gpocreate()` est appelé → ✅ création OK côté AD.
2. Au 2ᵉ submit (re-test idempotence) : `search_ad` retourne toujours `[]` → `gpocreate()` rappelé → ❌ `samba-tool gpo create "Wallpaper"` échoue avec `GPO already existing`.

### Evidence

| Source | Fichier:ligne | Observation |
|---|---|---|
| Shim actuel | `legacy/ldap.inc.php:437` | `default: _shim_log_unimplemented("search_ad(type={$type})"); return []` |
| Shim actuel | `legacy/ldap.inc.php:622` | `modify_ad` entièrement stubbé, log unimplemented |
| Absence | `legacy/` (grep) | Aucune fonction `gpolistcontainers`, `gpogetlink`, `gposetlink`, `gpodellink` |
| Absence | `legacy/` (grep) | Aucune fonction `sysvol_put`, `read_gpo_sysvol`, `update_gpo_sysvol`, `sysvol_acl_reset` |
| Legacy original | `sambaedu/includes/ldap.inc.php:1406` | Case `'gpo'` attendu : filtre LDAP `(&(objectclass=grouppolicycontainer)(\|(cn=$name)(displayname=$name)))` dans `CN=Policies,CN=System,$base_dn` |
| Legacy original | `sambaedu/includes/ldap.inc.php:1428,1442` | Cases `'site'` et `'subnet'` |
| Story source | `1bis-18a.md` dev notes (ligne 177–178) | « Le shim LDAP (`legacy/ldap.inc.php`) couvre `search_ad`, `search_user`, `search_parcs`, `modify_ad` » — affirmation **partielle** : couvre les **types génériques**, pas les types GPO-spécifiques |
| État aval | `sprint-status.yaml:153` | `9-3-gestion-des-scripts-de-demarrage-windows: paused  # bloqué par 1bis-18e (shim gpo/applications.php)` — pattern déjà connu |

### Categorisation

**Technical limitation discovered during implementation.** Pas de pivot stratégique, pas de changement d'exigence. La story 18a a livré un shim LDAP centré sur les cas d'usage *users/groups/machines* (Tier 1 et Tier 2), sans couvrir les types AD spécifiques au module GPO (Tier 3). L'épic 1bis reste valide, le périmètre ne change pas — il manque une **brique technique intermédiaire** entre 18a (includes chargeables) et 18b+ (pages fonctionnelles).

### ⚠️ Principe architectural clé (posé par Henri, 2026-04-15)

**Les GPO sont requêtées sur le vrai AD Samba, pas shimmées vers Eloquent.** Contrairement aux cases `user/group/machine/member/filter` de `search_ad()` qui sont shimmées vers les modèles Laravel (parce que SER est propriétaire de ces données), les cases `gpo/site/subnet` et les fonctions sysvol **n'ont pas d'équivalent Eloquent** : la source de vérité est exclusivement l'AD Samba réel et son SYSVOL SMB. Cette contrainte est **structurelle** (pas un choix d'implémentation) et conditionne toute la story 18g — tests unit avec mocks `ldap_*` / `exec`, validation fonctionnelle sur VM uniquement.

---

## Section 2 — Impact Analysis

### Epic Impact

**epic-1bis (Cloisonnement Legacy)** — *in-progress*
- ✅ Objectif global inchangé : cloisonner les modules legacy derrière le catchall Laravel.
- ➕ **Une story ajoutée** : `1bis-18g` entre 18b et 18c. Pas de rescope de l'epic.
- ➕ **Resequencement interne** : 18c/d/e/f attendaient implicitement que les shims GPO fonctionnent. On formalise le blocage.

**epic-9 (Déploiement Windows SER)** — *in-progress*
- ⚠️ Story `9-3` déjà marquée `paused  # bloqué par 1bis-18e (shim gpo/applications.php)`. La réalisation de 18g ne la débloque pas directement (9-3 dépend de 18e, qui lui-même dépend de 18g via 18c). Mais elle retire une couche d'incertitude sur le chemin critique.
- Pas de rescope epic-9.

### Story Impact

| Story | Statut avant | Statut après proposal | Impact |
|---|---|---|---|
| `1bis-18a` | done | **done (inchangé)** | Livrée correctement selon ses AC. On ajoute un pointeur vers 18g dans ses Dev Notes (note de complétude). Rollback **non nécessaire**. |
| `1bis-18b` | done | **done + known-limitation** | Livrée correctement selon ses AC (host-side contract testing, HTTP 200, CSRF, embedding SER). Ajouter une section "Known Limitations" pointant vers 18g pour le flux e2e VM. Rollback **non nécessaire**. |
| `1bis-18c` | ready-for-dev | **blocked (attend 18g)** | Dépendance dure ajoutée. Ne pas démarrer avant 18g `done`. |
| `1bis-18d` | backlog | **backlog (dépend de 18g + 18c)** | Inchangé mais dépendance documentée. |
| `1bis-18e` | backlog | **backlog (dépend de 18g + 18c)** | Débloquera 9-3. |
| `1bis-18f` | backlog | **backlog (dépend de 18g)** | Inchangé. |
| `1bis-18g` | — | **ready-for-dev (nouveau)** | Nouvelle story intermédiaire. |
| `9-3` | paused | paused (inchangé) | Note mise à jour pour préciser la chaîne 18g → 18c → 18e. |

### Artifact Conflicts

| Artefact | Impact | Action |
|---|---|---|
| `_bmad-output/planning-artifacts/prd.md` | Aucun conflit. Les FR GPO ne sont pas redéfinis, juste décalés sur la chaîne de dépendance. | **Aucune modification** |
| `_bmad-output/planning-artifacts/epics.md` | Pas de rescope epic. Possible à mettre à jour si la liste des stories 1bis-18* y est explicitée — à vérifier. | **Vérifier + patch mineur si nécessaire** |
| `_bmad-output/planning-artifacts/architecture.md` | Pas d'impact architectural : le shim reste au même endroit (`legacy/ldap.inc.php`), on ajoute des cases et des fonctions dans le même pattern déjà en place. | **Aucune modification** |
| `_bmad-output/implementation-artifacts/sprint-status.yaml` | Ajouter `1bis-18g: ready-for-dev` sous `epic-1bis`. Mettre à jour `last_updated: 2026-04-15`. Ajouter note de dépendance sur 18c. | **Modification obligatoire** |
| `_bmad-output/implementation-artifacts/1bis-18a-*.md` | Ajouter un pointeur en Dev Notes vers 18g (complétude du shim pour cas GPO). | **Patch optionnel** (traçabilité) |
| `_bmad-output/implementation-artifacts/1bis-18b-*.md` | Ajouter une section "Known Limitations" dans le Change Log pointant vers 18g. | **Patch recommandé** (honnêteté traçable) |

### Technical Impact

| Zone | Impact | Mesure |
|---|---|---|
| Code | `legacy/ldap.inc.php` étendu (nouveaux cases + fonctions). Pas de refacto des cas existants. | Faible |
| Code | **Nouveau fichier** `legacy/gpo_shim.inc.php` (proposé) pour isoler les shims GPO-spécifiques (`gpolistcontainers`, `gpogetlink`, `gposetlink`, `gpodellink`, `sysvol_*`) — évite d'alourdir `ldap.inc.php`. Chargé par `bootstrap.php`. | Faible |
| Bootstrap | Ajouter 1 ligne dans `legacy/bootstrap.php` pour charger `gpo_shim.inc.php`. | Trivial |
| Tests unitaires | Nouveaux tests contract côté host (mock LDAP + smbclient) — pattern 18a/18b. | Moyen |
| Tests intégration VM | **Obligatoire pour cette story** : test manuel `/gpo/gpo-maj.php` sur la VM avec un vrai AD Samba. | Moyen (friction SSH, pas de CI) |
| CI/CD | Aucun impact. La VM n'est pas dans la CI. | Aucun |
| Infrastructure | Requiert un ticket Kerberos valide (`/usr/share/sambaedu/sbin/renew_ticket.sh`) et l'extension `php-ldap` + `smbclient` CLI présents sur la VM — **déjà garantis par l'image SER**. | Aucun |

---

## Section 3 — Recommended Approach

### Options évaluées

**Option 1 — Direct Adjustment (créer 1bis-18g)** — ✅ **RECOMMANDÉ**
- Effort : Medium (3–5 j dev + tests intégration VM)
- Risque : Medium (LDAP + Kerberos + smbclient avec bridge de sessions — zone Tier 3)
- Maintient le timeline global. Respecte la granularité établie (18a = fondation, 18b = pages gestion, 18c-f = apps/wallpaper/scripts/profils). 18g s'insère naturellement entre "fondation" et "pages consommatrices".

**Option 2 — Rollback 18a + refonte du shim**
- Effort : High (réouverture d'une story done, invalidation des 14 tests unit de 18a)
- Risque : High (risque de régression sur les cas déjà couverts par 18a)
- **Non justifié** : 18a a livré ses AC (bootstrap + function_exists). Le gap est une limite de périmètre, pas un bug d'implémentation.

**Option 3 — MVP Review (différer les stories 18c-f)**
- Effort : Low côté planif, mais impact fonctionnel majeur
- Risque : High — **bloque l'epic 9-3 indéfiniment** (Windows startup scripts), fonctionnalité attendue par le client SER.
- **Non viable** : les 4 stories 18c-f sont dans le périmètre MVP légitime (wallpaper, Firefox/Thunderbird, scripts démarrage, profils itinérants — tous sont des usages quotidiens établissement).

### Selected Approach : **Option 1 — Direct Adjustment**

**Justification :**
1. **Granularité cohérente** : 18g fait ~350–500 lignes de shim PHP + ~15 tests — taille comparable à 18a (fondation) et inférieure à 18b (intégration + tests Feature).
2. **Blast radius contenu** : aucune modification du code Laravel app/. Zone de modification strictement `legacy/`.
3. **Déblocage en cascade clair** : 18g done → 18c démarrable → 18d/e/f démarrables → 9-3 réévaluable.
4. **Traçabilité** : la story 18g matérialise dans le backlog ce qui était une assumption implicite dans 18a. Améliore la qualité de la documentation d'epic.

### Effort Estimate & Timeline

| Phase | Effort | Notes |
|---|---|---|
| Analyse + lecture legacy (cases gpo/site/subnet, sysvol_put, gpolistcontainers) | 0.5 j | Lire `sambaedu/includes/ldap.inc.php:1406+` et `sambaedu/includes/gpo.inc.php` |
| Impl `search_ad(type=gpo/site/subnet)` + `modify_ad(type=gpo)` | 1 j | LDAP direct via Eloquent shim ou `ldap_*` builtins |
| Impl wrappers `gpolistcontainers`/`gpogetlink`/`gposetlink`/`gpodellink` | 1 j | `samba-tool gpo ...` via exec + parsing, OU LDAP direct |
| Impl bridge sysvol (`sysvol_put`, `read_gpo_sysvol`, `update_gpo_sysvol`, `sysvol_acl_reset`) | 1 j | `smbclient`/`smbcacls` avec `KRB5CCNAME` depuis `renew_ticket.sh` |
| Tests unit + intégration VM | 1 j | Host : mocks LDAP + exec. VM : test manuel documenté dans la story. |
| **Total** | **~4.5 j** | |

**Timeline target** : 18g `ready-for-dev` dès approbation de ce proposal. Pick-up dev à partir de 2026-04-16. Target `done` : 2026-04-22.

### Risk Assessment

| Risque | Probabilité | Impact | Mitigation |
|---|---|---|---|
| Bridge Kerberos `KRB5CCNAME` non disponible hors VM | Haute | Bloque les tests host | Tests unit sur mock + test manuel VM documenté dans AC |
| Injection shell via `samba-tool gpo create "$name"` | Moyenne | Sécurité | Utiliser `escapeshellarg()` systématique. Audit documenté dans la story (pattern 18a). |
| `search_ad(type=gpo)` renvoie format différent de ce qu'attend `import_gpo` | Moyenne | Flux cassé malgré implémentation | Tests contract sur la forme du résultat (keys attendues : `cn`, `displayname`, `gpcfilesyspath`, `versionnumber`, `gpcuserextensionnames`, `gpcmachineextensionnames`, `gpcfunctionalityversion`, `flags`) |
| Régression sur les cases `user/group/machine` existants | Faible | Story 2-* / 4-* cassées | Les cases existants ne sont pas touchés. Suite de tests existante (524 tests) ne doit pas régresser. Gate CI habituelle. |

---

## Section 4 — Detailed Change Proposals

### 4.1 — Nouvelle Story : `1bis-18g-module-gpo-shims-ldap-sysvol`

**Fichier** : `_bmad-output/implementation-artifacts/1bis-18g-module-gpo-shims-ldap-sysvol.md`

**Résumé (titre + intention)** :
> **Story 1bis.18g — Module GPO : Shims LDAP/AD (types gpo/site/subnet) + wrappers sysvol**
>
> As a **développeur**, I want implémenter dans `legacy/` les shims LDAP manquants pour les types GPO-spécifiques (`gpo`, `site`, `subnet`), les wrappers d'action GPO (`gpolistcontainers`, `gpogetlink`, `gposetlink`, `gpodellink`, `modify_ad(type=gpo)`) et les fonctions d'accès SYSVOL (`sysvol_put`, `read_gpo_sysvol`, `update_gpo_sysvol`, `sysvol_acl_reset`), So que les flux import/export/link GPO des stories 18b/c/d/e/f fonctionnent end-to-end sur la VM cible et pas uniquement en HTTP 200 host-side.

**AC principaux** (extrait) :
1. `search_ad($config, $name, 'gpo')` retourne un tableau non vide pour une GPO existante dans l'AD, avec les clés `cn`, `displayname`, `gpcfilesyspath`, `versionnumber`, `gpcuserextensionnames`, `gpcmachineextensionnames`, `gpcfunctionalityversion`, `flags`.
2. Même contrat pour `type='site'` et `type='subnet'`.
3. `modify_ad(..., 'gpo', ...)` met à jour `versionnumber`, `gpcuserextensionnames`, `gpcmachineextensionnames`, `gpcfunctionalityversion` sans erreur et l'attribut modifié est visible au `search_ad` suivant.
4. `gpolistcontainers`, `gpogetlink`, `gposetlink`, `gpodellink` retournent/modifient les liens GPO→OU conformément à la spec legacy.
5. `sysvol_put`, `read_gpo_sysvol`, `update_gpo_sysvol` lisent/écrivent sur SYSVOL via smbclient avec le ticket Kerberos courant ; `sysvol_acl_reset` réapplique les ACLs GPO par défaut.
6. **Test d'acceptation end-to-end sur VM** : `/gpo/gpo-maj.php` → cocher "Wallpaper" → Valider → message vert "Importation via Git OK" ET `ssh <vm> 'samba-tool gpo listall'` affiche la GPO "Wallpaper".
7. **Idempotence import** : un 2ᵉ submit du même "Wallpaper" ne déclenche PAS de `samba-tool gpo create` (pas d'erreur "already existing"), mais un simple `modify_ad` / resync sysvol.
8. Suite de tests host (524 tests) reste verte — aucune régression sur les cases `user/group/machine/member/filter` existants.
9. Tests unit ajoutés : couverture contract des nouveaux cases + mocks LDAP + mocks exec pour smbclient/samba-tool.
10. Audit sécurité exec : tableau documenté pour `samba-tool gpo create|del|setlink|dellink`, `smbclient`, `smbcacls`, avec statut `escapeshellarg` pour chaque paramètre.

**Dépendances** : `1bis-18a` (done), `1bis-18b` (done)
**Bloque** : `1bis-18c`, `1bis-18d`, `1bis-18e`, `1bis-18f`
**Débloque (indirect)** : `9-3` (via 18e)

(Le fichier story complet est généré en parallèle — voir `_bmad-output/implementation-artifacts/1bis-18g-module-gpo-shims-ldap-sysvol.md`.)

### 4.2 — Update `sprint-status.yaml`

```diff
- last_updated: 2026-04-15  # 1bis-18b done
+ last_updated: 2026-04-15  # 1bis-18g créée (course correction shim GPO)

  1bis-18a-module-gpo-includes-core: done
  1bis-18b-module-gpo-gestion-import-export: done
+ 1bis-18g-module-gpo-shims-ldap-sysvol: ready-for-dev  # bloque 18c-f, cf. sprint-change-proposal-2026-04-15
- 1bis-18c-module-gpo-config-apps-firefox-thunderbird: ready-for-dev
+ 1bis-18c-module-gpo-config-apps-firefox-thunderbird: blocked  # dep: 1bis-18g
  1bis-18d-module-gpo-wallpaper-personnalisation: backlog
  1bis-18e-module-gpo-scripts-veyon-wine-associations: backlog
  1bis-18f-module-gpo-profils-itinerants: backlog
```

### 4.3 — Patch `1bis-18b-*.md` (Known Limitations)

Ajout en fin de Change Log :

```markdown
### Known Limitations (découvert post-merge, 2026-04-15)

Le flux e2e d'import GPO via `/gpo/gpo-maj.php` **ne fonctionne pas sur VM** en l'état
des shims `legacy/ldap.inc.php`. Le catchall rend la page (AC #1–#11 remplis), mais la
soumission d'un import déclenche `import_gpo()` → `search_ad($config, $gpo, 'gpo')` qui
tombe dans le default du switch et retourne `[]`. Résultat : `gpocreate()` est rappelé
au 2ᵉ submit → erreur "GPO already existing".

Traité par la story **1bis-18g** (Shims GPO LDAP/AD + sysvol) — voir
`_bmad-output/planning-artifacts/sprint-change-proposal-2026-04-15.md`.
```

### 4.4 — Patch `1bis-18a-*.md` (optionnel, traçabilité)

Ajout en fin de Dev Notes, section "Learnings" :

```markdown
### Follow-up post-story (2026-04-15)

L'affirmation « Le shim LDAP couvre search_ad, search_user, search_parcs, modify_ad »
(ligne 177–178) est exacte pour les types génériques (user/group/machine/member/filter)
mais **ne couvre pas** les types GPO-spécifiques (gpo/site/subnet) ni les wrappers
sysvol. Ces shims sont ajoutés dans la story **1bis-18g** suite au sprint change
proposal du 2026-04-15.
```

---

## Section 5 — Implementation Handoff

### Scope Classification : **Moderate**

> **Moderate** — Requires backlog reorganization and PO/SM coordination
> - Nouvelle story ajoutée (`1bis-18g`)
> - Resequencement : 18c passe de `ready-for-dev` à `blocked`
> - Note de `Known Limitations` ajoutée à 18b
> - Dépendances documentées pour 18d/e/f

### Handoff Recipients

| Rôle | Responsabilité | Action |
|---|---|---|
| **Scrum Master (bmad-sm)** | Finaliser le fichier story `1bis-18g` (AC détaillés, Tasks/Subtasks, Dev Notes) | Lancer `/bmad-sm` → `bmad-create-story` avec ce proposal en input |
| **Dev team** | Implémenter 18g | Pick-up après approbation SM + Dev Notes complètes. Modèle Opus recommandé (Tier 3, cohérent avec 18a). |
| **QA / Henri** | Test d'acceptation manuel VM | Exécuter le scénario AC #6 sur VM : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50` puis navigation `/gpo/gpo-maj.php` |
| **PO (implicite)** | Validation du resequencement | Acter que 18c attend 18g |

### Success Criteria

1. Fichier `_bmad-output/implementation-artifacts/1bis-18g-module-gpo-shims-ldap-sysvol.md` présent, complet et `ready-for-dev`.
2. `sprint-status.yaml` à jour : `1bis-18g: ready-for-dev`, `1bis-18c: blocked`, `last_updated: 2026-04-15`.
3. Patch "Known Limitations" appliqué à la story 18b.
4. Sprint Change Proposal (ce fichier) committé dans `_bmad-output/planning-artifacts/`.
5. Henri a explicitement approuvé le proposal (étape 5 du workflow correct-course).

### Non-Goals de ce proposal

- **Pas** de modification du code Laravel `app/` ou des migrations.
- **Pas** de réouverture de 18a ou 18b.
- **Pas** de rescope PRD ni epics.md.
- **Pas** de changement sur les autres stories Tier 2/3 non-GPO (`1bis-10 ipxe`, `1bis-11 wpkg`, etc.).

---

## Approval

> **Henri, pour approuver :** réponds `yes` ou `approved`.
> Pour ajuster : indique quelle section/quel point tu veux revoir.
