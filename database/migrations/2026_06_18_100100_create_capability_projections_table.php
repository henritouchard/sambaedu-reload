<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Epic 27 — Projection d'une capacité : COMMENT une intention se matérialise sur
 * un OS donné, via un MÉCANISME donné (= le `type` du contrat desired-state).
 *
 * Une capacité a 1..N projections. Le compilateur sélectionne celles dont l'`os`
 * correspond à la cible et dont le `mechanism` correspond au provider, puis
 * expanse la `spec` en items de contrat concrets selon la valeur effective de la
 * capacité. L'agent ne reçoit JAMAIS « capacité » : il reçoit `{type:<mechanism>}`.
 *
 * `mechanism` ∈ {registry, firewall, localgroup, file, …} = identifiant figé du
 * type de ressource (cf. `StateContract::RESOURCE_TYPES`). ⚠️ `registry` est déjà
 * publié (gratuit) ; tout NOUVEAU mécanisme = ajout ADDITIF à la liste figée +
 * handler agent + redéploiement (slices B/C).
 *
 * Format de `spec` (interprété par le compilateur, mécanisme par mécanisme) :
 *  - registry : { "keys": [ { hive, path, name, type, value }, … ] } où chaque
 *    `value` est SOIT un littéral (toujours émis quand la capacité s'applique),
 *    SOIT une MAP valeur-capacité → donnée-registre (ex. {"on":0,"off":1}).
 *    Une clé de capacité absente de la map ⇒ la clé n'est PAS émise (= cesser de
 *    gérer, piège n°5). La donnée est ensuite COERCÉE par `type` au payload
 *    (DWORD/QWORD→int, MULTI_SZ→liste de chaînes, SZ/EXPAND_SZ→chaîne ; zéro float),
 *    comme en 27.3.
 *  - firewall (slice B) : { "value": { "on": { action, allow }, … } }.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('capability_projections')) {
            return;
        }

        Schema::create('capability_projections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capability_id')
                ->constrained('capabilities')
                ->cascadeOnDelete();

            $table->string('os', 16)->comment('OS cible de cette projection : windows | linux');
            $table->string('mechanism', 32)->comment('Mécanisme = type contrat (registry|firewall|localgroup|…) — figé NFR12');
            $table->json('spec')->comment('Définition mécanisme-spécifique : comment la valeur de capacité se matérialise');

            $table->timestamps();

            // Une seule projection par (capacité, os, mécanisme) : la spec d'un
            // mécanisme porte déjà la LISTE de ses éléments (ex. registry.keys[]).
            $table->unique(['capability_id', 'os', 'mechanism'], 'capability_projection_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capability_projections');
    }
};
