package shared

import (
	"crypto/sha256"
	"encoding/hex"
	"io"
	"os"
	"strings"
)

// Sync des assets wallpaper côté SYSTEM (Story 24.6 — portage de
// Sync-WallpaperAssets 24.4). Les handlers de scope `session` tournent dans
// le COMPAGNON (droits user, ni réseau ni token — partition 24.3) : le
// service SYSTEM pré-télécharge donc les assets référencés par les états en
// cache vers assets\<filename> (lisible user, ACL Users:R à la création).
//
// TRANSPORT STATIQUE (calque Story 27.7, iso icon_assets.go) : le fond d'écran
// est servi EN DIRECT par Apache via l'Alias `/assets/wallpaper/<sha256>.<ext>`
// — un GET HTTP SIMPLE (PAS le Client token'd) qui ne traverse plus PHP-FPM
// (images de plusieurs centaines de Ko à plusieurs Mo). Un asset de la biblio
// est un blob public-safe (content-addressed `<checksum>.<ext>`) ; le
// content-addressing + la vérif SHA-256 AVANT écriture SONT la garantie
// d'intégrité (un contenu divergent n'entre JAMAIS dans le cache). Le token
// serait du sur-engineering. La route Laravel token'd `agent.v1.assets.wallpaper`
// reste vivante le temps du rollout (postes en ancien agent), retrait ultérieur.
//
// Décision 24.4 n° 2 (figée) : PAS de champ `url` au payload — l'agent DÉRIVE
// l'URL depuis server_url + le chemin statique connu (WallpaperRoute).
//
//   - content-addressed : un fichier présent porte déjà le bon contenu (son
//     nom EST son checksum) — jamais re-téléchargé, sync idempotent ;
//   - SHA-256 vérifié = payload.checksum AVANT toute écriture : un contenu
//     divergent n'entre JAMAIS dans le cache (log + retry au prochain
//     passage) ;
//   - 404 (asset retiré de la biblio entre compilation et download) = log,
//     l'état suivant ne le référencera plus ; pas de purge (iso-24.4, noté) ;
//   - réseau / status inattendu = log + skip, retry au prochain passage ;
//   - le corps de réponse est borné à 16 Mio (LimitReader) : un asset au-delà
//     serait tronqué → checksum divergent → jamais écrit, warning à chaque
//     cycle (borne assumée — la biblio sert des images de fond d'écran, pas
//     des ISO).

// WallpaperRoute : chemin statique de l'Alias Apache (`/assets/wallpaper/`).
// FIGÉ côté agent — l'URL est dérivée, jamais reçue (décision 24.4 n° 2).
const WallpaperRoute = "/assets/wallpaper/"

// wallpaperAssetMaxBytes : borne du corps téléchargé — la biblio sert des
// images de fond d'écran ; 16 Mio est une marge large (au-delà = tronqué →
// checksum divergent → rejeté).
const wallpaperAssetMaxBytes = 16 << 20 // 16 MiB

// wallpaperAssetRef : un asset voulu {filename, checksum}, déjà validé.
type wallpaperAssetRef struct {
	Filename string
	Checksum string
}

// wantedWallpaperAssets : liste dédupliquée des assets wallpaper référencés
// par les états en cache (machine + toutes les sessions). Filename/checksum
// validés STRICTEMENT avant tout usage (un cache d'état reste un fichier
// disque — jamais de jointure de chemin sur une valeur non validée).
func (a *Agent) wantedWallpaperAssets() []wallpaperAssetRef {
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
	wanted := []wallpaperAssetRef{}
	for _, raw := range statePayloads {
		state, err := ParseState(raw)
		if err != nil {
			a.Log.Warningf("Cache d'état illisible : %v — ignoré pour le sync des assets.", err)

			continue
		}
		scopes := [][]any{state.Machine, state.Session, state.MachineUser}
		for _, scope := range scopes {
			for _, item := range ItemsFromScope(scope, nil) {
				// `lockscreen` partage la même biblio content-addressed et la
				// même route de serving que `wallpaper` : SYSTEM pré-télécharge
				// les deux dans le cache d'assets (le handler lockscreen tourne
				// aussi côté SYSTEM, mais lit le cache plutôt que le réseau).
				if item.Type != "wallpaper" && item.Type != "lockscreen" {
					continue
				}
				payload, ok := item.Payload.(map[string]any)
				if !ok || payload == nil {
					continue
				}
				asset, present := payload["asset"]
				if !present || asset == nil {
					continue // `asset: null` = pas de fond imposé, rien à télécharger
				}
				filename, _ := asset.(string)
				checksum, _ := payload["checksum"].(string)
				if !ValidWallpaperAssetFilename(filename) || !ValidChecksum(checksum) {
					a.Log.Warningf("Item wallpaper avec asset/checksum hors format (%q) : ignoré.", filename)

					continue
				}
				if !seen[filename] {
					seen[filename] = true
					wanted = append(wanted, wallpaperAssetRef{Filename: filename, Checksum: checksum})
				}
			}
		}
	}

	return wanted
}

// SyncWallpaperAssets télécharge les assets wallpaper manquants du cache local
// (côté SYSTEM) via un GET HTTP SIMPLE (sans token) et VÉRIFIE le SHA-256.
// Appelé au cycle du service ET en fin de session-fetch (idempotent :
// content-addressed, un double passage ne re-télécharge rien). Un échec ne
// casse jamais le cycle — rattrapage au prochain passage.
func (a *Agent) SyncWallpaperAssets(cfg Config) {
	if a.quarantined {
		a.Log.Debugf("Quarantaine active : sync des assets sauté.")

		return
	}

	missing := []wallpaperAssetRef{}
	for _, asset := range a.wantedWallpaperAssets() {
		if _, err := os.Stat(a.Store.AssetPath(asset.Filename)); err != nil {
			missing = append(missing, asset)
		}
	}
	if len(missing) == 0 {
		return
	}

	if err := a.Store.EnsureAssetsDir(a.AssetsACL); err != nil {
		a.Log.Warningf("Préparation du cache d'assets en échec : %v", err)

		return
	}

	base := strings.TrimRight(cfg.ServerURL, "/")
	for _, asset := range missing {
		assetURL := base + WallpaperRoute + asset.Filename
		body, status, err := a.getStatic(assetURL, wallpaperAssetMaxBytes)
		if err != nil {
			a.Log.Warningf("Serveur injoignable sur GET asset %s : %v — skip (rattrapage au prochain cycle).", asset.Filename, err)

			continue
		}

		switch status {
		case 200:
			sum := sha256.Sum256(body)
			actual := hex.EncodeToString(sum[:])
			if actual != asset.Checksum {
				// Vérif AVANT écriture : jamais un asset corrompu dans le
				// cache. Retry au prochain passage.
				a.Log.Warningf("Asset %s : SHA-256 téléchargé (%s) != checksum attendu — rejeté, retry au prochain cycle.", asset.Filename, actual)

				continue
			}
			// Écriture atomique tmp PID DANS le répertoire cible : le
			// fichier hérite de l'ACL (Users:R via (OI)) — jamais de ré-ACL.
			if err := WriteFileAtomic(a.Store.AssetPath(asset.Filename), body); err != nil {
				a.Log.Warningf("Écriture de l'asset %s en échec : %v", asset.Filename, err)

				continue
			}
			a.Log.Infof("Asset wallpaper %s téléchargé et vérifié (SHA-256 ok).", asset.Filename)
		case 404:
			a.Log.Warningf("Asset %s inconnu du serveur (404) : retiré de la bibliothèque ? L'état suivant ne le référencera plus.", asset.Filename)
		default:
			a.Log.Warningf("GET asset %s -> %d inattendu : skip (rattrapage au prochain cycle).", asset.Filename, status)
		}
	}
}

// getStatic : GET HTTP SIMPLE (PAS le Client token'd) vers un Alias Apache
// statique, mutualisé par SyncWallpaperAssets et SyncShortcutIcons (Story 27.7).
// Réutilise le *http.Client sous-jacent (timeout/transport) sans la couche
// token/rotation. Corps borné (LimitReader). Retourne (corps, status, err).
func (a *Agent) getStatic(url string, maxBytes int64) ([]byte, int, error) {
	resp, err := a.Client.HTTP.Get(url)
	if err != nil {
		return nil, 0, err
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(io.LimitReader(resp.Body, maxBytes))
	if err != nil {
		return nil, resp.StatusCode, err
	}

	return body, resp.StatusCode, nil
}
