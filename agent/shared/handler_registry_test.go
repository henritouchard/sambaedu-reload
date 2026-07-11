package shared

import (
	"fmt"
	"os"
	"path/filepath"
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
	// unmountedHku : bases HKU (`.default`/`<sid>` en minuscules) DÉMONTÉES
	// après l'énumération (race logoff, review 35.3 #1) — Read les voit
	// absentes (aucune value posée), Write les SAUTE (no-op, iso Windows).
	unmountedHku map[string]bool
	// userHives : cibles du fan-out HKU (Story 35.3, RegistryOps.UserHives) —
	// ".DEFAULT" + SID chargés, injectables par le test (l'impl Windows filtre
	// et trie ; le fake rend la liste telle quelle). userHivesErr simule un
	// échec d'énumération (item HKU inapplicable) ; userHivesCnt prouve
	// l'énumération PAR APPEL (jamais de cache entre cycles).
	userHives    []string
	userHivesErr error
	userHivesCnt int
}

// NB (Story 43.1) : plus de NotifyShellChanged ici — le rafraîchissement n'est
// PLUS un hook sur Ops : les handlers ACCUMULENT (RefreshRequester), le
// compagnon exécute. Les tests lisent h.TakeRefreshRequest().

func newFakeRegistryOps() *fakeRegistryOps {
	return &fakeRegistryOps{
		values:       map[string]RegistryValue{},
		readErr:      map[string]error{},
		writeErr:     map[string]error{},
		deleteErr:    map[string]error{},
		namesErr:     map[string]error{},
		unmountedHku: map[string]bool{},
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
	// Iso impl Windows (review 35.3 #1) : une cible HKU dont la ruche de
	// fan-out a été DÉMONTÉE depuis l'énumération est SAUTÉE (no-op nil,
	// jamais d'orpheline) — la base est le premier segment du path.
	if isUsersHive(spec.Hive) {
		base, _, _ := strings.Cut(strings.ToLower(spec.Path), `\`)
		if o.unmountedHku[base] {
			return nil
		}
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

// UserHives : cibles du fan-out HKU (Story 35.3). Copie défensive, comptée par
// appel (l'énumération est PAR APPEL Test/Apply — jamais de cache).
func (o *fakeRegistryOps) UserHives() ([]string, error) {
	o.userHivesCnt++
	if o.userHivesErr != nil {
		return nil, o.userHivesErr
	}

	return append([]string{}, o.userHives...), nil
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

// --- Rafraîchissement : accumulé sur changement HKCU seul (Story 43.1) -------
// Migration des tests `notifyCnt` : la même séquence observable (plancher
// shell_notify sur changement HKCU effectif, silence sinon) se lit désormais
// via TakeRefreshRequest() — plus aucune émission inline par le handler.

func TestRegistryShellRefreshOnUserHiveChangeOnly(t *testing.T) {
	t.Run("changement HKCU → plancher shell_notify accumulé (puis idempotent)", func(t *testing.T) {
		ops := newFakeRegistryOps()
		h := &RegistryHandler{Ops: ops}
		items := []StateItem{dwordItem("HKCU", `Software\Test\Advanced`, "Hidden", 1)}

		if err := h.Apply(items); err != nil {
			t.Fatalf("apply: %v", err)
		}
		if got := h.TakeRefreshRequest(); got != RefreshShellNotify {
			t.Fatalf("changement HKCU sans hint : plancher shell_notify attendu, obtenu %s", got)
		}
		// État stable : 2e passe sans écriture → aucun besoin de plus (et la
		// consommation précédente a bien remis l'accumulation à zéro).
		if err := h.Apply(items); err != nil {
			t.Fatalf("apply 2: %v", err)
		}
		if got := h.TakeRefreshRequest(); got != RefreshNone {
			t.Fatalf("état stable : aucun geste attendu, obtenu %s", got)
		}
	})

	t.Run("HKLM seul (service, session 0) → aucun besoin accumulé", func(t *testing.T) {
		ops := newFakeRegistryOps()
		h := &RegistryHandler{Ops: ops}
		items := []StateItem{dwordItem("HKLM", `SOFTWARE\Test\System`, "EnableLUA", 0)}

		if err := h.Apply(items); err != nil {
			t.Fatalf("apply: %v", err)
		}
		if got := h.TakeRefreshRequest(); got != RefreshNone {
			t.Fatalf("HKLM : aucun geste attendu, obtenu %s", got)
		}
	})

	t.Run("déjà conforme → aucune écriture, aucun besoin", func(t *testing.T) {
		ops := newFakeRegistryOps()
		ops.values[keyID("HKCU", `Software\Test\Advanced`, "Hidden")] = RegistryValue{Kind: "REG_DWORD", Int: 1}
		h := &RegistryHandler{Ops: ops}
		items := []StateItem{dwordItem("HKCU", `Software\Test\Advanced`, "Hidden", 1)}

		if err := h.Apply(items); err != nil {
			t.Fatalf("apply: %v", err)
		}
		if got := h.TakeRefreshRequest(); got != RefreshNone {
			t.Fatalf("état déjà conforme : aucun geste attendu, obtenu %s", got)
		}
	})
}

// --- Hint `refresh` du payload : escalade du plancher (Story 43.1, AC1/AC3) --

func TestRegistryRefreshHintEscalatesFloorOnChange(t *testing.T) {
	t.Run("item changé avec hint explorer_restart → niveau escaladé", func(t *testing.T) {
		ops := newFakeRegistryOps()
		h := &RegistryHandler{Ops: ops}
		item := dwordItem("HKCU", `Software\P\Explorer`, "RestrictRun", 1)
		item.Payload.(map[string]any)["refresh"] = "explorer_restart"

		if err := h.Apply([]StateItem{item}); err != nil {
			t.Fatalf("apply: %v", err)
		}
		if got := h.TakeRefreshRequest(); got != RefreshExplorerRestart {
			t.Fatalf("hint explorer_restart sur item changé : explorer_restart attendu, obtenu %s", got)
		}
	})

	t.Run("hints hétérogènes → le plus fort des items CHANGÉS", func(t *testing.T) {
		ops := newFakeRegistryOps()
		h := &RegistryHandler{Ops: ops}
		soft := dwordItem("HKCU", `Software\Test\Advanced`, "Hidden", 1)
		soft.Payload.(map[string]any)["refresh"] = "shell_notify"
		strong := dwordItem("HKCU", `Software\P\Explorer`, "RestrictRun", 1)
		strong.Payload.(map[string]any)["refresh"] = "policy_broadcast"

		if err := h.Apply([]StateItem{soft, strong}); err != nil {
			t.Fatalf("apply: %v", err)
		}
		if got := h.TakeRefreshRequest(); got != RefreshPolicyBroadcast {
			t.Fatalf("max des items changés attendu (policy_broadcast), obtenu %s", got)
		}
	})

	t.Run("item NON changé avec hint fort → le hint ne compte pas", func(t *testing.T) {
		// Seul l'item changé (sans hint) pèse : plancher shell_notify — le
		// hint fort d'un item déjà conforme n'escalade RIEN (gate changed).
		ops := newFakeRegistryOps()
		ops.values[keyID("HKCU", `Software\P\Explorer`, "RestrictRun")] = RegistryValue{Kind: "REG_DWORD", Int: 1}
		h := &RegistryHandler{Ops: ops}
		compliantStrong := dwordItem("HKCU", `Software\P\Explorer`, "RestrictRun", 1)
		compliantStrong.Payload.(map[string]any)["refresh"] = "explorer_restart"
		changedNoHint := dwordItem("HKCU", `Software\Test\Advanced`, "Hidden", 1)

		if err := h.Apply([]StateItem{compliantStrong, changedNoHint}); err != nil {
			t.Fatalf("apply: %v", err)
		}
		if got := h.TakeRefreshRequest(); got != RefreshShellNotify {
			t.Fatalf("seul l'item changé pèse (plancher shell_notify), obtenu %s", got)
		}
	})

	t.Run("hint inconnu / non-string → plancher shell_notify, jamais une erreur", func(t *testing.T) {
		ops := newFakeRegistryOps()
		h := &RegistryHandler{Ops: ops, Log: &Logger{}}
		unknown := dwordItem("HKCU", `Software\Test\Advanced`, "Hidden", 1)
		unknown.Payload.(map[string]any)["refresh"] = "reboot_the_universe"
		nonString := dwordItem("HKCU", `Software\Test\Advanced2`, "HideFileExt", 0)
		nonString.Payload.(map[string]any)["refresh"] = 42

		if ok, err := h.Test([]StateItem{unknown, nonString}); err != nil || ok {
			t.Fatalf("hint inconnu = enveloppe VALIDE, drift attendu (ok=%v err=%v)", ok, err)
		}
		if err := h.Apply([]StateItem{unknown, nonString}); err != nil {
			t.Fatalf("apply: %v", err)
		}
		if got := h.TakeRefreshRequest(); got != RefreshShellNotify {
			t.Fatalf("hint inconnu ⇒ comportement plancher (shell_notify), obtenu %s", got)
		}
	})

	t.Run("hint fort sur HKLM changé → jamais de geste (session 0)", func(t *testing.T) {
		ops := newFakeRegistryOps()
		h := &RegistryHandler{Ops: ops}
		item := dwordItem("HKLM", `SOFTWARE\P\System`, "EnableLUA", 1)
		item.Payload.(map[string]any)["refresh"] = "explorer_restart"

		if err := h.Apply([]StateItem{item}); err != nil {
			t.Fatalf("apply: %v", err)
		}
		if got := h.TakeRefreshRequest(); got != RefreshNone {
			t.Fatalf("HKLM : aucun geste même avec hint fort, obtenu %s", got)
		}
	})
}

func TestRegistryUnknownRefreshHintLoggedOncePerPass(t *testing.T) {
	// Review 43.1 #3 : Test PUIS Apply re-parsent les MÊMES items dans une
	// passe (dispatch §5) — la trace debug du hint inconnu ne part qu'UNE fois
	// par passe et par item (chemin Test seulement, logHints).
	ops := newFakeRegistryOps()
	dir := t.TempDir()
	h := &RegistryHandler{Ops: ops, Log: &Logger{Dir: dir}}
	item := dwordItem("HKCU", `Software\Test\Advanced`, "Hidden", 1)
	item.Payload.(map[string]any)["refresh"] = "warp_speed"
	items := []StateItem{item}

	if ok, err := h.Test(items); err != nil || ok {
		t.Fatalf("drift attendu : %v %v", ok, err)
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}

	raw, err := os.ReadFile(filepath.Join(dir, "agent.log"))
	if err != nil {
		t.Fatalf("log attendu : %v", err)
	}
	if got := strings.Count(string(raw), "Hint refresh inconnu"); got != 1 {
		t.Fatalf("UNE trace de hint inconnu par passe attendue, obtenu %d :\n%s", got, raw)
	}
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
	// (e) même gate que l'écriture : suppression HKCU EFFECTIVE ⇒ besoin
	// shell_notify accumulé ; suppression no-op (déjà absente) ou HKLM ⇒ rien.
	t.Run("delete HKCU effectif → plancher shell_notify", func(t *testing.T) {
		ops := newFakeRegistryOps()
		ops.values[keyID("HKCU", `Software\Test\Policies`, "NoDrives")] = RegistryValue{Kind: "REG_DWORD", Int: 4}
		h := &RegistryHandler{Ops: ops}

		if err := h.Apply([]StateItem{absentItem("HKCU", `Software\Test\Policies`, "NoDrives")}); err != nil {
			t.Fatalf("apply: %v", err)
		}
		if got := h.TakeRefreshRequest(); got != RefreshShellNotify {
			t.Fatalf("suppression HKCU effective : shell_notify attendu, obtenu %s", got)
		}
	})

	t.Run("delete no-op (déjà absente) → aucun besoin", func(t *testing.T) {
		ops := newFakeRegistryOps()
		h := &RegistryHandler{Ops: ops}

		if err := h.Apply([]StateItem{absentItem("HKCU", `Software\Test\Policies`, "NoDrives")}); err != nil {
			t.Fatalf("apply: %v", err)
		}
		if got := h.TakeRefreshRequest(); got != RefreshNone {
			t.Fatalf("no-op : aucun geste attendu, obtenu %s", got)
		}
	})

	t.Run("delete HKLM effectif (service, session 0) → aucun besoin", func(t *testing.T) {
		ops := newFakeRegistryOps()
		ops.values[keyID("HKLM", `SOFTWARE\Test`, "M")] = RegistryValue{Kind: "REG_DWORD", Int: 1}
		h := &RegistryHandler{Ops: ops}

		if err := h.Apply([]StateItem{absentItem("HKLM", `SOFTWARE\Test`, "M")}); err != nil {
			t.Fatalf("apply: %v", err)
		}
		if got := h.TakeRefreshRequest(); got != RefreshNone {
			t.Fatalf("HKLM : aucun geste attendu, obtenu %s", got)
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

// --- Ruche HKU : fan-out .DEFAULT + ruches chargées (Story 35.3) --------------
//
// Un item `hive:"HKU"` (portée machine, service SYSTEM) est UNE cible logique
// que le handler applique à `HKU\.DEFAULT` + chaque ruche utilisateur chargée
// (RegistryOps.UserHives, énuméré PAR APPEL). Drift AGRÉGÉ, idempotence PAR
// CIBLE, identité/hash de l'item inchangés par le nombre de sessions.

const hkuNumlockPath = `Control Panel\Keyboard`

const hkuNumlockName = "InitialKeyboardIndicators"

// hkuItem : l'item numlock écran de logon (cas réel de la story). Le path ne
// porte JAMAIS `.DEFAULT\` (piège n° 6 : c'est le handler qui préfixe).
func hkuItem(value string) StateItem {
	return szItem("HKU", hkuNumlockPath, hkuNumlockName, value)
}

func TestRegistryHkuWriteFansOutToAllHivesThenIdempotent(t *testing.T) {
	// (a) écriture HKU : fan-out .DEFAULT + 2 SID, re-Test true, 2e Apply =
	// zéro op — et l'énumération est PAR APPEL (userHivesCnt suit les appels).
	ops := newFakeRegistryOps()
	ops.userHives = []string{".DEFAULT", "S-1-5-21-111", "S-1-5-21-222"}
	h := &RegistryHandler{Ops: ops}
	items := []StateItem{hkuItem("2")}

	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test: %v", err)
	}
	if ok {
		t.Fatalf("aucune ruche ne porte la valeur → non conforme attendu")
	}
	if ops.userHivesCnt != 1 {
		t.Fatalf("Test = 1 énumération, obtenu %d", ops.userHivesCnt)
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.writeCnt != 3 {
		t.Fatalf("fan-out attendu vers 3 ruches (3 écritures), obtenu %d", ops.writeCnt)
	}
	for _, hive := range ops.userHives {
		got := ops.values[keyID("HKU", hive+`\`+hkuNumlockPath, hkuNumlockName)]
		if got.Str != "2" {
			t.Fatalf("ruche %s : valeur '2' attendue, obtenu %q", hive, got.Str)
		}
	}
	if ops.userHivesCnt != 2 {
		t.Fatalf("Test + Apply = 2 énumérations (une PAR APPEL), obtenu %d", ops.userHivesCnt)
	}

	ok, err = h.Test(items)
	if err != nil || !ok {
		t.Fatalf("re-test après fan-out : ok=%v err=%v (conforme attendu)", ok, err)
	}

	// 2e Apply sur état stable : ZÉRO écriture (idempotence par cible).
	before := ops.writeCnt
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply 2: %v", err)
	}
	if ops.writeCnt != before {
		t.Fatalf("apply idempotent attendu : %d écriture(s) de plus", ops.writeCnt-before)
	}
}

func TestRegistryHkuWriteSkipsHiveUnmountedAfterEnumeration(t *testing.T) {
	// Race logoff RÉELLE (review 35.3 #1) : UserHives a énuméré un SID, la
	// ruche est démontée avant l'écriture. Chemin Windows réel : Read
	// not-present (drift) puis Write SKIP no-op — JAMAIS de clé orpheline
	// matérialisée sous HKEY_USERS. Les autres ruches convergent, aucune
	// erreur. Le cycle suivant n'énumère plus la ruche → item conforme.
	ops := newFakeRegistryOps()
	ops.userHives = []string{".DEFAULT", "S-1-5-21-999"}
	ops.unmountedHku["s-1-5-21-999"] = true // démontée APRÈS l'énumération
	h := &RegistryHandler{Ops: ops}
	items := []StateItem{hkuItem("2")}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v (le skip d'une ruche démontée n'est pas une erreur)", err)
	}
	if ops.writeCnt != 1 {
		t.Fatalf("seule .DEFAULT doit être écrite (1 écriture), obtenu %d", ops.writeCnt)
	}
	if _, orphan := ops.values[keyID("HKU", `S-1-5-21-999\`+hkuNumlockPath, hkuNumlockName)]; orphan {
		t.Fatalf("clé ORPHELINE matérialisée dans une ruche démontée — interdit")
	}

	// Cycle suivant : la ruche a disparu de l'énumération → conforme.
	ops.userHives = []string{".DEFAULT"}
	ok, err := h.Test(items)
	if err != nil || !ok {
		t.Fatalf("cycle suivant sans la ruche : conforme attendu (ok=%v err=%v)", ok, err)
	}
}

func TestRegistryHkuAggregatedDriftRewritesOnlyTheDivergentHive(t *testing.T) {
	// (b) UNE ruche divergente ⇒ Test false (drift agrégé) ; Apply ne réécrit
	// QUE cette ruche (idempotence par cible : les conformes sont intouchées).
	ops := newFakeRegistryOps()
	ops.userHives = []string{".DEFAULT", "S-1-5-21-111", "S-1-5-21-222"}
	ops.values[keyID("HKU", `.DEFAULT\`+hkuNumlockPath, hkuNumlockName)] = RegistryValue{Kind: "REG_SZ", Str: "2"}
	ops.values[keyID("HKU", `S-1-5-21-111\`+hkuNumlockPath, hkuNumlockName)] = RegistryValue{Kind: "REG_SZ", Str: "2"}
	ops.values[keyID("HKU", `S-1-5-21-222\`+hkuNumlockPath, hkuNumlockName)] = RegistryValue{Kind: "REG_SZ", Str: "0"} // divergente
	h := &RegistryHandler{Ops: ops}
	items := []StateItem{hkuItem("2")}

	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test: %v", err)
	}
	if ok {
		t.Fatalf("une ruche divergente ⇒ item NON conforme (drift agrégé)")
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.writeCnt != 1 {
		t.Fatalf("seule la ruche divergente doit être réécrite (1 écriture), obtenu %d", ops.writeCnt)
	}
	if got := ops.values[keyID("HKU", `S-1-5-21-222\`+hkuNumlockPath, hkuNumlockName)]; got.Str != "2" {
		t.Fatalf("la ruche divergente aurait dû converger à '2', obtenu %q", got.Str)
	}
}

func TestRegistryHkuNewSessionCoveredNextCycleThroughEngineStrict(t *testing.T) {
	// (c) policy STRICT via le moteur (engine.go INTOUCHÉ, iso
	// TestRegistryAbsentThroughEngineStrictRedrift) : une ruche AJOUTÉE entre
	// deux passes (session ouverte après coup) est vue au cycle suivant —
	// énumération par appel, jamais de cache.
	ops := newFakeRegistryOps()
	ops.userHives = []string{".DEFAULT"}
	h := &RegistryHandler{Ops: ops}
	engine := &Engine{Handlers: map[string]Handler{"registry": h}}
	target := []StateItem{hkuItem("2")}

	// Cycle 1 : .DEFAULT vierge → drift + écriture.
	report := engine.RunPass(target, AppliedState{})
	if len(report) != 1 || report[0].Status != "drift" {
		t.Fatalf("cycle 1 : drift attendu, obtenu %+v", report)
	}
	if ops.writeCnt != 1 {
		t.Fatalf("cycle 1 : 1 écriture (.DEFAULT), obtenu %d", ops.writeCnt)
	}

	// Cycle 2 : stable → compliant, zéro op.
	report = engine.RunPass(target, AppliedState{})
	if len(report) != 1 || report[0].Status != "compliant" {
		t.Fatalf("cycle 2 : compliant attendu, obtenu %+v", report)
	}

	// Une session S'OUVRE (ruche chargée entre deux cycles) → cycle 3 :
	// re-drift (la nouvelle ruche n'a pas la valeur) + convergence de la seule
	// nouvelle ruche.
	ops.userHives = append(ops.userHives, "S-1-5-21-333")
	report = engine.RunPass(target, AppliedState{})
	if len(report) != 1 || report[0].Status != "drift" {
		t.Fatalf("cycle 3 (session ouverte après coup) : drift attendu, obtenu %+v", report)
	}
	if ops.writeCnt != 2 {
		t.Fatalf("cycle 3 : 1 écriture de plus (nouvelle ruche seule), obtenu %d au total", ops.writeCnt)
	}
	if got := ops.values[keyID("HKU", `S-1-5-21-333\`+hkuNumlockPath, hkuNumlockName)]; got.Str != "2" {
		t.Fatalf("la nouvelle ruche aurait dû converger à '2', obtenu %q", got.Str)
	}

	// Cycle 4 : stable → compliant.
	report = engine.RunPass(target, AppliedState{})
	if len(report) != 1 || report[0].Status != "compliant" {
		t.Fatalf("cycle 4 : compliant attendu, obtenu %+v", report)
	}
}

func TestRegistryHkuAbsentDeletesAcrossAllHivesIncludingUnsupportedKind(t *testing.T) {
	// (d) `ensure:"absent"` HKU supprime la valeur nommée dans TOUTES les
	// ruches — y compris une valeur d'un type hors contrat (sentinelle
	// REG_UNSUPPORTED, piège n° 10) présente dans UNE seule ruche alors que
	// .DEFAULT est déjà propre (drift agrégé).
	ops := newFakeRegistryOps()
	ops.userHives = []string{".DEFAULT", "S-1-5-21-111", "S-1-5-21-222"}
	// .DEFAULT : déjà propre. 111 : REG_DWORD résiduel. 222 : REG_BINARY (kind
	// sentinelle) résiduel.
	ops.values[keyID("HKU", `S-1-5-21-111\`+hkuNumlockPath, hkuNumlockName)] = RegistryValue{Kind: "REG_DWORD", Int: 2}
	ops.values[keyID("HKU", `S-1-5-21-222\`+hkuNumlockPath, hkuNumlockName)] = RegistryValue{Kind: "REG_UNSUPPORTED"}
	h := &RegistryHandler{Ops: ops}
	items := []StateItem{absentItem("HKU", hkuNumlockPath, hkuNumlockName)}

	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test: %v", err)
	}
	if ok {
		t.Fatalf("valeurs résiduelles dans 2 ruches ⇒ non conforme attendu")
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.deleteCnt != 2 {
		t.Fatalf("2 suppressions effectives attendues (111 + 222), obtenu %d", ops.deleteCnt)
	}

	ok, err = h.Test(items)
	if err != nil || !ok {
		t.Fatalf("après purge : conforme attendu (ok=%v err=%v)", ok, err)
	}

	// Idempotence : 2e Apply = zéro suppression de plus.
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply 2: %v", err)
	}
	if ops.deleteCnt != 2 {
		t.Fatalf("apply idempotent : aucune suppression de plus, obtenu %d", ops.deleteCnt)
	}
}

func TestRegistryHkuEnumerationErrorIsFrankButOtherKeysConverge(t *testing.T) {
	// (e-1) une erreur d'ÉNUMÉRATION rend l'item HKU inapplicable : Test =
	// erreur franche (le moteur rendra {status: error} pour le type SANS
	// Apply) ; en Apply (course), l'erreur est remontée mais les AUTRES clés
	// de la passe convergent (effort maximal).
	ops := newFakeRegistryOps()
	ops.userHivesErr = fmt.Errorf("accès refusé à HKEY_USERS")
	h := &RegistryHandler{Ops: ops}
	items := []StateItem{
		hkuItem("2"),
		dwordItem("HKLM", `SOFTWARE\Test\System`, "EnableLUA", 0),
	}

	// Test sur l'item HKU seul : erreur FRANCHE (NB : dans une passe mixte,
	// Test peut court-circuiter en (false, nil) sur une dérive HKLM rencontrée
	// AVANT l'item HKU — ordre d'identité — sans que ça change le verdict :
	// le moteur appelle alors Apply, qui remonte l'erreur d'énumération).
	if _, err := h.Test(items[:1]); err == nil {
		t.Fatalf("erreur d'énumération : erreur franche attendue de Test")
	}

	err := h.Apply(items)
	if err == nil {
		t.Fatalf("erreur d'énumération : erreur attendue d'Apply")
	}
	if ops.values[keyID("HKLM", `SOFTWARE\Test\System`, "EnableLUA")].Int != 0 {
		t.Fatalf("la clé HKLM de la même passe aurait dû converger malgré l'échec HKU")
	}
}

func TestRegistryHkuPerHiveErrorIsIsolated(t *testing.T) {
	// (e-2) une ruche en échec (accès refusé / déchargée en course logoff)
	// n'empêche NI les autres ruches NI les autres clés de converger ; la
	// première erreur est remontée à la fin.
	ops := newFakeRegistryOps()
	ops.userHives = []string{".DEFAULT", "S-1-5-21-111", "S-1-5-21-222"}
	ops.writeErr[keyID("HKU", `S-1-5-21-111\`+hkuNumlockPath, hkuNumlockName)] = fmt.Errorf("ruche déchargée")
	h := &RegistryHandler{Ops: ops}
	items := []StateItem{
		hkuItem("2"),
		dwordItem("HKLM", `SOFTWARE\Test\System`, "ZZZ", 1),
	}

	err := h.Apply(items)
	if err == nil {
		t.Fatalf("une ruche en échec devrait remonter une erreur d'apply")
	}
	// Les 2 autres ruches ont convergé.
	for _, hive := range []string{".DEFAULT", "S-1-5-21-222"} {
		if got := ops.values[keyID("HKU", hive+`\`+hkuNumlockPath, hkuNumlockName)]; got.Str != "2" {
			t.Fatalf("ruche %s : aurait dû converger malgré l'échec de 111, obtenu %q", hive, got.Str)
		}
	}
	// L'autre clé de la passe aussi.
	if ops.values[keyID("HKLM", `SOFTWARE\Test\System`, "ZZZ")].Int != 1 {
		t.Fatalf("la clé HKLM aurait dû converger malgré l'échec d'une ruche HKU")
	}
}

func TestRegistryHkuAndHklmMixedInOneMachinePass(t *testing.T) {
	// (f) mix HKLM + HKU dans une même passe machine : le service SYSTEM
	// applique les deux via le MÊME handler (wiring inchangé).
	ops := newFakeRegistryOps()
	ops.userHives = []string{".DEFAULT", "S-1-5-21-111"}
	h := &RegistryHandler{Ops: ops}
	items := []StateItem{
		dwordItem("HKLM", `SOFTWARE\X`, "M", 7),
		hkuItem("2"),
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.values[keyID("HKLM", `SOFTWARE\X`, "M")].Int != 7 {
		t.Fatalf("clé HKLM non appliquée")
	}
	if ops.writeCnt != 3 {
		t.Fatalf("1 écriture HKLM + 2 cibles HKU = 3 écritures, obtenu %d", ops.writeCnt)
	}
}

func TestRegistryHkuNeverTriggersShellRefresh(t *testing.T) {
	// (g) aucun besoin de rafraîchissement pour HKU (piège n° 9, test négatif
	// piège n° 2 de 43.1) : le service écrit depuis la session 0 — isUserHive
	// rend false pour HKU, écriture ET suppression effectives comprises, MÊME
	// avec un hint `refresh` fort sur l'item (le fan-out HKU changé rend
	// TakeRefreshRequest() == RefreshNone).
	ops := newFakeRegistryOps()
	ops.userHives = []string{".DEFAULT", "S-1-5-21-111"}
	ops.values[keyID("HKU", `S-1-5-21-111\Software\Residue`, "Old")] = RegistryValue{Kind: "REG_DWORD", Int: 1}
	h := &RegistryHandler{Ops: ops}
	strongHku := hkuItem("2")
	strongHku.Payload.(map[string]any)["refresh"] = "explorer_restart"
	items := []StateItem{
		strongHku,
		absentItem("HKU", `Software\Residue`, "Old"),
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.writeCnt == 0 || ops.deleteCnt == 0 {
		t.Fatalf("précondition : écriture (%d) et suppression (%d) effectives attendues", ops.writeCnt, ops.deleteCnt)
	}
	if got := h.TakeRefreshRequest(); got != RefreshNone {
		t.Fatalf("HKU : aucun geste attendu (session 0), obtenu %s", got)
	}
}

func TestRegistryHkuDedupKeepsLastOccurrence(t *testing.T) {
	// (h) identité/dédup INCHANGÉES : deux items sur la MÊME identité logique
	// {hku|path|name} → la DERNIÈRE occurrence fait foi (desiredSpecs intouché),
	// le fan-out n'écrit qu'UNE valeur par ruche.
	ops := newFakeRegistryOps()
	ops.userHives = []string{".DEFAULT", "S-1-5-21-111"}
	h := &RegistryHandler{Ops: ops}
	items := []StateItem{
		hkuItem("0"), // écrasée par la suivante (même identité)
		hkuItem("2"),
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.writeCnt != 2 {
		t.Fatalf("1 spec dédupliqué × 2 ruches = 2 écritures, obtenu %d", ops.writeCnt)
	}
	for _, hive := range ops.userHives {
		if got := ops.values[keyID("HKU", hive+`\`+hkuNumlockPath, hkuNumlockName)]; got.Str != "2" {
			t.Fatalf("ruche %s : la dernière occurrence ('2') fait foi, obtenu %q", hive, got.Str)
		}
	}
}
