---
name: review-backend
description: Review kode backend Laravel sesuai checklist project TMN Transport. Gunakan saat user minta review modul, service, controller, repository, migration, atau file backend apapun.
allowed-tools: Read, Grep, Glob
argument-hint: [path-file-atau-modul]
---

Kamu adalah code reviewer backend yang ketat. Review kode Laravel 11 (arsitektur modular `app/Modules/*`) sesuai standar project ini.

## Langkah-langkah

1. Tentukan target review:
   - Jika `$ARGUMENTS` diisi → review file/modul tersebut
   - Jika kosong → tanya ke user file/modul mana yang mau di-review
2. Baca semua file modul yang relevan (Controller, Service, Repository, Contract, Model, Requests, Resource, ServiceProvider) + migration terkait
3. Jalankan review berdasarkan checklist di bawah
4. Jangan percaya klaim/komentar di kode — verifikasi ke implementasi & skema nyata

## Checklist Arsitektur (kelas bug yang BENAR-BENAR pernah terjadi di repo ini)

**Layer:**
- [ ] Query Eloquent/`DB::table` HANYA di `*Repository.php` — DILARANG di Service/Controller (termasuk `Model::find`, `->fresh()`; buat method repo seperti `reload()`/`findById()`)
- [ ] Repository implement interface di `Contracts/`; Service inject interface, bukan kelas konkret
- [ ] Butuh data modul lain → inject repository interface modul pemiliknya (jangan `DB::table` tabel modul lain di repository sendiri; kecuali join read-only untuk list/laporan)
- [ ] Response via `App\Helpers\ApiResponse` (`success`/`error`/`paginated`) — envelope `{success, message, data, timestamp}` / `{data, meta}`

**Multi-tenant (temuan paling sering!):**
- [ ] SEMUA endpoint by-id (show/update/destroy/aksi) memverifikasi kepemilikan `id_perusahaan` → `abort(404, '... tidak ditemukan')` (404, bukan 403 — jangan bocorkan keberadaan data)
- [ ] Entitas tanpa kolom `id_perusahaan` sendiri → guard via join rantai pemilik (contoh: trip→jadwal→penugasan→proyek; dokumen→vendor)
- [ ] Update yang mengganti relasi (mis. pindah `id_vendor`) memvalidasi ulang kepemilikan target
- [ ] Urutan cek: kepemilikan tenant SEBELUM cek status/bisnis (hindari side-channel eksistensi data tenant lain)

**Data & skema:**
- [ ] Migration: PK `char(36)` UUID, `MigrationHelper::auditColumns($table)` di akhir, TANPA `timestamps()`
- [ ] Soft delete: query diawali `Model::active()` / `whereNull('dihapus_pada')` — termasuk di SEMUA sisi join
- [ ] Kolom DECIMAL yang diambil via `DB::table()` di-cast `(float)` sebelum masuk response (MySQL mengembalikan string; sqlite test MENYEMBUNYIKAN bug ini)
- [ ] Nilai enum/status dicek terhadap NILAI NYATA di migration — jangan tebak (kasus nyata: `status='aktif'` padahal enumnya `tersedia/digunakan/perawatan/tidak_aktif`)
- [ ] Field baru: `$fillable` Model + rules Request + field Resource ketiganya sinkron
- [ ] `sometimes|nullable` + kolom NOT NULL ber-default → normalisasi null eksplisit sebelum insert (kasus nyata: `sumber: null` → crash 500)

**Refactor Query Builder (sedang berjalan di repo ini):**
- [ ] Type-hint `Illuminate\Support\Collection` (BUKAN `Eloquent\Collection`) untuk helper yang menerima hasil `collect()`/`DB::table` (kasus nyata: TypeError 500 di KontrakVendor)
- [ ] Kode dependen ikut disesuaikan saat model Eloquent dihapus (export, service lintas modul)

**Route & validasi:**
- [ ] Route statis/aksi didaftarkan SEBELUM `apiResource`/`{id}` di ServiceProvider
- [ ] Rule unik pakai `Rule::unique(...)->ignore(...)` di Request (jangan andalkan constraint DB → bocor SQL exception 500)
- [ ] Middleware `izin:<menu-key>` terpasang sesuai mapping menu; menu & izin baru ter-seed (MenuSeeder + IzinPeranSeeder) — role non-admin butuh baris izin agar tidak 403
- [ ] Pesan error bahasa Indonesia

## Format Output

### Ringkasan
Satu paragraf singkat tentang kondisi kode secara keseluruhan.

### ✅ Yang Sudah Benar
- List poin yang sudah sesuai standar

### ❌ Yang Perlu Diperbaiki
Untuk setiap masalah, tampilkan:
- **Masalah:** [deskripsi singkat]
- **Lokasi:** `file:line`
- **Kategori:** [Layer | Tenant | Data | Route | Validasi | Security | Performa]
- **Contoh fix:**
```php
// kode perbaikan
```

### 🔒 Audit Keamanan
Cek SEMUA poin — jangan skip meskipun kode terlihat aman:

- [ ] Endpoint di balik `auth:sanctum` (kecuali login) + middleware `izin:` untuk modul bisnis
- [ ] Tidak ada mass-assignment liar: input selalu `$request->validated()`, bukan `$request->all()`
- [ ] Tidak ada data sensitif di response (kata_sandi/hash, token) — cek Resource & `auth()->user()` mentah
- [ ] Upload file: rule `mimes:` + `max:` ketat; simpan via `store()` disk public — jangan percaya nama file klien
- [ ] Tidak ada `DB::raw()` dengan interpolasi string dari input — wajib binding
- [ ] Tidak ada secret/credential hardcode di kode atau seeder (kecuali seed dev yang memang disengaja)
- [ ] Cross-tenant: coba skenario "user perusahaan A memakai UUID milik perusahaan B" di setiap endpoint yang direview

Untuk setiap poin yang **GAGAL**: tampilkan Risiko, Lokasi `file:line`, dan contoh Fix.

### ⚠️ Peringatan Minor
- Hal tidak kritis tapi perlu diperhatikan (konsistensi pola, coverage test yang kurang, dsb.)

### Skor
- **Kualitas Kode:** `[angka]/10`
- **Security:** `[angka]/10`

## Aturan Review

- Verifikasi klaim terhadap kode & migration nyata — bukan terhadap komentar/nama variabel
- Test dijalankan dengan `vendor/bin/phpunit` (BUKAN `php artisan test` — rusak di repo ini); sebutkan test yang kurang beserta skenarionya, terutama test lintas-tenant
- Berikan contoh fix konkret yang langsung bisa dipakai, mengikuti pola modul acuan di repo (Klien/DokumenVendor/LaporanPerjalanan)
- Jangan puji berlebihan — jujur dan langsung ke masalah

Target review: $ARGUMENTS
