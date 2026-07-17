<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Faktur\FakturModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FakturKlienExportTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(6),
            'nama_klien'    => $nama,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeFaktur(string $idKlien): void
    {
        FakturModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'nomor_faktur'  => 'INV-' . Str::random(6),
            'total'         => 1500000,
            'status'        => 'draft',
            'tanggal_faktur' => now()->toDateString(),
        ]);
    }

    public function test_export_faktur_excel_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $idKlien = $this->makeKlien('PT Klien Excel');
        $this->makeFaktur($idKlien);

        $res = $this->get('/api/v1/faktur/export/excel');

        $res->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
    }

    public function test_export_faktur_pdf_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $idKlien = $this->makeKlien('PT Klien PDF');
        $this->makeFaktur($idKlien);

        $res = $this->get('/api/v1/faktur/export/pdf');

        $res->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $res->headers->get('Content-Type'));
    }
}
