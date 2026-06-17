package shared

import (
	"fmt"
	"strings"
	"testing"
)

// fakeRegistryOps : RegistryOps en mémoire (testable hôte). Modélise les valeurs
// réelles par identité {hive\path\name} (insensible à la casse) + des erreurs
// d'accès injectables.
type fakeRegistryOps struct {
	values    map[string]RegistryValue // identité → valeur réelle (présente)
	readErr   map[string]error         // identité → erreur de lecture
	writeErr  map[string]error         // identité → erreur d'écriture
	writeCnt  int
	readCnt   int
	notifyCnt int // appels NotifyShellChanged (rafraîchissement shell émis)
}

// NotifyShellChanged : implémente registryNotifier (optionnel) → compte les
// rafraîchissements shell émis par Apply après un changement HKCU.
func (o *fakeRegistryOps) NotifyShellChanged() { o.notifyCnt++ }

func newFakeRegistryOps() *fakeRegistryOps {
	return &fakeRegistryOps{
		values:   map[string]RegistryValue{},
		readErr:  map[string]error{},
		writeErr: map[string]error{},
	}
}

func keyID(hive, path, name string) string {
	return strings.ToLower(hive) + `\` + strings.ToLower(path) + `\` + strings.ToLower(name)
}

func (o *fakeRegistryOps) Read(hive, path, name string) (RegistryValue, bool, error) {
	o.readCnt++
	id := keyID(hive, path, name)
	if err := o.readErr[id]; err != nil {
		return RegistryValue{}, false, err
	}
	v, ok := o.values[id]

	return v, ok, nil
}

func (o *fakeRegistryOps) Write(spec RegistrySpec) error {
	id := keyID(spec.Hive, spec.Path, spec.Name)
	if err := o.writeErr[id]; err != nil {
		return err
	}
	o.writeCnt++
	o.values[id] = spec.Value

	return nil
}

// dwordItem construit un StateItem `registry` REG_DWORD.
func dwordItem(hive, path, name string, value int) StateItem {
	return StateItem{
		Type:      "registry",
		Semantics: "exclusive",
		Hash:      name + "-h",
		Payload: map[string]any{
			"hive":  hive,
			"path":  path,
			"name":  name,
			"type":  "REG_DWORD",
			"value": value,
		},
	}
}

// szItem construit un StateItem `registry` REG_SZ.
func szItem(hive, path, name, value string) StateItem {
	return StateItem{
		Type:      "registry",
		Semantics: "exclusive",
		Hash:      name + "-h",
		Payload: map[string]any{
			"hive":  hive,
			"path":  path,
			"name":  name,
			"type":  "REG_SZ",
			"value": value,
		},
	}
}

// --- Set cible + idempotence -------------------------------------------------

func TestRegistryApplyWritesTargetThenIdempotent(t *testing.T) {
	ops := newFakeRegistryOps()
	h := &RegistryHandler{Ops: ops}
	items := []StateItem{
		dwordItem("HKCU", `Software\Test\Advanced`, "HideFileExt", 0),
		dwordItem("HKLM", `SOFTWARE\Test\System`, "EnableLUA", 0),
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

// --- Drift (valeur réelle ≠ cible) → réapplication ---------------------------

func TestRegistryDriftIsRewritten(t *testing.T) {
	ops := newFakeRegistryOps()
	// La clé existe mais avec une MAUVAISE valeur (1 au lieu de 0).
	ops.values[keyID("HKCU", `Software\Test\Advanced`, "HideFileExt")] = RegistryValue{Kind: "REG_DWORD", Int: 1}
	h := &RegistryHandler{Ops: ops}
	items := []StateItem{dwordItem("HKCU", `Software\Test\Advanced`, "HideFileExt", 0)}

	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test: %v", err)
	}
	if ok {
		t.Fatalf("valeur divergente → devrait être non conforme")
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	got := ops.values[keyID("HKCU", `Software\Test\Advanced`, "HideFileExt")]
	if got.Int != 0 {
		t.Fatalf("valeur aurait dû être réimposée à 0, obtenu %d", got.Int)
	}
}

// --- Clé absente → écriture --------------------------------------------------

func TestRegistryMissingKeyIsWritten(t *testing.T) {
	ops := newFakeRegistryOps()
	h := &RegistryHandler{Ops: ops}
	items := []StateItem{szItem("HKCU", `Software\Test\Run`, "Hello", "world")}

	ok, _ := h.Test(items)
	if ok {
		t.Fatalf("clé absente → devrait être non conforme")
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	got := ops.values[keyID("HKCU", `Software\Test\Run`, "Hello")]
	if got.Str != "world" {
		t.Fatalf("valeur SZ attendue 'world', obtenu %q", got.Str)
	}
}

// --- « Désactiver = cesser de gérer » : un réglage retiré N'EFFACE RIEN -------

func TestRegistryDoesNotRemoveKeysAbsentFromTarget(t *testing.T) {
	ops := newFakeRegistryOps()
	// Une clé gérée précédemment, désormais ABSENTE de la cible.
	ops.values[keyID("HKCU", `Software\Test\Advanced`, "OldSetting")] = RegistryValue{Kind: "REG_DWORD", Int: 1}
	h := &RegistryHandler{Ops: ops}

	// Cible = une AUTRE clé seulement.
	items := []StateItem{dwordItem("HKCU", `Software\Test\Advanced`, "HideFileExt", 0)}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}

	// L'ancienne clé est INTACTE (jamais effacée — piège n° 5).
	old, ok := ops.values[keyID("HKCU", `Software\Test\Advanced`, "OldSetting")]
	if !ok || old.Int != 1 {
		t.Fatalf("clé hors cible NE doit PAS être touchée : %v ok=%v", old, ok)
	}
	// La nouvelle clé est appliquée.
	if ops.values[keyID("HKCU", `Software\Test\Advanced`, "HideFileExt")].Int != 0 {
		t.Fatalf("la clé cible aurait dû être écrite")
	}
}

// --- Erreur isolée + isolation inter-items -----------------------------------

func TestRegistryErrorIsolatedAcrossKeys(t *testing.T) {
	ops := newFakeRegistryOps()
	// La 1re clé (par ordre d'identité) échoue à l'écriture ; les autres doivent
	// converger quand même, l'erreur est remontée (le moteur rend error pour le
	// type, mais les clés saines sont appliquées).
	ops.writeErr[keyID("HKLM", `SOFTWARE\Test\System`, "AAA")] = fmt.Errorf("accès refusé")
	h := &RegistryHandler{Ops: ops}
	items := []StateItem{
		dwordItem("HKLM", `SOFTWARE\Test\System`, "AAA", 0), // échoue
		dwordItem("HKLM", `SOFTWARE\Test\System`, "ZZZ", 1), // doit converger
	}

	err := h.Apply(items)
	if err == nil {
		t.Fatalf("une clé en erreur devrait remonter une erreur d'apply")
	}
	// La clé saine a bien convergé malgré l'échec de l'autre (isolation).
	if ops.values[keyID("HKLM", `SOFTWARE\Test\System`, "ZZZ")].Int != 1 {
		t.Fatalf("la clé saine ZZZ aurait dû converger malgré l'échec de AAA")
	}
}

func TestRegistryReadErrorIsError(t *testing.T) {
	ops := newFakeRegistryOps()
	ops.readErr[keyID("HKLM", `SOFTWARE\Bad`, "X")] = fmt.Errorf("ruche absente")
	h := &RegistryHandler{Ops: ops}
	items := []StateItem{dwordItem("HKLM", `SOFTWARE\Bad`, "X", 0)}

	if _, err := h.Test(items); err == nil {
		t.Fatalf("erreur de lecture : erreur attendue de Test")
	}
	if err := h.Apply(items); err == nil {
		t.Fatalf("erreur de lecture : erreur attendue de Apply")
	}
}

// --- HKLM et HKCU via le MÊME handler (D-Q2) ---------------------------------

func TestRegistryHandlesBothHivesGenerically(t *testing.T) {
	ops := newFakeRegistryOps()
	h := &RegistryHandler{Ops: ops}
	items := []StateItem{
		dwordItem("HKLM", `SOFTWARE\X`, "M", 0),
		dwordItem("HKCU", `Software\Y`, "U", 1),
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.values[keyID("HKLM", `SOFTWARE\X`, "M")].Int != 0 {
		t.Fatalf("clé HKLM non appliquée")
	}
	if ops.values[keyID("HKCU", `Software\Y`, "U")].Int != 1 {
		t.Fatalf("clé HKCU non appliquée")
	}
}

// --- Rafraîchissement shell : émis sur changement HKCU seul ------------------

func TestRegistryShellRefreshOnUserHiveChangeOnly(t *testing.T) {
	t.Run("changement HKCU → notification shell émise (puis idempotente)", func(t *testing.T) {
		ops := newFakeRegistryOps()
		h := &RegistryHandler{Ops: ops}
		items := []StateItem{dwordItem("HKCU", `Software\Test\Advanced`, "Hidden", 1)}

		if err := h.Apply(items); err != nil {
			t.Fatalf("apply: %v", err)
		}
		if ops.notifyCnt != 1 {
			t.Fatalf("changement HKCU : 1 rafraîchissement shell attendu, obtenu %d", ops.notifyCnt)
		}
		// État stable : 2e passe sans écriture → aucune notification de plus.
		if err := h.Apply(items); err != nil {
			t.Fatalf("apply 2: %v", err)
		}
		if ops.notifyCnt != 1 {
			t.Fatalf("état stable : aucune notification supplémentaire attendue, obtenu %d", ops.notifyCnt)
		}
	})

	t.Run("HKLM seul (service, session 0) → aucune notification shell", func(t *testing.T) {
		ops := newFakeRegistryOps()
		h := &RegistryHandler{Ops: ops}
		items := []StateItem{dwordItem("HKLM", `SOFTWARE\Test\System`, "EnableLUA", 0)}

		if err := h.Apply(items); err != nil {
			t.Fatalf("apply: %v", err)
		}
		if ops.notifyCnt != 0 {
			t.Fatalf("HKLM : aucun rafraîchissement shell attendu, obtenu %d", ops.notifyCnt)
		}
	})

	t.Run("déjà conforme → aucune écriture, aucune notification", func(t *testing.T) {
		ops := newFakeRegistryOps()
		ops.values[keyID("HKCU", `Software\Test\Advanced`, "Hidden")] = RegistryValue{Kind: "REG_DWORD", Int: 1}
		h := &RegistryHandler{Ops: ops}
		items := []StateItem{dwordItem("HKCU", `Software\Test\Advanced`, "Hidden", 1)}

		if err := h.Apply(items); err != nil {
			t.Fatalf("apply: %v", err)
		}
		if ops.notifyCnt != 0 {
			t.Fatalf("état déjà conforme : aucune notification attendue, obtenu %d", ops.notifyCnt)
		}
	})
}

// --- Payload invalide → error (enveloppe) ------------------------------------

func TestRegistryInvalidPayloadIsError(t *testing.T) {
	h := &RegistryHandler{Ops: newFakeRegistryOps()}
	cases := []struct {
		name    string
		payload map[string]any
	}{
		{"hive vide", map[string]any{"hive": "", "path": "p", "name": "n", "type": "REG_DWORD", "value": 0}},
		{"path vide", map[string]any{"hive": "HKCU", "path": "", "name": "n", "type": "REG_DWORD", "value": 0}},
		{"name vide", map[string]any{"hive": "HKCU", "path": "p", "name": "", "type": "REG_DWORD", "value": 0}},
		{"type vide", map[string]any{"hive": "HKCU", "path": "p", "name": "n", "type": "", "value": 0}},
		{"type inconnu", map[string]any{"hive": "HKCU", "path": "p", "name": "n", "type": "REG_BINARY", "value": 0}},
		{"dword value non entier", map[string]any{"hive": "HKCU", "path": "p", "name": "n", "type": "REG_DWORD", "value": "x"}},
		{"sz value non string", map[string]any{"hive": "HKCU", "path": "p", "name": "n", "type": "REG_SZ", "value": 5}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			items := []StateItem{{Type: "registry", Semantics: "exclusive", Hash: "h", Payload: tc.payload}}
			if _, err := h.Test(items); err == nil {
				t.Fatalf("payload invalide attendu en erreur")
			}
		})
	}
}

// --- REG_MULTI_SZ : convergence par liste ------------------------------------

func TestRegistryMultiSzConvergence(t *testing.T) {
	ops := newFakeRegistryOps()
	h := &RegistryHandler{Ops: ops}
	items := []StateItem{{
		Type: "registry", Semantics: "exclusive", Hash: "h",
		Payload: map[string]any{
			"hive": "HKCU", "path": `Software\M`, "name": "List", "type": "REG_MULTI_SZ",
			"value": []any{"a", "b"},
		},
	}}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	got := ops.values[keyID("HKCU", `Software\M`, "List")]
	if len(got.Multi) != 2 || got.Multi[0] != "a" || got.Multi[1] != "b" {
		t.Fatalf("MULTI_SZ attendu [a b], obtenu %v", got.Multi)
	}
	// Idempotent.
	before := ops.writeCnt
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply 2: %v", err)
	}
	if ops.writeCnt != before {
		t.Fatalf("MULTI_SZ non idempotent")
	}
}

// --- Machine d'états §5 via le moteur (STRICT inconditionnel) ----------------

func TestRegistryThroughEngineSection5(t *testing.T) {
	target := []StateItem{dwordItem("HKLM", `SOFTWARE\Test\System`, "EnableLUA", 0)}
	targetHash := AggregateHash(target)
	_ = targetHash

	cases := []struct {
		name       string
		seedValue  *int // valeur réelle initiale (nil = absente)
		wantStatus string
		wantApply  bool
	}{
		{"clé absente → drift + apply", nil, "drift", true},
		{"valeur divergente → drift + apply", intPtr(1), "drift", true},
		{"conforme → compliant", intPtr(0), "compliant", false},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			ops := newFakeRegistryOps()
			if tc.seedValue != nil {
				ops.values[keyID("HKLM", `SOFTWARE\Test\System`, "EnableLUA")] = RegistryValue{Kind: "REG_DWORD", Int: int64(*tc.seedValue)}
			}
			h := &RegistryHandler{Ops: ops}
			engine := &Engine{Handlers: map[string]Handler{"registry": h}}

			report := engine.RunPass([]StateItem{dwordItem("HKLM", `SOFTWARE\Test\System`, "EnableLUA", 0)}, AppliedState{})
			if len(report) != 1 {
				t.Fatalf("attendu 1 item de rapport, obtenu %d", len(report))
			}
			if report[0].Status != tc.wantStatus {
				t.Fatalf("statut = %q, attendu %q", report[0].Status, tc.wantStatus)
			}
			if (ops.writeCnt > 0) != tc.wantApply {
				t.Fatalf("apply = %v, attendu %v (writeCnt=%d)", ops.writeCnt > 0, tc.wantApply, ops.writeCnt)
			}
		})
	}
}

func intPtr(i int) *int { return &i }

// --- Fusion par type : registry HKLM + HKCU = UN item au rapport (contrat §6) -

func TestMergeReportItemsByTypeKeepsWorstStatus(t *testing.T) {
	// registry arrive de DEUX portées (machine HKLM compliant + session HKCU
	// drift) : un SEUL item registry au rapport, statut = le plus grave (drift).
	items := []ReportItem{
		{Type: "wallpaper", Status: "compliant", Hash: "w"},
		{Type: "registry", Status: "compliant", Hash: "hklm"}, // machine
		{Type: "registry", Status: "drift", Hash: "hkcu"},     // session
	}
	merged := MergeReportItemsByType(items)
	if len(merged) != 2 {
		t.Fatalf("attendu 2 types uniques, obtenu %d : %+v", len(merged), merged)
	}
	byType := map[string]ReportItem{}
	for _, it := range merged {
		byType[it.Type] = it
	}
	if byType["registry"].Status != "drift" {
		t.Fatalf("registry devrait porter le pire statut (drift), obtenu %q", byType["registry"].Status)
	}
	// Ordre déterministe (types asc).
	if merged[0].Type != "registry" || merged[1].Type != "wallpaper" {
		t.Fatalf("ordre des types non déterministe : %+v", merged)
	}
}

func TestMergeReportItemsByTypeErrorBeatsDrift(t *testing.T) {
	items := []ReportItem{
		{Type: "registry", Status: "drift", Hash: "a"},
		{Type: "registry", Status: "error", Hash: "b", Detail: "boom"},
	}
	merged := MergeReportItemsByType(items)
	if len(merged) != 1 || merged[0].Status != "error" {
		t.Fatalf("error devrait gagner : %+v", merged)
	}
}

func TestMergeReportItemsByTypeNoopOnUniqueTypes(t *testing.T) {
	items := []ReportItem{
		{Type: "drives", Status: "compliant", Hash: "d"},
		{Type: "printers", Status: "drift", Hash: "p"},
	}
	merged := MergeReportItemsByType(items)
	if len(merged) != 2 {
		t.Fatalf("types déjà uniques : aucune fusion attendue, obtenu %+v", merged)
	}
}
