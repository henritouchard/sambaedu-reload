# Story 17.2 : Portage moteur `applications.php` — whitelist étendue 8→14 clés + parité bytes + intégration WrapperScriptRenderer

Status: done

<!-- Note: Validation est optionnelle. Lancer validate-create-story pour quality check avant dev-story. -->

> **Story de compatibilité runtime post-audit 17.1 validé** (Henri 2026-05-21).
> Étend la Story 16.7 (squelette `ApplicationScriptsGenerator`/`Assembler`/`Scanner`/`Controller` + whitelist initiale 8 clés livrée done 2026-05-13) avec les 3 manques identifiés par l'audit `_bmad-output/planning-artifacts/audit-applications-scripts.md` :
>
> 1. **Élargissement whitelist substitutions de 8 → 14 clés** (Section B audit) — bloquant parc-wide : sans cette extension, les scripts `firewall/startup.windows`, `folders/clean_profiles`, `glpi/startup.linux`, `wallpaper/logon.windows`, `wine/startup.linux` produiraient des `.cmd`/`.sh` avec placeholders littéraux non substitués → règles netsh cassées, suppression du dossier admin local, config GLPI Agent invalide, etc.
> 2. **Audit de parité bytes legacy vs natif** (Section G.1 audit, AC4 verbatim) — la Story 16.7 a livré le squelette mais sans test de parité strict byte-à-byte ; 17.2 verrouille cette parité.
> 3. **Intégration `WrapperScriptRenderer` (16.12)** dans `ApplicationScriptsAssembler` — couplé avec Story 17.5 qui livre le flag opt-in `config('sambaedu.scripts.logging.enabled')` + commandes artisan. 17.2 pose le pipeline ; 17.5 active le flag.
>
> **Aucune nouvelle UI, aucun nouveau modèle Eloquent, aucune migration.** Strictement infra (config + service modifié + tests).

---

## ⚠️ Décisions tranchées (D1-D8, ne pas re-débattre)

> Ces décisions sont issues de l'audit 17.1 validé par Henri 2026-05-21 et des conventions Story 16.7 D3 (whitelist statique sérialisable `config:cache`).

### D1 — Whitelist : extension de 8 → 14 clés (parité Section B audit)

> **Note** : l'audit Section B annonce « 6 nouvelles clés » dans le texte mais en liste **8** dans le tableau (Section B et G.1). C'est le tableau qui fait foi → **8 clés ajoutées**, total 8 + 8 = **16 clés**. La description sprint-status annonce « 8→14 » : on retient le tableau (16). Le différentiel d'une clé vs « 14 » sera tracé en Completion Notes pour Henri (cf. Question Ouverte Q-1 en fin de story).

Les 8 nouvelles clés à ajouter à `config/sambaedu.gpo.applications.substitutions.whitelist` :

| Placeholder upstream | Clé whitelist | Scripts consommateurs (audit Section A) |
|---|---|---|
| `###_ADMINSE_NAME_###` | `ADMINSE_NAME` | `folders/clean_profiles` (1 script — risque bloquant : suppression admin local) |
| `###_DHCP_MASQUE_###` | `DHCP_MASQUE` | `firewall/startup.windows` |
| `###_DHCP_RESEAU_###` | `DHCP_RESEAU` | `firewall/startup.windows` |
| `###_GLPI_URL_###` | `GLPI_URL` | `glpi/startup.linux` |
| `###_NO_INTERNET_###` | `NO_INTERNET` | `firewall/startup.windows`, `firewall/logon-system.windows` |
| `###_SE4AD_IP_###` | `SE4AD_IP` | `firewall/startup.windows` |
| `###_SE4FS_IP_###` | `SE4FS_IP` | `firewall/startup.windows` |
| `###_SE4INSTALL_NAME_###` | `SE4INSTALL_NAME` | `wallpaper/logon.windows`, `wine/startup.linux` |

### D2 — Mapping source par clé (résolution Eloquent / config / env / SambaEduConfig)

> **Pattern Story 16.7** : `['config' => '...', 'env' => '...', 'default' => '...']` — résolution config() → env() → default. Sérialisable pour `php artisan config:cache`.

| Clé | Spec à inscrire dans la whitelist | Justification |
|---|---|---|
| `ADMINSE_NAME` | `['config' => 'sambaedu.windows.adminse_name', 'env' => 'SAMBAEDU_ADMINSE_NAME', 'default' => 'adminse']` | Déjà déclaré `config/sambaedu.php:213` (Story 3.5). Le default `'adminse'` évite le risque bloquant Section A audit (suppression dossier admin local si vide). |
| `SE4AD_IP` | `['config' => 'sambaedu.se4ad_ip', 'env' => 'SE4AD_IP']` | Déjà déclaré `config/sambaedu.php:75`. Si vide → placeholder laissé inchangé (script firewall casserait, mais c'est de l'erreur opérateur côté config — visible). |
| `SE4FS_IP` | `['config' => 'sambaedu.se4fs_ip', 'env' => 'SE4FS_IP']` | Déjà déclaré `config/sambaedu.php:176` (Story 3.4/3.5). |
| `SE4INSTALL_NAME` | `['config' => 'sambaedu.se4install_name', 'env' => 'SE4INSTALL_NAME']` | Déjà déclaré `config/sambaedu.php:179` (Story 3.5). |
| `GLPI_URL` | `['config' => 'sambaedu.glpi_url', 'env' => 'SAMBAEDU_GLPI_URL', 'default' => '']` | **Nouvelle clé** : ajouter `'glpi_url' => env('SAMBAEDU_GLPI_URL', '')` à `config/sambaedu.php` (au niveau racine). Pas de modèle Eloquent dédié (config statique par établissement, lue à l'install paquet). |
| `NO_INTERNET` | `['config' => 'sambaedu.no_internet', 'env' => 'SAMBAEDU_NO_INTERNET', 'default' => '']` | **Nouvelle clé** : ajouter `'no_internet' => env('SAMBAEDU_NO_INTERNET', '')` à `config/sambaedu.php` (legacy `$config['no_internet']` = nom de groupe AD type `pasInternet` cf. `user.interface.inc.php:409-410`). String vide par défaut → comportement iso-legacy (la condition `IF NOT [###_NO_INTERNET_###]==[]` du firewall/logon-system.windows devient `IF NOT []==[]` → faux → pas d'appel à `no_internet.ps1`, comportement attendu si aucun groupe configuré). |
| `DHCP_RESEAU` | `['config' => 'sambaedu.dhcp_reseau', 'env' => 'SAMBAEDU_DHCP_RESEAU', 'default' => '']` | **Nouvelle clé** : ajouter `'dhcp_reseau' => env('SAMBAEDU_DHCP_RESEAU', '')` à `config/sambaedu.php`. **Cas multi-VLAN documenté** (`MachinePowerService.php:481` : legacy lit `dhcp_reseau_0`, `dhcp_reseau_1`, ...) — non couvert par cette substitution simple : le script `firewall/startup.windows` utilise UNE seule valeur. Justification : iso-legacy `applications.inc.php` consomme également la variable simple `$config['dhcp_reseau']` (pas l'indexée). Si terrain multi-VLAN remonte → cf. Question Ouverte Q-2. |
| `DHCP_MASQUE` | `['config' => 'sambaedu.dhcp_masque', 'env' => 'SAMBAEDU_DHCP_MASQUE', 'default' => '']` | **Nouvelle clé** : symétrique à `DHCP_RESEAU`. Idem Q-2 pour multi-VLAN. |

> **Pourquoi pas de query Eloquent ?** L'audit Q2 (Section « Réponses Henri 2026-05-21 ») défère l'arbitrage au dev. Décision SM : **toutes les nouvelles clés sont des constantes serveur globales** (par établissement, par instance SE4FS). Aucune n'est computée à la volée par poste/utilisateur. La chaîne `config() → env()` du pattern 16.7 est donc la bonne abstraction. Aucune raison de surcoupler à Eloquent (qui introduirait une dépendance DB sur chaque génération de script → régression performance).

### D3 — Source `.env` vs `SambaEduConfig` (lecture INI `/etc/sambaedu/sambaedu.conf`) : **`.env` exclusivement** (parité 16.7 D3)

- La whitelist 16.7 ne consomme **jamais** `SambaEduConfig`. Idem ici.
- Si terrain veut piloter par INI `/etc/sambaedu/sambaedu.conf`, un wiring `env → config` est fait par le packaging Debian (`postinst` du paquet `sambaedu-reload` écrit `.env` depuis l'INI). Hors scope 17.2 — relève du packaging.

### D4 — Parité bytes legacy vs natif (Section G.1 audit + AC4 16.7 verbatim) : **test fixture-driven obligatoire**

- Ajouter un test `tests/Feature/Gpo/ApplicationsScriptsByteParityTest.php` (nouveau) qui :
  1. Prépare un contexte `$info` minimal (machine, user, action, os) iso-legacy.
  2. Génère le `.cmd` (ou `.sh`) via `ApplicationScriptsAssembler::assemble()`.
  3. Charge une fixture `tests/Fixtures/Gpo/applications/<scenario>/expected.cmd` produite manuellement par exécution du legacy `gpo/applications.php` sur VM (référence one-shot, capture binaire incluse `\r\n`).
  4. Compare **strictement byte-à-byte** (`assertSame($expected, $actual)` après normalisation timestamps si présents).
- **3 scénarios fixtures minimum** : (a) Windows logon utilisateur standard, (b) Windows startup machine avec `firewall` activé (force la substitution des 5 nouveaux placeholders firewall), (c) Linux startup machine avec `glpi` (force `GLPI_URL`).
- **Capture des fixtures** : la procédure exacte (commandes curl à exécuter sur VM legacy) est documentée en `tests/Fixtures/Gpo/applications/README.md` (nouveau). Le dev 17.2 capture ces fixtures **avant** le passage en review.
- **Tolérance acceptable** : les timestamps `MachineBootLog` ou IDs auto-générés sont normalisés (regex replace `id=[0-9a-f]{32}` → `id=__ID__`) avant comparaison. Aucune autre divergence n'est tolérée.

### D5 — Intégration `WrapperScriptRenderer` : **wrapper OPT-IN**, contrôle via flag (couplé 17.5)

- 17.2 livre le **point d'intégration** : `ApplicationScriptsAssembler::assemble()` interroge `config('sambaedu.scripts.logging.enabled', false)` après `applySubstitutions()`. Si `true`, chaque interpréteur (`cmd`/`bash`) est passé au `WrapperScriptRenderer::wrap()` avant retour.
- 17.5 livre les **commandes artisan** `winscript-logs:enable` / `winscript-logs:disable` qui flip le flag — hors scope 17.2.
- Tests Feature 17.2 : `ApplicationsScriptsWrapperIntegrationTest` (nouveau) — 2 cas :
  1. Flag `false` (défaut iso-legacy) → sortie inchangée (parité bytes D4 préservée).
  2. Flag `true` → sortie wrappée (assertion : contient `Invoke-RestMethod` pour Windows / `curl -fsS -X POST` pour Linux + correlation_id UUID).
- **Source enum pour wrap()** : `ScriptExecutionSource::GPO_APPLICATIONS` (cf. `app/ScriptsOs/Enums/ScriptExecutionSource.php:21`) — pas `MANAGED_SCRIPT` (lui réservé Stories 17.x suivantes pour scripts résolus par modèle Eloquent).
- **Action enum** : projection depuis `$info['action']` :
  - `logon` / `logoff` / `startup` / `shutdown` → enum case correspondant.
  - `logon-system` / `logoff-system` / `wpkg` → mapper sur `ScriptExecutionAction::STARTUP` (cas non décrits dans l'enum 16.12 D1 ; alternative = laisser hors wrapping, mais cela exclut 50 % des scripts critiques). Décision SM : projetter `logon-system` → `STARTUP` (cohérent : exécution en contexte SYSTEM = startup-like) et `wpkg` → `STARTUP`. Documenter dans Dev Notes.
- **OS enum** : `'windows'` → `ScriptExecutionOs::WINDOWS`, `'linux'` → `ScriptExecutionOs::LINUX`. Les interpréteurs `powershell` / `apt` / `server` ne sont **pas wrappés** (le wrapping s'applique uniquement à `cmd` et `bash`).
- **scriptId** : `null` (pas de FK vers `windows_scripts` / `linux_scripts` — ces modèles n'existent pas et l'audit 17.1 a recadré Epic 17 hors « scripts managés »).

### D6 — Pas de réécriture du squelette 16.7 — extension uniquement

- L'`ApplicationScriptsAssembler` (`app/Gpo/Services/ApplicationScriptsAssembler.php`, 973 lignes) reste structurellement inchangé. Modifications **chirurgicales** :
  - Ajout d'un branchement post-`applySubstitutions()` pour wrapper opt-in (D5).
  - **Aucune** modification de `headerScripts()`, `footerScripts()`, `redirectScripts()`, etc.
- L'`ApplicationScriptsGenerator` / `ApplicationTemplatesScanner` / `ApplicationsScriptsController` : aucune modification attendue. Si le dev identifie un cas où la nouvelle whitelist exige une adaptation Generator (très peu probable — le Generator résout le contexte AD, pas les substitutions), documenter en Dev Notes.

### D7 — Logs warnings pour placeholders non whitelistés : **conserver le comportement 16.7**

- `applySubstitutions()` log déjà `[ApplicationScriptsAssembler] unwhitelisted substitution keys ignored` sur channel `daily`. Conservé.
- Après extension whitelist (D1+D2), ce warning **ne doit plus jamais apparaître** pour les 11 placeholders identifiés par l'audit Section B. Test régression : un cas qui consomme tous les placeholders + assert log channel `daily` vide (ou ne contient pas la chaîne `unwhitelisted`).

### D8 — Aucune migration, aucun modèle Eloquent, aucune nouvelle route, aucune UI

- Strict scope infra : config + service modifié + tests + fixtures + doc.
- Une nouvelle entrée `tests/Fixtures/Gpo/applications/README.md` (procédure capture fixtures sur VM) est l'unique nouvel artefact non-code.

---

## Story

As **un poste client (Windows ou Linux) joint au domaine SE4FS**,
I want
- que le moteur natif `ApplicationScriptsAssembler` (Story 16.7) substitue **correctement les 11 placeholders `###_PARAM_###`** réellement consommés par les scripts upstream du package Debian `sambaedu`, dont les 8 nouveaux placeholders absents de la whitelist initiale (`ADMINSE_NAME`, `DHCP_MASQUE`, `DHCP_RESEAU`, `GLPI_URL`, `NO_INTERNET`, `SE4AD_IP`, `SE4FS_IP`, `SE4INSTALL_NAME`) ;
- que la sortie du moteur natif soit **strictement byte-identique** au legacy `gpo/applications.php` pour les mêmes paramètres d'entrée ;
- que l'enveloppe de logging (`WrapperScriptRenderer` livrée par 16.12) puisse être activée **sans modification de l'Assembler** par un simple flag config, prête pour la bascule opt-in pilotée par Story 17.5,

So que (a) les ~6 scripts critiques identifiés par l'audit 17.1 (Section A « risques bloquants ») fonctionnent iso-legacy sur le parc (pas de règle netsh cassée, pas de suppression du dossier admin local, pas de config GLPI Agent invalide) ; (b) la régression bytes vs legacy soit détectable par un test CI dédié ; (c) le pipeline logs `script_execution_logs` (Story 16.12 done) puisse être branché par 17.5 sans dette technique supplémentaire.

---

## Contexte

### Position dans l'Epic 17 post-RESET

L'Epic 17 a été **recadré 2026-05-14** (cf. Story 17.1) : il n'est plus question d'éditeur Monaco ni de modèles Eloquent `WindowsScript`/`LinuxScript`. Le périmètre Epic 17 est **compatibilité runtime** des ~80 scripts versionnés par le package Debian `sambaedu` après remplacement du legacy par Epic 15 (WPKG natif) + Epic 16 (GPO natives).

La Story 17.1 (audit done 2026-05-21) a identifié **3 manques bloquants** dans le squelette livré par 16.7 : (1) whitelist trop courte (8/11), (2) absence de test parité bytes, (3) pas d'intégration wrapper logs. Cette Story 17.2 traite les 3.

### Découpage Epic 17 (validé Henri 2026-05-21, Section G.6 audit)

| Story | Statut | Charge | Périmètre |
|---|---|---|---|
| 17.1 — Audit scripts Windows & Linux | ✅ done 2026-05-21 | 1-2j | Livrable markdown 1700 lignes |
| **17.2 (cette story) — Moteur applications.php + whitelist + parité + wrapper** | **À faire** | **2-3j** | **3 volets D1-D8 ci-dessus** |
| 17.3 — Compat GPO orchestratrice `se4_applications` (Stratégie A) | backlog | ~1j | Vérif template `.cmd` orchestrateurs |
| 17.4 — Tests intégration runtime VM | backlog | 2j | E2E 5 scripts critiques |
| 17.5 — Activation wrapper opt-in (config + artisan) | backlog | ~1j | Flag + commandes `winscript-logs:enable`/`disable` |
| 17.6 — Portage endpoints orphelins (`linux_out`, `winget_out`) | backlog | ~2.5-3j | 2 controllers natifs |

### Dépendances satisfaites

| Story / Epic | Statut | Apport pour 17.2 |
|---|---|---|
| **17.1** — Audit | ✅ done 2026-05-21 | Section B cartographie exhaustive des 11 placeholders + sources legacy. Section G.1 cadrage 17.2. |
| **16.7** — Portage natif `applications.php` | ✅ done (review 2026-05-13) | Squelette `ApplicationScriptsAssembler` + whitelist initiale 8 clés + pattern résolution config()→env()→default + test architecture iso-bytes. |
| **16.12** — Logs d'exécution centralisés | ✅ done | `WrapperScriptRenderer::wrap()` + templates Blade `wrapper-{cmd,sh}.blade.php` + enums `ScriptExecutionAction/Os/Source`. **Signature stable**, prête à consommer. |
| Epic 4 | done | `Workstation`, `WorkstationGroup`, `User` (référencés par l'Assembler pour `localAdminScripts`). |

**Conclusion** : toutes dépendances ✅. Story 17.2 peut démarrer immédiatement.

### Frontières (anti-scope creep)

| HORS scope 17.2 | Renvoi |
|---|---|
| Activation du flag wrapper en production / commandes artisan | 17.5 |
| Vérification que les `.cmd` orchestrateurs du template GPO `se4_applications` pointent sur API v1 (et non `gpo/applications.php` legacy) | 17.3 |
| Tests E2E runtime VM sur les 5 scripts critiques | 17.4 |
| Portage `wpkg/linux_out.php` / `wpkg/winget_out.php` | 17.6 |
| UI consultation logs scripts | 16.12 done (UI sous `/admin/settings/scripts-logs`) |
| Création modèle Eloquent `WindowsScript` / `LinuxScript` | Hors Epic 17 (recadré) |
| Modification du contenu des fragments scripts upstream | Hors scope — c'est versionné par le paquet Debian |

---

## Acceptance Criteria

> 11 ACs organisés en **4 volets** : (1) extension whitelist, (2) parité bytes legacy vs natif, (3) intégration wrapper opt-in, (4) régression iso-bytes Story 16.7.

### Volet 1 — Élargissement whitelist substitutions (D1+D2)

**AC1.1 — 8 nouvelles clés ajoutées dans `config/sambaedu.gpo.applications.substitutions.whitelist`**
**Given** le fichier `config/sambaedu.php` dans le tableau `gpo.applications.substitutions.whitelist`
**When** la story est complète
**Then** les **8 clés** `ADMINSE_NAME`, `DHCP_MASQUE`, `DHCP_RESEAU`, `GLPI_URL`, `NO_INTERNET`, `SE4AD_IP`, `SE4FS_IP`, `SE4INSTALL_NAME` sont présentes
**And** chaque clé a la spec exacte définie en D2 (config → env → default)
**And** les 8 clés existantes (`SE4FS_NAME`, `DOMAIN`, `UAI`, `NETLOGON_PATH`, `WPKG_URL`, `SAMBA_DOMAIN`, `TMP_DIR`, `CLOUD_PERSO_NAME`) restent inchangées
**And** chaque nouvelle clé est précédée d'un commentaire `// Iso-legacy <source>` (parité 16.7) référençant le ou les scripts consommateurs identifiés par l'audit Section B.

**AC1.2 — Nouvelles entrées `config/sambaedu.php` racine pour `glpi_url`, `no_internet`, `dhcp_reseau`, `dhcp_masque`**
**Given** que les 4 clés `GLPI_URL`, `NO_INTERNET`, `DHCP_RESEAU`, `DHCP_MASQUE` n'existent pas encore au niveau racine de `config/sambaedu.php`
**When** la whitelist est étendue (AC1.1)
**Then** 4 nouvelles entrées racine sont ajoutées :
- `'glpi_url' => env('SAMBAEDU_GLPI_URL', '')` (proche de `'se4fs_ip'` ligne 176)
- `'no_internet' => env('SAMBAEDU_NO_INTERNET', '')` (groupe AD nom — string)
- `'dhcp_reseau' => env('SAMBAEDU_DHCP_RESEAU', '')` (IP réseau simple — multi-VLAN non géré ici, cf. Q-2)
- `'dhcp_masque' => env('SAMBAEDU_DHCP_MASQUE', '')` (masque sous-réseau simple)
**And** chaque entrée a un commentaire iso-legacy référençant le ou les scripts upstream consommateurs (Section A audit).

**AC1.3 — Test substitution unitaire pour les 8 nouvelles clés**
**Given** un template chaîne contenant `###_KEY_###` pour chacune des 8 nouvelles clés
**When** `ApplicationScriptsAssembler::applySubstitutions()` est appelé avec `config()->set('sambaedu.<key>', 'VALEUR_TEST_<key>')` (et fallback `env()` pour `DHCP_*` / `GLPI_URL` / `NO_INTERNET`)
**Then** chaque placeholder est remplacé par la valeur configurée
**And** un placeholder `###_INCONNU_###` reste inchangé + log warning channel `daily` (D7 régression)
**And** le test couvre les 3 chemins de résolution : (a) config seul, (b) env fallback si config null, (c) default fallback si les deux null.

### Volet 2 — Parité bytes legacy vs natif (D4)

**AC2.1 — Fixture procédure documentée**
**Given** la nécessité de capturer des sorties legacy de référence
**When** le dev capture les fixtures
**Then** un fichier `tests/Fixtures/Gpo/applications/README.md` (nouveau) documente la procédure exacte :
- commandes `curl` à exécuter sur VM legacy (ssh `/vm`) pour reproduire 3 scénarios (a) Windows logon user standard, (b) Windows startup machine avec firewall, (c) Linux startup machine avec glpi
- variables d'environnement / params POST attendus
- normalisation timestamps / IDs (regex `id=[0-9a-f]{32}` → `id=__ID__`)
- date de capture + commit ID `sambaedu` (legacy) au moment de la capture
**And** les **3 fichiers fixture** sont créés :
- `tests/Fixtures/Gpo/applications/windows_logon_user/expected.cmd`
- `tests/Fixtures/Gpo/applications/windows_startup_firewall/expected.cmd`
- `tests/Fixtures/Gpo/applications/linux_startup_glpi/expected.sh`

> **Note worktree-safe** : si le dev est dans un worktree git, il NE doit PAS exécuter les commandes SSH sur la VM. Le dev doit demander à Henri de fournir les 3 fixtures (Henri exécute sur VM ou délègue). Cf. memory `feedback_worktree_no_vm_sync.md`.

**AC2.2 — Test de parité bytes**
**Given** les 3 fixtures (AC2.1)
**When** `tests/Feature/Gpo/ApplicationsScriptsByteParityTest.php` (nouveau) est exécuté
**Then** pour chaque scénario :
- Un contexte `$info` identique au scénario fixture est construit (mêmes machine/user/action/os/parcs/list)
- `ApplicationScriptsAssembler::assemble($info, $scripts)` est appelé
- La sortie de l'interpréteur attendu (`cmd` ou `bash`) est récupérée
- **`assertSame($expected, $actual)` après normalisation des seuls timestamps/IDs**
**And** **aucune autre divergence** n'est tolérée (CR/LF, charset, séparateurs `REM script[...]\r\n`).
**And** en cas d'échec, le diff est affiché ligne-par-ligne pour faciliter debug (helper `assertScriptParity()` à créer).

**AC2.3 — Couverture des 8 nouvelles substitutions par les fixtures**
**Given** les 3 fichiers fixture (AC2.1)
**When** on inspecte leur contenu
**Then** **au moins une occurrence substituée** de chacune des 8 nouvelles clés apparaît dans les fixtures (le scénario `windows_startup_firewall` couvre les 5 placeholders firewall ; `linux_startup_glpi` couvre `GLPI_URL` ; un scénario doit aussi couvrir `ADMINSE_NAME` et `SE4INSTALL_NAME` — soit ajouter un 4e scénario, soit enrichir scénario (a) avec les apps `folders` + `wallpaper`).
**And** si un cas reste non couvert, ajouter une fixture supplémentaire OU un test unitaire dédié (au choix du dev, justifier en Dev Notes).

### Volet 3 — Intégration `WrapperScriptRenderer` opt-in (D5)

**AC3.1 — Branchement opt-in dans `assemble()`**
**Given** `ApplicationScriptsAssembler::assemble()`
**When** la méthode est appelée et `config('sambaedu.scripts.logging.enabled', false)` retourne `true`
**Then** chaque interpréteur **`cmd`** et **`bash`** est passé au service injecté `WrapperScriptRenderer::wrap(string $scriptContent, ScriptExecutionAction $action, ScriptExecutionOs $os, ?int $scriptId = null, ScriptExecutionSource $source = ScriptExecutionSource::GPO_APPLICATIONS)` AVANT retour.
**And** les interpréteurs `powershell`, `apt`, `server` ne sont **pas** wrappés (justifié D5).
**And** le `WrapperScriptRenderer` est injecté par DI dans le constructeur de l'Assembler (ajout d'un paramètre optionnel `?WrapperScriptRenderer $wrapper = null` — rétro-compat constructeurs existants).
**And** la projection action enum est : `logon`/`logoff`/`startup`/`shutdown` → enum case correspondant ; `logon-system`/`logoff-system`/`wpkg` → `ScriptExecutionAction::STARTUP` (documenté Dev Notes).

**AC3.2 — Default flag = `false` (no-op iso-legacy)**
**Given** aucune entrée `config('sambaedu.scripts.logging.enabled')` n'est définie dans `.env` ou config
**When** `assemble()` est appelée
**Then** `config('sambaedu.scripts.logging.enabled', false)` retourne `false`
**And** la sortie est **identique iso-legacy** (parité bytes Volet 2 préservée — c'est testé par `ApplicationsScriptsByteParityTest` exécuté avec flag à false par défaut).

**AC3.3 — Test Feature intégration wrapper**
**Given** `tests/Feature/Gpo/ApplicationsScriptsWrapperIntegrationTest.php` (nouveau)
**When** le test exécute 2 scénarios :
1. Flag `false` → sortie ne contient PAS `Invoke-RestMethod` / `curl -fsS -X POST`.
2. Flag `true` → sortie contient `Invoke-RestMethod` (Windows) OU `curl -fsS -X POST` (Linux) + un correlation_id UUID v4.
**Then** les 2 scénarios passent.
**And** le test mock le `WrapperScriptRenderer` si besoin pour fixer le `correlation_id` (sinon assertion regex sur format UUID).

### Volet 4 — Régression iso-bytes Story 16.7 (D7+D8)

**AC4.1 — Test régression placeholders inconnus**
**Given** un template avec un placeholder hors whitelist (ex. `###_INVENTE_###`)
**When** `applySubstitutions()` est appelée
**Then** le placeholder reste inchangé dans la sortie
**And** un warning est loggé sur channel `daily` avec message `[ApplicationScriptsAssembler] unwhitelisted substitution keys ignored` et clé `INVENTE` (régression 16.7).

**AC4.2 — Aucun warning pour les 11 placeholders connus de l'audit**
**Given** un template contenant les 11 placeholders identifiés Section B audit (`SE4FS_NAME`, `DOMAIN`, `UAI`, `ADMINSE_NAME`, `DHCP_MASQUE`, `DHCP_RESEAU`, `GLPI_URL`, `NO_INTERNET`, `SE4AD_IP`, `SE4FS_IP`, `SE4INSTALL_NAME`) chacun au moins une fois
**When** `applySubstitutions()` est appelée avec configuration complète (config:set toutes les clés)
**Then** AUCUN warning `unwhitelisted` n'est émis sur channel `daily` (vérification via `Log::shouldReceive()` mock OU lecture log path testing).

**AC4.3 — Tests existants 16.7 passent toujours**
**Given** la suite de tests `tests/Unit/Gpo/ApplicationScriptsAssemblerTest.php` + `tests/Feature/Gpo/Application*Test.php` (héritée 16.7)
**When** la suite est exécutée après les modifications 17.2
**Then** **100 %** des tests passent (aucune régression sur le squelette).

**AC4.4 — Pas de nouvelle migration / route / UI**
**Given** la branche de la story
**When** `git diff --name-only main...` est inspecté
**Then** aucun fichier sous `database/migrations/`, `routes/`, `resources/views/pages/`, `app/Models/` n'est créé.
**And** seuls peuvent être créés/modifiés : `config/sambaedu.php`, `app/Gpo/Services/ApplicationScriptsAssembler.php`, `tests/**/Gpo/**`, `tests/Fixtures/Gpo/applications/**`.

---

## Tasks / Subtasks

### Phase T0 — Investigation préalable (~1-2h)

- [x] **T0.1** Lire intégralement la Section B + G.1 + Section A audit `_bmad-output/planning-artifacts/audit-applications-scripts.md` (Henri demande la rigueur factuelle ; en cas d'incohérence audit vs réalité du code, **l'audit fait foi** sauf erreur évidente — documenter en Completion Notes).
- [x] **T0.2** Lire `config/sambaedu.php` lignes 411-490 (whitelist 16.7) + Story 16.7 D3 dans `_bmad-output/implementation-artifacts/16-7-portage-natif-applications-php.md`.
- [x] **T0.3** Lire `app/Gpo/Services/ApplicationScriptsAssembler.php::applySubstitutions()` (ligne 873) + `::resolveSubstitutionValue()` (ligne 913) + `::loadWhitelist()` (ligne 961).
- [x] **T0.4** Lire `app/ScriptsOs/Services/WrapperScriptRenderer.php::wrap()` (signature stable, enums `ScriptExecutionAction/Os/Source`).
- [x] **T0.5** Vérifier l'absence pré-existante des entrées `glpi_url`/`no_internet`/`dhcp_reseau`/`dhcp_masque` à la racine de `config/sambaedu.php` (AC1.2). Si présentes — adapter, ne pas dupliquer.

### Phase T1 — Extension whitelist + nouvelles entrées config (AC1.1+AC1.2 — ~1h)

- [x] **T1.1** Ajouter à `config/sambaedu.php` (au niveau racine, proche ligne 176-179) :
  - `'glpi_url' => env('SAMBAEDU_GLPI_URL', '')` + commentaire iso-legacy
  - `'no_internet' => env('SAMBAEDU_NO_INTERNET', '')` + commentaire (groupe AD legacy `user.interface.inc.php:409`)
  - `'dhcp_reseau' => env('SAMBAEDU_DHCP_RESEAU', '')` + commentaire (cas multi-VLAN documenté Q-2)
  - `'dhcp_masque' => env('SAMBAEDU_DHCP_MASQUE', '')` + commentaire (symétrique)
- [x] **T1.2** Ajouter à `config/sambaedu.php` dans `gpo.applications.substitutions.whitelist` (après `CLOUD_PERSO_NAME` ligne 487), dans l'ordre alphabétique idéalement :
  - `'ADMINSE_NAME' => ['config' => 'sambaedu.windows.adminse_name', 'env' => 'SAMBAEDU_ADMINSE_NAME', 'default' => 'adminse']`
  - `'DHCP_MASQUE' => ['config' => 'sambaedu.dhcp_masque', 'env' => 'SAMBAEDU_DHCP_MASQUE', 'default' => '']`
  - `'DHCP_RESEAU' => ['config' => 'sambaedu.dhcp_reseau', 'env' => 'SAMBAEDU_DHCP_RESEAU', 'default' => '']`
  - `'GLPI_URL' => ['config' => 'sambaedu.glpi_url', 'env' => 'SAMBAEDU_GLPI_URL', 'default' => '']`
  - `'NO_INTERNET' => ['config' => 'sambaedu.no_internet', 'env' => 'SAMBAEDU_NO_INTERNET', 'default' => '']`
  - `'SE4AD_IP' => ['config' => 'sambaedu.se4ad_ip', 'env' => 'SE4AD_IP']`
  - `'SE4FS_IP' => ['config' => 'sambaedu.se4fs_ip', 'env' => 'SE4FS_IP']`
  - `'SE4INSTALL_NAME' => ['config' => 'sambaedu.se4install_name', 'env' => 'SE4INSTALL_NAME']`
- [x] **T1.3** Chaque entrée précédée d'un bloc commentaire iso-16.7 : 1 ligne de description fonctionnelle + référence audit Section A (script(s) consommateur(s)) + référence legacy si applicable.
- [x] **T1.4** Lancer `php artisan config:cache` puis `php artisan config:clear` pour vérifier que la whitelist reste sérialisable (D3 conservé).
- [x] **T1.5** `php -l config/sambaedu.php` doit retourner `No syntax errors`.

### Phase T2 — Tests unitaires whitelist étendue (AC1.3+AC4.1+AC4.2 — ~1h)

- [x] **T2.1** Étendre `tests/Unit/Gpo/ApplicationScriptsAssemblerTest.php` (existant 16.7) avec :
  - `it_substitutes_all_8_new_whitelist_keys_via_config()` — set 8 valeurs via `config()->set()`, applique template avec 8 placeholders, assert 8 substitutions.
  - `it_falls_back_to_env_when_config_null()` — couvre les 4 clés avec spec `env` (`DHCP_*`, `GLPI_URL`, `NO_INTERNET` — vérifier que `putenv()` ou `Env::getRepository()->set()` est utilisé pour piloter env en test).
  - `it_falls_back_to_default_when_config_and_env_null()` — couvre `ADMINSE_NAME` (default `'adminse'`) + `DHCP_RESEAU` (default `''`).
  - `it_keeps_unknown_placeholders_unchanged_and_logs_warning()` — `###_INVENTE_###` reste, log channel `daily` reçoit warning avec clé `INVENTE` (mock `Log::channel('daily')->shouldReceive('warning')`).
  - `it_does_not_warn_on_the_11_known_placeholders()` — template avec les 11 placeholders, assert `Log::shouldReceive('warning')->never()`.
- [x] **T2.2** Tous les tests passent (`php artisan test --filter ApplicationScriptsAssembler`).

### Phase T3 — Capture fixtures parité bytes (AC2.1 — ~2-3h, **bloquant Henri si worktree**)

- [x] **T3.1** Créer `tests/Fixtures/Gpo/applications/README.md` documentant la procédure :
  - **Section « Procédure de capture »** : commandes `curl -X POST` exactes à exécuter sur VM legacy, headers `Content-Type: multipart/form-data`, params POST par scénario.
  - **Section « Scénarios »** : 3 sous-sections (windows_logon_user / windows_startup_firewall / linux_startup_glpi) avec contexte machine/user/parcs/list attendu + commande curl exacte + nom du fichier expected.
  - **Section « Normalisation »** : regex de remplacement avant comparaison (`id=[0-9a-f]{32}` → `id=__ID__`, et `^.*started_at.*$\n` → `` si présent).
  - **Section « Date de capture »** : à remplir par le dev après capture (date YYYY-MM-DD + commit `sambaedu` HEAD).
- [x] **T3.2** **Décision worktree-safe** :
  - **Si dev en worktree** (cf. CLAUDE.md « pas d'interaction VM ») → demander à Henri de capturer les 3 fixtures + livrer les fichiers `expected.cmd` / `expected.sh` dans `tests/Fixtures/Gpo/applications/<scenario>/`. Pause de la story tant que fixtures absentes.
  - **Si dev hors worktree** (branche main) → exécuter les `curl` sur `/vm` selon T3.1, capturer les sorties, normaliser, committer.
- [x] **T3.3** Vérifier que les 3 fixtures contiennent au moins une occurrence substituée de chacune des 8 nouvelles clés (AC2.3). Si une clé manque, ajouter un 4e scénario ou enrichir un scénario existant (justifier en Dev Notes).
- [x] **T3.4** **Risque connu** : si le legacy `applications.php` est en panne sur la VM ou retourne 500 → escalader à Henri. Ne pas tenter de réparer le legacy (out-of-scope).

### Phase T4 — Test parité bytes (AC2.2+AC2.3 — ~2-3h)

- [x] **T4.1** Créer `tests/Feature/Gpo/ApplicationsScriptsByteParityTest.php` (nouveau).
- [x] **T4.2** Implémenter le helper privé `assertScriptParity(string $expected, string $actual, array $normalizers = [])` :
  - Applique `$normalizers` (regex replace) sur `$expected` ET `$actual`.
  - Compare via `assertSame`.
  - En cas d'échec, génère un diff ligne-par-ligne lisible (utiliser `SebastianBergmann\Diff\Differ` ou similaire — disponible via PHPUnit).
- [x] **T4.3** Pour chaque scénario fixture, écrire un test :
  - `it_matches_legacy_bytes_for_windows_logon_user()` (`@dataProvider` pour la liste des scénarios).
  - `it_matches_legacy_bytes_for_windows_startup_firewall()`.
  - `it_matches_legacy_bytes_for_linux_startup_glpi()`.
- [x] **T4.4** Chaque test :
  - Build `$info` strict-iso au scénario (fixture du contexte AD/LDAP via `MockObject` du `ApplicationScriptsGenerator` ou directement passe `$info` array manuellement).
  - Build `$scripts` via `ApplicationTemplatesScanner` réel scanné sur `/usr/share/sambaedu/applications` (si présent en test env) OU mocké.
  - Appelle `$assembler->assemble($info, $scripts)`.
  - Récupère `$out[interpreter]` selon scénario.
  - Charge `tests/Fixtures/Gpo/applications/<scenario>/expected.<ext>` via `file_get_contents`.
  - Appelle `$this->assertScriptParity($expected, $actual, $normalizers)`.
- [x] **T4.5** Le test **doit échouer initialement** (sanity check) si on retire l'une des 8 nouvelles substitutions whitelist — vérifier ce comportement avant validation.
- [x] **T4.6** **Doc** : ajouter en haut du fichier de test un block docstring expliquant le contexte (référence Section G.1 audit + comment regénérer les fixtures).

### Phase T5 — Intégration `WrapperScriptRenderer` opt-in (AC3.1-3.3 — ~2-3h)

- [x] **T5.1** Modifier `ApplicationScriptsAssembler::__construct()` :
  ```php
  public function __construct(
      private readonly ?PermissionService $permissionService = null,
      private readonly ?WrapperScriptRenderer $wrapper = null,  // NEW
  ) {}
  ```
  - Rétro-compat constructeurs existants (16.7) : argument optionnel `null`, fallback `app(WrapperScriptRenderer::class)` quand le flag est `true`.
- [x] **T5.2** Ajouter méthode privée `wrapInterpreters(array $texts, array $info): array` :
  - Lit `config('sambaedu.scripts.logging.enabled', false)`. Si `false` → return `$texts` inchangé.
  - Sinon : pour chaque interpréteur dans `['cmd', 'bash']`, si `$texts[$interp] !== ''`, applique :
    - `$os = $info['os'] === 'linux' ? ScriptExecutionOs::LINUX : ScriptExecutionOs::WINDOWS`
    - `$action = $this->mapAction($info['action'] ?? 'startup')` — méthode helper qui projette `logon-system`/`logoff-system`/`wpkg` → `ScriptExecutionAction::STARTUP` et les autres directement
    - `$wrapper = $this->wrapper ?? app(WrapperScriptRenderer::class)`
    - `$texts[$interp] = $wrapper->wrap($texts[$interp], $action, $os, null, ScriptExecutionSource::GPO_APPLICATIONS)`
- [x] **T5.3** Appeler `wrapInterpreters($texts, $info)` en fin de `assemble()` (après la boucle de concat finale, ligne 184 actuelle, juste avant `return $texts`).
- [x] **T5.4** Ajouter méthode helper privée `private function mapAction(string $action): ScriptExecutionAction` :
  ```php
  return match ($action) {
      'logon' => ScriptExecutionAction::LOGON,
      'logoff' => ScriptExecutionAction::LOGOFF,
      'shutdown' => ScriptExecutionAction::SHUTDOWN,
      'startup', 'logon-system', 'logoff-system', 'wpkg' => ScriptExecutionAction::STARTUP,
      default => ScriptExecutionAction::STARTUP,
  };
  ```
- [x] **T5.5** Créer `tests/Feature/Gpo/ApplicationsScriptsWrapperIntegrationTest.php` (nouveau) :
  - `it_returns_unwrapped_output_when_flag_disabled()` (config par défaut)
  - `it_wraps_cmd_output_with_invoke_restmethod_when_flag_enabled()` (Windows logon — assert string contains `Invoke-RestMethod`)
  - `it_wraps_bash_output_with_curl_when_flag_enabled()` (Linux startup — assert contains `curl -fsS -X POST`)
  - `it_does_not_wrap_powershell_or_apt_interpreters_even_when_enabled()` (régression — flag true mais asserts que `$texts['powershell']` n'a PAS de wrapping)
  - `it_uses_gpo_applications_source_enum()` — capture le `WrapperScriptRenderer::wrap()` mock et vérifie le 5ème argument = `ScriptExecutionSource::GPO_APPLICATIONS`.
- [x] **T5.6** Lancer `php artisan test --filter ApplicationsScriptsWrapperIntegration`.

### Phase T6 — Régression iso-bytes 16.7 (AC4.3+AC4.4 — ~30min)

- [x] **T6.1** Lancer la suite complète tests Gpo : `php artisan test --testsuite=Feature --filter Gpo` + `php artisan test --testsuite=Unit --filter Gpo` + `php artisan test --filter Application`.
- [x] **T6.2** Vérifier que **100 %** des tests héritage 16.7 passent (zéro régression).
- [x] **T6.3** Vérifier `git diff --name-only main..HEAD` : aucun fichier sous `database/migrations/`, `routes/`, `resources/views/pages/`, `app/Models/` (AC4.4).
- [x] **T6.4** Lancer `php -l app/Gpo/Services/ApplicationScriptsAssembler.php` + `php -l config/sambaedu.php` → 0 erreur.
- [x] **T6.5** Lancer `vendor/bin/phpstan analyse app/Gpo/Services/ApplicationScriptsAssembler.php` si phpstan configuré (sinon skip avec note).

### Phase T7 — Documentation & finalisation (~30min)

- [x] **T7.1** Remplir la section « Dev Agent Record » de cette story (file list, completion notes, agent model used).
- [x] **T7.2** Si questions ouvertes Q-1, Q-2 résolues durant le dev (ex. confirmation Henri du multi-VLAN), documenter la résolution en Completion Notes.
- [x] **T7.3** **Cas particulier worktree** : si fixtures non capturées (T3.2 dev en worktree, Henri pas encore livré), marquer la story `ready-for-review` avec Completion Note « fixtures à capturer post-merge — story partielle ». Décision finale revient à Henri.
- [x] **T7.4** Mettre à jour le statut sprint-status.yaml `17-2-portage-moteur-applications-php-whitelist-etendue` de `ready-for-dev` à `review` (lors du dev).

---

## Dev Notes

### Architecture / Patterns

#### `ApplicationScriptsAssembler` — point d'extension chirurgical

- Classe `App\Gpo\Services\ApplicationScriptsAssembler` (`app/Gpo/Services/ApplicationScriptsAssembler.php`).
- Méthode publique : `assemble(array $info, array $scripts): array` (ligne 87).
- **Point d'injection wrapper** : juste avant `return $texts` (ligne 184).
- **Point d'extension whitelist** : aucune modification du code PHP — seulement `config/sambaedu.php`. Le service `loadWhitelist()` (ligne 961) lit `config('sambaedu.gpo.applications.substitutions.whitelist')` qui sera automatiquement enrichi.
- **Cache statique whitelist** : `$substitutionsCache` (ligne 45) — si tests config:set en runtime, penser à clear cache via reflection OU recréer instance Assembler à chaque test (pattern Laravel `app()->forgetInstance(...)`).

#### `WrapperScriptRenderer` — service injectable (livré 16.12)

- Classe `App\ScriptsOs\Services\WrapperScriptRenderer` (`app/ScriptsOs/Services/WrapperScriptRenderer.php`).
- Signature `wrap(string $scriptContent, ScriptExecutionAction $action, ScriptExecutionOs $os, ?int $scriptId = null, ScriptExecutionSource $source = ScriptExecutionSource::MANAGED_SCRIPT): string`.
- Le rendu est **idempotent par appel** (correlation_id régénéré à chaque appel) — pour les tests, mocker l'instance OU asserter regex UUID.
- **Performance** : cache statique `templateCache` (private static array) keyed par OS — partagé entre instances. Pas d'enjeu perf en 17.2 (peu d'appels — un par interpréteur par requête).

#### Pattern résolution config()→env()→default (16.7 D3 — référence)

Spec déclarative sérialisable : `['config' => 'path', 'env' => 'VAR', 'default' => 'fallback']`. Chaîne courte-circuit : config (non-null non-vide) → env (non-null non-false non-vide) → default. Une valeur vide chaîne `''` venant de `config()` est **traitée comme manquante** (fall-through `env()`) sauf si `default => ''` explicite. Voir `ApplicationScriptsAssembler::resolveSubstitutionValue()` ligne 913.

#### Wrap projection enum action — décision documentée

Le `ScriptExecutionAction` enum (16.12 D1) liste 5 cases : `LOGON`, `STARTUP`, `SHUTDOWN`, `LOGOFF`, `ONESHOT`. Les actions legacy `logon-system`, `logoff-system`, `wpkg` ne sont pas représentées. Décision SM **17.2 D5** : projection sur `STARTUP` (cohérent sémantiquement — exécution en contexte SYSTEM). Si Epic 17 évolue vers un enum étendu, refactor minimal de `mapAction()`.

### Tests Standards

- **Framework** : PHPUnit (via `php artisan test` Laravel).
- **Couverture cible** : ≥ 12 tests cumulés (5 Unit AC1.3+AC4.1+AC4.2 + 3 Feature AC2.2 + 5 Feature AC3.3 = 13).
- **Pattern Mock Log** : `Log::shouldReceive('channel')->with('daily')->andReturnSelf()->shouldReceive('warning')->...`. Voir tests 16.7 pour exemple.
- **Pattern env testing** : `\Illuminate\Support\Env::getRepository()->set('SAMBAEDU_GLPI_URL', '...')` (Laravel 11) ou `putenv('SAMBAEDU_GLPI_URL=...')` (legacy compatible) — checker quelle approche est utilisée par les tests 16.7 existants (`tests/Unit/Gpo/ApplicationScriptsAssemblerTest.php`).
- **Pattern fixture** : `file_get_contents(__DIR__ . '/../../Fixtures/Gpo/applications/<scenario>/expected.<ext>')` — préserver CR/LF en mode binaire si nécessaire (pas de conversion ligne).

### Sécurité

- Le pattern whitelist statique (16.7 D3) **est l'unique protection** contre injection via templates (audit F3 16.1 adressé). En étendant la whitelist, on ne baisse pas la garde — chaque clé ajoutée est explicitement listée.
- **Aucune nouvelle clé ne lit d'input user** : toutes les 8 nouvelles entrées résolvent vers config statique / env. Pas de query Eloquent paramétrée. Pas de risque d'injection.
- `glpi_url` peut contenir une URL fournie par l'admin — c'est de l'output HTTP, **pas de l'input SQL** ni de l'output HTML (le placeholder est substitué dans un fichier `.cfg` GLPI Agent). Risque XSS = nul. Risque SSRF = relevant uniquement si un attaquant peut modifier `.env`, ce qui est hors modèle de menace.

### Notes opérationnelles déploiement

- À l'installation paquet `sambaedu-reload` Debian, le `postinst` devra écrire dans `.env` les 4 nouvelles variables `SAMBAEDU_GLPI_URL`, `SAMBAEDU_NO_INTERNET`, `SAMBAEDU_DHCP_RESEAU`, `SAMBAEDU_DHCP_MASQUE` à partir des valeurs déjà présentes dans `/etc/sambaedu/sambaedu.conf` (legacy). **Hors scope 17.2** — ticket packaging à ouvrir lors de la release.
- Les valeurs default `''` permettent au serveur de démarrer sans configurer ces 4 variables — au prix d'un comportement dégradé sur les 6 scripts critiques. Acceptable en dev/test, à monitorer en prod.

### Références

- Audit 17.1 : `_bmad-output/planning-artifacts/audit-applications-scripts.md` — Sections A (cartographie 54 fragments), B (catalogue 11 placeholders), G.1 (cadrage 17.2), Q2 (déférence dev pour mapping sources).
- Story 16.7 (référence portage) : `_bmad-output/implementation-artifacts/16-7-portage-natif-applications-php.md` — D3 whitelist statique, AC4 substitutions.
- Story 16.12 (référence wrapper) : `_bmad-output/implementation-artifacts/16-12-logs-execution-centralises-ui-consultation.md` — D4 service `WrapperScriptRenderer`.
- Config actuelle whitelist : `config/sambaedu.php:411-490` (8 clés Story 16.7).
- Service Assembler : `app/Gpo/Services/ApplicationScriptsAssembler.php:873-972` (`applySubstitutions`, `resolveSubstitutionValue`, `loadWhitelist`).
- Service Wrapper : `app/ScriptsOs/Services/WrapperScriptRenderer.php` (signature D4 16.12).
- Enums logs : `app/ScriptsOs/Enums/{ScriptExecutionAction,ScriptExecutionOs,ScriptExecutionSource}.php`.
- Tests existants 16.7 : `tests/Unit/Gpo/ApplicationScriptsAssemblerTest.php` + `tests/Feature/Gpo/Applications*Test.php`.
- Legacy reference : `legacy/includes/applications.inc.php` (ou `sambaedu/includes/applications.inc.php` côté source upstream) — 1007 lignes, fonction `make_application_scripts:290` consomme `$config['cloud_perso_name']` directement (cf. comm whitelist `CLOUD_PERSO_NAME` ligne 487).

### File List anticipée (à compléter par le dev)

**Modifiés** :
- `config/sambaedu.php` (entrées racine + whitelist étendue)
- `app/Gpo/Services/ApplicationScriptsAssembler.php` (constructeur + wrapper opt-in)
- `tests/Unit/Gpo/ApplicationScriptsAssemblerTest.php` (~5 nouveaux tests)

**Créés** :
- `tests/Feature/Gpo/ApplicationsScriptsByteParityTest.php`
- `tests/Feature/Gpo/ApplicationsScriptsWrapperIntegrationTest.php`
- `tests/Fixtures/Gpo/applications/README.md`
- `tests/Fixtures/Gpo/applications/windows_logon_user/expected.cmd`
- `tests/Fixtures/Gpo/applications/windows_startup_firewall/expected.cmd`
- `tests/Fixtures/Gpo/applications/linux_startup_glpi/expected.sh`
- (potentiellement) `tests/Fixtures/Gpo/applications/windows_<4e>/expected.cmd` si AC2.3 nécessite un scénario supplémentaire

### Questions ouvertes — ✅ RÉSOLUES Henri 2026-05-21 (avant lancement dev)

#### Q-1 — Comptage whitelist : ✅ **16 clés** (8 existantes + 8 nouvelles)

Décision Henri : appliquer le tableau Section B (16 clés au total). Les clés non-utilisées par les scripts upstream restent inoffensives.

#### Q-2 — DHCP multi-VLAN : ✅ **forme simple iso-script**

Décision Henri : mapper `DHCP_RESEAU`/`DHCP_MASQUE` vers `config('sambaedu.dhcp_reseau')` / `config('sambaedu.dhcp_masque')` (forme simple). Ticket Phase 3 si terrain multi-VLAN remonte.

#### Q-3 — Capture fixtures legacy : ✅ **dev SSH /vm autorisé (exception ponctuelle)**

Décision Henri : exception accordée au dev (worktree ou branche) pour SSH `/vm` afin de capturer les 3 fixtures `windows_logon_user/expected.cmd`, `windows_startup_firewall/expected.cmd`, `linux_startup_glpi/expected.sh`. Le dev exécute les `curl` sur `gpo/applications.php` legacy et stocke les sorties binaires en fixtures. Procédure exacte documentée dans `tests/Fixtures/Gpo/applications/README.md` (T7.1).

#### Q-4 — Enrichir `.env.example` : ✅ **OUI, ajouter les 4 vars**

Décision Henri : ajouter `SAMBAEDU_GLPI_URL`, `SAMBAEDU_NO_INTERNET`, `SAMBAEDU_DHCP_RESEAU`, `SAMBAEDU_DHCP_MASQUE` à `.env.example` (T1.1 enrichi). Documente pour packaging Debian futur.

---

## Dev Agent Record

### Agent Model Used

`claude-sonnet-4-6` — 2026-05-21

### Debug Log References

- Fix `localAdminScripts()` : retour `['script' => $script !== '' ? [$script] : []]` au lieu de `['script' => [$script]]`. Cause racine : le legacy PHP retourne `interpreter='bash'` pour windows+logon+sans-droits-admin (quirk legacy ligne ~740 de `applications.inc.php`), ce qui fait disparaître le séparateur `REM script [local_admin]` de la sortie cmd. Le natif retournait `interpreter='cmd'` avec `script=['']`, ajoutant un séparateur vide qui décalait l'ordonnancement des scripts redirect-thunderbird vs associations dans la fixture `windows_logon_user`.
- Parité bytes : 2 normaliseurs appliqués avant `assertSame` — `SET DOMAINSID=S-1-5-21-[\d-]+` → `SET DOMAINSID=__SID__` (SID variable par instance Samba VM) et lookahead négatif pour éviter double-remplacement.
- Tests wrapper integration : `atLeast()->once()` requis car `wrapInterpreters()` wrape AUSSI le fragment `bash` (header bash non vide même en contexte Windows logon) — comportement correct, le bash header est généré pour tous les OS.

### Completion Notes List

- **AC2.3 couverture 8 nouvelles clés** : couverte par les 3 scénarios existants. `ADMINSE_NAME` → `windows_startup_firewall` (script `folders/clean_profiles`). `SE4INSTALL_NAME` → `windows_logon_user` (script `wallpaper/logon.windows`). Pas besoin d'un 4e scénario.
- **Q-1 (comptage 16 clés)** : confirmé — 8 existantes + 8 nouvelles = 16 totales. La description story « 8→14 » était une erreur dans le titre ; le tableau Section B (16) fait foi comme décidé Henri.
- **Q-2 (DHCP multi-VLAN)** : implémenté en forme simple `config('sambaedu.dhcp_reseau')` iso-legacy `$config['dhcp_reseau']`. Ticket Phase 3 si terrain multi-VLAN remonte.
- **T6.5 phpstan** : non disponible dans le scope de la VM de test — skip avec note. `php -l` retourne 0 erreur sur les deux fichiers modifiés.
- **Fixtures capturées via SSH /vm** (exception Q-3 accordée Henri) : appel direct aux fonctions PHP legacy avec `$info` synthétique (VM sans postes clients réels dans l'AD). Contexte `context=''` utilisé pour linux_startup_glpi pour éviter `local_admin_scripts()` qui requiert un schéma LDAP complet non disponible.

### File List

**Modifiés** :
- `config/sambaedu.php` — 4 nouvelles entrées racine (`glpi_url`, `no_internet`, `dhcp_reseau`, `dhcp_masque`) + 8 nouvelles entrées whitelist (`ADMINSE_NAME`, `DHCP_MASQUE`, `DHCP_RESEAU`, `GLPI_URL`, `NO_INTERNET`, `SE4AD_IP`, `SE4FS_IP`, `SE4INSTALL_NAME`)
- `app/Gpo/Services/ApplicationScriptsAssembler.php` — constructeur `?WrapperScriptRenderer $wrapper = null` + méthodes `wrapInterpreters()` + `mapAction()` + imports enums + fix `localAdminScripts()` retour empty array quand script vide
- `tests/Unit/Gpo/ApplicationScriptsAssemblerTest.php` — 5 nouveaux tests AC1.3+AC4.1+AC4.2
- `.env.example` — 4 nouvelles vars `SAMBAEDU_GLPI_URL`, `SAMBAEDU_NO_INTERNET`, `SAMBAEDU_DHCP_RESEAU`, `SAMBAEDU_DHCP_MASQUE`

**Créés** :
- `tests/Feature/Gpo/ApplicationsScriptsByteParityTest.php` — 3 tests parité bytes (AC2.2)
- `tests/Feature/Gpo/ApplicationsScriptsWrapperIntegrationTest.php` — 6 tests intégration wrapper (AC3.3)
- `tests/Fixtures/Gpo/applications/README.md` — procédure capture fixtures (AC2.1)
- `tests/Fixtures/Gpo/applications/windows_logon_user/expected.cmd` — fixture 8578 bytes
- `tests/Fixtures/Gpo/applications/windows_startup_firewall/expected.cmd` — fixture 20866 bytes
- `tests/Fixtures/Gpo/applications/linux_startup_glpi/expected.sh` — fixture 10834 bytes

---

## Completion Notes — Post-review corrections (2026-05-21)

Suite à la code review opus (cf. `_bmad-output/codeReviews/17-2.md`), 9 problèmes ont été corrigés.

### Fix #1 — Bug OS dans `wrapInterpreters` (🔴 Critique)

**Fichier** : `app/Gpo/Services/ApplicationScriptsAssembler.php:900-926`

L'OS était dérivé de `$info['os']` et appliqué à tous les interpréteurs. Corrigé : `$osMap = ['cmd' => ScriptExecutionOs::WINDOWS, 'bash' => ScriptExecutionOs::LINUX]` — l'OS est désormais intrinsèque à l'interpréteur. Cela évite qu'un fragment bash (header présent même en contexte Windows) soit wrappé comme un binaire cmd.

Test ajouté : `it_wraps_bash_with_linux_when_info_os_is_windows` dans `ApplicationsScriptsWrapperIntegrationTest.php`.

### Fix #2 — Test parité bytes vs unwrapped (🟠 Important)

**Fichier** : `tests/Feature/Gpo/ApplicationsScriptsWrapperIntegrationTest.php`

`it_returns_unwrapped_output_when_flag_disabled` amélioré : snapshot baseline sans wrapper injecté (flag false + wrapper null), puis assertion `assertSame` byte-strict entre baseline et sortie avec wrapper mocké (flag false → never() vérifié).

### Fix #3 — Déclarer `sambaedu.scripts.logging.enabled` (🟠 Important)

**Fichiers** : `config/sambaedu.php`, `.env.example`

Ajout de `'scripts' => ['logging' => ['enabled' => (bool) env('SAMBAEDU_SCRIPTS_LOGGING_ENABLED', false)]]` au niveau racine de `config/sambaedu.php`. Variable `SAMBAEDU_SCRIPTS_LOGGING_ENABLED=false` ajoutée à `.env.example` avec commentaire.

### Fix #4 — Remplacer `putenv()` par API Env Laravel (🟠 Important)

**Fichier** : `tests/Unit/Gpo/ApplicationScriptsAssemblerTest.php:401-417`

Ajout de `\Illuminate\Support\Env::enablePutenv()` avant et après les appels `putenv()` pour resetter le repository statique immutable de phpdotenv. Garantit que le repository relit correctement `getenv()` sans interférence avec le cache immutable.

### Fix #5 — Documenter fix `localAdminScripts` (🟠 Important)

**Fichier** : `app/Gpo/Services/ApplicationScriptsAssembler.php:737`

Commentaire ajouté ligne 737 : `// 17.2: return [] not [''] so addScripts() skips the separator (legacy parity for users without admin rights)`.

Test dédié ajouté : `it_returns_empty_script_array_when_user_has_no_admin_rights` assertant `assertSame([], $result['script'])`.

**Note** : ce fix latent 16.7 est intégré en 17.2 car il impactait directement la parité bytes (fixture `windows_logon_user` — séparateur parasite vs legacy). Décision Henri Q#1 : garder en 17.2 + documenter.

### Fix #6 — Checksum paquet legacy dans README fixtures (🟠 Important)

**Fichier** : `tests/Fixtures/Gpo/applications/README.md`

Section "Versioning paquet legacy" ajoutée avec :
- Version paquet : `4.17.285`
- SHA256 `/usr/share/sambaedu/applications/` : `8e0b5be2498b000762af4de89141023e62d9cf5e75713e982169d50a0f8c280e`
- Commande de recalcul du checksum
- Phrase explicite sur la procédure de regénération si le paquet est mis à jour

### Fix #7 — Normaliseur DOMAINSID simplifié (🟡 Mineur)

**Fichier** : `tests/Feature/Gpo/ApplicationsScriptsByteParityTest.php:62`

Remplacement des 2 regex en chaîne (dont un lookahead négatif inutile) par une seule regex unifiée : `'/SET DOMAINSID=[^\r\n]*/'`.

### Fix #8 — Skip silencieux documenté (🟡 Mineur)

Couvert par Fix #6 — mention explicite dans le README fixtures que les tests `ApplicationsScriptsByteParityTest` skipent en CI sans VM legacy, avec commande pour les exécuter sur VM.

### Fix #9 — Test résolution container (🟡 Mineur)

**Fichier** : `tests/Feature/Gpo/ApplicationsScriptsWrapperIntegrationTest.php`

Test `it_resolves_wrapper_from_container_when_not_injected` ajouté : construit `new ApplicationScriptsAssembler(null, null)` avec flag true → vérifie que le container Laravel résout `WrapperScriptRenderer::class` sans exception.

---

## Recommandation Modèle Dev

**Recommandation : `sonnet`** (avec bascule `opus` possible si T3-T4 dérive).

### Justification

**Périmètre technique réel** :
- Volet 1 (whitelist + config) = **mécanique pure**, pattern strictement décalqué de 16.7 D3, déjà documenté ligne-par-ligne dans cette story (T1.1 + T1.2 sont quasi du copy-paste enrichi). Aucune décision d'architecture.
- Volet 2 (parité bytes) = **rigueur fixture-driven**, le challenge est la capture (T3.2 — bloquant Henri si worktree) et la robustesse du normaliser. Pas de complexité algorithmique.
- Volet 3 (wrapper opt-in) = **branchement chirurgical** (D6) sur un service existant 16.7. Le `WrapperScriptRenderer::wrap()` 16.12 a une signature stable + 4 tests unit livrés + cache statique testé. L'intégration = 1 méthode helper `mapAction()` + 1 méthode `wrapInterpreters()` + 5 tests. Pas de risque architectural.
- Volet 4 (régression) = lancer la suite existante.

**Charge réelle 2-3j** alignée sprint-status. Sonnet a livré récemment 16.12, 16.13, 16.15 sans dérive sur ce niveau de complexité.

**Pourquoi pas opus** :
- Pas de décision archi transverse (toutes tranchées D1-D8 dans cette story).
- Pas de nouvelle modélisation Eloquent.
- Pas d'algorithme complexe (substitution = `str_replace` + log, wrapping = service injection conditionnelle).
- Audit 17.1 (opus) a déjà fait le gros du travail conceptuel — 17.2 est l'exécution.

**Conditions de bascule opus en cours de dev** :
- Si T3 (capture fixtures) révèle des écarts legacy/natif **non documentés par l'audit** au point qu'il faille redesigner la projection enum action (D5) ou que des placeholders supplémentaires apparaissent.
- Si la parité bytes échoue de façon non-évidente après plusieurs itérations de normalisation (suggère un bug profond dans 16.7).

Dans ces deux cas, le dev sonnet doit **escalader à Henri** pour rebascule opus, pas tenter de corriger par lui-même.
