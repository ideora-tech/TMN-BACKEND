# Paket Sparepart per Jenis Perawatan & Kategori Sparepart — Design

**Repo:** TMN-TRANSPORT-BACKEND (menyentuh backend + TMN-TRANSPORT-FRONTEND)

## Latar Belakang

Modul Sparepart (`sparepart`, `sparepart_mutasi`, `perawatan_sparepart`) sudah berjalan: sparepart dipakai di form Catat Perawatan Armada, stok otomatis terpotong ("keluar") saat dipakai dan otomatis balik ("masuk") saat servis dibatalkan — mekanisme kartu stok standar bengkel sudah terpenuhi.

Dua gap yang disepakati untuk dikerjakan sekarang:

1. **Paket/BOM (Bill of Materials) per Jenis Perawatan** — daftar sparepart standar (dan qty-nya) untuk suatu Jenis Perawatan × Jenis Kendaraan, supaya form Catat Perawatan bisa auto-fill daftar part alih-alih mekanik mengetik manual tiap kali.
2. **Kategori Sparepart** — pengelompokan sparepart (Oli & Pelumas, Filter, dst) supaya lebih mudah dicari & dikelola.

**Di luar cakupan** (akan jadi spec terpisah setelah ini): mekanisme **Pembelian Sparepart** (master vendor/supplier, Purchase Order, penerimaan barang, histori harga beli per vendor).

## 1. Paket/BOM Sparepart per Jenis Perawatan

### Data Model

Tabel baru `paket_perawatan_sparepart`, mengikuti pola `interval_perawatan` tapi 1:many (satu kombinasi Jenis Perawatan × Jenis Kendaraan bisa punya banyak baris sparepart):

```
id_paket_perawatan_sparepart  char(36) primary
id_perusahaan                 char(36)
id_jenis_perawatan             char(36)
id_jenis_kendaraan             char(36)
id_sparepart                   char(36)
qty_standar                    unsignedInteger
aktif                          tinyInteger default 1
+ audit columns (dibuat_pada, dibuat_oleh, diubah_pada, diubah_oleh, dihapus_pada, dihapus_oleh)
index (id_perusahaan, id_jenis_perawatan, id_jenis_kendaraan)  -- nama index: paket_perawatan_sparepart_lookup_idx
```

Keunikan kombinasi (`id_perusahaan` + `id_jenis_perawatan` + `id_jenis_kendaraan` + `id_sparepart`) divalidasi di level Service (bukan constraint DB) — sama seperti pola `sparepart.kode` dan `interval_perawatan.findByKombinasi`.

### Backend — Modul `app/Modules/PaketPerawatanSparepart/`

Struktur identik `IntervalPerawatan` (Controller, Service, Repository, Contracts, Requests, Resources):

- `GET /paket-perawatan-sparepart` — list berpaging, filter opsional `id_jenis_perawatan` & `id_jenis_kendaraan`. Join ke `jenis_perawatan`, `jenis_kendaraan`, `sparepart` untuk tampilan (nama, bukan cuma ID).
- `GET /paket-perawatan-sparepart/{id}` — detail satu baris.
- `POST /paket-perawatan-sparepart` — tambah baris (validasi: `id_jenis_perawatan`, `id_jenis_kendaraan`, `id_sparepart` milik perusahaan yang sama; kombinasi belum ada → 422 kalau duplikat).
- `PUT /paket-perawatan-sparepart/{id}` — update `qty_standar` / `aktif`.
- `DELETE /paket-perawatan-sparepart/{id}` — soft delete.
- `GET /paket-perawatan-sparepart/resolusi?id_jenis_perawatan=&id_jenis_kendaraan=` — **beda dari resolusi Interval Perawatan** (yang balikin satu angka `interval_hari`): endpoint ini balikin **array** baris aktif `{id_sparepart, nama, satuan, qty_standar, harga_standar}` (join ke `sparepart` untuk nama/satuan/harga). Kombinasi tanpa paket terdaftar → balikin array kosong (`[]`), bukan error.

Route terdaftar di grup API yang sama dengan `IntervalPerawatan` (Sanctum + `CheckIzinPeran` middleware), scope ke `id_perusahaan` milik user seperti modul lain.

### Frontend

**Halaman admin baru** `/paket-perawatan-sparepart` (menu baru "Paket Sparepart" di bawah Data Master, migrasi seed menu mengikuti pola persis `2026_07_19_100002_seed_menu_interval_perawatan.php`):
- List: tabel Jenis Perawatan | Jenis Kendaraan | Sparepart | Qty Standar | Aktif, dengan filter Jenis Perawatan & Jenis Kendaraan.
- Form tambah/edit: pilih Jenis Perawatan, Jenis Kendaraan, Sparepart (dropdown), input Qty Standar.

**Auto-fill di [PerawatanForm.tsx](../../../../TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/perawatan-armada/PerawatanForm.tsx):**

Tambahkan `useEffect` baru, mengikuti pola persis auto-fill "Jadwal Servis Berikutnya" yang sudah ada (baris 96-114), dengan syarat tambahan `items.length === 0` (supaya tidak menimpa part yang sudah diketik manual):

```ts
// Auto-fill daftar sparepart dari paket standar — hanya saat CREATE dan list masih kosong,
// supaya tidak menimpa part yang sudah ditambah manual.
useEffect(() => {
    if (isEdit || items.length > 0) return
    const armada = armadaList.find(a => a.id_armada === form.id_armada)
    if (!armada?.id_jenis_kendaraan || !form.id_jenis_perawatan) return

    let aktif = true
    paketPerawatanSparepartService.resolusi({
        id_jenis_perawatan: form.id_jenis_perawatan,
        id_jenis_kendaraan: armada.id_jenis_kendaraan,
    })
        .then(res => {
            if (aktif && res.length > 0) {
                setItems(res.map(r => ({
                    id_sparepart: r.id_sparepart,
                    qty: String(r.qty_standar),
                    harga: String(r.harga_standar),
                })))
            }
        })
        .catch(() => {})
    return () => { aktif = false }
}, [isEdit, form.id_armada, form.id_jenis_perawatan, armadaList, items.length])
```

Tetap fully editable sesudah auto-fill (tambah/hapus/ubah qty & harga seperti biasa).

## 2. Kategori Sparepart

### Data Model

Tabel baru `kategori_sparepart`:

```
id_kategori_sparepart  char(36) primary
id_perusahaan          char(36)
nama                   string(100)
aktif                  tinyInteger default 1
+ audit columns
```

Kolom baru di `sparepart`: `id_kategori_sparepart` char(36) **nullable** (supaya data sparepart lama yang belum dikategorikan tidak error).

### Backend — Modul `app/Modules/KategoriSparepart/`

CRUD sederhana, niru struktur `JenisPerawatan` (tanpa endpoint resolusi — cukup index/show/store/update/destroy). Menu baru "Kategori Sparepart" di bawah Data Master.

`SparepartRequest` (Store & Update) ditambah field opsional `id_kategori_sparepart` (nullable, validasi `exists` ke tabel `kategori_sparepart` milik perusahaan yang sama kalau diisi).

### Frontend

- Form Tambah/Edit Sparepart: tambah dropdown "Kategori" (opsional).
- Halaman list Sparepart: tambah kolom Kategori + filter dropdown Kategori.
- Halaman admin baru `/kategori-sparepart` (list + tambah/edit sederhana, mirip `/jenis-perawatan`).

## 3. Seed Data

### Sparepart master baru (real, bukan placeholder)

| Nama | Satuan | Kategori | Harga Standar |
|---|---|---|---|
| Oli Mesin Diesel 15W-40 | liter | Oli & Pelumas | Rp 60.000 |
| Oli Gardan (Gear Oil 85W-140) | liter | Oli & Pelumas | Rp 70.000 |
| Oli Transmisi | liter | Oli & Pelumas | Rp 65.000 |
| Filter Oli | pcs | Filter | Rp 85.000 |
| Filter Udara | pcs | Filter | Rp 150.000 |
| Filter Solar | pcs | Filter | Rp 120.000 |

Truk diesel (Isuzu Elf, Mitsubishi Fuso, dll) memakai oli mesin grade **15W-40** (diesel engine oil), bukan 5W-30 (grade itu untuk mobil bensin/penumpang) — sudah dikoreksi. Harga standar di atas estimasi harga pasar bulk/fleet untuk part commercial truck di Indonesia, dipakai langsung sebagai nilai seed (bukan keputusan yang ditunda ke implementasi).

### Paket/BOM — hanya 5 dari 10 Jenis Perawatan yang punya part tetap tiap servis

Jenis Perawatan **dengan** paket default — 6 pasangan (Jenis Perawatan, Sparepart) karena "Ganti Oli Mesin & Filter Oli" butuh 2 sparepart sekaligus — masing-masing × 6 Jenis Kendaraan = 36 baris `paket_perawatan_sparepart`:

| Jenis Perawatan | Sparepart | Qty per Kendaraan (Pickup / CDD / Engkel / Fuso / Tronton / Wingbox) |
|---|---|---|
| Ganti Oli Mesin & Filter Oli | Oli Mesin Diesel 15W-40 | 4 / 6 / 6 / 12 / 20 / 15 (liter) |
| Ganti Oli Mesin & Filter Oli | Filter Oli | 1 / 1 / 1 / 1 / 1 / 1 (pcs) |
| Ganti Oli Gardan | Oli Gardan | 1 / 2 / 2 / 4 / 6 / 5 (liter) |
| Ganti Oli Transmisi | Oli Transmisi | 2 / 3 / 3 / 6 / 8 / 7 (liter) |
| Ganti Filter Udara | Filter Udara | 1 (semua kelas) |
| Ganti Filter Solar (Bahan Bakar) | Filter Solar | 1 (semua kelas) |

Jenis Perawatan **tanpa** paket default (part kondisional, tetap diisi manual seperti sekarang): Rotasi & Cek Tekanan Ban, Servis Rem, Cek & Ganti Aki, Servis Berkala Besar, Cek Sistem Pendingin.

### Mekanisme seed

Seeder baru `PerawatanSparepartMasterDataSeeder` (Laravel Seeder, dijalankan manual via `php artisan db:seed --class=...`, mengikuti pola persis `PerawatanMasterDataSeeder` yang sudah ada) — hardcoded ke `id_perusahaan` tenant dev (`b8f3c1a2-0000-4000-8000-000000000001`) dan 6 `id_jenis_kendaraan` yang sudah ada, upsert idempoten by ID.

## Error Handling & Edge Cases

- Kombinasi Jenis Perawatan × Jenis Kendaraan tanpa paket terdaftar → `resolusi` balikin `[]`, form tetap kosong (bukan error) — perilaku sudah ada sekarang, tidak berubah.
- Sparepart yang direferensikan di `paket_perawatan_sparepart` dihapus (soft delete) → baris paket ikut jadi tidak valid; endpoint `resolusi` join dengan `whereNull('sparepart.dihapus_pada')` supaya paket yang part-nya sudah dihapus otomatis tidak muncul (tidak perlu cascade delete manual).
- Sparepart lama tanpa kategori (`id_kategori_sparepart = NULL`) tetap tampil normal di list, kolom Kategori kosong/"-".
- Auto-fill di form HANYA jalan saat `items.length === 0` — kalau mekanik sudah mulai tambah part manual sebelum jenis perawatan+armada lengkap dipilih, auto-fill tidak menimpa.
- Validasi `POST`/`PUT` paket: `id_jenis_perawatan`, `id_jenis_kendaraan`, `id_sparepart` harus milik `id_perusahaan` yang sama dengan user (401/404 kalau lintas tenant) — pola sama seperti `IntervalPerawatanService::validasiReferensi`.

## Testing

- Backend: PHPUnit untuk `PaketPerawatanSparepartService` (create/update/delete, validasi kombinasi duplikat, validasi referensi lintas tenant) dan `resolusi()` (kombinasi ada paket vs tidak ada vs sparepart-nya sudah dihapus). Untuk `KategoriSparepartService`: CRUD dasar + validasi `id_kategori_sparepart` opsional di `StoreSparepartRequest`/`UpdateSparepartRequest`.
- Frontend: manual test di browser — pilih Armada + Jenis Perawatan yang punya paket di form Catat Perawatan, pastikan daftar part ke-auto-fill dengan qty & harga benar, pastikan tetap bisa diedit; pilih kombinasi tanpa paket, pastikan form tetap kosong seperti sekarang.
