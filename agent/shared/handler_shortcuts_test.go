package shared

import (
	"encoding/json"
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
	// desktopPathErr : desktop_path → erreur de résolution (Story 27.21 —
	// simule un Bureau RÉSEAU non résoluble sur CE poste, hors-domaine, sans
	// casser la résolution du Bureau local).
	desktopPathErr map[string]error
}

func newFakeOps() *fakeShortcutOps {
	return &fakeShortcutOps{
		files:          map[string]ShortcutSpec{},
		userLnks:       map[string]bool{},
		placeErr:       map[string]error{},
		desktopPathErr: map[string]error{},
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
		if err := o.desktopPathErr[spec.DesktopPath]; err != nil {
			return "", err
		}
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

// shortcutItemSweep : idem, mais le serveur NOMME les emplacements Bureau à
// BALAYER (`desktop_sweep_paths`, Story 27.21 arbitrage option A). Le champ est
// un `[]any` — la forme réelle après décodage JSON du contrat.
func shortcutItemSweep(name, target, place, desktopPath string, sweep []string) StateItem {
	item := shortcutItem(name, target, place, desktopPath)
	payload, _ := item.Payload.(map[string]any)
	raw := make([]any, 0, len(sweep))
	for _, p := range sweep {
		raw = append(raw, p)
	}
	payload["desktop_sweep_paths"] = raw

	return item
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

// --- Story 27.21 : le SERVEUR nomme les Bureaux à balayer (option A) ---------
//
// Le serveur désigne l'emplacement de POSE via `desktop_path` (réseau si parc
// partagé ET home accessible, local sinon) ET les emplacements de BALAYAGE via
// `desktop_sweep_paths` — deux notions distinctes. Sur un parc `shared_local`,
// le serveur ordonne les DEUX Bureaux, pour supprimer les `.lnk` GÉRÉS restés
// sur l'emplacement devenu inactif après une bascule de la politique home
// (AC2/AC3). Sur un parc perdir/nomade, il n'ordonne que le Bureau local.

func TestShortcutsManagedDirsSweepServerNamedDesktops(t *testing.T) {
	bothDesktops := []string{netDesktop, localDesktop}
	cases := []struct {
		name     string
		desired  []StateItem
		wantDirs []string
	}{
		{
			// Le champ vit sur TOUS les items du type — un parc partagé sans
			// aucune règle `desktop` fait quand même balayer les deux Bureaux
			// (leçon review #2 de 27.1 : sinon orphelins à vie).
			"aucune règle desktop (parc partagé)",
			[]StateItem{shortcutItemSweep("N", `C:\n.exe`, shortcutPlaceStartup, "", bothDesktops)},
			[]string{netDesktop, strings.TrimRight(localDesktop, `\/`)},
		},
		{
			"règle desktop RÉSEAU (parc partagé, home on)",
			[]StateItem{shortcutItemSweep("A", "ta", shortcutPlaceDesktop, netDesktop, bothDesktops)},
			[]string{netDesktop, strings.TrimRight(localDesktop, `\/`)},
		},
		{
			"règle desktop LOCALE (parc partagé, home off)",
			[]StateItem{shortcutItemSweep("A", "ta", shortcutPlaceDesktop, localDesktop, bothDesktops)},
			[]string{netDesktop, strings.TrimRight(localDesktop, `\/`)},
		},
		{
			// LE cas du finding #1 : aucun Bureau réseau dans les dirs balayés.
			"parc perdir/nomade : Bureau LOCAL seul",
			[]StateItem{shortcutItemSweep("A", "ta", shortcutPlaceDesktop, localDesktop, []string{localDesktop})},
			[]string{strings.TrimRight(localDesktop, `\/`)},
		},
		{
			// Serveur antérieur à 27.21 (champ absent) : repli CONSERVATEUR sur
			// les seuls emplacements propres au poste — jamais le Bureau réseau.
			"champ absent (serveur antérieur) : repli local",
			[]StateItem{shortcutItem("A", "ta", shortcutPlaceDesktop, localDesktop)},
			[]string{strings.TrimRight(localDesktop, `\/`)},
		},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			ops := newFakeOps()
			h := &ShortcutsHandler{Ops: ops}
			desired, err := h.desiredSet(tc.desired)
			if err != nil {
				t.Fatalf("desiredSet: %v", err)
			}

			dirs, err := h.managedDirs(desired, sweepPathsFrom(tc.desired))
			if err != nil {
				t.Fatalf("managedDirs: %v", err)
			}

			for _, want := range tc.wantDirs {
				if !containsDir(dirs, want) {
					t.Fatalf("le Bureau %q doit être balayé (dirs=%v)", want, dirs)
				}
			}
			// …sans perdre les autres emplacements gérables.
			for _, want := range []string{`C:\Users\test\Startup`, `C:\Users\test\TaskBar`} {
				if !containsDir(dirs, want) {
					t.Fatalf("l'emplacement %q doit rester balayé (dirs=%v)", want, dirs)
				}
			}
			// Aucun emplacement de PLUS que ceux attendus : un Bureau réseau non
			// ordonné ne doit JAMAIS se glisser dans la liste.
			if len(dirs) != len(tc.wantDirs)+2 {
				t.Fatalf("%d répertoires distincts attendus (%v + startup + taskbar), obtenu %v",
					len(tc.wantDirs)+2, tc.wantDirs, dirs)
			}
		})
	}
}

// Verrou serveur⇄agent SANS duplication de littéral : on lit le golden
// CANONIQUE partagé (`tests/Fixtures/Agent/state.v1.json`, la même source de
// vérité que le hash croisé NFR13) et on prouve que le handler balaie
// EXACTEMENT les emplacements qu'il porte. Si le serveur change sa convention de
// chemin, le golden change et ce test suit — plus aucune constante réseau côté
// agent (le jumelage Go-vs-Go tautologique de la 1re passe est supprimé,
// review 27.21 #2).
func TestShortcutsSweepsExactlyTheGoldenNamedDesktops(t *testing.T) {
	var state struct {
		MachineUser []struct {
			Type    string         `json:"type"`
			Payload map[string]any `json:"payload"`
		} `json:"machine_user"`
	}
	if err := json.Unmarshal(goldenFile(t, "state.v1.json"), &state); err != nil {
		t.Fatalf("golden illisible : %v", err)
	}

	items := []StateItem{}
	wantDirs := []string{}
	for _, raw := range state.MachineUser {
		if raw.Type != "shortcuts" {
			continue
		}
		items = append(items, StateItem{Type: raw.Type, Semantics: "aggregate", Hash: "golden", Payload: raw.Payload})
		for _, path := range sweepPathValues(raw.Payload[shortcutSweepPathsKey]) {
			wantDirs = append(wantDirs, strings.TrimRight(path, `\/`))
		}
	}
	if len(items) == 0 {
		t.Fatal("le golden doit porter au moins un item `shortcuts` (portée machine_user)")
	}
	if len(wantDirs) == 0 {
		t.Fatalf("le golden doit porter %q sur son item `shortcuts` (contrat 27.21)", shortcutSweepPathsKey)
	}

	ops := newFakeOps()
	h := &ShortcutsHandler{Ops: ops}
	desired, err := h.desiredSet(items)
	if err != nil {
		t.Fatalf("desiredSet: %v", err)
	}
	dirs, err := h.managedDirs(desired, sweepPathsFrom(items))
	if err != nil {
		t.Fatalf("managedDirs: %v", err)
	}
	for _, want := range wantDirs {
		if !containsDir(dirs, want) {
			t.Fatalf("le Bureau %q nommé par le golden doit être balayé (dirs=%v)", want, dirs)
		}
	}
}

// Bascule de la politique home dans les DEUX sens : le `.lnk` géré de
// l'emplacement devenu inactif est supprimé au passage suivant, celui de
// l'emplacement actif est posé. Zéro orphelin (AC3).
func TestShortcutsHomePolicySwitchLeavesNoOrphan(t *testing.T) {
	cases := []struct {
		name     string
		fromPath string
		toPath   string
	}{
		{"K: coupé : réseau → local", netDesktop, localDesktop},
		{"K: réactivé : local → réseau", localDesktop, netDesktop},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			ops := newFakeOps()
			h := &ShortcutsHandler{Ops: ops}
			fromLnk := strings.TrimRight(tc.fromPath, `\/`) + `\Intranet.lnk`
			toLnk := strings.TrimRight(tc.toPath, `\/`) + `\Intranet.lnk`

			// Parc `shared_local` : le serveur ORDONNE le balayage des deux
			// Bureaux (c'est ce parc, et lui seul, qui a autorité sur le réseau).
			bothDesktops := []string{netDesktop, localDesktop}

			// Passage 1 : la politique en vigueur pose le raccourci ici.
			before := []StateItem{shortcutItemSweep("Intranet", "https://intranet", shortcutPlaceDesktop, tc.fromPath, bothDesktops)}
			if err := h.Apply(before); err != nil {
				t.Fatalf("apply 1: %v", err)
			}
			if _, ok := ops.files[fromLnk]; !ok {
				t.Fatalf("raccourci attendu à %q après le 1er passage, posés=%v", fromLnk, ops.files)
			}

			// L'admin bascule la politique home ⇒ le serveur émet l'AUTRE Bureau
			// en POSE, mais la liste de BALAYAGE ne bouge pas (elle ne dépend que
			// de l'environnement du parc).
			after := []StateItem{shortcutItemSweep("Intranet", "https://intranet", shortcutPlaceDesktop, tc.toPath, bothDesktops)}

			// Test doit être NON conforme AVANT la convergence : le résidu géré de
			// l'emplacement inactif compte comme une dérive (level-triggered honnête).
			ok, err := h.Test(after)
			if err != nil {
				t.Fatalf("test: %v", err)
			}
			if ok {
				t.Fatalf("test devait être NON conforme (résidu géré sur l'ancien Bureau)")
			}

			if err := h.Apply(after); err != nil {
				t.Fatalf("apply 2: %v", err)
			}
			if _, exists := ops.files[fromLnk]; exists {
				t.Fatalf("le `.lnk` géré de l'emplacement INACTIF devait être supprimé : %v", ops.files)
			}
			if _, ok := ops.files[toLnk]; !ok {
				t.Fatalf("le raccourci devait être posé à %q, posés=%v", toLnk, ops.files)
			}

			// Convergence atteinte + idempotence.
			ok, err = h.Test(after)
			if err != nil || !ok {
				t.Fatalf("test après bascule : ok=%v err=%v (attendu conforme)", ok, err)
			}
			removes := ops.removeCalls
			creates := ops.createCalls
			if err := h.Apply(after); err != nil {
				t.Fatalf("apply 3: %v", err)
			}
			if ops.removeCalls != removes || ops.createCalls != creates {
				t.Fatalf("passe stable non idempotente (removes %d→%d, creates %d→%d)",
					removes, ops.removeCalls, creates, ops.createCalls)
			}
		})
	}
}

// Un `.lnk` NON marqué (créé par l'utilisateur) présent dans CHACUN des deux
// Bureaux n'est jamais supprimé — l'élargissement du balayage n'élargit PAS le
// périmètre de suppression (garantie ListManaged/marqueur, AC2).
func TestShortcutsNeverDeletesUserLnkInEitherDesktop(t *testing.T) {
	ops := newFakeOps()
	ops.userLnks[netDesktop+`\MesNotes.lnk`] = true
	ops.userLnks[strings.TrimRight(localDesktop, `\/`)+`\MesNotes.lnk`] = true
	h := &ShortcutsHandler{Ops: ops}
	bothDesktops := []string{netDesktop, localDesktop}

	// Bascule complète (réseau → local) avec des fichiers user dans les deux.
	if err := h.Apply([]StateItem{shortcutItemSweep("A", "ta", shortcutPlaceDesktop, netDesktop, bothDesktops)}); err != nil {
		t.Fatalf("apply 1: %v", err)
	}
	if err := h.Apply([]StateItem{shortcutItemSweep("A", "ta", shortcutPlaceDesktop, localDesktop, bothDesktops)}); err != nil {
		t.Fatalf("apply 2: %v", err)
	}

	// Le résidu GÉRÉ du réseau est parti ; les deux fichiers USER sont intacts.
	if _, exists := ops.files[netDesktop+`\A.lnk`]; exists {
		t.Fatalf("le raccourci géré de l'ancien Bureau devait partir")
	}
	if ops.removeCalls != 1 {
		t.Fatalf("exactement 1 suppression attendue (le seul géré orphelin), obtenu %d", ops.removeCalls)
	}
	for path := range ops.userLnks {
		if _, wiped := ops.files[path]; wiped {
			t.Fatalf("le raccourci utilisateur %q ne doit jamais être écrasé", path)
		}
	}
}

// Fail-soft : un Bureau non résoluble sur CE poste (hors-domaine — `<se4fs>`
// sans valeur) n'est PAS fatal. La probe est ignorée, les autres emplacements
// convergent (AC2).
func TestShortcutsUnresolvableNetworkDesktopIsNotFatal(t *testing.T) {
	ops := newFakeOps()
	ops.desktopPathErr[netDesktop] = fmt.Errorf("SE4FS non défini")
	h := &ShortcutsHandler{Ops: ops}

	// Le serveur ordonne bien les deux Bureaux (parc partagé), mais le Bureau
	// réseau n'est pas résoluble sur CE poste.
	items := []StateItem{shortcutItemSweep(
		"A", "ta", shortcutPlaceDesktop, localDesktop, []string{netDesktop, localDesktop},
	)}
	if err := h.Apply(items); err != nil {
		t.Fatalf("un Bureau réseau non résoluble ne doit pas faire échouer la passe : %v", err)
	}
	localLnk := strings.TrimRight(localDesktop, `\/`) + `\A.lnk`
	if _, ok := ops.files[localLnk]; !ok {
		t.Fatalf("le raccourci local devait converger malgré la probe réseau ignorée, posés=%v", ops.files)
	}
	ok, err := h.Test(items)
	if err != nil || !ok {
		t.Fatalf("test : ok=%v err=%v (attendu conforme)", ok, err)
	}
}

func TestUsableShortcutDir(t *testing.T) {
	cases := []struct {
		dir  string
		want bool
	}{
		{`\\se4fs\users\bob\Bureau\`, true},
		{`C:\Users\bob\Desktop`, true},
		{"", false},
		{"   ", false},
		{`\\\users\bob\Bureau\`, false}, // `<se4fs>` non substitué → serveur vide
		{`\\`, false},
	}
	for _, tc := range cases {
		if got := UsableShortcutDir(tc.dir); got != tc.want {
			t.Fatalf("UsableShortcutDir(%q) = %v, attendu %v", tc.dir, got, tc.want)
		}
	}
}

// --- Story 27.21 (arbitrage option A) : le SERVEUR nomme les Bureaux balayés --
//
// LE test de non-régression du finding #1 (🔴 review 27.21) : le Bureau RÉSEAU
// `\\<se4fs>\users\<user>\Bureau\` est un emplacement PAR UTILISATEUR, PARTAGÉ
// entre TOUS ses postes, alors que le desired-state est compilé par couple
// (poste, user). Un poste `personal_local`/`nomade` n'a AUCUNE autorité dessus :
// s'il le balayait, il y supprimerait les `.lnk` gérés légitimement posés par un
// poste `shared_local` du même utilisateur (ping-pong permanent de
// suppressions/re-créations sur un partage de production).
//
// Depuis l'option A, l'agent n'invente plus l'emplacement réseau : il balaie
// EXACTEMENT les chemins que le serveur lui nomme (`desktop_sweep_paths`).
func TestShortcutsPerdirNeverSweepsNetworkDesktop(t *testing.T) {
	ops := newFakeOps()
	// Un `.lnk` GÉRÉ posé sur le Bureau RÉSEAU par un AUTRE poste (shared_local)
	// du même utilisateur — parfaitement légitime, invisible de ce poste-ci.
	netLnk := netDesktop + `\Intranet.lnk`
	ops.files[netLnk] = ShortcutSpec{
		Name: "Intranet", Target: "https://intranet", Place: shortcutPlaceDesktop, DesktopPath: netDesktop,
	}

	h := &ShortcutsHandler{Ops: ops}
	// Poste perdir/nomade : le serveur n'ordonne QUE le Bureau local.
	items := []StateItem{shortcutItemSweep(
		"Intranet", "https://intranet", shortcutPlaceDesktop, localDesktop, []string{localDesktop},
	)}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if _, survived := ops.files[netLnk]; !survived {
		t.Fatalf("FINDING #1 : le poste perdir a supprimé un `.lnk` géré du Bureau RÉSEAU partagé (%q) — il n'a aucune autorité dessus", netLnk)
	}
	if ops.removeCalls != 0 {
		t.Fatalf("aucune suppression attendue sur un poste sans autorité réseau, removeCalls=%d", ops.removeCalls)
	}

	// …et l'état du poste converge quand même : le raccourci local est posé et
	// `Test` est conforme (le résidu réseau n'est PAS de son ressort — sinon le
	// poste rapporterait une dérive qu'il ne peut pas corriger, indéfiniment).
	localLnk := strings.TrimRight(localDesktop, `\/`) + `\Intranet.lnk`
	if _, ok := ops.files[localLnk]; !ok {
		t.Fatalf("le raccourci local devait être posé, posés=%v", ops.files)
	}
	ok, err := h.Test(items)
	if err != nil || !ok {
		t.Fatalf("test : ok=%v err=%v (attendu conforme — le Bureau réseau est hors de son périmètre)", ok, err)
	}
}

// Le pendant : un poste `shared_local` — à qui le serveur ORDONNE les deux
// emplacements — nettoie toujours correctement l'emplacement devenu inactif
// (le double-balayage anti-orphelins de l'AC2/AC3 reste pleinement en vigueur).
func TestShortcutsSharedLocalStillSweepsBothDesktops(t *testing.T) {
	ops := newFakeOps()
	netLnk := netDesktop + `\Intranet.lnk`
	ops.files[netLnk] = ShortcutSpec{
		Name: "Intranet", Target: "https://intranet", Place: shortcutPlaceDesktop, DesktopPath: netDesktop,
	}

	h := &ShortcutsHandler{Ops: ops}
	// Parc partagé, home coupé : pose en LOCAL, balayage des DEUX Bureaux.
	items := []StateItem{shortcutItemSweep(
		"Intranet", "https://intranet", shortcutPlaceDesktop, localDesktop, []string{netDesktop, localDesktop},
	)}

	// Level-triggered honnête : le résidu réseau rend l'item NON conforme.
	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test: %v", err)
	}
	if ok {
		t.Fatalf("test devait être NON conforme (résidu géré sur le Bureau réseau balayé)")
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if _, exists := ops.files[netLnk]; exists {
		t.Fatalf("le `.lnk` géré du Bureau réseau devait être nettoyé sur un poste shared_local : %v", ops.files)
	}
	if _, ok := ops.files[strings.TrimRight(localDesktop, `\/`)+`\Intranet.lnk`]; !ok {
		t.Fatalf("le raccourci devait être posé en local, posés=%v", ops.files)
	}
}

func containsDir(dirs []string, want string) bool {
	for _, d := range dirs {
		if strings.TrimRight(d, `\/`) == strings.TrimRight(want, `\/`) {
			return true
		}
	}

	return false
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
