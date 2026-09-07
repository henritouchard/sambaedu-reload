<?php

declare(strict_types=1);

namespace App\Ipxe\Support;

use App\Ipxe\Enums\EnrollNameStatus;
use App\Ipxe\Services\IpxeHostnameSanitizer;
use App\Models\Workstation;

/**
 * Value object immuable retourné par
 * {@see \App\Ipxe\Services\WorkstationEnrollmentService::enrollName()}.
 *
 * **Pourquoi un value object plutôt qu'un retour `bool` ?** Le template
 * Blade `ipxe.enrollment.name` switch sur 6 cas distincts (`CREATED`,
 * `RENAMED`, `SAME_NAME`, `NAME_TAKEN`, `DB_ERROR`, `AD_ERROR`) qui doivent
 * être visibles côté controller pour conditionner le rendu. Un `bool` perdrait
 * cette information et obligerait à dupliquer la logique.
 *
 * **Pourquoi un `final readonly class` ?** Aucun setter : l'objet est
 * construit en bloc et immuable.
 */
final readonly class EnrollNameResult
{
    public function __construct(
        public EnrollNameStatus $status,
        public string $sanitizedName,
        public ?Workstation $workstation = null,
        public ?bool $adResult = null,
        public ?string $reasonLabel = null,
    ) {
    }

    /**
     * Factory : cas 1 (création neuve réussie).
     */
    public static function created(Workstation $workstation, string $sanitizedName, bool $adResult): self
    {
        // Sanitization ASCII : le firmware iPXE rejette l'ASCII étendu.
        return new self(
            status: EnrollNameStatus::Created,
            sanitizedName: IpxeHostnameSanitizer::sanitizeForIpxeOutput($sanitizedName),
            workstation: $workstation,
            adResult: $adResult,
        );
    }

    /**
     * Factory : cas 2 (idempotent — même nom déjà enregistré).
     */
    public static function sameName(Workstation $workstation, string $sanitizedName): self
    {
        // Sanitization ASCII : le firmware iPXE rejette l'ASCII étendu.
        return new self(
            status: EnrollNameStatus::SameName,
            sanitizedName: IpxeHostnameSanitizer::sanitizeForIpxeOutput($sanitizedName),
            workstation: $workstation,
        );
    }

    /**
     * Factory : cas 3 (renommage réussi en DB).
     */
    public static function renamed(Workstation $workstation, string $sanitizedName, bool $adResult): self
    {
        // Sanitization ASCII : le firmware iPXE rejette l'ASCII étendu.
        return new self(
            status: EnrollNameStatus::Renamed,
            sanitizedName: IpxeHostnameSanitizer::sanitizeForIpxeOutput($sanitizedName),
            workstation: $workstation,
            adResult: $adResult,
        );
    }

    /**
     * Factory : cas 4 (nom déjà pris par un autre poste).
     */
    public static function nameTaken(string $sanitizedName): self
    {
        // Sanitization ASCII : le firmware iPXE rejette l'ASCII étendu.
        return new self(
            status: EnrollNameStatus::NameTaken,
            sanitizedName: IpxeHostnameSanitizer::sanitizeForIpxeOutput($sanitizedName),
            reasonLabel: IpxeHostnameSanitizer::sanitizeForIpxeOutput('nom deja pris'),
        );
    }

    /**
     * Factory : erreur infrastructure DB.
     */
    public static function dbError(string $sanitizedName, ?string $reason = null): self
    {
        // Sanitization ASCII : le firmware iPXE rejette l'ASCII étendu.
        return new self(
            status: EnrollNameStatus::DbError,
            sanitizedName: IpxeHostnameSanitizer::sanitizeForIpxeOutput($sanitizedName),
            reasonLabel: IpxeHostnameSanitizer::sanitizeForIpxeOutput($reason ?? 'erreur base de donnees'),
        );
    }

    /**
     * Factory : erreur infrastructure AD.
     */
    public static function adError(string $sanitizedName, ?Workstation $workstation = null): self
    {
        // Sanitization ASCII : le firmware iPXE rejette l'ASCII étendu.
        return new self(
            status: EnrollNameStatus::AdError,
            sanitizedName: IpxeHostnameSanitizer::sanitizeForIpxeOutput($sanitizedName),
            workstation: $workstation,
            adResult: false,
            reasonLabel: IpxeHostnameSanitizer::sanitizeForIpxeOutput('AD non synchronise'),
        );
    }
}
