<?php
// database/migrations/2026_07_19_100004_add_kategori_to_sparepart_table.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sparepart', function (Blueprint $table) {
            $table->char('id_kategori_sparepart', 36)->nullable()->after('nama');
            $table->index(['id_kategori_sparepart']);
        });
    }

    public function down(): void
    {
        Schema::table('sparepart', function (Blueprint $table) {
            $table->dropIndex(['id_kategori_sparepart']);
            $table->dropColumn('id_kategori_sparepart');
        });
    }
};
