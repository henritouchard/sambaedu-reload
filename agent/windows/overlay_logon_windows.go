package main

import (
	"os"
	"path/filepath"

	"golang.org/x/sys/windows"

	"sambaedu/agent/shared"
)

// Écriture de overlay.json par le SERVICE SYSTEM au logon (Story 27.1bis,
// volet 2 — D1/D2). Déclenchée par le case svc.SessionChange / EventType
// WTS_SESSION_LOGON (0x5) de service_windows.go. RÉUTILISE le socle 24.6 :
// résolution user/SID via le token de session (WTSQueryUserToken), composition
// via shared.OverlayDocumentForSession (logique pure, ComposeOverlayDocument
// inchangé), écriture atomique WriteFileAtomic, ACL <SID>:R via icacls.
//
// Contrairement au compagnon (droits user, falsifiable), c'est ici SYSTEM qui
// possède le fichier — l'élève lit, ne falsifie jamais (NFR5). L'overlay a
// QUITTÉ la map du compagnon (D1, companion_windows.go).
//
// NFR7 : aucune dépendance AD — l'identité de session vient du token WTS (LSA),
// jamais de l'annuaire.
//
// Tout est best-effort/gracieux : rien ne doit jamais bloquer ni faire échouer
// le service au logon. Erreurs → agent.log, jamais de panique propagée (le
// case appelant recover déjà).

// writeOverlayForAllSessions : sur un logon, ré-énumère les sessions
// interactives (WTS vet-clean, 24.6) et écrit overlay.json pour chacune. La
// nouvelle session y apparaît ; les autres re-convergent (idempotent — même
// document = même fichier). Gracieux de bout en bout.
func writeOverlayForAllSessions(store *shared.Store, computerName string, log *shared.Logger) {
	ids, err := interactiveSessionIDs()
	if err != nil {
		log.Warningf("Overlay au logon : énumération des sessions interactives en échec (%v) — écriture sautée.", err)

		return
	}
	for _, id := range ids {
		writeOverlayAtLogon(id, store, computerName, log)
	}
}

// writeOverlayAtLogon résout l'utilisateur de la session, compose le document
// overlay et l'écrit possédé SYSTEM + ACL <SID>:R sous le %LOCALAPPDATA% de la
// session. Gracieux de bout en bout (un échec est loggé, jamais propagé).
func writeOverlayAtLogon(sessionID uint32, store *shared.Store, computerName string, log *shared.Logger) {
	// Token de l'utilisateur de la session (SYSTEM seul peut l'obtenir).
	var userToken windows.Token
	if err := windows.WTSQueryUserToken(sessionID, &userToken); err != nil {
		// Session sans token interactif (console verrouillée transitoire,
		// session système) : rien à composer — gracieux.
		log.Debugf("Overlay au logon : WTSQueryUserToken(session=%d) en échec (%v) — pas d'écriture (gracieux).", sessionID, err)

		return
	}
	defer userToken.Close()

	// SID de l'utilisateur (clé des ACL et du cache per-SID) — même
	// sous-système LSA que LookupSID/currentProcessSID (cohérence 24.6).
	tokenUser, err := userToken.GetTokenUser()
	if err != nil {
		log.Warningf("Overlay au logon : résolution du SID de session impossible (%v) — écriture sautée.", err)

		return
	}
	sid := tokenUser.User.Sid.String()

	// Liste blanche cohérente avec l'énumération 24.6 : seuls les comptes
	// users réels (S-1-5-21-) ont un cache d'état à composer.
	if !isInteractiveUserSID(sid) {
		log.Debugf("Overlay au logon : SID %s hors liste blanche (S-1-5-21-) — ignoré.", sid)

		return
	}

	// %LOCALAPPDATA% de la session, résolu sous SYSTEM via le PROFIL du token
	// (D2 — chemin per-user conservé %LOCALAPPDATA%\SambaEdu\Agent\overlay.json,
	// la skin ne change pas de JsonPath). LocalAppData = <profil>\AppData\Local.
	profileDir, err := userToken.GetUserProfileDirectory()
	if err != nil || profileDir == "" {
		// Fallback documenté (D2/Q2) : profil non résoluble sous SYSTEM
		// (sessions atypiques) → chemin commun %ProgramData%\SambaEdu\overlay.json.
		// Limite assumée : perte du per-user multi-session (la skin garde son
		// JsonPath %LOCALAPPDATA% — ce fallback n'est PAS rendu par défaut ;
		// il garantit seulement qu'une écriture SYSTEM a lieu et est tracée).
		log.Warningf("Overlay au logon : profil de session %s non résoluble (%v) — fallback %%ProgramData%% (per-user multi-session perdu).", sid, err)
		writeOverlayDocument(filepath.Join(shared.DefaultProgramDataSambaEdu, "overlay.json"), sid, store, computerName, log)

		return
	}

	overlayPath := filepath.Join(profileDir, "AppData", "Local", "SambaEdu", "Agent", "overlay.json")
	writeOverlayDocument(overlayPath, sid, store, computerName, log)
}

// writeOverlayDocument compose (depuis les caches machine + session per-SID) et
// écrit overlay.json au chemin résolu, possédé SYSTEM + ACL <SID>:R. Aucune
// portée exploitable = no-op gracieux (premier logon hors-ligne : on n'écrase
// pas un overlay précédent par un document vide).
func writeOverlayDocument(overlayPath, sid string, store *shared.Store, computerName string, log *shared.Logger) {
	document, ok := shared.OverlayDocumentForSession(store, sid, computerName, log)
	if !ok {
		log.Debugf("Overlay au logon : aucun cache exploitable (machine ni session) pour %s — pas d'écriture (gracieux).", sid)

		return
	}

	if err := os.MkdirAll(filepath.Dir(overlayPath), 0o700); err != nil {
		log.Warningf("Overlay au logon : création de %s en échec (%v).", filepath.Dir(overlayPath), err)

		return
	}
	if err := shared.WriteFileAtomic(overlayPath, []byte(document)); err != nil {
		log.Warningf("Overlay au logon : écriture de %s en échec (%v).", overlayPath, err)

		return
	}
	// ACL <SID>:R, SYSTEM/Admins full, héritage retiré — SYSTEM propriétaire,
	// l'élève LIT mais ne falsifie jamais (NFR5). Posée sur le FICHIER (pas le
	// dossier user, qui appartient au user).
	//
	// Micro-fenêtre TOCTOU (#4, limite assumée) : entre WriteFileAtomic et la
	// pose d'ACL, le fichier existe brièvement avec l'ACL héritée du dossier
	// user. C'est le MÊME pattern acté en 24.6 (écriture atomique puis durcissage
	// ACL) ; le risque est borné (fenêtre sub-milliseconde, fichier possédé
	// SYSTEM, écrasé à chaque logon) et l'ACL finale prime. Non corrigé ici.
	if err := setOverlayFileACL(overlayPath, sid); err != nil {
		log.Warningf("Overlay au logon : pose de l'ACL <SID>:R sur %s en échec (%v) — fichier écrit mais protection non garantie.", overlayPath, err)

		return
	}
	log.Infof("Overlay au logon : overlay.json composé et écrit par SYSTEM (ACL %s:R) pour la session %s.", sid, sid)
}

// isInteractiveUserSID : liste blanche S-1-5-21- (compte user réel, domaine ou
// local) — cohérente avec enumerateInteractiveSessions (24.6).
func isInteractiveUserSID(sid string) bool {
	const prefix = "S-1-5-21-"

	return len(sid) > len(prefix) && sid[:len(prefix)] == prefix
}
