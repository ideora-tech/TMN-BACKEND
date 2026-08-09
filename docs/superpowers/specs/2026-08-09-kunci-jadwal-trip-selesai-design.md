# Kunci Jadwal Shift yang Tripnya Sudah Selesai / Sedang Berjalan

**Tanggal:** 2026-08-09
**Status:** Disetujui user (scope penuh: hapus + ganti shift + import timpa)

## Masalah

Jadwal shift di papan bisa dihapus (tombol hapus & bulk delete), diganti shift-nya,
atau ditimpa lewat import — termasuk untuk hari yang supirnya **sudah menyelesaikan
trip** atau **sedang menjalankan trip**. Riwayat trip sendiri aman (tidak ada FK ke
`jadwal_shift`), tapi papan dan export bulanan jadi bohong: supir nyatanya kerja
hari itu, papan bilang tidak dijadwalkan.

Guard existing hanya melindungi jadwal **hari ini** saat supir punya **trip aktif**
(alasan: alokasi armada), bukan riwayat hari lampau.

## Solusi

### Mekanisme deteksi

Helper privat baru `JadwalShiftService::statusTripUntukJadwal(object $record): ?string`
— memanggil `TripRepositoryInterface::statusTripPerSupirTanggal($record->id_proyek,
[$record->id_supir], $record->tanggal, $record->tanggal)` (method existing yang
dipakai papan untuk badge sel; scope per proyek; `belum_mulai` dihitung `berjalan`
karena armada sudah dikunci). Return `'berjalan'` | `'selesai'` | `null`.

Label pesan: `'selesai'` → "sudah selesai", `'berjalan'` → "sedang berjalan".

### Tiga titik guard (semua di `JadwalShiftService`)

1. **`delete()`** — bila status non-null → abort 422:
   `"Jadwal tanggal {tanggal} tidak dapat dihapus — trip supir pada tanggal ini {label}"`.
   Bulk delete papan sudah per-sel: sel yang kena guard muncul di dialog gagal,
   sel lain tetap terhapus. Guard lama (hari ini + trip aktif lintas proyek via
   `findTripAktifUntukAktor`) TETAP dipertahankan.
2. **`updateShift()`** — guard sama → abort 422:
   `"Jadwal tanggal {tanggal} tidak dapat diganti shift-nya — trip supir pada tanggal ini {label}"`.
3. **`importMatriks`** (cabang timpa, SETELAH cek shift sama) — bila status non-null
   → masuk `gagal[]`:
   `"Tanggal {tanggal}: trip supir {label} — jadwal tidak ditimpa"`; jadwal lama utuh.
   Kasus shift sama tetap `sukses` (no-op). Guard lama today+trip-aktif tetap.

### Frontend

Tidak ada perubahan — pesan 422 tampil via toast/dialog gagal existing; sel ber-trip
sudah ditandai badge `status_trip` di papan.

## Perilaku Tepi

- Trip `dibatalkan` → tidak mengunci (statusTripPerSupirTanggal tidak memasukkannya).
- Trip supir di **proyek lain** pada tanggal itu → tidak mengunci jadwal proyek ini
  (scope per proyek); tetap ada guard lama untuk trip aktif lintas proyek hari ini.
- Tanggal trip ditentukan dari `waktu_checkin ?? waktu_checkout ?? dibuat_pada`
  (konsisten dengan badge papan).

## Testing (PHPUnit)

- `JadwalShiftTest`: hapus jadwal ber-trip `selesai` → 422; ber-trip `berjalan` →
  422; tanpa trip → 200 (tetap bisa); ganti shift jadwal ber-trip `selesai` → 422.
- `JadwalShiftImportTest`: import timpa (shift beda) pada hari ber-trip `selesai` →
  masuk `gagal`, shift lama tidak berubah di DB.
- Seluruh suite tetap lolos.

## Di Luar Cakupan

- Menonaktifkan tombol hapus/ganti di frontend untuk sel ber-trip (backend sudah
  menolak; polish UI bisa menyusul).
- Guard serupa untuk modul Penugasan (ini khusus papan jadwal shift).
