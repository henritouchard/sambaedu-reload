package shared

import (
	"os"
	"strings"
	"testing"
)

const otherSID = "S-1-5-21-1111111111-2222222222-3333333333-1002"

func writeDrop(t *testing.T, store *Store, sid, raw string) {
	t.Helper()
	if err := store.EnsureSessionReportDir(sid, nil); err != nil {
		t.Fatal(err)
	}
	if err := WriteFileAtomic(store.SessionReportPath(sid), []byte(raw)); err != nil {
		t.Fatal(err)
	}
}

func hashA() string { return strings.Repeat("a", 64) }
func hashB() string { return strings.Repeat("b", 64) }

func TestCollectSessionReportsValidDrop(t *testing.T) {
	store := newTestStore(t)
	writeDrop(t, store, testSID,
		`{"generated_at":"2026-06-12T10:00:00Z","items":[{"type":"overlay","status":"compliant","hash":"`+hashB()+`"},{"type":"wallpaper","status":"drift","hash":"`+hashA()+`"}]}`)

	items := CollectSessionReports(store, nil)
	if len(items) != 2 {
		t.Fatalf("2 items attendus : %+v", items)
	}
	// Ordre des types ASCENDANT (déterminisme, acquis 24.4 n° 5).
	if items[0].Type != "overlay" || items[1].Type != "wallpaper" {
		t.Errorf("types asc attendus : %+v", items)
	}
}

func TestCollectSessionReportsStrictValidation(t *testing.T) {
	// FRONTIÈRE DE CONFIANCE : chaque entrée forgeable est validée AVANT
	// fusion — table-driven (piège n° 8).
	cases := []struct {
		name string
		item string
	}{
		{"type_hors_liste", `{"type":"malware","status":"compliant","hash":"` + hashA() + `"}`},
		{"status_hors_enum", `{"type":"wallpaper","status":"pwned","hash":"` + hashA() + `"}`},
		{"hash_non_hex64", `{"type":"wallpaper","status":"compliant","hash":"xyz"}`},
		{"hash_majuscule", `{"type":"wallpaper","status":"compliant","hash":"` + strings.ToUpper(hashA()) + `"}`},
		{"error_sans_detail", `{"type":"wallpaper","status":"error","hash":"` + hashA() + `"}`},
		{"error_detail_blanc", `{"type":"wallpaper","status":"error","hash":"` + hashA() + `","detail":"   "}`},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			store := newTestStore(t)
			writeDrop(t, store, testSID, `{"generated_at":"2026-06-12T10:00:00Z","items":[`+tc.item+`]}`)
			if items := CollectSessionReports(store, nil); len(items) != 0 {
				t.Errorf("entrée invalide rejetée attendue : %+v", items)
			}
		})
	}
}

func TestCollectSessionReportsInvalidEntryDoesNotSinkValidOnes(t *testing.T) {
	store := newTestStore(t)
	writeDrop(t, store, testSID,
		`{"generated_at":"2026-06-12T10:00:00Z","items":[{"type":"forge","status":"compliant","hash":"`+hashA()+`"},{"type":"wallpaper","status":"compliant","hash":"`+hashA()+`"}]}`)

	items := CollectSessionReports(store, nil)
	if len(items) != 1 || items[0].Type != "wallpaper" {
		t.Errorf("l'entrée valide survit : %+v", items)
	}
}

func TestCollectSessionReportsInvalidJSONIgnored(t *testing.T) {
	store := newTestStore(t)
	writeDrop(t, store, testSID, `{broken`)
	writeDrop(t, store, otherSID,
		`{"generated_at":"2026-06-12T10:00:00Z","items":[{"type":"overlay","status":"compliant","hash":"`+hashB()+`"}]}`)

	items := CollectSessionReports(store, nil)
	if len(items) != 1 || items[0].Type != "overlay" {
		t.Errorf("drop JSON invalide ignoré, l'autre survit : %+v", items)
	}
}

func TestCollectSessionReportsSizeCapBeforeParse(t *testing.T) {
	store := newTestStore(t)
	huge := `{"generated_at":"2026-06-12T10:00:00Z","items":[{"type":"wallpaper","status":"compliant","hash":"` +
		hashA() + `","detail":"` + strings.Repeat("x", SessionReportMaxBytes) + `"}]}`
	writeDrop(t, store, testSID, huge)

	if items := CollectSessionReports(store, nil); len(items) != 0 {
		t.Errorf("drop au-delà du plafond ignoré : %d items", len(items))
	}
}

func TestCollectSessionReportsMostRecentWinsPerType(t *testing.T) {
	store := newTestStore(t)
	writeDrop(t, store, testSID,
		`{"generated_at":"2026-06-12T09:00:00Z","items":[{"type":"wallpaper","status":"compliant","hash":"`+hashA()+`"}]}`)
	writeDrop(t, store, otherSID,
		`{"generated_at":"2026-06-12T10:00:00Z","items":[{"type":"wallpaper","status":"drift","hash":"`+hashB()+`"}]}`)

	items := CollectSessionReports(store, nil)
	if len(items) != 1 {
		t.Fatalf("fusion unique par type : %+v", items)
	}
	if items[0].Status != "drift" || items[0].Hash != hashB() {
		t.Errorf("le generated_at le plus récent gagne : %+v", items[0])
	}
}

func TestCollectSessionReportsUnparsableDateLosesMerge(t *testing.T) {
	store := newTestStore(t)
	writeDrop(t, store, testSID,
		`{"generated_at":"pas-une-date","items":[{"type":"wallpaper","status":"compliant","hash":"`+hashA()+`"}]}`)
	writeDrop(t, store, otherSID,
		`{"generated_at":"2026-06-12T10:00:00Z","items":[{"type":"wallpaper","status":"drift","hash":"`+hashB()+`"}]}`)

	items := CollectSessionReports(store, nil)
	if len(items) != 1 || items[0].Hash != hashB() {
		t.Errorf("date non parsable = époque zéro (perd la fusion) : %+v", items)
	}
}

func TestCollectSessionReportsForgedFutureDateLosesMerge(t *testing.T) {
	store := newTestStore(t)
	writeDrop(t, store, testSID,
		`{"generated_at":"2999-01-01T00:00:00Z","items":[{"type":"wallpaper","status":"compliant","hash":"`+hashA()+`"}]}`)
	writeDrop(t, store, otherSID,
		`{"generated_at":"2026-06-12T10:00:00Z","items":[{"type":"wallpaper","status":"drift","hash":"`+hashB()+`"}]}`)

	items := CollectSessionReports(store, nil)
	if len(items) != 1 || items[0].Hash != hashB() {
		t.Errorf("generated_at futur forgé = époque zéro (perd la fusion) : %+v", items)
	}
}

func TestCollectSessionReportsDetailTruncated(t *testing.T) {
	store := newTestStore(t)
	writeDrop(t, store, testSID,
		`{"generated_at":"2026-06-12T10:00:00Z","items":[{"type":"wallpaper","status":"error","hash":"`+hashA()+`","detail":"`+strings.Repeat("y", 3000)+`"}]}`)

	items := CollectSessionReports(store, nil)
	if len(items) != 1 || len(items[0].Detail) != detailMaxLength {
		t.Errorf("detail borné à %d : %d", detailMaxLength, len(items[0].Detail))
	}
}

func TestCollectSessionReportsNoDropsDir(t *testing.T) {
	store := newTestStore(t)
	if items := CollectSessionReports(store, nil); len(items) != 0 {
		t.Errorf("aucun répertoire = items vides : %+v", items)
	}
}

// ── PurgeOrphanDrops (fix fantômes) ──────────────────────────────────────────

func TestPurgeOrphanDropsRemovesInactiveKeepsActive(t *testing.T) {
	store := newTestStore(t)
	writeDrop(t, store, testSID, `{"generated_at":"2026-06-12T10:00:00Z","items":[]}`)
	writeDrop(t, store, otherSID, `{"generated_at":"2026-06-12T10:00:00Z","items":[]}`)

	// testSID vivant, otherSID terminé.
	PurgeOrphanDrops(store, map[string]bool{testSID: true}, nil)

	if _, err := os.Stat(store.SessionReportDir(testSID)); err != nil {
		t.Errorf("la session vivante doit être conservée : %v", err)
	}
	if _, err := os.Stat(store.SessionReportDir(otherSID)); !os.IsNotExist(err) {
		t.Errorf("la session orpheline doit être purgée (err=%v)", err)
	}
}

func TestPurgeOrphanDropsNilSetIsFailOpen(t *testing.T) {
	store := newTestStore(t)
	writeDrop(t, store, testSID, `{"generated_at":"2026-06-12T10:00:00Z","items":[]}`)

	PurgeOrphanDrops(store, nil, nil) // ensemble indisponible → ne purge rien

	if _, err := os.Stat(store.SessionReportDir(testSID)); err != nil {
		t.Errorf("set nil = fail-open : rien ne doit être purgé (%v)", err)
	}
}

func TestPurgeOrphanDropsEmptySetPurgesAll(t *testing.T) {
	store := newTestStore(t)
	writeDrop(t, store, testSID, `{"generated_at":"2026-06-12T10:00:00Z","items":[]}`)
	writeDrop(t, store, otherSID, `{"generated_at":"2026-06-12T10:00:00Z","items":[]}`)

	PurgeOrphanDrops(store, map[string]bool{}, nil) // zéro session confirmée → tout orphelin

	for _, sid := range []string{testSID, otherSID} {
		if _, err := os.Stat(store.SessionReportDir(sid)); !os.IsNotExist(err) {
			t.Errorf("aucune session vivante = tout purgé : %s subsiste (%v)", sid, err)
		}
	}
}

func TestPurgeOrphanDropsNoDropsDirNoPanic(t *testing.T) {
	store := newTestStore(t)
	PurgeOrphanDrops(store, map[string]bool{}, nil) // répertoire absent : aucun panic
}

// Le fantôme d'une session partie ne doit plus être collecté après purge.
func TestPurgeThenCollectExcludesOrphanItems(t *testing.T) {
	store := newTestStore(t)
	// Drop d'une session TERMINÉE portant une erreur (le fantôme).
	writeDrop(t, store, otherSID,
		`{"generated_at":"2026-06-12T10:00:00Z","items":[{"type":"drives","status":"error","hash":"`+hashA()+`","detail":"K: KO"}]}`)
	// Drop de la session vivante.
	writeDrop(t, store, testSID,
		`{"generated_at":"2026-06-12T11:00:00Z","items":[{"type":"wallpaper","status":"compliant","hash":"`+hashB()+`"}]}`)

	PurgeOrphanDrops(store, map[string]bool{testSID: true}, nil)
	items := CollectSessionReports(store, nil)

	if len(items) != 1 || items[0].Type != "wallpaper" {
		t.Errorf("le fantôme drives de la session partie ne doit plus être collecté : %+v", items)
	}
}
