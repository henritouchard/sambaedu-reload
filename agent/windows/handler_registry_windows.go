package main

import (
	"errors"
	"fmt"
	"strings"

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
// apply). err = ruche invalide / accès refusé.
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
		// Type réel inattendu (BINARY, etc.) : on considère « non conforme »
		// (present=false) → apply réécrira avec le type cible.
		return shared.RegistryValue{}, false, nil
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
