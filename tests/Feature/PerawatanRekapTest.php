<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerawatanRekapTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(string $nopol): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => $nopol,
            'merk'          => 'Hino',
        ]);
    }

    private function makePerawatan(string $idArmada, string $tanggal, float $biaya, ?int $km = null, string $status = 'selesai'): string
    {
        $id = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan'    => $id,
            'id_armada'       => $idArmada,
            'tanggal'         => $tanggal,
            'jenis_perawatan' => 'Servis',
            'biaya'           => $biaya,
            'km_odometer'     => $km,
            'status'          => $status,
            'dibuat_pada'     => now(),
        ]);
        return $id;
    }

    private function tambahSparepartLine(string $idPerawatan, int $qty, float $harga, ?string $idSparepart = null, string $nama = 'Part Uji', string $sumber = 'bengkel'): void
    {
        DB::table('perawatan_sparepart')->insert([
            'id_perawatan_sparepart' => (string) Str::uuid(),
            'id_perawatan'           => $idPerawatan,
            'id_sparepart'           => $idSparepart,
            'nama_sparepart'         => $nama,
            'sumber'                 => $sumber,
            'qty'                    => $qty,
            'harga'                  => $harga,
            'dibuat_pada'            => now(),
        ]);
    }

    /** @return array{armada: ArmadaModel, p1: string, p2: string, partX: string} */
    private function siapkanDataBiayaUnit(): array
    {
        $armada = $this->makeArmada('B 5555 EE');
        $partX  = (string) Str::uuid();

        $p1 = $this->makePerawatan($armada->id_armada, '2026-07-01', 100000, 12000);
        $this->tambahSparepartLine($p1, 2, 50000, $partX, 'Filter Oli', 'stok_sendiri');
        $this->travel(1)->seconds();
        $this->tambahSparepartLine($p1, 1, 20000, null, 'Kampas Rem', 'bengkel');

        $p2 = $this->makePerawatan($armada->id_armada, '2026-07-15', 200000, 15000);
        $this->tambahSparepartLine($p2, 1, 60000, $partX, 'Filter Oli', 'bengkel');
        $this->travel(1)->seconds();
        $this->tambahSparepartLine($p2, 1, 20000, null, 'Kampas Rem', 'bengkel');

        $batal = $this->makePerawatan($armada->id_armada, '2026-07-20', 999999, 99999, 'dibatalkan');
        $this->tambahSparepartLine($batal, 9, 99999, $partX, 'Filter Oli');

        return ['armada' => $armada, 'p1' => $p1, 'p2' => $p2, 'partX' => $partX];
    }

    public function test_rekap_per_unit_menyertakan_qty_sparepart_dan_km_terakhir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanDataBiayaUnit();

        $res = $this->getJson('/api/perawatan-armada/rekap-per-unit');

        $res->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.jumlah_perawatan', 2)
            ->assertJsonPath('data.0.qty_sparepart', 5)
            ->assertJsonPath('data.0.km_terakhir', 15000)
            ->assertJsonPath('data.0.biaya_jasa', 300000)
            ->assertJsonPath('data.0.biaya_sparepart', 200000)
            ->assertJsonPath('data.0.total_biaya', 500000);
    }

    public function test_riwayat_biaya_unit_menyertakan_sparepart_dan_ringkasan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $data = $this->siapkanDataBiayaUnit();

        $res = $this->getJson("/api/armada/{$data['armada']->id_armada}/perawatan/riwayat-biaya");

        $res->assertStatus(200)
            ->assertJsonPath('data.armada.nopol', 'B 5555 EE')
            ->assertJsonPath('data.ringkasan.jumlah_perawatan', 2)
            ->assertJsonPath('data.ringkasan.qty_sparepart', 5)
            ->assertJsonPath('data.ringkasan.biaya_jasa', 300000)
            ->assertJsonPath('data.ringkasan.biaya_sparepart', 200000)
            ->assertJsonPath('data.ringkasan.total_biaya', 500000)
            ->assertJsonPath('data.ringkasan.km_terakhir', 15000)
            ->assertJsonCount(2, 'data.riwayat')
            ->assertJsonPath('data.riwayat.0.id_perawatan', $data['p2'])
            ->assertJsonPath('data.riwayat.0.total_biaya', 280000)
            ->assertJsonCount(2, 'data.riwayat.0.sparepart')
            ->assertJsonPath('data.riwayat.0.sparepart.1.id_sparepart', null)
            ->assertJsonPath('data.riwayat.1.id_perawatan', $data['p1'])
            ->assertJsonCount(2, 'data.riwayat.1.sparepart')
            ->assertJsonPath('data.riwayat.1.sparepart.0.nama_sparepart', 'Filter Oli')
            ->assertJsonPath('data.riwayat.1.sparepart.0.sumber', 'stok_sendiri')
            ->assertJsonPath('data.riwayat.1.sparepart.0.subtotal', 100000);

        $filter = $this->getJson("/api/armada/{$data['armada']->id_armada}/perawatan/riwayat-biaya?tanggal_dari=2026-07-10&tanggal_sampai=2026-07-31");
        $filter->assertStatus(200)
            ->assertJsonCount(1, 'data.riwayat')
            ->assertJsonPath('data.ringkasan.total_biaya', 280000);

        $this->getJson("/api/armada/{$data['armada']->id_armada}/perawatan/riwayat-biaya?tanggal_sampai=2026-07-10")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.riwayat')
            ->assertJsonPath('data.riwayat.0.id_perawatan', $data['p1']);
    }

    public function test_parameter_tanggal_tidak_valid_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $data = $this->siapkanDataBiayaUnit();
        $idArmada = $data['armada']->id_armada;

        $this->getJson('/api/perawatan-armada/rekap-per-unit?tanggal_dari=abc')->assertStatus(422);
        $this->getJson("/api/armada/{$idArmada}/perawatan/riwayat-biaya?tanggal_sampai=2026-13-01")->assertStatus(422);
        $this->getJson("/api/armada/{$idArmada}/perawatan/rekap-sparepart?tanggal_dari=2026-07-31&tanggal_sampai=2026-07-01")->assertStatus(422);
        $this->getJson("/api/armada/{$idArmada}/perawatan/export/excel?tanggal_dari=abc&tanggal_sampai=xyz")->assertStatus(422);
        $this->getJson('/api/perawatan-armada/rekap-per-unit/export/excel?tanggal_dari=01/07/2026')->assertStatus(422);
    }

    public function test_rekap_sparepart_unit_mengelompokkan_per_part(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $data = $this->siapkanDataBiayaUnit();

        $res = $this->getJson("/api/armada/{$data['armada']->id_armada}/perawatan/rekap-sparepart");

        $res->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id_sparepart', $data['partX'])
            ->assertJsonPath('data.0.nama_sparepart', 'Filter Oli')
            ->assertJsonPath('data.0.total_qty', 3)
            ->assertJsonPath('data.0.total_biaya', 160000)
            ->assertJsonPath('data.0.harga_rata', 53333.33)
            ->assertJsonPath('data.0.jumlah_perawatan', 2)
            ->assertJsonPath('data.0.sumber', 'campuran')
            ->assertJsonPath('data.0.terakhir_dipakai', '2026-07-15')
            ->assertJsonPath('data.1.nama_sparepart', 'Kampas Rem')
            ->assertJsonPath('data.1.id_sparepart', null)
            ->assertJsonPath('data.1.total_qty', 2)
            ->assertJsonPath('data.1.total_biaya', 40000)
            ->assertJsonPath('data.1.jumlah_perawatan', 2)
            ->assertJsonPath('data.1.sumber', 'bengkel');
    }

    public function test_riwayat_dan_rekap_sparepart_armada_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $armadaLain = ArmadaModel::create(['id_perusahaan' => $idPerusahaanLain, 'nopol' => 'B 9999 ZZ', 'merk' => 'Hino']);
        $this->makePerawatan($armadaLain->id_armada, '2026-07-01', 100000);

        $this->getJson("/api/armada/{$armadaLain->id_armada}/perawatan/riwayat-biaya")->assertStatus(404);
        $this->getJson("/api/armada/{$armadaLain->id_armada}/perawatan/rekap-sparepart")->assertStatus(404);
        $this->get("/api/armada/{$armadaLain->id_armada}/perawatan/export/excel")->assertStatus(404);
        $this->getJson('/api/perawatan-armada/rekap-per-unit')->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_rekap_per_unit_menjumlahkan_biaya_jasa_dan_sparepart(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armadaA = $this->makeArmada('B 1111 AA');
        $armadaB = $this->makeArmada('B 2222 BB');

        $p1 = $this->makePerawatan($armadaA->id_armada, '2026-07-01', 100000);
        $this->tambahSparepartLine($p1, 2, 50000);
        $this->makePerawatan($armadaA->id_armada, '2026-07-15', 200000);
        $this->makePerawatan($armadaB->id_armada, '2026-07-20', 300000);

        $res = $this->getJson('/api/perawatan-armada/rekap-per-unit');

        $res->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.nopol', 'B 1111 AA')
            ->assertJsonPath('data.0.jumlah_perawatan', 2)
            ->assertJsonPath('data.0.biaya_jasa', 300000)
            ->assertJsonPath('data.0.biaya_sparepart', 100000)
            ->assertJsonPath('data.0.total_biaya', 400000)
            ->assertJsonPath('data.1.nopol', 'B 2222 BB')
            ->assertJsonPath('data.1.total_biaya', 300000);
    }

    public function test_list_dukung_multi_status_dan_urut_aktivitas_terbaru(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armadaLama = $this->makeArmada('B 1111 AA');
        $armadaBaru = $this->makeArmada('B 2222 BB');

        DB::table('perawatan_armada')->where('id_perawatan', $this->makePerawatan($armadaLama->id_armada, '2026-07-01', 100000))
            ->update(['status' => 'terjadwal']);
        DB::table('perawatan_armada')->where('id_perawatan', $this->makePerawatan($armadaBaru->id_armada, '2026-07-20', 200000))
            ->update(['status' => 'dalam_proses']);
        $this->makePerawatan($armadaLama->id_armada, '2026-06-01', 50000); // status selesai — tidak boleh ikut

        $res = $this->getJson('/api/perawatan-armada?status=terjadwal,dalam_proses');

        $res->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.armada_nopol', 'B 2222 BB')
            ->assertJsonPath('data.1.armada_nopol', 'B 1111 AA');
    }

    public function test_semua_endpoint_export_mengembalikan_file(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('B 4444 DD');
        $p = $this->makePerawatan($armada->id_armada, '2026-07-01', 150000, 10000);
        $this->tambahSparepartLine($p, 2, 25000);

        $this->get('/api/perawatan-armada/rekap-per-unit/export/excel')->assertStatus(200);
        $this->get('/api/perawatan-armada/rekap-per-unit/export/pdf')->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');
        $this->get("/api/armada/{$armada->id_armada}/perawatan/export/excel")->assertStatus(200);
        $this->get("/api/armada/{$armada->id_armada}/perawatan/export/pdf")->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_rekap_per_unit_menghormati_filter_tanggal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('B 3333 CC');
        $this->makePerawatan($armada->id_armada, '2026-06-01', 100000);
        $this->makePerawatan($armada->id_armada, '2026-07-10', 250000);

        $res = $this->getJson('/api/perawatan-armada/rekap-per-unit?tanggal_dari=2026-07-01&tanggal_sampai=2026-07-31');

        $res->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.jumlah_perawatan', 1)
            ->assertJsonPath('data.0.total_biaya', 250000);
    }
}
