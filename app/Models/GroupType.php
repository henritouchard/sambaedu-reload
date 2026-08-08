<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\GroupTypeCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Story 62.2 — UNE LIGNE DU CATALOGUE DE TYPES DE GROUPES : une clé immuable, un
 * libellé modifiable, une icône, un rang d'affichage.
 *
 * La clé est ce qui est STOCKÉ dans `user_groups.type`, ce que les recettes de
 * répertoire visent (`directory_templates.attached_group_type`) et ce que le code
 * métier compare en littéral (« ce groupe est-il une `classe` ? »). Le libellé est
 * ce qui se LIT à l'écran. Séparer les deux est tout l'objet de cette table :
 * renommer « Classe » en « Division » ne doit toucher aucune donnée dérivée — ni
 * un groupe, ni une recette, ni un plan de fichiers résolu.
 *
 * **La clé ne se modifie jamais.** Ce n'est pas une préférence de style : la
 * changer réécrirait le sens de tous les groupes qui la portent déjà, et casserait
 * l'appariement de toutes les recettes qui la visent — silencieusement, parce
 * qu'un accrochage qui ne s'apparie plus est indiscernable d'une absence
 * légitime. Changer de vocabulaire se fait en créant un type et en migrant les
 * groupes.
 *
 * **Ce que ce modèle ne garde PAS.** Il ne borne pas `user_groups.type` : des
 * dizaines de tests et le balayage AD écrivent `UserGroup` directement, et la
 * frontière de vocabulaire vit au point d'étranglement applicatif
 * ({@see \App\Services\UserGroupService::validateData()}), pas sur une colonne.
 *
 * @property int $id
 * @property string $key
 * @property string $label
 * @property string|null $icon
 * @property int $sort_order
 */
class GroupType extends Model
{
    protected $table = 'group_types';

    /** @var list<string> */
    protected $fillable = ['key', 'label', 'icon', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /**
     * Longueur MAXIMALE d'une clé.
     *
     * Elle est écrite telle quelle dans `user_groups.type`, un `string(50)` depuis
     * la création de la table. SQLite ne borne pas les varchar : sans cette garde,
     * une clé de 60 caractères passerait en test et lèverait un 22001 en
     * PostgreSQL, à la création d'un groupe.
     */
    public const KEY_MAX_LENGTH = 50;

    /** Motif d'une clé : slug snake_case. */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * Les NEUF clés statiques, non supprimables même sans usage.
     *
     * Chacune est écrite EN LITTÉRAL par du code vivant, qui ne demande rien à la
     * base et ne s'apercevrait donc de rien :
     *
     *  - `classe`, `equipe`, `cours`, `projet`, `matiere`, `matiere_classe`,
     *    `role`, `function`, `custom` sont les valeurs de retour de
     *    `UserGroupService::detectTypeFromAdGroupName()` : le prochain balayage AD
     *    les réécrit, qu'elles soient au catalogue ou non ;
     *  - `custom` est le défaut de `validateData()` et des deux formulaires ;
     *  - `classe` et `equipe` sortent du fold des groupes legacy
     *    ({@see \App\Actions\Groups\MergeLegacyUserGroups}) et gardent des
     *    comportements métier entiers (professeur principal, partage de classe) ;
     *  - cinq d'entre elles pilotent `GroupNameNormalizer::TYPE_PREFIXES` et
     *    `UserGroupService::mapTypeToLdap()`.
     *
     * Les supprimer ne casserait aucune contrainte : ça casserait du code.
     *
     * @var list<string>
     */
    public const PROTECTED_KEYS = [
        'custom',
        'classe',
        'cours',
        'matiere',
        'matiere_classe',
        'projet',
        'equipe',
        'role',
        'function',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $type): void {
            $type->assertKeyIsWellFormed();
            $type->assertKeyIsImmutable();
        });

        // La lecture du catalogue est MÉMOÏSÉE : toute écriture doit la périmer,
        // sinon l'écran qui vient de créer « club » continue de refuser la clé.
        static::saved(fn () => GroupTypeCatalog::flush());
        static::deleted(fn () => GroupTypeCatalog::flush());
    }

    /** Slug snake_case borné, dérivé d'un libellé saisi (patron `GroupRole`). */
    public static function slugify(string $label): string
    {
        $slug = trim(Str::slug($label, '_'), '_');

        // Une clé commence par une lettre : un slug qui débute par un chiffre (ou
        // qui est vide) n'est pas une clé, c'est un accident de saisie.
        $slug = ltrim($slug, '0123456789_');

        return substr($slug, 0, self::KEY_MAX_LENGTH);
    }

    private function assertKeyIsWellFormed(): void
    {
        $key = (string) $this->key;

        if ($key === '' || preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Clé de type de groupe invalide : « %s ». Attendu : un slug en minuscules (lettre, puis lettres, chiffres ou « _ »).',
                $key,
            ));
        }

        if (strlen($key) > self::KEY_MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Clé de type de groupe trop longue : « %s » (%d caractères, maximum %d — c\'est la borne de « user_groups.type »).',
                $key,
                strlen($key),
                self::KEY_MAX_LENGTH,
            ));
        }
    }

    private function assertKeyIsImmutable(): void
    {
        if (! $this->exists || ! $this->isDirty('key')) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'La clé d\'un type de groupe est immuable : « %s » ne peut pas devenir « %s ». Les groupes qui la '
            . 'portent et les arborescences qui s\'y accrochent la référencent par cette valeur ; seul le '
            . 'libellé se modifie.',
            (string) $this->getOriginal('key'),
            (string) $this->key,
        ));
    }

    /** `true` si ce type fait partie des neuf clés statiques. */
    public function isProtected(): bool
    {
        return in_array((string) $this->key, self::PROTECTED_KEYS, true);
    }

    /**
     * Story 62.2 — les USAGES d'une clé de type, comptés à la demande.
     *
     *  - `groups`    : groupes d'utilisateurs qui PORTENT cette valeur ;
     *  - `templates` : recettes de répertoire ACCROCHÉES à ce type (arbres et
     *    plates confondues).
     *
     * @return array{groups: int, templates: int}
     */
    public function usage(): array
    {
        $key = (string) $this->key;

        return [
            'groups' => self::countGroups($key),
            'templates' => self::countTemplates($key),
        ];
    }

    /**
     * Groupes portant cette clé — comparaison EXACTE.
     *
     * `user_groups.type` n'a JAMAIS été normalisé : si `classe` et `Classe`
     * coexistent en base, chacun compte les siens et l'écran montre la réalité.
     * Compter en insensible à la casse ici masquerait précisément l'anomalie que
     * la découverte dynamique de la migration rend visible.
     */
    public static function countGroups(string $key): int
    {
        if (! Schema::hasTable('user_groups')) {
            return 0;
        }

        return DB::table('user_groups')->where('type', $key)->count();
    }

    /**
     * Recettes accrochées à ce type — comparaison INSENSIBLE À LA CASSE.
     *
     * L'asymétrie avec `countGroups()` est voulue : `attached_group_type` EST
     * normalisé en minuscules à l'écriture depuis la story 60.5, et
     * `DirectoryTemplate::attachedTo()` compare déjà en `LOWER()`. On compte donc
     * comme la résolution apparie — sinon une recette accrochée compterait pour
     * zéro et la suppression du type passerait.
     */
    public static function countTemplates(string $key): int
    {
        if (! Schema::hasTable('directory_templates')) {
            return 0;
        }

        return DB::table('directory_templates')
            ->whereRaw('LOWER(attached_group_type) = ?', [mb_strtolower($key)])
            ->count();
    }

    /**
     * Ce que les recettes disent de ce type, pour l'écran.
     *
     * **L'invariant vivant n'est PAS « au plus une recette par type ».** L'index
     * unique posé en 60.2 a été délibérément RELÂCHÉ en 60.5
     * (`2026_08_05_110000_relax_attached_group_type_uniqueness`) : l'accrochage y
     * est devenu une propriété d'ÉLIGIBILITÉ que plusieurs recettes peuvent
     * porter sur le même type. Ce qui survit, tenu par la garde applicative
     * {@see DirectoryTemplate::assertSingleTreeAttachment()}, est plus étroit :
     * **un type ne porte qu'une recette d'ARBRE**. Le type `classe` en porte deux
     * dans le seeder livré — l'arbre du partage de classe et la recette plate
     * « profs → élèves ».
     *
     * @return array{tree: ?string, flat: int}
     */
    public function attachment(): array
    {
        if (! Schema::hasTable('directory_templates')) {
            return ['tree' => null, 'flat' => 0];
        }

        $key = (string) $this->key;
        $tree = DirectoryTemplate::attachedTo($key);

        $flat = DB::table('directory_templates')
            ->whereRaw('LOWER(attached_group_type) = ?', [mb_strtolower($key)])
            ->whereNull('path_pattern')
            ->count();

        return [
            'tree' => $tree === null ? null : (string) $tree->key,
            'flat' => $flat,
        ];
    }

    /**
     * Story 62.2 — le REFUS de suppression, nommé, ou `null` si la suppression est
     * légitime.
     *
     * Deux motifs, deux messages, et JAMAIS de cascade : supprimer un type porté
     * par des groupes reviendrait soit à les laisser pointer dans le vide, soit à
     * réécrire leur nature. On refuse et on dit pourquoi — ce qu'un `RESTRICT` ne
     * saurait pas formuler, et c'est précisément pour cela qu'aucune clé étrangère
     * n'est posée.
     */
    public function deletionRefusal(): ?string
    {
        if ($this->isProtected()) {
            return sprintf(
                'Refusé : « %s » est un type structurel. Sa clé « %s » est écrite en toutes lettres dans le code '
                . 'de SE5 (détection des groupes de l\'annuaire au balayage, préfixes de noms, routage LDAP, '
                . 'comportements de classe) — le supprimer ne casserait aucune contrainte, seulement du code, et '
                . 'le prochain balayage AD la réécrirait. Son libellé et son icône, eux, se modifient.',
                (string) $this->label,
                (string) $this->key,
            );
        }

        $usage = $this->usage();

        if ($usage['groups'] === 0 && $usage['templates'] === 0) {
            return null;
        }

        $parts = [];
        if ($usage['groups'] > 0) {
            $parts[] = $usage['groups'] > 1
                ? sprintf('%d groupes portent ce type', $usage['groups'])
                : '1 groupe porte ce type';
        }
        if ($usage['templates'] > 0) {
            $parts[] = $usage['templates'] > 1
                ? sprintf('%d arborescences s\'y accrochent', $usage['templates'])
                : '1 arborescence s\'y accroche';
        }

        return sprintf(
            'Refusé : %s. Aucune donnée n\'a été modifiée — retirez d\'abord ces usages.',
            implode(' et ', $parts),
        );
    }
}
