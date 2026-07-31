package shared

import (
	"errors"
	"fmt"
	"strings"
	"testing"
)

// Tests du handler `folders` (Story 58.1, contrat §7.12) — logique PURE, aucune
// dépendance Windows : le registre passe par le fake `fakeRegistryOps` déjà
// utilisé par le handler `registry`, les accès disque par `fakeFolderOps`.
//
// Ce qui est réellement protégé ici : l'ORDRE dossier-puis-valeur (rediriger
// vers un dossier absent donne un Bureau vide), l'idempotence (sinon Explorer
// redémarre à chaque cycle) et le refus d'écrire quand la cible est
// injoignable (sinon on grave une redirection morte dans le profil itinérant).

// fakeFolderOps : impl FolderOps en mémoire. `dirs` = dossiers existants ;
// `statErr` = erreur d'accès (serveur muet) ; `resolveErr` = tokens non
// substituables.
type fakeFolderOps struct {
	dirs       map[string]bool
	statErr    map[string]error
	mkdirErr   map[string]error
	resolveErr error
	// se4fs : valeur substituée au token `<se4fs>` ; user à `<user>`.
	se4fs string
	user  string

	// pins : emplacements épinglés dans l'Accès rapide (clés = chemins tels que
	// le handler les passe, la résolution des %VAR% étant l'affaire de l'op
	// Windows). pinErr / pinnedErr simulent une panne du shell.
	pins      map[string]bool
	pinErr    error
	pinnedErr error

	statCnt   int
	mkdirCnt  int
	pinCnt    int
	unpinCnt  int
	pinnedCnt int
}

func newFakeFolderOps() *fakeFolderOps {
	return &fakeFolderOps{
		dirs:     map[string]bool{},
		statErr:  map[string]error{},
		mkdirErr: map[string]error{},
		pins:     map[string]bool{},
		se4fs:    "se4fs-0991229y",
		user:     "mickael.barbier",
	}
}

func (o *fakeFolderOps) QuickAccessPinned(value string) (bool, error) {
	o.pinnedCnt++
	if o.pinnedErr != nil {
		return false, o.pinnedErr
	}

	return o.pins[value], nil
}

func (o *fakeFolderOps) QuickAccessPin(value string) error {
	o.pinCnt++
	if o.pinErr != nil {
		return o.pinErr
	}
	o.pins[value] = true

	return nil
}

func (o *fakeFolderOps) QuickAccessUnpin(value string) error {
	o.unpinCnt++
	if o.pinErr != nil {
		return o.pinErr
	}
	delete(o.pins, value)

	return nil
}

func (o *fakeFolderOps) ResolvePath(path string) (string, error) {
	if o.resolveErr != nil {
		return "", o.resolveErr
	}

	return strings.TrimRight(SubstituteServerTokens(path, o.user, o.se4fs), `\`), nil
}

func (o *fakeFolderOps) DirExists(value string) (bool, error) {
	o.statCnt++
	if err := o.statErr[value]; err != nil {
		return false, err
	}

	return o.dirs[value], nil
}

func (o *fakeFolderOps) EnsureDir(value string) error {
	o.mkdirCnt++
	if err := o.mkdirErr[value]; err != nil {
		return err
	}
	o.dirs[value] = true

	return nil
}

// networkDesktop / localDesktop : les deux gabarits que le serveur émet
// (DesktopPathResolver côté PHP) — repris VERBATIM, backslash final compris.
const (
	networkDesktopTemplate = `\\<se4fs>\users\<user>\Bureau\`
	localDesktopTemplate   = `%USERPROFILE%\Desktop\`
)

// resolvedNetworkDesktop : ce que le fake produit pour le gabarit réseau.
const resolvedNetworkDesktop = `\\se4fs-0991229y\users\mickael.barbier\Bureau`

func folderItem(folder, path string) StateItem {
	return StateItem{
		Type:      "folders",
		Semantics: "exclusive",
		Hash:      "h",
		Payload:   map[string]any{"folder": folder, "path": path},
	}
}

// pinnedFolderItem : l'item tel que le SERVEUR l'émet réellement (§7.12) —
// avec `quick_access: pinned`, pour que l'Accès rapide suive la redirection.
func pinnedFolderItem(folder, path string) StateItem {
	return StateItem{
		Type:      "folders",
		Semantics: "exclusive",
		Hash:      "h",
		Payload:   map[string]any{"folder": folder, "path": path, "quick_access": "pinned"},
	}
}

func newFoldersHandler() (*FoldersHandler, *fakeFolderOps, *fakeRegistryOps) {
	ops := newFakeFolderOps()
	reg := newFakeRegistryOps()

	return &FoldersHandler{Ops: ops, Registry: reg}, ops, reg
}

// desktopValue : lecture directe de la valeur écrite dans le fake registre.
func desktopValue(reg *fakeRegistryOps) (RegistryValue, bool) {
	v, ok := reg.values[keyID("HKCU", userShellFoldersPath, "Desktop")]

	return v, ok
}

// --- Le geste nominal --------------------------------------------------------

func TestFoldersApplyCreatesDirThenWritesExpandSzValue(t *testing.T) {
	h, ops, reg := newFoldersHandler()
	items := []StateItem{folderItem("desktop", networkDesktopTemplate)}

	// Rien n'existe : ni le dossier, ni la valeur — c'est l'état d'un profil
	// itinérant créé après la coupure de la GPO legacy.
	compliant, err := h.Test(items)
	if err != nil {
		t.Fatalf("Test : %v", err)
	}
	if compliant {
		t.Fatal("un profil sans redirection ne peut pas être conforme")
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("Apply : %v", err)
	}

	if !ops.dirs[resolvedNetworkDesktop] {
		t.Errorf("le dossier cible %q doit avoir été créé", resolvedNetworkDesktop)
	}
	v, ok := desktopValue(reg)
	if !ok {
		t.Fatal("la valeur User Shell Folders\\Desktop doit avoir été écrite")
	}
	// REG_EXPAND_SZ est non négociable : c'est ce type qui fait de
	// `%USERPROFILE%\Desktop` un chemin valable pour CHAQUE utilisateur.
	if v.Kind != "REG_EXPAND_SZ" {
		t.Errorf("type de valeur : got %s, want REG_EXPAND_SZ", v.Kind)
	}
	if v.Str != resolvedNetworkDesktop {
		t.Errorf("valeur : got %q, want %q", v.Str, resolvedNetworkDesktop)
	}
}

func TestFoldersApplyCreatesDirBeforeWritingValue(t *testing.T) {
	h, ops, reg := newFoldersHandler()
	// Le dossier ne peut PAS être créé (partage en lecture seule, quota, ACL).
	ops.mkdirErr[resolvedNetworkDesktop] = errors.New("accès refusé")

	err := h.Apply([]StateItem{folderItem("desktop", networkDesktopTemplate)})
	if err == nil {
		t.Fatal("une création de dossier en échec doit remonter")
	}
	// LE point : aucune redirection n'a été posée. Rediriger le Bureau vers un
	// dossier qui n'existe pas donne un bureau vide à l'utilisateur — et la
	// valeur, une fois dans le profil itinérant, le suit sur TOUS ses postes.
	if _, ok := desktopValue(reg); ok {
		t.Error("aucune valeur ne doit être écrite tant que le dossier n'existe pas")
	}
}

// --- Idempotence -------------------------------------------------------------

func TestFoldersIsIdempotentOnStableState(t *testing.T) {
	h, ops, reg := newFoldersHandler()
	items := []StateItem{folderItem("desktop", networkDesktopTemplate)}

	if err := h.Apply(items); err != nil {
		t.Fatalf("Apply n°1 : %v", err)
	}
	_ = h.TakeRefreshRequest() // consomme le geste de la 1re convergence

	writesAfterFirst := reg.writeCnt
	mkdirAfterFirst := ops.mkdirCnt

	compliant, err := h.Test(items)
	if err != nil {
		t.Fatalf("Test : %v", err)
	}
	if !compliant {
		t.Fatal("après convergence, l'état doit être conforme")
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("Apply n°2 : %v", err)
	}
	if reg.writeCnt != writesAfterFirst {
		t.Errorf("2e passe : %d écriture(s) de trop", reg.writeCnt-writesAfterFirst)
	}
	if ops.mkdirCnt != mkdirAfterFirst {
		t.Errorf("2e passe : %d création(s) de dossier de trop", ops.mkdirCnt-mkdirAfterFirst)
	}
	// Zéro écriture ⇒ zéro relance d'Explorer : sans ça, le bureau de
	// l'utilisateur clignoterait à CHAQUE cycle de convergence.
	if level := h.TakeRefreshRequest(); level != RefreshNone {
		t.Errorf("passe stable : geste de rafraîchissement %v demandé, want RefreshNone", level)
	}
}

// --- Rafraîchissement --------------------------------------------------------

func TestFoldersRequestsExplorerRestartOnEffectiveChange(t *testing.T) {
	h, _, _ := newFoldersHandler()

	if err := h.Apply([]StateItem{folderItem("desktop", networkDesktopTemplate)}); err != nil {
		t.Fatalf("Apply : %v", err)
	}

	// Explorer ne relit `User Shell Folders` qu'à SON démarrage : un
	// SHChangeNotify ne suffirait pas, la redirection resterait sans effet
	// jusqu'au logon suivant.
	if level := h.TakeRefreshRequest(); level != RefreshExplorerRestart {
		t.Errorf("geste demandé : got %v, want RefreshExplorerRestart", level)
	}
	// Consommation PAR PASSE : pas de geste fantôme au cycle suivant.
	if level := h.TakeRefreshRequest(); level != RefreshNone {
		t.Errorf("2e lecture : got %v, want RefreshNone", level)
	}
}

// --- Dérives -----------------------------------------------------------------

func TestFoldersDetectsValuePointingElsewhere(t *testing.T) {
	h, ops, reg := newFoldersHandler()
	items := []StateItem{folderItem("desktop", networkDesktopTemplate)}
	ops.dirs[resolvedNetworkDesktop] = true
	// Le profil itinérant arrive d'un poste perdir : il porte encore le Bureau
	// LOCAL. C'est exactement le cas qui rendait les raccourcis invisibles.
	reg.values[keyID("HKCU", userShellFoldersPath, "Desktop")] = RegistryValue{
		Kind: "REG_EXPAND_SZ", Str: `%USERPROFILE%\Desktop`,
	}

	compliant, err := h.Test(items)
	if err != nil {
		t.Fatalf("Test : %v", err)
	}
	if compliant {
		t.Fatal("une redirection pointant ailleurs est une dérive")
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("Apply : %v", err)
	}
	if v, _ := desktopValue(reg); v.Str != resolvedNetworkDesktop {
		t.Errorf("après convergence : got %q, want %q", v.Str, resolvedNetworkDesktop)
	}
}

func TestFoldersDetectsMissingDirEvenWhenValueIsCorrect(t *testing.T) {
	h, ops, reg := newFoldersHandler()
	items := []StateItem{folderItem("desktop", networkDesktopTemplate)}
	// Valeur juste, dossier disparu (purge de home, restauration partielle) :
	// c'est précisément l'état cassé qu'on répare — il ne doit jamais passer
	// pour conforme.
	reg.values[keyID("HKCU", userShellFoldersPath, "Desktop")] = RegistryValue{
		Kind: "REG_EXPAND_SZ", Str: resolvedNetworkDesktop,
	}

	compliant, err := h.Test(items)
	if err != nil {
		t.Fatalf("Test : %v", err)
	}
	if compliant {
		t.Fatal("une redirection vers un dossier absent ne peut pas être conforme")
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("Apply : %v", err)
	}
	if !ops.dirs[resolvedNetworkDesktop] {
		t.Error("le dossier manquant doit avoir été recréé")
	}
	// La valeur était déjà bonne : aucune écriture registre. Mais Explorer, lui,
	// a démarré alors que le dossier n'existait pas — il regarde un emplacement
	// de repli et ne verra le dossier réapparu qu'après relance.
	if reg.writeCnt != 0 {
		t.Error("la valeur étant déjà conforme, aucune écriture ne doit avoir lieu")
	}
	if level := h.TakeRefreshRequest(); level != RefreshExplorerRestart {
		t.Errorf("création de dossier seule : geste %v, want RefreshExplorerRestart", level)
	}
}

// --- Le chemin local ---------------------------------------------------------

func TestFoldersLocalTemplateKeepsEnvVarUnexpanded(t *testing.T) {
	h, ops, reg := newFoldersHandler()
	ops.dirs[`%USERPROFILE%\Desktop`] = true

	if err := h.Apply([]StateItem{folderItem("desktop", localDesktopTemplate)}); err != nil {
		t.Fatalf("Apply : %v", err)
	}

	v, _ := desktopValue(reg)
	// Le `%USERPROFILE%` reste LITTÉRAL dans la ruche : c'est Windows qui
	// l'expanse à la lecture (REG_EXPAND_SZ). L'expanser ici graverait le
	// chemin d'un profil dans la valeur d'un autre — et ferait diverger la
	// valeur écrite de la valeur par défaut de Windows, donc une réécriture
	// et un redémarrage d'Explorer à chaque logon d'un poste perdir.
	if v.Str != `%USERPROFILE%\Desktop` {
		t.Errorf("valeur : got %q, want %%USERPROFILE%%\\Desktop littéral", v.Str)
	}
}

func TestFoldersTrimsTrailingSeparatorFromServerTemplate(t *testing.T) {
	h, ops, reg := newFoldersHandler()
	ops.dirs[resolvedNetworkDesktop] = true

	if err := h.Apply([]StateItem{folderItem("desktop", networkDesktopTemplate)}); err != nil {
		t.Fatalf("Apply : %v", err)
	}

	// Le serveur émet le gabarit avec un backslash terminal (convention legacy,
	// partagée avec `desktop_path`) ; Windows écrit sans. Sans ce trim, une
	// session vanilla serait éternellement « en dérive » et réécrite à chaque
	// cycle — donc un Explorer relancé à chaque cycle.
	v, _ := desktopValue(reg)
	if strings.HasSuffix(v.Str, `\`) {
		t.Errorf("la valeur ne doit pas se terminer par un séparateur : %q", v.Str)
	}
}

// --- Cible injoignable -------------------------------------------------------

func TestFoldersUnreachableTargetIsAnErrorNotADrift(t *testing.T) {
	h, ops, reg := newFoldersHandler()
	items := []StateItem{folderItem("desktop", networkDesktopTemplate)}
	ops.statErr[resolvedNetworkDesktop] = fmt.Errorf("serveur injoignable")

	if _, err := h.Test(items); err == nil {
		t.Fatal("un serveur de fichiers muet doit rendre le type en erreur")
	}
	if err := h.Apply(items); err == nil {
		t.Fatal("Apply doit remonter l'erreur d'accès")
	}
	// Le point : on n'écrit RIEN. Confondre « injoignable » et « absent »
	// ferait basculer un utilisateur sur un Bureau local le jour où le serveur
	// tousse — et la valeur le suivrait ensuite sur tous ses postes.
	if _, ok := desktopValue(reg); ok {
		t.Error("aucune redirection ne doit être posée quand la cible est injoignable")
	}
	if ops.mkdirCnt != 0 {
		t.Error("aucun dossier ne doit être créé quand la cible est injoignable")
	}
}

func TestFoldersUnresolvableTokensAbortWithoutWriting(t *testing.T) {
	h, ops, reg := newFoldersHandler()
	// Poste hors-domaine : ni SE4FS ni LOGONSERVER. Écrire `\\\users\…`
	// donnerait un Bureau mort.
	ops.resolveErr = errors.New("tokens non substituables")

	if _, err := h.Test([]StateItem{folderItem("desktop", networkDesktopTemplate)}); err == nil {
		t.Fatal("un chemin non résoluble doit rendre le type en erreur")
	}
	if err := h.Apply([]StateItem{folderItem("desktop", networkDesktopTemplate)}); err == nil {
		t.Fatal("Apply doit refuser un chemin non résoluble")
	}
	if _, ok := desktopValue(reg); ok {
		t.Error("aucune redirection ne doit être posée avec des tokens non résolus")
	}
}

// --- Enveloppe ---------------------------------------------------------------

func TestFoldersRejectsInvalidPayloads(t *testing.T) {
	cases := map[string]any{
		"payload non-map":   "desktop",
		"payload nil":       nil,
		"folder manquant":   map[string]any{"path": localDesktopTemplate},
		"path manquant":     map[string]any{"folder": "desktop"},
		"path vide":         map[string]any{"folder": "desktop", "path": ""},
		"dossier hors enum": map[string]any{"folder": "documents", "path": localDesktopTemplate},
		"dossier inventé":   map[string]any{"folder": "corbeille", "path": localDesktopTemplate},
		"folder non-string": map[string]any{"folder": 1, "path": localDesktopTemplate},
	}

	for name, payload := range cases {
		t.Run(name, func(t *testing.T) {
			h, _, reg := newFoldersHandler()
			items := []StateItem{{Type: "folders", Semantics: "exclusive", Hash: "h", Payload: payload}}

			if _, err := h.Test(items); err == nil {
				t.Error("enveloppe invalide : Test doit rendre une erreur")
			}
			if err := h.Apply(items); err == nil {
				t.Error("enveloppe invalide : Apply doit rendre une erreur")
			}
			// Un `folder` inconnu ne doit JAMAIS produire une écriture dans une
			// valeur devinée : l'enum est FERMÉ.
			if reg.writeCnt != 0 {
				t.Error("aucune écriture ne doit avoir lieu sur une enveloppe invalide")
			}
		})
	}
}

func TestFoldersEmptyTargetIsCompliantAndWritesNothing(t *testing.T) {
	h, _, reg := newFoldersHandler()

	// Contrat §8 : type présent mais tableau vide = « aucune redirection
	// gérée ». Le handler ne touche RIEN — il ne remet surtout pas le Bureau
	// par défaut (ce serait une purge non demandée).
	compliant, err := h.Test(nil)
	if err != nil {
		t.Fatalf("Test : %v", err)
	}
	if !compliant {
		t.Error("une cible vide est conforme par construction")
	}
	if err := h.Apply(nil); err != nil {
		t.Fatalf("Apply : %v", err)
	}
	if reg.writeCnt != 0 || reg.deleteCnt != 0 {
		t.Error("une cible vide ne doit produire ni écriture ni suppression")
	}
}

// --- Câblage dans le moteur --------------------------------------------------

func TestFoldersHandlerConvergesThroughEngine(t *testing.T) {
	h, _, _ := newFoldersHandler()
	engine := &Engine{Handlers: map[string]Handler{"folders": h}}

	applied := AppliedState{}
	reports := engine.RunPass([]StateItem{folderItem("desktop", networkDesktopTemplate)}, applied)

	if len(reports) != 1 {
		t.Fatalf("verdicts : got %d, want 1", len(reports))
	}
	if reports[0].Status == "error" {
		t.Errorf("verdict inattendu : %s (%s)", reports[0].Status, reports[0].Detail)
	}
}

// --- Accès rapide -------------------------------------------------------------

func TestFoldersPinsQuickAccessAndUnpinsTheReplacedLocation(t *testing.T) {
	h, ops, reg := newFoldersHandler()
	items := []StateItem{pinnedFolderItem("desktop", networkDesktopTemplate)}

	// État de départ = un profil vanilla : Bureau LOCAL, épinglé d'office par
	// Windows à la création du profil. C'est cette épingle-là qui devient
	// menteuse dès qu'on redirige.
	reg.values[keyID("HKCU", userShellFoldersPath, "Desktop")] = RegistryValue{
		Kind: "REG_EXPAND_SZ", Str: `%USERPROFILE%\Desktop`,
	}
	ops.pins[`%USERPROFILE%\Desktop`] = true

	if err := h.Apply(items); err != nil {
		t.Fatalf("Apply : %v", err)
	}

	if ops.pins[`%USERPROFILE%\Desktop`] {
		t.Error("l'épingle de l'emplacement REMPLACÉ doit avoir été retirée")
	}
	if !ops.pins[resolvedNetworkDesktop] {
		t.Errorf("le nouvel emplacement %q doit être épinglé", resolvedNetworkDesktop)
	}
}

func TestFoldersNeverUnpinsALocationItDidNotReplace(t *testing.T) {
	h, ops, reg := newFoldersHandler()
	items := []StateItem{pinnedFolderItem("desktop", networkDesktopTemplate)}
	ops.dirs[resolvedNetworkDesktop] = true
	// Valeur déjà conforme : ce poste ne remplace RIEN.
	reg.values[keyID("HKCU", userShellFoldersPath, "Desktop")] = RegistryValue{
		Kind: "REG_EXPAND_SZ", Str: resolvedNetworkDesktop,
	}
	// Une épingle posée par un AUTRE poste du même utilisateur (la jumplist vit
	// dans %APPDATA%, donc dans le profil itinérant PARTAGÉ).
	ops.pins[`C:\Users\mickael.barbier\Desktop`] = true

	if err := h.Apply(items); err != nil {
		t.Fatalf("Apply : %v", err)
	}

	// LE point (finding 🔴 de la review 27.21, transposé) : sans `previous`, le
	// handler « nettoierait » un emplacement partagé et déclencherait une guerre
	// de désépinglage entre les postes de l'utilisateur.
	if !ops.pins[`C:\Users\mickael.barbier\Desktop`] {
		t.Error("une épingle que ce poste n'a pas remplacée ne doit JAMAIS être retirée")
	}
	if ops.unpinCnt != 0 {
		t.Errorf("aucun désépinglage attendu, %d effectué(s)", ops.unpinCnt)
	}
}

func TestFoldersRepinsWhenUserUnpinnedByHand(t *testing.T) {
	h, ops, reg := newFoldersHandler()
	items := []StateItem{pinnedFolderItem("desktop", networkDesktopTemplate)}
	ops.dirs[resolvedNetworkDesktop] = true
	reg.values[keyID("HKCU", userShellFoldersPath, "Desktop")] = RegistryValue{
		Kind: "REG_EXPAND_SZ", Str: resolvedNetworkDesktop,
	}
	// Registre conforme, mais plus d'épingle : l'utilisateur l'a retirée.

	compliant, err := h.Test(items)
	if err != nil {
		t.Fatalf("Test : %v", err)
	}
	if compliant {
		t.Fatal("une redirection juste dont l'épingle a disparu n'est qu'à moitié appliquée")
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("Apply : %v", err)
	}
	if !ops.pins[resolvedNetworkDesktop] {
		t.Error("l'épingle doit être reposée (convergence level-triggered)")
	}
	// La valeur registre était bonne : aucune écriture, donc aucune relance
	// d'Explorer pour ce seul repinning.
	if reg.writeCnt != 0 {
		t.Error("aucune écriture registre attendue")
	}
}

func TestFoldersQuickAccessIsIdempotent(t *testing.T) {
	h, ops, _ := newFoldersHandler()
	items := []StateItem{pinnedFolderItem("desktop", networkDesktopTemplate)}

	if err := h.Apply(items); err != nil {
		t.Fatalf("Apply n°1 : %v", err)
	}
	pinsAfterFirst := ops.pinCnt

	compliant, err := h.Test(items)
	if err != nil {
		t.Fatalf("Test : %v", err)
	}
	if !compliant {
		t.Fatal("après convergence, l'état doit être conforme (épingle comprise)")
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("Apply n°2 : %v", err)
	}

	if ops.pinCnt != pinsAfterFirst {
		t.Errorf("2e passe : %d épinglage(s) de trop", ops.pinCnt-pinsAfterFirst)
	}
	if ops.unpinCnt != 0 {
		t.Errorf("2e passe : %d désépinglage(s) inattendu(s)", ops.unpinCnt)
	}
}

func TestFoldersUnmanagedQuickAccessTouchesNothing(t *testing.T) {
	h, ops, _ := newFoldersHandler()
	// Champ absent = `unmanaged` : comportement d'un agent antérieur, et porte
	// de sortie si un réglage rend un jour la main sur l'Accès rapide.
	items := []StateItem{folderItem("desktop", networkDesktopTemplate)}
	ops.pins[`%USERPROFILE%\Desktop`] = true

	if err := h.Apply(items); err != nil {
		t.Fatalf("Apply : %v", err)
	}

	if ops.pinCnt != 0 || ops.unpinCnt != 0 || ops.pinnedCnt != 0 {
		t.Error("`unmanaged` ⇒ l'Accès rapide n'est ni lu ni modifié (§8)")
	}
	if !ops.pins[`%USERPROFILE%\Desktop`] {
		t.Error("aucune épingle existante ne doit être touchée")
	}
	// La redirection, elle, s'applique normalement.
	compliant, err := h.Test(items)
	if err != nil {
		t.Fatalf("Test : %v", err)
	}
	if !compliant {
		t.Error("sans gestion de l'Accès rapide, la redirection seule suffit à être conforme")
	}
}

func TestFoldersQuickAccessFailureIsReported(t *testing.T) {
	h, ops, _ := newFoldersHandler()
	ops.pinErr = errors.New("shell indisponible")

	// Un échec d'épinglage n'est pas avalé : sans remontée, l'entrée d'Accès
	// rapide pourrirait en silence — précisément le mode de panne qu'on répare.
	if err := h.Apply([]StateItem{pinnedFolderItem("desktop", networkDesktopTemplate)}); err == nil {
		t.Fatal("un épinglage en échec doit remonter")
	}
}

func TestFoldersRejectsUnknownQuickAccessVerb(t *testing.T) {
	h, _, _ := newFoldersHandler()
	items := []StateItem{{
		Type: "folders", Semantics: "exclusive", Hash: "h",
		Payload: map[string]any{
			"folder": "desktop", "path": localDesktopTemplate, "quick_access": "pined",
		},
	}}

	// Verbe mal orthographié : refusé franchement. Le traduire silencieusement
	// en « on ne fait rien » rendrait la faute de frappe invisible.
	if _, err := h.Test(items); err == nil {
		t.Error("un verbe quick_access inconnu est une enveloppe invalide")
	}
}
