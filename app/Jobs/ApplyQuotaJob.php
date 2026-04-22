<?php

namespace App\Jobs;

use App\Models\QuotaAuditLog;
use App\Services\Filesystem\XfsQuotaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job asynchrone pour appliquer un quota sur le filesystem XFS
 * 
 * Ce job est dispatché par XfsQuotaService lors de la création/modification
 * d'une règle de quota. Il permet de ne pas bloquer la requête HTTP.
 */
class ApplyQuotaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        private string $username,
        private string $partition,
        private int $quotaSoftMb,
        private int $quotaHardMb,
        private string $performedBy,
        private ?int $quotaRuleId = null
    ) {
        $this->onQueue('quotas');
    }

    public function handle(XfsQuotaService $quotaService): void
    {
        Log::info('ApplyQuotaJob: Début application quota', [
            'username' => $this->username,
            'partition' => $this->partition,
            'soft_mb' => $this->quotaSoftMb,
            'hard_mb' => $this->quotaHardMb,
        ]);

        $result = $quotaService->applyQuotaToFilesystem(
            $this->username,
            $this->partition,
            $this->quotaSoftMb,
            $this->quotaHardMb
        );

        // Log d'audit de l'application
        QuotaAuditLog::log(
            QuotaAuditLog::ACTION_APPLY,
            $this->performedBy,
            'user',
            $this->username,
            $this->partition,
            null,
            [
                'quota_soft_mb' => $this->quotaSoftMb,
                'quota_hard_mb' => $this->quotaHardMb,
            ],
            $this->quotaRuleId,
            $result['success'],
            $result['error']
        );

        if (!$result['success']) {
            Log::error('ApplyQuotaJob: Échec application quota', [
                'username' => $this->username,
                'partition' => $this->partition,
                'error' => $result['error'],
            ]);

            // Relancer le job si échec
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ApplyQuotaJob: Job échoué définitivement', [
            'username' => $this->username,
            'partition' => $this->partition,
            'error' => $exception->getMessage(),
        ]);

        QuotaAuditLog::log(
            QuotaAuditLog::ACTION_APPLY,
            $this->performedBy,
            'user',
            $this->username,
            $this->partition,
            null,
            [
                'quota_soft_mb' => $this->quotaSoftMb,
                'quota_hard_mb' => $this->quotaHardMb,
            ],
            $this->quotaRuleId,
            false,
            'Job échoué après ' . $this->tries . ' tentatives: ' . $exception->getMessage()
        );
    }
}
