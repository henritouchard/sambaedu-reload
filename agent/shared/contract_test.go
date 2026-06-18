package shared

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// --- ParseState ---------------------------------------------------------------

func TestParseStateGoldenFile(t *testing.T) {
	state, err := ParseState(goldenFile(t, "state.v1.json"))
	if err != nil {
		t.Fatalf("ParseState golden : %v", err)
	}

	if state.Schema != ContractSchema {
		t.Errorf("schema : got %q, want %q", state.Schema, ContractSchema)
	}
	if state.GeneratedAt != "2026-06-11T08:00:00+00:00" {
		t.Errorf("generated_at : got %q", state.GeneratedAt)
	}
	if state.TtlSeconds != 3600 {
		t.Errorf("ttl_seconds : got %d, want 3600", state.TtlSeconds)
	}
	// Story 27.2 : portée session = 4 items réels (wallpaper, overlay identity,
	// printers, drives). Story 27.3 : +1 item `registry` (HKCU) → session = 5.
	// Story 27.3bis : +1 item `associations` (HKCU/UserChoice) → session = 6.
	// Story 27.10 : la SALLE passe en portée machine — nouvel item overlay
	// `{kind:"machine", room}` (préchargement poste+salle au logon) → machine = 1.
	// Story 27.4 : +1 item `app_config` (policies.json FF/TB, aggregate). Correctif
	// post-review 2026-06-17 (review #1) : `app_config` est en portée MACHINE
	// (`policies.json` machine-wide, admin-write, écrit par le service SYSTEM ;
	// résolu PAR PARC) → machine = 2, session reste 6.
	// Story 27.5 : +1 item `applications` (aggregate, portée MACHINE — l'agent
	// DÉCLENCHE WPKG, qui installe machine-wide) → machine = 3, session reste 6.
	if len(state.Machine) != 3 || len(state.Session) != 6 || len(state.MachineUser) != 1 {
		t.Errorf("portées : machine=%d session=%d machine_user=%d (attendu 3/6/1)",
			len(state.Machine), len(state.Session), len(state.MachineUser))
	}
}

func TestParseStateRefusesUnknownMajor(t *testing.T) {
	for _, schema := range []string{"se5.desired-state/v2", "se5.desired-state/v99", "autre/v1", ""} {
		raw := []byte(`{"schema":"` + schema + `","generated_at":"x","ttl_seconds":1,"machine":[],"session":[],"machine_user":[]}`)
		if _, err := ParseState(raw); err == nil {
			t.Errorf("schema %q : refus attendu (contrat §9)", schema)
		}
	}
}

func TestParseStateAcceptsMinorVersion(t *testing.T) {
	// Forward-compat §9 : seule la version MAJEURE est discriminante.
	raw := []byte(`{"schema":"se5.desired-state/v1.1","machine":[],"session":[],"machine_user":[]}`)
	if _, err := ParseState(raw); err != nil {
		t.Errorf("v1.1 (mineure) doit être acceptée : %v", err)
	}
}

func TestParseStateIgnoresUnknownFieldsAndMissingScopes(t *testing.T) {
	// Champ ajouté inconnu = ignoré ; portée absente = liste vide jamais nil.
	raw := []byte(`{"schema":"se5.desired-state/v1","nouveau_champ":{"x":1},"session":[{"type":"wallpaper"}]}`)
	state, err := ParseState(raw)
	if err != nil {
		t.Fatalf("ParseState : %v", err)
	}
	if state.Machine == nil || len(state.Machine) != 0 {
		t.Errorf("portée machine absente : liste vide attendue, got %#v", state.Machine)
	}
	if state.MachineUser == nil || len(state.MachineUser) != 0 {
		t.Errorf("portée machine_user absente : liste vide attendue, got %#v", state.MachineUser)
	}
	if len(state.Session) != 1 {
		t.Errorf("session : 1 item attendu, got %d", len(state.Session))
	}
}

func TestParseStateRejectsNonObjectAndInvalidJson(t *testing.T) {
	for _, raw := range []string{`[]`, `"texte"`, `{broken`} {
		if _, err := ParseState([]byte(raw)); err == nil {
			t.Errorf("entrée %q : erreur attendue", raw)
		}
	}
}

func TestParseStateDecodesDebugFlag(t *testing.T) {
	cases := []struct {
		raw  string
		want bool
	}{
		{`{"schema":"se5.desired-state/v1","debug":true,"machine":[],"session":[],"machine_user":[]}`, true},
		{`{"schema":"se5.desired-state/v1","debug":false,"machine":[],"session":[],"machine_user":[]}`, false},
		// Absent (serveur antérieur) → false, jamais d'erreur.
		{`{"schema":"se5.desired-state/v1","machine":[],"session":[],"machine_user":[]}`, false},
	}
	for _, c := range cases {
		state, err := ParseState([]byte(c.raw))
		if err != nil {
			t.Fatalf("ParseState(%s) : %v", c.raw, err)
		}
		if state.Debug != c.want {
			t.Errorf("debug : got %v, want %v (raw=%s)", state.Debug, c.want, c.raw)
		}
	}
}

func TestDebugFromStateCacheFile(t *testing.T) {
	dir := t.TempDir()

	// debug=true dans le cache → true.
	onPath := filepath.Join(dir, "on.json")
	if err := os.WriteFile(onPath, []byte(`{"schema":"se5.desired-state/v1","debug":true,"machine":[],"session":[],"machine_user":[]}`), 0o600); err != nil {
		t.Fatal(err)
	}
	if !DebugFromStateCacheFile(onPath) {
		t.Error("cache debug=true : DebugFromStateCacheFile doit retourner true")
	}

	// debug=false → false.
	offPath := filepath.Join(dir, "off.json")
	if err := os.WriteFile(offPath, []byte(`{"schema":"se5.desired-state/v1","debug":false,"machine":[],"session":[],"machine_user":[]}`), 0o600); err != nil {
		t.Fatal(err)
	}
	if DebugFromStateCacheFile(offPath) {
		t.Error("cache debug=false : DebugFromStateCacheFile doit retourner false")
	}

	// Best-effort : fichier absent → false, jamais de panique.
	if DebugFromStateCacheFile(filepath.Join(dir, "absent.json")) {
		t.Error("cache absent : false attendu")
	}

	// JSON invalide / major inconnu → false.
	badPath := filepath.Join(dir, "bad.json")
	if err := os.WriteFile(badPath, []byte(`{"schema":"se5.desired-state/v2","debug":true}`), 0o600); err != nil {
		t.Fatal(err)
	}
	if DebugFromStateCacheFile(badPath) {
		t.Error("major inconnu : false attendu (cache best-effort)")
	}
}

// --- ValidSchema ----------------------------------------------------------------

func TestValidSchema(t *testing.T) {
	valid := []string{"se5.desired-state/v1", "se5.desired-state/v1.1"}
	invalid := []string{"se5.desired-state/v2", "se5.desired-state/", "v1", "", "se5.desired-state/vX"}

	for _, s := range valid {
		if !ValidSchema(s) {
			t.Errorf("%q devrait être accepté", s)
		}
	}
	for _, s := range invalid {
		if ValidSchema(s) {
			t.Errorf("%q devrait être refusé", s)
		}
	}
}

// --- BuildReport ----------------------------------------------------------------

func TestBuildReportSkeleton(t *testing.T) {
	now := time.Date(2026, 6, 12, 8, 5, 0, 0, time.FixedZone("CEST", 2*3600))

	raw, err := BuildReport("SALLE101-PC03", "F1D2C3B4-A5E6-4789-9ABC-0123456789AB", nil, now)
	if err != nil {
		t.Fatalf("BuildReport : %v", err)
	}

	var got map[string]any
	if err := json.Unmarshal(raw, &got); err != nil {
		t.Fatalf("rapport non décodable : %v", err)
	}

	if got["schema"] != ContractSchema {
		t.Errorf("schema : got %v", got["schema"])
	}
	if got["agent_version"] != Version {
		t.Errorf("agent_version : got %v, want %s (source unique)", got["agent_version"], Version)
	}
	// generated_at : UTC ISO 8601 avec timezone (le now local est converti).
	if got["generated_at"] != "2026-06-12T06:05:00Z" {
		t.Errorf("generated_at : got %v, want 2026-06-12T06:05:00Z", got["generated_at"])
	}

	ws, _ := got["workstation"].(map[string]any)
	if ws["hostname"] != "SALLE101-PC03" {
		t.Errorf("hostname (court, verbatim) : got %v", ws["hostname"])
	}
	if ws["uuid"] != "F1D2C3B4-A5E6-4789-9ABC-0123456789AB" {
		t.Errorf("uuid (SMBIOS verbatim, jamais normalisé côté agent) : got %v", ws["uuid"])
	}

	// items: [] — jamais null (le serveur valide `present`).
	if !strings.Contains(string(raw), `"items":[]`) {
		t.Errorf("items doit être sérialisé [] (jamais null) : %s", raw)
	}
}

func TestBuildReportEmptyUuidAccepted(t *testing.T) {
	// Firmware sans UUID SMBIOS : champ déclaratif, le rapport part quand même
	// (comportement 24.2/24.4 conservé — l'identité réelle est le token).
	raw, err := BuildReport("PC", "", nil, time.Now())
	if err != nil {
		t.Fatalf("uuid vide doit être admis : %v", err)
	}
	if !strings.Contains(string(raw), `"uuid":""`) {
		t.Errorf("uuid vide attendu dans le rapport : %s", raw)
	}
}

func TestBuildReportRejectsEmptyHostname(t *testing.T) {
	if _, err := BuildReport("", "uuid", nil, time.Now()); err == nil {
		t.Error("hostname vide : erreur attendue")
	}
}

func TestBuildReportWithItemsAndDetail(t *testing.T) {
	items := []ReportItem{
		{Type: "wallpaper", Status: "compliant", Hash: strings.Repeat("a", 64)},
		{Type: "printers", Status: "error", Hash: strings.Repeat("b", 64), Detail: "Spooler indisponible"},
	}
	raw, err := BuildReport("PC", "u", items, time.Now())
	if err != nil {
		t.Fatalf("BuildReport : %v", err)
	}
	s := string(raw)
	if !strings.Contains(s, `"detail":"Spooler indisponible"`) {
		t.Errorf("detail attendu : %s", s)
	}
	if strings.Contains(s, `"detail":""`) {
		t.Errorf("detail vide doit être omis (omitempty) : %s", s)
	}
}

// --- Constantes du contrat --------------------------------------------------------

func TestContractConstantsAreFrozen(t *testing.T) {
	if ContractSchema != "se5.desired-state/v1" {
		t.Errorf("ContractSchema modifié : %q — le contrat est FIGÉ (NFR12)", ContractSchema)
	}
	if len(ResourceTypes) != 9 {
		t.Errorf("9 identifiants de type publiés (§7), got %d", len(ResourceTypes))
	}
	// Story 27.8 : `drifted_allowed` retiré → 3 statuts (STRICT inconditionnel).
	if len(ResourceStatuses) != 3 {
		t.Errorf("3 statuts (§6), got %d", len(ResourceStatuses))
	}
}
