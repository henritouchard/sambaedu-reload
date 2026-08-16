# Fonctionnalités SE4 → SE5 : état du portage

> Investigation croisée du legacy `../sambaedu` (SE4, PHP) et de `sambaedu-reload` (SE5, Laravel + agent Go), 2026-07-02.
> Couvre l'application web **et** les capacités des postes.

**Légende des statuts**

| Statut                    | Signification                                                                              |
| ------------------------- | ------------------------------------------------------------------------------------------ |
| ✅ **Natif**              | Réécrit en SE5 (Laravel / agent Go), le legacy n'est plus nécessaire                       |
| 🟡 **Partiel**            | Porté pour l'essentiel, avec un manque identifié (précisé en section)                      |
| 🔗 **Legacy supporté**    | Toujours rendu par le code SE4, mais piloté/encapsulé par SE5 (shim, bridge, liens en dur) |
| ⏳ **En cours / backlog** | Story ou epic SE5 identifié, non livré                                                     |
| ❌ **Non porté**          | Aucun équivalent SE5, pas de story dédiée                                                  |
| 🚫 **Abandonné**          | Volontairement retiré, ou jamais abouti en SE4                                             |
| ☁️ **controlHub**         | Sort du périmètre SE5 : repris par le central controlHub (`../irundoo`)                    |

---

## Tableau résumé

| #     | Domaine       | Fonctionnalité                                                   | Statut                                        | todo |
| ----- | ------------- | ---------------------------------------------------------------- | --------------------------------------------- | ---- |
| 1.1   | Utilisateurs  | Création / modification / désactivation / suppression de comptes | ✅ Natif                                      | ok |
| 1.2   | Utilisateurs  | Statut itinérant + nettoyage profils volumineux                  | ✅ Natif                                      | ok |
| 1.3   | Utilisateurs  | Changement de rôle avec déplacement de DN                        | ✅ Natif                                      | ok |
| 1.4   | Utilisateurs  | Réinitialisation mots de passe en masse + export PDF/CSV         | ✅ Natif                                      | ok |
| 1.5   | Utilisateurs  | Liste / fiche / recherche / filtres d'audit (quota, mdp défaut)  | ✅ Natif                                      | ok |
| 1.6   | Utilisateurs  | Groupes, classes, membres, prof principal                        | ✅ Natif                                      | ok |
| 1.7   | Utilisateurs  | Purge des profils Windows itinérants (unitaire fiche user)       | 🟡 Partiel                                    | ok |
| 1.8   | Utilisateurs  | Export CSV de listes de groupes                                  | 🟡 Partiel                                    | ok |
| 1.9   | Utilisateurs  | Imports SIECLE / STS / AAF / ENT / GPEI                          | 🔗 Legacy supporté (choix ADR-1)              | ok |
| 1.10  | Utilisateurs  | Import CSV de comptes (~20 formats ENT)                          | 🟡 Partiel                                    | ok |
| 1.11  | Utilisateurs  | Sync cron central↔établissements                                | 🚫 Remplacé (sync AD→SQL)                     | ok |
| 1.12  | Utilisateurs  | Comptes temporaires (purge auto)                                 | ❌ Non porté                                  | ok |
| 1.13  | Utilisateurs  | Professeur remplaçant                                            | ❌ Non porté                                  | ok |
| 1.14  | Utilisateurs  | Nettoyage des comptes orphelins LDAP                             | ❌ Non porté                                  | ok |
| 2.1   | Auth          | Login LDAP, auto-login par IP, logout, changement mdp forcé      | ✅ Natif                                      | ok |
| 2.2   | Auth          | SSO OAuth2 OpenENT                                               | ✅ Natif                                      | ok |
| 2.3   | Auth          | SSO CAS (ENT académiques)                                        | 🟡 Partiel                                    | ok |
| 2.4   | Auth          | MFA SMS admin ENT / OpenID Connect / public_key.js               | 🚫 Abandonné                                  | ok |
| 2.5   | Auth          | Espace personnel utilisateur (quota self-service)                | ❌ Non porté                                  | ok |
| 3.1   | Droits        | Délégations de droits par périmètre (ex-bitmask)                 | ✅ Natif (Spatie)                             | ok |
| 3.2   | Droits        | Profils de droits (matrice profils × droits)                     | ✅ Natif (revue Epic 12 en cours)             | ok |
| 4.1   | Parcs         | CRUD parcs, hiérarchie, salles physiques, délégation             | ✅ Natif                                      | ok |
| 4.2   | Parcs         | Association postes ↔ parcs, import/export CSV                   | ✅ Natif                                      | ok |
| 4.3   | Parcs         | WOL / extinction / redémarrage / ping-détection OS               | ✅ Natif                                      | ok |
| 4.4   | Parcs         | Planification horaire allumage/extinction                        | ✅ Natif (MVP wake+shutdown)                  | ok |
| 4.5   | Parcs         | Prise en main à distance (Guacamole RDP)                         | 🔗 Legacy supporté                            | ok |
| 4.6   | Parcs         | Coupure Internet par le prof (no_internet)                       | 🟡 Partiel (UI prof manquante)                | ok |
| 5.1   | iPXE          | Boot réseau, menus, enrôlement (parc/salle/nommage/BYOD)         | ✅ Natif                                      | ok |
| 5.2   | iPXE          | Installation Windows (sysprep/wimboot/unattend) + ISO            | ✅ Natif (enrichi)                            | ok |
| 5.3   | iPXE          | Installation Linux (preseed Debian/Ubuntu)                       | ✅ Natif                                      | ok |
| 5.4   | iPXE          | Clonage Clonezilla + diagnostics                                 | 🟡 Partiel (outils annexes à confirmer)       | ok |
| 5.5   | iPXE          | LTSP / boot diskless                                             | ⏳ Backlog (3-9)                              | ok |
| 6.1   | Logiciels     | Endpoints WPKG (hosts/profiles/packages/linux/winget)            | ✅ Natif                                      | ok |
| 6.2   | Logiciels     | Catalogue, profils applicatifs, association parcs/postes         | ✅ Natif                                      | ok |
| 6.3   | Logiciels     | Pipeline de déploiement, dashboard, logs/rapports WPKG           | ✅ Natif (refonte)                            | ok |
| 6.4   | Logiciels     | Dépôt d'applications (AppStore) + install natives                | ✅ Natif                                      | ok |
| 6.5   | Logiciels     | Déclenchement par l'agent (« un tuyau, deux outils »)            | ✅ Natif (nouveau)                            | ok |
| 7.1   | Config postes | Gestion des GPO (listing, lecture, liens OU/parc)                | ✅ Natif                                      | ok |
| 7.2   | Config postes | Création/duplication/suppression de GPO                          | 🚫 Abandonné (16-4)                           | ok |
| 7.3   | Config postes | Publication SYSVOL (import_gpo)                                  | 🔗 Legacy supporté (dette 27.14)              | ok |
| 7.4   | Config postes | Lecteurs réseau (K:/H: + répertoires assignés)                   | ✅ Natif (agent)                              | ok |
| 7.5   | Config postes | Fond d'écran / écran de verrouillage / overlay                   | ✅ Natif (agent, enrichi)                     | ok |
| 7.6   | Config postes | Raccourcis bureau                                                | ✅ Natif (agent)                              | ok |
| 7.7   | Config postes | Associations de fichiers                                         | ✅ Natif (agent)                              | ok |
| 7.8   | Config postes | Réglages registre / restrictions (capacités)                     | 🟡 Partiel (gaps → Epic 35)                   | ok |
| 7.9   | Config postes | Blocage Internet « examen »                                      | ⏳ En cours (27-13, Epic 36)                  | ok |
| 7.10  | Config postes | Veyon (supervision de classe)                                    | ✅ Natif (endpoint)                           | ok |
| 7.11  | Config postes | Politiques Firefox / Thunderbird                                 | ✅ Natif                                      | ok |
| 7.12  | Config postes | Wine (apps Windows sous Linux)                                   | ✅ Natif                                      | ok |
| 7.13  | Config postes | Profils itinérants / redirections (GPO roaming)                  | 🔗 Legacy supporté (shim SYSVOL)              | ok |
| 7.14  | Config postes | Scripts startup/logon (applications.php)                         | ✅ Natif                                      | ok |
| 8.1   | Partages      | Partages de classe (\_travail/\_profs/\_echange + ACL)           | ✅ Natif                                      | ok |
| 8.2   | Partages      | Dossiers élèves, changement de classe, archivage                 | ✅ Natif                                      | ok |
| 8.3   | Partages      | Homes, corbeille, purge                                          | ✅ Natif                                      | ok |
| 8.4   | Partages      | Répertoires réseau managés + templates                           | ✅ Natif (nouveau, 34.3 en review)            | ok |
| 8.5   | Partages      | Workflow devoirs (collecte des copies)                           | 🟡 Partiel                                    | ok |
| 8.6   | Partages      | Éditeur ACL libre sur chemin arbitraire                          | 🟡 Partiel (remplacé par modèle managé ro/rw) | ok |
| 8.7   | Partages      | Casiers élève → prof                                             | ⏳ Backlog (34.x)                             | ok |
| 8.8   | Partages      | Quotas disque XFS (héritage user/groupe)                         | ✅ Natif                                      | ok |
| 8.9   | Partages      | Sync cloud Nextcloud/Seafile des partages                        | ✅ Natif (mécanisme changé)                   | ok |
| 9.1   | Impression    | Imprimantes CUPS (CRUD, pilotes, réservation)                    | ✅ Natif                                      | ok |
| 9.2   | Impression    | Déploiement par salle/parc (+ défaut)                            | ✅ Natif (agent)                              | ok |
| 9.3   | Impression    | Déploiement par groupe _utilisateur_                             | 🟡 Partiel (maille poste seulement)           | ok |
| 9.4   | Impression    | Gestion des jobs (suppression)                                   | 🟡 Partiel (lecture seule)                    | ok |
| 10.1  | Réseau        | Réservations DHCP, baux, export dhcpd, DNS update                | ✅ Natif                                      | ok |
| 11.1  | Collaboration | BigBlueButton (salons, enregistrements, équilibrage)             | 🔗 Legacy supporté (Epic 13 backlog)          | ok |
| 11.2  | Collaboration | Visio invités externes                                           | 🔗 Legacy supporté                            | ok |
| 11.3  | Collaboration | Affichage dynamique / signage (display)                          | 🔗 Legacy supporté (14-2 en pause)            | ok |
| 11.4  | Collaboration | Sync Google Workspace                                            | 🚫 Abandonné                                  | ok |
| 11.5  | Collaboration | Ecowatt (sobriété énergétique)                                   | ✅ Natif                                      | ok |
| 12.1  | Admin         | Mise à jour système (update.sh + sambaedu:app:update)            | ✅ Natif                                      | ok |
| 12.2  | Admin         | Diagnostics (doctor + /system-status)                            | ✅ Natif                                      | ok |
| 12.3  | Admin         | Délégation admin (have_right → Spatie)                           | ✅ Natif                                      | ok |
| 12.4  | Admin         | Paramétrage serveur (conf_params)                                | 🟡 Partiel (éclaté, restes non mappés)        | ok |
| 12.5  | Admin         | Configuration SMTP en UI                                         | ❌ Non porté                                  | ok |
| 12.6  | Admin         | Actions serveur physiques (shutdown/Proxmox/console)             | ❌ Non porté                                  | ok |
| 12.7  | Admin         | État des paquets sambaedu-\*                                     | 🟡 Partiel (repensé)                          | ok |
| 12.8  | Admin         | Logs de connexions postes                                        | ✅ Natif (repensé : reporting agent + audits) | ok |
| 12.9  | Admin         | Stats d'occupation des postes / plannings                        | ☁️ controlHub (partiel local)                 | ok |
| 12.10 | Admin         | Métriques InfluxDB                                               | ☁️ controlHub                                 | ok |
| 12.11 | Admin         | Serveur central (dashboard mutualisation)                        | ☁️ controlHub                                 | ok |
| 12.12 | Admin         | API clients (api2 + Ecowatt)                                     | ✅ Natif (API v1)                             | ok |

**Bilan chiffré** : sur ~75 fonctionnalités SE4 recensées — **≈ 45 portées natives**, **≈ 12 partielles**, **7 legacy supporté**, **3 en cours/backlog**, **6 non portées**, **5 abandonnées/remplacées**, **3 reprises par controlHub**.

**Les 3 grands blocs encore servis par le legacy** : la **collaboration** (BBB, visio invités, affichage dynamique), la **prise en main à distance Guacamole**, et les **imports d'annuaire externes** (SIECLE/AAF/ENT — choix d'architecture assumé, cf. §1.9). Les **shims techniques restants** : `import_gpo` SYSVOL et les profils itinérants/roaming (lecture-écriture GPO redirections).

---

## 1. Utilisateurs & annuaire

**Contexte architectural** : deux ADR SE5 (`docs/identite/metier.md`) expliquent la majorité des écarts. ADR-1 : **PostgreSQL devient la source de vérité**, l'import AD→SQL est un outil transitoire de bascule. ADR-6 : SE5 n'écrit plus les **comptes** dans l'AD (seulement postes et groupes qu'il possède). Conséquence : tous les imports SE4 qui provisionnaient l'AD depuis des sources externes restent au legacy pendant la coexistence.

### 1.1 CRUD comptes — ✅ Natif

Création (`pages/users/new/`), modification (fiche `users/[login]`), désactivation/suppression via `UserService`. SE4 : `annu2/add_user.php`, `mod_user_entry.php`, `desac_user_entry.php`, `del_user.php`. Stories 2-1 → 2-3 done. Docs `docs/USER_CREATE.md`, `USER_UPDATE.md`.

### 1.2 Statut itinérant — ✅ Natif

Story 2-4 + 26.3 (pastille profil volumineux, snapshot `profiles:snapshot`, purge des orphelins). `RoamingProfileService`, page `admin/settings/profils-itinerants`.

### 1.3 Changement de rôle avec déplacement DN — ✅ Natif

Partial `role-change-form.blade.php`, story 2-5. SE4 : `mod_user_entry.php`.

### 1.4 Reset mots de passe en masse — ✅ Natif

`PasswordService`, `BulkResetListingService`, `PasswordResetExportService` (PDF/CSV signés). SE4 : `annu/reinit_mdp.php`, `pass_user_init.php`. Story 2-6 done.

### 1.5 Liste / fiche / recherche — ✅ Natif

`pages/users/` avec filtres d'audit natifs « quota dépassé » et « mot de passe par défaut » (story 14.4) qui remplacent `quota_visu.php` / `infomdp.php`. SE4 : `annu2/peoples_list.php`, `people.php`, `search.php`.

### 1.6 Groupes, classes, membres, prof principal — ✅ Natif

`UserGroupService` (+ écriture AD idempotente `UserGroupAdSyncService`), pages `users/groups/`, pivot `is_head_teacher` (4-16). Quotas et partage de classe intégrés à la page groupe. SE4 : `annu2/add_group.php`, `add_user_group.php`, `group.php`, `constitutiongroupe.php`. Une route de repli legacy `users/groups/legacy-new` subsiste.

### 1.7 Purge des profils Windows itinérants — 🟡 Partiel

Le mécanisme existe (`RoamingProfileService::generatePurgeScript()`, snapshot 26.3) côté admin/filesystem ; la purge **unitaire depuis la fiche user** (`annu2/del_nt_profile.php`) reste à confirmer/exposer.

### 1.8 Export CSV de listes de groupes — 🟡 Partiel

`ImportExportService` couvre l'import/export CSV générique et l'export des listes de reset, mais pas d'écran dédié « export CSV d'annuaire par groupe » (`annu/grouplist_csv.php`, `listing.php`).

### 1.9 Imports externes SIECLE / STS / AAF / ENT / GPEI — 🔗 Legacy supporté (choix assumé)

`annu/import_siecle.php`, `import_sts.php`, `import_asm.php`, `import_ent.php` + `config_ent.php`, `import_gpei.php`. **Non reportés par décision d'architecture** (ADR-1/ADR-6) : ces imports provisionnent l'AD, ce que SE5 ne fait plus. Ils restent accessibles via le legacy le temps de la coexistence ; à terme, le provisioning viendra de controlHub/Keycloak (Epic 11, backlog).

### 1.10 Import CSV de comptes — 🟡 Partiel

`ImportExportService::importFromCSV()` existe, mais sans les ~20 formats ENT préconfigurés du legacy (`$tab_csv_format` : atos, kosmos, itop, pentila, toutatice…) ni provisioning AD.

### 1.11 Sync cron central↔établissements — 🚫 Remplacé

Le flux SE4 (SFTP bastion rectorat → SCP vers les établissements, `annu/sync_cron.php`) est inversé : SE5 synchronise **AD→SQL** (`users:sync-from-ad` toutes les 5 min, `UserSyncService`, page `sync-from-ad`). La distribution inter-serveurs relève de controlHub.

### 1.12 Comptes temporaires — ❌ Non porté

`annu/delete_temp_users.php` (purge cron de comptes invités/éphémères). Aucune notion d'expiration dans `User`/`UserService`, pas de story.

### 1.13 Professeur remplaçant — ❌ Non porté

`annu/remplace.php` (rattachement d'un remplaçant aux classes d'un titulaire). Aucun équivalent, pas de story.

### 1.14 Nettoyage des comptes orphelins — ❌ Non porté

`annu/ldap_cleaner.php` (central uniquement). `AdSyncChecker` fait du contrôle de cohérence, pas de purge. Pertinence à réévaluer dans le modèle SQL-source-de-vérité.

---

## 2. Authentification & SSO

### 2.1 Login LDAP, auto-login IP, logout, changement mdp — ✅ Natif

`AuthController` (+ `SambaEduAuthGuard`), `attemptAutoLogin($ip)` avec fallback `?autolog=1`, `ChangePasswordController` + middleware `password.change` (mdp forcé au 1er login via `password_changed_at`). SE4 : `auth.php`, `logout.php`, `annu/mod_pwd.php`.

### 2.2 OAuth2 OpenENT — ✅ Natif

`AuthController::redirectToEntOAuth2()` / `entCallback()` avec `league/oauth2-client` (comme SE4), state CSRF, gestion quota ENT, appariement par `externalId`. SE4 : `sso/oauth2.php`, `oauth2/`.

### 2.3 CAS — 🟡 Partiel

`redirectToCas()` / `casCallback()` (phpCAS) portés, mais deux manques : le **catalogue d'~150 serveurs CAS préconfigurés par académie/ENT** (`$tab_serveur_cas`) — SE5 attend une config unique par instance — et la **chaîne de proxy CAS** (`allowProxyChain`). Refonte native du module en pause (14-3).

### 2.4 MFA SMS admin ENT, OpenID Connect, public_key.js — 🚫 Abandonnés

OpenID (`sso/openid.php`) n'a jamais été fini en SE4. Le MFA SMS servait le scraping de comptes ENT, rendu sans objet par l'OAuth2 direct. Le chiffrement RSA côté navigateur (`public_key.js`) est obsolète face à HTTPS + sessions Laravel.

### 2.5 Espace personnel (quota self-service) — ❌ Non porté

`individuel.php` (occupation disque de l'utilisateur connecté, avertissements de dépassement). Les quotas sont exposés côté admin uniquement ; l'overlay poste affiche l'alerte quota, mais pas de page web self-service. Pas de story dédiée.

**Nouveauté SE5 sans équivalent SE4** : authentification fédérée d'intervenants externes hors-AD par JWT signé controlHub (Epic 20, 5/6 done) — audit dédié, rétention RGPD, purge.

---

## 3. Droits & délégations

### 3.1 Délégations par périmètre — ✅ Natif

Le bitmask legacy (`SE_ADMIN`, `have_right()` dans chaque page) est remplacé par **rôles/permissions Spatie scopés** : `RightsService` (reconstruit le bitmask en contrat de rétro-compat), `Delegation` + historique, migration prod AD→SQL (`MigrateRightsToSpatieCommand`, `MigrateDelegationsCommand`). Epic 7 done. Page `/rights-management`.

⚠️ Gap connu : le scope par WorkstationGroup n'est pas appliqué aux Gates `wpkg.*` (délégation WPKG globale, cf. mémoire projet).

### 3.2 Profils de droits — ✅ Natif

Pages `rights-management/profiles/`. La revue de la matrice profils × droits par l'équipe est l'Epic 12 (in-progress).

---

## 4. Parcs & postes

### 4.1 CRUD parcs — ✅ Natif

`WorkstationGroupService` (arbre, salles physiques, verrouillage, bulk), pivot unifié poste↔groupe (4-11). SE4 : `parcs2/create_parc.php` et consorts, `parcs/action_parc.php`. Enum environnement partagé/personnel/nomade (Epic 26) — nouveauté structurante par rapport à SE4.

### 4.2 Association postes, import/export CSV — ✅ Natif

Routes `parcs.import.csv` / `parcs.export.csv` (story 4-5). Parité fine de `import_sites.php` non vérifiée.

### 4.3 WOL / extinction / redémarrage / ping — ✅ Natif

`MachinePowerService` (WoL, sonde TCP 22/445/135/139 pour détecter linux/windows/offline), `DispatchMachinePowerActionJob`, actions relayées par l'agent. SE4 : `parcs2/wolstop_station.php`, `parcs.inc.php`.

### 4.4 Planification horaire — ✅ Natif (MVP)

`WorkstationGroupSchedule` (récurrent jours+heure+timezone, ou one-shot), runs historisés. SE4 : créneaux 15 min de `fonc_parc.inc.php` + table `actions`. Le MVP couvre wake + shutdown ; restart/shutdown-force non planifiables.

### 4.5 Prise en main à distance (Guacamole) — 🔗 Legacy supporté

`RemoteAccessService` **require** encore `includes/remote.inc.php` (recherche de poste, `create_remote_token`, URLs Guacamole). `AdMachineManager` retourne vide (« guacamole repo not yet native », `docs/tech-debt-gpo.md`). Le Guacamole lui-même est un fork custom 1.6.0 hors repo ; le volet multi-établissements part côté controlHub (Epic C10, backlog). Principal point de dette du domaine parc.

### 4.6 Coupure Internet par le prof — 🟡 Partiel

Le mécanisme est porté (groupe `no_internet` dans `config/sambaedu.php`, scripts firewall logon/startup) mais le **geste enseignant** (formulaire « Bloquer/débloquer Internet » de `user.interface.inc.php`) n'a pas d'UI SE5. À rapprocher du blocage examen par capability firewall (§7.9) qui pourrait le remplacer proprement.

---

## 5. Installation OS / iPXE

Refonte native complète (Epic 3 done). SE4 : `ipxe/` (boot.php, enregistrement, Win10/, linux/, clonezilla…).

### 5.1 Boot + enrôlement — ✅ Natif

`app/Ipxe/` : boot par MAC/UUID, menus admin/maintenance, enrôlement parc/salle/nommage/BYOD. **L'enrôlement iPXE émet aussi le ticket one-time de l'agent** (porte 1 du canal desired-state) — couplage nouveau vs SE4.

### 5.2 Installation Windows + ISO — ✅ Natif (enrichi)

Builders unattend/sysprep/diskpart/install.bat, suivi post-install, upload ISO chunké, extraction, **injection automatique de pilotes NIC WinPE via wimlib (3-10, nouveauté SE5)**.

### 5.3 Installation Linux — ✅ Natif

Preseed Debian/Ubuntu, menus, post-install tracker, autorun.

### 5.4 Clonage & maintenance — 🟡 Partiel

Clonezilla (live, save/restore sda1/sda2) et diagnostics couverts (`IpxeAdminAction`). Parité fine des outils annexes (sysrescuecd, gparted, hdt, memtest) à confirmer. Variantes diskless legacy `se4fs`/`se4ad` non retrouvées.

### 5.5 LTSP — ⏳ Backlog (story 3-9)

---

## 6. Déploiement logiciels

### 6.1 Endpoints WPKG — ✅ Natif

`hosts_xml_out` / `profiles_xml_out` / `linux_out` / `winget_out` réécrits avec docblocks `@legacy-port`, résolveur Eloquent unique (`WorkstationPackagesResolver`), transport **full HTTP** (27-19, fin du SMB).

### 6.2 Catalogue & profils applicatifs — ✅ Natif

`Application` / `AppProfile` / `NativeApplication` / `DepotApplication`, association parcs/postes, défauts de parc.

### 6.3 Pipeline, dashboard, logs — ✅ Natif (refonte, Epic 15 done)

Déploiements ciblés, options par poste, statuts par poste, ingestion des rapports (`/api/v1/wpkg/reports/{hostname}`), archivage/rotation, parsing d'erreurs (9-4/9-5).

### 6.4 Dépôt / AppStore — ✅ Natif (Epic 8.2 done)

Sync dépôt, download queue non-bloquante avec progression Livewire, SHA-512, post-traitements. Installations natives (hors WPKG) incluses. Dépôt borné controlHub : Epic 31 done.

### 6.5 Déclenchement par l'agent — ✅ Natif (nouveau paradigme)

`ApplicationsStateProvider` + `handler_applications` : l'agent déclenche WPKG (« un tuyau, deux outils »), inventaire applicatif remonté. Gap connu : le retrait d'une app ne redéclenche pas la désinstallation automatiquement (mémoire projet).

---

## 7. Configuration des postes (GPO → agent desired-state)

**Le changement d'architecture central de SE5** : SE4 configure les postes par GPO + scripts générés à la volée (`gpo/*_out.php` appelés par la GPO orchestratrice `se4_applications`). SE5 remplace ce canal par l'**agent Go** (service SYSTEM signé + compagnon de session) qui tire un état cible JSON (`se5.desired-state/v1`, 10 types de ressources) et rapporte sa conformité. L'extinction du canal legacy est la story **27-14 (review) — gate de la mise en prod**.

### 7.1 Gestion des GPO — ✅ Natif

`GpoService` (wrapper samba-tool : list/get/links/héritage/setLink), UI `/admin/settings/gpo` (by-ou, links, wine, wpkg-deployment), santé/pin de version des templates (doctor).

### 7.2 Création/duplication/suppression de GPO — 🚫 Abandonné

Story 16-4 annulée : create/delete natifs = stubs ; la création réelle passe par `import_gpo`. Cohérent avec la direction : les GPO ne sont plus le canal d'avenir (successeur = capacités agent).

### 7.3 Publication SYSVOL (import_gpo) — 🔗 Legacy supporté

`AgentBootstrapPublisher` appelle le shim legacy `import_gpo` sous ticket Kerberos. Marqué explicitement « à porter natif à l'extinction du legacy (FR30/27.14) ».

### 7.4 Lecteurs réseau — ✅ Natif (agent)

SE4 : `homeDrive` AD + GPO Drives. SE5 : `DrivesStateProvider` + `handler_drives` — K: home, H: classes, lettres auto M-Z pour les répertoires managés, label appliqué (2.2.20). I: (Docs) et L: (Progs) volontairement non repris. Epic 34.

### 7.5 Wallpaper / lockscreen / overlay — ✅ Natif (agent, enrichi)

SE4 : `gpo/wallpaper_out.php`. SE5 : providers wallpaper/lockscreen (HKLM PersonalizationCSP) + **overlay Rainmeter verrouillé** (alertes info/warning/critical, quota, multi-session) — l'overlay est une nouveauté sans équivalent SE4.

### 7.6 Raccourcis — ✅ Natif (agent)

`ShortcutsStateProvider` + pivot assignables, icônes par asset statique (27-7). Gap connu : les .ico uploadés ne sont pas livrés par 27.1 (mémoire projet).

### 7.7 Associations de fichiers — ✅ Natif (agent)

Catalogue tagué + validation prédictive serveur, composer extension+app (27-11), UserChoice géré côté agent.

### 7.8 Registre / restrictions (capacités) — 🟡 Partiel

Modèle capabilities/projections/assignments (27-12), défaut broadcast + override par parc, lots seedés (ISO, CD95 du 2026-07-02). **Gaps tracés → Epic 35** : listes indexées (`ExtensionInstallForcelist`, `DisallowRun`), mécanisme secedit (`SeDenyRemoteInteractiveLogonRight`), ruche `HKCU\Policies` (DisableCMD), `HKU\.DEFAULT` (Numlock), UI d'assignation par UserGroup.

### 7.9 Blocage Internet examen — ⏳ En cours

Capacité `internet_access` par mécanisme firewall (27-13 ready-for-dev, Epic 36 cadré : règles firewall possédées + fs_acl).

### 7.10 Veyon — ✅ Natif

Endpoint runtime `veyon_out` réimplémenté (16-4 epic runtime endpoints).

### 7.11 Firefox / Thunderbird — ✅ Natif

`FirefoxPolicyAdapter` / `ThunderbirdPolicyAdapter` (+ découverte d'extensions), scope MACHINE par parc via `AppConfigStateProvider` + `handler_app_config` (27-4). SE4 : `includes/firefox.inc.php`, `gpo/firefox*.php`.

### 7.12 Wine — ✅ Natif

`GenerateWineImageJob`, `WinePrefixScanner`, associations packages.

### 7.13 Roaming / redirections — 🔗 Legacy supporté

`RoamingProfileService` lit/écrit la GPO `redirections` via les shims SYSVOL legacy (`read_gpo_sysvol`, byte-fidèle à `del_roam.php`). Dernier gros consommateur des shims LDAP/SYSVOL.

### 7.14 Scripts startup/logon — ✅ Natif

Moteur `applications.php` porté avec whitelist + wrapper de logs (`app/ScriptsOs/`, ingestion + archivage), compat GPO orchestratrice `se4_applications` (Epic 17 done).

---

## 8. Partages, ACL, quotas

### 8.1 Partages de classe — ✅ Natif

`ShareService::createClassShare()` : décalque 1:1 du legacy (`_travail` prof-RW/élèves-RO, `_profs`, `_echange`, dossier par élève), durci (anti-traversal, fail-closed getent, verrous, audit, suffixe établissement fédéré). Toggle `_echange` séparé. SE4 : `partages/rep_classes.php`, `dossier_echange/`, `includes/partages.inc.php`.

### 8.2 Dossiers élèves & changement de classe — ✅ Natif

`syncUserClassMemberships` avec archivage vers `Archives/`. L'inversion de login `nom.prenom` legacy n'est pas reproduite (login normalisé).

### 8.3 Homes & corbeille — ✅ Natif

`HomeDirService` (create/archive/restore/delete + verrous), `trash:purge` par TTL. Corbeille étendue aux partages managés (`.trash/`, jamais de rm -rf).

### 8.4 Répertoires réseau managés + templates — ✅ Natif (nouveau)

`NetworkShareService` (provision/deprovision/drift), assignations User/UserGroup × ro/rw, `DirectoryTemplateService` (4 recettes : direction→tous, profs→élèves, user↔user, groupe). Import de l'existant : `shares:inspect-fs` / `shares:import-from-fs`. Story 34.3 en review (branche `worktree-E34-lecteurs`).

### 8.5 Workflow devoirs — 🟡 Partiel

Le dossier `_travail/devoirs` et ses ACL sont posés, mais la logique de collecte/récupération des copies (`liste_devoirs`/`find_devoirs`) n'est pas portée — notée « feature à concevoir » dans le code.

### 8.6 Éditeur ACL libre — 🟡 Partiel (par conception)

Le module `acls/` (navigation arbo + édition par entrée r/w/x, héritage, récursif) est remplacé par le modèle managé réduit **ro/rw**. Les ACL non représentables sont classées `unmappable` à l'import (fail-closed). Choix assumé : moins expressif, plus sûr. Le mécanisme fs_acl de l'Epic 36 en récupérera une partie (règles d'accès aux dossiers par formulaire).

### 8.7 Casiers élève→prof — ⏳ Backlog

Sous-espaces par élève visibles du prof. Explicitement reporté « 34.x » (le socle pose l'ACL à la racine du répertoire, pas par sous-dossier).

### 8.8 Quotas XFS — ✅ Natif

`XfsQuotaService` (héritage user > groupe > **plafond par défaut d'instance**, une ligne par partition ; les défauts typés par population n'existent plus), snapshot quotidien, audit, UI admin + page groupe. SE4 : `includes/quotas.inc.php`.

### 8.9 Sync cloud Nextcloud/Seafile — ✅ Natif (mécanisme changé)

`partages/rep_cloud.php` + `includes/cloud.inc.php` (réplication du modèle de partages classes vers Nextcloud/Seafile, lecteur S:). SE5 ne réplique plus : un espace peut avoir un **produit cloud pour autorité**, et les droits y sont écrits directement par une implémentation du contrat de backend (`app/Services/Filesystem/Backend/Nextcloud/`, `.../OpenCloud/`). Un seul produit cloud est actif à la fois, et un espace servi par lui n'a **aucune lettre de lecteur** : l'accès se fait au navigateur ou par le client natif de l'éditeur. Voir [`domains/filesystem.md`](domains/filesystem.md).

---

## 9. Impression

### 9.1 CUPS — ✅ Natif

`CupsPrinterService` (CRUD lpadmin, enable/disable, drivers, exceptions typées Kerberos/daemon), `PrintDriverService`, syncs. Réservation DHCP intégrée. SE4 : `printers/` + `includes/printers.inc.php` (rpcclient/smbclient).

### 9.2 Déploiement par salle/parc — ✅ Natif (mécanisme changé)

Le `Printers.xml` GPO (FilterGroup par SID) est remplacé par le pivot `printer_workstation_group` + `PrintersStateProvider` (imprimante par défaut exclusive résolue serveur).

### 9.3 Déploiement par groupe utilisateur — 🟡 Partiel

Le legacy filtrait aussi par groupe **utilisateur** (userContext=1) ; SE5 ne connaît que la maille **poste** (« l'imprimante de la salle »). À valider si des déploiements par groupe user existaient en production.

### 9.4 Jobs d'impression — 🟡 Partiel

Comptage des jobs porté (`getJobsCount`), suppression de jobs (`printer_jobs.php`) non portée.

---

## 10. Réseau

### 10.1 DHCP — ✅ Natif

`DhcpService` (CRUD réservations, export `reservations.inc`, reload, parsing des baux `dhcpd.leases`), `DhcpImportService`, UI `pages/network/`. SE4 : `dhcp/`. Rappel hors-git : sur les déploiements, `dhcpd.conf` doit chaîner `/ipxe/boot` (config VM/serveur, pas applicatif).

---

## 11. Collaboration & divers

### 11.1 BigBlueButton — 🔗 Legacy supporté

Salons par prof, équilibrage multi-serveurs/Scalelite, enregistrements, salle d'attente (`bbb/` + `includes/bbb.inc.php`, 33 Ko). Les menus SE5 pointent en dur vers `/bbb/create.php`. Refonte/compat BBB 3.x = **Epic 13, backlog post-prod**.

### 11.2 Visio invités externes — 🔗 Legacy supporté

Page publique `visio/` (lien hashé + mdp, APCu 4 h). Suit le sort de BBB.

### 11.3 Affichage dynamique (display) — 🔗 Legacy supporté

Kiosque reveal.js (RSS + images + horloge) configuré par IP. Liens legacy dans `pages/homelegacy/`. Refonte native 14-2 **en pause**. (`display/screen.php` — programmation des écrans — n'a jamais été implémenté en SE4.)

### 11.4 Google Workspace — 🚫 Abandonné

Stub expérimental SE4 (domaine codé en dur), jamais productisé. Zéro trace SE5.

### 11.5 Ecowatt — ✅ Natif

`EcowattService` + endpoint API (relais du signal RTE). SE4 : `includes/power.inc.php`, `api/ecowatt.php`.

---

## 12. Administration, supervision, central

### 12.1 Mises à jour — ✅ Natif

`scripts/update.sh` (pipeline idempotent : composer, migrations, systemd, Apache, PKI, doctor) + `sambaedu:app:update`. Remplace `majtest.php` en nettement plus robuste.

### 12.2 Diagnostics — ✅ Natif

Système **doctor** (`sambaedu:doctor`, auto-discovery des checks AD/DB/Apache/GPO/iPXE/filesystem/controlHub) + page `/admin/settings/system-status`. Remplace `infos/` (`test_ldap.php`, df/du…) et la page `tests/` de diagnostics Ajax.

### 12.3 Délégation admin — ✅ Natif (cf. §3.1)

### 12.4 Paramétrage serveur — 🟡 Partiel

Le monolithe `conf_params.php` (38 Ko) + son applicateur `config/config_action.php` sont éclatés en pages settings ciblées + table `system_settings` + Services/Jobs spécialisés. **Paramètres sans équivalent UI identifié** : UAI, politique de mot de passe globale, proxy WPAD, choix du dépôt apt, IP Amon, mail d'alerte. À trier : encore pertinents ou obsolètes ?

### 12.5 SMTP — ❌ Non porté (en UI)

`conf_smtp.php` éditait `/etc/msmtprc` + `/etc/aliases` avec mail de test. SE5 **lit** msmtprc au provisioning (`create-env.sh` → `.env`) mais n'offre plus d'édition. Régression UI à arbitrer.

### 12.6 Actions serveur physiques — ❌ Non porté

`action_serv.php` : shutdown/reboot du serveur, extinction Proxmox via Redfish, console root SSH. Les actions power SE5 ciblent les **postes** ; l'ops serveur passe par systemd/update.sh. À confirmer comme retrait volontaire.

### 12.7 Paquets sambaedu-\* — 🟡 Partiel (repensé)

`conf_modules.php` mélangeait paquets Debian et modules. SE5 sépare : paquets système → `update.sh`/apt (hors app) ; applications déployables → AppStore natif. Pas de page « état des paquets ».

### 12.8 Logs de connexions postes — ✅ Natif (repensé)

`logs.php` (logon/logoff des postes) est couvert par le reporting agent + sessions. SE5 ajoute trois pistes d'audit sans équivalent SE4 : error-logger DB, monitoring catchall legacy, audit des actions externes.

### 12.9 Stats d'occupation / plannings — ☁️ controlHub (partiel local)

`StatsService` couvre l'instantané (système, users, disques) ; l'historisation par tranches horaires (`stats/update_stats.php`, plannings par poste) n'a pas d'équivalent local — destinée au dashboard controlHub.

### 12.10 Métriques InfluxDB — ☁️ controlHub

Le push `metrics/metrics.php` n'est pas repris localement ; SE5 expose ses stats au hub (heartbeat, snapshot, sync-manifest).

### 12.11 Serveur central — ☁️ controlHub

Toute l'app Slim `central/` (dashboard multi-établissements, alertes, annuaire central, carto) est remplacée par controlHub (`../irundoo`). SE5 devient **client** : handshake, heartbeat, ingestion de contrats, groupes imposés, rupture de lien (Epics 28-33, majoritairement done).

### 12.12 API clients — ✅ Natif

`api2/` (directory/status/action/stats) réécrit en API v1 Laravel ; le paradigme impératif `/action/machine` cède la place au desired-state agent. Ecowatt porté tel quel.

---

## 13. Nouveautés SE5 sans équivalent SE4

Pour mémoire, le delta inverse — ce que SE5 apporte que SE4 n'a jamais eu :

1. **Agent Go desired-state** (Epics 23-27) : convergence état-cible, rapports de conformité, service SYSTEM signé Authenticode, compagnon de session.
2. **Auto-update de l'agent** par releases signées et rings de déploiement (Epic 25).
3. **Overlay verrouillé** Rainmeter (alertes, quota, identité poste) (27-1bis/ter).
4. **Token per-host + enrôlement iPXE + anti-clonage** (23-2/23-3).
5. **Contrat amont controlHub** : verrou/permissif, ciblage par labels, dépôt applicatif borné, cycle de vie du lien (Epics 28-33).
6. **Auth fédérée d'externes** par JWT controlHub (Epic 20).
7. **Répertoires réseau managés + templates** (Epic 34).
8. **Injection auto de pilotes NIC WinPE** (3-10).
9. **Capacités v2 / bibliothèque de mécanismes typés** (registry_list, fs_acl, firewall — Epics 35-36, à venir).
10. **WPKG full HTTP** (fin du transport SMB) + résolveur Eloquent unique.
11. **Doctor** (diagnostics auto-découverts) + pipeline update.sh idempotent.
12. **Audits structurés** : error-logger, actions externes, overrides de capacités, historique de délégations, logs de scripts.

---

## 14. Points d'attention (gaps sans story identifiée)

Les manques suivants ne sont couverts par **aucune story backlog** repérée — candidats à arbitrage (porter / assumer l'abandon) :

1. **Catalogue CAS/ENT préconfiguré** (~150 serveurs) + proxy chain CAS (§2.3)
2. **Espace personnel / quota self-service** pour l'utilisateur final (§2.5)
3. **Comptes temporaires** à purge automatique (§1.12)
4. **Professeur remplaçant** (§1.13)
5. **Nettoyage des comptes orphelins** (§1.14)
6. **UI prof de coupure Internet** (§4.6) — probablement à fusionner avec la capability firewall 27-13
7. **Workflow devoirs** : collecte des copies (§8.5)
8. **Suppression des jobs d'impression** (§9.4)
9. **Déploiement d'imprimantes par groupe utilisateur** (§9.3)
10. **Édition SMTP en UI** (§12.5)
11. **Actions serveur physiques** (shutdown/Proxmox/console) (§12.6)
12. **Paramètres conf_params orphelins** : UAI, politique mdp, WPAD, dépôt apt, mail d'alerte (§12.4)
