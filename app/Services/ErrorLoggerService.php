<?php

namespace App\Services;

use App\Models\ErrorLog;
use Illuminate\Support\Facades\Log;

class ErrorLoggerService
{
    /**
     * Log une erreur en base de données.
     *
     * La méthode est silencieuse en cas d'échec — le logger ne doit jamais crasher l'app.
     */
    public function log(string $source, string $message): void
    {
        try {
            ErrorLog::create([
                'source' => $source,
                'message' => $message,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('ErrorLoggerService: impossible de logger en DB', [
                'source' => $source,
                'message' => $message,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
