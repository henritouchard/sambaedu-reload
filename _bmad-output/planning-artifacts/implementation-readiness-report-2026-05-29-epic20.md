---
stepsCompleted: [step-01-document-discovery, assessment-epic20, complete]
verdict: 'GO conditionnel — bloquants sécurité archi levés (H1/M1/M4), reste H2 en prépa story'
findingsCount: { high: 2, medium: 5, low: 4 }
findingsResolved: [H1, M1, M4, L2]
scope: 'Epic 20 — Authentification fédérée d''utilisateurs externes'
date: '2026-05-29'
project_name: 'codebase'
filesAssessed:
  - _bmad-output/planning-artifacts/prd.md
  - _bmad-output/planning-artifacts/architecture.md
  - _bmad-output/planning-artifacts/epics.md
---

# Implementation Readiness Assessment Report

**Date:** 2026-05-29
**Project:** codebase
**Périmètre :** Epic 20 — Authentification fédérée d'utilisateurs externes (techniciens flotte)

## Inventaire documentaire (Step 1 — Document Discovery)

| Type | Fichier | Format | Statut |
|---|---|---|---|
| PRD | `prd.md` | document unique | ✅ complet (`step-12-complete`) |
| Architecture | `architecture.md` | document unique | ✅ complet + section Epic 20 (2026-05-29) |
| Epics & Stories | `epics.md` | document unique | ✅ Epic 20 présent (5 stories, 🟡 cadrage) |
| UX Design | — | — | ⚠️ aucun (non bloquant — voir analyse) |

**Doublons :** aucun. **Dossiers shardés :** aucun. **Conflits critiques :** aucun.

## Traçabilité PRD ↔ Architecture ↔ Stories

### Alignement PRD

| Élément PRD | Statut vis-à-vis Epic 20 |
|---|---|
| Principe « Aucune notion de "central" ne doit exister dans SER » | ✅ **Respecté** — recadrage domain-neutral (IdP externe configurable) ; Story 20.1 AC « zéro controlHub codé en dur ». |
| Persona « techniciens DSI académiques qui supervisent la flotte » (Executive Summary) | ✅ Le technicien flotte externe correspond à ce persona déjà nommé dans le PRD. |
| Liste des FRs (35 FRs) | ⚠️ **Aucune FR n'adresse la fédération d'identité externe.** Epic 19 référence FR40 ; Epic 20 n'a pas de FR rattachée → traçabilité incomplète (cf. L1). |
| SER standalone / open-source | ✅ La capacité IdP externe est générique, ne crée pas de dépendance dure à irundoo. |

### Alignement Architecture

| Décision Architecture | Couverture story |
|---|---|
| `AuthGuardInterface` (Story 1.4) + slot Phase 2 | ✅ Story 20.1 s'y branche (`FederatedIdpAuthGuard`). |
| ① Redirect JWT signé / ② guard distinct / ③ session standard + TTL court | ✅ Tranché 2026-05-29, documenté en section archi Epic 20. |
| Contrat = rôle, pas permissions | ✅ Story 20.3 AC. |
| Identité persistante ≠ accès / audit dénormalisé | ✅ Stories 20.2 + 20.4. |
| Table « Auth & Sécurité » (résumé) | ⚠️ Non mise à jour pour référencer le guard fédéré (cf. L2). |
| Versioning API `/api/v1/` | ⚠️ Emplacement/route de l'endpoint de fédération non figé (cf. L3). |

## Constats — par sévérité

| # | Sévérité | Constat | Action |
|---|---|---|---|
| **H1** | 🔴 Haute | **Surface d'attaque JWT non verrouillée.** L'archi dit « vérifie la signature » mais ne **pin pas l'algorithme**, n'interdit pas explicitement `alg:none` / la confusion d'algorithme (RS256↔HS256), et ne liste pas la validation `aud`/`iss`/`exp`/`nbf`. Faille JWT classique et grave. | Pin algo (RS256 ou EdDSA), rejet `alg:none`, validation stricte des claims standard. À intégrer en archi + AC de Story 20.1. |
| **H2** | 🔴 Haute | **Pas d'AC de tests sécurité** sur le guard, malgré la règle projet « tests unitaires + E2E par feature » et la sensibilité auth. | Ajouter des AC : signature forgée, jeton expiré, émetteur inconnu, rôle inconnu, rejeu → tous refusés explicitement. |
| **M1** | 🟠 Moyenne | **Distribution de la clé publique controlHub non spécifiée** (config statique vs endpoint JWKS, rotation de clé). | Trancher au démarrage de Story 20.1. |
| **M2** | 🟠 Moyenne | **Couplage fort 20.1 ↔ 20.2** : le guard ne peut ouvrir de session réelle sans le modèle `ExternalIdentity`. La séquence actuelle (20.1 puis 20.2) est incohérente. | Faire de 20.2 un prérequis *dans* 20.1, ou fusionner la persistance minimale dans 20.1. |
| **M3** | 🟠 Moyenne | **Sémantique des rôles cibles non vérifiée.** Le contrat porte un nom de rôle, mais quels rôles Spatie sont des cibles valides, et le rôle `technicien` existant accorde-t-il le périmètre adapté à un technicien flotte ? Non établi. | Décision produit avant Story 20.3 (réutiliser `technicien` ou définir un rôle dédié). |
| **M4** | 🟠 Moyenne | **Protection anti-rejeu & tolérance d'horloge** non spécifiées (avec un TTL court, le `jti`/nonce et le clock-skew comptent). | Spécifier en Story 20.1. |
| **M5** | 🟠 Moyenne | **Tension RGPD** : « `ExternalIdentity` jamais hard-delete » (audit) vs droit à l'effacement / minimisation. Base légale de conservation non énoncée. | Énoncer la base de rétention (probable obligation d'audit en contexte éducation) dans Story 20.2/20.4. |
| **L1** | 🟡 Basse | Pas de FR PRD pour la fédération externe (persona présent, FR absente). | Ajouter une FR au PRD, ou noter explicitement « capacité post-PRD ». |
| **L2** | 🟡 Basse | Table « Auth & Sécurité » du doc archi non mise à jour. | Ajouter une ligne « Auth fédérée externe → `FederatedIdpAuthGuard` (Epic 20) ». |
| **L3** | 🟡 Basse | Route/versioning de l'endpoint de fédération non figés (`/api/v1/` vs route web). | Pin en Story 20.1. |
| **L4** | 🟡 Basse | Comportement de logout / fin de session externe non couvert explicitement. | Confirmer que le logout SE5 standard s'applique. |
| **L5** | 🟢 Info | Aucun doc UX. **Non bloquant** — Epic 20 quasi-backend, seule surface = redirect navigateur. | Aucune. |

## Verdict de readiness

**🟡 GO CONDITIONNEL — prêt pour la préparation de stories, pas encore "ready-for-dev".**

- **Fondations saines** : les 3 décisions d'archi sont cohérentes, alignées avec le principe fondateur (domain-neutral respecté) et **réutilisent une abstraction déjà construite** (`AuthGuardInterface`). Pas de pilier nouveau, pas de contradiction inter-artefacts. La logique de séquencement des stories est globalement correcte.
- **Bloquants avant que Story 20.1 passe ready-for-dev** : **H1** et **H2** (sécurité JWT + tests) doivent être intégrés aux AC de la story / à la section archi. Ce sont des exigences de sécurité non négociables sur du code d'auth.
- **À trancher au kickoff de story** : M1-M5 (clé publique, couplage 20.1/20.2, rôles cibles, anti-rejeu, base RGPD).
- **Cosmétique / traçabilité** : L1-L4, à nettoyer quand pratique.

**Recommandation :** avant le dev, faire un court passage de durcissement sur Story 20.1 (intégrer H1/H2 dans les AC + figer M1/M4), puis préparer la story via `bmad-sm` / `create-story`. Le reste (M3, M5) se tranche en début de story concernée.

## Mise à jour 2026-05-29 — durcissement archi appliqué

Section archi « Authentification Fédérée » enrichie d'un bloc **« Sécurité du jeton JWT »** + table « Auth & Sécurité » rafraîchie. Statut des constats :

| # | Statut | Détail |
|---|---|---|
| **H1** | ✅ **Traité (archi)** | Algo pinné `RS256`, rejet `alg:none`/symétriques, validation `iss`/`aud`/`exp`/`nbf`/`iat`, `aud` lie le jeton à l'instance. Reste à reporter en **AC de Story 20.1**. |
| **M1** | ✅ **Traité (archi)** | Clé publique statique en config (MVP) + rotation par `kid` ; JWKS = évolution. |
| **M4** | ✅ **Traité (archi)** | `jti` à usage unique (cache TTL) + tolérance d'horloge ±60 s. |
| **L2** | ✅ **Traité (archi)** | Ligne « Auth fédérée externe » ajoutée à la table « Auth & Sécurité ». |
| **H2** | ⏳ Ouvert | AC de tests sécurité → à écrire dans Story 20.1 (prépa de story). |
| **M2, M3, M5** | ⏳ Ouvert | À trancher au kickoff des stories 20.1/20.2/20.3. |
| **L1, L3, L4** | ⏳ Ouvert | Cosmétique/traçabilité, quand pratique. |

**Verdict mis à jour :** les bloquants de sécurité **archi** (H1, M1, M4) sont levés. Reste **H2** (AC de tests) à intégrer lors de la création de Story 20.1 pour atteindre « ready-for-dev ».

