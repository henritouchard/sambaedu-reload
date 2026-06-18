package shared

import (
	"net/url"
	"os"
	"time"
)

// Fetch de session côté SYSTEM (Story 24.6 — portage d'Invoke-SessionStateFetch
// 24.3/24.4). Le compagnon de session (droits user) ne peut NI lire le token
// NI appeler le serveur (ACL 23.3 figée, NFR5) : le canal réseau reste 100 %
// SYSTEM. Ce code tire `GET /state?user=` pour chaque session interactive et
// écrit un cache PER-USER que le processus user lit en LECTURE SEULE.
//
// Un seul code pour les deux déclencheurs (décision 24.3 n° 4, conservée) :
// la tâche planifiée at-logon (`agent.exe session-fetch`) ET le cycle du
// service (rafraîchissement mid-session, IN-PROCESS — jamais de
// sous-processus).

// Session : une session interactive résolue côté SYSTEM (énumération WTS —
// l'identité n'est JAMAIS déclarée par le user, anti-usurpation par
// construction). Login = login COURT (jamais DOMAIN\user) ; SID = clé stable
// des ACL et des répertoires per-user.
type Session struct {
	Login string
	SID   string
}

// fetchSessionStates : pour chaque session interactive, `GET /state?user=`
// avec l'If-None-Match DU CONTEXTE (poste, user — un ETag par couple, piège
// n° 2), puis cache per-SID.
//
//   - quarantaine : AUCUN fetch de session (les check-ins légers restent le
//     GET /state machine du service) ;
//   - erreur réseau : log + skip de la session, PAS de backoff propre — le
//     rattrapage est le cycle du service ;
//   - rotation D5 / grâce / deux-acteurs : gérées par le Client 24.5 (le
//     MÊME client que la portée machine — jamais un second client HTTP) ;
//   - login inconnu / compte local : le serveur répond 200 machine-only
//     (`agent.state.unknown_user` côté serveur) — traité comme tout 200,
//     aucun bruit côté poste.
func (a *Agent) fetchSessionStates(cfg Config) {
	// Réinitialisé à chaque passe. Sert AUSSI d'ensemble autoritaire des SID
	// vivants pour PurgeOrphanDrops (RunCycle) — réutilisé sans seconde
	// énumération WTS. nil = indéterminé (quarantaine / pas d'énumérateur /
	// échec) → purge fail-open ; map vide (zéro session) → purge légitime.
	a.activeSIDs = nil
	if a.quarantined {
		a.Log.Debugf("Quarantaine active : fetch de session sauté (check-ins légers = GET /state machine uniquement).")

		return
	}
	if a.Sessions == nil {
		return
	}

	sessions, err := a.Sessions()
	if err != nil {
		a.Log.Warningf("Énumération des sessions interactives en échec : %v", err)

		return
	}

	// Ensemble des SID vivants AVANT le court-circuit « zéro session » : zéro
	// session interactive = map vide autoritaire (tous les drops sont orphelins).
	active := make(map[string]bool, len(sessions))
	for _, session := range sessions {
		active[session.SID] = true
	}
	a.activeSIDs = active

	if len(sessions) == 0 {
		a.Log.Debugf("Aucune session interactive : pas de fetch de session.")

		return
	}

	// Token relu sur disque à CHAQUE fetch : l'autre acteur (service ou
	// tâche logon) peut l'avoir rotaté depuis la dernière lecture de CE
	// process.
	token, err := a.Store.ReadToken()
	if err != nil {
		a.Log.Errorf("Fetch de session impossible : %v", err)

		return
	}
	a.Client.SetToken(token)

	for _, session := range sessions {
		// Garde structurelle (review 24.3 #1, conservée en défense même si
		// l'énumérateur filtre déjà) : `?user=` vide ne part JAMAIS.
		if session.Login == "" || session.SID == "" {
			continue
		}

		// Le répertoire de drop per-SID est garanti AVANT toute passe
		// compagnon (créé/ACLé par SYSTEM — le user ne peut pas le créer
		// lui-même sous ProgramData). Même au 304 : un cache existant suffit
		// au compagnon pour converger et déposer son drop.
		if err := a.Store.EnsureSessionReportDir(session.SID, a.SessionReportACL); err != nil {
			a.Log.Warningf("Création du répertoire de drop %s en échec : %v", session.SID, err)
		}

		stateURL := cfg.ServerURL + "/api/v1/agent/state?user=" + url.QueryEscape(session.Login)
		resp, err := a.Client.Get(stateURL, a.Store.ReadSessionEtag(session.SID))
		if err != nil {
			a.Log.Warningf("Serveur injoignable sur GET /state?user=%s : %v — skip (rattrapage au cycle du service).", session.Login, err)

			continue
		}

		switch resp.StatusCode {
		case 200:
			// Refus d'un major inconnu (§9) : log erreur, cache du contexte
			// PRÉSERVÉ — même posture que la portée machine.
			if _, err := ParseState(resp.Body); err != nil {
				a.Log.Errorf("État de session refusé pour %s (%v) : cache du contexte préservé.", session.Login, err)

				continue
			}
			newEtag := resp.Header.Get(headerETag)
			if newEtag != "" {
				if err := a.Store.WriteSessionStateCache(session.SID, resp.Body, newEtag, a.SessionCacheACL); err != nil {
					a.Log.Warningf("Persistance du cache de session %s en échec : %v", session.SID, err)

					continue
				}
			}
			a.Log.Infof("GET /state?user=%s -> 200 : cache de session %s rafraîchi.", session.Login, session.SID)
		case 304:
			// Cache encore VALIDE (ETag match) mais inchangé : le serveur ne
			// renvoie pas de corps, donc on ne réécrit pas le fichier. On
			// rafraîchit néanmoins son mtime (levier B) : sans ça, après une
			// période sans changement (que des 304), le cache garde le mtime de
			// son dernier 200 — vieux de plusieurs minutes/heures — et le
			// compagnon le juge « périmé » (WaitForCache : frais = mtime <
			// FreshWindow), attendant le PollTimeout plein (~60 s) à chaque
			// logon avant de converger. Toucher le mtime reflète la VÉRITÉ
			// (« cache validé à l'instant ») et supprime cette attente en
			// régime stable. Best-effort : un échec de touch n'interrompt rien.
			now := time.Now()
			if err := os.Chtimes(a.Store.SessionStatePath(session.SID), now, now); err != nil {
				a.Log.Debugf("Rafraîchissement du mtime du cache de session %s (304) en échec : %v — sans impact (rattrapage au prochain 200).", session.SID, err)
			}
			a.Log.Debugf("GET /state?user=%s -> 304 : cache de session %s valide (mtime rafraîchi).", session.Login, session.SID)
		case 401:
			// Grâce mémoire ET relecture disque déjà tentées par le Client :
			// irrécupérable. On ARRÊTE les fetchs (les sessions suivantes
			// échoueraient pareil) ; jamais de re-enrôlement auto.
			a.Log.Errorf("401 irrécupérable sur GET /state?user=%s : fetchs de session interrompus — re-enrôlement MANUEL requis.", session.Login)

			return
		case 403:
			// Quarantaine prononcée pendant le fetch : plus AUCUN traitement
			// d'état — le flag coupe aussi le rapport du cycle en cours côté
			// service. (La tâche at-logon, processus neuf, ne connaît pas
			// l'état quarantaine du service : elle tente UN fetch, encaisse
			// le 403, s'arrête — asymétrie documentée session-companion.md §7.)
			a.enterQuarantine("GET /state?user=")

			return
		default:
			a.Log.Warningf("GET /state?user=%s -> %d inattendu : skip (rattrapage au cycle du service).", session.Login, resp.StatusCode)
		}
	}
}

// RunSessionFetch : point d'entrée de la tâche planifiée at-logon
// (`agent.exe session-fetch`, contexte SYSTEM) — fetch des sessions puis
// sync des assets wallpaper (le compagnon n'a ni réseau ni token). Toute
// panique est rattrapée : rien ne doit jamais être visible/bloquant au logon
// (NFR1).
func (a *Agent) RunSessionFetch(cfg Config) {
	defer func() {
		if r := recover(); r != nil {
			a.Log.Errorf("SessionStateFetch en échec (panique rattrapée) : %v", r)
		}
	}()

	a.fetchSessionStates(cfg)
	a.SyncWallpaperAssets(cfg)
	a.SyncShortcutIcons(cfg) // Story 27.7 : icônes uploadées (GET statique sans token)
}
