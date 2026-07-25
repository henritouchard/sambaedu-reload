# QA Manuel — Domaine Réseau (DHCP)

> Runbook E2E pour le domaine réseau SE4FS. Append-only : chaque story
> ajoute une section avec ses scénarios numérotés stables.

**Stories couvertes** : 8.1 (réservations DHCP — FR20), 8.3 (sous-réseaux/VLAN
+ scripts DHCP versionnés). _Import CSV (FR22) désactivé. Story future ajoutera
DNS (FR21 reporté)._

**Code de référence (Story 8.1)** :

- `app/Models/DhcpReservation.php` — modèle Eloquent
- `app/Services/Network/DhcpService.php` — service shellout + parsing
- `database/migrations/2026_05_11_120000_create_dhcp_reservations_table.php`
- `config/sambaedu.php` — section `dhcp` (paths + script + service)
- `config/logging.php` — channel `network`
- `resources/views/pages/network/dhcp/` — page Livewire (index)
- `resources/views/pages/sync-from-ad/index.blade.php` — étape 10 (migration legacy)
- `app/Policies/DhcpPolicy.php` — gates `viewAny-dhcp` / `manage-dhcp`

### Tests automatisés non couverts

- **Concurrence verrou DHCP (`Cache::lock('dhcp.reload')`)** — non testée
  automatiquement. La suite tourne avec `CACHE_DRIVER=array` (single-process,
  pas de partage inter-instances) et le timeout `block(15)` interne au service
  rendrait un vrai test bloquant ~15 s. Vérification manuelle requise :
  ouvrir deux sessions Livewire en parallèle et déclencher deux `save()`
  simultanés ; la seconde doit afficher le toast warning « Verrou DHCP
  toujours détenu après 15s ». Cf. review code 8.1 #11 (S1).

---

## Pré-requis communs

### Système (VM SE4FS)

- VM accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- Migrations à jour : `cd /var/www/sambaedu-reload && php artisan migrate`
- Cache Spatie reset si évolutions permission : `php artisan permission:cache-reset`
- User PHP-FPM cible : `www-admin` (`ps -o user -p $(pgrep -f php-fpm | head -1)`)
- Service ISC DHCP : `systemctl status isc-dhcp-server` doit retourner `active`.
  Si non installé : `sudo apt install -y isc-dhcp-server`.
- Fichier de réservations writable par `www-data` :
  - `ls -l /etc/sambaedu/reservations.inc`
  - `sudo chgrp www-data /etc/sambaedu/reservations.inc && sudo chmod 0664 /etc/sambaedu/reservations.inc`
- Fichier de leases lisible : `ls -l /var/lib/dhcp/dhcpd.leases` (mode 0644 par défaut).
- Script de reload présent : `ls -l /usr/share/sambaedu/sbin/make_dhcpd_conf.sh`
  (attention au **bug legacy `/sh/share/...`** dans `dhcpd_restart()` —
  ne pas corriger ce chemin côté legacy, SER utilise le chemin correct via
  `config('sambaedu.dhcp.reload_command')`).

### Sudoers — hérité du packaging legacy

Le user `www-data` doit pouvoir invoquer sans mot de passe :
- `sudo systemctl is-active isc-dhcp-server.service`
- `sudo /usr/share/sambaedu/sbin/make_dhcpd_conf.sh`

**Sur une VM où sambaedu legacy tourne déjà**, ces règles sont **déjà en place**
(posées par le paquet `.deb` historique — le legacy utilise exactement les
mêmes commandes via `sambaedu/includes/dhcpd.inc.php` et `sambaedu/dhcp/config.php`).
**Rien à faire** dans ce cas.

Validation rapide (à exécuter une fois) :
```bash
sudo -u www-data sudo -n systemctl is-active isc-dhcp-server.service   # doit répondre active/inactive sans prompt
sudo -u www-data sudo -n /usr/share/sambaedu/sbin/make_dhcpd_conf.sh    # doit s'exécuter sans prompt
```

Si l'une des deux demande un mot de passe (cas VM neuve hors packaging),
créer `/etc/sudoers.d/sambaedu-dhcp` :
```
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl is-active isc-dhcp-server.service
www-data ALL=(root) NOPASSWD: /usr/share/sambaedu/sbin/make_dhcpd_conf.sh
```
puis `sudo visudo -cf /etc/sudoers.d/sambaedu-dhcp`.

### Permissions Spatie

L'opérateur de test doit avoir la permission Spatie **`server.admin`**
(gates `viewAny-dhcp` et `manage-dhcp` y sont mappées — cf.
`app/Policies/DhcpPolicy.php`).

---

## Story 8.1 — Gestion des Réservations DHCP et Baux Actifs

**Date livraison** : 2026-05-11
**Migration à appliquer** : `2026_05_11_120000_create_dhcp_reservations_table`

> **Décision archi consolidée** : la source de vérité SER est la table
> `dhcp_reservations`. Le fichier `/etc/sambaedu/reservations.inc` est un
> EXPORT dérivé, régénéré atomiquement après chaque mutation par
> `DhcpService::exportReservationsFile()`. **Ne jamais éditer le fichier
> manuellement** : la prochaine mutation l'écrasera. Pour modifier une
> réservation, passer par l'UI `/app/network/dhcp` ou (one-shot) par
> l'étape 10 de `/sync-from-ad`.

---

### Section 1 — Réservations CRUD

#### Scénario 8.1-1.1 — Listing initial + bannière statut service

1. Se connecter en utilisateur `server.admin`.
2. Vérifier la présence du menu **"Réseau (DHCP)"** dans la sidebar.
3. Cliquer dessus → URL `/app/network/dhcp`.
4. Vérifier les éléments de page :
   - Titre **"Réservations DHCP"**.
   - Bannière **verte** "Service DHCP actif" (ou rouge si service down — cf. Section 4).
   - Section **"Réservations"** avec le compteur entre parenthèses (0 si DB vide).
   - Section **"Baux actifs"** (peut être vide si pas de client DHCP connecté).
   - Bouton **"Nouvelle réservation"** dans l'en-tête (le bouton "Importer CSV" est désactivé — FR22 reporté).
5. Vérifier les logs : `tail -n 5 storage/logs/network/network-$(date +%Y-%m-%d).log` ne montre
   pas d'erreur à la consultation simple.

#### Scénario 8.1-1.2 — Création d'une réservation manuelle

1. Cliquer **"Nouvelle réservation"** → la modale s'ouvre.
2. Saisir :
   - Nom : `posteTestQA1`
   - MAC : `aa:bb:cc:dd:ee:01` (format canonique)
   - IP : `10.0.0.50`
   - Description : `Test QA story 8.1`
3. Cliquer **"Créer"**.
4. Toast **success** : "Réservation créée et service DHCP rechargé."
5. La modale se ferme, la liste se rafraîchit, la ligne `posteTestQA1` apparaît
   avec badge source `manual` (bleu).
6. Vérifier en BD : `sudo -u postgres psql -d sambaedu -c "SELECT name, mac, ip, source FROM dhcp_reservations WHERE name='posteTestQA1';"` → 1 ligne.
7. Vérifier la régénération fichier : `cat /etc/sambaedu/reservations.inc | grep posteTestQA1`
   → ligne `host posteTestQA1 { hardware ethernet aa:bb:cc:dd:ee:01; fixed-address 10.0.0.50; }`.
8. Vérifier le reload effectif : `sudo systemctl status isc-dhcp-server.service`
   doit toujours afficher `active (running)` ; le `dhcpd.conf` doit inclure la nouvelle ligne.

#### Scénario 8.1-1.3 — Création d'une réservation liée à un Workstation existant

1. Pré-requis : avoir un poste `posteLab01` dans `workstations` (table déjà peuplée
   ou créer via `/app/parc`).
2. Cliquer **"Nouvelle réservation"**.
3. Saisir **nom = `posteLab01`** (live binding) → le selector "Machine du parc"
   apparaît avec la liste des Workstation matchant.
4. Sélectionner `posteLab01` dans le dropdown.
5. Saisir MAC `aa:bb:cc:dd:ee:02`, IP `10.0.0.51`.
6. Créer → la ligne apparaît avec un lien cliquable vers `/app/parc/machines/{id}`
   dans la colonne "Machine liée".

#### Scénario 8.1-1.4 — Édition + suppression

1. Cliquer l'icône **stylo** sur `posteTestQA1`.
2. Modifier l'IP en `10.0.0.150`, sauvegarder.
3. Toast success "Réservation modifiée et service DHCP rechargé."
4. Vérifier en BD : IP mise à jour.
5. Cliquer l'icône **poubelle** sur la même ligne.
6. Modale de confirmation : "Voulez-vous vraiment supprimer la réservation posteTestQA1 (10.0.0.150) ?"
7. Confirmer → toast success "Réservation supprimée et service DHCP rechargé."
8. La ligne disparaît de la liste. Vérifier en BD que la ligne est supprimée.
9. Vérifier que `reservations.inc` ne contient plus la ligne.

#### Scénario 8.1-1.5 — Validations (cas d'erreur)

1. Tenter de créer avec MAC invalide `XY:ZZ:11:22:33` → toast erreur
   "Format MAC invalide (12 caractères hexadécimaux attendus)." + erreur inline.
2. Tenter avec IP invalide `not.an.ip` → toast erreur "Format IP invalide".
3. Tenter de créer 2 réservations avec la même MAC → 2e tentative bloquée
   "Cette MAC est déjà réservée pour la machine X".
4. Idem doublon IP, doublon nom.
5. Tenter formats MAC alternatifs : `aa-bb-cc-dd-ee-01`, `aabbccddee01`,
   `aabb.ccdd.ee01` → tous normalisés en `aa:bb:cc:dd:ee:01` en BD.

#### Scénario 8.1-1.6 — Permissions

1. Se déconnecter, se reconnecter en utilisateur **non server.admin** (ex : élève).
2. Tenter d'accéder à `/app/network/dhcp` directement.
3. Page renvoie **403**.
4. Le menu "Réseau (DHCP)" doit être **absent** de la sidebar pour ce profil.

---

### Section 2 — Baux actifs (lecture `dhcpd.leases`)

#### Scénario 8.1-2.1 — Liste des baux

1. Pré-requis : avoir au moins un client DHCP qui a obtenu un bail récemment
   (ex : un poste Windows qui a démarré).
2. Sur `/app/network/dhcp`, scroller vers la section "Baux actifs".
3. Vérifier les colonnes : Hostname, MAC, IP, État (badge `active` vert ou `free` gris),
   Expire, bouton "Réserver ce bail".
4. Cliquer sur **"Réserver ce bail"** → la modale création s'ouvre, pré-remplie
   avec MAC + IP du bail. Compléter le nom et créer.

#### Scénario 8.1-2.2 — Mode dégradé lecture

1. Sur la VM : `sudo systemctl stop isc-dhcp-server.service`.
2. Recharger `/app/network/dhcp`.
3. La bannière haute passe en **rouge** "Service DHCP injoignable".
4. La section "Baux actifs" reste affichée mais peut être vide.
5. La table des réservations reste lisible (lecture BD pure).
6. Relancer : `sudo systemctl start isc-dhcp-server.service`. La bannière redevient verte.

---

### Section 3 — Import CSV (FR22) — DÉSACTIVÉ

> Feature retirée du scope 8.1 livré. Le bouton "Importer CSV" et les routes
> `/app/network/dhcp/import/*` sont désactivés en production. Scénarios QA
> retirés. Voir éventuellement la story de réactivation à venir.

---

### Section 4 — Mode dégradé (AC6)

#### Scénario 8.1-4.1 — Création pendant service down

1. Sur la VM : `sudo systemctl stop isc-dhcp-server.service`.
2. Sur `/app/network/dhcp` : la bannière haute passe en **rouge**.
3. Tenter une création avec MAC + IP valides.
4. Toast **warning** (orange, pas rouge) : "Réservation enregistrée. Le service DHCP n'a pas pu être rechargé — relancer le service manuellement. (cause : …)".
5. La modale se ferme. La nouvelle ligne **est présente en BD et dans la liste UI**.
6. Vérifier dans `storage/logs/network/network-*.log` : ligne `ERROR` avec
   `DhcpService: reload service échoué` + stderr.
7. Relancer le service : `sudo systemctl start isc-dhcp-server.service`.
   Manuellement : `sudo /usr/share/sambaedu/sbin/make_dhcpd_conf.sh` pour reload
   complet (régénération + reload service).
8. Vérifier que la réservation créée est bien prise en compte (un client
   sortant l'IP doit l'obtenir).

#### Scénario 8.1-4.2 — Sudoers refusé

1. Désactiver temporairement le sudoers : `sudo mv /etc/sudoers.d/sambaedu-dhcp /tmp/`.
2. Tenter une création → même comportement que 4.1 : toast warning, réservation persistée,
   reload en erreur (cette fois c'est `sudo: a password is required`).
3. Restaurer : `sudo mv /tmp/sambaedu-dhcp /etc/sudoers.d/`.

---

### Section 5 — Concurrence reload (R2)

#### Scénario 8.1-5.1 — Deux mutations simultanées

1. Ouvrir 2 onglets `/app/network/dhcp` connectés en `server.admin`.
2. Dans chaque onglet, préparer une création différente (MAC/IP distinctes).
3. Cliquer **"Créer"** sur les deux dans un intervalle < 1 s.
4. Les deux opérations doivent réussir. Vérifier `reservations.inc` :
   contient bien **les deux** lignes (l'ordre est garanti par tri `name`).
5. **Pas** de fichier corrompu (= deux fragments de `host {...}` mélangés) :
   `/etc/sambaedu/reservations.inc` doit être un fichier complet et valide.

> Garantie : `Cache::lock('dhcp.reload', 30)` autour de `exportReservationsFile + reloadService`.
> En cas de contention, l'opération en attente attend max 15 s puis échoue
> avec `DhcpCommandException` "Verrou DHCP indisponible".

---

### Section 6 — Migration legacy via `/sync-from-ad` (AC9 / T8b)

#### Scénario 8.1-6.1 — Import one-shot du fichier legacy

1. Pré-requis : VM avec fichier `/etc/sambaedu/reservations.inc` héritage SE3
   (peuplé par la migration depuis SE3). Backup recommandé :
   `sudo cp /etc/sambaedu/reservations.inc /etc/sambaedu/reservations.inc.bak`.
2. Aller sur `/admin/sync-from-ad`.
3. Scroller jusqu'à l'**étape 10 — "Importer les réservations DHCP"**.
4. Cliquer le bouton **play** de l'étape.
5. Statut passe à running puis à success.
6. Badges stats affichés : `N parsés`, `+N créées`, `~N màj`, etc.
7. Cliquer sur la flèche pour déplier les logs : voir le détail
   ligne par ligne (créées + erreurs s'il y a des blocs malformés).

#### Scénario 8.1-6.2 — Idempotence (rejeu)

1. Cliquer à nouveau **play** sur l'étape 10.
2. Stats : `+0 créées`, `~N màj` (toutes les lignes déjà en base sont updates).
3. Aucun doublon créé en BD : `SELECT COUNT(*) FROM dhcp_reservations WHERE source='legacy-migration'`
   doit être stable d'un passage à l'autre.

#### Scénario 8.1-6.3 — Liaison Workstation lors de la migration

1. Pré-requis : avoir au moins un Workstation dont `name` matche un `host`
   du fichier legacy (par exemple, importer d'abord les workstations via
   étape 3 de la même page `/sync-from-ad`).
2. Lancer l'étape 10.
3. Vérifier en BD : `SELECT name, workstation_id FROM dhcp_reservations WHERE source='legacy-migration' AND workstation_id IS NOT NULL`
   → liste des liaisons effectuées.

#### Scénario 8.1-6.4 — Préservation de la source `manual` sur rejeu

1. Créer manuellement une réservation pour `posteXX` via l'UI (= source `manual`).
2. S'assurer que le fichier legacy contient un `host posteXX { ... }` avec
   les **mêmes** MAC/IP.
3. Lancer l'étape 10.
4. La ligne est mise à jour mais **conserve `source='manual'`** (et non
   `legacy-migration` qui serait moins spécifique).

#### Scénario 8.1-6.5 — Aucun reload déclenché par l'étape 10

1. Avant lancement, regarder `tail -f storage/logs/network/network-*.log`.
2. Lancer l'étape 10.
3. Aucune ligne `DhcpService: reload service` ne doit apparaître dans les logs
   (l'étape lit le fichier conf ; elle n'écrit ni ne reload).

---

### Section 7 — Bascule legacy → SER (informatif, non bloquant)

> Le shim 1bis-16 (`legacy/modules/dhcp/`) reste **actif en parallèle** de
> cette story 8.1 — via le catchall Laravel. Aucun retrait dans le scope
> 8.1. La bascule complète (suppression du shim + redirect `legacy/dhcp/*`
> vers `/app/network/dhcp`) sera arbitrée dans une story de follow-up
> (similaire à la trajectoire `printers` post-6.1).

> **Conseil** : pendant la transition, **éviter les éditions croisées**
> (modifier la même réservation via l'UI legacy ET via l'UI native).
> La table `dhcp_reservations` est la source de vérité côté natif ; toute
> édition legacy passera par un `set_dhcp_reservation()` qui modifie l'AD
> et le fichier conf, **pas** la table SQL — l'UI native ne verra pas la
> modification (cf. risque R6 dans la story).

---

## Checklist rapide (à cocher en fin de relecture)

- [ ] Migrations appliquées (`dhcp_reservations` créée)
- [ ] Sudoers DHCP fonctionnels (déjà déployés par le packaging legacy — vérification : `sudo -u www-data sudo -n systemctl is-active isc-dhcp-server.service` répond sans prompt)
- [ ] Service `isc-dhcp-server` actif, fichier `reservations.inc` writable par www-data
- [ ] Section 1 (CRUD) verte
- [ ] Section 2 (Baux) verte
- [ ] Section 4 (Mode dégradé) verte — réservation persiste même reload échoué
- [ ] Section 5 (Concurrence) verte — pas de fichier corrompu
- [ ] Section 6 (Migration legacy via /sync-from-ad) verte — idempotente, lien Workstation OK, source préservée
- [ ] Logs channel `network` lisibles (`storage/logs/network/network-*.log`)
- [ ] Permission 403 correct pour profil non `server.admin`

---

## Story 8.3 — Sous-réseaux DHCP (VLAN) + scripts DHCP versionnés

**Date livraison** : 2026-07-11
**Migration à appliquer** : `2026_07_11_120000_create_dhcp_subnets_table`

> **Décision archi (D1)** : la table `dhcp_subnets` est la source de vérité des
> VLAN gérés. `DhcpSubnetService::exportSubnetsFile()` rend atomiquement le
> fichier de params `/etc/sambaedu/sambaedu.conf.d/dhcp-subnets.conf` (clés
> plates `dhcp_reseau_<N>`, `dhcp_masque_<N>`, …) que `make_dhcpd_conf.sh`
> consomme pour émettre les blocs `subnet {}`. **Pas** de génération native de
> `dhcpd.conf` (D2 — zone iPXE à risque). Le **sous-réseau par défaut** (VLAN 0)
> reste géré par l'autoconf serveur (`dhcp.conf`) et est affiché en LECTURE
> SEULE (D3). N° de VLAN ∈ 1..999 (D4). Fichier de params INI strict
> `clé = "valeur"` sans espace dans les valeurs (D5).

**Code de référence** :

- `app/Models/DhcpSubnet.php` — modèle Eloquent (`ranges` cast array)
- `app/Services/Network/DhcpSubnetService.php` — validations pures + CRUD + export
- `database/migrations/2026_07_11_120000_create_dhcp_subnets_table.php`
- `config/sambaedu.php` — clé `dhcp.subnets_file`
- `resources/views/pages/network/dhcp/index.blade.php` — onglet « Sous-réseaux »
- `resources/views/pages/network/dhcp/_partials/subnets-table.blade.php`
- `scripts/system/make_dhcpd_conf.sh`, `scripts/system/dhcp-dyndns.sh` — scripts versionnés
- `scripts/update.sh` — `ensure_dhcp_scripts()`

### Pré-requis spécifiques

- Reload DHCP disponible (mêmes sudoers que 8.1 : `make_dhcpd_conf.sh` + `systemctl is-active`).
- Fichier de params writable par `www-admin` :
  `sudo touch /etc/sambaedu/sambaedu.conf.d/dhcp-subnets.conf && sudo chown www-admin: /etc/sambaedu/sambaedu.conf.d/dhcp-subnets.conf`
  (créé automatiquement au 1er export ; si le dossier est root-only, poser les droits une fois).

---

### Section 8 — Sous-réseaux (VLAN) : CRUD

#### Scénario 8.3-8.1 — Création d'un VLAN mono-plage

1. `/app/network/dhcp` → onglet « Sous-réseaux ». Vérifier la carte
   « Sous-réseau par défaut » (lecture seule, badge « géré par l'autoconf serveur »).
2. « Nouveau sous-réseau » → VLAN `20`, réseau `192.168.20.0/24`, passerelle
   `192.168.20.254`, plage `192.168.20.10` → `192.168.20.200`. Créer.
3. Toast succès « Sous-réseau créé et service DHCP rechargé. »
4. La ligne apparaît dans la table (VLAN 20, CIDR, passerelle, plage).
5. Vérifier le fichier généré :
   `grep dhcp_reseau_20 /etc/sambaedu/sambaedu.conf.d/dhcp-subnets.conf`
   → `dhcp_reseau_20 = "192.168.20.0"`, `dhcp_masque_20 = "255.255.255.0"`,
   `dhcp_gateway_20 = "192.168.20.254"`, `dhcp_begin_range_20`/`dhcp_end_range_20`.
6. Vérifier le `dhcpd.conf` régénéré :
   `grep -A3 'SUBNET DECLARATION 20' /etc/dhcp/dhcpd.conf` → bloc `subnet 192.168.20.0 netmask 255.255.255.0 { range … ; option routers 192.168.20.254; }`.

#### Scénario 8.3-8.2 — Plages dynamiques multiples

1. Éditer le VLAN 20 → « Ajouter une plage ». Déclarer 2 plages :
   `192.168.20.10`→`192.168.20.50` et `192.168.20.100`→`192.168.20.150`. Enregistrer.
2. Fichier de params :
   `dhcp_begin_range_20 = "192.168.20.10"` (1re plage sans suffixe) ET
   `dhcp_begin_range_20_1 = "192.168.20.100"` (2e plage, suffixe `_1` contigu dès 1).
3. `dhcpd.conf` : le bloc `subnet … 20` contient **deux** lignes `range`.

#### Scénario 8.3-8.3 — Validations refusées en bloc

Tenter puis vérifier le refus (toast erreur, aucune écriture, transaction tout-ou-rien) :

1. CIDR invalide (`192.168.20.0` sans `/`, `/33`).
2. N° VLAN hors 1..999 (`0`, `1000`) ou déjà pris.
3. Passerelle hors réseau (`10.0.0.1` pour `192.168.20.0/24`).
4. Plage hors réseau ; début > fin.
5. Réseau chevauchant un autre VLAN OU le sous-réseau par défaut.
6. Plage recouvrant l'IP d'une réservation DHCP existante
   (créer d'abord une réservation `192.168.20.50` puis un VLAN dont la plage
   englobe `.50` → refus).

#### Scénario 8.3-8.4 — Suppression (retrait réel des clés)

1. Supprimer le VLAN 20 (modale de confirmation).
2. Toast succès. Le fichier de params ne contient PLUS de clé `dhcp_*_20`
   (`grep dhcp_reseau_20 …` → vide) — capacité absente du legacy.
3. `dhcpd.conf` régénéré ne contient plus le bloc `SUBNET DECLARATION 20`.

#### Scénario 8.3-8.5 — Mode dégradé (AC5)

1. Arrêter isc-dhcp-server (`sudo systemctl stop isc-dhcp-server`) OU casser
   temporairement le reload.
2. Créer/éditer un VLAN. Attendu : toast **warning** « Sous-réseau enregistré.
   Le service DHCP n'a pas pu être rechargé — relancer le service manuellement. »
   (pas d'erreur bloquante).
3. Vérifier que le sous-réseau est **persisté en SQL** et que le fichier de
   params a bien été régénéré (la saisie n'est jamais perdue).

---

### Section 9 — Scripts DHCP versionnés (greenfield `ensure_dhcp_scripts`)

#### Scénario 8.3-9.1 — Déploiement idempotent

1. Sur une VM sans les paquets `sambaedu-*` (ou pour valider) :
   `sudo bash /var/www/sambaedu-reload/scripts/update.sh` (ou rejouer l'update).
2. Vérifier que les 2 scripts sont déployés :
   `ls -l /usr/share/sambaedu/sbin/make_dhcpd_conf.sh /usr/share/sambaedu/sbin/dhcp-dyndns.sh`
   (mode `755`).
3. Rejouer l'update : les logs indiquent « déjà à jour » (comparaison de
   contenu `cmp -s` **et** du bit exécutable `-x`, pas de réécriture inutile ;
   cf. Scénario 8.3-PC.2 pour le cas d'un mode dégradé).
4. Vérifier que la copie SE5 de `make_dhcpd_conf.sh` **n'appelle plus**
   `action_cron_php.sh dhcp/script_make_reservations.php`
   (`grep script_make_reservations /usr/share/sambaedu/sbin/make_dhcpd_conf.sh` → vide)
   mais conserve l'inclusion conditionnelle de `reservations.inc`
   (`grep 'reservations.inc' …` → présent).
5. Vérifier que le `dhcpd.conf` généré chaîne toujours le bootstrap natif
   `/ipxe/boot` (options boot iPXE arch 00:00/06/07 intactes) et les hooks
   `on commit/release/expiry` vers `dhcp-dyndns.sh`.

---

## Checklist rapide — Story 8.3

- [ ] Migration `dhcp_subnets` appliquée
- [ ] Section 8 (CRUD VLAN) verte — création mono/multi-plages, validations, suppression
- [ ] Fichier `dhcp-subnets.conf` généré, clés `dhcp_*_N` correctes, writable `www-admin`
- [ ] `dhcpd.conf` régénéré contient les blocs `subnet {}` par VLAN (multi-`range` OK)
- [ ] Sous-réseau par défaut affiché en lecture seule
- [ ] Mode dégradé (AC5) — VLAN persiste même si reload échoué, toast warning
- [ ] Section 9 (scripts versionnés) verte — `ensure_dhcp_scripts` idempotent, appel legacy retiré, hooks iPXE/dyndns intacts

---

## Post-correctifs & non-régressions — Story 8.3

> Incidents détectés en code review (2026-07-11) et corrigés avant merge. Angles
> de test à re-dérouler en priorité, car non couverts par l'intuition initiale.

| Incident | Type | Correctif | Couvert par |
|----------|------|-----------|-------------|
| #1 — Injection shell RCE root via `extra_option` | Sécurité 🔴 | Liste blanche stricte chemin absolu | Unit `validate_extra_option_rejects_injection_payloads` + Scénario 8.3-PC.1 |
| #2 — `ensure_dhcp_scripts` non idempotent sur le mode | Robustesse | `[[ -x "$dst" ]]` ajouté à la condition de skip | Scénario 8.3-PC.2 |
| #3 — TOCTOU chevauchement / `vlan_id` | Concurrence | Lock `dhcp.reload` acquis avant validation+écriture | Section 5 (concurrence) transposée aux VLAN |

#### Scénario 8.3-PC.1 — `extra_option` refuse toute injection (sécurité)

1. Onglet Sous-réseaux → créer/éditer un VLAN. Dans le champ « Fichier d'option
   supplémentaire », saisir successivement :
   - `/a';{touch,/tmp/pwned};x='` (payload d'injection réel)
   - `/etc/$(id)`, `/etc/\`id\``, `/etc/x;reboot`, `/etc/a b.conf`
2. **Attendu** : chaque valeur est **refusée** (toast/erreur « chemin absolu sans
   espace ni caractère spécial ») ; aucune écriture en base ni dans
   `dhcp-subnets.conf`.
3. Vérifier qu'aucun fichier `/tmp/pwned` n'a été créé sur la VM :
   `ls /tmp/pwned` → « No such file » (le parseur `config.inc.sh` `eval`-ue en
   root — un seul passage suffirait à l'exécution).
4. **Cas nominal** : `/etc/dhcp/vlan20.conf` est accepté et bien exporté
   (`grep dhcp_extra_option_20 /etc/sambaedu/sambaedu.conf.d/dhcp-subnets.conf`).

#### Scénario 8.3-PC.2 — Redéploiement si bit exécutable perdu

1. Scripts déjà déployés (Section 9). Simuler une perte du bit x :
   `chmod 644 /usr/share/sambaedu/sbin/make_dhcpd_conf.sh`.
2. Rejouer `sudo bash …/scripts/update.sh`.
3. **Attendu** : le log indique « déployé » (pas « déjà à jour ») et
   `ls -l …/make_dhcpd_conf.sh` montre de nouveau le mode `755` — `sudo
   make_dhcpd_conf.sh` refonctionne (pas d'EACCES silencieux).

---

## Story 8.4 — DDNS piloté par DHCP (endpoint natif idempotent)

> Le serveur DHCP maintient les enregistrements **A** de l'AD pour les machines
> que le DDNS sécurisé Windows ne couvre pas : clients Linux, machines en cours
> d'installation iPXE (avant jointure), appareils hors domaine — et il nettoie
> les A périmés que Windows laisse derrière lui.
>
> Deux défauts legacy corrigés ici, à vérifier explicitement :
> 1. **Fonction morte** — `dhcp/dnsupdate.php` répondait **500** à chaque appel,
>    silencieusement (`curl -s -f` détaché). Plus aucun A record n'était maintenu.
> 2. **Écritures inutiles** — `on commit` de dhcpd se déclenche à **chaque
>    renouvellement** de bail (~toutes les 5 min avec `default-lease-time 600`),
>    et le legacy réécrivait le record à chaque fois : ~288 écritures/jour/poste
>    pour une IP inchangée. L'endpoint natif est **level-triggered** : il lit
>    l'état, compare, et n'écrit que si l'état diffère.

### Pré-requis spécifiques

- `update.sh` a redéployé `dhcp-dyndns.sh` (`ensure_dhcp_scripts`) — vérifier
  que `/usr/share/sambaedu/sbin/dhcp-dyndns.sh` cible bien
  `http://127.0.0.1/dhcp/dnsupdate` et poste en `--form-string` :
  `grep -E 'dnsupdate|form-string' /usr/share/sambaedu/sbin/dhcp-dyndns.sh`
- Suivi en direct : `tail -f storage/logs/network/network-*.log | grep ddns`
- État DNS de référence :
  `samba-tool dns query <se4ad_name> <domain> <poste> A -U Administrator`

**Garde d'origine (`dhcp.server.request`)** : l'endpoint n'authentifie pas
l'appelant et sait supprimer des enregistrements DNS — il n'accepte donc QUE le
loopback et `se4fs_ip` (`/etc/sambaedu/sambaedu.conf`), pas l'allowlist du parc.
Un 403 dans `sambaedu-reload-access.log` sur `/dhcp/dnsupdate` signifie que
l'appel ne vient pas du serveur : vérifier que le script cible bien `127.0.0.1`
(un appel via le nom d'hôte présente l'IP LAN et sera refusé).

### Section 10 — DDNS

#### Scénario 10.1 — Renouvellement de bail : AUCUNE écriture DNS (le test clé)

1. Un poste est allumé avec un bail actif et son A record correct.
2. Attendre 2 à 3 renouvellements (≈ 5 min chacun, cf. `default-lease-time`),
   ou forcer côté client : `ipconfig /renew` (Windows) / `dhclient -r && dhclient` (Linux).
3. **Attendu** dans `network.log` : une ligne `[ddns] unchanged` par
   renouvellement, avec `"wrote": false`.
4. **Attendu** : aucune ligne `created` / `updated`. Le `serial` du record ne
   bouge pas :
   `samba-tool dns query … <poste> A` → `serial=` identique avant/après.
5. **Si des `updated` apparaissent en boucle** : la lecture d'état est en échec
   (droits samba-tool, zone/serveur mal résolus) — vérifier les lignes
   `[ddns] exception` du log. C'est exactement le défaut que la story corrige.
   **Exception attendue — machine bi-domiciliée** : un portable dont le Wi-Fi et
   l'Ethernet sont actifs simultanément présente le même `client-hostname` sur
   deux baux ; chaque renouvellement bascule alors le A record d'une interface à
   l'autre (`updated` en boucle, ~2 écritures/5 min). Ce n'est PAS une panne de
   lecture — c'est l'invariant « un nom = une IP », déjà celui du legacy.
   Vérifier avec `ip a` / `ipconfig /all` côté poste avant de conclure.
6. **Si l'état DNS devient illisible** (DC injoignable, ticket expiré), le
   service sort en `failed` **sans écrire** : ni `add`, ni `delete`. Une panne
   ne doit jamais produire de mutation DNS.

#### Scénario 10.2 — Changement d'IP : purge puis ajout

1. Changer l'IP d'un poste (réservation DHCP modifiée, ou bascule de VLAN).
2. Relancer un bail (`ipconfig /renew`).
3. **Attendu** : `[ddns] updated` dans le log ; `samba-tool dns query` ne
   retourne **qu'une seule** ligne `A:` — la nouvelle IP. L'ancienne a été
   supprimée (sinon le nom résoudrait aléatoirement vers deux adresses).

#### Scénario 10.3 — Nouveau poste : création

1. Enrôler/booter une machine inconnue du DNS (typiquement une installation
   iPXE, avant jointure au domaine).
2. **Attendu** : `[ddns] created`, puis le nom résout :
   `host <poste>.<domain> <se4ad_ip>` renvoie l'IP du bail.

#### Scénario 10.4 — Libération de bail : nettoyage

1. Libérer le bail d'un poste : `ipconfig /release` (Windows), `dhclient -r`
   (Linux), ou éteindre le poste et laisser le bail expirer.
2. **Attendu** : `[ddns] deleted` ; le A record disparaît de la zone.
3. **Note** : dhcpd ne transmet PAS le nom sur `on release`/`on expiry` — le
   serveur retrouve le porteur de l'IP par balayage de zone. Le legacy s'appuyait
   sur un `gethostbyaddr()` inopérant (aucun PTR n'est créé) : le nettoyage ne se
   faisait **jamais**. Si des A records fantômes préexistent, ils ne seront purgés
   qu'au prochain release de l'IP concernée.

#### Scénario 10.5 — Garde-fous (noms ignorés, hors établissement, injection)

1. Un client se présentant avec un `client-hostname` en `l-…`, `dhcp-…` ou
   `iphone…` obtient un bail : **attendu** `[ddns] skipped`, aucune écriture,
   aucun appel `samba-tool` (machines éphémères, parité legacy).
2. Sur une instance rattachée à un établissement (`etab_ou` = UAI), un nom sans
   le suffixe établissement (ex. `-1229y`) → `skipped`.
3. **Sécurité** : le `client-hostname` est choisi par le client. Forcer un nom
   hostile (`pc-01; reboot`, `pc$(id)`, nom avec espace) → `skipped`, jamais
   d'exécution. Vérifier qu'aucune commande anormale n'apparaît dans le log et
   que la machine n'a pas redémarré.

#### Scénario 10.6 — Chemin legacy encore appelé (instance non redéployée)

1. Simuler un script non mis à jour :
   `curl -s -F "action=add" -F "name=<poste>" -F "ip=<ip>" http://<se4fs>/dhcp/dnsupdate.php`
2. **Attendu** : réponse `text/plain` contenant l'issue (`unchanged`, `created`…),
   **pas** du HTML ni une 500 — le chemin `.php` est servi nativement.
3. **Attendu 38.6** : ce hit ne doit PAS apparaître comme legacy dans
   `php artisan se4:status` (il est servi par une route native, il n'atteint
   jamais le catchall).

### Checklist rapide — Story 8.4

- [ ] `dhcp-dyndns.sh` déployé pointe sur `/dhcp/dnsupdate`
- [ ] Scénario 10.1 vert — **renouvellements = `unchanged`, zéro écriture** (le point central)
- [ ] Scénario 10.2 vert — changement d'IP : un seul A record final
- [ ] Scénario 10.3 vert — création pour une machine nouvelle
- [ ] Scénario 10.4 vert — libération de bail : record supprimé
- [ ] Scénario 10.5 vert — préfixes ignorés, hors-étab, noms hostiles refusés
- [ ] Scénario 10.6 vert — chemin `.php` servi nativement, absent du verdict `se4:status`
- [ ] Scénario 10.7 vert — records d'infrastructure protégés
- [ ] `se4:status` ne liste plus `dhcp/dnsupdate.php` en hit legacy (débloque le GO 38.6)

## Post-correctifs & non-régressions — Story 8.4

| Incident | Constat | Correctif | Non-régression |
|---|---|---|---|
| **#I1 — Suppression du A record du DC** (2026-07-21, vérification sur /vm) | Un `delete` **sans nom** sur l'IP du DC déclenche le balayage de zone, trouve `se4ad` — que ni les préfixes ignorés ni le suffixe d'établissement n'écartaient — et supprime son enregistrement A. `se4ad.localdev.fr` cesse de résoudre ; le DC reste joignable par IP, donc la panne est **silencieuse** jusqu'au premier service qui résout le nom. | `isEligible()` exclut désormais `se4ad_name` et `se4fs_name` sur **les deux** chemins (`add` et `delete`, balayage compris). | `infrastructure_records_are_never_deleted_by_scan`, `infrastructure_records_are_not_touched_by_add_either` |

**Pourquoi ce n'est pas qu'un artefact de test** : les serveurs peuvent porter une
réservation DHCP (`make_dhcpd_conf.sh` en génère), donc un `on release` / `on expiry`
réel sur cette IP suffit à reproduire le cas en production.

**Réparation si le cas s'est déjà produit** (le nom du DC ne résout plus, donc
`samba-tool` doit viser l'**IP** du DC et non son FQDN) :

```
php artisan tinker --execute='
$r = app(App\Gpo\Support\SambaToolRunner::class)->withoutDirectoryUrl()
    ->run(["dns","add","<IP_DC>",config("sambaedu.domain"),config("sambaedu.se4ad_name"),"A","<IP_DC>"]);
echo $r->exitCode()." ".$r->output();'
```

Vérifier ensuite `getent hosts <se4ad_name>.<domain>`.

#### Scénario 10.7 — Records d'infrastructure protégés

1. Relever l'IP du DC (`se4ad_ip`) et celle du serveur (`se4fs_ip`).
2. Depuis le serveur : `curl -H "Host: <se4fs_name>" --form-string "action=delete" --form-string "ip=<IP_DC>" http://127.0.0.1/dhcp/dnsupdate`
3. **Attendu** : réponse `unchanged`, ligne de log `"wrote": false`, et
   `getent hosts <se4ad_name>.<domain>` résout toujours.
4. Idem avec l'IP du serveur de fichiers.

### Constat empirique — réservation ≠ exemption DDNS (2026-07-25)

Vérifié sur /vm avec un vrai client DHCP (netns + macvlan Docker sur `virbr0`,
dhcpd sur le guest `.50`) : une réservation `fixed-address` **ne dispense pas**
du canal DDNS. ISC dhcpd 4.4.3 déclenche `on commit` (`add`) et `on release`
(`delete`) même pour un hôte à IP fixe (`leased-address` est renseigné, sans
qu'un bail soit écrit dans `dhcpd.leases`). Un périphérique réservé
(imprimante/NAS) voit donc son A record créé au boot et supprimé à l'extinction.

Ce n'est pas un problème : les événements portent sur le **propre** nom/IP du
périphérique, et la garde infrastructure (scénario 10.7) couvre les serveurs.
La suppression croisée de l'incident I1 ne s'applique pas aux IP fixes (pas de
réutilisation d'IP entre noms).

#### Scénario 10.8 — Réservation dans une plage dynamique refusée

Garde `DhcpService::assertIpNotInDynamicRange()` — **conflit d'IP**, indépendant
du DDNS : une IP réservée dans une plage servie dynamiquement pourrait être
attribuée par bail à un autre poste.

1. Plage par défaut sur /vm : `192.168.122.100–200` (`dhcp_begin_range` /
   `dhcp_end_range` de `sambaedu.conf`).
2. `/app/network/dhcp?tab=reservations` → créer une réservation avec IP
   **dans** la plage (ex. `.150`).
3. **Attendu** : refus, message « L'IP … est dans la plage dynamique …
   (sous-réseau par défaut) : un poste pourrait recevoir cette adresse par bail,
   créant un conflit. » (erreur de champ + toast).
4. IP **hors** plage (ex. `.55`, ou `.201–.254`) → acceptée.
5. Même refus pour une IP tombant dans la plage d'un VLAN géré (message
   « VLAN <id> »).

- [ ] Scénario 10.8 vert — réservation en plage dynamique refusée (défaut + VLAN)
