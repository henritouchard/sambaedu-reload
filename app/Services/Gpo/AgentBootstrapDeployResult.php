<?php

declare(strict_types=1);

namespace App\Services\Gpo;

/**
 * Résultat structuré d'un déploiement du bootstrap agent
 * ({@see AgentBootstrapPublisher::deploy()}).
 *
 * Distingue explicitement `skipped` (garde fail-soft : creds/DC absents — NE FAIT
 * PAS échouer install/update) de `failed` (erreur réelle pendant la publication).
 * La commande artisan mappe `skipped`/`dry-run`/`deployed`/`published_without_link`
 * → exit 0 ; seul `failed` → exit non nul (mais la commande l'avale aussi en
 * fail-soft, cf. Tâche 4).
 */
final readonly class AgentBootstrapDeployResult
{
    public const KIND_DEPLOYED = 'deployed';
    public const KIND_PUBLISHED_WITHOUT_LINK = 'published_without_link';
    public const KIND_SKIPPED = 'skipped';
    public const KIND_DRY_RUN = 'dry-run';
    public const KIND_FAILED = 'failed';

    /**
     * @param  string       $kind          Une des constantes KIND_*.
     * @param  string|null  $guid          GUID de la GPO publiée (si applicable).
     * @param  string|null  $targetOuDn    DN de l'OU computers cible (lien + blocage héritage).
     * @param  string       $message       Message lisible (raison du skip, erreur, etc.).
     * @param  string       $operationId   ID d'opération (corrélation logs GpoLogger).
     */
    private function __construct(
        public string $kind,
        public ?string $guid,
        public ?string $targetOuDn,
        public string $message,
        public string $operationId,
    ) {}

    public static function deployed(string $guid, string $ouDn, string $operationId): self
    {
        return new self(self::KIND_DEPLOYED, $guid, $ouDn, 'GPO publiée, héritage bloqué et liée à l\'OU établissement.', $operationId);
    }

    public static function publishedWithoutLink(string $guid, string $operationId): self
    {
        return new self(self::KIND_PUBLISHED_WITHOUT_LINK, $guid, null, 'GPO publiée, mais aucune OU computers cible détectée (lien/héritage non appliqués).', $operationId);
    }

    public static function skipped(string $reason, string $operationId): self
    {
        return new self(self::KIND_SKIPPED, null, null, $reason, $operationId);
    }

    public static function dryRun(?string $ouDn, string $operationId): self
    {
        return new self(self::KIND_DRY_RUN, null, $ouDn, 'Dry-run : aucune écriture effectuée.', $operationId);
    }

    public static function failed(string $message, string $operationId): self
    {
        return new self(self::KIND_FAILED, null, null, $message, $operationId);
    }

    public function isSkipped(): bool
    {
        return $this->kind === self::KIND_SKIPPED;
    }

    public function isFailed(): bool
    {
        return $this->kind === self::KIND_FAILED;
    }
}
