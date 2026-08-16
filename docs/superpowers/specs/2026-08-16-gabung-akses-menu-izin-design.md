# Gabung Akses Menu ke Peran & Akses — Design

Tanggal: 2026-08-16
Status: Disetujui user (opsi A — sidebar mengikuti izin "lihat")

## 1. Masalah

Ada dua sistem akses yang dikelola terpisah dan bisa saling bertentangan:

| Sistem | Tabel | Halaman | Fungsi |
|---|---|---|---|
| Visibilitas sidebar | `menu_peran` (id_menu, kode_peran; tanpa baris = tampil semua peran) | Pengaturan → Akses Menu | `GET menu/tree` memfilter sidebar via `MenuRepository::tree($kodePeran)` |
| Izin API per aksi | `izin_peran` (id_menu, kode_peran, aksi `lihat|tambah|ubah|hapus`, diizinkan, id_perusahaan nullable) | Pengaturan → Peran & Akses (`/peran/{id}`) | Middleware `CheckIzinPeran` (alias `izin:`) menolak request 403; SUPERADMIN bypass |

Akibatnya menu bisa tampil di sidebar tapi API-nya 403 (kasus NINA/Keuangan), dan admin harus mengurus dua halaman untuk satu maksud.

## 2. Keputusan

Sumber kebenaran tunggal = `izin_peran`. **Sidebar diturunkan dari izin aksi `lihat`**: centang Lihat di Peran & Akses → menu tampil di sidebar peran itu sekaligus API-nya boleh diakses; hilangkan centang → hilang dari sidebar dan API 403. Halaman Akses Menu dihapus.

## 3. Perubahan Backend

### 3.1 Filter tree berbasis izin

`MenuRepository::tree()` diubah: parameter menjadi `tree(?string $kodePeran, ?string $idPerusahaan)` (controller meneruskan `kode_peran` + `id_perusahaan` user login).

Aturan visibilitas per menu:
- `kodePeran === 'SUPERADMIN'` (atau `$kodePeran === null`, dipakai internal) → semua menu aktif tampil (perilaku bypass sama dengan middleware).
- Menu **berpath**: tampil bila ada baris `izin_peran` cocok (`id_menu`, `kode_peran`, `aksi='lihat'`, `dihapus_pada IS NULL`) dengan **`diizinkan = 1` pada baris pemenang** — baris `id_perusahaan = perusahaan user` menang atas baris `id_perusahaan IS NULL` (presedensi identik dengan `CheckIzinPeran`, termasuk revoke per-perusahaan).
- Menu **grup (path NULL)**: tampil bila punya minimal satu turunan yang tampil. Grup tanpa turunan tampil = disembunyikan.

Implementasi: ambil semua menu aktif + semua baris izin `lihat` milik peran itu dalam satu query, hitung visibilitas di PHP, lalu `buildTree` dari himpunan menu lolos filter + leluhurnya. `menu_peran` **tidak dibaca lagi** di jalur ini.

### 3.2 Hapus endpoint & jalur Akses Menu

- Hapus route `GET menu/akses-peran` dan `PUT menu/akses-peran/{kodePeran}` beserta `MenuController::aksesPeran`, `simpanAksesPeran`, `MenuService` method terkait, dan `MenuRepository::sinkronAksesPeran` + `aksesPeran` (query daftar kode_peran per menu).
- Relasi `perans()` di `MenuModel` dan model `MenuPeran` dihapus bila tidak ada pemakai tersisa (grep dulu).
- Tabel `menu_peran` **tidak di-drop** (jaga rollback); berhenti dibaca/ditulis. Catat sebagai kandidat drop di masa depan.

### 3.3 Migrasi data (satu kali, idempoten)

File `2026_08_16_110001_materialisasi_izin_lihat_dari_menu_peran.php`:

1. Ambil semua `kode_peran` dari tabel `peran` (aktif, belum dihapus) — kecuali `SUPERADMIN` (bypass, tidak butuh baris).
2. Untuk tiap menu aktif **berpath** × tiap peran: tentukan "tampil menurut aturan lama" — menu tanpa baris `menu_peran` = tampil untuk semua; menu dengan baris = tampil hanya untuk peran yang terdaftar (case-insensitive).
3. Bila tampil menurut aturan lama **dan belum ada baris** `izin_peran` (id_menu, kode_peran, aksi `lihat`, `id_perusahaan IS NULL`, aktif) → insert baris `lihat`, `diizinkan = 1`, `id_perusahaan = NULL`. Baris yang sudah ada (termasuk revoke `diizinkan = 0`) **tidak disentuh** — konfigurasi eksplisit admin menang.
4. Menu grup (path NULL) dilewati — visibilitasnya turunan.
5. `down()`: no-op (baris hasil materialisasi tidak bisa dibedakan dari isian manual; rollback tampilan cukup lewat revert kode karena `menu_peran` masih utuh).

Hasil: sidebar tiap peran hampir identik sebelum vs sesudah cutover, dengan **dua perubahan yang disengaja dan disetujui user (16 Agu)**: (1) menu yang selama ini tampil-tapi-403 ikut hilang dari sidebar (koreksi yang diinginkan); (2) menu yang selama ini punya **izin API tanpa menu** (baris `lihat` dari `IzinPeranSeeder` untuk dropdown/filter — mis. KEUANGAN atas `/klien`, `/project`, `/armada`) kini **ikut tampil di sidebar** peran tersebut — konsekuensi bawaan satu sumber kebenaran; sidebar bisa dirapikan per peran dengan sadar (cabut Lihat = menu hilang sekaligus API tertutup).

Catatan implementasi yang disepakati saat review: migrasi tidak memfilter `peran.aktif` (kolom bukan penentu; baris ekstra tidak berbahaya) dan `down()` me-restore baris menu `/akses-menu` (bukan no-op penuh). Fresh install: `IzinPeranSeeder` menambahkan baris `lihat` menu `/home` untuk semua peran non-SUPERADMIN (migrasi materialisasi berjalan sebelum tabel `peran` terisi, jadi Dashboard dijamin lewat seeder).

### 3.4 Soft-delete menu "Akses Menu"

Migration yang sama men-soft-delete baris menu ber-path `/akses-menu` (`dihapus_pada = now()`). Sumber baris menu itu adalah migration lama `2026_07_30_100001_seed_menu_akses_menu.php` (bukan `MenuSeeder`) — pada instalasi baru migration lama tetap berjalan lalu migration baru men-soft-delete-nya, urutan konsisten; `MenuSeeder` tidak perlu diubah karena tidak memuat entri akses-menu.

## 4. Perubahan Frontend

- Hapus halaman `src/app/(protected-pages)/akses-menu/page.tsx`, `src/services/aksesMenu.service.ts`, konstanta `MENU_AKSES_PERAN*`, entri ROUTES/routes.config/navigation untuk `/akses-menu`.
- Halaman Peran & Akses (`/peran/[id]`): tanpa perubahan struktural — tambah keterangan di bawah judul matrix: `Centang "Lihat" juga menentukan menu yang tampil di sidebar peran ini.` Kolom aksi tetap `lihat|tambah|ubah|hapus`.
- Sidebar (`GET menu/tree`) tidak berubah bentuk respons — hanya isi filternya yang berubah di backend.

## 5. Konsekuensi yang disepakati

- Tidak ada lagi konsep "terbuka untuk semua peran" implisit. Menu baru (dibuat via Pengaturan → Menu) hanya terlihat SUPERADMIN sampai diberi centang Lihat di Peran & Akses per peran.
- Seeder/migration masa depan yang menambah menu baru wajib sekalian menyisipkan baris `izin_peran` lihat untuk peran yang dituju (pola yang sudah dipakai migration seed menu Approval Keuangan tetap berlaku, ditambah baris izin).
- Mobile supir tidak terdampak (navigasi statis, tidak memakai `menu/tree`) — verifikasi sekali di plan.

## 6. Testing

Feature test baru `GabungAksesMenuIzinTest` (sqlite in-memory):
1. Peran dengan izin lihat pada 2 menu anak → `menu/tree` memuat 2 anak + grup induknya saja; menu tanpa izin tidak muncul.
2. Grup tanpa anak tampil → grup ikut hilang.
3. Baris revoke per-perusahaan (`diizinkan=0`, id_perusahaan user) menang atas baris global `diizinkan=1` → menu hilang.
4. SUPERADMIN → semua menu aktif tampil tanpa baris izin.
5. Migrasi materialisasi: seed kondisi `menu_peran` (menu terbuka semua, menu terbatas 1 peran, menu dengan baris izin lihat revoke yang sudah ada) → jalankan migration → baris izin sesuai aturan §3.3, baris revoke tidak tertimpa.
6. Endpoint `menu/akses-peran` (GET/PUT) → 404.
7. Regression: `CheckIzinPeran` tidak berubah perilaku (suite existing).

## 7. Di luar scope

- Drop tabel `menu_peran` (kandidat pembersihan terpisah).
- Kolom aksi baru (mis. approval) di matrix Peran & Akses.
- Override izin per-perusahaan dari UI (middleware sudah mendukung; UI pengelolaannya belum ada dan tidak ditambah sekarang).
- Guard `authority` statis di `routes.config` frontend (lapisan terpisah, tidak disentuh).
