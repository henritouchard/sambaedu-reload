# Story 1bis.18f : Module GPO — Profils itinérants (refonte native + bridge SYSVOL)

Status: review

> **Pivot 2026-04-27 — Décision produit Henri.** L'approche initiale « shim catchall » (3 fichiers legacy `no_roam.php` / `user_profile_stats.php` / `del_roam.php` embarqués via `LegacyCatchallController`) est **abandonnée**. La présente story livre une **refonte UI native Laravel/Livewire** (option B) qui reste fidèle à la mécanique GPO Windows réelle (clé Registry `ExcludeProfileDirs` côté `User Configuration` de la GPO `redirections`, persistée sur SYSVOL via les wrappers livrés par 1bis.18g).
>
> **Justification du pivot :**
> 1. **Vraie pile GPO Windows** — contrairement à 18c/18d (préfixe URL `gpo/` trompeur, refondés en natif sans dépendance SYSVOL), `ExcludeProfileDirs` est un **paramètre Registry GPO authentique** consommé par `winlogon.exe` au login Windows. La logique de lecture/écriture du fichier `.pol` binaire SYSVOL **doit** rester côté SYSVOL — on ne peut pas la sortir vers une table Eloquent sans casser le contrat GPO.
> 2. **UI conforme `CLAUDE.md`** — le filesystem-based router (`resources/views/pages/`), la modale réutilisable `<x-molecules.modal>` et le trait `WithToasts` méritent d'être appliqués à cette page admin (cohérent avec 5.1c, 7.1, 4.7, 4.8).
> 3. **Réutilisation 18g sans réécriture parsing `.pol`** — 18g a livré (done) les wrappers SYSVOL `read_gpo_sysvol`, `update_gpo_sysvol`, `increment_gpo_sysvol` + `search_ad(type='gpo')` + bridge Kerberos. Un service Laravel `RoamingProfileService` les invoque via `function_exists` après chargement du bootstrap legacy (pattern `App\Services\GpoSyncService` éprouvé). 3-5 jours de réécriture du parser Registry binaire évités.

---

## Story

En tant que **responsable de collège (administrateur SE_ADMIN)**,
je veux gérer la liste des dossiers exclus du profil itinérant (`ExcludeProfileDirs`) ainsi que les statistiques de taille des profils via une page admin native moderne, et déclencher la génération d'un script bash de purge des profils trop volumineux,
afin d'administrer la GPO `redirections` côté Windows sans dépendre de l'UI legacy embarquée, tout en bénéficiant d'une expérience cohérente avec le reste de l'application Laravel (toasts, modale, scoping permission, audit cohérent CLAUDE.md).

---

## Contexte & Motivation

### Pourquoi `ExcludeProfileDirs` reste côté SYSVOL

`ExcludeProfileDirs` est une **clé Registry Windows** (`HKEY_CURRENT_USER\Software\Policies\Microsoft\Windows\System\ExcludeProfileDirs`) lue par `winlogon.exe` au login pour exclure des sous-dossiers du roaming profile (typiquement `AppData\Local\Mozilla`, `AppData\Local\Microsoft\Windows\INetCache`, etc.). La GPO `redirections` (User Configuration) la stocke dans le fichier `.pol` binaire SYSVOL (`\\<dom>\SYSVOL\<dom>\Policies\{GUID}\User\Registry.pol`). C'est un paramètre **lu par les postes Windows joints au domaine Samba AD**, pas un paramètre interne SER.

→ **Conséquence design** : on ne peut pas remplacer le SYSVOL par une table Eloquent sans casser le mécanisme GPO. La couche persistance reste SYSVOL ; seule l'UI/orchestration migre vers Laravel.

### Cartographie legacy concernée (lecture seule — référence comportementale)

| Fichier legacy | LOC | Rôle | Refonte 18f |
|---|---|---|---|
| `sambaedu/gpo/no_roam.php` | 65 | UI admin liste/édit `ExcludeProfileDirs` + tableau stats globales | **Remplacé** par onglet "Profils" dans `/admin/settings` |
| `sambaedu/gpo/user_profile_stats.php` | 32 | Drill-down stats par dossier (`?path=AppData/Local`) | **Remplacé** par modale ou route dédiée native |
| `sambaedu/gpo/del_roam.php` | 27 | Endpoint text/plain qui génère un script bash de purge | **Remplacé** par route Laravel `del-roam.sh` (génération PHP pure) |
| `sambaedu/includes/partages.inc.php:653` | — | Helper `roaming_profiles_stats()` qui parse `/tmp/du.txt` | **Réimplémenté** en natif dans `RoamingProfileService` (parsing `/tmp/du.txt` — 20 lignes) |
| `sambaedu/includes/gpo_ui.inc.php:3-100` | — | Helpers HTML `gpo_form_no_roam`, `table_roam_stats`, `table_roam_stats_user` | **Non réutilisés** (UI Livewire native) |

### Choix architecturaux (déjà arbitrés Henri 2026-04-27)

1. **Onglet "Profils" dans `/admin/settings`** *(option a)* — La page Livewire SFC `resources/views/pages/admin/settings/index.blade.php` (livrée 5.1c) inclut explicitement dans son docblock l'onglet "Profils" comme onglet futur prévu (commentaire L16 : `// futurs onglets (DHCP, CUPS, Profils, ...)`). 18f matérialise ce slot. Pattern déjà éprouvé : `#[Url(keep: true)] public string $tab` + `setTab()` (double Gate guard) + `<button role="tab" class="tab tab-active">` + `<livewire:pages::admin.settings._partials.profils-itinerants-tab />`.
2. **Service `App\Services\RoamingProfileService`** appelle les wrappers SYSVOL legacy (`read_gpo_sysvol`, `update_gpo_sysvol`, `increment_gpo_sysvol`, `change_pol_key`, `get_pol_key`, `write_gpo_json`, `search_ad`) via `function_exists()` après chargement du bootstrap legacy. Pattern décalqué sur `App\Services\GpoSyncService` (lignes 43-66, 84-107) qui appelle déjà `add_delegation_salle`/`remove_delegation_salle` après bootstrap.
3. **Endpoint `del-roam.sh`** = route Laravel dédiée qui produit le script bash en **pur PHP** (sans appeler `del_roam.php` legacy). La protection `header_authorize_script` (whitelist IP `se4fs_ip` + paramètre `se4_key`) est portée en middleware Laravel dédié. Format byte-identique au legacy (cf. AC #6 lignes attendues).
4. **Conformité CLAUDE.md stricte** : modale réutilisable `<x-molecules.modal>` (pattern `quota-override-modal.blade.php` 5.1b — `wire:model="showXxxModal"` + `closeMethod="closeXxxModal"`), trait `WithToasts` pour notifications, composants Blade `<x-molecules.xxx>` jamais `<livewire:components::molecules.xxx>`.

### Investigation code (2026-04-27)

**`app/Services/GpoSyncService.php`** (132 L) — précédent canonique d'un service Laravel qui appelle des fonctions legacy via `function_exists` + `try/catch` + `Log::error('[GpoSyncService] ...', [...])`. **À décalquer** strictement pour `RoamingProfileService`.

**`app/Services/LegacyEmbedService.php`** L43 — `require_once base_path('legacy/bootstrap.php');`. **Pattern d'accès au bootstrap** : à reprendre dans `RoamingProfileService::ensureBootstrap()` (méthode privée, idempotente — le bootstrap legacy est déjà guardé par `defined('LEGACY_BOOTSTRAP_LOADED')`, double appel sans effet).

**`app/Http/Controllers/LegacyCatchallController.php` L296** — `require_once base_path('legacy/bootstrap.php');` confirme le pattern.

**`legacy/bootstrap.php`** (L42-117) — charge bien `gpo.inc.php` (qui définit `read_gpo_sysvol`/`update_gpo_sysvol`/`increment_gpo_sysvol`/`get_pol_key`/`change_pol_key`/`write_gpo_json` + constantes `USER_GPO`/`MACHINE_GPO`), `gpo_ui.inc.php`, `delegations.inc.php`, `samba-tool.inc.php`. Les shims `gpo_shim.inc.php` (L117) fournissent les fallbacks SYSVOL guardés `function_exists` côté tests/host. **Aucun include manquant** — 18a + 18g ont tout livré.

**`legacy/ldap.inc.php:624`** — `search_ad(array $config, string $cn, string $type)` shimmé pour `type='gpo'` par 18g. Retourne `array` (1ʳᵉ entrée = la GPO matchée). Pattern d'appel : `$gpo = search_ad($config, "redirections", "gpo")[0];`.

**`routes/web.php:214`** — groupe `Route::prefix('admin')->middleware('sambaedu.admin')->name('admin.')` est l'emplacement cible pour la route `del-roam.sh`. La route page (`/admin/settings`) existe déjà L237 — aucune nouvelle route Livewire à ajouter (le tab `profils` est servi par la même page Livewire SFC qui pilote déjà `#[Url(keep: true)] public string $tab`).

**`resources/views/pages/admin/settings/index.blade.php`** (5.1c — 73 L) — page Livewire SFC à enrichir : ajout d'un second bouton tab "Profils" + `@if ($tab === 'profils-itinerants')` chargeant le partial Livewire. La double Gate (`Gate::allows('server.admin')` dans `mount()` + `setTab()`) protège déjà la page.

**`resources/views/components/molecules/modal/index.blade.php`** + **`organisms/quota-override-modal.blade.php`** (5.1b post-review) — pattern modale réutilisable à décalquer : `<x-molecules.modal wire:model="showXxxModal" closeMethod="closeXxxModal" title="...">` + `<x-molecules.modal.section title="..." icon="..." dense>` pour les sections internes. Le composant gère `<dialog class="modal">` + `@teleport('body')` + `modal-backdrop` en interne.

**`app/Components/Traits/WithToasts.php`** — trait à `use` dans le composant Livewire (`use WithToasts;` en haut de la classe anonyme). Méthodes : `toastSuccess(string $msg)`, `toastError(string $msg)`, `toastWarning(string $msg)`, `toastInfo(string $msg)`, `toastAccessDenied()`.

### Couplages, points d'attention

1. **Idempotence `ensureBootstrap()`** — `legacy/bootstrap.php` est guardé par `defined('LEGACY_BOOTSTRAP_LOADED')` (L16). Double appel sans effet. Pas de surcoût.
2. **`USER_GPO` / `MACHINE_GPO`** — constantes définies par `gpo.inc.php:58/64` après bootstrap. Vérifier `defined('USER_GPO')` avant tout appel `read_gpo_sysvol(..., USER_GPO)` (early-return + log warning si absent — environnement dégradé).
3. **`search_ad($config, 'redirections', 'gpo')`** dépend du shim 18g ET de la présence réelle de la GPO `redirections` dans l'AD Samba. **Sur host (tests)** : le shim retourne probablement `[]` ou `{count:0}` → `[0]` est `null` → fatal sur `read_gpo_sysvol(null, ...)`. **Mitigation service** : early-return + log warning si `$gpo === null` (`Log::warning('[RoamingProfileService] GPO redirections introuvable')`) + retour d'un tableau vide pour les exclusions.
4. **`/tmp/du.txt`** — fichier produit par un cron hors scope SER (legacy `sambaedu/cron/du.sh` ou équivalent). Absent en CI/host → `getProfileStatsGlobal()` retourne `[]` graceful. À documenter dans le banner UI ("Statistiques indisponibles — `/tmp/du.txt` non généré").
5. **Path traversal `del-roam.sh`** — la valeur `$value` lue depuis la GPO (issue d'un POST admin `Value[]`) est interpolée dans un chemin bash `rm -fr "/home/profiles/${username}/$value"`. Si un admin malveillant a stocké `../../../etc` dans `ExcludeProfileDirs`, le script généré peut traverser. **Mitigation native** : `RoamingProfileService::generatePurgeScript()` valide chaque `$value` via regex `^[\w\-./ ]+$` avant interpolation + escapeshellarg si dynamique. Documenter la dette dans Dev Notes (audit sécurité).
6. **Ne pas casser les liens existants** vers `gpo/no_roam.php` — le module legacy `sambaedu/gpo/gestion_gpo.php` (livré par 18b) contient probablement un lien vers `no_roam.php`. **Décision** : le `LegacyCatchallController` ajoutera une **redirection 302** spécifique pour `/gpo/no_roam.php`, `/gpo/user_profile_stats.php`, `/gpo/del_roam.php` → page native `/admin/settings?tab=profils-itinerants` (ou la route `del-roam.sh` pour le 3ᵉ). Préserve les bookmarks et les liens internes legacy. Cf. AC #9.
7. **Permission `server.admin`** — réutilisation de la même permission Spatie que 5.1c, 7.2, sync-from-ad. Pas de nouvelle perm. Double guard : route `can:server.admin` (déjà en place sur la page settings) + `Gate::allows('server.admin')` dans chaque méthode publique du composant.
8. **Pas de mock du legacy en tests** — le service appelle réellement `read_gpo_sysvol` etc. via `function_exists` + bootstrap (cohérent feedback Henri). En tests host, le bootstrap charge les shims 18g qui retournent des valeurs vides ; les tests valident le contrat (early-return graceful) sans exécuter de smbclient.

---

## Acceptance Criteria

**AC #1 — Onglet "Profils" injecté dans `/admin/settings`**

- Given la page Livewire SFC `resources/views/pages/admin/settings/index.blade.php` (livrée 5.1c) sert un seul onglet "Quotas & FS"
- When 18f est livrée
- Then un **second bouton tab** "Profils" est ajouté dans le `<div role="tablist">`, cohérent visuellement avec l'existant (icône `fa-solid fa-users-gear` ou équivalent semantique)
- And le bouton déclenche `wire:click="setTab('profils-itinerants')"` (double Gate guard `server.admin` déjà en place)
- And `@if ($tab === 'profils-itinerants')` charge `<livewire:pages::admin.settings._partials.profils-itinerants-tab />`
- And la query string `/admin/settings?tab=profils-itinerants` synchronise l'onglet (`#[Url(keep: true)]`)
- And l'onglet "Quotas & FS" reste fonctionnel — non-régression 5.1c

**AC #2 — Composant Livewire SFC `profils-itinerants-tab.blade.php`**

- Given le partial `resources/views/pages/admin/settings/_partials/profils-itinerants-tab.blade.php` est créé
- When la page se charge
- Then le composant est un Livewire SFC standard (pattern `quotas-fs-tab.blade.php` à décalquer) :
  - Bloc `<?php new class extends Component { use WithToasts; ... }; ?>`
  - `mount()` first-line guard `if (!Gate::allows('server.admin')) abort(403);`
  - `boot(RoamingProfileService $service): void` injection du service
  - Propriétés : `array $exclusions = []` (liste à plat des `ExcludeProfileDirs`), `array $statsGlobal = []` (parsing `/tmp/du.txt`), `bool $showAddModal = false`, `bool $showStatsModal = false`, `string $statsPath = ''`, `string $newExclusion = ''`
  - `mount()` charge `$this->exclusions = $service->getExclusions();` et `$this->statsGlobal = $service->getProfileStatsGlobal();`
  - Toutes les méthodes publiques (`addExclusion`, `removeExclusion`, `applyToGpo`, `openStats`, `closeStats`) commencent par `if (!Gate::allows('server.admin')) abort(403);`

**AC #3 — Affichage liste éditable `ExcludeProfileDirs`**

- Given un admin `server.admin` consulte l'onglet "Profils"
- When la page est rendue
- Then une `<x-organisms.card>` titrée "Exclusions du profil itinérant" affiche :
  - Liste à plat des exclusions courantes sous forme de chips/rows avec bouton "Supprimer" (icône poubelle) par item
  - Bouton primaire "Ajouter une exclusion" qui déclenche `wire:click="$set('showAddModal', true)"`
  - Bouton secondaire "Mettre à jour la GPO" (icône `fa-arrows-rotate`) qui déclenche `wire:click="applyToGpo"` — équivalent du `apply='Mettre à jour la GPO'` legacy (déclenche `write_gpo_json` + `increment_gpo_sysvol`)
- And si la liste est vide : message d'état vide "Aucune exclusion configurée."
- And si `$service->getExclusions()` lève une exception (GPO `redirections` introuvable) : message d'erreur `<x-molecules.alert variant="warning">` "Impossible de charger la GPO `redirections`. Vérifiez que la GPO existe sur l'AD." + log structuré (cf. AC #11)

**AC #4 — Modale d'ajout d'exclusion (réutilisable `<x-molecules.modal>`)**

- Given l'admin clique sur "Ajouter une exclusion"
- When la modale s'ouvre
- Then le markup utilise `<x-molecules.modal wire:model="showAddModal" closeMethod="closeAddModal" title="Ajouter une exclusion">` (pattern strict `quota-override-modal.blade.php` 5.1b)
- And la modale contient un input `<input type="text" wire:model.defer="newExclusion" placeholder="ex: AppData/Local/Mozilla">` + bouton "Ajouter" + bouton "Annuler"
- And à la soumission, `addExclusion()` :
  - Vérifie `Gate::allows('server.admin')` first
  - Valide `$this->newExclusion` non vide ET match regex `^[\w\-./ ]+$` (anti-path-traversal — cf. AC #11)
  - Appelle `$service->setExclusions(array_merge($this->exclusions, [$this->newExclusion]), false)` — `applyVersionBump=false` (pas d'increment SYSVOL ici, fait globalement par "Mettre à jour la GPO")
  - Reload `$this->exclusions = $service->getExclusions()`
  - Reset `$this->newExclusion = ''` + `$this->showAddModal = false`
  - Toast `$this->toastSuccess('Exclusion ajoutée.')`
- And en cas d'exception : toast générique `$this->toastError('Impossible d\'ajouter l\'exclusion.')` (pas `$e->getMessage()` — leçon 5.1b post-review #4) + log structuré

**AC #5 — Suppression d'exclusion + bouton "Mettre à jour la GPO"**

- Given une liste d'exclusions affichée
- When l'admin clique "Supprimer" sur un item d'index `$key`
- Then `removeExclusion(int $key)` :
  - Gate guard first
  - `unset($this->exclusions[$key])` puis `$this->exclusions = array_values($this->exclusions)`
  - Appel `$service->setExclusions($this->exclusions, false)`
  - Toast `$this->toastSuccess('Exclusion supprimée.')`
- And quand l'admin clique "Mettre à jour la GPO" :
  - `applyToGpo()` Gate guard first
  - Appel `$service->setExclusions($this->exclusions, applyVersionBump: true)` — déclenche `write_gpo_json` + `increment_gpo_sysvol` (équivalent legacy `apply='Mettre à jour la GPO'`)
  - Toast `$this->toastSuccess('GPO mise à jour. Les postes Windows recevront le changement à leur prochaine application de stratégie.')`

**AC #6 — Endpoint `del-roam.sh` natif (pur PHP, format legacy préservé)**

- Given l'endpoint `Route::get('/admin/gpo/del-roam.sh', [\App\Http\Controllers\Admin\RoamingProfileController::class, 'delRoamScript'])->middleware(\App\Http\Middleware\AllowSe4FsScript::class)->name('admin.gpo.del-roam-script')` est ajouté dans le groupe `prefix('admin')->middleware('sambaedu.admin')`
- When un consommateur autorisé (whitelist IP `se4fs_ip` OU paramètre `?se4_key=<valeur>` matchant `config('sambaedu.se4_key')`) appelle `GET /admin/gpo/del-roam.sh`
- Then la réponse a `Content-Type: text/plain; charset=UTF-8` (pas embarquée dans le layout)
- And le corps est généré en **pur PHP** par `RoamingProfileService::generatePurgeScript(): string` :
  - Récupère `$exclusions = $this->getExclusions()`
  - Émet `# suppression des dossiers trop gros\n` (ligne fixe 1ʳᵉ)
  - Pour chaque `$value` non-vide, émet `rm -fr "/home/profiles/\${username}/<value-sanitized>" 2>/dev/null\n` (`<value-sanitized>` = `preg_replace("/\\\\\\\\/", "/", $value)` côté Windows + validation regex `^[\w\-./ ]+$` côté path traversal — cf. AC #11)
  - Termine par `rm -fr "/home/profiles/\${username}/AppData/Roaming/Mozilla/Firefox/Profiles" 2>/dev/null\n` (ligne fixe finale)
- And le format est byte-identique au legacy `sambaedu/gpo/del_roam.php:18-26` (test d'égalité sur lignes statiques + assertion regex sur lignes dynamiques)
- And sans paramètre `se4_key` ET IP non whitelistée : la réponse est `403 Forbidden` (middleware `AllowSe4FsScript` rend la décision avant le controller)

**AC #7 — Drill-down stats par utilisateur**

- Given le tableau des stats globales affiché (sous-section "Statistiques des profils itinérants")
- When l'admin clique sur le nom d'un dossier (par exemple "AppData/Local")
- Then une modale `<x-molecules.modal wire:model="showStatsModal" title="Détail des profils — {{$statsPath}}">` s'ouvre (méthode `openStats(string $path)` Gate guarded)
- And le contenu de la modale est un tableau (rendu via Blade pur — pas d'helper legacy `table_roam_stats_user`) qui itère sur `$service->getProfileStatsForPath($path)` et affiche `username | taille (Mo) | dernière maj`
- And si `/tmp/du.txt` est absent ou la stat est vide : message "Aucune donnée disponible pour ce dossier."
- And la modale se ferme via `closeStats()` (reset `$this->statsPath = ''` + `$this->showStatsModal = false`)
- And le partial peut alternativement implémenter le drill-down via une route dédiée `?path=...` (architecture-libre — recommandation SM : modale, plus cohérent UX page settings)

**AC #8 — Service `App\Services\RoamingProfileService` — contrat + bridge legacy**

- Given le service `App\Services\RoamingProfileService` est créé
- When ses méthodes publiques sont appelées
- Then chaque méthode :
  - Charge le bootstrap legacy via `$this->ensureBootstrap()` (méthode privée idempotente — `require_once base_path('legacy/bootstrap.php')`)
  - Vérifie `function_exists('read_gpo_sysvol')` etc. avant chaque appel (defensive — pattern `GpoSyncService.php:43`)
  - Wrap chaque appel legacy dans `try/catch \Throwable` + `Log::error('[RoamingProfileService] ...', ['op' => ..., 'error' => $e->getMessage()])` + ré-throw `RuntimeException` ou retour valeur safe
- And le contrat public attendu :
  - `getExclusions(): array` — appelle `search_ad($config, 'redirections', 'gpo')[0]` puis `read_gpo_sysvol($config, $gpo, USER_GPO)` puis `get_pol_key($policy, 'ExcludeProfileDirs')`. Retourne `[]` si la GPO est introuvable (early-return + log warning).
  - `setExclusions(array $values, bool $applyVersionBump = false): void` — appelle `change_pol_key($policy, 'ExcludeProfileDirs', $values)` + `update_gpo_sysvol($config, $gpo, USER_GPO, $policy)`. Si `$applyVersionBump`, appelle aussi `write_gpo_json($gpo, USER_GPO, 'ExcludeProfileDirs', $data)` + `increment_gpo_sysvol($config, $gpo, USER_GPO)`.
  - `getProfileStatsGlobal(): array` — **réimplémenté en natif** (parsing `/tmp/du.txt` — 20 lignes inspirées de `sambaedu/includes/partages.inc.php:653-673`). **Pas d'appel à `roaming_profiles_stats()` legacy** (decoupling complet de `partages.inc.php`).
  - `getProfileStatsForPath(string $path): array` — variante qui filtre les lignes `/tmp/du.txt` matchant le sous-arbre `$path`. Format : `[ ['user' => 'jdupont', 'size_mb' => 124.5, 'mtime' => 1714123200], ... ]`.
  - `generatePurgeScript(): string` — **génère le bash en pur PHP** (sans appeler `del_roam.php` legacy). Lit `getExclusions()`, applique la regex de validation `^[\w\-./ ]+$` (skip + log warning si invalide — cf. AC #11), formate selon AC #6.
- And toutes les méthodes publiques sont **testables** sans mock du legacy (les shims 18g retournent des valeurs safe en environnement test)

**AC #9 — Rétro-compat liens legacy `gpo/no_roam.php`, etc.**

- Given le legacy `sambaedu/gpo/gestion_gpo.php` (livré par 18b) contient un lien `<a href="no_roam.php">` (à confirmer par grep — cf. T1.4)
- When un utilisateur clique ce lien (résolu vers `/gpo/no_roam.php` côté navigateur)
- Then le `LegacyCatchallController::handle` détecte les chemins `gpo/no_roam.php` et `gpo/user_profile_stats.php` ET renvoie une **redirection 302** vers `route('admin.settings', ['tab' => 'profils-itinerants'])` (option (c) de la décision produit — préserve les bookmarks navigateurs sans toucher le legacy `gestion_gpo.php`)
- And le chemin `gpo/del_roam.php` redirige vers `route('admin.gpo.del-roam-script')` en préservant la query string (notamment `se4_key`)
- And ces 3 redirections sont implémentées en `early-return` dans `LegacyCatchallController::handle()` (avant le pipeline d'exécution legacy normal). Pattern : ajout d'une `match`/`switch` ou tableau `MIGRATED_TO_NATIVE` consulté en début de méthode.

**AC #10 — Tests Feature ≥ 8 cas verts**

- Given un fichier `tests/Feature/Livewire/Admin/AdminSettingsProfilsItinerantsTabTest.php` (pattern `AdminSettingsQuotasFsTabTest.php` 5.1c) + un fichier `tests/Feature/Admin/RoamingProfileScriptEndpointTest.php` (pour `del-roam.sh`) + un fichier `tests/Unit/Services/RoamingProfileServiceTest.php`
- When `php artisan test --filter='ProfilsItinerants|RoamingProfile'` est exécuté
- Then **au minimum 8 tests** passent couvrant :
  1. `it_renders_profils_tab_with_server_admin` — `actingAs($admin)->get('/admin/settings?tab=profils-itinerants')` → 200 + assertSee("Exclusions du profil itinérant")
  2. `it_blocks_access_without_server_admin` — `actingAs($nonAdmin)->get('/admin/settings?tab=profils-itinerants')` → 403
  3. `it_blocks_add_exclusion_without_server_admin_even_on_forged_payload` — `Livewire::test(profils-itinerants-tab)->actingAs($nonAdmin)->call('addExclusion')` → expect 403
  4. `it_adds_exclusion_via_modal_and_persists_via_service` — bind `$this->newExclusion = 'AppData/Local/Mozilla'` + call `addExclusion` → vérifier que le service a été appelé (sous-classe anonyme `RoamingProfileService` qui capture les calls — pattern 5.1c stub `XfsQuotaService`) + toast success
  5. `it_rejects_path_traversal_attempt_in_add_exclusion` — bind `$this->newExclusion = '../../etc/passwd'` + call `addExclusion` → erreur de validation (toast error) + service jamais appelé
  6. `it_apply_to_gpo_calls_set_exclusions_with_version_bump` — call `applyToGpo` → vérifier que `setExclusions(..., true)` a été appelé
  7. `it_del_roam_script_endpoint_returns_text_plain_with_correct_format` — `actingAs($admin)->withSession(...)->get('/admin/gpo/del-roam.sh?se4_key=<valeur>')` → 200 + Content-Type `text/plain` + body matching `/^# suppression des dossiers trop gros\n/` + `/rm -fr "\/home\/profiles\/\$\{username\}\/AppData\/Roaming\/Mozilla\/Firefox\/Profiles" 2>\/dev\/null\n$/`
  8. `it_del_roam_script_endpoint_blocks_without_se4_key_and_wrong_ip` — `Config::set('sambaedu.se4fs_ip', '10.0.0.99')` + GET sans `se4_key` → 403
  9. `it_legacy_no_roam_php_redirects_to_native_settings` — `actingAs($admin)->get('/gpo/no_roam.php')` → 302 + `assertRedirect(route('admin.settings', ['tab' => 'profils-itinerants']))`
  10. `it_roaming_profile_service_get_exclusions_returns_empty_when_gpo_missing` — bootstrap chargé, `search_ad` shim retourne `[]` → `getExclusions()` return `[]` + log warning
  11. `it_roaming_profile_service_generate_purge_script_format_matches_legacy_byte_for_byte` — seed mocké des exclusions `['AppData/Local/Mozilla', 'AppData/Local/Microsoft/Windows/INetCache']` → `generatePurgeScript()` produit string commençant par `# suppression...` + lignes `rm -fr ...` byte-identiques au legacy `del_roam.php` (test sur lignes statiques + format des lignes dynamiques)
- And l'objectif est ≥ 8 tests verts, non-régression 5.1c (`AdminSettingsPageTest` + `AdminSettingsQuotasFsTabTest` doivent rester verts)

**AC #11 — Audit sécurité exec et path-traversal documenté + mitigé**

- Given le service appelle `update_gpo_sysvol` (smbclient/smbcacls — héritage 18g, déjà audité escapeshellarg) et génère un script bash interpolant `$value` issu de POST admin
- When la story est revue
- Then un tableau d'audit complet figure dans Dev Notes section "Audit sécurité exec" listant :
  - (a) `update_gpo_sysvol` / `increment_gpo_sysvol` → exec smbclient/smbcacls — risque FAIBLE — héritage 18g audité (escapeshellarg côté shim)
  - (b) `del-roam.sh` interpolation `$value` dans `rm -fr "/home/profiles/${username}/<value>"` → risque MOYEN path traversal — **mitigation 18f** : regex de validation `^[\w\-./ ]+$` appliquée dans `RoamingProfileService::setExclusions()` AVANT persistance ET dans `generatePurgeScript()` AVANT interpolation (skip + log warning si invalide). Test AC #10 cas #5 + cas #11.
  - (c) Aucun `exec()` direct dans la couche Laravel — toute exec est déléguée aux wrappers SYSVOL 18g.
- And la regex de validation est `^[\w\-./ ]+$` — autorise lettres/chiffres/underscore/tiret/point/slash/espace, **rejette** `..`, `;`, `$()`, `\``, `|`, `&`, `<`, `>`, `'`, `"`, `\` (anti-path-traversal + anti-injection)
- And la validation est appliquée **deux fois** (defense-in-depth) : à l'écriture (setExclusions) ET à la lecture pour génération script (generatePurgeScript) — un admin légitime ne peut jamais persister un path malformé ; et même si la GPO contient une valeur héritée non-validée, la génération de script la skip avec log

---

## Tasks / Subtasks

### Phase 1 — Audit & arbitrages produit (AC #1, #6, #9)

- [x] **T1.1** Vérifier que les stories `1bis-18a`, `1bis-18b`, `1bis-18g` sont `done` dans `sprint-status.yaml`. Vérifier que `legacy/bootstrap.php` charge bien `gpo.inc.php`, `gpo_ui.inc.php`, `gpo_shim.inc.php` (cf. L87-91, L117). (AC: #8)
- [x] **T1.2** Confirmer la disponibilité des wrappers via `function_exists` après bootstrap : `read_gpo_sysvol`, `update_gpo_sysvol`, `increment_gpo_sysvol`, `get_pol_key`, `change_pol_key`, `write_gpo_json`, `search_ad`, `get_config`. Toutes définies par 18a + 18g. (AC: #8)
- [x] **T1.3** Vérifier que la page `/admin/settings` (5.1c) accepte un second onglet sans régression (lecture `index.blade.php` + revue du commentaire L16 qui mentionne "Profils" comme onglet futur). Confirmer la place de "Profils" comme nouvel onglet. (AC: #1)
- [x] **T1.4** Grep `grep -nr "no_roam\|user_profile_stats\|del_roam" sambaedu/` pour identifier tous les liens legacy entrants vers les 3 anciennes pages. Documenter dans Dev Notes section "Migration / Redirections" la liste des callers à préserver. Décider de la **redirection 302 côté `LegacyCatchallController`** (option (c) — recommandation produit Henri). (AC: #9)
- [x] **T1.5** Produire le tableau d'audit sécurité exec + path-traversal (Dev Notes section dédiée). Identifier précisément la regex anti-path-traversal `^[\w\-./ ]+$`. Confirmer les exec hérités 18g (smbclient/smbcacls) + absence d'exec direct Laravel. (AC: #11)

### Phase 2 — Service `RoamingProfileService` (AC #8, #11)

- [x] **T2.1** Créer `app/Services/RoamingProfileService.php` (~150 LOC). Structure : namespace `App\Services`, méthode privée `ensureBootstrap()` (require_once idempotent), `getExclusions()`, `setExclusions(array, bool)`, `getProfileStatsGlobal()`, `getProfileStatsForPath(string)`, `generatePurgeScript()`. Pattern de logs : préfixe `[RoamingProfileService]` (cohérent `GpoSyncService`). (AC: #8)
- [x] **T2.2** Implémenter `getExclusions()` : `ensureBootstrap()` → vérif `function_exists('search_ad')`, `function_exists('read_gpo_sysvol')`, `function_exists('get_pol_key')` → `$config = get_config(); $gpo = search_ad($config, 'redirections', 'gpo')[0] ?? null;` → early-return `[]` + log warning si null → `read_gpo_sysvol($config, $gpo, USER_GPO)` → `get_pol_key($policy, 'ExcludeProfileDirs')` → return array. Try/catch global. (AC: #8)
- [x] **T2.3** Implémenter `setExclusions(array $values, bool $applyVersionBump = false)` : valider chaque `$value` via regex `^[\w\-./ ]+$` (skip + log warning si invalide) → `ensureBootstrap()` + checks `function_exists` → `search_ad → read_gpo_sysvol → change_pol_key → update_gpo_sysvol`. Si `$applyVersionBump` : `write_gpo_json` + `increment_gpo_sysvol`. (AC: #5, #11)
- [x] **T2.4** Implémenter `getProfileStatsGlobal()` et `getProfileStatsForPath(string $path)` en **PHP natif** (lecture `/tmp/du.txt` via `file()` + parse — décalque la logique de `partages.inc.php:653-673` mais en method privée du service, sans dépendance legacy). Retourne `[]` graceful si fichier absent. (AC: #3, #7)
- [x] **T2.5** Implémenter `generatePurgeScript(): string` en **pur PHP** : récupère `getExclusions()`, valide chaque `$value` via regex (skip invalid + log), interpole dans le format legacy byte-identique (cf. AC #6). Test byte-for-byte sur lignes statiques + format des lignes dynamiques. (AC: #6, #11)

### Phase 3 — Page admin native + endpoint script (AC #1-7)

- [x] **T3.1** Modifier `resources/views/pages/admin/settings/index.blade.php` (5.1c) : ajouter le second bouton tab "Profils" + le `@if ($tab === 'profils-itinerants')` chargeant `<livewire:pages::admin.settings._partials.profils-itinerants-tab />`. Ne PAS retirer ni modifier l'onglet "Quotas & FS". (AC: #1)
- [x] **T3.2** Créer `resources/views/pages/admin/settings/_partials/profils-itinerants-tab.blade.php` (Livewire SFC, ~250-350 lignes). Bloc PHP : classe anonyme `extends Component` avec `use WithToasts`, `boot(RoamingProfileService $service)`, `mount()` first-line Gate guard, propriétés (exclusions, statsGlobal, showAddModal, showStatsModal, statsPath, newExclusion). Méthodes : `addExclusion`, `removeExclusion(int)`, `applyToGpo`, `openStats(string)`, `closeStats`, `closeAddModal`. Tous les setters first-line `if (!Gate::allows('server.admin')) abort(403);`. Try/catch + toasts génériques (AC #4 leçon 5.1b post-review #4). (AC: #2, #3, #4, #5)
- [x] **T3.3** Template Blade du partial : 2 cards principales (`<x-organisms.card>`) — "Exclusions du profil itinérant" (liste éditable + bouton Ajouter + bouton Mettre à jour) ET "Statistiques globales" (tableau avec lignes cliquables → openStats). 2 modales `<x-molecules.modal>` (ajout exclusion + drill-down stats). Conformité CLAUDE.md : `<x-molecules.xxx>` jamais `<livewire:components::molecules.xxx>`. (AC: #3, #4, #7)
- [x] **T3.4** Créer `app/Http/Controllers/Admin/RoamingProfileController.php` : méthode unique `delRoamScript(RoamingProfileService $service)`. Lit le script via `$service->generatePurgeScript()`, retourne `response($script, 200)->header('Content-Type', 'text/plain; charset=UTF-8')`. (AC: #6)
- [x] **T3.5** Créer `app/Http/Middleware/AllowSe4FsScript.php` : porte la logique `header_authorize_script` legacy en middleware Laravel. Whitelist `request()->ip()` vs `config('sambaedu.se4fs_ip')` OU paramètre `?se4_key=...` matchant `config('sambaedu.se4_key')`. Si aucun match : `abort(403)`. Log `[AllowSe4FsScript]` (info pour passes, warning pour blocks). (AC: #6)
- [x] **T3.6** Ajouter dans `routes/web.php` (groupe `admin` L214, après la route `settings` L237) : `Route::get('/gpo/del-roam.sh', [\App\Http\Controllers\Admin\RoamingProfileController::class, 'delRoamScript'])->middleware(\App\Http\Middleware\AllowSe4FsScript::class)->name('gpo.del-roam-script');`. (AC: #6)
- [x] **T3.7** Ajouter dans `LegacyCatchallController::handle()` (early-return) la map des chemins migrés `MIGRATED_TO_NATIVE = ['gpo/no_roam.php' => 'admin.settings', 'gpo/user_profile_stats.php' => 'admin.settings', 'gpo/del_roam.php' => 'admin.gpo.del-roam-script']` — vérifie le path ; si match, return `redirect()->route(...)` avec query (`tab=profils-itinerants` pour les 2 premiers, query string preservée pour le 3ᵉ). (AC: #9)

### Phase 4 — Tests Feature + Unit (AC #10)

- [x] **T4.1** Créer `tests/Unit/Services/RoamingProfileServiceTest.php` (≥ 4 tests) : (a) `getExclusions` early-return graceful si `search_ad` retourne `[]` (assertion log warning + retour `[]`), (b) `generatePurgeScript` byte-for-byte avec exclusions seedées (2 lignes statiques + N lignes dynamiques validées), (c) `generatePurgeScript` skip + log warning sur valeur path-traversal `../../etc`, (d) `setExclusions` rejette les valeurs invalides path-traversal avant appel `change_pol_key`. **Pas de mock legacy** : invocations réelles, les shims 18g répondent côté host. (AC: #8, #10, #11)
- [x] **T4.2** Créer `tests/Feature/Livewire/Admin/AdminSettingsProfilsItinerantsTabTest.php` (≥ 5 tests) : (a) `it_renders_profils_tab_with_server_admin`, (b) `it_blocks_access_without_server_admin`, (c) `it_blocks_add_exclusion_without_server_admin_even_on_forged_payload`, (d) `it_adds_exclusion_via_modal_and_persists_via_service` (sous-classe anonyme `RoamingProfileService` qui capture les calls — pattern 5.1c), (e) `it_rejects_path_traversal_attempt_in_add_exclusion`, (f) `it_apply_to_gpo_calls_set_exclusions_with_version_bump`. (AC: #10)
- [x] **T4.3** Créer `tests/Feature/Admin/RoamingProfileScriptEndpointTest.php` (≥ 2 tests) : (a) `it_del_roam_script_endpoint_returns_text_plain_with_correct_format` — Config seed `se4_key` + GET avec `?se4_key=...` → 200 + Content-Type + body matching regex format legacy, (b) `it_del_roam_script_endpoint_blocks_without_se4_key_and_wrong_ip` — Config seed `se4fs_ip='10.0.0.99'` + GET sans `se4_key` → 403. (AC: #6, #10)
- [x] **T4.4** Ajouter test `it_legacy_no_roam_php_redirects_to_native_settings` dans `tests/Feature/Legacy/LegacyCatchallTest.php` (ou suite existante) : `actingAs($admin)->get('/gpo/no_roam.php')` → `assertRedirect(route('admin.settings', ['tab' => 'profils-itinerants']))`. Variantes pour `/gpo/user_profile_stats.php` et `/gpo/del_roam.php?se4_key=x`. (AC: #9, #10)
- [x] **T4.5** Vérifier la non-régression 5.1c : `php artisan test --filter='AdminSettingsPageTest|AdminSettingsQuotasFsTabTest'` → tous les tests existants restent verts. (AC: non-régression)
- [x] **T4.6** Suite globale : `php artisan test 2>&1 | tail -5` → cible **+ ≥ 11 tests verts** (4 unit + 5-6 Feature partial + 2 Feature endpoint + 3 Feature redirect) sans régression.

### Phase 5 — Documentation & validation finale (AC #11)

- [x] **T5.1** Consolider Dev Notes section "Audit sécurité exec" + "Mitigation path-traversal" — tableau complet AC #11. (AC: #11)
- [x] **T5.2** Documenter dans Dev Notes section "Tests manuels VM" la commande `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50` + smoke tests : (a) `curl -k -b "<cookie>" https://<vm>/admin/settings?tab=profils-itinerants` (200 + HTML cohérent), (b) `curl -k "https://<vm>/admin/gpo/del-roam.sh?se4_key=<valeur>"` (200 + Content-Type text/plain), (c) `curl -k https://<vm>/gpo/no_roam.php` (302 → /admin/settings?tab=profils-itinerants). **Ne pas exécuter** depuis le subagent.
- [x] **T5.3** Mettre à jour `_bmad-output/implementation-artifacts/sprint-status.yaml` : `1bis-18f-module-gpo-profils-itinerants: ready-for-dev → review` (à faire par le dev en fin d'implémentation, **pas** par le créateur de la story) + `last_updated`.
- [x] **T5.4** Préparer Change Log + File List dans ce fichier story (déjà rédigés ci-dessous, à enrichir par le dev en fin d'implémentation).

---

## Dev Notes

### Contexte

Sixième et **dernière** sous-story du module GPO (Tier 3). Pivot 2026-04-27 vers **option B (refonte native + bridge SYSVOL)** après abandon de l'approche shim catchall (interdit par décision Henri suite à clarification : `ExcludeProfileDirs` est une vraie GPO Windows, contrairement à 18c/18d qui n'avaient que le préfixe URL `gpo/`).

Elle s'appuie sur :
- **1bis.18a** (done) — bootstrap + 4 includes GPO core + stubs `gpo_deps.inc.php`.
- **1bis.18g** (done) — wrappers SYSVOL `read_gpo_sysvol`/`update_gpo_sysvol`/`increment_gpo_sysvol`/`sysvol_put` + shim `search_ad(type=gpo)` + bridge Kerberos.
- **1bis.18b** (done) — module `gestion_gpo.php` legacy (qui contient probablement un lien vers `no_roam.php` — cf. T1.4 grep).
- **5.1c** (done) — page `/admin/settings` Livewire SFC à onglets, qui prévoit explicitement "Profils" comme onglet futur (cf. docblock L16 de `index.blade.php`).

### Architecture cible

```
Browser
  ↓ GET /admin/settings?tab=profils-itinerants
  ↓ middleware sambaedu.admin + can:server.admin (route 5.1c)
  ↓ pages/admin/settings/index.blade.php (Livewire SFC 5.1c)
  ↓ @if ($tab === 'profils-itinerants')
  ↓ <livewire:pages::admin.settings._partials.profils-itinerants-tab />
  ↓ pages/admin/settings/_partials/profils-itinerants-tab.blade.php (Livewire SFC 18f)
        ↓ mount() : Gate::allows('server.admin') + boot(RoamingProfileService)
        ↓ App\Services\RoamingProfileService::getExclusions()
              ↓ ensureBootstrap() → require_once base_path('legacy/bootstrap.php')
              ↓ function_exists('search_ad') + function_exists('read_gpo_sysvol')
              ↓ get_config()
              ↓ search_ad($config, 'redirections', 'gpo')[0]   [shim 18g via legacy/ldap.inc.php]
              ↓ read_gpo_sysvol($config, $gpo, USER_GPO)        [shim 18g via legacy/gpo_shim.inc.php]
              ↓ get_pol_key($policy, 'ExcludeProfileDirs')      [legacy gpo.inc.php:386]
              ↓ return array
        ↓ render template Blade
              ↓ <x-organisms.card title="Exclusions du profil itinérant">
              ↓ foreach $exclusions as $key => $value : chip + bouton Supprimer
              ↓ Bouton "Ajouter" → wire:click="$set('showAddModal', true)"
              ↓ Bouton "Mettre à jour la GPO" → wire:click="applyToGpo"
              ↓ <x-organisms.card title="Statistiques globales">
              ↓ Tableau (table_roam_stats équivalent natif) avec lignes cliquables
              ↓ <x-molecules.modal wire:model="showAddModal">
              ↓ <x-molecules.modal wire:model="showStatsModal">

Browser
  ↓ GET /admin/gpo/del-roam.sh?se4_key=...
  ↓ middleware sambaedu.admin + middleware AllowSe4FsScript
  ↓ App\Http\Controllers\Admin\RoamingProfileController::delRoamScript
  ↓ App\Services\RoamingProfileService::generatePurgeScript()
        ↓ getExclusions()  [bridge legacy via bootstrap]
        ↓ génération PHP pure (validation regex anti-path-traversal)
        ↓ format byte-identique au legacy del_roam.php:18-26
  ↓ response($script)->header('Content-Type', 'text/plain; charset=UTF-8')

Browser (lien depuis legacy gestion_gpo.php)
  ↓ GET /gpo/no_roam.php
  ↓ LegacyCatchallController::handle (early-return MIGRATED_TO_NATIVE)
  ↓ redirect()->route('admin.settings', ['tab' => 'profils-itinerants'])  → 302
```

### Carte des dépendances legacy

```
RoamingProfileService (Laravel)
  ├── ensureBootstrap()
  │     require_once base_path('legacy/bootstrap.php')   [idempotent — defined LEGACY_BOOTSTRAP_LOADED]
  │
  ├── Fonctions consommées (toutes définies après bootstrap 18a + 18g) :
  │     # AD search (legacy/ldap.inc.php + shim 18g)
  │     search_ad($config, 'redirections', 'gpo')        [case 'gpo' implémentée par 18g]
  │
  │     # GPO core (gpo.inc.php — chargé par 18a, fallback shim 18g)
  │     read_gpo_sysvol($config, $gpo, USER_GPO)         [shim 18g fallback ou gpo.inc.php:1197]
  │     update_gpo_sysvol($config, $gpo, USER_GPO, $p)   [shim 18g fallback ou gpo.inc.php:1337]
  │     increment_gpo_sysvol($config, $gpo, USER_GPO)    [shim 18g fallback ou gpo.inc.php:1399]
  │     get_pol_key($policy, 'ExcludeProfileDirs')       [gpo.inc.php:386]
  │     change_pol_key($policy, $key, $values)           [gpo.inc.php:404]
  │     write_gpo_json($gpo, USER_GPO, $key, $data)      [gpo.inc.php:425]
  │
  │     # Config bridge
  │     get_config()                                     [legacy/config.inc.php — bridge Laravel→legacy]
  │
  │     # Constantes GPO
  │     USER_GPO / MACHINE_GPO                           [gpo.inc.php:58/64 — défini après bootstrap]
  │
  └── exec() invoqués (résumé — voir Audit sécurité ci-dessous) :
        # Direct (dans RoamingProfileService) : aucun.
        # Indirect via update_gpo_sysvol / increment_gpo_sysvol :
        smbclient -k --use-kerberos=required -c "put …" //{domain}/SYSVOL  [audit 18g — escapeshellarg OK]
        smbcacls --machine-pass …                                          [audit 18g — escapeshellarg OK]
```

### Audit sécurité exec + path-traversal

| Source | Emplacement | Paramètre user | Échappement | Risque | Mitigation 18f |
|---|---|---|---|---|---|
| `update_gpo_sysvol`, `increment_gpo_sysvol` | shim `gpo_shim.inc.php` ou `gpo.inc.php` | `$gpo['cn']` (via search_ad), `USER_GPO` (constante) | escapeshellarg côté shim 18g | FAIBLE | OK — héritage 18g audité |
| `del-roam.sh` interpolation `$value` | `RoamingProfileService::generatePurgeScript` | `$value` ← `$_POST['Value']` original (légitime via UI admin) | regex `^[\w\-./ ]+$` validée à l'écriture **et** à la génération | MOYEN — path traversal | **Mitigation 18f** : double validation (set + generate) + skip + log warning si invalide |
| `no_roam.php` POST `Value[]` | `RoamingProfileService::setExclusions` | `$value` POST admin | regex `^[\w\-./ ]+$` first-line | MOYEN — path traversal | **Mitigation 18f** : refus avec toast error + log si non-conforme (test cas #5) |
| _Aucun exec direct_ dans la couche Laravel | — | — | — | — | — |

**Defense-in-depth path-traversal** :
1. **À l'écriture** (`setExclusions`) : valeurs invalides rejetées avec toast error. Un admin légitime ne peut pas persister `../../etc/passwd`.
2. **À la lecture** (`generatePurgeScript`) : valeurs invalides skippées avec log warning. Même si une GPO contient une valeur héritée non-validée (cas legacy pré-18f), le script généré ne l'interpole pas.

**Régex** : `^[\w\-./ ]+$` — autorise `[A-Za-z0-9_]`, `-`, `.`, `/`, ` `. Rejette `..`, `;`, `$()`, `` ` ``, `|`, `&`, `<`, `>`, `'`, `"`, `\`, `*`, `?`, `[`, `]`, etc.

### Choix produit arbitrés (décisions Henri 2026-04-27)

1. **D1 — Stratégie de migration** : option B (refonte native + bridge SYSVOL). Justification : (a) ExcludeProfileDirs est vraie GPO Windows, (b) UI mérite refonte CLAUDE.md, (c) wrappers SYSVOL 18g réutilisables sans réécrire parser `.pol` (3-5 jours évités).
2. **D2 — Emplacement UI** : onglet "Profils" dans `/admin/settings` (option a). Justification : (a) 5.1c prévoit explicitement cet onglet futur (cf. docblock L16), (b) cohérence UX avec scaffold à onglets extensible, (c) une seule route à maintenir.
3. **D3 — Pattern de redirection legacy** : option (c) — redirections HTTP 302 dans `LegacyCatchallController::handle()` early-return. Justification : (a) préserve les bookmarks navigateurs, (b) ne touche pas aux fichiers legacy `gestion_gpo.php` (byte-identique préservé), (c) un seul point de modification controller.
4. **D4 — Source des stats `/tmp/du.txt`** : réimplémentation native dans `RoamingProfileService::getProfileStatsGlobal/forPath` (pas de réutilisation de `roaming_profiles_stats()` legacy). Justification : (a) decoupling complet de `partages.inc.php` (qui n'est pas chargé par bootstrap), (b) parsing 20 lignes simple à porter, (c) testable sans bootstrap.
5. **D5 — Génération du script bash** : pure PHP côté `RoamingProfileService::generatePurgeScript`. Justification : (a) decoupling complet du legacy `del_roam.php`, (b) test byte-for-byte facile, (c) validation path-traversal in-band.
6. **D6 — Auth endpoint `del-roam.sh`** : middleware Laravel dédié `AllowSe4FsScript` (port natif de `header_authorize_script` legacy). Justification : (a) testable avec `Config::set`, (b) pas de fuite stub legacy, (c) cohérent avec les autres middlewares Laravel.
7. **D7 — Validation path-traversal** : double validation (à l'écriture et à la génération). Justification : defense-in-depth — un admin légitime ne peut jamais persister `../`, et même une valeur héritée corrompue est skippée à la génération.

### Question(s) ouverte(s) à valider par Henri (avant ou pendant dev)

- **Q1 — `legacy/bootstrap.php` modification** : le bootstrap actuel charge `gpo.inc.php` + `gpo_ui.inc.php` mais **pas** `partages.inc.php`. Le service `RoamingProfileService::getProfileStatsGlobal` réimplémente le parsing `/tmp/du.txt` en natif (D4 ci-dessus), donc **aucune modif bootstrap nécessaire**. Confirmer ce choix avant développement.
- **Q2 — Position du bouton "Mettre à jour la GPO"** : actuellement spec'd dans la même card que les exclusions. Alternative possible : footer fixe de la page, ou bouton flottant. Recommandation SM : garder dans la card (pattern 5.1c saveDefaults). À confirmer en design review.
- **Q3 — Drill-down stats par utilisateur** : modale (recommandé) vs route dédiée `?path=...`. Recommandation SM : modale (cohérent UX page settings, évite navigation). Décision finale au dev (architecture-libre dans AC #7).
- **Q4 — Cron `/tmp/du.txt`** : la story 18f n'inclut pas la planification de la commande `du -k /home/profiles/* > /tmp/du.txt` (héritée du legacy `cron/du.sh`). À documenter dans Dev Notes mais pas dans le scope de cette story (cron exploitation à valider hors story).
- **Q5 — Confirmation grep T1.4** : `gestion_gpo.php` contient-il vraiment un lien vers `no_roam.php` ? Si non, la redirection AC #9 reste utile pour les bookmarks externes mais pas pour les liens internes. À confirmer en T1.4 avant dev.

### Patterns à suivre (rappel CLAUDE.md + memory)

- **Filesystem-based router** : `resources/views/pages/admin/settings/_partials/profils-itinerants-tab.blade.php` (tab) + `resources/views/pages/admin/settings/index.blade.php` modifié (5.1c). Aucun composant hors `pages/`.
- **Modale réutilisable** : `<x-molecules.modal wire:model="showXxxModal" closeMethod="closeXxxModal" title="...">` + sections `<x-molecules.modal.section title="..." icon="..." dense>`. Pattern strict `quota-override-modal.blade.php` (5.1b post-review).
- **Trait WithToasts** : `use App\Components\Traits\WithToasts;` dans la classe anonyme + appels `$this->toastSuccess(...)`, `$this->toastError(...)`. Pas de `toast_magic` direct depuis le composant Livewire.
- **Composants Blade** : `<x-molecules.xxx>`, `<x-organisms.xxx>` — JAMAIS `<livewire:components::molecules.xxx>` (memory : `feedback_blade_component_syntax`).
- **`base_path()`** : privilégier aux `dirname(__DIR__, N)` (memory : `feedback_prefer_base_path`).
- **`auth()->user()`** retourne un `App\Models\User` Eloquent (memory : `auth_uses_eloquent_user`). Plus de wrapper `AuthUser`.
- **`function_exists` defensive** : pattern `GpoSyncService:43` — vérifier avant chaque appel legacy même après bootstrap (resilience environnement dégradé).
- **Logs structurés** : préfixe `[RoamingProfileService]` cohérent `GpoSyncService`. Format `Log::error('[RoamingProfileService] op=getExclusions error=...', [...contexte])`.
- **Toasts génériques en exception** : `toastError('Impossible de ...')`, **PAS** `$e->getMessage()` (memory + leçon 5.1b post-review #4 — fuite info sensibilité).
- **Atomicité** : non applicable (toutes les écritures vont dans la GPO SYSVOL via les wrappers 18g qui gèrent leur propre atomicité). Le fichier `/tmp/du.txt` est lu en lecture seule.

### Project Structure Notes

```
# Fichiers à créer
app/Services/RoamingProfileService.php                                                # ~150 LOC
app/Http/Controllers/Admin/RoamingProfileController.php                               # ~30 LOC (méthode unique delRoamScript)
app/Http/Middleware/AllowSe4FsScript.php                                              # ~50 LOC
resources/views/pages/admin/settings/_partials/profils-itinerants-tab.blade.php       # ~250-350 LOC (Livewire SFC)
tests/Unit/Services/RoamingProfileServiceTest.php                                     # ≥ 4 tests
tests/Feature/Livewire/Admin/AdminSettingsProfilsItinerantsTabTest.php                # ≥ 5 tests
tests/Feature/Admin/RoamingProfileScriptEndpointTest.php                              # ≥ 2 tests

# Fichiers à modifier
resources/views/pages/admin/settings/index.blade.php                                  # ajout 2ᵉ bouton tab "Profils" + @if branche
routes/web.php                                                                        # ajout route GET /admin/gpo/del-roam.sh
app/Http/Controllers/LegacyCatchallController.php                                     # ajout map MIGRATED_TO_NATIVE early-return
tests/Feature/Legacy/LegacyCatchallTest.php (si existant)                             # ajout 3 tests redirection 302

# Fichiers à NE PAS modifier (vérification explicite)
legacy/bootstrap.php                                                                  # tout déjà chargé par 18a + 18g
legacy/stubs/gpo_deps.inc.php                                                         # stub roaming_profiles_stats inutilisé en 18f (réimpl native)
legacy/gpo_shim.inc.php                                                               # 18g done — wrappers SYSVOL
sambaedu/gpo/no_roam.php, user_profile_stats.php, del_roam.php                        # legacy figé (lecture seule)
sambaedu/includes/partages.inc.php                                                    # legacy figé (decoupling D4)
sambaedu/includes/gpo_ui.inc.php                                                      # legacy figé (UI native ne réutilise pas)
sambaedu/gpo/gestion_gpo.php (livré 18b)                                              # legacy figé (redirections 302 préservent les liens)
resources/views/pages/admin/settings/_partials/quotas-fs-tab.blade.php (5.1c)         # non-régression — onglet existant intact

# Fichiers de référence (lecture seule pour pattern)
app/Services/GpoSyncService.php                                                       # pattern bridge legacy via function_exists
app/Services/LegacyEmbedService.php                                                   # pattern require_once base_path('legacy/bootstrap.php')
resources/views/components/organisms/quota-override-modal.blade.php                   # pattern <x-molecules.modal> à décalquer
resources/views/pages/admin/settings/_partials/quotas-fs-tab.blade.php (5.1c)         # pattern Livewire SFC tab
tests/Feature/Livewire/Admin/AdminSettingsQuotasFsTabTest.php (5.1c)                  # pattern test Feature SFC tab
```

### Alignement avec l'architecture projet

- **Filesystem-based router CLAUDE.md** : OUI — partial dans `resources/views/pages/admin/settings/_partials/`.
- **Composants spécifiques CLAUDE.md** : OUI — modale réutilisable (`<x-molecules.modal>`) + `WithToasts`.
- **Architecture services Laravel** : `App\Services\RoamingProfileService` cohérent `App\Services\GpoSyncService`, `App\Services\Filesystem\XfsQuotaService`. Bridge legacy via `function_exists`.
- **Permissions Spatie** : `server.admin` réutilisée (pas de nouvelle permission).
- **Routing** : nouvelle route GET `/admin/gpo/del-roam.sh` dans le groupe `prefix('admin')->middleware('sambaedu.admin')`. Pas de nouvelle route page (l'onglet est servi par la page `/admin/settings` existante).

### Tests manuels VM (à exécuter par Henri post-merge — pas par le subagent)

```bash
# SSH dans la VM
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50

# Sur la VM
cd /var/www/sambaedu/laravel
php artisan test --filter='ProfilsItinerants|RoamingProfile'

# Smoke tests HTTP (après login navigateur, copier laravel_session)
curl -k -b "laravel_session=<valeur>" https://<vm>/admin/settings?tab=profils-itinerants | head -100
# Attendu : HTML cohérent avec onglet actif "Profils" + card "Exclusions"

curl -k "https://<vm>/admin/gpo/del-roam.sh?se4_key=<valeur_se4_key>"
# Attendu : Content-Type: text/plain + script bash (lignes commentaire + rm -fr ...)

curl -k -I https://<vm>/gpo/no_roam.php
# Attendu : HTTP/2 302 + Location: /admin/settings?tab=profils-itinerants

# Vérifier les logs
tail -f storage/logs/laravel.log | grep RoamingProfileService
```

### References

- Architecture — Cloisonnement Legacy : [_bmad-output/planning-artifacts/architecture.md#Cloisonnement-Legacy]
- Architecture — Shims : [_bmad-output/planning-artifacts/architecture.md#Shims]
- Epics — Story 1bis.18 (vue cluster + 18a/18b) : [_bmad-output/planning-artifacts/epics.md#Story-1bis-18]
- Epics — Story 1bis.18f : [_bmad-output/planning-artifacts/epics.md#Story-1bis-18f]
- Story 1bis.18a (bootstrap GPO) : [_bmad-output/implementation-artifacts/1bis-18a-module-gpo-includes-core.md]
- Story 1bis.18b (gestion_gpo legacy + audit exec pattern) : [_bmad-output/implementation-artifacts/1bis-18b-module-gpo-gestion-import-export.md]
- Story 1bis.18g (shims search_ad gpo + sysvol wrappers) : [_bmad-output/implementation-artifacts/1bis-18g-module-gpo-shims-ldap-sysvol.md]
- Story 4.7 (refonte native UI Livewire — pattern de pivot wallpaper) : [_bmad-output/implementation-artifacts/4-7-gestion-des-fonds-decran-wallpapers-eloquent.md]
- Story 4.8 (pattern Adapter scoping polymorphe) : [_bmad-output/implementation-artifacts/4-8-personnalisation-apps-extensible.md]
- Story 5.1c (page admin Livewire SFC à onglets — slot "Profils" prévu) : [_bmad-output/implementation-artifacts/5-1c-quotas-groupes-settings-flash-over-quota.md]
- Service de référence — Bridge legacy via function_exists : [app/Services/GpoSyncService.php]
- Service de référence — require_once bootstrap : [app/Services/LegacyEmbedService.php:43]
- Modale réutilisable — pattern à décalquer : [resources/views/components/organisms/quota-override-modal.blade.php]
- Pattern test Feature SFC tab : [tests/Feature/Livewire/Admin/AdminSettingsQuotasFsTabTest.php]
- Trait notifications : [app/Components/Traits/WithToasts.php]
- Sources legacy (lecture seule) : [sambaedu/gpo/no_roam.php], [sambaedu/gpo/user_profile_stats.php], [sambaedu/gpo/del_roam.php], [sambaedu/includes/gpo_ui.inc.php], [sambaedu/includes/partages.inc.php]
- Bootstrap + shims : [legacy/bootstrap.php], [legacy/gpo_shim.inc.php], [legacy/ldap.inc.php]

---

## Recommandation Modèle Dev

**Opus** — La story est **plus complexe** que la version shim initialement écrite, avec une montée en charge sur 4 axes :

1. **Multi-couches coordonnées** : nouveau service Laravel (`RoamingProfileService` ~150 LOC) + bridge legacy via bootstrap + Livewire SFC partiel (~300 LOC) + page settings modifiée + middleware d'auth dédié + endpoint script + redirections legacy. 6 fichiers neufs + 3 modifiés à concevoir cohéremment.
2. **Décisions architecturales fines** : choix onglet vs page dédiée (arbitré), pattern redirection 302 vs réécriture catchall, double validation path-traversal (defense-in-depth), réimplémentation native du parsing `/tmp/du.txt`. Plusieurs trade-offs à exécuter sans erreur.
3. **Sécurité critique** : path-traversal dans `del-roam.sh` (interpolation bash), middleware d'auth IP+clé, double Gate Livewire, échappement valeurs admin. Une faille passerait silencieusement.
4. **Testabilité du bridge legacy** : pas de mock du legacy (instruction Henri) — tests appellent réellement les shims 18g via bootstrap, ce qui exige un setup soigné des fixtures et une compréhension fine des early-returns gracefuls.

Sonnet pourrait suffire si le scope était shim simple, mais le pivot natif demande la rigueur architecturale et le multi-fichiers cohérent qui caractérisent bien Opus.

---

## Dev Agent Record

### Agent Model Used

claude-opus-4-7 (1M context) — dev story 2026-04-28.

### Debug Log References

- `php artisan test --filter='RoamingProfile|ProfilsItinerants|LegacyRoamingRedirects'` → 24 passing (4 suites).
- Régression suite `AdminSettingsPageTest|AdminSettingsQuotasFsTabTest|LegacyModuleGpoGestionTest|LegacyGpoShimsTest` : baseline 4 failed pré-existants (Vite manifest absent — non lié), 0 régression introduite par 18f.
- `composer dump-autoload` exécuté pour enregistrer `App\Services\RoamingProfileService` + `App\Http\Controllers\Admin\RoamingProfileController` + `App\Http\Middleware\AllowSe4FsScript`.

### Completion Notes List

- **Service `RoamingProfileService`** : implémenté avec bootstrap idempotent + `function_exists` defensive + try/catch global + log structuré `[RoamingProfileService]` (cohérent `GpoSyncService`). Stats `/tmp/du.txt` réimplémentées en natif (pas de dépendance `partages.inc.php`).
- **Validation path-traversal renforcée** : la regex `^[\w\-./ ]+$` autorise `.` (extensions), donc `..` passe. Ajout d'une méthode helper `RoamingProfileService::isValueSafe()` qui combine la regex ET un veto explicite sur `..`. Defense-in-depth : appelée à la fois dans `setExclusions()` (filtrage à l'écriture) ET dans `generatePurgeScript()` (filtrage à la génération bash). Le composant Livewire utilise aussi `isValueSafe` côté UI pour le toast user-friendly.
- **Endpoint `/admin/gpo/del-roam.sh`** : route placée HORS du groupe `prefix('admin')->middleware('sambaedu.admin')` car les logon scripts Windows n'ont pas de session web admin. L'auth IP/clé via `AllowSe4FsScript` est suffisante (port natif `header_authorize_script`). Légère divergence vs Tasks T3.6 — documenté ici. Sémantique d'auth = OR (whitelist IP **OU** clé) cohérent avec AC #6 + AC #10 cas #8.
- **Redirections legacy** : ajoutées en early-return en début de `LegacyCatchallController::handle()` (après strip UAI, avant blocage routes migrées et tout pipeline d'exécution). Préserve la query string pour `gpo/del_roam.php` (clé `se4_key` cruciale pour les logon scripts).
- **Tests : 24 verts** (largement au-dessus de l'objectif ≥ 8) :
  - 7 Unit `RoamingProfileServiceTest` (regex valide/rejet, byte-fidélité script, conversion `\` Windows, log warning sur skip, GPO absente graceful)
  - 8 Feature Livewire `AdminSettingsProfilsItinerantsTabTest` (rendu, blocages 403, add/remove via service, applyToGpo bump, drill-down stats)
  - 5 Feature `RoamingProfileScriptEndpointTest` (auth se4_key/IP/empty/wrong)
  - 4 Feature `LegacyRoamingRedirectsTest` (3 paths + preservation query string)
- **Conformité CLAUDE.md** : modale `<x-molecules.modal>` + `<x-molecules.modal.section>`, trait `WithToasts`, filesystem-based router (`pages/admin/settings/_partials/profils-itinerants-tab.blade.php`), composants `<x-molecules.xxx>`/`<x-organisms.xxx>` jamais `<livewire:components::...>`.
- **Runbook QA** : nouveau domaine `docs/qa/domains/gpo.md` créé (10 scénarios numérotés stables). Entrée ajoutée à `docs/qa/README.md` Domaines couverts.

### File List

**Créés** :
- `app/Services/RoamingProfileService.php` — ~290 LOC (bridge SYSVOL legacy + génération bash + parsing `/tmp/du.txt` + helper `isValueSafe`).
- `app/Http/Controllers/Admin/RoamingProfileController.php` — ~25 LOC (méthode unique `delRoamScript`).
- `app/Http/Middleware/AllowSe4FsScript.php` — ~55 LOC (port natif de `header_authorize_script`, auth IP+clé en `hash_equals`).
- `resources/views/pages/admin/settings/_partials/profils-itinerants-tab.blade.php` — ~330 LOC (Livewire SFC, 2 cards + 2 modales).
- `tests/Unit/Services/RoamingProfileServiceTest.php` — 7 tests.
- `tests/Feature/Livewire/Admin/AdminSettingsProfilsItinerantsTabTest.php` — 8 tests.
- `tests/Feature/Admin/RoamingProfileScriptEndpointTest.php` — 5 tests.
- `tests/Feature/Legacy/LegacyRoamingRedirectsTest.php` — 4 tests.
- `docs/qa/domains/gpo.md` — 10 scénarios manuels.

**Modifiés** :
- `resources/views/pages/admin/settings/index.blade.php` — ajout du tab `profils-itinerants` (bouton + branche `@elseif`).
- `routes/web.php` — ajout route GET `/admin/gpo/del-roam.sh` avec middleware `AllowSe4FsScript`.
- `app/Http/Controllers/LegacyCatchallController.php` — ajout early-return des 3 redirections legacy (gpo/no_roam.php, gpo/user_profile_stats.php, gpo/del_roam.php).
- `docs/qa/README.md` — ajout entrée GPO dans le tableau "Domaines couverts".

### Change Log

| Date       | Auteur | Description                                                                                       |
|------------|--------|---------------------------------------------------------------------------------------------------|
| 2026-04-27 | SM Opus | Création initiale de la story 1bis.18f — approche shim catchall (3 pages legacy embarquées via LegacyCatchallController) — ready-for-dev |
| 2026-04-27 | SM Opus | **Pivot option B (décision Henri)** : abandon shim catchall, refonte native Laravel/Livewire + service `RoamingProfileService` qui appelle wrappers SYSVOL 18g via bootstrap legacy. Onglet "Profils" injecté dans `/admin/settings` (5.1c). Endpoint `del-roam.sh` natif avec middleware d'auth IP/clé. Redirections HTTP 302 pour préserver les liens legacy. Réécriture intégrale de la story (AC, Tasks, Dev Notes, audit sécurité path-traversal, recommandation modèle dev passée à Opus). Statut maintenu `ready-for-dev`. |
| 2026-04-28 | dev claude-opus-4-7 | **Implémentation complète** — 4 fichiers neufs (service + controller + middleware + partial Livewire SFC) + 4 fichiers modifiés (settings index, web.php, LegacyCatchallController, qa README) + 4 suites de tests (24 tests verts, +24 vs baseline). Décisions techniques notables : (1) endpoint `del-roam.sh` placé HORS du groupe `sambaedu.admin` car les logon scripts n'ont pas de session web — auth IP/clé suffisante (légère divergence vs T3.6 documentée). (2) Validation path-traversal renforcée par helper `isValueSafe()` (regex + veto explicite sur `..`) car la regex seule autorise `.` et donc laissait passer `..`. Defense-in-depth UI + service. (3) Conformité CLAUDE.md stricte : `<x-molecules.modal>`, `WithToasts`, filesystem-based router. (4) Runbook QA : nouveau domaine `gpo.md` (10 scénarios) + entrée README. Status : ready-for-dev → review. |
| 2026-04-28 | review Sonnet + second avis Opus | **Code review adversariale** — 7 problèmes Sonnet ; second avis Opus invalide #2 comme faux positif (`du -b` legacy → formule `/1024/1024` arithmétiquement juste). Document de review : `_bmad-output/codeReviews/1bis-18f.md` (status `to-validate`). |
| 2026-04-28 | dev claude-opus-4-7 | **Review fixes appliqués** (6/7) : #4 try/catch englobant `setExclusions` (cohérence vs `getExclusions`), #5 test unit direct `setExclusions` filtrage path-traversal (15 lignes), #6 `Log::info` → `Log::debug` `AllowSe4FsScript` (évite flood logon scripts), #7 `getExclusions` filtre via `isValueSafe` + log warning (cohérence lecture↔écriture, évite divergence affichage/persistance lors d'`applyToGpo`), #8 garde anti-écrasement total `setExclusions` (`RuntimeException` si toutes valeurs filtrées) + commentaires `du -k` → `du -b` corrigés (lignes 234, 320 du service). Tests post-corrections : 25/25 verts (+1 nouveau test) — 0 régression. Restent en attente Henri : Q1 (`se4_key` source de vérité — env vs `$config` legacy), Q2 (sémantique OR vs AND middleware — décision OR déjà tracée). Migration sambaedu-reload → w1bis effectuée (worktree w1bis maintenant équipé de symlinks `_bmad/_bmad-output`, fichiers neufs supprimés sur la VM via SSH). Status maintenu `review` en attente validation Henri. |
