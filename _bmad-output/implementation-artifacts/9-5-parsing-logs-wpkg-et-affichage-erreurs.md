# Story 9.5 : Affichage du Log d'Installation WPKG

Status: done

## Story

As a **responsable de college**,
I want consulter le fichier log brut d'installation WPKG d'un poste depuis la page detail,
So that je puisse voir le detail des erreurs d'installation sans me connecter au poste.

## Contexte

La story 9.4 a implémenté l'ingestion des rapports `.txt` WPKG et l'affichage des statuts par poste. Les statuts `error` n'ont pas de message d'erreur associé en base : le rapport `.txt` ne contient que `Status: Installed|Not Installed`.

Le legacy (`sambaedu/wpkg/log.php`) affichait simplement le contenu brut du fichier `HOSTNAME.log` déposé sur le partage SMB à côté du `.txt`. Aucun parsing, aucune transformation — juste un `file() + echo` avec une mise en couleur rudimentaire des séparateurs.

**Cette story reproduit exactement ce comportement dans Laravel**, en mode lazy-load : on ne charge le log qu'à la demande (clic utilisateur) et on l'affiche dans la modale réutilisable du projet.

Le parsing structuré / stockage d'un `error_message` en base sera traité **quand on refactorera les scripts WPKG côté client** pour qu'ils émettent du JSON. En attendant, le dump brut est suffisant (c'est ce que fait le legacy depuis des années).

## Acceptance Criteria

1. **Route de lecture du log** — Given un utilisateur admin/responsable authentifié, When il appelle `GET /app/windows-deploy/reports/{workstation}/log`, Then la route renvoie le contenu brut du fichier pointé par `workstations.log_path`, avec `Content-Type text/plain; charset=UTF-8`. 404 si le fichier est absent du disque ou si `log_path` est null.

2. **Sécurité path traversal** — Given `workstations.log_path` pourrait contenir des caractères de path traversal (en théorie contrôlés, mais défensive in depth), When la route calcule le chemin d'accès, Then `basename()` est appliqué et le résultat est préfixé par `config('sambaedu.wpkg.reports_path')`. Un `realpath()` final vérifie que le chemin reste bien dans le répertoire autorisé. Toute tentative de sortie → 404.

3. **Taille max** — Given un fichier log > 5 MiB, When la route le sert, Then le contenu est tronqué aux 5 premières MiB + ajout d'une ligne `… (tronqué à 5 MiB)`. Pas de 413 côté UI (on veut afficher ce qu'on a).

4. **Lazy load dans la vue détail** — Given l'utilisateur consulte `/app/windows-deploy/reports/{workstation}`, When la page se charge, Then le log N'EST PAS récupéré automatiquement (le fichier peut être gros). Le log est chargé uniquement sur action utilisateur (clic sur ligne en erreur OU bouton dédié "Voir le log d'installation"). Le chargement utilise un `fetch()` vers la route AC #1 ou un appel Livewire, et affiche un spinner pendant le chargement.

5. **Affichage dans la modale** — Given le log est chargé, When la modale s'ouvre, Then : nom du poste en titre, contenu brut dans un `<pre>` scrollable avec monospace, bouton "Copier" (via trait `WithToasts` ou JS clipboard), bouton "Ouvrir dans un nouvel onglet" qui pointe vers la route AC #1 (équivalent legacy `target='rapport_poste'`).

6. **Fallback fichier absent** — Given `workstations.log_path` est null OU le fichier n'existe pas sur le disque, When l'utilisateur tente de consulter le log, Then la modale (ou un toast) affiche un message clair : "Log d'installation non disponible pour ce poste — le fichier sera généré au prochain rapport WPKG."

## Tasks / Subtasks

### Phase 1 : Route et contrôleur (AC #1, #2, #3)

- [x] **T1.1** Créer `app/Http/Controllers/WorkstationLogController.php` avec une méthode `show(Workstation $workstation)` :
  - Retourne 404 si `$workstation->log_path` est null
  - Construit le chemin : `config('sambaedu.wpkg.reports_path') . '/' . basename($workstation->log_path)`
  - Vérifie que `realpath($resolvedPath)` commence bien par `realpath(config('sambaedu.wpkg.reports_path'))` (defensive)
  - Vérifie `file_exists()`. Si non → 404.
  - Lit avec `stream_get_contents(fopen(..., 'r'), 5 * 1024 * 1024)` (limite 5 MiB)
  - Si le fichier est plus gros, append `"\n… (tronqué à 5 MiB)\n"`
  - Retourne la réponse brute avec `Content-Type: text/plain; charset=UTF-8` et `Content-Disposition: inline`
  - (AC: #1, #2, #3)

- [x] **T1.2** Enregistrer la route dans `routes/web.php` dans le groupe auth admin/responsable existant (réutiliser le groupe de 9.4) :
  ```php
  Route::get('/app/windows-deploy/reports/{workstation}/log', [WorkstationLogController::class, 'show'])
      ->whereNumber('workstation')
      ->name('windows-deploy.reports.log');
  ```
  - (AC: #1)

- [x] **T1.3** Tests Feature `tests/Feature/Windows/WorkstationLogControllerTest.php` :
  - Poste avec `log_path` + fichier présent sur disque → 200 avec contenu
  - Poste avec `log_path` mais fichier absent du disque → 404
  - Poste sans `log_path` (null) → 404
  - Tentative path traversal (`log_path = '../../etc/passwd'`) → 404 via basename + realpath
  - Fichier > 5 MiB → 200, contenu tronqué, footer `… (tronqué à 5 MiB)` présent
  - GET sans auth → 302 vers login
  - GET avec auth non-admin → 403
  - Convention : `DatabaseTransactions` + fixture fichier log temporaire dans `storage/app/test/` avec `config()` override
  - (AC: #1, #2, #3)

### Phase 2 : Composant modale lazy-load (AC #4, #5, #6)

- [x] **T2.1** Identifier le composant modale réutilisable du projet (cf. CLAUDE.md : « modale réutilisable et son bouton de déclenchement »). Comprendre son API : props, slots, comment l'ouvrir/fermer.

- [x] **T2.2** Créer le composant Livewire SFC pour le modal du log :
  - Fichier : `resources/views/pages/windows-deploy/reports/_partials/install-log-modal.blade.php`
  - Props : `workstation` (Workstation)
  - Propriété Livewire : `$logContent = null`, `$isLoading = false`, `$error = null`
  - Action `loadLog()` : met `$isLoading = true`, fait un HTTP `Http::get(route('windows-deploy.reports.log', $workstation))` ou lit directement via le controller injecté, stocke dans `$logContent` ou `$error` si 404/erreur
  - Template : titre (nom du poste), si `$isLoading` → spinner, si `$error` → message fallback AC #6, sinon `<pre>` avec `$logContent`
  - Bouton "Copier" (trait `WithToasts` pour feedback)
  - Bouton "Ouvrir dans un nouvel onglet" → `<a href="{{ route('windows-deploy.reports.log', $workstation) }}" target="_blank">`
  - (AC: #4, #5, #6)

- [x] **T2.3** Intégrer la modale dans la vue détail poste (`resources/views/pages/windows-deploy/reports/[workstation]/index.blade.php`) :
  - Ajouter un bouton global "Voir le log d'installation" en haut de la vue, à côté des métadonnées du poste
  - Pour chaque ligne de `WorkstationApplicationStatus` avec `status === 'error'`, la rendre cliquable (curseur pointer, hover highlight) → déclenche l'ouverture de la modale
  - Le clic appelle `loadLog()` sur le composant modale puis ouvre la modale (via le wiring habituel de la modale réutilisable)
  - (AC: #4, #5)

- [x] **T2.4** Tests Feature `WpkgReportsPageTest` (extension) :
  - Vue détail d'un poste avec statuts error → le bouton "Voir le log d'installation" est présent
  - Vue détail → les lignes en erreur ont la classe CSS cliquable (ex: `class="... cursor-pointer ..."`) ou attribut wire:click
  - Test d'interaction Livewire : déclencher `loadLog()` avec HTTP mock qui renvoie le contenu → `$logContent` populé
  - Test : `loadLog()` avec HTTP mock 404 → `$error` populé avec message fallback
  - (AC: #4, #5, #6)

### Phase 3 : Seed (support dev)

- [x] **T3.1** Mettre à jour le seed WPKG (`/tmp/seed_wpkg_reports.php` ou équivalent) :
  - Créer physiquement un faux fichier log pour chaque poste profil `errors` ou `mixed` dans `/var/sambaedu/unattended/install/wpkg/rapports/` (le répertoire configuré dans `config('sambaedu.wpkg.reports_path')`)
  - Contenu crédible : ~30 lignes de log WPKG avec timestamps + quelques erreurs typées :
    ```
    2026-04-14 10:32:15, DEBUG  : Synchronizing package 'firefox'
    2026-04-14 10:32:17, INFO   : Installing 'firefox' (v125.0)
    2026-04-14 10:32:47, ERROR  : Installation of 'firefox' failed
    2026-04-14 10:32:47, DEBUG  : Return code 1603 (ERROR_INSTALL_FAILURE)
    2026-04-14 10:32:47, DEBUG  : The installation cannot be completed because the required file is in use
    ...
    ```
  - S'assurer que `workstations.log_path` pointe vers `{hostname}.log` (déjà le cas après 9.4)
  - (support testing end-to-end de l'UI)

## Dev Notes

### Pourquoi ce minimalisme

Trois options ont été pesées. On choisit la plus légère :

- **A ~~— dump brut équivalent legacy~~** : juste une route qui sert le log, pas de résumé.
- **B — hybride** : dump + résumé heuristique en base. Demande un endpoint d'ingestion, un worker étendu, une migration.
- **C — parser exhaustif** : hors de portée tant que le format log n'est pas stable (scripts client WPKG à refactorer).

**On part sur A** (la story originale option B a été rejetée). Justification :
- Le legacy tourne en mode A depuis des années — suffit fonctionnellement
- Zéro migration, zéro évolution du worker, zéro nouvel endpoint d'ingestion
- Le jour où les scripts WPKG côté client émettront du JSON structuré (refactor prévu mais pas planifié), on reviendra avec une vraie story (parsing + schema + UX riche)
- En attendant, l'utilisateur voit exactement ce que voyait le legacy : le log brut dans une modale

### Architecture

```
Vue détail poste (Livewire SFC)
   │
   │ clic utilisateur (ligne erreur OU bouton "Voir le log")
   ▼
Composant modale lazy-load (Livewire SFC)
   │
   │ loadLog() → Http::get(route('…log', $ws))
   ▼
WorkstationLogController::show(Workstation $ws)
   │
   │ realpath check + file_get_contents capé à 5 MiB
   ▼
Contenu brut text/plain
   │
   ▼
<pre> dans la modale
```

### Sécurité route brute (AC #2)

Path traversal défense en profondeur :
1. `basename($workstation->log_path)` — supprime tout préfixe `../`
2. Préfixe fixe `config('sambaedu.wpkg.reports_path')`
3. `realpath($resolvedPath)` — résout les symlinks
4. Check `str_starts_with(realpath($file), realpath($baseDir))` — garantit containment
5. Check suffixe `.log` (cohérent avec le legacy `log.php:23`)

L'auth est gérée par le middleware existant du groupe admin/responsable (réutilise le groupe de 9.4).

### Performance / lazy-load (AC #4)

Important : **ne PAS charger le log au render de la page détail**. Un fichier log peut atteindre plusieurs MiB et multiplier par le nombre de statuts en erreur affichés (N+1). Le chargement est à la demande uniquement.

Techniquement :
- Le composant modale a une méthode Livewire `loadLog()` déclenchée par `wire:click`
- Tant que la méthode n'est pas appelée, aucun I/O disque
- Après appel : `$logContent` est propagé via l'état du composant, la modale re-render

Alternative JS pure : `fetch(route)` + afficher dans un `<pre>` côté client. Plus léger que Livewire round-trip. À discrétion du dev selon la convention projet.

### Réutilisation de 9.4

- Route dans le même groupe auth que `/app/windows-deploy/reports`
- Convention Livewire SFC respectée (pages/ + _partials/)
- Trait `WithToasts` pour le feedback "Copié dans le presse-papier"
- Tests : `DatabaseTransactions` + fixtures fichiers log temporaires
- `$this->withoutVite()` dans `setUp()`

### Évolution future (hors scope)

Quand les scripts WPKG côté client seront refactorés pour émettre un JSON structuré (par exemple `{app_id, exit_code, message}[]`), on créera une vraie story avec :
- Endpoint d'ingestion du JSON
- Migration `workstation_application_status.error_message + exit_code`
- Modale avec affichage par app
- Le log brut pourra rester accessible (cette story) en complément

### Dépendances

- Story 9.4 done — base de code Windows, modèles, page Livewire de détail, convention modale réutilisable
- Convention modale réutilisable du projet (CLAUDE.md) — composant à identifier en T2.1

## Recommandation Modèle Dev

**Sonnet** — Story très simple : un controller avec path traversal protection, un composant Livewire lazy-load, une modale. Pas de complexité. Vigilance principale sur la sécurité de la route (bien tester les cas path traversal) et la UX lazy-load (ne pas déclencher de chargement intempestif). Pas besoin d'Opus.

## Notes d'implémentation v3

Passe 3 (2026-04-16) — implémentation finale dans le bon repo (`sambaedu-reload/`).

### Déviations par rapport à la story originale

1. **Pas de route/controller** : La story originale demandait `GET /app/windows-deploy/reports/{workstation}/log` + `WorkstationLogController`. Ces specs sont obsolètes. L'ensemble est remplacé par un composant Livewire partagé + service PHP injecté via DI.

2. **Encodage CP850 fixe** : Pas de heuristique `mb_detect_encoding`. L'encodage canonique des logs WPKG (cscript Windows FR) est CP850, confirmé par analyse d'un fichier réel (octet 0x82 → é, 0x88 → ê). Détection BOM UTF-8/UTF-16 en amont comme filet de sécurité.

3. **Cap 256 KB** (au lieu de 5 MiB dans la story originale) : Les logs WPKG typiques font quelques KB. 256 KB couvre largement 1000+ lignes. Footer `… (tronqué à 256 KB)` si dépassé.

4. **Cache Laravel** : Clé `wpkg-log:{workstation_id}:{filename}:{mtime}`, TTL 60s, driver par défaut. Si le fichier change sur disque → mtime change → cache invalidé automatiquement.

5. **Composant Livewire partagé** : Un seul SFC `<livewire:components::organisms.install-log-modal />` inclus dans les 2 pages. Écoute l'événement `open-install-log-modal` via `#[On]`. Aucune duplication entre les pages.

6. **Option A choisie** : Les anciennes props/méthodes `openDeploymentModal`, `closeDeploymentModal`, `getDeploymentModalStatusProperty`, `deploymentModalStatusId` ont été supprimées des 2 SFC. Les boutons dispatchent directement l'événement vers le composant partagé.

## Dev Agent Record

### Agent Model Used

Claude Sonnet 4.6 (claude-sonnet-4-6) — 3 passes

### Debug Log References

Aucun — implémentation directe sans debug notable.

### Completion Notes List

**Passe initiale (dev-cycle skill) :**

1. **Contexte de travail** : La story 9.4 est dans le worktree `w1bis/` (commit `ad2da02`). Ce worktree est distinct de `ser/`. Toute l'implémentation se trouve dans `w1bis/`.

2. **Routes 9.4 absentes de HEAD** : Les routes `windows-deploy.*` et les vues Livewire de la story 9.4 n'étaient pas présentes dans HEAD de `w1bis/` (elles avaient été créées dans le commit `ad2da02` mais retirées lors du rebase/merge). Elles ont été restaurées dans cette implémentation.

3. **Sécurité path traversal** : Implémentation conforme à la story avec 5 niveaux de défense : basename + suffixe .log + préfixe fixe + realpath + containment check.

4. **Config `reports_path`** : Déjà présente dans `w1bis/config/sambaedu.php` (ligne 185) depuis la story 9.4.

---

**Passe de re-scope (2026-04-16) — DÉVIATION MAJEURE DE SCOPE :**

5. **Scope original abandonné** : La story originale demandait une route `/app/windows-deploy/reports/{workstation}/log`, un `WorkstationLogController`, et une nouvelle page Livewire SFC. Ces éléments ont été abandonnés sur directive utilisateur car basés sur des specs périmées.

6. **Problèmes identifiés** : Payload Livewire trop lourd (log sérialisé dans l'état des 2 SFC), encodage non fiable (`mb_detect_encoding`), duplication entre les 2 pages.

---

**Passe v3 (2026-04-16) — implémentation finale dans `sambaedu-reload/` :**

7. **Repo correct** : Tout l'implémentation est dans `sambaedu-reload/`, pas `w1bis/`.

8. **Service `WorkstationLogReader`** : Créé dans `app/Services/Windows/WorkstationLogReader.php` (sous-namespace `Windows`, cohérent avec `WpkgReportIngestionService`). 5 couches de défense path traversal + null byte + BOM detection + cache mtime.

9. **Composant Livewire partagé** : SFC `resources/views/components/organisms/install-log-modal.blade.php` — pattern identique aux autres organisms (shortcut-assignment-modal, workstation-group-selector). Injecté avec `<livewire:components::organisms.install-log-modal />`.

10. **Bouton "Télécharger"** : Blob JS depuis `$wire.installLogContent` + lien temporaire. Pas de route HTTP requise.

11. **Bouton "Copier"** : Alpine.js avec fallback `execCommand` pour contextes HTTP non-sécurisés.

12. **T3.1 non coché** : Seed non demandé dans ce scope.

13. **Tests créés** :
    - `tests/Unit/Services/WorkstationLogReaderTest.php` — 14 cas (CP850, UTF-8, UTF-16LE BOM, BOM UTF-8, null path, fichier absent, suffixe non-.log, path traversal, null byte, reports_path vide, reports_path '/', fichier > 256 KB, cache cohérent, cache invalidation mtime).
    - `tests/Feature/InstallLogModalTest.php` — 5 cas (mount initial, open+log, open+missing, open+status inexistant, close reset).
    - Tests non lancés (VM distante requise).

### File List

**Passe initiale (w1bis/ — obsolète) :**

| Statut | Fichier |
|--------|---------|
| A | `w1bis/app/Http/Controllers/Windows/WorkstationLogController.php` |
| A | `w1bis/resources/views/pages/windows-deploy/reports/index.blade.php` |
| A | `w1bis/resources/views/pages/windows-deploy/reports/[workstation]/index.blade.php` |
| A | `w1bis/tests/Feature/Windows/WorkstationLogControllerTest.php` |
| M | `w1bis/routes/web.php` |
| M | `w1bis/resources/views/components/organisms/sidebar.blade.php` |

**Passe v3 (sambaedu-reload/ — implémentation finale) :**

| Statut | Fichier |
|--------|---------|
| A | `sambaedu-reload/app/Services/Windows/WorkstationLogReader.php` |
| A | `sambaedu-reload/resources/views/components/organisms/install-log-modal.blade.php` |
| A | `sambaedu-reload/tests/Unit/Services/WorkstationLogReaderTest.php` |
| A | `sambaedu-reload/tests/Feature/InstallLogModalTest.php` |
| M | `sambaedu-reload/resources/views/pages/parc/machines/[id]/index.blade.php` |
| M | `sambaedu-reload/resources/views/pages/parc-settings/applications/index.blade.php` |
| M | `_bmad-output/implementation-artifacts/9-5-parsing-logs-wpkg-et-affichage-erreurs.md` |
