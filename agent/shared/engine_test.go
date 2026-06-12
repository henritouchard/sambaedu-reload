package shared

import (
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"strings"
	"testing"
	"time"
)

// fakeHandler : handler scriptable pour les tests du moteur.
type fakeHandler struct {
	compliant bool
	testErr   error
	applyErr  error
	testPanic bool

	testCalls  int
	applyCalls int
	lastItems  []StateItem
}

func (h *fakeHandler) Test(items []StateItem) (bool, error) {
	h.testCalls++
	h.lastItems = items
	if h.testPanic {
		panic("boom du handler")
	}

	return h.compliant, h.testErr
}

func (h *fakeHandler) Apply(items []StateItem) error {
	h.applyCalls++

	return h.applyErr
}

// --- Machine d'états §5 (table-driven, VERBATIM) -----------------------------------

func TestResolveItemStatusSection5Verbatim(t *testing.T) {
	const target = "cible"
	cases := []struct {
		name        string
		compliant   bool
		mode        string
		lastApplied string
		want        Verdict
	}{
		// strict : la cible fait loi, sans exception.
		{"strict/compliant", true, "strict", "", Verdict{Status: "compliant", ShouldPersist: true}},
		{"strict/compliant_avec_memoire", true, "strict", target, Verdict{Status: "compliant", ShouldPersist: true}},
		{"strict/drift_premier_passage", false, "strict", "", Verdict{Status: "drift", ShouldApply: true, ShouldPersist: true}},
		{"strict/drift_meme_si_dernier_applique_egal_cible", false, "strict", target, Verdict{Status: "drift", ShouldApply: true, ShouldPersist: true}},
		{"strict/drift_cible_changee", false, "strict", "ancienne", Verdict{Status: "drift", ShouldApply: true, ShouldPersist: true}},

		// default : tolère la dérive humaine volontaire.
		{"default/compliant", true, "default", "", Verdict{Status: "compliant", ShouldPersist: true}},
		{"default/derive_humaine", false, "default", target, Verdict{Status: "drifted_allowed"}},
		{"default/cible_changee_applique", false, "default", "ancienne", Verdict{Status: "drift", ShouldApply: true, ShouldPersist: true}},
		// Premier passage (pas de mémoire) : JAMAIS drifted_allowed (§5).
		{"default/premier_passage_jamais_drifted_allowed", false, "default", "", Verdict{Status: "drift", ShouldApply: true, ShouldPersist: true}},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got := ResolveItemStatus(tc.compliant, tc.mode, tc.lastApplied, target)
			if got != tc.want {
				t.Errorf("got %+v, want %+v", got, tc.want)
			}
		})
	}
}

// --- RunPass : machine d'états intégrée (premier passage / persistance) -------------

func wallpaperItem(hash string) StateItem {
	return StateItem{Type: "wallpaper", Semantics: "exclusive", Mode: "default", Hash: hash}
}

func TestRunPassDefaultModeFullLifecycle(t *testing.T) {
	// Cycle de vie complet du mode default sur un type exclusive :
	// premier passage drift → compliant → dérive humaine → cible changée.
	h := &fakeHandler{}
	e := &Engine{Handlers: map[string]Handler{"wallpaper": h},
		Now: func() time.Time { return time.Date(2026, 6, 12, 10, 0, 0, 0, time.UTC) }}
	applied := AppliedState{}

	// 1. Premier passage, réel ≠ cible → applique → drift + persiste.
	items := e.RunPass([]StateItem{wallpaperItem("aaa")}, applied)
	if len(items) != 1 || items[0].Status != "drift" || items[0].Hash != "aaa" {
		t.Fatalf("premier passage : %+v", items)
	}
	if h.applyCalls != 1 {
		t.Fatalf("apply attendu au premier passage, got %d", h.applyCalls)
	}
	if applied["wallpaper"].Hash != "aaa" || applied["wallpaper"].AppliedAt != "2026-06-12T10:00:00Z" {
		t.Fatalf("persistance du dernier-appliqué : %+v", applied["wallpaper"])
	}

	// 2. Passe suivante, réel = cible → compliant, zéro apply.
	h.compliant = true
	items = e.RunPass([]StateItem{wallpaperItem("aaa")}, applied)
	if items[0].Status != "compliant" || h.applyCalls != 1 {
		t.Fatalf("compliant sans apply attendu : %+v (applyCalls=%d)", items, h.applyCalls)
	}

	// 3. Dérive humaine : réel ≠ cible ∧ dernier-appliqué = cible →
	//    drifted_allowed, ne réapplique PAS.
	h.compliant = false
	items = e.RunPass([]StateItem{wallpaperItem("aaa")}, applied)
	if items[0].Status != "drifted_allowed" || h.applyCalls != 1 {
		t.Fatalf("drifted_allowed sans apply attendu : %+v (applyCalls=%d)", items, h.applyCalls)
	}
	if applied["wallpaper"].Hash != "aaa" {
		t.Fatal("drifted_allowed ne doit rien persister")
	}

	// 4. Cible changée (dernier-appliqué ≠ nouvelle cible) → applique → drift.
	items = e.RunPass([]StateItem{wallpaperItem("bbb")}, applied)
	if items[0].Status != "drift" || h.applyCalls != 2 || items[0].Hash != "bbb" {
		t.Fatalf("cible changée : %+v (applyCalls=%d)", items, h.applyCalls)
	}
	if applied["wallpaper"].Hash != "bbb" {
		t.Fatalf("nouvelle cible persistée attendue : %+v", applied["wallpaper"])
	}
}

func TestRunPassFirstPassCompliantPersists(t *testing.T) {
	// Premier passage, réel = cible → compliant + persiste (§5).
	h := &fakeHandler{compliant: true}
	e := &Engine{Handlers: map[string]Handler{"wallpaper": h}}
	applied := AppliedState{}

	items := e.RunPass([]StateItem{wallpaperItem("aaa")}, applied)
	if items[0].Status != "compliant" {
		t.Fatalf("compliant attendu : %+v", items)
	}
	if applied["wallpaper"].Hash != "aaa" {
		t.Error("compliant au premier passage doit persister la cible")
	}
}

// --- Isolation, ordre, dispatch ------------------------------------------------------

func TestRunPassIsolationErrorContinues(t *testing.T) {
	// Un handler en échec → error + detail pour CE type, la passe CONTINUE.
	failing := &fakeHandler{testErr: errors.New("registre inaccessible")}
	healthy := &fakeHandler{compliant: true}
	e := &Engine{Handlers: map[string]Handler{"wallpaper": failing, "overlay": healthy}}
	applied := AppliedState{}

	items := e.RunPass([]StateItem{
		wallpaperItem("aaa"),
		{Type: "overlay", Semantics: "aggregate", Mode: "strict", Hash: "bbb"},
	}, applied)

	if len(items) != 2 {
		t.Fatalf("2 items de rapport attendus (isolation), got %+v", items)
	}
	if items[0].Status != "error" || items[0].Detail != "registre inaccessible" {
		t.Errorf("error + detail attendus : %+v", items[0])
	}
	if items[1].Status != "compliant" {
		t.Errorf("le type suivant doit être traité : %+v", items[1])
	}
	if _, ok := applied["wallpaper"]; ok {
		t.Error("un échec ne persiste jamais la cible")
	}
}

func TestRunPassIsolationPanicRecovered(t *testing.T) {
	panicking := &fakeHandler{testPanic: true}
	healthy := &fakeHandler{compliant: true}
	e := &Engine{Handlers: map[string]Handler{"wallpaper": panicking, "overlay": healthy}}

	items := e.RunPass([]StateItem{
		wallpaperItem("aaa"),
		{Type: "overlay", Semantics: "aggregate", Hash: "bbb"},
	}, AppliedState{})

	if len(items) != 2 || items[0].Status != "error" || items[1].Status != "compliant" {
		t.Fatalf("panic isolée + passe poursuivie attendues : %+v", items)
	}
	if items[0].Detail == "" || !strings.Contains(items[0].Detail, "boom") {
		t.Errorf("detail non vide attendu sur panic : %+v", items[0])
	}
}

func TestRunPassApplyErrorReportsErrorWithoutPersist(t *testing.T) {
	h := &fakeHandler{applyErr: errors.New("asset wallpaper absent du cache local")}
	e := &Engine{Handlers: map[string]Handler{"wallpaper": h}}
	applied := AppliedState{}

	items := e.RunPass([]StateItem{wallpaperItem("aaa")}, applied)
	if items[0].Status != "error" || items[0].Detail == "" {
		t.Fatalf("apply en échec → error + detail : %+v", items)
	}
	if _, ok := applied["wallpaper"]; ok {
		t.Error("apply en échec ne persiste pas")
	}
}

func TestRunPassServerOrderPreserved(t *testing.T) {
	// L'ordre du rapport suit l'ordre de PREMIÈRE occurrence dans le payload
	// serveur — jamais d'ordre inventé.
	e := &Engine{Handlers: map[string]Handler{
		"overlay":   &fakeHandler{compliant: true},
		"wallpaper": &fakeHandler{compliant: true},
		"shortcuts": &fakeHandler{compliant: true},
	}}

	items := e.RunPass([]StateItem{
		{Type: "overlay", Semantics: "aggregate", Hash: "o1"},
		{Type: "wallpaper", Semantics: "exclusive", Hash: "w1"},
		{Type: "overlay", Semantics: "aggregate", Hash: "o2"},
		{Type: "shortcuts", Semantics: "aggregate", Hash: "s1"},
	}, AppliedState{})

	gotOrder := []string{}
	for _, item := range items {
		gotOrder = append(gotOrder, item.Type)
	}
	want := []string{"overlay", "wallpaper", "shortcuts"}
	if fmt.Sprint(gotOrder) != fmt.Sprint(want) {
		t.Errorf("ordre serveur : got %v, want %v", gotOrder, want)
	}
}

func TestRunPassTypeWithoutHandlerEmitsNoStatus(t *testing.T) {
	// Contrat §8 : type sans handler = ignoré, AUCUN statut émis.
	e := &Engine{Handlers: map[string]Handler{"wallpaper": &fakeHandler{compliant: true}}}

	items := e.RunPass([]StateItem{
		{Type: "printers", Semantics: "aggregate", Hash: "p1"},
		wallpaperItem("w1"),
	}, AppliedState{})

	if len(items) != 1 || items[0].Type != "wallpaper" {
		t.Errorf("seul wallpaper doit produire un statut : %+v", items)
	}
}

func TestRunPassUnknownModeTreatedAsStrict(t *testing.T) {
	// Mode inconnu (contrat futur ?) : posture sûre = strict (réapplique).
	h := &fakeHandler{}
	e := &Engine{Handlers: map[string]Handler{"wallpaper": h}}
	applied := AppliedState{"wallpaper": {Hash: "aaa"}}

	items := e.RunPass([]StateItem{
		{Type: "wallpaper", Semantics: "exclusive", Mode: "permissif", Hash: "aaa"},
	}, applied)

	// En default ce serait drifted_allowed (dernier-appliqué = cible) ; en
	// strict c'est drift + apply.
	if items[0].Status != "drift" || h.applyCalls != 1 {
		t.Errorf("mode inconnu = strict attendu : %+v (applyCalls=%d)", items, h.applyCalls)
	}
}

func TestRunPassExclusiveMultiItemsLastWins(t *testing.T) {
	h := &fakeHandler{compliant: true}
	e := &Engine{Handlers: map[string]Handler{"wallpaper": h}}

	items := e.RunPass([]StateItem{
		{Type: "wallpaper", Semantics: "exclusive", Hash: "premier"},
		{Type: "wallpaper", Semantics: "exclusive", Hash: "dernier"},
	}, AppliedState{})

	if items[0].Hash != "dernier" {
		t.Errorf("exclusive multi-items : le DERNIER fait foi (§3.1), got %q", items[0].Hash)
	}
	if len(h.lastItems) != 2 {
		t.Errorf("le handler reçoit tous les items du type, got %d", len(h.lastItems))
	}
}

// --- Conventions de hash --------------------------------------------------------------

func TestAggregateHashConvention(t *testing.T) {
	// Empreinte d'agrégat = SHA-256 hex de la CONCATÉNATION des hashes
	// opaques, dans l'ordre serveur — convention 24.4, à l'identique.
	items := []StateItem{{Hash: "abc"}, {Hash: "def"}}
	sum := sha256.Sum256([]byte("abcdef"))
	want := hex.EncodeToString(sum[:])

	if got := AggregateHash(items); got != want {
		t.Errorf("AggregateHash : got %q, want %q", got, want)
	}

	// L'ordre compte (l'ordre serveur est significatif).
	if AggregateHash([]StateItem{{Hash: "def"}, {Hash: "abc"}}) == want {
		t.Error("l'empreinte doit dépendre de l'ordre serveur")
	}

	// Liste vide : empreinte du vide, déterministe.
	empty := sha256.Sum256([]byte(""))
	if AggregateHash(nil) != hex.EncodeToString(empty[:]) {
		t.Error("empreinte déterministe sur liste vide")
	}
}

func TestRunPassAggregateUsesFingerprintExclusiveUsesVerbatim(t *testing.T) {
	overlay := &fakeHandler{compliant: true}
	wallpaper := &fakeHandler{compliant: true}
	e := &Engine{Handlers: map[string]Handler{"overlay": overlay, "wallpaper": wallpaper}}

	stateItems := []StateItem{
		{Type: "overlay", Semantics: "aggregate", Hash: "h1"},
		{Type: "overlay", Semantics: "aggregate", Hash: "h2"},
		{Type: "wallpaper", Semantics: "exclusive", Hash: "whash"},
	}
	items := e.RunPass(stateItems, AppliedState{})

	sum := sha256.Sum256([]byte("h1h2"))
	if items[0].Hash != hex.EncodeToString(sum[:]) {
		t.Errorf("aggregate = empreinte de concaténation : got %q", items[0].Hash)
	}
	if items[1].Hash != "whash" {
		t.Errorf("exclusive = hash d'item VERBATIM : got %q", items[1].Hash)
	}
}

func TestRunPassSemanticsDefaultsToExclusive(t *testing.T) {
	h := &fakeHandler{compliant: true}
	e := &Engine{Handlers: map[string]Handler{"wallpaper": h}}

	items := e.RunPass([]StateItem{{Type: "wallpaper", Hash: "verbatim"}}, AppliedState{})
	if items[0].Hash != "verbatim" {
		t.Errorf("semantics absent = exclusive (hash verbatim) : got %q", items[0].Hash)
	}
}

// --- Détail d'erreur ------------------------------------------------------------------

func TestErrorDetailBoundedAndNeverEmpty(t *testing.T) {
	long := &fakeHandler{testErr: errors.New(strings.Repeat("é", 3000))}
	e := &Engine{Handlers: map[string]Handler{"wallpaper": long}}

	items := e.RunPass([]StateItem{wallpaperItem("aaa")}, AppliedState{})
	if got := len([]rune(items[0].Detail)); got != detailMaxLength {
		t.Errorf("detail borné à %d runes (jamais coupé mi-UTF-8) : got %d", detailMaxLength, got)
	}

	empty := &fakeHandler{testErr: errors.New("")}
	e = &Engine{Handlers: map[string]Handler{"wallpaper": empty}}
	items = e.RunPass([]StateItem{wallpaperItem("aaa")}, AppliedState{})
	if items[0].Detail == "" {
		t.Error("detail obligatoire non vide sur error (contrat §6)")
	}
}

// --- ItemsFromScope ------------------------------------------------------------------

func TestItemsFromScopeSkipsMalformedEntries(t *testing.T) {
	raw := []any{
		map[string]any{"type": "wallpaper", "hash": "aaa", "semantics": "exclusive", "mode": "default", "payload": map[string]any{"asset": nil}},
		map[string]any{"type": "overlay"},   // sans hash → ignoré
		map[string]any{"hash": "orphelin"},  // sans type → ignoré
		"pas-un-objet",                      // non-objet → ignoré
		map[string]any{"type": "shortcuts", "hash": "bbb"},
	}

	items := ItemsFromScope(raw, nil)
	if len(items) != 2 {
		t.Fatalf("2 items valides attendus, got %+v", items)
	}
	if items[0].Type != "wallpaper" || items[0].Mode != "default" || items[0].Semantics != "exclusive" {
		t.Errorf("extraction des champs : %+v", items[0])
	}
	if items[1].Type != "shortcuts" || items[1].Semantics != "" || items[1].Mode != "" {
		t.Errorf("défauts vides (résolus par le moteur) : %+v", items[1])
	}
}
