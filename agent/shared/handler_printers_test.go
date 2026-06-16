package shared

import (
	"fmt"
	"sort"
	"strings"
	"testing"
)

// fakePrinterOps : PrinterOps en mémoire (testable hôte). `installed` modélise
// les connexions installées par l'agent ; `userConns` les connexions hors
// périmètre (homonymes utilisateur) jamais touchées ; `def` la connexion par
// défaut courante.
type fakePrinterOps struct {
	installed map[string]bool // connexions GÉRÉES installées
	userConns map[string]bool // connexions utilisateur (hors périmètre)
	def       string          // imprimante par défaut courante

	addCalls    int
	removeCalls int
	setDefCalls int
	resolveErr  map[string]error // connexion logique → erreur de résolution
}

func newFakePrinterOps() *fakePrinterOps {
	return &fakePrinterOps{
		installed:  map[string]bool{},
		userConns:  map[string]bool{},
		resolveErr: map[string]error{},
	}
}

// ResolveConnection : substitue un token fictif <se4fs> → "SE4FS" pour les tests
// (la vraie substitution est Windows-only). Erreur injectable (serveur down).
func (o *fakePrinterOps) ResolveConnection(spec PrinterSpec) (string, error) {
	if err := o.resolveErr[spec.Connection]; err != nil {
		return "", err
	}

	return strings.ReplaceAll(spec.Connection, "<se4fs>", "SE4FS"), nil
}

func (o *fakePrinterOps) ListManaged() ([]string, error) {
	out := []string{}
	for conn := range o.installed {
		out = append(out, conn)
	}
	sort.Strings(out)

	return out, nil
}

func (o *fakePrinterOps) Installed(conn string) (bool, error) {
	return o.installed[conn], nil
}

func (o *fakePrinterOps) Blocked(conn string) (bool, error) {
	return o.userConns[conn], nil
}

func (o *fakePrinterOps) Add(conn string) error {
	o.addCalls++
	o.installed[conn] = true

	return nil
}

func (o *fakePrinterOps) Remove(conn string) error {
	o.removeCalls++
	delete(o.installed, conn)

	return nil
}

func (o *fakePrinterOps) DefaultPrinter() (string, error) {
	return o.def, nil
}

func (o *fakePrinterOps) SetDefault(conn string) error {
	o.setDefCalls++
	o.def = conn

	return nil
}

// printerItem construit un StateItem `printers`.
func printerItem(cupsName string, isDefault bool) StateItem {
	return StateItem{
		Type:      "printers",
		Semantics: "aggregate",
		Hash:      cupsName + "-h",
		Payload: map[string]any{
			"cups_name":   cupsName,
			"connection":  `\\<se4fs>\` + cupsName,
			"description": "desc " + cupsName,
			"location":    "loc " + cupsName,
			"is_default":  isDefault,
		},
	}
}

func conn(cupsName string) string { return `\\SE4FS\` + cupsName }

// --- Set cible + idempotence -------------------------------------------------

func TestPrintersApplyInstallsTargetSetThenIdempotent(t *testing.T) {
	ops := newFakePrinterOps()
	h := &PrintersHandler{Ops: ops}
	items := []StateItem{printerItem("imp1", false), printerItem("imp2", false)}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply 1: %v", err)
	}
	if ops.addCalls != 2 {
		t.Fatalf("attendu 2 installations, obtenu %d", ops.addCalls)
	}
	if !ops.installed[conn("imp1")] || !ops.installed[conn("imp2")] {
		t.Fatalf("les 2 imprimantes auraient dû être installées : %v", ops.installed)
	}

	ok, err := h.Test(items)
	if err != nil || !ok {
		t.Fatalf("test après apply : ok=%v err=%v (attendu conforme)", ok, err)
	}

	before := ops.addCalls
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply 2: %v", err)
	}
	if ops.addCalls != before {
		t.Fatalf("apply idempotent attendu : %d installations supplémentaires", ops.addCalls-before)
	}
}

// --- Suppression level-triggered (sortie des règles) -------------------------

func TestPrintersRemovesManagedPrinterDroppedFromRules(t *testing.T) {
	ops := newFakePrinterOps()
	h := &PrintersHandler{Ops: ops}
	full := []StateItem{printerItem("impA", false), printerItem("impB", false)}
	if err := h.Apply(full); err != nil {
		t.Fatalf("apply full: %v", err)
	}
	if len(ops.installed) != 2 {
		t.Fatalf("attendu 2 imprimantes, obtenu %d", len(ops.installed))
	}

	// impB retirée des règles : convergence → impB désinstallée, impA reste.
	reduced := []StateItem{printerItem("impA", false)}
	if err := h.Apply(reduced); err != nil {
		t.Fatalf("apply reduced: %v", err)
	}
	if ops.installed[conn("impB")] {
		t.Fatalf("impB aurait dû être désinstallée (level-triggered) : %v", ops.installed)
	}
	if !ops.installed[conn("impA")] {
		t.Fatalf("impA aurait dû rester")
	}
	if ops.removeCalls != 1 {
		t.Fatalf("attendu 1 désinstallation, obtenu %d", ops.removeCalls)
	}
}

// --- Une imprimante UTILISATEUR n'est jamais désinstallée --------------------

func TestPrintersNeverTouchesUserInstalledPrinter(t *testing.T) {
	ops := newFakePrinterOps()
	// L'utilisateur a installé une imprimante homonyme d'une cible.
	ops.userConns[conn("impA")] = true

	h := &PrintersHandler{Ops: ops}
	items := []StateItem{printerItem("impA", false), printerItem("impB", false)}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply ne doit pas planter sur un homonyme user : %v", err)
	}
	// impA (homonyme user) n'est ni installée par l'agent ni touchée.
	if ops.installed[conn("impA")] {
		t.Fatalf("l'imprimante utilisateur homonyme NE doit PAS être ré-installée par l'agent")
	}
	if ops.removeCalls != 0 {
		t.Fatalf("aucune imprimante utilisateur ne doit être désinstallée, removeCalls=%d", ops.removeCalls)
	}
	// impB converge quand même.
	if !ops.installed[conn("impB")] {
		t.Fatalf("impB (hors homonyme) aurait dû converger : %v", ops.installed)
	}

	// Test conforme : l'homonyme est ignoré, impB installée.
	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test ne doit pas planter : %v", err)
	}
	if !ok {
		t.Fatalf("test devrait être conforme (homonyme ignoré, impB installée)")
	}
}

// --- Imprimante par défaut posée sur l'item marqué --------------------------

func TestPrintersSetsDefaultOnMarkedItem(t *testing.T) {
	ops := newFakePrinterOps()
	h := &PrintersHandler{Ops: ops}
	items := []StateItem{printerItem("imp1", false), printerItem("imp2", true)}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.def != conn("imp2") {
		t.Fatalf("le défaut aurait dû être imp2, obtenu %q", ops.def)
	}
	if ops.setDefCalls != 1 {
		t.Fatalf("attendu 1 SetDefault, obtenu %d", ops.setDefCalls)
	}

	// Idempotence : le défaut est déjà imp2 → pas de réécriture.
	before := ops.setDefCalls
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply 2: %v", err)
	}
	if ops.setDefCalls != before {
		t.Fatalf("SetDefault non idempotent : %d appels supplémentaires", ops.setDefCalls-before)
	}

	// Test conforme quand le défaut correspond.
	ok, err := h.Test(items)
	if err != nil || !ok {
		t.Fatalf("test devrait être conforme : ok=%v err=%v", ok, err)
	}
}

// Défaut change de cible (imp1 → imp2) : convergence repose le défaut.
func TestPrintersDefaultFollowsRuleChange(t *testing.T) {
	ops := newFakePrinterOps()
	h := &PrintersHandler{Ops: ops}

	if err := h.Apply([]StateItem{printerItem("imp1", true), printerItem("imp2", false)}); err != nil {
		t.Fatalf("apply 1: %v", err)
	}
	if ops.def != conn("imp1") {
		t.Fatalf("défaut initial attendu imp1, obtenu %q", ops.def)
	}

	if err := h.Apply([]StateItem{printerItem("imp1", false), printerItem("imp2", true)}); err != nil {
		t.Fatalf("apply 2: %v", err)
	}
	if ops.def != conn("imp2") {
		t.Fatalf("le défaut aurait dû basculer vers imp2, obtenu %q", ops.def)
	}
}

// Décision Henri 27.2 (review F2/M1) : quand l'admin DÉCOCHE le défaut partout
// (plus aucun is_default au payload), l'ancien défaut Windows RESTE en place —
// Windows exige toujours UNE imprimante par défaut, aucune cible naturelle vers
// quoi rebasculer. Comportement figé : ni Apply ni Test ne touchent au défaut
// quand defaultConn == "".
func TestPrintersDefaultRemovedLeavesCurrentInPlace(t *testing.T) {
	ops := newFakePrinterOps()
	h := &PrintersHandler{Ops: ops}

	// imp1 réglé par défaut.
	if err := h.Apply([]StateItem{printerItem("imp1", true), printerItem("imp2", false)}); err != nil {
		t.Fatalf("apply 1: %v", err)
	}
	if ops.def != conn("imp1") {
		t.Fatalf("défaut initial attendu imp1, obtenu %q", ops.def)
	}
	callsBefore := ops.setDefCalls

	// L'admin décoche : plus aucun is_default. Les deux imprimantes restent
	// dans les règles, mais aucune n'est marquée par défaut.
	noDefault := []StateItem{printerItem("imp1", false), printerItem("imp2", false)}

	// Test() ne doit PAS signaler de dérive du fait du défaut résiduel.
	ok, err := h.Test(noDefault)
	if err != nil {
		t.Fatalf("test: %v", err)
	}
	if !ok {
		t.Fatalf("test devrait rester conforme (défaut résiduel non considéré comme dérive)")
	}

	// Apply() ne doit PAS retoucher le défaut Windows : imp1 reste, zéro appel.
	if err := h.Apply(noDefault); err != nil {
		t.Fatalf("apply 2: %v", err)
	}
	if ops.def != conn("imp1") {
		t.Fatalf("l'ancien défaut imp1 aurait dû rester en place, obtenu %q", ops.def)
	}
	if ops.setDefCalls != callsBefore {
		t.Fatalf("aucun SetDefault attendu au décochage, obtenu %d appels supplémentaires", ops.setDefCalls-callsBefore)
	}
}

// --- Item error isolé : serveur d'impression injoignable ---------------------

func TestPrintersServerUnreachableIsErrorIsolated(t *testing.T) {
	ops := newFakePrinterOps()
	ops.resolveErr[`\\<se4fs>\imp1`] = fmt.Errorf("service Spooler indisponible (RPC 0x6ba)")
	h := &PrintersHandler{Ops: ops}

	items := []StateItem{printerItem("imp1", false)}

	// Test ET Apply remontent l'erreur → le moteur la transforme en
	// {status: error} pour le SEUL type printers (les autres types continuent).
	if _, err := h.Test(items); err == nil {
		t.Fatalf("serveur injoignable : erreur attendue de Test")
	}
	if err := h.Apply(items); err == nil {
		t.Fatalf("serveur injoignable : erreur attendue de Apply")
	}
}

// Vérifie l'ISOLATION au niveau moteur : printers en error n'empêche pas drives.
func TestPrintersErrorDoesNotBlockOtherTypes(t *testing.T) {
	pOps := newFakePrinterOps()
	pOps.resolveErr[`\\<se4fs>\imp1`] = fmt.Errorf("Spooler down")
	dOps := newFakeDriveOps()

	engine := &Engine{Handlers: map[string]Handler{
		"printers": &PrintersHandler{Ops: pOps},
		"drives":   &DrivesHandler{Ops: dOps},
	}}

	items := []StateItem{
		printerItem("imp1", false),
		driveItem("K", `\\<se4fs>\Classe_3A\<user>\`),
	}

	report := engine.RunPass(items, AppliedState{})
	if len(report) != 2 {
		t.Fatalf("attendu 2 items de rapport, obtenu %d", len(report))
	}
	byType := map[string]string{}
	for _, r := range report {
		byType[r.Type] = r.Status
	}
	if byType["printers"] != "error" {
		t.Fatalf("printers attendu error, obtenu %q", byType["printers"])
	}
	// drives a convergé malgré l'échec printers (isolation engine.go RunPass).
	if byType["drives"] != "drift" {
		t.Fatalf("drives aurait dû converger (drift au premier passage), obtenu %q", byType["drives"])
	}
	if !dOps.mapped["K:"].mapped {
		t.Fatalf("le lecteur K: aurait dû être monté malgré l'échec printers")
	}
}

// --- Payload invalide → error (enveloppe) ------------------------------------

func TestPrintersInvalidPayloadIsError(t *testing.T) {
	ops := newFakePrinterOps()
	h := &PrintersHandler{Ops: ops}

	cases := []struct {
		name    string
		payload map[string]any
	}{
		{"cups_name vide", map[string]any{"cups_name": "", "connection": `\\x\y`}},
		{"connection vide", map[string]any{"cups_name": "imp1", "connection": ""}},
		{"connection absente", map[string]any{"cups_name": "imp1"}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			items := []StateItem{{Type: "printers", Semantics: "aggregate", Hash: "h", Payload: tc.payload}}
			if _, err := h.Test(items); err == nil {
				t.Fatalf("payload invalide attendu en erreur")
			}
		})
	}
}

// --- Machine d'états §5 via le moteur (STRICT inconditionnel, Story 27.8) -----

func TestPrintersThroughEngineSection5(t *testing.T) {
	items := []StateItem{printerItem("imp1", false)}
	targetHash := AggregateHash(items)

	cases := []struct {
		name        string
		seedManaged bool // une imprimante gérée DIVERGENTE déjà installée
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
			ops := newFakePrinterOps()
			if tc.seedManaged {
				// Une imprimante gérée hors cible (sortie des règles) → réel ≠ cible.
				ops.installed[conn("ghost")] = true
			}
			if tc.name == "conforme → compliant" {
				ops.installed[conn("imp1")] = true
			}

			h := &PrintersHandler{Ops: ops}
			engine := &Engine{Handlers: map[string]Handler{"printers": h}}
			it := []StateItem{printerItem("imp1", false)}

			applied := AppliedState{}
			if tc.lastApplied != "" {
				applied["printers"] = AppliedEntry{Hash: tc.lastApplied}
			}

			report := engine.RunPass(it, applied)
			if len(report) != 1 {
				t.Fatalf("attendu 1 item de rapport, obtenu %d", len(report))
			}
			if report[0].Status != tc.wantStatus {
				t.Fatalf("statut = %q, attendu %q", report[0].Status, tc.wantStatus)
			}
			applied2 := ops.addCalls > 0 || ops.removeCalls > 0
			if applied2 != tc.wantApply {
				t.Fatalf("apply = %v, attendu %v (add=%d remove=%d)", applied2, tc.wantApply, ops.addCalls, ops.removeCalls)
			}
		})
	}
}

// --- Empreinte d'agrégat stable, ordre serveur (réutilise le moteur) ----------

func TestPrintersAggregateHashIsServerOrderConcat(t *testing.T) {
	items := []StateItem{printerItem("imp1", false), printerItem("imp2", true)}
	got := AggregateHash(items)
	if got == "" || len(got) != 64 {
		t.Fatalf("empreinte d'agrégat invalide : %q", got)
	}
	if AggregateHash(items) != got {
		t.Fatalf("empreinte non déterministe")
	}
}
