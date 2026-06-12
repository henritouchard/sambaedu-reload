// Package shared est le cœur OS-agnostique de l'agent SambaEdu desired-state
// (contrainte n° 5 du cahier des charges : cœur partageable cross-OS).
//
// Il contient : la canonicalisation + StateHasher (miroir bit-à-bit de
// app/Services/Agent/StateHasher.php, validé contre les golden files), le
// parsing du contrat v1, la construction du rapport, le client HTTP (rotation
// D5, fenêtre de grâce, quarantaine), le cache local atomique et la boucle de
// convergence. Rien ici ne dépend de Windows : tout est testable par
// `go test ./...` sur l'hôte Linux. Le spécifique Win32 (service SYSTEM, ACL
// icacls, UUID SMBIOS) vit dans agent/windows/.
//
// Le contrat wire est FIGÉ côté serveur : docs/agent/contract-v1.md + golden
// files tests/Fixtures/Agent/*.v1.json — ce package ne fait que le consommer.
package shared

// Version est la source unique de la version de l'agent (AC6 story 24.5) :
// déclarée dans chaque rapport (`agent_version`) et reprise par le nommage de
// l'artefact de build. La lignée PowerShell (spike 24.2-24.4) était 1.0.0 ;
// le binaire Go marque une rupture d'artefact → 2.x (les rapports Go sont
// discernables des rapports PS en lab).
//
// Injectable au build (var, pas const) :
//
//	go build -ldflags "-X sambaedu/agent/shared.Version=2.0.1"
var Version = "2.0.0"
