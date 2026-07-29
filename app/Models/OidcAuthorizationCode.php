<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 55.1 — Un CODE D'AUTORISATION à usage unique (TTL 60 s).
 *
 * C'est la seule chose qui transite par la barre d'adresse du navigateur entre
 * `/oidc/authorize` et le client : un identifiant opaque, à durée de vie très
 * courte, **inutilisable sans le `code_verifier` PKCE** que seul le client
 * légitime possède. Sa contrepartie serveur porte tout le contexte de la
 * requête d'autorisation (URI de redirection, challenge, `nonce`, `scope`,
 * sujet) pour que l'échange puisse tout re-vérifier.
 *
 * ⚠️ **`code_hash` est dans `$hidden` et stocke un sha256** : le code CLAIR ne
 * touche jamais la base ni les logs (NFR3). Un vol de la base ne donne aucun
 * code utilisable.
 *
 * ⚠️ **Usage unique** : `consumed_at` est posé DANS la transaction d'échange
 * (`lockForUpdate`), de sorte que deux échanges concurrents ne peuvent pas
 * gagner tous les deux. Un échec de vérification du `code_verifier` consomme
 * AUSSI le code : le code a été présenté par quelqu'un qui le possède, il n'y a
 * pas de seconde chance.
 *
 * `user_login` porte la valeur du futur claim `sub`. Sa résolution est confiée
 * à un point UNIQUE : {@see \App\Auth\Oidc\Support\OidcSubjectResolver}.
 *
 * @property int $id
 * @property int $oidc_client_id
 * @property int|null $user_id
 * @property string $user_login
 * @property string $code_hash
 * @property string $redirect_uri
 * @property string $code_challenge
 * @property string $code_challenge_method
 * @property string $nonce
 * @property string $scope
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $consumed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read OidcClient|null $client
 */
class OidcAuthorizationCode extends Model
{
    protected $table = 'oidc_authorization_codes';

    /** Table technique : un code se consomme, il ne se « modifie » pas. */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'oidc_client_id',
        'user_id',
        'user_login',
        'code_hash',
        'redirect_uri',
        'code_challenge',
        'code_challenge_method',
        'nonce',
        'scope',
        'expires_at',
        'consumed_at',
        'created_at',
    ];

    /** NFR3 : le hash du code ne sort jamais d'une sérialisation. */
    protected $hidden = [
        'code_hash',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(OidcClient::class, 'oidc_client_id');
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
