# Sederhanakan Alur Trip — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Trip dimulai langsung dari penugasan (record jadwal_keberangkatan dibuat otomatis), aksi lifecycle pindah ke detail Trip, dan menu + halaman Jadwal dihapus dari UI web.

**Architecture:** Backend menambah endpoint `POST /api/v1/trip/mulai` di modul Trip (service meng-orchestrate: guard trip aktif → auto-create jadwal via JadwalKeberangkatanRepository → create trip → checkin) plus filter `id_penugasan`/`id_supir` di list trip. Frontend menambah dialog MulaiTrip yang dipakai Trip Monitor & detail Penugasan, memindahkan aksi checkin/checkout/batalkan ke `trip/[id]`, mengganti daftar jadwal di detail Penugasan/Supir dengan daftar trip, lalu menghapus seluruh UI jadwal.

**Tech Stack:** Laravel 11 (modul Trip pakai Eloquent Model — ikuti pola modul ini, BUKAN pola QB murni), Next.js 15 + Ecme, phpunit (SQLite in-memory).

## Global Constraints

- **DILARANG `git commit`** — setiap task diakhiri `git add <path spesifik>` saja (tanpa `-A`/`.`); user commit sendiri. DILARANG menjalankan docker/npm build.
- Backend test: `vendor/bin/phpunit` (JANGAN `php artisan test`).
- Response API shape standar: `{ success, message, data }` via `ApiResponse::success/paginated`.
- Scope semua query ke `id_perusahaan` (via join proyek) + soft-delete aware (`whereNull('dihapus_pada')` / scope `active()`).
- Pesan error & teks UI bahasa Indonesia. Frontend: `parseApiError(err)`, konstanta `ROUTES.*` / `API_ENDPOINTS.*`, tanpa `toLocaleString('id-ID')`.
- Warna status trip (sudah dipakai, jangan diubah): `belum_mulai` biru, `berjalan` emerald, `selesai` ungu, `dibatalkan` merah — kelas persis seperti `STATUS_TAG` di `trip/page.tsx`.
- Jangan tulis komentar penjelas di kode kecuali constraint yang tidak terlihat dari kodenya.
- JANGAN menyentuh endpoint jadwal existing (index/show/store/update/destroy/by-supir) dan `API_ENDPOINTS.JADWAL_SHIFT*` (modul lain).
- Verifikasi frontend per task: `npx tsc --noEmit -p tsconfig.json` + `npx eslint <file yang disentuh>` dari `D:\PROJECT-TMN\TMN-TRANSPORT-FRONTEND`.

Repo backend: `D:\PROJECT-TMN\TMN-TRANSPORT-BACKEND`. Repo frontend: `D:\PROJECT-TMN\TMN-TRANSPORT-FRONTEND`.

---

### Task 1: Backend — endpoint `POST /trip/mulai` + filter list trip

**Files:**
- Modify: `app/Modules/Trip/TripService.php`
- Modify: `app/Modules/Trip/TripRepository.php`
- Modify: `app/Modules/Trip/Contracts/TripRepositoryInterface.php`
- Create: `app/Modules/Trip/Requests/MulaiTripRequest.php`
- Modify: `app/Modules/Trip/TripController.php`
- Modify: `app/Modules/Trip/TripServiceProvider.php`
- Test: `tests/Feature/TripMulaiTest.php` (create)

**Interfaces:**
- Consumes: `JadwalKeberangkatanRepositoryInterface::create(array): JadwalKeberangkatanModel`, `RuteRepositoryInterface::findById(string)` (keduanya sudah ada dan ter-bind di provider masing-masing).
- Produces: `POST /api/v1/trip/mulai` body `{ id_penugasan, id_rute?, catatan? }` → 201 `{ data: TripResource }` dengan `status='berjalan'`; `GET /api/v1/trip?id_penugasan=X` dan `?id_supir=Y` (dipakai Task 3-7).

- [ ] **Step 1: Tulis failing test** — buat `tests/Feature/TripMulaiTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TripMulaiTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Mulai Trip',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeSupir(): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Budi Santoso',
            'no_sim'        => 'SIM-' . Str::random(8),
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeRute(string $nama = 'Jakarta - Bandung'): string
    {
        $id = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute'       => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute'     => 'RUT-' . Str::random(8),
            'nama_rute'     => $nama,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makePenugasan(?string $idPerusahaan = null): PenugasanModel
    {
        $proyek = ProyekModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien($idPerusahaan),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Mulai Trip',
        ]);

        $idArmada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . rand(1000, 9999) . ' MT',
            'merk'          => 'Hino',
        ])->id_armada;

        return PenugasanModel::create([
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $idArmada,
            'id_supir'  => $this->makeSupir(),
        ]);
    }

    public function test_mulai_trip_membuat_jadwal_otomatis_dan_trip_berjalan(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan();
        $idRute    = $this->makeRute('Jakarta - Bandung');

        $res = $this->postJson('/api/v1/trip/mulai', [
            'id_penugasan' => $penugasan->id_penugasan,
            'id_rute'      => $idRute,
        ]);

        $res->assertStatus(201)->assertJsonPath('data.status', 'berjalan');

        $this->assertDatabaseHas('jadwal_keberangkatan', [
            'id_penugasan' => $penugasan->id_penugasan,
            'id_rute'      => $idRute,
            'rute'         => 'Jakarta - Bandung',
        ]);
        $this->assertSame(1, DB::table('trip')->whereNotNull('waktu_checkin')->where('status', 'berjalan')->count());
    }

    public function test_mulai_trip_tanpa_rute_boleh(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan();

        $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasan->id_penugasan])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'berjalan');
    }

    public function test_mulai_trip_ditolak_jika_masih_ada_trip_aktif(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan();

        $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasan->id_penugasan])->assertStatus(201);

        $res = $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasan->id_penugasan]);
        $res->assertStatus(422);
        $this->assertStringContainsString('trip aktif', $res->json('message'));
    }

    public function test_setelah_checkout_bisa_mulai_trip_lagi(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan();

        $idTrip = $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasan->id_penugasan])
            ->json('data.id_trip');
        $this->postJson("/api/v1/trip/{$idTrip}/checkout")->assertStatus(200);

        $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasan->id_penugasan])->assertStatus(201);
    }

    public function test_mulai_trip_penugasan_perusahaan_lain_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $penugasanLain = $this->makePenugasan($idPerusahaanLain);

        $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasanLain->id_penugasan])
            ->assertStatus(404);
    }

    public function test_list_trip_filter_id_penugasan_dan_id_supir(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasanA = $this->makePenugasan();
        $penugasanB = $this->makePenugasan();

        $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasanA->id_penugasan])->assertStatus(201);
        $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasanB->id_penugasan])->assertStatus(201);

        $byPenugasan = $this->getJson("/api/v1/trip?id_penugasan={$penugasanA->id_penugasan}");
        $byPenugasan->assertStatus(200);
        $this->assertCount(1, $byPenugasan->json('data'));

        $bySupir = $this->getJson("/api/v1/trip?id_supir={$penugasanB->id_supir}");
        $bySupir->assertStatus(200);
        $this->assertCount(1, $bySupir->json('data'));
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal** — `vendor/bin/phpunit --filter=TripMulaiTest`. Expected: FAIL (404/405 route tidak ada).

- [ ] **Step 3: Buat `app/Modules/Trip/Requests/MulaiTripRequest.php`:**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Trip\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MulaiTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_penugasan' => ['required', 'string', 'exists:penugasan,id_penugasan,dihapus_pada,NULL'],
            'id_rute'      => ['sometimes', 'nullable', 'string', 'exists:rute,id_rute,dihapus_pada,NULL'],
            'catatan'      => ['sometimes', 'nullable', 'string'],
        ];
    }
}
```

- [ ] **Step 4: Tambah 2 method repository.** Di `app/Modules/Trip/Contracts/TripRepositoryInterface.php` tambahkan signature (sesuaikan gaya interface yang ada; ubah juga signature `paginate` menambah 2 parameter opsional):

```php
public function paginate(string $idPerusahaan, int $page, int $limit, ?string $idJadwal = null, ?string $idPenugasan = null, ?string $idSupir = null): LengthAwarePaginator;

public function findPenugasanMilikPerusahaan(string $idPenugasan, string $idPerusahaan): ?object;

public function adaTripAktifUntukAktor(?string $idArmada, ?string $idSupir, ?string $idArmadaVendor, ?string $idSupirVendor): bool;
```

Di `app/Modules/Trip/TripRepository.php`: pada method `paginate`, ubah signature sesuai interface dan tambahkan dua `->when()` setelah `->when($idJadwal, ...)`:

```php
            ->when($idPenugasan, fn ($q, $v) => $q->where('jk.id_penugasan', $v))
            ->when($idSupir, fn ($q, $v) => $q->where('p.id_supir', $v))
```

Lalu tambahkan dua method baru (letakkan setelah `findByJadwal`):

```php
    public function findPenugasanMilikPerusahaan(string $idPenugasan, string $idPerusahaan): ?object
    {
        return DB::table('penugasan as p')
            ->join('proyek as pr', 'pr.id_proyek', '=', 'p.id_proyek')
            ->where('p.id_penugasan', $idPenugasan)
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->select('p.*')
            ->first();
    }

    public function adaTripAktifUntukAktor(?string $idArmada, ?string $idSupir, ?string $idArmadaVendor, ?string $idSupirVendor): bool
    {
        if (!$idArmada && !$idSupir && !$idArmadaVendor && !$idSupirVendor) {
            return false;
        }

        return DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNotIn('t.status', ['selesai', 'dibatalkan'])
            ->where(function ($q) use ($idArmada, $idSupir, $idArmadaVendor, $idSupirVendor) {
                if ($idArmada) {
                    $q->orWhere('p.id_armada', $idArmada);
                }
                if ($idSupir) {
                    $q->orWhere('p.id_supir', $idSupir);
                }
                if ($idArmadaVendor) {
                    $q->orWhere('p.id_armada_vendor', $idArmadaVendor);
                }
                if ($idSupirVendor) {
                    $q->orWhere('p.id_supir_vendor', $idSupirVendor);
                }
            })
            ->exists();
    }
```

Tambahkan `use Illuminate\Support\Facades\DB;` bila belum ada di file.

- [ ] **Step 5: Service.** Di `app/Modules/Trip/TripService.php`, ubah constructor dan `list`, tambah `mulaiDariPenugasan`:

```php
use App\Modules\JadwalKeberangkatan\Contracts\JadwalKeberangkatanRepositoryInterface;
use App\Modules\Rute\Contracts\RuteRepositoryInterface;
use App\Modules\Trip\Contracts\TripRepositoryInterface;

class TripService
{
    public function __construct(
        private readonly TripRepositoryInterface $repo,
        private readonly JadwalKeberangkatanRepositoryInterface $jadwalRepo,
        private readonly RuteRepositoryInterface $ruteRepo
    ) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $idJadwal = null, ?string $idPenugasan = null, ?string $idSupir = null): array
    {
        $result = $this->repo->paginate($idPerusahaan, $page, $limit, $idJadwal, $idPenugasan, $idSupir);
        // ... (body meta tidak berubah)
    }

    public function mulaiDariPenugasan(array $data, string $idPerusahaan): TripModel
    {
        $penugasan = $this->repo->findPenugasanMilikPerusahaan($data['id_penugasan'], $idPerusahaan);
        if ($penugasan === null) {
            abort(404, 'Penugasan tidak ditemukan');
        }

        if ($this->repo->adaTripAktifUntukAktor(
            $penugasan->id_armada,
            $penugasan->id_supir,
            $penugasan->id_armada_vendor,
            $penugasan->id_supir_vendor
        )) {
            abort(422, 'Supir/armada masih memiliki trip aktif');
        }

        $idRute = $data['id_rute'] ?? null;
        $jadwal = $this->jadwalRepo->create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'id_rute'         => $idRute,
            'rute'            => $idRute !== null ? $this->ruteRepo->findById($idRute)?->nama_rute : null,
            'waktu_berangkat' => now(),
        ]);

        $trip = $this->repo->create([
            'id_jadwal' => $jadwal->id_jadwal,
            'catatan'   => $data['catatan'] ?? null,
        ]);

        return $this->checkin($trip->id_trip);
    }
```

- [ ] **Step 6: Controller + route.** Di `TripController.php` tambah import `MulaiTripRequest` dan method (letakkan setelah `store`); di `index` teruskan 2 filter baru:

```php
    // index(): tambahkan dua argumen setelah id_jadwal
            $request->get('id_jadwal'),
            $request->get('id_penugasan'),
            $request->get('id_supir')

    public function mulai(MulaiTripRequest $request): JsonResponse
    {
        $record = $this->service->mulaiDariPenugasan(
            $request->validated(),
            (string) $request->user()->id_perusahaan
        );
        return ApiResponse::success(new TripResource($record), 'Trip berhasil dimulai', 201);
    }
```

Di `TripServiceProvider::boot()` tambahkan SEBELUM `Route::get('trip/{id}', ...)`:

```php
                Route::post('trip/mulai', [TripController::class, 'mulai']);
```

- [ ] **Step 7: Jalankan test.** `vendor/bin/phpunit --filter=TripMulaiTest` → 6/6 PASS. Lalu full suite `vendor/bin/phpunit` → semua hijau (perhatikan `TripTest`, `StatusTripTest`, `RekapBiayaTest` tidak boleh pecah — constructor TripService berubah tapi DI container auto-resolve).

- [ ] **Step 8: Stage.**

```bash
git add app/Modules/Trip/TripService.php app/Modules/Trip/TripRepository.php app/Modules/Trip/Contracts/TripRepositoryInterface.php app/Modules/Trip/Requests/MulaiTripRequest.php app/Modules/Trip/TripController.php app/Modules/Trip/TripServiceProvider.php tests/Feature/TripMulaiTest.php
```

---

### Task 2: Backend — migration hapus menu Jadwal + rapikan MenuSeeder

**Files:**
- Create: `database/migrations/2026_07_19_000001_hapus_menu_jadwal.php`
- Modify: `database/seeders/MenuSeeder.php`

**Interfaces:**
- Consumes: tabel `menu` (kolom `id_menu`, `path`) dan `menu_peran` (`id_menu`, `kode_peran`).
- Produces: DB berjalan tidak lagi punya menu `/jadwal`; fresh seed juga tidak.

- [ ] **Step 1: Buat migration** `database/migrations/2026_07_19_000001_hapus_menu_jadwal.php` (ikuti gaya `2026_07_17_000009_seed_menu_shift.php` — tanpa `declare(strict_types=1)`):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('menu')->where('path', '/jadwal')->pluck('id_menu');

        DB::table('menu_peran')->whereIn('id_menu', $ids)->delete();
        DB::table('menu')->whereIn('id_menu', $ids)->delete();
    }

    public function down(): void
    {
        // Menu Jadwal dihapus permanen; pemulihan lewat MenuSeeder versi sebelum 2026-07-19
    }
};
```

- [ ] **Step 2: Rapikan `database/seeders/MenuSeeder.php`** — hapus TIGA hal:
  1. Entri `'jadwal' => 'm0000001-0000-4000-8000-000000000024',` dari array `$ids`.
  2. Baris menu: `['id_menu' => $ids['jadwal'], 'nama_menu' => 'Jadwal', 'path' => '/jadwal', ...]` dari array `$menus`.
  3. Empat baris `[$ids['jadwal'], 'DISPATCHER'|'MANAGER'|'ADMIN'|'SUPERADMIN'],` dari array `$menuPeran`.

  Jangan ubah `urutan` menu lain (gap urutan 4 di children Operasional tidak apa-apa).

- [ ] **Step 3: Verifikasi.** `vendor/bin/phpunit` full suite tetap hijau (RefreshDatabase menjalankan semua migration termasuk yang baru; seeder tidak dijalankan di test). Cek sintaks seeder: `php -l database/seeders/MenuSeeder.php`.

- [ ] **Step 4: Stage.**

```bash
git add database/migrations/2026_07_19_000001_hapus_menu_jadwal.php database/seeders/MenuSeeder.php
```

---

### Task 3: Frontend — perluas trip.service + konstanta + project.service limit

**Files:**
- Modify: `src/services/trip.service.ts`
- Modify: `src/constants/api.constant.ts`
- Modify: `src/services/project.service.ts`
- Modify: `src/app/(protected-pages)/trip/page.tsx` (hanya baris pemanggil `tripService.list`)

**Interfaces:**
- Produces (dipakai Task 4-7): `tripService.list(params?: { page?: number; limit?: number; id_penugasan?: string; id_supir?: string })`, `tripService.mulai(payload: { id_penugasan: string; id_rute?: string | null; catatan?: string | null })`, `API_ENDPOINTS.TRIP_MULAI`, `projectService.list(page?, limit?)`.

- [ ] **Step 1:** Di `src/constants/api.constant.ts`, di blok `// Trip` (setelah baris `TRIP:` dan sebelum `TRIP_DETAIL:`) tambahkan:

```ts
    TRIP_MULAI:    '/api/proxy/trip/mulai',
```

- [ ] **Step 2:** Di `src/services/trip.service.ts` ganti method `list` dan tambah `mulai` (interface `Trip` tidak berubah):

```ts
    async list(params: { page?: number; limit?: number; id_penugasan?: string; id_supir?: string } = {}) {
        const { data } = await axios.get(API_ENDPOINTS.TRIP, { params: { page: 1, limit: 15, ...params } })
        return data as { data: Trip[]; meta: { page: number; total: number; totalPages: number; limit: number } }
    },
    async mulai(payload: { id_penugasan: string; id_rute?: string | null; catatan?: string | null }) {
        const { data } = await axios.post(API_ENDPOINTS.TRIP_MULAI, payload)
        return data.data as Trip
    },
```

- [ ] **Step 3:** Di `src/app/(protected-pages)/trip/page.tsx` ubah pemanggil: `tripService.list(currentPage)` → `tripService.list({ page: currentPage })`.

- [ ] **Step 4:** Di `src/services/project.service.ts` ubah `async list(page = 1)` menjadi `async list(page = 1, limit = 15)` dan pakai `limit` di params (ganti `limit: 15` → `limit`).

- [ ] **Step 5: Verifikasi + stage.** `npx tsc --noEmit -p tsconfig.json` 0 error; eslint 4 file bersih.

```bash
git add src/services/trip.service.ts src/constants/api.constant.ts src/services/project.service.ts "src/app/(protected-pages)/trip/page.tsx"
```

---

### Task 4: Frontend — komponen MulaiTripDialog + tombol di Trip Monitor

**Files:**
- Create: `src/app/(protected-pages)/trip/MulaiTripDialog.tsx`
- Modify: `src/app/(protected-pages)/trip/page.tsx`

**Interfaces:**
- Consumes: `tripService.mulai`, `projectService.list(1, 100)`, `penugasanService.list(idProyek, 1, undefined, 100)`, `ruteService.list({ limit: 100 })`, `supirService.get` / `armadaService.get` / `supirVendorService.get` / `armadaVendorService.get`.
- Produces: `<MulaiTripDialog isOpen onClose onSukses idPenugasanTerkunci? idProyekTerkunci? />` (dipakai lagi di Task 6).

- [ ] **Step 1: Buat `src/app/(protected-pages)/trip/MulaiTripDialog.tsx`:**

```tsx
'use client'
import { useEffect, useState, useCallback } from 'react'
import { Button, FormItem, toast, Notification, Dialog } from '@/components/ui'
import Select from '@/components/ui/Select'
import { parseApiError } from '@/utils/error.util'
import { tripService } from '@/services/trip.service'
import { projectService } from '@/services/project.service'
import { penugasanService, Penugasan } from '@/services/penugasan.service'
import { ruteService, Rute } from '@/services/rute.service'
import { supirService } from '@/services/supir.service'
import { armadaService } from '@/services/armada.service'
import { supirVendorService } from '@/services/supirVendor.service'
import { armadaVendorService } from '@/services/armadaVendor.service'

type Option = { value: string; label: string }

type Props = {
    isOpen: boolean
    onClose: () => void
    onSukses: () => void
    idPenugasanTerkunci?: string
    idProyekTerkunci?: string
}

export default function MulaiTripDialog({ isOpen, onClose, onSukses, idPenugasanTerkunci, idProyekTerkunci }: Props) {
    const terkunci = !!idPenugasanTerkunci

    const [proyekOptions, setProyekOptions] = useState<Option[]>([])
    const [pilihProyek, setPilihProyek]     = useState('')
    const [penugasanOptions, setPenugasanOptions] = useState<Option[]>([])
    const [pilihPenugasan, setPilihPenugasan]     = useState('')
    const [ruteOptions, setRuteOptions] = useState<Option[]>([])
    const [pilihRute, setPilihRute]     = useState<string | null>(null)
    const [memuat, setMemuat] = useState(false)
    const [saving, setSaving] = useState(false)

    useEffect(() => {
        if (!isOpen) return
        setPilihProyek(idProyekTerkunci ?? '')
        setPilihPenugasan(idPenugasanTerkunci ?? '')
        setPilihRute(null)
        ruteService.list({ limit: 100 })
            .then(res => setRuteOptions((res.data as Rute[]).map(r => ({ value: r.id_rute, label: r.nama_rute }))))
            .catch(() => {})
        if (!terkunci) {
            projectService.list(1, 100)
                .then(res => setProyekOptions(res.data.map(p => ({ value: p.id_proyek, label: p.nama_proyek }))))
                .catch(err => toast.push(<Notification type="danger" title={parseApiError(err)} />))
        }
    }, [isOpen, terkunci, idPenugasanTerkunci, idProyekTerkunci])

    const muatPenugasan = useCallback(async (idProyek: string) => {
        setMemuat(true)
        try {
            const res = await penugasanService.list(idProyek, 1, undefined, 100)
            const rows = res.data.filter(p => p.status === 'pending' || p.status === 'aktif')
            const supirIds        = [...new Set(rows.map(p => p.id_supir).filter(Boolean))] as string[]
            const armadaIds       = [...new Set(rows.map(p => p.id_armada).filter(Boolean))] as string[]
            const supirVendorIds  = [...new Set(rows.map(p => p.id_supir_vendor).filter(Boolean))] as string[]
            const armadaVendorIds = [...new Set(rows.map(p => p.id_armada_vendor).filter(Boolean))] as string[]
            const [supirs, armadas, supirVendors, armadaVendors] = await Promise.all([
                Promise.all(supirIds.map(x => supirService.get(x).catch(() => null))),
                Promise.all(armadaIds.map(x => armadaService.get(x).catch(() => null))),
                Promise.all(supirVendorIds.map(x => supirVendorService.get(x).catch(() => null))),
                Promise.all(armadaVendorIds.map(x => armadaVendorService.get(x).catch(() => null))),
            ])
            const namaSupir: Record<string, string> = {}
            supirs.forEach(s => { if (s) namaSupir[s.id_supir] = s.nama })
            supirVendors.forEach(s => { if (s) namaSupir[s.id_supir_vendor] = s.nama })
            const nopolArmada: Record<string, string> = {}
            armadas.forEach(a => { if (a) nopolArmada[a.id_armada] = a.nopol })
            armadaVendors.forEach(a => { if (a) nopolArmada[a.id_armada_vendor] = a.nopol })

            setPenugasanOptions(rows.map((p: Penugasan) => {
                const supir  = namaSupir[p.id_supir ?? p.id_supir_vendor ?? ''] ?? 'Tanpa supir'
                const armada = nopolArmada[p.id_armada ?? p.id_armada_vendor ?? ''] ?? 'tanpa armada'
                return {
                    value: p.id_penugasan,
                    label: `${supir} — ${armada}${p.sumber === 'vendor' ? ' (vendor)' : ''}`,
                }
            }))
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setMemuat(false)
        }
    }, [])

    useEffect(() => {
        if (!isOpen || terkunci || !pilihProyek) return
        setPilihPenugasan('')
        muatPenugasan(pilihProyek)
    }, [isOpen, terkunci, pilihProyek, muatPenugasan])

    const handleSubmit = async () => {
        if (!pilihPenugasan) return
        setSaving(true)
        try {
            await tripService.mulai({ id_penugasan: pilihPenugasan, id_rute: pilihRute })
            toast.push(<Notification type="success" title="Trip berhasil dimulai" />)
            onSukses()
            onClose()
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setSaving(false)
        }
    }

    return (
        <Dialog isOpen={isOpen} onRequestClose={onClose} width={460}>
            <h5 className="text-base font-semibold mb-1">Mulai Trip</h5>
            <p className="text-xs text-gray-400 mb-4">
                Trip langsung berjalan (check-in otomatis) — pastikan supir & armada siap berangkat.
            </p>
            <form onSubmit={e => { e.preventDefault(); handleSubmit() }}>
                {!terkunci && (
                    <>
                        <FormItem label="Proyek" asterisk>
                            <Select placeholder="Pilih proyek..."
                                options={proyekOptions}
                                value={proyekOptions.find(o => o.value === pilihProyek) ?? null}
                                onChange={opt => setPilihProyek((opt as Option | null)?.value ?? '')} />
                        </FormItem>
                        <FormItem label="Penugasan (Supir — Armada)" asterisk>
                            <Select placeholder={memuat ? 'Memuat...' : 'Pilih penugasan...'}
                                isDisabled={!pilihProyek || memuat}
                                options={penugasanOptions}
                                value={penugasanOptions.find(o => o.value === pilihPenugasan) ?? null}
                                onChange={opt => setPilihPenugasan((opt as Option | null)?.value ?? '')} />
                        </FormItem>
                    </>
                )}
                <FormItem label="Rute (opsional)">
                    <Select isClearable placeholder="Pilih rute..."
                        options={ruteOptions}
                        value={ruteOptions.find(o => o.value === pilihRute) ?? null}
                        onChange={opt => setPilihRute((opt as Option | null)?.value ?? null)} />
                </FormItem>
                <div className="flex justify-end gap-2 mt-4">
                    <Button type="button" variant="plain" onClick={onClose}>Batal</Button>
                    <Button type="submit" variant="solid" loading={saving} disabled={!pilihPenugasan}>
                        Mulai Trip
                    </Button>
                </div>
            </form>
        </Dialog>
    )
}
```

Catatan implementer: cek dulu bentuk balikan `ruteService.list` (`res.data` array Rute — lihat `src/services/rute.service.ts`) dan field `nama` di `supirVendorService.get` / `nopol` di `armadaVendorService.get`; kalau nama field berbeda, sesuaikan mapping (jangan sesuaikan servicenya).

- [ ] **Step 2: Tombol di Trip Monitor.** Di `src/app/(protected-pages)/trip/page.tsx`:
  - Import: `import MulaiTripDialog from './MulaiTripDialog'` dan `HiOutlinePlay` ATAU `HiOutlinePlus` dari react-icons/hi (pakai `HiOutlinePlus`), plus `Button` dari `@/components/ui`.
  - State: `const [showMulai, setShowMulai] = useState(false)`.
  - Header page-level (div `flex items-center justify-between` yang berisi judul "Trip") — tambahkan di sisi kanan:

```tsx
                <Button variant="solid" icon={<HiOutlinePlus />} onClick={() => setShowMulai(true)}>
                    Mulai Trip
                </Button>
```

  - Sebelum penutup div terluar, render dialog:

```tsx
            <MulaiTripDialog isOpen={showMulai} onClose={() => setShowMulai(false)} onSukses={fetchData} />
```

- [ ] **Step 3: Verifikasi + stage.** tsc 0 error, eslint bersih.

```bash
git add "src/app/(protected-pages)/trip/MulaiTripDialog.tsx" "src/app/(protected-pages)/trip/page.tsx"
```

---

### Task 5: Frontend — aksi lifecycle di detail Trip

**Files:**
- Modify: `src/app/(protected-pages)/trip/[id]/page.tsx`

**Interfaces:**
- Consumes: `tripService.checkin/checkout/batalkan/get/getStatus` (sudah ada).

- [ ] **Step 1: Tambah state + handler** (letakkan setelah state `jenisBbmList`, sebelum useEffect pertama; tipe & konstanta di atas komponen):

```tsx
type AksiTrip = 'mulai' | 'selesai' | 'batalkan'

const AKSI_TITLE: Record<AksiTrip, string> = {
    mulai:    'Mulai Trip',
    selesai:  'Selesaikan Trip',
    batalkan: 'Batalkan Trip',
}

const AKSI_MESSAGE: Record<AksiTrip, string> = {
    mulai:    'Mulai trip ini? Status akan berubah menjadi berjalan.',
    selesai:  'Selesaikan trip ini? Status akan berubah menjadi selesai.',
    batalkan: 'Batalkan trip ini? Tindakan ini tidak dapat dibatalkan.',
}
```

```tsx
    const [aksiTrip, setAksiTrip]         = useState<AksiTrip | null>(null)
    const [aksiLoading, setAksiLoading]   = useState(false)

    const handleAksiTrip = async () => {
        if (!aksiTrip) return
        setAksiLoading(true)
        try {
            if (aksiTrip === 'mulai') await tripService.checkin(id)
            else if (aksiTrip === 'selesai') await tripService.checkout(id)
            else await tripService.batalkan(id)
            toast.push(<Notification type="success" title={`${AKSI_TITLE[aksiTrip]} berhasil`} />)
            setAksiTrip(null)
            const t = await tripService.get(id)
            setTrip(t)
            tripService.getStatus(id).then(setStatuses).catch(() => {})
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setAksiLoading(false)
        }
    }
```

- [ ] **Step 2: Card aksi + ConfirmDialog.** Setelah Card info trip pertama di JSX (blok Card pertama setelah header), tambahkan:

```tsx
            {(trip.status === 'belum_mulai' || trip.status === 'berjalan') && (
                <Card className="border border-dashed border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                    <div className="flex flex-col sm:flex-row items-start sm:items-center gap-3">
                        <div className="flex-1">
                            <p className="text-sm font-medium text-gray-700 dark:text-gray-300">Aksi Trip</p>
                            <p className="text-xs text-gray-400 mt-0.5">
                                Status saat ini: <span className="font-semibold">{STATUS_LABEL[trip.status]}</span>
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {trip.status === 'belum_mulai' && (
                                <Button size="sm" variant="solid" onClick={() => setAksiTrip('mulai')} disabled={aksiLoading}>
                                    Mulai Trip
                                </Button>
                            )}
                            {trip.status === 'berjalan' && (
                                <Button size="sm" variant="solid" onClick={() => setAksiTrip('selesai')} disabled={aksiLoading}>
                                    Selesaikan
                                </Button>
                            )}
                            <Button size="sm" variant="default"
                                className={`${STATUS_TAG['dibatalkan']} border border-current`}
                                onClick={() => setAksiTrip('batalkan')} disabled={aksiLoading}>
                                Batalkan
                            </Button>
                        </div>
                    </div>
                </Card>
            )}
```

Dan di bagian dialog (dekat ConfirmDialog foto yang sudah ada):

```tsx
            <ConfirmDialog isOpen={!!aksiTrip}
                type={aksiTrip === 'batalkan' ? 'danger' : 'info'}
                title={aksiTrip ? AKSI_TITLE[aksiTrip] : ''}
                confirmText="Ya, Lanjutkan" cancelText="Batal"
                onClose={() => setAksiTrip(null)}
                onCancel={() => setAksiTrip(null)}
                onConfirm={handleAksiTrip}
                confirmButtonProps={{ loading: aksiLoading }}>
                <p>{aksiTrip ? AKSI_MESSAGE[aksiTrip] : ''}</p>
            </ConfirmDialog>
```

- [ ] **Step 3: Verifikasi + stage.** tsc 0 error, eslint bersih.

```bash
git add "src/app/(protected-pages)/trip/[id]/page.tsx"
```

---

### Task 6: Frontend — detail Penugasan: section Jadwal → section Trip

**Files:**
- Modify: `src/app/(protected-pages)/penugasan/[id]/page.tsx`

**Interfaces:**
- Consumes: `tripService.list({ id_penugasan, limit: 50 })`, `MulaiTripDialog` (Task 4), `ROUTES.TRIP_DETAIL`.

- [ ] **Step 1: Bersihkan jadwal.** Hapus dari file:
  - Import `jadwalService, Jadwal` dan konstanta `JADWAL_STATUS_CLASS`.
  - State: `jadwalList, jadwalLoading, showJadwalForm, jadwalForm, jadwalErrors, addingJadwal, deleteJadwalTarget, deletingJadwal` (state `ruteOptions` DIPERTAHANKAN — masih dipakai rute estimasi BOK; cek pemakaiannya sebelum hapus).
  - Fungsi: `fetchJadwal` + useEffect-nya, `validateJadwal`, `handleAddJadwal`, `handleDeleteJadwal`.
  - JSX: seluruh Card "Jadwal Keberangkatan" (header + form + tabel) dan ConfirmDialog "Hapus Jadwal?".
  - Di `fetchJadwal` lama ada `setIdRuteEstimasi(prev => prev || (res.data[0]?.id_rute ?? ''))` — hilang bersama fungsi; `idRuteEstimasi` kini murni pilihan manual user (auto-fill BOK tetap jalan).

- [ ] **Step 2: Tambah section Trip.** Import baru:

```tsx
import MulaiTripDialog from '../../trip/MulaiTripDialog'
import { tripService, Trip } from '@/services/trip.service'
```

Konstanta (ganti posisi JADWAL_STATUS_CLASS lama):

```tsx
const TRIP_STATUS_CLASS: Record<string, string> = {
    belum_mulai: 'bg-blue-100 text-blue-600 dark:bg-blue-500/20 dark:text-blue-100',
    berjalan:    'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-100',
    selesai:     'bg-purple-100 text-purple-600 dark:bg-purple-500/20 dark:text-purple-100',
    dibatalkan:  'bg-red-100 text-red-500 dark:bg-red-500/20 dark:text-red-100',
}
```

State + fetch:

```tsx
    const [tripList, setTripList]       = useState<Trip[]>([])
    const [tripLoading, setTripLoading] = useState(false)
    const [showMulaiTrip, setShowMulaiTrip] = useState(false)

    const fetchTrip = useCallback(async () => {
        setTripLoading(true)
        try {
            const res = await tripService.list({ id_penugasan: id, limit: 50 })
            setTripList(res.data)
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setTripLoading(false)
        }
    }, [id])

    useEffect(() => { fetchTrip() }, [fetchTrip])
```

JSX pengganti Card jadwal (posisi sama, sebelum penutup div terluar):

```tsx
            {/* Trip */}
            <Card>
                <div className="flex items-center justify-between mb-1">
                    <div>
                        <p className="text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wide">Trip</p>
                        <p className="text-xs text-gray-400 mt-0.5">{tripList.length} trip tercatat</p>
                    </div>
                    <Button size="sm" variant="solid" icon={<HiOutlinePlus />} onClick={() => setShowMulaiTrip(true)}>
                        Mulai Trip
                    </Button>
                </div>

                {tripLoading ? (
                    <div className="flex justify-center py-6"><Spinner /></div>
                ) : tripList.length === 0 ? (
                    <p className="text-gray-400 text-sm py-6 text-center">Belum ada trip untuk penugasan ini</p>
                ) : (
                    <div className="overflow-x-auto mt-4">
                        <table className="w-full text-sm">
                            <thead className="bg-blue-50 dark:bg-blue-500/10">
                                <tr className="border-b border-gray-100 dark:border-gray-700">
                                    <th className="py-2.5 text-left text-xs font-medium text-gray-400 uppercase tracking-wide pr-4">Waktu Berangkat</th>
                                    <th className="py-2.5 text-left text-xs font-medium text-gray-400 uppercase tracking-wide pr-4">Rute</th>
                                    <th className="py-2.5 text-left text-xs font-medium text-gray-400 uppercase tracking-wide pr-4">Check-in</th>
                                    <th className="py-2.5 text-left text-xs font-medium text-gray-400 uppercase tracking-wide pr-4">Check-out</th>
                                    <th className="py-2.5 text-left text-xs font-medium text-gray-400 uppercase tracking-wide pr-4">Status</th>
                                    <th className="py-2.5" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                                {tripList.map(t => (
                                    <tr key={t.id_trip}>
                                        <td className="py-3 pr-4 text-gray-700 dark:text-gray-300 whitespace-nowrap">
                                            {t.waktu_berangkat ? dayjs(t.waktu_berangkat).format('DD MMM YYYY HH:mm') : <span className="text-gray-400">—</span>}
                                        </td>
                                        <td className="py-3 pr-4 text-gray-600 dark:text-gray-400 max-w-[200px] truncate">
                                            {t.rute ?? <span className="text-gray-400">—</span>}
                                        </td>
                                        <td className="py-3 pr-4 text-gray-500 whitespace-nowrap">
                                            {t.waktu_checkin ? dayjs(t.waktu_checkin).format('DD MMM HH:mm') : <span className="text-gray-400">—</span>}
                                        </td>
                                        <td className="py-3 pr-4 text-gray-500 whitespace-nowrap">
                                            {t.waktu_checkout ? dayjs(t.waktu_checkout).format('DD MMM HH:mm') : <span className="text-gray-400">—</span>}
                                        </td>
                                        <td className="py-3 pr-4">
                                            <Tag className={`text-xs font-semibold ${TRIP_STATUS_CLASS[t.status] ?? 'bg-gray-100 text-gray-600'}`}>
                                                {t.status.replace('_', ' ')}
                                            </Tag>
                                        </td>
                                        <td className="py-3 text-right">
                                            <Button size="xs" variant="plain" icon={<HiOutlineExternalLink />}
                                                onClick={() => router.push(ROUTES.TRIP_DETAIL(t.id_trip))} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <MulaiTripDialog isOpen={showMulaiTrip}
                onClose={() => setShowMulaiTrip(false)}
                onSukses={fetchTrip}
                idPenugasanTerkunci={id}
                idProyekTerkunci={penugasan?.id_proyek} />
```

- [ ] **Step 3: Verifikasi + stage.** tsc 0 error; eslint bersih; pastikan tidak ada lagi `jadwalService` di file (grep).

```bash
git add "src/app/(protected-pages)/penugasan/[id]/page.tsx"
```

---

### Task 7: Frontend — detail Supir: riwayat jadwal → riwayat trip

**Files:**
- Modify: `src/app/(protected-pages)/supir/[id]/page.tsx`

**Interfaces:**
- Consumes: `tripService.list({ id_supir, limit: 50 })`, `ROUTES.TRIP_DETAIL`.

- [ ] **Step 1:** Hapus import `jadwalService, Jadwal`; ganti konstanta `JADWAL_STATUS_CLASS` dengan `TRIP_STATUS_CLASS` (isi persis sama dengan Task 6 tapi key `belum_mulai` menggantikan `terjadwal`). Tambah import `{ tripService, Trip } from '@/services/trip.service'`.

- [ ] **Step 2:** Ganti state & fetch jadwal:

```tsx
    // riwayat trip
    const [tripList, setTripList]       = useState<Trip[]>([])
    const [tripLoading, setTripLoading] = useState(false)
    const [tripTotal, setTripTotal]     = useState(0)

    const fetchTrip = useCallback(async () => {
        setTripLoading(true)
        try {
            const res = await tripService.list({ id_supir: id, limit: 50 })
            setTripList(res.data)
            setTripTotal(res.meta.total)
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setTripLoading(false)
        }
    }, [id])

    useEffect(() => { fetchTrip() }, [fetchTrip])
```

- [ ] **Step 3:** Sesuaikan statistik ringkasan: `const aktif = tripList.filter(t => t.status === 'berjalan').length`, `const selesai = tripList.filter(t => t.status === 'selesai').length`; kartu ringkasan pakai `tripTotal`/`tripLoading` dengan label pertama `'Total Trip'` (label lain tetap). Ganti seluruh Card "Riwayat Jadwal Perjalanan" menjadi "Riwayat Trip" dengan tabel kolom: Waktu Berangkat (`t.waktu_berangkat`), Rute (`t.rute`), Check-in (`t.waktu_checkin`), Check-out (`t.waktu_checkout`), Status (`TRIP_STATUS_CLASS[t.status]`, teks `t.status.replace('_', ' ')`), aksi eye → `router.push(ROUTES.TRIP_DETAIL(t.id_trip))`. Format tanggal & kelas styling meniru persis tabel lama (lihat kode tabel Task 6 — struktur identik).

- [ ] **Step 4: Verifikasi + stage.** tsc 0 error; eslint bersih; grep `jadwalService` di file = 0.

```bash
git add "src/app/(protected-pages)/supir/[id]/page.tsx"
```

---

### Task 8: Frontend — hapus seluruh UI Jadwal

**Files:**
- Delete: `src/app/(protected-pages)/jadwal/page.tsx`, `src/app/(protected-pages)/jadwal/[id]/page.tsx` (beserta folder `jadwal/`)
- Delete: `src/services/jadwal.service.ts`
- Modify: `src/constants/route.constant.ts` (hapus `JADWAL:` dan `JADWAL_DETAIL:`)
- Modify: `src/constants/api.constant.ts` (hapus blok `// Jadwal` berisi `JADWAL:` dan `JADWAL_DETAIL:` — baris 81-83; JANGAN sentuh blok `// Jadwal Shift`)
- Modify: `src/configs/navigation.ts` (hapus 2 baris `{ label: 'Jadwal', href: ROUTES.JADWAL },` di dispatcher & manager)
- Modify: `src/configs/routes.config/routes.config.ts` (hapus 2 baris literal `'/jadwal': ...` dan `'/jadwal/[id]': ...`)

- [ ] **Step 1:** Hapus file & folder `src/app/(protected-pages)/jadwal/` dan `src/services/jadwal.service.ts`.

- [ ] **Step 2:** Bersihkan 4 file konstanta/config sesuai daftar di atas.

- [ ] **Step 3: Verifikasi menyeluruh.** Dari root frontend:
  - `npx tsc --noEmit -p tsconfig.json` → 0 error.
  - Grep sisa referensi: `jadwalService`, `jadwal.service`, `ROUTES.JADWAL`, `JADWAL_DETAIL`, `API_ENDPOINTS.JADWAL` (kecuali `JADWAL_SHIFT`) → hasil HARUS kosong. Pemakaian `'/jadwal'` string apa pun yang tersisa = bug.
  - eslint file yang dimodifikasi bersih.

- [ ] **Step 4: Stage** (termasuk deletion — `git add` path terhapus mencatat penghapusan):

```bash
git add "src/app/(protected-pages)/jadwal" src/services/jadwal.service.ts src/constants/route.constant.ts src/constants/api.constant.ts src/configs/navigation.ts src/configs/routes.config/routes.config.ts
```

---

## Catatan Eksekusi

- Urutan wajib: Task 1 → 2 boleh paralel secara logika tapi kerjakan berurutan; Task 3 sebelum 4-7; Task 8 TERAKHIR (setelah 6 & 7 menghapus semua pemakai jadwalService).
- Ledger: `.superpowers/sdd/progress.md` (repo backend) — tambah section "Sederhanakan Alur Trip".
- Review package per task via `git diff --staged` (konvensi sesi: tidak pernah commit, baseline = staged vs HEAD lama; task frontend di repo frontend, task backend di repo backend).
- Setelah semua task: final review whole-plan, TANPA docker build (user build sendiri).
