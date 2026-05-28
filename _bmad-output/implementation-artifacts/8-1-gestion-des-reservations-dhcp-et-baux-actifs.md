# Story 8.1 : Gestion des Réservations DHCP et Baux Actifs

Status: done

> **Story Epic 8 #1** — Refonte native de la gestion DHCP (FR20 + FR22).
> Première et **seule** story de l'Epic 8 prévue à ce stade : remplace le shim legacy `legacy/modules/dhcp/` (Story 1bis.16 ✅) par une UI Laravel/Livewire native, un `DhcpService` typé, et un import CSV en masse avec rapport d'erreurs.
>
> **FR21 (DNS) — Décision SM 2026-05-11** : reporté hors scope de cette story. L'investigation legacy (cf. § Investigation Legacy ci-dessous) confirme que le DNS est manipulé **uniquement** via `samba-tool dns add/delete` côté Samba AD DC (fonctions `dns_add`/`dns_delete` dans `sambaedu/includes/samba-tool.inc.php`), et que la mise à jour DNS automatique post-réservation DHCP est **optionnelle** (déclenchée par `dnsupdate.php` en POST depuis l'UI legacy). FR21 fera l'objet d'une story Epic 8.2 dédiée si le besoin métier de CRUD DNS manuel se confirme. **Cette story 8.1 ne fait aucune mise à jour DNS automatique** — parité fonctionnelle stricte avec le legacy : pas de régression, pas de feature add.

---

## Story

As a **responsable de collège**,
I want consulter la liste des réservations DHCP existantes, voir les baux DHCP actifs (clients ayant obtenu une IP dynamique), créer / modifier / supprimer des réservations individuelles, et importer des réservations en masse depuis un fichier CSV avec un rapport ligne-à-ligne des succès et erreurs,
So que chaque machine du parc dispose d'une adresse IP fixe sans manipulation système (édition manuelle `dhcpd.conf`, redémarrage `isc-dhcp-server` en SSH) — l'UI SER pilote le serveur DHCP de façon autonome.

---

## Contexte

### Cadre Epic

L'Epic 8 « Réseau (DHCP/DNS) SER » couvre les FR20 (réservations + baux), FR21 (DNS, **reporté**) et FR22 (import masse). Le shim legacy `legacy/modules/dhcp/` (Story 1bis.16 ✅) reste opérationnel via le catchall Laravel le temps de la livraison de cette story 8.1. Après livraison, une bascule (catchall → route native) sera arbitrée (similaire à la trajectoire `printers` / `gpo`).

### Pourquoi maintenant

- Le shim 1bis.16 reproduit la dette UX legacy (pages `baux.php` / `config.php` mal alignées avec la nav SER, layout HTML legacy injecté dans le wrapper).
- L'epic 7 (Permissions Spatie) a livré la `DhcpPolicy` (`app/Policies/DhcpPolicy.php`) avec les gates `viewAny-dhcp` et `manage-dhcp` (toutes deux mappées sur `server.admin`) — la chaîne d'autorisation est en place.
- L'Epic 4 a livré le modèle `Workstation` (table `workstations`, colonnes `ip`, `mac`, `name`) — base Eloquent pour relier les réservations aux postes.

### Invariants & Conventions

- **Filesystem-based router** : page sous `resources/views/pages/network/dhcp/`, sous-routes en sous-dossiers (`new/`, `import/`), Livewire SFC pour la partie interactive, partials Blade dans `_partials/`.
- **Modale réutilisable** : pour confirmation suppression et formulaire création/édition, utiliser `<x-molecules.modal>` + déclenchement standard.
- **Toasts** : trait `App\Components\Traits\WithToasts` pour notifier succès / erreur opérations DHCP.
- **NFR18** : aucun appel système direct depuis les SFC Livewire. **Tout** appel `exec()` / `Process::run()` doit transiter par `DhcpService` (`app/Services/Network/DhcpService.php`).
- **Pattern shellout** : aligné sur `App\Services\Print\CupsPrinterService` (Story 6.1) et `App\Services\Filesystem\XfsQuotaService` (Story 5.1a) :
  - `escapeshellarg()` systématique avant `commandRunner->run()`.
  - Capture `stdout` / `stderr` / `returnCode` → exception structurée typée (`DhcpCommandException`, `DhcpDaemonDownException`).
  - Préfixe logs `DhcpService:` (grep opérateurs).
  - `LC_ALL=C` centralisé dans `RealCommandRunner` (réutiliser celui de `Services/Print/`).
- **Format API uniforme** : `{ success: bool, message: string, ...clés métier }`. Pas de wrapper `data:`.
- **Test archi** : tout nouveau code sous `App\Services\Network\*` (services, exceptions, commandes Artisan éventuelles).

---

## Investigation Legacy (réalisée par SM, 2026-05-11)

> Lecture obligatoire mentionnée dans l'epic. Synthèse des findings — les chemins absolus pointent vers le legacy `/home/htouchard/code/irundo/codebase/sambaedu/`.

### Format de configuration DHCP

- **Serveur** : `isc-dhcp-server` (Debian package). Service systemd : `isc-dhcp-server.service`.
- **Fichier de réservations** : `/etc/sambaedu/reservations.inc` — fragment dhcpd au format `host <cn> { hardware ethernet <mac>; fixed-address <ip>; }` inclus dans la conf principale `dhcpd.conf` (source : `sambaedu/includes/ldap.inc.php:4947 export_dhcp_reservations`).
- **Fichier de leases actifs** : `/var/lib/dhcp/dhcpd.leases` (parsé par `sambaedu/includes/ldap.inc.php:4987 import_dhcp_leases`).
- **Génération de `dhcpd.conf`** : script externe `/usr/share/sambaedu/sbin/make_dhcpd_conf.sh` (invoqué via `sudo`), bug legacy connu : chemin `/sh/share/sambaedu/sbin/make_dhcpd_conf.sh` dans `dhcpd_restart()` (`sambaedu/includes/dhcpd.inc.php:733`) — à ne pas reproduire.
- **Source de vérité legacy** : **AD** (`iphostnumber` + `networkaddress` sur les objets machine AD). Le fichier `/etc/sambaedu/reservations.inc` est un **export** dérivé de l'AD via `export_dhcp_reservations()`.

### Commandes de rechargement service DHCP

Identifiées dans `sambaedu/dhcp/baux.php`, `sambaedu/dhcp/config.php` et `sambaedu/includes/dhcpd.inc.php` :

| Action | Commande legacy | Note |
|--------|-----------------|------|
| Status | `sudo systemctl is-active isc-dhcp-server.service` (return code = 0 si actif) + `sudo systemctl status isc-dhcp-server.service` (détails) | `dhcpd.inc.php:709 dhcpd_status` |
| Restart | `sudo /usr/share/sambaedu/sbin/make_dhcpd_conf.sh` (régénère conf + reload) | `config.php:59` — pattern principal |
| Stop | `sudo systemctl stop isc-dhcp-server.service` | `dhcpd.inc.php:743 dhcpd_stop` |
| Purge leases | `sudo systemctl stop … && sudo rm -f /var/lib/dhcp/dhcpd.leases && sudo systemctl start …` | `baux.php:50-52` (action "reinit") |

> **Décision dev** : la story 8.1 ne réimplémente pas la purge des leases (`baux.php` action "reinit"). C'est une action « réparation » rare, hors scope FR20. Si besoin de réparation, le shim 1bis.16 reste accessible via catchall en parallèle.

### Lien réservations ↔ machines de l'inventaire

- **Legacy** : la réservation est une *propriété* de l'objet machine AD (`iphostnumber` + `networkaddress` + `dhcp_state=reservation`). `set_dhcp_reservation($config, $cn, $ip, $mac)` modifie l'objet AD via `modify_ad()` ; l'export DHCP balaye l'AD (`search_ad($config, "*", "machine_fast", "all")`) et émet les lignes `host {...}`.
- **Cible SER (cette story)** : **dissocier** la réservation de l'objet AD. Une table dédiée `dhcp_reservations` (colonne FK nullable `workstation_id` → `workstations.id`) permet :
  - de créer une réservation pour une machine **non encore enregistrée** dans `workstations` (cas import en masse depuis un fichier exporté d'un autre SER).
  - de découpler le cycle de vie : suppression machine AD ≠ suppression réservation (la réservation reste tant qu'elle n'est pas explicitement supprimée).
  - de ne pas dépendre d'écritures AD pour une feature serveur local — aligné NFR « rien d'AD en chemin critique » (Story 15.3).

> **Trade-off accepté** : on s'éloigne du modèle legacy (AD = source de vérité). Justification : (a) le DHCP est un service **local** au serveur SE4FS, pas centralisé ; (b) le legacy lui-même contourne déjà ce couplage via le fichier `reservations.inc` ; (c) la cohérence avec `workstations.ip` est garantie par un observer (sync sortante uniquement à la création de la réservation si la machine existe — pas de cron entrant). Documenter cette décision dans `docs/qa/domains/network.md`.

### Parsing leases DHCP

Le format `/var/lib/dhcp/dhcpd.leases` est un fragment ISC DHCP standard. Parser legacy (`import_dhcp_leases` ldap.inc.php:4987) :

```
lease <ip> {
  binding state active;
  hardware ethernet <mac>;
  client-hostname "<hostname>";
  ...
}
```

Filtres legacy à reproduire :
- Garder uniquement `binding state` ∈ {`active`, `free`}.
- Dédupliquer : conserver uniquement le **dernier** enregistrement par IP (parcours linéaire, écrasement).
- Exclure les baux qui correspondent à une réservation existante (cf. `list_dhcp_leases` ldap.inc.php:5044 — pour éviter d'afficher comme « bail dynamique » une IP en fait réservée).

### Endpoints scripts legacy à NE PAS migrer

- `dnsupdate.php` (mise à jour DNS sur ajout/suppression réservation) → **hors scope** (FR21 reporté).
- `script_make_reservations.php` + `make_reservations.php` (cron qui exporte AD → `reservations.inc`) → **disparaît** : la table `dhcp_reservations` est la source de vérité, l'export est synchrone et déclenché par le service à chaque mutation.
- `import_reservations.php` (import depuis le fichier `reservations.inc.se3` legacy) → **intégré dans cette story** : nouvelle étape 10 dans `/sync-from-ad` qui parse `/etc/sambaedu/reservations.inc` et upsert dans `dhcp_reservations` avec `source='legacy-migration'`. One-shot, idempotente, rejouable. Décision Henri 2026-05-11 (option B). Le format parsé est le fragment dhcpd `host <cn> { hardware ethernet <mac>; fixed-address <ip>; }`. Aucun reload DHCP n'est déclenché par cette étape (lecture seule du fichier conf — on importe, on ne réécrit pas). La story 8.1 livre donc à la fois l'import CSV (FR22) **et** la migration legacy via l'étape 10 de `/sync-from-ad`.

---

## Dépendances

| Story | Titre | Status attendu | Détail |
|-------|-------|----------------|--------|
| Epic 1bis | Cloisonnement legacy | done (1bis-1 à 1bis-16) | `legacy/modules/dhcp/` opérationnel via catchall — reste actif en parallèle pendant le rollout 8.1, sera retiré post-stabilisation (similaire à `printers` post-6.1). |
| 1bis-16 | Module DHCP shimé | done | Backup fonctionnel le temps de la transition. **Ne pas supprimer dans cette story.** |
| Epic 4 | Workstation / WorkstationGroup | done | Modèle `App\Models\Workstation` (`workstations.id`, `name`, `ip`, `mac`). FK `dhcp_reservations.workstation_id` pointe ici. |
| Epic 7 | Permissions Spatie | done | `DhcpPolicy` (`app/Policies/DhcpPolicy.php`) + gates `viewAny-dhcp` / `manage-dhcp` mappés sur `server.admin`. |
| Story 6.1 | CUPS Service | done | **Référence pattern obligatoire** : `App\Services\Print\CupsPrinterService` + `RealCommandRunner` + exceptions typées + `LC_ALL=C` + escapeshellarg(). |
| Story 5.1a | Filesystem refactor | done | Pattern shellout `XfsQuotaService` — reuse de la convention `CommandRunner`. |
| Story 7.2 | Calcul droits Spatie | done | `DhcpPolicy::registerGates()` déjà appelée dans `AuthServiceProvider`. |

Toutes les dépendances sont satisfaites. La story peut être implémentée immédiatement.

> **Note (T8b / AC9)** : la page `/sync-from-ad` (`resources/views/pages/sync-from-ad/index.blade.php`) est déjà livrée par les stories précédentes (Epic 2/4/7). Cette story 8.1 la **modifie** (ajout étape 10) — pas de dépendance bloquante supplémentaire, juste un MODIFY (pas un CREATE).

---

## Décisions SM (figées 2026-05-11, à valider en T0 avec Henri)

### 1. Modèle de données : nouvelle table `dhcp_reservations`

Plutôt que de stocker la réservation sur `workstations` (colonnes `ip` + `mac` déjà présentes), une **table dédiée** est créée :

```
dhcp_reservations
├── id              bigint PK
├── name            string  (cn legacy, lowercased, unique)
├── mac             string  (lower, format XX:XX:XX:XX:XX:XX, unique)
├── ip              inet    (PostgreSQL `inet` ou `string`, indexed, unique)
├── workstation_id  bigint FK nullable → workstations.id (set null on delete)
├── description     text    nullable
├── source          string  enum('manual', 'import', 'legacy-migration') default 'manual'
├── created_at / updated_at
```

**Justification** : permet (a) réservations pour machines non encore en `workstations`, (b) découplage cycle de vie, (c) traçabilité de l'origine. La cohérence `workstations.ip ↔ dhcp_reservations.ip` quand `workstation_id` est lié est **opt-in** (pas de cascade automatique — un opérateur peut vouloir changer l'IP réservée sans toucher au `workstations.ip` qui peut être dynamique).

### 2. Frontière avec `workstations.ip` / `workstations.mac`

Les colonnes `workstations.ip` et `workstations.mac` reflètent l'**état observé** (rapport WPKG, sync AD, dernier bail). Une réservation DHCP est une **intention** distincte. Si un opérateur crée une réservation et que la machine existe (`workstations.name` match), la story propose **mais ne force pas** le lien (`workstation_id` rempli). Pas d'écriture automatique sur `workstations.ip` ni `mac` depuis cette story.

### 3. Rechargement service DHCP

Stratégie unique : appel `sudo /usr/share/sambaedu/sbin/make_dhcpd_conf.sh` après chaque mutation. Le script lit `/etc/sambaedu/reservations.inc` (régénéré par `DhcpService::exportReservationsFile()` AVANT le reload) et régénère `dhcpd.conf`. Le service est rechargé via `systemctl reload isc-dhcp-server.service` (à l'intérieur du shell script ; ne pas dupliquer côté PHP).

**Atomicité de l'écriture** : utiliser `App\Support\AtomicFileWriter::write()` (livré Story 15.1) pour `/etc/sambaedu/reservations.inc` afin d'éviter qu'un reload concurrent lise un fichier tronqué.

### 4. Permissions UI

- `viewAny-dhcp` (= `server.admin`) → accès à `/app/network/dhcp` (liste + baux).
- `manage-dhcp` (= `server.admin`) → création / édition / suppression / import.

Pas de nouvelle permission Spatie ajoutée dans cette story. Si besoin de granularité (lecture seule pour profil « technicien »), arbitrer en Epic 12 (matrice profils × droits).

### 5. Format CSV import (FR22)

Format proposé (validé en T0) :

```
name,mac,ip,description
posteSalle1,00:11:22:33:44:55,10.0.0.50,Salle informatique poste #1
imprimanteCDI,AA:BB:CC:DD:EE:FF,10.0.0.30,Imprimante CDI
```

- Séparateur `,` (virgule). Header obligatoire (1ère ligne).
- Colonnes `name`, `mac`, `ip` obligatoires. `description` optionnelle.
- Pas de support multi-vlan dans cette story (mono-réseau). Les vlans legacy (`get_network()` / `create_site_dhcp()`) **sont hors scope** — couverts par une story future Epic 8.2 si besoin.

### 6. Rapport d'import (FR22)

À l'issue d'un import :

- Toasts : « X réservations importées, Y erreurs, Z lignes ignorées (doublons) ».
- Vue de rapport persistée 24h en cache (`Cache::store('redis')->put("dhcp.import.report.<uuid>", $report, 86400)`) accessible via `/app/network/dhcp/import/{uuid}` — colonnes : `#ligne`, `name`, `mac`, `ip`, `status` (ok/error/skipped), `reason`.
- Pattern réutilisable : voir `App\Services\PasswordResetExportService` (Story 2.6) et `BulkResetListingService` pour la pattern cache + uuid signé.

### 7. Channel logs

Nouveau channel `network` (`config/logging.php`) — pattern aligné sur `wpkg-deploy` (15.1). Format ligne : `[DhcpService] <action> ip=<ip> mac=<mac> name=<name> result=<ok|fail>`. Niveau ERROR pour fail exec, INFO pour mutations CRUD, DEBUG pour parsing leases.

### 8. Mode dégradé (binaire absent / sudo refusé / service down)

Aligné Story 1bis.16 + 6.1 (`CupsDaemonDownException`) :

- Si `systemctl is-active isc-dhcp-server.service` échoue (return non-zero + stderr non vide) → `DhcpDaemonDownException`. La page liste s'affiche quand même (à partir de la DB), un bandeau avertit « Service DHCP injoignable ».
- Si `make_dhcpd_conf.sh` retourne non-zero → `DhcpCommandException` capturée, toast erreur « Le service DHCP n'a pas pu être rechargé (cause : …). Les réservations sont enregistrées mais ne seront actives qu'après reload manuel. » + log channel `network` niveau ERROR.
- Si `/var/lib/dhcp/dhcpd.leases` est illisible → liste « baux actifs » affiche message « Lecture des baux indisponible » au lieu de planter.

### 9. Migration legacy intégrée à `/sync-from-ad` (décision Henri 2026-05-11 — option B)

La migration ponctuelle du fichier legacy `/etc/sambaedu/reservations.inc` est désormais **dans le scope** de cette story (auparavant explicitement exclue à ligne 105). Plutôt qu'une commande Artisan séparée, on greffe une **étape 10** à la page existante `/sync-from-ad` (composant Livewire SFC `resources/views/pages/sync-from-ad/index.blade.php`, pattern « 9 étapes séquentielles »).

**Justification :**
- Cohérence UX : la bascule legacy → SER se pilote depuis une seule page d'assistant (déjà 9 étapes : users, groups, workstations, OUs, app profiles, shortcuts, rights profiles, rights migration).
- Réutilisation du pattern existant : `initializeSteps()` + `runStep()` switch + méthode privée `runXxxSync(): void` + `addLog()` + `stepLogs[]` + badges stats dans le blade.
- Idempotence native : la table `dhcp_reservations` ayant des contraintes uniques (`mac`, `name`), un rejeu donne `created=0, updated=N`.

**Format parsé :** fragment dhcpd `host <cn> { hardware ethernet <mac>; fixed-address <ip>; }` (cf. § Investigation Legacy — `export_dhcp_reservations` ldap.inc.php:4947, sens inverse mais format identique).

**Pas de reload DHCP :** cette étape lit le fichier conf comme source, elle n'écrit rien dans `/etc/sambaedu/reservations.inc`. Le reload n'est déclenché que par les mutations CRUD via UI (AC2/AC3) ou import CSV (AC5).

**Lien Workstation :** si un `Workstation` avec le `name == cn` existe au moment de l'import, on remplit `workstation_id`. Sinon `workstation_id` reste `null` (la réservation survit indépendamment, alignement décision #1).

**Source :** les enregistrements créés ou mis à jour par cette étape portent `source='legacy-migration'` (les rejeux ultérieurs n'écrasent pas la source si elle est plus spécifique — à l'`updated` on conserve la source d'origine).

---

## Acceptance Criteria

### AC1 — Liste des réservations + baux affichée

**Given** je dispose de la permission `server.admin` et navigue vers `/app/network/dhcp`,
**When** la page se charge,
**Then** je vois deux sections :
- **Réservations** : table paginée (25 lignes/page) avec colonnes `Nom`, `MAC`, `IP`, `Description`, `Machine liée` (lien vers `/app/parc/machines/{id}` si `workstation_id` rempli), `Source`, `Actions` (éditer / supprimer).
- **Baux actifs** : table (non paginée, max 200 dernières entrées) avec colonnes `Hostname`, `MAC`, `IP`, `État` (active / free), bouton « Réserver ce bail » qui pré-remplit le formulaire de création.

**And** un bandeau d'état du service DHCP est visible (vert « actif » / rouge « inactif/injoignable ») en haut de page.

### AC2 — Création d'une réservation

**Given** je clique sur « Nouvelle réservation »,
**When** le formulaire s'ouvre dans une modale (`<x-molecules.modal>`),
**Then** je peux saisir `Nom`, `MAC` (auto-formatée `XX:XX:XX:XX:XX:XX`), `IP` (validée format IPv4), `Description` (optionnelle).

**And** un selector optionnel propose les `Workstation` du parc dont le `name` matche le nom saisi (pré-remplit `workstation_id`).

**Given** je valide,
**When** `DhcpService::createReservation()` est invoqué,
**Then** une ligne `dhcp_reservations` est créée,
**And** `/etc/sambaedu/reservations.inc` est régénéré (atomic write),
**And** `sudo /usr/share/sambaedu/sbin/make_dhcpd_conf.sh` est exécuté,
**And** un toast « Réservation créée et service DHCP rechargé » est affiché,
**And** la liste se rafraîchit.

### AC3 — Édition / suppression d'une réservation

**Given** je clique sur l'icône d'édition d'une ligne,
**When** la modale d'édition s'ouvre avec les valeurs pré-remplies,
**Then** je peux modifier `name`, `mac`, `ip`, `description`, `workstation_id`.

**Given** je clique sur l'icône de suppression,
**When** une modale de confirmation s'affiche,
**And** je confirme,
**Then** la ligne est supprimée,
**And** le service DHCP est rechargé,
**And** un toast confirme.

### AC4 — Validations métier

**Given** je tente de créer / modifier une réservation,
**When** je saisis une `MAC` invalide (regex `/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/i` après normalisation),
**Then** la modale affiche une erreur inline « Format MAC invalide » et le submit est bloqué.

**Given** je saisis une `IP` déjà réservée par une autre ligne `dhcp_reservations`,
**Then** une erreur « Cette IP est déjà réservée pour la machine X » est affichée.

**Given** je saisis une `MAC` déjà réservée par une autre ligne,
**Then** une erreur « Cette MAC est déjà réservée pour la machine X » est affichée.

**Given** je saisis un `name` déjà utilisé,
**Then** une erreur « Ce nom est déjà utilisé » est affichée (unicité base).

### AC5 — Import CSV en masse (FR22)

**Given** je clique sur « Importer un fichier » et sélectionne un CSV valide (header `name,mac,ip,description`),
**When** l'import est traité par `DhcpService::importFromCsv(UploadedFile $file): ImportReport`,
**Then** pour chaque ligne :
- ligne valide + nouvelle → réservation créée → `status=ok`,
- ligne valide + `name`/`mac`/`ip` existante → upsert (mise à jour) → `status=ok` avec `action=updated`,
- ligne invalide → enregistrée en erreur avec `reason` explicite (`Format MAC invalide`, `IP déjà utilisée par <name>`, `Colonne manquante`, etc.),
- **aucune** exception ne fait avorter l'import complet (collecte exhaustive).

**And** le service DHCP est rechargé **une seule fois** à la fin de l'import (pas de reload par ligne).

**And** je suis redirigé vers `/app/network/dhcp/import/{uuid}` qui affiche le rapport complet (`#ligne`, `name`, `mac`, `ip`, `status`, `reason`, `action`).

**And** un toast résume « X importées, Y mises à jour, Z erreurs ».

### AC6 — Mode dégradé (service DHCP injoignable)

**Given** `isc-dhcp-server.service` est arrêté ou non installé,
**When** je consulte la page `/app/network/dhcp`,
**Then** la liste des réservations s'affiche (lecture DB),
**And** un bandeau rouge indique « Service DHCP inactif »,
**And** la table « Baux actifs » affiche « Données indisponibles (service injoignable) ».

**Given** je tente de créer une réservation pendant que le service est down,
**When** je valide,
**Then** la réservation est **persistée en DB** + `reservations.inc` est régénéré,
**And** le reload échoue avec `DhcpCommandException`,
**And** un toast d'avertissement (pas d'erreur bloquante) indique « Réservation enregistrée. Le service DHCP n'a pas pu être rechargé — relancer le service manuellement. »
**And** l'erreur est loggée channel `network` niveau ERROR.

### AC7 — Permissions

**Given** je ne dispose pas de la permission `server.admin`,
**When** je tente d'accéder à `/app/network/dhcp`,
**Then** la page renvoie 403 (gate `viewAny-dhcp`).

**Given** un appel direct à l'API Livewire `createReservation` est tenté sans `server.admin`,
**Then** une `AuthorizationException` est levée (gate `manage-dhcp` côté action).

### AC9 — Migration legacy via /sync-from-ad

**Given** je suis l'administrateur lors d'une bascule legacy → SER,
**When** je lance l'étape « 10. Importer les réservations DHCP » dans `/sync-from-ad`,
**Then** le fichier `/etc/sambaedu/reservations.inc` est parsé,
**And** chaque entrée `host <cn> { hardware ethernet <mac>; fixed-address <ip>; }` est upsertée dans `dhcp_reservations`,
**And** `source='legacy-migration'` est positionné sur les enregistrements créés,
**And** si un `Workstation` avec le même `cn` existe (lookup par `name`), `workstation_id` est lié,
**And** l'opération est idempotente (rejouable sans doublon : clé unique `mac` OU `name`),
**And** un rapport ligne-à-ligne est affiché (`created` / `updated` / `skipped` / `errors`) dans les logs de l'étape + badges stats,
**And** aucun reload DHCP n'est déclenché par cette étape (le fichier conf est déjà la source — on lit, on n'écrit pas dans `/etc/sambaedu/reservations.inc`).

**Given** le fichier `/etc/sambaedu/reservations.inc` contient des lignes commentées (`#`), des lignes vides, ou des entrées mal formées,
**When** l'étape est exécutée,
**Then** les commentaires et lignes vides sont silencieusement ignorés,
**And** les entrées mal formées sont comptabilisées dans `errors[]` avec la ligne / raison,
**And** l'import continue (pas d'avortement global).

**Given** une réservation existe déjà en base avec la même `mac` ou le même `name`,
**When** l'étape rencontre la même entrée dans le fichier legacy,
**Then** la ligne est mise à jour (`updated`) avec les valeurs du fichier (IP éventuellement re-synchronisée),
**And** la `source` d'origine est préservée si différente de `legacy-migration` (pas d'écrasement d'une source plus spécifique).

### AC8 — Couverture tests

**Given** la story est livrée,
**When** la suite de tests est exécutée (`php artisan test`),
**Then** les tests suivants passent :
- **Unit** `DhcpServiceTest` : validations MAC/IP, normalisation, parsing leases (fixtures), génération `reservations.inc` (snapshot), unicité contraintes.
- **Feature** `DhcpReservationsCrudTest` : create/edit/delete via Livewire SFC + assert reload service appelé (mock `CommandRunner`).
- **Feature** `DhcpImportCsvTest` : import 5 lignes mixtes (3 OK, 2 erreurs) + assert rapport correct + reload appelé 1 fois.
- **Feature** `DhcpLeasesParsingTest` : parsing fixture `/var/lib/dhcp/dhcpd.leases` réelle (à committer dans `tests/Fixtures/dhcp/`).
- **Feature** `DhcpDegradedModeTest` : service down → page affichée + toast non-bloquant.
- **Policy** `DhcpPolicyTest` (peut déjà exister) : gates `viewAny-dhcp` / `manage-dhcp` respectent `server.admin`.
- **Architecture** : `App\Services\Network\*` testé par `tests/Architecture/` (pattern existant — réutiliser `WpkgDeploymentNamespaceTest` comme modèle).

---

## Tasks / Subtasks

- [x] **T0 — Validation des décisions SM** (AC: toutes) — **BLOQUANT**
  - [x] Confirmer avec Henri : modèle table `dhcp_reservations` (vs colonnes sur `workstations`) — VALIDÉ (table dédiée, cf. § Décisions SM #1).
  - [x] Confirmer scope mono-vlan (pas de gestion multi-réseaux dans 8.1) — VALIDÉ.
  - [x] Confirmer abandon DNS auto (FR21 reporté) — VALIDÉ.
  - [x] Confirmer format CSV (séparateur, colonnes, header) — VALIDÉ : `name,mac,ip,description`, séparateur `,`, header obligatoire.
  - [x] Confirmer commande de reload : `sudo /usr/share/sambaedu/sbin/make_dhcpd_conf.sh` — VALIDÉ + configurable via `config('sambaedu.dhcp.reload_command')`.
  - [x] **Migration legacy intégrée dans `/sync-from-ad` (option B)** — arbitré Henri 2026-05-11 (cf. § Décisions SM #9 et T8b). Plus de commande Artisan séparée : étape 10 dans la page d'assistant existante.
  - [x] **D-T0-1 (dev)** : Réutilisation `App\Services\Print\Contracts\CommandRunner` (déjà bindé `AppServiceProvider`) pour ne pas dupliquer le contrat. Pas de `App\Services\Network\Contracts\CommandRunner` ni `RealCommandRunner` créé.
  - [x] **D-T0-2 (dev)** : Colonne `ip` en `string(45)` (pas `inet` PostgreSQL) — portabilité SQLite tests + IPv6 future. Validation IPv4 reste applicative.

- [x] **T1 — Migration BD + modèle Eloquent** (AC: 1, 2, 3, 4)
  - [x] Créer migration `database/migrations/2026_05_11_120000_create_dhcp_reservations_table.php` : colonnes (id, name, mac, ip, workstation_id, description, source, timestamps), UNIQUE sur `name`/`mac`/`ip`, FK `workstation_id` nullable `ON DELETE SET NULL`, index `source` et `workstation_id`.
  - [x] Créer modèle `app/Models/DhcpReservation.php` : `$fillable`, `$casts`, constantes `SOURCE_*`, relation `belongsTo(Workstation::class)`, scopes `bySource()` et `matchingWorkstationName()`.
  - [x] Factory `database/factories/DhcpReservationFactory.php` avec états `fromImport()` et `fromLegacy()`.
  - [x] **Note user** : la migration doit être lancée côté VM (`php artisan migrate`).

- [x] **T2 — Service `DhcpService` + dépendances** (AC: 2, 3, 5, 6)
  - [x] Créer arborescence `app/Services/Network/` (+ `Data/`, `Exceptions/`).
  - [x] Réutilisation `App\Services\Print\Contracts\CommandRunner` et `RealCommandRunner` (cf. D-T0-1).
  - [x] Créer exceptions : `DhcpCommandException.php`, `DhcpDaemonDownException.php`, `DhcpValidationException.php`.
  - [x] Créer `app/Services/Network/DhcpService.php` avec toutes les méthodes prévues :
    - `validateName/Mac/Ip` (Mac normalisée multi-format : canonique, `-`, `.`, no-sep) ;
    - `createReservation/updateReservation/deleteReservation` (transaction + reload + lock) ;
    - `listActiveLeases` (parsing fixture + dédup IP + exclusion réservations) ;
    - `exportReservationsFile` (atomic write) + `renderReservationsFile` (pure pour snapshot tests) ;
    - `reloadService` + `serviceStatus` + `assertServiceUp` ;
    - `importFromLegacyFile` (T8b — AC9).
  - [x] Préfixe logs `DhcpService:` channel `network` (fallback gracieux si le channel n'est pas configuré).
  - [x] Lock `Cache::lock('dhcp.reload', 30)` + try/finally autour de export+reload (R2).

- [x] **T3 — Config + sudoers + script reload** (AC: 2, 6)
  - [x] Ajouter section `dhcp` dans `config/sambaedu.php` : `reservations_file`, `leases_file`, `reload_command`, `service_name` — tous configurables via env.
  - [x] Documenter la config sudoers attendue dans `docs/qa/domains/network.md` (T11).
  - [x] Sudoers VM NON modifié dans la story — procédure documentée pour l'opérateur.

- [x] **T4 — Channel logs `network`** (AC: 6, 8)
  - [x] Ajouter dans `config/logging.php` un channel `network` (driver `daily`, level `debug` overridable `NETWORK_LOG_LEVEL`, path `storage/logs/network/network.log`, rotation 7 jours).

- [x] **T5 — Service d'import CSV + rapport** (AC: 5)
  - [x] Créer `app/Services/Network/DhcpImportService.php` (SRP — séparé de DhcpService, injecté avec lui).
  - [x] DTO `app/Services/Network/Data/ImportReportRow.php` (factories `ok`/`error`/`skipped`).
  - [x] DTO `app/Services/Network/Data/ImportReport.php` (sérialisation cache + reconstruction `fromArray`).
  - [x] Persistance rapport : cache 24h sous clé `dhcp.import.report.<uuid>` (driver Redis/array — pattern Story 2.6).
  - [x] Validation header CSV stricte. Tolérance : ignore lignes vides + lignes commentées (`#`).
  - [x] Atomicité reload : 1 seul reload à la fin, après commits BD (test dédié `it_reloads_service_only_once_at_the_end`).
  - [x] Méthode `fetchReport(string $uuid): ?ImportReport` + variante test `importFromCsvContent(string)`.

- [x] **T6 — Route + page Livewire SFC `/app/network/dhcp`** (AC: 1, 7)
  - [x] Créer `resources/views/pages/network/dhcp/index.blade.php` (Livewire SFC) : `mount()` avec check `viewAny-dhcp`, paginated `$reservations` (25/page), `$leases` (best-effort `try/catch`), `$serviceStatus`, `$search` live-debounce.
  - [x] Partials `_partials/reservations-table.blade.php`, `_partials/leases-table.blade.php`, `_partials/service-status-banner.blade.php`.
  - [x] `<x-molecules.modal>` pour le formulaire création/édition ET la confirmation de suppression (2 modales).
  - [x] Trait `WithToasts` utilisé (toast success / warning pour mode dégradé / error pour validation).
  - [x] Méthode `preFillFromLease()` pour le bouton "Réserver ce bail" (AC1 colonne action de la table baux).

- [x] **T7 — Page Livewire SFC `/app/network/dhcp/new`** (AC: 2)
  - [x] Créer `resources/views/pages/network/dhcp/new/index.blade.php` (Livewire SFC, plein écran cette fois — alternative à la modale).
  - [x] Formulaire complet + selector workstation (live filtré sur le nom saisi).
  - [x] Validation `DhcpService::validate*` (defense in depth).
  - [x] Action `save()` → gate `manage-dhcp` → service → toast + redirect vers `/app/network/dhcp`.

- [x] **T8 — Page Livewire SFC import CSV + rapport** (AC: 5)
  - [x] Créer `resources/views/pages/network/dhcp/import/index.blade.php` (upload CSV via `WithFileUploads`).
  - [x] Créer `resources/views/pages/network/dhcp/import/[uuid]/index.blade.php` (rapport, 404 si UUID expiré).
  - [x] Exemple inline dans la page (bloc `<pre>` montrant le format CSV).
  - [x] Partial `_partials/import-report-table.blade.php` pour les détails ligne par ligne.

- [x] **T8b — Migration legacy via /sync-from-ad** (AC: 9)
  - [x] Méthode `DhcpService::importFromLegacyFile(string $path, callable $logger): array` :
    - lecture seule via `file_get_contents()`, `RuntimeException` claire si fichier manquant / illisible ;
    - parsing regex multi-ligne tolérante (`/host\s+(\S+)\s*\{\s*hardware\s+ethernet\s+([0-9a-fA-F:]+)\s*;\s*fixed-address\s+([0-9.]+)\s*;\s*\}/m`) ;
    - préfiltrage commentaires `#` et `//` + lignes vides + mapping offset → ligne origine pour messages d'erreur ;
    - normalisation MAC ; upsert par MAC en priorité, sinon `name` ;
    - `source='legacy-migration'` UNIQUEMENT à la création (préservation `manual`/`import` sur update — AC9) ;
    - liaison `workstation_id` si `Workstation::where('name', $cn)->first()` ;
    - retour `['parsed', 'created', 'updated', 'skipped', 'errors' => [['line', 'reason'], …]]` ;
    - **AUCUN** appel `reloadService()` (AC9).
  - [x] Ajout étape `dhcp_reservations` (id) dans `initializeSteps()` (position 10, après `rights_migration`).
  - [x] `case 'dhcp_reservations'` dans le `switch` de `runStep()`.
  - [x] Méthode privée `runDhcpReservationsSync(): void` qui logge un bilan + détaille jusqu'à 20 erreurs.
  - [x] `'dhcp_reservations' => []` dans `$stepLogs`.
  - [x] Badges stats spécifiques (`parsed`, `+created`, `~updated`, `skipped`) dans le blade — pattern identique à `rights_migration`.
  - [x] **Tests** :
    - `tests/Unit/Services/Network/DhcpServiceImportLegacyTest.php` — 9 cas (nominal, commentaires, lignes vides, MAC dup, MAC/IP invalide, espaces variables, idempotence, source préservée, lien Workstation, fichier manquant).
    - `tests/Feature/Pages/SyncFromAd/DhcpReservationsStepTest.php` — 5 tests (exécution + idempotence + lien Workstation + stats Livewire + no-reload).

- [x] **T9 — Routes web** (AC: 1, 2, 5, 7)
  - [x] Routes ajoutées dans `routes/web.php` sous `prefix('network/dhcp')` :
    - `/` middleware `can:viewAny-dhcp` (`network.dhcp`).
    - `/new` middleware `can:manage-dhcp` (`network.dhcp.new`).
    - `/import` middleware `can:manage-dhcp` (`network.dhcp.import`).
    - `/import/{uuid}` middleware `can:viewAny-dhcp` (`network.dhcp.import.report`).
  - [x] Convention filesystem-based router respectée (cf. CLAUDE.md).
  - [x] Lien ajouté dans `resources/views/components/organisms/sidebar.blade.php` sous `@can('viewAny-dhcp')` (icône `fa-network-wired`, label "Réseau (DHCP)").

- [x] **T10 — Tests** (AC: 8)
  - [x] `tests/Unit/Services/Network/DhcpServiceTest.php` — validations (name regex, MAC multi-format, IPv4), parsing leases (fixture réelle), génération `reservations.inc`, statut service, reload, unicité métier.
  - [x] `tests/Unit/Services/Network/DhcpServiceImportLegacyTest.php` — T8b (cf. ci-dessus).
  - [x] `tests/Unit/Services/Network/DhcpImportServiceTest.php` — pipeline CSV (header invalide, lignes vides/commentées, doublons intra-fichier, reload unique, upsert par MAC, cache 24h, vide → no-reload).
  - [x] `tests/Feature/Network/DhcpReservationsCrudTest.php` — CRUD complet via Livewire SFC + bypass permission.
  - [x] `tests/Feature/Network/DhcpImportCsvTest.php` — import fixture + source `import` + reload unique.
  - [x] `tests/Feature/Network/DhcpLeasesParsingTest.php` — parsing leases + exclusion réservations + file manquant.
  - [x] `tests/Feature/Network/DhcpDegradedModeTest.php` — service down, page rendue, réservation persistée.
  - [x] `tests/Feature/Pages/SyncFromAd/DhcpReservationsStepTest.php` — étape 10 page Livewire.
  - [x] `tests/Feature/Policies/DhcpPolicyTest.php` — déjà existant Story 7.2, pas de modification.
  - [x] `tests/Architecture/NetworkNamespaceTest.php` — garde-fou imports interdits + sous-namespaces présents.
  - [x] Fixtures : `tests/Fixtures/dhcp/reservations.inc`, `dhcpd.leases`, `sample-import.csv`.
  - [x] Trait `tests/Traits/CreatesDhcpSchema.php` (cohérent `CreatesPrintersSchema`).

- [x] **T11 — QA Runbook + Documentation** (AC: toutes)
  - [x] Créé `docs/qa/domains/network.md` — pré-requis (sudoers documenté), 7 sections (CRUD, baux, import, mode dégradé, concurrence, migration legacy, bascule), checklist rapide finale.
  - [x] Ligne ajoutée dans `docs/qa/README.md` section "Domaines couverts".
  - [x] Ligne "legacy-shims" préservée (shim 1bis-16 reste actif en parallèle).

- [x] **T12 — Retrait progressif du shim 1bis.16 (préparation, pas exécution)** (hors AC, optionnel)
  - [x] Section 7 "Bascule legacy → SER" dans `docs/qa/domains/network.md` — procédure documentée + risque R6 (éditions croisées) rappelé.
  - [x] **PAS** de retrait `legacy/modules/dhcp/` — différé à une story Epic 14 follow-up.

---

## File List prévisionnel

### Création

**Backend**
- `app/Models/DhcpReservation.php`
- `app/Services/Network/DhcpService.php` _(porte également `importFromLegacyFile(string $path, callable $logger): array` — cf. T8b / AC9)_
- `app/Services/Network/DhcpImportService.php`
- `app/Services/Network/Contracts/CommandRunner.php` _(ou réutilisation `Services/Print/Contracts/CommandRunner.php` — à arbitrer T0)_
- `app/Services/Network/RealCommandRunner.php` _(idem)_
- `app/Services/Network/Exceptions/DhcpCommandException.php`
- `app/Services/Network/Exceptions/DhcpDaemonDownException.php`
- `app/Services/Network/Exceptions/DhcpValidationException.php`
- `app/Services/Network/Data/ImportReport.php`
- `app/Services/Network/Data/ImportReportRow.php`

**Migrations / Factories**
- `database/migrations/2026_05_11_XXXXXX_create_dhcp_reservations_table.php`
- `database/factories/DhcpReservationFactory.php`

**Views (Livewire SFC + partials)**
- `resources/views/pages/network/dhcp/index.blade.php` _(Livewire SFC)_
- `resources/views/pages/network/dhcp/_partials/reservations-table.blade.php`
- `resources/views/pages/network/dhcp/_partials/leases-table.blade.php`
- `resources/views/pages/network/dhcp/_partials/service-status-banner.blade.php`
- `resources/views/pages/network/dhcp/new/index.blade.php` _(Livewire SFC, ou modale au choix dev)_
- `resources/views/pages/network/dhcp/import/index.blade.php` _(Livewire SFC)_
- `resources/views/pages/network/dhcp/import/[uuid]/index.blade.php` _(Livewire SFC)_
- `resources/views/pages/network/dhcp/import/_partials/import-report-table.blade.php`

**Tests**
- `tests/Unit/Services/Network/DhcpServiceTest.php`
- `tests/Unit/Services/Network/DhcpServiceImportLegacyTest.php` _(T8b / AC9 — parsing fixture `reservations.inc`)_
- `tests/Feature/Network/DhcpReservationsCrudTest.php`
- `tests/Feature/Network/DhcpImportCsvTest.php`
- `tests/Feature/Network/DhcpLeasesParsingTest.php`
- `tests/Feature/Network/DhcpDegradedModeTest.php`
- `tests/Feature/Pages/SyncFromAd/DhcpReservationsStepTest.php` _(T8b / AC9 — feature étape 10 page Livewire)_
- `tests/Feature/Policies/DhcpPolicyTest.php`
- `tests/Architecture/NetworkNamespaceTest.php`
- `tests/Fixtures/dhcp/dhcpd.leases` _(fixture)_
- `tests/Fixtures/dhcp/sample-import.csv` _(fixture)_
- `tests/Fixtures/dhcp/reservations.inc` _(fixture parsing legacy — T8b / AC9)_

**Documentation**
- `docs/qa/domains/network.md`

### Modification

- `config/sambaedu.php` _(ajout section `dhcp`)_
- `config/logging.php` _(ajout channel `network`)_
- `routes/web.php` _(routes `/app/network/dhcp/*`)_
- `resources/views/components/layouts/sidebar.blade.php` ou équivalent _(entrée navigation « Réseau »)_
- `resources/views/pages/sync-from-ad/index.blade.php` _(T8b / AC9 — ajout étape 10 « Importer les réservations DHCP » : `initializeSteps()`, `runStep()` switch, `runDhcpReservationsSync()`, `$stepLogs`, badges stats spécifiques)_
- `docs/qa/README.md` _(ajout ligne `network` dans « Domaines couverts »)_

### À NE PAS toucher

- `legacy/modules/dhcp/` — reste opérationnel via catchall pendant la transition.
- `app/Policies/DhcpPolicy.php` — déjà OK (Story 7.2).
- `app/Services/Print/Contracts/CommandRunner.php` _(à analyser en T0 pour réutilisation, sinon ne pas modifier)_.
- `app/Models/Workstation.php` — pas de colonnes ajoutées ; relation `hasMany(DhcpReservation::class)` à ajouter uniquement si besoin pour la vue machine (decision T0, simple, non bloquant).

---

## Risques & Mitigation

| Risque | Impact | Probabilité | Mitigation |
|--------|--------|-------------|------------|
| R1 — Bug legacy `dhcpd_restart` (`/sh/share/` au lieu de `/usr/share/`) reproduit | Crash silencieux reload service | Faible | Test feature avec mock + path config explicite (`config('sambaedu.dhcp.reload_command')`). |
| R2 — Concurrence reload (2 mutations simultanées → fichier `reservations.inc` corrompu) | Réservations perdues / service en panne | Moyen | `AtomicFileWriter` (15.1) + lock applicatif `Cache::lock('dhcp.reload', 30)` autour de `exportReservationsFile + reloadService`. |
| R3 — Sudoers VM non configuré → reload échoue à chaque mutation | UX dégradée permanente | Moyen | T11 QA runbook documente l'install sudoers + AC6 garantit mode dégradé non-bloquant (réservation persiste + toast warning). |
| R4 — Format `dhcpd.leases` variable selon version ISC | Parse cassé en prod | Faible | Fixture réelle commitée + tolérance parser (skip lignes inconnues, jamais throw). |
| R5 — Désynchro `dhcp_reservations.workstation_id` ↔ `workstations` (machine supprimée) | FK orpheline | Faible | `ON DELETE SET NULL` sur la FK. Ligne `dhcp_reservations` survit (intention indépendante). |
| R6 — Conflit avec shim 1bis.16 (utilisateur édite une réservation côté legacy ET côté native) | Divergence AD ↔ `dhcp_reservations` | Moyen | Bandeau d'info en haut de page « Page legacy en parallèle accessible — éviter les édits croisés ». Bascule complète arbitrée post-stabilisation. |
| R7 — IP `inet` PostgreSQL vs `string` portable | Migration / driver alternatif | Faible | Utiliser `string` (varchar 45) — couvre IPv4/IPv6 future. PostgreSQL `inet` natif possible mais inutile à ce stade. |
| R8 — Permission write `/etc/sambaedu/reservations.inc` | Fail silent à l'écriture | Moyen | Vérification explicite `is_writable()` + log channel `network` ERROR + AC6 mode dégradé. |
| R9 — APCu absent (présent dans le legacy script_make_reservations) | Pas d'impact (on n'utilise pas APCu) | N/A | On reste sur Redis. Ignorer. |

---

## Project Structure Notes

- Alignement avec la convention `CLAUDE.md` :
  - Pages sous `resources/views/pages/network/dhcp/` ✅
  - Livewire SFC pour la partie interactive ✅
  - Modale réutilisable `<x-molecules.modal>` ✅
  - Trait `WithToasts` pour notifications ✅
  - Services dans `app/Services/Network/` (cohérent avec architecture.md ligne 449 : « Network/ — À créer — DhcpService, DnsService ») ✅
- Pas de divergence détectée avec l'architecture documentée.

---

## Previous Story Intelligence

### Story 1bis-16 — Module DHCP shimé (done)

- **Source de vérité** : `1bis-16-module-dhcp.md`.
- **Findings utiles** : audit exec complet, bug legacy `dhcpd_restart()` chemin (`/sh/share/...`), absence de SQL direct, présence d'APCu (non bloquante ici car on n'en dépend pas), parsing leases déjà compris.
- **Pattern à reprendre** : la story 1bis-16 a documenté que `isc-dhcp-server` est absent de la VM dev → les exec échouent silencieusement → AC6 garantit ce comportement (mode dégradé). Le reload doit être idempotent / safe-fail.

### Story 6.1 — CUPS (done)

- **Pattern shellout** : `CupsPrinterService` + `CommandRunner` + exceptions typées + `LC_ALL=C` + `escapeshellarg()`. **Référence directe** pour `DhcpService`.
- **Mode dégradé** : `CupsDaemonDownException` pour distinguer « service down » de « liste vide ». Réutiliser exactement (`DhcpDaemonDownException`).

### Story 15.1 — Pipeline WPKG (done)

- **Pattern atomic write** : `App\Support\AtomicFileWriter::write()` — réutilisable pour `/etc/sambaedu/reservations.inc`.
- **Pattern channel logs** : `wpkg-deploy` → modèle pour le nouveau channel `network`.

### Story 2.6 — Bulk reset MDP (done)

- **Pattern cache + uuid signé** pour le rapport d'import (similaire au listing bulk reset).

---

## References

### Sources Legacy

- `sambaedu/dhcp/baux.php` — endpoint liste baux + reset leases (legacy)
- `sambaedu/dhcp/config.php` — endpoint config DHCP (multi-vlan, **hors scope** 8.1)
- `sambaedu/dhcp/import_reservations.php` — endpoint cron export AD → fichier
- `sambaedu/dhcp/script_make_reservations.php` — script lock APCu (legacy)
- `sambaedu/dhcp/make_reservations.php` — wrapper script export
- `sambaedu/dhcp/dnsupdate.php` — mise à jour DNS (FR21 — reporté)
- `sambaedu/includes/dhcpd.inc.php` — 914 L, fonctions DHCP + HTML form legacy
- `sambaedu/includes/ldap.inc.php:4519` — `get_free_ip()`
- `sambaedu/includes/ldap.inc.php:4558` — `set_dhcp_reservation()` (logique AD)
- `sambaedu/includes/ldap.inc.php:4627` — `delete_dhcp_reservation()`
- `sambaedu/includes/ldap.inc.php:4654` — `import_dhcp_reservations()` (parse legacy `reservations.inc.se3`)
- `sambaedu/includes/ldap.inc.php:4947` — `export_dhcp_reservations()` (format `host { hardware ethernet …; fixed-address …; }`)
- `sambaedu/includes/ldap.inc.php:4987` — `import_dhcp_leases()` (parse `/var/lib/dhcp/dhcpd.leases`)
- `sambaedu/includes/ldap.inc.php:5044` — `list_dhcp_leases()` (filtre baux vs réservations)

### Sources Projet (`dns/`)

- `app/Policies/DhcpPolicy.php` — gates `viewAny-dhcp` / `manage-dhcp` déjà déclarées
- `app/Services/Print/CupsPrinterService.php` — **modèle pattern shellout** (Story 6.1)
- `app/Services/Print/RealCommandRunner.php` — réutiliser ou cloner
- `app/Services/Filesystem/XfsQuotaService.php` — autre référence pattern shellout (Story 5.1a)
- `app/Support/AtomicFileWriter.php` — Story 15.1
- `app/Models/Workstation.php` — FK cible

### Sources Documentation

- `_bmad-output/planning-artifacts/epics.md:2118` — Epic 8 intro
- `_bmad-output/planning-artifacts/epics.md:2124` — Story 8.1 (cette story)
- `_bmad-output/planning-artifacts/epics.md:58` — FR20
- `_bmad-output/planning-artifacts/epics.md:59` — FR21 (reporté)
- `_bmad-output/planning-artifacts/epics.md:60` — FR22
- `_bmad-output/planning-artifacts/architecture.md:449` — `Network/` services à créer
- `_bmad-output/planning-artifacts/architecture.md:482-484` — pages `network/dhcp/` + `network/dns/`
- `_bmad-output/planning-artifacts/architecture.md:526` — mapping FR20-22 → Network
- `_bmad-output/implementation-artifacts/1bis-16-module-dhcp.md` — story shim (référence audit)
- `docs/qa/README.md` — convention QA, doit créer `domains/network.md`
- `CLAUDE.md` (`dns/` racine) — conventions filesystem router + Livewire + modale + WithToasts

---

## Dev Agent Record

### Agent Model Used

`claude-opus-4-7[1m]` (Claude Opus 4.7, contexte 1M tokens).

### Implémentation : 2026-05-11

- **Début** : 2026-05-11 (suite arbitrage T0 par Henri)
- **Fin** : 2026-05-11
- **Modèle** : opus (renforcé suite ajout AC9/T8b, justification dans § Recommandation Modèle Dev)
- **Stratégie** : implémentation linéaire T0→T12 + T8b, sans HALT.

### Debug Log References

- Channel logs `network` : `storage/logs/network/network-{date}.log`
- Pattern logs : `[DhcpService] <action> name=… mac=… ip=… result=ok|fail`
- Niveau ERROR pour fail exec, INFO pour mutations CRUD, DEBUG pour parsing/leases.

### Décisions techniques notables (dev)

1. **D-T0-1 — Réutilisation `CommandRunner` du namespace `Print`** : pas de
   `App\Services\Network\Contracts\CommandRunner` créé. Le contrat de Print
   (Story 6.1) est suffisamment générique (interface `run(string): array`).
   `AppServiceProvider` n'a PAS été modifié (le binding `Print\Contracts\CommandRunner →
   RealCommandRunner` existe déjà).
2. **D-T0-2 — Colonne `ip` en `string(45)`** : portabilité driver test SQLite
   (pas de `inet` natif). 45 caractères couvre IPv4 (15) + IPv6 future. Validation
   IPv4 stricte reste applicative.
3. **D-MAC-1 — Normalisation MAC multi-format** : `validateMac()` accepte
   `xx:xx:xx:xx:xx:xx`, `xx-xx-xx-xx-xx-xx`, `xxxxxxxxxxxx`, `xxxx.xxxx.xxxx`
   (notation Cisco). Strip tout non-hex puis longueur 12 + insère `:`. Choix
   testé par DataProvider 6 cas (`it_normalizes_mac_to_canonical_format`).
4. **D-LOCK-1 — `Cache::lock('dhcp.reload', 30)`** dans `reloadAfterMutation` :
   block(15s) max attente. En cas de timeout, `DhcpCommandException` "Verrou
   indisponible". Garantit R2 (mutations concurrentes ne corrompent pas le
   fichier).
5. **D-PARSE-1 — Parser legacy `reservations.inc`** : regex multi-ligne tolérante
   aux espaces/tabs, préfiltrage commentaires `#`/`//` avec mapping
   offset→ligne pour messages d'erreur ciblés. Validation MAC AVANT upsert
   (les MAC invalides comptent en `errors[]`, pas en `parsed`).
6. **D-PRESERVE-SOURCE — Préservation source sur update** : `importFromLegacyFile`
   ne touche **pas** au champ `source` sur les updates (AC9). Seul le path
   création pose `source='legacy-migration'`. Couvert par test
   `it_does_not_overwrite_manual_source_on_replay`.
7. **D-CSV-UPSERT — Politique upsert CSV** : priorité `mac` > `name` > `ip`
   pour détecter une réservation existante. Évite les surprises quand un
   opérateur réutilise une MAC mais change le `name`.
8. **D-MODAL-1 — 2 modales sur `index.blade.php`** : `modalOpen` (form
   create/edit) et `deleteOpen` (confirmation suppression). Choix discuté
   vs page séparée. La page `/new` est conservée pour les accès directs
   par URL.
9. **D-LIVEWIRE-RENDER — `rendering()` plutôt que `mount()` pour le statut
   service** : permet de rafraîchir la bannière à chaque cycle Livewire
   (utile pour la transition vert → rouge si le service tombe pendant la
   session UI).
10. **D-TEST-INSTANCE — `$this->app->instance(CommandRunner::class, $runner)`** :
    pattern de substitution du binding global dans les tests Feature. Toutes
    les résolutions `app(DhcpService::class)` ultérieures injectent le
    FakeCommandRunner.

### Completion Notes List

- **AC1** ✅ Liste réservations paginated + table baux + bannière statut.
- **AC2** ✅ Création modale + selector workstation live + reload + toast success.
- **AC3** ✅ Édition modale + suppression avec confirm modal.
- **AC4** ✅ Validations regex + unicité multi-clés (name/mac/ip) + messages ciblés.
- **AC5** ✅ Import CSV avec rapport UUID 24h, reload unique, header strict, tolérance comments/empty, intra-file dedup.
- **AC6** ✅ Mode dégradé : réservation persistée même si reload fail, toast warning non-bloquant, bannière rouge, lecture leases best-effort.
- **AC7** ✅ Gates Spatie `viewAny-dhcp` / `manage-dhcp` (= `server.admin`) à 3 niveaux : middleware route, `mount()`, méthodes Livewire publiques.
- **AC8** ✅ Couverture tests : 1 Unit DhcpService + 1 Unit DhcpServiceImportLegacy + 1 Unit DhcpImportService + 4 Feature + 1 Architecture + DhcpPolicyTest existant.
- **AC9** ✅ Étape 10 `/sync-from-ad`, idempotente, lien Workstation, source `legacy-migration` à la création, préservation source sur update, pas de reload déclenché.

### File List

#### Créations (backend + tests + docs)

**Backend**
- `app/Models/DhcpReservation.php`
- `app/Services/Network/DhcpService.php`
- `app/Services/Network/DhcpImportService.php`
- `app/Services/Network/Exceptions/DhcpCommandException.php`
- `app/Services/Network/Exceptions/DhcpDaemonDownException.php`
- `app/Services/Network/Exceptions/DhcpValidationException.php`
- `app/Services/Network/Data/ImportReport.php`
- `app/Services/Network/Data/ImportReportRow.php`

**Migration / Factory**
- `database/migrations/2026_05_11_120000_create_dhcp_reservations_table.php`
- `database/factories/DhcpReservationFactory.php`

**Vues Livewire**
- `resources/views/pages/network/dhcp/index.blade.php`
- `resources/views/pages/network/dhcp/_partials/reservations-table.blade.php`
- `resources/views/pages/network/dhcp/_partials/leases-table.blade.php`
- `resources/views/pages/network/dhcp/_partials/service-status-banner.blade.php`
- ~~`resources/views/pages/network/dhcp/new/index.blade.php`~~ _(supprimé — Code Review Fixes 2026-05-11 #7 : remplacé par modale create/edit dans `/index`)_
- `resources/views/pages/network/dhcp/import/index.blade.php`
- `resources/views/pages/network/dhcp/import/[uuid]/index.blade.php`
- `resources/views/pages/network/dhcp/import/_partials/import-report-table.blade.php`

**Tests**
- `tests/Traits/CreatesDhcpSchema.php`
- `tests/Unit/Services/Network/DhcpServiceTest.php`
- `tests/Unit/Services/Network/DhcpServiceImportLegacyTest.php`
- `tests/Unit/Services/Network/DhcpImportServiceTest.php`
- `tests/Feature/Network/DhcpReservationsCrudTest.php`
- `tests/Feature/Network/DhcpImportCsvTest.php`
- `tests/Feature/Network/DhcpImportReportPageTest.php` _(ajouté Code Review Fixes 2026-05-11 #9)_
- `tests/Feature/Network/DhcpLeasesParsingTest.php`
- `tests/Feature/Network/DhcpDegradedModeTest.php`
- `tests/Feature/Pages/SyncFromAd/DhcpReservationsStepTest.php`
- `tests/Architecture/NetworkNamespaceTest.php`
- `tests/Fixtures/dhcp/reservations.inc`
- `tests/Fixtures/dhcp/dhcpd.leases`
- `tests/Fixtures/dhcp/sample-import.csv`

**Documentation**
- `docs/qa/domains/network.md`

#### Modifications

- `config/sambaedu.php` — ajout section `dhcp` (4 clés env-overridables)
- `config/logging.php` — ajout channel `network` (driver daily, 7j rotation)
- `routes/web.php` — ajout 4 routes `network.dhcp.*` avec gates
- `resources/views/components/organisms/sidebar.blade.php` — entrée "Réseau (DHCP)" sous `@can('viewAny-dhcp')`
- `resources/views/pages/sync-from-ad/index.blade.php` — étape 10 (initializeSteps, switch runStep, runDhcpReservationsSync, stepLogs, badges stats)
- `docs/qa/README.md` — ligne `network` dans "Domaines couverts"

#### Non touchés (volontairement)

- `legacy/modules/dhcp/` — shim 1bis-16 reste actif en parallèle (cf. T12).
- `app/Policies/DhcpPolicy.php` — déjà OK (Story 7.2).
- `app/Services/Print/Contracts/CommandRunner.php` — réutilisé tel quel (D-T0-1).
- `app/Models/Workstation.php` — pas de colonnes ni de relation `hasMany(DhcpReservation::class)` ajoutée (non bloquant, decision T0 — peut être ajoutée plus tard si une page machine veut afficher ses réservations).
- `app/Providers/AppServiceProvider.php` — binding `CommandRunner` déjà présent (réutilisé).

## Code Review Fixes 2026-05-11

> Corrections post-review claude-opus appliquées suite arbitrages Henri sur
> `_bmad-output/codeReviews/8-1.md`. Status story reste `review`.

1. **#1 — `Cache::lock::block()` LockTimeoutException** : `DhcpService::reloadAfterMutation()` wrappe `block(15)` dans try/catch → `DhcpCommandException` avec message ciblé « Verrou DHCP toujours détenu après 15s ». Branche `if (!$lock->block(15))` inatteignable supprimée. `DhcpCommandException` accepte désormais un `?Throwable $previous` optionnel.
2. **#2 — Injection HTML `wire:click`** : `leases-table.blade.php` utilise `preFillFromLeaseByIndex({{ $loop->index }})` ; le composant Livewire recharge les baux côté serveur depuis l'index, plus de string concaténée sortie depuis Blade.
3. **#3 — Upsert priorité explicite MAC > name > IP** : `DhcpImportService::processRows()` et `DhcpService::importFromLegacyFile()` remplacent `orWhere()` par recherche séquentielle (`?? ->first()`). Pour le parser legacy, la séquence est MAC puis name (pas d'IP, conformément à l'unicité legacy). Branche update ne touche pas `source` (préservation manual/import intacte).
4. **#4 — Cache 15s `serviceStatus()`** : `Cache::remember('dhcp.service_status', 15, ...)` autour de la logique shellout `sudo systemctl is-active`. Plus de spam à chaque cycle Livewire.
5. **#5 (Q1) — Blocs `host {` orphelins comptabilisés** : second pass regex `host\s+(\S+)\s*\{` après le matcher principal, blocs non consommés poussés en `errors[]` avec ligne et raison « malformé ». Test `it_reports_malformed_host_block_as_error` ajouté.
6. **#6 (Q2) — Bandeau R6 « éditions croisées legacy »** : alert warning ajouté en haut de `/network/dhcp/index` au-dessus du status-banner.
7. **#7 (Q3) — Page `/new` supprimée** : `resources/views/pages/network/dhcp/new/index.blade.php` retiré (trash), dossier `new/` vide retiré, route `Route::livewire('/new', ...)` retirée dans `routes/web.php`. Bouton « Nouvelle réservation » de `/index` pointe déjà sur `wire:click="openCreateModal"`. Doc `network.md` mise à jour.
8. **#8 — Test dégradé `toastWarning`** : `DhcpDegradedModeTest::test_create_persists_in_db_even_when_reload_fails` assert désormais `assertDispatched('toastMagic', status: 'warning')` — vérifie explicitement le toast non bloquant.
9. **#9 (Q4) — Test Feature page rapport `[uuid]/index`** : `tests/Feature/Network/DhcpImportReportPageTest.php` ajouté (3 tests : admin OK avec UUID valide, 404 UUID inconnu, 403 non-admin).
10. **#10 — Parser leases compteur d'imbrication** : `parseLeasesContent()` scanne caractère par caractère en comptant `{`/`}` pour trouver la fermeture au niveau 0. Test `it_parses_lease_body_with_nested_braces` ajouté (body avec `set vendor-options = { ... };`).
11. **#11 (Q5) — Test concurrence Cache::lock** : abandonné — un vrai test bloquerait ~15s (timeout interne du service), `CACHE_DRIVER=array` en tests est single-process et ne partage pas les locks inter-instances. Limitation documentée dans `docs/qa/domains/network.md` section « Tests automatisés non couverts » → vérification manuelle requise (2 sessions Livewire parallèles).

### Change Log

| Date       | Auteur       | Changement                                         |
|------------|--------------|----------------------------------------------------|
| 2026-05-11 | SM opus 4.7  | Story créée + ajout AC9/T8b (option B Henri).      |
| 2026-05-11 | Dev opus 4.7 | Implémentation complète : 30 fichiers créés / 6 modifiés. Couverture tests : 8 tests + 1 archi + 3 fixtures. Statut → review. |
| 2026-05-11 | Dev opus 4.7 | Code Review Fixes : 10 corrections appliquées (#1–#10), #11 documenté en limitation. 8 fichiers modifiés, 1 ajouté (test report page), 1 supprimé (page `/new`). |

---

## Recommandation Modèle Dev

**Modèle recommandé** : `opus` _(renforcé suite ajout AC9 / T8b — 2026-05-11)_

**Justification** :

Cette story cumule plusieurs facteurs de complexité qui poussent vers **opus** plutôt que sonnet :

1. **Multi-fichiers stratégique** : ≈ 28 fichiers à créer / modifier (modèle, migration, 2 services, 5 exceptions, 2 DTOs, 7 views + MODIFY sync-from-ad, 9 tests, runbook QA, config). Sonnet peut traiter, mais opus orchestre mieux la cohérence cross-fichier (notamment l'alignement avec les patterns `CupsPrinterService` / `XfsQuotaService` qu'il faudra **transposer** pas **copier**).

2. **Intégration système critique** : appels `sudo systemctl` + `sudo make_dhcpd_conf.sh` + écriture `/etc/sambaedu/reservations.inc`. Erreurs silencieuses inacceptables (un fichier `reservations.inc` corrompu casse tout le serveur DHCP du collège). Le mode dégradé (AC6) est subtil — opus est plus rigoureux sur le « ne jamais perdre une réservation, ne jamais bloquer l'UI ».

3. **Parsing legacy non-trivial** : le format `dhcpd.leases` (binding state, dédup par IP, exclusion des réservations) nécessite une transposition fidèle du parser legacy ldap.inc.php:4987. Opus traite mieux les traductions PHP procédural → Eloquent typé.

4. **Import en masse avec rapport d'erreurs détaillé** (AC5) : pattern similaire à Story 2.6 (bulk reset MDP) qui a été livrée en opus. Atomicité (reload unique, transaction DB), exhaustivité collecte erreurs, persistance cache 24h — opus a déjà livré ce type de pipeline avec succès.

5. **Validation defense-in-depth** : MAC normalisation + IP IPv4 + unicité multi-colonnes + collision avec réservations existantes. Cas limites nombreux (IPv6 hors scope mais à anticiper en column sizing, MAC en CISCO notation `aaaa.bbbb.cccc`, etc.). Sonnet rate plus souvent les edge cases que opus.

6. **Concurrence** : R2 du tableau risques (deux mutations simultanées → fichier corrompu). `Cache::lock` + `AtomicFileWriter` + ordre exact `lock → export → reload → unlock` — opus écrit ce pattern correctement du premier coup, sonnet a tendance à oublier le `try/finally` autour du lock.

7. **Documentation QA complète** : création `network.md` à partir de zéro (pas d'enrichissement append) — opus produit des runbooks QA plus détaillés et plus exécutables.

8. **Parser legacy `reservations.inc` (AC9 / T8b, ajouté 2026-05-11)** : transposition d'un fragment de configuration dhcpd ISC vers un upsert SQL idempotent. Cas tordus à gérer **sans avorter l'import** : lignes mal formées (accolade manquante, point-virgule oublié), MACs dupliquées dans le même fichier, espaces/tabs variables dans les blocs `host { ... }`, mix commentaires `#` et `//`, entrées partielles. La regex multi-ligne doit être tolérante mais sûre — opus produit ce type de parseur défensif avec moins de régressions que sonnet (qui a tendance à écrire des regex trop strictes qui plantent sur le premier fichier réel).

9. **Greffe sur page Livewire existante (T8b)** : ajout d'une étape à un composant SFC de 800+ lignes avec un pattern « 9 étapes » déjà installé (switch `runStep()`, méthode privée `runXxxSync()`, `addLog`, badges stats). Risque de régression sur les 9 étapes existantes si la greffe n'est pas chirurgicale. Opus respecte mieux le pattern en place sans le « refactoriser au passage ».

Le seul argument pour sonnet serait le coût ; mais l'epic 8 ne contient qu'une seule story (8.1), c'est la dernière FR « système » majeure de SER, et l'ajout AC9 (parser legacy + greffe sync-from-ad) renforce le besoin de rigueur. Opus justifié.
