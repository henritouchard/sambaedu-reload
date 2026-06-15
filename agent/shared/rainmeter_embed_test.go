package shared

import (
	"os"
	"path/filepath"
	"testing"
)

// TestEmbeddedSkinMatchesRepoSource garantit la NON-DIVERGENCE (M3) entre la
// skin EMBARQUÉE dans le binaire agent (embedded/SambaEduOverlay.ini, servie via
// rainmeter_embed.go) et la skin CANONIQUE du repo
// (resources/overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini).
//
// go:embed ne peut référencer que des fichiers du dossier du package, d'où la
// copie sous agent/shared/embedded/. La skin canonique du repo reste l'autorité
// (éditée en T6) : ce test échoue dès qu'on touche la canonique SANS recopier la
// copie embarquée (ou l'inverse) — il interdit le drift silencieux qui
// poserait une skin obsolète sur le parc.
func TestEmbeddedSkinMatchesRepoSource(t *testing.T) {
	// Chemin du package = agent/shared ; remonter de deux niveaux atteint la
	// racine du repo (agent/shared -> agent -> racine).
	repoSkin := filepath.Join("..", "..", "resources", "overlay", "rainmeter", "SambaEduOverlay", "SambaEduOverlay.ini")

	want, err := os.ReadFile(repoSkin)
	if err != nil {
		t.Fatalf("lecture de la skin canonique du repo (%s) : %v", repoSkin, err)
	}

	got := []byte(RainmeterSkinSource())

	if string(got) != string(want) {
		t.Fatalf("skin embarquée (embedded/SambaEduOverlay.ini) DIVERGE de la skin canonique du repo (%s).\n"+
			"Toute édition de la skin canonique doit être recopiée dans agent/shared/embedded/ (et inversement).\n"+
			"tailles : embarquée=%d, repo=%d octets.", repoSkin, len(got), len(want))
	}
}
