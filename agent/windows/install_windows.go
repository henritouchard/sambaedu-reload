package main

import (
	"fmt"
	"os"
	"time"

	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"

	"sambaedu/agent/shared"
)

// installService : remplace Install-SambaEduAgent.ps1 (24.2). Le binaire
// courant est enregistré comme service SYSTEM (LocalSystem = défaut SCM),
// démarrage automatique, relance 30 s sur crash. Idempotent : un service
// existant est arrêté/supprimé puis recréé (iso-PS).
//
// Story 25.4 (Fork 1 = B) : la garde « token présent » est RELÂCHÉE. L'install
// procède SANS token — c'est le chemin du poste migré (GPO-dispatcher figée),
// qui n'a pas encore de token : il s'auto-enrôle (porte 2) une fois le service
// posé. La suppression de la garde est bénigne : sans token, le run loop ne
// converge pas (il poste sa demande d'enrôlement + check-ins légers), il ne se
// brique jamais. La config/arborescence/ACL/SCM restent écrites à l'identique.
// Sur un poste DÉJÀ enrôlé dont l'agent est briqué, la réinstall CONSERVE le
// token (hors périmètre install, piège n° 11) → convergence directe.
//
// Le binaire doit être exécuté depuis son emplacement DÉFINITIF (recommandé :
// C:\Program Files\SambaEdu\Agent\agent.exe) : le SCM enregistre ce chemin.
func installService(serverURL string, intervalSeconds int) error {
	store := &shared.Store{SetACL: setAgentACL}

	// Config locale (format 24.2 conservé) + arborescence cache/applied-state.
	if err := store.WriteConfig(shared.Config{ServerURL: serverURL, IntervalSeconds: intervalSeconds}); err != nil {
		return fmt.Errorf("écriture de la configuration : %w", err)
	}
	if err := store.EnsureLayout(); err != nil {
		return fmt.Errorf("préparation de l'arborescence : %w", err)
	}
	// Story 24.6 : cache d'assets (Users:R) créé dès l'install — les
	// répertoires per-SID (cache de session, drop) sont créés/ACLés par le
	// fetch SYSTEM à la volée, SID par SID.
	if err := store.EnsureAssetsDir(setAssetsACL); err != nil {
		return fmt.Errorf("préparation du cache d'assets : %w", err)
	}
	// Story 25.2 : répertoire de staging d'auto-update (SYSTEM F + Admins F,
	// PAS de Users:R — un binaire stagé n'est pas un asset affiché). Créé dès
	// l'install ; SelfUpdate le (re)crée à la volée si absent (idempotent).
	if err := store.EnsureUpdateDir(setUpdateACL); err != nil {
		return fmt.Errorf("préparation du répertoire de staging d'auto-update : %w", err)
	}

	exe, err := os.Executable()
	if err != nil {
		return fmt.Errorf("chemin du binaire : %w", err)
	}

	m, err := mgr.Connect()
	if err != nil {
		return fmt.Errorf("connexion au SCM : %w (lancer en administrateur)", err)
	}
	defer m.Disconnect()

	// Réinstallation : arrêt + suppression + attente de la disparition
	// effective (sc delete est ASYNCHRONE — « marked for deletion »).
	if existing, err := m.OpenService(serviceName); err == nil {
		fmt.Printf("Service %s déjà présent : arrêt + suppression avant réinstallation.\n", serviceName)
		stopServiceBlocking(existing)
		if err := existing.Delete(); err != nil {
			existing.Close()

			return fmt.Errorf("suppression du service existant : %w", err)
		}
		existing.Close()
		if err := waitServiceGone(m, 30*time.Second); err != nil {
			return err
		}
	}

	s, err := m.CreateService(serviceName, exe, mgr.Config{
		DisplayName: serviceDisplayName,
		Description: serviceDescription,
		StartType:   mgr.StartAutomatic,
		// ServiceStartName vide = LocalSystem (compte SYSTEM).
	})
	if err != nil {
		return fmt.Errorf("création du service : %w", err)
	}
	defer s.Close()

	// Relance automatique 30 s sur terminaison ANORMALE (iso `sc.exe failure ...
	// reset=86400 actions=restart/30000/...`). Un ARRÊT PROPRE (401 irrécupérable
	// → svc.Handler signale SERVICE_STOPPED gracieusement) n'est PAS relancé : la
	// relance ne vaut que pour les terminaisons anormales.
	//
	// Story 25.2 (Option A) : après un swap d'auto-update réussi, l'agent sort
	// par os.Exit(≠0) (swapExitCode, update_windows.go) — le process meurt SANS
	// que le svc.Handler ne signale SERVICE_STOPPED proprement. Le SCM voit une
	// terminaison anormale et applique ces RecoveryActions → relance avec le
	// binaire vN+1. La fenêtre de reset (86400 s) ne remet le compteur d'échecs
	// à zéro qu'après 24 h sans incident : 3 relances ×30 s couvrent largement un
	// swap (un seul échec attendu par update), et un poste qui bouclerait sur des
	// crashs serait de toute façon réparé par le bootstrap GPO figé (25.4).
	if err := s.SetRecoveryActions([]mgr.RecoveryAction{
		{Type: mgr.ServiceRestart, Delay: 30 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 30 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 30 * time.Second},
	}, 86400); err != nil {
		return fmt.Errorf("configuration de la relance sur crash : %w", err)
	}
	// Story 25.2 (Option A) : ceinture-bretelles pour garantir la relance après la
	// sortie volontaire post-swap. Selon que le SCM classe notre os.Exit(≠0)
	// comme un « crash » (process disparu sans SERVICE_STOPPED) ou comme un
	// « échec non-crash » (code de sortie ≠ 0), seul ce flag garantit que les
	// RecoveryActions s'appliquent AUSSI au second cas. Sans lui, une sortie avec
	// code ≠ 0 considérée « non-crash » ne déclencherait pas la relance → l'agent
	// vN+1 resterait en place mais arrêté jusqu'au prochain boot.
	if err := s.SetRecoveryActionsOnNonCrashFailures(true); err != nil {
		return fmt.Errorf("activation de la relance sur sortie non-gracieuse (post-swap) : %w", err)
	}

	// Story 24.6 : tâches planifiées at-logon du compagnon (session-fetch
	// SYSTEM + companion Users), idempotent — désenregistre au passage les
	// tâches PS homonymes héritées du spike (piège n° 21).
	if err := registerSessionTasks(exe); err != nil {
		return err
	}

	// Démarrage : première boucle immédiate.
	if err := s.Start(); err != nil {
		return fmt.Errorf("démarrage du service : %w", err)
	}

	fmt.Printf("Service %s installé et démarré (SYSTEM, démarrage automatique, relance 30 s).\n", serviceName)
	fmt.Printf("Tâches planifiées at-logon : %s (SYSTEM) + %s (Users, résident).\n", taskSessionFetch, taskSessionCompanion)
	fmt.Printf("Binaire enregistré : %s\n", exe)
	fmt.Println(`Log local : C:\ProgramData\SambaEdu\Agent\logs\agent.log`)
	fmt.Println(`Log compagnon (par user) : %LOCALAPPDATA%\SambaEdu\Agent\companion.log`)

	return nil
}

// uninstallService : remplace Uninstall-SambaEduAgent.ps1. Par défaut,
// CONSERVE les données d'enrôlement (token 23.3, cache, logs) : une
// réinstallation reprend là où le poste en était, sans re-enrôlement.
// purge=true efface tout (le poste devra être re-enrôlé via la chaîne iPXE).
func uninstallService(purge bool) error {
	m, err := mgr.Connect()
	if err != nil {
		return fmt.Errorf("connexion au SCM : %w (lancer en administrateur)", err)
	}
	defer m.Disconnect()

	if s, err := m.OpenService(serviceName); err == nil {
		stopServiceBlocking(s)
		if err := s.Delete(); err != nil {
			s.Close()

			return fmt.Errorf("suppression du service : %w", err)
		}
		s.Close()
		if err := waitServiceGone(m, 30*time.Second); err != nil {
			fmt.Fprintf(os.Stderr, "avertissement : %v (handle SCM ouvert ? fermer services.msc)\n", err)
		} else {
			fmt.Printf("Service %s supprimé.\n", serviceName)
		}
	} else {
		fmt.Printf("Service %s absent : rien à supprimer.\n", serviceName)
	}

	// Story 24.6 : suppression des 2 tâches at-logon (données conservées —
	// le flag -purge ci-dessous reste le seul à toucher aux données).
	if err := unregisterSessionTasks(); err != nil {
		fmt.Fprintf(os.Stderr, "avertissement : %v\n", err)
	} else {
		fmt.Printf("Tâches planifiées %s + %s supprimées.\n", taskSessionFetch, taskSessionCompanion)
	}

	dataDir := shared.DefaultAgentRoot
	if purge {
		if err := os.RemoveAll(dataDir); err != nil {
			return fmt.Errorf("purge de %s : %w", dataDir, err)
		}
		fmt.Printf("Données purgées : %s (token compris — re-enrôlement iPXE requis).\n", dataDir)
	} else {
		fmt.Printf("Données conservées : %s (token, cache, logs) — utiliser -purge pour tout effacer.\n", dataDir)
	}

	return nil
}

// stopServiceBlocking : Stop + attente de l'arrêt effectif (30 s max).
func stopServiceBlocking(s *mgr.Service) {
	status, err := s.Query()
	if err != nil || status.State == svc.Stopped {
		return
	}
	if _, err := s.Control(svc.Stop); err != nil {
		return
	}
	deadline := time.Now().Add(30 * time.Second)
	for time.Now().Before(deadline) {
		status, err := s.Query()
		if err != nil || status.State == svc.Stopped {
			return
		}
		time.Sleep(500 * time.Millisecond)
	}
}

// waitServiceGone : attend la disparition effective du service après Delete
// (sinon une recréation immédiate sur le même nom échoue).
func waitServiceGone(m *mgr.Mgr, timeout time.Duration) error {
	deadline := time.Now().Add(timeout)
	for time.Now().Before(deadline) {
		s, err := m.OpenService(serviceName)
		if err != nil {
			return nil // disparu
		}
		s.Close()
		time.Sleep(time.Second)
	}

	return fmt.Errorf("service %s toujours marqué pour suppression après %s", serviceName, timeout)
}
