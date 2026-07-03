package main

import (
	"context"
	"os"
	"os/signal"
	"time"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc"
)

// agentService implémente le protocole SCM (svc.Handler) : la boucle shared
// tourne dans une goroutine annulée proprement sur Stop/Shutdown.
type agentService struct{}

func (s *agentService) Execute(_ []string, requests <-chan svc.ChangeRequest, status chan<- svc.Status) (bool, uint32) {
	status <- svc.Status{State: svc.StartPending}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	// Story 25.2 : au démarrage de la nouvelle image (après un auto-update),
	// nettoyer agent.exe.old (best-effort — l'ancien process est mort, son
	// image n'est plus verrouillée). No-op si pas de résidu.
	cleanupOldBinary()

	agent := newAgent(false)
	done := make(chan struct{})
	go func() {
		agent.Run(ctx)
		close(done)
	}()

	// Story 27.1bis : COMPUTERNAME (machine.name de l'overlay) — jamais
	// demandé au serveur, lu localement (iso ComposeOverlayDocument 24.6).
	computerName := os.Getenv("COMPUTERNAME")

	// Story 27.1bis (volet 2, D1) : on s'abonne aux notifications de session
	// (AcceptSessionChange) — au logon (WTS_SESSION_LOGON), le service compose
	// et écrit overlay.json possédé SYSTEM (ACL <SID>:R, infalsifiable NFR5).
	status <- svc.Status{State: svc.Running, Accepts: svc.AcceptStop | svc.AcceptShutdown | svc.AcceptSessionChange}

	for {
		select {
		case req := <-requests:
			switch req.Cmd {
			case svc.Interrogate:
				status <- req.CurrentStatus
			case svc.SessionChange:
				// On ne réagit QU'au logon (WTS_SESSION_LOGON 0x5) ; les autres
				// événements (logoff, lock, unlock…) sont ignorés (Q1=B :
				// logon-only, pas de re-write périodique ni sur unlock).
				// Best-effort : une composition/écriture overlay ne doit JAMAIS
				// bloquer le SCM — elle est rapide (lecture cache +
				// WriteFileAtomic + icacls). On NE déréférence PAS le
				// lpEventData (uintptr→Pointer rejeté par vet) : on
				// ré-énumère les sessions interactives (WTS vet-clean, 24.6) —
				// la nouvelle session y apparaît, on écrit pour chacune
				// (idempotent).
				if req.EventType == windows.WTS_SESSION_LOGON {
					// Story 27.1bis : réécriture overlay.json (best-effort, sous
					// garde recover — une panique overlay ne tue pas le SCM).
					func() {
						defer func() {
							if r := recover(); r != nil {
								agent.Log.Errorf("Écriture overlay au logon en échec (panique rattrapée) : %v", r)
							}
						}()
						writeOverlayForAllSessions(agent.Store, computerName, agent.Log)
					}()
					// Story 27.9 : réveil de la boucle de convergence — un cycle
					// complet (RunCycle) part dès le logon au lieu d'attendre le
					// prochain tick (jusqu'à ~1 h). Send NON-BLOQUANT (coalescé,
					// jamais de blocage du SCM) et INDÉPENDANT de l'overlay : posé
					// HORS du recover ci-dessus, une panique overlay n'empêche pas
					// le réveil et vice-versa (les deux sont best-effort distincts,
					// AC4). Le debounce min-interval vit côté boucle.
					agent.RequestWake()
				}
				// On re-confirme l'état courant au SCM (l'event n'a pas changé
				// l'état du service).
				status <- req.CurrentStatus
			case svc.Stop, svc.Shutdown:
				status <- svc.Status{State: svc.StopPending}
				if req.Cmd == svc.Shutdown {
					// Extinction MACHINE (pas un stop manuel du service, où le
					// poste reste allumé) : on signale le serveur AVANT d'annuler
					// la boucle — best-effort borné 3 s (budget shutdown SCM
					// ~5 s), sous garde recover (une panique ne bloque jamais
					// l'arrêt du système).
					func() {
						defer func() {
							if r := recover(); r != nil {
								agent.Log.Errorf("Signal d'extinction en échec (panique rattrapée) : %v", r)
							}
						}()
						agent.NotifyShutdown(3 * time.Second)
					}()
				}
				cancel()
				select {
				case <-done:
				case <-time.After(30 * time.Second):
					// La boucle ne rend pas la main (appel HTTP en vol,
					// timeout 30 s) : on n'attend pas indéfiniment le SCM.
				}

				return false, 0
			default:
				// Commande SCM non gérée : ignorée (Accepts la filtre déjà).
			}
		case <-done:
			// La boucle est sortie SEULE : 401 irrécupérable (arrêt + log
			// local, JAMAIS de re-enrôlement automatique — un admin doit
			// intervenir). Sortie PROPRE : le SCM ne relance pas un arrêt
			// gracieux (la relance 30 s ne vaut que pour les crashs).
			return false, 0
		}
	}
}

func runService() error {
	return svc.Run(serviceName, &agentService{})
}

// runConsole : même boucle en avant-plan (debug lab) — log recopié sur
// stderr, arrêt sur Ctrl-C.
func runConsole() {
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt)
	defer stop()

	newAgent(true).Run(ctx)
}
