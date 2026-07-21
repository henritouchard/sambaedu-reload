package main

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"syscall"

	"sambaedu/agent/shared"
)

// Câblage Windows du handler `app_profile` (Story 36.5, contrat §7.11) —
// redirection du profil applicatif vers le home réseau. Exécuté par le
// COMPAGNON (droits user) : les ini, le marqueur et le user.js vivent dans le
// profil de l'utilisateur connecté / son home réseau. Toute la logique de
// convergence (Test/Apply, idempotence, génération des ini) est PORTABLE
// (shared.AppProfileHandler) ; ce fichier n'apporte que les ops Win32
// (substitution de tokens, I/O fichier, CONSTAT du lien).
//
// SPLIT SYSTEM-lien / COMPAGNON-reste (amendement final 36.5, Henri 2026-07-21).
// La pose du lien de dossier vers UNC exige `SeCreateSymbolicLinkPrivilege`,
// qu'AUCUN canal SE5 ne peut accorder au compagnon (le mécanisme `privilege` 35.6
// est SeDeny*-only par conception) — mais que le service LocalSystem possède
// nativement. Le service SYSTEM pose donc / répare le LIEN au logon
// (app_profile_logon_windows.go, sur le modèle EXACT de l'overlay). LE COMPAGNON
// NE POSE PLUS LE LIEN : il le CONSTATE (LinkState) et n'écrit la paire d'ini que
// s'il est déjà en place — sinon Firefox lancé entre-temps créerait un vrai
// dossier à l'emplacement du lien (scénario C1). Il n'y a donc plus de méthode
// CreateLink ici (déplacée côté SYSTEM avec la mise-de-côté C1 : moveDirAside).

// appProfileOps : impl shared.AppProfileOps de production (Windows).
type appProfileOps struct {
	log *shared.Logger
}

// ResolveServer substitue les tokens locaux (`<se4fs>`/`<user>`) — réutilise
// substituteTokens (jamais un second helper). Un token NON résolu (identité
// manquante) ⇒ erreur (l'item devient error, aucune donnée touchée).
func (o *appProfileOps) ResolveServer(server string) (string, error) {
	resolved := substituteTokens(server)
	if strings.Contains(resolved, "<se4fs>") || strings.Contains(resolved, "<user>") {
		return "", fmt.Errorf("tokens non résolus dans %q (SE4FS/USERNAME manquant)", server)
	}

	return resolved, nil
}

// ResolveLink résout le chemin relatif contre %USERPROFILE%.
func (o *appProfileOps) ResolveLink(link string) (string, error) {
	profile := os.Getenv("USERPROFILE")
	if profile == "" {
		return "", fmt.Errorf("USERPROFILE non défini")
	}

	return filepath.Join(profile, link), nil
}

// ResolveLocalCache résout %LOCALAPPDATA%\<cacheLocal> (AC5).
func (o *appProfileOps) ResolveLocalCache(cacheLocal string) (string, error) {
	local := os.Getenv("LOCALAPPDATA")
	if local == "" {
		return "", fmt.Errorf("LOCALAPPDATA non défini")
	}

	return filepath.Join(local, cacheLocal), nil
}

// EnsureDir crée le dossier et ses parents. Home réseau injoignable ⇒ erreur
// (remontée telle quelle → item error, aucune op locale n'a eu lieu).
func (o *appProfileOps) EnsureDir(path string) error {
	return os.MkdirAll(path, 0o755)
}

// LinkState inspecte `link` : cible réelle (si lien), existence, nature de lien.
func (o *appProfileOps) LinkState(link string) (target string, exists bool, isLink bool, err error) {
	info, err := os.Lstat(link)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return "", false, false, nil
		}

		return "", false, false, err
	}

	// Un lien symbolique / jonction (point d'analyse) porte ModeSymlink.
	if info.Mode()&os.ModeSymlink == 0 {
		// Dossier/fichier réel : existe mais n'est pas un lien.
		return "", true, false, nil
	}

	target, err = os.Readlink(link)
	if err != nil {
		// Point d'analyse illisible par Readlink : on le traite comme un lien
		// divergent (cible inconnue) → il sera refait.
		return "", true, true, nil
	}

	return target, true, true, nil
}

// ReadFile lit un fichier texte. Absent ⇒ (˝˝, false, nil) ; support injoignable
// (home réseau coupé) ⇒ err != nil.
//
// ⚠️ ORDRE CRITIQUE : le test réseau passe AVANT le test ErrNotExist. Go mappe
// certains Errno réseau Windows (dont ERROR_BAD_NETPATH=53) sur os.ErrNotExist
// (`syscall_windows.go`) — sans ce garde, un home INJOIGNABLE serait lu comme
// fichier ABSENT et le Test conclurait « non conforme » au lieu d'« erreur »
// (marqueur/profil réputés à recréer sur un serveur en réalité coupé). On veut
// l'inverse : home injoignable ⇒ ERREUR remontée, jamais de fausse absence.
func (o *appProfileOps) ReadFile(path string) (string, bool, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		if isNetworkErrno(err) {
			return "", false, fmt.Errorf("home réseau injoignable (%q) : %w", path, err)
		}
		if errors.Is(err, os.ErrNotExist) {
			return "", false, nil
		}

		return "", false, err
	}

	return string(data), true, nil
}

// isNetworkErrno : l'erreur porte-t-elle un code d'échec RÉSEAU Windows ? Ces
// codes signalent un support (partage SMB / home réseau) injoignable, PAS une
// absence de fichier. Go en mappe une partie sur os.ErrNotExist (mapping
// TROMPEUR : `syscall_windows.go` traduit ERROR_BAD_NETPATH=53 en ENOENT/
// ErrNotExist) — d'où ce test explicite, à faire AVANT tout `errors.Is(err,
// os.ErrNotExist)`. errors.As déballe l'éventuel *PathError vers syscall.Errno.
func isNetworkErrno(err error) bool {
	var errno syscall.Errno
	if !errors.As(err, &errno) {
		return false
	}
	switch errno {
	case 53, // ERROR_BAD_NETPATH        — chemin réseau introuvable
		59,   // ERROR_UNEXP_NET_ERR    — erreur réseau inattendue
		64,   // ERROR_NETNAME_DELETED  — nom réseau supprimé (session coupée)
		67,   // ERROR_BAD_NET_NAME     — nom réseau introuvable
		1231, // ERROR_NETWORK_UNREACHABLE
		1232: // ERROR_HOST_UNREACHABLE
		return true
	}

	return false
}

// WriteFile écrit un fichier texte (parents créés au besoin, écriture atomique).
func (o *appProfileOps) WriteFile(path, content string) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return fmt.Errorf("création du dossier de %q : %w", path, err)
	}

	return shared.WriteFileAtomic(path, []byte(content))
}
