<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

/**
 * Story 36.1 (AC3) — garde-fou d'AUTHORING des projections `windows/fs_acl` :
 * refuse à la SOURCE les ACE que l'agent refuserait (défense en profondeur, le
 * serveur peut avoir tort mais ne doit JAMAIS produire un catalogue dangereux).
 *
 * **Où.** L'authoring est catalogue-first (migrations/seeds — aucune UI de
 * création n'existe encore) : ce service PUR (projections en entrée, violations
 * NOMMÉES en sortie, zéro requête/écriture) est exécuté par un invariant de
 * test sur les données réellement seedées ({@see \Tests\Feature\Migrations\CapabilityFsAclSeedTest}).
 * Il est conçu pour être RÉUTILISÉ TEL QUEL par le formulaire « règles d'accès »
 * de la Story 36.4 (mêmes messages FR, mêmes constantes publiques).
 *
 * **Ce qu'il refuse** (Q1/Q2 + principals système, décisions Henri) :
 *   1. un `deny` sur un principal SYSTÈME ({@see SYSTEM_TRUSTEES}, avec ou sans
 *      préfixe domaine, insensible à la casse) — casser SYSTEM/Administrators/
 *      TrustedInstaller/… briserait le poste. Les jetons `@…` ne sont JAMAIS
 *      système (ils résolvent des groupes MÉTIER) ;
 *   2. un `deny` à héritage DESCENDANT (`folder_subfolders_files` /
 *      `subfolders_files_only`) sur une racine protégée ({@see PROTECTED_ROOTS},
 *      liste Q2 TELLE QUELLE) — le `deny list_folder folder_only` (masquer sans
 *      casser) y reste AUTORISÉ ;
 *   3. enums hors domaine (`ace_type`/`rights`/`applies_to`/`ensure`), `path`
 *      non absolu, `trustee` vide, jeton d'audience inconnu (hors
 *      {@see AudienceTokens::TOKENS}) ;
 *   4. une capacité dont AU MOINS une entrée `deny` existe SANS `warning` non
 *      vide (l'implication doit être confirmée).
 *
 * **Pas de ciblage par utilisateur (piège #10).** Le mécanisme `fs_acl` est de
 * portée MACHINE : « quel utilisateur est bridé » = le `trustee` DANS le
 * payload, « quels postes » = les assignations parc/salle/poste/broadcast. Un
 * override UserGroup/User est structurellement SANS EFFET (le service SYSTEM
 * fetch sans `?user`) — pas un garde-fou runtime, un fait de compilation.
 *
 * **Différence avec {@see CapabilitySpecCollisionGuard}** (registre) : service
 * SÉPARÉ (36.3 écrit dans le guard registre en parallèle) ; il valide la
 * SÉCURITÉ des ACE, pas l'arbitrabilité de clés-conteneurs.
 */
final class FsAclAuthoringGuard
{
    /** Types d'ACE admis (D3, contrat §7.7). */
    public const ACE_TYPES = ['allow', 'deny'];

    /** Droits admis (mots métier — la traduction en masques vit dans le handler). */
    public const RIGHTS = ['list_folder', 'read', 'write', 'modify'];

    /** Portée d'héritage admise (D3). */
    public const APPLIES_TO = ['folder_only', 'folder_subfolders_files', 'subfolders_files_only'];

    /** Verbe de convergence (D3, TOUJOURS explicite côté payload — piège #13). */
    public const ENSURE = ['present', 'absent'];

    /**
     * Portées d'héritage DESCENDANT (deny interdit sur racine protégée, Q2).
     * `folder_only` en est ABSENT : masquer sans casser reste autorisé partout.
     *
     * @var list<string>
     */
    public const DESCENDANT_APPLIES_TO = ['folder_subfolders_files', 'subfolders_files_only'];

    /**
     * Racines protégées (Q2 — liste Henri TELLE QUELLE). Un `deny` à héritage
     * descendant y est REFUSÉ. Forme normalisée (minuscules, sans backslash
     * final) : `C:\` → `c:`, `C:\Windows` → `c:\windows`, … (cf.
     * {@see normalizePath}).
     *
     * @var list<string>
     */
    public const PROTECTED_ROOTS = [
        'C:\\',
        'C:\\Windows',
        'C:\\Program Files',
        'C:\\Program Files (x86)',
        'C:\\ProgramData',
    ];

    /**
     * Principals SYSTÈME sur lesquels un `deny` est REFUSÉ (forme normalisée :
     * minuscules, préfixe domaine `NT AUTHORITY\`/`BUILTIN\` retiré). Couvre
     * FR et EN (le poste peut être localisé).
     *
     * @var list<string>
     */
    public const SYSTEM_TRUSTEES = [
        'system',
        'local system',
        'administrators',
        'administrateurs',
        'trustedinstaller',
        'localservice',
        'local service',
        'networkservice',
        'network service',
        'everyone',
        'tout le monde',
        'authenticated users',
        'utilisateurs authentifiés',
        'utilisateurs authentifies',
    ];

    /**
     * Valide un ensemble de projections d'authoring `fs_acl`.
     *
     * @param  list<array{capability:string, warning:?string, spec:mixed}>  $projections
     *         une entrée par projection windows/fs_acl : `capability` = key
     *         lisible (messages), `warning` = message d'implications de la
     *         capacité (pour la règle « deny ⇒ warning non vide »), `spec` =
     *         `{"aces": […]}` décodé.
     * @return list<string> violations lisibles (vide = authoring valide)
     */
    public function violations(array $projections): array
    {
        $violations = [];

        foreach ($projections as $projection) {
            $capability = (string) ($projection['capability'] ?? '?');
            $warning = $projection['warning'] ?? null;
            $hasDeny = false;

            foreach ($this->aces($projection['spec'] ?? null) as $ace) {
                $aceType = strtolower(trim((string) ($ace['ace_type'] ?? '')));
                $rights = strtolower(trim((string) ($ace['rights'] ?? '')));
                $appliesTo = strtolower(trim((string) ($ace['applies_to'] ?? '')));
                $path = (string) ($ace['path'] ?? '');

                // Enums bornés (D3).
                if (! in_array($aceType, self::ACE_TYPES, true)) {
                    $violations[] = sprintf("fs_acl [%s] chemin '%s' : ace_type '%s' hors domaine (allow|deny).", $capability, $path, $aceType);
                }
                if (! in_array($rights, self::RIGHTS, true)) {
                    $violations[] = sprintf("fs_acl [%s] chemin '%s' : rights '%s' hors domaine (list_folder|read|write|modify).", $capability, $path, $rights);
                }
                if (! in_array($appliesTo, self::APPLIES_TO, true)) {
                    $violations[] = sprintf("fs_acl [%s] chemin '%s' : applies_to '%s' hors domaine (folder_only|folder_subfolders_files|subfolders_files_only).", $capability, $path, $appliesTo);
                }
                foreach ($this->ensureValues($ace['ensure'] ?? null) as $ensure) {
                    if (! in_array(strtolower(trim((string) $ensure)), self::ENSURE, true)) {
                        $violations[] = sprintf("fs_acl [%s] chemin '%s' : ensure '%s' hors domaine (present|absent).", $capability, $path, (string) $ensure);
                    }
                }

                // Path absolu Windows (D3 : `<lettre>:\…`).
                if (! $this->isAbsoluteWindowsPath($path)) {
                    $violations[] = sprintf("fs_acl [%s] : chemin '%s' non absolu (attendu : chemin Windows absolu <lettre>:\\…).", $capability, $path);
                }

                if ($aceType === 'deny') {
                    $hasDeny = true;
                }

                // Trustees possibles (littéral OU chaque valeur d'une map).
                foreach ($this->trusteeValues($ace['trustee'] ?? null) as $trustee) {
                    $trustee = (string) $trustee;
                    if (trim($trustee) === '') {
                        $violations[] = sprintf("fs_acl [%s] chemin '%s' : trustee vide.", $capability, $path);

                        continue;
                    }

                    if (AudienceTokens::isToken($trustee)) {
                        // Jeton d'audience : doit être dans l'enum fermé (Q1).
                        if (! array_key_exists(strtolower($trustee), AudienceTokens::TOKENS)) {
                            $violations[] = sprintf(
                                "fs_acl [%s] chemin '%s' : jeton d'audience '%s' inconnu (admis : %s).",
                                $capability,
                                $path,
                                $trustee,
                                implode(', ', array_keys(AudienceTokens::TOKENS)),
                            );
                        }

                        // Un jeton résout un groupe MÉTIER — jamais système :
                        // pas de contrôle SYSTEM_TRUSTEES à faire ici.
                        continue;
                    }

                    // Trustee littéral : deny sur principal système REFUSÉ.
                    if ($aceType === 'deny' && $this->isSystemTrustee($trustee)) {
                        $violations[] = sprintf(
                            "fs_acl [%s] chemin '%s' : deny interdit sur le principal système '%s' (casser %s briserait le poste).",
                            $capability,
                            $path,
                            $trustee,
                            $trustee,
                        );
                    }
                }

                // Deny à héritage DESCENDANT sur racine protégée REFUSÉ (Q2) —
                // le deny list_folder folder_only y reste autorisé.
                if ($aceType === 'deny'
                    && in_array($appliesTo, self::DESCENDANT_APPLIES_TO, true)
                    && $this->isProtectedRoot($path)) {
                    $violations[] = sprintf(
                        "fs_acl [%s] : deny à héritage descendant (applies_to '%s') interdit sur la racine protégée '%s' "
                        .'(Q2) — seul « deny list_folder folder_only » (masquer sans casser) y est autorisé.',
                        $capability,
                        $appliesTo,
                        $path,
                    );
                }
            }

            // Deny ⇒ warning non vide (AC3).
            if ($hasDeny && trim((string) ($warning ?? '')) === '') {
                $violations[] = sprintf(
                    "fs_acl [%s] : au moins une entrée `deny` sans `warning` non vide — l'implication (accès refusé) doit être confirmée.",
                    $capability,
                );
            }
        }

        return $violations;
    }

    /**
     * Entrées `aces[]` d'une `spec` (défensif : spec inattendue = liste vide).
     *
     * @return list<array<string,mixed>>
     */
    private function aces(mixed $spec): array
    {
        if (! is_array($spec) || ! isset($spec['aces']) || ! is_array($spec['aces'])) {
            return [];
        }

        return array_values(array_filter($spec['aces'], 'is_array'));
    }

    /**
     * Valeurs possibles d'un champ `trustee`/`ensure` de `spec` : littéral (1)
     * OU chaque valeur d'une map valeur-capacité (D8). Une map dont une valeur
     * est elle-même une structure est ignorée (le provider ne l'émettra pas).
     *
     * @return list<mixed>
     */
    private function mapOrLiteralValues(mixed $raw): array
    {
        if (is_array($raw)) {
            if (array_is_list($raw)) {
                return []; // une liste n'est pas une forme valide (ni littéral ni map).
            }

            return array_values(array_filter($raw, static fn ($v): bool => is_scalar($v)));
        }

        return [$raw];
    }

    /**
     * @return list<mixed>
     */
    private function trusteeValues(mixed $raw): array
    {
        return $this->mapOrLiteralValues($raw);
    }

    /**
     * @return list<mixed>
     */
    private function ensureValues(mixed $raw): array
    {
        // `ensure` optionnel côté spec (défaut `present`). Absent ⇒ aucun enum
        // à valider (le provider posera `present`).
        if ($raw === null) {
            return [];
        }

        return $this->mapOrLiteralValues($raw);
    }

    private function isAbsoluteWindowsPath(string $path): bool
    {
        return (bool) preg_match('/^[A-Za-z]:\\\\/', $path);
    }

    /**
     * Normalise un chemin pour la comparaison EXACTE (Q2) : minuscules,
     * backslashes multiples réduits, backslash final retiré (la racine `C:\`
     * devient `c:` — forme stable).
     */
    private function normalizePath(string $path): string
    {
        $lower = strtolower(trim($path));
        $lower = preg_replace('/\\\\+/', '\\', $lower) ?? $lower;

        return rtrim($lower, '\\');
    }

    private function isProtectedRoot(string $path): bool
    {
        $normalized = $this->normalizePath($path);
        foreach (self::PROTECTED_ROOTS as $root) {
            if ($normalized === $this->normalizePath($root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un trustee littéral est-il un principal SYSTÈME ? Normalise en retirant le
     * préfixe domaine (`NT AUTHORITY\SYSTEM`, `BUILTIN\Administrators` → dernier
     * segment) puis compare insensiblement à la casse.
     */
    private function isSystemTrustee(string $trustee): bool
    {
        $bare = $trustee;
        $pos = strrpos($trustee, '\\');
        if ($pos !== false) {
            $bare = substr($trustee, $pos + 1);
        }

        return in_array(strtolower(trim($bare)), self::SYSTEM_TRUSTEES, true);
    }
}
