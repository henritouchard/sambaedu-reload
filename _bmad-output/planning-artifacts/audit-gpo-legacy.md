# Audit GPO Legacy — Epic 16 (Story 16.1)

> Livrable structurant Story 16.1. Cartographie exhaustive des 19 fichiers UI
> `sambaedu/gpo/*.php` + 4 includes `sambaedu/includes/{gpo,samba-tool,delegations,gpo_ui}.inc.php`
> (≈5447 lignes legacy au total). Guide le découpage technique des stories
> 16.2 → 16.6 et la stratégie de portage fichier par fichier.

**Date** : 2026-05-11
**Auteur** : claude-opus-4-7 (Story 16.1, Phase T6)
**Statut** : draft — à valider avec Henri avant marquage `review` story 16.1.

**Révision 2026-05-11 (suite review)** : complétion AC6.1 — ajout explicite du
champ tabulaire **« Fichier source »** (chemin absolu + lignes) à chaque fiche
des 23 fichiers, garantissant que chaque fiche présente bien les **11 champs**
sous forme de lignes de tableau (et non uniquement dans le titre H4). Aucun
nouveau fichier ajouté : les 23 fiches existaient déjà en 6.A.1 (19) et 6.A.2
(4). Recompte vérifié : 23 occurrences pour chacun des 11 champs.

---

## Synthèse exécutive

- **23 fichiers** legacy cartographiés (19 UI + 4 includes).
- **9 fichiers déjà refondus en natif** (Stories 4.7, 4.8, `ShortcutsService`,
  `RoamingProfileService` 1bis.18f) → stratégie **« déjà-natif-rien-à-faire »**
  (sortie progressive du shim au fil de 16.x).
- **9 fichiers à porter** dans Epic 16 (16.2 à 16.6), dont **5 sections
  spécialisées** (Veyon, Wine, Associations apps, Network proxy, Roaming
  profiles complet).
- **2 fichiers de fondation** (`gpo.inc.php` + `samba-tool.inc.php`) à
  re-décomposer en services natifs sans port byte-identique — risque
  d'injection identifié.
- **23 commandes `samba-tool gpo *`** ou wrappers identifiés ; les 11 cibles
  de `GpoService` (AC3.1) couvrent l'intégralité du legacy.
- **Risques sécurité hérités** : 1 vecteur d'injection critique (`sambatool()`
  ligne 54), 3 `exec("wbinfo …")` non échappés, 1 `smbcacls` sur path non
  échappé — tous à corriger lors du portage natif.
- **Capacités legacy non revendiquées** identifiées : import/export de GPO
  templates (`gpo-maj`, `gpo-export`), réplication AD, fonctions DNS — à
  arbitrer avec Henri (Section 6.E).

**Recommandation découpage** (cf. 6.G) : la Story 16.3 est probablement à
splitter en **3 sous-stories** (16.3a Veyon/Wine/Associations, 16.3b Network
proxy + Roaming, 16.3c Sections déjà-natives à exposer). Le périmètre actuel
(7 sections) est sous-estimé : il y a en réalité **9 catégories** à traiter.

---

## Section 6.A — Cartographie fichier par fichier

> Pour chaque fichier : **11 champs** (AC6.1) — (1) Fichier source (chemin
> absolu + lignes), (2) Rôle, (3) Endpoints HTTP, (4) Inputs, (5) Outputs,
> (6) Dépendances système, (7) Statut shim 1bis.18, (8) Statut native,
> (9) Stratégie de port, (10) Story Epic 16 cible, (11) Risques / pièges.
> 23 fichiers couverts (19 UI + 4 includes). Chaque fiche présente les 11
> champs sous forme de lignes de tableau.

### 6.A.1 — Fichiers UI (`sambaedu/gpo/*.php`)

#### `gpo/gestion_gpo.php` (69 lignes)

| Champ                     | Valeur                                                                                                                          |
|---------------------------|---------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/gpo/gestion_gpo.php` — 69 lignes                                                  |
| **Rôle**                  | Page d'accueil du module GPO : liens vers `gpo-maj.php`, `gpo-export.php`, `no_roam.php`. Point d'entrée admin.                  |
| **Endpoints HTTP**        | GET `/gpo/gestion_gpo.php` — aucun paramètre                                                                                    |
| **Inputs**                | Aucun (page d'accueil)                                                                                                          |
| **Outputs**               | HTML statique (3 liens admin)                                                                                                   |
| **Dépendances système**   | Aucune (pas de samba-tool/smbclient)                                                                                            |
| **Statut shim 1bis.18**   | Oui — copié dans `legacy/modules/gpo/gestion_gpo.php` (1bis-18b)                                                                 |
| **Statut native**         | Non refondu                                                                                                                     |
| **Stratégie de port**     | **réécriture** (page d'index Livewire simple)                                                                                   |
| **Story Epic 16 cible**   | **16.2** (listing/lecture GPO native + index page)                                                                              |
| **Risques / pièges**      | Le check `empty($config['etab_ou'])` cache les liens import/export en mode établissement — comportement à conserver en natif.   |

#### `gpo/gpo-maj.php` (193 lignes)

| Champ                     | Valeur                                                                                                                                                                                       |
|---------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/gpo/gpo-maj.php` — 193 lignes                                                                                                                  |
| **Rôle**                  | Import des GPO templates dans l'AD : sélection multi `se4_<gpo>.zip` (templates Git) ou `etab_<gpo>.zip` (locaux), import avec gestion versions, listing des GPO actuelles sur le DC.        |
| **Endpoints HTTP**        | GET `/gpo/gpo-maj.php` (formulaire) + POST `imports[]`, `imports_etab[]`                                                                                                                     |
| **Inputs**                | POST `imports[]` (array de displaynames), `imports_etab[]` (array). Lecture FS : `/usr/share/sambaedu/gpo/templates/*.zip`, `/etc/sambaedu/applications/gpos.json`                            |
| **Outputs**               | HTML form + résultats d'import. Side effects : `import_gpo()` (gpo.inc.php:956) — copie SYSVOL via smbclient + `modify_ad` + `gposetlink`                                                    |
| **Dépendances système**   | `samba-tool gpo create`, `samba-tool gpo listcontainers`, `samba-tool gpo setlink`, `smbclient` (sysvol upload), `sudo apt update && apt install sambaedu-gpo-templates`                     |
| **Statut shim 1bis.18**   | Oui — copié dans `legacy/modules/gpo/gpo-maj.php` (1bis-18b)                                                                                                                                 |
| **Statut native**         | Non refondu                                                                                                                                                                                  |
| **Stratégie de port**     | **port + adaptation** : `import_gpo()` (gpo.inc.php) est dense (~120 lignes), forte logique métier (versioning, increment, links). Idéalement décomposer en `GpoImportService`               |
| **Story Epic 16 cible**   | **16.4** (CRUD complet, import = `create` + `setLink` + sysvol upload)                                                                                                                       |
| **Risques / pièges**      | (1) `exec("sudo apt update …")` à supprimer impérativement en natif. (2) Versioning major.minor sur 16 bits chaque — préserver. (3) `update_gpo_sysvol` utilise `change_pol_key` qui peut écraser des modifs locales. (4) Gestion des "force/update" pas triviale (priorisation entre version Git, locale, AD). |

#### `gpo/gpo-export.php` (88 lignes)

| Champ                     | Valeur                                                                                                                            |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/gpo/gpo-export.php` — 88 lignes                                                    |
| **Rôle**                  | Export des GPO du DC vers archive zip téléchargeable (`/var/www/sambaedu/tmp/etab_<gpo>.zip`).                                    |
| **Endpoints HTTP**        | GET/POST `/gpo/gpo-export.php` — POST `exports[]`                                                                                 |
| **Inputs**                | POST `exports[]` (array displaynames)                                                                                             |
| **Outputs**               | HTML form + lien de téléchargement zip                                                                                            |
| **Dépendances système**   | `samba-tool gpo getlink` (listing), `smbclient` (fetch sysvol via `export_gpo`), `zip_gpo()` (`exec("zip …")`)                    |
| **Statut shim 1bis.18**   | Oui — `legacy/modules/gpo/gpo-export.php` (1bis-18b)                                                                              |
| **Statut native**         | Non refondu                                                                                                                       |
| **Stratégie de port**     | **port + adaptation** : `export_gpo()` (gpo.inc.php:1087) génère un GPT.INI + zip. Story 16.4 (CRUD).                              |
| **Story Epic 16 cible**   | **16.4**                                                                                                                          |
| **Risques / pièges**      | `exec("cp -f ...")` ligne 77 — chemins partiellement échappés. Le contenu généralisé peut leaker des SIDs réels (cf. `generalise_gpo`). |

#### `gpo/no_roam.php` (65 lignes)

| Champ                     | Valeur                                                                                                                            |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/gpo/no_roam.php` — 65 lignes                                                       |
| **Rôle**                  | UI de gestion des exclusions de profils itinérants Windows (GPO `redirections`, clé `ExcludeProfileDirs`).                        |
| **Endpoints HTTP**        | GET/POST `/gpo/no_roam.php`                                                                                                       |
| **Inputs**                | POST `valider`, `suppr[]`, `apply`, `Value[]`                                                                                     |
| **Outputs**               | HTML form + stats roaming                                                                                                         |
| **Dépendances système**   | LDAP (`search_ad`), SYSVOL (`read_gpo_sysvol`, `update_gpo_sysvol`), `increment_gpo_sysvol`                                        |
| **Statut shim 1bis.18**   | Non — redirigé vers `/admin/settings?tab=profils-itinerants` (Story 1bis.18f, Catchall override)                                  |
| **Statut native**         | **Refondu Story 1bis.18f** — `RoamingProfileService` + tab Livewire dans `/admin/settings`                                        |
| **Stratégie de port**     | **déjà-natif-rien-à-faire**                                                                                                       |
| **Story Epic 16 cible**   | hors Epic 16 (déjà fait)                                                                                                          |
| **Risques / pièges**      | Pendant Epic 16, vérifier que la redirection 1bis.18f reste active si on remplace le catchall.                                    |

#### `gpo/del_roam.php` (27 lignes)

| Champ                     | Valeur                                                                                                                            |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/gpo/del_roam.php` — 27 lignes                                                      |
| **Rôle**                  | Endpoint script logon (curl depuis poste Windows) qui retourne un script `rm -fr` pour nettoyer les dossiers exclus du profil.    |
| **Endpoints HTTP**        | GET `/gpo/del_roam.php?se4_key=…`                                                                                                 |
| **Inputs**                | GET `se4_key` (auth via `header_authorize_script`)                                                                                |
| **Outputs**               | `text/plain` — script bash `rm -fr`                                                                                               |
| **Dépendances système**   | LDAP `search_ad`, SYSVOL `read_gpo_sysvol`                                                                                        |
| **Statut shim 1bis.18**   | Non — redirigé vers `/admin/gpo/del-roam.sh` (Story 1bis.18f)                                                                     |
| **Statut native**         | **Refondu Story 1bis.18f** — route Laravel `del-roam.sh`                                                                          |
| **Stratégie de port**     | **déjà-natif-rien-à-faire**                                                                                                       |
| **Story Epic 16 cible**   | hors Epic 16                                                                                                                      |
| **Risques / pièges**      | (1) `$value` issu de GPO injecté dans `rm -fr` → vecteur si valeur GPO malicieuse (mitigé par filtrage regex 1bis.18f défense). (2) Garder le scenario QA 1bis.18f-13 (log debug, pas info). |

#### `gpo/user_profile_stats.php` (32 lignes)

| Champ                     | Valeur                                                                                                                          |
|---------------------------|---------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/gpo/user_profile_stats.php` — 32 lignes                                          |
| **Rôle**                  | Drill-down stats roaming par utilisateur (sous-page de `no_roam.php`).                                                          |
| **Endpoints HTTP**        | GET `/gpo/user_profile_stats.php?path=…`                                                                                        |
| **Inputs**                | GET `path` (dossier dans le profil)                                                                                             |
| **Outputs**               | HTML tableau                                                                                                                    |
| **Dépendances système**   | `partages.inc.php:roaming_profiles_stats` (lit `/tmp/du.txt` cron `du.sh`)                                                      |
| **Statut shim 1bis.18**   | Non — redirigé vers `/admin/settings?tab=profils-itinerants` (modale détail)                                                    |
| **Statut native**         | **Refondu Story 1bis.18f** (modale stats users)                                                                                 |
| **Stratégie de port**     | **déjà-natif-rien-à-faire**                                                                                                     |
| **Story Epic 16 cible**   | hors Epic 16                                                                                                                    |
| **Risques / pièges**      | Aucun.                                                                                                                          |

#### `gpo/applications.php` (51 lignes)

| Champ                     | Valeur                                                                                                                                                                   |
|---------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/gpo/applications.php` — 51 lignes                                                                                          |
| **Rôle**                  | Endpoint script GPO `se4_applications` — génère un script `.cmd`/`.bash` exécuté au startup/logon/logoff/shutdown des postes (configuration des apps).                   |
| **Endpoints HTTP**        | GET/POST `/gpo/applications.php?ret=…&action=…&os=…&user=…&machine=…`                                                                                                    |
| **Inputs**                | POST/GET `ret` (id apcu), résolu via `get_app_scripts_info($config)` ; `applications.inc.php`, `cloud.inc.php`                                                            |
| **Outputs**               | `text/plain` (script bash/cmd) + log dans `/tmp/applications-<action>-<user>.cmd`                                                                                        |
| **Dépendances système**   | LDAP (`search_ad`), `applications.inc.php` (substitutions de variables), `cloud.inc.php`                                                                                 |
| **Statut shim 1bis.18**   | Oui — `legacy/modules/gpo/applications.php` (1bis-18e)                                                                                                                   |
| **Statut native**         | Non refondu                                                                                                                                                              |
| **Stratégie de port**     | **réécriture** — endpoint stateless de génération de script, candidat naturel pour un Controller Laravel + service.                                                      |
| **Story Epic 16 cible**   | **16.3** (sections spécialisées — apps scripts startup/logon)                                                                                                            |
| **Risques / pièges**      | (1) Substitution `###_PARAMETRE_###` doit être audité — vecteur d'injection si template malformé. (2) Le script est CONSOMMÉ comme batch → ne pas générer de contenu user-controlled non sanitisé. |

#### `gpo/associations_out.php` (173 lignes)

| Champ                     | Valeur                                                                                                                                                          |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/gpo/associations_out.php` — 173 lignes                                                                            |
| **Rôle**                  | Endpoint JSON qui retourne les associations d'extensions de fichiers (`ProgId`) pour les apps WPKG installées sur un poste.                                     |
| **Endpoints HTTP**        | POST `/gpo/associations_out.php` (POST `id`, `list`)                                                                                                            |
| **Inputs**                | POST `id` (apcu key), `list` (JSON des assocs locales du poste)                                                                                                 |
| **Outputs**               | JSON `{result: {...}}`                                                                                                                                          |
| **Dépendances système**   | LDAP, `wpkg_lib.php`, `wpkg_libsql.php`, lecture `/usr/share/sambaedu/applications/associations/default.xml`, `/etc/sambaedu/applications/associations/associations.json`, `packages.xml` (XML DOM) |
| **Statut shim 1bis.18**   | Oui — `legacy/modules/gpo/associations_out.php` (1bis-18e)                                                                                                      |
| **Statut native**         | Non refondu (sous-jacent WPKG géré par Stories 15.x)                                                                                                            |
| **Stratégie de port**     | **réécriture** — couplage fort avec le modèle WPKG (Story 15.x). À aligner avec le pipeline WPKG natif Story 15.2/15.3.                                          |
| **Story Epic 16 cible**   | **16.3** (sections spécialisées — associations apps) OU à déplacer dans Epic 15 selon couplage                                                                  |
| **Risques / pièges**      | `file_put_contents("/tmp/assoc_*.json", …)` writes debug en clair — fuite info modeste. Pas d'auth visible — vérifier au moment du port.                        |

#### `gpo/firefox.php` (107 lignes)

| Champ                     | Valeur                                                                                                                                  |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/gpo/firefox.php` — 107 lignes                                                            |
| **Rôle**                  | UI admin de configuration Firefox (page d'accueil, marque-pages, extensions). Persiste `/etc/sambaedu/applications/firefox/default.json`. |
| **Endpoints HTTP**        | GET/POST `/gpo/firefox.php`                                                                                                             |
| **Inputs**                | POST `valider`, `suppr[]`, `Homepage`, `Title[]`, `URL[]`, `Folder[]`, `installation_mode[]`, `install_url[]`, `ext_id`, `ext_suppr[]`  |
| **Outputs**               | HTML form `ff_form_policy()` (depuis `firefox.inc.php`)                                                                                 |
| **Dépendances système**   | `firefox.inc.php` (ff_import_policy/ff_export_policy/ff_form_policy/get_ff_ext_id)                                                      |
| **Statut shim 1bis.18**   | Non — **annulé 1bis-18c (superseded 4-8)**                                                                                              |
| **Statut native**         | **Refondu Story 4.8** (`AppCustomization` model + UI Livewire)                                                                          |
| **Stratégie de port**     | **déjà-natif-rien-à-faire** OU à adapter pour exposer dans la page GPO native (Story 16.3)                                              |
| **Story Epic 16 cible**   | À trancher en 16.3 : soit hors scope, soit on ajoute un lien vers la page natifs `AppCustomization`.                                    |
| **Risques / pièges**      | Aucun — déjà native.                                                                                                                    |

#### `gpo/firefox_out.php` (16 lignes)

| Champ                     | Valeur                                                                                                                                  |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/gpo/firefox_out.php` — 16 lignes                                                         |
| **Rôle**                  | Endpoint JSON consommé par les postes Firefox : retourne la politique Firefox effective pour `id` (machine/groupe).                     |
| **Endpoints HTTP**        | GET/POST `/gpo/firefox_out.php?id=…&os=…`                                                                                               |
| **Inputs**                | GET/POST `id` (apcu key), `os`                                                                                                          |
| **Outputs**               | JSON Firefox policies                                                                                                                   |
| **Dépendances système**   | `firefox.inc.php:ff_import_policy/ff_export_policy`                                                                                     |
| **Statut shim 1bis.18**   | Non — annulé 1bis-18c                                                                                                                   |
| **Statut native**         | **Refondu Story 4.8** (endpoint natif via `AppCustomization`)                                                                           |
| **Stratégie de port**     | **déjà-natif-rien-à-faire**                                                                                                             |
| **Story Epic 16 cible**   | hors Epic 16                                                                                                                            |
| **Risques / pièges**      | Aucun.                                                                                                                                  |

#### `gpo/thunderbird_out.php` (14 lignes)

| Champ                     | Valeur                                                                                                                                  |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/gpo/thunderbird_out.php` — 14 lignes                                                     |
| **Rôle**                  | Endpoint JSON pour les postes Thunderbird (similaire `firefox_out.php`).                                                                |
| **Endpoints HTTP**        | GET/POST `/gpo/thunderbird_out.php?id=…`                                                                                                |
| **Inputs**                | GET/POST `id`                                                                                                                           |
| **Outputs**               | JSON policy Thunderbird                                                                                                                 |
| **Dépendances système**   | `firefox.inc.php:tb_import_policy/ff_export_policy`                                                                                     |
| **Statut shim 1bis.18**   | Non — annulé 1bis-18c                                                                                                                   |
| **Statut native**         | **Refondu Story 4.8** (endpoint natif via `AppCustomization`)                                                                           |
| **Stratégie de port**     | **déjà-natif-rien-à-faire**                                                                                                             |
| **Story Epic 16 cible**   | hors Epic 16                                                                                                                            |
| **Risques / pièges**      | Aucun.                                                                                                                                  |

#### `gpo/wallpaper.php` (46 lignes)

| Champ                     | Valeur                                                                                                                                  |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/gpo/wallpaper.php` — 46 lignes                                                           |
| **Rôle**                  | UI admin de gestion des fonds d'écran (récap + upload via `upload_wallpaper`).                                                          |
| **Endpoints HTTP**        | GET/POST `/gpo/wallpaper.php`                                                                                                           |
| **Inputs**                | POST uploads multipart                                                                                                                  |
| **Outputs**               | HTML form                                                                                                                               |
| **Dépendances système**   | `wallpaper.inc.php:upload_wallpaper`                                                                                                    |
| **Statut shim 1bis.18**   | Non — annulé 1bis-18d (superseded 4-7)                                                                                                  |
| **Statut native**         | **Refondu Story 4.7** (wallpaper Eloquent + Livewire)                                                                                   |
| **Stratégie de port**     | **déjà-natif-rien-à-faire**                                                                                                             |
| **Story Epic 16 cible**   | hors Epic 16                                                                                                                            |
| **Risques / pièges**      | Aucun.                                                                                                                                  |

#### `gpo/wallpaper_out.php` (48 lignes)

| Champ                     | Valeur                                                                                                                                  |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/gpo/wallpaper_out.php` — 48 lignes                                                       |
| **Rôle**                  | Endpoint image (jpg/png) qui sert dynamiquement le fond d'écran courant pour un poste / utilisateur.                                    |
| **Endpoints HTTP**        | GET/POST `/gpo/wallpaper_out.php?action=lockscreen|wallpaper|veyon|icone&user=…&id=…&format=…`                                          |
| **Inputs**                | GET/POST `action`, `user`, `id`, `format`                                                                                               |
| **Outputs**               | Image binaire (image/jpg, image/png)                                                                                                    |
| **Dépendances système**   | `wallpaper.inc.php:make_lockscreen/make_wallpaper/make_icon`                                                                            |
| **Statut shim 1bis.18**   | Non — annulé 1bis-18d                                                                                                                   |
| **Statut native**         | **Refondu Story 4.7** (endpoints natifs avec controller `WallpaperController`)                                                          |
| **Stratégie de port**     | **déjà-natif-rien-à-faire**                                                                                                             |
| **Story Epic 16 cible**   | hors Epic 16                                                                                                                            |
| **Risques / pièges**      | Aucun.                                                                                                                                  |

#### `gpo/shortcuts.php` (33 lignes)

| Champ                     | Valeur                                                                                                                                  |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/gpo/shortcuts.php` — 33 lignes                                                           |
| **Rôle**                  | UI admin récap des raccourcis (apps startup, raccourcis bureau).                                                                        |
| **Endpoints HTTP**        | GET/POST `/gpo/shortcuts.php`                                                                                                           |
| **Inputs**                | (vide ou récap seul)                                                                                                                    |
| **Outputs**               | HTML `list_shortcuts()` (de `shortcuts.inc.php`)                                                                                        |
| **Dépendances système**   | `shortcuts.inc.php`                                                                                                                     |
| **Statut shim 1bis.18**   | Non (catchall `blocked_legacy_routes` redirige `^gpo/shortcuts_out\.php` vers `app/shortcuts`)                                          |
| **Statut native**         | **Refondu** — `ShortcutsService` + `ShortcutExportController` + page `/app/shortcuts`                                                   |
| **Stratégie de port**     | **déjà-natif-rien-à-faire**                                                                                                             |
| **Story Epic 16 cible**   | hors Epic 16                                                                                                                            |
| **Risques / pièges**      | Aucun.                                                                                                                                  |

#### `gpo/shortcuts_out.php` (67 lignes)

| Champ                     | Valeur                                                                                                                                  |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/gpo/shortcuts_out.php` — 67 lignes                                                       |
| **Rôle**                  | Endpoint script logon qui génère les commandes de création de raccourcis pour `windows`/`linux` (curl depuis poste).                    |
| **Endpoints HTTP**        | GET/POST `/gpo/shortcuts_out.php?action=…&os=…&user=…&machine=…&shortcut=…`                                                             |
| **Inputs**                | GET/POST tous params                                                                                                                    |
| **Outputs**               | `text/plain` (script bash/cmd) ou fichier `.desktop` ou icône image                                                                     |
| **Dépendances système**   | `shortcuts.inc.php:shortcut_create_script`, `make_shortcut`, `make_shortcut_icon`                                                       |
| **Statut shim 1bis.18**   | Non — bloqué par `blocked_legacy_routes` du catchall, redirigé vers `app/shortcuts`                                                     |
| **Statut native**         | **Refondu** (`ShortcutExportController` Laravel)                                                                                        |
| **Stratégie de port**     | **déjà-natif-rien-à-faire**                                                                                                             |
| **Story Epic 16 cible**   | hors Epic 16                                                                                                                            |
| **Risques / pièges**      | Aucun — déjà native.                                                                                                                    |

#### `gpo/gestion_apps.php` (48 lignes)

| Champ                     | Valeur                                                                                                                                  |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/gpo/gestion_apps.php` — 48 lignes                                                        |
| **Rôle**                  | Page de gestion des applications GPO — actuellement réduite à un seul lien vers Firefox config.                                         |
| **Endpoints HTTP**        | GET `/gpo/gestion_apps.php`                                                                                                             |
| **Inputs**                | Aucun                                                                                                                                   |
| **Outputs**               | HTML statique (1 lien)                                                                                                                  |
| **Dépendances système**   | Aucune (juste ihm.inc.php, ldap.inc.php)                                                                                                |
| **Statut shim 1bis.18**   | (à confirmer — 1bis-18e ne l'inclut pas explicitement)                                                                                  |
| **Statut native**         | Non refondu                                                                                                                             |
| **Stratégie de port**     | **abandon** (capacité résiduelle = lien Firefox, déjà refondu Story 4.8)                                                                |
| **Story Epic 16 cible**   | hors Epic 16                                                                                                                            |
| **Risques / pièges**      | Aucun.                                                                                                                                  |

#### `gpo/veyon_out.php` (141 lignes)

| Champ                     | Valeur                                                                                                                                                          |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/gpo/veyon_out.php` — 141 lignes                                                                                   |
| **Rôle**                  | Endpoint JSON qui génère la config Veyon pour un poste (LDAP binds, AccessControl, ServerHost…). Crée un compte service `read.user` au passage si absent.       |
| **Endpoints HTTP**        | GET/POST `/gpo/veyon_out.php?id=…&licence=…`                                                                                                                    |
| **Inputs**                | POST/GET `id` (apcu key machine), `licence` (1 = retourne `licence.vlf`)                                                                                        |
| **Outputs**               | JSON (config Veyon) + side effect : création AD du compte `read.user` + ldap_pass crypté openssl                                                                |
| **Dépendances système**   | LDAP shim (`search_ad`, `search_parcs`, `create_ad_user`), `usersetpassword`, `set_config`, `openssl_public_encrypt`                                            |
| **Statut shim 1bis.18**   | Oui — `legacy/modules/gpo/veyon_out.php` (1bis-18e)                                                                                                             |
| **Statut native**         | Non refondu                                                                                                                                                     |
| **Stratégie de port**     | **réécriture** — endpoint stateless + service Veyon dédié. Side-effect création compte AD à isoler proprement.                                                  |
| **Story Epic 16 cible**   | **16.3** (sections spécialisées — Veyon)                                                                                                                        |
| **Risques / pièges**      | (1) Création de compte AD silencieuse au premier appel — risque race condition multi-appels concurrents. (2) `openssl_public_encrypt(PKCS1_OAEP)` — préserver le format binhex consommé par Veyon. (3) Le fichier `licence.vlf` est servi en clair sans auth particulière — vérifier exposition. |

#### `gpo/wine.php` (79 lignes)

| Champ                     | Valeur                                                                                                                                                          |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/gpo/wine.php` — 79 lignes                                                                                         |
| **Rôle**                  | UI admin Wine : génère l'image partagée + raccourcis depuis le profil `se4install` (compte de référence).                                                       |
| **Endpoints HTTP**        | GET/POST `/gpo/wine.php`                                                                                                                                        |
| **Inputs**                | POST `action` (= `Générer l'image` ou `Générer les raccourcis`), `application` (containeur wine)                                                                |
| **Outputs**               | HTML form + batch (`batch_command`)                                                                                                                             |
| **Dépendances système**   | `batch_command("/usr/share/sambaedu/scripts/make_wine_image.sh")`, `shortcuts.inc.php:get_wine_shortcuts`                                                       |
| **Statut shim 1bis.18**   | Oui — `legacy/modules/gpo/wine.php` (1bis-18e)                                                                                                                  |
| **Statut native**         | Non refondu                                                                                                                                                     |
| **Stratégie de port**     | **réécriture** : action batch (génération d'image Wine) à confier à un Job queue Laravel ; raccourcis à factoriser dans `ShortcutsService`.                     |
| **Story Epic 16 cible**   | **16.3** (sections spécialisées — Wine)                                                                                                                         |
| **Risques / pièges**      | (1) `batch_command()` exécute un shell script — vecteur d'injection si `$application` non échappé (le code legacy passe la variable telle quelle). (2) Bug subtil ligne 52 : `if ($application = $select_application)` assignment au lieu de comparaison. À ne PAS reproduire en natif. |

#### `gpo/network_out.php` (54 lignes)

| Champ                     | Valeur                                                                                                                                  |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/gpo/network_out.php` — 54 lignes                                                         |
| **Rôle**                  | Endpoint script logon/startup qui génère un script bash/cmd de config réseau (proxy GNOME, etc.) pour un poste Linux/Windows.           |
| **Endpoints HTTP**        | GET/POST `/gpo/network_out.php?action=startup|logon&os=…&id=…`                                                                          |
| **Inputs**                | GET/POST `action`, `os`, `id`                                                                                                           |
| **Outputs**               | `text/plain` script                                                                                                                     |
| **Dépendances système**   | `network.inc.php:network_create_script/system_proxy/gnome_proxy`                                                                        |
| **Statut shim 1bis.18**   | Oui — `legacy/modules/gpo/network_out.php` (1bis-18e)                                                                                   |
| **Statut native**         | Non refondu                                                                                                                             |
| **Stratégie de port**     | **réécriture** — endpoint stateless, à porter via Controller + service `NetworkScriptGenerator`                                         |
| **Story Epic 16 cible**   | **16.3** (sections spécialisées — Network proxy — section absente du cadrage actuel)                                                    |
| **Risques / pièges**      | (1) Switch limité aux OS `linux` — windows ignoré ? À vérifier. (2) `$id` consommé sans validation visible — bien valider en natif.     |

### 6.A.2 — Fichiers includes (`sambaedu/includes/*.inc.php`)

#### `includes/gpo.inc.php` (1423 lignes, ~45 fonctions)

| Champ                     | Valeur                                                                                                                                                          |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/includes/gpo.inc.php` — 1423 lignes                                                                               |
| **Rôle**                  | Cœur fonctionnel GPO côté legacy. Lecture/écriture `.pol` (encodage UTF-16), import/export GPO (zip), gestion SYSVOL via smbclient, helpers SID/wbinfo, génération `fdeploy.ini` etc. Composants : `read_pol/write_pol/get_pol_key/change_pol_key`, `import_gpo/export_gpo/delete_gpo`, `read_gpo_sysvol/update_gpo_sysvol/sysvol_put`, `increment_gpo/increment_gpo_sysvol`, `get_sid_from_name/get_name_from_sid/get_domain_sid`. |
| **Endpoints HTTP**        | (include — pas d'endpoint direct)                                                                                                                               |
| **Inputs**                | `$config` (config bridge), paths SYSVOL                                                                                                                         |
| **Outputs**               | Side effects : fichiers SYSVOL, `/etc/sambaedu/applications/gpos.json`, `/var/www/sambaedu/temp/policies`, `/var/www/sambaedu/tmp/`                             |
| **Dépendances système**   | `exec("wbinfo …")` (3x), `exec("smbclient …")` (~9x), `exec("rm -fr …")` (3x), `exec("mkdir …")` (1x), fonctions `samba-tool.inc.php` (`gpocreate`, `gpodel`, etc.), shim LDAP |
| **Statut shim 1bis.18**   | Oui — chargé dans `legacy/bootstrap.php` (1bis-18a)                                                                                                              |
| **Statut native**         | Partiellement utilisé via `GpoSyncService` (computer.elevate)                                                                                                   |
| **Stratégie de port**     | **réécriture totale** — décomposer en services Laravel ciblés : `PolFileReader/Writer` (encodage UTF-16), `SysvolService` (smbclient/smbcacls), `GpoImportService`, `GpoExportService`, `SidResolver` (wbinfo). Pas de port byte-identique. |
| **Story Epic 16 cible**   | **16.3** (read/write sections) + **16.4** (CRUD complet)                                                                                                        |
| **Risques / pièges**      | (1) **30+ constantes top-level `define()`** — ne pas dupliquer côté natif, redéfinir comme constantes de classe / enums. (2) **Encodage UTF-16LE** dans `.pol` — réutiliser la logique `read_pol`/`write_pol` (helper potentiellement `@legacy-port`). (3) **`exec("smbclient … " . escapeshellarg($command))` mais `$command` lui-même contient `$tmppath` non echappé** — vérifier au cas par cas. (4) `recursive_copy`/`zip_gpo`/`unzip_gpo` utilisent des paths concatenés. (5) `import_gpo`/`export_gpo` ont logique métier dense (versioning major.minor, increment, links) — risque de régression élevé. |

#### `includes/samba-tool.inc.php` (1396 lignes, ~50 fonctions)

| Champ                     | Valeur                                                                                                                                                          |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/includes/samba-tool.inc.php` — 1396 lignes                                                                        |
| **Rôle**                  | Wrapper `exec("/usr/bin/samba-tool ...")` centralisé + 50 fonctions métier (CRUD users / groups / OUs / GPOs / DNS). Fonction cœur : `sambatool($config, $command, &$message)`. |
| **Endpoints HTTP**        | (include — pas d'endpoint direct)                                                                                                                               |
| **Inputs**                | `$config`                                                                                                                                                       |
| **Outputs**               | Side effects : tout l'AD                                                                                                                                        |
| **Dépendances système**   | `exec("/usr/bin/samba-tool …")` partout, LDAP shim                                                                                                              |
| **Statut shim 1bis.18**   | Oui — chargé dans `legacy/bootstrap.php` (1bis-18a)                                                                                                              |
| **Statut native**         | Partiellement (la délégation passe par `function_exists('add_delegation_salle')`)                                                                               |
| **Stratégie de port**     | **réécriture** sous forme de `SambaToolRunner` (Story 16.1, déjà fait pour les sous-commandes `gpo`) + services métier `UserService`/`GroupService`/`OuService` — déjà partiellement couverts (Epic 2 users, Story 5.x groups). |
| **Story Epic 16 cible**   | **16.1** (runner pour `gpo *`), **16.4** (services GPO CRUD), hors Epic 16 pour users/groups (déjà couvert par Epics 2, 5)                                      |
| **Risques / pièges**      | (1) **Ligne 54 : `exec("/usr/bin/samba-tool " . $command . $kerb_option …)`** — `$command` est string concat non échappée. C'est LE vecteur d'injection critique. Mitigé par le mode array de `SambaToolRunner` natif. (2) `dns_add`, `dns_delete`, `dns_wpad` parsent des sorties — fragiles. (3) `replicate_ad` : commande de réplication multi-DC — hors scope Epic 16 ? |

#### `includes/delegations.inc.php` (373 lignes, 7 fonctions)

| Champ                     | Valeur                                                                                                                                                          |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/includes/delegations.inc.php` — 373 lignes                                                                        |
| **Rôle**                  | Gestion des délégations admin sur OU/salles : associe une GPO à une OU + ajoute des membres à un groupe pour l'élévation locale. Fonctions : `add_delegation_salle`, `remove_delegation_salle`, `list_delegation_salles`, etc. |
| **Endpoints HTTP**        | (include)                                                                                                                                                       |
| **Inputs**                | `$config`, `$delegation` (login), `$salle` (groupe physique)                                                                                                    |
| **Outputs**               | AD writes : OU, groupes, GPO links                                                                                                                              |
| **Dépendances système**   | `samba-tool gpo create`, `gpo setlink`, `gpo dellink`, `gpo listcontainers`, `gpo getlink`, `groupaddmember`, `groupdelmember`, fonctions `gpo.inc.php` (`read_gpo_sysvol`, `increment_gpo`), LDAP (`search_ad`, `modify_ad`, `ldap_add`, `ldap_delete`), `get_sid_from_name`, `guid` (de `printers.inc.php`!) |
| **Statut shim 1bis.18**   | Oui — chargé dans `legacy/bootstrap.php` (1bis-18a)                                                                                                              |
| **Statut native**         | Utilisé par `GpoSyncService` (computer.elevate) qui le wrappe via `function_exists`                                                                             |
| **Stratégie de port**     | **port + adaptation** dans `App\Gpo\Services\DelegationService` (à créer) — la logique métier (groupage AD + GPO setlink) doit être préservée.                  |
| **Story Epic 16 cible**   | **16.4** (création GPO via délégation) + **16.5** (liaison GPO ↔ OU)                                                                                            |
| **Risques / pièges**      | (1) Couplage à `guid()` (printers.inc.php) — dépendance cross-module. (2) `$config['bind']` partagé — refactoriser pour passer une connexion typée. (3) Comportement implicite : ajoute des members à des groupes existants par naming convention (`<salle>_admins`) — à documenter. |

#### `includes/gpo_ui.inc.php` (76 lignes, 3 fonctions)

| Champ                     | Valeur                                                                                                                                                          |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Fichier source**        | `/home/htouchard/code/irundo/codebase/sambaedu/includes/gpo_ui.inc.php` — 76 lignes                                                                              |
| **Rôle**                  | Helpers UI HTML : `gpo_form_no_roam` (form exclusions), `table_roam_stats` (tableau stats), `table_roam_stats_user` (drill-down).                               |
| **Endpoints HTTP**        | (include)                                                                                                                                                       |
| **Inputs**                | `$config`, valeurs GPO                                                                                                                                          |
| **Outputs**               | HTML inline                                                                                                                                                     |
| **Dépendances système**   | `partages.inc.php:roaming_profiles_stats` (lit `/tmp/du.txt`)                                                                                                   |
| **Statut shim 1bis.18**   | Oui — chargé dans `legacy/bootstrap.php` (1bis-18a)                                                                                                              |
| **Statut native**         | **Refondu Story 1bis.18f** (Livewire component `RoamingProfilesTab`)                                                                                            |
| **Stratégie de port**     | **abandon** — l'UI native Livewire remplace ces helpers HTML inline.                                                                                            |
| **Story Epic 16 cible**   | hors Epic 16                                                                                                                                                    |
| **Risques / pièges**      | Le fichier reste chargé tant que le shim 1bis.18 vit — pas de risque d'effet de bord, mais à retirer du bootstrap lors de la dépose finale.                     |

---

## Section 6.B — Catalogue commandes `samba-tool gpo *`

Liste exhaustive des wrappers `samba-tool gpo` définis dans `samba-tool.inc.php`
lignes 923-1088 :

| Commande exacte                            | Fonction wrapper                     | Paramètres                                  | Output attendu                              | Couverture `GpoService` (AC3.1) | Commentaire                                                                |
|--------------------------------------------|--------------------------------------|---------------------------------------------|---------------------------------------------|---------------------------------|----------------------------------------------------------------------------|
| `samba-tool gpo listall`                   | (n/a — pas wrappé explicitement)     | aucun                                       | bloc texte multi-GPO                        | ✅ `list()`                     | Wrapper natif ; legacy passe par `gpogetlink($config, $base_dn)`.          |
| `samba-tool gpo list <cn>`                 | `gpolist($config, $cn)`              | `cn` user/machine                           | liste `[uuid, displayname]`                 | ✅ couvert par `getLinks()` (sémantique différente — voir note) | Le legacy l'utilise pour lister les GPO appliquées à un user/machine.       |
| `samba-tool gpo show <gpo>`                | (implicite — utilisé dans tests legacy mais pas wrappé) | `gpo` GUID                                  | bloc clé:valeur                             | ✅ `get()`                      | Le legacy lit plutôt directement `search_ad($config, $name, "gpo")` via LDAP. |
| `samba-tool gpo create <displayname>`      | `gpocreate($config, $displayname, &$msg)` | `displayname` string                        | GUID dans stdout (regex `{...}`)            | ✅ `create()` stub             | Implémentation effective : Story 16.4.                                     |
| `samba-tool gpo del <gpo>`                 | `gpodel($config, $gpo)`              | `gpo` GUID                                  | exit 0 (ou 255 accepté)                     | ✅ `delete()` stub             | Story 16.4.                                                                |
| `samba-tool gpo listcontainers <gpo>`      | `gpolistcontainers($config, $gpo)`   | `gpo` GUID                                  | lignes `dn: ...`                            | ✅ `listContainers()`           |                                                                            |
| `samba-tool gpo getlink <container>`       | `gpogetlink($config, $container)`    | `container` DN                              | blocs `GPO/Name/Options`                    | ✅ `getLinks()`                 |                                                                            |
| `samba-tool gpo setlink <container> <gpo> [--enforce] [--disable]` | `gposetlink($config, $container, $gpo, $enforce, $disable)` | container DN + gpo GUID + flags             | exit 0                                       | ✅ `setLink()` stub            | Story 16.5.                                                                |
| `samba-tool gpo dellink <container> <gpo>` | `gpodellink($config, $container, $gpo)` | container DN + gpo GUID                     | exit 0 ou 255                                | ✅ `removeLink()` stub         | Story 16.5.                                                                |
| `samba-tool gpo getinheritance <container>`| `gpogetinheritance($config, $container)` | container DN                                | string contenant `GPO_INHERIT` ou `GPO_BLOCK_INHERITANCE` | ✅ `getInheritance()`           |                                                                            |
| `samba-tool gpo setinheritance <container> [inherit|block]` | `gposetinheritance($config, $container, $flag)` | container DN + flag                         | exit 0                                       | ✅ `setInheritance()` stub     | Story 16.5. **Bug legacy ligne 1027-1030** : `$message .= " inherit"` au lieu de `$command .= " inherit"`. À NE PAS reproduire — implémenter proprement avec arg array. |
| `samba-tool gpo fetch <gpo> --tmpdir=<dir>`| `gpofetch($config, $gpo, $dir)`      | `gpo` GUID + `dir`                          | exit 0, fichiers déposés dans `$dir`         | ✅ `fetch()` stub              | Story 16.3 / 16.4.                                                         |

### Wrappers additionnels (non `samba-tool gpo` mais utilisés par le module)

| Commande                                   | Fonction wrapper                     | Story Epic 16 cible                       |
|--------------------------------------------|--------------------------------------|-------------------------------------------|
| `samba-tool dsacl set <dn> <sddl>`         | `dsacl_set($config, $dn, $sddl)`     | hors Epic 16 (utilisé pour delegations.inc.php) |
| `samba-tool dsacl get <dn>`                | `dsacl_get($config, $dn)`            | hors Epic 16                              |

### Commandes manquantes éventuellement nécessaires

| Commande                                   | Pourquoi pertinente                                                            | Story future éventuelle |
|--------------------------------------------|--------------------------------------------------------------------------------|-------------------------|
| `samba-tool gpo backup <gpo> --tmpdir=…`   | Backup d'une GPO entière (alternative à `fetch`) — non utilisé par le legacy   | 16.4 (corbeille)        |
| `samba-tool gpo restore <name> --tmpdir=…` | Restauration d'un backup                                                       | 16.4 (corbeille)        |
| `samba-tool gpo aclcheck`                  | Audit des ACLs SYSVOL                                                          | hors scope Epic 16      |
| `samba-tool gpo manage … (admx, sections)` | Manipulation des fichiers ADMX templates                                       | hors scope Epic 16      |

---

## Section 6.C — Mapping sections spécialisées → composants Laravel cibles

> Tableau revu et corrigé sur la base de l'audit. **Le cadrage initial Epic 16
> mentionne 7 sections ; l'audit confirme qu'il y en a 9.**

| #  | Section          | Fichier(s) legacy                                            | Statut actuel                                          | Stratégie portage                                          | Story cible (recommandation)    |
|----|------------------|--------------------------------------------------------------|--------------------------------------------------------|------------------------------------------------------------|---------------------------------|
| 1  | Firefox          | `gpo/firefox.php`, `gpo/firefox_out.php`, `firefox.inc.php`  | Refondu Story 4.8 (`AppCustomization`)                 | **hors scope** OU exposer dans page GPO native             | À trancher en 16.3              |
| 2  | Thunderbird      | `gpo/thunderbird_out.php`                                    | Refondu Story 4.8 (`AppCustomization`)                 | **hors scope** OU exposer dans page GPO native             | À trancher en 16.3              |
| 3  | Wallpaper        | `gpo/wallpaper.php`, `gpo/wallpaper_out.php`, `wallpaper.inc.php` | Refondu Story 4.7                                     | **hors scope** (déjà fait)                                 | (n/a)                           |
| 4  | Raccourcis       | `gpo/shortcuts.php`, `gpo/shortcuts_out.php`                 | Refondu (`ShortcutsService`, `ShortcutExportController`) | **hors scope** (déjà fait)                                 | (n/a)                           |
| 5  | Veyon            | `gpo/veyon_out.php`                                          | Shimé 1bis-18e (byte-identique)                        | **réécriture**                                             | **16.3** (sous-story dédiée)    |
| 6  | Wine             | `gpo/wine.php`                                               | Shimé 1bis-18e                                         | **réécriture**                                             | **16.3** (sous-story dédiée)    |
| 7  | Associations apps| `gpo/applications.php`, `gpo/associations_out.php`           | Shimé 1bis-18e                                         | **réécriture** (couplage WPKG — à clarifier scope Epic 15/16) | **16.3** OU déplacer en Epic 15 |
| 8  | Network (proxy)  | `gpo/network_out.php`, `network.inc.php`                     | Shimé 1bis-18e                                         | **réécriture** — section ajoutée par l'audit (manquait dans cadrage initial) | **16.3** (sous-story dédiée)    |
| 9  | Roaming profiles | `gpo/no_roam.php`, `gpo/del_roam.php`, `gpo/user_profile_stats.php` | Refondu Story 1bis-18f (`RoamingProfileService`)       | **adapter** — exposer dans page GPO native (lien depuis `/app/gpo` vers `/admin/settings?tab=profils-itinerants`) | **16.2** (lien dans page index)  |

### Sections déjà-natives — décision d'exposition

Pour les sections **1, 2, 3, 4, 9** (déjà natives), Story 16.2 / 16.3 doivent
décider si la page GPO native expose des liens vers les UIs existantes
(`AppCustomization`, wallpaper management, shortcuts, `RoamingProfileService`)
ou si elle reste centrée sur la lecture pure GPO/AD. Recommandation : **liens
profonds vers les pages existantes** pour conserver une vue unifiée admin sans
duplication d'UI.

---

## Section 6.D — Contour du shim 1bis.18 (frontière transition)

### Pages legacy encore servies via le shim (8 fichiers)

Pages présentes dans `legacy/modules/gpo/` (1bis-18b/e), donc consommées par
le catchall :

1. `gestion_gpo.php` — accueil module
2. `gpo-maj.php` — import GPO
3. `gpo-export.php` — export GPO
4. `applications.php` — scripts startup/logon apps
5. `associations_out.php` — endpoint associations
6. `veyon_out.php` — endpoint Veyon
7. `wine.php` — UI Wine
8. `network_out.php` — endpoint network

### Pages legacy déjà refondues / interceptées avant le shim

- `no_roam.php` — redirigé vers `/admin/settings?tab=profils-itinerants` (1bis-18f)
- `del_roam.php` — redirigé vers `/admin/gpo/del-roam.sh` (1bis-18f)
- `user_profile_stats.php` — redirigé vers `/admin/settings` (1bis-18f)
- `firefox.php`, `firefox_out.php`, `thunderbird_out.php` — annulés (Story 4.8)
- `wallpaper.php`, `wallpaper_out.php` — annulés (Story 4.7)
- `shortcuts.php`, `shortcuts_out.php` — bloqués/redirigés vers `app/shortcuts`
  via `config('sambaedu.blocked_legacy_routes')`
- `gestion_apps.php` — page résiduelle, peut être bloquée en ajoutant
  `gpo/gestion_apps\.php` à `blocked_legacy_routes`

### Stratégie de désactivation progressive

Au fur et à mesure des stories 16.x, chaque page native ajoutée doit :

1. **Ajouter une entrée dans `config('sambaedu.blocked_legacy_routes')`**
   (pattern regex → URL native) OU
2. **Cohabiter** : la page legacy reste accessible via une URL distincte de
   la page native (ex. `/gpo/foo.php` legacy + `/app/gpo/foo` native — utile
   en phase de comparaison / non-régression).

**Décision SM par défaut (D6)** : le shim 1bis.18 reste actif pendant tout
Epic 16. Pas de désactivation atomique.

### Action recommandée — Story 16.2

Story 16.2 doit ajouter au moins :

- Bloquer `^gpo/gestion_gpo\.php$` (page d'accueil legacy) → `/app/gpo`
- Conserver `gpo-maj.php`, `gpo-export.php` en cohabitation jusqu'à Story 16.4.

---

## Section 6.E — Capacités legacy *non revendiquées* par Epic 16

| #  | Fonction / fichier                                       | Description                                                                                                         | Recommandation                                                                       |
|----|----------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------|
| 1  | `gpo.inc.php:list_gpo_templates_git()`                   | Téléchargement et listing des templates GPO depuis le dépôt Git `sambaedu-gpo-templates` (packagé `apt`).            | **Ajouter à Story 16.4** (CRUD complet doit inclure l'import depuis templates).      |
| 2  | `gpo.inc.php:check_gpo_templates()`                      | Vérification de présence des templates installés via apt.                                                            | À porter dans le service `GpoImportService` (16.4).                                  |
| 3  | `gpo.inc.php:generalise_gpo()` / `specialise_gpo()`      | Normalisation des SIDs/UAI dans une GPO exportée (pour réimport sur un autre établissement).                        | **Important pour la mutualisation établissement** — à porter en 16.4.                |
| 4  | `gpo.inc.php:set_proxy_gpo()` (legacy)                   | Mise à jour automatique du proxy système dans la GPO "proxy" depuis la config.                                       | Lié à la section Network (16.3) — peut être abandonné si on a une UI dédiée.         |
| 5  | `samba-tool.inc.php:replicate_ad()`                      | Réplication AD entre DCs (`samba-tool drs replicate`).                                                              | **Hors scope Epic 16** — couvert par les ops système, pas par l'UI SER.              |
| 6  | `samba-tool.inc.php:check_ad_replication_errors()`       | Audit de l'état de réplication AD.                                                                                  | **Hors scope Epic 16** — éventuellement dashboard monitoring séparé.                 |
| 7  | `samba-tool.inc.php:dns_add()`/`dns_delete()`/`dns_wpad()` | Gestion DNS via `samba-tool dns` (lié au DC Samba).                                                                 | **Hors scope Epic 16** — éventuellement Story dédiée (Epic DNS si besoin).            |
| 8  | `delegations.inc.php` toutes fonctions                   | Délégations admin par OU (création de GPOs ad-hoc qui élèvent un user au groupe Administrateurs locaux).            | **Périmètre 16.4 / 16.5** — replier `GpoSyncService` ici.                            |
| 9  | `gpo.inc.php:read_gpo_json()` / `write_gpo_json()`       | Cache JSON `/etc/sambaedu/applications/gpos.json` qui mémorise la version dernière importée + patches locaux.       | **À porter en 16.4** (cache local versioning, équivalent en table Eloquent ou fichier JSON natif). |
| 10 | `gpo.inc.php:list_gpo_templates_etab()`                  | Listing des GPO templates locaux établissement (`etab_*.zip`).                                                       | À porter en 16.4 (import depuis archive).                                            |
| 11 | `gpo.inc.php:get_gpo_template_info()` / `unzip_gpo()` / `zip_gpo()` | Manipulation des archives `.zip` de GPO templates.                                                                  | À porter en 16.4 (utiliser `ZipArchive` PHP natif).                                  |

### Synthèse

- **8 capacités** doivent être absorbées par Epic 16 (16.3 ou 16.4).
- **3 capacités** sont **hors scope Epic 16** (réplication AD, DNS, ACLs SYSVOL).
- **0 abandon recommandé** — toutes ont une valeur résiduelle.

> Recommandation : **clarifier avec Henri** si les fonctions DNS et de
> réplication AD doivent être portées dans une Epic dédiée (Epic DNS ?
> Epic Ops monitoring ?). Sinon elles resteront legacy à perpétuité, c'est
> acceptable mais à acter.

---

## Section 6.F — Risques sécurité hérités

| #  | Localisation                                       | Vecteur                                                                                          | Sévérité  | Recommandation                                                                                      |
|----|----------------------------------------------------|--------------------------------------------------------------------------------------------------|-----------|-----------------------------------------------------------------------------------------------------|
| F1 | `samba-tool.inc.php:54` `sambatool()`              | `exec("/usr/bin/samba-tool " . $command . $kerb_option …)` — `$command` est string concat non échappée par le wrapper, chaque appelant doit échapper. | **Critique** | **Mitigé dès Story 16.1** par `SambaToolRunner` en mode array (`Process::run([...])`). Test archi `GpoNamespaceTest` interdit `exec()` direct dans `App\Gpo\*` hors runner. Pour le legacy, accepter le risque (admin-only, derrière auth). |
| F2 | `gpo.inc.php:177` `get_domain_sid()`               | `exec("wbinfo --name-to-sid " . $user)` — `$user` non échappé.                                  | Modéré    | **À corriger** lors du portage (Story 16.3/16.4) via un `WbinfoRunner` sibling de `SambaToolRunner`. |
| F3 | `gpo.inc.php:183` `get_sid_from_name()`            | `exec("wbinfo --name-to-sid \"" . $name . "\"")` — guillemets bash mais pas `escapeshellarg`.   | Modéré    | Idem F2.                                                                                            |
| F4 | `gpo.inc.php:196` `get_name_from_sid()`            | `exec("wbinfo --sid-to-name " . $sid)` — `$sid` non échappé (mais issu d'AD, donc contrôlé).    | Faible    | Idem F2 par cohérence.                                                                              |
| F5 | `gpo.inc.php:1097` `export_gpo` smbclient          | `exec('smbclient "//' . ad_url($config, "dns", true) . '/sysvol" … -c ' . escapeshellarg($command))` — la commande interne `$command` contient `$tmppath` non échappé. | Modéré    | À corriger en Story 16.4 (réécriture de `export_gpo` en `GpoExportService`).                        |
| F6 | `gpo.inc.php:1241` `sysvol_acl_reset` smbcacls     | `$path` non échappé dans la commande smbcacls.                                                  | Modéré    | À corriger en Story 16.4.                                                                           |
| F7 | `gpo/wine.php:61` `batch_command()`                | `batch_command("/usr/share/sambaedu/scripts/make_wine_image.sh " . $application)` — `$application` issu de `$_POST` (whitelist par listing mais pas validé strictement). | Élevé     | **À corriger** lors du portage Story 16.3 (Wine) — valider strictement contre whitelist regex `^[a-zA-Z0-9._-]+$`. |
| F8 | `gpo/gpo-maj.php:67` `sudo apt update && apt install` | Commande sudo absolue, mais `sudo` doit être configuré sans password — risque de privilege escalation si sudoers mal configuré. | Modéré    | **À supprimer** en Story 16.4 — l'install de templates doit passer par un pipeline ops (apt sur la VM), pas exécuté depuis le web.   |
| F9 | `gpo/del_roam.php` `$value` GPO → `rm -fr`         | Valeur lue depuis SYSVOL injectée dans `rm -fr "/home/profiles/${username}/<value>"`. Si SYSVOL compromis → RCE poste. | **Critique (mais 1bis.18f mitige)** | Déjà couvert par défense Story 1bis.18f (regex anti path-traversal côté lecture + écriture). Conserver QA 1bis.18f-11/12. |
| F10 | `gpo/veyon_out.php:43` `create_ad_user`           | Création silencieuse d'un compte AD `read.user` au premier appel non auth (juste un POST sans auth admin visible).   | Faible    | À vérifier en Story 16.3 (Veyon) — confirmer que l'endpoint est bien derrière auth admin / API token.   |

### Conclusion sécurité

- Les vecteurs critiques (F1, F9) sont **soit mitigés Story 16.1** (`SambaToolRunner`),
  **soit Story 1bis.18f** (filtrage `del-roam.sh`).
- Les vecteurs modérés (F2-F8) seront corrigés au fur et à mesure des
  portages Story 16.3 / 16.4 (le code natif ne reproduira PAS les concaténations
  shell).
- Aucun port `@legacy-port byte-identique` des fonctions exec n'est recommandé
  — toutes doivent être réécrites avec `Process::run([...])` en mode array.

---

## Section 6.G — Conclusion & recommandations de découpage

### Découpage proposé pour les stories 16.2 → 16.6

#### Story 16.2 — Listing / lecture GPO + UI native

- Page `/app/gpo` (Livewire) — listing des GPOs (`GpoService::list()`)
- Détail GPO `/app/gpo/{name}` — `GpoService::get()`, `listContainers()`, `getLinks()`, `getInheritance()`
- Bloquer `gpo/gestion_gpo.php` (catchall override)
- Ajouter liens profonds vers UIs natives existantes : `/admin/settings?tab=profils-itinerants`, `/app/shortcuts`, `/app/parc-settings/wallpapers`, `/app/parc-settings/customizations`
- **PAS de mutations** — pure lecture.

**Charge estimée** : 1-2 jours dev + 0.5 jour QA. Pas de refactoring lourd.

#### Story 16.3 — Édition de sections spécialisées (**à splitter en 3 sous-stories**)

L'audit révèle que les 7 sections du cadrage initial sont en réalité 9, et
qu'elles ne se valent pas en complexité. **Recommandation forte : splitter
en 3 sous-stories**.

##### Story 16.3a — Sections déjà-natives à exposer (basse complexité)

- Liens profonds depuis `/app/gpo` vers les sections natives (Firefox/Thunderbird via AppCustomization, Wallpapers via Story 4.7, Shortcuts via ShortcutsService, Roaming via RoamingProfileService).
- Pas de nouveau code métier, juste de la navigation + breadcrumbs.

**Charge estimée** : 1 jour.

##### Story 16.3b — Section Network proxy + Veyon (complexité moyenne)

- Réécriture native de `/gpo/network_out.php` et `/gpo/veyon_out.php` en Controllers Laravel + services.
- Bloquer les URLs legacy correspondantes via catchall.
- Tests d'intégration : un poste Veyon réel consomme bien la config générée.

**Charge estimée** : 2-3 jours.

##### Story 16.3c — Section Wine + Associations apps + Apps scripts (haute complexité)

- Wine : génération d'image via Job queue (pas en sync depuis le web).
- Associations apps : couplage fort WPKG (Story 15.x) — à arbitrer si on
  consolide dans Epic 15 ou Epic 16.
- `/gpo/applications.php` (scripts startup/logon) : endpoint stateless, ok.

**Charge estimée** : 3-4 jours.

#### Story 16.4 — CRUD GPO + import/export + corbeille

Reste essentiellement comme prévu, mais l'audit montre que **le scope est plus
large que le cadrage initial** :

- `create` / `delete` (implémentation des stubs Story 16.1)
- `import_gpo` / `export_gpo` (templates Git + locaux, zip, generalise/specialise SIDs)
- Corbeille avec restauration paramétrable (backup avant delete)
- Cache local versioning (`read_gpo_json`/`write_gpo_json` natifs)
- Replier `App\Services\GpoSyncService` (computer.elevate) ici
- **Réécriture des 6 fonctions `exec("smbclient …")`** avec runner dédié

**Charge estimée révisée** : 5-7 jours (vs estimation initiale plus courte).

#### Story 16.5 — Liaisons GPO ↔ OU/WorkstationGroup + propagation

- `setLink` / `removeLink` / `setInheritance` (implémentation des stubs)
- UI graphe d'impact (visualisation OU → GPOs)
- Ordre de précédence GPO (affichage)
- Replier les fonctions `delegations.inc.php` (`add_delegation_salle` etc.)

**Charge estimée** : 4-5 jours.

#### Story 16.6 — Intégration WPKG / décommissionnement shim

- Audit final des routes legacy encore servies, blocage final
- Décision sur les capacités hors scope (DNS, réplication AD) — story dédiée ou abandon
- Retrait du chargement des includes GPO du `legacy/bootstrap.php` (si plus d'appelants)

**Charge estimée** : 2 jours.

### Discrepances à arbitrer avec Henri (PO)

1. **Section Associations apps** : appartient-elle à Epic 15 (WPKG) ou Epic 16
   (GPO) ? Couplage fort au modèle WPKG.
2. **Fonctions DNS et réplication AD** : portées dans Epic 16 ou dédiées (Epic
   DNS / Epic Ops) ? Recommandation : laisser en legacy, Story 16.6 documente
   qu'elles restent legacy.
3. **Page `/app/gpo` vs `pages/windows-deploy/gpo/`** : trancher en 16.2.
   Recommandation forte : sous-page `windows-deploy/gpo/` cohérent avec Epic
   15 (`pages/windows-deploy/wpkg/`) et future Epic 17.
4. **Story 16.3 split** : valider le split en 16.3a/b/c — sinon Story 16.3
   devient hors taille raisonnable (~9 jours dev).
5. **Templates GPO Git** (`sambaedu-gpo-templates`) : conserver le pattern apt
   ou migrer vers un système de templates intégré Laravel ?

### Risques transversaux

- **Régression sur `computer.elevate`** : `GpoSyncService` legacy reste vivant.
  Tester systématiquement les Story 7.1/7.2 (délégations) après chaque story
  16.x qui touche au namespace `App\Gpo`.
- **SYSVOL R/W** : toute mutation GPO écrit dans SYSVOL via `smbclient`/`smbcacls`.
  Les tests E2E doivent inclure un volet vérification SYSVOL réel.
- **Encodage UTF-16 `.pol`** : la logique `read_pol`/`write_pol` est dense
  (~90 lignes) et fonctionne. Recommandation : la porter en `@legacy-port`
  (pas de réécriture from scratch — trop risqué pour faible valeur).

### Synthèse final

L'Epic 16 est **bien périmètré** mais le cadrage initial Story 16.3 est
**sous-évalué** (split en 3 recommandé). Les Stories 16.4 et 16.5 absorberont
des capacités legacy non explicitement listées dans `epics.md` (templates Git,
delegations, cache JSON). Les fondations posées par Story 16.1 (channel logs
`gpo`, namespace `App\Gpo`, `SambaToolRunner`, garde-fous archi) sont
**suffisantes** pour démarrer 16.2.

> **Validation requise** : Henri à valider ce document avant marquage Story
> 16.1 `review` (cf. AC6.1 + décision SM D7).

---

## Annexes

### A.1 — Volumétrie legacy GPO

| Fichier                             | Lignes  |
|-------------------------------------|---------|
| `sambaedu/gpo/applications.php`     | 51      |
| `sambaedu/gpo/associations_out.php` | 173     |
| `sambaedu/gpo/del_roam.php`         | 27      |
| `sambaedu/gpo/firefox_out.php`      | 16      |
| `sambaedu/gpo/firefox.php`          | 107     |
| `sambaedu/gpo/gestion_apps.php`     | 48      |
| `sambaedu/gpo/gestion_gpo.php`      | 69      |
| `sambaedu/gpo/gpo-export.php`       | 88      |
| `sambaedu/gpo/gpo-maj.php`          | 193     |
| `sambaedu/gpo/network_out.php`      | 54      |
| `sambaedu/gpo/no_roam.php`          | 65      |
| `sambaedu/gpo/shortcuts_out.php`    | 67      |
| `sambaedu/gpo/shortcuts.php`        | 33      |
| `sambaedu/gpo/thunderbird_out.php`  | 14      |
| `sambaedu/gpo/user_profile_stats.php` | 32    |
| `sambaedu/gpo/veyon_out.php`        | 141     |
| `sambaedu/gpo/wallpaper_out.php`    | 48      |
| `sambaedu/gpo/wallpaper.php`        | 46      |
| `sambaedu/gpo/wine.php`             | 79      |
| `sambaedu/includes/gpo.inc.php`     | 1423    |
| `sambaedu/includes/gpo_ui.inc.php`  | 76      |
| `sambaedu/includes/samba-tool.inc.php` | 1396 |
| `sambaedu/includes/delegations.inc.php` | 373 |
| **Total**                           | **4619** UI + includes |

> Note : le total annoncé dans la story (5447 lignes) inclut probablement
> aussi `firefox.inc.php`, `wallpaper.inc.php`, `shortcuts.inc.php`,
> `network.inc.php`, `applications.inc.php` (utilisés par les pages GPO mais
> hors scope car déjà-natifs ou hors module GPO). Le périmètre strictement
> module GPO = 4619 lignes.

### A.2 — Catalogue `action_type` Epic 16 (rappel)

Voir `app/Gpo/README.md` § Convention de logging Epic 16. 14 action types
référencés. Les 6 stubs `GpoService` couvrent : `gpo.create`, `gpo.delete`,
`gpo.fetch`, `gpo.link.set`, `gpo.link.remove`, `gpo.inheritance.set`. Les
4 méthodes lecture utilisent `gpo.list` et `gpo.show` (avec attribut `sub`
pour différencier `listcontainers`/`getlink`/`getinheritance`).
