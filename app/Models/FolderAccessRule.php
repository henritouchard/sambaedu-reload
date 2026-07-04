<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Story 36.4 — Règle d'accès à un dossier (feature à formulaire, D8).
 *
 * SECONDE surface d'authoring du mécanisme `fs_acl` (36.1) : « interdire/autoriser
 * CE dossier à CE groupe ». Une règle ACTIVE assignée à un parc du poste se
 * projette en item `fs_acl` IDENTIQUE à celui d'une capacité (6 clés `{path,
 * trustee, ace_type, rights, applies_to, ensure}`) via
 * {@see \App\Services\Agent\Providers\FolderAccessRulesStateProvider} — l'agent
 * ignore qui l'a produite. Calque STRUCTUREL de {@see NetworkShare} (34.1).
 *
 * **Pas de ciblage par utilisateur** (piège #10 36.1) : « quel utilisateur est
 * bridé » = le `trustee` DÉRIVÉ du groupe (D9), « quels postes » = les parcs
 * assignés. Le mécanisme est de portée MACHINE (service SYSTEM).
 *
 * **Trustee dérivé (D9, piège #4)** : le nom SQL est FOLDÉ au nom nu (mémoire
 * `usergroup_sql_fold_bare_name` : `user_groups.name = '3A'` pour
 * `ad_dn = 'CN=Classe_3A,…'`). Émettre `name` casserait la résolution LSA côté
 * poste. On dérive le CN de `ad_dn` (fallback `name`) — UN seul foyer
 * ({@see deriveTrustee()}), consommé par le provider ET le service/validator.
 *
 * @property int $id
 * @property string $path
 * @property int $user_group_id
 * @property string $ace_type
 * @property string $rights
 * @property string $applies_to
 * @property string $label
 * @property bool $is_active
 * @property int|null $created_by_user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class FolderAccessRule extends Model
{
    use HasFactory;

    /**
     * Types polymorphes autorisés sur le pivot (validés applicativement, calque
     * `NetworkShare::ALLOWED_ASSIGNABLE_TYPES`). v1 : parc SEULEMENT — le
     * mécanisme est machine (un override User/UserGroup serait sans effet, piège
     * #10). Extensible SANS migration.
     *
     * @var list<class-string>
     */
    public const ALLOWED_ASSIGNABLE_TYPES = [
        WorkstationGroup::class,
    ];

    protected $table = 'folder_access_rules';

    protected $fillable = [
        'path',
        'user_group_id',
        'ace_type',
        'rights',
        'applies_to',
        'label',
        'is_active',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Dérivation du trustee à ÉMETTRE au payload (D9, piège #4). Dernier segment
     * `CN=` de `ad_dn` (le CN propre du groupe — leftmost RDN, ex.
     * `CN=Classe_3A,OU=…` → `Classe_3A`) quand `ad_dn` est renseigné ; sinon le
     * `name` verbatim (fallback). Un groupe SANS `ad_dn` déclenche l'avertissement
     * D9 côté formulaire (résolution potentiellement impossible au poste).
     *
     * Foyer UNIQUE : consommé par le provider (à l'émission, par jointure — un
     * rename de groupe suit) ET le validator/service (guard, overlap). Zéro SID
     * en SQL (D5 36.1 : la résolution LSA est côté POSTE).
     */
    public static function deriveTrustee(?string $adDn, string $name): string
    {
        // Correction review #4 : la regex naïve `CN=([^,]+)` tronquait un CN
        // contenant une virgule ÉCHAPPÉE (`\,`, DN RFC 4514 valide — ex.
        // `CN=Salle B\, annexe,OU=Groups`) → trustee irrésoluble côté LSA.
        // Ici la valeur du CN est une suite de caractères non spéciaux
        // (`[^,\\]`) OU de paires échappées (`\\.` : `\,`, `\\`, `\+`…) : une
        // virgule échappée ne termine donc PAS le RDN.
        if ($adDn !== null && preg_match('/(?:^|,)\s*CN=((?:[^,\\\\]|\\\\.)*)/i', $adDn, $m) === 1) {
            // Dé-échappe les séquences `\<char>` (RFC 4514) → caractère littéral
            // (`\,` → `,`, `\\` → `\`).
            $cn = trim((string) preg_replace('/\\\\(.)/', '$1', $m[1]));
            if ($cn !== '') {
                return $cn;
            }
        }

        return $name;
    }

    /**
     * Trustee dérivé pour CETTE règle (à partir du groupe lié). Charge la
     * relation si nécessaire.
     */
    public function trusteeName(): string
    {
        $group = $this->relationLoaded('userGroup') ? $this->userGroup : $this->userGroup()->first();

        return self::deriveTrustee($group?->ad_dn, (string) ($group?->name ?? ''));
    }

    public function userGroup(): BelongsTo
    {
        return $this->belongsTo(UserGroup::class, 'user_group_id');
    }

    /**
     * Toutes les lignes d'assignation (pivot polymorphe brut) — itérable côté
     * service pour l'audit des ids de parcs.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(FolderAccessRuleAssignable::class, 'folder_access_rule_id');
    }

    /**
     * Parcs (WorkstationGroup) assignés — la maille de projection de la règle.
     */
    public function workstationGroups(): MorphToMany
    {
        return $this->morphedByMany(
            WorkstationGroup::class,
            'assignable',
            'folder_access_rule_assignables',
            'folder_access_rule_id',
            'assignable_id',
        )->withTimestamps();
    }

    /**
     * Ids des parcs assignés (pour les snapshots d'audit + les pickers).
     *
     * @return list<int>
     */
    public function assignedWorkstationGroupIds(): array
    {
        return $this->assignments()
            ->where('assignable_type', WorkstationGroup::class)
            ->pluck('assignable_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
