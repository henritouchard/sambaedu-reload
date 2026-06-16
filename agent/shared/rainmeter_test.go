package shared

import (
	"archive/zip"
	"bytes"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"golang.org/x/text/encoding/unicode"
	"golang.org/x/text/transform"
)

// --- Conversion UTF-16 LE + BOM (D6) ------------------------------------------

func TestToUTF16LEWithBOM_BOMAndRoundTrip(t *testing.T) {
	src := "Salle B-12 · élève éàü"

	out, err := ToUTF16LEWithBOM(src)
	if err != nil {
		t.Fatal(err)
	}

	// BOM UTF-16 LE = FF FE en tête (sinon mojibake `Â·`).
	if len(out) < 2 || out[0] != 0xFF || out[1] != 0xFE {
		t.Fatalf("BOM FF FE attendu en tête, got % x", out[:min(2, len(out))])
	}

	// Round-trip : décodage UTF-16 LE (avec BOM) → la source UTF-8 d'origine.
	dec := unicode.UTF16(unicode.LittleEndian, unicode.UseBOM).NewDecoder()
	back, _, err := transform.Bytes(dec, out)
	if err != nil {
		t.Fatal(err)
	}
	if string(back) != src {
		t.Fatalf("round-trip cassé :\n--- got ---\n%q\n--- want ---\n%q", string(back), src)
	}
}

func TestToUTF16LEWithBOM_Deterministic(t *testing.T) {
	// Idempotence : deux conversions de la même source = mêmes octets (la
	// pose ne réécrit que si divergence — pattern test/apply).
	src := "déterministe"
	a, _ := ToUTF16LEWithBOM(src)
	b, _ := ToUTF16LEWithBOM(src)
	if !bytes.Equal(a, b) {
		t.Fatal("conversion non déterministe")
	}
}

func TestToUTF16LEWithBOM_NonASCIIEncodedNotMojibake(t *testing.T) {
	// Le `·` (U+00B7) doit s'encoder en UTF-16 (B7 00), PAS en sa double-octet
	// UTF-8 mal interprétée (`Â·` = C2 B7). On vérifie la présence de B7 00.
	out, _ := ToUTF16LEWithBOM("·")
	// out = BOM(FF FE) + B7 00
	if !bytes.Contains(out, []byte{0xB7, 0x00}) {
		t.Fatalf("U+00B7 mal encodé (mojibake ?) : % x", out)
	}
	if bytes.Contains(out[2:], []byte{0xC2}) {
		t.Fatalf("octet UTF-8 0xC2 résiduel = mojibake : % x", out)
	}
}

// --- Rainmeter.ini durci (D4) -------------------------------------------------

func TestBuildHardenedRainmeterIni_LockdownDirectives(t *testing.T) {
	ini := BuildHardenedRainmeterIni()

	mustContain := []string{
		"TrayIcon=0",                         // pas d'icône de tray pilotable
		"Draggable=0",                        // non déplaçable
		"ClickThrough=1",                     // clics traversent
		"KeepOnScreen=1",                     // épinglée
		"[SambaEduOverlay]", // section d'instance = dossier de config relatif à Skins\
		"Active=1",
	}
	for _, want := range mustContain {
		if !strings.Contains(ini, want) {
			t.Errorf("Rainmeter.ini durci doit contenir %q :\n%s", want, ini)
		}
	}
}

func TestBuildHardenedRainmeterIni_Deterministic(t *testing.T) {
	if BuildHardenedRainmeterIni() != BuildHardenedRainmeterIni() {
		t.Fatal("Rainmeter.ini non déterministe (idempotence par hash cassée)")
	}
}

// --- Idempotence de pose (fileMatchesContent) --------------------------------

func TestFileMatchesContent(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "skin.ini")

	want := []byte{0xFF, 0xFE, 0x41, 0x00}
	if fileMatchesContent(path, want) {
		t.Fatal("fichier absent ne doit jamais matcher")
	}
	if err := os.WriteFile(path, want, 0o600); err != nil {
		t.Fatal(err)
	}
	if !fileMatchesContent(path, want) {
		t.Fatal("contenu identique doit matcher")
	}
	if fileMatchesContent(path, []byte{0xFF, 0xFE, 0x42, 0x00}) {
		t.Fatal("contenu divergent (1 octet) ne doit pas matcher (UTF-16 sensible à l'octet)")
	}
}

// --- Extraction portable + zip-slip ------------------------------------------

func makeZip(t *testing.T, entries map[string]string) []byte {
	t.Helper()
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	for name, content := range entries {
		w, err := zw.Create(name)
		if err != nil {
			t.Fatal(err)
		}
		if _, err := w.Write([]byte(content)); err != nil {
			t.Fatal(err)
		}
	}
	if err := zw.Close(); err != nil {
		t.Fatal(err)
	}

	return buf.Bytes()
}

func TestExtractPortableZip_OK(t *testing.T) {
	dir := t.TempDir()
	archive := makeZip(t, map[string]string{
		"Rainmeter.exe":    "MZ-fake",
		"Skins/readme.txt": "hello",
	})

	if err := ExtractPortableZip(archive, dir); err != nil {
		t.Fatal(err)
	}
	got, err := os.ReadFile(filepath.Join(dir, "Rainmeter.exe"))
	if err != nil || string(got) != "MZ-fake" {
		t.Fatalf("Rainmeter.exe non extrait : %v %q", err, got)
	}
	if got, _ := os.ReadFile(filepath.Join(dir, "Skins", "readme.txt")); string(got) != "hello" {
		t.Fatalf("sous-dossier non extrait : %q", got)
	}
}

func TestExtractPortableZip_ZipSlipRejected(t *testing.T) {
	dir := t.TempDir()
	// Entrée traversal : un `..` qui sortirait du dossier cible.
	archive := makeZip(t, map[string]string{
		"../evil.txt": "pwned",
	})
	if err := ExtractPortableZip(archive, dir); err == nil {
		t.Fatal("zip-slip (..) doit être rejeté")
	}
	if _, err := os.Stat(filepath.Join(filepath.Dir(dir), "evil.txt")); err == nil {
		t.Fatal("le fichier traversal ne doit JAMAIS être écrit hors du dossier cible")
	}
}

func TestExtractPortableZip_Idempotent(t *testing.T) {
	dir := t.TempDir()
	archive := makeZip(t, map[string]string{"Rainmeter.exe": "v1"})
	if err := ExtractPortableZip(archive, dir); err != nil {
		t.Fatal(err)
	}
	// Re-extract : recouvre les mêmes octets, pas d'erreur.
	if err := ExtractPortableZip(archive, dir); err != nil {
		t.Fatalf("re-extraction (idempotence) en échec : %v", err)
	}
}

// --- RainmeterStore : install-if-absent --------------------------------------

func TestRainmeterStore_InstalledMarkerSentinel(t *testing.T) {
	dir := t.TempDir()
	store := &RainmeterStore{Root: dir}

	if store.RainmeterInstalled() {
		t.Fatal("marqueur absent → non installé")
	}

	// Rainmeter.exe SEUL (extraction partielle interrompue avant le marqueur)
	// ne doit PAS compter comme installé (#10) : sinon le cycle suivant ne
	// rejoue pas l'extraction et le poste reste avec un portable cassé.
	if err := os.MkdirAll(store.AppDir(), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(store.ExePath(), []byte("MZ"), 0o600); err != nil {
		t.Fatal(err)
	}
	if store.RainmeterInstalled() {
		t.Fatal("Rainmeter.exe présent mais marqueur absent → NON installé (extraction partielle)")
	}

	// Marqueur posé (extraction complète + ACL terminées) → installé.
	if err := os.WriteFile(store.InstalledMarkerPath(), []byte("sha"), 0o600); err != nil {
		t.Fatal(err)
	}
	if !store.RainmeterInstalled() {
		t.Fatal("marqueur présent → installé")
	}
}

func TestRainmeterStore_PortableLayoutFlat(t *testing.T) {
	// Structure portable RÉELLE (#7/M1) : Rainmeter.exe ET Rainmeter.ini À LA
	// RACINE (pas de sous-dossier app/), Skins/ au même niveau.
	store := &RainmeterStore{Root: `/tmp/rm`}
	if store.ExePath() != filepath.Join(`/tmp/rm`, "Rainmeter.exe") {
		t.Errorf("Rainmeter.exe doit être à la racine, got %s", store.ExePath())
	}
	if store.SettingsPath() != filepath.Join(`/tmp/rm`, "Rainmeter.ini") {
		t.Errorf("Rainmeter.ini doit être à côté de l'exe (racine), got %s", store.SettingsPath())
	}
	if store.SkinsDir() != filepath.Join(`/tmp/rm`, "Skins") {
		t.Errorf("Skins/ doit être à la racine, got %s", store.SkinsDir())
	}
	if filepath.Dir(store.ExePath()) != filepath.Dir(store.SettingsPath()) {
		t.Errorf("Rainmeter.exe et Rainmeter.ini doivent vivre dans le même dossier (mode portable)")
	}
}

// TestRainmeterIni_NoFormulaInWindowPosition vérifie qu'aucune variable/formule
// non résolue ne fuit dans le Rainmeter.ini de settings (#18/M2) : WindowX/Y
// doivent rester des entiers bruts (le placement fin est délégué à la skin).
func TestRainmeterIni_NoFormulaInWindowPosition(t *testing.T) {
	ini := BuildHardenedRainmeterIni()
	for _, banned := range []string{"#WORKAREAWIDTH#", "#SCREENAREAWIDTH#", "(", "384"} {
		if strings.Contains(ini, banned) {
			t.Errorf("Rainmeter.ini de settings ne doit pas contenir %q (variable/formule non résolue) :\n%s", banned, ini)
		}
	}
}

// TestExtractPortableZip_TotalBytesBound vérifie la borne TOTALE cumulée (#9) :
// une archive dont la somme des entrées dépasse rainmeterUnzipMaxTotalBytes est
// rejetée même si chaque entrée reste sous la borne par entrée.
func TestExtractPortableZip_TotalBytesBound(t *testing.T) {
	dir := t.TempDir()
	// Beaucoup d'entrées légères qui, cumulées, dépassent la borne totale.
	// On fabrique des entrées de ~1 Mio chacune jusqu'à dépasser la borne.
	entries := map[string]string{}
	chunk := strings.Repeat("A", 1<<20) // 1 Mio
	count := int(rainmeterUnzipMaxTotalBytes>>20) + 2
	for i := 0; i < count; i++ {
		entries[fmt.Sprintf("f%04d.bin", i)] = chunk
	}
	archive := makeZip(t, entries)
	if err := ExtractPortableZip(archive, dir); err == nil {
		t.Fatal("archive dépassant la borne totale doit être rejetée (#9)")
	}
}

func TestRainmeterStore_PathsUnderRoot(t *testing.T) {
	store := &RainmeterStore{Root: `/tmp/rm`}
	for _, p := range []string{store.ExePath(), store.SettingsPath(), store.SkinPath()} {
		if !strings.HasPrefix(p, `/tmp/rm`) {
			t.Errorf("chemin hors racine : %s", p)
		}
	}
	// La skin doit vivre sous Skins\SambaEduOverlay\.
	if !strings.Contains(store.SkinPath(), filepath.Join("Skins", "SambaEduOverlay")) {
		t.Errorf("skin hors arborescence Rainmeter attendue : %s", store.SkinPath())
	}
}
