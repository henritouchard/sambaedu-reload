package main

import (
	"fmt"
	"strings"
	"unsafe"

	"golang.org/x/sys/windows"

	"sambaedu/agent/shared"
)

// Câblage Windows du handler `drives` (Story 27.2) — montage de lecteur réseau
// via l'API Win32 mpr (WNetAddConnection2W / WNetCancelConnection2W /
// WNetGetConnectionW) EN GO NATIF (PAS de shell-out `net use`, iso décision
// 27.1 n° 7). Zéro dépendance ajoutée (mpr.dll via golang.org/x/sys/windows
// lazy DLL).
//
// Exécuté par le COMPAGNON (droits user) : les montages sont per-user (le `net
// use` équivalent). Le MARQUEUR de périmètre (décision n° 8) est résolu par le
// serveur cible : on ne gère QUE les lettres montées vers le serveur SambaEdu
// (`<se4fs>`). Une lettre montée par l'utilisateur vers un autre serveur n'est
// jamais listée, jamais démontée. Une lettre cible déjà occupée par un montage
// user (vers un AUTRE UNC) est Blocked → on n'écrase pas.
//
// Tokens `<se4fs>`/`<login>` substitués LOCALEMENT ; l'UNC logique
// (`\\<se4fs>\Classe_<name>\<login>\`) est resté CÔTÉ SERVEUR (provider MVP-A).

var (
	modMpr = windows.NewLazySystemDLL("mpr.dll")

	procWNetAddConnection2W    = modMpr.NewProc("WNetAddConnection2W")
	procWNetCancelConnection2W = modMpr.NewProc("WNetCancelConnection2W")
	procWNetGetConnectionW     = modMpr.NewProc("WNetGetConnectionW")
)

const (
	resourceTypeDisk    = 0x00000001
	connectUpdateProfile = 0x00000001 // persiste le montage (équiv. `net use /persistent:yes`)
	connectForce         = 0x00000001 // WNetCancelConnection2 fForce
)

// netResource : NETRESOURCEW (mpr). On ne renseigne que dwType / lpLocalName /
// lpRemoteName.
type netResource struct {
	Scope       uint32
	Type        uint32
	DisplayType uint32
	Usage       uint32
	LocalName   *uint16
	RemoteName  *uint16
	Comment     *uint16
	Provider    *uint16
}

// driveOps : impl shared.DriveOps de production (Windows mpr).
type driveOps struct {
	log *shared.Logger
}

// ResolveUNC substitue les tokens locaux (`<se4fs>`/`<login>`) dans l'UNC cible.
// Le `net use` n'accepte pas de backslash final → on le retire.
func (o *driveOps) ResolveUNC(spec shared.DriveSpec) (string, error) {
	unc := strings.TrimRight(substituteTokens(spec.UNC), `\`)
	if unc == "" || !strings.HasPrefix(unc, `\\`) {
		return "", fmt.Errorf("UNC non résoluble : %q", spec.UNC)
	}

	return unc, nil
}

// currentMapping : l'UNC vers lequel une lettre est actuellement montée (vide si
// non montée). WNetGetConnectionW.
func currentMapping(letter string) (string, bool) {
	local, err := windows.UTF16PtrFromString(strings.TrimRight(letter, `\`)) // "K:"
	if err != nil {
		return "", false
	}
	var size uint32 = windows.MAX_PATH
	buf := make([]uint16, size)
	r, _, _ := procWNetGetConnectionW.Call(
		uintptr(unsafe.Pointer(local)),
		uintptr(unsafe.Pointer(&buf[0])),
		uintptr(unsafe.Pointer(&size)),
	)
	if r != 0 { // != NO_ERROR → pas de connexion réseau sur cette lettre
		return "", false
	}

	return windows.UTF16ToString(buf), true
}

// ListManaged liste les lettres montées vers le serveur SambaEdu (marqueur de
// périmètre). On balaye A:..Z: et on ne retient que celles dont l'UNC pointe le
// serveur résolu.
func (o *driveOps) ListManaged() ([]string, error) {
	server := o.printServerPrefix()
	if server == "" {
		return nil, nil // pas de serveur résolu → aucun périmètre
	}

	managed := []string{}
	for c := byte('A'); c <= 'Z'; c++ {
		letter := string(c) + ":"
		unc, ok := currentMapping(letter)
		if !ok {
			continue
		}
		if strings.HasPrefix(strings.ToLower(unc), strings.ToLower(server)) {
			managed = append(managed, letter)
		}
	}

	return managed, nil
}

// printServerPrefix : `\\<se4fs>\` (serveur SambaEdu résolu) — marqueur de
// périmètre des montages gérés.
func (o *driveOps) printServerPrefix() string {
	se4fs := substituteTokens("<se4fs>")
	if se4fs == "" {
		return ""
	}

	return `\\` + se4fs + `\`
}

// Mapped : la lettre est-elle montée vers exactement `unc` ?
func (o *driveOps) Mapped(letter, unc string) (bool, error) {
	current, ok := currentMapping(letter)
	if !ok {
		return false, nil
	}

	return strings.EqualFold(strings.TrimRight(current, `\`), strings.TrimRight(unc, `\`)), nil
}

// Blocked : la lettre est-elle occupée par un montage HORS périmètre (vers un
// serveur autre que SambaEdu) ? true → on ne touche pas.
func (o *driveOps) Blocked(letter string) (bool, error) {
	current, ok := currentMapping(letter)
	if !ok {
		return false, nil // libre → apply montera
	}
	server := o.printServerPrefix()
	if server == "" {
		// Pas de serveur résolu : par prudence, une lettre déjà montée est
		// considérée hors périmètre (on ne touche pas à un montage existant).
		return true, nil
	}

	return !strings.HasPrefix(strings.ToLower(current), strings.ToLower(server)), nil
}

// Map monte la lettre vers l'UNC (WNetAddConnection2W, persistant). Si la lettre
// est déjà montée (gérée, vers un autre UNC), on démonte d'abord puis on
// remonte (idempotence de la convergence).
func (o *driveOps) Map(letter, unc string) error {
	// Si une connexion gérée diverge déjà sur cette lettre, la retirer d'abord.
	if _, ok := currentMapping(letter); ok {
		_ = o.Unmap(letter)
	}

	local, err := windows.UTF16PtrFromString(strings.TrimRight(letter, `\`)) // "K:"
	if err != nil {
		return err
	}
	remote, err := windows.UTF16PtrFromString(unc)
	if err != nil {
		return err
	}
	nr := netResource{
		Type:       resourceTypeDisk,
		LocalName:  local,
		RemoteName: remote,
	}
	r, _, callErr := procWNetAddConnection2W.Call(
		uintptr(unsafe.Pointer(&nr)),
		0, // lpPassword (NULL — auth Kerberos/NTLM de la session)
		0, // lpUserName (NULL — user courant)
		uintptr(connectUpdateProfile),
	)
	if r != 0 { // != NO_ERROR
		return fmt.Errorf("WNetAddConnection2(%s → %s) en échec (code=%d) : %v", letter, unc, r, callErr)
	}

	return nil
}

// Unmap démonte une lettre gérée (WNetCancelConnection2W, force). Absente = pas
// d'erreur (idempotent).
func (o *driveOps) Unmap(letter string) error {
	local, err := windows.UTF16PtrFromString(strings.TrimRight(letter, `\`))
	if err != nil {
		return err
	}
	r, _, callErr := procWNetCancelConnection2W.Call(
		uintptr(unsafe.Pointer(local)),
		uintptr(connectUpdateProfile),
		uintptr(connectForce),
	)
	// NO_ERROR (0) ou ERROR_NOT_CONNECTED (2250) = succès idempotent.
	const errorNotConnected = 2250
	if r != 0 && r != errorNotConnected {
		return fmt.Errorf("WNetCancelConnection2(%s) en échec (code=%d) : %v", letter, r, callErr)
	}

	return nil
}
