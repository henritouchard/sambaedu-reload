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
// session-fetch SYSTEM), en extrait les items des portées session +
// machine_user (mêmes portées que le compagnon, ordre serveur) et compose le
// document overlay.json cible.
//
// Retourne (document, true) si un cache exploitable existe ; ("", false) si le
// cache est absent (premier logon hors-ligne : rien à composer — l'invariant
// « Rainmeter absent = gracieux » s'applique à l'amont, on n'écrit rien plutôt
// qu'un document vide qui écraserait un overlay précédent valide). Un cache
// illisible/corrompu = ("", false) + log côté appelant.
func OverlayDocumentForSession(store *Store, sid, computerName string, log *Logger) (string, bool) {
	raw, err := os.ReadFile(store.SessionStatePath(sid))
	if err != nil {
		logDebug(log, "Cache de session absent : pas de composition overlay au logon (gracieux).")

		return "", false
	}

	state, err := ParseState(raw)
	if err != nil {
		logWarning(log, "Cache de session illisible : composition overlay sautée au logon.")

		return "", false
	}

	// Mêmes portées que le compagnon (session + machine_user, ordre serveur).
	items := ItemsFromScope(state.Session, log)
	items = append(items, ItemsFromScope(state.MachineUser, log)...)

	// Ne retenir que les items overlay (les autres types — wallpaper,
	// shortcuts, printers, drives — restent gérés par le compagnon). La
	// composition tolère une liste sans item overlay (document machine-only).
	overlayItems := make([]StateItem, 0, len(items))
	for _, item := range items {
		if item.Type == "overlay" {
			overlayItems = append(overlayItems, item)
		}
	}

	return ComposeOverlayDocument(overlayItems, computerName), true
}
