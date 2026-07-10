<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyCatchallLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'source',
        'method',
        'path',
        'ip',
        'machine',
        'user_login',
        'query_string',
        'referer',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
