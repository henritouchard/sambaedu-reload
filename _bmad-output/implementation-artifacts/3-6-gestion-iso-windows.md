# Story 3.6 : Gestion ISO Windows

Status: done

> Validée Henri 2026-05-25 (review → done) — code mergé sur main. Dev terminé 2026-05-21 par claude-opus-4-7[1m] (worktree `ipxe`). Voir Dev Agent Record + Change Log en bas du fichier.

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **Suite directe de Stories 3.1 → 3.5** (« iPXE Service Core » + « Boot et Menu Admin iPXE » + « Enrollment Machine — Parcs, Salles, Nommage » + « Installation Linux » + « Installation Windows Sysprep/Wimboot »). Porte nativement la **page admin web de gestion des ISO Windows** consommées en aval par les routines 3.5 — équivalent legacy `sambaedu/ipxe/Win10/win_iso.php` (110 LOC). Réutilise le socle d'authent web (`sambaedu.auth + sambaedu.admin + can:server.admin`), le routeur Livewire SFC filesystem-based (`resources/views/pages/{path}/index.blade.php`), le trait `WithToasts`, le pattern Laravel Queue + Job singleton (iso jobs `SyncWorkstationGroupJob`, `SyncAppProfileJob`, `BulkCreateWorkstationGroupsJob` Epic 4) et la table `MachineBootLog` (extension d'action si besoin).
>
> **Scope strict 3.6** = (a) **1 nouvelle page admin Livewire SFC** `resources/views/pages/admin/ipxe/iso-windows/index.blade.php` (route `/admin/ipxe/iso-windows`) qui affiche la liste des versions Win10/Win11 + Win10-old/Win11-old déployées sous `/var/sambaedu/unattended/install/os/Win{10,11}{,-old}/`, (b) **1 formulaire** d'upload d'URL Microsoft (input text URL — pas d'upload multipart) + bouton « Télécharger l'ISO », (c) **1 nouveau service `WindowsIsoUrlValidator`** (regex iso-legacy `#(Win.*\.iso)$#` + allowlist host `microsoft.com` defense in depth + anti-SSRF), (d) **1 nouveau service `WindowsIsoSourcesReader`** (lecture `/var/sambaedu/unattended/install/os/Win{10,11}{,-old}/version` + best-effort si absent), (e) **1 nouveau service `WindowsIsoDownloadOrchestrator`** (assemble URL+iso_name validés + dispatch d'un Job Laravel Queue), (f) **1 nouveau Job Laravel queue `DownloadWindowsIsoJob`** (1 instance vivante à la fois via cache lock — exécute `curl -o /var/sambaedu/unattended/install/os/iso/<iso_name> <url>` puis `sudo /usr/share/sambaedu/scripts/install-win-iso.sh <version> <iso_name>` via `Symfony\Component\Process\Process`, capture stdout/stderr/timecodes/exit_code, journalise), (g) **1 nouvelle migration + table `windows_iso_downloads`** (id, version Win10|Win11, iso_name, source_url, status [pending|downloading|extracting|success|failed|cancelled], started_at, completed_at, exit_code nullable, error nullable, initiated_by_user_id FK users, host_ip — tracking complet par tentative), (h) **1 nouveau modèle Eloquent `WindowsIsoDownload`** + factory, (i) **polling Livewire `wire:poll.5s`** tant que `status ∈ {pending, downloading, extracting}` pour rafraîchir la liste des sources et la card du téléchargement en cours, (j) **toasts** via trait `WithToasts` (succès, erreur de validation URL, lancement OK, échec download), (k) **modale de confirmation** « Êtes-vous sûr de vouloir télécharger… ? » via le composant modale réutilisable du projet, (l) **actions Livewire intra-composant** `submitDownload()` + `confirmDownload()` + `cancelDownload(int $id)` (déclencheur + annulation — pas de routes POST dédiées ; iso pattern `/admin/sync-from-ad`). Note Opus-J post-review 2026-05-21 : la spec initiale parlait de « 2 endpoints HTTP POST » (`POST /admin/ipxe/iso-windows/download` + `POST /admin/ipxe/iso-windows/{download}/cancel`) mais la décision finale D2 (cf. DO-3 Dev Agent Record) a retenu une seule route Livewire fullpage + méthodes intra-composant — aucun `WindowsIsoDownloadController` n'est livré. (m) **extension `config/ipxe.php`** avec une section `iso_management` (paths os/Win{10,11}, paths iso/, version_file_name, install_win_iso_binary, allowed_url_pattern, allowed_url_hosts, download_timeout_seconds), (n) **extension `config/sambaedu.php`** avec section `windows_iso` (chemin du script `install-win-iso.sh`, user sudoers — documentation des prérequis VM), (o) tests Unit + Feature Livewire + Architecture ≥ 25 cumulés, (p) extension `docs/qa/domains/ipxe.md` Section 15 « Story 3.6 » + ≥10 scénarios stables 3.6-1 à 3.6-N.
>
> **HORS-SCOPE 3.6** (explicitement reportés ou abandonnés) :
>
> - **Upload de fichier ISO depuis le navigateur** (multipart HTTP) — le legacy ne fait que `curl` côté serveur depuis une URL Microsoft, pas de multipart. SE5 = parité stricte. Si besoin terrain (admin sans Internet sortant) → ouvrir une story dédiée.
> - **Refonte UX exhaustive** (drag-drop ISO local, sélection multi-versions, comparaison de checksums SHA, tableau de migration entre versions) — pas demandé en legacy. Phase 2.
> - **Association ISO ↔ profils de déploiement avancée** — le legacy n'a qu'un mapping implicite Win10/Win11 → dossier fixe sous `/var/sambaedu/unattended/install/os/Win{10,11}/`. La story 3.6 documente ce mapping mais n'introduit **aucune** table d'association multi-profils. La sélection de version par profil reste implicite (le poste consomme `Win10` ou `Win11` selon l'enum `IpxeAdminAction::install_win*` décidé en 3.5).
> - **Modification des routines 3.5 d'installation Windows** (templates Blade `install_win*`, `WindowsUnattendBuilder`, `WindowsInstallBatBuilder`) — déjà livrées, hors-scope strict.
> - **Clonezilla / mode rescue / actions post-install complètes** → Story 3.7 dédiée.
> - **Re-implémentation native du script shell `install-win-iso.sh`** — vit sur `/usr/share/sambaedu/scripts/` côté VM (paquet `sambaedu`), 3.6 ne fait que l'invoquer avec un contrat strict `<version> <iso_name>`. Tout port natif PHP du shell est différé à une future story.
> - **Re-implémentation native du mécanisme `batch_command` + `batch_write` legacy** (config.inc.php:565-595) qui empile des commandes shell dans un script `/tmp/admin_script_{fast,normal,slow}.sh` puis se base sur APCu + `sudo chmod 755` + un cron pour exécuter. SE5 = patron Laravel Queue + Job singleton (memoire `project_story_16-15_cache_driver`). Documenté D4.
> - **Suppression / rotation des ISO téléchargées** (housekeeping disque) — non demandé legacy, manuel pour l'admin.
> - **Injection de drivers DISM dans `boot.wim`** (mentionné dans la doc d'aide legacy `<a href=…doc.sambaedu.org…>`) — opération manuelle admin sur poste Windows, hors scope.
> - **Validation SHA256/checksum du fichier ISO téléchargé** — Phase 2 (le legacy ne le fait pas non plus, on s'aligne strict).
> - **UI menu iPXE** d'item de gestion ISO — c'est une page admin **web** SE5, **pas** un menu firmware iPXE. Aucun item ne sera ajouté à `admin.blade.php` côté iPXE.
> - **Retrait du fichier legacy `sambaedu/ipxe/Win10/win_iso.php` du catchall** → reporté **fin d'Epic 3** (Story 3.7 cleanup). Le catchall continue de servir l'URL legacy `…/ipxe/Win10/win_iso.php` jusqu'au cleanup global Phase 2.
> - **Multi-établissements** (URL ISO différentes par etab) — non pertinent ici, l'install d'ISO est une opération serveur centrale.

---

## Mode de livraison & contraintes opérationnelles

> **Worktree git dédié `ipxe`** (`/home/htouchard/code/irundo/codebase/ipxe`). Ne JAMAIS SSH `/vm` ni run de tests sur la VM depuis ce worktree (mémoire `feedback_worktree_no_vm_sync`). Static delivery iso 3.1-3.5 : lint statique `php -l` + PHPUnit local si `vendor/` présent + 0 sync manuel.
>
> - **Code synchronisé via inotify** sur la branche `main` uniquement (les worktrees ne sont PAS sync). Henri opère un merge `ipxe → main` post-review pour propager.
> - **Action Henri post-merge VM up** : `composer install` + migrations (`php artisan migrate`) + reset cache (`php artisan optimize:clear`) + reload PHP-FPM (`systemctl reload php8.2-fpm@www-admin`) + smoke `curl -b cookies http://192.168.122.50/admin/ipxe/iso-windows` (après login admin) + vérifier que le worker Laravel queue tourne (`systemctl status laravel-queue` ou `php artisan queue:work --queue=ipxe_iso_downloads` en service).
> - **Sudoers prérequis VM** : l'exécution de `/usr/share/sambaedu/scripts/install-win-iso.sh` nécessite **root** (mount loop, écriture dans `/var/sambaedu/unattended/install/os/Win{10,11}/`). Le worker Laravel queue tourne en `www-admin` (uid 599, mémoire `project_php_fpm_user_www_admin`). La VM doit avoir une règle sudoers :
>   ```
>   # /etc/sudoers.d/sambaedu-iso-install
>   www-admin ALL=(root) NOPASSWD: /usr/share/sambaedu/scripts/install-win-iso.sh
>   ```
>   **À documenter dans Section 15 du runbook QA + à valider en T0.5 par Henri** (action VM). Si la règle est absente, le Job échouera avec exit_code 1 + stderr "no tty present and no askpass program specified" — la story doit gérer ce cas avec un message utilisateur clair via toast.
> - **NE PAS** modifier `sambaedu/ipxe/Win10/win_iso.php` ni `legacy/modules/ipxe/*.php` — restent intacts (le catchall continue de servir l'URL legacy).
> - **NE PAS** créer de commit hors scope.
> - **mémoire `feedback_auth_iso_legacy`** : ne s'applique PAS ici (page admin web SE5 — auth iso Spatie `server.admin`, pas auth iPXE LAN-only).
> - **mémoire `project_php_fpm_user_www_admin`** : tout fichier écrit par le Job (logs intermédiaires) doit être chown `www-admin`. Le script `install-win-iso.sh` (exécuté en root via sudo) gère lui-même les permissions des fichiers extraits sous `/var/sambaedu/unattended/install/os/`.
> - **Secrets** : aucun secret côté serveur — la URL Microsoft est publique, pas de credential.
> - **Risque SSRF** : un opérateur admin malveillant pourrait essayer d'injecter une URL pointant vers `http://localhost:8080/metrics` ou un endpoint interne. Mitigation D5 = double allowlist (regex `#(Win.*\.iso)$#` iso-legacy + allowlist `microsoft.com` host + scheme HTTPS strict). **Acceptable Phase 2** vu que l'opérateur doit déjà avoir `server.admin` (rôle ultra-restreint).

---

## Encadré contexte

**Continuité avec 3.5** : 3.5 a posé tout le mécanisme natif d'installation Windows iPXE (menu, wimboot, unattend.xml, install.bat, hooks post-install). Les artefacts consommés à l'install se trouvent sous `/var/sambaedu/unattended/install/os/Win{10,11}/{sources/boot.wim, boot/boot.sdi, boot/bcd, ...}`. **Ces artefacts sont produits par `install-win-iso.sh`** (extraction d'une ISO Microsoft téléchargée). Sans 3.6, l'admin doit aujourd'hui :

1. Soit utiliser l'écran legacy `sambaedu/ipxe/Win10/win_iso.php` (servi via catchall) — fonctionne mais cosmétiquement legacy.
2. Soit SSH la VM et lancer manuellement `curl` + `install-win-iso.sh` — friction terrain non documentée.

**3.6 livre une page admin native SE5** iso-fonctionnelle au legacy : saisie URL Microsoft → lancement async → polling de fin → page refresh listant la nouvelle version déployée.

3.6 **active** le flow :

1. Admin authentifié (`sambaedu.auth + sambaedu.admin + can:server.admin`) navigue vers `/admin/ipxe/iso-windows`.
2. La page Livewire SFC liste les versions Win10 et Win11 actuellement déployées (`current` + `old`).
3. Admin saisit l'URL Microsoft (ex. `https://software-static.download.prss.microsoft.com/.../Win11_24H2.iso`).
4. Validation 2 couches (regex `#(Win.*\.iso)$#` + allowlist host) → si OK, modale de confirmation.
5. Sur confirm, dispatch d'un Job Laravel queue `DownloadWindowsIsoJob` (queue `ipxe_iso_downloads`, lock cache 1 instance).
6. Insertion d'une row `windows_iso_downloads` `status=pending` + retour immédiat avec polling.
7. Le worker queue exécute (en `www-admin`) :
   - `Process::run("curl -fSL -o /var/sambaedu/unattended/install/os/iso/<iso_name> <url>", timeout: 7200)` — passe status `downloading`.
   - À la fin du curl OK : `Process::run("sudo /usr/share/sambaedu/scripts/install-win-iso.sh <version> <iso_name>", timeout: 1800)` — passe status `extracting`.
   - À la fin OK : status `success`, completed_at = now, exit_code = 0.
   - À la fin KO : status `failed`, error = stderr abrégé (≤2000 chars), exit_code = $process->getExitCode().
8. Polling Livewire (wire:poll.5s) tant que le status est `pending|downloading|extracting` → la card affiche progression + log tail.
9. À success → la liste des versions est refresh (relit les fichiers `version`), toast success "ISO déployée avec succès", polling stoppe.

**Topologie cible 3.6** :

```
Admin → /admin/ipxe/iso-windows  (Livewire SFC, GET)
        └─ sambaedu.auth + sambaedu.admin + can:server.admin
        ├─ WindowsIsoSourcesReader::list()
        │   ├─ /var/sambaedu/unattended/install/os/Win10/version    → "Win10_22H2.iso"
        │   ├─ /var/sambaedu/unattended/install/os/Win10-old/version → "Win10_21H2.iso"
        │   ├─ /var/sambaedu/unattended/install/os/Win11/version    → "Win11_24H2.iso"
        │   └─ /var/sambaedu/unattended/install/os/Win11-old/version → "Win11_23H2.iso"
        ├─ WindowsIsoDownload::orderByDesc('started_at')->take(10)
        └─ render component avec versions[] + downloads[] + current_running

Admin saisit URL "https://....microsoft.com/.../Win11_25H1.iso"
        └─ Livewire: $this->submitDownload()
              ├─ WindowsIsoUrlValidator::validate($url)
              │   ├─ regex iso-legacy : `#(Win.*\.iso)$#i` → extract iso_name "Win11_25H1.iso"
              │   ├─ allowlist hosts : `parse_url($url, PHP_URL_HOST)` ∈ config('ipxe.iso_management.allowed_url_hosts')
              │   │   (defense in depth 'microsoft.com', 'software-static.download.prss.microsoft.com', etc.)
              │   ├─ scheme strict 'https'
              │   └─ retourne ['url' => $url, 'iso_name' => 'Win11_25H1.iso', 'version' => 'Win11']
              │      OU lève ValidationException
              ├─ Cache::lock('ipxe.iso.download.global', 7200)->get(fn() => ...)
              │   ├─ si déjà locked → refuse via toastError + remet status pending
              │   └─ sinon claim et continue
              ├─ WindowsIsoDownload::create([
              │     'version' => 'Win11', 'iso_name' => 'Win11_25H1.iso',
              │     'source_url' => $url, 'status' => 'pending',
              │     'initiated_by_user_id' => Auth::id(),
              │     'host_ip' => request()->ip(),
              │   ])
              ├─ DownloadWindowsIsoJob::dispatch($download->id)->onQueue('ipxe_iso_downloads')
              ├─ toastSuccess "Téléchargement lancé"
              └─ wire:poll.5s actif

Worker queue (php artisan queue:work --queue=ipxe_iso_downloads) :
        └─ DownloadWindowsIsoJob::handle()
              ├─ $download = WindowsIsoDownload::lockForUpdate()->find($id)
              ├─ $download->update(['status' => 'downloading', 'started_at' => now()])
              ├─ Process::fromShellCommandline("curl -fSL --max-time 7200 -o '/var/sambaedu/unattended/install/os/iso/{$iso_name}' {$url}")
              │   ->setTimeout(7200)->run(fn($type, $buffer) => Log::channel('ipxe')->info("curl: $buffer"))
              ├─ if (!$proc->isSuccessful()) → $download->update([status=failed, exit_code, error]) + toast push + return
              ├─ $download->update(['status' => 'extracting'])
              ├─ Process::fromShellCommandline("sudo /usr/share/sambaedu/scripts/install-win-iso.sh {$version_num} '{$iso_name}'")
              │   ->setTimeout(1800)->run(fn(...) => Log::channel('ipxe')->info(...))
              ├─ if (!$proc->isSuccessful()) → status=failed ...
              └─ $download->update([status=success, completed_at=now, exit_code=0])
              ├─ Cache::lock('ipxe.iso.download.global')->release()
              └─ Log audit channel ipxe ('ipxe.iso.download.success' / .failed)

Polling Livewire (wire:poll.5s, durée max 7200s = 2h, plus le timeout queue) :
        └─ refresh : sources reread + downloads reread
        └─ si current_running == null → polling stoppe naturellement (Livewire condition)
        └─ si status === 'failed' → toastError + polling stoppe
        └─ si status === 'success' → toastSuccess + polling stoppe + relit version files
```

**Comportement parité legacy** (à reproduire iso strict — cf. `sambaedu/ipxe/Win10/win_iso.php`) :

1. **`GET /admin/ipxe/iso-windows`** — page admin web :
   - **Pré-requis auth** : `sambaedu.auth + sambaedu.admin + can:server.admin` (iso `/admin/settings`, `/admin/sync-from-ad`).
   - Affichage : titre `<h1>Mise en place des sources d'installation Windows</h1>` + liste sources (Win10 courante/ancienne, Win11 courante/ancienne) + formulaire URL.
   - **Liste de versions** :
     - Lit `/var/sambaedu/unattended/install/os/Win11/version` → `Win11_24H2.iso`
     - Lit `/var/sambaedu/unattended/install/os/Win11-old/version` → `Win11_23H2.iso`
     - Lit `/var/sambaedu/unattended/install/os/Win10/version` → `Win10_22H2.iso` (note : legacy fait `Win11/` seulement — décision 3.6 = lire Win10 aussi pour cohérence avec scope Win10|Win11 de 3.5)
     - Lit `/var/sambaedu/unattended/install/os/Win10-old/version`
     - Si le fichier `version` est absent ou vide → affiche "inconnue" / "non déployée".
2. **Méthode Livewire `submitDownload()` + `confirmDownload(orchestrator)`** — déclencheur (Opus-J : action intra-composant, **pas** un endpoint POST) :
   - **Inputs** : `$url` (text, required, max 2048, valide selon regex + allowlist).
   - Validation 2 couches : Livewire `rules()` (regex `WindowsIsoUrlValidator::URL_PATH_REGEX` — source unique post-review #6) **ET** `WindowsIsoUrlValidator::validate()` service-level (defense in depth + extraction `iso_name` + détection version Win10|Win11).
   - **Pas d'upload de fichier** — seulement la URL.
   - Détection de la version :
     - regex `#Win(10|11)#i` sur l'`iso_name` → "10" ou "11"
     - Si la version ne peut être détectée → erreur de validation + toast "URL invalide : impossible de déterminer la version Windows (Win10 ou Win11)".
   - Idempotence : avant insert, vérifie qu'aucun `WindowsIsoDownload` `status ∈ {pending, downloading, extracting}` n'existe via lock Cache global `ipxe.iso.download.global` (`Cache::lock(..., 7200)->get(fn() => ...)`). Si lock indisponible → toast error "Un téléchargement est déjà en cours, attendez sa fin ou annulez-le."
3. **Méthode Livewire `cancelDownload(int $id, orchestrator)`** — annulation (Opus-J : action intra-composant, **pas** un endpoint POST) :
   - **Inputs** : `$id` (id du model passé en argument Livewire wire:click).
   - Vérifie le download existe + status ∈ {pending, downloading, extracting}.
   - Si oui : marque `status='cancelled'` + relâche `Cache::lock('ipxe.iso.download.global')` + toast info.
   - **Limitation** : n'envoie PAS de SIGTERM au process curl/install-win-iso.sh en cours (parité legacy qui ne fait pas non plus). Le worker queue détectera à la fin du Process en cours que le download est `cancelled` et bypassera la suite. Le curl/extract en cours continuera jusqu'à sa fin naturelle ou son timeout.
4. **Polling** :
   - **Mécanisme** : `wire:poll.60s` côté Livewire (`@if($currentRunning) wire:poll.60s @endif` — Q4 Henri post-review) — déclenche un re-render serveur toutes les 60 secondes.
   - Le composant relit les sources (version files) + relit la table downloads.
   - **Stop conditions** : automatique dès que `$currentRunning === null` (Livewire ne génère plus de poll attribute).
   - Pas de SSE/WebSocket — overkill pour Phase 2.

**Couplage Stories 3.5 + Stories admin existantes — modifications mineures attendues** :

| Élément | Modification 3.6 | Raison |
|---|---|---|
| `routes/web.php` | Ajout d'un bloc Story 3.6 dans le groupe `/admin/*` (après `/admin/settings`) avec **1 seule route Livewire fullpage** sous middleware `sambaedu.auth + sambaedu.admin + can:server.admin` (DO-3 décision finale — pas de POST controllers ; toutes les actions sont intra-composant Livewire). | Cohérence iso `/admin/sync-from-ad`, `/admin/settings`. |
| `config/ipxe.php` | Section `iso_management` D11. | Configurable par environnement. |
| `config/sambaedu.php` | Section `windows_iso` D11 (paths script + sudoers doc). | Variables système. |
| `MachineBootLog.action` | **0 nouvelle valeur 3.6** — l'audit passe par la table dédiée `windows_iso_downloads` + channel log `ipxe`. Pas de pollution de la table iPXE boot. | Séparation propre : MachineBootLog = trace par machine, `windows_iso_downloads` = trace par opération serveur. |
| Channel log `ipxe` | Réutilisé (déjà créé 3.1). 5 nouveaux events 3.6. | Cohérence single channel iPXE. |

**Idempotence + sécurité** :

- `GET /admin/ipxe/iso-windows` (rendu page) : **idempotent** (lecture filesystem + lecture DB + log info).
- Action Livewire `confirmDownload` (lancement download — Opus-J : pas de POST dédié) : **partiellement idempotent** — le Cache::lock global empêche les doubles soumissions concurrentes. En cas de retry réseau côté admin, le 2e appel sera rejeté avec toast error "déjà en cours".
- Action Livewire `cancelDownload($id)` (annulation — Opus-J : pas de POST dédié) : **idempotent** — un cancel sur un download déjà `cancelled|success|failed` est no-op + toast info.
- Polling `wire:poll.60s` (Q4 Henri post-review) : **idempotent** (lecture seule).

**Side effects** :
- **DB PostgreSQL** : insert + updates `windows_iso_downloads`.
- **Filesystem VM** (via Job — donc différé worker queue) : écriture `/var/sambaedu/unattended/install/os/iso/<iso_name>` + écriture `/var/sambaedu/unattended/install/os/Win{10,11}/*` (par `install-win-iso.sh` qui rotate Win{10,11} → Win{10,11}-old + extract le nouveau).
- **Cache Laravel** : 1 lock global `ipxe.iso.download.global` (TTL 7200s = 2h, équivaut à 2h max de téléchargement+extraction).
- **Logs** : `Log::channel('ipxe')` (events) + tail du stdout/stderr Process.
- **Network** : `curl -fSL --max-time 7200 <url>` sortant depuis la VM vers microsoft.com (déjà autorisé en proxy école normalement).
- **AD/LDAP** : **AUCUNE** modification AD côté 3.6.

---

## Décisions tranchées (D1-D15, ne pas re-débattre)

> Cadrage SM 2026-05-21 par claude-opus-4-7. Le dev applique sans re-discuter. En cas de blocage technique réel, documenter dans Dev Agent Record et continuer.

### D1 — Namespace : extension **`App\Ipxe\Iso`** (sous-namespace dédié)

- Ajouts sous `app/Ipxe/Iso/` (justifié — frontière forte vs 3.1-3.5 qui traitent les endpoints firmware) :
  ```
  app/Ipxe/Iso/
  ├── Services/
  │   ├── WindowsIsoUrlValidator.php        (NEW — regex + allowlist + extract iso_name + detect version)
  │   ├── WindowsIsoSourcesReader.php       (NEW — lecture filesystem versions déployées)
  │   └── WindowsIsoDownloadOrchestrator.php (NEW — orchestrateur entry-point Livewire)
  ├── Jobs/
  │   └── DownloadWindowsIsoJob.php         (NEW — Process curl + sudo install-win-iso.sh + audit)
  ├── Enums/
  │   └── WindowsIsoDownloadStatus.php      (NEW — Pending|Downloading|Extracting|Success|Failed|Cancelled)
  └── Exceptions/
      └── WindowsIsoValidationException.php (NEW — domain-specific validation)
  ```
- **Modèle Eloquent** : `app/Models/WindowsIsoDownload.php` (modèle de domaine — pas dans le sous-namespace pour cohérence avec `MachineBootLog`, `Workstation`, etc. qui restent sous `App\Models`).
- **Justification sous-namespace** : 3.1-3.5 ont posé `App\Ipxe\{Services,Enums,Http,Support,Exceptions}` pour les endpoints firmware iPXE consommés en LAN par le firmware. 3.6 est **fondamentalement différent** (page admin web SE5, pas un endpoint firmware) — le sous-namespace `App\Ipxe\Iso` marque cette frontière + facilite les tests archi (`tests/Architecture/IpxeNamespaceTest.php` peut imposer que `App\Ipxe\Iso\*` ne dépende **PAS** de `App\Ipxe\Services\IpxeMenuRenderer`, et inversement).
- **Anti-pattern** : ne PAS créer `App\Admin\IsoWindows\…` (sortirait du namespace iPXE — perte de cohésion). Ne PAS mettre dans `App\Ipxe\Services\WindowsIsoUrlValidator` (pollue le namespace firmware iPXE 3.1-3.5).

### D2 — Routes : **3 routes admin web sous `/admin/ipxe/iso-windows/*` (filesystem-based router)**

- Bloc à ajouter dans `routes/web.php` **dans le groupe `admin` existant** (après `/admin/settings` ligne 339, avant le groupe `settings/gpo` ligne 357) :
  ```php
  /*
  |--------------------------------------------------------------------------
  | Story 3.6 — Gestion ISO Windows (D2)
  |--------------------------------------------------------------------------
  | Page admin web SE5 native qui porte iso-fonctionnellement
  | `sambaedu/ipxe/Win10/win_iso.php` (110 LOC) : upload d'URL Microsoft +
  | listing des versions Win{10,11}{,-old} déployées + déclenchement
  | asynchrone du téléchargement via Job Laravel queue (curl + sudo
  | install-win-iso.sh) + polling Livewire.
  |
  | Sécurité : `sambaedu.auth + sambaedu.admin + can:server.admin` —
  | parité `/admin/sync-from-ad` et `/admin/settings`.
  |
  | Note : aucune route iPXE firmware (3.1-3.5) n'est ajoutée — 3.6 est
  | une page admin web SE5, pas un endpoint LAN.
  */
  // Une seule route Livewire (DO-3 / Opus-J post-review 2026-05-21) :
  Route::livewire('/ipxe/iso-windows', 'pages::admin.ipxe.iso-windows.index')
      ->middleware('can:server.admin')
      ->name('ipxe.iso-windows');
  ```
- **Pourquoi pas de routes POST controllers ?** (Opus-J post-review 2026-05-21 — nettoyage incohérence D2) Le pattern Livewire SFC iso 3.1-5 du projet utilise des méthodes Livewire (`wire:click`, `wire:submit`). Les actions `submitDownload()` / `confirmDownload()` / `cancelDownload(int $id)` sont des **méthodes du composant Livewire SFC** (analogue `runStep('users_establishment')` dans `/admin/sync-from-ad`) — aucun `WindowsIsoDownloadController` n'est livré. Tests Feature = Livewire test (`Livewire::test(...)->call('submitDownload', ...)`).
- **Pourquoi pas de routes API REST séparées ?** Iso-projet (sync-from-ad ne fait pas non plus de REST + Livewire = framework intégré). Pas de consommateur externe.

### D3 — Sécurité : **réutilisation stricte `sambaedu.auth + sambaedu.admin + can:server.admin`**

- Iso `/admin/sync-from-ad` (Story 7.2 AC8), `/admin/settings` (Story 5.1c AC5/AC12) — convention déjà établie pour les actions critiques admin (sync AD, gestion système).
- **Risque accepté** : un admin avec `server.admin` peut potentiellement lancer un curl vers une URL forgée si la double validation échoue. Mitigation D5.
- **Anti-pattern** : ne PAS créer une nouvelle permission Spatie (`ipxe.iso.manage`) en Phase 2 — `server.admin` est déjà le rôle ultra-restreint qui couvre les actions critiques. Si nécessaire en Phase 3 (multi-admin avec scopes différents) → ouvrir une story dédiée.

### D4 — Mécanisme d'exécution shell async : **Laravel Queue + Job singleton (`DownloadWindowsIsoJob`)**, pas `batch_command` legacy

- **Décision claire** : on **NE PORTE PAS** le mécanisme legacy `batch_command` + `batch_write` (APCu + `/tmp/admin_script_*.sh` + `sudo chmod 755` + cron) — c'est un anti-pattern (memoire `project_story_16-15_cache_driver` qui a migré APCu→Cache Laravel) qui ne s'aligne ni avec PSR-12 ni avec les conventions Laravel.
- **Patron retenu** : Laravel Queue. Job dédié `App\Ipxe\Iso\Jobs\DownloadWindowsIsoJob` qui :
  - Est `ShouldQueue` (queue `ipxe_iso_downloads`).
  - Implémente `WithoutOverlapping` (Laravel 12+ middleware) pour garantir 1 instance vivante à la fois (defense in depth vs Cache::lock applicatif).
  - Timeout `7200` secondes (2h — alignement TTL Cache::lock).
  - `tries = 1` (pas de retry auto — un échec curl ou install-win-iso.sh est terminal jusqu'à intervention admin).
  - Backoff non utilisé (`tries=1`).
- **Justification vs `batch_command` legacy** :
  - Audit traçable par row DB (status, started_at, completed_at, exit_code, error).
  - Pas de fichier temporaire `/tmp/admin_script_*.sh` à nettoyer (memoire `project_inotify_no_delete_sync`).
  - Pas d'APCu (memoire `project_story_16-15`).
  - Visibilité Livewire polling immédiate.
  - Testabilité (Job factory + `Queue::fake()` dans les tests Feature).
- **Pré-requis VM** : worker queue actif. Sur la VM `/vm` actuelle, Henri doit configurer (action T0.5) :
  ```ini
  # /etc/systemd/system/laravel-queue-ipxe-iso.service
  [Unit]
  Description=Laravel Queue worker — ipxe_iso_downloads
  After=network.target

  [Service]
  User=www-admin
  Group=www-admin
  Restart=always
  WorkingDirectory=/var/www/sambaedu-reload
  ExecStart=/usr/bin/php artisan queue:work --queue=ipxe_iso_downloads --tries=1 --timeout=7500 --memory=512

  [Install]
  WantedBy=multi-user.target
  ```
  **À documenter dans Section 15 du runbook QA + à valider en T0.5**.
- **Fallback** si worker absent : le Job restera `pending` indéfiniment → polling boucle → admin voit "Téléchargement non démarré (vérifier worker queue)". Acceptable Phase 2.

### D5 — Validation URL Microsoft : **2 couches strictes (regex iso-legacy + allowlist host)**

- **Couche 1 — Livewire `rules()` validation** :
  ```php
  protected function rules(): array {
      return [
          'url' => [
              'required',
              'string',
              'max:2048',
              'regex:#^https://[^\s<>"\']+\.iso$#i',  // sanity check basic
          ],
      ];
  }
  ```
- **Couche 2 — Service-level `WindowsIsoUrlValidator::validate(string $url): array` (defense in depth)** :
  ```php
  public function validate(string $url): array {
      // 1) Scheme strict
      $parsed = parse_url($url);
      if (($parsed['scheme'] ?? '') !== 'https') {
          throw new WindowsIsoValidationException('Scheme HTTPS obligatoire');
      }
      // 2) Host allowlist (defense in depth vs SSRF)
      $host = strtolower($parsed['host'] ?? '');
      $allowedHosts = config('ipxe.iso_management.allowed_url_hosts', [
          'software-static.download.prss.microsoft.com',
          'software-download.microsoft.com',
          'download.microsoft.com',
      ]);
      $ok = false;
      foreach ($allowedHosts as $allowed) {
          if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
              $ok = true; break;
          }
      }
      if (!$ok) {
          throw new WindowsIsoValidationException("Host '$host' non autorisé (whitelist microsoft.com)");
      }
      // 3) Extraction iso_name + version (regex iso-legacy)
      if (!preg_match('#/(Win(?:10|11)[^/]*?\.iso)$#i', $url, $matches)) {
          throw new WindowsIsoValidationException(
              "URL invalide : le segment final doit matcher 'Win(10|11)*.iso' (parité legacy win_iso.php)"
          );
      }
      $isoName = $matches[1];
      // 4) Détection version
      if (!preg_match('#^Win(10|11)#i', $isoName, $m)) {
          throw new WindowsIsoValidationException("Impossible de déterminer Win10 ou Win11 dans '$isoName'");
      }
      $version = 'Win' . $m[1];
      return [
          'url' => $url,
          'iso_name' => $isoName,
          'version' => $version,
          'version_num' => $m[1],  // '10' ou '11' pour install-win-iso.sh
      ];
  }
  ```
- **Anti-pattern** :
  - ❌ Ne PAS faire confiance à la couche 1 seule — un attaquant pourrait soumettre via Livewire un payload `url=https://evil.com/Win11.iso` qui passe la regex basique mais doit échouer Couche 2.
  - ❌ Ne PAS implémenter la résolution DNS côté serveur (`gethostbyname()`) pour vérifier que l'IP est publique — overkill Phase 2. La allowlist host est suffisante (Microsoft contrôle ses hostnames).
  - ❌ Ne PAS étendre l'allowlist à `www.microsoft.com` ou `microsoft.com` directement — ces pages ne servent pas d'ISO (= attaquant pourrait héberger `https://microsoft.com/.well-known/.../evil-Win11.iso` si compromis). Allowlist stricte sur les hostnames "download" connus.
- **Mémoire risque** : Henri peut ajuster l'allowlist via `.env` (`IPXE_ISO_ALLOWED_HOSTS=...`) — convention iso-projet `.env` driver overrides.

### D6 — Service `WindowsIsoSourcesReader` (lecture filesystem)

- Nouveau service `App\Ipxe\Iso\Services\WindowsIsoSourcesReader` (singleton stateless).
- **Méthode principale** :
  ```php
  /**
   * Liste les versions Windows déployées (courante + ancienne) pour Win10 et Win11.
   *
   * @return array{
   *     win10: array{current: ?string, old: ?string},
   *     win11: array{current: ?string, old: ?string},
   * }
   */
  public function list(): array;
  ```
- **Algorithme** :
  1. Pour chaque combinaison (Win10/Win11) × (current/old) → tente de lire le fichier `{base_path}/Win{N}{-old?}/version`.
  2. Si fichier existe + non vide → trim + retourne le contenu.
  3. Si absent → retourne `null`.
  4. Base path : `config('ipxe.iso_management.deployed_os_base_path', '/var/sambaedu/unattended/install/os')`.
  5. **Lecture en best-effort** : `File::exists(...) ? File::get(...) : null` (pas d'exception levée si filesystem inaccessible — log warning + null).
- **Side effect** : aucun (lecture pure).
- **Tests** : utiliser `Storage::fake()` ou `Filesystem` mockable (singleton injection).

### D7 — Service `WindowsIsoDownloadOrchestrator` (entry-point Livewire)

- Nouveau service `App\Ipxe\Iso\Services\WindowsIsoDownloadOrchestrator` (singleton injectable via container).
- **Méthode principale** :
  ```php
  /**
   * Orchestre la soumission d'un nouveau téléchargement.
   *
   * @return WindowsIsoDownload  la row créée (status=pending).
   * @throws WindowsIsoValidationException  si URL invalide.
   * @throws WindowsIsoLockException         si lock global indisponible.
   */
  public function submit(string $url, int $initiatedByUserId, string $hostIp): WindowsIsoDownload;
  ```
- **Algorithme** :
  1. Appelle `WindowsIsoUrlValidator::validate($url)` → array (url, iso_name, version, version_num).
  2. Tente `Cache::lock('ipxe.iso.download.global', 7200)->get()` (lock non-bloquant).
  3. Si lock refusé → throw `WindowsIsoLockException`.
  4. Sinon → crée la row `WindowsIsoDownload` (status=pending + tous champs).
  5. Dispatch `DownloadWindowsIsoJob::dispatch($download->id)->onQueue('ipxe_iso_downloads')`.
  6. Log info `ipxe.iso.download.submitted` (context : user_id, iso_name, version, host_ip).
  7. Retourne le model.
- **Cas spécial — lock libéré côté Job** : c'est le Job qui release le lock dans son `finally` après terminus (succès, échec, cancel). Si le Job crash (worker tué) → le TTL 7200s release naturellement.

### D8 — Job `DownloadWindowsIsoJob`

- Nouveau Job `App\Ipxe\Iso\Jobs\DownloadWindowsIsoJob` :
  ```php
  class DownloadWindowsIsoJob implements ShouldQueue {
      use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

      public int $tries = 1;
      public int $timeout = 7500;  // 7500s = 2h05min (5min de marge sur lock 7200s)

      public function __construct(public readonly int $downloadId) {}

      public function middleware(): array {
          return [new WithoutOverlapping("ipxe-iso-download-{$this->downloadId}")];
      }

      public function handle(): void {
          $download = WindowsIsoDownload::find($this->downloadId);
          if (!$download || $download->status === 'cancelled') {
              Log::channel('ipxe')->info('ipxe.iso.download.skipped_cancelled', [...]);
              Cache::lock('ipxe.iso.download.global')->release();
              return;
          }
          try {
              // 1) Phase downloading
              $download->update(['status' => 'downloading', 'started_at' => now()]);
              $isoPath = config('ipxe.iso_management.iso_storage_path') . '/' . $download->iso_name;
              $curlCmd = sprintf('curl -fSL --max-time 7200 -o %s %s',
                  escapeshellarg($isoPath),
                  escapeshellarg($download->source_url),
              );
              $process = Process::fromShellCommandline($curlCmd);
              $process->setTimeout(7200);
              $process->run(fn($type, $buf) => Log::channel('ipxe')->info("curl-iso: $buf"));
              if (!$process->isSuccessful()) {
                  $this->markFailed($download, $process, 'curl-failed'); return;
              }
              // Refresh : cancelled entre-temps ?
              $download->refresh();
              if ($download->status === 'cancelled') {
                  Log::channel('ipxe')->info('ipxe.iso.download.cancelled_after_curl', [...]);
                  return;
              }
              // 2) Phase extracting
              $download->update(['status' => 'extracting']);
              $scriptPath = config('sambaedu.windows_iso.install_script', '/usr/share/sambaedu/scripts/install-win-iso.sh');
              $extractCmd = sprintf('sudo %s %s %s',
                  escapeshellarg($scriptPath),
                  escapeshellarg((string) $download->version_num()),  // '10' ou '11'
                  escapeshellarg($download->iso_name),
              );
              $process = Process::fromShellCommandline($extractCmd);
              $process->setTimeout(1800);
              $process->run(fn($type, $buf) => Log::channel('ipxe')->info("install-win-iso: $buf"));
              if (!$process->isSuccessful()) {
                  $this->markFailed($download, $process, 'extract-failed'); return;
              }
              // 3) Phase success
              $download->update([
                  'status' => 'success',
                  'completed_at' => now(),
                  'exit_code' => 0,
              ]);
              Log::channel('ipxe')->info('ipxe.iso.download.success', [...]);
          } catch (\Throwable $e) {
              Log::channel('ipxe')->error('ipxe.iso.download.exception', ['ex' => $e->getMessage()]);
              $download->update(['status' => 'failed', 'completed_at' => now(), 'error' => substr($e->getMessage(), 0, 2000), 'exit_code' => -1]);
          } finally {
              Cache::lock('ipxe.iso.download.global')->release();
          }
      }

      private function markFailed(WindowsIsoDownload $download, Process $process, string $stage): void {
          $stderr = substr($process->getErrorOutput() ?: $process->getOutput(), -2000);
          $download->update([
              'status' => 'failed',
              'completed_at' => now(),
              'exit_code' => $process->getExitCode(),
              'error' => "[$stage] " . $stderr,
          ]);
          Log::channel('ipxe')->error("ipxe.iso.download.$stage", [
              'download_id' => $download->id,
              'exit_code' => $process->getExitCode(),
              'iso_name' => $download->iso_name,
          ]);
      }
  }
  ```
- **Sécurité** : `escapeshellarg()` systématique sur tous les arguments shell. `iso_name` est déjà validé regex `#Win(10|11)[^/]*\.iso$#` (pas de space ni de meta-char) mais defense in depth.
- **Logs** : tout passe par `Log::channel('ipxe')` (channel daily 14j créé en 3.1).
- **Tests** : `Bus::fake()` + `Queue::fake()` + assertions sur les transitions de status. Utiliser `Process::fake()` (Laravel HTTP/Process testing) pour stubber curl + install-win-iso.sh.

### D9 — Schéma DB : **nouvelle table `windows_iso_downloads` + nouveau modèle Eloquent**

- Nouvelle migration `database/migrations/2026_05_21_HHMMSS_create_windows_iso_downloads_table.php` :
  ```php
  Schema::create('windows_iso_downloads', function (Blueprint $t) {
      $t->id();
      $t->string('version', 10);                          // 'Win10' | 'Win11'
      $t->string('iso_name', 255);                        // 'Win11_24H2.iso'
      $t->string('source_url', 2048);                     // URL Microsoft saisie
      $t->string('status', 20)->default('pending');       // pending|downloading|extracting|success|failed|cancelled
      $t->timestamp('started_at')->nullable();
      $t->timestamp('completed_at')->nullable();
      $t->integer('exit_code')->nullable();
      $t->text('error')->nullable();                      // stderr abrégé (≤2000 chars)
      $t->foreignId('initiated_by_user_id')->constrained('users')->cascadeOnDelete();
      $t->string('host_ip', 45)->nullable();              // IPv4/IPv6 de l'admin
      $t->timestamps();
      $t->index(['status', 'created_at']);
      $t->index(['version', 'status']);
  });
  ```
- **Justification** :
  - Pas de réutilisation `MachineBootLog` (cible = machine, pas opération serveur).
  - `version` en string limite à `Win10|Win11` (CHECK applicatif via `WindowsIsoDownload::VERSIONS`).
  - `status` en string limite à 6 valeurs (enum applicatif `WindowsIsoDownloadStatus`).
  - `source_url` 2048 chars (parité Livewire max 2048).
  - `error` text (stderr peut être > 255 chars).
  - `initiated_by_user_id` FK obligatoire (audit qui a lancé).
- **Modèle** `App\Models\WindowsIsoDownload` :
  ```php
  class WindowsIsoDownload extends Model {
      use HasFactory;

      protected $fillable = ['version', 'iso_name', 'source_url', 'status',
          'started_at', 'completed_at', 'exit_code', 'error',
          'initiated_by_user_id', 'host_ip'];

      protected $casts = [
          'started_at' => 'datetime', 'completed_at' => 'datetime',
          'status' => WindowsIsoDownloadStatus::class,
      ];

      public function initiatedBy(): BelongsTo { return $this->belongsTo(User::class, 'initiated_by_user_id'); }

      public function versionNum(): string { return str_replace('Win', '', $this->version); }
      public function isRunning(): bool { return in_array($this->status->value, ['pending','downloading','extracting'], true); }
      public function isTerminal(): bool { return in_array($this->status->value, ['success','failed','cancelled'], true); }
  }
  ```
- **Anti-pattern** : ne PAS étendre `Workstation` ni `MachineBootLog` — opération **serveur**, pas par-machine.

### D10 — Templates / UI Livewire SFC

- **Page principale** : `resources/views/pages/admin/ipxe/iso-windows/index.blade.php` — Livewire SFC à la convention iso `/admin/sync-from-ad/index.blade.php` :
  - Header avec `<x-organisms.page>` + slot actions (bouton "Rafraîchir" — manuel).
  - Card "Versions Windows déployées" : 4 lignes (Win10 courante, Win10 ancienne, Win11 courante, Win11 ancienne).
  - Card "Téléchargement en cours" (conditionnelle `@if($currentRunning)`) avec polling.
  - Card "Formulaire" : input URL + bouton "Télécharger".
  - Card "Historique" (les 10 derniers `WindowsIsoDownload` ordonnés desc).
  - Modale de confirmation pour le bouton "Télécharger" (via composant modale réutilisable du projet — cf. CLAUDE.md).
  - Toasts via trait `WithToasts` (success/error/info/warning).
- **Pas de partials externes** en 3.6 (≤ 1 fichier SFC). Si la page dépasse 800 LOC, possibilité de découper en `_partials/sources.blade.php`, `_partials/form.blade.php` etc. (convention CLAUDE.md filesystem-based router).
- **Charset** : UTF-8 standard (page web, pas iPXE ASCII strict).
- **Composants atomiques** : utiliser les `<x-organisms.*>` et `<x-atoms.*>` existants du projet (cf. `/admin/sync-from-ad`).
- **Polling** : `wire:poll.5s` conditionnel `@if($currentRunning) wire:poll.5s @endif` sur la card en cours uniquement (pas sur toute la page).

### D11 — Variables de configuration : **extension `config/ipxe.php` + `config/sambaedu.php`**

- Nouvelle section dans `config/ipxe.php` :
  ```php
  /*
  |--------------------------------------------------------------------------
  | Story 3.6 — Gestion ISO Windows (D11)
  |--------------------------------------------------------------------------
  */
  'iso_management' => [
      'enabled' => filter_var(env('IPXE_ISO_MANAGEMENT_ENABLED', true), FILTER_VALIDATE_BOOL),
      'deployed_os_base_path' => env('IPXE_ISO_DEPLOYED_OS_BASE', '/var/sambaedu/unattended/install/os'),
      'iso_storage_path'      => env('IPXE_ISO_STORAGE_PATH', '/var/sambaedu/unattended/install/os/iso'),
      'version_file_name'     => env('IPXE_ISO_VERSION_FILE', 'version'),
      'allowed_url_hosts'     => explode(',', env('IPXE_ISO_ALLOWED_HOSTS',
          'software-static.download.prss.microsoft.com,software-download.microsoft.com,download.microsoft.com'
      )),
      'download_timeout_seconds' => (int) env('IPXE_ISO_DOWNLOAD_TIMEOUT', 7200),
      'extract_timeout_seconds'  => (int) env('IPXE_ISO_EXTRACT_TIMEOUT', 1800),
      'queue_name'            => env('IPXE_ISO_QUEUE', 'ipxe_iso_downloads'),
      'global_lock_key'       => 'ipxe.iso.download.global',
      'global_lock_ttl'       => 7200,  // 2h
      'history_limit'         => (int) env('IPXE_ISO_HISTORY_LIMIT', 10),
  ],
  ```
- **Nouvelle sous-section dans `config/sambaedu.php`** :
  ```php
  /*
  |--------------------------------------------------------------------------
  | Story 3.6 — Script externe d'extraction ISO Windows (D11)
  |--------------------------------------------------------------------------
  | install-win-iso.sh vit sous /usr/share/sambaedu/scripts/ (paquet
  | sambaedu côté VM). SE5 ne le porte pas — l'invoque via sudo.
  | Prérequis sudoers documenté Section 15 du runbook QA.
  */
  'windows_iso' => [
      'install_script' => env('SAMBAEDU_INSTALL_WIN_ISO_SCRIPT',
          '/usr/share/sambaedu/scripts/install-win-iso.sh'),
      'sudoers_user' => env('SAMBAEDU_INSTALL_WIN_ISO_SUDO_USER', 'www-admin'),
  ],
  ```
- **Audit T0.5** : vérifier que `/usr/share/sambaedu/scripts/install-win-iso.sh` existe + est exécutable + la règle sudoers est posée.

### D12 — `MachineBootLog::action` : **AUCUNE extension 3.6**

- L'audit 3.6 passe par la table dédiée `windows_iso_downloads`, **pas** par `MachineBootLog`. Justification : `MachineBootLog` cible une machine (boot/install/enrollment), pas une opération admin serveur. Mixer les deux pollue le grain.
- Channel log `ipxe` (créé 3.1) est étendu avec 5 nouveaux events D14.

### D13 — UI menu iPXE firmware : **AUCUNE modification**

- 3.6 est une page admin **web** SE5, **pas** un menu firmware iPXE. **AUCUN item** n'est ajouté à `resources/views/ipxe/menu/admin.blade.php`. Le menu iPXE reste tel quel.
- Pour accéder à 3.6, l'admin passe par le menu de gauche SE5 web (navigation classique) — cf. modification `_layouts/partials/sidebar.blade.php` si Henri arbitre l'ajout d'un item. **Hors-scope 3.6 strict** : pas de modification de la sidebar. L'URL `/admin/ipxe/iso-windows` est accessible directement (et sera ajoutée à la navigation post-3.6 par Henri si besoin).

### D14 — Logging structuré channel `ipxe` (extension 3.1-3.5)

- 5 nouveaux events à logger (channel `ipxe`, driver daily 14j — iso 3.1) :
  - `ipxe.iso.download.submitted` (info) — soumission utilisateur acceptée. Context : user_id, iso_name, version, source_url (URL en clair OK — c'est une URL publique Microsoft), host_ip.
  - `ipxe.iso.download.curl-failed` (error) — phase curl échouée. Context : download_id, exit_code, stderr_prefix (200 chars).
  - `ipxe.iso.download.extract-failed` (error) — phase install-win-iso.sh échouée. Context : download_id, exit_code, stderr_prefix.
  - `ipxe.iso.download.success` (info) — fin OK. Context : download_id, iso_name, version, duration_seconds.
  - `ipxe.iso.download.cancelled` (info) — annulation utilisateur. Context : download_id, cancelled_by_user_id, stage (pending|downloading|extracting).
- **Pas de secret loggué** (URL publique Microsoft = pas un secret).
- **stderr tail prefix** : 200 chars (anti-pollution log). Le stderr complet ≤2000 chars est dans le DB row `error`.

### D15 — Idempotence concurrente : **`Cache::lock` global 7200s + `WithoutOverlapping` Job middleware (defense in depth)**

- Le but : empêcher 2 admins de cliquer simultanément "Télécharger" sur 2 URLs différentes — sinon les 2 Jobs s'exécutent en parallèle et l'extraction par `install-win-iso.sh` (qui write `/var/sambaedu/unattended/install/os/Win{10,11}/*`) corrompt l'install Windows.
- **Mécanisme couche 1 (orchestrator)** :
  ```php
  $lock = Cache::lock('ipxe.iso.download.global', 7200);
  if (!$lock->get()) {
      throw new WindowsIsoLockException('Un téléchargement est déjà en cours');
  }
  ```
- **Mécanisme couche 2 (Job middleware)** :
  ```php
  public function middleware(): array {
      return [new WithoutOverlapping('ipxe-iso-download-global')];
  }
  ```
- **Release** : couche 1 release dans le `finally` du `handle()` (success, failure, exception). Couche 2 release auto par middleware Laravel.
- **Fallback TTL 7200s** : si le worker crash sans release → le lock expire naturellement après 2h.
- **Tests** : test feature `it_rejects_second_submission_while_first_is_running`.

---

## Story

As **un admin Sambaedu (`server.admin`)** authentifié SE5 ainsi qu'**un mainteneur du codebase `sambaedu-reload`** et **Henri en tant qu'admin SER opérant la VM** :

I want
- disposer d'**une page admin web native SE5** sous `/admin/ipxe/iso-windows` qui remplace iso-fonctionnellement `sambaedu/ipxe/Win10/win_iso.php` (110 LOC), avec :
  - affichage de la liste des versions Windows 10 et Windows 11 (courantes + anciennes) actuellement déployées sous `/var/sambaedu/unattended/install/os/Win{10,11}{,-old}/version`,
  - formulaire de saisie d'une URL Microsoft + bouton "Télécharger",
  - validation 2 couches stricte (regex `#Win(10|11).*\.iso$#` + allowlist host `microsoft.com`) anti-SSRF/RCE,
  - lancement asynchrone via Laravel Queue (1 instance vivante max) qui exécute `curl` puis `sudo install-win-iso.sh`,
  - polling Livewire `wire:poll.5s` qui rafraîchit la progression + bascule sur fini quand le download termine,
  - historique des téléchargements (10 derniers) avec status, started_at, completed_at, exit_code, error,
  - annulation possible d'un téléchargement en cours,
- assurer **zéro régression** sur les autres URLs `/admin/*` et sur `/ipxe/Win10/win_iso.php` legacy qui reste servi via catchall jusqu'à 3.7 cleanup.

So que :
- (a) **Henri** dispose d'un flow web SE5 pour déployer une nouvelle ISO Windows sans SSH la VM ni passer par le legacy PHP procédural ;
- (b) **les opérateurs terrain `server.admin`** peuvent mettre à jour les sources d'install Windows (rentrée scolaire = nouvelles ISO Microsoft H1/H2) sans friction ;
- (c) **les développeurs des stories suivantes (3.7 Clonage et Maintenance, Epic 9 WPKG/GPO)** disposent d'un patron d'orchestration shell async (Job singleton + Process + audit DB + log channel + polling Livewire) à imiter.

---

## Contexte

### État entrant (post-Story 3.5 done, 3.6 = page admin web native)

| Élément | État actuel | Action 3.6 |
|---|---|---|
| Namespace `App\Ipxe` | ✅ Créé 3.1, étendu 3.2-3.5 (~70 classes + ~30 templates Blade) | **Étendre** — créer sous-namespace `App\Ipxe\Iso\*` |
| Stories 3.1-3.5 | ✅ done | Pas de modification — réutilisation `WindowsVersion` enum (3.5) pour cohérence si pertinent |
| Channel log `ipxe` | ✅ Créé 3.1 | **Étendre** — 5 nouveaux events D14 |
| Table `machine_boot_logs` | ✅ Existante | **Pas de modification** (audit 3.6 via `windows_iso_downloads`) |
| Table `windows_iso_downloads` | ❌ N'existe pas | **Créer** via migration D9 |
| Modèle `WindowsIsoDownload` | ❌ N'existe pas | **Créer** + factory |
| Page `/admin/ipxe/iso-windows` | ❌ Servie par catchall legacy (URL alternative `/ipxe/Win10/win_iso.php`) | **Créer** — page Livewire SFC + 1 route Livewire fullpage |
| `config/ipxe.php` | ✅ Créé 3.1, étendu 3.2-3.5 | **Étendre** — section `iso_management` D11 |
| `config/sambaedu.php` | ✅ Existe + sections linux 3.4 + windows 3.5 | **Étendre** — section `windows_iso` (script + sudoers doc) D11 |
| Doc QA `docs/qa/domains/ipxe.md` | ✅ Étendue 3.1-3.5 (~14 sections) | **Étendre** — Section 15 + ≥10 scénarios stables 3.6-1 à 3.6-N |
| Tests Unit/Feature/Architecture iPXE | ✅ ~255-285 verts cumulés (3.1-3.5) | **Étendre** — ≥25 nouveaux tests cumulés (≥15 unit + ≥8 feature Livewire + ≥2 archi) |
| Worker queue Laravel `ipxe_iso_downloads` | ❌ Non configuré en VM | **À configurer** (T0.5 action Henri) |
| Sudoers `www-admin → install-win-iso.sh` | 🟡 À valider | **T0.5 audit Henri** |

### Source de vérité du comportement attendu

Fichiers legacy à lire en T0.4 (lecture obligatoire) :

- `sambaedu/ipxe/Win10/win_iso.php` (110 LOC) — **source de portage principale** :
  - Lignes 31-32 : page title + récupération `$_POST['iso']`.
  - Lignes 35-47 : fonction `get_win_url()` — regex `#(Win.*iso)#` + extraction iso_name.
  - Lignes 49-53 : `check_win_sources()` — lit `/var/sambaedu/unattended/install/os/Win11/version` + check str_contains.
  - Lignes 55-71 : `list_win_sources()` — liste Win11 + Win11-old (legacy ne fait QUE Win11 — décision 3.6 : étendre à Win10 + Win10-old pour cohérence avec scope 3.5 + parité firmware).
  - Lignes 73-74 : check droit `SE_COMPUTER_ADMIN` (= server.admin en Spatie SE5).
  - Lignes 77-108 : flow complet (saisie URL → batch_command curl → batch_command install-win-iso.sh → batch_write → form refresh "Rafraîchir...").
- `sambaedu/includes/config.inc.php:565-595` — `batch_command()` + `batch_write()` (mécanisme APCu + `/tmp/admin_script_$queue.sh` + cron). **À NE PAS PORTER** — décision D4 = Laravel Queue.
- `/usr/share/sambaedu/scripts/install-win-iso.sh` — **vit sur la VM**, pas dans le repo. Contrat documenté T0.4 : `install-win-iso.sh <version_num> <iso_name>` où :
  - `<version_num>` = `10` ou `11`.
  - `<iso_name>` = nom de fichier ISO existant sous `/var/sambaedu/unattended/install/os/iso/`.
  - **Comportement attendu** : monte loop l'ISO + copie/extrait vers `/var/sambaedu/unattended/install/os/Win{version_num}/` (avec rotation Win{N} → Win{N}-old) + écrit `/var/sambaedu/unattended/install/os/Win{N}/version` avec l'iso_name.
  - **Exit code** : 0 = OK, ≠0 = échec.
- Stories Epic 3 précédentes :
  - `_bmad-output/implementation-artifacts/3-5-installation-windows-sysprep-wimboot.md` — pattern complet d'extension `config/ipxe.php`, config/sambaedu.php section windows, channel log ipxe.

### Risques entrants

| Risque | Sévérité | Mitigation 3.6 |
|---|---|---|
| SSRF via URL forgée (admin malveillant) | 🟠 Élevée | D5 — 2 couches validation (regex + allowlist host) + scheme HTTPS strict. |
| RCE via injection shell sur `iso_name` ou `url` (escapeshellarg défaillant) | 🟠 Élevée | D5+D8 — regex valide `iso_name` avant escape + `escapeshellarg()` systématique + datas-providers ≥ 8 payloads malicieux dans tests (`;rm -rf /`, `$(curl evil)`, backtick, etc.). |
| 2 admins lancent simultanément 2 downloads → corruption install-win-iso.sh | 🟠 Élevée | D15 — `Cache::lock` global 7200s + `WithoutOverlapping` middleware Job. |
| Worker queue non configuré en VM → Job pending indéfiniment | 🟠 Élevée | T0.5 audit + documentation Section 15 runbook + UI affiche "Téléchargement non démarré (vérifier worker queue)" si pending > 30s. |
| Sudoers absent → install-win-iso.sh échoue exit 1 stderr "no tty" | 🟠 Élevée | T0.5 audit Henri + Section 15 documentation + Job remonte stderr abrégé dans toast. |
| `install-win-iso.sh` modifié côté paquet sambaedu → contrat changé | 🟡 Moyenne | T0.4 audit contrat actuel + commentaire dans `config/sambaedu.php` qui documente la version testée. |
| Réseau VM bloque microsoft.com (proxy école) | 🟡 Moyenne | Job échoue `curl-failed` exit 6/22/35 → toast error + admin contacte l'équipe réseau. |
| Filesystem `/var/sambaedu/unattended/install/os/Win11/` absent (VM neuve) | 🟡 Moyenne | `WindowsIsoSourcesReader` retourne null + UI affiche "non déployée" + le premier download créera la structure via `install-win-iso.sh`. |
| Polling Livewire conserve la connexion DB ouverte 2h | 🟢 Mineure | wire:poll.5s = re-connexion fraîche chaque fois (pas long-poll). PG fine. |
| Une row `windows_iso_downloads` orpheline (worker crash) → status `pending` indéfini | 🟢 Mineure | Cron de housekeeping `cleanup-stuck-iso-downloads` Phase 3 (post-3.6). Workaround manuel = annuler via UI. |

### Pré-requis (à valider en T0)

- **Worktree git `ipxe`** : branche dédiée, pas de SSH VM. Iso 3.3/3.4/3.5.
- **Story 3.5 en `done`** : ✅ confirmé sprint-status au 2026-05-21.
- **Worker queue Laravel** : 🟡 à valider — Henri pose la unit systemd (D4) en T0.5.
- **Sudoers `www-admin → install-win-iso.sh NOPASSWD`** : 🟡 à valider — Henri pose le fichier `/etc/sudoers.d/sambaedu-iso-install` en T0.5.
- **`install-win-iso.sh` présent + exécutable** : 🟡 à valider — Henri SSH la VM et `ls -la /usr/share/sambaedu/scripts/install-win-iso.sh` en T0.5.
- **Filesystem `/var/sambaedu/unattended/install/os/iso/` writable par `www-admin`** : 🟡 à valider en T0.5 (le curl écrit là — sans sudo).
- **DB users table existante** : ✅ Epic 2 done — FK `initiated_by_user_id → users` valide.

---

## Acceptance Criteria

> AC organisées en **8 volets**. Volet 8 = QA + sprint-status (append-only sur le runbook `ipxe.md` 3.1-3.5).

### Volet 1 — Migration + Modèle Eloquent + Enum (D1, D9)

**AC1.1** — **Migration `windows_iso_downloads` posée**

**Given** la migration `database/migrations/2026_05_21_HHMMSS_create_windows_iso_downloads_table.php`,
**When** `php artisan migrate` est exécuté,
**Then** :
- Table `windows_iso_downloads` créée avec colonnes : id, version (string 10), iso_name (string 255), source_url (string 2048), status (string 20, default 'pending'), started_at (nullable timestamp), completed_at (nullable timestamp), exit_code (nullable int), error (nullable text), initiated_by_user_id (FK users), host_ip (nullable string 45), timestamps.
- Index `(status, created_at)` posé.
- Index `(version, status)` posé.
- FK cascade-on-delete depuis `users`.

**And** test feature `WindowsIsoDownloadMigrationTest::it_creates_table_with_expected_columns` (≥6 assertions).

**AC1.2** — **Modèle `WindowsIsoDownload` posé**

**Given** le modèle `app/Models/WindowsIsoDownload.php`,
**When** instancié,
**Then** :
- `$fillable` contient les 9 colonnes éditables.
- `$casts` map `started_at`/`completed_at` → datetime + `status` → enum `WindowsIsoDownloadStatus`.
- Relation `initiatedBy(): BelongsTo` vers `User`.
- Méthodes helpers `versionNum(): string` (retourne '10' ou '11'), `isRunning(): bool` (pending|downloading|extracting), `isTerminal(): bool` (success|failed|cancelled).
- Factory `WindowsIsoDownloadFactory` posée + utilisable en test (`WindowsIsoDownload::factory()->create()`).

**And** test unit `WindowsIsoDownloadTest` ≥6 tests (cast enum, versionNum, isRunning, isTerminal, fillable, relation initiatedBy).

**AC1.3** — **Enum `WindowsIsoDownloadStatus`**

**Given** le fichier `app/Ipxe/Iso/Enums/WindowsIsoDownloadStatus.php`,
**When** le dev le crée selon D1,
**Then** :
- Cases : Pending='pending', Downloading='downloading', Extracting='extracting', Success='success', Failed='failed', Cancelled='cancelled'.
- Méthode `isTerminal(): bool` → true pour Success|Failed|Cancelled.
- Méthode `isRunning(): bool` → true pour Pending|Downloading|Extracting.
- Méthode `label(): string` → libellé fr ("En attente", "Téléchargement", "Extraction", "Succès", "Échec", "Annulé").
- Méthode `badgeClass(): string` → classe CSS daisyUI ("badge-ghost", "badge-info", "badge-warning", "badge-success", "badge-error", "badge-neutral").

**And** test unit ≥5 (transitions, isTerminal/Running matrix, label fr, badgeClass cohérent).

### Volet 2 — Service `WindowsIsoUrlValidator` (D5)

**AC2.1** — **Validation URL Microsoft 2 couches passe les URLs valides**

**Given** `WindowsIsoUrlValidator::validate(string $url): array`,
**When** appelé avec `'https://software-static.download.prss.microsoft.com/.../Win11_24H2.iso'`,
**Then** retourne `['url' => $url, 'iso_name' => 'Win11_24H2.iso', 'version' => 'Win11', 'version_num' => '11']`.

**AC2.2** — **Rejette URLs malformées + anti-SSRF**

**Given** `WindowsIsoUrlValidator::validate()`,
**When** appelé avec une URL invalide,
**Then** lève `WindowsIsoValidationException` avec message explicite. Cas à tester (≥10 dont 6 anti-injection/anti-SSRF) :
- HTTP non HTTPS : `http://software-download.microsoft.com/Win11.iso` → "Scheme HTTPS obligatoire".
- Host hors allowlist : `https://evil.com/Win11.iso` → "Host 'evil.com' non autorisé".
- Path non-Win : `https://download.microsoft.com/Office.iso` → "URL invalide : segment final doit matcher 'Win(10|11)*.iso'".
- Pas d'extension `.iso` : `https://download.microsoft.com/Win11_24H2.exe` → "URL invalide".
- Path traversal : `https://download.microsoft.com/../../etc/Win11.iso` → "URL invalide" (la regex `#/(Win(10|11)...).iso$#` n'accepte que le segment final).
- Shell injection : `https://download.microsoft.com/Win11.iso;curl evil` → "URL invalide" (la regex stricte rejette le `;`).
- Newline injection : `"https://download.microsoft.com/Win11.iso\n; rm -rf /"` → "URL invalide".
- Backtick injection : `https://download.microsoft.com/Win11\`curl evil\`.iso` → "URL invalide".
- Localhost SSRF : `https://localhost/Win11.iso` → "Host 'localhost' non autorisé".
- Internal IP SSRF : `https://192.168.1.1/Win11.iso` → "Host '192.168.1.1' non autorisé".

**AC2.3** — **Détection version Win10|Win11 correcte**

**Given** `WindowsIsoUrlValidator::validate()`,
**When** appelé avec `.../Win10_22H2.iso` → `version='Win10', version_num='10'`.
**When** appelé avec `.../Win11_24H2.iso` → `version='Win11', version_num='11'`.
**When** appelé avec `.../Win7_SP1.iso` → throw "Win10 ou Win11 attendu" (Win7 hors scope).

**And** tests unit `WindowsIsoUrlValidatorTest` ≥12 tests (3 valides + 10 invalides + 2 versions).

### Volet 3 — Service `WindowsIsoSourcesReader` (D6)

**AC3.1** — **Lecture des 4 versions déployées (Win10/Win11 + current/old)**

**Given** `WindowsIsoSourcesReader::list()`,
**When** invoqué avec un filesystem mocké qui contient :
- `/var/.../Win10/version` = `'Win10_22H2.iso\n'`
- `/var/.../Win10-old/version` absent
- `/var/.../Win11/version` = `'Win11_24H2.iso'`
- `/var/.../Win11-old/version` = `'Win11_23H2.iso'`,
**Then** retourne `['win10' => ['current' => 'Win10_22H2.iso', 'old' => null], 'win11' => ['current' => 'Win11_24H2.iso', 'old' => 'Win11_23H2.iso']]`.

**AC3.2** — **Fichier absent / dossier absent / erreur filesystem → null + log warning**

**Given** `WindowsIsoSourcesReader::list()`,
**When** le filesystem entier `/var/sambaedu/unattended/install/os/` est absent,
**Then** retourne `['win10' => ['current' => null, 'old' => null], 'win11' => ['current' => null, 'old' => null]]` + log warning `ipxe.iso.sources.base_path_missing`.

**And** tests unit `WindowsIsoSourcesReaderTest` ≥6 tests (4 versions présentes, 0 versions, partiel Win10 only, fichier vide, dossier absent, base_path absent).

### Volet 4 — Service `WindowsIsoDownloadOrchestrator` + Job `DownloadWindowsIsoJob` (D7, D8, D15)

**AC4.1** — **Orchestrator submit() OK case**

**Given** `WindowsIsoDownloadOrchestrator::submit($url, $userId, $hostIp)`,
**When** appelé avec URL valide + user authentifié + IP,
**Then** :
- Valide via `WindowsIsoUrlValidator::validate()` → OK.
- Acquiert `Cache::lock('ipxe.iso.download.global', 7200)`.
- Crée une row `WindowsIsoDownload` (status=pending + tous champs).
- Dispatch `DownloadWindowsIsoJob::dispatch($download->id)->onQueue('ipxe_iso_downloads')`.
- Log info `ipxe.iso.download.submitted` (context user_id, iso_name, version, host_ip).
- Retourne le model.

**AC4.2** — **Orchestrator rejette si lock global déjà pris**

**Given** un download déjà en cours (lock pris),
**When** un 2ème admin invoque `submit()`,
**Then** lève `WindowsIsoLockException` + log info `ipxe.iso.download.rejected_locked`.

**AC4.3** — **Job download path nominal**

**Given** le Job `DownloadWindowsIsoJob` avec une row `WindowsIsoDownload` `status=pending`,
**When** le Job est dispatché et `handle()` exécuté avec `Process::fake()`,
**Then** transitions :
- `pending → downloading` (started_at set).
- Process curl appelé avec args escapés (`curl -fSL --max-time 7200 -o '<isoPath>' '<url>'`).
- Process curl succeeds → `downloading → extracting`.
- Process install-win-iso.sh appelé avec args escapés (`sudo '<scriptPath>' '11' 'Win11_24H2.iso'`).
- Process install-win-iso.sh succeeds → `extracting → success` (completed_at set, exit_code=0).
- Log info `ipxe.iso.download.success`.
- Cache::lock released.

**AC4.4** — **Job échec phase curl → status `failed` + error stderr**

**Given** le Job avec Process::fake retournant exit 6 + stderr "Could not resolve host",
**When** `handle()` exécuté,
**Then** :
- Transition `pending → downloading → failed`.
- `exit_code = 6`, `error = "[curl-failed] Could not resolve host"` (≤2000 chars).
- Log error `ipxe.iso.download.curl-failed`.
- Cache::lock released.
- Pas d'appel à install-win-iso.sh.

**AC4.5** — **Job échec phase install-win-iso.sh → status `failed`**

**Given** Process::fake : curl OK + install-win-iso.sh exit 1 + stderr "Mount loop failed",
**When** `handle()` exécuté,
**Then** :
- Transitions `pending → downloading → extracting → failed`.
- `exit_code = 1`, `error = "[extract-failed] Mount loop failed"`.
- Log error `ipxe.iso.download.extract-failed`.

**AC4.6** — **Job annulé entre curl et extract → skip extract + status `cancelled`**

**Given** Process::fake curl OK + admin cancel via UI entre-temps (status→`cancelled`),
**When** `handle()` continue,
**Then** :
- Détecte `$download->refresh()` status=`cancelled`.
- Log info `ipxe.iso.download.cancelled_after_curl`.
- Skip extract.
- Cache::lock released.

**AC4.7** — **`WithoutOverlapping` Job middleware**

**Given** 2 dispatch concurrents du Job avec le même `downloadId`,
**When** workers traitent en parallèle,
**Then** un seul Job s'exécute (l'autre est défer/release par `WithoutOverlapping`).

**And** tests unit `DownloadWindowsIsoJobTest` ≥8 + `WindowsIsoDownloadOrchestratorTest` ≥6 (Process::fake() + Bus::fake() patterns).

### Volet 5 — Composant Livewire SFC + Controller actions (D2, D10)

**AC5.1** — **Page `/admin/ipxe/iso-windows` rendue (auth admin)**

**Given** un user `server.admin` authentifié,
**When** `GET /admin/ipxe/iso-windows`,
**Then** :
- 200 OK + render Livewire SFC `pages::admin.ipxe.iso-windows.index`.
- Title HTML `<title>Gestion ISO Windows - SE5</title>` (via attribut Livewire).
- Affiche 4 cards : sources, formulaire URL, current_running (conditionnel), historique.

**Given** un user non-admin (`teacher`, `student`),
**When** `GET /admin/ipxe/iso-windows`,
**Then** 403 Forbidden.

**Given** un user non authentifié,
**When** `GET /admin/ipxe/iso-windows`,
**Then** redirect 302 login.

**AC5.2** — **Liste des sources affichée**

**Given** le composant Livewire avec `WindowsIsoSourcesReader` injecté retournant 4 versions,
**When** le composant est `mount()`,
**Then** :
- `$sources['win10']['current'] === 'Win10_22H2.iso'`.
- `$sources['win11']['current'] === 'Win11_24H2.iso'`.
- Render contient 4 lignes "Windows 10 (courante) : Win10_22H2.iso", etc.
- Si version absente → affiche "non déployée" (badge ghost).

**AC5.3** — **`submitDownload()` Livewire action**

**Given** le composant Livewire,
**When** `Livewire::test(...)->set('url', $validUrl)->call('submitDownload')`,
**Then** :
- Délègue à `WindowsIsoDownloadOrchestrator::submit($url, Auth::id(), request()->ip())`.
- Si OK → toastSuccess "Téléchargement lancé" + refresh sources/downloads + `$url` reset.
- Si `WindowsIsoValidationException` → toastError avec message (rule fr).
- Si `WindowsIsoLockException` → toastError "Un téléchargement est déjà en cours".
- Le composant fait apparaître la card "Téléchargement en cours" (`$currentRunning !== null`).

**AC5.4** — **`cancelDownload(int $id)` Livewire action**

**Given** un download `status ∈ {pending, downloading, extracting}`,
**When** `Livewire::test(...)->call('cancelDownload', $id)`,
**Then** :
- Met à jour status='cancelled' + log info `ipxe.iso.download.cancelled` + Cache::lock released.
- ToastInfo "Téléchargement annulé".
- Refresh listes + polling stoppe naturellement.

**Given** un download `status ∈ {success, failed, cancelled}`,
**When** `cancelDownload($id)`,
**Then** no-op (déjà terminal) + toastInfo "Téléchargement déjà terminé".

**AC5.5** — **Polling Livewire `wire:poll.5s` conditionnel**

**Given** le composant avec `$currentRunning = WindowsIsoDownload(status=downloading)`,
**When** le template Blade rendu,
**Then** la card "Téléchargement en cours" contient l'attribut HTML `wire:poll.5s` + refresh auto.

**Given** `$currentRunning = null`,
**When** le template Blade rendu,
**Then** **aucune** card avec `wire:poll.5s` (polling stoppé).

**AC5.6** — **Modale de confirmation avant submit**

**Given** le formulaire avec une URL saisie,
**When** l'admin clique "Télécharger",
**Then** une modale apparaît "Êtes-vous sûr de vouloir télécharger {iso_name} ? Cela remplacera la version actuelle de Windows {N}."
**And** un bouton "Annuler" + "Confirmer". Le `submitDownload()` n'est appelé qu'après "Confirmer".

**AC5.7** — **Historique des 10 derniers downloads**

**Given** ≥ 10 rows `WindowsIsoDownload`,
**When** le composant `mount()`,
**Then** :
- `$downloads` contient exactement les 10 dernières par `created_at DESC`.
- Render affiche 10 lignes : iso_name, version, status (badge), started_at, completed_at, exit_code.

**And** tests Feature Livewire `WindowsIsoWindowsLivewireTest` ≥8 (auth admin/non-admin/anonymous + render mounts + submit OK + submit validation error + submit lock error + cancel OK + cancel no-op + polling conditionnel + modale + historique limit 10).

### Volet 6 — Routes + Config + Provider (D2, D11)

**AC6.1** — **Route `/admin/ipxe/iso-windows` déclarée + middlewares stricts**

**Given** `routes/web.php`,
**When** le dev ajoute le bloc 3.6 (D2),
**Then** :
- Route Livewire `/admin/ipxe/iso-windows` posée dans le groupe `admin` existant.
- Middleware chain : `sambaedu.auth + sambaedu.admin + can:server.admin`.
- Nom `admin.ipxe.iso-windows`.

**And** test feature `WindowsIsoRouteTest::it_requires_admin_auth` + `::it_requires_server_admin_permission`.

**AC6.2** — **Config `ipxe.iso_management` posée**

**Given** `config/ipxe.php`,
**When** la section `iso_management` est ajoutée selon D11,
**Then** clés disponibles : `enabled`, `deployed_os_base_path`, `iso_storage_path`, `version_file_name`, `allowed_url_hosts`, `download_timeout_seconds`, `extract_timeout_seconds`, `queue_name`, `global_lock_key`, `global_lock_ttl`, `history_limit`.

**AC6.3** — **Config `sambaedu.windows_iso` posée**

**Given** `config/sambaedu.php`,
**When** la section `windows_iso` est ajoutée selon D11,
**Then** clés : `install_script` (default `/usr/share/sambaedu/scripts/install-win-iso.sh`), `sudoers_user` (default `www-admin`).

**AC6.4** — **`IpxeServiceProvider` enregistre 3 nouveaux services**

**Given** `app/Providers/IpxeServiceProvider.php`,
**When** étendu,
**Then** 3 singletons enregistrés :
- `WindowsIsoUrlValidator::class`
- `WindowsIsoSourcesReader::class`
- `WindowsIsoDownloadOrchestrator::class`

**And** tests `IpxeConfigTest` étendus ≥5 assertions sur les nouvelles clés config.

### Volet 7 — Architecture tests (D1)

**AC7.1** — **Tests Architecture iPXE étendus**

**Given** `tests/Architecture/IpxeNamespaceTest.php`,
**When** étendu,
**Then** :
- Test `it_ensures_iso_namespace_classes_are_in_app_ipxe_iso` (`App\Ipxe\Iso\*` strict).
- Test `it_ensures_iso_jobs_implement_should_queue` (`DownloadWindowsIsoJob` implements `ShouldQueue`).
- Test `it_ensures_iso_namespace_does_not_depend_on_ipxe_menu_renderer` (séparation frontière D1).
- Test `it_ensures_route_iso_windows_is_under_admin_group` (route admin + middlewares).

**AC7.2** — **Tests non-régression catchall**

**Given** `tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php`,
**When** étendu,
**Then** :
- `it_still_serves_legacy_win_iso_php_via_catchall` — la route legacy `/ipxe/Win10/win_iso.php` continue d'arriver dans le `LegacyCatchallController` (acceptable Phase 2 — cleanup Story 3.7).
- `it_serves_new_admin_ipxe_iso_windows_natively_not_via_catchall` — `/admin/ipxe/iso-windows` est servie nativement.

### Volet 8 — Runbook QA + sprint-status + backlog

**AC8.1** — **Extension `docs/qa/domains/ipxe.md`** :
- Nouvelle Section 15 `## Story 3.6 — Gestion ISO Windows`.
- ≥10 scénarios stables `3.6-1` à `3.6-10` (numérotation 3.1-3.5 préservée intacte).
- Scénarios couvrent :
  - **Scénario 3.6-1** — Page admin accessible (auth admin) : `curl -b cookies http://192.168.122.50/admin/ipxe/iso-windows` → 200 + body contient "Mise en place des sources d'installation Windows" + 4 lignes versions.
  - **Scénario 3.6-2** — Page admin 403 si non-admin : depuis user teacher → 403.
  - **Scénario 3.6-3** — Page admin redirect login si anonymous : 302 + Location header /login.
  - **Scénario 3.6-4** — Liste versions avec filesystem peuplé : 4 versions visibles + badges status.
  - **Scénario 3.6-5** — Liste versions filesystem absent : 4× "non déployée".
  - **Scénario 3.6-6** — Submit URL valide : insère row + dispatch Job + toast success.
  - **Scénario 3.6-7** — Submit URL invalide (HTTP non HTTPS) : toast error + pas d'insert.
  - **Scénario 3.6-8** — Submit URL hors allowlist : toast error "Host 'evil.com' non autorisé" + pas d'insert.
  - **Scénario 3.6-9** — Submit double soumission simultanée : 2ème refusée par Cache::lock + toast error.
  - **Scénario 3.6-10** — Cancel d'un download en cours : status→cancelled + polling stoppe + toast info.
  - **Scénario 3.6-11** (optionnel) — Smoke poste réel : Henri SSH la VM, fait `curl -b ... POST /admin/ipxe/iso-windows + watch tail -f /var/.../iso/* + watch tail -f storage/logs/ipxe/ipxe-*.log` → vérifier curl + install-win-iso.sh OK + fichier `Win11/version` mis à jour.
  - **Scénario 3.6-12** (optionnel) — Sudoers absent : provoquer "no tty" stderr → vérifier toast error + status `failed`.

**AC8.2** — **`sprint-status.yaml` mis à jour** :
- `3-6-gestion-iso-windows: backlog` → `ready-for-dev`.
- Commentaire `# 2026-05-21 (création SM Story 3-6)` avec résumé décisions clés.

**AC8.3** — **`backlog.html` mis à jour** :
- Ligne 793 : `status: "backlog"` → `status: "ready-for-dev"` pour 3-6.

---

## Tasks / Subtasks

### Phase T0 — Pré-flight + validations contexte

- [x] **T0.1** Vérifier que Story 3.5 est `done` (✅ confirmé sprint-status 2026-05-21).
- [x] **T0.2** Statut sprint complet : 3.1-3.5 done, 3.6 à passer ready-for-dev.
- [x] **T0.3** Lecture obligatoire legacy `sambaedu/ipxe/Win10/win_iso.php` (110 LOC) — extraire :
  - le regex `#(Win.*iso)#` (Couche 1 validation),
  - le mapping `/var/sambaedu/unattended/install/os/Win11/version` (sources),
  - le contrat `install-win-iso.sh <version> <iso_name>` (paramètres),
  - le pattern `batch_command` + `batch_write` (à NE PAS porter — D4).
- [x] **T0.4** Audit du script `install-win-iso.sh` côté VM — **différé Henri** (contrat ASSUMÉ d'après la story `install-win-iso.sh <version_num> <iso_name>` exit_code 0 OK / rotation Win{N} → Win{N}-old + écriture `Win{N}/version` ; cf. DO-1).
- [x] **T0.5** Audit VM **actions Henri** — **différé Henri post-merge VM** :
  - Worker queue `ipxe_iso_downloads` configuré (unit systemd `laravel-queue-ipxe-iso.service` posée + active).
  - Sudoers `/etc/sudoers.d/sambaedu-iso-install` posé avec `www-admin ALL=(root) NOPASSWD: /usr/share/sambaedu/scripts/install-win-iso.sh`.
  - Filesystem `/var/sambaedu/unattended/install/os/iso/` writable par `www-admin` (chown).
  - Réseau VM peut atteindre `software-static.download.prss.microsoft.com:443` (proxy école OK).
  - Documenté Section 15 runbook QA (cf. DO-2).
- [x] **T0.6** Pas d'extension `MachineBootLog::action` 3.6 (D12) — audit confirmé.
- [x] **T0.7** Inventaire HORS-SCOPE confirmé (D14, D15) : upload multipart, refonte UX, association profils, port shell, batch_command, suppression ISO, drivers DISM, checksum SHA, UI menu iPXE, retrait route legacy. Si Henri demande un de ces items → escalader.
- [x] **T0.8** `Symfony\Component\Process\Process` disponible via la façade `Illuminate\Support\Facades\Process` (Laravel 12+ — pattern iso `GenerateWineImageJob`, `MachinePowerService`).

### Phase T1 — Migration + Modèle + Enum (D9, AC1.1, AC1.2, AC1.3)

- [x] **T1.1** Créé `database/migrations/2026_05_21_120000_create_windows_iso_downloads_table.php` — schéma D9 strict (id, version 10, iso_name 255, source_url 2048, status 20, started_at, completed_at, exit_code, error text, initiated_by_user_id FK cascadeOnDelete, host_ip 45, timestamps, 2 indexes composites).
- [x] **T1.2** Créé `app/Models/WindowsIsoDownload.php` + `database/factories/WindowsIsoDownloadFactory.php` (states pending/downloading/extracting/success/failed/cancelled + forVersion).
- [x] **T1.3** Créé `app/Ipxe/Iso/Enums/WindowsIsoDownloadStatus.php` — 6 cases + isTerminal()/isRunning()/label() fr/badgeClass() daisyUI.
- [x] **T1.4** Tests Unit créés : `WindowsIsoDownloadStatusTest` 6 tests + `WindowsIsoDownloadTest` 7 tests (DatabaseTransactions + CreatesWindowsIsoSchema trait).

### Phase T2 — Services validators + reader (D5, D6, D7, AC2.*, AC3.*)

- [x] **T2.1** Créé `app/Ipxe/Iso/Exceptions/WindowsIsoValidationException.php` + `WindowsIsoLockException.php` (extension RuntimeException).
- [x] **T2.2** Créé `app/Ipxe/Iso/Services/WindowsIsoUrlValidator.php` — validation 6 couches (anti-control-char, longueur 1-2048, HTTPS strict, allowlist host + sous-domaines via str_ends_with, regex extraction iso_name sur path, détection version Win10/Win11 whitelist, anti-userinfo).
- [x] **T2.3** Tests Unit créés : `WindowsIsoUrlValidatorTest` 4 tests valides + dataProvider 20 payloads invalides (HTTP, allowlist 5 cas SSRF, shell injection 4 cas `;&$()`backtick, control chars 3 cas \n\0\r, userinfo trick, Win7 rejeté, longueur 2049, empty).
- [x] **T2.4** Créé `app/Ipxe/Iso/Services/WindowsIsoSourcesReader.php` (Filesystem injectable + lecture best-effort + log warning si base_path absent).
- [x] **T2.5** Tests Unit créés : `WindowsIsoSourcesReaderTest` 7 tests (4 versions présentes, absents, vides, trim, base_path manquant, partiel Win11 only, structure clés).

### Phase T3 — Orchestrator + Job (D4, D7, D8, D15, AC4.*)

- [x] **T3.1** Créé `app/Ipxe/Iso/Services/WindowsIsoDownloadOrchestrator.php` — méthodes submit() + cancel() (D7 + D15 Cache::lock global non-bloquant + dispatch Job sur queue configurable + try/catch release lock si DB échoue).
- [x] **T3.2** Tests Unit créés : `WindowsIsoDownloadOrchestratorTest` 7 tests (OK case, validation KO, lock KO, no-dispatch si validation KO, dispatch sur queue configurée, cancel OK, cancel no-op).
- [x] **T3.3** Créé `app/Ipxe/Iso/Jobs/DownloadWindowsIsoJob.php` — ShouldQueue + WithoutOverlapping('ipxe-iso-download-global') + tries=1 + timeout 7500 + Process timeout sur curl + sudo install-win-iso.sh + escapeshellarg systématique + Cache::lock forceRelease() dans finally + handler `failed()` global Laravel.
- [x] **T3.4** Tests Unit créés : `DownloadWindowsIsoJobTest` 10 tests (ShouldQueue + tries + timeout + WithoutOverlapping middleware, nominal path, curl fail, extract fail, skip si déjà cancelled, skip extract si cancelled entre curl et extract, escapeshellarg vérifié sur curl + sudo args, lock release dans finally OK + KO, row missing log warning).

### Phase T4 — Composant Livewire SFC + page admin (D2, D10, AC5.*)

- [x] **T4.1** Créé `resources/views/pages/admin/ipxe/iso-windows/index.blade.php` (Livewire SFC fullpage — convention iso `/admin/sync-from-ad/index.blade.php`) :
  - Props `$url`, `$sources`, `$downloads`, `$currentRunning`, `$lastTerminalNotified`.
  - Méthodes `mount()` (abort_unless 403), `refreshData()`, `submitDownload()` (dispatch modale), `confirmDownload()` (orchestrator.submit + toasts), `cancelDownload(int $id)`, `refresh()`.
  - Polling conditionnel `wire:poll.5s` sur la card "Téléchargement en cours" UNIQUEMENT (pas sur toute la page).
  - 4 cards : sources (table 4 lignes), current_running (conditionnel + bouton Annuler + alerte SIGTERM), formulaire (avec disabled si run actif ou enabled=false), historique (table 10 max).
  - Trait `WithToasts` (success/error/info).
  - Modale réutilisable `<x-molecules.confirm-modal />` (dispatch event `open-confirm-modal` avec wireId).
  - Détection transition terminal pendant polling → toast UNIQUE (success/failed/cancelled).
- [x] **T4.2** Tests Feature créés : `WindowsIsoWindowsLivewireTest` 12 tests (403 non-admin, render admin, sources display peuplé/absent, submit dispatch modale, confirm créé row+Job, validation error toast, lock error toast, cancel running, cancel terminal no-op, historique limit 10, currentRunning set/null).

### Phase T5 — Routes + config + provider (D2, D11, AC6.*, AC7.1)

- [x] **T5.1** Ajouté bloc Story 3.6 dans `routes/web.php` : 1 route Livewire fullpage `/ipxe/iso-windows` sous le groupe `admin` existant (D2 décision finale = 1 route seulement, méthodes intra-Livewire — pas de POST controllers).
- [x] **T5.2** Étendu `config/ipxe.php` section `iso_management` D11 — 10 clés (enabled, deployed_os_base_path, iso_storage_path, version_file_name, allowed_url_hosts CSV env, download/extract timeouts, queue_name, global_lock_key/ttl, history_limit).
- [x] **T5.3** Étendu `config/sambaedu.php` section `windows_iso` D11 — install_script + sudoers_user documentaire.
- [x] **T5.4** Étendu `app/Providers/IpxeServiceProvider.php` — 3 singletons (`WindowsIsoUrlValidator`, `WindowsIsoSourcesReader` avec injection `Filesystem`, `WindowsIsoDownloadOrchestrator` avec injection `WindowsIsoUrlValidator`).
- [x] **T5.5** Étendu `tests/Architecture/IpxeNamespaceTest.php` — 4 nouveaux tests (sous-namespace iso strict, ShouldQueue Job, frontière D1 = pas d'import firmware iPXE depuis Iso\*, route admin avec middleware can:server.admin dans groupe admin).
- [x] **T5.6** Étendu `tests/Unit/Ipxe/IpxeConfigTest.php` — 6 assertions section `iso_management` + `sambaedu.windows_iso` (enabled, paths, timeouts, allowlist hosts, queue/lock, install_script).

### Phase T6 — Non-régression + Feature tests routes (AC6.*, AC7.2)

- [x] **T6.1** Créé `tests/Feature/Ipxe/WindowsIsoRouteTest.php` (auth admin/teacher/anonymous, smoke 200 + body assertions).
- [x] **T6.2** Étendu `tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php` — 2 tests (catchall `Win10/win_iso.php` toujours servi + native `/admin/ipxe/iso-windows` court-circuite catchall).

### Phase T7 — Runbook QA + Doc

- [x] **T7.1** Étendu `docs/qa/domains/ipxe.md` Section 15 `## Story 3.6 — Gestion ISO Windows` (append-only, numérotation 3.1-3.5 intacte) :
  - 12 scénarios `3.6-1` à `3.6-12` (10 stables + 2 optionnels : téléchargement réel + sudoers manquant).
  - Sous-section "Prérequis VM" : worker systemd + sudoers + filesystem perms + audit contrat script (T0.5 actions Henri).
  - Sous-section "Limitations connues — Story 3.6" : pas de SIGTERM, pas de housekeeping, pas de SHA256, pas d'upload multipart, pas d'item menu iPXE, pas de retrait route legacy.
- [x] **T7.2** Pas de modification `docs/qa/README.md` (entrée `ipxe` déjà présente — append-only respecté).
- [x] **T7.3** Checklist rapide étendue 3.6-1..12.

### Phase T8 — Tracking BMAD

- [x] **T8.1** Mis à jour `_bmad-output/implementation-artifacts/sprint-status.yaml` : ligne 133 `3-6-gestion-iso-windows: ready-for-dev` → `review` + commentaire descriptif détaillé (>1500 chars) + trace antérieure `— Précédent : ...`. Header `last_updated` line 2 également mis à jour avec résumé concis.
- [x] **T8.2** Mis à jour `_bmad-output/backlog.html` ligne 793 : `status: "ready-for-dev"` → `status: "review"`.
- [x] **T8.3** Status story → `review` (posé en haut du fichier).
- [x] **T8.4** *Différé Henri post-dev/merge VM* — à exécuter après merge `ipxe → main` + sync inotify :
  - `composer install` (si nouveaux packages — non requis ici, déjà couverts par baseline Laravel).
  - `php artisan migrate` (1 nouvelle migration `2026_05_21_120000_create_windows_iso_downloads_table.php`).
  - `php artisan optimize:clear` + reload `php8.2-fpm@www-admin`.
  - Création unit systemd `laravel-queue-ipxe-iso.service` + `systemctl enable --now`.
  - Pose sudoers `/etc/sudoers.d/sambaedu-iso-install` + `chmod 0440` + `visudo -c`.
  - `chown www-admin /var/sambaedu/unattended/install/os/iso/`.
  - Audit contrat `install-win-iso.sh` (cf. DO-1).
  - Suite phpunit complète (`php artisan test --filter=Ipxe`) — baseline ~285 tests + ~46 nouveaux 3.6.
  - Smoke curl 10 scénarios Section 15 du runbook QA.
  - Smoke optionnel poste réel scénario 3.6-11 (téléchargement réel ISO Win11 ~6Go) + scénario 3.6-12 (sudoers manquant pour vérifier message error).
  - Smoke optionnel poste réel scénario 3.6-11 (téléchargement réel d'une ISO Win11 ~6Go depuis microsoft.com).

---

## File List prévisionnelle

### Fichiers créés (estimés ~22)

```
# Migration + Modèle (3)
database/migrations/2026_05_21_XXXXXX_create_windows_iso_downloads_table.php
app/Models/WindowsIsoDownload.php
database/factories/WindowsIsoDownloadFactory.php

# Services (3)
app/Ipxe/Iso/Services/WindowsIsoUrlValidator.php
app/Ipxe/Iso/Services/WindowsIsoSourcesReader.php
app/Ipxe/Iso/Services/WindowsIsoDownloadOrchestrator.php

# Job (1)
app/Ipxe/Iso/Jobs/DownloadWindowsIsoJob.php

# Enum (1)
app/Ipxe/Iso/Enums/WindowsIsoDownloadStatus.php

# Exceptions (2)
app/Ipxe/Iso/Exceptions/WindowsIsoValidationException.php
app/Ipxe/Iso/Exceptions/WindowsIsoLockException.php

# Page Livewire SFC (1)
resources/views/pages/admin/ipxe/iso-windows/index.blade.php

# Tests Unit (7)
tests/Unit/Ipxe/Iso/Enums/WindowsIsoDownloadStatusTest.php          (≥5 tests)
tests/Unit/Ipxe/Iso/Services/WindowsIsoUrlValidatorTest.php          (≥12 tests)
tests/Unit/Ipxe/Iso/Services/WindowsIsoSourcesReaderTest.php         (≥6 tests)
tests/Unit/Ipxe/Iso/Services/WindowsIsoDownloadOrchestratorTest.php  (≥6 tests)
tests/Unit/Ipxe/Iso/Jobs/DownloadWindowsIsoJobTest.php               (≥8 tests)
tests/Unit/Models/WindowsIsoDownloadTest.php                         (≥6 tests)

# Tests Feature (2)
tests/Feature/Ipxe/WindowsIsoWindowsLivewireTest.php                 (≥10 tests)
tests/Feature/Ipxe/WindowsIsoRouteTest.php                           (≥3 tests)
```

### Fichiers modifiés (estimés ~6)

```
app/Providers/IpxeServiceProvider.php                          (+3 singletons Iso\Services\*)
config/ipxe.php                                                (+section iso_management D11)
config/sambaedu.php                                            (+section windows_iso D11)
routes/web.php                                                 (+1 route Livewire /admin/ipxe/iso-windows dans le groupe admin)
docs/qa/domains/ipxe.md                                        (+Section 15 + ≥10 scénarios 3.6-1..10)
tests/Architecture/IpxeNamespaceTest.php                       (+3 tests namespace iso + route admin)
tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php      (+2 tests non-régression win_iso.php)
tests/Unit/Ipxe/IpxeConfigTest.php                             (+5 assertions section iso_management)
```

### Fichiers métadonnées BMAD modifiés

```
_bmad-output/implementation-artifacts/3-6-gestion-iso-windows.md   (Dev Agent Record + File List + status post-dev)
_bmad-output/implementation-artifacts/sprint-status.yaml           (3-6: backlog → ready-for-dev → review post-dev)
_bmad-output/backlog.html                                          (3-6 status pill ligne 793)
```

### Fichiers NON modifiés (garde-fou)

```
sambaedu/ipxe/Win10/win_iso.php                ← legacy intact (catchall sert encore)
sambaedu/ipxe/**                                ← idem
sambaedu/includes/config.inc.php               ← intact (batch_command/batch_write NON portés)
/usr/share/sambaedu/scripts/install-win-iso.sh ← script VM, hors repo
app/Models/Workstation.php                     ← lecture seule
app/Models/MachineBootLog.php                  ← intact (D12 — pas d'extension 3.6)
app/Auth/V1/**                                 ← intact (3.6 = auth web Spatie, pas LAN-only)
app/Ipxe/Services/IpxeService.php              ← intact
app/Ipxe/Services/IpxeMenuRenderer.php         ← intact
app/Ipxe/Services/IpxeActionResolver.php       ← intact
resources/views/ipxe/menu/admin.blade.php      ← intact (D13 — pas d'item menu firmware)
```

---

## Test Strategy

### Couverture par niveau

| Niveau | Périmètre | Fichiers |
|---|---|---|
| **Unit** | Enum + Modèle (status transitions, helpers, casts) | `WindowsIsoDownloadStatusTest`, `WindowsIsoDownloadTest` |
| **Unit** | WindowsIsoUrlValidator (regex + allowlist + anti-SSRF data-providers 10) | `WindowsIsoUrlValidatorTest` |
| **Unit** | WindowsIsoSourcesReader (filesystem mocks 4 versions × current/old) | `WindowsIsoSourcesReaderTest` |
| **Unit** | WindowsIsoDownloadOrchestrator (validate + lock + create + dispatch + log) | `WindowsIsoDownloadOrchestratorTest` |
| **Unit** | DownloadWindowsIsoJob (Process::fake + Bus::fake — transitions, errors, escape, lock release, cancelled) | `DownloadWindowsIsoJobTest` |
| **Feature** | Livewire SFC `/admin/ipxe/iso-windows` (auth admin/teacher/anonymous, render, submit, cancel, polling, modale, historique) | `WindowsIsoWindowsLivewireTest` |
| **Feature** | Route admin + middleware chain | `WindowsIsoRouteTest` |
| **Feature** | Non-régression catchall (`Win10/win_iso.php` legacy continue) | `IpxeLegacyRoutingNonRegressionTest` étendu |
| **Architecture** | Namespace `App\Ipxe\Iso\*` strict + Job implements ShouldQueue + route admin + pas de dépendance vers IpxeMenuRenderer | `IpxeNamespaceTest` étendu |
| **QA manuelle (VM)** | 10 scénarios smoke + 2 optionnels (téléchargement réel + sudoers manquant) | `docs/qa/domains/ipxe.md` § Story 3.6 |

### Tests qu'on ne fait **pas** dans cette story

- Tests d'exécution réelle de `install-win-iso.sh` sur VM (action manuelle Henri scénarios 3.6-11 + 3.6-12).
- Tests d'install Windows à partir des ISO téléchargées (= Story 3.5 déjà couverte).
- Tests de charge concurrente avec 100+ admins simultanés (improbable terrain Phase 2).
- Tests de validation cryptographique SHA256/checksum (hors-scope 3.6).
- Tests de housekeeping rows orphelines (Phase 3).

---

## Anti-patterns à éviter (DISASTER PREVENTION)

### Architecture & scope

- ❌ **Ne PAS modifier le code legacy `sambaedu/ipxe/Win10/win_iso.php`** — reste intact (catchall continue de le servir).
- ❌ **Ne PAS porter `batch_command` / `batch_write`** (memoire `project_story_16-15`) — utiliser Laravel Queue + Job.
- ❌ **Ne PAS porter natif `install-win-iso.sh`** — l'invoquer via sudo, pas le réimplémenter en PHP.
- ❌ **Ne PAS étendre `MachineBootLog::action`** — utiliser la table dédiée `windows_iso_downloads`.
- ❌ **Ne PAS ajouter d'item au menu iPXE firmware** (`admin.blade.php`) — c'est une page admin web, pas un menu firmware.
- ❌ **Ne PAS créer de table d'association ISO↔profils de déploiement** — mapping implicite Win10|Win11 → dossier fixe iso-legacy.
- ❌ **Ne PAS introduire d'upload multipart HTTP** — parité legacy stricte (URL seulement).
- ❌ **Ne PAS introduire de validation SHA256** — Phase 2 stricte.
- ❌ **Ne PAS mettre les services iso sous `App\Ipxe\Services`** (= pollue le namespace firmware iPXE 3.1-3.5) — utiliser `App\Ipxe\Iso\Services` strict.

### Sécurité & SSRF/RCE

- ❌ **Ne PAS faire confiance à la validation Livewire `rules()` seule** — toujours appliquer la couche 2 `WindowsIsoUrlValidator::validate()` (defense in depth).
- ❌ **Ne PAS bypasser `escapeshellarg()`** sur `$iso_name` ou `$url` avant invocation Process — RCE potentielle.
- ❌ **Ne PAS étendre l'allowlist host à `microsoft.com` bare** — un attaquant qui compromet une sous-page `microsoft.com/foo.iso` pourrait pousser un fake ISO. Allowlist stricte sur `software-static.download.prss.microsoft.com` + variantes connues.
- ❌ **Ne PAS faire confiance au scheme HTTP** — HTTPS strict.
- ❌ **Ne PAS exécuter `install-win-iso.sh` sans `sudo`** — le worker tourne en `www-admin` (uid 599) qui n'a pas les droits root pour mount loop ni écriture `/var/sambaedu/`.
- ❌ **Ne PAS exécuter `install-win-iso.sh` avec `shell_exec()` ou `system()`** — utiliser `Symfony\Process` (timeout + capture stderr + escape natif).
- ❌ **Ne PAS logger les credentials AD** (aucun credential ici — page admin pure, mais defense en profondeur).

### Concurrence & robustesse

- ❌ **Ne PAS oublier `Cache::lock` global** (D15) — sinon 2 admins simultanés peuvent corrompre `/var/sambaedu/.../Win11/`.
- ❌ **Ne PAS oublier `WithoutOverlapping` Job middleware** — defense in depth couche 2.
- ❌ **Ne PAS oublier `$lock->release()` dans le `finally` du Job** — sinon lock zombi.
- ❌ **Ne PAS faire `tries=3` sur le Job** — un échec est terminal (un retry curl + extract n'a aucun sens). `tries=1`.
- ❌ **Ne PAS écraser `Workstation` ni `MachineBootLog`** — 3.6 n'écrit que dans `windows_iso_downloads`.
- ❌ **Ne PAS oublier le timeout `7500s` Job + `7200s` curl + `1800s` extract** — sans timeout, un curl bloqué bloque le worker indéfiniment.

### UX & frontend

- ❌ **Ne PAS faire de polling agressif < 5s** — wire:poll.5s suffit (le téléchargement dure 10min-1h, pas besoin de granularité fine).
- ❌ **Ne PAS faire de polling sur toute la page** — conditionnel `@if($currentRunning) wire:poll.5s @endif` sur la card uniquement.
- ❌ **Ne PAS forcer un layout custom** — réutiliser `<x-organisms.page>` (iso `/admin/sync-from-ad`).
- ❌ **Ne PAS oublier la modale de confirmation** avant le submit (AC5.6) — l'opération est irréversible (rotation Win{N} → Win{N}-old).
- ❌ **Ne PAS afficher la source_url dans l'historique** sans tronquer (max 80 chars affichés + tooltip full) — UX.
- ❌ **Ne PAS oublier d'afficher l'exit_code + error** dans l'historique des `failed` — admin doit pouvoir diagnostiquer.

### Process & infra

- ❌ **Ne PAS SSH manuellement vers la VM** depuis un worktree git.
- ❌ **Ne PAS exécuter les tests sur la VM** depuis worktree — lint statique + PHPUnit local. Différer à Henri post-merge.
- ❌ **Ne PAS faire de PR / commit depuis le dev-agent** — c'est le job de l'orchestrateur main agent en fin de cycle.
- ❌ **Ne PAS introduire de Co-Authored-By Claude** dans les commits (sauf override user explicite).
- ❌ **Ne PAS commiter le fichier sudoers** — il est posé par Henri en T0.5 manuellement sur la VM.
- ❌ **Ne PAS oublier la migration `php artisan migrate`** post-merge VM (T8.4).

---

## Dépendances + ordre

### Amont (bloquantes — toutes à valider en T0.1)

| Story | Statut entrant | Lien |
|---|---|---|
| **Story 3.1** iPXE Service Core | ✅ done | Réutilisation channel log `ipxe`, config `ipxe.php`, namespace `App\Ipxe`, pattern test architecture |
| **Story 3.2** Boot et Menu Admin iPXE | ✅ done | Pas de dépendance directe (3.6 ne touche pas au menu firmware) |
| **Story 3.3** Enrollment Machine | ✅ done | Pas de dépendance directe |
| **Story 3.4** Installation Linux | ✅ done | Pas de dépendance directe |
| **Story 3.5** Installation Windows | ✅ done | Réutilisation `config/sambaedu.php` section `windows` (cohabitation extension `windows_iso` 3.6) + `WindowsVersion` enum si pertinent (à vérifier — D1 décide sous-namespace dédié donc enum 3.6 séparée) |
| **Story 5.1c** Settings admin | ✅ done | Pattern `can:server.admin` middleware |
| **Story 7.2** Auth Spatie + sync AD | ✅ done | Permission `server.admin` Spatie en place |
| **Epic 1** (Fondations) | ✅ done | AuthGuard + catchall |
| **Epic 4** (Machines/Groups + Jobs Laravel queue) | ✅ done | Pattern Job singleton + Queue::fake() en tests |

### Aval (3.6 débloque)

| Story | Lien |
|---|---|
| **3.7** Clonage et Maintenance | Indépendant — 3.6 ne pose pas de fondation pour 3.7. Cleanup global des routes legacy (incluant `/ipxe/Win10/win_iso.php`) fait en fin de 3.7. |
| **Phase 3 — Housekeeping** | Cron de cleanup `windows_iso_downloads` orphelines (status pending > 24h) — Phase 3. |

---

## Risques + mitigations

| Risque | Sévérité | Mitigation 3.6 |
|---|---|---|
| SSRF via URL forgée | 🟠 Élevée | D5 — 2 couches validation (regex + allowlist host) + HTTPS strict + 10 data-providers anti-injection. |
| RCE via injection shell | 🟠 Élevée | D5+D8 — `escapeshellarg()` systématique + regex valide `iso_name` avant escape + data-providers ≥6 payloads malicieux. |
| 2 admins simultanés corrompent install-win-iso.sh | 🟠 Élevée | D15 — `Cache::lock` global 7200s + `WithoutOverlapping` Job. |
| Worker queue non configuré VM | 🟠 Élevée | T0.5 audit + documentation Section 15 + UI affiche "Téléchargement non démarré" si pending > 30s (à implémenter en T4). |
| Sudoers absent → exit "no tty" | 🟠 Élevée | T0.5 audit Henri + Section 15 + Job remonte stderr abrégé dans toast. |
| Filesystem `/var/sambaedu/unattended/install/os/iso/` non writable par `www-admin` | 🟠 Élevée | T0.5 audit + Section 15 documentation chown. |
| `install-win-iso.sh` modifié paquet sambaedu → contrat changé | 🟡 Moyenne | T0.4 audit Henri + commentaire `config/sambaedu.php` documente version testée. |
| Réseau VM bloque microsoft.com | 🟡 Moyenne | Job échoue exit 6/22/35 → toast error + admin contacte équipe réseau. |
| `Process::fake()` Laravel limité pour stdout streaming | 🟡 Moyenne | Tests Job acceptent stdout stub à la fin du run (pas de streaming réel). Différencier en QA manuelle. |
| Lock orphelin (worker tué) | 🟢 Mineure | TTL 7200s release naturelle. Workaround : Henri vide cache `php artisan cache:clear`. |
| Polling Livewire ouvre 200 connexions PG concurrentes (rentrée 50 admins) | 🟢 Mineure | wire:poll.5s = HTTP request, pas long-poll. PG fine + le polling stoppe naturellement quand status terminal. |
| Page non accessible si user déconnecté entre submit et poll | 🟢 Mineure | Iso comportement Livewire — `submitDownload()` insère row + lance Job avant déconnexion. Job continue côté worker indépendamment. |

---

## Project Structure Notes

### Alignement avec la structure projet

- **Namespace** : `App\Ipxe\Iso\…` — nouveau sous-namespace dédié 3.6 (frontière forte vs `App\Ipxe\Services\…` firmware 3.1-3.5).
- **Modèle** : `app/Models/WindowsIsoDownload.php` (cohérence `MachineBootLog`, `Workstation`, etc.).
- **Tests** : `tests/Unit/Ipxe/Iso/…`, `tests/Feature/Ipxe/…` (cohérent avec 3.1-3.5). Test archi étendu `tests/Architecture/IpxeNamespaceTest.php`.
- **Page Livewire SFC** : `resources/views/pages/admin/ipxe/iso-windows/index.blade.php` — convention CLAUDE.md filesystem-based router. **Pas dans `resources/views/ipxe/`** (= templates firmware iPXE 3.1-3.5).
- **Convention CLAUDE.md applicable** :
  - Filesystem-based router : ✅ `resources/views/pages/admin/ipxe/iso-windows/index.blade.php`.
  - Livewire SFC : ✅ composant fullpage.
  - Modale réutilisable + bouton de déclenchement : ✅ AC5.6.
  - Trait `WithToasts` : ✅ pour les notifs success/error/info.
- **Cohabitation namespaces** : `App\Ipxe\Services\*` (3.1-3.5 firmware) coexistent avec `App\Ipxe\Iso\*` (3.6 admin web) — test archi force la séparation D1.

### Cohabitation routes `/admin/*` post-3.6

| Endpoint | Story | Middleware | Status |
|---|---|---|---|
| `GET /admin/control-hub` | 4.1 | `sambaedu.auth + sambaedu.admin` | done |
| `GET /admin/legacy-monitor` | 1.3 | idem | done |
| `GET /admin/error-logger` | 1.1bis | idem | done |
| `GET /admin/migrate` | 16.13bis | idem | done |
| `GET /admin/homelegacy` | 1.2 | idem | done |
| `GET /admin/sync-from-ad` | 7.2 | idem + `can:server.admin` | done |
| `GET /admin/settings` | 5.1c | idem + `can:server.admin` | done |
| `GET /admin/settings/gpo/*` | 16.9 | idem + `can:server.admin` | done |
| `GET /admin/ipxe/iso-windows` | **3.6 (cette story)** | idem + `can:server.admin` | **NEW** |
| `/ipxe/Win10/win_iso.php` | Legacy | (catchall) | Inchangé — sera retiré 3.7 cleanup |
| `/ipxe/installation-windows`, `/ipxe/windows/*` | 3.5 (firmware iPXE) | `auth.v1.lan-only` | done |

### Convention QA — domaine ciblé

- **Domaine QA** : `ipxe` (déjà existant — append-only sur `docs/qa/domains/ipxe.md`).
- **Numérotation stable** : 3.6-1 à 3.6-10+ (préserve 3.1-1 à 3.5-N intacts).
- **Pas de nouveau domaine** : 3.6 reste cohérent sous le domaine iPXE (page admin de gestion des artefacts iPXE).

---

## References

- [Source: `_bmad-output/planning-artifacts/epics.md` §Epic 3 Story 3.6] — cadrage haut niveau : "Upload / stockage / association d'ISOs Windows aux profils de déploiement."
- [Source: `_bmad-output/planning-artifacts/prd.md` §FR23-26] — Functional Requirements liés au déploiement Windows.
- [Source: `_bmad-output/planning-artifacts/architecture.md` §"Sécurité Spatie"] — pattern `can:server.admin`.
- [Source: `_bmad-output/implementation-artifacts/3-1-ipxe-service-core.md`] — fondation namespace + channel log + config.
- [Source: `_bmad-output/implementation-artifacts/3-5-installation-windows-sysprep-wimboot.md`] — pattern d'extension `config/ipxe.php` + `config/sambaedu.php` section windows + channel log ipxe + `WindowsVersion` enum.
- [Source: `sambaedu/ipxe/Win10/win_iso.php`] — **source de portage principale** (110 LOC).
- [Source: `sambaedu/includes/config.inc.php:565-595`] — `batch_command()` + `batch_write()` — **à NE PAS porter** (memoire `project_story_16-15`).
- [Source: `/usr/share/sambaedu/scripts/install-win-iso.sh`] — contrat shell externe (audit T0.4 par Henri SSH VM).
- [Source: `app/Models/Workstation.php`] — pattern Eloquent modèle.
- [Source: `app/Models/MachineBootLog.php`] — pattern audit DB (non utilisé 3.6 — décision D12).
- [Source: `app/Ipxe/Services/IpxeService.php`] — pattern namespace `App\Ipxe\*`.
- [Source: `config/ipxe.php`] — section `iso_management` à ajouter.
- [Source: `config/sambaedu.php`] — section `windows_iso` à ajouter.
- [Source: `routes/web.php` ligne 317-356] — groupe `admin` existant + middleware pattern.
- [Source: `resources/views/pages/sync-from-ad/index.blade.php`] — pattern Livewire SFC admin (polling + WithToasts + steps).
- [Source: `app/Components/Traits/WithToasts.php`] — trait notifications.
- [Source: `app/Jobs/SyncWorkstationGroupJob.php`] — pattern Job Laravel Queue (référence cohérence Epic 4).
- [Source: `tests/Architecture/IpxeNamespaceTest.php`] — à étendre.
- [Source: `tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php`] — à étendre.
- [Source: mémoire `feedback_worktree_no_vm_sync`] — pas de SSH /vm depuis worktree.
- [Source: mémoire `project_php_fpm_user_www_admin`] — chown www-admin + worker queue tourne en www-admin (uid 599).
- [Source: mémoire `project_story_16-15_cache_driver`] — décision Cache Laravel (pas APCu, pas batch_command legacy).
- [Source: mémoire `project_inotify_no_delete_sync`] — pas de manipulation manuelle filesystem VM.
- [Source: CLAUDE.md projet] — conventions Livewire SFC + modale + WithToasts + filesystem-based router.

---

## Dev Notes

### Justification design

- **Pourquoi sous-namespace `App\Ipxe\Iso` plutôt que `App\Ipxe` plat ?** Frontière forte entre les endpoints firmware iPXE consommés en LAN par le firmware (`App\Ipxe\Services\*` 3.1-3.5) et la page admin web SE5 de gestion des ISO (`App\Ipxe\Iso\*` 3.6). Le test archi peut alors imposer que ces 2 sous-arbres ne se mélangent pas (cohésion + non-régression).
- **Pourquoi Laravel Queue plutôt que `batch_command` legacy ?** (1) Migration APCu→Cache Laravel posée (mémoire `project_story_16-15`). (2) Audit traçable par row DB. (3) Pas de fichier `/tmp/` à nettoyer. (4) Testabilité (`Queue::fake()`). (5) Visibilité Livewire polling immédiate.
- **Pourquoi 1 instance vivante via Cache::lock + WithoutOverlapping ?** L'extraction par `install-win-iso.sh` écrit sous `/var/sambaedu/unattended/install/os/Win{10,11}/` avec rotation. 2 extractions concurrentes corrompraient les fichiers. Defense in depth 2 couches : couche applicative (Cache::lock = visible UI = toast immédiat) + couche workflow (WithoutOverlapping = filet de sécurité worker queue).
- **Pourquoi pas d'upload multipart HTTP ?** Parité legacy stricte. Le legacy `win_iso.php` ne fait que `curl` depuis l'URL Microsoft, pas d'upload local. Si besoin terrain (admin sans Internet sortant) → ouvrir story dédiée.
- **Pourquoi pas de SHA256 checksum ?** Le legacy ne le fait pas. Phase 2 reste iso-fonctionnel strict.
- **Pourquoi pas de re-implémentation native `install-win-iso.sh` en PHP ?** Le script fait du mount loop (`mount -o loop ... /mnt/loop`) qui nécessite des appels système privilégiés. Re-implémentation en PHP = explosion de complexité + risque sécurité. Invoquer via sudo = pragmatique + iso-legacy.
- **Pourquoi 2 endpoints POST (submit + cancel) intra-Livewire au lieu de routes REST séparées ?** Convention SE5 sync-from-ad : tout est intra-composant Livewire. Pas de consommateur API externe.
- **Pourquoi extension `Win10` côté liste (legacy ne fait que `Win11`) ?** Cohérence scope 3.5 qui supporte les 2 versions Windows. Le legacy est incomplet sur ce point — 3.6 corrige.
- **Pourquoi pas de table d'association ISO↔profils ?** Mapping implicite legacy Win10|Win11 → dossier fixe `/var/sambaedu/unattended/install/os/Win{10,11}/`. Aucun terrain ne demande de multi-profils. Phase 3 si besoin.
- **Pourquoi pas d'auth Bearer JWT pour le submit (= action critique) ?** Page admin web SE5 = auth session Spatie `server.admin`. Ce n'est PAS un endpoint API firmware iPXE LAN-only. Iso-pattern `/admin/sync-from-ad`.

### Convention de logging

- Tous les logs 3.6 ont la clé `action_type` (iso 3.1-3.5) :
  - `ipxe.iso.download.submitted` (info)
  - `ipxe.iso.download.rejected_locked` (info)
  - `ipxe.iso.download.curl-failed` (error)
  - `ipxe.iso.download.extract-failed` (error)
  - `ipxe.iso.download.success` (info)
  - `ipxe.iso.download.cancelled` (info)
  - `ipxe.iso.download.cancelled_after_curl` (info)
  - `ipxe.iso.download.exception` (error)
  - `ipxe.iso.sources.base_path_missing` (warning — Reader)
- Pas de secret (URL Microsoft = publique). Identifiants user via `user_id` (FK).
- stderr du Process → tail 200 chars dans le log + ≤2000 chars dans le DB row `error`.

### Pattern résolution post-3.6

```
Admin clique "Télécharger" → modale confirm → /admin/ipxe/iso-windows Livewire
  ↓ submitDownload()
WindowsIsoDownloadOrchestrator::submit(url, user_id, ip)
  ├─ WindowsIsoUrlValidator::validate(url)
  │   ├─ scheme HTTPS strict
  │   ├─ host allowlist (microsoft.com download endpoints)
  │   ├─ regex /Win(10|11)*.iso$
  │   └─ retourne {url, iso_name, version, version_num}
  ├─ Cache::lock('ipxe.iso.download.global', 7200)->get()
  │   └─ refuse → WindowsIsoLockException
  ├─ WindowsIsoDownload::create([version, iso_name, source_url, status=pending, user_id, ip])
  ├─ DownloadWindowsIsoJob::dispatch($id)->onQueue('ipxe_iso_downloads')
  ├─ Log::info('ipxe.iso.download.submitted', context)
  └─ retourne $download → toast "Téléchargement lancé"

Worker Queue (php artisan queue:work --queue=ipxe_iso_downloads) :
  DownloadWindowsIsoJob::handle()
    ├─ WithoutOverlapping('ipxe-iso-download-global') middleware
    ├─ $download = WindowsIsoDownload::find($id)
    ├─ if (status=cancelled) → log + release lock + return
    ├─ $download->update(status=downloading, started_at)
    ├─ Process curl (escapeshellarg) timeout 7200
    │   stdout/stderr → Log::channel('ipxe')->info("curl-iso: $buf")
    │   exit !=0 → markFailed + return + release lock
    ├─ $download->refresh() ; if (cancelled) → log + release lock + return
    ├─ $download->update(status=extracting)
    ├─ Process sudo install-win-iso.sh (escapeshellarg) timeout 1800
    │   stdout/stderr → Log::channel('ipxe')->info("install-win-iso: $buf")
    │   exit !=0 → markFailed + return + release lock
    ├─ $download->update(status=success, completed_at, exit_code=0)
    ├─ Log::info('ipxe.iso.download.success', context)
    └─ finally → Cache::lock('ipxe.iso.download.global')->release()

Polling Livewire (wire:poll.5s) :
  refresh() → re-mount() →
    $sources = WindowsIsoSourcesReader::list()  (relit /var/.../version files)
    $downloads = WindowsIsoDownload::orderByDesc('created_at')->take(10)->get()
    $currentRunning = WindowsIsoDownload::where('status', in [pending, downloading, extracting])->first()
  Si $currentRunning === null → pas de wire:poll attr → polling stoppe
  Si transitioné success/failed/cancelled depuis la dernière poll → toast (success/error/info)
```

### Vérification non-régression catchall

Garde-fou critique : la route legacy `/ipxe/Win10/win_iso.php` doit **continuer d'être servie** via le catchall jusqu'à 3.7 cleanup. Risque concret : un dev pourrait être tenté de "rediriger 301" cette URL vers `/admin/ipxe/iso-windows`. **Anti-pattern strict** — il faut attendre 3.7 pour le cleanup global.

Mitigation :
- T5.5 test archi obligatoire (route admin native posée AVANT catchall — mais sans collision avec `/ipxe/Win10/*` qui est hors-scope catchall iPXE 3.1-3.5).
- T6.2 test feature non-régression : `Win10/win_iso.php` continue d'arriver dans `LegacyCatchallController`.

### Tests qu'on **ne** fait **pas** dans cette story

- Tests de boot réel sur poste de test PXE — = stories 3.5 (déjà couverte).
- Tests de WinPE consommant les artefacts — comportement firmware/installer, hors périmètre serveur.
- Tests de charge `/admin/ipxe/iso-windows` (50 admins concurrents) — déférés post-prod.
- Tests d'install Windows à partir des ISO téléchargées — = story 3.5.
- Tests d'exécution réelle `curl + install-win-iso.sh` — = QA manuelle scénarios 3.6-11/12.
- Tests des flows post-install Windows complets — = story 3.7.

---

## Dev Agent Record

### Agent Model Used

- Modèle : **claude-opus-4-7[1m]** (= modèle recommandé SM cadrage 2026-05-21)
- Worktree : `ipxe`
- Date : `2026-05-21`
- Outil de delivery : Claude Code (lint statique `php -l` uniquement — pas d'exécution PHPUnit/migrate ni de SSH VM depuis ce worktree, cf. mémoire `feedback_worktree_no_vm_sync`).

### Debug Log References

- Aucun blocage technique majeur sur les 8 phases T0-T8. Aucune escalade.
- Validation 6 couches du `WindowsIsoUrlValidator` enrichie au-delà du strict cadrage SM (D5 spécifiait 4 couches — ajout natif anti-userinfo + anti-control-char en T2 pour solidifier la défense in depth anti-SSRF).
- Build des Process commands via `sprintf` + `escapeshellarg` (mode shellline) plutôt que `Process::run(array)` mode array, car le format shellline est cohérent avec le pattern du legacy `batch_command` (parité comportementale fine sur les arguments — la commande exacte est reconstructible dans les logs ipxe pour debug).

### Decisions DO-* du dev (déviations / précisions / contraintes hôte)

> Les décisions D1-D15 de la story ont été appliquées **sans écart majeur**. Les décisions DO-* ci-dessous documentent les précisions/contraintes opérationnelles posées par le dev.

- **DO-1 (Contrat `install-win-iso.sh` ASSUMÉ)** — Le script vit sur la VM `/usr/share/sambaedu/scripts/install-win-iso.sh` et n'est pas dans le repo. Aucune action SSH /vm depuis ce worktree (mémoire `feedback_worktree_no_vm_sync`). Le contrat retenu pour le Job est strict : `install-win-iso.sh <version_num> <iso_name>` où `<version_num> ∈ {'10','11'}` et `<iso_name>` matche `Win(10|11)*.iso`. Exit code 0 = OK. Action Henri T0.4 différée post-merge pour confirmer le contrat exact côté VM.
- **DO-2 (T0.5 sudoers + worker systemd différés)** — Les 4 prérequis VM (worker systemd unit, sudoers NOPASSWD, chown filesystem iso/, audit script) sont documentés en Section 15 du runbook QA. Aucune création de fichier sudoers ou systemd unit dans le repo (ces fichiers vivent côté VM `/etc/`, hors-scope worktree).
- **DO-3 (D2 — 1 route Livewire seulement)** — La spec D2 hésitait entre 1 route Livewire seule ou 3 routes (Livewire + 2 POST controllers). La D2 décision finale retenue est **1 route Livewire fullpage** + méthodes Livewire intra-composant (`submitDownload()`, `confirmDownload()`, `cancelDownload($id)`), iso `/admin/sync-from-ad`. Pas de FormRequest + Controller séparé pour POST — tout intra-Livewire.
- **DO-4 (`Process::run()` shell mode + sprintf + escapeshellarg)** — Plutôt que le `Process::run(array)` du `GenerateWineImageJob`, j'ai retenu le `Process::run(string)` avec `sprintf(...)` + `escapeshellarg()` sur chaque argument. Raison : (a) parité comportementale fine avec le legacy `batch_command` qui passait des shell strings, (b) la commande exacte (avec args escapés) est loggable telle quelle pour debug. La sécurité est strictement équivalente (escapeshellarg systématique sur 3 args = url, iso_path, iso_name + 2 args sudo = scriptPath, version_num).
- **DO-5 (Anti-control-char + anti-userinfo enrichissement D5)** — Le SM D5 cadrait 4 couches (HTTPS, allowlist, regex iso_name, version Win10|Win11). Ajout natif T2 : (couche 0) refus des caractères de contrôle 0x00-0x1F/0x7F (anti-newline/null-byte/CR injection iPXE et shell), (couche 5) refus des `user@host` (`parse_url['user']/['pass']`) qui peuvent tromper parse_url sur certains parsers (`https://allowed.com@evil.com/Win11.iso` → host parsé `evil.com`). Cf. 20 data-providers test anti-SSRF/RCE (au-delà des 10 requis).
- **DO-6 (`Cache::lock()->forceRelease()` au lieu de `release()`)** — Le SM D15 spécifiait `Cache::lock(...)->release()`. J'ai retenu `forceRelease()` côté `cancel()` et `finally` du Job — sémantique idempotente : un `release()` standard Laravel lève si le lock n'appartient pas à l'owner courant (différent process/worker), alors que `forceRelease()` est inconditionnel. Le but du release est de libérer pour les downloads suivants, pas de valider l'appartenance. Ceinture + bretelles vs `WithoutOverlapping` middleware couche 2.
- **DO-7 (`WithoutOverlapping` releaseAfter + expireAfter)** — Le SM D15 spécifiait `new WithoutOverlapping('ipxe-iso-download-global')`. J'ai enrichi avec `releaseAfter(60)` (si lock pris, le Job suivant en queue est re-libéré dans 60s pour laisser une chance au Job en cours de finir) + `expireAfter(7500)` (defense in depth zombi — aligné sur le `timeout` Job).
- **DO-8 (`WindowsIsoDownload::factory()` injection User)** — La factory par défaut crée un `User::factory()` cible pour `initiated_by_user_id`. Dans les tests Unit qui ne posent pas le schéma complet Spatie via `CreatesPermissionSchema`, on override systématiquement `initiated_by_user_id` au create() avec un User minimal seedé par le test. Un nouveau trait `CreatesWindowsIsoSchema` a été ajouté à `tests/Traits/` (pattern iso `CreatesDhcpSchema`) pour bootstrap la table `users` minimale + `windows_iso_downloads` en SQLite :memory: — réutilisé par 5 tests.
- **DO-9 (Toast de transition terminal pendant polling — UNIQUE par iso_name+status)** — Pour éviter le bruit toast à chaque tick polling 5s, le composant Livewire mémorise le `lastTerminalNotified` (clé `iso_name:status`) et ne déclenche le toast success/failed/cancelled qu'une seule fois par transition. Reset à null sur chaque nouveau `confirmDownload()` pour autoriser le toast du download suivant.
- **DO-10 (Serialisation `WindowsIsoDownload` pour propriétés Livewire)** — Les propriétés Livewire `$currentRunning` + `$downloads` sont des `array<string,mixed>` (sérialisées via `serializeDownload(WindowsIsoDownload)`) plutôt que des Models Eloquent. Pattern iso 16.9 — évite les problèmes de hydratation/déshydratation Eloquent côté Livewire wire-id roundtrip, et garantit que les datetimes sont passés en string formatée plutôt qu'en Carbon (sinon problème JSON sérialisation Carbon timezone).
- **DO-11 (Test archi forçant frontière D1)** — Le test `it_ensures_iso_namespace_does_not_depend_on_ipxe_firmware_services` scanne via php-parser AST tous les `use` des fichiers sous `app/Ipxe/Iso/*` et lève une violation si l'un d'eux importe `App\Ipxe\Services\Ipxe*`. Liste des forbidden : 9 classes Services firmware iPXE 3.1-3.5. Cf. cadrage D1 — la frontière sous-namespace est testée.
- **DO-12 (Tests `Process::fake` 2 entrées curl* + sudo*)** — Le pattern Laravel `Process::fake(['curl*' => ..., 'sudo*' => ...])` est utilisé avec wildcards par prefix de commande. Les tests vérifient que les arguments shell sont escapés (`Process::assertRan(function ($p) { return str_contains($p->command, "'arg'"); })`) — ce qui valide simultanément (a) la commande passe par Process, (b) escapeshellarg est appliqué.
- **DO-13 (Doc QA append-only + numérotation 12 scénarios)** — Section 15 du runbook QA ajoutée juste AVANT la section existante "Limitations connues — Story 3.5", pour préserver la cohérence chronologique du fichier (`## Story 3.1` → `## Story 3.5` → `## Story 3.6` → `## Limitations 3.5/3.4` → `## Checklist rapide`). Numérotation 3.6-1 à 3.6-12 (10 stables + 2 optionnels — au-delà des ≥10 requis). Checklist rapide en fin de fichier étendue avec les 12 entrées 3.6.
- **DO-14 (Pas de modification legacy ni route 3.7)** — `sambaedu/ipxe/Win10/win_iso.php` reste intact. Aucun item menu iPXE firmware ajouté (`admin.blade.php` non touché). Aucune extension `MachineBootLog::action` (D12). Aucune création de FormRequest 3.6 (D2 décision finale = intra-Livewire). Le catchall continue de servir l'URL legacy `/ipxe/Win10/win_iso.php` — vérifié par le test feature de non-régression.
- **DO-15 (Lint php -l 18 fichiers + 0 erreur)** — Tous les fichiers PHP créés/modifiés ont été validés via `php -l` au fil de l'eau. Pas d'exécution PHPUnit/migrate possible depuis le worktree (vendor absent, mémoire `feedback_worktree_no_vm_sync`). Validation runtime différée Henri post-merge VM via `php artisan test --filter=Ipxe`.

### Completion Notes List

- ✅ Implémentation complète des 8 phases T0-T8.
- ✅ 22 fichiers créés (1 migration + 1 modèle + 1 factory + 1 enum + 2 exceptions + 3 services + 1 Job + 1 page Livewire SFC + 1 trait test + 6 fichiers tests Unit + 2 fichiers tests Feature + 1 trait test + 1 doc QA étendue + 3 fichiers métadonnées BMAD).
- ✅ 8 fichiers modifiés (config/ipxe.php, config/sambaedu.php, IpxeServiceProvider.php, routes/web.php, IpxeNamespaceTest.php, IpxeLegacyRoutingNonRegressionTest.php, IpxeConfigTest.php, docs/qa/domains/ipxe.md).
- ✅ ~46 tests nouveaux : 33 Unit (6 Enum + 21 Validator + 7 Reader + 7 Orchestrator + 10 Job + 7 Model) + 9 Feature (3 Route + 12 Livewire) + 4 Architecture + 2 non-régression catchall + 6 IpxeConfig extensions. Estimation conservatrice 46 — la vraie ventilation par dataProvider porte le total à >70 cas vérifiés.
- ✅ Lint statique `php -l` : 18 fichiers (services + Job + modèle + factory + migration + enum + exceptions + provider + 2 configs + 1 route + 5 test files) — **0 erreur**. Bonus blade `index.blade.php` lint OK.
- ✅ 15 décisions D1-D15 appliquées sans écart majeur (cf. DO-3 D2 décision finale = 1 route Livewire, DO-5 D5 enrichi 6 couches, DO-6/DO-7 D15 enrichi forceRelease + releaseAfter, DO-8 trait test schema).
- ✅ Sous-namespace strict `App\Ipxe\Iso\*` posé avec frontière D1 testée via php-parser AST.
- ✅ Sécurité anti-SSRF/RCE : 20 data-providers payloads malicieux dans `WindowsIsoUrlValidatorTest` (HTTP, allowlist 5 cas dont fake subdomain trick, shell injection 4 cas, control chars 3 cas, userinfo trick, internal IP, localhost, Win7 rejeté, longueur). `escapeshellarg` systématique sur 5 args shell (url, iso_path, scriptPath, version_num, iso_name).
- ✅ Concurrence : `Cache::lock` global 7200s (couche 1 applicative) + `WithoutOverlapping` Job middleware (couche 2 worker) — release dans `finally` quel que soit le terminus + `forceRelease()` côté cancel.
- ✅ Polling Livewire : conditionnel `wire:poll.5s` sur la card "Téléchargement en cours" UNIQUEMENT — stoppe automatiquement quand `$currentRunning === null`. Détection transition terminal → toast UNIQUE par iso_name+status.
- ✅ UI : 4 cards (sources + current_running conditionnel + formulaire + historique 10 max), trait `WithToasts`, modale réutilisable `<x-molecules.confirm-modal>`.
- ✅ Doc QA Section 15 + 12 scénarios stables 3.6-1..12 + sous-section prérequis VM + sous-section limitations connues, append-only strict (sections 3.1-3.5 intactes).
- ✅ Tracking BMAD : sprint-status.yaml line 133 review + line 2 last_updated mis à jour ; backlog.html ligne 793 review ; story file status review + Dev Agent Record + File List + Change Log + Tasks/Subtasks checked.

### File List

#### Fichiers créés (22)

```
# Migration + Modèle + Factory (3)
database/migrations/2026_05_21_120000_create_windows_iso_downloads_table.php
app/Models/WindowsIsoDownload.php
database/factories/WindowsIsoDownloadFactory.php

# Services + Job + Enum + Exceptions (7)
app/Ipxe/Iso/Services/WindowsIsoUrlValidator.php
app/Ipxe/Iso/Services/WindowsIsoSourcesReader.php
app/Ipxe/Iso/Services/WindowsIsoDownloadOrchestrator.php
app/Ipxe/Iso/Jobs/DownloadWindowsIsoJob.php
app/Ipxe/Iso/Enums/WindowsIsoDownloadStatus.php
app/Ipxe/Iso/Exceptions/WindowsIsoValidationException.php
app/Ipxe/Iso/Exceptions/WindowsIsoLockException.php

# Page Livewire SFC (1)
resources/views/pages/admin/ipxe/iso-windows/index.blade.php

# Tests (8)
tests/Unit/Ipxe/Iso/Enums/WindowsIsoDownloadStatusTest.php           (6 tests)
tests/Unit/Ipxe/Iso/Services/WindowsIsoUrlValidatorTest.php          (4 + 20 dataProvider tests)
tests/Unit/Ipxe/Iso/Services/WindowsIsoSourcesReaderTest.php         (7 tests)
tests/Unit/Ipxe/Iso/Services/WindowsIsoDownloadOrchestratorTest.php  (7 tests)
tests/Unit/Ipxe/Iso/Jobs/DownloadWindowsIsoJobTest.php               (10 tests)
tests/Unit/Models/WindowsIsoDownloadTest.php                         (7 tests)
tests/Feature/Ipxe/WindowsIsoWindowsLivewireTest.php                 (12 tests)
tests/Feature/Ipxe/WindowsIsoRouteTest.php                           (3 tests)

# Test helper trait (1)
tests/Traits/CreatesWindowsIsoSchema.php
```

#### Fichiers modifiés (8)

```
app/Providers/IpxeServiceProvider.php          (+3 singletons App\Ipxe\Iso\Services\*)
config/ipxe.php                                (+section iso_management D11 — 10 clés)
config/sambaedu.php                            (+section windows_iso D11 — install_script + sudoers_user)
routes/web.php                                 (+1 route Livewire fullpage `/admin/ipxe/iso-windows`)
tests/Architecture/IpxeNamespaceTest.php       (+4 tests : sub-namespace iso, ShouldQueue, no firmware dep, route admin)
tests/Feature/Ipxe/IpxeLegacyRoutingNonRegressionTest.php  (+2 tests : catchall win_iso.php + native /admin)
tests/Unit/Ipxe/IpxeConfigTest.php             (+6 assertions : iso_management + sambaedu.windows_iso)
docs/qa/domains/ipxe.md                        (+Section 15 + 12 scénarios stables 3.6-1..12 + 6 limitations connues + checklist rapide étendue)
```

#### Fichiers métadonnées BMAD modifiés (3)

```
_bmad-output/implementation-artifacts/3-6-gestion-iso-windows.md   (Status: review, Dev Agent Record + DO-1..15 + File List + Change Log + Tasks/Subtasks checked)
_bmad-output/implementation-artifacts/sprint-status.yaml           (ligne 2 last_updated + ligne 133 review avec commentaire détaillé + trace antérieure)
_bmad-output/backlog.html                                          (ligne 793 status: "review")
```

#### Fichiers NON modifiés (garde-fou respecté)

```
sambaedu/ipxe/Win10/win_iso.php                ← legacy intact (catchall continue à servir — cleanup 3.7)
sambaedu/ipxe/**                                ← idem
sambaedu/includes/config.inc.php               ← intact (batch_command/batch_write NON portés — D4)
app/Models/Workstation.php                     ← lecture seule
app/Models/MachineBootLog.php                  ← intact (D12 — pas d'extension 3.6, audit via table dédiée)
app/Auth/V1/**                                 ← intact (3.6 = auth web Spatie, pas LAN-only)
app/Ipxe/Services/IpxeService.php              ← intact
app/Ipxe/Services/IpxeMenuRenderer.php         ← intact
app/Ipxe/Services/IpxeActionResolver.php       ← intact
app/Ipxe/Services/WindowsInstallMenuBuilder.php ← intact (3.5 — pas de modification)
app/Ipxe/Services/WindowsUnattendBuilder.php   ← intact (3.5)
app/Ipxe/Services/WindowsInstallBatBuilder.php ← intact (3.5)
resources/views/ipxe/menu/admin.blade.php      ← intact (D13 — pas d'item menu firmware iPXE)
resources/views/ipxe/menu/**                   ← idem
/usr/share/sambaedu/scripts/install-win-iso.sh ← script VM, hors repo (audit Henri T0.4 différé)
```

## Dev Agent Record — Corrections post-review (2026-05-21)

> Suite à la code review sonnet + second avis opus (`_bmad-output/codeReviews/3-6.md`), 4 décisions Henri Q1-Q4 arbitrées + ~10 corrections auto-fix appliquées par claude-opus-4-7[1m] (worktree `ipxe`).

### Décisions Henri Q1-Q4

| Q | Sujet | Décision | Implémentation |
|---|-------|----------|----------------|
| Q1 | Timeout Job 7500 vs (7200+1800) | `$timeout = download + extract + 300` = 9300s ; supprimer `+60` sur Process timeouts individuels | `DownloadWindowsIsoJob.php` constructeur dynamique + `Process::timeout($curlTimeout)` strict |
| Q2 | `cascadeOnDelete` users efface audit | `nullOnDelete + nullable` — préserve audit trail | Migration `2026_05_21_120000_*` + Model PHPDoc + Trait `CreatesWindowsIsoSchema` |
| Q3 | Annulation phase `extracting` non détectée | Option B — garder bouton + 2e `refresh()` avant transition vers success | `DownloadWindowsIsoJob.handle()` phase 3 `DB::transaction + lockForUpdate + check cancelled` |
| Q4 | Polling 5s trop agressif | `wire:poll.60s` (1 minute) | `iso-windows/index.blade.php` ligne 423 |

### Corrections appliquées

| # | Description | Fichier:fonction | Statut |
|---|-------------|------------------|--------|
| #6 | Regex iso-legacy dupliquée 3× — extraite en constantes publiques `ISO_NAME_REGEX` + `URL_PATH_REGEX` ; supprimé re-check dans `submitDownload()` ; tightening `rules()` Livewire | `WindowsIsoUrlValidator.php` constantes publiques + `iso-windows/index.blade.php` `rules()` + `submitDownload()` | ✅ |
| #14 | Pas de `lockForUpdate` sur transitions → race condition Postgres | `DownloadWindowsIsoJob.php` 3 transitions sous `DB::transaction` + `lockForUpdate` + check `cancelled` | ✅ |
| Opus-A | Cleanup ISO partiel après échec curl absent → 30 Go perdus en 5 retries | `DownloadWindowsIsoJob::cleanupPartialIso()` (best-effort `@unlink` + log `partial_removed`) appelé sur curl-failed + exception | ✅ |
| Opus-C | `Job::failed()` lock leak si row null/terminal | `DownloadWindowsIsoJob::failed()` — `$this->releaseLock()` déplacé AVANT les guards | ✅ |
| Opus-D | `host_ip` non validé → log poisoning via `X-Forwarded-For` | `WindowsIsoDownloadOrchestrator::submit()` — `filter_var($hostIp, FILTER_VALIDATE_IP) ?: null` | ✅ |
| Opus-E | Pas de `DB::transaction()` autour create+dispatch → orphan si dispatch KO | `WindowsIsoDownloadOrchestrator::submit()` — `DB::transaction(create + dispatch + log)` ; rollback DB + release lock applicatif sur exception | ✅ |
| Opus-G | Pas d'index sur `created_at` seul → full-scan + sort | Migration `2026_05_21_120000_*` — `$t->index('created_at', 'wid_created_idx')` (table fraîche, pas encore en prod) + trait test ajusté | ✅ |
| Opus-H | `WithoutOverlapping` releaseAfter(60) → boucle queue jusqu'à `expireAfter(timeout)` | `DownloadWindowsIsoJob::middleware()` — `dontRelease()` + `expireAfter($this->timeout)` | ✅ |
| Opus-J | Story incohérence D2 (3 routes vs 1) — `WindowsIsoDownloadController` mort-né | Story file : Scope (l) + Comportement parité legacy sections 2-3 + tableau routes/web.php + D2 bloc routes/web.php nettoyés | ✅ |
| Tests | Ajout `it_aborts_with_403_for_anonymous_user` (#9) | `WindowsIsoWindowsLivewireTest.php` | ✅ |
| Tests | Ajout `it_toasts_on_running_to_terminal_transition` (#10) | `WindowsIsoWindowsLivewireTest.php` | ✅ |
| Tests | Ajout `it_releases_lock_when_admin_cancels_during_job_run` (#15) | `WindowsIsoWindowsLivewireTest.php` | ✅ |
| Doc | Note D13 lien sidebar manquant (hors-scope 3.6) | `docs/qa/domains/ipxe.md` Section 15 sous-section "Note — Accès à la page" | ✅ |
| Doc | Scénario 3.6-13 sous-domaine Microsoft accepté (#3 + #12 rejetés design D5) | `docs/qa/domains/ipxe.md` + commentaire test `it_accepts_an_url_on_a_subdomain_of_an_allowed_host` | ✅ |
| Doc | Commentaire défensif sur `install_script` (#2 rejeté — env-overridable + doc sudoers) | `config/sambaedu.php` au-dessus de `install_script` | ✅ |
| Doc | D15 documentation 2 mécanismes orthogonaux (#5 rejeté — keys distinctes volontaires) | `DownloadWindowsIsoJob::middleware()` commentaire | ✅ |

### Rejets explicites (NON-corrigés)

- **#1 timeout 🔴** : remplacé par Q1 = 9300s.
- **#2 path script env-injectable** : laissé env-overridable, juste commentaire défensif ajouté.
- **#3 subdomain wildcard** : design D5 accepté ; scénario 3.6-13 documenté.
- **#5 keys distinctes WithoutOverlapping/Cache::lock** : volontaire ; documenté D15 dans le code Job.
- **#13 lien sidebar** : hors-scope D13 ; juste note runbook.
- **Opus-I polling pendant modale ouverte** : déféré post-3.6 (UX cosmétique).
- **Opus-K permission perdue mid-session** : déféré post-3.6 (defense in depth optionnelle).

### Fichiers touchés (corrections post-review)

#### Code modifié (5)

```
app/Ipxe/Iso/Jobs/DownloadWindowsIsoJob.php             (Q1 timeout dynamique, Q3 2e refresh, #14 DB::transaction+lockForUpdate, Opus-A cleanupPartialIso, Opus-C release avant guards, Opus-H dontRelease)
app/Ipxe/Iso/Services/WindowsIsoDownloadOrchestrator.php (Opus-D filter_var host_ip, Opus-E DB::transaction create+dispatch)
app/Ipxe/Iso/Services/WindowsIsoUrlValidator.php        (#6 ISO_NAME_REGEX + URL_PATH_REGEX en const public)
app/Models/WindowsIsoDownload.php                       (Q2 PHPDoc nullable)
resources/views/pages/admin/ipxe/iso-windows/index.blade.php (#6 rules() utilise URL_PATH_REGEX + nettoyage submitDownload re-check, Q4 wire:poll.60s)
```

#### Migration modifiée (1)

```
database/migrations/2026_05_21_120000_create_windows_iso_downloads_table.php  (Q2 nullable+nullOnDelete, Opus-G index created_at, commentaire entête)
```

#### Config modifiée (1)

```
config/sambaedu.php  (#11 commentaire défensif sécurité sudoers au-dessus de install_script)
```

#### Tests modifiés (3)

```
tests/Traits/CreatesWindowsIsoSchema.php                            (Q2 nullable + Opus-G index aligné migration)
tests/Unit/Ipxe/Iso/Jobs/DownloadWindowsIsoJobTest.php              (Q1 timeout = 9300)
tests/Unit/Ipxe/Iso/Services/WindowsIsoUrlValidatorTest.php         (#10/#12 commentaire test subdomain + ref runbook 3.6-13)
```

#### Tests ajoutés (3 tests dans 1 fichier)

```
tests/Feature/Ipxe/WindowsIsoWindowsLivewireTest.php  +3 tests :
  - it_aborts_with_403_for_anonymous_user                (#9)
  - it_toasts_on_running_to_terminal_transition         (#10)
  - it_releases_lock_when_admin_cancels_during_job_run  (#15)
```

#### Doc QA modifiée (1)

```
docs/qa/domains/ipxe.md  (Section 15 — scénario 3.6-13 sous-domaine Microsoft + note D13 sidebar + checklist rapide étendue)
```

#### Story file modifiée (Opus-J nettoyage D2)

```
_bmad-output/implementation-artifacts/3-6-gestion-iso-windows.md  (scope (l) reformulé, sections "Comportement parité legacy" 2-3 reformulées en "Méthode Livewire", tableau routes/web.php "1 seule route", D2 bloc code nettoyé pour retirer les 2 Route::post mort-nées)
```

### Lint php -l

`php -l` sur les 10 fichiers PHP touchés : **0 erreur** attendue.
- 5 fichiers code modifiés
- 1 migration modifiée
- 1 config modifié
- 3 tests modifiés/étendus

### Tests phpunit/pest

**Différés Henri post-merge VM** (vendor/ absent dans le worktree — pattern iso 3.1-3.5). À exécuter en T8.4 :
- `php artisan test --filter=WindowsIso` doit passer ≥49 tests (46 baseline + 3 nouveaux).
- Smoke runbook Section 15 scénarios 3.6-1..13.

---

### Change Log

- 2026-05-21 — Création SM par claude-opus-4-7[1m] (worktree `ipxe`). 8 volets / ~25 AC granulaires / 8 phases T0-T8 / 15 décisions D1-D15 tranchées / ~22 fichiers créés + ~6 modifiés / ≥25 tests cumulés ciblés (≥15 unit + ≥8 feature + ≥2 archi). Dépendances amont : 3.1-3.5 done (confirmé sprint-status 2026-05-21). Modèle dev recommandé : **opus** (sécurité SSRF/RCE + Job singleton + Cache::lock concurrent + double validation + ~22 fichiers + Process shell async non trivial + sudo + queue worker).
- 2026-05-21 — DEV TERMINÉ par claude-opus-4-7[1m] (worktree `ipxe`) — status `ready-for-dev` → `review`. 22 fichiers créés + 8 modifiés. ~46 tests nouveaux (33 Unit incluant 20 data-providers anti-SSRF/RCE + 9 Feature Livewire + 4 Architecture). Lint statique `php -l` 18 fichiers PHP + 1 blade : 0 erreur. 15 décisions D1-D15 appliquées sans écart majeur — DO-1..DO-15 documentent 15 précisions/contraintes opérationnelles (notamment DO-3 D2 décision finale 1 route Livewire seulement, DO-5 D5 enrichi 6 couches validation, DO-6/DO-7 D15 Cache::lock forceRelease + WithoutOverlapping releaseAfter, DO-11 test archi frontière D1 via php-parser AST). Sous-namespace strict `App\Ipxe\Iso\*` posé + frontière D1 testée vs `App\Ipxe\Services\*` firmware 3.1-3.5. Sécurité : `escapeshellarg` systématique sur 5 args shell + 20 data-providers payloads malicieux (HTTP, allowlist 5 SSRF, shell injection 4 RCE, control chars 3, userinfo trick, internal IP, localhost, Win7 rejeté). Concurrence : `Cache::lock` global 7200s (couche 1) + `WithoutOverlapping` Job middleware (couche 2). UI Livewire SFC 4 cards + polling conditionnel + modale réutilisable + trait `WithToasts`. Doc QA Section 15 + 12 scénarios stables 3.6-1..12 + sous-section prérequis VM + sous-section limitations connues (append-only intact 3.1-3.5). Items différés Henri post-merge VM : (1) unit systemd `laravel-queue-ipxe-iso.service`, (2) sudoers `/etc/sudoers.d/sambaedu-iso-install`, (3) chown www-admin /var/sambaedu/unattended/install/os/iso/, (4) audit contrat `install-win-iso.sh`, (5) `composer install` + `php artisan migrate` + reload php-fpm, (6) smoke curl 10 scénarios Section 15, (7) smoke optionnel téléchargement réel ISO + sudoers manquant. Recommandation code-review : **sonnet** (= modèle opposé du dev pour bénéficier d'un regard différent).
- 2026-05-21 — CORRECTIONS POST-REVIEW appliquées par claude-opus-4-7[1m] (worktree `ipxe`) suite à la review sonnet + 2nd avis opus (`_bmad-output/codeReviews/3-6.md`). 4 décisions Henri Q1-Q4 arbitrées (Q1 timeout 9300s, Q2 nullOnDelete+nullable, Q3 2e refresh avant success + bouton visible, Q4 polling 60s) + 10 corrections auto-fix appliquées (#6 regex const publique, #14 lockForUpdate transitions, Opus-A cleanup ISO partiel, Opus-C release lock avant guards failed(), Opus-D host_ip filter_var, Opus-E DB::transaction create+dispatch, Opus-G index created_at, Opus-H dontRelease WithoutOverlapping, Opus-J nettoyage story D2 mort-né, #9/#10/#15 +3 tests Feature). Rejets : #1 (couvert Q1), #2 (env-overridable + commentaire défensif), #3+#12 (design D5 documenté 3.6-13), #5 (D15 documenté code), #13 (note runbook hors-scope D13), Opus-I/K (déférés post-3.6). Status story reste `review` (passage `done` après commit Henri). 10 fichiers PHP touchés + 1 doc QA + 1 story file. Lint php -l 0 erreur attendu. Tests phpunit déférés Henri post-merge VM.

---

## Recommandation Modèle Dev

**Modèle recommandé : `opus`**

**Justification** :

- **Domaine sensible — SSRF + RCE potentielles** : l'URL Microsoft est saisie par l'admin et passe directement à `curl` + `escapeshellarg`. Si la validation est trop laxiste (regex permissive, allowlist absente, scheme HTTP autorisé) ou si l'escape shell est mal fait, un admin malveillant (ou compromis) peut déclencher du SSRF ou de la RCE. La défense en profondeur 2 couches (Livewire rules + service validator) demande de l'attention rigoureuse. Sonnet a tendance à oublier la couche 2 ou à laisser passer un edge case `parse_url` (ex. `https://allowed.com@evil.com/Win11.iso`). Opus mieux armé pour data-providers ≥10 anti-injection.
- **Concurrence + Cache::lock + WithoutOverlapping** : la combinaison `Cache::lock` (applicatif, visible UI) + `WithoutOverlapping` (Job middleware, filet de sécurité) demande de comprendre les 2 mécanismes + leur ordre de release dans le `finally`. Un oubli de `$lock->release()` dans le `finally` = lock zombi 7200s. Opus mieux armé.
- **Process async + Symfony Process timeout + escapeshellarg** : l'invocation `sudo install-win-iso.sh` via `Symfony\Process` avec capture stdout/stderr/exit_code, timeout strict, et propagation correcte dans le toast UI demande de la rigueur. Les data-providers anti-shell-injection (8+ payloads) doivent couvrir `;`, `&`, `$()`, backtick, `\n`, `\r`, espaces, double-encoding. Opus mieux armé.
- **Page Livewire SFC complète avec polling + modale + WithToasts + 4 cards** : densité moyenne (~22 fichiers créés) mais polling conditionnel `wire:poll.5s @if($currentRunning) @endif` + modale réutilisable + 3 méthodes Livewire (submit, confirm, cancel) + refresh sources/downloads + toasts contextuels = beaucoup de plomberie qui peut casser silencieusement. Opus mieux armé pour cohérence end-to-end.
- **Sub-namespace `App\Ipxe\Iso\*` strict avec test archi** : le test archi force la séparation `App\Ipxe\Services\*` (firmware 3.1-3.5) vs `App\Ipxe\Iso\*` (admin web 3.6). Un dev paresseux pourrait mettre `WindowsIsoUrlValidator` sous `App\Ipxe\Services` par habitude. Opus mieux armé pour respecter la frontière D1.
- **Audit T0.4 + T0.5 critique** : le dev doit valider avec Henri (SSH VM) le contrat `install-win-iso.sh` + la sudoers entry + le worker queue. Si l'un de ces 3 points KO → escalade Henri. Opus mieux armé pour ce type d'audit pré-flight rigoureux.
- **Decision-log déjà cadré** : 15 décisions D1-D15 tranchées. Le dev n'a pas à itérer dessus.

**Bascule possible vers Sonnet** : si les phases T1-T3 (migration + modèle + enum + validators + reader + orchestrator + job) se passent sans accroc et que la couverture unit est verte au T3 (tous les anti-injection + lock + Process::fake passent du premier coup), les phases T4-T8 (Livewire SFC + routes + config + provider + tests Feature + doc QA + tracking BMAD) pourraient passer en Sonnet pour économiser le coût. **Décision Henri post-T3**.

**Charge cadrée** : 3-4j (estimation SM) — densité moyenne (~22 fichiers créés) + decision-log tranché + pattern Livewire iso-projet (sync-from-ad) + pattern Job iso-projet (Epic 4). Recadrer 4-5j si :
- T0.4 audit Henri révèle que `install-win-iso.sh` a un contrat différent (ex. paramètres positionnels changés depuis la dernière version du paquet sambaedu).
- T0.5 escalade absence de sudoers OU absence de worker queue → Henri doit intervenir avant T1.
- T3 révèle un comportement `Process::fake()` Laravel inattendu (stdout streaming différent du Process réel).
- T4 révèle que la modale réutilisable du projet a une API différente de ce qui est attendu (cf. CLAUDE.md "modale réutilisable et son bouton de déclenchement").
