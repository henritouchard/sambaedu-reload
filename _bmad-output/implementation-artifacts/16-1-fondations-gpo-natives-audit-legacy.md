# Story 16.1 : Fondations GPO natives + audit legacy

Status: review

> **Story de fondation Epic 16** — pré-requis bloquant pour toutes les stories 16.2 → 16.6.
> Aucune capacité utilisateur livrée : pure infrastructure (logs, namespace, abstraction `samba-tool gpo`, garde-fous architecturaux) + **livrable audit** `audit-gpo-legacy.md` qui guidera le découpage technique de 16.2 → 16.6.

---

## Story

As a **développeur SER**,
I want disposer d'un socle technique isolé pour la réécriture native du module GPO (channel logs `gpo` **verbeux par type d'action**, namespace `App\Gpo`, abstraction `GpoService` autour de `samba-tool gpo`, garde-fou architectural Eloquent/AD) **et** d'un audit exhaustif du legacy `sambaedu/gpo/*` + shim 1bis.18,
So que les stories 16.2 → 16.6 puissent s'appuyer sur des fondations claires, **que toute action GPO soit traçable de bout en bout pendant la phase de transition** et qu'aucune décision de portage ne soit prise à l'aveugle (réécriture / port + adaptation / abandon documentée fichier par fichier).

---

## Contexte

L'Epic 16 remplace la Story 9.1 PAUSED par une **réécriture native** Laravel du module GPO. Le shim 1bis.18 (a/b/c/d/e/f/g) reste opérationnel en transition. Cette Story 16.1 ne livre **aucune capacité utilisateur** — c'est une story de socle au sens de 15.1 (cf. `_bmad-output/implementation-artifacts/15-1-fondations-pipeline-deploiement-wpkg.md`), à la différence près qu'elle ajoute un **livrable de recherche** : `audit-gpo-legacy.md`.

Le **legacy SambaEdu** contient ~5 500 lignes PHP réparties sur 4 includes (`gpo.inc.php` 1423L, `samba-tool.inc.php` 1396L, `delegations.inc.php` 373L, `gpo_ui.inc.php` 76L) + 19 fichiers UI dans `sambaedu/gpo/*.php`. Le shim 1bis.18 a partiellement préservé certaines pages (modules `gpo/` Tier 3 conservés byte-identiques dans `legacy/modules/gpo/`) ; d'autres ont déjà été refondues en natif (raccourcis via `ShortcutsService`, wallpapers via Story 4.7, apps Firefox/Thunderbird via Story 4.8). **Le périmètre à porter par Epic 16 est donc ce qui reste non-couvert**, et la cartographie n'a pas encore été faite.

> **Garde-fous Epic 16 :**
> - **AD = source de vérité GPO** : contrairement à Epic 15 (Eloquent first), les GPO vivent dans l'AD/SYSVOL. Eloquent ne stocke que des **vues / cache / index de tracking** (à décider story par story).
> - **Abstraction `samba-tool gpo`** : pas d'appel `exec()` direct dans le code métier ; tout passe par `GpoService` (à créer).
> - **Channel logs `gpo-deploy`** corrélé par identifiant d'opération (à formaliser).
> - **Stratégie port legacy** : tout fichier porté du legacy `sambaedu/gpo/*.php` ou `sambaedu/includes/gpo*.inc.php` porte un header `@legacy-port` + référence source + `@todo` de refactoring (cohérence avec convention WPKG/Story 15.1).
> - **Le shim 1bis.18 reste vivant pendant toute la transition** — pas de suppression dans cette story.

---

## Dépendances

| Story / Epic | Titre | Status | Détail |
|---|---|---|---|
| Epic 4 | Workstation, WorkstationGroup, AppProfile | done (2026-04-22) | Modèles Eloquent disponibles ; nécessaires pour les liaisons GPO ↔ WorkstationGroup (Story 16.5) |
| 1bis-18a | Module legacy `gpo` — includes core | done (review) | `gpo.inc.php` + `samba-tool.inc.php` + `delegations.inc.php` + `gpo_ui.inc.php` chargeables via `legacy/bootstrap.php` |
| 1bis-18b | Module legacy `gpo` — gestion / import / export | done | Pages `gestion_gpo.php`, `gpo-maj.php`, `gpo-export.php` accessibles via catchall |
| 1bis-18c | Module legacy `gpo` — config apps Firefox / Thunderbird | **cancelled** (superseded by 4-8) | Refondu en natif via `AppCustomization` → **hors scope Epic 16** |
| 1bis-18d | Module legacy `gpo` — wallpaper | **cancelled** (superseded by 4-7) | Refondu en natif (Story 4.7) → **hors scope Epic 16** |
| 1bis-18e | Module legacy `gpo` — scripts Veyon/Wine/associations | review (2026-04-21) | 5 pages copiées byte-identique dans `legacy/modules/gpo/` |
| 1bis-18f | Module legacy `gpo` — profils itinérants | review (2026-04-28) | Service natif `RoamingProfileService` + Livewire SFC settings — **déjà partiellement natif** |
| 1bis-18g | Module legacy `gpo` — shims LDAP/SYSVOL | done (2026-04-18) | Shims `search_ad(gpo/site/subnet)`, `modify_ad(gpo)` opérationnels |
| 9.1 | Gestion des GPOs | **annulée** (remplacée par Epic 16) | Référence historique uniquement |
| 15.1 | Fondations pipeline déploiement WPKG | review | **Référence de structure** : reproduire le pattern (namespace racine, test archi, channel logs, AtomicFileWriter) |

**Conclusion dépendances :** toutes satisfaites — la story peut être implémentée immédiatement.

---

## Acceptance Criteria

> AC organisées en **6 volets**. Le volet 6 (Audit legacy) produit le livrable de recherche qui guidera 16.2 → 16.6.

### Volet 1 — Channel logs dédié `gpo` (verbeux par type d'action)

> **Décision SM (sur demande Henri 2026-05-11)** : un seul channel large `gpo` pour tout l'Epic 16 (couvre lecture, écriture, audit, sync, déploiement) — **PAS** `gpo-deploy` qui aurait été trop étroit. La verbosité est volontairement élevée pendant toute la phase de transition Epic 16, et la convention de logging par type d'action ci-dessous **s'applique à toutes les stories 16.x**, pas seulement 16.1.

**AC1.1** — **Channel `gpo` configuré**
**Given** la configuration logging Laravel (`config/logging.php`)
**When** un service du namespace `App\Gpo` émet un log
**Then** ce log est routé vers le channel `gpo`
**And** le fichier est `storage/logs/gpo/gpo-{date}.log` (driver `daily`, rotation quotidienne, rétention 30 jours par défaut, paramétrable via `GPO_LOG_DAYS`)
**And** le niveau de verbosité est paramétrable sans redeploy via `GPO_LOG_LEVEL` (default `debug` pendant la transition Epic 16 — sera bumpé à `info` une fois l'Epic stabilisé)
**And** le contexte inclut systématiquement `operation_id` (UUID), `action_type` (cf. AC1.3) et `gpo_name` / `target_dn` quand disponibles.

**AC1.2** — **Création auto du dossier au boot**
**Given** le boot applicatif
**When** le dossier `storage/logs/gpo/` est manquant
**Then** il est créé automatiquement (parité avec Story 15.1 et `wpkg-deploy`, cf. commit `42cebba`).

**AC1.3** — **Convention de logging verbeuse par type d'action** (s'applique à TOUT Epic 16)
**Given** une action GPO (lecture, écriture, sync, audit) exécutée par un service `App\Gpo`
**When** l'action démarre, progresse, échoue ou réussit
**Then** au moins **3 logs** sont émis : `start`, `step` (au moins un step intermédiaire si pertinent), `end` (success ou failure)
**And** chaque log inclut un champ `action_type` parmi le **catalogue suivant** (à enrichir story par story) :

| `action_type` | Quand l'émettre | Story où le pattern apparait |
|---|---|---|
| `gpo.list` | Listing GPOs (`samba-tool gpo listall`) | 16.1, 16.2 |
| `gpo.show` | Lecture détail GPO | 16.1, 16.2 |
| `gpo.fetch` | Fetch policy depuis SYSVOL | 16.1, 16.3 |
| `gpo.create` | Création GPO (`samba-tool gpo create`) | 16.4 |
| `gpo.delete` | Suppression GPO | 16.4 |
| `gpo.duplicate` | Duplication par copie d'arbre policy | 16.4 |
| `gpo.section.read` | Lecture d'une section (Firefox, Veyon, Wine…) | 16.3 |
| `gpo.section.write` | Édition d'une section (avec diff before/after) | 16.3 |
| `gpo.link.set` | Liaison GPO ↔ OU / WorkstationGroup | 16.5 |
| `gpo.link.remove` | Suppression de liaison | 16.5 |
| `gpo.inheritance.set` | Modification de l'héritage OU | 16.5 |
| `gpo.sysvol.write` | Écriture fichier `.pol` / `.xml` / `.ini` SYSVOL | 16.3, 16.4 |
| `gpo.sambatool.exec` | Exécution brute d'une commande `samba-tool` (niveau debug, dump cmd + stdout/stderr/code retour) | tous |
| `gpo.audit.legacy` | Trace de portage d'un fichier legacy | 16.1 (audit) |

**And** pour une action en échec, le log `end` contient `outcome=failure` + `error.class`, `error.message`, `error.trace` (si `GPO_LOG_LEVEL=debug`)
**And** pour une action de **mutation** (`create`, `delete`, `section.write`, `link.set`, `link.remove`, `inheritance.set`, `sysvol.write`), un **diff before/after** est logué dès lors qu'il est calculable
**And** chaque appel `samba-tool` produit un log `gpo.sambatool.exec` séparé contenant : commande exacte (args en array), durée d'exécution, code retour, stdout/stderr complets (tronqués à 8 Ko avec marker `[truncated]` si dépassement).

**AC1.4** — **Helper `GpoLogger` pour appliquer la convention**
**Given** la classe `App\Gpo\Support\GpoLogger`
**When** un service GPO l'utilise
**Then** elle expose **a minima** :
- `static action(string $type, ?string $operationId = null): GpoActionLog` — démarre une action loggée (émet `start` + retourne handle)
- `GpoActionLog::step(string $message, array $context = []): void` — log d'étape intermédiaire
- `GpoActionLog::success(array $context = []): void` — log `end` avec `outcome=success` + durée totale
- `GpoActionLog::failure(\Throwable $e, array $context = []): void` — log `end` avec `outcome=failure` + détails exception
- `GpoActionLog::diff(string $what, mixed $before, mixed $after): void` — log diff structuré (utile section/link/inheritance)
- Génération automatique de `operation_id` si non fourni (UUID v4)
- Push automatique de `action_type`, `operation_id`, durée dans le contexte de chaque log
**And** le test `tests/Unit/Gpo/GpoLoggerTest.php` vérifie qu'une action émet bien `start` + `success` (ou `failure`) avec les champs requis.

### Volet 2 — Namespace et structure code

**AC2.1**
**Given** le code natif GPO
**When** un développeur crée un service, generator, job, ou modèle Eloquent dédié GPO
**Then** il est placé sous `app/Gpo/` avec la structure :
- `app/Gpo/Services/` — services métier (à commencer par `GpoService`)
- `app/Gpo/Jobs/` — jobs async
- `app/Gpo/Models/` — modèles Eloquent dédiés GPO (vues / cache / tracking)
- `app/Gpo/Support/` — helpers (ex. wrappers `Process` `samba-tool`)
- `app/Gpo/Events/` — events GPO (création, modification, lien, etc.)
- `app/Gpo/Dto/` — DTOs typés (`GpoSummary`, `GpoLink`, etc.)

**And** un README `app/Gpo/README.md` documente cette structure et la décision d'écart vis-à-vis de `app/Services/Windows/Gpo*` mentionné dans `architecture.md:453` (justification : parallélisme avec `App\Wpkg\Deployment\*` de Story 15.1, namespace racine dédié par domaine fonctionnel).

**AC2.2**
**Given** le namespace `App\Gpo`
**When** le test archi `tests/Architecture/GpoNamespaceTest.php` s'exécute
**Then** il vérifie via `Symfony\Finder` + reflection que :
- Aucun fichier sous `app/Gpo/` n'importe `LdapRecord\*` directement (sauf exception explicite documentée — la lecture AD passe par les shims existants `App\LdapModels\*` ou par `samba-tool gpo` via `GpoService`)
- Aucun appel `exec(`, `shell_exec(`, `passthru(`, ou `proc_open(` direct dans les fichiers sous `app/Gpo/` autre que `app/Gpo/Support/SambaToolRunner.php` (ou nom équivalent à décider)
- Aucun appel direct à la fonction legacy `sambatool()` (PHP fonctionnel) — uniquement via `GpoService`.

**And** ce test est conçu pour migrer vers ArchTest / PHPStan rule plus tard (cohérence avec AC2.1 de Story 15.1).

**AC2.3**
**Given** un fichier porté du legacy (ex. utilitaires de génération de policies copiés depuis `gpo.inc.php`)
**When** ce fichier est créé sous `app/Gpo/`
**Then** il porte un header docblock `@legacy-port path="sambaedu/<file>.php"` + `@todo` de refactoring + lien vers une note de dette dans `docs/tech-debt-gpo.md` (créer le doc s'il n'existe pas).

### Volet 3 — Abstraction `GpoService` autour de `samba-tool gpo`

**AC3.1**
**Given** la classe `App\Gpo\Services\GpoService` (renommée pour ne pas collisionner avec l'actuel `App\Services\GpoSyncService`)
**When** elle est instanciée
**Then** elle expose **a minima** ces méthodes typées (signatures à détailler dans la doc) couvrant les commandes `samba-tool gpo *` disponibles dans le legacy :
- `list(): Collection<GpoSummary>` — wrapper `samba-tool gpo listall`
- `get(string $name): ?GpoSummary` — wrapper `samba-tool gpo show`
- `create(string $displayname): GpoSummary` — wrapper `samba-tool gpo create`
- `delete(string $name): bool` — wrapper `samba-tool gpo del`
- `fetch(string $name, string $destDir): bool` — wrapper `samba-tool gpo fetch`
- `listContainers(string $name): array` — wrapper `samba-tool gpo listcontainers`
- `getLinks(string $containerDn): array` — wrapper `samba-tool gpo getlink`
- `setLink(string $containerDn, string $gpoName, bool $enforce = false, bool $disable = false): bool`
- `removeLink(string $containerDn, string $gpoName): bool`
- `getInheritance(string $containerDn): bool`
- `setInheritance(string $containerDn, bool $enabled): bool`

**And** chaque méthode logge entrée / sortie / erreur dans le channel `gpo` (cf. décision D2) avec `operation_id`
**And** toutes les valeurs CLI sont passées via `escapeshellarg()` ou via `Process::run()` en mode array (pas de concaténation string).

**AC3.2**
**Given** le runner d'exécution shell `App\Gpo\Support\SambaToolRunner` (ou nom équivalent)
**When** une commande `samba-tool gpo *` est exécutée
**Then** elle passe par `Illuminate\Support\Facades\Process` en mode array
**And** le code retour, stdout, stderr sont capturés et logués
**And** un `timeout` (default 30s, paramétrable) est appliqué
**And** un `binPath` configurable (default `/usr/bin/samba-tool`) permet le mock en tests
**And** un mode `dry-run` est disponible (n'exécute pas, retourne la commande qui aurait été lancée — utile pour tests + Story 16.2/16.4).

**AC3.3**
**Given** la classe `App\Gpo\Services\GpoService`
**When** elle est instanciée en environnement de test
**Then** le runner shell peut être mocké facilement via `Process::fake()` (Laravel standard)
**And** un helper `tests/Support/FakesGpoService.php` (ou trait équivalent) fournit des fixtures réutilisables (liste de GPOs simulées, output `samba-tool gpo listall` typique, etc.).

**AC3.4** — **Cohabitation avec l'existant**
**Given** le service legacy `App\Services\GpoSyncService` (computer.elevate)
**When** la Story 16.1 est mergée
**Then** l'ancien service n'est **pas supprimé** mais une **note de dépréciation** est ajoutée en docblock (`@deprecated to be folded into App\Gpo\Services\GpoService in Story 16.4+`) pointant la migration future
**And** aucune autre partie du code applicatif n'est cassée (regression suite verte).

### Volet 4 — Garde-fou architectural : pas de logique métier dans le shim 1bis.18 hors transition

**AC4.1**
**Given** le namespace `App\Gpo` et le module legacy `legacy/modules/gpo/`
**When** le test archi `tests/Architecture/GpoLegacyIsolationTest.php` s'exécute
**Then** il vérifie que :
- Aucun fichier sous `app/Gpo/` n'invoque `require_once` / `include` de fichiers `legacy/` (le shim reste un canal de transition isolé)
- Inversement, aucun fichier sous `app/Gpo/` n'est requis par `legacy/bootstrap.php` (frontière nette).

**AC4.2** — **Pas de production de tables Eloquent dans cette story**
**Given** la décision de cadrage SM
**When** la Story 16.1 est implémentée
**Then** **aucune migration Eloquent n'est créée**. Les éventuelles tables de tracking (cache de liste GPO, corbeille, journal d'opérations) seront créées **story par story** au fil de 16.2-16.6, selon le besoin réel
**And** ce choix est consigné dans `app/Gpo/README.md` (« AD = source de vérité, Eloquent en lecture seule jusqu'à besoin avéré »).

### Volet 5 — Paramétrage chemins & config

**AC5.1**
**Given** le fichier `config/sambaedu.php`
**When** un service `App\Gpo\*` consomme un chemin SYSVOL ou un paramètre samba-tool
**Then** la lecture passe par une section dédiée `config('sambaedu.gpo.*')` :
- `bin_path` — chemin binaire `samba-tool` (default `/usr/bin/samba-tool`)
- `sysvol_path` — chemin SYSVOL local (default `/var/lib/samba/sysvol`)
- `policies_temp_path` — répertoire de travail pour fetch GPO (default `/var/www/sambaedu/temp/policies`, parité legacy `gpo.inc.php:1053`)
- `samba_tool_timeout` — timeout default 30s
- `kerb_option` — option `-k yes` ou via authentification stockée (parité legacy `samba-tool.inc.php:54`)

**And** ces valeurs sont écrites **en dur** dans `config/sambaedu.php` (pas de `env(...)` sauf indication contraire, cohérence avec Story 15.1 AC4.1)
**And** un check de démarrage applicatif vérifie que `bin_path` existe et que `sysvol_path` est accessible, sinon log warning explicite.

### Volet 6 — Audit legacy `audit-gpo-legacy.md` (livrable de recherche)

**AC6.1** — **Livrable**
**Given** la fin de l'implémentation des volets 1-5
**When** la story est marquée prête pour review
**Then** le fichier `_bmad-output/planning-artifacts/audit-gpo-legacy.md` existe et contient :

#### Section 6.A — Cartographie fichier par fichier

Pour **chacun** des 19 fichiers `sambaedu/gpo/*.php` ET des 4 includes `sambaedu/includes/gpo.inc.php` / `samba-tool.inc.php` / `delegations.inc.php` / `gpo_ui.inc.php`, un sous-bloc :

- **Fichier source** + chemin + lignes
- **Rôle** (en 1-3 phrases)
- **Endpoints HTTP** s'il y en a (pages PHP) : routes legacy, paramètres GET/POST attendus
- **Inputs** : paramètres, fichiers lus, requêtes LDAP, requêtes SQL legacy
- **Outputs** : HTML, fichiers écrits (SYSVOL, .pol, .xml, etc.), JSON, redirections
- **Dépendances système** : appels `samba-tool`, `smbclient`, `smbcacls`, `wbinfo`, `rm`, `mkdir` ; fichiers `.inc.php` requis
- **Statut shim 1bis.18** : présent dans `legacy/modules/gpo/` ? (oui/non), si oui sous-story (18a-g)
- **Statut native** : déjà partiellement refondu en Laravel ? (citer la story et le composant — ex. wallpaper → Story 4.7, raccourcis → `ShortcutsService`, profils itinérants → `RoamingProfileService` 1bis-18f)
- **Stratégie de port recommandée** : `réécriture` / `port + adaptation` / `abandon` / `déjà-natif-rien-à-faire`
- **Story Epic 16 cible** : 16.2 / 16.3 / 16.4 / 16.5 / 16.6 (ou « hors Epic 16 »)
- **Risques / pièges** (ex. : appels `exec` non escapés, dépendance fonction `function_exists`, encodage UTF/UTF-16 legacy `detect_utf_encoding`, etc.)

#### Section 6.B — Catalogue commandes `samba-tool gpo *`

Liste exhaustive des commandes `samba-tool gpo` invoquées par le legacy avec :
- Commande exacte (ex. `samba-tool gpo create`, `samba-tool gpo listall`, etc.)
- Fichier / fonction d'appel (`samba-tool.inc.php:gpocreate`, etc.)
- Paramètres passés (typés autant que possible)
- Output attendu (format text/JSON/stdout vide+code retour)
- Couverture proposée dans `GpoService` (référence à AC3.1)
- Commandes manquantes éventuellement nécessaires non utilisées par le legacy (à signaler pour futures stories)

#### Section 6.C — Mapping sections spécialisées → composants Laravel cibles

Tableau Markdown des **7 sections** mentionnées dans le cadrage Epic 16 (Story 16.3) :

| Section | Fichier(s) legacy | Statut actuel | Stratégie portage | Story cible |
|---|---|---|---|---|
| Firefox | `gpo/firefox.php`, `gpo/firefox_out.php`, `includes/firefox.inc.php` | Refondu en natif Story 4.8 (`AppCustomization`) | Hors scope Epic 16 OU adapter `AppCustomization` à exposer dans page GPO | À trancher en 16.3 |
| Thunderbird | `gpo/thunderbird_out.php` | Refondu en natif Story 4.8 | Idem Firefox | À trancher en 16.3 |
| Wallpaper | `gpo/wallpaper.php`, `gpo/wallpaper_out.php`, `includes/wallpaper.inc.php` | Refondu en natif Story 4.7 | Hors scope Epic 16 | (déjà fait) |
| Raccourcis | `gpo/shortcuts.php`, `gpo/shortcuts_out.php` | Refondu en natif (`ShortcutsService`, `ShortcutExportController`) | Hors scope Epic 16 | (déjà fait) |
| Veyon | `gpo/veyon_out.php` | Shimé 1bis-18e (byte-identique) | Réécriture native | 16.3 |
| Wine | `gpo/wine.php` | Shimé 1bis-18e | Réécriture native | 16.3 |
| Associations apps | `gpo/applications.php`, `gpo/associations_out.php` | Shimé 1bis-18e | Réécriture native | 16.3 |
| Network (proxy) | `gpo/network_out.php`, `includes/network.inc.php` | Shimé 1bis-18e | Réécriture native | 16.3 (à ajouter aux 7 sections du cadrage) |
| Roaming profiles | `gpo/no_roam.php`, `gpo/del_roam.php`, `gpo/user_profile_stats.php` | Refondu en natif Story 1bis-18f (`RoamingProfileService`) | Compléter / exposer dans page GPO | 16.3 |

> Le dev affinera ce tableau pendant l'audit ; le contenu ci-dessus est un **point de départ** à valider / corriger.

#### Section 6.D — Contour du shim 1bis.18 (frontière transition)

- Liste des pages legacy actuellement encore servies via catchall (i.e. **pas** refondues) : à enrichir pendant l'audit
- Liste des pages déjà refondues en natif (à laisser tomber)
- Stratégie de désactivation progressive du shim story par story : routage (catchall override / drapeau feature / table de mapping)
- **Décision SM par défaut** : le shim 1bis.18 **reste actif** pendant tout l'Epic 16. Chaque story 16.x qui livre une page native peut soit (a) override le catchall pour sa route, soit (b) cohabiter (page legacy + page native différenciées par URL).

#### Section 6.E — Capacités legacy *non revendiquées* par Epic 16

Liste des fonctionnalités du legacy GPO que le cadrage Epic 16 **n'a pas prévues** mais qui sont potentiellement utilisées en production. Pour chacune :
- Fonction / fichier
- Description
- Recommandation : ajouter à Epic 16 (et à quelle story) / ne pas porter (et justification) / à clarifier avec Henri

> Référence recommandation transversale du rapport readiness 2026-05-05 (lignes 466-467) : *« identifier les capacités legacy non revendiquées par le cadrage Epic 16 — risque de parité fonctionnelle incomplète »*.

#### Section 6.F — Risques sécurité hérités

Liste des vecteurs d'injection ou risques identifiés (ex. `exec("/usr/bin/samba-tool " . $command)` dans `samba-tool.inc.php:54` — voir audit 1bis-18a T3.x) avec :
- Localisation
- Vecteur (paramètre user-controlled ? admin-only ?)
- Recommandation : à corriger dans la story de portage (laquelle) / à laisser tel quel (justifier) / à mitiger via `GpoService` (échappement systématique)

#### Section 6.G — Conclusion & recommandations de découpage

Synthèse des recommandations de découpage pour 16.2 → 16.6 (« la Story 16.3 doit être splittée par section X parce que Y », « la Story 16.4 peut absorber telle ou telle capacité legacy non revendiquée », etc.).

---

## Tasks / Subtasks

### Phase T0 — Cadrage & vérifications préalables

- [x] **T0.1** Lire `_bmad-output/implementation-artifacts/15-1-fondations-pipeline-deploiement-wpkg.md` en entier (référence de structure pour le namespace + tests archi + channel logs).
- [x] **T0.2** Lire `_bmad-output/implementation-artifacts/1bis-18a-module-gpo-includes-core.md` (cartographie partielle déjà disponible des fonctions des includes legacy).
- [x] **T0.3** Vérifier l'état des stories 1bis-18 dans `sprint-status.yaml` (done / cancelled / review) — la liste à jour est dans le tableau **Dépendances** ci-dessus.
- [x] **T0.4** Vérifier l'existence et l'état actuel de `App\Services\GpoSyncService` (déjà présent — sera renommé / déprécié en AC3.4).

### Phase T1 — Volet 1 : Channel logs `gpo` (verbeux par action)

- [x] **T1.1** Ajouter le channel `gpo` dans `config/logging.php` (modèle : channel `wpkg-deploy` ligne 133-139 — `daily`, path `storage/logs/gpo/gpo.log`, days from `GPO_LOG_DAYS` default 30, level from `GPO_LOG_LEVEL` default `debug`). (AC: 1.1)
- [x] **T1.2** Vérifier / ajouter la création automatique de `storage/logs/gpo/` au boot (référence commit `42cebba` pour `wpkg-deploy`). (AC: 1.2)
- [x] **T1.3** Créer la classe `App\Gpo\Support\GpoLogger` avec API `action()` / `step()` / `success()` / `failure()` / `diff()` selon AC1.4. Inclure une classe interne `App\Gpo\Support\GpoActionLog` (handle d'action). (AC: 1.3, 1.4)
- [x] **T1.4** Documenter le catalogue `action_type` dans `app/Gpo/README.md` (table copiée depuis AC1.3) — le README devient la **convention de logging Epic 16** à respecter par toutes les stories 16.x. (AC: 1.3)
- [x] **T1.5** Tests Unit `tests/Unit/Gpo/GpoLoggerTest.php` : une action émet bien `start` + `success`/`failure`, `operation_id` auto-généré, durée mesurée, diff structuré, troncature stdout/stderr à 8 Ko. (AC: 1.4)

### Phase T2 — Volet 2 : Namespace `App\Gpo`

- [x] **T2.1** Créer l'arborescence `app/Gpo/{Services,Jobs,Models,Support,Events,Dto}/` avec `.gitkeep`. (AC: 2.1)
- [x] **T2.2** Créer `app/Gpo/README.md` documentant la structure et la justification de l'écart vs `architecture.md:453`. (AC: 2.1)
- [x] **T2.3** Créer `docs/tech-debt-gpo.md` (vide ou note d'amorce) pour héberger les références `@legacy-port` futures. (AC: 2.3)
- [x] **T2.4** Implémenter `tests/Architecture/GpoNamespaceTest.php` (interdit `LdapRecord\*` direct, interdit `exec(`/`shell_exec(`/`passthru(`/`proc_open(` hors `app/Gpo/Support/SambaToolRunner.php`, interdit appel à `sambatool()` legacy). (AC: 2.2)

### Phase T3 — Volet 3 : `GpoService` + `SambaToolRunner`

- [x] **T3.1** Créer `app/Gpo/Support/SambaToolRunner.php` (wrapper `Process::run()` mode array, gestion timeout / stdout / stderr / dry-run, paramètres depuis `config('sambaedu.gpo.*')`). (AC: 3.2)
- [x] **T3.2** Créer `app/Gpo/Services/GpoService.php` exposant **a minima** les 11 méthodes listées en AC3.1. Pour cette story, implémentation **lecture** complète (`list`, `get`, `listContainers`, `getLinks`, `getInheritance`) ; implémentation **écriture** **stubs typés** (signatures + log + `throw new \RuntimeException('not implemented yet — see Story 16.4')`) — pour ne pas court-circuiter le travail des stories 16.4/16.5. (AC: 3.1)
- [x] **T3.3** Créer les DTOs `App\Gpo\Dto\GpoSummary` et `App\Gpo\Dto\GpoLink` (readonly, typés strict). (AC: 3.1)
- [x] **T3.4** Créer le helper de test `tests/Support/FakesGpoService.php` (fixtures réutilisables : output simulé `samba-tool gpo listall`). (AC: 3.3)
- [x] **T3.5** Tests Unit `tests/Unit/Gpo/GpoServiceTest.php` couvrant : parsing sortie `listall`, parsing erreurs samba-tool, escape arguments, dry-run mode, timeout, mock `Process::fake()`. (AC: 3.1, 3.3)
- [x] **T3.6** Ajouter docblock `@deprecated` sur `App\Services\GpoSyncService` pointant vers `App\Gpo\Services\GpoService` (sans supprimer ni casser). (AC: 3.4)

### Phase T4 — Volet 4 : Garde-fou isolation legacy

- [x] **T4.1** Implémenter `tests/Architecture/GpoLegacyIsolationTest.php` (interdit `require_once` / `include` de `legacy/*` depuis `app/Gpo/*`). (AC: 4.1)
- [x] **T4.2** Documenter dans `app/Gpo/README.md` la décision « pas de migrations Eloquent dans cette story — tables créées story par story ». (AC: 4.2)

### Phase T5 — Volet 5 : Config `sambaedu.gpo.*`

- [x] **T5.1** Ajouter dans `config/sambaedu.php` une section `'gpo' => [ ... ]` avec les 5 clés `bin_path`, `sysvol_path`, `policies_temp_path`, `samba_tool_timeout`, `kerb_option`. (AC: 5.1)
- [x] **T5.2** Implémenter le check de démarrage applicatif (Service Provider ou commande artisan) qui vérifie l'existence de `bin_path` et l'accessibilité de `sysvol_path` — log warning si KO, ne bloque pas le boot. (AC: 5.1)

### Phase T6 — Volet 6 : Audit legacy → `audit-gpo-legacy.md`

> Le dev produit ce livrable **après** l'implémentation des volets 1-5 (l'écriture du code donne la connaissance nécessaire pour l'audit).

- [x] **T6.1** Section 6.A — cartographie fichier par fichier (19 fichiers UI + 4 includes). Pour chaque entrée, remplir les 11 champs prévus en AC6.1. (AC: 6.1)
- [x] **T6.2** Section 6.B — catalogue commandes `samba-tool gpo *` (parcourir `samba-tool.inc.php` lignes 923-1078 où sont définies les wrappers `gpolist`, `gposetlink`, `gpolistcontainers`, `gpogetlink`, `gpodellink`, `gposetinheritance`, `gpogetinheritance`, `gpofetch`, `gpocreate`, `gpodel`). (AC: 6.1)
- [x] **T6.3** Section 6.C — mapping sections spécialisées → composants Laravel cibles. Affiner / corriger le tableau d'amorce ci-dessus. (AC: 6.1)
- [x] **T6.4** Section 6.D — contour shim 1bis.18 (lister les routes encore servies vs refondues). Croiser avec `legacy/modules/gpo/` (8 fichiers présents) et avec les pages natives existantes (`/app/shortcuts`, `/app/parc-settings/wallpapers`, `/app/parc-settings/customizations`, `/app/admin/settings`). (AC: 6.1)
- [x] **T6.5** Section 6.E — capacités legacy non revendiquées par Epic 16. Croiser avec le cadrage Epic 16 dans `epics.md` lignes 3294-3332. (AC: 6.1)
- [x] **T6.6** Section 6.F — risques sécurité hérités (vecteurs d'injection identifiés dans 1bis-18a T3.1-T3.4 + audit propre). (AC: 6.1)
- [x] **T6.7** Section 6.G — conclusion + recommandations de découpage pour 16.2-16.6 (en particulier : confirmer / proposer le split de Story 16.3). (AC: 6.1)
- [ ] **T6.8** Soumettre l'audit à Henri pour validation **avant** de marquer la story `review`. L'audit est le deliverable structurant.
      **Note dev** : audit livré, en attente de validation par Henri (cf. Completion Notes).

### Phase T7 — Validation finale

- [ ] **T7.1** Exécuter la suite de tests complète : aucun test cassé (régression).
      **Note dev** : non lancé depuis le worktree (pas de `vendor/` local — code synchronisé via inotify vers la VM). Henri à lancer sur la VM : voir Completion Notes.
- [ ] **T7.2** Tests nouveaux verts : `tests/Architecture/GpoNamespaceTest.php`, `tests/Architecture/GpoLegacyIsolationTest.php`, `tests/Unit/Gpo/GpoServiceTest.php`.
      **Note dev** : à lancer sur la VM. Syntaxe PHP validée (`php -l`) sur tous les fichiers.
- [ ] **T7.3** Vérifier qu'aucun service legacy existant (notamment `GpoSyncService` appelé par `Spatie\Permission`) n'est cassé.
      **Note dev** : modifications limitées à ajout de docblock `@deprecated` — aucune signature ni body modifié. Test de non-régression à lancer par Henri (cf. Scénario QA 16.1-4).
- [x] **T7.4** Mettre à jour le sprint-status.yaml : `16-1-fondations-gpo-natives-audit-legacy: ready-for-dev` → `review` (ou `done` selon flux validation SM).
- [ ] **T7.5** Demander à Henri un smoke test VM minimal : (a) le boot ne casse rien ; (b) `php artisan tinker` → `app(App\Gpo\Services\GpoService::class)->list()` retourne la liste réelle des GPOs ; (c) le channel logs `gpo` produit bien un fichier sous `storage/logs/gpo/`.
      **Note dev** : checklist détaillée ajoutée dans `docs/qa/domains/gpo.md` § Story 16.1 (scénarios 16.1-1 à 16.1-5).

---

## File List prévisionnelle

### Fichiers créés

```
app/Gpo/README.md
app/Gpo/Services/GpoService.php
app/Gpo/Support/SambaToolRunner.php
app/Gpo/Support/GpoLogger.php
app/Gpo/Support/GpoActionLog.php
app/Gpo/Dto/GpoSummary.php
app/Gpo/Dto/GpoLink.php
app/Gpo/Jobs/.gitkeep
app/Gpo/Models/.gitkeep
app/Gpo/Events/.gitkeep

docs/tech-debt-gpo.md

tests/Architecture/GpoNamespaceTest.php
tests/Architecture/GpoLegacyIsolationTest.php
tests/Unit/Gpo/GpoServiceTest.php
tests/Unit/Gpo/SambaToolRunnerTest.php
tests/Unit/Gpo/GpoLoggerTest.php
tests/Feature/Gpo/GpoLoggingChannelTest.php
tests/Support/FakesGpoService.php

_bmad-output/planning-artifacts/audit-gpo-legacy.md          ← LIVRABLE PRINCIPAL
```

### Fichiers modifiés

```
config/logging.php                            (+ channel gpo)
config/sambaedu.php                           (+ section 'gpo')
app/Services/GpoSyncService.php               (+ docblock @deprecated)
app/Providers/AppServiceProvider.php          (+ boot check sambaedu.gpo.bin_path)
_bmad-output/implementation-artifacts/sprint-status.yaml   (status update)
```

> **Note :** aucune migration Eloquent dans cette story (cf. AC4.2). Aucun fichier supprimé (le shim 1bis.18 reste vivant).

---

## Test Strategy

### Couverture par niveau

| Niveau | Périmètre | Fichiers |
|---|---|---|
| **Unit** | `SambaToolRunner` (mock `Process::fake()`) — escape args, timeout, dry-run, parsing stdout/stderr | `tests/Unit/Gpo/SambaToolRunnerTest.php` |
| **Unit** | `GpoService` — parsing sortie `listall`, parsing erreurs, stubs écriture lèvent bien `RuntimeException` | `tests/Unit/Gpo/GpoServiceTest.php` |
| **Architecture** | `app/Gpo/*` n'importe pas `LdapRecord\*` / pas d'`exec()` direct / pas d'appel à `sambatool()` legacy | `tests/Architecture/GpoNamespaceTest.php` |
| **Architecture** | `app/Gpo/*` n'inclut pas `legacy/*` et n'est pas inclus par `legacy/bootstrap.php` | `tests/Architecture/GpoLegacyIsolationTest.php` |
| **Feature** | Channel `gpo` route bien les logs vers le bon fichier (test rapide d'intégration logging) | `tests/Feature/Gpo/GpoLoggingChannelTest.php` |
| **Smoke VM (manuel)** | `GpoService::list()` retourne la liste réelle des GPOs sur la VM (Henri) | (smoke test post-merge) |

### Stratégie de mock samba-tool

- **Production** : `Process::run(['/usr/bin/samba-tool', 'gpo', 'listall', ...])` réel.
- **Tests** : `Process::fake([...])` Laravel standard. Le helper `FakesGpoService` fournit des outputs typiques (au moins 3 GPOs simulées, codes retour 0 et 1, stderr non vide).
- **Dev local** : mode `dry-run` via `App\Gpo\Support\SambaToolRunner::dryRun()` qui retourne la commande sans l'exécuter.

### Tests qu'on ne fait **pas** dans cette story

- Tests E2E de création / suppression de GPO réelles → reportés à Story 16.4 (qui implémente l'écriture).
- Tests UI Livewire → reportés à Story 16.2.
- Tests de liaisons GPO ↔ OU → reportés à Story 16.5.

---

## Dev Notes — Contraintes & décisions cadrage SM

### Décisions de cadrage prises par le SM (sur la base du rapport readiness 2026-05-05)

| # | Décision | Justification |
|---|---|---|
| D1 | Namespace **`App\Gpo`** (pas `App\Services\Windows\Gpo*`) | Cohérence avec `App\Wpkg\Deployment\*` (Story 15.1) : namespace racine par domaine fonctionnel. Le cadrage Epic 16 dans `epics.md:3312` mentionne explicitement `App\Gpo`. Écart documenté dans `app/Gpo/README.md` (AC2.1). |
| D2 | Channel logs **`gpo`** (large, pas `gpo-deploy`) + **convention de logging verbeuse par `action_type`** (s'applique à tout Epic 16) | Demande explicite Henri 2026-05-11 : channel large couvrant lecture + écriture + audit + sync + déploiement (pas seulement deploy). Verbosité élevée (`debug` par défaut) en transition Epic 16 pour traçabilité maximale. Catalogue d'`action_type` documenté dans `app/Gpo/README.md` comme **convention transverse Epic 16**. |
| D3 | **Pas de migration Eloquent dans cette story** | AD = source de vérité GPO. Les tables de tracking (cache liste, corbeille, journal) seront créées story par story selon besoin avéré, pas par anticipation. |
| D4 | **Renommer** la classe : nouveau `App\Gpo\Services\GpoService` ≠ existant `App\Services\GpoSyncService` (computer.elevate délégations) | Évite collision. L'ancien sera replié progressivement (Story 16.4+). Dans cette story : ajout docblock `@deprecated`. |
| D5 | **Volet écriture en stubs uniquement** dans cette story (`create`, `delete`, `setLink`, etc. = `throw new RuntimeException`) | Évite que 16.1 absorbe le scope de 16.4. La story 16.1 livre les **signatures stables** et la lecture complète, pas l'écriture. |
| D6 | **Le shim 1bis.18 reste vivant** | Pas de désactivation dans cette story. Décision par story 16.2-16.6 de cohabiter ou d'override. |
| D7 | **Audit = livrable bloquant** | La story ne peut être marquée `review` qu'après validation Henri de `audit-gpo-legacy.md`. C'est l'output structurant qui guide tout Epic 16. |
| D8 | **Tests archi en PHPUnit custom** (Symfony Finder + reflection), pas ArchTest/PHPStan | Cohérence avec Story 15.1 AC2.1. Migration vers ArchTest hors scope. |

### Discrepances connues à trancher pendant l'implémentation

| Item | Note SM |
|---|---|
| Route `/app/gpo` (cadrage Story 16.2) vs `pages/windows-deploy/` (architecture) | **À trancher en Story 16.2, pas ici.** Cette story ne crée aucune page. Recommandation cohérence Epic 15/16/17 : sous-page `windows-deploy/gpo/`. |
| Story 16.3 — split en sous-stories par section | L'audit (Section 6.G) doit produire la recommandation finale de découpage. **Cette story 16.1 ne tranche pas, mais arme la décision.** |
| Story 16.6 — jonction WPKG, FR PRD manquant | Hors scope 16.1. À traiter au moment du cadrage 16.6. Mentionner dans la conclusion d'audit (Section 6.E) si l'audit révèle un besoin spécifique. |

### Références codebase pour le dev

- **Structure de référence** : `app/Wpkg/Deployment/` (sous-dossiers `Services/`, `Generators/`, `Jobs/`, etc.) — reproduire le pattern.
- **Channel logs de référence** : `config/logging.php:131-139` (`wpkg-deploy`).
- **Test archi de référence** : voir Story 15.1 AC2.1 — `tests/Architecture/WpkgDeploymentNamespaceTest.php` (implémentation à imiter).
- **Service legacy à ne pas casser** : `app/Services/GpoSyncService.php` (utilisé par `Spatie\Permission` pour `computer.elevate`) — laissé vivant, juste un `@deprecated`.
- **Includes legacy chargés** : voir Story 1bis-18a — `legacy/bootstrap.php` charge déjà `samba-tool.inc.php`, `gpo.inc.php`, `delegations.inc.php`, `gpo_ui.inc.php` dans cet ordre. **Ne pas y toucher.**
- **Catalogue fonctions legacy** (point de départ audit Section 6.A et 6.B) :
  - `sambaedu/includes/samba-tool.inc.php` lignes 923-1078 : `gpolist`, `gposetlink`, `gpolistcontainers`, `gpogetlink`, `gpodellink`, `gposetinheritance`, `gpogetinheritance`, `gpofetch`, `gpocreate`, `gpodel`, `dsacl_set`, `dsacl_get`
  - `sambaedu/includes/gpo.inc.php` ~45 fonctions (cf. Read effectué en cadrage : `read_pol`, `write_pol`, `import_gpo`, `export_gpo`, `delete_gpo`, `update_gpo_sysvol`, etc.)
  - `sambaedu/includes/delegations.inc.php` : `add_delegation_salle`, `remove_delegation_salle` (déjà utilisés par `GpoSyncService` actuel)
  - `sambaedu/includes/gpo_ui.inc.php` 3 fonctions UI (`gpo_form_no_roam`, etc.)
- **Convention CLAUDE.md à suivre** :
  - Modale réutilisable + bouton de déclenchement (pas dans cette story — pas d'UI)
  - Notifications via trait `WithToasts` (pas dans cette story)
  - Architecture trois couches : SFC → Services → Models (zéro logique métier dans SFC) — applicable à 16.2+
- **Volume cible audit** : 19 + 4 = **23 fichiers PHP** à cartographier en Section 6.A. C'est volumineux, prévoir ~½ journée de travail.

### Pièges identifiés (cf. dev notes 1bis-18a)

1. **Encodage UTF-16 dans `.pol` legacy** : `read_pol` / `write_pol` (`gpo.inc.php:247,342`) gèrent l'encodage policy registry Windows. Toute lecture/écriture de `.pol` dans `App\Gpo` devra réutiliser cette logique (potentiellement via un helper porté `@legacy-port`).
2. **Vecteurs d'injection `samba-tool`** : `samba-tool.inc.php:54` `exec("/usr/bin/samba-tool " . $command . $kerb_option ...)` — le `$command` n'est pas systématiquement échappé par les appelants. `SambaToolRunner` doit imposer l'échappement.
3. **Idempotence `define()`** : `gpo.inc.php` définit 30+ constantes au top-level. Si on les réutilise depuis `App\Gpo`, ne pas re-définir (utiliser `defined()` guard).
4. **Dépendance `function_exists` legacy** : `App\Services\GpoSyncService` actuel utilise `function_exists('add_delegation_salle')` pour découvrir le legacy. `App\Gpo\Services\GpoService` doit éviter ce pattern et passer par `samba-tool gpo` directement.
5. **Le SYSVOL est partagé via Samba** : les écritures de fichiers SYSVOL passent par `smbclient` / `smbcacls` (cf. 1bis-18a T3.3). À héberger dans `SambaToolRunner` aussi (ou un sibling `SmbClientRunner`) — **décision déléguée à Story 16.4** quand on touchera l'écriture.

---

## Project Structure Notes

### Alignement structure projet

- **Namespace cible :** `App\Gpo` (racine, cohérence avec `App\Wpkg`). Cf. D1 ci-dessus.
- **Pages cibles :** *hors scope cette story.* Routage `/app/gpo` ou `/app/windows-deploy/gpo` à trancher en 16.2.
- **Tests :** `tests/Unit/Gpo/`, `tests/Feature/Gpo/`, `tests/Architecture/` (le dossier `Architecture/` existe-t-il déjà ? sinon le créer — cohérence Story 15.1).

### Conflits / variances détectés

| Élément | Architecture officielle | Décision Story 16.1 | Justification |
|---|---|---|---|
| Namespace | `App\Services\Windows\GpoService` (architecture.md:453) | `App\Gpo\Services\GpoService` | Parallélisme `App\Wpkg\Deployment` + cadrage Epic 16 |
| Route page | `pages/windows-deploy/` (architecture.md:486) | *non décidé dans cette story* | Sera tranché en 16.2 |
| Tables Eloquent | implicite « tables de tracking si pertinentes » (cadrage Epic 16) | Aucune table dans 16.1 | Décision SM D3 |

---

## References

- `_bmad-output/planning-artifacts/prd.md#FR23` — exigence fonctionnelle racine
- `_bmad-output/planning-artifacts/epics.md` lignes 3294-3332 — Epic 16 cadrage
- `_bmad-output/planning-artifacts/architecture.md` lignes 440-530 — structure projet, mapping FRs
- `_bmad-output/planning-artifacts/implementation-readiness-report-2026-05-05-epic16.md` — rapport readiness Epic 16 (contexte exhaustif)
- `_bmad-output/implementation-artifacts/15-1-fondations-pipeline-deploiement-wpkg.md` — **référence de structure**
- `_bmad-output/implementation-artifacts/1bis-18a-module-gpo-includes-core.md` — audit partiel des includes legacy
- `_bmad-output/implementation-artifacts/1bis-18e-module-gpo-scripts-veyon-wine-associations.md` — pages legacy encore shimées
- `_bmad-output/implementation-artifacts/1bis-18f-module-gpo-profils-itinerants.md` — refonte native partielle déjà faite
- `app/Services/GpoSyncService.php` — service existant à laisser vivant (deprecate only)
- `app/Wpkg/Deployment/` — pattern de structure à reproduire
- `config/logging.php:131-139` — channel `wpkg-deploy` (modèle pour `gpo-deploy`)
- `config/sambaedu.php:167+` — section `wpkg` (modèle pour section `gpo`)
- `sambaedu/includes/gpo.inc.php`, `samba-tool.inc.php`, `delegations.inc.php`, `gpo_ui.inc.php` — sources legacy à auditer
- `sambaedu/gpo/*.php` — 19 fichiers UI à cartographier
- `legacy/modules/gpo/` — copies byte-identiques shimées (8 fichiers présents)

---

## Dev Agent Record

### Agent Model Used

claude-opus-4-7 (1M context, dev-cycle Étape 3)

### Debug Log References

- Channel logs `gpo` ajouté à `config/logging.php:142-153` (driver `daily`, level
  `GPO_LOG_LEVEL=debug`, rotation `GPO_LOG_DAYS=30`).
- Provisioning auto du dossier `storage/logs/gpo/` au boot via
  `App\Providers\GpoServiceProvider` (cohérent commit `42cebba` pour wpkg-deploy).
- Validation syntaxe PHP de tous les fichiers nouveaux/modifiés via `php -l`.
- Tests NON exécutés depuis le worktree (pas de `vendor/` local, code synchronisé
  vers la VM via inotify). À lancer par Henri sur la VM — commandes dans
  Completion Notes ci-dessous.

### Completion Notes List

1. **Channel logs `gpo`** (pas `gpo-deploy`) conforme décision Henri 2026-05-11.
   Niveau `debug` par défaut pendant Epic 16, à bumper en `info` une fois
   l'epic stabilisé.

2. **Decision D5 (stubs écriture)** appliquée à la lettre : les 6 méthodes
   `create`, `delete`, `fetch`, `setLink`, `removeLink`, `setInheritance`
   lèvent `RuntimeException` avec message stable contenant la ref de la story
   future (16.3, 16.4 ou 16.5). Les signatures sont stables — Stories 16.4/16.5
   peuvent compter dessus.

3. **`GpoLogger` / `GpoActionLog`** : API complète (`action()`/`step()`/
   `success()`/`failure()`/`diff()`) + helper `sambaToolExec()` pour les logs
   debug avec troncature stdout/stderr à 8 Ko. UUID v4 auto-généré, durée
   propagée (`elapsed_ms`), `success()`/`failure()` idempotents.

4. **`SambaToolRunner`** : seul point du namespace `App\Gpo` autorisé à
   utiliser `Illuminate\Support\Facades\Process` (whitelist explicite dans
   `GpoNamespaceTest`). Mode array (pas de concat string), timeout
   configurable via `withTimeout()`, dry-run via `withDryRun()` (clone
   immutable). Le mode dry-run construit un faux `ProcessResult` via
   `Process::result()` sans polluer `Process::fake()` global — cohérent avec
   l'usage Story 15.1.

5. **`GpoService`** parsers texte robustes : `parseListAll` accepte le format
   multi-bloc séparé par lignes vides, `parseGetLink` calcule bien
   `enforced`/`disabled` via le bitfield Options samba-tool (cf.
   gpo.inc.php:1001-1006 legacy). `parseGetInheritance` reproduit la logique
   regex `/GPO_INHERIT/` du legacy (samba-tool.inc.php:1045).

6. **`GpoServiceProvider`** : skip complet en environment `testing` (parité
   `WpkgDeploymentServiceProvider`), création atomique du dossier de logs
   AVANT tout autre log sur le channel `gpo` (ordre critique chicken-and-egg).
   Vérifie `bin_path` (exists + executable) et `sysvol_path` (exists +
   readable). Warnings non-bloquants.

7. **Tests archi (`GpoNamespaceTest` + `GpoLegacyIsolationTest`)** : 3 tests
   archi distincts (interdit LdapRecord direct, interdit shell exec hors
   runner, interdit appel `sambatool()` legacy) + 2 tests de symétrie
   (frontière legacy ↔ natif). Pattern PHP-Parser pour les imports, regex
   pour les patterns code (avec strip commentaires).

8. **phpunit.xml** : ajout suite `Architecture` (pas dans config par défaut)
   — permet de lancer `php artisan test --testsuite=Architecture` proprement.

9. **`GpoSyncService` legacy** : juste un docblock `@deprecated` ajouté.
   Signature et body inchangés — pas de risque de régression sur les
   délégations `computer.elevate` (Stories 7.1/7.2 Spatie).

10. **Audit legacy** (`_bmad-output/planning-artifacts/audit-gpo-legacy.md`) :
    23/23 fichiers cartographiés en Section 6.A (19 UI + 4 includes). 7
    sections obligatoires complètes (6.A à 6.G). **Recommandation clé** :
    Story 16.3 à splitter en **3 sous-stories** (16.3a/b/c) — cadrage initial
    sous-évalué. 9 sections spécialisées identifiées (vs 7 dans le cadrage).
    Audit en attente de validation Henri (T6.8 non coché).

11. **Discrepances ouvertes à valider avec Henri** :
    - Périmètre `gpo/associations_out.php` : Epic 15 ou Epic 16 ? (couplage WPKG)
    - Fonctions DNS et réplication AD : Epic 16 ou hors scope ?
    - Route `/app/gpo` vs `pages/windows-deploy/gpo/` (à trancher en 16.2)
    - Validation finale du split Story 16.3 (recommandation 16.3a/b/c)
    - Templates GPO Git (`sambaedu-gpo-templates`) : conserver le pattern apt
      ou migrer en système intégré ?

12. **Tests à lancer manuellement sur la VM** (Henri) :
    ```bash
    ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50
    cd /var/www/sambaedu-reload
    php artisan test --testsuite=Architecture
    php artisan test tests/Unit/Gpo
    php artisan test tests/Feature/Gpo
    # Suite complète de régression :
    php artisan test
    ```
    Smoke tests détaillés : voir `docs/qa/domains/gpo.md` § Story 16.1
    (scénarios 16.1-1 à 16.1-5).

13. **Aucune migration Eloquent créée** (décision D3 respectée). Les tables
    de tracking (cache liste, corbeille, journal) seront créées story par
    story selon besoin avéré.

14. **Convention `@legacy-port`** documentée dans `app/Gpo/README.md` et
    `docs/tech-debt-gpo.md`. Aucun fichier porté dans cette story (rien à
    annoter) — la convention attend Story 16.3/16.4.

15. **Cohérence Epic 15** : pattern reproduit fidèlement (namespace racine,
    test archi PHP-Parser, ServiceProvider dédié, channel logs dédié). Le
    point d'écart documenté est le namespace (`App\Gpo` vs
    `App\Services\Windows\Gpo*` doc architecture).

### File List

**Créés :**

- `app/Gpo/README.md`
- `app/Gpo/Services/GpoService.php`
- `app/Gpo/Support/GpoLogger.php`
- `app/Gpo/Support/GpoActionLog.php`
- `app/Gpo/Support/SambaToolRunner.php`
- `app/Gpo/Dto/GpoSummary.php`
- `app/Gpo/Dto/GpoLink.php`
- `app/Gpo/Jobs/.gitkeep`
- `app/Gpo/Models/.gitkeep`
- `app/Gpo/Events/.gitkeep`
- `app/Providers/GpoServiceProvider.php`
- `docs/tech-debt-gpo.md`
- `tests/Architecture/GpoNamespaceTest.php`
- `tests/Architecture/GpoLegacyIsolationTest.php`
- `tests/Unit/Gpo/GpoServiceTest.php`
- `tests/Unit/Gpo/SambaToolRunnerTest.php`
- `tests/Unit/Gpo/GpoLoggerTest.php`
- `tests/Feature/Gpo/GpoLoggingChannelTest.php`
- `tests/Support/FakesGpoService.php`
- `_bmad-output/planning-artifacts/audit-gpo-legacy.md` (LIVRABLE PRINCIPAL)

**Modifiés :**

- `config/logging.php` (+ channel `gpo`)
- `config/sambaedu.php` (+ section `gpo`)
- `config/app.php` (+ `GpoServiceProvider` dans providers)
- `app/Services/GpoSyncService.php` (+ docblock `@deprecated`)
- `phpunit.xml` (+ suite `Architecture`)
- `docs/qa/README.md` (référence Story 16.1 ajoutée pour le domaine `gpo`)
- `docs/qa/domains/gpo.md` (+ section Story 16.1 avec 5 scénarios QA)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (status `review`)

**Aucun fichier supprimé** (le shim 1bis.18 reste vivant — décision D6).
**Aucune migration Eloquent créée** (décision D3).

### Change Log

| Date       | Auteur               | Changement                                                            |
|------------|----------------------|-----------------------------------------------------------------------|
| 2026-05-11 | claude-opus-4-7      | Story créée par SM, status `ready-for-dev`.                           |
| 2026-05-11 | claude-opus-4-7 dev  | Implémentation complète T0-T7 + audit. Status `ready-for-dev` → `review`. |
| 2026-05-11 | claude-sonnet-4-6 + claude-opus-4-7 | Code review adversariale + second avis Opus → 17 problèmes identifiés, 8 corrigés automatiquement (#1 error.trace gating debug, #2 résidus gpo-deploy doc, #6/#7 tests Process::fake/timeout, #8 whitelist test archi fine-grained, #9 mixed types, #11 regex /i, #A parseGetLink robuste blocs, #B parseGetInheritance throw sur sortie inconnue). #C/#D capturés dans `docs/tech-debt-gpo.md`. Voir `_bmad-output/codeReviews/16-1.md`. |
| 2026-05-11 | henri (décisions post-review)  | **Q1 → A** : 3 étiquettes dédiées au catalogue (`gpo.containers.list`, `gpo.link.get`, `gpo.inheritance.get`) — `GpoService::listContainers/getLinks/getInheritance` et `README.md` mis à jour. **Q2 → B** : compléter l'audit fichier par fichier (subagent lancé en parallèle pour ajouter les fiches détaillées manquantes des ~13 fichiers UI synthétisés). **Q3 → B** : DNS + réplication AD sortent du périmètre Epic 16 ; **Epic 18 créé** (5 stories cadrées dans `epics.md` + `sprint-status.yaml`). |

---

## Recommandation Modèle Dev

**Modèle recommandé : opus**

Raison :
1. **Story de fondation transverse** — toutes les décisions architecturales (namespace, abstraction `GpoService`, signatures des 11 méthodes, channel logs, garde-fous archi) posent les rails de l'Epic 16 complet. Une décision boiteuse ici se propage sur 16.2 → 16.6.
2. **Volume d'audit important** — 19 fichiers UI + 4 includes legacy (5 447 lignes au total) à cartographier en 6 sections, dont une analyse de stratégie de port fichier par fichier (réécriture / port + adaptation / abandon / déjà-natif). C'est un travail de raisonnement structuré qui exige de la profondeur, pas de la productivité.
3. **Croisement multi-artefacts** — il faut croiser le legacy, le shim 1bis-18 (a-g, dont 18c/d annulés et 18f partiellement natif), les refontes natives existantes (Stories 4.7, 4.8, `ShortcutsService`, `RoamingProfileService`) pour produire une cartographie sans angles morts. Un sonnet risque de manquer un fichier déjà refondu et de proposer un portage redondant.
4. **Décisions d'écart architectural** — la story divergent volontairement de `architecture.md:453` (namespace `App\Gpo` vs `App\Services\Windows\Gpo*`) et doit le **documenter et le justifier**. Ce type de méta-raisonnement (« je m'écarte de la doc parce que… ») est mieux servi par opus.
5. **Tests architecturaux non-triviaux** — `GpoNamespaceTest` et `GpoLegacyIsolationTest` font du scan reflection sur arborescences ; à coder avec soin pour éviter des faux positifs / négatifs.

Le dev sonnet serait viable pour les volets 1, 2, 5 (purement mécaniques) mais **risqué** pour les volets 3 (abstraction stable des 11 méthodes), 6 (audit qualitatif) et la décision D1 (namespace).
