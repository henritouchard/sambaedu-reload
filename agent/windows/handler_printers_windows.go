package main

import (
	"fmt"
	"os"
	"strings"
	"unsafe"

	"golang.org/x/sys/windows"

	"sambaedu/agent/shared"
)

// Câblage Windows du handler `printers` (Story 27.2) — connexion à une
// imprimante réseau partagée via l'API Win32 winspool EN GO NATIF (PAS de
// shell-out PowerShell/printui, iso décision 27.1 n° 7) : AddPrinterConnection /
// DeletePrinterConnection / SetDefaultPrinter / EnumPrinters. Zéro dépendance
// ajoutée (winspool.drv via golang.org/x/sys/windows lazy DLL, comme le reste
// de l'agent câble Win32 sans cgo).
//
// Exécuté par le COMPAGNON (droits user) : les connexions imprimante sont
// per-user (PRINTER_CONNECTIONS). Le MARQUEUR de périmètre (décision n° 8) est
// IMPLICITE : une connexion gérée est un partage `\\<serveur>\<nom>` dont le
// serveur correspond au serveur SambaEdu résolu (`<se4fs>`). On ne liste / ne
// désinstalle QUE les connexions vers CE serveur — jamais une imprimante locale
// ni une connexion vers un autre serveur (montée par l'utilisateur).
//
// La substitution du token serveur (`<se4fs>`) se fait LOCALEMENT ici ; la
// connexion logique (`\\<se4fs>\<cups_name>`) et le choix du défaut
// (physique > logique) sont restés CÔTÉ SERVEUR (provider). L'agent ne fait que
// matérialiser.

var (
	modWinspool = windows.NewLazySystemDLL("winspool.drv")

	// API W (UTF-16). Connexions imprimante per-user.
	procAddPrinterConnectionW    = modWinspool.NewProc("AddPrinterConnectionW")
	procDeletePrinterConnectionW = modWinspool.NewProc("DeletePrinterConnectionW")
	procSetDefaultPrinterW       = modWinspool.NewProc("SetDefaultPrinterW")
	procGetDefaultPrinterW       = modWinspool.NewProc("GetDefaultPrinterW")
	procEnumPrintersW            = modWinspool.NewProc("EnumPrintersW")
)

const (
	// EnumPrinters : connexions imprimante de l'utilisateur courant.
	printerEnumConnections = 0x00000004
	// Niveau 4 = struct légère {pPrinterName, pServerName, Attributes} —
	// suffisant pour récupérer le nom de connexion `\\serveur\nom`.
	printerInfoLevel4 = 4
)

// printerInfo4 : PRINTER_INFO_4W (winspool). On ne lit que pPrinterName.
type printerInfo4 struct {
	PrinterName *uint16
	ServerName  *uint16
	Attributes  uint32
}

// printerOps : impl shared.PrinterOps de production (Windows winspool).
type printerOps struct {
	log *shared.Logger
}

// ResolveConnection substitue le token serveur (`<se4fs>`) dans la connexion
// logique → la connexion réelle `\\serveur\nom`.
func (o *printerOps) ResolveConnection(spec shared.PrinterSpec) (string, error) {
	conn := substituteTokens(spec.Connection)
	if conn == "" || !strings.HasPrefix(conn, `\\`) {
		return "", fmt.Errorf("connexion imprimante non résoluble : %q", spec.Connection)
	}

	return conn, nil
}

// printServer : nom du serveur SambaEdu résolu localement, préfixé `\\` — sert
// de marqueur de périmètre (on ne gère QUE les connexions vers ce serveur).
func (o *printerOps) printServer() string {
	se4fs := os.Getenv("SE4FS")
	if se4fs == "" {
		se4fs = strings.TrimLeft(os.Getenv("LOGONSERVER"), `\`)
	}
	if se4fs == "" {
		return ""
	}

	return `\\` + se4fs
}

// ListManaged liste les connexions imprimante de l'utilisateur vers le serveur
// SambaEdu (marqueur de périmètre). EnumPrinters(PRINTER_ENUM_CONNECTIONS).
func (o *printerOps) ListManaged() ([]string, error) {
	server := o.printServer()
	if server == "" {
		// Pas de serveur résolu → aucun périmètre à gérer (jamais une erreur :
		// on ne veut pas faire basculer tout le type en error sans serveur).
		return nil, nil
	}
	prefix := strings.ToLower(server) + `\`

	all, err := enumConnectionPrinters()
	if err != nil {
		return nil, err
	}

	managed := []string{}
	for _, name := range all {
		if strings.HasPrefix(strings.ToLower(name), prefix) {
			managed = append(managed, name)
		}
	}

	return managed, nil
}

// Installed : la connexion est-elle déjà présente parmi les connexions de
// l'utilisateur ?
func (o *printerOps) Installed(conn string) (bool, error) {
	all, err := enumConnectionPrinters()
	if err != nil {
		return false, err
	}
	for _, name := range all {
		if strings.EqualFold(name, conn) {
			return true, nil
		}
	}

	return false, nil
}

// Blocked : une imprimante HORS périmètre occupe-t-elle cette connexion ? Pour
// les imprimantes, l'identité EST la connexion `\\serveur\nom` : une connexion
// vers NOTRE serveur ne peut être qu'à nous (gérée). Il n'y a donc pas
// d'homonyme « user » possible sur la même connexion (Windows déduplique par
// nom de connexion). On retourne toujours false : la connexion cible, si
// présente, est forcément la nôtre (ListManaged la liste comme gérée).
func (o *printerOps) Blocked(conn string) (bool, error) {
	return false, nil
}

// Add installe la connexion imprimante (AddPrinterConnectionW).
func (o *printerOps) Add(conn string) error {
	p, err := windows.UTF16PtrFromString(conn)
	if err != nil {
		return err
	}
	r, _, callErr := procAddPrinterConnectionW.Call(uintptr(unsafe.Pointer(p)))
	if r == 0 {
		return fmt.Errorf("AddPrinterConnection(%s) en échec : %v", conn, callErr)
	}

	return nil
}

// Remove désinstalle une connexion gérée (DeletePrinterConnectionW). Best-effort
// idempotent : un échec « déjà absente » n'est pas fatal.
func (o *printerOps) Remove(conn string) error {
	p, err := windows.UTF16PtrFromString(conn)
	if err != nil {
		return err
	}
	r, _, callErr := procDeletePrinterConnectionW.Call(uintptr(unsafe.Pointer(p)))
	if r == 0 {
		// Vérifier si elle est encore présente : absente = succès idempotent.
		installed, e := o.Installed(conn)
		if e == nil && !installed {
			return nil
		}

		return fmt.Errorf("DeletePrinterConnection(%s) en échec : %v", conn, callErr)
	}

	return nil
}

// DefaultPrinter retourne la connexion par défaut courante (GetDefaultPrinterW).
func (o *printerOps) DefaultPrinter() (string, error) {
	var size uint32
	// 1er appel : taille requise (buffer nil → renvoie 0 + size).
	procGetDefaultPrinterW.Call(0, uintptr(unsafe.Pointer(&size)))
	if size == 0 {
		return "", nil // aucun défaut
	}
	buf := make([]uint16, size)
	r, _, _ := procGetDefaultPrinterW.Call(
		uintptr(unsafe.Pointer(&buf[0])),
		uintptr(unsafe.Pointer(&size)),
	)
	if r == 0 {
		return "", nil
	}

	return windows.UTF16ToString(buf), nil
}

// SetDefault pose l'imprimante par défaut (SetDefaultPrinterW).
func (o *printerOps) SetDefault(conn string) error {
	p, err := windows.UTF16PtrFromString(conn)
	if err != nil {
		return err
	}
	r, _, callErr := procSetDefaultPrinterW.Call(uintptr(unsafe.Pointer(p)))
	if r == 0 {
		return fmt.Errorf("SetDefaultPrinter(%s) en échec : %v", conn, callErr)
	}

	return nil
}

// enumConnectionPrinters : noms de toutes les connexions imprimante de
// l'utilisateur (EnumPrintersW niveau 4). Double appel (taille puis données).
func enumConnectionPrinters() ([]string, error) {
	var needed, returned uint32
	// 1er appel : pBuf=nil, cbBuf=0 → ERROR_INSUFFICIENT_BUFFER + `needed`.
	procEnumPrintersW.Call(
		uintptr(printerEnumConnections),
		0, // pName (NULL)
		uintptr(printerInfoLevel4),
		0, // pBuf
		0, // cbBuf
		uintptr(unsafe.Pointer(&needed)),
		uintptr(unsafe.Pointer(&returned)),
	)
	if needed == 0 {
		return nil, nil // aucune connexion
	}

	buf := make([]byte, needed)
	r, _, callErr := procEnumPrintersW.Call(
		uintptr(printerEnumConnections),
		0,
		uintptr(printerInfoLevel4),
		uintptr(unsafe.Pointer(&buf[0])),
		uintptr(needed),
		uintptr(unsafe.Pointer(&needed)),
		uintptr(unsafe.Pointer(&returned)),
	)
	if r == 0 {
		return nil, fmt.Errorf("EnumPrinters en échec : %v", callErr)
	}

	names := make([]string, 0, returned)
	info := unsafe.Slice((*printerInfo4)(unsafe.Pointer(&buf[0])), returned)
	for i := uint32(0); i < returned; i++ {
		if info[i].PrinterName != nil {
			names = append(names, windows.UTF16PtrToString(info[i].PrinterName))
		}
	}

	return names, nil
}
