<?php

declare(strict_types=1);

namespace App\Modules\TokenPerangkat;

use App\Models\BaseModel;

class TokenPerangkatModel extends BaseModel
{
    protected $table = 'token_perangkat';
    protected $primaryKey = 'id_token_perangkat';

    protected $fillable = [
        'id_token_perangkat',
        'id_pengguna',
        'token',
        'platform',
    ];
}
