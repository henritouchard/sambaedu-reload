# Story 27.13 : Capacité non-registre bout-en-bout — firewall « blocage Internet examen »

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **ℹ️ DÉPEND DE 27.12** (modèle capacités : `capabilities`/`capability_projections`/`capability_assignments`,
> `AbstractCapabilityStateProvider`, interpréteur de `spec`). Cette story **prouve le modèle mécanisme-agnostique**
> en livrant la **1ʳᵉ capacité NON-registre bout-en-bout** : « Blocage Internet examen » via le mécanisme
> **`firewall`**. Contrairement à 27.12 (contract-clean), elle **touche le contrat figé et l'agent** : c'est le
> coût réel, assumé, d'un nouveau mécanisme (cf. découverte 27.12 sur `StateContract::RESOURCE_TYPES`).
>
> **Pourquoi firewall et pas proxy** (tranché en conception) : le blocage examen legacy (`no_internet_out.php`)
> est **dynamique** (signal True/False, pas une GPO) ; un pare-feu est **instantané, non contournable par
> l'élève, indépendant du navigateur** — là où un proxy registre est faible (contournable, ne couvre pas
> Firefox, latence WinINET). C'est l'avantage « l'agent fait mieux que la GPO ».

## ✅ DÉCISIONS HENRI — TRANCHÉES

> **D1 — Bout-en-bout maintenant.** On ne se contente pas de la preuve *structurelle* (le compilateur émet
> `{type:firewall}`) : l'agent **applique réellement** la règle sur le poste. Donc : ajout du type au contrat +
> handler Go + ingestion rapports + golden croisé.
>
> **D2 — `firewall` = ajout ADDITIF au contrat figé (NFR12).** `StateContract::RESOURCE_TYPES += 'firewall'`
> (constante additive autorisée : seuls golden + `contract-v1.md` + hash sont « intouchables » au sens
> « pas de renommage » — un AJOUT est permis avec golden mis à jour). Conséquences : ingestion rapports (24.1)
> doit accepter `firewall` (sinon 422) ; golden `state.v1.json` + `FROZEN_STATE_HASH` PHP↔Go **bumpés croisés**
> SI le golden inclut un item firewall.
>
> **D3 — La capacité examen.** `key=block_internet_exam`, `value_type=toggle`, **`default_value="off"`**
> (diffusion « off » = **aucune** règle posée par défaut, cf. D5 : la valeur effective `off` est **absente** de
> la map → rien n'est émis). L'examen est un **override `on`** sur le parc `postes_exam` (et/ou un groupe user
> `no_internet`) → maille spécifique → la règle est posée. « Retirer/repasser à off » = la règle est retirée à la
> convergence suivante.
>
> **D4 — Sémantique `firewall` = Exclusive (non-keyed).** Un poste a UN posture pare-feu « examen » à un instant
> donné : la maille la plus spécifique gagne (comme `wallpaper`). PAS `KeyedExclusiveProvider`.
>
> **D5 — Le LIFELINE est non négociable.** La règle `block_egress` DOIT **toujours autoriser** : le **serveur
> SE5** (sinon l'agent ne peut plus poller pour *lever* le blocage → poste brické), l'**AD/DNS/DHCP**, le
> **loopback**. La liste d'autorisation (`allow`) est **injectée par le provider** depuis la config serveur
> (hôte SE5, DC) — PAS saisie par l'admin, PAS dépendante de substitution de variables. C'est le garde-fou
> central de la story.

## Story

En tant qu'**admin d'établissement**,
je veux **activer le « blocage Internet examen » sur un parc** (capacité, vocabulaire métier),
afin que **les postes concernés perdent l'accès Internet de façon instantanée et non contournable pendant les
épreuves**, sans que je touche à une règle de pare-feu ni à un proxy — et **sans jamais couper le lien
agent↔serveur** (le blocage doit pouvoir être levé).

## Contexte & intention

27.12 a posé le modèle capacités avec une seule projection (`registry`). Cette story ajoute le **2ᵉ mécanisme**
(`firewall`) **de bout en bout** — c'est la validation que « capacité » n'est pas « du registre mieux nommé » :
la même capacité, le même compilateur, le même contrat d'enveloppe, mais un item `{type:firewall}` appliqué par
un **nouveau handler Go**. Le legacy faisait ça par un signal dynamique + script client ; on le fait par
desired-state convergé (instantané, observable, réversible).

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **🔴 LIFELINE (D5).** Un `block_egress` naïf coupe l'agent de son serveur → impossible de lever le blocage =
   poste brické jusqu'à intervention physique. La règle **doit** autoriser SE5 + AD/DNS/DHCP + loopback,
   **injectés par le provider** (config serveur). **Test obligatoire** : le payload firewall émis contient
   toujours l'hôte SE5 dans `allow`. C'est l'AC le plus important.
2. **🔴 Contrat figé — ajout additif (D2).** `RESOURCE_TYPES += 'firewall'` ; ingestion rapports (24.1) doit
   accepter `firewall` (étendre la liste/validation) ; **golden croisé PHP↔Go** (NFR13) : relever d'abord la
   valeur courante, bumper `state.v1.json` + `FROZEN_STATE_HASH` (PHP) + `frozenStateHash` (Go) **ensemble**,
   golden files mis à jour, `contract-v1.md` §7 enrichi d'une section `firewall`. **Jamais** de renommage d'un
   type existant.
3. **Off = rien (D3/D5).** `default_value="off"` → la valeur effective `off` est **absente** de la map de la
   projection → **aucun** item émis (pas de « règle allow-all »). Le pare-feu n'est posé QUE sur override `on`.
   Ne PAS émettre un item « désactivé ».
4. **Sémantique Exclusive non-keyed (D4).** `FirewallCapabilityProvider::semantics()=Exclusive`, **pas**
   `KeyedExclusiveProvider`. Le `StateCompiler::selectExclusive()` élit alors UN gagnant pour le type (maille la
   plus spécifique). Vérifier que ce chemin (sans `exclusiveKey`) fonctionne (il existe déjà pour `wallpaper`).
5. **Scope = Machine.** Le pare-feu est machine-wide → `scope()=Machine`, appliqué par le **service SYSTEM**
   (pas le compagnon). Pas de notion HKCU.
6. **Live = pas de logon requis.** Une règle pare-feu prend effet **immédiatement** (contrairement aux prefs
   Explorer HKCU). C'est l'argument de la story — le tester (ou le documenter pour la validation lab).
7. **Idempotence + réversibilité.** `handler_firewall` : test-then-apply (règle déjà conforme → no-op) ; et
   **retrait** quand l'item disparaît du contrat (off) — ATTENTION : c'est un mécanisme qui DOIT savoir
   **retirer** sa règle (sinon le blocage reste après l'examen). Contrairement au handler registre (qui ne
   supprime pas), le handler firewall **gère le cycle de vie complet** de SA règle (taggée, ex. nom
   `SE5-exam-block`) : présente si item émis, absente sinon. Documenter cet écart de cycle de vie.
8. **Go = hôte uniquement** (`project_host_go_toolchain_path`), **jamais** de VM depuis le worktree. Build/test
   Go sur l'hôte ; cross-compile `GOOS=windows`. Validation poste réel = action humaine (lab).
9. **Firefox/onglets ouverts.** Une connexion déjà établie peut survivre un court instant ; documenter (la règle
   bloque les nouvelles connexions sortantes). Acceptable pour l'examen.

## Acceptance Criteria

### AC1 — Contrat : type `firewall` ajouté (D2)
**Then** `StateContract::RESOURCE_TYPES` contient `'firewall'` ; `docs/agent/contract-v1.md` §7 décrit le payload
firewall ; l'ingestion rapports (Story 24.1) **accepte** `firewall` (plus de 422 sur ce type) **And** golden
`tests/Fixtures/Agent/state.v1.json` + `ContractV1Test::FROZEN_STATE_HASH` (PHP) + `frozenStateHash` (Go)
**bumpés croisés** avec preuve (valeur avant/après) **si** le golden inclut un item firewall ; sinon conclusion
explicite « golden inchangé » justifiée.

### AC2 — Capacité examen + projection firewall (D3, D5)
**Then** une migration de données crée `block_internet_exam` (`value_type=toggle`, `default_value="off"`,
`applies_to_os=["windows"]`, `warning` explicite) + une `capability_projection` `(os=windows,
mechanism=firewall)` dont la `spec` mappe **`{"on": { "action": "block_egress" }}`** (`off` absent → rien) **And**
la liste `allow` (lifeline) **n'est PAS** dans la spec figée : elle est **injectée par le provider** (D5).

### AC3 — Provider firewall (D4, D5)
**Given** `block_internet_exam` overridé `on` sur le parc `postes_exam` (assignation) **When**
`FirewallCapabilityProvider` (base `AbstractCapabilityStateProvider`, `type()='firewall'`,
`semantics()=Exclusive` **non**-keyed, `scope()=Machine`, lecture Postgres pure) compile **Then** il émet un
`StateCandidate` (maille du parc) `payload = { action:"block_egress", allow:[<hôte SE5>, <AD/DNS/DHCP>,
loopback, …] }` — **`allow` injecté depuis la config serveur** **And** par défaut (`off`, pas d'override) →
**aucun** candidat (D3) **And** candidats bruts (D2), `StateCompiler` INTOUCHÉ.

### AC4 — Compilateur : examen gagne par maille
**Given** override `on` (parc) + défaut `off` **When** `StateCompiler` compile **Then** l'item firewall est émis
pour les postes du parc examen, absent ailleurs ; deux mailles → la plus spécifique gagne (Exclusive). Aucune
modif du compilateur.

### AC5 — Handler Go `firewall` (D5, pièges n°5/7)
**Then** `agent/shared/handler_firewall.go` (générique testable hôte : parse `{action, allow}`, test-then-apply,
**cycle de vie complet de SA règle** taggée `SE5-exam-block` — présente si item, **retirée** sinon) + interface
`FirewallOps` ; `agent/windows/handler_firewall_windows.go` (implémentation Windows Firewall — règle de blocage
sortant avec exceptions `allow`) ; enregistré dans `engine.go` côté **service SYSTEM** (scope Machine) **And**
**idempotent** (règle conforme → no-op) **And** **lifeline respecté** : la règle générée laisse toujours passer
les hôtes de `allow` (sinon l'agent se couperait lui-même — test unitaire Go).

### AC6 — UI
**Then** la capacité `block_internet_exam` apparaît dans l'onglet « Options/Capacités » du parc (toggle), avec
son **`warning`** (« coupe tout accès Internet sortant des postes du parc sauf services internes ; pour les
épreuves ») ; override `on`/retrait via le même flux que 27.12.

### AC7 — Tests
**Then** Laravel : `FirewallCapabilityProviderTest` (override `on` → item avec `allow` contenant l'hôte SE5 ;
`off`/défaut → rien ; Exclusive ; scope Machine ; NFR7) ; `StateCompilerTest` (examen gagne par maille) ;
`ContractV1Test` (type `firewall` accepté, golden conforme à AC1) ; ingestion (type `firewall` non rejeté). Go :
`handler_firewall_test.go` (test/apply/retrait idempotents ; **lifeline** : `allow` toujours préservé) + golden
croisé. `go test ./...` + `GOOS=windows` cross-compile verts.

### AC8 — Docs/QA + validation lab
**Then** `docs/agent/contract-v1.md` (§firewall), `docs/agent/state-providers.md` (mécanisme firewall),
`docs/qa/domains/agent.md` `## Story 27.13` (append-only) ; **Actions /vm** listées (migrate + route:cache si UI,
**publier l'agent** incluant `handler_firewall` — release agent requise) ; **Validation lab (action Henri)** :
poste du parc examen perd Internet **instantanément** mais **reste joignable par l'agent** (lifeline) ; retrait →
accès rétabli au cycle suivant.

## Tasks / Subtasks
- [ ] **T1 — Contrat** (AC1) : `RESOURCE_TYPES += 'firewall'` ; ingestion 24.1 accepte ; `contract-v1.md` §firewall.
- [ ] **T2 — Capacité + projection** (AC2) : migration de données `block_internet_exam` + projection firewall.
- [ ] **T3 — Provider** (AC3, AC4) : `FirewallCapabilityProvider` (Exclusive non-keyed, scope Machine, **injection
      lifeline** depuis config). Enregistrer dans `AgentServiceProvider`.
- [ ] **T4 — Handler Go** (AC5) : `shared/handler_firewall.go` + `FirewallOps` + `windows/handler_firewall_windows.go`
      + `engine.go` (SYSTEM). Cycle de vie complet de la règle taggée. Lifeline.
- [ ] **T5 — Golden croisé** (AC1) : relever, bumper `state.v1.json` + `FROZEN_STATE_HASH` (PHP) + `frozenStateHash`
      (Go) ensemble, preuve.
- [ ] **T6 — UI** (AC6) : capacité dans l'onglet (réutilise 27.12, toggle + warning).
- [ ] **T7 — Tests** (AC7) PHP + Go.
- [ ] **T8 — Docs/QA + actions /vm + lab** (AC8) — release agent incluse (handler dans le binaire publié, cf.
      `project_agent_handler_not_in_published_binary`).

## Dev Notes
### References
- [Source: `_bmad-output/implementation-artifacts/27-12-config-capacites-registre-capability-first.md`] — modèle
  capacités (prérequis).
- [Source: `app/Services/Agent/StateContract.php` (RESOURCE_TYPES), `StateCompiler.php` (Exclusive non-keyed,
  chemin `wallpaper`), `Providers/WallpaperStateProvider.php` (patron Exclusive non-keyed)].
- [Source: `agent/shared/handler_registry.go` (+ `handler_registry_windows.go`) — patron handler shared+windows ;
  `agent/shared/engine.go` (enregistrement handlers, scope) ; `agent/shared/contract.go`].
- [Source: ingestion rapports Story 24.1 — `app/Services/Agent/Reporting/ReportIngestService.php` (validation des
  types)].
- [Source: golden — `tests/Fixtures/Agent/state.v1.json`, `tests/Unit/Services/Agent/ContractV1Test.php`,
  `agent/shared/hasher_test.go`].
- [Source: mémoires `project_legacy_gpo_registry_inventory` (examen = dynamique, pas GPO),
  `project_registry_apply_effect_next_logon` (live-apply par mécanisme), `project_config_capabilities_model`,
  `project_agent_handler_not_in_published_binary`, `project_host_go_toolchain_path`, `feedback_worktree_no_vm_sync`].

### Périmètre — livré / hors-scope
| Livré (27.13) | Hors-scope |
|---|---|
| Type contrat `firewall` + ingestion + golden | Proxy registre WinINET (écarté au profit du pare-feu) |
| Capacité `block_internet_exam` + projection + provider (lifeline) | Firefox/policies (couvert ailleurs, 27.4) |
| Handler Go `firewall` (shared + windows, cycle de vie complet) | Mécanisme `localgroup` (admin local) → 27.14 |
| UI (toggle + warning) ; release agent | Projection Linux du pare-feu → futur |

## Recommandation Modèle Dev
**`opus`.** Story **contrat + agent** (ajout de type figé, golden croisé PHP↔Go, handler Go avec cycle de vie et
**lifeline critique**). Risque majeur = bricker un poste (lifeline manquant), casser le golden, oublier le retrait
de règle. Le réflexe « contrat → petit modèle » ne s'applique pas (raisonnement systèmes + sécurité réseau).

## Dev Agent Record
_(à remplir par le dev)_

## Change Log
- 2026-06-18 — Story **rédigée** (orchestrateur). 1ʳᵉ capacité non-registre bout-en-bout (firewall examen) ;
  dépend de 27.12. Touche le contrat figé (`RESOURCE_TYPES += 'firewall'`) + agent (handler Go). Lifeline =
  garde-fou central. **Status: ready-for-dev.**
