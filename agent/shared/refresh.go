package shared

import (
	"strings"
	"time"
)

// Échelle de rafraîchissement du compagnon (Story 43.1, Epic 43 — application
// immédiate). Après une écriture HKCU EFFECTIVE, l'Explorer déjà ouvert ne
// relit pas ses réglages tout seul : le compagnon exécute UN geste Windows en
// fin de passe — le plus fort requis par les items effectivement changés :
//
//	shell_notify < policy_broadcast < explorer_restart
//
// Le geste est déclaré par le SERVEUR via le champ optionnel `refresh` du
// PAYLOAD des items `registry`/`registry_list` (sous-structure provider-defined,
// contrat §3.2 — le wrapper 4 clés §3 est INTACT, golden inchangés en 43.1 ;
// l'émission serveur du hint est la Story 43.2).
//
// Frontières (D1) :
//   - les handlers ACCUMULENT le besoin pendant Apply (max des items changés,
//     plancher shell_notify pour tout changement HKCU — D2) et l'exposent via
//     l'interface optionnelle RefreshRequester ;
//   - le COMPAGNON consomme en toute fin de RunPass (un seul geste par passe,
//     zéro geste si passe stable) — engine.go reste sans AUCUN diff ;
//   - le service SYSTEM (MachineEngine) ne consomme JAMAIS : aucun geste en
//     session 0, y compris sur le fan-out HKU (piège n° 9 de 35.3, préservé
//     structurellement — isUserHive rend déjà false pour HKLM/HKU).

// RefreshLevel : niveau de l'échelle, ORDONNÉ (la comparaison numérique EST la
// sémantique « plus fort que » — maxRefreshLevel s'appuie dessus).
type RefreshLevel int

const (
	// RefreshNone : aucun geste requis (item sans changement, ou aucun item
	// HKCU changé dans la passe).
	RefreshNone RefreshLevel = iota
	// RefreshShellNotify : SHChangeNotify(SHCNE_ASSOCCHANGED) — l'Explorer
	// relit ses réglages de vue (Hidden, HideFileExt). PLANCHER de tout
	// changement HKCU effectif (migration iso-comportement du registryNotifier
	// historique, D2).
	RefreshShellNotify
	// RefreshPolicyBroadcast : SendMessageTimeout(HWND_BROADCAST,
	// WM_SETTINGCHANGE, "Policy") — les applis relisent leurs policies.
	RefreshPolicyBroadcast
	// RefreshExplorerRestart : terminer puis relancer explorer.exe de la
	// session du compagnon (droits user, garde anti-double-lancement) — le
	// geste le plus fort, pour les clés que l'Explorer ne lit qu'au démarrage
	// (ex. Policies\Explorer\RestrictRun).
	RefreshExplorerRestart
)

// String : libellé wire/logs du niveau (mêmes tokens que le vocabulaire
// serveur du champ `refresh`).
func (l RefreshLevel) String() string {
	switch l {
	case RefreshShellNotify:
		return "shell_notify"
	case RefreshPolicyBroadcast:
		return "policy_broadcast"
	case RefreshExplorerRestart:
		return "explorer_restart"
	default:
		return "none"
	}
}

// ParseRefreshLevel : lecture INDULGENTE du hint `refresh` d'un payload (D3).
// Valeur absente, vide ou INCONNUE ⇒ RefreshNone — JAMAIS une enveloppe
// invalide, jamais un {status: error} : la validation stricte du vocabulaire
// est serveur (AuthoringGuard 43.2, « rejet à l'authoring, jamais au
// runtime »). Un binaire antérieur ignore déjà le champ (parseurs indulgents,
// piège n° 1) : RefreshNone + plancher D2 = comportement actuel, additif sûr
// (NFR-A4). Le log debug du hint inconnu vit chez l'appelant (desiredSpecs —
// ce parseur pur n'a pas de logger).
func ParseRefreshLevel(raw string) RefreshLevel {
	switch strings.ToLower(strings.TrimSpace(raw)) {
	case "shell_notify":
		return RefreshShellNotify
	case "policy_broadcast":
		return RefreshPolicyBroadcast
	case "explorer_restart":
		return RefreshExplorerRestart
	default:
		return RefreshNone
	}
}

// logUnknownRefreshHint : trace (debug) un hint `refresh` NON VIDE que
// ParseRefreshLevel a rendu RefreshNone (vocabulaire inconnu) — l'item reste
// valide (D3 indulgent, jamais un {status: error}), le plancher D2 s'applique
// si l'item change. Appelé par desiredSpecs/desiredListSpecs depuis le chemin
// Test SEULEMENT (review 43.1 #3 : Apply re-parse les mêmes items dans la même
// passe — logHints=false y évite la ligne dupliquée ; le parseur pur n'a pas
// de logger). Champ absent/vide : silencieux (le cas nominal de tout le parc
// pré-43.2 — pas de bruit de log).
func logUnknownRefreshHint(log *Logger, payload any, identity string) {
	m, ok := payload.(map[string]any)
	if !ok {
		return
	}
	raw, ok := m["refresh"].(string)
	if ok && strings.TrimSpace(raw) != "" && ParseRefreshLevel(raw) == RefreshNone {
		logDebug(log, "Hint refresh inconnu %q sur %s : ignoré (plancher shell_notify si changement HKCU).", raw, identity)
	}
}

// maxRefreshLevel : le plus fort des deux niveaux (l'échelle est ordonnée).
func maxRefreshLevel(a, b RefreshLevel) RefreshLevel {
	if b > a {
		return b
	}

	return a
}

// RefreshOps : les trois gestes Windows, injectés (testable hôte — D4).
// L'impl de production vit dans agent/windows/refresh_windows.go (FFI
// NewLazySystemDLL, jamais de cgo) ; un fake en mémoire couvre les tests.
// nil côté Companion = no-op (hôte, non-Windows). CHAQUE geste est
// best-effort : un échec = warning loggé par l'appelant, JAMAIS une erreur de
// passe ni un statut d'item (les clés SONT écrites ; au pire l'effet attend le
// relogon — sémantique du registryNotifier historique, conservée).
type RefreshOps interface {
	// ShellNotify émet SHChangeNotify(SHCNE_ASSOCCHANGED, SHCNF_IDLIST) —
	// aucun retour exploitable côté Win32 (void).
	ShellNotify()
	// PolicyBroadcast émet SendMessageTimeout(HWND_BROADCAST, WM_SETTINGCHANGE,
	// 0, "Policy", SMTO_ABORTIFHUNG, timeout borné).
	PolicyBroadcast() error
	// RestartExplorer termine puis relance explorer.exe de la session du
	// compagnon (droits user, jamais d'élévation), avec garde
	// anti-double-lancement (piège n° 3).
	RestartExplorer() error
	// ShowRestartNotice affiche la fenêtre d'avertissement « patientez »
	// AVANT le redémarrage d'Explorer (Story 43.4, D2/D3). La fenêtre vit
	// dans le PROCESS du compagnon (jamais parentée au shell) : elle SURVIT
	// au kill d'explorer.exe et c'est le dismiss retourné qui la ferme,
	// appelé par le compagnon APRÈS le retour de RestartExplorer. Contrat :
	//   - best-effort ABSOLU (D4) : échec/lenteur de création = warning côté
	//     impl + dismiss no-op retourné — JAMAIS nil, JAMAIS bloquant, le
	//     restart part quand même (l'avertissement est un confort) ;
	//   - shown indique si la fenêtre a réellement été affichée : false sur
	//     échec/timeout de création. Le compagnon ne paie le lead time (délai
	//     de lecture) QUE si shown est true — pas de délai mort avant le kill
	//     quand il n'y a rien à lire (review 43.4 #2) ;
	//   - dismiss est IDEMPOTENT (double appel sans effet) et BORNÉ (ne pend
	//     jamais la passe), appelable même si la fenêtre n'a jamais existé.
	// Appelée UNIQUEMENT depuis la branche explorer_restart NON throttlée de
	// runRefreshGesture (D1, pièges #1/#6) — jamais pour les gestes faibles,
	// jamais en passe stable, jamais côté SYSTEM/session 0 (piège #5).
	ShowRestartNotice(text string) (shown bool, dismiss func())
}

// restartNoticeText : libellé de la fenêtre d'avertissement (Story 43.4, D6) —
// court, français, sans jargon ; purement informatif (aucun bouton, aucune
// interaction : la fenêtre est auto-fermée par le compagnon).
const restartNoticeText = "Application des réglages en cours — l'écran va se rafraîchir, merci de patienter quelques secondes."

// restartNoticeLeadTime : bref délai de lecture entre l'affichage de la
// fenêtre et le kill du shell (Story 43.4, D5) — borné et constant, encouru
// UNIQUEMENT sur la branche restart (jamais au régime stable : le surcoût ne
// frappe que le logon où un réglage change réellement). Défaut du champ
// Companion.NoticeLeadTime (injectable — tests).
const restartNoticeLeadTime = 2 * time.Second

// RefreshRequester : interface OPTIONNELLE qu'un handler peut implémenter pour
// déclarer le geste de rafraîchissement requis par sa DERNIÈRE passe (patron
// des interfaces additives DetailReporter/InventoryReporter — mais consommée
// par le COMPAGNON en fin de RunPass, jamais par le moteur : engine.go zéro
// diff, D1). TakeRefreshRequest retourne le niveau MAX accumulé pendant
// l'Apply de LA passe et remet l'accumulation à zéro (consommation par passe —
// pas de geste fantôme au tick suivant). Le service SYSTEM n'appelle jamais
// Take… : l'accumulation y reste vide de toute façon (gate isUserHive) et
// n'est pas consommée.
type RefreshRequester interface {
	TakeRefreshRequest() RefreshLevel
}
