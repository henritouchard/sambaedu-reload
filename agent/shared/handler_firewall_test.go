package shared

import (
	"strings"
	"testing"
)

// Tests du handler `firewall` (Story 36.2, contrat §7.8) — fake FirewallOps en
// mémoire, portant AUSSI des règles HORS groupe pour prouver qu'elles ne sont
// JAMAIS touchées (D4).

// --- Fake FirewallOps ---------------------------------------------------------

type fakeFirewallOps struct {
	rules   []FwRule // toutes les règles du poste (groupe + hors groupe)
	addCnt  int
	rmCnt   int
	listErr error
}

func newFakeFirewallOps() *fakeFirewallOps { return &fakeFirewallOps{} }

func (f *fakeFirewallOps) ListGroupRules(group string) ([]FwRule, error) {
	if f.listErr != nil {
		return nil, f.listErr
	}
	out := []FwRule{}
	for _, r := range f.rules {
		if strings.EqualFold(r.Grouping, group) {
			out = append(out, r)
		}
	}

	return out, nil
}

func (f *fakeFirewallOps) AddRule(rule FwRule) error {
	f.rules = append(f.rules, rule)
	f.addCnt++

	return nil
}

func (f *fakeFirewallOps) RemoveRule(name string) error {
	kept := f.rules[:0:0]
	removed := false
	for _, r := range f.rules {
		if !removed && strings.EqualFold(r.Name, name) {
			removed = true

			continue
		}
		kept = append(kept, r)
	}
	f.rules = kept
	if removed {
		f.rmCnt++
	}

	return nil
}

func (f *fakeFirewallOps) hasRule(name string) bool {
	for _, r := range f.rules {
		if strings.EqualFold(r.Name, name) {
			return true
		}
	}

	return false
}

func (f *fakeFirewallOps) groupCount() int {
	n := 0
	for _, r := range f.rules {
		if strings.EqualFold(r.Grouping, FirewallRuleGroup) {
			n++
		}
	}

	return n
}

// --- Helpers ------------------------------------------------------------------

func fwItem(ruleID, direction, action, scope, protocol, ensure string, addrs, ports []string) StateItem {
	payload := map[string]any{
		"rule_id":      ruleID,
		"direction":    direction,
		"action":       action,
		"remote_scope": scope,
		"protocol":     protocol,
		"ensure":       ensure,
	}
	if len(addrs) > 0 {
		arr := make([]any, len(addrs))
		for i, a := range addrs {
			arr[i] = a
		}
		payload["remote_addresses"] = arr
	}
	if len(ports) > 0 {
		arr := make([]any, len(ports))
		for i, p := range ports {
			arr[i] = p
		}
		payload["ports"] = arr
	}

	return StateItem{Type: "firewall", Semantics: "exclusive", Hash: ruleID + "-h", Payload: payload}
}

// internetBlock : l'item de la capacité de preuve (`internet_access = off`).
func internetBlock() StateItem {
	return fwItem("internet-block", "out", "block", "internet", "any", "present", nil, nil)
}

const internetBlockName = FirewallRuleGroup + ": internet-block"

// --- (a) pose + relecture conforme + 2e Apply zéro op -------------------------

func TestFirewallApplyThenIdempotent(t *testing.T) {
	ops := newFakeFirewallOps()
	h := &FirewallHandler{Ops: ops}
	items := []StateItem{internetBlock()}

	ok, err := h.Test(items)
	if err != nil || ok {
		t.Fatalf("règle absente → non conforme attendu (ok=%v err=%v)", ok, err)
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if !ops.hasRule(internetBlockName) {
		t.Fatalf("la règle internet-block aurait dû être posée")
	}
	if ops.addCnt != 1 {
		t.Fatalf("1 pose attendue, obtenu %d", ops.addCnt)
	}

	ok, err = h.Test(items)
	if err != nil || !ok {
		t.Fatalf("après apply : conforme attendu (ok=%v err=%v)", ok, err)
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply 2: %v", err)
	}
	if ops.addCnt != 1 || ops.rmCnt != 0 {
		t.Fatalf("apply idempotent attendu : addCnt=%d rmCnt=%d", ops.addCnt, ops.rmCnt)
	}
}

// --- (b) règle gérée supprimée à la main ⇒ re-drift STRICT à travers le moteur -

func TestFirewallThroughEngineStrictRedrift(t *testing.T) {
	ops := newFakeFirewallOps()
	h := &FirewallHandler{Ops: ops}
	engine := &Engine{Handlers: map[string]Handler{"firewall": h}}
	target := []StateItem{internetBlock()}

	report := engine.RunPass(target, AppliedState{})
	if len(report) != 1 || report[0].Status != "drift" {
		t.Fatalf("cycle 1 : drift attendu, obtenu %+v", report)
	}
	if ops.addCnt != 1 {
		t.Fatalf("cycle 1 : 1 pose attendue, obtenu %d", ops.addCnt)
	}

	// Suppression MANUELLE → re-drift + re-pose (STRICT).
	_ = ops.RemoveRule(internetBlockName)
	report = engine.RunPass(target, AppliedState{})
	if len(report) != 1 || report[0].Status != "drift" {
		t.Fatalf("cycle 2 (tampering) : re-drift attendu, obtenu %+v", report)
	}
	if ops.addCnt != 2 {
		t.Fatalf("cycle 2 : 2 poses cumulées attendues, obtenu %d", ops.addCnt)
	}

	report = engine.RunPass(target, AppliedState{})
	if len(report) != 1 || report[0].Status != "compliant" {
		t.Fatalf("cycle 3 : compliant attendu, obtenu %+v", report)
	}
	if ops.addCnt != 2 {
		t.Fatalf("cycle 3 : aucune pose supplémentaire (addCnt=%d)", ops.addCnt)
	}
}

// --- (c) règle étrangère injectée dans le groupe ⇒ supprimée ------------------

func TestFirewallStrayInGroupRemoved(t *testing.T) {
	ops := newFakeFirewallOps()
	// Règle étrangère mais ÉTIQUETÉE de notre groupe (le groupe nous appartient
	// EN ENTIER, D4) → doit être supprimée.
	ops.rules = append(ops.rules, FwRule{Name: FirewallRuleGroup + ": stray", Grouping: FirewallRuleGroup, Direction: "in", Action: "allow", Protocol: "tcp", Enabled: true})
	h := &FirewallHandler{Ops: ops}
	items := []StateItem{internetBlock()}

	if ok, _ := h.Test(items); ok {
		t.Fatalf("règle étrangère dans le groupe → non conforme attendu")
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.hasRule(FirewallRuleGroup + ": stray") {
		t.Fatalf("la règle étrangère du groupe aurait dû être supprimée")
	}
	if !ops.hasRule(internetBlockName) {
		t.Fatalf("la règle désirée aurait dû être posée")
	}
	if ops.groupCount() != 1 {
		t.Fatalf("exactement 1 règle du groupe attendue, obtenu %d", ops.groupCount())
	}
}

// --- (d) bascule present→absent même rule_id ⇒ groupe vidé, compliant ---------

func TestFirewallPresentToAbsentEmptiesGroup(t *testing.T) {
	ops := newFakeFirewallOps()
	h := &FirewallHandler{Ops: ops}

	if err := h.Apply([]StateItem{internetBlock()}); err != nil {
		t.Fatalf("apply present: %v", err)
	}
	if !ops.hasRule(internetBlockName) {
		t.Fatalf("la règle aurait dû être posée")
	}
	// `on`/retrait → MÊME rule_id en ensure:absent → règle retirée, groupe vide.
	absent := []StateItem{fwItem("internet-block", "out", "block", "internet", "any", "absent", nil, nil)}
	if err := h.Apply(absent); err != nil {
		t.Fatalf("apply absent: %v", err)
	}
	if ops.hasRule(internetBlockName) {
		t.Fatalf("la règle aurait dû être retirée (ensure:absent)")
	}
	if ops.groupCount() != 0 {
		t.Fatalf("groupe vide attendu, obtenu %d", ops.groupCount())
	}
	if ok, err := h.Test(absent); err != nil || !ok {
		t.Fatalf("après retrait : compliant attendu (ok=%v err=%v)", ok, err)
	}
}

// --- (e) désir effectif vide (que des absent) ⇒ groupe vidé -------------------

func TestFirewallAllAbsentEmptiesGroup(t *testing.T) {
	ops := newFakeFirewallOps()
	// Deux règles gérées préexistantes dans le groupe.
	ops.rules = append(ops.rules,
		FwRule{Name: FirewallRuleGroup + ": a", Grouping: FirewallRuleGroup, Direction: "out", Action: "block", Protocol: "any", RemoteAddresses: internetRemoteAddresses(), Enabled: true},
		FwRule{Name: FirewallRuleGroup + ": b", Grouping: FirewallRuleGroup, Direction: "out", Action: "block", Protocol: "any", RemoteAddresses: internetRemoteAddresses(), Enabled: true},
	)
	h := &FirewallHandler{Ops: ops}
	items := []StateItem{
		fwItem("a", "out", "block", "internet", "any", "absent", nil, nil),
		fwItem("b", "out", "block", "internet", "any", "absent", nil, nil),
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.groupCount() != 0 {
		t.Fatalf("désir 100%% absent ⇒ groupe vidé, obtenu %d", ops.groupCount())
	}
}

// --- (f) règle non conforme ⇒ Remove+Add (recréation) ------------------------

func TestFirewallNonCompliantRuleRecreated(t *testing.T) {
	ops := newFakeFirewallOps()
	// Règle du groupe au bon nom mais action DIVERGENTE (allow au lieu de block).
	ops.rules = append(ops.rules, FwRule{Name: internetBlockName, Grouping: FirewallRuleGroup, Direction: "out", Action: "allow", Protocol: "any", RemoteAddresses: internetRemoteAddresses(), Enabled: true})
	h := &FirewallHandler{Ops: ops}
	items := []StateItem{internetBlock()}

	if ok, _ := h.Test(items); ok {
		t.Fatalf("règle divergente (allow) → non conforme attendu")
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.rmCnt != 1 || ops.addCnt != 1 {
		t.Fatalf("recréation Remove+Add attendue : rmCnt=%d addCnt=%d", ops.rmCnt, ops.addCnt)
	}
	if ops.groupCount() != 1 {
		t.Fatalf("exactement 1 règle après recréation, obtenu %d", ops.groupCount())
	}
	if ok, err := h.Test(items); err != nil || !ok {
		t.Fatalf("après recréation : conforme attendu (ok=%v err=%v)", ok, err)
	}
}

// --- (g) règles HORS groupe JAMAIS touchées ----------------------------------

func TestFirewallNeverTouchesRulesOutsideGroup(t *testing.T) {
	ops := newFakeFirewallOps()
	foreign := FwRule{Name: "Windows Update", Grouping: "Core Networking", Direction: "in", Action: "allow", Protocol: "tcp", Enabled: true}
	ops.rules = append(ops.rules, foreign)
	h := &FirewallHandler{Ops: ops}

	// Poser puis retirer NOTRE règle : la voisine hors groupe survit aux deux.
	if err := h.Apply([]StateItem{internetBlock()}); err != nil {
		t.Fatalf("apply present: %v", err)
	}
	if !ops.hasRule("Windows Update") {
		t.Fatalf("la règle hors groupe doit survivre à la pose de la nôtre")
	}
	if err := h.Apply([]StateItem{fwItem("internet-block", "out", "block", "internet", "any", "absent", nil, nil)}); err != nil {
		t.Fatalf("apply absent: %v", err)
	}
	if !ops.hasRule("Windows Update") {
		t.Fatalf("la règle hors groupe doit survivre au retrait de la nôtre")
	}
	// Test avec un désir vide : la règle hors groupe n'est même pas énumérée.
	if ok, err := h.Test([]StateItem{}); err != nil || !ok {
		t.Fatalf("désir vide + règle hors groupe = conforme (ok=%v err=%v)", ok, err)
	}
}

// --- (h) traduction internet = chaîne EXACTE figée (IPv4 plages + IPv6) -------

func TestFirewallInternetTranslationIsFrozen(t *testing.T) {
	got := internetRemoteAddresses()
	want := []string{
		"1.0.0.0-9.255.255.255",
		"11.0.0.0-126.255.255.255",
		"128.0.0.0-169.253.255.255",
		"169.255.0.0-172.15.255.255",
		"172.32.0.0-192.167.255.255",
		"192.169.0.0-223.255.255.255",
		"2000::/3",
	}
	if len(got) != len(want) {
		t.Fatalf("traduction internet : %d plages, attendu %d", len(got), len(want))
	}
	for i := range want {
		if got[i] != want[i] {
			t.Errorf("plage internet[%d] : got %q, want %q", i, got[i], want[i])
		}
	}
	// SÛRETÉ Q3 : aucune plage internet ne chevauche une plage protégée.
	for _, spec := range []FirewallSpec{{RuleID: "x", Direction: "out", Action: "block", RemoteScope: "internet", Protocol: "any", Ensure: "present"}} {
		if v := firewallItemViolation(spec); v != "" {
			t.Errorf("un block internet ne doit JAMAIS être refusé (Q3) : %s", v)
		}
	}
}

// --- (i) refus Q3 : block explicit couvrant une plage protégée ---------------

func TestFirewallQ3RefusalInTestAndApply(t *testing.T) {
	forbidden := []string{
		"192.168.0.0/16",  // RFC1918 littéral
		"192.160.0.0/12",  // CIDR englobant sans écrire 192.168
		"0.0.0.0/0",       // /0 v4
		"::/0",            // /0 v6
		"10.0.0.5",        // hôte RFC1918
		"fc00::/7",        // ULA v6
	}
	for _, addr := range forbidden {
		spec := FirewallSpec{RuleID: "rogue", Direction: "out", Action: "block", RemoteScope: "explicit", Protocol: "any", RemoteAddresses: []string{addr}, Ensure: "present"}
		if firewallItemViolation(spec) == "" {
			t.Errorf("block explicit sur %q doit être refusé (Q3)", addr)
		}
	}

	// À travers Test ET Apply, ISOLÉ avec un item SÛR qui converge.
	ops := newFakeFirewallOps()
	h := &FirewallHandler{Ops: ops}
	items := []StateItem{
		fwItem("rogue", "out", "block", "explicit", "any", "present", []string{"192.168.0.0/16"}, nil),
		internetBlock(), // sûr → doit converger
	}

	if ok, _ := h.Test(items); ok {
		t.Fatalf("un item block refusé Q3 → non conforme attendu")
	}
	err := h.Apply(items)
	if err == nil {
		t.Fatalf("un block explicit couvrant le LAN doit remonter une erreur d'item")
	}
	if ops.hasRule(FirewallRuleGroup + ": rogue") {
		t.Fatalf("la règle refusée Q3 n'aurait JAMAIS dû être posée")
	}
	if !ops.hasRule(internetBlockName) {
		t.Fatalf("l'item SÛR aurait dû converger malgré le refus isolé")
	}

	engine := &Engine{Handlers: map[string]Handler{"firewall": h}}
	report := engine.RunPass(items, AppliedState{})
	if len(report) != 1 || report[0].Status != "error" {
		t.Fatalf("verdict error attendu pour le type firewall, obtenu %+v", report)
	}
}

func TestFirewallBlockPublicExplicitAllowed(t *testing.T) {
	// block explicit sur des adresses PUBLIQUES = échappatoire assumée (Q3).
	spec := FirewallSpec{RuleID: "block-proxy", Direction: "out", Action: "block", RemoteScope: "explicit", Protocol: "any", RemoteAddresses: []string{"8.8.8.8", "203.0.113.0/24"}, Ensure: "present"}
	if v := firewallItemViolation(spec); v != "" {
		t.Errorf("block explicit sur des adresses publiques doit être AUTORISÉ : %s", v)
	}
}

// --- (j) adresse non parsable ⇒ erreur d'item --------------------------------

func TestFirewallUnparsableAddressIsItemError(t *testing.T) {
	spec := FirewallSpec{RuleID: "bad", Direction: "out", Action: "block", RemoteScope: "explicit", Protocol: "any", RemoteAddresses: []string{"LocalSubnet"}, Ensure: "present"}
	if firewallItemViolation(spec) == "" {
		t.Fatalf("un mot-clé Windows (non parsable) doit être une erreur d'item")
	}
	ops := newFakeFirewallOps()
	h := &FirewallHandler{Ops: ops}
	items := []StateItem{
		fwItem("bad", "out", "block", "explicit", "any", "present", []string{"LocalSubnet"}, nil),
		internetBlock(),
	}
	if err := h.Apply(items); err == nil {
		t.Fatalf("adresse non parsable : erreur d'item attendue")
	}
	if !ops.hasRule(internetBlockName) {
		t.Fatalf("l'item valide aurait dû converger (effort maximal)")
	}
}

// --- (k) payload invalide ⇒ error pour le type -------------------------------

func TestFirewallInvalidPayloadIsError(t *testing.T) {
	h := &FirewallHandler{Ops: newFakeFirewallOps()}
	cases := []struct {
		name    string
		payload any
	}{
		{"non objet", "texte"},
		{"rule_id absent", map[string]any{"direction": "out", "action": "block", "remote_scope": "internet", "protocol": "any", "ensure": "present"}},
		{"direction inconnue", map[string]any{"rule_id": "x", "direction": "sideways", "action": "block", "remote_scope": "internet", "protocol": "any", "ensure": "present"}},
		{"action inconnue", map[string]any{"rule_id": "x", "direction": "out", "action": "log", "remote_scope": "internet", "protocol": "any", "ensure": "present"}},
		{"scope inconnu", map[string]any{"rule_id": "x", "direction": "out", "action": "block", "remote_scope": "lan", "protocol": "any", "ensure": "present"}},
		{"protocol inconnu", map[string]any{"rule_id": "x", "direction": "out", "action": "block", "remote_scope": "internet", "protocol": "icmp", "ensure": "present"}},
		{"ensure inconnu", map[string]any{"rule_id": "x", "direction": "out", "action": "block", "remote_scope": "internet", "protocol": "any", "ensure": "maybe"}},
		{"ensure absent", map[string]any{"rule_id": "x", "direction": "out", "action": "block", "remote_scope": "internet", "protocol": "any"}},
		{"explicit sans adresses", map[string]any{"rule_id": "x", "direction": "out", "action": "block", "remote_scope": "explicit", "protocol": "any", "ensure": "present"}},
		{"internet avec adresses", map[string]any{"rule_id": "x", "direction": "out", "action": "block", "remote_scope": "internet", "protocol": "any", "ensure": "present", "remote_addresses": []any{"8.8.8.8"}}},
		{"ports avec any", map[string]any{"rule_id": "x", "direction": "out", "action": "block", "remote_scope": "internet", "protocol": "any", "ensure": "present", "ports": []any{"80"}}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			items := []StateItem{{Type: "firewall", Semantics: "exclusive", Hash: "h", Payload: tc.payload}}
			if _, err := h.Test(items); err == nil {
				t.Fatalf("payload invalide attendu en erreur de Test")
			}
			if err := h.Apply(items); err == nil {
				t.Fatalf("payload invalide attendu en erreur d'Apply")
			}
		})
	}
}

// --- (l) normalisation d'écho : CIDR vs masque pointé ⇒ compliant ------------

func TestFirewallEchoNormalizationNoDriftLoop(t *testing.T) {
	ops := newFakeFirewallOps()
	// Règle du groupe posée par nous puis RELUE en forme échoée par Windows :
	// CIDR `192.0.2.0/24` → `192.0.2.0/255.255.255.0`, port relu identique.
	ops.rules = append(ops.rules, FwRule{
		Name:            FirewallRuleGroup + ": proxy",
		Grouping:        FirewallRuleGroup,
		Direction:       "out",
		Action:          "block",
		Protocol:        "tcp",
		RemoteAddresses: []string{"192.0.2.0/255.255.255.0"}, // forme d'écho
		RemotePorts:     []string{"8080"},
		Enabled:         true,
	})
	h := &FirewallHandler{Ops: ops}
	// Le désir porte la forme d'AUTHORING (CIDR) — logiquement identique.
	items := []StateItem{fwItem("proxy", "out", "block", "explicit", "tcp", "present", []string{"192.0.2.0/24"}, []string{"8080"})}

	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test: %v", err)
	}
	if !ok {
		t.Fatalf("CIDR vs masque pointé (forme d'écho) doivent être ÉQUIVALENTS (pas de drift-loop)")
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.addCnt != 0 || ops.rmCnt != 0 {
		t.Fatalf("état stable ⇒ zéro op (addCnt=%d rmCnt=%d)", ops.addCnt, ops.rmCnt)
	}
}

// --- (m) dédoublonnage rule_id (dernière occurrence) -------------------------

func TestFirewallDedupByRuleID(t *testing.T) {
	ops := newFakeFirewallOps()
	h := &FirewallHandler{Ops: ops}
	// Deux items MÊME rule_id : la DERNIÈRE occurrence fait foi (action allow).
	items := []StateItem{
		fwItem("dup", "out", "block", "internet", "any", "present", nil, nil),
		fwItem("dup", "out", "allow", "internet", "any", "present", nil, nil),
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.groupCount() != 1 {
		t.Fatalf("dédoublonnage : 1 seule règle attendue, obtenu %d", ops.groupCount())
	}
	rules, _ := ops.ListGroupRules(FirewallRuleGroup)
	if rules[0].Action != "allow" {
		t.Fatalf("la dernière occurrence (allow) doit faire foi, obtenu %q", rules[0].Action)
	}
}
