# Story 3.10 : Injection automatique de pilotes NIC dans le boot.wim WinPE

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **SM claude-opus-4-8[1m]** — story créée le 2026-06-26 sur la base de la recette **PoC validée e2e** le 2026-06-26 (lab1 étab, Lenovo ThinkCentre M700 / Intel I219, 100 % Linux sans poste Windows). Voir mémoire `project_winpe_nic_driver_boot_wim_gap`.
>
> **Suite directe de Story 3.6 (« Gestion ISO Windows », done)** dont elle **corrige une régression**. Réutilise intégralement le pipeline d'extraction natif (`WindowsIsoExtractor`) et les conventions iPXE (Process, exceptions dédiées, channel log `ipxe`, www-admin).

---

## Story

As a **responsable de collège qui déploie Windows par iPXE**,
I want que les pilotes réseau (NIC) absents du `boot.wim` Microsoft soient injectés automatiquement et de façon persistante dans l'image WinPE servie aux postes,
so que l'installation Windows démarre sur du matériel dont la carte réseau n'est plus prise en charge nativement par WinPE (régression Win11 24H2+).

---

## Contexte (cause racine + régression — NE PAS re-débattre)

**Déclencheur (validé builds 26100 ET 26200).** Microsoft a retiré des pilotes Intel LAN legacy (`e1d`, ex. Intel I219 `DEV_15B7/15B8`) du `boot.wim` **ET** de l'`install.wim` à partir de Win11 24H2. Sur un poste à NIC non-inbox, WinPE ne monte pas le réseau : le `@PING <se4fsIp>` de l'`install.bat` échoue (« défaillance générale » = la pile IP ne peut même pas émettre), la boucle `IPCONFIG /RENEW`→`PING` de `WindowsInstallBatBuilder` tourne sans fin, l'install ne démarre jamais.

**Pourquoi le boot.wim et rien d'autre (chicken-and-egg).** Pour le **NIC**, le seul levier est le `boot.wim` lui-même (driver inbox OU injecté). Le `z:\os\drivers` de l'`unattend.xml` (PnpCustomizationsWinPE, disque/chipset) est **inutile** ici : `z:` est monté par `net use` qui exige déjà le réseau. **Périmètre strict = NIC dans le boot.wim WinPE. PAS l'ISO, PAS l'install.wim, PAS `z:\os\drivers`.**

**Régression SE5 à tuer.** `WindowsIsoExtractor::extract()` (Story 3.6) ré-extrait l'ISO et **écrase le `boot.wim`** par le stock Microsoft à chaque extraction (backup best-effort `boot.wim-<version>-old`). Une injection one-shot (DISM manuel, comme le legacy) **ne tient pas** : elle est détruite à la première ré-extraction. C'est ce qui a cassé le lab le 2026-06-25. **L'injection doit être rejouée automatiquement à chaque extraction.**

**Insight d'idempotence (clé de l'implémentation).** Chaque extraction copie un `boot.wim` **frais et pristine** depuis l'ISO (cf. `WindowsIsoExtractor` l.91-95 : `cp -R` du contenu monté). L'injection s'exécute **juste après cette copie fraîche**, donc toujours sur un wim vierge de toute injection antérieure → l'opération `wimlib add` n'a jamais à dédupliquer, et le résultat est déterministe. L'idempotence est garantie *par construction* (source toujours pristine), pas par une logique de diff.

---

## Acceptance Criteria

### Volet 1 — Pack de pilotes persistant (hors arbre extrait)

**AC1.1 — Emplacement persistant et server-side.**
**Given** un répertoire de pack de pilotes WinPE défini par config (`ipxe.iso_management.winpe_drivers_path`, défaut `storage_path('install/winpe-drivers')`)
**When** une extraction d'ISO est déclenchée
**Then** ce répertoire n'est **jamais** touché par le `sudo rm -rf <target>` de l'extraction (il vit hors de `{deployed_os_base_path}/Win{N}`)
**And** il survit donc à toutes les ré-extractions (persistance).

**AC1.2 — Structure par famille de matériel.**
**Given** le pack de pilotes
**When** on y range des pilotes
**Then** la structure est `winpe_drivers_path/<famille>/` (ex. `intel-i219/`) contenant les triplets `.inf` + `.sys` + `.cat`
**And** plusieurs familles peuvent coexister (chaque sous-dossier est injecté).

### Volet 2 — Injection automatique dans `WindowsIsoExtractor` (idempotente, persistante, no-op propre)

**AC2.1 — Rejeu à chaque extraction.**
**Given** un pack de pilotes non vide
**When** `WindowsIsoExtractor::extract()` s'exécute
**Then** après la copie fraîche du `boot.wim` et son `chmod 0666`, le pack est injecté dans le `boot.wim` cible (`{target}/sources/boot.wim`)
**And** le `boot.wim` reste possédé par `www-admin:www-admin` et `0666` après injection (servi en SMB/HTTP).

**AC2.2 — Injection sur l'index BOOTABLE (piège index 2).**
**Given** un `boot.wim` de média d'install Windows (2 images : index 1 = Windows Setup, index 2 = WinPE bootable)
**When** l'injection s'exécute
**Then** elle cible l'**index 2** (configurable `ipxe.iso_management.winpe_boot_wim_image_index`, défaut `2`), JAMAIS l'index 1
**And** les pilotes sont placés à `\drivers\<famille>\` dans l'image (visible comme `X:\drivers\<famille>` dans le WinPE booté).

**AC2.3 — No-op propre si pack vide ou absent.**
**Given** un `winpe_drivers_path` inexistant, vide, ou sans aucun `.inf`
**When** l'extraction s'exécute
**Then** l'injection est **sautée** sans erreur
**And** un `log()` channel `ipxe` (niveau `info`) trace « aucun pilote NIC à injecter »
**And** le `boot.wim` reste le stock Microsoft intact (comportement actuel préservé — zéro régression pour les parcs à NIC inbox).

**AC2.4 — Log explicite de ce qui est injecté.**
**Given** un pack non vide
**When** l'injection s'exécute
**Then** un `log()` channel `ipxe` énumère les familles et le nombre de `.inf` injectés (ex. « boot.wim Win11 index 2 : injecté intel-i219 (1 .inf) + nicload.cmd »).

**AC2.5 — Échec d'injection = exception explicite, pas de demi-boot.**
**Given** `wimlib-imagex` absent, exit non-zéro, ou index invalide
**When** l'injection est tentée
**Then** une exception dédiée (`WinpeDriverInjectionException`) est levée avec l'exit code + stderr
**And** elle remonte au `DownloadWindowsIsoJob` qui marque le download `failed` avec un message exploitable (toast côté UI 3.6).

### Volet 3 — Livraison du `drvload` (point unique = `nicload.cmd` injecté + chaînage `winpeshl.ini`)

**AC3.1 — `nicload.cmd` injecté dans le wim.**
**Given** une injection de pilotes
**When** elle s'exécute
**Then** un fichier versionné `resources/ipxe/winpe/nicload.cmd` est injecté dans le `boot.wim` index 2 à `\Windows\System32\nicload.cmd`
**And** son contenu est `for /r X:\drivers %%f in (*.inf) do drvload "%%f"` avec **line endings `\r\n` STRICT** (WinPE rejette silencieusement les `.cmd` en LF — même contrainte que `WindowsInstallBatBuilder`).

**AC3.2 — Chaînage `winpeshl.ini` : `nicload.cmd` PUIS `install.bat`.**
**Given** le fichier servi `resources/ipxe/winpe/winpeshl.ini` (seedé par `WindowsIsoExtractor::seedWinpeHelpers()` vers `{base}/winpe/`)
**When** WinPE démarre
**Then** `winpeshl.ini` lance d'abord `"nicload.cmd"` (charge les NIC) PUIS `"install.bat"` (flux d'install inchangé)
**And** `winpeshl.ini` conserve ses line endings et reste un fichier statique versionné (pas de génération dynamique).

**AC3.3 — `WindowsInstallBatBuilder` reste INCHANGÉ.**
**Given** la décision de point unique (cf. D2)
**When** la story est implémentée
**Then** `app/Ipxe/Services/WindowsInstallBatBuilder.php` n'est **pas** modifié (parité iso-legacy stricte préservée — le drvload n'y est PAS préfixé).

### Volet 4 — Commande d'ingestion des pilotes (`.exe` Lenovo / `.zip` Intel)

**AC4.1 — Ingestion CLI.**
**Given** une archive de pilotes (`.exe` InnoSetup Lenovo ou `.zip` Intel) et une famille cible
**When** l'admin lance `php artisan ipxe:winpe-drivers:ingest <famille> <chemin-archive>`
**Then** l'archive est extraite (`.exe` → `innoextract` ; `.zip` → `unzip`), les `.inf`/`.sys`/`.cat` sont localisés et copiés dans `winpe_drivers_path/<famille>/`
**And** le résultat est chown `www-admin:www-admin`
**And** un récapitulatif liste les `.inf` ingérés.

**AC4.2 — `.exe` extrait via `innoextract`, PAS 7z.**
**Given** un `.exe` Lenovo InnoSetup
**When** la commande l'ingère
**Then** elle utilise `innoextract` (7z ne voit que les sections PE et rate les fichiers pilote — validé PoC)
**And** si `innoextract` est absent, un message d'erreur clair indique le paquet à installer.

**AC4.3 — Validation d'entrée.**
**Given** une archive non reconnue (ni `.exe` ni `.zip`) ou un dossier extrait sans aucun `.inf`
**When** la commande s'exécute
**Then** elle échoue proprement (exit non-zéro) avec un message explicite, sans laisser de pack partiel.

**AC4.4 — Upload UI Livewire (décision Henri : CLI + UI).**
**Given** la page admin `resources/views/pages/admin/ipxe/iso-windows/index.blade.php` (SFC Livewire, gate `can:server.admin`, trait `WithToasts`)
**When** l'admin dépose une archive de pilotes (`.exe` Lenovo / `.zip` Intel) + saisit une famille via le formulaire d'upload
**Then** le composant délègue à la **même logique d'ingestion** que la commande artisan (service partagé `WinpeDriverIngestor`, PAS de duplication de logique dans le composant)
**And** un toast `WithToasts` confirme les `.inf` ingérés ou affiche l'erreur (archive inconnue / aucun .inf / binaire manquant)
**And** la liste des familles de pilotes présentes dans le pack est affichée (lecture seule du `winpe_drivers_path`)
**And** l'upload respecte les contraintes Livewire connues (`project_livewire_reserved_upload_method` : ne jamais nommer l'action `upload` ; ne pas `move()` un `TemporaryUploadedFile` — utiliser `getRealPath()` / `store()`).

### Volet 5 — Prérequis runtime / provisioning

**AC5.1 — Dépendances système documentées.**
**Given** le serveur SE5
**When** la story est livrée
**Then** les prérequis `wimtools` (fournit `wimlib-imagex`), `innoextract` et `unzip` sont documentés (runbook QA + commentaire config) comme dépendances de provisioning
**And** l'injection (`wimlib-imagex`) s'exécute en **www-admin sans sudo** (le `boot.wim` lui appartient déjà après le `chown` de l'extraction) — aucune nouvelle règle sudoers requise.

### Volet 6 — Tests + non-régression

**AC6.1 — Tests unitaires injecteur.**
**Given** la suite de tests (hôte, php8.4 + pdo_sqlite — cf. `project_phpunit_test_env_host_vs_vm`)
**When** on teste `WinpeDriverInjector`
**Then** sont couverts : no-op pack vide (aucune commande wimlib lancée), construction de la commande `wimlib-imagex update` (index 2, add par famille), injection `nicload.cmd`, log, exception sur exit non-zéro — via `Process::fake()`.

**AC6.2 — Test commande d'ingestion.**
**Given** la suite de tests
**When** on teste `IngestWinpeDriversCommand`
**Then** sont couverts : dispatch innoextract vs unzip selon l'extension, échec sur archive inconnue, échec si aucun `.inf` (via `Process::fake()`).

**AC6.3 — Non-régression 3.6.**
**Given** les tests existants de `WindowsIsoExtractor` / Story 3.6
**When** la story est livrée
**Then** ils passent toujours
**And** un test prouve que l'extraction avec pack vide produit exactement le comportement 3.6 (boot.wim stock, pas d'appel wimlib).

**AC6.4 — Test d'architecture.**
**Given** la convention namespace `App\Ipxe\Iso`
**When** le nouveau service/exception sont créés
**Then** un test d'architecture vérifie leur emplacement et le suffixe d'exception (cohérent avec `WindowsIsoExtractionException`).

### Volet 7 — Documentation runbook QA

**AC7.1 — Section runbook.**
**Given** `docs/qa/domains/ipxe.md`
**When** la story est livrée
**Then** une nouvelle section `## Story 3.10 — Injection pilotes NIC boot.wim WinPE` (append-only) documente : la recette, l'emplacement du pack, la commande d'ingestion, les prérequis système, et le scénario de smoke e2e (M700/I219).

---

## Tasks / Subtasks

- [x] **T1 — Config + arbo pack pilotes** (AC1.1, AC1.2, AC2.2, AC5.1)
  - [x] T1.1 Étendre `config/ipxe.php` section `iso_management` : `winpe_drivers_path` (défaut `storage_path('install/winpe-drivers')`, override `IPXE_WINPE_DRIVERS_PATH`) + `winpe_boot_wim_image_index` (défaut `2`, override `IPXE_WINPE_BOOT_WIM_INDEX`).
  - [x] T1.2 Commenter dans la config : pack server-side only (NON servi aux postes — le boot.wim porte déjà les pilotes injectés), prérequis `wimtools`/`innoextract`/`unzip`, piège index 2.
  - [x] T1.3 `config:cache` + chown www-admin à refaire côté VM post-merge (cf. `project_vm_config_cache_not_synced`) — noté en action Henri (Completion Notes + runbook), NON exécuté depuis worktree.

- [x] **T2 — Asset versionné `nicload.cmd` + chaînage `winpeshl.ini`** (AC3.1, AC3.2)
  - [x] T2.1 Créer `resources/ipxe/winpe/nicload.cmd` : `for /r X:\drivers %%f in (*.inf) do drvload "%%f"` en **CRLF strict** (vérifié `xxd` : aucun LF nu).
  - [x] T2.2 Modifier `resources/ipxe/winpe/winpeshl.ini` → `[LaunchApps]` puis `"nicload.cmd"` puis `"install.bat"` (CRLF, ordre strict).
  - [x] T2.3 Vérifié que `WindowsIsoExtractor::seedWinpeHelpers()` copie tout `resources/ipxe/winpe/` (winpeshl.ini + wimboot + désormais nicload.cmd dans le dossier source ; nicload.cmd est aussi INJECTÉ dans le wim — cf. T3).

- [x] **T3 — Service `WinpeDriverInjector`** (AC2.1, AC2.2, AC2.3, AC2.4, AC2.5, AC3.1)
  - [x] T3.1 Créé `app/Ipxe/Iso/Services/WinpeDriverInjector.php` : méthode `inject(string $bootWimPath, ?int $timeoutSeconds = null): void`.
  - [x] T3.2 No-op propre : si `winpe_drivers_path` absent/vide/sans `.inf` → `log()` info `ipxe.winpe.drivers.skipped_empty` + return (aucune commande lancée).
  - [x] T3.3 Pour chaque famille non vide : `wimlib-imagex update <bootWim> <index> --command="add <pack>/<famille> /drivers/<famille>"` via `Illuminate\Support\Facades\Process`.
  - [x] T3.4 Injecte `nicload.cmd` : `wimlib-imagex update <bootWim> <index> --command="add <winpe_source>/nicload.cmd /Windows/System32/nicload.cmd"`.
  - [x] T3.5 `log()` channel `ipxe` récapitulatif (familles + nb .inf). Sur échec → `WinpeDriverInjectionException` (exit + stderr).
  - [x] T3.6 Créé `app/Ipxe/Iso/Exceptions/WinpeDriverInjectionException.php` (calque `WindowsIsoExtractionException`).

- [x] **T4 — Brancher l'injection dans `WindowsIsoExtractor`** (AC2.1, AC2.3, AC6.3)
  - [x] T4.1 Dans `extract()`, **après** le `@chmod($target.'/sources/boot.wim', 0666)` et **avant** `seedWinpeHelpers()`, appel `app(WinpeDriverInjector::class)->inject($target.'/sources/boot.wim', $timeout)`.
  - [x] T4.2 Re-`chmod 0666` + chown www-admin du boot.wim après injection (fait DANS l'injecteur après réécriture wimlib).
  - [x] T4.3 L'exception d'injection remonte (pas avalée par le `finally` umount) ; catch dédié `WinpeDriverInjectionException` ajouté dans `DownloadWindowsIsoJob` → download `failed` + exit code wimlib reporté (AC2.5).

- [x] **T5 — Ingestion (service partagé + commande + upload UI)** (AC4.1, AC4.2, AC4.3, AC4.4)
  - [x] T5.1 Créé le **service partagé** `app/Ipxe/Iso/Services/WinpeDriverIngestor.php` : `ingest(string $famille, string $archivePath, ?string $originalFilename = null): array`. Toute la logique vit ICI — la commande ET le composant Livewire l'appellent.
  - [x] T5.2 Dispatch par extension `.exe` → `innoextract` ; `.zip` → `unzip` (tmp jetable) ; binaire absent/extension inconnue → message clair ; localisation récursive `.inf`/`.sys`/`.cat`/`.dll`, copie À PLAT dans `winpe_drivers_path/<famille>/`, chown www-admin best-effort, échec si aucun `.inf` (`WinpeDriverIngestionException`, aucun pack partiel).
  - [x] T5.3 Créé `app/Console/Commands/IngestWinpeDriversCommand.php`, signature `ipxe:winpe-drivers:ingest {famille} {archive}` → délègue à `WinpeDriverIngestor`, récap des `.inf`, exit non-zéro sur échec métier.
  - [x] T5.4 **Upload UI Livewire** (AC4.4) : SFC étendue (`WithFileUploads`, `$driverArchive`, `$driverFamily`, `$driverFamilies`). Action `ingestDrivers()` (PAS `upload`), passe `getRealPath()` (PAS `move()`) + `getClientOriginalName()` au service partagé, toast `WithToasts`. Liste lecture-seule des familles présentes. Gate `can:server.admin` en place. Zéro duplication.

- [x] **T6 — Tests** (AC6.1, AC6.2, AC6.3, AC6.4)
  - [x] T6.1 `tests/Unit/Ipxe/Iso/WinpeDriverInjectorTest.php` (8 tests, Process::fake : no-op vide/absent/sans-inf, commande add index 2, nicload, jamais index 1, exception exit non-zéro, boot.wim manquant).
  - [x] T6.2 `tests/Feature/Ipxe/Iso/IngestWinpeDriversCommandTest.php` (7 tests : zip réel via unzip, dispatch innoextract vs unzip, binaire absent, archive inconnue, aucun .inf, famille invalide).
  - [x] T6.2b `tests/Feature/Ipxe/Iso/WinpeDriverIngestLivewireTest.php` (6 tests : succès zip réel, extension inconnue, famille invalide, aucun .inf, liste lecture-seule, invariants action≠`upload`/`getRealPath`/pas de `move()`).
  - [x] T6.3 Non-régression `WindowsIsoExtractorTest::it_does_not_run_wimlib_when_driver_pack_is_empty` : pack vide ⇒ aucun appel wimlib (boot.wim stock).
  - [x] T6.4 Test d'architecture (`IpxeNamespaceTest`, 3 méthodes 3.10) : emplacements service/exception/commande, suffixe exception, CRLF + ordre nicload→install, `WindowsInstallBatBuilder` sans drvload (D2/AC3.3).

- [x] **T7 — Doc runbook QA** (AC7.1)
  - [x] T7.1 Section append-only `## Story 3.10` dans `docs/qa/domains/ipxe.md` (recette, pack, 2 canaux d'ingestion CLI+UI, prérequis système, actions VM post-merge, smoke M700/I219, couverture auto).

- [x] **T8 — Tracking BMAD**
  - [x] T8.1 sprint-status `3-10-injection-pilotes-nic-boot-wim-winpe` : `ready-for-dev` → `in-progress` (dev) → `review` (finalisation).
  - [x] T8.2 (post-dev) Backlog éclaté synchronisé par le hook sprint-status (cf. `project_sync_backlog_hook_targets_main`).

---

## Dev Notes

### Points d'ancrage code (à LIRE avant de coder)

- **`app/Ipxe/Iso/Services/WindowsIsoExtractor.php`** — LE point d'injection. Séquence dans `extract()` : `sudo mount` → backup best-effort `boot.wim-<version>-old` (l.79-82) → `sudo rm -rf {target}` → `cp -R` contenu ISO → `chown -R www-admin` → fichier `version` → `@chmod(boot.wim, 0666)` (l.107) → `seedWinpeHelpers()` (l.115) → `finally { umount }`. **Insère l'appel injecteur juste après le chmod 0666.** Réutilise le helper privé `runOrThrow($cmd, $timeout, $stage)` comme modèle (Process::timeout + exception sur `!successful()`).
- **`app/Ipxe/Services/WindowsInstallBatBuilder.php`** — NE PAS MODIFIER (D2). C'est le port iso-legacy strict de `install.bat.php` ; il génère la boucle `IPCONFIG /RENEW`→`@PING`→`net use z:`→`setup.exe` en CRLF strict. Le drvload NE doit PAS y être préfixé. À lire seulement pour comprendre où survient le symptôme (le PING qui boucle).
- **`resources/ipxe/winpe/winpeshl.ini`** — actuellement `[LaunchApps]\n"install.bat"`. C'est un asset versionné, seedé tel quel vers `{base}/winpe/` par `seedWinpeHelpers()`. Le wimboot l'injecte dans le WinPE booté. **À modifier** pour chaîner nicload.cmd avant install.bat.
- **`resources/ipxe/winpe/`** — dossier source des helpers WinPE (`wimboot`, `winpeshl.ini`). Y ajouter `nicload.cmd` (versionné). Référencé par config `ipxe.windows.assets_paths.winpe_source_path` (défaut `resource_path('ipxe/winpe')`).
- **`config/ipxe.php`** — section `iso_management` (l.480+) : `deployed_os_base_path` (`/var/sambaedu/unattended/install/os`, sert `/os` aux postes), `iso_storage_path` (`storage/install/iso`). Y ajouter `winpe_drivers_path` + `winpe_boot_wim_image_index`. Section `windows.assets_paths` (l.418) documente `boot_wim => '{version}/sources/boot.wim'`.
- **`app/Ipxe/Iso/Exceptions/WindowsIsoExtractionException.php`** — modèle pour la nouvelle exception (constructeur message + exit code).
- **Conventions** : `Illuminate\Support\Facades\Process` (pas Symfony direct), `declare(strict_types=1)`, namespace `App\Ipxe\Iso\Services` / `App\Ipxe\Iso\Exceptions`, channel log `ipxe` (`Log::channel('ipxe')` ou `logger()` configuré).

### Mécanique WinPE (pourquoi ces choix)

- **Pilotes DANS le wim** (et pas servis par wimboot comme install.bat) : le NIC doit être présent à `X:\drivers` dans le **système de fichiers du WinPE booté** (X: = racine montée du boot.wim). `drvload` lit `X:\drivers\*\*.inf`. Donc l'`add` wimlib place les pilotes à `\drivers\<famille>` dans l'image.
- **`nicload.cmd` aussi dans le wim** (à `\Windows\System32\`, le working dir de winpeshl LaunchApps) : cohérent avec les pilotes, exécuté avant toute tentative réseau. `winpeshl.ini`, lui, est l'asset wimboot-injecté qui orchestre l'ordre `nicload.cmd` → `install.bat`.
- **Index 2 = piège majeur.** Sur un média d'install Windows, `boot.wim` contient 2 images : index 1 = « Windows Setup », index 2 = WinPE bootable. Injecter sur 1 ne charge rien au boot. Toujours index 2 (validé PoC ; rendu configurable par prudence).
- **NDIS65.** Le driver I219 utilisé en PoC = `app/NDIS65/Universal/e1d65x64.*` extrait du `.exe` Lenovo (`download.lenovo.com/.../u1etn20us14avc.exe`) — NDIS65 se `drvload` correctement sur WinPE 26xxx. Le pack Intel direct était inaccessible (WAF AWS bloque curl). La story ne hardcode aucun driver : elle ingère ce que l'admin dépose.

### Points tranchés (décisions par défaut — modifiables par Henri)

- **D1 — Emplacement du pack : `storage/install/winpe-drivers/<famille>/` (DÉCISION par défaut).** Le PoC utilisait `os/winpe-drivers/` (sous `/os`). MAIS le pack est **server-side uniquement** : il est lu par `wimlib-imagex` (www-admin) au moment de l'extraction ; les **postes ne le téléchargent jamais** (ils reçoivent le `boot.wim` avec pilotes déjà injectés). Or la convention `project_storage_convention_non_versioned` impose `storage/*` pour tout asset non versionné NON client-facing, et l'exception `/os` ne vaut que pour ce qui est servi aux postes. → Default = `storage/install/winpe-drivers` (cohérent avec `iso_storage_path = storage/install/iso`, même catégorie « source serveur »). Le chemin reste un **config key overridable** : poser `IPXE_WINPE_DRIVERS_PATH=/var/sambaedu/unattended/install/os/winpe-drivers` reproduit exactement le PoC si Henri préfère. **L'essentiel (invariant) : le pack vit HORS `{deployed_os_base_path}/Win{N}` pour échapper au `rm -rf` de l'extraction.**
- **D2 — drvload via `nicload.cmd` injecté dans le wim (PAS préfixe `WindowsInstallBatBuilder`).** Point unique = le wim. Justification : (a) `WindowsInstallBatBuilder` est un port iso-legacy strict, on le garde intact (parité, moins de surface de régression) ; (b) le chargement des NIC est une préoccupation de **boot WinPE**, antérieure à toute logique d'install, sa place est dans l'image WinPE ; (c) c'est exactement la recette validée e2e. Conséquence : seul `winpeshl.ini` (l'orchestrateur de boot) est touché côté flux.
- **D3 — Ingestion CLI + upload UI Livewire (TRANCHÉ par Henri le 2026-06-26).** Les deux canaux dans cette story : (a) commande artisan `ipxe:winpe-drivers:ingest` (admin avec shell) ; (b) formulaire d'upload sur la page existante `/admin/ipxe/iso-windows` (SFC Livewire, gate `can:server.admin`, `WithToasts`). **Invariant : logique d'ingestion centralisée dans le service `WinpeDriverIngestor`** — commande et composant l'appellent, zéro duplication. Contraintes Livewire : action ≠ `upload`, pas de `move()` sur `TemporaryUploadedFile` (cf. `project_livewire_reserved_upload_method`).
- **D4 — Idempotence par construction + no-op pack vide.** Pas de logique de diff : la copie fraîche du boot.wim depuis l'ISO garantit un wim pristine à chaque injection. Pack vide → skip + log, boot.wim stock préservé (zéro impact parcs NIC inbox).

### Project Structure Notes

- Nouveau code sous `App\Ipxe\Iso` (parité avec `WindowsIsoExtractor`, `WindowsIsoSourcesReader`, etc.). Exception sous `App\Ipxe\Iso\Exceptions`.
- Commande artisan : `app/Console/Commands/` est **plat** (pas de sous-dossier `Ipxe/`) → `IngestWinpeDriversCommand.php` y va directement, signature namespacée `ipxe:winpe-drivers:ingest`.
- Assets WinPE versionnés sous `resources/ipxe/winpe/` (déjà la source seedée). Le pack de pilotes **runtime** sous `storage/install/winpe-drivers/` (gitignored, non versionné).
- **www-admin** (`project_php_fpm_user_www_admin`) : tout fichier écrit (pack ingéré, boot.wim réécrit) → owner www-admin. L'injection wimlib n'exige PAS sudo (boot.wim déjà www-admin après l'`chown` de l'extraction).
- **Tests sur HÔTE** (`project_phpunit_test_env_host_vs_vm`) : php8.4 + pdo_sqlite ; la VM php8.2 n'a pas pdo_sqlite. `Process::fake()` partout — ne jamais lancer un vrai wimlib en test.
- **Worktree** (`feedback_worktree_no_vm_sync`) : si dev en worktree, aucun SSH/test VM ; `config:cache` + chown VM = action Henri post-merge.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 3.10] — définition, déclencheur, recette PoC, points à trancher.
- [Source: _bmad-output/implementation-artifacts/3-6-gestion-iso-windows.md] — Story 3.6 (done) : `WindowsIsoExtractor`, pipeline upload→extraction, `DownloadWindowsIsoJob`, config `iso_management`.
- [Source: app/Ipxe/Iso/Services/WindowsIsoExtractor.php:79-115] — backup/copie/chmod boot.wim + seedWinpeHelpers (point d'injection).
- [Source: app/Ipxe/Services/WindowsInstallBatBuilder.php:98-131] — boucle PING/net use (symptôme) ; CRLF strict ; NE PAS modifier.
- [Source: config/ipxe.php:418-527] — `windows.assets_paths` + `iso_management` (où ajouter les clés).
- [Source: resources/ipxe/winpe/winpeshl.ini] — chaînage à modifier.
- [Mémoire: project_winpe_nic_driver_boot_wim_gap] — recette PoC e2e, index 2, NDIS65, innoextract.
- [Mémoire: project_winpe_setup_never_returns] — setup.exe ne rend jamais la main (winpeshl chaîne avant setup).
- [Mémoire: project_storage_convention_non_versioned] — assets non client-facing → storage/* (justifie D1).
- [Mémoire: project_iso_storage_relocated_and_pipeline_gaps] — sources sous storage/install/iso ; extracteur natif.
- [Mémoire: project_php_fpm_user_www_admin / project_phpunit_test_env_host_vs_vm / feedback_worktree_no_vm_sync].

### Dépendances

- **Story 3.6 (done)** — prérequis dur (réutilise `WindowsIsoExtractor` + pipeline). ✅
- **Story 3.5 (done)** — `WindowsInstallBatBuilder`, `winpeshl.ini`, seed WinPE. ✅
- **Système (provisioning)** : `wimtools`, `innoextract`, `unzip` à installer sur le serveur SE5 (action Henri / one-shot-install). `wimlib-imagex` présent sur l'hôte de dev ; `innoextract` absent (à installer pour tester la commande, ou `Process::fake()`).

---

## Recommandation Modèle Dev

**opus.** Story de complexité élevée : manipulation bas niveau d'images WIM (index bootable, chemins internes, idempotence par construction), correction d'une **régression cross-story** dans un pipeline critique (`WindowsIsoExtractor`) sans casser la non-régression 3.6, contraintes système fines (CRLF WinPE, ownership www-admin, drvload/NDIS65), et arbitrages de conventions (storage vs /os). Le coût d'une erreur est un parc qui ne boote plus — précision > vitesse.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (dev-story BMAD).

### Debug Log References

- Tests sur HÔTE (php 8.4.5 + pdo_sqlite, cf. `project_phpunit_test_env_host_vs_vm`), `Process::fake()` partout — aucun vrai `wimlib-imagex`/`innoextract` lancé.
- Découvertes env hôte : l'extension PHP `zip` (ZipArchive) N'EST PAS chargée (les tests existants `ToolsCatalogSurfaceTest` échouent pour la même raison) → les `.zip` de test sont construits via le binaire `zip` (présent), extraits par `unzip` (présent). `innoextract` absent de l'hôte → chemins `.exe` testés via `Process::fake()` uniquement.

### Completion Notes List

**Implémenté (T1→T8, 22 AC).**
- **Injection (D2/D4)** : `WinpeDriverInjector::inject()` rejoué à chaque extraction sur le boot.wim FRAÎCHEMENT copié (idempotent par construction). No-op propre si pack vide/absent (boot.wim stock préservé, zéro régression NIC inbox). Cible l'index **2** (configurable). Re-chmod 0666 + chown www-admin après réécriture wimlib. Échec → `WinpeDriverInjectionException` (exit+stderr) remontée jusqu'au `DownloadWindowsIsoJob` (catch dédié ajouté) → download `failed` (AC2.5).
- **drvload (D2)** : point unique = `nicload.cmd` injecté dans le wim (`\Windows\System32\`) + `winpeshl.ini` chaîne `nicload.cmd` PUIS `install.bat`. `WindowsInstallBatBuilder` INCHANGÉ (vérifié par test d'archi). nicload.cmd + winpeshl.ini en **CRLF strict** (vérifié `xxd` + test d'archi).
- **Ingestion (D3)** : service partagé `WinpeDriverIngestor` appelé par la commande artisan `ipxe:winpe-drivers:ingest` ET le composant Livewire — zéro duplication. Dispatch `.exe`→innoextract / `.zip`→unzip ; message clair si binaire absent ; échec propre si extension inconnue / aucun `.inf` (aucun pack partiel). Famille validée anti path-traversal. Copie À PLAT des triplets `.inf/.sys/.cat(+.dll)`.
- **UI Livewire** : action `ingestDrivers()` (jamais `upload`), `getRealPath()` + `getClientOriginalName()` (jamais `move()`), toast `WithToasts`, liste lecture-seule des familles. Respecte `project_livewire_reserved_upload_method`.
- **Pack (D1)** : défaut `storage/install/winpe-drivers/<famille>/` (server-side, gitignored via `/storage/install/`), hors `{deployed_os_base_path}/Win{N}` (échappe au `rm -rf`). Overridable `IPXE_WINPE_DRIVERS_PATH`.

**Tests (sur hôte, ciblés).**
- `WinpeDriverInjectorTest` : 8/8 ✓.
- `IngestWinpeDriversCommandTest` : 7/7 ✓.
- `WinpeDriverIngestLivewireTest` : 6/6 ✓.
- `WindowsIsoExtractorTest::it_does_not_run_wimlib_when_driver_pack_is_empty` (non-régression 3.6) : ✓.
- `IpxeNamespaceTest` (3 méthodes 3.10) : 3/3 ✓.
- **Total story : 25 nouveaux tests verts.**

**Échecs PRÉ-EXISTANTS (hors-scope, identiques sur HEAD avant cette story — vérifié par swap du fichier original) :**
- `WindowsIsoExtractorTest::it_unmounts_even_when_copy_fails` (assert exit 5 ≠ 1 obtenu) — quirk `Process::fake()` wildcard/exit-code de la version PHPUnit/Laravel de l'hôte ; échoue déjà sans mes modifs (la copie échoue AVANT toute injection).
- `IpxeNamespaceTest::story_3_4_templates_are_ascii_strict_and_no_php` — `windows/cmd/{join,post}.blade.php` chars non-ASCII (fichiers non touchés par 3.10).
- `WindowsIsoWindowsLivewireTest` : 2 erreurs « Vite manifest not found » (pages 403 rendent le layout d'erreur — pas de build front sur l'hôte) + 1 échec lock store ; identiques sur HEAD.

**Actions Henri post-merge (VM, NON exécutées depuis worktree — cf. `feedback_worktree_no_vm_sync` / `project_vm_config_cache_not_synced`) :**
1. `apt-get install -y wimtools innoextract unzip` sur la VM (prérequis provisioning).
2. `php artisan config:cache && chown www-admin:www-admin bootstrap/cache/*.php`.
3. `mkdir -p storage/install/winpe-drivers && chown -R www-admin:www-admin storage/install/winpe-drivers`.
4. Smoke e2e M700/I219 (cf. runbook `docs/qa/domains/ipxe.md` §Story 3.10).

**Note ZipArchive** : si la prod/VM dispose de l'extension PHP `zip`, le test `it_ingests_a_real_zip_via_unzip_and_copies_inf_files` reste valide (il utilise le binaire `zip`, pas ZipArchive). À surveiller si l'env CI diffère.

### File List

**Créés :**
- `app/Ipxe/Iso/Services/WinpeDriverInjector.php`
- `app/Ipxe/Iso/Services/WinpeDriverIngestor.php`
- `app/Ipxe/Iso/Exceptions/WinpeDriverInjectionException.php`
- `app/Ipxe/Iso/Exceptions/WinpeDriverIngestionException.php`
- `app/Console/Commands/IngestWinpeDriversCommand.php`
- `resources/ipxe/winpe/nicload.cmd`
- `tests/Unit/Ipxe/Iso/WinpeDriverInjectorTest.php`
- `tests/Feature/Ipxe/Iso/IngestWinpeDriversCommandTest.php`
- `tests/Feature/Ipxe/Iso/WinpeDriverIngestLivewireTest.php`

**Modifiés :**
- `config/ipxe.php` (clés `winpe_drivers_path` + `winpe_boot_wim_image_index` + commentaires)
- `app/Ipxe/Iso/Services/WindowsIsoExtractor.php` (appel injecteur après chmod 0666)
- `app/Ipxe/Iso/Jobs/DownloadWindowsIsoJob.php` (catch dédié `WinpeDriverInjectionException` → failed)
- `resources/ipxe/winpe/winpeshl.ini` (CRLF + chaînage nicload.cmd → install.bat)
- `resources/views/pages/admin/ipxe/iso-windows/index.blade.php` (upload UI pilotes)
- `tests/Unit/Ipxe/Iso/Services/WindowsIsoExtractorTest.php` (test non-régression pack vide)
- `tests/Architecture/IpxeNamespaceTest.php` (3 méthodes 3.10)
- `docs/qa/domains/ipxe.md` (section runbook Story 3.10)
- `.gitignore` (`/storage/install/`)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (statut 3.10)
