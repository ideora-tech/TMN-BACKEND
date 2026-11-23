<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArmadaVendor\ArmadaVendorModel;
use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KontrakVendorNomorOtomatisTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Nomor',
        ]);
    }

    private function payload(VendorModel $vendor, array $override = []): array
    {
        return array_merge([
            'id_vendor' => $vendor->id_vendor,
            'mekanisme' => 'unit_only',
        ], $override);
    }

    private function makePenugasan(string $idArmadaVendor, string $status, string $tanggal): void
    {
        DB::table('penugasan')->insert([
            'id_penugasan'     => (string) Str::uuid(),
            'id_proyek'        => (string) Str::uuid(),
            'id_armada_vendor' => $idArmadaVendor,
            'sumber'           => 'vendor',
            'status'           => $status,
            'tanggal_tugas'    => $tanggal,
            'dibuat_pada'      => now(),
        ]);
    }

    // ── Nomor kontrak otomatis ──────────────────────────────────────────────

    public function test_nomor_kontrak_kosong_dibuat_otomatis_format_surat(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $bulanTahun = now()->format('m/Y');

        $pertama = $this->postJson('/api/kontrak-vendor', $this->payload($vendor))->assertStatus(201);
        $kedua   = $this->postJson('/api/kontrak-vendor', $this->payload($vendor, ['nomor_kontrak' => '']))->assertStatus(201);

        $this->assertSame("001/PKS/VM/SLI/{$bulanTahun}", $pertama->json('data.nomor_kontrak'));
        $this->assertSame("002/PKS/VM/SLI/{$bulanTahun}", $kedua->json('data.nomor_kontrak'));
    }

    public function test_nomor_kontrak_manual_tetap_dipakai_dan_tidak_memakan_urutan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $this->postJson('/api/kontrak-vendor', $this->payload($vendor, ['nomor_kontrak' => 'KONTRAK-LAMA-01']))
            ->assertStatus(201)
            ->assertJsonPath('data.nomor_kontrak', 'KONTRAK-LAMA-01');

        $otomatis = $this->postJson('/api/kontrak-vendor', $this->payload($vendor))->assertStatus(201);
        $this->assertStringStartsWith('001/PKS/VM/SLI/', $otomatis->json('data.nomor_kontrak'));
    }

    public function test_format_nomor_kontrak_mengikuti_pengaturan_kode(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();

        $this->putJson('/api/pengaturan-kode/kontrak_vendor', [
            'prefix'        => 'PKS/OPS/SLI',
            'panjang_digit' => 4,
            'reset'         => 'tahunan',
        ])->assertStatus(200);

        $res = $this->postJson('/api/kontrak-vendor', $this->payload($vendor))->assertStatus(201);
        $this->assertSame('0001/PKS/OPS/SLI/' . now()->format('m/Y'), $res->json('data.nomor_kontrak'));
    }

    public function test_daftar_pengaturan_kode_memuat_kontrak_vendor_berformat_surat(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $baris = collect($this->getJson('/api/pengaturan-kode')->assertStatus(200)->json('data'))
            ->firstWhere('entitas', 'kontrak_vendor');

        $this->assertNotNull($baris);
        $this->assertSame('PKS/VM/SLI', $baris['prefix']);
        $this->assertSame(3, $baris['panjang_digit']);
        $this->assertSame('tahunan', $baris['reset']);
        $this->assertSame('surat', $baris['format']);
    }

    public function test_prefix_garis_miring_hanya_untuk_entitas_format_surat(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->putJson('/api/pengaturan-kode/rute', [
            'prefix' => 'RT/X', 'panjang_digit' => 4, 'reset' => 'tidak',
        ])->assertStatus(422);
    }

    // ── Unit kontrak lama bisa dipakai kontrak baru ─────────────────────────

    public function test_unit_dari_kontrak_yang_sudah_selesai_bisa_dipakai_kontrak_baru(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $kontrakLama = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'status'        => 'selesai',
        ]);
        $unit = ArmadaVendorModel::create([
            'id_vendor'         => $vendor->id_vendor,
            'id_kontrak_vendor' => $kontrakLama->id_kontrak_vendor,
            'nopol'             => 'B 9001 LAMA',
        ]);

        $res = $this->postJson('/api/kontrak-vendor', $this->payload($vendor, [
            'unit' => [[
                'nopol'             => 'B 9001 LAMA',
                'masa_berlaku_stnk' => '2027-03-01',
                'masa_berlaku_kir'  => '2026-12-15',
            ]],
        ]))->assertStatus(201);

        $this->assertDatabaseHas('armada_vendor', [
            'id_armada_vendor'  => $unit->id_armada_vendor,
            'id_kontrak_vendor' => $res->json('data.id_kontrak_vendor'),
        ]);
        $this->assertSame(1, DB::table('armada_vendor')->where('nopol', 'B 9001 LAMA')->whereNull('dihapus_pada')->count());
    }

    public function test_adopsi_unit_tanpa_pasangan_supir_mempertahankan_supir_default(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $idSupir = (string) Str::uuid();
        DB::table('supir_vendor')->insert([
            'id_supir_vendor' => $idSupir,
            'id_vendor'       => $vendor->id_vendor,
            'nama'            => 'Driver Tetap',
            'dibuat_pada'     => now(),
        ]);
        $unit = ArmadaVendorModel::create([
            'id_vendor'               => $vendor->id_vendor,
            'id_supir_vendor_default' => $idSupir,
            'nopol'                   => 'B 9002 LAMA',
        ]);

        $this->postJson('/api/kontrak-vendor', $this->payload($vendor, [
            'mekanisme' => 'unit_driver',
            'unit'      => [[
                'nopol'             => 'B 9002 LAMA',
                'masa_berlaku_stnk' => '2027-03-01',
                'masa_berlaku_kir'  => '2026-12-15',
            ]],
        ]))->assertStatus(201);

        $this->assertDatabaseHas('armada_vendor', [
            'id_armada_vendor'        => $unit->id_armada_vendor,
            'id_supir_vendor_default' => $idSupir,
        ]);
    }

    public function test_daftar_unit_vendor_menandai_unit_bebas_kontrak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $buatKontrak = fn (string $status) => KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'status'        => $status,
        ]);
        ArmadaVendorModel::create(['id_vendor' => $vendor->id_vendor, 'nopol' => 'B 1 BEBAS']);
        ArmadaVendorModel::create(['id_vendor' => $vendor->id_vendor, 'nopol' => 'B 2 SELESAI', 'id_kontrak_vendor' => $buatKontrak('selesai')->id_kontrak_vendor]);
        ArmadaVendorModel::create(['id_vendor' => $vendor->id_vendor, 'nopol' => 'B 3 AKTIF', 'id_kontrak_vendor' => $buatKontrak('aktif')->id_kontrak_vendor]);

        $data = collect($this->getJson("/api/armada-vendor?id_vendor={$vendor->id_vendor}&limit=50")->assertStatus(200)->json('data'))
            ->keyBy('nopol');

        $this->assertTrue($data['B 1 BEBAS']['bebas_kontrak']);
        $this->assertTrue($data['B 2 SELESAI']['bebas_kontrak']);
        $this->assertSame('selesai', $data['B 2 SELESAI']['status_kontrak']);
        $this->assertFalse($data['B 3 AKTIF']['bebas_kontrak']);
    }

    // ── Status operasional unit vendor ──────────────────────────────────────

    public function test_status_operasional_unit_vendor_diambil_dari_penugasan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor = $this->makeVendor();
        $hariIni = now()->toDateString();
        $unit = fn (string $nopol) => ArmadaVendorModel::create(['id_vendor' => $vendor->id_vendor, 'nopol' => $nopol]);

        $dipakai   = $unit('B 10 PAKAI');
        $terjadwal = $unit('B 11 JADWAL');
        $selesai   = $unit('B 12 SELESAI');
        $unit('B 13 BARU');
        $batal     = $unit('B 14 BATAL');

        $this->makePenugasan($dipakai->id_armada_vendor, 'aktif', $hariIni);
        $this->makePenugasan($dipakai->id_armada_vendor, 'selesai', now()->subDays(3)->toDateString());
        $this->makePenugasan($terjadwal->id_armada_vendor, 'pending', now()->addDays(2)->toDateString());
        $this->makePenugasan($selesai->id_armada_vendor, 'selesai', now()->subDay()->toDateString());
        $this->makePenugasan($batal->id_armada_vendor, 'batal', $hariIni);

        $data = collect($this->getJson("/api/armada-vendor?id_vendor={$vendor->id_vendor}&limit=50")->assertStatus(200)->json('data'))
            ->pluck('status_operasional', 'nopol');

        $this->assertSame('digunakan', $data['B 10 PAKAI']);
        $this->assertSame('terjadwal', $data['B 11 JADWAL']);
        $this->assertSame('selesai_digunakan', $data['B 12 SELESAI']);
        $this->assertSame('belum_digunakan', $data['B 13 BARU']);
        $this->assertSame('belum_digunakan', $data['B 14 BATAL']);
    }
}
