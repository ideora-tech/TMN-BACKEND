# Laporan Proyek Lengkap (List + Detail)

**Tanggal:** 2026-08-11 · **Status:** Disetujui user

## Masalah
Tab Laporan Proyek menampilkan UUID mentah, search di kolom UUID, dan `total_trip`
beku (dihitung sekali saat create, dari penugasan — bukan trip — sehingga 0).
Halaman detail sama mentahnya (ID Laporan/ID Proyek UUID + ringkasan).

## Solusi

### Backend (modul LaporanProyek)
1. **List** (`paginate`): leftJoin `klien`; kirim `kode_proyek`, `nama_proyek`,
   `nama_klien`; search pindah ke nama/kode proyek; tambah `total_trip_aktual`
   (subquery count trip `selesai` per proyek, soft-delete aware).
2. **Detail** (`show`): scoped ke perusahaan (404 lintas perusahaan); kirim
   tambahan `kode_proyek`, `nama_proyek`, `nama_klien`, `diserahkan_oleh`
   (username pengguna), dan `statistik`:
   `{ total_trip, total_jarak_km, total_biaya }` — real-time dari trip selesai +
   laporan perjalanan (biaya = bbm + tol + uang jalan + biaya lain), rumus sama
   dengan tab Laporan Trip.
3. **Create**: `total_trip` snapshot dihitung dari **trip selesai** proyek (bukan
   penugasan selesai).

### Frontend
1. **List**: kolom No, Proyek (kode — nama, link detail proyek), Klien,
   Total Trip (`total_trip_aktual`), Diserahkan, aksi lihat; placeholder search
   "Cari nama/kode proyek...".
2. **Detail** (`laporan/[id]`, desain konsisten halaman detail lain):
   - Header: back + judul `{kode} — {nama proyek}`, subtitle nama klien.
   - Card "Informasi Laporan": 3 kartu angka (Total Trip Selesai, Total Jarak,
     Total Biaya Ops — pola kartu dashboard), Diserahkan Oleh/Pada, Ringkasan
     (kotak abu), link "Lihat Proyek" (text-blue-500 hover:underline).
   - Card "Trip dalam Proyek": tabel band biru (Tanggal, Rute, Nopol, Supir,
     Sumber, Status), baris klik → detail trip; fetch endpoint trip existing
     `?id_proyek=` limit 100 + catatan bila total > 100.

## Testing
`LaporanProyekTest` baru: list memuat nama proyek/klien + `total_trip_aktual`
benar; detail memuat statistik + 404 lintas perusahaan. Suite penuh hijau;
frontend lint + tsc.

## Di Luar Cakupan
Perubahan export Excel/PDF laporan proyek; verifikasi/approval laporan.
