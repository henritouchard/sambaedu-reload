package shared

import (
	"fmt"
	"sort"
	"strings"
	"testing"
	"time"
)

// fakeApplicationsOps : ApplicationsOps en mémoire (testable hôte). Modélise
// l'état installé (`wpkg.xml`) + le résultat du déclenchement WPKG, avec des
// erreurs injectables et des compteurs.
type fakeApplicationsOps struct {
	// installed : ensemble des package-id présents dans `wpkg.xml`.
	installed map[string]bool
	// installsOnTrigger : package-id que le run WPKG INSTALLE quand il est
	// déclenché (simule la résolution/installation par WPKG). nil = le run
	// n'installe rien de neuf (poste déjà convergé / installeur en échec).
	installsOnTrigger []string
	// listErr : erreur de lecture de `wpkg.xml` (corrompu/illisible).
	listErr error
	// triggerErr : erreur de déclenchement (profil non déposable, cscript absent).
	triggerErr error
	// deployed : package-id du `profiles.xml` par-hôte DÉJÀ déposé (« ce que
	// l'agent gère »). Mis à jour par TriggerWpkg (= dropHostProfile écrit le
	// profil = ensemble cible). Vide = jamais déposé.
	deployed []string
	// deployedErr : erreur de lecture du profil déposé (corrompu/illisible).
	deployedErr error

	triggerCnt int
	lastSpecs  []ApplicationsSpec
}

func newFakeApplicationsOps() *fakeApplicationsOps {
	return &fakeApplicationsOps{installed: map[string]bool{}}
}

func (o *fakeApplicationsOps) installedList() []string {
	out := make([]string, 0, len(o.installed))
	for id := range o.installed {
		out = append(out, id)
	}
	sort.Strings(out)

	return out
}

func (o *fakeApplicationsOps) ListInstalled() ([]string, error) {
	if o.listErr != nil {
		return nil, o.listErr
	}

	return o.installedList(), nil
}

func (o *fakeApplicationsOps) DeployedProfileAppIds() ([]string, error) {
	if o.deployedErr != nil {
		return nil, o.deployedErr
	}

	return append([]string(nil), o.deployed...), nil
}

func (o *fakeApplicationsOps) TriggerWpkg(specs []ApplicationsSpec) (WpkgResult, error) {
	o.triggerCnt++
	o.lastSpecs = specs
	if o.triggerErr != nil {
		return WpkgResult{}, o.triggerErr
	}
	specSet := map[string]bool{}
	for _, s := range specs {
		specSet[s.AppId] = true
	}
	// WPKG `/synchronize` : désinstalle les paquets qui QUITTENT le profil déposé
	// (présents dans l'ancien profil, absents du nouvel ensemble cible).
	for _, id := range o.deployed {
		if !specSet[id] {
			delete(o.installed, id)
		}
	}
	// Le run WPKG installe les paquets prévus (simulation de la résolution).
	for _, id := range o.installsOnTrigger {
		o.installed[id] = true
	}
	// dropHostProfile : le profil déposé devient le nouvel ensemble cible.
	o.deployed = make([]string, 0, len(specs))
	for _, s := range specs {
		o.deployed = append(o.deployed, s.AppId)
	}

	return WpkgResult{Triggered: true, Installed: o.installedList()}, nil
}

// applicationsItem construit un StateItem `applications` (aggregate) pour une app.
func applicationsItem(appID, name string) StateItem {
	return StateItem{
		Type:      "applications",
		Semantics: "aggregate",
		Hash:      appID + "-h",
		Payload: map[string]any{
			"app_id": appID,
			"name":   name,
		},
	}
}

// --- Test : désiré ⊆ installé → compliant (pas de re-déclenchement) ----------

func TestApplicationsTestCompliantWhenAllInstalled(t *testing.T) {
	ops := newFakeApplicationsOps()
	// `extra` = logiciel installé HORS-SE5 (jamais dans notre profil déposé) :
	// il ne doit PAS provoquer de non-conformité (périmètre géré = profil déposé,
	// pas le set installé complet).
	ops.installed = map[string]bool{"firefox": true, "vlc": true, "extra": true}
	ops.deployed = []string{"firefox", "vlc"}
	h := &ApplicationsHandler{Ops: ops}
	items := []StateItem{
		applicationsItem("firefox", "Mozilla Firefox"),
		applicationsItem("vlc", "VLC"),
	}

	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test : %v", err)
	}
	if !ok {
		t.Fatalf("désiré ⊆ installé → compliant attendu (toutes les apps cibles présentes)")
	}
	// Compliant ne déclenche JAMAIS WPKG (level-triggered).
	if ops.triggerCnt != 0 {
		t.Errorf("compliant ne doit PAS déclencher WPKG, obtenu %d déclenchement(s)", ops.triggerCnt)
	}
	// Inventaire renseigné (AC4) : toutes installées.
	inv := h.Inventory()
	if len(inv) != 2 {
		t.Fatalf("inventaire : 2 apps attendues, obtenu %d", len(inv))
	}
	for _, r := range inv {
		if !r.Installed {
			t.Errorf("app %q devrait être inventoriée installée", r.AppId)
		}
	}
}

func TestApplicationsTestNotCompliantWhenAnyMissing(t *testing.T) {
	ops := newFakeApplicationsOps()
	ops.installed = map[string]bool{"firefox": true} // vlc manquant
	h := &ApplicationsHandler{Ops: ops}
	items := []StateItem{
		applicationsItem("firefox", "Mozilla Firefox"),
		applicationsItem("vlc", "VLC"),
	}

	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test : %v", err)
	}
	if ok {
		t.Fatalf("une app cible manquante (vlc) → non conforme attendu")
	}
	// L'inventaire reflète l'état réel (vlc absent).
	inv := map[string]bool{}
	for _, r := range h.Inventory() {
		inv[r.AppId] = r.Installed
	}
	if !inv["firefox"] || inv["vlc"] {
		t.Errorf("inventaire incohérent : firefox=%v vlc=%v (attendu true/false)", inv["firefox"], inv["vlc"])
	}
}

// --- RETRAIT : une app quittant le profil → non conforme → désinstallation ---
//
// Régression terrain 2026-06-19 : retirer adnarn/ganttProject d'un profil ne les
// désinstallait pas. Cause : `Test = désiré ⊆ installé` restait vert (les apps
// RESTANTES sont installées) → `Apply` jamais déclenché → `profiles.xml` jamais
// réécrit → WPKG jamais relancé. Fix : `Test` compare aussi le desired set au
// profil DÉPOSÉ ; un retrait (desired ⊊ déposé) le rend non conforme → `Apply`
// réécrit le profil + relance `/synchronize`, qui désinstalle nativement (<remove>).
func TestApplicationsTestNotCompliantWhenAppRemovedFromProfile(t *testing.T) {
	ops := newFakeApplicationsOps()
	// adnarn était géré (dans le profil déposé) ET installé, comme firefox/vlc.
	ops.installed = map[string]bool{"firefox": true, "vlc": true, "adnarn": true}
	ops.deployed = []string{"firefox", "vlc", "adnarn"}
	h := &ApplicationsHandler{Ops: ops}
	// adnarn retiré du profil côté serveur → desired set sans adnarn.
	items := []StateItem{
		applicationsItem("firefox", "Mozilla Firefox"),
		applicationsItem("vlc", "VLC"),
	}

	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test : %v", err)
	}
	if ok {
		t.Fatalf("app retirée (adnarn) toujours dans le profil déposé → non conforme attendu (désiré ⊊ déposé)")
	}
}

// Profil par-hôte déposé illisible → Test remonte l'erreur (le moteur rend error ;
// jamais un faux compliant — on ne peut pas déterminer le périmètre géré).
func TestApplicationsTestErrorWhenDeployedProfileUnreadable(t *testing.T) {
	ops := newFakeApplicationsOps()
	ops.installed = map[string]bool{"firefox": true}
	ops.deployedErr = fmt.Errorf("profiles.xml corrompu")
	h := &ApplicationsHandler{Ops: ops}
	items := []StateItem{applicationsItem("firefox", "Mozilla Firefox")}

	if _, err := h.Test(items); err == nil {
		t.Errorf("Test doit remonter l'erreur de lecture du profil par-hôte déposé")
	}
}

// --- Apply : déclenche WPKG, qui installe ce qui manque ----------------------

func TestApplicationsApplyTriggersWpkgAndConverges(t *testing.T) {
	ops := newFakeApplicationsOps()
	// Le run WPKG installe firefox + vlc (résolution par WPKG).
	ops.installsOnTrigger = []string{"firefox", "vlc"}
	h := &ApplicationsHandler{Ops: ops}
	items := []StateItem{
		applicationsItem("firefox", "Mozilla Firefox"),
		applicationsItem("vlc", "VLC"),
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply : %v", err)
	}
	if ops.triggerCnt != 1 {
		t.Fatalf("apply doit déclencher WPKG exactement 1 fois, obtenu %d", ops.triggerCnt)
	}
	// Le profil par-hôte (specs) a bien été passé au déclencheur (D9).
	if len(ops.lastSpecs) != 2 {
		t.Errorf("le déclencheur doit recevoir l'ensemble cible (2 specs), obtenu %d", len(ops.lastSpecs))
	}

	// Après le run, Test est compliant (idempotence / level-triggered).
	ok, err := h.Test(items)
	if err != nil || !ok {
		t.Fatalf("après apply, Test doit être compliant : ok=%v err=%v", ok, err)
	}
}

// --- Effort maximal : WPKG déclenché mais une app reste manquante → error ----
//
// Leçon 🟠 27.4 #7 : jamais un faux `compliant`. Un installeur en échec laisse
// l'app absente après le run → error + detail, jamais un compliant optimiste.
func TestApplicationsApplyErrorWhenAppStillMissingAfterRun(t *testing.T) {
	ops := newFakeApplicationsOps()
	// Le run installe firefox mais PAS vlc (installeur en échec).
	ops.installsOnTrigger = []string{"firefox"}
	h := &ApplicationsHandler{Ops: ops}
	items := []StateItem{
		applicationsItem("firefox", "Mozilla Firefox"),
		applicationsItem("vlc", "VLC"),
	}

	err := h.Apply(items)
	if err == nil {
		t.Fatalf("apply doit échouer (vlc non installée après le run) — jamais un faux compliant (27.4 #7)")
	}
	if got := err.Error(); !containsAll(got, "vlc") {
		t.Errorf("l'erreur doit nommer l'app manquante (vlc), obtenu : %v", err)
	}
	// L'inventaire reflète l'état réel : firefox installée, vlc non.
	inv := map[string]bool{}
	for _, r := range h.Inventory() {
		inv[r.AppId] = r.Installed
	}
	if !inv["firefox"] || inv["vlc"] {
		t.Errorf("inventaire incohérent après run partiel : firefox=%v vlc=%v", inv["firefox"], inv["vlc"])
	}
}

// --- Déclenchement impossible → error (profil non déposable / cscript absent) -

func TestApplicationsApplyErrorWhenTriggerFails(t *testing.T) {
	ops := newFakeApplicationsOps()
	ops.triggerErr = fmt.Errorf("cscript introuvable")
	h := &ApplicationsHandler{Ops: ops}
	items := []StateItem{applicationsItem("firefox", "Mozilla Firefox")}

	if err := h.Apply(items); err == nil {
		t.Fatalf("apply doit échouer si le déclenchement WPKG échoue")
	}
}

// --- wpkg.xml illisible → Test remonte une erreur (le moteur rend error) -----

func TestApplicationsTestErrorWhenWpkgXmlUnreadable(t *testing.T) {
	ops := newFakeApplicationsOps()
	ops.listErr = fmt.Errorf("wpkg.xml corrompu")
	h := &ApplicationsHandler{Ops: ops}
	items := []StateItem{applicationsItem("firefox", "Mozilla Firefox")}

	if _, err := h.Test(items); err == nil {
		t.Errorf("Test doit remonter l'erreur de lecture de wpkg.xml (le moteur rend error)")
	}
}

// --- Enveloppe invalide : payload non conforme → erreur ----------------------

func TestApplicationsInvalidPayloadIsError(t *testing.T) {
	h := &ApplicationsHandler{Ops: newFakeApplicationsOps()}

	cases := []struct {
		name    string
		payload any
	}{
		{"app_id manquant", map[string]any{"name": "X"}},
		{"app_id vide", map[string]any{"app_id": "", "name": "X"}},
		{"app_id espaces", map[string]any{"app_id": "   "}},
		{"payload non objet", "pas une map"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			items := []StateItem{{Type: "applications", Semantics: "aggregate", Hash: "h", Payload: tc.payload}}
			if _, err := h.Test(items); err == nil {
				t.Errorf("enveloppe invalide attendue (Test doit échouer)")
			}
			if err := h.Apply(items); err == nil {
				t.Errorf("enveloppe invalide attendue (Apply doit échouer)")
			}
		})
	}
}

// --- Machine d'états §5 STRICT (table-driven, via le moteur) ------------------

func TestApplicationsEngineStrictStateMachine(t *testing.T) {
	type setup struct {
		name          string
		preInstalled  []string // état initial de wpkg.xml
		deployed      []string // profil par-hôte déjà déposé (profiles.xml)
		installsOnRun []string // ce que le run WPKG installe
		wantStatus    string
		wantTriggers  int
	}

	cases := []setup{
		{
			name:          "tout installé + profil à jour → compliant (zéro déclenchement)",
			preInstalled:  []string{"firefox", "vlc"},
			deployed:      []string{"firefox", "vlc"},
			installsOnRun: nil,
			wantStatus:    "compliant",
			wantTriggers:  0,
		},
		{
			name:          "app manquante → drift (déclenche WPKG) qui converge",
			preInstalled:  []string{"firefox"},
			deployed:      []string{"firefox"},
			installsOnRun: []string{"vlc"},
			wantStatus:    "drift",
			wantTriggers:  1,
		},
		{
			name:          "déclenché mais app toujours manquante → error",
			preInstalled:  nil,
			deployed:      nil,
			installsOnRun: []string{"firefox"}, // vlc jamais installée
			wantStatus:    "error",
			wantTriggers:  1,
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			ops := newFakeApplicationsOps()
			for _, id := range tc.preInstalled {
				ops.installed[id] = true
			}
			ops.deployed = tc.deployed
			ops.installsOnTrigger = tc.installsOnRun

			eng := &Engine{Handlers: map[string]Handler{
				"applications": &ApplicationsHandler{Ops: ops},
			}}
			items := []StateItem{
				applicationsItem("firefox", "Mozilla Firefox"),
				applicationsItem("vlc", "VLC"),
			}

			report := eng.RunPass(items, AppliedState{})
			if len(report) != 1 {
				t.Fatalf("1 item de rapport attendu, obtenu %d", len(report))
			}
			if report[0].Status != tc.wantStatus {
				t.Errorf("statut : got %q want %q", report[0].Status, tc.wantStatus)
			}
			if ops.triggerCnt != tc.wantTriggers {
				t.Errorf("déclenchements : got %d want %d", ops.triggerCnt, tc.wantTriggers)
			}
			if tc.wantStatus == "error" && report[0].Detail == "" {
				t.Errorf("un item error doit porter un detail non vide (contrat §6)")
			}
			// Sur le chemin error (vlc jamais installée après le run), l'inventaire
			// doit refléter l'état réel : firefox installée (compliant), vlc non (error).
			if tc.wantStatus == "error" {
				inv := map[string]string{}
				for _, r := range report[0].Inventory {
					inv[r.AppID] = r.Status
				}
				if len(inv) == 0 {
					t.Errorf("chemin error : l'inventaire par app doit être joint au rapport (AC4)")
				}
				if s, ok := inv["vlc"]; ok && s != "error" {
					t.Errorf("inventaire error : vlc non installée → error attendu, obtenu %q", s)
				}
			}
		})
	}
}

// Bout-à-bout via le moteur : un retrait → drift → WPKG relancé → app désinstallée
// → cycle suivant compliant (idempotence). Couvre la régression 2026-06-19.
func TestApplicationsEngineRemovalTriggersUninstall(t *testing.T) {
	ops := newFakeApplicationsOps()
	ops.installed = map[string]bool{"firefox": true, "vlc": true, "adnarn": true}
	ops.deployed = []string{"firefox", "vlc", "adnarn"}
	eng := &Engine{Handlers: map[string]Handler{
		"applications": &ApplicationsHandler{Ops: ops},
	}}
	// adnarn retiré du profil : ensemble cible = firefox + vlc.
	items := []StateItem{
		applicationsItem("firefox", "Mozilla Firefox"),
		applicationsItem("vlc", "VLC"),
	}

	report := eng.RunPass(items, AppliedState{})
	if len(report) != 1 {
		t.Fatalf("1 item de rapport attendu, obtenu %d", len(report))
	}
	// Retrait = dérive corrigée par Apply (re-déclenchement WPKG).
	if report[0].Status != "drift" {
		t.Errorf("statut : got %q want drift (retrait → re-déclenchement)", report[0].Status)
	}
	if ops.triggerCnt != 1 {
		t.Errorf("WPKG doit être relancé exactement 1 fois pour désinstaller, obtenu %d", ops.triggerCnt)
	}
	// Le profil redéposé reflète l'ensemble cible (sans adnarn).
	if len(ops.lastSpecs) != 2 {
		t.Errorf("le profil redéposé doit contenir l'ensemble cible (2 apps), obtenu %d", len(ops.lastSpecs))
	}
	// `/synchronize` a désinstallé adnarn (parti du profil déposé).
	if ops.installed["adnarn"] {
		t.Errorf("adnarn aurait dû être désinstallée par /synchronize après le retrait")
	}

	// Cycle suivant : convergé → compliant, plus aucun re-déclenchement.
	report2 := eng.RunPass(items, AppliedState{})
	if report2[0].Status != "compliant" {
		t.Errorf("après convergence, statut compliant attendu, obtenu %q", report2[0].Status)
	}
	if ops.triggerCnt != 1 {
		t.Errorf("cycle convergé : aucun re-déclenchement supplémentaire (idempotence), obtenu %d au total", ops.triggerCnt)
	}
}

// Isolation : un échec applications n'impacte pas les autres types.
func TestApplicationsEngineErrorDoesNotKillOtherTypes(t *testing.T) {
	appOps := newFakeApplicationsOps()
	appOps.triggerErr = fmt.Errorf("cscript introuvable")
	regOps := newFakeRegistryOps()

	eng := &Engine{Handlers: map[string]Handler{
		"applications": &ApplicationsHandler{Ops: appOps},
		"registry":     &RegistryHandler{Ops: regOps},
	}}
	items := []StateItem{
		applicationsItem("firefox", "Mozilla Firefox"),
		dwordItem("HKCU", `Software\Test`, "X", 1),
	}

	report := eng.RunPass(items, AppliedState{})

	byType := map[string]ReportItem{}
	for _, r := range report {
		byType[r.Type] = r
	}
	if byType["applications"].Status != "error" {
		t.Errorf("applications attendu error (déclenchement KO), obtenu %q", byType["applications"].Status)
	}
	if byType["applications"].Detail == "" {
		t.Errorf("un item error doit porter un detail non vide (contrat §6)")
	}
	// registry converge malgré l'échec applications (isolation par type).
	if byType["registry"].Status != "drift" {
		t.Errorf("registry doit converger (drift) malgré l'échec applications, obtenu %q", byType["registry"].Status)
	}
	if regOps.writeCnt != 1 {
		t.Errorf("registry doit avoir écrit 1 clé malgré l'échec applications")
	}
}

// --- Inventaire par app joint au rapport via le moteur (AC4) -----------------

func TestApplicationsReportCarriesPerAppInventory(t *testing.T) {
	ops := newFakeApplicationsOps()
	ops.installed = map[string]bool{"firefox": true} // vlc manquant
	eng := &Engine{Handlers: map[string]Handler{
		"applications": &ApplicationsHandler{Ops: ops},
	}}
	// Le run installe vlc → après apply, les deux sont installées.
	ops.installsOnTrigger = []string{"vlc"}
	items := []StateItem{
		applicationsItem("firefox", "Mozilla Firefox"),
		applicationsItem("vlc", "VLC"),
	}

	report := eng.RunPass(items, AppliedState{})
	if len(report) != 1 {
		t.Fatalf("1 item de rapport attendu, obtenu %d", len(report))
	}
	// Le verdict du TYPE reste un seul statut (drift : vlc manquait → apply).
	if report[0].Status != "drift" {
		t.Errorf("statut du type : got %q want drift", report[0].Status)
	}
	// L'inventaire PAR APP est joint (AC4) — donnée additive, jamais un verdict.
	inv := map[string]string{}
	for _, r := range report[0].Inventory {
		inv[r.AppID] = r.Status
	}
	if len(inv) != 2 {
		t.Fatalf("inventaire : 2 apps attendues, obtenu %d (%v)", len(inv), report[0].Inventory)
	}
	if inv["firefox"] != "compliant" || inv["vlc"] != "compliant" {
		t.Errorf("inventaire après run : firefox=%q vlc=%q (attendu compliant/compliant)", inv["firefox"], inv["vlc"])
	}
}

// L'inventaire reflète une app non installée comme `error` (siège non occupé).
func TestApplicationsReportInventoryMarksMissingAsError(t *testing.T) {
	ops := newFakeApplicationsOps()
	ops.installed = map[string]bool{"firefox": true} // vlc manquant, jamais installé
	eng := &Engine{Handlers: map[string]Handler{
		"applications": &ApplicationsHandler{Ops: ops},
	}}
	items := []StateItem{
		applicationsItem("firefox", "Mozilla Firefox"),
		applicationsItem("vlc", "VLC"),
	}

	report := eng.RunPass(items, AppliedState{})
	inv := map[string]string{}
	for _, r := range report[0].Inventory {
		inv[r.AppID] = r.Status
	}
	if inv["firefox"] != "compliant" {
		t.Errorf("firefox installé → inventaire compliant, obtenu %q", inv["firefox"])
	}
	if inv["vlc"] != "error" {
		t.Errorf("vlc non installé → inventaire error (siège non occupé), obtenu %q", inv["vlc"])
	}
}

// Sérialisation du rapport : le champ `inventory` apparaît bien sur l'item
// applications (et est omis quand vide — omitempty).
func TestApplicationsReportInventorySerialization(t *testing.T) {
	items := []ReportItem{
		{
			Type:   "applications",
			Status: "drift",
			Hash:   strings.Repeat("a", 64),
			Inventory: []ReportInventoryItem{
				{AppID: "firefox", Status: "compliant"},
				{AppID: "vlc", Status: "error", Detail: "installeur en échec"},
			},
		},
		{Type: "wallpaper", Status: "compliant", Hash: strings.Repeat("b", 64)},
	}
	raw, err := BuildReport("PC", "u", items, time.Now())
	if err != nil {
		t.Fatalf("BuildReport : %v", err)
	}
	s := string(raw)
	if !strings.Contains(s, `"inventory"`) {
		t.Errorf("le champ inventory doit apparaître pour applications : %s", s)
	}
	if !strings.Contains(s, `"app_id":"firefox"`) || !strings.Contains(s, `"app_id":"vlc"`) {
		t.Errorf("les app_id de l'inventaire doivent apparaître : %s", s)
	}
	// wallpaper n'a pas d'inventaire → champ omis (omitempty).
	if strings.Count(s, `"inventory"`) != 1 {
		t.Errorf("inventory ne doit apparaître QUE sur applications (omitempty) : %s", s)
	}
}

// --- NFC : un package-id en NFD ne produit pas un faux « non installé » -------

func TestApplicationsNFCNormalizationAvoidsFalseDrift(t *testing.T) {
	ops := newFakeApplicationsOps()
	// Formes construites explicitement par points de code (jamais dépendantes de
	// l'encodage du fichier source) : NFD = "libr" + e + U+0301 (accent
	// combinant) + "office" ; NFC = "libr" + U+00E9 (é précomposé) + "office".
	nfd := "libr" + string(rune(0x65)) + string(rune(0x0301)) + "office"
	nfc := "libr" + string(rune(0x00E9)) + "office"
	if nfd == nfc {
		t.Fatalf("setup invalide : les formes NFD et NFC doivent différer en octets")
	}
	ops.installed = map[string]bool{nfd: true}
	// Profil déposé en NFD : la comparaison desired(NFC) ↔ déposé doit aussi
	// normaliser (sinon faux « périmètre changé »).
	ops.deployed = []string{nfd}
	h := &ApplicationsHandler{Ops: ops}
	items := []StateItem{applicationsItem(nfc, "LibreOffice")}

	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test : %v", err)
	}
	if !ok {
		t.Errorf("NFC : un package-id NFD installé doit matcher la cible NFC (pas de faux non-installé)")
	}
}

// containsAll : helper (toutes les sous-chaînes présentes).
func containsAll(s string, subs ...string) bool {
	for _, sub := range subs {
		found := false
		for i := 0; i+len(sub) <= len(s); i++ {
			if s[i:i+len(sub)] == sub {
				found = true

				break
			}
		}
		if !found {
			return false
		}
	}

	return true
}
