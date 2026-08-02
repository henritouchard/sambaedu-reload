# QA — Synchronisation AD

Runbook QA pour la **synchronisation Active Directory** des objets PG (Workstation, WorkstationGroup, UserGroup, AppProfile, Shortcut) via le pattern observer-driven.

## Pré-requis communs

- VM accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- AD `localdev.fr` opérationnel (samba-tool + ldapsearch fonctionnels sur la VM)
- Worker queue actif : `php artisan queue:work --tries=3` (ou exécution `--once` ponctuelle)
- Une machine de test pré-existante en AD ET en PG (ex. `CN=test-4-9,OU=computers,DC=localdev,DC=fr` + `Workstation::where('name','test-4-9')`)

---

## Section 1 — Workstation (Story 4.9)

Le pattern : toute écriture Eloquent sur `Workstation` déclenche `WorkstationObserver` → dispatch async `WorkstationAdSyncJob` qui exécute l'action AD correspondante.

**Pourquoi rename via LdapRecord modrdn (et pas samba-tool computer rename)** :

| Attribut | `samba-tool computer rename` | `LdapRecord modrdn` (Story 4.9) |
|---|---|---|
| `cn` / `name` / DN | Renommé (delete + recreate) | Renommé (modrdn pur) |
| `sAMAccountName` | Renommé | Renommé manuellement |
| `objectGUID` | **DÉTRUIT** (nouveau GUID) | **PRÉSERVÉ** |
| `netbootGUID` | **DÉTRUIT** | **PRÉSERVÉ** |
| `dNSHostName` | À reposer manuellement | À reposer manuellement |
| `servicePrincipalName` | À reposer | À reposer |

Validation expérimentale : VM `/vm` 2026-05-28 sur `CN=28051115,OU=ULIS,OU=computers,DC=localdev,DC=fr`. Avant/après le modrdn : `objectGUID` et `netbootGUID` strictement identiques (vérification `ldapsearch -b ... '+'`).

### Scénario 1.1 — Rename Workstation préserve objectGUID + netbootGUID

**Pré-condition :** une `Workstation` PG existe avec `name = 'test-4-9'`, un compte AD `CN=test-4-9,...` existe également avec un `netbootGUID` posé (via `AdMachineManager::registerHardware`).

1. **Capture initiale** :
   ```bash
   ssh /vm 'ldapsearch -Y EXTERNAL -H ldapi:// -b "CN=test-4-9,OU=computers,DC=localdev,DC=fr" "+" objectGUID netbootGUID sAMAccountName dNSHostName' | tee /tmp/before.ldif
   ```
2. **Déclencher le rename** depuis Laravel :
   ```bash
   ssh /vm 'cd /var/www/sambaedu-reload && php artisan tinker --execute="
     \App\Models\Workstation::where(\"name\",\"test-4-9\")->first()->update([\"name\" => \"test-4-9-renamed\"]);
   "'
   ```
3. **Exécuter la queue** (job async) :
   ```bash
   ssh /vm 'cd /var/www/sambaedu-reload && php artisan queue:work --once'
   ```
4. **Capture finale** :
   ```bash
   ssh /vm 'ldapsearch -Y EXTERNAL -H ldapi:// -b "CN=test-4-9-renamed,OU=computers,DC=localdev,DC=fr" "+" objectGUID netbootGUID sAMAccountName dNSHostName' | tee /tmp/after.ldif
   ```
5. **Asserts** :
   - `objectGUID` strictement identique avant/après (`diff <(grep objectGUID /tmp/before.ldif) <(grep objectGUID /tmp/after.ldif)` → vide).
   - `netbootGUID` strictement identique avant/après.
   - `sAMAccountName == TEST-4-9-RENAMED$`.
   - `dNSHostName == test-4-9-renamed.localdev.fr`.
   - `servicePrincipalName` contient `HOST/test-4-9-renamed` ET `HOST/test-4-9-renamed.localdev.fr`.
   - `Workstation::where('name', 'test-4-9-renamed')->exists() === true` côté PG.

### Scénario 1.2 — Changement de status PG → userAccountControl AD

**Mapping (D5) :**
- `status = 'active'` ou `'protected'` → `userAccountControl = 4096`.
- `status = 'inactive'` → `userAccountControl = 4098` (4096 + ACCOUNTDISABLE).
- Autre valeur → throw `InvalidArgumentException` (alerte ops, retry x3).

1. Depuis tinker : `Workstation::where(...)->update(['status' => 'inactive'])`.
2. `php artisan queue:work --once`.
3. `ldapsearch -b "CN=test-4-9-renamed,..." userAccountControl` → doit afficher `4098`.
4. Repasser à `active` → `4096`.

### Scénario 1.3 — Suppression Workstation supprime le compte AD

1. `Workstation::where('name', 'test-4-9-renamed')->first()->delete();`
2. `php artisan queue:work --once`.
3. `ldapsearch -b "CN=test-4-9-renamed,..."` → doit renvoyer `No such object` (compte absent).

### Scénario 1.4 — Création Workstation pose ad_guid PG

1. `Workstation::create(['name' => 'pc-fresh-1', 'uuid' => '...', 'mac' => '...', 'status' => 'active'])`.
2. `php artisan queue:work --once`.
3. `Workstation::where('name', 'pc-fresh-1')->first()->ad_guid` → non null (GUID lu via `MachineModel::getConvertedGuid()`).
4. `ldapsearch -b "CN=pc-fresh-1,..." objectGUID` → doit matcher `ad_guid` PG (modulo format).
5. Si `uuid` fourni → `netbootGUID` doit être posé (`AdMachineManager::registerHardware`).

### Scénario 1.5 — withoutSync ne déclenche pas le job AD

Cas légitime : seeders, imports CSV, sync inverse depuis AD.

```php
\App\Observers\WorkstationObserver::withoutSync(function () {
    \App\Models\Workstation::create([
        'name' => 'pc-seed-1',
        'uuid' => '...',
        'mac' => '...',
        'status' => 'active',
    ]);
});
```

Vérifier dans `storage/logs/laravel.log` qu'aucun message `[WorkstationAdSyncJob]` n'apparaît pour ce poste. La queue (`jobs` table) ne doit pas non plus contenir de job pour ce ws.

### Scénario 1.6 — Rollback nom original (recette inverse)

Après scénario 1.1, restaurer l'état initial pour pouvoir rejouer la batterie :
```php
\App\Models\Workstation::where('name', 'test-4-9-renamed')->update(['name' => 'test-4-9']);
```
+ `queue:work --once`.

### Post-correctifs & non-régressions

- **Story 4.9** : root-cause fix de la divergence PG↔AD dans le flow iPXE Windows post-install (`WindowsPostInstallTracker::recordRenommeAdRenamed`). Avant : rename AD via samba-tool MAIS pas d'écriture `$ws->name` en PG → AD avance, PG reste sur l'ancien nom. Après : `$ws->name = $role` dans la transaction PG, observer dispatche le job AD async (modrdn).
- **Décision D7** : suppression de `registerHardware` post-rename dans `WorkstationEnrollmentService` — modrdn préserve `netbootGUID`, plus besoin de le reposer.

### Checklist rapide

- [ ] 1.1 — Rename : `objectGUID` et `netbootGUID` préservés strictement
- [ ] 1.2 — Status inactive → UAC 4098, active → UAC 4096
- [ ] 1.3 — Delete → compte AD absent
- [ ] 1.4 — Create → ad_guid posé en PG
- [ ] 1.5 — withoutSync ne dispatche aucun job
- [ ] 1.6 — Rollback nom original OK

---

## Story 38.7 — `OU=Parcs` en lecture seule (extinction des écritures SE5)

**Invariant.** `OU=Parcs` est un vestige SE4 en LECTURE SEULE : on le LIT à l'import
de migration (`sync-from-ad`), on n'y ÉCRIT plus rien — ni parcs logiques, ni miroir
`CN` des salles physiques, ni profils applicatifs (`AppProfile`). `OU=Computers` reste
INTÉGRALEMENT géré par SE5 : c'est là que les machines sont rangées et que les GPO sont
liées — l'unique invariant AD à protéger.

> **Note d'honnêteté (asymétrie assumée).** `WorkstationGroupService::importLogicalGroupsFromAd()`
> aspire *tout* `CN` de `OU=Parcs`, y compris d'anciens profils WPKG legacy — comportement
> préexistant, non aggravé, mais désormais asymétrique (on lit un conteneur qu'on n'écrit
> plus). Signalé explicitement plutôt que subi.

### Scénario 38.7-A — Aucune écriture SE5 dans `OU=Parcs`

Créer / renommer / déplacer / supprimer un groupe **logique** (`is_physical = false`),
créer / renommer / supprimer un `AppProfile`, déplacer une machine d'une salle à l'autre.

Vérifier dans `storage/logs/laravel.log` qu'AUCUN `[WorkstationGroupAdSyncJob]` ni
`AppProfileAdSyncJob` n'est émis pour ces objets, et qu'aucun `CN` n'apparaît /
disparaît sous `OU=Parcs`. Test de référence : **la suite passe avec un annuaire
injoignable** sur tous ces chemins d'administration.

### Scénario 38.7-B — La salle physique reste écrite (non-régression)

Créer / renommer / déplacer / supprimer un groupe **physique** (`is_physical = true`) :
l'`OU` correspondante sous `OU=Computers` doit être créée / renommée / déplacée /
supprimée exactement comme avant, et l'objet ordinateur rangé dans la bonne `OU`.

### Scénario 38.7-C — `se4:prune-ad-parcs` (prévisionnel)

- `php artisan se4:prune-ad-parcs` (sans `--confirm`) : dry-run, liste les `CN`,
  journalise NOMMÉMENT les exclusions (homonymes d'un `app_profiles.name` — collision ;
  homonymes d'un groupe physique — miroir vivant), **aucune écriture LDAP**.
- `--confirm` : refuse si l'extinction à blanc SE4 n'est pas en place (le legacy lit
  encore `OU=Parcs` via `gpo/applications.php`), et refuse d'agir sans droit d'écriture
  (pas de faux succès). NON branchée dans un scheduler ni dans `import:sync-from-ad`.

### Scénario 38.7-D — Import de migration sélectif

- Étape 5 (groupes logiques) : un `CN` dont le parc legacy ne porte AUCUNE application
  n'est PAS importé (listé nommément avec son nombre de machines). `_TousLesPostes` n'est
  jamais importé. Salles physiques (étape 4) : NON concernées.
- Étape 7 (profils) : un `AppProfile` n'est créé QUE si le parc legacy porte des
  applications. `_TousLesPostes` → ses applications sont promues en `applications.is_parc_default`
  (couche Broadcast), jamais un profil. Legacy injoignable ⇒ **zéro création + warning**
  (« étape incomplète, à rejouer »), jamais de repli silencieux. Rejeu idempotent :
  `is_parc_default` n'est jamais retiré.

### Checklist rapide

- [ ] 38.7-A — Admin parcs/profils : zéro écriture `OU=Parcs`, suite verte annuaire injoignable
- [ ] 38.7-B — Salle physique : `OU=Computers` créée/renommée/déplacée/supprimée comme avant
- [ ] 38.7-C — `se4:prune-ad-parcs` dry-run liste + exclusions journalisées, `--confirm` gardé
- [ ] 38.7-D — Import : parc sans app sauté (nom+machines), `_TousLesPostes` → défauts parc, legacy KO = zéro création

---

## Story 49.3 — Réconciliation des départs (`users:reconcile-departures`)

**Ce que la passe fait, et rien d'autre.** Chaque nuit à **01h30**, un balayage AD
complet ; tout compte actif `source='ad'` **absent de ce balayage** est désactivé
(`is_active=false`, `role='autre'`) et **détaché de tous ses groupes**. Le volet droits
en découle sans code dédié : le détachement déclenche l'observer pivot, qui
réconcilie les profils **portés** par les groupes (cf. `rights-management.md` §19).

Ce qu'elle **ne** fait **pas** : aucun hard-delete (la ligne `users` reste, c'est la
piste d'audit), **aucun archivage de home** (contrairement à la désactivation
manuelle depuis la fiche utilisateur), aucune écriture AD, et **aucun retrait de
délégation manuelle** — un professeur également `user-admin` qui part garde
`user-admin` sur un compte inactif ; c'est la session qui lui est refusée, pas le
droit qui est effacé.

> La sortie d'un **groupe** (l'utilisateur est toujours dans l'annuaire) n'a rien à
> voir avec un départ : elle est déjà traitée en continu par le tick de synchro des
> 5 minutes. Le nightly ne traite QUE les absents.

### Scénario 49.3-A — Départ réel

1. Dans l'AD, retirer un utilisateur de test de `Eleves`/`Profs`/`Administratifs` (ou
   supprimer son compte).
2. `php artisan users:reconcile-departures --dry-run` : le plan doit le lister
   nommément, **sans rien écrire**.
3. `php artisan users:reconcile-departures` (ou attendre 01h30).
4. Vérifier : `users.is_active = false`, `users.role = 'autre'`, plus aucune ligne
   `user_group_user`, profils **portés** retirés de `model_has_roles`, délégations
   manuelles **toujours présentes**, home **intact** sur le disque.
5. Compte-rendu : `désactivés = 1`, `garde = passée`, code de sortie **0**.

### Scénario 49.3-B — Retour de l'utilisateur

Réintégrer le compte dans son groupe principal, puis attendre le tick delta
(≤ 5 min) ou lancer `php artisan users:sync-from-ad --mode=full --now` :
`is_active` repasse à `true` (miroir de `useraccountcontrol`), `users.role` est
réécrit depuis le groupe principal, les appartenances sont re-posées par le
read-back et les profils portés re-matérialisés. Le compteur `reactivated` du
compte-rendu doit valoir 1.

### Scénario 49.3-C — Désactivation manuelle NON ressuscitée (non-régression)

Désactiver un utilisateur depuis l'interface (double-write : AD `uac=514` + SQL).
Le compte reste membre de ses groupes AD, donc **présent** à chaque balayage.
Après plusieurs ticks de 5 minutes **et** une passe nightly : `is_active` doit
toujours valoir `false`, et l'utilisateur **ne doit pas** avoir été détaché de ses
groupes (ce n'est pas un départ).

### Scénario 49.3-D — La garde anti-désactivation en masse (le test qui compte)

À jouer **avant toute mise en service** : c'est l'assurance qu'une panne
d'annuaire ne désactive pas l'établissement.

| Simulation | Attendu |
|---|---|
| Arrêter Samba / couper le bind LDAP, puis lancer la commande | `ABANDONNÉE (balayage AD en échec)`, **0 désactivation**, `Log::critical`, sortie **2** |
| Rendre un seul groupe principal illisible (ACL, renommage) | `ABANDONNÉE (au moins un groupe principal illisible)`, **0 désactivation**, sortie **2** |
| Renommer/supprimer les trois groupes principaux | `ABANDONNÉE (aucun groupe principal trouvé)`, sortie **2** |
| Balayage vide alors que la base compte des actifs | `ABANDONNÉE (balayage vide…)`, sortie **2** |
| Vider un groupe entier (au-delà du seuil) | `ABANDONNÉE (seuil… dépassé)`, sortie **2** |

Dans les **cinq** cas : `désactivés = 0`, base inchangée. Vérifier le log critique,
il doit nommer la condition **et** porter les compteurs (présents AD, actifs base,
candidats, seuil) — c'est ce qui permet de décider de la suite.

### Scénario 49.3-E — Procédure de rentrée (purge massive LÉGITIME)

À la rentrée, une purge AAF fait partir des centaines de comptes d'un coup : la
garde de seuil se déclenche, **et c'est le comportement voulu**. Le geste assumé :

1. `php artisan users:reconcile-departures --dry-run` — **lire la liste**. Est-ce
   bien la promotion sortante, et pas un groupe amputé par une erreur d'annuaire ?
2. Si et seulement si la liste est cohérente :
   `php artisan users:reconcile-departures --force`.
3. Vérifier le compte-rendu (`désactivés` = le nombre attendu, `erreurs = 0`).

> `--force` ne lève **QUE** le seuil. Les quatre gardes de santé du balayage restent
> infranchissables : sur un annuaire en panne, la commande refusera d'agir même
> forcée. Un `--force` qui sort en **2** est donc un incident d'annuaire, pas un
> seuil à augmenter.

**Même cause, autre effet — changement de code établissement.** Modifier l'UAI
courant exclut d'un coup tous les anciens utilisateurs du balayage : la garde se
déclenchera. Ne pas forcer sans avoir vérifié le `--dry-run`.

### Scénario 49.3-F — Idempotence & fail-soft

- Relancer la commande immédiatement : `absents détectés = 0`, `désactivés = 0`,
  sortie **0** (les partis sont déjà inactifs et détachés).
- Si un utilisateur est en erreur : la boucle **continue**, l'erreur est comptée et
  loggée, sa transaction à lui est annulée, et la commande sort en **1**.

### Réglages

| Clé | Env | Défaut | Rôle |
|---|---|---|---|
| `sambaedu.user_sync.reconcile.max_disable_ratio` | `USER_SYNC_RECONCILE_MAX_DISABLE_RATIO` | `0.10` | Protège les gros parcs |
| `sambaedu.user_sync.reconcile.max_disable_floor` | `USER_SYNC_RECONCILE_MAX_DISABLE_FLOOR` | `5` | Protège les petits parcs |

Seuil effectif = `max(ceil(ratio × actifs), plancher)`.

### Checklist rapide

- [ ] 49.3-A — Départ : désactivé, détaché, profils portés retirés, **délégation conservée**, home intact
- [ ] 49.3-B — Retour : réactivé au tick delta, rôle et profils re-posés, `reactivated = 1`
- [ ] 49.3-C — Désactivation manuelle jamais ressuscitée par la sync
- [ ] 49.3-D — Les 5 conditions de garde : 0 désactivation, log critique, sortie 2
- [ ] 49.3-E — `--dry-run` puis `--force` : seul le seuil est levé
- [ ] 49.3-F — Re-run = no-op ; une erreur n'arrête pas la passe (sortie 1)

### Scénario 49.3.7 — Corrections de review : criticité de la pagination & périmètre établissement

> Deux angles morts trouvés par la review adversariale, tous deux sur le même thème : **la garde ne
> protège que ce qu'elle peut voir.**

**A — Le contrôle paged-results est désormais CRITIQUE sur le chemin de réconciliation.**
Un contrôle LDAP non critique qu'un serveur ne reconnaît pas est *ignoré silencieusement*
(RFC 4511 §4.1.11) : le balayage repartait alors en mode tronqué-sans-signal, celui-là même que la
pagination devait éviter. `fetchPresence()` pose maintenant la criticité ; l'import et le delta
5 min gardent la criticité par défaut (les casser toutes les 5 minutes serait pire que la
troncature qu'ils subissaient déjà).

1. Sur un annuaire de test, vérifier que `php artisan users:reconcile-departures --dry-run` termine
   normalement (Samba AD honore le contrôle : c'est le cas nominal attendu).
2. Si un jour un annuaire ne l'honore pas : la commande doit **échouer bruyamment** (exit 2, log
   `[ReconcileUserDepartures] balayage AD en échec` avec la classe et le message d'exception), et
   **aucun compte ne doit être désactivé**. C'est le comportement voulu — fail-closed.
3. Contre-épreuve : le delta 5 min (`users:sync-from-ad --mode=delta`) doit continuer de tourner
   normalement dans cette même situation.

**B — Les comptes d'un AUTRE établissement ne sont plus candidats au départ.**
Quand un code établissement est configuré, le balayage écarte les comptes non rattachés : ils
étaient donc « absents » à chaque passe et se faisaient désactiver toutes les nuits — sous le seuil
de la garde, puisque ces populations sont petites, donc en silence.

4. Sur une instance avec `establishmentCode` configuré, créer/identifier un compte `source='ad'`
   dont `school_code` diffère du code courant.
5. Lancer `users:reconcile-departures --dry-run` : ce compte **ne doit pas** figurer parmi les
   candidats, même s'il n'apparaît dans aucun balayage.
6. Contre-épreuve : un compte du même établissement, réellement absent de l'AD, doit toujours être
   candidat.

> Règle à retenir pour toute évolution du périmètre : **ce que le balayage ne peut pas voir ne peut
> pas être déclaré parti.** Les quatre exclusions (fédérés, admin protégé, comptes système, autre
> établissement) découlent toutes de cette seule phrase.

**C — Journalisation d'une panne réelle.** La commande tourne sous cron sans redirection de sortie :
le message d'exception est désormais écrit dans `storage/logs/laravel.log`. Après un échec nocturne,
vérifier qu'on peut distinguer un bind refusé d'un timeout réseau — sans cette trace, l'exit code 2
ne dit que « rien n'a été fait ».
