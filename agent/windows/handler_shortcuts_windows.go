package main

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"unsafe"

	"golang.org/x/sys/windows"

	"sambaedu/agent/shared"
)

// Câblage Windows du handler `shortcuts` (Story 27.1, décision n° 7) — création
// du `.lnk` via COM IShellLink EN GO NATIF (PAS de shell-out PowerShell). Une
// seule fonction createShortcut(...) appelée en boucle ; zéro dépendance
// ajoutée (COM via golang.org/x/sys/windows + syscall vtable, comme le reste de
// l'agent câble Win32 sans cgo).
//
// Exécuté par le COMPAGNON (droits user) : les `.lnk` sont per-user (bureau
// local, Startup, Quick Launch). Le marqueur de périmètre (décision n° 5) est
// écrit dans le champ Description du raccourci (SetDescription) =
// shared.ShortcutManagedMarker — seuls les `.lnk` portant ce marqueur sont
// listés/supprimés, JAMAIS un raccourci créé par l'utilisateur.
//
// La substitution des tokens serveur (`<user>`/`<se4fs>`) se fait LOCALEMENT
// ici (login courant, nom du serveur de fichiers) — l'intelligence métier
// (shared_local vs personal_local → quel chemin) est restée côté serveur
// (provider), l'agent ne fait que matérialiser.

// --- GUIDs COM (CLSID_ShellLink, IID_IShellLinkW, IID_IPersistFile) ---------

var (
	clsidShellLink  = windows.GUID{Data1: 0x00021401, Data2: 0x0000, Data3: 0x0000, Data4: [8]byte{0xC0, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x46}}
	iidIShellLinkW  = windows.GUID{Data1: 0x000214F9, Data2: 0x0000, Data3: 0x0000, Data4: [8]byte{0xC0, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x46}}
	iidIPersistFile = windows.GUID{Data1: 0x0000010b, Data2: 0x0000, Data3: 0x0000, Data4: [8]byte{0xC0, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x46}}
)

const (
	coinitApartmentThreaded = 0x2
	clsctxInprocServer      = 0x1
)

var (
	modOle32           = windows.NewLazySystemDLL("ole32.dll")
	procCoInitializeEx = modOle32.NewProc("CoInitializeEx")
	procCoUninitialize = modOle32.NewProc("CoUninitialize")
	procCoCreateInst   = modOle32.NewProc("CoCreateInstance")
)

// iUnknownVtbl : préfixe commun (QueryInterface/AddRef/Release) — index 0..2.
// IShellLinkW et IPersistFile partagent ce préfixe.
type iUnknownVtbl struct {
	QueryInterface uintptr
	AddRef         uintptr
	Release        uintptr
}

// IShellLinkW vtable : on n'utilise que ce dont on a besoin (offsets ABI figés
// par Windows). Index : 0..2 IUnknown ; 3 GetPath ; 4 GetIDList ; … on cible
// SetPath(20)/SetArguments(11)/SetDescription(7)/SetIconLocation(17). Pour
// éviter les erreurs d'offset, on déclare la table COMPLÈTE dans l'ordre ABI.
type iShellLinkWVtbl struct {
	iUnknownVtbl
	GetPath             uintptr
	GetIDList           uintptr
	SetIDList           uintptr
	GetDescription      uintptr
	SetDescription      uintptr
	GetWorkingDirectory uintptr
	SetWorkingDirectory uintptr
	GetArguments        uintptr
	SetArguments        uintptr
	GetHotkey           uintptr
	SetHotkey           uintptr
	GetShowCmd          uintptr
	SetShowCmd          uintptr
	GetIconLocation     uintptr
	SetIconLocation     uintptr
	SetRelativePath     uintptr
	Resolve             uintptr
	SetPath             uintptr
}

type iShellLink struct {
	vtbl *iShellLinkWVtbl
}

type iPersistFileVtbl struct {
	iUnknownVtbl
	GetClassID    uintptr
	IsDirty       uintptr
	Load          uintptr
	Save          uintptr
	SaveCompleted uintptr
	GetCurFile    uintptr
}

type iPersistFile struct {
	vtbl *iPersistFileVtbl
}

// shortcutOps : impl ShortcutOps de production (Windows COM).
type shortcutOps struct {
	log *shared.Logger
	// iconsDir : cache local des icônes UPLOADÉES content-addressed
	// (C:\ProgramData\SambaEdu\Agent\icons, Story 27.7). Le SERVICE SYSTEM les
	// pré-télécharge (SyncShortcutIcons) ; le compagnon (ici) pointe
	// l'IconLocation dessus. Vide (tests) = pas de résolution d'asset → on
	// retombe sur l'icône brute.
	iconsDir string
}

// effectiveIcon résout l'IconLocation à poser sur le `.lnk` (Story 27.7).
//
//   - icône UPLOADÉE (spec.IconAsset non vide) ET le `.ico` local content-
//     addressed est présent (pré-téléchargé par SyncShortcutIcons) → on pointe
//     sur le chemin LOCAL absolu (index 0). Plus de « feuille blanche ».
//   - icône uploadée dont le `.ico` local manque encore (pas téléchargé /
//     checksum KO côté sync) → IconLocation VIDE (icône défaut Windows),
//     JAMAIS un chemin irrésoluble. Le drift est rattrapé au cycle suivant
//     (sous-décision F, piège n° 7).
//   - icône RÉELLE (chemin `firefox.exe,0`, IconAsset vide) → la même valeur
//     brute qu'avant (tokens substitués, ParseIconLocation gère `,index`).
func (o *shortcutOps) effectiveIcon(spec shared.ShortcutSpec) string {
	if spec.IconAsset != "" {
		// Décision PURE (stat du `.ico` local content-addressed) factorisée en
		// shared (testée sur l'hôte) : chemin local si présent, "" si manquant
		// (icône défaut, jamais cassée).
		return shared.ResolveUploadedIconLocation(spec.IconAsset, o.iconsDir)
	}

	return substituteTokens(spec.Icon)
}

// PlaceDir résout le répertoire absolu d'un emplacement (tokens substitués).
func (o *shortcutOps) PlaceDir(spec shared.ShortcutSpec) (string, error) {
	switch spec.Place {
	case "desktop":
		// Cible desktop normale : le chemin est résolu SERVEUR (Bug C) et porté
		// par desktop_path. Probe de balayage (review #2 — managedDirs sans règle
		// desktop) : desktop_path vide → on résout le bureau STANDARD local pour
		// nettoyer les orphelins gérés. Ne JAMAIS poser un `.lnk` sur cette probe
		// (desiredSet n'émet que des specs avec desktop_path non vide).
		//
		// Story 27.21 (option A) : la MÊME porte sert au Bureau RÉSEAU tokenisé.
		// Ce chemin ne vit PLUS dans une constante d'agent — c'est le SERVEUR qui
		// le NOMME via `desktop_sweep_paths` (il connaît seul l'environnement du
		// parc, cf. finding #1) ; l'agent le reçoit dans le payload et le résout
		// ICI par la substitution de tokens UNIQUE (SubstituteServerTokens),
		// jamais une 2ᵉ implémentation.
		dir := substituteTokens(spec.DesktopPath)
		if dir == "" {
			return standardDesktopDir()
		}
		// Fail-soft (27.21) : un UNC dont `<se4fs>` n'a pas pu être substitué
		// (poste hors-domaine, ni SE4FS ni LOGONSERVER) donnerait `\\\users\…` —
		// on refuse l'emplacement. managedDirs IGNORE la probe non résoluble
		// (aucune erreur fatale, les autres emplacements convergent) ; une vraie
		// cible desktop, elle, remonte l'erreur d'item comme avant.
		if !shared.UsableShortcutDir(dir) {
			return "", fmt.Errorf("bureau %q non résoluble localement (tokens non substituables)", spec.DesktopPath)
		}

		return dir, nil
	case "startup":
		appData := os.Getenv("APPDATA")
		if appData == "" {
			return "", fmt.Errorf("APPDATA non défini")
		}

		return filepath.Join(appData, `Microsoft\Windows\Start Menu\Programs\Startup`), nil
	case "taskbar":
		appData := os.Getenv("APPDATA")
		if appData == "" {
			return "", fmt.Errorf("APPDATA non défini")
		}

		return filepath.Join(appData, `Microsoft\Internet Explorer\Quick Launch\User Pinned\TaskBar`), nil
	default:
		return "", fmt.Errorf("place inconnu : %q", spec.Place)
	}
}

// standardDesktopDir : bureau STANDARD local (`%USERPROFILE%\Desktop`) —
// utilisé UNIQUEMENT pour le balayage des orphelins gérés quand plus aucune
// règle desktop ne fournit le chemin serveur (review #2). Jamais pour poser un
// `.lnk` (le placement obéit toujours au desktop_path serveur). Erreur si
// %USERPROFILE% absent.
func standardDesktopDir() (string, error) {
	profile := os.Getenv("USERPROFILE")
	if profile == "" {
		return "", fmt.Errorf("USERPROFILE non défini")
	}

	return filepath.Join(profile, "Desktop"), nil
}

// substituteTokens remplace les tokens serveur par les valeurs LOCALES.
//
//	<user>  → login courant (USERNAME) — token CANONIQUE unique pour le login de
//	          session, émis aussi bien par ShortcutsStateProvider que
//	          DrivesStateProvider (cf. normalisation : drives émettait jadis
//	          `<login>`, non substitué ici → UNC littéral `<login>` →
//	          WNetAddConnection2 code 67, lecteurs non montés).
//	<se4fs> → nom du serveur de fichiers (SE4FS env, fallback LOGONSERVER sans \\)
//	%USERPROFILE%/%APPDATA%/… → laissés à os.ExpandEnv (Windows les substitue
//	aussi via cmd, mais on les résout ici pour un chemin propre).
func substituteTokens(path string) string {
	user := os.Getenv("USERNAME")
	se4fs := os.Getenv("SE4FS")
	if se4fs == "" {
		se4fs = strings.TrimLeft(os.Getenv("LOGONSERVER"), `\`)
	}
	// Cœur PUR unique de la substitution `<user>`/`<se4fs>` (shared) — le MÊME
	// helper est appelé par le service SYSTEM au logon (app_profile), avec
	// l'identité de la SESSION au lieu de l'environnement du service (36.5).
	path = shared.SubstituteServerTokens(path, user, se4fs)

	return expandWindowsEnv(path)
}

// expandWindowsEnv substitue les %VAR% Windows (os.ExpandEnv ne gère que $VAR).
func expandWindowsEnv(path string) string {
	for {
		start := strings.IndexByte(path, '%')
		if start < 0 {
			break
		}
		end := strings.IndexByte(path[start+1:], '%')
		if end < 0 {
			break
		}
		name := path[start+1 : start+1+end]
		val := os.Getenv(name)
		path = path[:start] + val + path[start+1+end+1:]
	}

	return path
}

// ListManaged liste les `.lnk` GÉRÉS (Description == marqueur) des dirs donnés.
func (o *shortcutOps) ListManaged(dirs []string) ([]string, error) {
	managed := []string{}
	for _, dir := range dirs {
		entries, err := os.ReadDir(dir)
		if err != nil {
			// Répertoire ABSENT = aucun fichier : silence légitime (un Bureau
			// réseau qui n'existe pas n'a pas de raccourci à nettoyer).
			//
			// Toute AUTRE erreur (partage injoignable, ACL, DNS, pare-feu) est
			// une chose différente : le balayage n'a PAS eu lieu alors que des
			// `.lnk` gérés y subsistent peut-être. Sans trace, `Test` rapporte
			// `compliant` et les fantômes persistent invisibles (review 27.21 #4).
			if !os.IsNotExist(err) {
				o.logf("Emplacement %s non balayé (%v) : les raccourcis gérés qui s'y trouveraient ne seront PAS nettoyés à cette passe", dir, err)
			}

			continue
		}
		for _, entry := range entries {
			if entry.IsDir() || !strings.EqualFold(filepath.Ext(entry.Name()), ".lnk") {
				continue
			}
			path := filepath.Join(dir, entry.Name())
			isManaged, err := o.isManaged(path)
			if err != nil {
				// Un `.lnk` illisible (corrompu, verrouillé) : on NE le gère pas
				// (prudence — jamais supprimer ce qu'on ne comprend pas).
				o.logf("Raccourci illisible ignoré (%s) : %v", path, err)

				continue
			}
			if isManaged {
				managed = append(managed, path)
			}
		}
	}

	return managed, nil
}

// Matches : le `.lnk` à path est-il géré ET conforme (target/args/icon) ?
func (o *shortcutOps) Matches(path string, spec shared.ShortcutSpec) (bool, error) {
	if _, err := os.Stat(path); err != nil {
		return false, nil // absent → apply créera
	}

	desc, target, args, icon, iconIndex, err := o.readShortcut(path)
	if err != nil {
		return false, nil // illisible → réécrire
	}
	if desc != shared.ShortcutManagedMarker {
		// Homonyme utilisateur (sans marqueur) au chemin d'une cible. On retourne
		// (false, nil) — JAMAIS une erreur (review #1 : une erreur passait TOUT le
		// type en `error`, annulant la convergence pour un seul raccourci créé par
		// un prof). Le handler partagé consulte Blocked() AVANT Matches : il saute
		// ce chemin sans l'écraser (décision n° 5). Le (false, nil) ici n'est donc
		// jamais suivi d'un Create sur un fichier user.
		return false, nil
	}

	// L'icône cible est résolue (asset local content-addressed OU chemin réel,
	// Story 27.7) PUIS décomposée en (chemin, index) — la même convention
	// `chemin,index` que celle posée par createShortcut. On compare chemin ET
	// index au `.lnk` relu, sinon le `,0` de la spec ne matche jamais le chemin
	// nu relu → réécriture à chaque passe (idempotence cassée). Une icône
	// uploadée dont l'asset local manque encore résout en "" (icône défaut) :
	// le `.lnk` sans IconLocation est donc « conforme » tant que l'asset n'est
	// pas téléchargé → pas de réécriture en boucle ; quand le sync l'a déposé,
	// effectiveIcon pointe le fichier local et la passe suivante converge.
	specIconPath, specIconIndex := shared.ParseIconLocation(o.effectiveIcon(spec))
	iconMatches := strings.EqualFold(icon, specIconPath) && iconIndex == specIconIndex

	// `args` est comparé sensible à la casse et SANS substitution de tokens
	// (review #M4) : latent, aucun payload `args` ne porte de token aujourd'hui.
	// Si un jour un `args` contenait `<user>`/`%VAR%`, il faudrait substituer ici
	// comme pour target/icon, sinon perte d'idempotence (réécriture à chaque passe).
	return strings.EqualFold(target, substituteTokens(spec.Target)) &&
		args == spec.Args &&
		iconMatches, nil
}

// Blocked : un `.lnk` NON géré occupe-t-il `path` ? (décision n° 5, review #1).
// Absent / géré = false. Homonyme utilisateur = true → le handler partagé saute
// ce chemin (ni écrasé, ni supprimé). Illisible = false (prudence : on ne touche
// pas ce qu'on ne comprend pas — il restera tel quel).
func (o *shortcutOps) Blocked(path string) (bool, error) {
	if _, err := os.Stat(path); err != nil {
		return false, nil // absent → libre, apply créera
	}
	desc, _, _, _, _, err := o.readShortcut(path)
	if err != nil {
		return false, nil // illisible → on ne le considère pas comme un blocage géré
	}

	return desc != shared.ShortcutManagedMarker, nil
}

// Create écrit (ou réécrit) le `.lnk` avec le marqueur de gestion.
func (o *shortcutOps) Create(path string, spec shared.ShortcutSpec) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return fmt.Errorf("création du répertoire cible : %w", err)
	}

	return createShortcut(
		path,
		substituteTokens(spec.Target),
		spec.Args,
		o.effectiveIcon(spec),
		shared.ShortcutManagedMarker,
	)
}

// Remove supprime un `.lnk` géré (absent = pas d'erreur).
func (o *shortcutOps) Remove(path string) error {
	err := os.Remove(path)
	if os.IsNotExist(err) {
		return nil
	}

	return err
}

func (o *shortcutOps) logf(format string, args ...any) {
	if o.log != nil {
		o.log.Debugf(format, args...)
	}
}

// isManaged : le `.lnk` porte-t-il le marqueur de gestion (Description) ?
func (o *shortcutOps) isManaged(path string) (bool, error) {
	desc, _, _, _, _, err := o.readShortcut(path)
	if err != nil {
		return false, err
	}

	return desc == shared.ShortcutManagedMarker, nil
}

// --- COM helpers ------------------------------------------------------------

// withShellLink initialise COM, crée un IShellLink, charge le fichier si
// `loadPath` non vide, exécute fn, libère tout. Centralise CoInitialize/Release
// (jamais de fuite de référence).
func withShellLink(loadPath string, fn func(sl *iShellLink, pf *iPersistFile) error) (retErr error) {
	hr, _, _ := procCoInitializeEx.Call(0, coinitApartmentThreaded)
	// hr == 0 (S_OK) ou 1 (S_FALSE : déjà initialisé) → on doit Uninitialize.
	// E_… (négatif en int32) → échec, pas de Uninitialize.
	if int32(hr) < 0 {
		return fmt.Errorf("CoInitializeEx en échec (hr=0x%x)", uint32(hr))
	}
	defer procCoUninitialize.Call()

	var slPtr unsafe.Pointer
	hr, _, _ = procCoCreateInst.Call(
		uintptr(unsafe.Pointer(&clsidShellLink)),
		0,
		clsctxInprocServer,
		uintptr(unsafe.Pointer(&iidIShellLinkW)),
		uintptr(unsafe.Pointer(&slPtr)),
	)
	if int32(hr) < 0 || slPtr == nil {
		return fmt.Errorf("CoCreateInstance(ShellLink) en échec (hr=0x%x)", uint32(hr))
	}
	sl := (*iShellLink)(slPtr)
	defer release(sl.vtbl.Release, slPtr)

	var pfPtr unsafe.Pointer
	hr, _, _ = syscall.SyscallN(sl.vtbl.QueryInterface,
		uintptr(slPtr),
		uintptr(unsafe.Pointer(&iidIPersistFile)),
		uintptr(unsafe.Pointer(&pfPtr)),
	)
	if int32(hr) < 0 || pfPtr == nil {
		return fmt.Errorf("QueryInterface(IPersistFile) en échec (hr=0x%x)", uint32(hr))
	}
	pf := (*iPersistFile)(pfPtr)
	defer release(pf.vtbl.Release, pfPtr)

	if loadPath != "" {
		p, err := windows.UTF16PtrFromString(loadPath)
		if err != nil {
			return err
		}
		// IPersistFile::Load(pszFileName, dwMode=0=STGM_READ). `this` =
		// unsafe.Pointer(pf), harmonisé avec Save (review #3 — `pf` et `pfPtr`
		// pointent la même adresse ; on dérive le `this` depuis `pf` des deux côtés).
		hr, _, _ = syscall.SyscallN(pf.vtbl.Load, uintptr(unsafe.Pointer(pf)), uintptr(unsafe.Pointer(p)), 0)
		if int32(hr) < 0 {
			return fmt.Errorf("IPersistFile::Load(%s) en échec (hr=0x%x)", loadPath, uint32(hr))
		}
	}

	return fn(sl, pf)
}

func release(releaseFn uintptr, ptr unsafe.Pointer) {
	_, _, _ = syscall.SyscallN(releaseFn, uintptr(ptr))
}

// createShortcut : écrit un `.lnk` (target/args/icon/description) — la SEULE
// fonction de création, appelée en boucle (décision n° 7).
func createShortcut(path, target, args, icon, description string) error {
	return withShellLink("", func(sl *iShellLink, pf *iPersistFile) error {
		if err := setStr(sl.vtbl.SetPath, sl, target); err != nil {
			return fmt.Errorf("SetPath : %w", err)
		}
		if args != "" {
			if err := setStr(sl.vtbl.SetArguments, sl, args); err != nil {
				return fmt.Errorf("SetArguments : %w", err)
			}
		}
		if description != "" {
			if err := setStr(sl.vtbl.SetDescription, sl, description); err != nil {
				return fmt.Errorf("SetDescription : %w", err)
			}
		}
		if icon != "" {
			// L'icône suit la convention `chemin,index` (ex. `firefox.exe,0`) :
			// on la décompose pour SetIconLocation(path, index), sinon le `,0`
			// est pris comme partie du chemin → fichier introuvable → icône
			// « feuille blanche » (bug terrain 27.1).
			iconPath, iconIndex := shared.ParseIconLocation(icon)
			if err := setIconLocation(sl, iconPath, iconIndex); err != nil {
				return fmt.Errorf("SetIconLocation : %w", err)
			}
		}

		p, err := windows.UTF16PtrFromString(path)
		if err != nil {
			return err
		}
		// IPersistFile::Save(pszFileName, fRemember=TRUE). `this` = unsafe.Pointer(pf),
		// harmonisé avec Load (review #3 — pf et pfPtr pointent la même adresse ;
		// `pfPtr` n'est pas dans la portée de ce closure, on dérive donc le `this`
		// depuis `pf` des deux côtés ; changement non fonctionnel).
		hr, _, _ := syscall.SyscallN(pf.vtbl.Save, uintptr(unsafe.Pointer(pf)), uintptr(unsafe.Pointer(p)), 1)
		if int32(hr) < 0 {
			return fmt.Errorf("IPersistFile::Save(%s) en échec (hr=0x%x)", path, uint32(hr))
		}

		return nil
	})
}

// readShortcut lit description/target/args/icon(+index) d'un `.lnk` existant.
func (o *shortcutOps) readShortcut(path string) (desc, target, args, icon string, iconIndex int, err error) {
	err = withShellLink(path, func(sl *iShellLink, _ *iPersistFile) error {
		desc = getStr(sl.vtbl.GetDescription, sl)
		target = getStr(sl.vtbl.GetPath, sl)
		args = getStr(sl.vtbl.GetArguments, sl)
		icon, iconIndex = getIconLocation(sl)

		return nil
	})

	return desc, target, args, icon, iconIndex, err
}

const maxPathW = 1024

// setStr appelle une méthode IShellLinkW::Set*(LPCWSTR).
func setStr(method uintptr, sl *iShellLink, value string) error {
	p, err := windows.UTF16PtrFromString(value)
	if err != nil {
		return err
	}
	hr, _, _ := syscall.SyscallN(method, uintptr(unsafe.Pointer(sl)), uintptr(unsafe.Pointer(p)))
	if int32(hr) < 0 {
		return fmt.Errorf("hr=0x%x", uint32(hr))
	}

	return nil
}

// getStr appelle une méthode IShellLinkW::Get*(LPWSTR, cch[, …]) à 1 buffer.
// GetPath/GetArguments/GetDescription ont des signatures compatibles ici (on
// passe un buffer + sa taille ; GetPath a des params optionnels qu'on met à 0).
func getStr(method uintptr, sl *iShellLink) string {
	buf := make([]uint16, maxPathW)
	// GetPath(pszFile, cch, WIN32_FIND_DATA*=nil, fFlags=0) ;
	// GetArguments/GetDescription(psz, cch). Les args excédentaires (0) sont
	// inoffensifs sur stdcall x64.
	syscall.SyscallN(method,
		uintptr(unsafe.Pointer(sl)),
		uintptr(unsafe.Pointer(&buf[0])),
		uintptr(len(buf)),
		0,
		0,
	)

	return windows.UTF16ToString(buf)
}

// setIconLocation : SetIconLocation(LPCWSTR pszIconPath, int iIcon).
func setIconLocation(sl *iShellLink, icon string, index int) error {
	p, err := windows.UTF16PtrFromString(icon)
	if err != nil {
		return err
	}
	hr, _, _ := syscall.SyscallN(sl.vtbl.SetIconLocation,
		uintptr(unsafe.Pointer(sl)),
		uintptr(unsafe.Pointer(p)),
		uintptr(index),
	)
	if int32(hr) < 0 {
		return fmt.Errorf("hr=0x%x", uint32(hr))
	}

	return nil
}

// getIconLocation : GetIconLocation(LPWSTR pszIconPath, int cch, int* piIcon).
// Retourne le chemin ET l'index (deux champs natifs du `.lnk`) pour une
// comparaison d'idempotence correcte (sinon l'index est ignoré et le `.lnk` est
// réécrit à chaque passe).
func getIconLocation(sl *iShellLink) (string, int) {
	buf := make([]uint16, maxPathW)
	var idx int32
	syscall.SyscallN(sl.vtbl.GetIconLocation,
		uintptr(unsafe.Pointer(sl)),
		uintptr(unsafe.Pointer(&buf[0])),
		uintptr(len(buf)),
		uintptr(unsafe.Pointer(&idx)),
	)

	return windows.UTF16ToString(buf), int(idx)
}
