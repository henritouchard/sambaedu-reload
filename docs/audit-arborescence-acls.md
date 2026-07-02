# Audit — Arborescence & droits POSIX : couverture legacy → refonte

> **But du document.** Répondre à la question « a-t-on pris en compte toute l'arborescence
> et comment mettre les bons droits sur les bons dossiers/fichiers ? », inventorier les
> écarts, et documenter ce qui a été livré pour les combler.
>
> Analyse conduite **sur le code** (legacy `sambaedu/` vs refonte `sambaedu-reload/`), pas
> sur l'état réel d'une VM. Une passe d'audit du parc réel reste possible via
> `php artisan shares:inspect-fs <chemin>` (voir §5).

---

## 1. Réponse courte

**Non, la refonte ne porte pas *toute* l'arborescence legacy — en partie par choix, en partie par trous désormais comblés.**

- **Choix assumé** : le legacy éparpillait la création de dossiers dans ~10 includes avec des
  ACL ad-hoc, plus un éditeur ACL manuel par-dossier (`acls/`). La refonte remplace ça par une
  poignée de **provisionneurs canoniques desired-state** (SQL autoritaire, FS projeté).
- **Deux arbres porteurs sont couverts fidèlement** : partages de **classe** et **homes**.
- **Les trous critiques identifiés ont été traités** dans cette itération (§4), sauf un
  volontairement écarté après investigation (§3, profils itinérants).

---

## 2. Matrice de couverture

| Arbre legacy | Droits legacy | Refonte | Verdict |
|---|---|---|---|
| `Classes/Classe_<nom>/` (+ `_travail` `_profs` `_echange` `<élève>` `Archives`) | `equipe_<cls>:rwx/rx`, `classe_<cls>:rx`, `user:<élève>:rwx`, `domain admins:rwx` | `ShareService` | ✅ Porté 1:1 (suffixe établissement fédéré géré) |
| `/home/<login>` (+ skel, trash) | `mkhomedir 007`, `chgrp www-admin` | `HomeDirService` | ✅ Couvert |
| `Partages/<nom>` (nouveau modèle managé) | — | `NetworkShareService` | ✅ Nouveau socle desired-state |
| **`_travail/devoirs`** (dépôt de devoirs) | `chown <prof>` (mono-prof) | `ShareService` (**ajouté**, §4.C) | ✅ Comblé (ACL équipe multi-prof) |
| **`Docs` / `Progs`** établissement | ACL par sous-dossier (`public`, `ro`/`rw`) | `shares:seed-etablissement` (**ajouté**, §4.B) | ⚠️ Comblé en modèle **plat** (sous-structure non reproduite) |
| **Deprovision** (suppression lecteur) | — (aucun) | `NetworkShareService::deprovision` (**ajouté**, §4.A) | ✅ Comblé (+ **fix sécurité**) |
| **Profils itinérants** (`/home/profiles/*.V6`) | `repair_profiles` (ACL NTUSER.DAT) | `RoamingProfileService` (scan/purge) | 🚫 Repair **non porté** — voir §3 |
| `Docs/public` (world-writable) | `other::rwx` | — | 🚫 Non porté (footgun ; modèle force `other:---`) |
| SYSVOL / netlogon (FS) | via `smbclient`/GPO | shim legacy + checks Doctor | ✅ Hors périmètre FS-ACL (par design) |
| Print$ / scan / spool | `rest_rights.sh -p` | — | ❌ Hors périmètre (autres epics) |

---

## 3. Investigation « itinérant » — le point qui a fait bouger la décision

La question « l'itinérant existe mais n'a pas de profil, est-ce normal ? » se résout par une
**homonymie** : « itinérant » recouvre **trois** notions distinctes.

| « Itinérant » | Ce que c'est | A un profil ? |
|---|---|---|
| **Profil itinérant** (roaming) | Magasin serveur réel `/home/profiles/<user>.V6` (NTUSER.DAT) | **Oui** — mais SambaEdu **ne pose jamais `profile path`** (piloté Windows/GPO) |
| **Poste nomade / perdir** | Classe de poste « tout local » (`WorkstationEnvironment::Nomade`) | **Non** — bureau + données en `%USERPROFILE%` |
| **Utilisateur itinérant** | Compte externe/fédéré d'un autre établissement | **Non** — juste une règle de quota `default_itinerant` |

Points clés :

- SambaEdu **ne configure pas** le round-trip du profil (aucune directive `profile path` dans
  la génération smb.conf, 0 occurrence legacy). Le vrai coffre des données est le **home réseau
  redirigé** `\\<se4fs>\users\<user>\` (GPO `redirections` + `ExcludeProfileDirs`), déjà couvert
  par `HomeDirService`.
- `RoamingProfileService` fait de la **maintenance** du magasin (scan `du`, purge des orphelins
  vers corbeille, exclusions GPO), pas du roaming.

**Décision #2 — réparation des ACL de profil itinérant : NON portée (intentionnel).** C'est un
mécanisme **secondaire** dont les données ne sont pas critiques, et le geste legacy (chirurgie
ACL sur `NTUSER.DAT`) est **superseded** par le pattern SE5 déjà en place : purger le `.V6`
corrompu → Windows recrée un profil sain au logon suivant (`purgeOrphanProfiles`). Porter la
réparation in-place ajouterait de la dette sur un chemin que SE5 ne maintient que défensivement.

---

## 4. Ce qui a été livré dans cette itération

Tout est testé (unitaires `Process::fake`, aucune écriture FS réelle) et fail-soft.

### 4.A — Deprovision / nettoyage (fix sécurité)

**Problème** : `deleteShare()` retirait la ligne SQL mais **laissait le dossier + ses ACL** sous
`/var/sambaedu/Partages`, exposé en entier par le share SMB `[partages]` → un lecteur « supprimé »
restait atteignable en UNC avec ses grants (fuite de contrôle d'accès).

**Livré** : `NetworkShareService::deprovision()` — séquence data-safe et idempotente :
1. `setfacl -R -P -b` (révoque tous les grants) ;
2. `chmod -R 0770` (retire l'accès `other` résiduel du mode de base) ;
3. `mv` vers `Partages/.trash/<name>-<id>` (poubelle `0700 www-admin`, non listable).

Câblé dans `deleteShare()` (UI) **avant** la suppression SQL. Audité (`deprovision_share`).

### 4.B — Docs / Progs établissement

**Livré** : `php artisan shares:seed-etablissement [--apply]` — crée `Documents` et `Progs` comme
**lecteurs managés plats** (idempotent, dry-run par défaut), **sans audience** (l'admin assigne
la politique ensuite ; sûr par défaut : `domain admins` seuls tant que rien n'est assigné).

**Limite assumée** : le modèle managé est plat (un dossier, une audience, `ro|rw`). Il ne
reproduit **pas** la sous-structure legacy à ACL hétérogènes (`Docs/public`, `Progs/ro`+`rw`). Si
le découpage ro/rw est requis → créer deux lecteurs distincts. `Docs/public` (world-writable) est
volontairement abandonné.

### 4.C — Dépôt de devoirs

**Livré** : `ShareService::createClassShare()` crée désormais `_travail/devoirs/` avec l'ACL de
`_travail` (équipe pédagogique écrit, élèves lisent). Comble le gap legacy `find_devoirs()`, mais
en modèle **multi-enseignant** (ACL `equipe_<classe>`) au lieu du `chown <prof>` mono-enseignant.

**Limite assumée** : seul le **dossier de dépôt** est garanti. Le **workflow de collecte** des
copies rendues (récupération, `liste_devoirs`) reste une feature à concevoir.

### 4.D — Outillage transverse (itérations précédentes, rappel)

- `shares:inspect-fs` — inspection read-only + classification des ACL d'un dossier legacy.
- `shares:import-from-fs` — import one-shot FS→SQL (dry-run par défaut, fail-closed).
- `NetworkShareService::computeDrift()` + UI « Resynchroniser » + Doctor `NetworkShareAclDriftCheck`
  — audit de dérive désiré-vs-effectif.

---

## 5. Prérequis prod & suites

### ⚠️ Sudoers (bloquant pour `deprovision`)

`deprovision` utilise `chmod`, **absent** de la whitelist actuelle. Étendre
`/etc/sudoers.d/sambaedu` :

```
www-data ALL=(root) NOPASSWD: /usr/bin/setfacl, /usr/bin/getfacl, /bin/mkdir, /bin/mv, /bin/chown, /bin/chgrp, /bin/chmod
```

(`setfacl`/`getfacl`/`mkdir`/`mv`/`chown`/`chgrp` déjà présents ; seul **`/bin/chmod`** est nouveau.)

### Ancrer l'audit sur le parc réel

L'analyse ci-dessus est théorique (code). Pour la confronter au réel :

```bash
php artisan shares:inspect-fs /var/sambaedu/Docs
php artisan shares:inspect-fs /var/sambaedu/Progs
php artisan shares:inspect-fs /var/sambaedu/Classes/Classe_<xxx>
```

→ liste ce qui existe vraiment (ACL présentes) et ce qui serait importable / non-mappable.

### Reste ouvert (non traité, par décision ou périmètre)

- Workflow de **collecte** de devoirs (récupération des copies).
- Sous-structure **`Docs/public` / `Progs` ro-rw** (si un besoin réel émerge).
- **Réparation** de profils itinérants (écartée — cf. §3).
- **Print$ / scan / spool** (autres epics).
