<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeleteGuardAuditTest extends TestCase
{
    use RefreshDatabase;

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        return $id;
    }

    private function makeJenisKendaraan(string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id, 'id_perusahaan' => $idPerusahaan,
            'kode_jenis' => 'JK-' . Str::random(6), 'nama_jenis' => 'CDD ' . Str::random(4),
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeArmada(string $idPerusahaan = self::PERUSAHAAN_ID, ?string $idJenisKendaraan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada'          => $id,
            'id_perusahaan'      => $idPerusahaan,
            'id_jenis_kendaraan' => $idJenisKendaraan,
            'nopol'              => 'B ' . random_int(1000, 9999) . ' ' . Str::random(2),
            'status'             => 'tersedia',
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return $id;
    }

    private function makeProyek(string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('proyek')->insert([
            'id_proyek' => $id, 'id_perusahaan' => $idPerusahaan, 'id_klien' => (string) Str::uuid(),
            'kode_proyek' => 'PRJ-' . Str::random(8), 'nama_proyek' => 'Proyek Guard', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeSupir(string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $id,
            'id_perusahaan' => $idPerusahaan,
            'nama'          => 'Supir Guard',
            'no_sim'        => 'SIM-' . Str::random(8),
            'status'        => 'aktif',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeVendor(string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $id, 'id_perusahaan' => $idPerusahaan,
            'kode_vendor' => 'VDR-' . Str::random(8), 'nama_vendor' => 'Vendor Guard',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeKaryawan(string $idPerusahaan = self::PERUSAHAAN_ID, ?string $idJabatan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $id, 'id_perusahaan' => $idPerusahaan, 'id_jabatan' => $idJabatan,
            'nik' => 'NIK-' . Str::random(8), 'nama_karyawan' => 'Karyawan Guard', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeJabatan(string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan' => $id, 'id_perusahaan' => $idPerusahaan,
            'kode_jabatan' => 'JAB-' . Str::random(5), 'nama_jabatan' => 'Jabatan Guard', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_hapus_jenis_kendaraan_yang_dipakai_armada_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJenis = $this->makeJenisKendaraan();
        $this->makeArmada(self::PERUSAHAAN_ID, $idJenis);

        $this->deleteJson("/api/jenis-kendaraan/{$idJenis}")->assertStatus(422);
        $this->assertDatabaseHas('jenis_kendaraan', ['id_jenis_kendaraan' => $idJenis, 'dihapus_pada' => null]);
    }

    public function test_hapus_jenis_kendaraan_tanpa_relasi_tetap_boleh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJenis = $this->makeJenisKendaraan();

        $this->deleteJson("/api/jenis-kendaraan/{$idJenis}")->assertStatus(200);
    }

    public function test_hapus_jenis_kendaraan_milik_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJenis = $this->makeJenisKendaraan($this->makePerusahaanLain());

        $this->deleteJson("/api/jenis-kendaraan/{$idJenis}")->assertStatus(404);
        $this->assertDatabaseHas('jenis_kendaraan', ['id_jenis_kendaraan' => $idJenis, 'dihapus_pada' => null]);
    }

    public function test_hapus_lokasi_yang_dipakai_rute_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idLokasi = (string) Str::uuid();
        DB::table('lokasi')->insert([
            'id_lokasi' => $idLokasi, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_lokasi' => 'Gudang Guard', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('rute')->insert([
            'id_rute' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RUT-' . Str::random(5), 'nama_rute' => 'Rute Guard',
            'id_lokasi_asal' => $idLokasi, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $this->deleteJson("/api/lokasi/{$idLokasi}")->assertStatus(422);
        $this->assertDatabaseHas('lokasi', ['id_lokasi' => $idLokasi, 'dihapus_pada' => null]);
    }

    public function test_hapus_supplier_yang_punya_pembelian_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idSupplier = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier' => $idSupplier, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Supplier Guard', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('pembelian_sparepart')->insert([
            'id_pembelian'      => (string) Str::uuid(),
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'nomor_pengajuan'   => 'PS-GRD-' . Str::random(6),
            'id_supplier'       => $idSupplier,
            'status'            => 'lunas',
            'total_estimasi'    => 100000,
            'tanggal_pengajuan' => now()->toDateString(),
            'dibuat_pada'       => now(),
        ]);

        $this->deleteJson("/api/supplier/{$idSupplier}")->assertStatus(422);
        $this->assertDatabaseHas('supplier', ['id_supplier' => $idSupplier, 'dihapus_pada' => null]);
    }

    public function test_hapus_jenis_bbm_yang_dipakai_parameter_bok_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idBbm = (string) Str::uuid();
        DB::table('jenis_bbm')->insert([
            'id_jenis_bbm' => $idBbm, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_bbm' => 'Solar Guard', 'dibuat_pada' => now(),
        ]);
        DB::table('parameter_bok')->insert([
            'id_parameter_bok'       => (string) Str::uuid(),
            'id_perusahaan'          => self::PERUSAHAAN_ID,
            'id_jenis_kendaraan'     => $this->makeJenisKendaraan(),
            'id_jenis_bbm'           => $idBbm,
            'konsumsi_km_per_liter'  => 5,
            'biaya_ban_per_km'       => 500,
            'biaya_servis_per_km'    => 500,
            'biaya_tetap_bulanan'    => 10000000,
            'utilisasi_km_per_bulan' => 5000,
            'margin_persen'          => 10,
            'aktif'                  => 1,
            'dibuat_pada'            => now(),
        ]);

        $this->deleteJson("/api/jenis-bbm/{$idBbm}")->assertStatus(422);
        $this->assertDatabaseHas('jenis_bbm', ['id_jenis_bbm' => $idBbm, 'dihapus_pada' => null]);
    }

    public function test_hapus_tipe_pembayaran_yang_dipakai_invoice_vendor_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idTipe = (string) Str::uuid();
        DB::table('tipe_pembayaran')->insert([
            'id_tipe_pembayaran' => $idTipe, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_tipe' => 'cash_guard', 'nama_tipe' => 'Cash Guard', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('invoice_vendor')->insert([
            'id_invoice_vendor' => (string) Str::uuid(),
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'id_vendor'         => $this->makeVendor(),
            'nomor_invoice'     => 'INV-' . Str::random(10),
            'tanggal_invoice'   => now()->toDateString(),
            'tipe_pembayaran'   => 'cash_guard',
            'dpp'               => 1000000,
            'ppn'               => 0,
            'pph'               => 0,
            'total'             => 1000000,
            'status'            => 'draft',
            'status_pembayaran' => 'belum',
            'dibuat_pada'       => now(),
        ]);

        $this->deleteJson("/api/tipe-pembayaran/{$idTipe}")->assertStatus(422);
        $this->assertDatabaseHas('tipe_pembayaran', ['id_tipe_pembayaran' => $idTipe, 'dihapus_pada' => null]);
    }

    public function test_hapus_armada_yang_punya_penugasan_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idArmada = $this->makeArmada();
        DB::table('penugasan')->insert([
            'id_penugasan' => (string) Str::uuid(), 'id_proyek' => $this->makeProyek(),
            'id_armada' => $idArmada, 'status' => 'selesai', 'sumber' => 'internal', 'dibuat_pada' => now(),
        ]);

        $this->deleteJson("/api/armada/{$idArmada}")->assertStatus(422);
        $this->assertDatabaseHas('armada', ['id_armada' => $idArmada, 'dihapus_pada' => null]);
    }

    public function test_hapus_armada_milik_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idArmada = $this->makeArmada($this->makePerusahaanLain());

        $this->deleteJson("/api/armada/{$idArmada}")->assertStatus(404);
        $this->assertDatabaseHas('armada', ['id_armada' => $idArmada, 'dihapus_pada' => null]);
    }

    public function test_hapus_supir_yang_punya_jadwal_shift_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idSupir = $this->makeSupir();
        $idShift = (string) Str::uuid();
        DB::table('shift')->insert([
            'id_shift' => $idShift, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Pagi Guard', 'jam_mulai' => '08:00:00', 'jam_selesai' => '16:00:00',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('jadwal_shift')->insert([
            'id_jadwal_shift' => (string) Str::uuid(),
            'id_proyek'       => $this->makeProyek(),
            'id_shift'        => $idShift,
            'id_supir'        => $idSupir,
            'tanggal'         => now()->toDateString(),
            'dibuat_pada'     => now(),
        ]);

        $this->deleteJson("/api/supir/{$idSupir}")->assertStatus(422);
        $this->assertDatabaseHas('supir', ['id_supir' => $idSupir, 'dihapus_pada' => null]);
    }

    public function test_hapus_supir_milik_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idSupir = $this->makeSupir($this->makePerusahaanLain());

        $this->deleteJson("/api/supir/{$idSupir}")->assertStatus(404);
        $this->assertDatabaseHas('supir', ['id_supir' => $idSupir, 'dihapus_pada' => null]);
    }

    public function test_hapus_vendor_yang_punya_kontrak_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idVendor = $this->makeVendor();
        DB::table('kontrak_vendor')->insert([
            'id_kontrak_vendor' => (string) Str::uuid(),
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'id_vendor'         => $idVendor,
            'nomor_kontrak'     => 'KV-' . Str::random(8),
            'mekanisme'         => 'unit_only',
            'nilai_kontrak'     => 0,
            'status'            => 'aktif',
            'dibuat_pada'       => now(),
        ]);

        $this->deleteJson("/api/vendor/{$idVendor}")->assertStatus(422);
        $this->assertDatabaseHas('vendor', ['id_vendor' => $idVendor, 'dihapus_pada' => null]);
    }

    public function test_hapus_supir_vendor_dengan_penugasan_aktif_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idVendor = $this->makeVendor();
        $idSupirVendor = (string) Str::uuid();
        DB::table('supir_vendor')->insert([
            'id_supir_vendor' => $idSupirVendor, 'id_vendor' => $idVendor,
            'nama' => 'Supir Vendor Guard', 'dibuat_pada' => now(),
        ]);
        DB::table('penugasan')->insert([
            'id_penugasan' => (string) Str::uuid(), 'id_proyek' => $this->makeProyek(),
            'id_supir_vendor' => $idSupirVendor, 'sumber' => 'vendor', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        $this->deleteJson("/api/supir-vendor/{$idSupirVendor}")->assertStatus(422);
        $this->assertDatabaseHas('supir_vendor', ['id_supir_vendor' => $idSupirVendor, 'dihapus_pada' => null]);
    }

    public function test_hapus_penugasan_milik_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPenugasan = (string) Str::uuid();
        DB::table('penugasan')->insert([
            'id_penugasan' => $idPenugasan, 'id_proyek' => $this->makeProyek($this->makePerusahaanLain()),
            'status' => 'aktif', 'sumber' => 'internal', 'dibuat_pada' => now(),
        ]);

        $this->deleteJson("/api/penugasan/{$idPenugasan}")->assertStatus(404);
        $this->assertDatabaseHas('penugasan', ['id_penugasan' => $idPenugasan, 'dihapus_pada' => null]);
    }

    public function test_hapus_karyawan_yang_punya_absensi_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan();
        DB::table('absensi')->insert([
            'id_absensi' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_karyawan' => $idKaryawan, 'tanggal' => now()->toDateString(),
            'status' => 'hadir', 'dibuat_pada' => now(),
        ]);

        $this->deleteJson("/api/karyawan/{$idKaryawan}")->assertStatus(422);
        $this->assertDatabaseHas('karyawan', ['id_karyawan' => $idKaryawan, 'dihapus_pada' => null]);
    }

    public function test_hapus_karyawan_baru_tanpa_riwayat_tetap_boleh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan();

        $this->deleteJson("/api/karyawan/{$idKaryawan}")->assertStatus(200);
    }

    public function test_hapus_karyawan_milik_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan($this->makePerusahaanLain());

        $this->deleteJson("/api/karyawan/{$idKaryawan}")->assertStatus(404);
        $this->assertDatabaseHas('karyawan', ['id_karyawan' => $idKaryawan, 'dihapus_pada' => null]);
    }

    public function test_hapus_jabatan_yang_dipakai_karyawan_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJabatan = $this->makeJabatan();
        $this->makeKaryawan(self::PERUSAHAAN_ID, $idJabatan);

        $this->deleteJson("/api/jabatan/{$idJabatan}")->assertStatus(422);
        $this->assertDatabaseHas('jabatan', ['id_jabatan' => $idJabatan, 'dihapus_pada' => null]);
    }

    public function test_hapus_jabatan_milik_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJabatan = $this->makeJabatan($this->makePerusahaanLain());

        $this->deleteJson("/api/jabatan/{$idJabatan}")->assertStatus(404);
        $this->assertDatabaseHas('jabatan', ['id_jabatan' => $idJabatan, 'dihapus_pada' => null]);
    }

    public function test_hapus_departemen_yang_punya_jabatan_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idDepartemen = (string) Str::uuid();
        DB::table('departemen')->insert([
            'id_departemen' => $idDepartemen, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_departemen' => 'DEP-' . Str::random(4), 'nama_departemen' => 'Departemen Guard',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idJabatan = $this->makeJabatan();
        DB::table('jabatan')->where('id_jabatan', $idJabatan)->update(['id_departemen' => $idDepartemen]);

        $this->deleteJson("/api/departemen/{$idDepartemen}")->assertStatus(422);
        $this->assertDatabaseHas('departemen', ['id_departemen' => $idDepartemen, 'dihapus_pada' => null]);
    }

    public function test_hapus_pengguna_yang_jadi_approver_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPengguna = (string) Str::uuid();
        DB::table('pengguna')->insert([
            'id_pengguna' => $idPengguna, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => 'KEUANGAN', 'username' => 'approver_guard_' . Str::random(6),
            'email' => Str::random(8) . '@guard.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'faktur', 'nama' => 'Faktur', 'mode_resolusi' => 'pinned',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'pengguna', 'id_pengguna' => $idPengguna, 'dibuat_pada' => now(),
        ]);

        $this->deleteJson("/api/pengguna/{$idPengguna}")->assertStatus(422);
        $this->assertDatabaseHas('pengguna', ['id_pengguna' => $idPengguna, 'dihapus_pada' => null]);
    }

    private function buatPenggunaApproverLewatJabatan(int $aktif = 1): string
    {
        $idJabatan = $this->makeJabatan();
        $idKaryawan = $this->makeKaryawan(self::PERUSAHAAN_ID, $idJabatan);
        $idPengguna = (string) Str::uuid();
        DB::table('pengguna')->insert([
            'id_pengguna' => $idPengguna, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_karyawan' => $idKaryawan,
            'kode_peran' => 'MANAGER', 'username' => 'pejabat_' . Str::random(6),
            'email' => Str::random(8) . '@pejabat.id', 'kata_sandi' => bcrypt('x'), 'aktif' => $aktif, 'dibuat_pada' => now(),
        ]);
        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'penawaran', 'nama' => 'Penawaran', 'mode_resolusi' => 'pinned',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);

        return $idPengguna;
    }

    public function test_hapus_pengguna_pejabat_approver_lewat_jabatan_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPengguna = $this->buatPenggunaApproverLewatJabatan();

        $res = $this->deleteJson("/api/pengguna/{$idPengguna}");

        $res->assertStatus(422);
        $this->assertStringContainsString('pejabat approver', $res->json('message'));
        $this->assertDatabaseHas('pengguna', ['id_pengguna' => $idPengguna, 'dihapus_pada' => null]);
    }

    public function test_hapus_pengguna_pejabat_approver_yang_sudah_nonaktif_tetap_boleh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPengguna = $this->buatPenggunaApproverLewatJabatan(0);

        $this->deleteJson("/api/pengguna/{$idPengguna}")->assertStatus(200);
    }

    public function test_hapus_pengguna_milik_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPengguna = (string) Str::uuid();
        DB::table('pengguna')->insert([
            'id_pengguna' => $idPengguna, 'id_perusahaan' => $this->makePerusahaanLain(),
            'kode_peran' => 'ADMIN', 'username' => 'lain_' . Str::random(6),
            'email' => Str::random(8) . '@lain.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $this->deleteJson("/api/pengguna/{$idPengguna}")->assertStatus(404);
        $this->assertDatabaseHas('pengguna', ['id_pengguna' => $idPengguna, 'dihapus_pada' => null]);
    }

    public function test_hapus_perawatan_milik_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idArmadaLain = $this->makeArmada($this->makePerusahaanLain());
        $idPerawatan = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => $idPerawatan, 'id_armada' => $idArmadaLain,
            'tanggal' => now()->toDateString(), 'jenis_perawatan' => 'Ganti Oli',
            'biaya' => 100000, 'dibuat_pada' => now(),
        ]);

        $this->deleteJson("/api/armada/{$idArmadaLain}/perawatan/{$idPerawatan}", ['alasan' => 'test lintas tenant'])
            ->assertStatus(404);
        $this->assertDatabaseHas('perawatan_armada', ['id_perawatan' => $idPerawatan, 'dihapus_pada' => null]);
    }
}
