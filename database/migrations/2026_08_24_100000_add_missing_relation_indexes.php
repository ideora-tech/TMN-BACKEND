<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah index untuk kolom relasi (id_*) yang belum punya index sama sekali.
 * 92 kolom di 52 tabel — hasil audit karena skema ini tidak
 * pakai foreign key constraint (integritas relasi divalidasi di Service layer),
 * sehingga banyak kolom id_* tidak otomatis ke-index oleh MySQL.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('alokasi_armada', function (Blueprint $table) {
            $table->index('id_pemilik_asal', 'idx_alokasi_armada_id_pemilik_asal');
            $table->index('id_proyek', 'idx_alokasi_armada_id_proyek');
        });

        Schema::table('approver_keuangan', function (Blueprint $table) {
            $table->index('id_jabatan', 'idx_approver_keuangan_id_jabatan');
            $table->index('id_pengguna', 'idx_approver_keuangan_id_pengguna');
        });

        Schema::table('armada', function (Blueprint $table) {
            $table->index('id_jenis_kendaraan', 'idx_armada_id_jenis_kendaraan');
            $table->index('id_perusahaan', 'idx_armada_id_perusahaan');
            $table->index('id_vendor', 'idx_armada_id_vendor');
        });

        Schema::table('briefing_supir', function (Blueprint $table) {
            $table->index('id_dibriefing_oleh', 'idx_briefing_supir_id_dibriefing_oleh');
            $table->index('id_penugasan', 'idx_briefing_supir_id_penugasan');
        });

        Schema::table('departemen', function (Blueprint $table) {
            $table->index('id_departemen_induk', 'idx_departemen_id_departemen_induk');
            $table->index('id_perusahaan', 'idx_departemen_id_perusahaan');
        });

        Schema::table('dokumen_armada', function (Blueprint $table) {
            $table->index('id_armada', 'idx_dokumen_armada_id_armada');
        });

        Schema::table('dokumen_vendor', function (Blueprint $table) {
            $table->index('id_vendor', 'idx_dokumen_vendor_id_vendor');
        });

        Schema::table('evaluasi_trip', function (Blueprint $table) {
            $table->index('id_dievaluasi_oleh', 'idx_evaluasi_trip_id_dievaluasi_oleh');
        });

        Schema::table('faktur', function (Blueprint $table) {
            $table->index('id_klien', 'idx_faktur_id_klien');
            $table->index('id_proyek', 'idx_faktur_id_proyek');
        });

        Schema::table('faktur_item', function (Blueprint $table) {
            $table->index('id_faktur', 'idx_faktur_item_id_faktur');
        });

        Schema::table('invoice_vendor', function (Blueprint $table) {
            $table->index('id_kontrak_vendor', 'idx_invoice_vendor_id_kontrak_vendor');
        });

        Schema::table('izin_peran', function (Blueprint $table) {
            $table->index('id_menu', 'idx_izin_peran_id_menu');
            $table->index('id_perusahaan', 'idx_izin_peran_id_perusahaan');
        });

        Schema::table('jabatan', function (Blueprint $table) {
            $table->index('id_departemen', 'idx_jabatan_id_departemen');
            $table->index('id_peran', 'idx_jabatan_id_peran');
            $table->index('id_perusahaan', 'idx_jabatan_id_perusahaan');
        });

        Schema::table('jadwal_keberangkatan', function (Blueprint $table) {
            $table->index('id_rute', 'idx_jadwal_keberangkatan_id_rute');
        });

        Schema::table('jadwal_shift', function (Blueprint $table) {
            $table->index('id_armada_override', 'idx_jadwal_shift_id_armada_override');
            $table->index('id_shift', 'idx_jadwal_shift_id_shift');
            $table->index('id_supir_pengganti', 'idx_jadwal_shift_id_supir_pengganti');
        });

        Schema::table('jenis_bbm', function (Blueprint $table) {
            $table->index('id_perusahaan', 'idx_jenis_bbm_id_perusahaan');
        });

        Schema::table('jenis_perawatan', function (Blueprint $table) {
            $table->index('id_perusahaan', 'idx_jenis_perawatan_id_perusahaan');
        });

        Schema::table('karyawan', function (Blueprint $table) {
            $table->index('id_jabatan', 'idx_karyawan_id_jabatan');
            $table->index('id_lokasi', 'idx_karyawan_id_lokasi');
            $table->index('id_perusahaan', 'idx_karyawan_id_perusahaan');
        });

        Schema::table('karyawan_exit', function (Blueprint $table) {
            $table->index('id_karyawan', 'idx_karyawan_exit_id_karyawan');
            $table->index('id_perusahaan', 'idx_karyawan_exit_id_perusahaan');
        });

        Schema::table('karyawan_lokasi_kantor', function (Blueprint $table) {
            $table->index('id_karyawan', 'idx_karyawan_lokasi_kantor_id_karyawan');
            $table->index('id_lokasi', 'idx_karyawan_lokasi_kantor_id_lokasi');
        });

        Schema::table('klien', function (Blueprint $table) {
            $table->index('id_perusahaan', 'idx_klien_id_perusahaan');
        });

        Schema::table('kontrak_karyawan', function (Blueprint $table) {
            $table->index('id_perusahaan', 'idx_kontrak_karyawan_id_perusahaan');
        });

        Schema::table('kontrak_vendor', function (Blueprint $table) {
            $table->index('id_perusahaan', 'idx_kontrak_vendor_id_perusahaan');
            $table->index('id_proyek', 'idx_kontrak_vendor_id_proyek');
            $table->index('id_vendor', 'idx_kontrak_vendor_id_vendor');
        });

        Schema::table('laporan_perjalanan', function (Blueprint $table) {
            $table->index('id_jenis_bbm', 'idx_laporan_perjalanan_id_jenis_bbm');
            $table->index('id_perusahaan', 'idx_laporan_perjalanan_id_perusahaan');
        });

        Schema::table('laporan_proyek', function (Blueprint $table) {
            $table->index('id_diserahkan_oleh', 'idx_laporan_proyek_id_diserahkan_oleh');
        });

        Schema::table('log_error', function (Blueprint $table) {
            $table->index('id_pengguna', 'idx_log_error_id_pengguna');
        });

        Schema::table('lokasi', function (Blueprint $table) {
            $table->index('id_perusahaan', 'idx_lokasi_id_perusahaan');
        });

        Schema::table('lokasi_kantor', function (Blueprint $table) {
            $table->index('id_perusahaan', 'idx_lokasi_kantor_id_perusahaan');
        });

        Schema::table('menu', function (Blueprint $table) {
            $table->index('id_menu_induk', 'idx_menu_id_menu_induk');
        });

        Schema::table('notifikasi', function (Blueprint $table) {
            $table->index('id_pengguna', 'idx_notifikasi_id_pengguna');
            $table->index('id_perusahaan', 'idx_notifikasi_id_perusahaan');
        });

        Schema::table('paket_perawatan_sparepart', function (Blueprint $table) {
            $table->index('id_sparepart', 'idx_paket_perawatan_sparepart_id_sparepart');
        });

        Schema::table('parameter_bok', function (Blueprint $table) {
            $table->index('id_jenis_bbm', 'idx_parameter_bok_id_jenis_bbm');
        });

        Schema::table('payroll_slip', function (Blueprint $table) {
            $table->index('id_perusahaan', 'idx_payroll_slip_id_perusahaan');
        });

        Schema::table('penawaran', function (Blueprint $table) {
            $table->index('id_klien', 'idx_penawaran_id_klien');
            $table->index('id_penawaran_induk', 'idx_penawaran_id_penawaran_induk');
            $table->index('id_proyek', 'idx_penawaran_id_proyek');
        });

        Schema::table('penawaran_item', function (Blueprint $table) {
            $table->index('id_jenis_kendaraan', 'idx_penawaran_item_id_jenis_kendaraan');
            $table->index('id_perusahaan', 'idx_penawaran_item_id_perusahaan');
            $table->index('id_rute', 'idx_penawaran_item_id_rute');
        });

        Schema::table('pengajuan_approval', function (Blueprint $table) {
            $table->index('id_pengguna', 'idx_pengajuan_approval_id_pengguna');
        });

        Schema::table('pengajuan_cuti', function (Blueprint $table) {
            $table->index('id_jenis_cuti', 'idx_pengajuan_cuti_id_jenis_cuti');
            $table->index('id_perusahaan', 'idx_pengajuan_cuti_id_perusahaan');
        });

        Schema::table('pengajuan_pengeluaran', function (Blueprint $table) {
            $table->index('id_proyek', 'idx_pengajuan_pengeluaran_id_proyek');
            $table->index('id_supir', 'idx_pengajuan_pengeluaran_id_supir');
        });

        Schema::table('pengguna', function (Blueprint $table) {
            $table->index('id_karyawan', 'idx_pengguna_id_karyawan');
            $table->index('id_perusahaan', 'idx_pengguna_id_perusahaan');
        });

        Schema::table('penugasan', function (Blueprint $table) {
            $table->index('id_karyawan', 'idx_penugasan_id_karyawan');
            $table->index('id_kontrak_vendor', 'idx_penugasan_id_kontrak_vendor');
            $table->index('id_proyek', 'idx_penugasan_id_proyek');
            $table->index('id_rute', 'idx_penugasan_id_rute');
        });

        Schema::table('peran', function (Blueprint $table) {
            $table->index('id_perusahaan', 'idx_peran_id_perusahaan');
        });

        Schema::table('perawatan_armada', function (Blueprint $table) {
            $table->index('id_armada', 'idx_perawatan_armada_id_armada');
            $table->index('id_jenis_perawatan', 'idx_perawatan_armada_id_jenis_perawatan');
        });

        Schema::table('perusahaan', function (Blueprint $table) {
            $table->index('id_mata_uang', 'idx_perusahaan_id_mata_uang');
            $table->index('id_zona', 'idx_perusahaan_id_zona');
        });

        Schema::table('proyek', function (Blueprint $table) {
            $table->index('id_klien', 'idx_proyek_id_klien');
            $table->index('id_perusahaan', 'idx_proyek_id_perusahaan');
        });

        Schema::table('proyek_rute', function (Blueprint $table) {
            $table->index('id_jenis_kendaraan', 'idx_proyek_rute_id_jenis_kendaraan');
            $table->index('id_perusahaan', 'idx_proyek_rute_id_perusahaan');
            $table->index('id_rute', 'idx_proyek_rute_id_rute');
        });

        Schema::table('riwayat_jabatan', function (Blueprint $table) {
            $table->index('id_jabatan_baru', 'idx_riwayat_jabatan_id_jabatan_baru');
            $table->index('id_jabatan_lama', 'idx_riwayat_jabatan_id_jabatan_lama');
            $table->index('id_perusahaan', 'idx_riwayat_jabatan_id_perusahaan');
        });

        Schema::table('rute', function (Blueprint $table) {
            $table->index('id_lokasi_asal', 'idx_rute_id_lokasi_asal');
            $table->index('id_lokasi_tujuan', 'idx_rute_id_lokasi_tujuan');
            $table->index('id_perusahaan', 'idx_rute_id_perusahaan');
        });

        Schema::table('saldo_cuti', function (Blueprint $table) {
            $table->index('id_perusahaan', 'idx_saldo_cuti_id_perusahaan');
        });

        Schema::table('shift', function (Blueprint $table) {
            $table->index('id_perusahaan', 'idx_shift_id_perusahaan');
        });

        Schema::table('supir', function (Blueprint $table) {
            $table->index('id_armada_default', 'idx_supir_id_armada_default');
            $table->index('id_pengguna', 'idx_supir_id_pengguna');
        });

        Schema::table('supir_proyek', function (Blueprint $table) {
            $table->index('id_perusahaan', 'idx_supir_proyek_id_perusahaan');
        });

        Schema::table('vendor', function (Blueprint $table) {
            $table->index('id_perusahaan', 'idx_vendor_id_perusahaan');
        });
    }

    public function down(): void
    {
        Schema::table('alokasi_armada', function (Blueprint $table) {
            $table->dropIndex('idx_alokasi_armada_id_pemilik_asal');
            $table->dropIndex('idx_alokasi_armada_id_proyek');
        });

        Schema::table('approver_keuangan', function (Blueprint $table) {
            $table->dropIndex('idx_approver_keuangan_id_jabatan');
            $table->dropIndex('idx_approver_keuangan_id_pengguna');
        });

        Schema::table('armada', function (Blueprint $table) {
            $table->dropIndex('idx_armada_id_jenis_kendaraan');
            $table->dropIndex('idx_armada_id_perusahaan');
            $table->dropIndex('idx_armada_id_vendor');
        });

        Schema::table('briefing_supir', function (Blueprint $table) {
            $table->dropIndex('idx_briefing_supir_id_dibriefing_oleh');
            $table->dropIndex('idx_briefing_supir_id_penugasan');
        });

        Schema::table('departemen', function (Blueprint $table) {
            $table->dropIndex('idx_departemen_id_departemen_induk');
            $table->dropIndex('idx_departemen_id_perusahaan');
        });

        Schema::table('dokumen_armada', function (Blueprint $table) {
            $table->dropIndex('idx_dokumen_armada_id_armada');
        });

        Schema::table('dokumen_vendor', function (Blueprint $table) {
            $table->dropIndex('idx_dokumen_vendor_id_vendor');
        });

        Schema::table('evaluasi_trip', function (Blueprint $table) {
            $table->dropIndex('idx_evaluasi_trip_id_dievaluasi_oleh');
        });

        Schema::table('faktur', function (Blueprint $table) {
            $table->dropIndex('idx_faktur_id_klien');
            $table->dropIndex('idx_faktur_id_proyek');
        });

        Schema::table('faktur_item', function (Blueprint $table) {
            $table->dropIndex('idx_faktur_item_id_faktur');
        });

        Schema::table('invoice_vendor', function (Blueprint $table) {
            $table->dropIndex('idx_invoice_vendor_id_kontrak_vendor');
        });

        Schema::table('izin_peran', function (Blueprint $table) {
            $table->dropIndex('idx_izin_peran_id_menu');
            $table->dropIndex('idx_izin_peran_id_perusahaan');
        });

        Schema::table('jabatan', function (Blueprint $table) {
            $table->dropIndex('idx_jabatan_id_departemen');
            $table->dropIndex('idx_jabatan_id_peran');
            $table->dropIndex('idx_jabatan_id_perusahaan');
        });

        Schema::table('jadwal_keberangkatan', function (Blueprint $table) {
            $table->dropIndex('idx_jadwal_keberangkatan_id_rute');
        });

        Schema::table('jadwal_shift', function (Blueprint $table) {
            $table->dropIndex('idx_jadwal_shift_id_armada_override');
            $table->dropIndex('idx_jadwal_shift_id_shift');
            $table->dropIndex('idx_jadwal_shift_id_supir_pengganti');
        });

        Schema::table('jenis_bbm', function (Blueprint $table) {
            $table->dropIndex('idx_jenis_bbm_id_perusahaan');
        });

        Schema::table('jenis_perawatan', function (Blueprint $table) {
            $table->dropIndex('idx_jenis_perawatan_id_perusahaan');
        });

        Schema::table('karyawan', function (Blueprint $table) {
            $table->dropIndex('idx_karyawan_id_jabatan');
            $table->dropIndex('idx_karyawan_id_lokasi');
            $table->dropIndex('idx_karyawan_id_perusahaan');
        });

        Schema::table('karyawan_exit', function (Blueprint $table) {
            $table->dropIndex('idx_karyawan_exit_id_karyawan');
            $table->dropIndex('idx_karyawan_exit_id_perusahaan');
        });

        Schema::table('karyawan_lokasi_kantor', function (Blueprint $table) {
            $table->dropIndex('idx_karyawan_lokasi_kantor_id_karyawan');
            $table->dropIndex('idx_karyawan_lokasi_kantor_id_lokasi');
        });

        Schema::table('klien', function (Blueprint $table) {
            $table->dropIndex('idx_klien_id_perusahaan');
        });

        Schema::table('kontrak_karyawan', function (Blueprint $table) {
            $table->dropIndex('idx_kontrak_karyawan_id_perusahaan');
        });

        Schema::table('kontrak_vendor', function (Blueprint $table) {
            $table->dropIndex('idx_kontrak_vendor_id_perusahaan');
            $table->dropIndex('idx_kontrak_vendor_id_proyek');
            $table->dropIndex('idx_kontrak_vendor_id_vendor');
        });

        Schema::table('laporan_perjalanan', function (Blueprint $table) {
            $table->dropIndex('idx_laporan_perjalanan_id_jenis_bbm');
            $table->dropIndex('idx_laporan_perjalanan_id_perusahaan');
        });

        Schema::table('laporan_proyek', function (Blueprint $table) {
            $table->dropIndex('idx_laporan_proyek_id_diserahkan_oleh');
        });

        Schema::table('log_error', function (Blueprint $table) {
            $table->dropIndex('idx_log_error_id_pengguna');
        });

        Schema::table('lokasi', function (Blueprint $table) {
            $table->dropIndex('idx_lokasi_id_perusahaan');
        });

        Schema::table('lokasi_kantor', function (Blueprint $table) {
            $table->dropIndex('idx_lokasi_kantor_id_perusahaan');
        });

        Schema::table('menu', function (Blueprint $table) {
            $table->dropIndex('idx_menu_id_menu_induk');
        });

        Schema::table('notifikasi', function (Blueprint $table) {
            $table->dropIndex('idx_notifikasi_id_pengguna');
            $table->dropIndex('idx_notifikasi_id_perusahaan');
        });

        Schema::table('paket_perawatan_sparepart', function (Blueprint $table) {
            $table->dropIndex('idx_paket_perawatan_sparepart_id_sparepart');
        });

        Schema::table('parameter_bok', function (Blueprint $table) {
            $table->dropIndex('idx_parameter_bok_id_jenis_bbm');
        });

        Schema::table('payroll_slip', function (Blueprint $table) {
            $table->dropIndex('idx_payroll_slip_id_perusahaan');
        });

        Schema::table('penawaran', function (Blueprint $table) {
            $table->dropIndex('idx_penawaran_id_klien');
            $table->dropIndex('idx_penawaran_id_penawaran_induk');
            $table->dropIndex('idx_penawaran_id_proyek');
        });

        Schema::table('penawaran_item', function (Blueprint $table) {
            $table->dropIndex('idx_penawaran_item_id_jenis_kendaraan');
            $table->dropIndex('idx_penawaran_item_id_perusahaan');
            $table->dropIndex('idx_penawaran_item_id_rute');
        });

        Schema::table('pengajuan_approval', function (Blueprint $table) {
            $table->dropIndex('idx_pengajuan_approval_id_pengguna');
        });

        Schema::table('pengajuan_cuti', function (Blueprint $table) {
            $table->dropIndex('idx_pengajuan_cuti_id_jenis_cuti');
            $table->dropIndex('idx_pengajuan_cuti_id_perusahaan');
        });

        Schema::table('pengajuan_pengeluaran', function (Blueprint $table) {
            $table->dropIndex('idx_pengajuan_pengeluaran_id_proyek');
            $table->dropIndex('idx_pengajuan_pengeluaran_id_supir');
        });

        Schema::table('pengguna', function (Blueprint $table) {
            $table->dropIndex('idx_pengguna_id_karyawan');
            $table->dropIndex('idx_pengguna_id_perusahaan');
        });

        Schema::table('penugasan', function (Blueprint $table) {
            $table->dropIndex('idx_penugasan_id_karyawan');
            $table->dropIndex('idx_penugasan_id_kontrak_vendor');
            $table->dropIndex('idx_penugasan_id_proyek');
            $table->dropIndex('idx_penugasan_id_rute');
        });

        Schema::table('peran', function (Blueprint $table) {
            $table->dropIndex('idx_peran_id_perusahaan');
        });

        Schema::table('perawatan_armada', function (Blueprint $table) {
            $table->dropIndex('idx_perawatan_armada_id_armada');
            $table->dropIndex('idx_perawatan_armada_id_jenis_perawatan');
        });

        Schema::table('perusahaan', function (Blueprint $table) {
            $table->dropIndex('idx_perusahaan_id_mata_uang');
            $table->dropIndex('idx_perusahaan_id_zona');
        });

        Schema::table('proyek', function (Blueprint $table) {
            $table->dropIndex('idx_proyek_id_klien');
            $table->dropIndex('idx_proyek_id_perusahaan');
        });

        Schema::table('proyek_rute', function (Blueprint $table) {
            $table->dropIndex('idx_proyek_rute_id_jenis_kendaraan');
            $table->dropIndex('idx_proyek_rute_id_perusahaan');
            $table->dropIndex('idx_proyek_rute_id_rute');
        });

        Schema::table('riwayat_jabatan', function (Blueprint $table) {
            $table->dropIndex('idx_riwayat_jabatan_id_jabatan_baru');
            $table->dropIndex('idx_riwayat_jabatan_id_jabatan_lama');
            $table->dropIndex('idx_riwayat_jabatan_id_perusahaan');
        });

        Schema::table('rute', function (Blueprint $table) {
            $table->dropIndex('idx_rute_id_lokasi_asal');
            $table->dropIndex('idx_rute_id_lokasi_tujuan');
            $table->dropIndex('idx_rute_id_perusahaan');
        });

        Schema::table('saldo_cuti', function (Blueprint $table) {
            $table->dropIndex('idx_saldo_cuti_id_perusahaan');
        });

        Schema::table('shift', function (Blueprint $table) {
            $table->dropIndex('idx_shift_id_perusahaan');
        });

        Schema::table('supir', function (Blueprint $table) {
            $table->dropIndex('idx_supir_id_armada_default');
            $table->dropIndex('idx_supir_id_pengguna');
        });

        Schema::table('supir_proyek', function (Blueprint $table) {
            $table->dropIndex('idx_supir_proyek_id_perusahaan');
        });

        Schema::table('vendor', function (Blueprint $table) {
            $table->dropIndex('idx_vendor_id_perusahaan');
        });
    }
};
