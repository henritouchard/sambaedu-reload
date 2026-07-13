package main

import (
	"fmt"
	"runtime"
	"sync"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
)

// Fenêtre d'avertissement avant redémarrage d'Explorer (Story 43.4, Epic 43) —
// impl Windows de shared.RefreshOps.ShowRestartNotice, PREMIÈRE fenêtre native
// de l'agent. FFI user32/gdi32 pur (NewLazySystemDLL — patron wallpaper,
// JAMAIS de cgo), exécutée dans la SESSION du compagnon avec les droits du
// user connecté. Le MachineEngine SYSTEM ne reçoit aucune RefreshOps
// (main_windows.go sans diff) : aucune fenêtre en session 0 (piège #5).
//
// Mécanique (D2, piège #1) : la fenêtre appartient au PROCESSUS du compagnon
// — top-most, NON parentée au shell (aucun lien avec la barre des tâches) —
// donc le kill d'explorer.exe ne la ferme PAS : c'est ce qui lui permet de
// SURVIVRE au restart et de couvrir le trou visuel. C'est le dismiss retourné
// qui la ferme, appelé par le compagnon APRÈS le retour de RestartExplorer.
//
// Contraintes Win32 (pièges #2/#3) :
//   - une fenêtre exige une boucle de messages sur LE thread qui l'a créée :
//     goroutine dédiée sous runtime.LockOSThread, création + ShowWindow/
//     UpdateWindow + pump (GetMessage/TranslateMessage/DispatchMessage) ;
//   - la WNDPROC est une callback STABLE au niveau paquet (windows.NewCallback
//     a un quota PROCESS-WIDE — jamais re-créée par appel) ; messages non
//     gérés → DefWindowProcW ;
//   - RegisterClassExW UNE seule fois (sync.Once — ré-enregistrer échoue) ;
//   - le texte est un contrôle STATIC enfant (auto-peint, SS_CENTER) : pas de
//     WM_PAINT/GDI custom, moins de surface de bug.
//
// Best-effort ABSOLU (D4, piège #4) : toute erreur ou lenteur de création =
// warning + dismiss no-op + retour immédiat — la fenêtre ne retarde JAMAIS ni
// n'empêche le restart (le redémarrage est la valeur, l'avertissement un
// confort). dismiss est idempotent (sync.Once) et borné (jamais de blocage),
// appelable même si la fenêtre n'a jamais été créée.

const noticeClassName = "SambaEduRestartNotice"

// Styles/messages/metrics Win32 (piège #7 : WS_POPUP sans bordure ni titre,
// WS_EX_TOPMOST au-dessus de tout, WS_EX_TOOLWINDOW = pas de bouton barre des
// tâches — la barre va justement mourir).
const (
	wsPopup        = 0x80000000
	wsChild        = 0x40000000
	wsVisible      = 0x10000000
	wsExTopmost    = 0x00000008
	wsExToolWindow = 0x00000080
	ssCenter       = 0x00000001

	wmDestroy = 0x0002
	wmClose   = 0x0010
	wmSetFont = 0x0030
	wmTimer   = 0x0113

	swShowNA   = 8 // afficher SANS voler le focus (la session est active)
	smCxScreen = 0 // moniteur principal (piège #7 : centrage simple)
	smCyScreen = 1

	colorWindow = 5 // hbrBackground = COLOR_WINDOW+1 : fond système lisible
	idcArrow    = 32512

	// GetModuleHandleEx flag : NE PAS incrémenter le refcount du module.
	// Contrairement à GetModuleHandleW, GetModuleHandleEx bumpe le refcount avec
	// flags=0 ; sans FreeLibrary correspondant ce serait une fuite à chaque
	// explorer_restart. Ce flag rend l'appel iso-GetModuleHandleW (review 43.4 #1).
	getModuleHandleExUnchangedRefcount = 0x00000002

	// Polices (CreateFontW) : hauteurs NÉGATIVES = hauteur de caractère en
	// unités logiques (patron Win32). Segoe UI, rendu ClearType. Le message est
	// plus gros et lisible que DEFAULT_GUI_FONT ; le spinner est encore plus
	// gros pour être bien visible (Story 43.4 — design fenêtre).
	fwSemibold       = 600
	fwBold           = 700
	defaultCharset   = 1 // DEFAULT_CHARSET
	cleartypeQuality = 5 // CLEARTYPE_QUALITY
	noticeMsgFontH   = -19
	noticeSpinFontH  = -30

	// Spinner animé (piège #4 préservé : l'animation vit sur le thread DÉDIÉ de
	// la fenêtre, elle ne retarde jamais le restart côté compagnon).
	noticeTimerID     = 1
	spinnerIntervalMs = 120

	// Géométrie : fenêtre plus haute pour loger le message (multi-lignes) au
	// dessus du spinner, centrés horizontalement.
	noticeWidth  = 480
	noticeHeight = 184

	noticeMsgX = 24
	noticeMsgY = 28
	noticeMsgW = noticeWidth - 2*noticeMsgX
	noticeMsgH = 72

	noticeSpinX = 24
	noticeSpinY = 120
	noticeSpinW = noticeWidth - 2*noticeSpinX
	noticeSpinH = 44
)

// Bornes dures (piège #4) : ShowRestartNotice ne peut pas pendre la passe —
// création trop lente = on part sans fenêtre ; dismiss n'attend jamais plus
// que sa borne la sortie de la boucle de messages.
const (
	noticeCreateTimeout  = time.Second
	noticeDismissTimeout = 2 * time.Second
)

var (
	modGdi32 = windows.NewLazySystemDLL("gdi32.dll")

	procRegisterClassExW = modUser32.NewProc("RegisterClassExW")
	procCreateWindowExW  = modUser32.NewProc("CreateWindowExW")
	procDefWindowProcW   = modUser32.NewProc("DefWindowProcW")
	procDestroyWindow    = modUser32.NewProc("DestroyWindow")
	procPostMessageW     = modUser32.NewProc("PostMessageW")
	procPostQuitMessage  = modUser32.NewProc("PostQuitMessage")
	procShowWindow       = modUser32.NewProc("ShowWindow")
	procUpdateWindow     = modUser32.NewProc("UpdateWindow")
	procGetMessageW      = modUser32.NewProc("GetMessageW")
	procTranslateMessage = modUser32.NewProc("TranslateMessage")
	procDispatchMessageW = modUser32.NewProc("DispatchMessageW")
	procGetSystemMetrics = modUser32.NewProc("GetSystemMetrics")
	procLoadCursorW      = modUser32.NewProc("LoadCursorW")
	procSendMessageW     = modUser32.NewProc("SendMessageW")
	procSetWindowTextW   = modUser32.NewProc("SetWindowTextW")
	procSetTimer         = modUser32.NewProc("SetTimer")
	procKillTimer        = modUser32.NewProc("KillTimer")
	procCreateFontW      = modGdi32.NewProc("CreateFontW")
	procDeleteObject     = modGdi32.NewProc("DeleteObject")
)

// État de l'UNIQUE fenêtre d'avertissement vivante (un seul geste par passe, et
// ShowRestartNotice→dismiss est synchrone dans runRefreshGesture — jamais deux
// fenêtres à la fois). Touché uniquement sur le thread de la fenêtre (création
// + WNDPROC), donc sans verrou.
var (
	noticeSpinnerHwnd  uintptr   // STATIC du glyphe animé
	noticeSpinnerFrame int       // index courant dans noticeSpinnerPtrs
	noticeSpinnerPtrs  []*uint16 // frames "|/-\\" pré-converties UTF-16
	noticeFonts        []uintptr // HFONT à libérer (DeleteObject) au WM_DESTROY
)

// spinnerFrames : glyphes ASCII universels (aucun risque de « tofu » — présents
// sur toute police). L'enchaînement | / - \ lit comme une rotation.
var spinnerFrames = []string{"|", "/", "-", "\\"}

// noticeWndProcPtr : WNDPROC stable au niveau PAQUET (piège #3 — NewCallback a
// un quota process-wide, la callback n'est JAMAIS re-créée par appel). WM_TIMER
// avance le spinner ; WM_DESTROY arrête le timer, libère les polices et poste
// WM_QUIT (sortie propre de la boucle) ; tout le reste part à DefWindowProcW
// (WM_CLOSE y déclenche DestroyWindow). Exécutée UNIQUEMENT sur le thread de la
// fenêtre → accès sans verrou à l'état paquet.
var noticeWndProcPtr = windows.NewCallback(func(hwnd, msg, wparam, lparam uintptr) uintptr {
	switch msg {
	case wmTimer:
		if noticeSpinnerHwnd != 0 && len(noticeSpinnerPtrs) > 0 {
			noticeSpinnerFrame = (noticeSpinnerFrame + 1) % len(noticeSpinnerPtrs)
			_, _, _ = procSetWindowTextW.Call(noticeSpinnerHwnd, uintptr(unsafe.Pointer(noticeSpinnerPtrs[noticeSpinnerFrame])))
		}

		return 0
	case wmDestroy:
		_, _, _ = procKillTimer.Call(hwnd, noticeTimerID)
		for _, f := range noticeFonts {
			if f != 0 {
				_, _, _ = procDeleteObject.Call(f)
			}
		}
		noticeFonts = nil
		noticeSpinnerHwnd = 0
		_, _, _ = procPostQuitMessage.Call(0)

		return 0
	}
	r, _, _ := procDefWindowProcW.Call(hwnd, msg, wparam, lparam)

	return r
})

// createNoticeFont : CreateFontW Segoe UI, hauteur/graisse données (hauteur
// négative = hauteur de caractère). Retourne 0 en cas d'échec (l'appelant
// retombe alors sur la police par défaut du contrôle — best-effort).
func createNoticeFont(height, weight int32) uintptr {
	face, err := windows.UTF16PtrFromString("Segoe UI")
	if err != nil {
		return 0
	}
	h, _, _ := procCreateFontW.Call(
		uintptr(height), 0, 0, 0,
		uintptr(weight), 0, 0, 0,
		defaultCharset, 0, 0, cleartypeQuality, 0,
		uintptr(unsafe.Pointer(face)),
	)
	runtime.KeepAlive(face)

	return h
}

// wndClassExW : WNDCLASSEXW (layout C — l'alignement Go natif coïncide).
type wndClassExW struct {
	cbSize        uint32
	style         uint32
	lpfnWndProc   uintptr
	cbClsExtra    int32
	cbWndExtra    int32
	hInstance     windows.Handle
	hIcon         windows.Handle
	hCursor       windows.Handle
	hbrBackground windows.Handle
	lpszMenuName  *uint16
	lpszClassName *uint16
	hIconSm       windows.Handle
}

// noticeMsg : MSG (layout C — padding automatique identique).
type noticeMsg struct {
	hwnd    uintptr
	message uint32
	wParam  uintptr
	lParam  uintptr
	time    uint32
	pt      struct{ x, y int32 }
}

var (
	noticeClassOnce sync.Once
	noticeClassErr  error
)

// noticeModuleHandle : HINSTANCE du binaire courant (nom nil = module de
// l'exécutable — x/sys n'exporte pas GetModuleHandleW ; le flag
// UNCHANGED_REFCOUNT évite d'incrémenter le refcount à chaque appel).
func noticeModuleHandle() (windows.Handle, error) {
	var module windows.Handle
	if err := windows.GetModuleHandleEx(getModuleHandleExUnchangedRefcount, nil, &module); err != nil {
		return 0, err
	}

	return module, nil
}

// ensureNoticeClass : RegisterClassExW une seule fois (piège #3 —
// ré-enregistrer la même classe échoue). L'erreur est mémorisée : un premier
// échec rend tous les appels suivants no-op (best-effort assumé).
func ensureNoticeClass() error {
	noticeClassOnce.Do(func() {
		className, err := windows.UTF16PtrFromString(noticeClassName)
		if err != nil {
			noticeClassErr = fmt.Errorf("conversion UTF-16 du nom de classe : %w", err)

			return
		}
		hInstance, err := noticeModuleHandle()
		if err != nil {
			noticeClassErr = fmt.Errorf("GetModuleHandleEx : %w", err)

			return
		}
		cursor, _, _ := procLoadCursorW.Call(0, uintptr(idcArrow))
		wc := wndClassExW{
			cbSize:        uint32(unsafe.Sizeof(wndClassExW{})),
			lpfnWndProc:   noticeWndProcPtr,
			hInstance:     hInstance,
			hCursor:       windows.Handle(cursor),
			hbrBackground: windows.Handle(colorWindow + 1),
			lpszClassName: className,
		}
		atom, _, lastErr := procRegisterClassExW.Call(uintptr(unsafe.Pointer(&wc)))
		runtime.KeepAlive(&wc)
		runtime.KeepAlive(className)
		if atom == 0 {
			noticeClassErr = fmt.Errorf("RegisterClassExW : %v", lastErr)
		}
	})

	return noticeClassErr
}

// createNoticeWindow : création de la fenêtre + STATIC + affichage, sur LE
// thread appelant (verrouillé par l'appelant). Retourne le HWND ou une erreur
// (best-effort : l'appelant logge et renonce, jamais ne bloque).
func createNoticeWindow(text string) (uintptr, error) {
	if err := ensureNoticeClass(); err != nil {
		return 0, err
	}

	className, err := windows.UTF16PtrFromString(noticeClassName)
	if err != nil {
		return 0, fmt.Errorf("conversion UTF-16 du nom de classe : %w", err)
	}
	title, err := windows.UTF16PtrFromString("SambaEdu")
	if err != nil {
		return 0, fmt.Errorf("conversion UTF-16 du titre : %w", err)
	}
	staticClass, err := windows.UTF16PtrFromString("STATIC")
	if err != nil {
		return 0, fmt.Errorf("conversion UTF-16 de STATIC : %w", err)
	}
	label, err := windows.UTF16PtrFromString(text)
	if err != nil {
		return 0, fmt.Errorf("conversion UTF-16 du libellé : %w", err)
	}
	hInstance, err := noticeModuleHandle()
	if err != nil {
		return 0, fmt.Errorf("GetModuleHandleEx : %w", err)
	}

	// Centrage sur le moniteur PRINCIPAL (piège #7) — clampé à l'origine si
	// l'écran est plus petit que la fenêtre.
	screenW, _, _ := procGetSystemMetrics.Call(smCxScreen)
	screenH, _, _ := procGetSystemMetrics.Call(smCyScreen)
	x := (int32(screenW) - noticeWidth) / 2
	y := (int32(screenH) - noticeHeight) / 2
	if x < 0 {
		x = 0
	}
	if y < 0 {
		y = 0
	}

	hwnd, _, lastErr := procCreateWindowExW.Call(
		uintptr(wsExTopmost|wsExToolWindow),
		uintptr(unsafe.Pointer(className)),
		uintptr(unsafe.Pointer(title)),
		uintptr(wsPopup),
		uintptr(x), uintptr(y), noticeWidth, noticeHeight,
		0, 0, uintptr(hInstance), 0,
	)
	if hwnd == 0 {
		return 0, fmt.Errorf("CreateWindowExW : %v", lastErr)
	}

	// (Ré)initialise l'état de la fenêtre unique + pré-convertit les frames du
	// spinner (une seule fois — réutilisées ensuite par la WNDPROC).
	noticeFonts = nil
	noticeSpinnerHwnd = 0
	noticeSpinnerFrame = 0
	if len(noticeSpinnerPtrs) == 0 {
		ptrs := make([]*uint16, 0, len(spinnerFrames))
		for _, f := range spinnerFrames {
			p, convErr := windows.UTF16PtrFromString(f)
			if convErr != nil {
				p, _ = windows.UTF16PtrFromString(" ")
			}
			ptrs = append(ptrs, p)
		}
		noticeSpinnerPtrs = ptrs
	}

	// Polices : message lisible + spinner plus gros. Échec de création = 0 →
	// le contrôle garde sa police par défaut (best-effort, jamais bloquant).
	msgFont := createNoticeFont(noticeMsgFontH, fwSemibold)
	spinFont := createNoticeFont(noticeSpinFontH, fwBold)
	noticeFonts = []uintptr{msgFont, spinFont}

	// Message : STATIC enfant auto-peint (piège #3), centré, multi-lignes.
	message, _, lastErr := procCreateWindowExW.Call(
		0,
		uintptr(unsafe.Pointer(staticClass)),
		uintptr(unsafe.Pointer(label)),
		uintptr(wsChild|wsVisible|ssCenter),
		noticeMsgX, noticeMsgY, noticeMsgW, noticeMsgH,
		hwnd, 0, uintptr(hInstance), 0,
	)
	if message == 0 {
		// Une fenêtre SANS message n'avertit de rien : on détruit et on renonce.
		_, _, _ = procDestroyWindow.Call(hwnd)

		return 0, fmt.Errorf("CreateWindowExW(STATIC message) : %v", lastErr)
	}
	if msgFont != 0 {
		_, _, _ = procSendMessageW.Call(message, wmSetFont, msgFont, 1)
	}

	// Spinner : STATIC dédié affichant le glyphe animé (frame 0 au départ). Un
	// échec ici n'est PAS fatal — le message seul reste informatif.
	spinner, _, _ := procCreateWindowExW.Call(
		0,
		uintptr(unsafe.Pointer(staticClass)),
		uintptr(unsafe.Pointer(noticeSpinnerPtrs[0])),
		uintptr(wsChild|wsVisible|ssCenter),
		noticeSpinX, noticeSpinY, noticeSpinW, noticeSpinH,
		hwnd, 0, uintptr(hInstance), 0,
	)
	if spinner != 0 {
		noticeSpinnerHwnd = spinner
		if spinFont != 0 {
			_, _, _ = procSendMessageW.Call(spinner, wmSetFont, spinFont, 1)
		}
	}

	// Afficher SANS voler le focus, puis peindre tout de suite (piège #2 :
	// au moins un UpdateWindow avant le kill, sinon la fenêtre peut rester
	// blanche pendant le restart).
	_, _, _ = procShowWindow.Call(hwnd, swShowNA)
	_, _, _ = procUpdateWindow.Call(hwnd)

	// Lance l'animation UNE FOIS la fenêtre affichée (WM_TIMER → WNDPROC, même
	// thread). Sans spinner créé, inutile de timer.
	if noticeSpinnerHwnd != 0 {
		_, _, _ = procSetTimer.Call(hwnd, noticeTimerID, spinnerIntervalMs, 0)
	}

	runtime.KeepAlive(className)
	runtime.KeepAlive(title)
	runtime.KeepAlive(staticClass)
	runtime.KeepAlive(label)

	return hwnd, nil
}

// pumpNoticeMessages : boucle de messages classique — sort sur WM_QUIT (posté
// par la WNDPROC à WM_DESTROY) ou sur erreur. Sur erreur de GetMessage (-1), on
// détruit la fenêtre avant de sortir : sans pump, un WM_CLOSE posté par dismiss
// ne serait jamais dépilé et laisserait une fenêtre top-most figée à l'écran
// jusqu'à la fin de session (review 43.4 #3).
func pumpNoticeMessages(hwnd uintptr) {
	var msg noticeMsg
	for {
		r, _, _ := procGetMessageW.Call(uintptr(unsafe.Pointer(&msg)), 0, 0, 0)
		ret := int32(uint32(r))
		if ret == 0 {
			return // WM_QUIT : sortie propre
		}
		if ret == -1 {
			// GetMessage en erreur : DestroyWindow est légal ici (thread créateur).
			_, _, _ = procDestroyWindow.Call(hwnd)

			return
		}
		_, _, _ = procTranslateMessage.Call(uintptr(unsafe.Pointer(&msg)))
		_, _, _ = procDispatchMessageW.Call(uintptr(unsafe.Pointer(&msg)))
	}
}

// noticeState : cycle de vie d'UNE fenêtre d'avertissement (une par geste).
type noticeState struct {
	mu     sync.Mutex
	hwnd   uintptr
	closed bool
	done   chan struct{} // fermé quand la goroutine UI a terminé
}

// dismiss : demande la fermeture (WM_CLOSE → DefWindowProc → DestroyWindow →
// WM_DESTROY → PostQuitMessage → sortie de la boucle) puis attend la fin de la
// goroutine UI, BORNÉ (piège #4 : jamais de blocage). Sûre même si la fenêtre
// n'a jamais été créée (hwnd 0 : rien à poster, done se ferme seul) ou si la
// création aboutit APRÈS le timeout de l'appelant (closed=true → la goroutine
// détruit immédiatement).
func (n *noticeState) dismiss() {
	n.mu.Lock()
	n.closed = true
	hwnd := n.hwnd
	n.mu.Unlock()

	if hwnd != 0 {
		// PostMessage est légal cross-thread (contrairement à DestroyWindow,
		// réservé au thread créateur — c'est la boucle qui détruira).
		_, _, _ = procPostMessageW.Call(hwnd, wmClose, 0, 0)
	}
	select {
	case <-n.done:
	case <-time.After(noticeDismissTimeout):
		// Boucle muette : on n'attend pas plus — la fenêtre mourra avec le
		// processus au pire (compagnon = vie de la session).
	}
}

// ShowRestartNotice : impl shared.RefreshOps (Story 43.4). Crée la fenêtre
// top-most « patientez » sur une goroutine dédiée (thread OS verrouillé —
// piège #2), attend son affichage BORNÉ, et retourne un dismiss idempotent +
// borné. Best-effort ABSOLU : tout échec = warning + dismiss no-op, le
// restart n'est JAMAIS retardé au-delà de noticeCreateTimeout ni empêché.
func (o *refreshOps) ShowRestartNotice(text string) (bool, func()) {
	n := &noticeState{done: make(chan struct{})}
	ready := make(chan bool, 1) // true = fenêtre affichée ; buffer : la goroutine n'attend jamais l'appelant

	go func() {
		defer close(n.done)
		// La boucle de messages DOIT tourner sur le thread créateur (piège #2).
		runtime.LockOSThread()
		defer runtime.UnlockOSThread()

		hwnd, err := createNoticeWindow(text)
		if err != nil {
			if o.log != nil {
				o.log.Warningf("Fenêtre d'avertissement explorer_restart non créée : %v — le redémarrage part sans avertissement (best-effort).", err)
			}
			ready <- false

			return
		}

		n.mu.Lock()
		if n.closed {
			// dismiss a doublé la création (timeout côté appelant) : détruire
			// tout de suite (même thread), jamais de fenêtre orpheline. Non
			// affichée du point de vue de l'appelant → shown=false.
			n.mu.Unlock()
			_, _, _ = procDestroyWindow.Call(hwnd)
			ready <- false

			return
		}
		n.hwnd = hwnd
		n.mu.Unlock()
		ready <- true

		pumpNoticeMessages(hwnd)
	}()

	// Attente BORNÉE de l'affichage (piège #4) : la fenêtre doit être visible
	// avant le lead time du compagnon — sinon on part sans elle (shown=false :
	// le compagnon ne paie alors PAS le lead time, review 43.4 #2).
	shown := false
	select {
	case shown = <-ready:
	case <-time.After(noticeCreateTimeout):
		if o.log != nil {
			o.log.Warningf("Fenêtre d'avertissement explorer_restart : création trop lente (> %s) — le redémarrage part sans attendre (best-effort).", noticeCreateTimeout)
		}
	}

	var once sync.Once

	return shown, func() { once.Do(n.dismiss) }
}
