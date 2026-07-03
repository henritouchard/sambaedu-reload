package shared

import (
	"fmt"
	"testing"
)

// Tests du handler `registry_list` (Story 35.2, contrat §7.6) — réutilisent le
// fakeRegistryOps EXISTANT (étendu de ValueNames), jamais un second fake.

// listItem construit un StateItem `registry_list` (payload 4 clés §7.6).
func listItem(hive, path string, values []string) StateItem {
	return listItemTyped(hive, path, "REG_SZ", values)
}

func listItemTyped(hive, path, entryType string, values []string) StateItem {
	vals := make([]any, 0, len(values))
	for _, v := range values {
		vals = append(vals, v)
	}

	return StateItem{
		Type:      "registry_list",
		Semantics: "exclusive",
		Hash:      path + "-h",
		Payload: map[string]any{
			"hive":       hive,
			"path":       path,
			"entry_type": entryType,
			"values":     vals,
		},
	}
}

// --- (a) écriture 1..N ordonnée + relecture conforme --------------------------

func TestRegistryListWritesOrderedCanonThenIdempotent(t *testing.T) {
	ops := newFakeRegistryOps()
	h := &RegistryListHandler{Ops: ops}
	path := `Software\P\Explorer\DisallowRun`
	items := []StateItem{listItem("HKCU", path, []string{"powershell.exe", "mstsc.exe", "cmd.exe"})}

	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test: %v", err)
	}
	if ok {
		t.Fatalf("clé absente → non conforme attendu")
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	// Les entrées 1..N portent les values DANS L'ORDRE.
	for i, want := range []string{"powershell.exe", "mstsc.exe", "cmd.exe"} {
		got := ops.values[keyID("HKCU", path, fmt.Sprintf("%d", i+1))]
		if got.Kind != "REG_SZ" || got.Str != want {
			t.Fatalf("entrée %d : attendu REG_SZ %q, obtenu %+v", i+1, want, got)
		}
	}
	if ops.writeCnt != 3 {
		t.Fatalf("3 écritures attendues, obtenu %d", ops.writeCnt)
	}

	ok, err = h.Test(items)
	if err != nil || !ok {
		t.Fatalf("après apply : conforme attendu (ok=%v err=%v)", ok, err)
	}

	// (e) idempotence : 2e passe sur état stable = ZÉRO op.
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply 2: %v", err)
	}
	if ops.writeCnt != 3 || ops.deleteCnt != 0 {
		t.Fatalf("apply idempotent attendu : writeCnt=%d deleteCnt=%d", ops.writeCnt, ops.deleteCnt)
	}
}

func TestRegistryListOrderMattersForConvergence(t *testing.T) {
	// Le même contenu dans un AUTRE ordre est une AUTRE cible (l'ordre est
	// porteur de sens, §7.6) : les entrées sont réécrites.
	ops := newFakeRegistryOps()
	path := `SOFTWARE\X\List`
	ops.values[keyID("HKLM", path, "1")] = RegistryValue{Kind: "REG_SZ", Str: "b"}
	ops.values[keyID("HKLM", path, "2")] = RegistryValue{Kind: "REG_SZ", Str: "a"}
	h := &RegistryListHandler{Ops: ops}
	items := []StateItem{listItem("HKLM", path, []string{"a", "b"})}

	ok, _ := h.Test(items)
	if ok {
		t.Fatalf("ordre divergent → non conforme attendu")
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.values[keyID("HKLM", path, "1")].Str != "a" || ops.values[keyID("HKLM", path, "2")].Str != "b" {
		t.Fatalf("l'ordre cible aurait dû être réimposé")
	}
}

// --- (b) surnuméraire supprimée, non-numérique INTOUCHÉE ----------------------

func TestRegistryListRemovesSurplusNumericAndNeverTouchesNonNumeric(t *testing.T) {
	ops := newFakeRegistryOps()
	path := `Software\P\Explorer\DisallowRun`
	ops.values[keyID("HKCU", path, "1")] = RegistryValue{Kind: "REG_SZ", Str: "cmd.exe"}
	ops.values[keyID("HKCU", path, "2")] = RegistryValue{Kind: "REG_SZ", Str: "mstsc.exe"}
	ops.values[keyID("HKCU", path, "3")] = RegistryValue{Kind: "REG_SZ", Str: "orphan.exe"} // surnuméraire
	// Valeur VOISINE non numérique de la même clé : JAMAIS touchée.
	ops.values[keyID("HKCU", path, "NoDriveTypeAutoRun")] = RegistryValue{Kind: "REG_DWORD", Int: 255}
	h := &RegistryListHandler{Ops: ops}
	items := []StateItem{listItem("HKCU", path, []string{"cmd.exe", "mstsc.exe"})}

	ok, _ := h.Test(items)
	if ok {
		t.Fatalf("entrée surnuméraire \"3\" → non conforme attendu")
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if _, still := ops.values[keyID("HKCU", path, "3")]; still {
		t.Fatalf("l'entrée surnuméraire \"3\" aurait dû être supprimée")
	}
	if ops.writeCnt != 0 {
		t.Fatalf("entrées 1..2 déjà conformes : zéro écriture attendue, obtenu %d", ops.writeCnt)
	}
	neighbor, ok := ops.values[keyID("HKCU", path, "NoDriveTypeAutoRun")]
	if !ok || neighbor.Int != 255 {
		t.Fatalf("la valeur voisine NON numérique doit rester INTACTE : %+v ok=%v", neighbor, ok)
	}

	ok, err := h.Test(items)
	if err != nil || !ok {
		t.Fatalf("après purge du surnuméraire : conforme attendu (ok=%v err=%v)", ok, err)
	}
}

// --- (c) "01"/"007" hors canon strconv : SUPPRIMÉES ---------------------------

func TestRegistryListDeletesNonCanonicalNumericNames(t *testing.T) {
	// Canon = strconv.Itoa : "01" ≠ "1", "007" ≠ "7" (comparaison STRICTE de
	// chaînes, jamais de normalisation). Ces noms sont numériques → possédés →
	// hors canon → supprimés.
	ops := newFakeRegistryOps()
	path := `SOFTWARE\Policies\Google\Chrome\ExtensionInstallForcelist`
	ops.values[keyID("HKLM", path, "1")] = RegistryValue{Kind: "REG_SZ", Str: "abc"}
	ops.values[keyID("HKLM", path, "01")] = RegistryValue{Kind: "REG_SZ", Str: "dup"}
	ops.values[keyID("HKLM", path, "007")] = RegistryValue{Kind: "REG_SZ", Str: "bond"}
	h := &RegistryListHandler{Ops: ops}
	items := []StateItem{listItem("HKLM", path, []string{"abc"})}

	ok, _ := h.Test(items)
	if ok {
		t.Fatalf("\"01\"/\"007\" hors canon → non conforme attendu")
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if _, still := ops.values[keyID("HKLM", path, "01")]; still {
		t.Fatalf("\"01\" (hors canon) aurait dû être supprimée")
	}
	if _, still := ops.values[keyID("HKLM", path, "007")]; still {
		t.Fatalf("\"007\" (hors canon) aurait dû être supprimée")
	}
	if ops.values[keyID("HKLM", path, "1")].Str != "abc" {
		t.Fatalf("\"1\" (canon) doit rester")
	}
	if ops.deleteCnt != 2 {
		t.Fatalf("2 suppressions attendues, obtenu %d", ops.deleteCnt)
	}
}

// --- (d) liste vide = purge ; clé absente = compliant --------------------------

func TestRegistryListEmptyValuesPurgesNumericEntries(t *testing.T) {
	ops := newFakeRegistryOps()
	path := `Software\P\Explorer\DisallowRun`
	ops.values[keyID("HKCU", path, "1")] = RegistryValue{Kind: "REG_SZ", Str: "cmd.exe"}
	ops.values[keyID("HKCU", path, "2")] = RegistryValue{Kind: "REG_SZ", Str: "mstsc.exe"}
	ops.values[keyID("HKCU", path, "Keep")] = RegistryValue{Kind: "REG_SZ", Str: "voisine"}
	h := &RegistryListHandler{Ops: ops}
	items := []StateItem{listItem("HKCU", path, []string{})}

	ok, _ := h.Test(items)
	if ok {
		t.Fatalf("entrées numériques présentes + liste vide → non conforme attendu")
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.deleteCnt != 2 || ops.writeCnt != 0 {
		t.Fatalf("purge attendue (2 delete, 0 write) : deleteCnt=%d writeCnt=%d", ops.deleteCnt, ops.writeCnt)
	}
	if _, ok := ops.values[keyID("HKCU", path, "Keep")]; !ok {
		t.Fatalf("la valeur non numérique doit survivre à la purge")
	}

	ok, err := h.Test(items)
	if err != nil || !ok {
		t.Fatalf("après purge : conforme attendu (ok=%v err=%v)", ok, err)
	}
}

func TestRegistryListEmptyValuesOnAbsentKeyIsCompliant(t *testing.T) {
	// (k aussi) clé-conteneur ABSENTE + liste vide ⇒ compliant, ZÉRO op — la
	// clé n'est jamais créée ni supprimée.
	ops := newFakeRegistryOps()
	h := &RegistryListHandler{Ops: ops}
	items := []StateItem{listItem("HKCU", `Software\P\Explorer\DisallowRun`, []string{})}

	ok, err := h.Test(items)
	if err != nil || !ok {
		t.Fatalf("clé absente + values [] : conforme attendu (ok=%v err=%v)", ok, err)
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.writeCnt != 0 || ops.deleteCnt != 0 || ops.notifyCnt != 0 {
		t.Fatalf("aucune opération attendue : writeCnt=%d deleteCnt=%d notifyCnt=%d",
			ops.writeCnt, ops.deleteCnt, ops.notifyCnt)
	}
}

// --- (f) valeur numérotée de Kind exotique (REG_UNSUPPORTED) ------------------

func TestRegistryListUnsupportedKindEntriesAreRewrittenOrDeleted(t *testing.T) {
	// Review 35.1 #1 : une valeur numérotée de type hors contrat est PRÉSENTE
	// et divergente — réécrite au entry_type cible si dans le canon ("1"),
	// supprimée si surnuméraire ("9").
	ops := newFakeRegistryOps()
	path := `SOFTWARE\X\List`
	ops.values[keyID("HKLM", path, "1")] = RegistryValue{Kind: "REG_UNSUPPORTED"}
	ops.values[keyID("HKLM", path, "9")] = RegistryValue{Kind: "REG_UNSUPPORTED"}
	h := &RegistryListHandler{Ops: ops}
	items := []StateItem{listItem("HKLM", path, []string{"abc"})}

	ok, _ := h.Test(items)
	if ok {
		t.Fatalf("Kind exotique → non conforme attendu")
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if got := ops.values[keyID("HKLM", path, "1")]; got.Kind != "REG_SZ" || got.Str != "abc" {
		t.Fatalf("\"1\" aurait dû être RÉÉCRITE au type cible : %+v", got)
	}
	if _, still := ops.values[keyID("HKLM", path, "9")]; still {
		t.Fatalf("\"9\" (surnuméraire, Kind exotique) aurait dû être supprimée")
	}
}

// --- (g) re-drift STRICT à travers le moteur (engine.go INTOUCHÉ) -------------

func TestRegistryListThroughEngineStrictRedrift(t *testing.T) {
	// Iso TestRegistryAbsentThroughEngineStrictRedrift : entrée surnuméraire
	// (ré)apparue ⇒ drift + suppression ; revient ⇒ re-drift ; stable ⇒
	// compliant. Verdict PAR TYPE (grain 27.8).
	ops := newFakeRegistryOps()
	path := `SOFTWARE\Policies\Google\Chrome\ExtensionInstallForcelist`
	surplus := keyID("HKLM", path, "2")
	ops.values[surplus] = RegistryValue{Kind: "REG_SZ", Str: "rogue"}
	h := &RegistryListHandler{Ops: ops}
	engine := &Engine{Handlers: map[string]Handler{"registry_list": h}}
	target := []StateItem{listItem("HKLM", path, []string{"abc"})}

	// Cycle 1 : "1" manquante + "2" surnuméraire → drift (écrit + supprime).
	report := engine.RunPass(target, AppliedState{})
	if len(report) != 1 || report[0].Type != "registry_list" || report[0].Status != "drift" {
		t.Fatalf("cycle 1 : drift attendu pour le type registry_list, obtenu %+v", report)
	}
	if ops.deleteCnt != 1 || ops.writeCnt != 1 {
		t.Fatalf("cycle 1 : 1 write + 1 delete attendus (writeCnt=%d deleteCnt=%d)", ops.writeCnt, ops.deleteCnt)
	}

	// L'entrée surnuméraire RÉAPPARAÎT → re-drift + re-suppression (STRICT).
	ops.values[surplus] = RegistryValue{Kind: "REG_SZ", Str: "revenant"}
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
	if ops.deleteCnt != 2 || ops.writeCnt != 1 {
		t.Fatalf("cycle 3 : aucune op supplémentaire attendue (writeCnt=%d deleteCnt=%d)", ops.writeCnt, ops.deleteCnt)
	}
}

// --- (h) shellRefresh HKCU sur changement effectif, silence sinon -------------

func TestRegistryListShellRefreshOnEffectiveHkcuChangeOnly(t *testing.T) {
	t.Run("écriture HKCU effective → 1 notification (puis silence)", func(t *testing.T) {
		ops := newFakeRegistryOps()
		h := &RegistryListHandler{Ops: ops}
		items := []StateItem{listItem("HKCU", `Software\P\DisallowRun`, []string{"cmd.exe"})}

		if err := h.Apply(items); err != nil {
			t.Fatalf("apply: %v", err)
		}
		if ops.notifyCnt != 1 {
			t.Fatalf("changement HKCU : 1 rafraîchissement shell attendu, obtenu %d", ops.notifyCnt)
		}
		// Régime stable : zéro op = zéro notification.
		if err := h.Apply(items); err != nil {
			t.Fatalf("apply 2: %v", err)
		}
		if ops.notifyCnt != 1 {
			t.Fatalf("état stable : aucune notification supplémentaire, obtenu %d", ops.notifyCnt)
		}
	})

	t.Run("purge HKCU effective (delete seul) → notification", func(t *testing.T) {
		ops := newFakeRegistryOps()
		ops.values[keyID("HKCU", `Software\P\DisallowRun`, "1")] = RegistryValue{Kind: "REG_SZ", Str: "cmd.exe"}
		h := &RegistryListHandler{Ops: ops}

		if err := h.Apply([]StateItem{listItem("HKCU", `Software\P\DisallowRun`, []string{})}); err != nil {
			t.Fatalf("apply: %v", err)
		}
		if ops.notifyCnt != 1 {
			t.Fatalf("suppression HKCU effective : 1 notification attendue, obtenu %d", ops.notifyCnt)
		}
	})

	t.Run("HKLM (service, session 0) → aucune notification", func(t *testing.T) {
		ops := newFakeRegistryOps()
		h := &RegistryListHandler{Ops: ops}

		if err := h.Apply([]StateItem{listItem("HKLM", `SOFTWARE\X\List`, []string{"a"})}); err != nil {
			t.Fatalf("apply: %v", err)
		}
		if ops.notifyCnt != 0 {
			t.Fatalf("HKLM : aucune notification attendue, obtenu %d", ops.notifyCnt)
		}
	})
}

// --- (i) payloads invalides ⇒ error pour le type ------------------------------

func TestRegistryListInvalidPayloadIsError(t *testing.T) {
	h := &RegistryListHandler{Ops: newFakeRegistryOps()}
	cases := []struct {
		name    string
		payload any
	}{
		{"non objet", "texte"},
		{"hive vide", map[string]any{"hive": "", "path": "p", "entry_type": "REG_SZ", "values": []any{}}},
		{"path vide", map[string]any{"hive": "HKLM", "path": "", "entry_type": "REG_SZ", "values": []any{}}},
		{"entry_type absent", map[string]any{"hive": "HKLM", "path": "p", "values": []any{"a"}}},
		{"entry_type hors contrat", map[string]any{"hive": "HKLM", "path": "p", "entry_type": "REG_DWORD", "values": []any{"a"}}},
		{"values absent", map[string]any{"hive": "HKLM", "path": "p", "entry_type": "REG_SZ"}},
		{"values non liste", map[string]any{"hive": "HKLM", "path": "p", "entry_type": "REG_SZ", "values": "a"}},
		{"values entrée non string", map[string]any{"hive": "HKLM", "path": "p", "entry_type": "REG_SZ", "values": []any{"a", 5}}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			items := []StateItem{{Type: "registry_list", Semantics: "exclusive", Hash: "h", Payload: tc.payload}}
			if _, err := h.Test(items); err == nil {
				t.Fatalf("payload invalide attendu en erreur de Test")
			}
			if err := h.Apply(items); err == nil {
				t.Fatalf("payload invalide attendu en erreur d'Apply")
			}
		})
	}
}

// --- (j) mix multi-conteneurs : effort maximal, isolation des erreurs ---------

func TestRegistryListMultiContainerErrorIsolation(t *testing.T) {
	// Un conteneur en échec (énumération refusée) n'empêche PAS les autres de
	// converger ; l'erreur est remontée à la FIN (verdict error par type au
	// moteur, mais les conteneurs sains ont convergé).
	ops := newFakeRegistryOps()
	ops.namesErr[`hklm\software\bad\list`] = fmt.Errorf("accès refusé")
	ops.values[keyID("HKLM", `SOFTWARE\Good\List`, "9")] = RegistryValue{Kind: "REG_SZ", Str: "surplus"}
	h := &RegistryListHandler{Ops: ops}
	items := []StateItem{
		listItem("HKLM", `SOFTWARE\Bad\List`, []string{"a"}),  // échoue (énumération)
		listItem("HKLM", `SOFTWARE\Good\List`, []string{"a"}), // doit converger
	}

	err := h.Apply(items)
	if err == nil {
		t.Fatalf("un conteneur en erreur devrait remonter une erreur d'apply")
	}
	if got := ops.values[keyID("HKLM", `SOFTWARE\Good\List`, "1")]; got.Str != "a" {
		t.Fatalf("le conteneur SAIN aurait dû converger malgré l'échec de l'autre")
	}
	if _, still := ops.values[keyID("HKLM", `SOFTWARE\Good\List`, "9")]; still {
		t.Fatalf("le surnuméraire du conteneur sain aurait dû être supprimé")
	}
	// Le conteneur en échec d'énumération n'a PAS été touché (on n'écrit pas
	// à l'aveugle dans une clé qu'on ne peut pas lire).
	if _, wrote := ops.values[keyID("HKLM", `SOFTWARE\Bad\List`, "1")]; wrote {
		t.Fatalf("un conteneur illisible ne doit PAS être écrit")
	}
}

func TestRegistryListIntraContainerWriteErrorIsolation(t *testing.T) {
	// Effort maximal AU SEIN d'un conteneur : l'échec d'écriture de "1"
	// n'empêche ni "2" d'être écrite ni le surnuméraire d'être purgé.
	ops := newFakeRegistryOps()
	path := `SOFTWARE\X\List`
	ops.writeErr[keyID("HKLM", path, "1")] = fmt.Errorf("accès refusé")
	ops.values[keyID("HKLM", path, "5")] = RegistryValue{Kind: "REG_SZ", Str: "surplus"}
	h := &RegistryListHandler{Ops: ops}
	items := []StateItem{listItem("HKLM", path, []string{"a", "b"})}

	err := h.Apply(items)
	if err == nil {
		t.Fatalf("écriture en échec : erreur d'apply attendue")
	}
	if got := ops.values[keyID("HKLM", path, "2")]; got.Str != "b" {
		t.Fatalf("\"2\" aurait dû être écrite malgré l'échec de \"1\"")
	}
	if _, still := ops.values[keyID("HKLM", path, "5")]; still {
		t.Fatalf("le surnuméraire \"5\" aurait dû être supprimé malgré l'échec de \"1\"")
	}
}

// --- (k) la clé-conteneur n'est JAMAIS supprimée -------------------------------

func TestRegistryListNeverDeletesTheContainerKeyItself(t *testing.T) {
	// Le contrat RegistryOps n'expose AUCUN delete de clé (Delete = valeur
	// nommée seulement) : après une purge complète, les valeurs NON numériques
	// de la clé survivent — preuve comportementale que la clé vit toujours.
	ops := newFakeRegistryOps()
	path := `Software\P\Explorer\DisallowRun`
	ops.values[keyID("HKCU", path, "1")] = RegistryValue{Kind: "REG_SZ", Str: "cmd.exe"}
	ops.values[keyID("HKCU", path, "(default-ish)")] = RegistryValue{Kind: "REG_SZ", Str: "voisine"}
	h := &RegistryListHandler{Ops: ops}

	if err := h.Apply([]StateItem{listItem("HKCU", path, []string{})}); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if _, ok := ops.values[keyID("HKCU", path, "(default-ish)")]; !ok {
		t.Fatalf("les valeurs non numériques du conteneur doivent survivre à la purge complète")
	}
}

// --- dédoublonnage par identité de conteneur (défense, iso desiredSpecs) ------

func TestRegistryListDedupesByContainerIdentityLastWins(t *testing.T) {
	ops := newFakeRegistryOps()
	h := &RegistryListHandler{Ops: ops}
	items := []StateItem{
		listItem("HKLM", `SOFTWARE\X\List`, []string{"old"}),
		listItem("HKLM", `software\x\LIST`, []string{"new"}), // même identité (casse)
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.writeCnt != 1 {
		t.Fatalf("1 écriture attendue (dernière occurrence fait foi), obtenu %d", ops.writeCnt)
	}
	if got := ops.values[keyID("HKLM", `software\x\LIST`, "1")]; got.Str != "new" {
		t.Fatalf("la DERNIÈRE occurrence fait foi : %+v", got)
	}
}

// --- REG_EXPAND_SZ : entry_type respecté ---------------------------------------

func TestRegistryListExpandSzEntriesAreWrittenWithExpandKind(t *testing.T) {
	ops := newFakeRegistryOps()
	h := &RegistryListHandler{Ops: ops}
	items := []StateItem{listItemTyped("HKLM", `SOFTWARE\X\Paths`, "REG_EXPAND_SZ", []string{`%ProgramFiles%\a`})}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	got := ops.values[keyID("HKLM", `SOFTWARE\X\Paths`, "1")]
	if got.Kind != "REG_EXPAND_SZ" || got.Str != `%ProgramFiles%\a` {
		t.Fatalf("REG_EXPAND_SZ attendu, obtenu %+v", got)
	}
	ok, err := h.Test(items)
	if err != nil || !ok {
		t.Fatalf("relecture conforme attendue (ok=%v err=%v)", ok, err)
	}
}
