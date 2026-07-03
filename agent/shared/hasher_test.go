package shared

import (
	"encoding/hex"
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

// FROZEN_STATE_HASH — hash d'état figé du golden file state.v1.json, dupliqué
// depuis tests/Unit/Services/Agent/ContractV1Test.php:33 (le hash est FIGÉ
// par le contrat : la duplication de la constante est admise, story 24.5).
// Bumpé SCIEMMENT par la Story 27.1 (payload `shortcuts` v1 réel — évolution
// mineure §9) : ce test croisé prouve que le hasher Go suit le StateHasher PHP
// sur le nouveau payload (NFR13).
// Re-bumpé SCIEMMENT (mode debug du poste, §9) : ajout du champ d'enveloppe
// `debug` (bool) au golden — champ forward-compatible inclus dans le hash.
// Re-bumpé SCIEMMENT par la Story 27.2 (§9) : ajout des items `printers` +
// `drives` (portée session, payloads v1 réels) au golden — ce test croisé
// prouve que le hasher Go suit le StateHasher PHP sur les nouveaux payloads
// (NFR13).
// Re-bumpé SCIEMMENT par la Story 27.7 (§9) : le payload `shortcuts` gagne
// `{icon_asset, icon_checksum}` (icône UPLOADÉE content-addressed) — champs
// ajoutés, forward-compatible. Ce test croisé prouve que le hasher Go suit le
// StateHasher PHP sur le payload étendu (NFR13).
// Re-bumpé SCIEMMENT par la Story 27.8 (§9) : la clé `mode` est RETIRÉE de
// chaque item d'état (item 5 clés → 4 : type/semantics/payload/hash —
// convergence STRICT inconditionnelle). Le hash de chaque item ET le hash
// d'état changent. Bumpé à l'IDENTIQUE côté PHP (ContractV1Test::FROZEN_STATE_HASH).
// Re-bumpé SCIEMMENT (normalisation de token) : le payload `drives` émet
// désormais le token CANONIQUE `<user>` au lieu de `<login>` (même notion =
// login de session ; `<login>` n'était jamais substitué côté agent → UNC
// littéral, lecteurs non montés). Le hash du drive item ET le hash d'état
// changent. Bumpé à l'IDENTIQUE côté PHP (ContractV1Test::FROZEN_STATE_HASH).
// Re-bumpé SCIEMMENT par la Story 27.10 (§9) : la SALLE passe de la portée
// session (item identity) à la portée MACHINE — nouvel item overlay
// `{kind:"machine", room}` (préchargement poste+salle au logon) ; l'item
// identity session perd `room`.
// Re-bumpé SCIEMMENT par la Story 27.3 (§9) : ajout d'UN item `registry`
// (portée session, payload v1 réel `{hive, path, name, type, value}` owné par
// les providers registry) au golden — type DÉJÀ figé §7, payload ajouté =
// forward-compatible, pas un major.
// Rebase 27.3 sur main (27.10 inclus) : le golden combine désormais l'item
// overlay machine-scope (room) ET l'item registry session → 7 items, hash
// d'état RECALCULÉ. Bumpé à l'IDENTIQUE côté PHP (ContractV1Test::FROZEN_STATE_HASH).
// Re-bumpé SCIEMMENT par la Story 27.3bis (§9) : ajout d'UN item `associations`
// (portée session, payload v1 réel `{identifier, progid, type}` owné par
// AssociationsStateProvider) au golden — type DÉJÀ figé §7, payload ajouté =
// forward-compatible, pas un major. Le hash UserChoice n'est JAMAIS au payload
// (calculé agent-side). 8 items, hash d'état RECALCULÉ. Bumpé à l'IDENTIQUE
// côté PHP (ContractV1Test::FROZEN_STATE_HASH).
// Re-bumpé SCIEMMENT par la Story 27.4 (§9) : ajout d'UN item `app_config`
// (aggregate, payload v1 réel `{app_kind, policies}` owné par
// AppConfigStateProvider) au golden — type DÉJÀ figé §7, payload ajouté =
// forward-compatible, pas un major. Les policies sont CONCRÈTES (jamais un id de
// scope), sans float (§4.1). 9 items, hash d'état RECALCULÉ. Bumpé à l'IDENTIQUE
// côté PHP (ContractV1Test::FROZEN_STATE_HASH — test croisé NFR13).
//
// Correctif post-review 2026-06-17 (review #1) : l'item `app_config` passe de la
// portée `session` à la portée `machine` (`policies.json` machine-wide,
// admin-write, écrit par le service SYSTEM ; résolu PAR PARC niveaux 1-4). Le
// déplacement de portée RECALCULE le hash d'état (machine = 2, session = 6) ;
// bumpé à l'IDENTIQUE côté PHP (test croisé NFR13).
// Re-bumpé SCIEMMENT par la Story 27.5 (§9) : ajout d'UN item `applications`
// (aggregate, portée MACHINE) — payload v1 réel `{app_id, name}` owné par
// ApplicationsStateProvider (projection de l'ensemble cible WPKG). Type DÉJÀ figé
// §7, payload ajouté = forward-compatible. machine = 3, 10 items au total, hash
// d'état RECALCULÉ. Bumpé à l'IDENTIQUE côté PHP (ContractV1Test::FROZEN_STATE_HASH).
// Re-bumpé (lecteurs réseau natifs, décision Henri 2026-06-29) : DrivesStateProvider
// émet le jeu standard FIXE {K: home `\\<se4fs>\users\<user>\`, H: classes
// `\\<se4fs>\classes\`} au lieu d'un lecteur de classe sur K:. Le golden passe d'UN
// à DEUX items drives (11 items au total) → hash item drives ET hash d'état
// recalculés. Bumpé à l'IDENTIQUE côté PHP (ContractV1Test::FROZEN_STATE_HASH).
// Re-bumpé SCIEMMENT par la Story 35.1 (§9) : champ additif `ensure ∈
// present|absent` sur les items `registry` — le golden gagne UN item de
// SUPPRESSION en portée MACHINE (payload 4 clés `{hive, path, name,
// ensure:"absent"}`, clé DNSClient\EnableMulticast de `llmnr_disabled`, ni
// `type` ni `value`). Champ OPTIONNEL dont l'absence vaut `present` : les items
// d'écriture existants restent BYTE-IDENTIQUES (le serveur n'émet jamais
// `ensure:"present"` explicite) → forward-compatible, pas un major.
// `report.v1.json` INCHANGÉ (les items de rapport ne portent aucun payload).
// machine = 4, 12 items au total, hash d'état RECALCULÉ. Bumpé à l'IDENTIQUE
// côté PHP (ContractV1Test::FROZEN_STATE_HASH — test croisé NFR13).
const frozenStateHash = "f3d22e9f81d927d3cfc54ecf7f850d92a9a012135857df53e7fd91d82392be7f"

// goldenFile lit un golden file canonique EN PLACE (NFR13 : un seul jeu de
// golden files, partagé serveur ⇄ agent — jamais copié dans agent/).
func goldenFile(t *testing.T, name string) []byte {
	t.Helper()
	raw, err := os.ReadFile(filepath.Join("..", "..", "tests", "Fixtures", "Agent", name))
	if err != nil {
		t.Fatalf("golden file %s illisible : %v", name, err)
	}

	return raw
}

func decodeMap(t *testing.T, raw []byte) *OrderedMap {
	t.Helper()
	v, err := DecodeJSONOrdered(raw)
	if err != nil {
		t.Fatalf("décodage : %v", err)
	}
	m, ok := v.(*OrderedMap)
	if !ok {
		t.Fatalf("objet JSON attendu, obtenu %T", v)
	}

	return m
}

func mustGet(t *testing.T, o *OrderedMap, key string) any {
	t.Helper()
	v, ok := o.Get(key)
	if !ok {
		t.Fatalf("clé %q absente", key)
	}

	return v
}

// --- Tests croisés golden files (AC3, NFR13) --------------------------------

func TestHashStateGoldenMatchesFrozenHash(t *testing.T) {
	state := decodeMap(t, goldenFile(t, "state.v1.json"))

	got, err := HashState(state)
	if err != nil {
		t.Fatalf("HashState : %v", err)
	}
	if got != frozenStateHash {
		t.Errorf("hash du golden state divergent du serveur PHP :\n  got  %s\n  want %s", got, frozenStateHash)
	}
}

func TestHashStateExcludesVolatileGeneratedAt(t *testing.T) {
	state := decodeMap(t, goldenFile(t, "state.v1.json"))
	state.Set("generated_at", "2031-01-01T00:00:00+00:00")

	got, err := HashState(state)
	if err != nil {
		t.Fatalf("HashState : %v", err)
	}
	// Vérifié contre le StateHasher PHP réel (VM, 2026-06-12) : le même état
	// avec generated_at = 2031-01-01 produit aussi le hash figé.
	if got != frozenStateHash {
		t.Errorf("generated_at doit être exclu du hash : got %s, want %s", got, frozenStateHash)
	}

	state.Delete("generated_at")
	got, err = HashState(state)
	if err != nil {
		t.Fatalf("HashState : %v", err)
	}
	if got != frozenStateHash {
		t.Errorf("generated_at absent doit donner le même hash : got %s, want %s", got, frozenStateHash)
	}
}

func TestHashItemGoldenItemsMatchTheirHashFields(t *testing.T) {
	state := decodeMap(t, goldenFile(t, "state.v1.json"))

	checked := 0
	for _, scope := range []string{"machine", "session", "machine_user"} {
		items, ok := mustGet(t, state, scope).([]any)
		if !ok {
			t.Fatalf("portée %s : liste attendue", scope)
		}
		for i, raw := range items {
			item, ok := raw.(*OrderedMap)
			if !ok {
				t.Fatalf("portée %s item %d : objet attendu", scope, i)
			}
			want, _ := mustGet(t, item, "hash").(string)

			got, err := HashItem(item)
			if err != nil {
				t.Fatalf("HashItem %s[%d] : %v", scope, i, err)
			}
			if got != want {
				itemType, _ := item.Get("type")
				t.Errorf("hash item %s[%d] (%v) divergent :\n  got  %s\n  want %s",
					scope, i, itemType, got, want)
			}
			checked++
		}
	}
	if checked != 12 {
		t.Errorf("12 items attendus dans le golden state (machine room 27.10 + registry session 27.3 + associations session 27.3bis + app_config machine 27.4 + applications machine 27.5 + drives K:/H: natifs + registry absent machine 35.1), %d vérifiés", checked)
	}
}

func TestHashItemExcludesItsOwnHashKey(t *testing.T) {
	state := decodeMap(t, goldenFile(t, "state.v1.json"))
	items := mustGet(t, state, "session").([]any)
	item := items[0].(*OrderedMap)
	want := mustGet(t, item, "hash").(string)

	item.Set("hash", "0000000000000000000000000000000000000000000000000000000000000000")
	got, err := HashItem(item)
	if err != nil {
		t.Fatalf("HashItem : %v", err)
	}
	if got != want {
		t.Errorf("la clé hash doit être exclue (dépendance circulaire) : got %s, want %s", got, want)
	}
}

// --- Champ `ensure` (Story 35.1) : entre dans la canonicalisation ------------
//
// AUCUNE modification du hasher : la canonicalisation générique (tri récursif
// + JSON compact) intègre naturellement tout champ nouveau du payload. Ces
// tests le PROUVENT (AC1) — jumeaux des tests PHP (StateHasherTest).
func TestHashItemEnsureFieldChangesTheHash(t *testing.T) {
	withEnsure := decodeMap(t, []byte(`{"type":"registry","semantics":"exclusive","payload":{"hive":"HKLM","path":"SOFTWARE\\P","name":"N","ensure":"absent"}}`))
	withoutEnsure := decodeMap(t, []byte(`{"type":"registry","semantics":"exclusive","payload":{"hive":"HKLM","path":"SOFTWARE\\P","name":"N"}}`))

	a, err := HashItem(withEnsure)
	if err != nil {
		t.Fatalf("HashItem : %v", err)
	}
	b, err := HashItem(withoutEnsure)
	if err != nil {
		t.Fatalf("HashItem : %v", err)
	}
	if a == b {
		t.Errorf("deux items qui ne diffèrent que par `ensure` doivent avoir des hashes DISTINCTS (got %s)", a)
	}
}

func TestHashItemWriteItemWithoutEnsureKeepsPreStoryHash(t *testing.T) {
	// Non-régression byte-identité (piège n°1) : un item d'écriture 5 clés SANS
	// `ensure` garde EXACTEMENT son hash d'avant la story 35.1 (valeur figée =
	// hash historique de l'item registry HKCU du golden, inchangé depuis 27.3).
	item := decodeMap(t, []byte(`{"type":"registry","semantics":"exclusive","payload":{"hive":"HKCU","path":"Software\\Microsoft\\Windows\\CurrentVersion\\Explorer\\Advanced","name":"HideFileExt","type":"REG_DWORD","value":0}}`))

	got, err := HashItem(item)
	if err != nil {
		t.Fatalf("HashItem : %v", err)
	}
	if got != "92730f99ed3e64f81e99c955e64bfb37da8fcc765aa1eb44373c9c4e4af686b5" {
		t.Errorf("le hash d'un item d'écriture sans `ensure` ne doit PAS changer : got %s", got)
	}
}

// --- Tests croisés PHP réels (cas tordus) ------------------------------------
//
// Les hashes attendus ont été calculés le 2026-06-12 avec le StateHasher PHP
// RÉEL (app/Services/Agent/StateHasher.php) sur la VM :
//
//	ssh /vm  php /tmp/hash_cases.php  (json_decode assoc + hashItem)
//
// Les sources JSON sont embarquées en HEX : ce sont les octets EXACTS que le
// PHP a hashés (imprimés en bin2hex par le script de calcul) — aucune
// ambiguïté d'échappement entre les deux mondes.
func TestHashItemCrossValidatedAgainstPhp(t *testing.T) {
	cases := []struct {
		name   string
		srcHex string // document JSON, octets exacts
		want   string // hashItem(json_decode($src, true)) du PHP réel
	}{
		{
			// Clés numériques : tri SORT_STRING ⇒ "10" < "9"
			name:   "numeric_keys_sort_string",
			srcHex: "7b2274797065223a227265676973747279222c227061796c6f6164223a7b223130223a2262222c2239223a2261227d7d",
			want:   "c47e97a2884ce6ba831ca768cbe7af264bc8becc41090ae6e9ae12361154711a",
		},
		{
			// Unicode NFC brut + backslashes + slash non échappé
			name:   "unicode_nfc_backslash_slash",
			srcHex: "7b226e616d65223a22c389636f6c6520c3a96cc3a8766520e2809420e29c93222c2270617468223a22433a5c5c55736572735c5cc3a96cc3a876652fc3a0227d",
			want:   "cfccfa3493d596c8092a6838bb1cb333a152d6a2634bdc225da1b776d5d870f6",
		},
		{
			// Imbrication : objets triés récursivement, listes JAMAIS triées
			name:   "nested_objects_and_lists",
			srcHex: "7b2262223a5b332c312c325d2c2261223a7b227a223a6e756c6c2c2279223a747275652c2278223a5b7b226b32223a227632222c226b31223a227631227d5d7d7d",
			want:   "777f6e6c4a2cd6c071a621871db811a4385b3482db89a6699bf9cbdca1bdaed2",
		},
		{
			// Objet vide ⇒ [] (contrat §4.1 : {} ≡ [] côté PHP assoc)
			name:   "empty_object_becomes_list",
			srcHex: "7b227061796c6f6164223a7b7d2c2274797065223a226f7665726c6179227d",
			want:   "93e651ed68b450aa83e9c9b81135220b5af629e7dc1f1c7e355ba0c7e9cca07c",
		},
		{
			// Clés "0","1" ⇒ liste PHP après tri (json_decode assoc)
			name:   "sequential_numeric_keys_become_list",
			srcHex: "7b227061796c6f6164223a7b2231223a2262222c2230223a2261227d7d",
			want:   "8fa56f9773c551d6e2f716ca1963debc150edbef243368119e48310d06aa49ef",
		},
		{
			// Review 24.5 #1 — 11 clés "0".."10" DANS L'ORDRE du document :
			// array_is_list vrai au décodage ⇒ LISTE (jamais triée), alors
			// que le tri octet placerait "10" avant "2".
			// {"payload":{"0":"a",…,"9":"j","10":"k"}}
			name:   "eleven_sequential_keys_in_document_order_become_list",
			srcHex: "7b227061796c6f6164223a7b2230223a2261222c2231223a2262222c2232223a2263222c2233223a2264222c2234223a2265222c2235223a2266222c2236223a2267222c2237223a2268222c2238223a2269222c2239223a226a222c223130223a226b227d7d",
			want:   "8854cf4d516ff0a5cfa9b2af266ece83d298bc014cdcfca14864c86a7a166de0",
		},
		{
			// Review 24.5 #1 (contre-cas) — mêmes 11 clés mais HORS ordre
			// ("1" en tête) : array_is_list faux ⇒ ksort SORT_STRING ⇒ les
			// clés triées ("0","1","10","2",…) ne retombent PAS sur la
			// séquence ⇒ OBJET. La sémantique dépend de l'ordre du document.
			// {"payload":{"1":"b","0":"a","2":"c",…,"10":"k"}}
			name:   "eleven_sequential_keys_out_of_document_order_stay_object",
			srcHex: "7b227061796c6f6164223a7b2231223a2262222c2230223a2261222c2232223a2263222c2233223a2264222c2234223a2265222c2235223a2266222c2236223a2267222c2237223a2268222c2238223a2269222c2239223a226a222c223130223a226b227d7d",
			want:   "c99fe485d4eddf5fc0b1caf30b5b221ac757f41058dc5139c86b902906b9a5f3",
		},
		{
			// Caractères de contrôle : U+0001 + \b \f \t \n \r
			name:   "control_characters",
			srcHex: "7b2273223a22615c7530303031625c62635c66645c74655c6e665c7267227d",
			want:   "3cf25ca1672e9ced1d1308c62f9ee2d9b403b8f1ae14a91f75ed3e5a66d34349",
		},
		{
			// U+2028 / U+2029 : ré-échappés par PHP même en UNESCAPED_UNICODE
			name:   "line_paragraph_separators",
			srcHex: "7b2273223a22785c7532303238795c75323032397a227d",
			want:   "69b6daa9d23a30f70c14495b838668a9325e0abccd5d97c296cdb9966f3e7578",
		},
		{
			// Tri octet-par-octet toutes catégories : "0a" < "A" < "_" < "a" < "é"
			name:   "byte_order_mixed_keys",
			srcHex: "7b2261223a342c225f223a332c22c3a9223a352c2241223a322c223061223a317d",
			want:   "e38e5035e9a357e87fd83a6d346b956257b00cdf665cd8b27d03730ce21e5315",
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			src, err := hex.DecodeString(tc.srcHex)
			if err != nil {
				t.Fatalf("hex invalide : %v", err)
			}
			item := decodeMap(t, src)

			got, err := HashItem(item)
			if err != nil {
				t.Fatalf("HashItem : %v", err)
			}
			if got != tc.want {
				t.Errorf("hash divergent du PHP réel :\n  got  %s\n  want %s", got, tc.want)
			}
		})
	}
}

// --- Forme canonique exacte (lisibilité des invariants) ----------------------

func TestCanonicalizeProducesPhpCanonicalForm(t *testing.T) {
	cases := []struct {
		name    string
		srcHex  string
		wantHex string
	}{
		{
			// {"type":"registry","payload":{"10":"b","9":"a"}}
			// → {"payload":{"10":"b","9":"a"},"type":"registry"}
			name:    "sorted_keys_numeric_string_order",
			srcHex:  "7b2274797065223a227265676973747279222c227061796c6f6164223a7b223130223a2262222c2239223a2261227d7d",
			wantHex: "7b227061796c6f6164223a7b223130223a2262222c2239223a2261227d2c2274797065223a227265676973747279227d",
		},
		{
			// {"payload":{},"type":"overlay"} → {"payload":[],"type":"overlay"}
			name:    "empty_object_encodes_as_empty_list",
			srcHex:  "7b227061796c6f6164223a7b7d2c2274797065223a226f7665726c6179227d",
			wantHex: "7b227061796c6f6164223a5b5d2c2274797065223a226f7665726c6179227d",
		},
		{
			// {"payload":{"1":"b","0":"a"}} → {"payload":["a","b"]}
			name:    "sequential_keys_encode_as_list",
			srcHex:  "7b227061796c6f6164223a7b2231223a2262222c2230223a2261227d7d",
			wantHex: "7b227061796c6f6164223a5b2261222c2262225d7d",
		},
		{
			// Review 24.5 #1 — {"payload":{"0":"a",…,"10":"k"}} (ordre doc)
			// → {"payload":["a",…,"k"]} (canonHex PHP réel, VM 2026-06-12)
			name:    "eleven_keys_in_order_encode_as_list",
			srcHex:  "7b227061796c6f6164223a7b2230223a2261222c2231223a2262222c2232223a2263222c2233223a2264222c2234223a2265222c2235223a2266222c2236223a2267222c2237223a2268222c2238223a2269222c2239223a226a222c223130223a226b227d7d",
			wantHex: "7b227061796c6f6164223a5b2261222c2262222c2263222c2264222c2265222c2266222c2267222c2268222c2269222c226a222c226b225d7d",
		},
		{
			// Review 24.5 #1 (contre-cas) — mêmes clés hors ordre ("1" en
			// tête) → OBJET trié octet : {"0":"a","1":"b","10":"k","2":…}
			name:    "eleven_keys_out_of_order_encode_as_object",
			srcHex:  "7b227061796c6f6164223a7b2231223a2262222c2230223a2261222c2232223a2263222c2233223a2264222c2234223a2265222c2235223a2266222c2236223a2267222c2237223a2268222c2238223a2269222c2239223a226a222c223130223a226b227d7d",
			wantHex: "7b227061796c6f6164223a7b2230223a2261222c2231223a2262222c223130223a226b222c2232223a2263222c2233223a2264222c2234223a2265222c2235223a2266222c2236223a2267222c2237223a2268222c2238223a2269222c2239223a226a227d7d",
		},
		{
			// Unicode brut conservé, \\ échappé, / non échappé
			name:    "unicode_raw_slash_unescaped",
			srcHex:  "7b226e616d65223a22c389636f6c6520c3a96cc3a8766520e2809420e29c93222c2270617468223a22433a5c5c55736572735c5cc3a96cc3a876652fc3a0227d",
			wantHex: "7b226e616d65223a22c389636f6c6520c3a96cc3a8766520e2809420e29c93222c2270617468223a22433a5c5c55736572735c5cc3a96cc3a876652fc3a0227d",
		},
		{
			// Contrôles : U+0001 → u0001 backslash-échappé (hex minuscule), b/f/t/n/r nommés
			name:    "control_chars_named_and_u00xx",
			srcHex:  "7b2273223a22615c7530303031625c62635c66645c74655c6e665c7267227d",
			wantHex: "7b2273223a22615c7530303031625c62635c66645c74655c6e665c7267227d",
		},
		{
			// U+2028/U+2029 ré-échappés  /  (iso-PHP)
			name:    "line_separators_reescaped",
			srcHex:  "7b2273223a22785c7532303238795c75323032397a227d",
			wantHex: "7b2273223a22785c7532303238795c75323032397a227d",
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			src, _ := hex.DecodeString(tc.srcHex)
			want, _ := hex.DecodeString(tc.wantHex)

			v, err := DecodeJSONOrdered(src)
			if err != nil {
				t.Fatalf("DecodeJSONOrdered : %v", err)
			}
			got, err := Canonicalize(v)
			if err != nil {
				t.Fatalf("Canonicalize : %v", err)
			}
			if string(got) != string(want) {
				t.Errorf("forme canonique divergente :\n  got  %s\n  want %s", got, want)
			}
		})
	}
}

func TestCanonicalizeRejectsInvalidUtf8(t *testing.T) {
	if _, err := Canonicalize(string([]byte{0x61, 0xff, 0x62})); err == nil {
		t.Error("UTF-8 invalide : erreur attendue (iso JSON_THROW_ON_ERROR)")
	}
}

func TestCanonicalizeRejectsUnsupportedTypes(t *testing.T) {
	// map[string]any interdite : l'ordre du document est perdu, or la
	// sémantique liste/objet PHP en dépend (review 24.5 #1) — une seule voie
	// d'entrée (DecodeJSONOrdered) évite tout hash silencieusement faux.
	if _, err := Canonicalize(map[string]any{"x": json.Number("1")}); err == nil {
		t.Error("map[string]any : erreur attendue (décoder via DecodeJSONOrdered)")
	}
	// float64 interdit : un arbre hors DecodeJSONOrdered (UseNumber) est un
	// bug d'appelant — le contrat est zéro float (§4.1).
	o := NewOrderedMap()
	o.Set("x", float64(1))
	if _, err := Canonicalize(o); err == nil {
		t.Error("float64 : erreur attendue (l'arbre doit venir de DecodeJSONOrdered/UseNumber)")
	}
	o2 := NewOrderedMap()
	o2.Set("x", 12)
	if _, err := Canonicalize(o2); err == nil {
		t.Error("int natif : erreur attendue (l'arbre doit venir de DecodeJSONOrdered/UseNumber)")
	}
}
