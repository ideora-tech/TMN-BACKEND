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

    private function makePerawatan(string $idArmada, string $tanggal, float $biaya): string
    {
        $id = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan'    => $id,
            'id_armada'       => $idArmada,
            'tanggal'         => $tanggal,
            'jenis_perawatan' => 'Servis',
            'biaya'           => $biaya,
            'status'          => 'selesai',
            'dibuat_pada'     => now(),
        ]);
        return $id;
    }

    private function tambahSparepartLine(string $idPerawatan, int $qty, float $harga): void
    {
        DB::table('perawatan_sparepart')->insert([
            'id_perawatan_sparepart' => (string) Str::uuid(),
            'id_perawatan'           => $idPerawatan,
            'id_sparepart'           => (string) Str::uuid(),
            'nama_sparepart'         => 'Part Uji',
            'qty'                    => $qty,
            'harga'                  => $harga,
            'dibuat_pada'            => now(),
        ]);
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

        $res = $this->getJson('/api/v1/perawatan-armada/rekap-per-unit');

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

        $res = $this->getJson('/api/v1/perawatan-armada?status=terjadwal,dalam_proses');

        $res->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.armada_nopol', 'B 2222 BB')
            ->assertJsonPath('data.1.armada_nopol', 'B 1111 AA');
    }

    public function test_semua_endpoint_export_mengembalikan_file(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('B 4444 DD');
        $this->makePerawatan($armada->id_armada, '2026-07-01', 150000);

        $this->get('/api/v1/perawatan-armada/rekap-per-unit/export/excel')->assertStatus(200);
        $this->get('/api/v1/perawatan-armada/rekap-per-unit/export/pdf')->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');
        $this->get("/api/v1/armada/{$armada->id_armada}/perawatan/export/excel")->assertStatus(200);
        $this->get("/api/v1/armada/{$armada->id_armada}/perawatan/export/pdf")->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_rekap_per_unit_menghormati_filter_tanggal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('B 3333 CC');
        $this->makePerawatan($armada->id_armada, '2026-06-01', 100000);
        $this->makePerawatan($armada->id_armada, '2026-07-10', 250000);

        $res = $this->getJson('/api/v1/perawatan-armada/rekap-per-unit?tanggal_dari=2026-07-01&tanggal_sampai=2026-07-31');

        $res->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.jumlah_perawatan', 1)
            ->assertJsonPath('data.0.total_biaya', 250000);
    }
}
