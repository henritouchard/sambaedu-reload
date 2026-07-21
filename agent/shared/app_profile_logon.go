package shared

import (
	"fmt"
	"strings"
)

// Logique PORTABLE de la pose du lien `app_profile` par le SERVICE SYSTEM au
// logon (Story 36.5, amendement final — conception ARRÊTÉE Henri 2026-07-21).
//
// POURQUOI SYSTEM POSE LE LIEN. Le lien de dossier vers UNC (report SE4
// `mklink /D`, `applications.inc.php:597`) exige `SeCreateSymbolicLinkPrivilege`,
// que l'utilisateur standard n'a pas et qu'AUCUN canal SE5 ne peut lui accorder
// (le mécanisme `privilege` 35.6 est `SeDeny*`-only par conception). Le service
// LocalSystem, LUI, possède ce privilège nativement. Sur le modèle EXACT de
// l'overlay (Story 27.1bis, `overlay_logon_windows.go`), SYSTEM pose donc le
// LIEN au `WTS_SESSION_LOGON`, dans le profil de la session résolu via le token
// WTS — le COMPAGNON garde tout le reste (dossier serveur, marqueur, user.js,
// paire d'ini ; contexte user, non privilégié).
//
// SPLIT ISO-SE4 :
//   - SYSTEM   : pose / répare le LIEN uniquement (logon-triggered) ;
//   - COMPAGNON : dossier serveur + marqueur + user.js + ini (level-triggered).
//
// SOURCE INFALSIFIABLE. SYSTEM lit la spec `app_profile` dans le cache d'état
// per-SID qu'il a LUI-MÊME écrit au fetch (`cache\sessions\<SID>\state.json`,
// `sessionfetch.go`) — JAMAIS dans un artefact inscriptible par l'utilisateur
// (le drop `<SID>:M` est report-only, ligne rouge structurelle
// `dropcollect.go:243-253`). La demande vient donc du SERVEUR, jamais du
// compagnon.
//
// Ce fichier ne contient QUE de la logique pure (extraction des specs depuis un
// state parsé, substitution de tokens en contexte SYSTEM, calcul lien/cible,
// validation de borne, décision d'action) — testée sur l'hôte. Le glue WTS/token
// et les ops natives (CreateSymbolicLink DIRECTORY, os.Rename) vivent dans
// agent/windows/app_profile_logon_windows.go (non testable hôte, comme l'overlay).

// SubstituteServerTokens substitue les DEUX tokens serveur `<user>` et `<se4fs>`
// d'un chemin — CŒUR PUR unique de la substitution (contrainte 36.5 : un SEUL
// helper). Le compagnon l'appelle avec les valeurs tirées de son ENVIRONNEMENT
// (substituteTokens, agent/windows) ; le service SYSTEM l'appelle avec l'identité
// de la SESSION (login WTS) et le SE4FS machine — car sous SYSTEM l'environnement
// du service ne porte PAS l'utilisateur de la session (USERNAME = le compte de
// service). Ordre iso-legacy : `<user>` puis `<se4fs>`.
func SubstituteServerTokens(path, user, se4fs string) string {
	path = strings.ReplaceAll(path, "<user>", user)
	path = strings.ReplaceAll(path, "<se4fs>", se4fs)

	return path
}

// AppProfileSpecsFromSession extrait les specs `app_profile` de la portée SESSION
// d'un state parsé (le cache per-SID que SYSTEM a écrit au fetch). Best-effort :
// un item au payload invalide est LOGGÉ et SAUTÉ (jamais d'échec de la passe — un
// item malformé ne doit pas priver les autres sessions de leur lien). Les items
// d'un autre type sont ignorés.
func AppProfileSpecsFromSession(state *State, log *Logger) []AppProfileSpec {
	specs := make([]AppProfileSpec, 0, 2)
	for _, item := range ItemsFromScope(state.Session, log) {
		if item.Type != "app_profile" {
			continue
		}
		spec, ok := parseAppProfileSpec(item.Payload)
		if !ok {
			logWarning(log, "Lien app_profile au logon : item au payload invalide, sauté (les autres items restent traités).")

			continue
		}
		specs = append(specs, spec)
	}

	return specs
}

// AppProfileServerTarget résout le TOKEN serveur d'une spec (`\\<se4fs>\users\
// <user>\…`) en UNC réel, avec l'identité de la SESSION (login WTS) et le SE4FS
// machine — contexte SYSTEM.
func AppProfileServerTarget(spec AppProfileSpec, user, se4fs string) string {
	return SubstituteServerTokens(spec.Server, user, se4fs)
}

// AppProfileLinkPath résout le chemin ABSOLU du lien : `link` (relatif au profil
// Windows) joint au répertoire de profil de la session (retourné par le token
// WTS sous SYSTEM). joinPath (séparateur `\`) est portable — jamais filepath.Join
// (qui prendrait `/` sur l'hôte de test).
func AppProfileLinkPath(spec AppProfileSpec, profileDir string) string {
	return joinPath(profileDir, spec.Link)
}

// ValidateAppProfileBounds : DÉFENSE EN PROFONDEUR (obligatoire même si la source
// est infalsifiable). Deux invariants, sinon on REFUSE de poser (skip + log,
// jamais de pose) :
//   - la CIBLE doit être un chemin UNC (`\\serveur\…`) — on ne fait pointer un
//     lien de profil QUE vers le home réseau ;
//   - le LIEN doit être STRICTEMENT SOUS le répertoire de profil de la session
//     (filepath.Clean-équivalent + préfixe) — un `link` porteur de `..` qui
//     s'échapperait du profil (spec corrompue) ne doit JAMAIS matérialiser un
//     lien ailleurs sur le disque, en contexte SYSTEM privilégié.
func ValidateAppProfileBounds(link, profileDir, target string) error {
	if !isUncPath(target) {
		return fmt.Errorf("cible %q non-UNC (attendu \\\\serveur\\partage\\… ) : pose refusée", target)
	}
	if !windowsPathUnder(link, profileDir) {
		return fmt.Errorf("lien %q hors du répertoire de profil %q : pose refusée (défense en profondeur)", link, profileDir)
	}

	return nil
}

// isUncPath : vrai UNC `\\serveur\partage\…` UNIQUEMENT. `strings.HasPrefix
// "\\\\"` ne suffit pas : les syntaxes extended-length (`\\?\C:\…`) et device
// (`\\.\PhysicalDrive0`) commencent aussi par `\\` mais désignent des chemins
// LOCAUX — les accepter contournerait l'invariant « un lien de profil ne pointe
// QUE vers le home réseau » (contre-review 36.5, P1). On exige donc un premier
// segment hôte qui ne soit ni `?` ni `.`, suivi d'un partage non vide.
func isUncPath(target string) bool {
	if !strings.HasPrefix(target, `\\`) {
		return false
	}

	rest := strings.TrimPrefix(target, `\\`)
	segments := strings.SplitN(rest, `\`, 3)
	if len(segments) < 2 {
		return false // `\\serveur` seul : pas de partage.
	}

	host, share := segments[0], segments[1]

	return host != "" && host != "?" && host != "." && share != ""
}

// LinkAction : décision PURE de ce que la pose doit faire face à l'état constaté
// du chemin du lien. Extraite pour rester testable hôte (le glue windows exécute
// l'action avec de vrais appels os).
type LinkAction int

const (
	// LinkNoop : un lien correct pointe déjà la bonne cible — idempotent, rien à faire.
	LinkNoop LinkAction = iota
	// LinkCreate : rien à l'emplacement — poser le lien.
	LinkCreate
	// LinkReplaceLink : un lien EXISTE mais pointe ailleurs — retirer LE LIEN
	// (jamais sa cible) puis re-créer. Jamais de duplication.
	LinkReplaceLink
	// LinkMoveAside : un VRAI dossier (non-lien) occupe l'emplacement — le
	// RENOMMER DE CÔTÉ (jamais détruire, C1/doctrine desired-state) puis poser
	// le lien. Cas plausible : lien effacé par un nettoyeur/AV, Firefox
	// re-matérialise un dossier où l'utilisateur accumule des signets.
	LinkMoveAside
)

// DecideLinkAction : la machine de décision de la pose (PURE). `exists`/`isLink`
// décrivent le chemin du lien tel que Lstat le voit ; `currentTarget` est la
// cible lue (Readlink) si c'est un lien ; `wantTarget` est l'UNC désiré.
func DecideLinkAction(exists, isLink bool, currentTarget, wantTarget string) LinkAction {
	if !exists {
		return LinkCreate
	}
	if isLink {
		if samePath(currentTarget, wantTarget) {
			return LinkNoop
		}

		return LinkReplaceLink
	}

	return LinkMoveAside
}

// windowsPathUnder : `child` est-il STRICTEMENT sous `parent` (chemins Windows,
// séparateur `\`, insensible à la casse) ? Résout `.`/`..` par segments (portable
// — indépendant de l'OS de test, contrairement à filepath). Un préfixe UNC (`\\`)
// des deux doit correspondre.
func windowsPathUnder(child, parent string) bool {
	cPrefix, cSegs := windowsPathSegments(child)
	pPrefix, pSegs := windowsPathSegments(parent)
	if cPrefix != pPrefix {
		return false
	}
	if len(cSegs) <= len(pSegs) {
		return false // égal ou plus court = pas STRICTEMENT sous.
	}
	for i := range pSegs {
		if !strings.EqualFold(pSegs[i], cSegs[i]) {
			return false
		}
	}

	return true
}

// windowsPathSegments : décompose un chemin Windows en (préfixe UNC éventuel,
// segments), en résolvant `.` (ignoré) et `..` (remonte d'un cran). Séparateurs
// `/` normalisés en `\`.
func windowsPathSegments(p string) (prefix string, segs []string) {
	p = strings.ReplaceAll(p, "/", `\`)
	if strings.HasPrefix(p, `\\`) {
		prefix = `\\`
		p = strings.TrimLeft(p, `\`)
	}
	for _, s := range strings.Split(p, `\`) {
		switch s {
		case "", ".":
			// segment vide ou courant : ignoré.
		case "..":
			if len(segs) > 0 {
				segs = segs[:len(segs)-1]
			}
		default:
			segs = append(segs, s)
		}
	}

	return prefix, segs
}
