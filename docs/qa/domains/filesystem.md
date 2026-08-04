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
détection/purge des profils orphelins. **Aucun routage vers le legacy** (le kill-switch
`LEGACY_CONFIG_CHANNEL_ENABLED` a été RETIRÉ en story 38.2 — les routes client legacy
répondent désormais en tombstones natifs inertes).

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
| S1 `rename` cross-device (`/home/profiles` mount dédié en prod) → fallback `mv` | 26.3-10 |

#### Scénario 26.3-9 — `du` en succès PARTIEL : le snapshot reste exploitable

**Contexte** : sur un `/home/profiles` réel, des profils peuvent être illisibles (ACL, session ouverte pendant le scan). `du` sort alors en code ≠ 0 tout en imprimant des tailles valides. Le snapshot ne doit PAS être abandonné.

1. Sur la VM, rendre un sous-dossier de `/home/profiles` illisible : `chmod 000 /home/profiles/<un_profil>`.
2. Lancer `php artisan profiles:snapshot`.
3. **Attendu** : exit `SUCCESS`, les autres profils sont bien persistés (colonne `users.profile_snapshot` + `SystemSetting profiles.orphans` peuplés), un log `warning` « du en code non-zéro » mentionne `parsed_dirs > 0`. La pastille et le compteur ne sont PAS vides.
4. Restaurer : `chmod 700 /home/profiles/<un_profil>`.
5. **Échec total** (sortie vide / `/home/profiles` illisible en entier) → exit non-fatal, log `error`, snapshot PRÉCÉDENT conservé (fail-soft).

#### Scénario 26.3-10 — Purge robuste cross-device (`/home/profiles` sur volume dédié)

**Contexte** : la purge déplace via `rename()`, qui échoue (`EXDEV`) si `/home/profiles` et `/home/admin/_Trash_users` sont sur des mounts distincts. Le code bascule alors sur `mv -f` (copie+suppression, gère le cross-device). À valider sur un serveur où `/home/profiles` est un volume dédié.

1. `stat -c '%d %n' /home/profiles /home/admin` — noter si les device id diffèrent (volume profils dédié = cas intéressant).
2. Créer un dossier orphelin de test sous `/home/profiles`, le faire détecter (`profiles:snapshot`), puis lancer la purge depuis l'UI admin.
3. **Attendu** : le dossier est bien déplacé dans `/home/admin/_Trash_users/` (compteur `moved`, **pas** `errors`), même si les deux chemins sont sur des disques différents. Vérifier que la source a disparu de `/home/profiles`.
4. Sur le **même** device (cas VM), le déplacement passe par `rename()` direct — résultat identique.

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
- [ ] Scénario 26.3-10 : purge robuste cross-device (fallback `mv`, review S1)

---

## Story 34.1 — Lecteurs réseau gérés (fondations backend)

**Date livraison** : 2026-06-30 par Opus 4.8.

**Migrations à appliquer** :
`2026_06_29_120000_create_network_shares_table`,
`2026_06_29_120100_create_network_share_assignables_table`.

**Cadrage** : FONDATION BACKEND (décision Henri — PAS d'UI, PAS de templates).
La création/assignation se fait par `php artisan tinker` (ou factory). L'UI =
Story 34.2.

**Pré-requis VM** :

- Sudoers (déjà couvert 5.2) : `/etc/sudoers.d/sambaedu` whiteliste
  `setfacl/getfacl/mkdir/mv/chown/chgrp` par binaire (path-agnostique) — aucune
  nouvelle entrée pour `Partages`. Si manquant : escalader à Henri.
- **`smb.conf`** : déclarer un partage `[partages]` →
  `path = /var/sambaedu/Partages`, utilisateurs authentifiés, traversable
  (l'accès réel est gaté par l'ACL POSIX de chaque sous-dossier). Infra serveur,
  hors git — iso `[users]`/`[classes]`.
  ```bash
  grep -A6 '^\[partages\]' /etc/samba/smb.conf
  ```
- Racine FS : le service crée `/var/sambaedu/Partages` au 1er `provision()`
  (`mkdir -p`, `chown www-admin:'domain admins'`). Vérifier après le 1er scénario.

### Scénario 34.1-1 — Migrations + schéma

1. `php artisan migrate:status | grep network_share` → les 2 migrations sont
   listées. `php artisan migrate` si `Pending`.
2. Vérifier en BDD : tables `network_shares` (colonnes `name`, `directory_name`
   UNIQUE, `label`, `letter`, `created_by_user_id`) et `network_share_assignables`
   (`network_share_id`, `assignable_id`, `assignable_type`, `access`, contrainte
   `network_share_assignable_unique`).

### Scénario 34.1-2 — Provisioning nominal + ACLs RO/RW (assignations user)

1. Tinker :
   ```php
   $s = App\Models\NetworkShare::create(['name'=>'Échanges direction','directory_name'=>'direction','letter'=>'P:']);
   $alice = App\Models\User::where('login','alice')->first();
   $bob   = App\Models\User::where('login','bob')->first();
   $s->users()->attach($alice->id, ['access'=>'rw']);
   $s->users()->attach($bob->id,   ['access'=>'ro']);
   app(App\Services\Filesystem\NetworkShareService::class)->provision($s, 'qa-runbook');
   ```
2. **Attendu FS** :
   ```bash
   ls -la /var/sambaedu/Partages/direction        # dossier créé, owner www-admin, grp 'domain admins'
   getfacl /var/sambaedu/Partages/direction
   # → user:alice:rwx, user:bob:r-x, group:domain admins:rwx, mask::rwx, other::---
   #   + default:* miroir (héritage)
   ```
3. Audit BDD : `SELECT * FROM quota_audit_logs WHERE target_type='share' AND target_name='direction' ORDER BY id DESC LIMIT 1`
   → `action='provision_share'`, `partition='/var/sambaedu'`, `performed_by='qa-runbook'`.

### Scénario 34.1-3 — Assignation classe/équipe → groupe Unix dérivé

1. Tinker (groupe classe `3emeA`) :
   ```php
   $g = App\Models\UserGroup::where('name','3emeA')->where('type','classe')->first();
   $s = App\Models\NetworkShare::create(['name'=>'Travaux 3A','directory_name'=>'travaux3a','letter'=>'Q:']);
   $s->userGroups()->attach($g->id, ['access'=>'rw']);
   app(App\Services\Filesystem\NetworkShareService::class)->provision($s);
   ```
2. **Attendu** : `getfacl /var/sambaedu/Partages/travaux3a` montre
   `group:classe_3emea<suffixe-etab>:rwx` (mapping `classe_<localPart>`,
   suffixe établissement fédéré inclus si AD central). Un groupe `type='equipe'`
   donnerait `group:equipe_<localPart>:…`.

### Scénario 34.1-4 — WorkstationGroup = MONTAGE-SEUL (aucune ACL)

1. Tinker :
   ```php
   $wg = App\Models\WorkstationGroup::where('is_physical',false)->first();
   $s = App\Models\NetworkShare::create(['name'=>'Parc info','directory_name'=>'parcinfo','letter'=>'R:']);
   $s->workstationGroups()->attach($wg->id, ['access'=>'rw']);
   app(App\Services\Filesystem\NetworkShareService::class)->provision($s);
   ```
2. **Attendu** : `getfacl /var/sambaedu/Partages/parcinfo` ne contient
   **AUCUNE** ligne `user:`/`group:` spécifique au-delà du set canonique
   (`user::`, `group::`, `group:domain admins`, `mask`, `other`). La visibilité
   de la lettre par parc est gérée par l'agent (projection), pas par le FS.

### Scénario 34.1-5 — Idempotence

1. Altérer une ACL manuellement :
   `sudo setfacl -m group:nobody:rwx /var/sambaedu/Partages/direction`.
2. Re-jouer `provision($s)` (même share que 34.1-2).
3. **Attendu** : `getfacl` ne montre plus `group:nobody:rwx` (wipe `setfacl -b`
   puis batch). Aucune donnée dans le dossier altérée. 2 lignes d'audit
   `provision_share` (idempotent).

### Scénario 34.1-6 — Anti-injection `directory_name`

1. Tinker :
   ```php
   $s = new App\Models\NetworkShare(['name'=>'x','directory_name'=>'../etc']);
   echo app(App\Services\Filesystem\NetworkShareService::class)->provision($s) ? 'OK' : 'REFUSÉ';
   ```
2. **Attendu** : `REFUSÉ` (false). Aucun `mkdir`/`setfacl` exécuté (garde de path
   durcie `validateSharePath` : regex anti-traversal + profondeur ≤ 2). Idem
   pour `'a/b'`, `'foo bar'`, `'evil;rm'`, `'.hidden'`.

### Scénario 34.1-7 — Projection agent : la lettre apparaît dans le desired-state

**Pré-requis** : 34.1-2 OK (share `direction` `P:` assigné à `alice` rw).

1. Tinker — compiler l'état pour (poste, alice) :
   ```php
   $ws = App\Models\Workstation::first();
   $alice = App\Models\User::where('login','alice')->first();
   $ctx = App\Services\Agent\TargetContext::for($ws, $alice);
   (new App\Services\Agent\Providers\DrivesStateProvider())->itemsFor($ctx)
       ->map(fn($c)=>$c->payload)->all();
   ```
2. **Attendu** : le jeu fixe `K:`/`H:` PLUS un item
   `{letter:'P:', unc:'\\<se4fs>\partages\direction\', label:'Échanges direction'}`.
   Le payload ne contient PAS de champ `access`.
3. **Lettre auto-assignée** : créer un share sans `letter` assigné à `alice` →
   ré-exécuter → il reçoit `M:` (1re lettre libre du pool `M..Z`, déterministe).
4. **Machine-only** : `TargetContext::for($ws, null)` → AUCUN lecteur (montage =
   session user, même pour les shares assignés à un WG du poste).

### Scénario 34.1-8 — Non-régression golden (zéro ligne ⇒ sortie figée)

1. Sur une BDD **sans** `network_shares` : la sortie de `DrivesStateProvider`
   est byte-identique au jeu fixe K:/H: (golden `state.v1.json` +
   `FROZEN_STATE_HASH` PHP/Go INCHANGÉS). Verrouillé par
   `DrivesStateProviderTest::zero_network_shares_yields_byte_identical_fixed_output`
   et `ContractV1Test` (HÔTE php8.4+sqlite). Aucun bump de version agent
   (`agent/**` intouché).

### Checklist rapide — Story 34.1

- [ ] 34.1-1 : migrations + schéma (tables + unique)
- [ ] 34.1-2 : provisioning nominal + ACL rwx/r-x (user)
- [ ] 34.1-3 : mapping groupe Unix classe/équipe (+ suffixe étab)
- [ ] 34.1-4 : WG = montage-seul (aucune ACL)
- [ ] 34.1-5 : idempotence (wipe + batch)
- [ ] 34.1-6 : anti-injection `directory_name`
- [ ] 34.1-7 : projection agent (lettre P: + auto M.., machine-only vide)
- [ ] 34.1-8 : non-régression golden (zéro ligne = sortie figée)

### Post-correctifs review 2026-06-30 (Story 34.1)

| Incident | Scénario de non-régression |
|---|---|
| #1 lettre **explicite réservée** (`letter='K:'`) sur un `network_share` → 2ᵉ candidat K: collisionnant le home fixe | 34.1-9 |
| #3 + bug révélé : >14 répertoires auto (pool `M..Z` épuisé) → `Undefined array key` faisait **planter** toute la compilation `drives` (le « fail-soft » n'était jamais atteint) | 34.1-10 |

#### Scénario 34.1-9 — Lettre explicite réservée ne casse pas le home

1. `tinker` : `$s = NetworkShare::factory()->create(['directory_name'=>'pirate','letter'=>'K:']); $s->assignments()->create(['assignable_type'=>App\Models\User::class,'assignable_id'=>$u->id,'access'=>'rw']);`
2. Compiler le desired-state du user `$u` (cf. 34.1-7).
3. **Attendu** : `K:` pointe TOUJOURS `\\<se4fs>\users\<user>\` (le home) ; le répertoire `pirate` apparaît sur une lettre **auto-assignée** du pool (`M:`…), jamais sur K:. Un `warning` `agent.drives.reserved_letter_ignored` est tracé dans le canal `agent`. Une seule entrée K: dans la sortie.

#### Scénario 34.1-10 — Pool de lettres épuisé : omission propre (pas de crash)

1. Créer **15** `network_shares` à `letter=null` tous assignés au même user.
2. Compiler le desired-state.
3. **Attendu** : aucune exception ; **14** lecteurs émis (pool `M..Z` complet) + K:/H:, le 15ᵉ répertoire **omis** proprement avec un `warning` `agent.drives.letter_pool_exhausted`. (Avant correctif : `ErrorException: Undefined array key` cassait toute la projection `drives` du user.)

- [ ] 34.1-9 : lettre explicite réservée → bascule auto, home intact
- [ ] 34.1-10 : pool épuisé → 14 émis + omission tracée, zéro crash

---

## Story 34.2 — UI admin (refnum) des lecteurs réseau gérés

Couche UI Livewire + **validation prédictive** PAR-DESSUS la fondation 34.1
(inchangée). Surfaces : page liste `/app/shares`, modale de création, page détail
`/app/shares/{id}` (édition + assignation par maille pivot SQL + suppression).
Gardée par la policy **dédiée** `NetworkSharePolicy` (permissions
`networkshare.view` / `networkshare.manage`, Q5).

> **Pré-requis VM** : les permissions `networkshare.*` sont nouvelles — exécuter
> un (re)seed `php artisan db:seed --class=PermissionSeeder` sur la VM pour les
> matérialiser et les rattacher aux rôles `referent-numerique` / `share-admin` /
> `user-admin` / `super-admin`. Tant que ce seed n'est pas joué, l'accès à
> `/app/shares` renvoie 403 même pour un refnum.

### Modèle d'accès (rappel 34.1, surfacé en UI 34.2)

- **Visibilité (montage)** : N'IMPORTE QUELLE maille (User / UserGroup /
  WorkstationGroup) fait apparaître la lettre.
- **ACL POSIX (RO/RW réel)** : SEULES les assignations `User`/`UserGroup`
  contribuent une ACL. Une assignation `WorkstationGroup` est **montage-seul**
  (la lettre est visible mais aucun accès réel) → la validation prédictive
  l'AVERTIT.

### Validation prédictive (`NetworkShareValidator`, pure lecture, calquée 30.5)

1. **WG-montage-seul** → *warning non bloquant* : répertoire assigné UNIQUEMENT à
   des parcs, sans grant user/groupe.
2. **Collision de lettre** → *erreur bloquante* : deux répertoires DISTINCTS à
   lettre EXPLICITE identique pour une audience qui se recouvre (≥ 1 cible
   commune). Refus AVANT écriture.
3. **Lettre réservée** (`A-D`, `H`, `I`, `K`, `L`) → *erreur* à la saisie
   (consomme `DrivesStateProvider::RESERVED_LETTERS`, foyer canonique unique).

### Dette connue (v1 — à documenter au QA)

- **Pickers user/groupe/parc NON scopés par établissement (Q3).** Faute de scope
  établissement SQL homogène (la résolution établissement vit côté LDAP,
  délibérément bannie du chemin SQL en 34.1), les listes de cibles proposées
  (`User`/`UserGroup`/`WorkstationGroup`) ne sont PAS filtrées au périmètre du
  refnum. L'accès à la feature reste gardé par la policy `networkshare.*` ; seul
  le périmètre des cibles assignables n'est pas restreint. **Déploiements
  mono-établissement non impactés.** Un scope par établissement est un sujet
  ultérieur (nécessiterait une colonne établissement SQL sur `User`/`UserGroup`).
- **Suppression FS non gérée (34.x).** Supprimer un répertoire en UI retire les
  lignes SQL + ACL (cascade pivot) mais NE supprime PAS le dossier sous
  `/var/sambaedu/Partages` (archivage deux-temps = story ultérieure).
- **Lettre auto encore instable par set (M2 / 34.x).** L'UI ENCOURAGE une lettre
  explicite (pré-remplissage de la prochaine lettre sûre libre) ; un champ laissé
  vide retombe sur l'auto-assignation provider (stable par set, pas globalement).

### Scénarios manuels

#### Scénario 34.2-1 — Accès gardé par la policy dédiée

1. Se connecter avec un `referent-numerique` (après seed des permissions).
2. **Attendu** : `/app/shares` accessible (liste). Avec un compte `prof`/`eleve`
   → **403**. Le bouton « Nouveau répertoire » n'apparaît que sous
   `manage-networkshare`.

#### Scénario 34.2-2 — Création + provisioning synchrone

1. « Nouveau répertoire » → la lettre est pré-remplie (prochaine libre `M:`…).
2. Saisir `name`, `directory_name` valide, laisser ou changer la lettre, créer.
3. **Attendu** : ligne `network_shares` créée (`created_by_user_id` = refnum),
   `NetworkShareService::provision()` appelé (toast succès/échec), retour à la
   liste.

#### Scénario 34.2-3 — Format `directory_name` validé au formulaire (finding M4)

1. Saisir `directory_name = "salle info/.."` (ou avec espace).
2. **Attendu** : erreur de validation au formulaire (regex miroir de
   `NetworkShareService::isValidDirectoryName`) — **rien n'est persisté**.
3. Un `directory_name` déjà pris → erreur d'unicité.

#### Scénario 34.2-4 — Lettre réservée refusée à la saisie (finding #4)

1. Saisir `letter = "K:"` (ou H/I/L/A-D).
2. **Attendu** : erreur « lettre réservée par le système » au formulaire — pas
   de création.

#### Scénario 34.2-5 — Assignation par maille + RO/RW + re-provision

1. Sur `/app/shares/{id}`, ajouter un `User` en `rw`, un `UserGroup` en `ro`.
2. **Attendu** : lignes pivot `network_share_assignables` créées (`access`
   correct), re-provisioning déclenché (ACL recalculées : `setfacl -b` + batch).
   Un même couple (cible) ré-ajouté met à jour l'`access` (pas de doublon —
   contrainte d'unicité du pivot).

#### Scénario 34.2-6 — WG-montage-seul = warning (piège #1 / finding M5)

1. Assigner UNIQUEMENT un `WorkstationGroup` (aucun user/groupe).
2. **Attendu** : *warning non bloquant* (« assignation parc = visibilité seule ;
   l'accès réel exige un grant utilisateur/groupe ») affiché en bandeau + toast.
   Le répertoire reste créable/assignable (pas un refus).

#### Scénario 34.2-7 — Collision de lettre = refus (piège #3 34.1 / finding M1)

1. Répertoire A : `letter = P:` assigné à l'utilisateur `u`.
2. Répertoire B : assigné aussi à `u` ; tenter de fixer `letter = P:`.
3. **Attendu** : **refus** (`toastError` nommant les deux répertoires et la
   lettre) AVANT écriture — la lettre de B n'est PAS enregistrée. Audiences
   disjointes ou lettres distinctes → PAS de refus.

#### Scénario 34.2-8 — Suppression (cascade pivot, FS conservé)

1. Supprimer un répertoire (confirmation `wire:confirm`).
2. **Attendu** : `network_shares` + pivot supprimés (cascade), redirection vers
   la liste avec toast. Le dossier `/var/sambaedu/Partages/<dir>` est **conservé**
   (suppression FS = 34.x).

#### Scénario 34.2-9 — Non-régression contrat agent (golden figé)

1. Exécuter `--filter ContractV1`, `--filter DrivesStateProvider`,
   `--filter Agent` (HÔTE php8.4+sqlite).
2. **Attendu** : golden `state.v1.json` + `FROZEN_STATE_HASH` (PHP + Go)
   **INCHANGÉS** ; aucune modif du payload `drives` ; `agent/**` intouché (pas de
   bump version agent). Seule modif provider = `RESERVED_LETTERS` `private`→`public`.

### Checklist rapide — Story 34.2

- [ ] 34.2-1 : accès gardé policy dédiée (`networkshare.*`), 403 prof/élève
- [ ] 34.2-2 : création + provisioning synchrone + toast
- [ ] 34.2-3 : format `directory_name` validé + unicité (M4)
- [ ] 34.2-4 : lettre réservée refusée à la saisie (#4)
- [ ] 34.2-5 : assignation par maille RO/RW + re-provision + upsert
- [ ] 34.2-6 : WG-montage-seul = warning non bloquant (M5)
- [ ] 34.2-7 : collision de lettre = refus bloquant (M1)
- [ ] 34.2-8 : suppression cascade pivot, FS conservé
- [ ] 34.2-9 : non-régression golden/agent figé
- [ ] **Pré-déploiement VM** : `db:seed --class=PermissionSeeder` joué (permissions `networkshare.*`)

### Post-correctifs review 2026-06-30 (Story 34.2)

| Incident | Scénario de non-régression |
|---|---|
| #1 collision de lettre non détectée sur le vecteur **ajout d'assignation** (`addAssignment` ne validait pas) → 2 montages même lettre | 34.2-10 |
| #5 index `mount()` sans `abort_unless` (asymétrie vs détail) | couvert par test `user_without_view_permission_is_forbidden_on_index` |

**Limitation connue (M-A) — collision CROSS-MAILLE non détectée (best-effort).** La validation prédictive ne signale PAS une collision quand un même utilisateur est atteint par DEUX mailles différentes (assigné en direct à un répertoire `P:` ET via son groupe/un parc à un autre répertoire `P:`). Le détecter exigerait d'expandre l'appartenance (à rebours du « pure lecture, zéro re-requête » du provider) ; le cas parc est de toute façon imprédictible. **Reco : limitation assumée, fermeture en 34.x avec la lettre stable.** En QA manuel : si deux répertoires partagent une lettre explicite et que leurs audiences se recouvrent par appartenance, vérifier manuellement (le filet automatique ne couvre que le recouvrement littéral / même maille).

#### Scénario 34.2-10 — Collision de lettre bloquée à l'ajout d'assignation (+ rollback)

1. Créer le répertoire A (`directory_name=direction`, lettre `P:`), lui assigner l'utilisateur `dave`.
2. Créer le répertoire B (`directory_name=projets`, lettre `P:`) **sans audience**.
3. Sur la fiche de B, assigner l'utilisateur `dave` (même maille User).
4. **Attendu** : toast d'erreur « collision de lettre P: » ; l'assignation de `dave` à B **n'est PAS persistée** (rollback transactionnel) ; aucun re-provisioning de B.

- [ ] 34.2-10 : collision bloquée à l'ajout d'assignation + rollback

---

## Story 34.3 — Templates de répertoire (préfabrication d'échanges)

Couche de **préfabrication** par-dessus 34.1/34.2 (socle figé INTOUCHÉ). Une
seconde modale « Créer depuis un template » sur `/app/shares` matérialise en un
geste un `NetworkShare` + toutes ses assignations par maille, à partir d'une
RECETTE choisie. Les recettes vivent dans la table `directory_templates`, peuplée
par `DirectoryTemplateSeeder` ; `DirectoryTemplateService::materialize` lit la
recette en DB et délègue au socle (`provision()`, `NetworkShareValidator`).

> **Pré-requis VM (NOUVEAU 34.3)** : exécuter
> `php artisan db:seed --class=DirectoryTemplateSeeder` sur la VM pour peupler les
> **4 recettes**. Idempotent (re-seed sans doublon, `updateOrCreate` sur `key`).
> Tant que ce seed n'est pas joué, le sélecteur de template est vide.

### Périmètre des 4 templates (arbitrage Henri 2026-06-30)

| Template (`key`) | Qui dépose (RW) | Qui lit (RO) | Mailles |
|---|---|---|---|
| `direction_to_all` | source (groupe direction/équipe) | destinataires (groupes) | 1 `UserGroup` + N `UserGroup` |
| `profs_to_eleves` | équipe enseignante | classe | 1 `UserGroup` type `equipe` + 1 type `classe` |
| `user_to_user` | les 2 utilisateurs | — | 2 × `User` (RW/RW) |
| `group_space` | le groupe | — | 1 × `UserGroup` (RW) |

> **HORS 34.3 — casiers « élèves → profs » / rendus par-élève (REPORTÉ 34.x).** Le
> socle pose l'ACL au RÉPERTOIRE racine (pas de sous-dossier ACLé par élève) ; un
> template « rendus » donnerait un dépôt partagé où chaque élève voit/écrase les
> rendus des autres — faux sens métier dangereux. Le template `élèves → profs`
> N'EST PAS livré. Les vrais casiers (sous-dossier + ACL par élève = extension
> `NetworkShareService`) sont reportés à 34.x.

### Invariants (rappel, vérifiés en test)

- **WG = montage-seul** : AUCUNE recette ne porte de maille `WorkstationGroup`
  (l'ACL ne porte que sur `User`/`UserGroup`). Le service refuse toute maille hors
  `User`/`UserGroup` (defense-in-depth).
- **Mapping de maille NON redérivé** (piège #3) : le template ASSIGNE le bon
  `UserGroup` typé ; `NetworkShareService::unixGroupFor` mappe au groupe Unix
  (`classe_`/`equipe_` + suffixe étab) à `provision()`. Le template ne calcule
  aucun nom de groupe Unix.
- **Dette 34.2 non aggravée** : pickers `User`/`UserGroup` NON scopés par
  établissement ; collision cross-maille (M-A) toujours best-effort.

### Scénarios manuels

#### Scénario 34.3-1 — Matérialisation `profs_to_eleves`

1. `/app/shares` → « Créer depuis un template » → choisir « Profs → élèves ».
2. Le formulaire expose DYNAMIQUEMENT deux pickers : équipe (`equipe`) + classe
   (`classe`). Saisir `name`, `directory_name` (MANUEL, validé format/unique),
   `letter` (pré-remplie prochaine libre).
3. Sélectionner une équipe + une classe → l'**aperçu** liste
   `équipe → Groupe → Lecture/écriture` et `classe → Groupe → Lecture seule`.
4. Matérialiser → toast succès + redirection vers la fiche du répertoire créé ;
   en base : 1 `NetworkShare` + 2 `network_share_assignables` (equipe `rw`,
   classe `ro`) ; provisioning FS/ACL déclenché.

#### Scénario 34.3-2 — `user_to_user` (deux RW)

1. Choisir « Utilisateur ↔ utilisateur », sélectionner deux utilisateurs.
2. Aperçu = deux lignes Lecture/écriture → matérialiser → 2 assignations `User`
   `rw`. (Sélectionner deux fois le même utilisateur → refus « cible deux fois ».)

#### Scénario 34.3-3 — Collision de lettre = rollback total

1. Pré-existant : répertoire `P:` assigné au groupe G.
2. Matérialiser un template (groupe G, lettre `P:`) → **toast d'erreur** collision ;
   **aucune** ligne `network_shares`/pivot créée (rollback transactionnel) ;
   aucun provisioning.

#### Scénario 34.3-4 — `directory_name` déjà pris

1. Matérialiser avec un `directory_name` déjà utilisé → erreur de champ « déjà
   utilisé. Éditez le répertoire existant depuis sa page » (one-shot, Q4 — pas de
   sync template↔share en 34.3).

#### Scénario 34.3-5 — Lettre réservée + format invalide

1. `letter` ∈ K/H/I/L/A-D → refus à la saisie. `directory_name` avec espace/`..`/`/`
   → refus format. Dans les deux cas : **aucune écriture**.

#### Scénario 34.3-6 — Gating refnum

1. Un compte sans `networkshare.manage` (viewer) ne voit pas le bouton ; toute
   tentative d'appel `createFromTemplate` / `openTemplate` → 403.

### Checklist rapide — Story 34.3

- [ ] 34.3-1 : matérialisation des 4 templates (assignations + access corrects)
- [ ] 34.3-2 : `user_to_user` deux RW ; doublon de cible refusé
- [ ] 34.3-3 : collision de lettre = rollback total (0 ligne) + toast erreur
- [ ] 34.3-4 : `directory_name` déjà pris = message « éditez-le depuis sa page »
- [ ] 34.3-5 : lettre réservée + format invalide refusés AVANT écriture
- [ ] 34.3-6 : gating `manage-networkshare` (viewer 403, bouton masqué)
- [ ] 34.3-7 : invariant WG-montage-seul (aucune recette ne grant un parc)
- [ ] **Élèves→profs / casiers par-élève = HORS 34.3, reporté 34.x** (vérifier que le template n'apparaît PAS dans le sélecteur)
- [ ] 34.3-8 : non-régression golden/agent figé (ContractV1 vert, `state.v1.json` + `FROZEN_STATE_HASH` inchangés)
- [ ] **Pré-déploiement VM** : `db:seed --class=DirectoryTemplateSeeder` joué (4 recettes)

### Post-correctifs review 2026-06-30 (Story 34.3)

| Incident couvert (corrigé post-review) | Scénario |
|---|---|
| #1 warnings prédictifs (WG-montage-seul) non surfacés après matérialisation (AC2 non honoré, inerte pour les 4 recettes mais contrat non tenu) → flash enrichi | 34.3-1, 34.3-7 (surfaçage warning) |
| #2 cardinalité des rôles validée côté service seulement (cible requise manquante → toast générique, pas d'erreur de champ) ; trou de couverture | 34.3-9 |
| M-1 unicité `directory_name` non pré-checkée dans le service (appel direct → `QueryException` au lieu d'`InvalidArgumentException`) → pré-check amont | 34.3-4 (même message UI + service) |
| M-2 seeder non-pruning → recettes orphelines si le catalogue rétrécit → test garde-fou « DB = clés canoniques » | 34.3 checklist (sélecteur ne liste QUE les 4 recettes) |

#### Scénario 34.3-9 — Cible requise manquante = refus propre, rien créé

1. Ouvrir « Créer depuis un template », choisir `user_to_user` (ou `group_space`).
2. Renseigner `name` + `directory_name`, MAIS laisser un picker de rôle requis **vide** (ne pas sélectionner le second utilisateur / le groupe).
3. Cliquer « Matérialiser ».
4. **Attendu** : toast d'erreur explicite (« Le rôle « … » attend exactement une cible » / « au moins une cible »), **aucun** répertoire ni assignation créés (vérifier la liste `/app/shares` inchangée). Le refus a lieu AVANT toute écriture (pas de transaction partielle).

---

## Story 60.1 — La recette devient un arbre (modèle + résolution pure)

> ⚠️ **Cette story n'a AUCUN effet observable en production.** Rien n'est branché :
> pas d'UI, pas de route, pas de commande, pas de nouveau chemin d'exécution. Le
> résolveur de plan n'a d'autre consommateur que ses tests ; ses consommateurs
> réels arrivent en 60.2/60.3. La recette de la classe exprimée en vocabulaire de
> plan n'est **pas seedée** — elle vit uniquement dans les tests.
>
> La QA manuelle se limite donc à trois vérifications : **la migration s'applique
> et se rétracte**, **les recettes livrées restent sans arbre**, et **rien du
> comportement 5.2 / 34.x n'a bougé**. Tout le reste (natures de nœud, clôture,
> suspension, neutralité du plan) est verrouillé par la suite automatisée sur
> l'hôte — c'est là qu'il faut le lire, pas sur la VM.

### Scénario 60.1-1 — La migration s'applique et ne touche à rien d'autre

1. Sur `/vm` : `php artisan migrate` (au besoin `--pretend` d'abord pour lire ce
   qui sera joué).
2. **Attendu** : la migration `add_tree_spec_to_directory_templates` ajoute
   exactement **deux colonnes nullables** à `directory_templates` :
   `path_pattern` (texte) et `nodes_spec` (JSON). Aucune autre table touchée,
   aucune reprise de données.
3. Vérifier en base : `\d directory_templates` (psql) ou
   `Schema::getColumnListing('directory_templates')` en tinker.

### Scénario 60.1-2 — Les 4 recettes livrées restent SANS arbre

1. En tinker : `DirectoryTemplate::all()->map(fn ($t) => [$t->key, $t->path_pattern, $t->nodes_spec]);`
2. **Attendu** : les 4 recettes (`direction_to_all`, `profs_to_eleves`,
   `user_to_user`, `group_space`) ont `path_pattern = null` **et**
   `nodes_spec = null`. `$t->hasTreeSpec()` répond `false` pour les quatre.
3. Rejouer `php artisan db:seed --class=DirectoryTemplateSeeder` : idempotent,
   toujours 4 recettes, toujours sans arbre (le seeder n'a pas été modifié).

### Scénario 60.1-3 — La matérialisation 34.3 est strictement inchangée

1. Sur `/app/shares`, « Créer depuis un template » : matérialiser un
   `profs_to_eleves` comme avant.
2. **Attendu** : comportement identique à 34.3 — mêmes assignations, mêmes
   `access`, même provisioning. Aucun message nouveau, aucune option nouvelle
   dans le formulaire (l'arbre n'est exposé nulle part).

### Scénario 60.1-4 — Aucune permission de fichier n'a bougé

1. Sur un partage classe existant : `getfacl /var/sambaedu/Classes/Classe_<X>` et
   ses sous-dossiers (`_travail`, `_profs`, `_echange`, dossiers élèves).
2. Sur un répertoire réseau 34.x : `getfacl /var/sambaedu/Partages/<dir>`.
3. **Attendu** : sorties **identiques** à avant déploiement. C'est trivialement
   vrai (rien ne touche au système de fichiers) mais c'est le critère mesurable
   du garde-fou d'epic — le relever une fois vaut mieux que le supposer.

### Scénario 60.1-5 — La migration se rétracte

1. Sur un environnement de test (jamais en prod) : `php artisan migrate:rollback --step=1`.
2. **Attendu** : les deux colonnes disparaissent, les 4 recettes et leurs
   `roles_spec` sont intactes, l'application continue de fonctionner (aucun code
   vivant ne lit ces colonnes). Rejouer `migrate` pour revenir.

### Checklist rapide — Story 60.1

- [ ] 60.1-1 : migration jouée, 2 colonnes nullables ajoutées, rien d'autre
- [ ] 60.1-2 : les 4 recettes restent sans arbre (`hasTreeSpec()` faux), seeder inchangé et idempotent
- [ ] 60.1-3 : « Créer depuis un template » se comporte exactement comme en 34.3
- [ ] 60.1-4 : `getfacl` inchangé sur les partages classe **et** sur les répertoires réseau
- [ ] 60.1-5 : `migrate:rollback` propre, données 34.3 intactes
- [ ] **Aucune UI, aucune route, aucune commande** n'a été ajoutée — si quelque chose d'observable est apparu, c'est un défaut
- [ ] Suite automatisée verte sur l'hôte (résolveur, garde de neutralité, isolation d'architecture) — la VM n'a pas `pdo_sqlite`, ne pas tenter d'y jouer les tests

---

*Dernière mise à jour : 2026-08-04 (Story 60.1 — la recette devient un arbre : colonnes additives nullables `path_pattern`/`nodes_spec` sur `directory_templates`, enum fermée `PlanNodeNature` (4 natures), DTO de plan neutres + résolution PURE `PlanResolver`, clôture calculée par nœud (AC9, issue du spike 60.0), garde de neutralité + test d'architecture verrouillant la ligne de coupe ; AUCUN effet observable en production — services d'exécution et seeder INTOUCHÉS)*

*Mise à jour précédente : 2026-06-30 (Story 34.3 — Templates de répertoire : table `directory_templates` + `DirectoryTemplateSeeder` (4 recettes, Q3 option B) + `DirectoryTemplateService::materialize` (transaction + validation réutilisée 34.2, mapping de maille non redérivé) + 2e modale « Créer depuis un template » sur `/app/shares` (formulaire dynamique, aperçu, gating `networkshare.manage`) ; casiers « élèves→profs » par-élève reportés 34.x ; socle figé INTOUCHÉ ; **post-review** : surfaçage warnings AC2 [#1], pré-check unicité service [M-1], garde-fou seeder [M-2], test cible manquante [#2])*
