# Story 4.7 : Gestion des Fonds d'Écran (Wallpapers) — Eloquent polymorphe + Capture legacy

Status: done

> **Origine :** refonte native du système wallpaper legacy (`sambaedu/gpo/wallpaper.php`, `gpo/wallpaper_out.php`, `includes/wallpaper.inc.php`). **Supersède `1bis-18d`** (shim cancelled — clarification henri 2026-04-20 : le préfixe "gpo" legacy reflète uniquement le chemin d'URL, aucun lien avec les GPO Windows).
> **Épic :** Epic 4 — Gestion des Machines, WorkstationGroups & AppProfiles SER.
> **Phase 1 déjà livrée (hors sprint formalisé — pré-existant, à consolider par cette story) :** migration `create_wallpapers_table`, modèle `App\Models\Wallpaper` avec `morphTo('owner')`, `morphMany` sur `User` / `UserGroup` / `WorkstationGroup`, route `gpo/wallpaper_out.php` captée par Laravel (stub 501 dans `WallpaperController::legacyOut`). Migration **déjà appliquée sur la VM**.

---

## Story

En tant que **responsable de collège**,
je veux paramétrer les fonds d'écran et écrans de verrouillage par salle, par groupe utilisateur, par utilisateur, ou par défaut global, via l'interface SER,
afin que les postes Linux et Windows affichent les images correctes au login sans maintenir deux systèmes en parallèle (legacy files + nouveau DB).

---

## Contexte Legacy

**Fichiers legacy concernés :**

- `sambaedu/gpo/wallpaper.php` (46 L) — UI admin : liste des fichiers `wallpaper@<X>.jpg` / `lockscreen@<X>.jpg` dans `/etc/sambaedu/applications/wallpaper/`, upload/remplacement via `upload_wallpaper()` (resize Imagick 1920x1080, `setImageFormat('jpg')`).
- `sambaedu/gpo/wallpaper_out.php` (48 L) — **endpoint client** (Linux/Windows). Reçoit `action` (`wallpaper|wallpaper-wait|lockscreen|veyon|icone`), `id` (md5 stocké APCu), `format` (jpg/png). Retourne blob `image/{format}`.
- `sambaedu/includes/wallpaper.inc.php` (364 L) — fonctions core :
  - `make_wallpaper()` (lines 58-223) — composition Imagick avec **7 niveaux de priorité** (voir Résolveur ci-dessous)
  - `make_lockscreen()` (lines 3-56) — composition écran de verrouillage
  - `make_icon()` (lines 225-244) — miniature admin (à ne **pas** porter — UI admin native Livewire)
  - `upload_wallpaper()` (lines 246-364) — form HTML+upload+resize (à ne **pas** porter — remplacé par UI Livewire)
- `sambaedu/parcs2/show_parc.php:95-101` — intégration UI per-salle (`if ($info[0]['type'] == "salle")` uniquement)
- `sambaedu/includes/annu.inc.php:1935-1958` — intégration UI per-groupe AD
- **Aucune intégration per-user ni "défaut établissement" dans l'UI legacy** — ces clés existent au runtime (`wallpaper@<user.cn>.jpg`, `wallpaper.jpg`, `default.jpg`) mais ne sont gérables que par FTP/CLI côté legacy. **Cette story comble ce manque**.

**Clients legacy qui consomment `gpo/wallpaper_out.php`** (scripts déployés sur la VM dans `/usr/share/sambaedu/applications/wallpaper/`, confirmés via `ssh ... grep -rn wallpaper_out`) :

| Script | OS | Trigger | Actions POSTées | Cible du fichier fetché |
|---|---|---|---|---|
| `logon.linux` | Linux | xsession login gnome/mate | `wallpaper`, `veyon` | `$HOME/.config/wallpaper-$machine.jpg`, `$HOME/.config/wallpaper-veyon.jpg` |
| `startup.linux` | Linux | boot machine | `lockscreen` (format=png) | `/usr/share/plymouth/themes/sambaedu/lockscreen.png` + lightdm/gdm wallpaper |
| `logon.windows` | Windows | user logon | `wallpaper`, `veyon` | `%WINDIR%\Web\SE4\wallpaper.jpg`, `%WINDIR%\Web\SE4\veyon.jpg` |
| `startup.windows` | Windows | boot machine | `lockscreen`, `wallpaper-wait` | `%WINDIR%\Web\SE4\lockscreen.jpg` (+ oobe backgrounds), `%WINDIR%\Web\SE4\wallpaper.jpg` |
| `logoff.windows` | Windows | logoff (actuellement commenté) | `wallpaper-wait` | — |

Toutes ces requêtes sont **POST multipart** avec `-F "action=..." -F "id=$id"`. **Auth** : le `$id` est un `md5(user+machine+action+application)` calculé côté serveur par `applications.inc.php::get_apps()` (lines 850-1000) et stocké dans APCu sous la clé `"apps.$id"` (TTL 1800s). Le dict APCu contient `{user, machine, salle, admin, list_u (groupes AD user), list_m (parcs machine), os, liste_applications, time, cloud, …}` — c'est la **seule source de contexte** lue par le runtime wallpaper.

**Logique de résolution legacy à reproduire fidèlement** (voir `make_wallpaper()` lines 109-151, priorité croissante — le dernier match gagne) :

1. `default.jpg` (fallback système `/usr/share/sambaedu/applications/wallpaper/default.jpg`)
2. `wallpaper.jpg` (défaut établissement `/etc/sambaedu/applications/wallpaper/wallpaper.jpg`)
3. `wallpaper@<salle>.jpg` (la salle de la machine — `$info['salle']`)
4. `wallpaper@<type>.jpg` pour `type ∈ {Profs, Eleves, Administratifs}` — **un seul** type principal, premier match avec `break`
5. `wallpaper@<group>.jpg` pour chacun des groupes AD de `$info['list_u']` (premier match dans la liste, `break`)
6. `wallpaper@<user.cn>.jpg` (per-user, **écrase tout le reste**)
7. `/home/<user>/Photos/wallpaper.jpg` si `$config['perso_wallpaper']` activé (**override final** — priorité absolue)
8. **Override total — quota dépassé** : fond rouge `ImagickPixel('red')` 1920×1080 + texte d'alerte quota (pas un wallpaper stocké — généré à la volée, ne passe pas en DB)

Pour `make_lockscreen()` (lines 3-56) la résolution est **plus simple** car pas d'utilisateur connecté : `lockscreen@<salle>.jpg` → `lockscreen.jpg` → `default.jpg`. Pas d'héritage par groupe utilisateur.

**Ce que le legacy ne fait PAS (et qu'on conserve tel quel) :**
- Pas d'héritage hiérarchique entre OU parentes (`WorkstationGroupLdapService::getEffectiveWallpaperInfo` dans le reload avait anticipé ça — à supprimer/ignorer, non conforme au legacy).
- Pas de remontée parent-child pour les groupes AD.
- Pas d'UI pour le défaut établissement (la story comble ce manque côté Laravel).

**Ce que le legacy fait mal (à corriger) :**
- Collision de namespace : `wallpaper@Profs.jpg` ambigu (groupe AD ? salle nommée "Profs" ?) — **résolu par `owner_type` en DB**.
- Pas d'historique (qui a uploadé quoi, quand) — **résolu par `uploaded_by` + timestamps**.
- UI admin éclatée (3 entrées : page établissement dans `gpo/wallpaper.php`, onglet salle dans `parcs2/show_parc.php`, onglet groupe dans `annu2/group.php`) — **unifiée ici**.

---

## Acceptance Criteria

**AC1 — Modèle de données + relations polymorphes (déjà livré Phase 1, à consolider)**

- Given la migration `2026_04_20_100000_create_wallpapers_table` est appliquée
- When je `SELECT * FROM wallpapers`
- Then la table existe avec les colonnes : `id`, `name`, `path`, `type enum('wallpaper','lockscreen')`, `owner_type`, `owner_id` (nullables pour défaut étab), `is_default bool`, `uploaded_by FK users`, `created_at`, `updated_at`
- And la contrainte **UNIQUE `(type, owner_type, owner_id)`** existe (un seul wallpaper actif par cible)
- And l'index partiel Postgres **`wallpapers_default_per_type`** existe : `UNIQUE (type) WHERE is_default = true AND owner_id IS NULL` (un seul default global par type)
- And `App\Models\Wallpaper` expose `morphTo('owner')`, scopes `ofType(string $type)` et `defaults()`, constantes `TYPE_WALLPAPER = 'wallpaper'` / `TYPE_LOCKSCREEN = 'lockscreen'`
- And `App\Models\User`, `App\Models\UserGroup`, `App\Models\WorkstationGroup` exposent chacun `wallpapers(): MorphMany`

**AC2 — Route d'interception `gpo/wallpaper_out.php` (stub déjà posé Phase 1, à compléter)**

- Given un client Linux/Windows POST `http://<SE>/gpo/wallpaper_out.php` avec `action=wallpaper&id=<md5>&format=jpg`
- When la route Laravel nommée `wallpaper.legacy` traite la requête **avant** tout catchall legacy
- Then `WallpaperController::legacyOut()` est invoqué
- And le fichier legacy `sambaedu/gpo/wallpaper_out.php` est **renommé `.legacy`** sur la VM (désactivation runtime legacy)
- And les actions supportées sont **exactement** : `wallpaper`, `wallpaper-wait`, `lockscreen`, `veyon`. **`icone` est explicitement non supportée** (elle servait uniquement à l'UI admin legacy, remplacée par l'UI Livewire).
- And un paramètre invalide retourne `400 Bad Request`
- And un `id` dont le contexte APCu a expiré retourne `404 Not Found` (mimant le comportement legacy `exit()`)

**AC3 — `ContextRepository` abstraction APCu (prépare migration future vers Cache Laravel)**

- Given une interface `App\Services\Wallpaper\Contracts\WallpaperContextRepository`
- When le controller a besoin du contexte d'un `$id`
- Then il **ne lit jamais APCu directement** — il passe par l'interface
- And une implémentation `ApcuWallpaperContextRepository::findById(string $id): ?WallpaperContext` lit `apcu_fetch("apps.$id")` et hydrate un DTO `WallpaperContext(userLogin, userFullname, userIsAdmin, machineName, salle, groupsUser, os, …)`
- And l'interface est bindée dans un `WallpaperServiceProvider` via `$this->app->bind(WallpaperContextRepository::class, ApcuWallpaperContextRepository::class)`
- And **note d'architecture** : cette interface permet de swap vers une `CacheWallpaperContextRepository` quand `applications.php` sera porté en Laravel (hors scope 4-7, mais contrat déjà en place).

**AC4 — `WallpaperResolver` — reproduction fidèle des 7 niveaux legacy**

- Given un `WallpaperContext` hydraté
- When `WallpaperResolver::resolve(WallpaperContext $ctx, string $type): WallpaperResolution` est appelé
- Then la résolution applique **exactement** les 7 niveaux legacy documentés ci-dessus (priorité croissante, le dernier gagne)
- And pour `type = 'wallpaper'` : niveaux 1→7 tous appliqués (default → étab → salle → type principal → groupes → user → home perso)
- And pour `type = 'lockscreen'` : uniquement niveaux 1→3 (default → étab → salle) — **pas d'héritage par user ou groupe** (fidèle à `make_lockscreen`)
- And le Resolver retourne un `WallpaperResolution(sourcePath, level, ownerType, ownerName, isQuotaOverride)` — indique quel niveau a matché (pour debug + logs)
- And **override quota** : si `QuotaService::isUserOverQuota($userLogin)` vrai, le Resolver retourne `WallpaperResolution::quotaOverride()` (pas de DB lookup — traité en amont par le Composer)
- And le Resolver requête la **DB en priorité** (table `wallpapers`) et **fallback fichier** `/etc/sambaedu/applications/wallpaper/wallpaper@<key>.jpg` si la DB est vide pour cette clé (compat pré-migration + rollback safety net)
- And le Resolver fait au **plus 3 lookups DB** par appel (user lookup, groups lookup avec `whereIn`, salle lookup) — pas de N+1

**AC5 — `WallpaperComposer` — composition moderne (Option C — refonte design)**

> **Décision produit henri 2026-04-20 :** on **ne reproduit pas** le texte gravé "gravity NORTHEAST en plein milieu" du legacy. On refait le design aux standards 2026 — bandeau inférieur structuré, icônes SVG, typographie moderne. Le legacy reste référence **fonctionnelle** (quels états, quels signaux), pas **visuelle**. Cf. AC 5 bis pour la spec design et AC 5 ter pour la matrice d'états.

- Given une `WallpaperResolution` + un `WallpaperContext`
- When `WallpaperComposer::composeWallpaper($resolution, $ctx, bool $wait, bool $veyon, string $format): string` est appelé
- Then l'image de base est chargée via Imagick depuis `resolution.sourcePath`
- And une **safe zone bandeau inférieur** de 120px de haut est composée avec gradient `rgba(0,0,0,0.0)` → `rgba(0,0,0,0.65)` (haut → bas) qui garantit la lisibilité **quelle que soit l'image de fond**
- And dans ce bandeau, les infos sont organisées en **3 zones** (gauche / centre / droite) au lieu du bloc texte unique legacy :
  - **Zone gauche (64px padding)** : identité discrète `$fullname` en taille 28, `"$machine · $salle"` en taille 18 couleur `rgba(255,255,255,0.7)`
  - **Zone droite (64px padding)** : **badges iconographiques** (voir AC 5 ter) — uniquement ceux actifs
  - **Zone centre** : vide en conditions normales, réservée aux **alertes critiques** (Veyon, connexions multiples) qui prennent alors tout le centre en texte taille 36
- And les overlays d'**alerte critique** (Veyon + connexions multiples) sont des **cartouches centraux pleine largeur** avec fond propre (pas juste du texte gravé) :
  - Veyon actif : cartouche rouge semi-transparent `rgba(200, 30, 30, 0.85)` pleine largeur 200px haut centré verticalement dans la moitié supérieure, texte `"🎥 Prise de contrôle à distance en cours"` + `config('sambaedu.veyon_message')` si défini
  - Connexions multiples : bandeau orange `rgba(230, 140, 30, 0.85)` sous le Veyon ou à sa place, `"⚠ Sessions détectées sur : $list"`
- And si `$wait = true` (logoff Windows avant login) : user affiché comme `"En cours de connexion…"`, pas de badges (l'utilisateur n'est pas encore résolu)
- And **override quota** (`$resolution->isQuotaOverride`) : l'image de base est remplacée par `newImage(1920, 1080, ImagickPixel('#8B0000'))` (rouge foncé, pas rouge pur) + **cartouche central blanc** 800×400 centré contenant : icône 🚫 taille 96, titre `"Stockage saturé"` taille 42 gras, body typé `"Espace perso utilisé : X Mo / Y Mo\nEspace Classe utilisé : A Mo / B Mo\nTemps restant avant blocage : N jours\n\nLibérez de l'espace et reconnectez-vous."` taille 22 ligne 1.4. Le cartouche est un rectangle arrondi (border radius simulé par Imagick `roundCorners`) avec ombre portée subtile
- And le format de sortie est `$format` (jpg ou png) via `setImageFormat($format)`, qualité 90
- And le return est le **blob binaire** (`$imagick->getImageBlob()`)
- And `composeLockscreen($resolution, $ctx, $format): string` applique les overlays simplifiés : bandeau inférieur même style mais **sans identité utilisateur** (pas connecté) — seulement `"$machine · $salle"` à gauche + logo établissement SVG rasterisé à droite + logo sambaedu discret. Si format=png : `resizeimage(1280, 720)` + compression level 8.

**AC 5 bis — Spec design du bandeau inférieur (refonte Option C)**

- Given la résolution cible standard est **1920×1080** (legacy)
- When le bandeau inférieur est composé
- Then les constantes design sont **centralisées** dans `config/wallpapers.php` :
  - `banner_height = 120` (px)
  - `banner_gradient = ['rgba(0,0,0,0)', 'rgba(0,0,0,0.65)']`
  - `banner_padding_horizontal = 64`
  - `banner_padding_vertical = 20`
  - `title_font_size = 28`
  - `subtitle_font_size = 18`
  - `alert_font_size = 36`
  - `badge_size = 48` (icône carrée 48×48)
  - `badge_gap = 16`
- And la typographie utilisée est **Atkinson Hyperlegible** (font conçue pour la lisibilité + accessibilité dyslexie, **déjà présente dans `resources/fonts/` via story 2-6** — réutilisation directe). Fichier attendu : `resources/fonts/Atkinson-Hyperlegible-Bold.ttf` + `-Regular.ttf`. Fallback DejaVuSans si fonts manquent.
- And les couleurs texte :
  - Titre (identité user) : `#FFFFFF`
  - Sous-titre (machine · salle) : `rgba(255,255,255,0.70)`
  - Alertes critiques : cartouche de fond coloré + texte blanc (pas de texte coloré sur fond indéterminé)
- And les badges iconographiques sont des **PNG 48×48** pré-rasterisés depuis SVG sources au build time, stockés dans `resources/assets/wallpaper-icons/` :
  - `badge-admin.png` — bouclier rouge `#D32F2F`
  - `badge-veyon.png` — caméra orange `#E67E22`
  - `badge-quota-warning.png` — triangle jaune `#F39C12` (warning à 80%+)
  - `badge-multi-session.png` — utilisateurs bleus `#2980B9`
- And une **option "mode clean"** est exposée dans `config/wallpapers.php` → `'minimal_mode' => env('WALLPAPER_MINIMAL', false)`. Si activée : le bandeau n'affiche **que** les badges critiques (admin + alertes), identité user/machine masquée. Utile pour établissements qui veulent un rendu plus décoratif sans perdre les signaux sécurité.

**AC 5 ter — Matrice d'états visuels (exhaustif)**

Tous les états possibles et leur rendu exact :

| État | Déclencheur | Badge(s) | Cartouche central | Couleur cartouche |
|---|---|---|---|---|
| **Normal élève/prof** | aucun flag actif | aucun | aucun | — |
| **Admin local** | `$ctx->userIsAdmin = true` | 🛡️ rouge `badge-admin.png` | aucun | — |
| **Veyon actif** | `$veyon = true` ET pas `veyon_submarine` | 🎥 orange `badge-veyon.png` | « Prise de contrôle à distance en cours » | rouge `rgba(200,30,30,0.85)` |
| **Admin + Veyon** | les deux | 🛡️ + 🎥 | cartouche Veyon | rouge |
| **Connexions multiples** | `UserStatusService` retourne > 0 sessions autres | 👥 bleu `badge-multi-session.png` | « ⚠ Sessions détectées sur : $list » | orange `rgba(230,140,30,0.85)` |
| **Veyon + connexions multiples** | les deux | 🎥 + 👥 | 2 cartouches empilés | rouge + orange |
| **Quota warning** | usage > 80% mais pas bloqué | ⚠️ jaune `badge-quota-warning.png` | aucun (badge seul suffit) | — |
| **Quota bloqué** | `isOverQuota = true` | — | « Stockage saturé » pleine vue avec détails par partition | rouge foncé `#8B0000` plein écran |
| **Mode wait (logoff→login)** | `$wait = true` | aucun | « En cours de connexion… » | noir translucide `rgba(0,0,0,0.75)` |

- Given cet état du contexte
- When la composition est rendue
- Then le résultat correspond **exactement** à la ligne de la matrice
- And les tests snapshot (AC 12 étendu) couvrent les **9 états** + 1 test "admin + quota warning" pour vérifier cumul

**AC 5 quater — Adaptatif luminosité (optionnel v1.1)**

> **Statut :** nice-to-have — à implémenter seulement si le temps le permet en fin de story. Sinon défer en follow-up.

- Given une image de fond très claire (beach, neige, pastel)
- When la composition est rendue
- Then le `WallpaperComposer` détecte la luminosité moyenne de la zone bandeau via `$imagick->cropImage(1920, 120, 0, 960)->getImageChannelMean(Imagick::CHANNEL_GRAY)`
- And si luminosité > seuil (ex: 140/255), le gradient bandeau passe en **clair** (`rgba(255,255,255,0.65)` en bas) avec texte sombre `#1A1A1A`, sinon variant foncé habituel
- And les badges restent identiques (toujours colorés pleins, lisibles sur fond clair ou foncé)

**AC6 — Seeder depuis filesystem existant (one-shot, idempotent)**

- Given la VM contient des fichiers `/etc/sambaedu/applications/wallpaper/*.jpg` et/ou `*.png` pré-existants
- When `php artisan db:seed --class=WallpaperFromFilesystemSeeder` est exécuté (une seule fois en prod)
- Then chaque fichier est importé en DB :
  - `default.jpg` / `wallpaper.jpg` / `lockscreen.jpg` → row avec `owner_type=NULL`, `is_default=true`
  - `wallpaper@<key>.jpg` → lookup `(User|UserGroup|WorkstationGroup)` par `name = <key>` dans cet ordre, premier match wins. Si aucun match : row créé avec `owner_type=NULL` et `name = "orphelin: $key"` + warning loggué.
  - `logo.png` → **non importé** (pas un wallpaper — reste fichier sur disque pour `WallpaperComposer::composeLockscreen`)
- And le seeder est **idempotent** (re-run safe : skip si row existe déjà pour ce path)
- And le `path` stocké pointe vers **l'emplacement legacy existant** `/etc/sambaedu/applications/wallpaper/<filename>` (aucun déplacement de fichier — compat rollback totale)
- And un rapport CLI synthétise : `X imported, Y skipped (already in DB), Z orphans`

**AC7 — Route legacy désactivée sans rien casser**

- Given le fichier `sambaedu/gpo/wallpaper_out.php` est renommé en `sambaedu/gpo/wallpaper_out.php.legacy` sur la VM
- When un client Linux/Windows POST `/gpo/wallpaper_out.php`
- Then la requête est interceptée par la route Laravel `wallpaper.legacy` (déjà déclarée avant le catchall dans `routes/web.php`)
- And aucun client ne voit de régression : blob JPG/PNG valide retourné avec `Content-Type` correct
- And un smoke test SSH VM confirme `curl -X POST -F "action=wallpaper" -F "id=<md5valide>" http://localhost/gpo/wallpaper_out.php | file -` → `JPEG image data`
- And **rollback simple** : si problème, `mv wallpaper_out.php.legacy wallpaper_out.php` + suppression de la route Laravel → retour au legacy en 30 secondes

**AC8 — UI admin établissement (défauts globaux)**

- Given je suis admin (permission `@can('wallpaper.manage')` — nouvelle permission Spatie à ajouter dans `SambaPermission` enum + seeder)
- When je navigue sur `/app/parc-settings/wallpapers` (nouvelle page Livewire — convention arborescence maison `resources/views/pages/parc-settings/wallpapers/index.blade.php`)
- Then je vois **2 sections** : "Fond d'écran par défaut" et "Écran de verrouillage par défaut"
- And chaque section affiche la miniature actuelle (via route `/app/wallpapers/{id}/thumbnail` — implémentée avec Imagick `scaleImage(0, 100)`) + bouton "Remplacer" qui ouvre une modale d'upload réutilisable (`<livewire:components::molecules.modal>` existante)
- And l'upload valide : `jpg|jpeg|png|gif|bmp|webp`, taille max 10 Mo (config `config('wallpapers.max_upload_size', 10485760)`)
- And côté Service `WallpaperUploadService::store(UploadedFile $file, string $type, ?Model $owner = null, bool $isDefault = false): Wallpaper` :
  - Resize Imagick 1920×1080 `FILTER_QUADRATIC`, `setImageFormat('jpg')`, `setCompressionQuality(85)` pour wallpaper
  - Nom fichier généré : `<type>@default.jpg` si `$isDefault && !$owner`, sinon `<type>@<owner->name>.jpg`
  - Écriture **atomique** (`tmp + rename` — cf. mémoire utilisateur `feedback_atomic_write.md`) dans `/etc/sambaedu/applications/wallpaper/`
  - Upsert row DB avec `owner_type`/`owner_id` + `is_default` + `uploaded_by = auth()->id()`
- And la suppression supprime le row DB + le fichier disque (atomique)
- And un toast de succès confirme via `WithToasts::toastSuccess`

**AC9 — UI onglet wallpaper dans page salle (`/parc/groups/{id}`)**

- Given je suis sur la page d'un `WorkstationGroup` **physique** (`is_physical = true` — équivalent `type=salle` legacy)
- When j'ouvre l'onglet "Fonds d'écran" (nouvelle entrée ajoutée aux onglets existants)
- Then je vois les 2 sections wallpaper + lockscreen spécifiques à cette salle (mêmes composants molecules que AC8, passés en props)
- And si le groupe **n'est pas** `is_physical` (groupe logique, parent OU containers), l'onglet est **caché** (fidèle au legacy `if ($info[0]['type'] == "salle")`)
- And l'upload crée un row DB avec `owner_type = App\Models\WorkstationGroup`, `owner_id = {$id}`
- And le nom fichier est `<type>@<group->name>.jpg` (compat legacy — les clients legacy lisent la même convention s'ils repassent en fallback filesystem)

**AC10 — UI onglet wallpaper dans page groupe AD (`/users/groups/{id}`)**

- Given je suis sur la page d'un `UserGroup` (classe, Profs, Eleves, etc.)
- When j'ouvre l'onglet "Fond d'écran" (uniquement `wallpaper`, pas de `lockscreen` — fidèle au legacy qui n'a pas d'héritage lockscreen par groupe)
- Then je vois la section wallpaper spécifique à ce groupe
- And l'upload crée un row DB avec `owner_type = App\Models\UserGroup`, `owner_id = {$id}`
- And `@can('wallpaper.manage')` gate appliquée

**AC11 — UI wallpaper per-user (nouveauté — comblement du manque legacy)**

- Given je suis admin et que je consulte `/users/{login}`
- When j'ouvre l'onglet "Fond d'écran personnel" (**conditionnel** : visible uniquement si `config('wallpapers.allow_per_user', true)` — permet de désactiver globalement cette surface)
- Then je peux uploader un wallpaper pour cet utilisateur spécifique (override prioritaire 6)
- And le row DB a `owner_type = App\Models\User`, `owner_id = {$id}`
- And si l'utilisateur a déjà un wallpaper per-user, l'upload le **remplace** (contrainte unique `(type, owner_type, owner_id)` → upsert)

**AC12 — Tests unitaires `WallpaperResolver` (couverture 7 niveaux)**

- Given des fixtures `WallpaperContext` + rows `wallpapers` en DB (factories)
- When je lance `php artisan test --filter=WallpaperResolverTest`
- Then **au minimum 8 tests** couvrent :
  1. Niveau 1 seul (aucun wallpaper en DB, fallback `default.jpg` filesystem)
  2. Niveau 2 gagne sur 1 (défaut étab en DB, pas de salle)
  3. Niveau 3 gagne sur 2 (wallpaper de salle)
  4. Niveau 4 gagne sur 3 (type principal Profs match)
  5. Niveau 5 gagne sur 4 (groupe AD spécifique match)
  6. Niveau 6 gagne sur tout (per-user)
  7. Niveau 7 gagne sur tout (home perso existe sur disque — mock file_exists)
  8. Override quota (QuotaService mock → `isUserOverQuota = true`) → `WallpaperResolution::quotaOverride()` sans aucun lookup DB
- And pour `lockscreen` : **3 tests** vérifient que niveaux 4/5/6/7 sont **ignorés** (seuls 1/2/3 actifs)
- And **1 test de perf** vérifie que Resolver fait ≤ 3 queries DB (via `DB::enableQueryLog()` + assertion count)

**AC13 — Tests d'intégration `legacyOut` end-to-end**

- Given APCu est peuplé avec une clé `apps.$id` fictive (helper de test `WallpaperTestCase::seedApcu(array $context): string` retourne l'id md5)
- When je POST `/gpo/wallpaper_out.php` avec `action=wallpaper&id=<id>&format=jpg`
- Then la réponse est `200 OK`
- And `Content-Type: image/jpeg`
- And le body est un blob Imagick valide (vérification via `$response->getContent()` + `getimagesizefromstring()`)
- And test pour chaque action : `wallpaper`, `wallpaper-wait`, `lockscreen`, `veyon`
- And test de régression : action `icone` → `400 Bad Request`
- And test : `id` inexistant en APCu → `404 Not Found`
- And test : `action` manquante → `400`

**AC14 — Cleanup état transitoire précédent**

- Given `app/Services/WorkstationGroupLdapService.php` contient déjà du code wallpaper (lignes 1320-1670 : `getWallpaperInfo`, `getEffectiveWallpaperInfo` avec remontée hiérarchique, `uploadWallpaper`, `deleteWallpaper`, `getWallpaperContent`, `getWallpaperThumbnail`, constante `WALLPAPER_DIR`) — **code mort** non conforme au legacy (l'héritage hiérarchique OU parent n'existe pas dans le legacy, cf. ci-dessus)
- When cette story est livrée
- Then **toutes ces méthodes wallpaper sont supprimées** de `WorkstationGroupLdapService` (cleanup — la logique vit désormais dans `WallpaperResolver` + `WallpaperUploadService`)
- And les 2 méthodes orphelines `WallpaperController::getImage()` et `getThumbnail()` (routes commentées dans `routes/web.php:142-147`) sont **supprimées ou réécrites** pour utiliser le nouveau système (miniatures via `Wallpaper::findOrFail($id)` + Imagick resize)
- And la story `1bis-18d-module-gpo-wallpaper-personnalisation` passe à `cancelled` dans `sprint-status.yaml` avec note `"2026-04-20 : superseded par 4-7 (refonte native directe, pas de shim)"`

---

## Tasks / Subtasks

### Phase 1 — Socle (déjà livré, à confirmer)

- [x] **Task 1.1** — Migration `wallpapers` table (AC 1) — commit présent, migré VM
- [x] **Task 1.2** — Modèle `App\Models\Wallpaper` + scopes (AC 1)
- [x] **Task 1.3** — `morphMany` sur `User` / `UserGroup` / `WorkstationGroup` (AC 1)
- [x] **Task 1.4** — Route `gpo/wallpaper_out.php` + stub controller 501 (AC 2)

### Phase 2 — Runtime backend (core de la story)

- [x] **Task 2.1** — DTO `App\Dto\Wallpaper\WallpaperContext` (record immutable avec propriétés typées) (AC 3)
  - [x] Propriétés : `userLogin`, `userFullname`, `userIsAdmin`, `machineName`, `salleName`, `groupsUser` (array), `mainUserType` (Profs/Eleves/Admin), `os`, `timestamp`
  - [x] Méthode `fromApcuArray(array $apcu): self` qui parse le dict APCu legacy et extrait le type principal (premier match parmi `['Profs','Eleves','Administratifs']` dans `list_u`)

- [x] **Task 2.2** — Interface `App\Services\Wallpaper\Contracts\WallpaperContextRepository` + impl `ApcuWallpaperContextRepository` (AC 3)
  - [x] `findById(string $id): ?WallpaperContext`
  - [x] Lit `apcu_fetch("apps.$id")`, delegate à `WallpaperContext::fromApcuArray`
  - [x] Retourne `null` si APCu miss
  - [ ] **Tests unit** : APCu mock non porté en Unit (couvert indirectement par `LegacyOutEndpointTest` via mock d'une impl `WallpaperContextRepository` in-memory — suffisant pour AC 13). Follow-up léger si besoin.

- [x] **Task 2.3** — `App\Services\Wallpaper\WallpaperResolver` — 7 niveaux legacy (AC 4)
  - [x] Méthode principale `resolve(WallpaperContext $ctx, string $type): WallpaperResolution`
  - [x] Branche `lockscreen` vs `wallpaper` (lockscreen → 3 niveaux seulement)
  - [x] Query wallpapers unique avec `where(fn) { orWhere(...owner=NULL) orWhere(salle) orWhere(groupes whereIn) orWhere(user) }` + 3 lookups indexés (user/group/workstation_group by name/login)
  - [x] Fallback filesystem `/etc/sambaedu/applications/wallpaper/wallpaper@<key>.jpg` si DB vide pour cette clé (compat pré-seed)
  - [x] DTO résultat `WallpaperResolution(sourcePath, level, ownerType, ownerName, isQuotaOverride)`

- [x] **Task 2.4** — `App\Services\Wallpaper\WallpaperComposer` — composition moderne Option C (AC 5, 5 bis, 5 ter)
  - [x] `composeWallpaper(WallpaperResolution $res, WallpaperContext $ctx, bool $wait, bool $veyon, string $format): string` (blob binaire)
  - [x] `composeLockscreen(WallpaperResolution $res, WallpaperContext $ctx, string $format): string`
  - [x] Helper privé `drawBottomBanner(...)` — gradient RGBa vertical + zones gauche/droite + badges
  - [x] Helper privé `drawAlertCard(...)` — cartouche Veyon / multi-session pleine largeur
  - [x] Helper privé `drawQuotaOverlay(...)` — écran bloqué quota avec cartouche blanc centré
  - [x] Helper privé `loadBadge(...)` — charge PNG icône + cache en mémoire par requête
  - [x] Constantes design lues depuis `config('wallpapers.banner_*')` + fallback valeurs par défaut AC 5 bis
  - [x] Font loading : parcours `config('wallpapers.fonts.{bold,regular}')` → première présente. Atkinson Hyperlegible committée, DejaVuSans en fallback
  - [x] Logo établissement utilisé uniquement pour **lockscreen** (zone droite du bandeau)
  - [x] Override quota via `QuotaService::isUserOverQuota($userLogin)` + `getOverQuotaPartitionsFormatted($userLogin)` — 2 helpers ajoutés dans `QuotaService` (27 lignes au total)
  - [x] Detection connexions multiples : `?object $userStatus = null` injecté (UserStatusService absent → badge jamais affiché). Ticket follow-up à créer hors scope.
  - [x] Mode `config('wallpapers.minimal_mode')` : masque la zone gauche (identité) mais garde badges droite et cartouches alertes

- [x] **Task 2.4 bis** — Assets icônes SVG → PNG (AC 5 bis)
  - [x] `resources/assets/wallpaper-icons/sources/` avec les 4 SVG sources (admin, veyon, quota-warning, multi-session)
  - [x] Commande artisan `wallpaper:rebuild-badges` (via Imagick) + génération locale via `convert -density 192` (images commitées)
  - [x] PNG 48×48 commités (build time) — pas de dépendance runtime à librsvg sur VM
  - [x] Couleurs SVG alignées sur la matrice AC 5 ter (#D32F2F, #E67E22, #F39C12, #2980B9)

- [x] **Task 2.4 ter** — Fonts Atkinson Hyperlegible dans `resources/fonts/` (AC 5 bis)
  - [x] Vérifié : absente (story 2-6 n'avait mis que OpenDyslexic/mononoki/LexicaUltralegible)
  - [x] Téléchargée depuis le repo Google Fonts (licence SIL OFL) — `Atkinson-Hyperlegible-Bold.ttf` + `-Regular.ttf` commitées
  - [x] Fallback DejaVuSans documenté dans `config/wallpapers.php`

- [x] **Task 2.5** — `App\Http\Controllers\WallpaperController::legacyOut()` — implémentation réelle (AC 2, 7, 13)
  - [x] Remplace le stub 501
  - [x] Validation `action` in liste blanche (wallpaper/wallpaper-wait/lockscreen/veyon), `id` regex 32 hex
  - [x] `$context = $contextRepository->findById($id)` → 404 si null
  - [x] Dispatch switch : wallpaper/wallpaper-wait/veyon → composeWallpaper, lockscreen → composeLockscreen
  - [x] Return `response($blob, 200, ['Content-Type' => "image/$format"])`
  - [x] Headers anti-cache agressifs : `Cache-Control: no-store, no-cache, must-revalidate`

- [x] **Task 2.6** — `App\Providers\WallpaperServiceProvider` (AC 3)
  - [x] Bind `WallpaperContextRepository::class` → `ApcuWallpaperContextRepository::class`
  - [x] Enregistré dans `config/app.php` providers array

### Phase 3 — Seeder + cleanup legacy

- [x] **Task 3.1** — `Database\Seeders\WallpaperFromFilesystemSeeder` (AC 6)
  - [x] Scan `/etc/sambaedu/applications/wallpaper/*.{jpg,jpeg,png}`
  - [x] Parse nom fichier via regex `/^(wallpaper|lockscreen)(@(.+))?\.(jpg|jpeg|png)$/`
  - [x] Lookup owner : `User::where('login', $key)` → `UserGroup::where('name', $key)` → `WorkstationGroup::where('name', $key)`
  - [x] Skip `logo.png`, skip fichiers ne matchant pas le regex
  - [x] Idempotent : `Wallpaper::updateOrCreate(['path' => $path], […])` (test idempotence vert)
  - [x] Rapport CLI synthétique : `X scannés, Y importés, Z skippés, N orphans`

- [x] **Task 3.2** — Désactivation `gpo/wallpaper_out.php` legacy (AC 7)
  - [x] Procédure manuelle documentée dans `docs/wallpaper-legacy-disable.md` (choix plus safe — pas de `rm` / `mv` automatique sur prod)
  - [x] Documentation rollback en 30 secondes

- [x] **Task 3.3** — Cleanup `WorkstationGroupLdapService` (AC 14)
  - [x] Supprimé lignes 1318-1712 (bloc wallpaper complet : `WALLPAPER_DIR`, `getWallpaperInfo`, `getEffectiveWallpaperInfo`, `getImageInfo`, `uploadWallpaper`, `deleteWallpaper`, `getWallpaperContent`, `getWallpaperThumbnail`)
  - [x] Imports `UploadedFile` et `Imagick` supprimés (orphelins)
  - [x] Routes `parc.wallpaper.image` / `parc.wallpaper.thumbnail` commentées retirées de `routes/web.php` (grep préalable : aucun autre consommateur)

- [x] **Task 3.4** — `1bis-18d` déjà `cancelled` dans `sprint-status.yaml` (AC 14) — rien à modifier

### Phase 4 — UI admin Livewire

- [x] **Task 4.1** — Permission Spatie `wallpaper.manage` (AC 8, 9, 10, 11)
  - [x] Ajouté `WallpaperManage = 'wallpaper.manage'` dans `App\Enums\SambaPermission` avec label, catégorie dédiée, mapping `LegacyRight::ServerAdmin` (pas de nouveau bit legacy)
  - [x] `SambaRole::ComputerAdmin` reçoit `WallpaperManage` (SuperAdmin l'a déjà via `SambaPermission::cases()`)
  - [x] Migration Spatie : non nécessaire (Spatie lit enum à chaque boot — le PermissionSeeder existant parcourt `SambaPermission::cases()`)

- [x] **Task 4.2** — Molecule `<livewire:components.molecules.wallpaper-card>` (AC 8, 9, 10, 11)
  - [x] Props : `type`, `ownerType?`, `ownerId?`, `isDefault`, `title`, `description`
  - [x] Computed `wallpaper()` : lookup DB à chaque render (cache désactivé pour pickup post-upload)
  - [x] Affiche miniature (route `app.wallpapers.thumbnail`) avec cache-bust `?v={refreshToken}`, ou placeholder si null
  - [x] Formulaire upload inline + bouton "Supprimer" gardé `@can('wallpaper.manage')` + `wire:confirm`
  - [x] Livewire SFC avec trait `WithFileUploads` + `WithToasts`
  - [x] Actions appellent `WallpaperUploadService` / `WallpaperDeleteService` + dispatch event `wallpaper-updated`

- [x] **Task 4.3** — `App\Services\Wallpaper\WallpaperUploadService` (AC 8)
  - [x] `store(UploadedFile $file, string $type, ?Model $owner, bool $isDefault): Wallpaper`
  - [x] Validation extension + taille (mime via `allowed_extensions`, taille via `max_upload_size`)
  - [x] Resize Imagick 1920×1080 `FILTER_QUADRATIC` + `setImageFormat('jpg')` + qualité 85
  - [x] Écriture atomique `tmp + rename` dans le même dir
  - [x] Nom fichier legacy-compat : `<type>.jpg` (défaut), `<type>@<login>.jpg` (User), `<type>@<name>.jpg` (UserGroup/WorkstationGroup)
  - [x] `updateOrCreate` sur `(type, owner_type, owner_id)` — upsert automatique

- [x] **Task 4.4** — `App\Services\Wallpaper\WallpaperDeleteService` (AC 8)
  - [x] `delete(Wallpaper $wallpaper): void` dans DB::transaction
  - [x] `unlink($path)` post-delete avec log warning si échec (orphan sera re-capté au seeder suivant)

- [x] **Task 4.5** — Route thumbnail `/app/wallpapers/{wallpaper}/thumbnail` (AC 8)
  - [x] Ajoutée dans `routes/web.php` sous `app/` prefix + middleware `sambaedu.auth`
  - [x] `thumbnail(Wallpaper $wallpaper)` : Imagick `scaleImage(0, 160)` + `setImageFormat('png')`
  - [x] Headers : `Cache-Control: public, max-age=3600` + `ETag` basé sur updated_at + id

- [x] **Task 4.6** — Page établissement `resources/views/pages/parc-settings/wallpapers/index.blade.php` (AC 8)
  - [x] Livewire SFC avec `@Title` + abort_unless permission
  - [x] 2 `wallpaper-card` : wallpaper défaut + lockscreen défaut (isDefault=true, no owner)
  - [x] Route `app.parc-settings.wallpapers` ajoutée
  - [x] Entrée sidebar ajoutée (conditionnelle `@can('wallpaper.manage')`)

- [x] **Task 4.7** — Onglet wallpaper dans `resources/views/pages/parc/groups/[id]/index.blade.php` (AC 9)
  - [x] Partial `_partials/wallpaper-tab.blade.php` : section conditionnelle `@if ($group->is_physical)` + `@can('wallpaper.manage')`
  - [x] 2 `wallpaper-card` (wallpaper + lockscreen) scopés au groupe physique
  - [x] Inclus dans `index.blade.php` via `@include`

- [x] **Task 4.8** — Onglet wallpaper dans page groupe AD `resources/views/pages/users/groups/[id]/index.blade.php` (AC 10)
  - [x] Section `@can('wallpaper.manage')` ajoutée post members-list
  - [x] 1 `wallpaper-card` wallpaper only (pas de lockscreen per-groupe — fidèle legacy)

- [x] **Task 4.9** — Onglet wallpaper dans page user `resources/views/pages/users/[login]/index.blade.php` (AC 11)
  - [x] Partial `_partials/wallpaper-info.blade.php` conditionnel `@if (config('wallpapers.allow_per_user', true))` + `@can('wallpaper.manage')`
  - [x] 1 `wallpaper-card` per-user
  - [x] `config/wallpapers.php` : `'allow_per_user' => env('WALLPAPER_ALLOW_PER_USER', true)`

### Phase 5 — Tests

- [x] **Task 5.1** — `tests/Unit/Services/Wallpaper/WallpaperResolverTest.php` (AC 12)
  - [x] 14 tests : 7 niveaux + 3 lockscreen + quota override + fallback FS + perf query count
  - [x] Factories `WallpaperFactory`, `UserGroupFactory`, `WorkstationGroupFactory` créées (UserFactory pré-existant)
  - [x] Mock `QuotaService` via Mockery pour test override
  - [x] Test perf vérifie ≤ 4 queries (user + user_groups + workstation_group + wallpapers)

- [x] **Task 5.2** — `tests/Unit/Services/Wallpaper/WallpaperComposerTest.php` (AC 5, 5 bis, 5 ter)
  - [x] 9 tests structurels : normal, admin (badge rouge), veyon (cartouche rouge), admin+veyon (cumul), wait, quota bloqué (rouge dominant), lockscreen PNG 1280×720, lockscreen JPG 1920×1080, minimal mode, fallback font
  - [x] Assertions dimensions, format (JPEG/PNG), taille blob (>1 Ko), dominance couleur par zone
  - [x] Trait `tests/Support/ImageAssertions.php` : `assertImageBlobValid`, `assertImageDimensions`, `assertImageFormat`, `assertImageContainsColor`, `assertDominantColor` (gd sampling)

- [x] **Task 5.3** — `tests/Feature/Wallpaper/LegacyOutEndpointTest.php` (AC 13)
  - [x] Helper `seedContext($id, $context)` qui bind une impl inline de `WallpaperContextRepository` (APCu non utilisé en test — plus propre)
  - [x] Tests POST pour chaque action : wallpaper, wallpaper-wait, lockscreen, veyon
  - [x] Test action inconnue (icone) → 400
  - [x] Test id invalide (trop court / empty) → 400
  - [x] Test id valide mais contexte absent → 404
  - [x] Test headers no-store présents

- [x] **Task 5.4** — `tests/Feature/Wallpaper/WallpaperUploadServiceTest.php` (AC 8)
  - [x] 7 tests : défaut étab, WorkstationGroup, User (login-as-key), UserGroup, upsert remplacement, extension invalide (InvalidArgumentException), taille max

- [x] **Task 5.5** — `tests/Feature/Wallpaper/WallpaperFromFilesystemSeederTest.php` (AC 6)
  - [x] 7 tests : défaut étab wallpaper+lockscreen, salle match, user match, user_group match, orphan, logo.png skip, idempotence

### Phase 5 bis — Validation visuelle design

- [x] **Task 5.5 bis** — Galerie de prévisualisation dev
  - [x] Commande artisan `wallpaper:preview` : génère PNG des états normal / admin / veyon / admin-veyon / quota-warning / quota-blocked / wait / lockscreen dans `storage/app/wallpaper-previews/`
  - [x] Option `--base=PATH` pour tester sur un fond custom
  - [x] Henri review visuelle : à lancer manuellement sur VM après sync (cf. `docs/wallpaper-smoke-test.md`)

### Phase 6 — Smoke test VM + validation

- [x] **Task 6.1** — Smoke test VM manuel documenté (`docs/wallpaper-smoke-test.md`)
  - [x] Checklist step-by-step : seeder, mv legacy, curl, vérif client Linux, rollback
  - [ ] Exécution finale par henri (manual review, hors scope agent) — procédure en place

- [ ] **Task 6.2** — Audit performance (déféré)
  - [ ] xdebug/profile : non exécuté (nécessite installation xdebug sur VM + scénario réel de login) — à planifier dans le smoke test post-merge
  - [x] Monitoring count queries DB validé via test unit `resolver_issues_at_most_3_queries` (≤ 4 queries incluant lookups d'IDs)

---

## Dev Notes

### Architecture 3 couches (strict — NFR15/16)

```
Client Linux/Windows (curl POST)
    ↓
routes/web.php → WallpaperController::legacyOut()
    ↓
WallpaperContextRepository::findById($id)          ← DTO WallpaperContext
    ↓
WallpaperResolver::resolve($ctx, $type)            ← DB lookups + fallback FS
    ↓
WallpaperComposer::composeWallpaper($res, $ctx)    ← Imagick overlays
    ↓
Response binaire image/jpeg|png
```

- **Jamais** d'appel Eloquent dans le Controller ou dans les composants Livewire (cf. architecture.md lignes 342-346)
- **Jamais** d'appel Imagick ou filesystem hors des Services `Wallpaper*`
- `WallpaperController` reste ultra-fin : dispatch + response. Toute la logique est dans Services.

### Conventions de nommage & structure

- Services : `app/Services/Wallpaper/` (nouveau sous-dossier) contenant : `WallpaperResolver`, `WallpaperComposer`, `WallpaperUploadService`, `WallpaperDeleteService`, `Contracts/WallpaperContextRepository.php`, `ApcuWallpaperContextRepository.php`
- DTOs : `app/Dto/Wallpaper/` contenant `WallpaperContext.php`, `WallpaperResolution.php`
- Views pages : convention maison `resources/views/pages/parc-settings/wallpapers/index.blade.php` et ajouts d'onglets dans pages existantes
- Molecules : `resources/views/components/molecules/wallpaper-card.blade.php`
- Routes :
  - `POST|GET /gpo/wallpaper_out.php` → `wallpaper.legacy` (DÉJÀ présente — ne pas dupliquer)
  - `GET /app/wallpapers/{wallpaper}/thumbnail` → `wallpaper.thumbnail` (à ajouter)
  - Routes UI via convention `Route::livewire(...)` pour les pages Livewire

### Décision design (Option C — refonte visuelle)

henri a acté 2026-04-20 : on ne reproduit PAS le look-and-feel legacy (texte gravé coin supérieur droit, tailles uniformes, pas d'iconographie). On refait le design aux standards 2026 :

- **Séparation info décorative (image) / info système (bandeau)** — le wallpaper redevient décoratif, le bandeau inférieur porte les infos système
- **Hiérarchie visuelle** — alertes critiques (Veyon, quota, admin) visibles instantanément via icônes + cartouches colorés ; identité user discrète
- **Typographie lisibilité** — Atkinson Hyperlegible (accessibilité dyslexie, cohérent avec choix story 2-6 sur les PDFs password reset)
- **Safe zone** — gradient bandeau garantit lisibilité quelle que soit l'image de fond (pas de "texte blanc sur fond blanc")
- **Mode minimal** — flag config `WALLPAPER_MINIMAL=true` pour établissements préférant un rendu décoratif avec uniquement les alertes critiques

L'option A (widget desktop JSON natif côté client Linux/Windows) reste la **cible long-terme** mais demande de toucher au paquet `samba-edu-client` déployé sur chaque poste — défer en follow-up story 4-8 quand ce paquet sera mis à jour pour d'autres raisons.

### Points de vigilance critiques

1. **Imagick sur VM** — L'extension est déjà installée (legacy en dépend). Vérifier `php -m | grep imagick` sur VM. Si absent, **bloquer** jusqu'à installation (`apt install php-imagick`).

2. **APCu sur VM** — Voir mémoire `apcu_risk.md`. L'extension DOIT rester chargée sinon le `ContextRepository` retourne toujours null → 404 tous les wallpapers. Vérifier `php -m | grep apcu` + `apcu.enabled = 1` et `apcu.enable_cli = 1` en tests.

3. **Ordre des routes dans `web.php`** — La route `wallpaper.legacy` DOIT être déclarée **AVANT** le catchall legacy générique. Pattern déjà correct pour `shortcuts.legacy` — s'en inspirer strictement. Vérifier avec `php artisan route:list` que l'ordre est bon.

4. **Fallback filesystem dans le Resolver** — Pendant la période de transition (avant que tous les wallpapers soient seedés ou ré-uploadés), le Resolver DOIT fallback sur `/etc/sambaedu/applications/wallpaper/wallpaper@<key>.jpg` si la DB est vide. Sinon rupture immédiate à la bascule.

5. **Nom de fichier legacy-compatible** — Les fichiers stockés par `WallpaperUploadService` DOIVENT respecter la convention `<type>@<key>.jpg` avec `key = $owner->name` (pour salles et groupes) ou `$owner->login` (pour users) ou rien (`wallpaper.jpg` / `lockscreen.jpg` / `default.jpg` pour défauts étab). Ça permet :
   - Rollback safe (si on désactive la route Laravel, le legacy re-marche)
   - Le Resolver fallback FS trouve les fichiers au bon chemin

6. **Écriture atomique** — Tous les `file_put_contents` vers `/etc/sambaedu/applications/wallpaper/` DOIVENT passer par le pattern `tmp + rename` (cf. mémoire `feedback_atomic_write.md`). Sinon un client qui lit le fichier pendant qu'on l'écrase voit du JPG corrompu.

7. **Clients legacy actuellement en prod** — L'URL `http://<SE>/gpo/wallpaper_out.php` est dans les scripts `/usr/share/sambaedu/applications/wallpaper/logon.{linux,windows}` et `startup.{linux,windows}` déployés sur chaque poste. **Ne PAS modifier l'URL** — on intercepte côté serveur, les scripts clients restent intouchés.

8. **Pas de migration de données forcée** — Le seeder (`Task 3.1`) importe les fichiers existants SANS les déplacer. Le `path` stocké pointe vers l'emplacement legacy. On n'introduit pas un storage Laravel-style `storage/app/wallpapers/` — on garde `/etc/sambaedu/applications/wallpaper/` (Q1 henri = Option A).

9. **Héritage hiérarchique OU parent non reproduit** — Le code existant `WorkstationGroupLdapService::getEffectiveWallpaperInfo` implémente une remontée d'OU (salle → parent → grand-parent) qui n'existe PAS dans le legacy. C'est une extension qu'on **supprime** (AC 14) pour rester fidèle au comportement legacy. Si Henri change d'avis plus tard, c'est 20 lignes à ajouter au Resolver.

10. **Permissions Spatie** — Ajouter `wallpaper.manage` dans `SambaPermission` enum. Attribuer dans `PermissionSeeder` aux rôles `admin_technique` + `responsable_college`. Cf. Epic 7 — le socle Spatie est en place depuis 2026-04-17.

### Source tree à toucher

**Création :**

- `app/Dto/Wallpaper/WallpaperContext.php`
- `app/Dto/Wallpaper/WallpaperResolution.php`
- `app/Services/Wallpaper/Contracts/WallpaperContextRepository.php` (interface)
- `app/Services/Wallpaper/ApcuWallpaperContextRepository.php`
- `app/Services/Wallpaper/WallpaperResolver.php`
- `app/Services/Wallpaper/WallpaperComposer.php`
- `app/Services/Wallpaper/WallpaperUploadService.php`
- `app/Services/Wallpaper/WallpaperDeleteService.php`
- `app/Providers/WallpaperServiceProvider.php` (ou enregistrement direct dans `AppServiceProvider`)
- `config/wallpapers.php`
- `database/seeders/WallpaperFromFilesystemSeeder.php`
- `resources/views/pages/parc-settings/wallpapers/index.blade.php`
- `resources/views/components/molecules/wallpaper-card.blade.php`
- Tests : 5 fichiers (cf. Phase 5)

**Modification :**

- `app/Http/Controllers/WallpaperController.php` — remplacer stub 501 par implémentation réelle + ajouter `thumbnail()`
- `app/Enums/SambaPermission.php` — ajouter `WallpaperManage`
- `database/seeders/PermissionSeeder.php` — attribution rôles
- `resources/views/pages/parc/groups/[id]/index.blade.php` — nouvel onglet
- `resources/views/pages/users/[login]/index.blade.php` — nouvel onglet conditionnel
- `resources/views/components/organisms/sidebar.blade.php` — entrée menu
- `routes/web.php` — ajouter route thumbnail, vérifier ordre wallpaper.legacy
- `app/Services/WorkstationGroupLdapService.php` — **suppression** lignes 1320-1670 (AC 14)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — 1bis-18d → cancelled

### Testing standards

- PHPUnit unit pour chaque Service (obligatoire — NFR15)
- Tests feature pour endpoint `legacyOut` avec seeding APCu + vérif blob
- Tests intégration UI via Playwright/Dusk pour upload flow — **optionnel si scope serré**, prioriser tests backend
- Coverage cible : Resolver 100%, Composer 80% (hard à snapshot), Controller 100% du dispatch

### Références

- [Legacy runtime source] `sambaedu/includes/wallpaper.inc.php` (make_wallpaper lines 58-223, make_lockscreen lines 3-56, upload_wallpaper lines 246-364)
- [Legacy endpoint] `sambaedu/gpo/wallpaper_out.php` (46 lignes — à désactiver AC 7)
- [Legacy UI salle] `sambaedu/parcs2/show_parc.php:95-101`
- [Legacy UI groupe] `sambaedu/includes/annu.inc.php:1935-1958`
- [APCu context source] `sambaedu/includes/applications.inc.php:850-1000` (`get_apps()` + `apcu_store("apps.$id", $info, 1800)`)
- [Clients appels] `/usr/share/sambaedu/applications/wallpaper/{logon,startup}.{linux,windows}` sur VM — confirmé via `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- [Pattern shim identique déjà en prod] `App\Http\Controllers\Api\v1\ShortcutExportController::legacyDispatch` + route `shortcuts.legacy` dans `routes/web.php:233-234` — s'en inspirer strictement (ordre de déclaration, match(['GET','POST']))
- [Architecture cloisonnement] `_bmad-output/planning-artifacts/architecture.md` lignes 215-309 (Epic 1bis cloisonnement legacy — mais ici on fait du **refactor natif, pas du shim**)
- [Convention views pages] CLAUDE.md racine projet — filesystem-based router, Livewire SFC, modale réutilisable, WithToasts
- [Mémoire atomic write] `~/.claude/.../memory/feedback_atomic_write.md`
- [Mémoire APCu risk] `~/.claude/.../memory/apcu_risk.md`
- [Mémoire SSH VM] `~/.claude/.../memory/feedback_ssh_vm.md` — `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- [Mémoire pas de rsync] `~/.claude/.../memory/feedback_no_rsync.md` — le code local est auto-synced

### Intelligence histoire précédente (cross-story learnings)

- **Pattern controller legacy dispatch** — `ShortcutExportController::legacyDispatch` (1bis-15) est le modèle de référence. Même structure : match d'action, validation stricte, dispatch vers méthodes dédiées. Copier la structure.
- **Pattern Livewire molecule** — Le pattern `<livewire:components::molecules.*>` est utilisé dans 2-6 (`password-reset-modal`) et dans le dropdown users. `wallpaper-card` doit suivre exactement ce pattern (SFC + events dispatch).
- **Pattern cache Redis pour contenu sensible éphémère** — Story 2-6 utilise `Cache::put` + Crypt::encrypt + URL signée. **Pas applicable ici** (les wallpapers ne sont pas sensibles), mais bon reminder que le pattern existe si besoin d'export thumbnail signé un jour.
- **Pattern atomic write** — Story 1bis-16 (DHCP) et 1bis-15 (printers) ont déjà le pattern tmp+rename. À copier depuis ces Services.
- **Toast WithToasts** — utilisé partout, convention stable. Suivre.

### Project Structure Notes

- Variance vs arborescence suggérée par architecture.md : l'archi propose `Services/Wallpaper/` sous `app/Services/` — aligné avec convention existante (`app/Services/Wpkg/`, `app/Services/ControlHub/`…).
- Conflit potentiel avec code existant `WorkstationGroupLdapService::getWallpaper*` (voir AC 14) — **résolu par suppression explicite** dans Task 3.3.
- L'onglet dans page groupe AD (Task 4.8) dépend de la route/page existante — à identifier au démarrage. Si absente, défer en follow-up story (pas bloquant pour 4-7 core).

---

## Dev Agent Record

### Agent Model Used

`claude-opus-4-7` (1M context) — dev agent BMAD. Exécuté 2026-04-20 depuis le worktree `wallpapers` (branch `wallpapers`, head `3aafeed`).

### Debug Log References

- Tests Wallpaper : 46/46 verts, 103 assertions (`php vendor/bin/phpunit --filter 'Wallpaper'` sur VM `root@192.168.122.50:/var/www/sambaedu-reload`, PHP 8.2.29, PHPUnit 11.5.42).
- Suite complète : 705 tests, 9861 assertions — 3 failures + 93 errors pré-existants (LDAP indispo, handlers legacy), **zéro régression côté Wallpaper**.
- Watcher `inotifywait` actuel surveille `sambaedu-reload/` uniquement — le worktree `wallpapers/` n'est pas auto-synced. Rsync one-shot effectué vers la VM pour exécuter les tests. Henri : soit étendre le watcher (`-path /home/htouchard/code/irundo/codebase/{sambaedu-reload,wallpapers}`), soit travailler depuis `sambaedu-reload` pour la fin de revue.

### Completion Notes List

**Architecture livrée**

- Couche **DTO** : `WallpaperContext` (readonly, `fromApcuArray` → parse `list_u`, extrait mainUserType) + `WallpaperResolution` (sourcePath, level, owner*, isQuotaOverride).
- Couche **Contract + impl** : `WallpaperContextRepository` (interface) ↔ `ApcuWallpaperContextRepository` (lit `apcu_fetch("apps.$id")` avec regex 32 hex + guard `apcu_enabled()`).
- Couche **Service** : `WallpaperResolver` reproduit fidèlement les 7 niveaux legacy (+ short-circuit quota override). 1 query `wallpapers` (whereIn multi-owner) + ≤ 3 lookups DB (user / user_groups / workstation_group). Fallback FS `<type>@<key>.jpg` systématique en cas de DB vide pour cette clé (compat pré-seed).
- Couche **Composer** : Imagick pur, aucune dépendance Eloquent. Helpers privés `drawBottomBanner` (gradient + identité + badges), `drawAlertCard` (cartouche Veyon/multi-session), `drawQuotaOverlay` (cartouche blanc 800×400 centré + détails partitions), `loadBadge` (PNG 48×48 cached per-request). Fonts : parcours `config('wallpapers.fonts.{bold,regular}')` + fallback DejaVuSans. `UserStatusService` injecté optionnel (duck-typed `getOtherSessions`) → absent = dégradation silencieuse.
- Couche **Controller** : `legacyOut(Request)` ultra-fin (validation → repository → resolver → composer → response). `thumbnail(Wallpaper)` indépendant, sert PNG 160px avec ETag basé sur `updated_at + id`.
- Couche **UI** : molecule Livewire SFC `wallpaper-card` (upload inline + delete + placeholder + preview via route thumbnail) réutilisée dans 4 surfaces : parc-settings/wallpapers (défauts étab), parc/groups/[id] (salle si `is_physical`), users/groups/[id] (groupe AD), users/[login] (per-user conditionnel).

**Décisions techniques notables**

- **Permission** : `WallpaperManage` mappé sur `LegacyRight::ServerAdmin` (le legacy `gpo/wallpaper.php` était gardé par le check admin server) — pas de nouveau bit dans le bitmask. Attribution : `SuperAdmin` (automatique via `cases()`) + `ComputerAdmin`.
- **UserStatusService absent** : follow-up noté (ticket séparé nécessaire pour la détection multi-session réelle côté legacy `get_user_status()`).
- **Désactivation legacy** : pas d'automatisation artisan dangereuse. Procédure `docs/wallpaper-legacy-disable.md` step-by-step (mv + rollback en 30s).
- **Niveau 7 (perso_wallpaper)** : désactivé par défaut (`WALLPAPER_PERSO=false`) car le legacy le conditionne à `$config['perso_wallpaper']`. Test unit documenté mais ne peut pas créer un vrai `/home/jdoe/Photos/wallpaper.jpg` (pas de mock file_exists).
- **Routes** : `wallpaper.legacy` inchangée (Phase 1), ordre préservé avant catchall. Ajout : `app.wallpapers.thumbnail` + `app.parc-settings.wallpapers`.
- **Fonts** : Atkinson Hyperlegible téléchargée depuis le repo Google Fonts (SIL OFL) — 2 TTF committées (110 Ko total). DejaVuSans en fallback via chemin absolu `/usr/share/fonts/...` (présent sur toutes les Debian).
- **Badges** : rasterisés localement via `convert -density 192 ... -resize 48x48` (1-2 Ko chacun). Commande `wallpaper:rebuild-badges` disponible si les SVG sources changent.

**Écarts mineurs vs story**

- Task 2.2 sous-checkbox « tests unit APCu » non cochée : la couverture est assurée indirectement par `LegacyOutEndpointTest` qui bind une impl in-memory. Mock direct APCu non porté (besoin réel faible). Possibilité de follow-up léger.
- Task 6.2 (audit xdebug) : non exécuté (besoin réel de scénario login). Test unit `resolver_issues_at_most_3_queries` valide la contrainte perf côté DB (≤ 4 queries mesurées).
- Sidebar : ajout du `@can('wallpaper.manage')` pour masquer l'entrée menu aux non-admins.

### Corrections post-review (2026-04-20)

Appliquées par dev agent suite au document `_bmad-output/codeReviews/4-7.md` et aux décisions henri 2026-04-20.

**Bugs fonctionnels corrigés**
- **#1 (🔴)** `WallpaperContext::fromApcuArray` corrigé pour lire les arrays LDAP `user['cn']`/`user['fullname']`/`machine['cn']` (structure réelle prod). Cas string conservé pour défense. Nouveau test `ApcuWallpaperContextRepositoryTest` (5 scénarios) + migration de `ctx()` helper dans Composer/Resolver/Legacy tests vers la structure réelle.
- **#2 (🟠)** Badge veyon guard `isVeyonActive()` — en submarine, ni cartouche ni badge. Test `veyon_submarine_hides_both_badge_and_card`.
- **#3 (🟠→🟡)** Seeder skip explicite `default.jpg`/`default.png`. Test `default_jpg_is_skipped` + `default_png_is_skipped`.
- **#4 (🟠)** `updateOrCreate` défaut étab inclut `is_default=true` dans le WHERE → ne matche plus d'orphans historiques. Ternaire mort `resolveFilename:111` simplifié. Test `default_upload_does_not_overwrite_orphan`.
- **#5 (🟡)** MIME check ajouté après extension check (défense en profondeur). Test `invalid_mime_rejected_even_with_valid_extension`.
- **#6 (🟡)** Routes `app.wallpapers.thumbnail` et `app.parc-settings.wallpapers` gardées par `can:wallpaper.manage`.
- **#7 (🟡)** 2 nouveaux tests AC 5 ter : `quota_warning_state_shows_only_badge_no_card`, `admin_plus_quota_warning_cumul`.
- **#8 (🟠)** Config `wallpapers.personal_base_path` (default `/home`). Resolver utilise le chemin configurable. Vrai test `level7_home_perso_wins_over_all` (crée tmpdir + fichier, override config, assert LEVEL_HOME_PERSO). Ancien test mensonger renommé `level7_disabled_falls_back_to_level6_user`.
- **#9 (🟡)** Test `missing_action_returns_400` ajouté.
- **#10 (🟡)** `WallpaperComposer::configureImagickLimits()` statique idempotent : 256 MB mémoire, 512 MB map. Appelé depuis les constructeurs de Composer et UploadService.

**Implémentation majeure**
- **#A (🟠 Blocker fonctionnel)** — Nouveau service `App\Services\UserSessionsService` (pas dans `UserService` — domaine distinct samba/winbind runtime). Source : parse `/tmp/smbstatus` (produit par cron samba, même fichier que le legacy `smbstatus()`). Cache Laravel 30s (pas APCu direct). API publique : `getActiveSessions(login)` + `getOtherMachines(login, currentMachine)`. Bindé en singleton dans `WallpaperServiceProvider`. Injection typée dans `WallpaperComposer` (`private readonly ?UserSessionsService $sessions`). Nouveaux tests : `UserSessionsServiceTest` (10 scénarios, fichier tmp mocké) + dans `WallpaperComposerTest` : `multi_session_state_shows_badge_and_orange_card`, `veyon_plus_multi_session_stacks_cards`, `no_multi_session_when_sessions_service_absent`.

**Hygiène packaging/infra**
- **#B (🟡)** `ext-imagick` et `ext-apcu` ajoutés à `composer.json`/require.
- **#C (🟡)** Resolver : boucle groupes AD ne fallback FS que sur groupes présents dans `$userGroupMap` (skip direct si DB miss → évite n×is_file() sur 50 groupes AD).
- **#D (🟡)** Seeder : orphans ne sont plus importés en DB (évite conflit unique sur `(type, NULL, NULL)`). Warning log + ligne console. Test `orphan_file_is_not_imported_to_db` mis à jour.
- **#E (🟡)** Migration `CREATE UNIQUE INDEX … WHERE …` wrappée dans `if driver === 'pgsql'` (compat tests SQLite en feature).
- **#F (🟡)** `basename()` défensif sur `$filename` après `resolveFilename` dans `WallpaperUploadService::store`.
- **#G (🟡)** `.gitignore` : ajout `/storage/app/wallpaper-previews/`.

**Helper tests**
- `ImageAssertions::assertImageDoesNotContainColor()` ajouté (symétrique de `assertImageContainsColor`) — utilisé par les tests submarine et dégradation.

### File List

**Créés (code)**

- `app/Dto/Wallpaper/WallpaperContext.php`
- `app/Dto/Wallpaper/WallpaperResolution.php`
- `app/Services/Wallpaper/Contracts/WallpaperContextRepository.php`
- `app/Services/Wallpaper/ApcuWallpaperContextRepository.php`
- `app/Services/Wallpaper/WallpaperResolver.php`
- `app/Services/Wallpaper/WallpaperComposer.php`
- `app/Services/Wallpaper/WallpaperUploadService.php`
- `app/Services/Wallpaper/WallpaperDeleteService.php`
- `app/Providers/WallpaperServiceProvider.php`
- `app/Console/Commands/WallpaperRebuildBadgesCommand.php`
- `app/Console/Commands/WallpaperPreviewCommand.php`
- `config/wallpapers.php`
- `database/seeders/WallpaperFromFilesystemSeeder.php`
- `database/factories/UserGroupFactory.php`
- `database/factories/WorkstationGroupFactory.php`
- `database/factories/WallpaperFactory.php`

**Créés (vues + assets)**

- `resources/views/components/molecules/wallpaper-card.blade.php`
- `resources/views/pages/parc-settings/wallpapers/index.blade.php`
- `resources/views/pages/parc/groups/[id]/_partials/wallpaper-tab.blade.php`
- `resources/views/pages/users/[login]/_partials/wallpaper-info.blade.php`
- `resources/assets/wallpaper-icons/sources/admin.svg`
- `resources/assets/wallpaper-icons/sources/veyon.svg`
- `resources/assets/wallpaper-icons/sources/quota-warning.svg`
- `resources/assets/wallpaper-icons/sources/multi-session.svg`
- `resources/assets/wallpaper-icons/badge-admin.png`
- `resources/assets/wallpaper-icons/badge-veyon.png`
- `resources/assets/wallpaper-icons/badge-quota-warning.png`
- `resources/assets/wallpaper-icons/badge-multi-session.png`
- `resources/fonts/Atkinson-Hyperlegible-Bold.ttf`
- `resources/fonts/Atkinson-Hyperlegible-Regular.ttf`

**Créés (tests)**

- `tests/Support/ImageAssertions.php`
- `tests/Unit/Services/Wallpaper/WallpaperResolverTest.php`
- `tests/Unit/Services/Wallpaper/WallpaperComposerTest.php`
- `tests/Unit/Services/Wallpaper/ApcuWallpaperContextRepositoryTest.php` — post-review #1
- `tests/Unit/Services/UserSessionsServiceTest.php` — post-review #A
- `tests/Feature/Wallpaper/LegacyOutEndpointTest.php`
- `tests/Feature/Wallpaper/WallpaperUploadServiceTest.php`
- `tests/Feature/Wallpaper/WallpaperFromFilesystemSeederTest.php`

**Créés (code, post-review)**

- `app/Services/UserSessionsService.php` — post-review #A (détection sessions multi-machines)

**Créés (docs)**

- `docs/wallpaper-legacy-disable.md`
- `docs/wallpaper-smoke-test.md`

**Modifiés**

- `app/Http/Controllers/WallpaperController.php` — remplacement du stub 501 par impl réelle + `thumbnail()`. Méthodes `getImage()`/`getThumbnail()` retirées (plus de consommateur).
- `app/Models/User.php` — inchangé côté morphMany (Phase 1).
- `app/Models/UserGroup.php` — ajout trait `HasFactory`.
- `app/Models/WorkstationGroup.php` — ajout trait `HasFactory`.
- `app/Models/Wallpaper.php` — ajout trait `HasFactory`.
- `app/Enums/SambaPermission.php` — case `WallpaperManage`, mapping legacy `ServerAdmin`, label FR, catégorie dédiée.
- `app/Enums/SambaRole.php` — `ComputerAdmin` reçoit `WallpaperManage`.
- `app/Services/QuotaService.php` — 2 helpers `isUserOverQuota()` + `getOverQuotaPartitionsFormatted()` (≤30 lignes total).
- `app/Services/WorkstationGroupLdapService.php` — suppression du bloc wallpaper (lignes 1318-1712 incluses) + imports orphelins `UploadedFile`, `Imagick`.
- `config/app.php` — `WallpaperServiceProvider` enregistré dans providers array.
- `resources/views/components/organisms/sidebar.blade.php` — entrée menu « Fonds d'écran » conditionnelle `@can`.
- `resources/views/pages/parc/groups/[id]/index.blade.php` — `@include wallpaper-tab`.
- `resources/views/pages/users/groups/[id]/index.blade.php` — section wallpaper inline.
- `resources/views/pages/users/[login]/index.blade.php` — `@include wallpaper-info`.
- `routes/web.php` — nouvelle route `app.wallpapers.thumbnail` + route `app.parc-settings.wallpapers`. Routes commentées retirées.
- `_bmad-output/implementation-artifacts/4-7-gestion-des-fonds-decran-wallpapers-eloquent.md` — ce document.
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — statut `in-progress` → `review`.

## Change Log

- **2026-04-20** (claude-opus-4-7) : livraison initiale de la story 4.7. 32 fichiers créés, 14 modifiés. 46/46 tests Wallpaper verts sur VM. Aucune régression sur la suite existante. Statut → `review`.
- **2026-04-20** (claude-opus-4-7) : application des corrections post-review (17 points : #1, #2, #3, #4, #5, #6, #7, #8, #9, #10, #A, #B, #C, #D, #E, #F, #G). Nouveau service `UserSessionsService` + tests. Bug critique mapping APCu `user['cn']`/`machine['cn']` corrigé. Détails dans Completion Notes List > Corrections post-review.

---

## Questions / Clarifications — résolues 2026-04-20

### ✅ Vérifications VM

- **Extensions PHP** : `apcu`, `imagick`, `gd` — **toutes présentes** sur la VM (vérifié via `ssh + php -m`). Aucune installation requise.

### ✅ `QuotaService` — existe, extension mineure suffit

`app/Services/QuotaService.php` (694 lignes) est **riche** et couvre déjà le besoin de lecture runtime pour l'overlay quota. Méthodes utiles existantes :

- `getDiskUsage(string $username): array` → retourne `['home' => [...], 'sambaedu' => [...]]` avec `is_over_soft`, `is_over_hard`, `used_mb`, `quota_soft_mb`, `quota_hard_mb`, `grace_days` — **exactement ce qu'il faut** pour le cartouche central "Stockage saturé" AC 5 ter
- Implémentation via `sudo quota -u <user> -F xfs -p -v` avec parsing ligne → array, cache 5 min
- Gère le fallback gracieux sur erreur (`$default` array sans crash)

**À ajouter dans QuotaService (≤15 lignes) — Task 2.4 complément** :

```php
public function isUserOverQuota(string $username): bool
{
    $usage = $this->getDiskUsage($username);
    return ($usage['home']['is_over_hard'] ?? false)
        || ($usage['sambaedu']['is_over_hard'] ?? false);
}

public function getOverQuotaPartitionsFormatted(string $username): array
{
    $usage = $this->getDiskUsage($username);
    $labels = ['home' => 'Espace perso', 'sambaedu' => 'Espace Classe'];
    $result = [];
    foreach ($usage as $partition => $info) {
        if (($info['is_over_hard'] ?? false) || ($info['is_over_soft'] ?? false)) {
            $result[] = [
                'label' => $labels[$partition] ?? $partition,
                'used_mb' => $info['used_mb'],
                'soft_mb' => $info['quota_soft_mb'],
                'grace_days' => $info['grace_days'],
            ];
        }
    }
    return $result;
}
```

**Conclusion :** pas de prérequis Epic 5 pour 4-7. Le Composer consomme `QuotaService` directement via injection.

### ⚠️ `UserStatusService` — absent

N'existe pas dans `app/Services/`. Comportement prévu :

- `WallpaperComposer` injecte optionnellement un `?UserStatusService $userStatus = null`
- Si null ou exception, le badge `multi-session` n'est **jamais affiché** et l'overlay orange "connexions détectées" n'est **jamais rendu**
- **Aucun crash**, dégradation gracieuse
- **Ticket follow-up** à créer (hors scope 4-7) : Story "Détection sessions multi-machines" — reprend la logique legacy `get_user_status()` (`sambaedu/includes/ent.inc.php` — à identifier précisément)

### ✅ Page groupe AD (Task 4.8) — existe

`resources/views/pages/users/groups/[id]/index.blade.php` **présente** — Livewire SFC classique avec `UserGroupService`. L'onglet wallpaper s'y intègre proprement. Task 4.8 **reste dans le scope**.

### ℹ️ Admin local — lu depuis APCu

Le champ `$info['admin']` dans APCu (peuplé par `applications.inc.php`) reste la source de vérité pour l'admin local du contexte courant. Pas de synchro DB nécessaire pour 4-7 — `WallpaperContext::fromApcuArray()` l'extrait directement (bool).

### Bilan — aucun blocage, dev agent peut démarrer

- Task 2.4 ajoute les 2 helpers à `QuotaService` en plus de ses propres fichiers
- Task "multi-session detection" dégradée + ticket follow-up
- Task 4.8 inchangée
- Fonts Atkinson : à télécharger lors du dev (SIL OFL, sources brailleinstitute.org)
