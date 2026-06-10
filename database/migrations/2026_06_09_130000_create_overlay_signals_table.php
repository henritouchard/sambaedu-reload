<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table `overlay_signals` — canal « signaux postés » de l'overlay poste.
 *
 * Un producteur (déclencheur Veyon, tâche admin, future UI « infos à
 * transmettre ») poste un signal ciblé sur un poste et/ou un user ; il est
 * renvoyé à CHAQUE poll tant qu'il est actif (`expires_at` null ou futur),
 * puis disparaît. Cf. spike `spike-wallpaper-overlay-tools-2026-06-09.md`.
 *
 * Les signaux « dérivés » (multi-session, quota) ne sont PAS stockés ici :
 * ils sont recalculés à chaque poll par `OverlaySignalBuilder`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overlay_signals', function (Blueprint $table): void {
            $table->id();

            // Classification / rendu côté overlay.
            $table->string('kind', 32)->default('notice');       // notice | remote_control | …
            $table->string('severity', 16)->default('info');     // info | warning | critical
            $table->string('title');
            $table->text('text');

            // Ciblage : null = joker. Un signal matche un poll si
            // (workstation_uuid null OU = courant) ET (user_login null OU = courant).
            $table->string('workstation_uuid', 36)->nullable()->index();
            $table->string('user_login')->nullable()->index();

            // Cycle de vie : null = pas d'expiration ; sinon actif tant que futur.
            $table->timestamp('expires_at')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overlay_signals');
    }
};
