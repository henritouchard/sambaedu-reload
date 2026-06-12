package shared

import (
	"fmt"
	"regexp"
)

// Logique PURE du handler `wallpaper` (Story 24.6) — résolution du filename
// attendu et décision `asset: null`, factorisée ici pour être testée sur
// l'hôte. Le test/apply spécifique OS (registre HKCU, SystemParametersInfo)
// vit dans agent/windows/handler_wallpaper_windows.go.

// wallpaperAssetPattern : format content-addressed des assets de la biblio
// (iso route serveur agent.v1.assets.wallpaper — `^[0-9a-f]{64}\.[a-z0-9]{2,5}$`).
var wallpaperAssetPattern = regexp.MustCompile(`^[0-9a-f]{64}\.[a-z0-9]{2,5}$`)

// checksumPattern : SHA-256 hex (payload.checksum, hash de rapport, drops).
var checksumPattern = regexp.MustCompile(`^[0-9a-f]{64}$`)

// ValidWallpaperAssetFilename valide un filename d'asset AVANT tout usage en
// chemin : un payload serveur (ou un cache d'état sur disque) reste une
// entrée externe — jamais de traversal depuis le cache d'assets.
func ValidWallpaperAssetFilename(filename string) bool {
	return wallpaperAssetPattern.MatchString(filename)
}

// ValidChecksum valide un SHA-256 hex minuscule.
func ValidChecksum(checksum string) bool {
	return checksumPattern.MatchString(checksum)
}

// ResolveWallpaperAsset extrait le filename d'asset cible des items
// wallpaper d'une passe. Sémantique exclusive (§3.1) : le DERNIER item fait
// foi (le moteur a déjà loggé l'anomalie multi-items).
//
// Retours :
//   - filename vide + imposed=false : règle EXPLICITE « pas de fond imposé »
//     (`asset: null`, contrat §8) — no-op compliant, jamais d'effacement du
//     fond courant ;
//   - filename non vide + imposed=true : asset attendu dans le cache local ;
//   - err : enveloppe inattendue (payload sans champ asset, format hors
//     content-addressed) → le moteur rapporte error.
func ResolveWallpaperAsset(items []StateItem) (filename string, imposed bool, err error) {
	if len(items) == 0 {
		return "", false, fmt.Errorf("aucun item wallpaper dans la passe : enveloppe inattendue")
	}

	payload, ok := items[len(items)-1].Payload.(map[string]any)
	if !ok || payload == nil {
		return "", false, fmt.Errorf("payload wallpaper absent : enveloppe inattendue")
	}
	asset, present := payload["asset"]
	if !present {
		return "", false, fmt.Errorf("payload wallpaper sans champ asset : enveloppe inattendue")
	}
	name, _ := asset.(string)
	if asset == nil || name == "" {
		return "", false, nil // règle explicite « pas de fond imposé » (contrat §8)
	}

	if !ValidWallpaperAssetFilename(name) {
		return "", false, fmt.Errorf("filename d'asset wallpaper inattendu (%q) : format content-addressed requis", name)
	}

	return name, true, nil
}
