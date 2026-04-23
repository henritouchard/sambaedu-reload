# Domaine Filesystem — Quotas XFS

> Dernière mise à jour : 2026-04-23 (story 5.1b).

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

## Références

- Story 5.1a — refactor services filesystem
  (`_bmad-output/implementation-artifacts/5-1a-refactor-services-filesystem.md`)
- Story 5.1b — snapshot quotas quotidien et UI user
  (`_bmad-output/implementation-artifacts/5-1b-snapshot-quotas-quotidien-et-ui-user.md`)
- Legacy : `sambaedu/includes/quotas.inc.php:96-137` (`repquota()`) — modèle
  pour le parser.
