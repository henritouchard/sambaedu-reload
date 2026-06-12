package main

import (
	"context"
	"os"
	"os/signal"
	"time"

	"golang.org/x/sys/windows/svc"
)

// agentService implémente le protocole SCM (svc.Handler) : la boucle shared
// tourne dans une goroutine annulée proprement sur Stop/Shutdown.
type agentService struct{}

func (s *agentService) Execute(_ []string, requests <-chan svc.ChangeRequest, status chan<- svc.Status) (bool, uint32) {
	status <- svc.Status{State: svc.StartPending}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	agent := newAgent(false)
	done := make(chan struct{})
	go func() {
		agent.Run(ctx)
		close(done)
	}()

	status <- svc.Status{State: svc.Running, Accepts: svc.AcceptStop | svc.AcceptShutdown}

	for {
		select {
		case req := <-requests:
			switch req.Cmd {
			case svc.Interrogate:
				status <- req.CurrentStatus
			case svc.Stop, svc.Shutdown:
				status <- svc.Status{State: svc.StopPending}
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
