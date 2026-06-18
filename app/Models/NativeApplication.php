<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Story 27.11 — Application NATIVE CURÉE (built-in Windows Win32).
 *
 * Référentiel CURÉ MANUELLEMENT des programmes livrés avec Windows dont le ProgId
 * canonique est CONNU et toujours présent (Bloc-notes/`txtfile`, Paint/`Paint.Picture`,
 * WordPad, Visionneuse de photos). Source 2 du dropdown du composer d'associations
 * (Source 1 = {@see Application} WPKG). **UWP modernes EXCLUES** (D-Henri n°2).
 *
 * Une native curée → {@see \App\Services\Agent\Resolvers\AssociationResolver}
 * émet son `progid` canonique avec `source=native`, `wpkg_package=null` — TOUJOURS
 * applicable (aucune dépendance de paquet, piège n°7).
 *
 * @property int $id
 * @property string $key Clé technique unique (slug)
 * @property string $label Libellé affichable dans le dropdown
 * @property string $progid ProgId canonique built-in (ex. txtfile, Paint.Picture)
 * @property string $executable Chemin/nom de l'exe runtime (fallback générique)
 * @property list<string> $assoc_types Identifiants (extensions/protocoles) gérés nativement
 * @property string|null $icon_url Icône optionnelle du dropdown
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class NativeApplication extends Model
{
    use HasFactory;

    protected $table = 'native_applications';

    protected $fillable = [
        'key',
        'label',
        'progid',
        'executable',
        'assoc_types',
        'icon_url',
    ];

    protected $casts = [
        'assoc_types' => 'array',
    ];

    /**
     * Ce built-in déclare-t-il un ProgId canonique POUR cet identifiant
     * (extension/protocole) ? (piège n°2 : un ProgId est par (app × type de
     * contenu) — un built-in ne couvre pas n'importe quelle extension.) Insensible
     * à la casse (Windows l'est sur extensions/protocoles).
     */
    public function supportsIdentifier(string $identifier): bool
    {
        $needle = strtolower($identifier);

        foreach ($this->assoc_types ?? [] as $type) {
            if (strtolower((string) $type) === $needle) {
                return true;
            }
        }

        return false;
    }
}
