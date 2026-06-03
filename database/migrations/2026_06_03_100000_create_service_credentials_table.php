<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store des credentials de comptes de service générés au runtime (ex.
 * `se4install`), source de vérité SQL — remplace la persistance dans
 * `/etc/sambaedu/sambaedu.conf` (`se4install_passwd`) et `/etc/sambaedu/hashes`
 * (token TOTP), destinés à disparaître avec le legacy.
 *
 * Conventions :
 *   - `name`        : identifiant logique du compte (`se4install`, …), unique.
 *   - `secret`      : mot de passe de base, CHIFFRÉ at-rest (cast `encrypted`
 *                     du modèle → AES-256-GCM via APP_KEY).
 *   - `totp_secret` : secret base32 du TOTP (pas de 6 h, SHA256, 6 digits —
 *                     parité legacy `oathtool -s 6h`), CHIFFRÉ at-rest. Nullable
 *                     tant que le port TOTP n'est pas câblé.
 *
 * `text` (et non `string`) car le payload chiffré Laravel est long (base64).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->text('secret')->nullable();
            $table->text('totp_secret')->nullable();
            // Compteur TOTP (= floor(epoch / period)) réellement appliqué à l'AD.
            // N'est avancé QU'APRÈS un write AD confirmé → garantit la
            // réconciliation idempotente et évite toute désync permanente
            // (cf. ServiceCredentials + commande sambaedu:totp:reconcile).
            $table->unsignedBigInteger('totp_applied_counter')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_credentials');
    }
};
