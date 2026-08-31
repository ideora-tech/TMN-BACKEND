<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Kontrak unit_driver/full menyewa unit + driver sebagai satu paket —
     * kolom ini memasangkan unit dengan driver bawaannya (prefill penugasan,
     * tetap bisa di-override saat vendor merotasi drivernya).
     */
    public function up(): void
    {
        Schema::table('armada_vendor', function (Blueprint $table) {
            $table->char('id_supir_vendor_default', 36)->nullable()->after('id_kontrak_vendor')->index();
        });
    }

    public function down(): void
    {
        Schema::table('armada_vendor', function (Blueprint $table) {
            $table->dropColumn('id_supir_vendor_default');
        });
    }
};
