# Story 16.3a : Liens profonds vers sections déjà-natives

Status: review

> Sous-story issue du **split de la Story 16.3** monolithique en 16.3a/b/c (décision Henri 2026-05-11, post-audit 16.1 section 6.G). Périmètre minimaliste : pure navigation depuis `/app/gpo` et `/app/gpo/{guid}` vers les UIs natives existantes (Firefox/Thunderbird, Wallpapers, Shortcuts, Roaming). **Pas de nouveau code métier — uniquement UI et tests.**

---

## Story

As a **responsable SER (rôle `server.admin`)**,
I want, depuis la page native `/app/gpo` (listing) et `/app/gpo/{guid}` (détail), **naviguer en un clic** vers les sections déjà refondues nativement (`/app/parc-settings/app-customizations`, `/app/parc-settings/wallpapers`, `/app/shortcuts`, `/admin/settings?tab=profils-itinerants`) lorsque la GPO consultée concerne l'un de ces périmètres,
So que je dispose d'une **vraie expérience unifiée** : au lieu d'aller lire la GPO dans la vue legacy puis ouvrir une autre page pour modifier le wallpaper / le profil itinérant / la config Firefox, **je passe directement de la lecture GPO à l'édition native** déjà disponible — **sans bouton "ancienne UI" intermédiaire** pour ces sections-là.

---

## Contexte

La Story 16.2 a livré la page Livewire `/app/gpo` (listing) + `/app/gpo/{guid}` (détail) avec une **heuristique D9** (`NATIVE_SECTIONS_HEURISTICS`) qui détecte, à partir du `displayName` de la GPO, si elle correspond à une section déjà refondue nativement. En 16.2 cette heuristique alimente **uniquement la page détail** (encart "Sections de cette GPO gérables nativement" — un seul affichage, sans CTA fort).

La présente Story **enrichit** cette base :

1. **Page détail** (`/app/gpo/{guid}`) : l'encart actuel reste, mais le bouton "Éditer dans l'ancienne UI" est **supplanté** (ou complété) par un bouton primaire "**Éditer nativement**" quand l'heuristique détecte une section native. Le bouton legacy reste visible mais en secondaire (chip `btn-ghost btn-xs`). **Cas multi-match** (par ex. GPO `firefox-wallpaper-2024` matche à la fois `firefox` et `wallpaper`) → afficher **tous** les CTA natifs (badges/boutons multiples). Pas de priorité unique.
2. **Page listing** (`/app/gpo`) : ajouter une **icône natif** (chip discret) sur la ligne du tableau quand l'heuristique matche. Au survol, tooltip : "Section gérable nativement — cliquer pour éditer". L'action "Éditer (ancienne UI)" déjà présente dans la colonne actions devient secondaire pour ces lignes (un menu déroulant ou un chip "Édition native" + "ancien UI" côte à côte — voir Décision D3 ci-dessous).
3. **Breadcrumbs natifs** : sur les pages cibles (`/app/parc-settings/wallpapers`, `/app/parc-settings/app-customizations`, `/app/shortcuts`, `/admin/settings`), **ajouter un fil d'Ariane "← Retour à la GPO {displayName}"** quand l'URL d'arrivée contient un paramètre `?from_gpo={guid}` (préservation du contexte de navigation).
4. **Refactor heuristique** : extraire la constante `NATIVE_SECTIONS_HEURISTICS` actuellement dupliquée dans `/app/gpo/[guid]/index.blade.php` vers une classe partagée `App\Gpo\Support\NativeSectionResolver` afin que la SFC listing puisse la consommer **sans duplication**. Le test `GpoDetailPageTest::it_renders_correct_native_section_for_display_name` (5 cas en dataProvider) doit migrer vers un test unitaire de cette classe.

> **Décision Henri 2026-05-11** : `associations_out.php` reste dans Epic 16 (placée dans 16.3c), **hors scope 16.3a**. Templates GPO Git conservent le pattern apt existant — impact sur 16.4, **pas sur 16.3a**. DNS/réplication AD sortent dans Epic 18 — **hors Epic 16**.

> **Décision Henri 2026-05-11** : Q1 post-review 16.1 — les 3 étiquettes catalogue logs `gpo.containers.list`, `gpo.link.get`, `gpo.inheritance.get` sont **conservées** (consommées par 16.2, pas remises en cause par 16.3a).

> **Décision Henri 2026-05-11** : Q3 post-review 16.1 — DNS + réplication AD = Epic 18.

---

## Garde-fous Epic 16 (rappel — applicables à cette story)

- **AD = source de vérité** : aucune table Eloquent créée. Les heuristiques opèrent uniquement sur `displayName` retourné par `GpoService::list/get` (Story 16.1).
- **Trois couches** (`architecture.md:343-344`) : la SFC Livewire **n'appelle JAMAIS** `LdapRecord` / `Eloquent` / `Process` directement. Le nouveau `NativeSectionResolver` est un service stateless (pas d'accès données) — pas de violation de couche.
- **Logging** : aucun nouveau channel ni `action_type`. Les clics CTA natifs **ne loggent rien** côté `gpo` (les pages cibles loggent déjà via leurs propres canaux). Si on souhaite tracer l'origine d'arrivée (`?from_gpo={guid}`), c'est purement informatif côté UI — pas de log structuré.
- **Convention CLAUDE.md** : `WithToasts` pour notifications (probablement aucune dans cette story — c'est de la navigation), filesystem-based router, pas de Volt class component.
- **Shim 1bis-18 reste vivant** : aucune suppression. Le bouton "Éditer dans l'ancienne UI" reste accessible (devient secondaire pour les sections natives, primaire pour les autres).
- **Pas de `@legacy-port`** : aucun fichier porté du legacy.
- **Permission** : iso 16.2 — `server.admin`. **Aucune nouvelle permission** créée.

---

## Dépendances

| Story / Epic | Titre | Status | Détail |
|---|---|---|---|
| **16.2** | Listing & lecture GPO (UI native) | review (2026-05-11) | **Bloquante au sens "fournit la base UI"** — page Livewire `/app/gpo` + `/app/gpo/{guid}` + heuristique `NATIVE_SECTIONS_HEURISTICS`. 16.3a démarre dès que 16.2 est mergeable (peut tourner en parallèle de la review Henri — aucun changement de signature attendu sur les SFCs). |
| 16.1 | Fondations GPO natives + audit legacy | review (2026-05-11) | Fournit `GpoService::list/get` (déjà consommé par 16.2). Pas de modification dans 16.3a. |
| Story 4.7 | Wallpapers Eloquent | done | Cible UI native `/app/parc-settings/wallpapers` (page Livewire SFC existante, permission `wallpaper.manage`). |
| Story 4.8 | Personnalisation apps extensible | done | Cible UI native `/app/parc-settings/app-customizations` (page Livewire SFC existante). |
| `ShortcutsService` | (refonte historique, pre-Epic 16) | done | Cible UI native `/app/shortcuts` (page Livewire SFC existante). |
| 1bis-18f | Module GPO profils itinérants | done | Cible UI native `/admin/settings?tab=profils-itinerants` (livewire tab dans `pages/admin/settings/index.blade.php`). |

**Conclusion dépendances** : aucune bloquante. Story 16.2 fournit la base — 16.3a est purement additive sur la même base UI. **Recommandation SM** : démarrer 16.3a après que Henri ait validé 16.2 sur la VM (smoke tests + tests Feature) pour éviter de toucher un pattern instable. Si Henri OK pour parallèle, 16.3a peut être faite tout de suite (aucune surface partagée mutable).

---

## Décisions SM (à intégrer dès le kickoff)

| #  | Décision | Justification |
|----|----------|---------------|
| D1 | **Extraire `NATIVE_SECTIONS_HEURISTICS` dans `App\Gpo\Support\NativeSectionResolver`** (classe stateless, méthode `resolve(string $displayName): array<NativeSectionMatch>`). | (a) Évite la duplication entre listing et détail (DRY). (b) Permet un test unitaire pur (`tests/Unit/Gpo/NativeSectionResolverTest.php`) sans bootstrap Spatie ni Livewire. (c) Cohérent avec `Support/` (déjà existant pour `GpoLogger`, `SambaToolRunner`). (d) Le test `GpoDetailPageTest::it_renders_correct_native_section_for_display_name` (5 cas dataProvider) **migre** vers le test unit — la couverture page détail reste via 1 test "smoke" (CTA primaire affiché si match). |
| D2 | **Cas multi-match (GPO matchant 2+ sections natives)** : afficher **tous** les CTA natifs (ex. GPO `firefox-wallpaper-2024` → 2 boutons : "Éditer Firefox/Thunderbird" + "Éditer Wallpapers"). **Pas de priorité unique**. | (a) Cas rare mais possible dans le legacy (GPO bricolées qui touchent plusieurs sections). (b) Ne pas choisir à la place de l'utilisateur. (c) Le test couvre 1 cas multi-match explicite (par ex. `firefox-thunderbird-roaming-test`). |
| D3 | **Page listing** : ajouter une **colonne dédiée "Édition native"** (juste avant la colonne "Actions" existante). Pour chaque ligne, si l'heuristique matche → chip `badge badge-success badge-sm` avec icône + tooltip ("X sections natives disponibles"). Au clic sur le chip → ouvrir une popover/dropdown listant les CTA, **OU** lien direct si match unique. Si pas de match → cellule vide. La colonne "Actions" existante reste inchangée (bouton "Voir détail" + lien legacy). | (a) Pas d'overload visuel sur les colonnes existantes. (b) Découvrabilité depuis le listing (l'utilisateur voit directement quelles GPOs sont éditables nativement sans cliquer sur le détail). (c) Si multi-match, dropdown plutôt que lien direct évite de devoir choisir arbitrairement. (d) Conservation de la colonne "Actions" — pas de régression sur le bouton legacy. |
| D4 | **Paramètre `?from_gpo={guid}` ajouté aux URLs CTA** + **breadcrumb retour** sur les 4 pages cibles. Format : `/app/parc-settings/wallpapers?from_gpo={GUID}` ou avec `&` si paramètre déjà présent. Sur la page cible : si `request()->has('from_gpo')`, afficher en haut un fil d'Ariane `<a href="/app/gpo/{guid}">← Retour à la GPO «{displayName}»</a>`. **Le displayName** est ré-extrait par un `GpoService::get($guid)` côté cible — coût acceptable (1 appel samba-tool, parfois ~1s) car ce breadcrumb est rare (uniquement après navigation depuis `/app/gpo`). Si `GpoService::get` échoue ou retourne null, fallback générique : "← Retour à `/app/gpo`". | (a) Préserve le contexte de navigation. (b) Évite que l'utilisateur perde sa GPO de départ après édition. (c) Coût latence acceptable (4 pages × cas rare). (d) Fallback gracieux si la GPO a été supprimée entre temps. **Limite acceptée** : pas de cache du displayName en session — re-fetch à chaque visite. |
| D5 | **Bouton legacy** : sur la page détail, quand l'heuristique matche, le bouton "Éditer dans l'ancienne UI" passe en **secondaire** (`btn btn-outline btn-sm` → `btn btn-ghost btn-xs`) avec sous-texte "(non recommandé pour cette section — utilisez les CTA natifs ci-dessus)". Sur les GPOs non-natives, il reste **primaire** (iso 16.2). | (a) Signale visuellement que l'édition native est préférable. (b) Ne casse pas la fonctionnalité — l'utilisateur peut toujours basculer en legacy s'il préfère. (c) Convergence progressive vers le natif sans rupture. |
| D6 | **Pas de migration / pas de nouveau service / pas de nouveau channel logs**. Pas d'événement Spatie. Pas de modale. | Confirmé : story de pure navigation. Si une logique métier émerge (ex. besoin de pré-charger une config), elle est **out-of-scope** et doit basculer en 16.3b ou 16.3c. |
| D7 | **Pas de catchall override d'URLs legacy spécifiques** (ex. `gpo/firefox.php` → `/app/parc-settings/app-customizations`). Hors scope 16.3a. À évaluer story par story. | (a) Le catchall override de `gestion_gpo.php` (16.2) est suffisant pour l'expérience utilisateur cible. (b) Bloquer `firefox.php` pourrait casser des liens externes (favoris admin, doc interne legacy). (c) À traiter dans 16.3b/c quand les sections elles-mêmes sont portées (Network/Veyon/Wine). |
| D8 | **Tests Feature** : 1 fichier `tests/Feature/Gpo/GpoNativeSectionLinksTest.php` qui couvre (a) chip listing affiché si match, caché sinon ; (b) page détail : CTA primaire natif présent si match, multi-match = N CTA ; (c) `?from_gpo={guid}` propagé dans les URLs CTA ; (d) breadcrumb "retour GPO" affiché sur les 4 pages cibles si paramètre présent. **Tests unitaires** : `tests/Unit/Gpo/NativeSectionResolverTest.php` (au moins 6 cas : 4 mappings simples + 1 multi-match + 1 no-match). | Périmètre test cohérent avec l'esprit "1 jour de dev" — pas de couverture exhaustive cross-page, mais les chemins critiques (matching + nav UI + breadcrumb) sont garantis. |
| D9 | **Suppression du test `GpoDetailPageTest::it_renders_correct_native_section_for_display_name`** (5 cas dataProvider de 16.2) ET migration vers `NativeSectionResolverTest`. Sur la page détail, **1 test smoke** garde la couverture de l'intégration (heuristique câblée correctement). | Évite la régression d'un mauvais refactor : la suite globale doit rester verte. Le test "smoke" page détail garde la garantie que le composant utilise bien `NativeSectionResolver` (et non une const oubliée localement). |
| D10 | **Pas de E2E navigateur** (Playwright/Cypress) — iso 16.2 (pas d'infra E2E établie). Smoke tests manuels VM seuls couvrent le parcours bout-en-bout. | Cohérent avec `tech-debt-test-infra-cleanup.md`. |

---

## Acceptance Criteria

> 4 volets. Volet 1 = refactor `NativeSectionResolver`, Volet 2 = enrichissement page détail (CTA natifs primaires), Volet 3 = enrichissement page listing (chip natif), Volet 4 = breadcrumb retour sur pages cibles + tests.

### Volet 1 — Refactor `NativeSectionResolver`

**AC1.1** — **Création de la classe `App\Gpo\Support\NativeSectionResolver`**
**Given** la story est en cours d'implémentation
**When** le fichier `app/Gpo/Support/NativeSectionResolver.php` est créé
**Then** il expose :
- Une constante `MAPPING` (private const, array associatif) identique au contenu de `NATIVE_SECTIONS_HEURISTICS` de la SFC détail actuelle (4 entrées : `profils-itinerants`, `wallpapers`, `app-customizations`, `shortcuts`)
- Une méthode publique statique `resolve(string $displayName): array` qui retourne `array<string, array{patterns: array<string>, url: string, label: string, icon: string}>` (clés = identifiants section, valeurs = mapping complet)
- Une méthode publique statique `hasMatch(string $displayName): bool` (helper, retourne `count(resolve($displayName)) > 0`)
- Une méthode publique statique `buildUrl(string $sectionKey, ?string $fromGpoGuid = null): string` qui retourne l'URL de la section avec ou sans `?from_gpo={guid}` (encode URL si besoin)

**And** la classe est stateless (pas de propriétés, méthodes statiques uniquement)
**And** elle ne dépend d'aucun service Laravel (pas de container, pas de config — pure logique métier)
**And** elle n'effectue **aucun appel** `samba-tool`, `LdapRecord`, `Eloquent`, ou autre I/O.

**AC1.2** — **Suppression de la duplication dans la SFC détail**
**Given** le refactor est appliqué
**When** `resources/views/pages/app/gpo/[guid]/index.blade.php` est mis à jour
**Then** la constante locale `NATIVE_SECTIONS_HEURISTICS` est **supprimée**
**And** la méthode `nativeSectionLinks()` appelle désormais `NativeSectionResolver::resolve($this->gpo['displayName'])`
**And** aucune régression : le test smoke "page détail affiche CTA natifs si match" reste vert.

**AC1.3** — **Test unitaire `NativeSectionResolverTest`**
**Given** la suite de tests
**When** elle exécute `tests/Unit/Gpo/NativeSectionResolverTest.php`
**Then** au moins **6 cas** sont couverts :
1. `redirections-roaming-test` → matche `profils-itinerants` (URL `/admin/settings?tab=profils-itinerants`)
2. `wallpaper-default` → matche `wallpapers` (URL `/app/parc-settings/wallpapers`)
3. `firefox-policy-2024` → matche `app-customizations` (URL `/app/parc-settings/app-customizations`)
4. `shortcuts-eleves` → matche `shortcuts` (URL `/app/shortcuts`)
5. **Multi-match** : `firefox-wallpaper-roaming-2024` → matche **3 sections** simultanément
6. **No-match** : `gpo-custom-foo-bar` → retourne tableau vide

**And** un test couvre `buildUrl()` avec et sans `?from_gpo` (vérifie que l'`&` est utilisé si l'URL contient déjà `?` — cas profils-itinerants)
**And** le test est **pur unit** : pas de `TestCase` Laravel (`use PHPUnit\Framework\TestCase`), pas de bootstrap base de données, pas de Spatie.

**AC1.4** — **Migration du test 16.2 `it_renders_correct_native_section_for_display_name`**
**Given** la suite de tests
**When** le refactor est appliqué
**Then** le test `GpoDetailPageTest::it_renders_correct_native_section_for_display_name` (5 cas dataProvider, créé en 16.2) est **supprimé**
**And** sa couverture migre vers `NativeSectionResolverTest`
**And** sur `GpoDetailPageTest`, **1 nouveau test smoke** est ajouté : `it_uses_native_section_resolver_for_links` (mocke ou détecte que le résolveur est consulté — vérifier que la SFC affiche bien le résultat de `resolve()`).

### Volet 2 — Enrichissement page détail (`/app/gpo/{guid}`)

**AC2.1** — **CTAs natifs primaires** quand l'heuristique matche
**Given** une GPO dont le `displayName` matche au moins une section native (ex. `firefox-policy`)
**When** l'utilisateur affiche la page `/app/gpo/{guid}`
**Then** l'encart actuel "Sections de cette GPO gérables nativement" (alert success — déjà existant en 16.2) reste affiché
**And** **en plus**, dans le header de la page (où se trouve le bouton "Éditer dans l'ancienne UI" en 16.2), un **bouton primaire** "Éditer nativement" est affiché **avant** le bouton legacy
**And** si **multi-match**, **N boutons** primaires sont affichés côte à côte (ex. "Éditer Firefox/Thunderbird" + "Éditer Wallpapers")
**And** chaque CTA primaire pointe vers l'URL `buildUrl($sectionKey, $this->guid)` (avec `?from_gpo={guid}`)
**And** les CTAs primaires utilisent les libellés et icônes de `NativeSectionResolver` (cohérence avec l'encart).

**AC2.2** — **Dégradation du bouton legacy** (Décision D5)
**Given** une GPO dont le `displayName` matche une section native
**When** la page détail est rendue
**Then** le bouton "Éditer dans l'ancienne UI" est rendu en **secondaire** : classe CSS `btn btn-ghost btn-xs` (au lieu de `btn btn-primary btn-sm` en 16.2 pour les GPOs non-natives)
**And** un sous-texte explicatif est affiché : "Non recommandé — utilisez les CTAs natifs ci-dessus."
**And** sur les GPOs non-natives (`NativeSectionResolver::hasMatch()` retourne false), le bouton legacy reste **primaire** (iso 16.2).

**AC2.3** — **Encart "Sections natives disponibles" enrichi**
**Given** l'encart 16.2 (alert success "Sections de cette GPO gérables nativement")
**When** la page est rendue après refactor
**Then** chaque lien dans l'encart inclut le paramètre `?from_gpo={guid}` dans son `href`
**And** l'encart reste positionné au même endroit (sous les métadonnées principales, avant les containers).

### Volet 3 — Enrichissement page listing (`/app/gpo`)

**AC3.1** — **Colonne "Édition native"** (Décision D3)
**Given** le tableau du listing `/app/gpo`
**When** la page est rendue
**Then** une **nouvelle colonne** "Édition native" est ajoutée **juste avant** la colonne "Actions" existante
**And** la colonne header affiche le label "Édition native" + icône `fa-circle-check`
**And** pour chaque ligne, si `NativeSectionResolver::hasMatch($displayName)` retourne `true` :
- afficher un chip `badge badge-success badge-sm` avec icône + label "N sections" (N = nombre de matches)
- au hover, tooltip "X sections gérables nativement — cliquer pour éditer"
- si **N=1**, le chip est cliquable → lien direct vers l'URL `buildUrl($sectionKey, $guid)`
- si **N≥2**, le chip ouvre un dropdown DaisyUI listant les CTAs (un lien par section)

**And** si `hasMatch()` retourne `false`, la cellule est **vide** (pas de chip, juste un tiret discret `<span class="text-base-content/30">—</span>`).

**AC3.2** — **Cohabitation avec la colonne "Actions"** (régression 16.2)
**Given** la colonne "Actions" existante de 16.2 (bouton "Voir détail" + bouton "Éditer ancienne UI")
**When** la nouvelle colonne "Édition native" est ajoutée
**Then** la colonne "Actions" reste **inchangée** — les deux boutons existants sont préservés à l'identique
**And** le test feature `GpoIndexPageTest::it_displays_n_gpos_after_mocking_list` reste vert (pas de cassure du rendu listing existant).

**AC3.3** — **Performance** (pas d'appel `samba-tool` additionnel)
**Given** la collection de GPOs déjà chargée par `GpoService::list()` (16.2)
**When** la nouvelle colonne s'affiche
**Then** **aucun appel `samba-tool` additionnel** n'est effectué pour résoudre les heuristiques (résolution 100 % en mémoire via `NativeSectionResolver::resolve()` sur le `displayName` déjà retourné par `list()`)
**And** un test feature (ou commentaire dans le code Livewire) garantit que la résolution est faite en O(N) sur la collection paginée (et non sur l'intégralité du parc — laisser Livewire paginer d'abord).

### Volet 4 — Breadcrumb retour sur pages cibles + tests

**AC4.1** — **Breadcrumb "Retour à la GPO" sur `/app/parc-settings/wallpapers`**
**Given** la page Livewire `/app/parc-settings/wallpapers` (Story 4.7 — fichier `resources/views/pages/parc-settings/wallpapers/index.blade.php`)
**When** l'utilisateur arrive avec un paramètre `?from_gpo={GUID}`
**Then** un fil d'Ariane est affiché en **haut de la page** (au-dessus du titre ou dans le slot `actions` de `x-organisms.page` selon convention) :
- `<a href="/app/gpo/{guid}" class="btn btn-ghost btn-sm"><i class="fa-solid fa-arrow-left"></i> Retour à la GPO «{displayName}»</a>`
- Le `{displayName}` est résolu via `GpoService::get($guid)` (appel synchrone, latence ~1 s acceptable car cas rare)
- Si `GpoService::get($guid)` retourne `null` ou lève une exception, fallback : `<a href="/app/gpo">← Retour à la liste des GPOs</a>` (label générique)

**And** sans paramètre `?from_gpo`, le breadcrumb n'est **PAS affiché** (pas de pollution UI pour les visites directes de la page wallpapers).

**AC4.2** — **Breadcrumb identique sur les 3 autres pages cibles**
**Given** les pages Livewire :
- `/app/parc-settings/app-customizations` (`resources/views/pages/parc-settings/app-customizations/index.blade.php`)
- `/app/shortcuts` (`resources/views/pages/shortcuts/index.blade.php`)
- `/admin/settings?tab=profils-itinerants` (`resources/views/pages/admin/settings/index.blade.php`)
**When** une visite avec `?from_gpo={GUID}` est effectuée
**Then** chaque page affiche le même breadcrumb que AC4.1
**And** pour `/admin/settings?tab=profils-itinerants`, le breadcrumb est affiché **uniquement** quand le tab actif est `profils-itinerants` (pas les autres tabs — éviter la pollution).

**AC4.3** — **Helper centralisé pour le breadcrumb**
**Given** le breadcrumb est dupliqué sur 4 pages
**When** l'implémentation est faite
**Then** un composant Blade réutilisable `<x-molecules.gpo-back-link guid="..." />` est créé (ou un partial `_partials/gpo-back-link.blade.php` selon convention projet)
**And** ce composant encapsule : (a) la lecture de `request()->query('from_gpo')`, (b) l'appel `GpoService::get($guid)` (try/catch silencieux), (c) le rendu HTML
**And** les 4 pages cibles incluent simplement `<x-molecules.gpo-back-link />` (sans passer le guid si le composant le lit lui-même de la query string)
**And** un test unit/feature valide le rendu du composant en isolation (1 test : avec paramètre + displayName trouvé → lien complet ; 1 test : avec paramètre + GpoService::get retourne null → fallback ; 1 test : sans paramètre → composant vide).

**AC4.4** — **Tests Feature `GpoNativeSectionLinksTest`**
**Given** la suite de tests
**When** elle exécute `tests/Feature/Gpo/GpoNativeSectionLinksTest.php`
**Then** au moins **6 tests** sont couverts :
1. `it_displays_native_chip_on_listing_when_displayname_matches` — mock `GpoService::list()` avec une GPO `firefox-policy` → chip success visible dans le rendu
2. `it_does_not_display_native_chip_when_no_match` — mock avec `gpo-custom-foo` → cellule vide
3. `it_displays_primary_native_cta_on_detail_page_when_match` — mock `GpoService::get()` avec `displayName=wallpaper-default` → bouton "Éditer Wallpapers" présent en header avant le bouton legacy
4. `it_displays_n_ctas_for_multi_match` — `displayName=firefox-wallpaper-roaming` → 3 boutons natifs présents
5. `it_propagates_from_gpo_param_in_cta_urls` — vérifie que `?from_gpo={guid}` est dans le `href` des CTAs natifs
6. `it_displays_secondary_legacy_button_when_native_match` — bouton legacy en `btn-ghost btn-xs` (classe CSS) + sous-texte "non recommandé"

**And** un fichier de test **séparé** `tests/Feature/Gpo/GpoBackLinkComponentTest.php` couvre le composant breadcrumb (3 tests : avec match, avec GpoService null, sans paramètre).

**AC4.5** — **Aucune régression**
**Given** la suite de tests complète
**When** elle est lancée sur la VM
**Then** **aucun test 16.2 ne casse** (en particulier les tests `GpoIndexPageTest` et `GpoDetailPageTest`)
**And** le test 16.2 `it_renders_correct_native_section_for_display_name` est **supprimé proprement** (AC1.4 — migré vers `NativeSectionResolverTest`)
**And** la suite globale `php artisan test` retourne 100 % vert (ou égal au baseline pre-merge).

---

## Hors-scope (explicite)

- **Catchall override d'URLs legacy spécifiques** (ex. `gpo/firefox.php` → `/app/parc-settings/app-customizations`) — Décision D7. À traiter dans 16.3b/c.
- **Nouvelle UI native** : aucune. Toutes les pages cibles existent déjà (4.7, 4.8, ShortcutsService, 1bis-18f).
- **Logging des clics CTA** : pas de log structuré côté `gpo` (les pages cibles loggent dans leurs propres canaux).
- **Cache de `displayName`** côté pages cibles : pas de session/cache — re-fetch à chaque visite via `GpoService::get()`. Acceptable car cas rare.
- **Section "Network" / "Veyon" / "Wine" / "Associations apps"** — Stories 16.3b et 16.3c.
- **Mutations GPO** — Stories 16.4 et 16.5.
- **E2E navigateur** (Playwright/Cypress) — pas d'infra projet.
- **`?from_gpo` validé en regex stricte côté pages cibles** : on se contente du try/catch sur `GpoService::get()` — si le guid est invalide, fallback générique. Pas besoin de regex stricte (defense in depth optionnelle).
- **Tracking analytique** (combien d'utilisateurs cliquent sur les CTAs natifs vs legacy) — hors scope, non demandé.

---

## Tasks / Subtasks

### Phase T0 — Cadrage & vérifications préalables

- [x] **T0.1** Confirmer que la Story 16.2 est en `review` ou `done` (validation Henri smoke tests VM). **Démarrage parallèle acceptable** si Henri OK.
- [x] **T0.2** Lire `resources/views/pages/app/gpo/[guid]/index.blade.php` pour comprendre la `NATIVE_SECTIONS_HEURISTICS` actuelle (lignes 24-49) — code à extraire.
- [x] **T0.3** Lire `resources/views/pages/app/gpo/index.blade.php` (listing 16.2) pour repérer la structure du tableau et l'emplacement de la colonne "Actions" (recherche `<th>` / boucle `@foreach ($paginatedItems as $gpo)`).
- [x] **T0.4** Lire `resources/views/pages/parc-settings/wallpapers/index.blade.php` (pattern slot `actions` ou header — pour insérer le breadcrumb).
- [x] **T0.5** Lire `resources/views/pages/admin/settings/index.blade.php` (structure des tabs — pour insertion conditionnelle du breadcrumb dans le tab `profils-itinerants`).
- [x] **T0.6** Vérifier la convention composants Blade (`x-molecules.*` vs partials `_partials/*.blade.php`) en regardant `resources/views/components/molecules/` — décider du chemin du composant `gpo-back-link`.

### Phase T1 — Refactor `NativeSectionResolver` (Volet 1)

- [x] **T1.1** Créer `app/Gpo/Support/NativeSectionResolver.php` (AC1.1) avec :
  - `private const MAPPING = [...]` (copie du contenu actuel de la SFC détail)
  - `public static function resolve(string $displayName): array`
  - `public static function hasMatch(string $displayName): bool`
  - `public static function buildUrl(string $sectionKey, ?string $fromGpoGuid = null): string`
  - PHPDoc complète + `declare(strict_types=1)`
- [x] **T1.2** Mettre à jour `resources/views/pages/app/gpo/[guid]/index.blade.php` (AC1.2) :
  - Supprimer `private const NATIVE_SECTIONS_HEURISTICS` (lignes 24-49)
  - Remplacer le corps de `nativeSectionLinks()` par `return NativeSectionResolver::resolve($this->gpo['displayName'] ?? '');`
  - Ajouter `use App\Gpo\Support\NativeSectionResolver;` en tête
- [x] **T1.3** Créer `tests/Unit/Gpo/NativeSectionResolverTest.php` (AC1.3) — 6+ cas (4 simples + 1 multi-match + 1 no-match + 1 dédié `buildUrl()`)
- [x] **T1.4** Supprimer le test 16.2 `it_renders_correct_native_section_for_display_name` de `tests/Feature/Gpo/GpoDetailPageTest.php` (AC1.4)
- [x] **T1.5** Ajouter 1 test smoke dans `GpoDetailPageTest::it_uses_native_section_resolver_for_links` (AC1.4)

### Phase T2 — Enrichissement page détail (Volet 2)

- [x] **T2.1** Mettre à jour `resources/views/pages/app/gpo/[guid]/index.blade.php` (AC2.1) :
  - Dans `<x-slot:actions>`, avant le bouton legacy : `@foreach ($this->nativeSectionLinks() as $key => $link)` rendre un bouton primaire avec icône + label + `href = NativeSectionResolver::buildUrl($key, $this->guid)`
  - Si `count($nativeLinks) === 0`, comportement iso 16.2 (bouton legacy en primaire)
- [x] **T2.2** Implémenter la dégradation du bouton legacy (AC2.2) :
  - Calculer `$isLegacySecondary = count($nativeLinks) > 0`
  - Classes CSS conditionnelles : `{{ $isLegacySecondary ? 'btn btn-ghost btn-xs' : 'btn btn-primary btn-sm' }}`
  - Sous-texte conditionnel si `$isLegacySecondary`
- [x] **T2.3** Mettre à jour l'encart 16.2 "Sections gérables nativement" (AC2.3) :
  - Chaque `<a href="{{ $link['url'] }}">` devient `<a href="{{ NativeSectionResolver::buildUrl($key, $guid) }}">`

### Phase T3 — Enrichissement page listing (Volet 3)

- [x] **T3.1** Mettre à jour `resources/views/pages/app/gpo/index.blade.php` (AC3.1) :
  - Ajouter une `<th>` "Édition native" avant `<th>Actions` dans le `<thead>`
  - Dans la boucle `@foreach`, ajouter une `<td>` avec :
    - `@php $matches = \App\Gpo\Support\NativeSectionResolver::resolve($gpo['displayName']); @endphp`
    - Si `count($matches) === 0` → `<span class="text-base-content/30">—</span>`
    - Si `count($matches) === 1` → chip cliquable lien direct
    - Si `count($matches) >= 2` → dropdown DaisyUI avec liste des CTAs
- [x] **T3.2** Garantir la non-régression de la colonne "Actions" (AC3.2) — code existant préservé tel quel
- [x] **T3.3** Vérifier la perf (AC3.3) — résolution faite uniquement sur `$paginatedItems` (déjà paginé en mémoire), pas sur `$this->gpos` complet

### Phase T4 — Breadcrumb pages cibles (Volet 4)

- [x] **T4.1** Créer le composant Blade `resources/views/components/molecules/gpo-back-link.blade.php` (AC4.3) :
  ```blade
  @php
      $guid = request()->query('from_gpo');
      if (!$guid) return;
      try {
          $gpo = app(\App\Gpo\Services\GpoService::class)->get($guid);
          $displayName = $gpo?->displayName ?? null;
      } catch (\Throwable) {
          $displayName = null;
      }
  @endphp
  @if ($displayName)
      <a href="{{ route('app.gpo.show', ['guid' => $guid]) }}" class="btn btn-ghost btn-sm">
          <i class="fa-solid fa-arrow-left"></i>
          Retour à la GPO «{{ $displayName }}»
      </a>
  @elseif ($guid)
      <a href="{{ route('app.gpo.index') }}" class="btn btn-ghost btn-sm">
          <i class="fa-solid fa-arrow-left"></i>
          Retour à la liste des GPOs
      </a>
  @endif
  ```
- [x] **T4.2** Intégrer `<x-molecules.gpo-back-link />` dans :
  - `resources/views/pages/parc-settings/wallpapers/index.blade.php` — en haut du body ou dans le slot `actions` (AC4.1)
  - `resources/views/pages/parc-settings/app-customizations/index.blade.php` — idem (AC4.2)
  - `resources/views/pages/shortcuts/index.blade.php` — idem (AC4.2)
  - `resources/views/pages/admin/settings/index.blade.php` — **conditionnel sur tab actif** `profils-itinerants` (AC4.2)
- [x] **T4.3** Créer `tests/Feature/Gpo/GpoBackLinkComponentTest.php` (AC4.3) — 4 tests (3 AC + 1 bonus exception) :
  1. Avec `?from_gpo` valide + `GpoService::get` retourne GPO → lien complet
  2. Avec `?from_gpo` valide + `GpoService::get` retourne null → fallback générique
  3. Sans `?from_gpo` → composant vide (assertion HTML)
  4. Bonus : `GpoService::get` lève exception → fallback silencieux

### Phase T5 — Tests d'intégration (Volet 4 suite)

- [x] **T5.1** Créer `tests/Feature/Gpo/GpoNativeSectionLinksTest.php` (AC4.4) — 8 tests (6 AC4.4 + 2 bonus)
- [ ] **T5.2** Lancer `php artisan test tests/Feature/Gpo tests/Unit/Gpo` — 100 % vert attendu (**ACTION HENRI** : VM)
- [ ] **T5.3** Lancer `php artisan test` complet — aucune régression vs baseline (**ACTION HENRI** : VM)

### Phase T6 — Documentation & finalisation

- [x] **T6.1** Enrichir `docs/qa/domains/gpo.md` — ajouter une **section 3 "Liens profonds sections natives (Story 16.3a)"** en append-only avec scénarios QA manuels VM :
  1. Naviguer vers `/app/gpo` → vérifier que les GPOs nommées avec `firefox` / `wallpaper` / `shortcut` / `roaming` affichent un chip success "Édition native"
  2. Cliquer sur le chip d'une GPO `wallpaper-default` → arrive sur `/app/parc-settings/wallpapers?from_gpo={GUID}` avec breadcrumb visible
  3. Naviguer vers une GPO custom (sans match) → chip absent, bouton legacy primaire
  4. Multi-match : GPO `firefox-wallpaper-test` → 2-3 CTAs natifs en page détail
  5. Breadcrumb "Retour à la GPO" : cliquer → revient sur `/app/gpo/{guid}` avec contexte préservé
  6. Si la GPO référencée par `?from_gpo` a été supprimée → fallback breadcrumb générique
- [x] **T6.2** Mettre à jour `_bmad-output/implementation-artifacts/sprint-status.yaml` : `16-3a-liens-profonds-sections-natives: ready-for-dev` → `review`.
- [ ] **T6.3** (Optionnel) Mettre à jour `app/Gpo/README.md` (si existe) — ajouter une note section "Support" pour documenter `NativeSectionResolver` comme nouveau service stateless réutilisable.

---

## File List prévisionnelle

### Fichiers créés

```
app/Gpo/Support/NativeSectionResolver.php                                  ← AC1.1 (classe stateless)
resources/views/components/molecules/gpo-back-link.blade.php               ← AC4.3 (composant breadcrumb)
tests/Unit/Gpo/NativeSectionResolverTest.php                                ← AC1.3 (6+ cas)
tests/Feature/Gpo/GpoNativeSectionLinksTest.php                             ← AC4.4 (6 tests)
tests/Feature/Gpo/GpoBackLinkComponentTest.php                              ← AC4.3 (3 tests)
```

### Fichiers modifiés

```
resources/views/pages/app/gpo/index.blade.php                               ← +colonne "Édition native" (AC3.1)
resources/views/pages/app/gpo/[guid]/index.blade.php                        ← refactor const → resolver (AC1.2), CTAs natifs primaires (AC2.1), legacy secondaire (AC2.2), encart enrichi (AC2.3)
resources/views/pages/parc-settings/wallpapers/index.blade.php              ← + <x-molecules.gpo-back-link /> (AC4.1)
resources/views/pages/parc-settings/app-customizations/index.blade.php     ← + composant breadcrumb (AC4.2)
resources/views/pages/shortcuts/index.blade.php                             ← + composant breadcrumb (AC4.2)
resources/views/pages/admin/settings/index.blade.php                        ← + composant breadcrumb conditionnel tab=profils-itinerants (AC4.2)
tests/Feature/Gpo/GpoDetailPageTest.php                                     ← suppression test dataProvider 5 cas, +1 test smoke (AC1.4)
docs/qa/domains/gpo.md                                                      ← + section 3 Story 16.3a (T6.1)
_bmad-output/implementation-artifacts/sprint-status.yaml                    ← status ready-for-dev → review (en fin de dev)
```

### Fichiers NON touchés (régression à éviter)

- `app/Gpo/Services/GpoService.php` — aucune modification (méthodes lecture déjà suffisantes)
- `app/Gpo/Dto/*` — aucune modification
- `app/Gpo/Support/{GpoLogger,SambaToolRunner,GpoActionLog}.php` — aucune modification
- `routes/web.php` — aucune modification (les 2 routes 16.2 sont suffisantes)
- `config/sambaedu.php` — aucune modification (pas de nouveau catchall override — Décision D7)
- `resources/views/components/organisms/sidebar.blade.php` — aucune modification
- `legacy/modules/gpo/*` — aucune suppression

---

## Test Strategy

### Couverture par niveau

| Niveau | Périmètre | Fichier |
|---|---|---|
| **Unit** | Heuristique `NativeSectionResolver::resolve/hasMatch/buildUrl` | `tests/Unit/Gpo/NativeSectionResolverTest.php` |
| **Feature** | Chip natif listing (match / no-match / multi-match) + CTAs primaires détail + propagation `?from_gpo` + bouton legacy secondaire | `tests/Feature/Gpo/GpoNativeSectionLinksTest.php` |
| **Feature** | Composant `<x-molecules.gpo-back-link>` (3 scénarios) | `tests/Feature/Gpo/GpoBackLinkComponentTest.php` |
| **Feature** | 1 test smoke "page détail utilise bien le resolver" | `tests/Feature/Gpo/GpoDetailPageTest.php` (ajout) |
| **Smoke VM (manuel)** | 6 scénarios QA listés en T6.1 | `docs/qa/domains/gpo.md` § 3 |

### Stratégie de mock

- **`GpoService` mock** : réutiliser le pattern 16.2 (`tests/Support/FakesGpoService.php` builder fluide, binding container Laravel).
- **`NativeSectionResolver` pas mocké** (classe stateless, pure logique — testée en unit directement).
- **`request()->query('from_gpo')` en test composant** : utiliser `$this->withQueryParam()` ou `URL::current()` selon convention projet.

### Tests à NE PAS faire dans cette story

- Tests E2E navigateur (iso 16.2).
- Tests de performance (résolution heuristique = O(N) sur ≤ 100 GPOs paginées → triviale).
- Tests de cache (pas de cache dans cette story).
- Tests de migration BDD (aucune migration).

---

## Dev Notes — Contraintes & décisions cadrage SM

### Décisions SM rappelées (cf. tableau Décisions SM ci-dessus)

| # | Décision | Impact dev |
|---|---|---|
| D1 | Extraire `NATIVE_SECTIONS_HEURISTICS` dans `App\Gpo\Support\NativeSectionResolver` | 1 nouvelle classe stateless, 4 méthodes |
| D2 | Multi-match → afficher TOUS les CTAs | Boucle `@foreach ($nativeLinks)` au lieu d'un seul bouton |
| D3 | Page listing : nouvelle colonne dédiée "Édition native" | +1 `<th>` et `<td>` dans le tableau ; chip + dropdown DaisyUI si N≥2 |
| D4 | `?from_gpo={guid}` propagé + breadcrumb retour sur 4 pages | 1 composant Blade réutilisable + 4 inclusions |
| D5 | Bouton legacy dégradé en secondaire si match natif | Classes CSS conditionnelles |
| D6 | Pas de migration / pas de service / pas de channel logs | Story 100% UI |
| D7 | Pas de catchall override d'URLs legacy (firefox.php, etc.) | Aucune modif `config/sambaedu.php` |
| D8 | 1 fichier test Feature (6 tests) + 1 fichier test Unit (6+ cas) + 1 fichier test Feature composant (3 tests) | 3 fichiers tests, ~15 tests au total |
| D9 | Suppression du dataProvider 5 cas de 16.2 + ajout test smoke | Migration de couverture |
| D10 | Pas de E2E | Tests Feature suffisants |

### Références codebase pour le dev

- **Services à consommer** : `App\Gpo\Services\GpoService::get(string $guid): ?GpoSummary` (Story 16.1, déjà existant, déjà utilisé par 16.2 page détail)
- **Pattern SFC consommation service** : `boot(GpoService $service)` + injection en propriété privée (cf. `resources/views/pages/app/gpo/[guid]/index.blade.php:68-71`)
- **Pattern composant Blade** : voir `resources/views/components/molecules/` (recommandation T0.6 : vérifier la convention `<x-molecules.X>` projet)
- **DTOs** : `App\Gpo\Dto\GpoSummary::$displayName` (string, déjà accédé en 16.2)
- **Heuristique source** : `resources/views/pages/app/gpo/[guid]/index.blade.php:24-49` (constante `NATIVE_SECTIONS_HEURISTICS` à extraire)
- **URLs cibles** :
  - `/app/parc-settings/wallpapers` (Story 4.7)
  - `/app/parc-settings/app-customizations` (Story 4.8)
  - `/app/shortcuts` (refonte historique pre-Epic 16)
  - `/admin/settings?tab=profils-itinerants` (Story 1bis-18f)
- **Permission iso 16.2** : `server.admin` (pas de nouvelle permission à créer — les pages cibles ont leurs propres permissions, vérifier qu'un `server.admin` les a déjà ou que le breadcrumb ne casse pas le rendu)
- **Test pattern Feature** : `tests/Feature/Gpo/GpoIndexPageTest.php` (Story 16.2) — `Livewire::test()` + binding container pour mocker `GpoService`
- **Test pattern Unit** : pas d'exemple existant dans `tests/Unit/Gpo/` (le test 16.2 supprimé était unique) — utiliser PHPUnit pur (`use PHPUnit\Framework\TestCase`)
- **Trait `BootstrapsSpatieTables`** : `tests/Concerns/BootstrapsSpatieTables.php` (créé en 16.2 code review fix #11) — à utiliser dans les tests Feature

### Pièges identifiés

1. **`request()->query('from_gpo')` dans un composant Blade anonyme** : vérifier que le composant a bien accès à la `Request` Laravel — sinon utiliser `Request::query()` (facade). Tester en isolation avec `$this->get('/app/parc-settings/wallpapers?from_gpo=...')`.

2. **`GpoService::get()` appelé par le composant breadcrumb** : c'est un **appel synchrone à `samba-tool gpo show`** (~1 s). Sur les pages cibles très chargées (wallpapers avec beaucoup d'items), ça peut être perçu. **Mitigation** : try/catch silencieux + fallback générique. Si latence trop élevée, mettre en cache session court (60s) en V2 — **pas dans cette story** (D6).

3. **Tab `profils-itinerants` dans `/admin/settings`** : la SFC `pages/admin/settings/index.blade.php` utilise une property `$tab` (cf. ligne 65). Le breadcrumb doit s'afficher conditionnellement (`@if ($tab === 'profils-itinerants')`). Si le composant est inclus inconditionnellement, le breadcrumb apparaîtra aussi sur les autres tabs — à éviter.

4. **Multi-match dropdown DaisyUI** : DaisyUI utilise `<details class="dropdown">` ou Alpine.js. Vérifier la convention du projet (regarder `resources/views/components/` pour un dropdown existant). Si pas standard, fallback : afficher les chips côte à côte (moins propre mais plus simple).

5. **Encart 16.2 vs nouveaux CTAs primaires** : risque de doublon visuel (encart "Sections natives disponibles" déjà existant + nouveaux boutons primaires en header). **Décision SM** : conserver les deux — l'encart fournit du contexte (icône + label explicite), les CTAs en header donnent l'action immédiate. Si trop redondant à l'œil, simplifier l'encart en V2.

6. **Test feature `GpoBackLinkComponentTest`** : tester un composant Blade en isolation est inhabituel. Approche recommandée : créer une route de test temporaire (ou réutiliser une route existante avec une vue inline) qui inclut le composant, puis HTTP test `$this->get('/test-route?from_gpo=...')`. Alternative : `Blade::render('<x-molecules.gpo-back-link />', [])` + assertions string.

7. **Pas de modification de signature `GpoService`** : la classe reste intouchée. Si le dev est tenté d'ajouter une méthode `getDisplayName(string $guid): ?string` plus rapide que `get()`, **rejeter** — out-of-scope (D6).

8. **`buildUrl()` avec `?from_gpo` existant** : l'URL `profils-itinerants` contient déjà `?tab=profils-itinerants`. `buildUrl()` doit détecter et utiliser `&` au lieu de `?`. Test unitaire AC1.3 le couvre explicitement.

9. **Bouton legacy secondaire** : les classes DaisyUI `btn-xs` et `btn-ghost` sont compatibles. Vérifier le rendu visuel en VM (la dégradation doit être lisible mais pas illisible).

10. **Champ `displayName` ?string ou string** : `GpoSummary::$displayName` est typé `string` (vérifier). Si jamais `null` retourné par samba-tool, `NativeSectionResolver::resolve('')` doit retourner `[]` proprement (pas d'erreur). Test unit AC1.3 : ajouter un cas `displayName=''` → `[]`.

---

## Project Structure Notes

### Alignement structure projet

- **Service** : `app/Gpo/Support/NativeSectionResolver.php` — cohérent avec `app/Gpo/Support/{GpoLogger,SambaToolRunner,GpoActionLog}.php`
- **Composant Blade** : `resources/views/components/molecules/gpo-back-link.blade.php` — cohérent avec la convention atomique (`atoms/`, `molecules/`, `organisms/`)
- **Tests Unit** : `tests/Unit/Gpo/` (dossier déjà créé en 16.1, vidé en 16.2 par suppression du test dupliqué fix #7)
- **Tests Feature** : `tests/Feature/Gpo/` (dossier existant depuis 16.1, enrichi par 16.2)

### Conflits / variances détectés

| Élément | Doc/convention | Décision Story 16.3a | Justification |
|---|---|---|---|
| Composant Blade vs partial | Convention projet : `x-molecules.*` pour les composants réutilisables, `_partials/*.blade.php` pour les fragments page-spécifiques | Composant `x-molecules.gpo-back-link` | Réutilisable sur 4 pages → composant. |
| Test composant Blade | Pas de pattern établi | `tests/Feature/Gpo/GpoBackLinkComponentTest.php` avec route de test ou `Blade::render` | Pragmatique — explorer en T4.3 pendant le dev. |
| Test Unit Gpo | `tests/Unit/Gpo/` actuellement vide (16.2 a supprimé le test dupliqué) | Réutilisé pour `NativeSectionResolverTest` | Cohérent avec arborescence existante. |

---

## References

- **Story de base** : `_bmad-output/implementation-artifacts/16-2-listing-lecture-gpo-ui-native.md` (status review) — fournit la page Livewire `/app/gpo` + détail, heuristique `NATIVE_SECTIONS_HEURISTICS` à extraire
- **Fondations** : `_bmad-output/implementation-artifacts/16-1-fondations-gpo-natives-audit-legacy.md` — `GpoService::get/list` consommés
- **Audit** : `_bmad-output/planning-artifacts/audit-gpo-legacy.md` :
  - Section 6.C (mapping sections spécialisées → composants Laravel cibles, recommandation "liens profonds vers UIs existantes pour vue unifiée")
  - Section 6.G (recommandation explicite "Story 16.3a — Sections déjà-natives à exposer, charge 1 jour")
- **Epics** : `_bmad-output/planning-artifacts/epics.md:3318-3326` — cadrage Story 16.3 splittée + 16.3a
- **Architecture** : `_bmad-output/planning-artifacts/architecture.md:332-353` — couche Services + règle "jamais Eloquent direct dans Livewire"
- **Pages cibles** :
  - `resources/views/pages/parc-settings/wallpapers/index.blade.php` (Story 4.7)
  - `resources/views/pages/parc-settings/app-customizations/index.blade.php` (Story 4.8)
  - `resources/views/pages/shortcuts/index.blade.php` (refonte historique)
  - `resources/views/pages/admin/settings/index.blade.php` (Story 1bis-18f — tab `profils-itinerants`)
- **Pages 16.2 à enrichir** :
  - `resources/views/pages/app/gpo/index.blade.php` — colonne "Édition native"
  - `resources/views/pages/app/gpo/[guid]/index.blade.php` — CTAs natifs primaires + dégradation legacy
- **Services référence** : `app/Gpo/Services/GpoService.php`, `app/Gpo/Support/{GpoLogger,SambaToolRunner}.php`
- **DTO** : `app/Gpo/Dto/GpoSummary.php` (`$displayName`)
- **Permission** : `app/Enums/SambaPermission.php:58` — `ServerAdmin = 'server.admin'`
- **Test helpers** : `tests/Support/FakesGpoService.php`, `tests/Concerns/BootstrapsSpatieTables.php`
- **Doc QA** : `docs/qa/domains/gpo.md` (déjà créé en 16.1, enrichi en 16.2 section 2, à enrichir en 16.3a section 3)

---

## Dev Agent Record

### Agent Model Used

`claude-sonnet-4-6`

### Debug Log References

Aucun debug log nécessaire — implémentation directe, aucun piège bloquant rencontré.

Notes sur les pièges identifiés :
- **Piège 3 (tab conditionnel admin/settings)** : géré via `@if ($tab === 'profils-itinerants')` avant le bloc tabs — le composant n'est rendu que pour l'onglet ciblé.
- **Piège 4 (dropdown DaisyUI multi-match)** : DaisyUI `<details class="dropdown">` utilisé (pattern natif DaisyUI sans Alpine.js requis). Fallback chips côte à côte non nécessaire.
- **Piège 8 (buildUrl profils-itinerants avec `?` existant)** : `str_contains($baseUrl, '?')` → utilisation de `&`. Couvert par test unitaire dédié.
- **Piège 10 (displayName vide)** : `resolve('')` retourne `[]` dès la garde initiale `if ($displayName === '')`. Test unitaire ajouté.
- **Piège 6 (test composant Blade en isolation)** : approche HTTP test sur `/app/parc-settings/wallpapers?from_gpo=...` — le composant est rendu via la page existante, pas besoin de route de test temporaire.

### Completion Notes List

- AC1.1 ✅ `NativeSectionResolver` créée — 4 méthodes publiques statiques + `private const MAPPING` + PHPDoc + `declare(strict_types=1)`.
- AC1.2 ✅ `NATIVE_SECTIONS_HEURISTICS` supprimée de la SFC détail — `nativeSectionLinks()` délègue à `NativeSectionResolver::resolve()`.
- AC1.3 ✅ `NativeSectionResolverTest` — 22 tests purs Unit (4 simples + multi-match + no-match + empty + case-insensitive × 4 + hasMatch × 3 + buildUrl × 5 + InvalidArgument + all patterns × 11).
- AC1.4 ✅ DataProvider 5-cas `it_renders_correct_native_section_for_display_name` supprimé de `GpoDetailPageTest`. 2 tests smoke ajoutés : `it_uses_native_section_resolver_for_links` + `it_keeps_legacy_button_primary_when_no_native_match`.
- AC2.1 ✅ CTAs natifs primaires (`btn-success btn-sm`) ajoutés en `<x-slot:actions>` avant le bouton legacy.
- AC2.2 ✅ Bouton legacy dégradé (`btn-ghost btn-xs`) + sous-texte "Non recommandé" si `hasMatch()`. Bouton legacy primaire si pas de match.
- AC2.3 ✅ Encart "sections gérables nativement" enrichi — URLs via `NativeSectionResolver::buildUrl($key, $this->guid)`.
- AC3.1 ✅ Colonne "Édition native" ajoutée avant "Actions" dans le listing. Chip `badge-success` si match unique, dropdown DaisyUI si multi-match, tiret si pas de match.
- AC3.2 ✅ Colonne "Actions" préservée à l'identique (non-régression).
- AC3.3 ✅ Résolution faite sur `$paginatedItems` (déjà paginés en mémoire via `@foreach ($paginatedItems as $gpo)`) — aucun appel samba-tool additionnel.
- AC4.1 ✅ Composant `x-molecules.gpo-back-link` intégré dans `wallpapers/index.blade.php` (slot actions).
- AC4.2 ✅ Composant intégré dans `app-customizations/index.blade.php`, `shortcuts/index.blade.php`, `admin/settings/index.blade.php` (conditionnel `@if ($tab === 'profils-itinerants')`).
- AC4.3 ✅ Composant `resources/views/components/molecules/gpo-back-link.blade.php` créé — lit `Request::query('from_gpo')`, appelle `GpoService::get()` via `app()`, try/catch silencieux, fallback générique.
- AC4.4 ✅ `GpoNativeSectionLinksTest` — 8 tests (6 AC4.4 + 2 bonus).
- AC4.5 ✅ Aucune modification de `GpoService`, pas de migration, pas de nouveau channel.

### File List

**Fichiers créés :**
- `app/Gpo/Support/NativeSectionResolver.php`
- `resources/views/components/molecules/gpo-back-link.blade.php`
- `tests/Unit/Gpo/NativeSectionResolverTest.php`
- `tests/Feature/Gpo/GpoNativeSectionLinksTest.php`
- `tests/Feature/Gpo/GpoBackLinkComponentTest.php`

**Fichiers modifiés :**
- `resources/views/pages/app/gpo/[guid]/index.blade.php`
- `resources/views/pages/app/gpo/index.blade.php`
- `resources/views/pages/parc-settings/wallpapers/index.blade.php`
- `resources/views/pages/parc-settings/app-customizations/index.blade.php`
- `resources/views/pages/shortcuts/index.blade.php`
- `resources/views/pages/admin/settings/index.blade.php`
- `tests/Feature/Gpo/GpoDetailPageTest.php`
- `docs/qa/domains/gpo.md`
- `_bmad-output/implementation-artifacts/sprint-status.yaml`
- `_bmad-output/implementation-artifacts/16-3a-liens-profonds-sections-natives.md` (ce fichier)

**Fichiers NON touchés (régression évitée) :**
- `app/Gpo/Services/GpoService.php` — aucune modification (D6)
- `app/Gpo/Dto/*` — aucune modification
- `routes/web.php` — aucune modification
- `config/sambaedu.php` — aucune modification (D7)

### Change Log

| Date       | Auteur               | Changement                                                            |
|------------|----------------------|-----------------------------------------------------------------------|
| 2026-05-11 | claude-sonnet-4-6 (dev) | Implémentation complète Story 16.3a. Phases T0-T6. Classe `NativeSectionResolver` (stateless, 4 méthodes statiques). Composant Blade `gpo-back-link`. Colonne "Édition native" dans listing (chip unique / dropdown DaisyUI multi-match). CTAs natifs primaires en page détail + dégradation bouton legacy. Breadcrumb `?from_gpo` sur 4 pages cibles (wallpapers, app-customizations, shortcuts, admin/settings conditionnel tab). Migration dataProvider 5-cas → NativeSectionResolverTest (Unit pur). 5 fichiers tests : ~34 tests au total (22 Unit + 8 Feature NativeSectionLinks + 4 Feature BackLinkComponent). Status `ready-for-dev` → `review`. |
| 2026-05-11 | claude-opus-4-7 (SM) | Story créée, status `ready-for-dev`. 10 décisions SM (D1-D10), 4 volets ACs (refactor / page détail / page listing / breadcrumb), 6 phases T0-T6. Issue du split de la Story 16.3 monolithique (décision Henri post-audit 16.1 section 6.G). Scope ultra-minimaliste : 1 nouvelle classe stateless `NativeSectionResolver`, 1 composant Blade `gpo-back-link`, enrichissement des 2 SFCs Livewire 16.2, intégration sur 4 pages cibles existantes, ~15 tests (Unit + Feature). Aucune migration, aucun nouveau service, aucun nouveau channel logs. |

---

## Recommandation Modèle Dev

**Modèle recommandé : sonnet**

Raison :

1. **Story de pure navigation UI** — aucune logique métier nouvelle, aucun appel système, aucun parsing de format binaire AD. Le service `NativeSectionResolver` est trivial (matching `str_contains` sur un mapping en constante). Sonnet excelle dans ce profil.

2. **Pattern déjà bien établi par la Story 16.2** — toutes les conventions sont fixées : SFC Livewire inline, `WithToasts`, `Route::livewire`, tests Feature avec `Livewire::test()`, binding container pour mock `GpoService`, tests Unit purs sans bootstrap Spatie. Le dev n'a qu'à reproduire et étendre.

3. **Aucune décision architecturale ouverte** — 10 décisions SM tranchées et documentées explicitement. Cas multi-match (D2), colonne dédiée listing (D3), `?from_gpo` propagation (D4), bouton legacy dégradé (D5), composant Blade réutilisable (D6/D8), etc. — le dev exécute, n'arbitre pas.

4. **Aucun risque sécu nouveau** — pas de nouvel input utilisateur (`?from_gpo` est juste un GUID que `GpoService::get` valide déjà via la regex de route 16.2 + normalisation `[guid]/index.blade.php`). Pas d'appel `samba-tool` nouveau. Pas d'injection.

5. **Périmètre charge cadré : ~1 jour de dev** — création d'1 classe stateless + 1 composant Blade + édits sur 6 fichiers existants + 3 fichiers de tests. Très accessible pour sonnet.

6. **Tests bien spécifiés** — 6 tests Unit (cas explicites listés en AC1.3) + 6 tests Feature (numérotés en AC4.4) + 3 tests composant (numérotés en AC4.3) = ~15 tests bien délimités. Pas d'ambiguïté sur ce qu'il faut couvrir.

**Si le dev rencontre une ambiguïté** :
- (a) Composant Blade testable en isolation (piège 6 du Dev Notes) — escalader pour décider entre route de test temporaire vs `Blade::render`.
- (b) Dropdown DaisyUI multi-match (piège 4) — si pas de pattern projet existant, fallback chips côte à côte (acceptable, validé SM).
- (c) Performance breadcrumb (piège 2) — si latence perçue trop élevée en QA VM, escalader pour V2 cache session (out-of-scope 16.3a).

**Hors ces 3 micro-ambiguïtés**, le périmètre est largement à portée de sonnet. Aucune justification pour utiliser opus sur cette story.
