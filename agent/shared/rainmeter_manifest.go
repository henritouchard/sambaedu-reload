package shared

import (
	"encoding/json"
	"fmt"
	"regexp"
)

// Manifest tool/skin servi à l'agent (Story 25.6, D6/D8b). Endpoint DÉDIÉ
// (GET /api/v1/agent/tools-manifest, token'd) — délibérément HORS du contrat
// desired-state versionné : un outil de rendu n'est pas un StateItem, le golden
// overlay/state reste donc INTOUCHÉ. L'agent y lit l'autorité serveur du hash
// du portable (plus la constante Go figée) et le hash de la skin.
//
// NFR7 : parsing pur, aucune dépendance AD/réseau ici.

// rainmeterToolFilenamePattern : forme STRICTE attendue du filename du portable
// (iso la regex du ToolController serveur `sambaedu-rainmeter-…\.zip`). Le
// manifest est une entrée externe : on revalide AVANT de l'utiliser pour
// dériver une URL de download (défense en profondeur — jamais de jointure
// d'URL/chemin sur une valeur non validée).
var rainmeterToolFilenamePattern = regexp.MustCompile(`^sambaedu-rainmeter-[0-9A-Za-z.+~-]+\.zip$`)

// sha256HexPattern : SHA-256 hex minuscule (64 caractères).
var sha256HexPattern = regexp.MustCompile(`^[0-9a-f]{64}$`)

// rainmeterToolEntry : l'outil ACTIF du manifest (nil si absent/désactivé).
type rainmeterToolEntry struct {
	Key      string `json:"key"`
	Filename string `json:"filename"`
	SHA256   string `json:"sha256"`
	Size     int64  `json:"size"`
}

// rainmeterSkinEntry : la skin servie du manifest (nil si introuvable serveur).
type rainmeterSkinEntry struct {
	Filename string `json:"filename"`
	SHA256   string `json:"sha256"`
}

// rainmeterManifest : enveloppe du manifest tool/skin (wrapper SE5
// `{success, tool, skin}`).
type rainmeterManifest struct {
	Success bool                `json:"success"`
	Tool    *rainmeterToolEntry `json:"tool"`
	Skin    *rainmeterSkinEntry `json:"skin"`
}

// ParseRainmeterManifest décode le corps JSON du manifest tool/skin et VALIDE
// strictement les champs utilisés en chemin/URL/intégrité. Les entrées
// malformées (filename hors pattern, hash non-hex) sont traitées comme ABSENTES
// (nil) — jamais une erreur fatale : un manifest partiellement servi laisse le
// provisioning en no-op gracieux plutôt que de casser le cycle.
func ParseRainmeterManifest(body []byte) (*rainmeterManifest, error) {
	var m rainmeterManifest
	if err := json.Unmarshal(body, &m); err != nil {
		return nil, fmt.Errorf("manifest tool/skin illisible : %w", err)
	}

	// Tool : nil si filename/hash non conformes (jamais utilisé pour dériver
	// une URL ou comparer un hash sans validation préalable).
	if m.Tool != nil {
		if !rainmeterToolFilenamePattern.MatchString(m.Tool.Filename) || !sha256HexPattern.MatchString(m.Tool.SHA256) {
			m.Tool = nil
		}
	}
	// Skin : nil si hash non conforme (le filename est fixe côté serveur ;
	// l'URL de la skin est dérivée d'une route FIGÉE, pas du filename).
	if m.Skin != nil {
		if !sha256HexPattern.MatchString(m.Skin.SHA256) {
			m.Skin = nil
		}
	}

	return &m, nil
}
