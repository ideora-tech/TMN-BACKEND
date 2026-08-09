# Link Pembelian di Riwayat Mutasi Stok Sparepart

**Tanggal:** 2026-08-09
**Status:** Disetujui user

## Masalah

Di halaman detail Spare Part (frontend), tabel "Riwayat Mutasi Stok" menampilkan mutasi
yang bersumber dari pembelian hanya sebagai teks `Pembelian PS-YYYYMM-NNNN` di kolom
Keterangan. User tidak bisa klik untuk melihat detail pembeliannya, padahal halaman
detail pembelian sudah ada (`/pembelian-sparepart/{id_pembelian}`).

Akar masalah: tabel `sparepart_mutasi` tidak menyimpan referensi `id_pembelian` —
saat realisasi pembelian (`PembelianSparepartRepository::tambahStokDanMutasi`), mutasi
hanya dicatat dengan teks keterangan dan `id_perawatan`.

## Solusi (disetujui: kolom id_pembelian + backfill)

### Backend

1. **Migration baru** — `sparepart_mutasi`:
   - Tambah kolom `id_pembelian CHAR(36) NULL` + index.
   - **Backfill data lama:** `UPDATE` join `sparepart_mutasi` → `sparepart`
     (ambil `id_perusahaan`) → `pembelian_sparepart` dengan syarat
     `keterangan = CONCAT('Pembelian ', nomor_pengajuan)` dan
     `pembelian_sparepart.id_perusahaan = sparepart.id_perusahaan`
     (nomor pengajuan hanya unik per perusahaan).
   - Backfill wajib kompatibel MySQL dan SQLite (PHPUnit pakai SQLite) — pakai
     driver-check seperti pola existing di `PembelianSparepartRepository::laporan()`
     (`CONCAT(...)` vs `||`).
   - `down()`: drop kolom.

2. **`PembelianSparepartRepository::tambahStokDanMutasi`** — tambah
   `'id_pembelian' => $header->id_pembelian` pada insert `sparepart_mutasi`.

3. **`SparepartRepository::MUTASI_COLUMNS`** dan **`SparepartMutasiResource`** —
   tambah `id_pembelian` agar kolom ikut di respons endpoint
   `GET /sparepart/{id}/mutasi`. Tidak ada perubahan Service/Controller/Interface.

### Frontend

4. **`src/services/sparepart.service.ts`** — interface `SparepartMutasi` tambah
   `id_pembelian: string | null`.

5. **`src/app/(protected-pages)/sparepart/[id]/page.tsx`** — sel kolom Keterangan:
   - Jika `m.id_pembelian` terisi → render keterangan sebagai `<Link>` Next.js ke
     `ROUTES.PEMBELIAN_SPAREPART_DETAIL(m.id_pembelian)`, style link biru standar
     (hover underline).
   - Jika null → teks biasa seperti sekarang.

## Perilaku Tepi

- Pembelian yang sudah di-soft-delete tetap tampil sebagai link; halaman detailnya
  menampilkan "tidak ditemukan" (query detail sudah filter `dihapus_pada`). Diterima,
  tanpa penanganan khusus.
- Mutasi non-pembelian (`Pemakaian servis`, penyesuaian manual) tidak berubah:
  `id_pembelian` null, tampil teks biasa.

## Testing

- PHPUnit: extend test realisasi pembelian — assert `id_pembelian` tersimpan pada baris
  `sparepart_mutasi` dan muncul di respons endpoint mutasi sparepart.
- Migrate & build dijalankan user sendiri (preferensi user; Claude tidak menjalankan
  build/migrate).

## Di Luar Cakupan

- Link ke detail perawatan dari `id_perawatan` (bisa menyusul jika dibutuhkan).
- Perubahan format teks keterangan.
