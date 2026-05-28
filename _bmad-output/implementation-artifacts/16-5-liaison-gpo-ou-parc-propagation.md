# Story 16.5 : Liaison GPO ↔ OU / parc + propagation

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> Première story **write AD** d'Epic 16 (toutes les précédentes 16.1/16.2/16.3*/16.7 sont read ou endpoint runtime). Implémente les stubs `setLink` / `removeLink` / `setInheritance` de `GpoService` (Story 16.1) + UI Livewire native de gestion des liaisons GPO ↔ OU AD + graphe d'impact lecture seule.
>
> **Périmètre strictement liaison/propagation** — la création/duplication/suppression de GPO reste dans le shim legacy `1bis-18` (Story 16-4 PAUSED). Aucun bouton "créer une GPO" dans l'UI native — un encart explicite redirige vers `/gpo/gpo-maj.php` (shim) si besoin.

---

## ⚠️ Décisions pré-tranchées par Henri (D1-D10, à appliquer telles quelles)

> Le SM a tranché 10 décisions cadrage en amont du dev. Le dev applique sans re-débattre ; en cas de blocage technique réel, il documente la difficulté et continue.

### D1 — Cible de liaison : **OU AD uniquement** en première itération (parité legacy stricte)

- Les GPOs s'attachent aux **OUs AD** (attribut `gpLink` sur l'OU AD), reproduction stricte du legacy `gposetlink`/`gpodellink`.
- **`WorkstationGroup` (table SQL) HORS SCOPE 16-5** : pas de mapping local SQL → OU AD dans cette story. Si Henri souhaite plus tard exposer une liaison `WorkstationGroup`, ce sera une story de suivi (16.5b) qui ajoutera une couche d'abstraction au-dessus de `setLink`.
- Justification : `WorkstationGroup` correspond souvent **déjà** à une OU AD (mapping name → DN) — l'UI 16-5 affiche l'OU AD ; la liaison via WorkstationGroup serait juste un sucre syntaxique qui peut être ajouté plus tard sans casser la base.

### D2 — Mécanisme d'écriture `gpLink` : **`samba-tool gpo setlink` exclusivement** (pas d'écriture LDAP directe)

- Pattern strict iso-Stories 16.1 / 16.3b / 16.7 : `SambaToolRunner` mode array, jamais `LdapRecord::update()` direct sur l'attribut `gpLink`.
- Justification : (a) défense en profondeur shell (mode array) ; (b) audit log gratuit via `GpoLogger` ; (c) parité legacy (le legacy passe exclusivement par `samba-tool` également) ; (d) pas de question droits LDAP write pour le compte Laravel ; (e) `samba-tool` gère correctement le format `gpLink` (concat ordonné `[LDAP://CN=...;0][...]` non trivial à reproduire à la main).

### D3 — Précédence / ordre : **affichage + gestion via réordonnancement explicite**

- L'attribut `gpLink` est une string ordonnée format `[LDAP://CN={GUID},...;0][LDAP://CN={GUID},...;1]` — l'ordre détermine la précédence (la **dernière** liaison gagne en cas de conflit selon le modèle Windows GPO).
- **UI** : afficher les liaisons d'une OU dans l'ordre `gpLink` natif, avec boutons "Monter" / "Descendre" pour réordonner.
- **Implémentation** : `samba-tool gpo setlink` ne réordonne pas nativement → pour réordonner, il faut **`removeLink` puis `setLink` à la bonne position**. Le SambaToolRunner n'expose pas d'option position → fallback : recréer tous les liens dans l'ordre voulu (transaction logique : remove all + setlink ordered). **Complexité acceptée** : c'est une action explicite d'admin (rare), latence ~2s acceptable.
- Action loggée : `gpo.link.order.update` (nouvelle entrée au catalogue Story 16.1).

### D4 — Désactivation vs suppression : **expose les deux** dans l'UI

- Le format `gpLink` permet de désactiver un lien (flag `1` = enforced, `2` = disabled, `3` = both) au lieu de supprimer.
- Préserver la flexibilité du legacy : `samba-tool gpo setlink --enforce` et `--disable` sont déjà dans la signature de `GpoService::setLink()` (stub Story 16.1).
- **UI** : pour chaque lien, afficher 4 actions : "Désactiver/Activer" (toggle disabled), "Forcer/Ne plus forcer" (toggle enforced), "Délier" (removeLink), "Réordonner" (up/down).
- Action loggée : `gpo.link.toggle.disabled`, `gpo.link.toggle.enforced` (nouvelles entrées catalogue).

### D5 — Graphe d'impact : **arbre 2 niveaux lecture seule + comptage postes** (KISS)

- Pas de viz graphique lourde (D3.js, etc.). **Arbre HTML simple** : GPO → OU(s) liée(s) → comptage `Workstation::where('ou_dn', $ouDn)->count()` ou équivalent.
- Affichage : depuis `/app/gpo/{guid}`, encart "Impact" avec liste des OUs liées + nombre de postes contenus dans chaque OU.
- **Pas d'arbre interactif** (collapse/expand). Pas de filtre. Lecture pure.
- Source de comptage postes : modèle Eloquent `Workstation` (Story 4.x), colonne `ou_dn` ou équivalent à vérifier T0. Si la colonne n'existe pas, fallback : compter via `MachineModel` LdapRecord avec filtre OU. **À trancher T0.4**.

### D6 — Modales de confirmation obligatoires sur toute opération write AD

- Toute action write (setLink, removeLink, setInheritance, toggle disabled/enforced, réordonnancement) déclenche une **modale de confirmation** via le composant `<x-modal>` réutilisable (cf. CLAUDE.md).
- Texte explicite : "Vous allez lier la GPO «{displayName}» à l'OU «{ouName}». Cette action est immédiate et propagée à tous les postes liés à cette OU au prochain `gpupdate`. Confirmer ?"
- Pattern : iso `wallpapers/index.blade.php` (Story 4.7) qui utilise `<x-modal>` pour les confirmations destructives.

### D7 — Logging dual channel iso-Stories 16.3b/16.7

- **`gpo`** (admin audit) → pour toute action write AD (`gpo.link.add`, `gpo.link.remove`, `gpo.link.order.update`, `gpo.link.toggle.disabled`, `gpo.link.toggle.enforced`, `gpo.inheritance.set`). Volume faible (~10/jour parc typique).
- **`daily`** (runtime/UI) → pour les actions de lecture si volume élevé (pas le cas ici — UI admin, lecture pure dans le détail GPO). On reste sur `gpo` exclusif dans cette story.
- Catalogue `action_type` enrichi : 6 nouvelles entrées documentées dans `app/Gpo/README.md` (cf. AC4.3).

### D8 — Iso-bytes : **non applicable** (UI admin, pas endpoint runtime poste client)

- Contrairement à 16.3b/16.3c/16.7, cette story ne génère **aucune sortie** consommée par les postes Windows (pas de script `.cmd`/`.bash` à reproduire byte-identique).
- Les écritures `gpLink` sont consommées par les postes via le mécanisme natif Windows `gpupdate`, pas via un endpoint HTTP serveur.
- **Pas de fixture comparison** dans cette story.

### D9 — Tests : **Unit GpoService + Feature Livewire + smoke VM**

- **Unit** : 4 nouvelles méthodes `setLink` / `removeLink` / `setInheritance` + helper réordonnancement → `tests/Unit/Gpo/GpoServiceWriteTest.php` (~12 tests, mock `SambaToolRunner` via `Process::fake()`).
- **Feature** : pages Livewire `/app/gpo/{guid}` (encart liaisons enrichi) + `/app/gpo/{guid}/links` (page dédiée gestion) → `tests/Feature/Gpo/GpoLinksPageTest.php` (~12 tests).
- **Architecture** : enrichir `GpoNamespaceTest` (Story 16.1) pour vérifier que les nouvelles méthodes write ne contournent pas `SambaToolRunner`.
- **Smoke VM** : Henri action — lier/délier/réordonner une GPO sur une OU de test, vérifier propagation via `gpupdate /force` sur un poste de test, vérifier logs `gpo` channel.

### D10 — Frontière Epic 17 : **stricte**

- 17.3 traite l'association **script Windows** → cible. 16-5 traite la liaison **GPO** → OU.
- 16-5 ne touche **pas** aux scripts (les scripts sont des fichiers `.bat`/`.vbs`/`.ps1` sur NETLOGON, indépendants des liaisons gpLink). Une GPO peut **invoquer** un script via les sections "scripts" Windows, mais c'est une **autre** story (16.6 / 17.3 jonction).
- Si une GPO est créée pour orchestrer un script personnalisé, cette liaison GPO → OU passera par 16-5 ; le script lui-même sera porté en Epic 17.

---

## Story

As a **responsable SER (rôle `server.admin`)**,
I want **lier et délier les GPOs existantes aux OUs Active Directory de mon établissement, gérer l'ordre de précédence (priorité d'application) et l'état (activé/désactivé, forcé/non-forcé) de chaque liaison, et visualiser l'impact (nombre de postes affectés) — depuis l'interface native Laravel**,
So que je dispose d'une **vue unifiée et contrôlée** des liaisons GPO du parc — sans avoir à manipuler `samba-tool` en ligne de commande ni à ouvrir l'UI legacy `gpo-maj.php`/`gestion_gpo.php` qui mélange import/export/liaison.

---

## Contexte

### Position dans Epic 16

L'Epic 16 remplace progressivement le module GPO legacy. Les stories précédentes ont posé :

1. **Story 16.1** — fondations : `App\Gpo\Services\GpoService` (5 méthodes lecture **implémentées** + 6 stubs **non implémentés** dont `setLink`, `removeLink`, `setInheritance`), `SambaToolRunner` mode array, `GpoLogger` + catalogue 14 `action_type`, channel logs `gpo`.
2. **Story 16.2** — UI listing `/app/gpo` + détail `/app/gpo/{guid}` (lecture seule, consomme les 5 méthodes lecture).
3. **Story 16.3a** — `NativeSectionResolver` (liens profonds depuis le détail GPO vers les pages natives sections).
4. **Stories 16.3b / 16.3c / 16.7** — endpoints runtime (network_out, veyon_out, wine, associations_out, applications.php) consommés par les postes au boot/logon.

**Story 16.5 ouvre la phase write AD admin d'Epic 16** : c'est la **première** story qui implémente effectivement les stubs write de `GpoService` (les autres stories write — `create`, `delete`, `fetch` — restent paused dans 16-4).

### Mécanique legacy à reproduire

Le legacy `sambaedu/gpo/gpo-maj.php` (193 lignes) **mélange** import de templates ZIP + création de GPO + liaison gpLink (via `import_gpo` → `gposetlink`). La fonction de liaison réelle est **`gposetlink($config, $container_dn, $gpo_guid, $enforce, $disable)`** dans `samba-tool.inc.php` (lignes 989-1014) — invoque `samba-tool gpo setlink` ou `samba-tool gpo dellink` selon flags.

L'UI legacy de liaison **n'existe pas en tant que telle** : elle est implicite dans le flux d'import (la GPO importée est automatiquement liée à l'OU racine de l'établissement). **16-5 introduit une UI dédiée native** centrée sur la liaison pure (pas l'import).

### Surface AD à porter natif

| Wrapper legacy | Méthode native (stub Story 16.1) | Path |
|---|---|---|
| `gposetlink($config, $container_dn, $gpo, $enforce, $disable)` (samba-tool.inc.php:989) | `GpoService::setLink(string $containerDn, string $gpoName, bool $enforce=false, bool $disable=false): bool` (stub) | `app/Gpo/Services/GpoService.php:258` |
| `gpodellink($config, $container_dn, $gpo)` (samba-tool.inc.php:1015) | `GpoService::removeLink(string $containerDn, string $gpoName): bool` (stub) | `app/Gpo/Services/GpoService.php:278` |
| `gposetinheritance($config, $container_dn, $flag)` (samba-tool.inc.php:1027) | `GpoService::setInheritance(string $containerDn, bool $enabled): bool` (stub) | `app/Gpo/Services/GpoService.php:296` |
| (n/a — pas wrappé legacy) | nouvelle méthode `GpoService::reorderLinks(string $containerDn, array $orderedGpoNames): bool` | à créer (D3) |

### Surface lecture déjà disponible (réutilisation directe)

| Besoin | Existant (réutiliser) | Path |
|---|---|---|
| Lister les GPOs | `GpoService::list(): Collection<GpoSummary>` | `app/Gpo/Services/GpoService.php:52` |
| Lire les containers liés à une GPO | `GpoService::listContainers(string $name): array<string>` | `app/Gpo/Services/GpoService.php:116` |
| Lire les liens d'une OU | `GpoService::getLinks(string $containerDn): array<GpoLink>` | `app/Gpo/Services/GpoService.php:148` |
| Lire l'héritage d'une OU | `GpoService::getInheritance(string $containerDn): bool` | `app/Gpo/Services/GpoService.php:177` |
| Lister les OUs du domaine | `App\LdapModels\OrganizationalUnitModel` (LdapRecord) + recherche par parent DN ou base DN | `app/LdapModels/OrganizationalUnitModel.php` |
| Compter postes par OU | `Workstation` Eloquent (Story 4.x) — à vérifier T0.4 colonne `ou_dn` | `app/Models/Workstation.php` |

### Position dans la chaîne native

```
[Admin SER] → /app/gpo/{guid}/links (UI Livewire 16-5)
                       │
                       ├── lit OUs domaine (LdapRecord OrganizationalUnitModel)
                       ├── lit liens existants (GpoService::getLinks)
                       ├── écrit gpLink (GpoService::setLink/removeLink — IMPLÉMENTÉS EN 16-5)
                       └── lit comptage postes par OU (Workstation Eloquent)
                       │
                       ▼
              samba-tool gpo setlink/dellink/setinheritance
                       │
                       ▼
              AD writeback (attribut gpLink sur OU)
                       │
                       ▼
         (postes Windows appliquent au prochain gpupdate /force)
```

---

## Garde-fous Epic 16 (rappel applicables à cette story)

- **AD = source de vérité** : aucune table Eloquent **nouvelle** créée en 16-5. Les liens sont lus via `getLinks` + écrits via `setLink` (samba-tool). Pas de cache local (recharge à chaque mount + invalidation après chaque write).
- **Trois couches** (`architecture.md:343-353`) : Livewire SFC → `GpoService` (déjà existant) → `SambaToolRunner` → AD. Pas d'`exec()` direct dans la SFC.
- **Pattern strict iso-Stories précédentes** : (a) `Route::livewire()` (pas `Volt::route`) ; (b) `WithToasts` trait pour notifications ; (c) modale `<x-modal>` pour confirmations destructives ; (d) `mount()` check `abort_unless(can('server.admin'))` + middleware `can:server.admin` sur la route (defense in depth).
- **Pas de @legacy-port** dans cette story : les 4 méthodes write sont **portées natif** (implémentation des stubs 16.1, pas de shim).
- **Shim 1bis-18 reste vivant** : aucune suppression de fichier legacy. Pas de catchall override d'URLs legacy (pas d'URL legacy équivalente — la liaison est implicite dans `gpo-maj.php`).
- **Channel logs `gpo`** : tout write AD logge via `GpoLogger::action()` (4 nouveaux `action_type` documentés dans `app/Gpo/README.md`).
- **Iso-bytes non applicable** (D8) — pas de fixture comparison.
- **Auth iso-legacy** : aucune modification de l'auth machine. La page admin est gardée par `server.admin` (Spatie). Aucun secret per-host (memory `feedback_auth_iso_legacy`).

---

## Infrastructure native existante à RÉUTILISER (pas de réinvention)

> Le dev consulte cette table **AVANT** d'écrire toute nouvelle classe.

| Besoin | Réutiliser | Path | Note |
|---|---|---|---|
| Service GPO orchestrateur | `App\Gpo\Services\GpoService` | `app/Gpo/Services/GpoService.php` | **Étendre** : implémenter `setLink`, `removeLink`, `setInheritance` (stubs ligne 258/278/296) + ajouter `reorderLinks` |
| Wrapper samba-tool sécurisé | `App\Gpo\Support\SambaToolRunner` (mode array) | `app/Gpo/Support/SambaToolRunner.php` | Mode array exclusif (déjà la convention) |
| Logger structuré gpo | `App\Gpo\Support\GpoLogger` + `App\Gpo\Support\GpoActionLog` | `app/Gpo/Support/GpoLogger.php` | `GpoLogger::action()` avec context + step + success/failure |
| Catalogue `action_type` | Documenté dans `app/Gpo/README.md` (Story 16.1) | `app/Gpo/README.md` | Enrichir avec les 4-6 nouvelles entrées (cf. AC4.3) |
| Resolver section native (lien profond) | `App\Gpo\Support\NativeSectionResolver` (Story 16.3a) | `app/Gpo/Support/NativeSectionResolver.php` | Pas modifié par 16-5 — référence pour cohérence pattern |
| DTOs lecture | `App\Gpo\Dto\GpoSummary`, `App\Gpo\Dto\GpoLink` | `app/Gpo/Dto/` | `GpoLink` contient déjà `enforced`/`disabled` (à vérifier T0.5) |
| Model LdapRecord OU | `App\LdapModels\OrganizationalUnitModel` | `app/LdapModels/OrganizationalUnitModel.php` | Lister/rechercher OUs domaine |
| Model Eloquent postes | `App\Models\Workstation` | `app/Models/Workstation.php` | Compter postes par OU (vérifier T0.4 colonne `ou_dn` ou équivalent) |
| Permission Spatie | `App\Enums\SambaPermission::ServerAdmin` (`'server.admin'`) | `app/Enums/SambaPermission.php:58` | Aucune nouvelle perm |
| Pattern SFC Livewire write + modale | `resources/views/pages/parc-settings/wallpapers/index.blade.php` (Story 4.7) | `resources/views/pages/parc-settings/wallpapers/index.blade.php` | Pattern référence `<x-modal>` + `WithToasts` + actions destructives |
| Trait notifications | `App\Components\Traits\WithToasts` (CLAUDE.md) | `app/Components/Traits/WithToasts.php` | `toastSuccess` / `toastError` / `toastWarning` |
| Pattern SFC Livewire détail GPO existant | `resources/views/pages/app/gpo/[guid]/index.blade.php` (Story 16.2) | `resources/views/pages/app/gpo/[guid]/index.blade.php` | Référence structure détail + navigation breadcrumb `?from_gpo` |
| Helper test fixtures | `tests/Support/FakesGpoService.php` (Story 16.1, builder fluide enrichi 16.2) | `tests/Support/FakesGpoService.php` | À étendre pour mocker `setLink` / `removeLink` / `setInheritance` |
| Trait test Spatie bootstrap | `tests/Concerns/BootstrapsSpatieTables` (Story 16.2) | `tests/Concerns/BootstrapsSpatieTables.php` | Bootstrap tables Spatie pour tests permission |
| Test architecture namespace | `tests/Architecture/GpoNamespaceTest` (Story 16.1) | `tests/Architecture/GpoNamespaceTest.php` | Enrichir avec garde-fous setLink/removeLink → SambaToolRunner |

---

## Dépendances

| Story / Epic | Titre | Status | Détail |
|---|---|---|---|
| **16.1** | Fondations GPO natives + audit legacy | review (2026-05-11, smoke VM en attente Henri) | **Bloquant doux** : fournit les stubs `setLink` / `removeLink` / `setInheritance` à implémenter + `SambaToolRunner` + `GpoLogger` + channel `gpo`. Aucun changement de signature attendu côté `GpoService` (les signatures stubs sont stables). |
| **16.2** | Listing & lecture GPO UI native | review | **Bloquant doux** : fournit la base UI `/app/gpo/{guid}` (détail) à enrichir avec l'encart liaisons + lien vers la sous-page `/app/gpo/{guid}/links`. Pattern Livewire SFC à reproduire. |
| **16.3a** | Liens profonds sections natives | review | Non bloquant. Pas d'enrichissement `NativeSectionResolver` pour 16-5 (la page links n'est pas une section native). |
| **16.3b / 16.3c / 16.7** | Endpoints runtime | review | Non bloquant. Pattern Controller iso-bytes pas applicable ici (UI admin, pas endpoint runtime). |
| **16.4** | CRUD GPO | **paused** (2026-05-13 décision Henri) | **Impact UI** : la sous-page liaison **NE DOIT PAS** exposer de bouton "créer une GPO". Un encart "Pour créer/dupliquer/supprimer une GPO, utilisez l'ancienne UI" + lien `target=_blank` vers `/gpo/gpo-maj.php` (shim) est obligatoire. |
| **16.6** | Hook GPO → wpkg.js | backlog | **Parallélisable** : 16.6 réutilisera la mécanique de liaison de 16-5 quand il créera la GPO `se4_applications` qui invoque `wpkg.js`. |
| **17.x** | Scripts de Démarrage Windows | not-ready | **Frontière** : 17 = scripts. 16-5 = liaisons GPO. Aucune dépendance directe. |
| **Epic 4** | Workstation, WorkstationGroup, AppProfile | done | `Workstation` Eloquent pour comptage postes par OU. **`WorkstationGroup` HORS SCOPE 16-5** (D1). |
| **1bis-18b** | Shim legacy gpo (`gpo-maj.php`, `gestion_gpo.php`) | review | **Conservation explicite** : les fichiers shim restent en place. La sous-page liaison **ne bloque PAS** l'URL legacy correspondante (pas de `blocked_legacy_routes` ajouté). Henri voit les deux UIs cohabiter pendant la transition (cohérence avec 16-4 paused). |

**Conclusion dépendances** : Stories 16.1 et 16.2 sont en `review` non encore `done`. **Démarrage parallèle acceptable** car (a) aucun changement de signature `GpoService` attendu (les stubs sont stables) ; (b) la page détail `/app/gpo/{guid}` est en cohabitation (16-5 enrichit, ne casse pas). **Smoke VM Henri 16.1/16.2/16.3*/16.7 préférable avant merge de 16-5** pour éviter de stratifier les régressions, mais pas bloquant cadrage.

---

## Discrepances ouvertes (à trancher pendant T0 ou dev)

> 4 discrepances à trancher en T0 (investigation) ou en cours de dev (justification dans la story + code review).

| # | Item | Note SM | Tranchement par défaut |
|---|---|---|---|
| **DO1** | **Réordonnancement gpLink — atomique ou best effort ?** | Pour réordonner, il faut `removeLink` ALL + `setLink` séquentiel ordonné. Si une étape échoue au milieu, état AD partiellement corrompu (certains liens supprimés mais pas tous re-créés). | **Par défaut** : best effort + rollback explicite via try/catch (re-setLink avec l'ordre initial mémorisé si échec partiel). Documenter limite dans `docs/tech-debt-gpo.md` (« réordonnancement non atomique — risque résiduel partiellement mitigé »). Si Henri veut atomique strict, story de suivi avec lock LDAP. À trancher **T0.6**. |
| **DO2** | **Source comptage postes par OU** : Eloquent `Workstation` (colonne `ou_dn`) vs LdapRecord `MachineModel` filtré OU | (a) Eloquent : rapide, en SQL, mais dépend que `ou_dn` soit synchronisé (Story 4.x). (b) LdapRecord : source de vérité AD, lent (1 requête LDAP par OU). | **Par défaut** : (a) Eloquent **si** la colonne `ou_dn` existe et est peuplée pour 95%+ des postes (vérifier T0.4). Sinon fallback (b) LdapRecord avec cache 60s. À trancher **T0.4**. |
| **DO3** | **Page liaison dédiée `/app/gpo/{guid}/links` vs encart enrichi dans `/app/gpo/{guid}`** | (a) Page dédiée : UX plus claire, moins de bruit sur le détail, URL propre pour partage admin. (b) Encart enrichi : moins de clics, tout sur la même page. | **Par défaut** : (a) **page dédiée** `/app/gpo/{guid}/links` (URL distincte + breadcrumb retour). L'encart 16.2 reste minimaliste (lecture seule) avec CTA "Gérer les liaisons" → nouvelle page. Cohérent pattern routes `pages/app/gpo/{guid}/links/index.blade.php`. À confirmer **T0.7** (mais pré-tranchable). |
| **DO4** | **Affichage OUs domaine — flat list vs arbre** | (a) Flat list : simple, tri alphabétique, OK si <50 OUs. (b) Arbre hiérarchique : plus visuel, mais complexité UI (lazy loading, expand/collapse). | **Par défaut** : (a) **flat list** triée alphabétiquement avec recherche/filtre, OU `displayName` + DN tooltip. Pattern KISS iso-D5. Si parc avec >50 OUs et UX dégradée, story de suivi pour arbre. À trancher **T0.5**. |

> Hypothèses pré-tranchables = par défaut, en l'absence de contre-indication en T0. Le dev peut basculer si T0 révèle un problème, en documentant en commentaire de code + entrée dans `docs/tech-debt-gpo.md`.

---

## Acceptance Criteria

> 7 volets. Volet 1 = implémentation stubs `GpoService` write. Volet 2 = page liaison `/app/gpo/{guid}/links`. Volet 3 = enrichissement détail `/app/gpo/{guid}`. Volet 4 = catalogue logs + doc. Volet 5 = sécurité. Volet 6 = tests. Volet 7 = encart "création paused".

### Volet 1 — Implémentation `GpoService` write (stubs Story 16.1)

**AC1.1** — **`GpoService::setLink()` implémenté**
**Given** la signature existante `setLink(string $containerDn, string $gpoName, bool $enforce = false, bool $disable = false): bool` (stub Story 16.1 ligne 258)
**When** la méthode est appelée
**Then** elle invoque `SambaToolRunner` mode array : `['gpo', 'setlink', $containerDn, $gpoName]` + `'--enforce'` si `$enforce` + `'--disable'` si `$disable`
**And** retourne `true` si exit code 0, `false` ou exception si exit code 255 (lien déjà existant) selon comportement legacy à investiguer T0.8
**And** logge via `GpoLogger::action('gpo.link.add', context: ['target_dn' => $containerDn, 'gpo_name' => $gpoName, 'enforce' => $enforce, 'disable' => $disable])` avec `step('samba-tool gpo setlink invoked')` et `success()` ou `failure($e)`
**And** valide les inputs : `$containerDn` doit matcher regex `/^[A-Za-z]+=.+/` (basique DN format, pas de shell metachar) ; `$gpoName` doit matcher regex GUID stricte `/^\{[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}\}$/` — sinon `InvalidArgumentException` AVANT side effect.

**AC1.2** — **`GpoService::removeLink()` implémenté**
**Given** la signature existante `removeLink(string $containerDn, string $gpoName): bool` (stub ligne 278)
**When** la méthode est appelée
**Then** elle invoque `SambaToolRunner` mode array : `['gpo', 'dellink', $containerDn, $gpoName]`
**And** retourne `true` si exit code 0 ou 255 (lien déjà absent — idempotence acceptée) — comportement à confirmer T0.8
**And** logge via `GpoLogger::action('gpo.link.remove', context: [...])`
**And** valide les mêmes regex que `setLink` AVANT side effect.

**AC1.3** — **`GpoService::setInheritance()` implémenté**
**Given** la signature existante `setInheritance(string $containerDn, bool $enabled): bool` (stub ligne 296)
**When** la méthode est appelée
**Then** elle invoque `SambaToolRunner` mode array : `['gpo', 'setinheritance', $containerDn, $enabled ? 'inherit' : 'block']`
**And** retourne `true` si exit code 0
**And** logge via `GpoLogger::action('gpo.inheritance.set', context: ['target_dn' => $containerDn, 'enabled' => $enabled])`
**And** **NE REPRODUIT PAS le bug legacy** (`samba-tool.inc.php:1027-1030` : `$message .= " inherit"` au lieu de `$command .= " inherit"` — bug documenté audit Section 6.B). Pattern mode array bypass naturellement le bug.

**AC1.4** — **Nouvelle méthode `GpoService::reorderLinks()`** (D3)
**Given** la nécessité de réordonner la précédence des liens d'une OU
**When** la méthode `public function reorderLinks(string $containerDn, array $orderedGpoNames): bool` est appelée avec un tableau ordonné de GUIDs
**Then** elle (a) lit l'état initial via `getLinks($containerDn)` (sauvegarde mémoire pour rollback), (b) appelle `removeLink($containerDn, $name)` pour chaque lien existant, (c) appelle `setLink($containerDn, $name)` dans l'ordre `$orderedGpoNames`, (d) en cas d'échec à mi-parcours, tente un rollback best effort (re-setLink dans l'ordre initial)
**And** logge via `GpoLogger::action('gpo.link.order.update', context: ['target_dn' => $containerDn, 'order_before' => [...], 'order_after' => [...]])` avec `step` détaillé par étape
**And** retourne `true` si succès complet, `false` si rollback réussi, lève `RuntimeException` si rollback échoué (état AD potentiellement incohérent — documenté).

**AC1.5** — **Architecture & sécurité**
**Given** les nouvelles implémentations
**When** la suite tests architecture s'exécute
**Then** `GpoNamespaceTest` valide que `setLink` / `removeLink` / `setInheritance` / `reorderLinks` n'utilisent **JAMAIS** `exec()` / `shell_exec()` / `Process::fromShellCommandline()` — uniquement `SambaToolRunner` mode array
**And** un nouveau test architecture `it_validates_gpo_write_methods_use_samba_tool_runner` vérifie ce contrat
**And** aucune concat shell string n'est présente dans le code (recherche regex sur le fichier).

### Volet 2 — Page liaison `/app/gpo/{guid}/links` (DO3 — page dédiée)

**AC2.1** — **Route et accessibilité**
**Given** un utilisateur authentifié avec permission `server.admin`
**When** il navigue vers `/app/gpo/{12345678-1234-1234-1234-123456789012}/links`
**Then** la page Livewire SFC `pages::app.gpo.[guid].links.index` (fichier `resources/views/pages/app/gpo/[guid]/links/index.blade.php`) est servie
**And** la route est définie dans `routes/web.php` sous le groupe `Route::prefix('app')->middleware('sambaedu.auth')` avec `->whereGuid` (regex GUID stricte iso 16.2) + `->middleware('can:server.admin')` + `->name('gpo.links')`
**And** `mount(string $guid, GpoService $service)` (boot DI) check permission via `abort_unless(auth()->user()->can('server.admin'))` (defense in depth)
**And** un GUID malformé → 404 (jamais d'appel `samba-tool` avec input invalide).

**AC2.2** — **Affichage des liens existants de la GPO**
**Given** la page mountée avec un GUID valide
**When** la SFC charge `GpoService::get($guid)` puis `GpoService::listContainers($guid)` puis pour chaque container `GpoService::getLinks($dn)` + `GpoService::getInheritance($dn)`
**Then** elle affiche :
- Header : displayName de la GPO + GUID (titre + sous-titre)
- Section "Liens actuels" : tableau ou cards listant chaque OU liée avec : DN, displayName extrait, badge "Hérité"/"Bloqué" (inheritance), pour chaque lien : badge "Activé"/"Désactivé", badge "Forcé"/"Non forcé", position dans l'ordre `gpLink`, comptage postes (DO2)
- Pour chaque lien : 4 boutons d'action : (a) "Désactiver" / "Activer" (toggle), (b) "Forcer" / "Ne plus forcer" (toggle), (c) "↑ Monter" / "↓ Descendre" (réordonner), (d) "Délier" (destructif, en danger)
- Section "Ajouter une nouvelle liaison" : sélecteur OU (flat list DO4) + bouton "Lier"
- Encart "Création GPO" (cf. Volet 7).

**AC2.3** — **Sélecteur OU pour ajout liaison**
**Given** l'admin clique "Ajouter une nouvelle liaison"
**When** la modale s'ouvre
**Then** elle affiche un `<select>` ou `<input list>` (datalist) listant toutes les OUs du domaine via `OrganizationalUnitModel::all()` ou équivalent (cf. T0.5)
**And** chaque option affiche l'OU `displayName` + DN en tooltip ou ligne secondaire
**And** la recherche/filtre textuelle est supportée (champ search côté SFC)
**And** une OU déjà liée à cette GPO est **soit masquée soit grisée** ("Déjà liée") pour éviter doublons
**And** un bouton "Confirmer" qui déclenche `setLink($selectedDn, $guid)` + toast succès + refresh listing.

**AC2.4** — **Action "Délier" avec modale de confirmation** (D6)
**Given** l'admin clique "Délier" sur une OU
**When** la modale `<x-modal>` s'ouvre
**Then** elle affiche le message : "Vous allez délier la GPO «{displayName}» de l'OU «{ouName}» ({ouDn}). Les postes liés à cette OU n'appliqueront plus cette GPO au prochain `gpupdate /force`. Cette action est immédiate. Confirmer ?"
**And** boutons "Annuler" (gris) + "Confirmer la suppression" (rouge `btn-error`)
**And** au clic "Confirmer", appelle `removeLink($dn, $guid)`, émet toast succès `toastSuccess('Liaison supprimée')`, refresh listing
**And** en cas d'exception, toast erreur `toastError('Erreur lors de la suppression : {message}')`, état UI inchangé.

**AC2.5** — **Action "Toggle disabled / enforced"** (D4)
**Given** l'admin clique "Désactiver" sur une liaison active
**When** la modale de confirmation s'ouvre
**Then** elle affiche un message contextualisé ("Désactiver cette liaison la rendra inactive sans la supprimer. Confirmer ?")
**And** au clic "Confirmer", appelle `removeLink` puis `setLink($dn, $guid, $enforce, true)` (set disabled flag) — **OU** investiguer T0.9 si `samba-tool gpo setlink` accepte de modifier un lien existant sans dellink préalable
**And** logge `action_type=gpo.link.toggle.disabled` avec context `from`/`to`
**And** même pattern pour "Forcer/Ne plus forcer" (`gpo.link.toggle.enforced`).

**AC2.6** — **Action "Monter / Descendre" (réordonner)**
**Given** l'admin clique "↑ Monter" sur une liaison
**When** la modale de confirmation s'ouvre
**Then** elle affiche : "Vous allez modifier l'ordre de précédence des liaisons de l'OU «{ouName}». La GPO «{displayName}» passera en position {newPos} (avant : {oldPos}). Confirmer ?"
**And** au clic "Confirmer", appelle `reorderLinks($dn, $newOrder)` avec le tableau réordonné
**And** logge `action_type=gpo.link.order.update`
**And** affiche un loading state pendant l'opération (peut prendre ~2-3s — multiple `samba-tool` calls).

**AC2.7** — **Encart "Inheritance" (héritage de l'OU)**
**Given** la section "Liens actuels" affiche les OUs liées
**When** chaque OU est rendue
**Then** un toggle "Hériter des GPOs parentes" / "Bloquer l'héritage" est affiché (état lu via `getInheritance($dn)`)
**And** au clic, modale confirmation + appel `setInheritance($dn, $enabled)`
**And** logge `action_type=gpo.inheritance.set`.

**AC2.8** — **Breadcrumb retour vers détail GPO**
**Given** la page liaison est affichée
**When** la page est rendue
**Then** un breadcrumb en header : `<a href="/app/gpo/{guid}">← Retour à la GPO «{displayName}»</a>` (pattern iso `<x-molecules.gpo-back-link>` ou similaire de Story 16.3a, mais cohérent ici car URL parent connue)
**And** un breadcrumb supérieur : `← Retour à la liste des GPOs` (vers `/app/gpo`).

**AC2.9** — **État vide légitime**
**Given** la GPO n'est liée à aucune OU
**When** la page est rendue
**Then** un état vide explicite : "Cette GPO n'est liée à aucune OU. Elle ne sera appliquée à aucun poste tant qu'elle n'est pas liée."
**And** le bouton "Ajouter une nouvelle liaison" reste prominent.

**AC2.10** — **Gestion d'erreur**
**Given** une exception lors de la lecture (`get`/`listContainers`/`getLinks`/`getInheritance`) OU lors de l'écriture (`setLink`/`removeLink`/`setInheritance`/`reorderLinks`)
**When** la SFC catche l'exception
**Then** toast erreur explicite `toastError('Erreur : {message}')`
**And** l'opération est rollback côté UI (recharge état AD si possible)
**And** la page reste navigable (les autres données chargées restent affichées).

### Volet 3 — Enrichissement détail `/app/gpo/{guid}` (Story 16.2)

**AC3.1** — **CTA "Gérer les liaisons" dans le header détail**
**Given** la page existante `/app/gpo/{guid}` (Story 16.2)
**When** la page est rendue
**Then** un nouveau bouton primaire `<a href="/app/gpo/{guid}/links" class="btn btn-primary">Gérer les liaisons</a>` est ajouté dans le header de la page (slot `actions` ou équivalent)
**And** ce bouton est positionné AVANT le bouton "Éditer dans l'ancienne UI" (legacy)
**And** sans permission `server.admin`, le bouton n'est pas affiché (gardé par `@can('server.admin')`).

**AC3.2** — **Encart "Impact" (graphe simplifié)** (D5)
**Given** la page détail
**When** la page est mountée
**Then** un nouvel encart "Impact de cette GPO" est affiché (sous les containers existants 16.2)
**And** il liste, pour chaque OU à laquelle la GPO est liée :
- Nom OU + DN tronqué
- Comptage postes (`Workstation::where('ou_dn', $dn)->count()` ou fallback LdapRecord — DO2)
- Badge "Activé/Désactivé" + "Forcé/Non forcé" + position dans l'ordre `gpLink`
**And** un total agrégé "{N} postes potentiellement affectés" en footer de l'encart
**And** un lien "Voir l'impact détaillé" vers la sous-page `/app/gpo/{guid}/links`
**And** si la GPO n'est liée à aucune OU, l'encart affiche : "Cette GPO n'a aucun impact (non liée)" + CTA "Lier maintenant".

**AC3.3** — **Pas de régression Volet 16.2**
**Given** les tests existants `tests/Feature/Gpo/GpoDetailPageTest.php` (Story 16.2)
**When** la suite est exécutée
**Then** tous les tests restent verts
**And** l'enrichissement n'affecte aucun comportement existant (CTAs natifs 16.3a, encart sections natives, bouton legacy, breadcrumb retour).

### Volet 4 — Catalogue logs + documentation

**AC4.1** — **6 nouveaux `action_type` au catalogue Story 16.1**
**Given** le catalogue documenté dans `app/Gpo/README.md` (Story 16.1, 14 entries)
**When** la story 16-5 est mergée
**Then** 6 nouvelles entrées sont ajoutées au catalogue :
- `gpo.link.add` (déjà documenté Story 16.1 mais stub — maintenant implémenté)
- `gpo.link.remove` (idem)
- `gpo.link.order.update` (**nouveau** — réordonnancement)
- `gpo.link.toggle.disabled` (**nouveau**)
- `gpo.link.toggle.enforced` (**nouveau**)
- `gpo.inheritance.set` (déjà documenté mais stub — maintenant implémenté)

**And** chaque entrée a une description, un context attendu, et la story qui l'a introduite.

**AC4.2** — **Documentation `docs/qa/domains/gpo.md` (section 7 Story 16-5)**
**Given** le runbook QA enrichi par les stories précédentes (sections 1-6)
**When** la story 16-5 est mergée
**Then** une nouvelle section 7 "Story 16.5 — Liaison GPO ↔ OU / parc + propagation" est ajoutée avec scénarios QA manuels VM :
1. Naviguer vers `/app/gpo/{guid}/links` → liaisons existantes affichées
2. Ajouter une liaison vers une OU de test → toast succès + apparition dans la liste
3. Vérifier sur poste de test (`gpupdate /force` puis `gpresult /r`) → GPO appliquée
4. Désactiver la liaison → toast succès + badge "Désactivé" + poste ne reçoit plus la GPO après `gpupdate`
5. Réordonner deux liaisons → ordre `gpLink` modifié sur l'OU AD (vérifier via `samba-tool gpo getlink {ou_dn}`)
6. Délier la GPO → toast succès + disparition + poste ne reçoit plus la GPO
7. Toggle "Bloquer l'héritage" sur une OU → propagation OK (les GPOs parentes ne s'appliquent plus à cette OU)
8. Tester avec utilisateur sans `server.admin` → 403 sur `/app/gpo/{guid}/links`
9. Tester un input malformé (GUID invalide) → 404 sans appel `samba-tool`
10. Vérifier les logs `storage/logs/gpo/gpo-{date}.log` → `gpo.link.add`, `gpo.link.remove`, `gpo.link.order.update`, etc. présents avec context complet.

**AC4.3** — **Documentation `app/Gpo/README.md`**
**Given** le README enrichi par les stories précédentes
**When** la story 16-5 est mergée
**Then** une nouvelle section "Story 16.5 — Liaison GPO" est ajoutée avec :
- Tableau des 4 méthodes write `GpoService` (setLink / removeLink / setInheritance / reorderLinks) — signature + contrat + action_type loggé
- Catalogue enrichi (6 nouveaux action_type)
- Référence à la page UI `/app/gpo/{guid}/links`
- Note sur le non-atomicity de `reorderLinks` (DO1).

**AC4.4** — **`docs/tech-debt-gpo.md`** (1-3 entrées)
**Given** les éventuelles dettes identifiées
**When** la story est mergée
**Then** au minimum 1 entrée documentée :
- **TD-16.5-1** : `reorderLinks` non atomique — risque résiduel si crash mi-parcours (DO1) — mitigé par rollback best effort, pas par lock LDAP. Story de suivi possible si besoin opérationnel.

Et éventuellement (si DO2 et DO4 ouvrent des dettes) :
- **TD-16.5-2** : Comptage postes via Eloquent vs LdapRecord — choix selon synchronisation `ou_dn` (DO2).
- **TD-16.5-3** : Flat list OUs domaine (pas d'arbre hiérarchique) — UX dégradée si parc avec >50 OUs (DO4).

### Volet 5 — Sécurité

**AC5.1** — **Validation input GUID / DN**
**Given** les méthodes write `GpoService::setLink/removeLink/setInheritance/reorderLinks`
**When** un input invalide est fourni (GUID malformé, DN avec metachar shell, GUID hors format strict)
**Then** une `InvalidArgumentException` est levée **AVANT** tout appel `SambaToolRunner`
**And** un test `it_rejects_malformed_guid_before_side_effect` (mock `SambaToolRunner::shouldNotReceive('run')`) valide ce contrat.

**AC5.2** — **Permission `server.admin` requise (UI + middleware route)**
**Given** un utilisateur sans permission `server.admin`
**When** il tente d'accéder à `/app/gpo/{guid}/links` (GET) ou de déclencher une action write (Livewire POST)
**Then** la route renvoie 403 (middleware `can:server.admin`)
**And** la SFC `mount()` re-check `abort_unless(auth()->user()->can('server.admin'))` (defense in depth)
**And** un test `tests/Feature/Gpo/GpoLinksPagePermissionTest.php` valide les deux niveaux de garde.

**AC5.3** — **Pas de catchall override d'URL legacy**
**Given** les routes legacy `gpo/gpo-maj.php` (import) et `gpo/gestion_gpo.php` (déjà bloqué par 16.2)
**When** la story 16-5 est mergée
**Then** **aucune nouvelle entrée** n'est ajoutée à `config('sambaedu.blocked_legacy_routes')` (cohérence Story 16-4 paused : la création/import reste dans le shim).

### Volet 6 — Tests

**AC6.1** — **Tests unit `GpoService` write** (≥12 tests)
**Given** la suite Unit
**When** elle exécute `tests/Unit/Gpo/GpoServiceWriteTest.php`
**Then** au moins 12 tests sont couverts :
1. `setLink` succès → samba-tool gpo setlink invoqué avec args corrects
2. `setLink` enforce → flag `--enforce` présent
3. `setLink` disable → flag `--disable` présent
4. `setLink` exit 255 → comportement attendu (cf. T0.8)
5. `setLink` GUID malformé → InvalidArgumentException + shouldNotReceive
6. `setLink` DN malformé → idem
7. `removeLink` succès
8. `removeLink` idempotence (exit 255 toléré)
9. `setInheritance` enabled → `inherit`
10. `setInheritance` disabled → `block`
11. `reorderLinks` succès complet
12. `reorderLinks` rollback partiel → état initial restauré

**And** tous les tests mockent `SambaToolRunner` via `Process::fake()` Laravel
**And** chaque test vérifie le logging via `GpoLogger` (action_type + context + outcome).

**AC6.2** — **Tests Feature page `/app/gpo/{guid}/links`** (≥10 tests)
**Given** la suite Feature
**When** elle exécute `tests/Feature/Gpo/GpoLinksPageTest.php`
**Then** au moins 10 tests :
1. Page charge avec permission
2. Page 403 sans permission
3. Page 404 GUID malformé (jamais d'appel samba-tool)
4. Affichage liens existants OK
5. Ajout liaison → modale → confirmation → setLink appelé → toast succès
6. Suppression liaison → modale → confirmation → removeLink appelé → toast succès
7. Toggle disabled → setLink avec flag disable → toast succès
8. Toggle enforced → setLink avec flag enforce → toast succès
9. Réordonner ↑ → reorderLinks appelé → toast succès
10. Toggle inheritance → setInheritance appelé → toast succès

**And** tous les tests mockent `GpoService` via container binding (pattern Story 16.2 `FakesGpoService`).

**AC6.3** — **Test Feature enrichissement détail** (≥3 tests)
**Given** la suite Feature
**When** elle exécute des tests dans `GpoDetailPageTest.php` (extension Story 16.2)
**Then** au moins 3 nouveaux tests :
1. CTA "Gérer les liaisons" affiché en header
2. Encart "Impact" affiché avec liste OUs + comptage postes
3. Encart "Impact" affiche état vide si GPO non liée.

**AC6.4** — **Test architecture write methods**
**Given** la suite Architecture
**When** elle exécute `tests/Architecture/GpoNamespaceTest`
**Then** un nouveau test `it_validates_gpo_write_methods_use_samba_tool_runner` valide qu'aucune méthode `GpoService` (set/remove/setInheritance/reorderLinks) n'utilise `exec`/`shell_exec`/`Process::fromShellCommandline` directement.

**AC6.5** — **Pas de régression baseline**
**Given** la suite globale `php artisan test`
**When** la story 16-5 est mergée
**Then** la suite reste verte (ou égale au baseline pre-merge, modulo les pré-existants Mockery final non liés à 16-5)
**And** aucun nouveau test 16.1/16.2/16.3*/16.7 ne casse.

### Volet 7 — Encart "Création GPO paused"

**AC7.1** — **Encart explicite "Pour créer une GPO..."**
**Given** la page `/app/gpo/{guid}/links` est rendue
**When** la page est mountée
**Then** un encart `<x-molecules.alert>` info (ou DaisyUI `alert alert-info` manuel) est affiché en pied de page :
"Vous souhaitez créer, dupliquer ou supprimer une GPO ? Cette fonctionnalité reste disponible dans l'ancienne interface. → [Ouvrir dans l'ancienne UI]"
**And** le lien pointe vers `/gpo/gpo-maj.php` (shim 1bis-18) avec `target="_blank"` + `rel="noopener noreferrer"`
**And** un sous-texte : "La création de GPOs sera portée nativement dans une story future (16-4 actuellement en pause)."

**AC7.2** — **Idem sur la page listing `/app/gpo`** (encart léger)
**Given** la page existante `/app/gpo` (Story 16.2)
**When** la page est rendue
**Then** un encart léger ou un bouton secondaire "Créer une GPO (ancienne UI)" est ajouté **discrètement** (pas en CTA primaire — éviter de pousser vers le legacy)
**And** ce bouton n'apparaît qu'avec permission `server.admin`.

---

## Hors-scope (explicite)

- **CRUD GPO native** (création, duplication, suppression) — Story 16-4 paused. Reste dans le shim.
- **Liaison à `WorkstationGroup`** (table SQL) — D1, story de suivi 16.5b éventuelle.
- **Arbre hiérarchique des OUs** — DO4 par défaut flat list, story de suivi possible.
- **Lock LDAP atomique pour reorderLinks** — DO1, rollback best effort uniquement.
- **Import/export GPO** — Story 16-4 paused.
- **Catchall override d'URLs legacy** — pas dans cette story (cohabitation 16-4 paused).
- **Tests E2E navigateur** (Playwright/Cypress) — pas d'infra projet, iso 16.2 / 16.3*.
- **Migration cache APCu → Cache Laravel** — Story 16-8 backlog (hors scope 16-5).
- **Affichage graphique impact (D3.js, Mermaid)** — D5 KISS, arbre HTML simple uniquement.
- **Audit avancé / Rollback historique** — pas dans cette story. Les logs `gpo` channel suffisent pour traçabilité.
- **Notifications cross-utilisateur (broadcast Pusher/Reverb)** — hors scope ; la page reload est manuelle.

---

## Tasks / Subtasks

### Phase T0 — Cadrage & vérifications préalables

- [x] **T0.1** Confirmer que Stories 16.1 / 16.2 / 16.3a / 16.3b / 16.3c / 16.7 sont en `review` (smoke VM Henri éventuel mais non bloquant). Démarrage parallèle acceptable.
- [x] **T0.2** Lire `app/Gpo/Services/GpoService.php` lignes 258-307 (signatures stubs setLink/removeLink/setInheritance) — confirmer qu'aucun changement de signature n'est nécessaire.
- [x] **T0.3** Lire `app/Gpo/Support/SambaToolRunner.php` — vérifier le pattern mode array et la méthode publique `run(array $args): ProcessResult` (ou équivalent).
- [x] **T0.4** **DO2** — Vérifier la colonne `ou_dn` (ou équivalent) sur `app/Models/Workstation.php`. Si présente et peuplée, utiliser Eloquent. Sinon fallback LdapRecord `MachineModel` avec filtre OU + cache 60s.
- [x] **T0.5** **DO4** — Vérifier `app/LdapModels/OrganizationalUnitModel.php` méthodes disponibles pour lister toutes les OUs du domaine. Si trop lent, paginer ou mettre en cache 5min.
- [x] **T0.6** **DO1** — Décider du fallback rollback best effort pour `reorderLinks`. Documenter limite résiduelle.
- [x] **T0.7** **DO3** — Confirmer page dédiée `/app/gpo/{guid}/links` (vs encart enrichi). Lire `resources/views/pages/app/gpo/[guid]/index.blade.php` pour comprendre la structure actuelle et où insérer le CTA "Gérer les liaisons" + encart Impact.
- [x] **T0.8** Investiguer le comportement legacy `samba-tool gpo setlink` quand un lien existe déjà (exit 255 ?) et `dellink` quand un lien est absent (exit 255 ?). Décider idempotence vs erreur — préférer idempotence (return `true` dans les deux cas, log warning).
- [x] **T0.9** Investiguer si `samba-tool gpo setlink` peut **modifier** un lien existant (changer enforce/disable) ou s'il faut systématiquement `dellink` + `setlink`. Si modification directe possible, simplifier `AC2.5`.
- [x] **T0.10** Vérifier l'existence du composant `<x-modal>` (CLAUDE.md) et son API (slot trigger, slot content, dispatch event). Référence `wallpapers/index.blade.php` pour pattern.
- [x] **T0.11** Lire `tests/Support/FakesGpoService.php` (builder fluide enrichi 16.2) pour comprendre comment l'étendre avec `withSetLinkResult`, `withRemoveLinkResult`, `withSetInheritanceResult`, `withReorderLinksResult`.
- [x] **T0.12** Lire `tests/Concerns/BootstrapsSpatieTables.php` (Story 16.2 fix #11) pour tests permission.

### Phase T1 — Implémentation `GpoService` write (Volet 1)

- [x] **T1.1** Implémenter `GpoService::setLink()` (AC1.1) :
  - Validation regex GUID + DN AVANT side effect
  - Construction args `SambaToolRunner` mode array
  - Logging `GpoLogger::action('gpo.link.add', context: [...])` avec step + success/failure
  - Gestion exit 255 selon T0.8
- [x] **T1.2** Implémenter `GpoService::removeLink()` (AC1.2) — pattern identique
- [x] **T1.3** Implémenter `GpoService::setInheritance()` (AC1.3) — attention au bug legacy à NE PAS reproduire
- [x] **T1.4** Ajouter `GpoService::reorderLinks()` (AC1.4) :
  - Lecture état initial via `getLinks($containerDn)` (mémorisation pour rollback)
  - Boucle `removeLink` puis `setLink` ordonné
  - Rollback best effort try/catch
  - Logging détaillé par étape (step `removing existing`, `adding in order`, `rollback initiated`)
- [x] **T1.5** Ajouter test architecture `it_validates_gpo_write_methods_use_samba_tool_runner` dans `tests/Architecture/GpoNamespaceTest.php` (AC1.5 / AC6.4)
- [x] **T1.6** Étendre `tests/Support/FakesGpoService.php` avec 4 nouveaux builders : `withSetLinkResult`, `withRemoveLinkResult`, `withSetInheritanceResult`, `withReorderLinksResult` + tracking des appels (pour assertions).

### Phase T2 — Tests Unit GpoService write (Volet 6)

- [x] **T2.1** Créer `tests/Unit/Gpo/GpoServiceWriteTest.php` (AC6.1) — ≥12 tests :
  - 6 tests `setLink` (succès, enforce, disable, exit 255, GUID malformé, DN malformé)
  - 2 tests `removeLink` (succès, idempotence)
  - 2 tests `setInheritance` (enabled, disabled)
  - 2 tests `reorderLinks` (succès, rollback partiel)
- [x] **T2.2** Mocker `SambaToolRunner` via `Process::fake()` (pattern AdMachineManagerTest Story 16.7)
- [x] **T2.3** Pour les tests de validation (GUID/DN malformé), utiliser `Process::fake()->shouldNotReceive(...)` ou équivalent pour prouver l'absence de side effect

### Phase T3 — Page liaison `/app/gpo/{guid}/links` (Volet 2)

- [x] **T3.1** Créer le dossier `resources/views/pages/app/gpo/[guid]/links/`
- [x] **T3.2** Créer `resources/views/pages/app/gpo/[guid]/links/index.blade.php` — SFC Livewire avec :
  - `use WithToasts`
  - `#[Title('Liaisons GPO - SE4FS')]`
  - `mount(string $guid, GpoService $service)` + abort_unless permission
  - Properties : `$guid`, `$gpo` (?GpoSummary), `$containers` (array<string>), `$linksByContainer` (array<string, list<GpoLink>>), `$inheritanceByContainer`, `$availableOus` (collection LdapRecord), `$workstationCountByOu`, `$pendingAction` (pour modale)
  - Methods : `loadAll()`, `addLink($ouDn)`, `removeLink($ouDn)`, `toggleDisabled($ouDn, $newState)`, `toggleEnforced($ouDn, $newState)`, `moveUp($ouDn, $position)`, `moveDown($ouDn, $position)`, `toggleInheritance($ouDn, $newState)`, `openConfirmation($action, $params)` (AC2.1-2.10)
- [x] **T3.3** Templating Blade : header + breadcrumb, section "Liens actuels", boutons d'action + modales `<x-modal>`, sélecteur OU dans modale ajout, encart "Création GPO" (Volet 7)
- [x] **T3.4** Ajouter la route dans `routes/web.php` (groupe `Route::prefix('app')` existant) :
  ```php
  Route::livewire('/gpo/{guid}/links', 'pages::app.gpo.[guid].links.index')
      ->where('guid', '\{?[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}\}?')
      ->middleware('can:server.admin')
      ->name('gpo.links');
  ```
  (cohérent regex tolérante de Story 16.2 fix #9)

### Phase T4 — Enrichissement détail `/app/gpo/{guid}` (Volet 3)

- [x] **T4.1** Modifier `resources/views/pages/app/gpo/[guid]/index.blade.php` (Story 16.2) :
  - Ajouter CTA "Gérer les liaisons" en header (AC3.1) — gardé par `@can('server.admin')`
  - Ajouter encart "Impact" (AC3.2) avec liste OUs + comptage postes (DO2) + total agrégé + lien "Voir l'impact détaillé"
- [x] **T4.2** Service de comptage : si DO2 = Eloquent, utiliser `Workstation::where('ou_dn', $dn)->count()` ; sinon LdapRecord avec cache `Cache::remember('gpo:5:wks-count:'.md5($dn), 60, fn() => ...)`.
- [x] **T4.3** Tests Feature enrichissement détail (AC6.3) — ≥3 tests dans `GpoDetailPageTest.php` (extension)

### Phase T5 — Tests Feature page liaison (Volet 6)

- [x] **T5.1** Créer `tests/Feature/Gpo/GpoLinksPageTest.php` (AC6.2) — ≥10 tests :
  - Permission OK / KO
  - GUID malformé 404
  - Affichage liens existants
  - Ajout / suppression / toggle disabled / toggle enforced / réordonner / toggle inheritance
- [x] **T5.2** Créer `tests/Feature/Gpo/GpoLinksPagePermissionTest.php` (AC5.2) — 4 tests : 200 avec perm, 403 sans perm, GET vs Livewire actions
- [x] **T5.3** Utiliser `FakesGpoService` (T1.6) + container binding pour mocker `GpoService`
- [x] **T5.4** Utiliser `BootstrapsSpatieTables` trait pour bootstrap tables permissions

### Phase T6 — Sécurité (Volet 5)

- [x] **T6.1** Tests de sécurité : `it_rejects_malformed_guid_before_side_effect` (mock `shouldNotReceive` sur SambaToolRunner) — déjà dans T2.1
- [x] **T6.2** Vérifier que `mount()` re-check permission (defense in depth) + middleware route
- [x] **T6.3** Vérifier qu'aucune entrée n'est ajoutée à `blocked_legacy_routes` (cohabitation 16-4 paused)

### Phase T7 — Documentation (Volet 4)

- [x] **T7.1** Mettre à jour `app/Gpo/README.md` (AC4.3) — section "Story 16.5 — Liaison GPO" avec tableau 4 méthodes + catalogue enrichi + note non-atomicity
- [x] **T7.2** Mettre à jour `docs/qa/domains/gpo.md` (AC4.2) — section 7 avec 10 scénarios QA manuels VM
- [x] **T7.3** Mettre à jour `docs/tech-debt-gpo.md` (AC4.4) — au minimum TD-16.5-1 (reorderLinks non atomique) + éventuellement TD-16.5-2 / TD-16.5-3
- [x] **T7.4** Mettre à jour `_bmad-output/implementation-artifacts/sprint-status.yaml` : `16-5-liaison-gpo-ou-parc-propagation: ready-for-dev` → `review` (le dev met à jour à la fin)

### Phase T8 — Smoke tests VM (action Henri)

- [ ] **T8.1** **(Henri)** Lancer `php artisan test tests/Feature/Gpo` + `tests/Unit/Gpo` sur la VM (branche `gpo`) → 100% vert
- [ ] **T8.2** **(Henri)** Smoke VM : naviguer `/app/gpo/{guid}/links` sur une GPO de test
- [ ] **T8.3** **(Henri)** Ajouter une liaison vers une OU de test → vérifier `samba-tool gpo getlink {ou_dn}` sur la VM
- [ ] **T8.4** **(Henri)** Sur un poste Windows de test, lancer `gpupdate /force` puis `gpresult /r` → GPO appliquée
- [ ] **T8.5** **(Henri)** Désactiver la liaison → `gpupdate` → GPO non appliquée
- [ ] **T8.6** **(Henri)** Réordonner deux liaisons → vérifier ordre dans `gpLink` sur OU AD
- [ ] **T8.7** **(Henri)** Délier → `gpupdate` → poste ne reçoit plus la GPO
- [ ] **T8.8** **(Henri)** Toggle "Bloquer l'héritage" sur une OU → propagation OK
- [ ] **T8.9** **(Henri)** Vérifier logs `storage/logs/gpo/gpo-{date}.log` : `gpo.link.add`, `gpo.link.remove`, `gpo.link.order.update`, `gpo.inheritance.set`, `gpo.link.toggle.disabled`, `gpo.link.toggle.enforced` présents avec context complet
- [ ] **T8.10** **(Henri)** Suite globale `php artisan test` sur VM — 0 régression vs baseline

---

## File List prévisionnelle

### Fichiers créés

```
resources/views/pages/app/gpo/[guid]/links/index.blade.php       ← SFC liaison (AC Volet 2)

tests/Unit/Gpo/GpoServiceWriteTest.php                            ← AC6.1 (≥12 tests)
tests/Feature/Gpo/GpoLinksPageTest.php                            ← AC6.2 (≥10 tests)
tests/Feature/Gpo/GpoLinksPagePermissionTest.php                  ← AC5.2 (4 tests)
```

### Fichiers modifiés

```
app/Gpo/Services/GpoService.php                                   ← Implémentation 3 stubs + nouvelle reorderLinks
app/Gpo/README.md                                                 ← Section Story 16.5 + catalogue action_type enrichi
routes/web.php                                                    ← +1 route /app/gpo/{guid}/links
resources/views/pages/app/gpo/[guid]/index.blade.php              ← CTA "Gérer les liaisons" + encart Impact
tests/Architecture/GpoNamespaceTest.php                           ← +1 test write methods → SambaToolRunner
tests/Support/FakesGpoService.php                                 ← +4 builders (withSetLinkResult / withRemoveLinkResult / ...)
tests/Feature/Gpo/GpoDetailPageTest.php                           ← +≥3 tests enrichissement détail (AC6.3)
docs/qa/domains/gpo.md                                            ← +Section 7 Story 16.5 (10 scénarios VM)
docs/tech-debt-gpo.md                                             ← +1-3 entrées TD-16.5-*
_bmad-output/implementation-artifacts/sprint-status.yaml          ← status backlog → ready-for-dev (SM) puis review (dev)
```

### Fichiers NON touchés (régression à éviter)

```
app/Gpo/Support/SambaToolRunner.php                               ← Pas de modification (pattern mode array déjà OK)
app/Gpo/Support/GpoLogger.php                                     ← Pas de modification (signature stable)
app/Gpo/Dto/GpoLink.php                                           ← Pas de modification (DTO lecture, déjà OK pour 16-5)
app/Gpo/Dto/GpoSummary.php                                        ← Idem
app/Gpo/Support/NativeSectionResolver.php                         ← Pas de modification (16-5 ne touche pas aux sections)
legacy/modules/gpo/*                                              ← Aucune suppression (D5 / cohabitation 16-4 paused)
config/sambaedu.php                                               ← Pas d'ajout à blocked_legacy_routes (AC5.3)
```

---

## Test Strategy

### Couverture par niveau

| Niveau | Périmètre | Fichier(s) |
|---|---|---|
| **Unit** | `GpoService` write methods (setLink / removeLink / setInheritance / reorderLinks) | `tests/Unit/Gpo/GpoServiceWriteTest.php` |
| **Feature** | Page `/app/gpo/{guid}/links` (rendu, modales, actions write, gestion erreur) | `tests/Feature/Gpo/GpoLinksPageTest.php` |
| **Feature** | Permission `server.admin` requise | `tests/Feature/Gpo/GpoLinksPagePermissionTest.php` |
| **Feature** | Enrichissement détail `/app/gpo/{guid}` (CTA + encart Impact) | `tests/Feature/Gpo/GpoDetailPageTest.php` (extension Story 16.2) |
| **Architecture** | Methods write → SambaToolRunner exclusivement | `tests/Architecture/GpoNamespaceTest.php` (extension Story 16.1) |
| **Smoke VM (manuel)** | Henri action — vrai parc AD, vrai poste Windows, `gpupdate`, vérif logs | (post-merge) |

### Stratégie de mock

- **`SambaToolRunner`** : via `Process::fake()` Laravel + assertions sur la commande invoquée + le mode array exclusif (pattern Story 16.7 `AdMachineManagerTest`).
- **`GpoService` côté Feature** : container binding (`$this->app->bind(GpoService::class, fn() => $fake)`) — pattern Story 16.2 (étendu via `FakesGpoService` builder fluide).
- **`OrganizationalUnitModel`** : mocker via factory ou stub (T0.5 confirmera la méthode `all()` ou équivalent).
- **`Workstation`** (Eloquent) : factory standard.
- **Anti-pattern à éviter** : appeler `samba-tool` réellement dans les tests Feature (perte temps + dépendance VM). Toujours mocker au niveau `SambaToolRunner` ou `GpoService`.

### Tests à NE PAS faire dans cette story

- Tests E2E navigateur (Playwright/Cypress) — pas d'infra projet.
- Tests de performance (latence réordonnancement N liens) — pas d'enjeu (cas rare, latence acceptée ~2-3s).
- Tests de race condition multi-admin (deux admins réordonnent simultanément) — hors scope, action rare admin.
- Tests d'audit historique (qui a changé quoi quand) — les logs `gpo` channel suffisent, pas de UI dédiée.

---

## Dev Notes — Contraintes & décisions cadrage SM

### Décisions SM rappelées (cf. section décisions D1-D10 ci-dessus)

| # | Décision | Impact dev |
|---|---|---|
| D1 | Cible = OU AD uniquement (pas WorkstationGroup) | Sélecteur OU lit `OrganizationalUnitModel` LdapRecord, pas Eloquent `WorkstationGroup` |
| D2 | Écriture via `samba-tool gpo setlink` exclusivement | Pas de LdapRecord::update direct sur attribut gpLink |
| D3 | Précédence gérée via réordonnancement explicite (boutons ↑/↓) | Nouvelle méthode `reorderLinks` non atomique avec rollback best effort |
| D4 | Désactivation + suppression exposées (flags --disable / --enforce) | 4 actions UI par lien : toggle disabled / toggle enforced / réordonner / délier |
| D5 | Graphe d'impact = arbre 2 niveaux + comptage postes (KISS) | Encart HTML simple sur détail GPO, pas de viz graphique |
| D6 | Modales de confirmation obligatoires sur write AD | Composant `<x-modal>` réutilisable (CLAUDE.md) |
| D7 | Channel `gpo` exclusif (write AD audit) — pas `daily` | `GpoLogger::action` sur 6 action_type |
| D8 | Iso-bytes non applicable | Pas de fixture comparison |
| D9 | Tests : Unit GpoService + Feature Livewire + smoke VM | ≥12 + ≥10 + ≥3 tests minimum |
| D10 | Frontière Epic 17 stricte | 16-5 ne touche pas aux scripts Windows |

### Références codebase pour le dev

- **Service GPO** : `app/Gpo/Services/GpoService.php:258-307` — stubs à implémenter
- **SambaToolRunner** : `app/Gpo/Support/SambaToolRunner.php` — mode array (confirmer API exacte T0.3)
- **GpoLogger** : `app/Gpo/Support/GpoLogger.php` — `GpoLogger::action($actionType, context: [...])->step($s)->success()`/`->failure($e)`
- **GpoActionLog** : `app/Gpo/Support/GpoActionLog.php` — DTO log structuré
- **DTOs** : `app/Gpo/Dto/{GpoSummary,GpoLink}.php` — GpoLink contient `enforced` et `disabled` (à vérifier T0.5 si non, étendre)
- **OrganizationalUnitModel** : `app/LdapModels/OrganizationalUnitModel.php` — méthodes statiques `findByDn`, `exists`, `createOU`, `extractOuNameFromDn`, `extractParentDn`. Pour lister TOUTES les OUs, vérifier la méthode (sinon `OrganizationalUnitModel::query()->get()` LdapRecord)
- **Workstation Eloquent** : `app/Models/Workstation.php` — vérifier colonne `ou_dn` (DO2 T0.4)
- **Permission Spatie** : `app/Enums/SambaPermission.php:58` — `ServerAdmin = 'server.admin'`
- **Page détail référence** : `resources/views/pages/app/gpo/[guid]/index.blade.php` (Story 16.2) — pattern Livewire SFC + breadcrumb
- **Page wallpapers référence write + modale** : `resources/views/pages/parc-settings/wallpapers/index.blade.php` (Story 4.7) — `<x-modal>` + actions destructives + `WithToasts`
- **Trait WithToasts** : `app/Components/Traits/WithToasts.php` — `toastSuccess` / `toastError` / `toastWarning`
- **Composant modale** : `<x-modal>` réutilisable (CLAUDE.md) — vérifier l'API exacte T0.10
- **Route prefix existant** : `routes/web.php` (groupe `Route::prefix('app')->middleware('sambaedu.auth')`) — étendre
- **Helper test FakesGpoService** : `tests/Support/FakesGpoService.php` — étendre avec 4 builders write (T1.6)
- **Trait BootstrapsSpatieTables** : `tests/Concerns/BootstrapsSpatieTables.php` — bootstrap tables permissions tests Feature
- **Test architecture** : `tests/Architecture/GpoNamespaceTest.php` — pattern pour AC1.5 / AC6.4
- **Channel logs `gpo`** : déjà configuré Story 16.1 — `storage/logs/gpo/gpo-{date}.log`

### Pièges identifiés

1. **Bug legacy `samba-tool.inc.php:1027-1030` `gposetinheritance`** : `$message .= " inherit"` au lieu de `$command .= " inherit"`. À **NE PAS** reproduire. Mode array Story 16.7 (AdMachineManager) bypass naturellement le bug. Mentionner en commentaire de code dans `setInheritance`.

2. **Format `gpLink` string ordonné** : `[LDAP://CN={GUID},...;0][LDAP://CN={GUID},...;1]`. Le `0` est l'état (0=normal, 1=enforced, 2=disabled, 3=both). **`samba-tool gpo setlink` gère la concat ordonnée** — ne pas reproduire à la main.

3. **`samba-tool gpo setlink` sur un lien existant** : comportement à investiguer T0.9. Si exit 255, peut nécessiter `dellink` + `setlink`. Si exit 0 (mise à jour des flags), simplifier toggle disabled/enforced.

4. **Réordonnancement non atomique** : `reorderLinks` = N appels `samba-tool`. Si crash mi-parcours, état AD potentiellement inconsistant. Rollback best effort (re-setLink avec ordre initial). Documenter limite TD-16.5-1.

5. **Latence `samba-tool`** : 1-3s par appel. Pour `reorderLinks` avec 5 liens, ~10-15s. **UI loading state obligatoire** (`wire:loading.attr="disabled"` + spinner).

6. **Format GUID accolades** : `{XXXXXXXX-...}`. Iso Story 16.2 (`normalizeGuid()` strip+rajoute les accolades pour samba-tool). Réutiliser le pattern T0.7.

7. **Validation DN format** : regex basique `/^[A-Za-z]+=.+/` (commence par `OU=`, `CN=`, `DC=`). Suffisant pour bloquer les shell metachar. SambaToolRunner mode array bloque de toute façon.

8. **Permission `server.admin`** : `middleware('can:server.admin')` + `mount()` re-check (defense in depth iso 16.2).

9. **Modale composant** : `<x-modal>` API à confirmer T0.10. Pattern wallpaper utilise probablement `wire:click="openConfirmation('delete', $id)"` puis `@if($pendingAction === 'delete') ... @endif` côté SFC. Sinon, Alpine.js `x-data="{ open: false }"`. À adapter selon convention projet.

10. **Comptage postes par OU** : DO2 à trancher T0.4. Si Eloquent `Workstation::where('ou_dn', $dn)` non disponible, fallback LdapRecord avec cache 60s pour éviter de bombarder l'AD.

11. **Liste OUs domaine** : DO4 = flat list. Si `OrganizationalUnitModel::query()->get()` retourne >500 OUs, mettre en cache (`Cache::remember('gpo:5:ous', 300, fn() => ...)`). Vérifier T0.5.

12. **`Route::livewire()` vs `Volt::route()`** : convention projet = `Route::livewire()` (cf. Story 16.2 fix #2). Pas de Volt class component, SFC inline new class.

13. **`server.admin` permission Spatie** : `auth()->user()->can('server.admin')` ou `Gate::allows('server.admin')`. Vérifier pattern existant 16.2 (probablement `can('server.admin')` direct).

14. **Encart "Création paused" (Volet 7)** : ne pas pousser visuellement vers le legacy. Texte explicite + lien `target=_blank`. Pattern iso 16.2 bouton "Éditer dans l'ancienne UI".

---

## Project Structure Notes

### Alignement structure projet

- **Page Livewire** : `resources/views/pages/app/gpo/[guid]/links/index.blade.php` (filesystem-based router CLAUDE.md)
- **Route name** : `gpo.links` (préfixe `app.` du groupe → `app.gpo.links`)
- **Tests** : `tests/Unit/Gpo/` + `tests/Feature/Gpo/` (dossiers déjà créés en 16.1)
- **Service** : `app/Gpo/Services/GpoService.php` — extension du fichier existant (pas de nouveau service)
- **Pas de nouveau namespace** — tout reste dans `App\Gpo\*`

### Conflits / variances détectés

| Élément | Doc/standard | Décision Story 16-5 | Justification |
|---|---|---|---|
| Liaison `WorkstationGroup` | epics.md ligne 3342 "OU AD **ou** WorkstationGroup" | OU AD uniquement | D1 — première itération parité legacy stricte ; WorkstationGroup story de suivi |
| Graphe d'impact | epics.md "Affichage d'un graphe de liaison" | Arbre HTML 2 niveaux + comptage postes | D5 KISS — pas de viz graphique lourde |
| Page dédiée vs encart | Discrepance ouverte SM | Page dédiée `/app/gpo/{guid}/links` (DO3) | UX claire + URL propre + cohérence pattern routes |
| Création GPO | epics.md "création/duplication/suppression" (Story 16.4) | **Paused** — encart redirige vers shim | Décision Henri 2026-05-13 |

---

## References

- `_bmad-output/planning-artifacts/epics.md:3340-3343` — Story 16.5 cadrage haut niveau
- `_bmad-output/planning-artifacts/audit-gpo-legacy.md:444-449` — wrappers `samba-tool gpo setlink/dellink/setinheritance` + bug 1027-1030
- `_bmad-output/planning-artifacts/audit-gpo-legacy.md:662-669` — Section 6.G recommandation Story 16.5
- `_bmad-output/planning-artifacts/architecture.md:343-353` — règle "3 couches" + jamais Eloquent direct dans Livewire
- `_bmad-output/implementation-artifacts/16-1-fondations-gpo-natives-audit-legacy.md` — fondations posées (GpoService, SambaToolRunner, GpoLogger, catalogue action_type)
- `_bmad-output/implementation-artifacts/16-2-listing-lecture-gpo-ui-native.md` — pattern UI listing/détail SFC Livewire + permission + FakesGpoService + BootstrapsSpatieTables
- `_bmad-output/implementation-artifacts/16-3a-liens-profonds-sections-natives.md` — pattern NativeSectionResolver + composant breadcrumb réutilisable
- `_bmad-output/implementation-artifacts/16-3b-network-veyon.md` — pattern Controller iso-bytes (référence pattern, pas appliqué ici car UI admin)
- `_bmad-output/implementation-artifacts/16-7-portage-natif-applications-php.md` — pattern `App\Ldap\AdMachineManager` natif AD writeback via SambaToolRunner mode array + Process::fake() pour tests Unit (référence forte pour le pattern setLink/removeLink/setInheritance)
- `app/Gpo/Services/GpoService.php:258-307` — stubs setLink / removeLink / setInheritance à implémenter
- `app/Gpo/Support/SambaToolRunner.php` — wrapper mode array
- `app/Gpo/Support/GpoLogger.php` + `GpoActionLog.php` — logging structuré
- `app/Gpo/Dto/{GpoSummary,GpoLink}.php` — DTOs lecture
- `app/LdapModels/OrganizationalUnitModel.php` — Model LdapRecord OUs
- `app/Models/Workstation.php` — Eloquent postes (DO2 colonne `ou_dn` T0.4)
- `app/Enums/SambaPermission.php:58` — `ServerAdmin = 'server.admin'`
- `app/Components/Traits/WithToasts.php` — notifications utilisateur
- `resources/views/pages/app/gpo/[guid]/index.blade.php` (Story 16.2) — page détail à enrichir (CTA + encart Impact)
- `resources/views/pages/parc-settings/wallpapers/index.blade.php` (Story 4.7) — pattern `<x-modal>` + actions destructives
- `routes/web.php` — groupe `Route::prefix('app')` à étendre avec `gpo.links`
- `tests/Support/FakesGpoService.php` — à étendre avec 4 builders write
- `tests/Concerns/BootstrapsSpatieTables.php` (Story 16.2) — bootstrap tables permissions
- `tests/Architecture/GpoNamespaceTest.php` — à enrichir avec test write methods → SambaToolRunner
- `docs/qa/domains/gpo.md` — à enrichir section 7 Story 16.5 (10 scénarios VM)
- `app/Gpo/README.md` — à enrichir section Story 16.5 + catalogue action_type
- `docs/tech-debt-gpo.md` — à enrichir TD-16.5-* (1-3 entrées)
- `legacy/modules/gpo/gpo-maj.php` — shim legacy import GPO (conservation cohabitation 16-4 paused)
- `sambaedu/includes/samba-tool.inc.php:989-1030` — fonctions legacy `gposetlink`/`gpodellink`/`gposetinheritance` (référence absolue)
- CLAUDE.md — convention filesystem-based router + composant modale + WithToasts

---

## Dev Agent Record

### Agent Model Used

`claude-opus-4-7` (dev BMAD, exécuté 2026-05-13).

### Debug Log References

- Suite Unit globale : 1124 tests / 70 errors / 5 failures (baseline 1127 / 85 / 7 — 0 régression introduite).
- Suite `GpoServiceWriteTest` : **21 tests** / 100% vert.
- Suite `GpoLinksPageTest` : **13 tests** / 100% vert.
- Suite `GpoLinksPagePermissionTest` : **4 tests** / 100% vert.
- Suite `GpoDetailPageTest` (enrichi de 3 tests) : 3 nouveaux tests verts ; les 3 errors + 3 failures pré-existantes (Vite manifest + position btn-primary du legacy button) inchangées.
- Suite Architecture `GpoNamespaceTest` : 1 nouveau test `it_validates_gpo_write_methods_use_samba_tool_runner` vert. 2 failures pré-existantes inchangées (NetworkScriptGenerator + GenerateWineImageJob).

### Completion Notes List

**T0 — Décisions DO1-DO4 actées par défaut documenté** :

- **DO1** : `reorderLinks` non atomique avec rollback best effort. Si rollback lui-même échoue → `RuntimeException` levée + log explicite « état AD potentiellement incohérent ». Documenté TD-16.5-1.
- **DO2** : Comptage postes via suffix-match SQL sur `ad_dn` Eloquent (`workstations.ad_dn ILIKE '%,<OU_DN>'`). Pas de colonne `ou_dn` dédiée sur la table `workstations`. Documenté TD-16.5-2 + fallback LIKE en SQLite (env tests).
- **DO3** : Page dédiée `/app/gpo/{guid}/links` (URL distincte + breadcrumb retour).
- **DO4** : Flat list OUs alphabétique avec recherche Livewire. Documenté TD-16.5-3 si parc avec >50 OUs.

**Pièges & contournements pendant le dev** :

1. **Piège Laravel route() avec regex contenant `{N}`** : `route('app.gpo.show', ['guid' => ...])` plante avec `Missing parameter: AAAAAAAA-...` quand la regex `where('guid', ...)` contient `{8}`/`{12}` (quantifiers regex interprétés comme placeholders par le générateur d'URL Laravel). **Contournement** : usage `url('/app/gpo/' . $this->guid . '/links')` au lieu de `route('app.gpo.links', [...])`. Pattern appliqué partout dans les nouveaux templates Blade.
2. **`SambaToolRunner` est `final`** : non mockable sans uopz/runkit. Pattern Story 16.7 reproduit — utilisation `Process::fake()` Laravel (mode array préservé), avec `Process::sequence()` pour les tests `reorderLinks` nécessitant un enchaînement de réponses.
3. **Catalogue `action_type` README** : `gpo.link.set` était documenté Story 16.1 mais la story 16.5 spécifie `gpo.link.add`. J'ai renommé l'entrée README de `gpo.link.set` → `gpo.link.add` + ajouté les 4 autres entrées 16.5 + marqué `gpo.inheritance.set` ✅.
4. **Tests `writeStubsProvider` (`GpoServiceTest`)** : retiré `setLink`/`removeLink`/`setInheritance` du provider (ils ne sont plus stubs). Reste les 3 stubs `create`/`delete`/`fetch` (Story 16.4 paused).
5. **Test architecture write methods → SambaToolRunner** : ma première regex `[\'"]samba-tool\s+[a-z]/` était trop large et matchait les messages d'erreur `RuntimeException` (`sprintf('samba-tool gpo X failed')`). J'ai resserré le pattern à `Process::[A-Za-z_]+\s*\(\s*[\'"]samba-tool` pour ne matcher que les vrais appels Process avec string (interdits).

**Shims/limitations introduits (cf. `docs/tech-debt-gpo.md`)** :

- TD-16.5-1 : `reorderLinks` non atomique (rollback best effort).
- TD-16.5-2 : comptage postes via suffix-match `ad_dn` (pas iso-AD strict).
- TD-16.5-3 : flat list OUs (pas d'arbre hiérarchique).

**Pas de nouveau shim @legacy-port introduit** — toutes les méthodes write sont nativement implémentées. Le shim 1bis-18 reste vivant pour la création GPO (16-4 paused), accessible via l'encart pied de page de la nouvelle UI.

**Frontière respectée Epic 17 / 16.6 / 16-4** :

- Aucune touche aux scripts Windows (`.bat`/`.vbs`/`.ps1` NETLOGON).
- Aucun bouton "Créer une GPO" — redirige vers shim legacy `/gpo/gpo-maj.php`.
- Aucun mapping `WorkstationGroup` SQL → OU (D1 — story 16.5b future).

### File List

**Fichiers créés** :

```
resources/views/pages/app/gpo/[guid]/links/index.blade.php  ← SFC liaisons ~600 lignes
tests/Unit/Gpo/GpoServiceWriteTest.php             ← 21 tests Unit méthodes write
tests/Feature/Gpo/GpoLinksPageTest.php             ← 13 tests Feature page liens
tests/Feature/Gpo/GpoLinksPagePermissionTest.php   ← 4 tests permission (mount + middleware route)
```

**Fichiers modifiés** :

```
app/Gpo/Services/GpoService.php                    ← 3 stubs implémentés + reorderLinks + 2 validators + 2 helpers privés (~250 lignes ajoutées)
app/Repositories/OrganizationalUnitRepository.php  ← +méthode listAll() (Cache::remember 5min + sort + fallback liste vide)
routes/web.php                                     ← +1 route /app/gpo/{guid}/links AVANT catchall
resources/views/pages/app/gpo/[guid]/index.blade.php  ← CTA "Gérer les liaisons" + encart Impact + helper countWorkstationsByOu
tests/Architecture/GpoNamespaceTest.php            ← +1 test it_validates_gpo_write_methods_use_samba_tool_runner
tests/Support/FakesGpoService.php                  ← 6 nouveaux builders write + expectNoCalls étendu
tests/Unit/Gpo/GpoServiceTest.php                  ← writeStubsProvider réduit aux 3 stubs restants (create/delete/fetch)
tests/Feature/Gpo/GpoDetailPageTest.php            ← +3 tests enrichissement détail (CTA + Impact + état vide)
app/Gpo/README.md                                  ← catalogue ✅ + nouvelle section "Story 16.5 — Liaison GPO ↔ OU AD"
docs/qa/domains/gpo.md                             ← +Section 7 (11 scénarios QA VM + checklist)
docs/tech-debt-gpo.md                              ← +3 entrées TD-16.5-1/2/3
_bmad-output/implementation-artifacts/sprint-status.yaml  ← status review + commentaire append-only + header last_updated
_bmad-output/implementation-artifacts/16-5-liaison-gpo-ou-parc-propagation.md  ← Status review + checkboxes T0-T7 + Dev Agent Record + File List
```

**Fichiers modifiés post-review (corrections automatiques 16-5)** :

```
resources/views/pages/app/gpo/index.blade.php      ← #1 AC7.2 : CTA "Créer une GPO (ancienne UI)" header listing (visible @can server.admin)
resources/views/pages/app/gpo/[guid]/links/index.blade.php  ← #2 rollback toggle disabled/enforced + #7 #[Locked] sur 5 props + #S1 Js::from → addslashes + #S2 garde "OU déjà liée" + loadAll() en cas d'échec + #4 wildcards SQL escapés (whereRaw ESCAPE)
resources/views/pages/app/gpo/[guid]/index.blade.php  ← #4 wildcards SQL escapés (whereRaw ESCAPE)
app/Gpo/Services/GpoService.php                    ← #3 heuristique idempotence resserrée (retire 'object class violation', restreint 'no such' → 'no such gp link') + #S3 garde permutation complète dans reorderLinks
routes/web.php                                     ← #11 commentaire route /links corrigé
docs/tech-debt-gpo.md                              ← TD-16.5-2 enrichie (scope sous-OU + wildcards) + TD-16.5-4 nouvelle (fragilité heuristiques stderr)
tests/Feature/Gpo/GpoIndexPageTest.php             ← +1 test #1 it_shows_create_gpo_legacy_cta_in_header_for_server_admin
tests/Feature/Gpo/GpoLinksPageTest.php             ← +5 tests (#4 wildcard escape, #2 rollback disabled, #2 rollback enforced, #S2 add link déjà liée)
tests/Feature/Gpo/GpoDetailPageTest.php            ← #10 bootstrap workstations + fixtures réelles dans it_shows_impact_card_with_workstation_counts_per_ou
tests/Unit/Gpo/GpoServiceWriteTest.php             ← +2 tests #S3 (reorder rejects truncated / foreign GUID)
```

---

## Recommandation Modèle Dev

**Modèle recommandé : `opus`**

**Justification** :

1. **Première story write AD d'Epic 16** — l'implémentation des stubs `setLink` / `removeLink` / `setInheritance` est **critique** : ces méthodes mutent l'attribut `gpLink` sur des OUs AD, propagé à tout le parc Windows au prochain `gpupdate`. Un bug = parc cassé. Confiance élevée nécessaire.

2. **Nouvelle méthode `reorderLinks` non triviale** — orchestration N appels `samba-tool` avec rollback best effort. Cas limites (échec mi-parcours, état initial mémorisé, ordre cible) demandent une logique défense en profondeur.

3. **Sécurité AD writeback** — validation regex GUID/DN AVANT side effect, mode array exclusif `SambaToolRunner`, tests `shouldNotReceive` pour prouver l'absence d'appel `samba-tool` avec input invalide. Pattern strict iso Story 16.7 `AdMachineManager`.

4. **Cohérence cross-stack multi-fichiers** — GpoService write (4 méthodes) + UI Livewire complexe (page dédiée + modales + sélecteur OU + comptage postes) + enrichissement détail 16.2 (CTA + encart Impact) + tests Unit/Feature/Architecture + 6 nouveaux `action_type` au catalogue + 3 docs (README, qa/domains, tech-debt). ~10 fichiers à toucher de manière cohérente.

5. **3 discrepances ouvertes (DO1/DO2/DO4)** à trancher en T0 avec impact architectural (atomicity, source comptage postes, UX OUs). Demande analyse en cours de dev.

6. **Bug legacy à NE PAS reproduire** (`gposetinheritance` ligne 1027) — nécessite vigilance sur le pattern mode array.

Cadre objectif : **4-5 jours** (estimation audit Section 6.G). Recadrage possible à 5-6j si T0 révèle complexités sur `reorderLinks` atomicity ou couverture LdapRecord OUs.

**Pourquoi pas sonnet** : sonnet est suffisant pour des stories UI/lecture pure (16.2, 16.3a) mais ici la combinaison AD writeback critique + nouvelle méthode `reorderLinks` non triviale + 3 discrepances ouvertes + sécurité défense en profondeur multi-niveaux + cohérence cross-stack 10+ fichiers justifie opus.
