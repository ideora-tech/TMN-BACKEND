# Re-approval Nominal Realisasi Pembelian Sparepart — Design

Tanggal: 2026-08-15
Status: Disetujui user (opsi "hanya saat naik", pendekatan A — pakai ulang mesin approval)

## 1. Masalah

Setelah pengajuan pembelian sparepart disetujui BOD, realisasi pembelian (input harga aktual) memanggil `sinkronNominalPengajuanPembelian` yang meng-update nominal pengajuan pada status `diajukan` **dan `disetujui`**. Akibatnya nominal bisa berubah setelah approval BOD dan keuangan tetap bisa transfer tanpa approval ulang.

Celah kedua: bila realisasi terjadi saat status `dicek` atau `menunggu_approval`, sinkron di-skip diam-diam — BOD meng-approve nominal estimasi yang sudah basi dan nominal aktual tidak pernah tercatat di pengajuan.

Scope hanya pembelian sparepart. `sinkronNominalPengajuanTrip` dan `sinkronNominalPengajuanPerawatan` hanya berjalan pada status `diajukan` — sudah aman, tidak disentuh.

## 2. Aturan (final)

Re-approval hanya dipicu saat nominal **naik**. Nominal turun selalu update langsung tanpa approval ulang. Nominal sama = no-op.

Perilaku `sinkronNominalPengajuanPembelian` per status pengajuan — seluruhnya di dalam `DB::transaction` dengan `findPengajuanForUpdate` (pola anti-race yang sama dengan `cek()`/`prosesApproval`):

| Status | Nominal naik | Nominal turun |
|---|---|---|
| `diajukan` | update nominal | update nominal |
| `dicek` (legacy) | update nominal | update nominal |
| `menunggu_approval` | update nominal + reset snapshot: void semua baris approval aktif, buat snapshot baru (semua approver `menunggu`), notif ulang | update nominal saja; approval yang sudah masuk tetap berlaku |
| `disetujui` | evaluasi ulang batas: nominal baru >= batas → void snapshot lama (bila ada), snapshot baru, status → `menunggu_approval`, notif approver; nominal baru < batas → update nominal, tetap `disetujui` | update nominal saja |
| `ditransfer`, `ditolak` | tidak disentuh (return) | tidak disentuh (return) |

Catatan:
- "Void" = soft delete (`dihapus_pada` + `dihapus_oleh` via `RecordHelper::stampDelete`) — jejak audit siapa meng-approve nominal lama tetap ada di DB.
- Batas nominal dibaca dari pengaturan `batas_approval_keuangan` milik perusahaan pengajuan (bukan perusahaan pemanggil) — konsisten dengan `cek()`.
- Bila evaluasi ulang butuh approval (`>= batas`) tetapi resolusi approver kosong → abort 422 `Approver keuangan belum diatur` (konsisten dengan `masukTahapApproval`). Transaksi realisasi ikut rollback; user harus mengatur approver dulu.
- Pengajuan pembelian yang **belum pernah** melewati approval (auto-disetujui karena dulu di bawah batas) diperlakukan sama: naik hingga >= batas → wajib approval.

## 3. Implementasi

Semua perubahan di modul `ArusKas` (backend saja):

- `ArusKasService::sinkronNominalPengajuanPembelian` ditulis ulang mengikuti tabel §2. Logika evaluasi-ulang memakai kembali blok yang sudah ada di `masukTahapApproval` (resolusi approver, `insertApprovalRows`, notifikasi) — diekstrak/dipakai ulang, bukan diduplikasi.
- Repository + interface: method baru `voidApprovalRows(string $idPengajuan): void` (soft delete semua baris `pengajuan_approval` aktif milik pengajuan itu). Method lain sudah tersedia (`findPengajuanForUpdate`, `insertApprovalRows`, `hitungApprovalMenunggu`, `resolusiApprover`, `getPengaturan`).
- Notifikasi approver saat revisi memakai `NotifikasiService::buatDanKirim` (in-app + FCM, `tipe` = `approval_keuangan`, `referensi_tipe` = `pengajuan_pengeluaran` → link `/arus-kas`), judul: `Pengajuan {nomor_pengajuan} perlu approval ulang`, isi: `Nominal berubah dari Rp {lama} menjadi Rp {baru}`. Format rupiah pakai `number_format($n, 0, ',', '.')`.
- Tidak ada migration, tidak ada endpoint baru, tidak ada perubahan frontend — status `menunggu_approval`, badge N/M, dan blok Approval BOD di dialog detail sudah menangani snapshot baru.

## 4. Testing

Tambahan kasus di `tests/Feature/ApprovalKeuanganAlurTest.php` (atau file baru bergaya sama), semua lewat endpoint realisasi pembelian sparepart:

1. Disetujui via approval BOD, realisasi naik melewati batas → status `menunggu_approval`, snapshot lama ter-soft-delete, snapshot baru semua `menunggu`, notifikasi terkirim ke tiap approver.
2. Auto-disetujui (di bawah batas), realisasi naik hingga >= batas → masuk `menunggu_approval`.
3. Disetujui, realisasi naik tapi masih < batas → tetap `disetujui`, nominal ter-update.
4. Disetujui, realisasi turun → tetap `disetujui`, nominal ter-update, tidak ada snapshot baru.
5. `menunggu_approval` dengan sebagian approver sudah setuju, realisasi naik → semua baris approval ter-reset `menunggu`.
6. `menunggu_approval`, realisasi turun → nominal ter-update, baris approval yang sudah `disetujui` tidak berubah.
7. Status `dicek` (legacy), realisasi → nominal ter-update (tidak lagi di-skip).
8. Status `ditransfer` → nominal tidak berubah.
9. Naik melewati batas tapi approver kosong → 422, realisasi rollback (pembelian tetap status sebelum realisasi, stok tidak bertambah).

## 5. Di luar scope

- Riwayat revisi nominal di UI (tabel/timeline revisi) — audit cukup lewat baris approval ter-soft-delete di DB.
- Re-approval untuk perawatan/trip — sinkron mereka hanya jalan di `diajukan`.
- Perubahan alur transfer — transfer tetap hanya dari `disetujui`, otomatis terblok saat pengajuan balik ke `menunggu_approval`.
