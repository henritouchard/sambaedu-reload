package main

import (
	"errors"
	"fmt"
	"os"
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
