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

---

## Story 16.3c — Wine (UI admin native) + Associations apps (endpoint runtime)

**Date livraison** : 2026-05-12
**Migrations à appliquer** : aucune
**Permission requise** : `server.admin` (UI Wine) / aucune (endpoint runtime Associations)

### Scénario 5.1 — Endpoint `associations_out.php` : cas nominal

1. Sur la VM, identifier un id valide en cours dans APCu :
   `php -r 'foreach (apcu_cache_info()["cache_list"] as $e) if (strpos($e["info"], "apps.") === 0) echo $e["info"] . PHP_EOL;'`
2. Capturer un `list` JSON minimal côté poste : `LIST='{"file":[".html,FirefoxHTML"]}'`
3. Exécuter :
   `curl -s -X POST -d "id=$VALID_ID" --data-urlencode "list=$LIST" http://localhost/gpo/associations_out.php`
4. Attendu : status `200`, header `Content-Type: text/json`, body JSON parseable
   avec section `result` contenant les associations à appliquer côté poste
   (delta vs `$LIST`).
5. Vérifier `/tmp/assoc_result.json` est écrit après l'appel.

### Scénario 5.2 — Endpoint `associations_out.php` : id invalide → 400

1. `curl -i -X POST -d "id=INJECTION" -d "list={}" http://localhost/gpo/associations_out.php`
2. Attendu : status `400 Bad request`, body vide. Aucun appel `apcu_fetch` /
   `WorkstationPackagesResolver` / `DOMDocument` dans les logs.

### Scénario 5.3 — Endpoint `associations_out.php` : list absent → 400

1. `curl -i -X POST -d "id=$VALID_ID" http://localhost/gpo/associations_out.php`
2. Attendu : status `400`, body vide.

### Scénario 5.4 — Endpoint `associations_out.php` : list > 10 Ko → 400

1. `LIST=$(printf '%.0s.x,Y\n' {1..5000})`
2. `curl -i -X POST -d "id=$VALID_ID" --data-urlencode "list=$LIST" http://localhost/gpo/associations_out.php`
3. Attendu : status `400` (validation taille AVANT json_decode).

### Scénario 5.5 — Endpoint `associations_out.php` : APCu expiré → 400

1. Forcer expiration : `apcu_delete "apps.$VALID_ID"` côté VM
2. `curl -i -X POST -d "id=$VALID_ID" -d "list={}" http://localhost/gpo/associations_out.php`
3. Attendu : status `400`, body vide, log `daily` debug `context expired`
   (pas info — éviter pollution boot de masse).

### Scénario 5.6 — Endpoint `associations_out.php` : `packages.xml` absent → gracieux

1. Renommer temporairement le `packages.xml` :
   `mv /var/sambaedu/unattended/install/wpkg/packages.xml{,.bak}`
2. `curl -X POST -d "id=$VALID_ID" -d "list={}" http://localhost/gpo/associations_out.php`
3. Attendu : status `200`, body `{"result": {}}` (parité legacy gracieux).
4. Log `daily` warning `packages.xml absent`.
5. Restaurer : `mv /var/sambaedu/unattended/install/wpkg/packages.xml{.bak,}`

### Scénario 5.7 — UI Wine : accès admin

1. Se connecter en `admin` avec permission `server.admin`.
2. Naviguer vers `/app/gpo/wine`.
3. Attendu : page rendue avec :
   - Sidebar GPO (cohérence 16.2)
   - Bandeau d'info expliquant l'activation Wine sur les postes Linux
   - `<select>` avec « Conteneur par défaut (.wine) » + N options `wine-<X>`
     correspondant au scan FS de `/var/sambaedu/unattended/install/wine/`
   - 2 boutons « Générer l'image » (primary) et « Générer les raccourcis » (secondary)
4. Vérifier l'attribut `selected` posé sur la bonne option (bug `wine.php:52`
   NON reproduit).

### Scénario 5.8 — UI Wine : 403 sans permission `server.admin`

1. Se connecter en user lambda (élève, prof, sans `server.admin`).
2. `GET /app/gpo/wine`
3. Attendu : `403 Forbidden`.

### Scénario 5.9 — UI Wine : génération image (Job dispatché)

1. En admin, naviguer vers `/app/gpo/wine`.
2. Sélectionner un conteneur dans le `<select>` (ou laisser défaut).
3. Cliquer « Générer l'image ».
4. Modale de confirmation s'ouvre : « La génération peut prendre ~10 minutes…
   Confirmer ? ». Cliquer « Lancer la génération ».
5. Toast info « L'image Wine est en cours de génération (≈ 10 min)… operation_id `<UUID>` ».
6. Vérifier : `php artisan queue:work --once` consomme le Job, lance
   `/usr/share/sambaedu/scripts/make_wine_image.sh <prefix>`.
7. Vérifier les logs : `tail -f storage/logs/gpo/*.log` doit afficher :
   - `[gpo] gpo.wine.image.generate start` (sub `queuer.dispatch`)
   - `[gpo] gpo.wine.image.generate step: queued`
   - `[gpo] gpo.wine.image.generate success`
   - `[gpo] gpo.wine.image.generate start` (sub `job.handle`, même `operation_id`)
   - `[gpo] gpo.wine.image.generate step: invoking make_wine_image.sh`
   - `[gpo] gpo.wine.image.generate success`

### Scénario 5.10 — UI Wine : idempotence (lock anti-double-dispatch)

1. Cliquer « Générer l'image » pour `firefox`, confirmer.
2. Sans attendre la fin du Job, cliquer **à nouveau** « Générer l'image »
   pour le même `firefox`.
3. Attendu : toast **warning** « Une génération est déjà en cours pour ce
   conteneur. Réessayez à la fin du Job actuel… »
4. Vérifier : un seul Job en queue (`php artisan queue:monitor`).
5. À la fin du Job (success ou failure), le lock est libéré → relance OK.

### Scénario 5.11 — UI Wine : génération raccourcis

1. Pré-requis : avoir un conteneur Wine installé sur la VM avec des
   raccourcis `.desktop` dans `/home/se4install/Bureau/`.
2. En admin, sélectionner ce conteneur, cliquer « Générer les raccourcis ».
3. Attendu : toast success « X raccourci(s) Wine ajouté(s) à `shortcuts.json` ».
4. Vérifier : `/etc/sambaedu/applications/shortcuts/shortcuts.json` enrichi.
5. Vérifier l'atomic write : `ls -la /etc/sambaedu/applications/shortcuts/*.tmp*`
   ne doit retourner aucun fichier orphelin.

### Scénario 5.12 — UI Wine : sécurité whitelist regex

1. Tentative d'injection : ouvrir DevTools, modifier la valeur du `<select>`
   (Livewire wire:model) en `; rm -rf /`.
2. Cliquer « Générer l'image ».
3. Attendu : toast error « Conteneur Wine invalide. Caractères autorisés :
   lettres, chiffres, point, tiret, underscore. ». **Aucun Job dispatché**.
4. Vérifier les logs : pas d'`action_type: gpo.wine.image.generate`.

### Scénario 5.13 — Redirect `/gpo/wine.php` → `/app/gpo/wine`

1. `curl -i -L --max-redirs 1 http://localhost/gpo/wine.php`
2. Attendu : `302 Found` → `Location: /app/gpo/wine`.
3. Idem avec query string : `curl -i http://localhost/gpo/wine.php?action=foo`
   → `302` vers `/app/gpo/wine`.

### Scénario 5.14 — Route native `associations_out.php` prioritaire sur catchall

1. `php artisan route:list | grep associations_out`
2. Attendu : une seule entrée `POST gpo/associations_out.php` pointant vers
   `AssociationsOutController@legacyOut` (pas catchall).
3. Smoke alternatif : `curl -s -o /dev/null -w "%{http_code}\n" -X GET
   http://localhost/gpo/associations_out.php` → status défini par le catchall
   (legacy ou 404), **pas** 200 native.

### Scénario 5.15 — Catalogue logs gpo : Wine vs Associations

1. Wine (admin audit) : `grep 'gpo.wine.image.generate' /var/log/sambaedu/gpo*.log`
   → channel `gpo`, formatage 3 logs `start`/`step`/`end` avec `operation_id`.
2. Associations (runtime poste) : `grep 'AssociationsOutController' /var/log/sambaedu/laravel.log`
   → channel `daily`, niveau `debug` (context expired) ou `error` (exception
   resolve). PAS de format `[gpo] action_type:...` (parité runtime endpoints).

### Scénario 5.16 — `apps.$id` toujours posé par `applications.php` (chaîne intacte)

1. Sur le poste, déclencher un logon → `applications.php` shim s'exécute.
2. Sur la VM : `php -r 'echo apcu_fetch("apps.<id>") !== false ? "OK" : "EXPIRED" . PHP_EOL;'`
3. Attendu : `OK` (la chaîne shim 1bis-18e n'a pas régressé). Si `EXPIRED`,
   les endpoints 4.7/4.8/16.3b/16.3c retomberont silencieusement sur leurs
   paths dégénérés (200 body vide ou 400) — **incident bloquant** à signaler.

### Checklist rapide Story 16.3c

- [ ] Scénarios 5.1 / 5.7 passent (associations endpoint nominal + UI Wine accessible)
- [ ] `id` malformé / `list` absent / `list > 10Ko` retournent **400 body vide** sans accès APCu/Eloquent
- [ ] `packages.xml` absent retourne `{"result": {}}` (status 200 gracieux)
- [ ] `/tmp/assoc_result.json` écrit après cas nominal, **pas** les 3 autres legacy
- [ ] Page `/app/gpo/wine` accessible avec `server.admin`, 403 sinon
- [ ] Click « Générer l'image » → modale confirmation → Job en queue → script shell exécuté par worker
- [ ] Double-click rapide « Générer l'image » → 2ᵉ refusé (toast warning lock idempotence)
- [ ] Click « Générer les raccourcis » → `shortcuts.json` enrichi (atomic write OK)
- [ ] Injection `; rm -rf /` dans `selectedApplication` → toast error, aucun Job dispatché
- [ ] Redirect `/gpo/wine.php` → `/app/gpo/wine` (302)
- [ ] Route native `associations_out.php` (POST) prioritaire sur catchall
- [ ] `apps.$id` toujours posé par `applications.php` shim (chaîne intacte)
- [ ] Tests `php artisan test tests/Feature/Gpo tests/Unit/Gpo tests/Feature/ShortcutsService tests/Architecture` → 100% vert

### Post-correctifs & non-régressions (Story 16.3c)

Append-only. Incidents/correctifs post-review claude-opus-4-7 (2026-05-12) couverts par les fixes — cf. `_bmad-output/codeReviews/16-3c.md`.

| Scénario              | Incident d'origine                                                                                                                                                | Couverture / non-régression                                                                                                                                                  |
|-----------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 5.17 Dead code Wine   | `WineController.php` jamais appelé (route Livewire filesystem-router) → dette d'entretien + ambiguïté pattern.                                                    | Fichier supprimé. `grep -rn "WineController" app/ docs/` doit retourner 0 résultat. README `app/Gpo/README.md` mentionne désormais explicitement la route Livewire native.   |
| 5.18 Throttle endpoint Associations | Pas de test fonctionnel `throttle:300,1` côté `AssociationsOutEndpointTest` (présent dans 16.3b NetworkOut).                                            | Test `it_applies_throttle_300_per_minute` ajouté (smoke iso pattern 16.3b). Existence middleware couverte par `AssociationsOutRouteRegistrationTest`.                        |
| 5.19 Fixture comparison iso sample XML | Fixture `legacy-associations-out.json` artisanal incomplet vs `packages-xml-sample.xml` (manquait `.htm`/`https`) → `AssociationsOutComparisonTest` cassé en CI. | Fixture régénéré cohérent (5 entries : `.jpg` default.xml + `.html`/`.htm`/`http`/`https` firefox). Marker `requires-fixture-capture` conservé (rappel capture VM réelle future). |
| 5.20 Regex `parseLocalAssocs` greedy iso-legacy | Regex native `^\s*(.*?)\s*,\s*(.*?)\s*$` (non-greedy) ≠ legacy greedy → input `".html,Foo,Bar"` capturait `(".html", "Foo,Bar")` au lieu de `(".html,Foo", "Bar")`. | Regex passée en greedy `/^\s*(.*)\s*,\s*(.*)$/`. Test unit `parse_local_assocs_uses_greedy_split_on_last_comma_iso_legacy` valide la sémantique iso-bytes parc-wide.        |


## Section 6 — Story 16.7 : Portage natif `gpo/applications.php` (endpoint amont)

Story 16.7 (2026-05-13) porte natif l'endpoint le plus complexe d'Epic 16 :
l'endpoint serveur qui POSE `apps.$id` consommé par tous les out précédents
(`firefox_out` 4.8, `wallpaper_out` 4.7, `network_out`/`veyon_out` 16.3b,
`associations_out` 16.3c).

### 6.1 Boot poste Windows à froid (action Henri)

**Setup** :
- 1 poste Windows joint au domaine SE4FS, parc `windows`.
- Logs server : `tail -F storage/logs/laravel-*.log /var/log/sambaedu/gpo-*.log`.

**Étapes** :
1. Démarrer le poste (cold boot).
2. Au boot `startup` : la GPO `se4_applications` appelle `curl http://se4fs/gpo/applications.php`.
3. **Vérifier** côté serveur :
   - HTTP 200 avec body cmd (vérifier `/tmp/applications-startup-*.cmd` côté serveur).
   - APCu : `apcu_fetch("apps.<id>")` retourne dict avec `machine.cn`, `os: 'windows'`, `action: 'startup'`.
   - `MachineBootLog` : ligne créée avec `error_flags = 256` (SAMBAEDU_STARTUP_APP_ERROR) au début, mis à 0 en fin.
   - Log `gpo` : `action_type = ad.machine.check` step `create` ou `exists` selon état AD initial.
   - Log `gpo` : `action_type = gpo.applications.context.put` avec `operation_id` UUID.
4. **Vérifier** côté poste : le `.cmd` est téléchargé dans `%windir%`, exécuté, le script appelle ensuite `firefox_out`/`network_out` etc.

### 6.2 Boot poste Linux LTSP

**Setup** : 1 poste Linux LTSP, hostname `l-pc-test` (le préfixe `l-` est strippé natif iso-legacy).

**Vérifier** :
- Recherche LDAP via `pc-test` (sans `l-`).
- Output `text/plain; charset=utf-8`, body bash `#!/bin/bash`...
- `liste_applications` consommée par les services suivants (firefox_out/etc.) — pas d'"edge" pour Linux.

### 6.3 Logon utilisateur (Windows)

**Étapes** :
1. Ouvrir une session avec un user AD (`jdupont`).
2. La GPO appelle `applications.php?action=logon&user=jdupont&machine=pc01`.
3. **Vérifier** :
   - **AUCUN** appel `AdMachineManager::check/registerHardware/setOs` (startup-only).
   - **UN seul** appel `AdMachineManager::listRemoteConnexion` (logon-only).
   - APCu `apps.$id` mis à jour avec `user.cn = 'jdupont'`, `list_u` contenant les groupes AD du user.
   - `MachineBootLog` ligne créée `action = 'logon'`, `error_flags = 1024`.

### 6.4 Logoff utilisateur — clean-up APCu

**Étapes** :
1. Fermer la session, attendre l'appel `applications.php?action=logoff&ret=0`.
2. **Vérifier** : `apcu_fetch("apps.$id")` retourne `false` (entrée supprimée par `AppContextWriter::forget`).
3. `apcu_fetch("scripts.$id")` aussi supprimé.

### 6.5 logon-system (poste Linux LTSP boot sans user)

**Étapes** :
1. Boot poste Linux LTSP, action `logon-system` (script admin exécuté en root après login user).
2. **Vérifier** : context = `system`, `local_admin_scripts` invoqué.

### 6.6 Injection regex bloquée AVANT toute exec

**Étapes** :
1. `curl -X POST http://se4fs/gpo/applications.php -F "machine=; rm -rf /" -F "action=startup"`.
2. **Vérifier** : 400 Bad Request, **aucun** log `samba-tool` exec, **aucune** mutation AD/FS/APCu.

### 6.7 Boot de masse parc (stress test ~20 postes simultanés)

**Setup** : 20 postes bootant ensemble (rentrée scolaire).

**Vérifier** :
- Aucun lock APCu (`apcu_store` atomique).
- Throttle `300/min/IP` ne bloque pas un boot normal (chaque poste fait ~5 requêtes max).
- `AdMachineManager::check` idempotent (`already exists` géré silencieusement).
- `MachineBootLog` ligne par poste/action sans collision.

### 6.8 Comparaison iso-bytes avec capture fixture legacy

**Action Henri** : capturer 2 scripts générés par le legacy AVANT bascule (poste de test reproductible) :
```
# Capture legacy startup Windows
curl -F "machine=pc-test" -F "action=startup" -F "os=windows" \
     http://se4fs-legacy/gpo/applications.php > tests/Fixtures/Gpo/legacy-applications-startup-windows.cmd

# Capture legacy logon Linux
curl -F "machine=pc-test" -F "action=logon" -F "os=linux" -F "user=jdupont" \
     http://se4fs-legacy/gpo/applications.php > tests/Fixtures/Gpo/legacy-applications-logon-linux.sh
```

Comparer ensuite avec sortie native (même inputs) par `cmp -b`. Diff = 0 byte → iso-bytes OK.

### Checklist rapide Story 16.7

- [ ] Scénario 6.1 passe (boot Windows : MachineBootLog + APCu + script .cmd OK)
- [ ] Scénario 6.2 passe (boot Linux LTSP : préfixe `l-` strippé, UTF-8)
- [ ] Scénario 6.3 passe (logon : pas de side effect startup AD, listRemoteConnexion appelé)
- [ ] Scénario 6.4 passe (logoff/shutdown clean APCu)
- [ ] Scénario 6.6 passe (injection bloquée 400 SANS appel AD)
- [ ] Scénario 6.7 passe (boot 20 postes sans race APCu / throttle ne bloque pas)
- [ ] Scénario 6.8 fixtures capturées (action Henri)
- [ ] `tail -F storage/logs/gpo-*.log` ne contient AUCUN `action_type = gpo.applications.context.put` avec keys inattendues
- [ ] Chaîne native intacte : `POST applications.php` + `GET firefox_out.php?id=...` retourne bien la policy attendue (test AC7.5)
- [ ] `php artisan test tests/Unit/Gpo tests/Unit/Ldap tests/Feature/Gpo tests/Architecture` 100% vert (hors limitations env Mockery uopz/runkit)


## Section 7 — Story 16.5 : Liaison GPO ↔ OU AD + propagation

Story 16.5 (2026-05-13) est la **première story write AD** d'Epic 16. Elle
implémente les 3 stubs `setLink` / `removeLink` / `setInheritance` de
`GpoService` (Story 16.1) + ajoute une nouvelle méthode `reorderLinks` non
atomique (rollback best effort). UI Livewire SFC dédiée
`/app/gpo/{guid}/links` + enrichissement du détail GPO (CTA + encart Impact).

### Scénario 7.1 — Naviguer vers la page liaisons et vérifier l'affichage

**Setup** : 1 GPO de test (« redirections-test ») liée à 1 OU AD (`OU=Test,DC=…`),
avec quelques postes Eloquent dans cette OU.

**Étapes** :
1. Se connecter avec un user `server.admin`.
2. Naviguer vers `/app/gpo/{GUID}/links`.
3. **Vérifier** :
   - HTTP 200, header avec displayName + GUID.
   - Section "Liens actuels" liste l'OU avec : nom OU, DN tronqué, badge
     "Héritage actif", badge "Position 1 / 1", badge "Actif" (ou Forcé /
     Désactivé selon flags).
   - Bouton "Ajouter une liaison" présent.
   - Encart "Création GPO" pied de page avec lien vers
     `/gpo/gpo-maj.php` (target=_blank).
   - Total agrégé en bas de la section indiquant le nombre de postes.

### Scénario 7.2 — Ajouter une liaison vers une nouvelle OU

**Étapes** :
1. Cliquer "Ajouter une liaison".
2. La modale `<x-molecules.modal>` s'ouvre avec :
   - Champ recherche OU (filtre côté Livewire).
   - `<select>` listant toutes les OUs candidates (hors celles déjà liées).
3. Sélectionner `OU=Test2,DC=…` et cliquer "Confirmer".
4. **Vérifier** :
   - Toast vert "Liaison créée — GPO liée à l'OU avec succès."
   - Log `storage/logs/gpo/gpo-{date}.log` : `action_type=gpo.link.add`,
     `target_dn=OU=Test2,DC=…`, `gpo_name={GUID}`, `outcome=success`.
   - Côté AD : `samba-tool gpo getlink OU=Test2,DC=…` retourne la GPO.
   - Section "Liens actuels" rechargée affiche maintenant 2 OUs.

### Scénario 7.3 — Désactiver une liaison (toggle disabled)

**Étapes** :
1. Sur l'OU `OU=Test,DC=…`, cliquer "Désactiver" (le bouton power-off).
2. Modale de confirmation avec message contextualisé.
3. Cliquer "Confirmer".
4. **Vérifier** :
   - Toast vert "Liaison mise à jour — Liaison désactivée."
   - Logs `gpo` : 1 × `gpo.link.remove` + 1 × `gpo.link.add` (avec `disable=true`)
     + 1 × `gpo.link.toggle.disabled` (avec `from=false`, `to=true`).
   - Badge "Désactivé" remplace "Actif" dans l'UI.
   - Sur un poste de test : `gpupdate /force` puis `gpresult /r` →
     la GPO « redirections-test » n'est plus dans la liste appliquée.

### Scénario 7.4 — Forcer une liaison (toggle enforced)

**Étapes** :
1. Sur une liaison non forcée, cliquer "Forcer".
2. Modale → Confirmer.
3. **Vérifier** :
   - Logs `gpo.link.toggle.enforced` avec `from=false`, `to=true`.
   - Badge "Forcé" apparaît.
   - Les OUs enfants ne peuvent plus surclasser cette GPO (mécanique
     Windows GPO standard).

### Scénario 7.5 — Réordonner deux liaisons sur une OU

**Setup** : `OU=Test,DC=…` a 2 GPOs liées (position 1 et 2). Notre GPO de
test est en position 2.

**Étapes** :
1. Cliquer "↑ Monter" sur notre GPO.
2. Modale → Confirmer.
3. **Vérifier** :
   - Logs `gpo` : `action_type=gpo.link.order.update`, `step` détaillé
     ("reading current links for rollback", "removing existing links",
     "adding links in target order"), `outcome=success`.
   - Côté AD : `samba-tool gpo getlink OU=Test,DC=…` retourne nos 2 GPOs
     dans le NOUVEL ordre (notre GPO en première position).
   - UI rechargée affiche "Position 1 / 2" sur notre GPO.

### Scénario 7.6 — Délier une GPO d'une OU

**Étapes** :
1. Cliquer "Délier" sur une OU.
2. Modale de confirmation avec bouton **rouge** "Confirmer".
3. **Vérifier** :
   - Toast vert "Liaison supprimée".
   - Log `gpo.link.remove` `outcome=success`.
   - L'OU disparaît de la section "Liens actuels".
   - Sur poste de test : `gpupdate /force` puis `gpresult /r` → la GPO
     n'est plus appliquée aux postes de cette OU.

### Scénario 7.7 — Bloquer l'héritage sur une OU

**Étapes** :
1. Sur une OU, cliquer le badge "Héritage actif" (devient bouton).
2. Modale → Confirmer.
3. **Vérifier** :
   - Logs `gpo.inheritance.set` avec `enabled=false`.
   - Badge "Héritage bloqué" remplace "Héritage actif".
   - Sur poste de test dans cette OU : `gpresult /r` → les GPOs des
     OUs parents ne s'appliquent plus (sauf liaisons forcées).

### Scénario 7.8 — Tentative d'accès sans permission `server.admin`

**Étapes** :
1. Se connecter avec un user `eleve` (sans permission).
2. Tenter d'accéder à `/app/gpo/{GUID}/links`.
3. **Vérifier** : HTTP 403 (middleware route + `mount()` abort_unless).

### Scénario 7.9 — Input malformé (GUID invalide)

**Étapes** :
1. Avec un user admin, naviguer vers `/app/gpo/INJECTION_ATTACK/links`.
2. **Vérifier** :
   - HTTP 404 (rejeté par la regex de route).
   - **Aucun** log `gpo.sambatool.exec` n'apparaît (input rejeté en amont).
   - **Aucun** log `gpo.link.*`.

### Scénario 7.10 — Vérification rollback `reorderLinks` en cas d'erreur

**Setup** synthétique : provoquer un échec mi-rollback (ex. supprimer
manuellement les droits du compte sur l'OU via `samba-tool gpo setinheritance`
juste après le premier `dellink`).

**Vérifier** :
- Le service tente le rollback (logs `step: apply phase failed — initiating
  rollback`).
- Si rollback OK : retour `false`, état initial restauré, toast `error`
  utilisateur — état AD cohérent.
- Si rollback KO : `RuntimeException` levée, log `step: rollback FAILED — état
  AD potentiellement incohérent`. Action manuelle requise (cf. TD-16.5-1).

### Scénario 7.11 — Redirection vers shim pour création GPO

**Étapes** :
1. Sur la page `/app/gpo/{GUID}/links`, vérifier que l'encart en pied de page
   indique « Vous souhaitez créer, dupliquer ou supprimer une GPO ? ».
2. Cliquer le bouton "Ouvrir dans l'ancienne UI".
3. **Vérifier** : ouverture nouvel onglet sur `/gpo/gpo-maj.php` (shim legacy
   1bis-18, cohabitation 16-4 paused).

### Checklist rapide Story 16.5

- [ ] Scénario 7.1 passe (page rendue avec liens existants + badges)
- [ ] Scénario 7.2 passe (ajout liaison → AD writeback + log + UI refresh)
- [ ] Scénario 7.3 passe (toggle disabled propage en `gpupdate`)
- [ ] Scénario 7.5 passe (réordonnancement effectif côté AD)
- [ ] Scénario 7.6 passe (suppression liaison propage)
- [ ] Scénario 7.7 passe (bloquer héritage propage)
- [ ] Scénario 7.8 passe (403 sans permission)
- [ ] Scénario 7.9 passe (404 input invalide SANS appel samba-tool)
- [ ] Scénario 7.10 testé une fois (rollback `reorderLinks`)
- [ ] Logs `storage/logs/gpo/gpo-*.log` contiennent les 6 nouveaux
      `action_type` (`gpo.link.add`, `gpo.link.remove`, `gpo.link.order.update`,
      `gpo.link.toggle.disabled`, `gpo.link.toggle.enforced`, `gpo.inheritance.set`)
- [ ] `php artisan test tests/Unit/Gpo/GpoServiceWriteTest tests/Feature/Gpo/GpoLinksPageTest
      tests/Feature/Gpo/GpoLinksPagePermissionTest tests/Architecture/GpoNamespaceTest`
      100% vert.


---

## Section 8 — Hook GPO ↔ WPKG (Story 16.6)

**Date livraison** : 2026-05-13
**Migrations à appliquer** : aucune (lecture seule sauf via shim `import_gpo`).
**Permission requise** : `server.admin`
**Pré-requis** :
- Template officiel `/usr/share/sambaedu/gpo/se4_wpkg.zip` présent.
- Endpoints `/wpkg/hosts.xml` et `/wpkg/profiles.xml` actifs (Story 15.2 done).
- Shim legacy `legacy/bootstrap.php` chargé (`sambaedu/includes/gpo.inc.php`
  expose `import_gpo` et `specialise_gpo`).

### Scénario 8.1 — Audit initial sur VM réelle

**Étapes** :
1. Sur la VM `/vm`, lancer `php artisan wpkg:gpo:sync --audit-only`.
2. **Vérifier** :
   - Exit code 0 (severity `ok`) si la GPO `se4_wpkg` existe et est liée.
   - Tableau affiché contenant `gpoExists=true`, `gpoGuid={...}`,
     `linkedOus=[OU=Computers,...]`, `templateExists=true`.
   - URLs `expectedHostsXmlUrl` et `expectedProfilesXmlUrl` pointent vers le
     domaine `SE4FS_NAME` configuré (ex. `http://se4fs.lycee.example/wpkg/...`).

### Scénario 8.2 — Audit initial via UI Livewire

**Étapes** :
1. Se connecter en admin (`server.admin`), naviguer vers
   `/app/gpo/wpkg-deployment`.
2. **Vérifier** :
   - Badge sévérité affiché en haut (`OK` vert si tout est en ordre).
   - 4 tableaux : État GPO / Liaisons / URLs serveur attendues / Couverture Bearer.
   - `operation_id` UUID affiché en pied du bloc statut.
   - Bouton "Re-publier la GPO `se4_wpkg`" rouge danger présent.

### Scénario 8.3 — Audit GPO non liée → warning visible

**Setup** : depuis `/app/gpo/{guid}/links`, dé-lier la GPO `se4_wpkg` de toutes
les OUs (clic "Délier" + confirmation modale).

**Étapes** :
1. Naviguer vers `/app/gpo/wpkg-deployment`.
2. **Vérifier** :
   - Badge sévérité passe à `WARNING`.
   - Encart "GPO non liée — aucun poste ne déclenchera `wpkg.js`" visible.
   - Bouton "Lier maintenant" présent qui redirige vers `/app/gpo/{guid}/links`.
3. Re-lier la GPO et confirmer que l'audit repasse à `OK`.

### Scénario 8.4 — Re-publication forcée (`--force`)

**Étapes** :
1. Sur la VM, lancer `php artisan wpkg:gpo:sync --force`.
2. **Vérifier** :
   - Logs `storage/logs/gpo/gpo-*.log` contiennent au moins 4 entrées avec
     les `action_type` : `gpo.wpkg.sync.start`, `gpo.wpkg.template.spec`,
     `gpo.wpkg.publish`, `gpo.wpkg.sync.end`.
   - Tous les logs partagent le même `operation_id` UUID.
   - SYSVOL est mis à jour : `ls -lat /var/lib/samba/sysvol/<domain>/Policies/{GUID}`
     montre des fichiers récents (mtime).
   - Exit code 0.
3. Re-lancer `--force` immédiatement → la GPO est re-importée idempotemment
   (le shim legacy `import_gpo` gère le `update=true`).

### Scénario 8.5 — Smoke poste Windows réel

**Pré-requis** : un poste Windows lié à une OU sur laquelle la GPO `se4_wpkg`
est appliquée.

**Étapes** :
1. Sur la VM, lancer `php artisan wpkg:gpo:sync --force`.
2. Sur le poste Windows : `gpupdate /force` (PowerShell admin).
3. Redémarrer le poste.
4. **Vérifier** :
   - Pendant le boot, le script `.cmd` startup est exécuté (le client
     `cscript wpkg.js /server=<SE4FS_NAME> /profile=<hostname>` se déclenche).
   - Côté serveur : `tail -f /var/log/nginx/access.log | grep wpkg` montre
     des hits sur `/wpkg/hosts.xml?poste=<hostname>` et
     `/wpkg/profiles.xml?poste=<hostname>` venant de l'IP du poste.
   - `tail -f storage/logs/wpkg-deploy/deploy-*.log` montre la réception du
     rapport POST `/api/v1/wpkg/reports/<hostname>` (Story 15.5).

### Scénario 8.6 — Bearer manquant signalé (mode tolérant DO2)

**Pré-requis** : table `workstation_api_secrets` migrée (Story 15.5 Phase 2).

**Étapes** :
1. Sur la VM, révoquer le secret d'un poste lié : la table
   `workstation_api_secrets.revoked_at IS NOT NULL` pour ce poste.
2. Recharger `/app/gpo/wpkg-deployment`.
3. **Vérifier** :
   - Section "Couverture Bearer Phase 2" affiche `N-1/N postes couverts`.
   - Mode tolérant par défaut (`bearer_required = false`) → severity reste `OK`
     ou `WARNING` selon le contexte, **pas** `ERROR`.
   - Détail expandable liste le nom du poste sans secret.
4. Activer `sambaedu.gpo.wpkg_sync.bearer_required = true` dans la config →
   severity bumpe à `WARNING` (≤10% manquant) ou `ERROR` (>10% manquant).

### Scénario 8.7 — Template absent → message d'erreur explicite

**Étapes** :
1. Sur la VM, renommer temporairement le template :
   `mv /usr/share/sambaedu/gpo/se4_wpkg.zip /tmp/se4_wpkg.zip.bak`.
2. Recharger `/app/gpo/wpkg-deployment`.
3. **Vérifier** :
   - Badge sévérité passe à `ERROR`.
   - Tableau "État GPO" affiche `Template présent ? : Absent` (badge error).
   - Message diagnostic : « Template officiel `/usr/share/sambaedu/gpo/se4_wpkg.zip`
     non trouvé sur le serveur ».
4. Lancer `php artisan wpkg:gpo:sync --force` → exit code 3 +
   `RuntimeException: Template officiel ... introuvable`.
5. Restaurer le template : `mv /tmp/se4_wpkg.zip.bak /usr/share/sambaedu/gpo/se4_wpkg.zip`.

### Scénario 8.8 — Concurrence : lock `gpo:wpkg:sync` bloque le 2e processus

**Étapes** :
1. Ouvrir 2 terminaux SSH sur la VM.
2. Terminal 1 : `php artisan wpkg:gpo:sync --force` (laisser tourner).
3. Terminal 2 : `php artisan wpkg:gpo:sync --force` immédiatement.
4. **Vérifier** :
   - Terminal 2 reçoit `RuntimeException: Synchronisation GPO se4_wpkg déjà en
     cours par un autre processus (lock indisponible après 10s)`.
   - Exit code 3.
   - Pas de double-import SYSVOL (Terminal 1 termine proprement).

### Scénario 8.9 — Permission 403 sans `server.admin`

**Étapes** :
1. Avec un user `eleve` (sans permission), accéder à `/app/gpo/wpkg-deployment`.
2. **Vérifier** : HTTP 403 (middleware route + `abort_unless` dans `mount()`).
3. Vérifier qu'aucun log `gpo.wpkg.sync.*` n'est émis (mock should-not-receive).

### Scénario 8.10 — Sortie JSON cron-friendly

**Étapes** :
1. Sur la VM, lancer `php artisan wpkg:gpo:sync --audit-only --json | jq .`.
2. **Vérifier** :
   - Sortie JSON bien formée parsable par `jq`.
   - Contient les clés : `gpoExists`, `gpoGuid`, `linkedOus`, `severity`,
     `templatePath`, `expectedHostsXmlUrl`, `messages`, `operationId`.
   - Exit code mappé sur severity (0/1/2 selon `ok`/`warning`/`error`).

### Checklist rapide Story 16.6

- [ ] Scénario 8.1 passe (audit CLI exit 0 + tableau lisible).
- [ ] Scénario 8.2 passe (UI Livewire affiche 4 tableaux + badge OK).
- [ ] Scénario 8.3 passe (warning non liée → CTA "Lier maintenant").
- [ ] Scénario 8.4 passe (re-publish + 4 action_type cumulés dans log channel `gpo`).
- [ ] Scénario 8.5 passe (poste Windows réel hit `/wpkg/hosts.xml` après reboot).
- [ ] Scénario 8.6 passe (couverture Bearer signalée — mode tolérant).
- [ ] Scénario 8.7 passe (template renommé → erreur explicite UI + CLI).
- [ ] Scénario 8.8 passe (lock concurrent bloque correctement).
- [ ] Scénario 8.9 passe (403 sans `server.admin`).
- [ ] Scénario 8.10 passe (JSON parsable + exit code mappé sévérité).
- [ ] `php artisan test tests/Unit/Gpo/WpkgGpoSynchronizerTest tests/Unit/Gpo/Dto/WpkgGpoSyncReportTest
      tests/Feature/Gpo/WpkgDeploymentPageTest tests/Feature/Gpo/WpkgDeploymentPagePermissionTest
      tests/Feature/Console/Wpkg/WpkgGpoSyncCommandTest tests/Architecture/GpoNamespaceTest`
      100% vert.

### Post-correctifs & non-régressions (2026-05-13)

Suite à la review adversariale, les changements de comportement
suivants sont à valider en T8 :

- **#3 (T8.1 critique)** : l'appel séparé à `specialise_gpo` côté natif
  a été supprimé. `WpkgGpoSynchronizer::publish()` n'invoque plus que
  `legacy.import_gpo` qui enchaîne en interne `unzip_gpo → specialise_gpo →
  sysvol_put`. **Impact attendu : zéro** côté postes (le legacy continue
  de faire la spécialisation correctement). À confirmer T8.1 : après
  `php artisan wpkg:gpo:sync --force`, inspecter le contenu d'un fichier
  spécialisé sous `\\<DC>\sysvol\<domain>\Policies\{GUID}\Machine\Scripts\Startup\wpkg.cmd`
  → vérifier que `###_SE4FS_NAME_###` est bien substitué par la valeur
  réelle (et idem pour `DOMAIN`, `DOMAIN_SID`, etc.).
- **#10/#4** : `Cache::lock('gpo:wpkg:sync', N)` utilise désormais
  N=300 s (TTL) et 30 s (wait) par défaut. Au lieu de 60/10. Le lock est
  configurable via `GPO_WPKG_LOCK_TIMEOUT` / `GPO_WPKG_LOCK_WAIT` env
  vars. Smoke T8.6 (lock concurrence) : ouvrir 2 sessions admin et
  vérifier que la 2e attend jusqu'à 30 s avant `RuntimeException`.
- **#1** : 4 clés `config('sambaedu.gpo.wpkg_sync.*')` désormais
  déclarées dans `config/sambaedu.php` (`template_path`, `bearer_required`,
  `lock_timeout`, `lock_wait`). Override possible via env vars. Aucun
  smoke supplémentaire — déjà couvert par le test unitaire
  `publish_lock_values_are_configurable`.
- **#C / #D / #F** : garde-fous défensifs (zip bomb / utf16 decode /
  workstation truncation) avec logs warning sur le channel `gpo`.
  Smoke T8.4 : vérifier l'absence de warnings sur un template VM
  réel (`se4_wpkg.zip` ≤ quelques Mo, pas d'utf16 mixte attendu).
