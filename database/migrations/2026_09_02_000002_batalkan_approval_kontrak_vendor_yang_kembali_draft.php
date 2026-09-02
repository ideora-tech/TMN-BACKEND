<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Perbaikan data: pengajuan approval kontrak_vendor berstatus menunggu/disetujui
 * yang kontraknya sudah kembali ke draft (diedit sebelum fix pembatalan otomatis
 * dirilis) — dibatalkan supaya Log Approval tidak menampilkan "Disetujui" untuk
 * kontrak yang butuh approval ulang. Kejadian baru sudah ditangani di
 * KontrakVendorService (update komitmen & tarikApprovalKarenaUnit).
 */
return new class extends Migration
{
    public function up(): void
    {
        $idEventTypes = DB::table('approval_event_type')
            ->where('kode', 'kontrak_vendor')
            ->pluck('id_event_type');

        if ($idEventTypes->isEmpty()) {
            return;
        }

        $stale = DB::table('approval_pengajuan as ap')
            ->join('kontrak_vendor as kv', 'kv.id_kontrak_vendor', '=', 'ap.id_referensi')
            ->whereIn('ap.id_event_type', $idEventTypes)
            ->whereIn('ap.status', ['menunggu', 'disetujui'])
            ->whereNull('ap.dihapus_pada')
            ->where('kv.status', 'draft')
            ->whereNull('kv.dihapus_pada')
            ->pluck('ap.id_approval');

        foreach ($stale as $idApproval) {
            DB::table('approval_keputusan')
                ->where('id_approval', $idApproval)
                ->whereNull('dihapus_pada')
                ->update(['dihapus_pada' => now()]);

            DB::table('approval_pengajuan')
                ->where('id_approval', $idApproval)
                ->update(['status' => 'dibatalkan', 'diubah_pada' => now()]);
        }
    }

    public function down(): void
    {
        // Perbaikan data satu arah — tidak bisa dibedakan lagi mana yang tadinya stale.
    }
};
