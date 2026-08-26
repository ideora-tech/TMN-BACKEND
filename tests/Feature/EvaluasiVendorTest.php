<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Proyek\ProyekModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvaluasiVendorTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(string $nama = 'Vendor Test', ?string $idPerusahaan = null): VendorModel
    {
        return VendorModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => $nama,
        ]);
    }

    private function makeVendorPerusahaanLain(): VendorModel
    {
        $idPerusahaanLain = (string) Str::uuid();

        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Test',
            'dibuat_pada'   => now(),
        ]);

        return $this->makeVendor('Vendor Perusahaan Lain', $idPerusahaanLain);
    }

    private function makeProyek(string $nama = 'Proyek Evaluasi Vendor', ?string $idPerusahaan = null): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => $nama,
        ]);
    }

    private function makeKontrak(string $idVendor, ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('kontrak_vendor')->insert([
            'id_kontrak_vendor' => $id,
            'id_perusahaan'     => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_vendor'         => $idVendor,
            'nomor_kontrak'     => 'KV-' . Str::random(8),
            'mekanisme'         => 'unit_only',
            'status'            => 'aktif',
            'dibuat_pada'       => now(),
        ]);
        return $id;
    }

    private function insertPenugasanVendor(string $idProyek, string $idKontrak, string $tanggalTugas = '2026-07-10'): string
    {
        $id = (string) Str::uuid();
        DB::table('penugasan')->insert([
            'id_penugasan'      => $id,
            'id_proyek'         => $idProyek,
            'tanggal_tugas'     => $tanggalTugas,
            'status'            => 'selesai',
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $idKontrak,
            'dibuat_pada'       => now(),
        ]);
        return $id;
    }

    private function insertPenugasanInternal(string $idProyek): string
    {
        $id = (string) Str::uuid();
        DB::table('penugasan')->insert([
            'id_penugasan' => $id,
            'id_proyek'    => $idProyek,
            'status'       => 'selesai',
            'sumber'       => 'internal',
            'dibuat_pada'  => now(),
        ]);
        return $id;
    }

    private function insertEvaluasi(string $idPenugasan, array $nilai = [], ?string $dibuatPada = null): string
    {
        $id = (string) Str::uuid();
        DB::table('evaluasi_trip')->insert(array_merge([
            'id_evaluasi'  => $id,
            'id_penugasan' => $idPenugasan,
            'dibuat_pada'  => $dibuatPada ?? now(),
        ], $nilai));
        return $id;
    }

    public function test_post_evaluasi_dengan_kriteria_vendor_tersimpan_dan_terbaca_kembali(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor);
        $idPenugasan = $this->insertPenugasanVendor($proyek->id_proyek, $kontrak);

        $res = $this->postJson("/api/penugasan/{$idPenugasan}/evaluasi", [
            'nilai_ketepatan_waktu' => 4,
            'nilai_kualitas'        => 5,
            'nilai_harga'           => 3,
            'nilai_responsif'       => 2,
            'nilai_armada'          => 4,
            'nilai_supir'           => 5,
            'catatan'               => 'Kinerja vendor baik',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nilai_ketepatan_waktu', 4)
            ->assertJsonPath('data.nilai_kualitas', 5)
            ->assertJsonPath('data.nilai_harga', 3)
            ->assertJsonPath('data.nilai_responsif', 2);

        $this->assertDatabaseHas('evaluasi_trip', [
            'id_penugasan'          => $idPenugasan,
            'nilai_ketepatan_waktu' => 4,
            'nilai_kualitas'        => 5,
            'nilai_harga'           => 3,
            'nilai_responsif'       => 2,
        ]);

        $this->getJson("/api/penugasan/{$idPenugasan}/evaluasi")
            ->assertStatus(200)
            ->assertJsonPath('data.nilai_ketepatan_waktu', 4)
            ->assertJsonPath('data.nilai_kualitas', 5)
            ->assertJsonPath('data.nilai_harga', 3)
            ->assertJsonPath('data.nilai_responsif', 2)
            ->assertJsonPath('data.catatan', 'Kinerja vendor baik');
    }

    public function test_put_evaluasi_mengubah_kriteria_vendor(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor);
        $idPenugasan = $this->insertPenugasanVendor($proyek->id_proyek, $kontrak);

        $idEvaluasi = $this->postJson("/api/penugasan/{$idPenugasan}/evaluasi", [
            'nilai_ketepatan_waktu' => 4,
            'nilai_kualitas'        => 5,
            'nilai_harga'           => 3,
            'nilai_responsif'       => 2,
        ])->assertStatus(201)->json('data.id_evaluasi');

        $res = $this->putJson("/api/evaluasi/{$idEvaluasi}", [
            'nilai_ketepatan_waktu' => 1,
            'nilai_harga'           => 5,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.nilai_ketepatan_waktu', 1)
            ->assertJsonPath('data.nilai_kualitas', 5)
            ->assertJsonPath('data.nilai_harga', 5)
            ->assertJsonPath('data.nilai_responsif', 2);

        $this->assertDatabaseHas('evaluasi_trip', [
            'id_evaluasi'           => $idEvaluasi,
            'nilai_ketepatan_waktu' => 1,
            'nilai_kualitas'        => 5,
            'nilai_harga'           => 5,
            'nilai_responsif'       => 2,
        ]);
    }

    public function test_validasi_nilai_kriteria_di_luar_rentang_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $vendor = $this->makeVendor();
        $kontrak = $this->makeKontrak($vendor->id_vendor);
        $idPenugasan = $this->insertPenugasanVendor($proyek->id_proyek, $kontrak);

        $this->postJson("/api/penugasan/{$idPenugasan}/evaluasi", [
            'nilai_kualitas' => 6,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['nilai_kualitas']);

        $this->postJson("/api/penugasan/{$idPenugasan}/evaluasi", [
            'nilai_responsif' => 0,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['nilai_responsif']);

        $this->assertDatabaseMissing('evaluasi_trip', [
            'id_penugasan' => $idPenugasan,
        ]);
    }

    public function test_rekap_menghitung_rata_rata_per_vendor(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();

        $vendorA = $this->makeVendor('Alpha Trans');
        $kontrakA = $this->makeKontrak($vendorA->id_vendor);
        $penugasanA1 = $this->insertPenugasanVendor($proyek->id_proyek, $kontrakA);
        $penugasanA2 = $this->insertPenugasanVendor($proyek->id_proyek, $kontrakA);
        $this->insertEvaluasi($penugasanA1, [
            'nilai_ketepatan_waktu' => 4,
            'nilai_kualitas'        => 5,
            'nilai_harga'           => 3,
            'nilai_responsif'       => 4,
        ]);
        $this->insertEvaluasi($penugasanA2, [
            'nilai_ketepatan_waktu' => 5,
            'nilai_kualitas'        => 4,
            'nilai_responsif'       => 2,
        ]);

        $vendorB = $this->makeVendor('Beta Logistik');
        $kontrakB = $this->makeKontrak($vendorB->id_vendor);
        $penugasanB1 = $this->insertPenugasanVendor($proyek->id_proyek, $kontrakB);
        $this->insertEvaluasi($penugasanB1, [
            'nilai_ketepatan_waktu' => 5,
        ]);

        $penugasanInternal = $this->insertPenugasanInternal($proyek->id_proyek);
        $this->insertEvaluasi($penugasanInternal, [
            'nilai_ketepatan_waktu' => 1,
            'nilai_kualitas'        => 1,
        ]);

        $res = $this->getJson('/api/evaluasi-vendor/rekap');

        $res->assertStatus(200)->assertJsonPath('success', true);
        $data = $res->json('data');
        $this->assertCount(2, $data);

        $this->assertSame($vendorA->id_vendor, $data[0]['id_vendor']);
        $this->assertSame('Alpha Trans', $data[0]['nama_vendor']);
        $this->assertSame(2, $data[0]['jumlah_evaluasi']);
        $this->assertSame(4.5, (float) $data[0]['rata_ketepatan_waktu']);
        $this->assertSame(4.5, (float) $data[0]['rata_kualitas']);
        $this->assertSame(3.0, (float) $data[0]['rata_harga']);
        $this->assertSame(3.0, (float) $data[0]['rata_responsif']);
        $this->assertSame(3.75, (float) $data[0]['rata_keseluruhan']);

        $this->assertSame($vendorB->id_vendor, $data[1]['id_vendor']);
        $this->assertSame('Beta Logistik', $data[1]['nama_vendor']);
        $this->assertSame(1, $data[1]['jumlah_evaluasi']);
        $this->assertSame(5.0, (float) $data[1]['rata_ketepatan_waktu']);
        $this->assertNull($data[1]['rata_kualitas']);
        $this->assertNull($data[1]['rata_harga']);
        $this->assertNull($data[1]['rata_responsif']);
        $this->assertSame(5.0, (float) $data[1]['rata_keseluruhan']);
    }

    public function test_rekap_tidak_menampilkan_vendor_perusahaan_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $proyek = $this->makeProyek();
        $vendor = $this->makeVendor('Vendor Sendiri');
        $kontrak = $this->makeKontrak($vendor->id_vendor);
        $penugasan = $this->insertPenugasanVendor($proyek->id_proyek, $kontrak);
        $this->insertEvaluasi($penugasan, ['nilai_ketepatan_waktu' => 4]);

        $vendorLain = $this->makeVendorPerusahaanLain();
        $proyekLain = $this->makeProyek('Proyek Perusahaan Lain', $vendorLain->id_perusahaan);
        $kontrakLain = $this->makeKontrak($vendorLain->id_vendor, $vendorLain->id_perusahaan);
        $penugasanLain = $this->insertPenugasanVendor($proyekLain->id_proyek, $kontrakLain);
        $this->insertEvaluasi($penugasanLain, ['nilai_ketepatan_waktu' => 5]);

        $res = $this->getJson('/api/evaluasi-vendor/rekap');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($vendor->id_vendor, $data[0]['id_vendor']);
    }

    public function test_list_evaluasi_per_vendor_menampilkan_daftar_urut_terbaru(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek('Proyek Angkutan Semen');
        $vendor = $this->makeVendor('Vendor Listing');
        $kontrak = $this->makeKontrak($vendor->id_vendor);

        $penugasanLama = $this->insertPenugasanVendor($proyek->id_proyek, $kontrak, '2026-07-01');
        $evaluasiLama = $this->insertEvaluasi($penugasanLama, [
            'nilai_ketepatan_waktu' => 3,
            'nilai_kualitas'        => 4,
            'catatan'               => 'Evaluasi lama',
        ], now()->subDay()->toDateTimeString());

        $penugasanBaru = $this->insertPenugasanVendor($proyek->id_proyek, $kontrak, '2026-07-15');
        $evaluasiBaru = $this->insertEvaluasi($penugasanBaru, [
            'nilai_ketepatan_waktu' => 5,
            'nilai_harga'           => 2,
            'nilai_armada'          => 4,
            'nilai_supir'           => 3,
            'catatan'               => 'Evaluasi baru',
        ], now()->toDateTimeString());

        $res = $this->getJson("/api/vendor/{$vendor->id_vendor}/evaluasi");

        $res->assertStatus(200)->assertJsonPath('success', true);
        $data = $res->json('data');
        $this->assertCount(2, $data);

        $this->assertSame($evaluasiBaru, $data[0]['id_evaluasi']);
        $this->assertSame($penugasanBaru, $data[0]['id_penugasan']);
        $this->assertSame('2026-07-15', $data[0]['tanggal_tugas']);
        $this->assertSame('Proyek Angkutan Semen', $data[0]['nama_proyek']);
        $this->assertSame(5, $data[0]['nilai_ketepatan_waktu']);
        $this->assertNull($data[0]['nilai_kualitas']);
        $this->assertSame(2, $data[0]['nilai_harga']);
        $this->assertSame(4, $data[0]['nilai_armada']);
        $this->assertSame(3, $data[0]['nilai_supir']);
        $this->assertSame('Evaluasi baru', $data[0]['catatan']);

        $this->assertSame($evaluasiLama, $data[1]['id_evaluasi']);
        $this->assertSame('2026-07-01', $data[1]['tanggal_tugas']);
        $this->assertSame(3, $data[1]['nilai_ketepatan_waktu']);
        $this->assertSame(4, $data[1]['nilai_kualitas']);
        $this->assertSame('Evaluasi lama', $data[1]['catatan']);
    }

    public function test_list_evaluasi_vendor_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorLain = $this->makeVendorPerusahaanLain();

        $this->getJson("/api/vendor/{$vendorLain->id_vendor}/evaluasi")
            ->assertStatus(404);
    }

    private function insertTripSelesai(string $idPenugasan): void
    {
        $idJadwal = (string) Str::uuid();
        DB::table('jadwal_keberangkatan')->insert([
            'id_jadwal'    => $idJadwal,
            'id_penugasan' => $idPenugasan,
            'dibuat_pada'  => now(),
        ]);
        DB::table('trip')->insert([
            'id_trip'     => (string) Str::uuid(),
            'id_jadwal'   => $idJadwal,
            'status'      => 'selesai',
            'dibuat_pada' => now(),
        ]);
    }

    public function test_daftar_penugasan_untuk_evaluasi_hanya_vendor_dengan_trip_selesai(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor  = $this->makeVendor();
        $proyek  = $this->makeProyek();
        $kontrak = $this->makeKontrak($vendor->id_vendor);

        $penugasanSelesai = $this->insertPenugasanVendor($proyek->id_proyek, $kontrak);
        $this->insertTripSelesai($penugasanSelesai);

        $this->insertPenugasanVendor($proyek->id_proyek, $kontrak, '2026-07-11');

        $penugasanInternal = $this->insertPenugasanInternal($proyek->id_proyek);
        $this->insertTripSelesai($penugasanInternal);

        $res = $this->getJson('/api/evaluasi-vendor/penugasan');

        $res->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id_penugasan', $penugasanSelesai)
            ->assertJsonPath('data.0.nama_vendor', 'Vendor Test')
            ->assertJsonPath('data.0.id_evaluasi', null);
    }

    public function test_daftar_penugasan_untuk_evaluasi_menyertakan_evaluasi_yang_sudah_ada(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendor  = $this->makeVendor();
        $proyek  = $this->makeProyek();
        $kontrak = $this->makeKontrak($vendor->id_vendor);

        $idPenugasan = $this->insertPenugasanVendor($proyek->id_proyek, $kontrak);
        $this->insertTripSelesai($idPenugasan);
        $idEvaluasi = $this->insertEvaluasi($idPenugasan, [
            'nilai_ketepatan_waktu' => 4,
            'nilai_kualitas'        => 5,
        ]);

        $res = $this->getJson('/api/evaluasi-vendor/penugasan');

        $res->assertStatus(200)
            ->assertJsonPath('data.0.id_evaluasi', $idEvaluasi)
            ->assertJsonPath('data.0.nilai_ketepatan_waktu', 4)
            ->assertJsonPath('data.0.nilai_kualitas', 5);
    }

    public function test_daftar_penugasan_untuk_evaluasi_tidak_bocor_perusahaan_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorLain  = $this->makeVendorPerusahaanLain();
        $proyekLain  = $this->makeProyek('Proyek Lain', (string) $vendorLain->id_perusahaan);
        $kontrakLain = $this->makeKontrak($vendorLain->id_vendor, (string) $vendorLain->id_perusahaan);

        $idPenugasan = $this->insertPenugasanVendor($proyekLain->id_proyek, $kontrakLain);
        $this->insertTripSelesai($idPenugasan);

        $this->getJson('/api/evaluasi-vendor/penugasan')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_evaluasi_penugasan_perusahaan_lain_ditolak_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $vendorLain  = $this->makeVendorPerusahaanLain();
        $proyekLain  = $this->makeProyek('Proyek Lain', (string) $vendorLain->id_perusahaan);
        $kontrakLain = $this->makeKontrak($vendorLain->id_vendor, (string) $vendorLain->id_perusahaan);
        $idPenugasan = $this->insertPenugasanVendor($proyekLain->id_proyek, $kontrakLain);

        $this->postJson("/api/penugasan/{$idPenugasan}/evaluasi", ['nilai_kualitas' => 5])
            ->assertStatus(404);

        $idEvaluasi = $this->insertEvaluasi($idPenugasan, ['nilai_kualitas' => 3]);
        $this->putJson("/api/evaluasi/{$idEvaluasi}", ['nilai_kualitas' => 5])
            ->assertStatus(404);

        $this->assertDatabaseHas('evaluasi_trip', ['id_evaluasi' => $idEvaluasi, 'nilai_kualitas' => 3]);
    }
}
