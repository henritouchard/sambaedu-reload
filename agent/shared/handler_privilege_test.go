package shared

import (
	"fmt"
	"reflect"
	"strings"
	"testing"
)

// Tests du handler `privilege` (Story 35.6, contrat §7.9) — fake PrivilegeOps
// en mémoire. Le fake porte AUSSI des titulaires « posés à la main » pour
// prouver la réconciliation de CONTENEUR (D4 : le handler possède la liste
// ENTIÈRE — surnuméraires révoqués) et compte les LookupSid (mémo PAR PASSE,
// piège #7).

// --- Fake PrivilegeOps ---------------------------------------------------------

type fakePrivilegeOps struct {
	// sids : annuaire nom (minuscule) → SID (les comptes résolubles du poste).
	sids map[string]string
	// holders : privilège (minuscule) → set de SID titulaires.
	holders map[string]map[string]bool

	lookupCnt int
	grantCnt  int
	revokeCnt int
}

func newFakePrivilegeOps() *fakePrivilegeOps {
	return &fakePrivilegeOps{
		sids: map[string]string{
			"eleves":  "S-1-5-21-1111-2222-3333-1103",
			"profs":   "S-1-5-21-1111-2222-3333-1104",
			"invites": "S-1-5-21-1111-2222-3333-1105",
			// Principals à LARGE PORTÉE (résolus par un poste réel — locale FR/EN
			// indifférente puisqu'on tranche sur le SID) : un formulaire mal
			// intentionné pourrait les cibler.
			"domain users": "S-1-5-21-1111-2222-3333-513", // RID domaine large
			"everyone":     "S-1-1-0",                     // well-known exact
		},
		holders: map[string]map[string]bool{},
	}
}

func (f *fakePrivilegeOps) LookupSid(name string) (string, error) {
	f.lookupCnt++
	if sid, ok := f.sids[strings.ToLower(name)]; ok {
		return sid, nil
	}

	return "", fmt.Errorf("compte %q inconnu de la LSA", name)
}

func (f *fakePrivilegeOps) AccountsWithPrivilege(privilege string) ([]string, error) {
	out := []string{}
	for sid := range f.holders[strings.ToLower(privilege)] {
		out = append(out, sid)
	}

	return out, nil
}

func (f *fakePrivilegeOps) GrantPrivilege(sid, privilege string) error {
	key := strings.ToLower(privilege)
	if f.holders[key] == nil {
		f.holders[key] = map[string]bool{}
	}
	f.holders[key][sid] = true
	f.grantCnt++

	return nil
}

func (f *fakePrivilegeOps) RevokePrivilege(sid, privilege string) error {
	delete(f.holders[strings.ToLower(privilege)], sid)
	f.revokeCnt++

	return nil
}

func (f *fakePrivilegeOps) holds(privilege, sid string) bool {
	return f.holders[strings.ToLower(privilege)][sid]
}

func (f *fakePrivilegeOps) holderCount(privilege string) int {
	return len(f.holders[strings.ToLower(privilege)])
}

// --- Helpers ------------------------------------------------------------------

const rdpDeny = "SeDenyRemoteInteractiveLogonRight"

func privItem(privilege string, accounts []string) StateItem {
	arr := make([]any, len(accounts))
	for i, a := range accounts {
		arr[i] = a
	}

	return StateItem{
		Type:      "privilege",
		Semantics: "exclusive",
		Hash:      strings.ToLower(privilege) + "-h",
		Payload:   map[string]any{"privilege": privilege, "accounts": arr},
	}
}

// --- (a) accord des manquants + relecture conforme + 2e Apply zéro op ---------

func TestPrivilegeApplyThenIdempotent(t *testing.T) {
	ops := newFakePrivilegeOps()
	h := &PrivilegeHandler{Ops: ops}
	items := []StateItem{privItem(rdpDeny, []string{"Eleves"})}

	ok, err := h.Test(items)
	if err != nil || ok {
		t.Fatalf("titulaire absent → non conforme attendu (ok=%v err=%v)", ok, err)
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if !ops.holds(rdpDeny, "S-1-5-21-1111-2222-3333-1103") {
		t.Fatalf("le SID Eleves aurait dû être accordé")
	}
	if ops.grantCnt != 1 {
		t.Fatalf("1 accord attendu, obtenu %d", ops.grantCnt)
	}

	ok, err = h.Test(items)
	if err != nil || !ok {
		t.Fatalf("après apply : conforme attendu (ok=%v err=%v)", ok, err)
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply 2: %v", err)
	}
	if ops.grantCnt != 1 || ops.revokeCnt != 0 {
		t.Fatalf("apply idempotent attendu : grantCnt=%d revokeCnt=%d", ops.grantCnt, ops.revokeCnt)
	}
}

// --- (b) titulaire retiré à la main ⇒ re-drift STRICT à travers le moteur ------

func TestPrivilegeThroughEngineStrictRedrift(t *testing.T) {
	ops := newFakePrivilegeOps()
	h := &PrivilegeHandler{Ops: ops}
	engine := &Engine{Handlers: map[string]Handler{"privilege": h}}
	target := []StateItem{privItem(rdpDeny, []string{"Eleves"})}

	report := engine.RunPass(target, AppliedState{})
	if len(report) != 1 || report[0].Status != "drift" {
		t.Fatalf("cycle 1 : drift attendu, obtenu %+v", report)
	}
	if ops.grantCnt != 1 {
		t.Fatalf("cycle 1 : 1 accord attendu, obtenu %d", ops.grantCnt)
	}

	// Retrait MANUEL (admin secpol.msc) → re-drift + ré-accord (STRICT).
	_ = ops.RevokePrivilege("S-1-5-21-1111-2222-3333-1103", rdpDeny)
	ops.revokeCnt = 0 // remise à zéro du compteur (op de scénario, pas du handler)
	report = engine.RunPass(target, AppliedState{})
	if len(report) != 1 || report[0].Status != "drift" {
		t.Fatalf("cycle 2 (tampering) : re-drift attendu, obtenu %+v", report)
	}
	if ops.grantCnt != 2 {
		t.Fatalf("cycle 2 : 2 accords cumulés attendus, obtenu %d", ops.grantCnt)
	}

	report = engine.RunPass(target, AppliedState{})
	if len(report) != 1 || report[0].Status != "compliant" {
		t.Fatalf("cycle 3 : compliant attendu, obtenu %+v", report)
	}
	if ops.grantCnt != 2 || ops.revokeCnt != 0 {
		t.Fatalf("cycle 3 : zéro op supplémentaire (grantCnt=%d revokeCnt=%d)", ops.grantCnt, ops.revokeCnt)
	}
}

// --- (c) titulaire surnuméraire (ajouté à la main) ⇒ révocation ---------------

func TestPrivilegeStrayHolderRevoked(t *testing.T) {
	ops := newFakePrivilegeOps()
	// Un admin a accordé le deny à `Profs` À LA MAIN : hors état désiré → le
	// handler possède la liste ENTIÈRE (D4), le surnuméraire est RÉVOQUÉ.
	_ = ops.GrantPrivilege("S-1-5-21-1111-2222-3333-1104", rdpDeny)
	ops.grantCnt = 0
	h := &PrivilegeHandler{Ops: ops}
	items := []StateItem{privItem(rdpDeny, []string{"Eleves"})}

	if ok, _ := h.Test(items); ok {
		t.Fatalf("titulaire surnuméraire → non conforme attendu")
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.holds(rdpDeny, "S-1-5-21-1111-2222-3333-1104") {
		t.Fatalf("le titulaire surnuméraire (Profs) aurait dû être révoqué")
	}
	if !ops.holds(rdpDeny, "S-1-5-21-1111-2222-3333-1103") {
		t.Fatalf("le titulaire désiré (Eleves) aurait dû être accordé")
	}
	if ops.holderCount(rdpDeny) != 1 {
		t.Fatalf("exactement 1 titulaire attendu, obtenu %d", ops.holderCount(rdpDeny))
	}
	if ok, err := h.Test(items); err != nil || !ok {
		t.Fatalf("après réconciliation : conforme attendu (ok=%v err=%v)", ok, err)
	}
}

// --- (d) accounts: [] ⇒ privilège vidé (off réel) ------------------------------

func TestPrivilegeEmptyAccountsEmptiesThePrivilege(t *testing.T) {
	ops := newFakePrivilegeOps()
	h := &PrivilegeHandler{Ops: ops}

	// Armement : Eleves refusé.
	if err := h.Apply([]StateItem{privItem(rdpDeny, []string{"Eleves"})}); err != nil {
		t.Fatalf("apply armement: %v", err)
	}
	// Bascule `off` : MÊME privilège, liste vide → tous les titulaires révoqués.
	off := []StateItem{privItem(rdpDeny, []string{})}
	if ok, _ := h.Test(off); ok {
		t.Fatalf("liste vide vs titulaire présent → drift attendu")
	}
	if err := h.Apply(off); err != nil {
		t.Fatalf("apply off: %v", err)
	}
	if ops.holderCount(rdpDeny) != 0 {
		t.Fatalf("privilège vidé attendu (RDP rétabli au logon suivant), obtenu %d titulaires", ops.holderCount(rdpDeny))
	}
	if ok, err := h.Test(off); err != nil || !ok {
		t.Fatalf("après vidage : compliant attendu (ok=%v err=%v)", ok, err)
	}
}

// --- (e) privilège hors SeDeny* ⇒ erreur d'item ISOLÉE (les autres convergent) -

func TestPrivilegeOutOfAllowlistIsIsolatedItemError(t *testing.T) {
	ops := newFakePrivilegeOps()
	h := &PrivilegeHandler{Ops: ops}
	items := []StateItem{
		// Droit *grant* (verrouillerait la machine) — refus agent (piège #9).
		privItem("SeRemoteInteractiveLogonRight", []string{"Eleves"}),
		// Item SÛR → doit converger malgré le refus isolé.
		privItem(rdpDeny, []string{"Eleves"}),
	}

	if ok, _ := h.Test(items); ok {
		t.Fatalf("un item hors allowlist → non conforme attendu")
	}
	err := h.Apply(items)
	if err == nil {
		t.Fatalf("un privilège hors SeDeny* doit remonter une erreur d'item")
	}
	if ops.holderCount("SeRemoteInteractiveLogonRight") != 0 {
		t.Fatalf("le droit grant n'aurait JAMAIS dû être touché")
	}
	if !ops.holds(rdpDeny, "S-1-5-21-1111-2222-3333-1103") {
		t.Fatalf("l'item SÛR aurait dû converger malgré le refus isolé (effort maximal)")
	}

	// À travers le moteur : verdict `error` pour le type (l'erreur remonte
	// TOUJOURS, grain 27.8).
	engine := &Engine{Handlers: map[string]Handler{"privilege": h}}
	report := engine.RunPass(items, AppliedState{})
	if len(report) != 1 || report[0].Status != "error" {
		t.Fatalf("verdict error attendu pour le type privilege, obtenu %+v", report)
	}
}

// --- (f) compte irrésoluble ⇒ erreur d'item SANS application partielle ---------

func TestPrivilegeUnresolvableAccountNoPartialApplication(t *testing.T) {
	ops := newFakePrivilegeOps()
	h := &PrivilegeHandler{Ops: ops}
	items := []StateItem{
		// `Fantome` est irrésoluble : l'item ENTIER est en erreur — `Eleves`
		// (résoluble, MÊME privilège) ne doit PAS être accordé (piège #8 : un
		// deny partiel laisserait un trou silencieux).
		privItem(rdpDeny, []string{"Eleves", "Fantome"}),
		// Un AUTRE privilège (autre item) converge normalement.
		privItem("SeDenyBatchLogonRight", []string{"Profs"}),
	}

	if ok, _ := h.Test(items); ok {
		t.Fatalf("compte irrésoluble → non conforme attendu")
	}
	err := h.Apply(items)
	if err == nil {
		t.Fatalf("un compte irrésoluble doit remonter une erreur d'item")
	}
	if !strings.Contains(err.Error(), "Fantome") {
		t.Fatalf("l'erreur doit nommer le compte fautif, obtenu : %v", err)
	}
	if ops.holderCount(rdpDeny) != 0 {
		t.Fatalf("AUCUNE application partielle du privilège en erreur (obtenu %d titulaires)", ops.holderCount(rdpDeny))
	}
	if !ops.holds("SeDenyBatchLogonRight", "S-1-5-21-1111-2222-3333-1104") {
		t.Fatalf("l'AUTRE privilège aurait dû converger (effort maximal)")
	}
}

// --- (g) payload invalide ⇒ error pour le type ---------------------------------

func TestPrivilegeInvalidPayloadIsError(t *testing.T) {
	h := &PrivilegeHandler{Ops: newFakePrivilegeOps()}
	cases := []struct {
		name    string
		payload any
	}{
		{"non objet", "texte"},
		{"privilege absent", map[string]any{"accounts": []any{"Eleves"}}},
		{"privilege vide", map[string]any{"privilege": "", "accounts": []any{"Eleves"}}},
		{"accounts absent", map[string]any{"privilege": rdpDeny}},
		{"accounts non liste", map[string]any{"privilege": rdpDeny, "accounts": "Eleves"}},
		{"accounts entrée non string", map[string]any{"privilege": rdpDeny, "accounts": []any{42}}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			items := []StateItem{{Type: "privilege", Semantics: "exclusive", Hash: "h", Payload: tc.payload}}
			if _, err := h.Test(items); err == nil {
				t.Fatalf("payload invalide attendu en erreur de Test")
			}
			if err := h.Apply(items); err == nil {
				t.Fatalf("payload invalide attendu en erreur d'Apply")
			}
		})
	}
}

// --- (h) mémo SID PAR PASSE (compteur du fake, piège #7) -----------------------

func TestPrivilegeSidMemoisedPerPassOnly(t *testing.T) {
	ops := newFakePrivilegeOps()
	h := &PrivilegeHandler{Ops: ops}
	// Le MÊME compte apparaît dans DEUX items : une seule résolution par passe.
	items := []StateItem{
		privItem(rdpDeny, []string{"Eleves"}),
		privItem("SeDenyBatchLogonRight", []string{"Eleves"}),
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.lookupCnt != 1 {
		t.Fatalf("mémo par passe : 1 seule résolution LSA attendue pour 2 items, obtenu %d", ops.lookupCnt)
	}

	// Une NOUVELLE passe re-résout (mémo PAR PASSE seulement, jamais persisté).
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply 2: %v", err)
	}
	if ops.lookupCnt != 2 {
		t.Fatalf("le mémo ne survit pas à la passe : 2 résolutions cumulées attendues, obtenu %d", ops.lookupCnt)
	}
}

// --- (i) AUCUN store (attesté structurellement, piège #2) ----------------------

func TestPrivilegeHandlerHasNoStore(t *testing.T) {
	// D4 : le privilège EST le conteneur (titulaires énumérables via LSA) —
	// contrairement à FsAclHandler (StatePath), le handler ne porte AUCUN champ
	// de store et PrivilegeOps n'expose AUCUNE op de fichier. Attesté
	// structurellement : les champs du handler sont EXACTEMENT {Ops, Log}.
	typ := reflect.TypeOf(PrivilegeHandler{})
	if typ.NumField() != 2 {
		t.Fatalf("PrivilegeHandler doit porter EXACTEMENT 2 champs (Ops, Log — aucun store), obtenu %d", typ.NumField())
	}
	for _, name := range []string{"Ops", "Log"} {
		if _, ok := typ.FieldByName(name); !ok {
			t.Fatalf("champ %s attendu sur PrivilegeHandler", name)
		}
	}
	if _, ok := typ.FieldByName("StatePath"); ok {
		t.Fatalf("PrivilegeHandler ne doit PAS porter de StatePath (conteneur sans store, D4)")
	}
}

// --- (j) compte à LARGE PORTÉE ⇒ erreur d'item SANS application partielle ------

func TestPrivilegeBroadPrincipalRefused(t *testing.T) {
	for _, account := range []string{"Domain Users", "Everyone"} {
		t.Run(account, func(t *testing.T) {
			ops := newFakePrivilegeOps()
			h := &PrivilegeHandler{Ops: ops}
			items := []StateItem{
				// SeDeny* LÉGITIME (passe l'allowlist) mais posée sur un principal
				// large → verrouillerait le poste : erreur d'item, `Eleves` (MÊME
				// item) NON accordé (pas d'application partielle, piège #8).
				privItem(rdpDeny, []string{"Eleves", account}),
				// Un AUTRE privilège sûr converge (effort maximal).
				privItem("SeDenyBatchLogonRight", []string{"Profs"}),
			}

			if ok, _ := h.Test(items); ok {
				t.Fatalf("compte à large portée → non conforme attendu")
			}
			err := h.Apply(items)
			if err == nil {
				t.Fatalf("un compte à large portée doit remonter une erreur d'item")
			}
			if !strings.Contains(strings.ToLower(err.Error()), "large portée") {
				t.Fatalf("l'erreur doit nommer le refus de portée, obtenu : %v", err)
			}
			if ops.holderCount(rdpDeny) != 0 {
				t.Fatalf("AUCUNE application partielle (obtenu %d titulaires)", ops.holderCount(rdpDeny))
			}
			if !ops.holds("SeDenyBatchLogonRight", "S-1-5-21-1111-2222-3333-1104") {
				t.Fatalf("l'AUTRE privilège sûr aurait dû converger (effort maximal)")
			}
		})
	}
}

// --- Dédoublonnage par privilège (dernière occurrence, iso desiredSpecs) -------

func TestPrivilegeDedupByPrivilegeName(t *testing.T) {
	ops := newFakePrivilegeOps()
	h := &PrivilegeHandler{Ops: ops}
	// Deux items MÊME privilège (casse différente) : la DERNIÈRE occurrence
	// fait foi (liste [Profs]).
	items := []StateItem{
		privItem(rdpDeny, []string{"Eleves"}),
		privItem(strings.ToUpper(rdpDeny), []string{"Profs"}),
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.holderCount(rdpDeny) != 1 || !ops.holds(rdpDeny, "S-1-5-21-1111-2222-3333-1104") {
		t.Fatalf("dédoublonnage : la dernière occurrence ([Profs]) doit faire foi")
	}
}
