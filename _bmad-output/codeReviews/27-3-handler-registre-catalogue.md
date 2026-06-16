# Code Review — Story 27.3 : Handler registre — catalogue de réglages par parc

Status: to-validate
Date: 2026-06-16
Dev model: opus (claude-opus-4-8, effort xhigh)
Review model: sonnet
Second review: oui (opus, effort xhigh)

> **NB numérotation** : `27-3.md` (même dossier) = trace de la story drift-policy **annulée** (superseded 27.8).
> CE document concerne la VRAIE story 27.3 (registre) — d'où le slug complet.

## Contexte

Story 27.3 livre le type `registry` au canal agent : catalogue serveur `registry_settings` + pivot, DEUX
providers (machine HKLM / session HKCU), UN handler Go générique, UI parc, contrat §7 + golden + hash croisé.

Décisions Henri figées à la validation : **D-Q1** (3 réglages catalogue), **D-Q2** (2 providers / 1 handler),
**D-Q3** (inversion GLOBALE de la précédence `logique > physique`), **D-ciblage** (UI par parc, pivot complet).

Verdict Sonnet : **APPROUVÉ AVEC RÉSERVES** (11 findings). Second avis Opus : **aucun 🔴 confirmé, story
mergeable** ; 2 risques critiques pressentis (applied-state partagé SYSTEM↔compagnon ; double-rapport) **écartés
par le code** (design correct) ; 2 problèmes manqués par Sonnet relevés (M1 doc, M2 test).

## Questions en attente (décision Henri)

> Aucune n'est bloquante pour le merge. Décisions de design / scope.

1. **Direction long terme du `MachineEngine`.** La portée `machine` (registre HKLM) a nécessité un PREMIER
   moteur de convergence côté service SYSTEM (`Agent.MachineEngine` + `convergeMachine()` dans `loop.go`,
   câblé `main_windows.go`). UN seul `Engine` pour toute la portée machine (un futur type machine = un handler
   de plus dans la map). OK comme direction, ou à terme un moteur par type ? (Sonnet Q1)

2. **Item `registry` portée `machine` dans le golden — maintenant ou plus tard ?** Le golden ne porte qu'un
   item `registry` en portée `session` (HKCU). L'équivalence de hash PHP↔Go pour le PAYLOAD registry est déjà
   prouvée (le hash d'item NE dépend PAS de la portée — l'item = `{type,semantics,payload,hash}`, scope =
   enveloppe). L'orchestration machine est désormais couverte par 4 tests Go (M2). Ajouter un item HKLM au
   golden = belt-and-suspenders mais re-bump du `FROZEN_STATE_HASH`. Jugé **non nécessaire** — à confirmer
   (sinon je l'ajoute et re-bumpe). (Sonnet Q2 / finding #4)

3. **Isolement par établissement des réglages parc.** La gate `app.customize` autorise l'assignation à
   n'importe quel parc, sans scoping établissement (iso `overlay-messages` et les autres pages `parc-settings`).
   Cohérent avec l'existant — à acter comme limite v1 ? (Sonnet Q3)

4. **D-Q3 = changement de comportement d'une story `done` (27.2).** L'inversion globale `logique > physique` a
   modifié la résolution de l'imprimante par défaut (27.2) — tests mis à jour sciemment. Tu valides ce
   changement de comportement livré au passage de 27.3 ?

## Synthèse des problèmes

| # | Problème | Sévérité | Pertinence Opus | Statut |
|---|----------|----------|-----------------|--------|
| M2 | `convergeMachine`/`MachineEngine` (code neuf) sans test direct | 🟠 | 3 | ✅ Corrigé (4 tests Go) |
| 3 | Rang logique/physique dupliqué dans `PrintersStateProvider` (couplage `specificity()`) | 🟠 | 2 | ✅ Corrigé (commentaire de couplage) |
| M1 | `registry` machine+session collapsé en 1 ligne d'état → flap `eventDue` + perte granularité | 🟠 | 3 | ✅ Documenté (limite connue) |
| 1 | `MergeReportItemsByType` s'applique à tous les types (fusion type-large) | 🟠 | 2 | ✅ Documenté (volontaire ; cf. M1) |
| 4 | Golden sans item `registry` portée machine (HKLM) | 🟠 | 2 | ⏳ Décision Henri (adressé via M2 ; re-bump optionnel) |
| 2 | applied-state machine via `WriteFileAtomic` (pas de re-pose ACL par fichier) | 🟠→🟡 | 1 | ✅ Commenté (sûr — ACL héritée du répertoire) |
| 7 | `REG_EXPAND_SZ` comparé en littéral non développé → drift permanent possible | 🟡 | 2 | ✅ Documenté (limite connue) |
| 5 | `mailleFor()` : WG physique+logique simultané classé Physical (théorique) | 🟡 | 1 | ✅ Corrigé (docblock disjonction) |
| 8 | `REG_QWORD` cast `(int)` tronquerait sur PHP 32 bits | 🟡 | 1 | ✅ Corrigé (commentaire 64 bits) |
| 9 | Seeder `down()` + cascade pivot non documentée | 🟡 | 1 | ✅ Corrigé (commentaire cascade) |
| 11 | Log `convergeMachine` « %d statut(s) » trompeur | 🟡 | 1 | ✅ Corrigé (libellé clé/verdict) |
| 6 | `toggle()` double requête pivot + race multi-admin | 🟡 | 1 | ❌ Non corrigé (v1, idempotent — accepté) |
| 10 | `exclusiveKey()` : `rule_ids` du warning en ordre SQL | 🟡 | 1 | ❌ Non corrigé (constat partiellement inexact — déjà déterministe) |

Légende sévérité : 🔴 Critique — 🟠 Important — 🟡 Mineur
Pertinence Opus : 0 = non pertinent, 1 = peu, 2 = pertinent, 3 = très pertinent, — = pas de second review

## Risques pressentis ÉCARTÉS par le second avis Opus (points de design positifs)

- **applied-state PARTAGÉ SYSTEM↔compagnon : N'EXISTE PAS.** Service → `C:\ProgramData\…\applied-state.json`
  (`Store.AppliedStatePath`), compagnon → `%LOCALAPPDATA%\…\applied-state.json` (`UserStore`). Deux fichiers
  distincts → aucun écrasement croisé.
- **Double POST /report (machine + session séparés) : IMPOSSIBLE.** Un SEUL process poste le rapport (service
  SYSTEM) ; le compagnon n'a aucun code réseau. `MergeReportItemsByType` agit dans l'unique producteur → suffit.
- **Régression wallpaper/exclusive non-keyed : ÉCARTÉE.** Un exclusive SANS `KeyedExclusiveProvider` retourne
  exactement 1 item (test `non_keyed_exclusive_keeps_single_winner_for_the_whole_type`).
- **Hash croisé PHP↔Go : RÉEL.** Recalcul indépendant des deux côtés sur le même golden + même constante
  (`2b49f008…`) — équivalence de canonicalisation prouvée structurellement (NFC, clés numériques, {} vs []).
- **NFR7 :** grep `ldap|apcu|samba-tool` sur les 3 providers registry = vide. Ciblage 100 % Postgres.

## Détail des corrections appliquées (étape 8)

### M2 — `convergeMachine`/`MachineEngine` sans test direct → ✅ Corrigé
`agent/shared/loop_machine_test.go` (neuf) : 4 tests hôte — apply HKLM + drain rapport + persistance
applied-state + idempotence (2e passe = 0 écriture, compliant) ; moteur nil = no-op ; cache absent = no-op ;
portée machine vide = no-op. Tous verts.

### #3 — Couplage rang logique/physique → ✅ Corrigé
`PrintersStateProvider::resolveDefaultCupsName` : commentaire de couplage avec `StateCompiler::specificity()`.

### #11 / #2 — Log + ACL applied-state machine → ✅ Corrigé / Commenté
`loop.go convergeMachine` : libellé « %d clé(s), %d verdict(s) de type » ; commentaire justifiant la sûreté de
`WriteFileAtomic` (répertoire ProgramData déjà `inheritance:r` SYSTEM+Admins, contenu = hashes opaques).

### #5 / #8 / #9 — Docblocks défensifs → ✅ Corrigé
`AbstractRegistryStateProvider::mailleFor` (disjonction physique/logique), `typedValue` (PHP 64 bits),
seeder `down()` (cascade pivot).

### M1 / #7 / #1 — Limites connues → ✅ Documenté
`docs/agent/state-providers.md` §registry « Limites connues » : (a) `REG_EXPAND_SZ` comparé en littéral non
développé (drift permanent si valeur stockée développée) ; (b) collapse `registry` machine+session en une ligne
d'état (flap `eventDue`, un seul `detail` survit) — raffinement futur = portée au rapport.

## Problèmes laissés ouverts (mineurs — assumés)

- **#6** : `toggle()` 1 requête pivot en trop + race multi-admin — neutralisé par l'UNIQUE +
  `syncWithoutDetaching` idempotent ; acceptable v1 (usage mono-admin).
- **#10** : ordre des `rule_ids` du warning — Opus a établi que l'ordre est DÉJÀ déterministe ; rien à corriger.

## Tests après corrections

- **Go** : `go test ./...` (shared OK, 2.5 s) + `go vet` linux + `GOOS=windows go vet` + cross-compile windows
  (10 797 568 octets) — **tous verts**. 4 tests M2 neufs verts.
- **PHP (hôte)** : suite ciblée `Registry*|StateCompiler|ContractV1|PrintersStateProvider|RegistrySettingsPage`
  = 56 tests / 212 assertions, **2 erreurs `ldap_search` PRÉ-EXISTANTES** (factory WG → observer LDAP sur hôte
  sans annuaire : `target_context_resolves_memberships_from_postgres_relations`,
  `full_compile_with_real_providers_and_a_fake_one_respects_contract_invariants`) — à rejouer `/vm`.
- **php -l** : OK sur tous les PHP touchés.

## Actions /vm (action humaine Henri — jamais depuis le worktree)

1. `php artisan migrate:status` puis `php artisan migrate --force` — 3 migrations (registry_settings + pivot +
   seeder catalogue).
2. `php artisan route:cache` + chown www-admin — **1 route ajoutée** (`app.parc-settings.registry-settings`).
3. Pas de `config:cache` (aucun `config/*.php`).
4. Rejouer la suite PHPUnit `--filter Agent` sur la VM (confirmer hors-host les 2 erreurs LDAP + non-régression).
5. Validation lab Windows : HKLM appliqué par SYSTEM ; HKCU au logon par compagnon ; valeur modifiée à la main
   réimposée (drift STRICT) ; même clé sur 2 parcs → maille la plus spécifique gagne (logique > physique).
