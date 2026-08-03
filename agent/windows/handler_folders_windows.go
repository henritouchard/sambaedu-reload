package main

import (
	"errors"
	"fmt"
	"os"
	"os/exec"
	"strings"

	"sambaedu/agent/shared"
)

// Câblage Windows du handler `folders` (Story 58.1, contrat §7.12) — redirection
// des dossiers shell (`User Shell Folders`). Exécuté par le COMPAGNON (droits
// user) : la clé vit dans HKCU, et la cible réseau doit être atteinte avec
// l'identité de l'utilisateur (le service SYSTEM n'a pas ses tickets Kerberos).
//
// Toute la convergence (Test/Apply, idempotence, ordre dossier-puis-valeur) est
// PORTABLE (shared.FoldersHandler) ; ce fichier n'apporte que les ops Win32 :
// substitution de tokens et accès disque. L'écriture registre, elle, passe par
// le MÊME `registryOps` que le handler `registry` — jamais une seconde
// implémentation d'accès à la ruche.

// folderOps : impl shared.FolderOps de production (Windows).
type folderOps struct {
	log *shared.Logger
}

// ResolvePath substitue les tokens serveur (`<se4fs>`/`<user>`) et normalise le
// résultat en valeur de registre.
//
// Contrairement à substituteTokens (raccourcis, lecteurs), on N'EXPANSE PAS les
// `%VAR%` : la valeur est écrite en REG_EXPAND_SZ et c'est Windows qui les
// résout à la lecture. Les résoudre ici graverait le chemin du profil COURANT
// dans une valeur censée valoir pour la session — et ferait diverger la valeur
// écrite de la valeur par défaut de Windows (`%USERPROFILE%\Desktop`), donc une
// réécriture + un redémarrage d'Explorer à CHAQUE logon d'un poste perdir.
//
// Le séparateur final est retiré : le serveur émet le gabarit avec un backslash
// terminal (convention legacy, partagée avec `desktop_path`), Windows écrit sans.
// Sans ce trim, une session vanilla serait éternellement « en dérive ».
func (o *folderOps) ResolvePath(path string) (string, error) {
	user := os.Getenv("USERNAME")
	se4fs := os.Getenv("SE4FS")
	if se4fs == "" {
		se4fs = strings.TrimLeft(os.Getenv("LOGONSERVER"), `\`)
	}
	// Cœur PUR unique de la substitution `<user>`/`<se4fs>` (shared) — le MÊME
	// helper que shortcuts / drives / app_profile.
	resolved := strings.TrimRight(shared.SubstituteServerTokens(path, user, se4fs), `\`)

	if strings.Contains(resolved, "<se4fs>") || strings.Contains(resolved, "<user>") {
		return "", fmt.Errorf("tokens non substituables dans %q (USERNAME/SE4FS absents ?)", path)
	}
	// Garde du serveur vide : `\\\users\…` (SE4FS et LOGONSERVER tous deux
	// absents, poste hors-domaine). Rediriger le Bureau là-dessus donnerait un
	// bureau MORT à l'utilisateur — on refuse plutôt que d'écrire.
	if !shared.UsableShortcutDir(resolved) {
		return "", fmt.Errorf("chemin %q non résoluble localement (serveur de fichiers inconnu)", path)
	}

	return resolved, nil
}

// DirExists : le dossier cible existe-t-il ? Les `%VAR%` de la valeur de
// registre sont expansés ICI (et seulement ici) pour l'accès disque.
//
// Distinction essentielle (iso app_profile) : « absent » est une DÉRIVE à
// corriger, « injoignable » est une ERREUR. Confondre les deux ferait créer un
// Bureau local en catimini le jour où le serveur de fichiers tousse.
func (o *folderOps) DirExists(value string) (bool, error) {
	info, err := os.Stat(expandWindowsEnv(value))
	if err == nil {
		if !info.IsDir() {
			return false, fmt.Errorf("%q existe mais n'est pas un dossier", value)
		}

		return true, nil
	}
	if errors.Is(err, os.ErrNotExist) {
		return false, nil
	}

	return false, err
}

// EnsureDir crée le dossier cible et ses parents. Cible réseau injoignable ⇒
// erreur remontée telle quelle (item error, aucune redirection posée).
func (o *folderOps) EnsureDir(value string) error {
	return os.MkdirAll(expandWindowsEnv(value), 0o755)
}

// --- Accès rapide (Quick Access / « Accueil ») -------------------------------
//
// POURQUOI PowerShell ICI, alors que le handler `shortcuts` proscrit le
// shell-out (il crée les `.lnk` en COM natif). Deux raisons, et elles ne valent
// que pour ce cas :
//
//  1. L'épinglage n'existe QUE comme verbe d'automation `pintohome` sur
//     `Shell.Application` — un objet IDispatch, sans interface à vtable
//     exploitable depuis Go par `syscall.SyscallN` comme l'est IShellLink. Le
//     câbler nativement voudrait dire écrire un client IDispatch complet
//     (GetIDsOfNames + Invoke + marshalling VARIANT/DISPPARAMS) pour trois
//     appels : beaucoup de code fragile pour zéro gain.
//  2. Le volume est sans commune mesure : `shortcuts` proscrivait le shell-out
//     parce qu'il tourne en boucle sur des dizaines de `.lnk` à chaque passe ;
//     ici c'est au plus trois invocations, uniquement lors d'un changement
//     effectif. L'agent shell-oute déjà vers powershell.exe ailleurs
//     (legacy_cleanup, tasks, smbios).
//
// C'est aussi, littéralement, le mécanisme du script legacy `bureau_samba.ps1`.
//
// ⚠️ `pintohome` est un verbe BASCULE : l'invoquer sur un emplacement déjà
// épinglé le DÉSÉPINGLE. Chaque op teste donc l'état AVANT d'invoquer — c'est ce
// qui rend Pin et Unpin idempotents comme leur contrat le promet, et ce qui
// évite qu'une double convergence n'annule la précédente.

// quickAccessNamespace : le dossier virtuel « Accès rapide » (KNOWNFOLDERID
// FOLDERID_Frequent). GUID figé par Windows.
const quickAccessNamespace = `shell:::{679f85cb-0220-4080-b29b-5540cc05aab6}`

// psQuote (échappement d'un littéral PowerShell simple-quote) est déclaré une
// seule fois pour le paquet, dans tasks_windows.go.

// quickAccessPaths énumère les emplacements ÉPINGLÉS, chemins résolus.
func (o *folderOps) quickAccessPaths() ([]string, error) {
	script := `$ErrorActionPreference='Stop';` +
		`(New-Object -ComObject shell.application).Namespace(` + psQuote(quickAccessNamespace) + `).Items() | ` +
		`ForEach-Object { $_.Path }`

	out, err := runPowerShell(script)
	if err != nil {
		return nil, fmt.Errorf("énumération de l'Accès rapide : %w", err)
	}

	paths := []string{}
	for _, line := range strings.Split(out, "\n") {
		line = strings.TrimSpace(line)
		if line != "" {
			paths = append(paths, line)
		}
	}

	return paths, nil
}

// QuickAccessPinned : `value` (une fois ses `%VAR%` expansés) figure-t-il parmi
// les épingles ? Comparaison insensible à la casse et au séparateur final —
// Windows enregistre le chemin concret, jamais le gabarit du serveur.
func (o *folderOps) QuickAccessPinned(value string) (bool, error) {
	paths, err := o.quickAccessPaths()
	if err != nil {
		return false, err
	}

	return containsPath(paths, expandWindowsEnv(value)), nil
}

// QuickAccessPin épingle `value`. Déjà épinglé ⇒ no-op (le verbe est une
// bascule : invoquer sans tester désépinglerait).
func (o *folderOps) QuickAccessPin(value string) error {
	pinned, err := o.QuickAccessPinned(value)
	if err != nil {
		return err
	}
	if pinned {
		return nil
	}

	return o.togglePin(expandWindowsEnv(value))
}

// QuickAccessUnpin retire l'épingle de `value`. Absente ⇒ no-op.
func (o *folderOps) QuickAccessUnpin(value string) error {
	pinned, err := o.QuickAccessPinned(value)
	if err != nil {
		return err
	}
	if !pinned {
		return nil
	}

	return o.togglePin(expandWindowsEnv(value))
}

// togglePin invoque `pintohome` sur un chemin RÉSOLU. L'appelant a déjà
// constaté l'état : c'est lui qui donne son sens à la bascule.
func (o *folderOps) togglePin(resolved string) error {
	script := `$ErrorActionPreference='Stop';` +
		`$item=(New-Object -ComObject shell.application).Namespace(` + psQuote(resolved) + `);` +
		`if ($item -eq $null) { throw 'emplacement introuvable' };` +
		`$item.Self.InvokeVerb('pintohome')`

	if _, err := runPowerShell(script); err != nil {
		return fmt.Errorf("bascule d'épingle sur %q : %w", resolved, err)
	}

	return nil
}

// containsPath : appartenance insensible à la casse, séparateur final ignoré.
func containsPath(haystack []string, needle string) bool {
	needle = strings.TrimRight(needle, `\`)
	for _, p := range haystack {
		if strings.EqualFold(strings.TrimRight(p, `\`), needle) {
			return true
		}
	}

	return false
}

// runPowerShell exécute un script court et rend sa sortie standard. Même
// invocation que le reste de l'agent (legacy_cleanup, tasks) : sans profil,
// non interactif.
func runPowerShell(script string) (string, error) {
	out, err := exec.Command("powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script).Output()
	if err != nil {
		var exitErr *exec.ExitError
		if errors.As(err, &exitErr) && len(exitErr.Stderr) > 0 {
			return "", fmt.Errorf("%w : %s", err, strings.TrimSpace(string(exitErr.Stderr)))
		}

		return "", err
	}

	return string(out), nil
}
