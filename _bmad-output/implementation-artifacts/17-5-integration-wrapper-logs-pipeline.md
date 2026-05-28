# Story 17.5 : Intégration WrapperScriptRenderer dans pipeline 17.2 (opt-in)

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **⚠ Contexte de cadrage critique (lire en premier, SM 2026-05-25)**
>
> La **Story 17.2 (done 2026-05-21) a déjà livré le pipeline d'intégration du wrapper** :
> - méthode `ApplicationScriptsAssembler::wrapInterpreters()` + `mapAction()` (port `app/Gpo/Services/ApplicationScriptsAssembler.php:904-973`) ;
> - branchement opt-in dans `assemble()` ligne 218 conditionné à `config('sambaedu.scripts.logging.enabled', false)` ;
> - injection DI du `?WrapperScriptRenderer $wrapper = null` au constructeur (ligne 84-87) ;
> - déclaration du flag dans `config/sambaedu.php:23-27` (`SAMBAEDU_SCRIPTS_LOGGING_ENABLED`) + `.env.example` ;
> - **6 tests Feature** `tests/Feature/Gpo/ApplicationsScriptsWrapperIntegrationTest.php` couvrant déjà flag ON/OFF, parité bytes flag OFF, mapping action, source enum, fix OS bash, résolution container.
>
> **Ce qui RESTE pour 17.5 = la couche d'activation manquante** : les **commandes artisan**
> `winscript-logs:enable` / `winscript-logs:disable` + `winscript-logs:status`, la **persistance**
> du flag (écriture `.env`), et les **tests Feature des commandes**.
>
> **NE PAS réimplémenter** `wrapInterpreters()`, `mapAction()`, ni recréer la config ou les tests
> d'intégration wrapper déjà livrés par 17.2. **NE PAS** créer de table, d'endpoint, de service de logs
> (tout est livré par 16.12 done). **NE PAS** modifier les scripts upstream ni la signature de `assemble()`.

## Story

As a administrateur SambaEdu (SE5),
I want pouvoir activer ou désactiver l'enveloppe de logging centralisé des scripts d'applications via des commandes artisan simples (`winscript-logs:enable` / `winscript-logs:disable` / `winscript-logs:status`), avec la fonctionnalité désactivée par défaut,
so that je peux brancher l'observabilité parc-wide des scripts (POST vers `/api/v1/script-execution-logs`, infra 16.12) à la demande, sans risque de latence en production par défaut, et sans devoir éditer manuellement le `.env` ni connaître le nommage interne de la clé de config.

## Acceptance Criteria

> 9 ACs organisés en **3 volets** : (1) commande d'activation/désactivation/statut ; (2) persistance & cohérence avec le pipeline 17.2 déjà livré ; (3) tests Feature + non-régression.

### Volet 1 — Commandes artisan de bascule du flag (D1, D2)

**AC1.1 — Commande `winscript-logs:enable`**
**Given** un environnement SE5 où `config('sambaedu.scripts.logging.enabled')` vaut `false` (défaut)
**When** l'admin exécute `php artisan winscript-logs:enable`
**Then** la variable `SAMBAEDU_SCRIPTS_LOGGING_ENABLED` est positionnée à `true` dans le fichier `.env` (base_path) — créée si absente, mise à jour si présente (quelle que soit sa valeur précédente)
**And** le cache de configuration est invalidé (`config:clear`) si un cache existe, afin que la prochaine requête PHP-FPM relise la valeur
**And** la commande affiche un message de confirmation explicite (FR) indiquant que le logging des scripts est désormais activé + rappel que les scripts assemblés seront wrappés (POST vers `/api/v1/script-execution-logs`)
**And** la commande retourne `Command::SUCCESS` (0)

**AC1.2 — Commande `winscript-logs:disable`**
**Given** un environnement où `SAMBAEDU_SCRIPTS_LOGGING_ENABLED=true`
**When** l'admin exécute `php artisan winscript-logs:disable`
**Then** la variable `SAMBAEDU_SCRIPTS_LOGGING_ENABLED` est positionnée à `false` dans le `.env`
**And** le cache de configuration est invalidé si présent
**And** la commande affiche un message de confirmation (FR) indiquant retour au comportement iso-legacy (scripts non wrappés, parité bytes)
**And** retourne `Command::SUCCESS`

**AC1.3 — Commande `winscript-logs:status` (lecture seule)**
**Given** n'importe quel état du flag
**When** l'admin exécute `php artisan winscript-logs:status`
**Then** la commande affiche l'état courant **effectif** lu via `config('sambaedu.scripts.logging.enabled', false)` (activé / désactivé) sans modifier aucun fichier
**And** affiche l'URL d'ingestion résolue (`route('scriptsos.logs.ingest')` si disponible, sinon le fallback documenté) pour aider l'admin à vérifier la cible
**And** retourne `Command::SUCCESS`

### Volet 2 — Persistance & cohérence avec le pipeline 17.2 (D1, D3, D4)

**AC2.1 — Idempotence des commandes**
**Given** `SAMBAEDU_SCRIPTS_LOGGING_ENABLED=true` déjà présent dans `.env`
**When** `winscript-logs:enable` est ré-exécuté
**Then** le `.env` reste valide (pas de duplication de la ligne, pas de ligne orpheline), la valeur reste `true`
**And** la commande signale (info) que le logging était déjà activé (pas d'erreur)
**And** symétriquement pour `disable` quand le flag est déjà `false`

**AC2.2 — Écriture `.env` non destructive**
**Given** un `.env` contenant d'autres variables (ex. `APP_KEY`, `DB_*`, `SAMBAEDU_GLPI_URL`)
**When** une commande enable/disable modifie `SAMBAEDU_SCRIPTS_LOGGING_ENABLED`
**Then** seule la ligne `SAMBAEDU_SCRIPTS_LOGGING_ENABLED` est ajoutée/modifiée
**And** toutes les autres lignes (variables, commentaires, lignes vides) sont préservées byte-pour-byte
**And** si la variable est ajoutée (absente au départ), elle est appendée à la fin du fichier avec une nouvelle ligne propre (pas de concaténation sur la dernière ligne existante)

**AC2.3 — Le flag pilote effectivement le wrapper 17.2 (cohérence bout-en-bout)**
**Given** le pipeline d'assemblage livré par 17.2 (`ApplicationScriptsAssembler::assemble()`)
**When** `config('sambaedu.scripts.logging.enabled')` est `true`
**Then** les interpréteurs `cmd` et `bash` sont wrappés via `WrapperScriptRenderer::wrap(..., source: ScriptExecutionSource::GPO_APPLICATIONS)` (comportement déjà livré 17.2 — la story le **vérifie**, ne le réécrit pas)
**And** quand le flag est `false`, la sortie est strictement iso-legacy (parité bytes) — vérifié par un test qui interroge la config réellement
**And** la clé de config lue par la commande est **exactement** celle lue par l'Assembler : `sambaedu.scripts.logging.enabled` (zéro divergence de nommage)

### Volet 3 — Tests Feature & non-régression (D5)

**AC3.1 — Tests Feature des commandes artisan**
**Given** `tests/Feature/ScriptsOs/WinscriptLogsCommandsTest.php` (nouveau)
**When** la suite est exécutée
**Then** elle couvre au minimum :
- `winscript-logs:enable` écrit `SAMBAEDU_SCRIPTS_LOGGING_ENABLED=true` dans un `.env` de test temporaire (fixture isolée — ne JAMAIS toucher le `.env` réel du repo)
- `winscript-logs:disable` écrit `=false`
- idempotence (double enable → 1 seule ligne)
- préservation des autres lignes (AC2.2)
- `winscript-logs:status` affiche l'état courant sans écrire (assertion sur la sortie console via `expectsOutput`/`assertSuccessful`)
**And** les tests utilisent un `.env` de fixture (chemin temporaire `sys_get_temp_dir()` ou `storage_path('framework/testing/...')`) et restaurent l'état initial en `tearDown` — **interdiction d'écrire dans le `.env` du projet**

**AC3.2 — Test Feature d'effet config sur le pipeline**
**Given** un test qui force `config(['sambaedu.scripts.logging.enabled' => true])` puis appelle `assemble()`
**When** le flag est ON vs OFF
**Then** la sortie est wrappée (flag ON) vs iso-legacy (flag OFF) — ce comportement est déjà couvert par `ApplicationsScriptsWrapperIntegrationTest` (17.2). **Le dev confirme que cette suite passe toujours (non-régression) et NE la duplique PAS.** Si une assertion supplémentaire de bout-en-bout est jugée utile, l'ajouter dans la suite 17.5 sans toucher la suite 17.2.

**AC3.3 — Non-régression suites Gpo + ScriptsOs**
**Given** les suites existantes `tests/Feature/Gpo/ApplicationsScriptsWrapperIntegrationTest.php`, `tests/Unit/Gpo/ApplicationScriptsAssemblerTest.php`, `tests/Feature/Gpo/ApplicationsScriptsByteParityTest.php`, et les tests d'ingestion ScriptsOs 16.12
**When** la suite complète est exécutée après livraison 17.5
**Then** aucune régression (0 failure nouveau) — les fichiers de l'Assembler et de la config NE sont PAS modifiés par 17.5 (sauf `.env.example` documentaire si nécessaire)

## Tasks / Subtasks

- [x] **T1 — Reconnaissance (lecture, AUCUNE modif) — bloque tout le reste**
  - [x] T1.1 Lire `app/Gpo/Services/ApplicationScriptsAssembler.php:84-87` (constructeur DI), `:215-221` (appel `wrapInterpreters`), `:904-973` (`wrapInterpreters()` + `mapAction()`). Confirmer que le pipeline lit `config('sambaedu.scripts.logging.enabled', false)`. **Ne rien y changer.**
  - [x] T1.2 Lire `config/sambaedu.php:13-27` — confirmer la clé `scripts.logging.enabled` ← `env('SAMBAEDU_SCRIPTS_LOGGING_ENABLED', false)`. C'est la SEULE source de vérité du flag.
  - [x] T1.3 Lire un modèle de commande existant : `app/Console/Commands/WpkgCacheFlushCommand.php` (signature `final class`, `protected $signature/$description`, `handle(): int`, `Command::SUCCESS`, `$this->info()`, `Log::channel(...)`). Reproduire ce style.
  - [x] T1.4 Confirmer le mécanisme d'auto-chargement des commandes : `app/Console/Kernel.php:138-143` fait `$this->load(__DIR__.'/Commands')` → **aucun enregistrement manuel requis** ; il suffit de poser le fichier dans `app/Console/Commands/`.

- [x] **T2 — Helper d'écriture `.env` (D2, AC2.1, AC2.2)**
  - [x] T2.1 Implémenter une méthode privée (dans la classe commande ou un petit trait/service partagé) qui : lit `base_path('.env')` ; si la ligne `^SAMBAEDU_SCRIPTS_LOGGING_ENABLED=` existe → la remplace via `preg_replace` ancré ligne par ligne ; sinon → append `\nSAMBAEDU_SCRIPTS_LOGGING_ENABLED={true|false}\n` proprement (gérer le cas où le fichier ne finit pas par `\n`). → trait `App\Console\Commands\Concerns\ManagesScriptLoggingFlag`.
  - [x] T2.2 Écrire le fichier de façon non destructive (lecture complète → transformation string → réécriture). Ne pas réordonner les autres lignes. Utilise `Illuminate\Support\Facades\File` (cohérent style projet `ClearAllCaches`).
  - [x] T2.3 Permettre l'injection du chemin `.env` (propriété `$envPathOverride` + setter `setEnvPath()`) pour que les tests pointent vers un `.env` temporaire — **jamais le `.env` réel**.
  - [x] T2.4 Après écriture, invalider le cache config : `Artisan::call('config:clear')` best-effort (try/catch + log) ; détecte si `bootstrap/cache/config.php` était présent pour avertir l'opérateur (D4). Ne relance PAS `config:cache`.

- [x] **T3 — Commande `winscript-logs:enable` (AC1.1)**
  - [x] T3.1 Créer `app/Console/Commands/WinscriptLogsEnableCommand.php` : `protected $signature = 'winscript-logs:enable';`, description FR.
  - [x] T3.2 `handle()` : écrire `true` via le helper T2 ; si déjà `true` (lecture `config()` avant écriture) → message info "déjà activé" mais réécrire idempotent (AC2.1) ; sinon confirmation activation. FAILURE si `.env` introuvable.
  - [x] T3.3 Message FR : rappelle que les scripts `cmd`/`bash` assemblés seront désormais wrappés et POSTeront vers `/api/v1/script-execution-logs` (16.12). `Log::channel('scriptsos')->info('winscript-logs.enabled', [...])`.
  - [x] T3.4 `return Command::SUCCESS;`

- [x] **T4 — Commande `winscript-logs:disable` (AC1.2, symétrique T3)**
  - [x] T4.1 Créer `app/Console/Commands/WinscriptLogsDisableCommand.php`, signature `winscript-logs:disable`.
  - [x] T4.2 Écrire `false` ; message FR retour iso-legacy (parité bytes) ; log channel `scriptsos`.

- [x] **T5 — Commande `winscript-logs:status` (AC1.3, lecture seule)**
  - [x] T5.1 Créer `app/Console/Commands/WinscriptLogsStatusCommand.php`, signature `winscript-logs:status`.
  - [x] T5.2 Lire `config('sambaedu.scripts.logging.enabled', false)` → afficher "ACTIVÉ"/"DÉSACTIVÉ" (FR). Aucune écriture fichier.
  - [x] T5.3 Afficher l'URL d'ingestion via `resolveIngestUrl()` (try `route('scriptsos.logs.ingest', [], true)` / fallback `config('app.url').'/api/v1/script-execution-logs'`) — même résolution que `WrapperScriptRenderer::resolveEndpointUrl()`.

- [x] **T6 — Tests Feature commandes (AC3.1)**
  - [x] T6.1 Créer `tests/Feature/ScriptsOs/WinscriptLogsCommandsTest.php` (`extends Tests\TestCase`, attributs `#[Test]` PHPUnit 11 — pattern iso suites existantes).
  - [x] T6.2 Setup : génère un chemin `.env` de fixture unique dans `sys_get_temp_dir()`, fait pointer la commande dessus via `setEnvPath()` (T2.3). `tearDown` : `unlink` du fichier temporaire.
  - [x] T6.3 Tests : `enable_sets_flag_true`, `disable_sets_flag_false`, `enable_appends_flag_when_absent_with_clean_newline`, `enable_is_idempotent` (double appel → 1 ligne, `preg_match_all`), `disable_is_idempotent`, `preserves_other_env_lines` (AC2.2), `enable_fails_when_env_file_missing`, `status_does_not_write_and_reports_enabled_state` (mtime + contenu inchangés), `status_reports_disabled_state`.
  - [x] T6.4 Lancer `php artisan test --filter WinscriptLogsCommands` → 9 PASS (sur VM).

- [x] **T7 — Non-régression & finalisation (AC3.2, AC3.3)**
  - [x] T7.1 Lancer `php artisan test --filter ApplicationsScriptsWrapperIntegration` → 8 PASS (pipeline 17.2 intact).
  - [x] T7.2 Lancer la suite Gpo + ScriptsOs ciblée (`tests/Feature/Gpo tests/Unit/Gpo tests/Feature/ScriptsOs`) → 476 passed, 87 skipped (parité bytes `requires-fixture-capture` hors VM — attendu), 3 risky (préexistants, hors 17.5), 0 failure.
  - [x] T7.3 `php -l` sur les 3 nouveaux fichiers commande + le trait + le fichier de test → 0 erreur.
  - [x] T7.4 (Documentaire) `.env.example` contient déjà `SAMBAEDU_SCRIPTS_LOGGING_ENABLED=false` (ligne 181, livré 17.2) — vérifié, non modifié.
  - [x] T7.5 Renseigner File List, Completion Notes, Debug Log References.

## Dev Notes

### Décisions SM (à respecter — toutes tranchées)

| # | Décision | Justification |
|---|----------|---------------|
| **D1** | **Persistance via `.env` (`SAMBAEDU_SCRIPTS_LOGGING_ENABLED`)**, PAS via `SystemSetting` DB | L'Assembler 17.2 (done, hors scope modif) lit `config('sambaedu.scripts.logging.enabled')` qui résout `env('SAMBAEDU_SCRIPTS_LOGGING_ENABLED', false)` (`config/sambaedu.php:25`). Pour basculer le flag SANS modifier le code applicatif 17.2, la seule source à muter est le `.env`. Utiliser `SystemSetting` imposerait de réécrire la lecture du flag dans l'Assembler → rouvrirait le scope 17.2 (interdit). |
| **D2** | Helper d'écriture `.env` **non destructif** + ancrage ligne par ligne (`preg_replace('/^SAMBAEDU_SCRIPTS_LOGGING_ENABLED=.*$/m', ...)`) | Évite la duplication de variable et préserve le reste du fichier (AC2.2). Pattern classique Laravel `key:generate`-like. |
| **D3** | Le flag a effet **à la prochaine lecture de config** par un nouveau process PHP-FPM (ou après `config:clear`). Pas de hot-reload du process en cours. | Comportement standard Laravel/env. La commande best-effort `config:clear` aide en dev/host ; en prod l'opérateur peut devoir relancer `php artisan config:cache` (cf. D4). À documenter dans le message de sortie. |
| **D4** | Si la config est **cachée** (`bootstrap/cache/config.php` présent — cas prod/VM via `config:cache`), `config:clear` la supprime → la valeur `.env` redevient lue. La commande NE relance PAS `config:cache` automatiquement (laisse l'opérateur décider, iso pattern projet). Mentionner dans le message si un cache config était présent. | Éviter d'imposer un re-cache lourd ; rester prévisible. Pattern observé : `ClearAllCaches` propose `--optimize` mais ne force pas. |
| **D5** | Tests commandes sur **`.env` de fixture temporaire** uniquement — jamais le `.env` réel | Sécurité CI/host : un test qui écrit le `.env` du repo corromprait l'environnement de dev. Injection du chemin (T2.3). |
| **D6** | 3 commandes (`enable`/`disable`/`status`), pas une seule commande paramétrée | Lisibilité opérateur + cadrage epics.md qui nomme explicitement `winscript-logs:enable` / `winscript-logs:disable`. `status` ajouté par confort (lecture seule, faible coût) — confirmer avec Henri si jugé hors scope (cf. Questions). |

### Le pipeline wrapper est DÉJÀ livré (17.2) — ancrage précis

**Code à NE PAS modifier**, fourni pour compréhension uniquement :

`app/Gpo/Services/ApplicationScriptsAssembler.php`
```php
// ligne 218 — déjà présent (17.2)
$texts = $this->wrapInterpreters($texts, $info);

// lignes 921-951 — déjà présent (17.2)
private function wrapInterpreters(array $texts, array $info): array
{
    if (! config('sambaedu.scripts.logging.enabled', false)) {
        return $texts;                       // ← flag OFF = iso-legacy
    }
    $action = $this->mapAction($info['action'] ?? 'startup');
    $renderer = $this->wrapper ?? app(WrapperScriptRenderer::class);
    $osMap = ['cmd' => ScriptExecutionOs::WINDOWS, 'bash' => ScriptExecutionOs::LINUX];
    foreach ($osMap as $interp => $os) {
        if (isset($texts[$interp]) && $texts[$interp] !== '') {
            $texts[$interp] = $renderer->wrap(
                $texts[$interp], $action, $os, null,
                ScriptExecutionSource::GPO_APPLICATIONS,
            );
        }
    }
    return $texts;
}
```

`config/sambaedu.php:23-27` (déjà présent — clé canonique)
```php
'scripts' => [
    'logging' => [
        'enabled' => (bool) env('SAMBAEDU_SCRIPTS_LOGGING_ENABLED', false),
    ],
],
```

### Infra logs livrée par 16.12 (done) — ne rien recréer

- **Service** : `App\ScriptsOs\Services\WrapperScriptRenderer` (`app/ScriptsOs/Services/WrapperScriptRenderer.php`) — méthode `wrap(string $scriptContent, ScriptExecutionAction $action, ScriptExecutionOs $os, ?int $scriptId = null, ScriptExecutionSource $source = ...)`. Cache statique de template (`clearCache()` pour tests). Résout l'URL via `route('scriptsos.logs.ingest', [], true)` (fallback `config('app.url').'/api/v1/script-execution-logs'`).
- **Templates** : `resources/views/auth/v1/wrapper-cmd.blade.php`, `resources/views/auth/v1/wrapper-sh.blade.php`.
- **Enums** : `App\ScriptsOs\Enums\ScriptExecution{Action,Os,Source,Status}`. `ScriptExecutionSource::GPO_APPLICATIONS = 'gpo_applications'`.
- **Endpoint d'ingestion** : `POST /api/v1/script-execution-logs`, route name `scriptsos.logs.ingest`, middleware `auth.v1.workstation` (JWT) + `throttle:60,1` (`routes/api.php:213-221`). Controller `ScriptsOsIngestionController::store`.
- **Modèle / table** : `App\ScriptsOs\Models\ScriptExecutionLog` (table `script_execution_logs`). Job d'archivage `script-logs:archive:rotate` planifié 04:00 (`Kernel.php:119-123`).
- **UI consultation** : `/admin/settings/scripts-logs` (livrée 16.12).

### Modèle de commande à décalquer (style projet)

Voir `app/Console/Commands/WpkgCacheFlushCommand.php` :
- `final class WinscriptLogsEnableCommand extends Command`
- `protected $signature` / `protected $description` en FR
- `public function handle(): int`
- Retours `Command::SUCCESS` / `Command::FAILURE`
- `$this->info()`, `$this->warn()`, `$this->newLine()`
- `Log::channel('scriptsos')->info(...)` (channel déjà utilisé par `WrapperScriptRenderer`)

Auto-chargement : `app/Console/Kernel.php` ligne 140 `$this->load(__DIR__.'/Commands')` — poser le fichier suffit, AUCUN enregistrement manuel ni édition de `routes/console.php`.

### Standards de test

- Runner : PHPUnit 11 (Laravel 12, PHP 8.2+). Les suites Gpo/ScriptsOs utilisent l'attribut `#[Test]` (`use PHPUnit\Framework\Attributes\Test;`) + `extends Tests\TestCase`. Reproduire ce style (PAS Pest functions).
- Pour tester les commandes : `$this->artisan('winscript-logs:enable')->assertSuccessful();` + `expectsOutput`/`expectsOutputToContain`.
- **Isolation `.env`** : injecter un chemin de fixture (propriété/paramètre surchargeable de la commande). Créer le fixture dans `sys_get_temp_dir()` au `setUp`, le supprimer au `tearDown`. Ne JAMAIS écrire `base_path('.env')` réel pendant les tests.
- Mockery déjà disponible (`Mockery::close()` en `tearDown` — cf. `ApplicationsScriptsWrapperIntegrationTest`).

### Project Structure Notes

- Commandes : `app/Console/Commands/WinscriptLogs{Enable,Disable,Status}Command.php` (PSR-4 `App\Console\Commands\`).
- Tests : `tests/Feature/ScriptsOs/WinscriptLogsCommandsTest.php` (le namespace `Tests\Feature\ScriptsOs` est cohérent avec le domaine `App\ScriptsOs`). Le dossier `tests/Feature/ScriptsOs/` **existe déjà** (suites d'ingestion 16.12).
- Le nommage `winscript-logs:*` est imposé par epics.md (Story 17.5) et sprint-status. Conserver tel quel même si le pipeline couvre aussi Linux (`bash`) — c'est le nom retenu côté produit.

### Frontières (anti-scope creep)

| HORS scope 17.5 | Renvoi |
|---|---|
| Réimplémenter `wrapInterpreters()` / `mapAction()` / le branchement `assemble()` | Déjà livré 17.2 (done) — interdit de modifier |
| Modifier `config/sambaedu.php` (clé `scripts.logging.enabled` déjà déclarée) | Déjà livré 17.2 |
| Créer table / endpoint / service / templates / enums de logs | Déjà livré 16.12 (done) |
| UI de consultation des logs | Déjà livré 16.12 (`/admin/settings/scripts-logs`) |
| Modifier le contenu des fragments scripts upstream | Versionné par le paquet Debian `sambaedu` |
| Tests parité bytes runtime des 5 scripts | Livré 17.4 (done) |
| Persister le flag en DB (`SystemSetting`) | Écarté D1 — `.env` est la source lue par l'Assembler |
| Auth/sécurisation de l'endpoint d'ingestion | Livré 16.12 (JWT `auth.v1.workstation`) |

### References

- Cadrage epic : `_bmad-output/planning-artifacts/epics.md#Story 17.5` (ligne ~3467) + Epic 17 (ligne 3423).
- Audit logging : `_bmad-output/planning-artifacts/audit-applications-scripts.md#Section G.4` (ligne 1190, périmètre post-16.12) + `#Section I` (ligne 1335, état des lieux — **note** : le contrat `/api/v1/winscripts/log` et le modèle `WinscriptLog` décrits Section I sont une recommandation pré-16.12 **caduque** ; l'infra réelle livrée est `/api/v1/script-execution-logs` + `ScriptExecutionLog`).
- Story prérequis 17.2 (pipeline wrapper) : `_bmad-output/implementation-artifacts/17-2-portage-moteur-applications-php-whitelist-etendue.md` — D5 (ligne 72), AC3.1-3.3 (ligne 226), File List (ligne 517), Fix #3 flag config (ligne 553).
- Story prérequis 16.12 (infra logs) : `_bmad-output/implementation-artifacts/16-12-logs-execution-centralises-ui-consultation.md`.
- Story sœur 17.4 (tests runtime, pattern parité) : `_bmad-output/implementation-artifacts/17-4-tests-integration-runtime-vm.md` — trait `tests/Concerns/AssertsScriptParity.php`.
- Code : `app/Gpo/Services/ApplicationScriptsAssembler.php` (pipeline), `app/ScriptsOs/Services/WrapperScriptRenderer.php` (service), `config/sambaedu.php:23-27` (flag), `app/Console/Commands/WpkgCacheFlushCommand.php` (modèle commande), `app/Console/Kernel.php:138-143` (auto-load), `tests/Feature/Gpo/ApplicationsScriptsWrapperIntegrationTest.php` (suite à ne pas dupliquer), `routes/api.php:213-221` (endpoint ingest).

## Previous Story Intelligence

Extrait des stories 17.2 (done) et 17.4 (done) — directement actionnable :

- **Le scope nominal de 17.5 a été partiellement absorbé par 17.2** (Fix #3 post-review 17.2 : déclaration du flag config + `.env.example`). La valeur résiduelle de 17.5 = l'**outillage opérateur** (commandes artisan) + sa persistance. Ne pas se laisser surprendre en trouvant déjà `wrapInterpreters()` et la config en place : c'est attendu.
- **Bug OS résolu en 17.2 (Fix #1)** : `wrapInterpreters()` dérive l'OS de l'interpréteur (`cmd→WINDOWS`, `bash→LINUX`), PAS de `$info['os']` (le header bash est généré même en contexte Windows). À connaître pour comprendre `ApplicationsScriptsWrapperIntegrationTest::it_wraps_bash_with_linux_when_info_os_is_windows`. Ne pas "corriger" ce comportement.
- **`localAdminScripts()` retourne `[]` (pas `['']`) quand l'utilisateur n'a pas les droits admin** (17.2 Fix #5) — quirk de parité bytes legacy. Ne pas toucher.
- **Channel de log `scriptsos`** : utilisé par `WrapperScriptRenderer` (`Log::channel('scriptsos')->debug(...)`). Réutiliser pour les commandes (cohérence observabilité).
- **Tests parité bytes skipent hors VM** (`requires-fixture-capture`) : c'est normal, pas une régression (17.2/17.4). La suite 17.5 n'a aucune dépendance VM — elle doit passer 100% sur host/CI.
- **Alerte inotify (17.4 + memory projet)** : après `git rm`/`trash`, des fichiers fantômes restent sur la VM. 17.5 ne supprime aucun fichier → non concerné, mais ne pas tenter de cleanup SSH.
- **Modèle dev 17.2 et 17.4 = sonnet** (livraisons propres sur ce niveau de complexité).

## Git Intelligence Summary

Commits récents pertinents (branche `main`) :
- `689aff7 test(story-17.4)` — tests intégration runtime + snapshot parité portable CI (trait `AssertsScriptParity`).
- `4d206ca feat(story-3.8)` — installation Windows post-OOBE (hors scope).
- 17.2 mergé (done 2026-05-21) — `ApplicationScriptsAssembler` + `wrapInterpreters()` + config flag + 6 tests wrapper.

Pattern de commande observé dans `app/Console/Commands/` : classes `final`, signature `domaine:verbe`, FR, `handle(): int`, retours `Command::SUCCESS/FAILURE`, log par channel dédié. Le projet n'a aucune commande qui écrit déjà le `.env` → le helper d'écriture (T2) est une nouveauté ; s'inspirer du pattern Laravel `KeyGenerateCommand` (regex ancrée `/^KEY=/m`) sans le réimporter.

## Project Context Reference

- Laravel 12 / PHP 8.2+ / PHPUnit 11. PHP-FPM tourne sous user `www-admin` (uid 599) — tout fichier écrit/lu par PHP doit rester accessible à `www-admin` (le `.env` l'est déjà).
- Communication & docs en français (config BMAD : `communication_language=French`, `document_output_language=French`).
- SE4 = legacy PHP, SE5 = sambaedu-reload Laravel.
- **NE PAS interagir avec la VM/SSH** pour cette story : aucune dépendance runtime VM (tests 100% host/CI). Le dev qui implémente sur le repo de code peut être en worktree → interdiction VM (memory projet).
- Sécurité : ne jamais `rm -rf` ; `trash` pour supprimer (non pertinent ici, aucune suppression).

## Questions ouvertes (pour Henri — non bloquantes, défauts SM appliqués)

1. **`winscript-logs:status`** : ajoutée par confort opérateur (lecture seule, coût quasi nul). Si jugée hors scope strict epics.md (qui ne nomme que `enable`/`disable`), la retirer — défaut SM : la garder.
2. **`config:cache` post-bascule** (D4) : la commande fait `config:clear` best-effort mais ne relance PAS `config:cache`. Sur la VM en prod, si la config est cachée, l'opérateur devra `php artisan config:cache` pour figer. Confirmer que ce comportement (clear sans re-cache) convient, ou demander un flag `--optimize` iso `sambaedu:clear-cache`.
3. **Persistance `.env` vs futur onglet UI Settings** : 17.5 livre l'outillage CLI. Si à terme un toggle UI est souhaité (iso quota trash via `SystemSetting`), il faudrait alors un refactor de la lecture du flag dans l'Assembler (basculer `config()` → `SystemSetting` avec fallback) — **hors scope 17.5**, à ticketiser Phase 3 si besoin terrain.

## Dev Agent Record

### Agent Model Used

claude-opus-4-7[1m] (Opus 4.7, 1M context) — DEV agent BMAD.

### Debug Log References

Tests exécutés sur la VM (`/vm` — `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`, projet `/var/www/sambaedu-reload`), fichiers auto-synchronisés via inotify (présence vérifiée, chown `www-admin` OK) :

- `php artisan test --filter WinscriptLogsCommands` → **9 passed (26 assertions)**, 0 failure.
- `php artisan test --filter ApplicationsScriptsWrapperIntegration` (non-régression pipeline 17.2) → **8 passed (13 assertions)**, 0 failure.
- `php artisan test tests/Feature/Gpo tests/Unit/Gpo tests/Feature/ScriptsOs` (non-régression ciblée) → **476 passed, 87 skipped, 3 risky, 0 failure** (1265 assertions). Les 87 skipped = tests de parité bytes `requires-fixture-capture` (hors VM, comportement attendu). Les 3 risky sont préexistants et hors périmètre 17.5 (suites Gpo).
- `php -l` sur les 3 commandes + le trait + le fichier de test → **0 erreur de syntaxe** (host + VM).

### Completion Notes List

- **Périmètre strictement respecté** : aucune modification de `ApplicationScriptsAssembler.php`, `config/sambaedu.php`, l'infra 16.12, ni les suites de tests 17.2. Le pipeline wrapper + le flag config étaient déjà livrés (confirmé en T1) — 17.5 n'ajoute que la couche d'activation opérateur.
- **3 commandes artisan** créées (auto-chargées via `Kernel::load(__DIR__.'/Commands')`, aucun enregistrement manuel) : `winscript-logs:enable`, `winscript-logs:disable`, `winscript-logs:status`. Style décalqué de `WpkgCacheFlushCommand` (classe `final`, `$signature`/`$description` FR, `handle(): int`, `Command::SUCCESS/FAILURE`, `Log::channel('scriptsos')`).
- **Mutualisation** : le helper d'écriture `.env` non destructif + la lecture du flag config + la résolution d'URL d'ingestion + le `config:clear` best-effort sont factorisés dans le trait `App\Console\Commands\Concerns\ManagesScriptLoggingFlag` (évite la duplication enable/disable/status). Décision technique : un trait plutôt que la duplication dans chaque commande, cohérent avec `app/Models/Concerns` et `app/Services/Concerns` existants.
- **Écriture `.env` non destructive (D2)** : regex ancrée `/^SAMBAEDU_SCRIPTS_LOGGING_ENABLED=.*$/m` via `preg_replace` (limite 1 remplacement) si la variable existe, sinon append propre avec gestion du `\n` final manquant. Les autres lignes/commentaires/lignes vides sont préservés byte-pour-byte (vérifié par `preserves_other_env_lines`).
- **Chemin `.env` injectable (D5)** : propriété `$envPathOverride` + `setEnvPath()`. Les tests instancient la commande via le container, injectent un `.env` de fixture unique dans `sys_get_temp_dir()` (jamais le `.env` réel), et l'exécutent via `run(ArrayInput, NullOutput)` — `$this->artisan()` ne permettant pas d'injecter le chemin avant exécution. Le test `status` (lecture seule via config) utilise `$this->artisan()` + `expectsOutputToContain`.
- **Cache config (D3/D4)** : `config:clear` best-effort (try/catch + log warning sur échec). La commande détecte si `bootstrap/cache/config.php` était présent et avertit l'opérateur de relancer `config:cache` en prod (ne le relance PAS automatiquement, iso pattern projet).
- **FAILURE explicite** si le `.env` est introuvable (couvert par `enable_fails_when_env_file_missing`) — évite une fausse confirmation d'activation.
- **Questions ouvertes SM** : `winscript-logs:status` conservée (défaut SM, lecture seule). `config:cache` non relancé automatiquement (D4 appliqué tel quel). Persistance `.env` (pas `SystemSetting`) conforme D1.
- **Doc QA** : domaine `gpo` (le plus proche — pipeline `App\Gpo`, scripts d'applications déjà couverts par 16.1+). Aucun domaine `scripts`/`applications`/`scripts-os` existant ; enrichissement append-only de `docs/qa/domains/gpo.md` (nouvelle section « Story 17.5 — Bascule opérateur du logging centralisé des scripts ») — pas de fichier par story. README QA inchangé (`gpo` déjà listé dans « Domaines couverts »).

### Corrections Post-Review (2026-05-25)

Review sonnet + second avis opus (cf. `_bmad-output/codeReviews/17-5.md`). 6 problèmes corrigés automatiquement, validés sur VM (`WinscriptLogsCommandsTest` **14 passed**, non-régression Gpo+ScriptsOs **481 passed, 0 failure**) :

- **#1 🔴** `ManagesScriptLoggingFlag` : ajout d'un garde-fou `if ($replaced === null) return false;` après `preg_replace` — un échec PCRE n'écrira plus jamais un `.env` vidé (le caller retourne `Command::FAILURE`).
- **#2 🟠** Pattern d'écriture `.env` basculé de `=.*$` vers `=[^\r\n]*` (sans ancre `$`) : le terminateur de ligne (`\n`/`\r\n`) n'est plus consommé → préservation byte-pour-byte y compris en CRLF (AC2.2). Test `preserves_crlf_line_endings_on_replacement` ajouté.
- **#4 🟡** Test `status_does_not_write` (assertions mtime tautologiques) → simplifié en `status_reports_enabled_state` (assertion de sortie réelle).
- **#5 🟡** `@unlink` → `unlink` dans `tearDown` (plus de masquage d'erreur).
- **#6 🟡** Ajout `preserves_other_lines_byte_for_byte_on_replacement` (branche remplacement + invariance du nombre de lignes).
- **S3 🟡** (manqué par la 1ʳᵉ review, trouvé par opus) : `runCommandWithFixture` capture la sortie via `BufferedOutput` ; 3 tests de messages ajoutés (`enable_reports_activation_message_with_ingest_url`, `enable_reports_idempotent_message_when_already_enabled`, `disable_reports_iso_legacy_message`).

**Non corrigés (volontaire)** : #3/S2 (formulation cosmétique des messages "déjà activé" basés sur `config()`) et #7 (atomicité `File::put` — le fix write-tmp-rename casserait la préservation de l'ownership `www-admin`, opus déconseille). Cf. doc review. Défauts conservés au commit sur main.

### File List

**Créés :**
- `app/Console/Commands/Concerns/ManagesScriptLoggingFlag.php` (trait — helper `.env` non destructif, lecture flag config, `config:clear` best-effort, résolution URL ingestion, chemin injectable)
- `app/Console/Commands/WinscriptLogsEnableCommand.php` (`winscript-logs:enable`)
- `app/Console/Commands/WinscriptLogsDisableCommand.php` (`winscript-logs:disable`)
- `app/Console/Commands/WinscriptLogsStatusCommand.php` (`winscript-logs:status`, lecture seule)
- `tests/Feature/ScriptsOs/WinscriptLogsCommandsTest.php` (9 tests Feature, fixture `.env` isolée)

**Modifiés :**
- `docs/qa/domains/gpo.md` (append-only — section « Story 17.5 » : scénarios 17.5-1 à 17.5-4 + checklist rapide 17.5)
- `_bmad-output/implementation-artifacts/17-5-integration-wrapper-logs-pipeline.md` (checkboxes Tasks, Dev Agent Record, Status → review)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (17-5 : ready-for-dev → review + last_updated)

**Vérifiés (non modifiés) :**
- `app/Gpo/Services/ApplicationScriptsAssembler.php`, `config/sambaedu.php`, `.env.example` (flag ligne 181 déjà présent — livré 17.2)

## Recommandation Modèle Dev

**Recommandation : `sonnet`.**

### Justification

Story de **faible complexité, périmètre net et borné** :
- 3 commandes artisan (pattern strictement décalqué de `WpkgCacheFlushCommand`, déjà documenté ligne-par-ligne) + 1 helper d'écriture `.env` (regex ancrée classique type `KeyGenerateCommand`) + 1 fichier de tests Feature.
- **Aucune décision d'architecture ouverte** : toutes tranchées (D1-D6), notamment le choix `.env` vs `SystemSetting`.
- **Aucune nouvelle modélisation Eloquent, aucun endpoint, aucune table, aucune librairie tierce** — toute l'infra (16.12) et le pipeline (17.2) sont déjà livrés et **interdits de modification**.
- Le risque principal est un **scope creep** (réimplémenter le wrapper déjà livré) — neutralisé par le bandeau de cadrage + le tableau de frontières. Sonnet suit bien des frontières explicites.
- Charge ~1j alignée sprint-status. Sonnet a livré 17.2 et 17.4 (complexité supérieure) sans dérive.

**Pas opus** : pas d'algorithme, pas de sécurité fine (l'endpoint est déjà JWT-protégé par 16.12), pas d'intégration complexe — l'intégration est déjà faite, 17.5 n'en est que l'interrupteur opérateur. Le seul point de vigilance (isolation `.env` en test, écriture non destructive) est explicitement scripté dans les tâches T2/T6.
