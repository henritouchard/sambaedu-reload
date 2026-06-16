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

// writeOverlayMachineCache pose le cache MACHINE (cache/state.json) — la salle
// vit en portée machine depuis la Story 27.10.
func writeOverlayMachineCache(t *testing.T, store *Store, body string) {
	t.Helper()
	if err := os.MkdirAll(store.CacheDir(), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(store.StateCachePath(), []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}
}

// Cache machine : la salle est en portée `machine` (Story 27.10).
const overlayMachineState = `{
  "schema": "se5.desired-state/v1",
  "generated_at": "2026-06-16T08:00:00Z",
  "machine": [
    {"type":"overlay","semantics":"aggregate","hash":"h-mc",
     "payload":{"kind":"machine","room":"Salle B-12"}}
  ],
  "session": [],
  "machine_user": []
}`

const overlaySessionState = `{
  "schema": "se5.desired-state/v1",
  "generated_at": "2026-06-16T08:00:00Z",
  "machine": [],
  "session": [
    {"type":"overlay","semantics":"aggregate","hash":"h-id",
     "payload":{"kind":"identity","fullname":"Jean Döe","login":"jdoe"}},
    {"type":"overlay","semantics":"aggregate","hash":"h-al",
     "payload":{"kind":"signal","severity":"warning","title":"Maintenance","text":"Coupure prévue"}},
    {"type":"wallpaper","semantics":"exclusive","hash":"h-wp",
     "payload":{"asset":null}}
  ],
  "machine_user": []
}`

func TestOverlayDocumentForSession_ComposesFromBothCaches(t *testing.T) {
	store := &Store{Root: t.TempDir()}
	sid := "S-1-5-21-1-2-3-1001"
	writeOverlayMachineCache(t, store, overlayMachineState)
	writeOverlaySessionCache(t, store, sid, overlaySessionState)

	doc, ok := OverlayDocumentForSession(store, sid, "SALLE101-PC03", nil)
	if !ok {
		t.Fatal("caches présents → composition attendue")
	}

	// La composition réutilise ComposeOverlayDocument À L'IDENTIQUE — le
	// document doit être EXACTEMENT celui produit par le sérialiseur figé pour
	// les seuls items overlay : la salle (cache MACHINE) + identity/alertes
	// (cache SESSION). Le wallpaper est ignoré.
	want := ComposeOverlayDocument([]StateItem{
		{Type: "overlay", Hash: "h-mc", Payload: map[string]any{"kind": "machine", "room": "Salle B-12"}},
		{Type: "overlay", Hash: "h-id", Payload: map[string]any{"kind": "identity", "fullname": "Jean Döe", "login": "jdoe"}},
		{Type: "overlay", Hash: "h-al", Payload: map[string]any{"kind": "signal", "severity": "warning", "title": "Maintenance", "text": "Coupure prévue"}},
	}, "SALLE101-PC03")

	if doc != want {
		t.Fatalf("document non identique à la composition figée :\n--- got ---\n%s\n--- want ---\n%s", doc, want)
	}
	// La salle (cache machine) ET l'identity (cache session) sont présentes.
	if !strings.Contains(doc, `"room": "Salle B-12"`) || !strings.Contains(doc, `"fullname": "Jean Döe"`) {
		t.Errorf("salle (machine) + identity (session) attendues :\n%s", doc)
	}
	// Sanity : le payload wallpaper (asset) ne pollue jamais l'overlay (le mot
	// « wallpaper » figure légitimement dans le schema se5.wallpaper-overlay/v1,
	// on cherche donc la trace de l'ITEM wallpaper, pas du schema).
	if strings.Contains(doc, `"asset"`) {
		t.Errorf("item non-overlay (asset) fuité dans le document :\n%s", doc)
	}
}

func TestOverlayDocumentForSession_PreloadsRoomWhenSessionAbsent(t *testing.T) {
	// CŒUR Story 27.10 (AC3/AC4) — préchargement : cache MACHINE présent
	// (salle), cache SESSION ABSENT (per-user pas encore frais au logon). Le
	// document porte machine.room + machine.name (local), identity VIDE.
	store := &Store{Root: t.TempDir()}
	sid := "S-1-5-21-1-2-3-7777"
	writeOverlayMachineCache(t, store, overlayMachineState)
	// Aucun cache session écrit pour ce SID.

	doc, ok := OverlayDocumentForSession(store, sid, "SALLE101-PC03", nil)
	if !ok {
		t.Fatal("cache machine présent → composition (préchargement) attendue même sans session")
	}
	for _, want := range []string{`"room": "Salle B-12"`, `"name": "SALLE101-PC03"`, `"fullname": ""`, `"login": ""`} {
		if !strings.Contains(doc, want) {
			t.Errorf("préchargement : fragment manquant %q :\n%s", want, doc)
		}
	}
}

func TestOverlayDocumentForSession_IdentityWhenMachineAbsent(t *testing.T) {
	// Symétrique : cache SESSION présent, cache MACHINE absent → identity
	// composée, room vide (la salle ne vient QUE du cache machine).
	store := &Store{Root: t.TempDir()}
	sid := "S-1-5-21-1-2-3-8888"
	writeOverlaySessionCache(t, store, sid, overlaySessionState)
	// Aucun cache machine écrit.

	doc, ok := OverlayDocumentForSession(store, sid, "PC", nil)
	if !ok {
		t.Fatal("cache session présent → composition attendue même sans cache machine")
	}
	if !strings.Contains(doc, `"fullname": "Jean Döe"`) {
		t.Errorf("identity attendue :\n%s", doc)
	}
	if !strings.Contains(doc, `"room": ""`) {
		t.Errorf("room vide attendue sans cache machine :\n%s", doc)
	}
}

func TestOverlayDocumentForSession_BothCachesAbsentGraceful(t *testing.T) {
	store := &Store{Root: t.TempDir()}
	// Ni cache machine, ni cache session.
	if _, ok := OverlayDocumentForSession(store, "S-1-5-21-0-0-0-0", "PC", nil); ok {
		t.Fatal("aucun cache exploitable → pas de composition (on n'écrase pas un overlay précédent par un vide)")
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

func TestOverlayDocumentForSession_MachineSaneSessionCorrupted(t *testing.T) {
	// Best-effort (Story 27.10, review F3) : cache MACHINE sain + cache SESSION
	// CORROMPU → la portée machine intacte est composée (salle préchargée), la
	// session illisible est SAUTÉE (identity vide), ok=true. C'est LE scénario
	// probable en prod : la salle persiste dans le cache machine, le cache
	// session est à moitié écrit / illisible au tout début de logon.
	store := &Store{Root: t.TempDir()}
	sid := "S-1-5-21-1-2-3-4004"
	writeOverlayMachineCache(t, store, overlayMachineState)
	writeOverlaySessionCache(t, store, sid, `{"schema":"bogus/v9","session":[]}`)

	doc, ok := OverlayDocumentForSession(store, sid, "SALLE101-PC03", nil)
	if !ok {
		t.Fatal("cache machine sain → composition best-effort attendue malgré une session corrompue")
	}
	if !strings.Contains(doc, `"room": "Salle B-12"`) {
		t.Errorf("salle (cache machine) attendue malgré session corrompue :\n%s", doc)
	}
	if !strings.Contains(doc, `"fullname": ""`) || !strings.Contains(doc, `"login": ""`) {
		t.Errorf("identity VIDE attendue (session corrompue sautée) :\n%s", doc)
	}
}
