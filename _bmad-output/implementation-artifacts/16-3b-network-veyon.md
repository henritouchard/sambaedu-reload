# Story 16.3b : Réécriture native `network_out.php` + `veyon_out.php` (endpoints runtime)

Status: review

## Corrections post-review (2026-05-12)

> Document de review : `_bmad-output/codeReviews/16-3b.md` (Sonnet + second avis Opus, 5 blockers/important corrigés).
> Application des **décisions produit Henri** :

### Décisions Henri appliquées

| # | Décision | Implémentation |
|---|----------|----------------|
| Option A complète (#1+#2) | Implémenter `AdUserManager` natif réutilisable (`create`/`exists`/`setPassword`/`validatePassword`) **ET** `SambaEduConfig::set` natif (avec lock `flock` + write atomique tmp/rename). | `app/Ldap/AdUserManager.php` + extension `app/Config/SambaEduConfig.php`. Shims `legacy/ldap.inc.php:1245+1619` délèguent désormais aux services natifs si dispo (`app()->bound(...)`). |
| Bootstrap iso-legacy | Création AD `read.user` toujours déclenchée à la 1ère requête `veyon_out.php` (pas de commande artisan séparée). | `ReadUserManager::ensurePassword()` inchangé côté trigger ; utilise `AdUserManager` + `SambaEduConfig::set` natifs en interne. |
| HTTP 200 strict (#4) | `os=windows` + context expiré + cas dégénérés → `200 body=""` (pas 204). | Helper `emptyOk()` dans les deux Controllers. Tests Feature mis à jour (`assertOk()->assertSame('', ...)`). |
| Diff structurel Veyon (#7) | `VeyonOutComparisonTest` implémente le diff JSON décodé avec `unset BindPassword` des deux côtés. Skip tant que fixture absent. | `tests/Feature/Gpo/VeyonOutComparisonTest.php` réécrit. |

### Fichiers créés

- `app/Ldap/AdUserManager.php` — Service natif AD réutilisable via `SambaToolRunner` (mode array, échappement shell sécurisé, channel `gpo` pour audit AD).
- `tests/Unit/Ldap/AdUserManagerTest.php` — 15 tests Unit (mock SambaToolRunner).
- `tests/Unit/Config/SambaEduConfigSetTest.php` — 9 tests Unit (write atomique, removal, escaping, cache invalidation).

### Fichiers modifiés

- `app/Config/SambaEduConfig.php` : ajout méthode `set(string $key, mixed $value): void` avec lock `flock(LOCK_EX)`, write atomique `.tmp` + `rename()`, invalidation cache statique.
- `app/Gpo/Services/ReadUserManager.php` : refactor pour injecter `AdUserManager` + utiliser `SambaEduConfig::set`. `createReadUserUnderLock` redevenue `private`. Drift recovery échec → `null` (option B Henri ; cf. fix #M1).
- `app/Http/Controllers/Gpo/NetworkOutController.php` : `empty204()` → `emptyOk()` (200 body=""). `Log::info` → `Log::debug` sur context expiré (fix #M4).
- `app/Http/Controllers/Gpo/VeyonOutController.php` : idem `emptyOk()` (Content-Type retiré, fix #5). `Log::info` → `Log::debug` (#M4). `serveLicence` fallback : `Cache-Control: no-store` ajouté sur les 2 returns vides (#M3).
- `app/Gpo/Services/NetworkScriptGenerator.php` : `networkCreateScript` `public` → `private` (#8).
- `legacy/ldap.inc.php` : stubs `create_ad_user` (lignes 1245+) et `set_config` (lignes 1619+) délèguent désormais aux services natifs `AdUserManager` / `SambaEduConfig::set` si bindable, sinon log shim.
- `tests/Unit/Gpo/ReadUserManagerTest.php` : mock `AdUserManager` au lieu de sous-classes stub. Nouveau test `it_returns_null_when_drift_recovery_fails` (#M1) + `it_treats_existing_ad_user_as_create_success` (idempotence anti-race).
- `tests/Feature/Gpo/NetworkOutEndpointTest.php` : tests renommés `it_returns_empty_ok_*`, assertions `assertOk()` + body vide. Ajout `it_writes_debug_file_to_tmp` (#6 AC1.7), `it_applies_throttle_300_per_minute` (#6 AC4.1).
- `tests/Feature/Gpo/VeyonOutEndpointTest.php` : assertions HTTP 200 + ajout `it_creates_read_user_when_password_missing` (#6 AC2.5), `it_calls_ensure_password_exactly_once_per_request`.
- `tests/Feature/Gpo/NetworkOutSecurityTest.php` : assertions 200 au lieu de 204.
- `tests/Feature/Gpo/VeyonOutComparisonTest.php` : diff structurel concret (plus de `markTestIncomplete`).
- `docs/tech-debt-gpo.md` : 4 nouvelles entrées (#3 `set_config die()`, #M2 `toLegacyArray`, #M5 `MachineKeyResolver` injectable, duplication test `SambaEduConfigSetTest`).
- `docs/qa/domains/gpo.md` : scénarios QA enrichis pour la création AD native + drift recovery (cf. section 4 « Post-correctifs »).

### Points résiduels / tech-debt acceptés

- **`SambaEduConfig::toLegacyArray()`** absent : les shims qui consommaient `$config['dn']['people']` nested n'ont pas d'équivalent natif. Story 16.4 (#M2).
- **`MachineKeyResolver` injectable** : `NetworkOutComparisonTest` peut encore tenter un `ssh pdbedit` en CI si le fixture est présent. Story 16.4 (#M5).
- **Duplication logique `set()` dans test** : la const `MAIN_CONFIG_FILE` étant privée, la sous-classe `TestableSambaEduConfig` duplique la logique. Refactor en propriété d'instance prévu Story 16.4.
- **Validation `AdUserManager::validatePassword`** : utilise `ldap_bind` natif PHP fail-open si client absent. Suffisant pour la drift detection mais à durcir si on étend l'usage.

## 🎯 Pré-tranchements Henri (2026-05-12)

> Henri a tranché en amont les 5 items "À TRANCHER pendant le dev" pour simplifier le brief. **Le dev applique les règles ci-dessous, justifie en commentaire de code, ne re-discute pas pendant le dev.** En cas de blocage technique réel (pas de blocage de design), il documente dans la story et continue.

| Item d'origine | Tranchement Henri | Règle |
|---|---|---|
| **D4 — API native vs shim `@legacy-port`** pour `create_ad_user` / `usersetpassword` / `user_valid_passwd` | **Fallback shim `@legacy-port` autorisé d'office** si API native absente | Pas de re-design AD natif dans cette story. Appel via `legacy/bootstrap.php` avec docblock `@legacy-port` + `@todo Story 16.4` (extraction native). Priorité = iso-fonctionnel. |
| **T0.7 — `SambaEduConfig::set` natif vs shim `set_config`** | **Utiliser celui qui existe et fonctionne**. Préférence native si dispo, sinon shim. | Vérification rapide en T0 (5 min `grep -r "SambaEduConfig::set" laravel/app`). Pas de débat. |
| **Piège 1 — `pdbedit ssh` injection** (`network.inc.php:23`) | **Iso-legacy strict** (regex d'échappement legacy reproduite à l'identique) | Pas de remplacement par `samba-tool user getpassword` dans cette story. Si vulnérabilité confirmée → TODO + signaler dans le rapport dev. |
| **Piège 11 — `os=windows` body vide (bug legacy)** | **Reproduire iso (body vide)** | Pas d'amélioration silencieuse. Documenter le bug legacy dans un commentaire `// @legacy-bug os=windows renvoie vide — non corrigé volontairement (Story 16.3b iso-fonctionnel)`. |
| **AC2.6 — Échec création AD `read.user`** | **Renvoyer la config JSON sans `BindPassword`** (option B) | Pas de 503 Retry-After. Le client Veyon échoue proprement et retry au prochain logon — cohérent avec le pattern "best effort" du legacy. Log error level. |

**Règle générale Henri** : **iso-legacy par défaut**. Le dev n'invente pas de comportement. Si une décision n'est pas couverte ici, il privilégie systématiquement le comportement du PHP procédural d'origine et documente le choix en commentaire.

---

> Sous-story issue du split de la Story 16.3 (décision Henri post-audit 16.1 §6.G). **Périmètre = 2 endpoints HTTP runtime** consommés par les postes clients via les GPO `se4_applications` (logon/startup) — **PAS des pages d'édition admin**. Iso-pattern Stories 4.7 (`WallpaperController::legacyOut`), 4.8 (`AppPolicyController::legacyFirefoxOut`), `ShortcutExportController::legacyDispatch`.

---

## ⚠️ Recadrage scope vs prompt SM initial

> Le brief initial laissait entendre que `network_out.php` / `veyon_out.php` étaient des **pages d'édition de sections "Proxy" / "Veyon" d'une GPO** (avec édition de form admin, persistance via `GpoService` stubs écriture, etc.). **Lecture du legacy + audit 16.1 §6.A** :
>
> - `legacy/modules/gpo/network_out.php` (54 lignes) = endpoint HTTP `GET|POST /gpo/network_out.php?action=startup|logon&os=…&id=…` qui renvoie **un script bash** (`text/plain`) consommé par le poste client au boot/logon pour configurer le proxy GNOME et le réseau (WPA/802.1x). **Aucune UI admin**.
> - `legacy/modules/gpo/veyon_out.php` (141 lignes) = endpoint HTTP `GET|POST /gpo/veyon_out.php?id=…[&licence=1]` qui renvoie **un JSON de configuration Veyon** (`application/json`) consommé par le client Veyon installé sur le poste pour ses bindings LDAP, ACL, groupes autorisés. **Aucune UI admin**.
> - **Aucun de ces endpoints ne mute une GPO** dans l'AD/SYSVOL — ils ne consomment **pas** les stubs écriture `GpoService` (`create/delete/setLink/...`). Le brief SM était erroné sur ce point. Voir Audit §6.A fiches `gpo/network_out.php` ligne 348 et `gpo/veyon_out.php` ligne 316.
> - Stratégie cible **audit §6.G ligne 631** : "Réécriture native de `/gpo/network_out.php` et `/gpo/veyon_out.php` en **Controllers Laravel + services**. Bloquer les URLs legacy correspondantes via catchall. Tests d'intégration : un poste Veyon réel consomme bien la config générée."
>
> **Pattern à reproduire = Stories 4.7/4.8** (interception iso-contrat via `Route::match([GET,POST], 'gpo/X.php', [...])` + Controller dédié + `AppContextRepository` pour résoudre le contexte APCu `apps.$id` posé par `applications.php`). **PAS** le pattern listing/édition Livewire `/app/gpo`.

---

## Story

As **un poste client Linux/Windows joint au domaine SE4FS**,
I want continuer à recevoir, depuis l'URL legacy `/gpo/network_out.php` (script bash proxy/802.1x au startup/logon) et l'URL legacy `/gpo/veyon_out.php` (config JSON Veyon), une réponse iso-contrat du legacy **alors même que le PHP procédural a été retiré du code natif Laravel** (interception côté Laravel par des Controllers natifs),
So que le déploiement des postes (config réseau au boot, config Veyon supervision classe) reste **strictement iso-fonctionnel pendant la transition Epic 16**, sans avoir à modifier la GPO `se4_applications` qui déclenche ces appels HTTP côté client.

---

## Contexte

Les postes Linux/Windows joints au domaine appellent ces 2 endpoints via la GPO `se4_applications` (script de logon/startup) — **leur URL est en dur dans des scripts déjà déployés sur des centaines de postes**. Toucher la GPO côté AD pour migrer les URLs (`/api/v1/network/script` ou autre) impliquerait un re-déploiement complet du parc. **Iso-contrat URL obligatoire** (cf. Story 4.7 AC 7 "appelable telle quelle par les postes en place", Story 4.8 AC 9 "iso-contrat legacy").

Aujourd'hui, ces 2 endpoints sont **shimés byte-identique** dans `legacy/modules/gpo/network_out.php` et `legacy/modules/gpo/veyon_out.php` (Story 1bis-18e). Ils sont servis par `LegacyCatchallController` qui charge `legacy/bootstrap.php` (charge `samba-tool.inc.php`, `gpo.inc.php`, etc.) puis `require`-include le fichier PHP procédural. **Cette story remplace l'exécution PHP procédurale par 2 Controllers Laravel natifs** sans changer l'URL publique.

### Pourquoi 2-3 jours (vs ~1j pour 16.3a)

- **`veyon_out.php` : effet de bord critique** — la 1ʳᵉ requête sur cet endpoint peut **créer un compte AD `read.user<suffix>`** + persister le mot de passe dans `config['read_ldap_password']` (`legacy/modules/gpo/veyon_out.php:29-50`). C'est une **mutation AD/config**. À porter avec précaution (lock anti-race, isolation auth).
- **Chiffrement OpenSSL PKCS1_OAEP** du mot de passe LDAP — format binaire consommé par le client Veyon (`bin2hex(openssl_public_encrypt(...))`). Iso-binaire requis.
- **Génération bash multi-OS** côté `network_out.php` — WPA-PSK, 802.1x filaire, 802.1x wifi, proxy GNOME, proxy système. Sortie consommée littéralement par `bash`.
- **Tests intégration Veyon réel** demandés par le sprint (`16-3b-network-veyon: backlog # ... Tests intégration Veyon réel`) — au-delà des tests Feature classiques, vérifier qu'un client Veyon parse bien le JSON produit (test smoke VM avec poste Veyon réel).
- **Catchall override** : ajouter les 2 URLs aux interceptions natives + ne PAS oublier le `licence=1` sous-cas de `veyon_out.php` (renvoie `licence.vlf` binaire).

### Recadrage scope vs cadrage initial

Le brief SM initial liste plusieurs questions inadaptées à la nature runtime des endpoints (édition `network`/`veyon` d'une GPO, persistance via `samba-tool gpo set`, validation URL proxy, etc.). Ces aspects **ne concernent PAS cette story**. Les vraies décisions sont listées dans la section **Décisions SM (D1-D11)** ci-dessous, recentrées sur les enjeux réels (iso-contrat URL legacy, side effects AD du veyon endpoint, sources de config natives).

---

## Garde-fous Epic 16 (rappel — applicables à cette story)

- **AD = source de vérité** : aucune table Eloquent créée par cette story. La création du compte `read.user` (si confirmée — voir **D4**) passe par le service AD natif existant (cf. `LdapModels`/`samba-tool` ou shim 1bis-18g `modify_ad(gpo)`).
- **Trois couches** (`architecture.md:343-353`) : Controllers fins ; logique métier dans **Services** dédiés (`NetworkScriptGenerator`, `VeyonConfigGenerator`) ; pas d'`exec()` direct dans les Controllers ; lecture LDAP via `LdapRecord` ou shim `search_ad`.
- **Logging verbeux Epic 16** : pas d'utilisation du channel `gpo` ici. Ces endpoints sont **runtime postes clients** — ils sont loggués via le channel `daily` standard (`Log::channel('daily')` ou `Log::info(...)` par défaut) **comme** le font `AppPolicyController` (`Log::error('[AppPolicyController] resolve failed', ...)`) et `WallpaperController`. **Décision SM D9 ci-dessous**.
- **Iso-contrat URL legacy** : les URLs `/gpo/network_out.php` et `/gpo/veyon_out.php` **doivent rester invariantes**. Le routage natif les intercepte exactement comme `gpo/firefox_out.php` / `gpo/wallpaper_out.php` / `gpo/shortcuts_out.php` (déclaration AVANT le catchall, hors groupe `sambaedu.admin`).
- **Pas d'auth web** sur ces endpoints (postes clients sans cookie Laravel). **Garde effective** : l'`id` md5 (32 hex) doit être présent dans APCu (clé `apps.$id` posée par `applications.php`, TTL 1800s) — entropie 64 bits. **Pattern iso 4.8** (`AppPolicyController::resolve`).
- **Pattern routes** : `Route::match(['GET', 'POST'], 'gpo/network_out.php', [...])` + `->middleware('throttle:300,1')` (parité firefox_out.php) — déclaration **AVANT** le catchall ligne 437 de `routes/web.php`.
- **Shim 1bis-18 reste vivant** : les fichiers `legacy/modules/gpo/network_out.php` et `legacy/modules/gpo/veyon_out.php` **ne sont PAS supprimés** par cette story (cohérence Story 16.1 D6 "shim reste actif pendant tout Epic 16"). Ils deviennent **inaccessibles** car la route native les intercepte avant — mais le code reste pour rollback éventuel et comparaison.
- **`@legacy-port`** : les helpers portés depuis `includes/network.inc.php` (`network_create_script`, `system_proxy`, `gnome_proxy`) **doivent** porter un docblock `@legacy-port path="sambaedu/includes/network.inc.php"` + `@todo` (convention Story 16.1 AC2.3). Pareil pour la logique Veyon (mapping ACL, AccessControl) portée depuis `gpo/veyon_out.php`.
- **Trois constantes top-level** côté legacy (`OPENSSL_PKCS1_OAEP_PADDING`, etc.) : déjà natives PHP — pas de redéfinition à craindre.
- **CLAUDE.md** : pas applicable directement (pas de UI Livewire, pas de modale, pas de `WithToasts`). Filesystem-based router non applicable (Controllers explicites dans `routes/web.php`).

---

## Dépendances

| Story / Epic | Titre | Status | Détail |
|---|---|---|---|
| **16.1** | Fondations GPO natives + audit legacy | review (2026-05-11) | Non bloquant pour cette story — **les stubs écriture `GpoService` ne sont PAS consommés** (recadrage scope). Le channel `gpo` n'est pas utilisé non plus (logs standard `daily`). `SambaToolRunner` non utilisé. |
| **16.3a** | Liens profonds sections natives | review (2026-05-11) | Non bloquant — `NativeSectionResolver` n'est pas modifié (mais voir D11 : décider si on enrichit pour pointer vers `network`/`veyon` natifs). **Recommandation SM : pas d'enrichissement** — ces endpoints sont runtime, pas des UIs admin destinées au CTA. |
| Story 4.7 | Wallpapers Eloquent | done | **Référence pattern Controller iso-contrat legacy** : `WallpaperController::legacyOut` + middleware `WallpaperContextRepository` (APCu). |
| Story 4.8 | Personnalisation apps extensible (Firefox/Thunderbird policies) | done | **Référence pattern Controller iso-contrat legacy** : `AppPolicyController::legacyFirefoxOut` + `AppContextRepository` (APCu). Validation `id` md5 32 hex strict. Throttle 300/min/IP. |
| `ShortcutExportController` (refonte historique) | Endpoint export raccourcis | done | **Référence pattern Controller iso-contrat legacy** : `legacyDispatch(Request)` route les actions startup/logon/file/icon. |
| Story 4.8 | `AppContextRepository` interface | done | **Réutilisation directe** : `findById(string $id): ?AppContext` retourne le contexte APCu `apps.$id`. `veyon_out.php` et `network_out.php` consomment exactement la même clé (`apcu_fetch("apps.$id)")` ligne 24/26 des legacy). |
| 1bis-18a/e | Shim legacy GPO | done / review | Fournit `legacy/modules/gpo/{network_out,veyon_out}.php` byte-identique pour rollback. Le shim reste accessible via `direct_legacy_routes` si jamais — mais bloqué par la nouvelle route Laravel placée AVANT le catchall. |
| `SambaEduConfig` (`app/Config/SambaEduConfig.php`) | Bridge config legacy → Laravel | done | **Source de config native** déjà existante pour `proxy_type`, `proxy_address`, `proxy_port`, `domain`, `se4fs_name`. Utilisé par `FirefoxPolicyAdapter`, `ThunderbirdPolicyAdapter`. Les clés `wpa_ssid`, `wpa_password`, `802_1x_wired`, `802_1x_ssid`, `read_ldap_password`, `openent_uri`, `apt_proxy`, `no_proxy` doivent être lisibles via ce même bridge (vérifier qu'elles le sont déjà ou les ajouter). |

**Conclusion dépendances** : aucune bloquante. La story peut démarrer immédiatement en parallèle de la review de 16.3a. **Le pattern Controller iso-contrat legacy est posé** par 3 contrôleurs existants (`WallpaperController`, `AppPolicyController`, `ShortcutExportController`) → le dev a 3 références concrètes à copier.

---

## Décisions SM (D1-D11)

| #   | Décision                                                                                                                                                                                                                                                                                                                                                                                          | Justification                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
|-----|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| D1  | **URLs natives = strictement iso-legacy** : `/gpo/network_out.php` et `/gpo/veyon_out.php`. **PAS** de nouvelle route `/app/gpo/{guid}/network` ni `/api/v1/network/script` dans cette story. Pas d'alias.                                                                                                                                                                                          | Iso-contrat URL = pré-requis (cf. Story 4.7 AC 7, 4.8 AC 9). Les GPO `se4_applications` déjà déployées sur le parc référencent ces URLs en dur — toute mutation casserait le parc. Pas d'alias supplémentaire pour éviter la confusion / dette routes.                                                                                                                                                                                                                                                                       |
| D2  | **Catchall override = `Route::match(['GET','POST'], 'gpo/network_out.php', [NetworkOutController::class, 'legacyOut'])` + idem `veyon_out.php`** — déclarées dans `routes/web.php` **AVANT** le catchall ligne 437. **AJOUT** au tableau `config('sambaedu.blocked_legacy_routes')` **NON NÉCESSAIRE** (la déclaration Laravel `Route::match` court-circuite déjà le catchall).                  | Pattern strictement identique à `firefox_out.php` (routes/web.php:390), `wallpaper_out.php` (routes/web.php:361), `shortcuts_out.php` (routes/web.php:350). Pas de doublon (`blocked_legacy_routes` traite les **redirections** vers nouvelles URLs natives ; ici on **intercepte** au même URL).                                                                                                                                                                                                                              |
| D3  | **Services métier dédiés** dans `App\Gpo\Services\` : `NetworkScriptGenerator` (génère le script bash) et `VeyonConfigGenerator` (génère le JSON Veyon). Controllers fins dans `App\Http\Controllers\` (`NetworkOutController`, `VeyonOutController`).                                                                                                                                              | (a) Cohérence Story 16.1 AC2.1 (`app/Gpo/Services/`). (b) Cohérence pattern 4.7/4.8 (Controller mince, logique dans Service). (c) Permet test unitaire des générateurs sans HTTP (`NetworkScriptGeneratorTest`, `VeyonConfigGeneratorTest`). (d) Réutilisable si plus tard on expose canoniquement (`/api/v1/network/...`) — out-of-scope ici.                                                                                                                                                                                  |
| D4  | **Création de compte AD `read.user<suffix>` côté `veyon_out.php` = OUI, à porter natif** (pas désactiver). Logique : (a) `SambaEduConfig->get('read_ldap_password')` vide → générer un mot de passe (≥ 15 char, conforme `password_rule` AD) ; (b) créer le compte AD via service AD natif existant (TBD T0 — investigation pendant le dev) ; (c) persister le mot de passe via `SambaEduConfig::set('read_ldap_password', $pwd)`. **Mutex/lock applicatif** (`Cache::lock('veyon-read-user-create', 30)->get(...)`) anti-race multi-requêtes simultanées au boot d'un parc. **Si la création échoue** : log error + renvoyer la config Veyon sans `BindPassword` (le client Veyon échouera proprement, retry au prochain logon). | (a) Le legacy le fait (gpo/veyon_out.php:29-50) — désactiver casserait le déploiement Veyon sur instances neuves. (b) **Race condition** : `applications.php` est appelé en `startup` quasi simultanément par tous les postes du parc → 1ère requête `veyon_out.php` peut être concurrente sur N postes. Lock obligatoire. (c) **Persistance config** : déjà géré par `SambaEduConfig::set` (déjà utilisé pour `apt_proxy` etc. — à vérifier). **À TRANCHER pendant le dev** : si le service AD natif n'expose pas encore `create_ad_user`/`usersetpassword` proprement, **fallback temporaire = appeler le shim `create_ad_user`/`usersetpassword` via `legacy/bootstrap.php`** avec docblock `@legacy-port` + TODO Story 16.4. **NE PAS BLOQUER** la story là-dessus. |
| D5  | **Validation `id`** : regex `^[a-f0-9]{32}$` (md5 32 hex). Si invalide → `400`. Si APCu retourne `null` (TTL expiré ou apcu absent) → **204 No Content** (parité `AppPolicyController:79` qui renvoie body vide en cas d'`id` vide — la cible est un poste client qui retry au prochain logon, on ne veut pas planter le script).                                                                  | (a) Pattern iso Story 4.8 `AppPolicyController::resolve`. (b) `204` au lieu de `404` est un compromis : pour `network_out.php` (script bash) un 404 produirait du texte HTML en sortie qui ferait planter `bash -c` côté poste. Body vide = `bash -c ""` = noop. (c) Le legacy `gpo/veyon_out.php:27` fait `exit()` (= 200 body vide) en cas de `$nom_poste` vide → cohérent.                                                                                                                                                   |
| D6  | **Réutilisation `AppContextRepository`** (interface) + `ApcuAppContextRepository` (Story 4.8) pour résoudre `apps.$id`. **PAS** de nouveau repository.                                                                                                                                                                                                                                              | (a) Code déjà testé (`AppPolicyLegacyEndpointTest`). (b) Dégradation gracieuse APCu absente (`findById` retourne `null` → 204). (c) Garantit que les 3 endpoints (`firefox_out`, `network_out`, `veyon_out`) partagent **la même session de boot** — un poste qui passe `applications.php` voit la config cohérente sur les 3.                                                                                                                                                                                                |
| D7  | **Permissions** : aucune permission Spatie/`server.admin` requise — ces endpoints sont **publics** (postes clients sans cookie). Iso 4.7/4.8. **Throttle `throttle:300,1`** par IP (parité firefox_out.php route web.php:391).                                                                                                                                                                       | Postes clients sans auth web. La garde réelle est l'`id` md5 dans APCu (entropie 64 bits, TTL 1800s) — un attaquant ne peut pas deviner un `id` valide. Le throttle évite l'abus (300 postes derrière NAT au pire = OK).                                                                                                                                                                                                                                                                                                       |
| D8  | **Format de sortie strictement iso-legacy**. `network_out.php` : `Content-Type: text/plain; charset=utf-8`, body = header bash/cmd + script `network_create_script` + `system_proxy` (action=startup) ou + `gnome_proxy` (action=logon). `veyon_out.php` : `Content-Type: application/json; charset=utf-8`, body = `json_encode($json, JSON_PRETTY_PRINT)`. Pas d'optimisation, pas de gzip, iso-bytes. | Le client Veyon parse en mode strict, le shell bash parse littéralement. Test comparison `tests/Feature/Gpo/{NetworkOutComparisonTest,VeyonOutComparisonTest}.php` qui diff la sortie native vs un fixture legacy de référence (capturé sur la VM avant migration). **Si diff = test rouge** pour forcer attention au moindre écart.                                                                                                                                                                                          |
| D9  | **Logging = channel `daily` standard** (pas le channel `gpo`). Code : `Log::error('[NetworkOutController] failed: ...', [...]);` (pattern iso `AppPolicyController:108`).                                                                                                                                                                                                                          | (a) Channel `gpo` (Story 16.1) est dédié aux **actions admin GPO** (`gpo.list`, `gpo.section.write`, etc.) — pas aux endpoints runtime postes clients. (b) Iso pattern existant 4.7/4.8 (logs standard). (c) Évite de polluer le rolling log `gpo` avec 300 logs/min de boot postes. **Si Henri veut un logging dédié** (canal `network-veyon-runtime` par ex.) → out-of-scope cette story (ajout futur ~5 min).                                                                                                                  |
| D10 | **Tests intégration Veyon réel = scénario QA manuel VM uniquement**, pas test automatisé CI. Ajouter une **section 4 dans `docs/qa/domains/gpo.md`** avec checklist concrète : (a) `curl -X POST -F "id=$ID" http://localhost/gpo/veyon_out.php` retourne JSON parseable ; (b) installer Veyon Master sur un poste, configurer `ConfigurationStore=LDAP`, importer la config retournée → vérifier que la salle apparaît, qu'on peut se connecter à 1 poste ; (c) `curl -F "action=startup" -F "os=linux" -F "id=$ID" http://localhost/gpo/network_out.php \| bash --noprofile` ne génère pas d'erreur (au moins syntaxiquement).                                                       | (a) Pas d'infra E2E Veyon en CI. (b) Le test "Veyon réel" est de l'**acceptance manuelle** — Henri/QA sur la VM. (c) Tests automatisés en parallèle = (i) tests Unit des générateurs (logique pure), (ii) tests Feature comparison qui diff la sortie native vs fixture legacy capturée — couvre ~95 % du risque. (d) Le smoke Veyon réel reste un must mais pas en bloquant CI.                                                                                                                                              |
| D11 | **Pas d'enrichissement de `NativeSectionResolver` (16.3a)** pour pointer vers ces endpoints natifs. Pas de bouton "Éditer Network/Veyon nativement" dans `/app/gpo/{guid}`.                                                                                                                                                                                                                          | Ces endpoints sont **runtime postes**, pas des UIs admin. Un bouton qui ouvrirait `gpo/network_out.php?id=…` n'a aucun sens côté admin (résultat = du bash brut). Pour l'admin, la **vraie** UI native d'édition de la config proxy / WPA / 802.1x / Veyon vit dans **`/admin/settings`** (déjà gérée par les Stories AdminSettings — hors scope Epic 16). On peut juste, à terme, ajouter une entrée dans `NativeSectionResolver::MAPPING` pour `*proxy*` / `*network*` / `*veyon*` → `/admin/settings?tab=network` — **mais hors scope cette story** (à arbitrer ultérieurement).  |

### Discrepances ouvertes à valider pendant le dev

| Item | Note SM |
|---|---|
| Création AD `read.user` — **API native disponible ?** | Le code natif n'expose pas (à date 2026-05-12) de service `AdUserCreator` propre. **Action dev T0** : vérifier `App\LdapModels\*`, `App\Services\*Ad*`, ou shim `modify_ad(gpo)` 1bis-18g. **Si rien de propre** → fallback `@legacy-port` via shim `create_ad_user`/`usersetpassword` + TODO Story 16.4 (cf. D4). |
| `SambaEduConfig::set('read_ldap_password', $pwd)` — **disponible ?** | Le bridge actuel expose `->get($key, $default)` — vérifier `->set($key, $value)` ou méthode équivalente. **Si absent**, ajouter (ou utiliser le shim legacy `set_config` via `@legacy-port`). |
| `licence=1` sous-cas Veyon | `legacy/modules/gpo/veyon_out.php:13-20` — si `POST licence=1` et fichier `/etc/sambaedu/applications/veyon/licence.vlf` existe → renvoyer son contenu raw. **Décision : porter en tant que sous-action du Controller** (`if ($request->input('licence') === '1') { return $this->serveLicence(); }`). Streamer le fichier via `BinaryFileResponse`. |
| Encodage du mot de passe LDAP (`openssl_public_encrypt + PKCS1_OAEP + bin2hex`) | Format binaire consommé par Veyon. **Iso strict**. Test unit `VeyonConfigGeneratorTest` qui vérifie que la sortie est `bin2hex(openssl_public_encrypt($pwd, $key, PKCS1_OAEP))` reproductible. |
| `ad_url($config, "dns")` (utilisé par veyon_out:104) | Méthode legacy retournant l'URL AD pour le bind. À porter ou réutiliser via un helper natif. **Action T0** : chercher l'équivalent natif (`ad_url`/`get_dns`). |

---

## Acceptance Criteria

> 5 volets. Volet 1 = `NetworkOutController`. Volet 2 = `VeyonOutController` + side effect AD. Volet 3 = routage + iso-contrat. Volet 4 = sécurité/throttle. Volet 5 = tests.

### Volet 1 — Endpoint `gpo/network_out.php` (`NetworkOutController`)

**AC1.1 — Route**
**Given** la story est implémentée
**When** un poste client fait `GET|POST /gpo/network_out.php?action=startup&os=linux&id=…`
**Then** la route Laravel `Route::match(['GET','POST'], 'gpo/network_out.php', [App\Http\Controllers\Gpo\NetworkOutController::class, 'legacyOut'])` (déclarée AVANT le catchall ligne 437 de `routes/web.php`) prend en charge la requête
**And** le middleware `throttle:300,1` est appliqué
**And** **aucune** auth web n'est requise (pas de middleware `sambaedu.admin`).

**AC1.2 — Validation `id`**
**Given** un appel avec un `id` invalide (format ≠ 32 hex)
**When** le Controller `legacyOut` est appelé
**Then** il retourne `204 No Content` (body vide, content-type `text/plain`)
**And** aucun appel système (pas de génération de script)
**And** un log warning éventuel est émis (`Log::warning(...)` standard, pas channel `gpo`).

**AC1.3 — Contexte APCu absent**
**Given** un appel avec un `id` valide mais expiré dans APCu (`AppContextRepository::findById()` retourne `null`)
**When** le Controller est appelé
**Then** il retourne `204 No Content`
**And** body vide
**And** un log info est émis (`Log::info('[NetworkOutController] context expired ...', ['id' => $id])`).

**AC1.4 — Action `startup` Linux**
**Given** un appel avec `action=startup`, `os=linux`, `id=$valid_id`
**When** le Controller invoque `NetworkScriptGenerator::buildStartup($context, 'linux')`
**Then** la sortie HTTP est :
- Status `200`
- `Content-Type: text/plain; charset=utf-8`
- Body = `"#!/bin/bash\n#startup\n# script de configuration du reseau Linux\n"` + `NetworkScriptGenerator::networkCreateScript($context)` + `NetworkScriptGenerator::systemProxy()`

**And** `NetworkScriptGenerator::networkCreateScript($context)` reproduit fidèlement `legacy/modules/gpo/network.inc.php::network_create_script` (config wifi WPA, 802.1x filaire/wifi avec ssh `pdbedit` pour récupérer le `machine_key` — voir D4 alternative pour pdbedit ssh).
**And** `NetworkScriptGenerator::systemProxy()` reproduit fidèlement `network.inc.php::system_proxy` (proxy_type ∈ {`aucun`, `automatique`, `manuel`}, sortie profile_file/wgetrc_file/apt 99proxy).
**And** la config est lue via `SambaEduConfig` (proxy_type/address/port/domain/se4fs_name/wpa_ssid/wpa_password/802_1x_wired/802_1x_ssid/apt_proxy/no_proxy).

**AC1.5 — Action `logon` Linux**
**Given** un appel avec `action=logon`, `os=linux`, `id=$valid_id`
**When** le Controller invoque `NetworkScriptGenerator::buildLogon($context, 'linux')`
**Then** la sortie est :
- Status `200`, content-type `text/plain; charset=utf-8`
- Body = `"#!/bin/bash\n#logon\n# script de configuration du reseau Linux\n"` + `NetworkScriptGenerator::gnomeProxy()`

**And** `gnomeProxy()` reproduit fidèlement `network.inc.php::gnome_proxy` (proxy_type ∈ {`aucun`, `automatique`, `manuel`} → gsettings org.gnome.system.proxy *).

**AC1.6 — Action ou OS non supportés**
**Given** un appel avec `action ∉ {startup, logon}` ou `os ∉ {linux}`
**When** le Controller est appelé
**Then** il retourne `200` body vide (cf. `network_out.php` legacy ligne 28 : `if (! empty($action) && ! empty($os) && $os == "linux")` — sinon noop)
**And** content-type `text/plain; charset=utf-8`.

> **Note** : le legacy filtre dur sur `$os == "linux"` (le case Windows existait mais générait le même header sans corps de script — comportement bogué probablement). **Décision SM : reproduire fidèlement** (= `os=windows` → body vide). Si Henri souhaite porter aussi la branche Windows, à signaler en post-review.

**AC1.7 — Log debug temporaire**
**Given** un appel avec sortie générée
**When** le Controller a produit le body
**Then** un fichier `/tmp/network-{action}-{id}.log` est écrit avec le contenu généré (parité legacy ligne 40/51 `file_put_contents("/tmp/network-…")`).

> **Note SM** : ce write `/tmp/` est de la dette legacy (debug). À conserver pour parité fonctionnelle exacte. À tagger `@legacy-port` + `@todo` (à supprimer en V2 quand le système sera stabilisé).

### Volet 2 — Endpoint `gpo/veyon_out.php` (`VeyonOutController`)

**AC2.1 — Route**
**Given** un poste client fait `GET|POST /gpo/veyon_out.php?id=…[&licence=1]`
**When** la route Laravel `Route::match(['GET','POST'], 'gpo/veyon_out.php', [App\Http\Controllers\Gpo\VeyonOutController::class, 'legacyOut'])` (déclarée AVANT le catchall) prend en charge la requête
**Then** middleware `throttle:300,1` appliqué
**And** aucune auth web requise.

**AC2.2 — Sous-action `licence=1`**
**Given** un appel avec `licence=1` (POST ou GET)
**When** le Controller détecte ce paramètre
**Then** si le fichier `/etc/sambaedu/applications/veyon/licence.vlf` existe, son contenu est retourné brut (`Content-Type: application/octet-stream`, status `200`)
**And** si le fichier n'existe pas, status `200` body vide (parité legacy `exit()` ligne 19).

**AC2.3 — Validation `id` (cas standard)**
**Given** un appel sans `licence=1`
**When** l'`id` est invalide ou le contexte APCu est expiré
**Then** status `204` body vide (cohérent avec D5)
**And** aucun side effect (pas de création AD, pas de génération JSON).

**AC2.4 — Génération JSON Veyon — cas nominal**
**Given** un appel avec `id=$valid_id`, contexte APCu présent, `read.user` déjà créé
**When** `VeyonConfigGenerator::generate($context)` est invoqué
**Then** la sortie est :
- Status `200`, `Content-Type: application/json; charset=utf-8`
- Body = `json_encode($json, JSON_PRETTY_PRINT)` reproduisant strictement la structure du legacy `gpo/veyon_out.php:78-130`

**And** la structure inclut :
- `$json` chargé depuis `/usr/share/sambaedu/applications/veyon/veyon.json` (override `/etc/sambaedu/applications/veyon/local.json` via `array_replace_recursive`)
- Section `LDAP` complète : `BaseDN`, `BindDN` (`CN=read.user{suffix},{people_rdn},{ldap_base_dn}`), `BindPassword` (bin2hex(openssl_public_encrypt(read_ldap_password, default-pubkey.pem, PKCS1_OAEP))), `ServerHost`, `ServerPort=389`, `TLSVerifyMode=1`, `UserGroupsFilter`, etc.
- Section `AccessControl.AuthorizedUserGroups` : `CN=Admins,…`, `CN=Profs,…`, `CN=Administratifs,…`
- Section `DesktopServices.PredefinedWebsites` enrichie si `openent_uri` configuré
- `cleandn()` appliqué partout (ou=→OU=, cn=→CN=, dc=→DC=)
- Helper `cleandn` extrait en méthode publique de `VeyonConfigGenerator` (testable).

**AC2.5 — Side effect : création compte AD `read.user{suffix}` si absent**
**Given** `SambaEduConfig::get('read_ldap_password')` retourne vide
**When** `VeyonOutController::legacyOut` détecte ce cas
**Then** un lock applicatif `Cache::lock('veyon-read-user-create', 30)` est acquis (timeout 30s)
**And** un mot de passe est généré (longueur ≥ 15, conforme `password_rule` AD si applicable)
**And** un compte AD `read.user{suffix}` est créé (via service AD natif si disponible — sinon **fallback** shim `create_ad_user` documenté `@legacy-port`)
**And** `SambaEduConfig::set('read_ldap_password', $pwd)` persiste le mot de passe (ou shim `set_config` fallback)
**And** le lock est libéré
**And** la suite normale du flux (génération JSON) continue avec ce nouveau mot de passe.

**AC2.6 — Robustesse création compte AD**
**Given** la création AD échoue (lock non acquis, AD KO, etc.)
**When** l'exception est capturée
**Then** un `Log::error('[VeyonOutController] read.user creation failed', [...])` est émis
**And** le Controller retourne `503 Service Unavailable` (header `Retry-After: 60`) **OU** le JSON sans `BindPassword` (TBD par Henri)

> **Décision SM par défaut** : retourner `503 Service Unavailable` avec header `Retry-After: 60`. Le client Veyon réessaiera au prochain logon — préférable à servir une config partielle qui pourrait piéger l'admin à se demander pourquoi Veyon ne se connecte plus. Henri peut basculer sur "JSON sans BindPassword" si problème de retry comportement Veyon.

**AC2.7 — Vérification password validity**
**Given** `read_ldap_password` déjà persisté
**When** `user_valid_passwd` (legacy ligne 48) est invoqué côté natif (équivalent à porter — vérifier auth AD avec ces credentials)
**Then** si invalide, `usersetpassword` est invoqué pour resynchroniser
**And** aucun crash, log warning si re-sync nécessaire.

> **Décision SM** : porter cette logique natif si l'équivalent existe (cf. `App\LdapModels\*` ou shim). Sinon **fallback** `@legacy-port` shim `user_valid_passwd`/`usersetpassword` avec TODO Story 16.4. **Ne pas bloquer** la story là-dessus.

**AC2.8 — `parcFilter` calculé**
**Given** le contexte APCu fournit `$info['salle']`
**When** `VeyonConfigGenerator` résout `$parcFilter`
**Then** la logique reproduit fidèlement `gpo/veyon_out.php:53-65` : `search_ad($config, $info['salle'], "salle")` puis si trouvé `search_ad(..., "salle", $salles[0]['dn'])` → `parcFilter = (|(cn=$parc.ou)...)`
**And** sinon `parcFilter = (cn=$info['salle'])`.

> **Note** : le shim `search_ad` est disponible (Story 1bis-18g). À utiliser directement avec docblock `@legacy-port`.

### Volet 3 — Routage + iso-contrat

**AC3.1 — Position dans `routes/web.php`**
**Given** les nouvelles routes
**When** elles sont déclarées
**Then** elles sont positionnées **AVANT** le catchall ligne 437 (`Route::match(['GET',...,'HEAD'], '{path}', [LegacyCatchallController::class, 'handle'])`)
**And** dans une section commentée `/* Interception legacy gpo/network_out.php + gpo/veyon_out.php (Story 16.3b) */` (pattern iso 4.7 ligne 354-362, 4.8 ligne 380-396)
**And** déclarées **hors** du groupe `sambaedu.admin` (pas d'auth web).

**AC3.2 — Iso-contrat vs legacy (comparison test)**
**Given** un fixture de référence capturé sur la VM AVANT migration (output legacy pour un id+context donné)
**When** la nouvelle route est appelée avec le même input
**Then** la diff entre output natif et output legacy est :
- Pour `network_out.php` : **identique modulo timestamps/random** (le legacy n'a pas de timestamps dans le script bash, donc diff strict attendu)
- Pour `veyon_out.php` : **identique modulo `BindPassword` chiffré** (chiffrement OpenSSL non déterministe — comparer la **structure** JSON, pas la valeur de `BindPassword`)

**And** un test Feature `tests/Feature/Gpo/NetworkOutComparisonTest.php` et `tests/Feature/Gpo/VeyonOutComparisonTest.php` exécute la comparison à partir de fixtures (`tests/Fixtures/Gpo/legacy-network-out-*.txt` et `tests/Fixtures/Gpo/legacy-veyon-out-*.json`).

> **Note SM** : si Henri n'a pas le temps de capturer un fixture VM, le dev produit un fixture **artisanal** depuis le code legacy (lecture mentale + reproduction texte) et marque le test `@group requires-fixture-capture` skippable. Pas bloquant.

**AC3.3 — Shim legacy conservé**
**Given** la story est implémentée
**When** la review est passée
**Then** les fichiers `legacy/modules/gpo/network_out.php` et `legacy/modules/gpo/veyon_out.php` **ne sont PAS supprimés**
**And** ils restent inaccessibles via HTTP (court-circuités par les routes natives) mais préservés sur disque pour rollback éventuel et comparaison (cohérence Story 16.1 D6).

### Volet 4 — Sécurité

**AC4.1 — Throttle 300/min/IP**
**Given** les 2 routes
**When** un client fait > 300 requêtes en 1 minute depuis la même IP
**Then** la 301ᵉ retourne `429 Too Many Requests` (middleware `throttle:300,1`)
**And** un test feature vérifie ce comportement (`NetworkOutThrottleTest` ou inline dans `NetworkOutEndpointTest`).

**AC4.2 — Pas d'injection shell**
**Given** un input `$context` du repository APCu
**When** `NetworkScriptGenerator` génère le script bash
**Then** **aucun input utilisateur** n'est concaténé brut dans le script bash sans échappement (cf. legacy `network.inc.php:23` `exec("ssh ... pdbedit -Lw " . $info['machine']['samaccountname'])` — porte un risque latent)
**And** le port natif **doit** : (a) soit valider strictement `samaccountname` (regex `^[A-Za-z0-9_\-\.\$]+$`), (b) soit utiliser `escapeshellarg`/`Process::run` mode array si exec invoqué.

> **Note SM** : le pdbedit ssh côté legacy `exec()` est un risque sécu identifié dans l'audit (§6.F F8 probable). À porter avec validation stricte. **Mitigation alternative** : si possible, remplacer le `ssh root@se4ad pdbedit` par un appel natif (`samba-tool user getpassword` ou requête LDAP attribut `dBCSPwd`). **À investiguer T0** ; sinon iso-legacy avec validation regex.

**AC4.3 — Validation `id` md5 strict**
**Given** tous les inputs `id`
**When** ils sont reçus par le Controller
**Then** la regex `^[a-f0-9]{32}$` est appliquée AVANT tout accès APCu / DB / AD
**And** un test feature vérifie qu'un `id` malformé (`INJECTION`, `' OR 1=1 --`, `../../etc/passwd`) retourne `204` sans aucun appel `samba-tool` / LDAP / `apcu_fetch`.

**AC4.4 — Pas d'exposition `licence.vlf` non autorisée**
**Given** la sous-action `licence=1` de `veyon_out.php`
**When** elle est appelée
**Then** elle ne lit **que** `/etc/sambaedu/applications/veyon/licence.vlf` (chemin codé en dur, pas de paramètre `path`)
**And** si le fichier n'existe pas → body vide (pas d'erreur 404 leakant le filesystem).

> **Note SM** : le legacy fait `echo file_get_contents("/etc/sambaedu/applications/veyon/licence.vlf")` sans auth. Conservé tel quel — la licence Veyon n'est pas un secret (clé publique). Si Henri veut auth → out-of-scope.

### Volet 5 — Tests

**AC5.1 — Tests Feature `NetworkOutController`** (`tests/Feature/Gpo/NetworkOutEndpointTest.php`)
Au moins **9 tests** :
1. `it_returns_204_for_invalid_id`
2. `it_returns_204_when_context_expired`
3. `it_returns_204_when_action_unsupported` (action=foo)
4. `it_returns_204_when_os_not_linux` (os=windows, attendu noop iso-legacy)
5. `it_generates_startup_linux_script_with_correct_headers`
6. `it_includes_system_proxy_in_startup_script`
7. `it_generates_logon_linux_script_with_gnome_proxy`
8. `it_writes_debug_file_to_tmp` (parité legacy)
9. `it_applies_throttle_300_per_minute` (peut être skippé si trop coûteux — `@group throttle`)

**AC5.2 — Tests Feature `VeyonOutController`** (`tests/Feature/Gpo/VeyonOutEndpointTest.php`)
Au moins **10 tests** :
1. `it_returns_licence_vlf_when_licence_param_is_1` (file existe)
2. `it_returns_empty_body_when_licence_vlf_missing`
3. `it_returns_204_for_invalid_id`
4. `it_returns_204_when_context_expired`
5. `it_generates_veyon_json_with_full_ldap_section`
6. `it_applies_cleandn_in_dn_fields`
7. `it_includes_openent_predefined_website_when_configured`
8. `it_creates_read_user_when_password_missing` (mock le service AD ou shim — vérifier appel)
9. `it_returns_503_when_read_user_creation_fails`
10. `it_acquires_lock_during_read_user_creation` (vérif lock acquis/libéré)

**AC5.3 — Tests Unit `NetworkScriptGenerator`** (`tests/Unit/Gpo/NetworkScriptGeneratorTest.php`)
Au moins **6 tests** :
1. `it_builds_startup_linux_header`
2. `it_generates_wpa_psk_block_when_configured`
3. `it_skips_wpa_block_when_not_configured`
4. `it_generates_8021x_wired_block_when_configured`
5. `it_emits_system_proxy_aucun_block`
6. `it_emits_system_proxy_manuel_block_with_apt`

**AC5.4 — Tests Unit `VeyonConfigGenerator`** (`tests/Unit/Gpo/VeyonConfigGeneratorTest.php`)
Au moins **6 tests** :
1. `it_builds_ldap_section_with_all_required_keys`
2. `it_encrypts_bind_password_with_openssl_pkcs1_oaep_padding`
3. `it_applies_cleandn_in_all_dn_attributes`
4. `it_merges_local_json_override_via_array_replace_recursive`
5. `it_includes_authorized_user_groups_admins_profs_administratifs`
6. `it_includes_openent_in_predefined_websites_when_openent_uri_set`

**AC5.5 — Tests Feature comparison** (`tests/Feature/Gpo/NetworkOutComparisonTest.php`, `tests/Feature/Gpo/VeyonOutComparisonTest.php`)
- 1 test par endpoint qui charge un fixture de référence (`tests/Fixtures/Gpo/legacy-network-out-startup-linux.txt`, `tests/Fixtures/Gpo/legacy-veyon-out.json`) et diff la sortie native
- Pour veyon : diff structure JSON (pas `BindPassword`)
- Peut être marqué `@group requires-fixture-capture` skippable si fixture absent

**AC5.6 — Aucune régression**
**Given** la suite globale
**When** elle s'exécute
**Then** aucun test pré-existant ne casse (notamment 16.1, 16.2, 16.3a, 4.7, 4.8 tests).

---

## Hors-scope (explicite)

- **Mutation de GPO** (édition Section AD, samba-tool gpo set, etc.) — **PAS DANS CETTE STORY**. Ces endpoints sont runtime, pas admin.
- **UI Livewire d'édition** de proxy / Veyon / WPA / 802.1x — vit dans `/admin/settings` (déjà géré, hors Epic 16).
- **Stubs écriture `GpoService` (create/delete/setLink/...)** — non consommés ici. Voir Story 16.4 pour leur implémentation.
- **Suppression du shim 1bis-18** — interdite par garde-fou Epic 16 D6.
- **Couplage WPKG / `associations_out.php` / `applications.php`** — Story 16.3c.
- **Wine generation Job queue** — Story 16.3c.
- **`gpo-maj.php`, `gpo-export.php`, corbeille GPO** — Story 16.4.
- **Liaisons GPO ↔ OU / WorkstationGroup** — Story 16.5.
- **Enrichissement `NativeSectionResolver`** pour pointer vers ces endpoints — décision D11 (pas dans cette story).
- **Refonte du compte AD `read.user` en service AD natif propre** — out-of-scope si fallback `@legacy-port` retenu (cf. D4). À traiter en Story 16.4 ou hors-Epic 16.
- **Suppression du write `/tmp/network-…-…log`** — legacy debt, conservée pour parité (cf. AC1.7).
- **Branche Windows de `network_out.php`** — le legacy filtre dur sur `os=linux`. Reproduit fidèlement (AC1.6).
- **Logging channel `gpo`** dédié pour ces endpoints runtime — channel `daily` standard suffit (D9).
- **Tests E2E navigateur** — iso 4.8.
- **Cache de la sortie générée** — pas de cache (la sortie dépend de chaque `id` ↦ contexte poste — pas mutualisable). Throttle 300/min suffit.

---

## Tasks / Subtasks

### Phase T0 — Cadrage & vérifications préalables

- [x] **T0.1** Lire `app/Http/Controllers/AppPolicyController.php` (lignes 1-130) — référence pattern Controller iso-contrat legacy.
- [x] **T0.2** Lire `app/Http/Controllers/WallpaperController.php` (lignes 1-80) — référence pattern `WallpaperContextRepository` + 400 sur validation.
- [x] **T0.3** Lire `app/Services/AppCustomization/ApcuAppContextRepository.php` (39 lignes) — pattern repository APCu réutilisable.
- [x] **T0.4** Lire `legacy/modules/gpo/network_out.php` (54 lignes) + `sambaedu/includes/network.inc.php` (`network_create_script`, `system_proxy`, `gnome_proxy`) — source du portage.
- [x] **T0.5** Lire `legacy/modules/gpo/veyon_out.php` (141 lignes) — source du portage.
- [x] **T0.6** **Investigation D4** : vérifier dans `app/LdapModels/`, `app/Services/Ad*`, ou shim `legacy/bootstrap.php` la disponibilité native de `create_ad_user($config, $user)` / `usersetpassword($config, $login, $pwd)` / `user_valid_passwd($config, $login, $pwd)`. **Si rien de propre** → fallback `@legacy-port` shim (`require_once 'legacy/bootstrap.php'` puis appel direct des fonctions — ces 3 fonctions vivent dans `samba-tool.inc.php` ou `ldap.inc.php`).
- [x] **T0.7** **Investigation `SambaEduConfig::set`** : vérifier que `App\Config\SambaEduConfig` expose une méthode de mutation (`->set`, `->update`, `->put`) pour persister `read_ldap_password`. Sinon → fallback shim `set_config` `@legacy-port`.
- [x] **T0.8** **Investigation `ad_url`** : chercher l'équivalent natif de `ad_url($config, "dns")` (legacy `samba-tool.inc.php` ou `ldap.inc.php`). Si pas natif → `@legacy-port`.
- [x] **T0.9** Vérifier que les clés config `wpa_ssid`, `wpa_password`, `802_1x_wired`, `802_1x_ssid`, `apt_proxy`, `no_proxy`, `openent_uri`, `suffix`, `domain`, `se4fs_name`, `dn.people`, `people_rdn`, `groups_rdn`, `ldap_base_dn`, `parcs_rdn`, `computers_rdn` sont accessibles via `SambaEduConfig` (sinon ajouter au bridge).
- [x] **T0.10** Capturer (idéalement Henri sur VM) 1 fixture legacy de référence pour `network_out.php?action=startup&os=linux` (avec un `id` test) → `tests/Fixtures/Gpo/legacy-network-out-startup-linux.txt`. Idem `veyon_out.php?id=test` → `tests/Fixtures/Gpo/legacy-veyon-out.json`. **Si non disponible**, dev produit un fixture mental + marque `@group requires-fixture-capture` (D10).

### Phase T1 — Service `NetworkScriptGenerator` (logique pure)

- [x] **T1.1** Créer `app/Gpo/Services/NetworkScriptGenerator.php` avec :
  - Injection `SambaEduConfig $config`
  - Méthode `buildStartup(AppContext $context, string $os): string` (header bash/cmd + `networkCreateScript` + `systemProxy`)
  - Méthode `buildLogon(AppContext $context, string $os): string` (header + `gnomeProxy`)
  - Méthodes privées `networkCreateScript`, `systemProxy`, `gnomeProxy` (portées depuis `legacy/modules/gpo/network.inc.php` — `@legacy-port`)
  - Pour `pdbedit ssh` (legacy `network.inc.php:23`) : voir AC4.2, soit valider regex strict + iso-legacy, soit remplacer par appel natif AD (T0.6).
  - `declare(strict_types=1)` + PHPDoc complet
- [x] **T1.2** Tests Unit `tests/Unit/Gpo/NetworkScriptGeneratorTest.php` (AC5.3, 6+ tests) — pur PHPUnit, mock `SambaEduConfig` via instance.

### Phase T2 — Service `VeyonConfigGenerator` (logique pure)

- [x] **T2.1** Créer `app/Gpo/Services/VeyonConfigGenerator.php` avec :
  - Injection `SambaEduConfig $config`
  - Méthode `generate(AppContext $context): array` (retourne le array JSON à encoder)
  - Méthode publique statique `cleandn(string $dn): string` (testable iso-legacy `gpo/veyon_out.php:6-11`)
  - Méthode privée `resolveParcFilter(AppContext $context): string` (porte la logique `search_ad` du legacy — `@legacy-port` shim 1bis-18g)
  - Méthode privée `encryptBindPassword(string $password, string $publicKey): string` (openssl_public_encrypt + bin2hex + PKCS1_OAEP)
  - Chargement et merge `veyon.json` + `local.json` (paths configurables ou hardcoded `/usr/share/sambaedu/applications/veyon/veyon.json` + `/etc/sambaedu/applications/veyon/local.json`)
- [x] **T2.2** Tests Unit `tests/Unit/Gpo/VeyonConfigGeneratorTest.php` (AC5.4, 6+ tests).

### Phase T3 — Service `ReadUserManager` (création AD compte service)

- [x] **T3.1** Créer `app/Gpo/Services/ReadUserManager.php` (ou nom équivalent) avec :
  - Méthode `ensureReadUser(): string` (retourne le password, cache local, lock 30s) :
    1. Lire `SambaEduConfig::get('read_ldap_password')` — si présent et valide → return
    2. Sinon : acquérir lock `Cache::lock('veyon-read-user-create', 30)`, générer password (≥15 char), créer compte AD via T0.6 (natif ou shim), persister via T0.7 (natif ou shim), libérer lock, return password
  - Méthode `validateReadUserPassword(string $pwd): bool` (vérifie bind LDAP — équivalent legacy `user_valid_passwd`)
  - Méthode `resetReadUserPassword(string $pwd): void` (`usersetpassword` natif ou shim)
  - Tous les chemins shim portent docblock `@legacy-port` + `@todo Story 16.4 reprendre en natif propre`
- [x] **T3.2** Tests Unit `tests/Unit/Gpo/ReadUserManagerTest.php` (mock `Cache::lock`, mock shim) — au moins 4 tests : (a) retourne pwd existant si déjà créé ; (b) crée si absent ; (c) lock empêche race ; (d) reset si pwd invalide.

### Phase T4 — Controllers `NetworkOutController` + `VeyonOutController`

- [x] **T4.1** Créer `app/Http/Controllers/Gpo/NetworkOutController.php` :
  - `__construct(AppContextRepository $contextRepo, NetworkScriptGenerator $generator)`
  - Méthode `legacyOut(Request $request): Response`
  - Validation `id` md5 strict (AC4.3)
  - Switch sur `action` ∈ {`startup`, `logon`} (AC1.4-1.6)
  - Génération via `$generator->buildStartup` / `buildLogon`
  - Write `/tmp/network-{action}-{id}.log` (AC1.7)
  - Headers iso D8
- [x] **T4.2** Créer `app/Http/Controllers/Gpo/VeyonOutController.php` :
  - `__construct(AppContextRepository $contextRepo, VeyonConfigGenerator $generator, ReadUserManager $readUser)`
  - Méthode `legacyOut(Request $request): Response|BinaryFileResponse`
  - Sous-action `licence=1` → serveLicence (AC2.2)
  - Validation `id` md5 (AC4.3)
  - Appel `$readUser->ensureReadUser()` (AC2.5-2.6, gestion 503 / lock)
  - Génération via `$generator->generate($context)` + `json_encode($json, JSON_PRETTY_PRINT)`
  - Headers iso D8
- [x] **T4.3** Tests Feature `tests/Feature/Gpo/NetworkOutEndpointTest.php` (AC5.1, 9 tests) — pattern `AppPolicyLegacyEndpointTest`.
- [x] **T4.4** Tests Feature `tests/Feature/Gpo/VeyonOutEndpointTest.php` (AC5.2, 10 tests) — mock `ReadUserManager` pour les scénarios création/échec.

### Phase T5 — Routage `routes/web.php`

- [x] **T5.1** Ajouter dans `routes/web.php` (AVANT le catchall ligne 437) la section :
  ```php
  /*
  |--------------------------------------------------------------------------
  | Interception legacy gpo/network_out.php + gpo/veyon_out.php (Story 16.3b)
  |--------------------------------------------------------------------------
  | Endpoints runtime postes clients : script bash réseau (network_out)
  | et config JSON Veyon (veyon_out). Pattern iso 4.7/4.8.
  | Throttle 300/min/IP. Pas d'auth web (id md5 APCu = garde effective).
  */
  Route::match(['GET', 'POST'], 'gpo/network_out.php', [\App\Http\Controllers\Gpo\NetworkOutController::class, 'legacyOut'])
      ->middleware('throttle:300,1')
      ->name('gpo.network-out.legacy');
  Route::match(['GET', 'POST'], 'gpo/veyon_out.php', [\App\Http\Controllers\Gpo\VeyonOutController::class, 'legacyOut'])
      ->middleware('throttle:300,1')
      ->name('gpo.veyon-out.legacy');
  ```
- [x] **T5.2** Vérifier que les 2 routes sont bien **HORS** du groupe `sambaedu.admin` (pas d'auth web).

### Phase T6 — Tests comparison + sécurité

- [x] **T6.1** Tests Feature `tests/Feature/Gpo/NetworkOutComparisonTest.php` (AC5.5) — charge fixture `legacy-network-out-startup-linux.txt`, diff strict avec sortie native.
- [x] **T6.2** Tests Feature `tests/Feature/Gpo/VeyonOutComparisonTest.php` (AC5.5) — diff structure JSON (pas `BindPassword`).
- [x] **T6.3** Test injection `tests/Feature/Gpo/NetworkOutSecurityTest.php` (AC4.3) — `id=INJECTION` / `id=' OR 1=1 --` / `id=../../etc/passwd` → 204, aucun appel `samba-tool` / LDAP / `apcu_fetch`.

### Phase T7 — Documentation & QA VM

- [x] **T7.1** Ajouter section 4 dans `docs/qa/domains/gpo.md` (créé en 16.1, enrichi 16.2/16.3a) — scénarios QA manuels VM :
  1. `curl -X POST -d "id=$VALID_ID" http://localhost/gpo/veyon_out.php` retourne JSON parseable
  2. Installer Veyon Master sur poste test, importer la config retournée → connexion à 1 poste de la salle réussie
  3. `curl -F "action=startup" -F "os=linux" -F "id=$VALID_ID" http://localhost/gpo/network_out.php | bash -n` (syntax check) sans erreur
  4. Vérifier que `/tmp/network-startup-{id}.log` est écrit
  5. Vérifier création AD `read.user` au 1er appel (lock acquis) : `ldapsearch -b $base "(cn=read.user*)"` retourne 1 entrée
  6. Re-jouer 2x en parallèle (xargs -P) → pas de race, pas de doublon AD, pas de re-création
  7. `curl -X POST -d "licence=1" http://localhost/gpo/veyon_out.php` retourne `licence.vlf` raw si présent
  8. URL legacy directe `/gpo/network_out.php` non interceptée par catchall (vérifier route native bien prioritaire)
- [x] **T7.2** Documenter dans `app/Gpo/README.md` (créé en 16.1) une section "Endpoints runtime postes clients (Story 16.3b)" listant les 2 endpoints, leur Controller, leur Service, leur pattern d'auth.
- [x] **T7.3** Ajouter convention `@legacy-port` sur **tous** les helpers portés (`networkCreateScript`, `systemProxy`, `gnomeProxy`, `cleandn`, `resolveParcFilter`, etc.) — référence `sambaedu/includes/network.inc.php` ou `sambaedu/gpo/veyon_out.php` + `@todo` refactoring + ligne dans `docs/tech-debt-gpo.md`.

### Phase T8 — Validation finale

- [ ] **T8.1** Lancer `php artisan test tests/Feature/Gpo tests/Unit/Gpo` sur la VM (**ACTION HENRI**)
- [ ] **T8.2** Lancer `php artisan test` complet — aucune régression vs baseline (**ACTION HENRI**)
- [ ] **T8.3** Smoke test Veyon réel selon checklist T7.1 (**ACTION HENRI**)
- [x] **T8.4** Mettre à jour `_bmad-output/implementation-artifacts/sprint-status.yaml` : `16-3b-network-veyon: ready-for-dev` → `review`
- [ ] **T8.5** Vérifier qu'aucune régression sur les logs de boot postes (parc test) après déploiement — `tail -f /tmp/network-*.log` doit montrer des scripts cohérents avec ceux du legacy

---

## File List prévisionnelle

### Fichiers créés

```
app/Http/Controllers/Gpo/NetworkOutController.php
app/Http/Controllers/Gpo/VeyonOutController.php
app/Gpo/Services/NetworkScriptGenerator.php
app/Gpo/Services/VeyonConfigGenerator.php
app/Gpo/Services/ReadUserManager.php

tests/Unit/Gpo/NetworkScriptGeneratorTest.php
tests/Unit/Gpo/VeyonConfigGeneratorTest.php
tests/Unit/Gpo/ReadUserManagerTest.php
tests/Feature/Gpo/NetworkOutEndpointTest.php
tests/Feature/Gpo/VeyonOutEndpointTest.php
tests/Feature/Gpo/NetworkOutComparisonTest.php           ← fixture-based
tests/Feature/Gpo/VeyonOutComparisonTest.php             ← fixture-based
tests/Feature/Gpo/NetworkOutSecurityTest.php              ← injection tests
tests/Fixtures/Gpo/legacy-network-out-startup-linux.txt  ← fixture (T0.10)
tests/Fixtures/Gpo/legacy-veyon-out.json                 ← fixture (T0.10)
```

### Fichiers modifiés

```
routes/web.php                                  (+2 routes Gpo runtime endpoints, T5.1)
app/Gpo/README.md                                (+ section "Endpoints runtime", T7.2)
docs/qa/domains/gpo.md                           (+ section 4 Story 16.3b, T7.1)
docs/tech-debt-gpo.md                            (+ entrées @legacy-port helpers portés)
_bmad-output/implementation-artifacts/sprint-status.yaml  (status update T8.4)
```

### Fichiers NON touchés (régression à éviter)

- `app/Gpo/Services/GpoService.php` — aucune modification (stubs écriture non consommés)
- `app/Gpo/Support/{NativeSectionResolver,GpoLogger,SambaToolRunner,GpoActionLog}.php` — aucune modification
- `app/Gpo/Dto/*` — aucune modification (pas de nouveau DTO ; on consomme `App\Dto\AppCustomization\AppContext` existant)
- `config/sambaedu.php` — aucune modification (D2)
- `config/logging.php` — aucune modification (D9 : channel `daily` standard)
- `legacy/modules/gpo/network_out.php`, `legacy/modules/gpo/veyon_out.php` — non supprimés (AC3.3)
- `resources/views/pages/app/gpo/*` — aucune modification (pas d'UI dans cette story)
- Toutes les Stories 16.1/16.2/16.3a — aucune régression attendue

---

## Test Strategy

### Couverture par niveau

| Niveau | Périmètre | Fichier |
|---|---|---|
| **Unit** | `NetworkScriptGenerator` (header, WPA, 802.1x, system_proxy, gnome_proxy) — logique pure | `tests/Unit/Gpo/NetworkScriptGeneratorTest.php` |
| **Unit** | `VeyonConfigGenerator` (LDAP section, cleandn, encrypt, merge local.json, openent) | `tests/Unit/Gpo/VeyonConfigGeneratorTest.php` |
| **Unit** | `ReadUserManager` (lock, création, reset, validate) | `tests/Unit/Gpo/ReadUserManagerTest.php` |
| **Feature** | `NetworkOutController` (route, validation id, action/os switch, body bash) | `tests/Feature/Gpo/NetworkOutEndpointTest.php` |
| **Feature** | `VeyonOutController` (route, licence sub-action, json structure, 503, lock) | `tests/Feature/Gpo/VeyonOutEndpointTest.php` |
| **Feature comparison** | Diff strict native vs fixture legacy | `tests/Feature/Gpo/{NetworkOut,VeyonOut}ComparisonTest.php` |
| **Feature security** | Injection id / path traversal / SQLi → 204, aucun side effect | `tests/Feature/Gpo/NetworkOutSecurityTest.php` |
| **Smoke VM (manuel)** | 8 scénarios QA T7.1 (Veyon réel, network bash -n, race lock, throttle) | `docs/qa/domains/gpo.md` § 4 |

### Stratégie de mock

- **`AppContextRepository`** : binding container avec stub (`AppContext::fromApcuArray([...])`). Pattern iso `AppPolicyLegacyEndpointTest`.
- **`SambaEduConfig`** : injection directe (instance avec array config en mémoire).
- **AD natif / shim** : mocker l'interface `ReadUserManager` dans les tests Controller (tester `ReadUserManager` à part en unit avec mock du AD service).
- **`Cache::lock`** : `Cache::shouldReceive('lock')->andReturn(...)` Mockery.
- **`Process::run`** (pour `pdbedit ssh` si conservé iso-legacy) : `Process::fake([...])` Laravel standard.
- **`file_get_contents`** sur paths config (`veyon.json`, `default-pubkey.pem`, `licence.vlf`) : utiliser `Storage::fake` ou paths de test redirigés via config.

### Tests à NE PAS faire dans cette story

- E2E navigateur (iso 4.8, pas d'infra).
- Tests réels du client Veyon (= smoke manuel VM, T7.1).
- Tests de réplication AD (out-of-scope, Epic 18).
- Tests SambaTool / `gpo show` (pas appelé ici).
- Bench performance (sortie déterministe stateless ~10ms/req).

---

## Dev Notes — Contraintes & décisions cadrage SM

### Décisions SM rappelées (cf. tableau Décisions SM ci-dessus)

| # | Décision | Impact dev |
|---|---|---|
| D1 | URLs natives strictement iso-legacy | `Route::match` sur path exact, pas d'alias |
| D2 | Interception via `Route::match` AVANT catchall (pas via `blocked_legacy_routes`) | Iso pattern 4.7/4.8 |
| D3 | 2 Controllers + 2 Services + 1 ReadUserManager | 5 nouveaux fichiers métier |
| D4 | Création AD `read.user` portée natif (fallback shim si nécessaire) | Investigation T0.6 + lock + idempotence |
| D5 | Validation `id` md5 strict, 204 si invalide/expiré | Pattern iso 4.8 |
| D6 | Réutilisation `AppContextRepository` existant (4.8) | Pas de nouveau repository |
| D7 | Pas d'auth web, throttle:300,1 | Iso 4.8 |
| D8 | Format sortie strictement iso-bytes legacy | Comparison tests + fixtures |
| D9 | Channel `daily` standard (pas `gpo`) | `Log::error/info/warning` direct |
| D10 | Tests Veyon réel = QA manuel VM, pas auto-CI | 8 scénarios `docs/qa/domains/gpo.md` |
| D11 | Pas d'enrichissement `NativeSectionResolver` | Hors scope cette story |

### Références codebase pour le dev

- **Pattern Controller iso-legacy** :
  - `app/Http/Controllers/AppPolicyController.php` (Firefox/Thunderbird out — 130 lignes)
  - `app/Http/Controllers/WallpaperController.php` (Wallpaper out — env. 130 lignes)
  - `app/Http/Controllers/Api/v1/ShortcutExportController.php` (Shortcuts out — env. 200 lignes)
- **Pattern Service iso-legacy** :
  - `app/Services/AppCustomization/AppCustomizationService.php`
  - `app/Services/Wallpaper/WallpaperResolver.php`
- **Pattern Repository APCu** :
  - `app/Services/AppCustomization/ApcuAppContextRepository.php` (39 lignes)
  - Interface `app/Services/AppCustomization/Contracts/AppContextRepository.php`
  - DTO `app/Dto/AppCustomization/AppContext.php`
- **Pattern Tests Feature** :
  - `tests/Feature/AppCustomization/AppPolicyLegacyEndpointTest.php` (référence pour mock APCu, validation id, throttle)
- **Pattern Routes** :
  - `routes/web.php:350` (`shortcuts_out.php`)
  - `routes/web.php:361` (`wallpaper_out.php`)
  - `routes/web.php:390-395` (`firefox_out.php`, `thunderbird_out.php`)
- **Bridge config legacy → Laravel** :
  - `app/Config/SambaEduConfig.php` (`->get($key, $default)`, `->set($key, $value)` à vérifier T0.7)
  - `config/app-customizations.php` lignes 54-61 (référence proxy keys déjà mappées)
- **Sources legacy à porter** :
  - `legacy/modules/gpo/network_out.php` (54 lignes)
  - `legacy/modules/gpo/veyon_out.php` (141 lignes)
  - `sambaedu/includes/network.inc.php` (`network_create_script`, `system_proxy`, `gnome_proxy` — 173 lignes)
- **Constantes / fichiers système attendus** :
  - `/usr/share/sambaedu/applications/veyon/veyon.json` (template)
  - `/etc/sambaedu/applications/veyon/local.json` (override)
  - `/usr/share/sambaedu/applications/veyon/default-pubkey.pem` (RSA public key pour PKCS1_OAEP)
  - `/etc/sambaedu/applications/veyon/licence.vlf` (licence file, optional)
- **Channel `gpo` (Story 16.1) — NON utilisé ici** : ne pas instancier `GpoLogger` dans ces controllers (cf. D9).
- **Stories de référence pour le pattern global** : 4.7 (Wallpaper), 4.8 (Firefox/Thunderbird), `ShortcutExportController` (refonte historique pre-Epic 16).
- **Audit legacy** : `_bmad-output/planning-artifacts/audit-gpo-legacy.md` fiches §6.A `gpo/network_out.php` (ligne 348) et `gpo/veyon_out.php` (ligne 316).

### Pièges identifiés

1. **`pdbedit ssh` côté `network_create_script`** (`network.inc.php:23`) : le legacy fait `exec("ssh -i /etc/sambaedu/id_rsa root@se4ad pdbedit -Lw " . $samaccountname)` — risque sécu (injection si `samaccountname` non strict). En natif : (a) iso-legacy avec regex stricte `^[A-Za-z0-9_\-\.\$]+$` sur `samaccountname` + `Process::run([ssh, args...])` en mode array, OU (b) remplacer par appel natif `samba-tool user getpassword $name --attributes=dBCSPwd` (à investiguer si dispo). **AC4.2** couvre ce point.

2. **`openssl_public_encrypt` non déterministe** : chaque appel produit un cipher différent (PKCS1_OAEP utilise du padding aléatoire). Tests `VeyonConfigGenerator` doivent **soit** mocker `openssl_public_encrypt` **soit** vérifier que `openssl_private_decrypt($cipher, $clear, $privateKey)` retourne le password original — pas d'égalité stricte sur le cipher. Tests comparison **excluent** `BindPassword` de la diff JSON.

3. **Race condition création `read.user`** : si N postes du parc bootent simultanément et que `read_ldap_password` est vide, N appels concurrents à `ensureReadUser()`. Le lock `Cache::lock('veyon-read-user-create', 30)->get(...)` doit être **bloquant** (pas `tryGet`) avec timeout > 0 pour les requêtes 2..N qui attendent que la 1ʳᵉ ait fini. AC2.5 + tests T3.

4. **`apcu_fetch` indisponible** (extension APCu absente) : `ApcuAppContextRepository::findById` retourne `null` (déjà géré). Le Controller renvoie 204. Pas de crash. **Vérifier que les tests Feature ne nécessitent pas APCu en CI** (mocker le repository via container binding).

5. **Fixtures legacy non-déterministes** : `BindPassword` (chiffré OAEP) et `read_ldap_password` (généré à chaque déploiement) varient. Tests comparison doivent normaliser ces champs avant diff. Marquer `$json['LDAP']['BindPassword'] = '<DYNAMIC>'` dans la normalisation.

6. **`Cache::lock` en mode test** : par défaut `array` driver → lock = noop. Si on veut tester le lock effectif, utiliser `database` driver de test ou Mockery sur la facade. **Décision SM** : test du lock = Mockery `Cache::shouldReceive('lock')->once()`.

7. **Position dans `routes/web.php`** : les routes natives **DOIVENT** être avant le catchall ligne 437. Le dev qui ajoute après par erreur → catchall matche et appelle le shim PHP procédural → silencieux mais incorrect. Tests Feature vérifient via `route()` resolution.

8. **`SambaEduConfig::set` peut ne pas exister** : si l'instance n'est pas mutable, fallback shim `set_config` legacy (`@legacy-port`). À investiguer T0.7. **Ne pas bloquer** : si rien de propre, doc `@legacy-port` + TODO Story 16.4.

9. **Fichiers `/usr/share/sambaedu/applications/veyon/veyon.json` et `default-pubkey.pem` absents en test/CI** : utiliser des fixtures de test (`tests/Fixtures/Gpo/veyon-template.json`, `tests/Fixtures/Gpo/test-pubkey.pem`) + config override (`config(['sambaedu.gpo.veyon.template_path' => ...])`). Ajouter une clé config si nécessaire.

10. **Write `/tmp/network-…log` en test** : peut polluer / poser des problèmes de permission. **Décision** : utiliser `Storage::fake('local')` + un wrapper `FileSystem::write(...)` injectable. Ou skip ce write en `app()->environment('testing')`. À discuter en review.

11. **`os=windows` côté `network_out.php`** : le legacy code (lignes 30-33) définit un header `windows` mais ne génère **rien** pour `os=windows` (le switch est vide). Comportement bogué legacy. **Reproduction iso** = retour 204 body vide. À conserver tel quel (D6 garde-fou Story 16.1).

12. **`config['suffix']`, `config['dn']['people']`, etc.** : noms de clés exotiques du legacy. Vérifier qu'elles existent dans `SambaEduConfig` natif (T0.9). Sinon ajouter au bridge. Notamment `dn.people` est une clé arborescente — `SambaEduConfig::get('dn.people')` doit fonctionner.

13. **`set_config($config, "read_ldap_password", $password)` côté legacy** : modifie la config globale `$config` (passage par valeur, le legacy fait `$config = set_config(...)`). En natif : `SambaEduConfig::set` doit muter l'instance ET persister sur disque (le legacy écrit dans `/etc/sambaedu/config.ini` ou équivalent). À vérifier T0.7.

14. **Charge estimée 2-3 jours réaliste** : `network_out.php` = 0.5j (script bash pur, peu de side effect). `veyon_out.php` = 1.5-2j (création AD, chiffrement, JSON structure, lock, tests). Tests = 0.5j. QA VM = 0.5j Henri.

---

## Project Structure Notes

### Alignement structure projet

- **Controllers** : `app/Http/Controllers/Gpo/` (sous-dossier dédié — cohérence pattern `app/Http/Controllers/Admin/`, `app/Http/Controllers/Api/v1/`). À créer.
- **Services métier** : `app/Gpo/Services/` (sous-dossier déjà créé Story 16.1) — cohérence `NetworkScriptGenerator`, `VeyonConfigGenerator`, `ReadUserManager`.
- **Tests Unit** : `tests/Unit/Gpo/` (existe depuis 16.1).
- **Tests Feature** : `tests/Feature/Gpo/` (existe depuis 16.1, enrichi 16.2/16.3a).
- **Fixtures** : `tests/Fixtures/Gpo/` (à créer — pas encore existant).
- **Routes** : déclarées dans `routes/web.php` (pas d'API séparée — iso-contrat URL legacy).

### Conflits / variances détectés

| Élément | Doc/convention | Décision Story 16.3b | Justification |
|---|---|---|---|
| Sous-dossier Controllers | Pas de convention stricte (`Admin/`, `Api/v1/` coexistent) | `app/Http/Controllers/Gpo/` | Cohérent `Api/v1/`, sépare clairement les endpoints GPO runtime des UIs admin (`AppPolicyController` est à la racine — choix historique mais on diverge pour clarté). |
| Channel logs | Story 16.1 promeut `gpo` | `daily` standard | D9 — endpoints runtime postes ≠ actions admin GPO. |
| Réutilisation `AppContextRepository` | Vit dans `App\Services\AppCustomization\Contracts` | Conservé là, importé | Pas de migration cross-namespace dans cette story (out-of-scope). |
| Pattern Controller mince + Service | Convention projet (`AppPolicyController`, `WallpaperController`) | Iso | OK. |

---

## References

- **Audit legacy** : `_bmad-output/planning-artifacts/audit-gpo-legacy.md`
  - §6.A fiche `gpo/network_out.php` (ligne 348)
  - §6.A fiche `gpo/veyon_out.php` (ligne 316)
  - §6.C tableau sections spécialisées (ligne 481-484)
  - §6.F risques sécurité (F10 création silencieuse compte AD)
  - §6.G recommandation Story 16.3b (ligne 631)
- **Stories de référence (pattern)** :
  - `_bmad-output/implementation-artifacts/4-7-gestion-des-fonds-decran-wallpapers-eloquent.md` — pattern `WallpaperController::legacyOut`
  - `_bmad-output/implementation-artifacts/4-8-personnalisation-apps-extensible.md` — pattern `AppPolicyController` + `AppContextRepository`
- **Fondations Epic 16** :
  - `_bmad-output/implementation-artifacts/16-1-fondations-gpo-natives-audit-legacy.md` — namespace `App\Gpo`, `@legacy-port` convention, garde-fous
  - `_bmad-output/implementation-artifacts/16-2-listing-lecture-gpo-ui-native.md` — pour info, pas consommé
  - `_bmad-output/implementation-artifacts/16-3a-liens-profonds-sections-natives.md` — pour info, pas consommé (cf. D11)
- **Cadrage Epic 16** :
  - `_bmad-output/planning-artifacts/epics.md:3318-3326` — cadrage Story 16.3 splittée
  - `_bmad-output/planning-artifacts/implementation-readiness-report-2026-05-05-epic16.md`
- **Architecture** : `_bmad-output/planning-artifacts/architecture.md:332-353` (couche Services + règle "Controllers fins")
- **Sources legacy** :
  - `legacy/modules/gpo/network_out.php` (54 lignes)
  - `legacy/modules/gpo/veyon_out.php` (141 lignes)
  - `sambaedu/includes/network.inc.php`
- **Code natif référence** :
  - `app/Http/Controllers/AppPolicyController.php`
  - `app/Http/Controllers/WallpaperController.php`
  - `app/Http/Controllers/Api/v1/ShortcutExportController.php`
  - `app/Services/AppCustomization/ApcuAppContextRepository.php`
  - `app/Services/AppCustomization/Contracts/AppContextRepository.php`
  - `app/Dto/AppCustomization/AppContext.php`
  - `app/Config/SambaEduConfig.php`
- **Tests référence** :
  - `tests/Feature/AppCustomization/AppPolicyLegacyEndpointTest.php`
  - `tests/Feature/AppCustomization/AppPolicyCanonicalEndpointTest.php`
- **Permission** : aucune (D7 — endpoints publics throttle).
- **Doc QA** : `docs/qa/domains/gpo.md` (créé 16.1, enrichi 16.2/16.3a, à enrichir 16.3b section 4)
- **Doc tech debt** : `docs/tech-debt-gpo.md` (créé 16.1)

---

## Dev Agent Record

### Agent Model Used

`claude-opus-4-7` (1M context), 2026-05-12.

### Debug Log References

- Lint syntaxique PHP (`php -l`) OK sur tous les fichiers créés (15 fichiers).
- Tests **non exécutés** en local : pas de `vendor/` côté host (`composer install`
  se fait sur la VM). Action Henri T8.1-T8.3 : `php artisan test tests/Feature/Gpo tests/Unit/Gpo` sur VM.

### Completion Notes List

**Application des pré-tranchements Henri (2026-05-12)** :

1. **Fallback shim `@legacy-port`** (D4) — ✅ appliqué. `ReadUserManager` appelle
   `create_ad_user`, `user_valid_passwd`, `usersetpassword`, `set_config` via le
   shim 1bis-18g chargé par `legacy/bootstrap.php` (lazy + idempotent). Docblocks
   `@legacy-port` + `@todo Story 16.4` partout. `LEGACY_SKIP_LEGACY_INCLUDES`
   en tests = pas d'`exec(samba-tool)` côté CI.
2. **`SambaEduConfig::set`** (T0.7) — investigation : aucune méthode mutative
   native. **Fallback shim `set_config`** retenu (cf. `ReadUserManager::persistPassword`).
   Entrée tech-debt ajoutée.
3. **`pdbedit ssh` injection** (piège 1) — iso-legacy STRICT + durcissement :
   regex `^[A-Za-z0-9_\-\.\$]+$` AVANT exec + `escapeshellarg` défense en
   profondeur. Si validation échoue, exec skippé → clé vide → bloc 802.1x émis
   sans password (poste retry au prochain boot). Mitigation tracée tech-debt.
4. **`os=windows` body vide** (piège 11) — reproduit iso, commentaires
   `@legacy-bug` dans `NetworkScriptGenerator::buildStartup` /
   `NetworkOutController::legacyOut`. AC1.6 testé.
5. **Échec création AD `read.user`** (AC2.6) — **option B Henri** appliquée :
   `VeyonOutController` renvoie le JSON sans `BindPassword` (status 200), log
   error level. Pas de 503 Retry-After. Testé via
   `it_strips_bind_password_when_read_user_creation_fails`.

**Autres décisions d'implémentation** :

- **Lock applicatif** anti-race : `Cache::lock('veyon-read-user-create', 30)`
  avec `block(30)` bloquant + double-checked locking (relire la config après
  acquisition pour détecter si un autre worker a déjà créé le compte).
- **Iso-bytes** : header bash strict `\n` (pas `\r\n`), `JSON_PRETTY_PRINT` côté
  Veyon, content-type explicite (`text/plain; charset=utf-8` / `application/json; charset=utf-8`).
- **Validation `id` md5 strict** AVANT tout accès APCu/AD/exec (AC4.3) — vérifié
  par `NetworkOutSecurityTest` avec 7 payloads d'attaque + spy `AppContextRepository`.
- **Write `/tmp/network-…log`** : conservé pour parité legacy (AC1.7), **skippé
  en `app()->environment('testing')`** pour éviter la pollution FS en CI.
- **Comparison tests fixture VM** : marqués `@group requires-fixture-capture` +
  `markTestSkipped` si fixture absent. Henri capturera en T0.10 sur VM si besoin.
- **Routes** déclarées dans `routes/web.php` AVANT le catchall ligne 437, en
  parallèle de `firefox_out.php` / `wallpaper_out.php` (pattern iso 4.7/4.8),
  avec `->middleware('throttle:300,1')`. Test
  `NetworkVeyonRouteRegistrationTest::native_routes_resolve_before_catchall`
  garantit la priorité.

**Points d'attention pour le reviewer** :

- `NetworkScriptGenerator::fetchMachineKey` fait un `exec()` shell (avec
  `escapeshellarg` + regex amont). Non testé en unit (dépend de la VM AD
  réelle) — validé en smoke VM scénarios 4.1/4.6.
- `ReadUserManager::ensurePassword` charge `legacy/bootstrap.php` en lazy. En
  tests, `LEGACY_SKIP_LEGACY_INCLUDES` empêche les vrais appels samba-tool ;
  le mock injecté du `ReadUserManager` côté `VeyonOutEndpointTest` court-circuite
  cette code-path.
- `SambaEduConfig` cache statique : `reload()` invalide après `set_config` shim.
  À surveiller si la VM a un cache APCu actif (potentiel TOCTOU sur cache
  statique de classe partagé entre PHP-FPM workers — chaque worker a son cache,
  donc l'invalidation ne traverse pas les workers : un worker qui a déjà lu
  `read_ldap_password=''` peut conserver cette valeur jusqu'à `reload()`
  explicite. Le lock anti-race protège la création AD, mais pas l'invalidation
  cross-worker du cache — point Story 16.4 à creuser).
- Test `VeyonOutComparisonTest` reste `markTestIncomplete` jusqu'à capture
  fixture VM. Non bloquant CI.
- Le test unit `ReadUserManagerTest` utilise une sous-classe anonyme stub
  (`readUserManagerStub*`) pour override `createReadUserUnderLock` (rendue
  `protected` au lieu de `private` côté `ReadUserManager`). Pas d'API publique
  modifiée — uniquement la testabilité.

### File List

**Fichiers créés** :

```
app/Http/Controllers/Gpo/NetworkOutController.php
app/Http/Controllers/Gpo/VeyonOutController.php
app/Gpo/Services/NetworkScriptGenerator.php
app/Gpo/Services/VeyonConfigGenerator.php
app/Gpo/Services/ReadUserManager.php

# 2026-05-12 review fixes
app/Ldap/AdUserManager.php

tests/Unit/Gpo/NetworkScriptGeneratorTest.php
tests/Unit/Gpo/VeyonConfigGeneratorTest.php
tests/Unit/Gpo/ReadUserManagerTest.php
tests/Feature/Gpo/NetworkOutEndpointTest.php
tests/Feature/Gpo/VeyonOutEndpointTest.php
tests/Feature/Gpo/NetworkOutSecurityTest.php
tests/Feature/Gpo/NetworkVeyonRouteRegistrationTest.php
tests/Feature/Gpo/NetworkOutComparisonTest.php
tests/Feature/Gpo/VeyonOutComparisonTest.php
tests/Fixtures/Gpo/veyon-template.json

# 2026-05-12 review fixes
tests/Unit/Ldap/AdUserManagerTest.php
tests/Unit/Config/SambaEduConfigSetTest.php
```

**Fichiers modifiés** :

```
routes/web.php                                              (+ 2 routes Story 16.3b)
app/Gpo/README.md                                            (+ section « Endpoints runtime postes clients »)
docs/qa/domains/gpo.md                                       (+ section 4 Story 16.3b — 11 scénarios QA VM)
docs/tech-debt-gpo.md                                        (+ 6 entrées dette `@legacy-port` Story 16.3b)
_bmad-output/implementation-artifacts/16-3b-network-veyon.md (status `ready-for-dev` → `review`, Dev Agent Record rempli, tasks tickées T0-T7 + T8.4)
_bmad-output/implementation-artifacts/sprint-status.yaml     (16-3b: `ready-for-dev` → `review` + last_updated)

# 2026-05-12 review fixes (claude-opus-4-7)
app/Config/SambaEduConfig.php                                (+ méthode set() native, write atomique + flock)
app/Gpo/Services/ReadUserManager.php                         (refactor : injection AdUserManager, native SambaEduConfig::set, drift #M1 retour null)
app/Http/Controllers/Gpo/NetworkOutController.php            (HTTP 200 strict iso-legacy, Log::debug, emptyOk helper)
app/Http/Controllers/Gpo/VeyonOutController.php              (HTTP 200 strict, no Content-Type, no-store sur licence fallback)
app/Gpo/Services/NetworkScriptGenerator.php                  (networkCreateScript public → private #8)
legacy/ldap.inc.php                                          (create_ad_user + set_config délèguent aux services natifs)
tests/Unit/Gpo/ReadUserManagerTest.php                       (mock AdUserManager, ajout it_returns_null_when_drift_recovery_fails)
tests/Feature/Gpo/NetworkOutEndpointTest.php                 (assertOk + ajout it_writes_debug_file_to_tmp, it_applies_throttle)
tests/Feature/Gpo/VeyonOutEndpointTest.php                   (assertOk + ajout it_creates_read_user_when_password_missing)
tests/Feature/Gpo/NetworkOutSecurityTest.php                 (assertOk au lieu de assertStatus(204))
tests/Feature/Gpo/VeyonOutComparisonTest.php                 (diff structurel concret, plus markTestIncomplete)
docs/tech-debt-gpo.md                                        (+ 4 entrées review fixes : #3, #M2, #M5, test duplication)
```

**Fichiers NON modifiés** (régression évitée) :

- `app/Gpo/Services/GpoService.php`, `app/Gpo/Support/*` (16.1)
- `app/Services/AppCustomization/*` (4.8)
- `legacy/modules/gpo/network_out.php`, `legacy/modules/gpo/veyon_out.php`
  (shim 1bis-18 conservé pour rollback — AC3.3)
- `config/sambaedu.php`, `config/logging.php` (D2 / D9)

### Change Log

| Date       | Auteur               | Changement                                                            |
|------------|----------------------|-----------------------------------------------------------------------|
| 2026-05-12 | claude-opus-4-7 (SM) | Story créée, status `ready-for-dev`. Recadrage scope vs prompt initial (endpoints HTTP runtime postes clients ≠ pages d'édition admin). 11 décisions SM (D1-D11). 5 volets ACs (NetworkOutController / VeyonOutController + side effect AD / routage iso-contrat / sécurité / tests). 8 phases T0-T8. Pattern strictement iso 4.7/4.8 (`WallpaperController`, `AppPolicyController`, `ShortcutExportController`). 5 fichiers métier créés (2 Controllers + 3 Services), 8 fichiers tests (~50 tests), 2 fixtures legacy. Réutilisation directe `AppContextRepository` (Story 4.8) — pas de nouveau repository. Channel logs `daily` standard (pas `gpo` — D9). Pas de consommation des stubs écriture `GpoService` (recadrage scope, contrairement au brief initial). Création AD `read.user` portée natif avec fallback `@legacy-port` shim si nécessaire (D4). Tests intégration Veyon réel = QA manuel VM (D10). |
| 2026-05-12 | claude-opus-4-7 (dev) | Implémentation complète. 5 pré-tranchements Henri appliqués (D4 fallback shim `@legacy-port` actif sur `ReadUserManager`, T0.7 shim `set_config` faute de natif, piège pdbedit iso-legacy + durcissement regex/escapeshellarg, piège 11 `os=windows` body vide reproduit, AC2.6 option B sans BindPassword si échec AD). 5 fichiers métier (NetworkOutController, VeyonOutController, NetworkScriptGenerator, VeyonConfigGenerator, ReadUserManager) + 9 fichiers tests (3 Unit + 4 Feature + 2 comparison skippables). 2 routes natives ajoutées AVANT le catchall ligne 437. Documentation mise à jour : section 4 `docs/qa/domains/gpo.md` (11 scénarios VM), section « Endpoints runtime » dans `app/Gpo/README.md`, 6 entrées dans `docs/tech-debt-gpo.md`. Status → `review`. T8.1-T8.3 + T8.5 = action Henri (smoke VM). |
| 2026-05-12 | claude-opus-4-7 (review fixes) | Corrections post-review (Sonnet + 2nd avis Opus) — décision Henri **option A complète**. Création `app/Ldap/AdUserManager.php` (service natif AD réutilisable via SambaToolRunner) + ajout méthode `SambaEduConfig::set()` native (write atomique flock+rename). Refactor `ReadUserManager` pour utiliser ces services natifs au lieu des shims `_shim_log_unimplemented`. Shims `legacy/ldap.inc.php::{create_ad_user, set_config}` délèguent désormais à l'`AdUserManager`/`SambaEduConfig::set` si bindable. Décision Henri HTTP iso-legacy strict : `200 body=""` (au lieu de 204) sur tous les paths dégénérés. Drift recovery #M1 : échec bruyant (retour `null` → option B BindPassword strip). #M3 : `Cache-Control: no-store` sur licence fallback. #M4 : `Log::info` → `Log::debug` sur context expiré. #8 : `networkCreateScript` `public` → `private`. #5 : Content-Type retiré sur emptyOk Veyon. `VeyonOutComparisonTest` : diff structurel concret (plus markTestIncomplete). Tests nouveaux : `tests/Unit/Ldap/AdUserManagerTest.php` (15 tests), `tests/Unit/Config/SambaEduConfigSetTest.php` (9 tests). Ajout tests AC5.1/AC5.2 manquants : `it_writes_debug_file_to_tmp`, `it_applies_throttle_300_per_minute`, `it_creates_read_user_when_password_missing`, `it_calls_ensure_password_exactly_once_per_request`, `it_returns_null_when_drift_recovery_fails`. 4 nouvelles entrées tech-debt-gpo.md (#3, #M2, #M5, duplication SambaEduConfigSetTest). Status reste `review` (Henri décide done). |

---

## Recommandation Modèle Dev

**Modèle recommandé : opus**

Raison :

1. **Story à effet de bord AD critique** — la création silencieuse du compte `read.user<suffix>` (D4, AC2.5-2.7) touche l'AD/SYSVOL avec un mot de passe persistant (`SambaEduConfig::set('read_ldap_password')`). Une erreur ici peut (a) créer des doublons de compte AD, (b) corrompre la config, (c) bloquer Veyon parc-wide. La gestion du lock applicatif, la décision de retry/503, le fallback shim `@legacy-port` quand l'API native n'existe pas — tout cela demande du **raisonnement structuré et de la prudence**, pas de la productivité.

2. **Iso-binaire / iso-bytes obligatoire** — la sortie JSON Veyon est consommée par un parser C++ strict côté Veyon client (clés sensibles à la casse, types JSON stricts). Une virgule en plus, un type `string` au lieu de `bool`, un encoding défaillant, et le client Veyon plante silencieusement (parc en perte de supervision). Idem pour le script bash : un `\r\n` au lieu de `\n` casse `bash` côté poste. Le diff fixture/native est non négociable. Le dev doit **lire attentivement** le legacy (141 + 54 lignes) ET le `network.inc.php` (173 lignes) pour porter sans rater une variable.

3. **Chiffrement OpenSSL PKCS1_OAEP + bin2hex** — `openssl_public_encrypt($pwd, $cipher, $key, OPENSSL_PKCS1_OAEP_PADDING)` puis `bin2hex($cipher)`. Test non déterministe → vérification via `openssl_private_decrypt`. Un dev sonnet pourrait simplifier ou rater le padding flag → cipher non décodable par Veyon. Connaissance crypto requise.

4. **Plusieurs décisions à TRANCHER pendant le dev** :
   - D4 — service AD natif vs fallback shim (T0.6 investigation)
   - D4 — `SambaEduConfig::set` disponible vs fallback shim (T0.7)
   - Piège 1 — `pdbedit ssh` injection : iso-legacy avec regex vs remplacer par natif samba-tool
   - Piège 11 — `os=windows` body vide (bug legacy) à reproduire ou améliorer
   - AC2.6 — 503 Retry-After vs JSON sans BindPassword
   Ces arbitrages sont de la conception architecturale en cours de dev, pas du suivi de spec.

5. **Tests intégration Veyon réel = 1 dimension de risque où l'erreur a un coût élevé** — un bug dans `VeyonConfigGenerator` parc-wide. Henri devra accepter d'exposer un parc test au cycle dev → re-dev → re-test. Mieux vaut une 1ʳᵉ implémentation soignée.

6. **Volume modéré, complexité élevée** — 195 lignes legacy à porter, mais la complexité par ligne est haute (chiffrement, side effect AD, lock concurrentiel, parsing/régénération multi-format text+json). Pattern sonnet (reproduction mécanique) sous-performant ici.

7. **Précédents 4.7 / 4.8 — modèles utilisés** : Story 4.8 (`AppPolicyController` + `AppCustomizationService` + Firefox/Thunderbird policy adapters) a été développée en partie par opus (modèles plus anciens) car la résolution policies + APCu + Firefox JSON GPO Windows était jugée fine. Cette story 16.3b est dans la même ligne (même `AppContextRepository`, même iso-contrat URL, même throttle, plus la complexité crypto+AD en bonus).

**Pour mémoire** : un dev sonnet **peut** faire les volets 1 (`NetworkScriptGenerator`), 4 (sécurité/throttle iso-pattern) et 5 (tests pattern existant). Mais le volet 2 (`VeyonConfigGenerator` + `ReadUserManager`) et la phase T0 d'investigation (D4 fallback shim, T0.7 `SambaEduConfig::set`) demandent opus pour ne pas créer de dette ou de bug latent.

**Confiance opus** : 90%.

---

