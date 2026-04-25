# QA manuel — Domaine Filesystem (Quotas XFS)

> Runbook E2E pour les stories du domaine Filesystem. Append-only : chaque
> story ajoute une section avec ses scénarios numérotés stables.

**Pré-requis** :

- VM accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- Migrations à jour : `cd /var/www/sambaedu-reload && php artisan migrate`
- Cache Spatie reset si évolutions permission : `php artisan permission:cache-reset`
- Sudoers VM : `www-data ALL=(root) NOPASSWD: /usr/sbin/xfs_quota` + `quota`
  (uniquement nécessaire pour les scénarios qui touchent le filesystem).

---

## Story 5.1c — Quotas groupes, `/admin/settings`, flash over-quota au login

**Date livraison** : 2026-04-25
**Migrations à appliquer** : `2026_04_25_100000_create_system_settings_table`

### Scénario 5.1c-1 — Section Quota groupe : affichage par défaut (Hérité)

**Pré-requis** : un groupe sans règle `QuotaRule::TYPE_GROUP` (ex: nouveau
groupe "test-classe-6a").

1. Se connecter en `admin` (server.admin).
2. Naviguer vers `/app/users/groups/{id}` du groupe en question.
3. Vérifier qu'une nouvelle section "Quota du groupe" est affichée entre la
   liste des membres et la wallpaper-card.
4. Pour chaque partition (`/home`, `/var/sambaedu`) : badge ghost "Hérité (défaut)"
   + texte explicatif "Aucune règle groupe — les utilisateurs héritent du
   quota par défaut de leur profil."
5. Le bouton "Modifier" est visible (admin server.admin).

### Scénario 5.1c-2 — Override quota groupe (custom)

1. Sur la même page, partition `/home`, cliquer "Modifier".
2. La modale s'ouvre via `<dialog class="modal">` (vérifier l'animation +
   le clic en dehors qui doit fermer via `modal-backdrop`).
3. Sélectionner type "Personnalisé". Soft = 500, Overage = 20.
4. Cliquer "Appliquer" → toast success "Quota /home : 500 Mo (+20% grâce)."
5. La modale se ferme. La section affiche maintenant un badge info
   `500 Mo (+20%)`.
6. Vérifier en BDD : `SELECT * FROM quota_rules WHERE type='group' AND target='test-classe-6a'`
   → 1 row avec `quota_soft_mb=500`, `quota_hard_mb=600`.

### Scénario 5.1c-3 — Override quota groupe (illimité)

1. Re-cliquer "Modifier" sur `/home`.
2. Sélectionner type "Illimité".
3. Cliquer "Appliquer" → toast success "Quota /home : illimité."
4. La section affiche badge success "Illimité".
5. BDD : la même row a `quota_soft_mb=0`, `quota_hard_mb=0`.

### Scénario 5.1c-4 — Override quota groupe (retour à l'héritage)

1. Re-cliquer "Modifier" sur `/home`.
2. Sélectionner type "Hériter (utiliser le défaut profil)".
3. Cliquer "Appliquer" → toast success "Quota /home : retour à l'héritage."
4. La section affiche badge ghost "Hérité (défaut)".
5. BDD : la row a été supprimée (`COUNT(*) = 0`).

### Scénario 5.1c-5 — Validation soft >= 10 Mo sur /home

1. Modifier `/home` → type Personnalisé → Soft = 5 → Overage = 20.
2. Cliquer "Appliquer".
3. Erreur de validation visible sous le champ Soft : "Le quota sur /home doit
   être d'au moins 10 Mo."
4. La modale reste ouverte. Aucune row créée en BDD.

### Scénario 5.1c-6 — Bouton "Modifier" caché sans server.admin

1. Se connecter en utilisateur sans permission `server.admin` (ex: prof normal).
2. Naviguer vers la fiche du même groupe.
3. La section "Quota du groupe" est visible en LECTURE SEULE.
4. **Aucun bouton "Modifier"** n'est affiché.
5. La modale n'est pas générée dans le DOM (vérifier via DevTools — pas de
   `<dialog class="modal">` correspondant).

### Scénario 5.1c-7 — Bypass payload Livewire forgé sur applyOverride

1. Toujours en utilisateur sans `server.admin`, ouvrir DevTools sur la fiche
   groupe.
2. Forger un appel Livewire vers `applyOverride` (via `Livewire.find('xxx').applyOverride()`
   ou injection manuelle dans le payload réseau).
3. Réponse HTTP 403 (page d'erreur Laravel).
4. Logs Laravel : aucune écriture BDD, aucun dispatch ApplyQuotaJob.

### Scénario 5.1c-8 — Page `/admin/settings` accessible

1. Se reconnecter en `admin` (server.admin).
2. Vérifier le lien "Réglages" dans la sidebar (sous "Migration", icône
   engrenage). Il est visible UNIQUEMENT pour server.admin.
3. Cliquer le lien → navigation vers `/admin/settings`.
4. La page affiche un seul onglet "Quotas & FS" (pas de placeholder, pas de
   "coming soon").
5. L'URL contient `?tab=quotas-fs` (paramètre `Url(keep:true)`).

### Scénario 5.1c-9 — Onglet "Quotas & FS" : Defaults par profil

1. Sur `/admin/settings?tab=quotas-fs`, section "Quotas par défaut".
2. Pour chaque profil (élève / prof / admin / itinérant) × 2 partitions
   (`/home`, `/var/sambaedu`) : 3 inputs visibles (Soft Mo, Overage %, Hard Mo
   read-only).
3. Le profil "Itinérant" affiche un badge "Effectif en 5.1d".
4. Modifier élève /home soft = 200, overage = 25 → Hard se met à jour live à 250.
5. Cliquer "Enregistrer les defaults" → toast success "Réglages enregistrés".
6. Recharger la page → les valeurs sont persistées (`SystemSetting::get('quota.defaults')`).

### Scénario 5.1c-10 — Onglet "Quotas & FS" : Validation defaults soft < 10 Mo

1. Modifier élève /home soft = 5 → Cliquer "Enregistrer".
2. Erreur visible sous le champ : "Le quota /home pour le profil eleve doit
   être d'au moins 10 Mo (ou 0 pour illimité)."
3. Aucune persistance n'a eu lieu (BDD inchangée).

### Scénario 5.1c-11 — Onglet "Quotas & FS" : Période de grâce

1. Section "Période de grâce". Inputs `/home` et `/var/sambaedu` (0-30 jours).
2. Régler `/home` = 14, `/var/sambaedu` = 21.
3. Cliquer "Enregistrer la période de grâce".
4. Si XFS dispo + sudoers OK : toast "Période de grâce mise à jour."
5. Si XFS indisponible (VM dev sans XFS) : toast info "Période de grâce
   enregistrée (application filesystem reportée — consultez les logs)."
6. BDD : `SELECT grace_period_days FROM quota_settings` → 14 et 21.

### Scénario 5.1c-12 — Onglet "Quotas & FS" : TTL trash + toggle purge

1. Section "Corbeille". Banner info "Cette configuration sera consommée par
   la commande `trash:purge` livrée dans la prochaine version (5.1d)."
2. Régler TTL = 60, toggle Purge automatique = true.
3. Cliquer "Enregistrer la configuration corbeille" → toast success.
4. BDD : `SystemSetting::get('quota.trash')` → `['ttl_days' => 60, 'purge_auto' => true]`.
5. **Aucune commande Artisan n'est exécutée** (5.1d s'en chargera).

### Scénario 5.1c-13 — Accès `/admin/settings` bloqué sans server.admin

1. Se reconnecter en utilisateur sans `server.admin`.
2. Tenter `/admin/settings` directement via URL → 403 Forbidden (middleware
   `can:server.admin` à la route).
3. Le lien "Réglages" est ABSENT de la sidebar.

### Scénario 5.1c-14 — Toast over-quota au login

**Pré-requis** : un utilisateur `over-test` avec un `quota_snapshot` indiquant
un dépassement.

```bash
ssh root@192.168.122.50 "cd /var/www/sambaedu-reload && php artisan tinker"
# Dans tinker :
$u = \App\Models\User::where('login', 'over-test')->first();
$u->update(['quota_snapshot' => [
    'home' => ['used_kb' => 520000, 'soft_kb' => 500000, 'hard_kb' => 600000,
               'used_mb' => 510, 'soft_mb' => 500, 'hard_mb' => 586,
               'percent' => 100, 'is_over_soft' => true, 'is_over_hard' => false,
               'grace_days' => 5],
    'sambaedu' => ['used_mb' => 1, 'soft_mb' => 98, 'is_over_soft' => false,
                   'is_over_hard' => false],
    'captured_at' => now()->toIso8601String(),
]]);
```

1. Se déconnecter, vider les cookies pour cet utilisateur.
2. Se reconnecter en `over-test` (passage par `SambaEduAuthGuard`).
3. Au render de la première page post-login : un toast warning apparaît
   avec titre "Votre espace Espace personnel est dépassé." et description
   "510 Mo utilisés / 500 Mo autorisés. Libérez de l'espace pour éviter les
   blocages."

### Scénario 5.1c-15 — Toast non re-déclenché à la 2ᵉ requête (idempotence)

1. Suite du scénario précédent — le toast a été affiché à l'arrivée.
2. Naviguer vers une autre page (ex: `/app/dashboard`) sans se déconnecter.
3. **Aucun nouveau toast** ne doit apparaître. Le pattern Login event garantit
   que le listener n'est appelé qu'une fois par session.

### Scénario 5.1c-16 — Pas de toast si snapshot null ou rien over

1. Avec un user `clean-test` sans `quota_snapshot` (ou avec un snapshot où
   `is_over_soft=false` partout).
2. Se connecter.
3. **Aucun toast** n'apparaît.
4. Logs Laravel : aucune entrée listener (silencieux).

### Scénario 5.1c-17 — Toast unique si 2 partitions over

1. Avec un user dont les 2 partitions sont en dépassement.
2. Se connecter.
3. Un SEUL toast apparaît avec titre "Plusieurs espaces de stockage sont
   dépassés." et description multi-lignes listant les 2 partitions
   (UX moins bruyante que 2 toasts séparés).

### Scénario 5.1c-18 — Listener n'empêche pas le login en cas d'erreur

1. Avec un user dont `quota_snapshot` est volontairement corrompu :
   `['home' => 'not-an-array', ...]`.
2. Se connecter.
3. Le login réussit (redirection vers dashboard normale).
4. Aucun toast.
5. Logs : `Log::warning('QuotaService: listener NotifyQuotaOverageOnLogin échoué', ...)`.
