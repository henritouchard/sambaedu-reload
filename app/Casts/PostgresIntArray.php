<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class PostgresIntArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value, '{}');
        if ($trimmed === '') {
            return [];
        }
        return array_map('intval', explode(',', $trimmed));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        return '{' . implode(',', array_map('intval', (array) $value)) . '}';
    }
}
