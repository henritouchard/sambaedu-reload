# Code Review — Story 1bis.18f : Profils itinérants (refonte native + bridge SYSVOL)

Status: to-validate
Date: 2026-04-28
Dev model: claude-opus-4-7 (1M context)
Review model: claude-sonnet-4-6 (modèle opposé)
Second review: oui (claude-opus-4-7 — vérification de pertinence des findings Sonnet)

## Verdict global

**Feu orange.** Implémentation solide, conforme `CLAUDE.md` (modale `<x-molecules.modal>`, `WithToasts`, filesystem-based router, PHPUnit `#[Test]`, `auth()->user()` Eloquent, `base_path()`). 24/24 tests verts, 0 régression. Sonnet a remonté 7 problèmes ; le second avis Opus invalide #2 (faux positif — `du -b` bytes legacy, formule correcte) et nuance #1 (mode IP whitelist reste fonctionnel en prod). Reste 6 corrections utiles, dont 5 automatisables sans décision produit.

## Questions en attente

> Questions nécessitant une réponse de Henri avant correction.

1. **`se4_key` (#1)** — Source de vérité de la clé partagée pour `del-roam.sh` ? Options :
   - (A) `env('SE4_KEY', '')` dans `config/sambaedu.php` + `.env.example` *(recommandation Opus, simple)*
   - (B) Lecture depuis le `$config` legacy via `get_config()` au middleware *(introduit dépendance bootstrap dans middleware, à éviter)*
   - (C) Ne pas implémenter le mode "key only" pour l'instant et garder l'IP whitelist comme seul mode → corriger commentaire middleware uniquement.

2. **OR vs AND (#3)** — Confirmer la sémantique OR (port natif) vs AND (legacy original) ? La story documente la décision OR ; le second avis Opus la valide. Confirmer définitivement avant correction de #3 (sinon non-action).

## Synthèse des problèmes

| # | Problème | Sévérité | Pertinence Opus | Statut |
|---|----------|----------|-----------------|--------|
| 1 | `se4_key` absent de `config/sambaedu.php` — auth par clé inopérante en prod | 🔴 → 🟠 | 2 | ⏳ En attente (Q1 Henri) |
| 2 | Bug calcul Mo dans `parseDuStats()` — division /1024/1024 | 🟠 | **0** (faux positif) | ❌ Non corrigé (formule juste — `du -b` bytes legacy) |
| 3 | Sémantique OR vs AND legacy | 🟠 | 1 | ⏳ En attente (Q2 Henri) — non-action probable |
| 4 | `setExclusions` : `ensureBootstrap()` + `requireFunction()` hors `try/catch` | 🟡 | 1 | ✅ Corrigé |
| 5 | Test unit manquant `setExclusions` filtrage path-traversal | 🟡 | 1 | ✅ Corrigé (test ajouté) |
| 6 | Log `info` flood sur `del-roam.sh` (logon scripts) | 🟡 | 2 | ✅ Corrigé (`info` → `debug`) |
| 7 | `getExclusions()` ne filtre pas via `isValueSafe()` | 🟡 | 1 | ✅ Corrigé (filter + log warning) |
| 8 | `applyVersionBump` peut écraser la GPO si filtrage total (Opus only) | 🟡 | 1 | ✅ Corrigé (garde refus + RuntimeException) |
| — | Commentaire `du -k` trompeur (Opus only, lié à #2) | 🟡 | 1 | ✅ Corrigé (commentaires lignes 234, 320) |

Légende sévérité : 🔴 Critique — 🟠 Important — 🟡 Mineur
Pertinence Opus : 0 = non pertinent, 1 = peu, 2 = pertinent, 3 = très pertinent

## Détail des problèmes

### #1 — `se4_key` absent de `config/sambaedu.php` — authentification par clé inopérante en prod

**Sévérité Sonnet** : 🔴 — **Pertinence Opus** : 2 (downgrade vers 🟠)
**Fichier(s)** : `app/Http/Middleware/AllowSe4FsScript.php:38`, `config/sambaedu.php`

**Constat initial (Sonnet)** :
Le middleware lit `config('sambaedu.se4_key', '')`. La clé n'existe pas dans `config/sambaedu.php` → toujours `''` → `$keyMatch` toujours `false`. Tests passent grâce à `Config::set` injection. En prod, l'auth ne fonctionne que via la whitelist IP.

**Avis Opus** :
Confirmé absence. Mais Sonnet exagère "endpoint cassé en prod" : le **mode IP whitelist reste fonctionnel** (les logon scripts SE4FS tournent sur l'IP attendue). La clé `se4_key` du legacy était stockée dans `/etc/sambaedu/all.cfg.php`, pas dans une config Laravel. Il faut une décision Henri sur la source de vérité (env vs fichier legacy).

**Action** : ⏳ En attente Q1 Henri. Si réponse (A) : 2 lignes à ajouter (config + .env.example).

---

### #2 — Bug calcul taille Mo dans `parseDuStats()`

**Sévérité Sonnet** : 🟠 — **Pertinence Opus** : **0 (faux positif)**
**Fichier(s)** : `app/Services/RoamingProfileService.php:355,362,363`

**Constat initial (Sonnet)** :
`du -k` produit des kilooctets, donc `/ 1024` donne Mo. Le code fait `/ 1024 / 1024` → résultats 1024× trop petits.

**Avis Opus** :
**Faux positif**. Le script legacy `clean_profiles.sh:21-22` (`/usr/share/sambaedu/sbin/`) utilise `du --max-depth=1 -b -t $size` — l'option **`-b` produit des bytes**, pas des Ko. Donc `bytes / 1024 / 1024 = Mo` est arithmétiquement correct. Le legacy `partages.inc.php:664-668` fait exactement la même formule. Port byte-fidèle.

Sonnet a été induit en erreur par un commentaire trompeur dans le service (lignes 234 et 320 mentionnent `du -k`).

**Action** : ❌ Non corrigé sur la formule. ✅ Corriger les commentaires trompeurs `du -k` → `du -b` (lignes 234, 320).

---

### #3 — Sémantique OR vs AND legacy

**Sévérité Sonnet** : 🟠 — **Pertinence Opus** : 1
**Fichier(s)** : `app/Http/Middleware/AllowSe4FsScript.php:19-25,46`

**Constat initial (Sonnet)** :
Legacy AND (IP **et** clé requis) → port natif OR (l'un suffit). Documenté comme décision Henri mais commentaire confusant.

**Avis Opus** :
Confirmé legacy AND. Décision OR tracée explicitement dans la story (L522 + commentaire middleware L23). Combiné avec #1, l'OR fait juste tomber l'auth sur l'IP-whitelist — défendable pour endpoint logon-script (IP source statique). Le test `it_allows_access_when_client_ip_matches_se4fs_ip` formalise déjà le cas.

**Action** : ⏳ En attente Q2 Henri. Probable non-action (décision déjà tracée).

---

### #4 — `setExclusions` : préconditions hors `try/catch`

**Sévérité Sonnet** : 🟡 — **Pertinence Opus** : 1
**Fichier(s)** : `app/Services/RoamingProfileService.php:166-193`

**Constat initial (Sonnet)** :
Asymétrie vs `getExclusions()` (qui englobe tout). Si bootstrap fail, log de service perdu (uniquement `Log::critical` de `requireFunction`).

**Avis Opus** :
Confirmé. Risque faible — `requireFunction` log déjà en critical et l'exception remonte au composant Livewire qui catche `\Throwable`. Mais l'asymétrie stylistique reste agaçante.

**Action** : ✅ Correction auto — déplacer `ensureBootstrap()` + `requireFunction()` dans le `try`. ~5 lignes.

---

### #5 — Test unit manquant `setExclusions filtrage path-traversal`

**Sévérité Sonnet** : 🟡 — **Pertinence Opus** : 1
**Fichier(s)** : `tests/Unit/Services/RoamingProfileServiceTest.php`

**Constat initial (Sonnet)** :
AC #10 cas #5 stipule la couverture côté UI ; cas #11 couvre `generatePurgeScript`. Aucun test direct sur `setExclusions(['../../etc/passwd'])`. Risque de régression silencieuse si quelqu'un retire `isValueSafe` du `setExclusions` interne.

**Avis Opus** :
Confirmé. `isValueSafe` est testée séparément (16 cas), UI testée. Défense-en-profondeur dans `setExclusions` n'a pas son propre test direct. Risque faible mais peu coûteux à couvrir.

**Action** : ✅ Correction auto — ajouter test direct via sous-classe anonyme capturant `change_pol_key`. ~15 lignes.

---

### #6 — Log `info` flood sur `del-roam.sh`

**Sévérité Sonnet** : 🟡 — **Pertinence Opus** : 2
**Fichier(s)** : `app/Http/Middleware/AllowSe4FsScript.php:55-58`

**Constat initial (Sonnet)** :
Endpoint consommé par logon scripts Windows → invocation à chaque login utilisateur sur chaque poste → 200-500 logs/jour dans un collège type 600 élèves.

**Avis Opus** :
Confirmé. Coût opérationnel réel. Le legacy original ne loggait rien sur l'auth réussie. Les `warning` de refus d'accès gardent toute leur valeur.

**Action** : ✅ Correction auto — `Log::info` → `Log::debug` (1 ligne).

---

### #7 — `getExclusions()` ne filtre pas via `isValueSafe()`

**Sévérité Sonnet** : 🟡 — **Pertinence Opus** : 1
**Fichier(s)** : `app/Services/RoamingProfileService.php:130-143`

**Constat initial (Sonnet)** :
Valeurs GPO héritées non-conformes (backslash Windows, etc.) arrivent dans l'UI. Divergence UI/persisté quand `setExclusions` filtre silencieusement à la réécriture.

**Avis Opus** :
Confirmé. Sonnet exagère "divergence UI/persisté" en termes de risque XSS (Blade échappe), mais le risque concret existe : la modale "Mettre à jour la GPO" (`applyToGpo`) re-persiste **toute la liste** y compris valeurs héritées non-safe → filtrage silencieux à la réécriture. Cohérence souhaitable.

**Action** : ✅ Correction auto — appliquer `isValueSafe()` dans `getExclusions()` avec `Log::warning` sur les valeurs filtrées. ~10 lignes.

---

### #8 — `applyVersionBump` peut écraser la GPO si filtrage total (problème nouveau Opus)

**Sévérité Opus** : 🟡 — **Pertinence Opus** : 1
**Fichier(s)** : `app/Services/RoamingProfileService.php:165-228`

**Constat (Opus)** :
Si toutes les valeurs passées à `setExclusions` sont rejetées par `isValueSafe`, `$clean = []` et on appelle `change_pol_key($policy, 'ExcludeProfileDirs', [])` puis `update_gpo_sysvol` avec une politique vidée. Combiné avec `applyVersionBump=true`, on incrémente la GPO en l'effaçant. Cas edge improbable (admin via API forgée saisit que des valeurs malformées) mais aucune garde "n'écrase pas si filtrage total".

**Action** : ✅ Correction auto — ajouter une garde `if ($clean === [] && $values !== []) { Log::warning + return early }`. ~5 lignes.

---

## Résultats finaux des corrections (2026-04-28)

**6 fixes appliqués** sur les 7 problèmes Sonnet (problème #2 invalidé comme faux positif par le second avis Opus — formule arithmétiquement correcte, seuls les commentaires `du -k` sont trompeurs et ont été corrigés en `du -b`). Le problème #8 (nouveau, identifié par Opus) a aussi été traité.

**Restent en attente Henri** :
- **#1** — choix de la source de vérité pour `se4_key` (config Laravel via `env()` vs `$config` legacy). En l'absence de clé, le mode IP whitelist reste fonctionnel pour les logon scripts SE_FS sur l'IP attendue.
- **#3** — confirmation de la sémantique OR (port natif) vs AND (legacy). Décision déjà tracée dans la story et le commentaire middleware.

**Tests** : 25/25 verts post-corrections (8 Unit + 8 Livewire + 5 Endpoint + 4 Legacy redirects, +1 test ajouté sur `setExclusions filtrage`). 1 skip pré-existant non lié (`LegacyGpoIncludesTest::roaming_profiles_stats_stub_returns_empty_array` — gardé `LEGACY_SKIP_LEGACY_INCLUDES` actif). Aucune régression.

**Fichiers modifiés post-review** :
- `app/Services/RoamingProfileService.php` — fixes #4 (try/catch englobant), #7 (filter `getExclusions`), #8 (garde anti-écrasement) + commentaires `du -b` (lié #2)
- `app/Http/Middleware/AllowSe4FsScript.php` — fix #6 (`info` → `debug`)
- `tests/Unit/Services/RoamingProfileServiceTest.php` — fix #5 (test direct `setExclusions filtre path-traversal`)
- `docs/qa/domains/gpo.md` — section Post-correctifs (incidents et scénarios additionnels)

## Points positifs (Sonnet — confirmés Opus)

- **Defense-in-depth path-traversal** cohérente (regex + veto explicite sur `..`).
- **Tests Feature robustes** — pattern de stub `RoamingProfileService` (sous-classe anonyme qui capture les appels) propre, testable sans mock du legacy.
- **Pattern bridge legacy fidèle** — `RoamingProfileService` suit `GpoSyncService` (function_exists + bootstrap + try/catch + log).
- **Middleware `AllowSe4FsScript`** — `hash_equals` (timing-safe), fallback 403 si config absente.
- **Conformité `CLAUDE.md`** — modales `<x-molecules.modal>`, `WithToasts`, filesystem-based router, `<x-molecules.xxx>`, `base_path()`, PHPUnit `#[Test]` — tous respectés.
- **Conformité memory utilisateur** — `auth()->user()` Eloquent direct, `base_path()`, attributs PHPUnit.
