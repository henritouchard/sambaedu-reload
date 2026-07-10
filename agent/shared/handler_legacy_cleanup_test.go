package shared

import (
	"fmt"
	"reflect"
	"regexp"
	"strings"
	"testing"
)

// Tests du handler `legacy_cleanup` (Story 38.3, contrat §7.10) — fake
// LegacyCleanupOps en mémoire. Racines injectées (WinDir/UsersDir/
// ProgramFiles/Netinst) pour des chemins Windows déterministes sur l'hôte.
// Couvre : chaque catégorie A-F, chaque GARDE (négatifs explicites),
// l'idempotence (2 RunPass), l'échec partiel (acquis conservés), le silence
// du poste sain (compliant sans Detail, zéro écriture) et le format des
// identifiants d'artefacts (T4).

// --- Fake LegacyCleanupOps -----------------------------------------------------

type fakeLegacyOps struct {
	files   map[string][]byte // chemin → contenu
	dirs    map[string]bool   // dossiers présents
	reparse map[string]bool   // chemins qui sont des reparse points
	tasks   map[string]string // tâche racine → action
	reg     map[string]RegistryValue

	failRemove map[string]bool // chemins dont Remove échoue (échec partiel)

	removeCnt    int
	removeAllCnt int
	writeCnt     int
	taskDelCnt   int
	regDelCnt    int
}

func newFakeLegacyOps() *fakeLegacyOps {
	return &fakeLegacyOps{
		files:      map[string][]byte{},
		dirs:       map[string]bool{},
		reparse:    map[string]bool{},
		tasks:      map[string]string{},
		reg:        map[string]RegistryValue{},
		failRemove: map[string]bool{},
	}
}

func regKey(hive, path, name string) string {
	return strings.ToLower(hive + `\` + path + `\` + name)
}

func (f *fakeLegacyOps) addFile(path, content string) { f.files[path] = []byte(content) }

func (f *fakeLegacyOps) Glob(pattern string) ([]string, error) {
	re := "^" + regexp.QuoteMeta(pattern) + "$"
	re = strings.ReplaceAll(re, `\*`, `[^\\]*`)
	rx, err := regexp.Compile("(?i)" + re)
	if err != nil {
		return nil, err
	}
	out := []string{}
	for path := range f.files {
		if rx.MatchString(path) {
			out = append(out, path)
		}
	}
	for path := range f.dirs {
		if rx.MatchString(path) {
			out = append(out, path)
		}
	}

	return out, nil
}

func (f *fakeLegacyOps) ReadFile(path string) ([]byte, error) {
	if raw, ok := f.files[path]; ok {
		return raw, nil
	}

	return nil, fmt.Errorf("fichier absent : %s", path)
}

func (f *fakeLegacyOps) WriteFile(path string, data []byte) error {
	f.files[path] = data
	f.writeCnt++

	return nil
}

func (f *fakeLegacyOps) Remove(path string) error {
	if f.failRemove[path] {
		return fmt.Errorf("accès refusé (simulé)")
	}
	if _, ok := f.files[path]; ok {
		delete(f.files, path)
		f.removeCnt++

		return nil
	}
	if f.dirs[path] || f.reparse[path] {
		delete(f.dirs, path)
		delete(f.reparse, path)
		f.removeCnt++

		return nil
	}

	return nil // déjà absent = idempotent (contrat de l'op)
}

func (f *fakeLegacyOps) RemoveAll(path string) error {
	prefix := strings.ToLower(path + `\`)
	for p := range f.files {
		if strings.ToLower(p) == strings.ToLower(path) || strings.HasPrefix(strings.ToLower(p), prefix) {
			delete(f.files, p)
		}
	}
	for p := range f.dirs {
		if strings.ToLower(p) == strings.ToLower(path) || strings.HasPrefix(strings.ToLower(p), prefix) {
			delete(f.dirs, p)
		}
	}
	f.removeAllCnt++

	return nil
}

func (f *fakeLegacyOps) Stat(path string) (LegacyPathInfo, error) {
	for p := range f.files {
		if strings.EqualFold(p, path) {
			return LegacyPathInfo{Exists: true}, nil
		}
	}
	for p := range f.dirs {
		if strings.EqualFold(p, path) {
			return LegacyPathInfo{Exists: true, IsDir: true, IsReparse: f.isReparse(p)}, nil
		}
	}
	for p := range f.reparse {
		if strings.EqualFold(p, path) {
			return LegacyPathInfo{Exists: true, IsReparse: true}, nil
		}
	}

	return LegacyPathInfo{}, nil
}

func (f *fakeLegacyOps) isReparse(path string) bool {
	for p := range f.reparse {
		if strings.EqualFold(p, path) {
			return true
		}
	}

	return false
}

func (f *fakeLegacyOps) ListDir(path string) ([]LegacyDirEntry, error) {
	prefix := path + `\`
	seen := map[string]LegacyDirEntry{}
	consider := func(p string, isDir bool) {
		if !strings.HasPrefix(strings.ToLower(p), strings.ToLower(prefix)) {
			return
		}
		rest := p[len(prefix):]
		name := rest
		child := false
		if i := strings.IndexByte(rest, '\\'); i >= 0 {
			name = rest[:i]
			child = true
		}
		key := strings.ToLower(name)
		if entry, ok := seen[key]; ok {
			if child && !entry.IsDir {
				entry.IsDir = true
				seen[key] = entry
			}

			return
		}
		full := prefix + name
		seen[key] = LegacyDirEntry{Name: name, IsDir: isDir || child || f.dirs[full], IsReparse: f.isReparse(full)}
	}
	for p := range f.files {
		consider(p, false)
	}
	for p := range f.dirs {
		if strings.EqualFold(p, path) {
			continue
		}
		consider(p, true)
	}
	for p := range f.reparse {
		consider(p, false)
	}
	out := []LegacyDirEntry{}
	for _, entry := range seen {
		out = append(out, entry)
	}

	return out, nil
}

func (f *fakeLegacyOps) TaskAction(name string) (string, bool, error) {
	action, ok := f.tasks[strings.ToLower(name)]

	return action, ok, nil
}

func (f *fakeLegacyOps) DeleteTask(name string) error {
	delete(f.tasks, strings.ToLower(name))
	f.taskDelCnt++

	return nil
}

func (f *fakeLegacyOps) RegistryRead(hive, path, name string) (RegistryValue, bool, error) {
	value, ok := f.reg[regKey(hive, path, name)]

	return value, ok, nil
}

func (f *fakeLegacyOps) RegistryDelete(hive, path, name string) error {
	delete(f.reg, regKey(hive, path, name))
	f.regDelCnt++

	return nil
}

func (f *fakeLegacyOps) opCount() int {
	return f.removeCnt + f.removeAllCnt + f.writeCnt + f.taskDelCnt + f.regDelCnt
}

// --- Helpers ---------------------------------------------------------------------

const (
	tWin   = `C:\Windows`
	tUsers = `C:\Users`
	tPF    = `C:\Program Files`
	tNet   = `C:\Netinst`
)

func newLegacyHandler(ops *fakeLegacyOps) *LegacyCleanupHandler {
	return &LegacyCleanupHandler{
		Ops:          ops,
		WinDir:       tWin,
		UsersDir:     tUsers,
		ProgramFiles: []string{tPF},
		NetinstDir:   tNet,
	}
}

func legacyItem() StateItem {
	return StateItem{
		Type:      "legacy_cleanup",
		Semantics: "exclusive",
		Hash:      "legacy-h",
		Payload:   map[string]any{"mozilla": "vanilla"},
	}
}

func legacyItems() []StateItem { return []StateItem{legacyItem()} }

// dirtyOps : un poste SE4 « sale » portant un artefact de CHAQUE catégorie A-F
// + les INTERDITS qui doivent rester intouchés.
func dirtyOps() *fakeLegacyOps {
	ops := newFakeLegacyOps()

	// A — blobs + marqueurs.
	ops.addFile(tWin+`\applications-logon.cmd`, "curl ...")
	ops.addFile(tWin+`\Temp\applications-logon-system.cmd`, "curl ...")
	ops.dirs[tUsers+`\marie`] = true
	ops.addFile(tUsers+`\marie\AppData\Local\Temp\applications-logon.cmd`, "curl ...")
	ops.addFile(tUsers+`\marie\AppData\Local\Temp\applications-logoff.ps1`, "ps1")
	ops.addFile(tUsers+`\marie\AppData\Local\Temp\shortcuts.cmd`, "cmd")
	ops.addFile(tWin+`\firefox.md5`, "0123456789abcdef0123456789ABCDEF\r\n")
	ops.addFile(tWin+`\notes.md5`, "pas un marqueur legacy") // garde : intouché

	// B — tâches.
	ops.tasks["wpkg4"] = `C:\Windows\wpkg-client.vbs /synchronize`
	ops.tasks["logon-system"] = `curl.exe -o "%windir%\temp\applications-logon-system.cmd" "http://se4/gpo/applications.php"`
	ops.tasks["logoff-system"] = `C:\Outils\sauvegarde.exe` // garde : conservée + rapportée

	// C — scripts GPO locale + scripts.ini (+ script sain conservé).
	gpoUser := tWin + `\System32\GroupPolicy\User\Scripts`
	ops.addFile(gpoUser+`\Logon\logon.cmd`, `curl.exe -F "os=windows" "http://%SE4FS%/gpo/applications.php"`)
	ops.addFile(gpoUser+`\Logon\autre.cmd`, "echo bonjour") // sain : conservé
	ops.addFile(gpoUser+`\scripts.ini`, "\r\n[Logon]\r\n0CmdLine=logon.cmd\r\n0Parameters=\r\n1CmdLine=autre.cmd\r\n1Parameters=\r\n")

	// D — WPKG + install + INTERDITS.
	ops.addFile(tWin+`\wpkg-client.vbs`, "vbs")
	ops.addFile(tWin+`\wpkg-gpo.txt`, "txt")
	ops.addFile(tWin+`\action.cmd`, "cmd")
	ops.addFile(tWin+`\autorun.cmd`, "cmd")
	ops.addFile(tWin+`\gpo.txt`, "txt")
	ops.addFile(tWin+`\wpkg.xml`, "<wpkg/>") // INTERDIT : base WPKG native
	ops.reparse[tWin+`\install`] = true      // jonction SMB legacy → supprimée
	ops.dirs[tWin+`\rapports`] = true        // VRAI dossier → intouché (garde)
	ops.dirs[tNet] = true
	ops.addFile(tNet+`\os\w10.wim`, "wim")
	ops.dirs[tWin+`\Web\SE4`] = true
	ops.addFile(tWin+`\Web\SE4\SetWallpaper.ps1`, "ps1")
	ops.reg[regKey("HKLM", legacyRunPath, "action")] = RegistryValue{Kind: "REG_SZ", Str: `%windir%\action.cmd`}
	ops.reg[regKey("HKLM", legacyWinlogonPath, "DefaultUserName")] = RegistryValue{Kind: "REG_SZ", Str: "se4install"}
	ops.reg[regKey("HKLM", legacyWinlogonPath, "DefaultPassword")] = RegistryValue{Kind: "REG_SZ", Str: "secret-en-clair"}
	ops.reg[regKey("HKLM", legacyWinlogonPath, "AutoAdminLogon")] = RegistryValue{Kind: "REG_SZ", Str: "1"}

	// E — helpers liste blanche + INTERDIT (l'agent).
	ops.dirs[tPF+`\SambaEdu`] = true
	ops.addFile(tPF+`\SambaEdu\SetWallpaper.ps1`, "ps1")
	ops.addFile(tPF+`\SambaEdu\Nettoyage WPKG.cmd`, "cmd")
	ops.dirs[tPF+`\SambaEdu\Agent`] = true
	ops.addFile(tPF+`\SambaEdu\Agent\agent.exe`, "exe") // INTERDIT ABSOLU

	// F — Mozilla : paire forcée (marie/Firefox), paire saine (paul/Firefox),
	// Thunderbird forcé (marie), dossier sambaedu.default à PRÉSERVER.
	ff := tUsers + `\marie\AppData\Roaming\Mozilla\Firefox`
	ops.addFile(ff+`\profiles.ini`, "[Install308046B0AF4A39CB]\r\nDefault=sambaedu.default\r\nLocked=1\r\n")
	ops.addFile(ff+`\installs.ini`, "[308046B0AF4A39CB]\r\nDefault=sambaedu.default\r\n")
	ops.dirs[ff+`\sambaedu.default`] = true
	ops.addFile(ff+`\sambaedu.default\places.sqlite`, "données utilisateur")
	tb := tUsers + `\marie\AppData\Roaming\Thunderbird`
	ops.addFile(tb+`\profiles.ini`, "[Profile0]\r\nName=default\r\nPath=sambaedu.default\r\n")
	// paul : profiles.ini SAIN (géré par l'utilisateur) → intouché.
	ops.dirs[tUsers+`\paul`] = true
	pf := tUsers + `\paul\AppData\Roaming\Mozilla\Firefox`
	ops.addFile(pf+`\profiles.ini`, "[Profile0]\r\nName=default\r\nPath=Profiles/abcd1234.default-release\r\n")
	ops.addFile(pf+`\installs.ini`, "[ABC]\r\nDefault=Profiles/abcd1234.default-release\r\n")
	// Répertoires spéciaux/jonctions de C:\Users : sautés.
	ops.dirs[tUsers+`\Public`] = true
	ops.addFile(tUsers+`\Public\AppData\Roaming\Mozilla\Firefox\profiles.ini`, "Default=sambaedu.default")
	ops.reparse[tUsers+`\ancien.profil`] = true

	return ops
}

func hasFile(ops *fakeLegacyOps, path string) bool {
	_, ok := ops.files[path]

	return ok
}

// --- (a) Nettoyage complet A-F + gardes négatives + idempotence ----------------

func TestLegacyCleanupFullPassRemovesCatalogAndRespectsGuards(t *testing.T) {
	ops := dirtyOps()
	h := newLegacyHandler(ops)
	engine := &Engine{Handlers: map[string]Handler{"legacy_cleanup": h}}

	// Passe 1 : drift + Detail listant les artefacts supprimés (AC5).
	report := engine.RunPass(legacyItems(), AppliedState{})
	if len(report) != 1 || report[0].Status != "drift" {
		t.Fatalf("passe 1 : drift attendu, obtenu %+v", report)
	}
	if report[0].Hash != "legacy-h" {
		t.Fatalf("hash opaque échoé verbatim attendu, obtenu %q", report[0].Hash)
	}
	detail := report[0].Detail
	for _, want := range []string{
		`file:` + tWin + `\applications-logon.cmd`,
		`task:wpkg4`,
		`task:logon-system`,
		`reg:HKLM\` + legacyRunPath + `\action`,
		`mozilla:` + tUsers + `\marie\AppData\Roaming\Mozilla\Firefox\profiles.ini`,
		`dir:` + tNet,
		`task:logoff-system conservée`,
	} {
		if !strings.Contains(detail, want) {
			t.Errorf("le Detail doit contenir %q, obtenu :\n%s", want, detail)
		}
	}

	// A — blobs supprimés, marqueur 32-hex supprimé, .md5 non-hex INTOUCHÉ.
	for _, gone := range []string{
		tWin + `\applications-logon.cmd`,
		tWin + `\Temp\applications-logon-system.cmd`,
		tUsers + `\marie\AppData\Local\Temp\applications-logon.cmd`,
		tUsers + `\marie\AppData\Local\Temp\applications-logoff.ps1`,
		tUsers + `\marie\AppData\Local\Temp\shortcuts.cmd`,
		tWin + `\firefox.md5`,
	} {
		if hasFile(ops, gone) {
			t.Errorf("A : %s aurait dû être supprimé", gone)
		}
	}
	if !hasFile(ops, tWin+`\notes.md5`) {
		t.Errorf("A garde : un .md5 au contenu non 32-hex ne doit JAMAIS être supprimé")
	}

	// B — tâches legacy supprimées, tâche suspecte CONSERVÉE.
	if _, ok := ops.tasks["wpkg4"]; ok {
		t.Errorf("B : la tâche wpkg4 aurait dû être supprimée")
	}
	if _, ok := ops.tasks["logon-system"]; ok {
		t.Errorf("B : la tâche logon-system aurait dû être supprimée")
	}
	if _, ok := ops.tasks["logoff-system"]; !ok {
		t.Errorf("B garde : une tâche au nom connu mais à l'action inconnue doit être CONSERVÉE")
	}

	// C — script curl supprimé, script sain conservé, scripts.ini purgé+renuméroté.
	gpoUser := tWin + `\System32\GroupPolicy\User\Scripts`
	if hasFile(ops, gpoUser+`\Logon\logon.cmd`) {
		t.Errorf("C : le script GPO locale curl-ant aurait dû être supprimé")
	}
	if !hasFile(ops, gpoUser+`\Logon\autre.cmd`) {
		t.Errorf("C garde : un script GPO locale SAIN ne doit pas être supprimé")
	}
	ini := string(ops.files[gpoUser+`\scripts.ini`])
	if strings.Contains(strings.ToLower(ini), "logon.cmd\r") || !strings.Contains(ini, "0CmdLine=autre.cmd") {
		t.Errorf("C : scripts.ini doit être purgé de logon.cmd et renuméroté, obtenu :\n%s", ini)
	}

	// D — fichiers/jonction/staging supprimés ; INTERDITS intouchés.
	for _, gone := range []string{tWin + `\wpkg-client.vbs`, tWin + `\wpkg-gpo.txt`, tWin + `\action.cmd`, tWin + `\autorun.cmd`, tWin + `\gpo.txt`} {
		if hasFile(ops, gone) {
			t.Errorf("D : %s aurait dû être supprimé", gone)
		}
	}
	if !hasFile(ops, tWin+`\wpkg.xml`) {
		t.Errorf("D INTERDIT : wpkg.xml (base WPKG du canal natif) ne doit JAMAIS être supprimé")
	}
	if ops.isReparse(tWin + `\install`) {
		t.Errorf("D : la jonction install (reparse) aurait dû être retirée")
	}
	if !ops.dirs[tWin+`\rapports`] {
		t.Errorf("D garde : un VRAI dossier rapports ne doit JAMAIS être touché")
	}
	if ops.dirs[tNet] || hasFile(ops, tNet+`\os\w10.wim`) {
		t.Errorf("D : C:\\Netinst aurait dû être supprimé récursivement")
	}
	if ops.dirs[tWin+`\Web\SE4`] || hasFile(ops, tWin+`\Web\SE4\SetWallpaper.ps1`) {
		t.Errorf("D : %%WINDIR%%\\Web\\SE4 aurait dû être supprimé")
	}
	if _, ok := ops.reg[regKey("HKLM", legacyRunPath, "action")]; ok {
		t.Errorf("D : la valeur Run\\action aurait dû être supprimée")
	}
	for _, name := range []string{"DefaultUserName", "DefaultPassword", "AutoAdminLogon"} {
		if _, ok := ops.reg[regKey("HKLM", legacyWinlogonPath, name)]; ok {
			t.Errorf("D : la valeur Winlogon\\%s (autologon se4install) aurait dû être supprimée", name)
		}
	}

	// E — helpers liste blanche supprimés, agent INTOUCHÉ, dossier conservé.
	if hasFile(ops, tPF+`\SambaEdu\SetWallpaper.ps1`) || hasFile(ops, tPF+`\SambaEdu\Nettoyage WPKG.cmd`) {
		t.Errorf("E : les helpers en liste blanche auraient dû être supprimés")
	}
	if !hasFile(ops, tPF+`\SambaEdu\Agent\agent.exe`) {
		t.Errorf("E INTERDIT ABSOLU : l'agent lui-même ne doit JAMAIS être touché")
	}
	if !ops.dirs[tPF+`\SambaEdu`] {
		t.Errorf("E : le dossier SambaEdu lui-même ne doit JAMAIS être supprimé")
	}

	// F — paires Mozilla forcées supprimées (FF + TB), dossier de profil
	// PRÉSERVÉ, profiles.ini sain INTOUCHÉ, répertoires spéciaux sautés.
	ff := tUsers + `\marie\AppData\Roaming\Mozilla\Firefox`
	if hasFile(ops, ff+`\profiles.ini`) || hasFile(ops, ff+`\installs.ini`) {
		t.Errorf("F : la paire profiles.ini/installs.ini forcée aurait dû être supprimée")
	}
	if !ops.dirs[ff+`\sambaedu.default`] || !hasFile(ops, ff+`\sambaedu.default\places.sqlite`) {
		t.Errorf("F garde : le dossier sambaedu.default (données utilisateur) ne doit JAMAIS être supprimé")
	}
	if hasFile(ops, tUsers+`\marie\AppData\Roaming\Thunderbird\profiles.ini`) {
		t.Errorf("F : la paire Thunderbird forcée aurait dû être supprimée")
	}
	pf := tUsers + `\paul\AppData\Roaming\Mozilla\Firefox`
	if !hasFile(ops, pf+`\profiles.ini`) || !hasFile(ops, pf+`\installs.ini`) {
		t.Errorf("F garde : un profiles.ini SAIN (et son installs.ini) ne doit JAMAIS être touché")
	}
	if !hasFile(ops, tUsers+`\Public\AppData\Roaming\Mozilla\Firefox\profiles.ini`) {
		t.Errorf("F garde : le répertoire spécial Public doit être sauté")
	}

	// Passe 2 : IDEMPOTENCE — plus rien à trouver, zéro op, compliant, Detail
	// = notes suspectes seulement (la tâche conservée reste rapportée).
	opsBefore := ops.opCount()
	report = engine.RunPass(legacyItems(), AppliedState{})
	if len(report) != 1 || report[0].Status != "compliant" {
		t.Fatalf("passe 2 : compliant attendu (idempotence), obtenu %+v", report)
	}
	if ops.opCount() != opsBefore {
		t.Fatalf("passe 2 : ZÉRO op attendue (idempotence), %d ops supplémentaires", ops.opCount()-opsBefore)
	}
	if !strings.Contains(report[0].Detail, "task:logoff-system conservée") {
		t.Errorf("passe 2 : la tâche suspecte conservée reste rapportée en détail, obtenu %q", report[0].Detail)
	}
}

// --- (b) Poste SAIN : compliant, SANS Detail, zéro écriture (AC5 / piège #6) ---

func TestLegacyCleanupHealthyWorkstationIsSilent(t *testing.T) {
	ops := newFakeLegacyOps()
	// Un poste SE5 nominal : agent + vrai dossier install natif + wpkg.xml.
	ops.dirs[tPF+`\SambaEdu\Agent`] = true
	ops.addFile(tPF+`\SambaEdu\Agent\agent.exe`, "exe")
	ops.dirs[tWin+`\install\wpkg\tools`] = true
	ops.addFile(tWin+`\wpkg.xml`, "<wpkg/>")
	ops.dirs[tUsers+`\marie`] = true
	pf := tUsers + `\marie\AppData\Roaming\Mozilla\Firefox`
	ops.addFile(pf+`\profiles.ini`, "[Profile0]\r\nPath=Profiles/abcd.default-release\r\n")

	h := newLegacyHandler(ops)
	engine := &Engine{Handlers: map[string]Handler{"legacy_cleanup": h}}

	report := engine.RunPass(legacyItems(), AppliedState{})
	if len(report) != 1 || report[0].Status != "compliant" {
		t.Fatalf("poste sain : compliant attendu, obtenu %+v", report)
	}
	if report[0].Detail != "" {
		t.Fatalf("poste sain : AUCUN Detail attendu (AC5), obtenu %q", report[0].Detail)
	}
	if ops.opCount() != 0 {
		t.Fatalf("poste sain : AUCUNE écriture attendue, obtenu %d ops", ops.opCount())
	}
	if !hasFile(ops, pf+`\profiles.ini`) || !hasFile(ops, tWin+`\wpkg.xml`) {
		t.Fatalf("poste sain : rien ne doit avoir bougé")
	}
}

// --- (c) Garde Winlogon : DefaultUserName ≠ se4install ⇒ INTOUCHÉ ---------------

func TestLegacyCleanupWinlogonGuardLegitAutologonUntouched(t *testing.T) {
	ops := newFakeLegacyOps()
	ops.reg[regKey("HKLM", legacyWinlogonPath, "DefaultUserName")] = RegistryValue{Kind: "REG_SZ", Str: "borne-accueil"}
	ops.reg[regKey("HKLM", legacyWinlogonPath, "DefaultPassword")] = RegistryValue{Kind: "REG_SZ", Str: "kiosque"}
	ops.reg[regKey("HKLM", legacyWinlogonPath, "AutoAdminLogon")] = RegistryValue{Kind: "REG_SZ", Str: "1"}

	h := newLegacyHandler(ops)
	ok, err := h.Test(legacyItems())
	if err != nil || !ok {
		t.Fatalf("autologon légitime : compliant attendu (ok=%v err=%v)", ok, err)
	}
	if err := h.Apply(legacyItems()); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if _, present := ops.reg[regKey("HKLM", legacyWinlogonPath, "DefaultPassword")]; !present {
		t.Fatalf("garde : un autologon légitime (DefaultUserName ≠ se4install) ne doit JAMAIS être purgé")
	}
	if ops.regDelCnt != 0 {
		t.Fatalf("aucune suppression registre attendue, obtenu %d", ops.regDelCnt)
	}
}

// --- (d) Échec partiel ⇒ error + acquis conservés (D4) --------------------------

func TestLegacyCleanupPartialFailureKeepsAcquiredRemovals(t *testing.T) {
	ops := newFakeLegacyOps()
	ops.addFile(tWin+`\applications-logon.cmd`, "curl")
	ops.addFile(tWin+`\wpkg-client.vbs`, "vbs")
	ops.failRemove[tWin+`\wpkg-client.vbs`] = true // fichier verrouillé simulé

	h := newLegacyHandler(ops)
	err := h.Apply(legacyItems())
	if err == nil {
		t.Fatalf("échec partiel : une erreur agrégée doit remonter")
	}
	if !strings.Contains(err.Error(), "wpkg-client.vbs") {
		t.Fatalf("l'erreur doit nommer l'artefact en échec, obtenu : %v", err)
	}
	if hasFile(ops, tWin+`\applications-logon.cmd`) {
		t.Fatalf("les AUTRES suppressions restent acquises (effort maximal)")
	}

	// À travers le moteur : verdict error pour le type, detail = message.
	engine := &Engine{Handlers: map[string]Handler{"legacy_cleanup": h}}
	ops2 := newFakeLegacyOps()
	ops2.addFile(tWin+`\wpkg-client.vbs`, "vbs")
	ops2.failRemove[tWin+`\wpkg-client.vbs`] = true
	h.Ops = ops2
	report := engine.RunPass(legacyItems(), AppliedState{})
	if len(report) != 1 || report[0].Status != "error" || report[0].Detail == "" {
		t.Fatalf("verdict error avec detail attendu, obtenu %+v", report)
	}

	// La passe suivante RETENTE (level-triggered) : déblocage ⇒ drift puis sain.
	delete(ops2.failRemove, tWin+`\wpkg-client.vbs`)
	report = engine.RunPass(legacyItems(), AppliedState{})
	if len(report) != 1 || report[0].Status != "drift" {
		t.Fatalf("après déblocage : drift (nettoyage) attendu, obtenu %+v", report)
	}
}

// --- (e) Payload invalide ⇒ error pour le type ----------------------------------

func TestLegacyCleanupInvalidPayloadIsError(t *testing.T) {
	h := newLegacyHandler(newFakeLegacyOps())
	cases := []struct {
		name    string
		payload any
	}{
		{"non objet", "texte"},
		{"mozilla absent", map[string]any{}},
		{"mozilla non string", map[string]any{"mozilla": 1}},
		{"mozilla hors enum", map[string]any{"mozilla": "forced"}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			items := []StateItem{{Type: "legacy_cleanup", Semantics: "exclusive", Hash: "h", Payload: tc.payload}}
			if _, err := h.Test(items); err == nil {
				t.Fatalf("payload invalide attendu en erreur de Test")
			}
			if err := h.Apply(items); err == nil {
				t.Fatalf("payload invalide attendu en erreur d'Apply")
			}
		})
	}
}

// --- (f) AUCUN store (attesté structurellement, piège #8) ------------------------

func TestLegacyCleanupHandlerHasNoStore(t *testing.T) {
	typ := reflect.TypeOf(LegacyCleanupHandler{})
	if _, ok := typ.FieldByName("StatePath"); ok {
		t.Fatalf("LegacyCleanupHandler ne doit PAS porter de StatePath (scan sans store, piège #8)")
	}
	// Aucune op de store dans l'interface : LegacyCleanupOps n'expose ni
	// lecture ni écriture d'un état persistant du handler (WriteFile ne sert
	// qu'à la purge de scripts.ini — vérifié par revue ; ici on fige le nombre
	// d'ops pour que tout ajout soit un choix conscient).
	if n := reflect.TypeOf((*LegacyCleanupOps)(nil)).Elem().NumMethod(); n != 11 {
		t.Fatalf("LegacyCleanupOps doit exposer exactement 11 ops, obtenu %d (tout ajout = décision consciente)", n)
	}
}

// --- (g) Détail borné à 2000 runes (contrat §6) ----------------------------------

func TestLegacyCleanupDetailIsBounded(t *testing.T) {
	ops := newFakeLegacyOps()
	for i := 0; i < 200; i++ {
		ops.addFile(fmt.Sprintf(`%s\applications-%03d-tres-long-nom-de-blob-legacy.cmd`, tWin, i), "curl")
	}
	h := newLegacyHandler(ops)
	engine := &Engine{Handlers: map[string]Handler{"legacy_cleanup": h}}

	report := engine.RunPass(legacyItems(), AppliedState{})
	if len(report) != 1 || report[0].Status != "drift" {
		t.Fatalf("drift attendu, obtenu %+v", report)
	}
	if got := len([]rune(report[0].Detail)); got > 2000 {
		t.Fatalf("detail borné à 2000 runes (contrat §6), obtenu %d", got)
	}
}

// --- (h) purgeScriptsIni : renumérotation + vidage --------------------------------

func TestPurgeScriptsIniRenumbersAndDetectsEmpty(t *testing.T) {
	content := "\r\n[Logon]\r\n0CmdLine=logon.cmd\r\n0Parameters=\r\n1CmdLine=autre.cmd\r\n1Parameters=x\r\n[Logoff]\r\n0CmdLine=logoff.cmd\r\n0Parameters=\r\n"

	out, changed, empty := purgeScriptsIni(content, map[string]bool{"logon.cmd": true, "logoff.cmd": true})
	if !changed || empty {
		t.Fatalf("changed=true empty=false attendus (autre.cmd subsiste), obtenu changed=%v empty=%v", changed, empty)
	}
	if !strings.Contains(out, "0CmdLine=autre.cmd") || strings.Contains(out, "logon.cmd") || strings.Contains(out, "[Logoff]") {
		t.Fatalf("purge/renumérotation attendue, obtenu :\n%s", out)
	}

	_, changed, empty = purgeScriptsIni(content, map[string]bool{"logon.cmd": true, "autre.cmd": true, "logoff.cmd": true})
	if !changed || !empty {
		t.Fatalf("tout purgé ⇒ empty=true attendu")
	}

	_, changed, _ = purgeScriptsIni(content, map[string]bool{"inconnu.cmd": true})
	if changed {
		t.Fatalf("aucune entrée matchée ⇒ changed=false attendu")
	}
}

// --- (i) UTF-16LE : round-trip du scripts.ini GPO ---------------------------------

func TestLegacyCleanupScriptsIniUtf16RoundTrip(t *testing.T) {
	plain := "\r\n[Logon]\r\n0CmdLine=logon.cmd\r\n0Parameters=\r\n1CmdLine=autre.cmd\r\n1Parameters=\r\n"
	raw := encodeText(plain, true) // BOM FF FE + UTF-16LE (format GPO usuel)

	ops := newFakeLegacyOps()
	gpo := tWin + `\System32\GroupPolicy\Machine\Scripts`
	ops.addFile(gpo+`\Startup\logon.cmd`, `curl "http://se4/gpo/shortcuts_out.php"`)
	ops.files[gpo+`\scripts.ini`] = raw

	h := newLegacyHandler(ops)
	if err := h.Apply(legacyItems()); err != nil {
		t.Fatalf("apply: %v", err)
	}
	rewritten := ops.files[gpo+`\scripts.ini`]
	if len(rewritten) < 2 || rewritten[0] != 0xFF || rewritten[1] != 0xFE {
		t.Fatalf("le scripts.ini réécrit doit rester en UTF-16LE avec BOM")
	}
	text, utf16le := decodeTextAuto(rewritten)
	if !utf16le || !strings.Contains(text, "0CmdLine=autre.cmd") || strings.Contains(text, "logon.cmd\r") {
		t.Fatalf("purge UTF-16 attendue, obtenu :\n%s", text)
	}
}

// --- (j) referencesSambaeduProfile / is32Hex : bornes ------------------------------

func TestLegacyCleanupContentGuards(t *testing.T) {
	if !referencesSambaeduProfile("[Install308046B0AF4A39CB]\nDefault=sambaedu.default\n") {
		t.Errorf("Default=sambaedu.default doit être reconnu")
	}
	if !referencesSambaeduProfile("[Profile0]\r\nPath=SAMBAEDU.DEFAULT\r\n") {
		t.Errorf("Path=sambaedu.default insensible à la casse doit être reconnu")
	}
	if referencesSambaeduProfile("[Profile0]\nPath=Profiles/abcd.default-release\n") {
		t.Errorf("un profil utilisateur normal ne doit PAS matcher")
	}
	if referencesSambaeduProfile("[Profile0]\nPath=sambaedu.default.backup\n") {
		t.Errorf("match EXACT exigé (pas de préfixe)")
	}

	if !is32Hex("0123456789abcdef0123456789ABCDEF") || !is32Hex("0123456789abcdef0123456789abcdef\r\n") {
		t.Errorf("32 hex (± fin de ligne) doit passer la garde")
	}
	if is32Hex("0123456789abcdef0123456789abcde") || is32Hex("0123456789abcdef0123456789abcdeg") || is32Hex("") {
		t.Errorf("contenu non 32-hex : garde fermée")
	}
}
