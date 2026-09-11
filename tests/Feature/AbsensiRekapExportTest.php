<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Absensi\Exports\RekapAbsensiExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class AbsensiRekapExportTest extends TestCase
{
    use RefreshDatabase;

    private function makeKaryawan(string $nama, string $nik, int $aktif = 1, float $gaji = 0, ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nik' => $nik, 'nama_karyawan' => $nama, 'aktif' => $aktif,
            'gaji_pokok' => $gaji, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeAbsensi(string $idKaryawan, string $tanggal, string $status, ?string $jamPulang = null): void
    {
        DB::table('absensi')->insert([
            'id_absensi' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_karyawan' => $idKaryawan, 'tanggal' => $tanggal, 'status' => $status,
            'jam_masuk' => $jamPulang ? '08:00' : null, 'jam_pulang' => $jamPulang, 'dibuat_pada' => now(),
        ]);
    }

    public function test_export_rekap_berisi_semua_karyawan_aktif_perusahaan_sendiri_dan_baris_total(): void
    {
        $this->actingAsRole('SUPERADMIN');
        Excel::fake();

        $andi = $this->makeKaryawan('Andi Rekap', 'NIK-01', 1, 1730000);
        $budi = $this->makeKaryawan('Budi Rekap', 'NIK-02');
        $this->makeKaryawan('Citra Nonaktif', 'NIK-03', 0);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $this->makeKaryawan('Dodi Lain', 'NIK-99', 1, 0, $idPerusahaanLain);

        $this->makeAbsensi($andi, '2026-09-01', 'hadir', '18:30');
        $this->makeAbsensi($andi, '2026-09-02', 'terlambat');
        $this->makeAbsensi($budi, '2026-09-01', 'sakit');
        $this->makeAbsensi($budi, '2026-08-31', 'alpha');

        $this->get('/api/absensi/rekap/export/excel?bulan=2026-09')->assertStatus(200);

        Excel::assertDownloaded('rekap-absensi-2026-09.xlsx', function (RekapAbsensiExport $export) {
            $baris = $export->collection()->map(fn ($r) => $export->map($r))->values()->all();
            $perNama = collect($baris)->keyBy(1);

            return count($baris) === 3
                && $export->subjudulLaporan() === 'Periode: September 2026'
                && $perNama['Andi Rekap'] === ['NIK-01', 'Andi Rekap', 1, 1, 0, 0, 0, 0, '1 jam 30 menit', 25000]
                && $perNama['Budi Rekap'] === ['NIK-02', 'Budi Rekap', 0, 0, 0, 1, 0, 0, '-', 0]
                && $baris[2] === ['', 'TOTAL', 1, 1, 0, 1, 0, 0, '1 jam 30 menit', 25000];
        });
    }

    public function test_export_mengikuti_pencarian(): void
    {
        $this->actingAsRole('SUPERADMIN');
        Excel::fake();

        $this->makeKaryawan('Andi Rekap', 'NIK-01');
        $this->makeKaryawan('Budi Rekap', 'NIK-02');

        $this->get('/api/absensi/rekap/export/excel?bulan=2026-09&search=budi')->assertStatus(200);

        Excel::assertDownloaded('rekap-absensi-2026-09.xlsx', function (RekapAbsensiExport $export) {
            $baris = $export->collection()->map(fn ($r) => $export->map($r))->values()->all();
            return count($baris) === 2 && $baris[0][1] === 'Budi Rekap';
        });
    }

    public function test_file_excel_benar_benar_terbentuk(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $andi = $this->makeKaryawan('Andi Rekap', 'NIK-01', 1, 1730000);
        $this->makeAbsensi($andi, '2026-09-01', 'hadir', '18:30');

        $res = $this->get('/api/absensi/rekap/export/excel?bulan=2026-09');

        $res->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('content-type'));
        $this->assertStringContainsString('rekap-absensi-2026-09.xlsx', (string) $res->headers->get('content-disposition'));
    }

    public function test_format_bulan_tidak_valid_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->getJson('/api/absensi/rekap/export/excel?bulan=2026-13')->assertStatus(422);
        $this->getJson('/api/absensi/rekap/export/excel?bulan=ngawur')->assertStatus(422);
    }
}
