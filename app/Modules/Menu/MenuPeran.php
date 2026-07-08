<?php

declare(strict_types=1);

namespace App\Modules\Menu;

use Illuminate\Database\Eloquent\Model;

class MenuPeran extends Model
{
    protected $table = 'menu_peran';
    public $timestamps = false;
    public $incrementing = false;

    protected $fillable = ['id_menu', 'kode_peran'];
}
