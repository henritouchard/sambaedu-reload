package shared

import (
	"fmt"
	"sort"
	"strings"
)

// Handler `folders` (exclusive PAR DOSSIER / scope machine_user) — Story 58.1,
// contrat §7.12. SEPTIÈME mécanisme hors-catalogue, appliqué par le COMPAGNON
// (la redirection vit dans HKCU : c'est une donnée d'UTILISATEUR). Logique PURE,
// OS-agnostique (registre + I/O injectés) → testée sur l'hôte ; agent/windows
// n'apporte que la substitution de tokens et les accès disque.
//
// # Ce qu'il répare
//
// `\\<se4fs>\users\<user>\Bureau\` n'est le Bureau de l'utilisateur QUE si
// `HKCU\…\Explorer\User Shell Folders\Desktop` pointe dessus. Cette valeur était
// écrite par le script de la GPO legacy « Bureau » (paquet `folders`), dernier
// émetteur, coupé le 2026-07-20 par le blocage d'héritage sur l'OU des comptes —
// sans successeur SE5.
//
// La panne était masquée : la valeur, écrite UNE fois, est FIGÉE dans le profil
// itinérant. Les profils antérieurs à la coupure la gardent, les profils créés
// après ne l'ont jamais eue. Résultat pour ces derniers : le handler `shortcuts`
// dépose consciencieusement les `.lnk` dans le Bureau RÉSEAU, que le shell ne
// regarde pas — raccourcis invisibles, sans la moindre erreur nulle part.
//
// # Convergence level-triggered, JAMAIS accumulation
//
//   - test  : pour chaque dossier cible, la valeur `User Shell Folders\<Name>`
//     vaut-elle EXACTEMENT le chemin voulu (REG_EXPAND_SZ) ET le dossier
//     existe-t-il ? Une cible injoignable (serveur de fichiers muet) est une
//     ERREUR, jamais un « conforme » silencieux ;
//   - apply : créer le dossier manquant PUIS écrire la valeur. Cet ordre est le
//     seul sûr — rediriger vers un dossier absent donne un Bureau vide et
//     Explorer peut recréer un dossier local à la place. Iso legacy, qui faisait
//     `MD` avant `reg add`. IDEMPOTENT (deux passes sur état stable = zéro
//     écriture, zéro création).
//
// # Gestes de rafraîchissement
//
// Une redirection de dossier shell n'est PAS relue par un simple
// SHChangeNotify : Explorer lit `User Shell Folders` à son démarrage. Un
// changement EFFECTIF demande donc RefreshExplorerRestart (échelle 43.1) — le
// COMPAGNON exécute le geste en fin de passe, une seule fois. Au régime stable,
// aucune écriture ⇒ aucun geste ⇒ pas de bureau qui clignote à chaque cycle.
//
// # Ce que le handler ne fait PAS
//
// Il ne DÉPLACE aucun contenu et ne SUPPRIME rien : rediriger le Bureau ne
// déménage pas les fichiers déjà posés à l'ancien emplacement (Windows non plus
// ne le fait pas pour `User Shell Folders`). Le nettoyage des `.lnk` gérés
// orphelins reste l'affaire de `shortcuts` et de ses `desktop_sweep_paths`, qui
// sont pilotés par le SERVEUR — un emplacement réseau est partagé entre tous les
// postes de l'utilisateur, ce handler n'a aucune autorité pour y toucher.

// userShellFoldersPath : clé HKCU des redirections de dossiers shell. Le
// pendant `Shell Folders` (sans « User ») est un CACHE régénéré par Windows à
// partir de celle-ci — on ne l'écrit pas (le legacy non plus).
const userShellFoldersPath = `Software\Microsoft\Windows\CurrentVersion\Explorer\User Shell Folders`

// shellFolderValueNames : traduction mot MÉTIER (payload) → nom de valeur de
// registre. Le serveur émet `desktop` ; il n'écrit JAMAIS de chemin de clé
// (invariant capability-first : l'agent est compétent sur le mécanisme). Enum
// FERMÉ — un `folder` hors de cette map est une enveloppe invalide, jamais une
// écriture au hasard dans la clé.
var shellFolderValueNames = map[string]string{
	"desktop": "Desktop",
}

// FolderSpec : une redirection cible (un item du payload `folders`). Tous les
// champs sont des strings (contrat §4.1).
type FolderSpec struct {
	Folder string // mot métier du dossier shell, ex. "desktop"
	Path   string // gabarit à tokens `\\<se4fs>\users\<user>\Bureau\` | `%USERPROFILE%\Desktop\`
}

// identity : clé d'identité (le dossier) — insensible à la casse.
func (s FolderSpec) identity() string {
	return strings.ToLower(s.Folder)
}

// FolderOps : résolution de chemin et accès disque spécifiques à l'OS, injectés
// (testable hôte). L'impl Windows vit dans agent/windows/handler_folders_windows.go ;
// un fake en mémoire couvre les tests.
type FolderOps interface {
	// ResolvePath substitue les tokens serveur (`<se4fs>`/`<user>`) et NORMALISE
	// le résultat en valeur de registre : séparateur final retiré (Windows écrit
	// `%USERPROFILE%\Desktop`, pas `…\Desktop\` — sans ce trim, une session
	// vanilla serait éternellement « en dérive » et réécrite à chaque cycle).
	//
	// Les `%VAR%` sont LAISSÉS INTACTS : la valeur est écrite en REG_EXPAND_SZ,
	// c'est Windows qui les expanse à la lecture. Les résoudre ici figerait le
	// chemin d'un profil dans la ruche d'un autre.
	//
	// err = tokens non substituables (poste hors-domaine : ni SE4FS ni
	// LOGONSERVER) — on refuse d'écrire `\\\users\…`, qui donnerait un Bureau
	// mort à l'utilisateur.
	ResolvePath(path string) (string, error)

	// DirExists : le dossier cible existe-t-il ? `value` est la valeur de
	// registre résolue (les `%VAR%` sont expansés par l'op avant l'accès disque).
	//   - absent            → (false, nil) : dérive, à créer ;
	//   - présent           → (true, nil) ;
	//   - injoignable/refusé→ (false, err) : le type passe en {status: error},
	//     jamais un « conforme » menteur ni une redirection posée à l'aveugle.
	DirExists(value string) (bool, error)

	// EnsureDir crée le dossier cible (et ses parents manquants). Idempotent.
	EnsureDir(value string) error
}

// FoldersHandler : handler exclusive-par-dossier branché dans le moteur
// (engine.go) — la machine d'états §5 reste au moteur, JAMAIS ici. Le registre
// passe par le MÊME RegistryOps que le handler `registry` (une seule
// implémentation d'accès à la ruche, jamais une jumelle).
type FoldersHandler struct {
	Ops      FolderOps
	Registry RegistryOps
	Log      *Logger

	// refreshWanted : besoin de rafraîchissement accumulé pendant l'Apply de la
	// passe courante. État PAR INSTANCE, mono-thread (patron acquis du
	// RegistryHandler) ; consommé + remis à zéro par TakeRefreshRequest.
	refreshWanted RefreshLevel
}

// TakeRefreshRequest : implémente RefreshRequester (refresh.go) — retourne le
// niveau max accumulé pendant la passe et remet l'accumulation à zéro.
func (h *FoldersHandler) TakeRefreshRequest() RefreshLevel {
	level := h.refreshWanted
	h.refreshWanted = RefreshNone

	return level
}

// folderTarget : une cible résolue — le nom de valeur de registre et le chemin
// à y écrire.
type folderTarget struct {
	spec      FolderSpec
	valueName string // "Desktop"
	value     string // chemin résolu, sans séparateur final
}

// desiredTargets : parse + dédoublonne par dossier les items cible, puis résout
// chaque chemin. Le serveur garantit déjà l'unicité (exclusive par dossier au
// compilateur) ; défense : la DERNIÈRE occurrence fait foi. Ordre déterministe
// par identité (logs et erreurs stables).
func (h *FoldersHandler) desiredTargets(items []StateItem) ([]folderTarget, error) {
	byIdentity := map[string]FolderSpec{}
	order := []string{}
	for _, item := range items {
		spec, ok := parseFolderSpec(item.Payload)
		if !ok {
			return nil, fmt.Errorf("payload folders inattendu : enveloppe invalide")
		}
		id := spec.identity()
		if _, seen := byIdentity[id]; !seen {
			order = append(order, id)
		}
		byIdentity[id] = spec
	}
	sort.Strings(order)

	targets := make([]folderTarget, 0, len(order))
	for _, id := range order {
		spec := byIdentity[id]
		value, err := h.Ops.ResolvePath(spec.Path)
		if err != nil {
			return nil, fmt.Errorf("chemin %q du dossier %q non résoluble : %w", spec.Path, spec.Folder, err)
		}
		targets = append(targets, folderTarget{
			spec:      spec,
			valueName: shellFolderValueNames[spec.identity()],
			value:     value,
		})
	}

	return targets, nil
}

// Test : chaque redirection cible pointe-t-elle EXACTEMENT le bon chemin, ET le
// dossier existe-t-il ? Une valeur absente/divergente ou un dossier manquant =
// non conforme (à converger). Une erreur d'accès (registre protégé, partage
// injoignable) remonte : le moteur rend {status: error} pour le type `folders`,
// les autres types continuent.
func (h *FoldersHandler) Test(items []StateItem) (bool, error) {
	targets, err := h.desiredTargets(items)
	if err != nil {
		return false, err
	}

	for _, target := range targets {
		// Le dossier D'ABORD : une valeur juste pointant un dossier absent est
		// exactement l'état cassé qu'on répare — il ne doit jamais passer pour
		// conforme.
		exists, err := h.Ops.DirExists(target.value)
		if err != nil {
			return false, fmt.Errorf("accès au dossier %q (%s) : %w", target.value, target.spec.Folder, err)
		}
		if !exists {
			return false, nil
		}

		actual, present, err := h.Registry.Read("HKCU", userShellFoldersPath, target.valueName)
		if err != nil {
			return false, fmt.Errorf("lecture de la redirection %q : %w", target.valueName, err)
		}
		if !present || !actual.Equal(expandSzValue(target.value)) {
			return false, nil
		}
	}

	return true, nil
}

// Apply : converge — crée les dossiers manquants PUIS (ré)écrit les valeurs
// divergentes. EFFORT MAXIMAL : on tente TOUTES les cibles, la première erreur
// est remontée à la fin (une redirection en échec n'empêche pas les autres de
// converger). Idempotent : une cible déjà conforme n'est ni recréée ni réécrite.
func (h *FoldersHandler) Apply(items []StateItem) error {
	targets, err := h.desiredTargets(items)
	if err != nil {
		return err
	}

	var firstErr error
	for _, target := range targets {
		if err := h.applyTarget(target); err != nil {
			if firstErr == nil {
				firstErr = err
			}

			continue // effort maximal : cible suivante
		}
	}

	return firstErr
}

// applyTarget : converge UNE redirection. Le dossier est garanti AVANT
// l'écriture de la valeur (ordre non négociable, cf. en-tête).
func (h *FoldersHandler) applyTarget(target folderTarget) error {
	exists, err := h.Ops.DirExists(target.value)
	if err != nil {
		logError(h.Log, "Accès au dossier %s (%s) en échec : %v", target.value, target.spec.Folder, err)

		return fmt.Errorf("accès au dossier %q (%s) : %w", target.value, target.spec.Folder, err)
	}
	if !exists {
		if err := h.Ops.EnsureDir(target.value); err != nil {
			logError(h.Log, "Création du dossier %s (%s) en échec : %v", target.value, target.spec.Folder, err)

			return fmt.Errorf("création du dossier %q (%s) : %w", target.value, target.spec.Folder, err)
		}
		logInfo(h.Log, "Dossier %s créé : %s", target.spec.Folder, target.value)
		// La création SEULE justifie déjà un redémarrage d'Explorer : si le
		// dossier manquait à son démarrage, il est retombé sur un emplacement de
		// repli et ne « verra » pas le dossier apparu depuis. Cas réel : valeur
		// de registre correcte (héritée du profil itinérant) mais dossier
		// supprimé côté home — sans ce geste, Apply converge et l'utilisateur
		// continue de regarder un Bureau vide.
		h.refreshWanted = maxRefreshLevel(h.refreshWanted, RefreshExplorerRestart)
	}

	spec := RegistrySpec{
		Hive:  "HKCU",
		Path:  userShellFoldersPath,
		Name:  target.valueName,
		Value: expandSzValue(target.value),
	}

	actual, present, err := h.Registry.Read(spec.Hive, spec.Path, spec.Name)
	if err != nil {
		logError(h.Log, "Lecture de la redirection %s en échec : %v", target.valueName, err)

		return fmt.Errorf("lecture de la redirection %q : %w", target.valueName, err)
	}
	if present && actual.Equal(spec.Value) {
		return nil // déjà conforme → idempotence (aucune écriture, aucun geste)
	}
	if err := h.Registry.Write(spec); err != nil {
		logError(h.Log, "Écriture de la redirection %s en échec : %v", target.valueName, err)

		return fmt.Errorf("écriture de la redirection %q : %w", target.valueName, err)
	}
	logInfo(h.Log, "Dossier %s redirigé vers %s", target.spec.Folder, target.value)

	// Explorer lit `User Shell Folders` à SON démarrage : un SHChangeNotify ne
	// suffit pas, il faut le relancer. Accumulé ici, exécuté UNE fois en fin de
	// passe par le compagnon (43.1) — jamais inline (une seule voie d'émission).
	h.refreshWanted = maxRefreshLevel(h.refreshWanted, RefreshExplorerRestart)

	return nil
}

// expandSzValue : la valeur typée d'une redirection. TOUJOURS REG_EXPAND_SZ —
// c'est ce type qui permet à `%USERPROFILE%\Desktop` de désigner le profil de
// CHAQUE utilisateur ; en REG_SZ le chemin serait littéral (et le legacy écrivait
// déjà `/t REG_EXPAND_SZ`).
func expandSzValue(value string) RegistryValue {
	return RegistryValue{Kind: "REG_EXPAND_SZ", Str: value}
}

// parseFolderSpec : extrait un FolderSpec d'un payload §3 brut. `folder` doit
// appartenir à l'enum FERMÉ (shellFolderValueNames) — un dossier inconnu est une
// enveloppe invalide (false), JAMAIS une écriture dans une valeur devinée.
// `path` vide = invalide : « pas de redirection » s'exprime en n'émettant pas
// l'item (contrat §8), pas en émettant un chemin vide qui casserait le shell.
func parseFolderSpec(raw any) (FolderSpec, bool) {
	payload, ok := raw.(map[string]any)
	if !ok || payload == nil {
		return FolderSpec{}, false
	}

	folder, _ := payload["folder"].(string)
	path, _ := payload["path"].(string)
	if folder == "" || path == "" {
		return FolderSpec{}, false
	}
	if _, known := shellFolderValueNames[strings.ToLower(folder)]; !known {
		return FolderSpec{}, false
	}

	return FolderSpec{Folder: folder, Path: path}, true
}
