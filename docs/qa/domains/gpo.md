# QA manuel — Domaine GPO (Group Policy Objects)

> Runbook E2E pour les stories du domaine GPO. Append-only : chaque story
> ajoute une section avec ses scénarios numérotés stables.

**Pré-requis** :

- VM accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- Migrations à jour : `cd /var/www/sambaedu-reload && php artisan migrate`
- Cache Spatie reset si évolutions permission : `php artisan permission:cache-reset`
- Samba AD opérationnel : ticket Kerberos valide pour le compte machine
  (`klist` → ticket `host/<dc>` non expiré).
- GPO `redirections` existante dans l'AD Samba (créée historiquement par
  l'installeur SE_FS — vérifier via `samba-tool gpo listall`).
- Cron `du.sh` opérationnel pour les stats `/tmp/du.txt` (hors scope SER —
  cf. `sambaedu/cron/du.sh` legacy).

---

## Story 1bis.18f — Profils itinérants (refonte native + bridge SYSVOL)

**Date livraison** : 2026-04-28
**Migrations à appliquer** : aucune (UI uniquement, persistance = SYSVOL legacy)
**Permission requise** : `server.admin`

### Scénario 1bis.18f-1 — Ouverture de l'onglet « Profils itinérants »

1. Se connecter en `admin` (`server.admin`).
2. Naviguer vers `/admin/settings`.
3. Vérifier la présence d'un second bouton tab `<i class="fa-solid fa-users-gear">` « Profils itinérants » à côté de « Quotas & FS ».
4. Cliquer dessus → l'URL devient `/admin/settings?tab=profils-itinerants` (synchro `#[Url(keep: true)]`).
5. Vérifier l'affichage des deux cards :
   - « Exclusions du profil itinérant » (avec boutons « Ajouter une exclusion » et « Mettre à jour la GPO »).
   - « Statistiques des profils itinérants ».

### Scénario 1bis.18f-2 — Ajout d'une exclusion via la modale

1. Sur l'onglet « Profils itinérants », cliquer « Ajouter une exclusion ».
2. La modale s'ouvre (header « Ajouter une exclusion », champ texte préfixé par `%USERPROFILE%\`).
3. Saisir `AppData/Local/Mozilla` puis cliquer « Ajouter ».
4. Toast success « Exclusion ajoutée. » + la modale se ferme + l'item apparaît dans la liste.
5. Vérifier en VM que la GPO `redirections` (User Configuration / Registry.pol) contient bien la nouvelle valeur :
   ```bash
   ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
   smbclient //$DOMAIN/SYSVOL --use-kerberos=required \
     -c "get $DOMAIN/Policies/$REDIRECTIONS_GUID/User/Registry.pol /tmp/registry.pol"
   # Inspecter avec un outil adéquat (ex: poltool, ou samba-tool gpo show).
   ```

### Scénario 1bis.18f-3 — Suppression d'une exclusion

1. Sur la liste des exclusions, cliquer « Supprimer » sur un item.
2. Confirmer le `wire:confirm` natif Livewire.
3. Toast success « Exclusion supprimée. » + l'item disparaît de la liste.
4. Vérifier en VM idem scénario précédent.

### Scénario 1bis.18f-4 — « Mettre à jour la GPO » (version bump)

1. Avec au moins une exclusion ajoutée/modifiée, cliquer « Mettre à jour la GPO ».
2. Toast success « GPO mise à jour. Les postes Windows recevront le changement à leur prochaine application de stratégie. ».
3. Vérifier en VM que :
   - Le numéro de version de la GPO `redirections` a été incrémenté (`samba-tool gpo show <GUID>` → champ `version`).
   - Le fichier JSON applicatif `/etc/sambaedu/applications/gpos.json` contient une entrée `redirections / local / ExcludeProfileDirs` avec les valeurs courantes.
4. Sur un poste Windows joint au domaine, après `gpupdate /force` + relogin, vérifier la clé Registry `HKEY_CURRENT_USER\Software\Policies\Microsoft\Windows\System\ExcludeProfileDirs`.

### Scénario 1bis.18f-5 — Drill-down stats utilisateurs

1. Sur la card « Statistiques des profils itinérants », cliquer sur une ligne (ou sur le bouton « Détail »).
2. La modale « Détail des profils — <chemin> » s'ouvre.
3. Vérifier le tableau Utilisateur / Taille (Mo).
4. Cliquer « Fermer » → modale fermée.

### Scénario 1bis.18f-6 — Endpoint `del-roam.sh` (auth `se4_key` valide)

1. Récupérer la valeur de `SE4_KEY` dans `/etc/sambaedu/credentials.json` (ou `.env`).
2. Sur la VM (en tant que script logon simulé) :
   ```bash
   curl -k "https://<vm>/admin/gpo/del-roam.sh?se4_key=<valeur>"
   ```
3. Attendu :
   - HTTP 200.
   - `Content-Type: text/plain; charset=UTF-8`.
   - Body commence par `# suppression des dossiers trop gros`.
   - Body se termine par la ligne `rm -fr "/home/profiles/${username}/AppData/Roaming/Mozilla/Firefox/Profiles" 2>/dev/null`.
   - Lignes intermédiaires `rm -fr "/home/profiles/${username}/<exclusion>" 2>/dev/null` cohérentes avec la GPO.

### Scénario 1bis.18f-7 — Endpoint `del-roam.sh` (auth `se4_key` invalide)

1. Sur la VM :
   ```bash
   curl -k -i "https://<vm>/admin/gpo/del-roam.sh"
   curl -k -i "https://<vm>/admin/gpo/del-roam.sh?se4_key=wrong-key"
   ```
2. Attendu : HTTP 403 dans les deux cas.
3. Vérifier les logs : `tail -f /var/www/sambaedu-reload/storage/logs/laravel.log | grep AllowSe4FsScript`
   → entrée `[AllowSe4FsScript] Accès refusé`.

### Scénario 1bis.18f-8 — Redirections legacy `gpo/no_roam.php`

1. En navigateur authentifié (`server.admin`) :
   - `https://<vm>/gpo/no_roam.php` → 302 vers `/admin/settings?tab=profils-itinerants`.
   - `https://<vm>/gpo/user_profile_stats.php` → 302 vers `/admin/settings?tab=profils-itinerants`.
   - `https://<vm>/gpo/del_roam.php?se4_key=<valeur>` → 302 vers `/admin/gpo/del-roam.sh?se4_key=<valeur>` (query string préservée).
2. Vérifier que la redirection est gérée en early-return dans `LegacyCatchallController::handle()` (avant tout pipeline d'exécution legacy) — pas d'entrée `[LegacyCatchall]` dans les logs pour ces paths spécifiques.

### Scénario 1bis.18f-9 — Refus d'accès sans `server.admin`

1. Se connecter en utilisateur `eleve` (sans `server.admin`).
2. Tenter `/admin/settings?tab=profils-itinerants` → 403 (middleware `can:server.admin`).
3. En tant qu'admin connecté, tenter de forger un payload Livewire pour appeler `addExclusion` sur le composant — la double Gate en première ligne de chaque méthode publique doit retourner 403.

### Scénario 1bis.18f-10 — GPO `redirections` introuvable (mode dégradé)

1. Sur une VM de test où la GPO `redirections` n'existe pas, ouvrir l'onglet « Profils itinérants ».
2. Attendu : alerte `<x-molecules.alert variant="warning">` « Impossible de charger la GPO `redirections`. Vérifiez que la GPO existe sur l'AD Samba. » + log `[RoamingProfileService] GPO redirections introuvable` dans `storage/logs/laravel.log`.
3. La page reste fonctionnelle (pas de crash 500). La liste d'exclusions affichée est vide.

## Post-correctifs & non-régressions

| Date       | Story    | Incident / Fix                                                                                                                                                                                                  | Scénario QA dédié    |
|------------|----------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------------------|
| 2026-04-28 | 1bis.18f | **Defense-in-depth `getExclusions`** — la lecture filtre désormais les valeurs GPO héritées non-conformes (regex anti path-traversal) avec log warning, pour éviter la divergence affichage/persistance lors d'un `applyToGpo`. | 1bis.18f-11          |
| 2026-04-28 | 1bis.18f | **Garde anti-écrasement total** — `setExclusions` refuse explicitement (`RuntimeException`) si toutes les valeurs ont été filtrées (au lieu d'écrire une politique vide qui pourrait bumper une GPO incrémentée). | 1bis.18f-12          |
| 2026-04-28 | 1bis.18f | **Log debug del-roam.sh** — `Log::info` remplacé par `Log::debug` (les logon scripts produisaient des centaines d'entrées/jour sans valeur diagnostique).                                                       | 1bis.18f-13          |

### Scénario 1bis.18f-11 — Filtrage défensif des valeurs GPO héritées

1. Sur la VM, injecter manuellement dans la GPO `redirections` une valeur d'exclusion non-conforme (ex. `AppData\Local\Bad;rm -rf` via un client GPMC Windows ou via `samba-tool gpo`).
2. Ouvrir l'onglet « Profils itinérants » dans `/admin/settings`.
3. Attendu : la valeur non-conforme **n'apparaît pas** dans la liste affichée. Une entrée `[RoamingProfileService] Valeur d'exclusion héritée filtrée à la lecture (regex anti path-traversal)` est présente dans `storage/logs/laravel.log`.
4. Cliquer « Mettre à jour la GPO ». La valeur non-conforme **n'est pas re-persistée** (suppression silencieuse confirmée — symétrie complète lecture↔écriture).

### Scénario 1bis.18f-12 — Refus écriture filtrage total

1. Forger une requête Livewire `setExclusions(['../bad', 'foo;rm -rf'])` (uniquement valeurs malformées) via un payload modifié (curl + token CSRF + état Livewire).
2. Attendu : 500 (RuntimeException catchée par le composant, toast d'erreur générique, **pas de mise à jour de la GPO**) + log warning `[RoamingProfileService] Refus écriture GPO : toutes les valeurs ont été filtrées`.
3. Vérifier que la GPO sur la VM n'a **pas** été incrémentée (lecture du fichier `Registry.pol` avant/après — version inchangée).

### Scénario 1bis.18f-13 — Log debug `del-roam.sh` (pas de flood `info`)

1. En condition normale d'exploitation (logon scripts Windows actifs), monitorer `storage/logs/laravel.log` pendant 1 heure.
2. Attendu : **0 entrée** `[AllowSe4FsScript] Accès autorisé` au niveau `info` (le canal Monolog par défaut filtre `debug` en prod).
3. Si le log channel est temporairement abaissé en `debug` (debug d'incident), les entrées apparaissent — c'est le comportement souhaité.
4. Les refus d'accès (`Log::warning`) restent visibles en prod par défaut → vérifier qu'une tentative depuis une IP non-whitelistée + clé invalide produit bien `[AllowSe4FsScript] Accès refusé`.

---

## Story 16.1 — Fondations GPO natives + audit legacy

**Date livraison** : 2026-05-11
**Migrations à appliquer** : aucune (décision SM D3 — pas de tables Eloquent)
**Permission requise** : aucune (story d'infrastructure, pas d'UI utilisateur)

> Story d'infrastructure pure : pose les rails du namespace `App\Gpo`, du
> channel logs `gpo`, du runner `samba-tool`, des garde-fous archi. **Aucune
> capacité utilisateur livrée** — les smoke tests valident l'infrastructure.

### Scénario 16.1-1 — Channel logs `gpo` opérationnel

1. Sur la VM : `sudo systemctl restart php8.4-fpm nginx` (rebuild containers).
2. Vérifier que le dossier `/var/www/sambaedu-reload/storage/logs/gpo/` est créé automatiquement au boot :
   ```bash
   ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
   ls -la /var/www/sambaedu-reload/storage/logs/gpo/
   ```
   → doit exister (créé par `GpoServiceProvider::ensureLogChannelDirectory()`).
3. Émettre un log de test depuis tinker :
   ```bash
   cd /var/www/sambaedu-reload && php artisan tinker
   >>> use App\Gpo\Support\GpoLogger;
   >>> $log = GpoLogger::action('gpo.list'); $log->step('test'); $log->success(['count' => 0]);
   ```
4. Vérifier que `storage/logs/gpo/gpo-YYYY-MM-DD.log` contient bien 3 lignes JSON avec `action_type=gpo.list`, `operation_id` UUID, `outcome=start|step|success`.

### Scénario 16.1-2 — `GpoService::list()` retourne la liste des GPOs

1. Sur la VM (Samba AD opérationnel) :
   ```bash
   cd /var/www/sambaedu-reload && php artisan tinker
   >>> $gpos = app(App\Gpo\Services\GpoService::class)->list();
   >>> $gpos->count();  // doit retourner ≥ 2 (Default Domain Policy + Default DC Policy + GPOs SE4)
   >>> $gpos->first()->displayName;
   ```
2. Vérifier que `storage/logs/gpo/gpo-YYYY-MM-DD.log` contient :
   - 1 log `[gpo] gpo.list start`
   - 1 log `[gpo] samba-tool exec` (debug) avec `command: ["/usr/bin/samba-tool","gpo","listall","--use-kerberos=required"]`
   - 1 log `[gpo] gpo.list success` avec `count: N`.

### Scénario 16.1-3 — Stubs d'écriture lèvent RuntimeException

1. Tinker :
   ```bash
   php artisan tinker
   >>> app(App\Gpo\Services\GpoService::class)->create('test-gpo');
   ```
2. Attendu : `RuntimeException` avec message contenant `not implemented yet — see Story 16.4`.
3. Vérifier que le log `gpo.create` est bien émis avec `outcome=failure` et `error.class=RuntimeException`.

### Scénario 16.1-4 — `GpoSyncService` legacy reste fonctionnel (non-régression)

1. Sur la VM, créer une délégation `computer.elevate` pour un user via la page `/admin/delegations` (ou via tinker `app(GpoSyncService::class)->syncGpoForGrant($user, $group)`).
2. Vérifier que l'AD est bien mis à jour : `samba-tool gpo listall | grep <delegation-name>` retourne une entrée.
3. Vérifier qu'aucune erreur n'est apparue dans `storage/logs/laravel.log` ni dans `storage/logs/gpo/gpo-YYYY-MM-DD.log` (le service legacy n'écrit pas dans le channel `gpo`).

### Scénario 16.1-5 — Boot warnings cohérents si Samba absent

1. Sur un environnement sans Samba (dev local) ou en modifiant temporairement `config('sambaedu.gpo.bin_path')` vers un path inexistant.
2. Vérifier que `storage/logs/gpo/gpo-YYYY-MM-DD.log` contient au boot un warning `[gpo] binaire samba-tool introuvable` avec le contexte `config_key` et `path`.
3. Vérifier que le boot Laravel ne casse pas (la page d'accueil reste accessible).

### Checklist rapide Story 16.1

- [ ] `storage/logs/gpo/` créé automatiquement au boot
- [ ] Channel `gpo` enregistré dans `config/logging.php`
- [ ] `GpoService::list()` retourne `Collection<GpoSummary>` non vide en VM
- [ ] Stubs écriture (`create`, `delete`, etc.) lèvent `RuntimeException` avec ref Story 16.4/16.5
- [ ] `GpoSyncService` legacy (`computer.elevate`) reste fonctionnel
- [ ] Aucun appel `exec()` direct détecté hors `SambaToolRunner` (test archi `GpoNamespaceTest`)
- [ ] Aucun `require_once 'legacy/*'` depuis `app/Gpo/*` (test archi `GpoLegacyIsolationTest`)
