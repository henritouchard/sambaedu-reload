<?php

declare(strict_types=1);

namespace App\Services\Shortcuts;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Log;

/**
 * L'icône du raccourci « portail web » — publication et lecture.
 *
 * Le raccourci vers le portail Nextcloud n'est PAS une ligne de `shortcuts` :
 * c'est un item SYNTHÉTIQUE émis par le
 * {@see \App\Services\Agent\Providers\ShortcutsStateProvider} quand le réglage
 * global l'active. Il n'a donc pas d'icône uploadée, et sans icône un `.lnk`
 * dont la cible est `rundll32.exe` affiche l'icône de `rundll32.exe` — un
 * raccourci illisible sur tous les bureaux de l'établissement.
 *
 * **Pourquoi deux temps (publier / lire) plutôt qu'un seul.** Le mécanisme
 * d'icônes de raccourcis est content-addressed : l'agent dérive l'URL du
 * `<sha256>.ico` servi par Apache et vérifie le checksum ({@see
 * ShortcutIconAssetService}). Il faut donc que le fichier soit COPIÉ dans le
 * dossier servi et que son empreinte soit connue. Faire ce calcul dans le
 * provider violerait son invariant de performance — il ne fait QUE des lectures
 * de colonnes, zéro hash, zéro I/O, à chaque compilation d'état de chaque poste.
 * La publication a donc lieu UNE fois, sur geste d'administration (l'écran
 * `/admin/settings/files`), et le provider ne relit qu'un couple persisté.
 *
 * **Le couple publié ne vit PAS dans `files.policy`** : ce réglage-là décrit ce
 * que l'exploitant a DÉCIDÉ, il est lisible et diffable. Un nom de fichier et une
 * empreinte sont de l'état DÉRIVÉ, régénérable, et n'ont rien à y faire. D'où une
 * clé de réglage dédiée.
 *
 * **Fail-soft de bout en bout** : une publication qui échoue (fichier source
 * absent, dossier servi non créable) ne fait pas disparaître le raccourci — elle
 * le laisse sans icône. Un chemin d'accès visible sans icône reste un chemin
 * d'accès ; pas de raccourci du tout serait la vraie perte.
 */
final class PortalShortcutIcon
{
    /** Clé SystemSetting du couple publié (état dérivé, pas du réglage). */
    public const SETTING_KEY = 'shortcuts.portal_icon';

    /**
     * Source versionnée de l'icône : un nuage NEUTRE, sans marque. Le même
     * raccourci-portail servira d'autres produits (OpenCloud) ; une icône de
     * marque aurait à être remplacée à chaque fois, et engagerait SE5 sur
     * l'usage d'un logo qui ne lui appartient pas.
     */
    public const SOURCE_RELATIVE_PATH = 'elements/images/cloud-portal.ico';

    public function __construct(
        private readonly ShortcutIconAssetService $assets,
    ) {}

    /** Chemin absolu du `.ico` source livré avec l'application. */
    public function sourcePath(): string
    {
        return public_path(self::SOURCE_RELATIVE_PATH);
    }

    /**
     * Publie l'icône dans le dossier servi et persiste le couple
     * `{asset, checksum}`. Idempotent : republier une icône inchangée ne réécrit
     * rien et repersiste le même couple.
     *
     * @return array{asset:string, checksum:string}|null `null` si la publication
     *                                                   a échoué (l'ancien couple
     *                                                   persisté est alors CONSERVÉ :
     *                                                   il désigne peut-être un
     *                                                   fichier encore servi).
     */
    public function publish(): ?array
    {
        $published = $this->assets->contentAddress($this->sourcePath());

        if ($published === null) {
            Log::warning('[PortalShortcutIcon] publication impossible, le raccourci portail restera sans icône', [
                'source' => $this->sourcePath(),
            ]);

            return null;
        }

        SystemSetting::set(self::SETTING_KEY, $published);

        return $published;
    }

    /**
     * Le couple publié, ou `null` s'il n'y en a pas encore.
     *
     * LECTURE PURE (une ligne de réglage) — appelable depuis le provider sans
     * toucher au système de fichiers.
     *
     * @return array{asset:string, checksum:string}|null
     */
    public function current(): ?array
    {
        $stored = SystemSetting::get(self::SETTING_KEY);

        if (! is_array($stored)) {
            return null;
        }

        $asset = is_string($stored['asset'] ?? null) ? $stored['asset'] : '';
        $checksum = is_string($stored['checksum'] ?? null) ? $stored['checksum'] : '';

        if ($asset === '' || $checksum === '') {
            return null;
        }

        return ['asset' => $asset, 'checksum' => $checksum];
    }
}
