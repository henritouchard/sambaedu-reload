package shared

import (
	"fmt"
	"sort"
	"strings"
)

// Handler `drives` (aggregate / scope session) — Story 27.2. Logique PURE,
// OS-agnostique (les montages réels sont injectés via DriveOps) → testée sur
// l'hôte ; agent/windows ne fait que câbler `net use` / WNetAddConnection2.
//
// CONVERGENCE level-triggered, JAMAIS accumulation :
//   - test  : l'ensemble des lettres GÉRÉES montées == l'union cible (lettre →
//     UNC) ?
//   - apply : monter les manquants + démonter les gérés sortis des règles.
//     IDEMPOTENT (deux passes sur état stable = aucune écriture).
//
// MARQUEUR de périmètre (décision n° 8) : seules les lettres montées par l'agent
// (vers un partage SambaEdu) sont gérées. Un lecteur monté par l'utilisateur
// (lettre occupée par un montage hors périmètre, ou une lettre cible déjà prise
// par un montage user) est IGNORÉ via Blocked() — ni démonté, ni ré-monté : les
// autres lecteurs convergent quand même (iso shortcuts/printers 27.1/27.2).
//
// L'UNC (`\\<se4fs>\Classe_<name>\<user>\`) est résolu CÔTÉ SERVEUR (projection
// des classes du user, MVP-A) ; l'agent substitue seulement les tokens locaux
// (`<se4fs>`, `<user>`). L'agent reste bête : aucune logique métier de classe.
//
// ISOLATION des erreurs (AC4) : serveur de fichiers injoignable au montage →
// l'op renvoie une erreur → le moteur rend {status: error, detail} pour le SEUL
// type `drives` ; les autres types continuent. Retry au cycle suivant.

// DriveSpec : un montage cible (un item du payload `drives`). Tous les champs
// sont des strings (contrat §4.1).
type DriveSpec struct {
	Letter string // lettre de lecteur, ex. "K:" (clé d'identité du montage)
	UNC    string // chemin UNC \\<se4fs>\Classe_<name>\<user>\ (tokens substitués localement)
}

// DriveOps : opérations de montage réseau spécifiques à l'OS, injectées
// (testable hôte). L'impl Windows vit dans agent/windows/handler_drives_windows.go
// (`net use`) ; un stub no-op couvre les autres OS.
//
// L'identité d'un montage géré est sa LETTRE (une lettre = un montage). La
// valeur (UNC résolu) sert à détecter une dérive (lettre montée vers le mauvais
// partage).
type DriveOps interface {
	// ResolveUNC substitue les tokens locaux (`<se4fs>`/`<user>`) dans l'UNC
	// cible → l'UNC réel `\\serveur\partage\login\`. Erreur = serveur non
	// résoluble (l'item devient error, les autres types continuent).
	ResolveUNC(spec DriveSpec) (string, error)

	// ListManaged liste les LETTRES GÉRÉES par l'agent (montées vers un partage
	// SambaEdu). N'inclut JAMAIS une lettre montée par l'utilisateur.
	ListManaged() ([]string, error)

	// Mapped : la lettre est-elle montée par l'agent vers `unc` (exactement) ?
	//   - non montée / montée vers un autre UNC → (false, nil).
	//   - montée gérée vers le bon UNC          → (true, nil).
	Mapped(letter, unc string) (bool, error)

	// Blocked : la lettre est-elle occupée par un montage HORS périmètre
	// SambaEdu (monté par l'utilisateur) ? true → on ne touche pas (ni map, ni
	// unmap). Libre / gérée par l'agent = false.
	Blocked(letter string) (bool, error)

	// Map monte (ou remonte) la lettre vers l'UNC. Idempotent.
	Map(letter, unc string) error

	// Unmap démonte une lettre GÉRÉE. Absente = pas d'erreur (idempotent).
	Unmap(letter string) error
}

// DrivesHandler : handler aggregate branché dans le moteur (engine.go) — la
// machine d'états §5 et le hash d'agrégat restent au moteur, JAMAIS ici.
type DrivesHandler struct {
	Ops DriveOps
	Log *Logger
}

// desiredSet : map lettre → UNC résolu, calculée depuis les items cible.
func (h *DrivesHandler) desiredSet(items []StateItem) (map[string]string, error) {
	desired := map[string]string{}
	for _, item := range items {
		spec, ok := parseDriveSpec(item.Payload)
		if !ok {
			return nil, fmt.Errorf("payload drives inattendu : enveloppe invalide")
		}
		unc, err := h.Ops.ResolveUNC(spec)
		if err != nil {
			return nil, fmt.Errorf("UNC %q non résoluble : %w", spec.UNC, err)
		}
		desired[spec.Letter] = unc
	}

	return desired, nil
}

// Test : l'ensemble des lettres gérées == l'union cible (lettre + UNC) ?
func (h *DrivesHandler) Test(items []StateItem) (bool, error) {
	desired, err := h.desiredSet(items)
	if err != nil {
		return false, err
	}

	managed, err := h.Ops.ListManaged()
	if err != nil {
		return false, err
	}

	// Une lettre gérée hors cible (sortie des règles) = non conforme.
	for _, letter := range managed {
		if _, want := desired[letter]; !want {
			return false, nil
		}
	}

	// Chaque cible doit être montée vers le bon UNC — SAUF si un montage
	// utilisateur (homonyme hors périmètre) occupe la lettre : ignoré (ni
	// conforme ni dérive), les autres convergent quand même.
	for letter, unc := range desired {
		blocked, err := h.Ops.Blocked(letter)
		if err != nil {
			return false, err
		}
		if blocked {
			continue
		}
		ok, err := h.Ops.Mapped(letter, unc)
		if err != nil {
			return false, err
		}
		if !ok {
			return false, nil
		}
	}

	return true, nil
}

// Apply : converge — monte les manquants/divergents, démonte les gérés sortis
// des règles. Idempotent + level-triggered.
func (h *DrivesHandler) Apply(items []StateItem) error {
	desired, err := h.desiredSet(items)
	if err != nil {
		return err
	}

	managed, err := h.Ops.ListManaged()
	if err != nil {
		return err
	}

	// 1) Démonter les GÉRÉS sortis des règles (jamais un montage user).
	sort.Strings(managed)
	for _, letter := range managed {
		if _, want := desired[letter]; want {
			continue
		}
		if err := h.Ops.Unmap(letter); err != nil {
			return fmt.Errorf("démontage du lecteur géré %q : %w", letter, err)
		}
		logInfo(h.Log, "Lecteur géré démonté (sorti des règles) : %s", letter)
	}

	// 2) Monter les cibles manquantes ou divergentes (déterminisme : ordre trié).
	letters := make([]string, 0, len(desired))
	for letter := range desired {
		letters = append(letters, letter)
	}
	sort.Strings(letters)
	for _, letter := range letters {
		unc := desired[letter]
		// Montage utilisateur (homonyme hors périmètre) : on ne l'écrase JAMAIS
		// (décision n° 8). On saute (les autres convergent quand même).
		blocked, err := h.Ops.Blocked(letter)
		if err != nil {
			return err
		}
		if blocked {
			logInfo(h.Log, "Lecteur utilisateur (hors périmètre) laissé tel quel : %s", letter)

			continue
		}
		ok, err := h.Ops.Mapped(letter, unc)
		if err != nil {
			return err
		}
		if ok {
			continue // déjà monté au bon UNC → idempotence (aucune écriture)
		}
		if err := h.Ops.Map(letter, unc); err != nil {
			return fmt.Errorf("montage du lecteur %q → %q : %w", letter, unc, err)
		}
		logInfo(h.Log, "Lecteur monté : %s → %s", letter, unc)
	}

	return nil
}

// parseDriveSpec : extrait un DriveSpec d'un payload §3 brut. letter / unc
// manquants = enveloppe invalide (false) → le moteur rapporte error. La lettre
// est normalisée en majuscule + `:` final (tolérance d'entrée).
func parseDriveSpec(raw any) (DriveSpec, bool) {
	payload, ok := raw.(map[string]any)
	if !ok || payload == nil {
		return DriveSpec{}, false
	}

	letter, _ := payload["letter"].(string)
	unc, _ := payload["unc"].(string)
	if letter == "" || unc == "" {
		return DriveSpec{}, false
	}

	letter = normalizeLetter(letter)
	if letter == "" {
		return DriveSpec{}, false
	}

	return DriveSpec{Letter: letter, UNC: unc}, true
}

// normalizeLetter : "k" / "K" / "k:" / "K:" → "K:". Une seule lettre A-Z, sinon
// "" (enveloppe invalide).
func normalizeLetter(raw string) string {
	s := strings.ToUpper(strings.TrimSuffix(strings.TrimSpace(raw), ":"))
	if len(s) != 1 || s[0] < 'A' || s[0] > 'Z' {
		return ""
	}

	return s + ":"
}
