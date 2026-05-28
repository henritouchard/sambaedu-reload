---
title: "Gap Analysis Epic 1bis — Idempotency map sambaedu ↔ sambaedu-reload"
author: John (PM) + Henri
date: 2026-04-16
status: revised-proposal-v2
revision_note: "v2 — intègre vérification empirique de la couverture du shim LDAP sur les modules backlog (§ 8). Verdict majeur : le shim existant couvre 100% des besoins LDAP — ce qui transforme la plupart des 'défer' en 'shim express'."
inputDocuments:
  - _bmad-output/planning-artifacts/epics.md
  - _bmad-output/planning-artifacts/prd.md
  - _bmad-output/planning-artifacts/architecture.md
  - _bmad-output/implementation-artifacts/sprint-status.yaml
  - _bmad-output/planning-artifacts/sprint-change-proposal-2026-04-16.md
scopeAudit:
  - sambaedu/                       # legacy PHP (référence fonctionnelle)
  - sambaedu-reload/app/            # Laravel — Services, Models, Controllers
  - sambaedu-reload/resources/views/pages/  # Livewire SFC pages
  - sambaedu-reload/legacy/modules/ # modules déjà cloisonnés
---

# Gap Analysis Epic 1bis — Idempotency map

## Objectif

Arbitrer **module par module** ce qui reste de l'Epic 1bis (shims de compatibilité legacy) à la lumière de ce qui est **déjà réimplémenté** dans `sambaedu-reload/` (Laravel).

La question centrale : **shim legacy** (rapide mais dette + tests compat pénibles) vs **build direct en Laravel** (plus lent mais maintenable et aligné avec les epics fonctionnels existants) vs **déférer à un epic dédié** déjà au backlog.

---

## 1. État d'avancement actuel (source : `sprint-status.yaml`)

| État | Stories |
|------|---------|
| ✅ **done** | 1bis.1, 1bis.2, 1bis.3, 1bis.4 display, 1bis.5 oauth2, 1bis.6 sso+cas, 1bis.7 api, 1bis.9 dossier_echange, 1bis.10 ipxe, 1bis.18a gpo core, 1bis.18b gpo import/export |
| ❌ **cancelled** | 1bis.8 user *(doublon avec `/users/{login}` côté Laravel — précédent important)* |
| 🔄 **review** | 1bis.18g gpo shims LDAP/SYSVOL |
| 🟢 **ready-for-dev** | 1bis.11 wpkg, 1bis.18c gpo firefox/thunderbird |
| 🟡 **backlog** | 1bis.12 annu2, 1bis.13 parcs2+acls, 1bis.14 partages, 1bis.15 printers, 1bis.16 dhcp, 1bis.17 bbb, 1bis.18d/e/f gpo (wallpaper, scripts, roaming), 1bis.19 infos |

**Périmètre de cette analyse** : les 6 modules backlog (hors gpo 18d/e/f qui dépendent de la décision wpkg) + le ready-for-dev 1bis.11 wpkg + l'arbitrage résiduel sur 18d/e/f et 19.

---

## 2. Tableau maître — Idempotency map

Pour chaque module :
- **Legacy** : fichiers PHP, lignes, dépendances systèmes (LDAP, SQL, exec, includes)
- **Laravel existant** : ce qui est déjà livré côté `sambaedu-reload/`
- **Gap** : ce qui manque pour idempotence
- **Verdict** : SHIM / BUILD / DEFER (+ epic cible si defer)
- **Effort** : L (< 1j) / M (1-3j) / H (3-5j) / XL (> 5j)

| Story | Module | Legacy (taille, deps) | Laravel existant (`sambaedu-reload/`) | Gap idempotence | Verdict | Effort | Rationale courte |
|-------|--------|----------------------|--------------------------------------|-----------------|---------|--------|------------------|
| 1bis.11 | **wpkg** | 50 f / 9150 L / 0 LDAP / 0 exec / SQL via `wpkg_libsql.php` | `AppStoreService`, `AppProfileService`, `DepotSyncService`, `PackageInstallerService`, `PackagesXmlService`, `LegacyParcBridgeService`, `WpkgReportIngestionService`, pages `/parc-settings/applications`, `/parc-settings/profiles`, `/windows-deploy/reports` | Génération XML client (`hosts_xml_out`, `profiles_xml_out`, `packages_xml_out`), CRUD catalogue `packages.xml` disque, sync dépôts distants, MEF templates, ACLs Samba, options `.ini` par poste, helpers `wpkg_lib*.php` | **SHIM** | **XL** | Décision déjà actée dans l'epic : cloisonnement pur, réécriture native renvoyée à Epic 9. TDD 3 niveaux imposé. Refaire natif maintenant = risque massif sur le client `wpkg-client.vbs` en prod. |
| 1bis.12 | **annu2** | 22 f / 810 L / mais **20 fichiers sont des stubs < 1 KB** (chaque page = `require config + ldap + admin_ui + ihm + 1 include métier`) / 0 exec / LDAP via shim | `/users`, `/users/new`, `/users/{login}`, `/users/groups/{id}`, `/rights-management`, `UserService`, `UserGroupService`, `PermissionService` + LegacyEmbedController déjà utilisé pour `users.groups.legacy-new` → `annu2/add_group.php` | `profiles.php` (5.2 KB, bitmask droits → Spatie), `pass_user_init.php` (réinit mdp bulk), `manage_rights.php`, `mod_entry.php`, quelques vues rapides d'import | **BUILD** | **M** | 20 / 22 fichiers = wrappers HTML triviaux autour d'includes métier déjà shimmés. La vraie logique = 2 fichiers (profiles, pass_user_init). Spatie Permissions déjà en place côté reload. LegacyEmbedController gère le résidu (patron établi). |
| 1bis.13 | **parcs2** | 12 f / 2234 L / LDAP shim / 67 occurrences patterns sensibles | `/parc`, `/parc/groups/{id}`, `/parc/machines/{id}`, `WorkstationService` (WOL, stop, reboot, `getMachinesByParc`, `importFromAd`), `WorkstationGroupService`, `Parc/MachinePowerService`, `RemoteAccessService`, `ParcController` (mass-action, import/export CSV, `/parcs/{parc}/shortcuts`, `/parcs/{parc}/applications`, API hiérarchie) | `create_parc` / `rename_parc` / `delete_parc` UI + `delegate_parc` (18 KB — **délégations salles aux enseignants**), `show_histo` (historique actions), `wolstop_station` (27 KB mais 80% JS legacy) | **BUILD** | **M** | Couche CRUD + power actions déjà là. Manque délégations salle + histo + UI consolidée `/parc/groups/new` (route pas encore implémentée). `wolstop_station` est couvert par `WorkstationService` côté Laravel — seul le JS de tri reste. |
| 1bis.13 | **acls** | 6 f / 899 L / 3 exec `samba-tool` (ACL Samba POSIX/NT) | **Aucun équivalent** | Visualisation ACLs partages (`visuacls.php` 15 KB), validation (`valide.php`), recherche ACLs par user/groupe | **DEFER → Epic 5** | M | Epic 5 (Fichiers SER, FR13-16) couvre déjà ACLs POSIX. Outil de visualisation admin = nice-to-have à intégrer lors de la refonte fichiers. Shim coûteux (tests `samba-tool` en VM + 15 KB de code de rendu HTML à shimmer fiable). |
| 1bis.14 | **partages** | 4 f / 304 L / 0 LDAP / 0 SQL / 0 exec (juste `have_right` + include `samba.inc.php`) | Rien de dédié, mais `FileManagerService`, `QuotaService` présents | `rep_classes.php` (gestion dossiers classe), `rep_cloud.php`+`rep_cloud_cron.php`+`cloud_out.php` (Nextcloud sync) | **DEFER → Epic 5** | S | Très petit module qui appartient fonctionnellement à Epic 5 (FR13-16 "partages de classe avec ACLs héritées"). Shim = dette throwaway. Build direct en Laravel lors du sprint Epic 5. |
| 1bis.15 | **printers** | 11 f / 1512 L / 0 LDAP / 4 exec (`lpadmin` + CUPS) | **Aucun équivalent** | `list_printers` (9.6 KB), `view_printers` (11.9 KB), `config_printer` (8.9 KB), `add_printer`, `delete_printer`, `add_driver`, `cups_driver` | **DEFER → Epic 6** | H | Epic 6 (Impression SER, FR17-19) planifié. Infrastructure cups-pdf déjà prévue pour tests. Shimmer 11 fichiers CUPS avec 4 exec sudo = galère de tests VM importante. Mieux vaut builder direct lors du sprint Epic 6. |
| 1bis.16 | **dhcp** | 6 f / 338 L / 0 LDAP / 2 exec sur fichiers conf | **Aucun équivalent** | `baux.php` (6.6 KB, baux actifs), `config.php` (réservations), `import_reservations`, `dnsupdate` | **DEFER → Epic 8** | S | Epic 8 (Réseau DHCP/DNS, FR20-22) planifié. Très petit module — refonte native triviale en Laravel. Shimmer 2 exec sur config = fragile (parsing fichier conf côté shim). |
| 1bis.17 | **bbb** | 6 f / 503 L / LDAP shim (`search_user`, `have_right`) / 0 exec / API BigBlueButton externe | **Aucun équivalent** | `config`, `create`, `join`, `launch` (9 KB, cœur intégration BBB), `records`, `refresh` | **BUILD** | **M** | Module **indépendant et petit**. Pas d'Epic dédié prévu. 1 Service `BbbService` (wrap API BBB) + 1 page Livewire `/app/bbb` ≈ 2j. Aucun code client embarqué, pas de JS legacy. Bascule directe sans dette. |
| 1bis.18d | **gpo wallpaper** | 2 f / 94 L + include 364 L | `WallpaperController` existe mais **routes commentées** (l. 132-138 `routes/web.php`) | `wallpaper.php` (gestion) + `wallpaper_out.php` (endpoint postes) | **SHIM** | **S** | Cohérent avec la stratégie GPO (reste du cluster 18.* en shim). Endpoint postes `wallpaper_out.php` **critique** en prod — shim safe. Refonte native en Epic 9 si besoin. |
| 1bis.18e | **gpo scripts/veyon/wine/associations** | 5 f / 498 L + includes | `ShortcutCompilerService`, `WindowsLnkGenerator`, `GpoSyncService` | `network_out`, `veyon_out`, `wine`, `applications`, `associations_out` (ce dernier dépend de wpkg 1bis.11) | **SHIM** | **M** | Dépend du verdict wpkg (SHIM). Génération scripts clients (bash/.cmd) + JSON Veyon = très ancré dans le legacy. Cohérent de tout shimmer d'un bloc. |
| 1bis.18f | **gpo profils itinérants** | 3 f / 124 L | — | `no_roam`, `del_roam`, `user_profile_stats` | **SHIM** | **S** | Petit, cohérent avec le cluster 18.*. Usage administrateur faible fréquence — pas prioritaire de refondre. |
| 1bis.19 | **infos** | 9 f / 1374 L / 1 LDAP / 7 exec | Dashboard `/app/dashboard` existe | `quota_fixer.php` (19.6 KB !), `infomdp.php` (6 KB), `quota_visu.php`, `du.php`, `df.php`, `fix_se4.php` (8.6 KB), `test_ldap.php` | **SPLIT** | **M** | Module fourre-tout. Décomposer : (a) infos système basiques (df/du/uname) → élargir `/app/dashboard` (L) ; (b) `quota_fixer` + `quota_visu` → Epic 2/5 (déjà `QuotaService`, `QuotaController`, `QuotaAuditLog` existent) ; (c) `test_ldap` → outil admin `/admin/` (L) ; (d) `infomdp` → Epic 2 (gestion mdp). Shim = rustine inutile. |

**Synthèse quantitative** :
| Verdict | Stories | Effort total estimé |
|---------|---------|---------------------|
| SHIM | 4 (wpkg, 18d, 18e, 18f) | XL + S + M + S ≈ **8-12j** (dominé par wpkg) |
| BUILD direct en `/app` Laravel | 3 (annu2, parcs2, bbb) | M + M + M ≈ **6-9j** |
| DEFER → epic existant | 4 (acls→E5, partages→E5, printers→E6, dhcp→E8) | intégré aux epics cibles, **0j additionnel** sur 1bis |
| SPLIT (infos) | 1 | M ≈ **2-3j** réparti |

---

## 3. Comparatif inventaire : ce qui **existe** vs ce qui **manque** côté Laravel

### 3.1. Services Laravel présents (`sambaedu-reload/app/Services/`)

```
AdSync/                         LegacyErrorHandler             UserGroupService
AppProfile/AppProfileService    LegacyEmbedService             UserService
AppStore/                       PasswordService                UserSyncService
├ AppStoreService              PermissionService               UtilityService
├ DepotSyncService             QuotaService                    Windows/
├ PackageInstallerService      RightsService                   ├ IngestionResult
└ PackagesXmlService           SE4/                            ├ WorkstationLogReader
AuthenticationService          ShortcutCompilerService         └ WpkgReportIngestionService
CacheService                   ShortcutsService                WindowsLnkGenerator
ControlHub/                    StatsService                    WorkerMonitoringService
EcowattService                 FileManagerService              WorkstationGroupLdapService
ErrorLoggerService             GpoSyncService                  WorkstationService
HealthCheckService             ImageManagerService             Parc/
ImportExportService            Legacy/LegacyParcBridgeService  ├ MachinePowerService
                                                                ├ RemoteAccessService
                                                                └ WorkstationGroupService
```

### 3.2. Models Laravel présents

```
Application            Delegation              QuotaAuditLog          UserGroup
AppProfile             Depot / DepotApplication QuotaRule / QuotaSetting User
AuthUser               ErrorLog                SE4FSApiToken           Workstation
CompiledShortcut       InstallationLog         Shortcut                WorkstationApplicationStatus
ControlHubConnection   LegacyCatchallLog                               WorkstationGroup
ControlHubTask         MachineBootLog
```

### 3.3. Pages Livewire présentes (`resources/views/pages/`)

```
admin/
├ error-logger          control-hub          users/
├ legacy-monitor        dashboard            ├ index, new
└ migrate               homelegacy           ├ groups/{id}/new
parc/                   rights-management    ├ sql-groups
├ groups/{id}/new       shortcuts/           ├ {login}/_partials
├ machines/{id}         ├ {id}/_partials     windows-deploy/
└ _partials             ├ new                └ reports/{workstation}
parc-settings/          sync-from-ad         workers/{pid}
├ applications          test-modal
└ profiles
```

### 3.4. Ce qui manque côté Laravel (ordre d'impact)

| Domaine manquant | Couvert par | Priorité |
|------------------|-------------|----------|
| Gestion ACLs Samba POSIX/NT | Epic 5 | H |
| Gestion partages classe + cloud | Epic 5 | H |
| Gestion CUPS (imprimantes, drivers, jobs) | Epic 6 | H |
| Gestion DHCP (baux, réservations, DNS) | Epic 8 | M |
| Intégration BigBlueButton | Aucun epic (à créer ou inclure dans 1bis.17 BUILD) | M |
| Génération XML wpkg pour clients `.vbs` | Epic 9 (renvoyé) | L |
| Infos système (df/du/uname/uptime) | Dashboard à étendre | L |

---

## 4. Décisions proposées

### 4.1. Retirer de l'Epic 1bis (défer vers epics existants)

| Story à retirer | Défer vers | Action |
|-----------------|------------|--------|
| 1bis.13 **acls** (séparée de parcs2) | **Epic 5 — Fichiers SER (FR13-16)** | Ajouter story 5.X "Visualisation ACLs Samba admin" |
| 1bis.14 **partages** | **Epic 5 — Fichiers SER (FR13-16)** | Story 5.X "Gestion répertoires classe + cloud sync" |
| 1bis.15 **printers** | **Epic 6 — Impression SER (FR17-19)** | Refonte native directe, pas de shim |
| 1bis.16 **dhcp** | **Epic 8 — Réseau DHCP/DNS SER (FR20-22)** | Refonte native directe, pas de shim |
| 1bis.19 **infos** (décomposé) | Dashboard + Epic 2 + `/admin/` | Éclater en micro-stories |

→ **5 stories 1bis supprimées** du backlog de cloisonnement.

### 4.2. Basculer en BUILD Laravel direct (skip shim)

| Story | Nouveau scope | Prérequis |
|-------|---------------|-----------|
| 1bis.12 **annu2** → **2bis.X** | Story Laravel : finir les profils de droits Spatie (`profiles.php`) + réinit mdp bulk + completion `/users/*` | Aucun — LegacyEmbedController couvre le résidu |
| 1bis.13 **parcs2** (sans acls) → **4.X** | Stories Laravel : `/parc/groups/new`, `/parc/groups/{id}/edit`, délégations salle, historique actions | WorkstationGroupService déjà en place |
| 1bis.17 **bbb** → **nouveau micro-epic** ou **story autonome** | `BbbService` + `/app/bbb` Livewire + 1 migration (si stockage sessions BBB requis) | Aucun |

→ **3 stories** à réaffecter à des epics fonctionnels existants (ou nouveau micro-epic BBB).

### 4.3. Maintenir en shim (confirmé)

| Story | Rationale |
|-------|-----------|
| 1bis.11 **wpkg** | Décision d'archi déjà prise (TDD 3 niveaux, refonte → Epic 9). **Priorité #1 restante sur l'Epic 1bis.** |
| 1bis.18c **gpo firefox/thunderbird** | Déjà ready-for-dev, débloqué par 18g en review |
| 1bis.18d **gpo wallpaper** | Cohérence cluster GPO + endpoint postes critique |
| 1bis.18e **gpo scripts/veyon/wine/associations** | Dépendance wpkg + complexité génération scripts |
| 1bis.18f **gpo profils itinérants** | Cohérence cluster GPO + usage admin faible |

→ **5 stories** à maintenir en shim (wpkg + cluster GPO 18c-f).

---

## 5. Nouvelle silhouette de l'Epic 1bis (après décisions)

**Avant** (backlog actuel) : 9 stories restantes (11, 12, 13, 14, 15, 16, 17, 18c/d/e/f, 19).
**Après** (proposé) : **5 stories restantes** + réaffectations ciblées.

```
Epic 1bis — Cloisonnement Legacy (nouvelle trajectoire)
├─ 1bis.11 wpkg                        [SHIM — critique — XL]
├─ 1bis.18c firefox/thunderbird        [SHIM — ready-for-dev]
├─ 1bis.18d wallpaper                  [SHIM — S]
├─ 1bis.18e scripts/veyon/wine/assoc   [SHIM — M, dépend 11]
└─ 1bis.18f profils itinérants         [SHIM — S]

Réaffectations :
├─ ex-1bis.12 annu2                    → Epic 2 (stories profils/mdp bulk)
├─ ex-1bis.13 parcs2                   → Epic 4 (stories délégation/historique/édition)
├─ ex-1bis.13 acls                     → Epic 5 (story visualisation ACLs)
├─ ex-1bis.14 partages                 → Epic 5 (story partages classe + cloud)
├─ ex-1bis.15 printers                 → Epic 6 (refonte native directe)
├─ ex-1bis.16 dhcp                     → Epic 8 (refonte native directe)
├─ ex-1bis.17 bbb                      → NEW micro-epic BBB ou story transverse
└─ ex-1bis.19 infos                    → splittée (dashboard, quota→E2/5, test_ldap→admin)
```

---

## 6. Impact sur la roadmap

### 6.1. Gains

- **−5 shims** à écrire, tester en VM, maintenir et décommissionner plus tard.
- **Suppression de la dette "shim throwaway"** pour printers, dhcp, partages, acls, infos — tous refondus natifs dans leurs epics fonctionnels.
- **Alignement** : chaque module manquant vit dans son epic naturel (5 = Fichiers, 6 = Impression, 8 = Réseau) au lieu d'être une étape intermédiaire legacy.
- **Tests plus faciles** : pas de setup `samba-tool` / CUPS / DHCP en bac à sable legacy, directement dans l'infra Laravel testée.
- **`annu2`, `parcs2`, `bbb` builds directs** = code Laravel propre dès le départ, pas de migration ultérieure à prévoir.

### 6.2. Risques & coûts

| Risque | Mitigation |
|--------|------------|
| Retard Epic 5/6/8 (inclusion des modules défer) | Valoriser : les stories défer n'augmentent pas linéairement l'effort epic cible (sambaedu-reload démarre souvent de zéro de toute façon). |
| URLs legacy `printers/*`, `dhcp/*`, `acls/*` cassées avant refonte epic cible | Ajouter ces paths à la `LEGACY_BLOCK_MIGRATED_ROUTES` liste AVEC redirection explicite vers la future page Laravel ("Fonctionnalité en cours de migration — voir Epic X"). Ou laisser le catchall servir le legacy PHP tel quel tant qu'Epic X n'est pas fait (faisable si le shim LDAP/SQL couvre déjà ces modules — **à vérifier module par module**). |
| `annu2` BUILD : risque de régression sur `profiles.php` (bitmask droits → Spatie) | Mini-story "data migration bitmask → Spatie permissions" avant suppression legacy. |
| `bbb` BUILD : API BBB peut évoluer | `BbbService` typé avec mock API pour tests. |

### 6.3. Ordre de bataille recommandé

1. **Débloquer wpkg (1bis.11)** en premier — c'est le seul gros morceau réellement critique et irrévocable en shim.
2. **Finir le cluster GPO** (18c → 18f) en bundle TDD court puisque les shims GPO sont déjà en review.
3. **Basculer annu2, parcs2, bbb en BUILD** — 3 micro-sprints de 2-3j chacun, revue rapide.
4. **Fermer Epic 1bis** après ces 8 stories.
5. **Ouvrir Epic 5, 6, 8** avec les modules défer intégrés dans leur scope naturel.

---

## 7. Points à valider avec Henri avant d'acter

1. **Confirmer le défer d'`acls` vers Epic 5** — y a-t-il un usage admin actif aujourd'hui de `visuacls.php` sur le terrain qui imposerait un shim intermédiaire ?
   → **Henri 2026-04-16 :** "aucune idée, je te donnerai la réponse plus tard." — ❓ En suspens.
   → **Henri 2026-04-17 :** "c'est effectivement obsolète." — ✅ **Tranché : 1bis-13b cancelled, defer Epic 5 sans shim intermédiaire.**
2. **Confirmer que le catchall legacy fonctionne pour printers/dhcp/acls/partages** sans shim dédié (shim LDAP + SQL couvrent-ils les appels de ces modules sans ajouter de code spécifique ?) — **à vérifier empiriquement** en appelant 2-3 pages via le catchall sur VM.
   → **Henri 2026-04-16 :** "ça vaut le coup de vérifier." — ✅ **Vérification faite** (§ 8 ci-dessous) : le shim existant couvre déjà **100%** des besoins LDAP de ces modules. Reste à tester les exec système en VM.
3. **BBB : BUILD ou SHIM ?**
   → **Henri 2026-04-16 :** "si shim est vraiment 2h on shim." — ✅ Décision : **SHIM EXPRESS** (cf. § 8.3).
4. **Ordre `annu2` / `parcs2`** : reformulé — les deux ont une base Laravel, laquelle finir en premier ? → **Henri** : "je ne comprends pas ta question, les deux sont partiellement réimplémentés." — ❓ Les deux partiels = les deux en BUILD, pas d'ordre imposé. On tranchera au moment des stories.
5. **`infos`** splittable sans story 1bis dédiée ?
   → **Henri 2026-04-16 :** "oui." — ✅ Validé.

---

## 8. Révision v2 — Vérification empirique de la couverture du shim existant

### 8.1. Fonctions LDAP shimmées aujourd'hui (`sambaedu-reload/legacy/ldap.inc.php`, 1982 L)

Fonctions haut niveau (exposition publique) :
`search_ad`, `search_user`, `search_group`, `search_machine`, `search_etabs`, `search_delegations`, `filter_user`, `filter_group` + variantes (classes, équipes, cours, matières, projets, autres), `list_members_group`, `list_members_parc`, `list_groups`, `list_rights`, `list_classes`, `list_profs`, `list_eleves`, `list_delegations`, `list_etabs`, `have_right`, `have_right_or_delegation`, `modify_ad`, `modify_ad_attr`, `delete_ad`, `move_ad`, `create_ad_user`, `create_group`, `create_machine`, `create_parc`, `trash_user`, `trash_users`, `recup_user`, `user_valid_passwd`, `is_dual_boot`, `is_eleve`, `is_prof`, `register_machine_hardware`, `ldap_dn2cn/ou/oudn/parent/sam/uai`, `ldap_sam2cn/suffix`, `escape_ldap_name`, `lock/try_lock/unlock/is_locked`, `se_encrypt/se_decrypt`, `get_config/set_config/set_param/init_param/get_config_file`, `is_local`, `etab_suffix`, + tout le cluster `_shim_gpo_*` (search, modify, connect, resolve_dn).

**Constat** : le shim est déjà très étoffé — 1982 lignes, couvre toute la surface LDAP publique legacy.

### 8.2. Appels LDAP des modules backlog (audit réel)

Fonctions LDAP effectivement appelées dans chaque module (grep exhaustif de la liste ci-dessus) :

| Module | Fonctions appelées | Couvertes par shim ? | SQL direct | Exec système |
|--------|-------------------|:-------------------:|:---------:|:------------:|
| **annu2** | `search_ad` (8 occ dans `profiles.php` uniquement — 20 autres fichiers = stubs HTML sans LDAP direct) | ✅ 100% | 0 | 0 |
| **parcs2** | `have_right`, `search_ad`, `search_machine`, `list_delegations`, `list_members_parc`, `ldap_dn2cn`, `ldap_dn2oudn`, `delete_ad`, `create_parc` | ✅ 100% | 4 occ `wolstop_station.php` (à vérifier via `wpkg_libsql`) | 0 |
| **acls** | `have_right`, `search_ad`, `search_user` | ✅ 100% | 0 | 3 `samba-tool` |
| **partages** | `have_right` uniquement (2 occ) | ✅ 100% | 0 | 0 |
| **printers** | `have_right` uniquement (14 occ) | ✅ 100% | 0 | 4 CUPS (`lpadmin` etc.) |
| **dhcp** | `have_right`, `search_machine` | ✅ 100% | 0 | 2 (conf files) |
| **bbb** | `have_right`, `search_user`, `is_eleve` | ✅ 100% | 0 | 0 |
| **infos** | `have_right`, `search_ad`, `search_user`, `search_group` | ✅ 100% | 0 | 7 (`df`, `du`, `uname`…) |

### 8.3. Conséquence : révision des verdicts

**Le shim existant couvre 100% des besoins LDAP.** Shimmer un module = essentiellement `cp -r sambaedu/{module} sambaedu-reload/legacy/modules/{module}/` + vérification chargement via catchall + smoke tests. La difficulté ne réside pas dans l'écriture du shim mais dans la **validation des exec système en VM**.

**Nouvelle classification** (remplace la § 4) :

#### Category A — SHIM EXPRESS (< 3h chacun, feature fonctionnelle conservée pendant la transition vers l'epic natif)

| Story | Effort shim express | Action |
|-------|--------------------|---------| 
| 1bis.14 **partages** | ~1h | cp modules/partages + vérif chargement. Zero exec. |
| 1bis.17 **bbb** | ~2h | cp modules/bbb + vérif chargement + config BBB.inc.php. Pas d'exec. |
| 1bis.16 **dhcp** | ~2h | cp + test des 2 exec sur VM (parsing conf dhcpd). |
| 1bis.19 **infos** | ~3h | cp + test des 7 exec basiques (`df`, `du`, `uname` = user admin fiables). test_ldap pratique pour debug. |
| ~~1bis.13b **acls**~~ | ~~~3h~~ | **Cancelled 2026-04-17** — module obsolète (confirmé par Henri). Defer Epic 5 sans shim intermédiaire. |
| 1bis.15 **printers** | ~3h | cp + test des 4 exec CUPS (nécessite cups-pdf sur VM test). |

**Total Category A : ≈ 11h ≈ 1.5 jour de travail** pour conserver tout le périmètre legacy fonctionnel *(après retrait 1bis-13b acls cancelled le 2026-04-17)*.

#### Category B — BUILD direct (skip shim car équivalent Laravel déjà substantiel)

| Story | Rationale | Scope |
|-------|-----------|-------|
| 1bis.12 **annu2** | `/users/*`, `/rights-management`, `UserService`, `Spatie Permissions` déjà en place. Shim = code jeté dans 1 sprint. | Stories Epic 2 : profils de droits Spatie (migration bitmask), réinit mdp bulk, completion `/users/new` |
| 1bis.13 **parcs2** (partie parc, sans acls) | `WorkstationService`, `WorkstationGroupService`, `MachinePowerService`, `/parc/*` déjà en place. Manque UI `/parc/groups/new`, délégations salle, histo. | Stories Epic 4 |

#### Category C — SHIM confirmé (gros module, refonte déjà renvoyée)

| Story | Rationale |
|-------|-----------|
| 1bis.11 **wpkg** | Décision d'archi actée (TDD 3 niveaux, refonte → Epic 9). Irrévocable. |
| 1bis.18c-d-e-f **cluster GPO** | Cohérence cluster GPO, endpoint critique en prod, cluster déjà outillé (18a-b done, 18g review). |

### 8.4. Risques résiduels identifiés dans la Category A

| Risque | Modules concernés | Mitigation |
|--------|-------------------|------------|
| Exec `samba-tool` avec droits insuffisants | `acls` (3), gpo 18b/e | `samba-tool` déjà utilisé par gpo 18b (done) — le sudoer est probablement déjà configuré. À confirmer. |
| Exec CUPS (`lpadmin`) nécessite CUPS installé + sudo | `printers` | Installer `cups-pdf` sur VM dev comme prévu dans Epic 6. Erreurs capturées par error logger. |
| 4 appels SQL résiduels dans `parcs2/wolstop_station.php` | `parcs2` | À inspecter : si appels tournent via `wpkg_libsql.php` → OK. Sinon l'analyser pendant le BUILD parcs2 (puisque parcs2 bascule en Category B). |
| 2 exec sur fichiers conf DHCP | `dhcp` | L'écriture directe dans `/etc/dhcp/dhcpd.conf.*` nécessite droits. Shim express = lecture OK, écriture à tester. |

### 8.5. Nouvelle silhouette Epic 1bis — v2

```
Epic 1bis — Cloisonnement Legacy (v2)

[Category A — SHIM EXPRESS, ≈ 1.5j cumulés]
├─ 1bis.14 partages          [~1h]
├─ 1bis.16 dhcp              [~2h]
├─ 1bis.17 bbb               [~2h]
├─ 1bis.19 infos             [~3h + split dashboard]
└─ 1bis.15 printers          [~3h ; nécessite cups-pdf]
   # 1bis.13b acls retiré le 2026-04-17 — module obsolète (defer Epic 5 sans shim)

[Category B — BUILD direct, réaffecté aux epics fonctionnels]
├─ 1bis.12 annu2             → stories Epic 2 (Spatie, mdp bulk)
└─ 1bis.13a parcs2           → stories Epic 4 (délégations, histo, édition UI)

[Category C — SHIM confirmé, pas de changement]
├─ 1bis.11 wpkg              [XL, priorité #1]
├─ 1bis.18c firefox/tbird    [ready-for-dev]
├─ 1bis.18d wallpaper        [S]
├─ 1bis.18e scripts/veyon    [M, dépend 11]
└─ 1bis.18f roaming          [S]
```

### 8.6. Ordre de bataille v2

1. **SHIM EXPRESS x 6** en 1 sprint de 2 jours (Category A) → tout le backlog fonctionnel devient accessible
2. **Débloquer wpkg (1bis.11)** — vrai gros morceau
3. **Finir le cluster GPO** (18c → 18f) en bundle TDD
4. **Fermer Epic 1bis**
5. **BUILD annu2 (Epic 2)** et **parcs2 (Epic 4)** via leurs epics respectifs
6. **Refonte native** printers/dhcp/partages/acls/bbb/infos dans Epic 5/6/8 / dashboard quand on y arrive — à ce moment, supprimer les shims express correspondants

### 8.7. Gains / coûts v2 vs v1

| Aspect | v1 (initial) | v2 (révisé) |
|--------|:-----------:|:-----------:|
| Stories shim restantes | 5 | 9 (6 express + 1bis.11 + 18c + 18d/e/f) — mais 6 d'entre elles coûtent < 3h chacune |
| Stories BUILD direct | 3 (annu2, parcs2, bbb) | 2 (annu2, parcs2) — bbb bascule en SHIM EXPRESS |
| Stories DEFER vers autre epic | 4 + split infos | 0 — le shim express couvre la transition |
| Features legacy accessibles | Cassées entre shim abandonné et refonte native | Fonctionnelles en continu via shim express |
| Dette throwaway | 5 stories à défer + 3 builds = code Laravel propre direct | 6 shims express à jeter au profit des refontes natives epics 5/6/8 |
| Effort cumulé 1bis restant | Indéterminé (défers = effort epic cible) | ~2j (shim express) + existant (wpkg + cluster GPO) |

**Verdict v2** : le **coût du shim express est tellement bas** (grâce à la richesse du shim LDAP existant) que **l'approche "tout shimmer d'abord, refondre natif ensuite"** domine pragmatiquement l'approche "défer pur" sur la plupart des modules.

Le seul cas où BUILD direct reste optimal : **annu2 et parcs2**, où la base Laravel existe déjà à ≥ 70% — shimmer = code jeté immédiatement.

---

## 9. Décisions finales proposées (synthèse v2)

1. ✅ **Category A — SHIM EXPRESS** : sprint court dédié (≈ 2j) pour cloisonner `partages`, `dhcp`, `bbb`, `infos`, `acls`, `printers`. Acceptance criteria minimaliste : "module chargé via catchall sans erreur fatale, pages principales répondent 200, exec système loggent gracefully". Refonte native ensuite au fil des epics 5/6/8.
2. ✅ **Category B — BUILD direct** : `annu2` et `parcs2` basculent dans leurs epics fonctionnels (2 et 4). Pas de story 1bis associée.
3. ✅ **Category C — inchangé** : wpkg + cluster GPO restent en shim classique TDD.
4. ✅ **`infos` splittable** sans story dédiée (validé par Henri).
5. ~~❓ **`acls` usage** : Henri statue plus tard.~~ → **Tranché 2026-04-17 : module obsolète. 1bis-13b cancelled + defer Epic 5 (sans shim intermédiaire).**

### Livrables à générer après validation finale

- **sprint-change-proposal-2026-04-17** (ou date de décision) formalisant la réorganisation Epic 1bis v2
- **Mise à jour `epics.md`** : retrait stories 12/13/17 du détail 1bis, ajout refs croisées vers Epics 2/4, ajout story 1bis "cluster SHIM EXPRESS" groupée (ou 6 micro-stories selon préférence)
- **Mise à jour `sprint-status.yaml`** : nouveaux statuts
- **Nouvelles stories via `bmad-create-story`** pour annu2 (Epic 2) et parcs2 (Epic 4)
