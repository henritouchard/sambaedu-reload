<?php

declare(strict_types=1);

namespace App\Models;

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
}
