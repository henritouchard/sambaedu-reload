# Story 15.6 : Réglages de déploiement WPKG configurables au runtime (UI admin)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **Story Epic 15 #6** — Sortir les bascules opérationnelles du canal de
> déploiement Windows (`WPKG_WINGET_ENABLED`, `WPKG_ALLOWED_IPS`) du fichier
> `.env` vers un store runtime piloté par l'admin via l'UI, **sans rebuild de
> cache ni accès shell**.
>
> **Pattern directeur** : `env` = défaut **bootstrap fail-closed**, DB
> (`SystemSetting`) = **override runtime**. Précédence **DB > env > défaut codé**.
> Un système fraîchement provisionné reste fermé jusqu'à ouverture explicite.
>
> **Hors scope** : auth token machine Phase 2 (`workstation_api_secrets`, Story
> 15.5 — l'allowlist UI converge vers ce modèle, ne le remplace pas) ; portage
> du canal legacy mort `packages_xml_out.php` (MySQL) ; refonte du modèle
> d'exécution WPKG/winget.

---

## Story

As a **administrateur système SER**,
I want **activer/désactiver le canal winget et gérer l'allowlist IP des endpoints de déploiement directement depuis l'interface d'administration**,
so that **je puisse ouvrir le canal de livraison logicielle à un parc ou un sous-réseau sans me connecter en SSH, éditer `.env`, relancer `php artisan config:cache` et corriger les droits `www-admin` — opération aujourd'hui obligatoire, faillible (clé `null` si cache non régénéré) et réservée à un opérateur shell**.

---

## Contexte

L'Epic 15 réécrit nativement le pipeline de déploiement WPKG (15.1→15.5 `done`).
Deux **bascules opérationnelles** du canal de livraison Windows ne vivent
aujourd'hui que dans `.env`, donc figées au runtime :

1. **`WPKG_WINGET_ENABLED`** (défaut `false`) → `config('sambaedu.wpkg.winget_enabled')` (`config/sambaedu.php:458`). Consommé par `WingetOutController::handle()` (`app/Wpkg/Deployment/Http/Controllers/WingetOutController.php:42`) : si falsy → **400** (parité legacy `winget_out.php:23-26`). C'est le **gate du canal primaire** d'installation winget : le script client `install.ps1` POST sa liste d'apps installées vers `/wpkg/winget_out.php` et applique la décision JSON `{install, upgrade, uninstall}` renvoyée. Flag off ⇒ 400 ⇒ `install.ps1` n'installe rien.

2. **`WPKG_ALLOWED_IPS`** (défaut `127.0.0.1,::1`) → `config('sambaedu.wpkg.report_ingestion_allowed_ips')` (`config/sambaedu.php:439-440`). Consommé par le middleware `EnsureLocalRequest` (`app/Http/Middleware/EnsureLocalRequest.php`, alias `local.request`) qui protège `/wpkg/winget_out.php` **et** `/wpkg/linux_out.php` (`routes/web.php:701-715`). N'autorise que `127.0.0.1`/`::1` **+** les entrées de cette liste. Un poste sur le LAN se prend un **403 AVANT même le check du flag winget**.

### Pourquoi maintenant (déclencheur terrain 2026-06-10)

Diagnostic d'un poste (`windaube`, groupe `cdi`) n'installant aucun WPKG malgré
une chaîne serveur saine (`hosts.xml`/`profiles.xml`/`packages.xml` cohérents,
profil → `7zip` + `NotepadPlusPlus`) : **deux verrous cumulés**, tous deux dans
`.env` — (1) `WPKG_WINGET_ENABLED=false` → 400, (2) IP LAN du poste absente de
`WPKG_ALLOWED_IPS` → 403. Un **stopgap** a été appliqué à la main sur la VM
(`.env` : `WPKG_WINGET_ENABLED=true`, `WPKG_ALLOWED_IPS=…,192.168.122.0/24` +
`config:cache` + `chown www-admin`). Vérifié : `POST winget_out.php?machine=windaube`
→ **200** `{install:[PowerShell, AppInstaller, 7zip.7zip, Notepad++.Notepad++]}`.

Le stopgap prouve le besoin : ces réglages doivent être **modifiables au runtime
par l'admin système** sans la chorégraphie SSH + `config:cache` + `chown`, qui est
à la fois un irritant opérationnel et un **piège connu** (toute modif `config/*.php`
ou `.env` non suivie de `config:cache` laisse la clé à `null` → tests verts mais
navigateur/endpoint KO).

### Invariants Epic 15 (rappel)

- **Cohésion namespace** : tout nouveau code sous `App\Wpkg\Deployment\*` passe `tests/Architecture/WpkgDeploymentNamespaceTest.php`.
- **Channel `wpkg-deploy`** : les logs structurés du pipeline (ici : changement de réglage, tentative auth refusée) y vont.
- **Filesystem-based router** : pages sous `resources/views/pages/`, Livewire SFC, modale réutilisable `<x-molecules.modal>`, trait `WithToasts`.
- **Non-régression** : absence de réglage DB ⇒ comportement **strictement inchangé** (fallback env → défaut codé).

---

## Dépendances

| Story | Titre | Status attendu | Détail |
|-------|-------|----------------|--------|
| 15-1 | Fondations Pipeline Déploiement WPKG | done | Channel `wpkg-deploy`, config `sambaedu.wpkg.*` |
| 15-2 | Generators XML + .ini | done | Endpoints `/wpkg/hosts.xml` + `/wpkg/profiles.xml` (sans `local.request`) |
| 15-5 | Pipeline rapports + dashboard | done | A introduit / anticipé `workstation_api_secrets` (Phase 2 Bearer). **Cette story ne touche pas au Bearer** — elle gère uniquement les 2 réglages env→DB. La convergence allowlist→Bearer est documentée, pas implémentée. |
| 17.6 | Endpoints `winget_out` / `linux_out` | done | `WingetOutController` (gate flag), routes `routes/web.php:701-715`, middleware `local.request` |
| — | `SystemSetting` (Story 5.1c) | done | Store K/V JSON (`app/Models/SystemSetting.php`), déjà branché sur `/admin/settings` (onglet Quotas) ; helpers statiques `get`/`set`/`forget` |
| — | Page admin existante | done | `resources/views/pages/admin/settings/gpo/wpkg-deployment/index.blade.php` (Livewire SFC, permission `server.admin`) — **étendue** ici |

---

## Décisions SM (à valider en T0)

### D1 — Source de vérité et précédence

`SystemSetting` (DB) **override** `config()` (env). Précédence stricte :
**DB > env (`config`) > défaut codé fail-closed**. Clés DB :

| Clé `SystemSetting` | Type stocké (JSON) | Défaut fallback |
|---------------------|--------------------|-----------------|
| `wpkg.winget_enabled` | `bool` | `config('sambaedu.wpkg.winget_enabled')` (env, défaut `false`) |
| `wpkg.allowed_ips` | `array<string>` (IP ou CIDR) | `config('sambaedu.wpkg.report_ingestion_allowed_ips')` (env, défaut `['127.0.0.1','::1']`) |

> **`127.0.0.1`/`::1` restent TOUJOURS autorisés en dur** dans `EnsureLocalRequest` (worker local, healthchecks) — l'allowlist DB/env ne fait qu'**ajouter**. Ce comportement existant ne change pas.

### D2 — Résolveur centralisé (pas de `SystemSetting::get` dispersé)

Introduire `App\Wpkg\Deployment\Services\WpkgDeploymentSettings` (cohésion namespace 15.x, testable, injectable) :

```php
final class WpkgDeploymentSettings
{
    public function wingetEnabled(): bool
    {
        return (bool) SystemSetting::get('wpkg.winget_enabled', config('sambaedu.wpkg.winget_enabled', false));
    }

    /** @return array<int,string> liste d'IP/CIDR additionnels (hors localhost en dur) */
    public function allowedIps(): array
    {
        $default = config('sambaedu.wpkg.report_ingestion_allowed_ips', []);
        $value = SystemSetting::get('wpkg.allowed_ips', $default);
        return is_array($value) ? array_values(array_filter($value, fn ($e) => is_string($e) && $e !== '')) : $default;
    }
}
```

**Les deux points de lecture appellent ce résolveur**, plus `config()` direct :
- `WingetOutController::handle()` : remplacer `config('sambaedu.wpkg.winget_enabled', false)` par `app(WpkgDeploymentSettings::class)->wingetEnabled()`.
- `EnsureLocalRequest::isAllowed()` : remplacer la lecture `config('sambaedu.wpkg.report_ingestion_allowed_ips')` par `app(WpkgDeploymentSettings::class)->allowedIps()`.

> **Attention non-régression** : `EnsureLocalRequest` est un middleware **générique** (commentaire : « endpoints d'ingestion locale »). Vérifier qu'il n'est utilisé QUE pour des endpoints WPKG/reports (grep alias `local.request`). S'il protège d'autres endpoints hors-WPKG, la lecture DB doit rester sémantiquement « allowlist d'ingestion WPKG » — c'est le cas (routes `wpkg/*` + `api/wpkg/reports/*`). Documenter le périmètre exact en Dev Notes.

### D3 — Validation de l'allowlist (frontière de sécurité)

Ces endpoints sont **non authentifiés** (auth iso-legacy : postes pas enrôlés au boot/install — pas de Bearer/secret généralisé). L'allowlist IP **est** la frontière de sécurité. La saisie UI doit donc être **stricte** :

- Chaque entrée doit être une IP valide (v4/v6) **ou** un CIDR valide (`IpUtils`-compatible).
- **Rejet dur** : `0.0.0.0/0` et `::/0` (ouvrir à tout Internet).
- **Rejet** des préfixes trop larges : IPv4 préfixe `< /16`, IPv6 `< /32` (garde-fou anti-erreur ; valeur seuil à confirmer T0). Message clair.
- Doublons ignorés, entrées vides filtrées.
- Règle de validation isolée et testable : `App\Wpkg\Deployment\Rules\SafeIpCidrRule` (ou validation inline documentée).

### D4 — Permission

Réglage d'**infrastructure / sécurité**, pas d'affectation d'apps. → permission **`server.admin`** (cohérent avec la page hôte `wpkg-deployment` existante, `mount()` ligne 51-55), **PAS** `wpkg.assign`. La mutation (save) ET la lecture du panneau sont gardées `server.admin`.

### D5 — Audit obligatoire (trou Livewire connu)

Toute modification de l'un des deux réglages **doit être auditée** (qui / quand / ancienne→nouvelle valeur). **Piège** : l'audit HTTP par middleware **ne capture pas** les mutations du canal Livewire (`livewire/update`) — projet Livewire-first, constat story 20.4, cf. mémoire projet « Audit HTTP rate le canal Livewire ». → l'action Livewire `save()` **émet elle-même** l'entrée d'audit (appel explicite au service d'audit existant + log structuré `wpkg-deploy`), ne pas compter sur un middleware. Élargir l'allowlist (ajout d'un CIDR) est l'événement sensible à tracer en priorité.

### D6 — Effet immédiat, pas de cache bloquant

`SystemSetting::get` lit la DB à chaque appel (une requête indexée triviale). `winget_out.php` est appelé au boot de chaque poste → acceptable (1 SELECT par requête). **Optionnel** : cache request-scoped ou `Cache` courte TTL avec invalidation sur `save()`. **Décision** : pas de cache en v1 (simplicité + garantie « effet immédiat » exigée par AC) ; si profilage montre un coût, ajouter un cache invalidé à l'écriture (documenter le choix en Dev Notes). **Aucun `config:cache` requis** côté admin — c'est tout le point de la story.

### D7 — UI : section dans la page existante

Étendre `pages/admin/settings/gpo/wpkg-deployment/index.blade.php` avec une **nouvelle carte « Réglages de déploiement »** en tête de page (avant l'audit GPO existant), ou un onglet dédié. Réutiliser le pattern daisyUI de la page (`card bg-base-100`, `badge`, `data-testid`), le trait `WithToasts`, et `<x-molecules.modal>` pour la confirmation D8. **Ne pas casser** l'audit GPO existant ni sa permission.

### D8 — Modale de confirmation pour l'élargissement allowlist

Activer/désactiver le toggle winget = action simple (toast). **Ajouter un CIDR à l'allowlist** (élargir la frontière de sécurité) = modale de confirmation `<x-molecules.modal>` rappelant que l'endpoint est non authentifié et que l'entrée ouvre l'ingestion WPKG à ce périmètre. Retrait d'une entrée / toggle winget : pas de modale.

---

## Acceptance Criteria

### Volet 1 — Résolveur & précédence

**AC1.1** — `WpkgDeploymentSettings::wingetEnabled()` retourne la valeur `SystemSetting('wpkg.winget_enabled')` si la clé existe, sinon `config('sambaedu.wpkg.winget_enabled')`.
**AC1.2** — `WpkgDeploymentSettings::allowedIps()` retourne `SystemSetting('wpkg.allowed_ips')` (array) si présent, sinon `config('sambaedu.wpkg.report_ingestion_allowed_ips')`. Entrées vides/non-string filtrées.
**AC1.3** — **Non-régression** : aucune clé DB définie ⇒ les deux méthodes retournent exactement les valeurs env actuelles (comportement strictement inchangé).
**AC1.4** — Le service est sous `App\Wpkg\Deployment\Services\` et passe `WpkgDeploymentNamespaceTest`.

### Volet 2 — Câblage des points de lecture

**AC2.1** — `WingetOutController::handle()` lit le flag via `WpkgDeploymentSettings::wingetEnabled()`. Quand `SystemSetting('wpkg.winget_enabled')=true` (et env reste `false`), un POST `action=list` valide → **200** + décision JSON (plus de 400). Quand `=false` → **400** (parité préservée).
**AC2.2** — `EnsureLocalRequest::isAllowed()` lit l'allowlist via `WpkgDeploymentSettings::allowedIps()`. Une IP couverte par un CIDR DB (ex. `192.168.122.50` ∈ `192.168.122.0/24`) → autorisée ; hors allowlist → **403**. `127.0.0.1`/`::1` toujours autorisés même allowlist DB vide.
**AC2.3** — L'override allowlist s'applique **identiquement** à `/wpkg/winget_out.php` ET `/wpkg/linux_out.php` (même middleware) — test sur les deux routes.
**AC2.4** — **Effet immédiat** : modifier la valeur via `SystemSetting::set(...)` puis refaire la requête (même cycle de test, sans `config:cache`) reflète la nouvelle valeur.

### Volet 3 — Validation allowlist

**AC3.1** — Entrée IP v4/v6 valide ou CIDR valide → acceptée.
**AC3.2** — `0.0.0.0/0` et `::/0` → **rejetées** avec message explicite.
**AC3.3** — Préfixe trop large (IPv4 `< /16`, IPv6 `< /32`, seuil confirmé T0) → rejeté.
**AC3.4** — Entrée syntaxiquement invalide (`999.1.1.1`, `abc`, `192.168.0.0/40`) → rejetée.
**AC3.5** — Doublons et entrées vides → normalisés (dédupliqués / filtrés) sans erreur.

### Volet 4 — UI admin

**AC4.1** — La page `/admin/settings/gpo/wpkg-deployment` affiche une carte « Réglages de déploiement » avec : toggle `winget_enabled` (état courant = valeur résolue), éditeur de liste `allowed_ips` (ajout/suppression), badge indiquant la **source** de chaque valeur (`DB` override vs `env` défaut).
**AC4.2** — Toggle winget on/off → persiste via `SystemSetting::set('wpkg.winget_enabled', bool)` + toast succès + audit (AC5).
**AC4.3** — Ajout d'un CIDR → **modale de confirmation** (D8) rappelant l'absence d'auth machine ; confirmation → `SystemSetting::set('wpkg.allowed_ips', [...])` + toast + audit. Suppression → pas de modale.
**AC4.4** — Saisie invalide (Volet 3) → message d'erreur inline, **pas** de persistance.
**AC4.5** — `mount()` : `abort_unless(auth()->user()->can('server.admin'), 403)`. Un user sans `server.admin` → 403. La carte n'apparaît pas / actions masquées si pas la permission.
**AC4.6** — L'audit GPO existant de la page (re-auditer / re-publier) reste **fonctionnel et inchangé** (non-régression visuelle + comportementale).

### Volet 5 — Audit

**AC5.1** — Chaque `save()` (toggle ou allowlist) émet une entrée d'audit explicite (service d'audit existant) avec : `user_id`, réglage modifié, ancienne valeur, nouvelle valeur, timestamp. **Émise depuis l'action Livewire** (pas via middleware HTTP — cf. D5).
**AC5.2** — Log structuré `wpkg-deploy` : `event: wpkg_deployment_setting_changed`, `setting`, `old`, `new`, `user_id`.

### Volet 6 — Tests

**AC6.1** — `WpkgDeploymentSettingsTest` (unit) : précédence DB>env>défaut pour les deux clés ; AC1.3 non-régression ; filtrage entrées.
**AC6.2** — Feature `WingetOutController` : 200/400 piloté par `SystemSetting` sans `config:cache` (AC2.1).
**AC6.3** — Feature `EnsureLocalRequest` : IP autorisée via CIDR DB / refusée hors liste / localhost toujours OK / les deux routes (AC2.2-2.3).
**AC6.4** — `SafeIpCidrRule` (unit) : tous les cas Volet 3.
**AC6.5** — Livewire (`Livewire::test`) : rendu, toggle persiste, modale allowlist, validation rejette, 403 sans `server.admin`, audit émis (AC5).
**AC6.6** — Non-régression : suite existante de la page wpkg-deployment + `WpkgOutRoutesTest` + `WpkgDeploymentNamespaceTest` vertes.
**AC6.7** — PHPUnit attributes (`#[Test]`, `#[DataProvider]`, `#[Group]`) — convention projet.

### Volet 7 — Documentation

**AC7.1** — `app/Wpkg/Deployment/README.md` : section « Réglages runtime (Story 15.6) » — tableau clés `SystemSetting`, précédence, points de lecture, rappel fail-closed.
**AC7.2** — Runbook QA (`docs/qa/domains/wpkg-deploy.md`) : scénario « activer winget + ajouter le CIDR du parc depuis l'UI → poste installe sans SSH ».
**AC7.3** — Note de migration : une fois cette story livrée, le **stopgap `.env` VM** (`WPKG_WINGET_ENABLED=true`, `WPKG_ALLOWED_IPS=…/24`) peut être **rebasculé en DB** via l'UI et `.env` revenir aux défauts fail-closed. Documenter (pas d'automatisation requise).

---

## Hors scope (explicite)

- **Auth token machine Phase 2** (`workstation_api_secrets`, Bearer — Story 15.5). L'allowlist UI **converge** vers ce modèle (point d'extension `EnsureLocalRequest`/middleware Bearer) mais ne l'implémente pas ici.
- **Portage de `packages_xml_out.php`** (canal VBS legacy lisant MySQL morte) — couche morte, hors scope (mémoire projet dédiée).
- **Refonte du modèle d'exécution** WPKG vs winget (impératif/déclaratif) — un tuyau, deux outils ; on ne fusionne rien.
- **Réglages additionnels** (timeouts, chemins catalogues winget add/remove, retention rapports) — pourraient suivre le même pattern plus tard ; pas dans cette story.
- **Synchronisation `.env` ↔ DB bidirectionnelle** — la DB override l'env, point. Pas de réécriture de `.env` depuis l'UI.
- **Provisioning legacy `conf.d`** (`/etc/sambaedu/sambaedu.conf.d/{clients,wpkg}.conf`) — alimente le shim legacy, pas SE5 ; hors scope.

---

## Tasks / Subtasks

- [x] **T0 — Audit pré-dev (~30 min)**
  - [x] Confirmer 15-1→15-5 `done`, 17.6 `done`, `SystemSetting` opérationnel.
  - [x] `grep` l'alias `local.request` : confirmer qu'il ne protège que des endpoints WPKG/reports (périmètre du changement allowlist). Documenter.
  - [x] Confirmer le seuil de préfixe « trop large » (D3 : `/16` v4, `/32` v6 ?).
- [x] **T1 — Résolveur `WpkgDeploymentSettings`** (AC1)
  - [x] Service sous `App\Wpkg\Deployment\Services\` + tests unit précédence.
- [x] **T2 — Câblage points de lecture** (AC2)
  - [x] `WingetOutController` → résolveur (flag).
  - [x] `EnsureLocalRequest` → résolveur (allowlist).
  - [x] Tests feature 200/400 + 403/allow sur les 2 routes, effet immédiat sans `config:cache`.
- [x] **T3 — Validation allowlist** (AC3)
  - [x] `SafeIpCidrRule` + tests (rejet `/0`, préfixes larges, syntaxe).
- [x] **T4 — UI** (AC4)
  - [x] Étendre la page Livewire wpkg-deployment : carte réglages, toggle, éditeur liste, badge source DB/env.
  - [x] Modale confirmation élargissement allowlist (D8).
  - [x] Garde `server.admin` ; non-régression audit GPO existant.
  - [x] Tests `Livewire::test`.
- [x] **T5 — Audit** (AC5)
  - [x] Émission audit explicite depuis `save()` + log `wpkg-deploy`.
  - [x] Test audit émis (attention : pas via middleware HTTP).
- [x] **T6 — Docs** (AC7)
  - [x] README namespace, runbook QA, note migration stopgap→DB.
- [x] **T7 — Non-régression & archi**
  - [x] `WpkgDeploymentNamespaceTest`, `WpkgOutRoutesTest`, suite page wpkg-deployment vertes.

---

## Dev Notes

### Patterns & contraintes

- **Pattern env→DB déjà éprouvé** par `SystemSetting` (onglet Quotas, `resources/views/pages/admin/settings/_partials/quotas-fs-tab.blade.php`) — s'en inspirer pour l'UI et la persistance. **Ne pas réinventer** un mécanisme de settings.
- **`SystemSetting` cast `value => 'array'`** : stocker `wpkg.winget_enabled` comme `bool` direct (le cast array gère les scalaires JSON) ; `wpkg.allowed_ips` comme `array<string>`. Vérifier le round-trip bool (pgsql/sqlite) via test.
- **`EnsureLocalRequest`** : `127.0.0.1`/`::1` en `const ALWAYS_ALLOWED` — ne pas toucher. La lecture config devient lecture résolveur. `IpUtils::checkIp()` gère déjà le CIDR.
- **`WingetOutController:42`** est le SEUL site du flag — un seul changement.
- **Fail-closed** : si `SystemSetting` indisponible (DB down) le `get()` lèverait — mais ce cas casse déjà tout le pipeline ; ne pas sur-ingénier un fallback silencieux qui ouvrirait la frontière.

### Source tree à toucher

- `app/Wpkg/Deployment/Services/WpkgDeploymentSettings.php` (nouveau)
- `app/Wpkg/Deployment/Rules/SafeIpCidrRule.php` (nouveau)
- `app/Wpkg/Deployment/Http/Controllers/WingetOutController.php` (1 ligne)
- `app/Http/Middleware/EnsureLocalRequest.php` (lecture résolveur)
- `resources/views/pages/admin/settings/gpo/wpkg-deployment/index.blade.php` (extension)
- `app/Wpkg/Deployment/README.md`, `docs/qa/domains/wpkg-deploy.md`
- Tests : `tests/Unit/Wpkg/Deployment/`, `tests/Feature/Wpkg/...`

### Piège audit Livewire (critique)

Le middleware d'audit HTTP **ne voit pas** `livewire/update`. L'entrée d'audit
DOIT être émise dans la méthode `save()` Livewire elle-même. Vérifier le service
d'audit utilisé ailleurs dans le projet (chercher les appels existants) plutôt que
d'en inventer un.

### Project Structure Notes

- Convention filesystem-based router respectée (page sous `pages/admin/settings/gpo/wpkg-deployment/`).
- Aucune migration (table `system_settings` existe). Aucun changement `config/sambaedu.php` (l'env reste le défaut bootstrap).

### References

- [Source: app/Wpkg/Deployment/Http/Controllers/WingetOutController.php#L42] — gate flag (400)
- [Source: app/Http/Middleware/EnsureLocalRequest.php] — allowlist IP + `ALWAYS_ALLOWED`
- [Source: config/sambaedu.php#L439-L458] — `report_ingestion_allowed_ips` (← `WPKG_ALLOWED_IPS`), `winget_enabled` (← `WPKG_WINGET_ENABLED`)
- [Source: routes/web.php#L701-L715] — routes `winget_out`/`linux_out` + middleware `local.request`
- [Source: app/Models/SystemSetting.php] — store K/V JSON, helpers `get`/`set`/`forget`
- [Source: resources/views/pages/admin/settings/gpo/wpkg-deployment/index.blade.php] — page hôte (permission `server.admin`, patterns daisyUI/modale/toasts)
- [Source: resources/views/pages/admin/settings/_partials/quotas-fs-tab.blade.php] — précédent UI `SystemSetting`
- [Source: app/Enums/SambaPermission.php#L53] — `WpkgAssign` (NON utilisée ici — voir D4, on prend `server.admin`)
- Mémoire projet : « Audit HTTP rate le canal Livewire » (D5) ; « VM config cachée ≠ synced » (motivation runtime) ; « auth iso-legacy » (D3, endpoint non authentifié)

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- Bug #1 : `$entry` non capturé dans closure de `validateIpCidrEntry()` → `Undefined variable $entry` ; fix : ajouter `$entry` au `use` clause.
- Bug #2 : test `toggle_winget_aborts_403_without_server_admin` utilisait `expectException()` mais Livewire intercepte `abort(403)` → réponse HTTP, pas exception PHP ; corrigé en `assertStatus(403)`.
- Non-régression cassée : `WpkgDeploymentPageTest` + `WpkgDeploymentPagePermissionTest` échouaient sur `no such table: system_settings` car le composant remanié charge `system_settings` dans `mount()` ; corrigé en ajoutant la création inline dans leurs `setUp()`.
- Pattern SQLite :memory: : `DatabaseTransactions` + création table inline dans `setUp()` requis pour tous les tests unitaires et feature avec `system_settings`.

### Completion Notes List

- T0 : alias `local.request` protège exclusivement `winget_out`, `linux_out`, `api/wpkg/reports/*` — aucun autre endpoint affecté.
- T0 : seuils confirmés `/16` IPv4, `/32` IPv6 (SafeIpCidrRule constantes).
- T1-T3 : résolveur + câblage + règle validation livrés, 79 tests story-15-6 verts.
- T4 : page Livewire étendue avec carte déploiement, toggle, allowlist editor, badges source, modale CIDR.
- T5 : audit via `Log::channel('wpkg-deploy')->info('réglage modifié', [...])` dans chaque action Livewire write.
- T6 : README namespace et runbook QA appended (append-only convention).
- T7 : 18 tests non-régression verts (WpkgDeploymentPageTest + WpkgDeploymentPagePermissionTest + WpkgDeploymentNamespaceTest + WpkgOutRoutesTest).
- Note migration stopgap : `WPKG_WINGET_ENABLED=true` + `WPKG_ALLOWED_IPS=…/24` dans `.env` VM peuvent être basculés en DB via l'UI puis `.env` revenir aux défauts fail-closed.

### Corrections post-review (2026-06-10)

Corrections appliquées suite à la review adversariale Opus (#4 laissé en l'état, décision utilisateur).

**Fix #1 — `system_settings` dans `WpkgSchemaBootstrapper`**
- `tests/Support/WpkgSchemaBootstrapper.php` : ajout de la création de `system_settings` (calque exact de la migration) dans `bootstrap()` + `tearDown()`.
- `tests/Feature/Wpkg/Deployment/Http/WingetOutSettingsTest.php` : suppression du patch inline `system_settings` (désormais couvert par le bootstrapper). Import `Schema`/`Blueprint` retirés.
- `tests/Feature/Wpkg/Deployment/Http/EnsureLocalRequestSettingsTest.php` : idem.
- `tests/Unit/Wpkg/Deployment/Services/WpkgDeploymentSettingsTest.php` : migré vers `WpkgSchemaBootstrapper::bootstrap()` + `tearDown()`, suppression du patch inline.

**Fix #2 — Audit AC5 contraignant (assertions réelles)**
- `tests/Feature/Gpo/WpkgDeploymentSettingsPageTest.php` : `toggle_winget_emits_structured_log_audit` et `confirm_add_cidr_emits_log_with_required_fields` réécrits avec `Log::shouldReceive(...)->once()->withArgs(...)` + capture dans closure + `assertTrue($logInfoCalled)`. La signature réelle confirmée : `Log::channel('wpkg-deploy')->info('[WpkgDeploymentSettings] réglage modifié', ['event' => 'wpkg_deployment_setting_changed', 'setting' => ..., 'old' => ..., 'new' => ..., 'user_id' => ...])`.

**Fix #3 — Fail-closed en lecture dans `allowedIps()`**
- `app/Wpkg/Deployment/Rules/SafeIpCidrRule.php` : ajout méthode statique `isSafe(string $entry): bool` (évite la duplication sans instanciation externe).
- `app/Wpkg/Deployment/Services/WpkgDeploymentSettings.php` : `allowedIps()` filtre chaque entrée via `SafeIpCidrRule::isSafe()` ; les entrées rejetées loguent un warning `event: wpkg_allowed_ip_rejected` sur le channel `wpkg-deploy`. `127.0.0.1` et `::1` sont préservés explicitement.
- `tests/Unit/Wpkg/Deployment/Services/WpkgDeploymentSettingsTest.php` : 5 nouveaux cas (0.0.0.0/0, ::/0, /8 trop large, liste mixte, localhost préservé).
- `tests/Feature/Wpkg/Deployment/Http/EnsureLocalRequestSettingsTest.php` : 1 nouveau cas `db_polluted_with_deny_all_cidr_still_rejects_external_ip` (203.0.113.5 → 403 même si `0.0.0.0/0` en DB).

**Fix #5 — Garde 403 par action (abort_unless prouvé)**
- `tests/Feature/Gpo/WpkgDeploymentSettingsPageTest.php` : ajout de `toggle_winget_action_itself_aborts_403_for_non_admin` et `confirm_add_cidr_action_itself_aborts_403_for_non_admin` qui prouvent que l'`abort_unless` dans `toggleWinget()` / `prepareAddCidr()` est évalué à chaque appel, pas seulement au `mount()`.

**Fix #6 — Assertions positives dans `EnsureLocalRequestSettingsTest`**
- `tests/Feature/Wpkg/Deployment/Http/EnsureLocalRequestSettingsTest.php` : 5 `assertNotEquals(403, ...)` remplacés par `assertOk()` (statut métier réel confirmé : winget_out avec params valides → 200, linux_out sans id valide → 200 corps vide).

**Résultat final : 129 passed, 0 failed, 0 risky**
`php artisan test --filter='WpkgDeployment|WingetOut|LinuxOut|EnsureLocalRequest|SafeIpCidr|WpkgOutRoutes|WpkgDeploymentNamespace'`

### File List

- `app/Wpkg/Deployment/Services/WpkgDeploymentSettings.php` — nouveau
- `app/Wpkg/Deployment/Rules/SafeIpCidrRule.php` — nouveau
- `app/Wpkg/Deployment/Http/Controllers/WingetOutController.php` — modifié (câblage résolveur)
- `app/Http/Middleware/EnsureLocalRequest.php` — modifié (câblage résolveur)
- `resources/views/pages/admin/settings/gpo/wpkg-deployment/index.blade.php` — modifié (extension UI + Livewire)
- `app/Wpkg/Deployment/README.md` — modifié (section story 15.6 appendée)
- `docs/qa/domains/wpkg-deploy.md` — modifié (section 7 appendée)
- `tests/Unit/Wpkg/Deployment/Services/WpkgDeploymentSettingsTest.php` — nouveau
- `tests/Unit/Wpkg/Deployment/Rules/SafeIpCidrRuleTest.php` — nouveau
- `tests/Feature/Wpkg/Deployment/Http/WingetOutSettingsTest.php` — nouveau
- `tests/Feature/Wpkg/Deployment/Http/EnsureLocalRequestSettingsTest.php` — nouveau
- `tests/Feature/Gpo/WpkgDeploymentSettingsPageTest.php` — nouveau
- `tests/Feature/Gpo/WpkgDeploymentPageTest.php` — modifié (ajout création table system_settings dans setUp)
- `tests/Feature/Gpo/WpkgDeploymentPagePermissionTest.php` — modifié (ajout création table system_settings dans setUp)
