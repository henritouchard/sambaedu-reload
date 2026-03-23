<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface AuthGuardInterface
{
    public function handle(Request $request, Closure $next): Response;
}
