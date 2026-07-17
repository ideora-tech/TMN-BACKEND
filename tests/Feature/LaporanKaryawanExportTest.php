<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\LaporanOperasional\LaporanOperasionalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LaporanKaryawanExportTest extends TestCase
{
    use RefreshDatabase;

    private function makeKaryawan(string $nama): void
    {
        DB::table('karyawan')->insert([
            'id_karyawan'        => (string) Str::uuid(),
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'nik'                => 'NIK-' . Str::random(6),
            'nama_karyawan'      => $nama,
            'status_kepegawaian' => 'tetap',
            'gaji_pokok'         => 4000000,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
    }

    private function makePerusahaanLain(): string
    {
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Test',
            'dibuat_pada'   => now(),
        ]);

        return $idPerusahaanLain;
    }

    public function test_export_karyawan_excel_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeKaryawan('Karyawan Excel Test');

        $res = $this->get('/api/v1/laporan/karyawan/export/excel');

        $res->assertStatus(200);
        $this->assertStringContainsString(
            'spreadsheetml',
            $res->headers->get('Content-Type')
        );
    }

    public function test_export_karyawan_pdf_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeKaryawan('Karyawan PDF Test');

        $res = $this->get('/api/v1/laporan/karyawan/export/pdf');

        $res->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $res->headers->get('Content-Type'));
    }

    public function test_export_karyawan_excel_tanpa_data_tetap_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->get('/api/v1/laporan/karyawan/export/excel');

        $res->assertStatus(200);
    }

    public function test_karyawan_aktif_query_mengembalikan_data_yang_benar(): void
    {
        $this->makeKaryawan('Karyawan Query Test');

        $nik = DB::table('karyawan')
            ->where('id_perusahaan', self::PERUSAHAAN_ID)
            ->where('nama_karyawan', 'Karyawan Query Test')
            ->value('nik');

        $idPerusahaanLain = $this->makePerusahaanLain();
        $nikLain = 'NIK-LAIN-' . Str::random(6);
        DB::table('karyawan')->insert([
            'id_karyawan'        => (string) Str::uuid(),
            'id_perusahaan'      => $idPerusahaanLain,
            'nik'                => $nikLain,
            'nama_karyawan'      => 'Karyawan Perusahaan Lain',
            'status_kepegawaian' => 'tetap',
            'gaji_pokok'         => 4000000,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);

        $service = app(LaporanOperasionalService::class);
        $hasil = $service->karyawanAktif(self::PERUSAHAAN_ID);

        $row = $hasil->firstWhere('nik', $nik);
        $this->assertNotNull($row, 'Karyawan yang baru diinsert harus ditemukan di hasil query.');
        $this->assertSame('Karyawan Query Test', $row->nama_karyawan);
        $this->assertSame($nik, $row->nik);

        $this->assertNull(
            $hasil->firstWhere('nik', $nikLain),
            'Karyawan milik perusahaan lain tidak boleh muncul di hasil query (multi-tenancy).'
        );
    }
}
