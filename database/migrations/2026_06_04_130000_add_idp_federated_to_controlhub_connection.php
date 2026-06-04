<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Ajoute les colonnes du bloc `idp_federated` reçu dans la réponse
     * du handshake controlHub (SSO fédéré — login technicien externe).
     *
     * - idp_public_key : clé publique PEM du controlHub (vérification des JWT fédérés).
     *   Clé PUBLIQUE → pas un secret, pas de chiffrement Crypt (contrairement à api_token).
     * - idp_kid : identifiant de clé (rotation future, ex: "irundo-federated-key-1")
     * - idp_iss : issuer attendu des JWT (ex: "https://central.exemple.fr")
     *
     * Nullables : un controlHub pas encore à jour n'envoie pas le bloc,
     * le handshake reste fonctionnel (SSO simplement indisponible).
     */
    public function up(): void
    {
        Schema::table('controlhub_connection', function (Blueprint $table): void {
            if (!Schema::hasColumn('controlhub_connection', 'idp_public_key')) {
                $table->text('idp_public_key')->nullable()
                    ->comment('Clé publique PEM du controlHub pour vérifier les JWT de login fédéré');
            }
            if (!Schema::hasColumn('controlhub_connection', 'idp_kid')) {
                $table->string('idp_kid', 100)->nullable()
                    ->comment('Key ID de la clé publique fédérée (rotation)');
            }
            if (!Schema::hasColumn('controlhub_connection', 'idp_iss')) {
                $table->string('idp_iss', 512)->nullable()
                    ->comment('Issuer attendu des JWT de login fédéré');
            }
        });
    }

    public function down(): void
    {
        Schema::table('controlhub_connection', function (Blueprint $table): void {
            foreach (['idp_public_key', 'idp_kid', 'idp_iss'] as $column) {
                if (Schema::hasColumn('controlhub_connection', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
