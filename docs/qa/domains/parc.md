# QA — Parc (postes & groupes)

Runbook QA pour la gestion du **parc** : appartenance poste↔groupe, salles physiques, parcs logiques, et la migration d'unification du modèle d'appartenance.

## Pré-requis communs

- VM accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- Migrations à jour : `cd /var/www/sambaedu-reload && php artisan migrate`
- Worker queue actif si vérification de la propagation OU AD : `php artisan queue:work --once`
- User `admin` (ou SuperAdmin `server.admin`) pour les mutations.

---

## Section 1 — Modèle d'appartenance unifié (Story 4.11)

### Principe

Avant 4.11, l'appartenance d'un poste à un groupe était **scindée** :

| Type de groupe | Stockage avant 4.11 | Stockage depuis 4.11 |
|---|---|---|
| Salle physique (`is_physical = true`) | FK `workstations.physical_room_id` (1 poste = 1 salle) | Pivot global `workstation_group_workstation` |
| Parc logique (`is_physical = false`) | Pivot `workstation_group_workstation` (N:N) | Pivot global `workstation_group_workstation` |

Depuis 4.11, **une seule source de vérité** : le pivot `workstation_group_workstation`. La salle d'un poste se lit
`$ws->groups()->where('is_physical', true)` (exposé par l'accessor `$ws->physicalRoom` / la relation `physicalRooms()`).

**Conséquence fonctionnelle** : tous les consommateurs « groupes d'un poste » (WPKG, GPO, raccourcis, filtres machines, AdSyncChecker) voient désormais la salle sans logique d'union FK+pivot. Avant 4.11, une salle porteuse d'un déploiement WPKG / raccourci / contexte GPO était invisible.

### Invariant « 1 salle max par poste » — décision D3 (app-only)

L'invariant n'est **pas** garanti par une contrainte DB (pas de colonne `is_physical` dénormalisée sur le pivot, pas d'index partiel). Il vit **uniquement** dans le swap transactionnel de
`WorkstationGroupService::assignMachineToPhysicalRoom()` (detach de toute salle physique courante + attach de la cible, dans la même transaction). C'est le **point d'écriture unique** des salles (UI parc, iPXE enrollment, imports).

**Rationale** : un index partiel Postgres ne peut pas référencer `workstation_groups.is_physical` (autre table) ; le filet DB exigerait une colonne pivot redondante, jugée non justifiée tant qu'aucun incident de double-salle n'est observé.

**Critère de réouverture** : si un incident de double-salle survient en prod (un poste rattaché à 2 groupes `is_physical=true` simultanément), durcir via une colonne `is_physical` dénormalisée sur le pivot + index unique partiel `WHERE is_physical = true`. Détection : voir Scénario 1.3.

### Scénario 1.1 — Affectation et swap de salle (UI parc)

**Pré-condition :** 2 salles physiques (`Salle A`, `Salle B`) et 1 poste de test en PG.

1. Onglet **Postes** → sélectionner le poste → « Ajouter à un groupe » → choisir `Salle A`.
2. **Assert** : le poste apparaît dans `Salle A` ; en base,
   ```bash
   ssh /vm 'cd /var/www/sambaedu-reload && php artisan tinker --execute="
     \$w = App\Models\Workstation::where(\"name\",\"<poste>\")->first();
     echo \$w->physicalRoom?->name;          // Salle A
     echo \$w->physicalRooms()->count();      // 1
   "'
   ```
3. Ré-affecter le même poste à `Salle B` (depuis la page Salle B → « Ajouter des machines », ou page poste → « Modifier » la salle).
4. **Assert** : le poste n'est plus dans `Salle A`, il est dans `Salle B`, et `physicalRooms()->count()` vaut toujours `1` (swap transactionnel, pas d'accumulation).

### Scénario 1.2 — Propagation OU AD au changement de salle (gap comblé par 4.11)

**Contexte :** avant 4.11, `WorkstationMembershipAdSyncJob::move` n'était dispatché **nulle part** — le déplacement de l'OU AD lors d'un changement de salle n'était pas propagé. 4.11 câble le dispatch dans le service.

1. S'assurer qu'un worker queue tourne (`php artisan queue:work --once` après l'action).
2. Affecter un poste à une salle physique différente de sa salle courante (UI ou iPXE).
3. **Assert** : un job `WorkstationMembershipAdSyncJob` (action `move`) est dispatché vers la queue, puis l'OU AD du compte machine est déplacée vers l'OU de la salle cible (`moveMachineToSalle`).
4. **Assert (anti-double-dispatch)** : ré-affecter le poste à **la même** salle → **aucun** job dispatché (garde `oldRoomId !== roomId`). Un simple detach (`roomId = null`) ne dispatche pas non plus.

### Scénario 1.3 — Détection d'une double-salle (sonde de l'invariant app-only D3)

Requête de surveillance à exécuter ponctuellement en prod (devrait toujours retourner 0 ligne) :

```bash
ssh /vm 'cd /var/www/sambaedu-reload && php artisan tinker --execute="
  echo App\Models\Workstation::whereHas(\"physicalRooms\", null, \">\", 1)->count();
"'
```

- **Attendu : `0`.** Toute valeur > 0 = incident de double-salle → déclenche le **critère de réouverture D3** (durcissement DB).

### Post-correctifs & non-régressions

- **3 échecs `GroupShowPageTest`** (group dropdown / shutdown force / remote action) étaient **rouges avant 4.11** : leurs fixtures attachaient une salle via le pivot (`['physical' => true]`) alors que l'ancien modèle lisait les membres salle via la FK. Devenus verts par l'unification (les membres salle se lisent désormais via `workstations()`).
- **WPKG via salle** : test `WorkstationPackagesResolverArchivedTest::physical_room_packages_resolve_via_pivot` prouve qu'une salle porteuse d'une app déploie sur ses postes.

---

## Section 2 — Runbook migration / rollback (Story 4.11)

Migration : `database/migrations/2026_06_04_120000_unify_workstation_membership_pivot.php`.

### Forward (`up`)

1. **Backfill** : chaque couple `(poste, salle)` de `workstations.physical_room_id` non nul devient une ligne `workstation_group_workstation`. Idempotent via la contrainte unique `wg_ws_unique (workstation_group_id, workstation_id)` + `insertOrIgnore` (PG : `ON CONFLICT DO NOTHING`). Le JOIN sur `workstation_groups` **écarte les FK orphelines** (poste pointant vers un groupe supprimé) pour ne pas violer la FK pivot.
2. **Drop** de la colonne `workstations.physical_room_id` (+ FK + index).

```bash
ssh /vm 'cd /var/www/sambaedu-reload && php artisan migrate --force'
# Vérif post-migration :
ssh /vm 'cd /var/www/sambaedu-reload && php artisan tinker --execute="
  var_dump(Schema::hasColumn(\"workstations\",\"physical_room_id\")); // false
  echo App\Models\Workstation::has(\"physicalRooms\")->count();        // postes ayant une salle
"'
```

### Rollback (`down`)

1. Recrée la colonne `physical_room_id` + index + FK (`onDelete set null`).
2. Repeuple `physical_room_id` depuis le pivot, **uniquement** pour les groupes `is_physical = true` (la salle ; les parcs logiques restent dans le pivot).

```bash
ssh /vm 'cd /var/www/sambaedu-reload && php artisan migrate:rollback --step=1 --force'
```

> **Limite connue du rollback** : si l'invariant 1-salle-max a été violé (double-salle dans le pivot), `down()` ne conserve que la dernière salle écrite dans la FK (la colonne ne peut en stocker qu'une). Vérifier Scénario 1.3 = 0 ligne **avant** un rollback.

> **Note pivot `physical`** : la colonne pivot historique `physical` (bool, morte) subsiste sur la VM ; elle n'est **pas** réintroduite comme écho de la FK (décision D1). Hors-scope 4.11.

### Tests couvrant la migration

- `tests/Feature/Migrations/UnifyMembershipPivotTest.php` : backfill, idempotence (double `up`), skip des FK orphelines, `down()` restaure colonne + données.

---

## Checklist rapide

- [ ] Affectation salle via UI parc → poste dans la salle (Scénario 1.1)
- [ ] Swap A→B → `physicalRooms()->count() == 1` (Scénario 1.1)
- [ ] Changement de salle → job `WorkstationMembershipAdSyncJob::move` dispatché (Scénario 1.2)
- [ ] Ré-affectation même salle → aucun dispatch (Scénario 1.2)
- [ ] Sonde double-salle = 0 ligne (Scénario 1.3)
- [ ] WPKG / GPO / raccourci porté par une salle → résolu pour les postes de la salle
- [ ] Migration up/down vérifiées (Section 2)
