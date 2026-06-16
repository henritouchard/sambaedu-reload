<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Story 27.3bis — Catalogue des associations de fichiers/protocoles par défaut
 * (type `associations` sans table métier existante : table DÉDIÉE, D1, iso 27.3).
 *
 * Chaque ligne est une association PRÉDÉTERMINÉE activable par parc. Le
 * {@see \App\Services\Agent\Providers\AssociationsStateProvider} la COMPILE en un
 * item de contrat CONCRET `{identifier, progid, type}`. Le `key` du catalogue (ou
 * l'`id`) ne fuite JAMAIS au payload — invariant central qui garde l'option
 * « clés brutes » gratuite plus tard (v2).
 *
 * **Le hash UserChoice n'est NI stocké NI émis** : il est calculé 100 % côté
 * agent (compagnon, HKCU) car il dépend du SID/timestamp/GUID du poste (piège
 * n° 2). Le catalogue ne porte que la cible logique.
 *
 * @property int $id
 * @property string $key Clé technique unique de l'association de catalogue
 * @property string $label Libellé affichable
 * @property string|null $description Aide UI
 * @property string $identifier Extension (.pdf) ou protocole (http)
 * @property string $assoc_type file | protocol
 * @property string $progid ProgId Windows cible (UserChoice)
 * @property string $source native | wpkg (D-Henri n°7) — SERVEUR-only
 * @property string|null $wpkg_package <package id> WPKG d'origine (= Application::app_id) ; null si native
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class FileAssociation extends Model
{
    use HasFactory;

    protected $table = 'file_associations';

    /**
     * Identifiant FIGÉ du type de ressource desired-state (contrat §7, NFR12),
     * iso `RegistrySetting::TYPE_REGISTRY`/`Shortcut::TYPE_SHORTCUTS`. Consommé
     * par {@see \App\Services\Agent\Providers\AssociationsStateProvider}.
     * snake_case, jamais renommé une fois publié.
     */
    public const TYPE_ASSOCIATIONS = 'associations';

    /** Association de fichier (extension) → clé `FileExts\<ext>\UserChoice`. */
    public const ASSOC_TYPE_FILE = 'file';

    /** Association de protocole → clé `UrlAssociations\<proto>\UserChoice`. */
    public const ASSOC_TYPE_PROTOCOL = 'protocol';

    /**
     * Source `native` (D-Henri n°7) : le ProgId est un built-in Windows (ex.
     * `txtfile` pour `.txt`, `WindowsPhotoViewer` pour `.jpg`) — TOUJOURS présent
     * sur le poste, donc l'association est TOUJOURS applicable (aucune dépendance
     * de paquet). `wpkg_package` est `null`.
     */
    public const SOURCE_NATIVE = 'native';

    /**
     * Source `wpkg` (D-Henri n°7) : le ProgId est fourni par un paquet WPKG (ex.
     * `FirefoxURL` par le paquet `firefox`). L'association n'est applicable QUE si
     * `wpkg_package` est déployé sur le parc — sinon l'UI affiche « indisponible »
     * AVANT déploiement (l'agent reste le dernier rempart sur le poste).
     */
    public const SOURCE_WPKG = 'wpkg';

    protected $fillable = [
        'key',
        'label',
        'description',
        'identifier',
        'assoc_type',
        'progid',
        'source',
        'wpkg_package',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Groupes de postes (salles physiques, parcs logiques) auxquels cette
     * association est assignée — geste UI v1 (par PARC). Pivot polymorphe calqué
     * shortcuts/registry.
     */
    public function workstationGroups(): MorphToMany
    {
        return $this->morphedByMany(
            WorkstationGroup::class,
            'assignable',
            'file_association_assignables',
            'file_association_id',
            'assignable_id',
        )->withTimestamps();
    }

    /**
     * Postes individuels assignés (pivot complet, extensible sans migration —
     * non exposé en UI v1).
     */
    public function workstations(): MorphToMany
    {
        return $this->morphedByMany(
            Workstation::class,
            'assignable',
            'file_association_assignables',
            'file_association_id',
            'assignable_id',
        )->withTimestamps();
    }

    /**
     * Groupes utilisateur assignés (pivot complet, non exposé en UI v1).
     */
    public function userGroups(): MorphToMany
    {
        return $this->morphedByMany(
            UserGroup::class,
            'assignable',
            'file_association_assignables',
            'file_association_id',
            'assignable_id',
        )->withTimestamps();
    }

    /**
     * Utilisateurs assignés (pivot complet, non exposé en UI v1).
     */
    public function users(): MorphToMany
    {
        return $this->morphedByMany(
            User::class,
            'assignable',
            'file_association_assignables',
            'file_association_id',
            'assignable_id',
        )->withTimestamps();
    }

    /**
     * L'association cible-t-elle un protocole (UrlAssociations) plutôt qu'une
     * extension (FileExts) ? Détermine la sous-clé de registre côté agent.
     */
    public function isProtocol(): bool
    {
        return $this->assoc_type === self::ASSOC_TYPE_PROTOCOL;
    }

    /**
     * Le ProgId cible est-il un built-in Windows (D-Henri n°7) ? Si oui,
     * l'association est applicable sur N'IMPORTE quel parc (aucune dépendance de
     * paquet WPKG). Sinon (`wpkg`), l'applicabilité dépend du déploiement du
     * paquet `wpkg_package` sur le parc — vérifié côté UI (validation prédictive),
     * JAMAIS côté provider (qui émet toujours, D-Henri n°3, NFR7).
     */
    public function isNative(): bool
    {
        return $this->source === self::SOURCE_NATIVE;
    }

    /**
     * Clé de catalogue DÉTERMINISTE dérivée de l'identité d'une entrée =
     * `(identifier, progid)`. Story 27.3bis : le seed-migration, la baseline figée
     * du seeder ET le parse `default.xml` legacy DOIVENT converger sur cette clé
     * pour qu'une paire identique upsert au lieu de DUPLIQUER (sinon doublon
     * catalogue + faux conflit `agent.state.conflict` au compilateur sur VM, où
     * `default.xml` est lisible). Deux ProgId DISTINCTS pour le même identifiant
     * restent deux entrées (deux choix de défaut pour l'extension).
     */
    public static function catalogKey(string $identifier, string $progid): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($identifier . '_' . $progid));

        return trim((string) $slug, '_');
    }
}
