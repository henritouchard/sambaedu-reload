<?php

declare(strict_types=1);

namespace App\Services\Filesystem;

use App\Models\NetworkShare;

/**
 * Story 34.3 → 60.4 — résultat de {@see DirectoryTemplateService::materialize()}.
 *
 * Porte le {@see NetworkShare} matérialisé, les avertissements NON bloquants à
 * surfacer en `toastWarning` (WG-montage-seul — vide en 34.3 puisque les recettes
 * n'assignent jamais de parc, mais conservé pour homogénéité avec la page détail
 * 34.2), et l'ÉTAT du provisionnement.
 *
 * **Pourquoi cet état n'est plus un booléen** (story 60.4). Quand la
 * matérialisation vient d'un écran, la pose des droits n'est plus faite dans le
 * cycle de la requête : elle est ENFILÉE. Un booléen n'aurait alors eu que deux
 * réponses possibles, toutes deux fausses — `true` aurait affirmé un
 * provisionnement accompli qui ne l'était pas, `false` aurait annoncé un échec qui
 * n'existait pas. C'est exactement le mode de rupture que la ligne de contrat
 * combat un cran plus bas : un verdict binaire qui ne sait pas dire « engagé ».
 */
final readonly class TemplateMaterializationResult
{
    /** Les droits sont posés : le répertoire est dans l'état voulu. */
    public const PROVISIONING_APPLIED = 'applique';

    /** La réconciliation est ENGAGÉE, pas achevée (chemin des écrans). */
    public const PROVISIONING_QUEUED = 'en_attente';

    /** La pose des droits a échoué ; les données en base, elles, sont écrites. */
    public const PROVISIONING_FAILED = 'echec';

    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public NetworkShare $share,
        public array $warnings,
        public string $provisioning,
    ) {
    }

    /** `true` seulement si la pose des droits a ÉCHOUÉ — enfilé n'est pas un échec. */
    public function isFailure(): bool
    {
        return $this->provisioning === self::PROVISIONING_FAILED;
    }
}
