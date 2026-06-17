package shared

import (
	"bytes"
	"encoding/json"
	"fmt"
	"strings"
	"testing"
)

// fakeAppConfigOps : AppConfigOps en mémoire (testable hôte). Modélise les
// `policies.json` réels par app_kind (présence + marqueur de gestion + contenu
// canonique), avec des erreurs d'accès injectables et des compteurs.
type fakeAppConfigOps struct {
	// files : app_kind → fichier réel présent.
	files map[string]*fakeAppConfigFile
	// pathErr : app_kind → erreur de résolution de chemin (app non gérée).
	pathErr map[string]error
	// writeErr : app_kind → erreur d'écriture (chemin verrouillé).
	writeErr map[string]error
	// inspectErr : app_kind → erreur d'inspection (fichier illisible).
	inspectErr map[string]error

	writeCnt  int
	removeCnt int
}

type fakeAppConfigFile struct {
	managed   bool   // posé par l'agent (marqueur) ?
	canonical string // contenu canonique (sans marqueur)
}

func newFakeAppConfigOps() *fakeAppConfigOps {
	return &fakeAppConfigOps{
		files:      map[string]*fakeAppConfigFile{},
		pathErr:    map[string]error{},
		writeErr:   map[string]error{},
		inspectErr: map[string]error{},
	}
}

// kindFromPath : le fake encode app_kind dans le chemin (`path://<kind>`).
func (o *fakeAppConfigOps) PolicyPath(appKind string) (string, error) {
	if err := o.pathErr[appKind]; err != nil {
		return "", err
	}

	return "path://" + appKind, nil
}

func pathKind(path string) string {
	return strings.TrimPrefix(path, "path://")
}

func (o *fakeAppConfigOps) Inspect(path string) (bool, bool, error) {
	kind := pathKind(path)
	if err := o.inspectErr[kind]; err != nil {
		return true, false, err
	}
	f, ok := o.files[kind]
	if !ok {
		return false, false, nil
	}

	return true, f.managed, nil
}

func (o *fakeAppConfigOps) Matches(path string, spec AppConfigSpec) (bool, error) {
	kind := pathKind(path)
	if err := o.inspectErr[kind]; err != nil {
		return false, err
	}
	f, ok := o.files[kind]
	if !ok {
		return false, nil
	}

	return f.canonical == string(spec.Canonical), nil
}

func (o *fakeAppConfigOps) Write(path string, spec AppConfigSpec) error {
	kind := pathKind(path)
	if err := o.writeErr[kind]; err != nil {
		return err
	}
	o.writeCnt++
	o.files[kind] = &fakeAppConfigFile{managed: true, canonical: string(spec.Canonical)}

	return nil
}

func (o *fakeAppConfigOps) Remove(path string) error {
	kind := pathKind(path)
	o.removeCnt++
	delete(o.files, kind)

	return nil
}

// appConfigItem construit un StateItem `app_config` (aggregate) pour une app.
func appConfigItem(appKind string, policies map[string]any) StateItem {
	return StateItem{
		Type:      "app_config",
		Semantics: "aggregate",
		Hash:      appKind + "-h",
		Payload: map[string]any{
			"app_kind": appKind,
			"policies": policies,
		},
	}
}

// canonicalOf : forme canonique attendue d'un jeu de policies (iso le handler).
func canonicalOf(t *testing.T, policies map[string]any) string {
	t.Helper()
	c, err := canonicalPolicies(policies)
	if err != nil {
		t.Fatalf("canonicalPolicies : %v", err)
	}

	return string(c)
}

// --- Parité de canonicalisation (review #3) : cible vs fichier relu -----------
//
// Le payload réseau est décodé `UseNumber` → les nombres sont des `json.Number`,
// PAS des float64. La forme CIBLE (spec.Canonical, calculée serveur-side par
// canonicalPolicies) et la forme RELUE du `policies.json` écrit (côté windows :
// décode UseNumber → retire le marqueur → CanonicalJSON) DOIVENT être identiques
// à l'octet près, sinon Matches() renvoie toujours false → réécriture en boucle
// à chaque logon (perte d'idempotence). Ce test reproduit le round-trip windows
// avec les MÊMES primitives `shared` (json.Number partout) et prouve la parité.

// decodeUseNumber : décode un objet JSON en json.Number (iso ParseState /
// l'impl windows decodeJSONObject). Helper de test pour simuler la relecture du
// fichier écrit.
func decodeUseNumber(t *testing.T, raw []byte) map[string]any {
	t.Helper()
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()
	var doc map[string]any
	if err := dec.Decode(&doc); err != nil {
		t.Fatalf("décodage UseNumber : %v", err)
	}

	return doc
}

func TestAppConfigCanonicalParityOnJSONNumber(t *testing.T) {
	// Policies CIBLE telles que décodées du réseau (UseNumber → json.Number) :
	// entiers, négatifs, grands nombres, et des nombres imbriqués dans une liste.
	netPayload := []byte(`{
	  "policies": {
	    "Homepage": {"URL": "https://etab.local/", "StartPage": "homepage"},
	    "OfferToSaveLogins": false,
	    "Cache": {"Size": 1024, "TTL": -1, "Big": 9007199254740993},
	    "BlockedPorts": [22, 25, 110],
	    "DNSOverHTTPS": {"Enabled": true, "ProviderURL": "https://dns/"}
	  }
	}`)
	policies := decodeUseNumber(t, netPayload)

	// Forme CIBLE (serveur-side) : ce que l'agent veut écrire.
	target, err := canonicalPolicies(policies)
	if err != nil {
		t.Fatalf("canonicalPolicies (cible) : %v", err)
	}

	// Simule l'écriture côté windows : doc = policies + marqueur, canonicalisé.
	doc := map[string]any{}
	for k, v := range policies {
		doc[k] = v
	}
	doc[AppConfigManagedMarker] = true
	written, err := CanonicalJSON(doc)
	if err != nil {
		t.Fatalf("CanonicalJSON (écriture) : %v", err)
	}

	// Simule la RELECTURE côté windows (canonicalWithoutMarker) : décode le
	// fichier écrit en UseNumber, retire le marqueur, re-canonicalise.
	reread := decodeUseNumber(t, written)
	delete(reread, AppConfigManagedMarker)
	actual, err := CanonicalJSON(reread)
	if err != nil {
		t.Fatalf("CanonicalJSON (relu) : %v", err)
	}

	if string(actual) != string(target) {
		t.Fatalf("PARITÉ ROMPUE (review #3) : la forme relue diverge de la cible\n  cible :\n%s\n  relu  :\n%s", target, actual)
	}

	// Et le contenu doit avoir traversé le round-trip sans dénaturer les nombres
	// (pas de notation scientifique ni de float — json.Number préservé).
	if strings.Contains(string(actual), "e+") || strings.Contains(string(actual), "E+") {
		t.Errorf("notation scientifique détectée — json.Number non préservé : %s", actual)
	}
	if !strings.Contains(string(actual), "9007199254740993") {
		t.Errorf("grand entier dénaturé (perte de précision float64) : %s", actual)
	}
}

// --- Set cible + idempotence -------------------------------------------------

func TestAppConfigApplyWritesTargetThenIdempotent(t *testing.T) {
	ops := newFakeAppConfigOps()
	h := &AppConfigHandler{Ops: ops}
	items := []StateItem{
		appConfigItem("firefox", map[string]any{"policies": map[string]any{"Homepage": map[string]any{"URL": "https://etab.local/"}}}),
		appConfigItem("thunderbird", map[string]any{"policies": map[string]any{"DisableTelemetry": true}}),
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply 1 : %v", err)
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
		t.Fatalf("apply 2 : %v", err)
	}
	if ops.writeCnt != before {
		t.Fatalf("apply idempotent attendu : %d écriture(s) supplémentaire(s)", ops.writeCnt-before)
	}
}

// --- Mécanisme : un policies.json par app au chemin natif ---------------------

func TestAppConfigWritesPoliciesJsonPerApp(t *testing.T) {
	ops := newFakeAppConfigOps()
	h := &AppConfigHandler{Ops: ops}
	ffPolicies := map[string]any{"policies": map[string]any{"Homepage": map[string]any{"URL": "https://x/"}}}
	items := []StateItem{appConfigItem("firefox", ffPolicies)}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply : %v", err)
	}
	f, ok := ops.files["firefox"]
	if !ok {
		t.Fatalf("policies.json firefox attendu au chemin natif")
	}
	if !f.managed {
		t.Errorf("le policies.json posé doit porter le marqueur de gestion")
	}
	if f.canonical != canonicalOf(t, ffPolicies) {
		t.Errorf("contenu policies.json firefox != cible résolue serveur")
	}
	// Thunderbird n'a pas d'item → aucun fichier posé (type/clé absente §8).
	if _, ok := ops.files["thunderbird"]; ok {
		t.Errorf("thunderbird sans item ne doit PAS recevoir de policies.json")
	}
}

// --- Drift STRICT (contenu réel != cible) → réapplication --------------------

func TestAppConfigDriftIsRewritten(t *testing.T) {
	ops := newFakeAppConfigOps()
	h := &AppConfigHandler{Ops: ops}
	target := map[string]any{"policies": map[string]any{"Homepage": map[string]any{"URL": "https://cible/"}}}
	items := []StateItem{appConfigItem("firefox", target)}

	// Fichier GÉRÉ mais dérivé (modifié à la main).
	ops.files["firefox"] = &fakeAppConfigFile{managed: true, canonical: `{"policies":"DERIVE"}`}

	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test : %v", err)
	}
	if ok {
		t.Fatalf("dérive attendue (non conforme) : un policies.json géré divergent")
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply : %v", err)
	}
	if ops.files["firefox"].canonical != canonicalOf(t, target) {
		t.Errorf("drift STRICT : le policies.json doit être réimposé à la cible")
	}
}

// --- Level-triggered : app sortie des règles → policies.json géré retiré ------

func TestAppConfigLevelTriggeredRemovesOrphan(t *testing.T) {
	ops := newFakeAppConfigOps()
	h := &AppConfigHandler{Ops: ops}

	// État initial : firefox ET thunderbird gérés.
	ops.files["firefox"] = &fakeAppConfigFile{managed: true, canonical: canonicalOf(t, map[string]any{"a": 1})}
	ops.files["thunderbird"] = &fakeAppConfigFile{managed: true, canonical: canonicalOf(t, map[string]any{"b": 2})}

	// Nouvelles règles : SEUL firefox subsiste (thunderbird désassigné).
	items := []StateItem{appConfigItem("firefox", map[string]any{"a": 1})}

	// Test : thunderbird géré orphelin → non conforme.
	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("test : %v", err)
	}
	if ok {
		t.Fatalf("orphelin attendu (non conforme) : thunderbird géré hors règles")
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply : %v", err)
	}
	if _, ok := ops.files["thunderbird"]; ok {
		t.Errorf("level-triggered : le policies.json thunderbird géré doit être RETIRÉ")
	}
	if _, ok := ops.files["firefox"]; !ok {
		t.Errorf("firefox toujours dans les règles doit subsister")
	}
	if ops.removeCnt != 1 {
		t.Errorf("exactement 1 retrait attendu, obtenu %d", ops.removeCnt)
	}
}

// --- Marqueur de périmètre : policies.json HORS SambaEdu jamais touché --------
//
// Review #7 (décision Henri 2026-06-17) : un fichier étranger sur une app CIBLE
// → JAMAIS écrasé/supprimé (non-ingérence préservée) MAIS rapporté `error` de
// conflit (la policy agent n'est pas active). Avant : `compliant` trompeur.
func TestAppConfigForeignFileOnTargetIsErrorNeverTouched(t *testing.T) {
	ops := newFakeAppConfigOps()
	h := &AppConfigHandler{Ops: ops}

	// Un policies.json posé par un AUTRE outil/admin (non géré) au chemin natif.
	ops.files["firefox"] = &fakeAppConfigFile{managed: false, canonical: `{"policies":"ADMIN"}`}
	target := map[string]any{"policies": map[string]any{"Homepage": map[string]any{"URL": "https://cible/"}}}
	items := []StateItem{appConfigItem("firefox", target)}

	// Test : fichier étranger sur app cible → conflit signalé en ERREUR (review #7).
	ok, err := h.Test(items)
	if ok {
		t.Fatalf("un fichier étranger sur une app cible NE doit PAS être conforme")
	}
	if err == nil {
		t.Fatalf("un conflit hors-périmètre doit remonter une erreur (review #7)")
	}
	if !strings.Contains(err.Error(), "hors-périmètre") || !strings.Contains(err.Error(), "firefox") {
		t.Errorf("l'erreur de conflit doit être explicite (hors-périmètre + app), obtenu : %v", err)
	}

	// Apply : remonte aussi l'erreur de conflit, mais JAMAIS d'écrasement.
	err = h.Apply(items)
	if err == nil {
		t.Fatalf("apply doit remonter l'erreur de conflit hors-périmètre (review #7)")
	}
	if !strings.Contains(err.Error(), "hors-périmètre") {
		t.Errorf("l'erreur apply doit signaler le conflit hors-périmètre, obtenu : %v", err)
	}
	// JAMAIS écrasé, JAMAIS supprimé (non-ingérence préservée).
	if ops.writeCnt != 0 {
		t.Errorf("aucune écriture attendue sur un fichier hors périmètre, obtenu %d", ops.writeCnt)
	}
	if ops.removeCnt != 0 {
		t.Errorf("aucun retrait attendu sur un fichier hors périmètre, obtenu %d", ops.removeCnt)
	}
	if ops.files["firefox"].canonical != `{"policies":"ADMIN"}` {
		t.Errorf("le contenu du fichier hors périmètre doit rester INTACT")
	}
}

// Isolation (review #7) : un conflit hors-périmètre sur firefox surface error
// mais n'empêche pas thunderbird (app saine) de converger (effort maximal).
func TestAppConfigForeignFileIsolatesOtherApps(t *testing.T) {
	ops := newFakeAppConfigOps()
	h := &AppConfigHandler{Ops: ops}

	ops.files["firefox"] = &fakeAppConfigFile{managed: false, canonical: `{"policies":"ADMIN"}`}
	items := []StateItem{
		appConfigItem("firefox", map[string]any{"a": 1}),
		appConfigItem("thunderbird", map[string]any{"b": 2}),
	}

	err := h.Apply(items)
	if err == nil {
		t.Fatalf("erreur de conflit attendue pour firefox (fichier étranger)")
	}
	// firefox étranger : JAMAIS écrasé.
	if ops.files["firefox"].canonical != `{"policies":"ADMIN"}` {
		t.Errorf("le fichier étranger firefox doit rester intact")
	}
	// thunderbird (app saine) a quand même convergé.
	if _, ok := ops.files["thunderbird"]; !ok {
		t.Errorf("thunderbird doit converger malgré le conflit firefox (isolation)")
	}
}

// Level-triggered sur orphelin HORS périmètre : jamais supprimé non plus.
func TestAppConfigOrphanOutOfScopeNotRemoved(t *testing.T) {
	ops := newFakeAppConfigOps()
	h := &AppConfigHandler{Ops: ops}

	// thunderbird : fichier NON géré, et hors règles (aucun item).
	ops.files["thunderbird"] = &fakeAppConfigFile{managed: false, canonical: `{"policies":"USER"}`}
	items := []StateItem{appConfigItem("firefox", map[string]any{"a": 1})}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply : %v", err)
	}
	if _, ok := ops.files["thunderbird"]; !ok {
		t.Errorf("un policies.json orphelin HORS périmètre ne doit JAMAIS être supprimé")
	}
	if ops.removeCnt != 0 {
		t.Errorf("aucun retrait attendu, obtenu %d", ops.removeCnt)
	}
}

// --- App butée / app_kind inconnu : erreur isolée (les autres convergent) -----

func TestAppConfigUnknownAppKindIsErrorIsolated(t *testing.T) {
	ops := newFakeAppConfigOps()
	h := &AppConfigHandler{Ops: ops}
	// app_kind "chrome" non géré (pas de mécanisme policies.json — recadrage).
	ops.pathErr["chrome"] = fmt.Errorf("app_config : app_kind non gérée \"chrome\"")
	items := []StateItem{
		appConfigItem("firefox", map[string]any{"a": 1}),
		appConfigItem("chrome", map[string]any{"b": 2}),
	}

	// Apply : firefox converge, chrome remonte une erreur (effort maximal).
	err := h.Apply(items)
	if err == nil {
		t.Fatalf("erreur attendue pour app_kind non géré")
	}
	if _, ok := ops.files["firefox"]; !ok {
		t.Errorf("firefox (app saine) doit converger MALGRÉ l'échec de chrome (isolation)")
	}
}

// --- Item error isolé : chemin verrouillé d'une app n'empêche pas l'autre -----

func TestAppConfigLockedPathIsErrorIsolated(t *testing.T) {
	ops := newFakeAppConfigOps()
	h := &AppConfigHandler{Ops: ops}
	ops.writeErr["thunderbird"] = fmt.Errorf("chemin verrouillé (ACCESS_DENIED)")
	items := []StateItem{
		appConfigItem("firefox", map[string]any{"a": 1}),
		appConfigItem("thunderbird", map[string]any{"b": 2}),
	}

	err := h.Apply(items)
	if err == nil {
		t.Fatalf("erreur attendue pour le chemin verrouillé thunderbird")
	}
	if !strings.Contains(err.Error(), "thunderbird") {
		t.Errorf("l'erreur doit cibler thunderbird, obtenu : %v", err)
	}
	// firefox a quand même convergé (effort maximal, isolation inter-items).
	if _, ok := ops.files["firefox"]; !ok {
		t.Errorf("firefox doit converger malgré l'échec d'écriture de thunderbird")
	}
}

// Inspect en échec (fichier illisible) → erreur remontée (le moteur rend error).
func TestAppConfigUnreadableFileIsError(t *testing.T) {
	ops := newFakeAppConfigOps()
	h := &AppConfigHandler{Ops: ops}
	ops.inspectErr["firefox"] = fmt.Errorf("policies.json corrompu")
	items := []StateItem{appConfigItem("firefox", map[string]any{"a": 1})}

	if _, err := h.Test(items); err == nil {
		t.Errorf("Test doit remonter l'erreur d'inspection (le moteur rend error)")
	}
}

// --- Enveloppe invalide : payload non conforme → erreur (moteur rend error) ---

func TestAppConfigInvalidPayloadIsError(t *testing.T) {
	ops := newFakeAppConfigOps()
	h := &AppConfigHandler{Ops: ops}

	cases := []struct {
		name    string
		payload any
	}{
		{"app_kind manquant", map[string]any{"policies": map[string]any{}}},
		{"app_kind vide", map[string]any{"app_kind": "", "policies": map[string]any{}}},
		{"policies non objet", map[string]any{"app_kind": "firefox", "policies": "oops"}},
		{"payload non objet", "pas une map"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			items := []StateItem{{Type: "app_config", Semantics: "aggregate", Hash: "h", Payload: tc.payload}}
			if _, err := h.Test(items); err == nil {
				t.Errorf("enveloppe invalide attendue (Test doit échouer)")
			}
			if err := h.Apply(items); err == nil {
				t.Errorf("enveloppe invalide attendue (Apply doit échouer)")
			}
		})
	}
}

// policies absent (clé non présente) = objet vide accepté (socle template/auto).
func TestAppConfigMissingPoliciesKeyAccepted(t *testing.T) {
	ops := newFakeAppConfigOps()
	h := &AppConfigHandler{Ops: ops}
	items := []StateItem{{
		Type:      "app_config",
		Semantics: "aggregate",
		Hash:      "h",
		Payload:   map[string]any{"app_kind": "firefox"}, // pas de clé policies
	}}

	if err := h.Apply(items); err != nil {
		t.Fatalf("policies absent doit être accepté (objet vide) : %v", err)
	}
	if _, ok := ops.files["firefox"]; !ok {
		t.Errorf("un policies.json (vide) doit être posé")
	}
}

// --- Machine d'états §5 STRICT (table-driven, via le moteur) ------------------

func TestAppConfigEngineStrictStateMachine(t *testing.T) {
	type setup struct {
		name        string
		preexisting *fakeAppConfigFile // état initial du fichier firefox (nil = absent)
		wantStatus  string
		wantWrites  int
	}
	target := map[string]any{"policies": map[string]any{"Homepage": map[string]any{"URL": "https://cible/"}}}
	targetCanonical := canonicalOf(t, target)

	cases := []setup{
		{
			name:        "absent → drift (apply) + persist",
			preexisting: nil,
			wantStatus:  "drift",
			wantWrites:  1,
		},
		{
			name:        "conforme → compliant (zéro écriture)",
			preexisting: &fakeAppConfigFile{managed: true, canonical: targetCanonical},
			wantStatus:  "compliant",
			wantWrites:  0,
		},
		{
			name:        "géré divergent → drift (réapplication) STRICT",
			preexisting: &fakeAppConfigFile{managed: true, canonical: `{"x":1}`},
			wantStatus:  "drift",
			wantWrites:  1,
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			ops := newFakeAppConfigOps()
			if tc.preexisting != nil {
				ops.files["firefox"] = tc.preexisting
			}
			eng := &Engine{Handlers: map[string]Handler{
				"app_config": &AppConfigHandler{Ops: ops},
			}}
			items := []StateItem{appConfigItem("firefox", target)}
			applied := AppliedState{}

			report := eng.RunPass(items, applied)
			if len(report) != 1 {
				t.Fatalf("1 item de rapport attendu, obtenu %d", len(report))
			}
			if report[0].Status != tc.wantStatus {
				t.Errorf("statut : got %q want %q", report[0].Status, tc.wantStatus)
			}
			if ops.writeCnt != tc.wantWrites {
				t.Errorf("écritures : got %d want %d", ops.writeCnt, tc.wantWrites)
			}
			// Persistance du dernier-appliqué (compliant ou drift réussi).
			if _, ok := applied["app_config"]; !ok {
				t.Errorf("le dernier-appliqué doit être persisté (compliant ou apply réussi)")
			}
		})
	}
}

// Le moteur isole le type app_config : un échec n'impacte pas les autres types.
func TestAppConfigEngineErrorDoesNotKillOtherTypes(t *testing.T) {
	ops := newFakeAppConfigOps()
	ops.writeErr["firefox"] = fmt.Errorf("verrouillé")
	regOps := newFakeRegistryOps()

	eng := &Engine{Handlers: map[string]Handler{
		"app_config": &AppConfigHandler{Ops: ops},
		"registry":   &RegistryHandler{Ops: regOps},
	}}
	items := []StateItem{
		appConfigItem("firefox", map[string]any{"a": 1}),
		dwordItem("HKCU", `Software\Test`, "X", 1),
	}

	report := eng.RunPass(items, AppliedState{})

	byType := map[string]ReportItem{}
	for _, r := range report {
		byType[r.Type] = r
	}
	if byType["app_config"].Status != "error" {
		t.Errorf("app_config attendu error (chemin verrouillé), obtenu %q", byType["app_config"].Status)
	}
	if byType["app_config"].Detail == "" {
		t.Errorf("un item error doit porter un detail non vide (contrat §6)")
	}
	// registry converge malgré l'échec app_config (isolation par type).
	if byType["registry"].Status != "drift" {
		t.Errorf("registry doit converger (drift) malgré l'échec app_config, obtenu %q", byType["registry"].Status)
	}
	if regOps.writeCnt != 1 {
		t.Errorf("registry doit avoir écrit 1 clé malgré l'échec app_config")
	}
}
