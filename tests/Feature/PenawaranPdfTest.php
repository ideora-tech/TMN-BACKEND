<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Penawaran\PenawaranModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenawaranPdfTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(): object
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'PT Klien Test',
            'dibuat_pada'   => now(),
        ]);
        return DB::table('klien')->where('id_klien', $id)->first();
    }

    private function makePenawaran(?string $idKlien = null): PenawaranModel
    {
        return PenawaranModel::create([
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'id_klien'           => $idKlien,
            'nomor_penawaran'    => 'PWR-' . Str::random(8),
            'judul'              => 'Penawaran Jasa Transport',
            'nilai_penawaran'    => 15000000,
            'status'             => 'draft',
            'tanggal_penawaran'  => now()->toDateString(),
            'tanggal_berlaku'    => now()->addDays(30)->toDateString(),
            'catatan'            => 'Catatan penawaran test',
        ]);
    }

    public function test_export_pdf_penawaran_mengembalikan_file_pdf(): void
    {
        $this->actingAsRole('ADMIN');
        $klien     = $this->makeKlien();
        $penawaran = $this->makePenawaran($klien->id_klien);

        $res = $this->get("/api/v1/penawaran/{$penawaran->id_penawaran}/pdf");

        $res->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $res->headers->get('content-type'));
    }

    public function test_export_pdf_penawaran_id_tidak_ditemukan_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->get('/api/v1/penawaran/' . (string) Str::uuid() . '/pdf');

        $res->assertStatus(404);
    }

    public function test_export_pdf_penawaran_milik_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Test',
            'dibuat_pada'   => now(),
        ]);

        $penawaranLain = PenawaranModel::create([
            'id_perusahaan'     => $idPerusahaanLain,
            'nomor_penawaran'   => 'PWR-' . Str::random(8),
            'judul'             => 'Penawaran Perusahaan Lain',
            'nilai_penawaran'   => 5000000,
            'status'            => 'draft',
            'tanggal_penawaran' => now()->toDateString(),
            'tanggal_berlaku'   => now()->addDays(30)->toDateString(),
        ]);

        $res = $this->get("/api/v1/penawaran/{$penawaranLain->id_penawaran}/pdf");

        $res->assertStatus(404);
    }
}
