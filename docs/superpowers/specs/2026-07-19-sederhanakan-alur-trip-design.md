# Spec: Sederhanakan Alur Trip — Hapus Langkah "Jadwal" dari UI

Tanggal: 2026-07-19
Status: disetujui user ("setuju bro")

## Latar Belakang

Flow operasional nyata user: supir+armada di-assign di Penugasan (+ jadwal shift via papan shift) → supir berangkat mengantar logistik lewat rute → sampai tujuan → (nanti: foto + surat bukti sampai). Tidak ada langkah "merencanakan jadwal keberangkatan" terpisah.

Sistem saat ini memaksa langkah ekstra: Penugasan → buat **Jadwal Keberangkatan** manual (menu Jadwal / detail penugasan) → dari detail jadwal baru bisa Mulai Trip. Menu Jadwal membingungkan user ("ini buat apaan, ga jelas") dan aksi lifecycle trip (mulai/selesai/batalkan) nyangkut di halaman detail jadwal, bukan di halaman trip.

Fakta pendukung (hasil eksplorasi):

- Aplikasi mobile **tidak memakai** endpoint jadwal sama sekali (nol referensi di `TMN-TRANSPORT-MOBILE/lib/`).
- Aksi Selesaikan/Batalkan trip **hanya ada** di `jadwal/[id]/page.tsx`; `trip/[id]/page.tsx` murni tampilan.
- `trip.id_jadwal` wajib di skema — jadwal_keberangkatan tetap dibutuhkan sebagai record internal.
- Penugasan index backend mewajibkan salah satu filter `id_proyek` / `id_armada` / `id_supir`.

## Tujuan

1. Trip bisa dimulai langsung dari Trip Monitor dan dari detail Penugasan — tanpa membuat jadwal manual.
2. Aksi lifecycle trip pindah ke halaman detail Trip.
3. Menu + halaman Jadwal dihapus dari UI web.
4. Skema DB dan modul backend JadwalKeberangkatan tetap hidup (record dibuat otomatis; deteksi dobel-trip tetap jalan).

## Non-Goals (proyek berikutnya / tidak disentuh)

- Foto + surat bukti sampai saat menyelesaikan trip (proyek lanjutan yang sudah disepakati).
- Papan shift, alur laporan perjalanan/operasional/keuangan setelah trip.
- Endpoint HTTP jadwal existing (index/show/store/update/destroy, by-supir) — dibiarkan untuk kompatibilitas, tidak dihapus.
- Mobile.

## Desain

### A. Endpoint baru: Mulai Trip

`POST /api/v1/trip/mulai`

Request (StoreMulaiTripRequest):

```
id_penugasan : required, string, exists:penugasan,id_penugasan,dihapus_pada,NULL
id_rute      : sometimes, nullable, string, exists:rute,id_rute,dihapus_pada,NULL
```

Perilaku `TripService::mulaiDariPenugasan(array $data, string $idPerusahaan)`:

1. Validasi penugasan milik perusahaan user (join proyek → `pr.id_perusahaan`), 404 bila bukan.
2. Guard dobel-trip: bila supir/armada (internal maupun vendor, sesuai FK yang terisi di penugasan) masih punya trip dengan status selain `selesai`/`dibatalkan` → abort 422 "Supir/armada masih memiliki trip aktif".
3. Buat record `jadwal_keberangkatan` otomatis: `id_penugasan`, `waktu_berangkat = now()`, `id_rute` + snapshot teks `rute` (pola snapshot yang sudah ada di JadwalKeberangkatanService), `estimasi_tiba = null`.
4. Buat trip (`id_jadwal` dari langkah 3) lalu langsung `checkin` — memakai method service existing supaya riwayat StatusTrip konsisten.
5. Return `TripResource` trip yang sudah berjalan.

Response sukses memakai shape API standar (`success/message/data`).

### B. Filter baru di list Trip

`GET /api/v1/trip` menerima parameter opsional tambahan:

- `id_penugasan` — join `jadwal_keberangkatan` (sudah ada di query) → `jk.id_penugasan = ?`
- `id_supir` — join `penugasan` → `p.id_supir = ?`

`TripRepository::paginate` dan `TripService::list` diperluas dengan dua parameter opsional ini. Perilaku tanpa parameter tidak berubah.

### C. Frontend: Trip Monitor

`trip/page.tsx`:

- Tombol **"Mulai Trip"** (solid, kanan atas header page-level).
- Dialog Mulai Trip: dua-tahap Select — **Proyek** (proyekService list) → **Penugasan** proyek tsb (penugasanService.list, status pending/aktif, label "Nama Supir — nopol (sumber)") → **Rute** (ruteService list, opsional). Submit → `POST /trip/mulai` → toast sukses → refresh tabel.
- Error backend (mis. 422 trip aktif) ditampilkan via `parseApiError`.

### D. Frontend: aksi lifecycle di detail Trip

`trip/[id]/page.tsx` mendapat card "Aksi Trip" (porting dari `jadwal/[id]`):

- Status `belum_mulai`/`berjalan` → tombol sesuai: **Mulai** (checkin, untuk trip lama yang belum jalan), **Selesaikan** (checkout), **Batalkan** (merah).
- ConfirmDialog per aksi, refresh data setelah sukses. Logika identik dengan yang sekarang ada di detail jadwal.

### E. Frontend: detail Penugasan & detail Supir

- `penugasan/[id]/page.tsx`: section "Jadwal" (form buat jadwal + daftar jadwal) **diganti** section "Trip": tombol **Mulai Trip** (dialog sama dengan C tapi penugasan terkunci, hanya pilih rute) + daftar trip penugasan itu (`tripService.list({ id_penugasan })`; kolom waktu berangkat, rute, status, link detail trip).
- `supir/[id]/page.tsx`: daftar jadwal supir **diganti** daftar trip (`tripService.list({ id_supir })`), link ke detail trip.

### F. Hapus UI Jadwal

- Migration baru: hapus row `menu` dengan `path = '/jadwal'` beserta row `menu_peran`-nya (id menu di-lookup by path, bukan hard-code UUID). `down()` boleh no-op dengan komentar singkat di migration.
- `database/seeders/MenuSeeder.php`: hapus baris menu Jadwal.
- Hapus file: `jadwal/page.tsx`, `jadwal/[id]/page.tsx` (aksi sudah pindah ke D).
- Bersihkan: entri Jadwal di `navigation.ts`, `ROUTES.JADWAL`/`JADWAL_DETAIL`, `API_ENDPOINTS.JADWAL*`, entri `/jadwal` di `routes.config.ts`.
- `jadwal.service.ts`: dihapus bila tidak ada pemakai tersisa setelah E (dicek saat implementasi; sisa pemakai = bug plan).

## Testing

Backend (`vendor/bin/phpunit`, JANGAN `php artisan test`):

- `TripMulaiTest`: sukses (jadwal auto-terbuat, trip status berjalan + waktu_checkin terisi, riwayat status tercatat); 422 saat supir masih punya trip aktif; sukses lagi setelah trip pertama selesai; 404 penugasan milik perusahaan lain; filter `id_penugasan` & `id_supir` pada list trip.
- Suite penuh tetap hijau.

Frontend: `npx tsc --noEmit` + eslint file yang disentuh; tidak ada referensi tersisa ke ROUTES/API jadwal yang dihapus (grep bersih).

## Batasan & Konvensi

- Semua konvensi repo berlaku: scope `id_perusahaan`, soft-delete aware, response shape standar, `parseApiError`, konstanta ROUTES/API_ENDPOINTS, teks UI bahasa Indonesia, status color scheme (terjadwal/belum_mulai=biru, berjalan=emerald, selesai=ungu, dibatalkan=merah).
- Modul Trip existing memakai Eloquent Model — ikuti pola modul tersebut (bukan pola QB murni modul baru).
- Tidak ada commit oleh agent; staging via `git add` path spesifik; user yang build & deploy.
