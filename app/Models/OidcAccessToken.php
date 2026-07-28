<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 55.1 — Un ACCESS TOKEN OPAQUE (TTL 600 s).
 *
 * **Opaque, et pas un JWT** : ce jeton ne porte aucune information ; il ne sert
 * qu'à retrouver cette ligne. C'est un choix de conception — un access token
 * auto-porteur ne peut pas être révoqué avant son `exp`, alors qu'une ligne en
 * base se supprime. Le seul jeton auto-porteur du système est l'`id_token`,
 * dont c'est précisément la fonction (preuve d'authentification vérifiable hors
 * ligne par le client).
 *
 * En 55.1 ce jeton est ÉMIS mais jamais consommé : la réponse du token endpoint
 * DOIT contenir un `access_token` (RFC 6749 §5.1) et le poser dès maintenant
 * évite une re-plomberie en 55.2, où `/userinfo` le lira.
 *
 * ⚠️ **`token_hash` est dans `$hidden` et stocke un sha256** : le jeton clair ne
 * touche jamais la base ni les logs (NFR3).
 *
 * Story 55.2 — `user_id` (migration additive `2026_07_28_310000`) : c'est par
 * CETTE clé que `/userinfo` résout l'utilisateur, jamais par `user_login`.
 * `user_login` est le **sub PUBLIÉ** (résolu à l'émission par
 * {@see \App\Auth\Oidc\Support\OidcSubjectResolver}) : une valeur de contrat,
 * pas une clé de jointure. Les deux colonnes coexistent pour cette raison —
 * `user_login` garantit l'égalité `sub` id_token ⇄ `sub` userinfo (OIDC Core
 * §5.3.2) PAR CONSTRUCTION, `user_id` garantit la résolution métier.
 *
 * @property int $id
 * @property int $oidc_client_id
 * @property int|null $user_id
 * @property string $user_login
 * @property string $token_hash
 * @property string $scope
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read OidcClient|null $client
 * @property-read User|null $user
 */
class OidcAccessToken extends Model
{
    protected $table = 'oidc_access_tokens';

    /** Table technique : un jeton s'émet et expire, il ne se « modifie » pas. */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'oidc_client_id',
        'user_id',
        'user_login',
        'token_hash',
        'scope',
        'expires_at',
        'created_at',
    ];

    /** NFR3 : le hash du jeton ne sort jamais d'une sérialisation. */
    protected $hidden = [
        'token_hash',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(OidcClient::class, 'oidc_client_id');
    }

    /**
     * Story 55.2 — l'utilisateur porteur du jeton.
     *
     * `null` si le compte a été supprimé depuis l'émission : c'est un cas
     * NOMINAL, traité fail-closed par
     * {@see \App\Auth\Oidc\Services\OidcAccessTokenValidator} (401, aucune
     * donnée), jamais une erreur d'intégrité.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
