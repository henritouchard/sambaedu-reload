package main

import (
	"net"
	"strings"

	"sambaedu/agent/shared"
)

// macAddress retourne le fournisseur d'adresse MAC du faisceau d'enrôlement
// porte 2 (Story 25.4). Ancre fiable de rapprochement côté serveur (25.3) — le
// serveur normalise via MacAddressNormalizer, donc le format brut importe peu :
// on renvoie la MAC de la PREMIÈRE interface physique active (up, non-loopback,
// MAC non vide), iso-legacy « adaptateur actif ».
//
// Implémentation pure-Go (net.Interfaces, zéro dépendance, zéro shell-out) :
// l'ancre est l'adresse matérielle de la NIC, pas un GUID Windows. Échec /
// aucune interface éligible → chaîne vide : la demande part QUAND MÊME (le
// serveur la trace mais ne pourra pas l'auto-approuver — piège n° 5 : on ne
// renvoie JAMAIS une MAC inventée).
func macAddress(log *shared.Logger) func() string {
	return func() string {
		ifaces, err := net.Interfaces()
		if err != nil {
			log.Warningf("Énumération des interfaces réseau en échec : %v — demande d'enrôlement sans MAC.", err)

			return ""
		}

		for _, iface := range ifaces {
			// Adaptateur actif : up, non loopback, MAC matérielle non vide.
			if iface.Flags&net.FlagUp == 0 || iface.Flags&net.FlagLoopback != 0 {
				continue
			}
			mac := iface.HardwareAddr.String()
			if strings.TrimSpace(mac) == "" {
				continue
			}

			return mac
		}

		log.Warningf("Aucun adaptateur réseau actif avec MAC matérielle : demande d'enrôlement sans MAC.")

		return ""
	}
}
