package shared

import "os"

// Écriture de overlay.json par le SERVICE SYSTEM au logon (Story 27.1bis,
// volet 2 — décisions D1/D2). L'overlay QUITTE la map du compagnon (D1) : il
// était écrit en droits user (falsifiable) ; il est désormais composé ET écrit
// par SYSTEM au logon, possédé SYSTEM avec ACL <SID>:R (l'élève lit, ne
// falsifie jamais — NFR5). La COMPOSITION (ComposeOverlayDocument) est
// réutilisée À L'IDENTIQUE (logique pure, golden inchangé).
//
// Q1 = Option B (logon-only, tranchée par Henri 2026-06-15) : écriture
// ÉVÉNEMENTIELLE au logon UNIQUEMENT — pas de timer périodique SYSTEM, les
// alertes live ne sont pas rafraîchies en cours de session (assumé démo).
//
// La logique pure (lecture du cache per-SID → extraction des items overlay →
// composition du document) vit ici, testée sur l'hôte. Le spécifique Windows
// (résolution du %LOCALAPPDATA% de la session sous SYSTEM via le token,
// écriture atomique, ACL <SID>:R) reste dans agent/windows.

// OverlayDocumentForSession lit le cache d'état per-SID (alimenté par
// session-fetch SYSTEM) ET le cache MACHINE persistant (cache/state.json,
// alimenté par le cycle service + réveil-logon 27.9), en extrait les items
// overlay des DEUX, et compose le document overlay.json cible.
//
// La salle (`machine.room`) vient de la portée MACHINE du cache machine
// (Story 27.10) : poste + salle sont ainsi composés DÈS le logon sans attendre
// le fetch per-user. `identity.login/fullname` viennent de la portée session
// du cache per-SID. Le compose tourne côté SYSTEM (logon-only) → l'accès aux
// deux caches respecte la partition des portées (le COMPAGNON en droits user
// ne lit JAMAIS la portée machine ; lui ne lit que son cache session per-SID).
//
// Gracieux à toutes les granularités :
//   - cache machine présent, session absent → `machine.room` rempli (préchargement),
//     `identity` vide jusqu'à l'arrivée du cache session ;
//   - cache session présent, machine absent → identity rempli, room vide ;
//   - les deux absents → ("", false) : rien à composer (on n'écrase pas un
//     overlay précédent valide par un document vide).
//
// Un cache illisible/corrompu n'avorte pas la composition : la portée intacte
// est utilisée (best-effort), l'autre est sautée + log. Retourne ("", false)
// uniquement si AUCUNE portée exploitable n'a été trouvée.
func OverlayDocumentForSession(store *Store, sid, computerName string, log *Logger) (string, bool) {
	overlayItems := make([]StateItem, 0, 8)
	found := false

	// Cache MACHINE (portée machine — la salle). Best-effort : absent/illisible
	// → on poursuit avec le seul cache session (room vide).
	if raw, err := os.ReadFile(store.StateCachePath()); err != nil {
		logDebug(log, "Cache machine absent : salle non préchargée au logon (gracieux).")
	} else if state, err := ParseState(raw); err != nil {
		logWarning(log, "Cache machine illisible : salle non préchargée au logon.")
	} else {
		overlayItems = appendOverlayItems(overlayItems, ItemsFromScope(state.Machine, log))
		found = true
	}

	// Cache SESSION per-SID (portées session + machine_user — identity + alertes).
	if raw, err := os.ReadFile(store.SessionStatePath(sid)); err != nil {
		logDebug(log, "Cache de session absent : identity non composée au logon (gracieux).")
	} else if state, err := ParseState(raw); err != nil {
		logWarning(log, "Cache de session illisible : identity sautée au logon.")
	} else {
		// Mêmes portées que le compagnon (session + machine_user, ordre serveur).
		overlayItems = appendOverlayItems(overlayItems, ItemsFromScope(state.Session, log))
		overlayItems = appendOverlayItems(overlayItems, ItemsFromScope(state.MachineUser, log))
		found = true
	}

	if !found {
		// Aucune portée exploitable : rien à composer (l'invariant « Rainmeter
		// absent = gracieux » s'applique à l'amont — on n'écrase pas un overlay
		// précédent valide par un document vide).
		return "", false
	}

	return ComposeOverlayDocument(overlayItems, computerName), true
}

// appendOverlayItems ne retient que les items overlay (les autres types —
// wallpaper, shortcuts, printers, drives — restent gérés par le compagnon). La
// composition tolère une liste sans item overlay (document machine-only).
func appendOverlayItems(dst, items []StateItem) []StateItem {
	for _, item := range items {
		if item.Type == "overlay" {
			dst = append(dst, item)
		}
	}

	return dst
}
