# Export Rekap Trip per Supir — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tombol Export Excel & PDF di tab Riwayat halaman Trip yang mengunduh rekap agregat trip per supir sesuai filter aktif.

**Architecture:** Query agregasi baru `rekapTripPerSupir` di `LaporanOperasionalRepository` (menumpang `baseTripQuery`), diexpose lewat interface dan dipanggil dari `TripService`. Route export didaftarkan di modul Trip (`izin:trip`). Frontend menambah dua tombol download blob di `RiwayatTripTab`.

**Tech Stack:** Laravel 11, maatwebsite/excel (`DenganGayaLaporan`), barryvdh/dompdf, Next.js 15 + Axios.

**Spec:** `docs/superpowers/specs/2026-08-05-export-rekap-trip-supir-design.md`

## Global Constraints

- **DILARANG menjalankan `git commit`/`git add`/perintah git apapun yang mengubah state** — user selalu commit manual. Akhiri task dengan laporan file yang berubah, bukan commit.
- Test backend dijalankan dengan `vendor/bin/phpunit` (JANGAN `php artisan test`).
- DILARANG `npm run build` / docker build — user jalankan sendiri. `npx eslint` boleh.
- Jangan tulis komentar penjelas di kode. Docblock satu baris di interface boleh (mengikuti konvensi file interface tsb).
- Eloquent/query builder hanya di `*Repository.php`.
- Semua teks UI bahasa Indonesia.
- Working dir backend: `D:\PROJECT-TMN\TMN-TRANSPORT-BACKEND`; frontend: `D:\PROJECT-TMN\TMN-TRANSPORT-FRONTEND`.

---

### Task 1: Query agregasi `rekapTripPerSupir`

**Files:**
- Test (create): `tests/Feature/TripRekapSupirExportTest.php`
- Modify: `app/Modules/LaporanOperasional/Contracts/LaporanOperasionalRepositoryInterface.php` (tambah 1 method di akhir interface)
- Modify: `app/Modules/LaporanOperasional/LaporanOperasionalRepository.php` (tambah 1 method setelah `ringkasanTrip`, sekitar baris 71)

**Interfaces:**
- Consumes: `baseTripQuery(string $idPerusahaan, array $f): Builder` (private, sudah ada di repository yang sama; sudah support filter `dari`, `sampai`, `sumber`).
- Produces: `rekapTripPerSupir(string $idPerusahaan, array $filter): Collection` — Collection of stdClass dengan properti: `nama_supir` (string), `sumber` ('internal'|'vendor'), `jumlah_trip`, `selesai`, `dibatalkan` (numeric-string), `total_jarak_km`, `total_biaya` (numeric-string), `trip_terakhir` (string 'Y-m-d H:i:s'|null). Filter keys: `dari`, `sampai`, `sumber`, `status` (semua opsional). Task 2 bergantung pada signature ini.

- [ ] **Step 1: Tulis file test dengan helper seed + 2 test agregat (RED)**

Buat `tests/Feature/TripRekapSupirExportTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\ArmadaVendor\ArmadaVendorModel;
use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\LaporanOperasional\Contracts\LaporanOperasionalRepositoryInterface;
use App\Modules\LaporanPerjalanan\BiayaLainTripModel;
use App\Modules\LaporanPerjalanan\LaporanPerjalananModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\SupirVendor\SupirVendorModel;
use App\Modules\Trip\TripModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TripRekapSupirExportTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(): object
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'PT Klien Rekap',
            'dibuat_pada'   => now(),
        ]);
        return DB::table('klien')->where('id_klien', $id)->first();
    }

    private function makeArmada(): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . random_int(1000, 9999) . ' RKP',
            'merk'          => 'Hino',
        ]);
    }

    private function makeSupir(string $nama): object
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => $nama,
            'no_sim'        => 'SIM-' . Str::random(8),
            'dibuat_pada'   => now(),
        ]);
        return DB::table('supir')->where('id_supir', $id)->first();
    }

    private function buatProyek(): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien()->id_klien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Rekap Supir',
        ]);
    }

    private function buatTripDariPenugasan(PenugasanModel $penugasan, string $waktuBerangkat, string $status): TripModel
    {
        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => $waktuBerangkat,
        ]);

        return TripModel::create([
            'id_jadwal' => $jadwal->id_jadwal,
            'status'    => $status,
        ]);
    }

    private function makeTrip(string $idArmada, string $idSupir, string $waktuBerangkat, string $status = 'selesai'): TripModel
    {
        $penugasan = PenugasanModel::create([
            'id_proyek' => $this->buatProyek()->id_proyek,
            'id_armada' => $idArmada,
            'id_supir'  => $idSupir,
        ]);

        return $this->buatTripDariPenugasan($penugasan, $waktuBerangkat, $status);
    }

    private function makeTripTanpaSupir(string $idArmada, string $waktuBerangkat): TripModel
    {
        $penugasan = PenugasanModel::create([
            'id_proyek' => $this->buatProyek()->id_proyek,
            'id_armada' => $idArmada,
        ]);

        return $this->buatTripDariPenugasan($penugasan, $waktuBerangkat, 'selesai');
    }

    private function makeTripVendor(string $namaSupirVendor, string $waktuBerangkat): TripModel
    {
        $vendor = VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Rekap',
        ]);
        $kontrak = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_driver',
        ]);
        $armadaVendor = ArmadaVendorModel::create([
            'id_vendor' => $vendor->id_vendor,
            'nopol'     => 'V ' . random_int(1000, 9999) . ' RKP',
        ]);
        $supirVendor = SupirVendorModel::create([
            'id_vendor' => $vendor->id_vendor,
            'nama'      => $namaSupirVendor,
        ]);

        $penugasan = PenugasanModel::create([
            'id_proyek'         => $this->buatProyek()->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $armadaVendor->id_armada_vendor,
            'id_supir_vendor'   => $supirVendor->id_supir_vendor,
        ]);

        return $this->buatTripDariPenugasan($penugasan, $waktuBerangkat, 'selesai');
    }

    private function makeLaporan(string $idTrip, float $bbm, float $uangJalan, float $jarak): LaporanPerjalananModel
    {
        return LaporanPerjalananModel::create([
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'id_trip'         => $idTrip,
            'biaya_bbm'       => $bbm,
            'uang_jalan'      => $uangJalan,
            'jarak_tempuh_km' => $jarak,
        ]);
    }

    private function rekap(array $filter = []): Collection
    {
        return app(LaporanOperasionalRepositoryInterface::class)
            ->rekapTripPerSupir(self::PERUSAHAAN_ID, $filter);
    }

    private function seedRekap(): void
    {
        $armada = $this->makeArmada();
        $asep   = $this->makeSupir('Asep Rekap');

        $tripA1 = $this->makeTrip($armada->id_armada, $asep->id_supir, '2026-03-05 08:00:00');
        $laporanA1 = $this->makeLaporan($tripA1->id_trip, 100000, 50000, 100);
        BiayaLainTripModel::create([
            'id_laporan' => $laporanA1->id_laporan,
            'nama_biaya' => 'Tol',
            'nominal'    => 25000,
        ]);

        $tripA2 = $this->makeTrip($armada->id_armada, $asep->id_supir, '2026-03-20 08:00:00');
        $this->makeLaporan($tripA2->id_trip, 200000, 0, 150);

        $this->makeTrip($armada->id_armada, $asep->id_supir, '2026-03-25 08:00:00', 'dibatalkan');

        $this->makeTripVendor('Vendor Rekap Satu', '2026-03-10 09:00:00');
        $this->makeTripTanpaSupir($armada->id_armada, '2026-03-12 07:00:00');
    }

    public function test_rekap_menghitung_agregat_per_supir(): void
    {
        $this->seedRekap();

        $rows = $this->rekap()->keyBy('nama_supir');

        $this->assertCount(2, $rows);

        $asep = $rows['Asep Rekap'];
        $this->assertSame('internal', $asep->sumber);
        $this->assertSame(3, (int) $asep->jumlah_trip);
        $this->assertSame(2, (int) $asep->selesai);
        $this->assertSame(1, (int) $asep->dibatalkan);
        $this->assertEquals(250, (float) $asep->total_jarak_km);
        $this->assertEquals(375000, (float) $asep->total_biaya);
        $this->assertSame('2026-03-25 08:00:00', $asep->trip_terakhir);

        $vendor = $rows['Vendor Rekap Satu'];
        $this->assertSame('vendor', $vendor->sumber);
        $this->assertSame(1, (int) $vendor->jumlah_trip);
        $this->assertEquals(0, (float) $vendor->total_biaya);
    }

    public function test_filter_periode_sumber_dan_status_bekerja(): void
    {
        $this->seedRekap();

        $periode = $this->rekap(['dari' => '2026-03-01', 'sampai' => '2026-03-15'])->keyBy('nama_supir');
        $this->assertSame(1, (int) $periode['Asep Rekap']->jumlah_trip);
        $this->assertSame(1, (int) $periode['Vendor Rekap Satu']->jumlah_trip);

        $vendorSaja = $this->rekap(['sumber' => 'vendor']);
        $this->assertCount(1, $vendorSaja);
        $this->assertSame('Vendor Rekap Satu', $vendorSaja->first()->nama_supir);

        $selesaiSaja = $this->rekap(['status' => 'selesai'])->keyBy('nama_supir');
        $this->assertSame(2, (int) $selesaiSaja['Asep Rekap']->jumlah_trip);
        $this->assertSame(0, (int) $selesaiSaja['Asep Rekap']->dibatalkan);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal dengan alasan benar**

Run: `vendor/bin/phpunit --filter=TripRekapSupirExportTest`
Expected: ERROR/FAIL dengan pesan `Call to undefined method ...rekapTripPerSupir` (method belum ada). Kalau gagal karena seeding (kolom tidak dikenal), perbaiki seed dulu sampai kegagalan tepat di method yang belum ada.

- [ ] **Step 3: Tambah method di interface**

Di `LaporanOperasionalRepositoryInterface.php`, setelah method `armadaAktif` tambahkan:

```php
    /**
     * Rekap agregat trip per supir (internal & vendor) untuk export tab Riwayat.
     */
    public function rekapTripPerSupir(string $idPerusahaan, array $filter): Collection;
```

- [ ] **Step 4: Implementasi method di repository**

Di `LaporanOperasionalRepository.php`, setelah method `ringkasanTrip` tambahkan:

```php
    public function rekapTripPerSupir(string $idPerusahaan, array $filter): \Illuminate\Support\Collection
    {
        return $this->baseTripQuery($idPerusahaan, $filter)
            ->whereRaw('coalesce(s.id_supir, sv.id_supir_vendor) is not null')
            ->when($filter['status'] ?? null,
                fn ($q, $v) => $q->where('t.status', $v),
                fn ($q) => $q->whereIn('t.status', ['selesai', 'dibatalkan']))
            ->groupBy(
                DB::raw('coalesce(s.id_supir, sv.id_supir_vendor)'),
                DB::raw('coalesce(s.nama, sv.nama)'),
                'p.sumber',
            )
            ->selectRaw('coalesce(s.nama, sv.nama) as nama_supir')
            ->selectRaw('p.sumber')
            ->selectRaw('count(t.id_trip) as jumlah_trip')
            ->selectRaw("sum(t.status = 'selesai') as selesai")
            ->selectRaw("sum(t.status = 'dibatalkan') as dibatalkan")
            ->selectRaw('coalesce(sum(coalesce(lp.jarak_tempuh_km, 0)), 0) as total_jarak_km')
            ->selectRaw('coalesce(sum(coalesce(lp.biaya_bbm,0) + coalesce(lp.uang_jalan,0) + coalesce(lp.uang_tol,0) + coalesce(bl.total_lain,0)), 0) as total_biaya')
            ->selectRaw('max(jk.waktu_berangkat) as trip_terakhir')
            ->orderBy('nama_supir')
            ->get();
    }
```

Catatan: file ini sudah `use Illuminate\Support\Facades\DB;`. Untuk return type, cek bagian `use` — bila `Illuminate\Support\Collection` belum di-import (interface-nya sudah), samakan dengan gaya interface: import `Illuminate\Support\Collection` dan pakai `Collection` sebagai return type, jangan FQCN.

- [ ] **Step 5: Jalankan test lagi, pastikan hijau**

Run: `vendor/bin/phpunit --filter=TripRekapSupirExportTest`
Expected: OK (2 tests)

- [ ] **Step 6: Laporkan file berubah (tanpa commit)**

Sebutkan 3 file yang dibuat/diubah di ringkasan task.

---

### Task 2: Endpoint export Excel + PDF di modul Trip

**Files:**
- Create: `app/Modules/Trip/Exports/RekapTripSupirExport.php`
- Create: `resources/views/exports/rekap-trip-supir.blade.php`
- Modify: `app/Modules/Trip/TripService.php` (constructor + 1 method)
- Modify: `app/Modules/Trip/TripController.php` (imports + 3 method)
- Modify: `app/Modules/Trip/TripServiceProvider.php` (2 route)
- Test (modify): `tests/Feature/TripRekapSupirExportTest.php` (tambah 2 test endpoint)

**Interfaces:**
- Consumes: `LaporanOperasionalRepositoryInterface::rekapTripPerSupir(string $idPerusahaan, array $filter): Collection` dari Task 1; trait `App\Support\Exports\DenganGayaLaporan` (butuh method `judulLaporan(): string` dan `subjudulLaporan(): string`).
- Produces: `GET /api/v1/trip/rekap-supir/export/excel` dan `.../pdf` (query opsional: `dari`, `sampai`, `sumber`, `status`) — dipakai Task 3.

- [ ] **Step 1: Tambah 2 test endpoint (RED)**

Di akhir class `TripRekapSupirExportTest` tambahkan:

```php
    public function test_export_excel_terunduh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->seedRekap();

        $res = $this->get('/api/v1/trip/rekap-supir/export/excel?dari=2026-03-01&sampai=2026-03-31');

        $res->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('content-type'));
        $this->assertStringContainsString('rekap-trip-supir-', (string) $res->headers->get('content-disposition'));
    }

    public function test_export_pdf_terunduh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->seedRekap();

        $res = $this->get('/api/v1/trip/rekap-supir/export/pdf');

        $res->assertStatus(200);
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringContainsString('rekap-trip-supir-', (string) $res->headers->get('content-disposition'));
    }
```

- [ ] **Step 2: Jalankan, pastikan gagal 404**

Run: `vendor/bin/phpunit --filter=TripRekapSupirExportTest`
Expected: 2 test baru FAIL (`Expected response status code [200] but received 404`), 2 test lama tetap PASS.

- [ ] **Step 3: Buat export class**

Buat `app/Modules/Trip/Exports/RekapTripSupirExport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Trip\Exports;

use App\Support\Exports\DenganGayaLaporan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class RekapTripSupirExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithEvents
{
    use DenganGayaLaporan;

    public function __construct(
        private readonly Collection $data,
        private readonly ?string $dari = null,
        private readonly ?string $sampai = null,
    ) {}

    public function judulLaporan(): string
    {
        return 'REKAP TRIP PER SUPIR';
    }

    public function subjudulLaporan(): string
    {
        if ($this->dari && $this->sampai) {
            return 'Periode: ' . date('d/m/Y', strtotime($this->dari)) . ' — ' . date('d/m/Y', strtotime($this->sampai));
        }
        return 'Semua Periode';
    }

    public function collection(): Collection
    {
        return $this->data;
    }

    public function headings(): array
    {
        return ['Nama Supir', 'Sumber', 'Jumlah Trip', 'Selesai', 'Dibatalkan', 'Total Jarak (km)', 'Total Biaya', 'Trip Terakhir'];
    }

    public function map($row): array
    {
        return [
            $row->nama_supir,
            ($row->sumber ?? 'internal') === 'vendor' ? 'Vendor' : 'Internal',
            (int) $row->jumlah_trip,
            (int) $row->selesai,
            (int) $row->dibatalkan,
            (float) $row->total_jarak_km,
            (float) $row->total_biaya,
            $row->trip_terakhir ? date('d/m/Y H:i', strtotime($row->trip_terakhir)) : '-',
        ];
    }
}
```

- [ ] **Step 4: Buat blade PDF**

Buat `resources/views/exports/rekap-trip-supir.blade.php` (gaya sama dengan `exports/laporan-trip.blade.php`):

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Rekap Trip per Supir</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; }
        h2 { margin-bottom: 4px; }
        p { margin: 2px 0 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 5px 7px; text-align: left; }
        th { background: #f4f4f4; font-weight: bold; }
        tr:nth-child(even) { background: #fafafa; }
        .amount { text-align: right; }
    </style>
</head>
<body>
    <h2>Rekap Trip per Supir</h2>
    <p>Dicetak: {{ now()->format('d M Y H:i') }}</p>
    <table>
        <thead>
            <tr>
                <th>Nama Supir</th>
                <th>Sumber</th>
                <th class="amount">Jumlah Trip</th>
                <th class="amount">Selesai</th>
                <th class="amount">Dibatalkan</th>
                <th class="amount">Total Jarak (km)</th>
                <th class="amount">Total Biaya</th>
                <th>Trip Terakhir</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
            <tr>
                <td>{{ $item->nama_supir }}</td>
                <td>{{ ($item->sumber ?? 'internal') === 'vendor' ? 'Vendor' : 'Internal' }}</td>
                <td class="amount">{{ (int) $item->jumlah_trip }}</td>
                <td class="amount">{{ (int) $item->selesai }}</td>
                <td class="amount">{{ (int) $item->dibatalkan }}</td>
                <td class="amount">{{ number_format($item->total_jarak_km ?? 0, 2, ',', '.') }}</td>
                <td class="amount">Rp {{ number_format($item->total_biaya ?? 0, 0, ',', '.') }}</td>
                <td>{{ $item->trip_terakhir ? \Illuminate\Support\Carbon::parse($item->trip_terakhir)->format('d/m/Y H:i') : '-' }}</td>
            </tr>
            @empty
            <tr><td colspan="8" style="text-align:center">Tidak ada data</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
```

- [ ] **Step 5: Tambah method di TripService**

Di `TripService.php`: tambah import `use App\Modules\LaporanOperasional\Contracts\LaporanOperasionalRepositoryInterface;` dan `use Illuminate\Support\Collection;`, tambah parameter constructor terakhir `private readonly LaporanOperasionalRepositoryInterface $laporanRepo`, lalu tambah method:

```php
    public function rekapSupir(string $idPerusahaan, array $filter): Collection
    {
        return $this->laporanRepo->rekapTripPerSupir($idPerusahaan, $filter);
    }
```

- [ ] **Step 6: Tambah handler di TripController**

Di `TripController.php`: tambah imports:

```php
use App\Modules\Trip\Exports\RekapTripSupirExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
```

Tambah 3 method di dalam class:

```php
    private function rekapFilter(Request $request): array
    {
        return [
            'dari'   => $request->query('dari'),
            'sampai' => $request->query('sampai'),
            'sumber' => $request->query('sumber'),
            'status' => $request->query('status'),
        ];
    }

    public function exportRekapSupirExcel(Request $request): BinaryFileResponse
    {
        $idPerusahaan = (string) auth()->user()?->id_perusahaan;
        $filter = $this->rekapFilter($request);
        $items = $this->service->rekapSupir($idPerusahaan, $filter);

        return Excel::download(
            new RekapTripSupirExport($items, $filter['dari'], $filter['sampai']),
            'rekap-trip-supir-' . date('Ymd') . '.xlsx'
        );
    }

    public function exportRekapSupirPdf(Request $request): Response
    {
        $idPerusahaan = (string) auth()->user()?->id_perusahaan;
        $items = $this->service->rekapSupir($idPerusahaan, $this->rekapFilter($request));

        $pdf = Pdf::loadView('exports.rekap-trip-supir', ['items' => $items]);

        return $pdf->download('rekap-trip-supir-' . date('Ymd') . '.pdf');
    }
```

- [ ] **Step 7: Daftarkan route SEBELUM `trip/{id}`**

Di `TripServiceProvider.php`, sisipkan dua baris ini setelah `Route::get('trip/settlement', ...)` (baris 32) dan sebelum `Route::get('trip/{id}', ...)`:

```php
                Route::get('trip/rekap-supir/export/excel', [TripController::class, 'exportRekapSupirExcel']);
                Route::get('trip/rekap-supir/export/pdf', [TripController::class, 'exportRekapSupirPdf']);
```

- [ ] **Step 8: Jalankan test file, pastikan 4 test hijau**

Run: `vendor/bin/phpunit --filter=TripRekapSupirExportTest`
Expected: OK (4 tests)

- [ ] **Step 9: Jalankan suite penuh backend**

Run: `vendor/bin/phpunit`
Expected: OK semua (regresi nol; constructor `TripService` bertambah dependency — test lain yang memakai TripService harus tetap hijau karena resolve via container).

- [ ] **Step 10: Laporkan file berubah (tanpa commit)**

---

### Task 3: Frontend — tombol Export Excel/PDF di tab Riwayat

**Files:**
- Modify: `src/constants/api.constant.ts` (setelah baris `TRIP_LAPORAN_PERJALANAN`, sekitar baris 268)
- Modify: `src/app/(protected-pages)/trip/RiwayatTripTab.tsx`

**Interfaces:**
- Consumes: endpoint Task 2 lewat proxy: `/api/proxy/trip/rekap-supir/export/excel|pdf` dengan params `dari`, `sampai`, `sumber`, `status` (semua opsional). Proxy sudah mendukung respons biner.
- Produces: tidak ada (task terakhir).

- [ ] **Step 1: Tambah konstanta endpoint**

Di `api.constant.ts` setelah baris `TRIP_LAPORAN_PERJALANAN: ...` tambahkan:

```ts
    TRIP_REKAP_SUPIR_EXPORT_EXCEL: '/api/proxy/trip/rekap-supir/export/excel',
    TRIP_REKAP_SUPIR_EXPORT_PDF:   '/api/proxy/trip/rekap-supir/export/pdf',
```

- [ ] **Step 2: Tambah tombol & logika download di RiwayatTripTab.tsx**

Ubah imports (baris 1–13): tambah `axios`, `Button` ke import `@/components/ui`, `HiOutlineDownload` ke import `react-icons/hi`, dan `API_ENDPOINTS`:

```tsx
import axios from 'axios'
import { Card, Input, Tag, Tooltip, toast, Notification, Button } from '@/components/ui'
import { HiOutlineSearch, HiOutlineX, HiOutlineEye, HiOutlineDownload } from 'react-icons/hi'
import { API_ENDPOINTS } from '@/constants/api.constant'
```

Di dalam komponen, setelah state `sampai` tambahkan:

```tsx
    const [downloading, setDownloading] = useState<'excel' | 'pdf' | null>(null)
```

Setelah `handleSearchClear` tambahkan:

```tsx
    const downloadFile = async (url: string, filename: string, key: 'excel' | 'pdf') => {
        setDownloading(key)
        try {
            const res = await axios.get(url, {
                responseType: 'blob',
                params: {
                    dari: dari ? dayjs(dari).format('YYYY-MM-DD') : undefined,
                    sampai: sampai ? dayjs(sampai).format('YYYY-MM-DD') : undefined,
                    sumber: sumberFilter || undefined,
                    status: statusFilter || undefined,
                },
            })
            const href = URL.createObjectURL(res.data)
            const link = document.createElement('a')
            link.href = href
            link.download = filename
            document.body.appendChild(link)
            link.click()
            document.body.removeChild(link)
            URL.revokeObjectURL(href)
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setDownloading(null)
        }
    }

    const today = dayjs().format('YYYY-MM-DD')
    const handleExportExcel = () => downloadFile(API_ENDPOINTS.TRIP_REKAP_SUPIR_EXPORT_EXCEL, `rekap-trip-supir-${today}.xlsx`, 'excel')
    const handleExportPdf   = () => downloadFile(API_ENDPOINTS.TRIP_REKAP_SUPIR_EXPORT_PDF, `rekap-trip-supir-${today}.pdf`, 'pdf')
```

Di JSX baris filter, setelah `</div>` penutup Select sumber (sebelum penutup div `flex flex-wrap`), tambahkan:

```tsx
                <Button variant="default" size="sm" icon={<HiOutlineDownload />}
                    loading={downloading === 'excel'} onClick={handleExportExcel}>
                    Export Excel
                </Button>
                <Button variant="default" size="sm" icon={<HiOutlineDownload />}
                    loading={downloading === 'pdf'} onClick={handleExportPdf}>
                    Export PDF
                </Button>
```

- [ ] **Step 3: Lint**

Run (dari folder frontend): `npx eslint "src/app/(protected-pages)/trip/RiwayatTripTab.tsx" "src/constants/api.constant.ts"`
Expected: tanpa output (bersih).

- [ ] **Step 4: Laporkan file berubah (tanpa commit)**

Ingatkan user: verifikasi visual dengan buka `/trip` tab Riwayat → dua tombol muncul → unduh Excel/PDF berisi rekap sesuai filter. Build/jalankan dev server dilakukan user sendiri.
