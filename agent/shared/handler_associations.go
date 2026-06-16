package shared

import (
	"crypto/md5" //nolint:gosec // hash UserChoice imposé par Windows (algorithme legacy, pas un usage cryptographique)
	"encoding/base64"
	"encoding/binary"
	"fmt"
	"sort"
	"strings"
	"unicode/utf16"
)

// Handler `associations` (exclusive PAR IDENTIFIANT / scope session) —
// Story 27.3bis. Logique PURE, OS-agnostique (les accès registre HKCU et la
// lecture du SID/experience/temps sont injectés via AssociationsOps) → testée
// sur l'hôte ; agent/windows ne fait que câbler
// golang.org/x/sys/windows/registry + la résolution des entrées poste.
//
// CŒUR DE RISQUE : le HASH USERCHOICE. Windows protège l'association par défaut
// d'une extension/d'un protocole par un hash anti-tamper. Écrire seulement
// `ProgId` sous `…\FileExts\<ext>\UserChoice` NE SUFFIT PAS : sans le `Hash`
// dérivé correct, Windows INVALIDE l'association et la réinitialise. Le hash est
// porté FIDÈLEMENT depuis `SFTA.ps1::Get-Hash` (legacy) — une seule constante
// fausse = hash silencieusement rejeté = association non appliquée (bug
// indétectable autrement). D'où les TESTS VECTORIELS (handler_associations_test.go).
//
// Le hash dépend d'entrées LUES SUR LE POSTE (SID de session, FileTime courant,
// GUID « user experience » de shell32.dll) — JAMAIS connues du serveur. Le
// payload ne porte donc que {identifier, progid, type} ; le hash est calculé ici.
//
// CONVERGENCE level-triggered, JAMAIS accumulation :
//   - test  : chaque identifiant cible a-t-il EXACTEMENT son ProgId cible sous
//     UserChoice ?
//   - apply : (ré)imposer ProgId+Hash pour les identifiants divergents.
//     IDEMPOTENT (2 passes sur état stable = aucune réécriture).
//
// « DÉSACTIVER = CESSER DE GÉRER » (piège n° 5) : une association retirée côté
// serveur DISPARAÎT de la liste → le handler NE TOUCHE PLUS à cette clé.
//
// PROGID ABSENT (D-Henri n°5) : si le ProgId cible n'est PAS enregistré sur le
// poste, l'agent NE supprime PAS et NE réécrit PAS la clé UserChoice existante
// (choix utilisateur PRÉSERVÉ, pas de clobber, pas de suppression-avant-réécriture
// sur ProgId absent). Statut `error` NON fatal (isolation par item), `detail`
// explicite, PAS de réécriture en boucle d'un défaut inapplicable.

// AssociationSpec : une association cible (un item du payload `associations`).
type AssociationSpec struct {
	Identifier string // extension ".pdf" ou protocole "http"
	ProgID     string // ProgId Windows cible
	Type       string // "file" | "protocol"
}

// identity : clé d'identité {identifier} insensible à la casse (Windows l'est) —
// sert les logs, l'unicité interne et le tri déterministe.
func (s AssociationSpec) identity() string {
	return strings.ToLower(s.Identifier)
}

// isProtocol : l'association cible-t-elle un protocole (UrlAssociations) plutôt
// qu'une extension (FileExts) ?
func (s AssociationSpec) isProtocol() bool {
	return strings.EqualFold(s.Type, "protocol")
}

// AssociationsOps : accès spécifiques à l'OS, injectés (testable hôte).
// L'impl Windows vit dans agent/windows/handler_associations_windows.go ; un
// fake en mémoire couvre les tests.
type AssociationsOps interface {
	// ReadUserChoiceProgID lit le ProgId réel inscrit sous UserChoice pour
	// l'identifiant (selon isProtocol). present=false si la clé/valeur n'existe
	// pas (dérive à corriger). err = accès refusé / ruche absente.
	ReadUserChoiceProgID(spec AssociationSpec) (progID string, present bool, err error)

	// ProgIDRegistered indique si le ProgId cible est enregistré/installé sur le
	// poste. false → l'agent NE touche PAS la clé existante (D-Henri n°5).
	ProgIDRegistered(progID string) (bool, error)

	// WriteUserChoice supprime l'ancienne clé UserChoice puis (ré)écrit
	// `ProgId` + `Hash` (calculé via HashFor). Idempotent du point de vue du
	// résultat. err = accès refusé / écriture rejetée.
	WriteUserChoice(spec AssociationSpec, hash string) error

	// SessionInputs retourne les entrées poste du hash : SID (minuscule),
	// FileTime hex courant (little-endian, minuscule), chaîne « user
	// experience » (GUID de shell32.dll). Lues une seule fois par passe.
	SessionInputs() (sid, dateTimeHex, userExperience string, err error)
}

// AssociationsHandler : handler exclusive-par-identifiant branché dans le moteur
// (engine.go) — la machine d'états §5 reste au moteur, JAMAIS ici.
type AssociationsHandler struct {
	Ops AssociationsOps
	Log *Logger
}

// desiredSpecs : parse + dédoublonne par identifiant les items cible. Le serveur
// garantit déjà un item unique par identifiant (exclusive par identifiant au
// compilateur) ; défense : la DERNIÈRE occurrence fait foi. Ordre déterministe.
func (h *AssociationsHandler) desiredSpecs(items []StateItem) ([]AssociationSpec, error) {
	byIdentity := map[string]AssociationSpec{}
	order := []string{}
	for _, item := range items {
		spec, ok := parseAssociationSpec(item.Payload)
		if !ok {
			return nil, fmt.Errorf("payload associations inattendu : enveloppe invalide")
		}
		id := spec.identity()
		if _, seen := byIdentity[id]; !seen {
			order = append(order, id)
		}
		byIdentity[id] = spec
	}

	sort.Strings(order)
	specs := make([]AssociationSpec, 0, len(order))
	for _, id := range order {
		specs = append(specs, byIdentity[id])
	}

	return specs, nil
}

// Test : chaque identifiant cible a-t-il EXACTEMENT son ProgId cible sous
// UserChoice ? Un identifiant absent ou divergent = non conforme. Une erreur
// d'accès remonte (le moteur rend error pour le type).
//
// ProgId absent du poste : on NE considère PAS l'item comme conforme (le défaut
// n'est pas appliqué) — mais apply NE clobberera pas pour autant (D-Henri n°5) ;
// l'item sera rapporté error non fatal. Ici on renvoie « non conforme » pour que
// le moteur déclenche apply, qui décidera (et rapportera error sans toucher).
func (h *AssociationsHandler) Test(items []StateItem) (bool, error) {
	specs, err := h.desiredSpecs(items)
	if err != nil {
		return false, err
	}

	for _, spec := range specs {
		registered, err := h.Ops.ProgIDRegistered(spec.ProgID)
		if err != nil {
			return false, fmt.Errorf("vérification du ProgId %q (%s) : %w", spec.ProgID, spec.identity(), err)
		}
		if !registered {
			// ProgId absent : le défaut N'EST PAS appliqué → non conforme. Apply
			// gérera la préservation du choix utilisateur + le statut error.
			return false, nil
		}

		actual, present, err := h.Ops.ReadUserChoiceProgID(spec)
		if err != nil {
			return false, fmt.Errorf("lecture de UserChoice %s : %w", spec.identity(), err)
		}
		if !present || !strings.EqualFold(actual, spec.ProgID) {
			return false, nil
		}
	}

	return true, nil
}

// Apply : converge — (ré)impose ProgId+Hash pour les identifiants divergents.
// Idempotent (un identifiant déjà conforme n'est pas réécrit). EFFORT MAXIMAL :
// on tente TOUS les identifiants ; la première erreur est remontée à la fin
// (les associations saines convergent quand même, isolation inter-items AC5).
//
// ProgId NON enregistré (D-Henri n°5) : on NE supprime PAS et NE réécrit PAS la
// clé UserChoice existante (choix utilisateur préservé) ; on remonte une erreur
// (error non fatal), SANS réécriture en boucle.
func (h *AssociationsHandler) Apply(items []StateItem) error {
	specs, err := h.desiredSpecs(items)
	if err != nil {
		return err
	}

	// Entrées poste du hash, lues une seule fois pour toute la passe.
	sid, dateTimeHex, userExperience, err := h.Ops.SessionInputs()
	if err != nil {
		return fmt.Errorf("lecture des entrées de session (SID/temps/experience) : %w", err)
	}

	var firstErr error
	for _, spec := range specs {
		registered, err := h.Ops.ProgIDRegistered(spec.ProgID)
		if err != nil {
			logError(h.Log, "Vérification du ProgId %q (%s) en échec : %v", spec.ProgID, spec.identity(), err)
			if firstErr == nil {
				firstErr = fmt.Errorf("vérification du ProgId %q (%s) : %w", spec.ProgID, spec.identity(), err)
			}

			continue
		}
		if !registered {
			// D-Henri n°5 : ProgId absent → on NE touche PAS la clé existante
			// (pas de clobber, pas de suppression-avant-réécriture). Choix
			// utilisateur conservé. Erreur NON fatale (isolation), pas de boucle.
			detail := fmt.Sprintf("ProgId %q non enregistré, choix utilisateur conservé (%s)", spec.ProgID, spec.identity())
			logWarning(h.Log, "Association %s ignorée : %s", spec.identity(), detail)
			if firstErr == nil {
				firstErr = fmt.Errorf("%s", detail)
			}

			continue
		}

		actual, present, err := h.Ops.ReadUserChoiceProgID(spec)
		if err != nil {
			logError(h.Log, "Lecture de UserChoice %s en échec : %v", spec.identity(), err)
			if firstErr == nil {
				firstErr = fmt.Errorf("lecture de UserChoice %s : %w", spec.identity(), err)
			}

			continue
		}
		if present && strings.EqualFold(actual, spec.ProgID) {
			continue // déjà conforme → idempotence (aucune réécriture)
		}

		hash := UserChoiceHash(spec, sid, dateTimeHex, userExperience)
		if err := h.Ops.WriteUserChoice(spec, hash); err != nil {
			logError(h.Log, "Écriture de UserChoice %s en échec : %v", spec.identity(), err)
			if firstErr == nil {
				firstErr = fmt.Errorf("écriture de UserChoice %s : %w", spec.identity(), err)
			}

			continue
		}
		logInfo(h.Log, "Association appliquée : %s → %s", spec.identity(), spec.ProgID)
	}

	return firstErr
}

// parseAssociationSpec : extrait un AssociationSpec d'un payload §3 brut. Champs
// identifier/progid/type manquants = enveloppe invalide (false) → le moteur
// rapporte error.
func parseAssociationSpec(raw any) (AssociationSpec, bool) {
	payload, ok := raw.(map[string]any)
	if !ok || payload == nil {
		return AssociationSpec{}, false
	}

	identifier, _ := payload["identifier"].(string)
	progID, _ := payload["progid"].(string)
	assocType, _ := payload["type"].(string)
	if identifier == "" || progID == "" || assocType == "" {
		return AssociationSpec{}, false
	}

	return AssociationSpec{Identifier: identifier, ProgID: progID, Type: assocType}, true
}

// UserChoiceHash calcule le hash anti-tamper UserChoice pour une association,
// FIDÈLEMENT iso `SFTA.ps1::Get-Hash` (legacy ~565-711).
//
//	baseInfo = ("{identifier}{sid}{progid}{dateTimeHex}{userExperience}").ToLower()
//	→ MD5 sur l'encodage UTF-16LE de (baseInfo + "\x00\x00")
//	→ deux passes de dérivation à constantes → 16 octets → XOR fold 8 octets
//	→ Base64.
func UserChoiceHash(spec AssociationSpec, sid, dateTimeHex, userExperience string) string {
	baseInfo := strings.ToLower(spec.Identifier + sid + spec.ProgID + dateTimeHex + userExperience)

	return getHash(baseInfo)
}

// getHash : portage à l'octet près de `Get-Hash` (SFTA.ps1). baseInfo est déjà
// en minuscules. Retourne le Base64 du hash 8 octets (ou "" si trop court, iso
// le legacy qui n'écrit rien dans ce cas — jamais atteint en pratique).
func getHash(baseInfo string) string {
	// bytesBaseInfo = UTF-16LE(baseInfo) + 0x00, 0x00 (terminateur).
	utf16Units := utf16.Encode([]rune(baseInfo))
	bytesBaseInfo := make([]byte, len(utf16Units)*2+2)
	for i, u := range utf16Units {
		binary.LittleEndian.PutUint16(bytesBaseInfo[i*2:], u)
	}
	// Les 2 derniers octets restent 0x00 0x00 (terminateur UTF-16 nul).

	sum := md5.Sum(bytesBaseInfo) //nolint:gosec // algorithme imposé par Windows
	bytesMD5 := sum[:]

	// $baseInfo.Length en PowerShell = nombre d'unités UTF-16 (chars). Pour le
	// texte ASCII + GUID concerné, = len(utf16Units).
	lengthBase := int64(len(utf16Units)*2) + 2
	// length = (($lengthBase -band 4) -le 1) + (Get-ShiftRight $lengthBase 2) - 1
	// ($lengthBase -band 4) -le 1 : booléen PowerShell → 1 si vrai, 0 si faux.
	band4le1 := int64(0)
	if (lengthBase & 4) <= 1 {
		band4le1 = 1
	}
	length := band4le1 + getShiftRight(lengthBase, 2) - 1

	if length <= 1 {
		return ""
	}

	// --- Passe 1 ---
	md51 := ((getLong(bytesMD5, 0) | 1) + 0x69FB0000)
	md52 := ((getLong(bytesMD5, 4) | 1) + 0x13DB0000)
	index := getShiftRight(length-2, 1)
	counter := index + 1

	var cache, outHash1, outHash2 int64
	var pdata int64
	for counter != 0 {
		r0 := convertInt32((getLong(bytesBaseInfo, pdata) + outHash1))
		r1 := convertInt32(getLong(bytesBaseInfo, pdata+4))
		pdata += 8
		r20 := convertInt32((r0 * md51) - (0x10FA9605 * getShiftRight(r0, 16)))
		r21 := convertInt32((0x79F8A395 * r20) + (0x689B6B9F * getShiftRight(r20, 16)))
		r3 := convertInt32((0xEA970001 * r21) - (0x3C101569 * getShiftRight(r21, 16)))
		r40 := convertInt32(r3 + r1)
		r50 := convertInt32(cache + r3)
		r60 := convertInt32((r40 * md52) - (0x3CE8EC25 * getShiftRight(r40, 16)))
		r61 := convertInt32((0x59C3AF2D * r60) - (0x2232E0F1 * getShiftRight(r60, 16)))
		outHash1 = convertInt32((0x1EC90001 * r61) + (0x35BD1EC9 * getShiftRight(r61, 16)))
		outHash2 = convertInt32(r50 + outHash1)
		cache = outHash2
		counter--
	}

	outHash := make([]byte, 16)
	binary.LittleEndian.PutUint32(outHash[0:], uint32(int32(outHash1)))
	binary.LittleEndian.PutUint32(outHash[4:], uint32(int32(outHash2)))

	// --- Passe 2 ---
	md51 = (getLong(bytesMD5, 0) | 1)
	md52 = (getLong(bytesMD5, 4) | 1)
	index = getShiftRight(length-2, 1)
	counter = index + 1

	cache, outHash1, outHash2 = 0, 0, 0
	pdata = 0
	for counter != 0 {
		r0 := convertInt32((getLong(bytesBaseInfo, pdata) + outHash1))
		pdata += 8
		r10 := convertInt32(r0 * md51)
		r11 := convertInt32((0xB1110000 * r10) - (0x30674EEF * getShiftRight(r10, 16)))
		r20 := convertInt32((0x5B9F0000 * r11) - (0x78F7A461 * getShiftRight(r11, 16)))
		r21 := convertInt32((0x12CEB96D * getShiftRight(r20, 16)) - (0x46930000 * r20))
		r3 := convertInt32((0x1D830000 * r21) + (0x257E1D83 * getShiftRight(r21, 16)))
		r40 := convertInt32(md52 * (r3 + getLong(bytesBaseInfo, pdata-4)))
		r41 := convertInt32((0x16F50000 * r40) - (0x5D8BE90B * getShiftRight(r40, 16)))
		r50 := convertInt32((0x96FF0000 * r41) - (0x2C7C6901 * getShiftRight(r41, 16)))
		r51 := convertInt32((0x2B890000 * r50) + (0x7C932B89 * getShiftRight(r50, 16)))
		outHash1 = convertInt32((0x9F690000 * r51) - (0x405B6097 * getShiftRight(r51, 16)))
		outHash2 = convertInt32(outHash1 + cache + r3)
		cache = outHash2
		counter--
	}

	binary.LittleEndian.PutUint32(outHash[8:], uint32(int32(outHash1)))
	binary.LittleEndian.PutUint32(outHash[12:], uint32(int32(outHash2)))

	// XOR fold → 8 octets.
	outHashBase := make([]byte, 8)
	hashValue1 := getLong(outHash, 8) ^ getLong(outHash, 0)
	hashValue2 := getLong(outHash, 12) ^ getLong(outHash, 4)
	binary.LittleEndian.PutUint32(outHashBase[0:], uint32(int32(hashValue1)))
	binary.LittleEndian.PutUint32(outHashBase[4:], uint32(int32(hashValue2)))

	return base64.StdEncoding.EncodeToString(outHashBase)
}

// getShiftRight : portage de `Get-ShiftRight` (SFTA.ps1). Si le bit 0x80000000
// est posé, décale ARITHMÉTIQUEMENT en forçant les bits hauts (xor 0xFFFF0000).
// Reproduit la sémantique PowerShell (long signé 64 bits) à l'identique.
func getShiftRight(value int64, count int) int64 {
	if value&0x80000000 != 0 {
		return (value >> uint(count)) ^ 0xFFFF0000
	}

	return value >> uint(count)
}

// getLong : portage de `Get-Long` — int32 little-endian aux octets [index..].
// Étend en int64 (les calculs PowerShell sont en long signé).
func getLong(bytes []byte, index int64) int64 {
	return int64(int32(binary.LittleEndian.Uint32(bytes[index : index+4])))
}

// convertInt32 : portage de `Convert-Int32` — tronque un long aux 32 bits de
// poids faible puis ré-interprète en int32 signé. Reproduit
// `[BitConverter]::ToInt32([BitConverter]::GetBytes($value), 0)`.
func convertInt32(value int64) int64 {
	return int64(int32(uint32(value)))
}
