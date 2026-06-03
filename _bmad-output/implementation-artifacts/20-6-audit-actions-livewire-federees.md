# Story 20.6 : Audit des actions Livewire fédérées

Status: backlog

> **Story de suivi d'Epic 20**, issue de la review de la Story 20.4 (« Logs d'audit dénormalisés des actions externes »). 20.4 a posé la **fondation** de l'audit dénormalisé (table `external_action_audit_logs` + dénormalisation login/sub/nom/rôle + capture par middleware HTTP `federated.audit`). Elle couvre l'**accès aux écrans à PII élève** (GET sensibles) et les **mutations via routes HTTP classiques**. **Elle ne couvre PAS les mutations passant par le canal Livewire** (`livewire/update`), qui portent pourtant le gros des actions admin natives dans ce projet Livewire-first.

## Contexte (constat 20.4)

- Le projet est **Livewire-first** (CLAUDE.md : front via SFC Livewire). Les mutations admin natives (CRUD utilisateur, reset mot de passe, attribution de droits via `/rights-management`, actions parc…) sont des **méthodes de composants Livewire** → elles POSTent toutes sur l'endpoint **unique** `livewire/update`, **hors** des groupes de routes `app`/`admin` où le middleware `federated.audit` (20.4) est branché.
- **Conséquence** : un technicien externe fédéré qui modifie l'état via l'UI Livewire **n'est pas journalisé** par 20.4.
- **Pourquoi 20.4 n'a pas simplement branché le middleware sur `livewire/update`** : un audit HTTP de cet endpoint produirait des lignes `POST /livewire/update` **sans libellé d'action exploitable** (chaque interaction Livewire — même non mutante : `wire:model.live`, ouverture de modale — est un POST). Bruit > signal. Un audit *signifiant* doit s'accrocher au **cycle de vie Livewire** (nom du composant + méthode appelée + arguments), pas au middleware HTTP.
- Le test d'architecture `FederatedAuditCoverageTest` (20.4) trace `livewire/update` en allowlist d'exceptions documentée → cette story lève cette exception.

## Objectif

As a **DPO / responsable du traitement**,
I want **que les actions d'administration mutantes réalisées par un technicien externe fédéré via les composants Livewire soient journalisées dans le même journal d'audit dénormalisé que les actions HTTP classiques (login + id externe + nom + rôle actif + libellé d'action lisible : composant + méthode)**,
so that **l'imputabilité des mutations couvre le canal réel de l'UI native (Livewire), pas seulement les routes HTTP classiques**.

## Pistes techniques (à cadrer en create-story)

- **Hook cycle de vie Livewire 3** : `Livewire::listen('component.calling', ...)` / hooks de composant (`Livewire::componentHook(...)`), ou un `ComponentHook` custom, pour capturer (nom du composant, méthode appelée, arguments non-PII pertinents) au moment de l'appel d'action — et n'auditer que les **méthodes mutantes** (pas les updates de propriété/synchro UI).
- **Réutiliser** le modèle `ExternalActionAuditLog` (20.4) et sa méthode `record()` — même table dénormalisée, même invariants (session fédérée uniquement via `FederatedSession::isFederated()`, best-effort fail-soft, `source='federated'`, pas de PII en Monolog). Étendre les colonnes si besoin d'un `action_label` lisible (`<Composant>::<méthode>`).
- **Filtrer le bruit** : ne pas auditer les hydratations/synchros de propriété, seulement les **appels de méthode d'action** (équivalent « mutation »). Définir le critère (méthodes publiques invoquées via `wire:click`/`wire:submit`, hors `updated*`/`mount`/`render`).
- **Domain-neutral strict** (principe fondateur PRD), comme 20.4.
- **Tests host SQLite** ; non-régression stricte de 20.4 et du lot Epic 20.

## Dépendances

- **20.4** (`review`) — fournit le modèle `ExternalActionAuditLog`, le marqueur `FederatedSession`, le channel `federated-auth`, et la convention de dénormalisation. À développer **après** stabilisation de 20.4.
- Framework Livewire 3 (présent).

## Hors-scope

- L'audit des actions AD/LDAP locales (cf. 20.4 Q-3, hors Epic 20).
- La rétention/purge du journal d'audit (cf. 20.4 Q-4, hors MVP).
- Toute UI de consultation du journal.

## Origine

Issue de la review de Story 20.4 (dev-cycle 2026-06-03, arbitrage Henri). Voir `_bmad-output/codeReviews/20-4.md` (découverte du test d'archi `FederatedAuditCoverageTest`) et la limite documentée dans `docs/qa/domains/federated-login.md` § « Limites connues 20.4 ».
