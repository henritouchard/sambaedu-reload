# Capacités v2 — couverture des GPO spéciales (CD95) - Epic 35 Breakdown

Date : 2026-07-02
Source : analyse des 11 GPO `../GPO_spécialesCD95` (session 2026-07-02) + palier A déjà livré
(migration `2026_07_02_100000_seed_capabilities_gpo_cd95_lot.php`, 11 capacités seedées).
Mémoire projet : `project_gpo_cd95_capability_lot`.

## Overview

Le palier A de l'analyse CD95 a montré que le modèle capability-first (Epic 27.12) couvre
déjà tous les toggles/enums registre HKLM/HKCU à valeur fixe. Restent **quatre murs** qui
empêchent de porter le reste des GPO ad-hoc — et, plus largement, toute GPO du même genre
qu'un autre département apporterait :

1. **Pas de verbe `delete`** : « cesser de gérer » laisse un résidu ; les capacités on-only
   (`llmnr_disabled`, `windows_updates_managed`) ne peuvent pas proposer d'« off » honnête.
2. **Pas de listes à sous-clés indexées `\1 \2 …`** (`ExtensionInstallForcelist`,
   `DisallowRun`) : l'interpréteur de `spec` n'émet que des items à `name` fixe, et rien ne
   réconcilie les entrées surnuméraires.
3. **Pas d'écriture des ruches utilisateur par SYSTEM** : `HKU\.DEFAULT` (écran de logon)
   et `HKCU\Software\Policies\*` (lecture seule pour l'utilisateur → le companion échoue)
   sont hors d'atteinte.
4. **Pas de mécanisme hors-registre** : les Privilege Rights LSA (`SeDeny*`) relèvent de
   `secedit`/LSA, pas du registre.

S'y ajoute un trou **produit** (pas moteur) : le ciblage d'une capacité par **groupe
d'utilisateurs** est supporté en base (`capability_assignments` polymorphe) mais aucun
geste UI ne l'expose — les capacités CD95 ciblées (Outlook direction, regedit élèves)
sont seedées mais inarmables.

**Découverte de cadrage qui simplifie l'epic** : `DisableCMD` (la seule clé qui semblait
exiger une écriture SYSTEM *par utilisateur*, donc un broker d'élévation
companion→service) est en réalité couverte par `DisallowRun` + `cmd.exe` : la GPO CD95
elle-même laissait les scripts autorisés (« Désactiver aussi les scripts : Non »), donc
bloquer l'exécutable interactif est iso-intention. `DisallowRun` vit dans le tree
restrictions user-writable → portée Session, ciblable par groupe, **zéro broker**.
Le broker d'élévation est donc explicitement HORS epic (sur-conception évitée).

## Décisions structurantes

- **D1 — Contrat additif uniquement.** Chaque évolution du contrat `se5.desired-state/v1`
  est un ajout (champ optionnel ou nouveau type) : bump mineur, golden files mis à jour
  avec justification, agents antérieurs non cassés (champ inconnu ignoré, type inconnu
  non géré). Jamais de retrait/renommage.
- **D2 — `StateCompiler` intouché.** Les nouvelles sémantiques passent par `exclusiveKey()`
  des providers (identité de clé pour `registry_list` = la clé-conteneur) — aucune
  précédence nouvelle au compilateur.
- **D3 — L'agent possède la clé-conteneur d'une liste.** Réconciliation au niveau de la
  clé (écrire `1..N`, supprimer les valeurs numérotées surnuméraires), pas de la valeur.
  C'est la seule sémantique qui gère « l'admin retire une entrée ».
- **D4 — Résolution SID côté agent** (mécanisme `privilege`) : le poste joint au domaine
  résout `DOMAIN\name` → SID via LSA ; le serveur reste en lecture Postgres pure (NFR7),
  aucun SID synchronisé en SQL.
- **D5 — Une capacité peut porter plusieurs projections** (même OS, mécanismes distincts) :
  l'unique `(capability_id, os, mechanism)` le permet déjà. Ex. `blocked_executables` =
  projection `registry` (flag `DisallowRun=1`) + projection `registry_list` (les entrées).
- **D6 — Gate métier sur le mécanisme `privilege`** : un mécanisme entier pour un seul
  consommateur connu (RDP élèves) dont il existe des alternatives (parc-level
  `remote_desktop_enabled`, futur `localgroup`). La story ne s'ouvre qu'après validation
  du besoin par Henri.

## Garde-fous d'epic (toutes stories)

- Toute story touchant `agent/**` : bump `agent/shared/version.go` + rappel que
  `update.sh` ne publie jamais seul (piège « handler absent du binaire publié »).
- Golden files (`tests/Fixtures/Agent/`) : tout changement de forme est justifié dans la
  story (règle d'évolution du contrat 23.1).
- Zéro AD/LdapRecord/APCu dans les providers (critère Keycloak).
- Drift policy STRICT (27.8) inchangée ; zéro float dans les payloads.
- Tests sur l'HÔTE (php8.4 + sqlite), filtres ciblés ; migrations à rejouer sur /vm
  signalées en fin de story.
- Reco dev : **fable** pour les stories touchant l'agent Go (35.1, 35.2, 35.3, 35.6),
  opus pour l'UI (35.4) et le seed (35.5).

## Epic List

### Epic 35 : Capacités v2 — verbe delete, listes indexées, ruche HKU, ciblage par groupe

Étendre le mécanisme `registry` capability-first pour couvrir l'intégralité des GPO
spéciales CD95 (paliers B/C de l'analyse) et exposer le geste UI d'override par groupe
d'utilisateurs. Livrable final : les 11 GPO CD95 intégralement remplaçables par des
capacités, et un moteur capable d'absorber les GPO ad-hoc équivalentes d'autres
départements sans nouvelle évolution.

Séquencement : 35.1 (socle) → 35.2 (en dépend pour la réconciliation) ; 35.3, 35.4 et
35.5 indépendantes entre elles (parallélisables après 35.1 pour la 35.3) ; 35.6 gated.

---

## Epic 35 : Capacités v2 — verbe delete, listes indexées, ruche HKU, ciblage par groupe

### Story 35.1 : Verbe `ensure` — present/absent sur les items registry (socle delete)

En tant que référent numérique,
je veux qu'une capacité désactivée puisse SUPPRIMER ses clés de registre,
afin que « off » rende la main à Windows au lieu de laisser un résidu ou d'être un no-op interdit.

**Acceptance Criteria:**

**Given** le contrat `se5.desired-state/v1`
**When** un item `registry` porte le nouveau champ optionnel `ensure ∈ present|absent`
**Then** l'absence du champ vaut `present` (rétro-compatible, ajout mineur)
**And** un item `absent` porte `{hive, path, name, ensure}` sans `value` ni `type` exigés
**And** les golden files state/report sont mis à jour avec justification, et le champ
entre dans la canonicalisation du `StateHasher` (deux états qui ne diffèrent que par
`ensure` ont des hashes distincts).

**Given** une `spec` de projection dont une map porte le marqueur réservé
`'off' => {'$ensure': 'absent'}`
**When** `AbstractCapabilityStateProvider::expand()` résout la valeur effective `off`
**Then** un item `ensure:absent` est émis pour cette clé (au lieu de la sentinelle
UNMANAGED qui n'émettait rien)
**And** la sentinelle UNMANAGED (« cesser de gérer ») reste disponible et distincte —
les trois régimes coexistent : écrire / supprimer / ne pas gérer.

**Given** le handler Go `registry` (portées Machine ET Session)
**When** il converge un item `ensure:absent`
**Then** la valeur est supprimée si présente, l'item est `compliant` si déjà absente
**And** le test/apply/report suit la policy STRICT (drift si la valeur réapparaît).

**Given** les capacités on-only du parc (`llmnr_disabled`, `windows_updates_managed`)
**When** la migration de retrofit est jouée
**Then** elles exposent un vrai « off » (`$ensure: absent` sur chaque clé) et leurs
`options` abandonnent le régime « Géré » on-only
**And** l'invariant « un off proposé fait une vraie action » est satisfait par la
suppression (mise à jour du test `on_off_capabilities_emit_a_real_value_for_off`).

**Given** l'agent modifié
**Then** `agent/shared/version.go` est bumpé et la note de publication rappelle
l'amorçage manuel (update.sh ne publie pas).

### Story 35.2 : Type `registry_list` — listes à sous-clés indexées `\N`

En tant que référent numérique,
je veux imposer des listes registre à entrées numérotées (extensions forcées, exécutables interdits),
afin de remplacer les GPO CD95 « ExtensionPix » et « Blocages élèves » par des capacités.

**Acceptance Criteria:**

**Given** le contrat v1
**When** le type `registry_list` est publié (ajout additif à `StateContract::RESOURCE_TYPES`,
`semantics: exclusive`)
**Then** le payload est `{hive, path, entry_type, values}` avec `values` = liste ordonnée
de chaînes et `entry_type ∈ REG_SZ|REG_EXPAND_SZ`
**And** golden files et documentation contrat (§7) mis à jour.

**Given** les providers serveur (`Machine` pour HKLM, `User` pour HKCU, mêmes casiers que
`registry`)
**When** une projection `registry_list` est expansée
**Then** `exclusiveKey()` = `{hive|path}` normalisé — la maille la plus spécifique gagne
la clé-conteneur ENTIÈRE, `StateCompiler` inchangé (D2)
**And** la map valeur-capacité → liste fonctionne comme pour `registry`
(`'on' => ['a','b']`, valeur absente ⇒ non émis).

**Given** le garde-fou serveur
**When** une clé-conteneur est ciblée à la fois par un item `registry` scalaire et un
`registry_list`
**Then** la validation d'authoring refuse (erreur explicite), aucune collision silencieuse.

**Given** le handler Go `registry_list`
**When** il converge une clé-conteneur
**Then** il écrit les valeurs nommées `1..N` dans l'ordre, supprime toute autre valeur
au nom numérique de la clé (réconciliation D3, s'appuie sur le delete de 35.1)
**And** une liste `values` vide supprime toutes les entrées numérotées
**And** test/apply/report par clé-conteneur (un statut par item, policy STRICT).

**Given** le seed du lot
**Then** deux capacités naissent :
- `pix_extension_forced` (Machine, opt-in `unmanaged`) : Forcelist Chrome
  (`pgpjajcmfbfdmcgjlbiengidaknopaok`) + Edge (id;url update CRX) ;
- `blocked_executables` (Session, opt-in `unmanaged`, cible = override UserGroup élèves) :
  projection `registry` (flag `Explorer\DisallowRun = 1`, `$ensure: absent` en off) +
  projection `registry_list` (`DisallowRun\N` = powershell.exe, powershell_ise.exe,
  pwsh.exe, mstsc.exe, **cmd.exe** — remplace `DisableCMD`, iso-intention CD95 : scripts
  non bloqués) — première capacité bi-projection (D5).

### Story 35.3 : Ruche `HKU` — écriture SYSTEM des ruches utilisateur

En tant que référent numérique,
je veux diffuser des clés per-user que seule la machine peut écrire (écran de logon, trees policy),
afin de couvrir `HKU\.DEFAULT` (numlock au logon) et `HKCU\Software\Policies\*` sans companion.

**Acceptance Criteria:**

**Given** une clé de `spec` avec `hive: 'HKU'`
**When** les providers filtrent par ruche
**Then** elle est émise par le provider MACHINE (portée Machine, appliquée par SYSTEM),
jamais par le provider Session
**And** la validation d'authoring documente la sémantique : « toutes les ruches
utilisateur du poste + .DEFAULT » — pas de ciblage par utilisateur sur cette ruche
(la portée machine n'a pas de contexte user ; le ciblage fin reste au Session/HKCU).

**Given** le handler Go `registry` (service SYSTEM)
**When** il converge un item `hive: HKU`
**Then** il l'applique à `HKU\.DEFAULT` ET à chaque ruche utilisateur chargée
(`HKU\<SID>` des sessions ouvertes), à chaque cycle de convergence
**And** une session ouverte après coup est couverte au cycle suivant
**And** le drift est agrégé : UNE ruche divergente ⇒ l'item rapporte `drift`.

**Given** le lot CD95
**When** la migration de complément est jouée
**Then** `numlock_on_logon` gagne la clé `HKU` (`.DEFAULT\Control Panel\Keyboard\
InitialKeyboardIndicators = 2`) exclue du palier A — le numlock vaut aussi à l'écran
de logon
**And** le commentaire de seed documente le nouveau débouché : toute clé
`HKCU\Software\Policies\*` devient diffusable en machine/parc via `HKU`
(le contournement HKLM type fix-Copilot n'est plus le seul chemin).

**Given** l'agent modifié
**Then** bump `agent/shared/version.go`, golden files inchangés si aucun champ nouveau
(HKU est une valeur de `hive`, pas un champ).

### Story 35.4 : Geste UI — override de capacité par groupe d'utilisateurs

En tant que référent numérique,
je veux armer une capacité pour un groupe d'utilisateurs (élèves, direction, vie scolaire),
afin d'utiliser réellement les capacités CD95 ciblées déjà seedées (Outlook, regedit) et à venir (exécutables interdits).

**Acceptance Criteria:**

**Given** la page d'édition d'un groupe d'utilisateurs (convention : la propriété
par-groupe vit sur la page du groupe, pas sur une page capacités centrale)
**When** l'admin ouvre la section « Capacités »
**Then** il voit les capacités actives assignables avec, pour ce groupe : la valeur
d'override si elle existe, sinon « suit le défaut » (défaut affiché avec son libellé
d'option)
**And** il peut poser un override (sélecteur piloté par `value_type`/`options`, warning
de capacité affiché et confirmé), le modifier, le retirer (retour au défaut au cycle
suivant — pas « cesser de gérer »).

**Given** une capacité `overrides_locked`
**Then** l'ajout d'un nouvel override par groupe est refusé (lecture seule, message).

**Given** un override posé/modifié/retiré
**Then** une ligne `capability_override_audit_logs` est écrite (auteur, groupe, valeur
avant/après), cohérente avec l'audit des overrides par parc.

**Given** la délégation d'établissement
**Then** le geste est gaté par la permission capacités existante ET scopé à
l'établissement du groupe (pas de Gate global non scopé — anti-piège délégation WPKG).

**Given** la précédence
**Then** aucun changement compilateur : la maille `UserGroup` existe déjà
(`resolveOverrides`/`mailleFor`) ; un test d'intégration prouve qu'un override
UserGroup bat le Broadcast pour un user membre, sur une capacité Session du lot CD95
(`registry_editing_disabled`).

### Story 35.5 : Capacité `photo_viewer_restored` — seed sans évolution moteur

En tant que référent numérique,
je veux restaurer la visionneuse de photos Windows sur les postes,
afin de remplacer la GPO CD95 « Ajustement_Photo » sans attendre le domaine associations.

**Acceptance Criteria:**

**Given** le mécanisme `registry` ACTUEL (aucune évolution moteur — l'exclusion du
palier A était sémantique, pas technique)
**When** la capacité `photo_viewer_restored` est seedée (toggle, opt-in `unmanaged`,
portée Session)
**Then** sa projection porte les 4 clés HKCR routées `HKCU\Software\Classes` :
`Applications\photoviewer.dll\shell\open\command` et `shell\print\command`
(REG_EXPAND_SZ, rundll32 ImageView_Fullscreen) + les 2 `DropTarget\Clsid` (REG_SZ)
**And** en `off` (via 35.1 si livrée : `$ensure: absent` ; sinon on-only honnête).

**Given** la limite du périmètre
**Then** la story documente que la capacité RÉENREGISTRE la visionneuse (iso-GPO CD95,
qui ne touchait pas UserChoice) ; le choix effectif de l'app par extension relève du
composer d'associations existant (27.11) — hors story.

### Story 35.6 : Mécanisme `privilege` — droits LSA `SeDeny*` (GATED)

> **Gate D6 — ne s'ouvre qu'après validation métier par Henri.** Besoin unique connu :
> « les élèves ne peuvent pas ouvrir de session RDP » (GPO Blocages_eleves). Alternatives
> à évaluer d'abord : `remote_desktop_enabled=off` par parc (déjà livré), futur mécanisme
> `localgroup` (Remote Desktop Users).

En tant que référent numérique,
je veux refuser un droit de logon Windows à un groupe (ex. RDP pour les élèves),
afin de couvrir la dernière brique de la GPO « Blocages élèves ».

**Acceptance Criteria:**

**Given** le contrat v1
**When** le type `privilege` est publié (ajout additif, `semantics: exclusive`, portée
Machine)
**Then** le payload est `{privilege, accounts}` avec `accounts` = liste `DOMAIN\name`
**And** `exclusiveKey()` = le nom du privilège.

**Given** la validation d'authoring
**Then** seuls les privilèges `SeDeny*` (vides par défaut sous Windows) sont acceptés —
les droits *grant* (`SeInteractiveLogonRight`, …) sont refusés : une convergence
exclusive sur un grant peut verrouiller une machine.

**Given** le handler Go `privilege` (SYSTEM)
**When** il converge un item
**Then** il résout chaque compte en SID via LSA (`LsaLookupNames`, D4 — zéro SID en SQL),
possède la liste ENTIÈRE du privilège (ajoute les manquants, retire les surnuméraires)
**And** un compte irrésoluble ⇒ item en `error` avec détail, pas d'application partielle
silencieuse.

**Given** la projection capacité
**Then** nouveau `mechanism: privilege` dans `capability_projections`, spec
`{privilege, accounts}` avec map valeur-capacité possible (`'on' => [...]`), capacité
`rdp_denied_for_group` seedée opt-in (le groupe cible vient de la donnée d'établissement,
pas du seed).

**Given** l'agent modifié
**Then** bump version + note de publication (nouveau handler = piège binaire antérieur).

---

## Couverture finale (GPO CD95 → capacités)

| GPO CD95 | Couverture | Story |
|---|---|---|
| Blocage_Actualités | `news_and_interests_off` | palier A (livré) |
| LMNNR | `llmnr_disabled` (+ off honnête) | livré + 35.1 |
| Capot_portables | `laptop_lid_action` | palier A (livré) |
| Verr_num | `numlock_on_logon` (+ écran de logon) | livré + 35.3 |
| Session_hors_reseau | `cached_logons_count` | palier A (livré) |
| Masquage_lecteurC | `hide_drives` | palier A (livré) |
| Compte_outlook | `outlook_disable_o365_account_creation` + armement | livré + 35.4 |
| Ajustement_Photo | `hide_last_username`, `onlyoffice_auto_update_off`, `numlock_on_logon` (livrés) + `photo_viewer_restored` | livré + 35.5 |
| Profil_Appx | `appx_special_profiles_allowed` | palier A (livré) |
| ExtensionPix | `pix_extension_forced` | 35.2 |
| Blocages_eleves | `registry_editing_disabled` (livré) + `blocked_executables` (cmd/powershell/mstsc) + `rdp_denied_for_group` | livré + 35.2 + 35.4 + 35.6 (gated) |
