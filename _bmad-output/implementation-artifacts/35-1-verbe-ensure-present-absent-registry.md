# Story 35.1 : Verbe `ensure` — present/absent sur les items registry (socle delete)

Status: review

<!-- Source d'autorité : _bmad-output/planning-artifacts/epics-capacites-v2.md (Epic 35 ne figure PAS dans epics.md). -->

## Story

En tant que **référent numérique**,
je veux **qu'une capacité désactivée puisse SUPPRIMER ses clés de registre**,
afin que **« off » rende la main à Windows au lieu de laisser un résidu ou d'être un no-op interdit**.

## Contexte & intention

**Story SOCLE de l'Epic 35** (Capacités v2 — couverture des GPO spéciales CD95). Le palier A (migration `2026_07_02_100000_seed_capabilities_gpo_cd95_lot.php`, 11 capacités) a montré que le modèle capability-first (27.12) couvre tous les toggles/enums registre à valeur fixe. Le **premier mur** restant : il n'existe pas de verbe `delete`. Conséquences concrètes aujourd'hui :

- « cesser de gérer » (sentinelle UNMANAGED) laisse un **résidu** : la clé garde sa dernière valeur écrite, Windows ne reprend jamais la main ;
- les capacités **on-only** (`llmnr_disabled` du lot CD95, `windows_updates_managed` du lot ISO) ne peuvent pas proposer d'« off » honnête — leurs clés (`NodeType`, bundle WindowsUpdate) n'ont pas de « valeur de restauration » écrite en dur qui soit correcte (elle dépend du réseau / du défaut Windows). L'invariant Henri (« un off proposé fait une VRAIE action », review #2 du lot ISO) leur interdit donc tout « off » → elles sont bloquées en « Géré » perpétuel.

**Ce que la story livre** — les trois régimes coexistent après elle :

1. **écrire** : item `registry` classique `{hive, path, name, type, value}` (inchangé, byte-identique) ;
2. **supprimer** : item `{hive, path, name, ensure: "absent"}` — l'agent supprime la VALEUR si présente, `compliant` si déjà absente, `drift` si elle réapparaît (policy STRICT) ;
3. **ne pas gérer** : sentinelle UNMANAGED (clé de map absente pour la valeur effective ⇒ rien n'est émis) — reste disponible et DISTINCTE.

**Chaîne de bout en bout** : contrat v1 (champ optionnel `ensure`, D1 additif) → interpréteur de `spec` du provider (marqueur réservé `'off' => {'$ensure': 'absent'}`) → handler Go `registry` (Test/Apply/Delete, portées Machine ET Session) → retrofit des deux capacités on-only (vrai « off » par suppression).

**Pourquoi maintenant** : 35.2 (`registry_list`, réconciliation des entrées surnuméraires `\N`) **s'appuie sur ce delete** ; le « off » de 35.5 (`photo_viewer_restored`) l'utilise aussi. C'est le socle de l'epic — aucune dépendance intra-epic en amont.

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **Piège #1 — contrat ADDITIF STRICT (D1), byte-identité des items existants.** `ensure` est un champ **optionnel** ; son absence vaut `present`. Le provider n'émet **JAMAIS** `ensure: "present"` explicitement sur les items d'écriture — sinon TOUS les payloads existants changent de forme → TOUS les hashes d'items changent → drift massif de flotte au déploiement + golden files intégralement réécrits. Un item d'écriture reste EXACTEMENT `{hive, path, name, type, value}` (5 clés) ; un item de suppression est EXACTEMENT `{hive, path, name, ensure}` (4 clés, ni `type` ni `value`).

2. **Piège #2 — StateCompiler INTOUCHÉ (D2).** Zéro ligne dans `app/Services/Agent/StateCompiler.php`. L'identité d'exclusivité reste `exclusiveKey() = {hive|path|name}` minuscules — un item `absent` et un item d'écriture sur la MÊME clé se disputent la clé par la précédence EXISTANTE (ex. broadcast `off`=absent battu par un override de parc `on`=écrit). Aucune sémantique nouvelle au compilateur.

3. **Piège #3 — StateHasher : AUCUN changement de code, mais un test.** La canonicalisation (`sortRecursive` + JSON compact) intègre naturellement tout champ nouveau du payload → deux états qui ne diffèrent que par `ensure` ont déjà des hashes distincts. NE PAS toucher `StateHasher.php` ni `agent/shared/hasher.go` ; AJOUTER les tests qui le prouvent des deux côtés (AC1).

4. **Piège #4 — marqueur `$ensure` vs coercition `typedValue()`.** Aujourd'hui, si une map de `spec` renvoyait un tableau associatif, `typedValue()` le coercerait silencieusement en `0`/`''` (latent). La détection du marqueur `{'$ensure': 'absent'}` doit se faire **APRÈS** `resolveKeyValue()` et **AVANT** `typedValue()` dans `expand()`. Forme reconnue : tableau assoc dont la valeur de `$ensure` est `absent` — toute autre forme assoc inattendue ⇒ **non émis** (défensif, jamais d'exception au render, iso discipline UNMANAGED). Le marqueur est distinct de la sentinelle : marqueur = émettre un item de SUPPRESSION ; sentinelle (clé de map absente) = n'émettre RIEN.

5. **Piège #5 — Go : `parseRegistrySpec` exige aujourd'hui `type` non vide.** Pour un item `ensure:"absent"`, `type` et `value` sont ABSENTS du payload → il faut brancher AVANT l'exigence `type`/`value` : si `ensure == "absent"` → seuls `hive/path/name` sont requis ; `ensure` absent ou `"present"` → parcours actuel inchangé ; `ensure` porteur d'une autre valeur → enveloppe invalide (le moteur rend `{status: error}`). Un agent ANTÉRIEUR qui reçoit un item `absent` échouera son parse (`type` manquant) → error sur le type `registry` : c'est le comportement attendu D1 (« champ inconnu ignoré » ne peut pas s'appliquer à un item sans `value`) — d'où le bump de version et la note de publication.

6. **Piège #6 — Delete = la VALEUR, pas la clé-conteneur.** `RegistryOps.Delete` supprime la **valeur nommée** (`key.DeleteValue(name)`), jamais la clé (sous-arbre) — supprimer la clé emporterait des valeurs voisines non gérées. La suppression d'une valeur déjà absente (`registry.ErrNotExist` sur la clé OU la valeur) n'est PAS une erreur (idempotence). La réconciliation de clé-conteneur ENTIÈRE (D3) est le sujet de 35.2, pas d'ici.

7. **Piège #7 — golden files = 2 hashes figés JUMEAUX + justification écrite (règle 23.1).** Ajouter un item au golden `state.v1.json` impose de recalculer : le `hash` de l'item (via `StateHasher::hashItem`), `ContractV1Test::FROZEN_STATE_HASH` (PHP) **ET** `frozenStateHash` dans `agent/shared/hasher_test.go` (Go, MÊME valeur). La justification s'écrit dans la chaîne de commentaires existante des DEUX fichiers de test (pattern : chaque bump historique y est motivé, cf. lignes 44-118 de `ContractV1Test.php`). Vérifier aussi `agent/shared/contract_test.go` (`TestParseStateGoldenFile`) et `loop_test.go` (`mustReadGolden`) qui consomment le golden — ajuster les éventuels comptages d'items.

8. **Piège #8 — `report.v1.json` ne change PAS (à justifier dans la story de commit/doc).** Les items de rapport sont `{type, status, hash[, detail]}` — ils ne portent aucun payload. Le verbe `ensure` ne modifie donc pas la forme du rapport ; l'AC epic « golden files state/report mis à jour » se satisfait par : `state.v1.json` modifié (nouvel item `absent`) + constat écrit que `report.v1.json` est inchangé PARCE QUE le rapport ne transporte pas les payloads.

9. **Piège #9 — retrofit ≠ sentinelle.** Le nouveau « off » de `llmnr_disabled` / `windows_updates_managed` est une **action** (suppression des clés → Windows reprend ses défauts), PAS un « Non géré ». Le libellé d'option ne doit pas dire « Non géré » (réservé à la sentinelle `unmanaged` des capacités opt-in) — convention libellés « sujet + état » : proposer par ex. `'off' => 'Désactivé (clés supprimées)'`. La migration de retrofit doit être **idempotente** (rejouable, `update` ciblé par `key`, garde `Schema::hasTable`) et fonctionner APRÈS les seeds d'origine (ordre chronologique des migrations : les fresh installs jouent seed puis retrofit).

10. **Piège #10 — agent : bump version OBLIGATOIRE + publication jamais automatique.** Toute modif `agent/**` ⇒ bump `agent/shared/version.go` (actuel : `2.2.20` ; recommandé : `2.3.0` — évolution mineure du contrat consommé) avec entrée de changelog dans le commentaire du fichier. **`update.sh` ne publie JAMAIS seul** : sans publication de release, le parc tourne sur un binaire ANTÉRIEUR où `ensure` est inconnu → piège « handler absent du binaire publié » (un réglage sans effet en lab = binaire pas à jour, pas un bug). La note de publication doit rappeler l'amorçage manuel.

11. **Piège #11 — drift STRICT (27.8), rien à faire côté moteur MAIS le prouver.** `engine.go` rend `compliant` (Test true) ou `drift + apply` (Test false) — pas de `drifted_allowed`, pas de mode. Pour `ensure:absent` : valeur présente ⇒ `Test=false` ⇒ `drift` + suppression ; réapparition au cycle suivant ⇒ re-`drift`. AUCUN changement `engine.go` ; un test Go du handler à travers le moteur (iso `TestRegistryThroughEngineSection5`) le démontre.

12. **Piège #12 — consommateurs annexes du payload registry.** `RegistryUpstreamAdapter` (canal controlHub amont, 28.3) émet des payloads 5-clés depuis sa convention `key = "hive|path|name[|REG_TYPE]"` : **HORS scope** (le contrat amont pourra gagner `ensure` plus tard — additif). Ne pas l'étendre ici ; vérifier seulement qu'aucun consommateur serveur (debug UI, conformité) ne CRASH sur un payload sans `value` (grep `payload['value']` / `payload['type']` sur le chemin registry).

## Décisions de design (tranchées — cadrage epic)

1. **D1 — contrat additif uniquement** : `ensure ∈ present|absent`, champ optionnel, absence vaut `present`. Bump mineur documenté §9 du contrat ; agents antérieurs : item `absent` → parse en erreur isolée sur le type `registry` (comportement assumé, motivant le bump + publication).
2. **D2 — StateCompiler intouché** ; `exclusiveKey()` inchangée.
3. **Marqueur d'authoring réservé** : dans une map valeur-capacité de `spec`, `'off' => {'$ensure': 'absent'}` (forme JSON seed ; côté PHP : `['$ensure' => 'absent']`). Exposer la constante (nom du marqueur + valeur) publiquement (ex. sur `AbstractCapabilityStateProvider` ou `CapabilityProjection`) — 35.2 et 35.5 la réutiliseront.
4. **Trois régimes coexistent** : écrire / supprimer / ne pas gérer. La sentinelle UNMANAGED n'est ni renommée ni altérée.
5. **Suppression = valeur nommée uniquement** (pas la clé-conteneur — D3/35.2).
6. **Rafraîchissement shell** : une suppression EFFECTIVE d'une valeur HKCU compte comme un changement → `shellRefresh` (iso écriture), même gate « changement effectif seulement ».

## Acceptance Criteria

### AC1 — Contrat v1 : champ optionnel `ensure` (D1)

**Given** le contrat `se5.desired-state/v1`
**When** un item `registry` porte le nouveau champ optionnel `ensure ∈ present|absent`
**Then** l'absence du champ vaut `present` (rétro-compatible, ajout mineur — les items d'écriture existants restent byte-identiques, `ensure:"present"` n'est jamais émis par le serveur)
**And** un item `absent` porte exactement `{hive, path, name, ensure}` — sans `value` ni `type`
**And** `docs/agent/contract-v1.md` §7.1 documente le champ (ligne de tableau + exemple d'item `absent` + sémantique des trois régimes) et le §9 (règle d'évolution) est respecté
**And** le golden `state.v1.json` gagne UN item registry `ensure:"absent"` (portée machine, ex. clé DNSClient de `llmnr_disabled`) avec justification écrite dans les chaînes de commentaires de `ContractV1Test.php` ET `hasher_test.go` ; `FROZEN_STATE_HASH` (PHP) et `frozenStateHash` (Go) sont recalculés à l'identique ; `report.v1.json` est inchangé avec justification (le rapport ne porte pas de payload)
**And** le champ entre dans la canonicalisation du `StateHasher` : un test PHP (`StateHasherTest`) et un test Go (`hasher_test.go`) prouvent que deux items qui ne diffèrent que par `ensure` ont des hashes distincts (aucune modification du code des hashers).

### AC2 — Provider : marqueur `$ensure` dans la `spec` (trois régimes)

**Given** une `spec` de projection dont une map valeur-capacité porte le marqueur réservé `'off' => {'$ensure': 'absent'}`
**When** `AbstractCapabilityStateProvider::expand()` résout la valeur effective `off`
**Then** un payload `{hive, path, name, ensure: 'absent'}` (4 clés) est émis pour cette clé — au lieu de la sentinelle UNMANAGED qui n'émettait rien
**And** la sentinelle UNMANAGED (clé de map ABSENTE pour la valeur effective ⇒ rien n'est émis) reste disponible et DISTINCTE — les trois régimes coexistent dans une même `spec`
**And** `exclusiveKey()` est inchangée : un item `absent` et un item d'écriture sur la même clé `{hive|path|name}` s'arbitrent par la précédence existante (test : broadcast `off`→absent battu par un override de parc `on`→écriture, StateCompiler INTOUCHÉ)
**And** les items d'écriture restent EXACTEMENT 5 clés, sans fuite d'id de capacité (invariant central 27.12) ; une forme assoc non reconnue dans la map ⇒ clé non émise (défensif, pas d'exception)
**And** le provider reste Postgres pur (zéro AD/LdapRecord/APCu), zéro float dans les payloads, marqueur émis par les DEUX providers (Machine/HKLM et User/HKCU) selon la ruche.

### AC3 — Handler Go `registry` : convergence du delete (portées Machine ET Session)

**Given** le handler Go `registry` (instancié par le SERVICE SYSTEM pour HKLM et par le COMPAGNON pour HKCU — wiring `main_windows.go`/`companion_windows.go` inchangé)
**When** il converge un item `ensure:"absent"`
**Then** `Test` rend non-conforme si la valeur EXISTE (peu importe son type/contenu), conforme si elle est déjà absente
**And** `Apply` supprime la valeur nommée via `RegistryOps.Delete(hive, path, name)` (nouvelle méthode d'interface ; impl Windows : `OpenKey(SET_VALUE)` + `DeleteValue`, `ErrNotExist` sur clé ou valeur ⇒ succès idempotent) — jamais la clé-conteneur
**And** le test/apply/report suit la policy STRICT : valeur présente ⇒ `drift` + suppression ; réapparue au cycle suivant ⇒ re-`drift` (`engine.go` INTOUCHÉ, démontré par un test à travers le moteur)
**And** l'isolation inter-clés (effort maximal) et l'idempotence (2 passes stables = zéro écriture/suppression) sont préservées ; une suppression effective HKCU déclenche le rafraîchissement shell (même gate que l'écriture)
**And** un item `absent` sans `hive/path/name`, ou un `ensure` de valeur inconnue ⇒ enveloppe invalide ⇒ `{status: error}` pour le type (comportement existant).

### AC4 — Retrofit des capacités on-only (vrai « off » par suppression)

**Given** les capacités on-only du parc : `llmnr_disabled` (seed CD95 `2026_07_02_100000`) et `windows_updates_managed` (seed ISO `2026_06_18_100300`)
**When** la migration de retrofit (nouvelle migration datée 2026-07-03+) est jouée
**Then** leurs `options` abandonnent le régime « Géré » on-only et exposent un vrai « off » (libellé explicite type « Désactivé (clés supprimées) » — PAS « Non géré », réservé à la sentinelle)
**And** CHAQUE clé de leur `spec` gagne `'off' => {'$ensure': 'absent'}` (LLMNR : `EnableMulticast` + `NodeType` ; WindowsUpdate : les 6 clés du bundle) — les valeurs `'on'` existantes sont inchangées
**And** la migration est IDEMPOTENTE (rejouable sans effet de bord, `update` ciblé par `key`, garde `Schema::hasTable`), avec un `down()` restaurant l'état on-only
**And** l'invariant « un off proposé fait une vraie action » est satisfait par la suppression : le test `on_off_capabilities_emit_a_real_value_for_off` est mis à jour (un `off` valide = valeur réelle OU marqueur `$ensure`) et `windows_update_is_managed_only_no_misleading_off` est REMPLACÉ par un test affirmant le nouveau régime des deux capacités.

### AC5 — Version agent + note de publication

**Given** l'agent modifié
**Then** `agent/shared/version.go` est bumpé (2.2.20 → **2.3.0** recommandé) avec une entrée de changelog dans le commentaire du fichier (motif : verbe `ensure`, delete registry)
**And** la note de fin de story rappelle que **`update.sh` ne publie jamais seul** : release à publier manuellement, sans quoi le parc reste sur un binaire antérieur où un item `absent` produit `{status: error}` sur le type `registry` (piège binaire antérieur).

## Tasks / Subtasks

- [x] **Task 1 — Contrat & golden files (AC1)** *(commencer ici : fige le wire format pour tout le reste)*
  - [x] 1.1 `docs/agent/contract-v1.md` §7.1 : ajouter la ligne `ensure` au tableau du payload registry (optionnel, `present|absent`, défaut `present`), un exemple d'item `absent` 4 clés, et la sémantique des trois régimes (écrire / supprimer / ne pas gérer) ; mentionner l'évolution au §9 (champ ajouté = mineur, golden + bump agent).
  - [x] 1.2 `tests/Fixtures/Agent/state.v1.json` : ajouter en portée `machine` UN item `{"type":"registry","semantics":"exclusive","payload":{"hive":"HKLM","path":"SOFTWARE\\Policies\\Microsoft\\Windows NT\\DNSClient","name":"EnableMulticast","ensure":"absent"},"hash":"<recalculé>"}`. Calculer le `hash` d'item via `StateHasher::hashItem` (tinker ou laisser le test `every item hash` afficher l'attendu).
  - [x] 1.3 `tests/Unit/Services/Agent/ContractV1Test.php` : recalculer `FROZEN_STATE_HASH`, ajouter la ligne de justification dans la chaîne de commentaires (règle 23.1 : « champ additif `ensure`, item de suppression — forward-compatible, pas un major »), ajuster les éventuels comptages d'items.
  - [x] 1.4 `agent/shared/hasher_test.go` : reporter le MÊME hash dans `frozenStateHash` + même justification ; vérifier `agent/shared/contract_test.go` (`TestParseStateGoldenFile`) et `loop_test.go` (`mustReadGolden`) — ajuster comptages/attentes si besoin.
  - [x] 1.5 Tests hash `ensure` : PHP (`StateHasherTest`) et Go (`hasher_test.go`) — deux items identiques sauf `ensure` ⇒ hashes distincts ; item sans `ensure` ⇒ hash INCHANGÉ par rapport à avant story (non-régression byte-identité).
- [x] **Task 2 — Provider : marqueur `$ensure` (AC2)**
  - [x] 2.1 `app/Services/Agent/Providers/AbstractCapabilityStateProvider.php` : constante publique du marqueur (ex. `public const SPEC_ENSURE = '$ensure';` + `public const ENSURE_ABSENT = 'absent';`) ; dans `expand()`, après `resolveKeyValue()` et AVANT `typedValue()` : si la valeur résolue est un tableau assoc portant `['$ensure' => 'absent']` ⇒ émettre `{hive, path, name, ensure: 'absent'}` (4 clés) ; forme assoc non reconnue ⇒ `continue` défensif. Mettre à jour le docblock « EXACTEMENT 5 clés » (5 clés pour l'écriture, 4 pour la suppression).
  - [x] 2.2 `tests/Unit/Services/Agent/CapabilityRegistryProviderTest.php` : nouveaux tests — (a) map `off => {$ensure: absent}` émet l'item 4 clés ; (b) UNMANAGED (clé de map absente) n'émet toujours rien ; (c) les trois régimes coexistent dans une même spec ; (d) item absent ne porte ni `value` ni `type` ni id de capacité ; (e) assoc inconnue ⇒ non émis ; (f) chaque provider filtre toujours par sa ruche. Adapter `payload_is_concrete_five_keys_without_any_capability_id` (5 clés = items d'ÉCRITURE).
  - [x] 2.3 Test de précédence compilateur (SANS toucher `StateCompiler`) : dans `CapabilityRegistryCompilationTest.php`, un broadcast `off`→absent battu par un override de parc `on`→écriture sur la même clé (et l'inverse) — `exclusiveKey()` identique pour les deux formes.
  - [x] 2.4 Grep de non-régression des consommateurs serveur du payload registry (`payload['value']`, `payload['type']` — ex. debug/conformité) : aucun crash sur un payload 4 clés ; `RegistryUpstreamAdapter` HORS scope (noter pourquoi dans le Dev Agent Record si touché).
- [x] **Task 3 — Handler Go registry (AC3)**
  - [x] 3.1 `agent/shared/handler_registry.go` : champ `Ensure string` sur `RegistrySpec` ; `parseRegistrySpec` — brancher sur `payload["ensure"]` : `"absent"` ⇒ exiger seulement hive/path/name (pas de `parseRegistryValue`) ; absent/`"present"` ⇒ parcours actuel ; autre valeur ⇒ invalide. `Test` : item absent ⇒ conforme ssi `!present`. `Apply` : item absent + présent ⇒ `Ops.Delete` (effort maximal, `shellRefresh` si HKCU). Nouvelle méthode `Delete(hive, path, name string) error` sur l'interface `RegistryOps`. Mettre à jour le bloc de doc « désactiver = cesser de gérer » (trois régimes ; le handler ne touche toujours JAMAIS une clé hors cible).
  - [x] 3.2 `agent/windows/handler_registry_windows.go` : impl `Delete` — `registry.OpenKey(root, path, registry.SET_VALUE)` + `key.DeleteValue(name)` ; `registry.ErrNotExist` (clé OU valeur) ⇒ `nil` (idempotent) ; autres erreurs remontées.
  - [x] 3.3 `agent/shared/handler_registry_test.go` : `fakeRegistryOps.Delete` + tests — (a) valeur présente ⇒ Test false, Apply supprime, re-Test true, 2e Apply = zéro op (idempotence) ; (b) valeur absente ⇒ compliant sans écriture ; (c) réapparition ⇒ re-drift (via moteur, iso `TestRegistryThroughEngineSection5` — STRICT, `engine.go` intouché) ; (d) mix items écrire+supprimer dans une même passe, isolation des erreurs ; (e) delete HKCU effectif ⇒ notification shell, delete no-op ⇒ pas de notification ; (f) `ensure` invalide / item absent incomplet ⇒ payload invalide ; (g) `ensure:"present"` explicite ≡ item d'écriture classique.
  - [x] 3.4 `agent/shared/version.go` : bump `2.3.0` + entrée changelog (verbe `ensure` : suppression de valeurs registre, contrat additif, golden bumpé).
- [x] **Task 4 — Retrofit on-only (AC4)**
  - [x] 4.1 Nouvelle migration `database/migrations/2026_07_03_1xxxxx_retrofit_ensure_off_on_only_capabilities.php` : pour `llmnr_disabled` et `windows_updates_managed` — `options` → `[{on: <libellé actuel>}, {off: 'Désactivé (clés supprimées)'}]` (ajuster libellés à la convention sujet+état) ; `spec` de la projection windows/registry → chaque clé gagne `'off' => ['$ensure' => 'absent']` (valeurs `on` inchangées). Idempotente (update par `key`, garde `hasTable`, rejouable) ; `down()` restaure on-only.
  - [x] 4.2 `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php` : mettre à jour `on_off_capabilities_emit_a_real_value_for_off` (off = valeur réelle OU marqueur `$ensure` ; y intégrer `llmnr_disabled` et `windows_updates_managed`) ; REMPLACER `windows_update_is_managed_only_no_misleading_off` par le test du nouveau régime (les 2 capacités exposent `off`, chaque clé porte le marqueur).
  - [x] 4.3 Test d'intégration provider sur le retrofit : `llmnr_disabled` avec valeur effective `off` émet 2 items `ensure:absent` HKLM (broadcast) — la chaîne seed→spec→expand→payload est prouvée sur données réelles.
- [x] **Task 5 — Validation finale**
  - [x] 5.1 Tests HÔTE ciblés (php8.4 + sqlite — JAMAIS de run massif) : `ContractV1Test`, `StateHasherTest`, `CapabilityRegistryProviderTest`, `CapabilityRegistryCompilationTest`, `CapabilitiesSchemaAndSeedTest`.
  - [x] 5.2 Tests Go (toolchain hôte, hors PATH : `~/go-toolchain/go/bin/go`) : `cd agent && go test ./shared/...` (au minimum `-run 'TestRegistry|TestHash|TestParseState'`) ; `GOOS=windows go build ./...` pour valider la compile de l'impl Windows.
  - [x] 5.3 Signaler en Dev Agent Record : migration de retrofit **à rejouer sur /vm** (`php artisan migrate` — les migrations ne sont PAS auto-appliquées sur la VM) + release agent **à publier manuellement** (update.sh ne publie jamais seul).

## Dev Notes

### Fichiers à toucher (exhaustif prévu)

| Fichier | Nature |
|---|---|
| `docs/agent/contract-v1.md` | §7.1 champ `ensure` + exemple ; §9 mention |
| `tests/Fixtures/Agent/state.v1.json` | +1 item registry `absent` (machine) |
| `tests/Unit/Services/Agent/ContractV1Test.php` | `FROZEN_STATE_HASH` + justification + comptages |
| `tests/Unit/Services/Agent/StateHasherTest.php` | test hash distinct par `ensure` |
| `app/Services/Agent/Providers/AbstractCapabilityStateProvider.php` | marqueur `$ensure` dans `expand()` + constantes publiques |
| `tests/Unit/Services/Agent/CapabilityRegistryProviderTest.php` | tests marqueur / trois régimes |
| `tests/Unit/Services/Agent/CapabilityRegistryCompilationTest.php` | précédence absent vs écrit (compilateur intouché) |
| `agent/shared/handler_registry.go` | `Ensure` sur spec, parse, Test/Apply, `RegistryOps.Delete` |
| `agent/shared/handler_registry_test.go` | fake `Delete` + tests convergence delete |
| `agent/shared/hasher_test.go` | `frozenStateHash` + justification + test ensure |
| `agent/shared/contract_test.go` / `loop_test.go` | ajustements de comptage éventuels (golden) |
| `agent/windows/handler_registry_windows.go` | impl `Delete` (DeleteValue, ErrNotExist ⇒ nil) |
| `agent/shared/version.go` | bump `2.3.0` + changelog |
| `database/migrations/2026_07_03_1xxxxx_retrofit_ensure_off_on_only_capabilities.php` | retrofit idempotent (NOUVEAU) |
| `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php` | invariant on/off mis à jour + remplacement test WU |

**NE PAS TOUCHER** : `app/Services/Agent/StateCompiler.php` (D2), `app/Services/Agent/StateHasher.php` + `agent/shared/hasher.go` (canonicalisation générique — seulement des tests), `agent/shared/engine.go` (Verdict STRICT inchangé), `StateContract::RESOURCE_TYPES` (le type reste `registry`), wiring `main_windows.go`/`companion_windows.go` (mêmes deux instanciations), `RegistryUpstreamAdapter` (contrat amont hors scope), `sprint-status.yaml` / `backlog.data.js` / `backlog.html` (orchestrateur), seeds d'origine `2026_06_18_100300` / `2026_07_02_100000` (le retrofit est une NOUVELLE migration — ne pas réécrire l'histoire).

### Patterns existants à imiter

- **Chaîne de justification golden** : commentaires numérotés de `ContractV1Test.php` (l.44-118) et `hasher_test.go` (l.11-75) — chaque bump y est motivé ; ajouter le vôtre dans le MÊME style.
- **Sentinelle UNMANAGED** : `AbstractCapabilityStateProvider::UNMANAGED` + `resolveKeyValue()` — le marqueur `$ensure` suit la même philosophie (résolution AVANT coercition, jamais d'exception au render).
- **Migration de retrofit** : pattern `updateOrInsert`/update par `key` + garde `hasTable` des seeds capacités (`2026_07_02_100000_seed_capabilities_gpo_cd95_lot.php` — en tête, la doctrine des défauts de diffusion).
- **Tests handler Go** : `fakeRegistryOps` en mémoire (`handler_registry_test.go`) — étendre le fake, pas en créer un autre ; test à travers le moteur : `TestRegistryThroughEngineSection5`.
- **Ordre déterministe** : `desiredSpecs()` dédoublonne par identité et trie — un item absent partage l'identité `{hive|path|name}` avec un item d'écriture ; la défense « dernière occurrence fait foi » reste valable telle quelle.

### Rappels transverses (garde-fous epic)

- Contrat **additif uniquement** (D1) ; golden AVEC justification (règle 23.1).
- **Drift policy STRICT** (27.8) — pas de `drifted_allowed`, pas de mode.
- **Zéro float** dans les payloads (`ensure` est une string) ; **zéro AD/LdapRecord/APCu** dans les providers (critère Keycloak).
- Tests PHP sur l'**HÔTE** (php8.4 + sqlite), **filtres ciblés uniquement** (un run massif VM produit de faux échecs) ; tests Go via `~/go-toolchain/go/bin/go`.
- Migrations **à rejouer sur /vm** = à SIGNALER en fin de story (Dev Agent Record), pas à exécuter depuis le dev.
- Toute modif `agent/**` ⇒ bump `agent/shared/version.go` ; **update.sh ne publie jamais seul** (amorçage manuel de la release).

### Project Structure Notes

- Serveur : logique capacités sous `app/Services/Agent/Providers/` ; contrat sous `app/Services/Agent/` ; aucun nouveau fichier PHP attendu (tout est extension de l'existant sauf la migration).
- Agent : cœur OS-agnostique dans `agent/shared/` (testé hôte), câblage Win32 dans `agent/windows/` — la logique delete vit dans `shared`, `windows` n'apporte que `DeleteValue`.
- Aucune UI dans cette story (le sélecteur d'options existant affiche automatiquement le nouveau « off » des deux capacités retrofittées — c'est de la donnée `options`).

### References

- [Source: _bmad-output/planning-artifacts/epics-capacites-v2.md#Story-35.1 + #Décisions-structurantes (D1, D2) + #Garde-fous-d'epic] — autorité de cadrage
- [Source: docs/agent/contract-v1.md#7.1 (payload registry), #4 (hash/canonicalisation), #4.1 (zéro float), #8 (tableau vide ≠ type absent), #9 (règle d'évolution)]
- [Source: app/Services/Agent/Providers/AbstractCapabilityStateProvider.php — expand()/resolveKeyValue()/UNMANAGED/exclusiveKey()]
- [Source: agent/shared/handler_registry.go + agent/windows/handler_registry_windows.go — RegistryOps/Test/Apply]
- [Source: agent/shared/engine.go — Verdict STRICT (27.8) ; _bmad-output/implementation-artifacts/27-8-retrait-mode-strict-default-drift-policy.md]
- [Source: _bmad-output/implementation-artifacts/27-12-config-capacites-registre-capability-first.md — modèle capability-first, pièges d'origine]
- [Source: database/migrations/2026_07_02_100000_seed_capabilities_gpo_cd95_lot.php + 2026_06_18_100300_seed_capabilities_iso_lot.php — seeds à retrofitter]
- [Source: tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php#on_off_capabilities_emit_a_real_value_for_off + #windows_update_is_managed_only_no_misleading_off]

## Dépendances

- **En amont (intra-epic) : AUCUNE.** 35.1 est le socle de l'Epic 35 ; elle ne dépend que de l'existant livré (27.12 capability-first, 27.8 STRICT, contrat 23.1).
- **En aval** :
  - **35.2 (`registry_list`) DÉPEND de 35.1** — la réconciliation D3 (suppression des valeurs numérotées surnuméraires) s'appuie sur le delete livré ici ;
  - **35.5 (`photo_viewer_restored`) dépend de 35.1 pour son « off »** (`$ensure: absent` ; sinon elle serait livrée on-only honnête) ;
  - 35.3 (HKU) et 35.4 (UI override UserGroup) sont indépendantes de 35.1 (parallélisables).

## Recommandation Modèle Dev

**fable** — prescription explicite de l'epic (garde-fous : « fable pour les stories touchant l'agent Go », 35.1 en tête de liste) et conforme à la mémoire projet `feedback_epic23_model_fable5`. La story est cross-language (contrat PHP ⇄ Go avec hashes figés jumeaux à recalculer à l'identique des deux côtés, golden files normatifs, sémantique de convergence) : c'est exactement le profil où la discipline de contrat prime — aucune raison de dévier.

## Dev Agent Record

### Agent Model Used

claude-fable-5 (Fable 5) — 2026-07-03.

### Debug Log References

- Tests PHP HÔTE (php8.4 + sqlite, filtres ciblés) :
  - `php artisan test --filter='ContractV1Test|StateHasherTest'` → 15 passed (119 assertions) ;
  - `php artisan test --filter='CapabilityRegistryProviderTest|CapabilityRegistryCompilationTest'` → 28 passed (117 assertions) ;
  - `php artisan test --filter='CapabilitiesSchemaAndSeedTest'` → 15 passed (216 assertions) ;
  - `php artisan test --filter='ContractV1Test|StateHasherTest|CapabilityRegistryProviderTest|CapabilityRegistryCompilationTest|StateCompilerTest|CapabilityPhysicalInheritanceTest'` → 79 passed (365 assertions) ;
  - `php artisan test --filter='GoAgentTest|ConformityServiceTest|ReportIngestServiceTest'` → 27 passed, 1 skipped (GoAgentTest : toolchain Go hors PATH — couvert par le run Go direct ci-dessous).
- Tests Go (`~/go-toolchain/go/bin/go`) : `go test ./...` (agent complet) → ok ; `GOOS=windows go build ./...` OK ; `go vet ./...` (linux ET GOOS=windows) OK.

### Completion Notes List

1. **⚠️ MIGRATION À REJOUER SUR /vm** : `database/migrations/2026_07_03_100000_retrofit_ensure_off_on_only_capabilities.php` — `php artisan migrate` sur /vm (les migrations ne sont PAS auto-appliquées, dev-cycle SQLite only).
2. **⚠️ RELEASE AGENT 2.3.0 À PUBLIER MANUELLEMENT** : `update.sh` ne publie JAMAIS seul. Sans publication, le parc reste sur un binaire antérieur (≤ 2.2.20) où un item `ensure:"absent"` échoue le parse → `{status: error}` isolé sur le type `registry` (comportement D1 assumé — ce n'est pas un bug, c'est le piège « handler absent du binaire publié »).
3. **Justification golden (règle 23.1)** : `state.v1.json` gagne UN item `registry` de suppression en portée `machine` (payload 4 clés `{hive, path, name, ensure:"absent"}`, clé DNSClient\EnableMulticast de `llmnr_disabled`). Champ ADDITIF optionnel dont l'absence vaut `present` → les 11 items existants sont BYTE-IDENTIQUES (hashes d'items inchangés, vérifié par `each_state_item_hash_matches_state_hasher` + test de non-régression du hash historique `92730f99…` des deux côtés). `FROZEN_STATE_HASH` (PHP) = `frozenStateHash` (Go) = `f3d22e9f81d927d3cfc54ecf7f850d92a9a012135857df53e7fd91d82392be7f` (recalculé via `StateHasher` réel). **`report.v1.json` INCHANGÉ** : les items de rapport `{type, status, hash[, detail]}` ne portent aucun payload — le verbe `ensure` n'y a pas de surface.
4. **Garde-fous respectés** : `StateCompiler.php`, `StateHasher.php`, `hasher.go`, `engine.go`, `StateContract::RESOURCE_TYPES`, wiring `main_windows.go`/`companion_windows.go`, `RegistryUpstreamAdapter`, seeds d'origine `2026_06_18_100300`/`2026_07_02_100000` : AUCUNE ligne modifiée. Le provider n'émet jamais `ensure:"present"` explicite. Drift STRICT démontré à travers le moteur (`TestRegistryAbsentThroughEngineStrictRedrift` : drift + suppression, re-drift à la réapparition, compliant au stable). Zéro float, zéro AD/LdapRecord/APCu (test `provider_source_has_no_ad_apcu_samba_dependency` vert).
5. **Task 2.4 — grep des consommateurs serveur du payload registry** : `UpstreamLockCollisionDetector` lit `payload['value'] ?? null` (null-safe sur un payload 4 clés — l'`absent` n'impose rien, doc du service : « l'absent n'impose rien ») ; `ControlHubContractSeveranceService::recoverCapabilityValue()` a déjà la garde `is_scalar` (review #8) → une entrée de map `['$ensure'=>'absent']` est ignorée proprement (non recouvrable, filet warning) ; `RegistryUpstreamAdapter` parse SA convention amont `key = "hive|path|name[|REG_TYPE]"` → HORS scope, non touché (le contrat amont pourra gagner `ensure` plus tard — additif). Aucun consommateur ne crashe sur un payload sans `value`/`type`.
6. **Décisions de dev** : (a) constantes publiques du marqueur posées sur `AbstractCapabilityStateProvider` (`SPEC_ENSURE = '$ensure'`, `ENSURE_ABSENT = 'absent'`) — 35.2/35.5 les réutiliseront ; la migration duplique les littéraux (iso seeds : les migrations ne référencent pas le code applicatif). (b) Forme assoc non reconnue dans une map (`{'$ensure':'present'}`, `{unexpected: …}`) ⇒ clé NON émise, défensif sans exception (comble le piège latent `typedValue()` → 0/''). (c) Libellés retrofit : on = « Géré » pour les DEUX capacités (libellés d'origine conservés — correction review 35.1 #2 : le retrofit n'ajoute que le « off », il ne relabelle rien, up()/down() inverses exacts), off = « Désactivé (clés supprimées) » pour les deux — jamais « Non géré » (réservé sentinelle). (d) Test d'idempotence/réversibilité explicite de la migration ajouté (`retrofit_migration_is_idempotent_and_reversible` : up() rejoué = no-op, down() restaure on-only, up() re-retrofitte). (e) `loop_test.go` (`mustReadGolden`) consomme le golden comme corps HTTP sans compter les items → aucun ajustement nécessaire ; `contract_test.go` (machine 3→4) et `hasher_test.go` (11→12 items) ajustés.
7. **QA** : runbook domaine agent enrichi (append-only) — section « Story 35.1 » de `docs/qa/domains/agent.md` (5 scénarios + checklist).

### Change Log

- 2026-07-03 — Story 35.1 implémentée intégralement (AC1-AC5) : contrat §7.1 `ensure` + golden bumpé (jumeaux PHP/Go), marqueur `$ensure` dans `expand()` (trois régimes), handler Go delete (RegistryOps.Delete + impl Windows DeleteValue), retrofit on-only (migration idempotente + tests), bump agent 2.2.20 → 2.3.0, runbook QA agent enrichi. Status → review.

### File List

| Fichier | Nature |
|---|---|
| `docs/agent/contract-v1.md` | modifié — §7.1 : ligne `ensure` au tableau + exemple d'item absent 4 clés + sémantique des trois régimes ; §9 : mention de l'évolution 35.1 |
| `tests/Fixtures/Agent/state.v1.json` | modifié — +1 item registry `ensure:"absent"` (portée machine, DNSClient\EnableMulticast), hash d'item recalculé |
| `tests/Unit/Services/Agent/ContractV1Test.php` | modifié — `FROZEN_STATE_HASH` recalculé + justification 35.1 dans la chaîne de commentaires |
| `tests/Unit/Services/Agent/StateHasherTest.php` | modifié — tests `ensure_field_changes_the_item_hash` + `write_item_without_ensure_keeps_its_pre_story_hash` (hasher INCHANGÉ) |
| `app/Services/Agent/Providers/AbstractCapabilityStateProvider.php` | modifié — constantes publiques `SPEC_ENSURE`/`ENSURE_ABSENT`, détection du marqueur dans `expand()` (après resolveKeyValue, avant typedValue), docblocks trois régimes |
| `tests/Unit/Services/Agent/CapabilityRegistryProviderTest.php` | modifié — 6 tests marqueur/trois régimes (a-f) + section 5 clés requalifiée « items d'ÉCRITURE » |
| `tests/Unit/Services/Agent/CapabilityRegistryCompilationTest.php` | modifié — précédence absent↔écrit dans les DEUX sens (StateCompiler intouché) |
| `agent/shared/handler_registry.go` | modifié — `Ensure` sur RegistrySpec, constantes ensure, branche parse `ensure`, Test/Apply delete (effort maximal, shellRefresh HKCU), `RegistryOps.Delete`, doc trois régimes |
| `agent/shared/handler_registry_test.go` | modifié — `fakeRegistryOps.Delete` (+deleteErr/deleteCnt) + 7 tests (a-g) dont re-drift STRICT via moteur |
| `agent/shared/hasher_test.go` | modifié — `frozenStateHash` recalculé + justification 35.1, comptage 11→12, tests ensure (distinct + non-régression byte-identité) |
| `agent/shared/contract_test.go` | modifié — comptage portée machine 3→4 (golden) |
| `agent/windows/handler_registry_windows.go` | modifié — impl `Delete` (OpenKey SET_VALUE + DeleteValue, ErrNotExist clé OU valeur ⇒ nil idempotent) |
| `agent/shared/version.go` | modifié — bump `2.2.20` → `2.3.0` + entrée changelog (verbe ensure, delete registry) |
| `database/migrations/2026_07_03_100000_retrofit_ensure_off_on_only_capabilities.php` | NOUVEAU — retrofit idempotent llmnr_disabled + windows_updates_managed (options off réel + marqueur sur chaque clé, down() on-only) |
| `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php` | modifié — invariant on/off étendu (valeur réelle OU marqueur), remplacement du test WU on-only par le nouveau régime, test idempotence/réversibilité migration, test d'intégration provider sur données réelles |
| `docs/qa/domains/agent.md` | modifié — section « Story 35.1 » append-only (5 scénarios + checklist) |
| `_bmad-output/implementation-artifacts/35-1-verbe-ensure-present-absent-registry.md` | modifié — checkboxes, Dev Agent Record, File List, status review |
| `_bmad-output/implementation-artifacts/sprint-status.yaml` | modifié — ligne 35-1 → review |
