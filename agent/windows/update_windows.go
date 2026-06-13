package main

import (
	"fmt"
	"os"
	"unsafe"

	"golang.org/x/sys/windows"

	"sambaedu/agent/shared"
)

// Primitives Windows de l'auto-update (Story 25.2, décision n° 2). Ce fichier
// est Windows-only (build tag _windows.go) : il N'EST PAS compilé ni testé sur
// l'hôte Linux. La logique de décision/download/vérif-hash/orchestration vit
// dans shared/update.go (testée Linux avec des stubs de ces fonctions, NFR8) ;
// seules la vérif de signature réelle et le swap+restart-SCM réel sont ici,
// validés au SMOKE sur poste de lab.
//
// ─── Choix Authenticode : WinVerifyTrust (x/sys/windows) ─────────────────────
// La vérification de signature utilise WinVerifyTrustEx de golang.org/x/sys/
// windows (déjà au go.mod, AUCUNE dépendance neuve) plutôt qu'un shell-out
// `Get-AuthenticodeSignature`. Rationale (tranché en dev, décision n° 3) :
//   - x/sys/windows EXPOSE nativement WinVerifyTrustEx + WINTRUST_ACTION_
//     GENERIC_VERIFY_V2 + WinTrustData/WinTrustFileInfo + les constantes WTD_*
//     (zéro FFI manuel à écrire, zéro struct Win32 à redéclarer) : le risque
//     « erreur silencieuse sur une struct mal alignée » du FFI brut est écarté ;
//   - le code de retour de WinVerifyTrustEx est BINAIRE (nil = signé + chaîne
//     de confiance valide jusqu'à une CA de confiance machine ; tout autre =
//     rejet) — pas de parsing de chaîne localisée comme « Valid »/« Valide »
//     qu'imposerait Get-AuthenticodeSignature (fragile en locale FR) ;
//   - in-process : pas de spawn de powershell.exe (plus rapide, pas de fenêtre
//     console, pas de dépendance à la politique d'exécution PowerShell).
// Le shell-out reste l'échappatoire admise si WinVerifyTrust posait problème
// terrain — non retenu ici car l'API native couvre exactement le besoin.

// verifyAuthenticode vérifie la signature Authenticode du fichier `path`
// (binaire STAGÉ, jamais le fichier en place — décision n° 5). nil = signé +
// chaîne de confiance valide ; erreur = à JETER sans installer (porte 2,
// AC1/AC3). WTD_REVOKE_NONE : pas de check de révocation en ligne (un poste de
// salle peut être hors-ligne au moment de l'update ; la confiance de la chaîne
// jusqu'à la CA interne machine suffit, brief #31).
func verifyAuthenticode(path string) error {
	pathUTF16, err := windows.UTF16PtrFromString(path)
	if err != nil {
		return fmt.Errorf("conversion du chemin %q : %w", path, err)
	}

	fileInfo := &windows.WinTrustFileInfo{
		Size:     uint32(unsafe.Sizeof(windows.WinTrustFileInfo{})),
		FilePath: pathUTF16,
	}
	data := &windows.WinTrustData{
		Size:                            uint32(unsafe.Sizeof(windows.WinTrustData{})),
		UIChoice:                        windows.WTD_UI_NONE,
		RevocationChecks:                windows.WTD_REVOKE_NONE,
		UnionChoice:                     windows.WTD_CHOICE_FILE,
		StateAction:                     windows.WTD_STATEACTION_VERIFY,
		FileOrCatalogOrBlobOrSgnrOrCert: unsafe.Pointer(fileInfo),
	}

	action := windows.WINTRUST_ACTION_GENERIC_VERIFY_V2
	verifyErr := windows.WinVerifyTrustEx(windows.InvalidHWND, &action, data)

	// Libération du contexte de vérification (WTD_STATEACTION_CLOSE) — best
	// effort, toujours appelé (pcwszFilePath/StateData restent référencés tant
	// qu'on n'a pas fermé). On ne masque jamais verifyErr avec une erreur de
	// close.
	data.StateAction = windows.WTD_STATEACTION_CLOSE
	_ = windows.WinVerifyTrustEx(windows.InvalidHWND, &action, data)

	if verifyErr != nil {
		return fmt.Errorf("signature Authenticode rejetée : %w", verifyErr)
	}

	return nil
}

// swapExitCode : code de sortie non-graceux après un swap réussi (Option A,
// décision review 25.2). NON nul : un service qui sort avec un code ≠ 0 est vu
// par le SCM comme une terminaison ANORMALE (le svc.Handler n'a PAS signalé
// SERVICE_STOPPED via un arrêt gracieux), ce qui déclenche les RecoveryActions
// (ServiceRestart ×3, install_windows.go) → relance avec le binaire vN+1.
// Un os.Exit court-circuite la boucle svc.Run : le SCM ne reçoit jamais de
// SERVICE_STOPPED « propre », il compte un échec et applique la recovery.
const swapExitCode = 42

// swapAndRestart : swap atomique anti-brique + sortie NON-GRACIEUSE (Story
// 25.2, Option A). Côté windows/, ce wrapper ne porte QUE les spécificités OS :
//   - résolution du chemin RÉEL du binaire en place (os.Executable, Program
//     Files) ;
//   - le déclencheur de redémarrage = sortie non-gracieuse os.Exit(≠0), injecté
//     dans shared.PerformSwap comme triggerRestart (testable Linux via stub).
//
// Le CŒUR anti-brique (copie-atomique→re-hash .new→rename→rollback) vit dans
// shared/swap.go (PerformSwap), RÉELLEMENT testé sur Linux. `expectedHash` =
// hash manifest : le binaire RÉELLEMENT mis en place est re-vérifié à sa
// position finale (M2) avant le rename.
//
// Invariant : à AUCUN instant agent.exe n'est absent ou corrompu. Si le swap
// échoue, PerformSwap rend la main avec une erreur (ancien binaire intact,
// triggerRestart jamais appelé) et os.Exit n'est PAS exécuté → l'agent en place
// continue. Si le swap réussit, triggerRestart (os.Exit) tue le process et
// PerformSwap ne rend jamais la main.
//
// POURQUOI os.Exit(≠0) déclenche la recovery : un service Windows ne redémarre
// pas après un arrêt GRACIEUX (SERVICE_STOPPED signalé proprement). En sortant
// par os.Exit (le process meurt sans passer par le retour propre du
// svc.Handler), le SCM voit une terminaison anormale et applique les
// RecoveryActions configurées à l'install (ServiceRestart). C'est le mécanisme
// EXACT que l'ancien stop+start in-process tentait d'imiter — en plus simple et
// sans deadlock (on ne s'arrête plus soi-même depuis sa propre goroutine).
func swapAndRestart(stagedPath, version, expectedHash string) error {
	exe, err := os.Executable()
	if err != nil {
		return fmt.Errorf("chemin du binaire en cours : %w", err)
	}

	// triggerRestart : appelé par PerformSwap APRÈS un swap réussi UNIQUEMENT.
	// Log local clair AVANT la sortie (le contre de l'Option A = ça ressemble à
	// un plantage dans le journal d'événements Windows — on documente l'intention).
	triggerRestart := func() {
		fmt.Fprintf(os.Stderr, "Auto-update : swap %s→%s réussi (binaire en place permuté), sortie volontaire (code %d) pour relance par la recovery SCM.\n", shared.Version, version, swapExitCode)
		os.Exit(swapExitCode)
	}

	return shared.PerformSwap(exe, stagedPath, expectedHash, triggerRestart)
}

// cleanupOldBinary : suppression best-effort de agent.exe.old au démarrage du
// nouvel agent (l'ancienne image n'est plus verrouillée une fois son process
// mort). Jamais d'erreur propagée : un .old résiduel sera supprimé au prochain
// swap (étape (a)).
func cleanupOldBinary() {
	exe, err := os.Executable()
	if err != nil {
		return
	}
	_ = os.Remove(exe + ".old")
	_ = os.Remove(exe + ".new")
}

// setUpdateACL : ACL du répertoire de staging update\ (Story 25.2) — SYSTEM F
// + Administrators F UNIQUEMENT, PAS de Users:R (un binaire stagé n'est pas un
// asset affiché, contrairement à assets\). Réutilise setAgentACL (le canal
// agent : SYSTEM + Admins, héritage retiré).
func setUpdateACL(path string) error {
	return setAgentACL(path)
}

// Garde de compilation : les primitives respectent les signatures injectées
// dans le type shared.Agent (VerifyAuthenticode / SwapAndRestart / UpdateACL).
var (
	_ func(string) error                 = verifyAuthenticode
	_ func(string, string, string) error = swapAndRestart
	_ func(string) error                 = setUpdateACL
)
