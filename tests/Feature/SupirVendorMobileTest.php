<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\ArmadaVendor\ArmadaVendorModel;
use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupirVendorMobileTest extends TestCase
{
    use RefreshDatabase;

    private const ID_MENU_TRIP = 'm0000001-0000-4000-8000-000000000777';

    private function actingAsSupirVendor(): object
    {
        $this->ensurePerusahaan();

        $pengguna = Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => 'SUPIR_VENDOR',
            'username'      => 'sv_' . Str::random(8),
            'email'         => Str::random(8) . '@vendor.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);

        $vendor = VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Mobile Test',
        ]);
        $kontrak = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_driver',
        ]);
        $armadaVendor = ArmadaVendorModel::create([
            'id_vendor' => $vendor->id_vendor,
            'nopol'     => 'B 7001 VM',
        ]);

        $idSupirVendor = (string) Str::uuid();
        DB::table('supir_vendor')->insert([
            'id_supir_vendor' => $idSupirVendor,
            'id_vendor'       => $vendor->id_vendor,
            'id_pengguna'     => $pengguna->id_pengguna,
            'nama'            => 'Driver Vendor Mobile',
            'dibuat_pada'     => now(),
        ]);

        DB::table('menu')->insertOrIgnore([
            'id_menu'     => self::ID_MENU_TRIP,
            'nama_menu'   => 'Trip Monitor',
            'path'        => '/trip',
            'aktif'       => 1,
            'dibuat_pada' => now(),
        ]);
        foreach (['lihat', 'tambah'] as $aksi) {
            DB::table('izin_peran')->insertOrIgnore([
                'id_izin'     => (string) Str::uuid(),
                'kode_peran'  => 'SUPIR_VENDOR',
                'id_menu'     => self::ID_MENU_TRIP,
                'aksi'        => $aksi,
                'diizinkan'   => 1,
                'dibuat_pada' => now(),
            ]);
        }

        Sanctum::actingAs($pengguna, ['*']);

        return (object) [
            'pengguna'         => $pengguna,
            'id_vendor'        => $vendor->id_vendor,
            'id_kontrak'       => $kontrak->id_kontrak_vendor,
            'id_armada_vendor' => $armadaVendor->id_armada_vendor,
            'id_supir_vendor'  => $idSupirVendor,
        ];
    }

    private function makeProyek(): ProyekModel
    {
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $idKlien,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Vendor Mobile',
            'dibuat_pada'   => now(),
        ]);

        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Vendor Mobile',
        ]);
    }

    private function makePenugasanVendor(object $ctx, string $idProyek, ?string $tanggal = null): PenugasanModel
    {
        return PenugasanModel::create([
            'id_proyek'         => $idProyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $ctx->id_kontrak,
            'id_armada_vendor'  => $ctx->id_armada_vendor,
            'id_supir_vendor'   => $ctx->id_supir_vendor,
            'status'            => 'aktif',
            'tanggal_tugas'     => $tanggal ?? now()->toDateString(),
            'estimasi_biaya'    => 500000,
        ]);
    }

    public function test_jadwal_saya_menampilkan_penugasan_vendor_tanpa_uang_jalan(): void
    {
        $ctx = $this->actingAsSupirVendor();
        $proyek = $this->makeProyek();
        $this->makePenugasanVendor($ctx, $proyek->id_proyek);

        $hariIni = now()->toDateString();
        $this->getJson("/api/trip/jadwal-saya?dari={$hariIni}&sampai={$hariIni}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.armada.nopol', 'B 7001 VM')
            ->assertJsonPath('data.0.uang_jalan', null);
    }

    public function test_supir_vendor_bisa_mulai_trip_tanpa_absen(): void
    {
        $ctx = $this->actingAsSupirVendor();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasanVendor($ctx, $proyek->id_proyek);

        $this->postJson('/api/trip/mulai-saya', ['id_penugasan' => $penugasan->id_penugasan])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'berjalan');
    }

    public function test_supir_vendor_bisa_batalkan_trip_dengan_alasan(): void
    {
        $ctx = $this->actingAsSupirVendor();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasanVendor($ctx, $proyek->id_proyek);

        $idTrip = $this->postJson('/api/trip/mulai-saya', ['id_penugasan' => $penugasan->id_penugasan])
            ->json('data.id_trip');

        $this->postJson("/api/trip/{$idTrip}/batalkan-saya", ['alasan' => 'Unit rusak'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'dibatalkan');
    }

    public function test_supir_vendor_tidak_bisa_akses_penugasan_supir_internal(): void
    {
        $ctx = $this->actingAsSupirVendor();
        $proyek = $this->makeProyek();

        $idSupirInternal = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $idSupirInternal,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Supir Internal Lain',
            'status'        => 'aktif',
            'dibuat_pada'   => now(),
        ]);
        $penugasanInternal = PenugasanModel::create([
            'id_proyek'     => $proyek->id_proyek,
            'id_supir'      => $idSupirInternal,
            'status'        => 'aktif',
            'tanggal_tugas' => now()->toDateString(),
        ]);

        $this->getJson("/api/trip/penugasan-saya/{$penugasanInternal->id_penugasan}")
            ->assertStatus(403);
    }

    public function test_detail_penugasan_vendor_menyembunyikan_uang_jalan(): void
    {
        $ctx = $this->actingAsSupirVendor();
        $proyek = $this->makeProyek();
        $penugasan = $this->makePenugasanVendor($ctx, $proyek->id_proyek);

        $this->getJson("/api/trip/penugasan-saya/{$penugasan->id_penugasan}")
            ->assertStatus(200)
            ->assertJsonPath('data.uang_jalan', null)
            ->assertJsonPath('data.armada_hari_ini.nopol', 'B 7001 VM');
    }

    public function test_absensi_supir_vendor_hari_ini_null_dan_absen_ditolak(): void
    {
        $this->actingAsSupirVendor();

        $this->getJson('/api/absensi-supir/hari-ini-saya')
            ->assertStatus(200)
            ->assertJsonPath('data', null);

        $this->postJson('/api/absensi-supir', ['status' => 'hadir'])
            ->assertStatus(403);
    }

    public function test_supir_me_mengembalikan_profil_vendor(): void
    {
        $ctx = $this->actingAsSupirVendor();

        DB::table('menu')->insertOrIgnore([
            'id_menu'     => 'm0000001-0000-4000-8000-000000000778',
            'nama_menu'   => 'Supir',
            'path'        => '/supir',
            'aktif'       => 1,
            'dibuat_pada' => now(),
        ]);
        DB::table('izin_peran')->insertOrIgnore([
            'id_izin'     => (string) Str::uuid(),
            'kode_peran'  => 'SUPIR_VENDOR',
            'id_menu'     => 'm0000001-0000-4000-8000-000000000778',
            'aksi'        => 'lihat',
            'diizinkan'   => 1,
            'dibuat_pada' => now(),
        ]);

        $this->getJson('/api/supir/me')
            ->assertStatus(200)
            ->assertJsonPath('data.nama', 'Driver Vendor Mobile')
            ->assertJsonPath('data.tipe_supir', 'vendor');
    }
}
