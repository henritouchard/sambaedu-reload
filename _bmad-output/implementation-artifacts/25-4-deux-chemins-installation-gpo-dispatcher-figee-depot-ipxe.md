# Story 25.4: Les deux chemins d'installation — GPO-dispatcher figée (bootstrap + filet) et dépôt iPXE

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant que **mainteneur SambaEdu**,
je veux **que « la dernière GPO de l'histoire » installe et répare l'agent sur les postes migrés, et que la chaîne iPXE le dépose sur les postes neufs**,
afin **que tout poste du parc, quel que soit son passé, finisse avec un agent vivant**.

## Contexte & intention

Quatrième story de l'**Epic 25** (« Gestion de flotte — distribution canari, bootstrap GPO, porte des postes migrés »). Elle livre les **deux chemins d'installation** de l'agent (FR25 + porte 1 de FR16), prérequis de tout déploiement hors lab :

- **Chemin neuf (iPXE/WinPE)** : un poste installé par la chaîne iPXE a déjà son **token** déposé au FirstLogon (porte 1, story 23.3 `done`). Cette story **ajoute** à l'unattend le **dépôt du binaire agent stable** (servi par SE5, 25.1) + le **déploiement de la racine CA**, puis l'enregistrement du service. Un poste neuf n'a **jamais** besoin de la GPO.
- **Chemin migré (GPO-dispatcher figée)** : un poste existant joint au domaine, **sans token**, reçoit l'agent via une **GPO générique figée** — le **dernier artefact AD, jamais ré-édité**. Son script de démarrage déploie la CA, dépose le binaire stable, lance `agent.exe install`. L'agent, une fois posé, **demande son enrôlement (porte 2, 25.3)** et converge dès l'approbation un-clic de l'admin. La même GPO **réinstalle un agent briqué/supprimé au passage suivant** — le filet éternel (#27).

Les briques amont existent toutes et ont été conçues pour ce point de jonction :

- **L'agent Go expose déjà `agent.exe install -server-url … [-interval …]`** : enregistrement SCM idempotent (arrêt/suppression/recréation d'un service existant). [Source: agent/windows/install_windows.go:14-22 ; agent/windows/main_windows.go:15-16,66-76]
- **Le serveur porte 2 est complet (25.3)** : un `POST /v1/agent/enrollment` **sans ticket** retombe sur `handleGate2()` → crée une demande `pending` (403 indistinct), ou émet le token 200 si une demande `approved` concordante existe, ou 409 si la MAC matche un poste déjà enrôlé. [Source: app/Services/Agent/Enrollment/EnrollmentService.php:109,121,178-211 ; app/Http/Controllers/Api/V1/Agent/EnrollController.php:42-49]
- **La racine CA existe déjà** (`CaInitializer::getCaCertPem()`, PKI Auth v1 story 16.10) et est **déjà servie en clair** dans le fragment de migration legacy + la réponse d'enrôlement JWT — précédent direct pour la servir aux chemins d'amorçage. [Source: app/Auth/V1/Pki/CaInitializer.php:229-241 ; app/Auth/V1/Migration/Services/MigrationFragmentRenderer.php:342-374 ; app/Auth/V1/Http/Controllers/EnrollController.php:105]
- **Le binaire stable est résolu par `ReleaseManifestService`** (fallback `is_stable` pour tout poste sans ring) et servi par `ReleaseController::download()` (confinement realpath strict). [Source: app/Services/Agent/Releases/ReleaseManifestService.php ; app/Http/Controllers/Api/V1/Agent/ReleaseController.php:89-140]

**Le verrou architectural à lever** : les routes 25.1 (`agent.v1.release` / `…release.download`) sont **authentifiées par token agent** (`agent.token`). Or les deux chemins d'amorçage tournent **avant** que l'agent ait un token (WinPE l'obtient au FirstLogon, le poste migré ne l'aura qu'après approbation). Il faut donc des **endpoints d'amorçage LAN non authentifiés** (binaire stable + CA), sur le modèle de `/v1/agent/enrollment` (`local.request`, pas de bearer). [Source: routes/api.php:270 ; epics-agent-desired-state.md:595-598]

## Décisions de cadrage actées avec Henri (2026-06-13)

> Deux forks tranchés avant rédaction. **À challenger en review, pas à re-trancher en dev.**

1. **Fork 1 — le client porte 2 vit dans l'agent Go (option B).** L'agent s'auto-enrôle : la **garde token de `installService` est relâchée** (l'install procède sans token), et le **run loop** poste sa demande d'enrôlement (porte 2) tant qu'il n'a pas de token. Garde-fous inscrits comme invariants : (a) un poste `rejected` n'est **jamais** ré-ouvert (25.3 décision n° 2) — l'agent boucle dans le vide sans escalade ; (b) l'**auto-approbation reste serveur-side** (25.3), bornée par la campagne — l'agent ne décide rien, il **demande** ; (c) `local.request` (LAN/VPN) cantonne déjà l'appelant au réseau de confiance. **La faille résiduelle (MAC spoofée sous campagne active) est commune à tout client porte 2 et a été actée en 25.3 (#M1) — l'option B ne l'introduit ni ne l'aggrave** ; elle déplace seulement le client de PowerShell vers Go. [Décision Henri 2026-06-13]
2. **Fork 2 — la GPO-dispatcher figée = template + runbook de publication manuel (Administrator), PAS d'automatisation de publication.** Justification : la GPO est « jamais ré-éditée » (automatiser une publication unique a un ROI quasi nul) et automatiser buterait d'abord sur le **bloqueur de droits SYSVOL connu** (`www-sambaedu` n'a que READ → `mkdir`/`put` ACCESS_DENIED, `smbclient` sort en 0 = faux succès — mémoire `project_sysvol_wwwadmin_no_write_rights_and_silent_success`). Le runbook réutilise le **workaround Administrator déjà éprouvé** (11 GPO publiées 2026-06-08). La surface testable Laravel = endpoints binaire+CA + unattend. Le template GPO vit **server-side sous `/usr/share/sambaedu/gpo/`** (hors git, convention `project_storage_convention_non_versioned`). [Décision Henri 2026-06-13]

## ⚠️ Pièges connus (lire avant de coder)

1. **`installService` exige un token AUJOURD'HUI — c'est la garde à relâcher (Fork 1).** `installService()` fait `store.ReadToken()` et **avorte** si absent (« le poste n'est pas enrôlé ? »). Pour le chemin migré (porte 2, sans token), l'install doit **procéder sans token** : écrire la config, créer l'arborescence/ACL, enregistrer + démarrer le service. La garde ne protégeait que contre un agent à moitié configuré ; sa suppression est **bénigne** (sans token = zéro convergence, juste des check-ins légers). Ne supprimez PAS l'écriture de config/layout/ACL — seulement la garde token. [Source: agent/windows/install_windows.go:22-48]
2. **Le run loop relit le token à CHAQUE cycle — c'est le point d'accroche de l'auto-enroll.** `loop.go` fait `a.Store.ReadToken()` au début de chaque cycle. Aujourd'hui, token absent = échec du cycle. Nouveau comportement : token absent → **mode demande d'enrôlement** (poster la porte 2, cf. décision de design n° 2), PAS un arrêt. Ne touchez pas au flux nominal (token présent → GET /state / POST /report inchangés). [Source: agent/shared/loop.go:133-140]
3. **La demande porte 2 = POST SANS bearer, ticket vide.** L'endpoint `POST /v1/agent/enrollment` accepte un `ticket` optionnel (`?? ''`) + `uuid`/`mac`/`hostname` ; ticket absent → `handleGate2()`. L'agent n'a PAS de token : la requête part **sans `Authorization`** (le client Go n'envoie le bearer que si `SetToken` a été appelé). **Le token de réponse est dans le CORPS JSON `{success, token}`**, PAS dans l'en-tête de rotation D5 — parsez le body (comme le PowerShell unattend `$r.token`), n'attendez pas `applyRotation()`. [Source: app/Http/Controllers/Api/V1/Agent/EnrollController.php:42-49 ; agent/shared/client.go:73-90 ; resources/ipxe/windows/unattend.xml:120]
4. **Trois réponses serveur à la demande porte 2, trois comportements agent distincts.** (a) **403** `AGENT_ENROLL_NOT_ALLOWED` (pending, indistinct) → check-ins légers, on retentera au prochain cycle (jamais de backoff agressif — iso quarantaine `loop.go:183-186`). (b) **200** `{token}` (demande approuvée concordante consommée) → écrire le token (atomique + ACL SID), basculer en convergence. (c) **409** `conflict` (la MAC matche un poste **déjà enrôlé**) → log + check-ins légers, **jamais** de ré-enrôlement automatique silencieux (clone potentiel — c'est le serveur qui tranche). [Source: app/Services/Agent/Enrollment/EnrollmentService.php:178-211 ; epics-agent-desired-state.md:574]
5. **L'agent doit collecter sa MAC pour le faisceau — elle n'est peut-être pas encore câblée.** Le faisceau porte 2 = `uuid` (déjà via `smbiosUUID()`) + `hostname` (court, déjà) + **`mac`** (ancre fiable de rapprochement, 25.3). Vérifiez si un collecteur MAC existe ; sinon, ajoutez-en un (iso-legacy : adaptateur actif, format quelconque — le serveur normalise via `MacAddressNormalizer`). **N'envoyez jamais une MAC inventée/vide en silence** : une MAC absente rend la demande non auto-approuvable (jamais rapprochable). [Source: agent/windows/smbios_windows.go:24 ; agent/shared/client.go:49 ; app/Ipxe/Support/MacAddressNormalizer.php]
6. **Endpoints d'amorçage = NON authentifiés, `local.request`, hors du groupe `agent.token`.** Les nouveaux endpoints (binaire stable + CA) tournent sans token → middleware `local.request` (LAN-only) comme `/v1/agent/enrollment`, **PAS** `agent.token`. Ne les ajoutez **pas** dans le bloc des routes authentifiées. ⚠️ Les noms `agent.v1.bootstrap.{cmd,sh}` ont été **supprimés** (routes/api.php:25) — n'y revenez pas ; choisissez des noms distincts (ex. `agent.v1.stable` / `agent.v1.stable.download` / `agent.v1.ca`). [Source: routes/api.php:25,270]
7. **Fenêtre 1500 chars `ScriptsOsNamespaceTest`.** Insérer les nouveaux blocs de routes API **APRÈS** le groupe 16.12, jamais juste avant (mémoire `api_routes_arch_test_window_trap`). Lancer `php artisan test --filter ScriptsOsNamespace` après ajout. [Source: routes/api.php:266-267]
8. **Réutiliser le confinement realpath de `ReleaseController::download()`, ne pas le réinventer.** Le téléchargement du binaire stable (non authentifié) doit garder le **même** garde-fou : pattern de nom strict → lookup DB d'abord → `realpath` confiné sous `config('agent.releases_path')` → 404 indistinct sinon. La seule différence avec 25.1 = la **résolution** (toujours la stable `is_stable`, jamais un ring) et le **middleware** (`local.request` au lieu de `agent.token`). [Source: app/Http/Controllers/Api/V1/Agent/ReleaseController.php:52,89-140 ; config/agent.php:60]
9. **CA non initialisée = 503, pas 500.** `CaInitializer::getCaCertPem()` lève `RuntimeException` si le `.crt` est absent. L'endpoint CA doit **catcher** et renvoyer **503** (config serveur incomplète, pas une erreur client), comme le controller de migration. Documenter `php artisan auth:ca:init` comme prérequis serveur. [Source: app/Auth/V1/Pki/CaInitializer.php:229-241 ; app/Auth/V1/Migration — gestion 503]
10. **Le binaire est déposé à son emplacement DÉFINITIF avant `install` — le SCM enregistre ce chemin.** `installService` enregistre `os.Executable()` comme `ImagePath`. Les scripts d'amorçage doivent donc télécharger le binaire vers `C:\Program Files\SambaEdu\Agent\agent.exe` (emplacement recommandé, ACL SYSTEM) **puis** exécuter `agent.exe install` **depuis cet emplacement**. Ne pas l'exécuter depuis `%temp%`. [Source: agent/windows/install_windows.go:20-21]
11. **Token hors périmètre de l'install/du swap — ne jamais l'écraser.** L'install (réparation comprise) et l'auto-update (25.2) ne touchent **pas** au fichier `token` (contrat figé 23.3, `C:\ProgramData\SambaEdu\Agent\token`, 64 hex, ACL SID). Sur un poste enrôlé dont l'agent est briqué, la réinstall **conserve** le token → l'agent repart en convergence directe (pas de ré-enrôlement). Sur sysprep, le token est purgé (obligatoire — mémoire `project_agent_token_file_path_contract`). [Source: agent/shared/files.go:64-108 ; agent/README.md:144]
12. **CRLF obligatoire pour les fragments cmd/bat (WinPE + GPO).** Tout script `.cmd`/`.bat` servi à WinPE ou déposé dans SYSVOL doit finir en `\r\n` — LF seul échoue silencieusement (mémoires `project_migration_passthrough_gpo_lab`, domaine ipxe.md). Les blocs PowerShell de l'unattend suivent le formatage existant (FirstLogonCommands). [Source: resources/ipxe/windows/unattend.xml:107-140]
13. **`setup.exe` ne rend jamais la main depuis WinPE.** Tout post-traitement poste neuf passe par l'**unattend** (specialize / FirstLogonCommands), jamais « après `setup.exe` » (mémoire `project_winpe_setup_never_returns`). Le dépôt binaire+CA+install s'ajoute donc dans les **FirstLogonCommands**, après l'enrôlement (Order 1) et avant/autour du curl oobe (Order 3). [Source: resources/ipxe/windows/unattend.xml:107-140]
14. **Déploiement CA = magasin LocalMachine\Root, idempotent.** `certutil -addstore -f Root <ca.crt>` (ou `Import-Certificate -CertStoreLocation Cert:\LocalMachine\Root`) — dédup par empreinte, ré-exécutable sans effet de bord. Contexte requis : SYSTEM (GPO startup) ou admin autologon (WinPE FirstLogon) — les deux l'ont. La CA est le **prérequis de confiance** de la signature Authenticode du binaire (NFR6) ET du TLS quand on passera en HTTPS.
15. **Frontière `agent_*` + zéro AD (critère Keycloak NFR7).** Les endpoints d'amorçage **lisent** `agent_releases` (stable) et le `.crt` PKI sur disque ; ils n'écrivent rien et n'appellent **aucun** LdapRecord/Kerberos/samba-tool. La GPO-dispatcher est posée **une fois** dans l'AD (bootstrap) puis l'AD n'est plus jamais touché. `grep` de revue : zéro `ldap`/`kerberos`/`samba-tool` dans le code neuf de cette story. [Source: architecture-agent-desired-state.md:126 (NFR7) ; epics-agent-desired-state.md:86]
16. **La GPO ne contient AUCUNE logique métier (dispatcher générique).** Le template = scripts génériques figés (event → install/réparation), seule spécialisation = `###_SE4FS_NAME_###` / `###_DOMAIN_###` (nom serveur, figé 1× à la publication). Machine/user résolus au runtime. Pas de `Registry.pol`, pas de churn SYSVOL, pas de re-spécialisation. C'est le pattern « GPO-dispatcher statique » du spike. [Source: spike-windows-anchor-2026-06-08.md:27-50,162-181 ; epics-agent-desired-state.md:598]

## Décisions de design prises ici (à challenger en review, pas à re-trancher en dev)

1. **Trois endpoints d'amorçage LAN non authentifiés** (préfixe `/v1/agent/`, middleware `['local.request', 'throttle:…']`, hors groupe `agent.token`) :
   - `GET /v1/agent/stable` → manifest stable `{success, version, hash, url}` (URL **absolue** du download, iso 25.1) ; 404 `no_release` si aucune stable. Réutilise `ReleaseManifestService` **forcé sur la stable** (jamais de résolution par ring — l'appelant n'a pas de token, donc pas de poste résolu). Permet aux scripts de **vérifier le hash** avant install (cohérent avec la double-porte 25.2).
   - `GET /v1/agent/stable/download` → binaire stable (octet-stream), confinement realpath iso `ReleaseController::download()`, 404 indistinct.
   - `GET /v1/agent/ca` → racine CA en PEM (`text/plain`), via `CaInitializer::getCaCertPem()` ; **503** si CA non initialisée (piège n° 9).
   - Noms proposés : `agent.v1.stable`, `agent.v1.stable.download`, `agent.v1.ca` (éviter `bootstrap`, supprimé). Le dev confirme l'emplacement exact (piège n° 7).
2. **Auto-enroll agent Go (Fork 1 = B)** — découpage `shared/` (testable Linux) × `windows/` (primitives réelles) iso 25.2 :
   - `installService` : retirer la garde `ReadToken()` (piège n° 1) ; tout le reste inchangé.
   - `loop.go` : extraire le « token absent » en branche **demande d'enrôlement**. Nouvelle fonction `shared/` (ex. `requestEnrollment(client, identity) (token string, outcome Outcome)`) testable Linux : construit le faisceau `{uuid, mac, hostname}`, `client.Post("/v1/agent/enrollment", body)` **sans bearer**, mappe 200→token / 403→pending(light) / 409→conflict(light) / autre→backoff. Le token obtenu est écrit via `store.WriteToken` (atomique + ACL) puis le cycle bascule en convergence (relire token, GET /state…).
   - `windows/` : collecteur MAC (piège n° 5) si absent ; `smbiosUUID()` réutilisé.
   - **Anti-boucle** : un cycle qui reçoit 403/409 ne déclenche **pas** de re-tentative immédiate — cadence normale (timer + jitter), exactement comme la quarantaine. Pas de re-enrôlement après un 401 irrécupérable sur /state (re-enrôlement = humain/bootstrap, jamais auto — FR22).
3. **Unattend porte 1 — dépôt binaire + CA + install dans les FirstLogonCommands**, après l'enrôlement (Order 1, déjà là) :
   - Nouvel `Order` (après le durcissement ACL du token, Order 2) : déploie la CA (`certutil -addstore -f Root` depuis `…/v1/agent/ca`), télécharge le binaire stable (`…/v1/agent/stable/download`) vers `C:\Program Files\SambaEdu\Agent\agent.exe`, exécute `agent.exe install -server-url http://###_SE4FS_NAME_###`. Échec non bloquant (retries bornés iso le bloc enrôlement) — un poste neuf dont l'install agent échoue retombe sur le **filet GPO** au prochain boot.
   - `WindowsUnattendBuilder` : les URLs réutilisent `###_SE4FS_NAME_###` déjà interpolé ; aucune nouvelle donnée sensible (pas de secret — binaire/CA publics LAN). Si de nouveaux placeholders sont nécessaires, valider via le sanitizer existant.
   - **Idempotence/ordre** : l'install agent passe **après** le token (la convergence pourra démarrer immédiatement, porte 1) mais l'agent tolère le token absent (auto-enroll) — donc l'ordre token↔install n'est pas un point de fragilité.
4. **GPO-dispatcher figée = template server-side + runbook (Fork 2)** :
   - **Template** sous `/usr/share/sambaedu/gpo/` (forme conforme `GpoTemplateRegistry` : préfixe `se4_`, `GPT.INI` avec `[CSE]`). Contenu : `Machine/Scripts/Startup/startup.cmd` (générique) qui (a) déploie la CA, (b) télécharge le binaire stable à l'emplacement définitif, (c) lance `agent.exe install -server-url http://%SE4FS%` (idempotent → installe **ou** répare), (d) **pose/recrée une tâche planifiée de refresh** (N min, SYSTEM) qui rejoue (a)-(c) — le filet éternel et le « poste jamais éteint » du spike. Auto-réparation : le startup recrée la tâche si absente. **Aucune logique métier** (piège n° 16).
   - **Runbook** (doc) : publication **Administrator** vers SYSVOL (workaround connu), liaison à la racine du domaine ou OU Parcs, vérification. Le template n'est **pas** publié par du code applicatif dans cette story (Fork 2).
   - Nom proposé : `se4_agent_bootstrap` (distinct de `se4_applications` qui reste le dispatcher de **config** legacy — ne pas les confondre).
5. **Pas de vérification Authenticode côté serveur ni côté script d'install.** L'intégrité au premier dépôt repose sur (a) le canal LAN/VPN de confiance (`local.request`), (b) le hash optionnellement vérifié par le script via le manifest stable, (c) la **signature Authenticode validée ensuite à chaque auto-update par l'agent (25.2)**. Le serveur ne re-signe/re-vérifie rien (iso décision 25.1). [Source: docs/agent/release-distribution.md ; story 25.2]
6. **Aucune route API neuve côté canal authentifié, aucune nouvelle table.** Cette story ne crée pas de table : elle lit `agent_releases` (25.1) et le `.crt` PKI (16.10). Les seules écritures DB éventuelles sont serveur-side via les services existants (aucune ici). Les logs neufs vont au channel `agent` (`agent.release.stable_served`, `agent.ca.served`, `agent.enroll.requested` réutilisé côté agent indirectement via le serveur 25.3).

## Acceptance Criteria

### AC1 — La GPO-dispatcher figée installe l'agent sur un poste migré (FR25)

**Given** un poste migré joint au domaine, **sans agent**, sur lequel la GPO-dispatcher `se4_agent_bootstrap` est liée
**When** la GPO s'exécute (boot ou refresh planifié)
**Then** le script générique (a) déploie la racine CA dans `LocalMachine\Root`, (b) télécharge le **binaire stable** depuis SE5 (`/v1/agent/stable/download`, non authentifié LAN) vers `C:\Program Files\SambaEdu\Agent\agent.exe`, (c) exécute `agent.exe install` → service SYSTEM enregistré
**And** la GPO **« se tait »** ensuite : elle ne contient **aucune logique métier** (event → install/réparation uniquement), seule spécialisation `###_SE4FS_NAME_###`/`###_DOMAIN_###` — c'est le dernier artefact AD, jamais ré-édité (toute évolution = auto-update 25.2)
**And** une fois posé, l'agent **demande son enrôlement (porte 2)** et reste en check-ins légers (403) jusqu'à l'approbation un-clic (25.3).

### AC2 — La même GPO répare un agent briqué/supprimé — le filet éternel (#27)

**Given** un poste dont l'agent est briqué, supprimé, ou dont le service a disparu
**When** la GPO-dispatcher repasse (boot ou tâche de refresh planifiée posée par la GPO elle-même)
**Then** `agent.exe install` (idempotent : arrêt/suppression/recréation) **réinstalle** l'agent au même emplacement
**And** si le poste était déjà enrôlé, le **token survit** (hors périmètre de l'install) → l'agent repart **directement** en convergence, sans ré-enrôlement
**And** la tâche de refresh est **recréée par le startup si absente** (auto-réparation du filet).

### AC3 — Un poste neuf (iPXE/WinPE) dépose binaire + token + CA, sans GPO (porte 1, FR16)

**Given** un poste neuf installé par la chaîne iPXE/WinPE
**When** les FirstLogonCommands de l'unattend s'exécutent
**Then** après l'obtention du **token** (Order 1, déjà en place 23.3), l'install **déploie la CA**, **télécharge le binaire stable** (dernière version servie par SE5) à l'emplacement définitif, et exécute `agent.exe install -server-url http://###_SE4FS_NAME_###`
**And** un poste neuf **n'a jamais besoin de la GPO** : il finit avec un agent vivant, déjà enrôlé (token présent → convergence immédiate)
**And** un échec d'install agent au FirstLogon est **non bloquant** (retries bornés) et rattrapable par le filet GPO.

### AC4 — Endpoints d'amorçage LAN + CA déployée par les deux chemins (NFR6, NFR7)

**Given** un appelant sur le LAN sans token agent (script GPO ou WinPE)
**When** il appelle `GET /v1/agent/stable` / `GET /v1/agent/stable/download` / `GET /v1/agent/ca`
**Then** il reçoit le manifest stable `{version, hash, url}` (URL absolue) / le binaire stable (confinement realpath, 404 indistinct sinon) / la racine CA en PEM
**And** ces endpoints sont en `local.request` (LAN-only), **hors** du groupe `agent.token`, **ne lisent que** `agent_releases` + le `.crt` PKI, **n'appellent aucun** LdapRecord/Kerberos/samba-tool (critère Keycloak)
**And** la racine CA est déployée par **les deux** chemins (prérequis de la signature Authenticode) ; CA non initialisée côté serveur → **503** (config incomplète), jamais 500.

### AC5 — L'agent Go s'auto-enrôle (porte 2) sans token, sans se briquer (Fork 1 = B)

**Given** un agent installé sur un poste **sans token** (chemin migré)
**When** le run loop démarre un cycle et ne trouve pas de token
**Then** il poste sa demande d'enrôlement porte 2 (`POST /v1/agent/enrollment`, **sans bearer**, faisceau `{uuid, mac, hostname}`, ticket vide) et interprète : **403** → check-ins légers, retentés à cadence normale ; **200 `{token}`** → écriture atomique du token (ACL SID) puis bascule en convergence ; **409** → log conflit + check-ins légers, **jamais** de ré-enrôlement automatique silencieux
**And** `installService` **n'avorte plus** faute de token (garde retirée), mais écrit toujours config + arborescence + ACL
**And** un poste `rejected` boucle dans le vide (jamais ré-ouvert, 25.3 décision n° 2) — aucune escalade, aucun brick
**And** le flux nominal (token présent) est **inchangé** : GET /state / POST /report / rotation D5 intacts.

### AC6 — Observabilité, frontière, golden files

**Given** les chemins d'amorçage et l'auto-enroll
**When** ils s'exécutent
**Then** logs au channel `agent` : `agent.release.stable_served` / `agent.ca.served` (serveur), demande porte 2 → `agent.enroll.requested` (déjà émis serveur-side, 25.3) ; **jamais** de token/hash en clair dans les logs
**And** aucune écriture hors `agent_*` ; golden files figés intouchés (state/report/release-manifest/contract-v1) ; les fixtures cross-tests `tests/Fixtures/Agent/` restent la source de vérité du contrat.

## Tasks / Subtasks

- [ ] **Tâche 1 — Endpoints d'amorçage LAN serveur** (AC4) [Source: pièges 6,7,8,9]
  - [ ] `GET /v1/agent/stable` : controller (réutiliser/étendre `ReleaseController` ou un `BootstrapController` neuf) → `ReleaseManifestService` **forcé stable** ; réponse `{success, version, hash, url}` URL absolue ; 404 `no_release`.
  - [ ] `GET /v1/agent/stable/download` : binaire stable, confinement realpath iso `ReleaseController::download()`, 404 indistinct.
  - [ ] `GET /v1/agent/ca` : `CaInitializer::getCaCertPem()` en `text/plain` ; catch `RuntimeException` → **503**.
  - [ ] Routes en `['local.request', 'throttle:…']`, **hors** groupe `agent.token`, **après** le groupe 16.12 (fenêtre 1500 chars) ; noms `agent.v1.stable*` / `agent.v1.ca` (PAS `bootstrap`).
  - [ ] Logs `agent.release.stable_served`, `agent.ca.served`.
- [ ] **Tâche 2 — Agent Go : relâcher la garde + auto-enroll porte 2** (AC5) [Source: pièges 1,2,3,4,5 ; décision design 2]
  - [ ] `installService` : retirer la garde `ReadToken()`, conserver config/layout/ACL/SCM.
  - [ ] `shared/` : `requestEnrollment(client, identity)` testable Linux (POST sans bearer, parse body `{token}`, mapping 200/403/409/autre).
  - [ ] `loop.go` : branche « token absent → requestEnrollment » ; 200 → `WriteToken` + bascule convergence ; 403/409 → check-ins légers cadence normale ; flux nominal inchangé.
  - [ ] `windows/` : collecteur MAC pour le faisceau (si absent) ; `smbiosUUID()` + hostname court réutilisés.
- [ ] **Tâche 3 — Unattend porte 1 : dépôt CA + binaire + install** (AC3) [Source: pièges 10,11,12,13,14 ; décision design 3]
  - [ ] `resources/ipxe/windows/unattend.xml` : nouvel `Order` après le token → certutil CA + download binaire vers emplacement définitif + `agent.exe install -server-url http://###_SE4FS_NAME_###` ; échec non bloquant (retries bornés).
  - [ ] `WindowsUnattendBuilder` : URLs réutilisent `###_SE4FS_NAME_###` ; pas de secret neuf.
  - [ ] CRLF respecté ; XML bien formé.
- [ ] **Tâche 4 — Template GPO-dispatcher figée + runbook** (AC1, AC2) [Source: pièges 14,16 ; décision design 4 ; spike-windows-anchor]
  - [ ] Template `se4_agent_bootstrap` (source dans le repo sous `tests/Fixtures/Gpo/…` ou `resources/`, déployable vers `/usr/share/sambaedu/gpo/`) : `GPT.INI` `[CSE]`, `Machine/Scripts/Startup/startup.cmd` générique (CA + binaire + `agent.exe install` + pose/recrée tâche refresh) ; auto-réparation.
  - [ ] Runbook de publication **Administrator** (SYSVOL + liaison + vérif), workaround droits documenté.
  - [ ] Vérifier la conformité du template à `GpoTemplateRegistry` (préfixe, GPT.INI) — la publication elle-même reste manuelle (Fork 2).
- [ ] **Tâche 5 — Tests** (AC1-AC6)
  - [ ] Serveur : feature tests des 3 endpoints (200/404/503, confinement realpath, `local.request` rejette hors LAN, lecture seule `agent_*`, zéro AD).
  - [ ] Agent : `go test ./...` — `requestEnrollment` (200/403/409/réseau KO), install sans token, boucle « token absent → demande → 403 → cadence normale », « 200 → convergence », non-régression flux nominal.
  - [ ] Unattend : `WindowsUnattendBuilderTest` (XML bien formé, présence des Orders CA/binaire/install, URLs interpolées) + endpoint test.
  - [ ] `ScriptsOsNamespaceTest` vert (routes).
- [ ] **Tâche 6 — Docs** (AC6)
  - [ ] `docs/agent/enrollment.md` : §porte 2 — câblage du **client** agent (auto-enroll), flux 403/200/409, ordre install↔enrôlement.
  - [ ] `docs/agent/release-distribution.md` : addendum endpoints d'amorçage non authentifiés (stable + CA).
  - [ ] `docs/qa/domains/agent.md` : Section 11 (scénarios e2e 11.x : GPO bootstrap, filet, poste neuf, auto-enroll) — append-only.
  - [ ] Runbook GPO `se4_agent_bootstrap` (publication + liaison + filet).

## Dev Notes

### Modèle recommandé

**opus** — story multi-domaine et sensible : agent Go (sécurité fail-safe, garde relâchée), endpoints serveur (frontière/auth), unattend WinPE iso-legacy, scripts GPO. Plus large que les stories agent-pures de l'Epic 23/24 (qui justifiaient fable). La greffe sans régression sur 4 surfaces (Go + Laravel + XML + GPO) + l'analyse de sécurité de l'auto-enroll appellent opus, iso 25.2/25.3.

### Architecture & contraintes (résumé exécutable)

- **Réutilisation maximale, zéro réinvention** : `agent.exe install` (SCM idempotent), `ReleaseManifestService`/`ReleaseController::download` (confinement), `CaInitializer::getCaCertPem` (CA déjà servie en clair ailleurs), `handleGate2` serveur (porte 2 complète), `MacAddressNormalizer` (côté serveur). Le code neuf = **plomberie de jonction**, pas de moteur neuf.
- **Frontière `agent_*` + zéro AD** (NFR7, critère Keycloak vérifié en review par grep). La GPO touche l'AD **une fois** (bootstrap), jamais ensuite.
- **Pas d'état transitoire legacy/agent** : `se4_agent_bootstrap` est distinct de `se4_applications` (config legacy) ; ne pas mélanger. [Source: mémoire `project_no_legacy_transition_state`]
- **Auth machine iso-legacy** : les chemins d'amorçage n'introduisent **aucun** secret per-host (binaire/CA publics LAN, token né serveur-side via porte 2). [Source: mémoire `feedback_auth_iso_legacy`]

### Project Structure Notes

- **Racine = projet Laravel** (plus de `laravel/`). Routes : `routes/api.php`. Agent Go : `agent/shared/` (testable Linux) × `agent/windows/` (build tag). [Source: mémoires `project_root_is_laravel`, `project_agent_runtime_go`, `project_host_go_toolchain_path`]
- **Templates GPO** : server-side `/usr/share/sambaedu/gpo/` (exception client-facing à la convention storage). [Source: mémoire `project_storage_convention_non_versioned`]
- **Token agent** : `C:\ProgramData\SambaEdu\Agent\token` (contrat figé). Binaire : `C:\Program Files\SambaEdu\Agent\agent.exe` (emplacement SCM). [Source: agent/README.md:144]

### VM / exécution (mémoires projet)

- Aucune migration neuve attendue. Si une clé `config/agent.php` ou de route est ajoutée → `php artisan config:cache`/`route:cache` + chown www-admin sur la VM (inotify ne sync pas le cache). [Source: mémoires `project_vm_config_cache_not_synced`, `project_route_cache_vm_ephemeral_test_routes`]
- Tests serveur : `php artisan test --filter Agent` (+ `--filter ScriptsOsNamespace`) ; agent : `go test ./...` (CI projet sans `-race` — data race pré-existante hors scope). [Source: mémoires `project_host_go_toolchain_path`, story 25.2]
- **Smoke e2e (action manuelle Henri)** : la chaîne réelle (publication GPO Administrator, install WinPE, dépôt CA, auto-enroll, approbation un-clic) se valide sur VM/poste de lab — pas de SSH `/vm` depuis dev-cycle. Runbook agent.md Section 11.
- ⚠️ Une release `2.1.2` (cert TEST) est publiée stable sur la VM (laissée par 25.1/25.2) — utile aux smokes 25.4.

### References

- [Source: epics-agent-desired-state.md:580-598] — Story 25.4 AC (deux chemins, filet, CA des deux côtés, dispatcher générique).
- [Source: epics-agent-desired-state.md:50 (FR16), :65 (FR25), :85 (NFR6), :86-87 (NFR7-8)]
- [Source: architecture-agent-desired-state.md:300-302,335-346,626-635] — bootstrap GPO, distribution, refresh aligné GPO.
- [Source: spike-windows-anchor-2026-06-08.md:27-68,156-204] — GPO-dispatcher statique, matrice d'événements, tâche refresh, critères go/no-go.
- [Source: agent/windows/install_windows.go:14-75 ; agent/windows/main_windows.go:15-100] — `agent.exe install/uninstall/run`, garde token.
- [Source: agent/shared/loop.go:133-186,329 ; agent/shared/client.go:49,73-90,184 ; agent/shared/files.go:64-108] — run loop, client POST sans bearer, contrat token.
- [Source: app/Services/Agent/Enrollment/EnrollmentService.php:109-211 ; app/Http/Controllers/Api/V1/Agent/EnrollController.php:42-49] — porte 2 serveur (handleGate2, 403/200/409).
- [Source: app/Http/Controllers/Api/V1/Agent/ReleaseController.php:52,89-140 ; app/Services/Agent/Releases/ReleaseManifestService.php ; config/agent.php:60] — manifest + download stable + confinement.
- [Source: app/Auth/V1/Pki/CaInitializer.php:229-241 ; config/auth_v1.php:94-96 ; app/Auth/V1/Migration/Services/MigrationFragmentRenderer.php:342-374] — CA root, précédent de service en clair.
- [Source: resources/ipxe/windows/unattend.xml:107-140 ; app/Ipxe/Services/WindowsUnattendBuilder.php] — FirstLogonCommands, point d'insertion.
- [Source: routes/api.php:25,266-270] — noms bootstrap supprimés, fenêtre 1500 chars, enrollment local.request.
- [Source: 25-3 …approbation-un-clic.md] — porte 2 serveur+UI (le client agent est livré ici).
- Mémoires : `project_sysvol_wwwadmin_no_write_rights_and_silent_success`, `project_storage_convention_non_versioned`, `project_agent_token_file_path_contract`, `project_winpe_setup_never_returns`, `project_no_legacy_transition_state`, `feedback_auth_iso_legacy`, `project_root_is_laravel`, `api_routes_arch_test_window_trap`, `project_vm_config_cache_not_synced`.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date | Version | Description | Author |
|------|---------|-------------|--------|
| 2026-06-13 | 0.1 | Création story 25.4 (SM/orchestrateur, Henri). Forks tranchés : Fork 1 = agent Go auto-enroll porte 2 (garde token relaxée) ; Fork 2 = template GPO + runbook manuel Administrator. 6 AC, 6 tâches. Reco modèle : opus. | henri |
