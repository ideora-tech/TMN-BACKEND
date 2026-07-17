# Design: Dashboard Maintenance Armada & Dokumen Armada

**Tanggal:** 2026-07-16
**Status:** Approved, menunggu implementasi

## Latar Belakang

User meminta "1 menu khusus untuk maintenance armada" dan "menu untuk mengelola dokumen armada". Riset awal menemukan bahwa backend untuk kedua fitur ini **sudah lengkap** (`app/Modules/PerawatanArmada/`, `app/Modules/DokumenArmada/` — Controller/Service/Repository/Model/Requests/Resources semua aktif dan ter-registrasi), dan frontend sudah punya service client (`perawatanArmada.service.ts`, `dokumenArmada.service.ts`) yang **sudah dipakai penuh** (CRUD lengkap) di halaman detail armada (`/armada/[id]`, section "Riwayat Perawatan" dan "Dokumen Kendaraan").

Yang belum ada: menu sidebar dedicated yang menampilkan data ini **lintas-armada** (semua kendaraan sekaligus), sehingga tim ops/manager tidak perlu buka satu-satu halaman armada untuk memantau jadwal servis atau dokumen yang mau kedaluwarsa. Backend `DokumenArmada` bahkan sudah punya endpoint `dokumen-armada/expiring` yang belum pernah dipakai frontend — mengindikasikan kebutuhan ini sudah diantisipasi tapi tidak pernah diselesaikan.

## Keputusan Desain (dikonfirmasi user)

1. **Bentuk fitur:** dashboard lintas-armada (bukan sekadar shortcut/redirect ke halaman detail armada).
2. **Mode CRUD:** full create/update/delete langsung dari dashboard baru (bukan read-only + link-out).
3. **Reminder:** field `jadwal_servis_berikutnya` (perawatan) mendapat badge "jatuh tempo segera" mirip pola dokumen yang mau expired.
4. **Sekalian konversi ke Query Builder:** kedua modul (`PerawatanArmada`, `DokumenArmada`) masih Eloquent (belum ikut migrasi Kelompok 1/2) — dikonversi penuh ke Query Builder + `RecordHelper` sebagai bagian dari pekerjaan ini, bukan ditunda ke batch terpisah.

## Prinsip Kunci: Jangan Duplikasi CRUD

Form tambah/edit/hapus yang sudah ada di `/armada/[id]` (nested di bawah satu armada) **tidak diduplikasi**. Dashboard baru menambahkan **hanya satu endpoint baru per modul** — list agregat lintas-armada (`GET`) — dan create/update/delete di dashboard baru me-reuse endpoint nested yang sudah ada (`POST/PUT/DELETE armada/{idArmada}/perawatan|dokumen`), dengan tambahan dropdown pilih armada di form.

## Backend

### Konversi Eloquent → Query Builder

Mengikuti pola persis Kelompok 1/2 (lihat `docs/superpowers/specs/2026-07-15-eloquent-to-query-builder-design.md`):
- Repository pakai `DB::table()`, kembalikan `?object`/`object` (bukan `?FooModel`/`FooModel`).
- Create/update/delete pakai `RecordHelper::stampCreate/stampUpdate/stampDelete`.
- Model Eloquent (`PerawatanArmadaModel.php`, `DokumenArmadaModel.php`) dihapus setelah semua referensi (Controller, Service, Resource, Contract interface) diupdate ke `object`.
- Tidak ada `SELECT *` — pakai `private const COLUMNS` eksplisit.
- Query wajib scope `id_perusahaan` (via join ke `armada`) dan `whereNull('dihapus_pada')`.
- `DokumenArmadaService`'s file-upload logic (Laravel `Storage` facade, disk `public`) **tidak berubah** — itu murni filesystem, tidak terkait ORM.

### Endpoint baru

**`GET /api/v1/perawatan-armada`** (top-level, baru)
- Query params: `page`, `limit`, `id_armada` (opsional), `status` (opsional: terjadwal/dalam_proses/selesai).
- Scope: join ke `armada`, filter `armada.id_perusahaan = auth()->user()->id_perusahaan`, `whereNull` di kedua tabel.
- Attach `armada_nopol` (dan `armada_merk` untuk konteks) via join langsung (bukan attach-helper terpisah — ini 1:1 join sederhana, tidak perlu batching seperti kasus Trip yang multi-hop).
- Response fields: semua kolom `perawatan_armada` + `armada_nopol`, `armada_merk`.
- Middleware: `izin:armada` (sama seperti endpoint nested yang sudah ada — tidak perlu izin key baru).

**`GET /api/v1/dokumen-armada`** (top-level, baru — melengkapi `expiring` yang sudah ada)
- Query params: `page`, `limit`, `id_armada` (opsional), `jenis_dokumen` (opsional).
- Scope & attach: sama pola dengan di atas (`armada_nopol`).
- Endpoint `dokumen-armada/expiring` yang sudah ada **tidak diubah**, tetap dipertahankan.
- Middleware: `izin:armada`.

Create/update/delete untuk kedua modul **tidak ada endpoint baru** — dashboard memanggil endpoint nested yang sudah ada persis seperti yang dipakai `/armada/[id]` hari ini.

### Menu & Permission

Migration baru menambah 2 baris ke tabel `menu`. **Catatan konsistensi istilah:** seluruh app pakai istilah Indonesia "Perawatan" (tabel `perawatan_armada`, section "Riwayat Perawatan", status armada "Perawatan") — bukan "Maintenance". Label menu & path memakai "Perawatan Armada" agar konsisten, meskipun user menyebutnya "maintenance" secara verbal:
- "Perawatan Armada" — `path: /perawatan-armada`, `id_menu_induk` = sama dengan menu "Armada" (flat sibling di bawah Operasional), `urutan` tepat setelah Armada.
- "Dokumen Armada" — `path: /dokumen-armada`, sama pola.

Seed `izin_peran` untuk kedua menu baru = salinan persis dari role-set yang sudah punya izin ke menu "Armada" (query existing `izin_peran` WHERE menu = Armada, insert dengan `id_menu` baru, role sama). Tidak perlu `izin:` middleware key baru karena API-nya reuse `izin:armada`.

## Frontend

### `/perawatan-armada` (halaman baru)
- Filter bar: search, dropdown Armada, dropdown Status — pola sama seperti halaman Trip/Rute yang sudah ada.
- Tabel kolom: Armada (nopol) · Tanggal · Jenis Perawatan · Biaya · KM Odometer · Jadwal Servis Berikutnya (badge kuning jika ≤30 hari, merah jika sudah lewat) · Status · Aksi.
- Tombol "Catat Perawatan" buka form: dropdown Armada (baru, wajib dipilih dulu) + field yang sama persis dengan form di `/armada/[id]` (tanggal, jenis_perawatan, biaya, km_odometer, status, jadwal_servis_berikutnya, keterangan).
- Edit/Hapus inline, reuse `perawatanArmadaService.update/delete(idArmada, id, ...)`.

### `/dokumen-armada` (halaman baru)
- Filter bar: search, dropdown Armada, dropdown Jenis Dokumen.
- Tabel kolom: Armada (nopol) · Jenis Dokumen · Nomor · Berlaku Sampai (badge kuning ≤30 hari, merah jika expired) · File (link buka tab baru) · Aksi.
- Tombol "Tambah Dokumen" buka form: dropdown Armada (baru) + field existing (jenis_dokumen, nomor, berlaku_sampai, upload file).
- Edit/Hapus inline, reuse `dokumenArmadaService.update/delete(idArmada, id, ...)`.

### Perubahan lain
- `perawatanArmada.service.ts` — tambah `listAll(params)` memanggil `GET /perawatan-armada`.
- `dokumenArmada.service.ts` — tambah `listAll(params)` memanggil `GET /dokumen-armada`.
- `src/constants/api.constant.ts` — tambah `PERAWATAN_ARMADA`, `DOKUMEN_ARMADA` (list top-level).
- `src/constants/route.constant.ts` + `routes.config.ts` — tambah 2 route baru.
- Sidebar **tidak perlu edit config statis** — nav DB-driven via `src/server/actions/navigation/getNavigation.ts`, otomatis muncul begitu menu di-seed.

## Testing

- Backend: test konversi Query Builder (mirror pola `tests/Feature/JenisKendaraanTest.php` dkk — CRUD dasar + regresi terhadap perilaku lama), test endpoint list baru (filter `id_armada`/`status`/`jenis_dokumen`, scoping `id_perusahaan`, badge-relevant date fields tidak berubah bentuk).
- Frontend: type-check + lint (pola yang sudah dipakai konsisten di sesi ini — tidak ada test runner frontend otomatis yang established).

## Di Luar Scope

- Tidak mengubah/menghapus form CRUD yang sudah ada di `/armada/[id]`.
- Tidak membuat notifikasi/email otomatis untuk dokumen kedaluwarsa (badge visual saja).
- Tidak mengubah endpoint `dokumen-armada/expiring` yang sudah ada.
