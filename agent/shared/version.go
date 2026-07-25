// Package shared est le cœur OS-agnostique de l'agent SambaEdu desired-state
// (contrainte n° 5 du cahier des charges : cœur partageable cross-OS).
//
// Il contient : la canonicalisation + StateHasher (miroir bit-à-bit de
// app/Services/Agent/StateHasher.php, validé contre les golden files), le
// parsing du contrat v1, la construction du rapport, le client HTTP (rotation
// D5, fenêtre de grâce, quarantaine), le cache local atomique et la boucle de
// convergence. Rien ici ne dépend de Windows : tout est testable par
// `go test ./...` sur l'hôte Linux. Le spécifique Win32 (service SYSTEM, ACL
// icacls, UUID SMBIOS) vit dans agent/windows/.
//
// Le contrat wire est FIGÉ côté serveur : docs/agent/contract-v1.md + golden
// files tests/Fixtures/Agent/*.v1.json — ce package ne fait que le consommer.
package shared

// Version est la source unique de la version de l'agent (AC6 story 24.5) :
// déclarée dans chaque rapport (`agent_version`) et reprise par le nommage de
// l'artefact de build. La lignée PowerShell (spike 24.2-24.4) était 1.0.0 ;
// le binaire Go marque une rupture d'artefact → 2.x (les rapports Go sont
// discernables des rapports PS en lab). 2.1.0 = binaire COMPLET 24.6
// (compagnon + handlers + drops) — discernable en lab des rapports core-only
// 2.0.0 de 24.5. 2.1.1 = correctif terrain T12 (setAgentACL : flags (OI)(CI)
// réservés aux répertoires — posés sur un fichier, DACL effective vide et
// writeAtomic échouait en Accès refusé à la première exécution Windows).
// 2.1.2 = correctif terrain T12 n° 2 (compagnon : FreeConsole au démarrage —
// la tâche at-logon laissait une fenêtre console résidente dans la session,
// fermable par le user = compagnon tué).
// 2.2.0 = cadence pilotée serveur : le `ttl_seconds` de l'enveloppe /state
// (AGENT_STATE_TTL_SECONDS côté SE5) gouverne l'intervalle de poll, clampé
// [60 s, 24 h], amorcé depuis le cache au démarrage ; `interval_seconds`
// local devient le repli avant la première enveloppe vue.
// 2.2.3 = fond d'écran servi EN DIRECT par Apache (Alias /assets/wallpaper,
// calque Story 27.7) : SyncWallpaperAssets passe du Client token'd
// (/api/v1/agent/assets/wallpaper) à un GET statique sans token, hors PHP-FPM.
// Contrat wire INCHANGÉ (payload {asset, checksum}, golden figés) — seule la
// dérivation d'URL côté agent change ; la route Laravel token'd reste vivante
// le temps du rollout.
// 2.2.4 = correctif race overlay au logon (Story 27.1bis) : la tâche
// `session-fetch` compose désormais overlay.json elle-même après avoir peuplé
// le cache per-SID, dans le même process SYSTEM séquentiel. L'évènement
// WTS_SESSION_LOGON du service arrivait avant le fetch réseau → cache absent au
// moment de composer → no-op gracieux jamais rattrapé (logon-only) → overlay.json
// jamais écrit. L'écriture du service reste en place (idempotente, filet).
// 2.2.5 = correctif section du Rainmeter.ini durci (Story 27.1bis) : la section
// d'instance était `[SambaEduOverlay\SambaEduOverlay]` (forme à deux niveaux) →
// Rainmeter cherchait un dossier de config Skins\SambaEduOverlay\SambaEduOverlay\
// inexistant → AUCUNE skin activée → Rainmeter tournait mais écran vide. La
// section correcte est `[SambaEduOverlay]` (chemin du DOSSIER de config relatif à
// Skins\) ; `Active=1` y sélectionne le 1er .ini. overlay.json était bien écrit,
// seul le rendu manquait.
// 2.2.9 = Rainmeter MODE INSTALLÉ, settings per-user writable (Story 27.1ter).
// Le « verrouillage par Rainmeter.ini read-only » de 27.1bis cassait l'e2e sur un
// user standard : modales « Rainmeter.ini is not writable » + « Safe Start »
// (Rainmeter ne peut écrire ses settings/marqueur d'arrêt sous ProgramData RX).
// Désormais : (1) le SERVICE SYSTEM ne pose plus de Rainmeter.ini sous ProgramData
// et SUPPRIME tout résiduel (sa présence à côté de Rainmeter.exe forcerait le mode
// portable) ; (2) BuildHardenedRainmeterIni gagne SkinPath=C:\ProgramData\…\Skins\
// (skins restées verrouillées RX) ; (3) le COMPAGNON (droits user) écrit
// %APPDATA%\Rainmeter\Rainmeter.ini durci, WRITABLE (atomique, idempotent, sans
// ACL), au démarrage AVANT le lancement du watchdog. overlay.json reste écrit par
// SYSTEM (NFR5 intact) ; contrat/golden inchangés.
// 2.2.19 = staging AGENT-DRIVEN des outils WPKG partagés (Story 27.20, pivot
// architectural). Le handler `applications` (windows) fetch désormais
// `<server_url>/wpkg/tools/manifest.json` AVANT de déclencher `wpkg-client.vbs`,
// puis appelle le NOUVEAU module générique `agent/provision` (Reconcile par hash)
// pour déposer les outils (7za.exe, nircmd.exe, tooltip/*) sous
// `%WinDir%\install\wpkg\tools\` (= `%Z%\wpkg\tools\`). Idempotence VRAIE par
// sha256 (skip si déjà à jour), download atomique, fail-soft (un outil manquant
// ne bloque pas le run). Remplace la tentative inerte de la 1re 27.20 (logique
// outils dans `resources/wpkg/wpkg.cmd`, jamais exécuté sur le chemin agent —
// reverti). Module `provision` OS-agnostique : adaptateur Windows seul réalisé,
// TargetResolver = interface prête pour un futur resolver Linux. Contrat
// wire/golden INCHANGÉS (aucune surface /state ou rapport touchée).
// 2.2.10 = préchargement identité MACHINE de l'overlay (Story 27.10). La SALLE
// (`machine.room`) passe de la portée session (item identity) à la portée
// MACHINE (cache persistant) : ComposeOverlayDocument extrait `room` de l'item
// `kind:"machine"`, et OverlayDocumentForSession lit le cache MACHINE + le cache
// session per-SID. Au logon, poste + salle s'affichent dès le cache machine, sans
// attendre le fetch per-user (login/fullname arrivent ensuite avec le cache
// session). Byte-format overlay.json INCHANGÉ ; contrat bumpé (golden + 2 hashes
// figés croisés PHP↔Go).
// 2.3.0 = verbe `ensure` sur les items `registry` (Story 35.1, contrat §7.1) :
// champ optionnel `ensure ∈ present|absent` (absence = present, contrat ADDITIF
// D1 — les items d'écriture 5 clés restent byte-identiques). Un item 4 clés
// `{hive, path, name, ensure:"absent"}` fait SUPPRIMER la valeur nommée
// (RegistryOps.Delete → DeleteValue ; ErrNotExist = succès idempotent — JAMAIS
// la clé-conteneur), portées Machine (SYSTEM/HKLM) ET Session (compagnon/HKCU,
// avec rafraîchissement shell sur suppression effective). Policy STRICT
// inchangée (drift + re-suppression si la valeur réapparaît). Golden
// state.v1.json bumpé (+1 item absent machine, hashes figés jumeaux PHP↔Go
// recalculés). Un binaire ANTÉRIEUR parse un item `absent` en {status: error}
// isolé sur le type registry → publier la release (update.sh ne publie jamais
// seul).
// 2.4.0 = type `registry_list` (Story 35.2, contrat §7.6) : listes registre à
// sous-valeurs indexées `\1..\N` (ExtensionInstallForcelist, DisallowRun) —
// NOUVEAU handler `RegistryListHandler` (portées Machine/SYSTEM et Session/
// compagnon), réconciliation de CLÉ-CONTENEUR (D3) : écrit les valeurs `1..N`
// dans l'ordre (Kind = entry_type ∈ REG_SZ|REG_EXPAND_SZ), supprime toute
// autre valeur AU NOM NUMÉRIQUE (canon strconv strict, "01" ≠ "1") — jamais
// les valeurs non numériques, jamais la clé-conteneur ; liste vide = purge.
// NOUVEL op additif `RegistryOps.ValueNames(hive, path)` (clé absente ⇒
// nil,nil). AUSSI : `parseRegistrySpec` accepte `name: ""` (valeur PAR DÉFAUT
// d'une clé, `(Default)` — besoin 35.5) : la clé `name` doit être PRÉSENTE
// (absence = invalide), vide = default value (Get/Set/DeleteValue("") la
// ciblent nativement). Golden state.v1.json bumpé (+1 item registry_list
// machine, hashes figés jumeaux PHP↔Go recalculés). ⚠️ Un binaire ≤ 2.3.0
// IGNORE le type registry_list EN SILENCE (contrat §8 — aucun statut, aucune
// erreur : « réglage sans effet ») → PUBLIER la release 2.4.0 (update.sh ne
// publie jamais seul).
//
// 2.4.1 : détection d'extinction — le service signale le shutdown MACHINE au
// serveur (`POST /v1/agent/shutdown`, best-effort 3 s, svc.Shutdown seulement,
// jamais le stop manuel du service) → présence « éteint » immédiate dans l'UI
// au lieu du seuil de silence 2 × ttl (NotifyShutdown, shared/shutdown.go).
//
// 2.5.0 = ruche `HKU` sur les items `registry` (Story 35.3, contrat §7.1) :
// troisième VALEUR admise du champ `hive` (pas un champ ni un type nouveau —
// golden/hashes figés INCHANGÉS). Un item `hive:"HKU"` (portée MACHINE,
// service SYSTEM) est FAN-OUT en interne par le handler vers `HKU\.DEFAULT`
// (écran de logon — numlock au logon) ET chaque ruche utilisateur CHARGÉE
// (`HKU\<SID>`, `S-1-5-21-*` hors `_Classes`), énumérées à CHAQUE cycle via le
// NOUVEL op REQUIS `RegistryOps.UserHives` (session ouverte après coup =
// couverte au cycle suivant). Drift AGRÉGÉ (une ruche divergente ⇒ drift du
// type), idempotence PAR CIBLE, `ensure:"absent"` supprime dans TOUTES les
// ruches, erreur par ruche ISOLÉE (effort maximal) / erreur d'énumération
// franche. Aucun rafraîchissement shell pour HKU (session 0). Débouché :
// `HKCU\Software\Policies\*` diffusable en machine/parc via HKU. ⚠️ Un binaire
// ≤ 2.4.1 PARSE un item HKU puis `rootKey()` le refuse à l'op → `{status:
// error}` pour le type `registry` machine ENTIER, SANS Apply : toutes les clés
// HKLM cessent de converger → PUBLIER la release 2.5.0 AVANT de jouer la
// migration numlock HKU (update.sh ne publie jamais seul).
//
// 2.6.0 = mécanisme HORS-REGISTRE `fs_acl` (Story 36.1, contrat §7.7) : NOUVEAU
// type + NOUVEAU handler `FsAclHandler` (portée MACHINE / service SYSTEM seul)
// qui gère des ACE NTFS explicites par CHIRURGIE DACL — merge
// SetEntriesInAcl + SetNamedSecurityInfo DACL-only (SANS PROTECTED_*), la DACL
// n'est JAMAIS réécrite, owner/SACL/ACE héritées/ACE tierces JAMAIS touchés
// (D4). Payload 6 clés `{path, trustee, ace_type, rights, applies_to, ensure}`,
// enums fermés de mots métier (masques/flags SPÉCIFIQUES traduits côté handler,
// jamais GENERIC_*). STORE « dernier appliqué » par item (fsacl-state.json,
// WriteFileAtomic) = SEULE mémoire des ACE posées → réconciliation d'orphelins
// (aucune ACE orpheline au changement de valeur). Résolution SID par LSA sur le
// poste joint (LookupAccountName, D5 — zéro SID en SQL). REFUS agent défense en
// profondeur : deny sur SID well-known système (Everyone/Authenticated Users/
// SYSTEM/BUILTIN/comptes de service) ⇒ erreur d'item ; chemin inexistant ⇒
// erreur (jamais de mkdir) ; trustee irrésoluble ⇒ erreur. Policy STRICT (ACE
// gérée supprimée à la main ⇒ drift + re-pose). NOUVEAU chemin de store
// `Store.FsAclStatePath()`. ⚠️ Un binaire ≤ 2.5.0 IGNORE le type fs_acl EN
// SILENCE (contrat §8 — aucun statut, aucune erreur : « réglage sans effet »)
// → PUBLIER la release 2.6.0 (update.sh ne publie jamais seul). Golden
// state.v1.json bumpé (+1 item fs_acl machine, hashes figés jumeaux PHP↔Go
// recalculés). PLOMBERIE 35.6 : la résolution SID (LSA) et les jetons
// d'audience sont livrés ici — le gate 35.6 (privilege) reste FERMÉ, RIEN
// n'est ouvert.
//
// 2.7.0 = mécanisme HORS-REGISTRE `firewall` (Story 36.2, contrat §7.8) :
// NOUVEAU type + NOUVEAU handler `FirewallHandler` (portée MACHINE / service
// SYSTEM seul) qui gère des règles pare-feu Windows POSSÉDÉES PAR GROUPE
// (`SambaEdu-Agent`). Contrairement à fs_acl (store « dernier appliqué »), le
// champ `Grouping` de la règle EST le marqueur de propriété (D4) : le handler
// réconcilie le GROUPE (iso registry_list — désirées présentes+conformes, toute
// règle du groupe hors désir SUPPRIMÉE, groupe vide = « off » symétrique),
// JAMAIS les règles hors groupe, la politique par défaut ou le service MpsSvc
// (FirewallOps 3 ops seulement, interdit structurel). Payload
// `{rule_id, direction, action, remote_scope, protocol, ensure}`
// (+ remote_addresses ssi explicit + ports ssi tcp|udp), enums fermés de mots
// métier (AUCUNE syntaxe netsh/SDDL). Traduction `remote_scope: internet` FIGÉE
// dans le code (plages inverses-RFC1918 IPv4 `a-b` + IPv6 `2000::/3`), comparaison
// par NORMALISATION CANONIQUE d'intervalles (anti drift-loop d'écho Windows,
// piège #4). REFUS agent défense en profondeur Q3 (dans Test ET Apply) : un
// `block explicit` chevauchant une plage protégée (RFC1918/loopback/link-local/
// ULA ou /0, INTERSECTION mathématique) ⇒ erreur d'item — MIROIR des plages du
// guard PHP. Impl Windows en COM natif vtable INetFwPolicy2 (ZÉRO dépendance —
// netsh ne sait pas poser Grouping ; agent/windows/handler_firewall_windows.go).
// ⚠️ Un binaire ≤ 2.6.0 IGNORE le type firewall EN SILENCE (contrat §8 — aucun
// statut, aucune erreur : « salle coupée sans effet »). Golden state.v1.json
// bumpé (+1 item firewall machine, hashes figés jumeaux PHP↔Go recalculés).
// ⚠️ PUBLICATION : la 2.6.0 (fs_acl) N'A PAS ENCORE ÉTÉ PUBLIÉE — la publication
// MANUELLE de la release 2.7.0 (update.sh ne publie jamais seul) livre les DEUX
// mécanismes fs_acl ET firewall d'un coup.
//
// 2.8.0 = mécanisme HORS-REGISTRE `privilege` (Story 35.6, contrat §7.9) :
// NOUVEAU type + NOUVEAU handler `PrivilegeHandler` (portée MACHINE / service
// SYSTEM seul) qui gère des droits de logon LSA `SeDeny*` par RÉCONCILIATION
// DE CONTENEUR SANS STORE (iso firewall, PAS fs_acl) : le privilège EST le
// conteneur, ses titulaires sont ÉNUMÉRABLES (LsaEnumerateAccountsWithUserRight)
// — le handler possède la liste ENTIÈRE (accorde les SID désirés manquants via
// LsaAddAccountRights, révoque tout titulaire hors état désiré via
// LsaRemoveAccountRights ; `accounts: []` VIDE le privilège = off réel). Payload
// 2 clés `{privilege, accounts}` — noms Windows seulement (résolution SID via
// windows.LookupSID, RÉUTILISE le pattern fsAclOps.LookupSid de 36.1, D5 ; mémo
// PAR PASSE). REFUS agent SeDeny*-only en DOUBLE RIDEAU (miroir de
// PrivilegeAuthoringGuard::ALLOWED_PRIVILEGES) : un droit *grant* possédé en
// liste entière révoquerait le logon de tout le monde → machine VERROUILLÉE —
// erreur d'item, jamais appliqué. Compte irrésoluble ⇒ erreur d'item SANS
// application partielle (jamais de deny à trous silencieux). EFFET AU LOGON
// SUIVANT (sémantique Windows : les droits de logon sont évalués à l'ouverture
// de session — aucune session tuée ; le retrait rétablit au logon suivant).
// Débouché : RDP refusé aux élèves / autorisé aux profs sur le MÊME poste
// (capacité `rdp_denied_for_group`, SeDenyRemoteInteractiveLogonRight).
// ⚠️ Un binaire ≤ 2.7.0 IGNORE le type privilege EN SILENCE (contrat §8 —
// aucun statut, aucune erreur : « RDP toujours ouvert, zéro erreur »). Golden
// state.v1.json bumpé (+1 item privilege machine, hashes figés jumeaux PHP↔Go
// recalculés). ⚠️ PUBLICATION : les 2.6.0 (fs_acl) ET 2.7.0 (firewall) N'ONT
// PAS ENCORE ÉTÉ PUBLIÉES — la publication MANUELLE de la release 2.8.0
// (update.sh ne publie jamais seul) livre les TROIS mécanismes d'un coup.
//
// 2.9.0 = nettoyage des crochets legacy SE4 (Story 38.3, contrat §7.10) :
// NOUVEAU type `legacy_cleanup` + NOUVEAU handler `LegacyCleanupHandler`
// (portée MACHINE / service SYSTEM seul) qui retire du poste les artefacts
// legacy LOCAUX par SCAN idempotent SANS store (iso firewall/privilege — les
// artefacts sont énumérables à chaque passe). Catalogue versionné DANS l'agent
// (D3) : blobs `applications-*` (%windir%, %windir%\Temp, %TEMP% per-user),
// marqueurs `.md5` (garde 32-hex), tâches planifiées `wpkg4`/`*-system`
// (garde : l'action référence gpo/applications.php|wpkg — sinon conservée +
// rapportée), scripts GPO LOCALE curl-ant `gpo/*.php` + purge `scripts.ini`
// (JAMAIS GroupPolicy\DataStore), `wpkg-client.vbs`/`wpkg-gpo.txt`, jonctions
// `install`/`rapports` (reparse-only — un vrai dossier = provisioning natif
// 27.20, INTOUCHABLE), `action.cmd`/`autorun.cmd`/`gpo.txt`/`C:\Netinst`/
// `%WINDIR%\Web\SE4`, valeur Run `action`, autologon résiduel `se4install`
// (garde DefaultUserName), helpers `%ProgramFiles%\SambaEdu` en LISTE BLANCHE
// nommée (JAMAIS Agent\**), paires Mozilla `profiles.ini`/`installs.ini`
// référençant `sambaedu.default` (Firefox ET Thunderbird, chaque C:\Users\* —
// Q5-a VANILLA : la PAIRE seulement, JAMAIS le dossier de profil, JAMAIS un
// profiles.ini sain, AUCUN profil forcé posé). Payload `{mozilla: "vanilla"}`
// (enum fermé). Reporting : drift + Detail listant les artefacts supprimés
// (nouvelle interface OPTIONNELLE DetailReporter du moteur, additive) ; poste
// sain = compliant sans Detail (zéro écriture, dédup serveur). Gating serveur :
// capacité `legacy_hooks_cleanup` (unmanaged/on, défaut Broadcast unmanaged).
// ⚠️ Un binaire ≤ 2.8.0 IGNORE le type legacy_cleanup EN SILENCE (contrat §8 —
// aucun statut, aucune erreur : « poste jamais nettoyé, zéro erreur »). Golden
// state.v1.json bumpé (+1 item legacy_cleanup machine, hashes figés jumeaux
// PHP↔Go recalculés). ⚠️ PUBLICATION : les 2.6.0 (fs_acl), 2.7.0 (firewall) ET
// 2.8.0 (privilege) N'ONT PAS ENCORE ÉTÉ PUBLIÉES — la publication MANUELLE de
// la release 2.9.0 (update.sh ne publie jamais seul) livre les QUATRE
// mécanismes d'un coup.
//
// 2.10.0 = échelle de rafraîchissement du compagnon (Story 43.1, Epic 43 —
// application immédiate) : le champ OPTIONNEL `refresh` du PAYLOAD des items
// `registry`/`registry_list` (shell_notify < policy_broadcast <
// explorer_restart) déclare le geste Windows minimal rendant un réglage HKCU
// effectif EN SESSION COURANTE (fin du « double logon » — Explorer lit ses
// policies au démarrage). Les handlers ACCUMULENT le besoin pendant Apply
// (max(plancher shell_notify, hint) par item HKCU EFFECTIVEMENT changé —
// nouvelle interface optionnelle RefreshRequester) ; le COMPAGNON exécute UN
// SEUL geste en toute fin de RunPass (le plus fort ; passe stable = zéro
// geste ; best-effort : échec = warning, jamais une erreur de passe). Trois
// gestes FFI NewLazySystemDLL, session user seulement
// (agent/windows/refresh_windows.go) : SHChangeNotify (MIGRÉ de l'ancien
// registryNotifier inline — une seule voie d'émission, non-régression lot
// vues Explorer), SendMessageTimeout(HWND_BROADCAST, WM_SETTINGCHANGE,
// "Policy", SMTO_ABORTIFHUNG 5 s), kill+relaunch d'explorer.exe de LA session
// (Toolhelp32, garde anti-double-lancement — Windows relance parfois le shell
// seul). JAMAIS de geste côté service SYSTEM/MachineEngine ni sur fan-out HKU
// (session 0). Parsing INDULGENT : `refresh` absent/vide/inconnu =
// comportement plancher actuel (additif sûr NFR-A4) — la validation stricte
// du vocabulaire est serveur (AuthoringGuard 43.2). Contrat wire/golden
// INCHANGÉS (le hint vit dans le payload provider-defined §3.2 ; AUCUN
// provider ne l'émet encore — c'est la 43.2). ⚠️ Un binaire ≤ 2.9.0 IGNORE le
// hint EN SILENCE (parseurs indulgents — clés écrites mais AUCUN geste :
// l'« effet immédiat » promis par l'UI serait un mensonge sur les postes non
// à jour) → PUBLIER la release 2.10.0 (manuelle — update.sh ne publie jamais
// seul) AVANT de jouer tout seeder/retrofit 43.2. ⚠️ ÉTAT DES PUBLICATIONS :
// à la création de la 38.3, les 2.6.0 (fs_acl), 2.7.0 (firewall) et 2.8.0
// (privilege) n'avaient JAMAIS été publiées — vérifier au moment de publier
// si la 2.9.0 l'a été depuis (sinon la 2.10.0 livre les cinq lots d'un coup).
//
// 2.11.0 = fenêtre d'avertissement avant explorer_restart (Story 43.4, Epic
// 43) : quand — et SEULEMENT quand — le geste résolu de fin de passe est un
// explorer_restart RÉELLEMENT exécuté (jamais shell_notify/policy_broadcast,
// jamais passe stable, jamais le restart throttlé→dégradé), le compagnon
// affiche SA propre petite fenêtre top-most « Application des réglages en
// cours — l'écran va se rafraîchir… » AVANT de tuer le shell (délai de
// lecture ~2 s, const restartNoticeLeadTime), la maintient PENDANT le
// redémarrage (elle vit dans le PROCESS du compagnon, non parentée au shell —
// elle SURVIT au kill d'explorer.exe) et la ferme APRÈS le retour du shell
// (dismiss après RestartExplorer, qui sonde déjà ce retour). NOUVELLE méthode
// RefreshOps.ShowRestartNotice(text) (dismiss func()) ; impl Windows
// agent/windows/notice_windows.go — PREMIÈRE fenêtre native de l'agent, FFI
// user32/gdi32 pur sans cgo (RegisterClassExW sous sync.Once, WNDPROC paquet
// via NewCallback, CreateWindowExW WS_POPUP + WS_EX_TOPMOST|WS_EX_TOOLWINDOW,
// STATIC centré, message pump sur goroutine LockOSThread). Best-effort
// ABSOLU : échec/lenteur de création (borne 1 s) = warning + dismiss no-op,
// le restart part QUAND MÊME ; dismiss idempotent (sync.Once) et borné (2 s).
// Session user seulement — le MachineEngine SYSTEM n'a aucune RefreshOps,
// aucune fenêtre en session 0. Contrat wire/golden INCHANGÉS (réaction 100 %
// LOCALE du compagnon au geste déjà résolu : aucun hint nouveau, aucun champ
// de payload, aucune projection — D7). ⚠️ Comportement VISIBLE : un binaire
// ≤ 2.10.0 redémarre Explorer SANS avertissement (aucune casse, juste le trou
// d'UX) → PUBLIER la release 2.11.0 (manuelle — update.sh ne publie jamais
// seul) pour que l'avertissement prenne effet sur le parc. ⚠️ ÉTAT DES
// PUBLICATIONS : vérifier au moment de publier si la 2.10.0 (échelle de
// rafraîchissement, gate des seeders 43.2) l'a été depuis la 43.1 — sinon la
// 2.11.0 livre les lots en attente d'un coup.
//
// 2.12.0 = application SYSTEM des capacités `HKCU\…\Policies\*` par session
// (Story 35.7, contrat §7.1/§7.6) : champ additif OPTIONNEL `writer` sur les
// payloads `registry`/`registry_list` (enum fermé, seule valeur publiée
// "system" — portées session/machine_user, ruche HKCU, mutuellement exclusif
// avec `refresh`). Cause racine : sur poste JOINT AU DOMAINE, TOUT
// `HKCU\…\Policies\*` — y compris `CurrentVersion\Policies` — est en LECTURE
// SEULE pour l'utilisateur standard (durcissement anti-GPO) : le COMPAGNON
// échouait en « Accès refusé » sur `blocked_executables` (flag DisallowRun +
// conteneur `DisallowRun\1..5`). Désormais : (1) le COMPAGNON ÉCARTE tout item
// porteur du champ `writer` AVANT son moteur (partition SplitSystemWriterItems
// — skip générique sur PRÉSENCE du champ, valeur future inconnue skippée aussi,
// engine.go/parseurs byte-identiques) ; (2) le SERVICE SYSTEM gagne une passe
// PAR-SESSION (sessionapply.go, cycle ET tâche at-logon session-fetch — un
// seul code) : pour chaque session interactive de la dernière énumération WTS,
// les items `writer == "system"` du cache per-SID sont appliqués dans
// `HKU\<SID de LA session ciblée>` via un DÉCORATEUR d'ops (sessionHiveOps :
// HKCU → HKU\<SID> — UN SID, JAMAIS le fan-out .DEFAULT/multi-ruches de 35.3 ;
// les overrides UserGroup/User atteignent l'item, ciblage par-utilisateur
// conservé). Handlers registry/registry_list réutilisés TELS QUELS
// (réconciliation de conteneur incluse) ; sonde race-logoff de Write héritée
// gratuitement (session déloguée = no-op, jamais d'orpheline) ; applied-state
// PAR SID (cache\sessions\<SID>\applied-state.json) ; verdicts fusionnés par
// type au rapport du cycle (la tâche at-logon converge sans rapporter).
// NOUVEAU champ Agent.SessionSystemOps (nil = passe inerte), câblé par
// main_windows.go. EFFET AU LOGON SUIVANT de la session ciblée (Explorer lit
// ces policies au logon — comportement GPO user policy ; le retrofit serveur
// RETIRE le hint `refresh` des 3 projections re-routées, exclusion mutuelle).
// ⚠️ Un binaire ≤ 2.11.x IGNORE le marqueur EN SILENCE (champ inconnu, §9) :
// AUCUNE casse ne flotte (contrairement au piège HKU/35.3) mais AUCUN
// correctif — le compagnon garde son « Accès refusé », le service n'applique
// rien → PUBLIER la release 2.12.0 (manuelle — update.sh ne publie jamais
// seul) AVANT de jouer la migration retrofit
// `2026_07_13_100000_retrofit_session_system_writer_policies.php` sur /vm
// (la version rapportée au check-in fait foi). ⚠️ ÉTAT DES PUBLICATIONS :
// vérifier au moment de publier si la 2.11.0 (fenêtre explorer_restart,
// epic 43) l'a été — sinon la 2.12.0 livre les lots en attente d'un coup.
//
// 2.12.1 — CORRECTIF : compagnon de session muet sur poste fraîchement
// installé par le bootstrap GPO. `startup.cmd` dépose agent.exe par
// `move /Y "%TEMP%\agent.tmp"` ; en SYSTEM %TEMP% = C:\Windows\TEMP, et un
// move intra-volume est un RENAME — NTFS conserve la DACL au lieu de la
// ré-hériter de Program Files. agent.exe se retrouve en SYSTEM+Admins seuls
// (ACE (I) mais ORPHELINES) : la tâche compagnon, en RunLevel Limited, échoue
// en 0x80070005 ACCESS_DENIED à chaque logon. Le service SYSTEM, lui, tourne
// normalement → AUCUN signal côté SE5 (l'overlay ne produit plus d'item de
// rapport depuis 27.1bis), diagnostic uniquement par
// `Get-ScheduledTaskInfo`/`icacls` sur le poste. `installService` répare
// désormais la DACL du binaire ({@see resetBinaryACL}, icacls /reset) avant
// d'enregistrer service et tâches — idempotent, non bloquant, et rejoué à
// chaque boot par le bootstrap (qui appelle `agent.exe install`), donc les
// postes déjà déployés se réparent seuls. Le script GPO figé n'est PAS touché
// (dernier artefact AD, jamais ré-édité). Pas de changement de contrat : un
// binaire antérieur reste fonctionnel côté SYSTEM, seul le compagnon manque.
//
// 2.12.2 — VISIBILITÉ + LIVRABILITÉ, deux angles morts révélés par le
// diagnostic de la 2.12.1.
//
// (a) `companion` : nouveau canal de signalement (REPORT-ONLY, aucun provider
// serveur). Le service SYSTEM juge la santé du compagnon sur les drops per-SID
// qu'il collecte déjà — session interactive vivante + drop absent ou plus vieux
// que 2 × la cadence effective ⇒ `error` nommant la session. C'est ce qui
// manquait le 2026-07-20 : le compagnon mourait à chaque logon et le poste
// paraissait parfaitement sain côté SE5. `compliant` est rapporté
// EXPLICITEMENT au retour à la normale (le type n'ayant pas de provider, le
// serveur ne le prune jamais : omettre l'item figerait l'erreur). Type
// volontairement HORS ResourceTypes côté Go — le répertoire de drop est
// inscriptible par le user, un type collectable serait un type forgeable.
//
// (b) `agent_update` réparé : il émettait `hash: ""` avec un type absent de la
// liste serveur ⇒ 422 sur le rapport ENTIER du cycle. Le signal d'échec
// détruisait son porteur, et ce chemin n'avait jamais fonctionné bout en bout
// depuis 25.2. Côté serveur, `StateContract::REPORT_ONLY_TYPES` accueille les
// deux types ; `RESOURCE_TYPES` (ce qui est SERVI) reste inchangé — golden
// files intacts.
//
// (c) Catalogue d'outils enfin capable de LIVRER une mise à jour :
// `provisionRainmeterPortable` compare le SHA-256 du marqueur à celui du
// manifest au lieu de tester la seule présence du fichier. La donnée était déjà
// écrite depuis 25.6, jamais relue — réuploader un portable neuf n'atteignait
// aucun poste déjà provisionné, EN SILENCE. Le marqueur est retiré juste avant
// la bascule : `RainmeterOps.Installed()` le relit, donc le watchdog du
// compagnon cesse de relancer l'ancienne image pendant le remplacement. On ne
// tue PAS l'instance vivante (le provisioning est SYSTEM, le lancement est
// compagnon) : exe verrouillé ⇒ bascule en échec ⇒ retry au cycle suivant,
// l'instance meurt au logoff. D4 intact : sans outil actif au manifest, on ne
// désinstalle jamais.
//
// ⚠️ Un binaire ≤ 2.12.1 n'émet aucun item `companion` : l'absence de la ligne
// vaut « agent trop ancien », PAS « compagnon sain ».
//
// 2.12.3 — console de debug du compagnon : décision RÉVERSIBLE.
//
// Le compagnon lisait le drapeau `debug` UNE SEULE FOIS, à t≈0, dans son cache
// per-SID, puis appelait FreeConsole. Or aucun AllocConsole n'existait nulle
// part : la décision était définitive pour la session. Sur un poste fraîchement
// réinstallé, cache\sessions\<SID>\ n'existe pas encore à cet instant —
// session-fetch (SYSTEM, qui l'écrit) et SessionCompanion partagent le trigger
// At log on, ils sont en COURSE — donc lecture ratée ⇒ `false` (best-effort :
// toute erreur → false) ⇒ console perdue alors que le poste était bien en debug.
// Le reste convergeait normalement, RunPass tolérant 60 s d'attente du cache
// (WaitForCache) : SEULE la console manquait, sans la moindre erreur nulle part.
// La décision console avait zéro tolérance temporelle là où la convergence en
// avait soixante secondes. Constaté 2026-07-20 sur poste réinstallé, agent
// 2.12.2, debug bien actif côté serveur.
//
// Correctif : `Companion.OnDebugChange` — le drapeau est relu à CHAQUE passe et
// notifié aux seuls CHANGEMENTS (level-triggered, aucun geste sur passe stable).
// Côté Windows, `attachConsole` réalloue une console (AllocConsole + réouverture
// de CONOUT$ et re-pointage de os.Stdout/os.Stderr — après FreeConsole les
// handles hérités sont morts, sans quoi la console s'ouvrirait vide). La lecture
// initiale reste le chemin nominal (cache déjà présent = console conservée, zéro
// clignotement) ; le hook n'est qu'un rattrapage. Effet de bord bienvenu : le
// toggle debug prend désormais effet EN COURS de session, sans rouvrir la
// session — la « latence assumée au logon suivant » documentée en 24.6 disparaît.
// Un échec de rattachement est LOGGÉ en warning (c'est le silence qui avait rendu
// ce bug indétectable), jamais fatal.
//
// (b) Rattrapage de convergence — un écart réparé n'attend plus une heure pour
// être signalé conforme. Sous politique STRICT (27.8), le premier passage sur un
// poste réinstallé est non conforme PAR CONSTRUCTION (rien n'est encore
// appliqué) : `Test()` négatif ⇒ `drift`, `Apply` répare dans la seconde, et il
// n'existe aucun statut « corrigé ». Le poste était donc conforme quelques
// secondes plus tard, mais le serveur ne l'apprenait qu'au cycle nominal
// suivant. Constaté 2026-07-20 : drives/wallpaper/registry affichés en écart de
// 11:37 à 12:37 — soit une heure d'alarme pour une divergence qui avait duré le
// temps d'un Apply, et aucun moyen pour l'exploitant de la distinguer d'un écart
// installé. Un rapport portant un écart programme désormais un cycle de
// rattrapage à 360 s (ConvergenceFollowUpSeconds) au lieu du nominal. 360 et non
// 60 : les items de portée session viennent des drops du compagnon, qui re-teste
// toutes les 5 min — un rattrapage plus court relirait le MÊME drop et
// conclurait à tort « toujours en écart ». UN SEUL rattrapage par épisode
// (budget rechargé au premier rapport intégralement conforme) : un poste
// durablement en écart ne doit pas passer en interrogation rapide, et c'est le
// RÉSULTAT du rattrapage qui porte la distinction — encore en écart après lui ⇒
// l'écart est installé. Jamais de rallongement : un `ttl_seconds` serveur plus
// court que 360 s l'emporte. Côté serveur, la fiche poste gagne une colonne
// « Depuis » (ConformityService::statusHeldSinceFor) : l'ancienneté de la
// dernière TRANSITION vers le statut courant — quelques minutes = convergence en
// cours, plusieurs jours = bloqué.
//
// 2.12.4 — la console de debug ne doit pas pouvoir tuer ni figer le compagnon
// qu'elle sert à diagnostiquer. Régression DIRECTE de la 2.12.3, corrigée à
// chaud le jour même.
//
// La 2.12.3 a rendu le mode debug enfin fiable — et a du même coup rendu
// ATTEIGNABLES les deux dangers que le commentaire de detachConsole documentait
// depuis 24.6 (constat lab ws 49). Avant elle, la course au logon empêchait le
// drapeau `debug` de s'armer : la console n'apparaissait jamais, le risque était
// théorique. Il ne l'est plus.
//
// Constaté 2026-07-20, quelques heures après la publication de la 2.12.3 : le
// canal `companion` passe en `error` (« sans signe de vie depuis plus de 2h »)
// alors que le processus tourne et que l'overlay s'affiche normalement. Une
// console Windows fraîchement allouée a ENABLE_QUICK_EDIT_MODE actif par défaut
// ; un clic dedans — réflexe devant une fenêtre qui surgit — met la console en
// sélection et BLOQUE toute écriture stdout. `Logger.log` détient son mutex
// pendant l'écriture : le compagnon se fige au premier log, plus aucune passe,
// plus aucun drop. Rien ne se voit à l'écran, Rainmeter étant un processus
// SÉPARÉ qui continue de rendre l'overlay. Le gel s'est levé seul deux heures
// plus tard, à la première frappe dans la fenêtre.
//
// Correctif (`hardenConsole`, appliqué à la console allouée ET à celle héritée
// de la tâche planifiée) : ENABLE_QUICK_EDIT_MODE retiré — avec
// ENABLE_EXTENDED_FLAGS, obligatoire pour que le retrait soit pris en compte —
// et SC_CLOSE supprimé du menu système, ce qui grise le bouton fermer et
// neutralise Alt+F4 (fermer la console d'un processus console le TUE ; la
// tâche n'ayant qu'un trigger At log on, la session ne reconvergerait plus
// jusqu'au logon suivant).
//
// Leçon retenue au passage : l'overlay affiché ne prouve RIEN sur la vitalité du
// compagnon. Le watchdog Rainmeter vit DANS le compagnon, mais Rainmeter est un
// process distinct qui lui survit — un compagnon mort ou figé laisse un overlay
// parfaitement rendu. Le canal `companion` (2.12.2) est le seul signal valide,
// et c'est lui qui a levé le lièvre ici : vrai positif.
//
// ⚠️ Angle mort connu, NON traité : après une auto-update mid-session, le swap
// renomme l'image verrouillée (swap.go) et le compagnon SURVIT — mais sur
// l'ANCIENNE version, face à un service déjà en N+1, jusqu'au logon suivant.
// DetectCompanionHealth ne voit pas cet écart (le compagnon vN dépose
// normalement). Toute rupture de contrat entre vN et vN+1 (format du cache
// per-SID, du drop, nouveaux types d'items) se manifeste dans cette fenêtre.
//
// 2.13.0 = Story 36.5 : nouveau type `app_profile` (§7.11, mécanisme
// HORS-REGISTRE — redirection du profil applicatif Firefox/Thunderbird vers le
// home réseau, portée SESSION). Ajout ADDITIF de type (contrat §9) : un binaire
// ≤ 2.12.4 IGNORE le type EN SILENCE (§8 — aucun statut, aucune erreur ; symptôme
// « profil non redirigé »). La release 2.13.0 DOIT être publiée manuellement pour
// armer le mécanisme.
//
// AMENDEMENT FINAL 36.5 (split SYSTEM-lien / COMPAGNON-reste, Henri 2026-07-21,
// toujours en 2.13.0 — non publiée). Le lien de dossier vers UNC (mklink /D
// iso-SE4) exige `SeCreateSymbolicLinkPrivilege`, qu'AUCUN canal SE5 ne peut
// accorder au compagnon (mécanisme `privilege` 35.6 SeDeny*-only) mais que
// LocalSystem possède nativement. Sur le modèle EXACT de l'overlay (27.1bis), le
// SERVICE SYSTEM pose donc / répare le LIEN au WTS_SESSION_LOGON
// (app_profile_logon.go pur + app_profile_logon_windows.go glue : token WTS →
// profil, source = cache per-SID INFALSIFIABLE écrit par SYSTEM au fetch,
// validation de borne lien⊂profil + cible UNC, mise de côté C1 déplacée ici). Le
// COMPAGNON garde tout le reste (dossier serveur, marqueur, user.js, ini) et NE
// POSE PLUS le lien : il le CONSTATE et n'écrit la paire d'ini QUE si le lien est
// déjà présent (sinon Firefox lancé entre-temps créerait un vrai dossier — C1).
// Lien manquant ⇒ item non-compliant avec detail « en attente de SYSTEM » ; au
// prochain logon, Test ⇒ compliant (level-triggered). Contrat wire/golden
// INCHANGÉS (aucun champ de payload nouveau — seul CHANGE l'acteur qui pose le
// lien).
//
// 2.14.0 = Story 27.21, 1re passe — ⛔ JAMAIS PUBLIÉE, COMPORTEMENT RÉPUDIÉ.
// NE JAMAIS CONSTRUIRE NI PUBLIER un binaire portant ce numéro.
//
// Elle faisait balayer au handler `shortcuts` les DEUX Bureaux candidats de
// façon INCONDITIONNELLE, le Bureau RÉSEAU étant dérivé localement d'une
// constante d'agent (`NetworkDesktopPathTemplate`, supprimée depuis). Défaut
// 🔴 identifié en review (finding #1) : `\\<se4fs>\users\<user>\Bureau\` est un
// emplacement PAR UTILISATEUR, PARTAGÉ entre TOUS ses postes, alors que le
// desired-state est compilé par couple (poste, user). Un poste perdir/nomade y
// supprimait donc les `.lnk` gérés légitimement posés par un poste `shared_local`
// du même utilisateur, que ce dernier recréait à la passe suivante — ping-pong
// permanent de suppressions/re-créations sur un partage de production.
//
// 2.15.0 = Story 27.21, arbitrage « option A » : c'est le SERVEUR qui NOMME les
// emplacements Bureau à balayer, via le champ additif `desktop_sweep_paths` du
// payload `shortcuts`. L'agent n'invente plus rien, il obéit :
//
//	parc shared_local             → [Bureau réseau, Bureau local]  (double
//	                                 balayage anti-orphelins : une bascule de la
//	                                 politique home ne laisse jamais de `.lnk`
//	                                 géré à l'ancien emplacement)
//	parc personal_local / nomade  → [Bureau local] SEULEMENT       (aucune
//	                                 autorité sur le Bureau réseau)
//
// POSE ≠ BALAYAGE : `desktop_path` (string) reste la SEULE autorité de
// PLACEMENT ; `desktop_sweep_paths` (liste) ne gouverne QUE le nettoyage des
// `.lnk` gérés sortis des règles. La constante réseau côté agent a DISPARU (plus
// aucune source de vérité dupliquée serveur/agent) ; l'agent n'ajoute d'office
// que des emplacements PROPRES AU POSTE (Bureau local standard, `desktop_path`
// du desired) — jamais un emplacement partagé non nommé par le serveur.
//
// CONTRAT WIRE : champ AJOUTÉ = évolution mineure §9, forward-compatible. Un
// agent antérieur qui ignore `desktop_sweep_paths` garde son comportement de
// balayage précédent — pour un binaire ≤ 2.13.0 : il pose au bon endroit (il
// obéit au `desktop_path`, déjà policy-aware côté serveur) mais ne nettoie PAS
// l'ancien emplacement ⇒ raccourcis fantômes sur le Bureau réseau après coupure
// de K:. Golden `state.v1.json` et `FROZEN_STATE_HASH` (PHP + Go) RÉGÉNÉRÉS
// sciemment (l'item `shortcuts` du golden porte le nouveau champ).
//
// Fail-soft associé (`UsableShortcutDir`) : sur un poste où `<se4fs>` n'est pas
// substituable (hors-domaine, ni SE4FS ni LOGONSERVER), la probe réseau est
// IGNORÉE — jamais une passe en erreur, les autres emplacements convergent.
//
// Injectable au build (var, pas const) :
//
//	go build -ldflags "-X sambaedu/agent/shared.Version=2.2.1"
var Version = "2.15.0"
