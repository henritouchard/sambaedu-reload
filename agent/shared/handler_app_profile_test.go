package shared

import (
	"errors"
	"strings"
	"testing"
)

// fakeAppProfileOps : impl mémoire de AppProfileOps (testable hôte). Les fichiers
// et l'état du lien sont des maps ; `serverDown` simule un home injoignable.
//
// SPLIT 36.5 : le compagnon NE POSE PLUS le lien (c'est le service SYSTEM au
// logon). Le fake ne fournit donc PLUS de CreateLink ; `linkTarget`/`linkReal`
// simulent le lien tel que SYSTEM l'a (ou non) déjà posé, que le compagnon se
// contente de CONSTATER (LinkState) pour décider s'il écrit la paire d'ini.
//
// ⚠️ LIMITE VOLONTAIRE (Errno réseau) : `serverDown` renvoie une erreur GÉNÉRIQUE
// (non-nil). Il ne reproduit PAS le mapping trompeur du VRAI Windows, où
// ERROR_BAD_NETPATH(53) est traduit en os.ErrNotExist par Go — c.-à-d. une FAUSSE
// absence, pas une erreur. La détection explicite de ces Errno réseau vit dans
// agent/windows/handler_app_profile_windows.go (`isNetworkErrno`, appelée AVANT
// le test ErrNotExist) et n'est validable que sous GOOS=windows (go vet/build) —
// ce fake ne peut donc pas la couvrir (d'où ce garde côté windows, pas ici).
type fakeAppProfileOps struct {
	files      map[string]string // chemin → contenu
	dirs       map[string]bool   // dossiers créés
	linkTarget string            // cible du lien (vide = pas de lien) — posé par SYSTEM
	linkReal   bool              // le chemin du lien est un dossier RÉEL (non-lien)
	serverDown bool              // EnsureDir/ReadFile du serveur échouent

	writes   int // nb d'écritures (idempotence)
	mkdirOps int // nb de EnsureDir
}

func newFakeAppProfileOps() *fakeAppProfileOps {
	return &fakeAppProfileOps{files: map[string]string{}, dirs: map[string]bool{}}
}

// resolvedServer : la cible que ResolveServer produit pour firefoxItem() — c'est
// ce que SYSTEM aura posé comme cible de lien (le compagnon la constate).
const fakeResolvedServer = `\\SRV\users\alice\.mozilla\firefox\managed.default`

func (f *fakeAppProfileOps) ResolveServer(server string) (string, error) {
	s := strings.ReplaceAll(server, "<se4fs>", "SRV")
	s = strings.ReplaceAll(s, "<user>", "alice")

	return s, nil
}

func (f *fakeAppProfileOps) ResolveLink(link string) (string, error) {
	return `C:\Users\alice\` + link, nil
}

func (f *fakeAppProfileOps) ResolveLocalCache(cacheLocal string) (string, error) {
	return `C:\Users\alice\AppData\Local\` + cacheLocal, nil
}

func (f *fakeAppProfileOps) isServer(path string) bool {
	return strings.HasPrefix(path, `\\SRV\`)
}

func (f *fakeAppProfileOps) EnsureDir(path string) error {
	if f.serverDown && f.isServer(path) {
		return errors.New("réseau injoignable")
	}
	f.mkdirOps++
	f.dirs[path] = true

	return nil
}

func (f *fakeAppProfileOps) LinkState(link string) (string, bool, bool, error) {
	if f.linkReal {
		return "", true, false, nil
	}
	if f.linkTarget == "" {
		return "", false, false, nil
	}

	return f.linkTarget, true, true, nil
}

func (f *fakeAppProfileOps) ReadFile(path string) (string, bool, error) {
	if f.serverDown && f.isServer(path) {
		return "", false, errors.New("réseau injoignable")
	}
	content, ok := f.files[path]

	return content, ok, nil
}

func (f *fakeAppProfileOps) WriteFile(path, content string) error {
	if f.serverDown && f.isServer(path) {
		return errors.New("réseau injoignable")
	}
	f.writes++
	f.files[path] = content

	return nil
}

// firefoxItem : un item app_profile firefox (payload iso golden/seed).
func firefoxItem() StateItem {
	return StateItem{
		Type:      "app_profile",
		Semantics: "aggregate",
		Payload: map[string]any{
			"app":          "firefox",
			"link":         `AppData\Roaming\Mozilla\Firefox\managed.default`,
			"server":       `\\<se4fs>\users\<user>\.mozilla\firefox\managed.default`,
			"profile_name": "managed.default",
			"install_hash": "308046B0AF4A39CB",
			"cache_local":  "cacheFirefox",
		},
	}
}

const fakeIniDir = `C:\Users\alice\AppData\Roaming\Mozilla\Firefox`

// converge : Apply puis Test == compliant (helper d'assertion). Suppose le lien
// DÉJÀ posé par SYSTEM (ops.linkTarget = cible résolue).
func converge(t *testing.T, h *AppProfileHandler, items []StateItem) {
	t.Helper()
	if err := h.Apply(items); err != nil {
		t.Fatalf("Apply : %v", err)
	}
	ok, err := h.Test(items)
	if err != nil {
		t.Fatalf("Test post-Apply : %v", err)
	}
	if !ok {
		t.Fatalf("Test post-Apply : non conforme")
	}
}

func TestAppProfileTestOnFreshProfileIsNonCompliantWithoutWriting(t *testing.T) {
	ops := newFakeAppProfileOps()
	h := &AppProfileHandler{Ops: ops}

	ok, err := h.Test([]StateItem{firefoxItem()})
	if err != nil {
		t.Fatalf("Test : %v", err)
	}
	if ok {
		t.Fatalf("profil neuf : non conforme attendu")
	}
	if ops.writes != 0 {
		t.Errorf("Test ne doit RIEN écrire : writes=%d", ops.writes)
	}
}

// Lien DÉJÀ posé par SYSTEM ⇒ le compagnon converge complètement (marqueur,
// user.js, paire d'ini) et devient idempotent.
func TestAppProfileApplyThenTestIsIdempotentWhenLinkPresent(t *testing.T) {
	ops := newFakeAppProfileOps()
	ops.linkTarget = fakeResolvedServer // SYSTEM a posé le lien.
	h := &AppProfileHandler{Ops: ops}

	converge(t, h, []StateItem{firefoxItem()})

	writesAfterFirst := ops.writes

	// 2ᵉ Apply : NO-OP (aucune écriture).
	if err := h.Apply([]StateItem{firefoxItem()}); err != nil {
		t.Fatalf("2e Apply : %v", err)
	}
	if ops.writes != writesAfterFirst {
		t.Errorf("2e Apply non idempotent : writes %d → %d", writesAfterFirst, ops.writes)
	}
}

// Lien ABSENT (SYSTEM ne l'a pas encore posé) : le compagnon écrit le côté HOME
// (marqueur + user.js) mais PAS la paire d'ini (gate anti-C1) et laisse un detail
// « en attente ».
func TestAppProfileApplyWritesServerSideButWaitsForLink(t *testing.T) {
	ops := newFakeAppProfileOps()
	h := &AppProfileHandler{Ops: ops}

	if err := h.Apply([]StateItem{firefoxItem()}); err != nil {
		t.Fatalf("Apply : %v", err)
	}

	// Marqueur + user.js côté home écrits.
	serverDir := fakeResolvedServer
	if ops.files[serverDir+`\.se-profile-version`] != AppProfileVersion+"\n" {
		t.Errorf("marqueur de version incorrect : %q", ops.files[serverDir+`\.se-profile-version`])
	}
	if _, ok := ops.files[serverDir+`\user.js`]; !ok {
		t.Errorf("user.js (épinglage cache AC5) absent")
	}
	// Paire d'ini NON écrite (lien absent — gate anti-C1).
	if _, ok := ops.files[fakeIniDir+`\profiles.ini`]; ok {
		t.Errorf("profiles.ini ne doit PAS être écrit tant que le lien n'est pas posé (anti-C1)")
	}
	if _, ok := ops.files[fakeIniDir+`\installs.ini`]; ok {
		t.Errorf("installs.ini ne doit PAS être écrit tant que le lien n'est pas posé (anti-C1)")
	}
	// Detail « en attente de SYSTEM ».
	detail := h.ReportDetail()
	if !strings.Contains(detail, "en attente") || !strings.Contains(detail, "SYSTEM") {
		t.Errorf("detail « lien en attente de SYSTEM » attendu, got %q", detail)
	}
}

// Lien PRÉSENT + correct : la paire d'ini est écrite (côté local, parent du lien).
func TestAppProfileWritesIniOnceLinkPresent(t *testing.T) {
	ops := newFakeAppProfileOps()
	ops.linkTarget = fakeResolvedServer
	h := &AppProfileHandler{Ops: ops}

	if err := h.Apply([]StateItem{firefoxItem()}); err != nil {
		t.Fatalf("Apply : %v", err)
	}
	if _, ok := ops.files[fakeIniDir+`\profiles.ini`]; !ok {
		t.Errorf("profiles.ini absent alors que le lien est présent")
	}
	if _, ok := ops.files[fakeIniDir+`\installs.ini`]; !ok {
		t.Errorf("installs.ini absent alors que le lien est présent")
	}
	if !strings.Contains(ops.files[fakeResolvedServer+`\user.js`], "browser.cache.disk.parent_directory") {
		t.Errorf("user.js n'épingle pas le cache : %q", ops.files[fakeResolvedServer+`\user.js`])
	}
	// Convergence complète ⇒ aucun detail.
	if d := h.ReportDetail(); d != "" {
		t.Errorf("lien présent : detail attendu vide, got %q", d)
	}
}

// Lien DIVERGENT (SYSTEM pas encore repassé) : le compagnon ne répare pas (ce
// n'est plus son rôle) et n'écrit PAS les ini — il attend.
func TestAppProfileDivergentLinkWaitsNoIni(t *testing.T) {
	ops := newFakeAppProfileOps()
	ops.linkTarget = `\\SRV\users\alice\autre\endroit` // lien pointant AILLEURS.
	h := &AppProfileHandler{Ops: ops}

	if err := h.Apply([]StateItem{firefoxItem()}); err != nil {
		t.Fatalf("Apply : %v", err)
	}
	if _, ok := ops.files[fakeIniDir+`\profiles.ini`]; ok {
		t.Errorf("lien divergent : profiles.ini ne doit PAS être écrit (attente de SYSTEM)")
	}
	if d := h.ReportDetail(); !strings.Contains(d, "en attente") {
		t.Errorf("lien divergent : detail « en attente » attendu, got %q", d)
	}
	// Test reste non conforme (le lien ne pointe pas le bon home).
	ok, err := h.Test([]StateItem{firefoxItem()})
	if err != nil {
		t.Fatalf("Test : %v", err)
	}
	if ok {
		t.Errorf("lien divergent : Test doit rester non conforme")
	}
}

// VRAI dossier à l'emplacement du lien : le compagnon NE LE TOUCHE PAS (la mise
// de côté C1 est côté SYSTEM). Il constate « lien non prêt » et attend, sans
// écrire d'ini.
func TestAppProfileRealDirAtLinkPathWaitsNoIni(t *testing.T) {
	ops := newFakeAppProfileOps()
	ops.linkReal = true // un VRAI dossier occupe l'emplacement du lien.
	h := &AppProfileHandler{Ops: ops}

	if err := h.Apply([]StateItem{firefoxItem()}); err != nil {
		t.Fatalf("Apply : %v", err)
	}
	if _, ok := ops.files[fakeIniDir+`\profiles.ini`]; ok {
		t.Errorf("vrai dossier au lien : profiles.ini ne doit PAS être écrit (anti-C1, attente de SYSTEM)")
	}
	if d := h.ReportDetail(); !strings.Contains(d, "en attente") {
		t.Errorf("vrai dossier au lien : detail « en attente » attendu, got %q", d)
	}
}

func TestAppProfileUnreachableHomeErrorsWithoutLocalDestruction(t *testing.T) {
	ops := newFakeAppProfileOps()
	h := &AppProfileHandler{Ops: ops}
	ops.serverDown = true

	// Test remonte une ERREUR (jamais compliant/false silencieux).
	if _, err := h.Test([]StateItem{firefoxItem()}); err == nil {
		t.Errorf("home injoignable : Test doit remonter une erreur")
	}

	// Apply échoue AVANT toute op locale : aucun ini écrit.
	if err := h.Apply([]StateItem{firefoxItem()}); err == nil {
		t.Errorf("home injoignable : Apply doit échouer")
	}
	// Aucun fichier local (ini) n'a été écrit.
	for path := range ops.files {
		if !ops.isServer(path) {
			t.Errorf("écriture locale inattendue en home injoignable : %q", path)
		}
	}
}

func TestAppProfileMissingMinimalFieldsIsInvalidEnvelope(t *testing.T) {
	ops := newFakeAppProfileOps()
	h := &AppProfileHandler{Ops: ops}
	bad := StateItem{Type: "app_profile", Semantics: "aggregate", Payload: map[string]any{
		"app": "firefox", // manque link/server/profile_name
	}}
	if _, err := h.Test([]StateItem{bad}); err == nil {
		t.Errorf("payload incomplet : erreur d'enveloppe attendue")
	}
}

// AC4 (piège n°1) — un profiles.ini produit par CE mécanisme ne doit JAMAIS être
// matché par la garde `referencesSambaeduProfile()` du mécanisme legacy_cleanup
// (38.3), sinon les deux canaux se battraient à chaque logon. Test verrouillé en
// appelant RÉELLEMENT la fonction du package.
func TestAppProfileIniNotMatchedByLegacyCleanupGuard(t *testing.T) {
	profilesIni := BuildProfilesIni("managed.default", "308046B0AF4A39CB")
	if referencesSambaeduProfile(profilesIni) {
		t.Errorf("collision : le profiles.ini natif (managed.default) est matché par referencesSambaeduProfile() — legacy_cleanup l'effacerait à chaque logon.\n%s", profilesIni)
	}

	// Thunderbird (autre install_hash) — même invariant.
	tb := BuildProfilesIni("managed.default", "D78BF5DD33499EC2")
	if referencesSambaeduProfile(tb) {
		t.Errorf("collision Thunderbird : profiles.ini natif matché par referencesSambaeduProfile()")
	}

	// Sanity négatif : un profiles.ini legacy (sambaedu.default) EST bien matché
	// (la garde fonctionne — sinon le test ci-dessus serait vide de sens).
	legacy := "[Profile0]\r\nName=sambaedu\r\nIsRelative=1\r\nPath=sambaedu.default\r\nDefault=1\r\n"
	if !referencesSambaeduProfile(legacy) {
		t.Errorf("sanity : la garde devrait matcher un profiles.ini legacy sambaedu.default")
	}
}

func TestAppProfileIniContentIsFaithful(t *testing.T) {
	profiles := BuildProfilesIni("managed.default", "308046B0AF4A39CB")
	for _, want := range []string{
		"[Install308046B0AF4A39CB]",
		"Default=managed.default",
		"[Profile0]",
		"Name=managed",
		"IsRelative=1",
		"Path=managed.default",
		"Default=1",
		"StartWithLastProfile=1",
		"Version=2",
	} {
		if !strings.Contains(profiles, want) {
			t.Errorf("profiles.ini : fragment manquant %q\n%s", want, profiles)
		}
	}
	installs := BuildInstallsIni("managed.default", "308046B0AF4A39CB")
	if !strings.Contains(installs, "[308046B0AF4A39CB]") || !strings.Contains(installs, "Locked=1") {
		t.Errorf("installs.ini inattendu : %q", installs)
	}
	// Sans install_hash : pas de section Install ni de fichier installs.ini.
	if strings.Contains(BuildProfilesIni("managed.default", ""), "[Install") {
		t.Errorf("profiles.ini sans install_hash ne doit pas porter de section Install")
	}
	if BuildInstallsIni("managed.default", "") != "" {
		t.Errorf("installs.ini doit être vide sans install_hash")
	}
}
