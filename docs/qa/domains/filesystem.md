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

**Pré-requis** : un groupe sans règle `QuotaRule::TYPE_GROUP`. Le créer de
**type "Classe"** (`type='classe'`) : la section quota s'affiche pour tous les
types, mais les scénarios 5.2-* et 34.1-3 exigent `classe` (`ShareService`
refuse tout autre type) — un seul groupe suffit ainsi pour tout le runbook.
Nom sans espace ni accent (cf. note #15). Exemple : `TEST6A`.

1. Se connecter en `admin` (server.admin).
2. Naviguer vers `/app/users/groups/{id}` du groupe en question.
3. Vérifier qu'une nouvelle section "Quota du groupe" est affichée entre la
   section "Professeur principal" et la section "Capacités" (ordre réel de
   la page : en-tête → membres → partage de classe (si `type='classe'`) →
   professeur principal → **quota du groupe** → capacités). La wallpaper-card
   n'existe plus : le fond d'écran est devenu une capacité et vit désormais
   dans la section "Capacités", en bas de page.
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

> ⛔ **Scénarios 5.1c-8 à 5.1c-13 et 5.1d-5/5.1d-6 : SUSPENDUS depuis le
> 2026-08-05.** L'onglet « Quotas & FS » a été retiré (décision Henri) : sa grille
> de quotas par défaut par profil n'appliquait rien à personne — elle écrivait
> `SystemSetting('quota.defaults')`, clé que la résolution ne lit pas. Il n'existe
> donc plus d'UI pour les défauts, la période de grâce ni la corbeille. Les valeurs
> déjà persistées **restent en vigueur** (cron `trash:purge` 02h00 inclus) et
> restent pilotables en CLI (`php artisan trash:purge`) et en tinker.
> La **story 5.1e** réinstalle un **défaut global unique** (fin des 4 profils) plus
> les cartes grâce et corbeille dans l'onglet « Personnels et partagés » — ces
> scénarios seront réécrits à ce moment-là, pas avant. Ne pas les jouer : ils
> décrivent une interface qui n'existe plus.

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

> ⛔ **SUSPENDU depuis le 2026-08-05** — l'onglet « Quotas & FS » qui portait ce
> bouton a été retiré (cf. encadré du bloc 5.1c). La purge reste jouable en CLI
> (`php artisan trash:purge`, scénarios 5.1d-3/5.1d-4). Le bouton revient en carte
> « Corbeille » avec la story 5.1e.

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

> ⛔ **Étape 1 SUSPENDUE depuis le 2026-08-05** — le toggle n'a plus d'UI (cf.
> encadré du bloc 5.1c). Basculer la valeur en tinker pour jouer les étapes 2-5,
> qui restent valables :
> `SystemSetting::set('quota.trash', ['ttl_days' => 30, 'purge_auto' => true]);`

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

> ⚠️ **Ne pas utiliser `profs_to_eleves` pour ce scénario** — défaut 34.3 constaté
> le 2026-08-04, indépendant de l'Epic 60 : son rôle « Équipe enseignante (dépose) »
> est contraint à `group_type = 'equipe'`, un type que le repli 4.13 ne produit plus
> (`Classe_X`/`Equipe_X`/`PP_X` → une ligne nue `type='classe'`). Le picker est vide
> et muet, la recette est immatérialisable. Utiliser **`direction_to_all`** ou
> **`group_space`** (`group_type = null`). Correctif suivi dans
> `_bmad-output/todo/_bugs.md` : recâbler la recette sur
> `RoleResolutionStrategy::EdgeRole` livrée en 60.2.

1. Sur `/admin/settings/files?tab=lecteurs-reseaux`, « Créer depuis un template » :
   matérialiser un `direction_to_all` comme avant.
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
- [ ] 60.1-3 : « Créer depuis un template » se comporte exactement comme en 34.3 (avec `direction_to_all` — `profs_to_eleves` est cassée par un défaut 34.3, cf. encadré)
- [ ] 60.1-4 : `getfacl` inchangé sur les partages classe **et** sur les répertoires réseau
- [ ] 60.1-5 : `migrate:rollback` propre, données 34.3 intactes
- [ ] **Aucune UI, aucune route, aucune commande** n'a été ajoutée — si quelque chose d'observable est apparu, c'est un défaut
- [ ] Suite automatisée verte sur l'hôte (résolveur, garde de neutralité, isolation d'architecture) — la VM n'a pas `pdo_sqlite`, ne pas tenter d'y jouer les tests

## Story 60.2 — Résolution de rôle par règle et accrochage au type de groupe

> ⚠️ **Cette story n'a, elle non plus, AUCUN effet observable en production — à une
> exception près, purement textuelle : trois libellés changent à l'écran.** La
> chaîne « groupe → plan » est livrée COMPLÈTE et **DORMANTE** : aucun code de
> production ne l'appelle, aucune recette n'est accrochée à un type de groupe,
> aucun déclencheur n'est câblé à la création d'un groupe. Le brancher exige un
> backend capable d'exécuter un arbre (60.3/60.4) et la recette classe seedée
> (60.5). Tenter de « voir le plan quelque part » dans l'admin est donc vain : il
> n'y a rien à voir, et c'est le comportement attendu.
>
> La QA manuelle porte sur quatre points : **la migration s'applique et se
> rétracte**, **les recettes livrées restent non accrochées et se matérialisent
> comme avant**, **les libellés de rôle d'arête suivent le type de groupe**, et
> **rien du comportement 5.2 / 34.x n'a bougé**. Le reste (stratégies de
> résolution, sujet abstrait d'audience, décomposition « matière × classe ») est
> verrouillé par la suite automatisée sur l'hôte.

### Scénario 60.2-1 — La migration s'applique et n'accroche rien

1. Sur `/vm` : `php artisan migrate` (au besoin `--pretend` d'abord).
2. **Attendu** : la migration `add_attached_group_type_to_directory_templates`
   ajoute **une seule colonne**, `attached_group_type`, **nullable** et **unique**,
   à `directory_templates`. Aucune autre table touchée, aucune reprise de données.
3. En tinker : `DirectoryTemplate::all()->pluck('attached_group_type', 'key');`
   → les 4 valeurs sont `null`. **Aucune recette n'est accrochée**, et c'est
   volontaire : l'accrochage est seedé en 60.5.

### Scénario 60.2-2 — Les 4 recettes livrées se matérialisent exactement comme avant

1. Sur `/app/shares`, « Créer depuis un template » : matérialiser un
   `profs_to_eleves` et un `user_to_user` comme d'habitude.
2. **Attendu** : formulaire identique, mêmes cibles à désigner à la main, mêmes
   assignations produites. L'absence de règle de résolution vaut « cible désignée
   à la matérialisation » — c'est le comportement de 34.3, inchangé.
3. Rejouer `php artisan db:seed --class=DirectoryTemplateSeeder` : toujours 4
   recettes, toujours non accrochées (le seeder n'a pas été modifié).
4. **Piège à vérifier au passage** : `user_to_user` doit rester matérialisable. Ses
   deux rôles visent des **personnes** — un correctif qui rejetterait les
   utilisateurs comme cible casserait cette recette, et c'est l'erreur que la revue
   de 60.1 avait failli faire entrer.

### Scénario 60.2-3 — Les libellés de rôle d'arête suivent le type de groupe

C'est **le seul changement visible** de la story. Les valeurs stockées
(`member`/`manager`/`owner`) sont **inchangées** : seule leur lecture change.

1. Ouvrir une **classe** (`/app/users/groups/<id>` d'un groupe de type `classe`),
   onglet Profs et onglet Élèves.
   **Attendu** : la colonne « Rôle » lit « Élève », « **Enseignant** » (et non plus
   « Prof ») et « **Professeur principal** » (et non plus « Prof principal »). Pour
   un porteur d'`update-group`, la liste déroulante affiche les mêmes trois
   libellés ; l'option « Professeur principal » reste réservée aux classes.
2. Ouvrir un groupe de type **projet** contenant un membre `manager`.
   **Attendu** : ce membre se lit « **Porteur** », plus « Prof ». Un membre simple
   s'y lit « **Membre** », plus « Élève ».
3. Ouvrir un groupe de type **équipe** contenant un `manager` : il se lit
   « **Référent** ».
4. Ouvrir un groupe d'un type non tranché (cours, matière, custom…) : repli
   générique « Membre / Gestionnaire / Propriétaire ». **Aucune valeur technique
   (`member`, `manager`, `owner`) ne doit apparaître comme texte visible, nulle
   part.**
5. Fiche utilisateur `/app/users/<login>` : dans la liste de ses groupes, le badge
   de rôle suit la même table (rien pour un membre simple, le libellé du type
   sinon).
6. **Non-régression fonctionnelle** : changer le rôle d'un membre depuis la liste
   déroulante fonctionne comme avant (la valeur envoyée reste `member`/`manager`/
   `owner`), et la reprojection d'annuaire du professeur principal (`PP_`) est
   inchangée.

### Scénario 60.2-4 — Aucune permission de fichier n'a bougé

1. Sur un partage classe existant : `getfacl /var/sambaedu/Classes/Classe_<X>` et
   ses sous-dossiers (`_travail`, `_profs`, `_echange`, dossiers élèves).
2. Sur un répertoire réseau 34.x : `getfacl /var/sambaedu/Partages/<dir>`.
3. **Attendu** : sorties **identiques** à avant déploiement. Trivialement vrai
   (aucun service d'exécution n'a été modifié : `ShareService`,
   `NetworkShareService`, `DirectoryTemplateService` et le seeder sont à zéro
   diff), mais c'est le critère mesurable du garde-fou d'epic.

### Scénario 60.2-5 — La migration se rétracte

1. Sur un environnement de test (jamais en prod) : `php artisan migrate:rollback --step=1`.
2. **Attendu** : la colonne `attached_group_type` et son index unique disparaissent,
   les 4 recettes et leurs `roles_spec` sont intactes, l'application continue de
   fonctionner. Rejouer `migrate` pour revenir.

### Ce qui N'EST PAS observable, et qu'il ne faut pas chercher

Ces comportements existent, sont testés sur l'hôte, et ne se voient d'aucun écran :

- les quatre stratégies de résolution (`self`, `designated`, `pattern`,
  `edge_role`) et leur validation ;
- le sujet **abstrait** d'une audience d'arête — « les membres de ce groupe portant
  ce rôle », un sujet par rôle listé, indépendant de l'effectif (3 membres et 300
  membres produisent les mêmes sujets) ;
- la décomposition d'un nom « matière × classe » (`Matiere_Math@3emeA`) en deux
  segments de chemin — **aucune recette matière n'est seedée**, le modèle sait
  seulement les accueillir ;
- l'accrochage d'une recette à un type de groupe — **aucune donnée d'accrochage
  n'est écrite** par cette story.

### Checklist rapide — Story 60.2

- [ ] 60.2-1 : migration jouée, 1 colonne nullable unique ajoutée, les 4 recettes restent non accrochées
- [ ] 60.2-2 : « Créer depuis un template » identique à 34.3, `user_to_user` toujours matérialisable
- [ ] 60.2-3 : libellés par type — classe « Élève / Enseignant / Professeur principal », projet « Porteur », équipe « Référent », repli générique ailleurs
- [ ] 60.2-3 : aucune valeur technique (`member`/`manager`/`owner`) rendue comme texte visible
- [ ] 60.2-3 : changer un rôle depuis la liste déroulante fonctionne toujours, projection `PP_` inchangée
- [ ] 60.2-4 : `getfacl` inchangé sur les partages classe **et** sur les répertoires réseau
- [ ] 60.2-5 : `migrate:rollback` propre, données 34.3 intactes
- [ ] **Aucune UI nouvelle, aucune route, aucune commande** — hors les trois libellés, rien d'observable ne doit être apparu
- [ ] Suite automatisée verte sur l'hôte — la VM n'a pas `pdo_sqlite`, ne pas tenter d'y jouer les tests

---

## Story 60.3 — Le contrat `FileBackend`, le backend d'aperçu, et la colonne `backend`

**Ce que cette story ajoute d'OBSERVABLE — et c'est une première dans l'epic.** Les deux
stories précédentes ne se voyaient d'aucun écran. Celle-ci en livre deux :

1. un **badge « Backend »** sur la liste des lecteurs réseau et sur la page d'un lecteur —
   « Serveur de fichiers (POSIX/SMB) » partout, puisque c'est ce que sont tous les partages
   en place ;
2. une action **« Aperçu du plan »** sur la page d'un lecteur, qui ouvre une modale montrant
   ce que le partage dit — la racine, ses accès prévus par personne et par groupe, le plafond
   et le résultat annoncé par le backend d'aperçu.

**Ce qu'elle ne change PAS** : aucun flux de provisioning ne route par la nouvelle colonne.
L'aperçu est en LECTURE SEULE et n'écrit rien, nulle part. Le badge n'est pas éditable, et
c'est délibéré : tant que rien ne route par la colonne, un sélecteur serait un réglage sans
effet.

### Scénario 60.3-1 — La migration s'applique et le défaut dit vrai

1. Sur `/vm` : `php artisan migrate` puis
   `psql -c "\d network_shares"` (ou l'équivalent) et
   `psql -c "SELECT DISTINCT backend FROM network_shares;"`.
2. **Attendu** : une colonne `backend` de type texte, **NOT NULL**, défaut `'posix'`, et
   **toutes** les lignes existantes à `posix`. Aucune reprise de données n'a été jouée ni
   n'est nécessaire — les partages en place *sont* du POSIX.
3. Contrôle : `php artisan migrate:rollback --step=1` retire la colonne proprement, et
   `php artisan migrate` la remet. Aucune autre table n'est touchée.

### Scénario 60.3-2 — Le backend s'affiche, et ne se modifie pas

1. Ouvrir `/admin/settings/files` → onglet « Lecteurs réseaux ».
2. **Attendu** : une colonne « Backend » portant, pour chaque lecteur, le badge
   **« Serveur de fichiers (POSIX/SMB) »**. Jamais la valeur technique `posix` à l'écran.
3. Ouvrir un lecteur. **Attendu** : le même badge dans l'en-tête, à côté de la lettre, avec
   une infobulle expliquant ce que ce choix change pour l'utilisateur.
4. **Contrôle négatif — le plus important de la story** : il n'existe **aucun** moyen de
   changer ce backend. Pas de liste déroulante, pas de bouton, pas de champ dans le
   formulaire « Modifier ». Si vous en trouvez un, c'est un défaut : la propriété serait
   affichée comme modifiable alors que rien ne l'honore.

### Scénario 60.3-3 — L'aperçu d'un partage réel

1. Sur un lecteur ayant au moins un utilisateur, un groupe et un parc assignés, cliquer
   **« Aperçu du plan »**.
2. **Attendu dans la modale** :
   - l'en-tête annonce **« Aperçu (aucune exécution) »** — c'est le backend d'aperçu qui
     répond, pas celui du partage ;
   - « Racine du plan » affiche le **nom de répertoire** du partage (relatif, jamais
     `/var/...`) et **1 nœud** ;
   - la ligne du dossier s'intitule **« (racine) »** — jamais un point tout seul ;
   - la colonne « Accès prévus » liste **l'utilisateur** et **le groupe**, par leurs noms
     SE5, avec « Lire » ou « Modifier » ;
   - **le parc n'y figure PAS**. C'est l'invariant montage-seul : un parc fait apparaître la
     lettre, il ne donne aucun accès. Un partage assigné à un seul parc affiche « Aucun
     accès prévu », et c'est la vérité ;
   - la colonne « Résultat » affiche **« Aucune exécution (aperçu) »**.
3. **Contrôle de neutralité, à faire à l'œil** : nulle part dans cette modale on ne doit lire
   un mode de permission (`rwx`), un nom de commande système, un chemin absolu ou un nom de
   groupe système. On y parle de dossiers, de personnes et de groupes.
4. Fermer, puis vérifier qu'**aucun** provisioning ne s'est déclenché : ni toast de
   provisioning, ni ligne nouvelle dans les journaux, ni changement de l'encart
   « Conformité ACL ».

### Scénario 60.3-4 — Aucune permission de fichier n'a bougé

1. Sur un partage classe existant : `getfacl /var/sambaedu/Classes/Classe_<X>` et ses
   sous-dossiers. Sur un répertoire réseau : `getfacl /var/sambaedu/Partages/<dir>`.
2. **Attendu** : sorties **identiques** à avant déploiement. Trivialement vrai — les cinq
   fichiers d'exécution (`DirectoryTemplateService`, `NetworkShareService`, `ShareService`,
   `AclService`, `DirectoryTemplateSeeder`) sont à **zéro diff**, vérifié par `git diff --stat`.
3. Créer un lecteur, lui ajouter une assignation, la retirer, le supprimer : le comportement
   est celui d'avant, y compris les toasts et l'encart de conformité.

### Ce qui N'EST PAS observable, et qu'il ne faut pas chercher

Ces éléments existent, sont testés sur l'hôte, et **dorment** :

- l'interface de contrat à cinq méthodes et ses rapports par nœud — **aucun backend réel ne
  l'implémente** ; l'autorité d'écriture reste le code historique, appelé comme avant ;
- le nom de backend `preview` n'est **jamais posé en base** par cette story : il n'est
  atteignable que par l'aperçu, en mémoire ;
- demander le backend `posix` au registre **échoue volontairement** (aucune implémentation
  avant la story 60.4). Ce n'est visible d'aucun écran — l'aperçu, lui, demande explicitement
  le backend d'aperçu ;
- la relecture d'état, le plafond de zone et la comparaison désiré/observé : **le contrat
  sait les dire, personne ne les exécute**. Aucune infrastructure de quota n'a été installée
  (la story qui la poserait est suspendue) ;
- le squelette Nextcloud écrit contre l'interface réelle vit sous `tests/Integration/`,
  **skippé par défaut**, hors intégration continue : il n'est ni enregistré, ni
  sélectionnable, ni atteignable depuis l'application.

### Checklist rapide — Story 60.3

- [ ] 60.3-1 : colonne `backend` NOT NULL défaut `posix`, toutes les lignes à `posix`, rollback propre
- [ ] 60.3-2 : badge « Serveur de fichiers (POSIX/SMB) » sur la liste ET la page détail
- [ ] 60.3-2 : **aucun** contrôle permettant de changer le backend, nulle part
- [ ] 60.3-3 : l'aperçu s'ouvre, annonce « Aperçu (aucune exécution) », affiche « (racine) » et 1 nœud
- [ ] 60.3-3 : utilisateurs et groupes listés par leurs noms SE5 ; **le parc n'apparaît pas**
- [ ] 60.3-3 : aucun `rwx`, aucune commande système, aucun chemin absolu à l'écran
- [ ] 60.3-3 : ouvrir l'aperçu ne provoque AUCUN provisioning (journaux, toasts, encart de conformité inchangés)
- [ ] 60.3-4 : `getfacl` inchangé sur les partages classe **et** sur les répertoires réseau
- [ ] 60.3-4 : créer / assigner / désassigner / supprimer un lecteur se comporte comme avant
- [ ] Suite automatisée verte sur l'hôte — la VM n'a pas `pdo_sqlite`, ne pas tenter d'y jouer les tests

---

## Story 60.4 — Le backend `posix` : l'exécution passe sous la ligne de contrat

> **Ce que cette story change POUR L'EXPLOITANT, en une phrase** : rien ne doit
> bouger sur le disque. Les droits sont désormais posés par une implémentation
> nommée derrière un contrat, et non plus par le service de provisionnement
> lui-même — mais les commandes émises, les entrées produites et l'état final sont
> les mêmes. **Le seul écart de comportement voulu est un écart en MOINS** : un
> répertoire déjà conforme n'est plus réécrit.
>
> Deux changements sont VISIBLES et attendus :
> 1. **le provisionnement déclenché depuis un écran est ENFILÉ** — l'écran dit
>    « la mise en place des droits est engagée », et l'état se lit au
>    rafraîchissement suivant ou en cliquant « Vérifier » ;
> 2. **l'encart de conformité ne montre plus de lignes de droits brutes** — il
>    nomme les destinataires par leur nom SE5 et dit « attendu / constaté ».
>
> Contexte d'exécution : la synchronisation vers la VM était COUPÉE pendant le
> développement. **Aucune vérification disque n'a été faite par le dev.** Tout ce
> qui suit est à exécuter par Henri.

### Prérequis d'exploitation à VÉRIFIER AVANT de conclure quoi que ce soit

Ces trois points ne sont pas touchés par la story ; s'ils ont dérivé pour une
autre raison, les scénarios ci-dessous mesureront la mauvaise chose.

- [ ] 60.4-P1 — **la racine des répertoires gérés reste traversable** :
      `getfacl -c /var/sambaedu/Partages | grep '^other'` doit montrer au moins
      `r-x`. Les participants en lecture seule doivent pouvoir TRAVERSER la racine
      pour atteindre leur dossier ; un `other::---` sur la racine casse tout
      l'étage sans qu'aucune entrée de sous-dossier ne soit fautive.
- [ ] 60.4-P2 — **le masquage SMB est toujours actif** :
      `grep -i 'hide unreadable' /etc/samba/smb-partages.conf` (ou le fichier de
      configuration inclus) rend `hide unreadable = Yes`. C'est lui qui empêche un
      élève de VOIR les dossiers auxquels il n'a pas accès.
- [ ] 60.4-P3 — **aucune option n'ignore les droits POSIX** :
      `testparm -s 2>/dev/null | grep -i 'ignore system acl'` ne doit RIEN rendre.
      SE5 s'appuie sur le fait que Samba projette les droits POSIX ; si cette
      option apparaissait, tout ce qui suit serait posé et sans effet.
- [ ] 60.4-P4 — **la liste blanche d'élévation couvre le jeu de commandes** :
      `grep -E 'setfacl|getfacl|chmod|chown|chgrp|mkdir|mv' /etc/sudoers.d/sambaedu`.
      La story n'ajoute **aucune commande privilégiée**. Elle ajoute une seule
      commande, `getent`, en LECTURE et SANS élévation — rien à autoriser.
- [ ] 60.4-P5 — **une file de traitement tourne** : `systemctl status` du service
      de file (ou `php artisan queue:work --once` à la main). **Sans elle, les
      écrans enfilent dans le vide** : l'écran dira « engagée » et rien ne se
      passera. C'est le point de vérification le plus important de cette story.

### Scénario 60.4-1 — LE TEST QUI DÉCIDE : `getfacl -R` avant / après

C'est le seul protocole qui établit « aucune entrée ne bouge sur une instance en
place ». À faire sur une instance qui a DÉJÀ des répertoires provisionnés.

**L'instantané doit être DÉTERMINISTE, sinon le diff ment.** `getfacl -R` descend
dans l'ordre du système de fichiers, qui n'est pas stable d'un passage à l'autre :
un même état pourrait produire deux fichiers différents. Et `sort` sans locale
imposée ignore la ponctuation, ce qui suffit à réordonner des chemins. D'où la
forme ci-dessous — chemins énumérés puis triés explicitement, en-têtes `# file:`
CONSERVÉS (sans elles, on ne sait plus à quel chemin appartient chaque bloc) :

```sh
# à mettre dans ~/acl-snapshot.sh sur le serveur
#!/bin/sh
export LC_ALL=C
find /var/sambaedu/Partages -print0 | sort -z | xargs -0 -r getfacl -p
```

1. **Avant tout déploiement du code de la story**, capturer l'état complet :
   ```
   sh ~/acl-snapshot.sh > /tmp/acl-avant.txt
   ```
   (le `.trash` éventuel est inclus, c'est voulu — il ne doit pas bouger non plus)
2. Déployer la story.
3. Sur **chaque** répertoire, cliquer « Resynchroniser » depuis sa fiche, puis
   laisser la file traiter (ou `php artisan queue:work --stop-when-empty`).
4. Recapturer et comparer :
   ```
   sh ~/acl-snapshot.sh > /tmp/acl-apres.txt
   diff -u /tmp/acl-avant.txt /tmp/acl-apres.txt
   ```

**Vue d'ensemble lisible** (pour se repérer, pas pour comparer) — une ligne par
chemin, mode, propriétaire et groupe :

```sh
export LC_ALL=C
find /var/sambaedu/Partages -printf '%p\t%M\t%u:%g\n' | sort \
  | awk -F'\t' '{printf "%-11s %-28s %s\n", $2, $3, $1}'
```

Le mode affiché ici est le mode POSIX classique : il ne dit pas qu'une liste
d'accès étendue existe. Pour la voir, `getfacl` sur le chemin — ou `ls -ld`, dont
le `+` en fin de mode la signale.

- [ ] 60.4-1a — **le diff est VIDE**. Toute ligne de différence est un défaut de la
      story, pas un réglage : la remonter telle quelle.
- [ ] 60.4-1b — relancer « Resynchroniser » une seconde fois sur un répertoire :
      dans `journalctl` / les journaux applicatifs, le second passage doit se
      solder par un état **`conforme`**, et **aucun `setfacl` ne doit apparaître**.
      C'est le changement de comportement voulu (avant, la séquence réécrivait
      toujours). Pour l'observer directement :
      `strace`-libre — surveiller simplement l'horodatage des fichiers ou activer
      le journal applicatif en `debug` le temps du test.
- [ ] 60.4-1c — sur un répertoire volontairement dérivé à la main
      (`sudo setfacl -m u:root:rwx /var/sambaedu/Partages/<un_dossier>`), l'encart
      de conformité doit passer en « Écart détecté » **et** la resynchronisation
      doit ramener l'état exactement à celui de `/tmp/acl-avant.txt`.

### Scénario 60.4-2 — La réconciliation enfilée (ce qui change à l'écran)

- [ ] 60.4-2a — ajouter une assignation sur un répertoire : le message dit
      **« engagée »**, et l'écran ne bloque pas pendant la pose.
- [ ] 60.4-2b — **avec la file ARRÊTÉE** : le message dit toujours « engagée », et
      `getfacl` ne montre AUCUN changement. C'est le comportement attendu, et c'est
      pourquoi 60.4-P5 est un prérequis et non un détail.
- [ ] 60.4-2c — redémarrer la file : la pose a lieu, et « Vérifier » sur la fiche
      rend « conformes ».
- [ ] 60.4-2d — supprimer un répertoire : la révocation reste **SYNCHRONE** (elle
      ne passe pas par la file). Vérifier immédiatement après la suppression que
      `\\serveur\partages\<nom>` n'est plus atteignable depuis un poste.

### Scénario 60.4-3 — L'archivage : le nom de dossier a CHANGÉ

La poubelle suffixait par l'identifiant de la ligne en base
(`.trash/<nom>-<id>`). Cet identifiant n'appartient pas au plan (le plan est
portable par conception) : le suffixe est désormais la **date d'archivage**.

- [ ] 60.4-3a — supprimer un répertoire, puis `ls -la /var/sambaedu/Partages/.trash`
      → une entrée `<nom>-AAAAMMJJ-HHMMSS`, en `0700 www-admin`.
- [ ] 60.4-3b — le contenu est INTACT (`ls -la` dedans). Aucune donnée n'est
      détruite, jamais.
- [ ] 60.4-3c — les archives ANTÉRIEURES (`<nom>-<id>`) sont toujours là, non
      renommées, non touchées.

**Cas de collision, vérifié en laboratoire.** Deux archivages du MÊME nom de
répertoire dans la MÊME seconde (donc : ligne supprimée puis recréée à
l'identique, puis re-supprimée aussitôt) viseraient la même cible. Le
déplacement n'écrase alors rien : le second contenu se retrouve **imbriqué** sous
`<nom>-AAAAMMJJ-HHMMSS/<nom>/`. Aucune donnée n'est perdue — l'obligation tenue
par cette story est respectée — mais l'exploitant qui tomberait dessus doit savoir
que c'est une imbrication, pas une fusion.

- [ ] 60.4-3d — (facultatif) si une archive contient un dossier de même nom que
      l'archive elle-même, c'est ce cas : deux archivages distincts, pas un seul.

### Scénario 60.4-4 — `pp_<classe>` comme sujet de droits : PREMIÈRE UTILISATION RÉELLE

**À lire avant de conclure.** Le groupe d'annuaire des professeurs principaux
(`PP_<base>`) existe depuis la story 42.2 et il est bien alimenté. Mais **aucune
liste d'accès du produit historique ne l'a jamais visé** : sa résolvabilité côté
système (par le démon de jonction d'annuaire) n'est **PAS établie**. La story ne
la présuppose pas et ne la contourne pas — elle la vérifie avant d'écrire, et
refuse d'écrire si le nom ne résout pas.

Ce scénario est là pour l'ÉTABLIR, sur une instance réelle.

1. Choisir une classe existante et relever sa base nue (nom court + suffixe
   d'établissement), par exemple `3sb-1229y`.
2. Sonder les trois noms du trio, exactement comme le fait le code :
   ```
   getent group classe_3sb-1229y
   getent group equipe_3sb-1229y
   getent group pp_3sb-1229y
   ```

- [ ] 60.4-4a — les deux premiers résolvent (ils sont utilisés depuis toujours).
- [ ] 60.4-4b — **le troisième résout-il ?** Noter la réponse telle quelle, c'est
      l'information attendue.
  - **S'il résout** : le rôle « propriétaire » d'une classe est projetable en
    droits, et la story suivante peut s'appuyer dessus.
  - **S'il ne résout PAS** : c'est le comportement NORMAL de la story — l'octroi
    n'est pas écrit, et la fiche du répertoire affiche un échec **nommant
    `pp_<base>`**. Rien n'est posé au hasard. Il faudra alors décider (story
    ultérieure) entre exposer le groupe au système ou compiler ce rôle autrement.
- [ ] 60.4-4c — vérifier la casse : `getent group PP_3sb-1229y` peut résoudre là où
      `pp_3sb-1229y` échoue (ou l'inverse) selon la configuration de sensibilité à
      la casse. Noter le résultat des deux.

### Scénario 60.4-5 — L'encart de conformité assaini

- [ ] 60.4-5a — sur une fiche de répertoire, l'encart n'affiche **plus aucune
      ligne de droits** (`user:...:rwx`), plus aucun nom de groupe système, plus
      aucun chemin absolu.
- [ ] 60.4-5b — sur un répertoire dérivé, il nomme les destinataires par leur nom
      SE5 et dit « Attendu / Constaté » (`Lire`, `Modifier`, `Aucun`, `—`), avec
      « (racine) » pour la racine.
- [ ] 60.4-5c — le bouton « Resynchroniser » est toujours là et engage la
      réconciliation ; le bouton « Vérifier » relit sans rien écrire.
- [ ] 60.4-5d — le contrôleur d'environnement (`php artisan se4:doctor`, section
      système de fichiers) compte toujours les lecteurs dérivés / non provisionnés /
      illisibles, avec la même sémantique qu'avant.

### Scénario 60.4-6 — Un groupe qui ne résout pas (l'incident connu, rendu visible)

Reproduit l'incident du groupe d'équipe sans suffixe d'établissement, où l'outil
échouait avec « argument invalide » et où la seule trace était une ligne de
journal que personne ne lisait.

1. Assigner à un répertoire un groupe SE5 dont le nom système n'existe PAS côté
   annuaire (créer un groupe SE5 « bidon », ne pas le synchroniser).
2. Resynchroniser, laisser la file traiter.

- [ ] 60.4-6a — `getfacl` sur le dossier ne montre **aucune** entrée pour ce
      groupe : rien n'a été posé au hasard.
- [ ] 60.4-6b — la fiche affiche un **échec** dont le texte NOMME le groupe système
      attendu. C'est l'information qui rend l'incident réparable.
- [ ] 60.4-6c — les autres entrées du même répertoire sont posées normalement : un
      octroi refusé n'emporte pas les autres.

### Scénario 60.4-7 — Résolution de noms INDISPONIBLE : le doute ne révoque rien

Distinct du précédent, et c'est tout l'enjeu : « ce groupe n'existe pas » est une
réponse, « je n'ai pas pu demander » n'en est pas une. La pose commence par purger
les droits étendus du répertoire — si un doute était traité comme une absence, une
panne de résolution de noms deviendrait une **révocation d'accès** sur tous les
répertoires réconciliés pendant la panne.

1. Noter d'abord l'état : `getfacl /var/sambaedu/Partages/<nom>` (le garder).
2. Rendre la résolution de noms indisponible **le temps du test** (arrêter le
   service de jonction au domaine : `systemctl stop winbind`).
3. Depuis la fiche du répertoire, cliquer « Resynchroniser », laisser la file
   traiter.

- [ ] 60.4-7a — `getfacl` sur le dossier est **strictement identique** à l'état
      noté en 1 : rien n'a été purgé, rien n'a été réécrit.
- [ ] 60.4-7b — la fiche affiche un **échec** disant qu'il a été impossible de
      savoir si le groupe se résout, et invitant à vérifier la jonction au domaine.
- [ ] 60.4-7c — relancer le service (`systemctl start winbind`), attendre la
      résolution (`getent group <nom>` répond), resynchroniser → l'état redevient
      conforme, sans intervention manuelle sur les droits.

### Scénario 60.4-8 — Un geste enfilé qui échoue le DIT à l'écran

Le service absorbe l'erreur : rien ne remonte à la file, donc ni réessai ni
consignation d'échec. Le seul destinataire possible est l'écran.

1. Provoquer un échec de préparation (le plus simple : renommer temporairement le
   répertoire de partage attendu, ou retirer la recette rattachée).
2. Resynchroniser, laisser la file traiter, **recharger la fiche**.

- [ ] 60.4-8a — la fiche affiche « la dernière mise en place des droits n'a pas eu
      lieu ». Elle ne reste PAS sur « réconciliation engagée ».
- [ ] 60.4-8b — après correction et resynchronisation aboutie, ce message
      **disparaît** — un échec qui resterait affiché serait le mensonge symétrique.

### Ce qui n'est PAS observable sur cette instance (et pourquoi)

À dire explicitement pour qu'une absence de constat ne se lise pas comme un
succès :

- **Le garde-fou d'échelle** (refus au-delà de 200 entrées nominatives sur un même
  nœud) : aucun répertoire réel n'a une audience nominative de cette taille — la
  voie normale est le groupe. Il est couvert par la suite automatisée, pas par ce
  runbook.
- **Le plafond de zone** : SE5 ne le pilote pas (story suspendue). Le backend
  répond « non piloté par SE5 pour l'instant » et **aucune infrastructure de quota
  n'est posée**. Rien à vérifier sur disque ; c'est une dette datée, pas une panne.
- **Les arbres à plusieurs niveaux** : la chaîne recette→arbre n'est pas encore
  branchée sur ce backend (story suivante). Tous les plans en production ont
  aujourd'hui UN nœud, la racine.
- **Le suivi de progression d'une réconciliation** : il n'existe pas, et c'est un
  choix. L'écran dit « engagée » puis l'administrateur relit à la demande. Il n'y a
  ni barre d'avancement ni actualisation automatique à chercher.
- **La suite automatisée ne tourne pas sur la VM** (pas de `pdo_sqlite`) : elle est
  exécutée sur la machine hôte. Ne pas tenter de la jouer ici.

### Checklist rapide — Story 60.4

- [ ] 60.4-P5 : **une file de traitement tourne** (sinon les écrans enfilent dans le vide)
- [ ] 60.4-1a : `getfacl -R` avant/après → **diff VIDE**
- [ ] 60.4-1b : second passage → `conforme`, **aucun `setfacl`**
- [ ] 60.4-1c : dérive manuelle → détectée, puis ramenée à l'état d'origine
- [ ] 60.4-2a/b/c : l'écran dit « engagée », la file exécute, « Vérifier » confirme
- [ ] 60.4-2d : suppression → révocation immédiate, chemin UNC inatteignable
- [ ] 60.4-3a/b/c : archive datée, contenu intact, archives antérieures non touchées
- [ ] 60.4-4b : **`getent group pp_<base>` — noter la réponse** (première utilisation réelle)
- [ ] 60.4-5a : plus aucune ligne de droits brute à l'écran
- [ ] 60.4-6a/b : groupe non résolu → rien de posé, échec qui NOMME le groupe attendu
- [ ] 60.4-7a : résolution de noms coupée → `getfacl` **inchangé** (le doute ne révoque pas)
- [ ] 60.4-8a : geste enfilé en échec → l'écran le DIT, il ne reste pas sur « engagée »
- [ ] Prérequis P1 à P4 vérifiés et inchangés
- [ ] Suite automatisée verte sur l'hôte

## Story 60.5 — Le seed SE4 dans une racine NEUVE, et la comparaison des deux arbres

**Ce que cette story change sur le serveur, en une phrase :** SE5 sait désormais
matérialiser l'arbre de partage d'une classe **dans une racine à lui**, distincte de
l'arbre historique, auquel il **n'écrit plus jamais un octet**. Les deux arbres
coexistent : l'historique reste le seul servi aux établissements, le neuf existe
pour être **comparé**.

**Deux arbres, deux commandes, à ne jamais confondre :**

| | arbre HISTORIQUE | arbre NEUF |
|---|---|---|
| racine | `/var/sambaedu/Classes` | `/var/sambaedu/ClassesSE5` (`SAMBAEDU_CLASS_TREES_ROOT`) |
| servi aux postes | **oui** (`[classes]`) | **non** — aucune exposition SMB dans cette story |
| commande | `shares:resync-class` | `shares:materialize-class-trees` |
| bascule `_echange` | fiche du GROUPE | fiche du PARTAGE (`/admin/shares/<id>`) |

L'arbre neuf **ne se vérifie donc PAS depuis un poste** : il se lit en `getfacl`
côté serveur. Une absence de constat côté poste n'est pas un échec — c'est le
comportement voulu pendant la phase de comparaison.

### Prérequis 60.5

- [ ] **60.5-P1 — le peuplement des recettes a été rejoué.**
      `php artisan db:seed --class=DirectoryTemplateSeeder` →
      `php artisan tinker --execute="echo App\Models\DirectoryTemplate::count();"`
      doit rendre **5** (la 5ᵉ est `classe_se4`).
      Sans ce passage, `shares:materialize-class-trees` sort en code **2** avec un
      message explicite : c'est voulu, ce n'est pas un succès silencieux.

- [ ] **60.5-P2 — LA LISTE BLANCHE `sudo` COUVRE LA RACINE NEUVE.** *(§[PROD], hors git)*
      C'est le prérequis d'infra de cette story, et le seul dont l'oubli produit un
      symptôme uniforme et déroutant : **toute** la matérialisation décline avec un
      refus de permission, y compris sur des classes parfaitement saines.
      La liste blanche autorise aujourd'hui `mkdir` / `setfacl` / `chown` / `chgrp` /
      `mv` sous `/var/sambaedu/Classes` et `/var/sambaedu/Partages` ; elle doit
      couvrir **le même jeu de commandes sous `/var/sambaedu/ClassesSE5`**.
      Vérification rapide, avant toute autre chose :
      ```bash
      sudo -n mkdir -p /var/sambaedu/ClassesSE5/.probe && sudo -n rmdir /var/sambaedu/ClassesSE5/.probe && echo OK
      ```
      `OK` ⇒ prérequis tenu. Un refus ⇒ **arrêter ici** : tous les scénarios qui
      suivent échoueraient pour cette seule raison.

- [ ] **60.5-P3 — un ouvrier de file tourne.** Identique au prérequis 60.4-P5, et
      pour la même raison : les écrans ENFILENT. Sans ouvrier, tout dit « engagé »
      et rien ne se produit. La commande de peuplement, elle, s'exécute en DIRECT
      et n'en a pas besoin — c'est délibéré.

- [ ] **60.5-P4 — un instantané de l'arbre historique est pris AVANT tout.**
      C'est la pièce à conviction du scénario 60.5-2 ; sans elle, l'invariance ne
      se prouve pas, elle s'affirme.
      ```bash
      sudo getfacl -R /var/sambaedu/Classes > ~/classes-avant.facl
      wc -l ~/classes-avant.facl        # doit être NON VIDE
      ```

### Scénario 60.5-1 — Peupler l'arbre neuf (simulation, puis réel)

1. **Simulation d'abord.** Elle ne crée rien, n'écrit rien, ne lance aucun processus :
   ```bash
   php artisan shares:materialize-class-trees --dry-run
   ```
2. Puis **une seule classe**, choisie parce que ses groupes d'annuaire se résolvent
   (`getent group equipe_<base>` et `getent group classe_<base>` répondent) :
   ```bash
   php artisan shares:materialize-class-trees --class=<nom de la classe>
   ```
3. Enfin le parc entier, une fois la première classe vérifiée :
   ```bash
   php artisan shares:materialize-class-trees
   ```

- [ ] 60.5-1a — la simulation liste les classes et dit, pour chacune, « arbre à
      créer » ou « arbre déjà relié » ; **`/var/sambaedu/ClassesSE5` est resté vide**.
- [ ] 60.5-1b — après le passage réel sur une classe, l'arbre est **complet** :
      ```bash
      ls -a /var/sambaedu/ClassesSE5/Classe_<nom>
      ```
      → `_travail`, `_travail/devoirs`, `_profs`, `_echange`, **et un dossier par
      élève** (nommé par son identifiant de connexion).
- [ ] 60.5-1c — le partage **apparaît dans la liste** `/admin/settings/files?tab=lecteurs-reseaux`
      et sa fiche affiche son **emplacement serveur réel** (`/var/sambaedu/ClassesSE5/Classe_<nom>`),
      la recette dont il vient et le groupe qu'il cloisonne.
- [ ] 60.5-1d — **rejouer la commande n'émet rien** : le second passage relit l'état
      et le trouve conforme.
      ```bash
      php artisan shares:materialize-class-trees --class=<nom> -v
      ```
      → toujours code **0**, et `getfacl -R` de l'arbre neuf **inchangé**.
- [ ] 60.5-1e — sur le parc entier, un nombre notable de classes **déclinent** en
      nommant le groupe d'annuaire introuvable (« classes déchets » dont les groupes
      ne se résolvent pas). Ce sont **exactement** celles que `shares:resync-class`
      saute déjà. Comparer les deux listes :
      ```bash
      php artisan shares:resync-class --dry-run | sort > /tmp/legacy-skip.txt
      ```
      → les mêmes classes doivent être écartées des deux côtés. Un écart ici est un
      vrai signal, à remonter.

### Scénario 60.5-2 — LA PREUVE : l'arbre historique n'a pas bougé d'un bit

C'est **la** vérification de cette story. « Aucune ACL ne bouge » n'est plus une
précaution, c'est une propriété : la garde de chemin n'a **aucun jeton** pour
l'arbre historique, donc aucun chemin ne peut y être fabriqué. On le **constate**
plutôt que de le croire.

1. Après TOUS les gestes du scénario 60.5-1 (et de préférence après ceux des
   scénarios 60.5-3 et 60.5-4) :
   ```bash
   sudo getfacl -R /var/sambaedu/Classes > ~/classes-apres.facl
   diff ~/classes-avant.facl ~/classes-apres.facl && echo "INVARIANT TENU"
   ```

- [ ] 60.5-2a — le `diff` est **VIDE**. Pas « presque vide », pas « seulement des
      dates » : `getfacl` n'écrit pas d'horodatage, donc toute ligne de diff est un
      vrai changement de droits et **fait échouer cette vérification**.
- [ ] 60.5-2b — les dates de modification des répertoires historiques n'ont pas
      bougé non plus :
      ```bash
      sudo find /var/sambaedu/Classes -maxdepth 2 -newer ~/classes-avant.facl
      ```
      → **aucune sortie**.
- [ ] 60.5-2c — aucune trace d'écriture SE5 dans l'arbre historique côté journal :
      ```bash
      sudo grep -c '/var/sambaedu/Classes/' storage/logs/laravel.log
      ```
      → les seules occurrences éventuelles viennent de `shares:resync-class`, qui est
      **l'outil de cet arbre-là** et reste parfaitement légitime. Vérifier qu'aucune
      ne provient de `shares:materialize-class-trees` ni d'une réconciliation de
      partage.

### Scénario 60.5-3 — Le DIFF DES DEUX ARBRES : l'écart attendu, et rien d'autre

L'épreuve du langage. Pour une même classe, les deux arbres doivent dire la même
chose — à **UN écart documenté près**, sur la racine seule.

1. Choisir une classe présente **des deux côtés** et provisionnée par
   `shares:resync-class` (arbre historique) ET par `shares:materialize-class-trees`
   (arbre neuf).
2. Comparer, nœud par nœud, en forme canonique :
   ```bash
   cd /var/sambaedu
   for n in . _travail _travail/devoirs _profs _echange; do
     echo "=== $n ==="
     diff <(sudo getfacl -c -E "Classes/Classe_<nom>/$n"    2>/dev/null | sort) \
          <(sudo getfacl -c -E "ClassesSE5/Classe_<nom>/$n" 2>/dev/null | sort)
   done
   ```

- [ ] 60.5-3a — sur `_travail`, `_travail/devoirs`, `_profs`, `_echange` et sur les
      dossiers d'élèves : **diff VIDE**.
- [ ] 60.5-3b — sur la **racine** (`.`), et là seulement, le diff porte
      **exactement** ces trois lignes, ni plus ni moins :
      - historique seulement : `default:group:equipe_<base>:rwx`
      - neuf seulement : `default:group:equipe_<base>:r-x`
      - neuf seulement : `default:group:classe_<base>:r-x`
- [ ] 60.5-3c — **tout autre écart est un vrai signal.** Ne pas l'écarter, ne pas
      l'expliquer après coup : c'est le langage de recette qui doit être interrogé,
      et le développement rouvert. C'est toute la valeur de cette comparaison.

**Ce que cet écart est, et pourquoi il n'est ni reproduit ni corrigé.** Sur la
racine, l'ACL d'héritage de l'arbre historique ne reflète pas son ACL d'accès :
l'équipe y est en écriture alors qu'elle est en lecture-traversée en accès, et la
classe n'y a **aucune** entrée d'héritage. C'est un **accident de séquence** — le
jeu canonique pose l'équipe en écriture avec ses miroirs, puis un ajustement
redescend l'accès sans toucher aux miroirs. L'arbre neuf, lui, dit la même chose en
accès et en héritage : la forme saine, celle que **tous les autres nœuds de l'arbre
historique ont déjà**. Effet réel : l'héritage ne concerne que des enfants créés à
la racine hors plan, et seuls les administrateurs peuvent y créer. On le documente,
la décision se prendra à la migration.

**Combien de racines le portent.** Sur l'instance de référence (mesure du
2026-08-05) : **21 racines sur 150**. Les **129 autres n'ont aucune entrée
`classe_` du tout** — leurs groupes d'annuaire ne se résolvent pas, et les DEUX
pré-contrôles les sautent. C'est une observation à consigner, pas un défaut à
traiter.

- [ ] 60.5-3d — noter le compte observé sur CETTE instance :
      ```bash
      sudo getfacl -R /var/sambaedu/Classes 2>/dev/null | grep -c 'default:group:equipe_.*:rwx'
      ```

**Un écart de CONTENU va apparaître avec le temps, et ce n'est pas un défaut.** Au
changement de classe d'un élève, l'arbre historique **archive** son dossier dans sa
nouvelle classe (comportement conservé) ; l'arbre neuf laisse les données **en
place** — un nœud disparu du plan n'est plus gouverné, il n'est pas détruit. Sur
deux arbres vivants, les contenus divergent donc. C'est un écart de contenu, jamais
d'ACL : la comparaison ci-dessus ne le voit pas, et il ne doit pas être lu comme
une régression. Le rapatriement délibéré appartient à la story de migration.

### Scénario 60.5-4 — La bascule `_echange` de l'arbre NEUF

Deux bascules, deux arbres, indépendantes. Celle de la fiche du GROUPE pilote
l'arbre historique ; celle de la fiche du PARTAGE pilote l'arbre neuf. **Aucune ne
pilote l'autre**, et rien à l'écran ne doit laisser croire le contraire.

1. Ouvrir `/admin/shares/<id du partage d'arbre>`, encart **« Dossiers activables »**.
2. Cliquer **Suspendre** sur l'espace d'échange, laisser la file traiter, puis :
   ```bash
   sudo getfacl -c -E /var/sambaedu/ClassesSE5/Classe_<nom>/_echange
   ```

- [ ] 60.5-4a — l'entrée `group:classe_<base>` est passée à `---`, **et son miroir
      d'héritage aussi**.
- [ ] 60.5-4b — **le dossier existe toujours et son contenu est intact** : suspendre
      vide un accès, cela ne supprime rien.
      ```bash
      sudo ls -la /var/sambaedu/ClassesSE5/Classe_<nom>/_echange
      ```
- [ ] 60.5-4c — l'entrée de l'équipe, elle, n'a pas bougé (elle n'est pas suspendable).
- [ ] 60.5-4d — **l'arbre historique n'a pas bougé** : rejouer le scénario 60.5-2,
      diff toujours vide. En particulier, `/var/sambaedu/Classes/Classe_<nom>/_echange`
      est inchangé.
- [ ] 60.5-4e — **Réactiver** rend l'état d'origine, à l'identique.

### Scénario 60.5-5 — Créer une classe NEUVE matérialise son arbre

1. Créer un groupe de type `classe` depuis `/app/users/groups` (ou par la
   synchronisation d'annuaire).
2. Laisser la file traiter, puis regarder `/var/sambaedu/ClassesSE5`.

- [ ] 60.5-5a — le partage d'arbre existe dans la liste des répertoires réseau,
      **sans lettre** (rien ne dit qu'il doit être monté).
- [ ] 60.5-5b — l'arborescence complète est là (4 dossiers fixes ; aucun dossier
      d'élève tant que la classe est vide — c'est un arbre valide).
- [ ] 60.5-5c — rattacher un élève à la classe → après traitement, **son dossier
      personnel apparaît**, avec son entrée nominative.
- [ ] 60.5-5d — **UN SEUL** partage est créé automatiquement : l'arbre. La recette
      plate « profs → élèves », pourtant accrochée au même type de groupe, ne se
      matérialise **jamais** toute seule.
- [ ] 60.5-5e — **supprimer le groupe ne supprime RIEN sur le disque** : la ligne du
      partage survit (son lien de groupe est délié), l'arborescence reste. C'est
      l'administrateur qui décide, depuis la fiche du partage.

### Scénario 60.5-6 — « Profs → élèves » redevient matérialisable, et un sélecteur vide PARLE

Cette recette était **inutilisable depuis cinq semaines** : elle demandait un
groupe de type `equipe`, type que l'import d'annuaire ne produit plus (comptage :
`classe 302`, `equipe 0`). Le sélecteur s'affichait vide, et **muet**.

1. `/admin/settings/files?tab=lecteurs-reseaux` → « Créer depuis un template » →
   choisir **« Profs → élèves »**.

- [ ] 60.5-6a — l'écran ne demande plus une cible par rôle, mais **UN groupe de
      matérialisation** ; la liste propose les classes.
- [ ] 60.5-6b — l'**aperçu** dit les audiences résolues : « <classe> — encadrants »
      en lecture/écriture, « <classe> » en lecture seule.
- [ ] 60.5-6c — après matérialisation, `getfacl` sur le répertoire créé sous
      `/var/sambaedu/Partages` porte **exactement deux audiences** :
      `group:equipe_<base>:rwx` et `group:classe_<base>:r-x`.
- [ ] 60.5-6d — **le sélecteur vide parle.** Pour l'éprouver : choisir une recette
      dont un rôle n'a aucun candidat sur cette instance (par exemple sur une
      instance sans aucun groupe). Le message « aucun groupe éligible… » s'affiche,
      il **nomme le type attendu**, et le bouton « Matérialiser » est **désactivé**.
      Plus jamais un vide muet.

### Ce qui n'est PAS observable — et pourquoi le dire

- **Sans ouvrier de file, RIEN de ce qui passe par un écran ne se produit.** La
  bascule d'un dossier activable, la création d'un groupe, un rattachement d'élève :
  tous ENFILENT. L'écran dit « engagé » et **ne ment pas** — mais si la file ne
  tourne pas, l'arbre ne bouge jamais. Symptôme : `getfacl` inchangé alors que
  l'écran annonce l'inverse. Le contrôle `sambaedu:doctor --tag=queue` le constate
  désormais **a posteriori** (seuil : un travail disponible non pris depuis plus de
  15 minutes). Seule `shares:materialize-class-trees` s'exécute en direct.
- **L'arbre neuf n'est exposé en SMB nulle part.** Ne pas le chercher depuis un
  poste : `[classes]` continue de pointer sur l'arbre historique, et c'est voulu.
  Une absence de constat côté poste **n'est pas un succès**, ce n'est simplement
  pas la question.
- **Le sujet `pp_<base>` n'est émis nulle part.** La recette ne liste que le rôle
  d'arête d'encadrement : le groupe des enseignants d'une classe contient déjà ses
  professeurs principaux. Aucune entrée `pp_` ne doit apparaître dans l'arbre neuf ;
  si l'une s'y trouve, c'est un défaut.
- **Le plafond de zone** reste non piloté par SE5 (story suspendue) : aucun quota
  n'est posé sur l'arbre neuf. Dette datée, pas panne.
- **La suite automatisée ne tourne pas sur la VM** (pas de `pdo_sqlite`) : elle est
  exécutée sur la machine hôte.

### Checklist rapide — Story 60.5

- [ ] 60.5-P1 : 5 recettes seedées (`classe_se4` présente)
- [ ] 60.5-P2 : **`sudo` autorise la racine neuve** (sinon tout décline pour cette seule raison)
- [ ] 60.5-P3 : un ouvrier de file tourne
- [ ] 60.5-P4 : instantané `getfacl -R` de l'arbre historique pris AVANT
- [ ] 60.5-1a/b : simulation sans effet, puis arbre neuf complet sous la racine neuve
- [ ] 60.5-1d : second passage → **aucune écriture**
- [ ] 60.5-1e : les classes sautées sont **les mêmes** des deux côtés
- [ ] **60.5-2a : `getfacl -R` de l'arbre historique AVANT/APRÈS → diff VIDE**
- [ ] 60.5-3a/b : diff des deux arbres = **le sédiment de racine, et rien d'autre**
- [ ] 60.5-3d : compte des racines portant le sédiment, noté
- [ ] 60.5-4a/b/e : suspension → accès vidé, **données intactes**, retour à l'identique
- [ ] 60.5-4d : l'arbre historique n'a toujours pas bougé
- [ ] 60.5-5a/d/e : classe neuve → arbre complet, **un seul** partage auto, suppression sans destruction
- [ ] 60.5-6a/c/d : « profs → élèves » matérialisable, deux audiences compilées, sélecteur vide qui PARLE
- [ ] Suite automatisée verte sur l'hôte

## Story 62.4 — Les droits à quatre verbes, et ce que POSIX déclare ne pas savoir rendre

**Ce que cette story change sur le serveur, en une phrase :** les droits du plan de
fichiers ne se disent plus « lecture seule / lecture-écriture » mais en **quatre
verbes combinables** — `lire`, `editer`, `creer`, `supprimer` — et, **sur une
instance en place, absolument rien ne bouge sur le disque**. C'est ce dernier point
que la vérification terrain doit établir, pas le premier.

**Pourquoi rien ne doit bouger.** La conversion des recettes stockées est
volontairement la plus généreuse possible (décision Henri Q3, 2026-08-08) :

| recette AVANT | recette APRÈS | ce que le disque porte |
|---|---|---|
| `ro` | `lire` | `r-x` — identique |
| `rw` | `lire` + `editer` + `creer` + `supprimer` | `rwx` — identique |

Aucune recette livrée ne demande donc « déposer sans effacer », la seule
combinaison qui ferait poser un drapeau nouveau sur un dossier. Les combinaisons
fines deviennent EXPRIMABLES, elles ne sont pas encore UTILISÉES : l'écran qui
permettra de les composer est la story 62.6.

**Ce que POSIX ne sait pas rendre, et qu'il DIT désormais.** Deux cas, tous deux
inatteignables avec les recettes d'aujourd'hui, mais qui apparaîtront dès 62.6 :

- **« supprimer sans créer »** — les deux verbes passent par la même permission
  d'écriture du dossier. L'accorder donnerait aussi la création. Le verbe n'est
  donc pas rendu, le reste de l'octroi l'est, et le nœud remonte
  « non supporté par ce backend » avec sa raison, en français ;
- **« créer sans supprimer » (déposer sans effacer)** — rendu de façon APPROCHÉE
  par le drapeau `t` (sticky) sur le dossier : le déposant peut encore retirer SES
  PROPRES dépôts. C'est une dégradation réelle, et elle est déclarée. Elle n'est
  PAS posée si un autre octroi du même dossier porte `supprimer` — elle lui
  retirerait l'effacement du travail des autres.

### Prérequis

- **62.4-P1** — instance avec des partages **déjà provisionnés** par SE5 (sinon il
  n'y a rien à comparer avant/après).
- **62.4-P2** — accès `root` au serveur de fichiers pour `getfacl -R` et `stat`.
- **62.4-P3** — la migration `2026_08_08_140000_migrate_directory_template_access_to_verbs`
  n'a PAS encore été jouée au moment de l'instantané « avant ».

### 62.4-1 — L'INSTANTANÉ AVANT/APRÈS : la vérification qui compte

C'est LA vérification de cette story. Tout le reste est secondaire.

```bash
# AVANT toute migration, sur le serveur :
find /var/sambaedu/Partages /var/sambaedu/ClassesSE5 -maxdepth 3 \
  -type d -exec getfacl -p {} \; > ~/acl-avant-62-4.txt

# Déployer + migrer + re-seeder les recettes :
php artisan migrate
php artisan db:seed --class=DirectoryTemplateSeeder

# Rejouer une réconciliation sur quelques partages représentatifs
# (au moins : un partage plat ro+rw, un arbre de classe, un dossier d'échange).

find /var/sambaedu/Partages /var/sambaedu/ClassesSE5 -maxdepth 3 \
  -type d -exec getfacl -p {} \; > ~/acl-apres-62-4.txt

diff ~/acl-avant-62-4.txt ~/acl-apres-62-4.txt
```

- [ ] **62.4-1a** — `diff` **VIDE**. Aucune entrée n'a bougé, aucun mode n'a bougé.
      Un diff non vide est un ÉCHEC de la story, pas un ajustement à documenter :
      il signifierait qu'une recette a changé de sens à la migration.
- [ ] **62.4-1b** — aucun dossier ne porte le drapeau `t`. À vérifier
      explicitement, parce que c'est le seul changement de MODE que cette story
      pourrait provoquer :
      ```bash
      find /var/sambaedu/Partages /var/sambaedu/ClassesSE5 -type d -perm -1000 | head
      ```
      **Attendu : aucune ligne.**
- [ ] **62.4-1c** — une seconde réconciliation sur un partage déjà conforme n'émet
      **aucune écriture** (l'idempotence tient malgré la nouvelle lecture de
      drapeau). Le rapport dit « Déjà conforme ».

### 62.4-2 — LE STICKY FACE À SAMBA : la question ouverte

**Pourquoi c'est une question et pas une case à cocher.** Le drapeau `t` est un
mécanisme POSIX. Un partage SMB, lui, présente aux clients Windows un modèle de
droits NT, et `smbd` fait la traduction. Deux comportements ne sont **pas** connus
d'avance et doivent être MESURÉS avant que 62.6 ne rende la combinaison
choisissable :

1. le membre de `domain admins` est-il lui aussi soumis à la restriction ? (En
   POSIX pur, seuls le propriétaire du fichier, le propriétaire du dossier et
   `root` échappent au sticky. `domain admins` a un accès `rwx` par ACL, mais n'est
   ni l'un ni l'autre.)
2. le refus de suppression remonte-t-il au client Windows comme un refus
   compréhensible, ou comme une erreur générique illisible ?

Protocole (à faire sur un partage de test, JAMAIS sur un partage servi) :

```bash
# Poser la situation à la main sur un dossier de test :
mkdir -p /var/sambaedu/Partages/test62_4
setfacl -m group:classe_3emea:rwx /var/sambaedu/Partages/test62_4
chmod +t /var/sambaedu/Partages/test62_4
# Un fichier déposé par un élève A :
sudo -u <eleveA> touch /var/sambaedu/Partages/test62_4/depot-A.txt
```

- [ ] **62.4-2a** — depuis une session Windows de l'**élève B** (même groupe) :
      la suppression de `depot-A.txt` est-elle **refusée** ? Noter le message exact.
- [ ] **62.4-2b** — l'élève **A** peut-il supprimer SON fichier ? (Attendu : oui —
      c'est précisément la dégradation assumée.)
- [ ] **62.4-2c** — depuis une session **membre de `domain admins`** : la
      suppression est-elle possible ? **Noter la réponse** : elle décide si le
      libellé de 62.6 doit avertir que la restriction s'applique aussi aux
      administrateurs.
- [ ] **62.4-2d** — l'onglet « Sécurité » de Windows sur ce dossier : le mappage NT
      montre-t-il quelque chose d'incohérent avec ce qui vient d'être observé ?
      Copie d'écran si oui.

À l'issue : **nettoyer** (`chmod -t`, `setfacl -b`, `rmdir`).

### 62.4-3 — La sélection par type d'objet (`find`) : à autoriser AVANT 62.6

Deux combinaisons de verbes exigent de poser un niveau **différent** sur les
dossiers et sur les fichiers (par exemple « lire + éditer » : écrire dans les
fichiers sans pouvoir en créer ni en retirer). L'outil de listes d'accès n'a aucun
sélecteur de type : SE5 émet alors

```
sudo find <chemin> -type d -exec setfacl -P -m <entrée> {} +
sudo find <chemin> -type f -exec setfacl -P -m <entrée> {} +
sudo find <chemin> -type d -exec chmod +t {} +
```

**Aucune recette d'aujourd'hui n'atteint ces gestes** — ils n'apparaîtront qu'avec
62.6. Mais `find` n'est pas dans la liste blanche d'élévation des instances.

- [ ] **62.4-3a** — vérifier ce que dit la liste blanche actuelle :
      ```bash
      grep -rn "find" /etc/sudoers.d/ | head
      ```
- [ ] **62.4-3b** — **avant le déploiement de 62.6**, ajouter `find` à la liste
      blanche (ou décider explicitement de ne pas le faire, auquel cas les
      combinaisons différenciées échoueront — bruyamment, en nommant leur cause,
      jamais en posant un droit approximatif).
- [ ] **62.4-3c** — tant que 62.6 n'est pas livrée, confirmer qu'**aucune**
      commande `find` n'est émise en exploitation :
      ```bash
      grep -c "sudo find" /var/log/sambaedu/*.log
      ```
      **Attendu : 0.**

### 62.4-4 — L'écran de conformité parle verbes

- [ ] **62.4-4a** — sur la fiche d'un partage, l'encart de conformité affiche
      « Lire » ou « Lire + Éditer + Créer + Supprimer », jamais `ro`, `rw`, `rwx`
      ni aucun mode.
- [ ] **62.4-4b** — sur un partage dont on a retiré un droit à la main
      (`setfacl -m group:X:rx`), l'écart s'affiche avec **les deux listes de
      verbes**, attendue et constatée.
- [ ] **62.4-4c** — l'aperçu d'une recette (modale « Créer depuis un template »)
      montre toujours les badges « Lire »/« Modifier » des assignations : ce
      vocabulaire-là, celui du MONTAGE, n'a pas changé et ne doit pas changer.

### 62.4-5 — Recettes non migrées : le bruit est voulu

- [ ] **62.4-5a** — si une instance porte une recette modifiée à la main et restée
      au vocabulaire `access`, sa validation échoue avec un message qui NOMME le
      vocabulaire abandonné (« champ inconnu `access` » ou « vocabulaire ABANDONNÉ »).
      C'est le comportement attendu : une recette non migrée doit être bruyante,
      jamais lue de travers.

### Checklist rapide — Story 62.4

- [ ] 62.4-P1/P2/P3 : instance provisionnée, accès root, instantané pris AVANT
- [ ] **62.4-1a : `getfacl -R` avant/après → diff VIDE** (la vérification pivot)
- [ ] 62.4-1b : aucun dossier ne porte le drapeau `t`
- [ ] 62.4-1c : second passage → aucune écriture
- [ ] 62.4-2a/b/c/d : effet réel du sticky sur SMB, **réponse `domain admins` notée**
- [ ] 62.4-3a/b : `find` dans la liste blanche, ou décision explicite, AVANT 62.6
- [ ] 62.4-3c : aucune commande `find` émise aujourd'hui
- [ ] 62.4-4a/b/c : l'écran parle verbes, l'assignation reste binaire
- [ ] 62.4-5a : une recette non migrée est refusée BRUYAMMENT
- [ ] Suite automatisée verte sur l'hôte


## Story 62.5 — La traversée dérivée, et la recette qui refuse un dossier inatteignable

**Ce que cette story change sur le serveur, en une phrase :** rien — et c'est ce
« rien » qu'il faut établir. Le mécanisme qui rend un dossier profond ATTEIGNABLE
existe désormais, il se calcule à chaque passage, et **sur les recettes livrées il
ne produit aucune entrée** : le planificateur rend l'ensemble vide sur chacun de
leurs nœuds.

**Le problème, dit sur le mécanisme.** Pour ouvrir `a/b/c`, il faut pouvoir
traverser `a` et `a/b`. Jusqu'ici le compilateur travaillait nœud par nœud : un rôle
octroyé sur `a/b/c` mais absent de `a` n'avait aucune entrée sur `a`, et la pose de
`a` referme le dossier pour tout le monde. La recette était « conforme », le rapport
vert, le dossier un mirage.

**Ce que SE5 fait désormais, et surtout ce qu'il ne fait pas :**

- il DÉRIVE un « couloir d'accès » sur chaque ancêtre déclaré, pour les rôles
  d'audience servis en profondeur ;
- ce couloir **n'accorde rien de plus** : on passe devant la porte, on n'entre pas.
  Le rôle qui lit `a/b/c` **ne peut pas lister `a`**, ni y lire, ni y écrire ;
- il ne dérive **jamais** pour un dossier PERSONNEL (un couloir par élève ferait
  250 entrées nominatives sur la racine d'une classe). L'atteignabilité des dossiers
  personnels est garantie autrement : la recette est **refusée à l'écriture** si un
  ancêtre n'accorde rien à une audience qui contient ces membres ;
- il ne perce **jamais** une suspension : quand l'espace d'échange est fermé, ce qui
  est dessous l'est aussi.

**La décision de conception, pour mémoire :** la traversée est calculée dans le
backend, PAS dans le plan. Le plan continue de ne dire QUE ce que l'administrateur a
écrit — c'est ce qui évitera, le jour de l'Epic 61, qu'un « octroi de traversée » se
transforme en accès réel propagé à tout un sous-arbre sur un plan de fichiers
distant.

### Prérequis

- **62.5-P1** — instance avec des partages **déjà provisionnés** par SE5 (arbre de
  classe compris), sinon il n'y a rien à comparer.
- **62.5-P2** — accès `root` au serveur de fichiers pour `getfacl -R`.
- **62.5-P3** — pour la partie « première traversée réelle » (62.5-3), l'éditeur
  d'arborescence de la **story 62.6** doit être livré : aucune recette d'aujourd'hui
  ne produit de couloir, et c'est précisément le point.

### 62.5-1 — L'INSTANTANÉ AVANT/APRÈS : aucune entrée ne bouge

C'est LA vérification de cette story, et elle est identique dans sa forme à celle de
la 62.4.

```bash
# AVANT déploiement, sur le serveur :
find /var/sambaedu/Partages /var/sambaedu/ClassesSE5 -maxdepth 3 \
  -type d -exec getfacl -p {} \; > ~/acl-avant-62-5.txt

# Déployer, puis rejouer une réconciliation sur les mêmes partages
# (au moins : un partage plat, un arbre de classe avec des dossiers élèves,
#  un dossier d'échange ACTIF et un SUSPENDU).

find /var/sambaedu/Partages /var/sambaedu/ClassesSE5 -maxdepth 3 \
  -type d -exec getfacl -p {} \; > ~/acl-apres-62-5.txt

diff ~/acl-avant-62-5.txt ~/acl-apres-62-5.txt
```

- [ ] **62.5-1a — le diff est VIDE.** Aucune entrée ajoutée, aucune retirée, aucun
      mode changé. Si une entrée en `--x` apparaît quelque part, **arrêter et
      remonter** : la dérivation sur-octroie sur une recette livrée, ce qui n'est pas
      censé être possible.
- [ ] **62.5-1b — aucune entrée `--x` nulle part** :
      `grep -c ':--x' ~/acl-apres-62-5.txt` doit rendre `0`.
- [ ] **62.5-1c — second passage : aucune écriture.** Relancer la réconciliation ;
      les nœuds rendent tous `conforme` et le journal ne montre aucune commande de
      pose. C'est l'idempotence, vérifiée AVEC le nouveau calcul dans la boucle.
- [ ] **62.5-1d — aucun bruit de dérive.** Ouvrir l'encart de dérive de deux ou
      trois partages : ils doivent être `conforme`, sans « entrée en trop » ni détail.

### 62.5-2 — Le jeu de commandes n'a pas bougé

- [ ] **62.5-2a** — aucune commande nouvelle : la story n'ajoute **aucun binaire**.
      Le couloir se pose avec la commande de pose déjà en liste blanche, simplement
      privée de son option de descente (`setfacl -P -m … <chemin>`, **sans `-R`**).
      Rien à ajouter à la liste blanche d'élévation.
- [ ] **62.5-2b** — vérifier dans le journal d'un passage qu'aucune commande de pose
      de couloir n'est émise aujourd'hui (conséquence directe de 62.5-1b).

### 62.5-3 — LA PREMIÈRE TRAVERSÉE RÉELLE (à jouer APRÈS la story 62.6)

Ces vérifications demandent une recette qui produise un couloir, donc l'éditeur
d'arborescence. **Elles sont la seule façon d'établir que le mécanisme fait ce que
le code croit qu'il fait** — tout le reste est vérifié automatiquement sur l'hôte.

Situation à construire : un arbre où un rôle reçoit un octroi sur un nœud PROFOND
(par exemple `_travail/prive`) **sans aucun octroi sur `_travail` ni sur la racine**.

- [ ] **62.5-3a — le couloir est posé, et seulement lui.**
      `getfacl -p <racine>/_travail` montre une entrée `group:<rôle>:--x` — pas
      `r-x`, pas `rwx` — et **aucune** entrée `default:group:<rôle>:…`.
- [ ] **62.5-3b — le couloir ne descend pas.** `getfacl -p` sur un sous-dossier de
      `_travail` créé à la main, et sur un fichier qui s'y trouve : **aucune** entrée
      pour ce rôle. Une pose récursive se verrait ici.
- [ ] **62.5-3c — la traversée n'accorde rien de plus, en session POSIX/SSH.** Avec
      un compte membre du rôle :
      - `ls <racine>/_travail` → **refusé** (on ne liste pas) ;
      - `cd <racine>/_travail/prive && ls` → **autorisé** (on atteint le dossier
        profond) ;
      - `touch <racine>/_travail/x` → **refusé**.
- [ ] **62.5-3d — LE POINT À OBSERVER EN VRAI : le même essai depuis SMB.** Monter
      le partage depuis un poste Windows avec ce compte, puis :
      - le dossier `_travail` doit être **inaccessible en listage** ;
      - le chemin complet `\\serveur\<partage>\_travail\prive` doit s'ouvrir en
        tapant l'adresse directement.
      **Noter le comportement observé, quel qu'il soit.** Le mappage NT ACL de
      « traverser sans lire » n'est pas garanti iso-POSIX : le serveur de fichiers
      peut traduire la traversée-sans-lecture d'une façon qui la rend inutilisable
      pour un client Windows (dossier invisible ET inatteignable), ou au contraire
      trop permissive. C'est la seule inconnue de cette story, et elle ne se lève que
      sur une vraie machine.
- [ ] **62.5-3e — retrait.** Retirer l'octroi profond, réconcilier, vérifier que
      l'entrée `--x` a **disparu** de `_travail` : un couloir devenu caduc ne survit
      pas à un passage.
- [ ] **62.5-3f — une suspension n'est pas percée.** Sur un espace d'échange
      SUSPENDU portant un nœud profond accordé au même rôle : l'entrée du rôle sur
      l'espace d'échange doit rester **`---`** (la suspension matérialisée), pas
      `--x`.

### 62.5-4 — La recette refuse ce qui serait inatteignable

À jouer depuis l'éditeur (62.6) ou, d'ici là, en tinker sur une recette de test.

- [ ] **62.5-4a** — déclarer `a/b/c` sans déclarer `a/b` : refus nommant le chemin
      fautif **et** l'ancêtre manquant.
- [ ] **62.5-4b** — déclarer un nœud sous un dossier « à contenu libre » : refus
      disant que le contenu de cet ancêtre n'est pas gouverné par le plan.
- [ ] **62.5-4c** — déclarer un dossier par membre dont un ancêtre n'accorde rien à
      une audience contenant ces membres : refus nommant l'ancêtre ET le rôle
      d'arête. Le message doit rester en français métier, sans un mot du mécanisme.
- [ ] **62.5-4d** — les 5 recettes livrées restent valides sans modification
      (`php artisan db:seed --class=DirectoryTemplateSeeder` passe, et une
      réconciliation d'arbre de classe reste `conforme`).

### Checklist rapide — Story 62.5

- [ ] 62.5-P1/P2 : instance provisionnée, accès root, instantané pris AVANT
- [ ] **62.5-1a : `getfacl` avant/après → diff VIDE** (la vérification pivot)
- [ ] 62.5-1b : `grep -c ':--x'` rend 0
- [ ] 62.5-1c : second passage → aucune écriture
- [ ] 62.5-1d : encart de dérive propre, aucun bruit
- [ ] 62.5-2a/b : aucun binaire nouveau, aucune pose de couloir aujourd'hui
- [ ] 62.5-3a→3f (APRÈS 62.6) : couloir posé, non descendu, `ls` refusé / `cd` OK,
      **comportement SMB NOTÉ**, retrait effectif, suspension non percée
- [ ] 62.5-4a/b/c/d : les quatre refus parlent, les recettes livrées passent
- [ ] Suite automatisée verte sur l'hôte



---

*Dernière mise à jour : 2026-08-08 (Story 62.5 — la traversée DÉRIVÉE et la validation parent→enfant : la traversée est calculée dans le BACKEND (`PosixTraversalPlanner`, décision docblockée et épinglée par test — le plan, sa clôture, sa sérialisation et `PlanStateComparator` restent à ZÉRO DIFF), un « couloir d'accès » dérivé des octrois profonds RENDUS des descendants déclarés, posé en TÊTE SEULE sans miroir d'héritage ni entrée fichier et sans aucun binaire nouveau, qui **n'accorde rien de plus** (le rôle qui lit `a/b/c` ne peut pas lister `a`) ; le NOMINATIF ne dérive jamais (sa contrepartie est une règle de couverture à la validation), une suspension n'est jamais percée, un octroi rendu vide n'ouvre aucun couloir ; l'inspection FILTRE les couloirs attendus comme des entrées structurelles (table de reprojection 62.4 INCHANGÉE, zéro bruit de dérive) et DIT ceux qui manquent ; quatre règles parent→enfant nouvelles sur `assertValidTreeSpec()` (ancêtre non déclaré, nœud sous contenu libre, dossiers par membre imbriqués de rôles d'arête différents, couverture des membres énumérés) — **impact sur les référentiels figés : ZÉRO littéral, la dérivation rend l'ensemble VIDE sur toutes les recettes livrées, épinglé par un test dédié** ; aucune UI, aucune migration, rien de persisté)*

*Mise à jour précédente : 2026-08-08 (Story 62.4 — les droits à quatre verbes : `PlanGrant` porte une LISTE de verbes `lire|editer|creer|supprimer` (contrat sémantique Q2 au docblock — éditer = contenu d'un fichier existant SEULEMENT, renommer = créer + supprimer, déplacer = supprimer à la source + créer à destination), `FilePlan::VERSION` → 2 avec refus NOMMÉ de l'ancienne clé `access`, migration de données Q3 (`ro` → `lire`, `rw` → les quatre — le seul mappage qui ne retire aucun accès), matrice de dégradation POSIX DÉRIVÉE de deux axes (fichier / dossier) + drapeau de nœud, `non_exprimable` désormais PRODUIT par le backend posix (supprimer sans créer ; créer sans supprimer sur un nœud mixte) sans jamais se confondre avec `non_implemente`, `find` entre au jeu fermé pour la pose différenciée et la restriction de suppression — **le référentiel figé n'a pas changé d'un caractère : sur une instance en place, aucune entrée ne bouge** ; assignations, `AclFormat::modeToAccess()` et `PosixSubjectProjector` INTOUCHÉS)*

*Mise à jour précédente : 2026-08-04 (Story 60.3 — le contrat `FileBackend` et le backend `preview` : interface à 5 méthodes de forme distante (name/provision/deprovision/inspect/quota, aucun `bool`), enums fermées `FileBackendName` (posix|preview) / `FileBackendOutcome` (7 états, dont `non_exprimable` PERMANENT ≠ `non_implemente` TEMPORAIRE ≠ `non_execute` PAR CONCEPTION) / `FileBackendObservation` (4 statuts), rapports à COMPLÉTUDE VALIDÉE À LA CONSTRUCTION (un rapport qui omet un nœud est inconstructible) et `detail` obligatoire au constructeur, racine `PlanNode::ROOT_PATH` devenue nœud de première classe, colonne `network_shares.backend` NOT NULL défaut `posix` (hors `$fillable`, non éditable), registre par nom fail-closed, `SharePlanProjector` (partage plat → plan neutre), squelette Nextcloud JETABLE vert contre l'instance réelle — PREMIER LIVRABLE VISIBLE DE L'EPIC : badge backend + aperçu du plan avant application ; services d'exécution et seeder INTOUCHÉS, aucun flux ne route par la colonne)*

*Mise à jour précédente : 2026-08-04 (Story 60.2 — résolution de rôle par règle : enum fermée `RoleResolutionStrategy` (self · designated [défaut, iso-34.3] · pattern · edge_role) portée par la clé additive `resolution` de `roles_spec` et validée sur le modèle, colonne additive nullable UNIQUE `attached_group_type`, assembleur `TreePlanService` (SQL seulement, hors namespace pur, test d'architecture étendu), décomposition « matière × classe » en deux segments de chemin, table canonique `EdgeRoleLabels` recâblée sur les trois écrans — SEUL changement visible : les libellés de rôle d'arête suivent le type de groupe ; la chaîne groupe→plan est livrée COMPLÈTE et DORMANTE, services d'exécution et seeder INTOUCHÉS)*

*Mise à jour précédente : 2026-08-04 (Story 60.1 — la recette devient un arbre : colonnes additives nullables `path_pattern`/`nodes_spec` sur `directory_templates`, enum fermée `PlanNodeNature` (4 natures), DTO de plan neutres + résolution PURE `PlanResolver`, clôture calculée par nœud (AC9, issue du spike 60.0), garde de neutralité + test d'architecture verrouillant la ligne de coupe ; AUCUN effet observable en production — services d'exécution et seeder INTOUCHÉS)*

*Mise à jour précédente : 2026-06-30 (Story 34.3 — Templates de répertoire : table `directory_templates` + `DirectoryTemplateSeeder` (4 recettes, Q3 option B) + `DirectoryTemplateService::materialize` (transaction + validation réutilisée 34.2, mapping de maille non redérivé) + 2e modale « Créer depuis un template » sur `/app/shares` (formulaire dynamique, aperçu, gating `networkshare.manage`) ; casiers « élèves→profs » par-élève reportés 34.x ; socle figé INTOUCHÉ ; **post-review** : surfaçage warnings AC2 [#1], pré-check unicité service [M-1], garde-fou seeder [M-2], test cible manquante [#2])*

*Mise à jour : 2026-08-05 (Story 60.5 — le seed SE4 dans une racine NEUVE : 5ᵉ recette `classe_se4` (six nœuds, racine comprise, `edge_roles: [manager]` seul), zone logique `PlanAnchor` portée par le plan et traduite par la garde de chemin SEULE (double ancrage `reseau`/`classes`, clé `filesystem.class_trees_root`, défaut `/var/sambaedu/ClassesSE5`), origine `directory_template_id`/`user_group_id`/`node_activation` sur `network_shares`, déclencheurs de création/appartenance SCOPÉS aux recettes d'arbre, commande `shares:materialize-class-trees`, bascule des dossiers activables + dernier rapport par nœud sur la fiche du partage, `profs_to_eleves` RECÂBLÉE sur le rôle d'arête + sélecteur vide qui PARLE, contrôle `sambaedu:doctor --tag=queue` — **l'arbre de classe HISTORIQUE, `ShareService`, `AclService` et `shares:resync-class` sont INTOUCHÉS : zéro diff, et SE5 n'a plus aucun chemin d'écriture vers `/var/sambaedu/Classes`**)*

---

## Story 61.1 — Nextcloud en chemin d'accès (external storage)

**Ce que cette story change, en une phrase :** Nextcloud **monte les partages SMB
déjà existants** (`partages` et `users`) et devient un chemin d'accès de plus vers
les mêmes fichiers, avec les mêmes droits — **rien sur le disque ne bouge, aucun
droit n'est écrit nulle part, et le lecteur réseau ne change pas d'un octet**.

**Le mécanisme, et pourquoi il rend le cloisonnement gratuit.** Le montage est en
« identifiants de connexion, enregistrés en session »
(`password::sessioncredentials`) : à chaque accès web, Nextcloud se présente à
Samba **avec les identifiants de l'utilisateur connecté**. C'est donc l'ACL POSIX
du kernel qui tranche, exactement comme pour le lecteur H:/K:. Aucun droit n'est
dupliqué côté Nextcloud, aucun credential utilisateur n'y est stocké, et
`hide unreadable = Yes` (posé en `[global]` côté Samba) masque au web ce qu'il
masque déjà au lecteur. **Corollaire** : l'utilisateur doit se connecter à
Nextcloud avec ses identifiants AD — d'où le provisionnement des comptes et la
propagation du mot de passe.

**Les deux montages, et rien d'autre :**

| Montage | Cible SMB | Équivalent poste |
|---|---|---|
| « Partages » | `\\<se4fs>\partages\` (racine `/var/sambaedu/Partages`) | répertoires réseau gérés (M:..Z:) |
| « Documents » | `\\<se4fs>\users\$user\` (`$user` substitué par Nextcloud) | lecteur K: |

L'arbre de classe (`H:`) **n'est PAS monté** : le partage SMB de la zone
`ClassesSE5` n'existe pas encore côté Samba. Dette nommée, pas oubli.

### Prérequis d'instance — à vérifier AVANT tout le reste

- [ ] **61.1-P1** — l'app « Stockage externe » est active :
  `occ app:enable files_external`. Sans elle, l'endpoint d'administration répond
  `404` et le « Tester la connexion » dit « app files_external absente ».
- [ ] **61.1-P2** — **le backend SMB est disponible sur l'hôte Nextcloud** :
  paquet `smbclient` **ou** extension `php-smbclient`. **⚠️ PIÈGE MESURÉ
  (2026-08-08) : installer le paquet NE SUFFIT PAS — la détection des backends est
  mise en cache, il faut REDÉMARRER le service Nextcloud.** Sans cela le
  provisionnement échoue en `422 Invalid storage backend "smb"`, avec l'app
  pourtant active et le compte pourtant admin.
- [ ] **61.1-P3** — un **compte administrateur** de l'instance, et un **app
  password** généré pour lui (Nextcloud › Paramètres personnels › Sécurité). Le
  mot de passe de session ne convient pas si la double authentification est
  active.
- [ ] **61.1-P4** — **l'instance Nextcloud atteint le serveur SMB** (réseau, DNS,
  ports 445). C'est le prérequis dont AUCUN test ne peut rendre compte (voir « Ce
  qui n'est PAS observable »).
- [ ] **61.1-P5** — un ouvrier de file tourne (le bouton « Provisionner » enfile).

### 61.1-1 — La configuration et les trois diagnostics

- [ ] **61.1-1a** — `/admin/settings/files`, onglet « Personnels et partagés » :
  la carte **« Accès Nextcloud »** n'est plus grisée. L'activer révèle le bloc de
  connexion (URL, compte admin, app password, serveur SMB, vérification TLS).
- [ ] **61.1-1b** — saisir une URL **sans schéma** (`cloud.etab.fr`) : refusée à la
  saisie, message « doit commencer par http:// ou https:// ». Un slash final est
  toléré.
- [ ] **61.1-1c** — saisir l'app password puis quitter le champ : le champ **se
  vide**, la mention passe à « Un app password est enregistré (chiffré) ».
  **Recharger la page : le secret n'est jamais réaffiché.** Contrôle
  complémentaire : `Ctrl+U` (source de la page) → le secret n'y figure pas.
- [ ] **61.1-1d** — « Tester la connexion » avec une **URL injoignable** → message
  « Instance Nextcloud injoignable à l'adresse … ».
- [ ] **61.1-1e** — « Tester la connexion » avec un **compte non administrateur** →
  message « … n'est pas administrateur de l'instance ».
- [ ] **61.1-1f** — « Tester la connexion » avec `files_external` **désactivée**
  (`occ app:disable files_external`) → message citant `files_external` et la
  commande d'activation. **Les trois messages doivent être différents** : c'est
  tout leur intérêt (chacun se corrige à un endroit différent).
- [ ] **61.1-1g** — configuration incomplète (compte admin vide, ou secret absent) :
  « Tester la connexion » et `php artisan nextcloud:provision` **nomment ce qui
  manque** et n'émettent **aucun appel**.

### 61.1-2 — Le provisionnement des montages

- [ ] **61.1-2a** — `php artisan nextcloud:provision --dry-run` : le tableau annonce
  « serait créé » pour les deux montages. **Vérifier dans l'écran d'administration
  Nextcloud (Paramètres › Administration › Stockages externes) : RIEN n'a été
  créé.**
- [ ] **61.1-2b** — `php artisan nextcloud:provision --mounts-only` : les deux
  montages apparaissent côté Nextcloud, type **SMB/CIFS**, authentification
  « Identifiants de connexion, enregistrés en session », **aucun groupe ni
  utilisateur en « Disponible pour »** (= tous).
- [ ] **61.1-2c** — **REJEU** : relancer la même commande. Le rapport dit « déjà
  conforme » pour les deux, et **l'écran Nextcloud contient toujours DEUX entrées,
  pas quatre**. C'est le contrôle pivot : `files_external` ne dédoublonne pas de
  lui-même.
- [ ] **61.1-2d** — renommer un des deux montages **côté Nextcloud** (« Partages »
  → « Zzz »), relancer : le rapport dit « mis à jour », le nom revient à
  « Partages », et **aucune entrée supplémentaire** n'est créée.
- [ ] **61.1-2e** — créer **à la main** côté Nextcloud un troisième stockage
  externe quelconque, relancer : il est **toujours là, inchangé**, et le rapport ne
  le mentionne pas. SE5 ne gouverne que ce qu'il a déclaré.
- [ ] **61.1-2f** — ⚠️ **le statut affiché par Nextcloud sur ces deux montages sera
  rouge, « Storage unauthorized / Session unavailable ». C'EST NORMAL et ce n'est
  pas un échec** : avec des identifiants de session, il n'y a personne à
  authentifier hors session utilisateur, donc le statut est inévaluable. SE5 ne le
  lit pas. Le seul verdict qui compte est celui du parcours 61.1-4.

### 61.1-3 — Les comptes utilisateurs

- [ ] **61.1-3a** — `php artisan nextcloud:provision --users-only` sur une instance
  **à synchro LDAP** : compteur `adoptés` = population AD active, `créés` = 0,
  `introuvables` = 0.
- [ ] **61.1-3b** — même commande sur une instance **sans comptes** : compteur
  `introuvables` non nul, et **AUCUN compte n'est créé côté Nextcloud**. Vérifier
  dans Nextcloud › Utilisateurs. C'est délibéré : un compte fabriqué avec un mot de
  passe aléatoire serait un compte auquel personne ne peut se connecter, et le
  compteur passerait au vert pour rien.
- [ ] **61.1-3c** — **rejeu** : relancer. Les utilisateurs déjà résolus ne
  provoquent **aucun appel** à l'instance (l'identité est cachée en base). Contrôle
  : `SELECT login, nextcloud_user_id FROM users WHERE nextcloud_user_id IS NOT NULL LIMIT 5;`
- [ ] **61.1-3d** — les comptes `source = 'federated'` (techniciens controlHub)
  sont comptés en « hors périmètre » et **jamais** interrogés : ils n'ont ni home
  ni mot de passe AD.
- [ ] **61.1-3e** — **créer un utilisateur** dans SE5 (`/app/users/new`) : le compte
  Nextcloud est créé **au fil de l'eau** avec le mot de passe AD. Vérifier la
  connexion web de ce compte immédiatement après.
- [ ] **61.1-3f** — **changer le mot de passe** de cet utilisateur (fiche
  utilisateur, ou réinitialisation en masse) : sa connexion web Nextcloud
  fonctionne **avec le nouveau mot de passe**. Sans cette propagation, le montage
  cesserait d'authentifier au premier changement.
- [ ] **61.1-3g** — désactiver la capacité « Accès Nextcloud », créer un
  utilisateur, changer un mot de passe : **aucun appel** n'est émis vers l'instance
  (contrôle : journal applicatif sans entrée `nextcloud.*`).

### 61.1-4 — LE PARCOURS RÉEL (celui qui vaut la story)

- [ ] **61.1-4a** — se connecter à Nextcloud **en tant qu'élève**, avec ses
  identifiants AD. Les dossiers **« Partages »** et **« Documents »** sont visibles
  dans « Fichiers ».
- [ ] **61.1-4b** — « Documents » montre **le contenu du home de cet élève**, et
  celui-là seulement.
- [ ] **61.1-4c** — **LE CONTRÔLE DE CLOISONNEMENT** : sous « Partages », l'élève
  voit **exactement** ce qu'il voit depuis son lecteur réseau sur le poste — et
  **pas `_profs`**. Comparer côte à côte : explorateur Windows d'un côté, Nextcloud
  de l'autre. Toute différence est un défaut, dans un sens comme dans l'autre.
- [ ] **61.1-4d** — se connecter **en tant qu'enseignant** : il voit `_profs`, et
  peut y écrire.
- [ ] **61.1-4e** — créer un fichier depuis Nextcloud, puis le retrouver **depuis le
  poste** sur le lecteur réseau (et inversement). C'est le même octet, pas une
  copie.
- [ ] **61.1-4f** — **retirer un accès côté SE5** (désassigner l'utilisateur du
  répertoire réseau, réconcilier), puis rafraîchir Nextcloud : le dossier disparaît
  de sa vue. L'autorité n'a pas bougé de place.

### 61.1-5 — `getfacl` : le contrôle de NON-EFFET

Cette story ne doit émettre **aucune commande système**. Le contrôle est donc un
contrôle de **non-effet**, et il se fait au diff.

```bash
# AVANT toute activation de la capacité
getfacl -R /var/sambaedu/Partages > /root/acl-avant-61-1.txt
getfacl -R /var/sambaedu/Classes  >> /root/acl-avant-61-1.txt
getfacl -R /home                  >> /root/acl-avant-61-1.txt

# … activer la capacité, provisionner, dérouler 61.1-2, 61.1-3 et 61.1-4 …

getfacl -R /var/sambaedu/Partages > /root/acl-apres-61-1.txt
getfacl -R /var/sambaedu/Classes  >> /root/acl-apres-61-1.txt
getfacl -R /home                  >> /root/acl-apres-61-1.txt

diff /root/acl-avant-61-1.txt /root/acl-apres-61-1.txt
```

- [ ] **61.1-5a** — **le diff est VIDE.** Toute ligne est un défaut : cette story
  n'a aucun chemin d'écriture vers le système de fichiers.
  (Réserve honnête : si le parcours 61.1-4e a créé un fichier depuis Nextcloud, ce
  fichier apparaîtra — c'est une écriture d'UTILISATEUR par Samba, pas une écriture
  de SE5. Le distinguer, ou prendre l'instantané avant 61.1-4e.)

### Ce qui n'est PAS observable — à ne pas confondre avec un succès

- **Le montage FONCTIONNEL exige que l'instance Nextcloud atteigne le serveur SMB.**
  Aucun test automatisé n'en rend compte : ni la suite sur l'hôte (aucun réseau),
  ni le test d'intégration contre l'instance de sondage (qui prouve le CANAL
  d'écriture de la configuration, avec une cible SMB fictive). Seul le parcours
  61.1-4 le prouve.
- **Le statut affiché par Nextcloud sur les deux montages n'est pas un verdict**
  (voir 61.1-2f). Un statut rouge avec un parcours 61.1-4 vert = tout va bien.
- **La suite automatisée ne tourne pas sur la VM** (pas de `pdo_sqlite`) : elle est
  exécutée sur la machine hôte.
- **Le test d'intégration du canal est skippé par défaut** : il exige
  `NC_SPIKE_URL`, `NC_SPIKE_ADMIN`, `NC_SPIKE_PASSWORD` et s'exécute depuis le
  checkout principal (`vendor/bin/phpunit -c phpunit.integration.xml --filter NextcloudProvisioningCanalTest`),
  jamais depuis un worktree.
- **Une instance à synchro LDAP refuse la propagation de mot de passe** : c'est
  toléré et journalisé en debug, ce n'est pas une panne — ses mots de passe
  viennent de l'annuaire, pas de SE5.

### 61.1-6 — Corrections de review (2026-08-08) : ce qui change à l'exploitation

Cinq corrections ont été appliquées après la revue de code. Trois se voient depuis
le poste d'exploitation ; les vérifier une fois.

- [ ] **61.1-6a — le champ « serveur de fichiers SMB » peut rester VIDE.**
  Il n'est pas un élément de connexion : laissé vide, il vaut le serveur de
  fichiers déjà connu de l'instance (`sambaedu.se4fs_name`, le jeton `<se4fs>` des
  UNC de lecteurs), et le formulaire l'annonce en indication de saisie.
  **Contrôle** : le laisser vide, « Tester la connexion » → vert ; provisionner →
  le rapport dit `SMB //<nom du serveur>/partages`. Créer un utilisateur et
  changer un mot de passe : les deux doivent produire leur effet côté Nextcloud.
  (Avant correction, ce champ vide rendait **muets, sans aucune trace**, la
  création de compte et la propagation de mot de passe.)

- [ ] **61.1-6b — un compte NC n'est plus adopté « par ressemblance ».**
  L'autocomplétion de Nextcloud cherche par sous-chaîne : un candidat unique
  n'est pas une preuve d'identité. SE5 n'adopte désormais **que l'homonyme
  exact** (casse indifférente). Un login SE5 dont l'instance ne connaît pas
  l'homonyme est donc rapporté **`introuvable`**, même quand des comptes proches
  existent — et le rapport les **nomme** (« non adoptés : … »).
  **Contrôle** : sur une instance où un compte `p.durand-martin` existe sans
  `p.durand`, `nextcloud:provision --users-only` doit compter un `introuvable`
  citant `p.durand-martin`, et **ne rien écrire** dans `users.nextcloud_user_id`.
  **Pourquoi c'est un gain** : l'identité adoptée sert ensuite à propager les mots
  de passe — un quasi-homonyme adopté aurait fait écraser le mot de passe d'un
  compte tiers au premier changement AD.
  ⚠️ **Conséquence à connaître** : sur une instance dont les identifiants NC ne
  sont PAS les logins (mappage sur un GUID), le balayage ne résout plus rien tout
  seul et rapporte des `introuvables`. C'est délibéré ; le rattachement demandera
  un geste explicite (hors périmètre 61.1).

- [ ] **61.1-6c — une exécution en cours (ou interrompue) se voit à l'écran.**
  Le rapport n'est écrit qu'à la fin d'une exécution : un traitement en file tué
  ne laissait rien à voir. Un bandeau « Provisionnement en cours depuis … »
  apparaît maintenant pendant l'exécution.
  **Contrôle** : lancer le provisionnement par le bouton, rafraîchir le rapport →
  le bandeau est là ; à la fin, il disparaît et le rapport se met à jour.
  **Si le bandeau persiste longtemps après la fin attendue**, l'exécution a été
  interrompue : relancer. Il s'efface de lui-même au bout de 30 minutes (durée du
  verrou d'exécution). Rappel : le travail déclare désormais un délai maximal de
  1500 s, inférieur au verrou — les unités `queue:work` n'ont pas besoin de
  `--timeout`.

- [ ] **61.1-6d — les journaux d'une réinitialisation en masse restent lisibles.**
  Deux changements sans effet visible à l'écran, mais qui se constatent dans
  `storage/logs` :
  1. sur une instance à **synchro LDAP**, le refus de changer un mot de passe
     (403 / OCS `997`) est journalisé en `debug` sous
     `nextcloud.user.password.not_applicable` — plus **aucun** WARNING par
     utilisateur à la rentrée ;
  2. si l'instance est **injoignable**, la propagation est abandonnée pour tout
     le lot dès le premier échec réseau, avec **un seul**
     `nextcloud.user.password.batch_skipped` disant combien de comptes n'ont pas
     été propagés — au lieu de payer 15 s de délai par élève dans la requête.
  **Contrôle** : couper l'accès à l'instance, réinitialiser un lot d'une dizaine
  d'élèves → la réinitialisation AD aboutit pour **tous**, la page répond
  normalement, et le journal ne contient **qu'une** ligne d'avertissement
  Nextcloud.

### Checklist rapide — Story 61.1

- [ ] 61.1-P1/P2 : `files_external` active **et** `smbclient` installé **+ service redémarré**
- [ ] 61.1-P3/P4 : compte admin + app password ; l'instance atteint le serveur SMB
- [ ] 61.1-1c : **le secret n'apparaît jamais dans la page** (source comprise)
- [ ] 61.1-1d/e/f : **trois diagnostics, trois messages différents**
- [ ] 61.1-2a : `--dry-run` → **rien de créé côté Nextcloud**
- [ ] **61.1-2c : rejeu → DEUX montages, pas quatre**
- [ ] 61.1-2e : montage créé à la main → **intact**
- [ ] 61.1-2f : statut rouge « Session unavailable » = **normal**, pas un échec
- [ ] **61.1-3b : aucun compte fabriqué au backfill** (`introuvables` rapportés)
- [ ] 61.1-3f : après changement de mot de passe, la connexion web **fonctionne encore**
- [ ] **61.1-4c : un élève ne voit pas `_profs`** — cloisonnement identique au lecteur
- [ ] **61.1-5a : `getfacl` avant/après → diff VIDE**
- [ ] Suite automatisée verte sur l'hôte

---

*Dernière mise à jour : 2026-08-08 (Story 61.1 — Nextcloud en chemin d'accès : deux montages `files_external` SMB « Partages » et « Documents » en `password::sessioncredentials` applicables à TOUS, idempotents par signature canonique normalisée (le slash initial que l'instance ajoute au point de montage est le piège d'idempotence n°1), client `NextcloudAdminClient` unique point de sortie HTTP sur `Http::` (falsifiable, zéro curl nu dans le code nouveau), credential admin chiffré dans `service_credentials` (`nextcloud_admin`), colonne-CACHE `users.nextcloud_user_id` hors `$fillable` (jamais l'AD — contre-modèle `Id NC` de SE4), création de compte au fil de l'eau + adoption `102` + **jamais de mot de passe inventé au backfill**, propagation du mot de passe sous double condition, commande `nextcloud:provision` (`--dry-run`/`--users-only`/`--mounts-only`, codes 0/1/2) et bouton enfilant le MÊME service, quatrième diagnostic `smbclient` manquant mesuré le 2026-08-08 — **ZÉRO droit écrit, ZÉRO commande système, `app/Services/Filesystem/**` et `FileBackendName` INTOUCHÉS**)*

---

## Story 61.2 — Administrer l'instance : connecter

> **⚠️ SECTION CORRIGÉE EN PLACE LE 2026-08-08 — ce runbook a été rédigé pour une
> story qui offrait DEUX modes d'administration ; l'un d'eux a été supprimé.**
>
> Décision d'Henri du 2026-08-08 : **le mode « instance non administrée » (compte
> porteur délégué) est SUPPRIMÉ. SE5 exige un compte administrateur sur l'instance
> Nextcloud.** Motif mesuré : avec un compte ordinaire, Nextcloud refuse de créer un
> Team folder (HTTP 200, mais **OCS 403** dans le corps, rien créé), refuse de créer
> un groupe, et un partage visant un groupe échoue. **Sans Team folder, pas de
> clôture** — donc pas de cloisonnement : un élève verrait `_profs`.
>
> **Pourquoi ces scénarios ont été RÉÉCRITS et non ajoutés en append** (écart assumé
> au principe append-only du domaine) : les contrôles du mode délégué faisaient
> constater **l'inverse** du comportement livré (un sélecteur qui n'existe plus, des
> champs porteurs absents du DOM, un bouton « Provisionner » grisé qui ne l'est plus).
> Les laisser aurait produit un **faux rapport de régression** à chaque passage —
> c'est-à-dire exactement l'inverse de ce à quoi sert un runbook. L'historique de la
> décision vit dans la story 61.2 (§ « Recadrage post-livraison ») et dans
> `sprint-status.yaml` ; le runbook, lui, décrit ce qui est en service.

**Ce que cette story change, en une phrase :** l'instance connectée est **vérifiée
avant d'être enregistrée** — SE5 s'assure que le compte configuré peut réellement
**administrer** l'instance, et refuse la configuration avec son motif sinon.

**Ce qu'elle ne change PAS, et c'est essentiel au contrôle :** aucun partage ne
bascule sur Nextcloud. La colonne de backend des partages ne connaît toujours que
`posix`. **Connecter une instance ne branche rien** — c'est 61.3 qui rendra le
backend Nextcloud sélectionnable partage par partage. Si un contrôle ci-dessous vous
fait chercher un fichier « passé sous Nextcloud », il n'y en a pas et il ne doit pas
y en avoir.

**Le compte doit être ADMINISTRATEUR — ce n'est pas une préférence :**

| Ce qui l'exige | Pourquoi un compte ordinaire ne suffit pas |
|---|---|
| Dossiers d'équipe (61.3) | création refusée : HTTP 200 avec **OCS 403** dans le corps |
| Groupes Nextcloud (61.3) | création refusée (403) |
| Octroi par groupe (61.3) | « Please specify a valid group » |
| Plafonds de zone / quotas (61.3) | opération d'administration |
| Montages de stockage externe + comptes (61.1) | opération d'administration |
| **Clôture d'un nœud privé** (`_profs` invisible aux élèves) | **impossible sans dossier d'équipe** — l'ancêtre propage |

### Prérequis 61.2

- [ ] **61.2-P1** — les prérequis 61.1 : instance joignable, app « Stockage
  externe » activée, app password d'un compte **administrateur**.
- [ ] **61.2-P2** — vérifier que le compte configuré est bien administrateur :
  depuis ce compte, `…/index.php/apps/files_external/globalstorages` et
  `…/ocs/v2.php/cloud/users` doivent répondre **200**. S'ils répondent **403**, le
  compte est ordinaire : la configuration sera refusée (et c'est le comportement
  attendu — voir 61.2-1b).

### 61.2-1 — La configuration de connexion, et son refus

- [ ] **61.2-1a** — `/admin/settings/files`, capacité « Accès Nextcloud » active :
  le bloc de connexion porte l'URL, le **compte administrateur**, son app password,
  le serveur de fichiers SMB et la case TLS. Sous le champ du compte, une phrase
  explique **pourquoi** il doit être administrateur. Il n'y a **aucun sélecteur de
  mode d'administration** : si vous en voyez un, l'écran n'est pas à jour.
- [ ] **61.2-1b — LE CONTRÔLE QUI VAUT LA STORY.** Remplacer l'identifiant du
  compte par un **compte ordinaire** de l'instance → la configuration est
  **refusée**, le champ **revient** à la valeur précédente, un message nomme le
  privilège manquant, et **rien n'est enregistré**. Recharger la page : l'ancien
  compte est toujours en place. Contrôle en base :
  `SELECT value FROM system_settings WHERE key = 'files.policy';` → inchangé.
- [ ] **61.2-1c** — saisir un app password **faux** (voir 61.2-6c : il est
  **enregistré quand même**, c'est voulu), puis lancer « Tester la connexion » →
  diagnostic rouge nommant le refus de l'instance. Le message ne contient
  **jamais** le secret (vérifier aussi dans le code source de la page).
- [ ] **61.2-1d** — laisser l'URL vide et tester → refus nommant ce qui manque,
  **sans aucun appel réseau**. Une configuration incomplète se refuse sans sortir.
- [ ] **61.2-1e** — configuration saine → « Tester la connexion » est **vert**, et
  le message dit explicitement ce qu'il n'a **pas** pu constater : la disponibilité
  du backend SMB sur l'instance, qui ne se voit qu'au premier montage. Personne n'a
  rien écrit sur l'instance pour le tester — c'est délibéré.
- [ ] **61.2-1f** — instance **éteinte**, changer l'URL → refusé (« instance
  injoignable »), et le champ revient à sa valeur en base. C'est voulu : on ne
  déclare pas une cible qu'on n'a pas pu vérifier. **L'échappatoire, s'il faut
  absolument sortir de là** : désactiver la capacité « Accès Nextcloud » — sans
  instance, plus rien n'est sondé, et les champs redeviennent librement modifiables.

### 61.2-2 — La sonde-garde ne bloque QUE la connexion

- [ ] **61.2-2a** — instance **éteinte**, capacité active : basculer « Répertoire
  personnel (K:) », « Partages réseau (H:) », changer le serveur de fichiers SMB
  → **tout s'enregistre normalement, sans attente**. Une panne d'instance ne doit
  pas verrouiller des réglages qui ne la concernent pas.
  *(**L'URL de l'instance, l'identifiant admin et la case TLS ne sont PAS dans
  cette liste** : ils définissent la connexion et re-déclenchent la sonde — voir
  61.2-6a.)*
- [ ] **61.2-2b** — ré-enregistrer une valeur **identique** (même URL, même
  compte) → aucun appel, aucun délai, aucun message. Rejouer un état n'est pas un
  changement.

### 61.2-3 — Le provisionnement, et ce qu'il ne défait jamais

- [ ] **61.2-3a** — configuration saine : le bouton **« Provisionner l'accès
  Nextcloud »** enfile le traitement, et le rapport apparaît sous le formulaire.
- [ ] **61.2-3b** — capacité « Accès Nextcloud » **désactivée**, puis
  `php artisan nextcloud:provision` → **code de sortie 2**, message nommant la
  capacité éteinte. `echo $?` pour le vérifier. **Aucun appel n'est émis vers
  l'instance** (le refus est avant).
- [ ] **61.2-3c — LE CONTRÔLE DE NON-DESTRUCTION (D9).** Noter les montages
  présents dans l'administration Nextcloud (« Stockage externe ») : « Partages » et
  « Documents ». Désactiver la capacité côté SE5, recharger cette page côté
  Nextcloud → **les deux montages sont toujours là, inchangés**. SE5 cesse de les
  gouverner ; il ne les défait jamais.
- [ ] **61.2-3d** — réactiver la capacité **sans rien reconfigurer** →
  `nextcloud:provision` reconverge (les montages sont reconnus « conformes », pas
  recréés), et l'identifiant admin comme son app password sont toujours là.
  **Une bascule de capacité ne perd aucune configuration.**
- [ ] **61.2-3e** — capacité éteinte, créer un utilisateur SE5 et changer un mot de
  passe : **aucun compte Nextcloud n'est créé, aucun mot de passe n'est propagé**,
  et le journal ne contient **aucun avertissement**. Une instance qui n'utilise pas
  Nextcloud ne doit pas faire crier le flux de vie des utilisateurs. Contrôle :
  `grep -c 'nextcloud' storage/logs/laravel.log` après une réinitialisation de mot
  de passe en masse — le compte d'avertissements ne doit pas bouger.

### 61.2-4 — Ce que l'écran DIT de l'exigence

- [ ] **61.2-4a** — sous le champ « Compte administrateur de l'instance », l'écran
  explique que le compte doit l'être, **pourquoi** (dossiers d'équipe, groupes,
  quotas — sans eux le cloisonnement n'est pas tenable) et qu'une configuration qui
  ne le permet pas **sera refusée avec son motif**. Si cette phrase a disparu,
  l'exigence n'est plus dite là où elle se joue.
- [ ] **61.2-4b** — l'écran n'affiche **aucun** bloc « dégradations », **aucun**
  champ « compte porteur », **aucun** bouton de provisionnement grisé « parce que le
  mode ne le porte pas ». Ces éléments appartenaient au mode supprimé : leur retour
  signalerait une régression.

### 61.2-5 — Le rattachement explicite d'identité

**À quoi ça sert** : depuis la correction de review de 61.1, SE5 n'adopte plus
qu'un **homonyme**. Sur une instance dont les identifiants ne sont pas les logins
(mappage sur un GUID, par exemple), les comptes remontent en « introuvables » —
avec, quand l'instance en a proposé, les **candidats écartés nommés**. Ce geste
transforme ces candidats en rattachement, à la main et **vérifié**. Il est
**indépendant** de tout ce qui précède et n'a pas bougé au recadrage.

- [ ] **61.2-5a** — lancer un provisionnement. Dans « Comptes demandant un geste »,
  chaque ligne porte un bouton **« Rattacher »**.
- [ ] **61.2-5b** — cliquer : la modale s'ouvre, **pré-remplie** avec le candidat
  que le rapport avait nommé. Valider → l'identité est vérifiée auprès de
  l'instance, puis enregistrée, et la modale se ferme.
- [ ] **61.2-5c — LE CONTRÔLE DE SÉCURITÉ.** Saisir un identifiant **qui n'existe
  pas** sur l'instance → refus (« l'instance ne connaît aucun compte … »), la
  modale **reste ouverte**, et **rien n'est écrit**. Vérifier en base :
  `SELECT login, nextcloud_user_id FROM users WHERE login = '…';` → toujours
  `NULL`. C'est la règle qui empêche qu'un futur changement de mot de passe AD
  aille écraser le mot de passe **du compte de quelqu'un d'autre**.
- [ ] **61.2-5d** — le même geste en commande, pour l'exploitation :
  ```
  php artisan nextcloud:identity p.durand                 # dit l'état courant
  php artisan nextcloud:identity p.durand --set=uuid-4f2a # rattache (vérifié)
  php artisan nextcloud:identity p.durand --clear         # détache
  ```
  Rejouer deux fois le même `--set` : la seconde fois dit « déjà rattaché » et
  **n'appelle même pas l'instance**. Codes de sortie : `0` abouti/conforme,
  `1` refusé, `2` usage invalide.
- [ ] **61.2-5e** — `--clear` ne supprime **rien** côté Nextcloud : ni le compte,
  ni ses fichiers, ni ses partages. C'est un cache qu'on efface.

### Ce qui n'est PAS observable — à ne pas confondre avec un succès

- **La disponibilité du backend SMB sur l'instance.** La sonde ne crée aucun
  montage : sonder ne doit pas modifier l'instance (leçon de l'enregistrement DNS
  effacé par un test « inoffensif »). Un vert dit donc « l'instance répond, le
  compte est administrateur, l'app Stockage externe est là », pas « un montage
  fonctionnera ». Cela se constate au **premier provisionnement**, et le message
  vert le dit.
- **Le comportement d'une instance à synchro LDAP** : non observé.
- **Tout effet sur les fichiers.** Cette story n'écrit ni un droit, ni un fichier,
  ni une ligne de la colonne de backend. `getfacl` avant/après : diff vide, par
  construction (le protocole de 61.1-5 s'applique tel quel).

### Checklist rapide — Story 61.2

- [ ] 61.2-P2 : le compte configuré est bien **administrateur** (200 sur les deux
      endpoints d'administration)
- [ ] **61.2-1b : une configuration que le compte ne peut pas honorer est REFUSÉE,
      et rien n'est enregistré**
- [ ] 61.2-1d : configuration incomplète → refus **sans aucun appel réseau**
- [ ] 61.2-1e : le message vert dit ce qu'il n'a **pas** pu vérifier
- [ ] 61.2-2a : instance éteinte → les réglages K:/H:/SMB s'enregistrent quand
      même (l'URL, le compte et le TLS, eux, sondent — 61.2-6a)
- [ ] 61.2-3b : capacité éteinte + `nextcloud:provision` → **code 2**, zéro appel
- [ ] **61.2-3c : les montages existants sont INTACTS après extinction de la
      capacité**
- [ ] 61.2-4a : l'exigence « compte administrateur » est **dite à l'écran**
- [ ] **61.2-4b : aucun vestige du mode supprimé (sélecteur, champs porteurs,
      bloc dégradations)**
- [ ] **61.2-5c : une identité non confirmée n'est JAMAIS écrite**
- [ ] 61.2-5d : `nextcloud:identity` rejouée rend le même état

---

### 61.2-6 — Ce que les corrections de review ont changé pour l'exploitant

*Ajouté après la review de la story 61.2, **puis mis à jour le 2026-08-08** par le
retrait du mode délégué. Ces trois points modifient ce que l'écran fait sous les
doigts de l'administrateur : ils sont à connaître avant de conclure « c'est cassé ».*

**61.2-6a — Changer l'URL de l'instance (ou le compte, ou la case TLS) re-déclenche
la sonde.** Avant, seule une modification du **compte** re-vérifiait : on pouvait
donc changer d'hébergeur — ou faire une faute de frappe dans l'URL — et la nouvelle
cible était enregistrée alors qu'aucun appel n'avait été émis vers elle. Désormais :

- [ ] **61.2-6a** — configuration fonctionnelle, ne changer **que** l'URL pour une
  adresse où le compte n'est pas administrateur (ou une adresse qui ne répond pas)
  → **la sonde est jouée**, la configuration est refusée avec son motif, **rien
  n'est persisté**, et **le champ URL de l'écran revient à la valeur en base**.
  Contrôle : recharger la page — l'URL affichée est bien celle d'avant.
  Même comportement pour la case « Vérifier le certificat TLS » et pour
  l'identifiant du compte.
- [ ] **61.2-6a-bis** — la garde de « constitution » tient toujours : tant qu'aucun
  app password n'est enregistré, saisir l'URL ou le compte ne sonde rien (sinon on
  ne pourrait jamais constituer une configuration neuve).
- [ ] **61.2-6a-ter** — les réglages qui ne concernent pas l'instance (K:, H:,
  serveur de fichiers SMB) continuent de s'enregistrer sans le moindre appel,
  instance éteinte comprise. C'est le point qui interdit qu'une panne verrouille
  l'écran (voir 61.2-2a).

**61.2-6b — Une identité Nextcloud ne peut être portée que par UN utilisateur SE5.**
Rattacher `p.durand` à un compte Nextcloud déjà rattaché à `a.dupont` est
**refusé, en nommant `a.dupont`**, et rien n'est écrit. Motif : deux logins SE5
pointant la même identité feraient qu'un changement de mot de passe de l'un
**écraserait le compte de l'autre** — le défaut que 61.1 avait fermé côté adoption
automatique. **Cette garde n'a pas bougé au recadrage du 2026-08-08 et ne bougera
pas : c'est une protection de sécurité.**

- [ ] **61.2-6b** — depuis la modale « Rattacher », saisir un identifiant déjà
  attribué → refus nommant le détenteur, modale ouverte, `SELECT login,
  nextcloud_user_id FROM users` inchangé pour les **deux** comptes.
- [ ] **61.2-6b-bis** — le geste de déplacement, quand il est légitime :
  ```
  php artisan nextcloud:identity a.dupont --clear          # libérer l'identité
  php artisan nextcloud:identity p.durand --set=<identite> # la rattacher ailleurs
  ```
- [ ] **61.2-6b-ter** — pendant un **provisionnement**, un compte dont l'identité
  résolue est déjà détenue n'interrompt PAS le balayage : il apparaît dans
  « Comptes demandant un geste » avec l'état `echec` et le détail nommant le
  détenteur. Le lot va jusqu'au bout.
  *(La base porte aussi un index unique sur `users.nextcloud_user_id` — défense en
  profondeur. Plusieurs comptes sans identité en cache restent parfaitement
  normaux : les `NULL` ne se gênent pas entre eux.)*

**61.2-6c — Remplacer un app password affiche un état « NON VÉRIFIÉ » explicite.**
Enregistrer un secret **n'est jamais refusé**, même si l'instance est éteinte —
sinon une instance momentanément injoignable deviendrait impossible à
reconfigurer. Mais l'écran ne laisse plus croire que la connexion est vérifiée :

- [ ] **61.2-6c** — instance saine, remplacer l'app password → **diagnostic vert**,
  comme après « Tester la connexion ».
- [ ] **61.2-6c-bis** — instance éteinte (ou app password erroné), remplacer le
  secret → **le secret est bien enregistré** (le bandeau « Un app password est
  enregistré (chiffré) » le confirme) **et** un encart orange dit « Connexion
  déclarée, **non vérifiée** depuis le dernier changement de secret », avec le
  motif. Ce n'est **pas** un échec : c'est le seul état honnête.
- [ ] **61.2-6c-ter** — **recharger la page** : l'encart « non vérifié » est
  **toujours là**. L'état de vérification est persisté ; il ne s'efface qu'en
  relançant « Tester la connexion » avec succès, ou en retirant le secret.

### 61.2-7 — Après le retrait du mode délégué : les contrôles de non-régression

*Ajouté le 2026-08-08. À passer une fois après la mise à jour, puis à oublier.*

- [ ] **61.2-7a** — `/admin/settings/files` : aucun sélecteur de mode, aucun champ
  « compte porteur », aucun bloc « Ce que ce mode dégrade », aucun encart
  « Provisionnement indisponible dans ce mode ».
- [ ] **61.2-7b** — le credential du compte porteur a été **effacé de la base** par
  la migration de retrait :
  `SELECT name FROM service_credentials WHERE name = 'nextcloud_delegue';` → **0
  ligne**. (Irréversible et assumé : un secret effacé ne se reconstitue pas, et le
  mode qui le lisait n'existe plus.)
- [ ] **61.2-7c** — le réglage ne porte plus les clés du mode :
  `SELECT value FROM system_settings WHERE key = 'files.policy';` → ni
  `nextcloud_mode`, ni `nextcloud_delegue_user`.
- [ ] **61.2-7d** — la configuration existante est **intacte** : URL, compte admin,
  app password admin, hôte SMB et case TLS n'ont pas bougé, et
  `nextcloud:provision` reconverge sans rien recréer.

---

## Story 61.3 — Le backend `nextcloud` : quand l'autorité bascule

*Ajouté le 2026-08-08 (append-only).*

**Ce qui change pour l'exploitant.** Jusqu'ici, Nextcloud était un CHEMIN D'ACCÈS :
il montait des zones dont le serveur de fichiers restait l'autorité. Depuis 61.3, un
répertoire peut être **servi par Nextcloud** — le plan y devient un **dossier
d'équipe**, les octrois des permissions de groupe, et le cloisonnement des **règles
de permissions avancées**. Conséquence directe et **définitive** : un tel répertoire
**n'a AUCUN lecteur réseau**. Il se consulte au web et se synchronise avec le client
de bureau. Ce n'est pas une fonctionnalité manquante, c'est une impossibilité
vérifiée (D7).

### 61.3-0 — Les prérequis, à vérifier AVANT tout le reste

- [ ] **61.3-0a** — la capacité « Accès Nextcloud » est **active** et la connexion
  **vérifiée** (`/admin/settings/files`, bouton « Tester la connexion »). Capacité
  éteinte ⇒ la case « Nextcloud (dossier d'équipe) » **n'apparaît pas** à la
  création, et le motif est écrit sous le champ.
- [ ] **61.3-0b** — l'app **`groupfolders`** est activée sur l'instance (mesurée en
  version 22.0.6 sur Nextcloud 34.0.2). Sans elle, aucune route de dossier d'équipe
  ne répond, et la réconciliation échoue en le disant.
- [ ] **61.3-0c** — le compte configuré est **administrateur de l'instance**. SE5
  l'exige : un compte ordinaire ne peut créer ni dossier d'équipe, ni groupe. Le
  piège à connaître : un refus d'administration rend **HTTP 200 avec un 403 dans le
  corps** — le code de transport ment, SE5 lit le corps.
- [ ] **61.3-0d** — ⚠️ **PRÉREQUIS LE PLUS COÛTEUX À DÉCOUVRIR : l'ACL AVANCÉE EST
  UN INTERRUPTEUR PAR DOSSIER D'ÉQUIPE.** SE5 l'active lui-même à la
  réconciliation (`POST /index.php/apps/groupfolders/folders/{id}/acl` avec
  `acl=1`). **Tant qu'il est éteint, les règles de cloisonnement sont acceptées et
  n'ont AUCUN EFFET.** Si un cloisonnement paraît ne rien faire, c'est la première
  chose à vérifier : Administration › Dossiers d'équipe › le dossier › colonne
  « Permissions avancées ».

### 61.3-1 — Le parcours réel : créer un répertoire servi par le cloud

- [ ] **61.3-1a** — `/admin/shares` › « Nouveau répertoire réseau » : le champ
  **« Autorité d'écriture des droits »** propose « Serveur de fichiers (POSIX/SMB) »
  et « Nextcloud (dossier d'équipe) ». La description du choix courant s'affiche
  sous le champ, et celle du cloud dit en toutes lettres qu'il n'y a **pas** de
  lecteur SMB.
- [ ] **61.3-1b** — créer un répertoire avec le backend cloud. Le message dit
  « mise en place des droits **engagée** », jamais « accomplie » : la réconciliation
  est enfilée.
- [ ] **61.3-1c** — sur l'instance : un dossier d'équipe porte le nom du répertoire,
  ses groupes `se5_…` apparaissent dans sa carte, et **« Permissions avancées » est
  activé**.
- [ ] **61.3-1d** — la page du répertoire affiche le badge « Nextcloud (dossier
  d'équipe) » et le tableau « Dernier passage sur les droits », **un nœud par
  ligne**. Un déclin permanent (`Non supporté par ce backend`) s'y voit avec son
  détail — il ne disparaît jamais dans un agrégat vert.
- [ ] **61.3-1e** — **AUCUNE LETTRE DE LECTEUR** n'est émise pour ce répertoire :
  vérifier l'état désiré d'un poste (`drives`) — la lettre du répertoire cloud n'y
  est pas, celles des répertoires POSIX y sont.

### 61.3-2 — La preuve qui compte : l'élève ne voit pas l'espace des enseignants

C'est le scénario pour lequel toute la clôture calculée existe. À jouer avec **deux
comptes réels** (un élève de la classe, un enseignant) :

- [ ] **61.3-2a** — se connecter au web avec le **compte élève** : le dossier
  d'équipe est visible, `_travail` est visible, **`_profs` N'APPARAÎT PAS dans le
  listing**. Ce n'est pas « visible mais interdit » : il a disparu.
- [ ] **61.3-2b** — forcer l'URL du dossier `_profs` avec le compte élève : refus.
- [ ] **61.3-2c** — se connecter avec le **compte enseignant** : `_profs` est
  visible et **inscriptible**.
- [ ] **61.3-2d** — **le contre-test qui compte** : sur l'instance, retirer à la
  main la règle de `_profs` pour le groupe `se5_…_member`, puis relancer l'audit de
  conformité depuis la page du répertoire. → **le répertoire passe en ÉCART**, en
  nommant le sujet qui n'est plus refermé. Sans cette comparaison, l'écran serait
  resté tout vert pendant que la classe lit le dossier privé des enseignants.
- [ ] **61.3-2e** — désactiver l'espace d'échange (nœud activable) : l'octroi de la
  classe devient **explicitement vide** côté instance (règle présente, permissions
  nulles) — jamais une absence de règle. Le dossier et son contenu **restent**.

### 61.3-3 — Vérifier une clôture à la main (protocole)

Sur un poste ayant `curl`, avec le compte admin de l'instance :

```
curl -u '<admin>:<motdepasse>' -X PROPFIND \
  -H 'Depth: 0' -H 'Content-Type: application/xml' \
  --data '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">
          <d:prop><nc:acl-list/><nc:inherited-acl-list/><nc:acl-enabled/></d:prop></d:propfind>' \
  '<url>/remote.php/dav/files/<admin>/<DossierEquipe>/_profs'
```

Comment lire la réponse — **et c'est là que se cachent trois pièges** :

- la réponse est un **`207`**, et **ce code ne conclut rien**. Le verdict est le
  `<d:status>` porté **par chaque propriété** dans le corps ;
- **`404 Not Found` sur `acl-list` veut dire « aucune règle posée »**, jamais une
  erreur (même famille que le `405` d'une création de dossier rejouée) ;
- la règle relue porte un champ **`acl-mapping-display-name` que le serveur AJOUTE**.
  Personne ne l'a écrit, et SE5 l'ignore dans ses comparaisons — sinon il verrait une
  dérive à chaque passage.

`inherited-acl-list` répond à l'autre question : cette règle est-elle posée **ici**
ou **descend-elle d'un dossier parent** ? C'est exactement la distinction que le
cloisonnement doit trancher.

### 61.3-4 — Les deux plafonds, et ils ne se recouvrent jamais

- [ ] **61.3-4a** — un plafond porté par la **racine** du plan devient le **quota du
  dossier d'équipe**, relu pour être confirmé.
- [ ] **61.3-4b** — un plafond porté par un **sous-dossier** rend « **Non supporté
  par ce backend** » : le quota d'un dossier d'équipe porte sur le dossier ENTIER.
  C'est une limite **permanente** du modèle, pas une dette de SE5 — l'écran la
  masque au lieu de la griser.
- [ ] **61.3-4c** — le **quota d'un compte** (règle de quota SE5) est appliqué par
  le **balayage de provisionnement** (`php artisan nextcloud:provision`), pas par la
  recette de partage. Il n'est écrit **que si une règle de quota SE5 existe** pour ce
  compte : sans règle, SE5 n'a pas d'opinion et **n'écrase pas** un plafond posé à la
  main sur l'instance.

### 61.3-5 — Ce qui n'est PAS observable, et qu'il ne faut pas croire observé

- **La perception effective d'un utilisateur** ne se déduit PAS des règles relues.
  Une règle relue prouve une règle, pas ce qu'un élève voit. Le seul moyen de le
  savoir est de **se connecter avec un compte de test** (61.3-2) — c'est ce que fait
  le test d'intégration, avec des comptes jetables.
- **Le comportement d'une instance à synchro annuaire (LDAP) n'a PAS été observé.**
  Les groupes que SE5 compile (`se5_…`) sont des groupes locaux de l'instance ; leur
  cohabitation avec des groupes synchronisés, et le fait qu'un compte synchronisé
  accepte d'y entrer, restent à mesurer.
- **Un objet créé à la main reste intouché.** Un dossier d'équipe hors plan n'est ni
  supprimé, ni modifié, ni rapporté. En revanche, une **règle** posée à la main **sur
  un dossier DU plan** est un écart — et elle doit l'être.
- **Le compte d'administration garde toujours accès.** SE5 le rattache à un groupe
  structurel `se5_administration`, sans quoi il ne verrait pas le dossier d'équipe et
  ne pourrait ni créer les sous-dossiers ni poser les règles. Ce groupe n'est PAS un
  octroi du plan : il n'apparaît pas dans les états relus, exactement comme le groupe
  d'administration d'annuaire côté serveur de fichiers.

### 61.3-6 — Ce qui ne se fait PAS (et où c'est traité)

- [ ] **61.3-6a** — **un répertoire ne change jamais d'autorité d'écriture.** Le
  choix se fait à la création et nulle part ailleurs : il n'existe aucun écran, aucun
  bouton, aucun champ pour le basculer. Migrer un répertoire existant (déplacer les
  données, retraduire les droits) est un chantier dédié, avec aperçu — **il n'est pas
  livré ici**.
- [ ] **61.3-6b** — **monter un répertoire cloud comme un lecteur sur le poste** est
  un chantier d'**agent** (montage, purge de profil sur poste partagé), pas de
  backend. Rien ne le promet dans l'interface.
- [ ] **61.3-6c** — **l'outil en ligne de commande de l'instance (`occ`) n'est jamais
  un chemin d'exécution de SE5.** Il suppose un accès système au serveur Nextcloud,
  qu'on n'a pas sur une instance distante ou tierce. Il reste utile **en diagnostic**
  sur une instance qu'on héberge soi-même — jamais dans une procédure de
  réconciliation.

### 61.3-7 — Corrections de revue : deux comportements d'exploitation à connaître

*(Ajouté après la revue de la story. Le reste de la section 61.3 est inchangé.)*

#### Un plafond de compte peut désormais **ne pas être écrit**, et le rapport le dit

Le profil de quota d'une personne — élève, enseignant, administrateur — est ce qui
choisit la **politique par défaut** appliquée à son compte. Ce profil est résolu par
l'**annuaire** (appartenance à `cn=admins` / `cn=profs`…), et par lui seul. Il l'était
auparavant par la colonne `role` de la fiche utilisateur, qui ne gouverne rien dans
SE5 : un enseignant dont le rôle n'était pas renseigné recevait **silencieusement le
plafond d'un élève**.

Conséquence directe pour l'exploitant :

- [ ] **61.3-7a** — quand l'annuaire **ne répond pas** pour un compte (ou ne le
  connaît pas), **aucun plafond n'est écrit** pour lui. Ce n'est pas une panne et ce
  n'est pas un échec : le compte est **adopté**, son montage fonctionne, le code de
  sortie de `nextcloud:provision` **ne change pas**. C'est un **constat** — SE5 ne
  devine pas un profil, parce qu'un plafond faux s'applique tandis qu'un plafond
  absent se voit.
- [ ] **61.3-7b** — le constat est **compté et affiché** : `nextcloud:provision`
  imprime un avertissement « Plafonds NON écrits — profil de quota indéterminable
  pour N compte(s) » avec quelques logins en exemple, et l'écran
  `/admin/settings/files` (onglet « Personnels et partagés ») affiche le badge
  « Plafonds non écrits (profil indéterminable) ». **Si ce nombre est élevé,
  regarder l'annuaire avant de regarder Nextcloud** : c'est le symptôme d'une lecture
  d'annuaire cassée, pas d'un problème de cloud.
- [ ] **61.3-7c** — une **règle de quota nominative** (visant un login précis) est
  écrite **même si l'annuaire est muet** : elle n'a besoin d'aucun profil. De même,
  une **règle de quota par groupe** s'applique désormais aux comptes Nextcloud —
  elle ne le pouvait pas auparavant, les groupes n'étant jamais transmis au calcul.
- [ ] **61.3-7d** — **coût** : tant qu'**aucune** règle de quota n'existe sur `/home`,
  le balayage n'émet **aucune** lecture d'annuaire, quelle que soit la taille de
  l'établissement. Dès qu'une règle existe, le coût est d'**au plus une lecture
  d'annuaire par compte** (jamais une par valeur lue).

#### Le compte d'administration ne peut plus être expulsé d'un groupe du plan

- [ ] **61.3-7e** — si l'exploitant ajoute **à la main** le compte d'administration
  configuré dans un groupe compilé par SE5 (`se5_…`), la réconciliation **ne l'en
  retire plus**. C'est cohérent avec « hors du plan, hors du geste » : le retirer lui
  ferait perdre l'accès qu'il s'est donné, et cette perte ne se serait manifestée
  qu'au **passage suivant**, sous la forme d'un dossier d'équipe soudain
  inatteignable. Les appartenances réellement périmées, elles, continuent d'être
  retirées.
- [ ] **61.3-7f** — ⚠️ **cela ne rend pas le geste souhaitable.** Le compte
  d'administration accède au dossier par le groupe **structurel**
  `se5_administration` (61.3-5), et il n'a besoin de rien d'autre. S'il est membre
  d'un groupe du plan et qu'un sous-dossier **referme ce groupe** (l'espace des
  enseignants pour la classe, par exemple), la clôture s'applique **aussi à lui** :
  SE5 ne le retirera pas du groupe, mais il perdra l'accès à ce sous-dossier et la
  réconciliation le rapportera en échec sur ce nœud. **Ne pas mettre le compte
  d'administration dans un groupe du plan.**


---

*Dernière mise à jour : 2026-08-08 (Corrections de revue de la story 61.3 — section
**61.3-7 ajoutée en append**. Deux effets d'exploitation : (1) le profil de quota
d'un compte est désormais résolu par l'**annuaire** et jamais deviné — quand il est
indéterminable, **aucun plafond n'est écrit** et le cas est compté au rapport et à
l'écran ; les règles de quota par **groupe** atteignent enfin les comptes Nextcloud ;
(2) la réconciliation **ne retire plus** le compte d'administration d'un groupe du
plan — sans pour autant que l'y mettre soit une bonne idée.)*

*Mise à jour précédente : 2026-08-08 (Story 61.3 — le backend `nextcloud` : l'autorité
d'écriture peut désormais être Nextcloud. Section 61.3 ajoutée en append. Ce qui
compte pour l'exploitant : un répertoire servi par le cloud n'a AUCUN lecteur réseau ;
l'ACL avancée est un interrupteur PAR dossier d'équipe, et sans lui les règles de
cloisonnement sont acceptées sans effet ; le choix de l'autorité est définitif.)*

*Mise à jour antérieure : 2026-08-08 (Recadrage post-livraison de la story 61.2 — le
mode « instance non administrée » (compte porteur délégué) est SUPPRIMÉ : mesuré,
un compte ordinaire ne peut créer ni Team folder, ni groupe, ni partage de groupe,
donc pas de clôture, donc pas de cloisonnement. SE5 EXIGE un compte administrateur.
La section 61.2 de ce runbook a été **corrigée en place** — écart assumé au principe
append-only, motivé en tête de section : des scénarios qui font constater l'inverse
du comportement livré produisent un faux rapport de régression. Ce qui reste
inchangé : tout 61.1, le rattachement explicite d'identité, la garde d'unicité de
`users.nextcloud_user_id`, le fail-closed sur la configuration et l'état « non
vérifié » persistant.)*

---

## Story 62.6 — L'éditeur d'arborescence par type de groupe

**Ce que cette story change, en une phrase :** l'arborescence de fichiers d'un type
de groupe se **saisit à l'écran** (`Paramètres › Groupes & droits › Arborescences`)
au lieu de vivre dans un fichier de seed, et l'administrateur voit **l'arborescence
résolue avant d'enregistrer**.

**Ce qu'elle NE change pas, et c'est la première chose à établir :** aucune donnée,
aucun droit, aucun fichier. L'écran ne matérialise rien, ne réconcilie rien,
n'enfile aucun traitement. Ouvrir l'éditeur et demander un aperçu sont des gestes
strictement **en lecture** — le premier point à vérifier ci-dessous.

**Ce qu'elle rend possible pour la première fois — et pourquoi ça compte ici.**
Jusqu'à cette story, aucune recette ne portait de combinaison de verbes
« différenciée » : les droits livrés étaient maximalement permissifs (contrepartie
assumée de la migration 62.4). L'éditeur rend ces combinaisons **saisissables**.
Deux vérifications terrain reportées deviennent donc jouables — et deux prérequis
d'exploitation deviennent bloquants.

### Prérequis

- [ ] **62.6-P1** — instance avec au moins **un groupe** du type édité (sans groupe,
      l'aperçu est indisponible et le dit ; l'enregistrement, lui, reste possible).
- [ ] **62.6-P2** — accès `root` au serveur de fichiers pour `getfacl`.
- [ ] **62.6-P3 — BLOQUANT avant d'appliquer une combinaison différenciée.** La règle
      d'élévation doit autoriser `find` (réf. **62.4-3b**). Sans elle, une recette
      « lire + éditer » ou « lire + créer » échoue **bruyamment** à l'exécution en
      nommant sa cause — elle ne pose jamais un droit approximatif. Vérifier :
      ```bash
      grep -rn "find" /etc/sudoers.d/ | head
      ```
- [ ] **62.6-P4** — pour la partie Nextcloud (62.6-6), une instance connectée et un
      dossier d'équipe servi par le backend `nextcloud` (réf. section 61.3).

### 62.6-1 — LA VÉRIFICATION PIVOT : ouvrir et prévisualiser n'écrivent RIEN

C'est la contrepartie terrain du test automatisé « ouvrir ne modifie rien ». Elle
porte sur les **deux** côtés : la base et le disque.

```bash
# AVANT d'ouvrir l'écran, sur le serveur :
find /var/sambaedu/ClassesSE5 -maxdepth 3 -type d -exec getfacl -p {} \; > ~/acl-avant-62-6.txt
psql -At -c "select md5(string_agg(key||label||coalesce(path_pattern,'')||roles_spec::text||coalesce(nodes_spec::text,'')||coalesce(attached_group_type,'')||coalesce(root_anchor,''),'|' order by id)) from directory_templates" sambaedu > ~/recettes-avant-62-6.txt
```

Puis, à l'écran : ouvrir `Paramètres › Groupes & droits › Arborescences`, cliquer
« Modifier l'arborescence » sur **Classe**, parcourir tous les dossiers, changer de
groupe d'essai, demander l'aperçu **deux fois** — et **fermer par « Annuler »**.

```bash
find /var/sambaedu/ClassesSE5 -maxdepth 3 -type d -exec getfacl -p {} \; > ~/acl-apres-62-6.txt
diff ~/acl-avant-62-6.txt ~/acl-apres-62-6.txt
psql -At -c "select md5(string_agg(key||label||coalesce(path_pattern,'')||roles_spec::text||coalesce(nodes_spec::text,'')||coalesce(attached_group_type,'')||coalesce(root_anchor,''),'|' order by id)) from directory_templates" sambaedu > ~/recettes-apres-62-6.txt
diff ~/recettes-avant-62-6.txt ~/recettes-apres-62-6.txt
```

- [ ] **62.6-1a — les DEUX diffs sont VIDES.** Un diff de recettes non vide signifie
      que l'écran a **normalisé** le contenu stocké à l'ouverture : c'est un défaut
      bloquant, pas un détail cosmétique — une recette réécrite « à l'identique »
      peut avoir perdu une clé facultative en chemin.
- [ ] **62.6-1b — aucune écriture disque.** Le diff `getfacl` est vide, et aucun
      dossier neuf n'apparaît sous la racine des arbres de classe.
- [ ] **62.6-1c — aucun traitement enfilé.** Le journal ne montre aucune
      réconciliation déclenchée par l'ouverture ou l'aperçu.

### 62.6-2 — L'APERÇU dit la vérité sur ce qui n'est pas écrit

Éditeur de **Classe** ouvert, groupe d'essai choisi, bouton « Prévisualiser ».

- [ ] **62.6-2a** — chaque ligne de l'aperçu porte l'issue **« Aucune exécution
      (aperçu) »** et le détail « Aucune écriture : aperçu du plan. ». Aucune ligne
      ne dit « conforme », « appliqué » ni « absent » : un aperçu n'a rien vérifié.
- [ ] **62.6-2b — la CLÔTURE est visible.** Sur le dossier des enseignants, le détail
      ajoute « Rôles sans octroi ici (clôture reçue du plan) : … » en nommant
      l'audience qui n'y a rien reçu. C'est la preuve, à l'écran, que la clôture
      traverse la ligne de contrat intacte.
- [ ] **62.6-2c — les chemins sont RÉSOLUS et RELATIFS.** Le nom du groupe d'essai
      apparaît à la place du jeton, et le dossier personnel porte un **vrai
      identifiant d'élève**. Aucun chemin absolu nulle part : un aperçu ne vise aucun
      endroit réel.
- [ ] **62.6-2d — les dossiers de membres sont REPLIÉS.** Sur une classe nombreuse,
      un seul exemplaire est montré, suivi de « … et N autres dossiers de membres ».
      Vérifier que **N + 1 = l'effectif** de la classe : l'aperçu replie, il ne
      tronque pas.
- [ ] **62.6-2e — un état invalide montre le REFUS, pas un aperçu partiel.** Déclarer
      un dossier `a/b/c` sans déclarer `a/b`, puis prévisualiser : le message métier
      nomme le chemin fautif **et** l'ancêtre manquant, et aucune ligne de plan ne
      s'affiche.

### 62.6-3 — CE QUE LE PARTAGE NE SAIT PAS RENDRE : grisé, expliqué, jamais corrigé

L'écran interroge le backend d'exécution ; il ne redit pas ses règles. Les trois
comportements ci-dessous se constatent **sans rien enregistrer**.

- [ ] **62.6-3a — « Supprimer » sans « Créer » est GRISÉ.** Sur un dossier, cocher
      « Lire » pour une audience puis tenter « Supprimer » : la case est
      **désactivée**, marquée « non exprimable », et son info-bulle explique que le
      même levier porte les deux verbes. Vérifier qu'aucun mot de mécanisme
      n'apparaît (pas de `rwx`, pas de nom de commande, pas de « sticky »).
- [ ] **62.6-3b — « Créer » sans « Supprimer » reste SAISISSABLE et porte sa note.**
      Sur un dossier de dépôt dont **aucune** autre audience ne porte la suppression :
      cocher « Lire + Créer ». La ligne affiche « Rendu approché : le déposant pourra
      encore retirer ses propres fichiers. »
- [ ] **62.6-3c — sur un dossier MIXTE, la note change.** Sur un dossier où une autre
      audience porte déjà « Supprimer », la même combinaison affiche la note **du
      nœud** : la restriction ne peut pas s'y poser, et l'octroi sera rendu à ce que
      le partage sait exprimer. C'est la subtilité que 62.4 a nommée ; l'écran la dit
      au lieu de la laisser découvrir.
- [ ] **62.6-3d — RIEN N'EST DÉCOCHÉ D'OFFICE.** Si une recette stockée porte déjà
      une combinaison inexprimable (possible : le vocabulaire du plan est neutre, et
      un autre plan de fichiers la rendra), elle s'ouvre **cochée**, marquée « non
      exprimable ». Modifier autre chose (le libellé) et enregistrer : rouvrir et
      **vérifier que la combinaison est toujours là**. Une amputation silencieuse
      serait le pire défaut possible de cet écran.

### 62.6-4 — LA PREMIÈRE TRAVERSÉE RÉELLE (protocole 62.5-3, désormais jouable)

C'est ici que la story débloque la vérification reportée par la 62.5. **Situation à
construire depuis l'éditeur**, sur un type de test (pas sur `classe` en production) :

1. créer l'arborescence d'un type nu, motif `Essai_{group.bare_name}` ;
2. ajouter deux audiences : « tout le groupe », et « les membres portant <rôle
   d'encadrement> » ;
3. déclarer trois dossiers : `.` (racine, « Lire » pour tout le groupe),
   `_travail` (« Lire » pour tout le groupe **et rien** pour l'encadrement),
   `_travail/prive` (les quatre verbes pour l'**encadrement seul**) ;
4. **avant d'enregistrer**, demander l'aperçu.

- [ ] **62.6-4a — l'aperçu ANNONCE le passage.** Un encart d'information dit que
      `_travail/prive` sera rendu **atteignable**, que le passage par les dossiers
      parents est dérivé automatiquement, et qu'il n'accorde ni lecture ni dépôt sur
      eux. L'info-bulle du mot « couloir » est présente et lisible.
- [ ] **62.6-4b** — enregistrer, créer un groupe de ce type, laisser la
      matérialisation se faire, puis dérouler **62.5-3a → 62.5-3f** à l'identique
      (couloir posé et lui seul, non descendu, `ls` refusé / `cd` autorisé, retrait
      effectif, suspension non percée).
- [ ] **62.6-4c — LE POINT À OBSERVER EN VRAI (réf. 62.5-3d) : le même essai depuis
      SMB.** Monter le partage depuis un poste Windows avec un compte de
      l'encadrement : `_travail` doit être inaccessible en listage, et le chemin
      complet `\\serveur\<partage>\_travail\prive` doit s'ouvrir en tapant l'adresse.
      **Noter le comportement observé, quel qu'il soit** — le mappage NT de
      « traverser sans lire » n'est pas garanti iso-POSIX, et c'est la seule inconnue
      que seule une vraie machine lève.

### 62.6-5 — LA RESTRICTION DE SUPPRESSION À TRAVERS SMB (réf. 62.4-2, désormais jouable)

Depuis l'éditeur, poser sur un dossier de test un octroi « Lire + Créer » pour une
audience d'élèves, **sans** aucune autre audience portant « Supprimer ». Enregistrer,
matérialiser, puis :

- [ ] **62.6-5a** — depuis une session Windows de l'**élève B**, la suppression d'un
      fichier déposé par l'**élève A** est-elle refusée ? Noter le message exact.
- [ ] **62.6-5b** — l'élève **A** peut-il supprimer SON fichier ? (Attendu : oui —
      c'est précisément la dégradation que l'écran a déclarée en 62.6-3b.)
- [ ] **62.6-5c** — depuis une session **membre du groupe d'administration** : la
      suppression est-elle possible ? **Noter la réponse** : elle décide si le libellé
      de l'écran doit un jour avertir que la restriction s'applique aussi aux
      administrateurs.
- [ ] **62.6-5d** — vérifier au journal qu'une commande de sélection par type d'objet
      a bien été émise (c'est ce qui exige `find` en liste blanche, 62.6-P3), et
      qu'elle n'a **pas** échoué.

### 62.6-6 — L'ARBORESCENCE NEXTCLOUD : où l'écran doit dire « non exprimable »

> ⚠️ **CETTE SECTION N'EST PAS ENCORE JOUABLE EN ENTIER, et il faut le savoir avant
> de s'y mettre.** Aucun backend Nextcloud n'implémente le contrat `FileBackend` à ce
> jour : **aucune arborescence n'est servie par Nextcloud**, donc la situation à
> construire ci-dessous ne peut pas l'être. Les cases 62.6-6a à 62.6-6d sont écrites
> pour le jour où ce sera le cas ; **62.6-6e**, lui, se joue déjà (il porte sur le
> cloisonnement mesuré en 61.3, pas sur l'éditeur).
>
> **Et l'écran n'y répondrait pas encore correctement.** Le grisé des verbes interroge
> aujourd'hui la déclaration du serveur de fichiers historique **par son nom de
> classe** — le contrat `FileBackend` n'expose rien qui dise ce qu'un backend sait
> rendre en verbes (il n'a que `quota()`, seul point réellement routé par le registre,
> d'où le fait que 62.6-6a fonctionnera, lui, sans changement d'écran). Étendre le
> contrat était hors du périmètre de 62.6 (zéro diff sous `app/`). Trois méthodes
> devront alors passer par le registre : `verbAnalysis()`, `verbIsExpressible()` et
> `nodeCarriesRestriction()`. **Tant que ce n'est pas fait, 62.6-6d ÉCHOUERA** — et
> c'est un défaut connu et daté, pas une surprise à découvrir sur le terrain.

**Pourquoi cette section existe.** La matrice de ce que l'on sait rendre est **par
backend**, jamais globale. Mesuré contre une instance réelle (61.3), Nextcloud rend
**nativement** les quatre verbes dans n'importe quelle combinaison : le grisé de
62.6-3a y serait un mensonge. En revanche, il a une limite que le serveur de
fichiers historique n'a pas — **le plafond porte sur le dossier d'équipe ENTIER**.

Situation à construire : une arborescence dont le partage est servi par le backend
`nextcloud` (section 61.3), avec un **plafond posé sur un SOUS-dossier**, pas sur la
racine.

- [ ] **62.6-6a — l'aperçu montre la déclaration du backend, mot pour mot.** À côté
      du plafond du sous-dossier, l'écran affiche le libellé et le détail que le
      backend produit. Sur un partage servi par le serveur de fichiers historique, il
      dit « Non piloté par SE5 pour l'instant » (une **dette datée**, le mécanisme
      existe côté système) ; sur un partage servi par Nextcloud, il doit dire
      **« Non supporté par ce backend »** (une **limite permanente du modèle**) en
      expliquant que le plafond d'un dossier d'équipe porte sur le dossier entier.
      **Les deux ne se disent pas pareil, et c'est le point** : l'un s'attend à
      changer, l'autre non.
- [ ] **62.6-6b — le plafond de la RACINE, lui, ne porte aucune limite.** Sur le même
      partage Nextcloud, déplacer le plafond sur le dossier racine : la mention
      « Non supporté » disparaît.
- [ ] **62.6-6c — vérification côté instance.** Après matérialisation, dans
      l'administration Nextcloud : le quota du dossier d'équipe correspond au plafond
      de la **racine**, et aucun quota n'a été inventé sur un sous-dossier.
- [ ] **62.6-6d — les quatre verbes sont rendus tels quels.** Sur un dossier servi par
      Nextcloud, poser « Lire + Supprimer » (la combinaison que le serveur de fichiers
      historique refuse) et vérifier, dans les permissions avancées du dossier
      d'équipe, que la valeur relue correspond exactement à ce qui a été demandé.
      **Attendu AUJOURD'HUI : cette case ÉCHOUE** — l'écran grisera « Supprimer », parce
      que le grisé interroge encore la déclaration du serveur de fichiers historique
      (voir l'avertissement en tête de section). Elle reste écrite parce qu'elle est le
      critère d'acceptation du jour où les trois méthodes passeront par le registre : la
      règle du grisé doit venir du backend concerné, pas d'une classe câblée en dur.
- [ ] **62.6-6e — le cloisonnement tient.** Depuis un compte élève, le dossier privé
      des enseignants est **refusé** et **disparaît du listage** (comportement
      mesuré en 61.3). L'aperçu l'avait annoncé par la phrase de clôture (62.6-2b).

### 62.6-7 — Les refus parlent, et n'écrivent rien

Chacun se provoque depuis l'éditeur, et se vérifie par **l'absence de modification**
en base (rejouer le calcul d'empreinte de 62.6-1).

- [ ] **62.6-7a** — un dossier `a/b/c` sans `a/b` : refus nommant le chemin fautif et
      l'ancêtre manquant.
- [ ] **62.6-7b** — un dossier sous un dossier « à contenu libre » : refus disant que
      le contenu de cet ancêtre n'est pas gouverné par le plan.
- [ ] **62.6-7c** — un dossier par membre dont aucun ancêtre n'ouvre l'accès à une
      audience contenant ces membres : refus nommant l'ancêtre **et** le rôle.
- [ ] **62.6-7d** — un second arbre sur un type qui en porte déjà un : refus nommant
      la recette déjà accrochée.
- [ ] **62.6-7e** — retirer une audience qui porte encore des droits : refus **avec le
      décompte**, et l'audience reste.
- [ ] **62.6-7f** — un plafond non numérique : refus disant qu'un plafond est un
      nombre d'octets strictement positif.
- [ ] **62.6-7g** — après chaque refus, l'empreinte des recettes est **inchangée**.

### 62.6-8 — Ce que l'enregistrement arme, et ce qu'il n'arme pas

- [ ] **62.6-8a** — l'écran annonce, au moment d'enregistrer, que les groupes de ce
      type créés **ensuite** porteront cette arborescence, et que les groupes
      existants ne sont pas touchés.
- [ ] **62.6-8b** — enregistrer, puis vérifier qu'**aucun** partage n'a été créé et
      qu'aucune réconciliation n'a été enfilée. La reprise du parc existant reste la
      commande dédiée :
      ```bash
      php artisan shares:materialize-class-trees
      ```
- [ ] **62.6-8c** — créer un groupe du type **après** l'enregistrement : son
      arborescence naît avec lui, conforme à ce que l'aperçu montrait.

### 62.6-9 — Le vocabulaire de l'écran reste celui du métier

- [ ] **62.6-9a** — parcourir l'onglet complet (matrice grisée et aperçu compris) :
      aucun mode de permission, aucun nom de commande, aucun nom de groupe système,
      aucun chemin absolu, aucun mot de mécanisme. Tout est dit en dossiers, en
      audiences et en verbes.
- [ ] **62.6-9b** — un rôle du catalogue absent de la liste des audiences est
      **expliqué** : l'écran dit que ce type ne le déclare pas, et renvoie vers
      l'onglet « Types de groupes ». Il ne le fait pas disparaître en silence.
- [ ] **62.6-9c** — sur une instance **sans profil de rôles installé**, toutes les
      audiences du catalogue sont proposées avec leurs libellés génériques, et
      l'écran reste juste. (Le profil s'installe par
      `php artisan college:seed:role-x-type` — il n'est jamais supposé.)

### Checklist rapide — Story 62.6

- [ ] 62.6-P1→P4 : groupe d'essai, accès root, **`find` en liste blanche**, instance NC
- [ ] **62.6-1a : empreinte des recettes + `getfacl` avant/après → diffs VIDES** (pivot)
- [ ] 62.6-2a→2e : aperçu honnête, clôture visible, chemins résolus, membres repliés
- [ ] 62.6-3a→3d : grisé expliqué, dégradations déclarées, **rien décoché d'office**
- [ ] 62.6-4a→4c (débloque 62.5-3) : couloir annoncé puis vérifié, **SMB NOTÉ**
- [ ] 62.6-5a→5d (débloque 62.4-2) : restriction réelle × SMB, **réponse admin NOTÉE**
- [ ] 62.6-6a→6e : **`non_exprimable` sur un plafond de sous-dossier Nextcloud**, les
      quatre verbes rendus tels quels, cloisonnement tenu
- [ ] 62.6-7a→7g : les refus parlent, et n'écrivent rien
- [ ] 62.6-8a→8c : rien n'est matérialisé, les groupes futurs portent l'arbre
- [ ] 62.6-9a→9c : vocabulaire métier, types fermés expliqués, base nue juste
- [ ] Suite automatisée verte sur l'hôte

---

*Mise à jour : 2026-08-09 (Story 62.6 — l'éditeur d'arborescence par type de groupe :
troisième onglet de `Paramètres › Groupes & droits`, saisie des dossiers (chemin avec
substitutions PROPOSÉES, libellé, nature, plafond), matrice audiences × quatre verbes
dont l'inexprimable est **grisé ET expliqué** et jamais décoché d'office, ajout
d'audience sur le vocabulaire réellement attribuable du type, et **aperçu du plan
résolu avant enregistrement** par le backend d'aperçu obtenu du registre — clôture
visible, dossiers de membres repliés, note de passage vers les dossiers profonds. La
règle du grisé et la déclaration des plafonds viennent du **backend**, jamais d'une
constante d'écran : c'est ce qui permettra à un partage Nextcloud d'afficher son
propre `non_exprimable` (plafond de sous-dossier) sans que l'écran change. **Zéro
diff moteur** : aucune classe PHP nouvelle, `app/` et `database/` intouchés ;
l'enregistrement n'exécute rien et ne matérialise rien.)*
