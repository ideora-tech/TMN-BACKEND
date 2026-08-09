# Link Pembelian di Riwayat Mutasi Stok — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kolom Keterangan di tabel Riwayat Mutasi Stok (detail Spare Part) menjadi link ke halaman detail pembelian jika mutasi bersumber dari pembelian.

**Architecture:** Tambah kolom `id_pembelian` (nullable, FK longgar) di `sparepart_mutasi` + backfill data lama via pencocokan teks keterangan per perusahaan. Realisasi pembelian mengisi kolom ini; endpoint mutasi sparepart mengekspornya; frontend merender `<Link>` bila terisi.

**Tech Stack:** Laravel 11 (query builder, PHPUnit + SQLite in-memory), Next.js 15 App Router.

**Spec:** `docs/superpowers/specs/2026-08-09-link-pembelian-mutasi-stok-design.md`

## Global Constraints

- **DILARANG `git commit` / menyentuh git state** — user commit manual (preferensi user; override langkah commit standar skill).
- **DILARANG menjalankan build/migrate** (`npm run build`, `docker compose`, `php artisan migrate`) — user jalankan sendiri.
- Test backend: `vendor/bin/phpunit` (JANGAN `php artisan test`).
- Semua teks UI bahasa Indonesia; tanpa komentar penjelas di kode.
- Multi-tenant: nomor pengajuan hanya unik per `id_perusahaan` — backfill wajib scoped per perusahaan.

---

### Task 1: Backend — kolom `id_pembelian`, backfill, insert realisasi, ekspor endpoint

**Files:**
- Create: `TMN-TRANSPORT-BACKEND/database/migrations/2026_08_11_000002_add_id_pembelian_to_sparepart_mutasi_table.php`
- Modify: `TMN-TRANSPORT-BACKEND/app/Modules/PembelianSparepart/PembelianSparepartRepository.php:175-183` (method `tambahStokDanMutasi`)
- Modify: `TMN-TRANSPORT-BACKEND/app/Modules/Sparepart/SparepartRepository.php:22-25` (const `MUTASI_COLUMNS`)
- Test: `TMN-TRANSPORT-BACKEND/tests/Feature/PembelianBuktiRealisasiTest.php:103-128` (extend `test_realisasi_menaikkan_stok_dan_membuat_mutasi`)

**Interfaces:**
- Consumes: `$header->id_pembelian` (PK `pembelian_sparepart`, sudah tersedia di objek header dari `findById`).
- Produces: field `id_pembelian: string|null` pada respons `GET /api/v1/sparepart/{id}/mutasi` — dipakai Task 2.

- [x] **Step 1: Extend test realisasi (failing test)**

Di `PembelianBuktiRealisasiTest::test_realisasi_menaikkan_stok_dan_membuat_mutasi`, ganti blok assert terakhir (baris 123-127) menjadi:

```php
        $idSparepart = $items[0]['id_sparepart'];
        $this->assertSame(2, (int) DB::table('sparepart')->where('id_sparepart', $idSparepart)->value('stok'));
        $this->assertDatabaseHas('sparepart_mutasi', [
            'id_sparepart' => $idSparepart, 'jenis' => 'masuk', 'qty' => 2, 'harga' => 65000,
            'id_pembelian' => $id,
        ]);

        $mutasi = $this->getJson("/api/v1/sparepart/{$idSparepart}/mutasi")->json('data');
        $this->assertSame($id, $mutasi[0]['id_pembelian']);
```

- [x] **Step 2: Jalankan test, pastikan gagal**

Run: `vendor/bin/phpunit --filter=test_realisasi_menaikkan_stok_dan_membuat_mutasi`
Expected: FAIL (kolom `id_pembelian` belum ada di tabel `sparepart_mutasi`).

- [x] **Step 3: Buat migration kolom + backfill**

File `database/migrations/2026_08_11_000002_add_id_pembelian_to_sparepart_mutasi_table.php` (timestamp dipilih agar urut setelah migration terakhir `2026_08_11_000001`; tabel `pembelian_sparepart` sudah ada saat migration ini jalan):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sparepart_mutasi', function (Blueprint $table) {
            $table->char('id_pembelian', 36)->nullable()->index()->after('id_perawatan');
        });

        $concat = DB::connection()->getDriverName() === 'sqlite'
            ? "'Pembelian ' || p.nomor_pengajuan"
            : "CONCAT('Pembelian ', p.nomor_pengajuan)";
        DB::statement("
            UPDATE sparepart_mutasi SET id_pembelian = (
                SELECT p.id_pembelian
                FROM pembelian_sparepart p
                JOIN sparepart s ON s.id_sparepart = sparepart_mutasi.id_sparepart
                WHERE p.id_perusahaan = s.id_perusahaan
                  AND sparepart_mutasi.keterangan = {$concat}
                LIMIT 1
            )
            WHERE jenis = 'masuk' AND id_pembelian IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('sparepart_mutasi', function (Blueprint $table) {
            $table->dropColumn('id_pembelian');
        });
    }
};
```

Catatan: backfill tidak bisa diuji otomatis (RefreshDatabase menjalankan migration sebelum ada data). Verifikasi manual oleh user setelah migrate:
`SELECT keterangan, id_pembelian FROM sparepart_mutasi WHERE jenis='masuk';` — baris `Pembelian PS-...` harus terisi.

- [x] **Step 4: Isi `id_pembelian` saat realisasi**

Di `PembelianSparepartRepository::tambahStokDanMutasi` (baris 175), tambah satu baris pada array insert:

```php
            DB::table('sparepart_mutasi')->insert(RecordHelper::stampCreate([
                'id_sparepart' => $item->id_sparepart,
                'jenis'        => 'masuk',
                'qty'          => (int) $item->qty,
                'harga'        => $item->harga_aktual,
                'id_perawatan' => $header->id_perawatan,
                'id_pembelian' => $header->id_pembelian,
                'keterangan'   => 'Pembelian ' . $header->nomor_pengajuan,
                'tanggal'      => $header->tanggal_pembelian,
            ], 'id_mutasi'));
```

- [x] **Step 5: Ekspor kolom di endpoint mutasi**

Di `SparepartRepository`, const `MUTASI_COLUMNS` (baris 22) tambah `'id_pembelian'`:

```php
    private const MUTASI_COLUMNS = [
        'id_mutasi', 'id_sparepart', 'jenis', 'qty', 'harga', 'id_perawatan', 'id_pembelian', 'keterangan', 'tanggal',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];
```

- [x] **Step 6: Jalankan test, pastikan lolos**

Run: `vendor/bin/phpunit --filter=test_realisasi_menaikkan_stok_dan_membuat_mutasi`
Expected: PASS

- [x] **Step 7: Jalankan seluruh suite backend**

Run: `vendor/bin/phpunit`
Expected: semua PASS (regresi nol). JANGAN commit — user commit manual.

---

### Task 2: Frontend — render link di kolom Keterangan

**Files:**
- Modify: `TMN-TRANSPORT-FRONTEND/src/services/sparepart.service.ts:19-29` (interface `SparepartMutasi`)
- Modify: `TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/sparepart/[id]/page.tsx:279` (sel Keterangan) + import `Link`

**Interfaces:**
- Consumes: `id_pembelian: string|null` dari respons endpoint mutasi (Task 1); `ROUTES.PEMBELIAN_SPAREPART_DETAIL(id)` (sudah ada di `route.constant.ts:44`).
- Produces: — (perubahan UI terminal).

- [x] **Step 1: Tambah field di interface `SparepartMutasi`**

Di `sparepart.service.ts`, setelah `id_perawatan: string | null`:

```ts
export interface SparepartMutasi {
    id_mutasi: string
    id_sparepart: string
    jenis: 'masuk' | 'keluar' | 'penyesuaian'
    qty: number
    harga: number | null
    id_perawatan: string | null
    id_pembelian: string | null
    keterangan: string | null
    tanggal: string
    dibuat_pada: string
}
```

- [x] **Step 2: Render link di sel Keterangan**

Di `sparepart/[id]/page.tsx`, tambah import di blok import atas:

```ts
import Link from 'next/link'
```

Ganti sel Keterangan (baris 279):

```tsx
                                        <td className="py-3 text-gray-600 dark:text-gray-400 max-w-[240px] truncate">
                                            {m.id_pembelian ? (
                                                <Link href={ROUTES.PEMBELIAN_SPAREPART_DETAIL(m.id_pembelian)}
                                                    className="text-blue-500 hover:underline">
                                                    {m.keterangan}
                                                </Link>
                                            ) : (
                                                m.keterangan ?? <span className="text-gray-400">—</span>
                                            )}
                                        </td>
```

(Pola link mengikuti `invoice-vendor/[id]/page.tsx:300` — `text-blue-500 hover:underline`.)

- [x] **Step 3: Lint frontend**

Run: `npm run lint` (di folder `TMN-TRANSPORT-FRONTEND`)
Expected: lolos tanpa error baru. JANGAN `npm run build` / commit — user jalankan sendiri.

---

## Verifikasi Manual (oleh user)

1. Jalankan migration (docker/host sesuai setup).
2. Buka detail sparepart yang punya mutasi `Pembelian PS-...` → teks jadi link biru → klik → mendarat di detail pembelian yang benar.
3. Mutasi `Pemakaian servis` / penyesuaian tetap teks biasa.
