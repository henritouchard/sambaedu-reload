<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Livewire\Wireable;

/**
 * Modèle Eloquent pour les raccourcis
 * 
 * Utilise la table PostgreSQL 'shortcuts'.
 * Représente un raccourci (bureau, démarrage, barre des tâches).
 * 
 * @property int $id
 * @property string|null $controlhub_id UUID universel généré par le ControlHub
 * @property string $key Clé unique du raccourci
 * @property string $name Nom d'affichage
 * @property string|null $owner Propriétaires (groupes AD, séparés par virgules)
 * @property string $place desktop, startup, taskbar
 * @property bool $is_global Géré par ControlHub
 * @property string|null $windows_link Chemin de l'exécutable Windows
 * @property string|null $windows_args Arguments Windows
 * @property string|null $windows_path Répertoire de travail Windows
 * @property string|null $windows_icon Chemin de l'icône Windows
 * @property string|null $linux_link Chemin de l'exécutable Linux
 * @property string|null $linux_args Arguments Linux
 * @property string|null $linux_path Répertoire de travail Linux
 * @property string|null $linux_icon Chemin de l'icône Linux
 * @property string|null $linux_startupwmclass StartupWMClass Linux
 * @property string|null $icon_path Chemin de l'icône uploadée
 * @property string|null $icon_asset Filename content-addressed `<sha256>.ico` de l'icône uploadée (Story 27.7)
 * @property string|null $icon_checksum SHA-256 hex du `.ico` content-addressed (Story 27.7)
 * @property string|null $category Catégorie du raccourci
 * @property string|null $description Description du raccourci
 * @property bool $is_active Raccourci actif
 * @property bool $is_url Raccourci de type URL
 * @property array|null $metadata Métadonnées supplémentaires
 * @property string|null $windows_workdir Répertoire de travail Windows
 * @property string|null $linux_workdir Répertoire de travail Linux
 * @property bool $is_dynamic Contient des variables dynamiques ($user, $userprofile, etc.)
 * @property array|null $compiled_data Données pré-compilées du raccourci
 * @property \DateTime|null $compiled_at Timestamp de la dernière compilation
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class Shortcut extends Model implements Wireable
{
    /**
     * La table associée au modèle
     */
    protected $table = 'shortcuts';

    /**
     * Les attributs qui peuvent être assignés en masse
     */
    protected $fillable = [
        'controlhub_id',
        'controlhub_version',
        'key',
        'name',
        'owner',
        'place',
        'is_global',
        'windows_link',
        'windows_args',
        'windows_path',
        'windows_icon',
        'linux_link',
        'linux_args',
        'linux_path',
        'linux_icon',
        'linux_startupwmclass',
        'icon_path',
        'icon_asset',
        'icon_checksum',
        'category',
        'description',
        'is_active',
        'is_url',
        'metadata',
        'windows_workdir',
        'linux_workdir',
        'is_dynamic',
        'compiled_data',
        'compiled_at',
        'ad_users',
        'ad_user_groups',
    ];

    /**
     * Les attributs qui doivent être castés
     */
    protected $casts = [
        'controlhub_id' => 'string',
        'controlhub_version' => 'datetime',
        'is_global' => 'boolean',
        'is_active' => 'boolean',
        'is_url' => 'boolean',
        'metadata' => 'array',
        'is_dynamic' => 'boolean',
        'compiled_data' => 'array',
        'compiled_at' => 'datetime',
        'ad_users' => 'array',
        'ad_user_groups' => 'array',
    ];

    /**
     * Identifiant FIGÉ du type de ressource desired-state (contrat §7, NFR12).
     * Story 27.1 — iso `Wallpaper::TYPE_WALLPAPER`, consommé par le
     * `ShortcutsStateProvider`. Jamais renommé une fois publié.
     */
    public const TYPE_SHORTCUTS = 'shortcuts';

    /**
     * Emplacements possibles
     */
    public const PLACE_DESKTOP = 'desktop';
    public const PLACE_STARTUP = 'startup';
    public const PLACE_TASKBAR = 'taskbar';

    /**
     * Variables dynamiques reconnues dans les champs de raccourcis.
     * Ces variables seront substituées au moment du téléchargement par le poste client.
     */
    public const DYNAMIC_VARIABLES = [
        '$user',
        '$userprofile',
        '$HOME',
        '$home',
        '%username%',
        '%userprofile%',
        '%computername%',
        '%temp%',
        '%TEMP%',
    ];


    /**
     * Entrées pré-compilées de ce raccourci
     */
    public function compiledShortcuts(): HasMany
    {
        return $this->hasMany(CompiledShortcut::class);
    }

    /**
     * Groupes de postes (salles, parcs) associés à ce raccourci
     */
    public function workstationGroups(): MorphToMany
    {
        return $this->morphedByMany(
            WorkstationGroup::class,
            'assignable',
            'shortcut_assignables',
            'shortcut_id',
            'assignable_id'
        )->withTimestamps();
    }

    /**
     * Postes de travail individuels associés à ce raccourci
     */
    public function workstations(): MorphToMany
    {
        return $this->morphedByMany(
            Workstation::class,
            'assignable',
            'shortcut_assignables',
            'shortcut_id',
            'assignable_id'
        )->withTimestamps();
    }

    /**
     * Tous les noms de groupes assignés (tous types confondus)
     */
    public function getAssignedGroupNames(): array
    {
        $names = [];

        foreach ($this->workstationGroups as $wg) {
            $names[] = $wg->display_name ?? $wg->name;
        }

        return $names;
    }
    /**
     * Scope pour les raccourcis globaux (ControlHub)
     */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->where('is_global', true);
    }

    /**
     * Scope pour les raccourcis locaux
     */
    public function scopeLocal(Builder $query): Builder
    {
        return $query->where('is_global', false);
    }

    /**
     * Scope pour filtrer par emplacement
     */
    public function scopeByPlace(Builder $query, string $place): Builder
    {
        return $query->where('place', $place);
    }

    /**
     * Scope pour les raccourcis du bureau
     */
    public function scopeDesktop(Builder $query): Builder
    {
        return $query->where('place', self::PLACE_DESKTOP);
    }

    /**
     * Scope pour les raccourcis de démarrage
     */
    public function scopeStartup(Builder $query): Builder
    {
        return $query->where('place', self::PLACE_STARTUP);
    }

    /**
     * Scope pour les raccourcis de la barre des tâches
     */
    public function scopeTaskbar(Builder $query): Builder
    {
        return $query->where('place', self::PLACE_TASKBAR);
    }

    /**
     * Scope pour rechercher par nom ou propriétaire
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'ILIKE', "%{$search}%")
                ->orWhere('owner', 'ILIKE', "%{$search}%");
        });
    }

    /**
     * Scope pour filtrer par propriétaire
     */
    public function scopeByOwner(Builder $query, string $owner): Builder
    {
        return $query->where('owner', 'ILIKE', "%{$owner}%");
    }

    /**
     * Vérifie si c'est un raccourci URL
     */
    public function isUrlShortcut(): bool
    {
        return !empty($this->windows_args) && preg_match('/^https?:\/\//', $this->windows_args);
    }

    /**
     * Détecte si le raccourci contient des variables dynamiques
     * dans ses champs Windows ou Linux.
     */
    public function detectDynamic(): bool
    {
        $fields = [
            $this->windows_link,
            $this->windows_args,
            $this->windows_path,
            $this->windows_icon,
            $this->linux_link,
            $this->linux_args,
            $this->linux_path,
        ];

        foreach ($fields as $field) {
            if (empty($field)) {
                continue;
            }
            foreach (self::DYNAMIC_VARIABLES as $var) {
                if (str_contains($field, $var)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Retourne la liste des variables dynamiques trouvées dans ce raccourci.
     *
     * @return string[]
     */
    public function getDynamicVariables(): array
    {
        $found = [];
        $fields = [
            'windows_link' => $this->windows_link,
            'windows_args' => $this->windows_args,
            'windows_path' => $this->windows_path,
            'windows_icon' => $this->windows_icon,
            'linux_link' => $this->linux_link,
            'linux_args' => $this->linux_args,
            'linux_path' => $this->linux_path,
        ];

        foreach ($fields as $fieldName => $fieldValue) {
            if (empty($fieldValue)) {
                continue;
            }
            foreach (self::DYNAMIC_VARIABLES as $var) {
                if (str_contains($fieldValue, $var) && !in_array($var, $found, true)) {
                    $found[] = $var;
                }
            }
        }

        return $found;
    }

    /**
     * Marque le raccourci comme nécessitant une recompilation.
     */
    public function invalidateCompilation(): void
    {
        $this->update([
            'compiled_data' => null,
            'compiled_at' => null,
        ]);
        $this->compiledShortcuts()->delete();
    }

    /**
     * Vérifie si c'est un raccourci global (ControlHub)
     */
    public function isGlobal(): bool
    {
        return $this->is_global;
    }

    /**
     * Retourne le libellé de l'emplacement
     */
    public function getPlaceLabel(): string
    {
        return match ($this->place) {
            self::PLACE_DESKTOP => 'Bureau',
            self::PLACE_STARTUP => 'Démarrage automatique',
            self::PLACE_TASKBAR => 'Barre des tâches',
            default => 'Inconnu',
        };
    }

    /**
     * Retourne les propriétaires sous forme de tableau
     */
    public function getOwnersArray(): array
    {
        if (empty($this->owner)) {
            return [];
        }
        return array_map('trim', preg_split('/\s*,\s*/', $this->owner));
    }

    /**
     * Retourne la configuration Windows sous forme de tableau
     */
    public function getWindowsConfig(): array
    {
        return [
            'link' => $this->windows_link,
            'args' => $this->windows_args,
            'path' => $this->windows_path,
            'icon' => $this->windows_icon,
        ];
    }

    /**
     * Retourne la configuration Linux sous forme de tableau
     */
    public function getLinuxConfig(): array
    {
        return [
            'link' => $this->linux_link,
            'args' => $this->linux_args,
            'path' => $this->linux_path,
            'icon' => $this->linux_icon,
            'startupwmclass' => $this->linux_startupwmclass,
        ];
    }

    /**
     * Convertit le modèle au format JSON legacy (compatibilité ShortcutsService)
     */
    public function toLegacyFormat(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'owner' => $this->owner ?? '',
            'place' => $this->place,
            'global' => $this->is_global,
            'windows' => [
                'link' => $this->windows_link ?? '',
                'args' => $this->windows_args ?? '',
                'path' => $this->windows_path ?? '',
                'icon' => $this->windows_icon ?? '',
            ],
            'linux' => [
                'link' => $this->linux_link ?? '',
                'args' => $this->linux_args ?? '',
                'path' => $this->linux_path ?? '',
                'icon' => $this->linux_icon ?? '',
                'startupwmclass' => $this->linux_startupwmclass ?? '',
            ],
        ];
    }

    /**
     * Crée un raccourci depuis le format legacy JSON
     */
    public static function fromLegacyFormat(string $key, array $data): self
    {
        return new self([
            'key' => $key,
            'name' => $data['name'] ?? '',
            'owner' => $data['owner'] ?? null,
            'place' => $data['place'] ?? self::PLACE_DESKTOP,
            'is_global' => $data['global'] ?? false,
            'windows_link' => $data['windows']['link'] ?? null,
            'windows_args' => $data['windows']['args'] ?? null,
            'windows_path' => $data['windows']['path'] ?? null,
            'windows_icon' => $data['windows']['icon'] ?? null,
            'linux_link' => $data['linux']['link'] ?? null,
            'linux_args' => $data['linux']['args'] ?? null,
            'linux_path' => $data['linux']['path'] ?? null,
            'linux_icon' => $data['linux']['icon'] ?? null,
            'linux_startupwmclass' => $data['linux']['startupwmclass'] ?? null,
        ]);
    }

    /**
     * Trouve un raccourci par sa clé
     */
    public static function findByKey(string $key): ?self
    {
        return static::where('key', $key)->first();
    }

    /**
     * Trouve un raccourci par son nom
     */
    public static function findByName(string $name): ?self
    {
        return static::where('name', $name)->first();
    }

    /**
     * Sérialise le modèle pour Livewire
     */
    public function toLivewire(): array
    {
        return ['id' => $this->id];
    }

    /**
     * Désérialise depuis Livewire
     */
    public static function fromLivewire($value): static
    {
        return static::findOrFail($value['id']);
    }
}
