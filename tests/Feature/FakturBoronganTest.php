<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Trip\TripModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FakturBoronganTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(string $idPerusahaan = self::PERUSAHAAN_ID): object
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => $idPerusahaan,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Borongan',
            'dibuat_pada'   => now(),
        ]);
        return DB::table('klien')->where('id_klien', $id)->first();
    }

    private function makeProyekBorongan(string $idKlien, float $hargaPenawaran, string $idPerusahaan = self::PERUSAHAAN_ID): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan'   => $idPerusahaan,
            'id_klien'        => $idKlien,
            'kode_proyek'     => 'PRJ-' . Str::random(8),
            'nama_proyek'     => 'Proyek Borongan',
            'tipe_harga'      => 'borongan',
            'harga_penawaran' => $hargaPenawaran,
        ]);
    }

    private function makeProyekPerRit(string $idKlien, ?float $hargaPenawaran = null): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'id_klien'        => $idKlien,
            'kode_proyek'     => 'PRJ-' . Str::random(8),
            'nama_proyek'     => 'Proyek Per Rit',
            'tipe_harga'      => 'per_rit',
            'harga_penawaran' => $hargaPenawaran,
        ]);
    }

    private function makeRute(): string
    {
        $id = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute'       => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute'     => 'RT-' . Str::random(6),
            'nama_rute'     => 'Jakarta - Bandung',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeJenisKendaraan(): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'kode_jenis'         => 'JK-' . Str::random(6),
            'nama_jenis'         => 'Tronton',
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return $id;
    }

    private function buatProyekRute(string $idProyek, string $idRute, ?string $idJenisKendaraan, ?float $harga): void
    {
        DB::table('proyek_rute')->insert([
            'id_proyek_rute'     => (string) Str::uuid(),
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'id_proyek'          => $idProyek,
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenisKendaraan,
            'harga_penawaran'    => $harga,
            'estimasi_ritase'    => 1,
            'dibuat_pada'        => now(),
        ]);
    }

    private function buatTripSelesai(string $idProyek, string $idRute, string $idJenisKendaraan, float $biayaTagihan = 0.0): TripModel
    {
        $idArmada = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmada, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol' => 'B ' . random_int(1000, 9999) . ' RL', 'merk' => 'Hino',
            'id_jenis_kendaraan' => $idJenisKendaraan, 'dibuat_pada' => now(),
        ]);

        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupir, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Supir Realisasi ' . Str::random(4), 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        $penugasan = PenugasanModel::create([
            'id_proyek' => $idProyek,
            'id_armada' => $idArmada,
            'id_supir'  => $idSupir,
        ]);

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'id_rute'         => $idRute,
            'waktu_berangkat' => now()->subDay(),
        ]);

        $trip = TripModel::create([
            'id_jadwal' => $jadwal->id_jadwal,
            'status'    => 'selesai',
        ]);

        $idLaporan = (string) Str::uuid();
        DB::table('laporan_perjalanan')->insert([
            'id_laporan' => $idLaporan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_trip' => $trip->id_trip, 'jarak_tempuh_km' => 300, 'dibuat_pada' => now(),
        ]);

        if ($biayaTagihan > 0) {
            DB::table('biaya_tagihan_trip')->insert([
                'id_biaya_tagihan' => (string) Str::uuid(), 'id_laporan' => $idLaporan,
                'nama_biaya' => 'TKBM', 'nominal' => $biayaTagihan, 'dibuat_pada' => now(),
            ]);
        }

        return $trip;
    }

    public function test_faktur_borongan_dalam_batas_menghasilkan_201_dan_tertaut_proyek_klien(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $proyek = $this->makeProyekBorongan($klien->id_klien, 50000000);

        $res = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/faktur-borongan", [
            'nominal'        => 20000000,
            'uraian'         => 'Termin 1',
            'tanggal_faktur' => now()->toDateString(),
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.id_proyek', $proyek->id_proyek)
            ->assertJsonPath('data.id_klien', $klien->id_klien)
            ->assertJsonPath('data.total', 20000000)
            ->assertJsonPath('data.status', 'draft');

        $idFaktur = $res->json('data.id_faktur');
        $this->assertDatabaseHas('faktur_item', [
            'id_faktur'  => $idFaktur,
            'deskripsi'  => 'Termin 1',
            'harga_satuan' => 20000000,
        ]);
    }

    public function test_faktur_borongan_termin_kedua_melebihi_sisa_ditolak_422_dengan_pesan_sisa(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $proyek = $this->makeProyekBorongan($klien->id_klien, 50000000);

        $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/faktur-borongan", [
            'nominal'        => 30000000,
            'uraian'         => 'Termin 1',
            'tanggal_faktur' => now()->toDateString(),
        ])->assertStatus(201);

        $res = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/faktur-borongan", [
            'nominal'        => 25000000,
            'uraian'         => 'Termin 2',
            'tanggal_faktur' => now()->toDateString(),
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Total faktur melebihi nilai kontrak — sisa Rp 20.000.000');

        $this->assertSame(1, DB::table('faktur')->where('id_proyek', $proyek->id_proyek)->count());
    }

    public function test_faktur_batal_tidak_dihitung_dalam_sisa(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $proyek = $this->makeProyekBorongan($klien->id_klien, 10000000);

        $pertama = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/faktur-borongan", [
            'nominal'        => 10000000,
            'uraian'         => 'Termin Penuh',
            'tanggal_faktur' => now()->toDateString(),
        ]);
        $pertama->assertStatus(201);

        $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/faktur-borongan", [
            'nominal'        => 1,
            'uraian'         => 'Termin Kelebihan',
            'tanggal_faktur' => now()->toDateString(),
        ])->assertStatus(422);

        $idFakturPertama = $pertama->json('data.id_faktur');
        $this->patchJson("/api/v1/faktur/{$idFakturPertama}/status", ['status' => 'batal'])->assertStatus(200);

        $kedua = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/faktur-borongan", [
            'nominal'        => 10000000,
            'uraian'         => 'Termin Setelah Batal',
            'tanggal_faktur' => now()->toDateString(),
        ]);
        $kedua->assertStatus(201);
    }

    public function test_faktur_borongan_ditolak_422_untuk_proyek_per_rit(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $proyek = $this->makeProyekPerRit($klien->id_klien, 50000000);

        $res = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/faktur-borongan", [
            'nominal'        => 5000000,
            'uraian'         => 'Termin 1',
            'tanggal_faktur' => now()->toDateString(),
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Faktur termin hanya untuk proyek borongan');
    }

    public function test_guard_sisa_membaca_state_faktur_terbaru_saat_dikunci(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $proyek = $this->makeProyekBorongan($klien->id_klien, 10000000);

        DB::table('faktur')->insert([
            'id_faktur'      => (string) Str::uuid(),
            'id_perusahaan'  => self::PERUSAHAAN_ID,
            'id_proyek'      => $proyek->id_proyek,
            'id_klien'       => $klien->id_klien,
            'nomor_faktur'   => 'FK-RACE-1',
            'total'          => 10000000,
            'status'         => 'draft',
            'tanggal_faktur' => now()->toDateString(),
            'dibuat_pada'    => now(),
        ]);

        $res = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/faktur-borongan", [
            'nominal'        => 1,
            'uraian'         => 'Termin Race',
            'tanggal_faktur' => now()->toDateString(),
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Total faktur melebihi nilai kontrak — sisa Rp 0');

        $this->assertSame(1, DB::table('faktur')->where('id_proyek', $proyek->id_proyek)->count());
    }

    public function test_faktur_borongan_proyek_perusahaan_lain_ditolak_404(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $klienLain  = $this->makeKlien($idPerusahaanLain);
        $proyekLain = $this->makeProyekBorongan($klienLain->id_klien, 10000000, $idPerusahaanLain);

        $res = $this->postJson("/api/v1/proyek/{$proyekLain->id_proyek}/faktur-borongan", [
            'nominal'        => 5000000,
            'uraian'         => 'Termin 1',
            'tanggal_faktur' => now()->toDateString(),
        ]);

        $res->assertStatus(404)->assertJsonPath('message', 'Proyek tidak ditemukan');
    }

    public function test_realisasi_per_rit_muncul_di_show_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $proyek  = $this->makeProyekPerRit($klien->id_klien, 3000000);
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();
        $this->buatProyekRute($proyek->id_proyek, $idRute, $idJenis, 1000000);

        $this->buatTripSelesai($proyek->id_proyek, $idRute, $idJenis, 150000);
        $this->buatTripSelesai($proyek->id_proyek, $idRute, $idJenis, 0);

        $res = $this->getJson("/api/v1/proyek/{$proyek->id_proyek}");

        $res->assertStatus(200)
            ->assertJsonPath('data.realisasi.total_rit', 2)
            ->assertJsonPath('data.realisasi.nilai_realisasi', 2150000)
            ->assertJsonPath('data.realisasi.nilai_penawaran', 3000000)
            ->assertJsonPath('data.realisasi.sisa_belum_difakturkan', null);
    }

    public function test_realisasi_per_rit_trip_supir_shift_pakai_jenis_kendaraan_dari_alokasi_armada(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien           = $this->makeKlien();
        $proyek          = $this->makeProyekPerRit($klien->id_klien, 5000000);
        $idRute          = $this->makeRute();
        $idJenisSpesifik = $this->makeJenisKendaraan();

        $this->buatProyekRute($proyek->id_proyek, $idRute, $idJenisSpesifik, 2000000);
        $this->buatProyekRute($proyek->id_proyek, $idRute, null, 500000);

        $idArmadaAlokasi = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmadaAlokasi, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol' => 'B ' . random_int(1000, 9999) . ' SH', 'merk' => 'Hino',
            'id_jenis_kendaraan' => $idJenisSpesifik, 'dibuat_pada' => now(),
        ]);

        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupir, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Supir Shift Realisasi', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        $tanggalTrip = now()->subDay();

        $penugasan = PenugasanModel::create([
            'id_proyek' => $proyek->id_proyek,
            'id_supir'  => $idSupir,
        ]);
        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'id_rute'         => $idRute,
            'waktu_berangkat' => $tanggalTrip,
        ]);
        $trip = TripModel::create(['id_jadwal' => $jadwal->id_jadwal, 'status' => 'selesai']);
        DB::table('laporan_perjalanan')->insert([
            'id_laporan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_trip' => $trip->id_trip, 'jarak_tempuh_km' => 200, 'dibuat_pada' => now(),
        ]);

        DB::table('alokasi_armada')->insert([
            'id_alokasi' => (string) Str::uuid(),
            'id_proyek'  => $proyek->id_proyek,
            'id_supir'   => $idSupir,
            'id_armada'  => $idArmadaAlokasi,
            'tanggal'    => $tanggalTrip->toDateString(),
            'sumber'     => 'penugasan',
            'dibuat_pada' => now(),
        ]);

        $res = $this->getJson("/api/v1/proyek/{$proyek->id_proyek}");

        $res->assertStatus(200)
            ->assertJsonPath('data.realisasi.total_rit', 1)
            ->assertJsonPath('data.realisasi.nilai_realisasi', 2000000);
    }

    public function test_realisasi_borongan_muncul_di_show_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $proyek = $this->makeProyekBorongan($klien->id_klien, 50000000);

        $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/faktur-borongan", [
            'nominal'        => 20000000,
            'uraian'         => 'Termin 1',
            'tanggal_faktur' => now()->toDateString(),
        ])->assertStatus(201);

        $res = $this->getJson("/api/v1/proyek/{$proyek->id_proyek}");

        $res->assertStatus(200)
            ->assertJsonPath('data.realisasi.total_rit', 0)
            ->assertJsonPath('data.realisasi.nilai_realisasi', 20000000)
            ->assertJsonPath('data.realisasi.nilai_penawaran', 50000000)
            ->assertJsonPath('data.realisasi.sisa_belum_difakturkan', 30000000);
    }
}
