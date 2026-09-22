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
        $this->actingAsRole('SUPERADMIN');
        $klien     = $this->makeKlien();
        $penawaran = $this->makePenawaran($klien->id_klien);

        $res = $this->get("/api/penawaran/{$penawaran->id_penawaran}/pdf");

        $res->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $res->headers->get('content-type'));
    }

    public function test_export_pdf_penawaran_id_tidak_ditemukan_mengembalikan_404(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->get('/api/penawaran/' . (string) Str::uuid() . '/pdf');

        $res->assertStatus(404);
    }

    public function test_export_pdf_penawaran_milik_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('SUPERADMIN');

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

        $res = $this->get("/api/penawaran/{$penawaranLain->id_penawaran}/pdf");

        $res->assertStatus(404);
    }

    public function test_tampilan_pdf_memuat_jumlah_hari_kolom_hari_dan_trip_tanpa_berlaku_hingga(): void
    {
        $penawaran = $this->makePenawaran();
        $penawaran->update(['jumlah_hari' => 26]);

        $html = view('exports.penawaran', [
            'p'          => $penawaran->fresh(),
            'klien'      => (object) ['nama_klien' => 'PT Klien Test'],
            'items'      => collect([(object) [
                'nama_rute'       => 'Jakarta - Bandung',
                'asal'            => 'Jakarta',
                'tujuan'          => 'Bandung',
                'keterangan'      => null,
                'nama_jenis'      => 'CDD',
                'harga_satuan'    => 1000000,
                'jumlah_hari'     => 26,
                'estimasi_ritase' => 3,
                'subtotal'        => 3000000,
            ]]),
            'logoBase64' => null,
            'perusahaan' => (object) [],
        ])->render();

        $this->assertStringContainsString('Jumlah hari', $html);
        $this->assertStringContainsString('26 hari', $html);
        $this->assertStringContainsString('>HARI<', $html);
        $this->assertStringContainsString('>TRIP<', $html);
        $this->assertStringNotContainsString('Berlaku hingga', $html);
        $this->assertStringNotContainsString('RITASE', $html);
    }
}
