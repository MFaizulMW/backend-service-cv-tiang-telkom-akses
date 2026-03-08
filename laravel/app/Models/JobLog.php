<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobLog extends Model
{
    protected $table = 'job_logs';

    public $timestamps = false; // We manage created_at manually

    protected $fillable = [
        'job_id',
        'photo_id',
        'event',
        'status',
        'context',
        'created_at',
    ];

    protected $casts = [
        'context'    => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->created_at = now();
        });
    }
}
