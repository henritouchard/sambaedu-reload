# Story 16.2 : Listing & lecture GPO (UI native)

Status: review

> Première story **utilisateur** de l'Epic 16. Livre la **page Livewire native `/app/gpo`** (listing + détail lecture-seule des GPOs Active Directory) qui consomme les méthodes lecture posées par Story 16.1 (`GpoService::list/get/listContainers/getLinks/getInheritance`).
>
> **Périmètre strictement lecture** — toutes les mutations restent shimmées vers le legacy `1bis-18` (boutons "éditer dans l'ancienne UI" → `/gpo/gestion_gpo.php`). L'écriture native est traitée par Stories 16.3, 16.4, 16.5.

---

## Story

As a **responsable SER (rôle `server.admin`)**,
I want consulter la liste des GPOs Active Directory de mon établissement et le détail de chacune (sections actives, valeurs des policies, liens OU/WorkstationGroup, héritage) **depuis l'interface native Laravel** plutôt que via les pages PHP legacy `gpo/gestion_gpo.php`,
So que je dispose d'une **vue unifiée et lisible** du parc GPO (filtrable par nom / OU / statut), corrélée aux WorkstationGroups Eloquent que je manipule par ailleurs, **sans perdre l'accès aux fonctions d'édition** (qui restent disponibles via boutons profonds vers le shim 1bis-18 en attendant les Stories 16.3-16.5).

---

## Contexte

L'Epic 16 remplace progressivement le module GPO legacy (`sambaedu/gpo/*.php`, ~4 619 lignes PHP shimées via 1bis-18a/b/e). La Story 16.1 a posé l'infrastructure : namespace `App\Gpo`, channel logs `gpo` (debug + verbeux par `action_type`), `GpoService` avec 5 méthodes lecture **implémentées** (`list/get/listContainers/getLinks/getInheritance`) et 6 stubs écriture, `SambaToolRunner` (mode array, no-shell-injection), `GpoServiceProvider` (boot check `bin_path` + `sysvol_path`), garde-fous archi (`GpoNamespaceTest`, `GpoLegacyIsolationTest`).

Cette Story 16.2 consomme ces fondations pour livrer la **première capacité utilisateur**. Elle est volontairement cantonnée à la **lecture** :

1. Page `/app/gpo` (Livewire SFC) — listing des GPOs avec filtres + lien profond vers le détail.
2. Page `/app/gpo/{guid}` — détail d'une GPO (métadonnées, liens AD, héritage par OU lié, navigation profonde vers les sections natives déjà existantes).
3. Bouton **« Éditer dans l'ancienne UI »** sur chaque GPO → ouvre la page legacy `/gpo/gestion_gpo.php?selectionne={displayname}` (ou équivalent shim) **dans un nouvel onglet** — assure la continuité fonctionnelle pendant que les Stories 16.3/16.4/16.5 portent l'édition native.
4. Catchall override : la route legacy `gpo/gestion_gpo.php` est ajoutée à `config('sambaedu.blocked_legacy_routes')` → redirigée vers `/app/gpo`. Les pages legacy d'édition (`gpo-maj.php`, `gpo-export.php`, sections spécialisées `wine.php`, `veyon_out.php`, `network_out.php`, `applications.php`) **restent accessibles** (cohabitation jusqu'aux Stories 16.3/16.4).

> **Décision SM Q1 — Route** : `/app/gpo` (pas `/app/windows-deploy/gpo`). Cf. **Décision D1** ci-dessous.
>
> **Décision SM Q2 — Lien shim** : OUI, bouton "Éditer dans l'ancienne UI" en page détail (target `_blank`). Cf. **Décision D2**.

---

## Garde-fous Epic 16 (rappel — applicables à cette story)

- **AD = source de vérité** : aucune table Eloquent créée dans cette story (cf. Story 16.1 décision D3). Le listing relit `GpoService::list()` à chaque chargement (acceptable car listing rare et latence `samba-tool listall` < 2 s sur parc typique). Caching applicatif **explicitement reporté** (cf. Hors-scope).
- **Logging verbeux** : toute action UI (chargement listing, lecture détail, refresh) déclenche un log `action_type` sur le channel `gpo` (cf. Story 16.1 AC1.3). La page n'utilise **JAMAIS** `\Log::*` directement — uniquement via `GpoLogger` ou indirectement via `GpoService` (qui logge déjà).
- **Trois couches** (`architecture.md:343-344`) : la SFC Livewire **n'appelle JAMAIS** `LdapRecord` / `Eloquent` / `Process` directement. Toute lecture passe par `GpoService` (déjà existant). Aucun `exec()` dans la SFC.
- **Shim 1bis-18 reste vivant** : la story **ne supprime aucun fichier legacy**. Elle ajoute juste un blocage catchall pour la page d'index `gestion_gpo.php`, et redirige proprement.
- **Convention CLAUDE.md** : `WithToasts` pour notifications, modale réutilisable si besoin (probablement aucune dans cette story — pas de mutation), filesystem-based router (`pages/app/gpo/index.blade.php`, `pages/app/gpo/[guid]/index.blade.php`).
- **Pas de @legacy-port dans cette story** : aucun fichier porté du legacy (la lecture passe 100 % via `GpoService` déjà natif).

---

## Dépendances

| Story / Epic | Titre | Status | Détail |
|---|---|---|---|
| **16.1** | Fondations GPO natives + audit legacy | review (2026-05-11, en attente validation Henri) | **Bloquante** — fournit `App\Gpo\Services\GpoService` (5 méthodes lecture), `SambaToolRunner`, `GpoLogger`, channel `gpo`. Cette story 16.2 ne crée aucun nouveau service, elle consomme uniquement. |
| 1bis-18a/b/e | Shim legacy GPO | done / review | Le shim reste vivant. Les routes `gpo/gpo-maj.php`, `gpo/gpo-export.php`, `gpo/wine.php`, etc. **continuent d'être servies** par le catchall pour la cohabitation. |
| Epic 4 | Workstation, WorkstationGroup, AppProfile | done | `WorkstationGroup` est utilisé en lecture pour corréler les liens GPO ↔ WorkstationGroup (lookup par OU AD si pertinent). Lecture pure, pas de migration. |
| 4.7 / 4.8 / `ShortcutsService` / `RoamingProfileService` (1bis-18f) | Sections déjà natives | done | Liens profonds depuis le détail GPO vers `/app/parc-settings/wallpapers`, `/app/parc-settings/app-customizations`, `/app/shortcuts`, `/admin/settings?tab=profils-itinerants` — cohérent avec recommandation audit Section 6.C ("liens profonds vers UIs existantes pour vue unifiée"). |
| 15.1 / 15.4 | Pattern Wpkg | done / done | **Référence de structure UI** : `parc-settings/_partials/applications-tab.blade.php` (onglets), `pages/parc-settings/wallpapers/index.blade.php` (page Livewire SFC simple avec `WithToasts`). |

**Conclusion dépendances** : Story 16.1 est en `review` non encore `done`. **Henri doit valider l'audit `audit-gpo-legacy.md` et lancer les smoke tests VM avant le démarrage du dev de cette story 16.2** — sinon risque de redécouvrir des décisions architecturales (signatures `GpoService`, conventions logging) qui pourraient bouger. Recommandation SM : démarrer 16.2 en parallèle de la validation 16.1 si Henri OK pour assumer un risque mineur de re-touche.

---

## Décisions SM (à intégrer dès le kickoff)

| #  | Décision                                                                                                                                                                                                                                                                                                                                                                                              | Justification                                                                                                                                                                                                                                                                                                                                                                                                                          |
|----|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| D1 | **Route = `/app/gpo`** (et **PAS** `/app/windows-deploy/gpo`).                                                                                                                                                                                                                                                                                                                                       | (a) **Cohérence avec le codebase actuel** : il n'existe PAS encore de dossier `pages/windows-deploy/` (vérifié 2026-05-11), et l'épic 15 (WPKG) **n'a pas créé** ce préfixe (il a intégré WPKG comme onglets dans `parc-settings/`, cf. décision Story 15.4 A). (b) **Parallélisme avec `/app/shortcuts`, `/app/parc-settings`, `/app/users`** — domaine fonctionnel à la racine `/app/`. (c) **Lisibilité URL** pour les administrateurs (URL courte). (d) Si un jour Henri décide d'introduire `/app/windows-deploy/`, ce sera un alias trivial à ajouter (`Route::redirect`). L'inverse (changer `/app/gpo` une fois en prod) coûtera un blocage catchall.  Argument contre : la doc `architecture.md:486` mentionne `pages/windows-deploy/` comme **cible théorique** pour FR23-26 — mais cette doc n'a jamais été appliquée dans le codebase réel. **Décision : suivre le code, pas la doc.** |
| D2 | **Bouton "Éditer dans l'ancienne UI"** présent sur la page détail `/app/gpo/{guid}` → ouvre `{{ url('/gpo/gestion_gpo.php') }}?selectionne={displayName}` dans un **nouvel onglet** (`target="_blank"` + `rel="noopener noreferrer"`).                                                                                                                                                                | Continuité fonctionnelle pendant la transition (les sections d'édition arrivent en 16.3/16.4/16.5). L'utilisateur n'est **jamais coincé** dans une page lecture-seule. Le `_blank` permet de revenir facilement à `/app/gpo` après l'édition legacy. À terme (Story 16.6) ce bouton sera retiré quand toutes les sections seront natives. **Note** : le shim legacy ne supporte pas systématiquement le paramètre `selectionne` — vérifier au dev. Sinon : juste lien vers la page d'index. |
| D3 | **Pas de table de cache Eloquent** dans cette story — relit `samba-tool gpo listall` à chaque mount.                                                                                                                                                                                                                                                                                                | Cohérent avec Story 16.1 décision D3 (AD source de vérité, tables story par story). Le coût de `listall` est faible (parc < 100 GPOs typique). Si la latence devient un problème, Story 16.4 introduira une table de cache (la corbeille y vit déjà). Cette story se contente d'un cache **mémoire local** (collection Livewire) avec bouton **« Rafraîchir »** explicite. |
| D4 | **Permission requise = `server.admin`**.                                                                                                                                                                                                                                                                                                                                                              | (a) Le legacy `gpo/gestion_gpo.php` est gardé par `SE_SERVER_ADMIN` (bitmask 0x10000 — vérifié dans audit Section 6.A). (b) `SambaPermission::ServerAdmin = 'server.admin'` existe déjà dans Spatie (cf. `app/Enums/SambaPermission.php:58`). (c) Pattern utilisé par les pages admin existantes (`wallpapers` utilise `wallpaper.manage`, mais GPO ne dispose pas de permission dédiée — `server.admin` est le bon proxy). Pas de nouvelle permission à créer dans cette story. |
| D5 | **Catchall override = bloquer uniquement `^gpo/gestion_gpo\.php$`** (page d'accueil legacy → redirige vers `/app/gpo`). **NE PAS bloquer** `gpo-maj.php`, `gpo-export.php`, `wine.php`, `veyon_out.php`, `network_out.php`, `applications.php`, `associations_out.php`.                                                                                                                              | Cohérent avec la stratégie audit Section 6.D : **cohabitation** progressive. Bloquer plus d'URLs reviendrait à casser des fonctionnalités d'édition non encore portées. La page d'index étant **purement navigationelle** dans le legacy (3 liens, cf. audit fiche `gestion_gpo.php`), son override est sans risque. |
| D6 | **Pagination simple côté Livewire** (pas Eloquent — c'est une `Collection`). Tri par `displayName` ASC par défaut.                                                                                                                                                                                                                                                                                  | `GpoService::list()` retourne `Collection<GpoSummary>`. Pagination via `Collection::forPage()` + compteur custom. 20 par page par défaut. Pas de URL state (`#[Url]`) sur la pagination car la URL state est moins critique pour 20-100 GPOs (à reconsidérer si parc > 500 GPOs). |
| D7 | **Filtres** : (a) **Recherche texte** sur `displayName` (case-insensitive, substring). (b) **Filtre statut** : Active / Inactive (basé sur `versionNumber` — version 0 = jamais déployée = "draft"). (c) **Filtre OU rattachée** = **REPORTÉ** à Story 16.5 (nécessite `getLinks` sur tous les containers — coût élevé). | Filtres légers pour ne pas multiplier les appels `samba-tool`. Le filtre OU nécessite de croiser `listContainers(name)` × N GPOs ou `getLinks(dn)` × N OUs — coût quadratique. À traiter en 16.5 quand on aura un cache table. |
| D8 | **Détail GPO** charge `get(guid)` + `listContainers(guid)` + pour chaque container : `getLinks(dn)` + `getInheritance(dn)`. Affichage en sections expansibles.                                                                                                                                                                                                                                       | Reproduit la sémantique legacy `samba-tool gpo show` enrichie. Si N containers > 5, afficher uniquement les 5 premiers + bouton "voir plus" pour limiter le nombre d'appels `samba-tool`. Logging `gpo.show` + `gpo.containers.list` + `gpo.link.get` × N + `gpo.inheritance.get` × N — verbeux mais conforme convention Epic 16. |
| D9 | **Liens profonds vers sections déjà natives** (recommandation audit Section 6.C) : depuis la page détail, afficher un encart "Sections de cette GPO gérables nativement" avec liens vers `/app/parc-settings/wallpapers`, `/app/parc-settings/app-customizations`, `/app/shortcuts`, `/admin/settings?tab=profils-itinerants`. **Affichage conditionnel selon `displayName`** (heuristique nom GPO).  | Reproduit la promesse "vue unifiée" sans dupliquer d'UI. L'heuristique est documentée dans le code (mapping `displayName → URL native`). Pour les GPOs custom non reconnues, encart caché. |
| D10 | **Affichage `versionNumber`** : extrait à partir de `samba-tool gpo show {guid}` (déjà parsé par `GpoService::get`). Format : version major.minor (16 bits chacun) — cf. legacy `gpo.inc.php` (version stockée comme entier 32-bit `(major << 16) | minor`). | Identique au legacy. Affichage `12.3` au lieu de `786435`. Décodage simple : `$major = $version >> 16; $minor = $version & 0xFFFF;`. À encapsuler dans `GpoSummary::version()` ou helper Blade. **Note** : le `GpoSummary` actuel expose `versionNumber: ?int`. Ajouter un accessor / helper `formattedVersion()` (sans toucher au DTO si possible — utiliser un helper Blade). |

---

## Acceptance Criteria

> 5 volets. Volet 1 = listing, Volet 2 = détail, Volet 3 = catchall override, Volet 4 = permissions / sécurité, Volet 5 = tests.

### Volet 1 — Page listing `/app/gpo`

**AC1.1** — **Route et accessibilité**
**Given** un utilisateur authentifié avec permission `server.admin`
**When** il navigue vers `/app/gpo`
**Then** la page Livewire SFC `pages::app.gpo.index` (fichier `resources/views/pages/app/gpo/index.blade.php`) est servie
**And** la route est définie dans `routes/web.php` sous `Route::prefix('app')->middleware('sambaedu.auth')` avec `->middleware('can:server.admin')->name('gpo.index')`
**And** sans la permission, l'utilisateur reçoit un 403 (cohérent avec pattern `wallpapers` ligne 18 de `pages/parc-settings/wallpapers/index.blade.php`).

**AC1.2** — **Affichage du listing**
**Given** la page est mountée
**When** la SFC appelle `GpoService::list()`
**Then** elle affiche un tableau (`<x-organisms.data-table>` ou équivalent existant) avec, pour chaque GPO, les colonnes :
- **Display name** (cliquable → lien vers `/app/gpo/{guid}`)
- **GUID** (tronqué à 8 caractères + tooltip valeur complète)
- **Version** (affichée comme `major.minor`, cf. décision D10 — `0.0` si version 0)
- **Path SYSVOL** (tronqué + tooltip)
- **Actions** : bouton "Voir détail" (lien interne) + bouton "Éditer (ancienne UI)" (lien legacy `/gpo/gestion_gpo.php?selectionne={displayName}` target=_blank)

**And** les GPOs sont triées par `displayName` ASC par défaut
**And** le composant tableau supporte le tri ASC/DESC sur les colonnes `displayName` et `version` (côté client, sur la `Collection`).

**AC1.3** — **Filtres et recherche**
**Given** le listing est affiché
**When** l'utilisateur saisit du texte dans le champ "Recherche"
**Then** le listing est filtré côté serveur (Livewire `updatedSearch()`) sur le `displayName` (case-insensitive, substring `mb_stripos`)
**And** le compteur "X GPO(s) sur Y" est mis à jour
**And** la recherche est synchronisée avec l'URL via `#[Url]` (parité shortcuts page).

**AC1.4** — **Filtre statut**
**Given** le listing affiche un filtre "Statut" (`<select>`)
**When** l'utilisateur sélectionne "Active" / "Inactive" / "Toutes"
**Then** "Active" filtre les GPOs avec `versionNumber > 0`
**And** "Inactive" filtre les GPOs avec `versionNumber === 0` ou `null`
**And** "Toutes" affiche tout
**And** le filtre est synchronisé avec l'URL via `#[Url]`.

**AC1.5** — **Pagination**
**Given** le listing contient plus de 20 GPOs (`perPage` configurable parmi `[10, 20, 50, 100]`)
**When** l'utilisateur change de page
**Then** la pagination utilise `Collection::forPage($currentPage, $perPage)` (pas Eloquent)
**And** les boutons "Précédent / Suivant / N° de page" sont rendus via le composant `<x-molecules.pagination>` (vérifier l'existence — sinon pagination simple manuelle)
**And** le compteur "Affichage X-Y sur Z" est rendu sous le tableau.

**AC1.6** — **Bouton "Rafraîchir"**
**Given** la page listing
**When** l'utilisateur clique "Rafraîchir"
**Then** la SFC réappelle `GpoService::list()` (qui ré-appelle `samba-tool gpo listall`)
**And** un toast de succès `WithToasts::toastSuccess('Liste rafraîchie ({count} GPOs)')` est émis
**And** un log `action_type=gpo.list` est émis dans le channel `gpo` (déjà fait par `GpoService`).

**AC1.7** — **Gestion d'erreur listing**
**Given** `GpoService::list()` lève une exception (samba-tool indisponible, AD KO)
**When** la SFC catche l'exception
**Then** un toast d'erreur `WithToasts::toastError('Impossible de charger les GPOs : {message}')` est émis
**And** le tableau affiche un état vide avec un bouton "Réessayer"
**And** l'exception est loggée par `GpoService` (channel `gpo`, `outcome=failure`) — la SFC ne logge **pas** elle-même.

**AC1.8** — **État vide légitime**
**Given** `GpoService::list()` retourne une collection vide (parc sans GPO)
**When** le rendu se fait
**Then** un état vide explicite est affiché (`<x-molecules.empty-state>` ou équivalent) avec message "Aucune GPO trouvée. Cliquez ici pour en créer une dans l'ancienne UI" + lien vers `/gpo/gestion_gpo.php`.

### Volet 2 — Page détail `/app/gpo/{guid}`

**AC2.1** — **Route paramétrée**
**Given** un utilisateur authentifié avec `server.admin`
**When** il navigue vers `/app/gpo/{12345678-1234-1234-1234-123456789012}`
**Then** la page Livewire SFC `pages::app.gpo.[guid].index` (fichier `resources/views/pages/app/gpo/[guid]/index.blade.php`) est servie
**And** la route est définie dans `routes/web.php` avec `->whereGuid('guid')` (ou `->whereIn` regex GUID strict pour valider format `{XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX}`) — éviter d'appeler `samba-tool` avec un input arbitraire
**And** le paramètre est bindé au public property `$guid` via `mount(string $guid)`.

**AC2.2** — **Chargement du détail**
**Given** la page est mountée avec un `$guid` valide
**When** la SFC appelle `GpoService::get($guid)`
**Then** si `null` retourné, page 404 affichée (`abort(404, 'GPO inexistante')`)
**And** sinon, les métadonnées sont affichées :
- Display name (titre H1)
- GUID (sous-titre)
- Version (`major.minor`)
- DN AD complet (collapsible)
- Path SYSVOL (collapsible)

**AC2.3** — **Containers liés**
**Given** le détail est chargé
**When** la SFC appelle `GpoService::listContainers($guid)`
**Then** elle obtient une `array<string>` de DNs (OUs / Sites / Domain)
**And** affiche pour chaque container (avec un cap d'affichage à 5 par défaut + bouton "Afficher tous") :
- Le DN complet
- L'état `getInheritance($dn)` (badge "Hérité" / "Bloqué")
- Les liens GPO de ce container via `getLinks($dn)` — affichage tableau imbriqué : Nom GPO, Enforced (badge), Disabled (badge), GUID
**And** un bouton "Tout afficher" charge l'intégralité si N > 5.

> **Note perf** : appels `samba-tool` séquentiels. Si N containers > 5, on cap à 5 par défaut. Recommandation : afficher un loader Livewire pendant les appels (si latence perçue > 1 s, sinon laisser bloquant). **Pas de jobs async dans cette story** — c'est une page admin, blocking acceptable.

**AC2.4** — **Encart "Sections gérables nativement"** (recommandation audit Section 6.C)
**Given** le détail est chargé
**When** le `displayName` matche une heuristique connue (mapping `displayName → URL native`)
**Then** un encart `<x-molecules.alert>` (info) affiche les liens profonds vers les UIs natives existantes :
- `redirections` / `*roaming*` / `*profil*` → `/admin/settings?tab=profils-itinerants` (label "Gérer les profils itinérants nativement")
- `*wallpaper*` / `*fond*` → `/app/parc-settings/wallpapers` (label "Gérer les fonds d'écran")
- `*firefox*` / `*thunderbird*` / `*app*` → `/app/parc-settings/app-customizations` (label "Personnaliser les apps")
- `*shortcut*` / `*raccourci*` → `/app/shortcuts` (label "Gérer les raccourcis")

**And** si aucune heuristique ne matche, l'encart est caché
**And** le mapping est défini dans une constante PHP de la SFC (`private const NATIVE_SECTIONS_HEURISTICS = [...]`) — pas dans la blade — pour rester testable.

**AC2.5** — **Bouton "Éditer dans l'ancienne UI"** (Décision D2)
**Given** le détail est chargé
**When** la page est rendue
**Then** un bouton primaire affichant "Éditer dans l'ancienne UI" est positionné dans le header de la page
**And** son `href` pointe `{{ url('/gpo/gestion_gpo.php') }}?selectionne={{ urlencode($displayName) }}` (le shim accepte ou ignore ce paramètre — fallback OK)
**And** ses attributs incluent `target="_blank"` + `rel="noopener noreferrer"`
**And** un sous-texte explicatif : "L'édition native arrive dans les prochaines stories de l'Epic 16."

**AC2.6** — **Bouton "Retour au listing"**
**Given** la page détail est affichée
**When** l'utilisateur clique "Retour au listing"
**Then** il est redirigé vers `/app/gpo` (préservation des filtres via la session ou `referer` — best effort, pas critique).

**AC2.7** — **Gestion d'erreur détail**
**Given** `GpoService::get()` ou `listContainers()` ou `getLinks()` ou `getInheritance()` lève une exception
**When** la SFC catche l'exception
**Then** un toast d'erreur explicite est émis et un encart "Détails partiels" indique quelles sections n'ont pu être chargées
**And** la page reste navigable (les autres données chargées avec succès restent affichées).

### Volet 3 — Catchall override `gpo/gestion_gpo.php` → `/app/gpo`

**AC3.1** — **Blocage de la route legacy d'index**
**Given** la config `config/sambaedu.php` ligne 42 (`blocked_legacy_routes`)
**When** la story est mergée
**Then** une nouvelle entrée `'^gpo/gestion_gpo\.php$' => 'app/gpo'` est ajoutée à `blocked_legacy_routes`
**And** un test feature (`tests/Feature/Gpo/LegacyGestionGpoRedirectTest.php`) vérifie qu'un GET `/gpo/gestion_gpo.php` retourne une redirection vers `/app/gpo` (codes 302/301).

**AC3.2** — **Pas de blocage des sections en cohabitation** (Décision D5)
**Given** les routes legacy `gpo/gpo-maj.php`, `gpo/gpo-export.php`, `gpo/wine.php`, `gpo/veyon_out.php`, `gpo/network_out.php`, `gpo/applications.php`, `gpo/associations_out.php`
**When** un utilisateur les invoque
**Then** elles continuent d'être servies par le shim 1bis-18 (catchall non override)
**And** un test feature vérifie qu'au moins `gpo/gpo-maj.php` retourne un code != 302 (cohabitation préservée).

### Volet 4 — Permissions et sécurité

**AC4.1** — **Permission `server.admin` requise** (Décision D4)
**Given** un utilisateur authentifié sans la permission `server.admin`
**When** il navigue vers `/app/gpo` ou `/app/gpo/{guid}`
**Then** il reçoit un 403
**And** un test feature `tests/Feature/Gpo/GpoPagePermissionTest.php` vérifie le 403 pour utilisateur sans permission, et le 200 pour utilisateur avec.

**AC4.2** — **Validation du paramètre `{guid}`** (anti-injection)
**Given** la route `/app/gpo/{guid}`
**When** l'URL est parsée
**Then** le paramètre est validé via une regex de format GUID Microsoft strict : `^\{[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}\}$` (avec accolades obligatoires)
**And** un GUID malformé retourne un 404 (avant tout appel `samba-tool`)
**And** un test feature `tests/Feature/Gpo/GpoDetailRouteValidationTest.php` vérifie que `/app/gpo/INJECTION` ou `/app/gpo/{not-a-guid}` retourne 404 sans appel `samba-tool` (mocker `GpoService` et asserter aucun appel).

**AC4.3** — **`escapeshellarg` appliqué upstream** (déjà géré par Story 16.1)
**Given** `SambaToolRunner` opère en mode array (`Process::run(['samba-tool', 'gpo', 'show', $guid])`)
**When** le `$guid` validé par AC4.2 est passé
**Then** aucune réintroduction de concat shell dans la SFC ou la route — seul `SambaToolRunner` invoque le binaire
**And** ceci est garanti par le test archi `GpoNamespaceTest` (existant, pas à recréer).

### Volet 5 — Tests

**AC5.1** — **Tests Feature de la page listing**
**Given** la suite de tests
**When** elle est exécutée
**Then** un fichier `tests/Feature/Gpo/GpoIndexPageTest.php` couvre :
- Rendu de la page (200) avec utilisateur `server.admin`
- 403 sans la permission
- Affichage de N GPOs après mock `GpoService::list()`
- Filtre par recherche réduit le nombre de lignes
- Filtre par statut (active/inactive) basé sur `versionNumber`
- Tri par `displayName` ASC/DESC
- Bouton "Rafraîchir" déclenche une nouvelle invocation de `GpoService::list()`
- Toast d'erreur quand `GpoService::list()` lève une exception
- État vide quand collection vide

**AC5.2** — **Tests Feature de la page détail**
**Given** la suite de tests
**When** elle est exécutée
**Then** un fichier `tests/Feature/Gpo/GpoDetailPageTest.php` couvre :
- Rendu (200) pour GUID valide existant
- 404 pour GUID malformé (pas d'appel samba-tool — `Process::fake()` sans assertion ou mock `GpoService` qui n'est pas invoqué)
- 404 pour GUID valide mais inexistant (`GpoService::get()` retourne null)
- 403 sans la permission
- Affichage des containers liés (mock `listContainers` retourne 3 DNs)
- Affichage des liens GPO par container (mock `getLinks` retourne 2 `GpoLink`)
- Affichage de l'héritage (mock `getInheritance` retourne true puis false)
- Encart "sections natives" affiché si `displayName=redirections` (heuristique)
- Bouton "Éditer dans l'ancienne UI" présent avec `target=_blank` et URL legacy correcte

**AC5.3** — **Test de la redirection catchall** (AC3.1)
- `tests/Feature/Gpo/LegacyGestionGpoRedirectTest.php` : `gpo/gestion_gpo.php` → 302 vers `/app/gpo`.
- `tests/Feature/Gpo/LegacyGestionGpoRedirectTest.php::it_does_not_redirect_legacy_section_pages` : `gpo/wine.php` → != 302 (cohabitation).

**AC5.4** — **Tests unitaires de l'heuristique sections natives**
**Given** le mapping `displayName → URL native` (constante de SFC)
**When** un test unitaire `tests/Unit/Gpo/NativeSectionHeuristicsTest.php` (ou directement dans `GpoDetailPageTest`) s'exécute
**Then** il vérifie au moins 4 mappings (`redirections`, `wallpaper-default`, `firefox-policy`, `shortcuts-default`) + 1 cas non-matchant (`custom-gpo-foo`).

**AC5.5** — **Aucun test casse** (régression)
**Given** la suite de tests complète
**When** elle est lancée sur la VM
**Then** aucune régression introduite (suite verte ou égale au baseline pre-merge).

> **Tests E2E navigateur (Playwright/Cypress) hors scope** — le projet n'a pas d'infra E2E établie pour les pages internes (cf. l'audit `tech-debt-test-infra-cleanup.md`). Tests Feature Livewire suffisants.

---

## Hors-scope (explicite)

- **Mutation de GPO** (création, suppression, édition) → Stories 16.3, 16.4, 16.5.
- **Édition de sections** (Firefox, Veyon, Wine, Network, Roaming) → Story 16.3 (ou 16.3a/b/c selon split recommandé par audit).
- **Liens GPO ↔ WorkstationGroup** (visualisation graphe d'impact) → Story 16.5.
- **Filtre par OU rattachée** dans le listing (coût quadratique sans cache) → Story 16.5 quand cache table dispo.
- **Cache table Eloquent** des GPOs → Story 16.4 (introduit avec la corbeille).
- **Hook GPO ↔ wpkg.js** → Story 16.6.
- **Dashboard ingestion rapports GPO** (parité Story 15.5 pour WPKG) → hors Epic 16 (à arbitrer ultérieurement).
- **Pages d'édition legacy bloquées** (`gpo-maj.php`, `gpo-export.php`, `wine.php`, etc.) → décision SM D5, on les laisse en cohabitation.

---

## Tasks / Subtasks

### Phase T0 — Cadrage & vérifications préalables

- [x] **T0.1** Confirmer que la Story 16.1 est validée par Henri (audit `audit-gpo-legacy.md` OK + smoke tests VM passés). **BLOCKER** théorique — si pas validé, démarrer en parallèle au risque de re-toucher (acceptable pour 16.2 car aucun changement de signature attendu côté `GpoService`).
- [x] **T0.2** Lire `resources/views/pages/parc-settings/wallpapers/index.blade.php` (référence permission + structure SFC simple).
- [x] **T0.3** Lire `resources/views/pages/shortcuts/index.blade.php` (référence listing avec filtres + pagination).
- [x] **T0.4** Vérifier l'existence du composant `<x-organisms.data-table>` (sinon utiliser `<table class="table">` DaisyUI manuel — cf. shortcuts qui le fait déjà). Vérifier aussi `<x-molecules.pagination>` et `<x-molecules.empty-state>` — sinon implémentation manuelle.
- [x] **T0.5** Vérifier que `App\Gpo\Services\GpoService` est bien injectable via le container Laravel (le test feature `GpoLoggingChannelTest` créé en 16.1 le prouve déjà).
- [x] **T0.6** Vérifier le format exact retourné par `samba-tool gpo show` sur la VM (champs disponibles : `displayname`, `version`, `dn`, `path`, etc.). Si `samba-tool` indisponible, se baser sur `tests/Support/FakesGpoService.php` (fixtures samba-tool typiques).

### Phase T1 — Page listing (Volet 1)

- [x] **T1.1** Créer le dossier `resources/views/pages/app/gpo/` (et `_partials/` si besoin de découper). (AC1.1)
- [x] **T1.2** Créer `resources/views/pages/app/gpo/index.blade.php` — SFC Livewire avec :
  - `use WithToasts`
  - `#[Title('Gestion des GPOs - SE4FS')]`
  - `mount()` : check permission `server.admin` (`abort_unless` pattern wallpaper)
  - Properties : `#[Url] $search`, `#[Url] $statusFilter`, `#[Url] $sortBy`, `#[Url] $sortDirection`, `$currentPage`, `$perPage`, `$gpos` (collection cachée mémoire), `$loading`
  - Method `loadGpos()` : appel `GpoService::list()` injecté via `mount(GpoService $service)`, catch exception → toast erreur
  - Method `refresh()` (AC1.6)
  - Methods `updatedSearch()`, `updatedStatusFilter()` → reset `currentPage = 1`
  - Method privée `filteredAndSortedGpos()` : applique filtre/recherche/tri sur la collection
  - Method `paginated()` : `Collection::forPage()`
  - Computed property pour le total filtré
  - Helper `formatVersion(int $version)` : décode major.minor
  - (AC1.2-1.8)
- [x] **T1.3** Templating Blade du listing : tableau (`<table class="table">` DaisyUI), header avec recherche + select statut, footer avec pagination + compteur, état vide gracieux. (AC1.2-1.8)
- [x] **T1.4** Ajouter la route dans `routes/web.php` (groupe `Route::prefix('app')->middleware('sambaedu.auth')` existant) :
  ```php
  Route::livewire('/gpo', 'pages::app.gpo.index')
      ->middleware('can:server.admin')
      ->name('gpo.index');
  ```
  (AC1.1)
- [x] **T1.5** Ajouter un lien vers `/app/gpo` dans la sidebar (`resources/views/components/organisms/sidebar.blade.php`). Recommandation : remplacer le lien legacy `gpo/gestion_gpo.php` ligne 307 par `route('app.gpo.index')`. **Conserver les autres liens legacy** (`gestion_apps.php`, `wallpaper.php`, `shortcuts.php`, `wine.php`) car ces pages ne sont pas refondues par 16.2.

### Phase T2 — Page détail (Volet 2)

- [x] **T2.1** Créer `resources/views/pages/app/gpo/[guid]/index.blade.php` — SFC Livewire avec :
  - `mount(string $guid, GpoService $service)` : check permission, validate GUID format (regex stricte), abort 404 si malformé, charger `get/listContainers/(getLinks+getInheritance) × N`, gérer exceptions individuellement
  - Properties : `$guid`, `$gpo` (`?GpoSummary`), `$containers` (`array<string>`), `$linksByContainer` (`array<string, list<GpoLink>>`), `$inheritanceByContainer` (`array<string, bool>`), `$showAllContainers`, `$loadErrors` (array partiel)
  - Method `toggleShowAll()` (AC2.3 cap 5)
  - Method privée `nativeSectionLinks()` retourne `array<string, string>` selon heuristique (AC2.4)
  - Helper `legacyEditUrl()` (AC2.5)
  - (AC2.1-2.7)
- [x] **T2.2** Templating Blade du détail : header (titre, GUID, version, bouton retour, bouton édition legacy `target=_blank`), section métadonnées, section containers (collapsibles si > 5), encart "Sections gérables nativement" (conditionnel), encart "Détails partiels" (si `$loadErrors` non vide).
- [x] **T2.3** Ajouter la route détail dans `routes/web.php` :
  ```php
  Route::livewire('/gpo/{guid}', 'pages::app.gpo.[guid].index')
      ->where('guid', '\{[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}\}')
      ->middleware('can:server.admin')
      ->name('gpo.show');
  ```
  (AC2.1, AC4.2)

### Phase T3 — Catchall override (Volet 3)

- [x] **T3.1** Ajouter dans `config/sambaedu.php` ligne 42 (`blocked_legacy_routes`) l'entrée :
  ```php
  '^gpo/gestion_gpo\.php$' => 'app/gpo',
  ```
  (AC3.1)
- [x] **T3.2** Vérifier que le mécanisme `LegacyCatchallController` honore bien le pattern (test feature ci-dessous suffit).

### Phase T4 — Tests (Volet 5)

- [x] **T4.1** Créer `tests/Feature/Gpo/GpoIndexPageTest.php` (AC5.1) — utiliser `Livewire::test()`, mocker `GpoService` via container binding. Au moins 9 tests (un par bullet AC5.1).
- [x] **T4.2** Créer `tests/Feature/Gpo/GpoDetailPageTest.php` (AC5.2) — même approche. Au moins 9 tests.
- [x] **T4.3** Créer `tests/Feature/Gpo/LegacyGestionGpoRedirectTest.php` (AC5.3) — 2 tests : redirection + non-redirection des sections.
- [x] **T4.4** Créer `tests/Feature/Gpo/GpoPagePermissionTest.php` (AC4.1) — 4 tests : 200 listing, 403 listing, 200 détail, 403 détail.
- [x] **T4.5** Créer `tests/Feature/Gpo/GpoDetailRouteValidationTest.php` (AC4.2) — 3 tests : GUID malformé `/app/gpo/INJECTION` → 404, GUID sans accolades → 404, GUID valide mais inexistant → 404 via `get()=null`. Vérifier qu'aucun appel `samba-tool` n'est fait pour les 2 premiers.
- [x] **T4.6** (Optionnel) `tests/Unit/Gpo/NativeSectionHeuristicsTest.php` (AC5.4) — peut être inclus dans `GpoDetailPageTest` si la logique reste simple.
- [ ] **T4.7** Lancer la suite ciblée : `php artisan test tests/Feature/Gpo` doit retourner 100 % vert. (**ACTION HENRI** : à lancer sur VM après checkout branche `gpo`)
- [ ] **T4.8** Lancer la suite globale : `php artisan test` — aucune régression vs baseline (idéalement 0 nouveau fail/error). (**ACTION HENRI**)

### Phase T5 — Documentation & QA

- [x] **T5.1** Mettre à jour `docs/qa/domains/gpo.md` (créé en Story 16.1) — ajouter une section "Story 16.2" avec scénarios QA manuels VM :
  1. Naviguer vers `/app/gpo` → liste affichée, GPOs réelles présentes
  2. Recherche par nom : filtre fonctionne
  3. Filtre statut Active/Inactive : valeurs cohérentes
  4. Cliquer sur une GPO → page détail charge containers + liens
  5. Bouton "Éditer dans l'ancienne UI" → ouvre nouvel onglet avec page legacy
  6. Naviguer vers `gpo/gestion_gpo.php` directement → redirection vers `/app/gpo`
  7. Tester avec utilisateur sans `server.admin` → 403
- [ ] **T5.2** (Optionnel, recommandé) Ajouter une note dans `app/Gpo/README.md` — section "Pages natives Epic 16" — pour documenter `/app/gpo` (listing) et `/app/gpo/{guid}` (détail) comme premières pages utilisateur de l'Epic.

### Phase T6 — Validation finale

- [ ] **T6.1** Smoke test sur la VM (Henri) : lancer la page `/app/gpo`, vérifier que les GPOs réelles s'affichent, ouvrir une GPO, vérifier que les containers et liens sont corrects. (**ACTION HENRI**)
- [ ] **T6.2** Vérifier les logs sur la VM : `tail -f storage/logs/gpo/gpo-{date}.log` doit montrer les `action_type=gpo.list`, `gpo.show`, `gpo.containers.list`, `gpo.link.get`, `gpo.inheritance.get` à chaque interaction. (**ACTION HENRI**)
- [x] **T6.3** Mettre à jour `_bmad-output/implementation-artifacts/sprint-status.yaml` : `16-2-listing-lecture-gpo-ui-native: ready-for-dev` → `review` (le dev mettra à jour à la fin).

---

## File List prévisionnelle

### Fichiers créés

```
resources/views/pages/app/gpo/index.blade.php                      ← SFC listing
resources/views/pages/app/gpo/[guid]/index.blade.php               ← SFC détail

tests/Feature/Gpo/GpoIndexPageTest.php                              ← AC5.1
tests/Feature/Gpo/GpoDetailPageTest.php                             ← AC5.2
tests/Feature/Gpo/LegacyGestionGpoRedirectTest.php                  ← AC5.3
tests/Feature/Gpo/GpoPagePermissionTest.php                         ← AC4.1
tests/Feature/Gpo/GpoDetailRouteValidationTest.php                  ← AC4.2
tests/Unit/Gpo/NativeSectionHeuristicsTest.php                      ← AC5.4 (optionnel)
```

### Fichiers modifiés

```
routes/web.php                                                      ← + 2 routes /app/gpo + /app/gpo/{guid}
config/sambaedu.php                                                 ← + entrée 'gpo/gestion_gpo.php' dans blocked_legacy_routes
resources/views/components/organisms/sidebar.blade.php              ← lien GPO legacy → route('app.gpo.index')
docs/qa/domains/gpo.md                                              ← + section Story 16.2 (7 scénarios)
app/Gpo/README.md                                                   ← (optionnel) section "Pages natives Epic 16"
_bmad-output/implementation-artifacts/sprint-status.yaml            ← status ready-for-dev → review (en fin de dev)
```

### Fichiers NON touchés

- `app/Gpo/Services/GpoService.php` — **aucune modification** (les 5 méthodes lecture sont suffisantes — D8 SM)
- `app/Gpo/Dto/*` — aucune modification (formattage version géré côté SFC)
- `app/Gpo/Support/*` — aucune modification
- `legacy/modules/gpo/*` — aucune suppression (D6 SM)

---

## Test Strategy

### Couverture par niveau

| Niveau | Périmètre | Fichiers |
|---|---|---|
| **Feature** | Page listing (rendu, filtres, tri, pagination, refresh, gestion erreur) | `tests/Feature/Gpo/GpoIndexPageTest.php` |
| **Feature** | Page détail (rendu, containers, liens, héritage, encart natif, bouton legacy) | `tests/Feature/Gpo/GpoDetailPageTest.php` |
| **Feature** | Permission `server.admin` requise | `tests/Feature/Gpo/GpoPagePermissionTest.php` |
| **Feature** | Validation regex GUID (anti-injection) | `tests/Feature/Gpo/GpoDetailRouteValidationTest.php` |
| **Feature** | Catchall override (redirection + non-régression cohabitation) | `tests/Feature/Gpo/LegacyGestionGpoRedirectTest.php` |
| **Unit** (optionnel) | Heuristique mapping `displayName → URL native` | `tests/Unit/Gpo/NativeSectionHeuristicsTest.php` |
| **Smoke VM (manuel)** | Henri lance `/app/gpo` sur la VM réelle, vérifie GPOs et logs | (post-merge) |

### Stratégie de mock GpoService

- **Approche recommandée** : binding container Laravel (`$this->app->bind(GpoService::class, fn() => $mock)`) — évite de mocker `Process::fake()` à plusieurs niveaux.
- **Fixtures réutilisables** : étendre `tests/Support/FakesGpoService.php` (créé en 16.1) avec un constructeur fluide :
  ```php
  FakesGpoService::create()
      ->withGpos([$gpo1, $gpo2])
      ->withContainersFor($guid, [$dn1, $dn2])
      ->withLinksFor($dn1, [$link1])
      ->withInheritanceFor($dn1, true)
  ```
- **Anti-pattern à éviter** : appeler `samba-tool` pour de vrai dans les tests Feature (perte de temps + dépendance à l'env de test). Toujours mocker au niveau `GpoService`.

### Tests à NE PAS faire dans cette story

- Tests E2E navigateur (Playwright/Cypress) — pas d'infra E2E établie.
- Tests de mutation GPO — Stories 16.3-16.5.
- Tests de performance (latence `samba-tool listall` × N) — pas d'enjeu sur cette story (parc < 100 GPOs typique).
- Tests de cache — pas de cache dans cette story.

---

## Dev Notes — Contraintes & décisions cadrage SM

### Décisions SM rappelées (cf. tableau Décisions SM ci-dessus)

| # | Décision | Impact dev |
|---|---|---|
| D1 | Route `/app/gpo` (pas `/app/windows-deploy/gpo`) | Filesystem-based router : `pages/app/gpo/{index.blade.php, [guid]/index.blade.php}` |
| D2 | Bouton "Éditer dans l'ancienne UI" en page détail (`target=_blank`) | Lien legacy préservé pendant transition Epic 16 |
| D3 | Pas de table Eloquent — relit samba-tool à chaque mount | Pas de migration. Bouton "Rafraîchir" explicite. |
| D4 | Permission `server.admin` (existante dans Spatie) | Aucune nouvelle perm à créer |
| D5 | Bloquer **uniquement** `gestion_gpo.php` | Cohabitation avec sections legacy (gpo-maj, wine, etc.) |
| D6 | Pagination Livewire sur `Collection::forPage()` | Pas Eloquent paginate — pas de migration |
| D7 | Filtres : recherche + statut. **Pas de filtre OU** | Filtre OU coût quadratique → reporté Story 16.5 |
| D8 | Détail : `get + listContainers + getLinks + getInheritance` (cap 5 containers) | Performance acceptable parc typique |
| D9 | Encart "sections natives" via heuristique displayName | Mapping en const PHP testable |
| D10 | Version affichée `major.minor` (16+16 bits) | Helper Blade ou méthode SFC |

### Références codebase pour le dev

- **SFC Livewire référence permission** : `resources/views/pages/parc-settings/wallpapers/index.blade.php:12-23` — `mount()` avec `abort_unless can('wallpaper.manage')`
- **SFC Livewire référence listing + filtres + URL state** : `resources/views/pages/shortcuts/index.blade.php:1-100` — pattern `#[Url]` + `updatedX()` + pagination
- **Service à consommer** : `app/Gpo/Services/GpoService.php` — méthodes `list/get/listContainers/getLinks/getInheritance` toutes implémentées
- **DTOs** : `App\Gpo\Dto\GpoSummary` (5 champs : `name/displayName/versionNumber/dn/path`) et `App\Gpo\Dto\GpoLink` (6 champs)
- **Route prefix existant** : `routes/web.php:50` — `Route::prefix('app')->middleware('sambaedu.auth')->name('app.')->group(...)`. Mettre les routes GPO **DANS ce groupe**.
- **Convention Volt SFC** : `Route::livewire('/path', 'pages::namespace.subnamespace.index')->name('xxx')` (cf. ligne 53 web.php pour dashboard)
- **Sidebar à modifier** : `resources/views/components/organisms/sidebar.blade.php:307` — ligne du lien legacy `gpo/gestion_gpo.php`
- **Helper test fixtures GPO** : `tests/Support/FakesGpoService.php` (créé en 16.1) — à étendre selon besoins
- **Composant tableau DaisyUI** : `resources/views/components/organisms/data-table.blade.php` (à vérifier l'existence — sinon tableau manuel `<table class="table">`)
- **Empty state** : `<x-molecules.empty-state>` (à vérifier — sinon DaisyUI `card` manuel)
- **Channel logs `gpo`** : déjà configuré (Story 16.1) — `storage/logs/gpo/gpo-{date}.log`
- **Test archi `GpoNamespaceTest`** : interdit `exec()` direct dans `app/Gpo/*` — la SFC est dans `resources/`, donc hors scope du test archi. Mais **respecter l'esprit** : aucun appel système dans la SFC.

### Pièges identifiés

1. **`samba-tool gpo show` peut être lent** (1-3 s sur DC chargé). La page détail enchaîne `show + listContainers + N × (getLinks + getInheritance)`. Pour N=10, ça peut atteindre 20 s. → **Cap N à 5 par défaut** (AC2.3) avec bouton "Tout afficher". Si latence perçue > 1 s, considérer Livewire loading state ou `wire:loading`.

2. **Format du `versionNumber`** : `samba-tool gpo show` retourne le champ `version : 786435` (entier). Décodage : major = `version >> 16`, minor = `version & 0xFFFF`. Vérifier sur la VM le format réel — si `samba-tool` retourne autre chose, ajuster.

3. **Heuristique `displayName → URL native`** : ne pas être trop large dans les regex (éviter qu'un GPO custom `firefox-old` matche `firefox` et propose un lien faussement pertinent). Préférer des matches stricts (`displayName === 'firefox-policy'`) ou exact contains (`stripos === 0`).

4. **GUID format** : samba-tool utilise des GUIDs avec accolades `{XXXXXXXX-...}`. Dans les URLs, les accolades doivent être URL-encodées (`%7B...%7D`) ou tolérer les deux formats (avec et sans accolades). **Recommandation** : URL avec accolades littérales (Laravel/PHP gèrent bien les caractères spéciaux). À tester sur la VM.

5. **`Route::livewire()` vs `Volt::route()`** : le projet utilise `Route::livewire()` (cf. ligne 53 `web.php`). Suivre cette convention, **ne pas introduire `Volt::route()`** sans raison.

6. **Permission Spatie + Gate `can:`** : `middleware('can:server.admin')` fonctionne bien avec Spatie (le `can` honore les permissions Spatie). Vérifier que `mount()` aussi ajoute son propre `abort_unless` (defense in depth) — pattern wallpaper.

7. **Lien legacy `?selectionne={displayName}`** : le shim ne le supporte peut-être pas. Vérifier le code legacy `legacy/modules/gpo/gestion_gpo.php` pour confirmer. Si pas supporté, juste lien sans paramètre.

8. **Catchall et tests** : `LegacyCatchallController` peut nécessiter du setup spécifique en test (route enregistrée + config bridgée). Vérifier les tests existants `tests/Feature/Legacy/*` pour le pattern.

9. **Pas de Volt class component**, pas de Forms — convention SFC inline new class.

---

## Project Structure Notes

### Alignement structure projet

- **Page Livewire** : `resources/views/pages/app/gpo/{index.blade.php, [guid]/index.blade.php}` (filesystem-based router maison)
- **Route name** : `app.gpo.index` et `app.gpo.show` (préfixe `app.` du groupe)
- **Tests** : `tests/Feature/Gpo/` (dossier déjà créé en 16.1)
- **Pas de nouveau code dans `app/`** — la SFC consomme uniquement `App\Gpo\Services\GpoService` existant

### Conflits / variances détectés

| Élément | Doc architecture | Décision Story 16.2 | Justification |
|---|---|---|---|
| Route page GPO | `pages/windows-deploy/` (architecture.md:486) | `pages/app/gpo/` | D1 : doc jamais appliquée, codebase n'a pas créé `windows-deploy/`. Cohérence avec `/app/users`, `/app/parc-settings`, etc. |
| Permission GPO | (non spécifié) | `server.admin` (existant) | D4 : pattern legacy + permission Spatie existante |
| Pagination | Eloquent (norme Laravel) | Livewire `Collection::forPage()` | D6 : `GpoService::list()` retourne `Collection`, pas Eloquent — éviter conversion artificielle |

---

## References

- `_bmad-output/planning-artifacts/epics.md:3294-3332` — Epic 16 cadrage
- `_bmad-output/planning-artifacts/epics.md:3314-3316` — Story 16.2 cadrage haut niveau
- `_bmad-output/planning-artifacts/audit-gpo-legacy.md` — audit Story 16.1, en particulier :
  - Section 6.A `gpo/gestion_gpo.php` (ligne 60+) — page d'index legacy
  - Section 6.C — mapping sections spécialisées (recommandation liens profonds)
  - Section 6.D — contour shim 1bis-18 (pages cohabitantes)
  - Section 6.G "Story 16.2" (lignes 608-616) — recommandation découpage
- `_bmad-output/planning-artifacts/architecture.md:486` — préfixe `pages/windows-deploy/` (non appliqué — voir D1)
- `_bmad-output/planning-artifacts/architecture.md:332-353` — couche Services + règle "jamais Eloquent direct dans Livewire"
- `_bmad-output/implementation-artifacts/16-1-fondations-gpo-natives-audit-legacy.md` — fondations posées
- `_bmad-output/implementation-artifacts/15-1-fondations-pipeline-deploiement-wpkg.md` — pattern référence Epic 15
- `_bmad-output/implementation-artifacts/15-4-ui-admin-assignation-apps-wpkg.md` — pattern UI Livewire avec onglets, bulk, modales (référence structure UI complexe — pas appliquée ici car listing simple)
- `app/Gpo/Services/GpoService.php` — service à consommer (5 méthodes lecture)
- `app/Gpo/Dto/{GpoSummary,GpoLink}.php` — DTOs typés
- `app/Enums/SambaPermission.php:58` — `ServerAdmin = 'server.admin'`
- `config/sambaedu.php:42-46` — `blocked_legacy_routes` (à compléter)
- `resources/views/pages/parc-settings/wallpapers/index.blade.php` — pattern permission `mount()`
- `resources/views/pages/shortcuts/index.blade.php` — pattern listing + filtres + `#[Url]` + pagination
- `resources/views/components/organisms/sidebar.blade.php:307` — lien legacy à remplacer
- `routes/web.php:50-160` — groupe `Route::prefix('app')` à étendre
- `tests/Support/FakesGpoService.php` — fixtures samba-tool (créé en 16.1)
- `docs/qa/domains/gpo.md` — runbook QA (à compléter section Story 16.2)

---

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- Pas d'appel samba-tool réel pendant le dev (branche `gpo` non synchronisée avec la VM qui est sur `main`).
- Tests à exécuter par Henri sur la VM après merge/checkout de la branche `gpo`.

### Completion Notes List

- **T0.1** : Story 16.1 en `review` — démarrage en parallèle acceptable (aucun changement de signature `GpoService` nécessaire).
- **T0.4** : `<x-organisms.data-table>` et `<x-molecules.pagination>` existent et sont utilisés (pattern shortcuts copié fidèlement).
- **T0.4** : `<x-molecules.empty-state>` n'existe PAS — état vide implémenté manuellement (card DaisyUI, cohérent avec shortcuts).
- **T0.5** : `GpoService` est injectable via le container (GpoServiceProvider posé en 16.1).
- **T1.5** : Lien sidebar ligne 307 mis à jour vers `route('app.gpo.index')`.
- **T2.3** : Route détail avec `where('guid', ...)` pour validation GUID strict côté route Laravel. Validation regex supplémentaire dans `mount()` pour defense in depth (AC4.2).
- **Piège GUID accolades** : Laravel accepte les accolades `{` et `}` dans le path URL. La regex where utilise un pattern qui les inclut. En pratique, l'URL sera `/app/gpo/{XXXXXXXX-...}` — les accolades sont transmises telles quelles (non URL-encodées par les navigateurs modernes avec ce type de route Laravel).
- **Piège route livewire** : Le namespace `pages::app.gpo.[guid].index` utilise les crochets pour le segment dynamique, cohérent avec le pattern `pages::workers.[pid].index` existant dans `web.php` ligne 57.
- **Heuristique D9** : Constante `NATIVE_SECTIONS_HEURISTICS` définie dans la SFC `[guid]/index.blade.php` ET dupliquée dans `NativeSectionHeuristicsTest.php` (note dans le test pour maintenir la synchronisation).
- **Décision D10 (version)** : Décodage `major >> 16 | minor & 0xFFFF` implémenté dans `formatVersion()` sur les deux SFCs.
- **Décision D2** : Bouton legacy présent en header page détail ET dans les actions de chaque ligne du tableau listing (deux points d'accès).
- **T4.3** : `LegacyGestionGpoRedirectTest` utilise le même pattern que `LegacyCatchallTest` (tmp dir + Http::fake + Config::set).
- **Tests non exécutés en CI** : La branche `gpo` n'est pas synchronisée vers la VM (inotify surveille seulement la branche `main`). Henri doit basculer sur `gpo` en VM pour lancer les tests.

### File List

**Fichiers créés :**
```
resources/views/pages/app/gpo/index.blade.php                      ← SFC listing (AC Volet 1)
resources/views/pages/app/gpo/[guid]/index.blade.php               ← SFC détail (AC Volet 2)
tests/Feature/Gpo/GpoIndexPageTest.php                              ← AC5.1 (10 tests, +1 clearFilters)
tests/Feature/Gpo/GpoDetailPageTest.php                             ← AC5.2 (11 tests, +dataProvider 5 cas)
tests/Feature/Gpo/LegacyGestionGpoRedirectTest.php                  ← AC5.3 (3 tests)
tests/Feature/Gpo/GpoPagePermissionTest.php                         ← AC4.1 (4 tests)
tests/Feature/Gpo/GpoDetailRouteValidationTest.php                  ← AC4.2 (5 tests, +2 HTTP)
tests/Concerns/BootstrapsSpatieTables.php                           ← Trait factorisant le bootstrap Spatie (Code Review fix #11)
```

**Fichiers supprimés :**
```
tests/Unit/Gpo/NativeSectionHeuristicsTest.php   ← test dupliqué supprimé (Code Review fix #7)
```

**Fichiers modifiés :**
```
routes/web.php                                   ← +2 routes /app/gpo + /app/gpo/{guid} + regex GUID tolérante (fix #2 #9)
config/sambaedu.php                              ← +entrée blocked_legacy_routes gestion_gpo.php
resources/views/components/organisms/sidebar.blade.php ← lien GPO → route('app.gpo.index')
docs/qa/domains/gpo.md                          ← Section 2 Story 16.2 (10 scénarios)
tests/Support/FakesGpoService.php                ← Builder fluide de mock (Code Review fix #11)
_bmad-output/implementation-artifacts/sprint-status.yaml ← 16-2 ready-for-dev → review
_bmad-output/implementation-artifacts/16-2-listing-lecture-gpo-ui-native.md ← status + record
_bmad-output/codeReviews/16-2.md                 ← Statuts review actualisés (12/12 ✅)
```

### Code Review Fixes (2026-05-11)

12 corrections appliquées suite à la review opus (`_bmad-output/codeReviews/16-2.md`) :

| # | Fix | Fichier(s) principal(aux) |
|---|-----|---------------------------|
| 1 | Test HTTP de la regex de route ajouté (frappe `/app/gpo/...` au lieu de `Livewire::test()`) | `tests/Feature/Gpo/GpoDetailRouteValidationTest.php` |
| 2 | Commentaire trompeur sur l'ordre des routes retiré, doc de la regex stricte | `routes/web.php` |
| 3 | `boot()` injecte le service, `mount()` ne reçoit que les paramètres routés (alignement pattern projet) | les 2 SFCs |
| 4 | Méthode `clearFilters()` remplace le `wire:click="$set(...); $set(...)"` invalide | `index.blade.php` |
| 5 | Toast « Liste rafraîchie » utilise `$totalGpos` au lieu de `count(paginatedGpos())` | `index.blade.php` |
| 6 | `urlencode($name)` → `http_build_query(['selectionne' => $name])` | les 2 SFCs |
| 7 | Test unitaire `NativeSectionHeuristicsTest.php` supprimé, `GpoDetailPageTest::it_renders_correct_native_section_for_display_name` (dataProvider, 5 cas) ajouté → testé sur la SFC réelle | `tests/Feature/Gpo/GpoDetailPageTest.php` |
| 8 | `loadContainerDetails(array $dns)` extrait, idempotent (skip si déjà chargé) ; `toggleShowAll()` ne re-charge plus la GPO ni `listContainers()` | `[guid]/index.blade.php` |
| 9 | Regex de route assouplie (`\{?...\}?`), `normalizeGuid()` strip+rajoute les accolades pour samba-tool | `routes/web.php`, `[guid]/index.blade.php` |
| 10 | `abort(404)` sorti du try/catch, exception réelle → `hasError = true` + toast (page navigable AC2.7) | `[guid]/index.blade.php` |
| 11 | Trait `BootstrapsSpatieTables` créé + `FakesGpoService` enrichi d'un builder fluide ; 4 tests Feature refactorés | `tests/Concerns/BootstrapsSpatieTables.php`, `tests/Support/FakesGpoService.php`, 4 fichiers `tests/Feature/Gpo/` |
| 12 | `onclick="window.location.href=..."` remplacé par `<a href="...">` (navigation native, accessibilité) | `index.blade.php` |

`php -l` OK sur les 9 fichiers PHP touchés. Tests non exécutés (branche `gpo` non sync VM — Henri à lancer post-merge).

### Change Log

| Date       | Auteur               | Changement                                                            |
|------------|----------------------|-----------------------------------------------------------------------|
| 2026-05-11 | claude-opus-4-7 (SM) | Story créée, status `ready-for-dev`. 10 décisions SM (D1-D10), 5 volets ACs, 6 phases T0-T6. Audit Story 16.1 intégré (recommandation Section 6.G "Story 16.2" appliquée). Discrepances 16.1 ouvertes tranchées : Q1 route → `/app/gpo` (D1), Q2 lien shim → OUI bouton dédié page détail (D2). |
| 2026-05-11 | claude-sonnet-4-6 (dev) | Implémentation complète. Status `ready-for-dev` → `review`. 2 SFC Livewire créées (`index.blade.php` listing + `[guid]/index.blade.php` détail). 6 fichiers tests (Feature + Unit). Config catchall + sidebar + routes mis à jour. QA doc Section 2 ajoutée. Tests non lancés (VM sur branche `main` — action Henri requise). |
| 2026-05-11 | claude-opus-4-7 (review-fixes) | 12 fixes de la code review appliqués (cf. tableau ci-dessus). Trait `BootstrapsSpatieTables` extrait, `FakesGpoService` enrichi (builder fluide), `NativeSectionHeuristicsTest` supprimé, regex GUID tolérante aux accolades, `abort(404)` sorti du try/catch, `onclick` → `<a>`, etc. `php -l` OK. Status reste `review` jusqu'à validation Henri post-tests VM. |

---

## Recommandation Modèle Dev

**Modèle recommandé : sonnet**

Raison :

1. **Story essentiellement UI/CRUD lecture** — le scope est constitué de 2 SFC Livewire (listing + détail), 5 fichiers de tests Feature, 1 modification config catchall, 1 modification sidebar, 1 modification routes. Pas de logique métier critique : tout passe par `GpoService` déjà construit en 16.1.

2. **Pattern UI déjà bien établi dans le codebase** — la SFC listing reproduit fidèlement le pattern de `pages/shortcuts/index.blade.php` (filtres `#[Url]`, pagination Livewire, `WithToasts`). La SFC détail est plus simple encore (pas de mutations, pas de modale). Sonnet excelle dans ce type de réplication de pattern.

3. **Aucune décision architecturale ouverte** — toutes les décisions (D1-D10) sont déjà tranchées dans la story (route, permission, scope catchall, scope filtres, scope détail). Le dev n'a pas à arbitrer, juste à exécuter.

4. **Aucun nouveau service / DTO / migration** — le namespace `App\Gpo` n'est pas touché. C'est purement une consommation de l'API stable posée en 16.1.

5. **Sécurité maîtrisée upstream** — `SambaToolRunner` (mode array, no shell concat) et `GpoNamespaceTest` (interdit `exec`) sont déjà en place. Le seul risque sécu de la story est la **validation du paramètre `{guid}`** (AC4.2), couvert par une regex stricte explicite et un test dédié.

6. **Tests Feature Livewire sont du pattern bien rodé** — `Livewire::test()` + binding container pour mocker `GpoService` + assertions d'affichage. Pas de complexité particulière.

**Si le dev rencontre une ambiguïté** (par ex. `samba-tool gpo show` retourne un format inattendu, ou l'heuristique sections natives explose en complexité, ou le composant `<x-organisms.data-table>` n'existe pas et il faut décider de l'implémentation tableau), il peut **escalader** vers une revue ou demander un avis SM. Mais le périmètre nominal est largement à portée de sonnet.

> **Estimation charge dev** : 1-2 jours. Cohérent avec l'estimation de l'audit Section 6.G ("1-2 jours dev + 0.5 jour QA").
