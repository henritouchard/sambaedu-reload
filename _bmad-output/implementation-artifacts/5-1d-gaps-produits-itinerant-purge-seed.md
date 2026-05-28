# Story 5.1d : Gaps produits filesystem (default_itinerant, purge trash, seed legacy)

Status: done

> **Origine** : Epic 5 — Système de Fichiers SER. Quatrième et dernière sous-story de la Story 5.1 splittée le 2026-04-22 (brainstorm Henri + investigation `sambaedu/includes/quotas.inc.php`).
>
> **Dépendances amont (toutes livrées)** :
> - **5.1a done** (2026-04-23 — commit `fb0b0b6`) — `App\Services\Filesystem\HomeDirService` (méthode `deleteHomeDirectoryPermanently(string $login): bool` exposée publique) + `App\Services\Filesystem\XfsQuotaService::getEffectiveQuota(...)` extraits.
> - **5.1c done** (2026-04-27, validé Henri post polish UX) — table `system_settings` K/V JSON + modèle `SystemSetting::get/set/forget` + onglet `/admin/settings → Quotas & FS` avec section "Corbeille" persistant `quota.trash` = `{ttl_days: int, purge_auto: bool}` + extension passive `QuotaRule::TYPE_DEFAULT_ITINERANT` (constante PHP, persistée par la section "Defaults profils → itinérant" de l'onglet, mais NON consommée par `getEffectiveQuota`).
>
> **Scope 5.1d — finition produit** :
> 1. **Volet 1 — `default_itinerant` actif** : modifier `XfsQuotaService::getEffectiveQuota()` pour appliquer la règle `TYPE_DEFAULT_ITINERANT` quand l'utilisateur est externe (`User::isExternal() == true`) ET qu'aucune règle explicite user/group ne s'applique. Fallback sur le default profil si `default_itinerant` absente.
> 2. **Volet 2 — Commande `trash:purge`** : commande Artisan qui parcourt `/home/trash/*`, supprime les sous-dossiers dont le `mtime` dépasse `quota.trash.ttl_days` configuré dans `/admin/settings`. Planifiée quotidiennement à 02h00 (avant snapshot 03h00) **conditionnée** par le toggle `quota.trash.purge_auto`. Bouton "Purger maintenant" ajouté à la section Corbeille de l'onglet Quotas & FS.
> 3. **Volet 3 — Commande `quota:seed-from-legacy`** : commande Artisan one-shot idempotente qui lit les règles legacy (table MySQL `quotas` du legacy via une connexion configurée) et crée les `QuotaRule` correspondantes + initialise les defaults profils dans `SystemSetting`. `--dry-run`, `--force`, rapport texte (importées / skipped / errors).
>
> **Stories avales** : aucune. 5.1d clôt la Story 5.1 splittée.

---

## Story

En tant que **responsable de collège**,
je veux que les utilisateurs itinérants aient automatiquement un quota réduit (`default_itinerant`), que la corbeille `/home/trash/` se purge selon le TTL et le toggle que j'ai réglés dans `/admin/settings` (auto via cron 02h00 ou manuel via bouton "Purger maintenant"), et que les règles `quotas` du legacy MySQL soient importées une fois pour toutes via une commande dédiée idempotente,
afin de ne pas avoir à configurer chaque utilisateur manuellement, de récupérer mes 50 Go d'archives sans intervention shell, et que la transition depuis l'ancien système soit transparente sans perte d'historique de quotas.

---

## Contexte & Motivation

### Ce qui est déjà livré (à NE PAS refaire)

| Composant | État | Détail |
|---|---|---|
| `App\Services\Filesystem\HomeDirService::deleteHomeDirectoryPermanently(string $login): bool` | livré 5.1a | l. **160-182** de [HomeDirService.php](../../app/Services/Filesystem/HomeDirService.php). Garde regex login `/^[a-zA-Z0-9._-]+$/`. `escapeshellarg` + `sudo rm -rf /home/trash/{login} 2>&1`. Renvoie `true` si rien à supprimer (idempotent). **Réutilisable tel quel** par `trash:purge` — le service ne fait pas la liste, il supprime un login passé en paramètre. |
| `App\Services\Filesystem\XfsQuotaService::getEffectiveQuota(string $username, string $partition, array $userGroups = [], string $userProfile = 'eleve'): array` | livré 5.1a | l. **71-155** de [XfsQuotaService.php](../../app/Services/Filesystem/XfsQuotaService.php). Hiérarchie : USER > GROUP (max) > DEFAULT_{ELEVE/PROF/ADMIN} via `match($userProfile)`. **Pas de prise en compte de `User::isExternal()` aujourd'hui** — c'est l'objet du Volet 1. |
| `App\Models\QuotaRule::TYPE_DEFAULT_ITINERANT = 'default_itinerant'` | livré 5.1c (D12=A) | Constante PHP + listé dans `isDefault()`, `scopeDefaults()`, `getTypeLabel()` (l. **42-167** de [QuotaRule.php](../../app/Models/QuotaRule.php)). **Le champ DB `type` est un `string(20)`** (pas un enum SQL — cf. migration `2026_02_20_100000_create_quota_tables.php:24`) → **PAS de migration enum nécessaire en 5.1d**. La valeur est déjà persistée par l'UI 5.1c, simplement non lue. |
| `App\Models\User::isExternal(): bool` | livré pré-Epic 5 | l. **304-317** de [User.php](../../app/Models/User.php). Compare `$this->school_code` (lower) à `\App\Facades\SEConfig::getCurrentEstablishmentCode()`. Renvoie `false` si `school_code` vide ou égal à `0`, ou si current code vide/`0`. **Réutilisable directement dans `getEffectiveQuota` mais signature de la méthode reçoit `$username: string`** — décision **D5** ci-dessous (résolution interne via lookup `User::where('login', $username)` vs ajout paramètre `bool $isExternal`). |
| `App\Models\SystemSetting::get/set/forget` | livré 5.1c | [SystemSetting.php](../../app/Models/SystemSetting.php) — helpers statiques + cast `value: array`. Pour 5.1d : `SystemSetting::get('quota.trash')` retourne `['ttl_days' => 30, 'purge_auto' => false]` ou `null`. Lecture symétrique de l'écriture par `quotas-fs-tab.blade.php:saveTrash` (l. **256-273** de [quotas-fs-tab.blade.php](../../resources/views/pages/admin/settings/_partials/quotas-fs-tab.blade.php)). |
| Section "Corbeille" de l'onglet `/admin/settings → Quotas & FS` | livré 5.1c | Cards "Corbeille (/home/trash)" l. **456-515** de quotas-fs-tab.blade.php. Form `wire:submit.prevent="saveTrash"` avec input `trash.ttl_days` (1-365, default 30) + toggle `trash.purge_auto` (default false) + alert info "consommée par `trash:purge` 5.1d". **À enrichir en 5.1d** d'un bouton "Purger maintenant" + retrait de l'alert info (puisque la commande arrive). |
| `App\Console\Kernel::schedule()` | en place | l. **52-58** de [Kernel.php](../../app/Console/Kernel.php) — `quota:snapshot` à `dailyAt('03:00')`. **`trash:purge` doit s'insérer à `dailyAt('02:00')`** (avant snapshot, conformément à epics.md). |
| `App\Listeners\NotifyQuotaOverageOnLogin` | livré 5.1c | [NotifyQuotaOverageOnLogin.php](../../app/Listeners/NotifyQuotaOverageOnLogin.php) — pattern try/catch global silencieux, lecture `users.quota_snapshot` cast `'array'`. **À ne PAS toucher en 5.1d**. |
| `App\Models\QuotaAuditLog` | en place | Modèle déjà utilisé par `XfsQuotaService::setQuotaRule/deleteQuotaRule` (rules audit). **À réutiliser** pour tracer les suppressions trash:purge si décision D6=A. Cf. [QuotaAuditLog.php](../../app/Models/QuotaAuditLog.php). |
| `App\Components\Traits\WithToasts` | livré | Pour le bouton "Purger maintenant" Livewire side. Déjà importé par `quotas-fs-tab.blade.php` (`use WithToasts;`). |
| Commande `quota:snapshot` | livré 5.1b | [QuotaSnapshotCommand.php](../../app/Console/Commands/QuotaSnapshotCommand.php) — pattern à imiter pour `trash:purge` et `quota:seed-from-legacy` : `protected $signature` + `handle(): int` + `Log::info` + `Command::SUCCESS/FAILURE`. |

### Volet 1 — `default_itinerant` : choisir comment résoudre `isExternal` dans `getEffectiveQuota`

`getEffectiveQuota($username: string, $partition, $userGroups, $userProfile)` reçoit aujourd'hui un **string login**, pas un `User` Eloquent. Pour activer la priorité `default_itinerant > default_<profil>`, le service doit savoir si l'utilisateur est externe. Trois options :

- **Option A — Lookup interne (recommandation SM)** : dans `getEffectiveQuota`, après avoir épuisé USER et GROUP rules, faire `User::where('login', $username)->select(['login','school_code'])->first()` et appeler `$user?->isExternal()` AVANT le `match($userProfile)`. Si `true` → tenter `TYPE_DEFAULT_ITINERANT` ; sinon ou si la règle itinérante n'existe pas → fallback sur `match($userProfile)` actuel. **Avantage** : signature publique inchangée, rétrocompatibilité totale (snapshot command + WallpaperResolver/Composer + QuotaController + dispatchRecalculateGroupJob continuent de marcher sans modif). **Inconvénient** : 1 SELECT BDD supplémentaire par appel — négligeable (~0.5ms PostgreSQL).
- **Option B — Nouveau paramètre `bool $isExternal = false`** : ajouter un 5e paramètre. Nécessite de modifier les 4 call sites internes (`dispatchRecalculateGroupJob` l. 527 + autres) qui devront résoudre `isExternal` via une lookup similaire — déplace simplement le coût ailleurs sans bénéfice.
- **Option C — Nouvelle méthode `getEffectiveQuotaForUser(User $user, string $partition): array`** : conserve l'ancienne pour compat. Demande de mettre à jour `dispatchRecalculateGroupJob` interne pour utiliser la nouvelle signature là où on a l'objet User. **Inconvénient** : 2 méthodes parallèles → dette technique.

**Recommandation SM : Option A**. Le coût SELECT est négligeable comparé aux shellouts XFS qui dominent les call paths. La signature publique est préservée → 0 modification d'appelants. **Décision D5** à valider au kickoff.

### Volet 2 — Commande `trash:purge` : architecture

#### Liste des dossiers à purger

`/home/trash/` contient des sous-dossiers nommés par login (cf. `HomeDirService::archiveHomeDirectory` l. **102** : `$trashPath = "/home/trash/" . $login;`). La commande doit :

1. Lire `SystemSetting::get('quota.trash', ['ttl_days' => 30, 'purge_auto' => false])`. **Si la clé n'existe pas → utiliser le scaffold default `30/false`**. **Si `ttl_days` <= 0 → log warning et exit** (garde-fou — décision D2).
2. Scanner `/home/trash/*` (1 niveau, dossiers uniquement, pas de fichiers orphelins).
3. Pour chaque sous-dossier `<login>` : récupérer `filemtime()` ET valider `preg_match('/^[a-zA-Z0-9._-]+$/', basename($dir))` (anti-injection, cohérent avec HomeDirService).
4. Calculer `$ageDays = (now - $mtime) / 86400`. Si `$ageDays > $ttlDays` → candidate à suppression.
5. Pour chaque candidate : appeler `HomeDirService::deleteHomeDirectoryPermanently($login)`. La méthode revalide le login + `escapeshellarg` + `sudo rm -rf /home/trash/{login}`. **Réutilisation maximale** du service éprouvé (41 tests anti-injection livrés en 5.1a review).
6. Tracer chaque suppression via `Log::info` + (D6=A si retenu) row dans `quota_audit_logs` avec `target_type='trash'`, `target=$login`, `action='purge'`.
7. Stats finales : `php artisan trash:purge` retourne `Command::SUCCESS` même si certaines suppressions ont échoué (compteur d'erreurs + `Log::error` par échec). **Exit `Command::FAILURE` uniquement si TOUTES les suppressions échouent** (cohérent avec fail-soft de `quota:snapshot` 5.1b).

#### Planification conditionnelle

Le pattern Laravel pour planifier conditionnellement est **`->when(closure)`**. Dans `Console\Kernel::schedule()` :

```php
$schedule->command('trash:purge')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->when(function () {
        $trash = \App\Models\SystemSetting::get('quota.trash', ['purge_auto' => false]);
        return (bool) ($trash['purge_auto'] ?? false);
    });
```

Avantages : la planification est TOUJOURS définie dans le code (testable via `KernelScheduleTest`), mais l'exécution est **gated par le toggle BDD**. Si l'admin change le toggle dans `/admin/settings`, la prise d'effet est immédiate (pas de redéploiement nécessaire). **Gotcha** : la closure `->when()` est évaluée à chaque tick scheduler (1 minute) → 1 SELECT supplémentaire par minute, négligeable.

#### Bouton "Purger maintenant"

Dans la section "Corbeille" de `quotas-fs-tab.blade.php`, ajouter un bouton entre l'input TTL et le toggle (ou en dessous du "Enregistrer la configuration corbeille"). Action Livewire `purgeNow()` qui :

1. `Gate::allows('server.admin')` first → `abort(403)` (cohérent saveDefaults/saveGrace/saveTrash).
2. Appelle `Artisan::call('trash:purge')` synchrone.
3. Récupère `Artisan::output()` pour les stats.
4. Émet `toastSuccess("Corbeille purgée — N dossiers supprimés.")` ou `toastError("Échec de la purge — voir les logs.")`.
5. **Décision D3** : exécution **synchrone** (recommandation SM — purge légère, ≤50 dossiers en moyenne, exec inline acceptable, feedback immédiat) vs dispatch async via Job. Pattern 4-2 pour les actions massives utilise async ; ici le volume est faible (∼10x les desactivations user) → sync est pragmatique. Si Henri préfère async, créer `Jobs/PurgeTrashJob.php` qui appelle la commande.

### Volet 3 — Commande `quota:seed-from-legacy` : où sont les données ?

#### Investigation source legacy (effectuée par SM 2026-04-27)

Examen de [`sambaedu/includes/quotas.inc.php`](../../sambaedu/includes/quotas.inc.php) (402 lignes) :

- **Source canonique** : table MySQL `quotas` du legacy (l. 13 commentaire + l. **172** SELECT + l. **300/310/315** DELETE/UPDATE/INSERT). Schéma confirmé via inspection des 3 mutations : `(nom string, quotasoft int, quotahard int, partition string)`. **Pas de colonne `type`** — la distinction user/group est implicite : `nom` est un login utilisateur OU un nom de groupe (le legacy résout l'ambiguïté à la lecture via un lookup AD).
- **Comment savoir si `nom` = user ou group ?** : le legacy lit l'AD pour discriminer. Pour la commande Laravel, deux pistes :
  - (a) Tenter `UserGroup::where('name', $nom)->exists()` → group ; sinon → user.
  - (b) Tenter `User::where('login', $nom)->exists()` → user ; sinon → group (heuristique inverse).
  - (c) Importer le `nom` dans QuotaRule sans discrimination user/group, taguer `type='user'` par défaut, laisser le RUNBOOK manuel post-seed corriger les rares groupes (s'il y en a).
  - Recommandation SM : (a) — pile-poil sur les modèles déjà synchronisés depuis l'AD (Story 2.1 + sync-from-ad qui tourne toutes les 5 min). **Décision D1** à trancher.

#### Accès à la table MySQL legacy depuis Laravel

`config/database.php` ne contient PAS de connexion legacy distincte (vérifié 2026-04-27). 3 options :

- **Option A (recommandation SM) — Connexion `legacy_mysql` ajoutée à `config/database.php`** lue via `env('LEGACY_DB_*')`. La VM SambaEdu actuelle a MySQL/MariaDB disponible (cf. `App\Config\SambaEduConfig::checkMariaDb()` l. **346**). Variables `.env` à ajouter : `LEGACY_DB_HOST`, `LEGACY_DB_DATABASE`, `LEGACY_DB_USERNAME`, `LEGACY_DB_PASSWORD`, `LEGACY_DB_SOCKET`. Lecture via `DB::connection('legacy_mysql')->table('quotas')->get()`. **Exposé optionnel** : la commande détecte la connexion absente → retourne FAILURE explicite avec message "Connexion legacy non configurée (cf. .env LEGACY_DB_*)".
- **Option B — Fichier YAML/JSON sur la VM** : l'opérateur exporte `mysqldump` une fois en JSON, on lit via `Storage::disk('local')->get('legacy/quotas.json')`. Plus simple à tester (sqlite test = JSON file mocked) mais demande à l'opérateur 1 étape manuelle avant de lancer la seed.
- **Option C — Réutiliser le bridge legacy** : `App\Services\Legacy\*` ou les inclusions `legacy/config.inc.php` qui exposent déjà l'authlink mysqli. Demande de creuser quelle classe expose `$authlink` côté Laravel — **option à éviter** (couplage aux includes legacy + difficile à tester).

**Recommandation SM : (A)**. Une connexion Laravel formelle est testable (sqlite mock via `DB::connection`-aware), élégante, et alignée avec le pattern qu'on déploiera pour d'autres seeds futurs (printers, dhcp). **Décision D1** à valider.

#### Defaults profils dans `SystemSetting`

Le legacy ne stocke **pas** explicitement les defaults profils (élève/prof/admin/itinérant) — la détection se fait au runtime via les groupes AD (cf. `XfsQuotaService::getUserProfile` l. **589**). La commande `quota:seed-from-legacy` doit donc :

1. Pour les `QuotaRule::TYPE_DEFAULT_*` : initialiser des **valeurs raisonnables** (élève 500/600 Mo `/home`, prof 1000/1200 Mo, admin 2000/2400 Mo, itinérant 200/240 Mo — à confirmer avec Henri au kickoff D4) si aucune règle n'existe déjà. **Pas de seed depuis le legacy puisque le legacy ne les a pas explicitement.**
2. **OU** : ne PAS écraser les defaults. La section "Defaults profils" de `/admin/settings → Quotas & FS` les persiste déjà via UI 5.1c. Si l'admin a déjà cliqué "Enregistrer", la commande **respecte** ses valeurs (`--force` les écraserait). **Recommandation SM** : seed les defaults UNIQUEMENT s'ils n'existent pas (`isDefault()` scope vide) — `quota.defaults` dans `SystemSetting` reste comme miroir UI mais source de vérité = `QuotaRule::TYPE_DEFAULT_*`.

#### Idempotence + flags

- **`--dry-run`** : affiche ce qui SERAIT importé (count par type) sans toucher la BDD. Sortie texte tabulaire.
- **`--force`** : si `QuotaRule::where('type', 'user')->where('target', $nom)->exists()` → écrase au lieu de skip. **Sans `--force`** : skip silencieux + log info "QuotaRule X déjà existante, skipped".
- **Rapport texte final** :
  ```
  Seed quotas legacy → SambaEdu Reload
  ─────────────────────────────────────
  Source : legacy_mysql.quotas (N rows)
  Importées : N user / M group
  Skipped (déjà présentes) : K
  Erreurs (rows malformées) : E
  Defaults profils initialisés : élève/prof/admin/itinérant (4)
  ─────────────────────────────────────
  Durée : Xs
  ```
- **Audit** : chaque INSERT QuotaRule est tracé via `QuotaAuditLog` avec `performed_by='quota:seed-from-legacy'` (cohérent pattern setQuotaRule).

### Couplages, points d'attention

1. **Sécurité `trash:purge`** — la commande N'OPÈRE QUE sur `/home/trash/*` (jamais `/home/*`). Le service `HomeDirService::deleteHomeDirectoryPermanently` enforce déjà ça (l. **167** : `$trashPath = "/home/trash/" . $login`). **Triple garde** : (1) commande scanne `/home/trash/`, (2) regex login validée, (3) service revalide + colle le préfixe. Aucun risque de fuite vers `/home/<active-user>/`.
2. **Compat sudoers VM** — `HomeDirService::deleteHomeDirectoryPermanently` utilise `sudo rm -rf`. La VM doit avoir `www-data ALL=(root) NOPASSWD: /usr/bin/rm` ou plus précis `sudoers /home/trash/*` — **décision D7** : vérifier sudoers VM ou ajouter dans `docs/qa/domains/filesystem.md` les exigences sudoers.
3. **Idempotence trash:purge** — si la commande est ré-exécutée la même journée, les dossiers déjà supprimés retournent `true` (rien à supprimer, l. **170-172** HomeDirService). Pas de double-comptage. ✅
4. **Race condition snapshot vs purge** — `trash:purge` à 02h00, `quota:snapshot` à 03h00. Aucune conflit (purge ≠ rules edits, snapshot lit XFS). ✅
5. **`User::isExternal()` interne lookup performance** — pour `getEffectiveQuota` avec ajout du SELECT user, dispatcher 100 jobs (groupe de 100 membres → `dispatchRecalculateGroupJob` l. **518**) = 100 SELECT supplémentaires. Acceptable (`select login, school_code from users where login = ?` = primary index). **À noter** : si on veut optimiser, ajouter `eager-load` `User::whereIn('login', $members)->keyBy('login')` dans `dispatchRecalculateGroupJob`. **Hors scope 5.1d** — recommandation SM : optimiser uniquement si benchmark prouve un goulot.
6. **Migration enum `quota_rules.type`** — **PAS NÉCESSAIRE** : le champ est `string(20)` (cf. migration `2026_02_20_100000_create_quota_tables.php:24`). La constante `TYPE_DEFAULT_ITINERANT = 'default_itinerant'` (16 chars) tient dans la limite. ✅ Économie : -1 migration vs hypothèse epics.md.
7. **Listener Login event 5.1c — non touché** — aucune modification de `NotifyQuotaOverageOnLogin`. Sécurité isolée. ✅
8. **Section Corbeille UI — alert info à retirer** — l'alert "consommée par `trash:purge` 5.1d" devient obsolète une fois 5.1d livrée. **À retirer** dans la même story (Phase UI). Sinon, la dette s'accumule (cohérent avec convention "interdiction de placeholders" du SM).
9. **Tests Feature `KernelScheduleTest`** — il faut un test qui valide la planification `trash:purge` à 02:00 + le `->when()` toggle. Pattern existant dans `tests/Feature/Console/KernelScheduleTest.php` (5.1a + 5.1b ont déjà des tests anti-régression).
10. **Tests Feature `LegacySeedTest`** — sqlite ne supporte pas une "vraie" connexion `legacy_mysql`. Stratégie : mocker `DB::connection('legacy_mysql')` via `DB::shouldReceive('connection')->with('legacy_mysql')->andReturn($mockBuilder)` ou créer une 2e BDD sqlite testing avec un schéma `quotas` minimal. **Pattern à acter en Phase 6**.
11. **Documentation QA** — append-only sur `docs/qa/domains/filesystem.md` (section "Story 5.1d — ..." avec scénarios numérotés stables 5.1d-1, 5.1d-2…). **PAS** de fichier `5-1d-e2e-manual.md` séparé (interdit par convention 5.1c — cf. [docs/qa/README.md](../../docs/qa/README.md):14-17).
12. **Sudoers seed legacy** — `quota:seed-from-legacy` est en lecture seule côté MySQL legacy (juste `SELECT`). Aucun sudo nécessaire côté MySQL. ✅
13. **Ordre d'exécution 5.1d** — Volet 1 (default_itinerant) NON-BLOQUANT vis-à-vis Volet 2 (trash) et Volet 3 (seed). Les 3 volets peuvent être implémentés en parallèle si dev veut découper. Recommandation : Volet 1 first (le plus court + le plus testable unitairement), puis Volet 2, puis Volet 3 (le plus lourd, dépendant des décisions D1-D4).

---

## Acceptance Criteria

**AC 1 — `default_itinerant` activé pour utilisateurs externes**

**Given** un utilisateur `User::isExternal() == true` (school_code différent de l'établissement courant)
**And** aucune `QuotaRule::TYPE_USER` matchant ce login
**And** aucune `QuotaRule::TYPE_GROUP` dans ses `userGroups`
**And** une `QuotaRule::TYPE_DEFAULT_ITINERANT` existe pour la partition demandée
**When** `XfsQuotaService::getEffectiveQuota($username, $partition, $userGroups, $userProfile)` est appelé
**Then** la réponse retourne `['source' => 'default', 'source_name' => 'Défaut itinérants', 'quota_soft_mb' => X, 'quota_hard_mb' => Y, 'is_unlimited' => bool]`
**And** la règle appliquée est `TYPE_DEFAULT_ITINERANT` (prioritaire sur `TYPE_DEFAULT_ELEVE/PROF/ADMIN`)

**AC 2 — Fallback `default_itinerant` absente → default profil**

**Given** un utilisateur externe (`isExternal == true`) avec un profil `'eleve'`
**And** aucune `QuotaRule::TYPE_USER` ni `TYPE_GROUP` matchant
**And** **aucune** `QuotaRule::TYPE_DEFAULT_ITINERANT` n'existe pour la partition
**And** une `QuotaRule::TYPE_DEFAULT_ELEVE` existe
**When** `getEffectiveQuota` est appelé
**Then** la règle `TYPE_DEFAULT_ELEVE` est appliquée (fallback non-silencieux côté UX, log silencieux côté serveur)

**AC 3 — Utilisateur interne : `default_itinerant` ignorée**

**Given** un utilisateur `User::isExternal() == false` (school_code identique au current code OU vide/`0`)
**And** aucune règle USER ni GROUP
**And** une `QuotaRule::TYPE_DEFAULT_ITINERANT` existe pour la partition
**And** une `QuotaRule::TYPE_DEFAULT_ELEVE` existe pour la partition
**When** `getEffectiveQuota($username, $partition, [], 'eleve')` est appelé
**Then** la règle appliquée est `TYPE_DEFAULT_ELEVE` (PAS `TYPE_DEFAULT_ITINERANT`)
**And** `source_name == 'Défaut élèves'` dans la réponse

**AC 4 — Signature publique de `getEffectiveQuota` préservée**

**Given** les 4 call sites internes du service (`dispatchRecalculateGroupJob` l. 527 + autres) et tous les call sites externes (snapshot command, WallpaperResolver, WallpaperComposer, QuotaController, Livewire SFC quota-section, group-quota-section)
**When** 5.1d est livrée
**Then** la signature `getEffectiveQuota(string $username, string $partition, array $userGroups = [], string $userProfile = 'eleve'): array` est inchangée
**And** aucun appelant externe au service n'est modifié (sauf si un nouveau paramètre est strictement nécessaire — décision D5 = Option A recommandée pour PAS toucher la signature)
**And** les 19 tests 5.1b + les tests 5.1c restent verts (non-régression stricte)

**AC 5 — Commande `trash:purge` : suppression sélective par TTL**

**Given** le dossier `/home/trash/alice` créé il y a **45 jours** (mtime ancien)
**And** le dossier `/home/trash/bob` créé il y a **5 jours**
**And** `SystemSetting::get('quota.trash')` retourne `['ttl_days' => 30, 'purge_auto' => false]`
**When** `php artisan trash:purge` est exécuté
**Then** `/home/trash/alice` est supprimé via `HomeDirService::deleteHomeDirectoryPermanently('alice')`
**And** `/home/trash/bob` est conservé
**And** le rapport stdout indique : `Purgé : 1 dossier(s). Conservé : 1 dossier(s). Erreurs : 0.`
**And** la commande retourne `Command::SUCCESS`

**AC 6 — Commande `trash:purge` : fail-soft + erreurs non-silencieuses**

**Given** un dossier `/home/trash/charlie` à supprimer dont la commande `sudo rm -rf` échoue (permissions refusées simulées)
**And** un autre dossier `/home/trash/dave` qui se supprime correctement
**When** `php artisan trash:purge` est exécuté
**Then** `Log::error('QuotaService: trash:purge échec', ['login' => 'charlie', 'output' => '...'])` est émis
**And** la suppression de `dave` continue malgré l'échec de `charlie`
**And** le rapport stdout liste : `Purgé : 1. Échecs : 1.`
**And** la commande retourne `Command::SUCCESS` (sauf si TOUTES les suppressions échouent → `Command::FAILURE` cohérent fail-soft 5.1b D3)

**AC 7 — Commande `trash:purge` : garde-fou TTL invalide**

**Given** `SystemSetting::get('quota.trash')` retourne `['ttl_days' => 0, 'purge_auto' => true]` OU est `null`
**When** `php artisan trash:purge` est exécuté
**Then** la commande log un warning `QuotaService: trash:purge TTL invalide ou non configuré` et retourne `Command::SUCCESS` (no-op safe)
**And** **aucun** dossier n'est supprimé

**AC 8 — Planification conditionnelle dans `Console\Kernel`**

**Given** le toggle `quota.trash.purge_auto = true` dans `SystemSetting`
**When** le scheduler tick à 02h00
**Then** la commande `trash:purge` est exécutée
**Given** le toggle `quota.trash.purge_auto = false`
**When** le scheduler tick à 02h00
**Then** la commande `trash:purge` n'est **PAS** exécutée
**And** un test Feature `KernelScheduleTest::it_schedules_trash_purge_only_when_auto_enabled` valide les 2 cas via `Schedule::dueEvents()` ou inspection du `->when()` callback

**AC 9 — Bouton "Purger maintenant" dans `/admin/settings → Quotas & FS`**

**Given** je suis admin (`server.admin`) sur `/admin/settings?tab=quotas-fs`
**When** je consulte la section "Corbeille (/home/trash)"
**Then** un bouton secondaire "Purger maintenant" est visible (icône `fa-broom` ou équivalent)
**And** au clic, la méthode Livewire `purgeNow()` est appelée
**And** la méthode `Gate::allows('server.admin')` puis `abort(403)` sinon (cohérent saveDefaults/saveGrace/saveTrash)
**And** la méthode appelle `Artisan::call('trash:purge')` synchrone (D3=A recommandation SM)
**And** un toast `WithToasts::toastSuccess("Corbeille purgée — N dossiers supprimés.")` est émis si succès (output parsé pour extraire N)
**And** un toast `WithToasts::toastError("Échec de la purge — voir les logs.")` est émis si exception
**And** la section affiche un spinner `wire:loading wire:target="purgeNow"` pendant l'exécution
**And** un test Feature `AdminSettingsQuotasFsTabTest::it_blocks_purge_now_without_server_admin` couvre le bypass tentative (forged Livewire payload)

**AC 10 — Alert info "5.1d à venir" supprimée**

**Given** la section "Corbeille (/home/trash)" de `quotas-fs-tab.blade.php` contient l. **472-483** un `<div class="alert alert-info">` "Cette configuration sera consommée par la commande `trash:purge` livrée dans la prochaine version (5.1d)."
**When** 5.1d est livrée
**Then** l'alert info est **supprimée** (la commande arrive — placeholder obsolète)
**And** un grep `grep -rn "trash:purge.*5.1d\|prochaine version" resources/views/pages/admin` retourne 0 hit applicatif

**AC 11 — Commande `quota:seed-from-legacy` : import idempotent depuis MySQL legacy**

**Given** une table MySQL legacy `quotas` (connexion `legacy_mysql` configurée dans `config/database.php` + `.env.LEGACY_DB_*`) contenant `(nom='alice', quotasoft=500000, quotahard=600000, partition='/home')`
**And** aucune `QuotaRule::TYPE_USER + target='alice' + partition='/home'` en BDD `pgsql`
**When** `php artisan quota:seed-from-legacy` est exécuté (sans `--dry-run` ni `--force`)
**Then** une row `QuotaRule(type='user', target='alice', partition='/home', quota_soft_mb=488, quota_hard_mb=586)` est créée (conversion KB → MB via `round($x / 1024)`)
**And** `QuotaAuditLog` trace l'INSERT avec `performed_by='quota:seed-from-legacy'`
**And** une 2e exécution de la même commande sans `--force` → row déjà présente, skipped (log info), 0 INSERT additionnel
**And** une 2e exécution avec `--force` → row mise à jour si valeurs diffèrent

**AC 12 — Commande `quota:seed-from-legacy` : discrimination user vs group**

**Given** une row legacy `(nom='Sixieme A', quotasoft=2000000, quotahard=2400000, partition='/home')` où `Sixieme A` est un nom de groupe AD synchronisé dans `user_groups`
**And** une row legacy `(nom='alice', ...)` où `alice` est un login utilisateur synchronisé dans `users`
**When** `php artisan quota:seed-from-legacy` est exécuté
**Then** la row groupe est créée avec `type='group'` (résolution via `UserGroup::where('name', 'Sixieme A')->exists()` → true)
**And** la row alice est créée avec `type='user'`

**AC 13 — Commande `quota:seed-from-legacy` : `--dry-run`**

**Given** la table MySQL legacy contient 50 rows
**When** `php artisan quota:seed-from-legacy --dry-run` est exécuté
**Then** la commande affiche un rapport : `Aperçu : 30 user / 15 group / 5 erreurs (rows malformées). Aucune modification BDD.`
**And** **aucune** row n'est créée dans `quota_rules`
**And** **aucune** row n'est créée dans `quota_audit_logs`
**And** la commande retourne `Command::SUCCESS`

**AC 14 — Commande `quota:seed-from-legacy` : connexion absente → erreur explicite**

**Given** la connexion `legacy_mysql` n'est PAS configurée (`.env` manquant `LEGACY_DB_*`)
**When** `php artisan quota:seed-from-legacy` est exécuté
**Then** la commande log `Log::error('QuotaService: connexion legacy_mysql non configurée')` et affiche en stdout un message clair "Connexion legacy non configurée — ajouter LEGACY_DB_HOST/DATABASE/USERNAME/PASSWORD dans .env"
**And** la commande retourne `Command::FAILURE`
**And** **aucune** row n'est touchée

**AC 15 — Commande `quota:seed-from-legacy` : init defaults profils si absents**

**Given** aucune `QuotaRule::TYPE_DEFAULT_*` n'existe en BDD (déploiement frais)
**When** `php artisan quota:seed-from-legacy` est exécuté
**Then** 8 rows sont créées : 4 profils (élève / prof / admin / itinérant) × 2 partitions (`/home` / `/var/sambaedu`) avec valeurs raisonnables (élève 500/600, prof 1000/1200, admin 2000/2400, itinérant 200/240 — confirmés D4)
**And** si certaines `TYPE_DEFAULT_*` existent déjà, elles sont **PRÉSERVÉES** (pas écrasées sans `--force`)

**AC 16 — Tests Feature + Unit complets**

**Given** les composants impactés par 5.1d
**When** les tests tournent
**Then** **au minimum 15 nouveaux tests passent** (répartition indicative) :
1. `XfsQuotaServiceItinerantTest::it_applies_default_itinerant_when_user_is_external` (AC 1)
2. `XfsQuotaServiceItinerantTest::it_falls_back_to_default_profile_when_itinerant_rule_missing` (AC 2)
3. `XfsQuotaServiceItinerantTest::it_ignores_default_itinerant_for_internal_user` (AC 3)
4. `XfsQuotaServiceItinerantTest::it_priorities_user_rule_over_default_itinerant_for_external_user` (régression sécurité)
5. `XfsQuotaServiceItinerantTest::it_priorities_group_rule_over_default_itinerant_for_external_user` (régression sécurité)
6. `TrashPurgeCommandTest::it_purges_directories_older_than_ttl` (AC 5 — vfsStream ou tempdir)
7. `TrashPurgeCommandTest::it_keeps_directories_younger_than_ttl` (AC 5 négatif)
8. `TrashPurgeCommandTest::it_continues_on_individual_failure` (AC 6)
9. `TrashPurgeCommandTest::it_returns_failure_when_all_deletes_fail` (AC 6 edge case)
10. `TrashPurgeCommandTest::it_skips_when_ttl_is_zero_or_missing` (AC 7)
11. `KernelScheduleTest::it_schedules_trash_purge_at_02h00_with_when_callback` (AC 8 positif)
12. `KernelScheduleTest::it_does_not_schedule_trash_purge_when_auto_disabled` (AC 8 négatif via évaluation closure)
13. `AdminSettingsQuotasFsTabTest::it_purges_now_via_button_when_admin` (AC 9 positif)
14. `AdminSettingsQuotasFsTabTest::it_blocks_purge_now_without_server_admin` (AC 9 sécurité)
15. `QuotaSeedFromLegacyCommandTest::it_imports_user_rules_from_legacy_table` (AC 11)
16. `QuotaSeedFromLegacyCommandTest::it_discriminates_user_vs_group_via_eloquent_lookup` (AC 12)
17. `QuotaSeedFromLegacyCommandTest::it_supports_dry_run_without_modifying_db` (AC 13)
18. `QuotaSeedFromLegacyCommandTest::it_returns_failure_when_legacy_connection_missing` (AC 14)
19. `QuotaSeedFromLegacyCommandTest::it_initializes_default_profiles_when_absent` (AC 15)
20. `QuotaSeedFromLegacyCommandTest::it_skips_existing_rules_without_force_flag` (AC 11 idempotence)
21. `QuotaSeedFromLegacyCommandTest::it_overwrites_existing_rules_with_force_flag` (AC 11 force)

**AC 17 — Non-régression 5.1a + 5.1b + 5.1c (≥ 1172 tests verts)**

**Given** la suite complète à 1172 tests verts (baseline 2026-04-27 post-5.1c) — incluant `HomeDirServiceTest` (41 tests), `QuotaSnapshotCommandTest` (8), `UserShowQuotaSectionTest` (5), `UsersIndexPageQuotaColumnTest` (4), `UsersIndexPageNoShelloutTest` (1), `KernelScheduleTest` (≥3), `GroupShowQuotaSectionTest` (8), `AdminSettingsPageTest` (3), `AdminSettingsQuotasFsTabTest` (6), `LoginOverQuotaToastTest` (6)
**When** 5.1d est livrée
**Then** les 1172 tests existants restent **TOUS** verts sans modification
**And** la suite globale atteint **≥ 1187 tests verts** (1172 + 15 nouveaux 5.1d minimum)
**And** **aucune modification** n'est apportée à : `app/Models/User.php` (cast/fillable `quota_snapshot`), `app/Console/Commands/QuotaSnapshotCommand.php`, `app/Listeners/NotifyQuotaOverageOnLogin.php`, la signature publique de `XfsQuotaService::getEffectiveQuota` (sauf décision D5 explicitement contraire — recommandation Option A préserve la signature)

**AC 18 — Gestion d'erreur préservée (pas de comportement silencieux)**

**Given** un échec d'opération (BDD down, sudo refusé, connexion legacy MySQL down, ttl_days hors borne)
**When** l'erreur survient dans `trash:purge` ou `quota:seed-from-legacy`
**Then** `Log::error('QuotaService: ...', [...contexte])` est émis (préfixe historique conservé selon décision SM 5.1a)
**And** un message stdout explicite est affiché à l'opérateur (pas de stack trace brute)
**And** le code retour reflète l'échec (`Command::FAILURE` si bloquant, `Command::SUCCESS` si fail-soft cohérent 5.1b)
**And** **aucune modification partielle non-tracée** n'est écrite (transaction DB pour `quota:seed-from-legacy` qui inserts en batch)

---

## Décisions produit à arbitrer au kickoff

> Les décisions doivent être confirmées par Henri avant le démarrage de l'implémentation. Reporter les choix dans Dev Notes section "Kickoff Décisions" de cette story.

1. **D1 — Source legacy pour `quota:seed-from-legacy`** (AC 11-14) : **(A) Connexion `legacy_mysql` ajoutée à `config/database.php` + `.env.LEGACY_DB_*`** (recommandation SM — testable, alignée avec patterns futurs) **vs (B) Fichier JSON exporté manuellement par mysqldump sur la VM** (plus simple à tester mais étape opérateur supplémentaire) **vs (C) Réutiliser le bridge legacy `App\Services\Legacy\*`** (couplage fort, à éviter). Recommandation : **A**.

2. **D2 — Comportement `trash:purge` quand `ttl_days <= 0` ou `quota.trash` absent** (AC 7) : **(A) No-op safe + log warning + exit SUCCESS** (recommandation SM — interpretation stricte = "pas de purge configurée") **vs (B) Utiliser un default 30j codé en dur dans la commande** (plus permissif mais cache le bug de config) **vs (C) `Command::FAILURE` explicite** (force l'admin à configurer). Recommandation : **A**.

3. **D3 — `purgeNow()` Livewire : sync vs async** (AC 9) : **(A) Synchrone via `Artisan::call('trash:purge')` inline** (recommandation SM — volume faible, feedback immédiat, simplicité) **vs (B) Dispatch async via `PurgeTrashJob`** (cohérence pattern Story 4-2 mais overkill pour ce volume). Recommandation : **A**.

4. **D4 — Defaults profils initialisés par `quota:seed-from-legacy`** (AC 15) : Valider les valeurs raisonnables proposées par SM : élève 500/600 Mo `/home`, prof 1000/1200, admin 2000/2400, itinérant 200/240. **Mêmes valeurs `/var/sambaedu` ou divisées par 2** (recommandation SM : mêmes — les partages sont gros). Trancher au kickoff. **Alternative** : ne PAS initialiser les defaults profils dans la commande seed (laisser l'admin les régler via UI 5.1c). Recommandation : initialiser pour avoir un fallback fonctionnel dès le déploiement.

5. **D5 — Résolution `User::isExternal()` dans `getEffectiveQuota`** (AC 1, 4) : **(A) Lookup interne `User::where('login', $username)`** (recommandation SM — signature publique préservée) **vs (B) Nouveau paramètre `bool $isExternal = false`** (déplace le coût aux appelants) **vs (C) Nouvelle méthode `getEffectiveQuotaForUser(User $user, ...)` parallèle** (dette technique). Recommandation : **A**.

6. **D6 — Audit `quota_audit_logs` pour les suppressions `trash:purge`** : **(A) Tracer chaque suppression** (recommandation SM — RGPD friendly, compteur opérateur) **vs (B) Log::info uniquement** (moins de pollution BDD). Recommandation : **A** — réutilise `QuotaAuditLog` avec `target_type='trash'`, `action='purge'`. Coût négligeable (≤50 rows/jour).

7. **D7 — Sudoers VM** : **(A) Vérifier que `www-data ALL=(root) NOPASSWD: /usr/bin/rm` est en place** (HomeDirService::deleteHomeDirectoryPermanently l'utilise déjà depuis 5.1a) — **simple validation kickoff** **vs (B) Resserrer sudoers spécifiquement à `/home/trash/*`** (sécurité accrue mais demande `update.sh` script). Recommandation : **A** — déjà éprouvé par les méthodes existantes (archive/restore/deletePermanently déployées sur VM en prod). À CONFIRMER au kickoff.

8. **D8 — Définition `User::isExternal()` couvre-t-elle TOUS les "itinérants" ?** : Vérifier au kickoff que `school_code != current_code` correspond bien à la sémantique métier "utilisateur itinérant rattaché à un autre établissement". Henri à valider la sémantique. Si insuffisant (ex: les "itinérants" sont marqués par un attribut AD distinct), proposer une alternative : (a) ajouter un attribut `User::is_itinerant` boolean ou (b) utiliser un groupe AD dédié `cn=itinerants`. Recommandation : **A** (réutiliser `isExternal()`) sauf invalidation Henri.

9. **D9 — Priorité `default_itinerant > default_profile`** (AC 1) : Confirmer que pour un user EXTERNE qui a aussi un profil "élève" (cas standard, un externe est presque toujours rattaché à un rôle élève/prof), la règle `default_itinerant` PRIME sur `default_eleve`. Recommandation : **A — itinérant prime** (epics.md ligne explicite "prioritaire sur default_eleve/prof/admin"). Validation Henri obligatoire.

---

## Tasks / Subtasks

### Phase 0 — Kickoff & décisions produit (bloquant)

- [x] **Tâche 0.1** — Capturer la baseline de tests avant démarrage : sur la VM, `cd /var/www/sambaedu-reload && php artisan test 2>&1 | tail -10`. Cible attendue : **1172 passed / 1 incomplete / 47 skipped** (état post-5.1c validé Henri 2026-04-27). Noter dans Dev Notes section Baseline.
- [x] **Tâche 0.2** — Décisions D1-D9 validées par Henri → reporter les choix dans Dev Notes section "Kickoff Décisions". Bloquer le démarrage si D1, D5, D8, D9 ne sont pas tranchées.
- [x] **Tâche 0.3** — Vérifier sudoers VM (D7) : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 'sudo -u www-data sudo -n rm --version 2>&1 | head -1'`. Si échec, escalader à Henri pour ajustement sudoers AVANT toute exec sur trash.
- [x] **Tâche 0.4** — Vérifier connexion legacy MySQL (D1=A) : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 'mysql -e "SELECT COUNT(*) FROM mysql.user" 2>&1 | head -3'`. Récupérer host/port/user/db legacy auprès de Henri si non documenté.
- [x] **Tâche 0.5** — Grep exhaustif baseline : `grep -rn 'trash:purge\|quota:seed-from-legacy\|TYPE_DEFAULT_ITINERANT' app tests routes resources docs` → documenter état initial dans Dev Notes (s'attendre à 0 hits pour les commandes, plusieurs hits pour la constante 5.1c).

### Phase 1 — Volet 1 : default_itinerant dans `getEffectiveQuota` (recommandation Option A)

- [x] **Tâche 1.1** — Modifier `app/Services/Filesystem/XfsQuotaService.php::getEffectiveQuota` (l. 71-155) : (D5=A) après l'épuisement des règles USER (l. 78-92) et GROUP (l. 95-123), AVANT le `match($userProfile)` (l. 126), insérer un bloc :
  ```php
  // Priorité itinérant : si User::isExternal() == true, tenter TYPE_DEFAULT_ITINERANT
  $user = \App\Models\User::query()
      ->select(['login', 'school_code'])
      ->where('login', $username)
      ->first();
  if ($user?->isExternal()) {
      $itinerantRule = QuotaRule::active()
          ->forPartition($partition)
          ->where('type', QuotaRule::TYPE_DEFAULT_ITINERANT)
          ->first();
      if ($itinerantRule) {
          return [
              'source' => 'default',
              'source_name' => $itinerantRule->getTypeLabel(),
              'quota_soft_mb' => $itinerantRule->quota_soft_mb,
              'quota_hard_mb' => $itinerantRule->quota_hard_mb,
              'is_unlimited' => $itinerantRule->isUnlimited(),
          ];
      }
      // Fallback silencieux : pas d'itinérant configurée → poursuivre vers default profil
  }
  ```
- [x] **Tâche 1.2** — Garde-fou : si `$user` est `null` (login inexistant ou supprimé), ne rien faire (pas de fail). La méthode reste tolérante. Pas de log (pollution potentielle pour groupes massifs).
- [x] **Tâche 1.3** — Documenter dans le docblock de la méthode l'ordre mis à jour : "1. USER, 2. GROUP, 2.5 DEFAULT_ITINERANT (si isExternal), 3. DEFAULT_<PROFILE>".
- [x] **Tâche 1.4** — Créer `tests/Unit/Services/Filesystem/XfsQuotaServiceItinerantTest.php` avec 5 tests minimum (AC 1-3 + 2 régressions sécurité priorité USER/GROUP > default_itinerant). Pattern : `RefreshDatabase` + factory `QuotaRule` + factory `User` avec `school_code` paramétré + mock `\App\Facades\SEConfig::getCurrentEstablishmentCode()`. ⚠️ Tests Unit pour la rapidité — pas besoin de Feature.

### Phase 2 — Volet 2 : commande `trash:purge`

- [x] **Tâche 2.1** — Créer `app/Console/Commands/TrashPurgeCommand.php` :
  - `protected $signature = 'trash:purge {--dry-run : Liste les dossiers candidats sans supprimer}';`
  - `protected $description = 'Purge les dossiers /home/trash/* plus vieux que SystemSetting quota.trash.ttl_days';`
  - DI constructeur : `public function __construct(private HomeDirService $homeDirService) { parent::__construct(); }`
  - `handle()` : lit `SystemSetting::get('quota.trash')`, garde D2=A si `ttl_days <= 0` ou null, scanne `/home/trash/*`, calcule mtime, validate login regex, appelle `$this->homeDirService->deleteHomeDirectoryPermanently($login)`, log info+error, écrit dans `QuotaAuditLog` si D6=A, retourne SUCCESS/FAILURE selon fail-soft 5.1b.
- [x] **Tâche 2.2** — Mode `--dry-run` : liste les candidats sans appeler `deleteHomeDirectoryPermanently`. Affiche tableau (login, mtime, age_days). Retourne SUCCESS sans toucher BDD.
- [x] **Tâche 2.3** — Logs : préfixe `QuotaService:` conservé (cohérent 5.1a/b/c). Format `Log::info('QuotaService: trash:purge supprimé', ['login' => $login, 'age_days' => $age])` et `Log::error('QuotaService: trash:purge échec', ['login' => $login, 'output' => ...])`.
- [x] **Tâche 2.4** — Audit (D6=A) : `QuotaAuditLog::create([...])` par suppression réussie avec `target_type='trash'`, `target=$login`, `action='purge'`, `performed_by='trash:purge'` (ou login admin si bouton "Purger maintenant"). Cf. modèle [`QuotaAuditLog.php`](../../app/Models/QuotaAuditLog.php).
- [x] **Tâche 2.5** — Modifier `app/Console/Kernel.php::schedule()` (l. 13-58) : ajouter le bloc `trash:purge` à 02h00 avec `->when(closure)` qui lit `SystemSetting::get('quota.trash.purge_auto', false)`. Insérer **avant** le bloc `quota:snapshot` (cohérence ordre temporel 02h00 < 03h00).
- [x] **Tâche 2.6** — Créer `tests/Feature/Console/TrashPurgeCommandTest.php` avec 5 tests (AC 5-7 + edge cases). Stratégie : utiliser `tempnam()` + `mkdir()` pour créer un répertoire temporaire `/tmp/trash-test-XXXX` (la commande doit accepter une env var `TRASH_PATH` pour les tests OU mocker le path via constante `class const TRASH_DIR = '/home/trash'` overridable). **Décision dev** : le plus simple est d'ajouter une constante `TrashPurgeCommand::TRASH_DIR` overridable via `static::$trashDir = '/tmp/...';` en test setUp. Pattern utilisé par d'autres commandes système.
- [x] **Tâche 2.7** — Étendre `tests/Feature/Console/KernelScheduleTest.php` (déjà créé en 5.1a/5.1b) avec 2 tests AC 8 : `it_schedules_trash_purge_at_02h00` (assertion sur `$event->getExpression() == '0 2 * * *'`) + `it_does_not_schedule_trash_purge_when_auto_disabled` (mock `SystemSetting::get` → false → vérifier que le filter `->when()` retourne false).

### Phase 3 — Volet 2 (suite) : bouton "Purger maintenant" UI

- [x] **Tâche 3.1** — Modifier `resources/views/pages/admin/settings/_partials/quotas-fs-tab.blade.php` :
  - **Retirer** le `<div class="alert alert-info">` l. **472-483** (AC 10 — placeholder obsolète).
  - **Ajouter** un bouton secondaire "Purger maintenant" entre l'input TTL (l. **485-495**) et le toggle `purge_auto` (l. **497-503**) OU sous le "Enregistrer la configuration corbeille" (choix UX dev). Icône `fa-broom`. Class `btn btn-outline btn-warning`. `wire:click="purgeNow"`. `wire:loading.attr="disabled" wire:target="purgeNow"`. Spinner `wire:loading wire:target="purgeNow"` pendant exec.
- [x] **Tâche 3.2** — Ajouter méthode `purgeNow()` dans la classe Livewire de `quotas-fs-tab.blade.php` (l. 246+) :
  ```php
  public function purgeNow(): void
  {
      if (!\Illuminate\Support\Facades\Gate::allows('server.admin')) {
          abort(403);
      }
      try {
          $exitCode = \Illuminate\Support\Facades\Artisan::call('trash:purge');
          $output = \Illuminate\Support\Facades\Artisan::output();
          // Parse output pour extraire le compteur "Purgé : N"
          $count = preg_match('/Purgé\s*:\s*(\d+)/u', $output, $m) ? (int) $m[1] : 0;
          if ($exitCode === 0) {
              $this->toastSuccess("Corbeille purgée — {$count} dossier(s) supprimé(s).");
          } else {
              $this->toastError("Échec de la purge — voir les logs.");
          }
      } catch (\Throwable $e) {
          \Illuminate\Support\Facades\Log::error('QuotaService: purgeNow échec', ['error' => $e->getMessage()]);
          $this->toastError("Échec de la purge — voir les logs.");
      }
  }
  ```
- [x] **Tâche 3.3** — Étendre `tests/Feature/Livewire/Admin/AdminSettingsQuotasFsTabTest.php` (déjà créé en 5.1c) avec 2 tests AC 9 : `it_purges_now_via_button_when_admin` (Mockery `Artisan::shouldReceive('call')->with('trash:purge')->andReturn(0)` + assertion toast success) + `it_blocks_purge_now_without_server_admin` (forged Livewire payload non-admin → 403).
- [x] **Tâche 3.4** — Vérifier que le test 5.1c `it_persists_trash_ttl_and_purge_toggle` (AdminSettingsQuotasFsTabTest) reste vert après le retrait de l'alert info (le test ne doit pas asserter sur l'alert info).

### Phase 4 — Volet 3 : commande `quota:seed-from-legacy`

- [x] **Tâche 4.1** — (D1=A) Ajouter dans `config/database.php` une connexion `legacy_mysql` :
  ```php
  'legacy_mysql' => [
      'driver' => 'mysql',
      'host' => env('LEGACY_DB_HOST', '127.0.0.1'),
      'port' => env('LEGACY_DB_PORT', '3306'),
      'database' => env('LEGACY_DB_DATABASE', 'sambaedu'),
      'username' => env('LEGACY_DB_USERNAME', ''),
      'password' => env('LEGACY_DB_PASSWORD', ''),
      'unix_socket' => env('LEGACY_DB_SOCKET', ''),
      'charset' => 'utf8mb4',
      'collation' => 'utf8mb4_unicode_ci',
      'prefix' => '',
      'strict' => false,  // legacy → pas de mode strict
      'engine' => null,
  ],
  ```
- [x] **Tâche 4.2** — Mettre à jour `.env.example` avec les 5 variables `LEGACY_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD/SOCKET` documentées en commentaire au-dessus.
- [x] **Tâche 4.3** — Créer `app/Console/Commands/QuotaSeedFromLegacyCommand.php` :
  - `protected $signature = 'quota:seed-from-legacy {--dry-run} {--force}';`
  - `protected $description = 'Importe les règles quotas depuis la base MySQL legacy';`
  - `handle()` :
    1. Vérifier la config `legacy_mysql` : `try { DB::connection('legacy_mysql')->getPdo(); } catch (\Throwable $e) { Log::error+stdout+return FAILURE }` (AC 14).
    2. SELECT `nom, quotasoft, quotahard, partition` FROM `quotas` (table legacy).
    3. Pour chaque row : valider la partition (must be `/home` ou `/var/sambaedu`, sinon errors++ et skip), discriminer user/group via `UserGroup::where('name', $nom)->exists()` (AC 12), conversion KB→MB (`round($qs / 1024)`), tenter `QuotaRule::firstOrCreate` ou `updateOrCreate` selon `--force`. Tracer via `QuotaAuditLog`.
    4. Init defaults profils (AC 15) : pour chaque combo (élève/prof/admin/itinérant) × (`/home`/`/var/sambaedu`), `QuotaRule::firstOrCreate` avec valeurs D4 validées kickoff. Skip si déjà existante (sauf `--force`).
    5. Mode `--dry-run` (AC 13) : tout sauf les INSERTs. Affiche tableau Symfony Console des candidats. Retour SUCCESS.
    6. Rapport stdout final formaté (cf. exemple Contexte Volet 3).
- [x] **Tâche 4.4** — Tests `tests/Feature/Console/QuotaSeedFromLegacyCommandTest.php` (7 tests) :
  - Pattern : créer une 2e DB sqlite testing in-memory pour la connexion `legacy_mysql` via `DB::purge` + `config(['database.connections.legacy_mysql' => ...])` + manual schema. Documenté dans Dev Notes Phase 4.
  - Tests AC 11/12/13/14/15 + 2 idempotence (skip / force).

### Phase 5 — Documentation

- [x] **Tâche 5.1** — Enrichir `docs/qa/domains/filesystem.md` (créé 5.1c) en append-only : nouvelle section `## Story 5.1d — Gaps produits filesystem (default_itinerant, purge trash, seed legacy)` avec **Date livraison**, **Migrations à appliquer** ("aucune"), et 8-10 scénarios numérotés stables (5.1d-1 à 5.1d-10) couvrant : default_itinerant pour user externe, fallback profil, purge trash auto via cron, purge trash manuelle via bouton, dry-run seed legacy, force seed legacy, init defaults profils, sudoers + connexion legacy validation.
- [x] **Tâche 5.2** — Enrichir `docs/domains/filesystem.md` (créé 5.1b, enrichi 5.1c) en append-only : nouvelle section "Story 5.1d" décrivant le comportement `default_itinerant`, le contrat de la commande `trash:purge` (TTL + toggle + audit), et le contrat de `quota:seed-from-legacy` (connexion + schéma source + idempotence + flags).
- [x] **Tâche 5.3** — Mettre à jour le footer "Dernière mise à jour" des 2 docs avec la date 2026-MM-JJ + référence story 5.1d.
- [x] **Tâche 5.4** — **Interdiction stricte** de créer `docs/qa/5-1d-e2e-manual.md` (convention 5.1c).

### Phase 6 — Validation finale

- [x] **Tâche 6.1** — Suite complète sur VM : `cd /var/www/sambaedu-reload && php artisan test`. Cible : ≥ **1187 passed** (1172 baseline + 15 nouveaux 5.1d minimum), **0 régression** sur les 1172 existants.
- [x] **Tâche 6.2** — Tests ciblés 5.1d : `php artisan test --filter='XfsQuotaServiceItinerantTest|TrashPurgeCommandTest|QuotaSeedFromLegacyCommandTest|KernelScheduleTest'` → tous verts.
- [x] **Tâche 6.3** — Non-régression 5.1c ciblée : `php artisan test --filter='GroupShowQuotaSectionTest|AdminSettingsPageTest|AdminSettingsQuotasFsTabTest|LoginOverQuotaToastTest'` → 23 tests verts (incluant le nouveau `it_purges_now_via_button_when_admin` qui s'ajoute aux 6 originaux 5.1c sur AdminSettingsQuotasFsTabTest).
- [x] **Tâche 6.4** — Smoke test VM `trash:purge` :
  ```bash
  ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
  cd /var/www/sambaedu-reload
  # Création faux dossiers test (en datant 1 d'il y a 60j et 1 d'il y a 5j)
  sudo mkdir -p /home/trash/{old-test,recent-test}
  sudo touch -d '60 days ago' /home/trash/old-test
  sudo touch -d '5 days ago' /home/trash/recent-test
  # Configurer TTL 30j auto false (purge manuelle uniquement pour le test)
  php artisan tinker --execute="App\Models\SystemSetting::set('quota.trash', ['ttl_days' => 30, 'purge_auto' => false]);"
  # Dry-run
  php artisan trash:purge --dry-run
  # Run réel
  php artisan trash:purge
  # Validation
  ls /home/trash/  # → recent-test seul doit subsister
  ```
- [x] **Tâche 6.5** — Smoke test VM `quota:seed-from-legacy` (en dry-run d'abord) : `php artisan quota:seed-from-legacy --dry-run` puis valider le rapport. Si OK et Henri valide → run réel `php artisan quota:seed-from-legacy`. **NE PAS** lancer en prod sans dry-run préalable.
- [x] **Tâche 6.6** — Grep finaux :
  - `grep -rn 'TYPE_DEFAULT_ITINERANT' app tests` → ≥ 5 hits applicatifs (constante + getEffectiveQuota + tests).
  - `grep -rn 'trash:purge\|TrashPurgeCommand' app tests routes resources docs` → hits attendus dans Kernel.php + TrashPurgeCommand.php + tests + UI bouton + docs.
  - `grep -rn 'quota:seed-from-legacy\|QuotaSeedFromLegacy' app tests config docs` → hits attendus uniquement (commande + tests + .env.example + docs).
  - `grep -rn "trash:purge.*5.1d\|prochaine version" resources/views/pages/admin` → 0 hit (AC 10 cleanup).
- [x] **Tâche 6.7** — Dev Notes finales rédigées : Kickoff Décisions D1-D9 + Investigation initiale + Dev Agent Record + File List ci-dessous.

---

## Fichiers concernés (prévisionnel)

### Fichiers créés (∼ 8-9)

- `app/Console/Commands/TrashPurgeCommand.php` *(nouvelle commande Artisan, ∼150 lignes — scan /home/trash + DI HomeDirService + audit)*
- `app/Console/Commands/QuotaSeedFromLegacyCommand.php` *(nouvelle commande Artisan, ∼250 lignes — connexion legacy_mysql + parsing + idempotence + dry-run/force + init defaults profils)*
- `tests/Unit/Services/Filesystem/XfsQuotaServiceItinerantTest.php` *(5 tests — AC 1-3 + régressions sécurité)*
- `tests/Feature/Console/TrashPurgeCommandTest.php` *(5 tests — AC 5-7 + edge cases)*
- `tests/Feature/Console/QuotaSeedFromLegacyCommandTest.php` *(7 tests — AC 11-15 + idempotence/force)*

### Fichiers modifiés (∼ 7-8)

- `app/Services/Filesystem/XfsQuotaService.php` — `getEffectiveQuota()` : ajout bloc itinérant entre USER/GROUP et `match($userProfile)` (D5=A — Option A préserve la signature publique). Docblock mis à jour.
- `app/Console/Kernel.php` — ajout du `$schedule->command('trash:purge')->dailyAt('02:00')->withoutOverlapping()->runInBackground()->when(closure)` avant le bloc `quota:snapshot` à 03h00.
- `app/Models/QuotaRule.php` — éventuelle adaptation mineure de `getTypeLabel()` (déjà OK 5.1c) ou rien à toucher si la constante `TYPE_DEFAULT_ITINERANT` est déjà utilisable telle quelle.
- `config/database.php` — ajout de la connexion `legacy_mysql` (D1=A).
- `.env.example` — ajout des 5 variables `LEGACY_DB_*` documentées.
- `resources/views/pages/admin/settings/_partials/quotas-fs-tab.blade.php` — retrait alert info l. 472-483 (AC 10) + ajout bouton "Purger maintenant" + méthode Livewire `purgeNow()`.
- `tests/Feature/Console/KernelScheduleTest.php` — extension : 2 nouveaux tests AC 8 (`it_schedules_trash_purge_at_02h00` + `it_does_not_schedule_trash_purge_when_auto_disabled`).
- `tests/Feature/Livewire/Admin/AdminSettingsQuotasFsTabTest.php` — extension : 2 nouveaux tests AC 9 (`it_purges_now_via_button_when_admin` + `it_blocks_purge_now_without_server_admin`).
- `docs/domains/filesystem.md` — append section Story 5.1d.
- `docs/qa/domains/filesystem.md` — append section Story 5.1d avec 8-10 scénarios numérotés.

### Fichiers supprimés (0)

Aucun fichier supprimé en 5.1d. La section "Corbeille" de l'onglet Quotas & FS perd son alert info via Edit, pas via suppression.

### Fichiers NON touchés (vérification explicite)

- `app/Services/Filesystem/HomeDirService.php` — **interdit** d'y toucher. La méthode `deleteHomeDirectoryPermanently` est consommée telle quelle. Toute modification = risque régression sur les tests anti-injection (41 tests) + sur archive/restore.
- `app/Console/Commands/QuotaSnapshotCommand.php` — aucun changement (5.1b livré, validé).
- `app/Listeners/NotifyQuotaOverageOnLogin.php` — aucun changement (5.1c livré, validé).
- `app/Models/User.php` — aucun changement cast/fillable `quota_snapshot` ni `isExternal()` (réutilisée telle quelle).
- `app/Models/SystemSetting.php` — aucun changement (helpers `get/set/forget` consommés tels quels).
- `app/Models/QuotaRule.php` — déjà étendu en 5.1c avec `TYPE_DEFAULT_ITINERANT`. Aucune nouvelle constante nécessaire.
- `app/Http/Controllers/QuotaController.php` — endpoint legacy dormant (D11=A 5.1c) conservé tel quel.
- `resources/views/pages/admin/settings/index.blade.php` — aucun changement (scaffold onglets unique livré 5.1c).
- `resources/views/pages/users/groups/[id]/_partials/group-quota-section.blade.php` — aucun changement (livré 5.1c).
- `resources/views/pages/users/[login]/_partials/quota-section.blade.php` — aucun changement (livré 5.1b, post-review fix #6).
- `database/migrations/*` — aucune nouvelle migration. Le champ `quota_rules.type` est déjà `string(20)` qui accepte `default_itinerant`.

---

## Dépendances

| Story | Statut | Pourquoi 5.1d en dépend |
|---|---|---|
| **5.1a** — Refactor Services Filesystem | done ✅ (2026-04-23) | Fournit `HomeDirService::deleteHomeDirectoryPermanently` (consommée par `trash:purge`) + `XfsQuotaService::getEffectiveQuota` (modifiée par 5.1d Volet 1). |
| **5.1c** — Quotas groupes, /admin/settings, flash over-quota | done ✅ (2026-04-27, validé Henri) | Fournit `SystemSetting::get('quota.trash')` (consommé par `trash:purge` + le `->when()` Kernel) + section "Corbeille" UI (à enrichir du bouton "Purger maintenant") + constante `QuotaRule::TYPE_DEFAULT_ITINERANT` (consommée par Volet 1). |

5.1d **NE dépend PAS** de 5.1b (snapshot quotas) directement — `quota:snapshot` reste en place sans modification. La dépendance `5.1a` couvre l'extraction de service dont 5.1d réutilise un méthode.

5.1d **clôt la Story 5.1 splittée**. Aucune story avale n'en dépend.

---

## Dev Notes

### Patterns à suivre

- **Convention services Filesystem** (5.1a) : `App\Services\Filesystem\XfsQuotaService` et `App\Services\Filesystem\HomeDirService`. **Interdiction** d'appeler directement `sudo xfs_quota`, `sudo quota`, `sudo rm` hors de ces services. La commande `trash:purge` PASSE PAR `HomeDirService::deleteHomeDirectoryPermanently` — pas de `exec` direct.
- **Logs préfixe historique** `QuotaService:` dans tous les logs `Log::info/warning/error` (décision SM 5.1a — opérateurs greppent ce préfixe sur `/var/log/`). S'applique aussi aux commandes 5.1d.
- **Commandes Artisan pattern** : suivre `QuotaSnapshotCommand` (5.1b) — `protected $signature` + `protected $description` + `handle(): int` + `Command::SUCCESS / Command::FAILURE`. DI via constructeur. Logs préfixés. Retour stdout formaté.
- **Tests Unit vs Feature** : `XfsQuotaServiceItinerantTest` = Unit (pure logique, RefreshDatabase + factories). `TrashPurgeCommand`/`QuotaSeedFromLegacyCommand` = Feature (commandes Artisan, BDD, eventually filesystem).
- **`base_path('...')`** privilégier aux `dirname(__DIR__, N)` (règle mémorisée `feedback_prefer_base_path.md`).
- **Migration legacy_mysql sqlite testing** : créer la 2e connexion sqlite in-memory dans `setUp()` du test, créer manuellement le schéma `quotas` legacy via `Schema::connection('legacy_mysql')->create('quotas', fn(Blueprint $t) => ...)` ou `DB::connection('legacy_mysql')->statement('CREATE TABLE ...')`. Pattern documenté dans `tests/Feature/Console/QuotaSeedFromLegacyCommandTest.php` (à créer).
- **Suppression sécurisée** : **interdiction stricte de `rm -rf`** côté code, hors de la méthode `HomeDirService::deleteHomeDirectoryPermanently` qui l'enforce avec garde regex + `escapeshellarg`. Côté `trash` machine VM : utiliser `gio trash` ou `trash` (cf. CLAUDE.md `/home/htouchard/.claude/CLAUDE.md`).
- **Idempotence commandes one-shot** : `quota:seed-from-legacy` doit être ré-exécutable sans effet de bord (sauf `--force`). Pattern `firstOrCreate` Eloquent.
- **Pattern `->when()` Schedule** : la closure est évaluée à chaque tick. L'évaluation doit rester légère (1 SELECT BDD). Cohérent avec `controlhub:heartbeat` (l. 16-19 Kernel) qui suit un pattern similaire.

### Permissions & Gates — rappel

- **Permission `SambaPermission::ServerAdmin = 'server.admin'`** réutilisée pour le bouton "Purger maintenant" (pas de nouvelle permission). Cohérent saveDefaults/saveGrace/saveTrash 5.1c.
- **Commandes Artisan** : `trash:purge` et `quota:seed-from-legacy` ne sont accessibles que via shell/cron — pas exposées en HTTP. Pas de gate runtime nécessaire.

### Testing Strategy

**Stratégie : tests Unit (Volet 1) + Feature (Volets 2/3) + non-régression stricte 5.1a/b/c.**

- **Baseline** : ≥ 1172 tests verts (post-5.1c validé Henri 2026-04-27). Cible 5.1d : **+15 nouveaux tests minimum**, **0 régression**.
- **Tests Unit `XfsQuotaServiceItinerantTest`** : `RefreshDatabase` + `User::factory()` (school_code paramétré) + mock `\App\Facades\SEConfig::getCurrentEstablishmentCode()` via `SEConfig::shouldReceive('getCurrentEstablishmentCode')->andReturn('PARIS')`. Tests rapides (< 100ms chacun).
- **Tests Feature `TrashPurgeCommandTest`** : utiliser un `TRASH_DIR` testing override via une constante de classe `TrashPurgeCommand::TRASH_DIR` (default `/home/trash`, override via reflection ou public static dans setUp). **Décision dev** : exposer une property `public static string $trashDir = '/home/trash'` permet `TrashPurgeCommand::$trashDir = sys_get_temp_dir() . '/trash-test-' . uniqid();` dans setUp + cleanup tearDown.
- **Tests Feature `QuotaSeedFromLegacyCommandTest`** : 2e connexion sqlite via `config(['database.connections.legacy_mysql' => ['driver' => 'sqlite', 'database' => ':memory:']])` + schema manuel + `DB::connection('legacy_mysql')->table('quotas')->insert(...)` pour préparer les fixtures. Pattern testable.
- **Mock `Artisan::call`** dans `AdminSettingsQuotasFsTabTest::it_purges_now_via_button_when_admin` : `Artisan::shouldReceive('call')->with('trash:purge')->once()->andReturn(0);` + `Artisan::shouldReceive('output')->andReturn('Purgé : 5 dossier(s).');`.
- **`KernelScheduleTest`** : pattern existant (cf. tests `it_schedules_quota_snapshot_at_3am` 5.1b). Pour le `->when()`, accéder à `$event->filtersPass($app)` après mock de `SystemSetting::get`.
- **Migration tests sqlite** : aucune migration nouvelle 5.1d, pas d'enjeu.

### Points d'attention / risques

| Risque | Probabilité | Impact | Mitigation |
|---|---|---|---|
| **`User::isExternal()` ne couvre pas tous les "itinérants"** (D8) | Moyenne | AC 1/2/3 invalides côté métier | Validation Henri kickoff. Si insuffisant, escalader pour ajouter attribut `User::is_itinerant` ou groupe AD dédié. |
| **Connexion `legacy_mysql` indisponible en prod (D1)** | Moyenne | AC 11 invalide en prod | AC 14 explicite : commande retourne FAILURE explicite avec message clair "configurer LEGACY_DB_*". Documenter la config dans docs/qa. |
| **Sudoers VM rejette `sudo rm`** (D7) | Faible | `trash:purge` échoue silencieusement OU plante | Validation kickoff Tâche 0.3. HomeDirService a déjà ce besoin depuis 5.1a, donc déjà éprouvé en prod. |
| **`trash:purge` supprime `/home/<active-user>/` par bug** | Très faible | CATASTROPHIQUE (perte data prod) | Triple garde : (1) commande scanne `/home/trash/`, (2) regex login, (3) HomeDirService colle `/home/trash/` préfixe. Tests unit explicites sur le path concaténé. |
| **`trash:purge` purge un user désactivé qui revient** | Faible | Perte data utilisateur disabled | TTL configurable (default 30j) + log audit. Convention métier : un user disabled > 30j est définitivement parti. À acter avec Henri. |
| **`->when()` closure à chaque tick = pollution BDD** | Faible | 1 SELECT/min sur `system_settings` | Acceptable (table peu volumineuse, pkey indexée). Si benchmark prouve lent, utiliser cache 60s avec `Cache::remember`. |
| **`quota:seed-from-legacy` race condition avec sync-from-ad** | Faible | UserGroup pas encore syncs → user mal classé | Documenter dans docs/qa : "Lancer `users:sync-from-ad` AVANT `quota:seed-from-legacy`". |
| **Migration enum `quota_rules.type` jugée nécessaire à tort** | Très faible | Story bloquée par migration inutile | Vérifié : `type` est `string(20)`, pas un enum DB. **Aucune migration**. |
| **Bouton "Purger maintenant" sync bloque l'UI 30s+ si gros volume** | Faible | UX dégradé | D3=A choix sync acceptable pour ≤50 dossiers. Si volume prod gros, bascule async via `PurgeTrashJob`. À monitorer post-déploiement. |
| **Rétrocompatibilité signature `getEffectiveQuota`** | Faible | Casse les call sites externes | D5=A préserve la signature publique. Tests AC 4 explicite. |
| **Audit `quota_audit_logs` saturée** | Faible | Tables grossit lentement | ≤50 rows/jour purge + ≤200 rows seed one-shot. Acceptable plusieurs années. Si problème, ajouter purge `quota:audit-prune` future story. |

### References

- [Source: epics.md#Story-5.1d:1665-1707](../planning-artifacts/epics.md) — scope + AC originaux de la story.
- [Source: epics.md#Investigation-Legacy-2026-04-22:1521-1536](../planning-artifacts/epics.md) — décisions produit Henri (TTL trash + toggle + seed legacy).
- [Source: architecture.md#Services/Filesystem:447](../planning-artifacts/architecture.md) — mapping architectural `Filesystem/`.
- [Source: 5-1a-refactor-services-filesystem.md](5-1a-refactor-services-filesystem.md) — 5.1a livré : `HomeDirService::deleteHomeDirectoryPermanently` + `XfsQuotaService::getEffectiveQuota`.
- [Source: 5-1b-snapshot-quotas-quotidien-et-ui-user.md](5-1b-snapshot-quotas-quotidien-et-ui-user.md) — 5.1b livré : pattern commande Artisan `quota:snapshot` à décalquer pour `trash:purge` + `quota:seed-from-legacy`.
- [Source: 5-1c-quotas-groupes-settings-flash-over-quota.md](5-1c-quotas-groupes-settings-flash-over-quota.md) — 5.1c livré : `SystemSetting`, onglet `/admin/settings → Quotas & FS` à enrichir, constante `TYPE_DEFAULT_ITINERANT` passive.
- [Source: app/Services/Filesystem/HomeDirService.php:160](../../app/Services/Filesystem/HomeDirService.php) — méthode `deleteHomeDirectoryPermanently` consommée par `trash:purge`.
- [Source: app/Services/Filesystem/XfsQuotaService.php:71](../../app/Services/Filesystem/XfsQuotaService.php) — `getEffectiveQuota` à modifier.
- [Source: app/Models/User.php:304](../../app/Models/User.php) — `isExternal()` consommée par Volet 1.
- [Source: app/Models/QuotaRule.php:59](../../app/Models/QuotaRule.php) — constante `TYPE_DEFAULT_ITINERANT`.
- [Source: app/Models/SystemSetting.php](../../app/Models/SystemSetting.php) — helpers `get/set/forget`.
- [Source: app/Console/Kernel.php:13](../../app/Console/Kernel.php) — schedule à étendre.
- [Source: app/Console/Commands/QuotaSnapshotCommand.php](../../app/Console/Commands/QuotaSnapshotCommand.php) — pattern commande Artisan à imiter.
- [Source: resources/views/pages/admin/settings/_partials/quotas-fs-tab.blade.php:456](../../resources/views/pages/admin/settings/_partials/quotas-fs-tab.blade.php) — section "Corbeille" à enrichir du bouton + retirer alert info.
- [Source: sambaedu/includes/quotas.inc.php:172](../../sambaedu/includes/quotas.inc.php) — schéma table `quotas` legacy (nom, quotasoft, quotahard, partition).
- [Source: docs/qa/README.md:14-17](../../docs/qa/README.md) — convention QA append-only par domaine (interdiction `5-1d-e2e-manual.md`).
- [Source: docs/qa/domains/filesystem.md](../../docs/qa/domains/filesystem.md) — fichier QA à enrichir append-only.
- [Source: CLAUDE.md](../../CLAUDE.md) — conventions routing filesystem-based + Livewire SFC + interdiction `rm -rf` (utiliser `trash`/`gio trash`).

### Kickoff Décisions (validées Henri 2026-04-27, appliquées par dev)

- **D1=A** — Source legacy : connexion `legacy_mysql` ajoutée à
  `config/database.php` + variables `.env.LEGACY_DB_*`. Si `LEGACY_DB_DATABASE`
  ou `LEGACY_DB_USERNAME` vide → la commande retourne FAILURE avec un message
  stdout explicite (AC 14).
- **D2=A** — TTL invalide → no-op safe : si `quota.trash.ttl_days <= 0` ou
  clé absente → `Log::info` + exit SUCCESS, aucun dossier touché. `--force`
  bypass ce garde-fou.
- **D3=A** — `purgeNow()` sync via `Artisan::call('trash:purge')` inline.
  Volume faible attendu (≤50 dossiers en moyenne SER), feedback immédiat
  à l'admin via toast WithToasts.
- **D4** — Defaults profils initialisés : élève 500/600, prof 1000/1200,
  admin 2000/2400, itinérant 200/240 Mo (mêmes valeurs sur `/home` et
  `/var/sambaedu`). Sans `--force`, les defaults existants (personnalisés
  par l'admin via l'onglet UI) ne sont pas écrasés.
- **D5=A** — Résolution `isExternal` interne dans `getEffectiveQuota` via
  lookup `User::where('login', $username)->first()`. Signature publique
  préservée (test `it_preserves_public_signature_for_get_effective_quota`).
- **D6=A** — Audit `quota_audit_logs` pour chaque suppression `trash:purge`
  réussie (`target_type='trash'`, `action='delete'`,
  `performed_by='trash:purge'` ou `'ui:<login>'`). Best-effort : un échec
  d'audit ne bloque pas la commande (Log::warning + continue).
- **D7=A** — Sudoers VM réutilisé tel quel via `HomeDirService::deleteHomeDirectoryPermanently`
  qui appelle `sudo rm -rf /home/trash/{login}` avec garde regex login +
  `escapeshellarg` (éprouvé en 5.1a — 41 tests). Aucune nouvelle entrée
  sudoers nécessaire.
- **D8=A** — Sémantique "itinérant" = `User::isExternal()` (school_code !=
  current_code). Couvre les utilisateurs rattachés à un autre établissement.
  Documentation explicite dans le filesystem.md.
- **D9=A** — `default_itinerant` PRIME sur `default_<profile>` quand
  `isExternal()=true`. Confirmé par Henri (cohérent avec epics.md). Test
  `it_applies_default_itinerant_when_user_is_external` couvre AC 1.

---

## Recommandation Modèle Dev

### Choix : **opus** (claude-opus-4-7)

### Justification

5.1d cumule **3 commandes Artisan distinctes** + **1 modification surgicale d'un service de service crititque** (`getEffectiveQuota` consommée par snapshot, wallpaper, controllers, livewire) + **1 enrichissement UI** + **1 investigation legacy MySQL non triviale**. Le scope est dense et hétérogène, avec plusieurs domaines transverses simultanés :

1. **Sécurité critique sur `trash:purge`** — la commande supprime des dossiers `rm -rf` sur la VM. Le moindre bug de path concat = perte de data prod. Triple garde requise (commande, regex, service). Le dev doit raisonner sur les 3 niveaux et écrire des tests qui couvrent EXPLICITEMENT le path concat (pas juste le regex login). Opus excelle sur cette analyse défensive.

2. **Modification surgicale `getEffectiveQuota`** — méthode consommée par 6+ call sites (snapshot, wallpaper, livewire user/group, dispatchRecalculateGroupJob, controller). La signature publique DOIT rester intacte (D5=A). Le dev doit raisonner sur les contournements possibles (User::factory pas synchro, school_code non rempli, current_code vide…) et garantir 0 régression sur 1172 tests. Opus garde le contexte global mieux que sonnet.

3. **Investigation legacy MySQL** — la table `quotas` legacy n'a pas de `type` user/group → discrimination via `UserGroup::where('name')->exists()` qui peut foirer si sync-from-ad pas encore tournée. Multiples cas d'erreur à traiter (connexion absente, schéma divergent, partition inconnue, valeurs malformées). Demande de la robustesse défensive.

4. **Planification conditionnelle Kernel `->when()`** — pattern peu courant. Le dev doit comprendre que la closure est ré-évaluée à chaque tick (1 SELECT/min) et pondérer les options (cache 60s ?). Opus raisonne mieux sur les trade-offs.

5. **9 décisions produit à trancher** au kickoff (D1-D9). Volume comparable à 5.1c (12 décisions). Chaque décision a des ramifications cross-fichiers. Opus mieux outillé pour le suivi.

6. **15+ nouveaux tests sur 4 fichiers** — XfsQuotaServiceItinerantTest, TrashPurgeCommandTest, QuotaSeedFromLegacyCommandTest, KernelScheduleTest étendu, AdminSettingsQuotasFsTabTest étendu. Setup/teardown différents (Eloquent, filesystem temp dir, 2e connexion sqlite, mocking Artisan, schedule inspection). Coordination orchestration test = opus.

7. **Non-régression stricte sur 1172 tests** — Volet 1 modifie une méthode coeur consommée massivement. Le dev doit lancer 28 tests ciblés 5.1c + 41 HomeDirServiceTest + 19 5.1b + tests wallpaper/quota controller. Opus orchestre mieux le run/diagnose.

**Alternative sonnet envisageable si** Henri accepte de découper en 3 PR séparées : PR1 = Volet 1 (default_itinerant — sonnet faisable, court, isolé) ; PR2 = Volet 2 (trash:purge + UI — sonnet faisable, mais sécurité demande relecture) ; PR3 = Volet 3 (quota:seed-from-legacy — opus à cause investigation legacy). Plus lourd opérationnellement (3 cycles dev/review). **Opus en une passe est plus simple, plus sûr, plus prévisible — recommandation forte.**

Modèle recommandé final : **`opus`** (claude-opus-4-7).

---

## Dev Agent Record

### Agent Model Used

claude-opus-4-7 (1M context)

### Debug Log References

- Baseline avant démarrage : 1172 passed / 1 incomplete / 47 skipped
  (vérifié 2026-04-27 14:33 sur la VM `php artisan test`).
- Suite finale : 1201 passed / 1 incomplete / 47 skipped → +29 tests verts,
  0 régression confirmée.
- Aucun test pré-existant modifié.

### Completion Notes List

**Volet 1 — `default_itinerant` actif** (AC 1-4) :

- `XfsQuotaService::getEffectiveQuota` (l. 71-180 post-modif) : insertion
  d'un bloc itinérant entre la lookup GROUP et le `match($userProfile)`.
- Garde-fou : `try/catch` autour du `User::where(...)->first()` pour ne pas
  casser le service si la connexion BDD est indisponible (cohérent avec
  les fail-soft 5.1a/b/c).
- Aucune migration. Constante `TYPE_DEFAULT_ITINERANT` déjà présente
  depuis 5.1c.
- 7 tests Unit dans `XfsQuotaServiceItinerantTest` (AC 1-3 + 2 régressions
  sécurité USER/GROUP > itinerant + 1 fallback "user inconnu" + 1
  signature préservée D5=A).

**Volet 2 — `trash:purge` + UI bouton** (AC 5-10) :

- `app/Console/Commands/TrashPurgeCommand.php` créé (∼260 lignes) avec
  signature `{--dry-run} {--force} {--performed-by=}`. DI
  `HomeDirService` via constructeur. Property statique `$trashDir`
  surchargeable en test (pattern décidé par dev — pragmatique).
- `Console\Kernel::schedule()` étendu avec `trash:purge` à 02h00 +
  `->when(closure)` lisant `quota.trash.purge_auto`.
- `quotas-fs-tab.blade.php` : retrait alert info "5.1d à venir" (AC 10),
  ajout bouton "Purger maintenant" avec `wire:confirm`, méthode
  Livewire `purgeNow()` avec double guard `Gate::allows('server.admin')`,
  parsing du compteur "Purgé : N" depuis `Artisan::output()`.
- Retrait du badge "Effectif en 5.1d" sur la card "Itinérant" (puisque
  désormais effectif).
- 8 tests Feature dans `TrashPurgeCommandTest` (AC 5-7 + dry-run + force +
  audit + skip nom invalide).
- 3 tests dans `KernelScheduleTest` pour la planification + filtrage
  `->when()`.
- 3 tests dans `AdminSettingsQuotasFsTabTest` pour le bouton purgeNow.

**Volet 3 — `quota:seed-from-legacy`** (AC 11-15) :

- `config/database.php` : ajout connexion `legacy_mysql` lue via
  `env('LEGACY_DB_*')`.
- `.env.example` : 6 variables `LEGACY_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD/SOCKET`
  avec commentaire d'usage.
- `app/Console/Commands/QuotaSeedFromLegacyCommand.php` créé (∼330 lignes)
  avec signature `{--dry-run} {--force}`, lecture table legacy `quotas`,
  discrimination user/group via `UserGroup::where('name')->exists()`,
  conversion KB→MB, idempotence par défaut, init defaults profils si
  absents (D4), audit complet, rapport stdout final.
- Garde-fou connexion absente (AC 14) : si `database`/`username` vide ou
  PDO échoue → `Log::error` + message stdout + FAILURE.
- 8 tests Feature dans `QuotaSeedFromLegacyCommandTest` via 2e connexion
  sqlite in-memory pour `legacy_mysql`. AC 11/12/13/14/15 + 2 idempotence
  + 1 errors malformed.

**Documentation** :

- `docs/domains/filesystem.md` : append section "Story 5.1d" (∼130 lignes)
  avec les 3 volets, les valeurs D4, et les variables `.env`.
- `docs/qa/domains/filesystem.md` : append section "Story 5.1d" avec
  10 scénarios numérotés stables 5.1d-1 à 5.1d-10.
- `docs/qa/README.md` non modifié (le tableau "Domaines couverts" reste
  correct — Filesystem couvre 5.1c+5.1d).

**Tests** :

- 7 (Unit XfsQuotaServiceItinerantTest)
- 8 (Feature TrashPurgeCommandTest)
- 8 (Feature QuotaSeedFromLegacyCommandTest)
- 3 (Feature KernelScheduleTest étendu)
- 3 (Feature AdminSettingsQuotasFsTabTest étendu)
- **Total : 29 nouveaux tests verts** (vs cible ≥ 15 — largement dépassée).

**Smoke tests VM** :

- `trash:purge --dry-run` testé sur VM avec succès (1 dossier candidat
  détecté, sortie tabulaire propre).
- `quota:seed-from-legacy --dry-run` testé sur VM : retourne le bon
  message d'erreur AC 14 puisque `LEGACY_DB_*` non configurées (comportement
  attendu — c'est l'admin prod qui configurera l'env).

**Notes opérationnelles** :

- La planification `trash:purge` est gated par le toggle UI ; le scheduler
  liste TOUJOURS la commande mais ne l'exécute que si le toggle est on.
  Pas de redéploiement nécessaire pour activer/désactiver.
- L'audit `quota_audit_logs` peut grossir : ≤50 rows/jour de purge + ≤200
  rows lors du seed one-shot. Acceptable plusieurs années. Si problème
  futur, ajouter `quota:audit-prune` dans une story dédiée.

### File List

**Fichiers créés (5)** :

- `app/Console/Commands/TrashPurgeCommand.php` — commande Artisan trash:purge
- `app/Console/Commands/QuotaSeedFromLegacyCommand.php` — commande Artisan quota:seed-from-legacy
- `tests/Unit/Services/Filesystem/XfsQuotaServiceItinerantTest.php` — 7 tests Unit
- `tests/Feature/Console/TrashPurgeCommandTest.php` — 8 tests Feature
- `tests/Feature/Console/QuotaSeedFromLegacyCommandTest.php` — 8 tests Feature

**Fichiers modifiés (8)** :

- `app/Services/Filesystem/XfsQuotaService.php` — bloc itinérant inséré
  dans getEffectiveQuota + import `App\Models\User`.
- `app/Console/Kernel.php` — schedule trash:purge à 02h00 + ->when().
- `config/database.php` — connexion `legacy_mysql`.
- `.env.example` — 6 variables `LEGACY_DB_*`.
- `resources/views/pages/admin/settings/_partials/quotas-fs-tab.blade.php`
  — retrait alert info, ajout bouton "Purger maintenant", méthode
  `purgeNow()`, retrait badge "Effectif en 5.1d", import `Artisan` facade.
- `tests/Feature/Console/KernelScheduleTest.php` — 3 nouveaux tests.
- `tests/Feature/Livewire/Admin/AdminSettingsQuotasFsTabTest.php` —
  3 nouveaux tests + import `Artisan`/`Mockery`.
- `docs/domains/filesystem.md` — section "Story 5.1d" appendée.
- `docs/qa/domains/filesystem.md` — section "Story 5.1d" + 10 scénarios.
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — passage
  ready-for-dev → review + last_updated.

**Fichiers supprimés (0)** : aucun.

### Code Review Fixes (2026-04-27)

Code review réalisée par claude-sonnet-4-6 + second avis claude-opus-4-7.
Document complet : [`_bmad-output/codeReviews/5-1d.md`](../codeReviews/5-1d.md).

**13 corrections appliquées par claude-opus-4-7 (1M context)** :

1. **#1 Commentaires STALE `QuotaRule.php`** — 3 blocs ("PASSIVE en 5.1c"
   / "5.1d activera") remplacés par "ACTIF depuis 5.1d (2026-04-27)" +
   `@property string $type` enrichi de `default_itinerant`.
2. **#3 Imports morts** — `use App\Models\User`, `use App\Models\SystemSetting`
   supprimés de `QuotaSeedFromLegacyCommand.php`.
3. **#5 Toast `purgeNow` faux succès** — pré-check `SystemSetting::get('quota.trash')`
   ajouté avant `Artisan::call` ; si TTL ≤ 0, `toastError` explicite et `return`.
4. **#6 docs/qa/README.md** — tableau "Domaines couverts" → `5.1c, 5.1d`.
5. **#7 docs/domains/filesystem.md** — paragraphes "passive en 5.1c" /
   "sera livrée par 5.1d" remplacés par état actuel post-5.1d (commande
   active, schedule conditionnel, bouton manuel disponible).
6. **#8 KernelScheduleTest** — `use DatabaseTransactions` + `tearDown()`
   appelant `SystemSetting::forget('quota.trash')` defensive (random order).
7. **#9 Note doc `target_type`** — valeurs valides ajoutées dans
   `docs/domains/filesystem.md` (`user`, `group`, `default_*`, `trash`).
8. **#M5 Validation `--performed-by`** — regex `/^[a-zA-Z0-9._:-]+$/` ajoutée
   en début de `TrashPurgeCommand::handle()`, exit FAILURE si invalide.
9. **#M6 Timeout PDO `legacy_mysql`** — `'options' => [\PDO::ATTR_TIMEOUT => 5]`
   ajouté dans `config/database.php`.
10. **#M7 Validation NULL/négatif seed** — détection `null` AVANT cast int
    (sinon `(int) null === 0` interprété comme illimité), check `< 0`,
    incrémente compteur `errors` + log warning + skip. Charset `utf8mb4`
    documenté.
11. **#M8 Doc CLI guard** — note "exécuter en root/www-data, pas de guard
    auth utilisateur, protection via permissions Unix" ajoutée.
12. **#M9 Test trashDir inexistant** — `it_returns_success_when_trash_dir_missing`
    ajouté dans `TrashPurgeCommandTest` (rmdir avant Artisan, vérifie SUCCESS
    + output "inexistant" + 0 audit).
13. **#M10 Skip symlinks** — `if (is_link($path)) { Log::warning(...); continue; }`
    ajouté dans `TrashPurgeCommand::collectCandidates`.

**Effet collatéral** : `AdminSettingsQuotasFsTabTest::test_it_purges_now_via_button_when_admin`
et `test_it_emits_error_toast_when_purge_command_fails` ajustés (ajout
`SystemSetting::set('quota.trash', ['ttl_days' => 30, ...])`) pour traverser
le pré-check TTL UI introduit par #5.

**Suite de tests post-fixes** (VM `php artisan test` complet) :

- 1202 passed (vs baseline 1201 fixée brief Henri ; +1 test M9)
- 1 incomplete (identique baseline)
- 47 skipped (identique baseline)
- **0 failure**, 0 régression confirmée

**7 problèmes laissés en attente** (questions Henri ou follow-ups
optionnels) :

- #2 Divergence `$trashDir` overridable / hardcode `/home/trash/` dans
  `HomeDirService` — décision design (DI complet vs commentaire `@internal`).
- #4 Sémantique `--force` + TTL=0 — Q2 Henri (no-op silencieux vs purge
  totale vs interdiction).
- #10 Test boundary `ageDays == ttlDays` — Q1 Henri (sémantique TTL J+30
  inclusive vs J+31 exclusive).
- #M1 Risque N+1 `dispatchRecalculateGroupJob` — optionnel (uniquement si
  volume > 500 users).
- #M2 Race condition `restoreHomeDirectory` ↔ `trash:purge` — Q3 Henri
  (acceptable comme dette ou `Cache::lock` immédiat).
- #M3 Lock UI bouton "Purger maintenant" — Q4 Henri (Cache::lock
  `'trash:purge'` 600s).
- #M4 Cache lookup `User` dans `getEffectiveQuota` — optionnel
  (mémoïsation request lifecycle).

**Fichiers modifiés par les fixes (11)** :

- `app/Models/QuotaRule.php`
- `app/Console/Commands/QuotaSeedFromLegacyCommand.php`
- `app/Console/Commands/TrashPurgeCommand.php`
- `config/database.php`
- `docs/domains/filesystem.md`
- `docs/qa/README.md`
- `docs/qa/domains/filesystem.md`
- `resources/views/pages/admin/settings/_partials/quotas-fs-tab.blade.php`
- `tests/Feature/Console/KernelScheduleTest.php`
- `tests/Feature/Console/TrashPurgeCommandTest.php`
- `tests/Feature/Livewire/Admin/AdminSettingsQuotasFsTabTest.php`

Aucun nouveau fichier créé hors test M9 (intégré au fichier existant).
Aucun fichier supprimé.
