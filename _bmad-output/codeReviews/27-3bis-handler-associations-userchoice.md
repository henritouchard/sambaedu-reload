# Code Review — Story 27.3bis : Handler associations de fichiers (UserChoice)

Status: to-validate
Date: 2026-06-17
Dev model: opus (claude-opus-4-8[1m])
Review model: sonnet
Second review: oui (opus)

## Questions en attente

> Décisions de design laissées à Henri (defaults sûrs déjà appliqués ; ces points sont des évolutions, pas des bugs bloquants).

1. **Sémantique de reproduction du catalogue (N2)** — quand `default.xml` (VM/prod) fournit un ProgId DIFFÉRENT pour un identifiant déjà en baseline (ex. `.html → Chrome` vs baseline `.html → Firefox`), le défaut appliqué = **ACCUMULER** (les deux deviennent deux choix de défaut pour `.html` dans le catalogue) — c'est le comportement conservateur retenu (non destructif). Veux-tu plutôt **REMPLACER par identifiant** (un seul défaut par extension, `default.xml` écrase la baseline) ? Le fix de déduplication appliqué empêche déjà les doublons de paires IDENTIQUES ; seule la divergence ProgId reste ouverte.
2. **Granularité `error` type-level (N1)** — documentée et **assumée** (grain type×poste figé par 27.8). Conséquence concrète : avec la non-intersection WPKG (D-Henri n°3), un défaut ciblant une app non installée maintient `associations: error` à chaque cycle. OK pour toi, ou veux-tu (hors 27.3bis) rouvrir le grain item×poste ?
3. **Ordre de déploiement du seeder (P4)** — si le seeder tourne avant la création des parcs, le catalogue est peuplé mais non assigné. Documenté (rejouable + UI). Suffisant, ou veux-tu un warning CLI explicite ?

## Synthèse des problèmes

| # | Problème | Sévérité | Pertinence Opus | Statut |
|---|----------|----------|-----------------|--------|
| P1 | Fidélité hash UserChoice non prouvée contre Windows natif (vecteurs inter-implémentation) | 🟠 | 1 (surévalué — 3 transcriptions concordantes) | ✅ Doc (validation → T9) |
| P2 | Variable `sysWow64` contient `C:\Windows` (mal nommée, piège maintenance) | 🟡 | 2 | ✅ Corrigé |
| P3 | `FileAssociationSeeder` importe `App\Gpo\Services\AssociationsResolver` (couplage legacy) | 🟠 | 1 | ✅ Corrigé (const locale) |
| P4 | Seeder avant création des parcs → catalogue non assigné | 🟡 | 2 | ⏳ Doc + question Henri |
| P5 | N+1 `find()` dans le seeder (modèles déjà chargés par `updateOrCreate`) | 🟡 | 2 (pire que décrit) | ✅ Corrigé |
| P6 | Nom de route faux dans le Dev Agent Record (`app.file-associations`) | 🟡 | 3 | ✅ Corrigé |
| P7 | Lecture shell32.dll entière (~20-30 Mo) vs 5 Mo legacy | 🟡 | 1 (bénin) | ✅ Doc (commentaire) |
| N1 | Granularité `error` **type-level**, pas item-level (« isolation par item » trompeur au rapport) | 🟠 | — (manqué par sonnet) | ✅ Doc (contrat §7 + QA) + UI (warning ligne + toast, demande Henri) |
| N2 | Duplication catalogue sur VM : clés seeder XML (`legacy_…`) ≠ clés migration | 🟠 | — (manqué par sonnet) | ✅ Corrigé (clé déterministe) |
| N3 | `down()` du seed-migration : liste de clés en dur (divergence si set évolue) | 🟡 | — | ✅ Corrigé (dérivé du catalogue) |
| N4 | Colonnes varchar sans contrainte de longueur (overflow PG invisible en SQLite) | 🟡 | — | ⏳ Note (pas d'action, identifiants courts) |
| N5 | `decodeUTF16LE` décode shell32.dll entier en mémoire (~40-60 Mo transitoires) | 🟡 | — | ✅ Doc (cumulé P7) |
| N6 | Nom de route non couvert par un test | 🟡 | — | ✅ Doc corrigée (test archi optionnel, hors scope) |
| N7 | Sécurité UI (Gate `app.customize` double verrou, `findOrFail`) | — | — | ✅ RAS (non-problème) |

Légende sévérité : 🔴 Critique — 🟠 Important — 🟡 Mineur
Pertinence Opus : 0 = non pertinent … 3 = très pertinent ; — = non noté (problème ajouté au 2e avis)

## Détail des problèmes notables

### P1 / N0 — Fidélité du hash UserChoice (cœur de risque)

**Constat initial (sonnet)** : vecteurs validés par une transcription Python indépendante = garantie inter-implémentation Go↔Python, PAS Windows-native. Risque d'erreur systématique partagée.

**Avis Opus** : pertinence **1** (risque SURÉVALUÉ). Opus a écrit une **3e** transcription indépendante depuis `SFTA.ps1` (pas depuis le Go) → reproduit EXACTEMENT les 3 vecteurs (`h5ZFaFkHaDU=`, `9RbFZtAB87g=`, `zWoSzvx4Irg=`). Revérification une-par-une de toutes les constantes des passes 1 et 2, de l'encodage UTF-16LE + terminateur, du `getShiftRight` sur valeurs négatives (point le plus piégeux), de la discrimination fichier/protocole et du chemin `UrlAssociations`. **Portage prouvé fidèle à l'algorithme public PS-SFTA** (déjà éprouvé en prod par le legacy). Risque résiduel = l'algo lui-même face au vrai Windows, **non bloquant**.

**Action** : note explicite ajoutée dans `handler_associations_test.go` (portée des vecteurs = inter-implémentation, preuve finale déléguée à T9 lab Windows). Le scénario QA 27.3bis.1/.2 couvre déjà la validation Windows réelle (clé non invalidée après redémarrage Explorer).

### N1 — Granularité `error` type-level (manqué par la 1re review)

**Constat (opus)** : le dispatch agent (`engine.go`) est **par type**. Un seul item `associations` en échec → tout le type reporté `error` + non persisté (re-convergence chaque cycle). La doc/story parlait d'« isolation par item » — faux au niveau rapport. Avec la non-intersection WPKG (D-Henri n°3), un défaut sur app non installée masque le type en `error` en permanence.

**Action** : `contract-v1.md` §7.2 et `docs/qa/domains/agent.md` (scénario 27.3bis.4) corrigés — granularité type-level explicitée, « error non fatal » redéfini (ne tue pas les autres TYPES, ≠ n'affecte pas les autres associations du même type). Comportement **assumé** (grain figé 27.8), pas un bug.

**Décision Henri (2026-06-17)** : accepter le grain type-level MAIS le rendre **visible dans l'UI**. Ajouté : un **warning à côté de chaque association activée** (icône + tooltip rappelant que si le ProgId cible n'est pas installé, le poste signale une erreur, choix utilisateur préservé) ET un **toast d'avertissement à l'activation** (`toastWarning`, nomme le ProgId). Implémenté dans `parc-settings/file-associations/index.blade.php`, test UI 5/5 vert.

### N2 — Duplication de catalogue sur VM (manqué par la 1re review)

**Constat (opus)** : la migration seede des clés `html_firefox`… ; le seeder en chemin `default.xml` (lisible sur VM) générait des clés `legacy_<id>_<progid>` → **2 lignes catalogue pour la même paire `(.html, FirefoxHTML)`**, doublons UI + faux conflit `agent.state.conflict` au compilateur.

**Action** : clé de catalogue **déterministe** `FileAssociation::catalogKey(identifier, progid)`, partagée par le seed-migration, la baseline figée ET le parse `default.xml` → une paire identique upsert au lieu de dupliquer. La sémantique « remplacer vs accumuler » pour des ProgId DIFFÉRENTS reste en question ouverte n°1.

## Corrections appliquées (Étape 8)

8 corrections auto + 4 notes documentaires, **sans déranger Henri** :
- **P2** : `sysWow64` → `winDir` + commentaire.
- **P3** : `LEGACY_DEFAULT_XML_PATH` const locale du seeder, import `App\Gpo` supprimé.
- **P5** : réutilisation des modèles `updateOrCreate` (plus de re-`find()`).
- **N2** : `FileAssociation::catalogKey()` déterministe, partagée migration ⇄ seeder.
- **N3** : `down()` dérive les clés du catalogue partagé.
- **P6** : nom de route corrigé (`app.parc-settings.file-associations`).
- **P1 / P7 / N5 / N1** : notes documentaires (vecteurs hash, mémoire shell32, granularité type-level).

**Vérifications post-correction** : `go vet`/`go test ./...`/cross-compile Windows **VERTS** ; `php -l` propre ; **PHPUnit 23/23** (AssociationsStateProvider + FileAssociationsPage + ContractV1).

## Reste à valider sur VM (action humaine)

- Migrations `file_associations` + pivot + seed → **Pending** (`migrate:status` puis `migrate --force`).
- Parse `default.xml` réel du seeder (absent sur l'hôte → seule la baseline figée testée).
- **Validation lab Windows (T9, ACTION HUMAINE Henri)** : 5 scénarios QA 27.3bis — appliqué au logon, drift réimposé, per-user, ProgId absent → choix préservé, parcs différents. **C'est la preuve finale de la fidélité du hash.**

---

## Extension WPKG-aware (T10 + T11) — 2e itération (2026-06-17)

Suite à la discussion avec Henri (`.txt → Notepad`), la story a été **étendue** (D-Henri n°7) : catalogue tagué `native`/`wpkg`, validation **prédictive serveur par parc**. Dev opus, review sonnet ciblée.

**Invariant prouvé** : extension **100% serveur** — `agent/`, golden `state.v1.json`, `frozenStateHash`/`FROZEN_STATE_HASH` **INTOUCHÉS** (`git diff --stat` vide, `go test` cached). Le payload reste `{identifier, progid, type}` ; l'agent ignore native/wpkg.

| Axe review sonnet | Verdict |
|---|---|
| Jointure `packages.xml <package id>` ⇄ `Application::app_id` | ✅ Correcte (invariant `ApplicationXmlReader`, fixtures cohérentes ; non enforced DB = risque préexistant hors story) |
| `deployedPackagesForParc()` (group-level Eloquent, PG-pur) | ✅ Sémantiquement équivalent au resolver, `archived_at` filtré, pas de N+1, pas de `Cache::` |
| NFR7 (provider PG-pur, émet toujours) | ✅ Conforme (D-Henri n°3 préservé) |
| Invariant contrat (payload inchangé) | ✅ Conforme (golden/hash croisés intacts `77fb548…`) |
| Seeder (préférence native, idempotence, tags) | ✅ Correct |
| Sécurité UI (Gate, findOrFail, escape Blade) | ✅ Conforme |

**Seul finding — P1-ext 🟡 (corrigé)** : donnée incohérente `source=wpkg` + `wpkg_package=null` (jamais produite par le code ; nécessiterait une altération DB manuelle) → message UI vide « `« » n'est pas déployé` ». **Garde-fou appliqué** : tooltip + toast affichent « Association mal configurée (paquet source manquant) » quand `wpkg_package` est vide. Test UI 34/34 vert.

**Vérifs 2e itération** : PHPUnit **34/34** (157 assertions) ; `go test ./...` cached (agent intouché) ; NFR7 grep provider VIDE ; `git diff --stat agent/ golden/` VIDE. **Verdict sonnet : OK pour merge sur main.**

### Reste à valider sur VM (extension)
- `migrate --force` : colonnes `source`/`wpkg_package` (ajoutées à la migration `create`).
- Parse `default.xml` + `packages.xml` **réels** par le seeder (hôte n'a ni l'un ni l'autre).
- Lab : scénario QA **27.3bis.6bis/.7** (validation prédictive UI : native applicable, wpkg non déployé → indisponible, wpkg déployé → applicable).
- ⚠️ Vérifier sur les vrais paquets que `<package id>` == `app_id` (sinon prédiction UI faussée ; l'agent reste le dernier rempart).
