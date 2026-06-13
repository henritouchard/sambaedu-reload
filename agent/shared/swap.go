package shared

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"os"
)

// Cœur ANTI-BRIQUE du swap d'auto-update (Story 25.2, AC3). Extrait de
// windows/ vers shared/ pour être RÉELLEMENT testé sur l'hôte Linux (#6/M6) :
// les renames POSIX se comportent comme Windows pour ce besoin (un rename de
// l'image en cours est autorisé des deux côtés ; le handle ouvert suit
// l'inode). Seules les SPÉCIFICITÉS OS restent côté windows/ : résolution des
// chemins réels (Program Files / ProgramData), ACL du staging, et la sortie
// non-gracieuse `os.Exit` (injectée ici comme `triggerRestart`).
//
// La séquence garantit qu'à AUCUN instant la cible n'est absente ou corrompue :
// chaque étape destructive a son inverse, le rename final est atomique (même
// volume). Pas d'état « ni ancien ni nouveau » à aucune étape.

// renameForSwap : indirection sur os.Rename pour les renames du swap (étapes
// (b) et (c)). En production = os.Rename. Les tests le surchargent pour forcer
// un échec déterministe du rename final (c) et vérifier le ROLLBACK réel
// (impossible à provoquer de façon portable autrement — l'échec de (c) est rare
// en prod : même volume, après un (b) réussi). La copie atomique (pré) garde
// os.Rename direct (son échec est testé via un staged absent / un dst occupé).
var renameForSwap = os.Rename

// PerformSwap permute atomiquement le binaire `target` (l'image en place, ex.
// agent.exe verrouillé) par le binaire `staged` (déjà vérifié hash+signature à
// sa position de staging), puis déclenche le redémarrage via `triggerRestart`.
//
// `expectedHash` est le SHA-256 hex attendu du binaire (== hash manifest) :
// le `.new` réellement mis en place est RE-HASHÉ avant le rename final (M2) —
// le binaire qui sera exécuté a passé la porte d'intégrité à SA position
// finale, pas seulement au staging (cohérent « deux portes »).
//
// Séquence (target = T, T.old, T.new ; staged = S) :
//
//	(pré) copie ATOMIQUE S -> T.new (tmp+rename, même volume que T — M1) ;
//	(re)  re-hash T.new == expectedHash (M2) ; divergent -> abort + cleanup ;
//	(a)   suppression d'un résidu T.old (idempotent) ;
//	(b)   rename T -> T.old (autorisé même si T verrouillé) ;
//	(c)   rename T.new -> T (atomique, même volume) ;
//	      si (c) KO -> rollback : rename T.old -> T, abort (ancien intact) ;
//	(fin) triggerRestart() — APRÈS un swap réussi UNIQUEMENT.
//
// Invariant : entre (b) et (c), T existe sous T.old ; (c) est atomique. Si
// quoi que ce soit échoue avant (c), `target` reste l'ANCIEN binaire intact et
// `triggerRestart` n'est JAMAIS appelé (anti-brique testable, AC3).
//
// `target` et `staged` peuvent vivre sur des volumes DIFFÉRENTS (Program Files
// vs ProgramData) : la copie (pré) crée T.new sur le MÊME volume que `target`
// (tmp à côté de la cible), donc le rename final (c) est intra-volume.
func PerformSwap(target, staged, expectedHash string, triggerRestart func()) error {
	oldPath := target + ".old"
	newPath := target + ".new"

	// (pré) Copie ATOMIQUE du stagé à côté de la cible (même volume), via un
	// tmp+rename sur ce volume : un crash pendant l'écriture ne laisse jamais un
	// T.new tronqué visible (M1). On copie octet pour octet ce qui a déjà passé
	// hash + signature au staging.
	if err := atomicCopyFile(staged, newPath); err != nil {
		return fmt.Errorf("copie atomique du binaire neuf à côté de la cible : %w", err)
	}

	// (re) Re-vérification d'intégrité du binaire RÉELLEMENT mis en place (M2) :
	// re-hash de T.new à sa position finale (Program Files), == hash manifest.
	// Couvre une corruption pendant la copie cross-volume (et un T.new substitué
	// dans la fenêtre TOCTOU, frontière de confiance #12). Divergent -> on
	// n'exécute JAMAIS ce .new : cleanup + abort, l'ancien binaire reste en place.
	if err := verifyFileHash(newPath, expectedHash); err != nil {
		_ = os.Remove(newPath)

		return fmt.Errorf("re-vérification du binaire en place (.new) avant swap : %w", err)
	}

	// (a) nettoyage d'un résidu .old (idempotent).
	_ = os.Remove(oldPath)

	// (b) target -> target.old (rename autorisé même sur image verrouillée).
	if err := renameForSwap(target, oldPath); err != nil {
		_ = os.Remove(newPath)

		return fmt.Errorf("rename de l'ancien binaire en .old : %w", err)
	}

	// (c) target.new -> target (rename atomique, même volume).
	if err := renameForSwap(newPath, target); err != nil {
		// Rollback : restaurer l'ancien binaire en place. L'ancien reste intact
		// et fonctionnel (anti-brique, AC3).
		if rbErr := renameForSwap(oldPath, target); rbErr != nil {
			return fmt.Errorf("dépose du neuf KO (%w) ET rollback KO (%v) — état à vérifier autour de %s", err, rbErr, target)
		}
		_ = os.Remove(newPath)

		return fmt.Errorf("dépose du neuf KO, rollback effectué (ancien binaire en place intact) : %w", err)
	}

	// Swap RÉUSSI : le nouveau binaire est en place. On déclenche le redémarrage
	// (sur Windows : sortie non-gracieuse os.Exit → recovery SCM). triggerRestart
	// n'est appelé QUE sur ce chemin de succès — jamais sur une erreur ci-dessus.
	if triggerRestart != nil {
		triggerRestart()
	}

	return nil
}

// atomicCopyFile copie src vers dst de façon atomique : écriture dans un
// tmp suffixé PID SUR LE MÊME RÉPERTOIRE (donc le même volume) que dst, puis
// rename. dst n'est jamais visible tronqué (iso WriteFileAtomic). Le tmp DOIT
// être à côté de dst (pas dans os.TempDir) pour que le rename soit intra-volume
// quand src et dst sont sur des volumes différents.
func atomicCopyFile(src, dst string) error {
	data, err := os.ReadFile(src)
	if err != nil {
		return fmt.Errorf("lecture de %s : %w", src, err)
	}
	tmp := fmt.Sprintf("%s.%d.tmp", dst, os.Getpid())
	if err := os.WriteFile(tmp, data, 0o755); err != nil {
		return fmt.Errorf("écriture de %s : %w", tmp, err)
	}
	if err := os.Rename(tmp, dst); err != nil {
		_ = os.Remove(tmp)

		return fmt.Errorf("rename %s → %s : %w", tmp, dst, err)
	}

	return nil
}

// verifyFileHash re-calcule le SHA-256 de `path` et le compare (hex) à
// `expected`. Même primitive que la porte 1 (assets.go / update.go). Erreur si
// lecture KO ou hash divergent.
func verifyFileHash(path, expected string) error {
	body, err := os.ReadFile(path)
	if err != nil {
		return fmt.Errorf("lecture de %s : %w", path, err)
	}
	sum := sha256.Sum256(body)
	actual := hex.EncodeToString(sum[:])
	if actual != expected {
		return fmt.Errorf("SHA-256 (%s) != attendu (%s)", actual, expected)
	}

	return nil
}
