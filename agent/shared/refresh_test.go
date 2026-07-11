package shared

import "testing"

// Tests de l'échelle de rafraîchissement (Story 43.1, AC1) — parsing
// INDULGENT du hint `refresh` et ordre de l'échelle.

func TestParseRefreshLevelKnownTokens(t *testing.T) {
	cases := []struct {
		raw  string
		want RefreshLevel
	}{
		{"shell_notify", RefreshShellNotify},
		{"policy_broadcast", RefreshPolicyBroadcast},
		{"explorer_restart", RefreshExplorerRestart},
		// Tolérance de forme (espace/casse) — le vocabulaire canonique reste
		// serveur (AuthoringGuard 43.2), l'agent ne fait que reconnaître.
		{"  Shell_Notify  ", RefreshShellNotify},
		{"EXPLORER_RESTART", RefreshExplorerRestart},
	}
	for _, c := range cases {
		if got := ParseRefreshLevel(c.raw); got != c.want {
			t.Errorf("ParseRefreshLevel(%q) = %s, attendu %s", c.raw, got, c.want)
		}
	}
}

func TestParseRefreshLevelLenientUnknownOrEmpty(t *testing.T) {
	// AC1 : absent/vide/inconnu ⇒ RefreshNone — JAMAIS une erreur d'enveloppe
	// (le parsing ne peut pas échouer : pas de second retour d'erreur).
	for _, raw := range []string{"", "   ", "none", "reboot", "shell-notify", "restart_explorer"} {
		if got := ParseRefreshLevel(raw); got != RefreshNone {
			t.Errorf("ParseRefreshLevel(%q) = %s, attendu none (indulgent)", raw, got)
		}
	}
}

func TestRefreshLevelOrderingAndMax(t *testing.T) {
	// L'échelle est ORDONNÉE : none < shell_notify < policy_broadcast <
	// explorer_restart — maxRefreshLevel s'appuie sur cet ordre.
	if !(RefreshNone < RefreshShellNotify && RefreshShellNotify < RefreshPolicyBroadcast &&
		RefreshPolicyBroadcast < RefreshExplorerRestart) {
		t.Fatal("l'ordre de l'échelle est cassé")
	}
	if got := maxRefreshLevel(RefreshShellNotify, RefreshExplorerRestart); got != RefreshExplorerRestart {
		t.Errorf("max(shell_notify, explorer_restart) = %s", got)
	}
	if got := maxRefreshLevel(RefreshPolicyBroadcast, RefreshNone); got != RefreshPolicyBroadcast {
		t.Errorf("max(policy_broadcast, none) = %s", got)
	}
	// Un hint ne peut qu'ESCALADER le plancher (D2) : max(plancher, hint plus
	// faible) reste le plancher.
	if got := maxRefreshLevel(RefreshShellNotify, RefreshNone); got != RefreshShellNotify {
		t.Errorf("le plancher ne s'affaiblit jamais : %s", got)
	}
}

func TestRefreshLevelString(t *testing.T) {
	cases := map[RefreshLevel]string{
		RefreshNone:            "none",
		RefreshShellNotify:     "shell_notify",
		RefreshPolicyBroadcast: "policy_broadcast",
		RefreshExplorerRestart: "explorer_restart",
		RefreshLevel(99):       "none", // hors échelle : libellé sûr
	}
	for level, want := range cases {
		if got := level.String(); got != want {
			t.Errorf("String(%d) = %q, attendu %q", int(level), got, want)
		}
	}
}

func TestLogUnknownRefreshHintNilSafe(t *testing.T) {
	// Ne panique jamais : logger nil, payload non-map, hint absent/connu.
	logUnknownRefreshHint(nil, nil, "x")
	logUnknownRefreshHint(nil, "pas une map", "x")
	logUnknownRefreshHint(nil, map[string]any{"refresh": "warp"}, "x")
	logUnknownRefreshHint(nil, map[string]any{"refresh": "shell_notify"}, "x")
	logUnknownRefreshHint(nil, map[string]any{}, "x")
}
