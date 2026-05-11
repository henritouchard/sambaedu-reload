# QA Manuel — Domaine Réseau (DHCP)

> Runbook E2E pour le domaine réseau SE4FS. Append-only : chaque story
> ajoute une section avec ses scénarios numérotés stables.

**Stories couvertes** : 8.1 (DHCP — FR20 + FR22). _Stories futures Epic 8.2
ajouteront DNS (FR21 reporté) et multi-VLAN._

**Code de référence (Story 8.1)** :

- `app/Models/DhcpReservation.php` — modèle Eloquent
- `app/Services/Network/DhcpService.php` — service shellout + parsing
- `app/Services/Network/DhcpImportService.php` — pipeline import CSV
- `database/migrations/2026_05_11_120000_create_dhcp_reservations_table.php`
- `config/sambaedu.php` — section `dhcp` (paths + script + service)
- `config/logging.php` — channel `network`
- `resources/views/pages/network/dhcp/` — pages Livewire (index, import)
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
   - Boutons **"Nouvelle réservation"** + **"Importer CSV"** dans l'en-tête.
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

### Section 3 — Import CSV (FR22)

#### Scénario 8.1-3.1 — Import nominal

1. Préparer un fichier CSV local `reservations-test.csv` :
   ```
   name,mac,ip,description
   posteSalle1,00:11:22:33:44:55,10.0.0.60,Salle informatique poste #1
   imprimanteCDI,AA:BB:CC:DD:EE:FF,10.0.0.30,Imprimante CDI
   posteOk2,11:22:33:44:55:66,10.0.0.61,Poste valide
   ```
2. Sur `/app/network/dhcp`, cliquer **"Importer CSV"**.
3. Sélectionner le fichier, cliquer **"Importer"**.
4. Toast sticky avec lien **"Voir le rapport"** : "3 réservation(s) importée(s), 0 mise(s) à jour, 0 erreur(s), 0 ligne(s) ignorée(s)."
5. La page redirige vers `/app/network/dhcp/import/{uuid}`.
6. Vérifier les statistiques (3 OK, 0 update, 0 erreurs, 0 ignorées) et le tableau détaillé.
7. Revenir à `/app/network/dhcp` → les 3 réservations apparaissent avec badge `import` (cyan).

#### Scénario 8.1-3.2 — Import avec erreurs (collecte exhaustive)

1. Préparer un CSV avec lignes valides + erreurs mixées :
   ```
   name,mac,ip,description
   posteOk1,00:11:22:33:44:01,10.0.0.70,desc1
   posteBadMac,zz:zz:zz:zz:zz:zz,10.0.0.71,bad mac
   posteBadIp,00:11:22:33:44:02,not-an-ip,bad ip

   # commentaire ignoré
   posteOk2,00:11:22:33:44:03,10.0.0.72,desc2
   ```
2. Importer → toast "2 réservation(s) importée(s), 0 mise(s) à jour, 2 erreur(s), 2 ligne(s) ignorée(s)."
3. Sur la page rapport : 4 lignes status `ok`, `error`, `error`, `skipped` (en plus de la ligne vide).
4. Vérifier qu'aucune erreur **n'a interrompu** l'import (les 2 lignes OK
   après les erreurs sont bien créées).
5. Vérifier la **présence d'un seul reload** dans les logs :
   `grep "make_dhcpd_conf" storage/logs/network/network-*.log | wc -l` → 1 par import (et non 1 par ligne).

#### Scénario 8.1-3.3 — Header CSV invalide

1. CSV : `wrong,header,values\nposteX,00:11:22:33:44:99,10.0.0.99,desc`
2. Importer → toast "0 importées, 1 erreur".
3. Rapport : 1 ligne `error` "Header CSV invalide. Attendu : 'name,mac,ip,description'".
4. **Aucune** insertion BD.

#### Scénario 8.1-3.4 — Rapport expiré

1. Récupérer un UUID d'import du jour : `/app/network/dhcp/import/{uuid}` → 200 OK.
2. Vider le cache : `php artisan cache:clear` (ou attendre 24h).
3. Recharger l'URL → 404 "Rapport d'import introuvable ou expiré (24h)."

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
- [ ] Section 3 (Import CSV) verte (3 scénarios)
- [ ] Section 4 (Mode dégradé) verte — réservation persiste même reload échoué
- [ ] Section 5 (Concurrence) verte — pas de fichier corrompu
- [ ] Section 6 (Migration legacy via /sync-from-ad) verte — idempotente, lien Workstation OK, source préservée
- [ ] Logs channel `network` lisibles (`storage/logs/network/network-*.log`)
- [ ] Permission 403 correct pour profil non `server.admin`
