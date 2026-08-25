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

    private function makeFaktur(string $idKlien, string $idPerusahaan = self::PERUSAHAAN_ID): FakturModel
    {
        $faktur = FakturModel::create([
            'id_perusahaan' => $idPerusahaan,
            'id_klien'      => $idKlien,
            'nomor_faktur'  => 'INV-' . Str::random(6),
            'total'         => 1500000,
            'status'        => 'draft',
            'tanggal_faktur' => now()->toDateString(),
        ]);

        DB::table('faktur_item')->insert([
            'id_faktur_item' => (string) Str::uuid(),
            'id_faktur'      => $faktur->id_faktur,
            'deskripsi'      => 'Rute Jakarta - Surabaya — 3 rit',
            'qty'            => 3,
            'harga_satuan'   => 500000,
            'subtotal'       => 1500000,
        ]);

        return $faktur;
    }

    public function test_export_faktur_excel_per_faktur_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKlien = $this->makeKlien('PT Klien Excel');
        $faktur  = $this->makeFaktur($idKlien);

        $res = $this->get("/api/faktur/{$faktur->id_faktur}/export/excel");

        $res->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
        $this->assertStringContainsString($faktur->nomor_faktur, $res->headers->get('Content-Disposition'));
    }

    public function test_export_faktur_pdf_per_faktur_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKlien = $this->makeKlien('PT Klien PDF');
        $faktur  = $this->makeFaktur($idKlien);

        $res = $this->get("/api/faktur/{$faktur->id_faktur}/export/pdf");

        $res->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringContainsString($faktur->nomor_faktur, $res->headers->get('Content-Disposition'));
    }

    public function test_export_faktur_pdf_tanpa_item_tetap_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKlien = $this->makeKlien('PT Klien Kosong');

        $faktur = FakturModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'nomor_faktur'  => 'INV-' . Str::random(6),
            'total'         => 250000,
            'status'        => 'terkirim',
            'tanggal_faktur' => now()->toDateString(),
        ]);

        $res = $this->get("/api/faktur/{$faktur->id_faktur}/export/pdf");

        $res->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $res->headers->get('Content-Type'));
    }

    public function test_export_pdf_menampilkan_baris_subtotal_dan_pajak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKlien = $this->makeKlien('PT Klien Pajak PDF');
        $faktur  = $this->makeFaktur($idKlien);
        $faktur->update(['nama_pajak' => 'PPN', 'persen_pajak' => 11, 'total' => 1665000]);

        $view = $this->view('exports.faktur', [
            'f'          => $faktur->fresh()->load('items'),
            'items'      => $faktur->fresh()->items,
            'logoBase64' => null,
            'perusahaan' => (object) [],
        ]);

        $view->assertSee('Subtotal');
        $view->assertSee('PPN (11%)', false);
        $view->assertSee('Rp 165.000');
        $view->assertSee('Rp 1.665.000');
    }

    public function test_excel_export_collection_menyertakan_baris_subtotal_dan_pajak(): void
    {
        $idKlien = $this->makeKlien('PT Klien Pajak Excel');
        $faktur  = $this->makeFaktur($idKlien);
        $faktur->update(['nama_pajak' => 'PPN', 'persen_pajak' => 11, 'total' => 1665000]);
        $faktur = $faktur->fresh()->load('items');

        $rows = (new \App\Modules\Faktur\Exports\FakturDetailExport($faktur))->collection();

        $this->assertSame('Subtotal', $rows[1][1]);
        $this->assertSame(1500000.0, $rows[1][4]);
        $this->assertSame('PPN (11%)', $rows[2][1]);
        $this->assertSame(165000.0, $rows[2][4]);
        $this->assertSame('TOTAL', $rows[3][1]);
        $this->assertSame(1665000.0, $rows[3][4]);
    }

    public function test_export_faktur_milik_perusahaan_lain_ditolak_404(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'PT Lain',
            'dibuat_pada'   => now(),
        ]);

        $idKlienLain = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $idKlienLain,
            'id_perusahaan' => $idPerusahaanLain,
            'kode_klien'    => 'KLN-' . Str::random(6),
            'nama_klien'    => 'Klien Lain',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);

        $faktur = $this->makeFaktur($idKlienLain, $idPerusahaanLain);

        $this->get("/api/faktur/{$faktur->id_faktur}/export/excel")->assertStatus(404);
        $this->get("/api/faktur/{$faktur->id_faktur}/export/pdf")->assertStatus(404);
    }
}
