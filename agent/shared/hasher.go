package shared

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"sort"
	"strconv"
	"unicode/utf8"
)

// StateHasher Go — miroir bit-à-bit de app/Services/Agent/StateHasher.php
// (algorithme unique et déterministe du contrat, FR7).
//
// SHA-256 sur une forme JSON canonicalisée : tri récursif lexicographique
// octet-par-octet des clés des objets (iso `ksort(…, SORT_STRING)` — les
// LISTES ne sont PAS triées, ordre significatif fixé par le serveur),
// encodage compact UTF-8 sans échappement HTML ni espaces (iso `json_encode(
// JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`).
//
// ⚠️ RÔLE : conformité prouvée par tests croisés contre les golden files
// (NFR13) + besoins futurs (empreintes locales 24.6). En RUNTIME l'agent ne
// recalcule JAMAIS un hash depuis sa propre sérialisation pour décider : il
// compare des hashes OPAQUES fournis par le serveur (ETag verbatim,
// item.hash).
//
// ⚠️ PIÈGES encoding/json évités ici (story 24.5, pièges n° 7-9) :
//   - les nombres sont décodés en json.Number (UseNumber) et ré-émis
//     VERBATIM — zéro float (contrat §4.1), jamais de float64 ;
//   - l'encodage des chaînes est implémenté À LA MAIN (appendCanonicalString)
//     pour reproduire php json_encode exactement : pas d'échappement HTML
//     (& < >), \b et \f nommés (Go émettrait \u0008/\u000c), U+2028/U+2029
//     échappés (PHP les échappe même avec JSON_UNESCAPED_UNICODE, sans
//     JSON_UNESCAPED_LINE_TERMINATORS), hex minuscule ;
//   - le tri des clés est EXPLICITE et récursif (iso sortRecursive PHP),
//     jamais délégué au tri implicite du marshal des maps ;
//   - compact sans espaces, UTF-8 brut, pas de '\n' final ;
//   - les objets sont décodés en OrderedMap (ordre du document préservé) :
//     la sémantique liste/objet de PHP dépend de l'ORDRE D'INSERTION des
//     clés (`array_is_list` après json_decode assoc), pas seulement de leur
//     ensemble — une map Go native perd cette information (review 24.5 #1).

// volatileStateKeys : champs volatils exclus du hash d'état (single point of
// truth, iso StateHasher::VOLATILE_STATE_KEYS). `ttl_seconds` ajouté par la
// Story 43.3 (AC3, D6) : le TTL dépend désormais du contexte (bascule
// sensible ou non, cf. app/Services/Agent/AgentTtlResolver.php côté PHP) mais
// reste une cadence de poll CONSEILLÉE, pas une donnée sémantique de la
// cible — un changement de TTL seul ne doit pas invalider l'ETag. HashState
// Go n'a AUCUN appelant runtime (seul le test l'appelle ; l'agent stocke
// l'ETag verbatim et ne recalcule jamais le hash d'état) : ce miroir n'est
// exercé que par les tests croisés (NFR13), aucun changement de comportement
// agent.
var volatileStateKeys = []string{"generated_at", "ttl_seconds"}

// OrderedMap — objet JSON dont l'ordre d'insertion des clés (= ordre du
// document) est préservé. Indispensable au miroir PHP : `json_decode(assoc)`
// produit une LISTE si les clés sont "0","1",…,"n-1" dans l'ordre du document
// (`array_is_list`), et sortRecursive ne trie jamais une liste.
type OrderedMap struct {
	keys []string
	m    map[string]any
}

func NewOrderedMap() *OrderedMap {
	return &OrderedMap{m: make(map[string]any)}
}

func (o *OrderedMap) Get(key string) (any, bool) {
	v, ok := o.m[key]

	return v, ok
}

// Set insère ou remplace ; une clé nouvelle s'ajoute en fin (iso PHP : écrire
// une clé existante ne change pas sa position).
func (o *OrderedMap) Set(key string, value any) {
	if _, ok := o.m[key]; !ok {
		o.keys = append(o.keys, key)
	}
	o.m[key] = value
}

func (o *OrderedMap) Delete(key string) {
	if _, ok := o.m[key]; !ok {
		return
	}
	delete(o.m, key)
	for i, k := range o.keys {
		if k == key {
			o.keys = append(o.keys[:i], o.keys[i+1:]...)

			break
		}
	}
}

func (o *OrderedMap) Len() int { return len(o.keys) }

// Keys retourne une copie des clés dans l'ordre du document.
func (o *OrderedMap) Keys() []string {
	out := make([]string, len(o.keys))
	copy(out, o.keys)

	return out
}

func (o *OrderedMap) clone() *OrderedMap {
	c := &OrderedMap{
		keys: make([]string, len(o.keys)),
		m:    make(map[string]any, len(o.m)),
	}
	copy(c.keys, o.keys)
	for k, v := range o.m {
		c.m[k] = v
	}

	return c
}

// DecodeJSON décode un document JSON en arbre générique avec UseNumber()
// (les nombres restent des json.Number — littéral préservé, zéro float).
// Objets = map[string]any (ordre perdu) : suffisant pour le PARSING du
// contrat (contract.go). Pour le HASH, utiliser DecodeJSONOrdered.
func DecodeJSON(raw []byte) (any, error) {
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()

	var v any
	if err := dec.Decode(&v); err != nil {
		return nil, fmt.Errorf("JSON invalide : %w", err)
	}

	return v, nil
}

// DecodeJSONOrdered décode un document JSON en arbre canonicalisable :
// *OrderedMap (objets, ordre du document préservé), []any (listes),
// json.Number (UseNumber — littéral verbatim), string, bool, nil.
func DecodeJSONOrdered(raw []byte) (any, error) {
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()

	v, err := decodeOrderedValue(dec)
	if err != nil {
		return nil, fmt.Errorf("JSON invalide : %w", err)
	}
	// Refuse tout contenu résiduel après le document (iso json.Decoder.Decode
	// sur un document unique — More() vrai = flux malformé pour notre usage).
	if dec.More() {
		return nil, fmt.Errorf("JSON invalide : contenu résiduel après le document")
	}

	return v, nil
}

func decodeOrderedValue(dec *json.Decoder) (any, error) {
	tok, err := dec.Token()
	if err != nil {
		return nil, err
	}

	switch t := tok.(type) {
	case json.Delim:
		switch t {
		case '{':
			obj := NewOrderedMap()
			for dec.More() {
				keyTok, err := dec.Token()
				if err != nil {
					return nil, err
				}
				key, ok := keyTok.(string)
				if !ok {
					return nil, fmt.Errorf("clé d'objet attendue, obtenu %v", keyTok)
				}
				val, err := decodeOrderedValue(dec)
				if err != nil {
					return nil, err
				}
				obj.Set(key, val)
			}
			if _, err := dec.Token(); err != nil { // consomme '}'
				return nil, err
			}

			return obj, nil
		case '[':
			list := []any{}
			for dec.More() {
				val, err := decodeOrderedValue(dec)
				if err != nil {
					return nil, err
				}
				list = append(list, val)
			}
			if _, err := dec.Token(); err != nil { // consomme ']'
				return nil, err
			}

			return list, nil
		default:
			return nil, fmt.Errorf("délimiteur inattendu %v", t)
		}
	default:
		// json.Number, string, bool, nil — déjà décodés par Token().
		return tok, nil
	}
}

// HashState hashe un état cible complet (enveloppe) : `generated_at` et
// `ttl_seconds` (Story 43.3, AC3) sont exclus AVANT canonicalisation, de
// sorte que seuls des changements sémantiques modifient le hash (iso
// StateHasher::hashState).
func HashState(state *OrderedMap) (string, error) {
	clone := state.clone()
	for _, key := range volatileStateKeys {
		clone.Delete(key)
	}

	return hashCanonical(clone)
}

// HashItem hashe le contenu *définissant* d'un item : sa propre clé `hash`
// est exclue (sinon dépendance circulaire — iso StateHasher::hashItem).
func HashItem(item *OrderedMap) (string, error) {
	clone := item.clone()
	clone.Delete("hash")

	return hashCanonical(clone)
}

func hashCanonical(v any) (string, error) {
	canonical, err := Canonicalize(v)
	if err != nil {
		return "", err
	}
	sum := sha256.Sum256(canonical)

	return hex.EncodeToString(sum[:]), nil
}

// Canonicalize produit la forme canonique JSON du contrat (§4) : tri récursif
// des clés des objets puis encodage compact iso-PHP. Accepte l'arbre issu de
// DecodeJSONOrdered (*OrderedMap, []any, json.Number, string, bool, nil) —
// les map[string]any sont REFUSÉES (ordre du document perdu : la sémantique
// liste/objet PHP en dépend, une seule voie d'entrée évite tout hash faux).
func Canonicalize(v any) ([]byte, error) {
	var buf bytes.Buffer
	if err := appendCanonical(&buf, v); err != nil {
		return nil, err
	}

	return buf.Bytes(), nil
}

func appendCanonical(buf *bytes.Buffer, v any) error {
	switch value := v.(type) {
	case nil:
		buf.WriteString("null")
	case bool:
		if value {
			buf.WriteString("true")
		} else {
			buf.WriteString("false")
		}
	case json.Number:
		// Littéral wire VERBATIM. Le contrat interdit les floats (§4.1 —
		// leur sérialisation PHP dépend de serialize_precision, un float
		// rendrait le hash instable) : les entiers transitent inchangés.
		// NB review 24.5 #2 : un entier hors plage int PHP (> 2^63-1) serait
		// décodé en float côté PHP (`1.0e+23`) et divergerait — cas
		// hors-contrat des deux côtés (§4.1 « zéro float »), non gardé ici.
		buf.WriteString(value.String())
	case string:
		return appendCanonicalString(buf, value)
	case []any:
		// Liste : JAMAIS triée (ordre significatif), récursion sur les
		// éléments (iso sortRecursive).
		buf.WriteByte('[')
		for i, item := range value {
			if i > 0 {
				buf.WriteByte(',')
			}
			if err := appendCanonical(buf, item); err != nil {
				return err
			}
		}
		buf.WriteByte(']')
	case *OrderedMap:
		return appendCanonicalObject(buf, value)
	case map[string]any:
		return fmt.Errorf("canonicalisation : map[string]any refusée (ordre du document perdu) — décoder via DecodeJSONOrdered")
	default:
		return fmt.Errorf("canonicalisation : type non supporté %T (arbre attendu : DecodeJSONOrdered)", v)
	}

	return nil
}

// appendCanonicalObject reproduit la chaîne PHP `json_decode(assoc:true)` →
// `sortRecursive` → `json_encode` pour un objet JSON. La sémantique
// liste/objet se joue en DEUX temps (review 24.5 #1, confirmé contre le
// StateHasher PHP réel sur la VM, 2026-06-12) :
//
//  1. clés "0","1",…,"n-1" dans l'ORDRE DU DOCUMENT ⇒ `array_is_list` vrai
//     au décodage : LISTE, jamais triée — y compris à 11+ clés ;
//  2. sinon `ksort(…, SORT_STRING)` ; si les clés TRIÉES retombent sur
//     "0","1",…,"n-1" dans l'ordre (possible seulement ≤ 10 clés — au-delà,
//     "10" se trie avant "2"), `json_encode` ré-émet une LISTE ;
//  3. sinon OBJET, clés triées octet-par-octet.
//
// C'est aussi pourquoi `{}` ≡ `[]` dans la forme canonique (contrat §4.1 :
// « distinction non fiable »). "01" (non canonique) ou "-1" restent des clés
// de chaîne ⇒ objet, iso-PHP.
func appendCanonicalObject(buf *bytes.Buffer, o *OrderedMap) error {
	emitList := func(keys []string) error {
		buf.WriteByte('[')
		for i, k := range keys {
			if i > 0 {
				buf.WriteByte(',')
			}
			v, _ := o.Get(k)
			if err := appendCanonical(buf, v); err != nil {
				return err
			}
		}
		buf.WriteByte(']')

		return nil
	}

	// (1) array_is_list sur l'ordre du document.
	if keysAreSequential(o.keys) {
		return emitList(o.keys)
	}

	// (2) ksort SORT_STRING (tri lexicographique octet-par-octet : "10" < "9").
	sorted := o.Keys()
	sort.Strings(sorted)
	if keysAreSequential(sorted) {
		return emitList(sorted)
	}

	// (3) Objet, clés triées.
	buf.WriteByte('{')
	for i, k := range sorted {
		if i > 0 {
			buf.WriteByte(',')
		}
		if err := appendCanonicalString(buf, k); err != nil {
			return err
		}
		buf.WriteByte(':')
		v, _ := o.Get(k)
		if err := appendCanonical(buf, v); err != nil {
			return err
		}
	}
	buf.WriteByte('}')

	return nil
}

// keysAreSequential : vrai si keys == ["0","1",…,"n-1"] dans cet ordre.
// Vide ⇒ vrai (`{}` → `[]`, §4.1).
func keysAreSequential(keys []string) bool {
	for i, k := range keys {
		if k != strconv.Itoa(i) {
			return false
		}
	}

	return true
}

// appendCanonicalString encode une chaîne iso `json_encode(
// JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)` :
//   - `"` et `\` échappés ; `/` NON échappé ;
//   - \b \f \n \r \t nommés ; autres contrôles < 0x20 en \u00xx (hex
//     MINUSCULE, iso-PHP) ;
//   - U+2028/U+2029 échappés (PHP les échappe sans
//     JSON_UNESCAPED_LINE_TERMINATORS) ;
//   - tout le reste en UTF-8 brut (aucune normalisation : le serveur émet en
//     NFC et le hash compare octet à octet — contrat §4.1).
func appendCanonicalString(buf *bytes.Buffer, s string) error {
	if !utf8.ValidString(s) {
		// PHP lèverait une JsonException (JSON_THROW_ON_ERROR) : même refus.
		return fmt.Errorf("canonicalisation : chaîne UTF-8 invalide (%q)", s)
	}

	buf.WriteByte('"')
	for _, r := range s {
		switch r {
		case '"':
			buf.WriteString(`\"`)
		case '\\':
			buf.WriteString(`\\`)
		case '\b':
			buf.WriteString(`\b`)
		case '\f':
			buf.WriteString(`\f`)
		case '\n':
			buf.WriteString(`\n`)
		case '\r':
			buf.WriteString(`\r`)
		case '\t':
			buf.WriteString(`\t`)
		case '\u2028':
			buf.WriteString(`\u2028`)
		case '\u2029':
			buf.WriteString(`\u2029`)
		default:
			if r < 0x20 {
				fmt.Fprintf(buf, `\u%04x`, r)
			} else {
				buf.WriteRune(r)
			}
		}
	}
	buf.WriteByte('"')

	return nil
}
