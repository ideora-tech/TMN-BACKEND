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

class JadwalShiftSupirVendorTest extends TestCase
{
    use RefreshDatabase;

    private const ID_MENU_TRIP = 'm0000001-0000-4000-8000-000000000777';

    private function makeProyek(?string $idPerusahaan = null): ProyekModel
    {
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $idKlien,
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Shift Vendor',
            'dibuat_pada'   => now(),
        ]);

        return ProyekModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Shift Vendor Test',
        ]);
    }

    private function makeShift(string $nama = 'Pagi', string $mulai = '08:00:00', string $selesai = '16:00:00'): string
    {
        $id = (string) Str::uuid();
        DB::table('shift')->insert([
            'id_shift' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => $nama, 'jam_mulai' => $mulai, 'jam_selesai' => $selesai,
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeVendor(?string $idPerusahaan = null, string $nama = 'Vendor Shift Test'): string
    {
        $vendor = VendorModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => $nama,
        ]);
        return (string) $vendor->id_vendor;
    }

    private function makeSupirVendor(string $idVendor, string $nama = 'Driver Vendor', int $aktif = 1, ?string $dihapusPada = null): string
    {
        $id = (string) Str::uuid();
        DB::table('supir_vendor')->insert([
            'id_supir_vendor' => $id,
            'id_vendor'       => $idVendor,
            'nama'            => $nama,
            'aktif'           => $aktif,
            'dibuat_pada'     => now(),
            'dihapus_pada'    => $dihapusPada,
        ]);
        return $id;
    }

    private function makeSupirInternal(string $nama = 'Budi Internal'): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => $nama, 'no_sim' => 'SIM-' . Str::random(8),
            'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeSupirProyek(string $idProyek, string $idSupir): void
    {
        DB::table('supir_proyek')->insert([
            'id_supir_proyek' => (string) Str::uuid(),
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'id_proyek'       => $idProyek,
            'id_supir'        => $idSupir,
            'dibuat_pada'     => now(),
        ]);
    }

    private function actingAsSupirVendorMobile(): object
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

        $idVendor = $this->makeVendor(nama: 'Vendor Shift Mobile');
        $kontrak = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $idVendor,
            'mekanisme'     => 'unit_driver',
        ]);
        $armadaVendor = ArmadaVendorModel::create([
            'id_vendor' => $idVendor,
            'nopol'     => 'B 7002 VS',
        ]);

        $idSupirVendor = $this->makeSupirVendor($idVendor, 'Driver Vendor Shift');
        DB::table('supir_vendor')->where('id_supir_vendor', $idSupirVendor)->update(['id_pengguna' => $pengguna->id_pengguna]);

        DB::table('menu')->insertOrIgnore([
            'id_menu'     => self::ID_MENU_TRIP,
            'nama_menu'   => 'Trip Monitor',
            'path'        => '/trip',
            'aktif'       => 1,
            'dibuat_pada' => now(),
        ]);
        DB::table('izin_peran')->insertOrIgnore([
            'id_izin'     => (string) Str::uuid(),
            'kode_peran'  => 'SUPIR_VENDOR',
            'id_menu'     => self::ID_MENU_TRIP,
            'aksi'        => 'lihat',
            'diizinkan'   => 1,
            'dibuat_pada' => now(),
        ]);

        Sanctum::actingAs($pengguna, ['*']);

        return (object) [
            'pengguna'         => $pengguna,
            'id_vendor'        => $idVendor,
            'id_kontrak'       => $kontrak->id_kontrak_vendor,
            'id_armada_vendor' => $armadaVendor->id_armada_vendor,
            'id_supir_vendor'  => $idSupirVendor,
        ];
    }

    public function test_batch_supir_vendor_sukses_tanpa_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $shift = $this->makeShift();
        $idVendor = $this->makeVendor();
        $idSupirVendor = $this->makeSupirVendor($idVendor);

        $res = $this->postJson('/api/jadwal-shift', [
            'id_proyek'    => $proyek->id_proyek,
            'id_shift'     => $shift,
            'tanggal'      => '2026-09-10',
            'supir_vendor' => [$idSupirVendor],
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.sukses', 1)
            ->assertJsonPath('data.gagal', []);

        $row = DB::table('jadwal_shift')->whereNull('dihapus_pada')->first();
        $this->assertSame($idSupirVendor, $row->id_supir_vendor);
        $this->assertNull($row->id_supir);
        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->count());
    }

    public function test_batch_vendor_tanggal_sama_kena_konflik_lintas_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyekA = $this->makeProyek();
        $proyekB = $this->makeProyek();
        $shiftPagi  = $this->makeShift('Pagi');
        $shiftMalam = $this->makeShift('Malam', '20:00:00', '04:00:00');
        $idVendor = $this->makeVendor();
        $idSupirVendor = $this->makeSupirVendor($idVendor);

        $this->postJson('/api/jadwal-shift', [
            'id_proyek' => $proyekA->id_proyek, 'id_shift' => $shiftPagi,
            'tanggal' => '2026-09-10', 'supir_vendor' => [$idSupirVendor],
        ])->assertJsonPath('data.sukses', 1);

        $res = $this->postJson('/api/jadwal-shift', [
            'id_proyek' => $proyekB->id_proyek, 'id_shift' => $shiftMalam,
            'tanggal' => '2026-09-10', 'supir_vendor' => [$idSupirVendor],
        ]);

        $res->assertStatus(200)->assertJsonPath('data.sukses', 0);
        $this->assertCount(1, $res->json('data.gagal'));
        $this->assertSame($idSupirVendor, $res->json('data.gagal.0.id_supir_vendor'));
        $this->assertStringContainsString('sudah dijadwalkan', $res->json('data.gagal.0.alasan'));
        $this->assertSame(1, DB::table('jadwal_shift')->whereNull('dihapus_pada')->count());
    }

    public function test_batch_supir_vendor_nonaktif_ditolak_per_item(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $shift = $this->makeShift();
        $idVendor = $this->makeVendor();
        $idNonaktif = $this->makeSupirVendor($idVendor, 'Driver Nonaktif', aktif: 0);

        $res = $this->postJson('/api/jadwal-shift', [
            'id_proyek'    => $proyek->id_proyek,
            'id_shift'     => $shift,
            'tanggal'      => '2026-09-10',
            'supir_vendor' => [$idNonaktif],
        ]);

        $res->assertStatus(200)->assertJsonPath('data.sukses', 0);
        $this->assertStringContainsString('tidak ditemukan/nonaktif', $res->json('data.gagal.0.alasan'));
    }

    public function test_list_memuat_baris_vendor_bersama_baris_internal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $shift = $this->makeShift();
        $idVendor = $this->makeVendor();
        $idSupirVendor = $this->makeSupirVendor($idVendor);
        $idSupirInternal = $this->makeSupirInternal();
        $this->makeSupirProyek($proyek->id_proyek, $idSupirInternal);

        $this->postJson('/api/jadwal-shift', [
            'id_proyek'    => $proyek->id_proyek,
            'id_shift'     => $shift,
            'tanggal'      => '2026-09-10',
            'supir'        => [$idSupirInternal],
            'supir_vendor' => [$idSupirVendor],
        ])->assertJsonPath('data.sukses', 2);

        $list = $this->getJson("/api/jadwal-shift?id_proyek={$proyek->id_proyek}&dari=2026-09-01&sampai=2026-09-30");
        $list->assertStatus(200);
        $data = collect($list->json('data'));
        $this->assertCount(2, $data);

        $barisVendor = $data->firstWhere('id_supir_vendor', $idSupirVendor);
        $this->assertNotNull($barisVendor);
        $this->assertNull($barisVendor['id_supir']);
        $this->assertSame('Pagi', $barisVendor['shift_nama']);
        $this->assertNull($barisVendor['status_trip']);
        $this->assertSame([], $barisVendor['trips']);

        $barisInternal = $data->firstWhere('id_supir', $idSupirInternal);
        $this->assertNotNull($barisInternal);
        $this->assertNull($barisInternal['id_supir_vendor']);
    }

    public function test_delete_jadwal_vendor_soft_delete(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $shift = $this->makeShift();
        $idVendor = $this->makeVendor();
        $idSupirVendor = $this->makeSupirVendor($idVendor);

        $this->postJson('/api/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shift,
            'tanggal' => '2026-09-10', 'supir_vendor' => [$idSupirVendor],
        ])->assertJsonPath('data.sukses', 1);
        $idJadwal = (string) DB::table('jadwal_shift')->value('id_jadwal_shift');

        $this->deleteJson("/api/jadwal-shift/{$idJadwal}")->assertStatus(200);
        $this->assertSoftDeleted('jadwal_shift', ['id_jadwal_shift' => $idJadwal]);
    }

    public function test_update_baris_vendor_boleh_ganti_shift_tapi_tolak_override(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $shiftPagi  = $this->makeShift('Pagi');
        $shiftMalam = $this->makeShift('Malam', '20:00:00', '04:00:00');
        $idVendor = $this->makeVendor();
        $idSupirVendor = $this->makeSupirVendor($idVendor);
        $idSupirInternal = $this->makeSupirInternal('Calon Pengganti');

        $this->postJson('/api/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shiftPagi,
            'tanggal' => '2026-09-10', 'supir_vendor' => [$idSupirVendor],
        ])->assertJsonPath('data.sukses', 1);
        $idJadwal = (string) DB::table('jadwal_shift')->value('id_jadwal_shift');

        $this->putJson("/api/jadwal-shift/{$idJadwal}", ['id_shift' => $shiftMalam])
            ->assertStatus(200)
            ->assertJsonPath('data.shift_nama', 'Malam')
            ->assertJsonPath('data.id_supir_vendor', $idSupirVendor);

        $res = $this->putJson("/api/jadwal-shift/{$idJadwal}", [
            'id_shift'           => $shiftMalam,
            'id_supir_pengganti' => $idSupirInternal,
        ]);
        $res->assertStatus(422);
        $this->assertStringContainsString('hanya untuk supir internal', (string) $res->json('message'));
    }

    public function test_opsi_supir_vendor_hanya_aktif_dan_tenant_scoped(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idVendor = $this->makeVendor(nama: 'Vendor Milik Sendiri');
        $idAktif = $this->makeSupirVendor($idVendor, 'Driver Aktif');
        $this->makeSupirVendor($idVendor, 'Driver Nonaktif', aktif: 0);
        $this->makeSupirVendor($idVendor, 'Driver Terhapus', dihapusPada: now()->toDateTimeString());

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $idVendorLain = $this->makeVendor($idPerusahaanLain, 'Vendor Tetangga');
        $this->makeSupirVendor($idVendorLain, 'Driver Tetangga');

        $res = $this->getJson('/api/jadwal-shift/opsi-supir-vendor');

        $res->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($idAktif, $res->json('data.0.id_supir_vendor'));
        $this->assertSame('Driver Aktif', $res->json('data.0.nama'));
        $this->assertSame('Vendor Milik Sendiri', $res->json('data.0.nama_vendor'));
    }

    public function test_detail_penugasan_saya_vendor_memuat_shift_hari_ini(): void
    {
        $ctx = $this->actingAsSupirVendorMobile();
        $proyek = $this->makeProyek();
        $penugasan = PenugasanModel::create([
            'id_proyek'         => $proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $ctx->id_kontrak,
            'id_armada_vendor'  => $ctx->id_armada_vendor,
            'id_supir_vendor'   => $ctx->id_supir_vendor,
            'status'            => 'aktif',
            'tanggal_tugas'     => now()->toDateString(),
            'estimasi_biaya'    => 500000,
        ]);

        $idShift = $this->makeShift('Siang', '10:00:00', '18:00:00');
        DB::table('jadwal_shift')->insert([
            'id_jadwal_shift' => (string) Str::uuid(),
            'id_proyek'       => $proyek->id_proyek,
            'id_shift'        => $idShift,
            'id_supir_vendor' => $ctx->id_supir_vendor,
            'tanggal'         => now()->toDateString(),
            'dibuat_pada'     => now(),
        ]);

        $this->getJson("/api/trip/penugasan-saya/{$penugasan->id_penugasan}")
            ->assertStatus(200)
            ->assertJsonPath('data.shift_hari_ini.nama', 'Siang')
            ->assertJsonPath('data.shift_hari_ini.jam_mulai', '10:00:00')
            ->assertJsonPath('data.shift_hari_ini.jam_selesai', '18:00:00')
            ->assertJsonPath('data.uang_jalan', null);
    }
}
