package shared

import (
	"fmt"
	"sort"
	"strings"
	"testing"
)

// driveMapping : état d'une lettre dans le fake (montée gérée ou non, vers quel
// UNC).
type driveMapping struct {
	mapped bool
	unc    string
}

// fakeDriveOps : DriveOps en mémoire (testable hôte). `mapped` modélise les
// lettres montées par l'agent ; `userLetters` les lettres montées par
// l'utilisateur (hors périmètre, jamais touchées).
type fakeDriveOps struct {
	mapped      map[string]driveMapping // lettre → montage géré
	userLetters map[string]string       // lettre → UNC utilisateur (hors périmètre)

	mapCalls   int
	unmapCalls int
	resolveErr map[string]error // UNC logique → erreur de résolution
}

func newFakeDriveOps() *fakeDriveOps {
	return &fakeDriveOps{
		mapped:      map[string]driveMapping{},
		userLetters: map[string]string{},
		resolveErr:  map[string]error{},
	}
}

// ResolveUNC : substitue les tokens fictifs <se4fs>/<login> pour les tests.
func (o *fakeDriveOps) ResolveUNC(spec DriveSpec) (string, error) {
	if err := o.resolveErr[spec.UNC]; err != nil {
		return "", err
	}
	unc := strings.ReplaceAll(spec.UNC, "<se4fs>", "SE4FS")
	unc = strings.ReplaceAll(unc, "<login>", "alice")

	return strings.TrimRight(unc, `\`), nil
}

func (o *fakeDriveOps) ListManaged() ([]string, error) {
	out := []string{}
	for letter, m := range o.mapped {
		if m.mapped {
			out = append(out, letter)
		}
	}
	sort.Strings(out)

	return out, nil
}

func (o *fakeDriveOps) Mapped(letter, unc string) (bool, error) {
	m, ok := o.mapped[letter]
	if !ok || !m.mapped {
		return false, nil
	}

	return strings.EqualFold(m.unc, unc), nil
}

func (o *fakeDriveOps) Blocked(letter string) (bool, error) {
	_, blocked := o.userLetters[letter]

	return blocked, nil
}

func (o *fakeDriveOps) Map(letter, unc string) error {
	o.mapCalls++
	o.mapped[letter] = driveMapping{mapped: true, unc: unc}

	return nil
}

func (o *fakeDriveOps) Unmap(letter string) error {
	o.unmapCalls++
	delete(o.mapped, letter)

	return nil
}

// driveItem construit un StateItem `drives`.
func driveItem(letter, unc string) StateItem {
	return StateItem{
		Type:      "drives",
		Semantics: "aggregate",
		Hash:      letter + "-h",
		Payload: map[string]any{
			"letter": letter,
			"unc":    unc,
			"label":  "Classe " + letter,
		},
	}
}

// resolvedUNC : l'UNC tel que résolu par le fake (tokens substitués).
func resolvedUNC(raw string) string {
	unc := strings.ReplaceAll(raw, "<se4fs>", "SE4FS")
	unc = strings.ReplaceAll(unc, "<login>", "alice")

	return strings.TrimRight(unc, `\`)
}

// --- Set cible + idempotence -------------------------------------------------

func TestDrivesApplyMapsTargetSetThenIdempotent(t *testing.T) {
	ops := newFakeDriveOps()
	h := &DrivesHandler{Ops: ops}
	items := []StateItem{
		driveItem("K", `\\<se4fs>\Classe_3A\<login>\`),
		driveItem("L", `\\<se4fs>\Classe_3B\<login>\`),
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply 1: %v", err)
	}
	if ops.mapCalls != 2 {
		t.Fatalf("attendu 2 montages, obtenu %d", ops.mapCalls)
	}
	if !ops.mapped["K:"].mapped || !ops.mapped["L:"].mapped {
		t.Fatalf("les 2 lecteurs auraient dû être montés : %v", ops.mapped)
	}

	ok, err := h.Test(items)
	if err != nil || !ok {
		t.Fatalf("test après apply : ok=%v err=%v (attendu conforme)", ok, err)
	}

	before := ops.mapCalls
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply 2: %v", err)
	}
	if ops.mapCalls != before {
		t.Fatalf("apply idempotent attendu : %d montages supplémentaires", ops.mapCalls-before)
	}
}

// Lettre normalisée : "k", "K", "k:", "K:" → "K:".
func TestDrivesLetterNormalization(t *testing.T) {
	for _, raw := range []string{"k", "K", "k:", "K:"} {
		ops := newFakeDriveOps()
		h := &DrivesHandler{Ops: ops}
		if err := h.Apply([]StateItem{driveItem(raw, `\\<se4fs>\Classe_3A\<login>\`)}); err != nil {
			t.Fatalf("apply %q: %v", raw, err)
		}
		if !ops.mapped["K:"].mapped {
			t.Fatalf("lettre %q aurait dû normaliser en K: ; mapped=%v", raw, ops.mapped)
		}
	}
}

// --- Suppression level-triggered (sortie des règles) -------------------------

func TestDrivesUnmapsManagedDriveDroppedFromRules(t *testing.T) {
	ops := newFakeDriveOps()
	h := &DrivesHandler{Ops: ops}
	full := []StateItem{
		driveItem("K", `\\<se4fs>\Classe_3A\<login>\`),
		driveItem("L", `\\<se4fs>\Classe_3B\<login>\`),
	}
	if err := h.Apply(full); err != nil {
		t.Fatalf("apply full: %v", err)
	}

	reduced := []StateItem{driveItem("K", `\\<se4fs>\Classe_3A\<login>\`)}
	if err := h.Apply(reduced); err != nil {
		t.Fatalf("apply reduced: %v", err)
	}
	if ops.mapped["L:"].mapped {
		t.Fatalf("L: aurait dû être démonté (level-triggered) : %v", ops.mapped)
	}
	if !ops.mapped["K:"].mapped {
		t.Fatalf("K: aurait dû rester")
	}
	if ops.unmapCalls != 1 {
		t.Fatalf("attendu 1 démontage, obtenu %d", ops.unmapCalls)
	}
}

// --- Un montage UTILISATEUR n'est jamais démonté/écrasé ----------------------

func TestDrivesNeverTouchesUserMappedLetter(t *testing.T) {
	ops := newFakeDriveOps()
	// L'utilisateur a monté K: vers un partage personnel (hors périmètre).
	ops.userLetters["K:"] = `\\autreserveur\perso`

	h := &DrivesHandler{Ops: ops}
	items := []StateItem{
		driveItem("K", `\\<se4fs>\Classe_3A\<login>\`), // homonyme bloqué
		driveItem("L", `\\<se4fs>\Classe_3B\<login>\`), // doit converger
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply ne doit pas planter sur un homonyme user : %v", err)
	}
	// K: (user) n'est ni écrasé ni démonté.
	if ops.mapped["K:"].mapped {
		t.Fatalf("K: utilisateur NE doit PAS être remonté par l'agent")
	}
	if ops.unmapCalls != 0 {
		t.Fatalf("aucun lecteur utilisateur ne doit être démonté, unmapCalls=%d", ops.unmapCalls)
	}
	// L: converge quand même.
	if !ops.mapped["L:"].mapped {
		t.Fatalf("L: (hors homonyme) aurait dû converger : %v", ops.mapped)
	}

	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test ne doit pas planter : %v", err)
	}
	if !ok {
		t.Fatalf("test devrait être conforme (homonyme ignoré, L: monté)")
	}
}

// --- Lettre montée vers le mauvais UNC (dérive) → remontée -------------------

func TestDrivesRemapsDivergentLetter(t *testing.T) {
	ops := newFakeDriveOps()
	// K: gérée mais montée vers le mauvais partage.
	ops.mapped["K:"] = driveMapping{mapped: true, unc: resolvedUNC(`\\<se4fs>\Classe_AUTRE\<login>\`)}

	h := &DrivesHandler{Ops: ops}
	items := []StateItem{driveItem("K", `\\<se4fs>\Classe_3A\<login>\`)}

	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test: %v", err)
	}
	if ok {
		t.Fatalf("K: monté vers le mauvais UNC devrait être non conforme")
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	want := resolvedUNC(`\\<se4fs>\Classe_3A\<login>\`)
	if ops.mapped["K:"].unc != want {
		t.Fatalf("K: aurait dû être remonté vers %q, obtenu %q", want, ops.mapped["K:"].unc)
	}
}

// --- Item error isolé : serveur de fichiers injoignable ----------------------

func TestDrivesServerUnreachableIsError(t *testing.T) {
	ops := newFakeDriveOps()
	ops.resolveErr[`\\<se4fs>\Classe_3A\<login>\`] = fmt.Errorf("serveur de fichiers injoignable")
	h := &DrivesHandler{Ops: ops}

	items := []StateItem{driveItem("K", `\\<se4fs>\Classe_3A\<login>\`)}
	if _, err := h.Test(items); err == nil {
		t.Fatalf("serveur injoignable : erreur attendue de Test")
	}
	if err := h.Apply(items); err == nil {
		t.Fatalf("serveur injoignable : erreur attendue de Apply")
	}
}

// --- Payload invalide → error (enveloppe) ------------------------------------

func TestDrivesInvalidPayloadIsError(t *testing.T) {
	ops := newFakeDriveOps()
	h := &DrivesHandler{Ops: ops}

	cases := []struct {
		name    string
		payload map[string]any
	}{
		{"letter vide", map[string]any{"letter": "", "unc": `\\x\y`}},
		{"unc vide", map[string]any{"letter": "K:", "unc": ""}},
		{"lettre invalide (2 chars)", map[string]any{"letter": "KK", "unc": `\\x\y`}},
		{"lettre non alpha", map[string]any{"letter": "1:", "unc": `\\x\y`}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			items := []StateItem{{Type: "drives", Semantics: "aggregate", Hash: "h", Payload: tc.payload}}
			if _, err := h.Test(items); err == nil {
				t.Fatalf("payload invalide attendu en erreur")
			}
		})
	}
}

// --- Machine d'états §5 via le moteur (STRICT inconditionnel, Story 27.8) -----

func TestDrivesThroughEngineSection5(t *testing.T) {
	items := []StateItem{driveItem("K", `\\<se4fs>\Classe_3A\<login>\`)}
	targetHash := AggregateHash(items)

	cases := []struct {
		name        string
		seedManaged bool
		lastApplied string
		wantStatus  string
		wantApply   bool
	}{
		{"premier passage → drift + apply", false, "", "drift", true},
		{"dérive → réapplique (drift)", true, targetHash, "drift", true},
		{"dérive même dernier=cible → réapplique (strict)", true, targetHash, "drift", true},
		{"conforme → compliant", false, targetHash, "compliant", false},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			ops := newFakeDriveOps()
			if tc.seedManaged {
				// Une lettre gérée hors cible (sortie des règles) → réel ≠ cible.
				ops.mapped["Z:"] = driveMapping{mapped: true, unc: resolvedUNC(`\\<se4fs>\Classe_ghost\<login>\`)}
			}
			if tc.name == "conforme → compliant" {
				ops.mapped["K:"] = driveMapping{mapped: true, unc: resolvedUNC(`\\<se4fs>\Classe_3A\<login>\`)}
			}

			h := &DrivesHandler{Ops: ops}
			engine := &Engine{Handlers: map[string]Handler{"drives": h}}
			it := []StateItem{driveItem("K", `\\<se4fs>\Classe_3A\<login>\`)}

			applied := AppliedState{}
			if tc.lastApplied != "" {
				applied["drives"] = AppliedEntry{Hash: tc.lastApplied}
			}

			report := engine.RunPass(it, applied)
			if len(report) != 1 {
				t.Fatalf("attendu 1 item de rapport, obtenu %d", len(report))
			}
			if report[0].Status != tc.wantStatus {
				t.Fatalf("statut = %q, attendu %q", report[0].Status, tc.wantStatus)
			}
			applied2 := ops.mapCalls > 0 || ops.unmapCalls > 0
			if applied2 != tc.wantApply {
				t.Fatalf("apply = %v, attendu %v (map=%d unmap=%d)", applied2, tc.wantApply, ops.mapCalls, ops.unmapCalls)
			}
		})
	}
}

// --- Empreinte d'agrégat stable, ordre serveur (réutilise le moteur) ----------

func TestDrivesAggregateHashIsServerOrderConcat(t *testing.T) {
	items := []StateItem{
		driveItem("K", `\\<se4fs>\Classe_3A\<login>\`),
		driveItem("L", `\\<se4fs>\Classe_3B\<login>\`),
	}
	got := AggregateHash(items)
	if got == "" || len(got) != 64 {
		t.Fatalf("empreinte d'agrégat invalide : %q", got)
	}
	if AggregateHash(items) != got {
		t.Fatalf("empreinte non déterministe")
	}
}
