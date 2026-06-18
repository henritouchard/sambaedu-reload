# Story 27.6 : Catalogue WPKG — source unique depuis le module (fix désync bundle/module + malformation)

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'admin d'établissement,
je veux qu'une application ajoutée au catalogue via le module AppStore atteigne le bundle WPKG que lit réellement le poste, dans un catalogue bien formé,
afin que mes postes installent ce que je leur assigne au lieu de planter sur « Database inconsistency » ou d'ignorer silencieusement mes ajouts.

## Contexte (à lire en premier)

**Bug terrain diagnostiqué en live le 2026-06-18** (poste « windeboule », app `ganttproject` ajoutée via le module AppStore UI). Le poste reçoit :

> `Database inconsistency: Package with ID 'ganttproject' does not exist within the package database`

… alors que son `profiles.xml` (déposé par l'agent depuis la DB SE5 — Story 27.5 / D9) assigne bien `ganttproject`. `notepad++` et `firefox` sont, eux, trouvés et installés. **Cause racine : DEUX catalogues `packages.xml` parallèles et déconnectés, et l'un des deux est malformé.**

**Deux catalogues, jamais reliés :**

| Catalogue | Généré par | Chemin | Sert à | Forme |
|---|---|---|---|---|
| **Bundle servi au poste** | `WpkgBundleGenerator` (27.5) | `config('agent.wpkg_bundle_path')` = `storage/app/public/wpkg/bundle/packages.xml` | ce que le poste **télécharge réellement** depuis Apache statique | sourcé de `resources/wpkg/packages.xml` (**statique, hand-curated** — n'a JAMAIS les apps ajoutées via le module) |
| **Catalogue module** | `PackagesXmlService::regenerate()` | `config('sambaedu.wpkg.packages_xml_path')` = `/var/sambaedu/unattended/install/wpkg/packages.xml` (SMB) | régénéré à chaque ajout/install d'app via l'UI AppStore | **MALFORMÉ** (double `<packages>` imbriqué → 0 package lisible) |

**Preuve que le poste lit le bundle (pas le SMB) :** il trouve exactement le set du bundle (`notepad++`/`firefox`) et installe `notepad++` ; le catalogue SMB étant malformé (0 package lisible côté engine), le poste ne l'utilise pas. Une app ajoutée via l'UI régénère le catalogue **module** (malformé, et de toute façon pas lu) — elle **n'atteint jamais** le bundle que lit le poste → `ganttproject` introuvable.

**La story répare les DEUX bugs et fait du bundle une SOURCE UNIQUE** : (Bug B) corriger l'import DOM de `PackagesXmlService` pour produire un catalogue à plat bien formé ; (Bug A) faire que `WpkgBundleGenerator` source son catalogue depuis le **catalogue module** (une fois bien formé) au lieu du `resources/wpkg/packages.xml` statique, et que l'ajout/retrait d'une app via le module **régénère le bundle**.

**Périmètre Epic 27 / suite 27.5.** Cette story se raccorde directement à 27.5 (qui a livré `WpkgBundleGenerator` + la garde structurelle, statut `review`). Elle ne touche NI le canal agent, NI le contrat agent, NI le handler Go — elle reste **côté serveur de livraison** (génération du catalogue). La garde structurelle de `WpkgBundleGenerator` (RuntimeException si ≠ 1 `<packages>` racine, leçon 2026-06-18) devient le **filet de sécurité** du nouveau sourcing.

**Hors-scope explicite (NE PAS toucher) :** le canal MySQL legacy `packages_xml_out.php` (non porté, supprimé) ; `winget_out`/`linux_out`/`/gpo/*` ; le canal agent / contrat / handler Go ; l'algorithme de résolution `WorkstationPackagesResolver` (réutilisé inchangé). **Ne PAS réintroduire** le canal MySQL legacy (cf. mémoire `project_wpkg_delivery_winget_only_pgsql_vs_legacy_mysql` : le canal classique HTTP/disque pgsql est sain ; seul `packages_xml_out.php` MySQL n'est pas porté).

## Acceptance Criteria

### AC1 — Bug B corrigé : `PackagesXmlService::regenerate()` produit un catalogue à plat bien formé

**Given** N applications installées (`Application::installed()`), chacune portant un recipe `$app->xml` qui est un document complet `<packages><package id="…">…</package></packages>` (racine `<packages>` wrapper)
**When** `PackagesXmlService::regenerate()` régénère le catalogue
**Then** le catalogue résultant a **une seule racine `<packages>`** contenant **N `<package>` enfants DIRECTS** (à plat), pas N `<packages>` imbriqués
**And** la méthode importe les `<package>` **INTERNES** de chaque recipe (pas le wrapper `<packages>` racine), en gérant **les deux cas** de racine : recipe à racine `<packages>` wrapper (cas courant) ET recipe à racine `<package>` directe
**And** le **strip des nœuds SambaEdu non compris du client Windows** (`download`/`delete`/`untar`/`unzip`) est **conservé**, appliqué par `<package>` importé
**And** un recipe XML invalide est **skippé + loggé** (comportement inchangé), sans casser la génération des autres
**And** vérifiable en DOM : `getElementsByTagName('packages')->length === 1` ET le nombre de `<package>` enfants directs de la racine === nombre d'apps installées à recipe valide.

### AC2 — Bug A corrigé : `WpkgBundleGenerator` source son catalogue depuis le catalogue MODULE (source unique)

**Given** Bug B corrigé (le catalogue module est désormais bien formé)
**When** `WpkgBundleGenerator::generate()` construit le bundle servi au poste
**Then** il source le catalogue depuis le **catalogue module** (`config('sambaedu.wpkg.packages_xml_path')`, écrit par `PackagesXmlService`) au lieu de `resources/wpkg/packages.xml`
**And** une application ajoutée au catalogue via le module AppStore **apparaît dans le `packages.xml` du bundle** après régénération du bundle
**And** la **garde structurelle existante** de `WpkgBundleGenerator` (RuntimeException si racine ≠ `<packages>` ou ≠ 1 `<packages>`) **protège** ce sourcing : si le catalogue module redevenait malformé, la génération du bundle **échoue fort et clair** (jamais de faux succès / catalogue inexploitable servi en silence)
**And** la substitution `SE4FS_NAME` (`<variable source="sambaedu">`) **continue de s'appliquer** sur le catalogue ainsi sourcé (inchangée)
**And** l'écriture reste **atomique** (tmp + rename, pattern existant inchangé) et idempotente.

### AC3 — Régénération du bundle déclenchée par l'évolution du catalogue (ajout/retrait d'app)

**Given** une app ajoutée OU retirée du catalogue via le module AppStore (chemin qui appelle déjà `AppStoreService::updateLocalPackagesXml()` → `PackagesXmlService::regenerate()`)
**When** le catalogue module est régénéré
**Then** le **bundle est régénéré dans la foulée** (le catalogue du bundle reste cohérent avec le catalogue module), sans intervention manuelle `php artisan wpkg:bundle`
**And** le déclencheur est **explicite et traçable** (régénération chaînée après `regenerate()` dans le service AppStore, OU via event/listener dédié — voir Dev Notes : décision D3) ; on **ne se repose PAS** uniquement sur l'invalidation du cache resolver (`InvalidateWorkstationPackagesCache`), qui purge le cache des packages par-hôte mais ne régénère PAS le bundle
**And** la régénération du bundle est **résiliente** : si elle échoue (ex. catalogue module malformé détecté par la garde), l'erreur est **loggée sur `wpkg-deploy`** et l'opération d'ajout d'app ne laisse pas un bundle à demi écrit (atomicité préservée) ; le sort du flux appelant (propager l'erreur vs logger+continuer) est tranché en Dev Notes (D4).

### AC4 — Sort de `resources/wpkg/packages.xml` décidé et documenté

**Given** le bundle ne source plus son catalogue depuis `resources/wpkg/packages.xml`
**Then** `resources/wpkg/packages.xml` est **SUPPRIMÉ** (`git rm` — décision D2 tranchée) : il n'est plus la source du catalogue du bundle (Bug A) et son maintien sous ce nom entretiendrait la confusion « deux catalogues ». Les **scripts** `resources/wpkg/*.{js,vbs,cmd}` restent (sourcés VERBATIM). Toute référence test/fixture au fichier est retirée ; le fantôme VM est nettoyé hors-bande (SSH)
**And** les **scripts** `resources/wpkg/{wpkg-se4.js, wpkg-client.vbs, wpkg.cmd}` restent sourcés depuis `resources/wpkg/` (VERBATIM, inchangé — seul le **catalogue** change de source)
**And** la documentation (`docs/wpkg-deploy/architecture.md` ou `resources/wpkg/README.md`) reflète la nouvelle topologie : scripts versionnés ⇒ `resources/wpkg/` ; catalogue ⇒ catalogue module (`PackagesXmlService`).

### AC5 — Tests (DOM bien formé + bundle inclut une app module + non-régression garde)

**Given** la chaîne de génération
**Then** un test prouve que le catalogue régénéré par `PackagesXmlService` a **UNE seule racine `<packages>`** et des `<package>` **à plat** (assertion DOM : `getElementsByTagName('packages')->length === 1` ET enfants directs `<package>` de la racine === N apps installées) — **test qui échouerait sur le code buggé actuel**
**And** un test prouve que le **bundle inclut une app « module »** : après ajout d'une app au catalogue module puis génération du bundle, le `packages.xml` du bundle contient le `<package id="…">` de cette app
**And** un test de **non-régression** prouve que la garde structurelle de `WpkgBundleGenerator` lève toujours `RuntimeException` sur un catalogue malformé (double `<packages>` imbriqué) — la garde reste active et protège le nouveau sourcing
**And** un test prouve que la **substitution `SE4FS_NAME`** s'applique encore sur le catalogue sourcé depuis le module (non-régression de 27.5).

### AC6 — Aucune dette nouvelle ni réintroduction de canal mort

**Given** les corrections livrées
**Then** aucun code livré ne réintroduit le canal MySQL legacy `packages_xml_out.php` (supprimé, non porté)
**And** aucune dépendance AD / Kerberos / `samba-tool` / `LdapRecord` n'est introduite par les changements (`PackagesXmlService` et `WpkgBundleGenerator` restent PG/disque-purs)
**And** la convention storage est respectée : la doc d'exploitation rappelle que **`chown www-admin` (uid 599)** sur le sous-dossier du bundle reste une **action serveur** (sinon serving Apache 404 silencieux — convention storage non versionnée).

## Tasks / Subtasks

- [ ] **T1 — Bug B : corriger l'import DOM de `PackagesXmlService::regenerate()`** (AC1)
  - [ ] Dans `app/Services/AppStore/PackagesXmlService.php::regenerate()` (≈ lignes 37-61) : ne plus faire `$imported = $dom->importNode($fragment->documentElement, true)` puis `$root->appendChild($imported)` sur le **wrapper `<packages>`**. À la place, **itérer sur les `<package>` internes** du recipe et importer/append **chaque `<package>`** sous `$root`.
  - [ ] **Gérer les deux cas de racine du recipe** : (a) racine `<packages>` wrapper (cas courant `$app->xml`) → boucler sur ses `<package>` enfants directs ; (b) racine `<package>` directe → importer cette racine telle quelle. Utiliser `$fragment->documentElement->localName` pour discriminer (ou itérer `$fragment->getElementsByTagName('package')` en se limitant aux `<package>` pertinents — choisir l'approche la plus robuste, documentée en commentaire).
  - [ ] **Conserver le strip** des nœuds SambaEdu (`download`/`delete`/`untar`/`unzip`) — l'appliquer **par `<package>` importé** (boucle interne, comme aujourd'hui mais sur le bon nœud).
  - [ ] Conserver le skip+log d'un recipe XML invalide (comportement inchangé).
  - [ ] Conserver l'écriture atomique (tmp + rename) et le `mkdir -p` du dossier cible.

- [ ] **T2 — Bug A : `WpkgBundleGenerator` source le catalogue depuis le catalogue module** (AC2, AC4)
  - [ ] Dans `app/Wpkg/Deployment/Services/WpkgBundleGenerator.php` : faire pointer la source du **catalogue** (`buildSubstitutedCatalog($catalogPath)`) vers le catalogue module `config('sambaedu.wpkg.packages_xml_path')` au lieu de `$source . DIRECTORY_SEPARATOR . self::CATALOG` (qui résout `resources/wpkg/packages.xml`).
  - [ ] **Les scripts** (`VERBATIM_SCRIPTS`) restent sourcés depuis `resources/wpkg/` (`sourceDir()`) — NE PAS changer (AC4) : seul le catalogue change de source.
  - [ ] Si le catalogue module **n'existe pas encore** (jamais régénéré), définir le comportement : régénérer d'abord via `PackagesXmlService` (le plus sûr — bundle toujours cohérent), OU échouer clairement avec un message explicite. Trancher selon Dev Notes (D5) ; le défaut recommandé = **régénérer le catalogue module si absent** avant de le sourcer.
  - [ ] La garde structurelle (lignes 134-150) protège le nouveau sourcing — **ne pas l'affaiblir** ; elle s'applique désormais au catalogue module (bien formé après T1).
  - [ ] La substitution `SE4FS_NAME` reste inchangée (elle opère sur le DOM du catalogue sourcé, quel qu'il soit).

- [ ] **T3 — Régénération du bundle au changement de catalogue** (AC3)
  - [ ] Chaîner la régénération du bundle après celle du catalogue module. Point d'accroche recommandé : `app/Services/AppStore/AppStoreService.php::updateLocalPackagesXml()` (déjà le point unique d'appel de `PackagesXmlService::regenerate()`) — après `regenerate()`, appeler `WpkgBundleGenerator::generate()`. **Décision D3** : appel direct chaîné (simple, traçable) vs event/listener dédié (`WpkgCatalogChanged` → listener qui régénère le bundle). Trancher en Dev Notes ; défaut = appel chaîné direct dans `updateLocalPackagesXml()` (le module est déjà le point unique).
  - [ ] **Résilience (AC3, D4)** : entourer la régénération du bundle d'un try/catch qui **logge sur `wpkg-deploy`** en cas d'échec (ex. garde structurelle déclenchée). Décider si l'échec du bundle doit faire échouer l'ajout d'app (propager) ou seulement logger (continuer) — défaut recommandé : **logger en error + ne pas casser l'ajout au catalogue** (le catalogue module reste écrit ; le bundle sera recohérent au prochain `wpkg:bundle`/changement), MAIS l'atomicité du bundle (tmp+rename) garantit qu'aucun bundle à demi écrit n'est servi.
  - [ ] Vérifier que `InvalidateWorkstationPackagesCache` (cache resolver par-hôte) **reste inchangé** : il purge le cache des packages par-hôte (resolver), ce n'est PAS le déclencheur du bundle. Ne pas mélanger les deux responsabilités.

- [ ] **T4 — Sort de `resources/wpkg/packages.xml`** (AC4)
  - [ ] **Décision D2 (tranchée : SUPPRESSION)** : `git rm resources/wpkg/packages.xml`. Retirer toute référence (config `sambaedu.wpkg.bundle_source_path` si elle pointe dessus, fixtures/tests). GARDER les scripts `resources/wpkg/*.{js,vbs,cmd}`. S'assurer que la structure de recipe est documentée dans `docs/wpkg-deploy`. NB : nettoyage du fantôme VM hors-bande (SSH ; inotify ne propage pas les deletes).
  - [ ] Documenter la nouvelle topologie : scripts ⇒ `resources/wpkg/` (VERBATIM) ; catalogue ⇒ catalogue module (`PackagesXmlService` → `config('sambaedu.wpkg.packages_xml_path')`).

- [ ] **T5 — Tests** (AC5)
  - [ ] `tests/Unit/Services/AppStore/PackagesXmlServiceTest.php` (ou Feature si DB requise pour `Application::installed()`) : test « catalogue à plat » — recipes wrapper `<packages><package/></packages>` × N → DOM régénéré : `getElementsByTagName('packages')->length === 1` ET N `<package>` enfants directs de la racine. **Ce test échoue sur le code buggé actuel** (il verrait N+1 `<packages>` et 0 `<package>` direct). Ajouter aussi un cas recipe à racine `<package>` directe.
  - [ ] Test « strip conservé » : un recipe avec `<download>/<delete>/<untar>/<unzip>` → ces nœuds absents du catalogue final, par package.
  - [ ] `tests/.../WpkgBundleGenerator…Test.php` (étendre l'existant 27.5) : test « bundle inclut une app module » — catalogue module contenant `<package id="ganttproject">` → bundle généré contient ce `<package id>`. Test « substitution `SE4FS_NAME` toujours appliquée » sur le catalogue sourcé du module. Test de **non-régression de la garde** : catalogue module malformé (double `<packages>`) → `generate()` lève `RuntimeException`.
  - [ ] Surcharger `config('sambaedu.wpkg.packages_xml_path')` et `config('agent.wpkg_bundle_path')` vers des fichiers/répertoires temp dans `setUp` (pas d'écriture sur les chemins de prod).

- [ ] **T6 — Documentation (suit le code)** (AC4, AC6)
  - [ ] `docs/wpkg-deploy/architecture.md` : section sur la **source unique du catalogue** (catalogue module = source de vérité, sourcé par le bundle) + topologie scripts/catalogue + rappel `chown www-admin` (action serveur, sinon serving Apache 404 silencieux).
  - [ ] Rappeler explicitement dans la doc que `packages_xml_out.php` (MySQL legacy) reste **non porté / hors-scope** (ne pas réintroduire).

- [ ] **T7 — Validation (host + /vm)**
  - [ ] **Hôte** : `php -l` sur les fichiers PHP touchés ; `vendor/bin/phpunit --filter PackagesXmlService` et `--filter WpkgBundle` si vendor dispo en local (sinon /vm). Grep de non-régression : aucun `LdapRecord`/`samba-tool`/`packages_xml_out` introduit.
  - [ ] **/vm (action Henri, hors worktree)** : `php artisan wpkg:bundle` après ajout d'une app via l'UI → vérifier que `packages.xml` du bundle contient le `<package id>` de l'app, racine `<packages>` unique, `<package>` à plat (`xmllint`/DOM). E2e : ajouter `ganttproject` via le module → bundle régénéré auto (AC3) → poste « windeboule » converge → plus de « Database inconsistency ». `chown www-admin` sur le sous-dossier bundle. `config:cache` si une clé config change.

## Dev Notes

### Le bug B en DOM (la malformation)

`PackagesXmlService::regenerate()` aujourd'hui (`app/Services/AppStore/PackagesXmlService.php:37-61`) :

```php
$root = $dom->createElement('packages');           // racine du catalogue cible
$dom->appendChild($root);
foreach ($installedApps as $app) {
    $fragment->loadXML($app->xml);                  // $app->xml = <packages><package/></packages>
    $imported = $dom->importNode($fragment->documentElement, true);  // ← importe le <packages> WRAPPER
    // strip download/delete/untar/unzip sur $imported …
    $root->appendChild($imported);                  // ← <packages> dans <packages>
}
```

Résultat : `<packages>` (racine) contenant **N `<packages>`** (un par app) → **0 `<package>` enfant DIRECT** de la racine. L'engine `wpkg-se4.js` lit `getPackages().selectNodes("package")` (= enfants directs de l'unique racine `<packages>`) → il voit **0 package**. Vérifié en DOM PHP sur la VM le 2026-06-18 : « package enfants directs de la racine = (vide) », et **6 `<packages>` pour 5 `<package id>`**.

**Fix** : importer les `<package>` INTERNES de chaque recipe, pas le wrapper. Pseudo-code recommandé :

```php
$srcRoot = $fragment->documentElement;
// recipe peut être <packages><package/>…</packages> (wrapper) OU <package/> (directe)
$packageNodes = $srcRoot->localName === 'package'
    ? [$srcRoot]
    : iterator_to_array($srcRoot->getElementsByTagName('package'));  // ou enfants directs
foreach ($packageNodes as $pkg) {
    $imported = $dom->importNode($pkg, true);
    // strip download/delete/untar/unzip SUR $imported
    foreach (['download','delete','untar','unzip'] as $nodeName) {
        $nodes = $imported->getElementsByTagName($nodeName);
        while ($nodes->length > 0) { $nodes->item(0)->parentNode->removeChild($nodes->item(0)); }
    }
    $root->appendChild($imported);   // <package> à plat sous l'unique racine <packages>
}
```

⚠️ **Subtilité `getElementsByTagName` pendant la mutation** : `getElementsByTagName` retourne une `DOMNodeList` **live**. Le pattern actuel (`while length > 0` + `item(0)`) gère ça correctement pour le strip. Si tu itères les `<package>` du recipe avec `getElementsByTagName('package')`, **matérialise** la liste (`iterator_to_array`) AVANT d'importer/déplacer, pour éviter de muter la liste pendant l'itération. Préférer les **enfants directs** `<package>` du wrapper (un recipe SE4 n'imbrique pas de `<package>` dans un `<package>`) pour rester strict.

### Le bug A (désync) et la source unique

`WpkgBundleGenerator` (`app/Wpkg/Deployment/Services/WpkgBundleGenerator.php:57-100`) source le catalogue via `$source = $this->sourceDir()` (→ `base_path('resources/wpkg')`) puis `buildSubstitutedCatalog($source . '/' . self::CATALOG)`. C'est **`resources/wpkg/packages.xml`** : statique, 4 packages hand-curated (vérifié : `grep -c "<package " resources/wpkg/packages.xml` = 4), **jamais** mis à jour par le module.

Le module, lui, régénère `config('sambaedu.wpkg.packages_xml_path')` = `/var/sambaedu/unattended/install/wpkg/packages.xml` via `PackagesXmlService::regenerate()`, appelé par `AppStoreService::updateLocalPackagesXml()` (`app/Services/AppStore/AppStoreService.php:216-219`), lui-même appelé dans le flux d'install/ajout du module AppStore.

**Fix A** : faire de `WpkgBundleGenerator` une **source unique** = sourcer le catalogue du bundle depuis le **catalogue module** (`config('sambaedu.wpkg.packages_xml_path')`). Une fois Bug B corrigé, ce catalogue est bien formé → la garde structurelle de `WpkgBundleGenerator` (lignes 134-150 : RuntimeException si racine ≠ `<packages>` ou ≠ 1 `<packages>`) **passe** et protège le sourcing : si jamais le catalogue module redevenait malformé, le bundle échoue fort (jamais de faux succès).

> ⚠️ **Ne PAS** sourcer le catalogue du bundle depuis `resolveItemsFor`/`WorkstationPackagesResolver` : le bundle est « pareil pour tous » (catalogue GLOBAL de toutes les apps installées), pas un profil par-hôte. Le profil par-hôte (`profiles.xml`/`hosts.xml`) est déposé par l'agent (27.5/D9). Le catalogue = l'ensemble des `<package>` disponibles ; le profil = la sélection par poste. Garder cette frontière (27.5 Dev Notes « pareil pour tous » vs « custom par-poste »).

### Décisions de cadrage à trancher en dev (avec défauts recommandés)

| # | Décision | Défaut recommandé | Alternative |
|---|----------|---------------------|-------------|
| **D2** ✅ TRANCHÉ (Henri 2026-06-18) | Sort de `resources/wpkg/packages.xml` | **SUPPRESSION** (`git rm`) : ne sert plus de source de catalogue (Bug A) ; le garder sous son vrai nom `packages.xml` est un piège — c'est la confusion « deux catalogues » à l'origine de ce bug. Les **scripts** `resources/wpkg/*.{js,vbs,cmd}` RESTENT (sourcés VERBATIM). Retirer toute référence test/fixture au fichier. La structure de recipe est documentée dans `docs/wpkg-deploy`. NB exploitation : inotify ne propage pas le delete → nettoyer le fantôme `/var/www/sambaedu-reload/resources/wpkg/packages.xml` sur la VM (SSH, hors-bande, accord Henri). | Conserver en seed/.example (REJETÉ : valeur de référence faible, entretient la confusion) |
| **D3** | Déclencheur de régénération du bundle | **Appel chaîné direct** dans `AppStoreService::updateLocalPackagesXml()` (point unique d'appel de `regenerate()` — simple, traçable) | Event `WpkgCatalogChanged` + listener dédié (plus découplé mais plus de surface ; à préférer SI d'autres points écrivent le catalogue module) |
| **D4** | Échec de régénération du bundle | **Logger en error sur `wpkg-deploy` + ne pas casser l'ajout au catalogue** (atomicité tmp+rename garantit qu'aucun bundle à demi écrit n'est servi ; le bundle sera recohérent au prochain changement) | Propager l'exception (rejeté : un ajout d'app ne doit pas planter parce qu'un bundle est temporairement non générable) |
| **D5** | Catalogue module absent au moment de générer le bundle | **Régénérer le catalogue module via `PackagesXmlService` si absent**, puis le sourcer (bundle toujours cohérent) | Échec explicite (acceptable mais moins robuste au 1er run) |

### Convention storage (rappel exploitation)

Le sous-dossier du bundle (`config('agent.wpkg_bundle_path')` = `storage/app/public/wpkg/bundle`) est servi en **statique par Apache** (PAS via Laravel — 27.5/D10). Après toute génération en tant que root, **`chown www-admin` (uid 599)** sur le sous-dossier, sinon serving Apache **404 silencieux** (convention storage non versionnée). Le command `wpkg:bundle` le rappelle déjà ; le chaînage AC3 doit en tenir compte côté exploitation (la régénération via le worker PHP-FPM tourne déjà en www-admin → OK ; seul un run root manuel nécessite le chown).

### Ne pas réintroduire le canal mort (mémoire `project_wpkg_delivery_winget_only_pgsql_vs_legacy_mysql`)

Le canal WPKG **classique** (catalogue `packages.xml` disque/SMB + serving HTTP) est **pgsql-backed et sain** — c'est ce que cette story répare. **Seul** `packages_xml_out.php` (MySQL legacy) n'est **pas porté** et a été supprimé : **ne pas le réintroduire**. `winget_out`/`linux_out` sont des sous-ponts distincts (gatés `WPKG_WINGET_ENABLED`), **hors-scope**.

### Project Structure Notes

- Service module : `app/Services/AppStore/PackagesXmlService.php` (Bug B) — couche Services, jamais dans un controller.
- Service livraison : `app/Wpkg/Deployment/Services/WpkgBundleGenerator.php` (Bug A) — namespace `App\Wpkg\Deployment\*` verrouillé sans LdapRecord/Ad (cf. `tests/Architecture/WpkgDeploymentNamespaceTest.php`).
- Point de chaînage : `app/Services/AppStore/AppStoreService.php::updateLocalPackagesXml()` (T3).
- Tests : `tests/Unit/Services/AppStore/` (PackagesXmlService) ; tests `WpkgBundleGenerator` existants de 27.5 à étendre.
- **Worktree** : ne pas interagir avec la VM depuis un worktree ; les actions /vm (e2e, chown, `wpkg:bundle` réel) sont des actions Henri. Inotify ne propage pas les deletes → si un fichier doit disparaître, signaler à Henri / `trash`, jamais `rm -rf`.

### References

- **Bug B (code)** : `app/Services/AppStore/PackagesXmlService.php:37-61` (`importNode($fragment->documentElement)` → `$root->appendChild`), `:49` (import wrapper), `:52-58` (strip), `:60` (append).
- **Bug A (code)** : `app/Wpkg/Deployment/Services/WpkgBundleGenerator.php:57-100` (`generate`), `:85-90` (catalogue sourcé de `resources/wpkg`), `:107-172` (`buildSubstitutedCatalog` + garde structurelle `:134-150` + substitution `SE4FS_NAME` `:152-164`), `:178-183` (`sourceDir()`).
- **Déclencheur** : `app/Services/AppStore/AppStoreService.php:214-219` (`updateLocalPackagesXml` → `regenerate`) ; `app/Providers/WpkgDeploymentServiceProvider.php:120-150` (listeners cache resolver — NE PAS confondre avec le bundle) ; `app/Wpkg/Deployment/Listeners/InvalidateWorkstationPackagesCache.php` (cache par-hôte, inchangé) ; `app/Console/Commands/WpkgBundleGenerateCommand.php` (`wpkg:bundle`, seul appelant actuel de `generate()`).
- **Config** : `config/agent.php:90` (`wpkg_bundle_path`), `:98` (`wpkg_bundle_url`) ; `config/sambaedu.php:440` (`packages_xml_path` = catalogue module), `:431` (`storage_path`), `:447-450` (deploy_path/SMB).
- **Catalogue source actuel (à dégager)** : `resources/wpkg/packages.xml` (4 packages hand-curated, racine `<packages>` unique) ; scripts `resources/wpkg/{wpkg-se4.js, wpkg-client.vbs, wpkg.cmd}` (restent VERBATIM).
- **Engine client (lecture du catalogue)** : `resources/wpkg/wpkg-se4.js` (`getPackages().selectNodes("package")` = enfants directs de la racine `<packages>`).
- **Architecture WPKG** : `docs/wpkg-deploy/architecture.md` (pipeline, atomic write, channel `wpkg-deploy`, convention `@legacy-port`).
- **Story connexe** : `_bmad-output/implementation-artifacts/27-5-applications-agent-declenche-wpkg.md` (a livré `WpkgBundleGenerator` + la garde structurelle + le bundle statique Apache — statut `review`).
- **Mémoires** : `project_wpkg_delivery_winget_only_pgsql_vs_legacy_mysql` (canal classique pgsql sain, `packages_xml_out.php` MySQL non porté), `project_storage_convention_non_versioned` (sous-dossier bundle, chown www-admin), `project_php_fpm_user_www_admin` (uid 599).

## Dépendances

- **Story 27.5** (`27-5-applications-agent-declenche-wpkg`, statut **`review`**) — a livré `WpkgBundleGenerator`, le bundle statique servi par Apache (D6/D10), la garde structurelle du catalogue (RuntimeException ≠ 1 `<packages>`), la substitution `SE4FS_NAME`, et la config `agent.wpkg_bundle_path`/`wpkg_bundle_url`. Cette story 27.6 **corrige la source du catalogue** de ce générateur et la malformation du catalogue module. **27.6 dépend de l'existence de `WpkgBundleGenerator` et de sa garde** (livrés par 27.5). 27.5 étant en `review` (non `done`), signaler à Henri si la review de 27.5 modifie la signature de `WpkgBundleGenerator::generate()`/`buildSubstitutedCatalog()` (rebase de cette story le cas échéant).
- **Aucune dépendance sur le canal agent / contrat Go** : 27.6 est purement côté serveur de livraison (génération du catalogue), sans bump de contrat ni changement de handler.

## Recommandation Modèle Dev

**Modèle recommandé : `opus`.**

Justification :
- **Deux services PHP couplés** (`PackagesXmlService` Bug B + `WpkgBundleGenerator` Bug A) avec une **décision d'architecture sur la source de vérité** (faire du catalogue module la source unique du bundle, sans casser la frontière catalogue-global / profil-par-hôte de 27.5) — pas une correction mécanique.
- **Manipulation DOM XML subtile** : import des `<package>` internes (deux cas de racine), strip par package, pièges `DOMNodeList` live pendant la mutation — un modèle moins capable risque de réintroduire une malformation ou de casser le strip.
- **Câblage event/déclencheur** (régénération du bundle au changement de catalogue) avec décision résilience/atomicité (D3/D4) et préservation de la garde structurelle existante.
- **Tests croisés** (DOM bien formé qui échoue sur le code buggé actuel + bundle inclut app module + non-régression garde + substitution) demandant de comprendre finement le comportement attendu de l'engine WPKG.
- Cohérent avec les stories Epic 27 précédentes (27.1→27.5), toutes développées en `opus`. `sonnet` serait risqué ici vu la densité DOM + archi ; `opus` est le choix prudent.
