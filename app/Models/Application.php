<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Wireable;

/**
 * Modèle Eloquent pour les applications WPKG
 * 
 * Utilise la table PostgreSQL 'applications'.
 * 
 * @property int $id
 * @property string|null $controlhub_id UUID ControlHub
 * @property \DateTime|null $controlhub_version Version ControlHub
 * @property bool $managed_by_control_hub Géré par ControlHub
 * @property int|null $depot_id ID du dépôt parent
 * @property string $app_id Identifiant technique de l'application
 * @property string $name Nom d'affichage
 * @property string|null $version Version de l'application
 * @property string|null $category Catégorie
 * @property string|null $compatibility Compatibilité OS
 * @property string|null $branch Branche (stable, testing, etc.)
 * @property string|null $xml Contenu XML
 * @property string|null $xml_url URL du fichier XML
 * @property string|null $xml_sha Hash SHA du XML
 * @property string|null $log_url URL du log
 * @property ApplicationStatus $status Statut d'installation
 * @property string|null $installed_version Version installée
 * @property string|null $installer_url URL de l'installeur
 * @property string|null $installer_sha256 Hash SHA256 de l'installeur
 * @property string|null $installer_filename Nom du fichier installeur
 * @property int|null $installer_size Taille en octets
 * @property string|null $local_xml_path Chemin XML local
 * @property string|null $local_installer_path Chemin installeur local
 * @property string|null $description Description
 * @property string|null $icon_url URL de l'icône
 * @property string|null $author Auteur du paquet
 * @property \DateTime|null $installed_at Date d'installation
 * @property \DateTime|null $last_checked_at Dernière vérification
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class Application extends Model implements Wireable
{
    /**
     * La table associée au modèle
     */
    protected $table = 'applications';

    /**
     * Les attributs qui peuvent être assignés en masse
     */
    protected $fillable = [
        'controlhub_id',
        'controlhub_version',
        'managed_by_control_hub',
        'depot_id',
        'app_id',
        'name',
        'version',
        'category',
        'compatibility',
        'branch',
        'xml',
        'xml_url',
        'xml_sha',
        'log_url',
        'status',
        'installed_version',
        'installer_url',
        'installer_sha256',
        'installer_filename',
        'installer_size',
        'local_xml_path',
        'local_installer_path',
        'description',
        'icon_url',
        'author',
        'installed_at',
        'last_checked_at',
    ];

    /**
     * Les attributs qui doivent être castés
     */
    protected $casts = [
        'depot_id' => 'integer',
        'status' => ApplicationStatus::class,
        'managed_by_control_hub' => 'boolean',
        'controlhub_version' => 'datetime',
        'installer_size' => 'integer',
        'installed_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    /**
     * Relation avec le dépôt parent
     */
    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class, 'depot_id');
    }

    /**
     * Relation ManyToMany avec les profils applicatifs
     */
    public function appProfiles(): BelongsToMany
    {
        return $this->belongsToMany(
            AppProfile::class,
            'app_profile_application',
            'application_id',
            'app_profile_id'
        )->withTimestamps();
    }

    /**
     * Scope pour filtrer par catégorie
     */
    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Scope pour filtrer par branche
     */
    public function scopeByBranch(Builder $query, string $branch): Builder
    {
        return $query->where('branch', $branch);
    }

    /**
     * Scope pour rechercher par nom
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'ILIKE', "%{$search}%")
                ->orWhere('app_id', 'ILIKE', "%{$search}%");
        });
    }

    /**
     * Scope pour les applications installées
     */
    public function scopeInstalled(Builder $query): Builder
    {
        return $query->where('status', ApplicationStatus::Installed);
    }

    /**
     * Scope pour les applications disponibles (non installées)
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', ApplicationStatus::Available);
    }

    /**
     * Scope pour les mises à jour disponibles
     */
    public function scopeUpdatable(Builder $query): Builder
    {
        return $query->where('status', ApplicationStatus::UpdateAvailable);
    }

    /**
     * Relation avec les logs d'installation
     */
    public function installationLogs(): HasMany
    {
        return $this->hasMany(InstallationLog::class)->orderByDesc('created_at');
    }

    /**
     * Vérifie si l'application est installée
     */
    public function isInstalled(): bool
    {
        return $this->status === ApplicationStatus::Installed;
    }

    /**
     * Vérifie si une mise à jour est disponible
     */
    public function hasUpdate(): bool
    {
        return $this->status === ApplicationStatus::UpdateAvailable;
    }

    /**
     * Vérifie si l'application est en cours de téléchargement
     */
    public function isDownloading(): bool
    {
        return $this->status === ApplicationStatus::Downloading;
    }

    /**
     * Retourne la taille formatée de l'installeur
     */
    public function getFormattedSizeAttribute(): string
    {
        if (!$this->installer_size) {
            return 'N/A';
        }

        $units = ['o', 'Ko', 'Mo', 'Go'];
        $size = (float) $this->installer_size;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 1) . ' ' . $units[$unitIndex];
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

    /**
     * Statuts d'installation sur les postes de travail
     */
    public function workstationStatuses(): HasMany
    {
        return $this->hasMany(WorkstationApplicationStatus::class);
    }
}
