<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FileBackendName;
use App\Exceptions\Filesystem\UnknownFileBackendException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Story 34.1 — répertoire réseau nommé (« lecteur réseau géré »).
 *
 * Fondation backend du module : persistance d'un répertoire matérialisé sous
 * `/var/sambaedu/Partages/<directory_name>` ({@see App\Services\Filesystem\NetworkShareService})
 * et projeté en montage de lecteur via le canal `drives` natif de l'agent
 * ({@see App\Services\Agent\Providers\DrivesStateProvider}).
 *
 * **Modèle d'accès à DEUX axes orthogonaux** (décision Henri 2026-06-29) sur le
 * MÊME jeu d'assignations polymorphes (`network_share_assignables`) :
 *  - **Visibilité (montage)** : N'IMPORTE QUELLE maille (`User` / `UserGroup` /
 *    `WorkstationGroup`) fait apparaître la lettre — l'union/dédup/précédence du
 *    `StateCompiler` gère tout (zéro modif compilateur).
 *  - **ACL POSIX (RO/RW réel)** : dérivée des seules assignations `User` /
 *    `UserGroup` (`user:<login>` / `group:<unix>`). Une assignation
 *    `WorkstationGroup` est MONTAGE-SEUL (aucune ACL — invariant).
 *
 * **Story 60.3 — `backend` : l'autorité d'écriture des droits.** Le partage porte
 * le NOM du backend qui écrit ses droits (`posix` par défaut — c'est ce que sont
 * tous les partages en place). La colonne est VISIBLE (elle détermine le chemin
 * d'accès de l'utilisateur) mais n'est ni `$fillable`, ni éditable depuis l'UI :
 * tant qu'aucun flux de provisioning ne route par elle, un sélecteur serait une
 * propriété qui ment. Routage et éditabilité arrivent ensemble en 60.4.
 *
 * @property int $id
 * @property string $name
 * @property string $directory_name
 * @property FileBackendName $backend
 * @property string|null $label
 * @property string|null $description
 * @property string|null $letter
 * @property int|null $created_by_user_id
 * @property \DateTime|null $created_at
 * @property \DateTime|null $updated_at
 */
class NetworkShare extends Model
{
    use HasFactory;

    /**
     * Identifiant FIGÉ du type de ressource desired-state (contrat §7, NFR12) —
     * iso `Shortcut::TYPE_SHORTCUTS`, consommé par {@see DrivesStateProvider}.
     * Le répertoire réseau se projette dans le type `drives` DÉJÀ figé (l'agent
     * monte n'importe quelle lettre→UNC sans modification).
     */
    public const TYPE_DRIVES = 'drives';

    /**
     * Types polymorphes autorisés sur le pivot (validés applicativement, calqué
     * sur `shortcut_assignables`). FQCN stockés en clair (pas de morph map).
     */
    public const ALLOWED_ASSIGNABLE_TYPES = [
        User::class,
        UserGroup::class,
        WorkstationGroup::class,
    ];

    protected $table = 'network_shares';

    /**
     * `backend` en est VOLONTAIREMENT ABSENT (story 60.3) : aucun chemin
     * d'écriture de masse latent tant que rien ne route par cette colonne. La
     * retirer d'ici est ce qui empêche un `create([...])` ou un `fill()` de faire
     * entrer, par inadvertance, une autorité d'écriture que personne n'honore.
     */
    protected $fillable = [
        'name',
        'directory_name',
        'label',
        'description',
        'letter',
        'created_by_user_id',
    ];

    /**
     * Story 60.3 — le nom de backend est du VOCABULAIRE, pas une chaîne libre.
     *
     * Le cast sert la lecture ordinaire. Le chemin SANCTIONNÉ, celui qui échoue en
     * nommant ce qui était attendu, est {@see backendName()} : c'est lui qu'appelle
     * la résolution et c'est lui qu'appelle l'affichage.
     */
    protected $casts = [
        'backend' => FileBackendName::class,
    ];

    /**
     * Story 60.3 — le défaut du MODÈLE recopie le défaut du SCHÉMA.
     *
     * Sans lui, une instance fraîchement créée n'aurait pas d'autorité d'écriture
     * en mémoire tant qu'elle n'a pas été relue, et {@see backendName()} devrait
     * choisir entre échouer sur un cas parfaitement normal ou se rabattre en
     * silence sur un défaut — les deux mauvais. Recopier le défaut le supprime.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'backend' => 'posix',
    ];

    /**
     * Toutes les lignes d'assignation (pivot polymorphe brut) — itérable côté
     * provisioning pour dériver les ACLs (avec leur `access`).
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(NetworkShareAssignable::class, 'network_share_id');
    }

    /**
     * Utilisateurs assignés (porte le pivot `access`). Maille `User`.
     */
    public function users(): MorphToMany
    {
        return $this->morphedByMany(
            User::class,
            'assignable',
            'network_share_assignables',
            'network_share_id',
            'assignable_id',
        )->withPivot('access')->withTimestamps();
    }

    /**
     * Groupes d'utilisateurs assignés (porte le pivot `access`). Maille
     * `UserGroup`.
     */
    public function userGroups(): MorphToMany
    {
        return $this->morphedByMany(
            UserGroup::class,
            'assignable',
            'network_share_assignables',
            'network_share_id',
            'assignable_id',
        )->withPivot('access')->withTimestamps();
    }

    /**
     * Parcs/salles assignés (MONTAGE-SEUL — `access` ignoré pour l'ACL, la
     * lettre s'affiche mais l'accès réel vient des grants user/group).
     */
    public function workstationGroups(): MorphToMany
    {
        return $this->morphedByMany(
            WorkstationGroup::class,
            'assignable',
            'network_share_assignables',
            'network_share_id',
            'assignable_id',
        )->withPivot('access')->withTimestamps();
    }

    /**
     * Story 60.3 — l'AUTORITÉ D'ÉCRITURE de ce partage, en vocabulaire fermé.
     *
     * **Le chemin sanctionné de lecture de la colonne.** Il lit la valeur BRUTE
     * (avant cast) pour pouvoir échouer en nommant ce qui était attendu, plutôt
     * que sur l'erreur générique du moteur de casts. Une valeur hors vocabulaire
     * ne se rabat JAMAIS sur un défaut : provisionner au hasard un partage dont
     * l'autorité est illisible est exactement ce qu'il faut empêcher.
     *
     * @throws UnknownFileBackendException valeur hors vocabulaire, ou colonne non chargée
     */
    public function backendName(): FileBackendName
    {
        $raw = $this->getAttributes()['backend'] ?? null;

        if ($raw instanceof FileBackendName) {
            return $raw;
        }

        $name = is_string($raw) ? FileBackendName::tryFrom($raw) : null;

        if ($name === null) {
            throw UnknownFileBackendException::unknownValue($raw);
        }

        return $name;
    }

    /**
     * Libellé EFFECTIF du lecteur dans l'explorateur : `label` si défini, sinon
     * le `name` (AC1/AC2).
     */
    public function effectiveLabel(): string
    {
        $label = $this->label;

        return ($label === null || $label === '') ? (string) $this->name : $label;
    }
}
