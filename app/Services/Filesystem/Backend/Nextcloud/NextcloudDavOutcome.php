<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Nextcloud;

/**
 * Story 61.3 — L'ISSUE D'UN GESTE WEBDAV, avec ses idempotences DÉJÀ normalisées.
 *
 * Le transport de ce backend a trois sémantiques natives pour « c'était déjà
 * fait » ou « ce n'est pas une erreur », et aucune ne doit remonter au-dessus de la
 * ligne de contrat :
 *
 *  - **405 sur la création d'un dossier** = le dossier existe. Idempotence, pas
 *    échec ;
 *  - **409 sur la création d'un dossier** = le parent manque. Ce n'en est PAS une :
 *    c'est un défaut d'ORDRE de notre côté (ce protocole ne crée pas les parents),
 *    et l'avaler ferait disparaître un nœud sans un mot ;
 *  - **404 sur la lecture d'une liste de règles** = il n'y a aucune règle. Une
 *    réponse, jamais une erreur (même famille que le `405` ci-dessus et que le
 *    statut « existe déjà » du canal OCS).
 *
 * L'objet porte donc la nuance ; le backend la traduit en état de contrat.
 */
final class NextcloudDavOutcome
{
    private function __construct(
        public readonly bool $ok,
        public readonly bool $alreadyThere,
        public readonly bool $orderFault,
        public readonly ?string $error,
        public readonly int $httpStatus,
    ) {
    }

    public static function created(int $status): self
    {
        return new self(true, false, false, null, $status);
    }

    /** Le geste n'avait rien à faire : la cible était déjà dans l'état voulu. */
    public static function alreadyThere(int $status): self
    {
        return new self(true, true, false, null, $status);
    }

    /** Le parent manque : c'est notre ordre de pose qui est en cause, pas l'instance. */
    public static function missingParent(int $status): self
    {
        return new self(false, false, true, 'le dossier parent n\'existe pas encore sur l\'instance : '
            . 'les niveaux se créent un par un, du plus haut au plus bas.', $status);
    }

    public static function failed(string $error, int $status): self
    {
        return new self(false, false, false, $error, $status);
    }
}
