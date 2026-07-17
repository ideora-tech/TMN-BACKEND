# Dashboard Perawatan Armada & Dokumen Armada Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambah 2 menu sidebar baru — "Perawatan Armada" dan "Dokumen Armada" — masing-masing dashboard lintas-armada dengan full CRUD, dibangun di atas modul backend yang sudah ada (dikonversi ke Query Builder sekalian) plus satu endpoint list baru per modul.

**Architecture:** Backend: konversi `PerawatanArmada`/`DokumenArmada` dari Eloquent ke Query Builder (pola identik Kelompok 1/2 migrasi ORM sebelumnya), tambah `paginateByPerusahaan()` di masing-masing repository (join ke `armada`, attach `armada_nopol`), tambah 1 route `GET` top-level per modul. CRUD create/update/delete TIDAK dibuat baru — direuse dari endpoint nested yang sudah ada. Frontend: 2 halaman dashboard baru yang reuse service client yang sudah ada (ditambah method `listAll()`), field form identik dengan yang sudah ada di `/armada/[id]`.

**Tech Stack:** Laravel 11 (Query Builder, bukan Eloquent), Next.js 15 App Router, komponen Ecme UI (`Card`, `Dialog`, `Select`, `DataTable`, `ConfirmDialog`).

## Global Constraints

- **JANGAN commit apa pun ke git.** Stage dengan `git add` (path spesifik, bukan `-A`), biarkan di working tree. User commit manual.
- Repository backend WAJIB `DB::table()`, TIDAK BOLEH Eloquent Model baru, TIDAK BOLEH `SELECT *` (pakai `private const COLUMNS` eksplisit).
- Semua query lintas-armada WAJIB scope ke `id_perusahaan` (via join `armada`) dan `whereNull('dihapus_pada')` di kedua tabel yang di-join.
- Create/update/delete pakai `App\Support\RecordHelper::stampCreate/stampUpdate/stampDelete`.
- Istilah UI konsisten pakai "Perawatan" (bukan "Maintenance") — path `/perawatan-armada`, bukan `/maintenance-armada`.
- Endpoint `dokumen-armada/expiring` yang sudah ada TIDAK diubah bentuk responsnya.
- Form CRUD yang sudah ada di `/armada/[id]` TIDAK disentuh/diduplikasi strukturnya — endpoint nested-nya direuse apa adanya oleh dashboard baru.
- Backend test: `vendor/bin/phpunit` dari host (bukan `php artisan test`, bukan di dalam Docker).
- Frontend: tidak ada test runner otomatis established — verifikasi via `npx tsc --noEmit` + `npx eslint` pada file yang diubah.

---

### Task 1: Konversi `PerawatanArmada` ke Query Builder + endpoint list lintas-armada

**Files:**
- Modify: `app/Modules/PerawatanArmada/Contracts/PerawatanArmadaRepositoryInterface.php`
- Modify: `app/Modules/PerawatanArmada/PerawatanArmadaRepository.php`
- Modify: `app/Modules/PerawatanArmada/PerawatanArmadaService.php`
- Modify: `app/Modules/PerawatanArmada/PerawatanArmadaController.php`
- Modify: `app/Modules/PerawatanArmada/PerawatanArmadaServiceProvider.php`
- Modify: `app/Modules/PerawatanArmada/Resources/PerawatanArmadaResource.php`
- Delete: `app/Modules/PerawatanArmada/PerawatanArmadaModel.php`
- Create: `tests/Feature/PerawatanArmadaTest.php`

**Interfaces:**
- Consumes: `App\Support\RecordHelper::stampCreate(array $data, string $primaryKey): array`, `::stampUpdate(array $data): array`, `::stampDelete(): array` (sudah ada, tidak berubah).
- Produces: `PerawatanArmadaRepositoryInterface::paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idArmada, ?string $status): LengthAwarePaginator` — dipakai Task 7 untuk verifikasi endpoint. Route baru `GET /api/v1/perawatan-armada`.

- [ ] **Step 1: Tulis test yang akan gagal (baseline belum ada endpoint baru)**

Buat `tests/Feature/PerawatanArmadaTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerawatanArmadaTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(string $nopol = 'B 1234 XYZ'): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => $nopol,
            'merk'          => 'Hino',
        ]);
    }

    private function makePerawatan(string $idArmada, string $tanggal = '2026-01-10', string $status = 'selesai'): object
    {
        $id = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan'    => $id,
            'id_armada'       => $idArmada,
            'tanggal'         => $tanggal,
            'jenis_perawatan' => 'Ganti Oli',
            'biaya'           => 250000,
            'status'          => $status,
            'dibuat_pada'     => now(),
        ]);
        return DB::table('perawatan_armada')->where('id_perawatan', $id)->first();
    }

    public function test_create_perawatan_via_endpoint_nested_masih_berfungsi(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();

        $res = $this->postJson("/api/v1/armada/{$armada->id_armada}/perawatan", [
            'tanggal'                  => '2026-02-01',
            'jenis_perawatan'          => 'Servis Besar',
            'biaya'                    => 1500000,
            'km_odometer'              => 50000,
            'status'                   => 'selesai',
            'jadwal_servis_berikutnya' => '2026-08-01',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.jenis_perawatan', 'Servis Besar')
            ->assertJsonPath('data.biaya', 1500000.0);

        $this->assertDatabaseHas('perawatan_armada', [
            'id_armada'       => $armada->id_armada,
            'jenis_perawatan' => 'Servis Besar',
        ]);
    }

    public function test_update_dan_delete_perawatan_via_endpoint_nested_masih_berfungsi(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();
        $perawatan = $this->makePerawatan($armada->id_armada);

        $resUpdate = $this->putJson("/api/v1/armada/{$armada->id_armada}/perawatan/{$perawatan->id_perawatan}", [
            'status' => 'dalam_proses',
        ]);
        $resUpdate->assertStatus(200)->assertJsonPath('data.status', 'dalam_proses');

        $resDelete = $this->deleteJson("/api/v1/armada/{$armada->id_armada}/perawatan/{$perawatan->id_perawatan}");
        $resDelete->assertStatus(200);

        $this->assertSoftDeleted('perawatan_armada', ['id_perawatan' => $perawatan->id_perawatan]);
    }

    public function test_list_lintas_armada_scoped_ke_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');
        $armadaSendiri = $this->makeArmada('B 1111 AA');
        $this->makePerawatan($armadaSendiri->id_armada);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $idArmadaLain = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmadaLain, 'id_perusahaan' => $idPerusahaanLain,
            'nopol' => 'D 9999 ZZ', 'dibuat_pada' => now(),
        ]);
        $this->makePerawatan($idArmadaLain);

        $res = $this->getJson('/api/v1/perawatan-armada');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($armadaSendiri->id_armada, $data[0]['id_armada']);
        $this->assertSame('B 1111 AA', $data[0]['armada_nopol']);
    }

    public function test_list_lintas_armada_filter_id_armada_dan_status(): void
    {
        $this->actingAsRole('ADMIN');
        $armadaA = $this->makeArmada('B 1111 AA');
        $armadaB = $this->makeArmada('B 2222 BB');
        $this->makePerawatan($armadaA->id_armada, '2026-01-01', 'selesai');
        $this->makePerawatan($armadaB->id_armada, '2026-01-02', 'terjadwal');

        $resByArmada = $this->getJson("/api/v1/perawatan-armada?id_armada={$armadaA->id_armada}");
        $resByArmada->assertStatus(200);
        $this->assertCount(1, $resByArmada->json('data'));
        $this->assertSame($armadaA->id_armada, $resByArmada->json('data.0.id_armada'));

        $resByStatus = $this->getJson('/api/v1/perawatan-armada?status=terjadwal');
        $resByStatus->assertStatus(200);
        $this->assertCount(1, $resByStatus->json('data'));
        $this->assertSame('terjadwal', $resByStatus->json('data.0.status'));
    }

    public function test_list_lintas_armada_urut_tanggal_terbaru_dulu(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();
        $this->makePerawatan($armada->id_armada, '2026-01-01');
        $this->makePerawatan($armada->id_armada, '2026-03-01');

        $res = $this->getJson('/api/v1/perawatan-armada');

        $res->assertStatus(200);
        $this->assertSame('2026-03-01', $res->json('data.0.tanggal'));
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal (endpoint list belum ada)**

Run: `cd TMN-TRANSPORT-BACKEND && vendor/bin/phpunit tests/Feature/PerawatanArmadaTest.php`
Expected: `test_list_lintas_armada_scoped_ke_perusahaan_sendiri` dkk FAIL — route `GET /api/v1/perawatan-armada` belum terdaftar (404). Test `test_create_...`/`test_update_dan_delete_...` (endpoint nested) harus PASS karena belum diubah.

- [ ] **Step 3: Tulis Contract baru (object-based + method list baru)**

Ganti isi `app/Modules/PerawatanArmada/Contracts/PerawatanArmadaRepositoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PerawatanArmadaRepositoryInterface
{
    public function paginateByArmada(string $idArmada, int $page, int $limit): LengthAwarePaginator;
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idArmada, ?string $status): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
}
```

- [ ] **Step 4: Tulis Repository Query Builder baru**

Ganti isi `app/Modules/PerawatanArmada/PerawatanArmadaRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada;

use App\Modules\PerawatanArmada\Contracts\PerawatanArmadaRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PerawatanArmadaRepository implements PerawatanArmadaRepositoryInterface
{
    private const COLUMNS = [
        'perawatan_armada.id_perawatan', 'perawatan_armada.id_armada', 'perawatan_armada.tanggal',
        'perawatan_armada.jenis_perawatan', 'perawatan_armada.biaya', 'perawatan_armada.km_odometer',
        'perawatan_armada.status', 'perawatan_armada.jadwal_servis_berikutnya', 'perawatan_armada.keterangan',
        'perawatan_armada.dibuat_pada', 'perawatan_armada.dibuat_oleh',
        'perawatan_armada.diubah_pada', 'perawatan_armada.diubah_oleh',
        'perawatan_armada.dihapus_pada', 'perawatan_armada.dihapus_oleh',
    ];

    public function paginateByArmada(string $idArmada, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('perawatan_armada')
            ->whereNull('dihapus_pada')
            ->where('id_armada', $idArmada)
            ->orderByDesc('tanggal')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idArmada, ?string $status): LengthAwarePaginator
    {
        return DB::table('perawatan_armada')
            ->join('armada', 'armada.id_armada', '=', 'perawatan_armada.id_armada')
            ->where('armada.id_perusahaan', $idPerusahaan)
            ->whereNull('perawatan_armada.dihapus_pada')
            ->whereNull('armada.dihapus_pada')
            ->when($idArmada, fn ($q, $v) => $q->where('perawatan_armada.id_armada', $v))
            ->when($status, fn ($q, $v) => $q->where('perawatan_armada.status', $v))
            ->orderByDesc('perawatan_armada.tanggal')
            ->select(array_merge(self::COLUMNS, ['armada.nopol as armada_nopol', 'armada.merk as armada_merk']))
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('perawatan_armada')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perawatan', $id)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_perawatan');
        DB::table('perawatan_armada')->insert($data);
        return $this->findById($data['id_perawatan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('perawatan_armada')
            ->where('id_perawatan', $record->id_perawatan)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_perawatan);
    }

    public function delete(object $record): void
    {
        DB::table('perawatan_armada')
            ->where('id_perawatan', $record->id_perawatan)
            ->update(RecordHelper::stampDelete());
    }
}
```

- [ ] **Step 5: Tulis Service baru (object type hints + listByPerusahaan)**

Ganti isi `app/Modules/PerawatanArmada/PerawatanArmadaService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada;

use App\Modules\PerawatanArmada\Contracts\PerawatanArmadaRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

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
        return $record;
    }

    public function create(string $idArmada, array $data): object
    {
        return $this->repo->create(array_merge($data, ['id_armada' => $idArmada]));
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

- [ ] **Step 6: Tulis Resource baru (field date sebagai string biasa, tambah armada_nopol)**

Ganti isi `app/Modules/PerawatanArmada/Resources/PerawatanArmadaResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PerawatanArmadaResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_perawatan'             => $this->id_perawatan,
            'id_armada'                => $this->id_armada,
            'armada_nopol'             => $this->armada_nopol ?? null,
            'armada_merk'              => $this->armada_merk ?? null,
            'tanggal'                  => $this->tanggal,
            'jenis_perawatan'          => $this->jenis_perawatan,
            'biaya'                    => (float) $this->biaya,
            'km_odometer'              => $this->km_odometer !== null ? (int) $this->km_odometer : null,
            'status'                   => $this->status ?? 'selesai',
            'jadwal_servis_berikutnya' => $this->jadwal_servis_berikutnya,
            'keterangan'               => $this->keterangan,
            'dibuat_pada'              => $this->dibuat_pada,
            'diubah_pada'              => $this->diubah_pada,
        ];
    }
}
```

- [ ] **Step 7: Tambah method `index()` di Controller**

Di `app/Modules/PerawatanArmada/PerawatanArmadaController.php`, tambahkan method baru setelah `indexByArmada()`:

```php
    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->listByPerusahaan(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('id_armada'),
            $request->get('status')
        );

        return ApiResponse::paginated(
            PerawatanArmadaResource::collection($result['data']),
            $result['meta']
        );
    }
```

- [ ] **Step 8: Daftarkan route baru**

Di `app/Modules/PerawatanArmada/PerawatanArmadaServiceProvider.php`, tambah baris di dalam `boot()`'s route group, sebelum baris `armada/{idArmada}/perawatan`:

```php
                Route::get('perawatan-armada', [PerawatanArmadaController::class, 'index']);
```

- [ ] **Step 9: Hapus Model Eloquent**

Hapus file `app/Modules/PerawatanArmada/PerawatanArmadaModel.php` (`git rm` atau hapus manual — pastikan tidak ada referensi lain, sudah dicek di riset: hanya dipakai di Contract/Repository/Service yang sudah diupdate).

- [ ] **Step 10: Jalankan test, pastikan semua pass**

Run: `cd TMN-TRANSPORT-BACKEND && vendor/bin/phpunit tests/Feature/PerawatanArmadaTest.php --testdox`
Expected: `OK (5 tests, ...)` — semua 5 test pass, termasuk 2 test regresi nested endpoint.

- [ ] **Step 11: Jalankan full suite untuk cek regresi**

Run: `cd TMN-TRANSPORT-BACKEND && vendor/bin/phpunit`
Expected: `OK (...)` — tidak ada test lain yang gagal.

- [ ] **Step 12: Stage perubahan (JANGAN commit)**

```bash
git add app/Modules/PerawatanArmada tests/Feature/PerawatanArmadaTest.php
```

---

### Task 2: Konversi `DokumenArmada` ke Query Builder + endpoint list lintas-armada

**Files:**
- Modify: `app/Modules/DokumenArmada/Contracts/DokumenArmadaRepositoryInterface.php`
- Modify: `app/Modules/DokumenArmada/DokumenArmadaRepository.php`
- Modify: `app/Modules/DokumenArmada/DokumenArmadaService.php`
- Modify: `app/Modules/DokumenArmada/DokumenArmadaController.php`
- Modify: `app/Modules/DokumenArmada/DokumenArmadaServiceProvider.php`
- Modify: `app/Modules/DokumenArmada/Resources/DokumenArmadaResource.php`
- Delete: `app/Modules/DokumenArmada/DokumenArmadaModel.php`
- Create: `tests/Feature/DokumenArmadaTest.php`

**Interfaces:**
- Consumes: `RecordHelper` (sama seperti Task 1). Laravel `Storage` facade untuk upload file — TIDAK berubah, murni filesystem.
- Produces: `DokumenArmadaRepositoryInterface::paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idArmada, ?string $jenisDokumen): LengthAwarePaginator`. Route baru `GET /api/v1/dokumen-armada`.

- [ ] **Step 1: Tulis test yang akan gagal**

Buat `tests/Feature/DokumenArmadaTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DokumenArmadaTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(string $nopol = 'B 1234 XYZ'): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => $nopol,
            'merk'          => 'Hino',
        ]);
    }

    private function makeDokumen(string $idArmada, string $jenis = 'STNK', ?string $berlakuSampai = '2026-12-31'): object
    {
        $id = (string) Str::uuid();
        DB::table('dokumen_armada')->insert([
            'id_dokumen_armada' => $id,
            'id_armada'         => $idArmada,
            'jenis_dokumen'     => $jenis,
            'berlaku_sampai'    => $berlakuSampai,
            'dibuat_pada'       => now(),
        ]);
        return DB::table('dokumen_armada')->where('id_dokumen_armada', $id)->first();
    }

    public function test_create_dokumen_via_endpoint_nested_dengan_upload_file_masih_berfungsi(): void
    {
        Storage::fake('public');
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();

        $res = $this->post("/api/v1/armada/{$armada->id_armada}/dokumen", [
            'jenis_dokumen'  => 'KIR',
            'nomor'          => 'KIR-001',
            'berlaku_sampai' => '2027-01-01',
            'file'           => UploadedFile::fake()->create('kir.pdf', 100, 'application/pdf'),
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.jenis_dokumen', 'KIR');
        $this->assertNotNull($res->json('data.url_file'));

        $this->assertDatabaseHas('dokumen_armada', [
            'id_armada'     => $armada->id_armada,
            'jenis_dokumen' => 'KIR',
        ]);
    }

    public function test_update_dan_delete_dokumen_via_endpoint_nested_masih_berfungsi(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();
        $dokumen = $this->makeDokumen($armada->id_armada);

        $resUpdate = $this->putJson("/api/v1/armada/{$armada->id_armada}/dokumen/{$dokumen->id_dokumen_armada}", [
            'nomor' => 'STNK-UPDATED',
        ]);
        $resUpdate->assertStatus(200)->assertJsonPath('data.nomor', 'STNK-UPDATED');

        $resDelete = $this->deleteJson("/api/v1/armada/{$armada->id_armada}/dokumen/{$dokumen->id_dokumen_armada}");
        $resDelete->assertStatus(200);

        $this->assertSoftDeleted('dokumen_armada', ['id_dokumen_armada' => $dokumen->id_dokumen_armada]);
    }

    public function test_list_lintas_armada_scoped_ke_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');
        $armadaSendiri = $this->makeArmada('B 1111 AA');
        $this->makeDokumen($armadaSendiri->id_armada);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $idArmadaLain = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmadaLain, 'id_perusahaan' => $idPerusahaanLain,
            'nopol' => 'D 9999 ZZ', 'dibuat_pada' => now(),
        ]);
        $this->makeDokumen($idArmadaLain);

        $res = $this->getJson('/api/v1/dokumen-armada');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($armadaSendiri->id_armada, $data[0]['id_armada']);
        $this->assertSame('B 1111 AA', $data[0]['armada_nopol']);
    }

    public function test_list_lintas_armada_filter_id_armada_dan_jenis_dokumen(): void
    {
        $this->actingAsRole('ADMIN');
        $armadaA = $this->makeArmada('B 1111 AA');
        $armadaB = $this->makeArmada('B 2222 BB');
        $this->makeDokumen($armadaA->id_armada, 'STNK');
        $this->makeDokumen($armadaB->id_armada, 'KIR');

        $resByArmada = $this->getJson("/api/v1/dokumen-armada?id_armada={$armadaA->id_armada}");
        $resByArmada->assertStatus(200);
        $this->assertCount(1, $resByArmada->json('data'));

        $resByJenis = $this->getJson('/api/v1/dokumen-armada?jenis_dokumen=KIR');
        $resByJenis->assertStatus(200);
        $this->assertCount(1, $resByJenis->json('data'));
        $this->assertSame('KIR', $resByJenis->json('data.0.jenis_dokumen'));
    }

    public function test_endpoint_expiring_yang_sudah_ada_tidak_berubah_bentuk(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();
        $this->makeDokumen($armada->id_armada, 'STNK', now()->addDays(10)->toDateString());

        $res = $this->getJson('/api/v1/dokumen-armada/expiring?days=30');

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('STNK', $res->json('data.0.jenis_dokumen'));
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `cd TMN-TRANSPORT-BACKEND && vendor/bin/phpunit tests/Feature/DokumenArmadaTest.php`
Expected: `test_list_lintas_armada_...` FAIL (404, route belum ada). Test lain harus PASS (belum diubah).

- [ ] **Step 3: Tulis Contract baru**

Ganti isi `app/Modules/DokumenArmada/Contracts/DokumenArmadaRepositoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface DokumenArmadaRepositoryInterface
{
    public function paginateByArmada(string $idArmada, int $page, int $limit): LengthAwarePaginator;
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idArmada, ?string $jenisDokumen): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function findExpiring(string $idPerusahaan, int $days): array;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
}
```

- [ ] **Step 4: Tulis Repository Query Builder baru**

Ganti isi `app/Modules/DokumenArmada/DokumenArmadaRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada;

use App\Modules\DokumenArmada\Contracts\DokumenArmadaRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DokumenArmadaRepository implements DokumenArmadaRepositoryInterface
{
    private const COLUMNS = [
        'dokumen_armada.id_dokumen_armada', 'dokumen_armada.id_armada', 'dokumen_armada.jenis_dokumen',
        'dokumen_armada.nomor', 'dokumen_armada.berlaku_sampai', 'dokumen_armada.url_file',
        'dokumen_armada.dibuat_pada', 'dokumen_armada.dibuat_oleh',
        'dokumen_armada.diubah_pada', 'dokumen_armada.diubah_oleh',
        'dokumen_armada.dihapus_pada', 'dokumen_armada.dihapus_oleh',
    ];

    public function paginateByArmada(string $idArmada, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('dokumen_armada')
            ->whereNull('dihapus_pada')
            ->where('id_armada', $idArmada)
            ->orderBy('berlaku_sampai')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idArmada, ?string $jenisDokumen): LengthAwarePaginator
    {
        return DB::table('dokumen_armada')
            ->join('armada', 'armada.id_armada', '=', 'dokumen_armada.id_armada')
            ->where('armada.id_perusahaan', $idPerusahaan)
            ->whereNull('dokumen_armada.dihapus_pada')
            ->whereNull('armada.dihapus_pada')
            ->when($idArmada, fn ($q, $v) => $q->where('dokumen_armada.id_armada', $v))
            ->when($jenisDokumen, fn ($q, $v) => $q->where('dokumen_armada.jenis_dokumen', $v))
            ->orderBy('dokumen_armada.berlaku_sampai')
            ->select(array_merge(self::COLUMNS, ['armada.nopol as armada_nopol', 'armada.merk as armada_merk']))
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('dokumen_armada')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_dokumen_armada', $id)
            ->first();
    }

    public function findExpiring(string $idPerusahaan, int $days): array
    {
        return DB::table('dokumen_armada')
            ->join('armada', 'armada.id_armada', '=', 'dokumen_armada.id_armada')
            ->where('armada.id_perusahaan', $idPerusahaan)
            ->whereNull('dokumen_armada.dihapus_pada')
            ->whereNotNull('dokumen_armada.berlaku_sampai')
            ->where('dokumen_armada.berlaku_sampai', '<=', now()->addDays($days))
            ->select(self::COLUMNS)
            ->get()
            ->all();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_dokumen_armada');
        DB::table('dokumen_armada')->insert($data);
        return $this->findById($data['id_dokumen_armada']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('dokumen_armada')
            ->where('id_dokumen_armada', $record->id_dokumen_armada)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_dokumen_armada);
    }

    public function delete(object $record): void
    {
        DB::table('dokumen_armada')
            ->where('id_dokumen_armada', $record->id_dokumen_armada)
            ->update(RecordHelper::stampDelete());
    }
}
```

- [ ] **Step 5: Tulis Service baru (pertahankan logic upload file apa adanya)**

Ganti isi `app/Modules/DokumenArmada/DokumenArmadaService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada;

use App\Modules\DokumenArmada\Contracts\DokumenArmadaRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

class DokumenArmadaService
{
    public function __construct(private readonly DokumenArmadaRepositoryInterface $repo) {}

    public function listByArmada(string $idArmada, int $page = 1, int $limit = 100): array
    {
        return $this->toPagedArray($this->repo->paginateByArmada($idArmada, $page, $limit));
    }

    public function listByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idArmada, ?string $jenisDokumen): array
    {
        return $this->toPagedArray($this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $idArmada, $jenisDokumen));
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
            abort(404, 'Dokumen armada tidak ditemukan');
        }
        return $record;
    }

    public function getExpiring(string $idPerusahaan, int $days): array
    {
        return $this->repo->findExpiring($idPerusahaan, $days);
    }

    public function create(string $idArmada, array $data, ?UploadedFile $file = null): object
    {
        if ($file) {
            $path = $file->store('dokumen', 'public');
            $data['url_file'] = Storage::disk('public')->url($path);
        }
        unset($data['file']);
        return $this->repo->create(array_merge($data, ['id_armada' => $idArmada]));
    }

    public function update(string $id, array $data, ?UploadedFile $file = null): object
    {
        if ($file) {
            $path = $file->store('dokumen', 'public');
            $data['url_file'] = Storage::disk('public')->url($path);
        }
        unset($data['file']);
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

- [ ] **Step 6: Tulis Resource baru**

Ganti isi `app/Modules/DokumenArmada/Resources/DokumenArmadaResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DokumenArmadaResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_dokumen_armada' => $this->id_dokumen_armada,
            'id_armada'         => $this->id_armada,
            'armada_nopol'      => $this->armada_nopol ?? null,
            'armada_merk'       => $this->armada_merk ?? null,
            'jenis_dokumen'     => $this->jenis_dokumen,
            'nomor'             => $this->nomor,
            'berlaku_sampai'    => $this->berlaku_sampai,
            'url_file'          => $this->url_file,
            'dibuat_pada'       => $this->dibuat_pada,
            'diubah_pada'       => $this->diubah_pada,
        ];
    }
}
```

- [ ] **Step 7: Tambah method `index()` di Controller**

Di `app/Modules/DokumenArmada/DokumenArmadaController.php`, tambahkan method baru setelah `indexByArmada()`:

```php
    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->listByPerusahaan(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('id_armada'),
            $request->get('jenis_dokumen')
        );

        return ApiResponse::paginated(
            DokumenArmadaResource::collection($result['data']),
            $result['meta']
        );
    }
```

- [ ] **Step 8: Daftarkan route baru**

Di `app/Modules/DokumenArmada/DokumenArmadaServiceProvider.php`, tambah baris di dalam `boot()`'s route group, sebelum baris `armada/{idArmada}/dokumen`:

```php
                Route::get('dokumen-armada', [DokumenArmadaController::class, 'index']);
```

- [ ] **Step 9: Hapus Model Eloquent**

Hapus file `app/Modules/DokumenArmada/DokumenArmadaModel.php`.

- [ ] **Step 10: Jalankan test, pastikan semua pass**

Run: `cd TMN-TRANSPORT-BACKEND && vendor/bin/phpunit tests/Feature/DokumenArmadaTest.php --testdox`
Expected: `OK (6 tests, ...)`.

- [ ] **Step 11: Jalankan full suite untuk cek regresi**

Run: `cd TMN-TRANSPORT-BACKEND && vendor/bin/phpunit`
Expected: `OK (...)`.

- [ ] **Step 12: Stage perubahan**

```bash
git add app/Modules/DokumenArmada tests/Feature/DokumenArmadaTest.php
```

---

### Task 3: Seed menu sidebar + ikon navigasi

**Files:**
- Create: `database/migrations/2026_07_16_000002_seed_menu_perawatan_dokumen_armada.php`
- Modify: `TMN-TRANSPORT-FRONTEND/src/configs/navigation-icon.config.tsx`

**Interfaces:**
- Consumes: tabel `menu` (`id_menu`, `nama_menu`, `path`, `icon`, `id_menu_induk`, `urutan`, `aktif`) dan `menu_peran` (`id_menu`, `kode_peran`) — sudah ada.
- Produces: 2 baris menu baru (`id_menu` = `m0000001-0000-4000-8000-000000000028` untuk Perawatan Armada, `...029` untuk Dokumen Armada), dipakai Task 7 untuk verifikasi sidebar.

- [ ] **Step 1: Buat migration seed menu**

Buat `database/migrations/2026_07_16_000002_seed_menu_perawatan_dokumen_armada.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idPerawatanArmada = 'm0000001-0000-4000-8000-000000000028';
    private string $idDokumenArmada   = 'm0000001-0000-4000-8000-000000000029';
    private string $idOperasional     = 'm0000001-0000-4000-8000-000000000020';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu'       => $this->idPerawatanArmada,
                'nama_menu'     => 'Perawatan Armada',
                'path'          => '/perawatan-armada',
                'icon'          => 'wrench',
                'id_menu_induk' => $this->idOperasional,
                'urutan'        => 8,
                'aktif'         => 1,
                'dibuat_pada'   => $now,
                'dibuat_oleh'   => null,
            ],
            [
                'id_menu'       => $this->idDokumenArmada,
                'nama_menu'     => 'Dokumen Armada',
                'path'          => '/dokumen-armada',
                'icon'          => 'fileText',
                'id_menu_induk' => $this->idOperasional,
                'urutan'        => 9,
                'aktif'         => 1,
                'dibuat_pada'   => $now,
                'dibuat_oleh'   => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idPerawatanArmada, 'kode_peran' => 'DISPATCHER'],
            ['id_menu' => $this->idPerawatanArmada, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idPerawatanArmada, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idPerawatanArmada, 'kode_peran' => 'SUPERADMIN'],
            ['id_menu' => $this->idDokumenArmada, 'kode_peran' => 'DISPATCHER'],
            ['id_menu' => $this->idDokumenArmada, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idDokumenArmada, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idDokumenArmada, 'kode_peran' => 'SUPERADMIN'],
        ]);
    }

    public function down(): void
    {
        DB::table('menu_peran')->whereIn('id_menu', [$this->idPerawatanArmada, $this->idDokumenArmada])->delete();
        DB::table('menu')->whereIn('id_menu', [$this->idPerawatanArmada, $this->idDokumenArmada])->delete();
    }
};
```

- [ ] **Step 2: Jalankan migration di host (SQLite test DB terpisah dari Docker MySQL, migration jalan otomatis saat test — cukup pastikan tidak error saat load)**

Run: `cd TMN-TRANSPORT-BACKEND && vendor/bin/phpunit tests/Feature/PerawatanArmadaTest.php` (test suite yang mana pun cukup — `RefreshDatabase` akan menjalankan migration ini otomatis di SQLite in-memory)
Expected: tidak ada error migration (test tetap PASS seperti Task 1/2, migration baru berhasil di-load tanpa syntax/constraint error).

- [ ] **Step 3: Tambah 2 ikon baru di navigation-icon.config.tsx**

Di `TMN-TRANSPORT-FRONTEND/src/configs/navigation-icon.config.tsx`, tambah import di baris import (setelah `PiGasPumpDuotone`):

```tsx
    PiGasPumpDuotone,
    PiWrenchDuotone,
    PiFileTextDuotone,
} from 'react-icons/pi'
```

Tambah entry di object `navigationIcon` (setelah `gasPump`):

```tsx
    gasPump:       <PiGasPumpDuotone />,
    wrench:        <PiWrenchDuotone />,
    fileText:      <PiFileTextDuotone />,
}
```

- [ ] **Step 4: Type-check frontend**

Run: `cd TMN-TRANSPORT-FRONTEND && npx tsc --noEmit -p tsconfig.json`
Expected: tidak ada error terkait `navigation-icon.config.tsx` (import `PiWrenchDuotone`/`PiFileTextDuotone` valid dari paket `react-icons/pi`).

- [ ] **Step 5: Stage perubahan**

```bash
cd TMN-TRANSPORT-BACKEND && git add database/migrations/2026_07_16_000002_seed_menu_perawatan_dokumen_armada.php
cd ../TMN-TRANSPORT-FRONTEND && git add src/configs/navigation-icon.config.tsx
```

---

### Task 4: Konstanta & service client frontend

**Files:**
- Modify: `TMN-TRANSPORT-FRONTEND/src/constants/api.constant.ts`
- Modify: `TMN-TRANSPORT-FRONTEND/src/constants/route.constant.ts`
- Modify: `TMN-TRANSPORT-FRONTEND/src/configs/routes.config/routes.config.ts`
- Modify: `TMN-TRANSPORT-FRONTEND/src/services/perawatanArmada.service.ts`
- Modify: `TMN-TRANSPORT-FRONTEND/src/services/dokumenArmada.service.ts`

**Interfaces:**
- Produces: `API_ENDPOINTS.PERAWATAN_ARMADA: string`, `API_ENDPOINTS.DOKUMEN_ARMADA: string`, `ROUTES.PERAWATAN_ARMADA: string`, `ROUTES.DOKUMEN_ARMADA: string`, `perawatanArmadaService.listAll(params): Promise<{data: PerawatanArmadaWithArmada[], meta}>`, `dokumenArmadaService.listAll(params): Promise<{data: DokumenArmadaWithArmada[], meta}>` — dikonsumsi Task 5 & 6.

- [ ] **Step 1: Tambah endpoint di api.constant.ts**

Di `TMN-TRANSPORT-FRONTEND/src/constants/api.constant.ts`, cari baris `ARMADA_PERAWATAN_DETAIL` (sudah ada) dan tambah setelahnya:

```ts
    ARMADA_PERAWATAN_DETAIL:(idArmada: string, id: string) => `/api/proxy/armada/${idArmada}/perawatan/${id}`,
    PERAWATAN_ARMADA:       '/api/proxy/perawatan-armada',
```

Cari baris `ARMADA_DOKUMEN_DELETE` (sudah ada) dan tambah setelahnya:

```ts
    ARMADA_DOKUMEN_DELETE: (idArmada: string, id: string) => `/api/proxy/armada/${idArmada}/dokumen/${id}`,
    DOKUMEN_ARMADA:        '/api/proxy/dokumen-armada',
```

- [ ] **Step 2: Tambah route di route.constant.ts**

Di `TMN-TRANSPORT-FRONTEND/src/constants/route.constant.ts`, cari baris `ARMADA_DETAIL` dan tambah setelahnya:

```ts
    ARMADA_DETAIL: (id: string) => `/armada/${id}`,
    PERAWATAN_ARMADA: '/perawatan-armada',
    DOKUMEN_ARMADA:   '/dokumen-armada',
```

- [ ] **Step 3: Tambah route di routes.config.ts**

Di `TMN-TRANSPORT-FRONTEND/src/configs/routes.config/routes.config.ts`, cari baris `...listRoute('armada', 'armada'),` dan tambah setelahnya:

```ts
    ...listRoute('armada', 'armada'),
    '/perawatan-armada': { key: 'perawatan-armada', authority: [] },
    '/dokumen-armada':   { key: 'dokumen-armada', authority: [] },
```

- [ ] **Step 4: Tambah `listAll()` di perawatanArmada.service.ts**

Di `TMN-TRANSPORT-FRONTEND/src/services/perawatanArmada.service.ts`, tambah interface baru setelah `PerawatanArmada` dan method `listAll` di object `perawatanArmadaService`:

```ts
export interface PerawatanArmadaWithArmada extends PerawatanArmada {
    armada_nopol: string | null
    armada_merk: string | null
}

export const perawatanArmadaService = {
    async listAll(params?: { page?: number; limit?: number; id_armada?: string; status?: StatusPerawatan | '' }) {
        const { data } = await axios.get(API_ENDPOINTS.PERAWATAN_ARMADA, { params })
        return data as { data: PerawatanArmadaWithArmada[]; meta: { page: number; total: number; totalPages: number; limit: number } }
    },
    async list(idArmada: string) {
        const { data } = await axios.get(API_ENDPOINTS.ARMADA_PERAWATAN(idArmada))
        return data.data as PerawatanArmada[]
    },
    async create(idArmada: string, payload: PerawatanPayload) {
        const { data } = await axios.post(API_ENDPOINTS.ARMADA_PERAWATAN(idArmada), payload)
        return data.data as PerawatanArmada
    },
    async update(idArmada: string, id: string, payload: Partial<PerawatanPayload>) {
        const { data } = await axios.put(API_ENDPOINTS.ARMADA_PERAWATAN_DETAIL(idArmada, id), payload)
        return data.data as PerawatanArmada
    },
    async delete(idArmada: string, id: string) {
        await axios.delete(API_ENDPOINTS.ARMADA_PERAWATAN_DETAIL(idArmada, id))
    },
}
```

(Catatan: ini menggantikan seluruh blok `export const perawatanArmadaService = { ... }` yang sudah ada — method `list`/`create`/`update`/`delete` isinya identik dengan sebelumnya, hanya ditambah `listAll` di awal.)

- [ ] **Step 5: Tambah `listAll()` di dokumenArmada.service.ts**

Di `TMN-TRANSPORT-FRONTEND/src/services/dokumenArmada.service.ts`, tambah interface baru setelah `DokumenArmada` dan method `listAll`:

```ts
export interface DokumenArmadaWithArmada extends DokumenArmada {
    armada_nopol: string | null
    armada_merk: string | null
}

export const dokumenArmadaService = {
    async listAll(params?: { page?: number; limit?: number; id_armada?: string; jenis_dokumen?: string }) {
        const { data } = await axios.get(API_ENDPOINTS.DOKUMEN_ARMADA, { params })
        return data as { data: DokumenArmadaWithArmada[]; meta: { page: number; total: number; totalPages: number; limit: number } }
    },

    async list(idArmada: string) {
        const { data } = await axios.get(API_ENDPOINTS.ARMADA_DOKUMEN(idArmada))
        return data.data as DokumenArmada[]
    },

    async create(idArmada: string, payload: DocPayload, file?: File | null) {
        const body = file ? buildFormData(payload, file) : payload
        const { data } = await axios.post(API_ENDPOINTS.ARMADA_DOKUMEN(idArmada), body)
        return data.data as DokumenArmada
    },

    async update(idArmada: string, id: string, payload: Partial<DocPayload>, file?: File | null) {
        if (file) {
            const fd = buildFormData({ jenis_dokumen: payload.jenis_dokumen ?? '', ...payload }, file)
            fd.append('_method', 'PUT')
            const { data } = await axios.post(API_ENDPOINTS.ARMADA_DOKUMEN_UPDATE(idArmada, id), fd)
            return data.data as DokumenArmada
        }
        const { data } = await axios.put(API_ENDPOINTS.ARMADA_DOKUMEN_UPDATE(idArmada, id), payload)
        return data.data as DokumenArmada
    },

    async delete(idArmada: string, id: string) {
        await axios.delete(API_ENDPOINTS.ARMADA_DOKUMEN_DELETE(idArmada, id))
    },
}
```

(Catatan: menggantikan seluruh blok `export const dokumenArmadaService = { ... }` yang sudah ada — method lama isinya identik, hanya ditambah `listAll` di awal.)

- [ ] **Step 6: Type-check frontend**

Run: `cd TMN-TRANSPORT-FRONTEND && npx tsc --noEmit -p tsconfig.json`
Expected: tidak ada error baru terkait file-file yang diubah di step 1-5.

- [ ] **Step 7: Stage perubahan**

```bash
git add src/constants/api.constant.ts src/constants/route.constant.ts src/configs/routes.config/routes.config.ts src/services/perawatanArmada.service.ts src/services/dokumenArmada.service.ts
```

---

### Task 5: Halaman dashboard `/perawatan-armada`

**Files:**
- Create: `TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/perawatan-armada/page.tsx`

**Interfaces:**
- Consumes: `perawatanArmadaService.listAll/create/update/delete` (Task 4), `armadaService.list` (sudah ada, `src/services/armada.service.ts`), `PerawatanArmadaWithArmada`, `StatusPerawatan` (Task 4).

- [ ] **Step 1: Buat halaman**

Buat `TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/perawatan-armada/page.tsx`:

```tsx
'use client'
import { useEffect, useState, useCallback } from 'react'
import { Card, Button, Dialog, FormItem, Input, DatePicker, Tag, Tooltip, toast, Notification } from '@/components/ui'
import Select from '@/components/ui/Select'
import ConfirmDialog from '@/components/shared/ConfirmDialog'
import DataTable from '@/components/shared/DataTable'
import type { ColumnDef, CellContext } from '@/components/shared/DataTable'
import { HiOutlineSearch, HiOutlineX, HiOutlinePlus, HiOutlinePencilAlt, HiOutlineTrash } from 'react-icons/hi'
import dayjs from 'dayjs'
import { parseApiError } from '@/utils/error.util'
import { formatRupiah, formatNum } from '@/utils/formatNumber'
import { perawatanArmadaService, PerawatanArmadaWithArmada, StatusPerawatan } from '@/services/perawatanArmada.service'
import { armadaService, Armada } from '@/services/armada.service'

type Option = { value: string; label: string }

const STATUS_OPTIONS: { value: StatusPerawatan | ''; label: string }[] = [
    { value: '',             label: 'Semua Status' },
    { value: 'terjadwal',    label: 'Terjadwal' },
    { value: 'dalam_proses', label: 'Dalam Proses' },
    { value: 'selesai',      label: 'Selesai' },
]

const FORM_STATUS_OPTIONS: { value: StatusPerawatan; label: string }[] = [
    { value: 'terjadwal',    label: 'Terjadwal' },
    { value: 'dalam_proses', label: 'Dalam Proses' },
    { value: 'selesai',      label: 'Selesai' },
]

const STATUS_CLASS: Record<string, string> = {
    terjadwal:    'bg-yellow-100 text-yellow-700 dark:bg-yellow-500/20 dark:text-yellow-400',
    dalam_proses: 'bg-blue-100 text-blue-600 dark:bg-blue-500/20 dark:text-blue-400',
    selesai:      'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400',
}

function getServisBadge(tanggal: string | null): { label: string; className: string } | null {
    if (!tanggal) return null
    const days = Math.ceil((new Date(tanggal).getTime() - Date.now()) / 86400000)
    if (days < 0)  return { label: 'Lewat jadwal', className: 'bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-400' }
    if (days <= 30) return { label: `${days} hari lagi`, className: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400' }
    return null
}

type RawatForm = {
    id_armada: string
    tanggal: string
    jenis_perawatan: string
    biaya: string
    km_odometer: string
    status: StatusPerawatan
    jadwal_servis_berikutnya: string
    keterangan: string
}

const emptyForm = (): RawatForm => ({
    id_armada: '', tanggal: '', jenis_perawatan: '', biaya: '', km_odometer: '',
    status: 'selesai', jadwal_servis_berikutnya: '', keterangan: '',
})

export default function PerawatanArmadaPage() {
    const [list, setList]       = useState<PerawatanArmadaWithArmada[]>([])
    const [loading, setLoading] = useState(false)
    const [armadaOptions, setArmadaOptions] = useState<Option[]>([])

    const [searchInput, setSearchInput]   = useState('')
    const [search, setSearch]             = useState('')
    const [armadaFilter, setArmadaFilter] = useState('')
    const [statusFilter, setStatusFilter] = useState<StatusPerawatan | ''>('')
    const [currentPage, setCurrentPage]   = useState(1)
    const [pageSize, setPageSize]         = useState(10)
    const [total, setTotal]               = useState(0)

    const [showForm, setShowForm]         = useState(false)
    const [form, setForm]                 = useState<RawatForm>(emptyForm())
    const [saving, setSaving]             = useState(false)
    const [editTarget, setEditTarget]     = useState<PerawatanArmadaWithArmada | null>(null)
    const [deleteTarget, setDeleteTarget] = useState<PerawatanArmadaWithArmada | null>(null)
    const [deleting, setDeleting]         = useState(false)

    const fetchData = useCallback(async () => {
        setLoading(true)
        try {
            const res = await perawatanArmadaService.listAll({
                page: currentPage, limit: pageSize,
                id_armada: armadaFilter || undefined,
                status: statusFilter || undefined,
            })
            setList(res.data)
            setTotal(res.meta.total)
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setLoading(false)
        }
    }, [currentPage, pageSize, armadaFilter, statusFilter])

    useEffect(() => { fetchData() }, [fetchData])

    useEffect(() => {
        armadaService.list(1, 100).then(res => {
            setArmadaOptions(res.data.map((a: Armada) => ({ value: a.id_armada, label: a.nopol })))
        }).catch(() => {})
    }, [])

    const handleSearchSubmit = () => setSearch(searchInput)
    const handleSearchClear  = () => { setSearchInput(''); setSearch('') }

    const filteredList = list.filter(p => {
        if (!search) return true
        const q = search.toLowerCase()
        return p.jenis_perawatan.toLowerCase().includes(q) || (p.armada_nopol ?? '').toLowerCase().includes(q)
    })

    const openAdd = () => { setForm(emptyForm()); setShowForm(true) }

    const openEdit = (p: PerawatanArmadaWithArmada) => {
        setEditTarget(p)
        setForm({
            id_armada: p.id_armada,
            tanggal: p.tanggal,
            jenis_perawatan: p.jenis_perawatan,
            biaya: String(p.biaya),
            km_odometer: p.km_odometer != null ? String(p.km_odometer) : '',
            status: p.status,
            jadwal_servis_berikutnya: p.jadwal_servis_berikutnya ?? '',
            keterangan: p.keterangan ?? '',
        })
    }

    const closeForm = () => { setShowForm(false); setEditTarget(null) }

    const handleSubmit = async () => {
        if (!form.id_armada || !form.tanggal || !form.jenis_perawatan) return
        setSaving(true)
        try {
            const payload = {
                tanggal: form.tanggal,
                jenis_perawatan: form.jenis_perawatan,
                biaya: Number(form.biaya) || 0,
                km_odometer: form.km_odometer ? Number(form.km_odometer) : null,
                status: form.status,
                jadwal_servis_berikutnya: form.jadwal_servis_berikutnya || null,
                keterangan: form.keterangan || null,
            }
            if (editTarget) {
                await perawatanArmadaService.update(editTarget.id_armada, editTarget.id_perawatan, payload)
                toast.push(<Notification type="success" title="Perawatan berhasil diperbarui" />)
            } else {
                await perawatanArmadaService.create(form.id_armada, payload)
                toast.push(<Notification type="success" title="Perawatan berhasil dicatat" />)
            }
            closeForm()
            fetchData()
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setSaving(false)
        }
    }

    const handleDelete = async () => {
        if (!deleteTarget) return
        setDeleting(true)
        try {
            await perawatanArmadaService.delete(deleteTarget.id_armada, deleteTarget.id_perawatan)
            toast.push(<Notification type="success" title="Data perawatan berhasil dihapus" />)
            setDeleteTarget(null)
            fetchData()
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setDeleting(false)
        }
    }

    const columns: ColumnDef<PerawatanArmadaWithArmada>[] = [
        {
            header: 'No', id: 'no', size: 60,
            cell: ({ row }: CellContext<PerawatanArmadaWithArmada, unknown>) =>
                (currentPage - 1) * pageSize + row.index + 1,
        },
        {
            header: 'Armada', accessorKey: 'armada_nopol', size: 130,
            cell: ({ row }: CellContext<PerawatanArmadaWithArmada, unknown>) => (
                <span className="font-mono text-xs font-semibold">{row.original.armada_nopol ?? '—'}</span>
            ),
        },
        {
            header: 'Tanggal', accessorKey: 'tanggal', size: 120,
            cell: ({ row }: CellContext<PerawatanArmadaWithArmada, unknown>) =>
                dayjs(row.original.tanggal).format('DD MMM YYYY'),
        },
        { header: 'Jenis Perawatan', accessorKey: 'jenis_perawatan', size: 180 },
        {
            header: 'Biaya', accessorKey: 'biaya', size: 130,
            cell: ({ row }: CellContext<PerawatanArmadaWithArmada, unknown>) => formatRupiah(row.original.biaya),
        },
        {
            header: 'KM Odometer', accessorKey: 'km_odometer', size: 120,
            cell: ({ row }: CellContext<PerawatanArmadaWithArmada, unknown>) =>
                row.original.km_odometer != null
                    ? <span className="font-mono text-xs">{formatNum(row.original.km_odometer)} km</span>
                    : <span className="text-gray-400">—</span>,
        },
        {
            header: 'Servis Berikutnya', accessorKey: 'jadwal_servis_berikutnya', size: 160,
            cell: ({ row }: CellContext<PerawatanArmadaWithArmada, unknown>) => {
                const tgl = row.original.jadwal_servis_berikutnya
                if (!tgl) return <span className="text-gray-400">—</span>
                const badge = getServisBadge(tgl)
                return (
                    <div>
                        <p className="text-xs">{dayjs(tgl).format('DD MMM YYYY')}</p>
                        {badge && <Tag className={`text-xs font-semibold mt-1 ${badge.className}`}>{badge.label}</Tag>}
                    </div>
                )
            },
        },
        {
            header: 'Status', accessorKey: 'status', size: 130,
            cell: ({ row }: CellContext<PerawatanArmadaWithArmada, unknown>) => (
                <Tag className={`text-xs font-semibold ${STATUS_CLASS[row.original.status] ?? 'bg-gray-100 text-gray-600'}`}>
                    {row.original.status}
                </Tag>
            ),
        },
        {
            header: '', id: 'action', size: 90,
            cell: ({ row }: CellContext<PerawatanArmadaWithArmada, unknown>) => (
                <div className="flex items-center justify-end gap-1">
                    <Tooltip title="Edit">
                        <span
                            className="cursor-pointer inline-flex items-center justify-center w-8 h-8 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100 dark:bg-blue-500/20 dark:text-blue-300 dark:hover:bg-blue-500/30 transition-colors"
                            onClick={() => openEdit(row.original)}>
                            <HiOutlinePencilAlt className="text-lg" />
                        </span>
                    </Tooltip>
                    <Tooltip title="Hapus">
                        <span
                            className="cursor-pointer inline-flex items-center justify-center w-8 h-8 rounded-lg bg-red-50 text-red-500 hover:bg-red-100 dark:bg-red-500/10 dark:hover:bg-red-500/20 transition-colors"
                            onClick={() => setDeleteTarget(row.original)}>
                            <HiOutlineTrash className="text-lg" />
                        </span>
                    </Tooltip>
                </div>
            ),
        },
    ]

    const isFormOpen = showForm || editTarget !== null

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center justify-between">
                <div>
                    <h3 className="font-bold">Perawatan Armada</h3>
                    <p className="text-gray-500 text-sm mt-0.5">Riwayat perawatan seluruh armada</p>
                </div>
                <Button variant="solid" icon={<HiOutlinePlus />} onClick={openAdd}>Catat Perawatan</Button>
            </div>
            <Card bodyClass="p-0">
                <div className="flex flex-col sm:flex-row items-center gap-3 px-4 py-3">
                    <Input
                        className="flex-1"
                        placeholder="Cari jenis perawatan atau nopol... (tekan Enter)"
                        suffix={
                            searchInput
                                ? <HiOutlineX className="text-gray-400 text-lg cursor-pointer hover:text-gray-600" onClick={handleSearchClear} />
                                : <HiOutlineSearch className="text-gray-400 text-lg cursor-pointer hover:text-gray-600" onClick={handleSearchSubmit} />
                        }
                        value={searchInput}
                        onChange={(e) => setSearchInput(e.target.value)}
                        onKeyDown={(e) => { if (e.key === 'Enter') handleSearchSubmit() }}
                    />
                    <div className="w-full sm:w-52 shrink-0">
                        <Select
                            placeholder="Semua Armada"
                            isClearable
                            options={armadaOptions}
                            value={armadaOptions.find(o => o.value === armadaFilter) ?? null}
                            onChange={(opt) => { setArmadaFilter((opt as Option | null)?.value ?? ''); setCurrentPage(1) }}
                        />
                    </div>
                    <div className="w-full sm:w-44 shrink-0">
                        <Select
                            isSearchable={false}
                            options={STATUS_OPTIONS}
                            value={STATUS_OPTIONS.find(o => o.value === statusFilter) ?? STATUS_OPTIONS[0]}
                            onChange={(opt) => { setStatusFilter((opt as { value: StatusPerawatan | '' }).value); setCurrentPage(1) }}
                        />
                    </div>
                </div>
                <DataTable
                    columns={columns}
                    data={filteredList as unknown[]}
                    loading={loading}
                    noData={!loading && filteredList.length === 0}
                    pagingData={{ total, pageIndex: currentPage, pageSize }}
                    onPaginationChange={setCurrentPage}
                    onSelectChange={(size) => { setPageSize(size); setCurrentPage(1) }}
                />
            </Card>

            <Dialog isOpen={isFormOpen} onRequestClose={closeForm} width={600}>
                <h5 className="text-base font-semibold mb-5">{editTarget ? 'Edit Perawatan' : 'Catat Perawatan'}</h5>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
                    <div className="sm:col-span-2">
                        <FormItem label="Armada" asterisk>
                            <Select
                                placeholder="Pilih armada..."
                                isDisabled={!!editTarget}
                                options={armadaOptions}
                                value={armadaOptions.find(o => o.value === form.id_armada) ?? null}
                                onChange={(opt) => setForm(p => ({ ...p, id_armada: (opt as Option | null)?.value ?? '' }))}
                            />
                        </FormItem>
                    </div>
                    <FormItem label="Tanggal" asterisk>
                        <DatePicker
                            value={form.tanggal ? new Date(form.tanggal) : null}
                            onChange={date => setForm(p => ({ ...p, tanggal: date ? dayjs(date).format('YYYY-MM-DD') : '' }))} />
                    </FormItem>
                    <FormItem label="Jenis Perawatan" asterisk>
                        <Input placeholder="Contoh: Ganti Oli, Tune Up..." value={form.jenis_perawatan}
                            onChange={e => setForm(p => ({ ...p, jenis_perawatan: e.target.value }))} />
                    </FormItem>
                    <FormItem label="Biaya (Rp)">
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
                            value={FORM_STATUS_OPTIONS.find(o => o.value === form.status) ?? null}
                            options={FORM_STATUS_OPTIONS}
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
                <div className="flex justify-end gap-2 mt-6">
                    <Button variant="plain" onClick={closeForm}>Batal</Button>
                    <Button variant="solid" loading={saving}
                        disabled={!form.id_armada || !form.tanggal || !form.jenis_perawatan}
                        onClick={handleSubmit}>Simpan</Button>
                </div>
            </Dialog>

            <ConfirmDialog
                isOpen={!!deleteTarget}
                type="danger"
                title="Hapus Data Perawatan"
                confirmText="Ya, Hapus"
                cancelText="Batal"
                onClose={() => setDeleteTarget(null)}
                onCancel={() => setDeleteTarget(null)}
                onConfirm={handleDelete}
                confirmButtonProps={{ loading: deleting }}
            >
                <p>Hapus data perawatan &quot;{deleteTarget?.jenis_perawatan}&quot; untuk armada {deleteTarget?.armada_nopol}?</p>
            </ConfirmDialog>
        </div>
    )
}
```

- [ ] **Step 2: Type-check**

Run: `cd TMN-TRANSPORT-FRONTEND && npx tsc --noEmit -p tsconfig.json`
Expected: tidak ada error di `perawatan-armada/page.tsx`.

- [ ] **Step 3: Lint**

Run: `cd TMN-TRANSPORT-FRONTEND && npx eslint "src/app/(protected-pages)/perawatan-armada/page.tsx"`
Expected: tidak ada error/warning.

- [ ] **Step 4: Stage perubahan**

```bash
git add "src/app/(protected-pages)/perawatan-armada"
```

---

### Task 6: Halaman dashboard `/dokumen-armada`

**Files:**
- Create: `TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/dokumen-armada/page.tsx`

**Interfaces:**
- Consumes: `dokumenArmadaService.listAll/create/update/delete` (Task 4), `armadaService.list` (sudah ada), `DokumenArmadaWithArmada` (Task 4).

- [ ] **Step 1: Buat halaman**

Buat `TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/dokumen-armada/page.tsx`:

```tsx
'use client'
import { useEffect, useState, useCallback } from 'react'
import { Card, Button, Dialog, FormItem, Input, DatePicker, Upload, Tag, Tooltip, toast, Notification } from '@/components/ui'
import Select from '@/components/ui/Select'
import ConfirmDialog from '@/components/shared/ConfirmDialog'
import DataTable from '@/components/shared/DataTable'
import type { ColumnDef, CellContext } from '@/components/shared/DataTable'
import { HiOutlineSearch, HiOutlineX, HiOutlinePlus, HiOutlinePencilAlt, HiOutlineTrash, HiOutlineDocumentText } from 'react-icons/hi'
import dayjs from 'dayjs'
import { parseApiError } from '@/utils/error.util'
import { dokumenArmadaService, DokumenArmadaWithArmada } from '@/services/dokumenArmada.service'
import { armadaService, Armada } from '@/services/armada.service'

type Option = { value: string; label: string }

const JENIS_DOKUMEN_OPTIONS: Option[] = [
    { value: 'STNK',     label: 'STNK' },
    { value: 'KIR',      label: 'KIR' },
    { value: 'Asuransi', label: 'Asuransi' },
    { value: 'BPKB',     label: 'BPKB' },
    { value: 'Pajak',    label: 'Pajak Kendaraan' },
    { value: 'Lainnya',  label: 'Lainnya' },
]

const JENIS_FILTER_OPTIONS: Option[] = [{ value: '', label: 'Semua Jenis' }, ...JENIS_DOKUMEN_OPTIONS]

function getExpiryInfo(berlakuSampai: string | null): { label: string; className: string } {
    if (!berlakuSampai) return { label: '—', className: 'bg-gray-100 text-gray-400' }
    const days = Math.ceil((new Date(berlakuSampai).getTime() - Date.now()) / 86400000)
    if (days < 0)   return { label: 'Kadaluarsa', className: 'bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-400' }
    if (days <= 14) return { label: `${days} hari lagi`, className: 'bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-400' }
    if (days <= 30) return { label: `${days} hari lagi`, className: 'bg-orange-100 text-orange-600 dark:bg-orange-500/20 dark:text-orange-400' }
    if (days <= 60) return { label: `${days} hari lagi`, className: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-500/20 dark:text-yellow-400' }
    return { label: `${days} hari lagi`, className: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400' }
}

type DocForm = { id_armada: string; jenis_dokumen: string; nomor: string; berlaku_sampai: string }

const emptyForm = (): DocForm => ({ id_armada: '', jenis_dokumen: '', nomor: '', berlaku_sampai: '' })

export default function DokumenArmadaPage() {
    const [list, setList]       = useState<DokumenArmadaWithArmada[]>([])
    const [loading, setLoading] = useState(false)
    const [armadaOptions, setArmadaOptions] = useState<Option[]>([])

    const [searchInput, setSearchInput]   = useState('')
    const [search, setSearch]             = useState('')
    const [armadaFilter, setArmadaFilter] = useState('')
    const [jenisFilter, setJenisFilter]   = useState('')
    const [currentPage, setCurrentPage]   = useState(1)
    const [pageSize, setPageSize]         = useState(10)
    const [total, setTotal]               = useState(0)

    const [showForm, setShowForm]         = useState(false)
    const [form, setForm]                 = useState<DocForm>(emptyForm())
    const [file, setFile]                 = useState<File | null>(null)
    const [saving, setSaving]             = useState(false)
    const [editTarget, setEditTarget]     = useState<DokumenArmadaWithArmada | null>(null)
    const [deleteTarget, setDeleteTarget] = useState<DokumenArmadaWithArmada | null>(null)
    const [deleting, setDeleting]         = useState(false)

    const fetchData = useCallback(async () => {
        setLoading(true)
        try {
            const res = await dokumenArmadaService.listAll({
                page: currentPage, limit: pageSize,
                id_armada: armadaFilter || undefined,
                jenis_dokumen: jenisFilter || undefined,
            })
            setList(res.data)
            setTotal(res.meta.total)
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setLoading(false)
        }
    }, [currentPage, pageSize, armadaFilter, jenisFilter])

    useEffect(() => { fetchData() }, [fetchData])

    useEffect(() => {
        armadaService.list(1, 100).then(res => {
            setArmadaOptions(res.data.map((a: Armada) => ({ value: a.id_armada, label: a.nopol })))
        }).catch(() => {})
    }, [])

    const handleSearchSubmit = () => setSearch(searchInput)
    const handleSearchClear  = () => { setSearchInput(''); setSearch('') }

    const filteredList = list.filter(d => {
        if (!search) return true
        const q = search.toLowerCase()
        return d.jenis_dokumen.toLowerCase().includes(q)
            || (d.nomor ?? '').toLowerCase().includes(q)
            || (d.armada_nopol ?? '').toLowerCase().includes(q)
    })

    const openAdd = () => { setForm(emptyForm()); setFile(null); setShowForm(true) }

    const openEdit = (d: DokumenArmadaWithArmada) => {
        setEditTarget(d)
        setForm({
            id_armada: d.id_armada,
            jenis_dokumen: d.jenis_dokumen,
            nomor: d.nomor ?? '',
            berlaku_sampai: d.berlaku_sampai ?? '',
        })
        setFile(null)
    }

    const closeForm = () => { setShowForm(false); setEditTarget(null) }

    const handleSubmit = async () => {
        if (!form.id_armada || !form.jenis_dokumen) return
        if (!editTarget && !file) return
        setSaving(true)
        try {
            const payload = {
                jenis_dokumen: form.jenis_dokumen,
                nomor: form.nomor || null,
                berlaku_sampai: form.berlaku_sampai || null,
            }
            if (editTarget) {
                await dokumenArmadaService.update(editTarget.id_armada, editTarget.id_dokumen_armada, payload, file ?? undefined)
                toast.push(<Notification type="success" title="Dokumen berhasil diperbarui" />)
            } else {
                await dokumenArmadaService.create(form.id_armada, payload, file)
                toast.push(<Notification type="success" title="Dokumen berhasil ditambahkan" />)
            }
            closeForm()
            fetchData()
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setSaving(false)
        }
    }

    const handleDelete = async () => {
        if (!deleteTarget) return
        setDeleting(true)
        try {
            await dokumenArmadaService.delete(deleteTarget.id_armada, deleteTarget.id_dokumen_armada)
            toast.push(<Notification type="success" title="Dokumen berhasil dihapus" />)
            setDeleteTarget(null)
            fetchData()
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setDeleting(false)
        }
    }

    const columns: ColumnDef<DokumenArmadaWithArmada>[] = [
        {
            header: 'No', id: 'no', size: 60,
            cell: ({ row }: CellContext<DokumenArmadaWithArmada, unknown>) =>
                (currentPage - 1) * pageSize + row.index + 1,
        },
        {
            header: 'Armada', accessorKey: 'armada_nopol', size: 130,
            cell: ({ row }: CellContext<DokumenArmadaWithArmada, unknown>) => (
                <span className="font-mono text-xs font-semibold">{row.original.armada_nopol ?? '—'}</span>
            ),
        },
        { header: 'Jenis Dokumen', accessorKey: 'jenis_dokumen', size: 140 },
        {
            header: 'Nomor', accessorKey: 'nomor', size: 150,
            cell: ({ row }: CellContext<DokumenArmadaWithArmada, unknown>) => (
                <span className="font-mono text-xs">{row.original.nomor ?? '—'}</span>
            ),
        },
        {
            header: 'Berlaku Sampai', accessorKey: 'berlaku_sampai', size: 180,
            cell: ({ row }: CellContext<DokumenArmadaWithArmada, unknown>) => {
                const tgl = row.original.berlaku_sampai
                const expiry = getExpiryInfo(tgl)
                return (
                    <div>
                        <p className="text-xs">{tgl ? dayjs(tgl).format('DD MMM YYYY') : '—'}</p>
                        <Tag className={`text-xs font-semibold mt-1 ${expiry.className}`}>{expiry.label}</Tag>
                    </div>
                )
            },
        },
        {
            header: 'File', accessorKey: 'url_file', size: 90,
            cell: ({ row }: CellContext<DokumenArmadaWithArmada, unknown>) =>
                row.original.url_file
                    ? <a href={row.original.url_file} target="_blank" rel="noreferrer" className="text-blue-500 hover:underline text-xs">Lihat</a>
                    : <span className="text-gray-400 text-xs">—</span>,
        },
        {
            header: '', id: 'action', size: 90,
            cell: ({ row }: CellContext<DokumenArmadaWithArmada, unknown>) => (
                <div className="flex items-center justify-end gap-1">
                    <Tooltip title="Edit">
                        <span
                            className="cursor-pointer inline-flex items-center justify-center w-8 h-8 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100 dark:bg-blue-500/20 dark:text-blue-300 dark:hover:bg-blue-500/30 transition-colors"
                            onClick={() => openEdit(row.original)}>
                            <HiOutlinePencilAlt className="text-lg" />
                        </span>
                    </Tooltip>
                    <Tooltip title="Hapus">
                        <span
                            className="cursor-pointer inline-flex items-center justify-center w-8 h-8 rounded-lg bg-red-50 text-red-500 hover:bg-red-100 dark:bg-red-500/10 dark:hover:bg-red-500/20 transition-colors"
                            onClick={() => setDeleteTarget(row.original)}>
                            <HiOutlineTrash className="text-lg" />
                        </span>
                    </Tooltip>
                </div>
            ),
        },
    ]

    const isFormOpen = showForm || editTarget !== null

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center justify-between">
                <div>
                    <h3 className="font-bold">Dokumen Armada</h3>
                    <p className="text-gray-500 text-sm mt-0.5">Kelola dokumen seluruh armada — STNK, KIR, Asuransi, dll</p>
                </div>
                <Button variant="solid" icon={<HiOutlinePlus />} onClick={openAdd}>Tambah Dokumen</Button>
            </div>
            <Card bodyClass="p-0">
                <div className="flex flex-col sm:flex-row items-center gap-3 px-4 py-3">
                    <Input
                        className="flex-1"
                        placeholder="Cari jenis dokumen, nomor, atau nopol... (tekan Enter)"
                        suffix={
                            searchInput
                                ? <HiOutlineX className="text-gray-400 text-lg cursor-pointer hover:text-gray-600" onClick={handleSearchClear} />
                                : <HiOutlineSearch className="text-gray-400 text-lg cursor-pointer hover:text-gray-600" onClick={handleSearchSubmit} />
                        }
                        value={searchInput}
                        onChange={(e) => setSearchInput(e.target.value)}
                        onKeyDown={(e) => { if (e.key === 'Enter') handleSearchSubmit() }}
                    />
                    <div className="w-full sm:w-52 shrink-0">
                        <Select
                            placeholder="Semua Armada"
                            isClearable
                            options={armadaOptions}
                            value={armadaOptions.find(o => o.value === armadaFilter) ?? null}
                            onChange={(opt) => { setArmadaFilter((opt as Option | null)?.value ?? ''); setCurrentPage(1) }}
                        />
                    </div>
                    <div className="w-full sm:w-44 shrink-0">
                        <Select
                            isSearchable={false}
                            options={JENIS_FILTER_OPTIONS}
                            value={JENIS_FILTER_OPTIONS.find(o => o.value === jenisFilter) ?? JENIS_FILTER_OPTIONS[0]}
                            onChange={(opt) => { setJenisFilter((opt as Option).value); setCurrentPage(1) }}
                        />
                    </div>
                </div>
                <DataTable
                    columns={columns}
                    data={filteredList as unknown[]}
                    loading={loading}
                    noData={!loading && filteredList.length === 0}
                    pagingData={{ total, pageIndex: currentPage, pageSize }}
                    onPaginationChange={setCurrentPage}
                    onSelectChange={(size) => { setPageSize(size); setCurrentPage(1) }}
                />
            </Card>

            <Dialog isOpen={isFormOpen} onRequestClose={closeForm} width={600}>
                <h5 className="text-base font-semibold mb-5">{editTarget ? 'Edit Dokumen' : 'Tambah Dokumen'}</h5>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
                    <div className="sm:col-span-2">
                        <FormItem label="Armada" asterisk>
                            <Select
                                placeholder="Pilih armada..."
                                isDisabled={!!editTarget}
                                options={armadaOptions}
                                value={armadaOptions.find(o => o.value === form.id_armada) ?? null}
                                onChange={(opt) => setForm(p => ({ ...p, id_armada: (opt as Option | null)?.value ?? '' }))}
                            />
                        </FormItem>
                    </div>
                    <FormItem label="Jenis Dokumen" asterisk>
                        <Select isSearchable={false} placeholder="Pilih jenis..."
                            options={JENIS_DOKUMEN_OPTIONS}
                            value={JENIS_DOKUMEN_OPTIONS.find(o => o.value === form.jenis_dokumen) ?? null}
                            onChange={opt => setForm(p => ({ ...p, jenis_dokumen: (opt as Option | null)?.value ?? '' }))} />
                    </FormItem>
                    <FormItem label="Nomor Dokumen">
                        <Input placeholder="Contoh: B 1234 XYZ" value={form.nomor}
                            onChange={e => setForm(p => ({ ...p, nomor: e.target.value }))} />
                    </FormItem>
                    <FormItem label="Berlaku Sampai">
                        <DatePicker
                            value={form.berlaku_sampai ? new Date(form.berlaku_sampai) : null}
                            onChange={date => setForm(p => ({ ...p, berlaku_sampai: date ? dayjs(date).format('YYYY-MM-DD') : '' }))} />
                    </FormItem>
                    <FormItem label="File Dokumen" asterisk={!editTarget}>
                        <Upload accept=".pdf,.jpg,.jpeg,.png" showList={false} uploadLimit={1}
                            onChange={files => setFile(files[0] ?? null)}>
                            <Button type="button" variant="default" size="sm" icon={<HiOutlineDocumentText />}>
                                {file ? file.name : (editTarget ? 'Ganti file (opsional)' : 'Pilih file (PDF/JPG/PNG)')}
                            </Button>
                        </Upload>
                        {file && (
                            <button type="button" className="text-xs text-red-400 hover:text-red-600 mt-1.5 block"
                                onClick={() => setFile(null)}>Hapus pilihan</button>
                        )}
                    </FormItem>
                </div>
                <div className="flex justify-end gap-2 mt-6">
                    <Button variant="plain" onClick={closeForm}>Batal</Button>
                    <Button variant="solid" loading={saving}
                        disabled={!form.id_armada || !form.jenis_dokumen || (!editTarget && !file)}
                        onClick={handleSubmit}>Simpan</Button>
                </div>
            </Dialog>

            <ConfirmDialog
                isOpen={!!deleteTarget}
                type="danger"
                title="Hapus Dokumen"
                confirmText="Ya, Hapus"
                cancelText="Batal"
                onClose={() => setDeleteTarget(null)}
                onCancel={() => setDeleteTarget(null)}
                onConfirm={handleDelete}
                confirmButtonProps={{ loading: deleting }}
            >
                <p>Hapus dokumen <strong>{deleteTarget?.jenis_dokumen}</strong> untuk armada {deleteTarget?.armada_nopol}?</p>
            </ConfirmDialog>
        </div>
    )
}
```

- [ ] **Step 2: Type-check**

Run: `cd TMN-TRANSPORT-FRONTEND && npx tsc --noEmit -p tsconfig.json`
Expected: tidak ada error di `dokumen-armada/page.tsx`.

- [ ] **Step 3: Lint**

Run: `cd TMN-TRANSPORT-FRONTEND && npx eslint "src/app/(protected-pages)/dokumen-armada/page.tsx"`
Expected: tidak ada error/warning.

- [ ] **Step 4: Stage perubahan**

```bash
git add "src/app/(protected-pages)/dokumen-armada"
```

---

### Task 7: Verifikasi penuh end-to-end

**Files:** tidak ada file baru — task operasional murni.

**Interfaces:**
- Consumes: seluruh hasil Task 1-6.

- [ ] **Step 1: Full backend test suite**

Run: `cd TMN-TRANSPORT-BACKEND && vendor/bin/phpunit`
Expected: `OK (...)`, total test count naik minimal 11 (5 dari Task 1 + 6 dari Task 2) dibanding baseline sebelum plan ini.

- [ ] **Step 2: Rebuild & restart Docker (backend + frontend)**

```bash
cd TMN-TRANSPORT-BACKEND
docker compose -f docker-compose.local.yml build backend frontend
docker compose -f docker-compose.local.yml up -d --no-deps backend frontend
```

Expected: kedua image build sukses tanpa error.

- [ ] **Step 3: Cek container boot bersih**

```bash
docker ps --filter "name=TMN-BACKEND" --filter "name=TMN-FRONTEND" --format "table {{.Names}}\t{{.Status}}"
docker logs TMN-BACKEND --tail 30
```

Expected: kedua container `Up`, log backend menampilkan migration `2026_07_16_000002_seed_menu_perawatan_dokumen_armada` berhasil dijalankan (`DONE`), tidak ada `Class not found` atau error lain.

- [ ] **Step 4: Live smoke test — endpoint baru**

```bash
cd TMN-TRANSPORT-BACKEND
LOGIN_RESP=$(curl -s -X POST http://localhost:4019/api/v1/auth/login -H "Content-Type: application/json" -d '{"username":"superadmin","password":"Password123!"}')
TOKEN=$(echo "$LOGIN_RESP" | grep -o '"token":"[^"]*"' | cut -d'"' -f4)
curl -s -o /dev/null -w "perawatan-armada -> %{http_code}\n" "http://localhost:4019/api/v1/perawatan-armada" -H "Authorization: Bearer $TOKEN"
curl -s -o /dev/null -w "dokumen-armada -> %{http_code}\n" "http://localhost:4019/api/v1/dokumen-armada" -H "Authorization: Bearer $TOKEN"
```

Expected: keduanya `200`.

- [ ] **Step 5: Live smoke test — menu tampil di sidebar (via API navigasi)**

```bash
curl -s "http://localhost:4019/api/v1/menu" -H "Authorization: Bearer $TOKEN" | grep -o '"nama_menu":"[^"]*"'
```

Expected: output memuat `"nama_menu":"Perawatan Armada"` dan `"nama_menu":"Dokumen Armada"`.

- [ ] **Step 6: Live smoke test — halaman frontend**

```bash
curl -s -o /dev/null -w "frontend /perawatan-armada -> %{http_code}\n" http://localhost:3009/perawatan-armada
curl -s -o /dev/null -w "frontend /dokumen-armada -> %{http_code}\n" http://localhost:3009/dokumen-armada
```

Expected: keduanya `302` (redirect ke login karena curl tidak punya session — ini normal, sama seperti pola `/trip`/`/penugasan` di verifikasi sebelumnya, bukan error).

- [ ] **Step 7: Grep referensi Model Eloquent yang sudah dihapus**

```bash
cd TMN-TRANSPORT-BACKEND
grep -rn "\bPerawatanArmadaModel\b\|\bDokumenArmadaModel\b" app/ --include="*.php"
```

Expected: nol hasil (atau hanya komentar).

- [ ] **Step 8: JANGAN commit — laporkan status staged ke user**

```bash
cd TMN-TRANSPORT-BACKEND && git status --short app/Modules/PerawatanArmada app/Modules/DokumenArmada database/migrations/2026_07_16_000002_seed_menu_perawatan_dokumen_armada.php tests/Feature/PerawatanArmadaTest.php tests/Feature/DokumenArmadaTest.php
cd ../TMN-TRANSPORT-FRONTEND && git status --short "src/app/(protected-pages)/perawatan-armada" "src/app/(protected-pages)/dokumen-armada" src/constants/api.constant.ts src/constants/route.constant.ts src/configs/routes.config/routes.config.ts src/services/perawatanArmada.service.ts src/services/dokumenArmada.service.ts src/configs/navigation-icon.config.tsx
```

Expected: semua file di atas berstatus staged (`M`/`A` di kolom pertama), tidak ada yang ter-commit. Laporkan ringkasan ke user untuk di-commit manual.
