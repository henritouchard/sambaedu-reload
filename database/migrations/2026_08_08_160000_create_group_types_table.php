<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Story 62.2 — LE CATALOGUE DE TYPES DE GROUPES devient un objet de premier
 * niveau.
 *
 * **Pourquoi une table.** `user_groups.type` est un `string(50)` NOT NULL depuis
 * la story 4.x — et une CHAÎNE LIBRE : rien, nulle part, n'a jamais borné son
 * vocabulaire. `UserGroupService::validateData()` n'exigeait qu'un non-vide ≤ 50 ;
 * les `<select>` des deux formulaires bornaient de fait, mais le balayage AD
 * écrivait ce que sa détection de préfixe produisait, et une migration de reprise
 * de 2026-03 y a versé deux valeurs de plus. Résultat : une colonne référencée par
 * `directory_templates.attached_group_type` (story 60.2) sans qu'il existe aucune
 * table à référencer — le docblock de cette migration-là l'écrivait noir sur
 * blanc (« Pas de clé étrangère : il n'existe pas de table des types »). Cette
 * phrase cesse d'être vraie ici.
 *
 * **Pourquoi la reprise est en DEUX temps — et pourquoi le second est
 * indispensable.** Le premier temps pose les NEUF valeurs recensées site par site
 * dans le code vivant :
 *
 *  1. `custom`, `classe`, `cours`, `matiere`, `matiere_classe`, `projet`, `equipe`
 *     — les `<option>` du formulaire de création de groupe et de l'édition SQL,
 *     plus le défaut `custom` de {@see \App\Services\UserGroupService} ;
 *  2. `role` et `function` — produits par `detectTypeFromAdGroupName()`
 *     (`MainGroups` / `FunctionGroups`) et par la migration de reprise
 *     `2026_03_31_100000_update_user_group_types_role_function`.
 *
 * Le second temps DÉCOUVRE ce que la base porte réellement
 * (`SELECT DISTINCT type FROM user_groups`). Il n'est pas une prudence de style :
 * la colonne étant libre depuis quatre ans, une instance en place peut porter
 * n'importe quoi — nos propres fixtures de tests portent `class`, `autre`,
 * `admin`. En rater une, ce serait faire disparaître de l'écran un type que des
 * groupes portent, et surtout casser l'appariement d'accrochage : une recette
 * accrochée à une valeur absente du catalogue serait refusée par la garde de
 * {@see \App\Models\DirectoryTemplate} alors qu'elle s'appariait très bien.
 *
 * **Aucune valeur n'est renommée, ni normalisée, ni fusionnée.** `class` découvert
 * reste `class`, à côté de `classe` — deux lignes, deux libellés. Corriger la
 * casse ou l'orthographe serait réécrire silencieusement le sens de groupes
 * réels, et l'appariement d'accrochage se ferait sur une valeur que plus personne
 * ne stocke. La migration LIT `user_groups` ; elle n'y écrit JAMAIS (garde-fou
 * d'epic 62 : « aucune valeur perdue ni renommée »).
 *
 * **Pourquoi `DB::table` et pas le modèle.** {@see \App\Models\GroupType} porte une
 * garde `saving` qui exige un slug snake_case : elle protège les créations
 * APPLICATIVES, pour qu'un libellé saisi ne produise jamais une clé exotique. Une
 * valeur HÉRITÉE, elle, entre telle quelle — même si elle ne ressemble pas à un
 * slug. Le catalogue doit montrer la donnée telle qu'elle est ; la garde ne vaut
 * que pour ce qu'on écrit à partir d'aujourd'hui.
 *
 * **Pourquoi la référence est la CLÉ et pas un id, sans clé étrangère.** Cohérence
 * avec le catalogue de rôles de la story 62.1 et décision D2 de l'epic : une
 * valeur lisible en base vaut mieux qu'une jointure, la clé est immuable par
 * construction, et le refus de suppression est NOMMÉ (« 42 groupes portent ce
 * type ») — ce qu'un `RESTRICT` ne saurait pas formuler.
 *
 * Idempotente : rejouable sans doublon (chaque insertion est précédée d'un test
 * d'existence sur `key`).
 */
return new class extends Migration
{
    /**
     * Les NEUF clés statiques, dans l'ordre des `<select>` historiques (parité
     * d'ordre : le picker ne doit pas se réordonner sous les doigts des
     * utilisateurs). Les libellés sont ceux de la forme la plus RICHE des `match`
     * d'affichage qui meurent avec cette story — celui de la fiche utilisateur,
     * seul à connaître « Rôle » et « Fonction ».
     *
     * @var list<array{key: string, label: string, icon: string, sort_order: int}>
     */
    private const STATIC_TYPES = [
        ['key' => 'custom', 'label' => 'Personnalisé', 'icon' => 'fa-solid fa-users', 'sort_order' => 1],
        ['key' => 'classe', 'label' => 'Classe', 'icon' => 'fa-solid fa-graduation-cap', 'sort_order' => 2],
        ['key' => 'cours', 'label' => 'Cours', 'icon' => 'fa-solid fa-book-open', 'sort_order' => 3],
        ['key' => 'matiere', 'label' => 'Matière', 'icon' => 'fa-solid fa-book', 'sort_order' => 4],
        ['key' => 'matiere_classe', 'label' => 'Matière / Classe', 'icon' => 'fa-solid fa-book-bookmark', 'sort_order' => 5],
        ['key' => 'projet', 'label' => 'Projet', 'icon' => 'fa-solid fa-diagram-project', 'sort_order' => 6],
        ['key' => 'equipe', 'label' => 'Équipe', 'icon' => 'fa-solid fa-people-group', 'sort_order' => 7],
        ['key' => 'role', 'label' => 'Rôle', 'icon' => 'fa-solid fa-id-badge', 'sort_order' => 8],
        ['key' => 'function', 'label' => 'Fonction', 'icon' => 'fa-solid fa-briefcase', 'sort_order' => 9],
    ];

    /** Borne de `user_groups.type` : une clé plus longue ne pourrait pas être portée. */
    private const KEY_MAX_LENGTH = 50;

    public function up(): void
    {
        if (! Schema::hasTable('group_types')) {
            Schema::create('group_types', function (Blueprint $table) {
                $table->id();
                // Clé IMMUABLE : c'est la valeur STOCKÉE dans `user_groups.type`
                // et visée par `directory_templates.attached_group_type`. 50 =
                // la borne de la colonne portée. Unique : deux types ne peuvent
                // pas se disputer la même valeur.
                $table->string('key', self::KEY_MAX_LENGTH)->unique();
                $table->string('label');
                // Classe Font Awesome, saisie librement. `null` est légitime :
                // les valeurs découvertes n'en ont pas, et la lecture retombe sur
                // une icône générique.
                $table->string('icon')->nullable();
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        $this->seedStaticTypes();
        $this->discoverStoredTypes();
    }

    /**
     * Le `down()` retire la TABLE, et rien d'autre.
     *
     * Il n'a rien à défaire par ailleurs : cette migration n'a écrit AUCUNE ligne
     * hors de `group_types`. C'est précisément l'invariant qu'elle revendique.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_types');
    }

    /** Les neuf lignes recensées, insérées si absentes (jamais écrasées). */
    private function seedStaticTypes(): void
    {
        $now = now();

        foreach (self::STATIC_TYPES as $type) {
            if (DB::table('group_types')->where('key', $type['key'])->exists()) {
                continue;
            }

            DB::table('group_types')->insert($type + ['created_at' => $now, 'updated_at' => $now]);
        }
    }

    /**
     * La DÉCOUVERTE : une ligne par valeur réellement stockée et absente du
     * catalogue, à sa valeur EXACTE.
     *
     * Le libellé de secours est `ucfirst()` — exactement ce que les `match`
     * d'affichage rendaient pour une valeur qu'ils ne connaissaient pas. L'admin
     * retrouve donc à l'écran des types ce qu'il voyait déjà sur les fiches, et il
     * peut désormais les renommer.
     */
    private function discoverStoredTypes(): void
    {
        if (! Schema::hasTable('user_groups')) {
            return;
        }

        $known = DB::table('group_types')->pluck('key')->all();
        $known = array_flip(array_map('strval', $known));

        $nextOrder = (int) (DB::table('group_types')->max('sort_order') ?? 0);
        $now = now();
        $discovered = [];

        foreach (DB::table('user_groups')->distinct()->pluck('type') as $raw) {
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }

            if (array_key_exists($raw, $known)) {
                continue;
            }

            if (mb_strlen($raw) > self::KEY_MAX_LENGTH) {
                // Impossible en PostgreSQL (la colonne source est bornée à 50),
                // possible en SQLite qui ne borne pas les varchar. On refuse
                // plutôt que de tronquer : tronquer serait RENOMMER.
                $this->warn(sprintf(
                    '[62.2] Type de groupe « %s » trop long (%d > %d) : non catalogué.',
                    $raw,
                    mb_strlen($raw),
                    self::KEY_MAX_LENGTH,
                ));

                continue;
            }

            $known[$raw] = true;
            $discovered[] = [
                'key' => $raw,
                // `ucfirst` et pas `Str::title` : c'est le repli EXACT des `match`
                // d'affichage remplacés par cette story. La parité d'écran prime
                // sur l'élégance typographique.
                'label' => ucfirst($raw),
                'icon' => null,
                'sort_order' => ++$nextOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($discovered === []) {
            return;
        }

        DB::table('group_types')->insert($discovered);

        $this->warn(sprintf(
            '[62.2] %d type(s) de groupe découvert(s) en base et catalogué(s) à leur valeur exacte : %s.',
            count($discovered),
            implode(', ', array_column($discovered, 'key')),
        ));
    }

    /** Journal gardé : cette migration doit rester traversable sans application bootée. */
    private function warn(string $message): void
    {
        try {
            Log::info($message);
        } catch (\Throwable) {
            // Pas de journal disponible : la migration reste la bonne réponse.
        }
    }
};
