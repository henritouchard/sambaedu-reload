package main

import (
	"fmt"
	"os"
	"strings"

	"golang.org/x/sys/windows/registry"
	"golang.org/x/text/unicode/norm"

	"sambaedu/agent/shared"
)

// Handler `lockscreen` (exclusive / machine) — fond de l'écran de VERROUILLAGE.
// Exécuté par le SERVICE SYSTEM (le verrouillage est PRÉ-login : LogonUI tourne
// en SYSTEM, aucune session ouverte ; l'image se pose machine-wide via le CSP
// de personnalisation, qui exige les droits SYSTEM — le compagnon user prendrait
// ACCESS_DENIED). Pendant machine du handler `wallpaper` (session / HKCU).
//
// Mécanisme : HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\PersonalizationCSP
// (PersonalizationCSP, iso politique MDM LockScreenImage) :
//   - LockScreenImageStatus (REG_DWORD) = 1 : le CSP impose l'image ;
//   - LockScreenImagePath / LockScreenImageUrl (REG_SZ) = chemin LOCAL de
//     l'asset (LogonUI lit le fichier en SYSTEM — le cache d'assets est
//     SYSTEM-readable). Les deux valeurs portent le même chemin (le CSP exige
//     un "url" non vide ; un chemin disque local est accepté).
//
//   - test  : LockScreenImagePath pointe-t-il vers assets\<filename> attendu ?
//     Comparaison CASE-INSENSITIVE (sémantique chemins Windows) + NFC ;
//   - apply : (ré)écriture des trois valeurs. IDEMPOTENT.
//
// Cas du contrat :
//   - `asset: null` = règle EXPLICITE « pas de fond de verrouillage imposé »
//     (contrat §8) : le handler NE TOUCHE PAS au CSP → test conforme →
//     compliant. Distinct du type absent (aucun statut émis) ;
//   - asset pas encore téléchargé (course avec SyncWallpaperAssets — même
//     cache, alimenté côté SYSTEM) : apply échoue avec un detail explicite →
//     error rapporté, résorbé au passage suivant.
//
// La résolution du filename attendu + la décision `asset: null` sont
// MUTUALISÉES avec le handler wallpaper (shared.ResolveWallpaperAsset, payload
// `{asset, checksum}` identique, testée hôte).

const lockscreenRegistryKey = `SOFTWARE\Microsoft\Windows\CurrentVersion\PersonalizationCSP`

type lockscreenHandler struct {
	// AssetsDir : cache d'assets partagé, alimenté par SYSTEM (iso wallpaper).
	AssetsDir string
}

// targetPath : chemin local attendu, ou "" si « pas de fond imposé ».
func (h *lockscreenHandler) targetPath(items []shared.StateItem) (string, bool, error) {
	filename, imposed, err := shared.ResolveWallpaperAsset(items)
	if err != nil || !imposed {
		return "", imposed, err
	}

	return h.AssetsDir + `\` + filename, true, nil
}

// Test : l'image de verrouillage imposée par le CSP correspond-elle à la cible ?
func (h *lockscreenHandler) Test(items []shared.StateItem) (bool, error) {
	target, imposed, err := h.targetPath(items)
	if err != nil {
		return false, err
	}
	if !imposed {
		// asset null : on ne touche pas, on rapporte compliant (contrat §8).
		return true, nil
	}

	key, err := registry.OpenKey(registry.LOCAL_MACHINE, lockscreenRegistryKey, registry.QUERY_VALUE)
	if err != nil {
		return false, nil // clé absente : non conforme, apply écrira
	}
	defer key.Close()

	current, _, err := key.GetStringValue("LockScreenImagePath")
	if err != nil || current == "" {
		return false, nil
	}

	// NFC + case-insensitive (sémantique chemins Windows), iso handler wallpaper.
	return strings.EqualFold(norm.NFC.String(current), norm.NFC.String(target)), nil
}

// Apply : (ré)écriture des trois valeurs PersonalizationCSP. Idempotent.
func (h *lockscreenHandler) Apply(items []shared.StateItem) error {
	target, imposed, err := h.targetPath(items)
	if err != nil {
		return err
	}
	if !imposed {
		return nil // asset null : no-op (jamais d'effacement du fond courant)
	}

	if _, err := os.Stat(target); err != nil {
		// Course avec le download SYSTEM (même cache que wallpaper) : error
		// explicite, résorbée au passage suivant (detail obligatoire, §6).
		return fmt.Errorf("asset lockscreen absent du cache local (%s) : téléchargement SYSTEM pas encore passé, nouvel essai au prochain passage", target)
	}

	key, _, err := registry.CreateKey(registry.LOCAL_MACHINE, lockscreenRegistryKey, registry.SET_VALUE)
	if err != nil {
		return fmt.Errorf("création/ouverture de HKLM\\%s : %w", lockscreenRegistryKey, err)
	}
	defer key.Close()

	if err := key.SetDWordValue("LockScreenImageStatus", 1); err != nil {
		return fmt.Errorf("écriture registre LockScreenImageStatus : %w", err)
	}
	for _, name := range []string{"LockScreenImagePath", "LockScreenImageUrl"} {
		if err := key.SetStringValue(name, target); err != nil {
			return fmt.Errorf("écriture registre %s : %w", name, err)
		}
	}

	return nil
}
