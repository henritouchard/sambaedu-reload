<?php

declare(strict_types=1);

namespace App\Gpo\Dto;

/**
 * Résumé d'une GPO retournée par `samba-tool gpo listall` ou
 * `samba-tool gpo show`.
 *
 * Mode lecture seule, typé strict. Construit par {@see \App\Gpo\Services\GpoService}
 * à partir du parsing de la sortie `samba-tool`.
 *
 * Note : `versionnumber` et `dn` ne sont remplis qu'à partir d'une lecture
 * détaillée (`get()`). En sortie `listall`, seuls `name` et `displayName`
 * sont disponibles — les autres champs sont null.
 *
 * @see https://www.samba.org/samba/docs/current/man-html/samba-tool.8.html
 */
final readonly class GpoSummary
{
    public function __construct(
        /** GUID (format `{XXXX...XXXX}`), identifiant interne AD de la GPO. */
        public string $name,
        /** Nom affiché lisible par l'admin (ex. `redirections`, `se4_proxy`). */
        public string $displayName,
        /** Numéro de version SYSVOL (NULL si listing simple). */
        public ?int $versionNumber = null,
        /** DN de la GPO dans l'AD (NULL si listing simple). */
        public ?string $dn = null,
        /** Path SYSVOL (NULL si listing simple). */
        public ?string $path = null,
    ) {}

    /**
     * @return array{name:string,displayName:string,versionNumber:?int,dn:?string,path:?string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'displayName' => $this->displayName,
            'versionNumber' => $this->versionNumber,
            'dn' => $this->dn,
            'path' => $this->path,
        ];
    }
}
