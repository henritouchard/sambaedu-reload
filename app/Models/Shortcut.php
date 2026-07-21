<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
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
     * Navigateurs proposés pour un raccourci de type « site web ».
     *
     * `windows` est TOUJOURS un chemin d'exécutable réel : l'agent le passe à
     * `IShellLink::SetPath()`, qui n'accepte rien d'autre. Le legacy stockait
     * ici les sentinelles `default` et `microsoft-edge` puis les réécrivait au
     * moment de générer le `.lnk` (`shortcuts.inc.php:181-187`) — ce sont ces
     * deux cas qui passent par `rundll32 url.dll,FileProtocolHandler`, seule
     * façon d'atteindre le navigateur par défaut ou de forcer Edge sans
     * dépendre d'un chemin d'installation.
     *
     * `windows_args_prefix` est collé DEVANT l'URL (espace final significatif ;
     * `microsoft-edge:` n'en prend pas, l'URL suit immédiatement le schéma).
     */
    public const BROWSERS = [
        '' => [
            'label' => 'Navigateur par défaut du poste',
            'windows' => 'C:\\Windows\\System32\\rundll32.exe',
            'windows_args_prefix' => 'url.dll,FileProtocolHandler ',
            // `xdg-open` est l'équivalent Linux du protocol handler. Le legacy
            // posait « firefox » pour ce cas, ce qui contredisait le libellé.
            'linux' => 'xdg-open',
            'linux_args_prefix' => '',
        ],
        'firefox' => [
            'label' => 'Mozilla Firefox',
            'windows' => 'C:\\Program Files\\Mozilla Firefox\\firefox.exe',
            'windows_args_prefix' => '',
            'linux' => 'firefox',
            'linux_args_prefix' => '',
        ],
        'chrome' => [
            'label' => 'Google Chrome',
            'windows' => 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'windows_args_prefix' => '',
            'linux' => 'chromium',
            'linux_args_prefix' => '',
        ],
        'edge' => [
            'label' => 'Microsoft Edge',
            'windows' => 'C:\\Windows\\System32\\rundll32.exe',
            'windows_args_prefix' => 'url.dll,FileProtocolHandler microsoft-edge:',
            'linux' => 'xdg-open',
            'linux_args_prefix' => '',
        ],
        'firefox_drm' => [
            'label' => 'Mozilla Firefox (vidéos avec DRM)',
            'windows' => 'C:\\Program Files\\Mozilla Firefox\\firefox.exe',
            'windows_args_prefix' => '-profile %temp%\\FFtemp -no-remote -new-instance ',
            'linux' => 'firefox',
            'linux_args_prefix' => '',
        ],
    ];

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
     * Utilisateurs (SQL) associés à ce raccourci.
     *
     * Le ciblage utilisateur passe par le MÊME pivot polymorphe que les postes.
     * `ShortcutsStateProvider` sait le lire depuis 27.14 ; jusqu'ici seule
     * l'écriture manquait — l'UI déposait des logins AD dans la colonne JSON
     * `ad_users`, que plus aucun canal ne lit. Une assignation utilisateur
     * n'avait donc aucun effet.
     */
    public function users(): MorphToMany
    {
        return $this->morphedByMany(
            User::class,
            'assignable',
            'shortcut_assignables',
            'shortcut_id',
            'assignable_id'
        )->withTimestamps();
    }

    /**
     * Groupes d'utilisateurs (SQL) associés à ce raccourci.
     *
     * `user_groups`, pas des CN AD : c'est ce que résout `TargetContext`
     * (`$user->groups()->pluck('user_groups.id')`).
     */
    public function userGroups(): MorphToMany
    {
        return $this->morphedByMany(
            UserGroup::class,
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
     * Vérifie si c'est un raccourci URL.
     *
     * La colonne `is_url` fait foi : le type est désormais CHOISI au
     * formulaire, plus deviné. La détection historique (`^https?://`) est
     * conservée en repli pour les lignes qui n'ont jamais été typées —
     * typiquement celles poussées par le ControlHub, dont le payload
     * `is_url` est optionnel.
     */
    public function isUrlShortcut(): bool
    {
        if ($this->is_url) {
            return true;
        }

        return $this->looksLikeUrlShortcut();
    }

    /**
     * Détection heuristique du type, sur les deux champs où une URL a pu
     * atterrir faute de choix explicite dans l'ancien formulaire.
     */
    public function looksLikeUrlShortcut(): bool
    {
        foreach ([$this->windows_args, $this->windows_link] as $value) {
            if (!empty($value) && preg_match('/^https?:\/\//', $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scope : raccourcis de type « site web » / « application ».
     *
     * Filtre en SQL sur `is_url` en incluant le même repli heuristique que
     * `isUrlShortcut()`, pour que la liste et le détail ne se contredisent pas.
     */
    public function scopeByType(Builder $query, string $type): Builder
    {
        $looksLikeUrl = fn (Builder $q) => $q
            ->where('windows_args', 'LIKE', 'http://%')
            ->orWhere('windows_args', 'LIKE', 'https://%')
            ->orWhere('windows_link', 'LIKE', 'http://%')
            ->orWhere('windows_link', 'LIKE', 'https://%');

        if ($type === 'url') {
            return $query->where(fn (Builder $q) => $q->where('is_url', true)->orWhere($looksLikeUrl));
        }

        return $query->where('is_url', false)->whereNot($looksLikeUrl);
    }

    /**
     * URL cible d'un raccourci web, quel que soit le champ où elle est stockée
     * (`windows_args` quand un navigateur est imposé, `windows_link` sinon).
     */
    public function getUrl(): ?string
    {
        foreach ([$this->windows_args, $this->windows_link, $this->linux_args] as $value) {
            // `preg_match` et non `str_starts_with` : un navigateur imposé peut
            // préfixer ses propres arguments avant l'URL (profil DRM).
            if (!empty($value) && preg_match('/\bhttps?:\/\/\S+/', $value, $m)) {
                return $m[0];
            }
        }

        return null;
    }

    /**
     * Traduit un couple (URL, navigateur) en attributs de raccourci.
     *
     * La cible est TOUJOURS un exécutable réel et l'URL passe en arguments :
     * c'est la seule forme que sait poser l'agent
     * (`ShortcutSpec{Target, Args}` → `IShellLink::SetPath`). Une URL en cible
     * produit « l'élément auquel ce raccourci renvoie a été modifié ou
     * déplacé » sur le poste.
     */
    public static function webTargetAttributes(string $url, string $browserKey = ''): array
    {
        $browser = self::BROWSERS[$browserKey] ?? self::BROWSERS[''];

        return [
            'is_url' => true,
            'windows_link' => $browser['windows'],
            'windows_args' => ($browser['windows_args_prefix'] ?? '').$url,
            'linux_link' => $browser['linux'],
            'linux_args' => ($browser['linux_args_prefix'] ?? '').$url,
        ];
    }

    /**
     * Retrouve la clé de `BROWSERS` correspondant à ce que porte le raccourci.
     *
     * Plusieurs navigateurs partagent une même cible — `rundll32.exe` pour le
     * défaut et Edge, le binaire Firefox pour Firefox et son profil DRM. Ce
     * sont les ARGUMENTS qui tranchent, d'où le tri par préfixe le plus long
     * d'abord : `url.dll,FileProtocolHandler microsoft-edge:` doit être testé
     * avant `url.dll,FileProtocolHandler `, dont il est une extension.
     */
    public function detectBrowserKey(): string
    {
        $link = (string) $this->windows_link;
        $args = (string) $this->windows_args;

        // Anciennes formes, antérieures au passage par `rundll32` : sentinelles
        // héritées du legacy, ou URL posée directement en cible. Sans ce
        // rattrapage, un raccourci Edge existant serait relu « par défaut » et
        // perdrait silencieusement son navigateur à la première réécriture.
        if (strcasecmp($link, 'microsoft-edge') === 0) {
            return 'edge';
        }
        if ($link === '' || strcasecmp($link, 'default') === 0 || preg_match('/^https?:\/\//', $link)) {
            return '';
        }

        $keys = array_keys(self::BROWSERS);
        usort($keys, fn ($a, $b) => strlen((string) (self::BROWSERS[$b]['windows_args_prefix'] ?? ''))
            <=> strlen((string) (self::BROWSERS[$a]['windows_args_prefix'] ?? '')));

        foreach ($keys as $key) {
            $browser = self::BROWSERS[$key];
            if (strcasecmp($browser['windows'], $link) !== 0) {
                continue;
            }
            $prefix = (string) ($browser['windows_args_prefix'] ?? '');
            if ($prefix !== '' && !str_starts_with($args, $prefix)) {
                continue;
            }

            return $key;
        }

        return '';
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
