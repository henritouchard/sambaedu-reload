<?php

declare(strict_types=1);

namespace App\Doctor;

enum Level: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Error = 'error';
}
