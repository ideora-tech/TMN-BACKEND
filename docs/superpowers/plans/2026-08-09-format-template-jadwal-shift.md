# Seragamkan Format Template Jadwal Shift — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Template import jadwal shift tampil dengan format visual yang sama seperti file export jadwal shift (judul kuning, periode, header biru, angka hari), dan parser import toleran terhadap baris judul.

**Architecture:** Template tetap digenerate backend (maatwebsite/excel); styling ditambahkan via `WithEvents/AfterSheet` meniru gaya `buatXlsx` frontend. Header tanggal ditulis sebagai nilai tanggal Excel ber-format `d` sehingga visual = angka hari tapi nilai tetap tanggal penuh. Parser `importMatriks` mencari baris header secara dinamis (kolom pertama = `No SIM`).

**Tech Stack:** Laravel 11, maatwebsite/excel + PhpSpreadsheet, PHPUnit (SQLite in-memory).

**Spec:** `docs/superpowers/specs/2026-08-09-format-template-jadwal-shift-design.md`

## Global Constraints

- **DILARANG `git commit` / menyentuh git state** — user commit manual.
- **DILARANG build/migrate/deploy** — user jalankan sendiri.
- Test: `vendor/bin/phpunit` (JANGAN `php artisan test`).
- Eloquent/query builder hanya di Repository; Repository wajib implement interface `Contracts/`.
- Tanpa komentar penjelas di kode; teks pesan error bahasa Indonesia.
- Kontrak kolom import TIDAK berubah: `No SIM | Nama Supir | Shift | tanggal...`, sel `H` = jadwal.

---

### Task 1: Parser import toleran baris judul + header tanggal serial Excel

**Files:**
- Modify: `TMN-TRANSPORT-BACKEND/app/Modules/JadwalShift/JadwalShiftService.php:157-171` (awal `importMatriks`)
- Test: `TMN-TRANSPORT-BACKEND/tests/Feature/JadwalShiftImportTest.php` (2 test baru)

**Interfaces:**
- Consumes: helper test `buatFileMatriks(array $rows): UploadedFile` (sudah ada di test yang sama, baris 72-80), `makeProyek()`, `makeSupir()`, `makeShiftNamed()`, `PenugasanModel`.
- Produces: `importMatriks` menerima file dengan baris judul apa pun di atas baris header `No SIM` — dipakai Task 2 (roundtrip template ber-judul).

- [x] **Step 1: Tulis 2 failing test**

Tambahkan di `JadwalShiftImportTest` (setelah `test_import_matriks_membuat_jadwal_dan_alokasi`):

```php
    public function test_import_dengan_baris_judul_di_atas_header_tetap_sukses(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $idSupir = $this->makeSupir('Supir Judul', 'SIM-JUDUL-1');
        PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $proyek->id_proyek,
            'id_supir'      => $idSupir,
            'status'        => 'aktif',
            'tanggal_tugas' => now()->toDateString(),
        ]);
        $this->makeShiftNamed('Pagi');

        $file = $this->buatFileMatriks([
            ['PROYEK IMPORT TEST', '', '', ''],
            ['PERIODE AGUSTUS 2026', '', '', ''],
            ['No SIM', 'Nama Supir', 'Shift', '2026-08-10'],
            ['SIM-JUDUL-1', 'Supir Judul', 'Pagi', 'H'],
        ]);

        $this->post('/api/v1/jadwal-shift/import', [
            'id_proyek' => $proyek->id_proyek,
            'file'      => $file,
        ])->assertStatus(200)->assertJsonPath('data.sukses', 1);

        $this->assertDatabaseHas('jadwal_shift', ['id_supir' => $idSupir, 'tanggal' => '2026-08-10']);
    }

    public function test_import_header_tanggal_serial_excel_terbaca(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $idSupir = $this->makeSupir('Supir Serial', 'SIM-SERIAL-1');
        PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $proyek->id_proyek,
            'id_supir'      => $idSupir,
            'status'        => 'aktif',
            'tanggal_tugas' => now()->toDateString(),
        ]);
        $this->makeShiftNamed('Pagi');

        $serial = \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTime('2026-08-15'));
        $file = $this->buatFileMatriks([
            ['No SIM', 'Nama Supir', 'Shift', $serial],
            ['SIM-SERIAL-1', 'Supir Serial', 'Pagi', 'H'],
        ]);

        $this->post('/api/v1/jadwal-shift/import', [
            'id_proyek' => $proyek->id_proyek,
            'file'      => $file,
        ])->assertStatus(200)->assertJsonPath('data.sukses', 1);

        $this->assertDatabaseHas('jadwal_shift', ['id_supir' => $idSupir, 'tanggal' => '2026-08-15']);
    }
```

Catatan: test kedua kemungkinan sudah lolos (branch numerik `parseTanggalHeader` sudah ada) — dia guard regresi untuk Task 2, biarkan tetap ada.

- [x] **Step 2: Jalankan test, pastikan test pertama gagal**

Run: `vendor/bin/phpunit --filter="test_import_dengan_baris_judul_di_atas_header_tetap_sukses|test_import_header_tanggal_serial_excel_terbaca"`
Expected: test pertama FAIL (422 — kolom tanggal tidak ditemukan karena header dianggap baris 1); test kedua PASS.

- [x] **Step 3: Implementasi deteksi header dinamis**

Di `JadwalShiftService::importMatriks`, ganti blok baris 157-171 (mulai `$rows = ...` sampai guard `$tanggalKolom === []`):

```php
        $rows = \Maatwebsite\Excel\Facades\Excel::toArray(new \App\Modules\JadwalShift\Imports\JadwalShiftImport(), $file)[0] ?? [];
        if (count($rows) < 2) {
            abort(422, 'File kosong atau tidak berisi baris data');
        }

        $barisHeader = null;
        foreach ($rows as $i => $row) {
            if (strtolower(trim((string) ($row[0] ?? ''))) === 'no sim') {
                $barisHeader = $i;
                break;
            }
        }
        if ($barisHeader === null) {
            abort(422, 'Baris header (kolom "No SIM") tidak ditemukan');
        }

        $tanggalKolom = [];
        foreach (array_slice($rows[$barisHeader], 3, null, true) as $kolom => $nilai) {
            $tanggal = $this->parseTanggalHeader($nilai);
            if ($tanggal !== null) {
                $tanggalKolom[$kolom] = $tanggal;
            }
        }
        if ($tanggalKolom === []) {
            abort(422, 'Kolom tanggal tidak ditemukan pada baris header (mulai kolom ke-4)');
        }
```

Lalu di dalam `DB::transaction`, ganti `array_slice($rows, 1, null, true)` menjadi `array_slice($rows, $barisHeader + 1, null, true)` dan tambahkan `$barisHeader` ke daftar `use (...)` closure transaction.

Masih di loop data (setelah blok `if ($noSim === '' && $namaShift === '')`), tambahkan skip untuk baris supir yang belum diisi (shift kosong tanpa tanda `H`) — tanpa ini, import balik template kosong menghasilkan `gagal "Shift '' tidak ditemukan"` per baris supir:

```php
                if ($namaShift === '') {
                    $adaH = false;
                    foreach (array_keys($tanggalKolom) as $kolom) {
                        if (strtoupper(trim((string) ($row[$kolom] ?? ''))) === 'H') {
                            $adaH = true;
                            break;
                        }
                    }
                    if (!$adaH) {
                        continue;
                    }
                }
```

(Baris dengan `H` tapi shift kosong tetap jatuh ke `gagal` "Shift '' tidak ditemukan" — itu memang error user yang harus dilaporkan.)

- [x] **Step 4: Jalankan kedua test, pastikan lolos**

Run: `vendor/bin/phpunit --filter="test_import_dengan_baris_judul_di_atas_header_tetap_sukses|test_import_header_tanggal_serial_excel_terbaca"`
Expected: PASS (2 tests).

- [x] **Step 5: Jalankan seluruh JadwalShiftImportTest (regresi format lama)**

Run: `vendor/bin/phpunit --filter=JadwalShiftImportTest`
Expected: semua PASS. JANGAN commit.

---

### Task 2: Template ber-styling format export + roundtrip test

**Files:**
- Modify: `TMN-TRANSPORT-BACKEND/app/Modules/JadwalShift/Contracts/JadwalShiftRepositoryInterface.php` (tambah method)
- Modify: `TMN-TRANSPORT-BACKEND/app/Modules/JadwalShift/JadwalShiftRepository.php` (tambah method `namaProyek`)
- Modify: `TMN-TRANSPORT-BACKEND/app/Modules/JadwalShift/JadwalShiftService.php:120-141` (`templateData`)
- Modify: `TMN-TRANSPORT-BACKEND/app/Modules/JadwalShift/Exports/JadwalShiftTemplateExport.php` (restyle penuh)
- Modify: `TMN-TRANSPORT-BACKEND/app/Modules/JadwalShift/JadwalShiftController.php:43-46` (argumen constructor export)
- Test: `TMN-TRANSPORT-BACKEND/tests/Feature/JadwalShiftImportTest.php` (1 test roundtrip baru)

**Interfaces:**
- Consumes: parser toleran dari Task 1 (template ber-judul harus tetap importable); `namaProyek(string $idProyek): ?string` (didefinisikan di task ini).
- Produces: `templateData(...)` return `['supir', 'tanggal', 'nama_proyek', 'periode']`; `JadwalShiftTemplateExport::__construct(array $supirList, array $tanggalList, string $namaProyek, string $periode)`.

- [x] **Step 1: Tulis failing test roundtrip**

Tambahkan di `JadwalShiftImportTest`:

```php
    public function test_template_bergaya_tetap_bisa_diimport_balik(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $idSupir = $this->makeSupir('Supir Roundtrip', 'SIM-RT-1');
        PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $proyek->id_proyek,
            'id_supir'      => $idSupir,
            'status'        => 'aktif',
            'tanggal_tugas' => now()->toDateString(),
        ]);

        $res = $this->get("/api/v1/jadwal-shift/import/template?id_proyek={$proyek->id_proyek}&dari=2026-08-01&sampai=2026-08-31");
        $res->assertStatus(200);

        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
        copy($res->baseResponse->getFile()->getPathname(), $path);
        $file = new UploadedFile($path, 'template.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->post('/api/v1/jadwal-shift/import', [
            'id_proyek' => $proyek->id_proyek,
            'file'      => $file,
        ])->assertStatus(200)->assertJsonPath('data.sukses', 0)->assertJsonPath('data.gagal', []);

        $rows = \Maatwebsite\Excel\Facades\Excel::toArray(new \App\Modules\JadwalShift\Imports\JadwalShiftImport(), $file)[0];
        $this->assertSame('Proyek Import Test', trim((string) $rows[0][0]));
        $this->assertSame('PERIODE AGUSTUS 2026', trim((string) $rows[1][0]));
        $this->assertSame('No SIM', trim((string) $rows[2][0]));
    }
```

(Setelah restyle, baris 1 = nama proyek, baris 2 = periode, baris 3 = header — test ini gagal selama template masih polos karena `$rows[0][0]` = `No SIM`.)

- [x] **Step 2: Jalankan test, pastikan gagal**

Run: `vendor/bin/phpunit --filter=test_template_bergaya_tetap_bisa_diimport_balik`
Expected: FAIL (assert `Proyek Import Test` di baris 1, aktual `No SIM`).

- [x] **Step 3: Tambah `namaProyek` di interface + repository**

`Contracts/JadwalShiftRepositoryInterface.php` — tambah setelah `proyekMilikPerusahaan`:

```php
    public function namaProyek(string $idProyek): ?string;
```

`JadwalShiftRepository.php` — tambah setelah method `proyekMilikPerusahaan`:

```php
    public function namaProyek(string $idProyek): ?string
    {
        $nama = DB::table('proyek')
            ->whereNull('dihapus_pada')
            ->where('id_proyek', $idProyek)
            ->value('nama_proyek');

        return $nama !== null ? (string) $nama : null;
    }
```

- [x] **Step 4: `templateData` return nama proyek + periode**

Di `JadwalShiftService::templateData`, ganti blok `return` (baris 137-140):

```php
        $periode = $mulai->isSameMonth($selesai, true)
            ? 'PERIODE ' . mb_strtoupper($mulai->locale('id')->translatedFormat('F Y'))
            : 'PERIODE ' . $mulai->format('d/m/Y') . ' - ' . $selesai->format('d/m/Y');

        return [
            'supir'       => $this->repo->supirTerdaftarDiProyek($idProyek),
            'tanggal'     => $tanggal,
            'nama_proyek' => $this->repo->namaProyek($idProyek) ?? 'JADWAL SHIFT SUPIR',
            'periode'     => $periode,
        ];
```

- [x] **Step 5: Restyle `JadwalShiftTemplateExport`**

Ganti seluruh isi file:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class JadwalShiftTemplateExport implements FromArray, WithColumnWidths, WithEvents
{
    /**
     * @param array<int, object> $supirList
     * @param array<int, string> $tanggalList
     */
    public function __construct(
        private readonly array $supirList,
        private readonly array $tanggalList,
        private readonly string $namaProyek,
        private readonly string $periode,
    ) {}

    public function array(): array
    {
        $header = array_merge(['No SIM', 'Nama Supir', 'Shift'], $this->tanggalList);

        $rows = array_map(
            fn ($s) => array_merge(
                [$s->no_sim, $s->nama, ''],
                array_fill(0, count($this->tanggalList), '')
            ),
            $this->supirList
        );

        return array_merge([$header], $rows);
    }

    public function columnWidths(): array
    {
        $widths = ['A' => 18, 'B' => 26, 'C' => 12];
        foreach (array_keys($this->tanggalList) as $i) {
            $widths[\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 4)] = 5;
        }

        return $widths;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet   = $event->sheet->getDelegate();
                $lastCol = $sheet->getHighestColumn();

                $sheet->insertNewRowBefore(1, 2);

                $sheet->setCellValue('A1', $this->namaProyek);
                $sheet->mergeCells("A1:{$lastCol}1");
                $sheet->getStyle('A1')->applyFromArray([
                    'font'      => ['bold' => true, 'size' => 14],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFC000']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(26);

                $sheet->setCellValue('A2', $this->periode);
                $sheet->mergeCells("A2:{$lastCol}2");

                $barisHeader = 3;
                foreach (array_values($this->tanggalList) as $i => $tanggal) {
                    $kolom = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 4);
                    $sheet->setCellValue("{$kolom}{$barisHeader}", ExcelDate::PHPToExcel(new \DateTime($tanggal)));
                    $sheet->getStyle("{$kolom}{$barisHeader}")->getNumberFormat()->setFormatCode('d');
                }

                $sheet->getStyle("A{$barisHeader}:{$lastCol}{$barisHeader}")->applyFromArray([
                    'font'      => ['bold' => true],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'BDD7EE']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                ]);

                $lastRow = max($sheet->getHighestRow(), $barisHeader + 1);
                $sheet->getStyle("A{$barisHeader}:{$lastCol}{$lastRow}")->applyFromArray([
                    'borders' => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']],
                    ],
                ]);
            },
        ];
    }
}
```

- [x] **Step 6: Controller kirim argumen baru**

Di `JadwalShiftController::downloadTemplate` (baris 43-46):

```php
        return Excel::download(
            new JadwalShiftTemplateExport($data['supir'], $data['tanggal'], $data['nama_proyek'], $data['periode']),
            'template-jadwal-shift.xlsx'
        );
```

- [x] **Step 7: Jalankan test roundtrip + template lama, pastikan lolos**

Run: `vendor/bin/phpunit --filter="test_template_bergaya_tetap_bisa_diimport_balik|test_template_import_bisa_diunduh"`
Expected: PASS (2 tests).

- [x] **Step 8: Jalankan seluruh suite backend**

Run: `vendor/bin/phpunit`
Expected: semua PASS. JANGAN commit — user commit & verifikasi visual file unduhan sendiri.

---

## Verifikasi Manual (oleh user)

1. Unduh template dari Papan Shift → buka di Excel: baris 1 kuning nama proyek, baris 2 periode, header biru, kolom tanggal angka hari — mirip file `jadwal-shift-2026-08.xlsx`.
2. Isi kolom Shift + `H` di beberapa sel → import → sukses.
3. File template LAMA (yang sudah terlanjur diunduh) tetap bisa diimport.
