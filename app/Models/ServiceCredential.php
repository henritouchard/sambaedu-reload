<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Credential d'un compte de service généré au runtime (ex. `se4install`).
 *
 * Source de vérité SQL pour des secrets que l'application génère elle-même et
 * doit persister au-delà du reboot — par opposition à `.env`/config (immuables,
 * gelés par `config:cache`, non chiffrés). Remplace `se4install_passwd` dans
 * `sambaedu.conf` et le token TOTP dans `/etc/sambaedu/hashes`.
 *
 * `secret` et `totp_secret` sont chiffrés at-rest via le cast `encrypted`
 * (AES-256-GCM, clé = APP_KEY). Lire/écrire les attributs en clair : le cast
 * fait le (dé)chiffrement de façon transparente.
 *
 * Accès recommandé via {@see \App\Services\ServiceCredentials} (API typée +
 * mémoïsation), pas directement par le modèle.
 *
 * @property int $id
 * @property string $name
 * @property string|null $secret  Mot de passe de base (déchiffré à la lecture).
 * @property string|null $totp_secret  Secret base32 du TOTP (déchiffré à la lecture).
 * @property int|null $totp_applied_counter  Fenêtre TOTP appliquée à l'AD (avancée après write confirmé).
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ServiceCredential extends Model
{
    protected $table = 'service_credentials';

    protected $fillable = [
        'name',
        'secret',
        'totp_secret',
        'totp_applied_counter',
    ];

    protected $casts = [
        'secret' => 'encrypted',
        'totp_secret' => 'encrypted',
        'totp_applied_counter' => 'integer',
    ];
}
