# Story 5.2 : Partages de Classe et Gestion des ACLs POSIX

Status: review

> **Origine** : Epic 5 — Système de Fichiers SER. Cinquième et dernière story de l'Epic, après le bloc 5.1a/b/c/d (quotas + corbeille). 5.2 absorbe le besoin Story 1bis.13b `acls` (CANCELLED 2026-04-17 — defer Epic 5) et la moitié de 1bis.14 `partages` (PAUSED — refonte native).
>
> **Dépendances amont (toutes livrées)** :
> - **5.1a done** (2026-04-23) — `App\Services\Filesystem\HomeDirService` + `XfsQuotaService` extraits dans `app/Services/Filesystem/`. Pattern Service à suivre : DI constructeur, regex anti-injection `/^[a-zA-Z0-9._-]+$/` sur paramètres user-controlled, `escapeshellarg` sur tout argument shell, `Log::error('QuotaService: ...')` (préfixe à adapter en `AclService:` / `ShareService:`), retour `bool` ou `array` (pas d'exception silencieuse — fail-soft documenté).
> - **5.1c done** (2026-04-27) — table `system_settings` K/V JSON + modèle `SystemSetting::get/set/forget`. Page `/admin/settings` avec onglets extensibles (mono-onglet "Quotas & FS" actuellement). 5.2 PEUT ajouter un onglet "Partages" si besoin (à arbitrer en kickoff D11).
> - **5.1d done** (2026-04-29 — review en cours, non bloquant) — pattern d'investigation legacy + 3 commandes Artisan livrées ; SystemSetting + audit log éprouvés.
> - **7.2 done** (2026-04-28) — `App\Policies\SharePolicy` existe déjà (`viewAny-share`/`view-share`/`refresh-share`) basée sur permissions Spatie `share.view` / `share.refresh`. **À étendre** en 5.2 avec une nouvelle méthode `manage(?Authenticatable, ?ShareEloquent): bool` adossée à une nouvelle permission `share.manage` (à ajouter dans `App\Enums\SambaPermission`).
> - **2.2 done** — `UserService::modifyUser()` existe et synchronise les `user_groups` Eloquent (méthode `$sqlUser->groups()->syncWithoutDetaching($groupIds)` l. 1414). **Aucun event/listener actif** sur ces changements aujourd'hui — l'orchestration de re-application des ACLs lors d'un changement de classe est l'objet de la Story 5.2 (décision D5 ci-dessous).
>
> **Scope 5.2 — création de partages de classe + gestion ACLs POSIX** :
> 1. **Domain model** — Décider si une table Eloquent `shares` est nécessaire (tracking métier audit+rattachement) ou si la liste est dérivée du filesystem `/var/sambaedu/Classes/Classe_*/` + des `UserGroup::where('type', 'classe')` (lecture pure, pas de table) — décision D1.
> 2. **`AclService`** — service `app/Services/Filesystem/AclService.php` qui encapsule `setfacl`/`getfacl` (pattern décalqué sur `partages.inc.php::set_acls`/`get_facl`/`add_acl`/`remove_acl`/`check_acls`). Méthodes publiques : `setAcls(string $path, array $acls, bool $recurse=true): bool`, `getFacl(string $path): array|false`, `addAcl(string $path, string $acl, bool $recurse=true): bool`, `removeAcl(string $path, string $acl, bool $recurse=true): bool`, `applyDefaultAcls(string $path, array $acls): bool`. Fail-soft : `Log::error('AclService: ...')` + retour `false`. Anti-injection : regex sur path absolu `/^/var/sambaedu/Classes/[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)*$/` + `escapeshellarg` sur path et arguments setfacl.
> 3. **`ShareService`** — service `app/Services/Filesystem/ShareService.php` qui orchestre la création/suppression/mise à jour d'un partage de classe. Méthodes publiques : `createClassShare(UserGroup $classe): bool` (crée `/var/sambaedu/Classes/Classe_<nom>/`, sous-dirs `_travail`, `_profs`, `_echange`, applique le set d'ACLs canonique 1:1 avec `partages.inc.php::update_classes` l. 452-580), `syncUserClassMemberships(User $user, array $oldClassIds=[], array $newClassIds=[]): bool` (révoque ACLs de l'ancienne classe, accorde sur la nouvelle, déplace `<eleve>/` archive si nécessaire — décalqué sur `cree_rep()` l. 326-393), `toggleEchange(UserGroup $classe, bool $active): bool` (toggle ACL `_echange` rwx ↔ ---), `archiveClassShare(UserGroup $classe): bool` (renomme `Classe_X` en `.Classe_X` quand le groupe est supprimé — pattern `partages.inc.php` l. 575-578).
> 4. **UI minimale** — section "Partage de classe" sur `/app/users/groups/[id]` (visible uniquement si `$group->type === 'classe'`) avec : état actuel (créé / non créé / désactivé), liste des sous-répertoires + permissions résumées (lecture du `getFacl`), bouton "Créer le partage" (si non créé), toggle "Activer le dossier d'échange" (D6 = activé par défaut ?), bouton "Réappliquer les ACLs" (idempotent, force refresh ACLs sur tout le sous-arbre).
> 5. **Hook changement de classe** — décision D5 : Observer Eloquent sur la table pivot `user_group_user` (création/suppression de relation User↔UserGroup type='classe') OU appel explicite depuis `UserService::modifyUser` post `syncWithoutDetaching` OU Job dispatché.
> 6. **Tests** — Unit (`AclServiceTest`, `ShareServiceTest`) avec `Process::fake` pour mocker `setfacl`/`getfacl`, Feature (UI `/app/users/groups/[id]` + Policy + hook).
>
> **Ce qui est HORS scope 5.2** (à acter en clarifications) :
> - Pas de gestion `smb.conf` modifiable — la section globale `[classes]` du `smb.conf` est déjà en place (path `/var/sambaedu/Classes`, vue VM 2026-04-30). 5.2 N'ÉCRIT PAS dans `smb.conf`. Tous les sous-dossiers sont automatiquement exposés via cette section. **Confirmation kickoff D8.**
> - Pas de gestion partages "personnels" autre que ceux liés aux classes (les `Cours_X`, `Matiere_X`, `Projet_X`, `Equipe_X` peuvent être traités plus tard si besoin — défer Epic 5+ ou hors-Epic).
> - Pas de migration UI `dossier_echange.php` — fonctionnalité couverte par le toggle dans la section partage de la page groupe (D6).
> - Pas de gestion `acls.php` legacy d'attribution d'ACLs arbitraires sur dossiers arbitraires — module obsolète confirmé Henri 2026-04-17 (cf. epics.md:794).
> - Pas de `home directory` ACLs (déjà géré par `HomeDirService` 5.1a).
> - Pas de Spatie permission migration — la nouvelle permission `share.manage` est ajoutée passivement (entrée enum + seeder/migration), pas de mapping legacy `ShareRefresh` → `ShareManage` (le scope 5.2 NE CASSE PAS la matrice 7.x).

---

## Story

En tant que **responsable de collège**,
je veux créer des répertoires de partage par classe (`Classe_<nom>`) avec ACLs POSIX héritées correctes (lecture/écriture par rôle), gérer le dossier d'échange dans chaque classe, et garantir que les ACLs sont mises à jour automatiquement quand un élève change de classe,
afin que les membres d'une classe accèdent aux ressources partagées avec les bons droits sans intervention shell, et que les changements d'effectifs (Story 2.2) propagent leurs effets sur le filesystem sans dérive entre AD et ACLs disque.

---

## Contexte & Motivation

### Investigation Legacy (effectuée par SM 2026-04-30)

Examen de `sambaedu/includes/partages.inc.php` (673 lignes) + `sambaedu/partages/rep_classes.php` + `sambaedu/dossier_echange/dossier_echange.php` + `sambaedu/acls/*` :

#### Structure FS legacy (validée VM 2026-04-30)

```
/var/sambaedu/Classes/
├── Classe_<NomClasse>/           # 1 dossier par classe
│   ├── _profs/                    # privé enseignants
│   ├── _travail/                  # lecture élève / écriture prof
│   ├── _echange/                  # déposable par tous, optionnel (toggle)
│   ├── <eleve_login_1>/           # dossier perso élève
│   ├── <eleve_login_2>/
│   └── ...
├── Classe_<AutreClasse>/
└── ...
```

Le `smb.conf` VM expose déjà la section globale `[classes]` (`path=/var/sambaedu/Classes`, `read only=no`, `create mask=0777`, `directory mask=0777`, vue 2026-04-30). **Aucune section `[Classe_<nom>]` individuelle** n'est nécessaire — les sous-dossiers héritent de l'exposition `[classes]`.

#### Schéma ACLs canonique legacy (référence : `partages.inc.php` l. 452-580)

**Sur `Classe_<nom>/` (racine classe)** :
```
user::rwx
group::---
group:equipe_<class_lower>:rx        # équipe pédagogique (lecture)
group:Classe_<class_lower>:rx        # tous les membres classe (lecture)
group:domain admins:rwx               # admins
mask::rwx
other::---
default:user::rwx
default:group::---
default:group:equipe_<class_lower>:rwx  # équipe : rwx hérité par les sous-dirs
default:group:domain admins:rwx
default:mask::rwx
default:other::---
```
+ `chgrp domain admins` + `chown www-admin`

> **Clarification (review #4, 2026-04-30)** : `default:group:Classe_<class_lower>:rx` n'est **PAS** posé sur la racine — le legacy ligne 567 ajoute uniquement les non-default `-m "group:equipe_X:rx,group:Classe_X:rx"`. La lecture `Classe_X:rx` est héritée via les sous-dirs `_travail`/`_echange` qui ont leur propre `default:group:Classe_X:rx` dans leurs ACLs. L'implémentation Laravel (`buildRootRwToRxAdjustment` ligne 148) est fidèle au legacy.

**Sur `Classe_<nom>/_travail/`** : ajout `group:Classe_<class_lower>:rx` (lecture seule) avec `default:group:Classe_<class_lower>:rx` (héritage descendants).

**Sur `Classe_<nom>/_echange/`** :
- ACL `group:Classe_<class_lower>:rwx` (activé) OU `group:Classe_<class_lower>:---` (désactivé)
- + `default:group:Classe_<class_lower>:<rwx|--->`
- Toggle persistant via re-application

**Sur `Classe_<nom>/<eleve_login>/`** (dossier perso) :
```
user::rwx
group::---
user:<eleve_login>:rwx               # le propriétaire élève
group:equipe_<class_lower>:rwx       # équipe pédagogique en RW (correction d'élève)
group:domain admins:rwx
mask::rwx
other::---
default:user::rwx
default:group::---
default:group:equipe_<class_lower>:rwx
default:group:domain admins:rwx
default:mask::rwx
default:other::---
default:user:<eleve_login>:rwx
```
+ `chgrp domain admins` + `chown www-admin`

#### Fonctions legacy clés à réimplémenter

| Fonction legacy | Lignes | Rôle | Adaptée vers |
|---|---|---|---|
| `set_acls($path, array $acls, bool $recurse)` | l. 27-43 | Wipe + setfacl batch sur path | `AclService::setAcls(string $path, array $acls, bool $recurse=true): bool` |
| `add_acl($path, string $acl, bool $recurse)` | l. 128-142 | setfacl -m (ajout) | `AclService::addAcl()` |
| `remove_acl($path, string $acl, bool $recurse)` | l. 72-85 | setfacl -x (suppression) | `AclService::removeAcl()` |
| `check_acls($path, array $acls): bool` | l. 14-25 | getfacl + comparaison | `AclService::checkAcls(string $path, array $acls): bool` (utile pour `réappliquer` idempotent) |
| `get_facl($path): array\|false` | l. 87-118 | parse getfacl en tableau associatif | `AclService::getFacl(string $path): array\|false` |
| `update_classes($config, $nom, $echange, $etat)` | l. 452-580 | crée la racine classe + `_travail` + `_profs` (+ `_echange` si toggle) avec ACLs, puis itère sur les élèves via `update_eleve` | `ShareService::createClassShare(UserGroup $classe): bool` + `ShareService::toggleEchange(UserGroup $classe, bool $active)` |
| `cree_rep($config, $login, $OldClasse)` | l. 326-393 | crée le dossier élève dans la classe, gère le déplacement archive si changement de classe | `ShareService::syncUserClassMemberships(User $user, array $oldClassIds, array $newClassIds): bool` |
| `update_eleve($config, $login, &$html)` | l. 395-432 | trouve la classe actuelle d'un élève, appelle `cree_rep` | Inclus dans `syncUserClassMemberships` (lookup classe via Eloquent `$user->groups()->where('type', 'classe')->get()`) |

#### Points clés de sécurité dans le legacy

- **Anti-injection** : le legacy utilise `escapeshellarg` partiellement (sur `$path` mais pas toujours sur `$acl`). 5.2 doit appliquer `escapeshellarg` SYSTÉMATIQUEMENT sur path ET acl + valider le path en regex `/^/var/sambaedu/Classes/[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+){0,3}$/` (limite à 3 niveaux de profondeur — racine classe, sous-dirs _travail/_profs/_echange/<eleve>, exclut tout traversal `..`).
- **Sudoers** : tous les `setfacl` legacy passent par `sudo`. La VM doit avoir `www-data ALL=(root) NOPASSWD: /usr/bin/setfacl, /usr/bin/getfacl, /bin/mkdir, /bin/mv, /bin/rm` ou plus précis. **À valider en kickoff D7**.
- **Atomicité** : si `mkdir` réussit puis `setfacl` échoue, le dossier reste créé sans ACLs. Le legacy ignore (best-effort). 5.2 doit décider : rollback (`rm -rf` du path créé) ou laisser + log error pour réapplication ultérieure (recommandation SM : laisser + log + idempotence garantie via `setAcls` qui fait `setfacl -b` puis batch — décision D9).

### Ce qui est déjà livré (à NE PAS refaire)

| Composant | État | Détail |
|---|---|---|
| `App\Models\UserGroup` | livré | Champ `type` string. `type='classe'` est la valeur canonique pour les classes scolaires. `name` = nom court (ex: `6A`), `display_name` optionnel. **Le nom AD effectif est `Classe_<name>`** (cf. `UserGroupService::resolvePrimaryGroupName` l. 559). 5.2 utilise le `name` brut comme partie suffixe de `Classe_<name>` côté FS. |
| `App\Models\User::groups()` | livré | Relation BelongsToMany via pivot `user_group_user`. Récupération des classes actuelles : `$user->groups()->where('type', 'classe')->get()`. |
| `App\Policies\SharePolicy` | livré 7.2 | Gates `viewAny-share`/`view-share`/`refresh-share` enregistrés. **À étendre** en 5.2 avec gate `manage-share` (permission `share.manage`). |
| `App\Enums\SambaPermission` | livré 7.2 | Enum avec `ShareView`/`ShareRefresh`. **À étendre** : ajouter `case ShareManage = 'share.manage';` + mapping `legacyRight()` (probablement vers `LegacyRight::ShareRefresh` puisque le legacy utilise `SE_SHARE_REFRESH` pour gérer les classes — cf. `partages/rep_classes.php:58`). |
| `App\Services\Filesystem\HomeDirService` | livré 5.1a | Pattern Service à imiter : DI constructeur, regex login, `escapeshellarg`, `exec(... 2>&1)` + return code, `Log::error/warning/info`, fail-soft retourne `bool`. 41 tests anti-injection livrés. |
| `App\Services\Filesystem\XfsQuotaService` | livré 5.1a | Pattern à imiter (préfixe log conservé `QuotaService:`). 5.2 utilisera `AclService:` / `ShareService:` comme préfixes pour distinguer les domaines. |
| `App\Models\SystemSetting::get/set` | livré 5.1c | Helpers statiques. 5.2 PEUT s'en servir si besoin de stocker des configs partages globales (D11) — sinon non utilisé. |
| `App\Components\Traits\WithToasts` | livré | Pour les boutons Livewire de la section partage page groupe. |
| Resources `pages/users/groups/[id]/index.blade.php` + `_partials/` | livré 5.1c | Page groupe existante (Livewire SFC). `_partials/group-quota-section.blade.php` est le pattern à imiter pour `class-share-section.blade.php`. **L'inclusion conditionnelle sur `$group->type === 'classe'`** est déjà supportée (cf. cohérence avec `members-list.blade.php` qui s'affiche pour tous types). |
| `smb.conf` VM `[classes]` | livré (legacy en prod) | Section globale `[classes]` path=/var/sambaedu/Classes (vue VM 2026-04-30). Aucune modif `smb.conf` requise pour 5.2. |
| `App\Models\QuotaAuditLog` | livré 5.1a | Modèle d'audit polyvalent (cols `target_type`, `target`, `action`, `performed_by`). **5.2 PEUT le réutiliser** pour tracer les opérations partage avec `target_type='share'`, `action='create'\|'sync'\|'archive'\|'reapply'` — ou créer une table dédiée `share_audit_logs` (D10). |

### Hook changement de classe — état des lieux (D5)

Aujourd'hui, le code Laravel synchronise les groupes Eloquent via `$sqlUser->groups()->syncWithoutDetaching($groupIds)` (`UserService.php:1414` dans `createUser`) et plus largement via `modifyUser` (Story 2.2 done). **AUCUN event/listener n'est déclenché** — pas d'Observer sur la pivot, pas d'event `UserGroupAttached/Detached`. Les changements de classe AD ne propagent donc actuellement aucune mise à jour ACL filesystem.

Le legacy traitait cela via 2 voies :
1. **Bouton "Mise à jour des classes" dans `partages/rep_classes.php`** — appel manuel administrateur déclenchant `update_classes(*)` sur toutes les classes sélectionnées (full re-sync FS depuis l'AD).
2. **Cron périodique** sur certaines installations (non systématique).

**4 options 5.2 (D5)** :
- **Option A — Observer Eloquent** sur la pivot `user_group_user` : l'attach/detach déclenche `ShareService::syncUserClassMemberships()` synchrone. Avantages : automatique, transparent, testable Laravel-natively. Inconvénient : 1 setfacl par attach/detach = latence cumulée si batch (ex: import LDAP qui assigne 30 classes à 500 élèves = 15000 calls). Mitigation : queue les jobs.
- **Option B — Appel explicite depuis `UserService::modifyUser` post `syncWithoutDetaching`** : code call site dans le service métier. Plus prévisible, mais oublié si on ajoute un autre call site (ex: import bulk).
- **Option C — Job dispatché async** depuis Observer ou call site explicite → `Jobs/SyncUserClassSharesJob.php` (queue `default`). Avantage : pas de latence UI/CLI bulk. Inconvénient : dette de monitoring (jobs failed à reprocessor).
- **Option D — Commande Artisan `shares:resync-class {--classe=}`** uniquement, exécutée manuellement ou via cron. Pas d'auto-sync. Reproduit le pattern legacy "bouton refresh".

**Recommandation SM : combinaison A+D** — Observer pour les changements unitaires (UI Story 2.2 single user), commande Artisan `shares:resync-class` pour les bulk + reprises. La commande est le filet de sécurité (re-applique les ACLs sur toute une classe ou toutes les classes en un coup). **Décision D5 à valider Henri.**

### Gestion suppression de classe (D4)

Quand un `UserGroup type='classe'` est supprimé côté Laravel (story future) :
- Le legacy renomme `Classe_X` en `.Classe_X` (`partages.inc.php:575-578`) — soft-archive sur le FS, pas de `rm -rf`.
- Aucun ACL spécifique appliqué (le dossier reste avec les ACLs précédentes, juste devient invisible aux smb users).

**Option A (recommandation SM)** : conserver le pattern legacy = `mv Classe_X .Classe_X`. Dossier soft-archivé, restauration manuelle possible (`mv .Classe_X Classe_X`). Pas de purge automatique (à arbitrer plus tard si besoin via `shares:purge-archived` future story). **Cohérent avec le pattern 5.1d trash:purge** : soft-delete d'abord, hard-delete sur policy.

**Option B** : pas de gestion suppression dans 5.2 (out of scope). La suppression de UserGroup côté Laravel n'a actuellement pas de page UI (cf. epics.md historique). 5.2 N'IMPLÉMENTE PAS la suppression UI mais EXPOSE la méthode `ShareService::archiveClassShare()` pour usage programmatique futur.

**Recommandation SM : B** — implémenter `archiveClassShare()` méthode publique mais N'AUTOMATISER aucun appel (pas d'Observer sur `UserGroup::deleting`). La méthode est testée unitairement et disponible pour stories futures (suppression de classe). **Décision D4 à valider.**

### Choix archives élève (changement de classe)

Le legacy `cree_rep()` (l. 345-356) gère le cas changement de classe :
1. Si `OldClasse` fourni : déplace `Classe_<old>/<eleve>` vers `Classe_<new>/<eleve>/Archives` (ou `rm` si `Archives` existe déjà).
2. Sinon : crée un nouveau dossier vide.

5.2 doit reproduire cette logique dans `ShareService::syncUserClassMemberships()`. **Décision D3** : conserver le pattern `Archives/` legacy (recommandation SM, déjà compris par les utilisateurs) vs créer une stratégie alternative (ex: archivage `/var/sambaedu/Classes/_archives/<oldclass>/<eleve>/`).

### Couplages, points d'attention

1. **Sudoers VM** — `setfacl`/`getfacl` doivent passer en sudo NOPASSWD pour `www-data`. Le legacy a déjà cette config en prod. À valider kickoff (D7).
2. **Atomicité** — `mkdir` puis `setfacl` ne sont pas atomiques. Si `setfacl` échoue, le dossier existe sans ACLs (visible des autres). Décision D9 : laisser + log + idempotence garantie via réapplication (recommandation SM) vs rollback `rm -rf`.
3. **Idempotence** — `setAcls` legacy fait `setfacl -b` (wipe) puis batch. Re-exécution = idem. **Garanti idempotent**. ✅
4. **Performance** — pour une classe de 30 élèves, créer le partage = 1 setfacl racine + 1 setfacl `_travail` + 1 setfacl `_profs` + 1 setfacl `_echange` + 30 × `setfacl <eleve>` ≈ 34 invocations sudo. Sur SSD ~2-3s total. Acceptable. **Pour bulk re-sync 500 élèves × 20 classes = 10000+ calls** — d'où l'intérêt de la commande `shares:resync-class --classe=X` ciblée (D5).
5. **Tests** — `Process::fake` ou `\Illuminate\Support\Facades\Process` pour mocker `setfacl/getfacl`. Pas besoin de vrai `tempnam` + ACL POSIX (peut foirer en CI Linux non-XFS et sur dev macOS). **Décision D12** : `Process::fake` mocks systématique vs vrai tempdir + skip si pas de `setfacl` dispo. Recommandation SM : `Process::fake` + 1 test e2e manuel VM smoke (cohérent docs/qa/domains/filesystem.md).
6. **Convention naming** — `UserGroup::name` peut contenir des caractères spéciaux (espaces, accents) — le legacy gère via `preg_replace('/ /', '\\\\040', $Classe)`. La regex anti-injection 5.2 sur path doit valider qu'on ne crée pas de chemin avec `..` ou autre. À traiter dans `AclService::validatePath()`.
7. **Convention groupes AD** — l'ACL legacy référence `equipe_<class_lower>` et `Classe_<class_lower>` (lowercase). Côté Laravel, le mapping vers Eloquent `UserGroup` se fait via `name` brut. Le `AclService` doit appliquer le `strtolower($name)` ET `preg_replace(' ', '\\040', ...)` pour la chaîne ACL group. **À documenter et tester** (cf. `partages.inc.php:344` `$ClasseAcl = strtolower(preg_replace("/ /", "\\\\040", $Classe));`).
8. **Permission Spatie `share.manage`** — nouvelle permission. Mapping legacy : recommandation Henri = pointe vers `LegacyRight::ShareRefresh` (le bit `SE_SHARE_REFRESH` du legacy couvrait à la fois la consultation et la mise à jour des classes). Décision D2.
9. **Gate `manage-share`** — méthode Policy `SharePolicy::manage(?Authenticatable, ?ShareEloquent)` — accepte `null` model si la création n'a pas encore d'objet (pattern Laravel standard). Test bypass payload Livewire forgé attendu (cohérent saveDefaults 5.1c).
10. **Documentation QA** — append-only sur `docs/qa/domains/filesystem.md` (section "Story 5.2 — partages classe + ACLs" avec scénarios numérotés stables 5.2-1, 5.2-2…). **PAS** de fichier `5-2-e2e-manual.md` (interdit par convention 5.1c — cf. `docs/qa/README.md:14-17`).
11. **Sidebar `/admin/settings`** — pas d'ajout d'onglet "Partages" car la gestion est dans la page groupe (D11). Si configs globales partages futures (ex: profil ACL non-classe), ajouter un onglet à ce moment.

---

## Décisions produit à arbitrer au kickoff

> Les décisions doivent être confirmées par Henri avant le démarrage de l'implémentation. Reporter les choix dans Dev Notes section "Kickoff Décisions".

1. **D1 — Stockage métier des partages : table Eloquent `shares` ou pure FS** :
   - **(A) Pas de table dédiée — source de vérité = filesystem `/var/sambaedu/Classes/Classe_*/` + `UserGroup::where('type', 'classe')`** (recommandation SM — cohérent legacy, simple, pas de migration). Le `ShareService::listShares()` glob le FS + cross-check avec `UserGroup`. Pas d'audit champ-by-champ — les opérations sont tracées via `QuotaAuditLog` (D10=A) ou table dédiée `share_audit_logs` (D10=B).
   - **(B) Table `shares` (PK `path` ou `name`, FK `user_group_id`, audit `created_at/created_by_user_id`)** — modèle 6.1 printers. Avantage : audit fin, rattachement explicit. Inconvénient : 1 migration + 1 modèle + sync FS↔BDD à maintenir + double source de vérité.
   - Recommandation : **A** (KISS, suit pattern legacy testé). Si Henri tient à l'audit fort, **B**.

2. **D2 — Permission Spatie `share.manage` — mapping legacy** :
   - **(A) Pointe vers `LegacyRight::ShareRefresh`** (recommandation SM — le legacy `SE_SHARE_REFRESH` couvrait création/MAJ classes). Mapping bit unique partagé avec `share.refresh`.
   - **(B) Nouveau `LegacyRight` dédié `ShareManage`** — demande extension enum `LegacyRight` + bitmask. Plus pur sémantiquement mais plus lourd.
   - **(C) Pointe vers `LegacyRight::ServerAdmin`** — restrictif (seuls les admins serveurs peuvent gérer les partages). Plus simple côté autorisation, plus fermé fonctionnellement.
   - Recommandation : **A**.

3. **D3 — Stratégie archives élève (changement de classe)** :
   - **(A) Pattern legacy `Classe_<new>/<eleve>/Archives/` qui contient l'ancien dossier** (recommandation SM — cohérent legacy, élève retrouve ses anciens travaux dans son nouveau dossier).
   - **(B) Archive dans `/var/sambaedu/Classes/_archives/<oldclass>/<eleve>/`** — séparation propre, mais découplé de la nouvelle classe (l'élève doit savoir aller chercher ailleurs).
   - **(C) Pas d'archive — purge directe** — risque perte data, déconseillé.
   - Recommandation : **A**.

4. **D4 — Gestion suppression de classe** :
   - **(A) `archiveClassShare()` méthode publique mais aucun appel automatique** (recommandation SM — out of scope auto, exposée pour stories futures).
   - **(B) Observer `UserGroup::deleting` qui appelle `archiveClassShare()` automatiquement**.
   - **(C) Hors scope total — méthode pas créée**.
   - Recommandation : **A** (méthode + tests, mais déclenchement manuel).

5. **D5 — Hook changement de classe (sync ACLs)** :
   - **(A) Observer sur pivot `user_group_user`** (création/suppression de relation User↔UserGroup type='classe' → call sync). Synchrone.
   - **(B) Appel explicite dans `UserService::modifyUser`** post `syncWithoutDetaching`. Synchrone.
   - **(C) Job async dispatché depuis A ou B**.
   - **(D) Commande Artisan `shares:resync-class` uniquement, manuelle/cron**.
   - **(A+D) (recommandation SM)** : Observer pour unitaire UI, commande Artisan pour bulk + reprise.
   - Recommandation : **A+D** — Observer synchrone (UI Story 2.2 single user) + commande Artisan ciblée (bulk + filet de sécurité).

6. **D6 — Dossier `_echange` activé par défaut à la création ?** :
   - **(A) Activé par défaut** (`group:Classe_X:rwx`) — recommandation SM, cohérent UX collège (les profs comptent sur l'échange).
   - **(B) Désactivé par défaut** — toggle requis pour activer.
   - Recommandation : **A**. Le toggle reste exposé pour désactivation post-création.

7. **D7 — Sudoers VM** :
   - Validation que `www-data ALL=(root) NOPASSWD: /usr/bin/setfacl, /usr/bin/getfacl, /bin/mkdir, /bin/mv` est en place sur la VM (5.1a confirme `sudo rm` éprouvé). Si entrées manquantes, escalader Henri. **Recommandation A — vérifier kickoff Tâche 0.3, ajouter au fichier sudoers VM si nécessaire.**

8. **D8 — `smb.conf` writes** :
   - **(A) Aucune écriture dans `smb.conf`** (recommandation SM — la section globale `[classes]` est suffisante, vue VM 2026-04-30). Pas de `smbcontrol reload-config` nécessaire.
   - **(B) Écriture conditionnelle dans une section dédiée par classe** — overkill, ne reproduit pas le legacy.
   - Recommandation : **A**.

9. **D9 — Atomicité création (mkdir + setfacl rollback ou pas ?)** :
   - **(A) Pas de rollback — laisser + log + bouton "Réappliquer ACLs" idempotent** (recommandation SM — cohérent legacy, idempotence garantit la convergence).
   - **(B) Rollback `rm -rf` du path créé en cas d'échec setfacl** — risque de perte si data déjà ajoutée par concurrent.
   - Recommandation : **A**.

10. **D10 — Audit log : table dédiée ou réutilisation `quota_audit_logs`** :
    - **(A) Réutiliser `quota_audit_logs` avec `target_type='share'`** (recommandation SM — table polyvalente, pas de migration).
    - **(B) Table dédiée `share_audit_logs`** — séparation propre, plus de migration.
    - Recommandation : **A**.

11. **D11 — Page admin "Réglages partages"** :
    - **(A) Pas d'onglet `/admin/settings → Partages` en 5.2** — la gestion se fait sur la page groupe (recommandation SM — KISS, pas de configs globales identifiées).
    - **(B) Onglet "Partages" avec configs globales** (ex: ACL profile par défaut, déclencher resync global).
    - Recommandation : **A**.

12. **D12 — Stratégie tests filesystem** :
    - **(A) `Process::fake` mocks `setfacl`/`getfacl`** (recommandation SM — robuste CI/dev macOS, rapide). 1 smoke test VM manuel documenté dans `docs/qa/domains/filesystem.md`.
    - **(B) Vrai `tempnam` + skip si setfacl indisponible** — fragile CI.
    - Recommandation : **A**.

13. **D13 — Convention path FS — utiliser `base_path()` pour racine partages ?** :
    - Le legacy hardcode `/var/sambaedu/Classes`. Pour les tests, il faut pouvoir overrider. **Recommandation SM** : exposer une property statique `ShareService::$classesRoot = '/var/sambaedu/Classes'` overridable en tests (cohérent pattern `TrashPurgeCommand::$trashDir` 5.1d). Permettre aussi config `config('filesystem.classes_root')` pour future flexibilité (différent path en environnement de test ou multi-tenant). **Décision : exposer property statique override + config Laravel optionnelle.**

14. **D14 — Sidebar/menu nav 5.2** :
    - Pas de nouvelle entrée nav (gestion via page groupe). **Recommandation A — pas de modif sidebar.**

---

## Acceptance Criteria

**AC 1 — Création d'un partage de classe avec ACLs canoniques**

**Given** un `UserGroup` avec `type='classe'`, `name='6A'`
**And** la racine `/var/sambaedu/Classes/Classe_6A/` n'existe pas
**When** `ShareService::createClassShare($group)` est appelé
**Then** le dossier `/var/sambaedu/Classes/Classe_6A/` est créé via `sudo mkdir`
**And** les sous-dossiers `_travail`, `_profs`, `_echange` sont créés
**And** chaque dossier reçoit le set d'ACLs canonique défini en investigation legacy (cf. section "Schéma ACLs canonique")
**And** D6=A : le dossier `_echange` est activé par défaut (`group:Classe_6a:rwx`)
**And** propriétaires : `chown www-admin` + `chgrp domain admins` sur tous les dossiers
**And** un row `QuotaAuditLog` (D10=A) est créé avec `target_type='share'`, `target='Classe_6A'`, `action='create'`, `performed_by=<login admin>`

**AC 2 — Application des ACLs membres après création**

**Given** un partage de classe créé pour `UserGroup name='6A'` avec 3 élèves rattachés (`alice`, `bob`, `charlie`)
**When** `ShareService::createClassShare($group)` complète son exécution
**Then** chaque élève dispose d'un dossier perso `/var/sambaedu/Classes/Classe_6A/<login>/` créé
**And** chaque dossier perso porte les ACLs élève canoniques (`user:<login>:rwx`, `group:equipe_6a:rwx`, `group:domain admins:rwx`, `default:user:<login>:rwx`)
**And** les enseignants membres du groupe `equipe_6A` (résolu via `UserGroup::where('name', 'equipe_6A')`) ont accès rwx via le groupe `equipe_6a` dans les ACLs (par construction)

**AC 3 — Idempotence : re-création d'un partage existant**

**Given** un partage `/var/sambaedu/Classes/Classe_6A/` déjà créé avec un ACL légèrement divergent (ex: ACL ajoutée manuellement par admin via shell)
**When** `ShareService::createClassShare($group)` est appelé une 2e fois
**Then** la commande `setfacl -b` (wipe) suivie de `setfacl --set ...` est appliquée
**And** l'ACL retrouve le set canonique (la divergence manuelle est écrasée)
**And** aucun dossier existant n'est supprimé (les fichiers data sont préservés)
**And** la commande retourne `true` même si certaines opérations sont des no-op

**AC 4 — Changement de classe d'un élève (Story 2.2)**

**Given** un élève `alice` rattaché à `UserGroup name='6A'` (id=10)
**And** la modification déplace `alice` vers `UserGroup name='5B'` (id=20)
**And** D5=A : Observer pivot `user_group_user`
**When** la pivot row `(user_id=alice.id, user_group_id=10)` est supprimée et `(user_id=alice.id, user_group_id=20)` est créée
**Then** `ShareService::syncUserClassMemberships(alice, [10], [20])` est invoqué automatiquement par l'Observer
**And** le dossier `/var/sambaedu/Classes/Classe_6A/alice/` est déplacé vers `/var/sambaedu/Classes/Classe_5B/alice/Archives/` (D3=A)
**And** un nouveau dossier `/var/sambaedu/Classes/Classe_5B/alice/` est créé avec les ACLs canoniques élève
**And** les anciens ACLs sur `/var/sambaedu/Classes/Classe_6A/` qui référencent `alice` sont implicitement obsolètes (ACLs `user:alice` retirées par re-application future ou par `shares:resync-class --classe=6A`)
**And** un row `QuotaAuditLog` `target_type='share'`, `action='sync_user'`, `target='alice'` trace l'opération

**AC 5 — Toggle dossier d'échange**

**Given** un partage `/var/sambaedu/Classes/Classe_6A/_echange/` actif (`group:Classe_6a:rwx`)
**When** `ShareService::toggleEchange($group, false)` est appelé
**Then** l'ACL est mise à jour : `group:Classe_6a:---` + `default:group:Classe_6a:---`
**And** le dossier `_echange` n'est PAS supprimé (data préservée, juste invisible aux membres classe)
**And** un nouvel appel `toggleEchange($group, true)` restaure `rwx`
**And** un row `QuotaAuditLog` `action='toggle_echange'` trace chaque toggle

**AC 6 — Réappliquer les ACLs (idempotent recovery)**

**Given** un partage de classe avec des ACLs partiellement corrompues (ex: dossier élève créé manuellement sans ACLs)
**When** je clique "Réappliquer les ACLs" sur la page `/app/users/groups/[id]`
**Then** `ShareService::createClassShare($group)` est rappelé (idempotent — AC 3)
**And** toutes les ACLs convergent vers le set canonique
**And** la commande retourne `true` ou un compteur d'erreurs si certains setfacl échouent
**And** un toast `WithToasts::toastSuccess("ACLs réappliquées avec succès — N opérations.")` confirme

**AC 7 — Lecture de l'état du partage (UI section)**

**Given** je consulte `/app/users/groups/[id]` pour un groupe `type='classe'`
**When** la page se charge
**Then** une section "Partage de classe" est visible (sous `members-list`, au-dessus de `group-quota-section`)
**And** la section affiche : état (créé / non créé), path FS résolu, sous-dirs présents avec ACL résumée (parsed via `getFacl`), toggle `_echange` (active/inactive), bouton "Créer le partage" (si non créé) ou "Réappliquer les ACLs" (si créé)
**And** Si `$group->type !== 'classe'`, la section N'EST PAS visible (seuls types classe ont des partages dédiés)
**And** Si l'utilisateur N'A PAS la permission `share.view`, la section affiche un message "Accès restreint"
**And** Si l'utilisateur N'A PAS la permission `share.manage`, les boutons d'action sont désactivés

**AC 8 — Sécurité : Gate `manage-share` enforced**

**Given** un utilisateur sans permission `share.manage`
**When** il forge un payload Livewire pour appeler `createShare()` ou `toggleEchange()` sur la page groupe
**Then** la méthode Livewire commence par `Gate::authorize('manage-share', $group)` ou `abort(403)` si refus
**And** le test Feature `ClassShareSectionTest::it_blocks_create_without_manage_permission` couvre le bypass tentative
**And** double guard : UI bouton `@can('manage-share', $group)` ET serveur `Gate::authorize` (pattern 5.1c)

**AC 9 — Permission `share.manage` ajoutée à l'enum + Policy**

**Given** la story 5.2 est livrée
**When** on inspecte `app/Enums/SambaPermission.php`
**Then** une case `ShareManage = 'share.manage'` existe
**And** son mapping `legacyRight()` retourne `LegacyRight::ShareRefresh` (D2=A)
**And** `app/Policies/SharePolicy.php` expose une méthode publique `manage(?Authenticatable $user, ?Model $share = null): bool` qui retourne `$this->hasPermission($user, 'share.manage')`
**And** le gate `manage-share` est enregistré dans le tableau statique `$gates`
**And** un seeder de tests + le seeder principal des permissions Spatie incluent `share.manage`

**AC 10 — Fail-soft + erreurs non-silencieuses**

**Given** une opération `setfacl` échoue (sudoers refusé, path inexistant, ACL invalide)
**When** l'erreur survient dans `AclService` ou `ShareService`
**Then** `Log::error('AclService: setfacl échec', ['path' => $path, 'acl' => $acl, 'output' => ...])` est émis
**And** la méthode publique retourne `false` (pas d'exception remontée)
**And** **aucune modification d'ACL précédente n'est altérée** (D9=A : pas de rollback, mais le `setAcls -b` initial est atomique au sens setfacl)
**And** un appelant peut décider de retry ou logger upstream
**And** test Feature `ShareServiceTest::it_returns_false_on_setfacl_failure` couvre

**AC 11 — Commande Artisan `shares:resync-class --classe=<name>` (D5=D)**

**Given** un partage `/var/sambaedu/Classes/Classe_6A/` avec dérive ACLs détectée
**When** `php artisan shares:resync-class --classe=6A` est exécuté
**Then** `ShareService::createClassShare($group)` est appelé pour `UserGroup name='6A'` (récupéré via Eloquent `where('type', 'classe')->where('name', '6A')->first()`)
**And** la sortie stdout liste les opérations effectuées (created/updated/errors par sous-dir et par élève)
**And** sans `--classe=` : la commande itère sur TOUS les `UserGroup type='classe'` actifs (équivalent legacy `update_classes(*)`)
**And** la commande supporte `--dry-run` pour preview
**And** la commande retourne `Command::SUCCESS` sauf si TOUTES les classes échouent (`Command::FAILURE`)

**AC 12 — Anti-injection chemins**

**Given** un `UserGroup` avec un `name` malicieux contenant `..`, `;`, `|`, `\`, `$`, espaces ou backticks
**When** `ShareService::createClassShare($group)` est appelé
**Then** la méthode rejette via `AclService::validatePath()` avec `Log::error` + retour `false`
**And** AUCUN appel `sudo mkdir` / `sudo setfacl` n'est exécuté
**And** test Unit `AclServiceTest::it_rejects_paths_outside_classes_root` couvre 8+ patterns malveillants (alignement HomeDirService 5.1a)

**AC 13 — Tests Unit + Feature complets**

**Given** les composants impactés par 5.2
**When** les tests tournent
**Then** **au minimum 18 nouveaux tests passent** (répartition indicative) :

1. `AclServiceTest::it_sets_acls_with_recurse_flag` (Process::fake)
2. `AclServiceTest::it_gets_facl_and_parses_output`
3. `AclServiceTest::it_adds_single_acl`
4. `AclServiceTest::it_removes_single_acl`
5. `AclServiceTest::it_validates_path_inside_classes_root`
6. `AclServiceTest::it_rejects_paths_outside_classes_root` (DataProvider 8+ injections)
7. `AclServiceTest::it_logs_error_on_setfacl_failure`
8. `ShareServiceTest::it_creates_class_share_with_canonical_acls` (AC 1)
9. `ShareServiceTest::it_creates_subdirs_travail_profs_echange` (AC 1)
10. `ShareServiceTest::it_applies_eleve_acls_for_each_member` (AC 2)
11. `ShareServiceTest::it_is_idempotent_on_recreate` (AC 3)
12. `ShareServiceTest::it_syncs_user_class_memberships_on_class_change` (AC 4)
13. `ShareServiceTest::it_archives_eleve_dir_when_changing_class` (AC 4 D3)
14. `ShareServiceTest::it_toggles_echange_acls` (AC 5)
15. `ShareServiceTest::it_returns_false_on_setfacl_failure` (AC 10)
16. `ClassShareSectionTest::it_renders_section_for_classe_type_only` (AC 7)
17. `ClassShareSectionTest::it_blocks_create_without_manage_permission` (AC 8)
18. `ClassShareSectionTest::it_invokes_create_share_on_button_click` (AC 7)
19. `ClassShareSectionTest::it_renders_facl_summary` (AC 7)
20. `SharesResyncClassCommandTest::it_resyncs_one_class_when_filter_provided` (AC 11)
21. `SharesResyncClassCommandTest::it_resyncs_all_classes_when_no_filter` (AC 11)
22. `SharesResyncClassCommandTest::it_supports_dry_run` (AC 11)
23. `UserGroupUserPivotObserverTest::it_triggers_sync_on_attach_classe_pivot` (AC 4 D5=A)
24. `UserGroupUserPivotObserverTest::it_does_not_trigger_for_non_classe_groups` (AC 4 garde)
25. `SambaPermissionTest::it_maps_share_manage_to_legacy_share_refresh` (AC 9 D2)

**AC 14 — Non-régression suite globale**

**Given** la suite complète (~1201 tests post-5.1d) verte au démarrage
**When** 5.2 est livrée
**Then** la totalité des tests existants restent verts sans modification
**And** la suite globale atteint **≥1201 + 18 = 1219 tests minimum**
**And** **aucune modification** n'est apportée à : `app/Services/Filesystem/HomeDirService.php`, `app/Services/Filesystem/XfsQuotaService.php`, `app/Models/User.php`, `app/Models/UserGroup.php` (sauf si Observer pivot D5=A nécessite une déclaration `protected $observables`)
**And** les tests de la `SharePolicy` 7.2 (`viewAny`/`view`/`refresh`) restent verts (la nouvelle méthode `manage` est additive)

**AC 15 — Documentation QA append-only**

**Given** la story 5.2 est livrée
**When** on inspecte `docs/qa/domains/filesystem.md`
**Then** une nouvelle section "Story 5.2 — Partages classe + ACLs POSIX" est appendée (pas de réécriture des sections 5.1c/5.1d antérieures)
**And** la section contient au minimum 8 scénarios numérotés stables `5.2-1, 5.2-2, ..., 5.2-8` couvrant :
  - 5.2-1 : Création partage classe nominal (avec membres)
  - 5.2-2 : Réapplication ACLs idempotente
  - 5.2-3 : Toggle dossier échange on/off
  - 5.2-4 : Changement de classe d'un élève (sync)
  - 5.2-5 : Suppression de classe (archive `mv` vers `.Classe_<x>`)
  - 5.2-6 : Bypass Gate manage-share via payload forgé → 403
  - 5.2-7 : Anti-injection path (UserGroup name avec `..`)
  - 5.2-8 : Commande `shares:resync-class --classe=X` smoke VM
**And** **PAS** de fichier `5-2-e2e-manual.md` séparé (convention 5.1c append-only par domaine)

**AC 16 — Logs préfixés explicites**

**Given** une opération AclService ou ShareService échoue ou réussit
**When** un log est émis
**Then** le préfixe est respectivement `'AclService: ...'` ou `'ShareService: ...'`
**And** le contexte log inclut au minimum : `path` (resolved), `class_name` (UserGroup::name), `operation` (create/sync/toggle/reapply), `output` (stderr du sudo command si erreur)
**And** **aucun log** ne fuite des données sensibles (mot de passe, ACL complète d'utilisateurs externes hors scope)

**AC 17 — Permission `share.view` autorise consultation only**

**Given** un utilisateur avec permission `share.view` mais SANS `share.manage`
**When** il consulte `/app/users/groups/[id]` pour un groupe classe
**Then** la section "Partage de classe" est visible (lecture state + getFacl)
**And** les boutons d'action ("Créer", "Réappliquer", toggle échange) sont désactivés ou masqués
**And** test Feature `ClassShareSectionTest::it_shows_readonly_for_view_only_permission` couvre

**AC 18 — Compatibilité `smb.conf` non touché**

**Given** la VM avec `smb.conf` actuel `[classes] path=/var/sambaedu/Classes` (vue 2026-04-30)
**When** 5.2 est livrée
**Then** **AUCUNE écriture** dans `/etc/samba/smb.conf` n'est effectuée par le code 5.2
**And** **AUCUN appel** `smbcontrol`, `testparm`, `samba-tool` n'est invoqué
**And** un grep `grep -rn "smb.conf\|smbcontrol\|testparm" app/Services/Filesystem/AclService.php app/Services/Filesystem/ShareService.php` retourne 0 hit
**And** D8=A documenté

---

## Tasks / Subtasks

### Phase 0 — Kickoff & décisions produit (bloquant)

- [x] **Tâche 0.1** — Capturer la baseline tests : sur la VM, `cd /var/www/sambaedu-reload && php artisan test 2>&1 | tail -10`. Cible attendue : **1201 passed** (post-5.1d). Noter dans Dev Notes section Baseline.
- [x] **Tâche 0.2** — Décisions D1-D14 validées par Henri → reporter dans Dev Notes "Kickoff Décisions". Bloquant : D1, D2, D3, D5, D7 doivent être tranchées. Les autres ont une recommandation SM par défaut applicable.
- [x] **Tâche 0.3** — Vérifier sudoers VM (D7) : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 'sudo -u www-data sudo -n setfacl --version 2>&1 | head -3'`. Tester aussi `getfacl --version`. Si échec, escalader Henri pour ajustement sudoers AVANT toute exec.
- [x] **Tâche 0.4** — Inspecter `smb.conf` VM (confirmation D8) : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 'cat /etc/samba/smb.conf | grep -A5 "\[classes\]"'`. Vérifier que la section globale `[classes]` existe et pointe `/var/sambaedu/Classes`.
- [x] **Tâche 0.5** — Inspecter état initial `/var/sambaedu/Classes/` VM : `ssh ... 'ls -la /var/sambaedu/Classes/ 2>&1 | head -20 && find /var/sambaedu/Classes -maxdepth 2 -type d | head -10'`. Documenter dans Dev Notes l'état initial.
- [x] **Tâche 0.6** — Grep exhaustif baseline : `grep -rn 'AclService\|ShareService\|share.manage\|setfacl\|getfacl' app tests routes resources/views/pages docs --include='*.php' --include='*.blade.php' --include='*.md'` → documenter état initial dans Dev Notes (s'attendre à 0 hits côté `AclService/ShareService`, présence de `share.view`/`share.refresh` 7.2 + `SharePolicy`).
- [x] **Tâche 0.7** — Audit namespace `App\Services\Filesystem` : `ls app/Services/Filesystem/` → s'attendre à HomeDirService.php + XfsQuotaService.php uniquement. Confirmer qu'aucun fichier `AclService.php` ou `ShareService.php` ne pré-existe.

### Phase 1 — Permission Spatie + Policy `manage-share` (D2 + AC 9)

- [x] **Tâche 1.1** — Étendre `app/Enums/SambaPermission.php` : ajouter `case ShareManage = 'share.manage';` dans la section "Partages" (après `ShareRefresh`).
- [x] **Tâche 1.2** — Étendre la méthode `legacyRight()` du même enum : ajouter `self::ShareManage => LegacyRight::ShareRefresh,` (D2=A — partage le bit avec `ShareRefresh` legacy).
- [x] **Tâche 1.3** — Étendre `app/Policies/SharePolicy.php` : ajouter méthode publique `manage(?Authenticatable $user, ?Model $share = null): bool` retournant `$this->hasPermission($user, 'share.manage')`. Ajouter `'manage-share' => 'manage'` dans `$gates`. Mettre à jour le docblock pour refléter la nouvelle gate.
- [x] **Tâche 1.4** — Vérifier la migration de seeders Spatie (`database/seeders/SpatiePermissionSeeder.php` ou équivalent — chercher via `grep -rn 'share.view' database/seeders`) : s'assurer que `share.manage` est inclus dans le seeder principal (sinon ajouter). Pour tests : un seeder de tests dédié peut être nécessaire (`tests/Feature/SharePolicyTest.php` ajoute via `Permission::create`).
- [x] **Tâche 1.5** — Tester : `tests/Unit/Enums/SambaPermissionTest.php` (étendre s'il existe, sinon créer) avec `SambaPermissionTest::it_maps_share_manage_to_legacy_share_refresh`. Tester aussi que `SharePolicy::manage()` est correctement enregistré comme gate `manage-share` via `Gate::has('manage-share')`.

### Phase 2 — `AclService` (Volet bas niveau)

- [x] **Tâche 2.1** — Créer `app/Services/Filesystem/AclService.php` (∼250 lignes) :
  - Namespace `App\Services\Filesystem`
  - Property statique `protected static string $classesRoot = '/var/sambaedu/Classes'` overridable en tests (D13)
  - Méthode `validatePath(string $path): bool` avec regex `/^/var/sambaedu/Classes/[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+){0,3}$/` + check `realpath()` ne sort pas du préfixe (anti `..`).
  - Méthode publique `setAcls(string $path, array $acls, bool $recurse=true): bool` — `setfacl -b` puis batch `setfacl -m`. Décalque `partages.inc.php::set_acls` l. 27-43 mais avec `escapeshellarg` partout + Process facade Laravel.
  - Méthode publique `getFacl(string $path): array|false` — décalque `get_facl()` l. 87-118.
  - Méthode publique `addAcl(string $path, string $acl, bool $recurse=true): bool` — `setfacl -m`.
  - Méthode publique `removeAcl(string $path, string $acl, bool $recurse=true): bool` — `setfacl -x`.
  - Méthode publique `checkAcls(string $path, array $expectedAcls): bool` — décalque `check_acls()` l. 14-25.
  - Logs `AclService: <op> succès/échec` avec contexte path/acl/output.
- [x] **Tâche 2.2** — Tests Unit `tests/Unit/Services/Filesystem/AclServiceTest.php` (∼7 tests + DataProvider injection) avec `Process::fake()` :
  - `it_sets_acls_with_recurse_flag` (vérifier que `setfacl -R -b` puis `setfacl -R -m` sont appelés)
  - `it_gets_facl_and_parses_output` (mocker output `getfacl` → vérifier parsing array)
  - `it_adds_single_acl` / `it_removes_single_acl`
  - `it_validates_path_inside_classes_root` (path valide → true)
  - `it_rejects_paths_outside_classes_root` (DataProvider 8+ patterns malveillants : `..`, `; rm`, ` & `, backticks, traversal `/var/sambaedu/Classes/../etc`, etc.)
  - `it_logs_error_on_setfacl_failure` (Process retourne exit code != 0 → log error, retour false)
  - `it_supports_classes_root_override_in_tests` (utilise `static::$classesRoot = '/tmp/...'`)
- [x] **Tâche 2.3** — Documenter dans le docblock de `AclService` les sudoers requis (`sudo setfacl`, `sudo getfacl`) + référence à `partages.inc.php`.

### Phase 3 — `ShareService` (Volet métier)

- [x] **Tâche 3.1** — Créer `app/Services/Filesystem/ShareService.php` (∼400 lignes) :
  - DI constructeur : `public function __construct(private AclService $aclService) {}`
  - Property statique `protected static string $classesRoot = '/var/sambaedu/Classes'` (cohérent AclService).
  - Méthode privée `resolveClassPath(UserGroup $group): string` — retourne `/var/sambaedu/Classes/Classe_<name>` après validation (rejette si `$group->type !== 'classe'`).
  - Méthode privée `buildCanonicalAcls(string $classNameLower): array` — retourne le set ACL canonique racine classe (cf. investigation).
  - Méthode privée `buildEleveAcls(string $login, string $classNameLower): array` — retourne le set ACL élève.
  - Méthode privée `buildEchangeAcls(string $classNameLower, bool $active): array`
  - Méthode privée `buildTravailAcls(string $classNameLower): array` / `buildProfsAcls(string $classNameLower): array`
  - Méthode privée `escapeAclClassName(string $name): string` — applique `strtolower()` + `preg_replace(' ', '\\040', $name)` (cohérent legacy l. 344, **clé pour matching ACLs**).
  - **Méthode publique** `createClassShare(UserGroup $group): bool` — décalque `partages.inc.php::update_classes` l. 452-580 :
    1. valide `$group->type === 'classe'`
    2. `mkdir -p Classe_<name>` si absent
    3. apply ACLs racine via `$this->aclService->setAcls($classPath, $this->buildCanonicalAcls(...))`
    4. boucle sur `_travail`, `_profs`, `_echange` : mkdir + setAcls
    5. boucle sur `$group->users` (BelongsToMany) : mkdir `<eleve>` + setAcls élève + chown www-admin + chgrp domain admins
    6. audit log `target_type='share'`, `action='create'`
    7. retour `true` si tout OK, `false` si erreurs (compteur partiel acceptable selon D9=A)
  - **Méthode publique** `syncUserClassMemberships(User $user, array $oldClassIds=[], array $newClassIds=[]): bool` — décalque `cree_rep` l. 326-393 :
    1. pour chaque `$newClassId` ∉ $oldClassIds : ajouter user à la classe (mkdir <login> + setAcls)
    2. pour chaque `$oldClassId` ∉ $newClassIds : déplacer `Classe_<old>/<login>` → `Classe_<new>/<login>/Archives` (D3=A) ou supprimer ACL `user:<login>` du dossier de l'ancienne classe si pas de nouvelle classe
    3. audit log `action='sync_user'`
  - **Méthode publique** `toggleEchange(UserGroup $group, bool $active): bool` — décalque l. 475-493
  - **Méthode publique** `archiveClassShare(UserGroup $group): bool` — `mv Classe_<name> .Classe_<name>` (D4=A, méthode disponible mais pas auto-déclenchée)
  - Logs `ShareService: <op> succès/échec` avec contexte classname/path/operation.
- [x] **Tâche 3.2** — Tests Unit `tests/Unit/Services/Filesystem/ShareServiceTest.php` (∼9 tests) avec `Process::fake()` + factories `UserGroup::factory()->state(['type' => 'classe', 'name' => '6A'])` + `User::factory()` :
  - `it_creates_class_share_with_canonical_acls`
  - `it_creates_subdirs_travail_profs_echange`
  - `it_applies_eleve_acls_for_each_member`
  - `it_is_idempotent_on_recreate`
  - `it_syncs_user_class_memberships_on_class_change`
  - `it_archives_eleve_dir_when_changing_class`
  - `it_toggles_echange_acls`
  - `it_returns_false_on_setfacl_failure`
  - `it_rejects_non_classe_user_group`
  - `it_archives_class_share_via_mv`

### Phase 4 — Hook Observer pivot user_group_user (D5=A)

- [x] **Tâche 4.1** — Si D5=A retenu, créer `app/Observers/UserGroupUserPivotObserver.php` ou utiliser `Model::pivot` events :
  - **Stratégie technique** : Eloquent ne supporte pas nativement les events sur les rows pivot. Utiliser un **modèle pivot personnalisé** `App\Models\Pivot\UserGroupUserPivot extends Pivot` avec property `public $incrementing = false` + booted events `created`/`deleted` qui invoquent `ShareService::syncUserClassMemberships`. Adapter la relation `User::groups()` et `UserGroup::users()` pour utiliser `->using(UserGroupUserPivot::class)`.
  - **Alternative simple** (recommandation SM si modèle pivot custom semble overkill) : ajouter une méthode `User::syncClassesAndShares(array $classIds)` qui appelle `$this->groups()->sync(...)` PUIS `app(ShareService::class)->syncUserClassMemberships(...)`. Utilisée explicitement par `UserService::modifyUser` (post Story 2.2 verifiée). Cohérent avec D5=B fallback.
  - **Décision dev** : à arbitrer pendant l'implémentation Phase 4. Préférer la stratégie qui passe les tests les plus propres (modèle pivot custom = robuste mais complexe ; appel explicite = plus simple, demande call site hygiene).
- [x] **Tâche 4.2** — Modifier `app/Services/UserService.php` au call site `syncWithoutDetaching` (l. 1414) + équivalents dans `modifyUser` : ajouter call à `ShareService::syncUserClassMemberships(...)` après l'opération sync. Cf. D5.
- [x] **Tâche 4.3** — Tests Feature `tests/Feature/Observers/UserGroupUserPivotObserverTest.php` (ou `UserServiceShareSyncTest.php` selon stratégie) :
  - `it_triggers_sync_on_attach_classe_pivot`
  - `it_does_not_trigger_for_non_classe_groups` (attach `type='cours'` → no call)
  - `it_handles_failure_silently` (ShareService throw → log + continue)

### Phase 5 — UI Livewire SFC `class-share-section` (page groupe)

- [x] **Tâche 5.1** — Créer `resources/views/pages/users/groups/[id]/_partials/class-share-section.blade.php` (Livewire SFC, ∼200 lignes) :
  - `@php use ... Livewire\Volt\Component; ...` SFC pattern
  - Public property `UserGroup $group`
  - State props : `bool $shareExists`, `array $aclSummary`, `bool $echangeActive`, `bool $isLoading`
  - Mount : si `$group->type !== 'classe'` → ne rien afficher (return `''`)
  - Computed : lire l'état FS via `ShareService::getStatus($group)` (méthode helper à ajouter Phase 3 si nécessaire) + `AclService::getFacl()`
  - Méthodes Livewire : `createShare()`, `reapplyAcls()`, `toggleEchange()`, `refresh()`. Toutes commencent par `Gate::authorize('manage-share', $this->group)` (sauf `refresh` qui requiert `share.view`).
  - UI Tailwind/DaisyUI : card "Partage de classe" avec 3 sections (état, sous-dirs résumé, actions). Toasts `WithToasts`. Spinners `wire:loading`.
- [x] **Tâche 5.2** — Inclure le partial dans `resources/views/pages/users/groups/[id]/index.blade.php` : ajouter `@include('pages.users.groups.[id]._partials.class-share-section')` ou `<livewire:pages::users.groups.[id]._partials.class-share-section :group="$group" />` selon convention. **Position** : entre `members-list` et `group-quota-section` (ordre : header → membres → partage → quota → wallpaper → app-customization). N'afficher que si `$group->type === 'classe'`.
- [x] **Tâche 5.3** — Tests Feature `tests/Feature/Livewire/ClassShareSectionTest.php` (∼5 tests) :
  - `it_renders_section_for_classe_type_only`
  - `it_renders_facl_summary_via_acl_service`
  - `it_blocks_create_without_manage_permission` (forge payload)
  - `it_invokes_create_share_on_button_click`
  - `it_shows_readonly_for_view_only_permission`
  - `it_toggles_echange_via_button`

### Phase 6 — Commande Artisan `shares:resync-class` (D5=D, AC 11)

- [x] **Tâche 6.1** — Créer `app/Console/Commands/SharesResyncClassCommand.php` (∼150 lignes) :
  - Signature : `'shares:resync-class {--classe= : Nom de classe spécifique (sinon toutes)} {--dry-run : Preview sans modif}'`
  - DI constructeur : `public function __construct(private ShareService $shareService) { parent::__construct(); }`
  - `handle()` :
    1. Si `--classe` : récupérer `UserGroup::where('type', 'classe')->where('name', option)->first()`. Si absent → FAILURE.
    2. Sinon : `UserGroup::where('type', 'classe')->get()`.
    3. Pour chaque classe : `$this->shareService->createClassShare($group)` (idempotent, AC 3).
    4. Compteurs : succès / échecs / skip (si `--dry-run`).
    5. Rapport stdout final + retour SUCCESS/FAILURE selon fail-soft pattern 5.1d.
- [x] **Tâche 6.2** — Tests Feature `tests/Feature/Console/SharesResyncClassCommandTest.php` (∼3 tests) :
  - `it_resyncs_one_class_when_filter_provided`
  - `it_resyncs_all_classes_when_no_filter`
  - `it_supports_dry_run`

### Phase 7 — Documentation QA append-only

- [x] **Tâche 7.1** — Append section "Story 5.2 — Partages classe + ACLs POSIX" à `docs/qa/domains/filesystem.md` avec **8 scénarios numérotés stables 5.2-1 à 5.2-8** (cf. AC 15 pour la liste).
- [x] **Tâche 7.2** — Append section "Story 5.2" à `docs/domains/filesystem.md` (overview développeur, **PAS de duplication QA**) : décrire `AclService` + `ShareService` + Observer (D5) + smb.conf strategy (D8) + sudoers requis (D7) + path FS structure.
- [x] **Tâche 7.3** — **PAS** de fichier `5-2-e2e-manual.md` séparé (interdit par convention 5.1c).

### Phase 8 — Validation finale + smoke VM

- [x] **Tâche 8.1** — Run suite complète VM : `ssh ... 'cd /var/www/sambaedu-reload && php artisan test 2>&1 | tail -20'`. Cible : ≥1219 passed (1201 baseline + 18 nouveaux).
- [x] **Tâche 8.2** — Smoke VM `shares:resync-class --dry-run` : `ssh ... 'cd /var/www/sambaedu-reload && php artisan shares:resync-class --dry-run 2>&1 | head -30'`. Vérifier qu'une liste de classes apparaît sans erreur.
- [x] **Tâche 8.3** — Smoke VM création partage : choisir une classe test, vérifier la création d'un partage `/var/sambaedu/Classes/Classe_<name>/` avec sous-dirs et ACLs (`getfacl /var/sambaedu/Classes/Classe_<name>/`).
- [x] **Tâche 8.4** — Smoke UI : `https://<vm>/app/users/groups/<id>` — vérifier l'apparition de la section "Partage de classe" et l'état affiché.
- [x] **Tâche 8.5** — Grep finaux :
  - `grep -rn 'AclService\|ShareService' app tests routes resources` → s'attendre à hits cohérents avec File List
  - `grep -rn 'smb.conf\|smbcontrol\|testparm' app/Services/Filesystem` → 0 hit (AC 18)
  - `grep -rn 'rm -rf' app/Services/Filesystem/AclService.php app/Services/Filesystem/ShareService.php` → 0 hit (sécurité — la méthode `archiveClassShare` utilise `mv` pas `rm`)

---

## File List prévisionnel

### Créés (∼12 fichiers)

| Fichier | Type | Lignes ~ | Phase |
|---|---|---|---|
| `app/Services/Filesystem/AclService.php` | Service | 250 | 2 |
| `app/Services/Filesystem/ShareService.php` | Service | 400 | 3 |
| `app/Console/Commands/SharesResyncClassCommand.php` | Command | 150 | 6 |
| `app/Observers/UserGroupUserPivotObserver.php` (si D5=A pivot pattern) OU `app/Models/Pivot/UserGroupUserPivot.php` | Observer/Model | 80 | 4 |
| `resources/views/pages/users/groups/[id]/_partials/class-share-section.blade.php` | Livewire SFC | 200 | 5 |
| `tests/Unit/Services/Filesystem/AclServiceTest.php` | Test Unit | 220 | 2 |
| `tests/Unit/Services/Filesystem/ShareServiceTest.php` | Test Unit | 280 | 3 |
| `tests/Feature/Console/SharesResyncClassCommandTest.php` | Test Feature | 100 | 6 |
| `tests/Feature/Livewire/ClassShareSectionTest.php` | Test Feature | 180 | 5 |
| `tests/Feature/Observers/UserGroupUserPivotObserverTest.php` (si D5=A) | Test Feature | 80 | 4 |
| `tests/Unit/Enums/SambaPermissionShareManageTest.php` (étendre existant ou créer) | Test Unit | 30 | 1 |

### Modifiés (∼6-8 fichiers)

| Fichier | Modif | Phase |
|---|---|---|
| `app/Enums/SambaPermission.php` | +`ShareManage` case + mapping `legacyRight()` | 1 |
| `app/Policies/SharePolicy.php` | +méthode `manage()` + entrée `$gates` | 1 |
| `app/Services/UserService.php` | +call `ShareService::syncUserClassMemberships` post `syncWithoutDetaching` (D5=B ou A+D) | 4 |
| `resources/views/pages/users/groups/[id]/index.blade.php` | +inclusion `<livewire:...:class-share-section>` conditionnelle | 5 |
| `app/Models/User.php` (si D5=A pivot custom) | +`->using(UserGroupUserPivot::class)` sur relation `groups()` | 4 |
| `app/Models/UserGroup.php` (si D5=A pivot custom) | +`->using(UserGroupUserPivot::class)` sur relation `users()` | 4 |
| `database/seeders/SpatiePermissionSeeder.php` (ou équivalent) | +`Permission::create('share.manage')` | 1 |
| `docs/domains/filesystem.md` | +section "Story 5.2" (append-only) | 7 |
| `docs/qa/domains/filesystem.md` | +section "Story 5.2" (append-only, 8 scénarios) | 7 |

### Supprimés

Aucun. La story est purement additive (le legacy `partages/`, `dossier_echange/`, `acls/` restent intouchés — ils seront purgés dans une story ultérieure de cleanup legacy une fois la confiance acquise).

---

## Dépendances

- **Amont (toutes prêtes)** :
  - **5.1a ✅ done** — pattern `Services/Filesystem` + DI + regex anti-injection.
  - **5.1c ✅ done** — `SystemSetting`, page `/admin/settings`, pattern Livewire SFC `_partials/`.
  - **5.1d ⚠️ review** — non bloquant (pattern commande Artisan + audit + sudoers `sudo rm` éprouvé). Si review identifie des regressions critiques, freeze 5.2 jusqu'à correction.
  - **7.2 ✅ done** — `SharePolicy` + permissions Spatie `share.view`/`share.refresh` (5.2 ajoute `share.manage`).
  - **2.2 ✅ done** — `UserService::modifyUser` synchronise les groupes Eloquent (call site Phase 4).

- **Aval** :
  - Aucune story Epic 5 après 5.2 (Epic 5 → done après 5.2 + retro).
  - Future story de cleanup legacy `partages/` `dossier_echange/` `acls/` (hors-Epic).

---

## Sécurité & Risques

| Risque | Probabilité | Impact | Mitigation |
|---|---|---|---|
| **Path injection via `UserGroup::name` malveillant** | Moyenne | CATASTROPHIQUE (création/suppression hors `/var/sambaedu/Classes/`) | Triple garde : (1) `AclService::validatePath()` regex anti-traversal `/^/var/sambaedu/Classes/[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+){0,3}$/` + `realpath` check, (2) `ShareService::resolveClassPath()` enforce préfixe, (3) `escapeshellarg` partout. Tests anti-injection AC 12 (DataProvider 8+ patterns). |
| **`setfacl` échoue après `mkdir` (atomicité)** | Moyenne | Dossier sans ACLs → fuite data potentielle | D9=A : pas de rollback, log error explicite + bouton UI "Réappliquer ACLs" idempotent (AC 6). Couverture AC 10. |
| **Sudoers VM rejette `sudo setfacl`** (D7) | Faible | Toutes les opérations 5.2 échouent | Validation kickoff Tâche 0.3. Documenter dans `docs/qa/domains/filesystem.md` + escalader Henri si manquant. |
| **Race condition Observer pivot vs UserService::modifyUser** (D5=A) | Faible | Double sync ACL même classe | Idempotence garantie par `setAcls -b + setfacl --set` — re-sync = même résultat. ✅ |
| **Bulk import LDAP déclenche 10000+ setfacl synchrones** | Moyenne | Latence import 10+ minutes | D5=A+D : Observer synchrone unitaire, mais bulk import doit utiliser `shares:resync-class` final pour batch optimisé OU dispatcher Job async (D5=C complément). À documenter post-livraison. |
| **`shares:resync-class --classe=*` long sur grosse infra (50 classes × 30 élèves = 1500 dossiers)** | Faible | Cron dépasse fenêtre maintenance | Smoke test VM Phase 8 mesure le temps réel. Si > 5min, ajouter `--parallel` ou batcher en future story. |
| **Bypass Gate Livewire forgé** | Faible | Accès non-autorisé création/sync | Double guard UI `@can('manage-share', $group)` + serveur `Gate::authorize` première ligne (cohérent 5.1c). Tests AC 8 explicite. |
| **Conflits ACL avec data legacy non-canonique** | Moyenne | Bouton "Réappliquer" écrase des ACLs custom admin | D9=A : c'est le comportement attendu (le set canonique prime). Documenter dans QA + bouton UI affiche un confirm dialog "Cette action écrase toute personnalisation ACL manuelle". |
| **`UserGroup::name` change après création partage** | Très faible | Partage `Classe_<oldname>` orphelin | Pas géré en 5.2 (rare en pratique). Future story : Observer `UserGroup::updating` qui rename le dossier FS. À documenter dans risk register `docs/`. |
| **smb.conf reload nécessaire après création partage ?** | Très faible | Sous-dossier non visible aux clients SMB | D8=A : la section globale `[classes]` du `smb.conf` couvre tous les sous-dossiers automatiquement (legacy testé en prod). Pas de reload nécessaire. AC 18 explicite. |
| **`getFacl` parsing fragile (output dépend de la version setfacl/getfacl)** | Faible | UI affiche état partage incorrect | Décalque legacy `get_facl()` testé en prod. Tests Process::fake avec output réel capturé sur VM 2026-04-30. |
| **Audit log `quota_audit_logs` saturée** | Faible | Table grossit lentement | ≤50 ops/jour en moyenne SER. Acceptable plusieurs années. Si problème, ajouter purge `audit:prune` future story. |

---

## Permissions & Gates — rappel

- **Permission `share.view`** (existant 7.2) : lecture seule de l'état partage (AC 17).
- **Permission `share.refresh`** (existant 7.2) : déclenchement d'un refresh manuel (legacy `SE_SHARE_REFRESH`). 5.2 garde l'usage mais privilégie `share.manage`.
- **Permission `share.manage`** (NOUVELLE 5.2 — D2=A) : création, modification ACLs, toggle échange, archivage classe. Mapping `legacyRight()` = `LegacyRight::ShareRefresh`.
- **Gate `manage-share`** (NOUVELLE) : enregistrée dans `SharePolicy::$gates`. Utilisée par UI Livewire + commandes (mais pas par les CLI Artisan qui restent shell-only).
- **Convention** : `Gate::authorize('manage-share', $group)` première ligne des méthodes Livewire publiques. Pas de bypass possible.

---

## Testing Strategy

**Stratégie : Unit + Feature + non-régression stricte 5.1a/b/c/d + 7.2.**

- **Baseline** : ≥ 1201 tests verts (post-5.1d 2026-04-29). Cible 5.2 : **+18 nouveaux tests minimum**, **0 régression**.
- **Tests Unit `AclServiceTest`** : `Process::fake()` (Laravel 9+ Process facade) pour mocker `setfacl`/`getfacl`. Pattern :
  ```php
  Process::fake([
      'sudo setfacl*' => Process::result(output: '', exitCode: 0),
      'sudo getfacl*' => Process::result(output: "user::rwx\ngroup::---\n...", exitCode: 0),
  ]);
  // ... act + assert
  Process::assertRan(fn ($p) => str_contains($p->command(), 'setfacl -R -b'));
  ```
- **Tests Unit `ShareServiceTest`** : `RefreshDatabase` + factories `UserGroup`/`User` + `Process::fake()`. Stratégie : tester chaque méthode publique isolément. Mocker `AclService` via DI test container : `$this->app->bind(AclService::class, fn () => Mockery::mock(AclService::class)->shouldReceive('setAcls')->...)`.
- **Tests Feature `ClassShareSectionTest`** : Livewire test helper `Livewire::test(ClassShareSection::class, ['group' => $group])`. Tests Gate via `actingAs($user)` + `$user->givePermissionTo('share.manage')` (Spatie). Bypass test : `Livewire::test(...)->call('createShare')->assertForbidden()` quand pas de permission.
- **Tests Feature `SharesResyncClassCommandTest`** : `$this->artisan('shares:resync-class', ['--classe' => '6A'])->assertSuccessful()` + assertions sur les compteurs stdout via `expectsOutputToContain`.
- **Tests Feature `UserGroupUserPivotObserverTest`** (D5=A) : créer User + UserGroup type='classe' + `$user->groups()->attach($group->id)` → assertions sur les calls `ShareService::syncUserClassMemberships` mockés.
- **Path override en tests** : `AclService::$classesRoot = sys_get_temp_dir() . '/test-classes-' . uniqid();` dans `setUp()` (cohérent pattern `TrashPurgeCommand::$trashDir` 5.1d). Cleanup `tearDown()`.
- **Migration tests** : aucune migration nouvelle (5.2 n'ajoute pas de table — D1=A retenu). Si D1=B → ajouter migration `shares` table + tester via factory.

---

## Doc

### `docs/domains/filesystem.md` — section append-only "Story 5.2"

Décrire (∼100 lignes) :
- Architecture `AclService` + `ShareService` + DI
- Schéma ACLs canonique (racine classe / sous-dirs / élève) avec exemple concret
- Convention naming `Classe_<name>` + `escapeAclClassName` (lowercase + `\040` espace)
- Hook changement de classe (D5 retenu) + comportement
- Sudoers VM requis (D7)
- `smb.conf` strategy (D8=A — non touché)
- Convention archives élève changement classe (D3)
- Commande `shares:resync-class` (usage + flags)

### `docs/qa/domains/filesystem.md` — section append-only "Story 5.2"

8 scénarios numérotés stables (cf. AC 15) :
- 5.2-1 : Création partage classe nominal (avec membres) — Given/When/Then + commandes shell de vérification
- 5.2-2 : Réapplication ACLs idempotente
- 5.2-3 : Toggle dossier échange on/off
- 5.2-4 : Changement de classe d'un élève (sync)
- 5.2-5 : Suppression de classe (archive `mv` vers `.Classe_<x>`)
- 5.2-6 : Bypass Gate manage-share via payload forgé → 403
- 5.2-7 : Anti-injection path (UserGroup name avec `..`)
- 5.2-8 : Commande `shares:resync-class --classe=X` smoke VM

**PAS de fichier `5-2-e2e-manual.md` séparé** (convention 5.1c append-only par domaine — `docs/qa/README.md:14-17`).

---

## [PROD] Manuel — à appliquer sur la VM après merge

1. **Sudoers VM (D7)** — vérifier que `/etc/sudoers.d/sambaedu` (ou équivalent) contient :
   ```
   www-data ALL=(root) NOPASSWD: /usr/bin/setfacl, /usr/bin/getfacl, /bin/mkdir, /bin/mv, /bin/chown, /bin/chgrp
   ```
   Si manquant, ajouter via `sudo visudo -f /etc/sudoers.d/sambaedu` puis `sudo systemctl reload sudo` (ou redémarrer la session www-data).

2. **smb.conf** — **AUCUNE modification requise**. Vérifier que la section globale `[classes]` est présente (l'install legacy l'avait déjà) :
   ```bash
   grep -A6 '^\[classes\]' /etc/samba/smb.conf
   ```
   Sortie attendue : `path = /var/sambaedu/Classes`.

3. **Migration BDD** — Aucune migration nouvelle 5.2 (D1=A retenu). Si D1=B retenu : `php artisan migrate` après merge.

4. **Seeder permission** — `php artisan db:seed --class=SpatiePermissionSeeder` pour ajouter `share.manage` aux permissions existantes (idempotent).

5. **Smoke test VM** :
   ```bash
   php artisan shares:resync-class --dry-run | head -30
   # Vérifier la liste des classes détectées
   php artisan shares:resync-class --classe=<une_classe_test>
   getfacl /var/sambaedu/Classes/Classe_<une_classe_test>/
   # Vérifier les ACLs canoniques
   ls -la /var/sambaedu/Classes/Classe_<une_classe_test>/
   # Vérifier _travail/_profs/_echange + dossiers élèves
   ```

6. **Smoke UI** — naviguer vers `/app/users/groups/<id_classe>` et vérifier l'apparition de la section "Partage de classe" + boutons fonctionnels (réservés admins ayant `share.manage`).

7. **Rollback plan** — si problème détecté en prod :
   - **Données préservées** : aucune commande 5.2 ne supprime de data (sauf `archiveClassShare` qui fait `mv`, réversible). Le bouton "Réappliquer les ACLs" est idempotent → safe à abuser.
   - **Désactivation rapide** : retirer `share.manage` de tous les utilisateurs Spatie via `php artisan tinker` + `User::all()->each(fn ($u) => $u->revokePermissionTo('share.manage'))`. Les boutons UI deviennent grisés. Le filesystem reste en place.

---

## References

- [Source: epics.md#Story-5.2:1719-1748](../planning-artifacts/epics.md) — scope + AC originaux.
- [Source: epics.md#1bis.13b-acls:794](../planning-artifacts/epics.md) — module legacy `acls` CANCELLED + defer Epic 5.
- [Source: epics.md#1bis.14-partages:829](../planning-artifacts/epics.md) — module legacy `partages` PAUSED + refonte Epic 5.
- [Source: architecture.md#Filesystem:485](../planning-artifacts/architecture.md) — `pages/filesystem/` planifié (mais 5.2 garde la gestion sur page groupe — pas de page filesystem dédiée).
- [Source: architecture.md#Services:505](../planning-artifacts/architecture.md) — appels système via Services obligatoire.
- [Source: 5-1a-refactor-services-filesystem.md](5-1a-refactor-services-filesystem.md) — pattern Service + DI à imiter.
- [Source: 5-1c-quotas-groupes-settings-flash-over-quota.md](5-1c-quotas-groupes-settings-flash-over-quota.md) — pattern Livewire SFC `_partials/` + double guard Gate.
- [Source: 5-1d-gaps-produits-itinerant-purge-seed.md](5-1d-gaps-produits-itinerant-purge-seed.md) — pattern commande Artisan + audit + property statique override en tests.
- [Source: app/Services/Filesystem/HomeDirService.php:34](../../app/Services/Filesystem/HomeDirService.php) — regex anti-injection à dupliquer.
- [Source: app/Services/Filesystem/XfsQuotaService.php](../../app/Services/Filesystem/XfsQuotaService.php) — préfixe log à adapter.
- [Source: app/Models/UserGroup.php](../../app/Models/UserGroup.php) — modèle, scope `byType('classe')`.
- [Source: app/Models/User.php](../../app/Models/User.php) — relation `groups()` BelongsToMany pivot.
- [Source: app/Policies/SharePolicy.php](../../app/Policies/SharePolicy.php) — Policy à étendre.
- [Source: app/Enums/SambaPermission.php:21](../../app/Enums/SambaPermission.php) — enum à étendre.
- [Source: app/Services/UserGroupService.php:559](../../app/Services/UserGroupService.php) — naming `Classe_<rawName>`.
- [Source: app/Services/UserService.php:1414](../../app/Services/UserService.php) — call site `syncWithoutDetaching` → hook 5.2.
- [Source: sambaedu/includes/partages.inc.php:14-580](../../sambaedu/includes/partages.inc.php) — investigation legacy ACL + create class share + sync user.
- [Source: sambaedu/dossier_echange/dossier_echange.php](../../sambaedu/dossier_echange/dossier_echange.php) — toggle échange legacy.
- [Source: sambaedu/partages/rep_classes.php](../../sambaedu/partages/rep_classes.php) — UI legacy refresh classes (référence métier).
- [Source: docs/qa/README.md:14-17](../../docs/qa/README.md) — convention QA append-only par domaine.
- [Source: docs/qa/domains/filesystem.md](../../docs/qa/domains/filesystem.md) — fichier QA à enrichir.
- [Source: CLAUDE.md](../../CLAUDE.md) — conventions routing + Livewire SFC + interdiction `rm -rf`.
- VM 2026-04-30 inspect `/etc/samba/smb.conf` confirme section `[classes]` globale (`path=/var/sambaedu/Classes`, `read only=no`, `create mask=0777`).

---

## Recommandation Modèle Dev

### Choix : **opus** (claude-opus-4-7)

### Justification

5.2 cumule **multi-couches simultanées** + **sécurité critique sudo** + **orchestration cross-domaine** + **investigation legacy non triviale** + **14 décisions produit** :

1. **Sécurité critique `setfacl` + `mkdir`** — toute erreur de path concat / regex anti-injection = création ou suppression hors `/var/sambaedu/Classes/`. Le moindre bug catastrophique. Triple garde requise (regex path validate, escapeshellarg, préfixe Service). Le dev doit raisonner défensivement sur 8+ patterns d'injection. Opus excelle sur cette analyse paranoïaque.

2. **Orchestration multi-couches** — AclService bas niveau + ShareService métier + Observer pivot Eloquent (D5=A patterns peu courants dans Laravel) + Commande Artisan + UI Livewire. 5 couches qui doivent communiquer correctement. Pattern modèle pivot custom (Phase 4.1) est subtil — Opus garde mieux le contexte cross-fichier.

3. **Décalque 1:1 du legacy `partages.inc.php`** (673 lignes) — le set d'ACLs canonique est complexe (5 sets différents : racine classe, _travail, _profs, _echange on/off, élève). Chaque set a 12-14 entrées ACL avec default ACLs + escape `\040` espaces + lowercase. Reproduire fidèlement sans dérive demande de la rigueur. Opus mieux outillé pour la traçabilité cross-source.

4. **Gestion changement de classe (Story 2.2 hook)** — D5 a 4 options avec trade-offs (Observer pivot custom, appel explicite UserService, Job async, Commande Artisan). La combinaison A+D recommandée demande d'arbitrer pendant l'implémentation. Logique d'archives (D3 — déplacer `<eleve>/` vers `<new>/Archives`) à reproduire fidèlement du legacy. Opus raisonne mieux sur les chemins critiques.

5. **14 décisions produit à trancher** au kickoff (D1-D14). Volume comparable à 5.1c (12 décisions) + 5.1d (9). Chaque décision a des ramifications cross-fichiers (ex: D1 table vs FS impacte 6+ fichiers, D5 hook impacte UserService + Observer + tests). Opus mieux outillé pour le suivi.

6. **18+ nouveaux tests sur 6+ fichiers** — AclServiceTest, ShareServiceTest, ClassShareSectionTest, SharesResyncClassCommandTest, UserGroupUserPivotObserverTest, SambaPermissionTest étendu. Setup différents (Process::fake, Mockery AclService, factories pivot, RefreshDatabase, actingAs + Spatie permission). Coordination orchestration test = opus.

7. **Permissions Spatie + Policy** — étendre SambaPermission enum + SharePolicy + seeder + tests Gate dans 4-5 fichiers. Mapping legacy `share.manage → ShareRefresh` (D2=A) demande de comprendre la matrice 7.x sans la casser. Opus garde mieux la cohérence transverse.

8. **Documentation cross-références** — `partages.inc.php` legacy est référencé ligne par ligne dans le contexte. La story doit documenter précisément les correspondances (l. 27-43 → AclService::setAcls, etc.). Opus suit mieux les Sources.

9. **Non-régression stricte** sur 1201 tests + non-modif HomeDirService/XfsQuotaService/User/UserGroup (sauf `->using(Pivot)` si D5=A pivot custom). Multi-ajustements coordonnés requis. Opus orchestre mieux les diffs.

10. **Performance bulk** — risque latence `setfacl` × 10000 calls en cas d'import LDAP massif. Le dev doit anticiper la mitigation (commande batch, queue Job, caching getFacl). Trade-offs subtils — opus.

**Alternative sonnet envisageable si** Henri accepte de découper en 3 PR séparées :
- PR1 = AclService + permission/policy (sonnet faisable, scope court)
- PR2 = ShareService + UI (sonnet faisable mais sécurité demande relecture serrée)
- PR3 = Observer pivot + commande Artisan (opus à cause complexité Eloquent pivot)

Plus lourd opérationnellement (3 cycles dev/review). **Opus en une passe est plus simple, plus sûr, plus prévisible — recommandation forte.**

Modèle recommandé final : **`opus`** (claude-opus-4-7).

---

## Dev Agent Record

### Agent Model Used

`claude-opus-4-7 (1M context)`

### Debug Log References

- **Baseline tests local (host, worktree non syncé VM)** :
  - Suite Unit globale : 807 tests, 24 errors, 2 failures, 35 skipped, 1
    incomplete, 16 risky — erreurs préexistantes liées à des extensions
    PHP non disponibles côté host (`ext-ldap`, `ext-gd`/`imagick`,
    `ext-zip`) sur les fichiers `LdapConnectionTest`, `LdapShimTest`,
    `FileManagerServiceTest`, `WallpaperComposerTest`. Aucune introduite
    par 5.2.
  - Suite Feature globale **avant** Story 5.2 (git stash) : 653 tests,
    **22 errors, 3 failures**, 63 skipped, 11 risky.
  - Suite Feature globale **après** Story 5.2 : 653 tests, **14 errors,
    3 failures**, 63 skipped, 9 risky. 5.2 résout par effet de bord 8
    erreurs (probablement liées à la table `quota_audit_logs` désormais
    créée par `CreatesPermissionSchema`). **0 régression introduite.**
  - Suite **Story 5.2 isolée** (6 fichiers) : **72/72 verts** (50 unit
    AclServiceTest + ShareServiceTest + SambaPermissionShareManageTest,
    14 feature ClassShareSectionTest + UserGroupUserPivotObserverTest,
    8 feature SharesResyncClassCommandTest). Cible AC 13
    (≥ 18 nouveaux tests) largement dépassée.

- **Smoke tests VM** : non exécutés (règle CLAUDE.md worktree —
  interdiction d'interaction avec la VM depuis le worktree git). À
  exécuter par Henri post-merge selon le runbook
  `docs/qa/domains/filesystem.md` scénarios 5.2-1 à 5.2-8.

- **Grep finaux** :
  - `grep -rn 'AclService\|ShareService' app tests routes resources` →
    hits cohérents avec le File List (services + tests + commande +
    Livewire SFC + Observer).
  - `grep -rn 'smb.conf\|smbcontrol\|testparm' app/Services/Filesystem`
    → 0 hit (AC 18 ✅).
  - `grep -rn 'rm -rf' app/Services/Filesystem/AclService.php` → 0 hit.
  - `grep -rn 'rm -rf' app/Services/Filesystem/ShareService.php` → 1 hit
    dans `removeDirectory()`, garde stricte (validatePath + profondeur
    ≥ 2 sous classesRoot + escapeshellarg) — utilisé uniquement pour
    purger un ancien dossier élève dont l'archive cible existe déjà
    (cohérent legacy `cree_rep` l. 354).

### Completion Notes List

- **Volet permission/policy/seeder** — `SambaPermission::ShareManage`
  ajouté avec mapping legacy `LegacyRight::ShareRefresh` (D2=A) +
  `isSecondaryBitPermission` (exclu du bitmask import pour ne pas
  sur-élargir les profils LDAP custom). `SharePolicy::manage(?Auth, ?UserGroup)`
  + gate `manage-share` enregistré. Rôles seedés impactés : `ShareAdmin`
  + `UserAdmin` (via `SambaRole::permissionNames()` — `PermissionSeeder`
  reste idempotent : `firstOrCreate` + `syncPermissions` uniquement à la
  création initiale).
- **Volet AclService** — bas niveau encapsulant `setfacl`/`getfacl` via
  la facade Process Laravel. Triple garde anti-injection (regex
  `validatePath` + `escapeshellarg` + sudo whitelist). 14 patterns
  malicieux testés via `#[DataProvider]`. Décalque fidèle des fonctions
  `partages.inc.php::set_acls/add_acl/remove_acl/check_acls/get_facl`.
- **Volet ShareService** — métier orchestrant `createClassShare` +
  `syncUserClassMemberships` + `toggleEchange` + `archiveClassShare` +
  `getStatus` (lecture cachable pour UI). DI sur `AclService`.
  `Cache::lock('shares:resync:'.$id, 60)` per-class anti-race vs
  Observer. Audit `quota_audit_logs` `target_type='share'` (D10=A) avec
  5 actions (`create_share`/`sync_user`/`toggle_echange`/`archive_share`/`resync_class`).
  ACL builders canoniques décalqués 1:1 sur partages.inc.php l. 372-580
  (lowercase + `\\040` sur les espaces des noms de groupe ACL,
  `domain\\040admins` pour le groupe d'admin).
- **Volet Observer pivot D5=A** — modèle pivot custom
  `App\Models\Pivot\UserGroupUserPivot` étendant `Pivot`, configuré sur
  `user_group_user`. `User::groups()` et `UserGroup::users()` ajoutent
  `->using(UserGroupUserPivot::class)` pour qu'Eloquent dispatche les
  events `created`/`deleted`. L'Observer filtre `$group->type==='classe'`
  et délègue à `ShareService::syncUserClassMemberships()`. Désactivable
  globalement (`UserGroupUserPivotObserver::disableSync()`) pour les
  tests/imports massifs (cohérent pattern `UserGroupObserver`). Pattern
  testé via `$user->groups()->attach()` / `detach()` — aucun fallback
  D5=B nécessaire.
- **Volet commande Artisan D5=D** — `shares:resync-class
  {--class=<name>} {--dry-run} {--performed-by=<who>}`. Filet de
  sécurité bulk (pattern legacy "Mise à jour des classes"). Validation
  regex sur `--class` (anti-injection) et `--performed-by` (anti log
  poisoning). Audit consolidé `action='resync_class'`. Lock per-class
  avant invocation `ShareService::createClassShare` ; relâchement
  explicite avant le call interne pour éviter le verrou imbriqué qui
  ferait warning+return false (le ShareService prend lui-même le même
  lock).
- **Review fixes (2026-04-30)** — 13 corrections appliquées (3 bloquants
  #1/#3 + 10 améliorations) suite à la review Sonnet/Opus. Décisions
  Henri tranchées sur Q1 (#1 Hook D5/D3 = Option B + Observer disabled
  pendant call explicit), Q2 (#11 archive existante = log warning +
  décalque legacy), Q3 (#2 lock+cron = exit code 2). +8 nouveaux tests
  (1477 tests globaux, 0 régression). Faux positifs Opus #5/#10 confirmés
  non corrigés ; #14 hors scope. Détail dans la section *Review Fixes*
  ci-dessous + `_bmad-output/codeReviews/5-2.md`.
- **Volet UI Livewire SFC** — `class-share-section.blade.php` (SFC
  anonyme `new class extends Component`) avec double guard
  (`@can('manage-share')` UI + `Gate::authorize('manage-share', $group)`
  serveur). Visible uniquement si `type === 'classe'`. Lecture FS
  cachée 60s (`Cache::remember('share-status:'.$id, 60, getStatus)`) +
  `bustCache()` après chaque action. Toasts génériques via `WithToasts`
  (pas `$e->getMessage()` — leçon 5.1b #4). Inclusion conditionnelle
  dans `pages/users/groups/[id]/index.blade.php` entre `members-list` et
  `group-quota-section`. Bouton "Refresh" sans `manage-share` (lecture
  seule via `share.view`). Note dev : pendant le test
  `it_shows_readonly_for_view_only_permission`, un faux positif sur
  `assertDontSee('Créer le partage')` a forcé la reformulation du texte
  d'aide pour ne plus contenir littéralement la chaîne du bouton
  (encadrement `@can('manage-share')` autour du conseil "Cliquez sur le
  bouton ci-contre…").

### File List

#### Créés (12 fichiers)

- `app/Services/Filesystem/AclService.php` — service bas niveau setfacl/getfacl (357 lignes).
- `app/Services/Filesystem/ShareService.php` — service métier partages classe (781 lignes).
- `app/Console/Commands/SharesResyncClassCommand.php` — commande Artisan filet bulk D5=D.
- `app/Models/Pivot/UserGroupUserPivot.php` — modèle pivot custom pour events Eloquent.
- `app/Observers/UserGroupUserPivotObserver.php` — Observer hook D5=A.
- `resources/views/pages/users/groups/[id]/_partials/class-share-section.blade.php` — Livewire SFC UI.
- `tests/Unit/Services/Filesystem/AclServiceTest.php` — 17 tests Unit (incl. DataProvider 14 patterns).
- `tests/Unit/Services/Filesystem/ShareServiceTest.php` — 22 tests Unit ShareService.
- `tests/Unit/Enums/SambaPermissionShareManageTest.php` — 4 tests mapping `share.manage`.
- `tests/Feature/Livewire/Users/ClassShareSectionTest.php` — 10 tests Feature SFC.
- `tests/Feature/Observers/UserGroupUserPivotObserverTest.php` — 4 tests Feature Observer pivot.
- `tests/Feature/Console/SharesResyncClassCommandTest.php` — 8 tests Feature commande Artisan.

#### Modifiés (10 fichiers)

- `app/Enums/SambaPermission.php` — `case ShareManage` + mapping
  `legacyRight()` + `isSecondaryBitPermission()` + label + category.
- `app/Enums/SambaRole.php` — `ShareManage` ajoutée aux rôles
  `ShareAdmin` et `UserAdmin`.
- `app/Policies/SharePolicy.php` — méthode `manage(?Auth, ?UserGroup)` +
  entrée `'manage-share' => 'manage'` dans `$gates`.
- `app/Models/User.php` — `->using(UserGroupUserPivot::class)` sur
  `groups()` (et la BelongsToMany d'attach/detach).
- `app/Models/UserGroup.php` — `->using(UserGroupUserPivot::class)` sur
  `users()`.
- `app/Providers/AppServiceProvider.php` —
  `UserGroupUserPivot::observe(UserGroupUserPivotObserver::class)` dans
  `boot()`.
- `resources/views/pages/users/groups/[id]/index.blade.php` —
  `@livewire('pages::users.groups.[id]._partials.class-share-section', …)`
  conditionnel sur `$type === 'classe'`, position entre `members-list`
  et `group-quota-section`.
- `tests/Traits/CreatesPermissionSchema.php` — création table
  `quota_audit_logs` ajoutée pour les tests Feature qui utilisent le
  trait (drop order ajusté).
- `docs/domains/filesystem.md` — section dev "Story 5.2" append-only
  (architecture, ACLs canonique, Observer pivot, sudoers, smb.conf,
  audit, sécurité, hors scope).
- `docs/qa/domains/filesystem.md` — section QA "Story 5.2" append-only
  avec 8 scénarios numérotés stables 5.2-1 à 5.2-8.

#### Supprimés

Aucun (story purement additive — le legacy `partages/`,
`dossier_echange/`, `acls/` reste intouché ; cleanup defer story future).

### Kickoff Décisions

Toutes les recommandations SM par défaut ont été appliquées telles
quelles, conformément à la consigne user.

- **D1=A** — pas de table dédiée `shares` ; FS = source de vérité ;
  audit via `quota_audit_logs` polyvalente avec `target_type='share'`.
- **D2=A** — `share.manage` mappée sur `LegacyRight::ShareRefresh` (bit
  `SE_SHARE_REFRESH` partagé avec `share.refresh`, marquée
  `isSecondaryBitPermission` pour ne pas être sur-attribuée par
  `fromBitmask`).
- **D3=A** — archive élève via `Classe_<new>/<eleve>/Archives/` (legacy
  l. 345-356).
- **D4=A** — `archiveClassShare()` exposée mais aucun appel automatique.
- **D5=A+D** — Observer pivot custom synchrone (D5=A) + commande
  Artisan `shares:resync-class` filet bulk (D5=D). Pivot pattern réussi,
  pas de fallback nécessaire.
- **D6=A** — `_echange` activé par défaut à la création (ACL
  `group:Classe_<x>:rwx`).
- **D7=A** — sudoers VM non édités depuis le worktree (règle CLAUDE.md
  no-VM-from-worktree). Documentation dans `docs/qa/domains/filesystem.md`
  pré-requis VM + section [PROD] de la story listent les entrées
  nécessaires (`/usr/bin/setfacl`, `/usr/bin/getfacl`, `/bin/mkdir`,
  `/bin/mv`, `/bin/chown`, `/bin/chgrp`, `/bin/rm`).
- **D8=A** — aucune écriture `smb.conf` (la section globale `[classes]`
  expose tous les sous-dossiers automatiquement).
- **D9=A** — pas de rollback `mkdir+setfacl` en cas d'échec partiel ;
  idempotence garantie via `setAcls -b` (wipe) + bouton UI
  "Réappliquer les ACLs".
- **D10=A** — réutilisation `quota_audit_logs` avec `target_type='share'`
  (5 actions distinctes : `create_share`, `sync_user`, `toggle_echange`,
  `archive_share`, `resync_class`).
- **D11=A** — pas d'onglet `/admin/settings → Partages` ; gestion
  par-classe.
- **D12=A** — tests via `Process::fake` (mocks 100% — aucune commande
  shell réelle) ; smoke VM manuel documenté dans
  `docs/qa/domains/filesystem.md` pour Henri post-merge.
- **D13=A** — `ShareService::$classesRoot` + `AclService::$classesRoot`
  property statique override en tests + lecture
  `config('filesystem.classes_root')` si défini (flexibilité multi-tenant
  future).
- **D14=A** — pas de modif sidebar (gestion accessible via la page
  groupe `/app/users/groups/[id]`).

---

## Project Structure Notes

- **Filesystem-based router** : la section partage est un partial Livewire SFC dans `resources/views/pages/users/groups/[id]/_partials/class-share-section.blade.php` (cohérent convention CLAUDE.md). Pas de nouvelle page racine — la gestion est dans la page groupe (D11=A).
- **Services Filesystem** : `AclService` et `ShareService` rejoignent `HomeDirService` et `XfsQuotaService` dans `app/Services/Filesystem/`. Pattern DI uniforme.
- **Pas de page `/admin/settings → Partages`** (D11=A) — la gestion ACLs est par classe, pas globale.
- **Convention `base_path()` vs `dirname(__DIR__, N)`** — toutes les références path Laravel utilisent `base_path()` (CLAUDE.md). Pour les paths système `/var/sambaedu/Classes`, utiliser une property statique `ShareService::$classesRoot` (D13=A) overridable en tests + idéalement passer par `config('filesystem.classes_root', '/var/sambaedu/Classes')` pour future flexibilité multi-tenant.
- **Pas d'ajout entrée sidebar** (D14=A) — l'accès est par la page groupe.
- **Compatibility Spatie 7.x** — la permission `share.manage` est ajoutée à l'enum + seeder. Pas de cast bitmask tordu (cf. `epic7_legacy_bitmask_sunset.md` MEMORY — bitmask est dette en sursis).

---

## Review Fixes (2026-04-30)

Après deux passes de review (Sonnet + second avis Opus → 16 problèmes recensés dans `_bmad-output/codeReviews/5-2.md`), 13 corrections appliquées. Décisions Henri tranchées sur les 3 questions ouvertes (Q1=Option B / Q2=log warning + décalque legacy / Q3=exit code 2). Faux positifs Opus #5/#10 confirmés non corrigés (faux positifs documentés). #14 hors scope (refonte Process en jobs Queue → 5.3+).

### Corrections par fichier

- `app/Services/Filesystem/AclService.php` (#3) — `setfacl -R` → `setfacl -R -P` dans `setAcls`/`addAcl`/`removeAcl` (anti symlink traversal vs legacy).
- `app/Services/Filesystem/ShareService.php` (#7, #8, #11, #12, #13, #15) — `chownAndChgrp` retour bool + Log::warning ; `remainingRemoved` simplifié (`array_diff` symétrique disjoint) ; `archiveClassShare` log warning si cible existe déjà ; `escapeAclClassName` regex resserrée (refus `.`/espaces/etc) ; `Cache::forget('share-status:'.$id)` post-mutation dans `createClassShare`/`toggleEchange`/`archiveClassShare`/`syncUserClassMemberships` ; doc class-level mise à jour.
- `app/Console/Commands/SharesResyncClassCommand.php` (#2 / Q3) — suppression du double-lock côté commande, exit code 2 si toutes verrouillées (`failed=0 && resynced=0 && locked>0`), `--description` documente les codes retour, doc PhpDoc class-level mise à jour.
- `app/Services/UserService.php` (#1 / Q1) — `persistUserGroupsToSql` : sync ciblé classes-only via `detach()` + `syncWithoutDetaching()` (au lieu de `syncWithoutDetaching` aveugle), Observer pivot désactivé pendant le sync atomique, call explicit `ShareService::syncUserClassMemberships($user, $oldClassIds, $newClassIds)` avec les deux listes en main pour permettre l'archivage D3=A.
- `resources/views/pages/users/groups/[id]/_partials/class-share-section.blade.php` (#6, #16) — `groupModel()` mémoïsé via `private ?UserGroup $cachedGroup` ; `bustCache()` invalide aussi le cache mémoïsé ; `refresh()` ajoute `Gate::authorize('viewAny-share')` (cohérence pattern double-guard).
- `tests/Traits/CreatesPermissionSchema.php` (#9) — `action(20)` et `target_type(20)` alignés sur la migration prod.
- `database/seeders/PermissionSeeder.php` (#9) — commentaire ligne 21 mis à jour : "21 perms depuis Story 5.2 avec ajout `share.manage`".

### Tests ajoutés

- `tests/Unit/Services/Filesystem/AclServiceTest.php` :
  - `it_uses_dash_p_to_prevent_symlink_traversal_when_recursive` (anti-régression #3).
  - Tests existants `it_sets_acls_with_recurse_flag` / `it_adds_single_acl_with_setfacl_m` mis à jour pour matcher `-R -P`.
- `tests/Unit/Services/Filesystem/ShareServiceTest.php` :
  - `it_logs_warning_when_archive_target_already_exists` (#11 / Q2).
  - `it_rejects_invalid_or_dangerous_class_names` (DataProvider 11 patterns) (#12 / #15).
  - `it_lowercases_acl_class_name_and_rejects_spaces` (refonte #15).
- `tests/Feature/Console/SharesResyncClassCommandTest.php` :
  - `it_returns_exit_code_2_when_all_classes_locked` (#2 / Q3).
- `tests/Feature/Services/UserServiceClassChangeTest.php` (NOUVEAU FICHIER) :
  - `it_calls_share_service_once_with_old_and_new_class_ids_on_class_change` (#1 / Q1).
  - `it_does_not_call_share_service_when_class_set_unchanged` (no-op sanity).
  - `it_disables_observer_during_atomic_sync_to_avoid_duplicate_calls` (anti-doublon Observer / call explicit).

### Investigation Legacy → Schéma ACLs canonique (clarification #4)

Le bloc `Sur Classe_<nom>/ (racine classe)` a été corrigé pour refléter la réalité legacy : `default:group:Classe_<class_lower>:rx` n'est PAS posé sur la racine (le legacy ligne 567 ne pose que les non-default `-m`). La lecture `Classe_X:rx` est héritée via les sous-dirs `_travail`/`_echange` qui ont leur propre `default:group:Classe_X:rx`. L'implémentation `buildRootRwToRxAdjustment` est fidèle au legacy.

### Tests / régressions

- Story 5.2 isolée + UserCreationTest + UserServiceClassChange : **102 tests verts** (vs baseline 74). +28 tests, 0 régression.
- Suite globale : 1477 tests, 38 errors / 5 failures **tous pré-existants** (LDAP `ldap_connect` requiert ext PHP ldap, Wallpaper requiert ext gd, Legacy modules requièrent VM Apache routes). Aucune régression Story 5.2.

### Status post-corrections

- Doc review : `_bmad-output/codeReviews/5-2.md` → status `to-validate`.
- Story : statut reste `review` (Henri valide explicitement le passage à `done` ou `merged`).
