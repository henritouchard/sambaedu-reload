package main

import (
	"fmt"
	"os"
	"strings"
	"unsafe"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"
	"golang.org/x/text/unicode/norm"

	"sambaedu/agent/shared"
)

// Handler `wallpaper` (exclusive / default / session) — Story 24.6, portage
// de handlers/Wallpaper.ps1 (24.4). Exécuté par le COMPAGNON (droits user —
// le wallpaper Windows est per-user : HKCU + SystemParametersInfo). Le
// handler ne contient QUE le test/apply spécifique OS : la machine d'états
// §5 vit dans le moteur (shared/engine.go), la résolution de l'asset dans
// shared/handler_wallpaper.go (testée hôte).
//
//   - test  : HKCU\Control Panel\Desktop\WallPaper pointe-t-il vers
//     assets\<filename> attendu ? Comparaison CASE-INSENSITIVE (sémantique
//     chemins Windows) + normalisation NFC (piège n° 9 — les filenames sont
//     hex ASCII mais la valeur registre peut venir d'ailleurs) ;
//   - apply : valeurs registre (style `fill` : WallpaperStyle=10,
//     TileWallpaper=0) + SystemParametersInfoW(SPI_SETDESKWALLPAPER,
//     UPDATEINIFILE|SENDCHANGE) en FFI Win32 SANS cgo (AC epic — user32.dll
//     via NewLazySystemDLL, jamais de shell-out ici). IDEMPOTENT : mêmes
//     écritures = même état, rejouable sans effet cumulatif.
//
// Cas du contrat :
//   - `asset: null` = règle EXPLICITE « pas de fond imposé » (contrat §8) :
//     le handler NE TOUCHE PAS au fond → test conforme → compliant. Distinct
//     du type absent (aucun statut émis — géré par le moteur, jamais ici) ;
//   - asset pas encore téléchargé (course avec SyncWallpaperAssets côté
//     SYSTEM) : apply échoue avec un detail explicite → error rapporté,
//     résorbé au passage suivant (le download est fait au cycle/logon).
//
// Le téléchargement n'est JAMAIS fait ici : le compagnon n'a ni réseau ni
// token (frontière 24.3) — le cache d'assets est alimenté par SYSTEM
// (SHA-256 vérifié), lisible user (ACL Users:R à la création).

const wallpaperRegistryKey = `Control Panel\Desktop`

// SPI_SETDESKWALLPAPER = 20 ; SPIF_UPDATEINIFILE (1) | SPIF_SENDCHANGE (2) = 3.
const (
	spiSetDeskWallpaper = 20
	spifUpdateAndNotify = 3
)

var (
	modUser32                = windows.NewLazySystemDLL("user32.dll")
	procSystemParametersInfo = modUser32.NewProc("SystemParametersInfoW")
)

type wallpaperHandler struct {
	// AssetsDir : cache d'assets partagé, alimenté par SYSTEM.
	AssetsDir string
}

// targetPath : chemin local attendu, ou "" si « pas de fond imposé ».
func (h *wallpaperHandler) targetPath(items []shared.StateItem) (string, bool, error) {
	filename, imposed, err := shared.ResolveWallpaperAsset(items)
	if err != nil || !imposed {
		return "", imposed, err
	}

	return h.AssetsDir + `\` + filename, true, nil
}

// Test : le fond courant de la session correspond-il à la cible ?
func (h *wallpaperHandler) Test(items []shared.StateItem) (bool, error) {
	target, imposed, err := h.targetPath(items)
	if err != nil {
		return false, err
	}
	if !imposed {
		// asset null : on ne touche pas, on rapporte compliant (contrat §8).
		return true, nil
	}

	key, err := registry.OpenKey(registry.CURRENT_USER, wallpaperRegistryKey, registry.QUERY_VALUE)
	if err != nil {
		return false, nil // clé absente : non conforme, apply écrira
	}
	defer key.Close()

	current, _, err := key.GetStringValue("WallPaper")
	if err != nil || current == "" {
		return false, nil
	}

	// NFC (piège n° 9) + case-insensitive (sémantique chemins Windows).
	return strings.EqualFold(norm.NFC.String(current), norm.NFC.String(target)), nil
}

// Apply : registre (valeur + style fill) puis rafraîchissement
// SystemParametersInfoW. Idempotent.
func (h *wallpaperHandler) Apply(items []shared.StateItem) error {
	target, imposed, err := h.targetPath(items)
	if err != nil {
		return err
	}
	if !imposed {
		return nil // asset null : no-op (jamais d'effacement du fond courant)
	}

	if _, err := os.Stat(target); err != nil {
		// Course avec le download SYSTEM : error explicite, résorbée au
		// passage suivant (detail obligatoire sur error, contrat §6).
		return fmt.Errorf("asset wallpaper absent du cache local (%s) : téléchargement SYSTEM pas encore passé, nouvel essai au prochain passage", target)
	}

	key, err := registry.OpenKey(registry.CURRENT_USER, wallpaperRegistryKey, registry.SET_VALUE)
	if err != nil {
		return fmt.Errorf("ouverture de HKCU\\%s : %w", wallpaperRegistryKey, err)
	}
	defer key.Close()

	// Style `fill` (décision 24.4 n° 8) : WallpaperStyle=10, TileWallpaper=0.
	for name, value := range map[string]string{
		"WallpaperStyle": "10",
		"TileWallpaper":  "0",
		"WallPaper":      target,
	} {
		if err := key.SetStringValue(name, value); err != nil {
			return fmt.Errorf("écriture registre %s : %w", name, err)
		}
	}

	targetPtr, err := windows.UTF16PtrFromString(target)
	if err != nil {
		return fmt.Errorf("conversion UTF-16 du chemin : %w", err)
	}
	r1, _, lastErr := procSystemParametersInfo.Call(
		spiSetDeskWallpaper, 0, uintptr(unsafe.Pointer(targetPtr)), spifUpdateAndNotify)
	if r1 == 0 {
		return fmt.Errorf("SystemParametersInfo(SPI_SETDESKWALLPAPER) en échec (%v) : fond non rafraîchi", lastErr)
	}

	return nil
}
