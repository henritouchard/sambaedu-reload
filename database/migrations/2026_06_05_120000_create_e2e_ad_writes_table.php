<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Story 21.2 (DP-LOG = Option 1) — Journal des écritures AD capturées par le
     * fake e2e.
     *
     * **e2e-only par conception.** La table n'est créée QUE si `APP_ENV === 'e2e'`
     * → le schéma de dev/prod/testing reste strictement inchangé (AC5). Le seul
     * écrivain de cette table est le fake AD ({@see \App\Ldap\Fakes\FakeAdDirectory}
     * / journal), lui-même bindé uniquement en e2e ; aucune autre surface ne la
     * touche. Le reset 21.1 (DROP/CREATE de la template) remet le journal à zéro
     * gratuitement.
     *
     * Chaque ligne = une écriture AD interceptée (création user/machine, move de
     * salle, setpassword, membership…) : type d'action, cible, payload pertinent,
     * GUID factice déterministe attribué, timestamp.
     */
    public function up(): void
    {
        // Garde-fou de pollution : aucune création hors e2e. Sur SQLite testing
        // ou Postgres dev/prod, ce `up()` est un no-op.
        if (! App::environment('e2e')) {
            return;
        }

        if (Schema::hasTable('e2e_ad_writes')) {
            return;
        }

        Schema::create('e2e_ad_writes', function (Blueprint $table): void {
            $table->id();

            // Type d'action capturée (ex. `user.create`, `machine.create`,
            // `machine.move`, `setpassword`, `membership.add`).
            $table->string('action_type')->index();

            // Cible logique de l'écriture (samAccountName / CN / nom de salle…).
            $table->string('target')->nullable()->index();

            // GUID factice DÉTERMINISTE attribué à l'objet (D-3) — stable entre
            // étapes d'un même parcours (rename/update/delete le retrouvent).
            $table->string('fake_guid')->nullable();

            // Payload pertinent de l'écriture (attributs, ancienne/nouvelle salle…).
            // JSON pour rester agnostique de la forme par type d'action.
            $table->json('payload')->nullable();

            // Canal physique d'origine (A=ldaprecord, B=bind, C=samba-tool) — utile
            // au debug d'un parcours pour savoir quel canal a été intercepté.
            $table->string('channel')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Symétrique du up() (review 21-2 P-8) : no-op hors e2e — la table n'y
        // existe jamais, mais on garde la même garde explicite.
        if (! App::environment('e2e')) {
            return;
        }

        Schema::dropIfExists('e2e_ad_writes');
    }
};
