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
// discernables des rapports PS en lab). 2.1.0 = binaire COMPLET 24.6
// (compagnon + handlers + drops) — discernable en lab des rapports core-only
// 2.0.0 de 24.5. 2.1.1 = correctif terrain T12 (setAgentACL : flags (OI)(CI)
// réservés aux répertoires — posés sur un fichier, DACL effective vide et
// writeAtomic échouait en Accès refusé à la première exécution Windows).
// 2.1.2 = correctif terrain T12 n° 2 (compagnon : FreeConsole au démarrage —
// la tâche at-logon laissait une fenêtre console résidente dans la session,
// fermable par le user = compagnon tué).
//
// Injectable au build (var, pas const) :
//
//	go build -ldflags "-X sambaedu/agent/shared.Version=2.1.3"
var Version = "2.1.2"
