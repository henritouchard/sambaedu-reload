<?php

declare(strict_types=1);

namespace App\Gpo\Dto;

use App\Gpo\Enums\WpkgGpoSyncSeverity;
use DateTimeImmutable;
use JsonSerializable;

/**
 * Rapport de cohérence GPO ↔ pipeline WPKG — Story 16.6 (AC1.1).
 *
 * Photographie immutable d'un appel {@see \App\Gpo\Services\WpkgGpoSynchronizer::audit()}.
 * Aucun side effect : ce DTO est servi en lecture seule à la page Livewire,
 * à la commande artisan (`--json`) et aux tests.
 *
 * Le DTO porte trois informations majeures :
 *  1. **État GPO** : existe ? GUID ? path SYSVOL ? liée à au moins une OU ?
 *  2. **URLs attendues** : résolues côté serveur via `URL::route('wpkg.*-xml')`
 *     (single source of truth de Story 15.2).
 *  3. **Diagnostic Bearer** : couverture des postes liés (lecture seule, DO2).
 */
final readonly class WpkgGpoSyncReport implements JsonSerializable
{
    /**
     * @param  list<string>             $linkedOus           DNs des OUs liées (vide si non lié).
     * @param  array<string,bool>       $bearerCoverage      `workstation_name => has_active_secret`.
     * @param  list<string>             $detectedPlaceholders Placeholders `###_*_###` trouvés dans le template.
     * @param  list<string>             $unknownPlaceholders Placeholders trouvés mais hors whitelist.
     * @param  list<string>             $messages            Diagnostics humains (warnings/errors).
     */
    public function __construct(
        public bool $gpoExists,
        public ?string $gpoGuid,
        public ?string $gpoDisplayName,
        public ?string $gpoPath,
        public array $linkedOus,
        public string $expectedHostsXmlUrl,
        public string $expectedProfilesXmlUrl,
        public string $templatePath,
        public bool $templateExists,
        public ?DateTimeImmutable $templateLastModified,
        public array $detectedPlaceholders,
        public array $unknownPlaceholders,
        public array $bearerCoverage,
        public bool $bearerTableAvailable,
        public WpkgGpoSyncSeverity $severity,
        public array $messages,
        public ?string $operationId = null,
    ) {}

    public function isLinked(): bool
    {
        return $this->linkedOus !== [];
    }

    public function bearerCoverageRatio(): ?float
    {
        if (! $this->bearerTableAvailable || $this->bearerCoverage === []) {
            return null;
        }
        $covered = count(array_filter($this->bearerCoverage));
        return $covered / count($this->bearerCoverage);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'gpoExists' => $this->gpoExists,
            'gpoGuid' => $this->gpoGuid,
            'gpoDisplayName' => $this->gpoDisplayName,
            'gpoPath' => $this->gpoPath,
            'linkedOus' => $this->linkedOus,
            'expectedHostsXmlUrl' => $this->expectedHostsXmlUrl,
            'expectedProfilesXmlUrl' => $this->expectedProfilesXmlUrl,
            'templatePath' => $this->templatePath,
            'templateExists' => $this->templateExists,
            'templateLastModified' => $this->templateLastModified?->format(\DateTimeInterface::ATOM),
            'detectedPlaceholders' => $this->detectedPlaceholders,
            'unknownPlaceholders' => $this->unknownPlaceholders,
            'bearerCoverage' => $this->bearerCoverage,
            'bearerTableAvailable' => $this->bearerTableAvailable,
            'severity' => $this->severity->value,
            'messages' => $this->messages,
            'operationId' => $this->operationId,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
