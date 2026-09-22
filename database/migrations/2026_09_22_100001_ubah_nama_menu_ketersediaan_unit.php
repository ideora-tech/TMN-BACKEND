<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idMenu = 'm0000001-0000-4000-8000-000000000097';

    public function up(): void
    {
        DB::table('menu')->where('id_menu', $this->idMenu)->update(['nama_menu' => 'Ketersediaan Unit']);
    }

    public function down(): void
    {
        DB::table('menu')->where('id_menu', $this->idMenu)->update(['nama_menu' => 'Ketersediaan Vendor']);
    }
};
