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
// 2.2.10 = préchargement identité MACHINE de l'overlay (Story 27.10). La SALLE
// (`machine.room`) passe de la portée session (item identity) à la portée
// MACHINE (cache persistant) : ComposeOverlayDocument extrait `room` de l'item
// `kind:"machine"`, et OverlayDocumentForSession lit le cache MACHINE + le cache
// session per-SID. Au logon, poste + salle s'affichent dès le cache machine, sans
// attendre le fetch per-user (login/fullname arrivent ensuite avec le cache
// session). Byte-format overlay.json INCHANGÉ ; contrat bumpé (golden + 2 hashes
// figés croisés PHP↔Go).
//
// Injectable au build (var, pas const) :
//
//	go build -ldflags "-X sambaedu/agent/shared.Version=2.2.1"
var Version = "2.2.15"
