# Pindahkan Export Master ke Halaman Master

**Tanggal:** 2026-08-11 · **Status:** Disetujui user

## Masalah
Tombol "Export Master: Karyawan (Excel/PDF), Armada (Excel/PDF)" nempel di halaman
Laporan (tab Laporan Trip) — tidak ada hubungannya dengan laporan trip, dan sulit
ditemukan dari halaman masternya sendiri.

## Solusi
1. Backend `LaporanOperasionalServiceProvider`: pisah grup route —
   `laporan/karyawan/export/{excel,pdf}` pakai `izin:karyawan`,
   `laporan/armada/export/{excel,pdf}` pakai `izin:armada`; sisanya tetap
   `izin:laporan`. URL & controller tidak berubah.
2. `LaporanTripTab.tsx`: hapus baris "Export Master" + 4 handler + entri
   `ExportKey` terkait.
3. Halaman `karyawan`: tombol Export Excel/PDF di header samping "Tambah
   Karyawan" (blob download, pola sama dengan laporan).
4. Halaman `armada`: tombol Export Excel/PDF di samping "Unduh Template".

## Testing
Suite backend penuh (route hanya ganti kunci izin; SUPERADMIN bypass); frontend
lint + tsc.

## Di Luar Cakupan
Perubahan isi/format file export.
