<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyCatchallLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'method',
        'path',
        'ip',
        'query_string',
        'referer',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
