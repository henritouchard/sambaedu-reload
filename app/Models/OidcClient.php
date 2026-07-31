<?php

declare(strict_types=1);

namespace App\Models;

use App\Auth\Oidc\Support\OidcClaimsResolver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Story 55.1 — Un CLIENT CONFIDENTIEL du fournisseur OIDC de SE5.
 *
 * Le registre des clients est un PROLONGEMENT du registre d'extensions
 * (`extension_id` nullable + `extension_key` dénormalisée) : une extension de
 * type `app` disposera de son client, provisionné automatiquement à
 * l'installation par l'Epic 56. En 55.1 les clients sont déclarés à la main
 * (`php artisan oidc:client:register`) — le canal d'installation n'existe pas
 * encore.
 *
 * ⚠️ **NFR3 — `client_secret_hash` est dans `$hidden`** : ni le secret ni son
 * hash ne doivent sortir en JSON, en log ou en UI. Le secret CLAIR n'est jamais
 * stocké : il est affiché UNE SEULE FOIS par la commande d'enregistrement, puis
 * n'existe plus que chez l'intégrateur.
 *
 * ⚠️ **`redirect_uris` est une liste STRICTE** : la comparaison est une égalité
 * de chaînes exacte, à l'autorisation ET à l'échange. Aucun préfixe, aucun
 * wildcard, aucune normalisation — une correspondance lâche ferait de SE5 un
 * open-redirector et laisserait fuir les codes d'autorisation.
 *
 * **Révocation = `enabled = false`** : la résolution passe TOUJOURS par
 * {@see \App\Auth\Oidc\Services\OidcClientRegistry::findEnabledByClientId()},
 * de sorte qu'un client révoqué n'obtient plus ni code ni token, tout en
 * conservant sa trace au registre.
 *
 * @property int $id
 * @property int|null $extension_id
 * @property string $extension_key
 * @property string $name
 * @property string $client_id
 * @property string $client_secret_hash
 * @property list<string> $redirect_uris
 * @property list<string> $granted_scopes
 * @property bool $enabled
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Extension|null $extension
 */
class OidcClient extends Model
{
    use HasFactory;

    protected $table = 'oidc_clients';

    /** @var list<string> */
    protected $fillable = [
        'extension_id',
        'extension_key',
        'name',
        'client_id',
        'client_secret_hash',
        'redirect_uris',
        'granted_scopes',
        'enabled',
    ];

    /**
     * NFR3 : le hash du secret ne sort JAMAIS d'une sérialisation. Il n'est
     * comparé qu'en mémoire, par `hash_equals`, dans le registre.
     *
     * @var list<string>
     */
    protected $hidden = [
        'client_secret_hash',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'redirect_uris' => 'array',
        'granted_scopes' => 'array',
        'enabled' => 'boolean',
    ];

    /** L'extension à laquelle ce client est adossé (nullable — cf. décision #3). */
    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'extension_id');
    }

    /** Les codes d'autorisation émis pour ce client. */
    public function authorizationCodes(): HasMany
    {
        return $this->hasMany(OidcAuthorizationCode::class, 'oidc_client_id');
    }

    /** Les access tokens opaques émis pour ce client. */
    public function accessTokens(): HasMany
    {
        return $this->hasMany(OidcAccessToken::class, 'oidc_client_id');
    }

    /**
     * Une `redirect_uri` est-elle déclarée par ce client ?
     *
     * ⚠️ Égalité de chaîne EXACTE — c'est la règle de sécurité, pas une
     * commodité : voir le docblock de classe.
     */
    public function allowsRedirectUri(string $redirectUri): bool
    {
        foreach ($this->redirectUris() as $declared) {
            if (hash_equals($declared, $redirectUri)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function redirectUris(): array
    {
        $uris = $this->redirect_uris;
        if (! is_array($uris)) {
            return [];
        }

        return array_values(array_map(static fn ($uri): string => (string) $uri, $uris));
    }

    /**
     * Story 56.4 — Les scopes ACCORDÉS à ce client, normalisés.
     *
     * Normalisation : chaînes non vides, dédupliquées, triées — de sorte que
     * l'affichage de la fiche, l'audit et l'intersection ci-dessous ne dépendent
     * jamais de l'ordre d'écriture en base.
     *
     * ⚠️ **`openid` n'y figure JAMAIS.** C'est le plancher du protocole (le
     * `sub` du SSO) : il n'est ni accordé, ni révocable. Révoquer l'identité,
     * c'est désinstaller l'extension (FR10), pas révoquer un scope (FR23).
     *
     * ⚠️ Cette méthode NE filtre PAS sur le catalogue de scopes : la garde de
     * vocabulaire vit à l'OCTROI
     * ({@see \App\Auth\Oidc\Services\OidcClientRegistry::normalizeGrantedScopes()}),
     * de sorte qu'une valeur aberrante posée à la main en base reste VISIBLE
     * sur la fiche au lieu d'être silencieusement masquée. Elle ne peut de toute
     * façon rien produire : {@see self::effectiveScopeFor()} l'intersecte avec
     * un scope de jeton déjà validé contre le catalogue fermé.
     *
     * @return list<string>
     */
    public function grantedScopes(): array
    {
        $scopes = $this->granted_scopes;

        if (! is_array($scopes)) {
            return [];
        }

        $normalized = [];

        foreach ($scopes as $scope) {
            if (! is_string($scope)) {
                continue;
            }

            $scope = trim($scope);

            if ($scope === '') {
                continue;
            }

            $normalized[] = $scope;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * Story 56.4 — **LE POINT UNIQUE du scope EFFECTIF** (FR23).
     *
     * ══════════════════════════════════════════════════════════════════════
     *  UNE RÈGLE, UN SEUL ÉNONCÉ, TROIS CONSOMMATEURS
     *
     *  scope effectif = scope DU JETON ∩ (`granted_scopes` + `openid`)
     *
     *   1. l'ÉMISSION  ({@see \App\Auth\Oidc\Http\Controllers\TokenController})
     *      — claims de l'id_token filtrés, access token persisté au scope
     *      effectif, et paramètre `scope` de la réponse qui l'ANNONCE
     *      (downscoping RFC 6749 §3.3) ;
     *   2. `/userinfo` (via `OidcAccessTokenValidator`) ;
     *   3. l'API extensions `/api/ext/v1/` (via le middleware `ext.token`).
     *
     *  Recalculé à CHAQUE usage : c'est ce qui rend une révocation immédiate
     *  sur les jetons DÉJÀ ÉMIS, sans purge ni ré-émission. Un seul de ces
     *  trois points qui lirait le scope stocké ferait mentir la révocation —
     *  d'où l'énoncé unique ici plutôt que trois intersections recopiées
     *  (leçon review 56.1 #3).
     * ══════════════════════════════════════════════════════════════════════
     *
     * **L'ORDRE DU SCOPE DEMANDÉ EST PRÉSERVÉ**, et le résultat n'est PAS
     * retrié : quand tout est accordé, l'effectif est alors identique CARACTÈRE
     * POUR CARACTÈRE au scope demandé — ce qui garantit que cette story
     * n'introduit aucune différence observable pour un client pleinement
     * consenti (l'ordre d'un `scope` OAuth n'est pas significatif, mais il est
     * observé : `oidc_access_tokens.scope` est asserté verbatim par les suites
     * 55.x).
     *
     * ⚠️ Un scope demandé HORS catalogue n'arrive jamais ici : il est refusé
     * `invalid_scope` à l'autorisation (invariant README #11). Le non-accordé,
     * lui, n'est pas refusé — il est RÉDUIT (décision 56.4 n° 3 : révoquer une
     * donnée ne doit pas provoquer une panne de SSO).
     */
    public function effectiveScopeFor(string $requestedScope): string
    {
        $requested = OidcClaimsResolver::parseScope($requestedScope);

        $allowed = array_merge(['openid'], $this->grantedScopes());

        $effective = [];

        foreach ($requested as $scope) {
            if (in_array($scope, $allowed, true) && ! in_array($scope, $effective, true)) {
                $effective[] = $scope;
            }
        }

        return implode(' ', $effective);
    }
}
