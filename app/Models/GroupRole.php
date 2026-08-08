<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Pivot\UserGroupUserPivot;
use App\Support\RoleCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Story 62.1 — UNE LIGNE DU CATALOGUE DE RÔLES : une clé immuable, un libellé
 * modifiable, un rang d'affichage.
 *
 * La clé est ce qui est STOCKÉ sur l'arête (`user_group_user.role`) et ce que les
 * recettes visent (`roles_spec[].resolution.edge_roles`, `nodes_spec[].edge_role`).
 * Le libellé est ce qui est LU à l'écran. Séparer les deux est tout l'objet de
 * cette table : renommer « Gestionnaire » en « Encadrant » ne doit toucher aucune
 * donnée dérivée — ni une arête, ni une recette, ni un plan de fichiers résolu.
 *
 * **La clé ne se modifie jamais.** Ce n'est pas une préférence de style : la
 * changer réécrirait silencieusement le sens de toutes les arêtes qui la portent
 * déjà, et de toutes les recettes qui la visent. Renommer se fait par le LIBELLÉ ;
 * changer de vocabulaire se fait en créant un rôle et en migrant les arêtes.
 *
 * **Le renommage des trois valeurs historiques a été EXAMINÉ et ÉCARTÉ** (story
 * 60.2, décision reportée ici avec la classe qu'elle documentait) : le rôle
 * d'arête n'est PAS un niveau d'accès. Nos propres recettes donnent l'écriture à
 * des `member`, et « contributeur »/« lecteur » existent déjà sous le nom d'accès
 * (`ro|rw`), qui est l'autre côté du mappage. Confondre les deux ferait croire
 * qu'un rôle d'arête détermine un droit, alors qu'il ne fait que qualifier une
 * appartenance.
 *
 * @property int $id
 * @property string $key
 * @property string $label
 * @property int $sort_order
 */
class GroupRole extends Model
{
    protected $table = 'group_roles';

    /** @var list<string> */
    protected $fillable = ['key', 'label', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /**
     * Longueur MAXIMALE d'une clé.
     *
     * Elle est écrite telle quelle dans `user_group_user.role`, un `string(20)`
     * depuis la story 42.1. SQLite ne borne pas les varchar : sans cette garde, une
     * clé de 30 caractères passerait en test et lèverait un 22001 en production, à
     * l'écriture d'une arête.
     */
    public const KEY_MAX_LENGTH = 20;

    /** Motif d'une clé : slug snake_case, jamais un jeton réservé (`@member`…). */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * Les trois clés STRUCTURELLES, non supprimables même sans usage.
     *
     * Elles sont écrites EN LITTÉRAL par du code vivant : la dérivation au
     * rattachement ({@see \App\Models\Pivot\UserGroupUserPivot::defaultRoleForGlobalRole()}),
     * la garde « professeur principal ⇒ classe », la projection d'annuaire `PP_`,
     * et les recettes seedées. Les supprimer ne casserait pas une contrainte : ça
     * casserait du code qui ne demande rien à la base.
     *
     * @var list<string>
     */
    public const PROTECTED_KEYS = [
        UserGroupUserPivot::ROLE_MEMBER,
        UserGroupUserPivot::ROLE_MANAGER,
        UserGroupUserPivot::ROLE_OWNER,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $role): void {
            $role->assertKeyIsWellFormed();
            $role->assertKeyIsImmutable();
        });

        // La lecture du catalogue est MÉMOÏSÉE : toute écriture doit la périmer,
        // sinon l'écran qui vient de créer « tuteur » continue de refuser la clé.
        static::saved(fn () => RoleCatalog::flush());
        static::deleted(fn () => RoleCatalog::flush());
    }

    /** Slug snake_case borné, dérivé d'un libellé saisi (patron `WorkstationGroup`). */
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
                'Clé de rôle invalide : « %s ». Attendu : un slug en minuscules (lettre, puis lettres, chiffres ou « _ »).',
                $key,
            ));
        }

        if (strlen($key) > self::KEY_MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Clé de rôle trop longue : « %s » (%d caractères, maximum %d — c\'est la borne de la colonne d\'arête).',
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
            'La clé d\'un rôle est immuable : « %s » ne peut pas devenir « %s ». Les arêtes et les recettes '
            . 'qui la portent la référencent par cette valeur ; seul le libellé se modifie.',
            (string) $this->getOriginal('key'),
            (string) $this->key,
        ));
    }

    /** `true` si ce rôle fait partie des trois clés structurelles. */
    public function isProtected(): bool
    {
        return in_array((string) $this->key, self::PROTECTED_KEYS, true);
    }

    /**
     * Story 62.1 — les USAGES d'une clé de rôle, comptés à la demande.
     *
     *  - `edges`       : arêtes d'appartenance qui portent cette valeur ;
     *  - `templates`   : recettes de répertoire qui VISENT cette clé ;
     *  - `group_types` : types de groupes DISTINCTS observés sur ces arêtes.
     *
     * Le dernier est une lecture d'USAGE, pas une déclaration : rien ne dit
     * aujourd'hui qu'un rôle « s'applique » à un type de groupe. La story 62.3
     * apportera la déclaration ; d'ici là, cette colonne répond à la seule
     * question qu'on peut honnêtement poser à la base — « où ce rôle sert-il,
     * en fait ? ».
     *
     * @return array{edges: int, templates: int, group_types: int}
     */
    public function usage(): array
    {
        $key = (string) $this->key;

        return [
            'edges' => self::countEdges($key),
            'templates' => self::countTemplates($key),
            'group_types' => self::countGroupTypes($key),
        ];
    }

    /** Arêtes `user_group_user` qui portent cette clé. */
    public static function countEdges(string $key): int
    {
        if (! Schema::hasTable('user_group_user')) {
            return 0;
        }

        return DB::table('user_group_user')->where('role', $key)->count();
    }

    /**
     * Types de groupes DISTINCTS portant au moins une arête de cette clé.
     *
     * Un groupe sans type (`null`) n'est pas un type : il ne compte pas.
     */
    public static function countGroupTypes(string $key): int
    {
        if (! Schema::hasTable('user_group_user') || ! Schema::hasTable('user_groups')) {
            return 0;
        }

        return DB::table('user_group_user')
            ->join('user_groups', 'user_groups.id', '=', 'user_group_user.user_group_id')
            ->where('user_group_user.role', $key)
            ->whereNotNull('user_groups.type')
            ->distinct()
            ->count('user_groups.type');
    }

    /**
     * Recettes de répertoire qui VISENT cette clé de rôle d'arête.
     *
     * DEUX formes existent en base, et il faut les deux :
     *  - `roles_spec[].resolution.edge_roles` — l'audience d'un rôle de recette
     *    résolu par rôle d'arête ;
     *  - `nodes_spec[].edge_role` — le rôle d'arête qui peuple un nœud par membre.
     *
     * Les CLÉS LOCALES de `roles_spec` (`profs`, `eleves`, `equipe`, `classe`…)
     * ne référencent PAS ce catalogue en 62.1 : elles restent locales à chaque
     * recette (l'éditeur 62.6 les fera converger). Une homonymie est donc
     * possible — un futur rôle `classe` face à la clé locale `classe` — et les
     * compter serait un faux positif qui bloquerait une suppression légitime.
     *
     * Scan PHP plutôt que SQL : cinq lignes en base, un JSON imbriqué, et deux
     * moteurs (SQLite en test, PostgreSQL en prod) dont les opérateurs JSON ne
     * s'écrivent pas pareil. Une requête portable coûterait plus qu'elle
     * n'économise.
     */
    public static function countTemplates(string $key): int
    {
        if (! Schema::hasTable('directory_templates')) {
            return 0;
        }

        $count = 0;

        foreach (DirectoryTemplate::all() as $template) {
            if (self::templateReferences($template, $key)) {
                $count++;
            }
        }

        return $count;
    }

    private static function templateReferences(DirectoryTemplate $template, string $key): bool
    {
        foreach ($template->roles_spec ?? [] as $role) {
            $edgeRoles = is_array($role) ? ($role['resolution']['edge_roles'] ?? null) : null;
            if (is_array($edgeRoles) && in_array($key, $edgeRoles, true)) {
                return true;
            }
        }

        foreach ($template->nodes_spec ?? [] as $node) {
            if (is_array($node) && ($node['edge_role'] ?? null) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Story 62.1 — le REFUS de suppression, nommé, ou `null` si la suppression
     * est légitime.
     *
     * Deux motifs, deux messages, et JAMAIS de cascade : supprimer un rôle porté
     * par des arêtes reviendrait à réécrire silencieusement l'appartenance de
     * gens réels. On refuse et on dit pourquoi — ce qu'une contrainte `RESTRICT`
     * ne saurait pas formuler, et c'est précisément pour cela qu'aucune clé
     * étrangère n'est posée sur le pivot.
     */
    public function deletionRefusal(): ?string
    {
        if ($this->isProtected()) {
            return sprintf(
                'Refusé : « %s » est un rôle structurel. Sa clé « %s » est écrite en toutes lettres dans le code '
                . 'de SE5 (rattachement d\'un enseignant, professeur principal, projection d\'annuaire, recettes '
                . 'de répertoire) — le supprimer casserait ces chemins sans qu\'aucune donnée ne s\'y oppose. '
                . 'Son libellé, lui, se modifie.',
                (string) $this->label,
                (string) $this->key,
            );
        }

        $usage = $this->usage();

        if ($usage['edges'] === 0 && $usage['templates'] === 0) {
            return null;
        }

        $parts = [];
        if ($usage['edges'] > 0) {
            $parts[] = $usage['edges'] . ' ' . ($usage['edges'] > 1 ? 'appartenances' : 'appartenance');
        }
        if ($usage['templates'] > 0) {
            $parts[] = $usage['templates'] . ' ' . ($usage['templates'] > 1 ? 'recettes' : 'recette');
        }

        return sprintf(
            'Refusé : %s %s ce rôle. Aucune donnée n\'a été modifiée — retirez d\'abord ces usages.',
            implode(' et ', $parts),
            count($parts) > 1 || $usage['edges'] > 1 || $usage['templates'] > 1 ? 'portent' : 'porte',
        );
    }
}
