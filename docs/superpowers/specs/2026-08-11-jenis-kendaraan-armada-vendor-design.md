# Jenis Kendaraan Armada Vendor — Trip Vendor Bisa Ditagihkan

**Tanggal:** 2026-08-11
**Status:** Disetujui user
**Konteks:** PR tersisa Fase 1 Penagihan Trip — trip armada vendor selalu "Tarif
belum diatur" karena `armada_vendor` tidak punya relasi jenis kendaraan, padahal
resolusi `tarif_rute` butuh `id_jenis_kendaraan`.

## Solusi

1. **Migration** — `armada_vendor` + `id_jenis_kendaraan CHAR(36) NULL` (index,
   setelah `jenis`). Kolom teks `jenis` lama tetap (deskripsi bebas).
2. **Backend ArmadaVendor** — rules store/update `id_jenis_kendaraan`
   (`sometimes|nullable|string|max:36`); service memvalidasi jenis kendaraan
   milik perusahaan yang sama dengan vendor (404/422 bila bukan); fillable model;
   resource + `nama_jenis_kendaraan` (join master) di list & detail.
3. **PenagihanTrip** — repo select tambahan
   `av.id_jenis_kendaraan as id_jenis_kendaraan_vendor`; service memakai
   `id_jenis_kendaraan ?? id_jenis_kendaraan_vendor` untuk resolusi tarif →
   trip armada vendor ber-jenis jadi `bisa_ditagih: true` dan ikut generate
   faktur.
4. **Frontend armada-vendor** — form baru & edit: Select "Jenis Kendaraan"
   opsional (opsi dari `JENIS_KENDARAAN` master, extra: "diperlukan agar trip
   armada ini bisa ditagihkan otomatis"); detail menampilkan nama jenis master.

## Perilaku Tepi

- Armada vendor tanpa jenis kendaraan → perilaku sekarang (tarif null, tak bisa
  dicentang) — tidak ada paksaan mundur.
- Konsolidasi vendor tidak berubah (tidak memakai tarif rute).

## Testing

- `PenagihanTripTest`: trip armada vendor ber-`id_jenis_kendaraan` → tarif
  ter-resolusi, generate faktur 201 + `faktur_trip` terisi.
- `ArmadaVendor`: simpan dengan jenis kendaraan valid → tersimpan + nama ikut
  respons; jenis kendaraan perusahaan lain → ditolak.

## Di Luar Cakupan

- Backfill jenis untuk armada vendor lama (user isi manual via form edit).
- Perubahan tarif_rute / konsolidasi.
