<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('menu')->where('id_menu', 'm0000002-0000-4000-8000-000000000001')->update(['icon' => 'fileText']);
        DB::table('menu')->where('id_menu', 'm0000002-0000-4000-8000-000000000002')->update(['icon' => 'wrench']);
    }

    public function down(): void
    {
        DB::table('menu')->where('id_menu', 'm0000002-0000-4000-8000-000000000001')->update(['icon' => 'coins']);
        DB::table('menu')->where('id_menu', 'm0000002-0000-4000-8000-000000000002')->update(['icon' => 'calculator']);
    }
};
