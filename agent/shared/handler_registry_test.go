package shared

import (
	"fmt"
	"sort"
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
	deleteErr map[string]error         // identité → erreur de suppression
	// namesErr : identité de CONTENEUR {hive\path} → erreur d'énumération
	// (Story 35.2, RegistryOps.ValueNames).
	namesErr  map[string]error
	writeCnt  int
	readCnt   int
	deleteCnt int // appels Delete EFFECTIFS (valeur supprimée)
	namesCnt  int // appels ValueNames
	notifyCnt int // appels NotifyShellChanged (rafraîchissement shell émis)
}

// NotifyShellChanged : implémente registryNotifier (optionnel) → compte les
// rafraîchissements shell émis par Apply après un changement HKCU.
func (o *fakeRegistryOps) NotifyShellChanged() { o.notifyCnt++ }

func newFakeRegistryOps() *fakeRegistryOps {
	return &fakeRegistryOps{
		values:    map[string]RegistryValue{},
		readErr:   map[string]error{},
		writeErr:  map[string]error{},
		deleteErr: map[string]error{},
		namesErr:  map[string]error{},
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

// Delete : supprime la valeur nommée. Iso impl Windows : une valeur déjà
// absente ⇒ nil (idempotent) SANS compter comme une suppression effective.
func (o *fakeRegistryOps) Delete(hive, path, name string) error {
	id := keyID(hive, path, name)
	if err := o.deleteErr[id]; err != nil {
		return err
	}
	if _, ok := o.values[id]; !ok {
		return nil // déjà absente (idempotent)
	}
	o.deleteCnt++
	delete(o.values, id)

	return nil
}

// ValueNames : énumère les noms des valeurs d'une clé (Story 35.2). Iso impl
// Windows : clé sans aucune valeur ⇒ (nil, nil), jamais une erreur. Les noms
// rendus sont en minuscules (le fake indexe par identité insensible à la
// casse) — sans incidence : les noms possédés par la réconciliation de liste
// sont NUMÉRIQUES (casse-invariants) et la comparaison de canon est stricte.
// Tri pour un ordre déterministe.
func (o *fakeRegistryOps) ValueNames(hive, path string) ([]string, error) {
	o.namesCnt++
	container := strings.ToLower(hive) + `\` + strings.ToLower(path)
	if err := o.namesErr[container]; err != nil {
		return nil, err
	}
	prefix := container + `\`
	names := []string{}
	for id := range o.values {
		if !strings.HasPrefix(id, prefix) {
			continue
		}
		name := id[len(prefix):]
		if strings.Contains(name, `\`) {
			continue // valeur d'une SOUS-clé, pas de ce conteneur
		}
		names = append(names, name)
	}
	sort.Strings(names)
	if len(names) == 0 {
		return nil, nil
	}

	return names, nil
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
		// Story 35.2 : `name: ""` n'est PLUS invalide (valeur PAR DÉFAUT de la
		// clé, contrat §7.1) — c'est l'ABSENCE de la clé `name` qui l'est.
		{"name absent", map[string]any{"hive": "HKCU", "path": "p", "type": "REG_DWORD", "value": 0}},
		{"name non-string", map[string]any{"hive": "HKCU", "path": "p", "name": 3, "type": "REG_DWORD", "value": 0}},
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

// --- Verbe `ensure:"absent"` (Story 35.1) : convergence du delete ------------

// absentItem construit un StateItem `registry` de SUPPRESSION (payload 4 clés,
// ni type ni value — contrat §7.1).
func absentItem(hive, path, name string) StateItem {
	return StateItem{
		Type:      "registry",
		Semantics: "exclusive",
		Hash:      name + "-h",
		Payload: map[string]any{
			"hive":   hive,
			"path":   path,
			"name":   name,
			"ensure": "absent",
		},
	}
}

func TestRegistryAbsentDeletesPresentValueThenIdempotent(t *testing.T) {
	// (a) valeur présente ⇒ Test false, Apply supprime, re-Test true, 2e Apply
	// = zéro opération (idempotence).
	ops := newFakeRegistryOps()
	ops.values[keyID("HKLM", `SOFTWARE\Policies\DNSClient`, "EnableMulticast")] = RegistryValue{Kind: "REG_DWORD", Int: 0}
	h := &RegistryHandler{Ops: ops}
	items := []StateItem{absentItem("HKLM", `SOFTWARE\Policies\DNSClient`, "EnableMulticast")}

	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test: %v", err)
	}
	if ok {
		t.Fatalf("valeur présente + ensure:absent → devrait être non conforme")
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.deleteCnt != 1 {
		t.Fatalf("attendu 1 suppression, obtenu %d", ops.deleteCnt)
	}
	if _, still := ops.values[keyID("HKLM", `SOFTWARE\Policies\DNSClient`, "EnableMulticast")]; still {
		t.Fatalf("la valeur aurait dû être supprimée")
	}

	ok, err = h.Test(items)
	if err != nil || !ok {
		t.Fatalf("test après suppression : ok=%v err=%v (attendu conforme)", ok, err)
	}

	// 2e passe sur état stable : ZÉRO suppression/écriture supplémentaire.
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply 2: %v", err)
	}
	if ops.deleteCnt != 1 || ops.writeCnt != 0 {
		t.Fatalf("apply idempotent attendu : deleteCnt=%d writeCnt=%d", ops.deleteCnt, ops.writeCnt)
	}
}

func TestRegistryAbsentValueAlreadyGoneIsCompliant(t *testing.T) {
	// (b) valeur absente ⇒ compliant, aucune écriture ni suppression.
	ops := newFakeRegistryOps()
	h := &RegistryHandler{Ops: ops}
	items := []StateItem{absentItem("HKLM", `SOFTWARE\Policies\DNSClient`, "EnableMulticast")}

	ok, err := h.Test(items)
	if err != nil || !ok {
		t.Fatalf("valeur déjà absente : conforme attendu (ok=%v err=%v)", ok, err)
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.deleteCnt != 0 || ops.writeCnt != 0 {
		t.Fatalf("aucune opération attendue : deleteCnt=%d writeCnt=%d", ops.deleteCnt, ops.writeCnt)
	}
}

func TestRegistryAbsentDeletesValueOfUnmanagedKind(t *testing.T) {
	// Review 35.1 #1 : une valeur EXISTANTE d'un type hors contrat (REG_BINARY,
	// REG_NONE, … — impl Windows : present=true, Kind sentinelle) est une dérive
	// pour un item `ensure:"absent"` : Test false, Apply la SUPPRIME (AC3 « peu
	// importe son type/contenu » — jamais de résidu rapporté compliant).
	ops := newFakeRegistryOps()
	ops.values[keyID("HKLM", `SOFTWARE\Policies\DNSClient`, "EnableMulticast")] =
		RegistryValue{Kind: "REG_UNSUPPORTED"}
	h := &RegistryHandler{Ops: ops}

	items := []StateItem{absentItem("HKLM", `SOFTWARE\Policies\DNSClient`, "EnableMulticast")}

	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test: %v", err)
	}
	if ok {
		t.Fatalf("valeur présente (type non géré) : l'item absent doit être NON conforme")
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.deleteCnt != 1 {
		t.Fatalf("la valeur de type non géré doit être supprimée (1 delete), obtenu %d", ops.deleteCnt)
	}

	ok, err = h.Test(items)
	if err != nil {
		t.Fatalf("re-test: %v", err)
	}
	if !ok {
		t.Fatalf("après suppression, l'item absent doit être conforme")
	}
}

func TestRegistryAbsentThroughEngineStrictRedrift(t *testing.T) {
	// (c) policy STRICT via le moteur (engine.go INTOUCHÉ, iso
	// TestRegistryThroughEngineSection5) : valeur présente ⇒ drift + suppression ;
	// RÉAPPARUE au cycle suivant ⇒ re-drift + re-suppression ; ensuite compliant.
	ops := newFakeRegistryOps()
	id := keyID("HKLM", `SOFTWARE\Policies\DNSClient`, "EnableMulticast")
	ops.values[id] = RegistryValue{Kind: "REG_DWORD", Int: 0}
	h := &RegistryHandler{Ops: ops}
	engine := &Engine{Handlers: map[string]Handler{"registry": h}}
	target := []StateItem{absentItem("HKLM", `SOFTWARE\Policies\DNSClient`, "EnableMulticast")}

	// Cycle 1 : présente → drift + suppression.
	report := engine.RunPass(target, AppliedState{})
	if len(report) != 1 || report[0].Status != "drift" {
		t.Fatalf("cycle 1 : drift attendu, obtenu %+v", report)
	}
	if ops.deleteCnt != 1 {
		t.Fatalf("cycle 1 : 1 suppression attendue, obtenu %d", ops.deleteCnt)
	}

	// La valeur RÉAPPARAÎT (autre outil / utilisateur) → re-drift au cycle 2.
	ops.values[id] = RegistryValue{Kind: "REG_SZ", Str: "revenant"}
	report = engine.RunPass(target, AppliedState{})
	if len(report) != 1 || report[0].Status != "drift" {
		t.Fatalf("cycle 2 (réapparition) : re-drift attendu, obtenu %+v", report)
	}
	if ops.deleteCnt != 2 {
		t.Fatalf("cycle 2 : 2 suppressions cumulées attendues, obtenu %d", ops.deleteCnt)
	}

	// Cycle 3 : stable → compliant, zéro op.
	report = engine.RunPass(target, AppliedState{})
	if len(report) != 1 || report[0].Status != "compliant" {
		t.Fatalf("cycle 3 : compliant attendu, obtenu %+v", report)
	}
	if ops.deleteCnt != 2 {
		t.Fatalf("cycle 3 : aucune suppression supplémentaire attendue, obtenu %d", ops.deleteCnt)
	}
}

func TestRegistryMixedWriteAndDeleteWithErrorIsolation(t *testing.T) {
	// (d) items écrire + supprimer dans une MÊME passe ; une suppression en
	// échec n'empêche ni les écritures ni les autres suppressions (effort
	// maximal), l'erreur est remontée.
	ops := newFakeRegistryOps()
	ops.values[keyID("HKLM", `SOFTWARE\Test`, "DeleteMe")] = RegistryValue{Kind: "REG_DWORD", Int: 1}
	ops.values[keyID("HKLM", `SOFTWARE\Test`, "Protected")] = RegistryValue{Kind: "REG_DWORD", Int: 1}
	ops.deleteErr[keyID("HKLM", `SOFTWARE\Test`, "Protected")] = fmt.Errorf("accès refusé")
	h := &RegistryHandler{Ops: ops}
	items := []StateItem{
		absentItem("HKLM", `SOFTWARE\Test`, "Protected"), // échoue
		absentItem("HKLM", `SOFTWARE\Test`, "DeleteMe"),  // doit être supprimée
		dwordItem("HKLM", `SOFTWARE\Test`, "WriteMe", 7), // doit être écrite
	}

	err := h.Apply(items)
	if err == nil {
		t.Fatalf("une suppression en erreur devrait remonter une erreur d'apply")
	}
	if _, still := ops.values[keyID("HKLM", `SOFTWARE\Test`, "DeleteMe")]; still {
		t.Fatalf("DeleteMe aurait dû être supprimée malgré l'échec de Protected")
	}
	if ops.values[keyID("HKLM", `SOFTWARE\Test`, "WriteMe")].Int != 7 {
		t.Fatalf("WriteMe aurait dû être écrite malgré l'échec de Protected")
	}
}

func TestRegistryAbsentShellRefreshOnEffectiveHkcuDeleteOnly(t *testing.T) {
	// (e) même gate que l'écriture : suppression HKCU EFFECTIVE ⇒ notification
	// shell ; suppression no-op (déjà absente) ou HKLM ⇒ aucune notification.
	t.Run("delete HKCU effectif → notification shell", func(t *testing.T) {
		ops := newFakeRegistryOps()
		ops.values[keyID("HKCU", `Software\Test\Policies`, "NoDrives")] = RegistryValue{Kind: "REG_DWORD", Int: 4}
		h := &RegistryHandler{Ops: ops}

		if err := h.Apply([]StateItem{absentItem("HKCU", `Software\Test\Policies`, "NoDrives")}); err != nil {
			t.Fatalf("apply: %v", err)
		}
		if ops.notifyCnt != 1 {
			t.Fatalf("suppression HKCU effective : 1 rafraîchissement shell attendu, obtenu %d", ops.notifyCnt)
		}
	})

	t.Run("delete no-op (déjà absente) → pas de notification", func(t *testing.T) {
		ops := newFakeRegistryOps()
		h := &RegistryHandler{Ops: ops}

		if err := h.Apply([]StateItem{absentItem("HKCU", `Software\Test\Policies`, "NoDrives")}); err != nil {
			t.Fatalf("apply: %v", err)
		}
		if ops.notifyCnt != 0 {
			t.Fatalf("no-op : aucune notification attendue, obtenu %d", ops.notifyCnt)
		}
	})

	t.Run("delete HKLM effectif (service, session 0) → pas de notification", func(t *testing.T) {
		ops := newFakeRegistryOps()
		ops.values[keyID("HKLM", `SOFTWARE\Test`, "M")] = RegistryValue{Kind: "REG_DWORD", Int: 1}
		h := &RegistryHandler{Ops: ops}

		if err := h.Apply([]StateItem{absentItem("HKLM", `SOFTWARE\Test`, "M")}); err != nil {
			t.Fatalf("apply: %v", err)
		}
		if ops.notifyCnt != 0 {
			t.Fatalf("HKLM : aucune notification attendue, obtenu %d", ops.notifyCnt)
		}
	})
}

func TestRegistryEnsureInvalidOrIncompletePayloadIsError(t *testing.T) {
	// (f) `ensure` de valeur inconnue / non-string, item absent incomplet ⇒
	// enveloppe invalide ⇒ error pour le type (comportement existant).
	h := &RegistryHandler{Ops: newFakeRegistryOps()}
	cases := []struct {
		name    string
		payload map[string]any
	}{
		{"ensure inconnu", map[string]any{"hive": "HKLM", "path": "p", "name": "n", "ensure": "gone"}},
		{"ensure vide", map[string]any{"hive": "HKLM", "path": "p", "name": "n", "ensure": ""}},
		{"ensure non-string", map[string]any{"hive": "HKLM", "path": "p", "name": "n", "ensure": 1}},
		{"absent sans hive", map[string]any{"hive": "", "path": "p", "name": "n", "ensure": "absent"}},
		{"absent sans path", map[string]any{"hive": "HKLM", "path": "", "name": "n", "ensure": "absent"}},
		// Story 35.2 : `name: ""` = valeur par défaut (LÉGITIME) — seule
		// l'ABSENCE de la clé `name` reste une enveloppe invalide.
		{"absent sans clé name", map[string]any{"hive": "HKLM", "path": "p", "ensure": "absent"}},
		{"present sans type ni value", map[string]any{"hive": "HKLM", "path": "p", "name": "n", "ensure": "present"}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			items := []StateItem{{Type: "registry", Semantics: "exclusive", Hash: "h", Payload: tc.payload}}
			if _, err := h.Test(items); err == nil {
				t.Fatalf("payload invalide attendu en erreur")
			}
			if err := h.Apply(items); err == nil {
				t.Fatalf("payload invalide attendu en erreur d'apply")
			}
		})
	}
}

func TestRegistryEnsurePresentExplicitBehavesAsWriteItem(t *testing.T) {
	// (g) `ensure:"present"` explicite ≡ item d'écriture classique (le serveur
	// ne l'émet jamais — byte-identité — mais le contrat l'admet).
	ops := newFakeRegistryOps()
	h := &RegistryHandler{Ops: ops}
	items := []StateItem{{
		Type: "registry", Semantics: "exclusive", Hash: "h",
		Payload: map[string]any{
			"hive": "HKCU", "path": `Software\Test`, "name": "K",
			"type": "REG_DWORD", "value": 3, "ensure": "present",
		},
	}}

	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test: %v", err)
	}
	if ok {
		t.Fatalf("valeur absente → non conforme attendu")
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.values[keyID("HKCU", `Software\Test`, "K")].Int != 3 {
		t.Fatalf("la valeur aurait dû être écrite (ensure:present ≡ écriture)")
	}
	if ops.deleteCnt != 0 {
		t.Fatalf("aucune suppression attendue pour un item present")
	}
}

// --- Valeur PAR DÉFAUT d'une clé : `name: ""` (Story 35.2, scope 35.5) -------
//
// `""` est le nom LÉGITIME de la valeur par défaut d'une clé Windows
// (`(Default)` dans regedit) : les API registre (RegQueryValueEx/RegSetValueEx/
// RegDeleteValue via golang.org/x/sys/windows/registry) traitent un nom vide
// comme cette valeur — Get/Set/DeleteValue("") la ciblent, aucun cas
// particulier côté handler. Besoin réel : 35.5 pose
// `Applications\photoviewer.dll\shell\open\command` (default value).

func TestRegistryDefaultValueNameParsesWriteAndAbsent(t *testing.T) {
	// (a) parse : `name` PRÉSENT et vide = valide, en écriture ET en absent.
	write, ok := parseRegistrySpec(map[string]any{
		"hive": "HKCU", "path": `Software\Classes\Applications\pv.dll\shell\open\command`,
		"name": "", "type": "REG_EXPAND_SZ", "value": `%SystemRoot%\pv.dll %1`,
	})
	if !ok {
		t.Fatalf("name \"\" (valeur par défaut) doit parser en item d'écriture")
	}
	if write.Name != "" || write.Value.Kind != "REG_EXPAND_SZ" {
		t.Fatalf("spec inattendu : %+v", write)
	}

	absent, ok := parseRegistrySpec(map[string]any{
		"hive": "HKCU", "path": `Software\Classes\X`, "name": "", "ensure": "absent",
	})
	if !ok || !absent.absent() {
		t.Fatalf("name \"\" doit parser en item absent (ok=%v spec=%+v)", ok, absent)
	}

	// (b) l'ABSENCE de la clé `name` reste une enveloppe invalide.
	if _, ok := parseRegistrySpec(map[string]any{
		"hive": "HKCU", "path": "p", "type": "REG_SZ", "value": "v",
	}); ok {
		t.Fatalf("payload sans clé name : enveloppe invalide attendue")
	}
}

func TestRegistryDefaultValueNameConvergesTestApplyDelete(t *testing.T) {
	// (c) Test/Apply écrivent la valeur par défaut via le fake (Ops reçoit
	// name "" tel quel), puis un item absent la SUPPRIME.
	ops := newFakeRegistryOps()
	h := &RegistryHandler{Ops: ops}
	path := `Software\Classes\Applications\pv.dll\shell\open\command`
	writeItems := []StateItem{{
		Type: "registry", Semantics: "exclusive", Hash: "h",
		Payload: map[string]any{
			"hive": "HKCU", "path": path, "name": "",
			"type": "REG_SZ", "value": "cmd %1",
		},
	}}

	ok, err := h.Test(writeItems)
	if err != nil {
		t.Fatalf("test: %v", err)
	}
	if ok {
		t.Fatalf("valeur par défaut absente → non conforme attendu")
	}
	if err := h.Apply(writeItems); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if got := ops.values[keyID("HKCU", path, "")]; got.Str != "cmd %1" {
		t.Fatalf("valeur par défaut attendue 'cmd %%1', obtenu %q", got.Str)
	}
	ok, err = h.Test(writeItems)
	if err != nil || !ok {
		t.Fatalf("après apply : conforme attendu (ok=%v err=%v)", ok, err)
	}

	// Suppression de la valeur par défaut (ensure:"absent", name "").
	absentItems := []StateItem{{
		Type: "registry", Semantics: "exclusive", Hash: "h2",
		Payload: map[string]any{"hive": "HKCU", "path": path, "name": "", "ensure": "absent"},
	}}
	if err := h.Apply(absentItems); err != nil {
		t.Fatalf("apply absent: %v", err)
	}
	if _, still := ops.values[keyID("HKCU", path, "")]; still {
		t.Fatalf("la valeur par défaut aurait dû être supprimée (DeleteValue(\"\"))")
	}
	if ops.deleteCnt != 1 {
		t.Fatalf("1 suppression effective attendue, obtenu %d", ops.deleteCnt)
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
