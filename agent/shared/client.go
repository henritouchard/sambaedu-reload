package shared

import (
	"bytes"
	"fmt"
	"io"
	"net/http"
	"time"
)

// En-têtes du canal agent (middleware 23.2, FIGÉS).
const (
	headerNewToken      = "X-Agent-New-Token"
	headerAgentHostname = "X-Agent-Hostname"
	headerIfNoneMatch   = "If-None-Match"
	headerETag          = "ETag"
)

// Response : réponse HTTP normalisée. Une erreur n'est retournée par Client
// que sur erreur RÉSEAU (DNS, timeout, connexion refusée) → backoff par
// l'appelant ; 304/4xx/5xx sont des réponses normales du point de vue agent.
type Response struct {
	StatusCode int
	Body       []byte
	Header     http.Header
}

// Client : couche HTTP unique de l'agent.
//
// Invariants gérés ICI (décisions 24.2/24.3 REPRISES, pas re-tranchées) :
//   - Bearer token : relu sur disque à chaque cycle par l'appelant
//     (SetToken) ; X-Agent-Hostname (nom COURT) sur CHAQUE appel
//     (anti-clonage 23.2) ; X-Agent-Mac volontairement NON envoyé
//     (multi-NIC → faux positif quarantaine, décision 24.2) ;
//   - rotation D5 : X-Agent-New-Token lu sur TOUTE réponse (GET 200 ET 304,
//     POST même non-200) → écriture ATOMIQUE sur disque, ancien token gardé
//     EN MÉMOIRE pour la fenêtre de grâce (jamais de token.previous sur
//     disque — surface minimale) ;
//   - 401 après rotation → UN réessai avec l'ancien token (grâce) ; puis
//     durcissement deux-acteurs (24.3) : relecture du token sur DISQUE et
//     réessai UNIQUE s'il diffère des tokens déjà essayés ; 401 après tout
//     ça = irrécupérable (l'appelant ARRÊTE — jamais de re-enrôlement auto) ;
//   - TLS via le magasin système (la racine CA interne est déployée par
//     iPXE 23.3) ; timeout explicite 30 s.
type Client struct {
	HTTP     *http.Client
	Store    *Store
	Log      *Logger
	Hostname string // nom COURT (X-Agent-Hostname)

	token         string // token courant du cycle
	previousToken string // fenêtre de grâce D5 (mémoire seulement)
}

// NewClient construit le client avec le timeout du contrat (30 s, iso-24.2).
func NewClient(store *Store, log *Logger, hostname string) *Client {
	return &Client{
		HTTP:     &http.Client{Timeout: 30 * time.Second},
		Store:    store,
		Log:      log,
		Hostname: hostname,
	}
}

// SetToken installe le token du cycle (relu sur disque par l'appelant).
func (c *Client) SetToken(token string) { c.token = token }

// Token expose le token courant (il peut avoir changé pendant le cycle :
// rotation, grâce, adoption disque).
func (c *Client) Token() string { return c.token }

// Get appelle GET url (If-None-Match optionnel, verbatim).
func (c *Client) Get(url, etag string) (*Response, error) {
	headers := map[string]string{}
	if etag != "" {
		headers[headerIfNoneMatch] = etag
	}

	return c.request(http.MethodGet, url, headers, nil)
}

// Post appelle POST url avec un corps JSON.
func (c *Client) Post(url string, body []byte) (*Response, error) {
	return c.request(http.MethodPost, url, map[string]string{"Content-Type": "application/json"}, body)
}

// PostNoAuth appelle POST url avec un corps JSON SANS bearer token ni
// rotation D5 — chemin d'amorçage de la demande d'enrôlement porte 2 (Story
// 25.4, piège n° 3). Le poste n'a pas encore de token : la requête part sans
// en-tête Authorization, et la réponse ne porte jamais de X-Agent-New-Token (le
// token d'enrôlement vit dans le CORPS JSON `{success, token}`, jamais dans
// l'en-tête de rotation). X-Agent-Hostname reste posé (anti-clonage, inoffensif
// hors canal authentifié). Une erreur n'est retournée que sur erreur RÉSEAU.
func (c *Client) PostNoAuth(url string, body []byte) (*Response, error) {
	var reader io.Reader
	if body != nil {
		reader = bytes.NewReader(body)
	}
	req, err := http.NewRequest(http.MethodPost, url, reader)
	if err != nil {
		return nil, fmt.Errorf("construction requête %s %s : %w", http.MethodPost, url, err)
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set(headerAgentHostname, c.Hostname)

	resp, err := c.HTTP.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	raw, err := io.ReadAll(io.LimitReader(resp.Body, 16<<20))
	if err != nil {
		return nil, fmt.Errorf("lecture du corps de réponse : %w", err)
	}

	return &Response{StatusCode: resp.StatusCode, Body: raw, Header: resp.Header}, nil
}

// request : appel + grâce 401 (mémoire puis disque) + rotation D5 sur la
// réponse FINALE retournée.
func (c *Client) request(method, url string, headers map[string]string, body []byte) (*Response, error) {
	tokenUsed := c.token
	resp, err := c.do(method, url, headers, body, tokenUsed)
	if err != nil {
		return nil, err
	}

	hasPrevious := c.previousToken != "" && c.previousToken != tokenUsed

	switch {
	case resp.StatusCode == 401 && hasPrevious:
		// Fenêtre de grâce D5 : UN réessai avec l'ancien token.
		c.logWarning("401 avec le token courant juste après une rotation : nouvel essai avec l'ancien token (fenêtre de grâce D5).")
		resp, err = c.do(method, url, headers, body, c.previousToken)
		if err != nil {
			return nil, err
		}
		if resp.StatusCode != 401 {
			// L'ancien marche encore : adoption pour la suite du cycle. Le
			// serveur ré-émettra un X-Agent-New-Token (rotateFor) traité
			// ci-dessous.
			tokenUsed = c.previousToken
			c.token = c.previousToken
		}
	case resp.StatusCode != 401 && hasPrevious:
		// Le token post-rotation vient d'être accepté : le serveur a fermé
		// la fenêtre de grâce. Purge locale — sinon tout 401 futur, même
		// légitime (révocation), déclencherait un réessai parasite.
		c.previousToken = ""
	}

	// Durcissement deux-acteurs (24.3) : la grâce mémoire n'a rien donné (ou
	// n'existait pas). Si le fichier token a changé entre-temps (rotation
	// reçue par un AUTRE acteur SYSTEM), le 401 n'est PAS irrécupérable :
	// un seul réessai avec le token du disque.
	if resp.StatusCode == 401 {
		diskToken, readErr := c.Store.ReadToken()
		if readErr != nil {
			c.logWarning("401 : relecture du token sur disque impossible (%v) — durcissement deux-acteurs sans effet.", readErr)
		} else if diskToken != tokenUsed && diskToken != c.previousToken {
			c.logWarning("401 mais le token sur disque a changé (rotation par un autre acteur) : réessai UNIQUE avec le token du disque (durcissement deux-acteurs).")
			resp, err = c.do(method, url, headers, body, diskToken)
			if err != nil {
				return nil, err
			}
			if resp.StatusCode != 401 {
				tokenUsed = diskToken
				c.token = diskToken
				c.previousToken = ""
				c.logInfo("Réessai avec le token du disque accepté : rotation concurrente rattrapée, poursuite normale.")
			} else {
				c.logError("401 aussi avec le token relu sur disque : authentification irrécupérable.")
			}
		}
	}

	c.applyRotation(resp, tokenUsed)

	return resp, nil
}

// applyRotation : invariant D5 — X-Agent-New-Token lu sur TOUTE réponse
// (GET 200/304, POST même non-200). Écriture atomique sur disque, ancien
// token gardé en mémoire pour la fenêtre de grâce.
func (c *Client) applyRotation(resp *Response, tokenUsed string) {
	newToken := resp.Header.Get(headerNewToken)
	if newToken == "" || newToken == tokenUsed {
		return
	}

	if err := c.Store.WriteToken(newToken); err != nil {
		// Token reçu mais non persistable : on continue avec l'ancien (il
		// reste valide pendant la fenêtre de grâce serveur) et on loggue —
		// jamais de crash de cycle pour une rotation.
		c.logError("Rotation token reçue mais écriture sur disque en échec : %v", err)

		return
	}

	c.previousToken = tokenUsed
	c.token = newToken
	c.logInfo("Rotation token reçue (X-Agent-New-Token) : nouveau token écrit sur disque, ancien gardé pour la fenêtre de grâce.")
}

func (c *Client) do(method, url string, headers map[string]string, body []byte, token string) (*Response, error) {
	var reader io.Reader
	if body != nil {
		reader = bytes.NewReader(body)
	}
	req, err := http.NewRequest(method, url, reader)
	if err != nil {
		return nil, fmt.Errorf("construction requête %s %s : %w", method, url, err)
	}
	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Accept", "application/json")
	req.Header.Set(headerAgentHostname, c.Hostname)
	for k, v := range headers {
		req.Header.Set(k, v)
	}

	resp, err := c.HTTP.Do(req)
	if err != nil {
		// Vraie erreur réseau (DNS, timeout, connexion refusée) → backoff.
		return nil, err
	}
	defer resp.Body.Close()

	// Corps borné (le serveur SE5 émet des enveloppes de quelques Ko ; un
	// corps anormalement énorme ne doit pas épuiser la mémoire du poste).
	raw, err := io.ReadAll(io.LimitReader(resp.Body, 16<<20))
	if err != nil {
		return nil, fmt.Errorf("lecture du corps de réponse : %w", err)
	}

	return &Response{StatusCode: resp.StatusCode, Body: raw, Header: resp.Header}, nil
}

func (c *Client) logInfo(format string, args ...any) {
	if c.Log != nil {
		c.Log.Infof(format, args...)
	}
}

func (c *Client) logWarning(format string, args ...any) {
	if c.Log != nil {
		c.Log.Warningf(format, args...)
	}
}

func (c *Client) logError(format string, args ...any) {
	if c.Log != nil {
		c.Log.Errorf(format, args...)
	}
}
