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

---

## Section 2 — Listing & lecture GPO (Story 16.2)

**Date livraison** : 2026-05-11
**Migrations à appliquer** : aucune (décision SM D3 — pas de tables Eloquent)
**Permission requise** : `server.admin`
**Pages livrées** : `/app/gpo` (listing) + `/app/gpo/{guid}` (détail lecture seule)

> Story utilisateur : première capacité native GPO. Listing filtrable + page détail
> avec containers, liens AD, héritage, encart "sections natives". Bouton "Éditer dans
> l'ancienne UI" (shim legacy) présent sur chaque GPO.

**Pré-requis supplémentaires** :
- Au moins 1 GPO créée dans l'AD (ex. la GPO `redirections` installée par défaut SE_FS).
- Ticket Kerberos valide (`klist` → ticket non expiré).
- Samba AD opérationnel (`samba-tool gpo listall` retourne ≥ 1 GPO).

### Scénario 2.1 — Navigation vers `/app/gpo` (listing)

1. Se connecter en `admin` (avec permission `server.admin`).
2. Naviguer vers `/app/gpo`.
3. Vérifier que la page charge sans erreur : titre "Gestion des GPOs", tableau des GPOs.
4. Vérifier que le sidebar affiche bien "Gestion des GPOs" avec le lien vers `/app/gpo`
   (et non plus vers l'ancienne `/gpo/gestion_gpo.php`).
5. Vérifier que les GPOs réelles de l'AD apparaissent (au moins `Default Domain Policy`,
   `Default Domain Controllers Policy`, `redirections`).

### Scénario 2.2 — Recherche par nom dans le listing

1. Sur `/app/gpo`, saisir `redirect` dans le champ de recherche.
2. Vérifier que seule la GPO `redirections` reste affichée.
3. Effacer la recherche → toutes les GPOs réapparaissent.
4. Vérifier que l'URL contient `?search=redirect` (sync `#[Url]`).

### Scénario 2.3 — Filtre statut Active/Inactive

1. Sur `/app/gpo`, sélectionner "Actives (version > 0)" dans le select de statut.
2. Vérifier que les GPOs avec `version = 0` (inactives) disparaissent du tableau.
3. Sélectionner "Inactives (version = 0)" → seules les GPOs non déployées s'affichent.
4. Sélectionner "Toutes les GPOs" → retour à l'état initial.

### Scénario 2.4 — Clic sur une GPO → page détail

1. Sur `/app/gpo`, cliquer sur la GPO `redirections` (via le lien "Voir détail" ou nom cliquable).
2. Vérifier que l'URL est `/app/gpo/{GUID-de-redirections}` (avec accolades).
3. Vérifier l'affichage :
   - Titre H2 : `redirections`.
   - Version format `major.minor` (ex. `0.3`).
   - Bouton "Retour au listing" → ramène à `/app/gpo`.
   - Bouton "Éditer dans l'ancienne UI" → présent.
4. Vérifier la section "Containers liés" : liste des OUs/Sites/Domain.
5. Pour chaque container affiché : badge "Héritage actif" ou "Héritage bloqué" visible.

### Scénario 2.5 — Bouton "Éditer dans l'ancienne UI" (Décision D2)

1. Sur `/app/gpo/{guid}` (page détail d'une GPO), cliquer le bouton "Éditer dans l'ancienne UI".
2. Vérifier qu'un **nouvel onglet** s'ouvre (attribut `target="_blank"`).
3. Vérifier que l'URL ouverte contient `/gpo/gestion_gpo.php?selectionne=<nom-encodé>`.
4. L'onglet legacy doit charger la page d'édition correspondante (ou l'index si le paramètre
   `selectionne` n'est pas supporté par le shim — c'est acceptable).

### Scénario 2.6 — Catchall redirect `gpo/gestion_gpo.php` → `/app/gpo`

1. Dans un navigateur authentifié (`server.admin`), aller directement sur
   `https://<vm>/gpo/gestion_gpo.php`.
2. Attendu : redirection transparente vers `/app/gpo` (302).
3. Vérifier que les logs ne contiennent pas d'erreur PHP legacy pour ce path
   (`tail -f storage/logs/gpo/gpo-*.log`).

### Scénario 2.7 — Vérification des logs `action_type` GPO

1. Sur la VM, ouvrir un tail des logs GPO :
   ```bash
   tail -f /var/www/sambaedu-reload/storage/logs/gpo/gpo-$(date +%Y-%m-%d).log
   ```
2. Naviguer vers `/app/gpo` → vérifier l'entrée `action_type=gpo.list`, `outcome=success`.
3. Cliquer sur une GPO → vérifier :
   - `action_type=gpo.show` avec `gpo_name={GUID}`, `outcome=success`.
   - `action_type=gpo.containers.list`, `outcome=success`.
   - `action_type=gpo.link.get` × N containers (N ≤ 5 par défaut).
   - `action_type=gpo.inheritance.get` × N containers.
4. Vérifier qu'aucun log `action_type=gpo.*` n'est émis depuis la SFC directement
   (les logs ne doivent venir que de `GpoService`).

### Scénario 2.8 — Refus d'accès sans `server.admin`

1. Se connecter en utilisateur sans `server.admin` (ex. `eleve`).
2. Tenter `https://<vm>/app/gpo` → doit retourner 403.
3. Tenter `https://<vm>/app/gpo/{un-guid-valide}` → doit retourner 403.
4. Vérifier qu'aucun appel `samba-tool` n'a eu lieu (logs GPO vides pour cet utilisateur).

### Scénario 2.9 — Cas d'erreur : samba-tool indisponible

1. Sur la VM, renommer temporairement le binaire :
   ```bash
   mv /usr/bin/samba-tool /usr/bin/samba-tool.bak
   ```
2. Naviguer vers `/app/gpo` en tant qu'admin.
3. Attendu :
   - Toast d'erreur : "Impossible de charger les GPOs : ...".
   - Tableau vide (ou message "Aucune GPO").
   - Bouton "Réessayer" visible.
   - Log `outcome=failure` dans `storage/logs/gpo/gpo-*.log`.
4. Restaurer : `mv /usr/bin/samba-tool.bak /usr/bin/samba-tool`.

### Scénario 2.10 — Encart "Sections gérables nativement" (heuristique D9)

1. Sur `/app/gpo`, cliquer sur la GPO nommée `redirections`.
2. Vérifier la présence de l'encart "Sections de cette GPO gérables nativement"
   avec un bouton "Gérer les profils itinérants nativement" → lien `/admin/settings?tab=profils-itinerants`.
3. Sur une GPO custom sans nom reconnu (ex. `Default Domain Policy`),
   vérifier que l'encart **n'apparaît pas** (aucune heuristique matchante).

### Checklist rapide Story 16.2

- [ ] Page `/app/gpo` accessible pour utilisateur `server.admin` (200)
- [ ] Page `/app/gpo` → 403 pour utilisateur sans permission
- [ ] Listing des GPOs réelles affiché (minimum 2 GPOs sur VM)
- [ ] Recherche par nom fonctionne (filtre temps réel)
- [ ] Filtre statut Active/Inactive cohérent avec `versionNumber`
- [ ] Clic sur GPO → page détail `/app/gpo/{guid}` (200)
- [ ] Détail : containers, liens, héritage affichés correctement
- [ ] Bouton "Éditer dans l'ancienne UI" ouvre l'onglet legacy (`target=_blank`)
- [ ] `gpo/gestion_gpo.php` → redirection 302 vers `/app/gpo`
- [ ] `gpo/wine.php` → NE PAS être redirigé (cohabitation D5)
- [ ] GUID malformé `/app/gpo/injection` → 404 sans log GPO
- [ ] Encart "sections natives" présent pour GPO `redirections`
- [ ] Logs `gpo.list`, `gpo.show`, `gpo.containers.list` visibles en VM
- [ ] Sidebar → lien "Gestion des GPOs" pointe vers `/app/gpo` (pas l'ancienne URL)

---

## Section 3 — Liens profonds sections natives (Story 16.3a)

**Date livraison** : 2026-05-11
**Migrations à appliquer** : aucune (story de pure navigation UI)
**Permission requise** : `server.admin` (pages GPO) + permissions propres aux pages cibles
**Pages enrichies** :
- `/app/gpo` — nouvelle colonne "Édition native"
- `/app/gpo/{guid}` — CTAs natifs primaires + bouton legacy dégradé
**Pages cibles breadcrumb** :
- `/app/parc-settings/wallpapers`
- `/app/parc-settings/app-customizations`
- `/app/shortcuts`
- `/admin/settings?tab=profils-itinerants`

> Story de pure navigation : résolution heuristique via `NativeSectionResolver` (classe
> stateless) sur le `displayName` de la GPO. Aucun appel `samba-tool` additionnel.
> Breadcrumb de retour déclenché par le paramètre `?from_gpo={guid}` dans l'URL.

**Pré-requis supplémentaires** :
- Au moins 1 GPO avec `displayName` contenant l'un des mots-clés : `firefox`, `thunderbird`,
  `wallpaper`, `shortcut`, `raccourci`, `redirections`, `roaming`, `profil`, `no_roam`.
- Sur la VM : GPO nommée `firefox-policy-test` ou `wallpaper-default` pour tester le chip.
- Permission `wallpaper.manage` pour accéder à `/app/parc-settings/wallpapers`.
- Permission `app.customize` pour accéder à `/app/parc-settings/app-customizations`.

### Scénario 3.1 — Chip "Édition native" visible dans le listing

1. Se connecter en `admin` (`server.admin`).
2. Naviguer vers `/app/gpo`.
3. Localiser une GPO dont le `displayName` contient `firefox`, `wallpaper`, `shortcut` ou `roaming`
   (ex. `firefox-policy`, `wallpaper-default`, `shortcuts-eleves`, `redirections`).
4. Vérifier la colonne **"Édition native"** (avant "Actions") :
   - Un chip `badge-success` vert est affiché avec le nombre de sections (ex. "1 section").
   - Au survol, un tooltip affiche le libellé de la section.
5. Sur une GPO sans nom reconnu (ex. `Default Domain Policy`), vérifier que la cellule affiche
   uniquement un tiret gris — **aucun chip vert**.

### Scénario 3.2 — Clic sur le chip → navigation vers la section native avec breadcrumb

1. Sur `/app/gpo`, localiser une GPO avec `displayName=wallpaper-default` (ou similaire).
2. Cliquer sur le chip "1 section" dans la colonne "Édition native".
3. Vérifier la navigation vers `/app/parc-settings/wallpapers?from_gpo={GUID}`.
4. Sur la page wallpapers, vérifier la présence du **breadcrumb** :
   - Bouton "← Retour à la GPO «wallpaper-default»" en haut de la page.
   - Le bouton pointe vers `/app/gpo/{GUID}`.
5. Cliquer le breadcrumb → retour sur la page détail de la GPO d'origine (contexte préservé).

### Scénario 3.3 — GPO sans match → bouton legacy primaire, pas de CTAs natifs

1. Sur `/app/gpo`, cliquer sur une GPO dont le nom n'est pas reconnu (ex. `Default Domain Policy`).
2. Sur la page détail `/app/gpo/{guid}` :
   - Vérifier que l'encart "Sections de cette GPO gérables nativement" **n'apparaît pas**.
   - Vérifier que le bouton "Éditer dans l'ancienne UI" est en **style primaire** (`btn-primary btn-sm`).
   - Vérifier qu'aucun sous-texte "Non recommandé" n'est affiché.

### Scénario 3.4 — Multi-match : plusieurs CTAs natifs en page détail

1. Sur la VM, créer (ou localiser) une GPO avec `displayName=firefox-wallpaper-test`
   (matche à la fois `firefox` → app-customizations et `wallpaper` → wallpapers).
2. Naviguer vers `/app/gpo/{guid}` (détail de cette GPO).
3. Vérifier que **2 boutons CTA natifs** sont présents en header (avant le bouton legacy) :
   - "Gérer les fonds d'écran" → `/app/parc-settings/wallpapers?from_gpo={GUID}`
   - "Personnaliser les applications" → `/app/parc-settings/app-customizations?from_gpo={GUID}`
4. Vérifier que le bouton "Éditer dans l'ancienne UI" est **dégradé** (`btn-ghost btn-xs`)
   avec le sous-texte "Non recommandé — utilisez les CTAs natifs ci-dessus."
5. Dans le listing `/app/gpo`, vérifier que la colonne "Édition native" affiche un **dropdown**
   ("2 sections") avec les 2 liens listés à l'intérieur.

### Scénario 3.5 — Breadcrumb de retour : navigation complète A→B→A

1. Depuis `/app/gpo/{guid}` (GPO `redirections`), cliquer le CTA natif "Gérer les profils itinérants".
2. Vérifier l'arrivée sur `/admin/settings?tab=profils-itinerants&from_gpo={GUID}`.
3. Vérifier que le breadcrumb "← Retour à la GPO «redirections»" s'affiche **uniquement**
   quand l'onglet actif est "Profils itinérants" (pas sur l'onglet "Quotas & FS").
4. Changer d'onglet (cliquer "Quotas & FS") → vérifier que le breadcrumb **disparaît**.
5. Cliquer le breadcrumb → retour sur `/app/gpo/{GUID}` (page détail de la GPO).

### Scénario 3.6 — Fallback breadcrumb si la GPO référencée a été supprimée

1. Construire une URL manuelle : `/app/parc-settings/wallpapers?from_gpo=%7BDEADBEEF-0000-0000-0000-000000000000%7D`
   (GUID inexistant dans l'AD).
2. Naviguer vers cette URL.
3. Vérifier que **le fallback générique** s'affiche : "← Retour à la liste des GPOs"
   (et non une erreur 500 ou un crash de la page).
4. Vérifier que la page wallpapers est **totalement fonctionnelle** malgré le GUID invalide.
5. Cliquer le lien fallback → navigation vers `/app/gpo` (liste principale).

### Checklist rapide Story 16.3a

- [ ] Colonne "Édition native" visible dans le tableau `/app/gpo`
- [ ] Chip `badge-success` affiché pour GPOs avec displayName matchant l'heuristique
- [ ] Cellule vide (tiret) pour GPOs sans match
- [ ] Match unique → lien direct cliquable sur le chip
- [ ] Multi-match → dropdown DaisyUI avec N liens
- [ ] Page détail `/app/gpo/{guid}` : CTAs natifs primaires visibles si match
- [ ] Plusieurs CTAs côte à côte pour multi-match
- [ ] Bouton legacy dégradé (`btn-ghost btn-xs`) + sous-texte "Non recommandé" si match
- [ ] Bouton legacy reste primaire si pas de match (non-régression 16.2)
- [ ] Paramètre `?from_gpo={guid}` présent dans les URLs CTAs (page détail + listing)
- [ ] Breadcrumb "Retour à la GPO" affiché sur wallpapers, app-customizations, shortcuts
- [ ] Breadcrumb sur admin/settings **uniquement** sur tab `profils-itinerants`
- [ ] Fallback générique "Retour à la liste des GPOs" si GUID introuvable
- [ ] Page cible toujours fonctionnelle si `?from_gpo` est invalide (pas de 500)
- [ ] Tests `php artisan test tests/Feature/Gpo tests/Unit/Gpo` → 100% vert

---

## Section 4 — Endpoints runtime postes clients : network_out / veyon_out (Story 16.3b)

**Date livraison** : 2026-05-12
**Migrations à appliquer** : aucune
**Permission requise** : aucune (endpoints publics ; garde = `id` md5 APCu)
**Routes ajoutées** :
- `Route::match(['GET','POST'], 'gpo/network_out.php', NetworkOutController@legacyOut)` (`throttle:300,1`)
- `Route::match(['GET','POST'], 'gpo/veyon_out.php', VeyonOutController@legacyOut)` (`throttle:300,1`)

**Contexte** : ces 2 endpoints sont consommés au startup/logon par les postes
Linux via la GPO `se4_applications`. Iso-contrat URL legacy obligatoire (les
URLs sont en dur dans des scripts déployés sur le parc).

**Pré-requis** :
- VM SambaEdu accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- Au moins 1 poste Linux joint au domaine pour le smoke « depuis poste »
- Optionnel : un poste avec Veyon Master installé (scénario 4.2 supervision)
- Variables `$VALID_ID` capturée via `apcu_fetch` (cf. récupération scénario 4.0)

### Scénario 4.0 — Récupération d'un `id` valide (pré-requis tous scénarios)

1. Sur la VM, faire boot ou logon d'un poste test → l'`id` md5 32 hex est posé
   en APCu (clé `apps.$id`, TTL 1800s).
2. Récupérer un `id` actif :
   ```bash
   # Via le legacy logs ou un dump apcu :
   apcu-cli getall | grep '^apps\.' | head -1
   # Sinon depuis les logs poste : /tmp/network-startup-*.log
   ls -t /tmp/network-startup-*.log | head -1 | sed 's|.*startup-\(.*\)\.log|\1|'
   ```
3. Exporter `VALID_ID=<l'id récupéré>` pour les scénarios suivants.

### Scénario 4.1 — `network_out.php` startup Linux → script bash valide

1. `curl -s -X POST -d "action=startup" -d "os=linux" -d "id=$VALID_ID" http://localhost/gpo/network_out.php`
2. Vérifier `Content-Type: text/plain; charset=utf-8`.
3. Vérifier que le body **commence** par `#!/bin/bash\n#startup\n# script de configuration du reseau Linux\n`.
4. Vérifier l'absence de `\r\n` : `curl ... | file -` ne mentionne pas `CRLF`.
5. Passer le script en mode syntax check : `curl ... | bash -n` → exit 0
   (pas d'erreur de syntaxe bash).
6. Vérifier que `/tmp/network-startup-$VALID_ID.log` est écrit avec le même contenu.

### Scénario 4.2 — `network_out.php` logon Linux → gnome_proxy

1. `curl -s -X POST -d "action=logon" -d "os=linux" -d "id=$VALID_ID" http://localhost/gpo/network_out.php`
2. Vérifier que le body contient `gsettings set org.gnome.system.proxy mode` (mode = `none`, `manual` ou `auto` selon config).
3. Vérifier que `/tmp/network-logon-$VALID_ID.log` est écrit.

### Scénario 4.3 — `network_out.php` os=windows → body vide (iso-legacy bug)

1. `curl -s -o /dev/null -w "%{http_code}\n" -X POST -d "action=startup" -d "os=windows" -d "id=$VALID_ID" http://localhost/gpo/network_out.php`
2. Vérifier code `200` et body vide. **C'est un bug legacy reproduit intentionnellement** (cf. story 16.3b AC1.6 + post-review 2026-05-12 : 200 strict iso-legacy, pas 204).

### Scénario 4.4 — `network_out.php` id invalide → 200 body vide sans side effect

1. `curl -s -o /dev/null -w "%{http_code}\n" -X POST -d "action=startup" -d "os=linux" -d "id=INJECTION" http://localhost/gpo/network_out.php`
2. Vérifier code `200` (post-review 2026-05-12 : iso-legacy strict).
3. Vérifier qu'aucun fichier `/tmp/network-startup-INJECTION.log` n'est créé.
4. Vérifier dans les logs Laravel qu'aucun appel `apcu_fetch` n'a eu lieu pour cet id.

### Scénario 4.5 — `veyon_out.php` cas nominal → JSON parseable

1. `curl -s -X POST -d "id=$VALID_ID" http://localhost/gpo/veyon_out.php | jq .`
2. Vérifier `Content-Type: application/json; charset=utf-8`.
3. Vérifier la présence des clés : `.LDAP.BaseDN`, `.LDAP.BindDN`, `.LDAP.BindPassword`, `.LDAP.ServerHost`, `.LDAP.ServerPort = 389`, `.AccessControl.AuthorizedUserGroups`.
4. Vérifier que `.LDAP.BindPassword` est une chaîne hex (`[0-9a-f]+`).

### Scénario 4.6 — Création AD `read.user{suffix}` au 1er appel

1. **Pré-requis** : `read_ldap_password` vide en config (`grep read_ldap_password /etc/sambaedu/sambaedu.conf*`).
2. Premier appel : `curl -s -X POST -d "id=$VALID_ID" http://localhost/gpo/veyon_out.php > /tmp/veyon-out.json`
3. Vérifier la création AD : `ldapsearch -H ldap://localhost -b "$(grep ldap_base_dn /etc/sambaedu/sambaedu.conf | cut -d= -f2- | tr -d ' \"')" "(cn=read.user*)" cn`
   → 1 entrée retournée.
4. Vérifier que `read_ldap_password` est désormais persistée en config.
5. Vérifier `tail /var/log/sambaedu/laravel.log` → `[ReadUserManager] read.user created`.

### Scénario 4.7 — Race condition multi-postes simultanés

1. **Pré-requis** : `read_ldap_password` vide (supprimer la clé en config si besoin pour rejouer).
2. Lancer 20 requêtes parallèles :
   ```bash
   seq 1 20 | xargs -P 20 -I{} curl -s -X POST -d "id=$VALID_ID" http://localhost/gpo/veyon_out.php -o /tmp/veyon-{}.json
   ```
3. Vérifier qu'**une seule entrée** AD `read.user*` existe :
   `ldapsearch ... "(cn=read.user*)" | grep -c "^dn:"` doit retourner 1.
4. Vérifier dans les logs : 1 seul log `[ReadUserManager] read.user created`,
   les 19 autres prennent le path « réutilise password existant ».

### Scénario 4.8 — `veyon_out.php?licence=1` → fichier vlf

1. **Avec fichier présent** : `ls /etc/sambaedu/applications/veyon/licence.vlf` (créer un fichier test si absent).
2. `curl -s -X POST -d "licence=1" http://localhost/gpo/veyon_out.php -o /tmp/lic.vlf`
3. Vérifier `Content-Type: application/octet-stream`.
4. Vérifier que `diff /tmp/lic.vlf /etc/sambaedu/applications/veyon/licence.vlf` est silencieux (contenu identique).
5. **Sans fichier** : renommer temporairement → re-curl → body vide, status 200 octet-stream.

### Scénario 4.9 — Veyon Master réel consomme la config

1. Sur un poste Veyon Master : `Configuration → Importer depuis fichier` → charger `/tmp/veyon-out.json`.
2. Vérifier que la salle (parc) du poste apparaît dans l'arborescence.
3. Sélectionner 1 poste → cliquer « Démo prof » → vérifier la connexion VNC réussie.
4. **Si échec bind LDAP** : vérifier dans Veyon Master logs que `BindPassword` est bien la valeur retournée (déchiffrement OAEP côté Veyon doit fonctionner).

### Scénario 4.10 — Interception native prioritaire sur catchall legacy

1. `curl -s -o /dev/null -w "Server: %{http_connect}\nName: %{header_x-laravel-route}\n" http://localhost/gpo/network_out.php?id=INJECTION`
2. Vérifier que la route Laravel matchée est bien `gpo.network-out.legacy` (et non le catchall).
3. Smoke alternatif : `php artisan route:list | grep gpo/network_out.php` doit lister une seule entrée pointant vers `NetworkOutController@legacyOut`.

### Checklist rapide Story 16.3b

- [ ] Scénarios 4.1 / 4.2 passent (bash valide, syntax check OK)
- [ ] `/tmp/network-{action}-{id}.log` écrit avec le contenu retourné
- [ ] `os=windows` retourne **200 body vide** (legacy bug iso reproduit, post-review : 200 strict)
- [ ] `id` malformé retourne **200 body vide** sans appel APCu / exec / LDAP
- [ ] `veyon_out.php` cas nominal retourne JSON parseable avec section LDAP complète
- [ ] Création AD `read.user{suffix}` se produit sur 1er appel sans `read_ldap_password`
- [ ] Stress test 20 requêtes parallèles → 1 seul compte AD créé (lock OK)
- [ ] `BindPassword` est hex et décodable PKCS1_OAEP avec la clé privée correspondante
- [ ] `licence=1` retourne le fichier `.vlf` raw quand présent, vide sinon
- [ ] Veyon Master importe la config → salle visible + connexion VNC OK
- [ ] Routes natives interceptent AVANT catchall (`php artisan route:list`)
- [ ] Tests `php artisan test tests/Feature/Gpo tests/Unit/Gpo tests/Unit/Ldap tests/Unit/Config` → 100% vert

### Post-correctifs Story 16.3b (review fixes 2026-05-12)

> Décision Henri **option A complète** : `AdUserManager` natif + `SambaEduConfig::set` natif. Scénarios QA ajoutés pour valider la création native + drift recovery.

| # | Incident review | Scénario QA |
|---|-----------------|-------------|
| #1+#2 | Création AD native (shim non-fonctionnel remplacé par `AdUserManager`) | **Installation vierge sans `read_ldap_password`** : (1) sur la VM, supprimer la ligne `read_ldap_password` de `/etc/sambaedu/sambaedu.conf` ; (2) premier `curl -X POST -d "id=$VALID_ID" http://localhost/gpo/veyon_out.php` ; (3) vérifier création AD via `samba-tool user list \| grep read.user` ; (4) vérifier persistance config `grep read_ldap_password /etc/sambaedu/sambaedu.conf` ; (5) vérifier log `tail /var/log/sambaedu/laravel.log \| grep '[AdUserManager] user created'` (channel `gpo`). |
| #M1 | Drift recovery silencieux → désormais bruyant (retour `null`) | **Forcer reset manuel pwd AD `read.user`** : (1) `samba-tool user setpassword read.user --newpassword=Different-Pwd-12345` (sans toucher la config Laravel) ; (2) `curl -X POST -d "id=$VALID_ID" http://localhost/gpo/veyon_out.php > /tmp/veyon-drift.json` ; (3) attendu : la recovery tente un re-push via `AdUserManager::setPassword`. Si elle échoue (ex : politique pwd violée par la valeur en config), le JSON est servi **sans `BindPassword`** (`jq '.LDAP \| has("BindPassword")'` → `false`) ; (4) vérifier log `error` visible `tail /var/log/sambaedu/laravel.log \| grep 'drift recovery failed'`. |
| #M3 | `Cache-Control: no-store` sur licence fallback | (1) Sans fichier `licence.vlf` : `curl -i -X POST -d "licence=1" http://localhost/gpo/veyon_out.php` → vérifier header `Cache-Control: no-store, no-cache, must-revalidate` (évite cache proxy de la réponse vide). |
| #M4 | `Log::debug` (vs `info`) sur context expiré | Reproduire 50 requêtes avec `id` md5 random valide (mais absent d'APCu) → vérifier que les 50 logs ne pollutent pas `laravel.log` au niveau `info` (le channel `daily` n'enregistre debug que si `LOG_LEVEL=debug`). |
| #4/#5 | HTTP 200 iso-legacy strict | Re-tester scénarios 4.3, 4.4 : le code HTTP **doit être 200** (et plus 204). `curl -s -o /dev/null -w "%{http_code}\n"` doit retourner `200` pour tous les paths body vide. |
