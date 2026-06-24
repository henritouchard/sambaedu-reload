package main

import (
	"encoding/json"
	"encoding/xml"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"

	"sambaedu/agent/provision"
	"sambaedu/agent/shared"
)

// Câblage Windows du handler `applications` (Story 27.5) — DÉCLENCHE le moteur
// WPKG local à la place de la GPO `se4_wpkg`, en SERVICE SYSTEM (portée MACHINE,
// leçon 🔴 27.4 #1 : WPKG installe machine-wide).
//
// « UN TUYAU, DEUX OUTILS » : l'agent donne l'URL du bundle au bootstrap +
// DÉPOSE le profil par-hôte localement (D9) + DÉCLENCHE `wpkg-client.vbs` ; c'est
// le CLIENT qui télécharge le bundle depuis Apache (l'agent ne télécharge pas :
// ni blocage, ni goroutine, zéro charge Laravel — D7). WPKG reste le moteur
// déclaratif (résolution de dépendances, `<check>/<install>/<upgrade>`) — non
// absorbé. Le SHELL-OUT vers le moteur WPKG est la SEULE exception justifiée à
// « API native, zéro shell-out » : déclencher un moteur externe ne s'écrit pas en
// Win32.
//
// AUCUNE dépendance AD (NFR7, vérifié) : `wpkg-client.vbs`/`wpkg-se4.js` ne
// lisent l'AD que via `getHostGroups()` (`WinNT://…`) wrappé try/catch JAMAIS
// utilisé (matching par NOM). L'identité du poste = locale
// (`WScript.Network.ComputerName`). Le seul prérequis posé ici est la variable
// machine `%SE4FS%` et le profil par-hôte déposé localement.

// applicationsOps : impl ApplicationsOps de production (Windows). Le bundle WPKG
// (scripts + catalogue pré-substitué) est servi STATIQUEMENT par Apache au
// sous-chemin {@link shared.WpkgBundlePath} de `server_url` ; `wpkg.cmd` (patché)
// le télécharge. L'agent ne fait que déposer le profil par-hôte + déclencher.
type applicationsOps struct {
	log *shared.Logger

	// store : accès à la config locale (`server_url`) — l'URL du bundle est
	// dérivée à CHAUD au déclenchement (server_url + WpkgBundlePath), donnée au
	// bootstrap via l'environnement du process déclenché. L'agent ne télécharge
	// PAS (D7). nil = bundle URL vide (le client retombera sur son défaut).
	store *shared.Store
}

// bundleURL : URL du sous-dossier Apache servant le bundle WPKG pré-substitué
// (D10). Dérivée de `server_url` (config locale) + {@link shared.WpkgBundlePath}.
// Donnée au bootstrap — l'agent ne télécharge PAS. Vide si la config est
// illisible (best-effort : le client a son propre défaut).
func (o *applicationsOps) bundleURL() string {
	if o.store == nil {
		return ""
	}
	cfg, err := o.store.ReadConfig()
	if err != nil {
		return ""
	}

	return cfg.ServerURL + shared.WpkgBundlePath
}

// toolsURL : base URL de l'alias Apache servant les OUTILS PARTAGÉS WPKG + leur
// `manifest.json` (Story 27.20). Dérivée de `server_url` + {@link
// shared.WpkgToolsPath} — EXACTEMENT comme bundleURL dérive WpkgBundlePath.
// Contrairement au bundle, c'est l'AGENT qui pilote ce provisioning (fetch du
// manifeste + provision.Reconcile, AVANT de déclencher WPKG). Vide si la config
// est illisible (le staging est alors sauté en fail-soft).
func (o *applicationsOps) toolsURL() string {
	if o.store == nil {
		return ""
	}
	cfg, err := o.store.ReadConfig()
	if err != nil {
		return ""
	}

	return cfg.ServerURL + shared.WpkgToolsPath
}

// toolManifestEntry : une entrée du `manifest.json` servi sous /wpkg/tools
// (généré côté serveur par `ensure_wpkg_tools`). Schéma figé, aligné sur
// provision.Resource (l'agent compose l'URL = toolsURL + "/" + relpath).
type toolManifestEntry struct {
	ID      string `json:"id"`
	Kind    string `json:"kind"`
	RelPath string `json:"relpath"`
	SHA256  string `json:"sha256"`
}

// stageSharedTools dépose/rafraîchit les outils partagés WPKG sous
// `%WinDir%\install\wpkg\tools\` AVANT de déclencher le moteur (les recettes les
// invoquent via `%Z%\wpkg\tools\…`). FAIL-SOFT par contrat : un manifeste
// inaccessible ou un outil en échec n'empêche JAMAIS le déclenchement WPKG (les
// recettes qui en dépendent échoueront côté poste, diagnosticables dans wpkg.log
// — le déclenchement global reste possible pour les autres). Idempotence VRAIE
// par hash : un outil déjà présent au bon sha256 est SKIPPÉ (zéro réseau).
func (o *applicationsOps) stageSharedTools() {
	base := o.toolsURL()
	if base == "" {
		o.logf("⚠ URL des outils WPKG vide (config agent illisible / store nil) — staging des outils partagés sauté (fail-soft)")

		return
	}

	manifestURL := base + "/manifest.json"
	entries, err := o.fetchToolManifest(manifestURL)
	if err != nil {
		o.logf("⚠ manifeste des outils WPKG inaccessible (%s) : %v — staging sauté (fail-soft, les recettes utilisant %%Z%%\\wpkg\\tools échoueront côté poste)", manifestURL, err)

		return
	}
	if len(entries) == 0 {
		o.logf("Manifeste des outils WPKG vide — aucun outil à déposer.")

		return
	}

	resources := make([]provision.Resource, 0, len(entries))
	for _, e := range entries {
		resources = append(resources, provision.Resource{
			ID:         e.ID,
			Kind:       e.Kind,
			RelPath:    e.RelPath,
			URL:        base + "/" + strings.TrimPrefix(e.RelPath, "/"),
			SHA256:     e.SHA256,
			Executable: strings.HasSuffix(strings.ToLower(e.RelPath), ".exe"),
		})
	}

	resolver := provision.NewWindowsResolver(o.logf)
	outcomes := provision.Reconcile(resources, resolver)

	var applied, skipped, failed int
	for _, oc := range outcomes {
		switch oc.Status {
		case provision.StatusApplied:
			applied++
		case provision.StatusSkipped:
			skipped++
		case provision.StatusFailed:
			failed++
			o.logf("⚠ outil WPKG non déposé : %s — %v (fail-soft)", oc.ResourceID, oc.Err)
		}
	}
	o.logf("Staging outils WPKG partagés : %d déposé(s), %d à jour, %d en échec (sur %d).", applied, skipped, failed, len(outcomes))
}

// fetchToolManifest récupère et décode le `manifest.json` des outils partagés.
// GET statique simple (hors PHP-FPM, comme le bundle), timeout porté.
func (o *applicationsOps) fetchToolManifest(url string) ([]toolManifestEntry, error) {
	client := &http.Client{Timeout: 30 * time.Second}
	resp, err := client.Get(url) //nolint:noctx // GET statique simple, timeout client.
	if err != nil {
		return nil, err
	}
	defer func() { _ = resp.Body.Close() }()
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("statut HTTP %d", resp.StatusCode)
	}

	raw, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20)) // garde-fou 1 Mio.
	if err != nil {
		return nil, err
	}

	var entries []toolManifestEntry
	if err := json.Unmarshal(raw, &entries); err != nil {
		return nil, fmt.Errorf("manifeste JSON illisible : %w", err)
	}

	return entries, nil
}

// wpkgDir : répertoire local où l'agent dépose le profil par-hôte
// (`profiles.xml`/`hosts.xml`) — `%ProgramData%\SambaEdu\wpkg` (frère de
// l'Agent, ACL SYSTEM héritée). `wpkg-se4.js` patché lit ces fichiers locaux.
func (o *applicationsOps) wpkgDir() string {
	pd := os.Getenv("ProgramData")
	if pd == "" {
		pd = `C:\ProgramData`
	}

	return filepath.Join(pd, "SambaEdu", "wpkg")
}

// resolveSe4fs : nom du serveur de fichiers, iso handler_printers_windows
// (variable machine `%SE4FS%`, repli LOGONSERVER). JAMAIS l'AD.
func (o *applicationsOps) resolveSe4fs() string {
	se4fs := os.Getenv("SE4FS")
	if se4fs == "" {
		se4fs = strings.TrimLeft(os.Getenv("LOGONSERVER"), `\`)
	}
	if se4fs == "" {
		se4fs = "se4fs"
	}

	return se4fs
}

// ListInstalled lit la base d'état locale de WPKG (`wpkg.xml`) — source de
// vérité PAR PAQUET (D5). Spike : on tente les DEUX chemins communs (le
// `settings_file_path = null` de `wpkg-se4.js` fait chercher le fichier dans le
// dossier système, mais l'emplacement varie) :
//
//	%SystemDrive%\wpkg.xml   (racine du lecteur système, défaut historique)
//	%PROGRAMDATA%\wpkg.xml   (emplacement moderne par-machine)
//
// Le PREMIER présent fait foi. ABSENT des deux → ([], nil) : aucun run WPKG
// encore (apply déclenchera). Présent mais ILLISIBLE/corrompu → erreur (le moteur
// rend error). On ne réimplémente PAS `<check>` : on LIT ce que WPKG a écrit.
func (o *applicationsOps) ListInstalled() ([]string, error) {
	for _, path := range o.wpkgXMLCandidatePaths() {
		raw, err := os.ReadFile(path)
		if err != nil {
			if os.IsNotExist(err) {
				continue
			}

			return nil, fmt.Errorf("lecture de %s : %w", path, err)
		}
		ids, err := parseWpkgXMLInstalled(raw)
		if err != nil {
			return nil, fmt.Errorf("wpkg.xml illisible (%s) : %w", path, err)
		}

		return ids, nil
	}

	// Aucun wpkg.xml : jamais de run WPKG sur ce poste encore (rien d'installé).
	return []string{}, nil
}

// DeployedProfileAppIds lit le `profiles.xml` par-hôte DÉJÀ DÉPOSÉ par l'agent
// (`%ProgramData%\SambaEdu\wpkg\profiles.xml`, écrit par `dropHostProfile` au
// dernier `Apply`) et renvoie l'ensemble des `package-id` qu'il référence — « ce
// que l'agent a demandé à WPKG de gérer la dernière fois ». ABSENT (jamais déposé)
// → ([], nil) : rien géré encore (toute cible désirée constitue un changement →
// Apply déposera). Illisible/corrompu → err (le moteur rend error pour le type).
// Schéma iso `dropHostProfile`/`ProfilesXmlController` : `<profiles><profile>
// <package package-id=…/></profile></profiles>`.
func (o *applicationsOps) DeployedProfileAppIds() ([]string, error) {
	path := filepath.Join(o.wpkgDir(), "profiles.xml")
	raw, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			// Jamais déposé : aucun périmètre géré encore.
			return []string{}, nil
		}

		return nil, fmt.Errorf("lecture de %s : %w", path, err)
	}

	var doc xmlProfiles
	if err := xml.Unmarshal(raw, &doc); err != nil {
		return nil, fmt.Errorf("profiles.xml déposé illisible (%s) : %w", path, err)
	}

	var ids []string
	for _, profile := range doc.Profiles {
		for _, pkg := range profile.Packages {
			if id := strings.TrimSpace(pkg.PackageID); id != "" {
				ids = append(ids, id)
			}
		}
	}

	return ids, nil
}

// wpkgXMLCandidatePaths : chemins candidats de `wpkg.xml` (D5), dans l'ordre
// de préférence. Chemin réel (prioritaire) : `%SystemRoot%\system32\wpkg.xml`
// — `wpkg-se4.js::getSettingsPath()` résout `fso.GetSpecialFolder(1)` =
// System32 quand `settings_file_path = null` (défaut, ligne 531 du script).
// Les deux autres restent en fallback (historique / configs alternatives).
func (o *applicationsOps) wpkgXMLCandidatePaths() []string {
	var paths []string

	// Priorité 1 : System32 (chemin réel de wpkg-se4.js).
	systemRoot := os.Getenv("SystemRoot")
	if systemRoot == "" {
		systemRoot = `C:\Windows`
	}
	paths = append(paths, filepath.Join(systemRoot, "system32", "wpkg.xml"))

	// Fallback 2 : racine du lecteur système (défaut historique).
	systemDrive := os.Getenv("SystemDrive")
	if systemDrive == "" {
		systemDrive = "C:"
	}
	paths = append(paths, filepath.Join(systemDrive+`\`, "wpkg.xml"))

	// Fallback 3 : ProgramData (emplacement moderne par-machine).
	if pd := os.Getenv("ProgramData"); pd != "" {
		paths = append(paths, filepath.Join(pd, "wpkg.xml"))
	} else {
		paths = append(paths, `C:\ProgramData\wpkg.xml`)
	}

	return paths
}

// TriggerWpkg dépose le profil par-hôte (D9) puis DÉCLENCHE le moteur WPKG local.
// L'agent ne télécharge PAS le bundle : il pose `profiles.xml`/`hosts.xml` en
// local (générés depuis l'ensemble cible — il a déjà la liste) et lance
// `cscript //B //NoLogo wpkg-client.vbs /NOTempo` ; le client télécharge le
// bundle depuis Apache (`bundleURL`) et lit les fichiers locaux. Après le run, on
// relit `wpkg.xml` (état par paquet, level-triggered).
func (o *applicationsOps) TriggerWpkg(specs []shared.ApplicationsSpec) (shared.WpkgResult, error) {
	hostname := os.Getenv("COMPUTERNAME")
	if hostname == "" {
		if hn, err := os.Hostname(); err == nil {
			hostname = hn
		}
	}
	// Un hostname vide produirait profiles.xml/hosts.xml avec id="" non matchable
	// par wpkg-se4.js → jamais un faux compliant (leçon 🟠 27.4 #7).
	if hostname == "" {
		return shared.WpkgResult{}, fmt.Errorf("hostname indéterminable (COMPUTERNAME absent + os.Hostname() en échec) — le profil wpkg.xml ne serait pas matché par wpkg-se4.js")
	}

	// 1. Déposer le profil par-hôte localement (D9) — zéro endpoint Laravel.
	if err := o.dropHostProfile(hostname, specs); err != nil {
		return shared.WpkgResult{}, fmt.Errorf("dépôt du profil par-hôte : %w", err)
	}

	// 2. Localiser l'orchestrateur `wpkg-client.vbs` (copié en %WinDir% par
	//    `wpkg.cmd` à l'install — dépendance de déploiement documentée). Sinon, on
	//    ne peut pas déclencher → error (jamais un faux compliant).
	vbs, err := o.locateClientVbs()
	if err != nil {
		return shared.WpkgResult{}, err
	}

	// 2bis. Stager les OUTILS PARTAGÉS WPKG (Story 27.20) AVANT le run : l'agent
	//    fetch le manifeste (/wpkg/tools/manifest.json), réconcilie par hash et
	//    dépose les outils sous %WinDir%\install\wpkg\tools\ (= %Z%\wpkg\tools\).
	//    FAIL-SOFT : un manifeste/outil en échec ne bloque JAMAIS le déclenchement
	//    (les recettes qui en dépendent échoueront côté poste, pas le run global).
	o.stageSharedTools()

	// 3. Garantir %SE4FS% (variable machine) pour le process déclenché + donner
	//    l'URL du bundle au bootstrap (le client télécharge depuis Apache).
	bundleURL := o.bundleURL()
	if bundleURL == "" {
		// Config illisible ou store nil : le client retombera sur son défaut câblé,
		// qui peut diverger de config('agent.wpkg_bundle_url') côté serveur.
		o.logf("⚠ bundle URL vide (config agent illisible / store nil) — wpkg.cmd retombera sur son défaut câblé")
	}
	cmd := exec.Command(o.cscriptPath(), "//B", "//NoLogo", vbs, "/NOTempo")
	cmd.Env = append(os.Environ(),
		"SE4FS="+o.resolveSe4fs(),
		"SE4_WPKG_BUNDLE_URL="+bundleURL,
		"SE4_WPKG_LOCAL_PROFILE_DIR="+o.wpkgDir(),
	)

	// 4. Déclencher (capture du code de sortie = signal de run global). Le client
	//    télécharge ET installe ; l'agent ne bloque que le temps du run (le
	//    moteur WPKG est in-process côté cscript, pas une goroutine agent).
	out, runErr := cmd.CombinedOutput()
	if runErr != nil {
		// Code de sortie d'échec global du moteur WPKG → error (les autres types
		// convergent ; jamais un faux compliant, leçon 🟠 27.4 #7).
		o.logf("Déclenchement WPKG (%s) en échec : %v — sortie : %s", vbs, runErr, truncate(string(out), 500))

		return shared.WpkgResult{}, fmt.Errorf("cscript wpkg-client.vbs : %w", runErr)
	}
	o.logf("WPKG déclenché (%s) : run terminé.", vbs)

	// 5. Relire l'état par paquet APRÈS le run (level-triggered) — `wpkg.xml`.
	installed, err := o.ListInstalled()
	if err != nil {
		return shared.WpkgResult{}, fmt.Errorf("relecture de wpkg.xml après run : %w", err)
	}

	return shared.WpkgResult{Triggered: true, Installed: installed}, nil
}

// dropHostProfile écrit `profiles.xml` + `hosts.xml` dans `%ProgramData%\SambaEdu\wpkg`
// (D9) depuis l'ensemble cible. Le schéma matche EXACTEMENT le serving SE5
// (ProfilesXmlController / HostsXmlController) : `<profiles><profile id=HOST>
// <package package-id=…/></profile></profiles>` et `<wpkg><host name=HOST
// profile-id=HOST/></wpkg>`. Écriture atomique (tmp + rename) — le client ne lit
// jamais un fichier à demi écrit.
func (o *applicationsOps) dropHostProfile(hostname string, specs []shared.ApplicationsSpec) error {
	dir := o.wpkgDir()
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return fmt.Errorf("création de %s : %w", dir, err)
	}
	// ACL SYSTEM+Administrators sur le dossier wpkg (cohérence avec les autres
	// fichiers agent — profiles.xml pilote les installs machine-wide via WPKG).
	if o.store != nil {
		if err := o.store.ApplyACL(dir); err != nil {
			// Non bloquant : l'héritage ProgramData protège en pratique, mais
			// loguer pour audit (asymétrie avec les autres fichiers agent).
			o.logf("ACL dossier wpkg : %v (non bloquant)", err)
		}
	}

	profilesXML, err := buildProfilesXML(hostname, specs)
	if err != nil {
		return err
	}
	hostsXML, err := buildHostsXML(hostname)
	if err != nil {
		return err
	}

	profilesPath := filepath.Join(dir, "profiles.xml")
	hostsPath := filepath.Join(dir, "hosts.xml")

	if err := writeFileAtomicLocal(profilesPath, profilesXML); err != nil {
		return err
	}
	if o.store != nil {
		if err := o.store.ApplyACL(profilesPath); err != nil {
			o.logf("ACL profiles.xml : %v", err)
		}
	}

	if err := writeFileAtomicLocal(hostsPath, hostsXML); err != nil {
		return err
	}
	if o.store != nil {
		if err := o.store.ApplyACL(hostsPath); err != nil {
			o.logf("ACL hosts.xml : %v", err)
		}
	}

	return nil
}

// --- Sérialisation XML du profil par-hôte (schéma iso serving SE5) -----------

type xmlProfiles struct {
	XMLName  xml.Name     `xml:"profiles"`
	Profiles []xmlProfile `xml:"profile"`
}

type xmlProfile struct {
	ID       string      `xml:"id,attr"`
	Packages []xmlPkgRef `xml:"package"`
}

type xmlPkgRef struct {
	PackageID string `xml:"package-id,attr"`
}

type xmlHostsRoot struct {
	XMLName xml.Name  `xml:"wpkg"`
	Hosts   []xmlHost `xml:"host"`
}

type xmlHost struct {
	Name      string `xml:"name,attr"`
	ProfileID string `xml:"profile-id,attr"`
}

func buildProfilesXML(hostname string, specs []shared.ApplicationsSpec) ([]byte, error) {
	pkgs := make([]xmlPkgRef, 0, len(specs))
	for _, spec := range specs {
		pkgs = append(pkgs, xmlPkgRef{PackageID: spec.AppId})
	}
	doc := xmlProfiles{Profiles: []xmlProfile{{ID: hostname, Packages: pkgs}}}

	return marshalXMLDoc(doc)
}

func buildHostsXML(hostname string) ([]byte, error) {
	doc := xmlHostsRoot{Hosts: []xmlHost{{Name: hostname, ProfileID: hostname}}}

	return marshalXMLDoc(doc)
}

func marshalXMLDoc(v any) ([]byte, error) {
	body, err := xml.MarshalIndent(v, "", "  ")
	if err != nil {
		return nil, fmt.Errorf("sérialisation XML : %w", err)
	}

	return append([]byte(xml.Header), append(body, '\n')...), nil
}

// --- Lecture de wpkg.xml (état installé par paquet) ---------------------------

// parseWpkgXMLInstalled extrait l'ensemble des `package-id` installés de la base
// d'état locale de WPKG. WPKG stocke chaque paquet installé en `<package id="…"
// …/>` (namespace `http://www.wpkg.org/settings`) ; on lit l'attribut `id` de
// TOUT élément local-name `package` (tolérant au namespace/à la profondeur).
func parseWpkgXMLInstalled(raw []byte) ([]string, error) {
	dec := xml.NewDecoder(strings.NewReader(string(raw)))
	var ids []string
	for {
		tok, err := dec.Token()
		if err != nil {
			if errors.Is(err, io.EOF) {
				break
			}

			return nil, err
		}
		start, ok := tok.(xml.StartElement)
		if !ok {
			continue
		}
		if start.Name.Local != "package" {
			continue
		}
		for _, attr := range start.Attr {
			// `id` (clé locale) — le package-id installé. WPKG met aussi
			// `name`/`revision` ; seul `id` matche le package-id du profil.
			if attr.Name.Local == "id" && attr.Value != "" {
				ids = append(ids, attr.Value)
			}
		}
	}

	return ids, nil
}

// --- Helpers ------------------------------------------------------------------

// writeFileAtomicLocal : écriture atomique locale (tmp + rename) — le client ne
// lit jamais un fichier à demi écrit. Nettoie le tmp sur tout échec.
func writeFileAtomicLocal(path string, data []byte) error {
	tmp := fmt.Sprintf("%s.%d.tmp", path, os.Getpid())
	renamed := false
	defer func() {
		if !renamed {
			_ = os.Remove(tmp)
		}
	}()
	if err := os.WriteFile(tmp, data, 0o644); err != nil {
		return fmt.Errorf("écriture de %s : %w", tmp, err)
	}
	if err := os.Rename(tmp, path); err != nil {
		return fmt.Errorf("rename %s → %s : %w", tmp, path, err)
	}
	renamed = true

	return nil
}

// locateClientVbs : localise `wpkg-client.vbs` (copié en `%WinDir%` par
// `wpkg.cmd` à l'install). ABSENT = error (poste migré non passé par le robocopy
// WinPE — dépendance de déploiement documentée) ; jamais un faux compliant.
func (o *applicationsOps) locateClientVbs() (string, error) {
	winDir := os.Getenv("WinDir")
	if winDir == "" {
		winDir = `C:\Windows`
	}
	vbs := filepath.Join(winDir, "wpkg-client.vbs")
	if _, err := os.Stat(vbs); err != nil {
		return "", fmt.Errorf("wpkg-client.vbs introuvable (%s) — poste non provisionné (bundle WPKG absent) : %w", vbs, err)
	}

	return vbs, nil
}

// cscriptPath : chemin de cscript.exe (System32).
func (o *applicationsOps) cscriptPath() string {
	winDir := os.Getenv("WinDir")
	if winDir == "" {
		winDir = `C:\Windows`
	}

	return filepath.Join(winDir, "system32", "cscript.exe")
}

func (o *applicationsOps) logf(format string, args ...any) {
	if o.log != nil {
		o.log.Infof(format, args...)
	}
}

// truncate coupe s à max runes (pas octets) pour éviter de couper au milieu
// d'un caractère multi-octets UTF-8.
func truncate(s string, max int) string {
	runes := []rune(s)
	if len(runes) <= max {
		return s
	}

	return string(runes[:max])
}
