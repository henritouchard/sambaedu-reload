package shared

import (
	"fmt"
	"sort"
	"strings"
)

// Handler `app_profile` (aggregate / scope SESSION) — Story 36.5, contrat §7.11.
// SIXIÈME mécanisme HORS-REGISTRE, appliqué par le COMPAGNON (un profil
// applicatif est une donnée d'UTILISATEUR, pas de machine). Logique PURE,
// OS-agnostique (les ops fichier / substitution de tokens sont injectées via
// AppProfileOps) → testée sur l'hôte ; agent/windows n'apporte que l'impl Win32
// (écriture des ini, I/O).
//
// SPLIT SYSTEM-lien / COMPAGNON-reste (amendement final 36.5, Henri 2026-07-21).
// La pose du lien de dossier vers UNC exige `SeCreateSymbolicLinkPrivilege`,
// qu'AUCUN canal SE5 ne peut accorder à l'utilisateur — mais que le service
// LocalSystem possède nativement. Sur le modèle EXACT de l'overlay, c'est donc le
// service SYSTEM qui pose / répare le LIEN au logon (app_profile_logon.go +
// app_profile_logon_windows.go). LE COMPAGNON, ICI, garde TOUT LE RESTE : dossier
// serveur, marqueur `.se-profile-version`, user.js, paire d'ini — et il NE CRÉE
// PLUS LE LIEN (il n'a pas le privilège). Il se contente de le CONSTATER.
//
// Report FIDÈLE du mécanisme SE4 `Roaming→Server` (`applications.inc.php:538`) :
// le LIEN DE DOSSIER (mklink /D, posé par SYSTEM) pointe le profil local
// (`AppData\Roaming\…`) vers le home réseau — Firefox/Thunderbird accèdent
// DIRECTEMENT au serveur, SANS copie (un profil à gros cache/bases sqlite ferait
// exploser le temps de logon s'il transitait par la copie d'un profil itinérant).
//
// CONVERGENCE level-triggered (§5, STRICT inconditionnel 27.8) :
//   - Test  : pour chaque app, le lien existe et pointe le bon home + la paire
//     profiles.ini/installs.ini est conforme + le marqueur `.se-profile-version`
//     vaut la version courante (+ user.js d'épinglage cache si `cache_local`)
//     ⇒ compliant, ZÉRO écriture. Home injoignable ⇒ ERREUR (jamais de
//     suppression locale). Lien absent/divergent (pas encore posé par SYSTEM)
//     ⇒ non-compliant, level-triggered ;
//   - Apply : crée le dossier serveur s'il manque (AVANT toute op locale — un
//     home injoignable abandonne sans rien détruire), écrit le marqueur puis le
//     user.js. Le LIEN n'est PAS posé ici (privilège absent) : le compagnon le
//     CONSTATE seulement. La paire d'ini n'est écrite QUE SI le lien est déjà
//     présent et correct — sinon Firefox lancé entre-temps créerait un VRAI
//     dossier à l'emplacement du lien (scénario C1). Lien manquant ⇒ item
//     non-compliant avec un `detail` explicite (« lien posé par SYSTEM au
//     logon — en attente ») ; au cycle suivant le logon, Test ⇒ compliant.
//     IDEMPOTENT (2 passes stables ⇒ la 2ᵉ est un no-op).
//
// AC4 — le nom de profil (`profile_name`, ex. `managed.default`) est émis par le
// serveur : NEUF, STABLE, NON versionné, HORS radical `sambaedu`. Un profiles.ini
// produit ici n'est JAMAIS matché par `referencesSambaeduProfile()`
// (handler_legacy_cleanup.go) : les deux canaux coexistent. La porte d'évolution
// passe par le marqueur `.se-profile-version` DANS le profil (relu à chaque
// Apply) — un futur changement de format détecte la v1 et migre EN PLACE au lieu
// d'orpheliner (jamais un nom versionné, qui provoquerait une perte de signets
// silencieuse).
//
// AC5 — le CACHE reste LOCAL : Firefox place par défaut son cache disque (cache2)
// sous %LOCALAPPDATA%, pas dans le profil roaming ; par sécurité (report du
// `AppData\Local\cacheFirefox` SE4, et parce que faire tourner un cache sqlite
// sur SMB est la fragilité connue de ce montage), un `user.js` épingle
// `browser.cache.disk.parent_directory` sur un dossier LOCAL quand `cache_local`
// est fourni. Décision documentée (contrat §7.11) — vérification lab consignée.

// AppProfileVersion : version du profil géré, écrite dans le marqueur
// `.se-profile-version` à la création et RELUE à chaque Apply. La porte
// d'évolution : un futur format v2 lit "1" et migre EN PLACE (le nom du profil
// ne change JAMAIS — piège n°1).
const AppProfileVersion = "1"

// appProfileMarkerName : fichier marqueur de version DANS le profil (côté home
// réseau). Nom hors radical `sambaedu` (préfixe `.se-`).
const appProfileMarkerName = ".se-profile-version"

// appProfileUserJsName : fichier user.js d'épinglage du cache (AC5), DANS le
// profil.
const appProfileUserJsName = "user.js"

// AppProfileSpec : une app redirigeable (un item du payload `app_profile`). Tous
// les champs sont des strings (contrat §4.1).
type AppProfileSpec struct {
	App         string // identifiant d'app (firefox|thunderbird|…) — informatif/log
	Link        string // chemin RELATIF au profil Windows (AppData\Roaming\…\managed.default)
	Server      string // TOKEN `\\<se4fs>\users\<user>\…` (substitué localement)
	ProfileName string // nom de profil = Path= de profiles.ini (dernier segment de Link)
	InstallHash string // section Firefox `[Install<hash>]` — vide = pas de section
	CacheLocal  string // dossier de cache LOCAL sous %LOCALAPPDATA% — vide = pas d'épinglage
}

// AppProfileOps : opérations spécifiques à l'OS, injectées (testable hôte).
// L'impl Windows vit dans agent/windows/handler_app_profile_windows.go.
type AppProfileOps interface {
	// ResolveServer substitue les tokens locaux (`<se4fs>`/`<user>`) du chemin
	// serveur → l'UNC réel. Réutilise substituteTokens (jamais un second helper).
	ResolveServer(server string) (string, error)

	// ResolveLink résout le chemin RELATIF `link` contre le profil de
	// l'utilisateur (%USERPROFILE%) → chemin absolu local.
	ResolveLink(link string) (string, error)

	// ResolveLocalCache résout `%LOCALAPPDATA%\<cacheLocal>` (AC5) → chemin
	// absolu local. Appelé seulement si CacheLocal est non vide.
	ResolveLocalCache(cacheLocal string) (string, error)

	// EnsureDir crée le dossier (et ses parents) s'il manque. Home réseau
	// injoignable ⇒ erreur (l'item devient error, aucune donnée locale touchée).
	EnsureDir(path string) error

	// LinkState inspecte `link` : sa cible réelle (si c'est un lien), s'il
	// existe, s'il est bien un lien/point d'analyse. Un dossier réel (non-lien)
	// existant ⇒ (˝˝, true, false, nil). Le compagnon s'en sert pour CONSTATER le
	// lien (posé par le service SYSTEM au logon) — il ne le pose jamais lui-même.
	LinkState(link string) (target string, exists bool, isLink bool, err error)

	// ReadFile lit un fichier texte. exists=false + err=nil si absent ; err!=nil
	// si le support est injoignable (home réseau coupé).
	ReadFile(path string) (content string, exists bool, err error)

	// WriteFile écrit un fichier texte (création des parents au besoin,
	// écriture atomique).
	WriteFile(path, content string) error
}

// AppProfileHandler : handler aggregate branché dans le moteur du COMPAGNON.
type AppProfileHandler struct {
	Ops AppProfileOps
	Log *Logger

	// lastDetail : détail du DERNIER Test/Apply (interface DetailReporter du
	// moteur, cf. engine.go) — trace les apps dont le LIEN n'est pas encore posé
	// (« en attente de pose par le service SYSTEM au prochain logon »). Le
	// compagnon ne pose plus le lien (split 36.5) : un Apply qui laisse le lien
	// manquant est un état ATTENDU (level-triggered), pas une erreur — le detail
	// l'explique. Convergence complète (lien présent) ⇒ "" : l'item compliant
	// reste SANS `detail` (dédup serveur par hash préservée).
	lastDetail string
}

// ReportDetail : détail du dernier Test/Apply (interface DetailReporter du
// moteur). Vide quand tous les liens sont en place (convergence complète).
func (h *AppProfileHandler) ReportDetail() string {
	return h.lastDetail
}

// noteLinkPending enregistre (pour le rapport) qu'une app attend la pose de son
// lien par le service SYSTEM au prochain logon — la trace remonte au `detail` de
// l'item (withDetail, engine.go).
func (h *AppProfileHandler) noteLinkPending(app, link string) {
	line := fmt.Sprintf(
		"profil %s : lien %s posé par le service SYSTEM au logon — en attente (level-triggered, converge au prochain logon)",
		app, link,
	)
	if h.lastDetail == "" {
		h.lastDetail = line
	} else {
		h.lastDetail += "\n" + line
	}
}

// resolved : chemins résolus d'une app (calculés une fois pour Test ET Apply).
type appProfileResolved struct {
	spec        AppProfileSpec
	server      string // UNC réel du profil (tokens substitués)
	link        string // chemin absolu local du lien
	iniDir      string // dossier des ini = parent(link)
	profilesIni string // contenu attendu de profiles.ini
	installsIni string // contenu attendu de installs.ini (vide si pas d'install_hash)
	markerPath  string // <server>\.se-profile-version
	userJsPath  string // <server>\user.js (vide si pas de cache_local)
	userJs      string // contenu attendu de user.js (vide si pas de cache_local)
}

// resolve calcule tous les chemins et contenus attendus d'une app.
func (h *AppProfileHandler) resolve(spec AppProfileSpec) (appProfileResolved, error) {
	server, err := h.Ops.ResolveServer(spec.Server)
	if err != nil {
		return appProfileResolved{}, fmt.Errorf("chemin serveur %q non résoluble : %w", spec.Server, err)
	}
	link, err := h.Ops.ResolveLink(spec.Link)
	if err != nil {
		return appProfileResolved{}, fmt.Errorf("chemin lien %q non résoluble : %w", spec.Link, err)
	}

	r := appProfileResolved{
		spec:        spec,
		server:      server,
		link:        link,
		iniDir:      parentPath(link),
		profilesIni: BuildProfilesIni(spec.ProfileName, spec.InstallHash),
		installsIni: BuildInstallsIni(spec.ProfileName, spec.InstallHash),
		markerPath:  joinPath(server, appProfileMarkerName),
	}

	if spec.CacheLocal != "" {
		cacheDir, err := h.Ops.ResolveLocalCache(spec.CacheLocal)
		if err != nil {
			return appProfileResolved{}, fmt.Errorf("cache local %q non résoluble : %w", spec.CacheLocal, err)
		}
		r.userJsPath = joinPath(server, appProfileUserJsName)
		r.userJs = BuildUserJs(cacheDir)
	}

	return r, nil
}

// Test : toutes les apps sont-elles conformes (lien + ini + marqueur + user.js) ?
func (h *AppProfileHandler) Test(items []StateItem) (bool, error) {
	h.lastDetail = "" // Test n'écrit rien : purge le détail d'un run antérieur.
	for _, item := range items {
		spec, ok := parseAppProfileSpec(item.Payload)
		if !ok {
			return false, fmt.Errorf("payload app_profile inattendu : enveloppe invalide")
		}
		r, err := h.resolve(spec)
		if err != nil {
			return false, err
		}

		compliant, err := h.testOne(r)
		if err != nil {
			return false, err
		}
		if !compliant {
			return false, nil
		}
	}

	return true, nil
}

// testOne : conformité d'une app SANS écriture. Une lecture serveur en échec
// (home injoignable) remonte en erreur (jamais compliant/false silencieux).
func (h *AppProfileHandler) testOne(r appProfileResolved) (bool, error) {
	// Marqueur de version (côté home) — sonde AUSSI la joignabilité du home.
	marker, markerExists, err := h.Ops.ReadFile(r.markerPath)
	if err != nil {
		return false, fmt.Errorf("home injoignable (marqueur %q) : %w", r.markerPath, err)
	}
	if !markerExists || strings.TrimSpace(marker) != AppProfileVersion {
		return false, nil
	}

	// user.js d'épinglage cache (AC5).
	if r.userJs != "" {
		content, exists, err := h.Ops.ReadFile(r.userJsPath)
		if err != nil {
			return false, fmt.Errorf("home injoignable (user.js %q) : %w", r.userJsPath, err)
		}
		if !exists || content != r.userJs {
			return false, nil
		}
	}

	// Lien de dossier : présent, c'est bien un lien, pointe le bon home.
	target, exists, isLink, err := h.Ops.LinkState(r.link)
	if err != nil {
		return false, fmt.Errorf("état du lien %q : %w", r.link, err)
	}
	if !exists || !isLink || !samePath(target, r.server) {
		return false, nil
	}

	// Paire d'ini (côté local, dans le dossier parent du lien).
	profiles, exists, err := h.Ops.ReadFile(joinPath(r.iniDir, "profiles.ini"))
	if err != nil {
		return false, fmt.Errorf("lecture profiles.ini : %w", err)
	}
	if !exists || profiles != r.profilesIni {
		return false, nil
	}
	if r.installsIni != "" {
		installs, exists, err := h.Ops.ReadFile(joinPath(r.iniDir, "installs.ini"))
		if err != nil {
			return false, fmt.Errorf("lecture installs.ini : %w", err)
		}
		if !exists || installs != r.installsIni {
			return false, nil
		}
	}

	return true, nil
}

// Apply : converge chaque app. Idempotent + level-triggered.
func (h *AppProfileHandler) Apply(items []StateItem) error {
	h.lastDetail = "" // repart d'un détail vierge pour ce run.
	// Ordre déterministe (par app) pour des logs stables.
	specs := make([]AppProfileSpec, 0, len(items))
	for _, item := range items {
		spec, ok := parseAppProfileSpec(item.Payload)
		if !ok {
			return fmt.Errorf("payload app_profile inattendu : enveloppe invalide")
		}
		specs = append(specs, spec)
	}
	sort.SliceStable(specs, func(i, j int) bool { return specs[i].App < specs[j].App })

	for _, spec := range specs {
		if err := h.applyOne(spec); err != nil {
			return err
		}
	}

	return nil
}

// applyOne : converge une app. Le dossier serveur est créé EN PREMIER — un home
// injoignable abandonne AVANT toute op locale (jamais de suppression de données
// locales en compensation, AC6). Le LIEN n'est PAS posé ici (split 36.5 : le
// service SYSTEM le pose au logon, le compagnon n'a pas le privilège) : le
// compagnon le CONSTATE, et n'écrit la paire d'ini QUE si le lien est déjà en
// place et correct.
func (h *AppProfileHandler) applyOne(spec AppProfileSpec) error {
	r, err := h.resolve(spec)
	if err != nil {
		return err
	}

	// 1) Dossier serveur (home réseau) — AVANT tout geste local.
	if err := h.Ops.EnsureDir(r.server); err != nil {
		return fmt.Errorf("création du dossier serveur %q (home injoignable ?) : %w", r.server, err)
	}

	// 2) Marqueur de version (côté home) — écrit à la création, relu à l'Apply.
	marker, markerExists, err := h.Ops.ReadFile(r.markerPath)
	if err != nil {
		return fmt.Errorf("home injoignable (marqueur %q) : %w", r.markerPath, err)
	}
	if !markerExists || strings.TrimSpace(marker) != AppProfileVersion {
		if err := h.Ops.WriteFile(r.markerPath, AppProfileVersion+"\n"); err != nil {
			return fmt.Errorf("écriture du marqueur %q : %w", r.markerPath, err)
		}
	}

	// 3) user.js d'épinglage cache (AC5), côté home.
	if r.userJs != "" {
		content, exists, err := h.Ops.ReadFile(r.userJsPath)
		if err != nil {
			return fmt.Errorf("home injoignable (user.js %q) : %w", r.userJsPath, err)
		}
		if !exists || content != r.userJs {
			if err := h.Ops.WriteFile(r.userJsPath, r.userJs); err != nil {
				return fmt.Errorf("écriture de user.js %q : %w", r.userJsPath, err)
			}
		}
	}

	// 4) Lien de dossier : CONSTATÉ, jamais posé (le service SYSTEM le pose au
	// logon — le compagnon n'a pas SeCreateSymbolicLinkPrivilege).
	target, exists, isLink, err := h.Ops.LinkState(r.link)
	if err != nil {
		return fmt.Errorf("état du lien %q : %w", r.link, err)
	}
	linkReady := exists && isLink && samePath(target, r.server)

	// 5) Paire d'ini — écrite SEULEMENT si le lien est déjà présent et correct.
	// POURQUOI CET ORDRE (repensé pour le split 36.5) : l'ancien ordre (serveur →
	// marqueur → user.js → lien → ini) reposait sur le compagnon posant le lien
	// juste avant les ini. Le compagnon ne pose plus le lien ; si on écrivait les
	// ini alors que le lien n'est PAS encore là, Firefox lancé entre-temps
	// suivrait `profiles.ini` et créerait un VRAI dossier à l'emplacement du lien
	// — exactement le scénario C1 (données accumulées hors serveur, à déplacer de
	// côté). On GATE donc les ini sur la présence du lien : tant que SYSTEM ne l'a
	// pas posé, aucun ini local, l'item reste non-compliant « en attente ».
	if !linkReady {
		h.noteLinkPending(spec.App, r.link)
		logInfo(h.Log, "Profil %s : lien %s absent/divergent — ini non écrits, en attente de pose par SYSTEM au logon.", spec.App, r.link)

		return nil
	}

	if err := h.writeIfDiffer(joinPath(r.iniDir, "profiles.ini"), r.profilesIni); err != nil {
		return err
	}
	if r.installsIni != "" {
		if err := h.writeIfDiffer(joinPath(r.iniDir, "installs.ini"), r.installsIni); err != nil {
			return err
		}
	}

	return nil
}

// writeIfDiffer : écrit `content` à `path` seulement s'il diffère (idempotence).
func (h *AppProfileHandler) writeIfDiffer(path, content string) error {
	current, exists, err := h.Ops.ReadFile(path)
	if err != nil {
		return fmt.Errorf("lecture de %q : %w", path, err)
	}
	if exists && current == content {
		return nil
	}

	return h.Ops.WriteFile(path, content)
}

// --- Génération des ini (PURE — testée, non-collision AC4) --------------------

// BuildProfilesIni : contenu de profiles.ini nommant le profil `profileName`
// (relatif au dossier des ini, IsRelative=1, Default=1). Si `installHash` est
// non vide, la section `[Install<hash>]` est ajoutée (défaut de CETTE install).
// CRLF (convention Windows, iso SE4). Le `Name=` dérive du profileName SANS le
// radical `sambaedu` (piège n°1). Vérifié : jamais matché par
// `referencesSambaeduProfile()`.
func BuildProfilesIni(profileName, installHash string) string {
	var b strings.Builder
	if installHash != "" {
		b.WriteString("[Install" + installHash + "]\r\n")
		b.WriteString("Default=" + profileName + "\r\n")
		b.WriteString("Locked=1\r\n")
		b.WriteString("\r\n")
	}
	b.WriteString("[Profile0]\r\n")
	b.WriteString("Name=" + appProfileDisplayName(profileName) + "\r\n")
	b.WriteString("IsRelative=1\r\n")
	b.WriteString("Path=" + profileName + "\r\n")
	b.WriteString("Default=1\r\n")
	b.WriteString("\r\n")
	b.WriteString("[General]\r\n")
	b.WriteString("StartWithLastProfile=1\r\n")
	b.WriteString("Version=2\r\n")

	return b.String()
}

// BuildInstallsIni : contenu de installs.ini pour la section `[<hash>]`. Vide si
// `installHash` est vide (pas de fichier installs.ini à écrire).
func BuildInstallsIni(profileName, installHash string) string {
	if installHash == "" {
		return ""
	}
	var b strings.Builder
	b.WriteString("[" + installHash + "]\r\n")
	b.WriteString("Default=" + profileName + "\r\n")
	b.WriteString("Locked=1\r\n")

	return b.String()
}

// BuildUserJs : user.js épinglant le cache disque en LOCAL (AC5). `cacheAbsPath`
// est un chemin Windows absolu ; les backslashes sont échappés (JS string).
func BuildUserJs(cacheAbsPath string) string {
	escaped := strings.ReplaceAll(cacheAbsPath, `\`, `\\`)

	return "// Généré par SambaEdu (Story 36.5) — cache disque épinglé en local (AC5).\r\n" +
		`user_pref("browser.cache.disk.parent_directory", "` + escaped + `");` + "\r\n"
}

// appProfileDisplayName : nom d'affichage du profil (`Name=` de profiles.ini) —
// dérivé du profileName SANS le suffixe `.default`. Jamais bâti sur `sambaedu`.
func appProfileDisplayName(profileName string) string {
	name := strings.TrimSuffix(profileName, ".default")
	if name == "" {
		return profileName
	}

	return name
}

// --- Parsing & helpers de chemin ---------------------------------------------

// parseAppProfileSpec : extrait un AppProfileSpec d'un payload §3 brut. Les
// quatre champs minimaux (app/link/server/profile_name) manquants = enveloppe
// invalide (false) → le moteur rapporte error.
func parseAppProfileSpec(raw any) (AppProfileSpec, bool) {
	payload, ok := raw.(map[string]any)
	if !ok || payload == nil {
		return AppProfileSpec{}, false
	}

	app, _ := payload["app"].(string)
	link, _ := payload["link"].(string)
	server, _ := payload["server"].(string)
	profileName, _ := payload["profile_name"].(string)
	if app == "" || link == "" || server == "" || profileName == "" {
		return AppProfileSpec{}, false
	}

	installHash, _ := payload["install_hash"].(string)
	cacheLocal, _ := payload["cache_local"].(string)

	return AppProfileSpec{
		App:         app,
		Link:        link,
		Server:      server,
		ProfileName: profileName,
		InstallHash: installHash,
		CacheLocal:  cacheLocal,
	}, true
}

// parentPath : dossier parent d'un chemin Windows (séparateur `\`).
func parentPath(path string) string {
	p := strings.TrimRight(path, `\`)
	idx := strings.LastIndex(p, `\`)
	if idx < 0 {
		return p
	}

	return p[:idx]
}

// joinPath : joint deux segments avec un unique séparateur `\`.
func joinPath(base, name string) string {
	return strings.TrimRight(base, `\`) + `\` + strings.TrimLeft(name, `\`)
}

// samePath : égalité de chemins Windows (insensible casse + backslash final).
func samePath(a, b string) bool {
	na := strings.ToLower(strings.TrimRight(a, `\`))
	nb := strings.ToLower(strings.TrimRight(b, `\`))

	return na == nb
}
