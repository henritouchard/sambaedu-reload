package main

import (
	"errors"
	"fmt"
	"strings"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"

	"sambaedu/agent/shared"
)

// Câblage Windows du handler `registry` (Story 27.3) — accès registre EN GO
// NATIF via golang.org/x/sys/windows/registry (déjà dans go.mod, partagé avec
// le handler wallpaper). Zéro dépendance ajoutée, zéro shell-out (`reg add`).
//
// UN SEUL handler générique (D-Q2) : le binaire l'instancie DEUX fois — une
// pour le SERVICE SYSTEM (items HKLM, portée machine) et une pour le COMPAGNON
// (items HKCU, portée session, ruche de l'utilisateur connecté). La logique de
// convergence (Test/Apply, idempotence, isolation par clé) vit dans
// shared.RegistryHandler (testée hôte avec un fake) ; ce fichier ne fait que
// résoudre la ruche, ouvrir/créer les clés et lire/écrire les valeurs typées
// REG_*.
//
// Les droits viennent du contexte d'exécution : HKLM\SOFTWARE\... exige le
// service SYSTEM ; HKCU\Software\... s'écrit dans la ruche de la session
// (compagnon, droits user). Une clé protégée / ruche absente → erreur remontée
// → le moteur rend {status: error, detail} pour le SEUL type `registry`
// (isolation, AC5).

// registryOps : impl shared.RegistryOps de production (Windows registry).
type registryOps struct {
	log *shared.Logger
}

// rootKey : mappe la ruche du payload vers la racine x/sys/windows/registry.
// HKLM → LOCAL_MACHINE, HKCU → CURRENT_USER. Toute autre ruche est refusée
// (le handler générique ne gère que ces deux portées — D-Q2).
func rootKey(hive string) (registry.Key, error) {
	switch strings.ToUpper(strings.TrimSpace(hive)) {
	case "HKLM", "HKEY_LOCAL_MACHINE":
		return registry.LOCAL_MACHINE, nil
	case "HKCU", "HKEY_CURRENT_USER":
		return registry.CURRENT_USER, nil
	default:
		return 0, fmt.Errorf("ruche de registre non supportée : %q (HKLM|HKCU attendu)", hive)
	}
}

// Read lit la valeur réelle d'une clé. present=false si la clé OU la valeur
// n'existe pas (ce n'est PAS une erreur : c'est une dérive à corriger par
// apply). Une valeur existante d'un type NON géré (REG_BINARY, …) est
// present=true avec Kind sentinelle "REG_UNSUPPORTED" — la présence est
// indépendante du type (chemin `ensure:"absent"`). err = ruche invalide /
// accès refusé.
func (o *registryOps) Read(hive, path, name string) (shared.RegistryValue, bool, error) {
	root, err := rootKey(hive)
	if err != nil {
		return shared.RegistryValue{}, false, err
	}

	key, err := registry.OpenKey(root, path, registry.QUERY_VALUE)
	if err != nil {
		if errors.Is(err, registry.ErrNotExist) {
			return shared.RegistryValue{}, false, nil // clé absente → dérive
		}

		return shared.RegistryValue{}, false, fmt.Errorf("ouverture de %s\\%s : %w", hive, path, err)
	}
	defer key.Close()

	_, valType, err := key.GetValue(name, nil)
	if err != nil {
		if errors.Is(err, registry.ErrNotExist) {
			return shared.RegistryValue{}, false, nil // valeur absente → dérive
		}

		return shared.RegistryValue{}, false, fmt.Errorf("lecture de %s\\%s!%s : %w", hive, path, name, err)
	}

	switch valType {
	case registry.DWORD:
		v, _, e := key.GetIntegerValue(name)
		if e != nil {
			return shared.RegistryValue{}, false, e
		}

		return shared.RegistryValue{Kind: "REG_DWORD", Int: int64(v)}, true, nil
	case registry.QWORD:
		v, _, e := key.GetIntegerValue(name)
		if e != nil {
			return shared.RegistryValue{}, false, e
		}

		return shared.RegistryValue{Kind: "REG_QWORD", Int: int64(v)}, true, nil
	case registry.SZ:
		v, _, e := key.GetStringValue(name)
		if e != nil {
			return shared.RegistryValue{}, false, e
		}

		return shared.RegistryValue{Kind: "REG_SZ", Str: v}, true, nil
	case registry.EXPAND_SZ:
		// Valeur BRUTE (non développée) — on compare la chaîne littérale cible
		// (%VAR% inclus), pas sa version développée.
		v, _, e := key.GetStringValue(name)
		if e != nil {
			return shared.RegistryValue{}, false, e
		}

		return shared.RegistryValue{Kind: "REG_EXPAND_SZ", Str: v}, true, nil
	case registry.MULTI_SZ:
		v, _, e := key.GetStringsValue(name)
		if e != nil {
			return shared.RegistryValue{}, false, e
		}

		return shared.RegistryValue{Kind: "REG_MULTI_SZ", Multi: v}, true, nil
	default:
		// Type réel non géré à la lecture (REG_BINARY, REG_NONE, …) : la valeur
		// EXISTE → present=true avec un Kind sentinelle hors contrat (review
		// 35.1 #1). Item d'écriture : Equal() échoue sur le Kind → apply réécrit
		// au type cible (comportement historique conservé). Item `ensure:
		// "absent"` : la valeur présente est une dérive → apply la SUPPRIME
		// (AC3 : « peu importe son type/contenu » — pas de résidu silencieux).
		return shared.RegistryValue{Kind: "REG_UNSUPPORTED"}, true, nil
	}
}

// Write (ré)écrit la valeur cible, CRÉANT la clé au besoin (CreateKey). Idempotent
// du point de vue du résultat. err = ruche invalide / accès refusé.
func (o *registryOps) Write(spec shared.RegistrySpec) error {
	root, err := rootKey(spec.Hive)
	if err != nil {
		return err
	}

	key, _, err := registry.CreateKey(root, spec.Path, registry.SET_VALUE)
	if err != nil {
		return fmt.Errorf("création/ouverture de %s\\%s : %w", spec.Hive, spec.Path, err)
	}
	defer key.Close()

	switch strings.ToUpper(spec.Value.Kind) {
	case "REG_DWORD":
		return key.SetDWordValue(spec.Name, uint32(spec.Value.Int))
	case "REG_QWORD":
		return key.SetQWordValue(spec.Name, uint64(spec.Value.Int))
	case "REG_SZ":
		return key.SetStringValue(spec.Name, spec.Value.Str)
	case "REG_EXPAND_SZ":
		return key.SetExpandStringValue(spec.Name, spec.Value.Str)
	case "REG_MULTI_SZ":
		return key.SetStringsValue(spec.Name, spec.Value.Multi)
	default:
		return fmt.Errorf("type REG_* non supporté à l'écriture : %q", spec.Value.Kind)
	}
}

// Delete supprime la VALEUR NOMMÉE d'une clé (Story 35.1, item `ensure:"absent"`)
// — JAMAIS la clé-conteneur (des valeurs voisines non gérées y vivent ; la
// réconciliation de clé entière est le type `registry_list`, D3/35.2).
// registry.ErrNotExist sur la CLÉ ou la VALEUR ⇒ nil (succès idempotent : la
// cible « valeur absente » est déjà atteinte). Autres erreurs (accès refusé /
// ruche invalide) remontées → {status: error} pour le type (isolation AC5).
func (o *registryOps) Delete(hive, path, name string) error {
	root, err := rootKey(hive)
	if err != nil {
		return err
	}

	key, err := registry.OpenKey(root, path, registry.SET_VALUE)
	if err != nil {
		if errors.Is(err, registry.ErrNotExist) {
			return nil // clé-conteneur absente → la valeur l'est aussi (idempotent)
		}

		return fmt.Errorf("ouverture de %s\\%s : %w", hive, path, err)
	}
	defer key.Close()

	if err := key.DeleteValue(name); err != nil {
		if errors.Is(err, registry.ErrNotExist) {
			return nil // valeur déjà absente (idempotent)
		}

		return fmt.Errorf("suppression de %s\\%s!%s : %w", hive, path, name, err)
	}

	return nil
}

// ValueNames énumère les NOMS des valeurs d'une clé (Story 35.2 — la
// réconciliation de clé-conteneur `registry_list` doit voir les entrées
// surnuméraires). Clé ABSENTE ⇒ (nil, nil) : pas une erreur (idempotence, iso
// Delete — la cible « aucune entrée » est déjà atteinte). err = accès refusé /
// ruche invalide. NB : la valeur PAR DÉFAUT de la clé, si définie, est
// énumérée sous le nom "" (jamais numérique → jamais touchée par la
// réconciliation de liste).
func (o *registryOps) ValueNames(hive, path string) ([]string, error) {
	root, err := rootKey(hive)
	if err != nil {
		return nil, err
	}

	key, err := registry.OpenKey(root, path, registry.QUERY_VALUE)
	if err != nil {
		if errors.Is(err, registry.ErrNotExist) {
			return nil, nil // clé-conteneur absente → aucune entrée (idempotent)
		}

		return nil, fmt.Errorf("ouverture de %s\\%s : %w", hive, path, err)
	}
	defer key.Close()

	names, err := key.ReadValueNames(-1)
	if err != nil {
		return nil, fmt.Errorf("énumération de %s\\%s : %w", hive, path, err)
	}

	return names, nil
}

// SHChangeNotify (shell32) — signale au shell un changement global afin que
// l'Explorer DÉJÀ ouvert relise ses réglages de vue (Hidden, HideFileExt) sans
// attendre un relogon. Sans cet appel, écrire HKCU\…\Explorer\Advanced ne change
// RIEN à l'écran tant que la session reste ouverte (l'Explorer met ses réglages
// de vue en cache). FFI Win32 sans cgo, même style que le handler wallpaper
// (NewLazySystemDLL).
const (
	shcneAssocChanged = 0x08000000 // SHCNE_ASSOCCHANGED : force le shell à relire ses réglages
	shcnfIDList       = 0x0000     // SHCNF_IDLIST
)

var (
	modShell32         = windows.NewLazySystemDLL("shell32.dll")
	procSHChangeNotify = modShell32.NewProc("SHChangeNotify")
)

// NotifyShellChanged émet SHChangeNotify(SHCNE_ASSOCCHANGED, SHCNF_IDLIST, ...) —
// implémente shared.registryNotifier (optionnel). Appelé par le COMPAGNON (dans
// la session de l'utilisateur) APRÈS une écriture HKCU effective. Best-effort :
// SHChangeNotify ne retourne rien d'exploitable et un shell non rafraîchi n'est
// PAS une erreur de convergence (la clé est bien écrite ; au pire l'effet
// apparaît au prochain relogon).
func (o *registryOps) NotifyShellChanged() {
	_, _, _ = procSHChangeNotify.Call(uintptr(shcneAssocChanged), uintptr(shcnfIDList), 0, 0)
	if o.log != nil {
		o.log.Infof("Rafraîchissement shell émis (SHChangeNotify) — l'Explorer relit Hidden/HideFileExt")
	}
}
