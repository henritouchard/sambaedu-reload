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
   (`/home`, `/var/sambaedu`) : 2 inputs visibles (Soft Mo, Overage %).
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

---

## Story 5.1d — `default_itinerant`, `trash:purge`, seed legacy

**Date livraison** : 2026-04-27 par claude-opus-4-7.

**Migrations à appliquer** : aucune (champ `quota_rules.type` est déjà
`string(20)` qui accepte `default_itinerant`).

**Pré-requis** :
- Snapshot quotas opérationnel (5.1b).
- Onglet `/admin/settings → Quotas & FS` opérationnel (5.1c).
- Connexion `legacy_mysql` configurée dans `.env` (variables `LEGACY_DB_*`)
  uniquement pour le scénario 5.1d-7/8.

### Scénario 5.1d-1 — User externe sans règle reçoit `default_itinerant`

1. **Préparation** : sur la VM, dans tinker :
   ```php
   App\Models\QuotaRule::create([
       'type' => App\Models\QuotaRule::TYPE_DEFAULT_ITINERANT,
       'target' => null,
       'partition' => '/home',
       'quota_soft_mb' => 200,
       'quota_hard_mb' => 240,
       'is_active' => true,
   ]);
   App\Models\QuotaRule::create([
       'type' => App\Models\QuotaRule::TYPE_DEFAULT_ELEVE,
       'target' => null,
       'partition' => '/home',
       'quota_soft_mb' => 500,
       'quota_hard_mb' => 600,
       'is_active' => true,
   ]);
   $u = App\Models\User::where('login', 'alice')->first();
   $u->school_code = '0770001a'; // différent de l'établissement courant
   $u->save();
   ```
2. Aller sur `/app/users/alice` (ou équivalent fiche user).
3. **Attendu** : la section quota indique `Source : Défaut itinérants`,
   `Soft : 200 Mo`, `Hard : 240 Mo`.
4. Si on enlève la règle `TYPE_DEFAULT_ITINERANT` puis relance :
   `Source : Défaut élèves` (fallback profil silencieux).

### Scénario 5.1d-2 — User externe avec règle USER ignore `default_itinerant`

1. Suite du scénario précédent. Ajouter une règle USER explicite :
   ```php
   App\Models\QuotaRule::create([
       'type' => App\Models\QuotaRule::TYPE_USER,
       'target' => 'alice',
       'partition' => '/home',
       'quota_soft_mb' => 1500,
       'quota_hard_mb' => 1800,
       'is_active' => true,
   ]);
   ```
2. Recharger la fiche `/app/users/alice`.
3. **Attendu** : `Source : Utilisateur (alice)`, `Soft : 1500`, `Hard : 1800`.
   La règle USER prime sur `default_itinerant`.

### Scénario 5.1d-3 — `trash:purge --dry-run`

1. Sur la VM : `cd /var/www/sambaedu-reload`.
2. Configurer le TTL : `php artisan tinker --execute="App\Models\SystemSetting::set('quota.trash', ['ttl_days' => 30, 'purge_auto' => false]);"`.
3. Créer 2 dossiers de test :
   ```bash
   sudo mkdir -p /home/trash/{old-test,recent-test}
   sudo touch -d '60 days ago' /home/trash/old-test
   sudo touch -d '5 days ago' /home/trash/recent-test
   ```
4. `php artisan trash:purge --dry-run`.
5. **Attendu** : tableau listant `old-test (À PURGER)` et `recent-test
   (conservé)`, message `[DRY-RUN] Candidats à purger : 1. Conservés : 1.
   Aucune modification effectuée.`. Les 2 dossiers existent toujours.

### Scénario 5.1d-4 — `trash:purge` réel

1. Suite du scénario précédent.
2. `php artisan trash:purge`.
3. **Attendu** : `Purgé : 1 dossier(s). Conservé : 1 dossier(s). Erreurs : 0.`
4. `ls /home/trash/` → seul `recent-test` reste.
5. Vérifier l'audit :
   ```php
   App\Models\QuotaAuditLog::where('target_type', 'trash')->latest()->first();
   // → action='delete', target_name='old-test', performed_by='trash:purge'
   ```

### Scénario 5.1d-5 — Bouton "Purger maintenant" UI

1. Re-créer un dossier vieux : `sudo mkdir /home/trash/manual-test && sudo touch -d '60 days ago' /home/trash/manual-test`.
2. Aller sur `/admin/settings?tab=quotas-fs` (en tant que `server.admin`).
3. Section "Corbeille (/home/trash)" — cliquer sur **Purger maintenant**.
4. Confirmer la modale `wire:confirm`.
5. **Attendu** :
   - Toast vert "Corbeille purgée — 1 dossier(s) supprimé(s)."
   - `ls /home/trash/` ne contient plus `manual-test`.
   - `QuotaAuditLog::where('performed_by', 'ui:<login_admin>')->latest()->first();`
     trace l'action.

### Scénario 5.1d-6 — Planification automatique à 02h00

1. Activer le toggle dans `/admin/settings → Quotas & FS` :
   `Purge automatique (cron 02h00)` → on.
2. Vérifier l'event scheduler : `php artisan schedule:list | grep trash:purge`.
3. **Attendu** : entrée `0 2 * * * php artisan trash:purge` listée.
4. Désactiver le toggle. Re-vérifier `schedule:list`.
5. **Attendu** : la commande **est toujours listée** (planification fixe)
   mais elle ne s'exécutera pas (le filtre `->when()` retourne false). Pour
   tester sans attendre 24h, modifier temporairement le `dailyAt('02:00')`
   en `everyMinute()` et observer les logs.

### Scénario 5.1d-7 — `quota:seed-from-legacy --dry-run`

1. Configurer `.env` avec les variables `LEGACY_DB_*` pointant vers la
   base MySQL legacy de l'établissement.
2. `php artisan config:clear`.
3. `php artisan quota:seed-from-legacy --dry-run`.
4. **Attendu** : rapport texte
   ```
   [DRY-RUN] Seed quotas legacy → SambaEdu Reload
   ─────────────────────────────────────
   Source : legacy_mysql.quotas (N rows)
   Importées : X user / Y group
   ...
   Mode dry-run actif — aucune modification BDD effectuée.
   ```
5. Vérifier `QuotaRule::count()` → inchangé.
6. Vérifier `QuotaAuditLog::where('performed_by', 'quota:seed-from-legacy')->count()` → 0.

### Scénario 5.1d-8 — `quota:seed-from-legacy` run réel

1. Suite du scénario précédent. Si le dry-run est OK :
   `php artisan quota:seed-from-legacy`.
2. **Attendu** : rapport similaire sans `[DRY-RUN]`. Les règles sont créées.
3. `QuotaRule::where('type', 'user')->count()` → reflète les imports.
4. `QuotaAuditLog::where('performed_by', 'quota:seed-from-legacy')->count()`
   → > 0.
5. Lancer une 2e fois SANS `--force` :
   - Les règles existantes sont skipped (log info), 0 INSERT additionnel.
6. Lancer une 3e fois AVEC `--force` :
   - Les règles existantes sont mises à jour (audit `update`).

### Scénario 5.1d-9 — `quota:seed-from-legacy` connexion absente

1. Vider les variables `LEGACY_DB_*` dans `.env`.
2. `php artisan config:clear`.
3. `php artisan quota:seed-from-legacy`.
4. **Attendu** :
   - Exit code 1 (FAILURE).
   - Message stdout :
     `Connexion legacy non configurée — ajouter LEGACY_DB_HOST/DATABASE/USERNAME/PASSWORD dans .env`
   - Aucune modification BDD.
   - Log Laravel : `QuotaService: connexion legacy_mysql non configurée`.

### Scénario 5.1d-10 — Init defaults profils si absents

1. Sur une BDD vide de defaults :
   `QuotaRule::whereIn('type', ['default_eleve','default_prof','default_admin','default_itinerant'])->delete();`
2. Configurer `.env` avec une connexion `legacy_mysql` vide ou pointant
   sur une table `quotas` vide.
3. `php artisan quota:seed-from-legacy`.
4. **Attendu** : 8 règles defaults créées (4 profils × 2 partitions),
   avec les valeurs prévues (élève 500/600 — prof 1000/1200 — admin
   2000/2400 — itinérant 200/240). Aucune écrasement si l'admin a déjà
   personnalisé via l'onglet UI (sauf `--force`).

---

## Story 5.2 — Partages classe + ACLs POSIX

**Date livraison** : 2026-04-30
**Migrations à appliquer** : aucune (D1=A — FS source de vérité, table polyvalente
`quota_audit_logs` réutilisée avec `target_type='share'`).

**Pré-requis VM** :

- Sudoers (D7) : `/etc/sudoers.d/sambaedu` doit contenir
  ```
  www-data ALL=(root) NOPASSWD: /usr/bin/setfacl, /usr/bin/getfacl, /bin/mkdir, /bin/mv, /bin/chown, /bin/chgrp, /bin/rm
  ```
  Si manquant : escalader à Henri AVANT de jouer le runbook.
- `smb.conf` (D8) : la section globale `[classes]` doit pointer
  `/var/sambaedu/Classes` (vue VM 2026-04-30, aucune modif requise par 5.2).
  ```bash
  grep -A6 '^\[classes\]' /etc/samba/smb.conf
  ```
- Racine FS : `/var/sambaedu/Classes/` existe, owner `www-admin`, groupe
  `domain admins`. Sinon `sudo mkdir -p /var/sambaedu/Classes && sudo chown www-admin:'domain admins' /var/sambaedu/Classes`.
- Permissions seedées : `php artisan db:seed --class=PermissionSeeder`
  (idempotent — ajoute `share.manage` aux rôles `ShareAdmin`, `UserAdmin`,
  `SuperAdmin`).
- Cache Spatie : `php artisan permission:cache-reset` après seed.

### Scénario 5.2-1 — Création partage classe nominal (avec membres)

**Pré-requis** : un `UserGroup::create(['name'=>'TEST6A','type'=>'classe'])`
sans dossier FS associé, et 2 élèves rattachés (`alice`, `bob`).

1. Se connecter en `admin` (rôle ShareAdmin / UserAdmin / SuperAdmin —
   tous trois possèdent `share.manage`).
2. Naviguer vers `/app/users/groups/{id}` du groupe `TEST6A`.
3. **Attendu UI** : la section "Partage de classe" s'affiche entre
   `members-list` et `group-quota-section`, badge orange "Non créé",
   bouton "Créer le partage" actif.
4. Cliquer "Créer le partage". Toast success "Partage de classe créé /
   ACLs réappliquées."
5. **Attendu FS** :
   ```bash
   ls -la /var/sambaedu/Classes/Classe_TEST6A/
   # → racine + _travail/ + _profs/ + _echange/ + alice/ + bob/
   getfacl /var/sambaedu/Classes/Classe_TEST6A/
   # → user::rwx, group::---, group:equipe_test6a:r-x, group:Classe_test6a:r-x,
   #    group:domain admins:rwx, mask::rwx, other::---
   #    + default:* identiques (héritage)
   getfacl /var/sambaedu/Classes/Classe_TEST6A/_echange/
   # → group:Classe_test6a:rwx (D6=A activé par défaut)
   getfacl /var/sambaedu/Classes/Classe_TEST6A/alice/
   # → user:alice:rwx, group:equipe_test6a:rwx, group:domain admins:rwx
   ```
6. Audit BDD : `SELECT * FROM quota_audit_logs WHERE action='create_share'
   ORDER BY id DESC LIMIT 1` → 1 row `target_type='share'`,
   `target_name='TEST6A'`, `partition='/var/sambaedu'`, `new_values` JSON
   contient `members_count: 2`.

### Scénario 5.2-2 — Réapplication ACLs idempotente

**Pré-requis** : Scénario 5.2-1 OK. ACL altérée manuellement :
```bash
sudo setfacl -m group:nobody:rwx /var/sambaedu/Classes/Classe_TEST6A/
```

1. Sur la même page, cliquer "Réappliquer les ACLs".
2. **Attendu UI** : toast success identique.
3. `getfacl` ne montre plus `group:nobody:rwx` — set canonique restauré.
4. Aucune data dans `_travail/_profs/_echange/alice/bob` n'a été altérée.
5. Audit : 2e row `action='create_share'` (idempotent : 2 audits, pas de
   différence sémantique).

### Scénario 5.2-3 — Toggle dossier d'échange on/off

**Pré-requis** : Scénario 5.2-1 OK, `_echange` actif.

1. Sur la même page, badge `_echange` = "activé". Cliquer
   "Désactiver le dossier d'échange".
2. Toast "Dossier d'échange désactivé (data préservée mais invisible aux membres)."
3. `getfacl /var/sambaedu/Classes/Classe_TEST6A/_echange/` → `group:Classe_test6a:---`
   (et `default:group:Classe_test6a:---`).
4. Ré-activer via le bouton — retour `rwx`. Audit : 2 rows
   `action='toggle_echange'` (un par toggle).

### Scénario 5.2-4 — Changement de classe d'un élève (sync)

**Pré-requis** : Scénarios 5.2-1 OK pour `TEST6A` et `TEST5B` (créer une
2e classe). Élève `alice` rattaché à `TEST6A`.

1. Naviguer `/app/users/{alice_id}/edit`. Décocher la classe `TEST6A`,
   cocher `TEST5B`. Sauvegarder.
2. **Attendu hook Observer pivot (D5=A)** : Laravel detache la pivot
   `(alice, TEST6A)` puis attache `(alice, TEST5B)`. L'Observer
   `UserGroupUserPivotObserver` invoque
   `ShareService::syncUserClassMemberships(alice, [oldId], [newId])`.
3. **Attendu FS** :
   ```bash
   ls /var/sambaedu/Classes/Classe_TEST5B/alice/Archives/
   # → contient les anciens fichiers (D3=A : déplacement vers Archives/)
   ls /var/sambaedu/Classes/Classe_TEST5B/alice/
   # → nouveau dossier vide (sauf Archives/) + ACLs canoniques.
   ls /var/sambaedu/Classes/Classe_TEST6A/alice/
   # → SOIT inexistant SOIT vide (mv déjà appliqué).
   ```
4. Audit : row `action='sync_user'`, `target_name='alice'`,
   `new_values.added=[TEST5B_id]`, `new_values.removed=[TEST6A_id]`.

### Scénario 5.2-5 — Suppression d'une classe (archive `mv`)

**Pré-requis** : `Classe_TEST6A/` existe sur FS.

1. **D4=A** — pas d'UI dédiée pour la suppression. Tinker :
   ```bash
   php artisan tinker --execute='\App\Services\Filesystem\ShareService;
   $g = \App\Models\UserGroup::where("name","TEST6A")->where("type","classe")->first();
   app(\App\Services\Filesystem\ShareService::class)->archiveClassShare($g);'
   ```
2. **Attendu FS** :
   ```bash
   ls -la /var/sambaedu/Classes/ | grep TEST6A
   # → .Classe_TEST6A (préfixé d'un point, soft-archive — invisible aux
   #    clients SMB)
   ```
3. Audit : row `action='archive_share'` avec `from`/`to` dans `new_values`.
4. Restauration manuelle si besoin :
   `sudo mv /var/sambaedu/Classes/.Classe_TEST6A /var/sambaedu/Classes/Classe_TEST6A`.

### Scénario 5.2-6 — Bypass Gate `manage-share` via payload forgé → 403

**Pré-requis** : un user `viewer` avec `share.view` UNIQUEMENT (pas
`share.manage`).

1. Se connecter en `viewer`.
2. Naviguer `/app/users/groups/{TEST6A_id}`. La section "Partage de
   classe" est visible MAIS les boutons sont masqués (`@can('manage-share')`
   garde la zone d'action).
3. Forger un payload Livewire (browser devtools) `wire:click="createShare"`
   sur un bouton existant. Le serveur doit renvoyer **HTTP 403**
   (`Gate::authorize('manage-share', $group)` première ligne du handler).
4. Aucune modif FS (`getfacl` inchangé).

### Scénario 5.2-7 — Anti-injection path (UserGroup::name malicieux)

**Pré-requis** : un admin avec `share.manage`.

1. Tenter via tinker :
   ```bash
   php artisan tinker --execute='
   $g = \App\Models\UserGroup::create([
     "name" => "../etc",
     "type" => "classe",
   ]);
   echo app(\App\Services\Filesystem\ShareService::class)->createClassShare($g) ? "OK" : "REFUSÉ";
   ';
   ```
2. **Attendu** : `REFUSÉ` (false). Aucun setfacl/mkdir exécuté
   (`AclService::validatePath()` rejette via regex anti-traversal).
3. Log Laravel `AclService:` ou `ShareService:` : `path invalide` ou
   `createClassShare refusé (type ou nom invalide)`.
4. `ls /var/sambaedu/Classes/ | grep -E '\.\.|etc'` → 0 hit.

### Scénario 5.2-8 — Commande `shares:resync-class` smoke VM

1. **Dry-run global** (sans modif) :
   ```bash
   php artisan shares:resync-class --dry-run
   ```
   **Attendu** : tableau listant toutes les classes (id, name, path FS,
   nb membres). Exit 0. Aucune commande shell `setfacl` n'est
   journalisée.

2. **Run ciblé sur une classe** (test de régression non destructif) :
   ```bash
   php artisan shares:resync-class --class=TEST6A --performed-by=qa-runbook
   ```
   **Attendu** :
   - stdout : `[OK] TEST6A (id=…) re-synchronisée.` puis
     `Classes : 1 traitée(s). Re-synchronisées : 1. Échecs : 0.`
   - exit 0.
   - `quota_audit_logs` : 1 row `action='create_share'` (par
     ShareService) + 1 row `action='resync_class'` (consolidé par la
     commande, `performed_by='qa-runbook'`).

3. **Run global** (TOUTES les classes) — réservé aux fenêtres de
   maintenance, peut prendre plusieurs minutes :
   ```bash
   php artisan shares:resync-class --performed-by=admin-bulk
   ```
   **Attendu** : compteur final `Re-synchronisées: N` cohérent avec le
   nombre de UserGroup type=classe.

4. **Tentative injection** (refus immédiat) :
   ```bash
   php artisan shares:resync-class --class='Classe;rm -rf /'
   ```
   **Attendu** : exit 1 + message `--class invalide (regex /^[A-Za-z0-9_. -]+$/)`.

---

### Post-correctifs review 2026-04-30 (Story 5.2)

13 corrections review appliquées (cf. `_bmad-output/codeReviews/5-2.md` status `to-validate`). Les scénarios précédents restent valables et ne nécessitent pas d'ajustement, **sauf** :

#### Codes de retour `shares:resync-class` (review #2 / Q3)

La commande retourne désormais 3 codes distincts (mise à jour `--description` Artisan) :

| Code | Sens | Cron monitoring |
|------|------|------------------|
| `0`  | Au moins 1 classe re-synchronisée OU aucune classe à traiter | OK |
| `1`  | Au moins 1 classe en erreur (`failed > 0`) | Alerter |
| `2`  | Toutes les classes étaient verrouillées (`resynced=0 && failed=0 && locked>0`) | Alerter (race chronique ou lock orphelin) |

#### Scénario 5.2-9 — Anti symlink traversal (review #3)

**Pré-requis** : Scénario 5.2-1 OK pour `TEST6A` ; un dossier élève `TEST6A/alice/` créé.

**Étapes** :

1. Plante un symlink dans le dossier élève (sur la VM, en élève) :
   ```bash
   ln -s /etc /var/sambaedu/Classes/Classe_TEST6A/alice/evil
   ```
2. Lance un resync admin :
   ```bash
   php artisan shares:resync-class --class=TEST6A --performed-by=qa-9
   ```
3. Vérifie qu'aucune ACL n'a été posée dans `/etc/` :
   ```bash
   getfacl /etc/passwd /etc/shadow
   ```
   **Attendu** : aucune entrée `group:equipe_test6a` ou `group:Classe_test6a` dans la sortie. Le `setfacl -R -P` (option `-P` ajoutée en review #3) a refusé de suivre le symlink. Le legacy `partages.inc.php` faisait déjà ainsi (l. 372/492/498/506) — la review #3 a comblé une régression sécurité du dev initial.

#### Notes additionnelles

- **#11 archive `.Classe_X` déjà existante** : `archiveClassShare` log `Log::warning 'ShareService: archiveClassShare cible déjà existante, mv refusé'` avec `classe`/`from`/`target` dans le contexte, et retourne false. Aucune perte de data — l'admin doit supprimer l'archive précédente manuellement avant de re-archiver. Décalque legacy strict (Q2).
- **#15 noms de classe avec espaces** : non supportés (cohérence FS path / `validatePath`). Exemple : un UserGroup `name='Seconde B'` est refusé en `escapeAclClassName` → `createClassShare` retourne false. Cf. `docs/domains/filesystem.md`.
- **#13 cache UI 60s** : invalidé automatiquement après chaque mutation `ShareService` (createClassShare / toggleEchange / archiveClassShare / syncUserClassMemberships) via `Cache::forget('share-status:'.$id)`. L'UI Livewire voit donc un état frais après une opération Artisan d'un autre admin (sans attendre l'expiration TTL).
- **#1 hook UserService → ShareService** (Q1=Option B) : le changement de classe d'un élève via la fiche LDAP (`UserService::modifyUser` → `persistUserGroupsToSql`) déclenche désormais l'archivage `Classe_<old>/<eleve> → Classe_<new>/<eleve>/Archives/` en passant par un call EXPLICIT à `ShareService::syncUserClassMemberships($user, $oldClassIds, $newClassIds)` — l'Observer pivot est désactivé pendant le sync atomique pour éviter le doublon (events `created`/`deleted` séparés).

---

## Story 26.3 — Nettoyage natif des profils itinérants (2026-06-15)

Réimplémentation native du nettoyage legacy `ldap_cleaner.php?do=3` /
`clean_profiles('*')` : snapshot nocturne des tailles de `/home/profiles` +
détection/purge des profils orphelins. **Aucun routage vers le legacy**
(kill-switch `LEGACY_CONFIG_CHANNEL_ENABLED=false`).

**Pré-requis spécifiques** :

- VM avec `/home/profiles` peuplé (au moins un dossier `<login>.V<N>` pour un
  compte existant + un dossier orphelin sans compte).
- Compte `admin` (`server.admin`) pour l'onglet `/admin/settings` → Profils itinérants.
- Binaire `du` disponible (présent par défaut).

### Section — Scan nocturne + cache (invariant perf)

#### Scénario 26.3-1 — Snapshot manuel `profiles:snapshot`

**Étapes** :

1. Créer un compte test `qa263` et un dossier `/home/profiles/qa263.V6` (>200 Mo
   pour déclencher la pastille), plus un dossier orphelin `/home/profiles/ghost.V2`.
2. Lancer le job : `php artisan profiles:snapshot`.
3. **Attendu** : sortie `ProfilesSnapshot terminé — dossiers scannés : N | users mis à jour : M | profils orphelins : 1 | durée : …s`, exit 0.
4. Vérifier en BDD : `users.profile_snapshot` de `qa263` contient `size_mb` ≈ taille réelle ; `SystemSetting` clé `profiles.orphans` contient `ghost.V2`.

#### Scénario 26.3-2 — Fail-soft `/home/profiles` absent

**Étapes** :

1. Renommer temporairement `/home/profiles` (ou tester sur un env sans ce dossier).
2. Lancer `php artisan profiles:snapshot`.
3. **Attendu** : `Log::error [RoamingProfileService]` (racine absente), message d'erreur en sortie, exit 1 (FAILURE) **non fatal pour le scheduler**, et le snapshot précédent (colonne + `profiles.orphans`) **conservé** (pas effacé).

#### Scénario 26.3-3 — Planification nocturne 04h30

**Étapes** :

1. `php artisan schedule:list` (ou inspection `Console\Kernel`).
2. **Attendu** : `profiles:snapshot` planifié `daily at 04:30`, `withoutOverlapping`, `runInBackground`. Créneau libre (après `script-logs:archive:rotate` 04:00).

#### Scénario 26.3-4 — INVARIANT PERF : aucun `du`/scan au render

**Pré-requis** : snapshot 26.3-1 OK.

**Étapes** :

1. Ouvrir `/app/users` (tableau) puis l'onglet `/admin/settings` → Profils itinérants.
2. Surveiller en parallèle sur la VM : `pgrep -af du` ou `auditctl` sur `/home/profiles`.
3. **Attendu** : aucun processus `du`, aucun accès FS à `/home/profiles` déclenché par l'affichage. Les valeurs (pastille volumineux, compteur orphelins) viennent **uniquement** du cache (colonne + SystemSetting). Verrouillé par test `ProfilsItinerantsOrphanPurgeTest::it_never_shells_out_at_render_or_purge`.

### Section — Purge des orphelins (anti-désastre)

#### Scénario 26.3-5 — Purge déplace vers `_Trash_users`

**Pré-requis** : 26.3-1 OK avec `ghost.V2` orphelin.

**Étapes** :

1. Onglet Profils itinérants : le bandeau « 1 profil(s) itinérant(s) orphelin(s) » s'affiche.
2. Cliquer « Purger les profils orphelins » → confirmer.
3. **Attendu** : toast « 1 profil(s) orphelin(s) déplacé(s) vers la corbeille _Trash_users », le compteur passe à 0, le bandeau disparaît. `/home/profiles/ghost.V2` n'existe plus ; `/home/admin/_Trash_users/ghost.V2.<horodatage>` existe (réversible).

#### Scénario 26.3-6 — Re-vérification : compte recréé entre snapshot et purge

**Pré-requis** : cache `profiles.orphans` contient `ghost.V2`.

**Étapes** :

1. Créer un compte `ghost` (login matchant le dossier orphelin) APRÈS le snapshot.
2. Lancer la purge.
3. **Attendu** : `ghost.V2` **n'est pas supprimé** (re-vérification BDD au moment de l'action — le cache pouvait dater). `Log::info` « dossier ignoré (compte réapparu) ». Aucune perte de données. Verrouillé par test `RoamingProfileCleanupTest::it_never_purges_a_dir_whose_user_exists`.

#### Scénario 26.3-7 — Gardes anti path-traversal

**Étapes** (test, non destructif) :

1. Injecter en BDD une entrée `profiles.orphans` contenant `../../etc`, `foo/bar`, `evil;rm`.
2. Lancer la purge.
3. **Attendu** : aucune entrée déplacée (`moved=0`), toutes rejetées (`isValueSafe` + veto `..` + refus `/` + realpath confiné sous `/home/profiles`). `Log::warning` par entrée. Verrouillé par `RoamingProfileCleanupTest::it_skips_purge_for_path_traversal_and_slash_names`.

#### Scénario 26.3-8 — Gating `server.admin` du bouton purge

**Étapes** :

1. Se connecter avec un compte **sans** `server.admin`.
2. Tenter d'atteindre `purgeOrphans` (payload Livewire forgé).
3. **Attendu** : `403`. Double gate (`mount()` + méthode). Verrouillé par `ProfilsItinerantsOrphanPurgeTest::it_blocks_purge_without_server_admin_even_on_forged_payload`.

### Post-correctifs review 2026-06-15 (Story 26.3)

| Incident | Scénario de non-régression |
|----------|----------------------------|
| #1 `du` exit-code ≠ 0 (sous-dossier illisible) jetait tout le snapshot | 26.3-9 |
| S1 `rename` cross-device (`/home/profiles` mount dédié en prod) | 26.3-10 (vérif infra) |

#### Scénario 26.3-9 — `du` en succès PARTIEL : le snapshot reste exploitable

**Contexte** : sur un `/home/profiles` réel, des profils peuvent être illisibles (ACL, session ouverte pendant le scan). `du` sort alors en code ≠ 0 tout en imprimant des tailles valides. Le snapshot ne doit PAS être abandonné.

1. Sur la VM, rendre un sous-dossier de `/home/profiles` illisible : `chmod 000 /home/profiles/<un_profil>`.
2. Lancer `php artisan profiles:snapshot`.
3. **Attendu** : exit `SUCCESS`, les autres profils sont bien persistés (colonne `users.profile_snapshot` + `SystemSetting profiles.orphans` peuplés), un log `warning` « du en code non-zéro » mentionne `parsed_dirs > 0`. La pastille et le compteur ne sont PAS vides.
4. Restaurer : `chmod 700 /home/profiles/<un_profil>`.
5. **Échec total** (sortie vide / `/home/profiles` illisible en entier) → exit non-fatal, log `error`, snapshot PRÉCÉDENT conservé (fail-soft).

#### Scénario 26.3-10 — Vérif infra : corbeille sur le même filesystem que `/home/profiles`

**Contexte** : la purge déplace via `rename()`, qui échoue (`EXDEV`) si `/home/profiles` et `/home/admin/_Trash_users` sont sur des mounts distincts.

1. `stat -c '%d %n' /home/profiles /home/admin` → les deux doivent afficher **le même device id**.
2. Si devices différents (volume profils dédié) : la purge échouera systématiquement (compteur `errors`). Prévoir un fallback `mv`/copy+unlink (cf. story Notes post-review S1) AVANT d'activer la purge en prod.

### Checklist rapide — Story 26.3

- [ ] Scénario 26.3-1 : snapshot manuel peuple colonne + orphans
- [ ] Scénario 26.3-2 : fail-soft FS absent (snapshot conservé)
- [ ] Scénario 26.3-3 : planification 04h30
- [ ] Scénario 26.3-4 : INVARIANT aucun `du` au render
- [ ] Scénario 26.3-5 : purge → `_Trash_users` (réversible)
- [ ] Scénario 26.3-6 : compte recréé jamais purgé (re-vérif)
- [ ] Scénario 26.3-7 : gardes path-traversal
- [ ] Scénario 26.3-8 : gating server.admin
- [ ] Scénario 26.3-9 : `du` succès partiel → snapshot exploitable (review #1)
- [ ] Scénario 26.3-10 : vérif infra corbeille même device (review S1)

---

*Dernière mise à jour : 2026-06-15 (Story 26.3 — nettoyage natif profils itinérants ; post-correctifs review #1/S1)*
