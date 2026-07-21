package shared

import "testing"

// Substitution en contexte SYSTEM : <user> vient de la SESSION (login WTS), pas
// de l'environnement du service. On vérifie que le cœur pur substitue bien les
// deux tokens avec des valeurs EXPLICITES (jamais d'accès env).
func TestSubstituteServerTokensSystemContext(t *testing.T) {
	got := SubstituteServerTokens(`\\<se4fs>\users\<user>\.mozilla\firefox\managed.default`, "alice", "FILESRV")
	want := `\\FILESRV\users\alice\.mozilla\firefox\managed.default`
	if got != want {
		t.Errorf("substitution SYSTEM : got %q, want %q", got, want)
	}
	// Aucun token laissé.
	if SubstituteServerTokens("<user>-<se4fs>", "bob", "srv") != "bob-srv" {
		t.Errorf("les deux tokens doivent être substitués")
	}
}

// Extraction des specs app_profile depuis la portée SESSION d'un state parsé (le
// cache per-SID que SYSTEM a écrit au fetch). Les autres types sont ignorés, un
// item invalide est sauté sans faire échouer la passe.
func TestAppProfileSpecsFromSession(t *testing.T) {
	state := &State{
		Session: []any{
			// Autre type : ignoré.
			map[string]any{"type": "drives", "hash": "h0", "payload": map[string]any{}},
			// app_profile valide.
			map[string]any{
				"type": "app_profile", "hash": "h1", "semantics": "aggregate",
				"payload": map[string]any{
					"app":          "firefox",
					"link":         `AppData\Roaming\Mozilla\Firefox\managed.default`,
					"server":       `\\<se4fs>\users\<user>\.mozilla\firefox\managed.default`,
					"profile_name": "managed.default",
				},
			},
			// app_profile invalide (champs minimaux manquants) : sauté.
			map[string]any{
				"type": "app_profile", "hash": "h2",
				"payload": map[string]any{"app": "thunderbird"},
			},
		},
	}

	specs := AppProfileSpecsFromSession(state, nil)
	if len(specs) != 1 {
		t.Fatalf("1 spec valide attendue, got %d", len(specs))
	}
	if specs[0].App != "firefox" {
		t.Errorf("app inattendue : %q", specs[0].App)
	}
}

// Calcul lien/cible depuis une spec (+ profil de session, login, se4fs).
func TestAppProfileLinkAndTargetComputation(t *testing.T) {
	spec := AppProfileSpec{
		App:    "firefox",
		Link:   `AppData\Roaming\Mozilla\Firefox\managed.default`,
		Server: `\\<se4fs>\users\<user>\.mozilla\firefox\managed.default`,
	}
	target := AppProfileServerTarget(spec, "alice", "FILESRV")
	if target != `\\FILESRV\users\alice\.mozilla\firefox\managed.default` {
		t.Errorf("cible inattendue : %q", target)
	}
	link := AppProfileLinkPath(spec, `C:\Users\alice`)
	if link != `C:\Users\alice\AppData\Roaming\Mozilla\Firefox\managed.default` {
		t.Errorf("lien inattendu : %q", link)
	}
}

// Validation de borne (défense en profondeur).
func TestValidateAppProfileBounds(t *testing.T) {
	profile := `C:\Users\alice`
	unc := `\\FILESRV\users\alice\.mozilla\firefox\managed.default`

	// Cas nominal : lien sous le profil + cible UNC.
	ok := `C:\Users\alice\AppData\Roaming\Mozilla\Firefox\managed.default`
	if err := ValidateAppProfileBounds(ok, profile, unc); err != nil {
		t.Errorf("cas nominal refusé : %v", err)
	}

	// Cible NON UNC : refusée.
	if err := ValidateAppProfileBounds(ok, profile, `C:\Windows\System32`); err == nil {
		t.Errorf("cible non-UNC : refus attendu")
	}

	// Lien qui s'échappe du profil via `..` : refusé.
	escape := `C:\Users\alice\..\..\Windows\System32\evil`
	if err := ValidateAppProfileBounds(escape, profile, unc); err == nil {
		t.Errorf("lien hors profil (échappement `..`) : refus attendu")
	}

	// Lien dans un AUTRE profil utilisateur : refusé.
	other := `C:\Users\bob\AppData\Roaming\Mozilla\Firefox\managed.default`
	if err := ValidateAppProfileBounds(other, profile, unc); err == nil {
		t.Errorf("lien dans un autre profil : refus attendu")
	}

	// Lien ÉGAL au profil (pas strictement dessous) : refusé.
	if err := ValidateAppProfileBounds(profile, profile, unc); err == nil {
		t.Errorf("lien égal au profil : refus attendu (pas strictement dessous)")
	}

	// Pseudo-UNC LOCAUX (contre-review P1) : `\\` en tête ne suffit pas.
	for _, target := range []string{
		`\\?\C:\Windows\System32`, // extended-length = chemin local
		`\\.\PhysicalDrive0`,      // device namespace
		`\\FILESRV`,               // hôte sans partage
		`\\FILESRV\`,              // partage vide
		`\\\share\x`,              // hôte vide
	} {
		if err := ValidateAppProfileBounds(ok, profile, target); err == nil {
			t.Errorf("cible %q : refus attendu (pseudo-UNC / UNC incomplet)", target)
		}
	}
}

// Décision d'action de pose (PURE) — idempotence, réparation, mise de côté C1.
func TestDecideLinkAction(t *testing.T) {
	want := `\\FILESRV\users\alice\.mozilla\firefox\managed.default`

	// Absent ⇒ créer.
	if a := DecideLinkAction(false, false, "", want); a != LinkCreate {
		t.Errorf("absent : LinkCreate attendu, got %v", a)
	}
	// Lien correct ⇒ no-op (idempotent) — insensible à la casse / backslash final.
	if a := DecideLinkAction(true, true, want+`\`, want); a != LinkNoop {
		t.Errorf("lien correct : LinkNoop attendu, got %v", a)
	}
	// Lien divergent ⇒ remplacer LE LIEN.
	if a := DecideLinkAction(true, true, `\\FILESRV\users\alice\autre`, want); a != LinkReplaceLink {
		t.Errorf("lien divergent : LinkReplaceLink attendu, got %v", a)
	}
	// VRAI dossier (non-lien) ⇒ mise de côté C1 (jamais destruction).
	if a := DecideLinkAction(true, false, "", want); a != LinkMoveAside {
		t.Errorf("vrai dossier : LinkMoveAside attendu, got %v", a)
	}
}
