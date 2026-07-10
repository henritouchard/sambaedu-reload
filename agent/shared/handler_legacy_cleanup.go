package shared

import (
	"fmt"
	"sort"
	"strings"
	"unicode/utf16"
)

// Handler `legacy_cleanup` (exclusive / scope MACHINE uniquement) — Story 38.3,
// contrat §7.10. Retire du poste les CROCHETS CLIENTS legacy SE4 (curl
// applications, déclencheurs WPKG, helpers obsolètes, autologon résiduel,
// paires Mozilla forcées) par le canal authentifié agent — JAMAIS par du code
// servi en HTTP (D2/Q1 de l'epic 38). Logique PURE, OS-agnostique (les accès
// fichiers/tâches/registre réels sont injectés via LegacyCleanupOps) → testée
// sur l'hôte ; agent/windows n'apporte que l'impl (os.*, powershell
// Get/Unregister-ScheduledTask, golang.org/x/sys/windows/registry).
//
// CATALOGUE VERSIONNÉ DANS L'AGENT (D3) : les chemins/globs/noms de tâches sont
// de la connaissance legacy FIGÉE (chemins Windows du canal SE4), pas du
// paramétrage métier — le serveur GATE (capacité `legacy_hooks_cleanup`),
// l'agent sait QUOI nettoyer. Payload minimal `{mozilla: "vanilla"}` (enum
// fermé 1 valeur — trace contractuelle de Q5-a, extensible).
//
// SANS STORE (piège #8, iso `firewall`/`privilege` — PAS `fs_acl`) : les
// artefacts sont ÉNUMÉRABLES PAR SCAN à chaque passe. Un store serait une
// seconde source de vérité inutile.
//
// CONVERGENCE level-triggered (§5, STRICT inconditionnel 27.8) :
//   - Test  : SCAN du catalogue — conforme ssi ZÉRO artefact supprimable
//     trouvé (poste sain = aucune écriture, item compliant sans Detail —
//     piège #6 : le type présent au state émet TOUJOURS son statut, la
//     « silence » est la dédup serveur par hash sur rapport identique) ;
//   - Apply : RE-SCAN puis suppression de chaque artefact trouvé, chaque
//     catégorie portant SA garde (piège #4 : liste blanche, jamais de récursif
//     large). Effort MAXIMAL par artefact : un échec (fichier verrouillé,
//     accès refusé) est collecté, les AUTRES suppressions restent acquises,
//     l'erreur agrégée remonte à la fin (verdict `error` du type, D4) — la
//     passe suivante retente (level-triggered). Idempotent : une 2e passe sur
//     poste nettoyé ne trouve plus rien et n'écrit rien.
//
// GARDES DE SÛRETÉ PAR CATÉGORIE (piège #4 — chaque suppression est
// individuellement gardée) :
//   - A `.md5` : contenu EXACTEMENT 32 hexadécimaux (± fin de ligne) ;
//   - B tâches : nom exact ET action référençant gpo/applications.php|wpkg —
//     nom connu mais action inconnue = CONSERVÉE + rapportée en détail ;
//   - C scripts GPO locale : contenu curl + gpo/applications.php|
//     gpo/shortcuts_out.php ; INTERDIT STRUCTUREL : GroupPolicy\DataStore
//     (cache des GPO de DOMAINE — contient SE_agent_bootstrap) n'est JAMAIS
//     visité (aucun chemin du catalogue n'y pointe) ;
//   - D jonctions install/rapports : reparse point SEULEMENT (un vrai dossier
//     = provisionné par le module natif 27.20, INTOUCHABLE — piège #3) ;
//     `%SystemRoot%\wpkg.xml` (base WPKG du canal natif) HORS catalogue ;
//     RemoveAll UNIQUEMENT sur C:\Netinst (exclusivement legacy) ;
//     %WINDIR%\Web\SE4 en forme conservatrice (fichier nommé + rmdir si
//     vide — review 38.3 #2) ; autologon Winlogon purgé SSI
//     DefaultUserName == se4install (jamais casser un autologon légitime) ;
//   - E helpers %ProgramFiles%\SambaEdu : LISTE BLANCHE NOMMÉE de fichiers —
//     jamais le dossier, jamais Agent\** (l'agent lui-même est INEXPRIMABLE :
//     aucun glob, que des noms de fichiers à la racine du dossier) ;
//   - F Mozilla (Q5-a VANILLA) : la PAIRE profiles.ini+installs.ini SSI
//     profiles.ini référence `sambaedu.default` — JAMAIS le dossier de profil
//     (données utilisateur), JAMAIS un profiles.ini sain (poste perso), AUCUN
//     profil forcé posé (Firefox/Thunderbird se recréent au prochain
//     lancement). Répertoires spéciaux/reparse de C:\Users sautés.

// legacyTaskNames : tâches planifiées legacy (racine du Task Scheduler) —
// canal applications (GPP ScheduledTasks.xml removePolicy=0) + wpkg4.
var legacyTaskNames = []string{
	"wpkg4", "logon-system", "logoff-system", "remote-logon-system", "remote-logoff-system",
}

// legacyHelperWhitelist : helpers %ProgramFiles%\SambaEdu obsolètes — LISTE
// BLANCHE NOMMÉE (jamais de suppression du dossier, jamais Agent\**).
var legacyHelperWhitelist = []string{
	"powershellTask.ps1", "driversAuto.ps1", "winget-install.ps1",
	"SetWallpaper.ps1", "Nettoyage WPKG.cmd",
}

// mozillaSkipProfiles : répertoires de C:\Users qui ne sont PAS des profils
// utilisateur réels (comparaison insensible à la casse).
var mozillaSkipProfiles = map[string]bool{
	"public": true, "default": true, "default user": true, "all users": true,
}

// LegacyPathInfo : résultat de LegacyCleanupOps.Stat. Exists=false ⇒ les
// autres champs sont sans signification.
type LegacyPathInfo struct {
	Exists    bool
	IsDir     bool
	IsReparse bool
}

// LegacyDirEntry : une entrée de LegacyCleanupOps.ListDir.
type LegacyDirEntry struct {
	Name      string
	IsDir     bool
	IsReparse bool
}

// LegacyCleanupOps : accès OS injectés (testable hôte). L'impl Windows vit
// dans agent/windows/handler_legacy_cleanup_windows.go ; un fake en mémoire
// couvre les tests. Les INTERDITS sont structurels : AUCUNE op récursive hors
// RemoveAll (que le handler ne pointe QUE sur C:\Netinst).
type LegacyCleanupOps interface {
	// Glob retourne les chemins matchant le motif (`*` par segment). Aucun
	// match ⇒ (nil, nil).
	Glob(pattern string) ([]string, error)
	// ReadFile lit le contenu d'un fichier (gardes de contenu : .md5 32-hex,
	// scripts curl, profiles.ini).
	ReadFile(path string) ([]byte, error)
	// WriteFile (ré)écrit un fichier (purge de scripts.ini).
	WriteFile(path string, data []byte) error
	// Remove supprime UN chemin : fichier, lien/jonction (le lien seul, jamais
	// la cible) ou dossier vide. JAMAIS récursif. Déjà absent ⇒ nil
	// (idempotent).
	Remove(path string) error
	// RemoveAll supprime récursivement — le handler ne l'appelle QUE sur
	// C:\Netinst (piège #4). Déjà absent ⇒ nil.
	RemoveAll(path string) error
	// Stat inspecte un chemin SANS suivre les liens (Lstat + détection reparse
	// point). Absent ⇒ ({Exists: false}, nil) — pas une erreur.
	Stat(path string) (LegacyPathInfo, error)
	// ListDir énumère les entrées directes d'un dossier (détection reparse par
	// entrée). Dossier absent ⇒ (nil, nil).
	ListDir(path string) ([]LegacyDirEntry, error)
	// TaskAction lit l'ACTION (exécutable + arguments) d'une tâche planifiée à
	// la RACINE du Task Scheduler. Absente ⇒ ("", false, nil).
	TaskAction(name string) (action string, exists bool, err error)
	// DeleteTask désenregistre la tâche nommée. Déjà absente ⇒ nil.
	DeleteTask(name string) error
	// RegistryRead lit une valeur de registre (iso RegistryOps.Read — l'impl
	// Windows DÉLÈGUE au registryOps existant).
	RegistryRead(hive, path, name string) (value RegistryValue, present bool, err error)
	// RegistryDelete supprime une VALEUR nommée (iso RegistryOps.Delete —
	// jamais la clé-conteneur). Déjà absente ⇒ nil.
	RegistryDelete(hive, path, name string) error
}

// legacyFinding : UN artefact legacy trouvé au scan — identifiant STABLE
// (format T4 : `task:wpkg4`, `file:C:\…`, `reg:HKLM\…`, `mozilla:C:\…`,
// `dir:C:\…`) + action de suppression GARDÉE (la garde a été évaluée au scan).
type legacyFinding struct {
	id     string
	remove func() error
}

// LegacyCleanupHandler : handler exclusive branché dans le moteur. SERVICE
// SYSTEM seul (HKLM, schtasks, C:\Users\* — D2 : aucun volet compagnon).
// AUCUN champ de store (piège #8) — les racines sont injectables (tests hôte),
// vides = défauts Windows.
type LegacyCleanupHandler struct {
	Ops LegacyCleanupOps
	Log *Logger

	// Racines injectables (tests hôte). Vides = défauts Windows.
	WinDir       string   // défaut C:\Windows
	UsersDir     string   // défaut C:\Users
	ProgramFiles []string // défaut [C:\Program Files, C:\Program Files (x86)]
	NetinstDir   string   // défaut C:\Netinst

	// lastDetail : détail du DERNIER Test/Apply (DetailReporter — artefacts
	// supprimés + tâches suspectes conservées). Poste sain ⇒ "" (AC5).
	lastDetail string
}

func (h *LegacyCleanupHandler) winDir() string {
	if h.WinDir != "" {
		return h.WinDir
	}

	return `C:\Windows`
}

func (h *LegacyCleanupHandler) usersDir() string {
	if h.UsersDir != "" {
		return h.UsersDir
	}

	return `C:\Users`
}

func (h *LegacyCleanupHandler) programFiles() []string {
	if len(h.ProgramFiles) > 0 {
		return h.ProgramFiles
	}

	return []string{`C:\Program Files`, `C:\Program Files (x86)`}
}

func (h *LegacyCleanupHandler) netinstDir() string {
	if h.NetinstDir != "" {
		return h.NetinstDir
	}

	return `C:\Netinst`
}

// ReportDetail : détail du dernier Test/Apply (interface DetailReporter du
// moteur). Vide sur poste sain — l'item compliant reste SANS detail (AC5).
func (h *LegacyCleanupHandler) ReportDetail() string {
	return h.lastDetail
}

// parseLegacyCleanupSpec : extrait le payload §7.10. Enveloppe invalide
// (false → {status: error} pour le type) si le payload n'est pas un objet, si
// `mozilla` est absent/non-string ou hors de l'enum fermé (`vanilla` seule
// valeur v1 — Q5-a).
func parseLegacyCleanupSpec(raw any) (mozilla string, ok bool) {
	payload, isMap := raw.(map[string]any)
	if !isMap || payload == nil {
		return "", false
	}
	value, isString := payload["mozilla"].(string)
	if !isString || strings.ToLower(strings.TrimSpace(value)) != "vanilla" {
		return "", false
	}

	return "vanilla", true
}

// desiredSpec : parse les items du type (exclusive : le compilateur garantit
// UN item ; défense : le DERNIER fait foi, iso les autres handlers). Toute
// enveloppe invalide = erreur franche.
func (h *LegacyCleanupHandler) desiredSpec(items []StateItem) (string, error) {
	mozilla := ""
	for _, item := range items {
		value, ok := parseLegacyCleanupSpec(item.Payload)
		if !ok {
			return "", fmt.Errorf("payload legacy_cleanup inattendu : enveloppe invalide (attendu {mozilla: \"vanilla\"})")
		}
		mozilla = value
	}
	if mozilla == "" {
		return "", fmt.Errorf("payload legacy_cleanup inattendu : aucun item")
	}

	return mozilla, nil
}

// Test : SCAN — conforme ssi zéro artefact supprimable. Les tâches SUSPECTES
// (nom connu, action inconnue — jamais supprimées) ne rendent PAS non conforme
// (sinon drift perpétuel sans op) mais sont rapportées en détail. Erreur de
// scan = franche (le moteur rend error pour le type ; design assumé iso
// registry 35.3 : un Test menteur masquerait des pannes réelles).
func (h *LegacyCleanupHandler) Test(items []StateItem) (bool, error) {
	h.lastDetail = ""
	mozilla, err := h.desiredSpec(items)
	if err != nil {
		return false, err
	}

	findings, suspects, err := h.scan(mozilla)
	if err != nil {
		return false, err
	}
	h.lastDetail = strings.Join(suspects, "\n")

	return len(findings) == 0, nil
}

// Apply : RE-SCAN puis suppression gardée de chaque artefact, en effort
// MAXIMAL (un échec n'empêche pas les autres suppressions — acquises ; erreur
// agrégée remontée à la fin, D4). Idempotent : poste nettoyé ⇒ zéro op.
func (h *LegacyCleanupHandler) Apply(items []StateItem) error {
	h.lastDetail = ""
	mozilla, err := h.desiredSpec(items)
	if err != nil {
		return err
	}

	findings, suspects, err := h.scan(mozilla)
	if err != nil {
		return err
	}

	removed := []string{}
	failures := []string{}
	for _, finding := range findings {
		if err := finding.remove(); err != nil {
			failures = append(failures, fmt.Sprintf("%s : %v", finding.id, err))

			continue
		}
		removed = append(removed, finding.id)
		logInfo(h.Log, "Artefact legacy supprimé : %s", finding.id)
	}

	lines := append(append([]string{}, removed...), suspects...)
	h.lastDetail = strings.Join(lines, "\n")

	if len(failures) > 0 {
		detail := strings.Join(failures, " ; ")
		if len(removed) > 0 {
			detail += " | supprimés avant échec : " + strings.Join(removed, ", ")
		}

		return fmt.Errorf("nettoyage legacy partiel (%d échec(s)) : %s", len(failures), detail)
	}

	return nil
}

// scan : parcourt le catalogue A-F et retourne les artefacts SUPPRIMABLES
// (garde évaluée) + les notes SUSPECTES (tâche au nom connu, action inconnue —
// conservée + rapportée). Ordre déterministe (logs/détails stables).
func (h *LegacyCleanupHandler) scan(mozilla string) ([]legacyFinding, []string, error) {
	findings := []legacyFinding{}
	suspects := []string{}
	seen := map[string]bool{} // dédup par id minuscule (variantes de casse E)

	add := func(f legacyFinding) {
		key := strings.ToLower(f.id)
		if seen[key] {
			return
		}
		seen[key] = true
		findings = append(findings, f)
	}

	if err := h.scanBlobs(add); err != nil {
		return nil, nil, err
	}
	taskSuspects, err := h.scanTasks(add)
	if err != nil {
		return nil, nil, err
	}
	suspects = append(suspects, taskSuspects...)
	if err := h.scanLocalGpoScripts(add); err != nil {
		return nil, nil, err
	}
	if err := h.scanWpkgAndInstall(add); err != nil {
		return nil, nil, err
	}
	if err := h.scanRegistryHooks(add); err != nil {
		return nil, nil, err
	}
	if err := h.scanHelpers(add); err != nil {
		return nil, nil, err
	}
	if mozilla == "vanilla" {
		if err := h.scanMozilla(add); err != nil {
			return nil, nil, err
		}
	}

	return findings, suspects, nil
}

// fileFinding : finding standard « supprimer ce fichier/lien » (Remove ciblé,
// jamais récursif).
func (h *LegacyCleanupHandler) fileFinding(path string) legacyFinding {
	return legacyFinding{id: "file:" + path, remove: func() error { return h.Ops.Remove(path) }}
}

// --- A : blobs et marqueurs du canal applications -----------------------------

func (h *LegacyCleanupHandler) scanBlobs(add func(legacyFinding)) error {
	win := h.winDir()

	// Blobs %windir% (patron ancien) + tâches GPP SYSTEM (%windir%\Temp).
	for _, pattern := range []string{
		win + `\applications-*.cmd`,
		win + `\Temp\applications-logon-system*.cmd`,
	} {
		matches, err := h.Ops.Glob(pattern)
		if err != nil {
			return fmt.Errorf("scan des blobs %s : %w", pattern, err)
		}
		for _, path := range matches {
			add(h.fileFinding(path))
		}
	}

	// Blobs per-user %TEMP% (constatés au ffdiag v2) — profils réels seulement.
	profiles, err := h.realProfiles()
	if err != nil {
		return err
	}
	for _, profile := range profiles {
		temp := h.usersDir() + `\` + profile + `\AppData\Local\Temp`
		for _, pattern := range []string{
			temp + `\applications-*.cmd`,
			temp + `\applications-*.ps1`,
			temp + `\shortcuts.cmd`,
		} {
			matches, err := h.Ops.Glob(pattern)
			if err != nil {
				return fmt.Errorf("scan des blobs per-user %s : %w", pattern, err)
			}
			for _, path := range matches {
				add(h.fileFinding(path))
			}
		}
	}

	// Marqueurs « once » %windir%\*.md5 — GARDE : contenu EXACTEMENT
	// 32 hexadécimaux (± fin de ligne), signature du marqueur legacy
	// (applications.inc.php:506-516). Tout autre contenu = INTOUCHÉ.
	md5s, err := h.Ops.Glob(win + `\*.md5`)
	if err != nil {
		return fmt.Errorf("scan des marqueurs .md5 : %w", err)
	}
	for _, path := range md5s {
		raw, err := h.Ops.ReadFile(path)
		if err != nil {
			return fmt.Errorf("lecture du marqueur %s : %w", path, err)
		}
		if is32Hex(string(raw)) {
			add(h.fileFinding(path))
		}
	}

	return nil
}

// is32Hex : le contenu est-il exactement 32 caractères hexadécimaux (± fin de
// ligne / espaces terminaux) ?
func is32Hex(content string) bool {
	trimmed := strings.TrimSpace(content)
	if len(trimmed) != 32 {
		return false
	}
	for _, r := range trimmed {
		switch {
		case r >= '0' && r <= '9', r >= 'a' && r <= 'f', r >= 'A' && r <= 'F':
		default:
			return false
		}
	}

	return true
}

// --- B : tâches planifiées legacy ---------------------------------------------

// scanTasks : GARDE nom exact ET action référençant le legacy
// (gpo/applications.php ou wpkg). Nom connu mais action inconnue → CONSERVÉE +
// note suspecte (rapportée en détail, jamais un drift perpétuel).
func (h *LegacyCleanupHandler) scanTasks(add func(legacyFinding)) ([]string, error) {
	suspects := []string{}
	for _, name := range legacyTaskNames {
		action, exists, err := h.Ops.TaskAction(name)
		if err != nil {
			return nil, fmt.Errorf("lecture de la tâche planifiée %q : %w", name, err)
		}
		if !exists {
			continue
		}
		lower := strings.ToLower(action)
		if strings.Contains(lower, "gpo/applications.php") || strings.Contains(lower, `gpo\applications.php`) || strings.Contains(lower, "wpkg") {
			taskName := name
			add(legacyFinding{id: "task:" + taskName, remove: func() error { return h.Ops.DeleteTask(taskName) }})

			continue
		}
		suspects = append(suspects, fmt.Sprintf("task:%s conservée (action inconnue, ne référence pas le legacy)", name))
	}

	return suspects, nil
}

// --- C : scripts GPO LOCALE curl-ant le legacy ----------------------------------

// scanLocalGpoScripts : fichiers sous GroupPolicy\{User,Machine}\Scripts\ dont
// le CONTENU matche curl + gpo/applications.php|gpo/shortcuts_out.php →
// suppression du fichier + purge de l'entrée scripts.ini correspondante.
// INTERDIT STRUCTUREL : GroupPolicy\DataStore n'est jamais visité (aucun
// chemin construit n'y pointe — cache des GPO de DOMAINE, SE_agent_bootstrap).
func (h *LegacyCleanupHandler) scanLocalGpoScripts(add func(legacyFinding)) error {
	for _, side := range []string{"User", "Machine"} {
		scriptsDir := h.winDir() + `\System32\GroupPolicy\` + side + `\Scripts`
		removedBases := map[string]bool{}
		for _, phase := range []string{"Logon", "Logoff", "Startup", "Shutdown"} {
			dir := scriptsDir + `\` + phase
			entries, err := h.Ops.ListDir(dir)
			if err != nil {
				return fmt.Errorf("scan des scripts GPO locale %s : %w", dir, err)
			}
			for _, entry := range entries {
				if entry.IsDir || entry.IsReparse {
					continue
				}
				path := dir + `\` + entry.Name
				raw, err := h.Ops.ReadFile(path)
				if err != nil {
					return fmt.Errorf("lecture du script GPO locale %s : %w", path, err)
				}
				content, _ := decodeTextAuto(raw)
				if isLegacyCurlScript(content) {
					add(h.fileFinding(path))
					removedBases[strings.ToLower(entry.Name)] = true
				}
			}
		}
		if len(removedBases) == 0 {
			continue
		}

		// Purge de scripts.ini (au niveau du dossier Scripts\) : retire les
		// entrées NCmdLine/NParameters référençant les fichiers supprimés,
		// renumérote ; vide ⇒ suppression du fichier.
		iniPath := scriptsDir + `\scripts.ini`
		info, err := h.Ops.Stat(iniPath)
		if err != nil {
			return fmt.Errorf("inspection de %s : %w", iniPath, err)
		}
		if !info.Exists || info.IsDir {
			continue
		}
		raw, err := h.Ops.ReadFile(iniPath)
		if err != nil {
			return fmt.Errorf("lecture de %s : %w", iniPath, err)
		}
		content, utf16le := decodeTextAuto(raw)
		newContent, changed, empty := purgeScriptsIni(content, removedBases)
		if !changed {
			continue
		}
		path := iniPath
		if empty {
			add(legacyFinding{id: "file:" + path + " (plus aucune entrée)", remove: func() error { return h.Ops.Remove(path) }})
		} else {
			data := encodeText(newContent, utf16le)
			add(legacyFinding{id: "file:" + path + " (purge entrées legacy)", remove: func() error { return h.Ops.WriteFile(path, data) }})
		}
	}

	return nil
}

// isLegacyCurlScript : GARDE de contenu — le script curl-e le canal legacy.
func isLegacyCurlScript(content string) bool {
	lower := strings.ToLower(content)

	return strings.Contains(lower, "curl") &&
		(strings.Contains(lower, "gpo/applications.php") || strings.Contains(lower, "gpo/shortcuts_out.php"))
}

// purgeScriptsIni : retire d'un scripts.ini GPO les paires NCmdLine/NParameters
// dont le CmdLine référence un fichier supprimé (basename, insensible à la
// casse), renumérote les indices restants PAR SECTION (format Windows
// consécutif). empty=true si plus AUCUNE entrée CmdLine ne subsiste.
func purgeScriptsIni(content string, removedBases map[string]bool) (out string, changed bool, empty bool) {
	type iniEntry struct {
		cmdLine, parameters string
		hasParams           bool
	}
	lines := strings.Split(strings.ReplaceAll(content, "\r\n", "\n"), "\n")

	sections := []string{}
	entries := map[string]map[int]*iniEntry{} // section → index → entrée
	for _, line := range lines {
		trimmed := strings.TrimSpace(line)
		if trimmed == "" {
			continue
		}
		if strings.HasPrefix(trimmed, "[") && strings.HasSuffix(trimmed, "]") {
			section := trimmed[1 : len(trimmed)-1]
			if _, ok := entries[section]; !ok {
				sections = append(sections, section)
				entries[section] = map[int]*iniEntry{}
			}

			continue
		}
		if len(sections) == 0 {
			continue // ligne hors section : ignorée (fichier inattendu)
		}
		section := sections[len(sections)-1]
		eq := strings.IndexByte(trimmed, '=')
		if eq < 0 {
			continue
		}
		key, value := trimmed[:eq], trimmed[eq+1:]
		idx, kind := splitIniKey(key)
		if idx < 0 {
			continue
		}
		if entries[section][idx] == nil {
			entries[section][idx] = &iniEntry{}
		}
		switch kind {
		case "cmdline":
			entries[section][idx].cmdLine = value
		case "parameters":
			entries[section][idx].parameters = value
			entries[section][idx].hasParams = true
		}
	}

	var b strings.Builder
	b.WriteString("\r\n") // les scripts.ini GPO commencent par une ligne vide
	remaining := 0
	for _, section := range sections {
		idxs := make([]int, 0, len(entries[section]))
		for idx := range entries[section] {
			idxs = append(idxs, idx)
		}
		sort.Ints(idxs)

		kept := []*iniEntry{}
		for _, idx := range idxs {
			entry := entries[section][idx]
			base := strings.ToLower(strings.TrimSpace(entry.cmdLine))
			if slash := strings.LastIndexAny(base, `\/`); slash >= 0 {
				base = base[slash+1:]
			}
			if removedBases[base] {
				changed = true

				continue
			}
			kept = append(kept, entry)
		}
		if len(kept) == 0 {
			continue
		}
		remaining += len(kept)
		b.WriteString("[" + section + "]\r\n")
		for i, entry := range kept {
			b.WriteString(fmt.Sprintf("%dCmdLine=%s\r\n", i, entry.cmdLine))
			if entry.hasParams {
				b.WriteString(fmt.Sprintf("%dParameters=%s\r\n", i, entry.parameters))
			}
		}
	}

	return b.String(), changed, remaining == 0
}

// splitIniKey : décompose `0CmdLine` → (0, "cmdline"). Clé inattendue ⇒ (-1, "").
func splitIniKey(key string) (int, string) {
	i := 0
	for i < len(key) && key[i] >= '0' && key[i] <= '9' {
		i++
	}
	if i == 0 {
		return -1, ""
	}
	idx := 0
	for _, c := range key[:i] {
		idx = idx*10 + int(c-'0')
	}
	switch strings.ToLower(key[i:]) {
	case "cmdline":
		return idx, "cmdline"
	case "parameters":
		return idx, "parameters"
	default:
		return -1, ""
	}
}

// --- D : déclencheurs et résidus WPKG legacy + canal install --------------------

func (h *LegacyCleanupHandler) scanWpkgAndInstall(add func(legacyFinding)) error {
	win := h.winDir()

	// Fichiers plats exclusivement legacy. `%SystemRoot%\wpkg.xml` (base WPKG
	// locale, canal natif) est HORS catalogue — INTERDIT (piège #4).
	for _, name := range []string{"wpkg-client.vbs", "wpkg-gpo.txt", "action.cmd", "autorun.cmd", "gpo.txt"} {
		path := win + `\` + name
		info, err := h.Ops.Stat(path)
		if err != nil {
			return fmt.Errorf("inspection de %s : %w", path, err)
		}
		if info.Exists && !info.IsDir {
			add(h.fileFinding(path))
		}
	}

	// Jonctions %WinDir%\install / %WinDir%\rapports — UNIQUEMENT si reparse
	// point (lien SMB legacy pendouillant). Un VRAI dossier `install` =
	// provisionné par le module natif 27.20 : INTOUCHABLE (piège #3, détection
	// iso provision_windows.go). Remove ne supprime que le lien.
	for _, name := range []string{"install", "rapports"} {
		path := win + `\` + name
		info, err := h.Ops.Stat(path)
		if err != nil {
			return fmt.Errorf("inspection de %s : %w", path, err)
		}
		if info.Exists && info.IsReparse {
			add(h.fileFinding(path))
		}
	}

	// Staging install legacy : RemoveAll AUTORISÉ sur C:\Netinst SEULEMENT
	// (exclusivement legacy — piège #4).
	netinst := h.netinstDir()
	info, err := h.Ops.Stat(netinst)
	if err != nil {
		return fmt.Errorf("inspection de %s : %w", netinst, err)
	}
	if info.Exists {
		add(legacyFinding{id: "dir:" + netinst, remove: func() error { return h.Ops.RemoveAll(netinst) }})
	}

	// %WINDIR%\Web\SE4 — forme CONSERVATRICE (inventaire E, review 38.3 #2) :
	// on supprime le fichier NOMMÉ SetWallpaper.ps1, puis le dossier SEULEMENT
	// s'il est vide. JAMAIS de RemoveAll sous %WINDIR%\Web : un contenu
	// inattendu y est laissé intact (et visible au drift suivant du .ps1 s'il
	// revient, jamais collatéral).
	se4Web := win + `\Web\SE4`
	wallpaper := se4Web + `\SetWallpaper.ps1`
	winfo, err := h.Ops.Stat(wallpaper)
	if err != nil {
		return fmt.Errorf("inspection de %s : %w", wallpaper, err)
	}
	if winfo.Exists {
		add(legacyFinding{id: "file:" + wallpaper, remove: func() error {
			if err := h.Ops.Remove(wallpaper); err != nil {
				return err
			}
			// rmdir best-effort : Remove ne supprime qu'un dossier VIDE.
			entries, err := h.Ops.ListDir(se4Web)
			if err == nil && len(entries) == 0 {
				return h.Ops.Remove(se4Web)
			}

			return nil
		}})
	} else {
		dinfo, err := h.Ops.Stat(se4Web)
		if err != nil {
			return fmt.Errorf("inspection de %s : %w", se4Web, err)
		}
		if dinfo.Exists && dinfo.IsDir {
			entries, err := h.Ops.ListDir(se4Web)
			if err != nil {
				return fmt.Errorf("énumération de %s : %w", se4Web, err)
			}
			if len(entries) == 0 {
				add(legacyFinding{id: "dir:" + se4Web, remove: func() error { return h.Ops.Remove(se4Web) }})
			}
		}
	}

	return nil
}

// --- D (registre) : clé Run `action` + autologon résiduel se4install -----------

const (
	legacyRunPath      = `SOFTWARE\Microsoft\Windows\CurrentVersion\Run`
	legacyWinlogonPath = `SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon`
)

// legacyWinlogonValues : valeurs d'autologon purgées quand
// DefaultUserName == se4install. Ordre : le mot de passe en CLAIR d'abord
// (hygiène), DefaultUserName en DERNIER (un échec partiel laisse la garde
// armée → la passe suivante retente).
var legacyWinlogonValues = []string{
	"DefaultPassword", "AutoAdminLogon", "AutoLogonCount", "DefaultDomainName", "DefaultUserName",
}

func (h *LegacyCleanupHandler) scanRegistryHooks(add func(legacyFinding)) error {
	// Relance autorun legacy (action.php:88).
	_, present, err := h.Ops.RegistryRead("HKLM", legacyRunPath, "action")
	if err != nil {
		return fmt.Errorf("lecture de HKLM\\%s\\action : %w", legacyRunPath, err)
	}
	if present {
		add(legacyFinding{
			id:     `reg:HKLM\` + legacyRunPath + `\action`,
			remove: func() error { return h.Ops.RegistryDelete("HKLM", legacyRunPath, "action") },
		})
	}

	// Autologon résiduel se4install — GARDE : SI ET SEULEMENT SI
	// DefaultUserName == se4install (mot de passe en CLAIR résiduel = hygiène
	// sécurité ; la garde interdit de casser un autologon légitime).
	value, present, err := h.Ops.RegistryRead("HKLM", legacyWinlogonPath, "DefaultUserName")
	if err != nil {
		return fmt.Errorf("lecture de HKLM\\%s\\DefaultUserName : %w", legacyWinlogonPath, err)
	}
	if present && strings.EqualFold(strings.TrimSpace(value.Str), "se4install") {
		add(legacyFinding{
			id: `reg:HKLM\` + legacyWinlogonPath + ` (autologon se4install)`,
			remove: func() error {
				var firstErr error
				for _, name := range legacyWinlogonValues {
					if err := h.Ops.RegistryDelete("HKLM", legacyWinlogonPath, name); err != nil && firstErr == nil {
						firstErr = fmt.Errorf("suppression de %s : %w", name, err)
					}
				}

				return firstErr
			},
		})
	}

	return nil
}

// --- E : helpers %ProgramFiles%\SambaEdu obsolètes ------------------------------

// scanHelpers : LISTE BLANCHE NOMMÉE de fichiers à la racine du dossier
// SambaEdu (variantes de casse SambaEdu/Sambaedu — dédupliquées par id
// minuscule côté scan, le FS Windows étant insensible à la casse). INTERDIT
// ABSOLU structurel : `Agent\**` et tout sous-répertoire sont INEXPRIMABLES
// (aucun glob, aucune récursion — que des noms de fichiers nommés).
func (h *LegacyCleanupHandler) scanHelpers(add func(legacyFinding)) error {
	for _, pf := range h.programFiles() {
		for _, dirName := range []string{"SambaEdu", "Sambaedu"} {
			for _, helper := range legacyHelperWhitelist {
				path := pf + `\` + dirName + `\` + helper
				info, err := h.Ops.Stat(path)
				if err != nil {
					return fmt.Errorf("inspection du helper %s : %w", path, err)
				}
				if info.Exists && !info.IsDir {
					add(h.fileFinding(path))
				}
			}
		}
	}

	return nil
}

// --- F : paires Mozilla forcées (Q5-a VANILLA) ----------------------------------

// mozillaAppDirs : chemins relatifs (depuis le profil Windows) des dossiers
// Mozilla porteurs de la paire profiles.ini/installs.ini forcée.
var mozillaAppDirs = []string{
	`AppData\Roaming\Mozilla\Firefox`,
	`AppData\Roaming\Thunderbird`,
}

// scanMozilla : pour chaque profil Windows RÉEL, si profiles.ini référence
// `sambaedu.default` → la PAIRE profiles.ini + installs.ini est supprimée.
// JAMAIS le dossier `sambaedu.default` (données utilisateur), JAMAIS un
// profiles.ini sain (profil géré par l'utilisateur), AUCUN profil forcé posé
// (piège #5 / Q5-a).
func (h *LegacyCleanupHandler) scanMozilla(add func(legacyFinding)) error {
	profiles, err := h.realProfiles()
	if err != nil {
		return err
	}
	for _, profile := range profiles {
		for _, appDir := range mozillaAppDirs {
			base := h.usersDir() + `\` + profile + `\` + appDir
			profilesIni := base + `\profiles.ini`
			info, err := h.Ops.Stat(profilesIni)
			if err != nil {
				return fmt.Errorf("inspection de %s : %w", profilesIni, err)
			}
			if !info.Exists || info.IsDir {
				continue
			}
			raw, err := h.Ops.ReadFile(profilesIni)
			if err != nil {
				return fmt.Errorf("lecture de %s : %w", profilesIni, err)
			}
			content, _ := decodeTextAuto(raw)
			if !referencesSambaeduProfile(content) {
				continue // profiles.ini sain (géré par l'utilisateur) : INTOUCHÉ.
			}
			iniPath := profilesIni
			installsPath := base + `\installs.ini`
			add(legacyFinding{
				id: "mozilla:" + iniPath,
				remove: func() error {
					// La PAIRE, rien d'autre : profiles.ini PUIS installs.ini
					// (Remove idempotent — installs.ini peut être absent).
					if err := h.Ops.Remove(iniPath); err != nil {
						return err
					}

					return h.Ops.Remove(installsPath)
				},
			})
		}
	}

	return nil
}

// referencesSambaeduProfile : GARDE — le profiles.ini référence-t-il le profil
// forcé legacy ? Clés `Default`/`Path` dont la VALEUR est `sambaedu.default`
// (nue ou en fin de chemin), insensible à la casse et aux espaces.
//
// Format réel VÉRIFIÉ à la source (review 38.3 #1) : les fragments paquet
// `/usr/share/sambaedu/applications/{firefox,thunderbird}/logon.windows`
// écrivent la forme NUE (`Default=sambaedu.default` / `Path=sambaedu.default`,
// hash install constaté 308046B0AF4A39CB). Le match par suffixe couvre en
// plus les variantes historiques (`Path=Profiles/sambaedu.default` — forme
// Linux du fragment — ou séparateur `\`), sans jamais matcher un profil
// utilisateur légitime (frontière `/` exigée).
func referencesSambaeduProfile(content string) bool {
	for _, line := range strings.Split(strings.ReplaceAll(content, "\r\n", "\n"), "\n") {
		key, value, found := strings.Cut(strings.TrimSpace(line), "=")
		if !found {
			continue
		}
		k := strings.ToLower(strings.TrimSpace(key))
		if k != "default" && k != "path" {
			continue
		}
		v := strings.ReplaceAll(strings.ToLower(strings.TrimSpace(value)), `\`, "/")
		if v == "sambaedu.default" || strings.HasSuffix(v, "/sambaedu.default") {
			return true
		}
	}

	return false
}

// realProfiles : les profils Windows RÉELS sous C:\Users — dossiers réels
// seulement (reparse points sautés), hors répertoires spéciaux (Public,
// Default, Default User, All Users). Ordre trié (détails stables).
func (h *LegacyCleanupHandler) realProfiles() ([]string, error) {
	entries, err := h.Ops.ListDir(h.usersDir())
	if err != nil {
		return nil, fmt.Errorf("énumération des profils %s : %w", h.usersDir(), err)
	}
	profiles := []string{}
	for _, entry := range entries {
		if !entry.IsDir || entry.IsReparse {
			continue
		}
		if mozillaSkipProfiles[strings.ToLower(entry.Name)] {
			continue
		}
		profiles = append(profiles, entry.Name)
	}
	sort.Strings(profiles)

	return profiles, nil
}

// --- Encodage texte (scripts.ini GPO = UTF-16LE possible) -----------------------

// decodeTextAuto : décode un contenu texte — UTF-16LE si BOM FF FE (format
// usuel des scripts.ini GPO), UTF-8/ANSI sinon.
func decodeTextAuto(raw []byte) (text string, utf16le bool) {
	if len(raw) >= 2 && raw[0] == 0xFF && raw[1] == 0xFE {
		u16 := make([]uint16, 0, (len(raw)-2)/2)
		for i := 2; i+1 < len(raw); i += 2 {
			u16 = append(u16, uint16(raw[i])|uint16(raw[i+1])<<8)
		}

		return string(utf16.Decode(u16)), true
	}

	return string(raw), false
}

// encodeText : ré-encode dans le format d'origine (UTF-16LE + BOM ou brut).
func encodeText(text string, utf16le bool) []byte {
	if !utf16le {
		return []byte(text)
	}
	u16 := utf16.Encode([]rune(text))
	out := make([]byte, 2, 2+len(u16)*2)
	out[0], out[1] = 0xFF, 0xFE
	for _, u := range u16 {
		out = append(out, byte(u), byte(u>>8))
	}

	return out
}
