<?php

namespace App\Exceptions;

use App\Services\ErrorLoggerService;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Report or log an exception.
     * Log toutes les exceptions en DB (y compris HttpException 404/403/etc.),
     * puis délègue au parent pour le reporting Laravel standard.
     */
    public function report(Throwable $e): void
    {
        $message = $e->getMessage();

        if ($e instanceof HttpException && empty($message)) {
            $path = request()?->getPathInfo() ?? '';
            $message = "HTTP {$e->getStatusCode()} {$path}";
        }

        app(ErrorLoggerService::class)->log('laravel', $message ?: get_class($e));

        parent::report($e);
    }
}
