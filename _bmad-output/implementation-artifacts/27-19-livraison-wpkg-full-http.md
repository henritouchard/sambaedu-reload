# Story 27.19: Livraison WPKG full HTTP (payloads servis par Apache, fin du transport SMB)

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an **administrateur d'établissement SE5**,
I want **que les binaires (payloads) des paquets WPKG soient livrés aux postes en HTTP depuis le serveur SE5, comme l'est déjà le catalogue, au lieu de dépendre du partage SMB legacy (`%SOFTWARE%`) qui n'est plus monté**,
so that **un paquet marqué « défaut parc » (ou rattaché à un profil) s'installe réellement sur le poste — aujourd'hui l'install échoue en silence car le binaire n'a aucune route pour atteindre la machine**.

## Contexte & cadrage (décisions Henri, 2026-06-24)

Issu du debug terrain de la story 27.17 : 7za marqué `is_parc_default` a bien été émis en Broadcast côté serveur, mais le poste rapporte « WPKG déclenché mais apps non installées : 7za » (`agent/shared/handler_applications.go:266` — verdict EXACT).

**Cause racine (croisée code + VM)** : installer un paquet = (1) amener le binaire sur la machine, (2) lancer l'install. Le geste (1) est cassé :
- La recette installe par recopie : `<install cmd='xcopy /Y %SOFTWARE%\7-zip\7za.exe %WinDir%\'>` — elle copie un fichier **pré-déposé**.
- En SE5 natif, le bundle HTTP (`/wpkg/bundle`) ne sert QUE `packages.xml` + les 3 scripts — **ni les payloads, ni un `config.xml`**.
- `PackagesXmlService::regenerate()` **strippe** les nœuds `<download>` (visible : nœud vidé dans le bundle servi).
- La variable WPKG `%SOFTWARE%` se déclare dans `config.xml <variables>` (jamais dans `packages.xml`) ; aucun `config.xml` n'est servi → `%SOFTWARE%` **indéfinie**.
- Le montage SMB historique (`MapNetworkDrive`, qui posait `%SOFTWARE%` = racine du partage) est **débranché** : `MapZ()` renvoie un chemin local `c:\windows\install` ([Source: resources/wpkg/wpkg-client.vbs ~663-710, bloc commenté]).
→ Sur le poste : check `%WinDir%\7za.exe` absent → `xcopy %SOFTWARE%\…` (source vide) → échec silencieux → check toujours absent → « non installé ». **Touche TOUT paquet dont `<install>` référence `%SOFTWARE%` ou dépend d'un `<download>`, pas seulement 7za.**

**Décision : aller FULL HTTP** — le poste télécharge chaque payload en HTTP depuis le serveur SE5 (comme il télécharge déjà le catalogue), en réutilisant la capacité de download **native** du moteur WPKG. On NE patche PAS le moteur, on NE réintroduit PAS de montage SMB.

**Pourquoi HTTP plutôt que rétablir SMB** : tout le reste de SE5 est déjà en HTTP (catalogue, bundle, assets, overlay, manifest, binaires agent — GET conditionnel ETag déjà en place) ; le mécanisme SMB en cause (mapping de lecteur en compte SYSTEM) est précisément le maillon fragile/déprécié qui casse ; les payloads sont des installeurs publics (l'absence d'ACL SMB n'est pas une perte). Ce qu'HTTP cède (auth + intégrité natives) est non requis (binaires publics) ou rattrapable (vérif sha en durcissement, HTTPS/jeton plus tard si payloads sensibles).

**Préserve l'intention d'origine de `/noDownload`** ([Source: resources/wpkg/wpkg-client.vbs:219] « on impose /noDownload car les downloads sont gérés côté serveur sur se4 ») : le serveur reste le **point central** qui héberge les binaires ; le poste consomme **du serveur**, jamais d'Internet. On change le transport SMB→HTTP, pas le modèle. C'est pourquoi la réécriture des URLs (T2) est obligatoire : sans elle, retirer `/noDownload` ferait taper les postes sur `deb.sambaedu.org` directement.

### Hypothèses de cadrage (actées)
1. **Greenfield, bascule franche** : aucun parc legacy SMB à maintenir en parallèle (cf. « pas d'état transitoire legacy »).
2. **Sha client différé** : v1 sans vérif sha sur le poste (le serveur vérifie déjà au download via `PackageInstallerService::downloadWithHash`) ; durcissement `certutil` en T4 ultérieur.
3. **Réécriture chirurgicale** (cœur du risque) : ne transformer que les recettes référençant `%SOFTWARE%` ; **EXCLURE** les `untar`/`unzip` déjà extraits côté serveur (sinon on réactiverait le download d'archives non extraites).

## Acceptance Criteria

**Transport HTTP des payloads**
1. Les binaires des paquets WPKG sont accessibles en HTTP depuis le serveur SE5 à une URL publique stable (`/wpkg/files/...`), servie statiquement par Apache depuis l'arbre où `PackageInstallerService` dépose les fichiers (`config('sambaedu.wpkg.storage_path')` + `saveto`). ([Source: app/Services/AppStore/PackageInstallerService.php:66 ; config/sambaedu.php:449])
2. L'alias n'expose QUE l'arbre des binaires paquets (`…/install/packages`), `-Indexes`, jamais `/var/sambaedu/unattended/install` entier, **jamais** `storage/keys/pki`. ([Source: scripts/setupApache.sh:151-156 — modèle `/wpkg/bundle`])

**Réécriture du catalogue (serveur)**
3. `PackagesXmlService::regenerate()` ne strippe plus `<download>` (mais continue de stripper `delete/untar/unzip` = post-traitement serveur). ([Source: app/Services/AppStore/PackagesXmlService.php:82])
4. Pour chaque paquet concerné, le `<download>` conservé est réécrit : `url` ← URL HTTP SE5 dérivée du `saveto` (`http://<SE4FS_NAME>/wpkg/files/<saveto sans préfixe "packages/">`) ; `target` relatif `%TEMP%` ajouté ; `saveto`/`sha256sum`/`md5sum` retirés (non lus par le moteur). La substitution `<SE4FS_NAME>` réutilise le mécanisme existant. ([Source: app/Wpkg/Deployment/Services/WpkgBundleGenerator.php:208-220])
5. Chaque `<install cmd>` référençant `%SOFTWARE%\…` est réécrit en `%TEMP%\<même sous-chemin>` (le moteur télécharge dans `%TEMP%`). ([Source: resources/wpkg/wpkg-se4.js — `getDownloadTarget` lit `target`, `downloadDir=%TEMP%`])
6. **Chirurgical** : seules les recettes référençant `%SOFTWARE%` sont transformées ; les recettes sans `%SOFTWARE%` et les payloads issus de `untar`/`unzip` (déjà extraits serveur via `processUntars`/`processUnzips`) ne voient PAS leur `<download>` réactivé.

**Rallumer le canal client**
7. `/noDownload` est retiré de `WPKG_OPTIONS` dans `resources/wpkg/wpkg-client.vbs:221` ; le bundle est régénéré (`php artisan wpkg:bundle`) et publié. Le moteur télécharge alors chaque payload AVANT d'exécuter l'install (`downloadAll()`), uniquement pour les paquets non conformes (le `<check>` reste le garde d'idempotence). ([Source: resources/wpkg/wpkg-se4.js:5809-5810 ; resources/wpkg/wpkg-client.vbs:221])

**Non-régression & transparence agent**
8. Le `<check>` de chaque recette est INCHANGÉ (ex. `file %WinDir%\7za.exe`) : seule change la SOURCE du `xcopy`. Idempotence préservée (si le fichier cible existe, ni download ni install ne se déclenchent).
9. **Aucun changement de l'agent Go** : pas de modif de `agent/**`, pas de bump `agent/shared/version.go`, pas de changement de contrat/golden/`FROZEN_STATE_HASH`. Le poste récupère le nouveau VBS/catalogue via le bundle HTTP (scripts copiés VERBATIM par `WpkgBundleGenerator`). ([Source: agent/windows/handler_applications_windows.go:20-22 — l'agent ne télécharge ni catalogue ni payload])

## Tasks / Subtasks

- [ ] **T1 — Alias Apache `/wpkg/files`** (AC: 1,2) — ajouter l'alias public (vhost `*:80`, après `/wpkg/bundle`) → `/var/sambaedu/unattended/install/packages` ; `-Indexes`, `Require all granted` ; garde-fou sécurité (jamais l'install entier ni `storage/keys/pki`). `scripts/setupApache.sh`. Doc : conf déployée hors git → runbook.
- [ ] **T2 — Transformation du catalogue** (AC: 3,4,5,6) — dans `PackagesXmlService::regenerate()` : retirer `'download'` du strip ; réécrire `<download>` (url HTTP SE5 + target `%TEMP%`, retirer saveto/sha) ; réécrire les `<install cmd>` `%SOFTWARE%`→`%TEMP%` ; **uniquement** pour les paquets `%SOFTWARE%`, exclure untar/unzip. Substitution `<SE4FS_NAME>` via `WpkgBundleGenerator::buildSubstitutedCatalog`. `app/Services/AppStore/PackagesXmlService.php` (+ éventuel `WpkgBundleGenerator.php`).
- [ ] **T3 — Rallumer le download client** (AC: 7) — retirer `/noDownload` de `resources/wpkg/wpkg-client.vbs:221` ; régénérer le bundle. `resources/wpkg/wpkg-client.vbs`.
- [ ] **T4 — (durcissement, optionnel) vérif sha client** (AC: —) — préfixer une vérif `certutil -hashfile %TEMP%\… SHA256` dans le `<install>` réécrit, alimentée par `$download['sha256sum']`. Reportable. `app/Services/AppStore/PackagesXmlService.php`.
- [ ] **T5 — Tests + doc** (AC: 8,9) — tests unitaires HÔTE de la transformation catalogue (download réécrit, install %TEMP%, exclusions untar/unzip/non-%SOFTWARE%, substitution SE4FS) ; test génération bundle ; doc runbook `docs/qa/domains/wpkg-deploy.md` (append) + `docs/wpkg-deploy/`. `tests/.../PackagesXmlServiceTest.php`.

## Dev Notes

### Capacité du moteur WPKG (décisif)
- `wpkg-se4.js` supporte le download HTTP natif : `download(node)` lit `url`/`target` ([Source: resources/wpkg/wpkg-se4.js:2274-2283]) ; `getDownloads()` collecte les `<download>` du package ET des commandes ([:2620-2636]) ; `downloadAll()` télécharge AVANT les commandes ([:5809-5810]) ; `downloadFile()` fait un GET + `saveToFile` ([:9864-9953]).
- **2 limites dures** : (a) `target` est TOUJOURS relatif à `downloadDir` = `%TEMP%` ([:437, :9875]) et l'attribut lu est `target`, PAS `saveto` ([:2646-2648]) → le payload va dans `%TEMP%\<target>` ; (b) **aucune vérif d'intégrité** (`sha256`/`md5` ignorés). D'où la réécriture `target` + `%SOFTWARE%`→`%TEMP%`, et le sha en T4.
- `/noDownload` ([wpkg-client.vbs:221]) met `noDownload=true` → `getDownloads()` ne retourne RIEN ([wpkg-se4.js:2625-2628]) : tant qu'il est imposé, même un `<download>` non strippé est inerte. Le retrait (T3) « rallume » le canal.

### `%SOFTWARE%` — origine
- Le moteur ne définit aucune variable native ; elles viennent de `config.xml <variables>` → `setEnv()` (var d'env du process) ([Source: resources/wpkg/wpkg-se4.js:2599-2607, 8688-8689, 10921-10943]). Défaut de chargement : `config.xml` adjacent au script ([:2534-2546]).
- Legacy : `%SOFTWARE%` n'est PAS générée côté PHP ; elle venait d'un `config.xml` sur le partage SMB monté (`MapNetworkDrive`, aujourd'hui commenté). SE5 a coupé le montage sans réémettre de `config.xml` → cause racine. **L'option full-HTTP évite tout `config.xml`/`%SOFTWARE%`** en passant par `%TEMP%`.

### Payloads côté serveur
- `PackageInstallerService::downloadFiles()` pose chaque payload à `storagePath . '/' . saveto` = `/var/sambaedu/unattended/install/packages/<...>` ([Source: app/Services/AppStore/PackageInstallerService.php:66 ; config/sambaedu.php:449]). World-readable 664. Emplacement stable et déterministe (= `saveto`) → servable tel quel par l'alias T1. URL : `saveto="packages/7-zip/7za.exe"` → alias mappe `packages/` sur la racine → `http://<se4fs>/wpkg/files/7-zip/7za.exe`.
- Pas d'alias public existant sur ce tree (l'alias `/os` est dans le vhost legacy 127.0.0.1:8082, inaccessible aux postes) → T1 le crée.

### Risques
- **Portée = TOUS les paquets**, pas que 7za : la réécriture doit gérer plusieurs `<download>` par package, install multi-commandes, recettes sans `%SOFTWARE%` (ne pas casser), et surtout les `untar`/`unzip` **déjà extraits serveur** (NE PAS réactiver leur download) — audit recette par recette (AC6).
- **`/noDownload` global** : son retrait réactive les `<download>` de TOUS les paquets. Gardefou : ne conserver/réécrire `<download>` QUE pour les paquets `%SOFTWARE%` (les autres restent inertes ou strippés selon le cas).
- **Substitution `<SE4FS_NAME>`** : placeholder non résolu → download 404 silencieux → même classe de bug qu'aujourd'hui. Tester la substitution.
- **`%TEMP%` du compte SYSTEM** : `wpkg-client.vbs` tourne en SYSTEM → `%TEMP%` = `C:\Windows\Temp`. Vérifier que le download natif y écrit et que `xcopy` y relit (cohérence du compte).
- **Intégrité HTTP clair** : risque MITM LAN → mitigé par T4 (certutil) ; v1 accepte le risque (LAN interne, serveur de confiance, hash déjà vérifié serveur).

### Validation e2e
- **HÔTE** : 7za « défaut parc » → `regenerate()` produit `<download url="http://<se4fs>/wpkg/files/7-zip/7za.exe" target="7-zip\7za.exe"/>` + `<install cmd='xcopy /Y %TEMP%\7-zip\7za.exe %WinDir%\'>` ; `wpkg:bundle` publie ce catalogue + le VBS sans `/noDownload` ; `curl http://<se4fs>/wpkg/files/7-zip/7za.exe` → 200.
- **VM/POSTE** : agent → dépôt profil local → `wpkg-client.vbs` (SYSTEM) → moteur télécharge `7za.exe` en HTTP dans `%TEMP%\7-zip\` → `xcopy` vers `%WinDir%` → `<check>` OK → `wpkg.xml` marque installé → handler relit → rapport **compliant**.
- À tester HÔTE : transformation catalogue (unit), génération bundle, alias (`curl`), substitution SE4FS. À tester VM/poste : download natif HTTP réel en SYSTEM, xcopy depuis `%TEMP%`, `<check>`, remontée `wpkg.xml`, rapport compliant.

### References
- [Source: app/Services/AppStore/PackagesXmlService.php:27-114] — régénération catalogue + strip nœuds
- [Source: app/Wpkg/Deployment/Services/WpkgBundleGenerator.php:208-220] — substitution SE4FS_NAME
- [Source: app/Services/AppStore/PackageInstallerService.php:66, 94-100] — dépôt payload + downloadWithHash
- [Source: resources/wpkg/wpkg-se4.js:2274-2283, 2620-2648, 5809-5810, 9864-9953] — download natif HTTP
- [Source: resources/wpkg/wpkg-client.vbs:219-221, 663-710] — /noDownload + MapZ commenté
- [Source: scripts/setupApache.sh:91-176] — vhost public *:80
- [Source: agent/windows/handler_applications_windows.go:20-22] — agent ne télécharge pas (transparence)

## Dépendances
- 27.5 (livraison native SE5 — bundle Apache, wpkg-se4.js/wpkg.cmd patchés) — DONE
- 27.6 (catalogue source unique bundle⇐module) — DONE
- 27.17 (a exposé le trou ; aucune dépendance fonctionnelle) — DONE

## Recommandation Modèle Dev

**opus** — réécriture programmatique de catalogue XML à portée parc-wide (risque de régression sur TOUS les paquets), exclusions chirurgicales subtiles (untar/unzip server-side), interaction moteur tiers/VBS, sécurité d'alias Apache. Faible surface de fichiers mais invariants délicats.
