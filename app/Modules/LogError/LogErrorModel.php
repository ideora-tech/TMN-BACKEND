<?php

declare(strict_types=1);

namespace App\Modules\LogError;

use Illuminate\Database\Eloquent\Model;

class LogErrorModel extends Model
{
    protected $table = 'log_error';
    protected $primaryKey = 'id_log_error';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id_log_error',
        'level',
        'pesan',
        'stack_trace',
        'metode_http',
        'jalur',
        'kode_status',
        'id_pengguna',
        'dibuat_pada',
    ];
}
