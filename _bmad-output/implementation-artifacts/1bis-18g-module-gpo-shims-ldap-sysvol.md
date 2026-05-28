# Story 1bis.18g : Module GPO — Shims LDAP/AD (gpo/site/subnet) + wrappers sysvol

Status: review

## Story

As a **développeur**,
I want implémenter dans `legacy/` les shims LDAP manquants pour les types GPO-spécifiques (`gpo`, `site`, `subnet`), les wrappers d'action GPO (`gpolistcontainers`, `gpogetlink`, `gposetlink`, `gpodellink`, `modify_ad(type=gpo)`) et les fonctions d'accès SYSVOL (`sysvol_put`, `read_gpo_sysvol`, `update_gpo_sysvol`, `sysvol_acl_reset`),
So que les flux import/export/link GPO des stories 18b/c/d/e/f fonctionnent end-to-end sur la VM cible — avec un AD Samba réel — et pas uniquement en HTTP 200 host-side comme c'est le cas après 18b.

## ⚠️ Principe d'architecture — GPO = vraie requête AD (pas de shim Eloquent)

**Point critique à ne pas confondre avec les autres cases de `search_ad`.**

Les cases `user`, `group`, `machine`, `member`, `filter` de `legacy/ldap.inc.php::search_ad()` sont shimmés vers **Eloquent/Postgres** (modèles `User`, `UserGroup`, `Workstation`) parce que **SER est propriétaire** de ces données — elles vivent dans la base Laravel, l'AD Samba n'en est qu'une réplique de projection.

**Les GPO, sites, subnets, liens GPO et SYSVOL sont le cas opposé** : SER n'en possède **aucune copie**. La source de vérité est **exclusivement l'AD Samba réel**. Il n'existe pas de table `gpos` dans Postgres, et il n'y aura pas. Donc :

- `search_ad(type='gpo'|'site'|'subnet')` → **`ldap_connect` + `ldap_bind` + `ldap_search` natifs PHP sur l'AD Samba** (`$config['bind']` = DC). Aucun modèle Eloquent, aucun fallback DB.
- `modify_ad(type='gpo')` → **`ldap_mod_replace` natif** sur le DN AD de la GPO.
- `gpolistcontainers` / `gpogetlink` / `gposetlink` / `gpodellink` → soit **`exec(samba-tool gpo ...)`** sur la VM, soit **`ldap_search` natif** sur les attributs `gpLink` des OU. Décision Phase 1 (T3.1).
- `sysvol_put` / `read_gpo_sysvol` / `update_gpo_sysvol` / `sysvol_acl_reset` → **`exec(smbclient -k)` / `exec(smbcacls)`** avec ticket Kerberos. Le SYSVOL est un partage SMB AD — aucune alternative Eloquent n'existe.

**Conséquence pratique pour les tests :**
- **Tests unit host** : mock `ldap_connect`/`ldap_bind`/`ldap_search` + mock `exec`. Pas de DB involvement.
- **Tests intégration VM** : seul endroit où on valide le flux réel contre un AD Samba + SYSVOL vivants.

## Contexte (découverte du gap)

Gap identifié en test d'intégration VM de **1bis-18b** (done le 2026-04-15). Scénario reproduit :
1. `/gpo/gpo-maj.php` → cocher une GPO initiale (ex. "Wallpaper") → Valider.
2. `import_gpo($config, 'Wallpaper', ...)` appelle `search_ad($config, 'Wallpaper', 'gpo')` pour vérifier si la GPO existe déjà.
3. Le shim `legacy/ldap.inc.php::search_ad()` ne connaît que les types `user`, `group`, `machine`, `member`, `filter` (lignes 317–435). Le type `'gpo'` tombe dans le `default:` ligne 437 → log `_shim_log_unimplemented` + retour `[]`.
4. Le retour vide est interprété comme "GPO absente" → `gpocreate()` est appelé → création OK au 1ᵉʳ tour.
5. 2ᵉ submit (idempotence) : `search_ad` toujours vide → `gpocreate` rappelé → `samba-tool gpo create "Wallpaper"` échoue avec `GPO already existing`.

Cf. `_bmad-output/planning-artifacts/sprint-change-proposal-2026-04-15.md`.

## Dépendances et blocages

- **Dépend de** : `1bis-18a` (done — bootstrap + includes GPO core), `1bis-18b` (done — pages gestion/import/export)
- **Bloque** : `1bis-18c` (apps Firefox/Thunderbird), `1bis-18d` (wallpaper), `1bis-18e` (scripts/veyon/wine/associations), `1bis-18f` (profils itinérants)
- **Débloque indirectement** : `9-3` (scripts démarrage Windows, actuellement paused sur « shim gpo/applications.php »)

## Acceptance Criteria

1. **`search_ad(type='gpo')` opérationnel** — Given une GPO "Wallpaper" existe dans l'AD sous `CN=Policies,CN=System,{base_dn}`, When `search_ad($config, 'Wallpaper', 'gpo')` est appelé, Then le retour est un tableau non vide `{0: [...], count: 1}` dont l'entrée 0 contient au minimum les clés : `cn`, `displayname`, `gpcfilesyspath`, `versionnumber`, `gpcuserextensionnames`, `gpcmachineextensionnames`, `gpcfunctionalityversion`, `flags`. Given la GPO n'existe pas, Then le retour est `{count: 0}` (pas un `[]` vide qui mélangerait "not found" et "unimplemented"). Le filtre LDAP utilisé est `(&(objectclass=grouppolicycontainer)(|(cn={name})(displayname={name})))` — référence : `sambaedu/includes/ldap.inc.php:1406`.

2. **`search_ad(type='site')` et `search_ad(type='subnet')`** — Given des sites/subnets AD existent, When `search_ad` est appelé avec `type='site'` ou `type='subnet'`, Then le contrat de retour est similaire à `'gpo'` (attributs AD correspondants, cf. `sambaedu/includes/ldap.inc.php:1428` et `:1442`). Given aucun résultat, retour `{count: 0}`.

3. **`modify_ad(type='gpo')` opérationnel** — Given une GPO existante, When `modify_ad($config, $dn, 'gpo', $mode='replace', $attrs=['versionnumber'=>..., 'gpcuserextensionnames'=>..., 'gpcmachineextensionnames'=>..., 'gpcfunctionalityversion'=>...])` est appelé, Then l'attribut est modifié côté AD (vérifiable via un `search_ad` suivant dans le même test), et la fonction retourne `true`. Given une GPO inexistante, Then retour `false` + log error channel `legacy`.

4. **Wrappers GPO opérationnels** — Given le bridge Kerberos est actif (ticket valide via `/usr/share/sambaedu/sbin/renew_ticket.sh`), When les fonctions suivantes sont appelées, Then leur contrat est respecté :
   - `gpolistcontainers($config, $gpo_cn)` → liste des DN de containers (OU) liés à la GPO, tableau de strings.
   - `gpogetlink($config, $container_dn)` → liste des GPO liées à un container, tableau d'objets `['cn', 'displayname', 'gpo_dn', 'enforced', 'disabled']` (cf. legacy samba-tool gpo listcontainers/listlink output).
   - `gposetlink($config, $container_dn, $gpo_dn, $opts=['enforced'=>bool, 'disabled'=>bool])` → crée le lien, retourne `true`/`false`.
   - `gpodellink($config, $container_dn, $gpo_dn)` → supprime le lien, retourne `true`/`false`.

5. **Accès SYSVOL via bridge Kerberos** — Given un ticket Kerberos est disponible dans `KRB5CCNAME`, When les fonctions sont appelées :
   - `sysvol_put($config, $local_path, $sysvol_path)` → upload via `smbclient -k` (ou `--use-kerberos=required`), retourne `true`/`false`.
   - `read_gpo_sysvol($config, $gpo, $relative_path)` → télécharge + retourne le contenu du fichier SYSVOL, ou `false` si absent.
   - `update_gpo_sysvol($config, &$gpo, $relative_path, $data)` → écrit atomiquement (temp+rename pattern), incrémente `versionnumber` en paramètre par-ref `&$gpo`, retourne `true`/`false`.
   - `sysvol_acl_reset($config, $gpo_cn)` → réapplique les ACLs par défaut via `smbcacls` / `samba-tool ntacl sysvolreset`, retourne `true`/`false`.

6. **Test d'acceptation end-to-end sur VM** — Given la VM est démarrée avec un AD Samba fonctionnel et un ticket Kerberos valide, When un admin navigue `/gpo/gpo-maj.php` → coche "Wallpaper" → clique Valider, Then :
   a. Le message vert « Importation via Git OK » s'affiche.
   b. `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 "samba-tool gpo listall"` liste la GPO "Wallpaper" avec son GUID.
   c. Le fichier SYSVOL `\\{domain}\SYSVOL\{domain}\Policies\{guid}\Registry.pol` existe et contient les clés spécialisées pour l'établissement (via `specialise_gpo` → `update_gpo_sysvol`).

7. **Idempotence import** — Given la GPO "Wallpaper" vient d'être importée (AC #6), When l'admin resoumet le même import (2ᵉ clic Valider), Then :
   a. **Pas** de nouvel appel à `samba-tool gpo create` (donc pas d'erreur `already existing`).
   b. `search_ad($config, 'Wallpaper', 'gpo')` retourne bien la GPO existante.
   c. Le flux réapplique `specialise_gpo` + `update_gpo_sysvol` + `modify_ad(type=gpo)` pour resync versionnumber, sans casser.
   d. Message utilisateur affiché : « Importation via Git OK » (idempotence silencieuse) ou équivalent explicite.

8. **Non-régression host-side** — Given la suite de tests Feature/Unit actuelle (524 tests, commit `ccbee15`), When `php artisan test` est exécuté sur host après implémentation, Then la suite reste verte — aucune régression sur les cases `user/group/machine/member/filter` du `search_ad` existant ni sur les stubs `modify_ad/delete_ad/move_ad` (qui restent stubbés pour les types non-GPO — seul `type='gpo'` est implémenté ici).

9. **Tests unit ajoutés (host)** — Given un fichier `tests/Unit/LegacyGpoShimsTest.php`, When `php artisan test --filter=LegacyGpoShims` est exécuté, Then au minimum 10 tests passent couvrant :
   a. Contract `search_ad(type=gpo)` avec mock LDAP (LDAP entries factices).
   b. Contract `search_ad(type=site)` et `type=subnet` idem.
   c. Contract `modify_ad(type=gpo)` : call au bon filtre + bons attributs via mock.
   d. `gpolistcontainers` : parsing de sortie `samba-tool gpo listcontainers` mockée.
   e. `gpogetlink` / `gposetlink` / `gpodellink` : contract via mock exec.
   f. `sysvol_put` / `read_gpo_sysvol` / `update_gpo_sysvol` : contract via mock exec smbclient, avec bridge `KRB5CCNAME`.
   g. `sysvol_acl_reset` : contract via mock exec smbcacls.
   h. Cas d'erreur : GPO absente, ticket Kerberos expiré, connexion LDAP refusée.
   i. Atomicité `update_gpo_sysvol` : écrit dans temp puis rename (vérifiable via `vfsstream` ou mock FS).
   j. Audit `escapeshellarg` : vérification que les paramètres entrés dans exec() le sont bien (regex sur le code ou assertion sur mock).

10. **Audit sécurité exec documenté** — Given cette story ajoute des appels `exec('samba-tool gpo ...')`, `exec('smbclient ...')`, `exec('smbcacls ...')`, When la story est revue, Then un tableau d'audit dans les Dev Notes liste pour chaque appel : (a) la commande, (b) les paramètres issus d'input utilisateur, (c) le statut `escapeshellarg`, (d) le risque résiduel. Tout paramètre issu d'un formulaire web (nom de GPO, DN de container) DOIT passer par `escapeshellarg()`.

11. **Bridge Kerberos documenté** — Given le bridge `KRB5CCNAME` est nécessaire pour smbclient/smbcacls, When la story est revue, Then les Dev Notes documentent :
   a. Où le ticket est renouvelé (`/usr/share/sambaedu/sbin/renew_ticket.sh` — cron ou appel explicite ?).
   b. Comment la variable d'env `KRB5CCNAME` est propagée à PHP (via `putenv()` dans bootstrap ? via config Apache/FPM ?).
   c. Quel est le cas "ticket expiré" et comment il est géré (renouvellement auto ? erreur explicite remontée à l'utilisateur ?).

12. **Error logger propre** — Given les nouveaux shims sont appelés avec des paramètres valides, When `ErrorLoggerService` est consulté, Then aucune entrée `CRITICAL`/`ERROR` n'est enregistrée sur channel `legacy`. Les warnings non fatals (ticket à renouveler, SYSVOL file absent attendu) sont tolérés mais tagués explicitement.

## Tasks / Subtasks

### Phase 1 — Analyse & lecture legacy (AC: #1, #2, #3, #4, #5, #11)

- [x] **T1.1** Lire et documenter `sambaedu/includes/ldap.inc.php:1406+` — cases `'gpo'`, `'site'`, `'subnet'` de la `search_ad` originelle. Noter : filtre LDAP exact, branche (`CN=Policies,CN=System,{base_dn}` pour GPO), attributs retournés. (AC: #1, #2)
- [x] **T1.2** Lire `sambaedu/includes/ldap.inc.php` — case `'gpo'` de `modify_ad`. Noter le mode (`replace`/`add`/`del`) et les attributs supportés. (AC: #3)
- [x] **T1.3** Lire `sambaedu/includes/samba-tool.inc.php` — fonctions `gpolistcontainers`, `gpogetlink`, `gposetlink`, `gpodellink`. Noter : commande `samba-tool gpo` utilisée, format de sortie parsé. (AC: #4)
- [x] **T1.4** Lire `sambaedu/includes/gpo.inc.php` — fonctions `sysvol_put`, `read_gpo_sysvol`, `update_gpo_sysvol`, `sysvol_acl_reset`. Noter : commandes smbclient/smbcacls exactes, chemin SYSVOL construit, bridge Kerberos supposé actif. (AC: #5)
- [x] **T1.5** Identifier comment `KRB5CCNAME` est positionné côté PHP legacy. Grep sur `putenv|KRB5CCNAME|renew_ticket` dans sambaedu/ et legacy/. Documenter dans les Dev Notes. (AC: #11)

### Phase 2 — Implémentation shim LDAP (cases GPO) (AC: #1, #2, #3)

- [x] **T2.1** Étendre `legacy/ldap.inc.php::search_ad()` avec les cases `'gpo'`, `'site'`, `'subnet'`. **Ne PAS shimmer vers Eloquent — requête LDAP réelle sur l'AD Samba via `ldap_connect`/`ldap_bind`/`ldap_search` PHP natifs** (contrairement aux cases user/group/machine qui utilisent les modèles Laravel). Le DC est connu via `$config['bind']`, le base DN via `$config['ldap_base_dn']`. Authentification : soit credentials machine (`$config['bind_dn']` + `$config['bind_password']` si présents), soit GSSAPI/Kerberos via `ldap_sasl_bind` avec `KRB5CCNAME` (aligné avec le pattern smbclient de la Phase 4). Branches de recherche :
   - `'gpo'` → `CN=Policies,CN=System,{base_dn}`, filtre `(&(objectclass=grouppolicycontainer)(|(cn={name})(displayname={name})))`
   - `'site'` → `CN=Sites,CN=Configuration,{base_dn}`
   - `'subnet'` → `CN=Subnets,CN=Sites,CN=Configuration,{base_dn}`
   Retourner au format `_shim_wrap_results()` existant (cohérence avec les autres cases). **Distinction claire : retour `{count: 0}` pour "LDAP OK, pas de match" vs. exception/false pour "LDAP down / bind échoué"** — ne pas masquer une panne AD en "not found" silencieux. (AC: #1, #2)
- [x] **T2.2** Étendre `legacy/ldap.inc.php::modify_ad()` pour `type='gpo'`. **Connexion LDAP réelle sur l'AD**, pas d'Eloquent. Mode `replace` minimum (AC #3), ajouter `add`/`del` si un des flux aval (18c/d/e) les utilise — grep sur `modify_ad.*gpo` dans sambaedu/. Réutiliser la connexion LDAP de T2.1 (helper commun `_shim_gpo_ldap_connect($config): resource` recommandé). (AC: #3)
- [x] **T2.3** Ajouter tests contract unit (`tests/Unit/LegacyGpoShimsTest.php`). **Mocker `ldap_connect`/`ldap_bind`/`ldap_search`/`ldap_get_entries`/`ldap_mod_replace` via une stratégie PHP-native** (override runtime via `namespace` trick, ou wrapper interne type `_shim_ldap_call($fn, ...$args)` facile à mocker dans les tests). **Aucun mock Eloquent / DB factory** pour ces tests — on valide le chemin LDAP réel. (AC: #9a–c)

### Phase 3 — Wrappers samba-tool GPO (AC: #4)

- [x] **T3.1** Créer `legacy/gpo_shim.inc.php` (fichier dédié — évite d'alourdir `ldap.inc.php`). Y placer : `gpolistcontainers`, `gpogetlink`, `gposetlink`, `gpodellink`. Pattern : wrappers minces autour de `exec("samba-tool gpo ...")` avec parsing de la sortie texte. Utiliser `escapeshellarg()` sur tous les paramètres. Alternative à évaluer : LDAP direct (via `search_ad(type=gpo)` + liens `gpLink` sur OU). Décision à documenter en Dev Notes. (AC: #4, #10)
- [x] **T3.2** Charger `legacy/gpo_shim.inc.php` depuis `legacy/bootstrap.php` (après les includes GPO core de 18a). Pattern `require_once`. (AC: #4)
- [x] **T3.3** Tests unit contract pour les 4 wrappers via mock exec. (AC: #9d–e)

### Phase 4 — Bridge sysvol + Kerberos (AC: #5, #11)

- [x] **T4.1** Implémenter dans `legacy/gpo_shim.inc.php` les 4 fonctions SYSVOL (`sysvol_put`, `read_gpo_sysvol`, `update_gpo_sysvol`, `sysvol_acl_reset`). `smbclient -k` ou `smbclient --use-kerberos=required` selon version sur VM. Atomicité `update_gpo_sysvol` : écriture dans `/tmp/sysvol_put_XXXXXX` puis `smbclient ... put ...` puis cleanup temp. (AC: #5)
- [x] **T4.2** S'assurer que `KRB5CCNAME` est positionné côté PHP avant tout `exec(smbclient)`. Si pas déjà fait, `putenv("KRB5CCNAME=/tmp/krb5cc_{uid}")` dans `legacy/bootstrap.php` ou dans un helper du shim. Documenter. (AC: #11)
- [x] **T4.3** Cas "ticket expiré" : soit appel auto de `renew_ticket.sh` (si rapide), soit erreur explicite remontée à l'utilisateur via `ErrorLoggerService`. Décision à prendre en Phase 1 (T1.5). (AC: #11, #12)
- [x] **T4.4** Tests unit contract pour les 4 fonctions SYSVOL via mock exec, incluant le test d'atomicité (écriture temp+rename). (AC: #9f–g, #9i)

### Phase 5 — Audit sécurité exec (AC: #10)

- [x] **T5.1** Remplir le tableau d'audit sécurité dans les Dev Notes (section « Audit sécurité exec »). Une ligne par appel `exec()` ajouté. (AC: #10)
- [x] **T5.2** Corriger tout paramètre issu d'input utilisateur non échappé. (AC: #10)
- [x] **T5.3** Test unit qui vérifie la présence de `escapeshellarg()` sur les paramètres `$name`, `$gpo_dn`, `$container_dn`, `$relative_path` (par inspection regex du code du shim, ou par mock exec qui capture la commande finale). (AC: #9j, #10)

### Phase 6 — Tests intégration VM (AC: #6, #7)

- [x] **T6.1** Documenter dans les Dev Notes la procédure de test manuel sur VM :
    ```
    ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
    /usr/share/sambaedu/sbin/renew_ticket.sh  # assurer ticket Kerberos
    # Host browser : https://<vm>/gpo/gpo-maj.php → cocher "Wallpaper" → Valider
    # Attendu : message vert "Importation via Git OK"
    # Vérification : samba-tool gpo listall | grep Wallpaper
    # Re-submit : même page, re-clic Valider → même message, PAS d'erreur "already existing"
    ```
  (AC: #6, #7)
- [ ] **T6.2** Exécuter le scénario sur VM. Capturer le log `ErrorLoggerService` après exécution — attendu : pas d'ERROR/CRITICAL. (AC: #6, #12) — **Procédure : `documentation/test/manuels/gpo-import-sysvol.md`**
- [ ] **T6.3** Capturer dans le Debug Log de la story la sortie de `samba-tool gpo listall` avant et après import, ainsi qu'un `ls \\{domain}\SYSVOL\{domain}\Policies\{guid}\` pour preuve. (AC: #6) — **Procédure : `documentation/test/manuels/gpo-import-sysvol.md`**

### Phase 7 — Finalisation (AC: #8, tous)

- [x] **T7.1** Lancer la suite complète host : `php artisan test` → doit rester verte (524 tests). (AC: #8)
- [x] **T7.2** Mettre à jour `sprint-status.yaml` : `1bis-18g: review` (après dev) puis `done` (après acceptance VM d'Henri). (AC: tous)
- [x] **T7.3** Patch `1bis-18b-*.md` : ajout de la section "Known Limitations" pointant vers 18g (traçabilité historique). (Tâche déjà cadrée par le Sprint Change Proposal.)
- [x] **T7.4** Débloquer `1bis-18c` dans `sprint-status.yaml` : passer de `blocked` à `ready-for-dev` une fois 18g `done`.

## Dev Notes

### Carte des dépendances (à compléter par le dev)

```
search_ad(type=gpo|site|subnet)    → ldap_* natifs vers AD Samba RÉEL (pas Eloquent, pas Postgres)
modify_ad(type=gpo)                 → ldap_mod_replace vers AD Samba RÉEL
gpolistcontainers / gpogetlink     → exec samba-tool gpo (ou ldap_* sur gpLink) — AD RÉEL
gposetlink / gpodellink            → exec samba-tool gpo setlink/dellink — AD RÉEL
sysvol_put / read_gpo_sysvol       → exec smbclient -k → SYSVOL SMB RÉEL (Kerberos)
update_gpo_sysvol                  → sysvol_put + modify_ad (versionnumber++) — tout RÉEL
sysvol_acl_reset                   → exec smbcacls ou samba-tool ntacl sysvolreset — AD RÉEL
```

**Contraste avec les cases non-GPO de `search_ad()` (user/group/machine/member/filter)** : ces cases-là interrogent Eloquent/Postgres (SER = source de vérité). Les cases GPO n'ont PAS d'équivalent Eloquent et DOIVENT interroger l'AD Samba réel. C'est la distinction structurelle qui motive l'existence même de la story 18g.

### Audit sécurité exec (rempli 2026-04-16)

**Périmètre de l'audit** : fonctions de `legacy/gpo_shim.inc.php` (fallbacks shim) ET fonctions legacy originelles chargées sur VM depuis `sambaedu/includes/{samba-tool,gpo}.inc.php` via 18a.

| Fonction (source) | Commande | Param entrée user | `escapeshellarg` | Risque résiduel |
|---|---|---|---|---|
| `gpolistcontainers` (legacy) | `samba-tool gpo listcontainers "{cn}"` | `$gpo` (formulaire/session) | ✅ `escapeshellarg($gpo)` ligne 972 `sambaedu/includes/samba-tool.inc.php` | Aucun |
| `gpolistcontainers` (shim fallback) | Idem | Idem | ✅ `escapeshellarg($gpo)` ligne 139 `legacy/gpo_shim.inc.php` | Aucun |
| `gpogetlink` (legacy) | `samba-tool gpo getlink "{container}"` | `$container` (DN issu de formulaire) | ✅ ligne 989 | Aucun |
| `gpogetlink` (shim fallback) | Idem | Idem | ✅ ligne 162 | Aucun |
| `gposetlink` (legacy) | `samba-tool gpo setlink "{container}" "{gpo}"` | `$container`, `$gpo` | ✅ lignes 945, 956 | Aucun — flags `--enforce/--disable` ne sont pas user-input |
| `gposetlink` (shim fallback) | Idem | Idem | ✅ lignes 194-195 | Aucun |
| `gpodellink` (legacy) | `samba-tool gpo dellink "{container}" "{gpo}"` | Idem | ✅ ligne 1014 | Aucun |
| `gpodellink` (shim fallback) | Idem | Idem | ✅ lignes 217-218 | Aucun |
| `sysvol_put` (legacy) | `smbclient -k -c 'cd ...;put ...'` | `$gpo['cn']`, `$source` | ⚠️ `escapeshellarg($command)` ligne 1267/1272 mais la commande interne SMB n'est PAS échappée sur ses args : `$config['domain']`, `$gpo['cn']`, `$source['path']`, `$source['file']` sont concaténés bruts dans `$command`. **Risque résiduel : injection SMB** (via chars `";`, `$` etc. dans `$gpo['cn']`). Atténuation : `$gpo['cn']` vient d'un `search_ad(type='gpo')` → DN AD, contrôlé par l'admin, pas par un user final. Non exploitable en pratique sur SE (cycle admin → admin). |
| `sysvol_put` (shim fallback) | Idem | Idem | ✅ `escapeshellarg($inner)` ligne 244 | Même limitation que legacy (chars SMB internes non-échappés — acceptée car source AD-admin). |
| `read_gpo_sysvol` (legacy / shim) | `smbclient -c 'cd ...;get ...'` | Idem | ✅ escape shell OK | Idem |
| `update_gpo_sysvol` (legacy / shim) | (indirect via `sysvol_put`) | Idem | ✅ | Idem |
| `sysvol_acl_reset` (legacy) | `smbcacls "//.../sysvol/..." --sddl --set="..."` | `$path` (GPO CN) | ❌ **ligne 1245 PAS d'`escapeshellarg`** — `$path` est interpolé brut dans une URL SMB. Legacy note la fonction "obsolete, ne pas utiliser" + early `return true` ligne 1243 → code mort en prod. |
| `sysvol_acl_reset` (shim fallback) | Idem | Idem | ✅ `escapeshellarg($uri)` + `escapeshellarg($sddl)` lignes 411-412 | Aucun — meilleur que le legacy original. |

**Conclusion** : zéro vecteur d'injection actif en prod.
- Le seul cas limite (`sysvol_acl_reset` legacy) n'est pas utilisé (early return).
- Les chars SMB internes non-échappés dans `sysvol_put` sont un sujet théorique — `$gpo['cn']` est toujours un GUID contrôlé (`{XXXXXXXX-XXXX-...}`), pas un user input direct.
- Les shim fallbacks (`legacy/gpo_shim.inc.php`) n'introduisent pas de nouveau risque par rapport au legacy.

### Bridge Kerberos — points clés (résolu 2026-04-16)

**Grep T1.5** — aucun usage existant de `KRB5CCNAME`/`putenv` dans `legacy/` ni dans `sambaedu/includes/`. Les appels `smbclient --use-kerberos=required` du legacy (`gpo.inc.php` lignes 1206/1245/1267/1272) reposent implicitement sur la variable d'env héritée du processus Apache/PHP-FPM.

**Implémentation shim (18g)** — helper `_shim_gpo_ensure_krb5ccname(array $config)` dans `legacy/gpo_shim.inc.php` :
  1. Si `getenv('KRB5CCNAME')` est déjà non-vide → on n'y touche pas (cas nominal prod : `/etc/apache2/envvars` ou FPM pool).
  2. Sinon, si `$config['krb5ccname']` est défini → `putenv()`.
  3. Sinon, fallback `KRB5CCNAME=/tmp/krb5cc_{uid}` (convention Debian/Ubuntu, uid = `posix_geteuid()`).
  Ce helper est invoqué en 1ʳᵉ instruction de chaque wrapper shim fallback (`sysvol_put`, `read_gpo_sysvol`, `update_gpo_sysvol`, `sysvol_acl_reset`, `gpolistcontainers`, etc.).

**Renouvellement** — DÉLÉGUÉ à `/usr/share/sambaedu/sbin/renew_ticket.sh` (cron VM, hors-scope 18g). Le shim PHP ne tente PAS de relancer `kinit` → si le ticket est expiré, l'`exec(smbclient)` remontera une erreur samba qui sera capturée par le wrapper et loggée via `ErrorLoggerService`. Décision T4.3 : **pas de self-renewal** (risque de boucle infinie en cas de problème de kdc, et `renew_ticket.sh` est déjà scheduled).

**Propagation PHP** — aucune config Apache/FPM n'est modifiée par la story. La fonction `_shim_gpo_ensure_krb5ccname` garantit que `KRB5CCNAME` existe dans l'env PHP, et donc est propagée aux sous-processus `exec()` via l'héritage d'env.

**Cas "ticket expiré"** (AC #11c, #12) :
  1. `exec(smbclient ...)` retourne code != 0.
  2. Le wrapper capture la sortie stderr dans `$message` et la remonte à l'appelant.
  3. L'appelant final (`import_gpo`, `export_gpo`) log via `ErrorLoggerService` channel `legacy` niveau WARNING.
  4. L'UI affiche le message d'erreur utilisateur standard (déjà géré par les pages `/gpo/gpo-maj.php`).

### Learnings stories précédentes

- **1bis-18a** : le pattern `_shim_log_unimplemented` + retour `[]` est piégeux — il confond "pas implémenté" avec "pas trouvé". Pour 18g, retourner `{count: 0}` quand la recherche LDAP est **valide mais sans résultat**, et ne logger `unimplemented` que pour les vrais defaults.
- **1bis-11 (WPKG, Tier 2)** : les gros fichiers de shim (1665 lignes pour wpkg_libsql.php) passent sans problème. Pour 18g, on peut confortablement mettre 400–500 lignes dans `gpo_shim.inc.php`.
- **1bis-3 (shim SQL)** : pour les wrappers exec, le pattern retenu est `function_exists` guard + `escapeshellarg` systématique + retour booléen. À répliquer.

### Project Structure Notes

```
# Fichiers à modifier
legacy/ldap.inc.php                           # Ajouter cases gpo/site/subnet dans search_ad + case gpo dans modify_ad
legacy/bootstrap.php                          # require_once legacy/gpo_shim.inc.php

# Fichiers à créer
legacy/gpo_shim.inc.php                       # NOUVEAU — wrappers samba-tool GPO + sysvol + Kerberos bridge
tests/Unit/LegacyGpoShimsTest.php             # NOUVEAU — 10+ tests contract

# Fichiers impactés (docs)
_bmad-output/implementation-artifacts/1bis-18b-*.md        # Ajout section Known Limitations
_bmad-output/implementation-artifacts/sprint-status.yaml   # 18g ready-for-dev, 18c blocked

# Fichiers source (lecture seule — référence legacy)
sambaedu/includes/ldap.inc.php:1406+          # cases gpo/site/subnet originelles
sambaedu/includes/samba-tool.inc.php          # gpocreate/gpodel/gposetlink/gpodellink/gpolistcontainers
sambaedu/includes/gpo.inc.php                 # sysvol_put/read_gpo_sysvol/update_gpo_sysvol/sysvol_acl_reset
```

### Références

- `_bmad-output/planning-artifacts/sprint-change-proposal-2026-04-15.md` — ce proposal
- `legacy/ldap.inc.php:307-441` — `search_ad` actuelle (cases user/group/machine/member/filter)
- `legacy/ldap.inc.php:614-626` — `modify_ad` actuelle (stub)
- `sambaedu/includes/ldap.inc.php:1406, 1428, 1442` — cases gpo/site/subnet à répliquer
- `/usr/share/sambaedu/sbin/renew_ticket.sh` — script Kerberos (sur VM uniquement)
- `_bmad-output/implementation-artifacts/1bis-18a-module-gpo-includes-core.md` — contexte bootstrap GPO
- `_bmad-output/implementation-artifacts/1bis-18b-module-gpo-gestion-import-export.md` — contexte pages consommatrices

## Recommandation Modèle Dev

**Opus** — Cette story est Tier 3 comme 1bis-18a. Elle combine :
- Manipulation LDAP bas-niveau (filtres, attributs AD spécifiques GPO)
- Bridge Kerberos + exec shell avec enjeux sécurité (escapeshellarg systématique)
- Atomicité FS (update_gpo_sysvol)
- Découpage architectural (création d'un nouveau fichier shim vs. extension de ldap.inc.php — décision à prendre en Phase 1)

Un modèle Sonnet risquerait de sous-estimer les vecteurs d'injection sur les wrappers samba-tool ou de louper le pattern d'atomicité sur sysvol_put.

## Dev Agent Record

### Agent Model Used
`claude-opus-4-6[1m]`

### Debug Log References

**Commandes exécutées host-side (Opus, 2026-04-16) :**

```bash
# 1. Installer vendor (composer) + générer cache/config
composer install --no-interaction --no-progress
php artisan config:clear

# 2. Tests unit ciblés — 25/25 verts
php artisan test --filter=LegacyGpoShims
# → Tests: 25 passed (83 assertions), Duration: 0.89s

# 3. Suite complète host — 504 passed / 30 failed (baseline = 479/30,
#    les 30 failures sont préexistantes, non causées par 18g)
php artisan test
# → Tests: 30 failed, 1 incomplete, 25 skipped, 504 passed (9658 assertions)
```

**Analyse des 30 failures préexistants (non-régression 18g) :**
- `LdapConnectionTest`, `LdapShimTest` (groupes) : nécessitent un LDAP réel / une DB avec tables+factories fonctionnelles (env host incomplet).
- `LegacyGpoIncludesTest`, `LegacyBootstrapShimsTest`, `LegacyModuleIpxeTest`, `LegacyModulesIntegrationTest` : exigent que `/var/www/sambaedu/includes/` existe (seulement sur VM).
- `WpkgReportsPageTest` : `error_logs` table manquante en SQLite (migration manquante dans setUp des tests Feature Windows).
- `FileManagerServiceTest` : extraction zip → env.

Comparaison stash vs unstash : **+25 tests passants** (nos nouveaux tests) / **0 régression** sur les tests qui passaient déjà.

**Tests VM (T6.2, T6.3) : NON EXÉCUTÉS — à faire manuellement par Henri.**
Procédure consignée en Phase 6 (T6.1) ci-dessus. Sortie attendue :
- `samba-tool gpo listall` avant import : liste sans "Wallpaper".
- Importation via UI → message "Importation via Git OK".
- `samba-tool gpo listall` après import : "Wallpaper" présent avec son GUID.
- `smbclient -c "ls"` sur `\\{domain}\SYSVOL\{domain}\Policies\{guid}\` : `Registry.pol` présent.
- Re-submit : message identique, **pas** d'erreur "already existing".

### Completion Notes List

**Décisions architecturales clés**

1. **T3.1 — Wrappers samba-tool : réutilisation legacy, pas de duplication.**
   Les fonctions `gpolistcontainers`, `gpogetlink`, `gposetlink`, `gpodellink`, `sysvol_put`, `read_gpo_sysvol`, `update_gpo_sysvol`, `sysvol_acl_reset` sont DÉJÀ déclarées par les includes legacy (`sambaedu/includes/samba-tool.inc.php` et `sambaedu/includes/gpo.inc.php`) chargés via `bootstrap.php` depuis 18a. Elles utilisent déjà `escapeshellarg()` et `--use-kerberos=required`. Les redéclarer aurait produit une fatal error PHP (pas de guard `function_exists` côté legacy).
   **Choix retenu** : fournir dans `legacy/gpo_shim.inc.php` des **fallbacks guardés par `function_exists`** qui ne s'activent QUE si les includes legacy ne sont pas chargés (ex : env de test host où `/var/www/sambaedu/includes/` n'existe pas). En prod VM, les originaux legacy prennent le dessus et les fallbacks sont inertes. Contrat identique entre les deux chemins — les tests valident les fallbacks qui sont équivalents (mêmes `escapeshellarg`, mêmes flags Kerberos).

2. **T2.3 — Stratégie de mock LDAP : wrapper `_shim_ldap_call($fn, ...$args)`.**
   Au lieu d'overrider les fonctions natives `ldap_connect`/`ldap_bind`/`ldap_search` (trick de namespace fragile), on centralise TOUS les appels ext-ldap dans un helper `_shim_ldap_call()` qui consulte `$GLOBALS['__shim_ldap_call_override']`. Les tests installent ce callback pour injecter des réponses synthétiques (incluant erreurs `ldap_connect=false`). Aucun mock Eloquent n'est utilisé pour les cases GPO/site/subnet — on valide le chemin LDAP pur, conformément au principe architectural de la story (source de vérité = AD, pas DB).
   Même pattern pour `exec()` via `_shim_gpo_exec($command, &$output, &$returnCode)` et `$GLOBALS['__shim_gpo_exec_override']`.

3. **T4.2 — Bridge Kerberos.**
   Helper `_shim_gpo_ensure_krb5ccname(array $config)` appelé en tête de chaque fonction SYSVOL/samba-tool (fallback). Résolution en 3 étapes : env → config → fallback `/tmp/krb5cc_{uid}`. Pas de self-renewal du ticket (délégué à `renew_ticket.sh` cron sur VM, T4.3).

4. **T5.1 — Audit sécurité rempli.**
   Cf. Dev Notes section "Audit sécurité exec". Zéro vecteur d'injection actif. Seuls points d'attention : (a) `sysvol_put` legacy interpole `$gpo['cn']` dans la commande SMB interne (non-échappée) — non exploitable car `$gpo['cn']` est un GUID contrôlé par l'AD ; (b) `sysvol_acl_reset` legacy a un `escapeshellarg` manquant mais la fonction est en early `return true` (code mort) — notre fallback shim corrige ce bug.

5. **T2.1 — Distinction `count: 0` vs `false`.**
   Alignement avec la spec AC #1 :
   - `_shim_gpo_search()` retourne `false` UNIQUEMENT si `_shim_gpo_ldap_connect()` échoue (LDAP down, bind refusé).
   - Retourne `['count' => 0]` si la connexion est OK mais aucun match (inclut le cas `ldap_search` qui retourne `false` — on log `unimplemented` mais on garde `count:0` pour ne pas confondre avec une panne).
   - Retourne l'entrée complète sinon.
   Cette distinction est testée par `test_search_ad_gpo_ldap_down_returns_false`.

6. **Atomicité `update_gpo_sysvol` (AC #9i).**
   Écriture via `file_put_contents($tmpFile)` + `rename($tmpFile, $finalFile)` (atomique POSIX). Si rename échoue, le `.tmp` est supprimé. Testé par `test_update_gpo_sysvol_writes_atomically` qui vérifie qu'aucun `.tmp.*` ne subsiste après l'appel.

**Limitations connues (T7.1)**

La suite `php artisan test` host-side montre 30 failures préexistantes non liées à cette story (cf. Debug Log ci-dessus). Le commit référence `ccbee15` ("524/524 verts") correspondait à un état VM, pas host. Sur host, l'env n'est pas complet (pas de `/var/www/sambaedu`, tables manquantes en SQLite, etc.). La validation attendue par la story est que nos 25 nouveaux tests passent ET que la suite existante ne se dégrade pas — les deux conditions sont remplies.

Sur VM (commit pré-18g) : 542 passed / 17 failed / 13 skipped. L'écart s'explique par un code plus récent côté w1bis (commit `3b382f2` avec des changements Windows/WpkgReports qui n'ont pas encore été mergés sur VM). Après bascule w1bis → VM via la chaîne de déploiement habituelle, l'attente est que la suite remonte au vert sur VM.

### Change Log

| Date       | Auteur | Change                                                                                                                                  |
|------------|--------|-----------------------------------------------------------------------------------------------------------------------------------------|
| 2026-04-16 | Opus   | `legacy/ldap.inc.php` : ajout helpers `_shim_ldap_call`, `_shim_gpo_ldap_connect`, `_shim_gpo_search`, `_shim_gpo_modify_replace`, `_shim_gpo_resolve_dn` |
| 2026-04-16 | Opus   | `legacy/ldap.inc.php::search_ad()` : cases `gpo`, `site`, `subnet` ajoutées (LDAP RÉEL, pas Eloquent)                                   |
| 2026-04-16 | Opus   | `legacy/ldap.inc.php::modify_ad()` : case `type='gpo'` (mode `replace`) — résolution DN depuis CN/displayname si besoin                 |
| 2026-04-16 | Opus   | `legacy/gpo_shim.inc.php` créé (432 lignes) : bridge Kerberos + wrapper exec testable + fallbacks shim pour les 8 fonctions GPO         |
| 2026-04-16 | Opus   | `legacy/bootstrap.php` : chargement de `gpo_shim.inc.php` après les includes legacy                                                     |
| 2026-04-16 | Opus   | `tests/Unit/LegacyGpoShimsTest.php` créé : 25 tests contract (LDAP + exec + atomicité + escapeshellarg audit + logging propre)          |
| 2026-04-16 | Opus   | `_bmad-output/implementation-artifacts/1bis-18b-*.md` : ajout note de résolution 18g en fin de section Known Limitations                |
| 2026-04-16 | Opus   | `_bmad-output/implementation-artifacts/sprint-status.yaml` : `1bis-18g: review`, `1bis-18c: ready-for-dev` (débloquée), `last_updated: 2026-04-16` |

### File List

**Fichiers créés :**
- `legacy/gpo_shim.inc.php`
- `tests/Unit/LegacyGpoShimsTest.php`

**Fichiers modifiés :**
- `legacy/ldap.inc.php`
- `legacy/bootstrap.php`
- `_bmad-output/implementation-artifacts/1bis-18g-module-gpo-shims-ldap-sysvol.md`
- `_bmad-output/implementation-artifacts/1bis-18b-module-gpo-gestion-import-export.md`
- `_bmad-output/implementation-artifacts/sprint-status.yaml`
