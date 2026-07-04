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
// Injectable au build (var, pas const) :
//
//	go build -ldflags "-X sambaedu/agent/shared.Version=2.2.1"
var Version = "2.6.0"
