# Fase 2 — Konsolidasi Data ke Vendor

**Tanggal:** 2026-08-11
**Status:** Disetujui user (desain fase 0-2)
**Konteks:** Penutup rangkaian: laporan perjalanan → rekap pemakaian armada vendor
per periode → bahan pencocokan dengan invoice vendor & dokumen konsolidasi untuk
dikirim ke vendor.

## Masalah

Tagihan vendor (Invoice Vendor) diverifikasi tanpa pembanding sistematis — tidak
ada rekap berapa rit/jarak armada vendor benar-benar dipakai pada satu periode,
dan tidak ada dokumen konsolidasi yang bisa dikirim ke vendor untuk pencocokan.

## Solusi

### Backend — modul baru `KonsolidasiVendor`

**Endpoint 1 — rekap**
`GET /api/v1/konsolidasi-vendor?id_vendor=&dari=&sampai=` (middleware
`izin:invoice-vendor`)

Sumber: trip **selesai ber-laporan** (konsisten Penagihan Trip) yang penugasannya
`sumber=vendor` dengan kontrak milik vendor tsb. Respons:

```
{
  vendor: { id_vendor, nama_vendor },
  ringkasan: {
    total_rit, total_jarak_km,
    kontrak: [{ id_kontrak_vendor, mekanisme, rate, satuan, nilai_kontrak,
                jumlah_rit, nilai_seharusnya }]
  },
  trips: [{ id_trip, tanggal, nopol, supir_nama, rute, jarak_tempuh_km,
            mekanisme }]
}
```

`nilai_seharusnya` per kontrak: `satuan === 'per trip'` → `jumlah_rit × rate`;
selain itu (`per hari/per bulan/per ton/lumpsum/null`) → `null` (frontend
menampilkan `nilai_kontrak` sebagai pembanding manual + label satuannya).

**Endpoint 2 — export Excel**
`GET /api/v1/konsolidasi-vendor/export/excel?id_vendor=&dari=&sampai=` —
maatwebsite + trait `DenganGayaLaporan` (judul `KONSOLIDASI VENDOR {NAMA}`,
subjudul `Periode {dari} s/d {sampai}`), kolom: No, Tanggal, Nopol, Supir, Rute,
Jarak (km), Mekanisme; baris terakhir `TOTAL` (rit & jarak). Nama file
`konsolidasi-vendor-{dari}.xlsx`.

### Data — periode di invoice vendor

Migration: `invoice_vendor` + `periode_dari DATE NULL`, `periode_sampai DATE NULL`
(setelah `no_do`). Request store/update + resource ikut memuat field ini.

### Frontend

1. **Halaman baru `/konsolidasi-vendor`** (menu Keuangan, setelah Invoice
   Vendor, authority `['keuangan','manager','superadmin','admin']`): filter
   vendor (Select, persist `?vendor=` + localStorage) + periode (default bulan
   berjalan); kartu ringkasan (total rit, total jarak, nilai seharusnya per
   kontrak); tabel trip; tombol **Export Excel** (blob download, pola
   `alokasiArmada.service`).
2. **Invoice Vendor**: form baru/edit + field "Periode Dari/Sampai" (opsional);
   detail invoice — bila periode terisi, panel **Pencocokan Konsolidasi**:
   fetch rekap periode itu → tampil total rit, nilai seharusnya (per kontrak),
   vs `total` invoice + **selisih** disorot (hijau ≈ cocok, merah beda). Flow
   verifikasi existing tidak berubah — panel hanya penyaji data.

## Perilaku Tepi

- Vendor tanpa trip pada periode → ringkasan nol + trips kosong (bukan 404).
- Trip vendor tanpa laporan tidak dihitung (konsisten aturan "laporan = bukti").
- Kontrak dengan `rate` null / satuan bukan `per trip` → `nilai_seharusnya`
  null; panel pencocokan menampilkan "hitung manual — satuan {x}".
- Invoice tanpa periode → panel pencocokan tidak tampil (ajakan mengisi periode).

## Testing (PHPUnit, `KonsolidasiVendorTest` baru + extend `InvoiceVendorTest`)

- Rekap: hanya trip selesai ber-laporan vendor tsb; total rit/jarak benar;
  `nilai_seharusnya = rit × rate` untuk satuan `per trip`; satuan lain → null;
  vendor perusahaan lain → 404.
- Export Excel → 200.
- Invoice vendor: `periode_dari/sampai` tersimpan & muncul di respons.

## Di Luar Cakupan

- Auto-verifikasi/auto-tolak invoice berdasar selisih.
- Pengiriman email ke vendor.
- Pemetaan jenis kendaraan armada vendor (masih dari Fase 1).
