<?php

namespace App\Logging;

use Illuminate\Log\Logger;

class ApplyLimitedTraceFormatter
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            if (method_exists($handler, 'setFormatter')) {
                $handler->setFormatter(new LimitedTraceFormatter(maxFrames: 15));
            }
        }
    }
}
