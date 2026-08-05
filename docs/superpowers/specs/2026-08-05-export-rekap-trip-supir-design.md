# Export Rekap Trip per Supir — Halaman Trip (Tab Riwayat)

Tanggal: 2026-08-05
Status: disetujui user (chat), menunggu review spec

## Ringkasan

Tab Riwayat pada halaman Trip (`/trip`) mendapat dua tombol export — Excel dan PDF — yang mengunduh **rekap agregat per supir**, bukan daftar trip per baris. Rekap mengikuti filter yang sedang aktif di tab (rentang tanggal, sumber, status). Fitur laporan list-trip yang sudah ada di halaman Laporan Operasional tidak berubah.

## Keputusan Desain (hasil brainstorming)

| Keputusan | Pilihan |
|---|---|
| Bentuk data | Rekap per supir (1 baris = 1 supir) |
| Format | Excel (.xlsx) + PDF |
| Kolom | Nama Supir, Sumber, Jumlah Trip, Selesai, Dibatalkan, Total Jarak (km), Total Biaya, Trip Terakhir |
| Arsitektur | Route di modul Trip (`izin:trip`); query agregasi di `LaporanOperasionalRepository` via interface (opsi A) |
| Filter search | Tidak ikut ke export (search menyaring teks per-trip, ambigu untuk rekap) |

## Backend

### Query agregasi — `LaporanOperasionalRepository::rekapTripPerSupir(string $idPerusahaan, array $filter): Collection`

- Dibangun di atas `baseTripQuery` (join trip → jadwal → penugasan → proyek/armada/supir/vendor/laporan_perjalanan/biaya_lain sudah tersedia di sana).
- `groupBy` tiga kolom sekaligus agar lolos `ONLY_FULL_GROUP_BY`: kunci supir gabungan `coalesce(s.id_supir, sv.id_supir_vendor)`, `coalesce(s.nama, sv.nama)`, dan `p.sumber`. Trip tanpa supir (kedua kolom id null) **dikecualikan** via `whereNotNull` pada coalesce (raw).
- Kolom hasil per baris:
  - `nama_supir` — `coalesce(s.nama, sv.nama)`
  - `sumber` — `internal` / `vendor` (dari `p.sumber`)
  - `jumlah_trip` — `count(t.id_trip)`
  - `selesai` — `sum(t.status = 'selesai')`
  - `dibatalkan` — `sum(t.status = 'dibatalkan')`
  - `total_jarak_km` — `sum(coalesce(lp.jarak_tempuh_km, 0))`
  - `total_biaya` — `sum(coalesce(lp.biaya_bbm,0) + coalesce(lp.uang_jalan,0) + coalesce(lp.uang_tol,0) + coalesce(bl.total_lain,0))`
  - `trip_terakhir` — `max(jk.waktu_berangkat)`
- Urutan: `nama_supir` A–Z.
- Filter yang didukung: `dari`, `sampai`, `sumber` (sudah ada di `baseTripQuery`) + `status` (baru, param opsional): tanpa param → `whereIn('t.status', ['selesai','dibatalkan'])`; dengan param (`selesai` atau `dibatalkan`) → filter status tunggal itu.
- Method didaftarkan di `LaporanOperasionalRepositoryInterface`.

### Endpoint — modul Trip (`izin:trip`)

```
GET /api/v1/trip/rekap-supir/export/excel
GET /api/v1/trip/rekap-supir/export/pdf
Query: dari, sampai, sumber, status (semua opsional)
```

- Didaftarkan di `TripServiceProvider` **sebelum** route `trip/{id}` agar tidak tertelan parameter dinamis.
- `TripService` inject `LaporanOperasionalRepositoryInterface` (preseden cross-module: Payroll → AbsensiService, Absensi → KaryawanRepositoryInterface).
- Excel: `Excel::download(new RekapTripSupirExport(...), 'rekap-trip-supir-YYYYMMDD.xlsx')`.
- PDF: `Pdf::loadView('exports.rekap-trip-supir', [...])->download('rekap-trip-supir-YYYYMMDD.pdf')`.

### Export class & view

- `app/Modules/Trip/Exports/RekapTripSupirExport.php` — `FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithEvents` + trait `DenganGayaLaporan`. Judul "REKAP TRIP PER SUPIR", subjudul periode `dd/mm/yyyy — dd/mm/yyyy` atau "Semua Periode" (pola `LaporanTripExport`).
- `resources/views/exports/rekap-trip-supir.blade.php` — meniru gaya `exports/laporan-trip.blade.php`.
- Format angka: jarak & biaya tampil apa adanya (numeric), `trip_terakhir` format `dd/mm/YYYY HH:mm`, sumber tampil "Internal"/"Vendor".

## Frontend — `RiwayatTripTab.tsx`

- Dua tombol di baris filter (kanan): "Export Excel" & "Export PDF", `variant="default" size="sm"` dengan loading state per tombol — meniru pola `downloadFile` (blob + anchor click) di `LaporanTripTab.tsx`.
- Param yang dikirim: `dari`/`sampai` (dari DatePicker, format `YYYY-MM-DD`), `sumber` (jika dipilih), `status` (jika dropdown bukan "Semua Riwayat"). Search **tidak** dikirim.
- Endpoint baru di `API_ENDPOINTS`: `TRIP_REKAP_SUPIR_EXPORT_EXCEL`, `TRIP_REKAP_SUPIR_EXPORT_PDF` (`/api/proxy/trip/rekap-supir/export/...`).
- Nama file unduhan: `rekap-trip-supir-YYYY-MM-DD.xlsx` / `.pdf`.

## Edge Case

- Hasil rekap kosong → file tetap terunduh dengan tabel kosong (konsisten export lain).
- `jarak_tempuh_km` / biaya null (laporan perjalanan belum diisi) → dihitung 0.
- Supir sama muncul di trip internal & vendor tidak mungkin tercampur (kunci group berbeda tabel).

## Testing (TDD)

`tests/Feature/TripRekapSupirExportTest.php`:

1. Seed: 1 supir internal (2 trip selesai + 1 dibatalkan, dengan laporan perjalanan berisi jarak & biaya + biaya lain), 1 supir vendor (1 trip selesai), 1 trip tanpa supir.
2. Assert angka agregat lewat `LaporanOperasionalRepositoryInterface::rekapTripPerSupir`: jumlah baris (trip tanpa supir tidak muncul), jumlah_trip, selesai/dibatalkan, total_jarak_km, total_biaya, trip_terakhir.
3. Assert filter: `dari`/`sampai` memotong periode, `sumber=vendor` hanya supir vendor, `status=selesai` mengecualikan yang dibatalkan.
4. Endpoint smoke test: excel & pdf balas 200 dengan `content-type` xlsx/pdf dan nama file di `content-disposition`.

## Di Luar Scope

- Rekap per armada / per proyek.
- Search free-text pada export.
- Perubahan apapun pada halaman Laporan Operasional dan endpoint existing.
