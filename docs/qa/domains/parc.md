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

## Section 3 — Environnement de poste par parc (Story 26.1)

> **Contexte** : chaque parc (logique OU physique) déclare la nature de ses
> postes — `shared_local` (partagé, bureau réseau), `personal_local` (perdir,
> bureau local + home réseau), `nomade` (tout local + sync, réalisé en 26.2).
> La donnée vit sur `workstation_groups.environment` (nullable). Un poste dans
> N parcs résout **un** environnement (précédence
> `nomade > personal_local > shared_local`, défaut `shared_local`). En 26.1 la
> donnée + le service de résolution sont livrés ; les handlers qui la
> consomment arrivent à l'Epic 27 — **aucun effet visible côté poste encore**.

### Scénario 3.1 — Sélection UI et persistance

1. Ouvrir `/app/parc-settings`, onglet **Environnement**.
2. Vérifier que la liste affiche **les parcs logiques ET les salles physiques**
   actifs (badge « parc logique » / « salle physique »).
3. Sur un parc, choisir **Nomade** dans le `<select>` → toast de succès, valeur
   conservée après rechargement de la page.
4. Sonde base :
   ```bash
   ssh /vm "cd /var/www/sambaedu-reload && php artisan tinker --execute=\"echo \App\Models\WorkstationGroup::where('name','<nom>')->value('environment');\""
   # attendu : nomade
   ```
5. Remettre **« Non déclaré (partagé par défaut) »** → la colonne repasse à
   `null` (PAS `shared_local` : on distingue non-déclaré / déclaré-partagé).

### Scénario 3.2 — Gate (autorisation)

1. Avec un utilisateur **sans** `computer.control` (ni délégation scopée sur le
   parc), l'action `save` doit être refusée (`AuthorizationException`) — c'est
   la **même** gate `update-workstationGroup` que l'édition d'un parc, pas
   `computer.install`.
2. Avec une **délégation scopée** `computer.control` sur un parc précis :
   l'utilisateur peut configurer l'environnement de CE parc uniquement.

### Scénario 3.3 — Précédence multi-parcs (résolution serveur)

Un poste membre de plusieurs parcs hérite de l'environnement le plus « fort ».
À vérifier via tinker (le service `WorkstationEnvironmentResolver`) :

```bash
ssh /vm "cd /var/www/sambaedu-reload && php artisan tinker --execute=\"
\\\$ws = \App\Models\Workstation::first();
echo app(\App\Services\Agent\WorkstationEnvironmentResolver::class)->resolve(\\\$ws)->value;
\""
```

- Poste dans `{partagé, personnel}` → **`personal_local`**.
- Poste dans `{partagé, personnel, nomade}` → **`nomade`**.
- Poste sans parc, ou tous parcs « Non déclaré » → **`shared_local`** (défaut).

> **Post-correctif / pourquoi ces scénarios** : la précédence est l'unique
> logique métier de la story (décision D1 : elle vit dans le service, jamais
> dans l'enum). Le défaut `shared_local` côté service (pas en SQL, décision D2)
> garantit qu'un parc oublié reste neutre. **Discipline NFR7** : le service lit
> Postgres uniquement — aucune requête AD/LDAP/APCu (à confirmer si un doute :
> `grep -n 'Ldap\|apcu\|AppCustomization' app/Services/Agent/WorkstationEnvironmentResolver.php` = 0 hit).
> **Note de transition** : aucun retrofit legacy ; le service n'est branché sur
> aucun chemin legacy (Bug C corrigé définitivement par le handler raccourcis
> en Story 27.1).

---

## Section 4 — Configuration par défaut du parc (27.17)

Page `/admin/settings/parc-defaults` : surface d'édition consolidée de la couche
`Broadcast` (config appliquée par défaut à TOUS les postes, overridable). Onglets :
Fond d'écran, Écran de verrouillage, Registre/capacités, Applications, Outils agent.
(L'onglet Overlay arrive en 27.18.)

**Pré-requis** : user `server.admin` ; au moins une `Application` en base (catalogue
WPKG) ; portable Rainmeter embarqué `resources/agent/tools/sambaedu-rainmeter-*.zip`.

### Scénario 4.1 — Accès page + gate server.admin

1. Se connecter en `server.admin`, ouvrir `/admin/settings` → la carte
   « Configuration par défaut du parc » (badge « Broadcast ») est présente.
2. Cliquer → la page s'ouvre sur l'onglet « Fond d'écran » (URL `?tab=wallpaper`).
3. Se connecter avec un user SANS `server.admin` et tenter d'accéder à
   `/admin/settings/parc-defaults` → **403** (middleware route + garde `mount()`).
4. Vérifier que chaque action mutante reste protégée même via `/livewire/update`
   (double garde `Gate::authorize('server.admin')`).

### Scénario 4.2 — Navigation par onglets

1. Cliquer successivement sur chaque onglet (Fond d'écran, Écran de verrouillage,
   Registre/capacités, Applications, Outils agent) → le contenu change, l'URL
   `?tab=` suit, aucun onglet ne lève d'erreur de rendu.
2. Forcer `?tab=bogus` dans l'URL → retombe sur « Fond d'écran » (fallback).

### Scénario 4.3 — Onglet Fond d'écran / Écran de verrouillage (défaut Broadcast)

1. Onglet « Fond d'écran » → uploader une image (ou choisir dans la bibliothèque)
   → le défaut établissement (`wallpapers` owner_id NULL, is_default, type='wallpaper')
   est créé/remplacé.
2. Vérifier qu'un poste SANS config wallpaper plus spécifique reçoit ce fond
   (provider `WallpaperStateProvider`, maille Broadcast).
3. Idem onglet « Écran de verrouillage » avec `type='lockscreen'`.
4. Ouvrir l'ancienne URL `/app/parc-settings/wallpapers` → REDIRIGE vers
   `parc-defaults?tab=wallpaper` (le lien GPO `?from_gpo=` continue de fonctionner).
5. Vérifier que les éditions CIBLÉES (wallpaper par parc/user) restent inchangées
   sur leurs pages d'origine.

### Scénario 4.4 — Onglet Registre / capacités (défaut diffusé)

1. Éditer le défaut d'une capacité (`saveDefault`) → `capabilities.default_value`
   mis à jour, diffusé à toute la flotte (Broadcast).
2. Geler/dégeler une capacité (`toggleLock`) → `overrides_locked` bascule sans
   couper la diffusion.
3. Vérifier que les overrides PAR PARC (onglet « Options/Capacités » du groupe)
   ne sont PAS touchés.

### Scénario 4.5 — Onglet Outils agent (canal séparé)

1. Vérifier l'alerte « canal séparé / hors-state / non overridable ».
2. Importer le portable Rainmeter (`.zip` Rainmeter.exe + Skins/) → SHA-256 calculé
   serveur, ligne `agent_tools` clé `rainmeter` créée, état « désactivé ».
3. Activer (toggle) → exposé au manifest, déployé au prochain check-in des postes.
4. Désactiver → no-op côté agent, les postes déjà équipés conservent l'outil.

### Scénario 4.6 — App `is_parc_default` diffusée à un poste neuf (Broadcast)

1. Onglet « Applications » → rechercher une app (ex. `7za`, `NirCmd`) → « Appliquer
   par défaut » → `applications.is_parc_default = true`.
2. Sur un poste NEUF (aucun profil/parc/app rattaché), interroger son state agent →
   l'app défaut parc apparaît bien dans le set `applications` (maille Broadcast),
   en plus de ce que le poste reçoit déjà via ses rattachements (union sans doublon).
3. Retirer l'app du défaut parc → elle disparaît du set Broadcast du poste neuf.
4. Non-régression : un poste sans app défaut parc ET sans config spécifique a un
   set `applications` VIDE (le state est inchangé hors apps défaut).

### Scénario 4.7 — Provisioning serveur `ensure_*` (idempotent, fail-soft)

1. Sur la VM : `php artisan agent:tools:register-defaults` → enregistre le portable
   embarqué dans `agent_tools` (clé `rainmeter`, désactivé) ; le fichier est posé
   sous `AGENT_TOOLS_PATH` (644, owner www-admin).
2. Relancer la commande → **no-op** (« déjà présent ») : aucune re-création, l'état
   `enabled` d'un éventuel toggle admin n'est pas écrasé (idempotence).
3. Renommer/retirer le portable embarqué + DB vide → la commande **warn** et sort
   en SUCCESS (un `required` sans source résolvable ne casse JAMAIS l'install/update).
4. Vérifier la sortie de `update.sh` : étape « Outils agent obligatoires (Rainmeter
   embarqué) » présente dans le résumé.
5. **Migration `is_parc_default` PENDING sur VM** : `php artisan migrate:status` avant
   tout e2e base ; jouer `php artisan migrate` (la migration n'est PAS auto-jouée
   par le dev-cycle, qui ne migre que SQLite côté tests).

### Post-correctifs & non-régressions (review 27.17)

#### Scénario 4.8 — Retour GPO depuis un lien profond wallpaper (non-régression #3)

Vérifie que le lien profond `?from_gpo=<GUID>` (généré par
`NativeSectionResolver` sur la page détail d'une GPO « wallpaper ») reste
fonctionnel après la consolidation de l'édition dans `parc-defaults`.

1. Ouvrir une GPO dont le `displayName` matche le pattern wallpaper (contient
   `wallpaper` / `fond-ecran` / `lockscreen`) → la page détail affiche le CTA
   natif « Gérer les fonds d'écran » pointant
   `/app/parc-settings/wallpapers?from_gpo=<GUID>`.
2. Cliquer le CTA. `parc-settings/wallpapers` **redirige** vers
   `/admin/settings/parc-defaults?tab=wallpaper&from_gpo=<GUID>` (le param
   `from_gpo` est **propagé** par la redirection — c'était la régression).
3. Sur la page de défaut parc : vérifier la présence du **breadcrumb de retour**
   `<x-molecules.gpo-back-link>` en haut (« Retour à la GPO «<displayName>» »).
   Le lien ramène à `admin.gpo.show` de la GPO d'origine.
4. Changer d'onglet (ex. « Registre / capacités ») puis revenir : le param
   `from_gpo` **persiste dans l'URL** (`#[Url] $from_gpo`) → le breadcrumb reste
   affiché (la navigation par onglets est Livewire, sans rechargement).
5. Cas dégradé : `?from_gpo=<GUID-inexistant>` → le composant retombe sur le lien
   générique « Retour à la liste des GPOs » (fallback silencieux, AC4.3 — pas
   d'erreur). Sans `?from_gpo` du tout → aucun breadcrumb (rendu conditionnel).

> Couvert automatiquement côté résolveur : `NativeSectionResolverTest`,
> `GpoNativeSectionLinksTest` (propagation du param dans les CTA). Le breadcrumb
> lui-même reste un check manuel (rendu Blade conditionnel + résolution GPO).

#### Autres correctifs (régression-test automatisée)

- **#1 `#[Locked]` sur `gate`** — couvert par `AdminSettingsParcDefaultsPageTest`
  (gate `server.admin` honorée) ; la prop n'est plus mutable client-side.
- **#2 fail-soft `\Throwable`** + **#6 découverte vide** —
  `AgentToolsRegisterDefaultsCommandTest::fail_soft_when_discovery_finds_no_embedded_portable`
  (sans skip ext-zip).
- **#4/#5 onglets registry/tools** — `AdminSettingsParcDefaultsPageTest`
  (saveDefault valide, toggleLock, gate 403 tools, toggle enabled).

---

## Checklist rapide

- [ ] Affectation salle via UI parc → poste dans la salle (Scénario 1.1)
- [ ] Swap A→B → `physicalRooms()->count() == 1` (Scénario 1.1)
- [ ] Changement de salle → job `WorkstationMembershipAdSyncJob::move` dispatché (Scénario 1.2)
- [ ] Ré-affectation même salle → aucun dispatch (Scénario 1.2)
- [ ] Sonde double-salle = 0 ligne (Scénario 1.3)
- [ ] WPKG / GPO / raccourci porté par une salle → résolu pour les postes de la salle
- [ ] Migration up/down vérifiées (Section 2)
- [ ] Onglet Environnement liste parcs logiques + physiques, sélection persiste (Scénario 3.1)
- [ ] « Non déclaré » écrit `null`, pas `shared_local` (Scénario 3.1)
- [ ] Gate `update-workstationGroup` (refus sans droit, délégation scopée OK) (Scénario 3.2)
- [ ] Précédence `nomade > personal_local > shared_local` + défaut (Scénario 3.3)
- [ ] Page parc-defaults : accès server.admin OK / 403 sans droit (Scénario 4.1)
- [ ] Navigation onglets + fallback `?tab=bogus` → wallpaper (Scénario 4.2)
- [ ] Wallpaper/lockscreen défaut Broadcast + redirection `/parc-settings/wallpapers` (Scénario 4.3)
- [ ] Capacités : défaut diffusé + gel, overrides parc intacts (Scénario 4.4)
- [ ] Outils agent : import + toggle (canal séparé manifest) (Scénario 4.5)
- [ ] App `is_parc_default` diffusée à un poste neuf, union sans doublon, non-régression vide (Scénario 4.6)
- [ ] `agent:tools:register-defaults` idempotent + fail-soft + 644/www-admin + migrate VM (Scénario 4.7)
- [ ] Retour GPO : `?from_gpo` propagé par la redirection + breadcrumb `gpo-back-link` présent et persistant à la navigation onglets (Scénario 4.8)
