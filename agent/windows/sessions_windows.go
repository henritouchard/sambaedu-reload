package main

import (
	"fmt"
	"strings"
	"unsafe"

	"golang.org/x/sys/windows"

	"sambaedu/agent/shared"
)

// Énumération des sessions interactives — côté SYSTEM (Story 24.6,
// décision n° 2 : WTS API en Win32 plat, zéro COM, zéro parsing localisé —
// remplace le CIM Win32_LogonSession du spike PS ; jamais quser, sortie
// localisée).
//
// L'identité est résolue ICI, côté SYSTEM — le processus user ne déclare
// jamais la sienne (anti-usurpation par construction). Filtres ACQUIS de la
// review 24.3 #1 :
//   - liste BLANCHE `S-1-5-21-` : seuls les comptes users réels (domaine OU
//     locaux) portent un SID S-1-5-21-<machine/domaine>-RID. Tout le reste
//     (pseudo-sessions DWM S-1-5-90-, UMFD S-1-5-96-, comptes virtuels de
//     service, builtin) n'a aucun état à tirer — une liste noire serait
//     structurellement incomplète ;
//   - garde login non vide : un WTSUserName vide (session système, listener)
//     produirait un fetch `?user=` vide + cache parasite ;
//   - dédoublonnage par SID (un user peut avoir plusieurs sessions).
//
// Résolution du double-lookup SID (review 24.3 #6, résolu AU PORTAGE) : le
// fetch résout le SID par LookupAccountName (LookupSID) sur DOMAIN\user, le
// compagnon par le SID de SON token de processus — les deux sortent du même
// sous-système de sécurité Win32 (LSA), contrairement au couple
// CIM Win32_Account.SID / WindowsIdentity du spike. L'équivalence est
// documentée dans session-companion.md §10 (limite résiduelle AzureAD
// S-1-12-1-* : hors liste blanche des deux côtés, donc cohérent).

// WTS_INFO_CLASS (wtsapi32) — seuls les deux champs consommés.
const (
	wtsUserName   = 5
	wtsDomainName = 7
)

var (
	modWtsapi32                  = windows.NewLazySystemDLL("wtsapi32.dll")
	procWTSQuerySessionInformat  = modWtsapi32.NewProc("WTSQuerySessionInformationW")
)

// wtsQuerySessionString : WTSQuerySessionInformationW → string Go (UTF-16).
func wtsQuerySessionString(sessionID uint32, infoClass uint32) (string, error) {
	var buf *uint16
	var bytesReturned uint32
	r1, _, err := procWTSQuerySessionInformat.Call(
		0, // WTS_CURRENT_SERVER_HANDLE
		uintptr(sessionID),
		uintptr(infoClass),
		uintptr(unsafe.Pointer(&buf)),
		uintptr(unsafe.Pointer(&bytesReturned)),
	)
	if r1 == 0 {
		return "", fmt.Errorf("WTSQuerySessionInformation(%d, %d) : %w", sessionID, infoClass, err)
	}
	defer windows.WTSFreeMemory(uintptr(unsafe.Pointer(buf)))

	return windows.UTF16PtrToString(buf), nil
}

// enumerateInteractiveSessions : retourne les sessions interactives
// {login COURT, SID} — états Active et Disconnected (un user déconnecté
// reste loggé : son état de session reste à tirer, iso CachedInteractive du
// spike). Le login court vient de WTSUserName (jamais DOMAIN\user vers
// `?user=` — le strip du domaine est structurel, pas du parsing).
func enumerateInteractiveSessions() ([]shared.Session, error) {
	var sessionInfo *windows.WTS_SESSION_INFO
	var count uint32
	if err := windows.WTSEnumerateSessions(0, 0, 1, &sessionInfo, &count); err != nil {
		return nil, fmt.Errorf("WTSEnumerateSessions : %w", err)
	}
	defer windows.WTSFreeMemory(uintptr(unsafe.Pointer(sessionInfo)))

	entries := unsafe.Slice(sessionInfo, count)

	bySid := map[string]bool{}
	sessions := []shared.Session{}
	for _, entry := range entries {
		if entry.State != windows.WTSActive && entry.State != windows.WTSDisconnected {
			continue
		}

		login, err := wtsQuerySessionString(entry.SessionID, wtsUserName)
		if err != nil {
			continue // session système/transitoire : rien à tirer
		}
		// Garde login non vide (review 24.3 #1).
		if strings.TrimSpace(login) == "" {
			continue
		}
		domain, _ := wtsQuerySessionString(entry.SessionID, wtsDomainName)

		account := login
		if domain != "" {
			account = domain + `\` + login
		}
		sid, _, _, err := windows.LookupSID("", account)
		if err != nil || sid == nil {
			continue // compte non résoluble (orphelin) : skip
		}
		sidString := sid.String()

		// Liste BLANCHE (review 24.3 #1) + dédoublonnage par SID.
		if !strings.HasPrefix(sidString, "S-1-5-21-") || bySid[sidString] {
			continue
		}
		bySid[sidString] = true
		sessions = append(sessions, shared.Session{Login: login, SID: sidString})
	}

	return sessions, nil
}

// interactiveSessionIDs : SessionID WTS des sessions interactives (Active ou
// Disconnected) — Story 27.1bis. Réutilise l'énumération WTS vet-clean de
// 24.6 (WTSEnumerateSessions, Pointer→uintptr uniquement) plutôt que de
// déréférencer le lpEventData (uintptr→Pointer interdit par vet) d'un
// session-change. Sur un logon, la nouvelle session apparaît dans cette
// énumération : le service écrit overlay.json pour chacune (idempotent, cheap).
func interactiveSessionIDs() ([]uint32, error) {
	var sessionInfo *windows.WTS_SESSION_INFO
	var count uint32
	if err := windows.WTSEnumerateSessions(0, 0, 1, &sessionInfo, &count); err != nil {
		return nil, fmt.Errorf("WTSEnumerateSessions : %w", err)
	}
	defer windows.WTSFreeMemory(uintptr(unsafe.Pointer(sessionInfo)))

	entries := unsafe.Slice(sessionInfo, count)

	ids := make([]uint32, 0, count)
	for _, entry := range entries {
		// On inclut DÉLIBÉRÉMENT les sessions Disconnected (#5, limite assumée) :
		// un user verrouillé/déconnecté (RDP détaché, bascule rapide) garde un
		// profil monté dont l'overlay doit rester à jour pour son retour. La
		// (ré)écriture est idempotente et cheap (overlay.json par session) — pas
		// d'effet de bord à écrire pour une session déconnectée.
		if entry.State != windows.WTSActive && entry.State != windows.WTSDisconnected {
			continue
		}
		ids = append(ids, entry.SessionID)
	}

	return ids, nil
}

// currentProcessSID : SID du token du PROCESSUS COURANT (compagnon —
// décision n° 2) : même sous-système de sécurité que LookupSID côté fetch.
// Uniquement pour trouver SON cache et SON drop, jamais transmis à personne.
func currentProcessSID() (string, error) {
	token, err := windows.OpenCurrentProcessToken()
	if err != nil {
		return "", fmt.Errorf("OpenCurrentProcessToken : %w", err)
	}
	defer token.Close()

	user, err := token.GetTokenUser()
	if err != nil {
		return "", fmt.Errorf("GetTokenUser : %w", err)
	}

	return user.User.Sid.String(), nil
}
