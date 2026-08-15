# Approval Keuangan (BOD) — Design Spec

**Tanggal:** 2026-08-15
**Status:** Disetujui user (Opsi A), menunggu review spec tertulis
**Modul terdampak:** ArusKas (pengajuan pengeluaran), Pengaturan, Notifikasi; frontend Arus Kas + Pengaturan + detail trip (log uang jalan)

## 1. Masalah

Langkah persetujuan pengajuan pengeluaran saat ini di-hardcode via middleware `role:SUPERADMIN,MANAGER` pada endpoint `setujui`. Kebutuhan: persetujuan level BOD yang **dinamis** — siapa orangnya / jabatannya bisa disetting dan berubah-ubah, BOD **diberi tahu** saat ada pengajuan menunggu, dan **semua** approver harus menyetujui sebelum uang boleh ditransfer.

## 2. Keputusan Desain (sudah dikunci user)

| Keputusan | Pilihan |
|---|---|
| Posisi alur | **Mengganti** langkah "disetujui" lama (bukan menambah tingkat) |
| Basis approver | **Jabatan + orang spesifik**, bisa campur, dikonfigurasi di Pengaturan |
| Threshold | **Batas nominal bisa disetting**; di bawah batas → lolos cek langsung `disetujui` otomatis; nilai default 0 = semua wajib approval |
| Kuorum | **SEMUA approver harus menyetujui**; satu orang menolak → pengajuan `ditolak` |
| Notifikasi | In-app + push FCM ke semua approver saat pengajuan masuk tahap approval |

## 3. Alur Baru

```
diajukan → dicek (Keuangan)
   ├─ nominal <  batas  → disetujui (otomatis, tercatat "di bawah batas approval")
   └─ nominal >= batas  → menunggu_approval (snapshot daftar approver + notifikasi)
         ├─ SEMUA approver setuju → disetujui (disetujui_oleh = approver terakhir)
         └─ SATU approver menolak → ditolak (alasan wajib)
disetujui → ditransfer (Keuangan, tidak berubah)
```

- Status baru `menunggu_approval` ditambahkan ke konstanta service (kolom `status` sudah varchar(20), tanpa migration enum).
- Endpoint `PATCH arus-kas/pengajuan/{id}/setujui` (role Manager) **dihapus** beserta middleware-nya — digantikan endpoint approval.
- `tolak` lama (Keuangan, dari status diajukan/dicek) tetap ada.
- Hook sinkron modul lain (mis. `sinkronPembelianSetujui/Tolak` untuk pembelian sparepart) dipindah ke titik transisi baru: dipanggil saat pengajuan MENCAPAI `disetujui`/`ditolak`, dari jalur mana pun (otomatis di bawah batas, semua-approve, maupun tolak approver).

## 4. Perubahan Data (3 tabel baru)

### 4.1 `approver_keuangan` (konfigurasi)

| Kolom | Tipe | Ket |
|---|---|---|
| id_approver | char(36) PK | |
| id_perusahaan | char(36), index | |
| tipe | varchar(10) | `jabatan` \| `pengguna` |
| id_jabatan | char(36) nullable | terisi bila tipe jabatan |
| id_pengguna | char(36) nullable | terisi bila tipe pengguna |
| aktif | tinyint default 1 | |
| audit | MigrationHelper::auditColumns | |

Guard service: tolak duplikat (kombinasi tipe+id yang sama masih aktif).

### 4.2 `pengaturan` (key-value per perusahaan, generik)

| Kolom | Tipe |
|---|---|
| id_pengaturan | char(36) PK |
| id_perusahaan | char(36), index |
| kunci | varchar(50) |
| nilai | text nullable |
| audit | MigrationHelper::auditColumns |

Unique logis (id_perusahaan, kunci). Kunci pertama: `batas_approval_keuangan` (angka, default dianggap 0 bila belum ada). Tabel ini jadi rumah setting keuangan lain ke depan (mis. PPN/PPH).

### 4.3 `pengajuan_approval` (snapshot per pengajuan)

| Kolom | Tipe | Ket |
|---|---|---|
| id_approval | char(36) PK | |
| id_pengajuan | char(36), index | |
| id_pengguna | char(36) | approver hasil resolusi snapshot |
| status | varchar(10) | `menunggu` \| `disetujui` \| `ditolak` |
| catatan | varchar(255) nullable | wajib diisi saat menolak |
| waktu_aksi | datetime nullable | |
| audit | MigrationHelper::auditColumns | |

## 5. Resolusi & Snapshot Approver

Saat `cek()` dan nominal >= batas:
1. Ambil `approver_keuangan` aktif perusahaan itu.
2. Resolve ke daftar `id_pengguna` unik: tipe `pengguna` → langsung; tipe `jabatan` → semua pengguna aktif yang `pengguna.id_karyawan` → `karyawan.id_jabatan` = jabatan tsb.
3. Hasil kosong → **abort 422** "Approver keuangan belum dikonfigurasi — atur di Pengaturan → Approval Keuangan" (cek gagal, tidak ada pengajuan nyangkut diam-diam).
4. Insert baris `pengajuan_approval` status `menunggu` per pengguna; set status pengajuan `menunggu_approval`; kirim notifikasi (in-app + FCM) ke tiap approver.

Snapshot disengaja: perubahan konfigurasi setelahnya TIDAK mengubah pengajuan yang sedang menunggu (audit bersih, tidak ada pengajuan yang tiba-tiba butuh orang baru / kehilangan approver).

## 6. API

**Konfigurasi (role SUPERADMIN,ADMIN):**
- `GET arus-kas/approver` — daftar approver (dengan nama jabatan/pengguna).
- `POST arus-kas/approver` — body `{tipe, id_jabatan?|id_pengguna?}`.
- `DELETE arus-kas/approver/{id}` — soft delete.
- `GET/PUT arus-kas/pengaturan-approval` — baca/simpan `batas_approval_keuangan`.

**Aksi approval (tanpa role middleware; guard keanggotaan di service):**
- `PATCH arus-kas/pengajuan/{id}/approval` — body `{keputusan: 'setuju'|'tolak', catatan?}`; `catatan` wajib bila tolak.
  - Guard: status pengajuan `menunggu_approval` (atau `dicek` — jalur lazy snapshot, lihat §9) DAN pemanggil punya baris approval `menunggu`. Bukan approver → 403; sudah beraksi → 409.
  - `setuju`: baris → disetujui + waktu; bila semua baris disetujui → pengajuan `disetujui` (+`disetujui_oleh/pada`).
  - `tolak`: baris → ditolak; pengajuan → `ditolak` + `alasan_ditolak` = catatan; baris menunggu lain dibiarkan (jejak).

**Respons pengajuan (list & detail)** ditambah: `approval: [{id_pengguna, nama, status, catatan, waktu_aksi}]`, `approval_progress: {disetujui: n, total: m}`, `bisa_approve: bool` (untuk user yang sedang login).

## 7. Notifikasi

Saat baris approval dibuat: `NotifikasiService` in-app per approver (judul "Pengajuan {nomor} menunggu approval Anda", isi nominal+penerima+kategori, `referensi_tipe` pengajuan pengeluaran) + push FCM ke perangkat approver — ikuti pola `kirimKeSupir` yang sudah ada (tabel `token_perangkat`), dibuat varian untuk pengguna umum. Tidak ada notifikasi tambahan lain (hasil approve/tolak terlihat di daftar).

## 8. Frontend

**Pengaturan → Approval Keuangan** (halaman baru + seeding menu):
- Field "Batas Nominal Approval" (Rp; teks bantu: 0 = semua pengajuan wajib approval).
- Tabel approver: kolom Tipe, Nama (jabatan/pengguna), aksi hapus; tombol "Tambah Approver" → dialog pilih tipe lalu pilih jabatan/pengguna dari master.
- Pola UI mengikuti standar list modul (band biru, HiPlusCircle, dsb.).

**Arus Kas (halaman WIP user — hanya MENAMBAH, tidak merombak):**
- Badge status baru `menunggu_approval` ("Menunggu Approval", warna amber) + progress "N/M".
- Di detail/daftar pengajuan: blok daftar approver + statusnya masing-masing; tombol **Approve** / **Tolak** (dialog catatan wajib saat tolak) muncul hanya bila `bisa_approve`.

**Detail Trip (log uang jalan):** `infoPengajuanTrip` menambah entri riwayat per aksi approval (siapa approve/tolak, kapan) + label status `menunggu_approval`; peta label/warna di kartu "Status Uang Jalan (Keuangan)" ditambah.

## 9. Guard & Edge Case

- Ubah/hapus pengajuan tetap hanya saat `diajukan` (tidak berubah).
- `transfer` tetap hanya dari `disetujui` (tidak berubah).
- Approver menolak wajib `catatan` (422 bila kosong).
- User yang sama muncul dua kali dari resolusi (jabatan + ditunjuk langsung) → tetap satu baris approval (dedup).
- Konfigurasi approver dihapus saat ada pengajuan `menunggu_approval` → pengajuan berjalan TIDAK terpengaruh (snapshot).
- **Pengajuan lama berstatus `dicek`** (dibuat sebelum fitur rilis, belum punya baris approval) → jalur **lazy snapshot**: `PATCH approval` pada pengajuan `dicek` menjalankan logika yang sama dengan `cek()` terlebih dahulu — bila nominal < batas, pengajuan langsung `disetujui` otomatis (keputusan pemanggil tidak diperlukan, respons memberi tahu); bila >= batas, snapshot dibuat, lalu keputusan pemanggil diproses bila ia termasuk approver (bukan approver → 403, snapshot tetap tersimpan). Tanpa migration data.

## 10. Testing (backend, phpunit)

1. CRUD approver + tolak duplikat; simpan/baca batas nominal.
2. `cek()` nominal >= batas → status `menunggu_approval`, baris approval sesuai resolusi (jabatan → pengguna, dedup), notifikasi terbuat.
3. `cek()` nominal < batas → langsung `disetujui`; hook pembelian sinkron tetap terpanggil.
4. `cek()` tanpa approver terkonfigurasi (dan >= batas) → 422.
5. Approve oleh sebagian → status tetap `menunggu_approval` + progress benar; approve semua → `disetujui`.
6. Tolak oleh satu approver (dengan catatan) → pengajuan `ditolak`; tanpa catatan → 422.
7. User bukan approver → 403; approve dua kali → 409.
8. Pengajuan lama status `dicek` → PATCH approval membuat snapshot lazy lalu memproses.
9. Transfer hanya bisa setelah `disetujui` (regresi).

## 11. Di Luar Cakupan

- Kuorum minimum yang bisa disetting (semua-harus-approve saja).
- Delegasi approver / pengganti saat cuti.
- Approval berjenjang multi-level.
- Approval dari aplikasi mobile.
