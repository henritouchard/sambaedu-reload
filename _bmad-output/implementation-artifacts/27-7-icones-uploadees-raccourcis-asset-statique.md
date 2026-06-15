# Story 27.7 : Icônes uploadées de raccourcis — livraison native par asset statique servi par Apache

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'**admin d'établissement**,
je veux **que les raccourcis dont l'icône a été uploadée s'affichent avec leur icône réelle sur le poste (plus de « feuille blanche »)**,
afin que **le handler natif (27.1) atteigne la parité avec le canal legacy AVANT son extinction (27.6) — par le bon modèle (asset content-addressed servi en direct), pas par un script de logon**.

## Contexte & intention

**Spin-off de l'Epic 27.1.** Le handler raccourcis natif (27.1, agent 2.2.1) converge le bureau et
gère les icônes au format **chemin Windows réel** (`firefox.exe,0`, `%APPDATA%\x.ico`) — la convention
`chemin,index` est parsée depuis 2.2.1 (`shared.ParseIconLocation`, commit `2c96e9e`). **MAIS** il ne
gère PAS les **icônes UPLOADÉES**. C'est un trou de parité découvert au **test lab du 2026-06-15**.

**Le gap, précisément (origine terrain) :**
- Quand un admin uploade une icône, `ImageManagerService::handleIconUpload()` **retourne le nom NU du
  raccourci** (`return $name;` — `app/Services/ImageManagerService.php:54`). `ShortcutsService::saveShortcutWithIcon()`
  range ce nom nu dans `windows_icon`. En base, `shortcuts.windows_icon`/`icon_path` vaut donc
  `Calculatrice`, `vivaldi` — **pas un chemin**.
- Le `.ico` réel vit **côté serveur**, **name-addressed**, dans `/etc/sambaedu/applications/shortcuts/<name>.ico`
  (`ShortcutsService::$iconsPath`). Vérifié au lab : `Calculatrice.ico` (67 Ko) existe.
- `ShortcutsStateProvider::payloadFor()` (`app/Services/Agent/Providers/ShortcutsStateProvider.php:189`)
  émet `windows_icon ?? icon_path` **BRUT** → l'agent pose un `IconLocation` irrésoluble (`Calculatrice`)
  → Windows cherche un fichier nommé « Calculatrice », introuvable → **icône « feuille blanche »**.

**Legacy de référence (à LIRE, ne PAS recâbler) :** `ShortcutCompilerService::generateWindowsLnk()`
(`app/Services/ShortcutCompilerService.php:175-189`) détectait un **nom nu** par la regex
« pas de séparateur `\ / . , %` » (`!preg_match('#[\\\\/.,%]#', $icon)`) et réécrivait l'IconLocation
vers `%userprofile%\AppData\Local\Temp\<name>.ico`, le `.ico` étant **téléchargé par le script de logon
legacy** dans `%temp%`. **Le natif doit faire l'équivalent SANS le canal legacy** : c'est l'objet de
cette story.

**Ce que cette story livre :**
- **Serveur (asset statique, PAS un endpoint Laravel)** : à l'upload, le `.ico` est **content-addressed**
  (`<sha256>.ico`) et déposé dans un **dossier dédié servi en direct par un Alias Apache** scopé
  (`-Indexes`, lisible par www-admin/Apache). Filename + checksum **persistés** (calque `WallpaperAsset`).
  Une **commande de backfill** convertit les icônes EXISTANTES (name-addressed → content-addressed).
- **Provider** : `payloadFor()` distingue les deux cas — **nom nu** (icône uploadée, regex iso-legacy) →
  émet `{icon_asset, icon_checksum}` (filename content-addressed + SHA-256) ; **chemin réel** (`firefox.exe,0`)
  → émet `icon` brut comme aujourd'hui. Les deux coexistent dans le payload `shortcuts`.
- **Agent Go** : un **GET HTTP simple** (pas le Client token'd — c'est un blob public-safe) sur l'URL
  **dérivée de `server_url` + le chemin statique connu**, vérification SHA-256 AVANT écriture, dépôt
  local content-addressed, puis `IconLocation` du `.lnk` pointé sur le fichier local. Calque `assets.go`
  (sync content-addressed, checksum-vérifié, idempotent) — mais transport HTTP statique au lieu du
  token'd `/api/v1/agent/assets/wallpaper/`.
- **Contrat (évolution MINEURE, forward-compatible)** : le payload `shortcuts` gagne `{icon_asset,
  icon_checksum}` (champs AJOUTÉS — l'agent forward-compatible les accepte) → bump **SCIEMMENT** du
  golden `state.v1.json` + du hash figé `FROZEN_STATE_HASH` (PHP `ContractV1Test`) et son **jumeau Go**
  (`hasher_test.go::frozenStateHash`). Itéré sans URL au payload (iso décision contrat 24.4 « pas de
  champ url » — l'agent DÉRIVE l'URL).

**Ce que cette story N'EST PAS :**
- Le décommissionnement du canal legacy raccourcis (`ShortcutCompilerService`, script de logon, route
  export) — il meurt **en bloc** en **27.6**. Ici **ZÉRO retrofit legacy** : on construit à côté.
- **La migration du canal wallpaper vers ce modèle** : décision SÉPARÉE (feature wallpaper déjà livrée,
  son transport token'd `AssetController` reste). Cette story ne touche **QUE les icônes de raccourcis**.
- Les icônes de raccourcis **Linux** (`.desktop`) côté agent : l'agent Go est Windows (project_agent_runtime_go).
  Le provider lit les règles indifféremment de l'OS ; le handler Windows matérialise l'`IconLocation`.

**Position dans l'Epic 27 — GATE DE PARITÉ :** cette story est un **prérequis à livrer AVANT 27.6**
(extinction legacy). Tant que les icônes uploadées affichent une feuille blanche en natif, le canal
legacy raccourcis ne peut pas s'éteindre sans régression visible côté parc (NFR « parité = compétences à
terminaison », project_no_legacy_transition_state). 27.7 ferme ce trou.

## ⚠️ Pièges & tensions découverts à l'analyse (lire avant de coder)

1. **GARDE-FOU SÉCURITÉ CRITIQUE — l'Alias Apache NE doit JAMAIS pointer sur `storage/` entier.**
   `storage/keys/pki/` contient le **PFX de code-signing + les clés CA**. L'Alias doit pointer
   **EXACTEMENT** sur le sous-dossier d'icônes (ex. `storage/app/shortcut-icons/`), JAMAIS sur un parent.
   `Options -Indexes` (dossier non énumérable). `chown`/ACL lisibles par l'utilisateur Apache
   (`www-admin`, uid 599 — project_php_fpm_user_www_admin). Seuls des **blobs public-safe** (icônes
   `.ico`/`.png`) entrent dans ce dossier. C'est l'invariant n° 1 de la review : tout autre chemin servi
   en direct = faille. **Vérifier le `Alias` ET le bloc `<Directory>` à chaque relecture du vhost.**

2. **Le content-addressing : à l'UPLOAD, pas à la compilation.** Tenté n° 1 (rejeté) : le provider hashe
   le `.ico` à la volée dans `payloadFor()` — **coûteux au render** (lecture disque + SHA-256 par
   raccourci, à chaque `GET /state`), viole l'invariant perf des providers (lecture seule, pas d'I/O
   lourde). **Retenu** : `handleIconUpload()` produit aussi `<sha>.ico` dans le dossier servi + **persiste
   filename + checksum** en base (colonnes dédiées sur `shortcuts`), calque `WallpaperAsset`. Le provider
   ne fait qu'une **lecture de colonne**. Conséquence : les icônes **EXISTANTES** (name-addressed
   `<name>.ico`) doivent être **backfillées** (commande artisan, calque `WallpaperLibraryBackfiller`),
   sinon elles restent invisibles au provider tant qu'aucun ré-upload.

3. **Distinguer « nom nu » de « chemin réel » exactement comme le legacy.** Le provider doit reproduire
   la détection legacy `!preg_match('#[\\\\/.,%]#', $icon)` (pas de séparateur `\ / . , %` = nom nu =
   icône uploadée). Un faux positif (un chemin pris pour un nom nu) émettrait un `icon_asset` introuvable ;
   un faux négatif (un nom nu pris pour un chemin) reproduit le bug actuel. **Réutiliser la MÊME regex que
   le legacy** (traçabilité) — la documenter dans le provider. Cas limite : un raccourci dont l'icône
   uploadée n'a PAS encore d'asset backfillé (`icon_asset` null en base) → tomber en `icon` brut (ancien
   comportement) plutôt qu'émettre un asset cassé.

4. **Payload SANS URL — l'agent DÉRIVE l'URL** (iso décision contrat 24.4). Le payload porte
   `{icon_asset, icon_checksum}` (filename content-addressed + SHA-256), JAMAIS l'URL complète. L'agent
   construit `server_url + "/<chemin statique connu>/" + icon_asset`. Ne PAS ajouter de champ `url` (ne
   pas casser la symétrie du contrat). Évolution MINEURE forward-compatible (champs ajoutés).

5. **Contrat figé = bump SCIEMMENT, croisé PHP↔Go.** Le golden `state.v1.json` + `FROZEN_STATE_HASH`
   (PHP `ContractV1Test`) + son jumeau Go (`hasher_test.go::frozenStateHash`) DOIVENT être bumpés
   ensemble. Si l'item `shortcuts` du golden gagne `icon_asset`/`icon_checksum`, le hash change. Le test
   croisé Go prouve l'accord serveur/agent (NFR13). **Documenter le bump** (évolution mineure, contrat §9).
   ⚠️ Attention au **hash conjoint** : le `FROZEN_STATE_HASH` courant couvre shortcuts + debug (cf. review
   27.1 « bundle assumé »). Relever la valeur courante du working tree au début du dev, ne pas présumer.

6. **Transport HTTP statique = GET simple, PAS le Client token'd.** L'agent wallpaper télécharge via le
   `Client` (token, throttle, rotation D5). Ici, blob public-safe servi par Apache → **GET HTTP simple**
   (un `http.Get` ou un client minimal sans token). Le checksum content-addressed EST la garantie
   d'intégrité (un contenu divergent n'entre jamais dans le cache). Distinguer clairement de `assets.go`
   (qui, lui, RESTE token'd pour wallpaper). Décider : réutiliser `assets.go` paramétré (token optionnel)
   OU un nouveau fichier `icon_assets.go` dédié. **Recommandation : fichier dédié** (le transport diffère,
   ne pas tordre `assets.go`). À trancher au design (cf. sous-décision C).

7. **Convergence : icône manquante = NE PAS poser une icône cassée.** Un raccourci à icône uploadée est
   `compliant` seulement si le `.ico` local est présent + checksum OK ET l'`IconLocation` pointe dessus.
   Asset absent localement / checksum KO → drift + re-sync au passage suivant, JAMAIS une `IconLocation`
   irrésoluble (régression « feuille blanche »), JAMAIS une erreur qui bloque les AUTRES raccourcis
   (isolation par item — réutiliser le `Blocked()`/skip de 27.1). Si l'asset ne se télécharge pas
   (404/réseau), le raccourci doit quand même être posé (sans icône, ou icône défaut) plutôt que rien.

8. **Le pré-download tourne en SYSTEM ; le handler `shortcuts` est scope `machine_user`/compagnon
   (droits user).** Le compagnon (qui pose les `.lnk`) n'a ni token ni réseau (partition 24.3) — MAIS le
   transport étant un GET HTTP statique **sans token**, le compagnon PEUT télécharger directement (pas
   besoin du pré-download SYSTEM, contrairement au wallpaper token'd). **Deux options** (sous-décision D) :
   (a) pré-download SYSTEM iso-wallpaper (le plus propre, le cache est prêt avant la passe compagnon) ;
   (b) download dans la passe compagnon (possible car GET sans token). **Recommandation : pré-download
   SYSTEM** (cohérence avec `assets.go`, un seul endroit qui touche le réseau, cache content-addressed
   partagé). À trancher au design.

9. **Chemin local du `.ico` : persistant, lisible user.** `%PROGRAMDATA%\SambaEdu\Agent\icons\<sha>.ico`
   (persistant, ACL Users:R via `setAssetsACL`) plutôt que `%temp%` (legacy). Réutiliser le store
   (`AssetPath`/`EnsureAssetsDir`/`AssetsACL` — `agent/shared/sessionstore.go`) ou ajouter un
   `IconPath()`/`EnsureIconsDir()` parallèle. L'`IconLocation` du `.lnk` pointe sur ce chemin local
   absolu (substitution faite par le handler Windows, comme `desktop_path`).

10. **VM : nouvelle config (config/apache) + migration + dossier servi.** L'Alias Apache vit hors-git
    (provisioning, `/etc/apache2/sites-enabled/sambaedu.conf` sur la VM) — à **documenter** dans le repo
    (`config/apache/sambaedu.conf` est le fallback versionné) ET reporter en provisioning. Migration des
    colonnes `icon_*` → **à jouer sur /vm** (`migrate:status` avant e2e — project_vm_migrations_not_auto_applied).
    Le dossier `storage/app/shortcut-icons/` doit exister + chown www-admin sur la VM. `inotify` sync le
    code mais PAS la config Apache ni les dossiers storage — provisioning manuel.

11. **`storage/` non versionné mais client-facing = exception assumée.** Mémoire projet
    (project_storage_convention_non_versioned) : assets sous `storage/*`, exceptions client-facing servies
    en direct (SYSVOL, wpkg, /os, /ipxe). Les icônes raccourcis servies par Apache rejoignent CETTE liste
    d'exceptions — `storage/app/shortcut-icons/` est non versionné mais servi. Documenter l'exception.

## Décisions de design — TRANCHÉES PAR HENRI le 2026-06-15 (ne pas re-trancher en dev)

> Les 5 décisions ci-dessous sont ACTÉES. Elles sont DÉFINITIVES pour 27.7.

1. **Transport = Apache STATIQUE, PAS un endpoint Laravel.** Les icônes (blobs non sensibles) sont
   servies en direct par un **Alias Apache** vers un dossier dédié (pattern projet : `Alias /ipxe
   /var/www/sambaedu/ipxe`, idem /os /wpkg). L'agent fait un **GET HTTP simple** (pas le Client token'd),
   vérifie le checksum, écrit localement. Rationale : un `.ico` est public-safe, le token'd serait du
   sur-engineering ; le content-addressing + checksum garantit l'intégrité.

2. **Content-addressed** : le fichier servi est nommé par son SHA-256 (`<sha256>.ico`). `Options -Indexes`
   → dossier non énumérable (pas un secret de toute façon). Un fichier présent porte déjà le bon contenu
   (son nom EST son checksum) → idempotent, jamais re-téléchargé.

3. **GARDE-FOU SÉCURITÉ CRITIQUE** : l'Alias pointe EXACTEMENT sur le sous-dossier d'icônes, JAMAIS sur
   `storage/` entier (`storage/keys/pki/` = PFX code-signing + clés CA). Dossier dédié, alias scopé,
   `-Indexes`, chown lisible Apache. Seuls des blobs public-safe dedans. (cf. piège n° 1.)

4. **Payload SANS URL** : le payload `shortcuts` gagne `{icon_asset, icon_checksum}` (filename
   content-addressed + SHA-256). L'agent DÉRIVE l'URL depuis `server_url` + le chemin statique connu
   (iso décision contrat 24.4 « pas de champ url »). Évolution MINEURE du contrat (champs ajoutés,
   forward-compatible) → bump SCIEMMENT du golden + hash figé (PHP `FROZEN_STATE_HASH` + jumeau Go).

5. **Hors-scope explicite** : NE PAS migrer le canal wallpaper vers ce modèle (décision séparée, feature
   déjà livrée). Cette story ne touche QUE les icônes de raccourcis.

## Sous-décisions instruites (défauts proposés — « à confirmer Henri » si vrai fork)

> Ces points ne sont PAS tranchés par Henri ; le SM propose un défaut argumenté. Le dev les applique sauf
> contre-indication. Les marqués **[à confirmer Henri]** sont des forks où l'avis d'Henri est souhaité.

- **A — Où content-adresser : à l'UPLOAD (recommandé).** `handleIconUpload()` produit aussi `<sha>.ico`
  dans le dossier servi + persiste filename/checksum en base (calque `WallpaperAsset`). Le provider ne
  fait qu'une lecture de colonne (zéro I/O lourde au render — invariant perf). **Backfill requis** pour
  les icônes existantes (sous-décision E). *Défaut retenu, pas un fork.*

- **B — Emplacement du dossier servi + Alias.** **`storage/app/shortcut-icons/`** (content-addressed) +
  Alias **`/assets/shortcut-icons/`**. Cohérent avec la convention storage non-versionné + client-facing.
  **[à confirmer Henri]** — le nom de route/alias et le sous-dossier exact (vérifier qu'aucun Alias
  `/assets` plus large n'existe déjà ; le vhost actuel n'a que `/ipxe`).

- **C — Réutiliser `assets.go` ou fichier dédié.** **Fichier dédié `agent/shared/icon_assets.go`**
  (recommandé) : le transport diffère (GET HTTP statique sans token vs Client token'd wallpaper). Tordre
  `assets.go` pour le rendre token-optionnel mélangerait deux contrats de transport. *Défaut retenu.*

- **D — Pré-download SYSTEM vs passe compagnon.** **Pré-download SYSTEM** (recommandé, iso `assets.go`) :
  un seul endroit touche le réseau, cache content-addressed prêt avant la passe compagnon, cohérence
  architecturale. Techniquement la passe compagnon POURRAIT télécharger (GET sans token) — mais on garde
  la partition « réseau = SYSTEM ». *Défaut retenu ; à challenger en review si le SYSTEM complexifie.*

- **E — Backfill name→content-addressed.** Commande artisan `php artisan shortcuts:backfill-icons` (calque
  `WallpaperLibraryBackfiller` + `WallpaperPreviewCommand`) : scanne `/etc/sambaedu/applications/shortcuts/*.ico`,
  pour chaque `<name>.ico` résout le(s) `shortcut(s)` dont `windows_icon`/`icon_path` == `<name>`, copie le
  `.ico` en `<sha>.ico` dans le dossier servi (dédup checksum), persiste filename/checksum. Idempotent
  (dédup checksum + `firstOrCreate`-like). Fichiers legacy COPIÉS (jamais supprimés) — rollback-safe.
  *Défaut retenu.*

- **F — Convergence si asset indisponible.** Asset local absent/checksum KO → le raccourci est posé SANS
  `IconLocation` (icône défaut Windows), reporté `drift` (re-sync au passage suivant), JAMAIS d'erreur qui
  bloque les autres raccourcis ni d'`IconLocation` irrésoluble. *Défaut retenu (cf. piège n° 7).*

## Acceptance Criteria

### AC1 — Icône uploadée content-addressed à l'upload + colonnes persistées (sous-décision A)

**Given** un admin uploade une icône pour un raccourci (`ShortcutsController` `icon_file` →
`ShortcutsService::saveShortcutWithIcon` → `ImageManagerService::handleIconUpload`)
**When** l'icône est traitée
**Then** un fichier **content-addressed `<sha256>.ico`** est déposé dans le dossier servi
(`storage/app/shortcut-icons/`, sous-décision B), chown lisible Apache, et le `shortcut` persiste son
**filename content-addressed + checksum SHA-256** dans des colonnes dédiées (ex. `icon_asset`,
`icon_checksum`) — calque `WallpaperAsset`
**And** le comportement legacy existant (`<name>.ico`/`<name>.png` dans `$iconsPath` pour l'UI) **n'est
pas cassé** (l'UI continue d'afficher l'aperçu) — ajout, pas remplacement.

### AC2 — Le provider distingue nom nu (uploadée) de chemin réel (sous-décision A, piège n° 3)

**Given** un raccourci `place=desktop|startup|taskbar` avec une icône
**When** `ShortcutsStateProvider::payloadFor()` compile
**Then** si l'icône est un **nom nu** (regex iso-legacy `!preg_match('#[\\\\/.,%]#', $icon)`) ET un asset
content-addressed existe pour ce raccourci → le payload porte **`{icon_asset, icon_checksum}`** (PAS d'URL,
décision n° 4) ; si l'icône est un **chemin réel** (`firefox.exe,0`, `%APPDATA%\x.ico`) → le payload porte
**`icon` brut** comme aujourd'hui (régression zéro pour ce cas)
**And** un nom nu SANS asset backfillé (`icon_asset` null) tombe sur `icon` brut (ancien comportement),
JAMAIS un asset cassé (piège n° 3) ; lecture Postgres pure, zéro AD/APCu, zéro I/O lourde au render.

### AC3 — Asset servi en direct par Apache, alias scopé, garde-fou sécurité (décisions n° 1, n° 3)

**Given** un fichier `<sha>.ico` dans `storage/app/shortcut-icons/`
**When** un client fait `GET <server_url>/assets/shortcut-icons/<sha>.ico`
**Then** Apache sert le binaire **en direct** (Alias scopé, `Options -Indexes`, `Require all granted`),
**sans passer par Laravel/FPM** ; l'Alias pointe **EXACTEMENT** sur le sous-dossier d'icônes, **JAMAIS** sur
`storage/` ni un parent (garde-fou : `storage/keys/pki/` reste inaccessible)
**And** le vhost versionné (`config/apache/sambaedu.conf`) documente l'Alias + le `<Directory>` ; le
dossier est listé comme exception « storage non-versionné mais client-facing » (piège n° 11) ; le
provisioning VM (`/etc/apache2/sites-enabled/`, hors-git) est documenté dans les Dev Notes pour report.

### AC4 — Agent : GET HTTP simple, checksum vérifié, dépôt local, IconLocation pointée (décisions n° 1, n° 4 ; pièges n° 6, n° 7, n° 9)

**Given** un item `shortcuts` avec `{icon_asset, icon_checksum}`
**When** l'agent converge
**Then** l'agent **DÉRIVE l'URL** depuis `server_url` + le chemin statique connu, fait un **GET HTTP
simple** (PAS le Client token'd), **vérifie le SHA-256 = `icon_checksum` AVANT écriture**, dépose le `.ico`
en local content-addressed (`%PROGRAMDATA%\SambaEdu\Agent\icons\<sha>.ico`, ACL Users:R), puis pose
l'`IconLocation` du `.lnk` sur ce **chemin local absolu**
**And** asset présent + checksum OK + IconLocation pointée = `compliant` ; asset absent/checksum KO → le
raccourci est posé **sans IconLocation irrésoluble** (icône défaut), reporté `drift` (re-sync), JAMAIS
d'erreur qui bloque les autres raccourcis (isolation par item, réutilise `Blocked()`/skip de 27.1)
**And** idempotent (content-addressed : un fichier présent n'est jamais re-téléchargé) ; le download
réseau tourne en SYSTEM (sous-décision D) si retenu.

### AC5 — Backfill des icônes existantes name→content-addressed (sous-décision E)

**Given** des icônes legacy name-addressed (`/etc/sambaedu/applications/shortcuts/<name>.ico`) référencées
par des raccourcis dont `windows_icon`/`icon_path` == `<name>`
**When** l'admin lance `php artisan shortcuts:backfill-icons`
**Then** chaque `<name>.ico` est copié en `<sha>.ico` dans le dossier servi (dédup checksum), le(s)
raccourci(s) correspondant(s) reçoi(ven)t `icon_asset`/`icon_checksum` persistés ; les fichiers legacy
sont **COPIÉS, jamais supprimés** (rollback-safe) ; **idempotent** (dédup checksum + re-run no-op)
**And** la commande rapporte un résumé (`{assets, linked, missing}`) ; un raccourci dont le `.ico` legacy
est absent → `missing` loggé, pas d'échec.

### AC6 — Contrat figé : payload v1 étendu + bump golden + hash croisé (décision n° 4, piège n° 5)

**Given** le payload `shortcuts` étendu de `{icon_asset, icon_checksum}` (champs AJOUTÉS, forward-compatible)
**When** le golden `tests/Fixtures/Agent/state.v1.json` est mis à jour SCIEMMENT
**Then** le hash figé `FROZEN_STATE_HASH` (PHP `ContractV1Test`) ET son jumeau Go
(`hasher_test.go::frozenStateHash`) sont bumpés **ensemble**, le test croisé Go prouve l'accord
serveur/agent (NFR13) ; le bump est **documenté** (évolution mineure, contrat §9)
**And** la valeur courante du hash conjoint (shortcuts + debug, cf. review 27.1) est **relevée du working
tree au début du dev**, pas présumée ; `report.v1.json` ajusté si nécessaire.

### AC7 — Tests : Pest serveur + go test agent, baselines intactes (NFR13)

**Then** côté **Laravel** : test provider (nom nu → `{icon_asset, icon_checksum}` ; chemin réel → `icon`
brut ; nom nu sans asset → `icon` brut ; lecture seule, zéro AD) ; test du content-addressing à l'upload
(filename + checksum persistés) ; test de la commande backfill (dédup, idempotence, missing) ;
non-régression `--filter Agent` sur `/vm` (baseline relevée au début du dev)
**And** côté **agent Go** : test du download/sync icônes (checksum vérifié AVANT écriture, idempotence,
asset absent → pas d'IconLocation cassée), test handler (IconLocation pointée sur le chemin local, drift si
asset manquant) ; `go test ./...`, `go vet` (linux + `GOOS=windows`), cross-compile verts sur l'hôte ;
spécifique Windows validé cross-compile + lab humain
**And** golden files cohérents serveur (Pest) ET agent (`go test`) — tests croisés (NFR13).

### AC8 — Documentation + QA (append-only)

**Then** `docs/agent/state-providers.md` : section `shortcuts` enrichie (icône uploadée → `{icon_asset,
icon_checksum}`, transport statique, dérivation d'URL, distinction nom nu/chemin réel) ;
`docs/agent/handlers-wallpaper-overlay.md` ou un doc dédié : le transport statique icônes (distinct du
token'd wallpaper) ; `agent/README.md` : sync icônes raccourcis ; `config/apache/sambaedu.conf` documenté
**And** `docs/qa/domains/agent.md` enrichi **append-only** (nouvelle section sans renuméroter) : icône
uploadée UI→poste, content-addressing, backfill, garde-fou alias sécu, convergence si asset indisponible ;
ligne 27.7 dans `docs/qa/README.md`
**And** restent **INTOUCHÉS** : le canal legacy raccourcis (`ShortcutCompilerService`, script de logon,
route export), le canal wallpaper (token'd `AssetController` — hors-scope décision n° 5), le contrat §7
(`shortcuts` déjà figé), `engine.go::ResolveItemStatus`/`AggregateHash` (réutilisés).

## Tasks / Subtasks

- [x] **T1 — Schéma : colonnes content-addressed sur `shortcuts`** (AC1) — *sous-décision A*
  - [x] Migration idempotente `add_icon_asset_to_shortcuts` : colonnes `icon_asset` VARCHAR(72) **nullable**
        (`<sha256>.ico` = 64 hex + ext) + `icon_checksum` VARCHAR(64) **nullable**, `Schema::hasColumn`,
        `down()` symétrique, `->comment()` daté story. Style calqué sur les migrations 27.1 `add_mode_*`
        (varchar simple, compat SQLite — project_sqlite_tests_no_varchar_enforcement).
  - [x] `Shortcut` : `'icon_asset'`, `'icon_checksum'` dans `$fillable` + `@property` docblock (pas de cast
        spécial — strings).
  - [x] Appliquer sur la VM (`migrate --force` après `migrate:status`) — project_vm_migrations_not_auto_applied.

- [x] **T2 — Content-addressing à l'upload** (AC1) — *sous-décision A*
  - [x] `ImageManagerService::handleIconUpload()` (ou un service dédié appelé par lui) : en plus du
        `<name>.ico`/`<name>.png` legacy (PRÉSERVÉS pour l'UI), produire `<sha256>.ico` dans le dossier
        servi (sous-décision B) ; calculer le SHA-256 ; retourner filename + checksum.
  - [x] `ShortcutsService::saveShortcutWithIcon()` : persister `icon_asset` (= `<sha>.ico`) + `icon_checksum`
        sur le raccourci, en plus de `windows_icon` (= nom nu, INCHANGÉ — l'UI et le legacy s'en servent).
  - [x] Dossier servi : créer `storage/app/shortut-icons/` (config `shortcut_icons.path` ?), chown
        www-admin. Vérifier qu'on ne dépose JAMAIS hors de ce dossier (garde-fou n° 3).

- [x] **T3 — Provider : distinction nom nu / chemin réel** (AC2) — *piège n° 3*
  - [x] `ShortcutsStateProvider::payloadFor()` : reproduire la détection legacy
        `!preg_match('#[\\\\/.,%]#', $icon)` (nom nu = icône uploadée). Documenter la regex (traçabilité
        `ShortcutCompilerService:187`).
  - [x] Nom nu + `icon_asset` non null en base → payload `{icon_asset, icon_checksum}` (PAS d'URL,
        décision n° 4). Nom nu sans asset → `icon` brut (ancien comportement). Chemin réel → `icon` brut.
        Les champs `icon`, `icon_asset`, `icon_checksum` coexistent (forward-compatible).
  - [x] Lecture des colonnes `icon_asset`/`icon_checksum` dans la requête `itemsFor()` (ajout au `get([...])`).

- [x] **T4 — Alias Apache + garde-fou sécurité** (AC3) — *décisions n° 1, n° 3 ; pièges n° 1, n° 10, n° 11*
  - [x] `config/apache/sambaedu.conf` (fallback versionné) : `Alias /assets/shortcut-icons
        /var/www/sambaedu-reload/storage/app/shortcut-icons` + bloc `<Directory>` (`Options -Indexes
        +FollowSymLinks`, `AllowOverride None`, `Require all granted`). **Scopé EXACTEMENT** sur le
        sous-dossier (jamais `storage/`). Pas de FallbackResource (pas de route Laravel).
  - [x] Documenter dans les Dev Notes le report sur la VM (`/etc/apache2/sites-enabled/sambaedu.conf`,
        hors-git) + `scripts/setupApache.sh` si présent (garder les deux en phase, cf. en-tête du vhost).
  - [x] Vérifier qu'aucun Alias `/assets` plus large n'existe et n'expose `storage/` (grep vhost).

- [x] **T5 — Agent Go : sync icônes + IconLocation** (AC4) — *décisions n° 1, n° 4 ; pièges n° 6, n° 8, n° 9*
  - [x] `agent/shared/icon_assets.go` (fichier dédié, sous-décision C) : `wantedShortcutIcons()` (parcourt
        les états en cache, collecte `{icon_asset, icon_checksum}` des items `shortcuts`, dédup, validation
        STRICTE filename/checksum) + `SyncShortcutIcons(cfg)` : **GET HTTP simple** sur
        `cfg.ServerURL + "/assets/shortcut-icons/" + icon_asset`, **SHA-256 vérifié AVANT écriture**, dépôt
        `IconPath(<sha>.ico)` content-addressed, idempotent. Calque `assets.go` MAIS sans token. Gestion
        404/réseau gracieuse (skip, retry au cycle suivant). Validateurs `ValidShortcutIconFilename`/réutiliser
        `ValidChecksum`.
  - [x] Store : `IconPath(filename)` + `EnsureIconsDir(acl)` (`agent/shared/sessionstore.go`, calque
        `AssetPath`/`EnsureAssetsDir`) ; ACL `setAssetsACL` réutilisée (Users:R).
  - [x] Branchement du pré-download SYSTEM (sous-décision D) : appeler `SyncShortcutIcons` au cycle du
        service + en fin de session-fetch, iso `SyncWallpaperAssets` (`main_windows.go`).
  - [x] `handler_shortcuts.go` / `handler_shortcuts_windows.go` : si l'item porte `icon_asset`, l'`IconLocation`
        du `.lnk` pointe sur le chemin LOCAL `IconPath(<sha>.ico)` (substitution faite côté handler) ; asset
        absent/checksum KO → pas d'`IconLocation` (icône défaut), drift (piège n° 7, sous-décision F). Le cas
        `icon` brut (chemin réel) reste inchangé (`ParseIconLocation`).

- [x] **T6 — Backfill artisan** (AC5) — *sous-décision E*
  - [x] Service `App\Services\Shortcuts\ShortcutIconBackfiller` (testable, calque `WallpaperLibraryBackfiller`) :
        scanne `$iconsPath/*.ico`, résout les raccourcis par nom nu, copie `<name>.ico` → `<sha>.ico` (dédup
        checksum), persiste `icon_asset`/`icon_checksum`. COPIE (jamais supprime). Idempotent. Stats
        `{assets, linked, missing}`.
  - [x] Commande `app/Console/Commands/ShortcutsBackfillIconsCommand.php` (`shortcuts:backfill-icons`) :
        appelant fin du service, affiche le résumé.

- [x] **T7 — Golden + bump hash figé** (AC6) — *piège n° 5*
  - [x] **Relever d'abord** la valeur courante de `FROZEN_STATE_HASH` (PHP) + `frozenStateHash` (Go) dans le
        working tree (hash conjoint shortcuts+debug). Mettre à jour `tests/Fixtures/Agent/state.v1.json`
        (item `shortcuts` avec `icon_asset`/`icon_checksum`) et `report.v1.json` si besoin ; bumper les DEUX
        hashes ensemble ; documenter (évolution mineure, contrat §9). Vérifier la cohérence Go (`go test` golden).

- [x] **T8 — Tests** (AC7)
  - [x] Pest : `ShortcutsStateProviderTest` (nom nu → asset/checksum ; chemin réel → icon ; nom nu sans
        asset → icon brut ; lecture seule, zéro AD) ; test upload content-addressing (filename+checksum
        persistés) ; `ShortcutIconBackfillerTest` (dédup, idempotence, missing). Non-régression
        `--filter Agent` /vm.
  - [x] Go : `icon_assets_test.go` (checksum vérifié AVANT écriture, idempotence, 404/réseau gracieux,
        validateur filename) ; `handler_shortcuts_test.go` ajout (IconLocation pointée sur chemin local,
        drift si asset manquant). `go test ./...`, `go vet` (linux+windows), cross-compile.

- [x] **T9 — Documentation + QA** (AC8)
  - [x] `docs/agent/state-providers.md` (section shortcuts enrichie), doc transport statique icônes,
        `agent/README.md`, `config/apache/sambaedu.conf` documenté, QA append-only `docs/qa/domains/agent.md`,
        ligne 27.7 `docs/qa/README.md`.

- [x] **T10 — Validation finale** (AC7)
  - [x] `php -l` sur les fichiers PHP ; grep critère Keycloak (`ldap|apcu|samba-tool`) sur le provider →
        vide ; grep « zéro retrofit legacy » (aucun fichier du canal legacy raccourcis NI du canal wallpaper
        token'd dans le diff) ; **grep garde-fou sécurité** : l'Alias ne contient JAMAIS `storage/$` ni un
        parent de `keys/pki`.
  - [x] `go test ./...` + `go vet` + cross-compile verts sur l'hôte ; `--filter Agent` /vm sans régression.
  - [x] **Validation lab (poste Windows) : ACTION HUMAINE (Henri)** — un raccourci à icône uploadée
        (`Calculatrice`) affiche son icône réelle sur le poste (plus de feuille blanche) ; backfill joué ;
        asset indisponible → icône défaut (pas de crash) ; garde-fou alias vérifié (`curl <url>/assets/shortcut-icons/`
        ne liste pas, `storage/keys/` non servi).

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (27.7) | Hors-scope (story) |
|---|---|
| Content-addressing des icônes uploadées à l'upload + colonnes `icon_asset`/`icon_checksum` | Migration du canal wallpaper vers ce modèle (décision n° 5 — token'd `AssetController` reste) |
| Provider : distinction nom nu (asset) / chemin réel (icon brut) | Décommissionnement canal legacy raccourcis → **27.6** |
| Alias Apache statique scopé + garde-fou sécurité | Icônes raccourcis **Linux** (`.desktop`) côté agent (agent = Windows) |
| Agent Go : sync GET HTTP simple checksum-vérifié + IconLocation locale | Refonte de `ParseIconLocation` / cas chemin réel (déjà livré 2.2.1, INCHANGÉ) |
| Backfill artisan name→content-addressed | Tout `ShortcutCompilerService` / script de logon / route export (INTOUCHÉS) |
| Golden + bump hash figé croisé PHP↔Go | Endpoint Laravel token'd pour les icônes (décision n° 1 = statique) |

### Le gap exact — fichiers à comprendre

[Source: app/Services/ImageManagerService.php:54] — `handleIconUpload()` `return $name;` → nom NU stocké.
[Source: app/Services/ShortcutsService.php:13-14,216-223,297-308] — `$iconsPath='/etc/sambaedu/applications/shortcuts/'`, `saveShortcutWithIcon` range le nom nu dans `windows_icon`.
[Source: app/Services/Agent/Providers/ShortcutsStateProvider.php:183-198] — `payloadFor()` émet `windows_icon ?? icon_path` BRUT (le bug).
[Source: app/Services/ShortcutCompilerService.php:175-189] — legacy de référence : détection nom nu `!preg_match('#[\\\\/.,%]#', $icon)` + réécriture `%temp%\<name>.ico` (NE PAS recâbler, reproduire l'équivalent natif).
[Source: app/Models/Shortcut.php:34,89] — `icon_path`, `mode` (27.1), `PLACE_*` ; `windows_icon` = chemin OU nom nu.

### Le pattern asset content-addressed — ce qu'on imite

[Source: app/Models/WallpaperAsset.php] — content-addressed `<sha>.ext`, `absolutePath` (défense anti-traversal F7), `libraryPath()` configurable sous `storage/`. Imiter pour les colonnes/dossier icônes.
[Source: app/Services/Wallpaper/WallpaperLibraryBackfiller.php] — backfill name→content-addressed, copie (jamais supprime), dédup checksum, stats `{assets, linked, missing}`. **Modèle direct du backfill icônes (sous-décision E).**
[Source: app/Http/Controllers/Api/V1/Agent/AssetController.php] — transport token'd wallpaper (ce qu'on NE fait PAS pour les icônes : on sert en STATIQUE Apache, décision n° 1). À LIRE pour le contraste, ne pas réutiliser.
[Source: agent/shared/assets.go] — sync content-addressed, checksum vérifié AVANT écriture, idempotent, 404/réseau gracieux. Calque pour `icon_assets.go` MAIS **sans token** (GET HTTP simple, décision n° 1).
[Source: agent/shared/sessionstore.go:69-73,182] — `AssetsDir()`/`AssetPath(filename)`/`EnsureAssetsDir(acl)` ; ajouter `IconPath`/`EnsureIconsDir` parallèles. `setAssetsACL` (agent/windows/acl_windows.go:71) réutilisée.
[Source: agent/shared/handler_wallpaper.go:23-28] — `ValidWallpaperAssetFilename` / `ValidChecksum` ; calquer un `ValidShortcutIconFilename`, réutiliser `ValidChecksum`.

### Le handler raccourcis 27.1 — ce qu'on étend (ne PAS réécrire)

[Source: agent/shared/handler_shortcuts.go] — `ShortcutSpec.Icon`, `ParseIconLocation` (chemin,index — cas chemin réel, INCHANGÉ), `Blocked()`/skip (isolation par item — réutilisé pour « asset manquant ne bloque pas les autres »). Ajouter le champ icon_asset au spec + la pose d'IconLocation locale.
[Source: agent/windows/handler_shortcuts_windows.go] — `createShortcut`/`setIconLocation` (COM IShellLink). Pointer l'IconLocation sur `IconPath(<sha>.ico)` quand `icon_asset` présent.
[Source: _bmad-output/implementation-artifacts/27-1-handler-raccourcis-convergence-bureau.md] — pattern provider/handler/golden, décisions tranchées, payload v1 `{name, target, args, icon, place, desktop_path}` (on AJOUTE icon_asset/icon_checksum).
[Source: _bmad-output/codeReviews/27-1.md] — review : isolation par item, hash conjoint shortcuts+debug (relever la valeur courante avant bump), homonyme `Blocked()`.

### Le contrat figé — bump croisé

[Source: docs/agent/contract-v1.md §3.2, §7, §8, §9] — payload owné par la story du provider (évolution mineure = champ ajouté forward-compatible) ; `shortcuts` figé §7 (PAS de nouvelle entrée) ; règle d'évolution §9.
[Source: tests/Fixtures/Agent/state.v1.json ; report.v1.json] — golden à faire évoluer (item shortcuts + icon_asset/icon_checksum).
[Source: tests/Unit/Services/Agent/ContractV1Test.php] — `FROZEN_STATE_HASH` à bumper.
[Source: agent/shared/hasher_test.go] — `frozenStateHash` jumeau Go à bumper (test croisé NFR13).
[Source: docs/agent/handlers-wallpaper-overlay.md §2] — « pas de champ url » (décision 24.4) reconduite : l'agent dérive l'URL.

### Le provisioning Apache — hors-git, à documenter

[Source: config/apache/sambaedu.conf:31-37] — `Alias /ipxe /var/www/sambaedu/ipxe` + `<Directory>` (`Options -Indexes`, `Require all granted`) = pattern EXACT à imiter pour `/assets/shortcut-icons`. En-tête du fichier : ce vhost est un FALLBACK ; le nominal passe par `scripts/setupApache.sh` — garder les deux en phase. Reporter l'Alias sur la VM (`/etc/apache2/sites-enabled/sambaedu.conf`, hors-git, inotify ne le sync pas).
- Mémoire projet : project_storage_convention_non_versioned (storage non-versionné, exception client-facing), project_php_fpm_user_www_admin (chown www-admin uid 599), project_ipxe_os_url_vs_script_url (assets servis hors FPM par alias).

### Project Structure Notes

- Migration → `database/migrations/2026_06_15_HHMMSS_add_icon_asset_to_shortcuts.php`.
- Modèle → `app/Models/Shortcut.php` (`icon_asset`, `icon_checksum` fillable + docblock).
- Upload → `app/Services/ImageManagerService.php` (ou service dédié) + `app/Services/ShortcutsService.php`.
- Provider → `app/Services/Agent/Providers/ShortcutsStateProvider.php` (`payloadFor()` + `itemsFor()` get).
- Backfill → `app/Services/Shortcuts/ShortcutIconBackfiller.php` + `app/Console/Commands/ShortcutsBackfillIconsCommand.php`.
- Apache → `config/apache/sambaedu.conf` (Alias + Directory scopés).
- Agent → `agent/shared/icon_assets.go` (+ test) ; `agent/shared/sessionstore.go` (`IconPath`/`EnsureIconsDir`) ;
  `agent/shared/handler_shortcuts.go` + `agent/windows/handler_shortcuts_windows.go` (IconLocation locale) ;
  branchement sync `agent/windows/main_windows.go`.
- Golden → `tests/Fixtures/Agent/state.v1.json` (+ `report.v1.json`) ; hash figé `ContractV1Test` + `hasher_test.go`.
- Doc → `docs/agent/state-providers.md`, `agent/README.md`, `docs/qa/domains/agent.md`, `docs/qa/README.md`.

### Environnement de dev — règles VM

- Code à la RACINE (app/, agent/, …) ; édité sur l'hôte, sync inotify auto, **jamais de sync manuelle**.
- **Go = hôte uniquement** (`~/go-toolchain/go/bin/go`, package main = `agent/windows`). Pest sur /vm.
- Migration `icon_asset` → **à jouer sur la VM** (`migrate:status` avant e2e — project_vm_migrations_not_auto_applied).
- **Apache + dossier servi + chown www-admin → provisioning manuel sur la VM** (inotify ne sync ni la
  config Apache ni les dossiers storage). `config:cache` non concerné (pas de `config/*.php` lu au runtime
  par le payload ; si `config/shortcut_icons.php` est ajouté → `config:cache` + chown — project_vm_config_cache_not_synced).
- Jamais d'interaction VM depuis un worktree git.

### Dépendances

| Story | Rôle pour 27.7 | Statut (sprint-status.yaml) | Bloquant ? |
|-------|----------------|------------------------------|------------|
| 27.1 — handler raccourcis + provider + golden | Base étendue (provider `payloadFor`, handler, golden, hash conjoint) | `review` | **Prérequis dur** — en review ; dev autorisé avec rebase si correctifs post-review |
| fix 2.2.1 — `ParseIconLocation` (commit `2c96e9e`) | Cas chemin réel `firefox.exe,0` (INCHANGÉ, on AJOUTE le cas uploadée) | livré (main) | Non (consommé) |
| 24.4/24.6 — asset wallpaper + sync content-addressed | Pattern `WallpaperAsset`/`assets.go`/`WallpaperLibraryBackfiller`/store assets imité | `done` | Non (consommé) |
| 23.1 — contrat v1 + golden + StateHasher | `shortcuts` figé §7, golden + hash croisé à faire évoluer | `done` | Non |
| **27.6 — extinction legacy** | **27.7 est une GATE de parité À LIVRER AVANT 27.6** | backlog | 27.7 bloque 27.6 |

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Epic 27] — pattern epic, extinction en bloc, parité.
- [Source: _bmad-output/implementation-artifacts/27-1-handler-raccourcis-convergence-bureau.md] — story de base (provider/handler/golden/décisions).
- [Source: _bmad-output/codeReviews/27-1.md] — review : isolation par item, hash conjoint, homonyme Blocked().
- [Source: commit 2c96e9e] — fix `ParseIconLocation` 2.2.1 (cas chemin réel).
- [Source: app/Services/ShortcutCompilerService.php:175-189] — legacy de référence (détection nom nu + réécriture temp).
- [Source: app/Services/ImageManagerService.php:54 ; app/Services/ShortcutsService.php] — origine du nom nu.
- [Source: app/Models/WallpaperAsset.php ; app/Services/Wallpaper/WallpaperLibraryBackfiller.php] — pattern content-addressed + backfill.
- [Source: agent/shared/assets.go ; agent/shared/sessionstore.go ; agent/shared/handler_wallpaper.go] — sync/store/validateurs à calquer (sans token).
- [Source: config/apache/sambaedu.conf:31-37] — pattern Alias statique scopé.
- [Source: docs/agent/contract-v1.md §3.2/§7/§8/§9 ; docs/agent/handlers-wallpaper-overlay.md §2] — contrat, « pas de champ url », règle d'évolution.

## Questions pour Henri

1. **Nom de l'Alias + sous-dossier servi** (sous-décision B) : défaut proposé `Alias /assets/shortcut-icons
   → storage/app/shortcut-icons`. Confirmer le nom de route et le chemin (aucun `/assets` plus large
   n'existe aujourd'hui — vérifié, seul `/ipxe`).
2. **Pré-download SYSTEM vs passe compagnon** (sous-décision D) : défaut = SYSTEM iso-wallpaper. OK, ou tu
   préfères le download dans la passe compagnon (possible car GET sans token) pour simplifier ?
3. **Fichier `icon_assets.go` dédié vs `assets.go` paramétré** (sous-décision C) : défaut = fichier dédié
   (transport différent). OK ?

## Recommandation Modèle Dev

**Recommandation : `fable`.**

Justification : conformément à la consigne projet (feedback_epic23_model_fable5 — pour les stories agent
desired-state, recommander **fable**, éviter le réflexe « contrat = petit modèle »), et parce que 27.7 est
une story de **suivi bien cadrée** qui REPRODUIT des patterns DÉJÀ établis et lus : content-addressed
(`WallpaperAsset`), backfill (`WallpaperLibraryBackfiller`), sync agent (`assets.go`), Alias Apache
(`/ipxe`), bump golden croisé (27.1). Aucune décision d'architecture n'est ouverte (les 5 d'Henri sont
actées, les sous-décisions ont des défauts argumentés). Le travail est multi-couches (provider PHP +
upload/backfill + Apache + agent Go + golden) MAIS chaque couche a un modèle iso-existant à calquer — le
raisonnement est de l'**exécution disciplinée**, pas de la conception. Les deux points qui demandent de la
rigueur — le **garde-fou sécurité de l'alias** (invariant binaire : scopé exactement, jamais `storage/`) et
le **bump croisé du hash conjoint** (relever la valeur courante, pas présumer) — sont **explicitement
balisés** dans les pièges et AC, donc à appliquer mécaniquement. `fable` convient ; la review adversariale
(opus en second avis, cf. dev-cycle-h) couvre le risque résiduel sécurité/contrat.

> Réserve : si le dev bute sur la partition SYSTEM/compagnon du transport (sous-décision D) ou sur la
> cohérence du hash conjoint, escalader vers `opus` pour ce point précis — mais le défaut reste `fable`.

## Dev Agent Record

### Dev model

`opus` (claude-opus-4-8) — `fable` indisponible.

### Decisions / écarts

- **Sous-décisions appliquées telles que recommandées** : A (content-adressage à l'upload + colonnes
  `icon_asset`/`icon_checksum`), B (Alias `/assets/shortcut-icons` → `storage/app/shortcut-icons`),
  C (fichier dédié `agent/shared/icon_assets.go`), D (pré-download SYSTEM iso-wallpaper, branché loop.go +
  sessionfetch.go), E (backfill artisan), F (convergence gracieuse : asset absent → `IconLocation` vide).
- **Transport GET sans token** : réutilise `Client.HTTP` (le `*http.Client` sous-jacent : timeout/transport)
  via `a.getStaticIcon()`, SANS la couche token/rotation du `Client` — un GET HTTP simple, conforme à la
  décision n° 1. Corps borné `LimitReader` 4 Mio.
- **Décision IconLocation factorisée en shared** : `ResolveUploadedIconLocation(iconAsset, iconsDir)`
  (logique pure stat + jointure) vit dans `handler_shortcuts.go` (testée hôte) ; le câblage Windows
  (`effectiveIcon`) l'appelle. Évite de laisser une décision métier non testable dans le fichier COM.
- **Persistance upload** : `saveShortcutWithIcon()` content-adresse le `.ico` ET met à jour le(s)
  raccourci(s) DB par nom nu (même résolution que le provider). Robustesse complétée par le backfill
  (la table DB est peuplée séparément via `importFromJson` selon le flux ; le backfill rattrape).
- **Hash figé bumpé sciemment** : `fe4cb121…` → `a43e8aad…` (PHP `ContractV1Test` + Go `hasher_test.go`),
  vérifié croisé PHP↔Go (item `1b97dcc7…`, state `a43e8aad…` identiques aux deux hashers).

### Validation

- Go (hôte) : `go test ./shared/` (mes tests + non-régression wallpaper/hash) VERT ; `go vet` linux+windows
  VERT ; cross-compile `GOOS=windows go build ./...` VERT. *(NB : le paquet `shared` portait un état
  transitoire NON compilable de fichiers `rainmeter*.go` — Story 27.1bis concurrente en cours d'édition ;
  une fois stabilisé, seul `TestRainmeterStore_InstalledSentinel` reste rouge, hors périmètre 27.7.)*
- PHP (VM) : `php artisan test --filter "ShortcutsStateProvider|ShortcutIconAssetService|ShortcutIconBackfiller|ContractV1"`
  → **28 passed (133 assertions)**. `--filter Agent` → **402 passed, 6 failed** ; les 6 échecs sont
  `ToolEndpointTest` (artefact/route tool 404), changements STAGÉS concurrents (Story 27.1bis, `ToolController`
  + `routes/api.php` + test nouveaux non finalisés), HORS périmètre 27.7 — aucun de mes fichiers ne les touche.
- `php -l` VERT sur tous les fichiers PHP. Garde-fou alias vérifié (Alias scopé `storage/app/shortcut-icons`,
  jamais `storage/` ni `keys/pki`). NFR7 : provider sans appel LDAP/APCu/cache (lecture Postgres pure).

### File List

**Créés**
- `database/migrations/2026_06_16_100000_add_icon_asset_to_shortcuts.php`
- `config/shortcut_icons.php`
- `app/Services/Shortcuts/ShortcutIconAssetService.php`
- `app/Services/Shortcuts/ShortcutIconBackfiller.php`
- `app/Console/Commands/ShortcutsBackfillIconsCommand.php`
- `agent/shared/icon_assets.go`
- `agent/shared/icon_assets_test.go`
- `tests/Unit/Services/Shortcuts/ShortcutIconAssetServiceTest.php`
- `tests/Unit/Services/Shortcuts/ShortcutIconBackfillerTest.php`

**Modifiés**
- `app/Models/Shortcut.php` (fillable + docblock `icon_asset`/`icon_checksum`)
- `app/Services/ShortcutsService.php` (content-adressage + persistance à l'upload)
- `app/Services/Agent/Providers/ShortcutsStateProvider.php` (distinction nom-nu/chemin-réel, payload étendu, get colonnes)
- `agent/shared/handler_shortcuts.go` (champs `IconAsset`/`IconChecksum`, parse + validation, `ResolveUploadedIconLocation`)
- `agent/shared/handler_shortcuts_test.go` (tests parse + résolution icône uploadée)
- `agent/shared/sessionstore.go` (`IconsDir`/`IconPath`/`EnsureIconsDir`)
- `agent/shared/loop.go` + `agent/shared/sessionfetch.go` (branchement `SyncShortcutIcons` SYSTEM)
- `agent/shared/sessionfetch_test.go` (route icône statique + `iconBody`/`iconCalls` du fake server)
- `agent/windows/handler_shortcuts_windows.go` (`effectiveIcon` → `ResolveUploadedIconLocation`, `iconsDir`)
- `agent/windows/companion_windows.go` (`shortcutOps.iconsDir = store.IconsDir()`)
- `config/apache/sambaedu.conf` + `scripts/setupApache.sh` (Alias `/assets/shortcut-icons` scopé + garde-fou)
- `tests/Fixtures/Agent/state.v1.json` (item `shortcuts` + `{icon_asset, icon_checksum}`, hashes bumpés)
- `tests/Unit/Services/Agent/ContractV1Test.php` (`FROZEN_STATE_HASH` bumpé sciemment)
- `agent/shared/hasher_test.go` (`frozenStateHash` bumpé, jumeau Go)
- `tests/Unit/Services/Agent/ShortcutsStateProviderTest.php` (3 cas : nom-nu→asset, chemin-réel→icon, nom-nu sans asset→icon)
- `docs/agent/state-providers.md`, `agent/README.md`, `docs/qa/domains/agent.md`, `docs/qa/README.md` (doc + QA)

### Action humaine requise (VM, hors-git)

1. **Alias Apache** : reporter le bloc `/assets/shortcut-icons` de `config/apache/sambaedu.conf` (ou via
   `scripts/setupApache.sh`) dans `/etc/apache2/sites-enabled/sambaedu.conf` puis `systemctl reload apache2`.
2. **Dossier servi + chown** : `mkdir -p storage/app/shortcut-icons && chown -R www-admin storage/app/shortcut-icons`.
3. **Migration** : `php artisan migrate --force` (déjà `Ran` sur la VM au moment du dev — vérifier `migrate:status`).
4. **Backfill** : `php artisan shortcuts:backfill-icons`.
5. **Validation lab Windows** : un raccourci à icône uploadée (ex. `Calculatrice`) affiche son icône réelle
   (plus de feuille blanche) ; asset indisponible → icône défaut sans crash ; `curl <url>/assets/shortcut-icons/`
   ne liste pas, `storage/keys/` non servi.
6. **Release agent** : bump version + publier une nouvelle release (le fix agent doit atteindre le parc).
