package shared

import (
	"crypto/sha256"
	"encoding/hex"
	"os"
	"regexp"
	"strings"
)

// Sync des icônes UPLOADÉES de raccourcis content-addressed (Story 27.7) —
// le pendant « icônes » de assets.go (wallpaper), MAIS sur un transport
// DIFFÉRENT (décision n° 1, piège n° 6) :
//
//   - wallpaper : Client token'd (GET /api/v1/agent/assets/wallpaper/…,
//     rotation D5) — un fond peut être réservé à un parc ;
//   - icônes    : GET HTTP SIMPLE (pas de token) sur un Alias Apache statique
//     (/assets/shortcut-icons/<sha>.ico). Un `.ico` de raccourci est un blob
//     public-safe ; le content-addressing + la vérif SHA-256 AVANT écriture
//     SONT la garantie d'intégrité (un contenu divergent n'entre JAMAIS dans
//     le cache). Le token serait du sur-engineering.
//
// Décision n° 4 (figée, iso 24.4) : PAS de champ `url` au payload —  l'agent
// DÉRIVE l'URL depuis server_url + le chemin statique connu (ShortcutIconsRoute).
//
// Invariants partagés avec assets.go :
//   - content-addressed : un fichier présent porte déjà le bon contenu (son
//     nom EST son checksum) → jamais re-téléchargé, sync idempotent ;
//   - SHA-256 vérifié = payload.icon_checksum AVANT écriture ;
//   - 404 / réseau = log + skip, retry au prochain passage ; jamais fatal ;
//   - le corps de réponse est borné (LimitReader) : un blob au-delà serait
//     tronqué → checksum divergent → jamais écrit.
//
// Le download tourne en SYSTEM (sous-décision D, iso wallpaper) : un seul
// endroit touche le réseau, cache content-addressed prêt avant la passe
// compagnon qui pose les `.lnk`.

// ShortcutIconsRoute : chemin statique de l'Alias Apache (config serveur
// shortcut_icons.route_path = 'assets/shortcut-icons'). FIGÉ côté agent —
// l'URL est dérivée, jamais reçue (décision n° 4).
const ShortcutIconsRoute = "/assets/shortcut-icons/"

// shortcutIconMaxBytes : borne du corps téléchargé — une icône `.ico` pèse
// quelques dizaines de Ko ; 4 Mio est une marge large (au-delà = tronqué →
// checksum divergent → rejeté).
const shortcutIconMaxBytes = 4 << 20 // 4 MiB

// shortcutIconAssetPattern : format content-addressed `<sha256>.ico` (iso
// colonne serveur shortcuts.icon_asset).
var shortcutIconAssetPattern = regexp.MustCompile(`^[0-9a-f]{64}\.ico$`)

// ValidShortcutIconFilename valide un filename d'icône AVANT tout usage en
// chemin (jamais de traversal depuis le cache d'icônes — un payload serveur ou
// un cache d'état sur disque reste une entrée externe).
func ValidShortcutIconFilename(filename string) bool {
	return shortcutIconAssetPattern.MatchString(filename)
}

// shortcutIconRef : une icône voulue {filename, checksum}, déjà validée.
type shortcutIconRef struct {
	Filename string
	Checksum string
}

// wantedShortcutIcons : liste dédupliquée des icônes raccourci référencées par
// les états en cache (machine + toutes les sessions). Filename/checksum validés
// STRICTEMENT avant tout usage.
func (a *Agent) wantedShortcutIcons() []shortcutIconRef {
	statePayloads := [][]byte{}
	if raw, err := a.Store.ReadStateCache(); err == nil {
		statePayloads = append(statePayloads, raw)
	}
	if entries, err := os.ReadDir(a.Store.SessionsCacheRoot()); err == nil {
		for _, entry := range entries {
			if !entry.IsDir() {
				continue
			}
			if raw, err := a.Store.ReadSessionStateCache(entry.Name()); err == nil {
				statePayloads = append(statePayloads, raw)
			}
		}
	}

	seen := map[string]bool{}
	wanted := []shortcutIconRef{}
	for _, raw := range statePayloads {
		state, err := ParseState(raw)
		if err != nil {
			a.Log.Warningf("Cache d'état illisible : %v — ignoré pour le sync des icônes.", err)

			continue
		}
		scopes := [][]any{state.Machine, state.Session, state.MachineUser}
		for _, scope := range scopes {
			for _, item := range ItemsFromScope(scope, nil) {
				if item.Type != "shortcuts" {
					continue
				}
				payload, ok := item.Payload.(map[string]any)
				if !ok || payload == nil {
					continue
				}
				asset, present := payload["icon_asset"]
				if !present || asset == nil {
					continue // raccourci sans icône uploadée content-addressed
				}
				filename, _ := asset.(string)
				checksum, _ := payload["icon_checksum"].(string)
				if !ValidShortcutIconFilename(filename) || !ValidChecksum(checksum) {
					a.Log.Warningf("Item shortcuts avec icon_asset/checksum hors format (%q) : ignoré.", filename)

					continue
				}
				if !seen[filename] {
					seen[filename] = true
					wanted = append(wanted, shortcutIconRef{Filename: filename, Checksum: checksum})
				}
			}
		}
	}

	return wanted
}

// SyncShortcutIcons télécharge les icônes raccourci manquantes du cache local
// (côté SYSTEM) via un GET HTTP SIMPLE (sans token) et VÉRIFIE le SHA-256.
// Appelé au cycle du service ET en fin de session-fetch (idempotent :
// content-addressed). Un échec ne casse JAMAIS le cycle — rattrapage au
// prochain passage. Le compagnon pose ensuite l'IconLocation sur le fichier
// local (handler_shortcuts), gracieux si l'icône manque encore.
func (a *Agent) SyncShortcutIcons(cfg Config) {
	if a.quarantined {
		a.Log.Debugf("Quarantaine active : sync des icônes raccourci sauté.")

		return
	}

	missing := []shortcutIconRef{}
	for _, icon := range a.wantedShortcutIcons() {
		if _, err := os.Stat(a.Store.IconPath(icon.Filename)); err != nil {
			missing = append(missing, icon)
		}
	}
	if len(missing) == 0 {
		return
	}

	if err := a.Store.EnsureIconsDir(a.AssetsACL); err != nil {
		a.Log.Warningf("Préparation du cache d'icônes en échec : %v", err)

		return
	}

	base := strings.TrimRight(cfg.ServerURL, "/")
	for _, icon := range missing {
		iconURL := base + ShortcutIconsRoute + icon.Filename
		body, status, err := a.getStatic(iconURL, shortcutIconMaxBytes)
		if err != nil {
			a.Log.Warningf("Serveur injoignable sur GET icône %s : %v — skip (rattrapage au prochain cycle).", icon.Filename, err)

			continue
		}

		switch status {
		case 200:
			sum := sha256.Sum256(body)
			actual := hex.EncodeToString(sum[:])
			if actual != icon.Checksum {
				// Vérif AVANT écriture : jamais une icône corrompue dans le
				// cache. Retry au prochain passage.
				a.Log.Warningf("Icône %s : SHA-256 téléchargé (%s) != checksum attendu — rejetée, retry au prochain cycle.", icon.Filename, actual)

				continue
			}
			// Écriture atomique tmp PID DANS le répertoire cible : le fichier
			// hérite de l'ACL Users:R (jamais de ré-ACL).
			if err := WriteFileAtomic(a.Store.IconPath(icon.Filename), body); err != nil {
				a.Log.Warningf("Écriture de l'icône %s en échec : %v", icon.Filename, err)

				continue
			}
			a.Log.Infof("Icône raccourci %s téléchargée et vérifiée (SHA-256 ok).", icon.Filename)
		case 404:
			a.Log.Warningf("Icône %s inconnue du serveur (404) : retirée ? L'état suivant ne la référencera plus.", icon.Filename)
		default:
			a.Log.Warningf("GET icône %s -> %d inattendu : skip (rattrapage au prochain cycle).", icon.Filename, status)
		}
	}
}
