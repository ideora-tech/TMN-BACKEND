<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $kolom = ['tol_per_trip', 'bbm_per_trip', 'biaya_lain_per_trip'];

    public function up(): void
    {
        Schema::table('uang_jalan', function (Blueprint $table) {
            foreach ($this->kolom as $nama) {
                if (!Schema::hasColumn('uang_jalan', $nama)) {
                    $table->decimal($nama, 15, 2)->default(0);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('uang_jalan', function (Blueprint $table) {
            foreach ($this->kolom as $nama) {
                if (Schema::hasColumn('uang_jalan', $nama)) {
                    $table->dropColumn($nama);
                }
            }
        });
    }
};
