# Domaine Filesystem — Quotas XFS

> Dernière mise à jour : 2026-04-25 (story 5.1c).

## Vue d'ensemble

La gestion des quotas XFS repose sur 3 éléments principaux :

1. **`App\Services\Filesystem\XfsQuotaService`** (story 5.1a) — service unique,
   seul point d'entrée pour interagir avec le filesystem XFS. Encapsule les
   shellouts `sudo xfs_quota ...` et `sudo quota ...`. Aucun autre composant
   (controller, Livewire, Blade) ne doit invoquer directement ces binaires.
2. **`App\Console\Commands\QuotaSnapshotCommand`** (story 5.1b) — commande
   `quota:snapshot`, planifiée quotidiennement à 03h00. Parse
   `xfs_quota -x -c 'report -a -N'` en une passe pour chaque partition
   supportée et alimente la colonne `users.quota_snapshot`.
3. **Colonne JSON `users.quota_snapshot`** (story 5.1b) — cache structuré
   lu par la page `/users` (colonne Utilisation %) et par la section Quota
   de la fiche `/users/[login]`. Zéro shellout au rendu.

## Structure du snapshot

Format du document JSON stocké dans `users.quota_snapshot` :

```json
{
  "home": {
    "used_kb": 12345,
    "soft_kb": 500000,
    "hard_kb": 600000,
    "used_mb": 12,
    "soft_mb": 488,
    "hard_mb": 586,
    "percent": 3,
    "is_over_soft": false,
    "is_over_hard": false,
    "grace_days": null
  },
  "sambaedu": { "...": "idem" },
  "captured_at": "2026-04-23T03:00:05+02:00"
}
```

- `used_kb` / `soft_kb` / `hard_kb` : valeurs brutes telles qu'exposées par
  `xfs_quota` (utile pour audit + debug).
- `used_mb` / `soft_mb` / `hard_mb` : pré-converties (arrondi) pour accélérer
  le rendu UI (zéro calcul côté Blade).
- `percent` : `min(100, round(used_mb / soft_mb * 100))` ou `0` si
  `soft_mb === 0` (quota illimité).
- `is_over_soft` : vrai si la ligne rapport XFS suffixait la colonne `used`
  d'une astérisque `*`.
- `is_over_hard` : calculé comme `used_kb > hard_kb && hard_kb > 0`.
- `grace_days` : nombre de jours extrait de `[N days]` si applicable, sinon
  `null` (`[--------]`).
- `captured_at` : horodatage ISO 8601 avec timezone, correspond à l'instant
  de la commande `quota:snapshot` (ou du refresh manuel).

Partition absente (ex : `/home` monté en ext4) : la clé correspondante est
**absente** du snapshot, pas stockée avec des valeurs à zéro. Le rendu UI
tombe en fallback gracieusement.

## Planification

```php
// app/Console/Kernel.php
$schedule->command('quota:snapshot')
         ->dailyAt('03:00')
         ->withoutOverlapping()
         ->runInBackground();
```

Vérification sur la VM :

```bash
php artisan schedule:list
# 0 3 * * *   quota:snapshot   App\Console\Commands\QuotaSnapshotCommand
```

## Exécution manuelle

```bash
# Snapshot complet (toutes les partitions)
php artisan quota:snapshot

# Snapshot limité à une partition (debug)
php artisan quota:snapshot --partition=/home
```

## Comportement fail-soft

### Partition non-XFS ou échec `xfs_quota`

Si une partition est non-XFS (par exemple `/home` monté en ext4 sur une VM de
développement) ou si `xfs_quota` échoue (binaire absent, lock concurrent,
etc.) :

- Un log structuré est émis :
  `Log::error('QuotaService: échec report xfs_quota', [...contexte])`.
- La commande **continue** avec la partition suivante — pas de fail-fast.
- Exit code `FAILURE` uniquement si **TOUTES** les partitions échouent.

### User en BDD absent du rapport XFS

Si un user a déjà un `quota_snapshot` en BDD mais n'apparaît dans AUCUN des
rapports parsés cette run (compte archivé, home supprimé, user ignoré par
xfs_quota…) :

- Son `quota_snapshot` existant est **conservé** (pas effacé).
- Un log `Log::info('QuotaService: user absent du rapport XFS', ['login' => ..., 'partitions_checked' => [...]])`
  est émis pour audit avec la liste des partitions scannées cette run.
- Comptabilisé dans le récap console sous `users BDD absents du rapport XFS`.

### User dans le rapport mais absent de la BDD

Cas rare (comptes système non synchronisés, uid sans ligne users) :

- Un log `Log::info('QuotaService: login XFS sans correspondance BDD', [...])`
  est émis.
- Aucune ligne BDD n'est créée (pas de side-effect).
- Comptabilisé dans le récap console sous `logins XFS sans correspondance BDD`.

## Refresh manuel depuis la fiche user

La section Quota Livewire de `/users/[login]` expose un bouton **Actualiser**
réservé aux utilisateurs avec la permission Spatie `server.admin` (décision
révisée post code review 5.1b : le refresh déclenche des shellouts
`sudo xfs_quota` coûteux, il est limité aux admins pour éviter un DoS). Au
clic :

1. Double guard : `Gate::allows('server.admin')` en première ligne serveur +
   `@can('server.admin')` côté UI. Payload Livewire forgé → `abort(403)`.
2. Rate-limit : 5 refresh max / 60s par user (via `RateLimiter::attempt`).
   Dépassement → toast "Trop de rafraîchissements. Réessayez dans un instant."
3. Check existence du user en BDD **avant** tout shellout — user disparu →
   toast et sortie (pas d'exec inutile).
4. `XfsQuotaService::getDiskUsage($login)` est invoqué en synchrone (lecture
   XFS live pour ce seul user).
5. `users.quota_snapshot` est mis à jour avec les nouvelles valeurs +
   `captured_at = now()`.
6. Un toast `WithToasts::toastSuccess('Quota actualisé')` confirme, ou
   `toastError('Impossible de rafraîchir le quota. Consultez les logs.')` en
   cas d'échec — message générique, pas d'exception fuitée côté UX.
7. Log structuré : `QuotaService: refresh manuel` (success) ou
   `QuotaService: échec refresh snapshot` (erreur, avec exception détaillée).

En cas d'erreur partition-spécifique (une des 2 partitions remonte une
erreur), la clé correspondante est **conservée** intacte dans le snapshot —
seule la partition qui a répondu est rafraîchie.

## Override quota utilisateur

Réservé aux utilisateurs avec permission Spatie `server.admin`. Double guard :

- UI : `@can('server.admin')` conditionne l'affichage du bouton "Modifier le
  quota" et la modale d'override.
- Serveur : `Gate::allows('server.admin')` vérifié en première ligne dans
  `applyOverride()` — `abort(403)` sinon, même en cas de payload Livewire
  forgé.

L'override passe par `XfsQuotaService::setQuotaRule(..., applyImmediately: true)`
qui dispatch `ApplyQuotaJob` sur la queue `quotas` (application asynchrone).
Après dispatch, le composant appelle `refreshSnapshot()` pour refléter
immédiatement le nouveau breakdown héritage.

Validation serveur : un soft quota sur `/home` doit être >= 10 Mo (cohérent
avec `QuotaController::updateUserQuota`).

## Sudoers VM

La commande `quota:snapshot` invoque `sudo xfs_quota` sans mot de passe. Le
provisionnement `/etc/sudoers.d/sambaedu` (IrundoOS installer) doit inclure
la ligne :

```
www-data ALL=(root) NOPASSWD: /usr/sbin/xfs_quota
www-data ALL=(root) NOPASSWD: /usr/bin/quota
```

## Quota groupe — UI Livewire

La fiche `/app/users/groups/[id]` expose une section "Quota du groupe" en
Livewire SFC (`pages.users.groups.[id]._partials.group-quota-section`) livrée
par la story 5.1c. Pattern décalqué 1:1 sur la section quota user (5.1b).

Affichage par partition (`/home`, `/var/sambaedu`) :

- **Aucune règle `QuotaRule::TYPE_GROUP`** pour ce groupe → label
  "Hérité (défaut)" avec badge ghost. Les utilisateurs membres du groupe
  héritent du quota par défaut de leur profil.
- **Règle avec `quota_soft_mb=0 && quota_hard_mb=0`** → label "Illimité"
  (badge success). Equivaut à pas de limite XFS pour ce groupe.
- **Règle custom** → badge info avec format `{soft_mo} Mo (+{overage}%)`
  (ou `{n} Go (+{overage}%)` si soft >= 1024).

### Override quota groupe

Réservé aux utilisateurs avec permission Spatie `server.admin` (cohérent
avec override user). Double guard :

- UI : `@can('server.admin')` conditionne le bouton "Modifier" + la modale.
- Serveur : `Gate::allows('server.admin')` en première ligne d'`applyOverride()`
  → `abort(403)` sinon (testé via `forged payload → 403`).

L'override appelle, selon le type :

- `inherited` → `XfsQuotaService::deleteQuotaRule($rule, $performedBy)` qui
  dispatch en interne `dispatchRecalculateGroupJob` (XfsQuotaService:365).
- `unlimited` → `setQuotaRule(TYPE_GROUP, $name, $partition, 0, 0, ..., applyImmediately=true)`.
- `custom` → `setQuotaRule(TYPE_GROUP, $name, $partition, $soft, $hard, ..., applyImmediately=true)`
  avec `$hard = round($soft * (1 + $overage/100))`. Validation serveur
  `$soft >= 10 Mo` sur `/home`.

Le SFC ne redispatcher PAS le recalcul groupe — `XfsQuotaService` le fait
automatiquement quand `applyImmediately=true` ET `type=group`.

## Réglages système — `/admin/settings` onglet "Quotas & FS"

Page `pages.admin.settings.index` livrée par la story 5.1c. Scaffold à
onglets extensible (pattern `parc-settings/index.blade.php` :
`#[Url(keep:true)] $tab` + `setTab()`). En 5.1c : un seul onglet "Quotas & FS"
visible (D3=A — interdiction stricte de placeholders).

Route : `Route::livewire('/settings', 'pages::admin.settings.index')->middleware('can:server.admin')`
dans le groupe `admin` (middleware `sambaedu.admin` hérité).

L'onglet expose 3 sections (cards) :

### Section 1 — Quotas par défaut (par profil)

Pour chacun des 4 profils (`eleve`, `prof`, `admin`, `itinerant`) ET chacune
des 2 partitions (`/home`, `/var/sambaedu`), un trio d'inputs : `soft_mb`,
`overage_percent` (0-100), et `hard_mb` calculé read-only en live (Blade).

Persistance : `SystemSetting::set('quota.defaults', [...])` (table K/V JSON).
Structure persistée :

```json
{
  "eleve":     {"home": {"soft_mb": 200, "overage_percent": 25}, "sambaedu": {...}},
  "prof":      {"home": {...}, "sambaedu": {...}},
  "admin":     {"home": {...}, "sambaedu": {...}},
  "itinerant": {"home": {...}, "sambaedu": {...}}
}
```

> **Note 5.1c** : le profil `itinerant` est PASSIVE en 5.1c. Les valeurs
> sont persistées et un badge "Effectif en 5.1d" apparaît. La logique de
> lecture (`XfsQuotaService::getEffectiveQuota` lit `quota.defaults.itinerant`
> si `User::isExternal()`) sera livrée par la story 5.1d.

Validation serveur : `soft_mb >= 10` sur `/home` (sauf 0 = illimité accepté).

### Section 2 — Période de grâce

2 inputs (1 par partition, 0-30 jours). Persistance double :

1. `QuotaSetting::forPartition($partition)->update(['grace_period_days' => $n])`
   (table existante `quota_settings`).
2. Application synchrone post-save : `XfsQuotaService::setGracePeriod()`
   (D4=A). Échec filesystem (XFS indisponible, sudoers manquant) ne bloque
   PAS la persistance BDD — un toast info "application reportée" est émis.

### Section 3 — Corbeille (`/home/trash`)

Input TTL (1-365 jours) + toggle "Purge automatique". Persistance :
`SystemSetting::set('quota.trash', ['ttl_days' => N, 'purge_auto' => bool])`.

Banner info visible : "Cette configuration sera consommée par la commande
`trash:purge` livrée dans la prochaine version (5.1d)." Aucune commande
Artisan n'est exécutée en 5.1c — la persistance seule suffit. La commande
`trash:purge` (5.1d) lira ces settings au runtime.

### Sécurité

Double guard sur chaque méthode publique du composant :
- `mount()`, `setTab()`, `saveDefaults()`, `saveGrace()`, `saveTrash()`
  appellent `Gate::allows('server.admin')` en première ligne → `abort(403)`.
- Middleware route `can:server.admin` (cohérent avec `/admin/sync-from-ad`).

## Toast over-quota au login

Story 5.1c — listener `App\Listeners\NotifyQuotaOverageOnLogin` câblé sur
l'event `Illuminate\Auth\Events\Login` dans `EventServiceProvider::$listen`.

### Flux

1. Utilisateur se connecte (handshake AD via `SambaEduAuthGuard`).
2. `Auth::login($eloquentUser)` est appelé par le middleware → Laravel émet
   `Login::class`.
3. Le listener lit `$event->user->quota_snapshot` (cast 'array' sur User).
4. Si une partition est `is_over_soft` OU `is_over_hard` → `ToastMagic::warning()`
   est appelée (stockage session, rendu via `{!! ToastMagic::scripts() !!}`
   déjà dans `layouts/app.blade.php`).
5. Le toast apparaît au render de la première page post-login.

### Idempotence (1×/session)

Garantie naturellement par le pattern Login event :
- Laravel n'émet `Login::class` qu'au PREMIER `Auth::login()` d'une session,
  pas à chaque revalidation de cookie.
- `SambaEduAuthGuard::handle()` ne re-call pas `Auth::login` quand
  `Auth::check()` est déjà true (l. 77).

### Format des messages

- 1 partition over → `ToastMagic::warning("Votre espace {label} est dépassé.", "X Mo utilisés / Y Mo autorisés. Libérez de l'espace pour éviter les blocages.")`
- 2 partitions over → 1 SEUL toast avec titre "Plusieurs espaces de stockage sont dépassés." + description multi-lignes (UX moins bruyante que 2 toasts séparés).

### Sécurité défensive

`handle()` est entièrement wrappé dans un try/catch global qui log
silencieusement (`Log::warning('QuotaService: listener NotifyQuotaOverageOnLogin échoué', ...)`)
sans relancer l'exception. **Un échec listener NE DOIT PAS empêcher le login**
— le toast n'est qu'un bonus UX.

## Modèle `SystemSetting` (story 5.1c)

Pattern K/V JSON pour les paramètres applicatifs globaux. Utilisable au-delà
des quotas (futurs onglets DHCP/CUPS/...).

```php
SystemSetting::get('quota.trash');                         // ?array
SystemSetting::get('quota.defaults', $defaultStructure);   // mixed
SystemSetting::set('quota.trash', ['ttl_days' => 30, 'purge_auto' => false]);
SystemSetting::forget('quota.trash');                      // suppression
```

Migration `2026_04_25_100000_create_system_settings_table` :
`key string(191) unique` + `value jsonb (pgsql) / json (sqlite)` conditionnel
via `DB::getDriverName()` (cohérent avec `add_quota_snapshot` 5.1b et
`delegation_history` 7.1). Cast `'value' => 'array'` sur le modèle.

## Références

- Story 5.1a — refactor services filesystem
  (`_bmad-output/implementation-artifacts/5-1a-refactor-services-filesystem.md`)
- Story 5.1b — snapshot quotas quotidien et UI user
  (`_bmad-output/implementation-artifacts/5-1b-snapshot-quotas-quotidien-et-ui-user.md`)
- Story 5.1c — quotas groupes, /admin/settings scaffold et flash over-quota au login
  (`_bmad-output/implementation-artifacts/5-1c-quotas-groupes-settings-flash-over-quota.md`)
- Legacy : `sambaedu/includes/quotas.inc.php:96-137` (`repquota()`) — modèle
  pour le parser.
