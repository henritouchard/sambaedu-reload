<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ExtensionInstallRun;
use App\Services\Extensions\ExtensionInstallService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 56.3 — L'état d'un run, sans base de données.
 *
 * `isStale()` est la seule règle du modèle qui décide de quelque chose : c'est
 * elle qui empêche un worker tué de condamner la bibliothèque. Elle se calcule
 * CÔTÉ PHP (jamais un `now()` SQL) parce que les sessions PostgreSQL du projet
 * sont en UTC alors que l'application vit à Paris — un verdict décalé de deux
 * heures libérerait, ou gèlerait, l'UI à contretemps.
 */
class ExtensionInstallRunTest extends TestCase
{
    private function makeRun(string $status, ?int $updatedSecondsAgo = 0): ExtensionInstallRun
    {
        $run = new ExtensionInstallRun();
        $run->status = $status;
        $run->updated_at = $updatedSecondsAgo === null ? null : now()->subSeconds($updatedSecondsAgo);

        return $run;
    }

    #[Test]
    public function a_pending_or_running_run_is_active(): void
    {
        self::assertTrue($this->makeRun(ExtensionInstallRun::STATUS_PENDING)->isActive());
        self::assertTrue($this->makeRun(ExtensionInstallRun::STATUS_RUNNING)->isActive());
        self::assertFalse($this->makeRun(ExtensionInstallRun::STATUS_SUCCESS)->isActive());
        self::assertFalse($this->makeRun(ExtensionInstallRun::STATUS_FAILED)->isActive());
    }

    #[Test]
    public function a_fresh_active_run_is_never_stale(): void
    {
        self::assertFalse($this->makeRun(ExtensionInstallRun::STATUS_RUNNING, 10)->isStale(1800));
        self::assertFalse($this->makeRun(ExtensionInstallRun::STATUS_PENDING, 1799)->isStale(1800));
    }

    #[Test]
    public function an_active_run_frozen_beyond_the_timeout_is_stale(): void
    {
        self::assertTrue($this->makeRun(ExtensionInstallRun::STATUS_RUNNING, 1801)->isStale(1800));
    }

    #[Test]
    public function a_terminated_run_is_never_stale_however_old(): void
    {
        // « Interrompu » qualifie un run qui n'a pas atteint son terminus. Un
        // run terminé il y a un mois est simplement… terminé.
        self::assertFalse($this->makeRun(ExtensionInstallRun::STATUS_SUCCESS, 10_000_000)->isStale(1800));
        self::assertFalse($this->makeRun(ExtensionInstallRun::STATUS_FAILED, 10_000_000)->isStale(1800));
    }

    #[Test]
    public function a_run_without_any_timestamp_is_treated_as_stale(): void
    {
        // Fail-safe : sans horodatage, on ne peut PAS affirmer qu'il est vivant
        // — et le gel de l'UI est le pire des deux verdicts.
        self::assertTrue($this->makeRun(ExtensionInstallRun::STATUS_RUNNING, null)->isStale(1800));
    }

    #[Test]
    public function the_operation_vocabulary_is_the_one_of_the_engine(): void
    {
        // Un seul énoncé : les trois opérations sont définies par le moteur et
        // seulement réexposées par le modèle.
        self::assertSame(ExtensionInstallService::OPERATION_INSTALL, ExtensionInstallRun::OPERATION_INSTALL);
        self::assertSame(ExtensionInstallService::OPERATION_UPDATE, ExtensionInstallRun::OPERATION_UPDATE);
        self::assertSame(ExtensionInstallService::OPERATION_REMOVE, ExtensionInstallRun::OPERATION_REMOVE);
        self::assertSame(['install', 'update', 'remove'], ExtensionInstallRun::OPERATIONS);
    }

    #[Test]
    public function every_operation_and_status_has_a_french_label(): void
    {
        foreach (ExtensionInstallRun::OPERATIONS as $operation) {
            $run = new ExtensionInstallRun();
            $run->operation = $operation;
            self::assertNotSame($operation, $run->operationLabel());
        }

        foreach ([
            ExtensionInstallRun::STATUS_PENDING,
            ExtensionInstallRun::STATUS_RUNNING,
            ExtensionInstallRun::STATUS_SUCCESS,
            ExtensionInstallRun::STATUS_FAILED,
        ] as $status) {
            $run = $this->makeRun($status);
            self::assertNotSame($status, $run->statusLabel());
            self::assertStringStartsWith('badge-', $run->statusBadgeClass());
        }
    }

    #[Test]
    public function a_technical_error_category_becomes_a_sentence_and_an_engine_one_stays_verbatim(): void
    {
        $run = new ExtensionInstallRun();

        $run->error = '';
        self::assertSame('', $run->errorLabel());

        $run->error = ExtensionInstallRun::ERROR_UNEXPECTED;
        self::assertStringContainsString('inattendue', $run->errorLabel());

        $run->error = ExtensionInstallRun::ERROR_INTERRUPTED;
        self::assertStringContainsString('interrompue', $run->errorLabel());

        // Les catégories du moteur sont déjà françaises, courtes et dépourvues
        // d'URL : les re-traduire créerait une seconde source de vérité.
        $run->error = 'sha256 du paquet non concordant';
        self::assertSame('sha256 du paquet non concordant', $run->errorLabel());
    }
}
