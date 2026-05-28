# All Post Call Legacy — Audit des appels central → SE

> **Contexte** : Le serveur central appelle régulièrement 15 endpoints POST sur SambaEdu. Cet audit cartographie chaque appel pour décider, dans le cadre du redéveloppement SambaEdu, lequel conserver via shim, migrer, ou supprimer.
>
> **Date** : 2026-04-15
> **Auteur** : henri (facilitation PM — John)
> **Event calendar** : "all-post-call-story w1bis" — 2026-04-16 10:00 (Europe/Paris)

---

## 1. Mécanisme actuel — LegacyCatcher

Toutes les requêtes non matchées par une route Laravel tombent dans le **catch-all** (`routes/web.php`, dernière route) → `LegacyCatchallController`. Le flow :

1. **Normalisation** du path (strip du préfixe UAI)
2. **Routes bloquées** (`config/sambaedu.php → blocked_legacy_routes`) → redirect SER
3. **Modules shimmés** (`legacy/modules/`) → bootstrap local avec stubs (`legacy/stubs/`)
4. **Fallback proxy HTTP** vers le vhost legacy (port 80) → embed dans layout SER si HTML

**État au 2026-04-15** : aucun des 15 endpoints listés ci-dessous n'a de shim ni n'est bloqué. Tous tournent via proxy HTTP vers le vhost legacy.

## 2. Architecture central/local — ControlHub

Le "central" = **ControlHub** (plateforme d'orchestration irundoo). Il pilote N instances SambaEdu pour :
- **Scheduling coordonné** multi-école (ex. imports ENT synchronisés)
- **Autorité centrale** pour données partagées (GPO, users ENT)
- **Fault-tolerance** : retry + idempotence via `ControlHubTask`
- **Multi-instance ops** : dispatch d'une tâche vers N écoles

Le nouveau pattern est `POST /api/v1/tasks/{taskType}` → 202 Accepted → job Laravel asynchrone → callback `POST /api/sambaedu/task-result/{instance_id}`.

Les 15 appels actuels sont l'**ancien pattern** (POST legacy directement sur un `.php`). C'est ce qu'il faut migrer.

## 3. Cartographie fonctionnelle des 15 endpoints

| # | Route | Fonction | Domaine |
|---|-------|----------|---------|
| 1 | `parcs/action_cron.php` | Actions planifiées machines (WoL, shutdown) — cron 15 min | Parcs |
| 2 | `gpo/del_roam.php` | Suppression profils itinérants via préférences GPO | GPO |
| 3 | `annu/mfa_ent.php` | Config MFA utilisateurs ENT durant import | Annuaire |
| 4 | `annu/delete_temp_users.php` | Purge comptes temporaires créés lors d'imports ENT | Annuaire |
| 5 | `annu/sync_cron.php` | Orchestration synchro users ENT (OpenENT, GPEI, CSV/XML) | Annuaire |
| 6 | `dhcp/dnsupdate.php` | Mise à jour baux DHCP + records DNS | DHCP |
| 7 | `wpkg/wpkg_rapport.php` | Parse rapports install WPKG → MySQL — cron 1 min | WPKG |
| 8 | `wpkg/wpkg_depot_import.php` | Import paquets depuis dépôts externes → catalogue WPKG — cron 5 min | WPKG |
| 9 | `partages/rep_cloud_cron.php` | Synchro stockage cloud / dossiers partagés + quotas | Partages |
| 10 | `wpkg/wpkg_ldap_update.php` | **CRITIQUE** — Synchro groupes machines AD → MySQL — cron 2 min. Prévu pour remplacement par `SyncWorkstationGroupsFromAd` | WPKG |
| 11 | `infos/repquota.php` | Calcul + maj quotas disque utilisateurs | Infos |
| 12 | `stats/update_stats.php` | Agrégation stats système pour dashboard | Stats |
| 13 | `config/test_ent.php` | Test connectivité sources ENT (API, SFTP, CSV) — read-only | Config |
| 14 | `dhcp/script_make_reservations.php` | Création réservations DHCP depuis MAC dans AD | DHCP |
| 15 | `parcs/clean_connexions.php` | Purge logs de connexion machines expirés | Parcs |

## 4. Décision de migration — 3 patterns

### Pattern A — Bascule en **cron local Laravel** (supprimer l'appel central)

Tâches qui n'ont **aucune raison** d'être orchestrées depuis le central : pas de coordination inter-école, données purement locales.

| # | Route | Justification |
|---|-------|---------------|
| 1 | `parcs/action_cron.php` | Actions WoL/shutdown sur les machines locales uniquement |
| 15 | `parcs/clean_connexions.php` | Purge de logs locaux — pure maintenance |
| 11 | `infos/repquota.php` | Quotas locaux. Déjà un `quota:refresh-cache` dans `Kernel.php` |
| 12 | `stats/update_stats.php` | Agrégation locale — peut remonter au central via heartbeat |
| 9 | `partages/rep_cloud_cron.php` | Synchro stockage local — pas de dépendance inter-école |

**Action** : déclarer dans `app/Console/Kernel.php` → `$schedule->command(...)`. Supprimer l'endpoint PHP quand plus appelé.

### Pattern B — **Migrer vers ControlHub Task** (garder l'orchestration centrale mais via le nouveau mécanisme)

Tâches qui doivent rester pilotées par le central, mais qui peuvent être converties en job Laravel avec callback — exit le proxy PHP.

| # | Route | Justification |
|---|-------|---------------|
| 10 | `wpkg/wpkg_ldap_update.php` | **Priorité 1** — déjà prévu pour `SyncWorkstationGroupsFromAd` |
| 7 | `wpkg/wpkg_rapport.php` | Rapports WPKG — job local + callback central |
| 8 | `wpkg/wpkg_depot_import.php` | Import dépôts — catalogue centralisé mais exécution locale |
| 6 | `dhcp/dnsupdate.php` | DNS local mais le central veut savoir que c'est fait |
| 14 | `dhcp/script_make_reservations.php` | Réservations DHCP — local + callback |

**Action** : créer un `BaseControlHubJob` par tâche, exposer via `POST /api/v1/tasks/{taskType}`.

### Pattern C — **Conserver via proxy/shim legacy temporairement**

Tâches complexes liées au cycle ENT/GPO centralisé. À traiter plus tard, quand le périmètre ENT/GPO sera redesigné.

| # | Route | Justification |
|---|-------|---------------|
| 5 | `annu/sync_cron.php` | Orchestration imports ENT — le central fetch et distribue |
| 3 | `annu/mfa_ent.php` | MFA couplé au cycle d'import ENT |
| 4 | `annu/delete_temp_users.php` | Nettoyage post-import ENT |
| 13 | `config/test_ent.php` | Credentials ENT gérés centralement |
| 2 | `gpo/del_roam.php` | GPO = autorité centrale (AD master) → distribution |

**Action** : shimmer dans `legacy/modules/` quand on bascule l'ENT/GPO en Laravel natif.

## 5. Priorisation suggérée

1. **P1** — `wpkg/wpkg_ldap_update.php` (déjà identifié critique, job Laravel en attente)
2. **P2** — Les 5 endpoints du Pattern A (quick wins, bascule cron local triviale)
3. **P3** — Les 4 endpoints restants du Pattern B (WPKG + DHCP) via ControlHub Task
4. **P4** — Pattern C, conjointement avec les epics ENT (12) et GPO (9-1)

## 6. Questions ouvertes

- [ ] Le `central` actuel appelle-t-il **vraiment** ces 15 endpoints sur toutes les instances, ou certains sont-ils déjà morts côté central ?
- [ ] Les callbacks de succès/échec sont-ils remontés aujourd'hui (legacy) ou le central est-il aveugle ?
- [ ] Existe-t-il un catalogue côté ControlHub listant ces crons (intervalle, payload attendu) ?

## 7. Prochaines étapes proposées

1. Auditer côté central (irundoo/controlHub) la liste réelle des jobs cron actifs et leur fréquence
2. Prioriser les 5 endpoints Pattern A → transformation en stories courtes
3. Planifier `wpkg_ldap_update.php` → story dédiée (extension de 1bis-11)
4. Revisiter en milieu de refactor pour Pattern C
