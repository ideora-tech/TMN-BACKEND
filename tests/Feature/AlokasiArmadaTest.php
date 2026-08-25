<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Alokasi armada otomatis dari papan shift/penugasan (dulu dipicu di
 * JadwalShiftService::createBatch/importMatriks/updateShift/delete dan
 * PenugasanService::update) sudah dicabut — modul AlokasiArmada TIDAK
 * dihapus (endpoint hitung-ulang manual, riwayat, list, export tetap ada),
 * hanya tidak lagi dipanggil otomatis dari alur shift/penugasan.
 */
class AlokasiArmadaTest extends TestCase
{
    use RefreshDatabase;

    private string $tanggalUji;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tanggalUji = now()->addDays(9)->toDateString();
    }

    private function makeArmada(string $nopol): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => $nopol,
            'merk'          => 'Hino',
        ]);
    }

    private function makeSupir(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => $nama,
            'no_sim'        => 'SIM-' . Str::random(8),
            'jenis_sim'     => 'B1',
            'status'        => 'aktif',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeProyek(): ProyekModel
    {
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $idKlien,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Alokasi Test',
            'dibuat_pada'   => now(),
        ]);

        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Alokasi Test',
        ]);
    }

    private function makePenugasan(string $idSupir, string $idProyek, ?string $idArmada = null): PenugasanModel
    {
        return PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $idProyek,
            'id_supir'      => $idSupir,
            'id_armada'     => $idArmada,
            'status'        => 'aktif',
            'tanggal_tugas' => now()->toDateString(),
        ]);
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

    private function makeShift(): string
    {
        $idShift = (string) Str::uuid();
        DB::table('shift')->insert([
            'id_shift'      => $idShift,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Pagi',
            'jam_mulai'     => '06:00:00',
            'jam_selesai'   => '14:00:00',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $idShift;
    }

    private function buatJadwal(string $idProyek, string $idShift, array $idSupir, string $tanggal): void
    {
        $this->postJson('/api/jadwal-shift', [
            'id_proyek' => $idProyek,
            'id_shift'  => $idShift,
            'tanggal'   => $tanggal,
            'supir'     => $idSupir,
        ])->assertStatus(200);
    }

    public function test_batch_shift_tidak_lagi_membuat_alokasi_armada_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = $this->makeArmada('B 1000 AA');
        $idSupir = $this->makeSupir('Supir Punya Mobil');
        $this->makePenugasan($idSupir, $proyek->id_proyek, $armada->id_armada);
        $this->makeSupirProyek($proyek->id_proyek, $idSupir);
        $idShift = $this->makeShift();

        $this->buatJadwal($proyek->id_proyek, $idShift, [$idSupir], $this->tanggalUji);

        $this->assertSame(0, DB::table('alokasi_armada')->count());
    }

    public function test_hitung_ulang_manual_aman_dipanggil_meski_tidak_ada_yang_berubah(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = $this->makeArmada('B 9037 TMN');
        $idSupir = $this->makeSupir('Supir Tetap');
        $this->makePenugasan($idSupir, $proyek->id_proyek, $armada->id_armada);
        $this->makeSupirProyek($proyek->id_proyek, $idSupir);
        $idShift = $this->makeShift();
        $this->buatJadwal($proyek->id_proyek, $idShift, [$idSupir], $this->tanggalUji);

        $res = $this->postJson('/api/alokasi-armada/hitung-ulang', [
            'id_proyek' => $proyek->id_proyek,
            'dari'      => now()->toDateString(),
            'sampai'    => now()->addDays(30)->toDateString(),
        ]);

        $res->assertStatus(200)->assertJsonPath('data.jumlah_dihitung_ulang', 1);
        $this->assertDatabaseHas('alokasi_armada', [
            'id_supir' => $idSupir, 'tanggal' => $this->tanggalUji,
            'id_armada' => $armada->id_armada, 'sumber' => 'penugasan', 'dihapus_pada' => null,
        ]);
        $this->assertSame(1, DB::table('alokasi_armada')->where('id_supir', $idSupir)->count());

        // Idempoten — dipanggil ulang tanpa ada perubahan tidak menghasilkan churn.
        $this->postJson('/api/alokasi-armada/hitung-ulang', [
            'id_proyek' => $proyek->id_proyek,
            'dari'      => now()->toDateString(),
            'sampai'    => now()->addDays(30)->toDateString(),
        ])->assertStatus(200);
        $this->assertSame(1, DB::table('alokasi_armada')->where('id_supir', $idSupir)->count());
    }

    public function test_hitung_ulang_proyek_lain_perusahaan_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain Hitung Ulang', 'dibuat_pada' => now(),
        ]);
        $proyekLain = ProyekModel::create([
            'id_perusahaan' => $idPerusahaanLain,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Perusahaan Lain',
        ]);

        $this->postJson('/api/alokasi-armada/hitung-ulang', [
            'id_proyek' => $proyekLain->id_proyek, 'dari' => now()->toDateString(), 'sampai' => now()->addDays(30)->toDateString(),
        ])->assertStatus(404);
    }

    public function test_laporan_riwayat_per_armada_bisa_diunduh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('B 9300 LL');

        $this->get("/api/alokasi-armada/export/excel?id_armada={$armada->id_armada}")
            ->assertStatus(200);
        $this->get("/api/alokasi-armada/export/pdf?id_armada={$armada->id_armada}")
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_list_bisa_difilter_per_armada(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armadaA = $this->makeArmada('B 9400 MM');
        $armadaB = $this->makeArmada('B 9500 NN');
        $supirA = $this->makeSupir('Supir AA');
        $supirB = $this->makeSupir('Supir BB');
        $this->makePenugasan($supirA, $proyek->id_proyek, $armadaA->id_armada);
        $this->makePenugasan($supirB, $proyek->id_proyek, $armadaB->id_armada);
        $this->makeSupirProyek($proyek->id_proyek, $supirA);
        $this->makeSupirProyek($proyek->id_proyek, $supirB);
        $idShift = $this->makeShift();
        $this->buatJadwal($proyek->id_proyek, $idShift, [$supirA, $supirB], $this->tanggalUji);

        $this->postJson('/api/alokasi-armada/hitung-ulang', [
            'id_proyek' => $proyek->id_proyek,
            'dari'      => $this->tanggalUji,
            'sampai'    => $this->tanggalUji,
        ])->assertStatus(200);

        $this->getJson("/api/alokasi-armada?id_armada={$armadaA->id_armada}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.armada_nopol', 'B 9400 MM');
    }

    public function test_endpoint_ganti_armada_manual_sudah_dihapus(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idAlokasiPalsu = (string) Str::uuid();

        $this->putJson("/api/alokasi-armada/{$idAlokasiPalsu}", ['id_armada' => (string) Str::uuid()])
            ->assertStatus(404);
        $this->getJson("/api/alokasi-armada/armada-tersedia?tanggal={$this->tanggalUji}")
            ->assertStatus(404);
    }

    public function test_list_mode_audit_saat_filter_armada(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada  = $this->makeArmada('B 1111 ALK');
        $idSupir = $this->makeSupir('Supir Audit');
        $proyek  = $this->makeProyek();

        DB::table('alokasi_armada')->insert([
            [
                'id_alokasi'      => (string) Str::uuid(),
                'tanggal'         => $this->tanggalUji,
                'id_proyek'       => $proyek->id_proyek,
                'id_supir'        => $idSupir,
                'id_armada'       => $armada->id_armada,
                'id_pemilik_asal' => null,
                'sumber'          => 'otomatis',
                'keterangan'      => 'Armada tanpa pemilik',
                'dibuat_pada'     => now()->subHour(),
                'dihapus_pada'    => now(),
            ],
            [
                'id_alokasi'      => (string) Str::uuid(),
                'tanggal'         => $this->tanggalUji,
                'id_proyek'       => $proyek->id_proyek,
                'id_supir'        => $idSupir,
                'id_armada'       => $armada->id_armada,
                'id_pemilik_asal' => null,
                'sumber'          => 'penugasan',
                'keterangan'      => null,
                'dibuat_pada'     => now(),
                'dihapus_pada'    => null,
            ],
        ]);

        $tanpaFilter = $this->getJson("/api/alokasi-armada?tanggal_dari={$this->tanggalUji}&tanggal_sampai={$this->tanggalUji}");
        $tanpaFilter->assertStatus(200);
        $this->assertCount(1, $tanpaFilter->json('data'));
        $this->assertNull($tanpaFilter->json('data.0.dihapus_pada'));

        $denganFilter = $this->getJson("/api/alokasi-armada?tanggal_dari={$this->tanggalUji}&tanggal_sampai={$this->tanggalUji}&id_armada={$armada->id_armada}");
        $denganFilter->assertStatus(200);
        $this->assertCount(2, $denganFilter->json('data'));
        $this->assertCount(1, collect($denganFilter->json('data'))->filter(fn ($r) => $r['dihapus_pada'] !== null));
    }
}
