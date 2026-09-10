<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerawatanDetailPdfTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(string $nopol, string $idPerusahaan = self::PERUSAHAAN_ID): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => $idPerusahaan,
            'nopol'         => $nopol,
            'merk'          => 'Hino',
        ]);
    }

    private function makePerawatan(string $idArmada): string
    {
        $id = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan'    => $id,
            'id_armada'       => $idArmada,
            'tanggal'         => '2026-07-01',
            'jenis_perawatan' => 'Servis',
            'biaya'           => 150000,
            'km_odometer'     => 45000,
            'status'          => 'selesai',
            'keterangan'      => 'Ganti oli',
            'dibuat_pada'     => now(),
        ]);
        DB::table('perawatan_sparepart')->insert([
            'id_perawatan_sparepart' => (string) Str::uuid(),
            'id_perawatan'           => $id,
            'id_sparepart'           => null,
            'nama_sparepart'         => 'Oli Mesin',
            'qty'                    => 2,
            'harga'                  => 75000,
            'sumber'                 => 'bengkel',
            'dibuat_pada'            => now(),
        ]);
        return $id;
    }

    public function test_unduh_pdf_detail_perawatan_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('B 1111 AA');
        $id = $this->makePerawatan($armada->id_armada);

        $res = $this->get("/api/armada/{$armada->id_armada}/perawatan/{$id}/export/pdf");

        $res->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('perawatan-B1111AA-20260701.pdf', (string) $res->headers->get('Content-Disposition'));
    }

    public function test_unduh_pdf_perawatan_milik_armada_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armadaA = $this->makeArmada('B 1111 AA');
        $armadaB = $this->makeArmada('B 2222 BB');
        $idMilikA = $this->makePerawatan($armadaA->id_armada);

        $this->get("/api/armada/{$armadaB->id_armada}/perawatan/{$idMilikA}/export/pdf")->assertStatus(404);
    }

    public function test_unduh_pdf_perawatan_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $armadaLain = $this->makeArmada('B 3333 CC', $idPerusahaanLain);
        $id = $this->makePerawatan($armadaLain->id_armada);

        $this->get("/api/armada/{$armadaLain->id_armada}/perawatan/{$id}/export/pdf")->assertStatus(404);
    }
}
