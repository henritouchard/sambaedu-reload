# Story 17.4 : Tests d'intégration runtime VM — 5 scripts critiques + parité bytes `applications.php`

Status: done

<!-- Validée Henri 2026-05-25 (orchestrateur dev-cycle) — review → done après corrections post-review 8/8. -->
<!-- Note: Validation est optionnelle. Lancer validate-create-story pour quality check avant dev-story. -->

> **Story de compatibilité runtime post-audit 17.1 validé** (Henri 2026-05-21).
> Verrou final de l'Epic 17 : tests Feature end-to-end **exécutés sur la VM `/vm`** garantissant que la chaîne de génération de scripts (`ApplicationScriptsAssembler` 16.7 + whitelist 16 clés 17.2 + endpoint natif API v1 16.13) reste **iso-legacy** pour les **5 scripts critiques** identifiés par l'audit Section A, et que les mécanismes transverses (surcharges admin `/etc/sambaedu/applications/`, placeholders étendus 17.2) fonctionnent runtime.
>
> Cette story **n'écrit que des tests + fixtures**. Aucune modification de code applicatif (sauf bug bloquant découvert → signaler à Henri, ne pas corriger sans arbitrage). Aucune migration, route, UI, GPO ni auth modifiée.
>
> Elle **étend le pattern de parité bytes déjà livré par 17.2** (`tests/Feature/Gpo/ApplicationsScriptsByteParityTest.php`, helper `assertScriptParity()`, fixtures `tests/Fixtures/Gpo/applications/<scenario>/expected.<ext>`, `README.md` procédure de capture) aux 5 scripts critiques et à l'endpoint natif `/api/v1/workstation-config/applications-scripts`.

---

## ⚠️ Décisions tranchées (D1-D9, ne pas re-débattre)

> Issues du cadrage epics.md Story 17.4, de l'audit 17.1 validé (Sections A, B, G.3, H.2, H.3, Q3) et des patterns établis par 17.2/17.3 done/review.

### D1 — Périmètre = tests d'intégration uniquement, ZÉRO code applicatif

- Cette story ajoute **uniquement** des fichiers sous `tests/` (Feature + Fixtures + éventuellement Concerns). Voir File List anticipée.
- **Interdiction stricte** de modifier `app/`, `config/`, `routes/`, `database/`. Le seul livrable hors `tests/` autorisé est l'enrichissement de `tests/Fixtures/Gpo/applications/README.md` (déjà sous `tests/`).
- **Si un bug bloquant est découvert** (parité bytes échoue de façon non-évidente, placeholder non substitué runtime, surcharge admin ignorée par le scanner) → **documenter en Completion Notes + escalader à Henri**. Ne pas patcher l'Assembler/Scanner soi-même (ce serait du scope 17.2 rouvert). Marquer le test concerné `markTestSkipped` avec référence au ticket d'escalade plutôt que de le faire passer artificiellement.

### D2 — Les 5 scripts critiques (epics.md + audit Section A)

| # | Script | Action | OS | Interpréteur | Placeholders consommés (audit) | Endpoint runtime aval | Statut audit |
|---|---|---|---|---|---|---|---|
| 1 | `wpkg/startup.windows` (3 l.) | `startup` | windows | cmd | aucun | aucun direct (ROBOCOPY déploie `install/os/SambaEdu/*`) | `Déjà couvert 16.6` |
| 2 | `wallpaper/logon.windows` (10 l.) | `logon` | windows | cmd | `SE4FS_NAME`, **`SE4INSTALL_NAME`** (nouvelle clé 17.2) | `POST wallpaper_out.php` (→ natif `/api/v1/workstation-config/wallpaper`) | `À adapter 17.6` (endpoint) + 17.2 (placeholder) |
| 3 | `shortcuts/logon.windows` (8 l.) | `logon` | windows | cmd | `SE4FS_NAME` | `POST shortcuts_out.php` (→ natif `/api/v1/workstation-config/shortcuts`) | `À adapter 17.6` |
| 4 | `firefox/logon.windows` (26 l.) | `logon` | windows | cmd | aucun | aucun (heredoc `profiles.ini`) | `OK iso-legacy` (à valider) |
| 5 | `firefox/logon.linux` (34 l.) | `logon` | linux | bash | aucun | aucun (heredoc `profiles.ini`) | `OK iso-legacy` |

> **Note** : scripts #4 et #5 n'ont **pas** de placeholder ni d'endpoint — leur test valide l'**assemblage iso-legacy** (heredoc `profiles.ini`/`installs.ini`, hash Firefox hardcodé, charset). Scripts #2 et #3 valident la **substitution placeholder runtime** + la **réécriture d'URL endpoint** (POST legacy → GET natif, cf. D5). Script #1 valide la **présence/assemblage du fragment ROBOCOPY** dans le `.cmd` startup (chaîne de déploiement `install/os/SambaEdu/*`, cf. audit H.3 point 5).

### D3 — Cible d'exécution = VM `/vm` (les fixtures requièrent `/usr/share/sambaedu/applications/`)

- Les tests de parité bytes **ne tournent réellement que sur la VM** : ils `markTestSkipped` quand `/usr/share/sambaedu/applications/` est absent (CI sans VM legacy), exactement comme `ApplicationsScriptsByteParityTest` 17.2 (groupe `requires-fixture-capture`).
- `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`, path projet VM `/var/www/sambaedu-reload`. Le code host est synchronisé via inotify (branche `main`). Le legacy équivalent est sous `/var/www/sambaedu/`.
- **Exécution suite** (sur VM) :
  ```bash
  ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 \
    "cd /var/www/sambaedu-reload && ./vendor/bin/phpunit --testsuite Feature --filter 'ApplicationsScripts'"
  ```
- **Décision worktree-safe (cf. memory `feedback_worktree_no_vm_sync.md`)** : si le dev travaille en worktree git → **interdit** de SSH `/vm`. Demander à Henri de capturer les fixtures + lancer la suite sur VM. Si le dev est sur la branche `main` (checkout principal) → exception accordée par Q-3 17.2 (déjà validée) : SSH `/vm` autorisé pour capturer fixtures + exécuter.

### D4 — Capture des fixtures : réutiliser la procédure 17.2 + l'étendre

- La procédure de capture existe déjà : `tests/Fixtures/Gpo/applications/README.md` (Story 17.2, paquet `sambaedu` 4.17.285, SHA256 `8e0b5be2…`). Elle invoque directement les fonctions PHP legacy (`read_application_scripts()` + `make_application_scripts()`) avec un `$info` synthétique — **pas** de poste client réel dans l'AD VM.
- Pour 17.4, **réutiliser le même mécanisme de capture** (PHP `-r` + injection `$config` + `$info` synthétique) en l'étendant aux contextes des 5 scripts critiques + au cas surcharge admin.
- **Ne jamais éditer une fixture à la main** — elle doit refléter la sortie binaire exacte du legacy (CRLF, charset CP1252 Windows / UTF-8 Linux préservés).
- Bumper la table « Version paquet / SHA256 » du README si capture sur un paquet plus récent.

### D5 — Mismatch méthode HTTP POST (legacy upstream) → GET (endpoint natif) : vérifié, PAS corrigé ici

- L'audit 17.3 (T0.4 inspection VM) a constaté que les 4 `.cmd` orchestrateurs du template GPO `se4_applications` (`/usr/share/sambaedu/gpo/sambaedu-gpo/se4_applications/`) hardcodent encore `http://%SE4FS%.###_DOMAIN_###/gpo/applications.php` en **POST multipart**, alors que l'endpoint natif API v1 16.13 est en **GET query string** (`Route::get('/api/v1/workstation-config/applications-scripts')`).
- 17.3 a livré le `.diff` upstream (POST→GET, `_bmad-output/implementation-artifacts/17-3-upstream-se4_applications.diff`) + la clé whitelist `APPLICATIONS_SCRIPTS_URL` (substitution post-extraction). Le fallback legacy `gpo/applications.php` est re-routé par `MigrationController::serveFragment(applications)` en `Route::match(['GET','POST'])` (16.13bis), donc **les deux méthodes fonctionnent** côté serveur pendant la transition JWT.
- **Scope 17.4** : un test Feature vérifie que l'endpoint natif `/api/v1/workstation-config/applications-scripts` répond **correctement en GET** (auth JWT poste) pour les actions des 5 scripts critiques, ET (si template présent sur VM) un test d'audit lit les `.cmd` orchestrateurs et **documente** la méthode HTTP réelle observée (POST patché ou non). **Ne pas modifier le template ni le `.diff`** (= scope 17.3, déjà en review).

### D6 — Surcharges admin `/etc/sambaedu/applications/<app>/` prioritaires sur `/usr/share/`

- Mécanisme livré par 16.7 (`ApplicationTemplatesScanner::scan($packagePath, $localPath)` — package lu d'abord, local surcharge via `mergeScripts()` indexé par hash). Audit H.2 confirme : ✅ préservé.
- **Test E2E surcharge** (audit H.2 + F10 + G.3) : créer un répertoire temporaire `local/` jouant le rôle de `/etc/sambaedu/applications/`, y déposer une surcharge d'un script critique (ex. `wallpaper/logon.windows` avec contenu différent), scanner `package=/usr/share/... local=<tmp>`, vérifier que **la version locale gagne** dans la sortie `assemble()`.
- **Worktree-safe** : le test surcharge n'a PAS besoin de la VM si on lui passe **deux répertoires de fixtures locaux** (un faux `package/`, un faux `local/`) — le `Scanner::scan()` accepte des chemins arbitraires en arguments. Préférer cette approche (test portable CI) + un test VM réel optionnel sur `/etc/sambaedu/applications/` (skip si répertoire absent).

### D7 — Placeholders étendus 17.2 : vérifier la substitution runtime via les fixtures

- 17.2 a livré la whitelist 16 clés + 3 fixtures de parité (`windows_logon_user`, `windows_startup_firewall`, `linux_startup_glpi`) couvrant les 8 nouvelles clés. 17.4 **ne re-teste pas la substitution unitaire** (= scope 17.2 `ApplicationScriptsAssemblerTest`).
- 17.4 vérifie la substitution **dans le contexte des 5 scripts critiques** : le scénario `wallpaper/logon.windows` doit prouver que `###_SE4INSTALL_NAME_###` est substitué (sinon `taskkill /FI "USERNAME ne ###_SE4INSTALL_NAME_###"` tue explorer.exe pour TOUS les users — risque bloquant audit Section A ligne 645).
- Assertion ciblée : aucune occurrence de `###_` ne subsiste dans la sortie assemblée des 5 scripts critiques (hors placeholders volontairement non-whitelistés documentés).

### D8 — Cas Linux : `firefox/logon.linux` obligatoire (Q3 audit, ≥ 1 cas Linux)

- Q3 audit déférée à 17.4 : couverture minimale **≥ 1 cas Linux**, par défaut `firefox/logon.linux` (epics.md). 
- **Décision SM** : couvrir `firefox/logon.linux` (script critique #5, interpréteur `bash`, heredoc `profiles.ini`). Le scénario `linux_startup_glpi` 17.2 couvre déjà un autre fragment Linux — on ne le re-teste pas, mais on peut le réutiliser comme témoin de régression.
- **Question ouverte Q-4** : confirmer avec Henri si d'autres `.linux` réellement actifs en parc (LTSP / postes nomades — `wallpaper/logon.linux`, `shortcuts/logon.linux`) doivent être ajoutés. Recommandation SM : se limiter à `firefox/logon.linux` (cadrage epics.md strict, charge 2j), différer les autres `.linux` à une story de durcissement si le terrain le remonte.

### D9 — Auth endpoint natif : JWT poste iso-legacy, réutiliser le trait `IssuesWorkstationJwt`

- L'endpoint `/api/v1/workstation-config/applications-scripts` est protégé par `auth.v1.workstation` (JWT RS256, tier=workstation, middleware 16.10). Le `workstation_uuid` est extrait du claim `sub` (jamais du body/query).
- **Réutiliser** le trait de test existant `tests/Concerns/IssuesWorkstationJwt.php` (`configureTestKeyPair()`, `issueTestJwt(['sub' => $uuid, 'tier' => 'workstation'])`, `ensureAuthV1Tables()`). Pattern déjà appliqué dans `tests/Feature/Api/V1/Config/ApiV1ConfigSecurityTest.php`. **Ne pas réimplémenter** l'émission JWT.
- Le `Workstation` ciblé doit exister en DB (Eloquent factory) avec le même `uuid` que le claim `sub`, sinon l'endpoint répond 404 (déviation D5 16.13).

---

## Story

As **un poste client (Windows ou Linux) joint au domaine SE4FS, et un mainteneur SambaEdu**,
I want
- que les **5 scripts critiques** du package Debian (`wpkg/startup.windows`, `wallpaper/logon.windows`, `shortcuts/logon.windows`, `firefox/logon.windows`, `firefox/logon.linux`) soient assemblés par le moteur natif de façon **byte-identique au legacy** `gpo/applications.php`, vérifié par des tests Feature exécutés **sur la VM** ;
- que l'endpoint natif `/api/v1/workstation-config/applications-scripts` (16.13, auth JWT) serve **en GET** les fragments des 5 scripts critiques avec le bon charset (CP1252 Windows / UTF-8 Linux) ;
- que les **surcharges admin** `/etc/sambaedu/applications/<app>/` prennent **priorité** sur `/usr/share/sambaedu/applications/` runtime ;
- que les **placeholders étendus 17.2** (en particulier `SE4INSTALL_NAME`) soient **correctement substitués** dans le contexte de ces scripts,

So que (a) le remplacement du legacy par Epic 15 (WPKG natif) + Epic 16 (GPO natives) ne casse aucun des scripts les plus consommés du parc (pas de wallpaper manquant, pas de raccourcis perdus, pas de `taskkill` tuant explorer.exe, pas de profil Firefox corrompu) ; (b) la régression de parité bytes soit détectable par une suite de tests dédiée exécutable sur VM ; (c) la couche de surcharges admin (`/etc/`) soit garantie non-perdue lors du déploiement natif (risque F10 audit).

---

## Contexte

### Position dans l'Epic 17 post-RESET

L'Epic 17 (recadré 2026-05-14, cf. Story 17.1) couvre la **compatibilité runtime** des ~80 scripts versionnés par le package Debian `sambaedu` après remplacement du legacy par Epic 15 + Epic 16. La chaîne native est :

```
GPO se4_applications (.cmd orchestrateurs côté poste)
   → curl GET /api/v1/workstation-config/applications-scripts  (16.13, auth JWT)
        ApplicationsScriptsController::apiV1
          → ApplicationScriptsGenerator::resolveInfo()   (contexte AD → $info)
          → ApplicationTemplatesScanner::scan()           (scan /usr/share + /etc surcharge, 16.7)
          → ApplicationScriptsAssembler::assemble()        (substitution whitelist 16 clés 17.2 + header/footer)
   → .cmd / .sh retourné au poste, exécuté
```

17.2 (done) a verrouillé la whitelist + la parité bytes sur **3 fixtures de couverture placeholder**. 17.3 (review) a vérifié/patché le template GPO orchestrateur. **17.4 verrouille la parité runtime sur les 5 scripts les plus critiques du parc + les mécanismes transverses (surcharges, charset, endpoint natif).**

### Découpage Epic 17 (validé Henri 2026-05-21, Section G.6 audit)

| Story | Statut | Charge | Périmètre |
|---|---|---|---|
| 17.1 — Audit scripts Windows & Linux | ✅ done 2026-05-21 | 1-2j | Livrable markdown 1700 lignes |
| 17.2 — Moteur applications.php + whitelist 16 clés + parité + wrapper | ✅ done 2026-05-21 | 2-3j | 3 volets, 11 ACs |
| 17.3 — Compat GPO orchestratrice `se4_applications` (Stratégie A) | review (code-complete 2026-05-22) | ~1j | Commande artisan audit + whitelist `APPLICATIONS_SCRIPTS_URL` + `.diff` upstream |
| **17.4 (cette story) — Tests intégration runtime VM** | **À faire** | **2j** | **E2E 5 scripts critiques + parité endpoint + surcharges + placeholders** |
| 17.5 — Activation wrapper opt-in (config + artisan) | backlog | ~1j | Flag + commandes `winscript-logs:enable`/`disable` |
| 17.6 — Portage endpoints orphelins (`linux_out`, `winget_out`) | backlog | ~2.5-3j | 2 controllers natifs |

### Dépendances

| Story / Epic | Statut | Apport pour 17.4 |
|---|---|---|
| **17.2** — Moteur applications.php + whitelist étendue | ✅ **done 2026-05-21** (validé Henri, mergé) | Pattern parité bytes `ApplicationsScriptsByteParityTest.php` + helper `assertScriptParity()` + fixtures `tests/Fixtures/Gpo/applications/<scenario>/` + `README.md` procédure de capture + whitelist 16 clés. **Base directe étendue par 17.4.** |
| **17.3** — Compat GPO orchestratrice `se4_applications` | ⏳ **review** (code-complete 2026-05-22, 13/13 problèmes post-review corrigés, doc review `to-validate`, attente validation orchestrateur dev-cycle pour merge) | Commande artisan `gpo:applications:audit` + clé whitelist `APPLICATIONS_SCRIPTS_URL` + observation VM : `.cmd` orchestrateurs en POST multipart (mismatch GET endpoint natif documenté pour 17.4, cf. D5). |
| **16.7** — Portage natif `applications.php` | ✅ done | `ApplicationScriptsAssembler` / `ApplicationTemplatesScanner` / `ApplicationScriptsGenerator` / `ApplicationsScriptsController` — services testés par 17.4 (ne PAS réimplémenter). Mécanisme surcharge `/etc/` (`Scanner::scan($pkg, $local)`). |
| **16.13** — Endpoints API v1 | ✅ done | Endpoint natif `/api/v1/workstation-config/applications-scripts` (route `agent.v1.config.applications-scripts`, méthode `apiV1`, auth JWT `auth.v1.workstation`, charset cp1252/utf-8). |
| **16.10/16.11** — Auth JWT poste + bootstrap | ✅ done | Trait de test `IssuesWorkstationJwt` (émission JWT RS256 tier=workstation, tables auth_v1). |
| **16.12** — Logs d'exécution centralisés | ✅ done | Flag `sambaedu.scripts.logging.enabled` (défaut `false` = iso-legacy ; 17.4 teste **flag false uniquement**, l'activation = 17.5). |
| Epic 4 | done | `Workstation`, `WorkstationGroup`, `User` (factories pour DB de test). |

> **Prérequis epics.md** : `17.2 + 17.3`. 17.2 est done. **17.3 est en review (code-complete)** — fonctionnellement suffisant pour démarrer 17.4 (le code 17.3 est mergeable, attente validation orchestrateur). Si le merge 17.3 tarde, 17.4 peut démarrer ses phases T0-T4 (parité bytes 5 scripts, indépendantes de 17.3) ; seule la vérification template GPO (T6, D5) dépend du contexte 17.3.

### Frontières (anti-scope creep)

| HORS scope 17.4 | Renvoi |
|---|---|
| Activation du flag wrapper logs / commandes artisan `winscript-logs:*` | 17.5 |
| Portage endpoints orphelins `wpkg/linux_out.php` / `wpkg/winget_out.php` | 17.6 |
| Modification / création du template GPO `se4_applications`, du `.diff` upstream | 17.3 (review) |
| Toute modification de code applicatif (`app/`, `config/`, `routes/`, `database/`) | Hors scope — sauf bug bloquant à **signaler** (D1) |
| Création/modification de modèle Eloquent, migration, auth | Hors Epic 17 |
| Modification du contenu des fragments scripts upstream | Versionné par le paquet Debian |
| Tests unitaires des services (substitution unitaire, etc.) | 17.2 (déjà livré) |
| Couverture exhaustive des 54 fragments | Hors scope — 5 scripts critiques + mécanismes transverses uniquement |

---

## Acceptance Criteria

> 14 ACs organisés en **6 volets** : (1) parité bytes 5 scripts critiques, (2) parité endpoint natif API v1, (3) surcharges admin, (4) placeholders étendus 17.2, (5) cas Linux, (6) régression & non-modification de code.

### Volet 1 — Parité bytes des 5 scripts critiques (D2, D3, D4)

**AC1.1 — Fixtures legacy capturées pour les 5 scripts critiques**
**Given** la nécessité de référencer la sortie legacy des 5 scripts critiques
**When** le dev capture les fixtures (procédure étendue de `tests/Fixtures/Gpo/applications/README.md`)
**Then** des fixtures sont créées pour chaque script critique non encore couvert par 17.2 :
- `tests/Fixtures/Gpo/applications/windows_startup_wpkg/expected.cmd` (fragment ROBOCOPY `wpkg/startup.windows`)
- `tests/Fixtures/Gpo/applications/windows_logon_wallpaper/expected.cmd` (couvre `wallpaper/logon.windows` + `SE4INSTALL_NAME`)
- `tests/Fixtures/Gpo/applications/windows_logon_shortcuts/expected.cmd` (couvre `shortcuts/logon.windows`)
- `tests/Fixtures/Gpo/applications/windows_logon_firefox/expected.cmd` (couvre `firefox/logon.windows`, heredoc `profiles.ini`)
- `tests/Fixtures/Gpo/applications/linux_logon_firefox/expected.sh` (couvre `firefox/logon.linux`)
**And** chaque fixture est capturée sur VM (paquet `sambaedu` documenté dans README) avec un `$info` synthétique iso-legacy documenté (machine/user/action/os/context/parcs/list)
**And** le `README.md` est enrichi : nouvelle section « Scénarios 17.4 » (5 sous-sections) + bump de la table version/SHA256 si paquet différent de 17.2.

> **Note worktree-safe** : si le dev est en worktree git → NE PAS SSH `/vm`. Demander à Henri de capturer/fournir les fixtures. Cf. memory `feedback_worktree_no_vm_sync.md` + D3.

**AC1.2 — Test de parité bytes par script critique**
**Given** les 5 fixtures (AC1.1) et le moteur natif
**When** `tests/Feature/Gpo/ApplicationsScriptsCriticalParityTest.php` (nouveau) est exécuté sur VM (`/usr/share/sambaedu/applications/` présent)
**Then** pour chacun des 5 scripts critiques :
- un contexte `$info` identique à la fixture est construit
- `ApplicationScriptsAssembler::assemble($info, $scripts)` est appelé avec `$scripts = (new ApplicationTemplatesScanner)->scan('/usr/share/sambaedu/applications/', '/etc/sambaedu/applications/')`
- la sortie de l'interpréteur attendu (`cmd`/`bash`) est comparée à la fixture via `assertScriptParity()` (réutilisé/factorisé depuis 17.2 — normalisation `DOMAINSID`, `id=__ID__`)
- **`assertSame` strict** après normalisation : aucune autre divergence tolérée (CRLF, charset, séparateurs `REM script[...]`)
**And** chaque test `markTestSkipped` proprement si la fixture OU `/usr/share/sambaedu/applications/` est absent (groupe `requires-fixture-capture`, iso 17.2).

**AC1.3 — Script #1 `wpkg/startup.windows` : présence du fragment ROBOCOPY (audit H.3)**
**Given** le `.cmd` startup Windows assemblé (contexte machine, action `startup`, os `windows`)
**When** le test inspecte la sortie
**Then** le fragment `ROBOCOPY %WinDir%\install\os\SambaEdu %ProgramFiles%\SambaEdu` est présent (mécanisme de déploiement des `install/os/SambaEdu/*.ps1` côté poste)
**And** la parité bytes AC1.2 couvre ce fragment dans son scénario dédié.

### Volet 2 — Parité endpoint natif API v1 (D5, D9)

**AC2.1 — L'endpoint natif répond en GET avec auth JWT pour les actions critiques**
**Given** l'endpoint `/api/v1/workstation-config/applications-scripts` (route `agent.v1.config.applications-scripts`, middleware `auth.v1.workstation`)
**When** `tests/Feature/Gpo/ApplicationsScriptsApiV1Test.php` (nouveau, trait `IssuesWorkstationJwt`) émet une requête **GET** authentifiée (Bearer JWT tier=workstation, `sub` = uuid d'un `Workstation` existant en DB) avec params `os`/`action`/`machine` pour les actions des scripts critiques (`startup` windows, `logon` windows, `logon` linux)
**Then** la réponse a un statut **200**
**And** le `Content-Type` est `text/plain; charset=cp1252` pour `os=windows`, `text/plain; charset=utf-8` pour `os=linux`
**And** le body n'est PAS vide pour un poste résolu (ou body vide documenté iso-legacy si contexte dégénéré).

**AC2.2 — L'endpoint natif rejette l'absence de JWT et le workstation inconnu**
**Given** l'endpoint natif
**When** une requête est émise sans Bearer JWT
**Then** la réponse est **401** (middleware `auth.v1.workstation`)
**And** quand le claim `sub` est un uuid sans `Workstation` correspondant en DB → réponse **404** JSON `{"error":"workstation_not_found"}` (déviation D5 16.13).

**AC2.3 — Vérification de la méthode HTTP du template GPO orchestrateur (D5, optionnel VM)**
**Given** le template GPO `se4_applications` (présent sur VM sous `/usr/share/sambaedu/gpo/sambaedu-gpo/se4_applications/` — répertoire git, cf. audit 17.3 T0.4)
**When** un test (ou la commande artisan `gpo:applications:audit --json` 17.3 invoquée en assertion) lit les `.cmd` orchestrateurs
**Then** le test **documente** la méthode HTTP réellement présente (POST multipart upstream non-patché OU GET query string si `.diff` 17.3 appliqué) sans la modifier
**And** si le template est absent (CI sans VM) → `markTestSkipped`.

> **Note D5** : 17.4 n'altère ni le template ni le `.diff`. Ce test est un **témoin de non-régression** documentant l'état VM au moment de l'exécution.

### Volet 3 — Surcharges admin `/etc/sambaedu/applications/` (D6, audit H.2 + F10)

**AC3.1 — La surcharge locale prend priorité sur le package (test portable)**
**Given** deux répertoires de fixtures locaux : un faux `package/` (copie minimale d'un script critique, ex. `wallpaper/logon.windows`) et un faux `local/` contenant une surcharge du même script avec un contenu **différent et identifiable** (ex. ligne marqueur `REM OVERRIDE_LOCAL_17_4`)
**When** `tests/Feature/Gpo/ApplicationsScriptsLocalOverrideTest.php` (nouveau) appelle `ApplicationTemplatesScanner::scan($fakePackagePath, $fakeLocalPath)` puis `ApplicationScriptsAssembler::assemble($info, $scripts)`
**Then** la sortie assemblée contient le **contenu de la surcharge locale** (`REM OVERRIDE_LOCAL_17_4`) et NON le contenu package pour le script surchargé
**And** les scripts présents uniquement dans `package/` (non surchargés) restent inchangés (merge incrémental iso-legacy `mergeScripts()`).
**And** ce test ne dépend PAS de la VM (fixtures locales arbitraires — exécutable CI).

**AC3.2 — Vérification de la surcharge réelle sur VM (optionnel)**
**Given** la VM avec `/etc/sambaedu/applications/` (peut être vide en pratique)
**When** le test scanne `/usr/share/sambaedu/applications/` + `/etc/sambaedu/applications/`
**Then** soit `/etc/` est vide → test `markTestSkipped` (cas nominal parc)
**Then** soit `/etc/` contient des surcharges → le test vérifie qu'elles sont bien présentes dans la sortie (documenter en Completion Notes la fréquence d'usage réelle observée, question Henri H.2).

### Volet 4 — Placeholders étendus 17.2 substitués runtime (D7)

**AC4.1 — `SE4INSTALL_NAME` substitué dans `wallpaper/logon.windows` (risque bloquant audit)**
**Given** le scénario `windows_logon_wallpaper` (AC1.1)
**When** la sortie assemblée est inspectée
**Then** aucune occurrence de `###_SE4INSTALL_NAME_###` ne subsiste
**And** le filtre `taskkill ... /FI "USERNAME ne <valeur_substituée>"` contient la valeur de config (`sambaedu.se4install_name`, ex. `se4install`) et NON le placeholder littéral (sinon explorer.exe serait tué pour tous les users — audit Section A ligne 645).

**AC4.2 — Aucun placeholder `###_…_###` résiduel dans les 5 scripts critiques**
**Given** les 5 sorties assemblées (AC1.2)
**When** chaque sortie est scannée par regex `/###_[A-Z0-9_]+_###/`
**Then** aucune occurrence n'est trouvée (tous les placeholders consommés par les 5 scripts critiques sont whitelistés 17.2 et substitués)
**And** si une occurrence subsiste → le test échoue avec le nom du placeholder manquant (→ escalade D1, ce serait un trou whitelist 17.2 non couvert).

### Volet 5 — Cas Linux (D8, Q3 audit)

**AC5.1 — `firefox/logon.linux` couvert (≥ 1 cas Linux obligatoire)**
**Given** le scénario `linux_logon_firefox` (AC1.1)
**When** le test de parité bytes Linux est exécuté
**Then** la sortie `bash` est byte-identique à la fixture `linux_logon_firefox/expected.sh`
**And** le heredoc `profiles.ini` est correctement généré (interpréteur `bash`, charset UTF-8, pas de CRLF Windows)
**And** ce cas Linux satisfait l'exigence minimale Q3 audit (≥ 1 cas Linux testé).

### Volet 6 — Régression & non-modification de code (D1)

**AC6.1 — Aucune modification de code applicatif**
**Given** la branche de la story
**When** `git diff --stat` est inspecté
**Then** seuls des fichiers sous `tests/` sont créés/modifiés (Feature + Fixtures + éventuellement Concerns/helper de parité factorisé)
**And** aucun fichier sous `app/`, `config/`, `routes/`, `database/` n'est modifié (D1).

**AC6.2 — Les tests existants 16.7 / 17.2 / 17.3 passent toujours**
**Given** la suite `tests/Unit/Gpo/` + `tests/Feature/Gpo/` (héritée 16.7/17.2/17.3)
**When** la suite est exécutée après ajout des tests 17.4
**Then** aucune régression introduite par 17.4 (les baselines connues — ex. Vite manifest failures documentés 17.3 — restent inchangées en nombre).

**AC6.3 — Tests skippés proprement hors VM**
**Given** un environnement CI sans `/usr/share/sambaedu/applications/`
**When** la suite 17.4 est exécutée
**Then** les tests de parité bytes + endpoint réel sont `markTestSkipped` avec message explicite (groupe `requires-fixture-capture`)
**And** les tests portables (surcharge admin via fixtures locales AC3.1, auth JWT 401/404 AC2.2) **passent** sans VM.

---

## Tasks / Subtasks

### Phase T0 — Investigation préalable & lecture (~1h)

- [x] **T0.1** Lire le pattern parité 17.2 : `tests/Feature/Gpo/ApplicationsScriptsByteParityTest.php` (helper `assertScriptParity()`, `buildLineDiff()`, `configureForFixtures()`, `makeAssembler()`) + `tests/Fixtures/Gpo/applications/README.md` (procédure de capture).
- [x] **T0.2** Lire le contrat de l'endpoint natif : `app/Http/Controllers/Gpo/ApplicationsScriptsController.php` méthode `apiV1` (auth JWT, lecture `auth_v1.workstation_uuid`, validation regex, `resolveContentType`, 404 workstation inconnu) + `routes/api.php:256` (route + middleware).
- [x] **T0.3** Lire le trait `tests/Concerns/IssuesWorkstationJwt.php` + `tests/Feature/Api/V1/Config/ApiV1ConfigSecurityTest.php` (pattern Bearer JWT + `Workstation` en DB).
- [x] **T0.4** Lire le mécanisme surcharge : `app/Gpo/Services/ApplicationTemplatesScanner.php` (`scan($pkg, $local)`, `mergeScripts()` indexé hash).
- [x] **T0.5** Lire les fiches audit des 5 scripts critiques (audit-applications-scripts.md).
- [x] **T0.6** Lire le `.diff` 17.3 pour comprendre l'état POST→GET du template (D5).
- [x] **T0.7** Décision worktree-safe : branche `main`, SSH `/vm` autorisé (Q-4 accordé).

### Phase T1 — Capture fixtures VM des 5 scripts critiques (AC1.1 — ~2-3h, bloquant Henri si worktree)

- [x] **T1.1** Étendre `tests/Fixtures/Gpo/applications/README.md` : nouvelle section « Scénarios Story 17.4 » (5 sous-sections + commande de capture).
- [x] **T1.2** Capturer les 5 fixtures via le mécanisme PHP legacy (`read_application_scripts` + `make_application_scripts`) iso-procédure 17.2 :
  - [x] **T1.2a** `windows_startup_wpkg/expected.cmd` — `wpkg/startup.windows` (fragment ROBOCOPY).
  - [x] **T1.2b** `windows_logon_wallpaper/expected.cmd` — `wallpaper/logon.windows` (`SE4INSTALL_NAME` + `SE4FS_NAME`).
  - [x] **T1.2c** `windows_logon_shortcuts/expected.cmd` — `shortcuts/logon.windows` (`SE4FS_NAME`).
  - [x] **T1.2d** `windows_logon_firefox/expected.cmd` — `firefox/logon.windows` (heredoc `profiles.ini`).
  - [x] **T1.2e** `linux_logon_firefox/expected.sh` — `firefox/logon.linux` (heredoc bash, UTF-8).
- [x] **T1.3** Paquet identique à 17.2 (`4.17.285`) — pas de bump nécessaire. Noté dans README.
- [x] **T1.4** Pas de blocage — tous les `$info` synthétiques ont produit une sortie non-vide.

### Phase T2 — Test de parité bytes des 5 scripts critiques (AC1.2 + AC1.3 — ~2-3h)

- [x] **T2.1** Créé `tests/Feature/Gpo/ApplicationsScriptsCriticalParityTest.php` (groupe `requires-fixture-capture`).
- [x] **T2.2** Extrait `assertScriptParity()` dans le trait `tests/Concerns/AssertsScriptParity.php` (DRY, recommandé SM).
- [x] **T2.3** Écrits 5 tests de parité (un par script critique), chacun avec `$info` iso-fixture + skip propre.
- [x] **T2.4** Test `it_includes_robocopy_deploy_fragment_for_wpkg_startup()` (AC1.3) : ROBOCOPY + `%ProgramFiles%\SambaEdu` vérifiés.

### Phase T3 — Test endpoint natif API v1 (AC2.1 + AC2.2 — ~2h)

- [x] **T3.1** Créé `tests/Feature/Gpo/ApplicationsScriptsApiV1Test.php` (`use IssuesWorkstationJwt`).
- [x] **T3.2** `setUp()` : `configureTestKeyPair()` + `ensureAuthV1Tables()` + `Workstation::create()` avec uuid connu.
- [x] **T3.3** Test GET windows startup authentifié → 200 + `text/plain; charset=cp1252`.
- [x] **T3.4** Test GET windows logon + GET linux logon → 200 + charset correct par OS.
- [x] **T3.5** Test sans JWT → 401.
- [x] **T3.6** Test JWT valide mais uuid sans Workstation en DB → 404 JSON `workstation_not_found`.
- [x] **T3.7** Pas de sous-cas « body non vide » sur VM — comportement iso-legacy (200 vide si resolveInfo=[]).

### Phase T4 — Test surcharges admin (AC3.1 + AC3.2 — ~1-2h)

- [x] **T4.1** Créé `tests/Feature/Gpo/ApplicationsScriptsLocalOverrideTest.php`.
- [x] **T4.2** `it_local_override_wins_over_package()` (AC3.1, portable CI) : deux tmp dirs, marqueur `REM OVERRIDE_LOCAL_17_4`, assertion locale gagne.
- [x] **T4.3** `it_unoverridden_package_scripts_unchanged()` : merge incrémental validé.
- [x] **T4.4** `it_real_vm_local_overrides_present_or_skipped()` (AC3.2, VM) : skip propre — `/etc/sambaedu/applications/` contient uniquement ressources, pas de scripts reconnus.
- [x] **T4.5** Nettoyage en `tearDown` via `File::deleteDirectory()`.

### Phase T5 — Tests placeholders étendus & cas Linux (AC4.1 + AC4.2 + AC5.1 — ~1h)

- [x] **T5.1** `it_substitutes_se4install_name_in_wallpaper_logon()` (AC4.1) : aucun `###_SE4INSTALL_NAME_###` résiduel + `se4install` présent.
- [x] **T5.2** `it_has_no_residual_placeholder_in_critical_script()` (AC4.2, DataProvider 5 cas) : regex → 0 match sur les 5 scripts.
- [x] **T5.3** `it_matches_legacy_bytes_for_firefox_logon_linux()` (AC5.1) : parité bytes + assertion UTF-8 + absence CRLF.

### Phase T6 — Vérification template GPO & méthode HTTP (AC2.3 — ~30min)

- [x] **T6.1** `it_documents_template_http_method_or_skips()` dans `ApplicationsScriptsApiV1Test` : skip propre (template `/usr/share/sambaedu/gpo/sambaedu-gpo/se4_applications/` absent sur cette VM).
- [x] **T6.2** État observé : template absent sur VM de dev (répertoire git non présent). Méthode HTTP non observable directement — contexte 17.3 review en attente de merge.

### Phase T7 — Régression & finalisation (AC6.1-6.3 — ~30min)

- [x] **T7.1** Suite complète sur VM : `./vendor/bin/phpunit --testsuite Feature --filter 'ApplicationsScripts'` → 62 tests, 0 failure, 24 skipped. Tests 17.2 non régressés (3/3).
- [x] **T7.2** `git diff --stat` → seuls fichiers `tests/` + `docs/qa/` touchés (AC6.1).
- [x] **T7.3** Skip propre hors VM : tests portables (AC3.1, AC2.2) passent ; tests VM (parité, endpoint réel AC2.3) skippés.
- [x] **T7.4** File List, Completion Notes, Debug Log References renseignés ci-dessous.

---

## Dev Notes

### Architecture / Patterns

- **Pattern parité bytes (17.2)** : `assertScriptParity($expected, $actual, $normalizers)` applique des regex de normalisation (`SET DOMAINSID=…` → `__SID__`, `id=[0-9a-f]{32}` → `__ID__`) puis `assertSame`. En cas d'échec, `buildLineDiff()` affiche un diff ligne-par-ligne. **Réutiliser tel quel** (factoriser en trait `tests/Concerns/AssertsScriptParity.php` recommandé).
- **`$info` synthétique** : la VM de test n'a pas de postes clients réels dans l'AD → on injecte un `$info` array manuel (machine/user/action/os/context/parcs/list/id). L'`id` doit être **fixé** à la même valeur md5 que lors de la capture fixture (sinon écart d'ID). Cf. `ApplicationsScriptsByteParityTest::it_matches_legacy_bytes_for_windows_logon_user()` pour le modèle exact du tableau `$info`.
- **Scan filesystem** : `ApplicationTemplatesScanner::scan($packagePath, $localPath)` — package lu d'abord, local surcharge via hash `mergeScripts()`. Les deux chemins sont des **arguments** → le test surcharge utilise des répertoires de fixtures arbitraires (portable CI).
- **Endpoint natif** : `ApplicationsScriptsController::apiV1` lit `auth_v1.workstation_uuid` depuis les attributs de requête (posé par middleware `auth.v1.workstation`), résout le `Workstation` Eloquent par uuid (404 si absent), valide les regex iso-legacy, puis `resolveInfo` → `scan` → `assemble`. Charset via `resolveContentType($os)` : cp1252 / utf-8.
- **Auth JWT test** : `issueTestJwt(['sub' => $workstation->uuid, 'tier' => 'workstation'])` → header `Authorization: Bearer <token>`. Le `Workstation` DOIT exister en DB (factory). `configureTestKeyPair()` + `ensureAuthV1Tables()` en `setUp()`.

### Tests Standards

- Framework : **PHPUnit** (attributs `#[Test]`, `#[Group]`, `#[DataProvider]`), pas Pest pour ce domaine (iso 17.2/17.3 Gpo).
- Testsuites : `Feature` (`tests/Feature/Gpo/`), `Unit` non concerné (pas de test unitaire ici — c'est de l'intégration). `phpunit.xml` déclare `Feature` → `tests/Feature`.
- Groupe `requires-fixture-capture` pour les tests dépendant de la VM (skip propre en CI, iso 17.2).
- Skip via `self::markTestSkipped(...)` avec message explicite (fixture absente / scripts package absents / template GPO absent / `/etc/` vide).
- DB de test : SQLite `:memory:` pour les tests endpoint (auth_v1 tables créées par `ensureAuthV1Tables()`).

### Sécurité

- Le test endpoint vérifie l'auth JWT (401 sans token, 404 workstation inconnu) — **ne pas** désactiver le middleware ni mocker l'auth (tester le contrat réel).
- Le `workstation_uuid` vient du claim JWT `sub`, jamais du body/query (iso 16.12 strict) — le test ne doit pas tenter de l'injecter par paramètre.

### Notes opérationnelles déploiement

- **Risque F10 audit** (couche surcharges admin perdue si migration mal faite) : le test AC3.1 garantit que le mécanisme `/etc/` > `/usr/share/` fonctionne ; le packaging Debian `sambaedu-reload` doit préserver `/etc/sambaedu/applications/` lors d'un upgrade — hors scope test mais à mentionner en Completion Notes pour le runbook.
- **Risque audit Section A wallpaper** : `SE4INSTALL_NAME` non substitué = `taskkill` tue explorer.exe pour tous. AC4.1 verrouille ce cas.

### Références

- Cadrage : `_bmad-output/planning-artifacts/epics.md` (Story 17.4, lignes 3463-3465 + Epic 17 lignes 3423-3447)
- Audit fondateur : `_bmad-output/planning-artifacts/audit-applications-scripts.md` — Section A (fiches 5 scripts critiques : firefox 255-285, shortcuts 560-573, wallpaper 631-645, wpkg 696-710), Section G.3 (cadrage 17.4, ligne 1163), Section H.2 (surcharges, 1293), Section H.3 (ROBOCOPY/install, 1302-1331), Q3 (cas Linux, 1538)
- Pattern parité 17.2 : `tests/Feature/Gpo/ApplicationsScriptsByteParityTest.php` + `tests/Fixtures/Gpo/applications/README.md`
- Story 17.2 (done) : `_bmad-output/implementation-artifacts/17-2-portage-moteur-applications-php-whitelist-etendue.md`
- Story 17.3 (review) : `_bmad-output/implementation-artifacts/17-3-compat-gpo-orchestratrice-se4-applications.md` + `17-3-upstream-se4_applications.diff`
- Endpoint natif : `app/Http/Controllers/Gpo/ApplicationsScriptsController.php` (apiV1) + `routes/api.php:245-257`
- Scanner surcharge : `app/Gpo/Services/ApplicationTemplatesScanner.php`
- Trait JWT test : `tests/Concerns/IssuesWorkstationJwt.php` + `tests/Feature/Api/V1/Config/ApiV1ConfigSecurityTest.php`

### File List anticipée (à compléter par le dev)

**Créés** :
- `tests/Feature/Gpo/ApplicationsScriptsCriticalParityTest.php`
- `tests/Feature/Gpo/ApplicationsScriptsApiV1Test.php`
- `tests/Feature/Gpo/ApplicationsScriptsLocalOverrideTest.php`
- `tests/Concerns/AssertsScriptParity.php` (trait factorisé du helper 17.2 — recommandé, optionnel)
- `tests/Fixtures/Gpo/applications/windows_startup_wpkg/expected.cmd`
- `tests/Fixtures/Gpo/applications/windows_logon_wallpaper/expected.cmd`
- `tests/Fixtures/Gpo/applications/windows_logon_shortcuts/expected.cmd`
- `tests/Fixtures/Gpo/applications/windows_logon_firefox/expected.cmd`
- `tests/Fixtures/Gpo/applications/linux_logon_firefox/expected.sh`

**Modifiés** :
- `tests/Fixtures/Gpo/applications/README.md` (section « Scénarios Story 17.4 » + bump version/SHA256 si besoin)
- `tests/Feature/Gpo/ApplicationsScriptsByteParityTest.php` (uniquement si refactor du helper en trait — sinon inchangé)

**Interdits** (D1) : `app/`, `config/`, `routes/`, `database/`.

### Questions ouvertes — à arbitrer par Henri avant/pendant le dev

#### Q-1 — `wpkg/startup.windows` : test parité bytes complet OU assertion fragment ROBOCOPY ?
Le script `wpkg/startup.windows` est statut `Déjà couvert 16.6` (sa chaîne aval est gérée par 16.6). Sa valeur runtime = déclencher le ROBOCOPY de déploiement. **Recommandation SM** : faire les deux — fixture parité bytes (AC1.2) + assertion ciblée ROBOCOPY (AC1.3). Si Henri juge la parité bytes redondante avec 16.6, se limiter à l'assertion ROBOCOPY.

#### Q-2 — Endpoints aval `wallpaper_out`/`shortcuts_out` : tester la chaîne complète OU juste le fragment assemblé ?
`wallpaper/logon.windows` et `shortcuts/logon.windows` appellent au runtime des endpoints (`wallpaper_out.php`/`shortcuts_out.php`, portés natifs 16.13 sous `/api/v1/workstation-config/{wallpaper,shortcuts}`). **Recommandation SM** : 17.4 teste uniquement l'**assemblage du fragment** (le fragment contient l'appel curl à l'endpoint) + l'endpoint `applications-scripts` lui-même (AC2). La joignabilité réelle des endpoints aval (image `wallpaper.jpg` téléchargée, `.lnk` générés) est du ressort de leurs propres stories 16.13/17.6 — la mentionner en Completion Notes comme test E2E manuel runbook si Henri veut une garantie terrain.

#### Q-3 — Cas Linux additionnels (D8) ?
Confirmer si `wallpaper/logon.linux` / `shortcuts/logon.linux` (autres `.linux` actifs LTSP/nomades) doivent être ajoutés au-delà de `firefox/logon.linux`. **Recommandation SM** : non (cadrage epics.md strict ≥ 1 cas Linux, charge 2j). Différer à story de durcissement si terrain le remonte.

#### Q-4 — Capture fixtures : worktree ou main ?
Si le dev tourne en worktree → Henri capture/fournit les 5 fixtures (cf. D3). Si sur main → SSH `/vm` autorisé (exception Q-3 17.2 déjà accordée). Confirmer le contexte d'exécution au lancement.

---

## Dev Agent Record

### Agent Model Used

`claude-sonnet-4-6`

### Debug Log References

**Commande suite principale (VM 2026-05-25)** :
```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 \
  "cd /var/www/sambaedu-reload && ./vendor/bin/phpunit --testsuite Feature --filter 'ApplicationsScripts'"
```
**Résultat** : Tests: 62, Assertions: 61, Failures: 0, Skipped: 24 — OK

**Commande régression 17.2** :
```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 \
  "cd /var/www/sambaedu-reload && ./vendor/bin/phpunit --testsuite Feature --filter 'ApplicationsScriptsByteParityTest'"
```
**Résultat** : Tests: 3, Assertions: 3 — OK (non régressé)

**Capture fixtures (2026-05-25, paquet sambaedu 4.17.285)** :
- Script capture : `/tmp/capture_fixtures_17_4.php` (via `php /tmp/capture_fixtures_17_4.php` sur VM)
- Rapatriement en base64 pour préserver CRLF/charset
- Tailles : wpkg=20866 bytes, wallpaper=8578, shortcuts=8578, firefox-win=8578, firefox-linux=5319 bytes

### Completion Notes List

1. **AC6.1 validé** : seuls `tests/` et `docs/qa/domains/gpo.md` modifiés. Aucun fichier `app/`/`config/`/`routes/`/`database/` touché.

2. **Divergence audit H.3 — chemin ROBOCOPY** : le script `wpkg/startup.windows` réel sur VM utilise `install\os\netinst` comme source (non `install\os\SambaEdu` comme mentionné dans l'audit H.3). L'assertion AC1.3 a été adaptée pour tester `ROBOCOPY` + `%ProgramFiles%\SambaEdu` (destination) qui sont bien présents. Pas de bug applicatif — c'est une imprécision de l'audit par rapport au script réel.

3. **Surcharges `/etc/sambaedu/applications/` (H.2)** : `/etc/sambaedu/applications/` contient 6 sous-dossiers (firefox, once, shortcuts, thunderbird, veyon, wallpaper) avec **uniquement des ressources** (images `.jpg`, `default.json`) — aucun fichier script reconnu par le scanner (pas de `.windows`/`.linux`/`scripts.json`). Le test AC3.2 (`it_real_vm_local_overrides_present_or_skipped`) est skippé avec message explicite documentant cet état. Fréquence surcharges scripts : 0 sur ce serveur. Le mécanisme est validé par AC3.1 (test portable CI sur fixtures locales arbitraires).

4. **Méthode HTTP template GPO (D5/AC2.3)** : le template `/usr/share/sambaedu/gpo/sambaedu-gpo/se4_applications/` est absent sur la VM de dev. Le test `it_documents_template_http_method_or_skips` est skippé proprement. L'état POST→GET du template ne peut être observé que sur un serveur avec le paquet GPO installé. Mentionné dans la checklist runbook QA 17.4-2.

5. **Runbook Q-2 (endpoints aval wallpaper/shortcuts)** : `wallpaper/logon.windows` et `shortcuts/logon.windows` appellent `wallpaper_out.php`/`shortcuts_out.php` au runtime. La joignabilité de ces endpoints aval est du ressort de leurs propres stories (16.13/17.6). Pour validation terrain E2E complète, un test manuel depuis un poste joint au domaine est requis (documenté dans `docs/qa/domains/gpo.md` scénario 17.4-2).

6. **Trait `AssertsScriptParity` extrait** : le helper `assertScriptParity()` + `buildLineDiff()` + `configureForFixtures()` + `makeAssembler()` est extrait dans `tests/Concerns/AssertsScriptParity.php` (DRY). `ApplicationsScriptsByteParityTest.php` n'a **pas** été modifié (sa version inline reste en place — refactoring optionnel documenté, non-régression validée).

7. **Init fixture — `id` md5 distincts** : les 5 scénarios 17.4 utilisent des `id` md5 distincts des 3 scénarios 17.2 (suffixe différent dans la chaîne md5) pour éviter tout conflit de cache APCu.

8. **2 PHPUnit Deprecations** : présentes dans la suite avant 17.4, non introduites par cette story (baseline connue).

### File List (mise à jour post-review 2026-05-25)

**Créés** :
- `tests/Concerns/AssertsScriptParity.php` (trait parité bytes factorisé + helper `applicationsScriptsSource()` snapshot P3 + normalisation `id` resserrée P7)
- `tests/Feature/Gpo/ApplicationsScriptsCriticalParityTest.php` (Volets 1, 4, 5 — restructuré P1/P2/P4/P8 : parité par contexte + assertions ciblées)
- `tests/Feature/Gpo/ApplicationsScriptsApiV1Test.php` (Volet 2 — P5 : seeding cache `apps.<id>` → body non vide)
- `tests/Feature/Gpo/ApplicationsScriptsLocalOverrideTest.php` (Volet 3 — inchangé post-review, AC3.1 déjà portable)
- `tests/Fixtures/Gpo/applications/windows_logon_wallpaper/expected.cmd` (fixture référence blob logon/windows, 8578 bytes)
- `tests/Fixtures/Gpo/applications/linux_logon_firefox/expected.sh` (fixture parité logon/linux, 5319 bytes)
- `tests/Fixtures/Gpo/applications/_package_snapshot/` (**P3** — snapshot portable du package `sambaedu 4.17.285`, 97 fichiers / ~1.4 Mo, SHA256 agrégé `8e0b5be2…`)

**Modifiés** :
- `tests/Feature/Gpo/ApplicationsScriptsByteParityTest.php` (**P6** — `use AssertsScriptParity`, copie inline du helper supprimée)
- `tests/Fixtures/Gpo/applications/README.md` (section snapshot P3 + scénarios par contexte + table normalisation P7)
- `docs/qa/domains/gpo.md` (section Story 17.4 mise à jour post-review : portabilité, P5, checklist 9 points)

**Supprimés (post-review P1/P2 — `gio trash`, byte-identiques redondantes)** :
- `tests/Fixtures/Gpo/applications/windows_logon_shortcuts/expected.cmd` (md5 normalisé `23eac4c1…` = wallpaper)
- `tests/Fixtures/Gpo/applications/windows_logon_firefox/expected.cmd` (md5 normalisé `23eac4c1…` = wallpaper)
- `tests/Fixtures/Gpo/applications/windows_startup_wpkg/expected.cmd` (md5 normalisé `240afc13…` = 17.2 firewall)

**Inchangés** (D1 respecté) :
- Tous fichiers `app/`, `config/`, `routes/`, `database/` : aucune modification.

### Completion Notes — corrections post-review (2026-05-25, claude-opus-4-7[1m])

> Arbitrage Henri appliqué intégralement. Doc review `_bmad-output/codeReviews/17-4.md` → `to-validate`, 8/8 problèmes `✅ Corrigé`.

1. **P1+P2 (clé de voûte)** : `assemble()` concatène tous les fragments d'un contexte → parité byte **globale par contexte**, impossible d'isoler un script. Confirmé md5 normalisé : `windows_logon_{wallpaper,shortcuts,firefox}` identiques (`23eac4c1…`), `windows_startup_wpkg` == 17.2 `windows_startup_firewall` (`240afc13…`). → 3 fixtures redondantes supprimées. Conservées : 1× `logon/windows` (`windows_logon_wallpaper`) + 1× `logon/linux` (`linux_logon_firefox`). Isolation par script via **assertions ciblées de fragment** : heredoc `profiles.ini` (firefox), `taskkill … se4install` (wallpaper, fusion P8), `shortcuts_out.php` (shortcuts), ROBOCOPY (wpkg).
2. **P3** : snapshot `_package_snapshot/` byte-identique au package (vérifié : diff VM/local vide, SHA256 agrégé `8e0b5be2…`). Tests de parité pointent dessus → **portables CI**. Vérifié sur host (sans `/usr/share/sambaedu/applications/`) : `ApplicationsScriptsCriticalParityTest` = 9/9 PASS, 0 skip.
3. **P4** : ligne ROBOCOPY complète testée via regex (`"%WinDir%\install\os\netinst" "%ProgramFiles%\SambaEdu"`). `netinst` = référence VM légitime (validé Henri).
4. **P5** : seeding réussi (Option A) — pré-pose `apps.<id>` dans le cache `app_context` (driver `array` en test) → `resolveInfo` court-circuite LDAP → `assemble()` déclenché → body NON vide asserté (marqueur `REM`/`#!/bin/bash`). **Sans toucher au code applicatif** (D1).
5. **P6** : 17.2 `use AssertsScriptParity` (copie inline supprimée). Normalizers `id` additionnels no-op sur 17.2 (id figé) — confirmé 3/3 PASS VM.
6. **P7** : regex `id` restreinte à 3 ancres (`SET id=`, `^id=` bash, `-F "id="`). Audit fragments : aucun hash non-session masqué (firefox `308046B0AF4A39CB` 16-MAJ hors champ ; once `md5_file` jamais préfixé `id=`). Documenté.
7. **P8** : ligne `taskkill /F /IM explorer.exe /FI "USERNAME ne se4install"` complète assertée (intégrée à l'assertion wallpaper P1).
8. **Résultats** : host CI **et** VM → 0 failure. Suite Gpo complète 546 tests, 0 failure (87 skip VM / 91 skip host, 3 risky préexistants hors 17.4).
9. **Alerte inotify** : les 3 fixtures supprimées restent en fichiers fantômes sur la VM (`/var/www/sambaedu-reload/tests/Fixtures/Gpo/applications/windows_logon_{shortcuts,firefox}/`, `windows_startup_wpkg/`) — inotify ne propage pas les deletes. Cleanup VM manuel à demander à Henri (sans impact sur les tests).

---

## Recommandation Modèle Dev

**Recommandation : `sonnet`** (avec bascule `opus` possible si T1-T2 dérive).

### Justification

**Périmètre technique réel** : cette story est **essentiellement de l'écriture de tests décalqués** du pattern de parité 17.2 (déjà livré et validé), étendus à 5 scripts + un endpoint + une surcharge. Tout est mécanique :

- **Volet 1 (parité 5 scripts)** = copie quasi-conforme de `ApplicationsScriptsByteParityTest` 17.2 (même helper `assertScriptParity`, même structure `$info`, même skip pattern). Le seul travail non-trivial = la capture des fixtures (procédure documentée 17.2, réutilisée). Pas de décision d'architecture.
- **Volet 2 (endpoint API v1)** = pattern d'auth JWT déjà établi (`IssuesWorkstationJwt` + `ApiV1ConfigSecurityTest`). Assertions statut/charset/401/404 mécaniques.
- **Volet 3 (surcharge)** = test portable sur deux répertoires de fixtures + appel `Scanner::scan()` (signature 16.7 stable).
- **Volets 4-6** = assertions regex (placeholders résiduels) + lancement de suite (régression).

**Charge réelle 2j** alignée sprint-status. Aucune nouvelle modélisation, aucun code applicatif (D1 strict), aucun algorithme. Sonnet a livré 16.12/16.13/16.15 + le dev de 17.2 (qui a produit exactement ce pattern de parité) sans dérive.

**Pourquoi pas opus** :
- Pas de décision archi transverse (toutes tranchées D1-D9).
- Pas de logique métier critique nouvelle — on **teste** du code existant, on n'en écrit pas.
- Le travail conceptuel (audit 17.1, design parité 17.2) est déjà fait.

**Conditions de bascule opus en cours de dev** (escalade Henri, pas auto-correction) :
- Si une parité bytes échoue de façon non-évidente après normalisations → suggère un **bug dans l'Assembler 16.7/17.2** (= scope 17.2 rouvert, D1 interdit la correction ici).
- Si un placeholder reste non substitué runtime (AC4.2 échoue) → trou whitelist 17.2 non couvert → escalade.
- Si la surcharge admin est ignorée par le scanner (AC3.1 échoue) → bug 16.7 → escalade.

Dans ces cas, le dev sonnet **signale à Henri** (D1) au lieu de patcher le code applicatif.
