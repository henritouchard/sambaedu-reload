<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Nextcloud;

/**
 * Story 61.3 — L'ÉTAT DE PERMISSIONS AVANCÉES RELU SUR UN CHEMIN.
 *
 * **Trois choses distinctes, et les confondre serait la fuite.**
 *
 *  - `$exists` — le chemin est là. `false` est un FAIT constaté, pas une ignorance.
 *  - `$readable` — la lecture a abouti. `false` veut dire « je ne sais pas », et
 *    « je ne sais pas » ne devient JAMAIS « conforme » : le backend le rend en
 *    observation non mesurable, jamais en observation vide.
 *  - `$rules` / `$inherited` — les règles posées ICI, et celles qui DESCENDENT de
 *    l'ancêtre. C'est la distinction que la clôture existe pour résoudre, et
 *    l'instance la nomme elle-même : la propagation d'un ancêtre a son propre nom
 *    dans le protocole. Elle est le pivot de la reprojection.
 *
 * Une liste de règles vide avec `$readable = true` est une VRAIE réponse : « aucune
 * règle n'est posée ». C'est ce que dit le statut `404` de la propriété dans le
 * corps de la relecture, et il ne se lit pas comme une erreur.
 */
final class NextcloudAclState
{
    /**
     * @param  list<NextcloudAclRule>  $rules
     * @param  list<NextcloudAclRule>  $inherited
     */
    private function __construct(
        public readonly bool $exists,
        public readonly bool $readable,
        public readonly array $rules,
        public readonly array $inherited,
        public readonly bool $aclEnabled,
        public readonly ?string $error,
    ) {
    }

    /**
     * @param  list<NextcloudAclRule>  $rules
     * @param  list<NextcloudAclRule>  $inherited
     */
    public static function read(array $rules, array $inherited, bool $aclEnabled): self
    {
        return new self(true, true, $rules, $inherited, $aclEnabled, null);
    }

    /** Le chemin a été cherché et n'existe pas. */
    public static function absent(): self
    {
        return new self(false, true, [], [], false, null);
    }

    /** La lecture a échoué : on ne conclut rien. */
    public static function unreadable(string $error): self
    {
        return new self(false, false, [], [], false, $error);
    }

    /** La règle POSÉE ICI pour ce principal, ou `null`. */
    public function ruleFor(string $principalKey): ?NextcloudAclRule
    {
        foreach ($this->rules as $rule) {
            if ($rule->principalKey() === $principalKey) {
                return $rule;
            }
        }

        return null;
    }

    /** La règle HÉRITÉE de l'ancêtre pour ce principal, ou `null`. */
    public function inheritedRuleFor(string $principalKey): ?NextcloudAclRule
    {
        foreach ($this->inherited as $rule) {
            if ($rule->principalKey() === $principalKey) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Les deux listes de règles portent-elles EXACTEMENT le jeu voulu, posé ici ?
     *
     * Comparaison sur les valeurs RELUES, ensemble contre ensemble, sans tenir
     * compte de l'ordre — l'instance n'en garantit aucun, et l'exiger fabriquerait
     * une dérive à chaque passage.
     *
     * @param  list<NextcloudAclRule>  $wanted
     */
    public function carriesExactly(array $wanted): bool
    {
        if (count($this->rules) !== count($wanted)) {
            return false;
        }

        foreach ($wanted as $rule) {
            $found = $this->ruleFor($rule->principalKey());
            if ($found === null || ! $found->equals($rule)) {
                return false;
            }
        }

        return true;
    }
}
