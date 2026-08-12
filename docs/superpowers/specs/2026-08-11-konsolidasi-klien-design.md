# Konsolidasi Klien — Rekap Laporan Perjalanan untuk Dicocokkan dengan Klien

**Tanggal:** 2026-08-11 · **Status:** Disetujui user

## Masalah
Tidak ada dokumen/layar rekap trip yang diserahkan ke KLIEN untuk pencocokan data
sebelum penagihan — padanan Konsolidasi Vendor arah sebaliknya. Klien menerima
faktur tanpa pernah menyepakati data ritnya lebih dulu.

## Solusi

### Backend — modul baru `KonsolidasiKlien` (izin:faktur, tanpa migration)

`GET /api/v1/konsolidasi-klien?id_klien=&dari=&sampai=`
- Sumber: trip **selesai ber-laporan** milik seluruh proyek klien tsb (periode
  dari `DATE(COALESCE(jk.waktu_berangkat, t.dibuat_pada))`).
- Per trip: tanggal, kode/nama proyek, rute, nopol, supir, sumber, jarak,
  **tarif** (resolusi `tarif_rute` — jenis kendaraan internal ?? vendor ??
  alokasi harian, pola PenagihanTrip), dan **`sudah_difakturkan`** (ada tautan
  `faktur_trip` ke faktur hidup non-batal).
- Ringkasan: `total_rit`, `total_jarak_km`, `estimasi_nilai` (jumlah tarif yang
  ter-resolusi), `tanpa_tarif` (jumlah trip tanpa tarif).

`GET /api/v1/konsolidasi-klien/export/excel?...` — gaya `DenganGayaLaporan`:
judul `KONSOLIDASI KLIEN {NAMA}`, subjudul periode; kolom No, Tanggal, Proyek,
Rute, Nopol, Supir, Jarak (km), Tarif (Rp), Status Tagihan; baris TOTAL.

### Frontend — halaman `/konsolidasi-klien` (menu Keuangan, sebelum Penagihan Trip)
Cermin halaman Konsolidasi Vendor: filter Select klien (persist `?klien=` +
localStorage) + periode default bulan berjalan; ringkasan kotak gaya Laporan
Trip (Total Rit, Total Jarak abu; Estimasi Nilai kotak biru + catatan "{n} trip
tanpa tarif") + tombol Export Excel di baris ringkasan; tabel band biru dengan
kolom Proyek dan Tag status tagihan (Sudah/Belum difakturkan).

## Alur bisnis
Laporan perjalanan → **Konsolidasi Klien** (sepakati data rit) → Penagihan Trip
(terbit faktur) → Rekonsiliasi. Simetris dengan Konsolidasi Vendor.

## Testing
`KonsolidasiKlienTest`: rekap lintas 2 proyek satu klien (rit/jarak/estimasi
benar, trip tanpa tarif terhitung), flag `sudah_difakturkan` berubah setelah
generate faktur via Penagihan Trip, klien perusahaan lain 404, export 200.
Suite penuh hijau; frontend lint + tsc.

## Di Luar Cakupan
Approval/tanda tangan klien di sistem; pengiriman email; PDF (Excel dulu).
