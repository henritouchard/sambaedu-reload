package main

import (
	"errors"
	"fmt"
	"sort"
	"strings"

	"golang.org/x/sys/windows/registry"

	"sambaedu/agent/shared"
)

// Câblage Windows du handler `registry` (Story 27.3) — accès registre EN GO
// NATIF via golang.org/x/sys/windows/registry (déjà dans go.mod, partagé avec
// le handler wallpaper). Zéro dépendance ajoutée, zéro shell-out (`reg add`).
//
// UN SEUL handler générique (D-Q2) : le binaire l'instancie DEUX fois — une
// pour le SERVICE SYSTEM (items HKLM + HKU, portée machine — Story 35.3) et une
// pour le COMPAGNON (items HKCU, portée session, ruche de l'utilisateur
// connecté). La logique de convergence (Test/Apply, idempotence, isolation par
// clé, fan-out HKU vers .DEFAULT + ruches chargées) vit dans
// shared.RegistryHandler (testée hôte avec un fake) ; ce fichier ne fait que
// résoudre la ruche, ouvrir/créer les clés, lire/écrire les valeurs typées
// REG_* et énumérer les cibles HKU (UserHives).
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
// HKLM → LOCAL_MACHINE, HKCU → CURRENT_USER, HKU → USERS (Story 35.3 : la 3e
// ruche est MACHINE-scope — le service SYSTEM fan-out les items HKU vers
// `.DEFAULT` + les ruches chargées, paths préfixés par le handler shared ;
// D-Q2 reste : un handler générique, la séparation des portées est serveur).
// Toute autre ruche est refusée.
func rootKey(hive string) (registry.Key, error) {
	switch strings.ToUpper(strings.TrimSpace(hive)) {
	case "HKLM", "HKEY_LOCAL_MACHINE":
		return registry.LOCAL_MACHINE, nil
	case "HKCU", "HKEY_CURRENT_USER":
		return registry.CURRENT_USER, nil
	case "HKU", "HKEY_USERS":
		return registry.USERS, nil
	default:
		return 0, fmt.Errorf("ruche de registre non supportée : %q (HKLM|HKCU|HKU attendu)", hive)
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

	// Race logoff (Story 35.3, review #1) : une cible HKU dont la ruche a été
	// DÉMONTÉE entre l'énumération (UserHives) et l'écriture ne doit PAS être
	// matérialisée — CreateKey ne renverrait pas d'erreur, il créerait une clé
	// ORPHELINE persistante directement sous HKEY_USERS (résidu + collision au
	// remontage du profil). Sonde de la racine de fan-out (`.DEFAULT`/`<SID>`) :
	// absente ⇒ no-op nil (la ruche a disparu, la cible aussi — le prochain
	// cycle ne l'énumérera plus, l'item redevient conforme).
	if root == registry.USERS {
		base, _, _ := strings.Cut(spec.Path, `\`)
		probe, probeErr := registry.OpenKey(registry.USERS, base, registry.QUERY_VALUE)
		if probeErr != nil {
			if errors.Is(probeErr, registry.ErrNotExist) {
				return nil // ruche démontée pendant le cycle : skip (jamais d'orpheline)
			}

			return fmt.Errorf("sonde de la ruche %s\\%s : %w", spec.Hive, base, probeErr)
		}
		probe.Close()
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

// UserHives énumère les CIBLES du fan-out HKU (Story 35.3) : sous-clés de
// HKEY_USERS filtrées STRICTEMENT — ".DEFAULT" (profil de l'écran de logon) +
// ruches utilisateur chargées `S-1-5-21-*` SANS le suffixe `_Classes`
// (jumelles HKCR per-user : y écrire `Control Panel\…` créerait des débris).
// Les SID de service S-1-5-18/19/20 ne matchent pas le préfixe S-1-5-21-
// (exclusion naturelle) ; les comptes AAD (S-1-12-1-*) sont hors périmètre
// (parc AD). Tri pour des logs déterministes. Énuméré à CHAQUE appel — jamais
// de cache (une session ouverte après coup est couverte au cycle suivant).
// err = accès refusé / énumération impossible (l'item HKU devient inapplicable
// → {status: error} pour le type).
func (o *registryOps) UserHives() ([]string, error) {
	key, err := registry.OpenKey(registry.USERS, "", registry.ENUMERATE_SUB_KEYS)
	if err != nil {
		return nil, fmt.Errorf("ouverture de HKEY_USERS : %w", err)
	}
	defer key.Close()

	names, err := key.ReadSubKeyNames(-1)
	if err != nil {
		return nil, fmt.Errorf("énumération de HKEY_USERS : %w", err)
	}

	targets := []string{}
	for _, name := range names {
		if isHkuFanOutTarget(name) {
			targets = append(targets, name)
		}
	}
	sort.Strings(targets)

	return targets, nil
}

// isHkuFanOutTarget : filtre STRICT des sous-clés de HKEY_USERS (piège n° 7,
// insensible à la casse) — garder ".DEFAULT" + `S-1-5-21-*` hors `_Classes`.
func isHkuFanOutTarget(name string) bool {
	upper := strings.ToUpper(strings.TrimSpace(name))
	if upper == ".DEFAULT" {
		return true
	}
	if !strings.HasPrefix(upper, "S-1-5-21-") {
		return false // SID de service (S-1-5-18/19/20), AAD (S-1-12-1-*), divers
	}

	return !strings.HasSuffix(upper, "_CLASSES")
}

// NB (Story 43.1) : l'ancien hook `NotifyShellChanged` (SHChangeNotify inline,
// ex-shared.registryNotifier) a MIGRÉ vers l'échelle de rafraîchissement —
// refresh_windows.go (refreshOps.ShellNotify), pilotée par le compagnon en fin
// de passe. UNE seule voie d'émission (piège n° 5) : registryOps ne porte plus
// aucun geste shell.
