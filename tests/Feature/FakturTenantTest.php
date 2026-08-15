<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Faktur\FakturModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FakturTenantTest extends TestCase
{
    use RefreshDatabase;

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $id,
            'nama'          => 'Perusahaan Lain',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeKlien(string $idPerusahaan): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => $idPerusahaan,
            'kode_klien'    => 'KLN-' . Str::random(6),
            'nama_klien'    => 'Klien ' . Str::random(4),
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeFaktur(string $idPerusahaan, string $idKlien, string $nomor): FakturModel
    {
        return FakturModel::create([
            'id_perusahaan'  => $idPerusahaan,
            'id_klien'       => $idKlien,
            'nomor_faktur'   => $nomor,
            'total'          => 1000000,
            'status'         => 'draft',
            'tanggal_faktur' => now()->toDateString(),
        ]);
    }

    public function test_show_faktur_perusahaan_lain_ditolak_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idLain = $this->makePerusahaanLain();
        $faktur = $this->makeFaktur($idLain, $this->makeKlien($idLain), 'INV-LAIN-1');

        $this->getJson("/api/v1/faktur/{$faktur->id_faktur}")->assertStatus(404);
    }

    public function test_update_status_dan_hapus_faktur_perusahaan_lain_ditolak_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idLain = $this->makePerusahaanLain();
        $faktur = $this->makeFaktur($idLain, $this->makeKlien($idLain), 'INV-LAIN-2');

        $this->patchJson("/api/v1/faktur/{$faktur->id_faktur}/status", ['status' => 'terkirim'])->assertStatus(404);
        $this->putJson("/api/v1/faktur/{$faktur->id_faktur}", ['nomor_faktur' => 'INV-UBAH'])->assertStatus(404);
        $this->deleteJson("/api/v1/faktur/{$faktur->id_faktur}")->assertStatus(404);

        $this->assertSame('draft', $faktur->fresh()->status);
        $this->assertNull($faktur->fresh()->dihapus_pada);
    }

    public function test_index_dengan_id_klien_perusahaan_lain_tidak_bocor(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idLain     = $this->makePerusahaanLain();
        $idKlienLain = $this->makeKlien($idLain);
        $this->makeFaktur($idLain, $idKlienLain, 'INV-LAIN-3');

        $res = $this->getJson("/api/v1/faktur?id_klien={$idKlienLain}");

        $res->assertStatus(200);
        $this->assertSame([], $res->json('data'));
    }

    public function test_nama_file_export_disanitasi_dari_nomor_faktur_bergaris_miring(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKlien = $this->makeKlien(self::PERUSAHAAN_ID);
        $faktur  = $this->makeFaktur(self::PERUSAHAAN_ID, $idKlien, '001/INV/TMN/VIII/2026');

        $resExcel = $this->get("/api/v1/faktur/{$faktur->id_faktur}/export/excel");
        $resExcel->assertStatus(200);
        $this->assertStringContainsString('invoice-001-INV-TMN-VIII-2026.xlsx', $resExcel->headers->get('Content-Disposition'));

        $resPdf = $this->get("/api/v1/faktur/{$faktur->id_faktur}/export/pdf");
        $resPdf->assertStatus(200);
        $this->assertStringContainsString('invoice-001-INV-TMN-VIII-2026.pdf', $resPdf->headers->get('Content-Disposition'));
    }
}
