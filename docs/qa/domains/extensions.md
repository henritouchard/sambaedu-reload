# QA Manuel — Extensions

**Domaine** : système d'extensions SE5 — registre local multi-sources, manifest déclaratif (contrat public), bibliothèque d'administration et fiches d'extension.

**Stories couvertes** : 54.1 (socle : tables `extension_sources` + `extensions`, enums, validation du manifest v1, synchro de la source embarquée, pages `/admin/extensions` et `/admin/extensions/{id}`, frontière NFR14 avec la sync amont). _Stories 54.2 (intégrer/désinstaller + audit), 54.3 (lanceur « gaufre » navbar), Epics 55/56 (SSO, sources distantes) à ajouter en sections suivantes quand livrées._

**Code de référence** :
- `database/migrations/2026_07_28_100000_create_extension_registry_tables.php` — les 2 tables, branches `jsonb`/`json` et `timestampTz`/`timestamp`, clé naturelle `ext_natural_key`
- `app/Enums/{ExtensionType,ExtensionStatus,ExtensionSourceKind}.php`
- `app/Models/{Extension,ExtensionSource}.php` — `status` volontairement HORS `$fillable`
- `app/Services/Extensions/ExtensionManifestValidator.php` — validation PURE du manifest v1 (version stricte d'abord)
- `app/Services/Extensions/ExtensionCatalogService.php` — `syncBundled()` / `library()` / `find()`
- `app/Exceptions/InvalidExtensionManifestException.php` — porte le champ fautif
- `config/extensions.php` — `bundled_path` (chemin de découverte surchargeable)
- `resources/extensions/doc/manifest.json` — manifest de la tuile Documentation (`link` → `/doc`)
- `database/seeders/BundledExtensionSeeder.php` (+ enregistrement dans `DatabaseSeeder`)
- `resources/views/pages/admin/extensions/index.blade.php`, `resources/views/pages/admin/extensions/[id]/index.blade.php`
- `resources/views/components/organisms/sidebar.blade.php` — entrée « Extensions » du bloc Serveur
- `routes/web.php` — `admin.extensions` et `admin.extensions.show` (groupe admin + `can:server.admin`)
- `tests/Feature/ControlHub/UpstreamSyncExtensionsBoundaryTest.php` — frontière NFR14

---

## Pré-requis communs

- VM SER accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- Migrations à jour : `cd /var/www/sambaedu-reload && php artisan migrate`
- Registre peuplé : `php artisan db:seed --class=BundledExtensionSeeder --force`
- Cache Spatie reset : `php artisan permission:cache-reset`
- Deux comptes :
  - `admin` — détenteur de `server.admin`
  - `enseignant.test` — rôle `prof`, **sans** `server.admin`
- Le site de documentation `/doc` doit être publié (Story 52.1, `bash scripts/update.sh`) pour que la cible de la tuile Documentation réponde.

> **Rappel de périmètre 54.1** : les pages sont en **lecture seule**. Aucun bouton « Intégrer » / « Désinstaller » (Story 54.2), aucun lanceur navbar (Story 54.3), aucune UI d'ajout de source distante (Epic 56). L'état affiché (« Disponible » / « Intégrée ») n'est mutable qu'en base à ce stade.

---

## Section 1 — Registre et source embarquée (Story 54.1)

### Scénario 1.1 — Seed initial de la source embarquée

1. Sur la VM : `php artisan db:seed --class=BundledExtensionSeeder --force`.
2. `php artisan tinker` puis :
   ```php
   \App\Models\ExtensionSource::all(['key','name','kind','url','is_official','enabled'])->toArray();
   \App\Models\Extension::all(['key','name','version','type','status'])->toArray();
   ```

**Attendu** :
- Exactement **une** source : `key = bundled`, `name = "Embarquée (SambaEdu)"`, `kind = bundled`, `url = ""` (chaîne vide, **jamais** `null`), `is_official = true`, `enabled = true`.
- Exactement **une** extension : `key = doc`, `name = Documentation`, `type = link`, `status = available`.
- Aucune erreur, aucun warning `[Extensions]` dans `storage/logs/laravel.log`.

### Scénario 1.2 — Re-seed idempotent (aucun doublon, aucune écriture)

1. Noter `updated_at` de l'extension `doc` :
   `php artisan tinker --execute="echo \App\Models\Extension::where('key','doc')->value('updated_at');"`
2. Rejouer `php artisan db:seed --class=BundledExtensionSeeder --force`.
3. Re-lire `updated_at` et compter les lignes.

**Attendu** :
- Toujours 1 source et 1 extension (pas de doublon).
- `updated_at` **inchangé** : un manifest identique n'écrit rien.
- Log `[Extensions] Synchro de la source embarquée terminée` avec `created: 0`, `updated: 0`, `skipped: 0`, `pruned: 0`.

### Scénario 1.3 — Le `status` n'est jamais réécrit par la synchro

1. Marquer l'extension comme intégrée (54.2 le fera depuis l'UI) :
   ```php
   $e = \App\Models\Extension::where('key','doc')->first();
   $e->status = \App\Enums\ExtensionStatus::Integrated; $e->save();
   ```
2. Rejouer `php artisan db:seed --class=BundledExtensionSeeder --force`.
3. Relire `status`.

**Attendu** :
- `status` reste `integrated`.
- **C'est l'invariant fondateur** : un simple rechargement de catalogue ne doit JAMAIS dé-intégrer une extension que l'admin a intégrée.

### Scénario 1.4 — Manifest disparu : prune borné

1. Créer un second manifest de test sur la VM :
   ```
   mkdir -p /var/www/sambaedu-reload/resources/extensions/demo
   ```
   y écrire un `manifest.json` valide (`id: demo`, `type: link`, `entry_url: /doc`, `visibility.roles: ["admin"]`).
2. `php artisan db:seed --class=BundledExtensionSeeder --force` → l'extension `demo` apparaît (`status = available`).
3. Supprimer le dossier `demo` (`trash` ou `rm -r` selon l'outillage local), puis re-seeder.

**Attendu** :
- L'extension `demo` (`available`) disparaît du registre — log `[Extensions] Extension retirée du catalogue (manifest disparu)`.
- L'extension `doc` est **intacte**.
- Variante : si `demo` avait été passée à `integrated` avant suppression du dossier, elle est **CONSERVÉE** avec un log `[Extensions] Manifest disparu pour une extension INTÉGRÉE — conservée`.

---

## Section 2 — Validation du manifest (Story 54.1, AC2)

> Ces scénarios manipulent des manifests volontairement fautifs sous `resources/extensions/`. **Toujours retirer le manifest de test après le scénario** et re-seeder pour revenir à l'état nominal.

### Scénario 2.1 — Champ obligatoire manquant : rejet nommant le champ

1. Créer `resources/extensions/ko1/manifest.json` sans la clé `entry_url` (le reste valide).
2. `php artisan db:seed --class=BundledExtensionSeeder --force`.
3. Lire `storage/logs/laravel.log`.

**Attendu** :
- Log `warning` `[Extensions] Manifest rejeté — extension ignorée` avec `field: entry_url`, `reason: champ obligatoire manquant`, et le `path` du fichier.
- Aucune ligne `ko1` créée en base.
- **L'extension `doc` est chargée normalement** — un manifest fautif n'en casse aucun autre.

### Scénario 2.2 — Type inconnu

1. Créer `resources/extensions/ko2/manifest.json` avec `"type": "widget"`.
2. Re-seeder, lire les logs.

**Attendu** :
- `field: type`, message citant `widget` **et** la liste des types connus (`link, app`).
- Aucune ligne `ko2` créée ; les autres extensions chargées.

### Scénario 2.3 — Version de manifest non supportée (rejet STRICT)

1. Créer `resources/extensions/ko3/manifest.json` avec `"manifest_version": 2` (tout le reste valide).
2. Re-seeder.
3. Recommencer avec `"manifest_version": "1.0"` puis `"manifest_version": "v1"`.

**Attendu** :
- Les **trois** variantes sont rejetées avec `field: manifest_version` et un message citant la version reçue et les versions supportées.
- **Aucun repli tolérant** : `"1.0"` n'est PAS interprété comme la version 1.
- Seul `1` (entier) ou `"1"` (chaîne numérique) est accepté.

### Scénario 2.4 — La version prime sur le contenu

1. Créer un manifest à la fois hors version (`"manifest_version": 99`) **et** hors domaine (`"type": "widget"`).
2. Re-seeder, lire le log.

**Attendu** :
- La cause rapportée est `manifest_version`, **pas** `type`. Un manifest émis sous une version future ne doit pas être interprété selon les règles de la v1 — sinon la vraie cause est masquée.

### Scénario 2.5 — JSON illisible

1. Créer `resources/extensions/ko4/manifest.json` contenant `{ ceci n'est pas du json`.
2. Re-seeder.

**Attendu** :
- Log `[Extensions] Manifest JSON invalide — extension ignorée` avec le `json_error`.
- Les autres manifests sont chargés ; le seed sort en succès (pas de 500, pas d'exception remontée).

### Scénario 2.6 — Identifiant non-slug

1. Créer un manifest avec `"id": "Mon Ext"` (majuscules + espace).
2. Re-seeder.

**Attendu** :
- Rejet `field: id`, message expliquant la règle de slug (minuscules, chiffres, `_`, `-`).

---

## Section 3 — Bibliothèque `/admin/extensions` (Story 54.1, AC1)

### Scénario 3.1 — Accès et entrée de menu

1. Se connecter en `admin`.
2. Vérifier la sidebar → bloc « Serveur ».
3. Cliquer « Extensions ».

**Attendu** :
- L'entrée « Extensions » (icône pièce de puzzle) est présente sous « Réglages ».
- Elle est **active** (surbrillance) une fois sur `/admin/extensions` et le reste sur `/admin/extensions/{id}`.
- La page s'affiche en 200 avec le titre « Extensions ».

### Scénario 3.2 — Contenu de la bibliothèque

1. Sur `/admin/extensions`.

**Attendu** :
- Une carte « Documentation » avec :
  - l'icône du manifest (`fa-book-open`),
  - l'éditeur « SambaEdu »,
  - un badge de type « Lien »,
  - un badge de source « Embarquée (SambaEdu) »,
  - un badge d'état « Disponible »,
  - la version (`v1.0.0`).
- Un compteur « 1 extension(s) au catalogue — 0 intégrée(s) ».
- La carte est **cliquable** et mène à la fiche.

### Scénario 3.3 — État intégré affiché

1. En base : passer `doc` à `status = integrated` (cf. scénario 1.3).
2. Recharger `/admin/extensions`.

**Attendu** :
- Le badge passe à « Intégrée » (vert) et le compteur à « 1 intégrée(s) ».
- **Aucun bouton d'action** n'apparaît (54.1 est en lecture seule).
- Remettre `status = available` avant de continuer.

### Scénario 3.4 — État vide propre

1. Vider temporairement le registre : `php artisan tinker --execute="\App\Models\Extension::query()->delete();"`.
2. Recharger `/admin/extensions`.

**Attendu** :
- Bloc centré « Aucune extension » avec un texte d'explication — **pas** de grille cassée, pas de section vide, pas d'erreur.
- Re-seeder pour revenir à l'état nominal.

### Scénario 3.5 — Refus sans `server.admin`

1. Se déconnecter, se connecter en `enseignant.test`.
2. Vérifier la sidebar.
3. Taper `/admin/extensions` dans la barre d'URL.

**Attendu** :
- L'entrée « Extensions » n'apparaît **pas** dans la sidebar (bloc Serveur masqué).
- L'accès direct à l'URL est refusé (403 / redirection admin selon le middleware du groupe) — jamais un rendu partiel de la page.

---

## Section 4 — Fiche d'extension `/admin/extensions/{id}` (Story 54.1, AC1)

### Scénario 4.1 — Fiche de la tuile Documentation

1. En `admin`, depuis `/admin/extensions`, cliquer la carte « Documentation ».

**Attendu** :
- Titre « Documentation », flèche de retour vers la bibliothèque (infobulle « Retour à la bibliothèque »).
- Bloc identité : Version `1.0.0`, Éditeur `SambaEdu`, Source « Embarquée (SambaEdu) » + badge « Embarquée » + badge « Officielle », Identifiant `doc`, Cible `/doc`.
- Description issue du manifest.
- Badges « Lien » et « Disponible ».

### Scénario 4.2 — Listes vides rendues proprement

1. Sur la fiche « Documentation » (`scopes: []`, `dependencies: []`).

**Attendu** :
- Section « Autorisations demandées » : compteur `0` + phrase « Aucun scope demandé. »
- Section « Dépendances » : compteur `0` + phrase « Aucune dépendance. »
- **Aucune** section cassée, aucune liste à puces vide, aucun `[]` affiché brut.

### Scénario 4.3 — Scopes et dépendances non vides

1. Créer un manifest de test `resources/extensions/demo2/manifest.json` avec
   `"scopes": ["profile","groups"]` et `"dependencies": ["doc"]`.
2. Re-seeder, ouvrir la fiche de `demo2`.

**Attendu** :
- Les 2 scopes et la dépendance apparaissent en badges monospace.
- Le texte de la section rappelle que **rien n'est accordé aujourd'hui** (information de transparence).
- Nettoyer le manifest de test et re-seeder.

### Scénario 4.4 — Public visé (rôles métier)

1. Sur la fiche « Documentation ».

**Attendu** :
- Section « Public visé » listant `admin`, `prof`, `eleve` — les rôles **métier** du manifest, jamais des permissions applicatives.
- Le texte précise que l'autorisation réelle reste du ressort de l'extension.
- ⚠️ 54.1 **stocke** cette visibilité ; c'est la Story 54.3 qui la **résout** dans le lanceur.

### Scénario 4.5 — Identifiant inconnu → 404

1. Ouvrir `/admin/extensions/999999`.

**Attendu** : page 404 SE5 (pas une 500, pas une fiche vide).

### Scénario 4.6 — Identifiant non numérique → 404 de routage

1. Ouvrir `/admin/extensions/abc`.

**Attendu** : 404 — la route est bornée par `whereNumber('id')`, la requête n'atteint jamais le composant.

### Scénario 4.7 — Refus sans `server.admin`

1. En `enseignant.test`, ouvrir directement `/admin/extensions/1`.

**Attendu** : refus (403 / redirection admin), jamais l'affichage de la fiche.

---

## Section 5 — Frontière avec la sync amont (Story 54.1, AC3 / NFR14)

> **Pourquoi cette section existe** : le catalogue applicatif LOCAL a déjà été effacé par la sync amont sur ce projet. Le registre d'extensions doit rester hors de portée de cette chaîne. L'isolement est **par construction** (aucune FK, aucun listener, aucun service commun) et verrouillé par `tests/Feature/ControlHub/UpstreamSyncExtensionsBoundaryTest.php` — cette section est le contrôle manuel de dernier recours avant mise en production.

### Scénario 5.1 — Ingestion d'un contrat amont : registre intact

1. Noter l'état du registre :
   ```php
   \App\Models\ExtensionSource::all(['id','key','updated_at'])->toArray();
   \App\Models\Extension::all(['id','key','status','updated_at'])->toArray();
   ```
2. Déclencher une réception de contrat amont (canal controlHub réel ou
   `ControlHubContractIngestionService::ingest($payload)` en tinker), avec un
   catalogue d'apps **ne contenant pas** les clés locales.
3. Re-lire l'état du registre.

**Attendu** :
- Lignes, `status` et `updated_at` **strictement identiques**.
- La cascade amont a bien tourné par ailleurs (groupes imposés créés / apps hors catalogue retirées, selon le contrat) — c'est ce qui rend l'observation significative.

### Scénario 5.2 — Rupture du lien amont : registre intact

1. Noter l'état du registre.
2. `php artisan controlhub:sever-link` (ou le canal API de rupture).
3. Re-lire l'état du registre.

**Attendu** : registre inchangé ; le lien est bien passé à `severed` et la transition est tracée dans `controlhub_link_audit_logs`.

### Scénario 5.3 — Application d'un manifeste de sync : registre intact

1. Noter l'état du registre.
2. Appliquer un manifeste de sync **vide** (branche `pass3Cleanup`, la plus destructive).
3. Re-lire l'état du registre.

**Attendu** : registre inchangé, y compris les `updated_at`.

---

## Section 6 — Correctifs de review 54.1

> Ajoutée après la review de la Story 54.1. Deux durcissements du chargement de la source embarquée.

### Scénario 6.1 — Racine des manifests introuvable : le catalogue est PRÉSERVÉ

**Contexte** : accident de déploiement — `resources/extensions/` absent de l'arbre livré, `EXTENSIONS_BUNDLED_PATH` mal résolu, ou `config:cache` figé sur un chemin d'une autre machine.

1. Partir d'un registre peuplé (`php artisan db:seed --class=BundledExtensionSeeder`), vérifier la bibliothèque non vide sur `/admin/extensions`.
2. Pointer la config sur un chemin inexistant : `EXTENSIONS_BUNDLED_PATH=/tmp/nexistepas` puis `php artisan config:clear`.
3. Rejouer le seed.

**Attendu** : la synchro sort en **no-op** — `pruned: 0`, `loaded: 0`. Un `Log::warning` « Racine des manifests embarqués introuvable — synchro ignorée (catalogue PRÉSERVÉ) » cite le chemin fautif. **La bibliothèque affiche toujours ses extensions.**

**Pourquoi ce scénario existe** : avant correctif, une racine absente et une racine vide étaient indistinguables (`[]` dans les deux cas) — le prune ne voyait aucune clé « vue » et **supprimait tout le catalogue embarqué `available`**. C'est le sinistre déjà vécu sur le catalogue applicatif local, rejoué sur le registre d'extensions.

### Scénario 6.2 — Racine présente mais vidée : le prune s'applique bien

**Contre-épreuve du 6.1** : la garde ne doit pas neutraliser le prune légitime.

1. Registre peuplé, puis supprimer les dossiers de manifests **en gardant** `resources/extensions/`.
2. Rejouer le seed.

**Attendu** : les lignes `available` disparues sont bien supprimées (`pruned` > 0), les `integrated` conservées (cf. 1.4).

### Scénario 6.3 — Un objet JSON n'est pas une liste

Déposer un manifest avec `"visibility": {"roles": {"a": "admin"}}` (objet au lieu de tableau), puis un autre avec `"scopes": {"x": "profile"}`.

**Attendu** : les deux sont **rejetés**, log nommant `visibility.roles` / `scopes`. Avant correctif ils étaient acceptés et ré-indexés silencieusement en `["admin"]` / `["profile"]`.

**Portée réelle** : sans effet sur la source embarquée (dépôt contrôlé) — décisif dès l'**Epic 56**, quand des sources distantes fourniront des manifests non contrôlés.

---

## Post-correctifs & non-régressions

- **Section 1.3 / 5.x — « le catalogue local effacé par la sync amont »** : incident réel du projet sur `applications`. Le registre d'extensions est isolé par construction ; les scénarios 1.3 (le `status` survit à un re-seed) et 5.1→5.3 (la sync amont ne touche pas les tables) sont les deux faces du même garde-fou. Toute story future qui ajouterait un listener ou une FK entre les deux mondes doit faire échouer `UpstreamSyncExtensionsBoundaryTest`.
- **Section 2.4 — la version prime sur le contenu** : décision reprise de la Story 33.2 (négociation du schéma d'échange amont). Un rejet de contenu sur un manifest de version future masquerait la vraie cause et ferait perdre du temps à l'admin.
- **Section 1.1 — `url = ""` et jamais `null`** : une colonne nullable participant à une clé ou une contrainte casse l'unicité (NULL distinct de NULL en PostgreSQL **comme** en SQLite). Même règle pour `publisher`, `icon`, `description`, `version`.
- **Section 6.1 — racine introuvable ≠ catalogue vide** : correctif de review 54.1. La distinction est portée par `discoverBundledManifestPaths()` qui renvoie désormais `null` (rien observé) au lieu de `[]` (observation légitime). Toute évolution du chargement de source — en particulier les sources **distantes** de l'Epic 56, où « source injoignable » est un cas nominal (NFR7) — doit reconduire cette distinction : **une source qu'on n'a pas pu lire ne prune rien**.
- **Section 6.3 — strictness du contrat manifest** : correctif de review 54.1. `array_is_list()` sur `visibility.roles`, `scopes`, `dependencies`. Le validateur affiche une philosophie de rejet strict (décision #1, iso-33.2) ; accepter un objet JSON ré-indexé la contredisait.
- **Section 4.2 — listes vides** : exigence explicite de l'AC1. Une section « Autorisations demandées » vide et muette laisserait penser à un bug d'affichage plutôt qu'à une extension sans scope.

---

## Checklist rapide

- [ ] 1.1 Seed initial : 1 source `bundled` (`url` vide, pas `null`) + 1 extension `doc`
- [ ] 1.2 Re-seed idempotent : aucun doublon, `updated_at` inchangé
- [ ] 1.3 Re-seed : le `status = integrated` survit
- [ ] 1.4 Manifest disparu : prune du `available`, conservation de l'`integrated`
- [ ] 2.1 Champ manquant : log nommant le champ, les autres manifests chargés
- [ ] 2.2 Type inconnu : log citant le type reçu et les types connus
- [ ] 2.3 Version non supportée : rejet strict (`2`, `"1.0"`, `"v1"`)
- [ ] 2.4 Version rejetée AVANT le contenu
- [ ] 2.5 JSON illisible : ignoré, seed en succès
- [ ] 2.6 Identifiant non-slug : rejet `field: id`
- [ ] 3.1 Entrée sidebar « Extensions » présente et active
- [ ] 3.2 Carte complète (nom, icône, type, éditeur, source, état, version)
- [ ] 3.3 État « Intégrée » affiché, aucun bouton d'action (54.1 = lecture seule)
- [ ] 3.4 Registre vide : état vide propre
- [ ] 3.5 Sans `server.admin` : entrée masquée + accès direct refusé
- [ ] 4.1 Fiche complète alimentée par le manifest
- [ ] 4.2 « Aucun scope demandé. » / « Aucune dépendance. »
- [ ] 4.3 Scopes et dépendances non vides affichés en badges
- [ ] 4.4 Public visé = rôles métier
- [ ] 4.5 Identifiant inconnu → 404
- [ ] 4.6 Identifiant non numérique → 404 de routage
- [ ] 4.7 Fiche refusée sans `server.admin`
- [ ] 5.1 Ingestion de contrat amont : registre intact
- [ ] 5.2 Rupture de lien : registre intact
- [ ] 5.3 Manifeste de sync vide : registre intact
- [ ] 6.1 Racine des manifests introuvable : catalogue PRÉSERVÉ, warning explicite
- [ ] 6.2 Racine présente mais vidée : prune légitime toujours actif
- [ ] 6.3 `visibility.roles` / `scopes` en objet JSON : rejetés
