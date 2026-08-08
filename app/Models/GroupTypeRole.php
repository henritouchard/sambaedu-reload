<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RoleCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Story 62.3 — UNE DÉCLARATION : « ce rôle a un sens dans ce type de groupe, et
 * voici comment il s'y dit ».
 *
 * C'est l'ARÊTE entre les deux catalogues livrés par 62.1 (les rôles) et 62.2
 * (les types). Elle n'invente aucun vocabulaire : elle relie deux clés existantes
 * et leur attache un libellé LOCAL optionnel.
 *
 * **La paire est la donnée ; le libellé est un attribut.** `label` est
 * NULLABLE, et `null` ne veut pas dire « pas de libellé » mais « pas de
 * surcharge » : le libellé du catalogue de rôles s'applique. C'est la différence
 * entre une DÉCLARATION (quels rôles ont un sens ici) et une SURCHARGE (comment
 * l'un d'eux se dit ici) — la constante défunte de `RoleCatalog` ne portait que la
 * seconde, et la confondre avec la première rendrait `member` inattribuable dans
 * tout type qui ne le renomme pas.
 *
 * **La paire est IMMUABLE.** On ne « déplace » pas une déclaration d'un type à un
 * autre ni d'un rôle à un autre : ce serait réécrire silencieusement ce qui est
 * attribuable dans deux types à la fois. On retire et on déclare — et le retrait,
 * lui, est REFUSÉ tant que des appartenances portent la paire.
 *
 * **Ce que ce modèle ne garde PAS.** Il ne borne pas `user_group_user.role` : la
 * contrainte d'attribution vit aux points d'étranglement HUMAINS
 * ({@see \App\Support\RoleCatalog::assertAssignable()}), jamais sur le pivot ni
 * sur un événement Eloquent. Le balayage d'annuaire, l'import d'utilisateurs, le
 * fold legacy et des dizaines de tests attachent en direct, et l'annuaire reste
 * autoritaire sur son propre flux — c'est exactement le précédent posé par 62.2,
 * qui a mis sa garde de vocabulaire au service et pas sur `UserGroup`.
 *
 * @property int $id
 * @property string $group_type_key
 * @property string $group_role_key
 * @property string|null $label
 */
class GroupTypeRole extends Model
{
    protected $table = 'group_type_roles';

    /** @var list<string> */
    protected $fillable = ['group_type_key', 'group_role_key', 'label'];

    protected static function booted(): void
    {
        static::saving(function (self $declaration): void {
            $declaration->normalizeLabel();
            $declaration->assertPairIsImmutable();
            $declaration->assertRoleKeyIsKnown();
            $declaration->assertTypeKeyIsKnown();
        });

        // La résolution des libellés est MÉMOÏSÉE dans `RoleCatalog` : toute
        // écriture doit la périmer, sinon l'écran qui vient de déclarer
        // « Tuteur » sur les projets continue de lire l'ancienne carte. La mémo
        // des déclarations vit dans `RoleCatalog` et son `flush()` vide les DEUX
        // mémos — c'est ce qui fait hériter gratuitement du `Queue::before` de
        // `AppServiceProvider` (review 62.1 #1) et du `setUp()` des tests, sans
        // y ajouter un troisième flush à tenir à jour.
        static::saved(fn () => RoleCatalog::flush());
        static::deleted(fn () => RoleCatalog::flush());
    }

    /** Un libellé local trimé ; une chaîne vide EST une absence de surcharge. */
    private function normalizeLabel(): void
    {
        $label = $this->label === null ? null : trim((string) $this->label);

        $this->label = ($label === null || $label === '') ? null : $label;
    }

    private function assertPairIsImmutable(): void
    {
        if (! $this->exists) {
            return;
        }

        if (! $this->isDirty('group_type_key') && ! $this->isDirty('group_role_key')) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'La paire (type, rôle) d\'une déclaration est immuable : « %s × %s » ne peut pas devenir '
            . '« %s × %s ». Retirez la déclaration et déclarez l\'autre ; seul le libellé local se modifie.',
            (string) $this->getOriginal('group_type_key'),
            (string) $this->getOriginal('group_role_key'),
            (string) $this->group_type_key,
            (string) $this->group_role_key,
        ));
    }

    /**
     * Le rôle déclaré appartient au catalogue de rôles — comparaison EXACTE.
     *
     * Les clés de RÔLE, elles, SONT des slugs : {@see GroupRole::KEY_PATTERN} les
     * y contraint depuis 62.1, et le plancher historique ne contient que
     * `member|manager|owner`. Aucune valeur héritée exotique n'existe de ce
     * côté-là de l'arête.
     */
    private function assertRoleKeyIsKnown(): void
    {
        $key = (string) $this->group_role_key;
        $known = RoleCatalog::keys();

        if (! in_array($key, $known, true)) {
            throw new InvalidArgumentException(sprintf(
                'Rôle inconnu du catalogue : « %s ». Vocabulaire attendu : %s.',
                $key,
                implode('|', $known),
            ));
        }
    }

    /**
     * Le type déclarant existe au catalogue de types — comparaison EXACTE, et
     * AUCUNE garde de format.
     *
     * Review 62.2 #1 — les clés de type héritées ne sont PAS des slugs :
     * `Custom`, `class` sont des lignes légitimes de `group_types`, découvertes
     * en base par la migration 62.2, et on s'est justement interdit de les
     * renormaliser. Une déclaration doit pouvoir s'y accrocher. On vérifie donc
     * l'EXISTENCE de la clé, jamais sa forme — la ligne se crée depuis la ligne
     * du catalogue, et c'est sa valeur stockée qui fait référence.
     */
    private function assertTypeKeyIsKnown(): void
    {
        $key = (string) $this->group_type_key;

        if (trim($key) === '') {
            throw new InvalidArgumentException(
                'Type de groupe manquant : une déclaration s\'accroche toujours à une ligne du catalogue de types.',
            );
        }

        if (! Schema::hasTable('group_types')) {
            // Schéma incomplet (migration en cours, test unitaire sur schéma
            // fabriqué) : il n'y a rien à vérifier contre, et refuser ici
            // bloquerait un chemin qui n'a rien fait de mal.
            return;
        }

        if (! DB::table('group_types')->where('key', $key)->exists()) {
            throw new InvalidArgumentException(sprintf(
                'Type de groupe inconnu du catalogue : « %s ». Créez-le d\'abord dans l\'onglet '
                . '« Types de groupes ».',
                $key,
            ));
        }
    }

    /**
     * Story 62.3 — le REFUS de retrait, nommé et chiffré, ou `null` si le retrait
     * est légitime.
     *
     * Retirer `manager` de `classe` alors que des enseignants sont `manager` dans
     * des classes ne peut pas être silencieux : ou bien on réécrirait
     * l'appartenance de gens réels, ou bien on laisserait des arêtes porter un
     * rôle que leur type ne reconnaît plus. On refuse, on DIT combien, et on
     * n'écrit RIEN — jamais de cascade. C'est le patron
     * {@see GroupRole::deletionRefusal()} / {@see GroupType::deletionRefusal()}.
     */
    public function removalRefusal(): ?string
    {
        $count = self::countEdges((string) $this->group_type_key, (string) $this->group_role_key);

        if ($count === 0) {
            return null;
        }

        return sprintf(
            'Refusé : %d appartenance%s porte%s le rôle « %s » dans des groupes de type « %s ». '
            . 'Aucune donnée n\'a été modifiée — changez d\'abord ces rôles.',
            $count,
            $count > 1 ? 's' : '',
            $count > 1 ? 'nt' : '',
            RoleCatalog::label((string) $this->group_type_key, (string) $this->group_role_key),
            (string) $this->group_type_key,
        );
    }

    /**
     * Appartenances portant ce rôle dans un groupe de ce type — comparaison de
     * type INSENSIBLE À LA CASSE.
     *
     * L'asymétrie avec {@see GroupType::countGroups()} (exact) est la même que
     * celle de {@see GroupType::countTemplates()}, et pour la même raison : **on
     * compte comme la RÉSOLUTION apparie**. `RoleCatalog::label()` abaisse et
     * trime le type entrant depuis la story 60.2 ; un groupe stocké `Classe`
     * lit donc bien les libellés déclarés sur `classe`, et son appartenance
     * `manager` DOIT compter quand on retire cette déclaration. Compter en exact
     * ici laisserait passer un retrait qui casse un affichage réel.
     *
     * La clé de RÔLE, elle, compare en exact : c'est un slug, jamais une valeur
     * de casse libre.
     */
    public static function countEdges(string $typeKey, string $roleKey): int
    {
        if (! Schema::hasTable('user_group_user') || ! Schema::hasTable('user_groups')) {
            return 0;
        }

        return DB::table('user_group_user')
            ->join('user_groups', 'user_groups.id', '=', 'user_group_user.user_group_id')
            ->where('user_group_user.role', $roleKey)
            ->whereRaw('LOWER(user_groups.type) = ?', [mb_strtolower(trim($typeKey))])
            ->count();
    }

    /**
     * Les déclarations d'un type, indexées par clé de rôle — accès EXACT sur la
     * clé stockée.
     *
     * C'est la lecture de l'ÉCRAN d'édition d'un type : elle porte sur la ligne
     * qu'on édite, pas sur ce que la résolution apparierait. Une ligne `Custom`
     * ne se voit pas attribuer les déclarations de `custom`.
     *
     * @return array<string, ?string> clé de rôle => libellé local (ou `null`)
     */
    public static function declaredFor(string $typeKey): array
    {
        if (! Schema::hasTable('group_type_roles')) {
            return [];
        }

        $declared = [];

        foreach (self::where('group_type_key', $typeKey)->get() as $row) {
            $declared[(string) $row->group_role_key] = $row->label === null ? null : (string) $row->label;
        }

        return $declared;
    }
}
