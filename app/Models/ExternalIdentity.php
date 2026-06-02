<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Story 20.1 — D-8.
 *
 * Identité externe persistante d'un acteur fédéré (technicien flotte, hors-AD).
 * Distincte de l'AD/LdapUser : JAMAIS écrite dans l'AD, JAMAIS synchronisée,
 * JAMAIS hard-delete (soft-delete) — cf. archi « identité persistante ≠ accès ».
 *
 * Upsert au login fédéré, clé = `external_sub` (claim `sub` du JWT, stable
 * côté IdP). Une reconnexion réutilise le même enregistrement.
 *
 * ⚠️ Le CYCLE DE VIE complet (sémantique soft-delete avancée, sync profil,
 * base légale RGPD de rétention) est HORS-SCOPE 20.1 → Story 20.2. Ce modèle
 * livre le strict minimum pour ouvrir une session (M2/IR).
 *
 * @property int $id
 * @property string $external_sub
 * @property string $issuer
 * @property string|null $login
 * @property string|null $name
 * @property string|null $email
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class ExternalIdentity extends Model
{
    use SoftDeletes;

    /** @var string */
    protected $table = 'external_identities';

    /** @var array<int,string> */
    protected $fillable = [
        'external_sub',
        'issuer',
        'login',
        'name',
        'email',
        'is_active',
        'last_login_at',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    /**
     * Les utilisateurs Eloquent provisionnés pour cette identité externe
     * (D-4 : principal de session = App\Models\User marqué `source='federated'`).
     *
     * @return HasMany<User>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'external_identity_id');
    }
}
