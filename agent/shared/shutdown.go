package shared

import (
	"time"
)

// NotifyShutdown signale l'extinction du poste au serveur (`POST
// /api/v1/agent/shutdown`) — BEST-EFFORT : appelé par le handler SCM Windows
// sur svc.Shutdown UNIQUEMENT (arrêt/redémarrage machine), jamais sur le stop
// manuel du service (le poste reste allumé). Un échec est logué et avalé : le
// serveur retombe alors sur son seuil de silence (2 × ttl), le signal n'est
// qu'une accélération de la détection.
//
// Le client est DÉDIÉ (timeout court imposé par le budget shutdown du SCM,
// ~5 s par défaut) et distinct de a.Client : la boucle Run peut être en plein
// cycle au moment du shutdown — on ne touche ni son token ni son timeout.
// Une rotation D5 portée par la réponse est persistée normalement (écriture
// disque atomique) ; une réponse de rotation perdue est déjà tolérée côté
// serveur (grâce sur le token précédent, ré-émission au prochain usage).
func (a *Agent) NotifyShutdown(timeout time.Duration) {
	cfg, err := a.Store.ReadConfig()
	if err != nil {
		a.Log.Warningf("Signal d'extinction abandonné (config illisible) : %v", err)

		return
	}
	if !a.Store.TokenExists() {
		// Poste jamais enrôlé : rien à signaler.
		return
	}
	token, err := a.Store.ReadToken()
	if err != nil {
		a.Log.Warningf("Signal d'extinction abandonné (token illisible) : %v", err)

		return
	}

	client := NewClient(a.Store, a.Log, a.Hostname)
	client.HTTP.Timeout = timeout
	client.SetToken(token)

	resp, err := client.Post(cfg.ServerURL+"/api/v1/agent/shutdown", nil)
	if err != nil {
		a.Log.Warningf("Signal d'extinction non délivré : %v", err)

		return
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		a.Log.Warningf("Signal d'extinction refusé par le serveur (HTTP %d).", resp.StatusCode)

		return
	}
	a.Log.Infof("Extinction signalée au serveur.")
}
