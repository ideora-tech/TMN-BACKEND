<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $kolom = ['biaya_overnight', 'biaya_cancellation', 'biaya_add_drop', 'biaya_cross_cluster'];

    public function up(): void
    {
        Schema::table('penawaran', function (Blueprint $table) {
            foreach ($this->kolom as $kolom) {
                if (!Schema::hasColumn('penawaran', $kolom)) {
                    $table->decimal($kolom, 15, 2)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('penawaran', function (Blueprint $table) {
            $table->dropColumn($this->kolom);
        });
    }
};
