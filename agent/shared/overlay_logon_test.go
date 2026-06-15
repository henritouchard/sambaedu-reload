package shared

import (
	"os"
	"strings"
	"testing"
)

// writeOverlaySessionCache pose un state.json per-SID minimal valide pour le test.
func writeOverlaySessionCache(t *testing.T, store *Store, sid, body string) {
	t.Helper()
	dir := store.SessionCacheDir(sid)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(store.SessionStatePath(sid), []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}
}

const overlaySessionState = `{
  "schema": "se5.desired-state/v1",
  "generated_at": "2026-06-16T08:00:00Z",
  "machine": [],
  "session": [
    {"type":"overlay","semantics":"aggregate","mode":"strict","hash":"h-id",
     "payload":{"kind":"identity","fullname":"Jean Döe","login":"jdoe","room":"Salle B-12"}},
    {"type":"overlay","semantics":"aggregate","mode":"strict","hash":"h-al",
     "payload":{"kind":"signal","severity":"warning","title":"Maintenance","text":"Coupure prévue"}},
    {"type":"wallpaper","semantics":"exclusive","mode":"default","hash":"h-wp",
     "payload":{"asset":null}}
  ],
  "machine_user": []
}`

func TestOverlayDocumentForSession_ComposesFromCache(t *testing.T) {
	store := &Store{Root: t.TempDir()}
	sid := "S-1-5-21-1-2-3-1001"
	writeOverlaySessionCache(t, store, sid, overlaySessionState)

	doc, ok := OverlayDocumentForSession(store, sid, "SALLE101-PC03", nil)
	if !ok {
		t.Fatal("cache présent → composition attendue")
	}

	// La composition réutilise ComposeOverlayDocument À L'IDENTIQUE — le
	// document doit être EXACTEMENT celui produit par le sérialiseur figé pour
	// les seuls items overlay (le wallpaper est ignoré).
	want := ComposeOverlayDocument([]StateItem{
		{Type: "overlay", Hash: "h-id", Payload: map[string]any{"kind": "identity", "fullname": "Jean Döe", "login": "jdoe", "room": "Salle B-12"}},
		{Type: "overlay", Hash: "h-al", Payload: map[string]any{"kind": "signal", "severity": "warning", "title": "Maintenance", "text": "Coupure prévue"}},
	}, "SALLE101-PC03")

	if doc != want {
		t.Fatalf("document non identique à la composition figée :\n--- got ---\n%s\n--- want ---\n%s", doc, want)
	}
	// Sanity : le payload wallpaper (asset) ne pollue jamais l'overlay (le mot
	// « wallpaper » figure légitimement dans le schema se5.wallpaper-overlay/v1,
	// on cherche donc la trace de l'ITEM wallpaper, pas du schema).
	if strings.Contains(doc, `"asset"`) {
		t.Errorf("item non-overlay (asset) fuité dans le document :\n%s", doc)
	}
}

func TestOverlayDocumentForSession_AbsentCacheGraceful(t *testing.T) {
	store := &Store{Root: t.TempDir()}
	// Aucun cache écrit pour ce SID.
	_, ok := OverlayDocumentForSession(store, "S-1-5-21-9-9-9-9", "PC", nil)
	if ok {
		t.Fatal("cache absent → pas de composition (on n'écrase pas un overlay précédent par un vide)")
	}
}

func TestOverlayDocumentForSession_MachineOnlyKeysPresent(t *testing.T) {
	// Une session sans item overlay → document machine-only (clés présentes,
	// alerts vide) — la regex du render exige la présence des clés.
	store := &Store{Root: t.TempDir()}
	sid := "S-1-5-21-1-2-3-2002"
	writeOverlaySessionCache(t, store, sid, `{"schema":"se5.desired-state/v1","machine":[],"session":[],"machine_user":[]}`)

	doc, ok := OverlayDocumentForSession(store, sid, "PC-X", nil)
	if !ok {
		t.Fatal("cache présent (vide) → composition machine-only attendue")
	}
	for _, key := range []string{`"fullname": ""`, `"name": "PC-X"`, `"alerts": []`} {
		if !strings.Contains(doc, key) {
			t.Errorf("clé machine-only manquante %q :\n%s", key, doc)
		}
	}
}

func TestOverlayDocumentForSession_CorruptedCacheGraceful(t *testing.T) {
	store := &Store{Root: t.TempDir()}
	sid := "S-1-5-21-1-2-3-3003"
	// Schema inconnu → ParseState échoue → composition sautée (gracieux).
	writeOverlaySessionCache(t, store, sid, `{"schema":"bogus/v9","session":[]}`)

	if _, ok := OverlayDocumentForSession(store, sid, "PC", nil); ok {
		t.Fatal("cache corrompu/schema inconnu → composition sautée (gracieux)")
	}
}

// La skin embarquée doit exister et être convertible en UTF-16 LE + BOM —
// garde-fou de l'embed (un embed cassé ferait échouer le provisioning).
func TestRainmeterSkinSource_EmbeddedAndConvertible(t *testing.T) {
	src := RainmeterSkinSource()
	if !strings.Contains(src, "[Rainmeter]") || !strings.Contains(src, "JsonPath") {
		t.Fatalf("skin embarquée invalide (pas une skin Rainmeter) : %.80q", src)
	}
	out, err := ToUTF16LEWithBOM(src)
	if err != nil || len(out) < 2 || out[0] != 0xFF || out[1] != 0xFE {
		t.Fatalf("skin embarquée non convertible UTF-16 LE + BOM : %v", err)
	}
}
