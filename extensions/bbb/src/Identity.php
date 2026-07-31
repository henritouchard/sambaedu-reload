<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb;

use SambaEdu\ExtBbb\Http\SessionStore;
use SambaEdu\ExtBbb\Oidc\ErrorCodes;
use SambaEdu\ExtBbb\Oidc\OidcException;

/**
 * Story 57.1 — **L'IDENTITÉ TELLE QUE LES CLAIMS LA DONNENT, ET RIEN DE PLUS.**
 *
 * Les claims v1 sont **GELÉS** par le contrat 55.2 :
 *
 * | Scope | Claims |
 * |---|---|
 * | `openid` | `sub` — le login SE5 |
 * | `profile` | `name`, `role` |
 * | `groups` | `groups` — noms NUS des classes et équipes, triés |
 *
 * Ni courriel, ni identifiant d'annuaire, ni permission : choix assumé (élèves
 * mineurs, NFR5). L'extension n'en demande pas davantage et n'en invente pas.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  LE RÔLE EST UN VOCABULAIRE FERMÉ, ET L'INCONNU EST UN REFUS
 *
 *  `role` est un SCALAIRE dont le contrat dit : « non résoluble ⇒ clé ABSENTE »
 *  — jamais `null`, jamais `""`, jamais `"autre"`. Il n'existe donc aucune
 *  valeur hors vocabulaire légitime : une valeur inconnue est soit un
 *  fournisseur qui a changé de contrat, soit une tentative. Dans les deux cas la
 *  réponse est la même : **pas d'identité, pas d'état ouvert**, et surtout aucun
 *  repli sur un rôle par défaut — un repli permissif serait une élévation de
 *  privilège silencieuse, un repli restrictif masquerait une rupture de contrat.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Un claim est une DONNÉE, pas une autorisation.** `role === 'admin'` dit ce
 * que SE5 sait de la personne ; c'est l'extension qui décide, à chaque requête,
 * ce que cela ouvre.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  L'IDENTITÉ LOCALE PÉRIME — review 57.1 #2
 *
 *  Le rôle relu ici vient de la SESSION, donc des claims d'UNE authentification
 *  passée. L'extension n'appelle ni `/userinfo` ni `/api/ext/v1/` (l'id_token
 *  suffit à son métier) et le contrat lui interdit le refresh token : elle n'a
 *  donc aucun moyen d'apprendre qu'un rôle a changé.
 *
 *  Sans borne, un compte rétrogradé garderait son `role` — donc, pour `admin`,
 *  la page des serveurs BBB et ses secrets — jusqu'à ce qu'il ferme son
 *  navigateur. C'est le contraire de la promesse du contrat (« une révocation
 *  prend effet dans la seconde », §6), tenue par `/userinfo` et l'API mais pas
 *  par une session locale éternelle.
 *
 *  D'où {@see self::MAX_AGE} : au-delà, l'identité est réputée périmée et le
 *  parcours SSO doit être rejoué. Le coût est nul pour l'utilisateur — tant que
 *  sa session SE5 vit, `/oidc/authorize` le renvoie aussitôt, sans ressaisie.
 *
 *  ⚠️ Cette borne est PROPRE à l'extension, ce n'est PAS une recopie de la durée
 *  de session de SE5 : l'extension ne peut pas la lire (FR33), et une règle
 *  recopiée finit toujours par diverger. Elle dit seulement « au bout de ce
 *  temps, redemande ».
 * ══════════════════════════════════════════════════════════════════════════
 */
final class Identity
{
    /** Vocabulaire FERMÉ du claim `role` (contrat v1, gelé). */
    public const ROLES = ['prof', 'eleve', 'administratif', 'admin'];

    /**
     * Âge maximal d'une identité locale, en secondes (12 h — au-delà d'une
     * journée de classe, on redemande). Voir le docblock de classe.
     */
    public const MAX_AGE = 43200;

    private const SESSION_KEY = 'identity';

    private const SESSION_AUTHENTICATED_AT = 'identity.authenticated_at';

    /**
     * @param  list<string>  $groups
     */
    public function __construct(
        public readonly string $sub,
        public readonly string $name,
        public readonly string $role,
        public readonly array $groups = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $claims
     *
     * @throws OidcException  Rôle absent ou hors vocabulaire.
     */
    public static function fromClaims(array $claims): self
    {
        $sub = isset($claims['sub']) && is_scalar($claims['sub']) ? trim((string) $claims['sub']) : '';
        $role = isset($claims['role']) && is_string($claims['role']) ? $claims['role'] : '';

        if ($sub === '') {
            throw OidcException::of(ErrorCodes::MISSING_CLAIM, 'sub absent');
        }

        if (! in_array($role, self::ROLES, true)) {
            throw OidcException::of(
                ErrorCodes::ROLE_UNSUPPORTED,
                'rôle absent ou hors vocabulaire — aucune identité ouverte',
            );
        }

        $name = isset($claims['name']) && is_scalar($claims['name']) ? trim((string) $claims['name']) : '';

        $groups = [];
        if (isset($claims['groups']) && is_array($claims['groups'])) {
            foreach ($claims['groups'] as $group) {
                if (is_string($group) && trim($group) !== '') {
                    $groups[] = trim($group);
                }
            }
        }

        return new self($sub, $name !== '' ? $name : $sub, $role, array_values(array_unique($groups)));
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function storeIn(SessionStore $store, ?int $now = null): void
    {
        $store->put(self::SESSION_KEY, [
            'sub' => $this->sub,
            'name' => $this->name,
            'role' => $this->role,
            'groups' => $this->groups,
        ]);
        $store->put(self::SESSION_AUTHENTICATED_AT, $now ?? time());
    }

    /**
     * Relit l'identité de l'état courant. Toute anomalie — un rôle devenu
     * invalide entre-temps, une identité PÉRIMÉE — rend `null` : le contrôle se
     * rejoue à CHAQUE requête, il n'est pas acquis une fois pour toutes à la
     * connexion.
     *
     * Une identité périmée est EFFACÉE au passage : elle ne doit pas rester à
     * traîner dans l'état, et un `null` non nettoyé se relirait indéfiniment.
     */
    public static function fromSessionStore(SessionStore $store, ?int $now = null): ?self
    {
        $raw = $store->get(self::SESSION_KEY);

        if (! is_array($raw)) {
            return null;
        }

        $authenticatedAt = $store->get(self::SESSION_AUTHENTICATED_AT);
        $now ??= time();

        // Horodatage absent (état d'une version antérieure) ou dépassé : dans
        // les deux cas on ne peut PAS affirmer que l'identité est fraîche.
        if (! is_int($authenticatedAt) || $authenticatedAt > $now || ($now - $authenticatedAt) >= self::MAX_AGE) {
            self::clear($store);

            return null;
        }

        try {
            return self::fromClaims($raw);
        } catch (OidcException) {
            self::clear($store);

            return null;
        }
    }

    public static function clear(SessionStore $store): void
    {
        $store->forget(self::SESSION_KEY);
        $store->forget(self::SESSION_AUTHENTICATED_AT);
    }
}
