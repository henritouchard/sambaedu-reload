<?php

namespace App\Logging;

use Monolog\Formatter\LineFormatter;

class LimitedTraceFormatter extends LineFormatter
{
    public function __construct(private int $maxFrames = 10)
    {
        parent::__construct(includeStacktraces: true);
    }

    protected function normalizeException(\Throwable $e, int $depth = 0): string
    {
        $full = parent::normalizeException($e, $depth);

        $stacktracePos = strpos($full, '[stacktrace]');
        if ($stacktracePos === false) {
            return $full;
        }

        $header = substr($full, 0, $stacktracePos + strlen('[stacktrace]') + 1);
        $traceStr = substr($full, $stacktracePos + strlen('[stacktrace]') + 1);

        // Chaque frame commence par "#N "
        $frames = preg_split('/(?=^#\d)/m', $traceStr, -1, PREG_SPLIT_NO_EMPTY);

        if (count($frames) <= $this->maxFrames) {
            return $full;
        }

        $kept = array_slice($frames, 0, $this->maxFrames);
        $skipped = count($frames) - $this->maxFrames;

        return $header . implode('', $kept) . "... {$skipped} frame(s) omis. Pour ajuster:  app/Logging/ApplyLimitedTraceFormatter.php, \"maxFrames\"\n";
    }
}
