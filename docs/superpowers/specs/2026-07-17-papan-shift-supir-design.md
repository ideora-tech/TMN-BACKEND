# Design: Papan Jadwal Shift Supir (menu Penugasan)

**Tanggal:** 2026-07-17
**Status:** Approved user ("gas eksekusi" + referensi visual pilot-watch-schedule SIMPANDA)

## Latar Belakang

Papan "jadwal keberangkatan" yang sempat dibuat di menu Penugasan salah sasaran — yang dimaksud user adalah **jadwal SHIFT kerja** supir yang sudah di-assign ke project (seperti jadwal jaga/pilot-watch di SIMPANDA). Papan keberangkatan DIHAPUS, diganti papan shift.

Keputusan user (via AskUserQuestion + koreksi visual):
1. **Master data Shift** (nama + jam mulai + jam selesai, CRUD, bisa lintas tengah malam).
2. Orientasi awal baris=shift DIBATALKAN oleh user via referensi visual kedua (pilot-watch-schedule): **baris = SUPIR, sel = card shift kotak** (nama shift + rentang jam + ikon edit/hapus di card).
3. Papan shift **menggantikan** papan keberangkatan di mode "Papan Jadwal" menu Penugasan. Jadwal keberangkatan tetap dikelola di detail penugasan & halaman Jadwal (tidak dicampur).
4. **Maks 1 shift per supir per tanggal, GLOBAL lintas proyek** — dobel ditolak dengan pesan jelas.
5. Tanpa panel kanan — rekap ("N shift") nempel di kolom kiri per supir. Simpan langsung per aksi (tanpa draft/tombol Simpan besar seperti referensi).

## Skema Database (2 migration baru)

1. **`shift`**: `id_shift` char(36) PK, `id_perusahaan` char(36), `nama` varchar(100), `jam_mulai` TIME, `jam_selesai` TIME, `aktif` tinyint default 1, audit columns. Shift lintas tengah malam valid (jam_selesai < jam_mulai berarti berakhir hari berikutnya — durasi dihitung +24 jam).
2. **`jadwal_shift`**: `id_jadwal_shift` char(36) PK, `id_proyek` char(36), `id_shift` char(36), `id_supir` char(36), `tanggal` DATE, audit. Index `(id_supir, tanggal)` dan `(id_proyek, tanggal)`. Uniqueness 1-shift-per-supir-per-tanggal di-enforce APP-level (soft-delete aware), bukan DB unique.

## Backend (Query Builder penuh, pola batch sebelumnya)

### Modul baru `Shift` (`app/Modules/Shift/`)
Mirror struktur `JenisPerawatan` (master-data QB paling sederhana): apiResource `shift`, middleware `['api','auth:sanctum']`, scope `id_perusahaan`, RecordHelper, `private const COLUMNS`. Field form: nama (required), jam_mulai (required, format `H:i`), jam_selesai (required, `H:i`), aktif.

### Modul baru `JadwalShift` (`app/Modules/JadwalShift/`)
- **`GET /api/v1/jadwal-shift?id_proyek=&dari=&sampai=`** — list rentang tanggal (papan kirim awal & akhir bulan). Wajib `id_proyek`. Response row: `{id_jadwal_shift, id_proyek, id_shift, id_supir, tanggal, shift_nama, jam_mulai, jam_selesai}` (join `shift` — attach nama+jam supaya frontend tidak perlu join sendiri). Scope perusahaan via join `proyek.id_perusahaan`.
- **`POST /api/v1/jadwal-shift`** — batch: `{id_proyek, id_shift, tanggal, supir: [id_supir, ...]}`. Per supir divalidasi dalam transaksi: (a) punya penugasan internal status pending/aktif di proyek tsb (kalau tidak → gagal item "Supir tidak ter-assign ke proyek ini"); (b) belum punya jadwal_shift aktif di tanggal itu di proyek MANAPUN (kalau ada → gagal item dengan sebut nama shift + proyeknya). Response: `{sukses: n, gagal: [{id_supir, alasan}]}` — HTTP 200 walau sebagian gagal (pola batch penugasan frontend).
- **`PUT /api/v1/jadwal-shift/{id}`** — ganti shift baris itu: `{id_shift}` (tanggal & supir tetap).
- **`DELETE /api/v1/jadwal-shift/{id}`** — soft delete.
- Semua write pakai RecordHelper; validasi `exists:...` scoped `dihapus_pada,NULL` (pelajaran batch Pemeliharaan).

### Seed menu
Migration: menu "Shift" (`path /shift`, icon `calendar` [sudah ada], induk grup **Data Master** `m0000001-0000-4000-8000-000000000050`, urutan 7) + `menu_peran` ADMIN/SUPERADMIN/MANAGER/DISPATCHER (pola Jenis BBM/Lokasi). ID menu baru: `m0000001-0000-4000-8000-000000000057`.

## Frontend

### Master `/shift` (3 halaman, mirror pola `jenis-perawatan`)
List (kolom: Nama, Jam Mulai–Selesai, Status, Aksi) + `/shift/baru` + `/shift/[id]`. Input jam pakai `<Input type="time">`. Service `shift.service.ts` mirror `jenisPerawatan.service.ts`.

### `PapanShift.tsx` (menggantikan `PapanJadwal.tsx` — file lama DIHAPUS)
Dipakai di `penugasan/page.tsx` mode "Papan Jadwal" (prop `idProyek`), desain mengikuti referensi pilot-watch-schedule:
- **Kolom kiri sticky**: search nama supir + baris per supir: avatar inisial berwarna (palet hash), nama, nopol armada default (dari penugasan), "N shift" (count bulan tampil).
- **Header kolom**: nama hari (SEN/SEL/…) di atas angka tanggal; tanggal hari ini dilingkari biru; sebulan penuh scroll horizontal; navigasi bulan ‹ › + label.
- **Sel terisi** = card kotak: nama shift (uppercase kecil), rentang jam (biru bold, format `HH:mm - HH:mm`), ikon ✏️ (ganti shift → dialog pilih shift → PUT) dan 🗑️ (ConfirmDialog → DELETE). Maks 1 card per sel (aturan 1 shift/hari).
- **Sel kosong** = area klik (hover memunculkan `+`) → dialog **assign**: pilih shift (Select dari master shift aktif, label "nama — jam") untuk supir+tanggal sel itu → `POST` batch dengan satu supir. Assign per-sel saja (tanpa rentang tanggal/copy minggu — YAGNI).
- Baris supir = supir unik dari penugasan internal pending/aktif proyek terpilih.
- Setiap aksi sukses → refetch papan. Error per aturan (dobel/non-proyek) tampil via `parseApiError`/pesan gagal batch.
- Service `jadwalShift.service.ts`: `list(idProyek, dari, sampai)`, `create(payload)`, `update(id, {id_shift})`, `delete(id)`.

### Perubahan lain
- `penugasan/page.tsx`: import `PapanShift` menggantikan `PapanJadwal` (label toggle tetap "Papan Jadwal").
- Konstanta: `API_ENDPOINTS.SHIFT/_DETAIL`, `JADWAL_SHIFT/_DETAIL`, `ROUTES.SHIFT/_BARU/_DETAIL`, routes.config `listRoute('shift')`.
- `jadwalService.update` & param `limit` `penugasanService.list` yang ditambahkan kemarin DIPERTAHANKAN (berguna umum).

## Testing
Backend: CRUD shift; jadwal-shift: create batch sukses, dobel-tanggal global ditolak per-item (lintas proyek), supir non-proyek ditolak per-item, PUT ganti shift, DELETE lalu tanggal bisa diisi lagi, list join shift benar + scope perusahaan. Frontend: tsc + eslint.

## Di Luar Scope
- Approval/draft workflow (referensi punya "Simpan" draft — TIDAK diadopsi, simpan langsung).
- Grup pandu / grouping (eksplisit tidak diminta).
- Assign rentang tanggal sekaligus / copy minggu.
- Integrasi shift dengan jadwal keberangkatan/trip.
