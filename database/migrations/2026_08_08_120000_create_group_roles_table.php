<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Story 62.1 — LE CATALOGUE DE RÔLES devient un objet de premier niveau.
 *
 * **Pourquoi une table et pas une constante de plus.** Le rôle porté sur une
 * arête d'appartenance (`user_group_user.role`) était un vocabulaire FERMÉ, écrit
 * en dur à deux endroits — le pivot d'arête, et sa recopie dans le normalizer du
 * plan de fichiers. Un établissement qui voulait un « tuteur » ou un « référent
 * numérique » n'avait aucun chemin : il fallait une story. Le catalogue rend le
 * vocabulaire ADMINISTRABLE sans rien casser, parce que la clé stockée ne change
 * jamais (décision Q1 = A, Henri 2026-08-08).
 *
 * **Pourquoi `group_roles` et pas `roles`.** Le nom `roles` est PRIS par Spatie
 * (profils de permissions applicatives). Ce sont deux notions disjointes — l'un
 * qualifie une APPARTENANCE à un groupe, l'autre accorde des DROITS dans SE5 — et
 * les faire cohabiter dans une table homonyme serait la confusion la plus chère du
 * modèle.
 *
 * **Pourquoi `key` est plafonnée à 20 caractères.** Elle est écrite telle quelle
 * dans `user_group_user.role`, qui est un `string('role', 20)` depuis la story
 * 42.1. Une clé plus longue passerait en SQLite (qui ne borne pas les varchar) et
 * lèverait un 22001 en PostgreSQL, à l'écriture, en production. La borne vit donc
 * ici ET dans la garde `saving` du modèle {@see \App\Models\GroupRole}.
 *
 * **Pourquoi la référence est la CLÉ et pas un id.** Une valeur lisible en base
 * vaut mieux qu'une jointure pour lire un pivot, et la clé est immuable par
 * construction. Aucune contrainte de clé étrangère n'est posée sur le pivot : la
 * garde applicative reste la frontière (comme depuis 42.1), et le refus de
 * suppression est NOMMÉ (« 42 appartenances et 2 recettes portent ce rôle »), ce
 * qu'un `RESTRICT` ne saurait pas dire.
 *
 * **La normalisation des arêtes.** La lecture normalise déjà toute valeur hors
 * vocabulaire vers `member` depuis la story 42.3. Cette migration applique la MÊME
 * normalisation À L'ÉCRITURE, une fois : sans elle, la colonne resterait à deux
 * vocabulaires (celui du catalogue, et le résidu que seule la lecture masquait).
 * Elle ne touche AUCUNE ligne dont le rôle est déjà `member`, `manager` ou
 * `owner` — c'est-à-dire, sur une base saine, aucune ligne du tout.
 */
return new class extends Migration
{
    /** Les trois clés HISTORIQUES, structurelles : elles se seedent, jamais ne se suppriment. */
    private const HISTORICAL_KEYS = ['member', 'manager', 'owner'];

    public function up(): void
    {
        if (! Schema::hasTable('group_roles')) {
            Schema::create('group_roles', function (Blueprint $table) {
                $table->id();
                // Clé IMMUABLE, slug snake_case, ≤ 20 : c'est elle qui est stockée
                // sur l'arête. Unique — deux rôles ne peuvent pas se disputer la
                // même valeur de pivot.
                $table->string('key', 20)->unique();
                $table->string('label');
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        $this->normalizeOutOfVocabularyEdges();
    }

    /**
     * Le `down()` retire la TABLE, et rien d'autre.
     *
     * La normalisation des arêtes n'est volontairement pas défaite : les valeurs
     * d'origine ne sont nulle part — et les restaurer voudrait dire réintroduire
     * un vocabulaire que la lecture masquait déjà depuis la story 42.3. Une
     * réversibilité qui remettrait de la donnée sale serait une régression
     * déguisée en prudence.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_roles');
    }

    /**
     * Ramène à `member` toute arête dont le rôle sort du vocabulaire historique.
     *
     * Idempotente (une seconde exécution ne trouve plus rien) et COMPTÉE : le
     * nombre de lignes touchées est journalisé, parce qu'une reprise de données
     * silencieuse est exactement le genre d'écriture qu'on ne peut plus expliquer
     * six mois plus tard.
     */
    private function normalizeOutOfVocabularyEdges(): void
    {
        if (! Schema::hasTable('user_group_user') || ! Schema::hasColumn('user_group_user', 'role')) {
            return;
        }

        $affected = DB::table('user_group_user')
            ->where(function ($query) {
                $query->whereNull('role')->orWhereNotIn('role', self::HISTORICAL_KEYS);
            })
            ->update(['role' => 'member']);

        if ($affected > 0) {
            Log::info('[62.1] Arêtes normalisées vers « member » (rôle hors vocabulaire).', [
                'affected' => $affected,
            ]);
        }
    }
};
