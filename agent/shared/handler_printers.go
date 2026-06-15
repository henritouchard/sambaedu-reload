package shared

import (
	"fmt"
	"sort"
)

// Handler `printers` (aggregate / scope session) — Story 27.2. Logique PURE,
// OS-agnostique (les opérations d'installation d'imprimante réseau réelles sont
// injectées via PrinterOps) → testée sur l'hôte ; agent/windows ne fait que
// câbler AddPrinterConnection/SetDefaultPrinter.
//
// CONVERGENCE level-triggered (décision n° 8), JAMAIS accumulation :
//   - test  : l'ensemble des imprimantes GÉRÉES installées == l'union cible ∧
//     l'imprimante par défaut == celle marquée `is_default` ?
//   - apply : installer les manquantes + désinstaller les gérées sorties des
//     règles + poser le défaut. IDEMPOTENT (deux passes sur état stable = aucune
//     écriture).
//
// MARQUEUR de périmètre (décision n° 8) : seules les imprimantes GÉRÉES par
// l'agent (connexions au partage Samba `\\<se4fs>\<cups_name>`) sont listées,
// JAMAIS une imprimante installée par l'utilisateur hors SambaEdu. Une
// imprimante user homonyme (même connexion qu'une cible) est IGNORÉE via
// Blocked() — ni désinstallée, ni ré-installée : les autres imprimantes
// convergent quand même (iso shortcuts 27.1 #1).
//
// La `connection` (`\\<se4fs>\<cups_name>`) est résolue CÔTÉ SERVEUR (connexion
// logique, décision n° 4) ; l'agent substitue seulement le token `<se4fs>`. Le
// défaut (`is_default`) est résolu CÔTÉ SERVEUR (provider : physique > logique) ;
// l'agent applique bêtement le marqueur reçu, il ne recalcule JAMAIS la
// spécificité.
//
// ISOLATION des erreurs (AC4) : si le serveur d'impression est injoignable à
// l'apply, l'op renvoie une erreur → le moteur (engine.go RunPass) rend
// {status: error, detail} pour le SEUL type `printers` ; `drives` et les autres
// types continuent. Retry au cycle suivant (level-triggered).

// PrinterSpec : une imprimante cible (un item du payload `printers`). Tous les
// champs viennent du payload (contrat §4.1 — connection/cups_name sont des
// strings, is_default un bool).
type PrinterSpec struct {
	CupsName   string // nom CUPS (clé d'identité de l'imprimante)
	Connection string // connexion logique \\<se4fs>\<cups_name> (token substitué localement)
	IsDefault  bool   // l'agent pose SetDefaultPrinter sur l'unique item marqué
}

// PrinterOps : opérations d'imprimante spécifiques à l'OS, injectées (testable
// hôte). L'impl Windows vit dans agent/windows/handler_printers_windows.go
// (AddPrinterConnection / SetDefaultPrinter) ; un stub no-op couvre les autres
// OS.
//
// L'identité d'une imprimante gérée est sa CONNEXION résolue (`\\serveur\nom`) :
// c'est ce que Windows stocke et ce que l'agent sait reconnaître comme « à lui ».
type PrinterOps interface {
	// ResolveConnection substitue les tokens locaux (`<se4fs>`) dans la
	// connexion logique → la connexion réelle `\\serveur\nom`. Erreur =
	// serveur non résoluble (l'item devient error, les autres types continuent).
	ResolveConnection(spec PrinterSpec) (string, error)

	// ListManaged liste les connexions ABSOLUES des imprimantes GÉRÉES par
	// l'agent (connexions de la forme `\\<serveur SambaEdu>\…`). N'inclut JAMAIS
	// une imprimante locale ou une connexion utilisateur hors périmètre.
	ListManaged() ([]string, error)

	// Installed : la connexion `conn` est-elle déjà installée (par l'agent) ?
	//   - absente            → (false, nil) : apply doit l'installer.
	//   - installée gérée    → (true, nil).
	Installed(conn string) (bool, error)

	// Blocked : une imprimante installée HORS périmètre SambaEdu occupe-t-elle
	// déjà cette connexion (homonyme utilisateur) ? true → on ne touche pas
	// (ni install, ni désinstall). Absente / gérée = false.
	Blocked(conn string) (bool, error)

	// Add installe (ou ré-installe) la connexion imprimante. Idempotent.
	Add(conn string) error

	// Remove désinstalle une connexion GÉRÉE. Absente = pas d'erreur (idempotent).
	Remove(conn string) error

	// DefaultPrinter retourne la connexion de l'imprimante par défaut courante
	// (vide si aucune / non résoluble).
	DefaultPrinter() (string, error)

	// SetDefault pose l'imprimante par défaut sur la connexion donnée.
	SetDefault(conn string) error
}

// PrintersHandler : handler aggregate branché dans le moteur (engine.go) — la
// machine d'états §5 et le hash d'agrégat restent au moteur, JAMAIS ici.
type PrintersHandler struct {
	Ops PrinterOps
	Log *Logger
}

// desiredSet : map connexion résolue → spec, + la connexion du défaut (vide si
// aucun). Calculée depuis les items cible.
func (h *PrintersHandler) desiredSet(items []StateItem) (map[string]PrinterSpec, string, error) {
	desired := map[string]PrinterSpec{}
	defaultConn := ""
	for _, item := range items {
		spec, ok := parsePrinterSpec(item.Payload)
		if !ok {
			return nil, "", fmt.Errorf("payload printers inattendu : enveloppe invalide")
		}
		conn, err := h.Ops.ResolveConnection(spec)
		if err != nil {
			return nil, "", fmt.Errorf("connexion %q non résoluble : %w", spec.Connection, err)
		}
		desired[conn] = spec
		if spec.IsDefault {
			// Le serveur garantit UN SEUL is_default ; défense : le dernier
			// marqué fait foi (sans incidence nominale).
			defaultConn = conn
		}
	}

	return desired, defaultConn, nil
}

// Test : l'ensemble des imprimantes gérées == l'union cible ∧ le défaut == le
// marqué ?
func (h *PrintersHandler) Test(items []StateItem) (bool, error) {
	desired, defaultConn, err := h.desiredSet(items)
	if err != nil {
		return false, err
	}

	managed, err := h.Ops.ListManaged()
	if err != nil {
		return false, err
	}

	// Une imprimante gérée hors cible (sortie des règles) = non conforme.
	for _, conn := range managed {
		if _, want := desired[conn]; !want {
			return false, nil
		}
	}

	// Chaque cible doit être installée — SAUF si une imprimante utilisateur
	// (homonyme hors périmètre) occupe la connexion : ignorée (ni conforme ni
	// dérive), les autres convergent quand même.
	for conn := range desired {
		blocked, err := h.Ops.Blocked(conn)
		if err != nil {
			return false, err
		}
		if blocked {
			continue
		}
		installed, err := h.Ops.Installed(conn)
		if err != nil {
			return false, err
		}
		if !installed {
			return false, nil
		}
	}

	// Le défaut doit correspondre (uniquement si une cible le réclame ET qu'elle
	// n'est pas bloquée par un homonyme user). Pas de défaut réclamé → on ne
	// touche pas au défaut courant (on ne le compte pas en dérive).
	if defaultConn != "" {
		blocked, err := h.Ops.Blocked(defaultConn)
		if err != nil {
			return false, err
		}
		if !blocked {
			current, err := h.Ops.DefaultPrinter()
			if err != nil {
				return false, err
			}
			if current != defaultConn {
				return false, nil
			}
		}
	}

	return true, nil
}

// Apply : converge — installe les manquantes, désinstalle les gérées sorties des
// règles, pose le défaut. Idempotent + level-triggered.
func (h *PrintersHandler) Apply(items []StateItem) error {
	desired, defaultConn, err := h.desiredSet(items)
	if err != nil {
		return err
	}

	managed, err := h.Ops.ListManaged()
	if err != nil {
		return err
	}

	// 1) Désinstaller les GÉRÉES sorties des règles (jamais une imprimante user).
	sort.Strings(managed) // déterminisme des logs
	for _, conn := range managed {
		if _, want := desired[conn]; want {
			continue
		}
		if err := h.Ops.Remove(conn); err != nil {
			return fmt.Errorf("désinstallation de l'imprimante gérée %q : %w", conn, err)
		}
		logInfo(h.Log, "Imprimante gérée retirée (sortie des règles) : %s", conn)
	}

	// 2) Installer les cibles manquantes (déterminisme : ordre trié).
	conns := make([]string, 0, len(desired))
	for conn := range desired {
		conns = append(conns, conn)
	}
	sort.Strings(conns)
	for _, conn := range conns {
		// Imprimante utilisateur (homonyme hors périmètre) : on ne l'écrase
		// JAMAIS (décision n° 8). On saute (les autres convergent quand même).
		blocked, err := h.Ops.Blocked(conn)
		if err != nil {
			return err
		}
		if blocked {
			logInfo(h.Log, "Imprimante utilisateur (hors périmètre) laissée telle quelle : %s", conn)

			continue
		}
		installed, err := h.Ops.Installed(conn)
		if err != nil {
			return err
		}
		if installed {
			continue // déjà installée → idempotence (aucune écriture)
		}
		if err := h.Ops.Add(conn); err != nil {
			return fmt.Errorf("installation de l'imprimante %q : %w", conn, err)
		}
		logInfo(h.Log, "Imprimante installée : %s", conn)
	}

	// 3) Poser le défaut sur l'item marqué (idempotent : ne réécrit pas si déjà
	// le défaut courant). Défaut bloqué par un homonyme user → on ne touche pas.
	if defaultConn != "" {
		blocked, err := h.Ops.Blocked(defaultConn)
		if err != nil {
			return err
		}
		if !blocked {
			current, err := h.Ops.DefaultPrinter()
			if err != nil {
				return err
			}
			if current != defaultConn {
				if err := h.Ops.SetDefault(defaultConn); err != nil {
					return fmt.Errorf("définition de l'imprimante par défaut %q : %w", defaultConn, err)
				}
				logInfo(h.Log, "Imprimante par défaut posée : %s", defaultConn)
			}
		}
	}

	return nil
}

// parsePrinterSpec : extrait un PrinterSpec d'un payload §3 brut. cups_name /
// connection manquants = enveloppe invalide (false) → le moteur rapporte error.
func parsePrinterSpec(raw any) (PrinterSpec, bool) {
	payload, ok := raw.(map[string]any)
	if !ok || payload == nil {
		return PrinterSpec{}, false
	}

	cupsName, _ := payload["cups_name"].(string)
	connection, _ := payload["connection"].(string)
	if cupsName == "" || connection == "" {
		return PrinterSpec{}, false
	}

	// is_default : bool du payload (absent = false). Tolérant à l'absence.
	isDefault, _ := payload["is_default"].(bool)

	return PrinterSpec{
		CupsName:   cupsName,
		Connection: connection,
		IsDefault:  isDefault,
	}, true
}
