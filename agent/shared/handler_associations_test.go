package shared

import (
	"fmt"
	"strings"
	"testing"
)

// --- TESTS VECTORIELS DU HASH USERCHOICE (cœur de risque, AC5) ---------------
//
// Les hashes attendus ont été calculés par un PORTAGE INDÉPENDANT de l'algorithme
// `SFTA.ps1::Get-Hash` (référence Python en arithmétique exacte, masquée 32 bits,
// transcrite séparément du code Go). Un triplet figé (identifier, sid, progid,
// dateTimeHex, userExperience) → hash Base64 attendu. Toute divergence Go vs ces
// vecteurs = transcription fautive d'une constante/d'un shift → hash rejeté par
// Windows (bug silencieux). Ces vecteurs VERROUILLENT la fidélité du portage.
//
// experience = chaîne hardcodée de Get-UserExperience (GUID figé de shell32.dll).
// sid/dateTimeHex = valeurs figées représentatives (forme réelle).
//
// ⚠️ PORTÉE DE CES VECTEURS : ils prouvent la fidélité INTER-IMPLÉMENTATION
// (Go ⇄ référence Python, toutes deux transcrites de l'algorithme public
// PS-SFTA), PAS l'acceptation Windows-native. La preuve FINALE (Windows applique
// l'association ET ne l'invalide pas après redémarrage d'Explorer) est déléguée à
// la validation lab Windows (story T9, action humaine — cf. docs/qa/domains/agent.md
// « Story 27.3bis »). Symptôme d'un hash subtilement faux = association non
// appliquée SANS erreur d'écriture côté agent → seul un poste réel le révèle.

const (
	vectorExperience = "User Choice set via Windows User Experience {D18B6DD5-6124-4341-9318-804003BAFA0B}"
	vectorSID        = "s-1-5-21-1234567890-1234567890-1234567890-1001"
	vectorDateTime   = "01d9e8b2c3a40000"
)

func TestUserChoiceHashVectors(t *testing.T) {
	cases := []struct {
		identifier string
		progID     string
		assocType  string
		want       string // calculé par la référence Python indépendante
	}{
		{".pdf", "Acrobat.Document.DC", "file", "h5ZFaFkHaDU="},
		{"http", "FirefoxURL", "protocol", "9RbFZtAB87g="},
		{".html", "FirefoxHTML", "file", "zWoSzvx4Irg="},
	}

	for _, tc := range cases {
		t.Run(tc.identifier, func(t *testing.T) {
			spec := AssociationSpec{Identifier: tc.identifier, ProgID: tc.progID, Type: tc.assocType}
			got := UserChoiceHash(spec, vectorSID, vectorDateTime, vectorExperience)
			if got != tc.want {
				t.Fatalf("hash UserChoice divergent (FIDÉLITÉ ROMPUE) :\n  identifier %q progid %q\n  got  %s\n  want %s",
					tc.identifier, tc.progID, got, tc.want)
			}
		})
	}
}

// Le hash dépend de TOUTES ses entrées : changer un seul champ change le hash.
func TestUserChoiceHashIsSensitiveToInputs(t *testing.T) {
	base := AssociationSpec{Identifier: ".pdf", ProgID: "Acrobat.Document.DC", Type: "file"}
	h0 := UserChoiceHash(base, vectorSID, vectorDateTime, vectorExperience)

	other := AssociationSpec{Identifier: ".pdf", ProgID: "Other.ProgId", Type: "file"}
	if UserChoiceHash(other, vectorSID, vectorDateTime, vectorExperience) == h0 {
		t.Error("le hash ne doit pas être indépendant du ProgId")
	}
	if UserChoiceHash(base, "s-1-5-21-999", vectorDateTime, vectorExperience) == h0 {
		t.Error("le hash ne doit pas être indépendant du SID")
	}
	if UserChoiceHash(base, vectorSID, "01d9000000000000", vectorExperience) == h0 {
		t.Error("le hash ne doit pas être indépendant du dateTime")
	}
}

// --- FAKE OPS ----------------------------------------------------------------

// fakeAssociationsOps : AssociationsOps en mémoire (testable hôte).
type fakeAssociationsOps struct {
	userChoice map[string]string // identifier (lower) → ProgId réel sous UserChoice
	registered map[string]bool   // progid (lower) → enregistré sur le poste
	readErr    map[string]error  // identifier (lower) → erreur de lecture
	writeErr   map[string]error  // identifier (lower) → erreur d'écriture
	regErr     map[string]error  // progid (lower) → erreur de vérification
	inputsErr  error
	writeCnt   int
	deleteSeen map[string]bool // identifier (lower) → WriteUserChoice appelé (= delete+write)
}

func newFakeAssociationsOps() *fakeAssociationsOps {
	return &fakeAssociationsOps{
		userChoice: map[string]string{},
		registered: map[string]bool{},
		readErr:    map[string]error{},
		writeErr:   map[string]error{},
		regErr:     map[string]error{},
		deleteSeen: map[string]bool{},
	}
}

func (o *fakeAssociationsOps) ReadUserChoiceProgID(spec AssociationSpec) (string, bool, error) {
	id := strings.ToLower(spec.Identifier)
	if err := o.readErr[id]; err != nil {
		return "", false, err
	}
	v, ok := o.userChoice[id]

	return v, ok, nil
}

func (o *fakeAssociationsOps) ProgIDRegistered(progID string) (bool, error) {
	p := strings.ToLower(progID)
	if err := o.regErr[p]; err != nil {
		return false, err
	}

	return o.registered[p], nil
}

func (o *fakeAssociationsOps) WriteUserChoice(spec AssociationSpec, hash string) error {
	id := strings.ToLower(spec.Identifier)
	if err := o.writeErr[id]; err != nil {
		return err
	}
	o.writeCnt++
	o.deleteSeen[id] = true
	o.userChoice[id] = spec.ProgID

	return nil
}

func (o *fakeAssociationsOps) SessionInputs() (string, string, string, error) {
	if o.inputsErr != nil {
		return "", "", "", o.inputsErr
	}

	return vectorSID, vectorDateTime, vectorExperience, nil
}

// fileItem / protoItem construisent des StateItem `associations`.
func fileItem(identifier, progID string) StateItem {
	return assocItem(identifier, progID, "file")
}

func protoItem(identifier, progID string) StateItem {
	return assocItem(identifier, progID, "protocol")
}

func assocItem(identifier, progID, assocType string) StateItem {
	return StateItem{
		Type:      "associations",
		Semantics: "exclusive",
		Hash:      identifier + "-h",
		Payload: map[string]any{
			"identifier": identifier,
			"progid":     progID,
			"type":       assocType,
		},
	}
}

// --- Set cible + idempotence -------------------------------------------------

func TestAssociationsApplyWritesTargetThenIdempotent(t *testing.T) {
	ops := newFakeAssociationsOps()
	ops.registered["acrobat.document.dc"] = true
	ops.registered["firefoxurl"] = true
	h := &AssociationsHandler{Ops: ops}
	items := []StateItem{
		fileItem(".pdf", "Acrobat.Document.DC"),
		protoItem("http", "FirefoxURL"),
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply 1: %v", err)
	}
	if ops.writeCnt != 2 {
		t.Fatalf("attendu 2 écritures, obtenu %d", ops.writeCnt)
	}

	ok, err := h.Test(items)
	if err != nil || !ok {
		t.Fatalf("test après apply : ok=%v err=%v (attendu conforme)", ok, err)
	}

	// 2e passe sur état stable : ZÉRO écriture (idempotence).
	before := ops.writeCnt
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply 2: %v", err)
	}
	if ops.writeCnt != before {
		t.Fatalf("apply idempotent attendu : %d écriture(s) supplémentaire(s)", ops.writeCnt-before)
	}
}

// --- Drift (ProgId réel ≠ cible) → réimposition ------------------------------

func TestAssociationsDriftIsRewritten(t *testing.T) {
	ops := newFakeAssociationsOps()
	ops.registered["firefoxhtml"] = true
	// L'identifiant existe mais avec un MAUVAIS ProgId (choix utilisateur dévié).
	ops.userChoice[".html"] = "ChromeHTML"
	h := &AssociationsHandler{Ops: ops}
	items := []StateItem{fileItem(".html", "FirefoxHTML")}

	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test: %v", err)
	}
	if ok {
		t.Fatal("drift attendu (ProgId réel ChromeHTML ≠ cible FirefoxHTML)")
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.userChoice[".html"] != "FirefoxHTML" {
		t.Fatalf("ProgId attendu réimposé FirefoxHTML, obtenu %q", ops.userChoice[".html"])
	}
}

// --- D-Henri n°5 : ProgId absent → choix utilisateur PRÉSERVÉ, error non fatal -

func TestAssociationsProgIdAbsentPreservesUserChoiceAndReportsError(t *testing.T) {
	ops := newFakeAssociationsOps()
	// Acrobat N'EST PAS enregistré ; l'utilisateur a déjà choisi un autre lecteur.
	ops.userChoice[".pdf"] = "SumatraPDF.User"
	// registered[".pdf"-progid] absent → ProgIDRegistered = false.
	h := &AssociationsHandler{Ops: ops}
	items := []StateItem{fileItem(".pdf", "Acrobat.Document.DC")}

	err := h.Apply(items)
	if err == nil {
		t.Fatal("error non fatale attendue (ProgId non enregistré)")
	}
	if !strings.Contains(err.Error(), "non enregistré") || !strings.Contains(err.Error(), "conservé") {
		t.Fatalf("detail attendu explicite (ProgId non enregistré, choix conservé), obtenu : %v", err)
	}
	// AUCUN clobber, AUCUNE suppression-avant-réécriture : choix utilisateur intact.
	if ops.deleteSeen[".pdf"] {
		t.Fatal("la clé UserChoice ne doit PAS être touchée quand le ProgId est absent (pas de clobber)")
	}
	if ops.userChoice[".pdf"] != "SumatraPDF.User" {
		t.Fatalf("choix utilisateur DOIT être préservé, obtenu %q", ops.userChoice[".pdf"])
	}
	if ops.writeCnt != 0 {
		t.Fatalf("aucune écriture attendue (ProgId absent), obtenu %d", ops.writeCnt)
	}
}

// ProgId absent ET aucune écriture en boucle même après plusieurs passes.
func TestAssociationsProgIdAbsentDoesNotLoopRewrite(t *testing.T) {
	ops := newFakeAssociationsOps()
	h := &AssociationsHandler{Ops: ops}
	items := []StateItem{fileItem(".pdf", "Acrobat.Document.DC")}

	for i := 0; i < 3; i++ {
		_ = h.Apply(items)
	}
	if ops.writeCnt != 0 {
		t.Fatalf("pas de réécriture en boucle d'un défaut inapplicable, obtenu %d écritures", ops.writeCnt)
	}
}

// --- Isolation par item : une erreur n'empêche pas les autres de converger ----

func TestAssociationsErrorIsolationBestEffort(t *testing.T) {
	ops := newFakeAssociationsOps()
	ops.registered["acrobat.document.dc"] = true
	ops.registered["firefoxurl"] = true
	// L'écriture de .pdf échoue ; http doit quand même converger.
	ops.writeErr[".pdf"] = fmt.Errorf("clé verrouillée")
	h := &AssociationsHandler{Ops: ops}
	items := []StateItem{
		fileItem(".pdf", "Acrobat.Document.DC"),
		protoItem("http", "FirefoxURL"),
	}

	err := h.Apply(items)
	if err == nil {
		t.Fatal("erreur attendue (écriture .pdf en échec)")
	}
	// http a convergé malgré l'échec de .pdf (effort maximal).
	if ops.userChoice["http"] != "FirefoxURL" {
		t.Fatalf("http devait converger malgré l'échec de .pdf, obtenu %q", ops.userChoice["http"])
	}
}

// --- Enveloppe invalide → erreur (le moteur rapporte error pour le type) ------

func TestAssociationsInvalidPayloadIsError(t *testing.T) {
	ops := newFakeAssociationsOps()
	h := &AssociationsHandler{Ops: ops}
	bad := StateItem{Type: "associations", Semantics: "exclusive", Hash: "x", Payload: map[string]any{"identifier": ".pdf"}}

	if _, err := h.Test([]StateItem{bad}); err == nil {
		t.Error("Test : enveloppe invalide (progid/type manquants) doit être une erreur")
	}
	if err := h.Apply([]StateItem{bad}); err == nil {
		t.Error("Apply : enveloppe invalide doit être une erreur")
	}
}

// --- Fichier vs protocole : les deux types convergent ------------------------

func TestAssociationsFileAndProtocolBothConverge(t *testing.T) {
	ops := newFakeAssociationsOps()
	ops.registered["firefoxhtml"] = true
	ops.registered["firefoxurl"] = true
	h := &AssociationsHandler{Ops: ops}
	items := []StateItem{
		fileItem(".html", "FirefoxHTML"),
		protoItem("https", "FirefoxURL"),
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.userChoice[".html"] != "FirefoxHTML" {
		t.Errorf("association fichier non appliquée : %q", ops.userChoice[".html"])
	}
	if ops.userChoice["https"] != "FirefoxURL" {
		t.Errorf("association protocole non appliquée : %q", ops.userChoice["https"])
	}
}

// --- Identifiant non géré (aucun item) = no-op --------------------------------

func TestAssociationsEmptyItemsNoOp(t *testing.T) {
	ops := newFakeAssociationsOps()
	h := &AssociationsHandler{Ops: ops}

	ok, err := h.Test(nil)
	if err != nil || !ok {
		t.Fatalf("aucun item = conforme (rien à gérer) : ok=%v err=%v", ok, err)
	}
	if err := h.Apply(nil); err != nil {
		t.Fatalf("aucun item = no-op : %v", err)
	}
	if ops.writeCnt != 0 {
		t.Fatalf("aucune écriture attendue, obtenu %d", ops.writeCnt)
	}
}
