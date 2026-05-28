# Matrice Profils × Droits — SambaEdu (legacy → Spatie)

> **Statut** : décisions actées 2026-04-22 avec Henri, enums Laravel mis à jour en conséquence.
> **Auteur** : John (PM) — audit 1h30 du code legacy `sambaedu/` + enums Laravel `app/Enums/*`.
> **Contexte** : Epic 7 — socle Spatie livré, matrice de référence profils × droits pour :
> - documenter le mapping legacy ↔ Spatie **pendant la phase de coexistence**,
> - cadrer les Stories 7.1 / 7.2 / 7.3,
> - servir de base aux Policies et aux tests du `PermissionService`.

---

## 0. Périmètre — Spatie gère les droits web, pas l'état AD/GPO

Le legacy mélange dans un même bitmask deux choses de nature différente :

| Axe | Exemple concret | Modèle |
|---|---|---|
| **État AD/GPO** porté par un user cible | "L'élève X est admin local sur sa machine" (bit poussé dans un attribut LDAP, consommé par GPO) | Attribut AD, **hors** Spatie |
| **Permission web d'agir** | "L'user Y a le droit *d'élever* un user, *d'installer* un poste, *de réinitialiser* un MDP" | **Dans Spatie** |

**Décision 2026-04-22** : `SambaPermission` ne représente **que** les permissions web. Les libellés doivent désigner sans ambigüité l'**action** portée par le droit, pas un état résultant.

Exemple : `ComputerElevate` ≠ "être admin local" ; c'est "avoir le droit d'accorder l'admin de poste via l'UI". Les labels `SambaPermission::label()` ont été clarifiés en ce sens :
- `ComputerElevate` → "Admin de poste" (avant : "Admin local (élévation)")
- `ComputerInstall` → "Installer un poste" (avant : "Installation automatisée")

---

## 1. Executive summary (post-décisions 2026-04-22)

| Verdict | Élément | Détail |
|---|---|---|
| ✅ | Bitmasks atomiques | `LegacyRight.php` 1:1 avec `sambaedu/includes/ldap.inc.php:2948-2976`. Aucune dérive. |
| ✅ | Composites (`userAdmin`, `eleveAdmin`, `shareAdmin`, `computerAdmin`, `admin`) | Valeurs strictement identiques au legacy. |
| ✅ | `SambaRole::SuperAdmin` | Équivalent `se3_is_admin` legacy. Reçoit automatiquement les 2 perms neuves (Wallpaper, AppCustomize). |
| ✅ | `SambaRole::UserAdmin` | Équivalent strict `Annu_is_admin` legacy (0xFF). |
| ✅ | `SambaRole::EleveAdmin` | Équivalent strict `sovajon_is_admin` legacy (0x07). |
| ✅ | `SambaRole::ComputerAdmin` | Aligné sur `computer_is_admin` legacy (0xEF00) + `WpkgCreate` + `AppCustomize` (évolutions Epic 7 documentées). `WallpaperManage` retiré de ce rôle. |
| ✅ | `SambaRole::ReferentNumerique` | **Aligné sur `RefNum` legacy** (0x90B) : UserPasswordInit + UserRead + UserCreateTemp + ComputerView + ComputerInstall. |
| ✅ | `SambaRole::Prof` | `UserRead + UserPasswordInit`. Le `UserPasswordInit` est **scopé par classe** (reproduit le comportement `sovajon_is_admin` legacy) — logique portée par une Policy à implémenter en **Story 7.2**. |
| 🆕 | `SambaRole::ShareAdmin` | Rôle créé pour Epic 7. Composite `SE_SHARE_ADMIN` (0xC0) existait en legacy mais aucun profil seedé ne le portait. |
| 🆕 | `SambaRole::Technicien` | Rôle créé pour Epic 7. Sous-ensemble de `SE_COMPUTER_ADMIN`. Aucun équivalent legacy direct. |
| ✅ | Négations (`no_*`) | Couvertes par `PermissionService::negateDelegation()` + flag sur `Delegation`. |
| ✅ | Délégations scopées (parc → workstation_group) | Couvertes par `PermissionService::grantDelegation` + `canOnWorkstationGroup`. |
| ⚠️ | Mapping bitmask legacy (`SambaPermission::legacyRight()`, `LegacyRight`, `fromBitmask`…) | **Dette en sursis** — voir §11. Disparaît après Story 7.3. |

**Verdict global** : socle Spatie **cohérent** avec le legacy après application des décisions 2026-04-22. Prêt à alimenter les Stories 7.1 → 7.3.

---

## 2. Bitmasks atomiques — cohérence legacy ↔ `LegacyRight`

Source legacy : `sambaedu/includes/ldap.inc.php:2948-2976`.

| Constante legacy | Valeur | `LegacyRight` case | Cohérence |
|---|---|---|---|
| `SE_NO_RIGHT` | `0` | (implicite) | ✅ |
| `SE_USER_PASSWORD_INIT` | `0x01` | `UserPasswordInit` | ✅ |
| `SE_USER_READ` | `0x02` | `UserRead` | ✅ |
| `SE_USER_MODIFY` | `0x04` | `UserModify` | ✅ |
| `SE_USER_CREATE_TEMP` | `0x08` | `UserCreateTemp` | ✅ |
| `SE_USER_ASSIGN_RIGHT` | `0x10` | `UserAssignRight` | ✅ |
| `SE_USER_DELEGATE` | `0x20` | `UserDelegate` | ✅ |
| `SE_SHARE_VIEW` | `0x40` | `ShareView` | ✅ |
| `SE_SHARE_REFRESH` | `0x80` | `ShareRefresh` | ✅ |
| `SE_COMPUTER_VIEW` | `0x100` | `ComputerView` | ✅ |
| `SE_COMPUTER_CONTROL` | `0x200` | `ComputerControl` | ✅ |
| `SE_COMPUTER_ELEVATE` | `0x400` | `ComputerElevate` | ✅ |
| `SE_COMPUTER_INSTALL` | `0x800` | `ComputerInstall` | ✅ |
| `SE_WPKG_ASSIGN` | `0x1000` | `WpkgAssign` | ✅ |
| `SE_WPKG_ADD` | `0x2000` | `WpkgAdd` | ✅ |
| `SE_WPKG_CREATE` | `0x4000` | `WpkgCreate` | ✅ |
| `SE_SERVER_ADMIN` | `0x8000` | `ServerAdmin` | ✅ |

---

## 3. Composites legacy ↔ helpers `LegacyRight`

Source : `sambaedu/includes/ldap.inc.php:2957,2959,2961,2973,2976`.

| Composite legacy | Valeur | Composition | Helper Laravel |
|---|---|---|---|
| `SE_SHARE_ADMIN` | `0xC0` | `ShareView \| ShareRefresh` | `LegacyRight::shareAdmin()` |
| `SE_ELEVE_ADMIN` | `0x07` | `UserPasswordInit \| UserRead \| UserModify` | `LegacyRight::eleveAdmin()` |
| `SE_USER_ADMIN` | `0xFF` | Tous les bits User + Share | `LegacyRight::userAdmin()` |
| `SE_COMPUTER_ADMIN` | `0xEF00` | 4 bits Computer + 2 bits Wpkg (pas `WpkgCreate`) | `LegacyRight::computerAdmin()` |
| `SE_ADMIN` | `0xFFFF` | Tous les bits | `LegacyRight::admin()` |

---

## 4. Profils legacy effectifs

### 4.1 Profils seedés à l'installation

Source : `sambaedu/includes/ldap.inc.php:739-743`.

| # | Nom groupe legacy | Description | Bitmask | Hex | Rôle Spatie correspondant |
|---|---|---|---|---|---|
| 1 | `se3_is_admin` | Super utilisateur | `SE_ADMIN` | `0xFFFF` | `SuperAdmin` |
| 2 | `computer_is_admin` | Gestion des machines | `SE_COMPUTER_ADMIN` | `0xEF00` | `ComputerAdmin` |
| 3 | `Annu_is_admin` | Gestion de l'annuaire | `SE_USER_ADMIN` | `0xFF` | `UserAdmin` |
| 4 | `password_is_admin` | Gestion des mots de passe | `SE_USER_PASSWORD_INIT` | `0x01` | *(Délégation ciblée `user.password.init`, pas un rôle)* |
| 5 | `RefNum` | Profil des référents numériques | (composite) | `0x90B` | `ReferentNumerique` |

### 4.2 Profils historiques reconnus par fallback UI

Source : `sambaedu/annu/profiles.php:56-63`. Non seedés, mais remappés si un groupe LDAP portant ce nom existe sans `info`.

| Nom groupe | Bitmask | Rôle Spatie |
|---|---|---|
| `sovajon_is_admin` | `SE_ELEVE_ADMIN` (0x07) | `EleveAdmin` |
| `annu_can_read` | `SE_USER_PASSWORD_INIT \| SE_USER_READ` (0x03) | Proche de `Prof` (voir §5.3 pour le scoping) |
| `password_can_reinit` | `SE_USER_PASSWORD_INIT` (0x01) | *(Délégation ciblée)* |
| `computer_is_admin` | `SE_COMPUTER_ADMIN` (idem seed) | `ComputerAdmin` |
| `Annu_is_admin` | `SE_COMPUTER_ADMIN` ⚠️ | *(Bug historique — on ignore ce fallback, on garde seulement le seed `SE_USER_ADMIN`)* |

### 4.3 Négations et délégations scopées

| Concept legacy | Source | Équivalent Spatie |
|---|---|---|
| Profil négatif `no_*` | `ldap.inc.php:3113-3121` (`$right &= ~($group['info'])`) | `Delegation` avec flag `negative` + `PermissionService::negateDelegation()` |
| Délégation de parc | `ldap.inc.php:3123-3128` (`$right \|= $d['level']`) | `PermissionService::grantDelegation(user, perm, workstationGroup)` + `canOnWorkstationGroup()` |
| Profil par appartenance récursive | `list_right_profiles()` | Rôles Spatie assignés directement ou via sync `syncFromAd` |

---

## 5. Matrice Profils × Droits

**Légende** : ✅ = assigné | ❌ = non assigné | 🔒 = scopé (Policy) | 🆕 = perm sans équivalent legacy atomique.

### 5.1 Matrice legacy — profils LDAP × bits atomiques

| Profil legacy \ Bit | PwdInit | Read | Modify | CreatTmp | AsgRght | Delegate | ShrView | ShrRefr | CompView | CompCtrl | CompElev | CompInst | WpkgAsg | WpkgAdd | WpkgCrt | SrvAdm |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `se3_is_admin` (0xFFFF) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `Annu_is_admin` (0xFF) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `computer_is_admin` (0xEF00) | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| `RefNum` (0x90B) | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `password_is_admin` (0x01) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `sovajon_is_admin` (0x07) | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

### 5.2 Matrice Spatie — rôles × permissions (post-décisions 2026-04-22)

Source : `SambaRole::permissions()` après edits du 2026-04-22.

> **Ajout 7.3 — décision Henri 2026-04-25** : 19ᵉ permission `c.remote.rdp` (`computer.remote.rdp`)
> ajoutée pour la migration des délégations RDP legacy (option C). Partage le bit
> legacy `0x200` avec `c.control` mais reste une permission Spatie distincte
> (gouvernance fine RDP). Attribuée par défaut à `ComputerAdmin` et `SuperAdmin`
> (via `SambaPermission::cases()`). Cf. §5.3 — mapping `rdp_<parc>` → `computer.remote.rdp`.

| Rôle Spatie | u.pwd.init | u.read | u.modify | u.create.temp | u.assign.right | u.delegate | s.view | s.refresh | c.view | c.control | c.elevate | c.install | w.assign | w.add | w.create | s.server.admin | wallpaper.manage | app.customize | c.remote.rdp |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `Eleve` | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `Prof` | 🔒 | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `EleveAdmin` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `ShareAdmin` | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `UserAdmin` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `Technicien` | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `ReferentNumerique` | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `ComputerAdmin` | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ 🆕 | ❌ | ❌ | ✅ 🆕 | ✅ 🆕 7.3 |
| `SuperAdmin` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ 🆕 | ✅ 🆕 | ✅ 🆕 7.3 |

**🔒 `Prof::UserPasswordInit`** = **permission scopée par classe** (Policy Story 7.2). Le Prof n'a le droit de réinitialiser le MDP que des élèves des classes qu'il encadre — comportement legacy `sovajon_is_admin` (cf `annu/people.php:254-255` : *"Test de l'appartenance à la classe pour le droit sovajon_is_admin"*). Le champ `type` du `UserGroup` (relation Eloquent confirmée par Henri) servira à filtrer les classes.

### 5.3 Correspondance profil legacy ↔ rôle Spatie

| Profil legacy | Rôle Spatie | Statut post-décisions |
|---|---|---|
| `se3_is_admin` (0xFFFF) | `SuperAdmin` | ✅ équivalent exact sur bits legacy ; reçoit automatiquement les 2 perms neuves (Wallpaper, AppCustomize) |
| `Annu_is_admin` (0xFF, seed) | `UserAdmin` | ✅ équivalent exact |
| `computer_is_admin` (0xEF00) | `ComputerAdmin` | ✅ aligné + évolution documentée : ajout `WpkgCreate` + `AppCustomize` (convention bit représentant, cf §11) |
| `sovajon_is_admin` (0x07) | `EleveAdmin` | ✅ équivalent exact |
| `password_is_admin` (0x01) | *(Permission directe ciblée)* | Migration 7.3 : `$user->givePermissionTo('user.password.init')` directement (pas de rôle, anti-escalade #1 décision Henri 2026-04-25) |
| `annu_can_read` (0x03, fallback) | `Prof` 🔒 | Legacy = `UserPasswordInit + UserRead` global. Spatie = `UserRead` global + `UserPasswordInit` **scopé par classe** (plus restrictif et plus sûr que le legacy) |
| `RefNum` (0x90B) | `ReferentNumerique` | ✅ aligné bit à bit sur legacy |
| *(aucun)* | `ShareAdmin` | 🆕 création Epic 7 (composite `SE_SHARE_ADMIN` existait mais pas seedé) |
| *(aucun)* | `Technicien` | 🆕 création Epic 7 |
| *(aucun)* | `Eleve` | ✅ équivalent implicite (0 droit par défaut) |

**Mapping délégations legacy `delegations_rdn` → permissions Spatie (Story 7.3)** :

| CN legacy (positif / négatif) | Permission Spatie cible | Note |
|---|---|---|
| `manage_<parc>` / `no_manage_<parc>` | `computer.elevate` (0x400) | `level=manage` du legacy (`OU=rights/manage` `info=0x400`) |
| `view_<parc>` / `no_view_<parc>` | `computer.view` (0x100) | `level=view` du legacy (`OU=rights/view` `info=0x100`) |
| `rdp_<parc>` / `no_rdp_<parc>` | `computer.remote.rdp` 🆕 | **ajout 7.3 — décision Henri 2026-04-25 (option C)** : nouvelle permission Spatie dédiée plutôt que de réutiliser `computer.control`. Permet une gouvernance fine RDP côté établissement. Partage le bit legacy `0x200` (`SE_COMPUTER_CONTROL`) côté `legacyRight()` mais reste distincte côté Spatie. |

---

## 6. Usage réel des droits dans le code legacy

Occurrences `SE_*` dans `sambaedu/` hors `vendor/` (hors `define` et commentaires) :

| Droit | Occurrences | Lecture |
|---|---|---|
| `SE_COMPUTER_ADMIN` | 82 | Garde-barrière le plus utilisé. |
| `SE_ADMIN` | 72 | Écrans réservés SuperAdmin. |
| `SE_USER_ADMIN` | 57 | Admin annuaire. |
| `SE_USER_PASSWORD_INIT` | 32 | Boutons "réinit MDP" disséminés. |
| `SE_USER_READ` | 31 | Consultation annuaire. |
| `SE_COMPUTER_VIEW` | 26 | Pages parcs/raccourcis. |
| `SE_USER_MODIFY` | 17 | Modification comptes. |
| `SE_COMPUTER_INSTALL` | 14 | Installation automatisée. |
| `SE_COMPUTER_ELEVATE` | 11 | Élévation admin de poste. |
| `SE_NO_RIGHT` | 9 | Fallback. |
| `SE_USER_ASSIGN_RIGHT` | 5 | Assignation de droits. |
| `SE_USER_CREATE_TEMP`, `SE_ELEVE_ADMIN` | 4 | Peu utilisés directement. |
| `SE_SHARE_VIEW`, `SE_SHARE_ADMIN` | 3 | Partages (peu gardés). |
| `SE_WPKG_ASSIGN`, `SE_SHARE_REFRESH`, `SE_SERVER_ADMIN`, `SE_COMPUTER_CONTROL` | 2 | Usage ponctuel. |
| `SE_WPKG_CREATE`, `SE_WPKG_ADD`, `SE_USER_DELEGATE` | 1 | Un seul usage chacun. |

> **Implication pour Epic 7** : concentrer les Policies Spatie sur les droits à fort usage (ComputerAdmin, UserAdmin, SuperAdmin). Les droits peu exercés (`UserDelegate`, `WpkgCreate`, `WpkgAdd`) restent présents pour la complétude de la matrice mais ne justifient pas de tests perfs dédiés.

---

## 7. Sémantique de négation & délégations — mapping cible

| Concept legacy | Mécanisme | Équivalent Spatie |
|---|---|---|
| Profil négatif `no_*` | OR profils positifs puis AND-NOT des négatifs | `Delegation` avec flag `negative` + `PermissionService::negateDelegation()` |
| Délégation de parc (workstation_group) | `$right \|= $d['level']` | `grantDelegation(user, perm, workstationGroup)` + `canOnWorkstationGroup()` |
| Cache APCu 300s | `apcu_store("rights_$hash", $right, 300)` | Cache Spatie natif (Story 7.2) |

---

## 8. Décisions actées 2026-04-22

Toutes les divergences repérées lors du spike ont été tranchées. Statut après edits :

| # | Point | Décision | Edit appliqué |
|---|---|---|---|
| 1 | `ReferentNumerique` vs `RefNum` legacy | Oubli → **aligner sur legacy** | `SambaRole::ReferentNumerique` = `UserPasswordInit + UserRead + UserCreateTemp + ComputerView + ComputerInstall` (retiré `ShareView`) |
| 2 | `WallpaperManage` dans `ComputerAdmin` | Le wallpaper était `SE_SERVER_ADMIN` en legacy → **retirer de `ComputerAdmin`** | `SambaRole::ComputerAdmin` sans `WallpaperManage` ; `SuperAdmin` la récupère automatiquement via `SambaPermission::cases()` |
| 3 | `AppCustomize` dans `ComputerAdmin` | Legacy = gardé par `SE_COMPUTER_ADMIN` → **garder dans `ComputerAdmin`** + remapper `legacyRight()` | `SambaPermission::AppCustomize => LegacyRight::ComputerInstall` (bit représentant du composite — cf §11) |
| 4 | `Prof` sans `UserPasswordInit` | Oubli → **ajouter avec scoping classe** (reproduit `sovajon_is_admin` legacy) | `SambaRole::Prof` = `UserRead + UserPasswordInit` ; la Policy de scoping par classe est à implémenter en **Story 7.2** via `$user->userGroups()->where('type', 'class')` |
| 5 | Labels `ComputerElevate` / `ComputerInstall` ambigus (mélangeaient état et action) | **Clarifier vers l'action web** | Labels = `"Admin de poste"` / `"Installer un poste"` |
| 6 | Bug fallback `Annu_is_admin → SE_COMPUTER_ADMIN` dans `annu/profiles.php` | **Ignorer le fallback**, garder seulement le seed `SE_USER_ADMIN` | Aucun edit nécessaire ; à documenter dans les tests de migration 7.3 comme cas à ne pas reproduire |

---

## 9. À traiter dans les stories suivantes

### Story 7.1 — UI `/rights-management`
- Aucun impact direct de ce spike.

### Story 7.2 — Policies + cache + middleware
- **Policy `UserPolicy::resetPassword`** avec scoping classe : un `Prof` ne peut réinitialiser que les MDP des users membres d'au moins un `UserGroup` de `type='class'` en commun avec lui.
- Idem : vérifier si d'autres permissions nécessitent un scoping équivalent (ex. `UserRead` pour `Prof` — même limite ?). **À décider avec Henri en début de 7.2.**

### Story 7.3 — Migration bitmask → Spatie (one-shot)
- Seeder qui convertit les groupes `rights_rdn` existants en assignations de rôles Spatie.
- **Ne pas reproduire** le fallback `profiles.php:58` (`Annu_is_admin → SE_COMPUTER_ADMIN`) : si un groupe `Annu_is_admin` existe sans `info`, le migrer sur `SE_USER_ADMIN` (valeur du seed) et non sur le fallback buggé.
- Après la migration : **supprimer** tout le mapping bitmask → Spatie (cf §11).

---

## 10. Zones grises restantes (non bloquantes)

### 10.1 "Cas itinérants"

Mentionné dans `epic7_next_steps.md` mais non défini dans le code legacy. **À clarifier en début de Story 7.2** : qu'est-ce qu'un user itinérant ? Un user avec délégations multi-parcs ? Multi-établissements ? Un exemple concret débloquera la décision sur la portée du `PermissionService::canOnWorkstationGroup()` (assez ou pas ?).

### 10.2 Scoping `UserRead` pour `Prof`

Question parallèle au §4 des décisions : le Prof peut-il consulter tous les users de l'annuaire ou seulement ses élèves ? Legacy : `sovajon_is_admin` scopait `UserPasswordInit` à la classe **mais** la lecture d'annuaire (`annu_can_read`) était globale. À trancher au moment d'écrire la Policy en 7.2.

---

## 11. Mapping bitmask legacy — dette en sursis

Le code `app/Enums/LegacyRight.php` + `SambaPermission::legacyRight()` + `bitmask()` + `fromBitmask()` + `bitmaskToPermissions` + `permissionsToBitmask` est **maintenu uniquement** pour l'aller-retour avec l'attribut `info` des groupes LDAP pendant la coexistence.

**Décision 2026-04-22 (Henri)** : après Story 7.3, Spatie devient **source de vérité** et le bitmask AD n'est plus qu'une projection écrite en aval par un Observer. Tout ce code bitmask → Spatie est alors à **supprimer**, pas juste à nettoyer.

**Convention pour les perms sans bit atomique dédié** : quand une permission Spatie correspond en legacy à un **composite** et pas à un bit unique (cas d'`AppCustomize` → `SE_COMPUTER_ADMIN`), on pointe `legacyRight()` sur un **bit représentant** du composite. Pendant la coexistence, cette convention garantit que tout user avec le bitmask composite aura la perm Spatie après sync. Après 7.3, cette convention disparaît avec le reste du mapping.

**À ne pas faire** :
- Ajouter un case composite à `LegacyRight` (casse la symétrie et `fromBitmask`).
- Refactorer le type de retour de `legacyRight()`.
- Ajouter des features, généraliser, ou tester autre chose que la migration 7.3.

---

## 12. Annexes

### 12.1 Fichiers sources audités

| Fichier | Rôle |
|---|---|
| `sambaedu/includes/ldap.inc.php` | Defines `SE_*` (L2948-2976), seed profils (L739-743), `have_right()` (L3038), `list_rights()` (L3072-3132), négations (L3113-3121), délégations (L3123-3128) |
| `sambaedu/annu/profiles.php` | UI gestion profils + fallback mapping nom→bitmask (L56-63) ; bug `Annu_is_admin` à ne pas reproduire |
| `app/Enums/LegacyRight.php` | Bits atomiques + composites côté Laravel (source de vérité coexistence) |
| `app/Enums/SambaPermission.php` | 19 permissions dot-notation + `legacyRight()` + labels clarifiés 2026-04-22 |
| `app/Enums/SambaRole.php` | 9 rôles Spatie + `permissions()` + `fromBitmask()` — aligné matrice 2026-04-22 |

### 12.2 Ce que cette matrice ne couvre pas

- **Ordre d'évaluation négations** : Spatie ne garantit pas nativement "OR positifs puis AND-NOT négatifs" ; c'est `negateDelegation` qui porte la logique. Tests à écrire en 7.2.
- **Synchro AD** : `syncFromAd` / `syncToAd` n'est pas comparé à `list_right_profiles` / `add_right_profile` legacy ici — à faire en 7.3.
- **Scope `UserRead` pour `Prof`** : non tranché (cf §10.2).
- **Définition "itinérant"** : non définie (cf §10.1).
