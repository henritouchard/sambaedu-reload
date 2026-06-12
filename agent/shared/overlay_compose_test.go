package shared

import (
	"bytes"
	"os"
	"strings"
	"testing"
)

func overlayIdentityItem(fullname, login, room string) StateItem {
	return StateItem{Type: "overlay", Semantics: "aggregate", Mode: "strict", Hash: "h-id",
		Payload: map[string]any{"kind": "identity", "fullname": fullname, "login": login, "room": room}}
}

func overlayAlertItem(severity, title, text string) StateItem {
	return StateItem{Type: "overlay", Semantics: "aggregate", Mode: "strict", Hash: "h-al",
		Payload: map[string]any{"kind": "signal", "severity": severity, "title": title, "text": text}}
}

// Golden byte-compatible 24.4 : le document ci-dessous reproduit À
// L'IDENTIQUE la sortie de Build-OverlayDocument (handlers/Overlay.ps1,
// spike 24.4) pour le même payload — transcription ligne à ligne du
// sérialiseur PS (structure littérale, `": "` simple, UTF-8 brut, \n,
// pas de \n final). Tout octet compte : le `test` du handler est une
// comparaison de contenu (drift perpétuel sinon).
func TestComposeOverlayDocumentGoldenByteCompatible(t *testing.T) {
	raw, err := os.ReadFile("testdata/overlay.golden.json")
	if err != nil {
		t.Fatal(err)
	}
	// Le sérialiseur n'émet PAS de \n final (jointure de lignes) — le
	// fichier golden peut en porter un (convention POSIX des éditeurs).
	want := string(bytes.TrimSuffix(raw, []byte("\n")))

	items := []StateItem{
		overlayIdentityItem("Jean Döe", "jdoe", "Salle B-12"),
		// Sanitize iso OverlayService::sanitizeText : retours ligne /
		// espaces multiples → UN espace.
		overlayAlertItem("warning", "Maintenance  réseau", "Coupure\nprévue à 18h"),
		// Guillemet échappé \" (JSON valide — caveat regex render documenté).
		overlayAlertItem("info", `Atelier "Théâtre"`, "Salle des fêtes"),
	}

	got := ComposeOverlayDocument(items, "SALLE101-PC03")
	if got != want {
		t.Errorf("document non byte-compatible avec le sérialiseur PS 24.4 :\n--- got ---\n%s\n--- want ---\n%s", got, want)
	}
}

func TestComposeOverlayDocumentMachineOnlyKeysAlwaysPresent(t *testing.T) {
	// Champs absents (machine-only sans identity) = chaînes vides, JAMAIS
	// omis : la regex du render exige la présence des clés.
	got := ComposeOverlayDocument(nil, "PC")

	want := strings.Join([]string{
		"{",
		`    "schema": "se5.wallpaper-overlay/v1",`,
		`    "identity": {`,
		`        "fullname": "",`,
		`        "login": ""`,
		"    },",
		`    "machine": {`,
		`        "name": "PC",`,
		`        "room": ""`,
		"    },",
		`    "alerts": []`,
		"}",
	}, "\n")
	if got != want {
		t.Errorf("document machine-only :\n--- got ---\n%s\n--- want ---\n%s", got, want)
	}
}

func TestComposeOverlayDocumentFirstIdentityWins(t *testing.T) {
	// Un seul bloc identité (le serveur n'en émet qu'un — défense : le
	// PREMIER gagne, ordre serveur).
	items := []StateItem{
		overlayIdentityItem("Premier", "p", "R1"),
		overlayIdentityItem("Second", "s", "R2"),
	}
	got := ComposeOverlayDocument(items, "PC")
	if !strings.Contains(got, `"fullname": "Premier"`) || strings.Contains(got, "Second") {
		t.Errorf("le premier bloc identity doit gagner :\n%s", got)
	}
}

func TestComposeOverlayDocumentSanitizeAndClamp(t *testing.T) {
	long := strings.Repeat("é", 300)
	items := []StateItem{
		overlayAlertItem("", "  a \t b  ", long),
	}
	got := ComposeOverlayDocument(items, "PC")

	if !strings.Contains(got, `"severity": "info"`) {
		t.Error("severity vide → défaut info")
	}
	if !strings.Contains(got, `"title": "a b"`) {
		t.Errorf("aplatissement + trim attendu :\n%s", got)
	}
	// text clampé à 2000 runes — ici 300 < 2000 : intact, mais title à 255 :
	longTitle := strings.Repeat("x", 300)
	got = ComposeOverlayDocument([]StateItem{overlayAlertItem("info", longTitle, "t")}, "PC")
	if !strings.Contains(got, `"title": "`+strings.Repeat("x", 255)+`"`) || strings.Contains(got, strings.Repeat("x", 256)) {
		t.Error("title clampé à 255")
	}
}

func TestComposeOverlayDocumentEscaping(t *testing.T) {
	items := []StateItem{
		overlayAlertItem("info", `back\slash`, "ctrl\x01char"),
	}
	got := ComposeOverlayDocument(items, "PC")

	if !strings.Contains(got, `"title": "back\\slash"`) {
		t.Errorf("backslash échappé attendu :\n%s", got)
	}
	if !strings.Contains(got, `"text": "ctrl char"`) {
		t.Errorf("contrôle résiduel → espace attendu :\n%s", got)
	}
}

func TestComposeOverlayDocumentNoVolatileField(t *testing.T) {
	// AUCUN champ volatil (generated_at…) : un champ horodaté ferait
	// dériver chaque passe. Deux compositions = mêmes octets.
	items := []StateItem{overlayIdentityItem("X", "x", "R")}
	if ComposeOverlayDocument(items, "PC") != ComposeOverlayDocument(items, "PC") {
		t.Error("composition non déterministe")
	}
	if strings.Contains(ComposeOverlayDocument(items, "PC"), "generated_at") {
		t.Error("champ volatil interdit dans le document")
	}
}

// --- Handler overlay (test/apply, Rainmeter gracieux) -------------------------------

func TestOverlayHandlerTestApplyLifecycle(t *testing.T) {
	dir := t.TempDir()
	h := &OverlayHandler{Path: dir + "/overlay.json", ComputerName: "PC"}
	items := []StateItem{overlayIdentityItem("Jean", "jdoe", "B12")}

	// Fichier absent → non conforme.
	if ok, err := h.Test(items); err != nil || ok {
		t.Fatalf("fichier absent = non conforme : %v %v", ok, err)
	}

	// Apply → conforme.
	if err := h.Apply(items); err != nil {
		t.Fatal(err)
	}
	if ok, _ := h.Test(items); !ok {
		t.Fatal("conforme après apply attendu")
	}

	// Divergence (contenu modifié à la main) → non conforme, apply réécrit
	// (mode strict : le moteur rapporte drift).
	if err := os.WriteFile(h.Path, []byte("{}"), 0o600); err != nil {
		t.Fatal(err)
	}
	if ok, _ := h.Test(items); ok {
		t.Fatal("divergence détectée attendue")
	}
	if err := h.Apply(items); err != nil {
		t.Fatal(err)
	}
	if ok, _ := h.Test(items); !ok {
		t.Fatal("reconvergé attendu")
	}
}

func TestOverlayHandlerNFCComparison(t *testing.T) {
	dir := t.TempDir()
	h := &OverlayHandler{Path: dir + "/overlay.json", ComputerName: "PC"}
	items := []StateItem{overlayIdentityItem("Jean Döe", "jdoe", "B12")}

	if err := h.Apply(items); err != nil {
		t.Fatal(err)
	}
	// Réécrit le fichier en NFD (ö → o + U+0308) : le contenu reste
	// ÉQUIVALENT après normalisation NFC → conforme (piège n° 9).
	raw, _ := os.ReadFile(h.Path)
	nfd := strings.ReplaceAll(string(raw), "ö", "ö")
	if nfd == string(raw) {
		t.Fatal("le document devrait contenir ö")
	}
	if err := os.WriteFile(h.Path, []byte(nfd), 0o600); err != nil {
		t.Fatal(err)
	}

	if ok, err := h.Test(items); err != nil || !ok {
		t.Errorf("comparaison NFC : un fichier NFD équivalent est conforme (%v, %v)", ok, err)
	}
}

func TestOverlayHandlerRainmeterAbsentGraceful(t *testing.T) {
	// Rainmeter absent → le handler écrit QUAND MÊME (config convergée,
	// statut normal, jamais error de ce seul fait) + log info.
	dir := t.TempDir()
	h := &OverlayHandler{Path: dir + "/overlay.json", ComputerName: "PC",
		RainmeterPresent: func() bool { return false }}

	if err := h.Apply([]StateItem{overlayIdentityItem("X", "x", "R")}); err != nil {
		t.Fatalf("Rainmeter absent ne doit JAMAIS être une erreur : %v", err)
	}
	if _, err := os.Stat(h.Path); err != nil {
		t.Error("overlay.json doit être écrit même sans Rainmeter")
	}
}

// --- Logique pure wallpaper -----------------------------------------------------------

func TestResolveWallpaperAsset(t *testing.T) {
	valid := strings.Repeat("a", 64) + ".jpg"

	item := func(payload any) StateItem {
		return StateItem{Type: "wallpaper", Hash: "h", Payload: payload}
	}

	// asset valide → imposé.
	name, imposed, err := ResolveWallpaperAsset([]StateItem{item(map[string]any{"asset": valid, "checksum": strings.Repeat("a", 64)})})
	if err != nil || !imposed || name != valid {
		t.Errorf("asset valide : %q %v %v", name, imposed, err)
	}

	// asset: null → règle explicite « pas de fond imposé » (no-op compliant).
	name, imposed, err = ResolveWallpaperAsset([]StateItem{item(map[string]any{"asset": nil})})
	if err != nil || imposed || name != "" {
		t.Errorf("asset null : %q %v %v", name, imposed, err)
	}

	// payload sans champ asset → erreur (enveloppe inattendue).
	if _, _, err = ResolveWallpaperAsset([]StateItem{item(map[string]any{})}); err == nil {
		t.Error("payload sans asset : erreur attendue")
	}

	// format hors content-addressed → erreur (jamais de traversal).
	if _, _, err = ResolveWallpaperAsset([]StateItem{item(map[string]any{"asset": "../../evil.jpg"})}); err == nil {
		t.Error("traversal : erreur attendue")
	}
	if _, _, err = ResolveWallpaperAsset([]StateItem{item(map[string]any{"asset": "fonds/ecole-2026.jpg"})}); err == nil {
		t.Error("format legacy non content-addressed : erreur attendue")
	}

	// multi-items exclusive : le DERNIER fait foi.
	other := strings.Repeat("b", 64) + ".png"
	name, _, _ = ResolveWallpaperAsset([]StateItem{
		item(map[string]any{"asset": valid}),
		item(map[string]any{"asset": other}),
	})
	if name != other {
		t.Errorf("le dernier item fait foi : got %q", name)
	}

	// aucun item → erreur.
	if _, _, err = ResolveWallpaperAsset(nil); err == nil {
		t.Error("aucun item : erreur attendue")
	}
}
