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

> **Note 5.1d (2026-04-27)** : le profil `itinerant` est ACTIF depuis la
> story 5.1d. `XfsQuotaService::getEffectiveQuota()` applique la règle
> `QuotaRule::TYPE_DEFAULT_ITINERANT` quand `User::isExternal()` retourne
> `true` ET qu'aucune règle USER/GROUP ne s'applique (la résolution se fait
> via lookup interne `User::where('login', $username)` — D5=A signature
> publique préservée). La règle itinérante prime sur le default profil
> (D9=A confirmée Henri).

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

Depuis la story 5.1d (2026-04-27) :
- Commande `trash:purge` planifiée à 02h00 quotidiennement, **conditionnée**
  par le toggle `purge_auto` via `->when(closure)` dans `Console\Kernel`
  (la closure lit `SystemSetting::get('quota.trash')` à chaque tick — prise
  d'effet immédiate du toggle UI sans redéploiement).
- Bouton "Purger maintenant" disponible dans la card Corbeille : appel
  synchrone `Artisan::call('trash:purge', ['--performed-by' => 'ui:<login>'])`
  avec parsing du compteur "Purgé : N" + toast WithToasts succès/info/erreur.
  Pré-check TTL côté UI : si `ttl_days <= 0`, toast d'erreur explicite
  ("Corbeille non purgée — TTL non configuré") sans appel Artisan.
- Le banner info "à venir 5.1d" a été retiré (la commande est livrée).

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

## Story 5.1d — `default_itinerant`, `trash:purge`, seed legacy

Livré le **2026-04-27** par claude-opus-4-7. Trois volets indépendants qui
clôturent la Story 5.1 splittée.

### Volet 1 — `default_itinerant` actif dans `getEffectiveQuota`

`XfsQuotaService::getEffectiveQuota()` applique désormais la règle
`QuotaRule::TYPE_DEFAULT_ITINERANT` quand l'utilisateur est externe
(`User::isExternal() == true`) ET qu'aucune règle USER/GROUP ne s'applique.
La règle itinérante est **prioritaire** sur le default profil
(élève/prof/admin) — D9 confirmée par Henri.

Ordre de résolution mis à jour :

1. `TYPE_USER` (règle utilisateur explicite)
2. `TYPE_GROUP` (plus grand quota parmi les groupes)
3. **NOUVEAU** : `TYPE_DEFAULT_ITINERANT` si `isExternal()` et règle existe
4. `TYPE_DEFAULT_<eleve|prof|admin>` (fallback profil)

**Décision D5=A** : la signature publique de `getEffectiveQuota` est
préservée — un lookup interne `User::where('login', $username)->first()`
résout `isExternal` sans propager le coût aux 6+ call sites externes
(snapshot, wallpaper, livewire, controllers). Coût négligeable
(~0.5ms primary key SELECT).

Fallback silencieux : si `isExternal()=true` mais aucune règle
`TYPE_DEFAULT_ITINERANT` n'existe pour la partition, on poursuit vers le
default profil. Aucun warning (la règle est optionnelle).

### Volet 2 — Commande `trash:purge`

Commande Artisan `php artisan trash:purge {--dry-run} {--force}` qui purge
les sous-dossiers de `/home/trash/*` plus vieux que `quota.trash.ttl_days`.

**Configuration** :
- TTL et toggle auto persistés dans `SystemSetting::get('quota.trash')`
  (table `system_settings` — story 5.1c).
- Si `ttl_days <= 0` ou clé absente : **no-op safe** (D2=A) — log warning
  + exit SUCCESS, aucun dossier supprimé.
- `--force` ignore ce garde-fou (utilisé par le bouton "Purger maintenant"
  pour permettre une purge ad-hoc si l'admin a oublié de configurer le TTL).

**Comportement** :
- Énumère `/home/trash/*` (1 niveau, dossiers seulement).
- Anti-injection : regex `[a-zA-Z0-9._-]+` sur chaque nom — les dossiers
  au nom suspect sont skipped (Log::warning) sans suppression.
- Pour chaque dossier expiré : appel `HomeDirService::deleteHomeDirectoryPermanently()`
  (réutilisation 5.1a — 41 tests anti-injection éprouvés).
- **Audit** (D6=A) : chaque suppression réussie écrit dans `quota_audit_logs`
  avec `target_type='trash'`, `action='delete'`, `performed_by='trash:purge'`
  (ou `'ui:<login>'` si déclenché par le bouton UI). `old_values`
  contient le path, mtime et age_days.

> **Note `quota_audit_logs.target_type`** : la colonne est un `string` libre
> (pas d'enum SQL — cf. migration `2026_02_20_100000_create_quota_tables.php`).
> Les valeurs effectivement émises par le code applicatif au 2026-04-27 sont :
> `user`, `group`, `default_eleve`, `default_prof`, `default_admin`,
> `default_itinerant`, `trash`. Le commentaire historique de la migration
> n'inclut pas `default_itinerant` ni `trash` — c'est une dette doc connue,
> pas de re-migration nécessaire (la colonne accepte déjà ces valeurs).

**Fail-soft** (cohérent 5.1b D3) : un échec sur un dossier ne bloque pas
les suivants. Exit `Command::FAILURE` UNIQUEMENT si TOUTES les suppressions
candidates ont échoué.

**Planification** : `Console\Kernel::schedule()` à 02h00 quotidiennement,
**conditionné** par le toggle `quota.trash.purge_auto` via `->when(closure)`.
La closure est ré-évaluée à chaque tick (1 minute) — coût négligeable
(1 SELECT primary key). Prise d'effet immédiate du toggle UI sans
redéploiement.

**Bouton "Purger maintenant"** dans `/admin/settings → Quotas & FS`
section Corbeille : appel `Artisan::call('trash:purge', ['--performed-by' => 'ui:<login>'])`
synchrone (D3=A — volume faible ≤50 dossiers, feedback immédiat). Toast
WithToasts de succès ou d'erreur selon l'exit code, avec parsing du
compteur "Purgé : N" pour afficher le nombre exact.

### Volet 3 — Commande `quota:seed-from-legacy`

Commande Artisan one-shot `php artisan quota:seed-from-legacy {--dry-run}
{--force}` qui importe les règles depuis la table MySQL legacy `quotas`
(schéma `nom string, quotasoft int (KB), quotahard int (KB), partition string`).

**Connexion legacy** : nouvelle entrée `legacy_mysql` dans
`config/database.php` (D1=A), lue via `env('LEGACY_DB_*')`. Si la
connexion n'est pas configurée (database/username vides), la commande
log `Log::error` + affiche un message stdout explicite ("Connexion legacy
non configurée — ajouter LEGACY_DB_*…") et retourne `Command::FAILURE`
(AC 14).

**Discrimination user/group** : `UserGroup::where('name', $nom)->exists()`.
Si match → `TYPE_GROUP`, sinon → `TYPE_USER`. Convention métier : les
groupes synchronisés depuis l'AD sont la source de vérité.

**Conversion KB → MB** : `round($quotasoft / 1024)` — le legacy stocke en
KB, SambaEdu Reload utilise des MB partout.

**Idempotence** :
- Sans `--force` : skip silencieux des règles déjà présentes (log info).
- Avec `--force` : `update` au lieu de `firstOrCreate` — réécrit les
  valeurs existantes.

**Init defaults profils** (D4 confirmée) : à chaque exécution, vérifie
qu'il existe une règle `TYPE_DEFAULT_<eleve|prof|admin|itinerant>` × deux
partitions (`/home`, `/var/sambaedu`). Si absente, la crée avec les
valeurs raisonnables :

| Profil       | Soft Mo | Hard Mo |
|--------------|--------:|--------:|
| élève        |     500 |     600 |
| prof         |    1000 |    1200 |
| admin        |    2000 |    2400 |
| itinérant    |     200 |     240 |

Mêmes valeurs sur `/home` et `/var/sambaedu` — les partages classes/docs
sont gros, pas de raison de les diviser. Sans `--force`, les defaults
existants ne sont pas écrasés (l'admin peut les avoir personnalisés via
l'onglet `/admin/settings → Quotas & FS`).

**Audit** : chaque INSERT/UPDATE est tracé dans `quota_audit_logs` avec
`performed_by='quota:seed-from-legacy'`.

> **Note ops (review #M8)** : la commande `quota:seed-from-legacy` doit
> être exécutée en tant que **root ou www-data** sur la VM (équivalent
> `php artisan ...`). Aucun guard d'authentification utilisateur n'est
> implémenté — la commande est destinée aux ops uniquement. La protection
> repose sur les permissions Unix sur l'`artisan` binary et la connexion
> `legacy_mysql` (mots de passe stockés dans `.env` non lisible par les
> autres comptes).

**Mode `--dry-run`** : preview tabulaire des candidats sans toucher BDD.

**Variables `.env` à configurer en prod** :

```env
LEGACY_DB_HOST=127.0.0.1
LEGACY_DB_PORT=3306
LEGACY_DB_DATABASE=sambaedu
LEGACY_DB_USERNAME=root
LEGACY_DB_PASSWORD=...
LEGACY_DB_SOCKET=
```

### Aucune migration nouvelle en 5.1d

Le champ `quota_rules.type` est `string(20)` — la constante
`TYPE_DEFAULT_ITINERANT = 'default_itinerant'` (16 chars) tient dans la
limite et était déjà persistable depuis 5.1c. Aucune migration
nécessaire.

---

## Références

- Story 5.1a — refactor services filesystem
  (`_bmad-output/implementation-artifacts/5-1a-refactor-services-filesystem.md`)
- Story 5.1b — snapshot quotas quotidien et UI user
  (`_bmad-output/implementation-artifacts/5-1b-snapshot-quotas-quotidien-et-ui-user.md`)
- Story 5.1c — quotas groupes, /admin/settings scaffold et flash over-quota au login
  (`_bmad-output/implementation-artifacts/5-1c-quotas-groupes-settings-flash-over-quota.md`)
- Story 5.1d — gaps produits filesystem (default_itinerant, purge trash, seed legacy)
  (`_bmad-output/implementation-artifacts/5-1d-gaps-produits-itinerant-purge-seed.md`)
- Legacy : `sambaedu/includes/quotas.inc.php:96-137` (`repquota()`) — modèle
  pour le parser.
- Legacy : `sambaedu/includes/quotas.inc.php:172` — schéma table `quotas`
  legacy (source du seed 5.1d Volet 3).

---

*Dernière mise à jour : 2026-04-27 (Story 5.1d)*
