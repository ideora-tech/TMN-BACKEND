# Modul Pemeliharaan Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Grup menu "Pemeliharaan" baru berisi Perawatan Armada (form pindah ke halaman terpisah + spare part per servis), Dokumen Armada (pindah grup saja), master data Jenis Perawatan, dan master data Spare Part dengan tracking stok.

**Architecture:** Backend Laravel Query Builder penuh (pola batch 2026-07-15/16: `DB::table()`, `RecordHelper`, `private const COLUMNS`, scoping `id_perusahaan`). 2 modul backend baru (JenisPerawatan mirror JenisKendaraan; Sparepart + mutasi stok), perluasan PerawatanArmada (FK jenis + snapshot teks pola `id_rute`/`rute`, line item sparepart dengan logika stok transaksional). Frontend Next.js 15: 3 halaman master jenis-perawatan (mirror jenis-bbm), 3 halaman sparepart, form perawatan jadi halaman terpisah dengan shared component.

**Tech Stack:** Laravel 11 + MySQL (SQLite in-memory utk test), Next.js 15 App Router + Ecme UI.

**Spec:** `docs/superpowers/specs/2026-07-17-modul-pemeliharaan-design.md`

## Global Constraints

- **JANGAN commit apa pun ke git** (kedua repo). Stage dengan `git add` path spesifik (bukan `-A`), biarkan di working tree. User commit manual.
- Repository backend WAJIB `DB::table()`, TIDAK BOLEH Eloquent Model baru, TIDAK BOLEH `SELECT *` (pakai `private const COLUMNS` eksplisit).
- Semua query scope ke `id_perusahaan` + `whereNull('dihapus_pada')`.
- Create/update/delete pakai `App\Support\RecordHelper::stampCreate/stampUpdate/stampDelete`.
- Modul master data pakai middleware `['api', 'auth:sanctum']` saja (TANPA `izin:` — konsisten JenisKendaraan).
- `sparepart.kode` TIDAK unique di level DB — validasi unik per `id_perusahaan` di app-level (Service), 422 kalau duplikat.
- Semua operasi stok dibungkus `DB::transaction()`; stok hasil akhir tidak boleh negatif → abort 422.
- Kolom teks `perawatan_armada.jenis_perawatan` = snapshot nama master; `id_jenis_perawatan` = sumber kebenaran (pola persis `jadwal_keberangkatan.rute`+`id_rute`).
- Frontend: error via `parseApiError()`, angka via `formatNum`/`formatRupiah`, TIDAK ADA `toLocaleString('id-ID')`, endpoint via `API_ENDPOINTS`, route via `ROUTES`.
- Backend test: `vendor/bin/phpunit` dari host. Frontend: `npx tsc --noEmit -p tsconfig.json` + `npx eslint <file>`.
- UUID menu HARUS verbatim: grup `m0000001-0000-4000-8000-000000000080`, Jenis Perawatan `...081`, Spare Part `...082`; menu existing Perawatan Armada `...028`, Dokumen Armada `...029`, Operasional `...020`.

---

### Task 1: Migration skema (5 file)

**Files:**
- Create: `database/migrations/2026_07_17_000001_create_jenis_perawatan_table.php`
- Create: `database/migrations/2026_07_17_000002_create_sparepart_table.php`
- Create: `database/migrations/2026_07_17_000003_create_sparepart_mutasi_table.php`
- Create: `database/migrations/2026_07_17_000004_create_perawatan_sparepart_table.php`
- Create: `database/migrations/2026_07_17_000005_add_id_jenis_perawatan_to_perawatan_armada.php`

**Interfaces:**
- Consumes: `App\Helpers\MigrationHelper::auditColumns($table)` (sudah ada).
- Produces: 4 tabel baru + 1 kolom baru — dipakai Task 2-4.

- [ ] **Step 1: Tulis 5 file migration**

`2026_07_17_000001_create_jenis_perawatan_table.php`:

```php
<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jenis_perawatan', function (Blueprint $table) {
            $table->char('id_jenis_perawatan', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('nama', 150);
            $table->text('keterangan')->nullable();
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jenis_perawatan');
    }
};
```

`2026_07_17_000002_create_sparepart_table.php`:

```php
<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sparepart', function (Blueprint $table) {
            $table->char('id_sparepart', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('kode', 50); // unik per perusahaan di app-level, BUKAN DB unique
            $table->string('nama', 150);
            $table->string('satuan', 30)->default('pcs');
            $table->decimal('harga_standar', 15, 2)->default(0);
            $table->integer('stok')->default(0);
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
            $table->index(['id_perusahaan', 'kode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sparepart');
    }
};
```

`2026_07_17_000003_create_sparepart_mutasi_table.php`:

```php
<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sparepart_mutasi', function (Blueprint $table) {
            $table->char('id_mutasi', 36)->primary();
            $table->char('id_sparepart', 36)->index();
            $table->enum('jenis', ['masuk', 'keluar', 'penyesuaian']);
            $table->integer('qty'); // masuk/keluar selalu positif; penyesuaian boleh negatif (delta)
            $table->decimal('harga', 15, 2)->nullable();
            $table->char('id_perawatan', 36)->nullable()->index();
            $table->text('keterangan')->nullable();
            $table->date('tanggal');
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sparepart_mutasi');
    }
};
```

`2026_07_17_000004_create_perawatan_sparepart_table.php`:

```php
<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perawatan_sparepart', function (Blueprint $table) {
            $table->char('id_perawatan_sparepart', 36)->primary();
            $table->char('id_perawatan', 36)->index();
            $table->char('id_sparepart', 36)->index();
            $table->string('nama_sparepart', 150); // snapshot nama saat dipakai
            $table->integer('qty');
            $table->decimal('harga', 15, 2)->default(0); // harga aktual per unit
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perawatan_sparepart');
    }
};
```

`2026_07_17_000005_add_id_jenis_perawatan_to_perawatan_armada.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perawatan_armada', function (Blueprint $table) {
            $table->char('id_jenis_perawatan', 36)->nullable()->after('id_armada');
        });
    }

    public function down(): void
    {
        Schema::table('perawatan_armada', function (Blueprint $table) {
            $table->dropColumn('id_jenis_perawatan');
        });
    }
};
```

- [ ] **Step 2: Verifikasi migration jalan bersih di SQLite test DB**

Run: `cd TMN-TRANSPORT-BACKEND && vendor/bin/phpunit tests/Feature/PerawatanArmadaTest.php`
Expected: semua PASS seperti sebelumnya (RefreshDatabase menjalankan SEMUA migration termasuk 5 file baru — kalau ada syntax/constraint error, test langsung gagal saat boot).

- [ ] **Step 3: Stage (JANGAN commit)**

```bash
git add database/migrations/2026_07_17_000001_create_jenis_perawatan_table.php database/migrations/2026_07_17_000002_create_sparepart_table.php database/migrations/2026_07_17_000003_create_sparepart_mutasi_table.php database/migrations/2026_07_17_000004_create_perawatan_sparepart_table.php database/migrations/2026_07_17_000005_add_id_jenis_perawatan_to_perawatan_armada.php
```

---

### Task 2: Modul backend `JenisPerawatan`

**Files:**
- Create: `app/Modules/JenisPerawatan/Contracts/JenisPerawatanRepositoryInterface.php`
- Create: `app/Modules/JenisPerawatan/JenisPerawatanRepository.php`
- Create: `app/Modules/JenisPerawatan/JenisPerawatanService.php`
- Create: `app/Modules/JenisPerawatan/JenisPerawatanController.php`
- Create: `app/Modules/JenisPerawatan/JenisPerawatanServiceProvider.php`
- Create: `app/Modules/JenisPerawatan/Requests/StoreJenisPerawatanRequest.php`
- Create: `app/Modules/JenisPerawatan/Requests/UpdateJenisPerawatanRequest.php`
- Create: `app/Modules/JenisPerawatan/Resources/JenisPerawatanResource.php`
- Modify: `bootstrap/providers.php` (daftarkan provider, urut alfabetis)
- Create: `tests/Feature/JenisPerawatanTest.php`

**Pola referensi:** modul ini mirror persis `app/Modules/JenisKendaraan/` (modul master-data Query Builder paling sederhana) — struktur file, style, dan konvensi identik, hanya nama entitas & field yang beda. Kode lengkap di bawah.

**Interfaces:**
- Produces: `Route::apiResource('jenis-perawatan')` → `GET/POST /api/v1/jenis-perawatan`, `GET/PUT/DELETE /api/v1/jenis-perawatan/{id}`. Response resource: `{id_jenis_perawatan, id_perusahaan, nama, keterangan, aktif(bool), dibuat_pada, diubah_pada}`. Dipakai Task 4 (`exists:jenis_perawatan`), Task 6-7 (frontend).

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/JenisPerawatanTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class JenisPerawatanTest extends TestCase
{
    use RefreshDatabase;

    private function makeJenis(string $nama = 'Ganti Oli', ?string $idPerusahaan = null): object
    {
        $id = (string) Str::uuid();
        DB::table('jenis_perawatan')->insert([
            'id_jenis_perawatan' => $id,
            'id_perusahaan'      => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nama'               => $nama,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return DB::table('jenis_perawatan')->where('id_jenis_perawatan', $id)->first();
    }

    public function test_create_jenis_perawatan_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/jenis-perawatan', [
            'nama'       => 'Tune Up Mesin',
            'keterangan' => 'Servis rutin 10.000 km',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.nama', 'Tune Up Mesin')
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('jenis_perawatan', [
            'nama'          => 'Tune Up Mesin',
            'id_perusahaan' => self::PERUSAHAAN_ID,
        ]);
    }

    public function test_list_scoped_ke_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeJenis('Milik Sendiri');

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $this->makeJenis('Milik Orang', $idLain);

        $res = $this->getJson('/api/v1/jenis-perawatan');

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Milik Sendiri', $res->json('data.0.nama'));
    }

    public function test_update_dan_show_jenis_perawatan(): void
    {
        $this->actingAsRole('ADMIN');
        $jenis = $this->makeJenis();

        $resUpdate = $this->putJson("/api/v1/jenis-perawatan/{$jenis->id_jenis_perawatan}", [
            'nama'  => 'Ganti Oli Mesin',
            'aktif' => false,
        ]);
        $resUpdate->assertStatus(200)
            ->assertJsonPath('data.nama', 'Ganti Oli Mesin')
            ->assertJsonPath('data.aktif', false);

        $resShow = $this->getJson("/api/v1/jenis-perawatan/{$jenis->id_jenis_perawatan}");
        $resShow->assertStatus(200)->assertJsonPath('data.nama', 'Ganti Oli Mesin');
    }

    public function test_delete_jenis_perawatan_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $jenis = $this->makeJenis();

        $res = $this->deleteJson("/api/v1/jenis-perawatan/{$jenis->id_jenis_perawatan}");
        $res->assertStatus(200);

        $this->assertSoftDeleted('jenis_perawatan', ['id_jenis_perawatan' => $jenis->id_jenis_perawatan]);
        $this->getJson("/api/v1/jenis-perawatan/{$jenis->id_jenis_perawatan}")->assertStatus(404);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal (route belum ada → 404)**

Run: `cd TMN-TRANSPORT-BACKEND && vendor/bin/phpunit tests/Feature/JenisPerawatanTest.php`
Expected: FAIL (404 di semua test — route belum terdaftar).

- [ ] **Step 3: Tulis semua file modul**

`app/Modules/JenisPerawatan/Contracts/JenisPerawatanRepositoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JenisPerawatan\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface JenisPerawatanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
}
```

`app/Modules/JenisPerawatan/JenisPerawatanRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JenisPerawatan;

use App\Modules\JenisPerawatan\Contracts\JenisPerawatanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class JenisPerawatanRepository implements JenisPerawatanRepositoryInterface
{
    private const COLUMNS = [
        'id_jenis_perawatan', 'id_perusahaan', 'nama', 'keterangan', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('jenis_perawatan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('jenis_perawatan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_jenis_perawatan', $id)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_jenis_perawatan');
        DB::table('jenis_perawatan')->insert($data);
        return $this->findById($data['id_jenis_perawatan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('jenis_perawatan')
            ->where('id_jenis_perawatan', $record->id_jenis_perawatan)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_jenis_perawatan);
    }

    public function delete(object $record): void
    {
        DB::table('jenis_perawatan')
            ->where('id_jenis_perawatan', $record->id_jenis_perawatan)
            ->update(RecordHelper::stampDelete());
    }
}
```

`app/Modules/JenisPerawatan/JenisPerawatanService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JenisPerawatan;

use App\Modules\JenisPerawatan\Contracts\JenisPerawatanRepositoryInterface;

class JenisPerawatanService
{
    public function __construct(private readonly JenisPerawatanRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit);

        return [
            'data' => $result->items(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    public function findOrFail(string $id): object
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Jenis perawatan tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): object
    {
        $record = $this->findOrFail($id);
        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
```

`app/Modules/JenisPerawatan/JenisPerawatanController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JenisPerawatan;

use App\Helpers\ApiResponse;
use App\Modules\JenisPerawatan\Requests\StoreJenisPerawatanRequest;
use App\Modules\JenisPerawatan\Requests\UpdateJenisPerawatanRequest;
use App\Modules\JenisPerawatan\Resources\JenisPerawatanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class JenisPerawatanController extends Controller
{
    public function __construct(private readonly JenisPerawatanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            JenisPerawatanResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new JenisPerawatanResource($this->service->findOrFail($id)));
    }

    public function store(StoreJenisPerawatanRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new JenisPerawatanResource($record), 'Jenis perawatan berhasil dibuat', 201);
    }

    public function update(UpdateJenisPerawatanRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(new JenisPerawatanResource($record), 'Jenis perawatan berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Jenis perawatan berhasil dihapus');
    }
}
```

`app/Modules/JenisPerawatan/JenisPerawatanServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JenisPerawatan;

use App\Modules\JenisPerawatan\Contracts\JenisPerawatanRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class JenisPerawatanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(JenisPerawatanRepositoryInterface::class, JenisPerawatanRepository::class);
        $this->app->bind(JenisPerawatanService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::apiResource('jenis-perawatan', JenisPerawatanController::class)
                    ->parameters(['jenis-perawatan' => 'id']);
            });
    }
}
```

`app/Modules/JenisPerawatan/Requests/StoreJenisPerawatanRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JenisPerawatan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreJenisPerawatanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'       => ['required', 'string', 'max:150'],
            'keterangan' => ['sometimes', 'nullable', 'string'],
            'aktif'      => ['sometimes', 'boolean'],
        ];
    }
}
```

`app/Modules/JenisPerawatan/Requests/UpdateJenisPerawatanRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JenisPerawatan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJenisPerawatanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'       => ['sometimes', 'string', 'max:150'],
            'keterangan' => ['sometimes', 'nullable', 'string'],
            'aktif'      => ['sometimes', 'boolean'],
        ];
    }
}
```

`app/Modules/JenisPerawatan/Resources/JenisPerawatanResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JenisPerawatan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class JenisPerawatanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_jenis_perawatan' => $this->id_jenis_perawatan,
            'id_perusahaan'      => $this->id_perusahaan,
            'nama'               => $this->nama,
            'keterangan'         => $this->keterangan,
            'aktif'              => (bool) $this->aktif,
            'dibuat_pada'        => $this->dibuat_pada,
            'diubah_pada'        => $this->diubah_pada,
        ];
    }
}
```

- [ ] **Step 4: Daftarkan provider**

Di `bootstrap/providers.php`, tambah baris berikut urut alfabetis (setelah `JenisKendaraanServiceProvider`):

```php
    App\Modules\JenisPerawatan\JenisPerawatanServiceProvider::class,
```

- [ ] **Step 5: Jalankan test, pastikan pass**

Run: `cd TMN-TRANSPORT-BACKEND && vendor/bin/phpunit tests/Feature/JenisPerawatanTest.php --testdox`
Expected: `OK (4 tests, ...)`.

- [ ] **Step 6: Full suite regresi**

Run: `cd TMN-TRANSPORT-BACKEND && vendor/bin/phpunit`
Expected: `OK` semua.

- [ ] **Step 7: Stage (JANGAN commit)**

```bash
git add app/Modules/JenisPerawatan bootstrap/providers.php tests/Feature/JenisPerawatanTest.php
```

---

### Task 3: Modul backend `Sparepart` (CRUD + stok + mutasi)

**Files:**
- Create: `app/Modules/Sparepart/Contracts/SparepartRepositoryInterface.php`
- Create: `app/Modules/Sparepart/SparepartRepository.php`
- Create: `app/Modules/Sparepart/SparepartService.php`
- Create: `app/Modules/Sparepart/SparepartController.php`
- Create: `app/Modules/Sparepart/SparepartServiceProvider.php`
- Create: `app/Modules/Sparepart/Requests/StoreSparepartRequest.php`
- Create: `app/Modules/Sparepart/Requests/UpdateSparepartRequest.php`
- Create: `app/Modules/Sparepart/Requests/StokSparepartRequest.php`
- Create: `app/Modules/Sparepart/Resources/SparepartResource.php`
- Create: `app/Modules/Sparepart/Resources/SparepartMutasiResource.php`
- Modify: `bootstrap/providers.php`
- Create: `tests/Feature/SparepartTest.php`

**Interfaces:**
- Produces: `apiResource('sparepart')` + `POST sparepart/{id}/stok` + `GET sparepart/{id}/mutasi`. Resource sparepart: `{id_sparepart, id_perusahaan, kode, nama, satuan, harga_standar(float), stok(int), aktif(bool), dibuat_pada, diubah_pada}`. Resource mutasi: `{id_mutasi, id_sparepart, jenis, qty(int), harga(float|null), id_perawatan, keterangan, tanggal, dibuat_pada}`. Dipakai Task 4 (tabel sparepart/mutasi dipakai lintas modul), Task 6/8 (frontend).
- Kode unik per perusahaan: 409 (konsisten `JenisKendaraanService::create` yang abort 409 utk kode duplikat). Stok negatif: 422.

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/SparepartTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SparepartTest extends TestCase
{
    use RefreshDatabase;

    private function makeSparepart(string $kode = 'SP-001', string $nama = 'Filter Oli', int $stok = 10, ?string $idPerusahaan = null): object
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart'  => $id,
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode'          => $kode,
            'nama'          => $nama,
            'satuan'        => 'pcs',
            'harga_standar' => 50000,
            'stok'          => $stok,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return DB::table('sparepart')->where('id_sparepart', $id)->first();
    }

    public function test_create_sparepart_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/sparepart', [
            'kode'          => 'SP-100',
            'nama'          => 'Kampas Rem',
            'satuan'        => 'set',
            'harga_standar' => 350000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.kode', 'SP-100')
            ->assertJsonPath('data.stok', 0)
            ->assertJsonPath('data.aktif', true);
    }

    public function test_kode_duplikat_per_perusahaan_ditolak_409_tapi_beda_perusahaan_boleh(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeSparepart('SP-001');

        $resDup = $this->postJson('/api/v1/sparepart', ['kode' => 'SP-001', 'nama' => 'Duplikat']);
        $resDup->assertStatus(409);

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $this->makeSparepart('SP-001', 'Punya Orang', 5, $idLain);
        // baris di atas insert langsung — membuktikan DB tidak punya unique global; validasi hanya app-level per perusahaan
        $this->assertSame(2, DB::table('sparepart')->where('kode', 'SP-001')->count());
    }

    public function test_tambah_stok_masuk_menambah_dan_mencatat_mutasi(): void
    {
        $this->actingAsRole('ADMIN');
        $sp = $this->makeSparepart('SP-001', 'Filter Oli', 10);

        $res = $this->postJson("/api/v1/sparepart/{$sp->id_sparepart}/stok", [
            'jenis'      => 'masuk',
            'qty'        => 5,
            'harga'      => 45000,
            'keterangan' => 'Pembelian rutin',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.stok', 15);

        $this->assertDatabaseHas('sparepart_mutasi', [
            'id_sparepart' => $sp->id_sparepart,
            'jenis'        => 'masuk',
            'qty'          => 5,
        ]);
    }

    public function test_penyesuaian_negatif_boleh_tapi_stok_minus_ditolak_422(): void
    {
        $this->actingAsRole('ADMIN');
        $sp = $this->makeSparepart('SP-001', 'Filter Oli', 10);

        $resOk = $this->postJson("/api/v1/sparepart/{$sp->id_sparepart}/stok", [
            'jenis' => 'penyesuaian', 'qty' => -3, 'keterangan' => 'Stok opname',
        ]);
        $resOk->assertStatus(200)->assertJsonPath('data.stok', 7);

        $resMinus = $this->postJson("/api/v1/sparepart/{$sp->id_sparepart}/stok", [
            'jenis' => 'penyesuaian', 'qty' => -100,
        ]);
        $resMinus->assertStatus(422);
        $this->assertSame(7, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));
    }

    public function test_masuk_qty_nol_atau_negatif_ditolak_422(): void
    {
        $this->actingAsRole('ADMIN');
        $sp = $this->makeSparepart();

        $this->postJson("/api/v1/sparepart/{$sp->id_sparepart}/stok", ['jenis' => 'masuk', 'qty' => 0])->assertStatus(422);
        $this->postJson("/api/v1/sparepart/{$sp->id_sparepart}/stok", ['jenis' => 'masuk', 'qty' => -2])->assertStatus(422);
    }

    public function test_riwayat_mutasi_terbaru_dulu(): void
    {
        $this->actingAsRole('ADMIN');
        $sp = $this->makeSparepart();

        $this->postJson("/api/v1/sparepart/{$sp->id_sparepart}/stok", ['jenis' => 'masuk', 'qty' => 5]);
        $this->travel(1)->seconds();
        $this->postJson("/api/v1/sparepart/{$sp->id_sparepart}/stok", ['jenis' => 'penyesuaian', 'qty' => -1]);

        $res = $this->getJson("/api/v1/sparepart/{$sp->id_sparepart}/mutasi");

        $res->assertStatus(200);
        $this->assertCount(2, $res->json('data'));
        $this->assertSame('penyesuaian', $res->json('data.0.jenis'));
    }

    public function test_list_scoped_dan_search(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeSparepart('SP-001', 'Filter Oli');
        $this->makeSparepart('SP-002', 'Kampas Rem');

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $this->makeSparepart('SP-003', 'Punya Orang', 5, $idLain);

        $resAll = $this->getJson('/api/v1/sparepart');
        $resAll->assertStatus(200);
        $this->assertCount(2, $resAll->json('data'));

        $resSearch = $this->getJson('/api/v1/sparepart?search=kampas');
        $this->assertCount(1, $resSearch->json('data'));
        $this->assertSame('SP-002', $resSearch->json('data.0.kode'));
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal (404)**

Run: `cd TMN-TRANSPORT-BACKEND && vendor/bin/phpunit tests/Feature/SparepartTest.php`
Expected: FAIL semua (route belum ada).

- [ ] **Step 3: Tulis semua file modul**

`app/Modules/Sparepart/Contracts/SparepartRepositoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface SparepartRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function findByIdForUpdate(string $id): ?object;
    public function findByKode(string $idPerusahaan, string $kode, ?string $excludeId = null): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
    public function setStok(string $id, int $stokBaru): void;
    public function insertMutasi(array $data): void;
    public function paginateMutasi(string $idSparepart, int $page, int $limit): LengthAwarePaginator;
}
```

`app/Modules/Sparepart/SparepartRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Sparepart;

use App\Modules\Sparepart\Contracts\SparepartRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SparepartRepository implements SparepartRepositoryInterface
{
    private const COLUMNS = [
        'id_sparepart', 'id_perusahaan', 'kode', 'nama', 'satuan', 'harga_standar', 'stok', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    private const MUTASI_COLUMNS = [
        'id_mutasi', 'id_sparepart', 'jenis', 'qty', 'harga', 'id_perawatan', 'keterangan', 'tanggal',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search): LengthAwarePaginator
    {
        return DB::table('sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('nama', 'like', "%{$search}%")
                   ->orWhere('kode', 'like', "%{$search}%");
            }))
            ->orderBy('nama')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('sparepart')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_sparepart', $id)
            ->first();
    }

    public function findByIdForUpdate(string $id): ?object
    {
        return DB::table('sparepart')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_sparepart', $id)
            ->lockForUpdate()
            ->first();
    }

    public function findByKode(string $idPerusahaan, string $kode, ?string $excludeId = null): ?object
    {
        return DB::table('sparepart')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kode', $kode)
            ->when($excludeId, fn ($q) => $q->where('id_sparepart', '!=', $excludeId))
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_sparepart');
        DB::table('sparepart')->insert($data);
        return $this->findById($data['id_sparepart']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('sparepart')
            ->where('id_sparepart', $record->id_sparepart)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_sparepart);
    }

    public function delete(object $record): void
    {
        DB::table('sparepart')
            ->where('id_sparepart', $record->id_sparepart)
            ->update(RecordHelper::stampDelete());
    }

    public function setStok(string $id, int $stokBaru): void
    {
        DB::table('sparepart')
            ->where('id_sparepart', $id)
            ->update(RecordHelper::stampUpdate(['stok' => $stokBaru]));
    }

    public function insertMutasi(array $data): void
    {
        DB::table('sparepart_mutasi')->insert(RecordHelper::stampCreate($data, 'id_mutasi'));
    }

    public function paginateMutasi(string $idSparepart, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('sparepart_mutasi')
            ->whereNull('dihapus_pada')
            ->where('id_sparepart', $idSparepart)
            ->orderByDesc('dibuat_pada')
            ->orderByDesc('id_mutasi')
            ->paginate($limit, self::MUTASI_COLUMNS, 'page', $page);
    }
}
```

`app/Modules/Sparepart/SparepartService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Sparepart;

use App\Modules\Sparepart\Contracts\SparepartRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SparepartService
{
    public function __construct(private readonly SparepartRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null): array
    {
        return $this->toPagedArray($this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search));
    }

    public function listMutasi(string $idSparepart, int $page = 1, int $limit = 10): array
    {
        $this->findOrFail($idSparepart);
        return $this->toPagedArray($this->repo->paginateMutasi($idSparepart, $page, $limit));
    }

    private function toPagedArray(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => [
                'page'       => $paginator->currentPage(),
                'limit'      => $paginator->perPage(),
                'total'      => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
            ],
        ];
    }

    public function findOrFail(string $id): object
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Spare part tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        if ($this->repo->findByKode($data['id_perusahaan'], $data['kode'])) {
            abort(409, 'Kode spare part sudah digunakan');
        }
        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id);

        if (isset($data['kode']) && $data['kode'] !== $record->kode) {
            if ($this->repo->findByKode($idPerusahaan, $data['kode'], $id)) {
                abort(409, 'Kode spare part sudah digunakan');
            }
        }

        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }

    /**
     * jenis 'masuk'  : qty wajib > 0 (divalidasi Request), menambah stok.
     * jenis 'penyesuaian' : qty delta bertanda (koreksi opname), boleh negatif.
     * Stok hasil akhir tidak boleh negatif → 422.
     */
    public function mutasiStok(string $id, array $data): object
    {
        return DB::transaction(function () use ($id, $data) {
            $record = $this->repo->findByIdForUpdate($id);
            if ($record === null) {
                abort(404, 'Spare part tidak ditemukan');
            }

            $stokBaru = (int) $record->stok + (int) $data['qty'];
            if ($stokBaru < 0) {
                abort(422, "Stok tidak boleh negatif (stok saat ini {$record->stok}, perubahan {$data['qty']})");
            }

            $this->repo->setStok($id, $stokBaru);
            $this->repo->insertMutasi([
                'id_sparepart' => $id,
                'jenis'        => $data['jenis'],
                'qty'          => (int) $data['qty'],
                'harga'        => $data['harga'] ?? null,
                'keterangan'   => $data['keterangan'] ?? null,
                'tanggal'      => now()->toDateString(),
            ]);

            return $this->findOrFail($id);
        });
    }
}
```

`app/Modules/Sparepart/SparepartController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Sparepart;

use App\Helpers\ApiResponse;
use App\Modules\Sparepart\Requests\StokSparepartRequest;
use App\Modules\Sparepart\Requests\StoreSparepartRequest;
use App\Modules\Sparepart\Requests\UpdateSparepartRequest;
use App\Modules\Sparepart\Resources\SparepartMutasiResource;
use App\Modules\Sparepart\Resources\SparepartResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SparepartController extends Controller
{
    public function __construct(private readonly SparepartService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('search')
        );

        return ApiResponse::paginated(
            SparepartResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new SparepartResource($this->service->findOrFail($id)));
    }

    public function store(StoreSparepartRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new SparepartResource($record), 'Spare part berhasil dibuat', 201);
    }

    public function update(UpdateSparepartRequest $request, string $id): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;
        $record = $this->service->update($id, $request->validated(), $idPerusahaan);
        return ApiResponse::success(new SparepartResource($record), 'Spare part berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Spare part berhasil dihapus');
    }

    public function mutasiStok(StokSparepartRequest $request, string $id): JsonResponse
    {
        $record = $this->service->mutasiStok($id, $request->validated());
        return ApiResponse::success(new SparepartResource($record), 'Stok berhasil diperbarui');
    }

    public function listMutasi(Request $request, string $id): JsonResponse
    {
        $result = $this->service->listMutasi(
            $id,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            SparepartMutasiResource::collection($result['data']),
            $result['meta']
        );
    }
}
```

`app/Modules/Sparepart/SparepartServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Sparepart;

use App\Modules\Sparepart\Contracts\SparepartRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SparepartServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SparepartRepositoryInterface::class, SparepartRepository::class);
        $this->app->bind(SparepartService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::post('sparepart/{id}/stok', [SparepartController::class, 'mutasiStok']);
                Route::get('sparepart/{id}/mutasi', [SparepartController::class, 'listMutasi']);
                Route::apiResource('sparepart', SparepartController::class)
                    ->parameters(['sparepart' => 'id']);
            });
    }
}
```

`app/Modules/Sparepart/Requests/StoreSparepartRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSparepartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode'          => ['required', 'string', 'max:50'],
            'nama'          => ['required', 'string', 'max:150'],
            'satuan'        => ['sometimes', 'string', 'max:30'],
            'harga_standar' => ['sometimes', 'numeric', 'min:0'],
            'aktif'         => ['sometimes', 'boolean'],
        ];
    }
}
```

`app/Modules/Sparepart/Requests/UpdateSparepartRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSparepartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode'          => ['sometimes', 'string', 'max:50'],
            'nama'          => ['sometimes', 'string', 'max:150'],
            'satuan'        => ['sometimes', 'string', 'max:30'],
            'harga_standar' => ['sometimes', 'numeric', 'min:0'],
            'aktif'         => ['sometimes', 'boolean'],
        ];
    }
}
```

`app/Modules/Sparepart/Requests/StokSparepartRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StokSparepartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'jenis'      => ['required', 'in:masuk,penyesuaian'],
            'qty'        => ['required', 'integer', 'not_in:0', 'required_if:jenis,masuk'],
            'harga'      => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'keterangan' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($this->input('jenis') === 'masuk' && (int) $this->input('qty') <= 0) {
                $v->errors()->add('qty', 'Qty barang masuk harus lebih dari 0');
            }
        });
    }
}
```

`app/Modules/Sparepart/Resources/SparepartResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SparepartResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_sparepart'  => $this->id_sparepart,
            'id_perusahaan' => $this->id_perusahaan,
            'kode'          => $this->kode,
            'nama'          => $this->nama,
            'satuan'        => $this->satuan,
            'harga_standar' => (float) $this->harga_standar,
            'stok'          => (int) $this->stok,
            'aktif'         => (bool) $this->aktif,
            'dibuat_pada'   => $this->dibuat_pada,
            'diubah_pada'   => $this->diubah_pada,
        ];
    }
}
```

`app/Modules/Sparepart/Resources/SparepartMutasiResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SparepartMutasiResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_mutasi'    => $this->id_mutasi,
            'id_sparepart' => $this->id_sparepart,
            'jenis'        => $this->jenis,
            'qty'          => (int) $this->qty,
            'harga'        => $this->harga !== null ? (float) $this->harga : null,
            'id_perawatan' => $this->id_perawatan,
            'keterangan'   => $this->keterangan,
            'tanggal'      => $this->tanggal,
            'dibuat_pada'  => $this->dibuat_pada,
        ];
    }
}
```

- [ ] **Step 4: Daftarkan provider di `bootstrap/providers.php`** (urut alfabetis):

```php
    App\Modules\Sparepart\SparepartServiceProvider::class,
```

- [ ] **Step 5: Jalankan test, pastikan pass**

Run: `cd TMN-TRANSPORT-BACKEND && vendor/bin/phpunit tests/Feature/SparepartTest.php --testdox`
Expected: `OK (7 tests, ...)`.

- [ ] **Step 6: Full suite regresi**

Run: `cd TMN-TRANSPORT-BACKEND && vendor/bin/phpunit`
Expected: `OK` semua.

- [ ] **Step 7: Stage (JANGAN commit)**

```bash
git add app/Modules/Sparepart bootstrap/providers.php tests/Feature/SparepartTest.php
```

---

### Task 4: Perluasan `PerawatanArmada` — FK jenis + snapshot, line item sparepart + logika stok

**Files:**
- Modify: `app/Modules/PerawatanArmada/Contracts/PerawatanArmadaRepositoryInterface.php`
- Modify: `app/Modules/PerawatanArmada/PerawatanArmadaRepository.php`
- Modify: `app/Modules/PerawatanArmada/PerawatanArmadaService.php`
- Modify: `app/Modules/PerawatanArmada/Requests/StorePerawatanArmadaRequest.php`
- Modify: `app/Modules/PerawatanArmada/Requests/UpdatePerawatanArmadaRequest.php`
- Modify: `app/Modules/PerawatanArmada/Resources/PerawatanArmadaResource.php`
- Create: `tests/Feature/PerawatanSparepartTest.php`

**PENTING:** Controller TIDAK berubah (signature `service->create($idArmada, $request->validated())` dll sudah pas). Modul ini baru saja dikonversi ke Query Builder (batch 2026-07-16) — jangan sentuh struktur konversi itu, hanya TAMBAH.

**Interfaces:**
- Consumes: tabel `jenis_perawatan`, `sparepart`, `sparepart_mutasi`, `perawatan_sparepart` (Task 1), kolom `perawatan_armada.id_jenis_perawatan` (Task 1).
- Produces: payload store/update menerima `id_jenis_perawatan` (nullable) + `sparepart: [{id_sparepart, qty, harga}]`; response `show`/`store`/`update` punya `id_jenis_perawatan` + array `sparepart` (`{id_perawatan_sparepart, id_sparepart, nama_sparepart, qty, harga, subtotal}`); list paginated TIDAK memuat array sparepart (ringkas). Dipakai Task 6/9 (frontend).

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/PerawatanSparepartTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerawatanSparepartTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . random_int(1000, 9999) . ' PS',
            'merk'          => 'Hino',
        ]);
    }

    private function makeSparepart(string $nama = 'Filter Oli', int $stok = 10, float $harga = 50000): object
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart'  => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode'          => 'SP-' . Str::random(6),
            'nama'          => $nama,
            'satuan'        => 'pcs',
            'harga_standar' => $harga,
            'stok'          => $stok,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return DB::table('sparepart')->where('id_sparepart', $id)->first();
    }

    private function makeJenis(string $nama = 'Servis Rutin'): object
    {
        $id = (string) Str::uuid();
        DB::table('jenis_perawatan')->insert([
            'id_jenis_perawatan' => $id,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'nama'               => $nama,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return DB::table('jenis_perawatan')->where('id_jenis_perawatan', $id)->first();
    }

    public function test_create_servis_dengan_sparepart_mengurangi_stok_dan_mencatat_mutasi(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();
        $jenis  = $this->makeJenis('Servis 10.000 km');
        $spA    = $this->makeSparepart('Filter Oli', 10);
        $spB    = $this->makeSparepart('Busi', 20, 25000);

        $res = $this->postJson("/api/v1/armada/{$armada->id_armada}/perawatan", [
            'tanggal'            => '2026-07-17',
            'id_jenis_perawatan' => $jenis->id_jenis_perawatan,
            'biaya'              => 500000,
            'sparepart'          => [
                ['id_sparepart' => $spA->id_sparepart, 'qty' => 2, 'harga' => 55000],
                ['id_sparepart' => $spB->id_sparepart, 'qty' => 4, 'harga' => 25000],
            ],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.jenis_perawatan', 'Servis 10.000 km')
            ->assertJsonPath('data.id_jenis_perawatan', $jenis->id_jenis_perawatan)
            ->assertJsonCount(2, 'data.sparepart');

        // jangan bergantung urutan array (dibuat_pada bisa tie di detik yang sama)
        $items = collect($res->json('data.sparepart'))->keyBy('nama_sparepart');
        $this->assertSame(2, $items->get('Filter Oli')['qty']);
        $this->assertSame(4, $items->get('Busi')['qty']);

        $this->assertSame(8,  (int) DB::table('sparepart')->where('id_sparepart', $spA->id_sparepart)->value('stok'));
        $this->assertSame(16, (int) DB::table('sparepart')->where('id_sparepart', $spB->id_sparepart)->value('stok'));

        $idPerawatan = $res->json('data.id_perawatan');
        $this->assertSame(2, DB::table('sparepart_mutasi')->where('id_perawatan', $idPerawatan)->where('jenis', 'keluar')->count());
    }

    public function test_create_stok_tidak_cukup_ditolak_422_dan_rollback_total(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();
        $sp     = $this->makeSparepart('Filter Oli', 1);

        $res = $this->postJson("/api/v1/armada/{$armada->id_armada}/perawatan", [
            'tanggal'         => '2026-07-17',
            'jenis_perawatan' => 'Ganti Filter',
            'sparepart'       => [
                ['id_sparepart' => $sp->id_sparepart, 'qty' => 5, 'harga' => 55000],
            ],
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('tidak cukup', (string) $res->json('message'));
        $this->assertSame(1, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));
        $this->assertSame(0, DB::table('perawatan_armada')->where('id_armada', $armada->id_armada)->count());
        $this->assertSame(0, DB::table('sparepart_mutasi')->count());
    }

    public function test_update_item_menghitung_delta_stok_dua_arah(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();
        $spA = $this->makeSparepart('Filter Oli', 10);
        $spB = $this->makeSparepart('Busi', 20, 25000);

        $create = $this->postJson("/api/v1/armada/{$armada->id_armada}/perawatan", [
            'tanggal' => '2026-07-17', 'jenis_perawatan' => 'Servis',
            'sparepart' => [
                ['id_sparepart' => $spA->id_sparepart, 'qty' => 2, 'harga' => 55000],
                ['id_sparepart' => $spB->id_sparepart, 'qty' => 4, 'harga' => 25000],
            ],
        ]);
        $idPerawatan = $create->json('data.id_perawatan');
        // stok kini: A=8, B=16

        $res = $this->putJson("/api/v1/armada/{$armada->id_armada}/perawatan/{$idPerawatan}", [
            'sparepart' => [
                ['id_sparepart' => $spA->id_sparepart, 'qty' => 5, 'harga' => 55000], // +3 → keluar 3
                // B dihapus dari daftar → delta -4 → masuk 4
            ],
        ]);

        $res->assertStatus(200)->assertJsonCount(1, 'data.sparepart');
        $this->assertSame(5,  (int) DB::table('sparepart')->where('id_sparepart', $spA->id_sparepart)->value('stok'));
        $this->assertSame(20, (int) DB::table('sparepart')->where('id_sparepart', $spB->id_sparepart)->value('stok'));

        $this->assertDatabaseHas('sparepart_mutasi', ['id_sparepart' => $spA->id_sparepart, 'jenis' => 'keluar', 'qty' => 3, 'keterangan' => 'Perubahan item servis']);
        $this->assertDatabaseHas('sparepart_mutasi', ['id_sparepart' => $spB->id_sparepart, 'jenis' => 'masuk', 'qty' => 4, 'keterangan' => 'Perubahan item servis']);
        // baris lama di-soft-delete, baris aktif tinggal 1
        $this->assertSame(1, DB::table('perawatan_sparepart')->where('id_perawatan', $idPerawatan)->whereNull('dihapus_pada')->count());
    }

    public function test_delete_servis_mengembalikan_stok(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();
        $sp = $this->makeSparepart('Filter Oli', 10);

        $create = $this->postJson("/api/v1/armada/{$armada->id_armada}/perawatan", [
            'tanggal' => '2026-07-17', 'jenis_perawatan' => 'Servis',
            'sparepart' => [['id_sparepart' => $sp->id_sparepart, 'qty' => 3, 'harga' => 50000]],
        ]);
        $idPerawatan = $create->json('data.id_perawatan');
        $this->assertSame(7, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));

        $this->deleteJson("/api/v1/armada/{$armada->id_armada}/perawatan/{$idPerawatan}")->assertStatus(200);

        $this->assertSame(10, (int) DB::table('sparepart')->where('id_sparepart', $sp->id_sparepart)->value('stok'));
        $this->assertDatabaseHas('sparepart_mutasi', ['id_perawatan' => $idPerawatan, 'jenis' => 'masuk', 'qty' => 3, 'keterangan' => 'Pembatalan servis']);
        $this->assertSoftDeleted('perawatan_armada', ['id_perawatan' => $idPerawatan]);
    }

    public function test_update_id_jenis_perawatan_menyinkronkan_snapshot_teks(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();
        $jenisBaru = $this->makeJenis('Overhaul Mesin');

        $create = $this->postJson("/api/v1/armada/{$armada->id_armada}/perawatan", [
            'tanggal' => '2026-07-17', 'jenis_perawatan' => 'Teks Manual Lama',
        ]);
        $idPerawatan = $create->json('data.id_perawatan');

        $res = $this->putJson("/api/v1/armada/{$armada->id_armada}/perawatan/{$idPerawatan}", [
            'id_jenis_perawatan' => $jenisBaru->id_jenis_perawatan,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.id_jenis_perawatan', $jenisBaru->id_jenis_perawatan)
            ->assertJsonPath('data.jenis_perawatan', 'Overhaul Mesin');
    }

    public function test_create_tanpa_sparepart_dan_teks_manual_tetap_jalan_regresi(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();

        $res = $this->postJson("/api/v1/armada/{$armada->id_armada}/perawatan", [
            'tanggal'         => '2026-07-17',
            'jenis_perawatan' => 'Cuci Kendaraan',
            'biaya'           => 100000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.jenis_perawatan', 'Cuci Kendaraan')
            ->assertJsonPath('data.id_jenis_perawatan', null);
        $this->assertSame([], $res->json('data.sparepart'));
    }

    public function test_tanpa_jenis_sama_sekali_ditolak_validasi(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();

        $res = $this->postJson("/api/v1/armada/{$armada->id_armada}/perawatan", [
            'tanggal' => '2026-07-17',
        ]);

        $res->assertStatus(422);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `cd TMN-TRANSPORT-BACKEND && vendor/bin/phpunit tests/Feature/PerawatanSparepartTest.php`
Expected: FAIL (field/perilaku baru belum ada; test regresi teks manual mungkin pass — itu OK).

- [ ] **Step 3: Update Requests**

`StorePerawatanArmadaRequest::rules()` — ganti seluruh return jadi:

```php
        return [
            'tanggal'                  => ['required', 'date'],
            'id_jenis_perawatan'       => ['sometimes', 'nullable', 'string', 'exists:jenis_perawatan,id_jenis_perawatan'],
            'jenis_perawatan'          => ['required_without:id_jenis_perawatan', 'string', 'max:150'],
            'biaya'                    => ['sometimes', 'numeric', 'min:0'],
            'km_odometer'              => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status'                   => ['sometimes', 'in:terjadwal,dalam_proses,selesai'],
            'jadwal_servis_berikutnya' => ['sometimes', 'nullable', 'date'],
            'keterangan'               => ['sometimes', 'nullable', 'string'],
            'sparepart'                => ['sometimes', 'array'],
            'sparepart.*.id_sparepart' => ['required', 'string', 'exists:sparepart,id_sparepart'],
            'sparepart.*.qty'          => ['required', 'integer', 'min:1'],
            'sparepart.*.harga'        => ['required', 'numeric', 'min:0'],
        ];
```

`UpdatePerawatanArmadaRequest::rules()` — ganti seluruh return jadi:

```php
        return [
            'tanggal'                  => ['sometimes', 'date'],
            'id_jenis_perawatan'       => ['sometimes', 'nullable', 'string', 'exists:jenis_perawatan,id_jenis_perawatan'],
            'jenis_perawatan'          => ['sometimes', 'string', 'max:150'],
            'biaya'                    => ['sometimes', 'numeric', 'min:0'],
            'km_odometer'              => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status'                   => ['sometimes', 'in:terjadwal,dalam_proses,selesai'],
            'jadwal_servis_berikutnya' => ['sometimes', 'nullable', 'date'],
            'keterangan'               => ['sometimes', 'nullable', 'string'],
            'sparepart'                => ['sometimes', 'array'],
            'sparepart.*.id_sparepart' => ['required', 'string', 'exists:sparepart,id_sparepart'],
            'sparepart.*.qty'          => ['required', 'integer', 'min:1'],
            'sparepart.*.harga'        => ['required', 'numeric', 'min:0'],
        ];
```

- [ ] **Step 4: Update Contract — tambahkan method baru di interface**

Tambah di `PerawatanArmadaRepositoryInterface` (setelah `delete`):

```php
    public function getActiveLines(string $idPerawatan): array;
    public function insertLine(array $data): void;
    public function softDeleteLines(string $idPerawatan): void;
    public function getSparepartForUpdate(string $idSparepart): ?object;
    public function setSparepartStok(string $idSparepart, int $stokBaru): void;
    public function insertSparepartMutasi(array $data): void;
    public function getJenisPerawatanNama(string $idJenisPerawatan): ?string;
```

- [ ] **Step 5: Update Repository**

Di `PerawatanArmadaRepository`: (a) tambah `'perawatan_armada.id_jenis_perawatan',` ke dalam `private const COLUMNS` (setelah `perawatan_armada.id_armada`); (b) tambah const + method berikut di akhir class:

```php
    private const LINE_COLUMNS = [
        'id_perawatan_sparepart', 'id_perawatan', 'id_sparepart', 'nama_sparepart', 'qty', 'harga',
    ];

    public function getActiveLines(string $idPerawatan): array
    {
        return DB::table('perawatan_sparepart')
            ->select(self::LINE_COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perawatan', $idPerawatan)
            ->orderBy('dibuat_pada')
            ->get()
            ->all();
    }

    public function insertLine(array $data): void
    {
        DB::table('perawatan_sparepart')->insert(RecordHelper::stampCreate($data, 'id_perawatan_sparepart'));
    }

    public function softDeleteLines(string $idPerawatan): void
    {
        DB::table('perawatan_sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_perawatan', $idPerawatan)
            ->update(RecordHelper::stampDelete());
    }

    public function getSparepartForUpdate(string $idSparepart): ?object
    {
        return DB::table('sparepart')
            ->select(['id_sparepart', 'nama', 'stok'])
            ->whereNull('dihapus_pada')
            ->where('id_sparepart', $idSparepart)
            ->lockForUpdate()
            ->first();
    }

    public function setSparepartStok(string $idSparepart, int $stokBaru): void
    {
        DB::table('sparepart')
            ->where('id_sparepart', $idSparepart)
            ->update(RecordHelper::stampUpdate(['stok' => $stokBaru]));
    }

    public function insertSparepartMutasi(array $data): void
    {
        DB::table('sparepart_mutasi')->insert(RecordHelper::stampCreate($data, 'id_mutasi'));
    }

    public function getJenisPerawatanNama(string $idJenisPerawatan): ?string
    {
        $nama = DB::table('jenis_perawatan')
            ->whereNull('dihapus_pada')
            ->where('id_jenis_perawatan', $idJenisPerawatan)
            ->value('nama');

        return $nama !== null ? (string) $nama : null;
    }
```

Tambahkan import `use App\Support\RecordHelper;` kalau belum ada (sudah ada dari konversi sebelumnya).

- [ ] **Step 6: Update Service — snapshot jenis + logika stok transaksional**

Ganti seluruh isi `PerawatanArmadaService.php` dengan:

```php
<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada;

use App\Modules\PerawatanArmada\Contracts\PerawatanArmadaRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PerawatanArmadaService
{
    public function __construct(private readonly PerawatanArmadaRepositoryInterface $repo) {}

    public function listByArmada(string $idArmada, int $page = 1, int $limit = 10): array
    {
        return $this->toPagedArray($this->repo->paginateByArmada($idArmada, $page, $limit));
    }

    public function listByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idArmada, ?string $status): array
    {
        return $this->toPagedArray($this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $idArmada, $status));
    }

    private function toPagedArray(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => [
                'page'       => $paginator->currentPage(),
                'limit'      => $paginator->perPage(),
                'total'      => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
            ],
        ];
    }

    public function findOrFail(string $id): object
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Perawatan armada tidak ditemukan');
        }

        $record->sparepart = array_map(fn ($line) => [
            'id_perawatan_sparepart' => $line->id_perawatan_sparepart,
            'id_sparepart'           => $line->id_sparepart,
            'nama_sparepart'         => $line->nama_sparepart,
            'qty'                    => (int) $line->qty,
            'harga'                  => (float) $line->harga,
            'subtotal'               => (int) $line->qty * (float) $line->harga,
        ], $this->repo->getActiveLines($id));

        return $record;
    }

    public function create(string $idArmada, array $data): object
    {
        $items = $data['sparepart'] ?? [];
        unset($data['sparepart']);
        $data = $this->applyJenisSnapshot($data);

        return DB::transaction(function () use ($idArmada, $data, $items) {
            $record = $this->repo->create(array_merge($data, ['id_armada' => $idArmada]));
            $this->keluarkanStokUntukItems($record->id_perawatan, $items);
            return $this->findOrFail($record->id_perawatan);
        });
    }

    public function update(string $id, array $data): object
    {
        $record = $this->findOrFail($id);
        $adaItems = array_key_exists('sparepart', $data);
        $items = $data['sparepart'] ?? [];
        unset($data['sparepart']);
        $data = $this->applyJenisSnapshot($data);

        return DB::transaction(function () use ($record, $data, $items, $adaItems) {
            $this->repo->update($record, $data);
            if ($adaItems) {
                $this->gantiItemsDenganDelta($record->id_perawatan, $items);
            }
            return $this->findOrFail($record->id_perawatan);
        });
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);

        DB::transaction(function () use ($record) {
            foreach ($this->repo->getActiveLines($record->id_perawatan) as $line) {
                $sp = $this->repo->getSparepartForUpdate($line->id_sparepart);
                if ($sp !== null) {
                    $this->repo->setSparepartStok($sp->id_sparepart, (int) $sp->stok + (int) $line->qty);
                    $this->repo->insertSparepartMutasi([
                        'id_sparepart' => $sp->id_sparepart,
                        'jenis'        => 'masuk',
                        'qty'          => (int) $line->qty,
                        'id_perawatan' => $record->id_perawatan,
                        'keterangan'   => 'Pembatalan servis',
                        'tanggal'      => now()->toDateString(),
                    ]);
                }
            }
            $this->repo->softDeleteLines($record->id_perawatan);
            $this->repo->delete($record);
        });
    }

    /**
     * id_jenis_perawatan = sumber kebenaran; kolom teks jenis_perawatan di-sync
     * sebagai snapshot nama master (pola sama dgn jadwal_keberangkatan.rute + id_rute).
     * Teks manual tetap diizinkan kalau id tidak dikirim (required_without di Request).
     */
    private function applyJenisSnapshot(array $data): array
    {
        if (!empty($data['id_jenis_perawatan'])) {
            $nama = $this->repo->getJenisPerawatanNama($data['id_jenis_perawatan']);
            if ($nama !== null) {
                $data['jenis_perawatan'] = $nama;
            }
        }
        return $data;
    }

    /** Create path: kunci baris sparepart, validasi stok, insert line + mutasi keluar. */
    private function keluarkanStokUntukItems(string $idPerawatan, array $items): void
    {
        foreach ($this->totalPerSparepart($items) as $idSparepart => $agg) {
            $sp = $this->repo->getSparepartForUpdate($idSparepart);
            if ($sp === null) {
                abort(422, 'Spare part tidak ditemukan');
            }
            if ((int) $sp->stok < $agg['qty']) {
                abort(422, "Stok {$sp->nama} tidak cukup (tersisa {$sp->stok}, diminta {$agg['qty']})");
            }

            $this->repo->setSparepartStok($idSparepart, (int) $sp->stok - $agg['qty']);
            $this->repo->insertLine([
                'id_perawatan'   => $idPerawatan,
                'id_sparepart'   => $idSparepart,
                'nama_sparepart' => $sp->nama,
                'qty'            => $agg['qty'],
                'harga'          => $agg['harga'],
            ]);
            $this->repo->insertSparepartMutasi([
                'id_sparepart' => $idSparepart,
                'jenis'        => 'keluar',
                'qty'          => $agg['qty'],
                'harga'        => $agg['harga'],
                'id_perawatan' => $idPerawatan,
                'keterangan'   => 'Pemakaian servis',
                'tanggal'      => now()->toDateString(),
            ]);
        }
    }

    /** Update path: hitung delta per sparepart vs lines aktif lama, koreksi stok + mutasi, replace lines. */
    private function gantiItemsDenganDelta(string $idPerawatan, array $items): void
    {
        $lama = [];
        foreach ($this->repo->getActiveLines($idPerawatan) as $line) {
            $lama[$line->id_sparepart] = ($lama[$line->id_sparepart] ?? 0) + (int) $line->qty;
        }

        $baru = $this->totalPerSparepart($items);
        $semuaId = array_unique(array_merge(array_keys($lama), array_keys($baru)));

        $namaMap = [];
        foreach ($semuaId as $idSparepart) {
            $qtyLama = $lama[$idSparepart] ?? 0;
            $qtyBaru = $baru[$idSparepart]['qty'] ?? 0;
            $delta = $qtyBaru - $qtyLama;

            $sp = $this->repo->getSparepartForUpdate($idSparepart);
            if ($sp === null) {
                abort(422, 'Spare part tidak ditemukan');
            }
            $namaMap[$idSparepart] = $sp->nama;

            if ($delta === 0) {
                continue;
            }
            if ($delta > 0 && (int) $sp->stok < $delta) {
                abort(422, "Stok {$sp->nama} tidak cukup (tersisa {$sp->stok}, diminta tambahan {$delta})");
            }

            $this->repo->setSparepartStok($idSparepart, (int) $sp->stok - $delta);
            $this->repo->insertSparepartMutasi([
                'id_sparepart' => $idSparepart,
                'jenis'        => $delta > 0 ? 'keluar' : 'masuk',
                'qty'          => abs($delta),
                'id_perawatan' => $idPerawatan,
                'keterangan'   => 'Perubahan item servis',
                'tanggal'      => now()->toDateString(),
            ]);
        }

        $this->repo->softDeleteLines($idPerawatan);
        foreach ($baru as $idSparepart => $agg) {
            $this->repo->insertLine([
                'id_perawatan'   => $idPerawatan,
                'id_sparepart'   => $idSparepart,
                'nama_sparepart' => $namaMap[$idSparepart],
                'qty'            => $agg['qty'],
                'harga'          => $agg['harga'],
            ]);
        }
    }

    /** Gabungkan item duplikat (id_sparepart sama) — qty dijumlah, harga pakai yang terakhir. */
    private function totalPerSparepart(array $items): array
    {
        $agg = [];
        foreach ($items as $item) {
            $id = $item['id_sparepart'];
            $agg[$id] = [
                'qty'   => ($agg[$id]['qty'] ?? 0) + (int) $item['qty'],
                'harga' => (float) $item['harga'],
            ];
        }
        return $agg;
    }
}
```

- [ ] **Step 7: Update Resource**

Di `PerawatanArmadaResource::toArray()`, tambah 2 baris setelah `'id_armada'`:

```php
            'id_jenis_perawatan'       => $this->id_jenis_perawatan ?? null,
```

dan sebelum `'dibuat_pada'`:

```php
            'sparepart'                => $this->sparepart ?? [],
```

(Catatan: `$this->sparepart` adalah properti biasa yang ditempel Service ke stdClass row — AMAN, bukan Eloquent model, tidak ada dirty-tracking.)

- [ ] **Step 8: Jalankan test, pastikan pass**

Run: `cd TMN-TRANSPORT-BACKEND && vendor/bin/phpunit tests/Feature/PerawatanSparepartTest.php --testdox`
Expected: `OK (7 tests, ...)`.

- [ ] **Step 9: Full suite regresi (termasuk PerawatanArmadaTest lama)**

Run: `cd TMN-TRANSPORT-BACKEND && vendor/bin/phpunit`
Expected: `OK` semua — khususnya `PerawatanArmadaTest` 5 test lama tetap hijau.

- [ ] **Step 10: Stage (JANGAN commit)**

```bash
git add app/Modules/PerawatanArmada tests/Feature/PerawatanSparepartTest.php
```

---

### Task 5: Migration menu — grup "Pemeliharaan"

**Files:**
- Create: `database/migrations/2026_07_17_000006_seed_menu_grup_pemeliharaan.php`

**Interfaces:**
- Consumes: menu existing `...028` (Perawatan Armada), `...029` (Dokumen Armada), `...020` (Operasional).
- Produces: grup `...080` + menu `...081` (/jenis-perawatan) + `...082` (/sparepart); 028/029 pindah induk. Icon `wrench`/`clipboard`/`puzzle` SUDAH ada di `navigation-icon.config.tsx` — TIDAK ada perubahan frontend di task ini.

- [ ] **Step 1: Tulis migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idGrup            = 'm0000001-0000-4000-8000-000000000080';
    private string $idJenisPerawatan  = 'm0000001-0000-4000-8000-000000000081';
    private string $idSparepart       = 'm0000001-0000-4000-8000-000000000082';
    private string $idPerawatanArmada = 'm0000001-0000-4000-8000-000000000028';
    private string $idDokumenArmada   = 'm0000001-0000-4000-8000-000000000029';
    private string $idOperasional     = 'm0000001-0000-4000-8000-000000000020';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idGrup, 'nama_menu' => 'Pemeliharaan', 'path' => null,
                'icon' => 'wrench', 'id_menu_induk' => null, 'urutan' => 9,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
            [
                'id_menu' => $this->idJenisPerawatan, 'nama_menu' => 'Jenis Perawatan', 'path' => '/jenis-perawatan',
                'icon' => 'clipboard', 'id_menu_induk' => $this->idGrup, 'urutan' => 3,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
            [
                'id_menu' => $this->idSparepart, 'nama_menu' => 'Spare Part', 'path' => '/sparepart',
                'icon' => 'puzzle', 'id_menu_induk' => $this->idGrup, 'urutan' => 4,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        // pindahkan 2 menu existing ke grup baru
        DB::table('menu')->where('id_menu', $this->idPerawatanArmada)
            ->update(['id_menu_induk' => $this->idGrup, 'urutan' => 1]);
        DB::table('menu')->where('id_menu', $this->idDokumenArmada)
            ->update(['id_menu_induk' => $this->idGrup, 'urutan' => 2]);

        $menuPeran = [];
        foreach ([$this->idGrup, $this->idJenisPerawatan, $this->idSparepart] as $idMenu) {
            foreach (['DISPATCHER', 'MANAGER', 'ADMIN', 'SUPERADMIN'] as $peran) {
                $menuPeran[] = ['id_menu' => $idMenu, 'kode_peran' => $peran];
            }
        }
        DB::table('menu_peran')->insertOrIgnore($menuPeran);
    }

    public function down(): void
    {
        DB::table('menu')->where('id_menu', $this->idPerawatanArmada)
            ->update(['id_menu_induk' => $this->idOperasional, 'urutan' => 8]);
        DB::table('menu')->where('id_menu', $this->idDokumenArmada)
            ->update(['id_menu_induk' => $this->idOperasional, 'urutan' => 9]);

        DB::table('menu_peran')->whereIn('id_menu', [$this->idGrup, $this->idJenisPerawatan, $this->idSparepart])->delete();
        DB::table('menu')->whereIn('id_menu', [$this->idGrup, $this->idJenisPerawatan, $this->idSparepart])->delete();
    }
};
```

- [ ] **Step 2: Verifikasi migration load bersih**

Run: `cd TMN-TRANSPORT-BACKEND && vendor/bin/phpunit tests/Feature/JenisPerawatanTest.php`
Expected: PASS (RefreshDatabase menjalankan migration baru tanpa error).

- [ ] **Step 3: Stage (JANGAN commit)**

```bash
git add database/migrations/2026_07_17_000006_seed_menu_grup_pemeliharaan.php
```

---

### Task 6: Frontend — services & konstanta

**Files (semua di `D:\PROJECT-TMN\TMN-TRANSPORT-FRONTEND`):**
- Create: `src/services/jenisPerawatan.service.ts`
- Create: `src/services/sparepart.service.ts`
- Modify: `src/services/perawatanArmada.service.ts`
- Modify: `src/constants/api.constant.ts`
- Modify: `src/constants/route.constant.ts`
- Modify: `src/configs/routes.config/routes.config.ts`

**PERINGATAN diff-bundling:** 3 file constants/config di atas punya perubahan uncommitted dari sesi lain — JANGAN utak-atik baris yang bukan milik task ini; tambah baris baru saja.

**Interfaces:**
- Produces (dipakai Task 7-9): `jenisPerawatanService.{list,get,create,update,delete}` + interface `JenisPerawatan`; `sparepartService.{list,get,create,update,delete,tambahStok,listMutasi}` + interface `Sparepart`, `SparepartMutasi`, `StokPayload`; `perawatanArmadaService.get(idArmada,id)` + interface `PerawatanSparepartItem` + payload berisi `id_jenis_perawatan`/`sparepart`; `API_ENDPOINTS.{JENIS_PERAWATAN, JENIS_PERAWATAN_DETAIL, SPAREPART, SPAREPART_DETAIL, SPAREPART_STOK, SPAREPART_MUTASI}`; `ROUTES.{JENIS_PERAWATAN, JENIS_PERAWATAN_BARU, JENIS_PERAWATAN_DETAIL, SPAREPART, SPAREPART_BARU, SPAREPART_DETAIL, PERAWATAN_ARMADA_BARU, PERAWATAN_ARMADA_DETAIL}`.

- [ ] **Step 1: Buat `src/services/jenisPerawatan.service.ts`**

```ts
import axios from 'axios'
import { API_ENDPOINTS } from '@/constants/api.constant'

export interface JenisPerawatan {
    id_jenis_perawatan: string
    id_perusahaan: string
    nama: string
    keterangan: string | null
    aktif: boolean
    dibuat_pada: string
    diubah_pada: string | null
}

export type JenisPerawatanPayload = {
    nama: string
    keterangan?: string | null
    aktif?: boolean
}

export const jenisPerawatanService = {
    async list(page = 1, limit = 15) {
        const { data } = await axios.get(API_ENDPOINTS.JENIS_PERAWATAN, { params: { page, limit } })
        return data as { data: JenisPerawatan[]; meta: { page: number; total: number; totalPages: number; limit: number } }
    },
    async get(id: string) {
        const { data } = await axios.get(API_ENDPOINTS.JENIS_PERAWATAN_DETAIL(id))
        return data.data as JenisPerawatan
    },
    async create(payload: JenisPerawatanPayload) {
        const { data } = await axios.post(API_ENDPOINTS.JENIS_PERAWATAN, payload)
        return data.data as JenisPerawatan
    },
    async update(id: string, payload: Partial<JenisPerawatanPayload>) {
        const { data } = await axios.put(API_ENDPOINTS.JENIS_PERAWATAN_DETAIL(id), payload)
        return data.data as JenisPerawatan
    },
    async delete(id: string) {
        await axios.delete(API_ENDPOINTS.JENIS_PERAWATAN_DETAIL(id))
    },
}
```

- [ ] **Step 2: Buat `src/services/sparepart.service.ts`**

```ts
import axios from 'axios'
import { API_ENDPOINTS } from '@/constants/api.constant'

export interface Sparepart {
    id_sparepart: string
    id_perusahaan: string
    kode: string
    nama: string
    satuan: string
    harga_standar: number
    stok: number
    aktif: boolean
    dibuat_pada: string
    diubah_pada: string | null
}

export interface SparepartMutasi {
    id_mutasi: string
    id_sparepart: string
    jenis: 'masuk' | 'keluar' | 'penyesuaian'
    qty: number
    harga: number | null
    id_perawatan: string | null
    keterangan: string | null
    tanggal: string
    dibuat_pada: string
}

export type SparepartPayload = {
    kode: string
    nama: string
    satuan?: string
    harga_standar?: number
    aktif?: boolean
}

export type StokPayload = {
    jenis: 'masuk' | 'penyesuaian'
    qty: number
    harga?: number | null
    keterangan?: string | null
}

export const sparepartService = {
    async list(params?: { page?: number; limit?: number; search?: string }) {
        const { data } = await axios.get(API_ENDPOINTS.SPAREPART, { params })
        return data as { data: Sparepart[]; meta: { page: number; total: number; totalPages: number; limit: number } }
    },
    async get(id: string) {
        const { data } = await axios.get(API_ENDPOINTS.SPAREPART_DETAIL(id))
        return data.data as Sparepart
    },
    async create(payload: SparepartPayload) {
        const { data } = await axios.post(API_ENDPOINTS.SPAREPART, payload)
        return data.data as Sparepart
    },
    async update(id: string, payload: Partial<SparepartPayload>) {
        const { data } = await axios.put(API_ENDPOINTS.SPAREPART_DETAIL(id), payload)
        return data.data as Sparepart
    },
    async delete(id: string) {
        await axios.delete(API_ENDPOINTS.SPAREPART_DETAIL(id))
    },
    async tambahStok(id: string, payload: StokPayload) {
        const { data } = await axios.post(API_ENDPOINTS.SPAREPART_STOK(id), payload)
        return data.data as Sparepart
    },
    async listMutasi(id: string, page = 1, limit = 10) {
        const { data } = await axios.get(API_ENDPOINTS.SPAREPART_MUTASI(id), { params: { page, limit } })
        return data as { data: SparepartMutasi[]; meta: { page: number; total: number; totalPages: number; limit: number } }
    },
}
```

- [ ] **Step 3: Update `src/services/perawatanArmada.service.ts`**

Tambah interface + type di bawah `StatusPerawatan` (JANGAN hapus yang ada):

```ts
export interface PerawatanSparepartItem {
    id_perawatan_sparepart: string
    id_sparepart: string
    nama_sparepart: string
    qty: number
    harga: number
    subtotal: number
}

export type PerawatanSparepartInput = {
    id_sparepart: string
    qty: number
    harga: number
}
```

Update interface `PerawatanArmada` — tambah 2 field setelah `id_armada`:

```ts
    id_jenis_perawatan: string | null
    sparepart?: PerawatanSparepartItem[]
```

Update type `PerawatanPayload` — tambah:

```ts
    id_jenis_perawatan?: string | null
    jenis_perawatan?: string
    sparepart?: PerawatanSparepartInput[]
```

(PERHATIAN: `jenis_perawatan` di payload berubah dari wajib jadi opsional karena backend sekarang `required_without:id_jenis_perawatan` — sesuaikan definisi type `PerawatanPayload` yang sekarang mewajibkannya.)

Tambah method `get` di object `perawatanArmadaService` (setelah `listAll`):

```ts
    async get(idArmada: string, id: string) {
        const { data } = await axios.get(API_ENDPOINTS.ARMADA_PERAWATAN_DETAIL(idArmada, id))
        return data.data as PerawatanArmada
    },
```

- [ ] **Step 4: Update `src/constants/api.constant.ts`** — tambah setelah baris `PERAWATAN_ARMADA`:

```ts
    JENIS_PERAWATAN:        '/api/proxy/jenis-perawatan',
    JENIS_PERAWATAN_DETAIL: (id: string) => `/api/proxy/jenis-perawatan/${id}`,
    SPAREPART:              '/api/proxy/sparepart',
    SPAREPART_DETAIL:       (id: string) => `/api/proxy/sparepart/${id}`,
    SPAREPART_STOK:         (id: string) => `/api/proxy/sparepart/${id}/stok`,
    SPAREPART_MUTASI:       (id: string) => `/api/proxy/sparepart/${id}/mutasi`,
```

- [ ] **Step 5: Update `src/constants/route.constant.ts`** — tambah setelah baris `DOKUMEN_ARMADA`:

```ts
    PERAWATAN_ARMADA_BARU:   '/perawatan-armada/baru',
    PERAWATAN_ARMADA_DETAIL: (id: string) => `/perawatan-armada/${id}`,
    JENIS_PERAWATAN:        '/jenis-perawatan',
    JENIS_PERAWATAN_BARU:   '/jenis-perawatan/baru',
    JENIS_PERAWATAN_DETAIL: (id: string) => `/jenis-perawatan/${id}`,
    SPAREPART:              '/sparepart',
    SPAREPART_BARU:         '/sparepart/baru',
    SPAREPART_DETAIL:       (id: string) => `/sparepart/${id}`,
```

- [ ] **Step 6: Update `src/configs/routes.config/routes.config.ts`**

Ganti baris `'/perawatan-armada': { key: 'perawatan-armada', authority: [] },` menjadi:

```ts
    ...listRoute('perawatan-armada', 'perawatan-armada'),
```

Tambah setelah baris `'/dokumen-armada'`:

```ts
    ...listRoute('jenis-perawatan', 'jenis-perawatan'),
    ...listRoute('sparepart', 'sparepart'),
```

- [ ] **Step 7: Verifikasi**

Run: `cd TMN-TRANSPORT-FRONTEND && npx tsc --noEmit -p tsconfig.json`
Expected: 0 error. (Kalau ada error di file lain yang memakai `PerawatanPayload` karena `jenis_perawatan` jadi opsional — perbaiki call site itu juga dan laporkan.)

- [ ] **Step 8: Stage (JANGAN commit)**

```bash
git add src/services/jenisPerawatan.service.ts src/services/sparepart.service.ts src/services/perawatanArmada.service.ts src/constants/api.constant.ts src/constants/route.constant.ts src/configs/routes.config/routes.config.ts
```

---

### Task 7: Frontend — halaman master `/jenis-perawatan` (3 file)

**Files:**
- Create: `src/app/(protected-pages)/jenis-perawatan/page.tsx`
- Create: `src/app/(protected-pages)/jenis-perawatan/baru/page.tsx`
- Create: `src/app/(protected-pages)/jenis-perawatan/[id]/page.tsx`

**Pola referensi (WAJIB dibaca dulu):** 3 halaman ini MIRROR persis struktur halaman master `src/app/(protected-pages)/jenis-bbm/` (`page.tsx` = list, `baru/page.tsx` = form create, `[id]/page.tsx` = detail+edit). Baca ketiga file referensi itu, replikasi struktur/layout/styling-nya persis, dengan substitusi:

| jenis-bbm (referensi) | jenis-perawatan (buat) |
|---|---|
| `jenisBbmService` / `JenisBbm` | `jenisPerawatanService` / `JenisPerawatan` (Task 6) |
| `id_jenis_bbm` | `id_jenis_perawatan` |
| field `nama_bbm` | field `nama` (label "Nama Jenis Perawatan") |
| field `harga_per_liter` + section Harga | TIDAK ADA — ganti field `keterangan` (Input textArea, opsional) |
| `ROUTES.JENIS_BBM*` | `ROUTES.JENIS_PERAWATAN*` |
| judul "Jenis BBM" | "Jenis Perawatan" |

Field form: `nama` (required), `keterangan` (opsional textarea), `aktif` (toggle/switch — hanya di halaman edit, ikuti pola referensi). Kolom list: No, Nama, Keterangan (truncate), Status Aktif (Tag), Aksi (pola pill blue/red seperti halaman lain). Semua konstruksi wajib patuh Global Constraints (parseApiError, ROUTES, dll).

- [ ] **Step 1: Baca 3 file referensi jenis-bbm + service Task 6, lalu buat 3 file baru sesuai tabel substitusi**
- [ ] **Step 2: Verifikasi**

Run: `cd TMN-TRANSPORT-FRONTEND && npx tsc --noEmit -p tsconfig.json && npx eslint "src/app/(protected-pages)/jenis-perawatan/page.tsx" "src/app/(protected-pages)/jenis-perawatan/baru/page.tsx" "src/app/(protected-pages)/jenis-perawatan/[id]/page.tsx"`
Expected: 0 error keduanya.

- [ ] **Step 3: Stage (JANGAN commit)**

```bash
git add "src/app/(protected-pages)/jenis-perawatan"
```

---

### Task 8: Frontend — halaman `/sparepart` (3 file: list, baru, detail+mutasi+stok)

**Files:**
- Create: `src/app/(protected-pages)/sparepart/page.tsx`
- Create: `src/app/(protected-pages)/sparepart/baru/page.tsx`
- Create: `src/app/(protected-pages)/sparepart/[id]/page.tsx`

**Pola referensi:** `page.tsx` (list) & `baru/page.tsx` mirror struktur halaman jenis-bbm yang sama (baca referensinya), dengan substitusi ke `sparepartService`/`Sparepart` (Task 6). Spesifik list: kolom No, Kode (font-mono), Nama, Satuan, Harga Standar (`formatRupiah`), **Stok** (angka + `Tag` merah `bg-red-100 text-red-600` jika stok === 0, kuning `bg-yellow-100 text-yellow-700` jika < 5, selain itu emerald), Aktif (Tag), Aksi. Search box memanggil `sparepartService.list({search})` server-side (param `search` sudah didukung backend Task 3), reset `currentPage` ke 1 saat submit/clear. Form baru: kode (required), nama (required), satuan (default 'pcs'), harga_standar (prefix Rp, `formatNum`), aktif.

`[id]/page.tsx` (detail) — kode LENGKAP di bawah:

```tsx
'use client'
import { use, useEffect, useState, useCallback } from 'react'
import { useRouter } from 'next/navigation'
import { Card, Button, Dialog, FormItem, Input, Tag, toast, Notification, Spinner } from '@/components/ui'
import Select from '@/components/ui/Select'
import ConfirmDialog from '@/components/shared/ConfirmDialog'
import { HiArrowLeft, HiOutlinePencilAlt, HiOutlinePlus, HiOutlineTrash } from 'react-icons/hi'
import dayjs from 'dayjs'
import { parseApiError } from '@/utils/error.util'
import { formatRupiah, formatNum } from '@/utils/formatNumber'
import { ROUTES } from '@/constants/route.constant'
import { sparepartService, Sparepart, SparepartMutasi } from '@/services/sparepart.service'

type Option = { value: 'masuk' | 'penyesuaian'; label: string }

const JENIS_STOK_OPTIONS: Option[] = [
    { value: 'masuk',       label: 'Barang Masuk' },
    { value: 'penyesuaian', label: 'Penyesuaian (koreksi +/-)' },
]

const MUTASI_CLASS: Record<string, string> = {
    masuk:       'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400',
    keluar:      'bg-red-100 text-red-500 dark:bg-red-500/20 dark:text-red-400',
    penyesuaian: 'bg-blue-100 text-blue-600 dark:bg-blue-500/20 dark:text-blue-400',
}

export default function SparepartDetailPage({ params }: { params: Promise<{ id: string }> }) {
    const { id } = use(params)
    const router = useRouter()

    const [sparepart, setSparepart] = useState<Sparepart | null>(null)
    const [loading, setLoading]     = useState(true)
    const [editing, setEditing]     = useState(false)
    const [form, setForm]           = useState({ kode: '', nama: '', satuan: '', harga_standar: '', aktif: true })
    const [saving, setSaving]       = useState(false)
    const [deleteOpen, setDeleteOpen] = useState(false)
    const [deleting, setDeleting]     = useState(false)

    const [mutasi, setMutasi]             = useState<SparepartMutasi[]>([])
    const [mutasiLoading, setMutasiLoading] = useState(false)

    const [stokOpen, setStokOpen]   = useState(false)
    const [stokForm, setStokForm]   = useState<{ jenis: 'masuk' | 'penyesuaian'; qty: string; harga: string; keterangan: string }>({ jenis: 'masuk', qty: '', harga: '', keterangan: '' })
    const [stokSaving, setStokSaving] = useState(false)

    const fetchSparepart = useCallback(async () => {
        try {
            const sp = await sparepartService.get(id)
            setSparepart(sp)
            setForm({ kode: sp.kode, nama: sp.nama, satuan: sp.satuan, harga_standar: String(sp.harga_standar), aktif: sp.aktif })
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setLoading(false)
        }
    }, [id])

    const fetchMutasi = useCallback(async () => {
        setMutasiLoading(true)
        try {
            const res = await sparepartService.listMutasi(id, 1, 20)
            setMutasi(res.data)
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setMutasiLoading(false)
        }
    }, [id])

    useEffect(() => { fetchSparepart() }, [fetchSparepart])
    useEffect(() => { fetchMutasi() }, [fetchMutasi])

    const handleSave = async () => {
        if (!form.kode.trim() || !form.nama.trim()) return
        setSaving(true)
        try {
            const updated = await sparepartService.update(id, {
                kode: form.kode,
                nama: form.nama,
                satuan: form.satuan || 'pcs',
                harga_standar: Number(form.harga_standar) || 0,
                aktif: form.aktif,
            })
            setSparepart(updated)
            setEditing(false)
            toast.push(<Notification type="success" title="Spare part berhasil diperbarui" />)
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setSaving(false)
        }
    }

    const handleDelete = async () => {
        setDeleting(true)
        try {
            await sparepartService.delete(id)
            toast.push(<Notification type="success" title="Spare part berhasil dihapus" />)
            router.push(ROUTES.SPAREPART)
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
            setDeleting(false)
        }
    }

    const handleTambahStok = async () => {
        const qty = Number(stokForm.qty)
        if (!qty) return
        setStokSaving(true)
        try {
            const updated = await sparepartService.tambahStok(id, {
                jenis: stokForm.jenis,
                qty,
                harga: stokForm.harga ? Number(stokForm.harga) : null,
                keterangan: stokForm.keterangan || null,
            })
            setSparepart(updated)
            setStokOpen(false)
            setStokForm({ jenis: 'masuk', qty: '', harga: '', keterangan: '' })
            toast.push(<Notification type="success" title="Stok berhasil diperbarui" />)
            fetchMutasi()
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setStokSaving(false)
        }
    }

    if (loading) return <div className="p-6 text-gray-500">Memuat...</div>
    if (!sparepart) return <div className="p-6 text-red-500">Spare part tidak ditemukan.</div>

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center gap-3">
                <button type="button" onClick={() => router.push(ROUTES.SPAREPART)}
                    className="flex items-center justify-center w-8 h-8 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500 transition-colors">
                    <HiArrowLeft className="text-xl" />
                </button>
                <div>
                    <h3 className="font-bold">{sparepart.nama}</h3>
                    <p className="text-gray-500 text-sm mt-0.5 font-mono">{sparepart.kode}</p>
                </div>
            </div>

            <Card>
                <div className="flex items-center justify-between mb-4">
                    <p className="text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wide">Informasi Spare Part</p>
                    <div className="flex gap-2">
                        {!editing && (
                            <>
                                <Button size="sm" variant="solid" icon={<HiOutlinePlus />} onClick={() => setStokOpen(true)}>Tambah Stok</Button>
                                <Button size="sm" variant="default" icon={<HiOutlinePencilAlt />} onClick={() => setEditing(true)}>Edit</Button>
                                <Button size="sm" variant="plain" icon={<HiOutlineTrash />} onClick={() => setDeleteOpen(true)}>Hapus</Button>
                            </>
                        )}
                    </div>
                </div>

                {!editing ? (
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-5">
                        {([
                            { label: 'Kode',          value: <span className="font-mono">{sparepart.kode}</span> },
                            { label: 'Nama',          value: sparepart.nama },
                            { label: 'Satuan',        value: sparepart.satuan },
                            { label: 'Harga Standar', value: formatRupiah(sparepart.harga_standar) },
                            {
                                label: 'Stok Saat Ini',
                                value: (
                                    <span className={`px-2.5 py-1 rounded-full text-xs font-semibold ${
                                        sparepart.stok === 0 ? 'bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-400'
                                        : sparepart.stok < 5 ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-500/20 dark:text-yellow-400'
                                        : 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400'
                                    }`}>
                                        {formatNum(sparepart.stok)} {sparepart.satuan}
                                    </span>
                                ),
                            },
                            {
                                label: 'Status',
                                value: (
                                    <Tag className={sparepart.aktif
                                        ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400'
                                        : 'bg-red-100 text-red-500 dark:bg-red-500/20 dark:text-red-400'}>
                                        {sparepart.aktif ? 'Aktif' : 'Nonaktif'}
                                    </Tag>
                                ),
                            },
                        ]).map(({ label, value }) => (
                            <div key={label}>
                                <p className="text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wide mb-1">{label}</p>
                                <p className="text-sm font-medium text-gray-800 dark:text-gray-200">{value}</p>
                            </div>
                        ))}
                    </div>
                ) : (
                    <form onSubmit={e => { e.preventDefault(); handleSave() }}>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
                            <FormItem label="Kode" asterisk>
                                <Input value={form.kode} onChange={e => setForm(p => ({ ...p, kode: e.target.value.toUpperCase() }))} />
                            </FormItem>
                            <FormItem label="Nama" asterisk>
                                <Input value={form.nama} onChange={e => setForm(p => ({ ...p, nama: e.target.value }))} />
                            </FormItem>
                            <FormItem label="Satuan">
                                <Input placeholder="pcs" value={form.satuan} onChange={e => setForm(p => ({ ...p, satuan: e.target.value }))} />
                            </FormItem>
                            <FormItem label="Harga Standar (Rp)">
                                <Input prefix="Rp" placeholder="0"
                                    value={form.harga_standar ? formatNum(Number(form.harga_standar)) : ''}
                                    onChange={e => setForm(p => ({ ...p, harga_standar: e.target.value.replace(/\D/g, '') }))} />
                            </FormItem>
                            <FormItem label="Status">
                                <Select isSearchable={false}
                                    options={[{ value: true, label: 'Aktif' }, { value: false, label: 'Nonaktif' }]}
                                    value={{ value: form.aktif, label: form.aktif ? 'Aktif' : 'Nonaktif' }}
                                    onChange={opt => opt && setForm(p => ({ ...p, aktif: (opt as { value: boolean }).value }))} />
                            </FormItem>
                        </div>
                        <div className="flex justify-end gap-2 mt-4">
                            <Button type="button" variant="plain" onClick={() => setEditing(false)}>Batal</Button>
                            <Button type="submit" variant="solid" loading={saving}>Simpan</Button>
                        </div>
                    </form>
                )}
            </Card>

            <Card>
                <div className="flex items-center justify-between mb-4">
                    <p className="text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wide">Riwayat Mutasi Stok (20 terakhir)</p>
                    {mutasiLoading && <Spinner size={20} />}
                </div>
                {mutasi.length === 0 && !mutasiLoading ? (
                    <p className="text-gray-400 text-sm py-4 text-center">Belum ada mutasi stok</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-blue-50 dark:bg-blue-500/10">
                                <tr className="border-b border-gray-100 dark:border-gray-700">
                                    <th className="py-2.5 text-left text-xs font-medium text-gray-400 uppercase tracking-wide pr-4">Tanggal</th>
                                    <th className="py-2.5 text-left text-xs font-medium text-gray-400 uppercase tracking-wide pr-4">Jenis</th>
                                    <th className="py-2.5 text-right text-xs font-medium text-gray-400 uppercase tracking-wide pr-4">Qty</th>
                                    <th className="py-2.5 text-right text-xs font-medium text-gray-400 uppercase tracking-wide pr-4">Harga</th>
                                    <th className="py-2.5 text-left text-xs font-medium text-gray-400 uppercase tracking-wide">Keterangan</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                                {mutasi.map(m => (
                                    <tr key={m.id_mutasi}>
                                        <td className="py-3 pr-4 text-xs text-gray-500 whitespace-nowrap">{dayjs(m.tanggal).format('DD MMM YYYY')}</td>
                                        <td className="py-3 pr-4">
                                            <Tag className={`text-xs font-semibold ${MUTASI_CLASS[m.jenis] ?? 'bg-gray-100 text-gray-600'}`}>{m.jenis}</Tag>
                                        </td>
                                        <td className="py-3 pr-4 text-right font-mono text-xs">{m.qty > 0 && m.jenis !== 'keluar' ? '+' : m.jenis === 'keluar' ? '-' : ''}{formatNum(Math.abs(m.qty))}</td>
                                        <td className="py-3 pr-4 text-right whitespace-nowrap">{m.harga != null ? formatRupiah(m.harga) : <span className="text-gray-400">—</span>}</td>
                                        <td className="py-3 text-gray-600 dark:text-gray-400 max-w-[240px] truncate">{m.keterangan ?? <span className="text-gray-400">—</span>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <Dialog isOpen={stokOpen} onRequestClose={() => setStokOpen(false)} width={480}>
                <h5 className="text-base font-semibold mb-5">Tambah / Sesuaikan Stok</h5>
                <FormItem label="Jenis" asterisk>
                    <Select isSearchable={false}
                        options={JENIS_STOK_OPTIONS}
                        value={JENIS_STOK_OPTIONS.find(o => o.value === stokForm.jenis) ?? null}
                        onChange={opt => opt && setStokForm(p => ({ ...p, jenis: (opt as Option).value }))} />
                </FormItem>
                <FormItem label={stokForm.jenis === 'masuk' ? 'Qty Masuk' : 'Qty Koreksi (+/-)'} asterisk>
                    <Input type="number" placeholder={stokForm.jenis === 'masuk' ? 'Contoh: 10' : 'Contoh: -3 atau 5'}
                        value={stokForm.qty}
                        onChange={e => setStokForm(p => ({ ...p, qty: e.target.value }))} />
                </FormItem>
                <FormItem label="Harga Beli per Unit (opsional)">
                    <Input prefix="Rp" placeholder="0"
                        value={stokForm.harga ? formatNum(Number(stokForm.harga)) : ''}
                        onChange={e => setStokForm(p => ({ ...p, harga: e.target.value.replace(/\D/g, '') }))} />
                </FormItem>
                <FormItem label="Keterangan">
                    <Input textArea placeholder="Contoh: Pembelian rutin / stok opname" value={stokForm.keterangan}
                        onChange={e => setStokForm(p => ({ ...p, keterangan: e.target.value }))} />
                </FormItem>
                <div className="flex justify-end gap-2 mt-4">
                    <Button variant="plain" onClick={() => setStokOpen(false)}>Batal</Button>
                    <Button variant="solid" loading={stokSaving} disabled={!Number(stokForm.qty)} onClick={handleTambahStok}>Simpan</Button>
                </div>
            </Dialog>

            <ConfirmDialog isOpen={deleteOpen} type="danger" title="Hapus Spare Part"
                confirmText="Ya, Hapus" cancelText="Batal"
                onClose={() => setDeleteOpen(false)} onCancel={() => setDeleteOpen(false)}
                onConfirm={handleDelete} confirmButtonProps={{ loading: deleting }}>
                <p>Hapus spare part <strong>{sparepart.nama}</strong>? Riwayat mutasi dan pemakaian servis lama tetap tersimpan.</p>
            </ConfirmDialog>
        </div>
    )
}
```

- [ ] **Step 1: Baca referensi jenis-bbm list/baru + service Task 6, buat `page.tsx` & `baru/page.tsx` sesuai spesifikasi di atas; buat `[id]/page.tsx` persis kode di atas**
- [ ] **Step 2: Verifikasi**

Run: `cd TMN-TRANSPORT-FRONTEND && npx tsc --noEmit -p tsconfig.json && npx eslint "src/app/(protected-pages)/sparepart/page.tsx" "src/app/(protected-pages)/sparepart/baru/page.tsx" "src/app/(protected-pages)/sparepart/[id]/page.tsx"`
Expected: 0 error.

- [ ] **Step 3: Stage (JANGAN commit)**

```bash
git add "src/app/(protected-pages)/sparepart"
```

---

### Task 9: Frontend — form perawatan jadi halaman terpisah + hapus modal di list

**Files:**
- Create: `src/app/(protected-pages)/perawatan-armada/PerawatanForm.tsx` (shared component)
- Create: `src/app/(protected-pages)/perawatan-armada/baru/page.tsx`
- Create: `src/app/(protected-pages)/perawatan-armada/[id]/page.tsx`
- Modify: `src/app/(protected-pages)/perawatan-armada/page.tsx` (hapus Dialog form, ganti navigasi)

**Interfaces:**
- Consumes: `perawatanArmadaService.{get,create,update,listAll}`, `jenisPerawatanService.list`, `sparepartService.list`, `armadaService.list` (semua dari Task 6), `ROUTES.PERAWATAN_ARMADA*`.

- [ ] **Step 1: Buat shared component `PerawatanForm.tsx`**

```tsx
'use client'
import { useEffect, useState } from 'react'
import { useRouter } from 'next/navigation'
import { Card, Button, FormItem, Input, DatePicker, toast, Notification } from '@/components/ui'
import Select from '@/components/ui/Select'
import { HiArrowLeft, HiOutlinePlus, HiOutlineTrash } from 'react-icons/hi'
import dayjs from 'dayjs'
import { parseApiError } from '@/utils/error.util'
import { formatRupiah, formatNum } from '@/utils/formatNumber'
import { ROUTES } from '@/constants/route.constant'
import { perawatanArmadaService, PerawatanArmada, StatusPerawatan, PerawatanSparepartInput } from '@/services/perawatanArmada.service'
import { jenisPerawatanService } from '@/services/jenisPerawatan.service'
import { sparepartService, Sparepart } from '@/services/sparepart.service'
import { armadaService, Armada } from '@/services/armada.service'

type Option = { value: string; label: string }

const STATUS_OPTIONS: { value: StatusPerawatan; label: string }[] = [
    { value: 'terjadwal',    label: 'Terjadwal' },
    { value: 'dalam_proses', label: 'Dalam Proses' },
    { value: 'selesai',      label: 'Selesai' },
]

type ItemRow = { id_sparepart: string; qty: string; harga: string }

type FormState = {
    id_armada: string
    id_jenis_perawatan: string | null
    tanggal: string
    biaya: string
    km_odometer: string
    status: StatusPerawatan
    jadwal_servis_berikutnya: string
    keterangan: string
}

const emptyForm = (): FormState => ({
    id_armada: '', id_jenis_perawatan: null, tanggal: '', biaya: '', km_odometer: '',
    status: 'selesai', jadwal_servis_berikutnya: '', keterangan: '',
})

export default function PerawatanForm({ editId, editArmadaId }: { editId?: string; editArmadaId?: string }) {
    const router = useRouter()
    const isEdit = !!editId

    const [form, setForm]   = useState<FormState>(emptyForm())
    const [items, setItems] = useState<ItemRow[]>([])
    const [loading, setLoading] = useState(isEdit)
    const [saving, setSaving]   = useState(false)

    const [armadaOptions, setArmadaOptions] = useState<Option[]>([])
    const [jenisOptions, setJenisOptions]   = useState<Option[]>([])
    const [sparepartList, setSparepartList] = useState<Sparepart[]>([])

    useEffect(() => {
        Promise.all([
            armadaService.list(1, 100),
            jenisPerawatanService.list(1, 100),
            sparepartService.list({ page: 1, limit: 100 }),
        ]).then(([armada, jenis, sp]) => {
            setArmadaOptions(armada.data.map((a: Armada) => ({ value: a.id_armada, label: a.nopol })))
            setJenisOptions(jenis.data.filter(j => j.aktif).map(j => ({ value: j.id_jenis_perawatan, label: j.nama })))
            setSparepartList(sp.data.filter(s => s.aktif))
        }).catch(err => toast.push(<Notification type="danger" title={parseApiError(err)} />))
    }, [])

    useEffect(() => {
        if (!isEdit || !editId || !editArmadaId) return
        perawatanArmadaService.get(editArmadaId, editId)
            .then((p: PerawatanArmada) => {
                setForm({
                    id_armada: p.id_armada,
                    id_jenis_perawatan: p.id_jenis_perawatan,
                    tanggal: p.tanggal,
                    biaya: String(p.biaya ?? ''),
                    km_odometer: p.km_odometer != null ? String(p.km_odometer) : '',
                    status: p.status,
                    jadwal_servis_berikutnya: p.jadwal_servis_berikutnya ?? '',
                    keterangan: p.keterangan ?? '',
                })
                setItems((p.sparepart ?? []).map(it => ({
                    id_sparepart: it.id_sparepart,
                    qty: String(it.qty),
                    harga: String(it.harga),
                })))
            })
            .catch(err => toast.push(<Notification type="danger" title={parseApiError(err)} />))
            .finally(() => setLoading(false))
    }, [isEdit, editId, editArmadaId])

    const sparepartOptions: Option[] = sparepartList.map(s => ({
        value: s.id_sparepart,
        label: `${s.nama} (stok: ${formatNum(s.stok)} ${s.satuan})`,
    }))

    const addItem = () => setItems(p => [...p, { id_sparepart: '', qty: '1', harga: '' }])
    const removeItem = (idx: number) => setItems(p => p.filter((_, i) => i !== idx))
    const updateItem = (idx: number, field: keyof ItemRow, value: string) => {
        setItems(p => {
            const next = [...p]
            next[idx] = { ...next[idx], [field]: value }
            return next
        })
    }
    const pilihSparepart = (idx: number, idSparepart: string) => {
        const sp = sparepartList.find(s => s.id_sparepart === idSparepart)
        setItems(p => {
            const next = [...p]
            next[idx] = {
                ...next[idx],
                id_sparepart: idSparepart,
                harga: next[idx].harga || (sp ? String(sp.harga_standar) : ''),
            }
            return next
        })
    }

    const totalSparepart = items.reduce((sum, it) => sum + (Number(it.qty) || 0) * (Number(it.harga) || 0), 0)

    const canSubmit = !!form.id_armada && !!form.tanggal && !!form.id_jenis_perawatan
        && items.every(it => it.id_sparepart && Number(it.qty) > 0)

    const handleSubmit = async () => {
        if (!canSubmit) return
        setSaving(true)
        try {
            const payload = {
                tanggal: form.tanggal,
                id_jenis_perawatan: form.id_jenis_perawatan,
                biaya: Number(form.biaya) || 0,
                km_odometer: form.km_odometer ? Number(form.km_odometer) : null,
                status: form.status,
                jadwal_servis_berikutnya: form.jadwal_servis_berikutnya || null,
                keterangan: form.keterangan || null,
                sparepart: items.map((it): PerawatanSparepartInput => ({
                    id_sparepart: it.id_sparepart,
                    qty: Number(it.qty),
                    harga: Number(it.harga) || 0,
                })),
            }
            if (isEdit && editId && editArmadaId) {
                await perawatanArmadaService.update(editArmadaId, editId, payload)
                toast.push(<Notification type="success" title="Perawatan berhasil diperbarui" />)
            } else {
                await perawatanArmadaService.create(form.id_armada, payload)
                toast.push(<Notification type="success" title="Perawatan berhasil dicatat" />)
            }
            router.push(ROUTES.PERAWATAN_ARMADA)
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setSaving(false)
        }
    }

    if (loading) return <div className="p-6 text-gray-500">Memuat...</div>

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center gap-3">
                <button type="button" onClick={() => router.push(ROUTES.PERAWATAN_ARMADA)}
                    className="flex items-center justify-center w-8 h-8 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500 transition-colors">
                    <HiArrowLeft className="text-xl" />
                </button>
                <div>
                    <h3 className="font-bold">{isEdit ? 'Edit Perawatan' : 'Catat Perawatan'}</h3>
                    <p className="text-gray-500 text-sm mt-0.5">{isEdit ? 'Perbarui data perawatan armada' : 'Catat perawatan armada baru'}</p>
                </div>
            </div>

            <Card>
                <form onSubmit={e => { e.preventDefault(); handleSubmit() }}>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
                        <FormItem label="Armada" asterisk>
                            <Select placeholder="Pilih armada..."
                                isDisabled={isEdit}
                                options={armadaOptions}
                                value={armadaOptions.find(o => o.value === form.id_armada) ?? null}
                                onChange={opt => setForm(p => ({ ...p, id_armada: (opt as Option | null)?.value ?? '' }))} />
                        </FormItem>
                        <FormItem label="Jenis Perawatan" asterisk>
                            <Select placeholder="Pilih jenis perawatan..."
                                options={jenisOptions}
                                value={jenisOptions.find(o => o.value === form.id_jenis_perawatan) ?? null}
                                onChange={opt => setForm(p => ({ ...p, id_jenis_perawatan: (opt as Option | null)?.value ?? null }))} />
                        </FormItem>
                        <FormItem label="Tanggal" asterisk>
                            <DatePicker
                                value={form.tanggal ? new Date(form.tanggal) : null}
                                onChange={date => setForm(p => ({ ...p, tanggal: date ? dayjs(date).format('YYYY-MM-DD') : '' }))} />
                        </FormItem>
                        <FormItem label="Biaya Jasa (Rp)">
                            <Input prefix="Rp" placeholder="0"
                                value={form.biaya ? formatNum(Number(form.biaya)) : ''}
                                onChange={e => setForm(p => ({ ...p, biaya: e.target.value.replace(/\D/g, '') }))} />
                        </FormItem>
                        <FormItem label="KM Odometer">
                            <Input suffix="km" placeholder="0" value={form.km_odometer}
                                onChange={e => setForm(p => ({ ...p, km_odometer: e.target.value.replace(/\D/g, '') }))} />
                        </FormItem>
                        <FormItem label="Status">
                            <Select isSearchable={false}
                                options={STATUS_OPTIONS}
                                value={STATUS_OPTIONS.find(o => o.value === form.status) ?? null}
                                onChange={opt => opt && setForm(p => ({ ...p, status: (opt as { value: StatusPerawatan }).value }))} />
                        </FormItem>
                        <FormItem label="Jadwal Servis Berikutnya">
                            <DatePicker
                                value={form.jadwal_servis_berikutnya ? new Date(form.jadwal_servis_berikutnya) : null}
                                onChange={date => setForm(p => ({ ...p, jadwal_servis_berikutnya: date ? dayjs(date).format('YYYY-MM-DD') : '' }))} />
                        </FormItem>
                        <div className="sm:col-span-2">
                            <FormItem label="Keterangan">
                                <Input textArea placeholder="Keterangan tambahan..." value={form.keterangan}
                                    onChange={e => setForm(p => ({ ...p, keterangan: e.target.value }))} />
                            </FormItem>
                        </div>
                    </div>

                    <div className="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700">
                        <div className="flex items-center justify-between mb-3">
                            <p className="text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wide">Spare Part Diganti</p>
                            <Button type="button" size="sm" variant="plain" icon={<HiOutlinePlus />} onClick={addItem}>Tambah Part</Button>
                        </div>
                        {items.length === 0 ? (
                            <p className="text-gray-400 text-xs py-2">Belum ada spare part ditambahkan.</p>
                        ) : (
                            <div className="flex flex-col gap-2">
                                {items.map((it, idx) => (
                                    <div key={idx} className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                                        <div className="flex-1 min-w-0">
                                            <Select placeholder="Pilih spare part..."
                                                options={sparepartOptions}
                                                value={sparepartOptions.find(o => o.value === it.id_sparepart) ?? null}
                                                onChange={opt => pilihSparepart(idx, (opt as Option | null)?.value ?? '')} />
                                        </div>
                                        <Input className="w-full sm:w-24" type="number" min={1} placeholder="Qty"
                                            value={it.qty}
                                            onChange={e => updateItem(idx, 'qty', e.target.value.replace(/\D/g, ''))} />
                                        <Input className="w-full sm:w-40" prefix="Rp" placeholder="Harga/unit"
                                            value={it.harga ? formatNum(Number(it.harga)) : ''}
                                            onChange={e => updateItem(idx, 'harga', e.target.value.replace(/\D/g, ''))} />
                                        <div className="w-full sm:w-32 text-right text-sm font-medium whitespace-nowrap self-center">
                                            {formatRupiah((Number(it.qty) || 0) * (Number(it.harga) || 0))}
                                        </div>
                                        <span
                                            className="cursor-pointer inline-flex items-center justify-center w-8 h-8 rounded-lg bg-red-50 text-red-500 hover:bg-red-100 dark:bg-red-500/10 dark:hover:bg-red-500/20 transition-colors flex-shrink-0 self-center"
                                            onClick={() => removeItem(idx)}>
                                            <HiOutlineTrash className="text-base" />
                                        </span>
                                    </div>
                                ))}
                                <div className="flex justify-end pt-2 border-t border-gray-100 dark:border-gray-700">
                                    <p className="text-sm">Total Spare Part: <span className="font-bold">{formatRupiah(totalSparepart)}</span></p>
                                </div>
                            </div>
                        )}
                    </div>

                    <div className="flex justify-end gap-2 mt-6">
                        <Button type="button" variant="plain" onClick={() => router.push(ROUTES.PERAWATAN_ARMADA)}>Batal</Button>
                        <Button type="submit" variant="solid" loading={saving} disabled={!canSubmit}>Simpan</Button>
                    </div>
                </form>
            </Card>
        </div>
    )
}
```

- [ ] **Step 2: Buat `baru/page.tsx`**

```tsx
import PerawatanForm from '../PerawatanForm'

export default function PerawatanBaruPage() {
    return <PerawatanForm />
}
```

- [ ] **Step 3: Buat `[id]/page.tsx`**

```tsx
'use client'
import { use } from 'react'
import { useSearchParams } from 'next/navigation'
import PerawatanForm from '../PerawatanForm'

export default function PerawatanEditPage({ params }: { params: Promise<{ id: string }> }) {
    const { id } = use(params)
    const searchParams = useSearchParams()
    const idArmada = searchParams.get('armada') ?? ''

    if (!idArmada) return <div className="p-6 text-red-500">Parameter armada tidak ditemukan.</div>

    return <PerawatanForm editId={id} editArmadaId={idArmada} />
}
```

- [ ] **Step 4: Update list page `perawatan-armada/page.tsx`**

Perubahan pada file existing (JANGAN tulis ulang seluruh file — edit terarah):
1. Hapus SELURUH blok `<Dialog ...>...</Dialog>` form create/edit (bukan `ConfirmDialog` hapus — itu tetap).
2. Hapus state & handler yang hanya dipakai dialog: `showForm`, `form`, `saving`, `editTarget`, `openAdd`, `openEdit`, `closeForm`, `handleSubmit`, `emptyForm`, type `RawatForm`, konstanta `FORM_STATUS_OPTIONS`, dan import yang jadi tidak terpakai (`Dialog`, `FormItem`, `DatePicker`, dsb — biarkan eslint yang memandu; `HiOutlinePencilAlt`/`HiOutlineTrash` tetap dipakai kolom aksi).
3. Tombol header: `onClick={openAdd}` → `onClick={() => router.push(ROUTES.PERAWATAN_ARMADA_BARU)}` — tambah `useRouter` dari `next/navigation` + import `ROUTES`.
4. Kolom aksi Edit: `onClick={() => openEdit(row.original)}` → `onClick={() => router.push(`${ROUTES.PERAWATAN_ARMADA_DETAIL(row.original.id_perawatan)}?armada=${row.original.id_armada}`)}`.
5. Kolom `Jenis Perawatan` tetap menampilkan `jenis_perawatan` (teks snapshot) — tidak berubah.

- [ ] **Step 5: Verifikasi**

Run: `cd TMN-TRANSPORT-FRONTEND && npx tsc --noEmit -p tsconfig.json && npx eslint "src/app/(protected-pages)/perawatan-armada"`
Expected: 0 error.

- [ ] **Step 6: Stage (JANGAN commit)**

```bash
git add "src/app/(protected-pages)/perawatan-armada"
```

---

### Task 10: Verifikasi penuh end-to-end

**Files:** tidak ada — operasional murni (dikerjakan controller langsung, bukan subagent).

- [ ] **Step 1:** Full backend suite: `vendor/bin/phpunit` → OK semua (naik ±18 test dari baseline 268).
- [ ] **Step 2:** Rebuild + restart Docker backend & frontend (`docker compose -f docker-compose.local.yml build backend frontend && docker compose -f docker-compose.local.yml up -d --no-deps backend frontend`).
- [ ] **Step 3:** Cek boot log backend: migration 000001-000006 jalan `DONE`, server up.
- [ ] **Step 4:** Smoke test API live (login superadmin): `GET jenis-perawatan` = 200, `GET sparepart` = 200, `POST sparepart` + `POST sparepart/{id}/stok` + `GET sparepart/{id}/mutasi` = 200/201, `GET menu/tree` memuat "Pemeliharaan", "Jenis Perawatan", "Spare Part", dan "Perawatan Armada"/"Dokumen Armada" pindah ke bawah grup baru.
- [ ] **Step 5:** Smoke test frontend: `/jenis-perawatan`, `/sparepart`, `/perawatan-armada/baru` → 302 redirect login (normal).
- [ ] **Step 6:** Grep sisa referensi: tidak ada `Modal`/`Dialog` form tersisa utk create/edit di `perawatan-armada/page.tsx`; tidak ada Model Eloquent baru di `app/Modules/JenisPerawatan|Sparepart`.
- [ ] **Step 7:** JANGAN commit — laporkan daftar file staged kedua repo ke user.

