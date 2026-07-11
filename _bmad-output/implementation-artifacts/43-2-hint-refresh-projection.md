# Story 43.2 : Serveur — hint `refresh` déclaré par projection + affichage temporalité d'effet

Status: review

<!-- Source d'autorité : _bmad-output/planning-artifacts/epics-application-immediate.md#Story-43.2
     + Overview + FR-A2/FR-A3 + NFR-A4 + Notes de coordination (amendées par la 43.3 :
     contrainte 41.3 create/DELETE des assignments — sans impact direct ici).
     Branche : ultradev/epic-43 — les stories 43.1 (mécanisme agent) et 43.3 (ttl_seconds
     hors hash, hash gelés DÉJÀ bumpés une fois) sont MERGÉES : toutes les lignes de code
     citées ont été re-vérifiées sur CETTE branche (2026-07-11).
     ⚠️ L'epic cite une UI « /admin/settings/capabilities » : cette page N'EXISTE PLUS —
     consolidée dans /admin/settings/parc-defaults, onglet « Registre / capacités »
     (décision Henri 27.17, cf. commentaire registry-tab.blade.php:16-17). Les ancres
     ci-dessous pointent les fichiers RÉELS. -->

## Story

En tant qu'administrateur du parc,
je veux déclarer sur chaque projection registre le geste de rafraîchissement qui rend
son réglage effectif en session courante, et voir dans l'UI la temporalité d'effet de
chaque capacité,
afin que l'agent 2.10.0 applique le bon geste (43.1) et que « j'ai appliqué, rien ne se
passe » disparaisse (FR-A2 volet serveur + FR-A3).

## Contexte & intention

La 43.1 (mergée) a livré l'échelle compagnon : chaque item `registry`/`registry_list`
du payload peut porter un hint `refresh` ∈ `shell_notify | policy_broadcast |
explorer_restart` (`agent/shared/handler_registry.go:505` lit `payload["refresh"]`,
lecture indulgente `ParseRefreshLevel`, `agent/shared/refresh.go:73-79`) ; en fin de
passe compagnon, UN geste (le plus fort des items changés) est exécuté ; plancher
`shell_notify` pour tout changed HKCU même sans hint (D2 de la 43.1).

Cette story livre le VERSANT SERVEUR, en 4 volets :

1. **Convention d'authoring** : champ optionnel `refresh` au NIVEAU RACINE du `spec`
   JSON des projections `registry`/`registry_list` (`{"keys": […], "refresh": "…"}`) —
   zéro migration de schéma (le `spec` est du JSON).
2. **Validation à l'authoring** : l'AuthoringGuard du mécanisme registre
   (`CapabilitySpecCollisionGuard`) rejette toute valeur hors vocabulaire — jamais au
   runtime (le render reste défensif, l'agent reste indulgent).
3. **Recopie au payload** : `AbstractCapabilityStateProvider` recopie le hint dans les
   payloads émis, portées Session/MachineUser UNIQUEMENT — jamais machine/HKU.
   Golden files PHP↔Go mis à jour (le hint entre dans le hash d'item → drift ponctuel
   de re-application, NFR-A4, bénin, documenté).
4. **UI + retrofit** : badge de temporalité d'effet dans le catalogue
   (parc-defaults, onglet Registre/capacités) et les formulaires d'assignation
   (parc + groupes d'utilisateurs) ; retrofit CONSERVATEUR du lot Explorer et des
   capacités `Policies\Explorer` (dont `blocked_executables`).

**Prêt pour 41.2** : la future capacité `restrict_run` n'aura qu'à poser
`"refresh": "…"` dans son seed — zéro code ici ou là-bas.

**Ancrage code re-vérifié (2026-07-11, branche ultradev/epic-43)** :

- `app/Services/Agent/Providers/AbstractCapabilityStateProvider.php` —
  `itemsFor()` :173-244 (DEUX sites de push : Broadcast :212-219, overrides :224-240),
  `expand()` :272-330 (payload « EXACTEMENT 5 clés » écriture / « EXACTEMENT 4 »
  suppression — invariant :47-50 et :262-264 à AMENDER), `resolveOverrides()` :431-497.
- `app/Services/Agent/Providers/AbstractRegistryListCapabilityProvider.php` —
  `expand()` :~90-150 (payload « EXACTEMENT 4 clés » :22 — à AMENDER).
- Providers concrets & portées : `RegistryUserCapabilityProvider` (`scope()=Session`,
  HKCU) ; `RegistryMachineCapabilityProvider` (`scope()=Machine`, HKLM + HKU via
  `handlesHive()`) ; `RegistryListUserCapabilityProvider` (Session) ;
  `RegistryListMachineCapabilityProvider` (Machine). Aucun provider registre
  MachineUser aujourd'hui — le gate par portée couvre le futur.
- `app/Services/Agent/Providers/CapabilitySpecCollisionGuard.php` — LE guard
  d'authoring des mécanismes registre (`ALLOWED_HIVES` :74-77, `violations()` :86+),
  service PUR exécuté par l'invariant de
  `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php:629-633` sur les
  projections réellement seedées.
- `app/Observers/CapabilityProjectionObserver.php:46-52` — dispatch par mécanisme :
  `registry`/`registry_list` retournent `null` (catalogue-first). **INCHANGÉ** (D2).
- `app/Models/CapabilityProjection.php` — constantes mécanismes :35-106, ruches
  :113-128. `app/Models/Capability.php` — `projections()` :85, helpers :142-193.
- Golden : `tests/Fixtures/Agent/state.v1.json` (item session `registry` HideFileExt
  = LE candidat au hint) ; `tests/Unit/Services/Agent/ContractV1Test.php:229`
  (`FROZEN_STATE_HASH = b1eb0560…`, déjà bumpé par 43.3) et :299-325 (hash par item +
  gelé) ; `agent/shared/hasher_test.go:152` (`frozenStateHash`, jumeau).
- Contrat : `docs/agent/contract-v1.md` §3.2 :94-101 (payload provider-defined),
  §7.1 :247 (tableau :268-275 + « EXACTEMENT 5 clés » :275), §7.6 :598 (« EXACTEMENT
  4 clés » :622), §9 :1060 (règle d'évolution).
- UI : `resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php`
  (catalogue : `capabilities()` :44-75 avec `with('projections')`, table :270-372,
  modale :378-440) ;
  `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php`
  (`overrides()` :92-135 et `addableCapabilities()` :144-181, les deux
  `with('projections')`) ;
  `resources/views/pages/users/groups/[id]/_partials/capabilities-section.blade.php`
  (`capabilities()` :98+, `with(['projections' => …])` :105).
- Seeds à retrofitter :
  `database/migrations/2026_06_18_100300_seed_capabilities_iso_lot.php` (vues
  Explorer + onedrive_hidden), `2026_07_02_100000_seed_capabilities_gpo_cd95_lot.php`
  (registry_editing_disabled…), `2026_07_03_110000_seed_capabilities_registry_list_lot.php`
  (blocked_executables, bi-projection), `2026_07_04_100000_seed_capabilities_explorer_lot.php`
  (lot Explorer 36.3). Patron de retrofit :
  `2026_07_03_100000_retrofit_ensure_off_on_only_capabilities.php` (nouvelle
  migration, on ne réécrit JAMAIS un seed d'origine).
- Runbook QA : `docs/qa/domains/agent.md` scénario 43.1.1 :3985-4005 — critère de
  décision lab `policy_broadcast` (consommé ICI, cf. D4).

## Décisions de design (tranchées en création de story)

- **D1 — `spec.refresh` au niveau RACINE du spec, une valeur par projection.**
  `{"keys": […], "refresh": "policy_broadcast"}`. Pas de hint par clé (sur-conception :
  aucune capacité n'a deux temporalités dans la même projection ; la bi-projection
  `blocked_executables` porte le hint dans CHACUN de ses deux specs). Vocabulaire =
  3 constantes NOUVELLES sur `CapabilityProjection` (`REFRESH_SHELL_NOTIFY = 'shell_notify'`,
  `REFRESH_POLICY_BROADCAST = 'policy_broadcast'`, `REFRESH_EXPLORER_RESTART =
  'explorer_restart'` + liste `REFRESH_HINTS`) — single source pour guard, provider
  et modèle (patron des constantes MECHANISM_*/HIVE_* déjà là). Casse canonique
  EXACTE minuscule (l'agent tolère trim+lowercase, le catalogue n'écrit que la forme
  canonique — iso convention « forme courte exclusive » des ruches, review 35.3 #3).
- **D2 — AuthoringGuard = `CapabilitySpecCollisionGuard` étendu (règle 5), observer
  INCHANGÉ.** Le guard du mécanisme registre EST ce service (fs_acl/firewall/privilege
  ont leurs guards dédiés routés par l'observer ; `registry`/`registry_list` sont
  catalogue-first : aucune UI ni canal Eloquent n'écrit leurs specs — le guard est
  exercé par l'invariant `CapabilitiesSchemaAndSeedTest:629` sur les données seedées,
  et reste réutilisable par un futur geste UI). Règle 5 (par projection
  registry/registry_list) : si `spec.refresh` présent → (a) string ∈ `REFRESH_HINTS`
  (sinon violation nommée citant la valeur ET le vocabulaire) ; (b) AU MOINS une clé
  `hive: HKCU` dans le spec (un hint sans clé session est INERTE — recopie jamais
  déclenchée — donc une erreur d'authoring, refusée en amont plutôt que silence).
  Valeur inconnue = rejet à l'authoring, JAMAIS au runtime (render défensif D3,
  agent indulgent 43.1-D3).
- **D3 — Recopie centralisée dans `itemsFor()`, double gate mécanisme + portée.**
  Nouveau helper `protected function withRefreshHint(CapabilityProjection $projection,
  array $payload): array` dans `AbstractCapabilityStateProvider`, appliqué aux DEUX
  sites de push d'`itemsFor()` (Broadcast :212-219 et overrides :224-240 — un seul
  foyer, hérité par registry_list ; les `expand()` restent intouchés). Recopie ssi :
  `$this->mechanism()` ∈ {registry, registry_list} (le `legacy_cleanup` hérite
  d'`itemsFor()` — exclu explicitement même s'il est Machine) ET `$this->scope()` ∈
  {Session, MachineUser} ET `spec['refresh']` est une string du vocabulaire (toute
  autre forme ⇒ pas de recopie, jamais d'exception au render — discipline UNMANAGED).
  Le hint est recopié sur TOUS les payloads émis par la projection, y compris les
  items de SUPPRESSION `ensure: "absent"` (supprimer une policy exige le même geste ;
  le gate `changed` agent neutralise le régime stable). Clé `refresh` en DERNIÈRE
  position du payload (lisibilité fixtures — le hash canonicalise en triant les clés,
  la position est indifférente).
- **D4 — Retrofit CONSERVATEUR (validation lab de `policy_broadcast` NON faite —
  décision d'orchestration).** Le lab n'est pas accessible ; le scénario QA 43.1.1
  (agent.md :3985-4005) tranchera. Choix motivés, ajustables post-lab par un SIMPLE
  UPDATE de seed (aucun code) :

  | Capacité (seed) | Clés session | Hint | Motivation |
  |---|---|---|---|
  | `show_file_extensions` (ISO) | HKCU Explorer\Advanced HideFileExt | `shell_notify` | Préférence de vues — comportement actuel préservé (plancher 43.1) rendu DÉCLARATIF + UI honnête |
  | `show_hidden_files` (ISO) | HKCU Explorer\Advanced Hidden | `shell_notify` | idem |
  | `quick_access_history_hidden` (36.3) | HKCU Explorer ShowRecent/ShowFrequent | `shell_notify` | Préférences de vues |
  | `onedrive_hidden` (ISO) | HKCU Classes\CLSID IsPinnedToNameSpaceTree | `shell_notify` | Épingle du volet — SHChangeNotify plausible, jamais plus fort sans preuve lab |
  | `quick_access_hidden` (36.3) | HKCU LaunchTo + CLSID Accueil (clé HKLM HubMode : jamais recopiée) | `shell_notify` | idem |
  | `explorer_gallery_hidden` (36.3) | HKCU CLSID Galerie | `shell_notify` | idem |
  | `blocked_executables` (35.2, BI-projection) | HKCU …CurrentVersion\Policies\Explorer (flag registry) + …\DisallowRun (conteneur registry_list) | `policy_broadcast` (dans les DEUX specs) | Clés `Policies` re-lues par Explorer sur WM_SETTINGCHANGE « Policy » (comportement documenté que le moteur GPO exploite) ; si le lab l'infirme → UPDATE seed vers `explorer_restart` |
  | `registry_editing_disabled` (CD95) | HKCU …CurrentVersion\Policies\System DisableRegistryTools | `policy_broadcast` | Famille Policies ; l'enforcement au lancement de regedit rend le libellé « Immédiat » honnête, le broadcast est inoffensif |

  **SANS hint (motivé, ne pas y toucher)** : `numlock_on_logon` (lu au logon —
  « à la prochaine session » est EXACT), `outlook_disable_o365_account_creation`
  (lu au lancement d'Outlook — aucun geste shell n'aide), `explorer_sidebar_pins_hidden`
  (HKLM only — la règle 5b du guard REFUSERAIT un hint), toutes les capacités
  machine/HKLM/HKU (`windows_copilot_off`, `hide_drives` — déplacées HKLM par les
  fixes 06-19/07-06, `uac_enabled`, `pix_extension_forced`, `internet_access`
  firewall, fs_acl, privilege, `legacy_hooks_cleanup`…) : effet naturellement live
  ou machine — jamais de hint. **AUCUN `explorer_restart` au retrofit** (conservateur).
- **D5 — Wording UI (tranché, FR-A3).** Badge dérivé du hint, UNIQUEMENT pour les
  capacités dont une projection registre porte AU MOINS une clé HKCU :
  - `shell_notify` et `policy_broadcast` → **« Immédiat »** ;
  - `explorer_restart` → **« Immédiat (le bureau redémarre) »** ;
  - hint ABSENT (mais clés HKCU présentes) → **« À la prochaine session »** ;
  - capacité SANS clé HKCU registre (machine-only, firewall, fs_acl…) → **AUCUN
    badge** (ne JAMAIS afficher « à la prochaine session » pour du naturellement
    live — ce serait un mensonge inverse).
  Tooltip (hint en tooltip, convention projet) : « Immédiat » → « Effectif en session
  ouverte dès que le poste applique le réglage (au plus tard à son prochain contact
  serveur). » ; variante restart → même phrase + « Les fenêtres de l'Explorateur sont
  rouvertes. » ; « À la prochaine session » → « Prendra effet à la prochaine ouverture
  de session Windows. » Pas de jargon (ni « logon », ni « HKCU », ni « broadcast »).
- **D6 — Dérivation dans le MODÈLE, zéro N+1.** `Capability::refreshHint(): ?string`
  (hint le plus FORT parmi les projections windows registry/registry_list dont le
  spec porte un `refresh` valide — la bi-projection prend le max) +
  `Capability::effectTiming(): ?array{label: string, tooltip: string}` (null si
  aucune clé HKCU registre → pas de badge). Les DEUX lisent la relation
  `projections` DÉJÀ eager-loaded par les 3 composants (registry-tab :51,
  capabilities-tab :100/:156, capabilities-section :105) — aucune requête ajoutée.
  La force de l'échelle côté PHP = ordre de `REFRESH_HINTS` (index croissant =
  plus fort), pas de duplication d'enum.
- **D7 — Golden : DEUX évolutions, hashes re-bumpés des deux côtés.**
  (a) l'item session `registry` existant (HideFileExt) gagne
  `"refresh": "shell_notify"` (cohérent avec le retrofit D4 — le golden illustre le
  wire RÉEL) ; (b) AJOUT d'un item session `registry_list` (conteneur
  `…CurrentVersion\Policies\Explorer\DisallowRun`, `entry_type: REG_SZ`, `values`
  illustratives, `"refresh": "policy_broadcast"`) — la portée session n'avait AUCUN
  item registry_list, et c'est le payload flagship pour 41.2. Recalculer le `hash`
  de CES items (`StateHasher::hashItem`) + `FROZEN_STATE_HASH` (PHP) et
  `frozenStateHash` (Go) « Re-bumpé SCIEMMENT par la Story 43.2 (§9 : hint `refresh`
  au payload session — évolution mineure additive) » — patron EXACT de la 43.3
  (script one-off avec le StateHasher réel, non committé, valeurs identiques des
  deux côtés). Les items machine restent STRICTEMENT inchangés (preuve du « jamais
  machine/HKU »).
- **D8 — Zéro diff source agent, zéro bump `version.go`.** Côté Go, SEUL
  `agent/shared/hasher_test.go` (constante gelée) bouge — fichier de TEST, aucun
  appelant runtime (justification identique 43.3, piège n°2 : un bump créerait une
  version fantôme). Le mécanisme agent est la 2.10.0 (43.1, déjà mergée).
- **D9 — Rollout NFR-A4 (gate Epic 35) : contrainte de DÉPLOIEMENT, pas de dev.**
  La migration de retrofit ne doit être JOUÉE en prod/VM qu'APRÈS publication
  MANUELLE de la release 2.10.0 (update.sh ne publie jamais ; état 2.6.0→2.9.0
  jamais publiées — cf. Dev Agent Record 43.1). Un binaire ≤ 2.9.0 ignore le hint
  EN SILENCE (clés écrites, aucun geste) : l'« Immédiat » affiché par l'UI serait
  un mensonge sur les postes non à jour. Les migrations VM ne sont PAS auto-jouées
  (`project_vm_migrations_not_auto_applied`) : la contrainte est consignable, pas
  exécutable ici. Le dev/tests HÔTE (sqlite, RefreshDatabase) n'est PAS concerné.

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **Piège n°1 — les invariants « EXACTEMENT N clés » deviennent FAUX si on ne les
   amende pas.** Docblocks `AbstractCapabilityStateProvider` :47-50 / :262-264,
   `AbstractRegistryListCapabilityProvider` :22, contrat §7.1 :275 (« un item
   d'écriture reste EXACTEMENT 5 clés ») et §7.6 :622 (« EXACTEMENT 4 clés ») :
   reformuler en « N clés (+ `refresh` optionnel, portée session/machine_user
   uniquement — jamais émis sur un item machine/HKU) ». L'invariant central qui NE
   BOUGE PAS : jamais d'id/key de capacité au payload.
2. **Piège n°2 — hash gelés DÉJÀ bumpés par la 43.3.** `FROZEN_STATE_HASH` /
   `frozenStateHash` valent `b1eb0560…` (ttl_seconds volatil). Recalculer À PARTIR
   de l'état mergé (cette branche), bumper les DEUX constantes à l'identique, et
   ajouter le commentaire de bump 43.2 SOUS celui de la 43.3 (patron des en-têtes).
   Ne pas oublier les `hash` PAR ITEM du fixture (test
   `each_state_item_hash_matches_state_hasher`, ContractV1Test:299-312 — item
   HideFileExt modifié + item registry_list ajouté ; côté Go les tests parsent le
   MÊME fichier `../../tests/Fixtures/Agent/state.v1.json`).
3. **Piège n°3 — drift ponctuel de re-application (NFR-A4), NE PAS « corriger ».**
   Le hint entre dans le hash d'item → au premier state compilé post-retrofit,
   chaque poste re-applique une fois les items concernés (rapport `drift` puis
   `compliant` — écriture idempotente de la même valeur + UN geste). Attendu, bénin.
   À DOCUMENTER (contrat §7.1 + Dev Agent Record), jamais à contourner (le hash est
   opaque côté agent — piège #7 de la 43.1).
4. **Piège n°4 — jamais de recopie machine/HKU (test négatif OBLIGATOIRE).**
   `RegistryMachineCapabilityProvider` expanse HKLM ET HKU (`handlesHive()`
   surchargé) ; `quick_access_hidden` a une clé HKLM dans un spec QUI PORTE un hint
   → l'item HKLM ne doit JAMAIS porter `refresh` (gate par `scope()`, pas par ruche
   — c'est ce qui rend le gate robuste aux specs mixtes). Tester explicitement :
   spec avec hint + clés HKLM/HKU/HKCU ⇒ seuls les items du provider Session
   portent le hint.
5. **Piège n°5 — écriture HKCU `…\Policies\*` douteuse (leçon hide_drives), HORS
   PÉRIMÈTRE.** La migration `2026_07_06_100000_move_hide_drives_capability_to_hklm.php`
   :29-31 consigne : sur poste joint au domaine, TOUT `…\Policies` sous HKCU est
   durci — le companion peut échouer (« Accès refusé »). `blocked_executables` et
   `registry_editing_disabled` (toujours HKCU) sont potentiellement concernés :
   tension PRÉEXISTANTE (35.2/CD95), pilotée par le lab 43.1.1 et par 41.2 — le hint
   n'aggrave RIEN (geste gated sur `changed` : pas d'écriture ⇒ pas de geste). NE
   PAS déplacer ces ruches ici, NE PAS « résoudre » — consigner.
6. **Piège n°6 — le guard tourne sur TOUT le catalogue seedé.** La règle 5 est
   exécutée par l'invariant `CapabilitiesSchemaAndSeedTest:629-633` sur l'ensemble
   des projections windows APRÈS toutes les migrations : elle doit passer sur le
   retrofit D4 (c'est le but) ET rester vraie par vacuité sur le reste. Attention à
   la règle 5b : le retrofit ne doit JAMAIS poser de hint sur
   `explorer_sidebar_pins_hidden` (HKLM only).
7. **Piège n°7 — seeds et retrofits n'importent PAS le code applicatif.** Les
   migrations dupliquent les littéraux (`'refresh' => 'shell_notify'`), comme
   `$ensure` (cf. en-tête du retrofit 35.1 :31-34). La nouvelle migration DÉCODE le
   `spec` existant, pose/retire la clé `refresh`, RÉ-ENCODE
   (`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`) — jamais de réécriture
   complète du spec (les clés `keys` d'origine sont préservées octet pour octet) ;
   `up()`/`down()` inverses exacts ; idempotente ; garde `Schema::hasTable`.
8. **Piège n°8 — UI : pas de badge inutile, pas de mensonge.** Convention
   `feedback_form_label_above_input_tooltip_hints` : le badge est une INFO de
   lecture (liste + modale), pas un hint de champ ; tooltip courte ; AUCUN badge
   pour les capacités sans clé HKCU registre (D5) — un « à la prochaine session »
   sur `internet_access` (firewall, effet immédiat) serait faux. Vérifier le rendu
   des 3 surfaces (catalogue, onglet parc, section groupes users) SANS requête
   ajoutée (D6 — relations déjà chargées).
9. **Piège n°9 — suite de tests : hôte uniquement, filtres ciblés.** PHPUnit sur
   l'HÔTE (php 8.4 + sqlite — `project_phpunit_test_env_host_vs_vm`), Go via
   `~/go-toolchain/go/bin/go` (hors PATH). Jamais de run massif VM
   (`project_vm_phpunit_bulk_run_false_failures`). Worktree : JAMAIS de VM/lab,
   jamais `git stash`, jamais `git add -A`.
10. **Piège n°10 — ne pas confondre plancher agent et déclaration serveur.** Un item
    HKCU changé SANS hint reçoit DÉJÀ `shell_notify` côté agent (43.1-D2, plancher).
    Le retrofit `shell_notify` du lot vues ne change donc RIEN au comportement
    poste : il rend la sémantique DÉCLARATIVE (spec = source de vérité) et le badge
    UI honnête (« Immédiat » au lieu de « À la prochaine session »). Ne pas
    « optimiser » en le retirant sous prétexte de redondance.

## Acceptance Criteria

### AC1 — Convention `spec.refresh` + vocabulaire fermé (FR-A2)

**Given** les constantes `CapabilityProjection::REFRESH_SHELL_NOTIFY|REFRESH_POLICY_BROADCAST|REFRESH_EXPLORER_RESTART` + `REFRESH_HINTS` (D1)
**When** une projection `registry`/`registry_list` porte `spec.refresh`
**Then** la valeur appartient au vocabulaire fermé (casse canonique minuscule) ; le
champ est OPTIONNEL (absent = « effet au prochain logon », zéro changement pour
l'existant) ; AUCUNE migration de schéma (le champ vit dans le JSON `spec`)
**And** le docblock de `CapabilityProjection` documente la convention (niveau racine
du spec, portée de recopie, renvoi contrat §7.1/§7.6).

### AC2 — AuthoringGuard : rejet à l'authoring, jamais au runtime (D2)

**Given** `CapabilitySpecCollisionGuard::violations()` étendu (règle 5)
**When** l'invariant `CapabilitiesSchemaAndSeedTest` (et tout futur appelant) valide
des projections
**Then** sont REFUSÉS avec violation nommée (capacité + valeur + vocabulaire attendu) :
`spec.refresh` non-string, valeur hors vocabulaire (dont variantes de casse
`SHELL_NOTIFY`, valeurs 41.x anticipées type `logoff`), hint sur un spec SANS
aucune clé `hive: HKCU` (hint inerte — règle 5b) — pour les DEUX mécanismes ;
**And** les 3 valeurs canoniques passent sur les deux mécanismes ; un spec sans
`refresh` passe inchangé ; `CapabilityProjectionObserver` reste SANS diff (registre
= catalogue-first) ; le catalogue seedé COMPLET (retrofit inclus) passe le guard.

### AC3 — Recopie au payload : portées Session/MachineUser uniquement (FR-A2)

**Given** `AbstractCapabilityStateProvider::withRefreshHint()` appliqué aux deux
sites de push d'`itemsFor()` (D3)
**When** une capacité dont le spec porte un hint valide est compilée
**Then** chaque payload émis par un provider de portée Session (écriture 5 clés → 6,
suppression `ensure: absent` 4 → 5, conteneur registry_list 4 → 5) porte
`"refresh": "<hint>"` en dernière clé — sources Broadcast ET override, mailles
confondues ;
**And** AUCUN payload d'un provider Machine ne porte `refresh` — y compris pour les
clés HKLM/HKU d'un spec mixte qui porte le hint (piège n°4, test négatif) ;
**And** un `spec.refresh` invalide (non-string / hors vocabulaire — donnée corrompue
hypothétique) ⇒ payload SANS `refresh`, jamais d'exception au render ; un spec sans
`refresh` ⇒ payloads BYTE-IDENTIQUES à aujourd'hui (non-régression : suites
`CapabilityRegistry{,List}{Provider,Compilation}Test` vertes) ;
**And** le mécanisme est GÉNÉRIQUE : aucun code par capacité — prêt pour le seed
`restrict_run` de 41.2 (rien d'autre à faire que poser le hint dans son spec).

### AC4 — Retrofit conservateur du lot Explorer + Policies\Explorer (D4)

**Given** une NOUVELLE migration de retrofit (patron 35.1 : décode/pose/ré-encode,
idempotente, `down()` inverse exact, littéraux dupliqués — piège n°7)
**When** les migrations sont jouées (fresh install : seed puis retrofit, ordre
chronologique)
**Then** les hints de la table D4 sont posés : `shell_notify` sur
`show_file_extensions`, `show_hidden_files`, `quick_access_history_hidden`,
`onedrive_hidden`, `quick_access_hidden`, `explorer_gallery_hidden` ;
`policy_broadcast` sur `blocked_executables` (les DEUX projections de la
bi-projection) et `registry_editing_disabled` ; AUCUN hint ailleurs (dont
`explorer_sidebar_pins_hidden`, `numlock_on_logon`,
`outlook_disable_o365_account_creation`, toutes capacités machine) ; AUCUN
`explorer_restart` ;
**And** `CapabilitiesSchemaAndSeedTest` prouve les hints seedés (assertions par
capacité) + le guard passe sur le catalogue complet (piège n°6) ;
**And** l'en-tête de la migration consigne : choix conservateur (lab
`policy_broadcast` NON validé — scénario QA 43.1.1), ajustement post-lab = UPDATE
de seed sans code, et la contrainte de rollout D9 (2.10.0 publiée AVANT migrate
prod/VM).

### AC5 — Golden PHP↔Go + contrat documenté (NFR-A4, §9)

**Given** `tests/Fixtures/Agent/state.v1.json`
**When** l'item session `registry` (HideFileExt) gagne `"refresh": "shell_notify"`
et qu'un item session `registry_list` (conteneur DisallowRun,
`"refresh": "policy_broadcast"`) est AJOUTÉ (D7)
**Then** les `hash` de ces items sont recalculés au `StateHasher` réel ;
`ContractV1Test::FROZEN_STATE_HASH` et `hasher_test.go::frozenStateHash` sont
re-bumpés À L'IDENTIQUE avec mention « Re-bumpé SCIEMMENT par la Story 43.2 » (sous
le bump 43.3 — piège n°2) ; les items machine sont STRICTEMENT inchangés ;
`ContractV1Test` + `go test ./shared/` (hôte) verts ; AUCUN fichier source Go
modifié, AUCUN bump `version.go` (D8, justification au Dev Agent Record) ;
**And** `docs/agent/contract-v1.md` : ligne `refresh` ajoutée aux tableaux §7.1 et
§7.6 (optionnel, vocabulaire fermé, portée session/machine_user uniquement, jamais
émis machine/HKU, agent ≤ 2.9.0 l'ignore sans erreur, consommation 43.1) ;
invariants « EXACTEMENT N clés » amendés (piège n°1) ; note NFR-A4 : le hint entre
dans le hash d'item → drift ponctuel de re-application à la première compilation
post-retrofit, attendu et bénin (piège n°3).

### AC6 — UI : temporalité d'effet visible (FR-A3, D5/D6)

**Given** `Capability::refreshHint()` + `Capability::effectTiming()` (D6, relations
projections déjà chargées — zéro requête ajoutée)
**When** l'admin consulte le catalogue (`/admin/settings/parc-defaults`, onglet
Registre/capacités — l'« /admin/settings/capabilities » de l'epic n'existe plus) ou
les formulaires d'assignation (onglet Options/Capacités d'un parc ; section
Capacités d'un groupe d'utilisateurs — listes ET modales d'édition)
**Then** chaque capacité à clés HKCU registre affiche son badge : « Immédiat »
(shell_notify/policy_broadcast), « Immédiat (le bureau redémarre) »
(explorer_restart), « À la prochaine session » (sans hint) — avec la tooltip D5 ;
une capacité sans clé HKCU registre (firewall, fs_acl, machine-only…) n'affiche
AUCUN badge (piège n°8) ;
**And** tests Livewire (patron `ParcDefaultsStatusBadgeTest`/
`CapabilitiesTabStatusBadgeTest`) : badge « Immédiat » pour une capacité
retrofittée, « À la prochaine session » pour une capacité HKCU sans hint, ABSENCE
de badge pour une capacité machine-only, badge restart pour un hint
`explorer_restart` forgé en test.

### AC7 — Non-régression transverse

- Suites ciblées vertes sur l'HÔTE : `tests/Unit/Services/Agent/` (providers,
  compilation, ContractV1, StateHasher), `tests/Feature/Migrations/
  CapabilitiesSchemaAndSeedTest`, `tests/Feature/Livewire/{Admin,Parc,Users}` du
  périmètre, `~/go-toolchain/go/bin/go test ./shared/` (`-C agent`).
- `StateCompiler`, `StateHasher` (source), `AgentTtlResolver`, `config/agent.php`,
  `engine.go`, `loop.go`, `refresh.go`, `version.go` : SANS diff.
- Les capacités sans hint compilent des payloads byte-identiques (AC3) — l'ETag des
  postes non concernés par le retrofit ne bouge pas.

## Tasks / Subtasks

- [x] **T1 — Vocabulaire + modèle (AC1, AC6-support)**
  - [x] `CapabilityProjection` : constantes `REFRESH_*` + `REFRESH_HINTS` + docblock
        convention (D1)
  - [x] `Capability::refreshHint()` (max de force via l'ordre de `REFRESH_HINTS`) +
        `Capability::effectTiming()` (null sans clé HKCU registre) — D6, sur relation
        chargée
- [x] **T2 — AuthoringGuard (AC2)**
  - [x] `CapabilitySpecCollisionGuard` : règle 5 (vocabulaire) + 5b (≥ 1 clé HKCU),
        violations nommées, docblock mis à jour
  - [x] Tests guard dans `CapabilitiesSchemaAndSeedTest` (accepte ×3 ×2 mécanismes ;
        rejette inconnu/casse/non-string/hint-sans-HKCU ; observer sans diff)
- [x] **T3 — Recopie provider (AC3)**
  - [x] `AbstractCapabilityStateProvider::withRefreshHint()` + application aux 2 sites
        de push d'`itemsFor()` (D3) ; docblocks « EXACTEMENT N clés » amendés
        (les deux classes abstraites — piège n°1)
  - [x] Tests : `CapabilityRegistryProviderTest` + `CapabilityRegistryListProviderTest`
        (session écrit/supprime/conteneur avec hint, Broadcast + override) ;
        négatifs machine/HKU sur spec mixte (piège n°4) ; spec.refresh invalide
        toléré au render ; byte-identité sans hint ; échantillon compilé de bout en
        bout dans `CapabilityRegistry{,List}CompilationTest` (item session avec
        `refresh`, item machine sans)
- [x] **T4 — Migration de retrofit (AC4)**
  - [x] `database/migrations/2026_07_11_100000_retrofit_capabilities_refresh_hints.php`
        (nom indicatif, horodater au jour réel) : table D4, décode/pose/retire la clé
        `refresh` (piège n°7), up/down inverses, en-tête complet (conservateur, lab
        43.1.1, rollout D9)
  - [x] `CapabilitiesSchemaAndSeedTest` : assertions hints par capacité + guard vert
        sur le catalogue complet
- [x] **T5 — Golden + contrat (AC5)**
  - [x] `state.v1.json` : hint sur l'item HideFileExt + nouvel item session
        registry_list (D7) ; hashes d'items recalculés (script one-off StateHasher,
        non committé — patron 43.3)
  - [x] `ContractV1Test::FROZEN_STATE_HASH` + `hasher_test.go::frozenStateHash`
        re-bumpés à l'identique, commentaires de bump 43.2 (piège n°2)
  - [x] `docs/agent/contract-v1.md` §7.1/§7.6 (+ note drift NFR-A4) — piège n°1/n°3
  - [x] `go -C agent test ./shared/` vert sur l'hôte ; zéro source Go modifié (D8)
- [x] **T6 — UI (AC6)**
  - [x] Badge + tooltip D5 : `registry-tab.blade.php` (colonne/ligne capacité +
        modale d'édition du défaut), `capabilities-tab.blade.php` (liste overrides +
        picker + modale), `capabilities-section.blade.php` (idem) — dérivation via
        `effectTiming()` dans les computed arrays existants
  - [x] Tests Livewire (patron StatusBadge) : présence/absence/wording des 3 badges
- [x] **T7 — Finalisation (AC7)**
  - [x] Suites ciblées hôte + diff-audit (fichiers INTERDITS sans diff)
  - [x] Dev Agent Record : justification zéro-bump Go (D8), rappel rollout D9
        (2.10.0 publiée AVANT migrate prod/VM — action hors story), drift NFR-A4

## Dev Notes

### Fichiers à toucher (exhaustif prévu)

| Fichier | Nature |
|---|---|
| `app/Models/CapabilityProjection.php` | constantes REFRESH_* + docblock |
| `app/Models/Capability.php` | `refreshHint()` + `effectTiming()` |
| `app/Services/Agent/Providers/CapabilitySpecCollisionGuard.php` | règle 5/5b |
| `app/Services/Agent/Providers/AbstractCapabilityStateProvider.php` | `withRefreshHint()` + 2 sites push + docblocks |
| `app/Services/Agent/Providers/AbstractRegistryListCapabilityProvider.php` | docblock « 4 clés » amendé (code hérité inchangé) |
| `database/migrations/2026_07_11_*_retrofit_capabilities_refresh_hints.php` | NOUVEAU — retrofit D4 |
| `tests/Fixtures/Agent/state.v1.json` | hint item session + item registry_list session |
| `tests/Unit/Services/Agent/ContractV1Test.php` | FROZEN_STATE_HASH + bump comment |
| `agent/shared/hasher_test.go` | frozenStateHash + bump comment (SEUL fichier agent) |
| `tests/Unit/Services/Agent/CapabilityRegistryProviderTest.php` | recopie/négatifs |
| `tests/Unit/Services/Agent/CapabilityRegistryListProviderTest.php` | idem list |
| `tests/Unit/Services/Agent/CapabilityRegistryCompilationTest.php` | bout-en-bout session/machine |
| `tests/Unit/Services/Agent/CapabilityRegistryListCompilationTest.php` | idem list |
| `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php` | guard + hints seedés |
| `resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php` | badge catalogue + modale |
| `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` | badge overrides + picker + modale |
| `resources/views/pages/users/groups/[id]/_partials/capabilities-section.blade.php` | badge liste + modale |
| `tests/Feature/Livewire/…` (2-3 nouveaux tests badge, patron StatusBadge) | AC6 |
| `docs/agent/contract-v1.md` | §7.1/§7.6 + note NFR-A4 |
| **INTERDITS de diff** | `agent/shared/*.go` (source), `agent/windows/**`, `version.go`, `StateCompiler.php`, `StateHasher.php`, `AgentTtlResolver.php`, `config/agent.php`, `CapabilityProjectionObserver.php`, seeds d'ORIGINE (2026_06_18/07_02/07_03/07_04) |

### Patterns existants à imiter

- **Retrofit de spec par migration** : `2026_07_03_100000_retrofit_ensure_off_on_only_capabilities.php`
  (en-tête, idempotence, up/down inverses, littéraux dupliqués sans import du code).
- **Extension du guard + invariant seedé** : `CapabilitySpecCollisionGuard` règles
  1-4 + `CapabilitiesSchemaAndSeedTest:629-692` (violations nommées, cas
  parent/enfant DisallowRun).
- **Bump de hash gelé** : Dev Agent Record 43.3 (script one-off StateHasher réel,
  valeurs jumelles PHP/Go, mention « SCIEMMENT ») + en-têtes `hasher_test.go:11-151`.
- **Badges UI + tests** : tri-état amont de `registry-tab.blade.php:301-323` (badge
  gaté, tooltip `title=`), tests `ParcDefaultsStatusBadgeTest` /
  `CapabilitiesTabStatusBadgeTest` / `GroupCapabilitiesSectionTest`.
- **Constantes de contrat partagées** : `AbstractRegistryListCapabilityProvider::ALLOWED_ENTRY_TYPES`
  (const publique consommée par le guard — même geste pour `REFRESH_HINTS` sur
  `CapabilityProjection`).

### Ce qu'il ne faut PAS faire

- PAS de migration de schéma, PAS de colonne `refresh` (le spec JSON suffit).
- PAS de hint par clé de spec (D1), PAS de valeurs hors vocabulaire « en avance »
  (logoff/service:* = hors V1, NFR-A3).
- PAS de recopie dans `expand()` (deux foyers → dérive) — un seul helper dans
  `itemsFor()`.
- PAS d'enum PHP nouveau ni de classe dédiée « RefreshHint » (sur-conception,
  `feedback_no_overengineered_choices`) : 3 constantes + 2 helpers modèle.
- PAS de badge sur les capacités machine-only (mensonge inverse, D5) ; pas de
  wording jargonneux.
- PAS toucher aux hives HKCU `…\Policies\*` existantes (piège n°5 — territoire
  41.x/lab).
- PAS de bump `version.go`, PAS de publication : story 100 % serveur.

### Project Structure Notes

- Racine = projet Laravel (`project_root_is_laravel`) ; pages Livewire SFC sous
  `resources/views/pages/**` (filesystem routing) ; badges = blade pur dans les SFC
  existants (aucun nouveau composant nécessaire).
- Aucun chevauchement de fichiers avec 43.1 (agent) ni 43.3 (compiler/config), déjà
  mergées — le SEUL point de contact est le trio golden/constantes gelées (piège
  n°2, séquentiel ici donc sans conflit).
- Tests hôte : `php artisan test --filter=…` ciblé ; Go :
  `~/go-toolchain/go/bin/go -C agent test ./shared/`.

### References

- [Source: _bmad-output/planning-artifacts/epics-application-immediate.md#Story-43.2
  + FR-A2/FR-A3 + NFR-A3/A4 + Notes de coordination (41.2 pose son hint en seed)]
- [Source: _bmad-output/implementation-artifacts/43-1-echelle-refresh-compagnon.md —
  D2 plancher, D3 indulgence agent, D7 (contrat/golden = 43.2), piège #8 publication]
- [Source: _bmad-output/implementation-artifacts/43-3-ttl-seconds-dynamique.md —
  patron bump hash gelés + piège n°8 collision golden]
- [Source: docs/agent/contract-v1.md §3.2, §7.1, §7.6, §9]
- [Source: docs/qa/domains/agent.md scénario 43.1.1 :3985-4005 — critère lab
  policy_broadcast]
- [Source: database/migrations/2026_07_06_100000_move_hide_drives_capability_to_hklm.php
  :29-31 — règle « …\Policies → HKLM », tension piège n°5]
- Code vérifié 2026-07-11 : `AbstractCapabilityStateProvider.php:47-50,173-244,262-330`,
  `AbstractRegistryListCapabilityProvider.php:22,90-150`,
  `CapabilitySpecCollisionGuard.php:74-77,86+`, `CapabilityProjectionObserver.php:46-52`,
  `CapabilitiesSchemaAndSeedTest.php:629-692`, `ContractV1Test.php:229,299-325`,
  `hasher_test.go:152`, `handler_registry.go:505`, `refresh.go:55-79`,
  `registry-tab.blade.php:44-75,270-440`, `capabilities-tab.blade.php:92-181`,
  `capabilities-section.blade.php:98-134`.

## Dépendances

- **Amont : 43.1 MERGÉE** (mécanisme agent : échelle + lecture `payload["refresh"]`,
  release 2.10.0 buildée — publication = action manuelle HORS stories, gate D9) ;
  **43.3 MERGÉE** (hash gelés déjà bumpés une fois en `b1eb0560…` — cette story les
  RE-bumpe avec le golden modifié, piège n°2).
- **Aval :** 41.2 (`restrict_run` pose son hint dans son seed — mécanisme prêt,
  AC3) ; 41.3+ (mode examen). Le verdict lab 43.1.1 peut ajuster les hints D4 par
  simple UPDATE de seed.
- **Rollout (NFR-A4)** : en prod/VM, publier la release agent **2.10.0 AVANT** de
  jouer la migration de retrofit (un binaire ≤ 2.9.0 ignore le hint en silence —
  l'UI mentirait). Dev/tests hôte non concernés. Les migrations VM ne sont pas
  auto-jouées (`migrate:status` avant tout e2e).

## Recommandation Modèle Dev

**sonnet** — confirme la pressentie epic, en connaissance du risque golden/contrat.
Le périmètre est large mais entièrement BALISÉ : chaque volet suit un patron
existant nommé ligne à ligne (guard = règles 1-4, retrofit = 35.1, badge = tri-état
29.4, recopie = helper unique à double gate). Le seul point réellement délicat — le
trio golden/hashes gelés PHP↔Go — vient d'être exécuté AVEC SUCCÈS par sonnet sur la
43.3 (même procédure : script one-off StateHasher réel, bump jumeau, tests croisés
NFR13 qui ÉCHOUENT BRUYAMMENT en cas d'erreur — le risque est auto-détecté, pas
silencieux), et la story fige ici les deux évolutions de fixture au payload près.
Aucune exploration ni arbitrage résiduel (hints D4 et wording D5 tranchés) ne
justifie opus ; la review adversariale opposée (opus) reste recommandée sur
l'exactitude des hashes recalculés et la byte-identité des payloads sans hint.

## Dev Agent Record

### Agent Model Used

Claude Sonnet 5 (worktree `ultradev/epic-43`, dev-story BMAD) — recommandation de
la story (sonnet) confirmée.

### Debug Log References

- Hash gelés recalculés au **VRAI** `StateHasher` PHP via un script one-off NON
  committé (`php <script> `, autoload composer seul, aucune dépendance HTTP/DB —
  la classe est pure) : item `registry` HideFileExt (hint `shell_notify`
  ajouté) → `8d81f541d4fe267ecf6763edf09635bdba0d33d2e59e0662c1312f800e66fbdd` ;
  nouvel item `registry_list` session (conteneur DisallowRun illustratif, hint
  `policy_broadcast`) → `698a7a3eccb1ebd7f6d8e477eb6372ecdee90e753ef29462388119689ead9422` ;
  `FROZEN_STATE_HASH` d'état complet →
  `5beb682b413ac2c5cef74baef19a17d3f47efe7cf163371201db0db954d506b0` (bump
  depuis `b1eb0560…` de la 43.3, ce même hash reporté À L'IDENTIQUE dans
  `agent/shared/hasher_test.go::frozenStateHash`).
- `go test ./shared/... ` (agent, hôte, `~/go-toolchain/go/bin/go`) : vert
  (`ok sambaedu/agent/shared`, `ok sambaedu/agent/provision`) après le bump
  jumeau — test croisé NFR13 passe du premier coup avec la valeur recalculée.
- `php vendor/bin/phpunit` ciblé (providers/compilation/ContractV1/guard/seed/
  Livewire/modèle) : 904 tests exécutés au global sur le périmètre agent +
  capacités + Livewire (14 skips pré-existants imagick/zip/LDAP, hors scope),
  0 échec après stabilisation.

### Completion Notes List

- **T1 (AC1)** — 3 constantes `CapabilityProjection::REFRESH_SHELL_NOTIFY|
  REFRESH_POLICY_BROADCAST|REFRESH_EXPLORER_RESTART` + `REFRESH_HINTS`
  (ordonnée par force croissante — single source pour guard/provider/modèle,
  D1). `Capability::refreshHint()` (max de force via `array_search` dans
  `REFRESH_HINTS`) + `Capability::effectTiming()` (D5/D6, null sans clé HKCU
  registre via un helper privé `hasHkcuRegistryKey()`) — LISENT la relation
  `projections` déjà chargée par l'appelant, zéro requête ajoutée. Zéro enum
  PHP ni classe dédiée (feedback_no_overengineered_choices).
- **T2 (AC2)** — Règle 5/5b ajoutée à `CapabilitySpecCollisionGuard::violations()`
  EN TÊTE de méthode (boucle dédiée sur `registry`+`registry_list`) : (a)
  `spec.refresh` présent et non-string OU hors `REFRESH_HINTS` → violation
  nommée citant capacité/mécanisme/valeur/vocabulaire ; (b) présent et valide
  mais AUCUNE clé/conteneur `hive=HKCU` dans la spec → violation « hint
  INERTE ». Champ ABSENT = no-op (AC1). 5 nouveaux tests unitaires directs
  (accepte ×3 valeurs ×2 mécanismes, passe sans refresh, refuse hors
  vocabulaire — casse/non-string/valeur 41.x anticipée, refuse 5b sur les DEUX
  mécanismes) + le test invariant existant
  `no_container_is_targeted_by_both_registry_scalar_and_registry_list` couvre
  DÉJÀ « catalogue seedé complet + retrofit inclus passe le guard » (piège n°6)
  — ajout d'un test dédié `guard_still_passes_on_the_full_seeded_catalog_after_the_refresh_retrofit`
  pour le nommer explicitement dans le contexte 43.2.
- **T3 (AC3)** — `AbstractCapabilityStateProvider::withRefreshHint()` : double
  gate `mechanism() ∈ {registry, registry_list}` (exclut `legacy_cleanup`/
  `fs_acl`/`firewall`/`privilege` qui héritent d'`itemsFor()` mais redéfinissent
  `mechanism()`) ET `scope() ∈ {Session, MachineUser}` (jamais Machine) ET
  `spec.refresh` string valide — sinon payload inchangé, jamais d'exception.
  Appliqué aux DEUX sites de push (Broadcast + overrides) d'`itemsFor()` ;
  `expand()` INTOUCHÉ dans les deux classes abstraites (un seul foyer, D3).
  Piège n°4 couvert par un test négatif dédié (spec mixte HKLM+HKU+HKCU + hint
  `explorer_restart` → seul l'item Session porte `refresh`). Byte-identité
  sans hint prouvée par un test dédié par mécanisme.
- **T4 (AC4)** — Migration `2026_07_11_100000_retrofit_capabilities_refresh_hints.php` :
  9 entrées (`RETROFIT` const, `key`+`mechanism`+`refresh`), `blocked_executables`
  en porte DEUX (bi-projection). `up()`/`down()` décodent/modifient/ré-encodent
  la `spec` ENTIÈRE (racine — D1, pas juste `keys` comme le patron 35.1) via un
  helper `mapSpec()` générique ; idempotent, garde `Schema::hasTable`, `down()`
  = inverse exact (retire juste `refresh`, conserve `keys` intacts). Assertions
  par capacité + tests d'idempotence/reversibilité + guard vert ajoutés à
  `CapabilitiesSchemaAndSeedTest`.
- **Piège n°7 bis (découvert en implémentant)** — rejouer isolément l'`up()`
  ORIGINAL d'un seed antérieur (`2026_07_03_110000`/`2026_07_04_100000`) APRÈS
  que le retrofit 43.2 a tourné efface `spec.refresh` (l'`up()` d'origine
  réécrit la colonne `spec` ENTIÈRE avec seulement `{"keys": …}`) : les 2 tests
  d'idempotence EXISTANTS (`registry_list_lot_migration_is_idempotent_and_reversible`,
  `explorer_lot_migration_is_idempotent_and_reversible`) rejouent CES seeds en
  isolation et échouaient donc après le retrofit. Ce n'est PAS une régression du
  retrofit (`up()`/`down()` du retrofit restent des inverses exacts l'un de
  l'autre, testés séparément) : c'est une conséquence STRUCTURELLE et attendue
  d'avoir 2 migrations successives qui écrivent la même colonne — la migration
  la plus ANCIENNE ne peut pas connaître un champ posé par une migration
  ultérieure. Corrigé en normalisant `refresh` HORS du snapshot de ces 2 tests
  (ils testent l'idempotence des champs QUE CES seeds possèdent, pas d'un
  retrofit orthogonal) — documenté inline dans chaque test. Deux tests de
  render (`blocked_executables_bi_projection_emits_flag_and_list_per_provider`,
  `quick_access_hidden_emits_split_machine_and_session_items_via_the_real_providers`)
  avaient aussi des assertions `array_keys($payload)` strictes désormais
  augmentées de `refresh` — mises à jour avec assertion de la valeur du hint.
- **T5 (AC5)** — Golden `state.v1.json` : item session `registry` (HideFileExt)
  gagne `"refresh": "shell_notify"` ; NOUVEL item session `registry_list`
  (conteneur `…\Policies\Explorer\DisallowRun`, `values: ["cmd.exe",
  "powershell.exe"]` illustratives, `"refresh": "policy_broadcast"`) — 18 items
  au total (session 7→8). Hashes recalculés au VRAI `StateHasher` (cf. Debug
  Log) ; `FROZEN_STATE_HASH`/`frozenStateHash` re-bumpés à l'identique avec
  commentaire de bump 43.2 SOUS celui de la 43.3 (patron exact). Contrat
  `docs/agent/contract-v1.md` : §7.1/§7.6 gagnent la ligne `refresh` (table +
  invariant « EXACTEMENT N clés » amendé — piège n°1), §9 gagne un exemple
  « champ ajouté » documentant le drift NFR-A4 (piège n°3).
- **Piège n°2 bis (Go, hors périmètre initial mais nécessaire)** —
  `agent/shared/contract_test.go::TestParseStateGoldenFile` compte AUSSI les
  items par portée (`machine=9 session=7 machine_user=1`, hardcodé) : cassé par
  l'ajout de l'item session. Mis à jour (`session=8`) — SEUL fichier de TEST Go
  touché en plus de `hasher_test.go` (aucun impact sur la discipline D8 : les
  DEUX fichiers modifiés sont des `_test.go`, zéro source, zéro
  `agent/shared/version.go`). `go test ./shared/...` et `go test ./...` verts.
- **D8 — Zéro diff source agent, zéro bump `version.go` (justification).** Le
  mécanisme de LECTURE de `payload["refresh"]` (`handler_registry.go`,
  `ParseRefreshLevel`) est livré par la 43.1, MERGÉE en amont de cette story :
  cette story ne fait QUE poser la donnée côté serveur (constantes + guard +
  provider + retrofit + golden + UI). Seuls `agent/shared/hasher_test.go`
  (constante gelée) et `agent/shared/contract_test.go` (compte d'items,
  ci-dessus) bougent — deux fichiers de TEST, aucun appelant runtime, aucune
  version fantôme créée (piège n°2 de la 43.1/43.3).
- **T6 (AC6)** — `Capability::effectTiming()` consommé par les 3 surfaces :
  `registry-tab.blade.php` (badge liste + modale, `editingCapability()` gagne
  `with('projections')`), `capabilities-tab.blade.php` (badge liste overrides +
  picker + modale, `overrides()`/`addableCapabilities()` gagnent `effect_timing`,
  `editingCapability()` gagne `with('projections')`), `capabilities-section.blade.php`
  (badge liste + modale, `capabilities()` portait déjà `with(['projections' =>
  …])` filtré registry Windows — suffisant car la bi-projection porte le MÊME
  hint dans ses deux specs, D4 ; `editingCapability()` gagne le même eager-load
  filtré). AUCUNE requête ajoutée (vérifié par les tests Livewire existants qui
  continuent de passer, dont les compteurs NFR3). 12 nouveaux tests Livewire
  (3 fichiers dédiés `*EffectTimingBadgeTest`, patron `ParcDefaultsStatusBadgeTest`/
  `CapabilitiesTabStatusBadgeTest`) : badge « Immédiat » (retrofit), « À la
  prochaine session » (HKCU sans hint), ABSENCE de badge (machine-only), badge
  restart (`explorer_restart` forgé en test).
- **NFR-A4 — Rollout (rappel, action HORS story)** : en prod/VM, la migration
  de retrofit `2026_07_11_100000` ne doit être JOUÉE qu'APRÈS publication
  MANUELLE de la release agent 2.10.0 (déjà buildée par la 43.1, jamais
  publiée — `update.sh` ne publie jamais seul). Un binaire ≤ 2.9.0 ignore le
  hint EN SILENCE (écrit la valeur, aucun geste) — l'« Immédiat » affiché par
  l'UI serait un mensonge sur les postes non à jour tant que 2.10.0 n'est pas
  publiée. Les migrations VM ne sont PAS auto-jouées
  (`project_vm_migrations_not_auto_applied`) : `migrate:status` avant tout
  e2e/déploiement.
- **NFR-A4 — Drift ponctuel (rappel, bénin)** : le hint `refresh` entre dans le
  `hash` de chaque item concerné (HideFileExt + le futur item `registry_list`
  DisallowRun de `blocked_executables` une fois armé). Au premier state compilé
  après le déploiement de cette migration en prod, les postes concernés
  rapporteront `drift` puis `compliant` au cycle suivant (écriture idempotente
  de la même valeur + un geste de rafraîchissement). Attendu, documenté au
  contrat §9 — NE JAMAIS « corriger » (le hash est opaque côté agent).
- **T7 (AC7)** — Diff-audit : `git status`/`git diff --stat` confirment ZÉRO
  diff sur tous les fichiers INTERDITS listés dans les Dev Notes
  (`CapabilityProjectionObserver.php`, `StateCompiler.php`, `StateHasher.php`,
  `AgentTtlResolver.php`, `config/agent.php`, `agent/shared/version.go`, tout
  fichier `agent/**/*.go` NON `_test.go`, les 4 seeds d'origine
  2026_06_18/07_02/07_03/07_04). `agent/windows/**` : aucun fichier de ce
  dossier n'apparaît dans le diff.
- **Style Pint** : `php vendor/bin/pint` appliqué sur les fichiers PHP
  nouveaux/modifiés par la story (cosmétique uniquement — quotes, imports
  triés, alignements ; aucune ligne de logique changée, revérifié par
  `git diff` + re-run des suites ciblées). Les fixers pré-existants sur les 3
  fichiers Blade touchés (dette de style antérieure à la story, confirmée par
  comparaison `git stash`) n'ont PAS été appliqués (hors scope, éviter un
  reformat non lié à la story).

### File List

**Nouveaux**
- `database/migrations/2026_07_11_100000_retrofit_capabilities_refresh_hints.php`
- `tests/Unit/Models/CapabilityRefreshHintTest.php`
- `tests/Feature/Livewire/Admin/ParcDefaultsEffectTimingBadgeTest.php`
- `tests/Feature/Livewire/Parc/CapabilitiesTabEffectTimingBadgeTest.php`
- `tests/Feature/Livewire/Users/GroupCapabilitiesSectionEffectTimingBadgeTest.php`

**Modifiés — code**
- `app/Models/CapabilityProjection.php`
- `app/Models/Capability.php`
- `app/Services/Agent/Providers/CapabilitySpecCollisionGuard.php`
- `app/Services/Agent/Providers/AbstractCapabilityStateProvider.php`
- `app/Services/Agent/Providers/AbstractRegistryListCapabilityProvider.php` (docblock uniquement)
- `resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php`
- `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php`
- `resources/views/pages/users/groups/[id]/_partials/capabilities-section.blade.php`

**Modifiés — golden/agent (test only, zéro source, zéro bump version.go)**
- `tests/Fixtures/Agent/state.v1.json`
- `agent/shared/hasher_test.go`
- `agent/shared/contract_test.go`

**Modifiés — doc**
- `docs/agent/contract-v1.md`

**Modifiés — tests (story)**
- `tests/Unit/Services/Agent/ContractV1Test.php`
- `tests/Unit/Services/Agent/CapabilityRegistryProviderTest.php`
- `tests/Unit/Services/Agent/CapabilityRegistryListProviderTest.php`
- `tests/Unit/Services/Agent/CapabilityRegistryCompilationTest.php`
- `tests/Unit/Services/Agent/CapabilityRegistryListCompilationTest.php`
- `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php`

**Sprint tracking**
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (ligne 43-2)
- `_bmad-output/implementation-artifacts/43-2-hint-refresh-projection.md` (ce fichier)

**QA**
- `docs/qa/domains/agent.md` (runbook, append-only — section « Story 43.2 »)

### Corrections post-review (2026-07-11, review opus — orchestrateur ultradev)

- **#1 (🟡, corrigé)** — Surface groupes-users (`capabilities-section.blade.php`) : les deux
  eager-loads de projections étaient filtrés `registry`-only alors que `refreshHint()` agrège
  registry ET registry_list → une future bi-projection au hint porté seulement côté
  `registry_list` aurait affiché une temporalité divergente des deux autres surfaces. Corrigé :
  eager-loads élargis aux deux mécanismes ; `isAssignableByUserGroup()` filtre désormais
  EXPLICITEMENT le mécanisme `registry` (au lieu de `projections->first()`) — sémantique
  d'assignabilité strictement inchangée.
- **#2 (🟡, corrigé)** — `use App\Services\Agent\StateHasher` retiré de la migration retrofit
  (référence docblock passée en texte brut) : une migration n'importe jamais le code applicatif
  (piège n°7 / patron 35.1). Les imports `@see` de `CapabilityProjection.php` jugés acceptables
  par la review, laissés en l'état.
- **#3 (🟡, corrigé, doc)** — `contract-v1.md` §9 : le drift ponctuel post-retrofit est désormais
  distingué par geste — `shell_notify` = iso-comportement (déjà le plancher) ; `policy_broadcast`
  (`blocked_executables`, `registry_editing_disabled`) = UN `WM_SETTINGCHANGE("Policy")` broadcast
  par poste assigné à sa première convergence post-migration (le geste standard du moteur GPO,
  inoffensif, borné à un tir par poste).
