package main

import (
	"errors"
	"fmt"
	"os"
	"os/exec"
	"strings"
	"time"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"

	"sambaedu/agent/shared"
)

// Câblage Windows du handler `associations` (Story 27.3bis) — accès registre
// HKCU EN GO NATIF via golang.org/x/sys/windows/registry (déjà dans go.mod) +
// lecture du SID/temps/experience pour le hash UserChoice. Zéro shell-out.
//
// Exécuté par le COMPAGNON (droits user) : UserChoice vit sous HKCU, c'est la
// ruche de l'utilisateur connecté. La logique de convergence (Test/Apply,
// idempotence, isolation, hash, mode ProgId-absent) vit dans
// shared.AssociationsHandler (testée hôte avec un fake) ; ce fichier ne fait que
// les opérations Windows concrètes.
//
// Clés UserChoice :
//   - fichier  : HKCU\Software\Microsoft\Windows\CurrentVersion\Explorer\FileExts\<ext>\UserChoice
//   - protocole: HKCU\Software\Microsoft\Windows\Shell\Associations\UrlAssociations\<proto>\UserChoice
//
// SUPPRESSION AVANT RÉÉCRITURE (Remove-UserChoiceKey legacy) : la clé UserChoice
// a un ACL hérité qui REFUSE l'écriture directe de `Hash`. Il faut SUPPRIMER la
// clé (RegDeleteKey natif) puis la RECRÉER. registry.DeleteKey appelle
// RegDeleteKeyW — même primitive que le legacy.

const (
	fileExtsBase = `Software\Microsoft\Windows\CurrentVersion\Explorer\FileExts`
	urlAssocBase = `Software\Microsoft\Windows\Shell\Associations\UrlAssociations`
)

// associationsOps : impl shared.AssociationsOps de production (Windows registry).
type associationsOps struct {
	log *shared.Logger
}

// userChoicePath : chemin de la clé UserChoice sous HKCU pour une association.
func userChoiceParentAndKey(spec shared.AssociationSpec) (parent, key string) {
	if strings.EqualFold(spec.Type, "protocol") {
		return urlAssocBase + `\` + spec.Identifier, urlAssocBase + `\` + spec.Identifier + `\UserChoice`
	}

	return fileExtsBase + `\` + spec.Identifier, fileExtsBase + `\` + spec.Identifier + `\UserChoice`
}

// ReadUserChoiceProgID lit le ProgId réel inscrit sous UserChoice. present=false
// si la clé ou la valeur n'existe pas (dérive). err = accès refusé.
func (o *associationsOps) ReadUserChoiceProgID(spec shared.AssociationSpec) (string, bool, error) {
	_, keyPath := userChoiceParentAndKey(spec)

	key, err := registry.OpenKey(registry.CURRENT_USER, keyPath, registry.QUERY_VALUE)
	if err != nil {
		if errors.Is(err, registry.ErrNotExist) {
			return "", false, nil // clé absente → dérive
		}

		return "", false, fmt.Errorf("ouverture de HKCU\\%s : %w", keyPath, err)
	}
	defer key.Close()

	progID, _, err := key.GetStringValue("ProgId")
	if err != nil {
		if errors.Is(err, registry.ErrNotExist) {
			return "", false, nil // valeur absente → dérive
		}

		return "", false, fmt.Errorf("lecture de HKCU\\%s!ProgId : %w", keyPath, err)
	}

	return progID, true, nil
}

// ProgIDRegistered : le ProgId cible est-il enregistré sur le poste ?
// CLASSES_ROOT fusionne HKLM\Software\Classes et HKCU\Software\Classes : la
// présence de la clé `<ProgId>` y atteste que l'application gère ce ProgId.
// D-Henri n°5 : si absent, l'agent NE touche PAS la clé UserChoice.
//
// Story 27.11 (raffinement CAS GÉNÉRIQUE) : pour `Applications\<exe>`, la présence
// du nœud ne suffit pas — sans `shell\open\command`, Windows ouvrirait « Comment
// voulez-vous ouvrir… ». On vérifie donc la sous-clé `shell\open\command` ET sa
// valeur par défaut non vide pour ce cas. Les ProgId riches restent inchangés.
func (o *associationsOps) ProgIDRegistered(progID string) (bool, error) {
	if progID == "" {
		return false, nil
	}

	if strings.HasPrefix(strings.ToLower(progID), `applications\`) {
		return progIDCommandRegistered(progID)
	}

	key, err := registry.OpenKey(registry.CLASSES_ROOT, progID, registry.QUERY_VALUE)
	if err != nil {
		if errors.Is(err, registry.ErrNotExist) {
			return false, nil
		}

		return false, fmt.Errorf("ouverture de HKCR\\%s : %w", progID, err)
	}
	_ = key.Close()

	return true, nil
}

// progIDCommandRegistered : le ProgId générique a-t-il une commande d'ouverture
// effective `HKCR\<ProgId>\shell\open\command` (valeur par défaut non vide) ? C'est
// la condition RÉELLE d'applicabilité d'un `Applications\<exe>` (Story 27.11).
func progIDCommandRegistered(progID string) (bool, error) {
	cmdPath := progID + `\shell\open\command`

	key, err := registry.OpenKey(registry.CLASSES_ROOT, cmdPath, registry.QUERY_VALUE)
	if err != nil {
		if errors.Is(err, registry.ErrNotExist) {
			return false, nil
		}

		return false, fmt.Errorf("ouverture de HKCR\\%s : %w", cmdPath, err)
	}
	defer key.Close()

	// Valeur par défaut (nom ""). Absente/vide → commande non effective.
	cmd, _, err := key.GetStringValue("")
	if err != nil {
		if errors.Is(err, registry.ErrNotExist) {
			return false, nil
		}

		return false, fmt.Errorf("lecture de HKCR\\%s (défaut) : %w", cmdPath, err)
	}

	return strings.TrimSpace(cmd) != "", nil
}

// RegisterApplicationProgID auto-enregistre PER-USER un ProgId générique
// `Applications\<exe>` (Story 27.11, AC6) : résout le chemin COMPLET de `<exe>` sur
// le poste (App Paths puis PATH) — JAMAIS reçu du serveur — et écrit
// `HKCU\Software\Classes\Applications\<exe>\shell\open\command = "<chemin>" "%1"`.
// AUCUNE écriture HKLM/admin. Exe introuvable → registered=false (abstention,
// choix utilisateur préservé). Idempotent (réécrire la même commande = no-op
// fonctionnel).
func (o *associationsOps) RegisterApplicationProgID(exe string) (bool, error) {
	exe = strings.TrimSpace(exe)
	if exe == "" {
		return false, nil
	}

	fullPath, found := resolveExecutablePath(exe)
	if !found {
		// Exe introuvable sur le poste → on s'abstient (D-Henri n°5).
		return false, nil
	}

	// HKCU\Software\Classes\Applications\<exe>\shell\open\command (per-user).
	keyPath := `Software\Classes\Applications\` + exe + `\shell\open\command`
	key, _, err := registry.CreateKey(registry.CURRENT_USER, keyPath, registry.SET_VALUE)
	if err != nil {
		return false, fmt.Errorf("création de HKCU\\%s : %w", keyPath, err)
	}
	defer key.Close()

	// `"<chemin>" "%1"` : le %1 passe le fichier en argument (obligatoire — piège
	// n°4). Valeur par défaut (nom "").
	command := `"` + fullPath + `" "%1"`
	if err := key.SetStringValue("", command); err != nil {
		return false, fmt.Errorf("écriture de HKCU\\%s (défaut) : %w", keyPath, err)
	}

	return true, nil
}

// resolveExecutablePath résout le chemin COMPLET d'un exe par son nom, sur le
// poste : d'abord via `App Paths` (HKCU puis HKLM —
// `Software\Microsoft\Windows\CurrentVersion\App Paths\<exe>`, convention Windows
// de résolution par nom), puis via le PATH (exec.LookPath). Introuvable → false.
func resolveExecutablePath(exe string) (string, bool) {
	for _, root := range []registry.Key{registry.CURRENT_USER, registry.LOCAL_MACHINE} {
		appPathsKey := `Software\Microsoft\Windows\CurrentVersion\App Paths\` + exe
		key, err := registry.OpenKey(root, appPathsKey, registry.QUERY_VALUE)
		if err != nil {
			continue
		}
		val, _, err := key.GetStringValue("")
		_ = key.Close()
		if err == nil {
			val = strings.Trim(strings.TrimSpace(val), `"`)
			if val != "" {
				return val, true
			}
		}
	}

	if p, err := exec.LookPath(exe); err == nil && p != "" {
		return p, true
	}

	return "", false
}

// WriteUserChoice supprime l'ancienne clé UserChoice (ACL hérité bloque la
// réécriture directe) puis la RECRÉE avec `Hash` + `ProgId`. Idempotent du point
// de vue du résultat. La suppression d'une clé absente est tolérée.
func (o *associationsOps) WriteUserChoice(spec shared.AssociationSpec, hash string) error {
	parent, keyPath := userChoiceParentAndKey(spec)

	// 1. Supprimer la clé UserChoice si elle existe (RegDeleteKeyW natif). On
	//    ouvre la clé PARENT et supprime la sous-clé `UserChoice` (la suppression
	//    d'une clé absente renvoie ErrNotExist, toléré).
	parentKey, err := registry.OpenKey(registry.CURRENT_USER, parent, registry.WRITE)
	if err == nil {
		if delErr := registry.DeleteKey(parentKey, "UserChoice"); delErr != nil && !errors.Is(delErr, registry.ErrNotExist) {
			_ = parentKey.Close()

			return fmt.Errorf("suppression de HKCU\\%s : %w", keyPath, delErr)
		}
		_ = parentKey.Close()
	} else if !errors.Is(err, registry.ErrNotExist) {
		return fmt.Errorf("ouverture du parent HKCU\\%s : %w", parent, err)
	}

	// 2. (Re)créer UserChoice et écrire Hash + ProgId.
	key, _, err := registry.CreateKey(registry.CURRENT_USER, keyPath, registry.SET_VALUE)
	if err != nil {
		return fmt.Errorf("création de HKCU\\%s : %w", keyPath, err)
	}
	defer key.Close()

	if err := key.SetStringValue("Hash", hash); err != nil {
		return fmt.Errorf("écriture de HKCU\\%s!Hash : %w", keyPath, err)
	}
	if err := key.SetStringValue("ProgId", spec.ProgID); err != nil {
		return fmt.Errorf("écriture de HKCU\\%s!ProgId : %w", keyPath, err)
	}

	return nil
}

// SessionInputs retourne les entrées poste du hash UserChoice : SID (minuscule),
// FileTime hex courant (secondes mises à zéro, iso Get-HexDateTime), chaîne
// « user experience » (GUID extrait de shell32.dll, ou hardcodée en repli).
func (o *associationsOps) SessionInputs() (string, string, string, error) {
	sid, err := currentProcessSID()
	if err != nil {
		return "", "", "", fmt.Errorf("résolution du SID de session : %w", err)
	}

	return strings.ToLower(sid), hexDateTimeNow(), userExperienceString(), nil
}

// hexDateTimeNow : portage de Get-HexDateTime — FileTime de l'instant courant
// avec les SECONDES MISES À ZÉRO, en hex (hi puis low en X8), minuscule.
func hexDateTimeNow() string {
	now := time.Now()
	zeroed := time.Date(now.Year(), now.Month(), now.Day(), now.Hour(), now.Minute(), 0, 0, now.Location())

	// Windows FileTime = 100-ns depuis 1601-01-01 UTC. windows.NsecToFiletime
	// convertit des nanosecondes Unix ; on passe par le filetime Windows.
	ft := windows.NsecToFiletime(zeroed.UnixNano())
	fileTime := (uint64(ft.HighDateTime) << 32) | uint64(ft.LowDateTime)

	hi := uint32(fileTime >> 32)
	low := uint32(fileTime & 0xFFFFFFFF)

	return strings.ToLower(fmt.Sprintf("%08X%08X", hi, low))
}

const hardcodedUserExperience = "User Choice set via Windows User Experience {D18B6DD5-6124-4341-9318-804003BAFA0B}"

// userExperienceString : portage de Get-UserExperience — extrait la chaîne
// « User Choice set via Windows User Experience {...} » de shell32.dll (lue dans
// SysWOW64, iso SpecialFolder.SystemX86). Repli sur la chaîne hardcodée à la
// moindre erreur (iso le try/catch legacy).
func userExperienceString() string {
	const search = "User Choice set via Windows User Experience"

	// GetSystemWindowsDirectory retourne le RÉPERTOIRE WINDOWS (`C:\Windows`), PAS
	// `…\SysWOW64` — on lui ajoute le sous-chemin explicitement (ne pas retirer le
	// suffixe en pensant que winDir pointe déjà sur SysWOW64).
	winDir, err := windows.GetSystemWindowsDirectory()
	if err != nil {
		return hardcodedUserExperience
	}
	shell32 := winDir + `\SysWOW64\Shell32.dll`
	// NB divergence legacy (bénigne) : SFTA.ps1 ne lit que les 5 premiers Mo de
	// shell32.dll ; ici os.ReadFile lit le binaire entier (~20-30 Mo, pic
	// transitoire au logon, une fois). Plus robuste (chaîne trouvée même tardive),
	// repli hardcodé en garde-fou ; non borné car la chaîne est en pratique tôt.
	data, err := os.ReadFile(shell32)
	if err != nil {
		// Repli sur System32 (postes 32 bits / SysWOW64 absent).
		shell32 = winDir + `\System32\Shell32.dll`
		data, err = os.ReadFile(shell32)
		if err != nil {
			return hardcodedUserExperience
		}
	}

	// La chaîne recherchée est en UTF-16LE dans le binaire. On la décode du flux.
	decoded := decodeUTF16LE(data)
	pos1 := strings.Index(decoded, search)
	if pos1 < 0 {
		return hardcodedUserExperience
	}
	pos2 := strings.Index(decoded[pos1:], "}")
	if pos2 < 0 {
		return hardcodedUserExperience
	}

	return decoded[pos1 : pos1+pos2+1]
}

// decodeUTF16LE décode un flux d'octets en chaîne en interprétant chaque paire
// d'octets comme une unité UTF-16LE (iso [Text.Encoding]::Unicode.GetString).
func decodeUTF16LE(b []byte) string {
	n := len(b) / 2
	u16 := make([]uint16, n)
	for i := 0; i < n; i++ {
		u16[i] = uint16(b[i*2]) | uint16(b[i*2+1])<<8
	}

	return string(windows.UTF16ToString(u16))
}
