<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Supir\Exports\RiwayatArmadaSupirExport;
use App\Modules\Trip\Exports\RiwayatTripSheet;
use App\Modules\Trip\TripModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class SupirRiwayatExportTest extends TestCase
{
    use RefreshDatabase;

    private function makeSupir(string $nama, ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $id,
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nama'          => $nama,
            'no_sim'        => 'SIM-' . Str::random(8),
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeProyek(): ProyekModel
    {
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $idKlien,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'PT Klien Riwayat',
            'dibuat_pada'   => now(),
        ]);

        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(6),
            'nama_proyek'   => 'Proyek Riwayat',
        ]);
    }

    private function makePenugasan(string $idSupir, ?string $idArmada, string $tanggal): PenugasanModel
    {
        return PenugasanModel::create([
            'id_proyek'     => $this->makeProyek()->id_proyek,
            'id_armada'     => $idArmada,
            'id_supir'      => $idSupir,
            'tanggal_tugas' => $tanggal,
            'status'        => 'aktif',
        ]);
    }

    private function makeTrip(PenugasanModel $penugasan, string $status): TripModel
    {
        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => '2026-09-01 08:00:00',
        ]);

        return TripModel::create(['id_jadwal' => $jadwal->id_jadwal, 'status' => $status]);
    }

    public function test_export_riwayat_armada_berisi_penugasan_supir_itu_saja(): void
    {
        $this->actingAsRole('SUPERADMIN');
        Excel::fake();

        $armada = ArmadaModel::create(['id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => 'B 9011 TMN', 'merk' => 'Isuzu', 'model' => 'Elf NLR 55']);
        $asep = $this->makeSupir('Asep Riwayat');
        $budi = $this->makeSupir('Budi Lain');
        $this->makePenugasan($asep, $armada->id_armada, '2026-09-08');
        $this->makePenugasan($asep, null, '2026-09-04');
        $this->makePenugasan($budi, $armada->id_armada, '2026-09-05');

        $this->get("/api/supir/{$asep}/riwayat-armada/export/excel")->assertStatus(200);

        Excel::assertDownloaded('riwayat-armada-asep-riwayat-' . date('Ymd') . '.xlsx', function (RiwayatArmadaSupirExport $export) {
            $rows = $export->collection()->map(fn ($r) => $export->map($r))->all();
            return count($rows) === 2
                && $rows[0][0] === '08/09/2026'
                && $rows[0][1] === 'B 9011 TMN'
                && $rows[0][2] === 'Isuzu Elf NLR 55'
                && $rows[1][1] === '-';
        });
    }

    public function test_export_riwayat_trip_berisi_semua_status_trip_supir_itu(): void
    {
        $this->actingAsRole('SUPERADMIN');
        Excel::fake();

        $asep = $this->makeSupir('Asep Riwayat');
        $budi = $this->makeSupir('Budi Lain');
        $penugasanAsep = $this->makePenugasan($asep, null, '2026-09-01');
        $this->makeTrip($penugasanAsep, 'selesai');
        $this->makeTrip($penugasanAsep, 'berjalan');
        $this->makeTrip($this->makePenugasan($budi, null, '2026-09-01'), 'selesai');

        $this->get("/api/supir/{$asep}/riwayat-trip/export/excel")->assertStatus(200);

        Excel::assertDownloaded('riwayat-trip-asep-riwayat-' . date('Ymd') . '.xlsx', function (RiwayatTripSheet $export) {
            return $export->collection()->count() === 2
                && $export->subjudulLaporan() === 'Supir: Asep Riwayat';
        });
    }

    public function test_export_riwayat_supir_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $supirLain = $this->makeSupir('Supir Lain', $idPerusahaanLain);

        $this->getJson("/api/supir/{$supirLain}/riwayat-armada/export/excel")->assertStatus(404);
        $this->getJson("/api/supir/{$supirLain}/riwayat-trip/export/excel")->assertStatus(404);
    }
}
