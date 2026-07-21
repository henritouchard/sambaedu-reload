package main

import (
	"errors"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"golang.org/x/sys/windows"

	"sambaedu/agent/shared"
)

// Pose du LIEN `app_profile` par le SERVICE SYSTEM au logon (Story 36.5,
// amendement final — conception ARRÊTÉE Henri 2026-07-21). Sur le modèle EXACT
// de l'overlay (Story 27.1bis, overlay_logon_windows.go) : déclenché par
// WTS_SESSION_LOGON (service_windows.go), résolution user/profil via le token de
// session (WTSQueryUserToken → GetUserProfileDirectory), best-effort/gracieux de
// bout en bout (rien ne bloque jamais le service ni les autres sessions).
//
// POURQUOI SYSTEM. Le lien de dossier vers UNC (mklink /D iso-SE4) exige
// SeCreateSymbolicLinkPrivilege, qu'AUCUN canal SE5 ne peut accorder à
// l'utilisateur (le mécanisme `privilege` 35.6 est SeDeny*-only par conception)
// — mais que LocalSystem possède NATIVEMENT. Le service pose donc / répare le
// LIEN ; le COMPAGNON garde tout le reste (dossier serveur, marqueur, user.js,
// paire d'ini — contexte user, non privilégié). Split iso-SE4.
//
// SOURCE INFALSIFIABLE. La spec `app_profile` est lue dans le cache d'état
// per-SID que SYSTEM a LUI-MÊME écrit au fetch (cache\sessions\<SID>\state.json,
// sessionfetch.go) — JAMAIS dans un artefact inscriptible par l'utilisateur (le
// drop <SID>:M est report-only, ligne rouge structurelle dropcollect.go:243).
//
// La logique PURE (extraction des specs, substitution de tokens, calcul
// lien/cible, validation de borne, décision d'action) vit dans
// shared/app_profile_logon.go (testée sur l'hôte). Ce fichier n'apporte que le
// glue WTS/token et les ops natives (CreateSymbolicLink DIRECTORY, os.Rename) —
// non testable hôte, comme l'overlay.

// applyAppProfilesForAllSessions : sur un logon, ré-énumère les sessions
// interactives (WTS vet-clean, iso writeOverlayForAllSessions) et pose/répare le
// lien app_profile pour chacune. La nouvelle session y apparaît ; les autres
// re-convergent (idempotent — lien déjà correct = no-op). Gracieux de bout en
// bout (un échec est loggé, jamais propagé).
func applyAppProfilesForAllSessions(store *shared.Store, log *shared.Logger) {
	ids, err := interactiveSessionIDs()
	if err != nil {
		log.Warningf("Lien app_profile au logon : énumération des sessions interactives en échec (%v) — pose sautée.", err)

		return
	}
	// SE4FS est une variable MACHINE, lisible par le service SYSTEM (même source
	// que handler_applications/shortcuts/printers). Résolu UNE fois pour toutes
	// les sessions.
	se4fs := machineSe4fs()
	for _, id := range ids {
		applyAppProfilesForSession(id, se4fs, store, log)
	}
}

// applyAppProfilesForSession résout l'utilisateur/profil de la session (via le
// token), lit son cache per-SID, extrait les specs app_profile et pose/répare le
// lien de chacune. Gracieux de bout en bout.
func applyAppProfilesForSession(sessionID uint32, se4fs string, store *shared.Store, log *shared.Logger) {
	// Token de l'utilisateur de la session (SYSTEM seul peut l'obtenir).
	var userToken windows.Token
	if err := windows.WTSQueryUserToken(sessionID, &userToken); err != nil {
		log.Debugf("Lien app_profile : WTSQueryUserToken(session=%d) en échec (%v) — pas de pose (gracieux).", sessionID, err)

		return
	}
	defer userToken.Close()

	// SID (clé du cache per-SID) — même sous-système LSA que le fetch (cohérence 24.6).
	tokenUser, err := userToken.GetTokenUser()
	if err != nil {
		log.Warningf("Lien app_profile : résolution du SID de session %d impossible (%v) — pose sautée.", sessionID, err)

		return
	}
	sid := tokenUser.User.Sid.String()
	if !isInteractiveUserSID(sid) {
		log.Debugf("Lien app_profile : SID %s hors liste blanche (S-1-5-21-) — ignoré.", sid)

		return
	}

	// %USERPROFILE% de la session, résolu sous SYSTEM via le PROFIL du token —
	// racine contre laquelle `link` (relatif) est joint (iso overlay).
	profileDir, err := userToken.GetUserProfileDirectory()
	if err != nil || profileDir == "" {
		log.Warningf("Lien app_profile : profil de session %s non résoluble (%v) — pose sautée.", sid, err)

		return
	}

	// Login COURT (substitution `<user>`) — depuis WTS, JAMAIS l'environnement du
	// service (USERNAME = le compte de service sous SYSTEM). Anti-usurpation par
	// construction (l'identité est résolue côté SYSTEM, iso sessions_windows.go).
	login, err := wtsQuerySessionString(sessionID, wtsUserName)
	if err != nil || strings.TrimSpace(login) == "" {
		log.Warningf("Lien app_profile : login de la session %s non résolu (%v) — pose sautée (impossible de substituer <user>).", sid, err)

		return
	}

	// Cache d'état per-SID que SYSTEM a lui-même écrit au fetch (source
	// infalsifiable). Absent (fetch pas encore passé) ⇒ pas de pose, gracieux.
	raw, err := os.ReadFile(store.SessionStatePath(sid))
	if err != nil {
		log.Debugf("Lien app_profile : cache de session %s absent (%v) — pas de pose (le fetch le posera).", sid, err)

		return
	}
	state, err := shared.ParseState(raw)
	if err != nil {
		log.Warningf("Lien app_profile : cache de session %s illisible (%v) — pose sautée.", sid, err)

		return
	}

	specs := shared.AppProfileSpecsFromSession(state, log)
	for _, spec := range specs {
		poseAppProfileLink(spec, profileDir, login, se4fs, log)
	}
}

// poseAppProfileLink pose / répare le lien d'UNE app (défense en profondeur +
// décision pure + ops natives). Un échec sur une app n'empêche pas les autres.
func poseAppProfileLink(spec shared.AppProfileSpec, profileDir, login, se4fs string, log *shared.Logger) {
	target := shared.AppProfileServerTarget(spec, login, se4fs)
	link := shared.AppProfileLinkPath(spec, profileDir)

	// DÉFENSE EN PROFONDEUR (obligatoire même si la source est infalsifiable) :
	// lien strictement SOUS le profil du token + cible UNC, sinon skip + log.
	if err := shared.ValidateAppProfileBounds(link, profileDir, target); err != nil {
		log.Warningf("Lien app_profile %s : %v", spec.App, err)

		return
	}

	exists, isLink, current, err := appProfileLinkState(link)
	if err != nil {
		log.Warningf("Lien app_profile %s : inspection de %q en échec (%v) — pose sautée.", spec.App, link, err)

		return
	}

	switch shared.DecideLinkAction(exists, isLink, current, target) {
	case shared.LinkNoop:
		return // lien déjà correct : idempotent, no-op silencieux.
	case shared.LinkReplaceLink:
		// Lien divergent : retirer LE LIEN (jamais sa cible) puis re-poser.
		if err := os.Remove(link); err != nil {
			log.Warningf("Lien app_profile %s : retrait du lien divergent %q en échec (%v) — pose sautée.", spec.App, link, err)

			return
		}
	case shared.LinkMoveAside:
		// VRAI dossier préexistant (C1) : renommé DE CÔTÉ, JAMAIS détruit.
		aside, err := moveDirAside(link)
		if err != nil {
			log.Warningf("Lien app_profile %s : mise de côté du dossier réel %q en échec (%v) — pose sautée.", spec.App, link, err)

			return
		}
		log.Infof("Lien app_profile %s : dossier réel préexistant déplacé de côté (jamais détruit) : %s → %s", spec.App, link, aside)
	case shared.LinkCreate:
		// Rien à l'emplacement : pose directe.
	}

	if err := os.MkdirAll(filepath.Dir(link), 0o755); err != nil {
		log.Warningf("Lien app_profile %s : création du dossier parent de %q en échec (%v) — pose sautée.", spec.App, link, err)

		return
	}
	if err := createDirectorySymlink(link, target); err != nil {
		log.Warningf("Lien app_profile %s : pose du lien %q → %q en échec (%v).", spec.App, link, target, err)

		return
	}
	log.Infof("Lien app_profile %s posé par SYSTEM au logon : %s → %s (session de %s).", spec.App, link, target, login)
}

// appProfileLinkState : Lstat + Readlink du chemin du lien. Absent ⇒
// (false, false, "", nil) ; vrai dossier/fichier ⇒ (true, false, "", nil) ;
// lien ⇒ (true, true, cible, nil) — un point d'analyse illisible est traité
// comme un lien divergent (cible inconnue → sera refait).
func appProfileLinkState(link string) (exists, isLink bool, target string, err error) {
	info, lerr := os.Lstat(link)
	if lerr != nil {
		if errors.Is(lerr, os.ErrNotExist) {
			return false, false, "", nil
		}

		return false, false, "", lerr
	}
	if info.Mode()&os.ModeSymlink == 0 {
		return true, false, "", nil
	}
	tgt, rerr := os.Readlink(link)
	if rerr != nil {
		return true, true, "", nil
	}

	return true, true, tgt, nil
}

// createDirectorySymlink pose un lien symbolique de DOSSIER `link` → `target`
// (UNC) via CreateSymbolicLink avec le flag SYMBOLIC_LINK_FLAG_DIRECTORY
// EXPLICITE.
//
// POURQUOI PAS os.Symlink : sous SYSTEM la cible UNC (`\\<se4fs>\users\<user>`)
// n'est PAS joignable (le service n'a pas de session réseau utilisateur) — donc
// l'auto-détection « la cible est-elle un dossier ? » de os.Symlink échouerait et
// poserait un lien de FICHIER, cassé pour l'utilisateur. On force donc le flag
// DIRECTORY. Le lien ne stocke qu'une CHAÎNE cible (aucun accès réseau à la
// création) ; l'UNC est résolu plus tard avec les credentials de l'UTILISATEUR
// quand il suit le lien depuis sa session.
func createDirectorySymlink(link, target string) error {
	linkPtr, err := windows.UTF16PtrFromString(link)
	if err != nil {
		return err
	}
	targetPtr, err := windows.UTF16PtrFromString(target)
	if err != nil {
		return err
	}

	return windows.CreateSymbolicLink(linkPtr, targetPtr, windows.SYMBOLIC_LINK_FLAG_DIRECTORY)
}

// moveDirAside renomme `path` DE CÔTÉ vers `<path>.pre-redirect-<horodatage>`
// (format compact 20060102-150405), en suffixant `-1`, `-2`… en cas de collision.
// Le dossier réel n'est JAMAIS détruit (doctrine desired-state, C1) — l'utilisateur
// / l'admin peut récupérer son contenu. Retourne le chemin final. (Déplacé du
// compagnon vers SYSTEM avec la pose du lien — même sémantique C1.)
func moveDirAside(path string) (string, error) {
	base := path + ".pre-redirect-" + time.Now().Format("20060102-150405")
	candidate := base
	for i := 1; ; i++ {
		if _, statErr := os.Lstat(candidate); errors.Is(statErr, os.ErrNotExist) {
			break
		}
		candidate = base + "-" + strconv.Itoa(i)
	}
	if err := os.Rename(path, candidate); err != nil {
		return "", err
	}

	return candidate, nil
}

// machineSe4fs résout SE4FS en contexte SYSTEM : variable MACHINE (source
// AUTORITAIRE, posée au provisioning du poste — même valeur que les autres
// handlers windows lisent). Fallback LOGONSERVER (dépouillé de `\\`) en dernier
// recours ; ⚠️ sous SYSTEM, LOGONSERVER pointe le DC d'authentification de la
// MACHINE (pas forcément le serveur de fichiers) — d'où le choix de SE4FS
// (machine) comme source primaire, documenté au contrat §7.11.
func machineSe4fs() string {
	se4fs := os.Getenv("SE4FS")
	if se4fs == "" {
		se4fs = strings.TrimLeft(os.Getenv("LOGONSERVER"), `\`)
	}

	return se4fs
}
