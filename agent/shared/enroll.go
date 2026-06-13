package shared

import (
	"encoding/json"
)

// EnrollOutcome : résultat de la demande d'enrôlement porte 2 (Story 25.4).
type EnrollOutcome int

const (
	// EnrollPending : 403 AGENT_ENROLL_NOT_ALLOWED — demande enregistrée
	// côté serveur (indistinct, sans oracle). Le poste reste en check-ins
	// légers et retentera à cadence normale, JAMAIS de backoff agressif
	// (iso quarantaine). Couvre aussi le cas `rejected` : un poste rejeté
	// boucle dans le vide (jamais ré-ouvert, 25.3 décision n° 2) — aucune
	// escalade, aucun brick.
	EnrollPending EnrollOutcome = iota
	// EnrollApproved : 200 {token} — une demande approuvée concordante a été
	// consommée côté serveur, le token est dans le corps. Le poste l'écrit
	// (atomique + ACL) et bascule en convergence.
	EnrollApproved
	// EnrollConflict : 409 — la MAC matche un poste DÉJÀ enrôlé (clone /
	// ré-enrôlement potentiel). Log + check-ins légers, JAMAIS de
	// ré-enrôlement automatique silencieux (c'est le serveur qui tranche).
	EnrollConflict
	// EnrollError : erreur réseau / 5xx / réponse inattendue → backoff par
	// l'appelant (jamais de re-tentative immédiate).
	EnrollError
)

// EnrollIdentity : faisceau d'identité de la demande porte 2. uuid/mac/hostname
// ne servent jamais à l'autorisation (le serveur tranche), mais la MAC est
// l'ancre fiable de rapprochement (25.3) : une demande sans MAC est non
// auto-approuvable. N'envoyez JAMAIS une MAC inventée/vide en silence (piège
// n° 5) — collectez-la réellement ou laissez-la vide (le serveur trace mais ne
// rapproche pas).
type EnrollIdentity struct {
	UUID     string `json:"uuid"`
	MAC      string `json:"mac"`
	Hostname string `json:"hostname"`
}

// enrollResponseBody : corps JSON de la réponse d'enrôlement (porte 1 et porte
// 2). Le token vit ici (`{success, token}`), JAMAIS dans l'en-tête de rotation
// (piège n° 3).
type enrollResponseBody struct {
	Success bool   `json:"success"`
	Token   string `json:"token"`
}

// requestEnrollment poste la demande d'enrôlement porte 2 (`POST
// /api/v1/agent/enrollment`, SANS bearer, ticket vide) et mappe la réponse
// serveur vers un EnrollOutcome (Story 25.4, Fork 1 = B).
//
// Mapping figé (contrat serveur 25.3) :
//   - 200 {token} → EnrollApproved + token (le poste l'écrit + bascule
//     convergence) ;
//   - 403         → EnrollPending (check-ins légers, cadence normale) ;
//   - 409         → EnrollConflict (log + check-ins légers, jamais de
//     ré-enrôlement auto) ;
//   - erreur réseau / 5xx / autre / corps 200 sans token → EnrollError
//     (backoff).
//
// Testable sur l'hôte Linux (aucune primitive Windows). serverURL est la base
// (sans slash final) ; l'endpoint est construit iso loop.go.
func requestEnrollment(client *Client, serverURL string, identity EnrollIdentity) (string, EnrollOutcome) {
	body, err := json.Marshal(identity)
	if err != nil {
		// Erreur de programmation (un faisceau de chaînes ne peut pas échouer
		// à marshaler) — défensif : on traite comme une erreur de cycle.
		return "", EnrollError
	}

	resp, err := client.PostNoAuth(serverURL+"/api/v1/agent/enrollment", body)
	if err != nil {
		// Erreur réseau : serveur injoignable → backoff (jamais de spin).
		return "", EnrollError
	}

	switch resp.StatusCode {
	case 200:
		var parsed enrollResponseBody
		if err := json.Unmarshal(resp.Body, &parsed); err != nil || parsed.Token == "" {
			// 200 sans token exploitable : on ne brique pas, on retentera.
			return "", EnrollError
		}

		return parsed.Token, EnrollApproved
	case 403:
		// Demande pending (indistinct) OU poste rejeté : check-ins légers,
		// cadence normale, jamais de backoff agressif ni d'escalade.
		return "", EnrollPending
	case 409:
		// MAC d'un poste déjà enrôlé : conflit tranché par le serveur.
		return "", EnrollConflict
	default:
		// 5xx, 429, 4xx inattendu : backoff.
		return "", EnrollError
	}
}
