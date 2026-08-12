# Fase 0 — Perjelas Mekanisme Vendor

**Tanggal:** 2026-08-10
**Status:** Disetujui user
**Konteks:** Prasyarat fitur rekap penagihan klien (Fase 1) & konsolidasi vendor
(Fase 2). Keputusan user: trip bersupir vendor dicatat ops via web; tanggungan
biaya bertingkat per mekanisme kontrak.

## Masalah

1. Trip bersumber vendor tidak menampilkan mekanisme kontraknya — ops tidak
   langsung tahu trip mana yang jadi tanggung jawabnya untuk dicatat manual dan
   biaya apa yang boleh diisi.
2. Laporan perjalanan menerima semua field biaya untuk semua trip — biaya yang
   sebenarnya ditanggung vendor (mis. BBM di kontrak `full`) bisa ikut tercatat
   sebagai biaya kita dan mencemari rekap.
3. Kolom `satuan` rate kontrak vendor bebas teks di backend (frontend sudah
   select) — nilai liar menghambat perhitungan otomatis konsolidasi nanti.

## Solusi

### 1. Ekspos mekanisme di Trip (backend + frontend)

- `TripRepository` (hidrasi aktor list & detail): selain `nama_vendor`, ambil juga
  `mekanisme` dari `kontrak_vendor` penugasan trip.
- `TripResource`: field baru `mekanisme` (`unit_only|unit_driver|full|null`).
- Frontend `trip/[id]` (detail): di samping info vendor tampil badge mekanisme
  (label: Unit Only / Unit + Driver / Full, warna mengikuti `MEKANISME_CLASS`
  yang sudah ada di PenugasanVendorTab).
- Frontend daftar trip: baris trip vendor sudah menampilkan nama vendor; tambah
  label mekanisme kecil di sebelahnya.

### 2. Laporan perjalanan sadar-mekanisme

Tanggungan bertingkat (keputusan user):

| Mekanisme | biaya_bbm+liter+jenis | uang_tol | uang_jalan | biaya_lain |
|---|---|---|---|---|
| internal / `unit_only` | kita | kita | kita | kita |
| `unit_driver` | kita | kita | **vendor** | kita |
| `full` | **vendor** | **vendor** | **vendor** | **vendor** |

- **Backend** — guard terpusat di `LaporanPerjalananService` (dipakai
  `createForTrip`, `update`, `upsertUntukSupir`): resolve penugasan trip via
  `TripRepositoryInterface::findPenugasanDariTrip`; bila `sumber=vendor`, ambil
  `mekanisme` kontraknya; field yang jadi tanggungan vendor **ditolak 422** bila
  dikirim bernilai > 0 / tidak kosong, pesan mis.
  `"Uang jalan ditanggung vendor (kontrak Unit + Driver)"` /
  `"Biaya operasional ditanggung vendor (kontrak Full)"`.
  Field selalu boleh: `jarak_tempuh_km`, `catatan_insiden`, `foto`.
- **Frontend** — dialog laporan di `trip/[id]/page.tsx`: field tanggungan vendor
  **disembunyikan** sesuai `mekanisme` trip + info kecil "Biaya X ditanggung
  vendor sesuai kontrak"; nilai yang dikirim untuk field tersembunyi
  dipaksa 0/kosong.
- **Mobile tidak berubah**: hanya supir internal yang memakai aplikasi
  (`unit_only` = semua field tanggungan kita). Guard backend tetap berlaku
  sebagai pengaman.

### 3. Validasi satuan rate kontrak (backend saja)

`StoreKontrakVendorRequest` + `UpdateKontrakVendorRequest`: `satuan` →
`in:per trip,per ton,per hari,per bulan,lumpsum` (tetap nullable) — mengikuti
`SATUAN_OPTIONS` yang sudah dipakai frontend. Data lama di luar daftar tetap
tampil di detail; baru dipaksa valid saat diedit.

## Perilaku Tepi

- Trip internal → guard tidak aktif, semua field seperti sekarang.
- Penugasan vendor tanpa kontrak tidak mungkin ada (validasi penugasan sudah
  mewajibkan kontrak) — bila data lama menyimpang (kontrak terhapus), guard
  memperlakukan sebagai internal (tidak memblokir).
- Laporan lama trip vendor yang terlanjur berisi biaya: tidak di-backfill/diubah —
  guard hanya berlaku untuk simpan/edit berikutnya.

## Testing (PHPUnit)

- Laporan trip vendor `unit_driver`: kirim `uang_jalan > 0` → 422; tanpa
  uang_jalan (bbm+tol terisi) → 200.
- Laporan trip vendor `full`: kirim `biaya_bbm > 0` (atau `biaya_lain`) → 422;
  hanya `jarak_tempuh_km` + catatan → 200.
- Trip internal → semua field tetap diterima (regresi).
- Detail trip vendor memuat `mekanisme`.
- Kontrak vendor dengan `satuan` liar → 422; `per trip` → 200.

## Di Luar Cakupan

- Akun aplikasi untuk supir vendor.
- Migration/backfill data lama (satuan liar & laporan lama dibiarkan).
- Fase 1 (rekap penagihan klien) & Fase 2 (konsolidasi vendor) — spec terpisah.
