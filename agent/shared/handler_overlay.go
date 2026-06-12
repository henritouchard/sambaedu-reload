package shared

import (
	"os"

	"golang.org/x/text/unicode/norm"
)

// Handler `overlay` (aggregate / strict / session) — Story 24.6, portage de
// handlers/Overlay.ps1 (24.4). OS-agnostique par injection (chemin per-user,
// COMPUTERNAME, détection Rainmeter) : la composition ET le handler complet
// sont testés sur l'hôte ; agent/windows ne fait que le câbler.
//
//   - test  : le fichier overlay.json existant est-il identique au document
//     cible ? Comparaison de CONTENU après normalisation NFC (le serveur
//     émet NFC mais un fichier réécrit par un autre outil Windows peut être
//     NFD ; le document porte fullname/room accentués). x/text/unicode/norm
//     est la dépendance annoncée par 24.5 « le moment venu » — justifiée au
//     README (même niveau de confiance que x/sys).
//   - apply : écriture ATOMIQUE (tmp PID + rename) du document composé,
//     UTF-8 sans BOM, sous %LOCALAPPDATA%. Mode `strict` (constante
//     provider) : toute divergence est réécrite — le moteur rapporte `drift`.
//
// Rainmeter ABSENT du poste (amendement Henri 2026-06-12) : comportement
// gracieux — le handler compose et écrit quand même overlay.json (la
// ressource config EST convergée → statut machine d'états normal, JAMAIS
// `error` du seul fait de l'absence) + log info. Installer une application
// n'est pas du desired-state config (livraison Rainmeter = workflow
// d'install des postes).
type OverlayHandler struct {
	// Path : fichier overlay.json per-user
	// (%LOCALAPPDATA%\SambaEdu\Agent\overlay.json en production).
	Path string

	// ComputerName : nom LOCAL du poste (machine.name — jamais demandé au
	// serveur).
	ComputerName string

	// RainmeterPresent : détection de présence du render — purement
	// informative, n'influe JAMAIS sur le statut de convergence. nil =
	// considéré présent (pas de log).
	RainmeterPresent func() bool

	Log *Logger
}

// Test : contenu identique après NFC ? Fichier absent = non conforme.
func (h *OverlayHandler) Test(items []StateItem) (bool, error) {
	target := ComposeOverlayDocument(items, h.ComputerName)

	current, err := os.ReadFile(h.Path)
	if err != nil {
		return false, nil // absent → apply écrira
	}

	return norm.NFC.String(string(current)) == norm.NFC.String(target), nil
}

// Apply : écriture atomique du document composé. Idempotent (même contenu =
// même fichier).
func (h *OverlayHandler) Apply(items []StateItem) error {
	target := ComposeOverlayDocument(items, h.ComputerName)

	if err := WriteFileAtomic(h.Path, []byte(target)); err != nil {
		return err
	}

	if h.RainmeterPresent != nil && !h.RainmeterPresent() {
		logInfo(h.Log, "rainmeter absent, overlay non rendu (overlay.json convergé quand même — install render = workflow postes, hors desired-state).")
	}

	return nil
}
