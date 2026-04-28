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
