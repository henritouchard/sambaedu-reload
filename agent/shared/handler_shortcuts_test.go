package shared

import (
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"
)

// fakeShortcutOps : ShortcutOps en mémoire (testable hôte). Les `.lnk` sont
// modélisés par un map chemin → spec ; `user` marque les fichiers créés par un
// utilisateur (non gérés, jamais supprimables).
type fakeShortcutOps struct {
	files    map[string]ShortcutSpec // raccourcis GÉRÉS posés
	userLnks map[string]bool         // raccourcis utilisateur (homonymes éventuels)

	createCalls int
	removeCalls int
	placeErr    map[string]error // place → erreur de résolution (test error path)
}

func newFakeOps() *fakeShortcutOps {
	return &fakeShortcutOps{
		files:    map[string]ShortcutSpec{},
		userLnks: map[string]bool{},
		placeErr: map[string]error{},
	}
}

// PlaceDir : chemins fictifs déterministes par emplacement. Le bureau utilise
// le desktop_path résolu serveur (tokens conservés tels quels — la
// substitution réelle est Windows-only).
func (o *fakeShortcutOps) PlaceDir(spec ShortcutSpec) (string, error) {
	if err := o.placeErr[spec.Place]; err != nil {
		return "", err
	}
	switch spec.Place {
	case shortcutPlaceDesktop:
		if spec.DesktopPath == "" {
			// Probe desktop sans desktop_path (balayage des orphelins, review #2) :
			// l'OS résout le bureau STANDARD. Le fake renvoie le bureau local.
			return strings.TrimRight(localDesktop, `\/`), nil
		}

		return strings.TrimRight(spec.DesktopPath, `\/`), nil
	case shortcutPlaceStartup:
		return `C:\Users\test\Startup`, nil
	case shortcutPlaceTaskbar:
		return `C:\Users\test\TaskBar`, nil
	default:
		return "", fmt.Errorf("place inconnu : %q", spec.Place)
	}
}

func (o *fakeShortcutOps) ListManaged(dirs []string) ([]string, error) {
	want := map[string]bool{}
	for _, d := range dirs {
		want[strings.TrimRight(d, `\/`)] = true
	}
	managed := []string{}
	for path := range o.files {
		if want[dirOf(path)] {
			managed = append(managed, path)
		}
	}
	sort.Strings(managed)

	return managed, nil
}

func (o *fakeShortcutOps) Matches(path string, spec ShortcutSpec) (bool, error) {
	if o.userLnks[path] {
		// Homonyme utilisateur : non géré → (false, nil), JAMAIS une erreur
		// (review #1). Le handler consulte Blocked() avant Matches et saute ce
		// chemin, donc ce false n'entraîne jamais d'écrasement.
		return false, nil
	}
	cur, ok := o.files[path]
	if !ok {
		return false, nil
	}

	return cur == spec, nil
}

// Blocked : un raccourci utilisateur (homonyme non géré) occupe-t-il le chemin ?
func (o *fakeShortcutOps) Blocked(path string) (bool, error) {
	return o.userLnks[path], nil
}

func (o *fakeShortcutOps) Create(path string, spec ShortcutSpec) error {
	o.createCalls++
	o.files[path] = spec

	return nil
}

func (o *fakeShortcutOps) Remove(path string) error {
	o.removeCalls++
	delete(o.files, path)

	return nil
}

func dirOf(path string) string {
	i := strings.LastIndexByte(path, '\\')
	if i < 0 {
		return ""
	}

	return path[:i]
}

// item construit un StateItem `shortcuts` avec un payload donné.
func shortcutItem(name, target, place, desktopPath string) StateItem {
	payload := map[string]any{
		"name":   name,
		"target": target,
		"args":   "",
		"icon":   "",
		"place":  place,
	}
	if place == shortcutPlaceDesktop {
		payload["desktop_path"] = desktopPath
	}

	return StateItem{Type: "shortcuts", Semantics: "aggregate", Hash: name + "-h", Payload: payload}
}

const netDesktop = `\\<se4fs>\users\<user>\Bureau`
const localDesktop = `%USERPROFILE%\Desktop`

// --- Résolution du chemin par environnement (fix Bug C, côté agent) ----------

func TestShortcutsDesktopPathFromServer(t *testing.T) {
	cases := []struct {
		name        string
		desktopPath string
		wantDir     string
	}{
		{"bureau réseau (shared_local)", netDesktop, netDesktop},
		{"bureau local (personal/nomade)", localDesktop, localDesktop},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			ops := newFakeOps()
			h := &ShortcutsHandler{Ops: ops}
			items := []StateItem{shortcutItem("Intranet", "https://x", shortcutPlaceDesktop, tc.desktopPath)}

			if err := h.Apply(items); err != nil {
				t.Fatalf("apply: %v", err)
			}
			wantPath := tc.wantDir + `\Intranet.lnk`
			if _, ok := ops.files[wantPath]; !ok {
				t.Fatalf("raccourci attendu à %q, posés=%v", wantPath, ops.files)
			}
		})
	}
}

// --- Set cible + idempotence -------------------------------------------------

func TestShortcutsApplyCreatesTargetSetThenIdempotent(t *testing.T) {
	ops := newFakeOps()
	h := &ShortcutsHandler{Ops: ops}
	items := []StateItem{
		shortcutItem("Intranet", "https://intranet", shortcutPlaceDesktop, netDesktop),
		shortcutItem("Notepad", `C:\Windows\notepad.exe`, shortcutPlaceStartup, ""),
	}

	// 1re passe : crée les 2.
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply 1: %v", err)
	}
	if ops.createCalls != 2 {
		t.Fatalf("attendu 2 créations, obtenu %d", ops.createCalls)
	}

	// test = conforme après apply.
	ok, err := h.Test(items)
	if err != nil || !ok {
		t.Fatalf("test après apply : ok=%v err=%v (attendu conforme)", ok, err)
	}

	// 2e passe idempotente : aucune écriture.
	before := ops.createCalls
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply 2: %v", err)
	}
	if ops.createCalls != before {
		t.Fatalf("apply idempotent attendu : %d créations supplémentaires", ops.createCalls-before)
	}
}

// --- Suppression level-triggered (sorti des règles) --------------------------

func TestShortcutsRemovesManagedShortcutDroppedFromRules(t *testing.T) {
	ops := newFakeOps()
	h := &ShortcutsHandler{Ops: ops}
	full := []StateItem{
		shortcutItem("A", "ta", shortcutPlaceDesktop, netDesktop),
		shortcutItem("B", "tb", shortcutPlaceDesktop, netDesktop),
	}
	if err := h.Apply(full); err != nil {
		t.Fatalf("apply full: %v", err)
	}
	if len(ops.files) != 2 {
		t.Fatalf("attendu 2 raccourcis, obtenu %d", len(ops.files))
	}

	// B retiré des règles : convergence → B disparaît, A reste.
	reduced := []StateItem{shortcutItem("A", "ta", shortcutPlaceDesktop, netDesktop)}
	if err := h.Apply(reduced); err != nil {
		t.Fatalf("apply reduced: %v", err)
	}
	if _, exists := ops.files[netDesktop+`\B.lnk`]; exists {
		t.Fatalf("B aurait dû être supprimé (level-triggered) : %v", ops.files)
	}
	if _, exists := ops.files[netDesktop+`\A.lnk`]; !exists {
		t.Fatalf("A aurait dû rester")
	}
	if ops.removeCalls != 1 {
		t.Fatalf("attendu 1 suppression, obtenu %d", ops.removeCalls)
	}
}

// --- Un raccourci UTILISATEUR n'est jamais supprimé --------------------------

func TestShortcutsNeverDeletesUserCreatedShortcut(t *testing.T) {
	ops := newFakeOps()
	ops.userLnks[netDesktop+`\MesNotes.lnk`] = true // créé par l'utilisateur
	h := &ShortcutsHandler{Ops: ops}

	items := []StateItem{shortcutItem("A", "ta", shortcutPlaceDesktop, netDesktop)}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.removeCalls != 0 {
		t.Fatalf("aucun raccourci utilisateur ne doit être supprimé, removeCalls=%d", ops.removeCalls)
	}
}

// --- #6 : homonyme user au chemin EXACT d'une cible --------------------------
//
// Un `.lnk` utilisateur (sans marqueur) occupe le chemin EXACT d'un raccourci
// désiré (un prof a créé « Intranet » sur son bureau). Test/Apply ne plantent
// pas, ne suppriment/n'écrasent pas le fichier user, et les AUTRES raccourcis
// convergent quand même (review #1).
func TestShortcutsUserHomonymOnDesiredPathIsIgnored(t *testing.T) {
	ops := newFakeOps()
	intranetPath := netDesktop + `\Intranet.lnk`
	ops.userLnks[intranetPath] = true // raccourci créé par l'utilisateur, homonyme de la cible

	h := &ShortcutsHandler{Ops: ops}
	items := []StateItem{
		shortcutItem("Intranet", "https://intranet", shortcutPlaceDesktop, netDesktop), // homonyme bloqué
		shortcutItem("Notepad", `C:\Windows\notepad.exe`, shortcutPlaceStartup, ""),    // doit converger
	}

	// Apply ne plante pas.
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply ne doit pas planter sur un homonyme user : %v", err)
	}
	// Le fichier user n'est ni écrasé (pas dans files), ni supprimé (jamais listé).
	if _, overwritten := ops.files[intranetPath]; overwritten {
		t.Fatalf("le raccourci utilisateur homonyme NE doit PAS être écrasé")
	}
	if ops.removeCalls != 0 {
		t.Fatalf("aucun raccourci utilisateur ne doit être supprimé, removeCalls=%d", ops.removeCalls)
	}
	// L'AUTRE raccourci converge quand même.
	notepadPath := `C:\Users\test\Startup\Notepad.lnk`
	if _, ok := ops.files[notepadPath]; !ok {
		t.Fatalf("le raccourci hors homonyme aurait dû converger, posés=%v", ops.files)
	}

	// Test ne plante pas et NE bascule PAS tout le type en non-conforme à cause
	// du seul homonyme : la cible Notepad est posée et l'homonyme est ignoré → conforme.
	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test ne doit pas planter sur un homonyme user : %v", err)
	}
	if !ok {
		t.Fatalf("test devrait être conforme : l'homonyme user est ignoré, Notepad est posé")
	}
}

// --- #7 : orphelins cross-placement supprimés au passage suivant -------------
//
// Toutes les règles `desktop` sont retirées alors qu'une règle `startup`
// subsiste → le `.lnk` desktop GÉRÉ (marqueur) est supprimé au passage suivant
// (review #2 — l'union des emplacements gérables est balayée, pas seulement ceux
// du desired courant).
func TestShortcutsCrossPlacementOrphanRemoved(t *testing.T) {
	ops := newFakeOps()
	h := &ShortcutsHandler{Ops: ops}

	// Passage 1 : une règle desktop (bureau standard) + une règle startup. Le
	// bureau STANDARD (résoluble sans desktop_path) permet de tester le balayage
	// cross-placement même après disparition de toute règle desktop.
	full := []StateItem{
		shortcutItem("Intranet", "https://intranet", shortcutPlaceDesktop, localDesktop),
		shortcutItem("Notepad", `C:\Windows\notepad.exe`, shortcutPlaceStartup, ""),
	}
	if err := h.Apply(full); err != nil {
		t.Fatalf("apply full: %v", err)
	}
	desktopLnk := strings.TrimRight(localDesktop, `\/`) + `\Intranet.lnk`
	startupLnk := `C:\Users\test\Startup\Notepad.lnk`
	if _, ok := ops.files[desktopLnk]; !ok {
		t.Fatalf("raccourci desktop attendu après passage 1")
	}

	// Passage 2 : la règle desktop disparaît, seule la règle startup subsiste.
	reduced := []StateItem{shortcutItem("Notepad", `C:\Windows\notepad.exe`, shortcutPlaceStartup, "")}
	if err := h.Apply(reduced); err != nil {
		t.Fatalf("apply reduced: %v", err)
	}

	// L'orphelin desktop géré DOIT être supprimé même si plus aucune règle desktop.
	if _, exists := ops.files[desktopLnk]; exists {
		t.Fatalf("le raccourci desktop orphelin aurait dû être supprimé (cross-placement) : %v", ops.files)
	}
	// La règle startup reste posée.
	if _, exists := ops.files[startupLnk]; !exists {
		t.Fatalf("le raccourci startup aurait dû rester")
	}
}

// --- Payload invalide → error (enveloppe) ------------------------------------

func TestShortcutsInvalidPayloadIsError(t *testing.T) {
	ops := newFakeOps()
	h := &ShortcutsHandler{Ops: ops}

	cases := []struct {
		name    string
		payload map[string]any
	}{
		{"desktop sans desktop_path", map[string]any{"name": "X", "place": "desktop", "target": "t"}},
		{"place inconnu", map[string]any{"name": "X", "place": "bogus", "target": "t"}},
		{"name vide", map[string]any{"name": "", "place": "startup", "target": "t"}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			items := []StateItem{{Type: "shortcuts", Semantics: "aggregate", Hash: "h", Payload: tc.payload}}
			if _, err := h.Test(items); err == nil {
				t.Fatalf("payload invalide attendu en erreur")
			}
		})
	}
}

// --- Dédup : empreinte d'agrégat stable, ordre serveur (réutilise le moteur) --

func TestShortcutsAggregateHashIsServerOrderConcat(t *testing.T) {
	// L'empreinte est la concat des hashes opaques (engine.AggregateHash) — la
	// dédup de contenu est FAITE CÔTÉ SERVEUR (StateCompiler) ; l'agent ne
	// recompose jamais un hash depuis sa sérialisation.
	items := []StateItem{
		shortcutItem("A", "ta", shortcutPlaceDesktop, netDesktop),
		shortcutItem("B", "tb", shortcutPlaceStartup, ""),
	}
	got := AggregateHash(items)
	if got == "" || len(got) != 64 {
		t.Fatalf("empreinte d'agrégat invalide : %q", got)
	}
	// Déterministe : mêmes items, même ordre → même empreinte.
	if AggregateHash(items) != got {
		t.Fatalf("empreinte non déterministe")
	}
}

// --- Machine d'états §5 via le moteur (STRICT inconditionnel, Story 27.8) -----

func TestShortcutsThroughEngineSection5(t *testing.T) {
	items := []StateItem{shortcutItem("A", "ta", shortcutPlaceDesktop, netDesktop)}
	targetHash := AggregateHash(items)

	cases := []struct {
		name        string
		seedManaged bool // un raccourci géré DIVERGENT déjà sur le poste
		lastApplied string
		wantStatus  string
		wantCreate  bool
	}{
		{"premier passage → drift + apply", false, "", "drift", true},
		{"dérive → réapplique (drift)", true, targetHash, "drift", true},
		{"dérive même dernier=cible → réapplique (strict)", true, targetHash, "drift", true},
		{"conforme → compliant", false, targetHash, "compliant", false},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			ops := newFakeOps()
			if tc.seedManaged {
				// Un raccourci géré au bon chemin mais DIVERGENT (target ≠ cible)
				// → réel ≠ cible.
				ops.files[netDesktop+`\A.lnk`] = ShortcutSpec{Name: "A", Target: "DIVERGENT", Place: "desktop", DesktopPath: netDesktop}
			}
			if tc.name == "conforme → compliant" {
				ops.files[netDesktop+`\A.lnk`] = ShortcutSpec{Name: "A", Target: "ta", Place: "desktop", DesktopPath: netDesktop}
			}

			h := &ShortcutsHandler{Ops: ops}
			engine := &Engine{Handlers: map[string]Handler{"shortcuts": h}}
			it := []StateItem{shortcutItem("A", "ta", shortcutPlaceDesktop, netDesktop)}

			applied := AppliedState{}
			if tc.lastApplied != "" {
				applied["shortcuts"] = AppliedEntry{Hash: tc.lastApplied}
			}

			report := engine.RunPass(it, applied)
			if len(report) != 1 {
				t.Fatalf("attendu 1 item de rapport, obtenu %d", len(report))
			}
			if report[0].Status != tc.wantStatus {
				t.Fatalf("statut = %q, attendu %q", report[0].Status, tc.wantStatus)
			}
			created := ops.createCalls > 0
			if created != tc.wantCreate {
				t.Fatalf("création = %v, attendu %v (createCalls=%d)", created, tc.wantCreate, ops.createCalls)
			}
		})
	}
}

// Bug terrain 27.1 : la convention `chemin,index` du `.lnk` doit être décomposée
// avant SetIconLocation, sinon `…\firefox.exe,0` est pris comme chemin de fichier
// (introuvable → icône « feuille blanche »).
func TestParseIconLocation(t *testing.T) {
	cases := []struct {
		name      string
		icon      string
		wantPath  string
		wantIndex int
	}{
		{"vide", "", "", 0},
		{"exe avec index 0", `C:\Program Files\Mozilla Firefox\firefox.exe,0`, `C:\Program Files\Mozilla Firefox\firefox.exe`, 0},
		{"exe avec index positif", `C:\Windows\System32\shell32.dll,42`, `C:\Windows\System32\shell32.dll`, 42},
		{"index négatif (ressource par id)", `C:\app\res.dll,-3`, `C:\app\res.dll`, -3},
		{"index avec espaces tolérés", `C:\app\icon.dll, 5`, `C:\app\icon.dll`, 5},
		{"ico sans index", `%APPDATA%\pronote.ico`, `%APPDATA%\pronote.ico`, 0},
		{"chemin sans virgule", `C:\app\firefox.exe`, `C:\app\firefox.exe`, 0},
		{"virgule non suivie d'un entier = partie du chemin", `C:\dir,with,comma\icon.ico`, `C:\dir,with,comma\icon.ico`, 0},
		{"suffixe non entier", `C:\app\icon.dll,abc`, `C:\app\icon.dll,abc`, 0},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			path, index := ParseIconLocation(tc.icon)
			if path != tc.wantPath || index != tc.wantIndex {
				t.Fatalf("ParseIconLocation(%q) = (%q, %d), attendu (%q, %d)",
					tc.icon, path, index, tc.wantPath, tc.wantIndex)
			}
		})
	}
}

// --- Story 27.7 : icône UPLOADÉE (icon_asset/icon_checksum) ------------------

func TestParseShortcutSpecCarriesUploadedIconFields(t *testing.T) {
	sha := strings.Repeat("a", 64)
	payload := map[string]any{
		"name":          "Calculatrice",
		"target":        `C:\Windows\System32\calc.exe`,
		"args":          "",
		"icon":          "Calculatrice", // nom nu (icône uploadée)
		"icon_asset":    sha + ".ico",
		"icon_checksum": sha,
		"place":         "startup",
	}
	spec, ok := parseShortcutSpec(payload)
	if !ok {
		t.Fatal("spec valide attendue")
	}
	if spec.IconAsset != sha+".ico" || spec.IconChecksum != sha {
		t.Fatalf("champs icône uploadée non portés : %+v", spec)
	}
}

func TestParseShortcutSpecStripsInvalidUploadedIcon(t *testing.T) {
	// icon_asset hors format content-addressed → remis à "" (on retombera sur
	// l'icône brute, jamais un asset cassé — piège n° 3).
	for _, bad := range []map[string]any{
		{"name": "x", "place": "startup", "icon": "x", "icon_asset": "../evil.ico", "icon_checksum": strings.Repeat("a", 64)},
		{"name": "x", "place": "startup", "icon": "x", "icon_asset": strings.Repeat("a", 64) + ".ico", "icon_checksum": "tooshort"},
	} {
		spec, ok := parseShortcutSpec(bad)
		if !ok {
			t.Fatalf("spec valide attendue pour %v", bad)
		}
		if spec.IconAsset != "" || spec.IconChecksum != "" {
			t.Errorf("asset hors format devrait être strippé : %+v", spec)
		}
	}
}

func TestResolveUploadedIconLocation(t *testing.T) {
	dir := t.TempDir()
	sha := strings.Repeat("a", 64)
	filename := sha + ".ico"

	// Asset NON présent localement → "" (icône défaut, jamais cassée).
	if got := ResolveUploadedIconLocation(filename, dir); got != "" {
		t.Errorf("asset absent → \"\" attendu, got %q", got)
	}

	// Asset présent → chemin local absolu.
	local := filepath.Join(dir, filename)
	if err := os.WriteFile(local, []byte("ico"), 0o644); err != nil {
		t.Fatal(err)
	}
	if got := ResolveUploadedIconLocation(filename, dir); got != local {
		t.Errorf("asset présent → %q attendu, got %q", local, got)
	}

	// Pas d'asset / pas de dir → "" (icône réelle gérée hors de cette fonction).
	if got := ResolveUploadedIconLocation("", dir); got != "" {
		t.Errorf("pas d'asset → \"\" attendu, got %q", got)
	}
	if got := ResolveUploadedIconLocation(filename, ""); got != "" {
		t.Errorf("pas de iconsDir → \"\" attendu, got %q", got)
	}
}
