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
> avec containers, liens AD, héritage, encart "sections natives".
>
> ⚠️ **Post-16.9 (cleanup 2026-05-17)** : le bouton "Éditer dans l'ancienne UI" (qui
> pointait vers `gpo/gestion_gpo.php?selectionne=<nom>`) a été retiré. Le legacy
> `gestion_gpo.php` est un menu de maintenance (maj base + export) qui ignore tout
> paramètre de sélection — le lien était trompeur. L'admin passe désormais par les
> CTAs natifs (16.3a) sur les GPOs matchant Firefox/Wallpaper/Shortcuts/Wine/Profils
> itinérants.
>
> ⚠️ **Post 2026-05-18 (Story 16-4 cancelled)** : tous les boutons/encarts UI native
> pointant vers `gpo/gpo-maj.php` (création/duplication/suppression de GPO) ont été
> retirés. Plus aucun lien UI native vers ce shim. La création de GPO se fait
> uniquement via admin SSH (`samba-tool gpo create`) ou en tapant directement l'URL
> legacy `/gpo/gpo-maj.php` dans la barre d'adresse (cohabitation Phase 2 conservée
> mais non promue).

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
   - Bouton "Gérer les liaisons" (icône lien) → présent pour `server.admin`.
4. Vérifier la section "Containers liés" : liste des OUs/Sites/Domain.
5. Pour chaque container affiché : badge "Héritage actif" ou "Héritage bloqué" visible.
6. Si la GPO ne matche aucune section native (heuristique 16.3a) : encart d'info
   "Cette page est en **lecture seule**. L'édition native arrive dans les prochaines
   stories de l'Epic 16." Sinon : un ou plusieurs CTAs natifs verts (cf. scénario 3.x).

### Scénario 2.5 — ~~Bouton "Éditer dans l'ancienne UI"~~ (RETIRÉ post-16.9)

⚠️ **Scénario obsolète** — le bouton a été retiré (cleanup 2026-05-17) car le
paramètre `?selectionne=` qu'il transmettait à `gestion_gpo.php` n'est en réalité
**pas reconnu par le legacy** (0 occurrence de `selectionne` dans `sambaedu/gpo/`).
L'admin atterrissait sur le menu de maintenance générique, jamais sur l'édition
de la GPO sélectionnée. Pour éditer une GPO : utiliser les CTAs natifs (Firefox /
Wallpaper / Shortcuts / Wine / Profils itinérants) quand l'heuristique matche.

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

### Scénario 2.10 — CTA natif en header (heuristique D9)

> ⚠️ **Post 2026-05-18** : l'encart body "Sections de cette GPO gérables nativement" a
> été retiré (doublon avec les chips header). La heuristique reste, exposée
> uniquement via les chips verts dans `<x-slot:actions>` (haut droite).

1. Sur `/admin/settings/gpo`, cliquer sur la GPO nommée `redirections`.
2. Vérifier en **haut à droite** de la page détail la présence d'un bouton vert
   `btn-success` "Gérer les profils itinérants nativement" → lien
   `/admin/settings?tab=profils-itinerants?from_gpo={GUID}`.
3. Sur une GPO custom sans nom reconnu (ex. `Default Domain Policy`),
   vérifier qu'**aucun chip vert** n'apparaît dans le header (aucune heuristique matchante)
   et que l'alert bleu "lecture seule" est présente à la place.

### Checklist rapide Story 16.2

- [ ] Page `/app/gpo` accessible pour utilisateur `server.admin` (200)
- [ ] Page `/app/gpo` → 403 pour utilisateur sans permission
- [ ] Listing des GPOs réelles affiché (minimum 2 GPOs sur VM)
- [ ] Recherche par nom fonctionne (filtre temps réel)
- [ ] Filtre statut Active/Inactive cohérent avec `versionNumber`
- [ ] Clic sur GPO → page détail `/app/gpo/{guid}` (200)
- [ ] Détail : containers, liens, héritage affichés correctement
- [ ] Bouton "Éditer dans l'ancienne UI" : ~~présent~~ **retiré post-16.9** (cf. scénario 2.5)
- [ ] `gpo/gestion_gpo.php` → redirection 302 vers `/app/gpo`
- [ ] `gpo/wine.php` → NE PAS être redirigé (cohabitation D5)
- [ ] GUID malformé `/app/gpo/injection` → 404 sans log GPO
- [ ] Chip natif `btn-success` présent dans le header pour GPO `redirections` (encart body retiré post 2026-05-18)
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
   - Vérifier qu'**aucun chip vert** `btn-success` natif n'apparaît dans le header (heuristique vide).
   - Vérifier que l'alert bleu "lecture seule" est présente en haut du body.

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

### Scénario 7.11 — ~~Redirection vers shim pour création GPO~~ (RETIRÉ 2026-05-18)

Scénario obsolète depuis l'abandon de la story 16-4 (CRUD GPO natif) le
2026-05-18. L'encart « Vous souhaitez créer, dupliquer ou supprimer une GPO ? »
et son bouton "Ouvrir dans l'ancienne UI" ont été supprimés de la page liens.
Plus aucun lien UI native vers `gpo/gpo-maj.php`.

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

---

## Story 16.8 — Stabilisation Phase 1 + audit iso-legacy (2026-05-15)

### Procédure d'exécution des tests Phase 1

Script reproductible `scripts/run-tests.sh` ajouté au dépôt :

```bash
# Suite Phase 1 (Architecture + Unit/Gpo + Unit/Ldap + Feature/Gpo) :
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 \
  'cd /var/www/sambaedu-reload && bash scripts/run-tests.sh --phase1-only'

# Suite complète (Architecture + Unit + Feature) :
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 \
  'cd /var/www/sambaedu-reload && bash scripts/run-tests.sh'
```

Le script produit :

- `storage/logs/tests/run-YYYY-MM-DDTHH-MM-SS.log` — sortie complète horodatée
- `storage/logs/tests/last-run-summary.json` — résumé synthétique (passed/failed/errors/skipped/risky/duration/exit_code)

Code de retour : `0` si exit propre, `1` sinon. À lancer manuellement avant chaque PR Phase 2.

### Seuil de fail acceptable

- **Tests Phase 1 (D4 16.8)** : 0 fail toléré. Tous les tests `tests/Architecture/`, `tests/Unit/Gpo/`, `tests/Unit/Ldap/`, `tests/Feature/Gpo/` doivent passer.
- **Tests non-Phase 1** : decision-log obligatoire pour chaque skip/delete, mais pas de fix obligatoire (cf. story 16.8 §D4).
- **Tests `@group requires-postgres`** : exclus par défaut (`phpunit.xml`).
- **Tests `@group requires-fixture-capture`** : restent skippés (capture fixtures = action Henri sur VM réelle, hors-scope dev).

### Baseline 2026-05-15 (HEAD `0a4609c`)

| Run | Scope | Passed | Failed | Skipped | Risky | Durée | Exit |
|---|---|---|---|---|---|---|---|
| Phase 1 only | 16.8 T6 | **474** | **0** | 15 | 3 | 19s | 0 |
| Suite complète | 16.8 T6.1 | 2074 | 18 (non-Phase 1) | 103 | 4 | 99s | 1 |

**18 failures non-Phase 1** = bug d'isolation pré-existant à 16.8 (les tests `LegacyBootstrapShimsTest`, `LegacyBootstrapCatchallTest`, `LegacyModulePrintersTest` passent isolément `68 passed/0 failed` mais pas dans la suite Feature complète — pollution `include_path` + fonctions globales). Hors-scope 16.8, à traiter en story dédiée tech-debt test-infra.

### Audit iso-legacy associé

- Rapport : `_bmad-output/planning-artifacts/audit-iso-legacy-2026-05-15.md`
- Volet A (`SE4FS` nu) : **0 occurrence critique** dans le code source. Tous les usages sont via substitution dynamique (`$config['se4fs_name']` / `%SE4FS%` env Windows / `###_SE4FS_NAME_###` placeholder).
- Volet B (shims 1bis.18) : 6 fichiers retirables maintenant (16.13), 3 conditionnels (Phase 3+), 2 services Laravel natifs encore dépendants de fonctions shim (`RoamingProfileService`, `WpkgGpoSynchronizer`).
- **GO 16.10 / 16.9** : aucun blocage iso-legacy identifié.
- **Action court terme avant 16.10** : refaire l'audit SYSVOL sur un serveur de **prod déployé**. Le DC dev `192.168.122.60` (domaine `localdev.fr`, credentials `.env`) est accessible via SMB mais ses 14 GPO sont vides de contenu (uniquement les `GPT.INI` metadata) — env dev propre, GPO non spécialisées. Le grep réel sur GPO peuplées doit être fait en prod.

---

## Section 9 — Exposition UI admin GPO sous `/admin/settings/gpo` (Story 16.9)

**Date livraison** : 2026-05-16 (dev claude-opus-4-7, modèle adverse pour review : sonnet).
**Migrations à appliquer** : aucune (story structurelle, pas de schéma DB modifié).
**Permission requise** : `server.admin` (Spatie) + middlewares groupe `admin` (`sambaedu.auth + sambaedu.admin`).

Cette section couvre le déplacement des **5 pages Livewire SFC GPO** depuis `/app/gpo/*` vers `/admin/settings/gpo/*` (Tech Spec §4 D2 — alignement « réglages système administrateur »). Les anciennes URLs `/app/gpo/*` continuent de fonctionner via **redirections 301 permanentes** : aucun action utilisateur n'est requise pour les bookmarks existants.

### Mapping des routes (avant → après)

| Ancienne URL                       | Nouvelle URL (16.9)                          | Mécanisme cohabitation                        |
|------------------------------------|----------------------------------------------|-----------------------------------------------|
| `/app/gpo`                         | `/admin/settings/gpo`                        | `Route::permanentRedirect` (HTTP 301)         |
| `/app/gpo/{guid}`                  | `/admin/settings/gpo/{guid}`                 | Closure `Route::get → redirect(..., 301)` (regex GUID iso 16.2 fix #9) |
| `/app/gpo/{guid}/links`            | `/admin/settings/gpo/{guid}/links`           | Closure idem                                  |
| `/app/gpo/wine`                    | `/admin/settings/gpo/wine`                   | `Route::permanentRedirect` (HTTP 301)         |
| `/app/gpo/wpkg-deployment`         | `/admin/settings/gpo/wpkg-deployment`        | `Route::permanentRedirect` (HTTP 301)         |

**Note importante sur les noms de routes Laravel** : les noms `app.gpo.*` (ex. `app.gpo.index`, `app.gpo.show`) **continuent de résoudre** — ils pointent maintenant vers les routes de redirection ci-dessus. Un appel `route('app.gpo.index')` retourne `/app/gpo` qui redirige vers `/admin/settings/gpo` (1 hop HTTP supplémentaire). Cette conservation évite un big-bang sur 30+ callsites. Toutes les callsites internes du code projet ont néanmoins été migrées vers `route('admin.gpo.*)` (vérifié par grep T8.4).

### Pré-requis spécifiques 16.9

- VM accessible (`ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`).
- Cache Spatie reset : `php artisan permission:cache-reset`.
- Compte admin avec permission `server.admin` (cf. pré-requis communs).
- Cache routes invalidé : `php artisan route:clear` après pull du code.

### Scénario 16.9-1 — Ouverture index GPO sous `/admin/settings/gpo`

**Objectif** : vérifier que la nouvelle page index est servie sous le nouveau path et est fonctionnellement identique à l'ancienne.

1. Se connecter avec un compte admin (`server.admin`).
2. Taper `https://<VM>/admin/settings/gpo` dans le navigateur.
3. La page se charge (HTTP 200) avec le titre « Gestion des GPOs ».
4. Le tableau liste les GPOs (mêmes colonnes qu'avant : Nom, Version, GUID, Path SYSVOL, **Édition native** (16.3a), Actions).
5. Les filtres (recherche, statut Active/Inactive, tri colonnes, pagination) fonctionnent.
6. ~~Le bouton « Créer une GPO (ancienne UI) »~~ — RETIRÉ 2026-05-18 (Story 16-4 cancelled). Aucun bouton de création GPO dans le header ; l'admin passe par SSH `samba-tool gpo create` ou tape directement `/gpo/gpo-maj.php` (cohabitation Phase 2 conservée mais non promue).

**Attendu** : page identique au listing servi historiquement sous `/app/gpo` (modulo l'URL dans la barre d'adresse). Aucune régression visuelle/comportementale.

### Scénario 16.9-2 — Navigation détail → liens

**Objectif** : vérifier la chaîne nav détail → liens sous la nouvelle arborescence.

1. Depuis le listing (scénario 16.9-1), cliquer sur une GPO (lien du nom OU bouton « Détail »).
2. URL devient `/admin/settings/gpo/{GUID}` (avec ou sans accolades — la regex GUID accepte les 2 formes).
3. Page détail s'affiche : encart Impact (16.5), containers liés, CTAs natifs si match (16.3a).
4. Cliquer le bouton « Gérer les liaisons ».
5. URL devient `/admin/settings/gpo/{GUID}/links`.
6. Page liens s'affiche : liste OUs liées + boutons d'action (ajout, suppression, toggle disabled/enforced, move up/down, toggle inheritance) + modale de confirmation `<x-molecules.modal>`.
7. Cliquer « Retour à la GPO » → revient sur `/admin/settings/gpo/{GUID}`.
8. Cliquer « Liste des GPOs » (badge ghost) → revient sur `/admin/settings/gpo`.

**Attendu** : navigation fluide, breadcrumbs cohérents, aucun lien cassé.

### Scénario 16.9-3 — Sidebar : nouveau bloc « GPO » sous « Réglages »

**Objectif** : vérifier que la sidebar reflète la nouvelle hiérarchie navigationnelle (D4).

1. Se connecter avec un compte admin (`server.admin`).
2. Ouvrir la sidebar (drawer) si elle est repliée.
3. Identifier le lien « Réglages » (qui pointe toujours vers `/admin/settings?tab=quotas-fs` — comportement inchangé, AC3.1).
4. **Juste en dessous de « Réglages »**, voir un nouveau bloc collapsible « GPO » (icône `fa-shield-halved`, indentation `ml-4`).
5. Cliquer pour déplier le bloc.
6. Le bloc contient 3 liens :
   - « Toutes les GPOs » → `route('admin.gpo.index')` (= `/admin/settings/gpo`)
   - « Wine — Apps Linux » → `route('admin.gpo.wine')`
   - « WPKG — Pipeline » → `route('admin.gpo.wpkg-deployment')`
7. Cliquer chaque lien — chacun atterrit sur la page correspondante.
8. La classe active visuelle (`bg-primary/10 text-primary`) s'applique au lien correspondant à l'URL courante.
9. Naviguer vers `/admin/settings/gpo/{GUID}` ou `/admin/settings/gpo/{GUID}/links` : le bloc reste auto-déplié (`@checked(request()->is('admin/settings/gpo*'))`) mais aucun lien direct vers ces sous-pages paramétrées GUID n'est exposé dans la sidebar (iso-Phase 1).

**Attendu** : sidebar lisible, hiérarchie cohérente, pas de duplication avec le bloc legacy « Clients et applications » (qui reste à 4 liens `.php` legacy + le lien GPOs maj vers `admin.gpo.index` pour ce sous-bloc commenté/désactivé).

### Scénario 16.9-4 — Redirection 301 ancien bookmark

**Objectif** : vérifier la backward-compat pour les bookmarks utilisateurs (D3).

Test 1 — `/app/gpo` (statique) :

1. Ouvrir un onglet privé / clear cache navigateur (pour ne pas suivre la redirection cachée).
2. Activer les DevTools Network panel.
3. Taper `https://<VM>/app/gpo` dans la barre d'adresse.
4. Première requête : HTTP **301 Moved Permanently** avec `Location: /admin/settings/gpo`.
5. Le navigateur suit automatiquement → seconde requête HTTP 200 sur `/admin/settings/gpo`.
6. La page index GPO s'affiche normalement.
7. La barre d'adresse affiche la nouvelle URL `/admin/settings/gpo`.

Test 2 — `/app/gpo/wine` :

1. Idem onglet privé.
2. Taper `https://<VM>/app/gpo/wine`.
3. 301 → 200 sur `/admin/settings/gpo/wine`.

Test 3 — `/app/gpo/{GUID}` (route paramétrée) :

1. Taper `https://<VM>/app/gpo/{8625C81D-89B0-4502-9DC5-7BFD7B8C7C42}` (ou un GUID valide local).
2. 301 → 302 (auth si non connecté) ou 200 sur `/admin/settings/gpo/{GUID}` (connecté admin).
3. Le GUID est préservé dans la redirection.

Test 4 — open-redirect bloqué :

1. Taper `https://<VM>/app/gpo/INJECTION`.
2. Réponse HTTP **404 Not Found** (la regex GUID stricte refuse `INJECTION`).
3. AUCUN `Location:` vers `/admin/settings/gpo/INJECTION` n'est généré (anti open-redirect garanti).

**Attendu** : aucun bookmark cassé. Les utilisateurs peuvent transitionner sans intervention.

### Scénario 16.9-5 — Lien d'erreur `WpkgGpoSynchronizer`

**Objectif** : vérifier que le message diagnostic du synchronizer pointe vers la nouvelle URL (D9).

1. Sur la VM, déclencher un état où la GPO `se4_wpkg` existe mais n'a aucune liaison (cf. Story 16.6 — environnement DC dev avec GPO publiée mais sans `setlink`).
2. Soit via l'UI : se rendre sur `/admin/settings/gpo/wpkg-deployment`, déclencher « Re-auditer ».
3. Soit via CLI : `php artisan wpkg:gpo:sync --audit-only --json`.
4. Inspecter les `messages[]` de la sortie ou de la page (section Diagnostics).
5. Le message diagnostic doit contenir : `Allez sur /admin/settings/gpo/<GUID>/links pour la lier.`
6. Aucune occurrence `/app/gpo/` dans les messages.

**Attendu** : message à jour avec la nouvelle URL. L'admin peut cliquer-coller le path directement sans 1 hop redirect.

### Scénario 16.9-6 — Deep link `NativeSectionResolver` pour Wine

**Objectif** : vérifier que le mapping heuristique des sections natives pointe vers la nouvelle URL (D8).

1. Se rendre sur `/admin/settings/gpo` (listing).
2. Localiser une GPO dont le `displayName` contient `wine` (ex. `se4_wine`, `wine_apps_lin`).
3. Dans la colonne « Édition native » (16.3a), un chip vert avec icône `fa-wine-glass` apparaît.
4. Cliquer le chip.
5. Atterrir sur `/admin/settings/gpo/wine?from_gpo=<GUID>` (URL avec query param `from_gpo` pour le breadcrumb).
6. La page Wine s'affiche normalement, le breadcrumb « Retour aux GPOs » (en haut à gauche) pointe vers `route('admin.gpo.index')`.

**Attendu** : navigation entre listing → édition native Wine sans 1 hop redirect, breadcrumb cohérent.

### Checklist rapide Story 16.9

- [ ] **16.9-1** Index GPO accessible sous `/admin/settings/gpo` (HTTP 200, contenu identique à l'ancien `/app/gpo`).
- [ ] **16.9-2** Navigation détail → liens fonctionne sous le nouveau préfixe.
- [ ] **16.9-3** Sidebar : nouveau bloc collapsible « GPO » sous « Réglages », 3 liens fonctionnels, classe active OK.
- [ ] **16.9-4a** `/app/gpo` redirige 301 vers `/admin/settings/gpo` (DevTools Network).
- [ ] **16.9-4b** `/app/gpo/wine` redirige 301 vers `/admin/settings/gpo/wine`.
- [ ] **16.9-4c** `/app/gpo/{GUID}` redirige 301 vers `/admin/settings/gpo/{GUID}` (GUID préservé).
- [ ] **16.9-4d** `/app/gpo/INJECTION` retourne 404 (anti open-redirect).
- [ ] **16.9-5** Message d'erreur `WpkgGpoSynchronizer` contient `/admin/settings/gpo/<GUID>/links`.
- [ ] **16.9-6** Deep link Wine depuis listing : chip → `/admin/settings/gpo/wine?from_gpo=<GUID>` (1 hop, pas 2).
- [ ] **Régression Phase 1** : suite `scripts/run-tests.sh phase1` passe exit 0.

---

## Section 16.14 — Story 16.14 Améliorations UX UI admin GPO

> Append 2026-05-20 — améliorations UX Phase 2 (A card hero, B filtres+exports, C vue inverse, D sections, E jobs).
> Scénarios numérotés stables — NE PAS renuméroter.

### Nouvelles routes livrées

| Route | Composant | Story |
|---|---|---|
| `/admin/settings/gpo` (enrichi) | `pages::admin.settings.gpo.index` | 16.14 A+B |
| `/admin/settings/gpo/by-ou` | `pages::admin.settings.gpo.by-ou.index` | 16.14 C |
| `/admin/settings/gpo/sections` | `pages::admin.settings.gpo.sections.index` | 16.14 D |
| `/admin/settings/system/jobs` | `pages::admin.settings.system.jobs.index` | 16.14 E |

### Pré-requis spécifiques 16.14

- VM accessible avec Samba AD opérationnel.
- Queue driver `database` configuré (sinon dashboard jobs affiche encart D15).
- Au moins 1 GPO dans l'AD (vérifier avec `samba-tool gpo listall`).
- Au moins 1 OU dans l'AD (vérifier avec `samba-tool ou list`).

---

### Scénario 16.14-1 — Card hero onboarding + dismiss

**Pré-requis** : Connecté en `server.admin`. Nouvelle session (cookies vidés).

**Étapes** :
1. Accéder à `/admin/settings/gpo`.
2. Vérifier que la card hero « Gestion des GPO Active Directory » est visible en haut de page.
3. Vérifier les 3 sous-cards : Consulter/Inspecter (lien vers `#listing-gpos`), Lier une GPO à une OU (vers `/by-ou`), Éditer une section native (vers `/sections`).
4. Cliquer sur le bouton croix (masquer) de la card hero.
5. Vérifier que la card disparaît et qu'un bouton « Afficher l'aide » apparaît.
6. Cliquer sur « Afficher l'aide » → la card réapparaît.
7. Fermer et rouvrir la session → la card hero est réaffichée (comportement session).

**Résultat attendu** : Card hero affichée + dismissible + persistance par session uniquement.

---

### Scénario 16.14-2 — Filtres avancés (type + natif)

**Pré-requis** : GPOs variées dans l'AD (dont au moins 1 `se4_machine_*`, 1 `se4_user_*`).

**Étapes** :
1. Accéder à `/admin/settings/gpo`.
2. Cliquer sur « Filtres avancés » pour ouvrir le panneau (fermé par défaut).
3. Sélectionner « Machine » dans le filtre Type.
4. Vérifier que seules les GPOs `se4_machine_*` / `se4_app_*` sont listées.
5. Cocher « Avec sections natives uniquement ».
6. Vérifier que le listing se restreint aux GPOs matchant `NativeSectionResolver`.
7. Cliquer « Réinitialiser » → tous les filtres avancés vidés, listing complet.

**Résultat attendu** : Filtres AND, compteur « N actifs », reset en 1 clic.

---

### Scénario 16.14-3 — Export CSV

**Pré-requis** : Listing GPO chargé (au moins 2 GPOs).

**Étapes** :
1. Accéder à `/admin/settings/gpo`.
2. Optionnellement filtrer par type pour réduire le listing.
3. Cliquer « CSV » (bouton en haut à droite).
4. Vérifier que le fichier `gpo-export-YYYY-MM-DD-HHMMSS.csv` est téléchargé.
5. Ouvrir dans Excel/LibreOffice → vérifier BOM UTF-8 (pas de corruption), colonnes correctes.
6. Vérifier que le nombre de lignes = nombre de GPOs filtrées (pas tout le parc).

**Résultat attendu** : CSV téléchargé, BOM UTF-8, colonnes D3, périmètre filtré.

---

### Scénario 16.14-4 — Export JSON

**Pré-requis** : Listing GPO chargé.

**Étapes** :
1. Accéder à `/admin/settings/gpo`.
2. Cliquer « JSON » (bouton en haut à droite).
3. Vérifier que le fichier `gpo-export-YYYY-MM-DD-HHMMSS.json` est téléchargé.
4. Vérifier que le contenu est pretty-printed, accents non échappés, clés snake_case.

**Résultat attendu** : JSON pretty-print D3, unicodé, périmètre filtré.

---

### Scénario 16.14-5 — Vue inverse OU → GPOs

**Pré-requis** : Au moins 1 OU dans l'AD avec au moins 1 GPO liée.

**Étapes** :
1. Accéder à `/admin/settings/gpo/by-ou`.
2. Taper les premières lettres du DN d'une OU dans le sélecteur.
3. Vérifier que des suggestions apparaissent (auto-complete substring).
4. Sélectionner une OU.
5. Vérifier le tableau des GPOs avec colonnes : Nom, Origine (Directe/Héritée), Enforced, Disabled, Actions.
6. Pour une OU enfant avec OU parent, vérifier que les GPOs parentes sont listées avec mention « Héritée ».
7. Si une OU a le blocage héritage, vérifier le badge « Héritage bloqué ».

**Résultat attendu** : Vue inverse fonctionnelle, héritage correctement affiché.

---

### Scénario 16.14-6 — Catalogue sections natives

**Pré-requis** : Connecté en `server.admin`.

**Étapes** :
1. Accéder à `/admin/settings/gpo/sections`.
2. Vérifier que 5 cards sont affichées : Profils itinérants, Fonds d'écran, Personnalisation apps, Raccourcis, Apps Wine.
3. Vérifier que chaque card a un compteur d'entités (ou `—` si N/A).
4. Cliquer sur « Fonds d'écran » → navigue vers `/app/parc-settings/wallpapers`.
5. Cliquer sur « Apps Wine (Linux) » → navigue vers `/admin/settings/gpo/wine`.
6. Vérifier la note « Source de vérité : NativeSectionResolver::MAPPING ».

**Résultat attendu** : 5 cards cliquables, compteurs, liens vers bonne destination.

---

### Scénario 16.14-7 — Dashboard jobs polling

**Pré-requis** : Driver queue `database` configuré sur la VM.

**Étapes** :
1. Accéder à `/admin/settings/system/jobs`.
2. Vérifier la puce verte clignotante « Rafraîchissement automatique toutes les 5 secondes ».
3. Déclencher un job Wine : `/admin/settings/gpo/wine` → générer une image.
4. Revenir sur le dashboard → le job doit apparaître dans la liste en attente ou en cours.
5. Attendre la complétion → le job disparaît de la liste.

**Résultat attendu** : Liste se rafraîchit automatiquement, job visible.

---

### Scénario 16.14-8 — Retry / Cancel job

**Pré-requis** : Au moins 1 job échoué (`failed_jobs`) ou pending (`jobs`) GPO/WPKG.

**Étapes** :
1. Accéder à `/admin/settings/system/jobs`.
2. Pour un job échoué : cliquer « Retry » → toast « Job remis en queue », job disparaît de la liste failed.
3. Pour un job pending (non réservé) : cliquer « Annuler » → toast « Job annulé », job disparaît.
4. Simuler job en cours de réservation + cliquer « Annuler » → toast warning « Le job était déjà en cours ».

**Résultat attendu** : Retry/Cancel fonctionnels avec toasts appropriés.

---

### Scénario 16.14-9 — Permissions : 403 sur nouvelles routes

**Pré-requis** : Utilisateur sans `server.admin`.

**Étapes** :
1. Se connecter avec un compte non-admin.
2. Tenter d'accéder à `/admin/settings/gpo/by-ou` → HTTP 403.
3. Tenter d'accéder à `/admin/settings/gpo/sections` → HTTP 403.
4. Tenter d'accéder à `/admin/settings/system/jobs` → HTTP 403.

**Résultat attendu** : 403 sur les 3 nouvelles routes pour non-admin.

---

### Scénario 16.14-10 — Non-régression listing 16.2

**Pré-requis** : Connecté en `server.admin`.

**Étapes** :
1. Accéder à `/admin/settings/gpo`.
2. Vérifier que le listing existant (filtres 16.2 : recherche, statut actif/inactif, tri) fonctionne normalement.
3. Vérifier les colonnes : Nom, Version, GUID, Path SYSVOL, Édition native.
4. Vérifier la pagination.
5. Vérifier le lien vers le détail GPO.
6. Vérifier que les chips « Édition native » (16.3a) fonctionnent.

**Résultat attendu** : Aucune régression sur le listing existant.

---

### Checklist rapide Story 16.14

- [ ] **16.14-1** Card hero visible + dismiss + réaffichage aide.
- [ ] **16.14-2** Filtres avancés (type machine/user/logon, natif) + AND logic + reset.
- [ ] **16.14-3** Export CSV téléchargé, BOM UTF-8, colonnes correctes.
- [ ] **16.14-4** Export JSON pretty-print, accents OK.
- [ ] **16.14-5** Vue inverse OU→GPOs : auto-complete, héritage, badge bloqué.
- [ ] **16.14-6** Catalogue sections : 5 cards, compteurs, liens OK.
- [ ] **16.14-7** Dashboard jobs : polling 5s, job visible après dispatch.
- [ ] **16.14-8** Retry job échoué + cancel job pending + warning si running.
- [ ] **16.14-9** 403 sur /by-ou + /sections + /system/jobs pour non-admin.
- [ ] **16.14-10** Non-régression listing 16.2 + chips 16.3a.
- [ ] **Régression 16.9** : sidebar GPO (Toutes, Vue par OU, Sections natives, Wine, WPKG) + bloc Système.

---

## Section 16.14bis — Post-arbitrage Q1-Q5 (cache santé, filtres, pagination, exports)

> Scénarios ajoutés suite aux arbitrages Henri 2026-05-20 sur les corrections post-review Q1-Q5.

**Pré-requis communs** : VM up, `php artisan gpo:warm-cache --force` exécuté au moins une fois, connecté en `server.admin`.

---

### Scénario 16.14-11 — Warm-up cache santé GPO

**Commandes** :
```bash
php artisan gpo:warm-cache --force
php artisan schedule:list | grep gpo:warm-cache
```

**Résultat attendu** :
- La commande affiche : `X GPO(s) warmed in Y ms (0 erreur)`.
- Le log `storage/logs/gpo/gpo-YYYY-MM-DD.log` contient une entrée `gpo.cache.warm` avec `count` et `duration_ms`.
- `schedule:list` affiche la commande avec heure `22:00` et `runInBackground`.

---

### Scénario 16.14-12 — Invalidation cache après modification lien GPO

**Étapes** :
1. `php artisan gpo:warm-cache --force` (cache chaud).
2. Via `/admin/settings/gpo/{guid}/links`, link ou unlink une GPO d'une OU.
3. Consulter `storage/logs/gpo/gpo-YYYY-MM-DD.log`.

**Résultat attendu** :
- Log `gpo.cache.invalidate` présent avec `guid` de la GPO modifiée.
- Au prochain chargement du listing, le statut santé de cette GPO est recalculé (nouveau lookup samba-tool).
- Le bouton "Rafraîchir cache santé" dans le panneau filtres avancés du listing produit un toast info + log `gpo.cache.flush`.

---

### Scénario 16.14-13 — Filtre statut santé multi-valeur (Q1)

**Pré-requis** : Au moins 1 GPO orpheline (pas liée à une OU) + 1 GPO stale (versionNumber=0) + 1 GPO healthy.

**Étapes** :
1. Accéder au listing `/admin/settings/gpo`, ouvrir le panneau filtres avancés.
2. Cocher à la fois `orphaned` et `stale` dans le groupe de checkboxes "Statut santé".
3. Vérifier le résultat.
4. Cocher uniquement `healthy`, vérifier.
5. Cliquer "Tout effacer" (bouton global reset).

**Résultat attendu** :
- Étape 2 : listing affiche les GPOs orphelines ET stale simultanément (union, pas intersection).
- Étape 4 : listing affiche uniquement les GPOs healthy.
- Étape 5 : toutes les checkboxes décochées, listing complet.

---

### Scénario 16.14-14 — Filtre "OU liée" fonctionnel dans le listing principal (Q3)

**Pré-requis** : Cache warm (`gpo:warm-cache --force`). Au moins 2 GPOs liées à des OUs distinctes.

**Étapes** :
1. Accéder au listing `/admin/settings/gpo`, ouvrir le panneau filtres avancés.
2. Saisir le DN d'une OU connue dans le champ "OU liée" (auto-complete).
3. Vérifier que seules les GPOs liées à cette OU (ou ses parents) apparaissent.
4. Saisir une OU à laquelle aucune GPO n'est liée.

**Résultat attendu** :
- Étape 3 : filtrage correct (≠ comportement précédent toujours vide).
- Étape 4 : listing vide + message "Aucune GPO ne correspond aux filtres".
- En cas de valeur invalide (hors whitelist OUs known) : filtre ignoré silencieusement, listing complet.

---

### Scénario 16.14-15 — Pagination dashboard jobs 20/page (Q4) + colonnes version export (Q5)

**Sous-scénario 15a — Pagination jobs** :
```bash
# Seed 25 jobs GPO en base
php artisan tinker --execute='for($i=0;$i<25;$i++) \DB::table("jobs")->insert(["queue"=>"default","payload"=>json_encode(["displayName"=>"App\\\\Gpo\\\\Jobs\\\\GenerateWineImageJob"]),"attempts"=>0,"available_at"=>time(),"created_at"=>time()-$i]);'
```
1. Accéder à `/admin/settings/system/jobs`.
2. Vérifier que la page 1 affiche 20 jobs et que le composant de pagination indique "page 1/2".
3. Naviguer en page 2, vérifier 5 jobs.
4. Supprimer les jobs de test après (`\DB::table('jobs')->delete()`).

**Sous-scénario 15b — Colonne `version_status` dans exports** :
1. Accéder au listing `/admin/settings/gpo`.
2. Déclencher export CSV.
3. Ouvrir le fichier CSV : vérifier la présence de la colonne `version_status` (`known` ou `unknown`).
4. Si cache chaud (`gpo:warm-cache --force` exécuté) : `version_status` = `known`, `version_major`/`version_minor` > 0 pour les GPOs actives.
5. Si cache vide : `version_status` = `unknown`, `version_major` = 0.

---

### Checklist rapide post-arbitrage Q1-Q5

- [ ] **16.14-11** `gpo:warm-cache --force` : affiche count+duration, log `gpo.cache.warm`, schedule 22:00.
- [ ] **16.14-12** Modif lien GPO via UI → log `gpo.cache.invalidate` immédiat + bouton refresh → toast + log `gpo.cache.flush`.
- [ ] **16.14-13** Filtre santé multi-checkbox : `orphaned` + `stale` simultanés → union correcte, `healthy` seul → filtre correct, "Tout effacer" → reset complet.
- [ ] **16.14-14** Filtre OU listing fonctionnel (cache Q2) : DN OU valide → filtre correct, valeur invalide → listing complet silencieux.
- [ ] **16.14-15a** Dashboard jobs 25 seeds → page 1 = 20, page 2 = 5.
- [ ] **16.14-15b** Export CSV : colonne `version_status` présente, `known` si cache chaud.

---

## Story 16.15 — Migration cache Laravel

**Date livraison** : 2026-05-21
**Migrations à appliquer** : aucune (refactor interne — pas de changement de schéma)
**Pré-requis complémentaires** :
- Driver APCu chargé en PHP-FPM (`php -r "echo apcu_enabled();"` → `1`) — requis pour le store `app_context` (interop legacy), PAS pour le store par défaut.
- `CACHE_DRIVER=file` dans `/var/www/sambaedu-reload/.env` : le store PAR DÉFAUT doit supporter `Cache::lock()` (cf. correctif 2026-06-23 — `apc` casse tous les `Cache::lock()`, dont l'install d'apps via `WithoutOverlapping`). L'interop legacy reste portée par le store `app_context` (hardcodé `apc` via `APP_CONTEXT_CACHE_DRIVER`, n'hérite PAS du global).
- `php artisan config:clear && php artisan cache:clear` (après deploy).

### Scénario 16.15-1 — Vérification store `app_context` déclaré et configuré

1. SSH sur la VM : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`.
2. `cd /var/www/sambaedu-reload && php artisan tinker --execute='print_r(config("cache.stores.app_context"));'`
3. Vérifier la sortie :
   - `driver` = `apc` (hardcodé via `APP_CONTEXT_CACHE_DRIVER`, n'hérite PAS de `CACHE_DRIVER` — review #10).
   - `prefix` = `''` (chaîne vide — **critique** pour interop legacy D5).
4. Vérifier que `config('cache.default')` = `file` (store par défaut lock-capable ; `app_context` reste `apc` indépendamment).

**Attendu** : tableau `['driver' => 'apc', 'prefix' => '']`.

---

### Scénario 16.15-2 — Dégradation gracieuse avec `CACHE_DRIVER=array`

1. Éditer `/var/www/sambaedu-reload/.env` : `CACHE_DRIVER=array`.
2. `php artisan config:clear`.
3. Déclencher un hit `/gpo/applications.php?machine=pc01&action=startup&os=windows`.
4. Vérifier que l'endpoint répond 200 (vide ou scripté — pas de 500).
5. Vérifier que le log `storage/logs/gpo.log` ne contient pas d'exception APCu.
6. Remettre `CACHE_DRIVER=apc` + `php artisan config:clear`.

**Attendu** : 200 sans erreur — dégradation gracieuse (store array ne persiste rien, mais ne lève pas d'exception).

---

### Scénario 16.15-3 — Interop legacy : lecture APCu direct après écriture Cache

1. `CACHE_DRIVER=apc` dans `.env` + `php artisan config:clear`.
2. Déclencher un appel POST `/gpo/applications.php` avec une machine et un user AD valides (uuid connu).
3. Récupérer l'`id` (md5) depuis `storage/logs/gpo.log` (champ `id` du log `gpo.applications.context.put`).
4. Vérifier la clé APCu brute :
   ```bash
   php -r 'echo print_r(apcu_fetch("apps.<id>"), true);'
   ```
5. Vérifier que le tableau retourné contient les clés `user`, `machine`, `salle`, `os`.

**Attendu** : `apcu_fetch("apps.<id>")` retourne le payload complet — interop bidirectionnelle confirmée (D3/D4/D5 Story 16.15).

---

### Scénario 16.15-4 — Bench micro-régression latence (AC9.1.4)

1. `CACHE_DRIVER=apc` dans `.env` + `php artisan config:clear`.
2. Mesurer 100 hits `/gpo/applications.php` post-16.15 (avec un uuid valide déjà en cache) :
   ```bash
   ab -n 100 -c 1 'http://localhost/gpo/applications.php?machine=pc01&action=startup&os=windows&uuid=<id>'
   ```
3. Comparer avec la baseline post-16.7 (si disponible) ou noter le `Time per request` moyen.
4. Vérifier que la latence moyenne < 110 % de la baseline (écart < 5 % accepté — overhead Cache facade ~1-5 µs/appel).

**Attendu** : pas de régression observable > 5 %. Driver APCu reste physiquement identique — l'overhead est uniquement le routing facade Laravel.

---

### Scénario 16.15-5 — Interop bi-directionnelle tinker + APCu CLI

1. Sur la VM :
   ```bash
   cd /var/www/sambaedu-reload
   php artisan tinker
   ```
2. Dans tinker :
   ```php
   Cache::store('app_context')->put('apps.12a734d9823e7d7ee4fc700dd595f391', ['x' => 1], 60);
   apcu_fetch('apps.12a734d9823e7d7ee4fc700dd595f391');
   ```
3. Vérifier que `apcu_fetch` retourne `['x' => 1]`.
4. Inverse :
   ```php
   apcu_store('apps.7a6253c7145453d2d17e918796ae9994', ['y' => 2], 60);
   Cache::store('app_context')->get('apps.7a6253c7145453d2d17e918796ae9994');
   ```
5. Vérifier que `Cache::store('app_context')->get(...)` retourne `['y' => 2]`.

**Attendu** : lecture/écriture croisée APCu direct ↔ Cache::store('app_context') transparente (preuve que `prefix => ''` est effectif).

---

### Checklist rapide 16.15 — post-deploy

- [ ] **16.15-1** Store `app_context` déclaré : driver = `apc`, prefix = `''`.
- [ ] **16.15-2** Dégradation gracieuse `CACHE_DRIVER=array` → 200 sans exception.
- [ ] **16.15-3** Interop legacy : `apcu_fetch("apps.<id>")` retourne le payload écrit par l'endpoint natif.
- [ ] **16.15-4** Bench 100 hits : latence < 110 % baseline (overhead Cache facade < 5 %).
- [ ] **16.15-5** Tinker bi-directionnel : `Cache::store('app_context')` ↔ `apcu_fetch` transparent.

---

## Story 17.3 — Compat GPO orchestratrice `se4_applications`

**Date livraison** : 2026-05-22
**Migrations à appliquer** : aucune (audit + extension whitelist + doc — pas de changement de schéma)
**Pré-requis complémentaires** :
- Paquet Debian `sambaedu-gpo` installé (template `/usr/share/sambaedu/gpo/se4_applications.zip` ou répertoire `/usr/share/sambaedu/gpo/sambaedu-gpo/se4_applications/`).
- Stories 16.10 + 16.11 done (JWT généralisé côté postes — pré-requis Q-1 résolution).
- Story 16.13 done (endpoint natif `/api/v1/workstation-config/applications-scripts` exposé).

### Objectif

Cette story garantit que les `.cmd` orchestrateurs embarqués dans le template GPO
`se4_applications` (Machine/Scripts/{Startup,Shutdown} + User/Scripts/{Logon,Logoff})
appellent l'**endpoint natif** Story 16.13 (`/api/v1/workstation-config/applications-scripts`)
et **non plus** l'endpoint legacy `gpo/applications.php` (shim PHP-FPM 1bis-11
destiné à disparaître). Deux stratégies combinées (Q-3 résolue Henri 2026-05-22) :

**Stratégie A.1 — Patch upstream Debian** (filière long terme) :
Le patch git est livré dans `_bmad-output/implementation-artifacts/17-3-upstream-se4_applications.diff`
et doit être appliqué côté repo `sambaedu-gpo` (GitLab interne) puis release Debian
propagée. Effet : remplace l'URL hardcodée `http://%SE4FS%.###_DOMAIN_###/gpo/applications.php`
par le placeholder substituable `###_APPLICATIONS_SCRIPTS_URL_###` dans les 4
`.cmd` orchestrateurs.

**Stratégie A.2 — Substitution post-extraction whitelist** (filière immédiate) :
La clé `APPLICATIONS_SCRIPTS_URL` est ajoutée à la whitelist
`config('sambaedu.gpo.applications.substitutions.whitelist')` avec une résolution
dynamique via `URL::route('agent.v1.config.applications-scripts', [], absolute: true)`.
Le shim legacy `specialise_gpo` substitue automatiquement le placeholder au moment
d'`import_gpo` dans SYSVOL. Cette filière est active **dès le déploiement 17.3**,
mais reste **sans effet tant que les `.cmd` upstream contiennent encore l'URL
hardcodée** — c'est précisément pour cela que la combinaison A.1+A.2 est nécessaire.

### Procédure opérateur

**1. Audit du template installé sur le serveur** (lecture pure, idempotente) :

```bash
# Mode humain (table ASCII) :
sudo -u www-admin php artisan gpo:applications:audit

# Mode JSON (CI / pipe machine-readable) :
sudo -u www-admin php artisan gpo:applications:audit --json | jq .

# Path override (testing) :
sudo -u www-admin php artisan gpo:applications:audit --path=/tmp/custom_template/
```

**Exit codes** :
- `0` : OK — aucune URL legacy détectée, aucun placeholder hors whitelist.
- `2` : WARNING — au moins une URL `gpo/applications.php` détectée OU au moins un
  placeholder `###_KEY_###` hors whitelist (cas où le template upstream introduit
  une nouvelle clé non documentée — bloquant parc-wide).
- `1` : ERROR — template absent / ZIP corrompu / `ZipArchive::open` échec.

**Output JSON attendu** (structure stable pour CI) :
```json
{
  "template_path": "/usr/share/sambaedu/gpo/se4_applications.zip",
  "files": [
    {
      "path": "Machine/Scripts/Startup/startup.cmd",
      "urls": ["http://%SE4FS%.###_DOMAIN_###/gpo/applications.php"],
      "placeholders": ["DOMAIN", "SE4FS_NAME"],
      "legacy_match": true,
      "recommendation": "substitute_post_extraction"
    }
  ],
  "summary": {
    "total_files": 4,
    "legacy_count": 4,
    "ok_count": 0,
    "unknown_placeholders_count": 0
  },
  "unknown_placeholders": []
}
```

**2. Application Stratégie A.1 (patch upstream)** — pré-requis : accès repo
`sambaedu-gpo` :

```bash
cd /usr/share/sambaedu/gpo/sambaedu-gpo/   # ou clone du repo GitLab
git apply 17-3-upstream-se4_applications.diff
git commit -m "fix(se4_applications): URL legacy → placeholder ###_APPLICATIONS_SCRIPTS_URL_### (Story 17.3)"
# → push + release Debian + apt-get install --reinstall sambaedu-gpo en prod
```

**3. Re-import du template GPO dans SYSVOL** (manuel, out-of-scope automatisation 17.3 — cf. D7) :
```
# Via UI legacy `gpo/gpo-maj.php` → bouton "Re-importer la GPO se4_applications"
# OU manuellement via PHP CLI :
sudo -u www-admin php -r "require '/var/www/sambaedu/includes/gpo.inc.php'; import_gpo('se4_applications');"
```

Le shim `import_gpo` enchaîne `unzip_gpo → specialise_gpo → sysvol_put`. La
`specialise_gpo` lit la whitelist `config('sambaedu.gpo.applications.substitutions.whitelist')`
et substitue `###_APPLICATIONS_SCRIPTS_URL_###` par l'URL native résolue
(`https://<se4fs>.<domain>/api/v1/workstation-config/applications-scripts`).

**4. Vérification post-déploiement** :
```bash
# Re-audit du template — doit retourner exit 0 et `legacy_count: 0` :
sudo -u www-admin php artisan gpo:applications:audit
# Inspect le contenu SYSVOL substitué :
sudo cat "/var/lib/samba/sysvol/<domain>/Policies/{<GUID>}/Machine/Scripts/Startup/startup.cmd"
# → Doit contenir l'URL native, pas /gpo/applications.php
```

### Override testing/CI

Variables d'environnement (cf. `.env.example`) :

```env
# Path template (par défaut /usr/share/sambaedu/gpo/se4_applications.zip)
GPO_APPLICATIONS_TEMPLATE_PATH=/tmp/custom_template/

# Override URL endpoint natif (par défaut résolu via URL::route)
SAMBAEDU_APPLICATIONS_SCRIPTS_URL=https://proxy.example.test/v1/apps
```

### Référence Q-1 (URL directe vs migration)

**Décision Henri 2026-05-22** : URL **directe API v1**. JWT généralisé
(16.10 + 16.11 done). Les `.cmd` orchestrateurs pointent directement sur
`/api/v1/workstation-config/applications-scripts`, **pas** sur l'URL legacy
`gpo/applications.php` ré-routée par `MigrationController::serveFragment(applications)`
(route web.php:618 option β 16.13bis). La route Migration **reste active**
comme filet de sécurité pour les postes pas encore JWT-migrés au moment où
le `.cmd` initial est encore distribué (Phase de transition).

### Transition JWT — fallback legacy (post-review 17.3 Q3)

**Décision Henri 2026-05-22 (résolution Q3 review)** : « done côté code » ne
signifie pas « 100% du parc JWT-migré au runtime ». Tant que tous les postes
Windows ne sont pas effectivement basculés JWT, conserver le double pattern
sans casser les postes non-migrés.

**Pattern par défaut** (recommandé quand le parc est entièrement JWT-migré) :
- Aucune env var posée → `resolveSubstitutionValue` appelle la closure
  `URL::route('agent.v1.config.applications-scripts', [], absolute: true)`
  qui résout vers l'URL native API v1 (`https://<se4fs>.<domain>/api/v1/workstation-config/applications-scripts`).
- Le `.cmd` orchestrateur fait `curl.exe -o ... "<URL native>?os=...&action=..."` (GET).
- Auth : middleware `auth.v1.workstation` (JWT machine).

**Pattern transition JWT** (recommandé tant que `feedback_auth_iso_legacy`
s'applique — postes Windows pas tous migrés) :
- Override env :
  ```env
  SAMBAEDU_APPLICATIONS_SCRIPTS_URL=http://se4fs.<domain>/gpo/applications.php
  ```
- Le `.cmd` orchestrateur fait `curl.exe -o ... "http://se4fs.<domain>/gpo/applications.php?os=...&action=..."`.
- Cette URL est captée par `Route::match(['GET','POST'], 'gpo/applications.php', ...)`
  → `MigrationController::serveFragment(applications)` (option β 16.13bis,
  `routes/web.php:618`) qui sert le bon fragment selon l'état JWT du poste
  (migration auto si poste non-bootstrappé).
- Le `.diff` upstream 17.3 (qui patche les `.cmd` template) est compatible
  avec les deux URLs : la substitution `###_APPLICATIONS_SCRIPTS_URL_###`
  peut résoudre vers l'une ou l'autre sans toucher au `.cmd`.

**Bascule définitive** :
1. Quand 100% du parc est JWT-migré (cf. tableau de bord 16.11 / monitoring
   migrations bootstrap), retirer `SAMBAEDU_APPLICATIONS_SCRIPTS_URL` du
   `.env` de production.
2. Re-`import_gpo` du template → la substitution applique automatiquement
   l'URL native API v1.
3. `php artisan gpo:applications:audit` → vérifier exit code 0 + détection
   du placeholder `APPLICATIONS_SCRIPTS_URL` substitué.
4. À terme (TD-17.3) : retirer la route Migration legacy
   `gpo/applications.php` (out-of-scope 17.3 — déféré).

### Scénarios QA manuels

#### Scénario 17.3-1 — Audit template legacy détecte les `.cmd` orchestrateurs

**Pré-condition** : Template Debian `sambaedu-gpo` installé non patché côté
upstream (URL hardcodée présente).

**Étapes** :
1. SSH sur la VM serveur, user `www-admin`.
2. `php artisan gpo:applications:audit`

**Attendu** :
- Exit code 2 (warning).
- Tableau ASCII liste 4 fichiers : `Machine/Scripts/Startup/startup.cmd`,
  `Machine/Scripts/Shutdown/shutdown.cmd`, `User/Scripts/Logon/logon.cmd`,
  `User/Scripts/Logoff/logoff.cmd`.
- Pour chaque : `legacy_match=true`, `recommendation=substitute_post_extraction`.
- Placeholders détectés : `SE4FS_NAME`, `DOMAIN` (les 2 sont dans la whitelist legacy).
- Aucun placeholder hors whitelist.

#### Scénario 17.3-2 — Mode JSON consommable par CI

**Étapes** :
1. `php artisan gpo:applications:audit --json | jq '.summary'`

**Attendu** :
```json
{
  "total_files": 4,
  "legacy_count": 4,
  "ok_count": 0,
  "unknown_placeholders_count": 0
}
```

#### Scénario 17.3-3 — Whitelist `APPLICATIONS_SCRIPTS_URL` résolue dynamiquement

**Pré-condition** : Template patché upstream A.1 (placeholder
`###_APPLICATIONS_SCRIPTS_URL_###` présent dans les `.cmd`).

**Étapes** :
1. `php artisan tinker` puis :
   ```php
   app(\App\Gpo\Services\ApplicationScriptsAssembler::class)
       ->applySubstitutions('url=###_APPLICATIONS_SCRIPTS_URL_###');
   ```

**Attendu** : `'url=https://<se4fs>.<domain>/api/v1/workstation-config/applications-scripts'`
(URL résolue dynamiquement par `URL::route()`).

#### Scénario 17.3-4 — Override env `SAMBAEDU_APPLICATIONS_SCRIPTS_URL`

**Étapes** :
1. Définir `SAMBAEDU_APPLICATIONS_SCRIPTS_URL=https://proxy.test/v1` dans `.env`.
2. `php artisan config:clear && php artisan config:cache`.
3. `php artisan gpo:applications:audit --json` (doit fonctionner — la closure
   est sérialisable via `[Classe::class, 'method']`).

**Attendu** : `config:cache` réussit (compatible production), et la substitution
retourne la valeur env override.

#### Scénario 17.3-5 — Re-audit post-patch upstream

**Pré-condition** : Patch A.1 appliqué côté upstream + `apt-get install
--reinstall sambaedu-gpo` exécuté + `import_gpo` réinvoqué.

**Étapes** :
1. `php artisan gpo:applications:audit`

**Attendu** :
- Exit code 0 (OK).
- 4 fichiers détectés, `legacy_match=false`, `recommendation=ok` pour tous.
- Placeholder `APPLICATIONS_SCRIPTS_URL` détecté (dans whitelist, pas inconnu).

### Checklist rapide 17.3 — post-deploy

- [ ] **17.3-1** Commande `php artisan gpo:applications:audit` retourne exit 2 sur template non patché upstream (URL legacy détectée).
- [ ] **17.3-2** Mode `--json` produit la structure documentée (`template_path`, `files`, `summary`, `unknown_placeholders`).
- [ ] **17.3-3** Whitelist `APPLICATIONS_SCRIPTS_URL` résolue dynamiquement via `URL::route()` quand config + env vides.
- [ ] **17.3-4** `php artisan config:cache` réussit (callable sérialisable `[Classe::class, 'method']`).
- [ ] **17.3-5** Patch upstream `17-3-upstream-se4_applications.diff` transmis à Henri pour push côté repo Debian.
- [ ] **17.3-6** Post-patch + re-`import_gpo` : `php artisan gpo:applications:audit` retourne exit 0 et `legacy_count=0`.

---

## Story 17.4 — Tests d'intégration runtime VM (5 scripts critiques + endpoint natif)

**Date livraison** : 2026-05-25
**Stories dépendantes** : 17.2 (done), 17.3 (review)
**Suite de tests** : `./vendor/bin/phpunit --testsuite Feature --filter 'ApplicationsScripts'`

> Cette section documente les scénarios de vérification **runtime sur VM** pour les
> 5 scripts critiques identifiés par l'audit 17.1 Section A, ainsi que les mécanismes
> transverses (surcharges `/etc/`, placeholders, endpoint natif GET).

### Pré-requis spécifiques 17.4 (post-review)

- **Snapshot portable P3** : `tests/Fixtures/Gpo/applications/_package_snapshot/` (byte-identique
  au paquet `sambaedu 4.17.285`, SHA256 `8e0b5be2…`) — committé, **les tests de parité 17.4
  n'exigent PLUS `/usr/share/sambaedu/applications/`** (portables CI / host / VM).
- Fixtures conservées : `tests/Fixtures/Gpo/applications/windows_logon_wallpaper/` (blob logon/windows),
  `linux_logon_firefox/` (logon/linux). *(Fixtures `windows_logon_shortcuts`, `windows_logon_firefox`,
  `windows_startup_wpkg` supprimées — byte-identiques redondantes, cf. README P1/P2.)*
- Clés auth-v1 présentes : `tests/fixtures/auth-v1/private.pem` + `public.pem`

### Scénario 17.4-1 — Suite de tests parité bytes (PORTABLE CI via snapshot P3)

**Objectif** : Vérifier que les contextes critiques sont assemblés iso-legacy byte-par-byte
+ que chaque fragment critique est présent/substitué (assertions ciblées).

**Exécution** (host CI **ou** VM — identique, le snapshot rend le test portable) :
```bash
./vendor/bin/phpunit --filter 'ApplicationsScriptsCriticalParityTest'
# ou sur VM :
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 \
  "cd /var/www/sambaedu-reload && ./vendor/bin/phpunit --filter 'ApplicationsScriptsCriticalParityTest'"
```

**Attendu** (9 tests, 0 skip — y compris hors VM grâce au snapshot) :
- `it_matches_legacy_bytes_for_windows_logon_context` → PASS (parité byte blob logon/windows)
- `it_matches_legacy_bytes_for_linux_logon_firefox` → PASS (parité byte, UTF-8, pas de CRLF)
- `it_includes_robocopy_deploy_fragment_for_wpkg_startup` → PASS (ligne ROBOCOPY complète : `netinst` + `%ProgramFiles%\SambaEdu`)
- `it_substitutes_se4install_name_in_wallpaper_logon` → PASS (ligne `taskkill … "USERNAME ne se4install"` complète)
- `it_includes_firefox_profiles_ini_fragment_in_windows_logon` → PASS (heredoc profiles.ini)
- `it_includes_shortcuts_out_fragment_in_windows_logon` → PASS (curl `shortcuts_out.php`, SE4FS_NAME substitué)
- `it_has_no_residual_placeholder_in_critical_context` (3 contextes) → PASS

**Si un test échoue** :
- Parité bytes non-évidente → **ne pas patcher le code** → signaler Henri (D1 story 17.4)
- Placeholder résiduel → trou whitelist 17.2 → escalade Henri
- Échec post-bump paquet → régénérer le snapshot ET les fixtures (cf. README).

### Scénario 17.4-2 — Endpoint natif GET /api/v1/workstation-config/applications-scripts

**Objectif** : Vérifier que l'endpoint natif répond correctement (auth JWT, charset, **body non vide**, 401/404).

**Exécution** (portable CI — pas de VM requise) :
```bash
./vendor/bin/phpunit --filter 'Tests\\Feature\\Gpo\\ApplicationsScriptsApiV1'
```

**Attendu** (post-review P5 — body NON vide prouvé via seeding cache `apps.<id>`) :
- `it_returns_200_non_empty_body_for_authenticated_windows_logon` → PASS (200 + cp1252 + body non vide, marqueur `REM`)
- `it_returns_200_non_empty_body_for_authenticated_windows_startup` → PASS (200 + cp1252 + body non vide)
- `it_returns_200_non_empty_body_for_authenticated_linux_logon` → PASS (200 + utf-8 + body non vide, `#!/bin/bash`)
- `it_returns_401_without_jwt` → PASS (401 sans Bearer)
- `it_returns_404_for_unknown_workstation_uuid` → PASS (404 + `{"error":"workstation_not_found"}`)
- `it_documents_template_http_method_or_skips` → SKIP si template `se4_applications/` absent (sous-cas VM-dépendant)

**Note méthode HTTP D5** : l'endpoint natif est `Route::get(...)` uniquement. Les `.cmd` orchestrateurs
legacy utilisent POST multipart (`-F "action=..."`). Pendant la transition, `gpo/applications.php`
accepte GET + POST (`Route::match`). Le patch POST→GET est dans `17-3-upstream-se4_applications.diff`.

**Note runbook endpoints aval (Q-2 story 17.4)** : `wallpaper/logon.windows` et `shortcuts/logon.windows`
appellent au runtime des endpoints aval (`wallpaper_out.php` → `/api/v1/workstation-config/wallpaper`,
`shortcuts_out.php` → `/api/v1/workstation-config/shortcuts`). La joignabilité de ces endpoints aval
(image `wallpaper.jpg` téléchargée, `.lnk` générés) est du ressort de leurs propres stories (16.13/17.6).
Pour validation terrain E2E complète, tester manuellement depuis un poste joint au domaine.

### Scénario 17.4-3 — Surcharges admin /etc/sambaedu/applications/

**Objectif** : Vérifier que les surcharges admin `/etc/` prennent priorité sur `/usr/share/`.

**Test portable CI** (sans VM) :
```bash
./vendor/bin/phpunit --testsuite Feature --filter 'ApplicationsScriptsLocalOverrideTest::it_local_override_wins_over_package'
./vendor/bin/phpunit --testsuite Feature --filter 'ApplicationsScriptsLocalOverrideTest::it_unoverridden_package_scripts_unchanged'
```

**Attendu** :
- `it_local_override_wins_over_package` → PASS : contenu local (`REM OVERRIDE_LOCAL_17_4`) présent, contenu package absent
- `it_unoverridden_package_scripts_unchanged` → PASS : scripts non surchargés restent depuis le package (merge incrémental)
- `it_real_vm_local_overrides_present_or_skipped` → SKIP si `/etc/sambaedu/applications/` vide ou uniquement ressources

**État observé VM 2026-05-25** : `/etc/sambaedu/applications/` contient 6 sous-dossiers (firefox, once,
shortcuts, thunderbird, veyon, wallpaper) avec uniquement des ressources (images .jpg, default.json).
Aucune surcharge script active. Cas nominal (H.2) : surcharges peu utilisées en parc standard.

**Note F10 (risque upgrade)** : lors d'un upgrade du paquet `sambaedu-reload`, préserver
`/etc/sambaedu/applications/` (ne pas écraser lors du `apt upgrade`). C'est une responsabilité
du packaging Debian `sambaedu-reload`, hors scope tests 17.4.

### Scénario 17.4-4 — Placeholder SE4INSTALL_NAME (risque bloquant)

**Objectif** : Garantir que `SE4INSTALL_NAME` est substitué dans `wallpaper/logon.windows`.

**Risque** : si `###_SE4INSTALL_NAME_###` subsiste, `taskkill /FI "USERNAME ne ###_SE4INSTALL_NAME_###"`
tuerait explorer.exe pour TOUS les utilisateurs (audit Section A ligne 645).

**Vérification manuelle** :
```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 \
  "cat /usr/share/sambaedu/applications/wallpaper/logon.windows | grep -i 'se4install\|taskkill'"
```

**Attendu** : présence de `se4install` (valeur de `sambaedu.se4install_name` config), absence de `###_SE4INSTALL_NAME_###`.

### Checklist rapide 17.4 — post-deploy (post-review)

- [ ] **17.4-1** Suite `./vendor/bin/phpunit --filter 'ApplicationsScriptsCriticalParity'` : 9 PASS, 0 skip **même hors VM** (snapshot P3)
- [ ] **17.4-2** Suite `./vendor/bin/phpunit --filter 'Tests\Feature\Gpo\ApplicationsScriptsApiV1'` : 200+cp1252, 200+utf8, **body non vide** (P5), 401, 404 tous PASS
- [ ] **17.4-3** Suite `./vendor/bin/phpunit --filter 'ApplicationsScriptsLocalOverride'` : surcharge locale gagne (portable CI)
- [ ] **17.4-4** Aucun placeholder `###_…_###` résiduel dans les 3 contextes critiques (DataProvider)
- [ ] **17.4-5** `SE4INSTALL_NAME` substitué dans wallpaper — ligne `taskkill … "USERNAME ne se4install"` complète (P8)
- [ ] **17.4-6** Script Linux `firefox/logon.linux` : UTF-8, pas de CRLF, parité bytes OK
- [ ] **17.4-7** Tests 17.2 non régressés (`ApplicationsScriptsByteParityTest` : 3/3 PASS, trait factorisé P6)
- [ ] **17.4-8** ROBOCOPY : ligne complète `"%WinDir%\install\os\netinst" "%ProgramFiles%\SambaEdu"` (P4 — `netinst` = réf VM validée Henri)
- [ ] **17.4-9** (VM, optionnel) `gio trash`/cleanup manuel des fixtures fantômes sur VM (`windows_logon_shortcuts/`, `windows_logon_firefox/`, `windows_startup_wpkg/`) — inotify ne propage pas les deletes

## Story 17.5 — Bascule opérateur du logging centralisé des scripts (`winscript-logs:*`)

> Append-only. Story de complétion : le pipeline wrapper (`ApplicationScriptsAssembler::wrapInterpreters()`)
> et le flag config `sambaedu.scripts.logging.enabled` ont été livrés par 17.2 ; l'infra logs
> (table `script_execution_logs`, endpoint `POST /api/v1/script-execution-logs`, service
> `WrapperScriptRenderer`) par 16.12. 17.5 ajoute UNIQUEMENT les 3 commandes artisan d'activation
> opérateur (`winscript-logs:enable` / `:disable` / `:status`) + la persistance non destructive du
> flag dans le `.env`.

**Pré-requis spécifiques** :
- Accès VM : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`, projet `/var/www/sambaedu-reload`.
- Le `.env` du projet contient (ou non) `SAMBAEDU_SCRIPTS_LOGGING_ENABLED` (défaut : `false` / absent).
- **⚠ Ces commandes mutent le `.env` réel sur la VM** — exécuter sur un environnement de test/préprod,
  ou prévoir un retour `winscript-logs:disable` après la vérification.

### Scénario 17.5-1 — Activation via `winscript-logs:enable`

1. État initial : `php artisan winscript-logs:status` → affiche « Logging des scripts d'applications : DÉSACTIVÉ »
   + l'URL d'ingestion résolue (`https://<host>/api/v1/script-execution-logs`).
2. Exécuter `php artisan winscript-logs:enable`.
3. **Attendu** :
   - Message « Logging des scripts d'applications ACTIVÉ. » + rappel POST vers `/api/v1/script-execution-logs`.
   - Si un cache config était présent (`bootstrap/cache/config.php`), warning « cache vidé (config:clear) »
     + invitation à relancer `php artisan config:cache`.
   - Dans le `.env` : la ligne `SAMBAEDU_SCRIPTS_LOGGING_ENABLED=true` est présente une seule fois
     (`grep -c '^SAMBAEDU_SCRIPTS_LOGGING_ENABLED=' .env` → `1`).
   - Toutes les autres variables du `.env` (APP_KEY, DB_*, …) sont intactes (diff `.env` avant/après =
     uniquement la ligne du flag).
   - Log `winscript-logs.enabled` dans `storage/logs/scriptsos/scriptsos.log`.

### Scénario 17.5-2 — Un script assemblé est effectivement wrappé (flag ON)

1. Avec le flag activé (scénario 17.5-1) et un cache config relancé si besoin
   (`php artisan config:cache`), provoquer l'assemblage d'un script d'applications
   (endpoint runtime `gpo/applications` ou tinker `app(ApplicationScriptsAssembler::class)->assemble($info, [])`).
2. **Attendu** : la sortie `cmd` contient le préfixe de setup + le suffixe d'appel
   `Invoke-RestMethod` vers l'endpoint d'ingestion ; la sortie `bash` contient `curl -fsS -X POST`.
   (Comportement livré 17.2 — vérifié ici de bout en bout, post-bascule CLI.)
3. Vérifier qu'une exécution réelle du script sur un poste produit une ligne dans
   `script_execution_logs` (UI `/admin/settings/scripts-logs`, livrée 16.12).

### Scénario 17.5-3 — Désactivation via `winscript-logs:disable` (retour iso-legacy)

1. Exécuter `php artisan winscript-logs:disable`.
2. **Attendu** :
   - Message « Logging des scripts d'applications DÉSACTIVÉ. » + « Retour au comportement iso-legacy
     (parité bytes). »
   - `.env` : `SAMBAEDU_SCRIPTS_LOGGING_ENABLED=false` (une seule ligne).
   - Après `config:clear` (+ `config:cache` si prod), un nouvel assemblage produit une sortie strictement
     iso-legacy (non wrappée — parité bytes, cf. suite `ApplicationsScriptsByteParityTest`).

### Scénario 17.5-4 — Idempotence et non destruction du `.env`

1. Exécuter `php artisan winscript-logs:enable` deux fois de suite.
2. **Attendu** : la 2ᵉ exécution affiche « était déjà activé — flag réécrit (idempotent) » ;
   `grep -c '^SAMBAEDU_SCRIPTS_LOGGING_ENABLED=' .env` → toujours `1` (aucune duplication).
3. Symétrique pour `winscript-logs:disable` ré-exécuté quand le flag est déjà `false`.
4. **Attendu** : aucune ligne orpheline, aucun commentaire/variable tiers altéré.

### Checklist rapide 17.5

- [ ] **17.5-1** `winscript-logs:enable` → `.env` `=true` (1 ligne), autres variables intactes, cache config vidé si présent
- [ ] **17.5-2** `winscript-logs:status` reflète l'état effectif (config) SANS écrire le `.env`
- [ ] **17.5-3** Flag ON → scripts `cmd`/`bash` wrappés (POST `/api/v1/script-execution-logs`)
- [ ] **17.5-4** `winscript-logs:disable` → `.env` `=false`, sortie iso-legacy (parité bytes) restaurée
- [ ] **17.5-5** Idempotence enable/disable : aucune duplication de la variable, `.env` non destructif
- [ ] **17.5-6** Tests automatisés verts : `php artisan test --filter WinscriptLogsCommands` (9 PASS, fixture `.env` isolée)

## Story 27.14 — Extinction du canal de configuration legacy

> **Pré-requis** : le parc tourne déjà sur le canal AGENT desired-state (bootstrap GPO `se4_agent_bootstrap` 25.4 déployé, postes enrôlés, agent ≥ version de parité). Cette story SUPPRIME physiquement le transport de config historique des postes ; elle n'ajoute aucune ressource au canal agent. **Gate de sortie** : la checklist de parité (ci-dessous, Scénario 27.14-0) doit être documentée avant la mise en production.

> **Frontière critique à NE PAS confondre** : `/api/v1/workstation-config/*` (legacy, SUPPRIMÉ) ≠ `/api/v1/agent/*` (canal agent, INTACT). Le bootstrap `se4_agent_bootstrap` (25.4) SURVIT, figé.

### Scénario 27.14-0 — Gate de parité de compétences (validation de sortie, palier 3)

1. Dérouler la table « Checklist de parité » de la story 27-14 : pour chaque capacité legacy (wallpapers+overlay, raccourcis/nature de poste, lecteurs réseau, imprimantes, associations de fichiers, registre/capacités, config d'app FF/TB, applications/WPKG, ciblage par critères, réveil au logon), démontrer l'équivalent sur le canal agent sur **environnement réaliste** : postes migrés ET neufs, plusieurs salles.
2. **Attendu** : chaque capacité dont la story SE5 est `done` est cochée `À PARITÉ` avec preuve de démo ; les capacités en `review` sont notées (le code legacy correspondant part quand même — extinction TOTALE réversible décidée par Henri 2026-06-19, le gate est une validation de sortie documentée, pas un blocage dur).
3. **Réversibilité** : avant toute purge sèche serveur, la neutralisation est réversible (move/rename `*.disabled-27.14` côté VM ; git côté repo).

### Scénario 27.14-1 — Plus aucune route legacy de config ne répond

1. `cd /var/www/sambaedu-reload && php artisan route:list`
2. **Attendu** : AUCUNE des routes suivantes n'apparaît :
   - `gpo/shortcuts_out.php`, `gpo/wallpaper_out.php`, `gpo/firefox_out.php`, `gpo/thunderbird_out.php`, `gpo/network_out.php`, `gpo/veyon_out.php`, `gpo/associations_out.php`, `gpo/applications.php` (ex-`migration.legacy.*`) ;
   - `api/v1/workstation-config/*` (ex-`agent.v1.config.*`, 9 routes) ;
   - `api/v1/shortcuts/export/{script,file,icon}` (ex-`shortcuts.export.*`) ;
   - `api/policies/{kind}/{id}` (ex-`app-policy.canonical`).
3. **Attendu (survivants)** : `wpkg/linux_out.php`, `wpkg/winget_out.php`, `api/v1/agent/{ca,stable,stable/download,state,report,...}`, les redirections 301 `app/gpo/*`, les miniatures `app/wallpapers/{wallpaper}/thumbnail` + `app/wallpaper-assets/{asset}/thumbnail`, et les routes ControlHub `api/v1/shortcuts/{sync,delete}` répondent toujours.
4. Côté poste migré : un appel HTTP à `http://<SE4FS>/gpo/wallpaper_out.php` retourne désormais le **catch-all legacy** (port 8082) ou 404 — il n'enrôle plus ni ne sert de fragment. Le poste reçoit sa configuration **exclusivement par l'agent**.

### Scénario 27.14-2 — Bootstrap (25.4) intact, dernier artefact AD

1. `git status resources/gpo/se4_agent_bootstrap/` → **diff vide** (GPT.INI + `Machine/Scripts/Startup/startup.cmd` + `scripts.ini` intouchés, octet pour octet).
2. `php artisan test tests/Unit/Gpo/Se4AgentBootstrapTemplateTest.php` → 4 PASS (publiable, CSE, dispatcher générique, CRLF).
3. **Attendu** : `GpoTemplateRegistry::isPublishable('se4_agent_bootstrap')` renvoie `true`. Le startup.cmd ne dépend que de `/api/v1/agent/ca` + `/api/v1/agent/stable/download` (canal agent, intacts).

### Scénario 27.14-3 — Kill-switch + canal Linux/install Windows préservés (hors scope)

1. **Attendu** : le middleware `legacy.config.channel` (`EnsureLegacyConfigChannelEnabled`), le flag `LEGACY_CONFIG_CHANNEL_ENABLED` (`config/sambaedu.php` défaut `true`) et la ligne `.env.example` sont CONSERVÉS — ils gatent encore `linux_out`/`winget_out`.
2. `LEGACY_CONFIG_CHANNEL_ENABLED=true` (défaut) → `GET /wpkg/linux_out.php` (depuis une IP LAN allowlistée) répond 200 (liste APT). `LEGACY_CONFIG_CHANNEL_ENABLED=false` → 410. La page `/admin/settings/gpo/wpkg-deployment` conserve sa carte « Réglages de déploiement » (toggle winget + allowlist IP) ; l'AUDIT GPO `se4_wpkg` et le bouton « Re-publier » ont disparu.

### Scénario 27.14-4 — KPI « 0 GPO créée/modifiée hors bootstrap » (audit SYSVOL opérateur)

> Non automatisable depuis le repo — **vérification opérateur en lab** (brief §153).

1. Sur le DC, lister les GPO : `samba-tool gpo listall` (ou via la page `/admin/settings/gpo`).
2. Inventorier le SYSVOL : aucune GPO de configuration de poste (`se4_*`, `etab_*`) ne doit avoir été **créée ou modifiée** par SE5 depuis l'extinction. Seul `se4_agent_bootstrap` est légitimement publiable, et il n'est jamais ré-édité.
3. **Attendu** : compteur des écritures SYSVOL SE5 hors bootstrap = **0**. Les GPO `se4_*` résiduelles côté lab sont des coquilles figées (déliées hors worktree, action opérateur) — elles ne sont plus alimentées par SE5.
4. **Note inotify** : les fichiers de code supprimés dans le worktree ne se propagent PAS à la VM (mémoire `project_inotify_no_delete_sync`). Après merge sur `main`, des fantômes peuvent subsister côté VM (`legacy/modules/gpo/*.php`, `app/Gpo/Services/*` supprimés) — nettoyage SSH à valider avec l'opérateur, jamais depuis un worktree.

### Scénario 27.14-5 — Postes migrés reçoivent leur conf par l'agent (non-régression R2)

1. Sur un poste **migré** SE4→SE5 (agent vivant) : au prochain cycle desired-state, vérifier que wallpaper, raccourcis, lecteurs, imprimantes, associations, config d'app et applications convergent via `/api/v1/agent/state` → handlers Go (plus aucun appel `gpo/*_out.php`).
2. **Risque à surveiller (R2)** : un poste migré dont l'agent serait mort/absent NE reçoit plus de fragment de réinjection legacy (le passthrough `MigrationController` est supprimé). Confirmer l'état réel du parc migré (agent déployé partout) avant la bascule prod. Le kill-switch (actif depuis 2026-06-12) montrait déjà le comportement cible (fragment no-op) ; la suppression le rend permanent.

### Checklist rapide 27.14

- [ ] **27.14-0** Gate de parité documenté (table de la story remplie, démo réaliste postes migrés+neufs/salles multiples)
- [ ] **27.14-1** `route:list` : 0 route `gpo/*_out.php` / `gpo/applications.php` / `workstation-config/*` / `shortcuts/export/*` / `api/policies/*` ; survivants (linux_out/winget_out, agent, 301, thumbnails, ControlHub) présents
- [ ] **27.14-2** Bootstrap `se4_agent_bootstrap` diff vide + `Se4AgentBootstrapTemplateTest` vert + `isPublishable` true
- [ ] **27.14-3** Kill-switch + linux_out/winget_out + carte réglages déploiement préservés ; audit/re-publish `se4_wpkg` retirés
- [ ] **27.14-4** KPI 0 GPO hors bootstrap (audit SYSVOL opérateur lab) ; fantômes inotify nettoyés hors worktree
- [ ] **27.14-5** Postes migrés convergent par l'agent (zéro appel `*_out.php`) ; parc migré couvert par l'agent avant bascule prod

## Story 27.16 — Déploiement automatisé de la GPO bootstrap `SE_agent_bootstrap` + isolation par blocage d'héritage

> **Renommage SE5** : la GPO bootstrap 25.4 `se4_agent_bootstrap` devient `SE_agent_bootstrap` (dossier + `[General]displayName` + en-tête `startup.cmd`). Préfixe `se_` ajouté à `GpoTemplateRegistry::ALLOWED_PREFIXES` (sans retirer `se4_`/`etab_`).
>
> **Automatisation** : la publication (ex-pas de runbook manuel Fork 2 25.4) est désormais portée par la commande `php artisan gpo:deploy-agent-bootstrap`, câblée dans `scripts/update.sh` (`ensure_agent_bootstrap_gpo`) et donc dans `install.sh` (qui rejoue update.sh). Idempotente, **fail-soft**, contexte Administrator pour vaincre le blocage SYSVOL READ-only.
>
> **Isolation (AD mutualisé ~75 collèges)** : option 1 « par établissement » — blocage d'héritage (`gPOptions=1`) sur l'OU computers de NOTRE établissement + lien du bootstrap sur cette MÊME OU (JAMAIS la racine). Aucune GPO legacy supprimée/déliée/éditée (objets partagés fédération-wide). Prérequis : DC joignable + `admin_passwd`. E2e réel = action manuelle Henri sur le lab.

### Scénario 27.16-1 — Renommage SE5 reconnu comme template publiable (HÔTE)

1. `php artisan test --filter 'SeAgentBootstrap|GpoTemplateRegistry'` → vert (préfixe `se_`, `[CSE]`, CRLF + pur ASCII, non-régression `se4_`/`etab_`, `se_` ne capture pas `session_x`).
2. `git status resources/gpo/SE_agent_bootstrap/` → rename propre depuis `se4_agent_bootstrap/` ; `GPT.INI` porte `displayName=SE_agent_bootstrap` ; `startup.cmd` en-tête renommé.
3. **Attendu** : `file resources/gpo/SE_agent_bootstrap/Machine/Scripts/Startup/startup.cmd` → « DOS batch file, ASCII text, with CRLF line terminators » (aucun LF orphelin, aucun octet non-ASCII). `GpoTemplateRegistry::isPublishable('SE_agent_bootstrap')` → `true`.

### Scénario 27.16-2 — Publication Administrator + vérification d'écriture RÉELLE (lab, opérateur)

1. Prérequis : DC AD joignable + `admin_passwd` (Domain Admin) en config. PKI initialisée + une release **stable** publiée (cf. runbook §0).
2. `php artisan gpo:deploy-agent-bootstrap` (sur la VM/se4fs). La commande : stage le template → `templates_dir/sambaedu-gpo/SE_agent_bootstrap/`, établit un ticket Kerberos **Administrator** dédié (`kinit` dans un `KRB5CCNAME` temporaire, purgé en fin), publie via le shim legacy `import_gpo` (crée la GPC si absente, écrit SYSVOL, pose `gPCMachineExtensionNames`, version).
3. **Vérification d'écriture réelle** (anti faux-succès `www-sambaedu` READ-only) : la commande re-lit le `startup.cmd` déposé en SYSVOL (`smbclient ls` sous Administrator) et exige une **taille > 0** ; un `ACCESS_DENIED` masqué en exit 0 devient un **échec explicite** (`gpo.sysvol.write` failure), pas un faux « publié ».
4. **Attendu** : `samba-tool gpo listall | grep SE_agent_bootstrap` la liste ; l'objet GPC porte `gPCMachineExtensionNames` (sans quoi le `startup.cmd` ne s'exécuterait jamais — piège runbook §3bis). Aucun secret en clair dans la sortie ni les logs `gpo` (`operation_id` corrélé `gpo.create`/`gpo.sysvol.write`/`gpo.link.add`/`gpo.inheritance.set`).

### Scénario 27.16-3 — Blocage d'héritage sur l'OU établissement (les GPO legacy ne s'appliquent plus à NOS postes, les 74 autres collèges intacts)

1. Après déploiement, sur le DC : `samba-tool gpo getinheritance "OU=<code>,OU=computers,<base_dn>"` → **GPO_BLOCK_INHERITANCE** (prod/lab1 avec couche établissement) ; en localdev plat → `OU=computers,<base>`.
2. **Attendu (notre établissement)** : un poste de NOTRE OU computers ne reçoit plus les GPO legacy liées racine (`wpkg`, `Wallpaper`, `redirections`, `applications`, `proxy`, `imprimantes`, `lecteurs reseau`, …) — `gpresult /R` sur un poste de test ne les liste plus comme appliquées.
3. **Attendu (autres collèges, NON-RÉGRESSION fédération)** : aucune autre OU établissement (`OU=<autre_code>,OU=computers`) n'a `gPOptions` modifié ; les 74 autres collèges continuent de recevoir les GPO racine. **Aucune** GPO legacy supprimée/déliée/éditée (ACL/Deny inclus) — `samba-tool gpo listall` et les `gPLink` racine inchangés.
4. **Idempotence** : ré-exécuter `gpo:deploy-agent-bootstrap` → héritage déjà bloqué = noop (pas d'erreur), lien déjà présent = succès silencieux (16.5).

### Scénario 27.16-4 — Lien bootstrap sur l'OU établissement + filet éternel sur un poste

1. **Attendu** : `samba-tool gpo getlink "OU=<code>,OU=computers,<base_dn>"` liste `SE_agent_bootstrap` (lié à l'OU établissement, JAMAIS à la racine — sinon il viserait les 75 collèges).
2. Déplacer un poste agent-less (ex. `windeboule`) dans l'OU établissement (action opérateur, hors commande), `gpupdate /force` puis **reboot** → le `startup.cmd` (SYSTEM) (re)pose la CA + le binaire stable + `agent.exe install` + la tâche de refresh 240 min. L'agent **demande son enrôlement** (porte 2), approbation un-clic (25.3) → convergence.
3. **Attendu** : un poste dont l'install agent unattend a échoué (403 `local.request` historique) se ré-installe l'agent tout seul au boot suivant — le filet FR25/#27 cesse d'être un pas manuel jamais exécuté.

### Scénario 27.16-5 — Garde fail-soft (DC injoignable / creds absents) + idempotence (HÔTE + lab)

1. **HÔTE** : `php artisan test --filter 'AgentBootstrapPublisher|GpoDeployAgentBootstrap'` → vert. La garde renvoie `skipped` (pas d'exception, AUCUN appel destructeur `setInheritance`/`setLink`) quand `admin_passwd` absent OU DC injoignable ; mapping exit codes : skip/deployed/dry-run → 0, `failed` → 0 (fail-soft) sauf `--strict` → 1.
2. **Lab** : couper le DC (ou vider `admin_passwd`) puis lancer `scripts/update.sh` → l'étape `ensure_agent_bootstrap_gpo` **warn + skip**, l'update **ne casse PAS** (exit non bloquant). Rétablir DC + creds → relancer : publication effective, idempotente.
3. **Attendu** : `--dry-run` affiche l'OU cible détectée et NE fait AUCUNE écriture (pas de staging effectif, pas de publication, pas de lien).

### Checklist rapide 27.16

- [ ] **27.16-1** Rename `SE_agent_bootstrap` reconnu (préfixe `se_`) + `SeAgentBootstrapTemplateTest`/`GpoTemplateRegistryTest` verts ; CRLF + pur ASCII intacts ; `se4_`/`etab_` non régressés
- [ ] **27.16-2** Publication Administrator (kinit dédié purgé) + `gPCMachineExtensionNames` posé + **vérification d'écriture réelle** (faux-succès SYSVOL → échec explicite) ; aucun secret en clair
- [ ] **27.16-3** Héritage bloqué sur l'OU computers de NOTRE établissement ; GPO legacy ne s'appliquent plus à nos postes ; 74 autres collèges intacts ; aucune GPO legacy supprimée/déliée/éditée
- [ ] **27.16-4** `SE_agent_bootstrap` liée à l'OU établissement (jamais racine) ; poste agent-less se ré-installe l'agent au reboot (filet éternel)
- [ ] **27.16-5** Garde fail-soft (DC/creds absents → skip non bloquant) ; idempotence (re-run = noop) ; `--dry-run` sans side effect ; `update.sh`/`install.sh` ne cassent jamais
