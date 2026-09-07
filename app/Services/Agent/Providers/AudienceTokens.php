<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Constants\Ldap\MainGroups;
use App\Models\UserGroup;

/**
 * Jetons d'audience `fs_acl` : ENUM FERMÉ EN DUR.
 *
 * Un `trustee` de projection `windows/fs_acl` peut être :
 *   - un **jeton** `@eleves | @profs | @personnels` — résolu par CONVENTION
 *     vers le nom du groupe principal GLOBAL du domaine
 *     ({@see MainGroups} : `Eleves`, `Profs`, `Administratifs` ; en SQL
 *     `user_groups.type = 'role'`, migration `2026_03_31_100000`), à condition
 *     que ce groupe EXISTE réellement dans `user_groups` (vérification mémoïsée
 *     par instance de service) ;
 *   - un **littéral** (tout ce qui ne commence pas par `@`, ex. `Domain Users`)
 *     — parti VERBATIM au payload : c'est l'AGENT qui le résout via LSA sur le
 *     poste joint (échec ⇒ erreur d'item, visible).
 *
 * **Vocabulaire EN DUR.** Aucune UI d'admin, aucune table d'audiences, aucun
 * mapping configurable : les trois jetons ci-dessus sont l'enum entier. Le
 * ciblage par un groupe SQL ARBITRAIRE passe par le picker du formulaire, PAS
 * par un jeton.
 *
 * Service PUR côté serveur (Postgres uniquement : une requête d'existence
 * mémoïsée) — jamais l'AD / LdapRecord / APCu (critère Keycloak). La
 * résolution SID reste 100 % côté POSTE (LSA), le serveur ne manipule que des
 * NOMS. RÉUTILISABLE tel quel (map publique).
 */
final class AudienceTokens
{
    /**
     * Jetons d'audience → nom de groupe conventionnel (enum FERMÉ). Les
     * valeurs réutilisent les constantes `MainGroups` (groupes principaux
     * globaux du domaine) : une SEULE source du vocabulaire.
     *
     * @var array<string, string>
     */
    public const TOKENS = [
        '@eleves' => MainGroups::ELEVES,
        '@profs' => MainGroups::PROFS,
        '@personnels' => MainGroups::ADMINISTRATIFS,
    ];

    /**
     * Préfixe qui distingue un jeton d'un trustee littéral.
     */
    public const PREFIX = '@';

    /**
     * Noms de groupes conventionnels présents dans `user_groups` — mémoïsé par
     * instance/requête HTTP (une seule requête pour les trois jetons). Clé =
     * nom conventionnel minuscule, valeur = présent (bool).
     *
     * @var array<string, bool>|null
     */
    private ?array $existingGroups = null;

    /**
     * Un trustee est-il un jeton d'audience (préfixe `@`) ?
     */
    public static function isToken(string $trustee): bool
    {
        return str_starts_with($trustee, self::PREFIX);
    }

    /**
     * Résout un trustee de `spec` vers le NOM à émettre au payload, ou `null`
     * si irrésoluble (jeton inconnu OU groupe conventionnel absent de
     * `user_groups`) — l'appelant N'ÉMET alors PAS l'entrée + loggue un
     * warning : un jeton brut n'atteint JAMAIS le payload.
     *
     *   - trustee littéral (pas de préfixe `@`) ⇒ renvoyé VERBATIM ;
     *   - jeton connu dont le groupe conventionnel EXISTE ⇒ nom conventionnel ;
     *   - jeton inconnu OU groupe absent ⇒ `null`.
     *
     * @return string|null nom à émettre, ou null (non résoluble)
     */
    public function resolve(string $trustee): ?string
    {
        if (! self::isToken($trustee)) {
            // Littéral : verbatim — l'agent le résout via LSA.
            return $trustee;
        }

        $conventional = self::TOKENS[strtolower($trustee)] ?? null;
        if ($conventional === null) {
            return null; // jeton hors enum fermé.
        }

        return $this->groupExists($conventional) ? $conventional : null;
    }

    /**
     * Le groupe conventionnel existe-t-il dans `user_groups` ? Requête UNIQUE
     * (les trois noms d'un coup) mémoïsée par instance : à l'expansion d'un
     * catalogue, le provider crée une instance par render → une requête au plus.
     */
    private function groupExists(string $conventional): bool
    {
        if ($this->existingGroups === null) {
            $names = UserGroup::query()
                ->whereIn('name', array_values(self::TOKENS))
                ->pluck('name')
                ->all();

            $this->existingGroups = [];
            foreach ($names as $name) {
                $this->existingGroups[strtolower((string) $name)] = true;
            }
        }

        return $this->existingGroups[strtolower($conventional)] ?? false;
    }
}
