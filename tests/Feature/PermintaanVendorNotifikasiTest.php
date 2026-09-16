<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\PermintaanVendor\PermintaanVendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PermintaanVendorNotifikasiTest extends TestCase
{
    use RefreshDatabase;

    private const PERAN_VENDOR = 'VENDOR_MGMT';
    private const PERAN_LAIN   = 'GUDANG';

    private function setIzin(string $path, string $kodePeran, string $aksi, int $diizinkan): void
    {
        $idMenu = DB::table('menu')->where('path', $path)->value('id_menu');
        if ($idMenu === null) {
            $idMenu = (string) Str::uuid();
            DB::table('menu')->insert([
                'id_menu' => $idMenu, 'nama_menu' => trim($path, '/'), 'path' => $path, 'aktif' => 1, 'dibuat_pada' => now(),
            ]);
        }
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null, 'kode_peran' => $kodePeran,
            'id_menu' => $idMenu, 'aksi' => $aksi, 'diizinkan' => $diizinkan, 'dibuat_pada' => now(),
        ]);
    }

    private function buatPengguna(string $kodePeran): Pengguna
    {
        $this->ensurePerusahaan();
        return Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => $kodePeran, 'username' => 'u_' . Str::random(8),
            'email' => Str::random(8) . '@test.id', 'kata_sandi' => bcrypt('Password123!'), 'aktif' => 1,
        ]);
    }

    private function makePermintaan(): PermintaanVendorModel
    {
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $idKlien, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'KLN-NOTIF', 'nama_klien' => 'Klien Notif', 'dibuat_pada' => now(),
        ]);
        $idProyek = (string) Str::uuid();
        DB::table('proyek')->insert([
            'id_proyek' => $idProyek, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $idKlien,
            'kode_proyek' => 'PRJ-NOTIF', 'nama_proyek' => 'TES NINJA', 'dibuat_pada' => now(),
        ]);
        $idJenis = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idJenis, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'CDD', 'nama_jenis' => 'CDD', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $permintaan = PermintaanVendorModel::create([
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'nomor_permintaan'   => 'PMV-NOTIF-1',
            'id_proyek'          => $idProyek,
            'id_jenis_kendaraan' => $idJenis,
            'jumlah_unit'        => 4,
            'mekanisme'          => 'unit_only',
            'status'             => 'draft',
        ]);
        DB::table('permintaan_vendor_unit')->insert([
            'id_permintaan_unit' => (string) Str::uuid(), 'id_permintaan' => $permintaan->id_permintaan,
            'id_jenis_kendaraan' => $idJenis, 'jumlah_unit' => 4, 'urutan' => 1, 'dibuat_pada' => now(),
        ]);

        return $permintaan;
    }

    private function aktifkanApproval(string $idPenggunaApprover): void
    {
        $idJabatan = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan' => $idJabatan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jabatan' => 'PROCMGR', 'nama_jabatan' => 'Procurement Manager', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $idKaryawan, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_jabatan' => $idJabatan,
            'nik' => 'NIK-' . Str::random(6), 'nama_karyawan' => 'Procurement Manager Test', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('pengguna')->where('id_pengguna', $idPenggunaApprover)->update(['id_karyawan' => $idKaryawan]);

        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'permintaan_vendor', 'nama' => 'Permintaan Vendor', 'mode_resolusi' => 'pinned',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'jabatan', 'id_jabatan' => $idJabatan, 'dibuat_pada' => now(),
        ]);
    }

    private function notifikasiUntuk(string $idPengguna): array
    {
        return DB::table('notifikasi')
            ->where('id_pengguna', $idPengguna)
            ->where('referensi_tipe', 'permintaan_vendor')
            ->orderBy('dibuat_pada')
            ->get()
            ->all();
    }

    public function test_ajukan_approval_mengirim_notifikasi_ke_pemilik_izin_kontrak_vendor(): void
    {
        $this->setIzin('/kontrak-vendor', self::PERAN_VENDOR, 'lihat', 1);
        $this->setIzin('/kontrak-vendor', self::PERAN_LAIN, 'lihat', 0);
        $timVendor = $this->buatPengguna(self::PERAN_VENDOR);
        $orangLain = $this->buatPengguna(self::PERAN_LAIN);

        $permintaan = $this->makePermintaan();
        $this->aktifkanApproval((string) $this->buatPengguna('MANAGER')->id_pengguna);
        $pengaju = $this->actingAsRole('SUPERADMIN');
        $this->postJson("/api/permintaan-vendor/{$permintaan->id_permintaan}/ajukan-approval")->assertStatus(200);

        $notif = $this->notifikasiUntuk((string) $timVendor->id_pengguna);
        $this->assertCount(1, $notif);
        $this->assertSame('Permintaan vendor PMV-NOTIF-1 menunggu approval', $notif[0]->judul);
        $this->assertStringContainsString('TES NINJA', $notif[0]->isi);
        $this->assertStringContainsString('CDD x 4', $notif[0]->isi);
        $this->assertSame('/permintaan-vendor/' . $permintaan->id_permintaan, $notif[0]->link);

        $this->assertCount(0, $this->notifikasiUntuk((string) $orangLain->id_pengguna));
        $this->assertCount(0, $this->notifikasiUntuk((string) $pengaju->id_pengguna));
    }

    public function test_permintaan_disetujui_mengirim_notifikasi_siap_dikontrakkan(): void
    {
        $this->setIzin('/kontrak-vendor', self::PERAN_VENDOR, 'lihat', 1);
        $timVendor = $this->buatPengguna(self::PERAN_VENDOR);

        $permintaan = $this->makePermintaan();
        $this->aktifkanApproval((string) $this->buatPengguna('MANAGER')->id_pengguna);
        $this->actingAsRole('SUPERADMIN');
        $this->postJson("/api/permintaan-vendor/{$permintaan->id_permintaan}/ajukan-approval")->assertStatus(200);

        app(\App\Modules\PermintaanVendor\PermintaanVendorService::class)
            ->terapkanKeputusanApproval((string) $permintaan->id_permintaan, self::PERUSAHAAN_ID, 'disetujui', null);

        $notif = $this->notifikasiUntuk((string) $timVendor->id_pengguna);
        $this->assertCount(2, $notif);
        $this->assertSame('Permintaan vendor PMV-NOTIF-1 siap dikontrakkan', $notif[1]->judul);
        $this->assertStringContainsString('silakan proses kontrak vendor', $notif[1]->isi);
    }

    public function test_permintaan_ditolak_tidak_mengirim_notifikasi_siap_dikontrakkan(): void
    {
        $this->setIzin('/kontrak-vendor', self::PERAN_VENDOR, 'lihat', 1);
        $timVendor = $this->buatPengguna(self::PERAN_VENDOR);

        $permintaan = $this->makePermintaan();
        $this->aktifkanApproval((string) $this->buatPengguna('MANAGER')->id_pengguna);
        $this->actingAsRole('SUPERADMIN');
        $this->postJson("/api/permintaan-vendor/{$permintaan->id_permintaan}/ajukan-approval")->assertStatus(200);

        app(\App\Modules\PermintaanVendor\PermintaanVendorService::class)
            ->terapkanKeputusanApproval((string) $permintaan->id_permintaan, self::PERUSAHAAN_ID, 'ditolak', 'Anggaran belum ada');

        $this->assertCount(1, $this->notifikasiUntuk((string) $timVendor->id_pengguna));
    }

    public function test_izin_kontrak_vendor_saja_boleh_membaca_daftar_permintaan_tapi_tidak_mengubah(): void
    {
        $this->setIzin('/kontrak-vendor', self::PERAN_VENDOR, 'lihat', 1);
        $this->setIzin('/permintaan-vendor', self::PERAN_VENDOR, 'lihat', 0);
        $this->setIzin('/permintaan-vendor', self::PERAN_VENDOR, 'tambah', 0);
        $this->setIzin('/permintaan-vendor', self::PERAN_VENDOR, 'hapus', 0);
        $permintaan = $this->makePermintaan();
        Sanctum::actingAs($this->buatPengguna(self::PERAN_VENDOR), ['*']);

        $this->getJson('/api/permintaan-vendor?status=disetujui')->assertStatus(200);
        $this->getJson("/api/permintaan-vendor/{$permintaan->id_permintaan}")->assertStatus(200);
        $this->deleteJson("/api/permintaan-vendor/{$permintaan->id_permintaan}")->assertStatus(403);
        $this->postJson("/api/permintaan-vendor/{$permintaan->id_permintaan}/ajukan-approval")->assertStatus(403);
    }
}
