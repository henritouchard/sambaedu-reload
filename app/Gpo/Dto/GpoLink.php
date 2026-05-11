<?php

declare(strict_types=1);

namespace App\Gpo\Dto;

/**
 * Liaison GPO ↔ container AD (OU, Site, Domain) — résultat de
 * `samba-tool gpo getlink`.
 *
 * @see \App\Gpo\Services\GpoService::getLinks()
 */
final readonly class GpoLink
{
    public function __construct(
        /** DN du container AD (OU, Site, Domain) auquel la GPO est liée. */
        public string $containerDn,
        /** GUID de la GPO (format `{XXXX...XXXX}`). */
        public string $gpoName,
        /** Display name de la GPO (peut être null si non résolu). */
        public ?string $gpoDisplayName = null,
        /** Liaison `enforced` (héritage obligatoire vers les enfants). */
        public bool $enforced = false,
        /** Liaison désactivée (la GPO ne s'applique pas malgré le lien). */
        public bool $disabled = false,
        /**
         * Brut de la valeur `Options` de samba-tool (bitfield 0/1/2/3).
         * 0 = enabled non-enforced, 1 = disabled, 2 = enforced, 3 = disabled+enforced.
         */
        public ?int $optionsRaw = null,
    ) {}

    /**
     * @return array{containerDn:string,gpoName:string,gpoDisplayName:?string,enforced:bool,disabled:bool,optionsRaw:?int}
     */
    public function toArray(): array
    {
        return [
            'containerDn' => $this->containerDn,
            'gpoName' => $this->gpoName,
            'gpoDisplayName' => $this->gpoDisplayName,
            'enforced' => $this->enforced,
            'disabled' => $this->disabled,
            'optionsRaw' => $this->optionsRaw,
        ];
    }
}
