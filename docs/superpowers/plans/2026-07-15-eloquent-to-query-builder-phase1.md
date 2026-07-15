# Migrasi Eloquent ke Query Builder — Kelompok 1 (Pilot) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Konversi 5 modul backend (JenisKendaraan, LokasiKantor, Departemen, Jabatan, StatusTrip) dari Eloquent Model ke Query Builder murni (`DB::table()`), sebagai kelompok pilot dari migrasi 37+ modul yang direncanakan di `docs/superpowers/specs/2026-07-15-eloquent-to-query-builder-design.md`.

**Architecture:** Setiap modul kehilangan `*Model.php`-nya sepenuhnya. Repository bicara langsung ke `DB::table('nama_tabel')`. Perilaku otomatis BaseModel (UUID, kolom audit, soft-delete) digantikan pemanggilan eksplisit ke `App\Support\RecordHelper` (dibuat di Task 1). Interface Contract & Service pindah dari type-hint `?FooModel`/`FooModel` ke `?object`/`object` — kompatibel langsung dengan `JsonResource` tanpa perubahan Resource (sudah terbukti di modul KontrakVendor).

**Tech Stack:** Laravel 11, PHP 8.2+, MySQL 8 (runtime, via Docker), SQLite in-memory (testing, via PHPUnit).

## Global Constraints

- Tidak boleh menjalankan `git commit` di task manapun — user commit manual sendiri. Tinggalkan perubahan di working tree.
- Setiap Repository method yang di-generate/diubah **tidak boleh** memanggil Eloquent Model manapun dari 5 modul ini — murni `DB::table()`.
- Primary key semua tabel di modul ini custom (`id_jenis_kendaraan`, `id_lokasi`, dst) — **jangan** pakai `DB::table()->find($id)` (defaultnya cari kolom `id`), selalu `->where('id_xxx', $id)->first()`.
- `RecordHelper` dipanggil eksplisit di Repository sebelum `insert()`/`update()` — tidak ada hook/event tersembunyi.
- Modul di luar kelompok 1 (Karyawan dkk) yang punya ketergantungan silang ke modul kelompok 1 harus dipatch dulu sebelum Model kelompok 1 dihapus (lihat Task 2).

---

### Task 1: Buat `RecordHelper` — pengganti perilaku otomatis BaseModel

**Files:**
- Create: `app/Support/RecordHelper.php`
- Test: `tests/Unit/RecordHelperTest.php`

**Interfaces:**
- Produces: `App\Support\RecordHelper::stampCreate(array $data, string $primaryKey): array`, `::stampUpdate(array $data): array`, `::stampDelete(): array` — dipakai oleh SEMUA Repository di task-task berikutnya.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Unit/RecordHelperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RecordHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordHelperTest extends TestCase
{
    use RefreshDatabase;

    public function test_stamp_create_generates_uuid_when_missing(): void
    {
        $result = RecordHelper::stampCreate(['nama' => 'Test'], 'id_test');

        $this->assertArrayHasKey('id_test', $result);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $result['id_test']
        );
        $this->assertArrayHasKey('dibuat_pada', $result);
    }

    public function test_stamp_create_keeps_existing_id(): void
    {
        $result = RecordHelper::stampCreate(['id_test' => 'sudah-ada', 'nama' => 'Test'], 'id_test');

        $this->assertSame('sudah-ada', $result['id_test']);
    }

    public function test_stamp_create_fills_dibuat_oleh_when_authenticated(): void
    {
        $pengguna = $this->actingAsRole('ADMIN');

        $result = RecordHelper::stampCreate(['nama' => 'Test'], 'id_test');

        $this->assertSame($pengguna->id_pengguna, $result['dibuat_oleh']);
    }

    public function test_stamp_create_tidak_isi_dibuat_oleh_saat_tidak_login(): void
    {
        $result = RecordHelper::stampCreate(['nama' => 'Test'], 'id_test');

        $this->assertArrayNotHasKey('dibuat_oleh', $result);
    }

    public function test_stamp_update_fills_diubah_pada(): void
    {
        $result = RecordHelper::stampUpdate(['nama' => 'Diubah']);

        $this->assertArrayHasKey('diubah_pada', $result);
        $this->assertSame('Diubah', $result['nama']);
    }

    public function test_stamp_delete_fills_dihapus_pada(): void
    {
        $result = RecordHelper::stampDelete();

        $this->assertArrayHasKey('dihapus_pada', $result);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `docker exec TMN-BACKEND php artisan test tests/Unit/RecordHelperTest.php`
Expected: FAIL dengan error `Class "App\Support\RecordHelper" not found`

- [ ] **Step 3: Buat implementasi**

Buat `app/Support/RecordHelper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

final class RecordHelper
{
    public static function stampCreate(array $data, string $primaryKey): array
    {
        $data[$primaryKey] ??= (string) Str::uuid();
        $data['dibuat_pada'] = now();
        if (auth()->check()) {
            $data['dibuat_oleh'] = auth()->id();
        }
        return $data;
    }

    public static function stampUpdate(array $data): array
    {
        $data['diubah_pada'] = now();
        if (auth()->check()) {
            $data['diubah_oleh'] = auth()->id();
        }
        return $data;
    }

    public static function stampDelete(): array
    {
        $data = ['dihapus_pada' => now()];
        if (auth()->check()) {
            $data['dihapus_oleh'] = auth()->id();
        }
        return $data;
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan lolos**

Run: `docker exec TMN-BACKEND php artisan test tests/Unit/RecordHelperTest.php`
Expected: PASS, 6 test lolos

- [ ] **Step 5: Commit**

Jangan commit — biarkan di working tree (lihat Global Constraints).

---

### Task 2: Patch modul Karyawan — putus ketergantungan Eloquent ke Jabatan & LokasiKantor

**Konteks penting:** `KaryawanModel::jabatan()` dan `::lokasi()` adalah relasi `belongsTo()` ke `JabatanModel`/`LokasiKantorModel`. `KaryawanRepository` memakai `->with(['jabatan', 'lokasi'])`, dan `KaryawanResource` membaca `$this->jabatan->nama_jabatan`/`$this->lokasi->nama_lokasi`. Task 4 & 6 akan MENGHAPUS `JabatanModel`/`LokasiKantorModel` — kalau task ini tidak dikerjakan lebih dulu, halaman Karyawan akan 500 error begitu kedua Model itu hilang. Karyawan module SENDIRI **tidak** dikonversi penuh di sini (itu jatah Kelompok 2) — cuma bagian yang bergantung ke Jabatan/LokasiKantor yang dipatch, memakai pola lookup manual sama seperti `attachNamaVendor()` di `KontrakVendorRepository`.

**Files:**
- Modify: `app/Modules/Karyawan/KaryawanModel.php`
- Modify: `app/Modules/Karyawan/KaryawanRepository.php`
- Modify: `app/Modules/Karyawan/Resources/KaryawanResource.php`
- Test: `tests/Feature/KaryawanJabatanLokasiTest.php`

**Interfaces:**
- Consumes: tabel `jabatan` (kolom `id_jabatan`, `nama_jabatan`), tabel `lokasi_kantor` (kolom `id_lokasi`, `nama_lokasi`) via `DB::table()`.
- Produces: `KaryawanResource` tetap mengeluarkan field JSON `vendor`... eh `jabatan`/`lokasi` dengan bentuk sama persis seperti sebelumnya (`{id_jabatan, nama_jabatan}` / `{id_lokasi, nama_lokasi}`) — tidak ada breaking change ke frontend.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/KaryawanJabatanLokasiTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KaryawanJabatanLokasiTest extends TestCase
{
    use RefreshDatabase;

    private function makeJabatan(string $nama = 'Supervisor'): string
    {
        $id = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan'     => $id,
            'id_perusahaan'  => self::PERUSAHAAN_ID,
            'kode_jabatan'   => 'JBT-' . Str::random(4),
            'nama_jabatan'   => $nama,
            'aktif'          => 1,
            'dibuat_pada'    => now(),
        ]);
        return $id;
    }

    private function makeLokasi(string $nama = 'Kantor Pusat'): string
    {
        $id = (string) Str::uuid();
        DB::table('lokasi_kantor')->insert([
            'id_lokasi'      => $id,
            'id_perusahaan'  => self::PERUSAHAAN_ID,
            'kode_lokasi'    => 'LOK-' . Str::random(4),
            'nama_lokasi'    => $nama,
            'radius'         => 100,
            'aktif'          => 1,
            'dibuat_pada'    => now(),
        ]);
        return $id;
    }

    private function makeKaryawan(string $idJabatan, string $idLokasi): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'         => $id,
            'id_perusahaan'       => self::PERUSAHAAN_ID,
            'id_jabatan'          => $idJabatan,
            'id_lokasi'           => $idLokasi,
            'nik'                 => 'NIK-' . Str::random(6),
            'nama_karyawan'       => 'Budi Santoso',
            'status_kepegawaian'  => 'tetap',
            'gaji_pokok'          => 5000000,
            'aktif'               => 1,
            'dibuat_pada'         => now(),
        ]);
        return $id;
    }

    public function test_list_karyawan_menyertakan_nama_jabatan_dan_lokasi(): void
    {
        $this->actingAsRole('ADMIN');
        $idJabatan = $this->makeJabatan('Manager Operasional');
        $idLokasi = $this->makeLokasi('Gudang Cikarang');
        $this->makeKaryawan($idJabatan, $idLokasi);

        $res = $this->getJson('/api/v1/karyawan');

        $res->assertStatus(200);
        $data = $res->json('data')[0];
        $this->assertSame('Manager Operasional', $data['jabatan']['nama_jabatan']);
        $this->assertSame('Gudang Cikarang', $data['lokasi']['nama_lokasi']);
    }

    public function test_show_karyawan_menyertakan_nama_jabatan_dan_lokasi(): void
    {
        $this->actingAsRole('ADMIN');
        $idJabatan = $this->makeJabatan('Staff Admin');
        $idLokasi = $this->makeLokasi('Kantor Cabang');
        $idKaryawan = $this->makeKaryawan($idJabatan, $idLokasi);

        $res = $this->getJson("/api/v1/karyawan/{$idKaryawan}");

        $res->assertStatus(200)
            ->assertJsonPath('data.jabatan.nama_jabatan', 'Staff Admin')
            ->assertJsonPath('data.lokasi.nama_lokasi', 'Kantor Cabang');
    }

    public function test_karyawan_tanpa_jabatan_lokasi_mengembalikan_null(): void
    {
        $this->actingAsRole('ADMIN');
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'        => $id,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'nik'                => 'NIK-' . Str::random(6),
            'nama_karyawan'      => 'Tanpa Jabatan',
            'status_kepegawaian' => 'kontrak',
            'gaji_pokok'         => 3000000,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);

        $res = $this->getJson("/api/v1/karyawan/{$id}");

        $res->assertStatus(200)
            ->assertJsonPath('data.jabatan', null)
            ->assertJsonPath('data.lokasi', null);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan lolos DULU (baseline sebelum diubah)**

Run: `docker exec TMN-BACKEND php artisan test tests/Feature/KaryawanJabatanLokasiTest.php`
Expected: PASS (3 test lolos) — kode existing masih Eloquent relationship, jadi harus sudah jalan. Ini konfirmasi baseline sebelum kita refactor.

- [ ] **Step 3: Patch `KaryawanModel.php` — hapus relasi `jabatan()`/`lokasi()`**

Ganti isi `app/Modules/Karyawan/KaryawanModel.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Karyawan;

use App\Models\BaseModel;

class KaryawanModel extends BaseModel
{
    protected $table = 'karyawan';
    protected $primaryKey = 'id_karyawan';

    protected $fillable = [
        'id_karyawan',
        'id_perusahaan',
        'id_jabatan',
        'id_lokasi',
        'nik',
        'nama_karyawan',
        'email',
        'telepon',
        'jenis_kelamin',
        'tanggal_lahir',
        'tanggal_masuk',
        'status_kepegawaian',
        'gaji_pokok',
        'aktif',
    ];

    public function exitHistory(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Modules\KaryawanExit\KaryawanExitModel::class, 'id_karyawan', 'id_karyawan');
    }
}
```

- [ ] **Step 4: Patch `KaryawanRepository.php` — ganti eager-load jadi lookup manual**

Ganti isi `app/Modules/Karyawan/KaryawanRepository.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Karyawan;

use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KaryawanRepository implements KaryawanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        $paginator = KaryawanModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_karyawan')
            ->paginate($limit, ['*'], 'page', $page);

        $this->attachJabatanLokasi($paginator->getCollection());

        return $paginator;
    }

    public function findById(string $id): ?KaryawanModel
    {
        $record = KaryawanModel::active()->find($id);
        if ($record !== null) {
            $this->attachJabatanLokasi(collect([$record]));
        }
        return $record;
    }

    public function findByNik(string $nik): ?KaryawanModel
    {
        return KaryawanModel::active()->where('nik', $nik)->first();
    }

    public function create(array $data): KaryawanModel
    {
        return KaryawanModel::create($data);
    }

    public function update(KaryawanModel $model, array $data): KaryawanModel
    {
        $model->update($data);
        $fresh = $model->fresh();
        $this->attachJabatanLokasi(collect([$fresh]));
        return $fresh;
    }

    public function delete(KaryawanModel $model): void
    {
        $model->softDelete();
    }

    public function exitHistory(string $idKaryawan): array
    {
        return \App\Modules\KaryawanExit\KaryawanExitModel::where('id_karyawan', $idKaryawan)
            ->orderBy('tanggal_efektif', 'desc')
            ->get()
            ->all();
    }

    /**
     * Tempel nama jabatan & lokasi via raw query builder (join manual),
     * bukan Eloquent relationship — JabatanModel & LokasiKantorModel sudah
     * dikonversi ke Query Builder di Task 4 & 6 dan tidak lagi punya class Eloquent.
     */
    private function attachJabatanLokasi(Collection $records): void
    {
        $idJabatanList = $records->pluck('id_jabatan')->filter()->unique()->values()->all();
        $idLokasiList  = $records->pluck('id_lokasi')->filter()->unique()->values()->all();

        $namaJabatanById = empty($idJabatanList)
            ? collect()
            : DB::table('jabatan')->whereIn('id_jabatan', $idJabatanList)->pluck('nama_jabatan', 'id_jabatan');

        $namaLokasiById = empty($idLokasiList)
            ? collect()
            : DB::table('lokasi_kantor')->whereIn('id_lokasi', $idLokasiList)->pluck('nama_lokasi', 'id_lokasi');

        foreach ($records as $record) {
            $record->jabatan_nama = $namaJabatanById[$record->id_jabatan] ?? null;
            $record->lokasi_nama  = $namaLokasiById[$record->id_lokasi] ?? null;
        }
    }
}
```

- [ ] **Step 5: Patch `KaryawanResource.php` — baca field yang ditempel, bukan relasi**

Ganti isi `app/Modules/Karyawan/Resources/KaryawanResource.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Karyawan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class KaryawanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_karyawan'        => $this->id_karyawan,
            'id_perusahaan'      => $this->id_perusahaan,
            'nik'                => $this->nik,
            'nama_karyawan'      => $this->nama_karyawan,
            'email'              => $this->email,
            'telepon'            => $this->telepon,
            'jenis_kelamin'      => $this->jenis_kelamin,
            'tanggal_lahir'      => $this->tanggal_lahir,
            'tanggal_masuk'      => $this->tanggal_masuk,
            'status_kepegawaian' => $this->status_kepegawaian,
            'gaji_pokok'         => (float) $this->gaji_pokok,
            'aktif'              => (bool) $this->aktif,
            'jabatan'            => $this->jabatan_nama !== null ? [
                'id_jabatan'   => $this->id_jabatan,
                'nama_jabatan' => $this->jabatan_nama,
            ] : null,
            'lokasi'             => $this->lokasi_nama !== null ? [
                'id_lokasi'   => $this->id_lokasi,
                'nama_lokasi' => $this->lokasi_nama,
            ] : null,
            'dibuat_pada'        => $this->dibuat_pada,
            'diubah_pada'        => $this->diubah_pada,
        ];
    }
}
```

- [ ] **Step 6: Jalankan test, pastikan tetap lolos setelah patch**

Run: `docker exec TMN-BACKEND php artisan test tests/Feature/KaryawanJabatanLokasiTest.php`
Expected: PASS (3 test lolos) — perilaku identik, cuma mekanismenya yang berubah dari Eloquent relationship ke raw lookup.

- [ ] **Step 7: Commit**

Jangan commit — biarkan di working tree.

---

### Task 3: Konversi modul JenisKendaraan

**Files:**
- Delete: `app/Modules/JenisKendaraan/JenisKendaraanModel.php`
- Modify: `app/Modules/JenisKendaraan/Contracts/JenisKendaraanRepositoryInterface.php`
- Modify: `app/Modules/JenisKendaraan/JenisKendaraanRepository.php`
- Modify: `app/Modules/JenisKendaraan/JenisKendaraanService.php`
- Test: `tests/Feature/JenisKendaraanTest.php`

**Interfaces:**
- Consumes: `App\Support\RecordHelper` (Task 1)
- Produces: `JenisKendaraanRepositoryInterface` dengan return type `?object`/`object` — dipakai `JenisKendaraanController` (tidak berubah, tidak type-hint Model).

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/JenisKendaraanTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class JenisKendaraanTest extends TestCase
{
    use RefreshDatabase;

    private function makeJenisKendaraan(string $idPerusahaan, string $kode = 'TRK-01', string $nama = 'Truk Engkel'): object
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id,
            'id_perusahaan'      => $idPerusahaan,
            'kode_jenis'         => $kode,
            'nama_jenis'         => $nama,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return DB::table('jenis_kendaraan')->where('id_jenis_kendaraan', $id)->first();
    }

    private function makePerusahaanLain(): string
    {
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Test',
            'dibuat_pada'   => now(),
        ]);
        return $idPerusahaanLain;
    }

    public function test_membuat_jenis_kendaraan_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/jenis-kendaraan', [
            'kode_jenis'       => 'TRK-02',
            'nama_jenis'       => 'Truk Tronton',
            'kapasitas_muatan' => 8000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama_jenis', 'Truk Tronton')
            ->assertJsonPath('data.kapasitas_muatan', 8000)
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('jenis_kendaraan', [
            'kode_jenis'    => 'TRK-02',
            'id_perusahaan' => self::PERUSAHAAN_ID,
        ]);
    }

    public function test_menolak_kode_jenis_duplikat(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeJenisKendaraan(self::PERUSAHAAN_ID, 'TRK-01');

        $res = $this->postJson('/api/v1/jenis-kendaraan', [
            'kode_jenis' => 'TRK-01',
            'nama_jenis' => 'Truk Duplikat',
        ]);

        $res->assertStatus(409);
    }

    public function test_list_jenis_kendaraan_hanya_menampilkan_milik_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');

        $this->makeJenisKendaraan(self::PERUSAHAAN_ID, 'TRK-01', 'Milik Sendiri');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $this->makeJenisKendaraan($idPerusahaanLain, 'TRK-01', 'Milik Lain');

        $res = $this->getJson('/api/v1/jenis-kendaraan');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Milik Sendiri', $data[0]['nama_jenis']);
    }

    public function test_show_jenis_kendaraan_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeJenisKendaraan(self::PERUSAHAAN_ID);

        $res = $this->getJson("/api/v1/jenis-kendaraan/{$item->id_jenis_kendaraan}");

        $res->assertStatus(200)->assertJsonPath('data.id_jenis_kendaraan', $item->id_jenis_kendaraan);
    }

    public function test_show_jenis_kendaraan_tidak_ditemukan_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->getJson('/api/v1/jenis-kendaraan/' . Str::uuid()->toString());

        $res->assertStatus(404);
    }

    public function test_update_jenis_kendaraan_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeJenisKendaraan(self::PERUSAHAAN_ID);

        $res = $this->putJson("/api/v1/jenis-kendaraan/{$item->id_jenis_kendaraan}", [
            'nama_jenis' => 'Nama Diperbarui',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama_jenis', 'Nama Diperbarui');
        $this->assertDatabaseHas('jenis_kendaraan', [
            'id_jenis_kendaraan' => $item->id_jenis_kendaraan,
            'nama_jenis'         => 'Nama Diperbarui',
        ]);
    }

    public function test_hapus_jenis_kendaraan_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeJenisKendaraan(self::PERUSAHAAN_ID);

        $res = $this->deleteJson("/api/v1/jenis-kendaraan/{$item->id_jenis_kendaraan}");
        $res->assertStatus(200)->assertJsonPath('success', true);

        $row = DB::table('jenis_kendaraan')->where('id_jenis_kendaraan', $item->id_jenis_kendaraan)->first();
        $this->assertNotNull($row->dihapus_pada);

        $this->assertCount(0, $this->getJson('/api/v1/jenis-kendaraan')->json('data'));
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan lolos DULU (baseline, kode masih Eloquent)**

Run: `docker exec TMN-BACKEND php artisan test tests/Feature/JenisKendaraanTest.php`
Expected: PASS (7 test lolos)

- [ ] **Step 3: Ganti Contract interface**

Ganti isi `app/Modules/JenisKendaraan/Contracts/JenisKendaraanRepositoryInterface.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JenisKendaraan\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface JenisKendaraanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function findByKode(string $idPerusahaan, string $kode): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
}
```

- [ ] **Step 4: Ganti Repository**

Ganti isi `app/Modules/JenisKendaraan/JenisKendaraanRepository.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JenisKendaraan;

use App\Modules\JenisKendaraan\Contracts\JenisKendaraanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class JenisKendaraanRepository implements JenisKendaraanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('jenis_kendaraan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_jenis')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('jenis_kendaraan')
            ->whereNull('dihapus_pada')
            ->where('id_jenis_kendaraan', $id)
            ->first();
    }

    public function findByKode(string $idPerusahaan, string $kode): ?object
    {
        return DB::table('jenis_kendaraan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kode_jenis', $kode)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_jenis_kendaraan');
        DB::table('jenis_kendaraan')->insert($data);
        return $this->findById($data['id_jenis_kendaraan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('jenis_kendaraan')
            ->where('id_jenis_kendaraan', $record->id_jenis_kendaraan)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_jenis_kendaraan);
    }

    public function delete(object $record): void
    {
        DB::table('jenis_kendaraan')
            ->where('id_jenis_kendaraan', $record->id_jenis_kendaraan)
            ->update(RecordHelper::stampDelete());
    }
}
```

- [ ] **Step 5: Ganti Service (cuma type hint)**

Ganti isi `app/Modules/JenisKendaraan/JenisKendaraanService.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JenisKendaraan;

use App\Modules\JenisKendaraan\Contracts\JenisKendaraanRepositoryInterface;

class JenisKendaraanService
{
    public function __construct(private readonly JenisKendaraanRepositoryInterface $repo) {}

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
            abort(404, 'Jenis kendaraan tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        $idPerusahaan = $data['id_perusahaan'];

        if ($this->repo->findByKode($idPerusahaan, $data['kode_jenis'])) {
            abort(409, 'Kode jenis kendaraan sudah digunakan');
        }

        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id);

        if (isset($data['kode_jenis']) && $data['kode_jenis'] !== $record->kode_jenis) {
            if ($this->repo->findByKode($idPerusahaan, $data['kode_jenis'])) {
                abort(409, 'Kode jenis kendaraan sudah digunakan');
            }
        }

        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
```

- [ ] **Step 6: Hapus Model**

Run: `rm "app/Modules/JenisKendaraan/JenisKendaraanModel.php"`

- [ ] **Step 7: Jalankan test, pastikan tetap lolos**

Run: `docker exec TMN-BACKEND php artisan test tests/Feature/JenisKendaraanTest.php`
Expected: PASS (7 test lolos)

- [ ] **Step 8: Commit**

Jangan commit — biarkan di working tree.

---

### Task 4: Konversi modul LokasiKantor

**Files:**
- Delete: `app/Modules/LokasiKantor/LokasiKantorModel.php`
- Modify: `app/Modules/LokasiKantor/Contracts/LokasiKantorRepositoryInterface.php`
- Modify: `app/Modules/LokasiKantor/LokasiKantorRepository.php`
- Modify: `app/Modules/LokasiKantor/LokasiKantorService.php`
- Test: `tests/Feature/LokasiKantorTest.php`

**Interfaces:**
- Consumes: `App\Support\RecordHelper` (Task 1). Task 2 sudah memastikan `KaryawanRepository` tidak lagi bergantung ke `LokasiKantorModel`.
- Produces: `LokasiKantorRepositoryInterface` dengan return type `?object`/`object`.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/LokasiKantorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LokasiKantorTest extends TestCase
{
    use RefreshDatabase;

    private function makeLokasiKantor(string $idPerusahaan, string $nama = 'Kantor Pusat'): object
    {
        $id = (string) Str::uuid();
        DB::table('lokasi_kantor')->insert([
            'id_lokasi'     => $id,
            'id_perusahaan' => $idPerusahaan,
            'kode_lokasi'   => 'LOK-' . Str::random(4),
            'nama_lokasi'   => $nama,
            'radius'        => 100,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return DB::table('lokasi_kantor')->where('id_lokasi', $id)->first();
    }

    private function makePerusahaanLain(): string
    {
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Test',
            'dibuat_pada'   => now(),
        ]);
        return $idPerusahaanLain;
    }

    public function test_membuat_lokasi_kantor_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/lokasi-kantor', [
            'kode_lokasi' => 'LOK-01',
            'nama_lokasi' => 'Gudang Bekasi',
            'kota'        => 'Bekasi',
            'radius'      => 150,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama_lokasi', 'Gudang Bekasi')
            ->assertJsonPath('data.radius', 150)
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('lokasi_kantor', [
            'kode_lokasi'   => 'LOK-01',
            'id_perusahaan' => self::PERUSAHAAN_ID,
        ]);
    }

    public function test_list_lokasi_kantor_hanya_menampilkan_milik_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');

        $this->makeLokasiKantor(self::PERUSAHAAN_ID, 'Milik Sendiri');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $this->makeLokasiKantor($idPerusahaanLain, 'Milik Lain');

        $res = $this->getJson('/api/v1/lokasi-kantor');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Milik Sendiri', $data[0]['nama_lokasi']);
    }

    public function test_show_lokasi_kantor_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeLokasiKantor(self::PERUSAHAAN_ID);

        $res = $this->getJson("/api/v1/lokasi-kantor/{$item->id_lokasi}");

        $res->assertStatus(200)->assertJsonPath('data.id_lokasi', $item->id_lokasi);
    }

    public function test_show_lokasi_kantor_tidak_ditemukan_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->getJson('/api/v1/lokasi-kantor/' . Str::uuid()->toString());

        $res->assertStatus(404);
    }

    public function test_update_lokasi_kantor_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeLokasiKantor(self::PERUSAHAAN_ID);

        $res = $this->putJson("/api/v1/lokasi-kantor/{$item->id_lokasi}", [
            'nama_lokasi' => 'Nama Diperbarui',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama_lokasi', 'Nama Diperbarui');
        $this->assertDatabaseHas('lokasi_kantor', [
            'id_lokasi'   => $item->id_lokasi,
            'nama_lokasi' => 'Nama Diperbarui',
        ]);
    }

    public function test_hapus_lokasi_kantor_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeLokasiKantor(self::PERUSAHAAN_ID);

        $res = $this->deleteJson("/api/v1/lokasi-kantor/{$item->id_lokasi}");
        $res->assertStatus(200)->assertJsonPath('success', true);

        $row = DB::table('lokasi_kantor')->where('id_lokasi', $item->id_lokasi)->first();
        $this->assertNotNull($row->dihapus_pada);

        $this->assertCount(0, $this->getJson('/api/v1/lokasi-kantor')->json('data'));
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan lolos DULU (baseline)**

Run: `docker exec TMN-BACKEND php artisan test tests/Feature/LokasiKantorTest.php`
Expected: PASS (6 test lolos)

- [ ] **Step 3: Ganti Contract interface**

Ganti isi `app/Modules/LokasiKantor/Contracts/LokasiKantorRepositoryInterface.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\LokasiKantor\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface LokasiKantorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
}
```

- [ ] **Step 4: Ganti Repository**

Ganti isi `app/Modules/LokasiKantor/LokasiKantorRepository.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\LokasiKantor;

use App\Modules\LokasiKantor\Contracts\LokasiKantorRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class LokasiKantorRepository implements LokasiKantorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('lokasi_kantor')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_lokasi')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('lokasi_kantor')
            ->whereNull('dihapus_pada')
            ->where('id_lokasi', $id)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_lokasi');
        DB::table('lokasi_kantor')->insert($data);
        return $this->findById($data['id_lokasi']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('lokasi_kantor')
            ->where('id_lokasi', $record->id_lokasi)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_lokasi);
    }

    public function delete(object $record): void
    {
        DB::table('lokasi_kantor')
            ->where('id_lokasi', $record->id_lokasi)
            ->update(RecordHelper::stampDelete());
    }
}
```

- [ ] **Step 5: Ganti Service (cuma type hint)**

Ganti isi `app/Modules/LokasiKantor/LokasiKantorService.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\LokasiKantor;

use App\Modules\LokasiKantor\Contracts\LokasiKantorRepositoryInterface;

class LokasiKantorService
{
    public function __construct(private readonly LokasiKantorRepositoryInterface $repo) {}

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
            abort(404, 'Lokasi kantor tidak ditemukan');
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

- [ ] **Step 6: Hapus Model**

Run: `rm "app/Modules/LokasiKantor/LokasiKantorModel.php"`

- [ ] **Step 7: Jalankan test LokasiKantor DAN test kompatibilitas Karyawan dari Task 2, pastikan tetap lolos**

Run: `docker exec TMN-BACKEND php artisan test tests/Feature/LokasiKantorTest.php tests/Feature/KaryawanJabatanLokasiTest.php`
Expected: PASS semua (6 + 3 = 9 test lolos) — konfirmasi patch Task 2 memang menyelamatkan Karyawan dari hilangnya `LokasiKantorModel`.

- [ ] **Step 8: Commit**

Jangan commit — biarkan di working tree.

---

### Task 5: Konversi modul Departemen (dengan tree)

**Files:**
- Delete: `app/Modules/Departemen/DepartemenModel.php`
- Modify: `app/Modules/Departemen/Contracts/DepartemenRepositoryInterface.php`
- Modify: `app/Modules/Departemen/DepartemenRepository.php`
- Modify: `app/Modules/Departemen/DepartemenService.php`
- Test: `tests/Feature/DepartemenTest.php`

**Interfaces:**
- Consumes: `App\Support\RecordHelper` (Task 1)
- Produces: `DepartemenRepositoryInterface::tree()` tetap `array` — `buildTree(Collection $items, ?string $parentId)` (private helper) TIDAK berubah sama sekali, karena sudah generic terhadap `Illuminate\Support\Collection` apa pun isinya (stdClass atau Eloquent model, keduanya support `->where()`/`->map()` dari Collection).

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/DepartemenTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DepartemenTest extends TestCase
{
    use RefreshDatabase;

    private function makeDepartemen(string $idPerusahaan, string $nama, ?string $idInduk = null): object
    {
        $id = (string) Str::uuid();
        DB::table('departemen')->insert([
            'id_departemen'       => $id,
            'id_perusahaan'       => $idPerusahaan,
            'id_departemen_induk' => $idInduk,
            'kode_departemen'     => 'DEP-' . Str::random(4),
            'nama_departemen'     => $nama,
            'aktif'               => 1,
            'dibuat_pada'         => now(),
        ]);
        return DB::table('departemen')->where('id_departemen', $id)->first();
    }

    public function test_membuat_departemen_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/departemen', [
            'kode_departemen' => 'DEP-01',
            'nama_departemen' => 'Operasional',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama_departemen', 'Operasional');

        $this->assertDatabaseHas('departemen', [
            'kode_departemen' => 'DEP-01',
            'id_perusahaan'   => self::PERUSAHAAN_ID,
        ]);
    }

    public function test_list_departemen_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeDepartemen(self::PERUSAHAAN_ID, 'HR');

        $res = $this->getJson('/api/v1/departemen');

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
    }

    public function test_show_departemen_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeDepartemen(self::PERUSAHAAN_ID, 'Keuangan');

        $res = $this->getJson("/api/v1/departemen/{$item->id_departemen}");

        $res->assertStatus(200)->assertJsonPath('data.nama_departemen', 'Keuangan');
    }

    public function test_update_departemen_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeDepartemen(self::PERUSAHAAN_ID, 'Lama');

        $res = $this->putJson("/api/v1/departemen/{$item->id_departemen}", [
            'nama_departemen' => 'Baru',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama_departemen', 'Baru');
    }

    public function test_hapus_departemen_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeDepartemen(self::PERUSAHAAN_ID, 'Dihapus');

        $res = $this->deleteJson("/api/v1/departemen/{$item->id_departemen}");
        $res->assertStatus(200);

        $row = DB::table('departemen')->where('id_departemen', $item->id_departemen)->first();
        $this->assertNotNull($row->dihapus_pada);
    }

    public function test_tree_departemen_menyusun_struktur_induk_anak(): void
    {
        $this->actingAsRole('ADMIN');
        $induk = $this->makeDepartemen(self::PERUSAHAAN_ID, 'Operasional');
        $anak = $this->makeDepartemen(self::PERUSAHAAN_ID, 'Armada', $induk->id_departemen);

        $res = $this->getJson('/api/v1/departemen/tree');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Operasional', $data[0]['nama_departemen']);
        $this->assertCount(1, $data[0]['children']);
        $this->assertSame('Armada', $data[0]['children'][0]['nama_departemen']);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan lolos DULU (baseline)**

Run: `docker exec TMN-BACKEND php artisan test tests/Feature/DepartemenTest.php`
Expected: PASS (6 test lolos)

- [ ] **Step 3: Ganti Contract interface**

Ganti isi `app/Modules/Departemen/Contracts/DepartemenRepositoryInterface.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Departemen\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface DepartemenRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function tree(string $idPerusahaan): array;
    public function findById(string $id): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
}
```

- [ ] **Step 4: Ganti Repository**

Ganti isi `app/Modules/Departemen/DepartemenRepository.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Departemen;

use App\Modules\Departemen\Contracts\DepartemenRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DepartemenRepository implements DepartemenRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('departemen')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_departemen')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function tree(string $idPerusahaan): array
    {
        $all = DB::table('departemen')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_departemen')
            ->get();

        return $this->buildTree($all, null);
    }

    public function findById(string $id): ?object
    {
        return DB::table('departemen')
            ->whereNull('dihapus_pada')
            ->where('id_departemen', $id)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_departemen');
        DB::table('departemen')->insert($data);
        return $this->findById($data['id_departemen']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('departemen')
            ->where('id_departemen', $record->id_departemen)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_departemen);
    }

    public function delete(object $record): void
    {
        DB::table('departemen')
            ->where('id_departemen', $record->id_departemen)
            ->update(RecordHelper::stampDelete());
    }

    private function buildTree(Collection $items, ?string $parentId): array
    {
        return $items->where('id_departemen_induk', $parentId)->values()->map(function ($item) use ($items) {
            $item->children = $this->buildTree($items, $item->id_departemen);
            return $item;
        })->all();
    }
}
```

- [ ] **Step 5: Ganti Service (cuma type hint)**

Ganti isi `app/Modules/Departemen/DepartemenService.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Departemen;

use App\Modules\Departemen\Contracts\DepartemenRepositoryInterface;

class DepartemenService
{
    public function __construct(private readonly DepartemenRepositoryInterface $repo) {}

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

    public function tree(string $idPerusahaan): array
    {
        return $this->repo->tree($idPerusahaan);
    }

    public function findOrFail(string $id): object
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Departemen tidak ditemukan');
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

- [ ] **Step 6: Hapus Model**

Run: `rm "app/Modules/Departemen/DepartemenModel.php"`

- [ ] **Step 7: Jalankan test, pastikan tetap lolos**

Run: `docker exec TMN-BACKEND php artisan test tests/Feature/DepartemenTest.php`
Expected: PASS (6 test lolos)

- [ ] **Step 8: Commit**

Jangan commit — biarkan di working tree.

---

### Task 6: Konversi modul Jabatan

**Files:**
- Delete: `app/Modules/Jabatan/JabatanModel.php`
- Modify: `app/Modules/Jabatan/Contracts/JabatanRepositoryInterface.php`
- Modify: `app/Modules/Jabatan/JabatanRepository.php`
- Modify: `app/Modules/Jabatan/JabatanService.php`
- Test: `tests/Feature/JabatanTest.php`

**Interfaces:**
- Consumes: `App\Support\RecordHelper` (Task 1). Task 2 sudah memastikan `KaryawanRepository` tidak lagi bergantung ke `JabatanModel`.
- Produces: `JabatanRepositoryInterface` dengan return type `?object`/`object`.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/JabatanTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class JabatanTest extends TestCase
{
    use RefreshDatabase;

    private function makeDepartemen(string $idPerusahaan, string $nama = 'Operasional'): string
    {
        $id = (string) Str::uuid();
        DB::table('departemen')->insert([
            'id_departemen'   => $id,
            'id_perusahaan'   => $idPerusahaan,
            'kode_departemen' => 'DEP-' . Str::random(4),
            'nama_departemen' => $nama,
            'aktif'           => 1,
            'dibuat_pada'     => now(),
        ]);
        return $id;
    }

    private function makeJabatan(string $idPerusahaan, ?string $idDepartemen = null, string $nama = 'Staff'): object
    {
        $id = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan'     => $id,
            'id_perusahaan'  => $idPerusahaan,
            'id_departemen'  => $idDepartemen,
            'kode_jabatan'   => 'JBT-' . Str::random(4),
            'nama_jabatan'   => $nama,
            'level'          => 1,
            'aktif'          => 1,
            'dibuat_pada'    => now(),
        ]);
        return DB::table('jabatan')->where('id_jabatan', $id)->first();
    }

    public function test_membuat_jabatan_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/jabatan', [
            'kode_jabatan' => 'JBT-01',
            'nama_jabatan' => 'Manager',
            'level'        => 3,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama_jabatan', 'Manager')
            ->assertJsonPath('data.level', 3);

        $this->assertDatabaseHas('jabatan', [
            'kode_jabatan'  => 'JBT-01',
            'id_perusahaan' => self::PERUSAHAAN_ID,
        ]);
    }

    public function test_list_jabatan_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeJabatan(self::PERUSAHAAN_ID);

        $res = $this->getJson('/api/v1/jabatan');

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
    }

    public function test_filter_jabatan_by_departemen(): void
    {
        $this->actingAsRole('ADMIN');
        $idDepA = $this->makeDepartemen(self::PERUSAHAAN_ID, 'Operasional');
        $idDepB = $this->makeDepartemen(self::PERUSAHAAN_ID, 'Keuangan');
        $this->makeJabatan(self::PERUSAHAAN_ID, $idDepA, 'Supir');
        $this->makeJabatan(self::PERUSAHAAN_ID, $idDepB, 'Akuntan');

        $res = $this->getJson("/api/v1/jabatan?id_departemen={$idDepA}");

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Supir', $data[0]['nama_jabatan']);
    }

    public function test_show_jabatan_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeJabatan(self::PERUSAHAAN_ID);

        $res = $this->getJson("/api/v1/jabatan/{$item->id_jabatan}");

        $res->assertStatus(200)->assertJsonPath('data.id_jabatan', $item->id_jabatan);
    }

    public function test_update_jabatan_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeJabatan(self::PERUSAHAAN_ID, null, 'Lama');

        $res = $this->putJson("/api/v1/jabatan/{$item->id_jabatan}", [
            'nama_jabatan' => 'Baru',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama_jabatan', 'Baru');
    }

    public function test_hapus_jabatan_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeJabatan(self::PERUSAHAAN_ID);

        $res = $this->deleteJson("/api/v1/jabatan/{$item->id_jabatan}");
        $res->assertStatus(200);

        $row = DB::table('jabatan')->where('id_jabatan', $item->id_jabatan)->first();
        $this->assertNotNull($row->dihapus_pada);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan lolos DULU (baseline)**

Run: `docker exec TMN-BACKEND php artisan test tests/Feature/JabatanTest.php`
Expected: PASS (6 test lolos)

- [ ] **Step 3: Ganti Contract interface**

Ganti isi `app/Modules/Jabatan/Contracts/JabatanRepositoryInterface.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Jabatan\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface JabatanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idDepartemen = null): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
}
```

- [ ] **Step 4: Ganti Repository**

Ganti isi `app/Modules/Jabatan/JabatanRepository.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Jabatan;

use App\Modules\Jabatan\Contracts\JabatanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class JabatanRepository implements JabatanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idDepartemen = null): LengthAwarePaginator
    {
        $query = DB::table('jabatan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('level')
            ->orderBy('nama_jabatan');

        if ($idDepartemen !== null) {
            $query->where('id_departemen', $idDepartemen);
        }

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('jabatan')
            ->whereNull('dihapus_pada')
            ->where('id_jabatan', $id)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_jabatan');
        DB::table('jabatan')->insert($data);
        return $this->findById($data['id_jabatan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('jabatan')
            ->where('id_jabatan', $record->id_jabatan)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_jabatan);
    }

    public function delete(object $record): void
    {
        DB::table('jabatan')
            ->where('id_jabatan', $record->id_jabatan)
            ->update(RecordHelper::stampDelete());
    }
}
```

- [ ] **Step 5: Ganti Service (cuma type hint)**

Ganti isi `app/Modules/Jabatan/JabatanService.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Jabatan;

use App\Modules\Jabatan\Contracts\JabatanRepositoryInterface;

class JabatanService
{
    public function __construct(private readonly JabatanRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $idDepartemen = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $idDepartemen);

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
            abort(404, 'Jabatan tidak ditemukan');
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

- [ ] **Step 6: Hapus Model**

Run: `rm "app/Modules/Jabatan/JabatanModel.php"`

- [ ] **Step 7: Jalankan test Jabatan DAN test kompatibilitas Karyawan dari Task 2, pastikan tetap lolos**

Run: `docker exec TMN-BACKEND php artisan test tests/Feature/JabatanTest.php tests/Feature/KaryawanJabatanLokasiTest.php`
Expected: PASS semua (6 + 3 = 9 test lolos)

- [ ] **Step 8: Commit**

Jangan commit — biarkan di working tree.

---

### Task 7: Konversi modul StatusTrip

**Konteks:** Beda dari 4 modul sebelumnya — `StatusTripModel` TIDAK extends `BaseModel` (extends `Model` polos + trait `HasUuidPrimaryKey` saja), tabelnya cuma punya `dibuat_pada`/`dibuat_oleh` (append-only log, tidak ada update/delete/soft-delete), dan Model-nya punya `$casts` (`latitude`/`longitude` => float, `dibuat_pada` => datetime) yang perlu dipindah manual ke Resource.

**Files:**
- Delete: `app/Modules/StatusTrip/StatusTripModel.php`
- Modify: `app/Modules/StatusTrip/Contracts/StatusTripRepositoryInterface.php`
- Modify: `app/Modules/StatusTrip/StatusTripRepository.php`
- Modify: `app/Modules/StatusTrip/StatusTripService.php`
- Modify: `app/Modules/StatusTrip/Resources/StatusTripResource.php`
- Test: `tests/Feature/StatusTripTest.php`

**Interfaces:**
- Consumes: `App\Support\RecordHelper::stampCreate()` (Task 1).
- Produces: `StatusTripRepositoryInterface::create()` return `object` (bukan lagi `StatusTripModel`).

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/StatusTripTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StatusTripTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(): string
    {
        $idTrip = (string) Str::uuid();
        DB::table('trip')->insert([
            'id_trip'     => $idTrip,
            'id_jadwal'   => (string) Str::uuid(),
            'status'      => 'berjalan',
            'dibuat_pada' => now(),
        ]);
        return $idTrip;
    }

    public function test_menambah_status_trip_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $idTrip = $this->makeTrip();

        $res = $this->postJson("/api/v1/trip/{$idTrip}/status", [
            'status'      => 'tiba_tujuan',
            'keterangan'  => 'Sampai di lokasi bongkar',
            'latitude'    => -6.200000,
            'longitude'   => 106.816666,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'tiba_tujuan')
            ->assertJsonPath('data.latitude', -6.2)
            ->assertJsonPath('data.longitude', 106.816666);

        $this->assertDatabaseHas('status_trip', [
            'id_trip' => $idTrip,
            'status'  => 'tiba_tujuan',
        ]);
    }

    public function test_menambah_status_trip_ke_trip_tidak_ada_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/trip/' . Str::uuid()->toString() . '/status', [
            'status' => 'berangkat',
        ]);

        $res->assertStatus(404);
    }

    public function test_list_status_trip_urut_terbaru_dulu(): void
    {
        $this->actingAsRole('ADMIN');
        $idTrip = $this->makeTrip();

        $this->postJson("/api/v1/trip/{$idTrip}/status", ['status' => 'berangkat']);
        $this->postJson("/api/v1/trip/{$idTrip}/status", ['status' => 'tiba_tujuan']);

        $res = $this->getJson("/api/v1/trip/{$idTrip}/status");

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(2, $data);
        $this->assertSame('tiba_tujuan', $data[0]['status']);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan lolos DULU (baseline)**

Run: `docker exec TMN-BACKEND php artisan test tests/Feature/StatusTripTest.php`
Expected: PASS (3 test lolos)

- [ ] **Step 3: Ganti Contract interface**

Ganti isi `app/Modules/StatusTrip/Contracts/StatusTripRepositoryInterface.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\StatusTrip\Contracts;

use Illuminate\Support\Collection;

interface StatusTripRepositoryInterface
{
    public function listByTrip(string $idTrip): Collection;
    public function create(array $data): object;
}
```

- [ ] **Step 4: Ganti Repository**

Ganti isi `app/Modules/StatusTrip/StatusTripRepository.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\StatusTrip;

use App\Modules\StatusTrip\Contracts\StatusTripRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StatusTripRepository implements StatusTripRepositoryInterface
{
    public function listByTrip(string $idTrip): Collection
    {
        return DB::table('status_trip')
            ->where('id_trip', $idTrip)
            ->orderBy('dibuat_pada', 'desc')
            ->get();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_status');
        DB::table('status_trip')->insert($data);
        return DB::table('status_trip')->where('id_status', $data['id_status'])->first();
    }
}
```

- [ ] **Step 5: Ganti Service (cuma type hint)**

Ganti isi `app/Modules/StatusTrip/StatusTripService.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\StatusTrip;

use App\Modules\StatusTrip\Contracts\StatusTripRepositoryInterface;
use App\Modules\Trip\Contracts\TripRepositoryInterface;
use Illuminate\Support\Collection;

class StatusTripService
{
    public function __construct(
        private readonly StatusTripRepositoryInterface $repo,
        private readonly TripRepositoryInterface $tripRepo,
    ) {}

    public function listByTrip(string $idTrip): Collection
    {
        $this->ensureTripExists($idTrip);
        return $this->repo->listByTrip($idTrip);
    }

    public function create(string $idTrip, array $data): object
    {
        $this->ensureTripExists($idTrip);

        return $this->repo->create(array_merge($data, ['id_trip' => $idTrip]));
    }

    private function ensureTripExists(string $idTrip): void
    {
        if (!$this->tripRepo->exists($idTrip)) {
            abort(404, 'Trip tidak ditemukan');
        }
    }
}
```

- [ ] **Step 6: Ganti Resource — tambah cast manual (Eloquent `$casts` sudah hilang)**

Ganti isi `app/Modules/StatusTrip/Resources/StatusTripResource.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\StatusTrip\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StatusTripResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_status'   => $this->id_status,
            'id_trip'     => $this->id_trip,
            'status'      => $this->status,
            'keterangan'  => $this->keterangan,
            'latitude'    => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude'   => $this->longitude !== null ? (float) $this->longitude : null,
            'dibuat_oleh' => $this->dibuat_oleh,
            'dibuat_pada' => $this->dibuat_pada,
        ];
    }
}
```

- [ ] **Step 7: Hapus Model**

Run: `rm "app/Modules/StatusTrip/StatusTripModel.php"`

- [ ] **Step 8: Jalankan test, pastikan tetap lolos**

Run: `docker exec TMN-BACKEND php artisan test tests/Feature/StatusTripTest.php`
Expected: PASS (3 test lolos)

- [ ] **Step 9: Commit**

Jangan commit — biarkan di working tree.

---

### Task 8: Verifikasi penuh Kelompok 1

**Files:** Tidak ada file baru — task ini murni verifikasi end-to-end sebelum kelompok 1 dianggap selesai.

**Interfaces:** Tidak ada.

- [ ] **Step 1: Jalankan seluruh test suite backend**

Run: `docker exec TMN-BACKEND php artisan test`
Expected: Semua test PASS (termasuk 16 test Feature existing yang sudah ada sebelum Kelompok 1 + test baru dari Task 1-7). Tidak boleh ada FAIL atau ERROR.

- [ ] **Step 2: Rebuild image backend & restart container**

Run:
```bash
cd "D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND"
docker compose -f docker-compose.local.yml build backend
docker compose -f docker-compose.local.yml up -d --no-deps backend
```
Expected: Build sukses, container `TMN-BACKEND` restart tanpa error.

- [ ] **Step 3: Cek container tidak crash-loop**

Run: `sleep 15 && docker ps --filter "name=TMN-BACKEND" --format "table {{.Names}}\t{{.Status}}"`
Expected: `TMN-BACKEND` status `Up ...` (bukan `Restarting`).

- [ ] **Step 4: Cek route:list boot bersih**

Run: `docker logs TMN-BACKEND --tail 30`
Expected: Ada baris `INFO  Server running on [http://0.0.0.0:4019]`, tidak ada `Class not found` atau `Class "App\Modules\...\...Model" not found`.

- [ ] **Step 5: Smoke test tiap endpoint via API asli (bukan test suite) — login dulu**

Run:
```bash
TOKEN=$(curl -s -X POST http://localhost:4019/api/v1/auth/login -H "Content-Type: application/json" -d '{"username":"superadmin","password":"Password123!"}' | grep -oP '"token":"\K[^"]+')
echo "TOKEN=$TOKEN"
```
Expected: `TOKEN` terisi string, bukan kosong.

- [ ] **Step 6: Smoke test list endpoint tiap modul Kelompok 1**

Run:
```bash
for path in jenis-kendaraan lokasi-kantor departemen jabatan; do
  echo "=== GET /api/v1/$path ==="
  curl -s -o /dev/null -w "HTTP %{http_code}\n" "http://localhost:4019/api/v1/$path" -H "Authorization: Bearer $TOKEN"
done
echo "=== GET /api/v1/departemen/tree ==="
curl -s -o /dev/null -w "HTTP %{http_code}\n" "http://localhost:4019/api/v1/departemen/tree" -H "Authorization: Bearer $TOKEN"
echo "=== GET /api/v1/karyawan (cek kompatibilitas Task 2) ==="
curl -s -o /dev/null -w "HTTP %{http_code}\n" "http://localhost:4019/api/v1/karyawan" -H "Authorization: Bearer $TOKEN"
```
Expected: Semua `HTTP 200`.

- [ ] **Step 7: Konfirmasi tidak ada sisa referensi ke 5 Model yang sudah dihapus**

Run:
```bash
grep -rln "JenisKendaraanModel\|LokasiKantorModel\|DepartemenModel\|JabatanModel\|StatusTripModel" app --include="*.php"
```
Expected: Output kosong (tidak ada baris apapun) — kalau masih ada hasil, berarti ada file yang kelewat di-update.

- [ ] **Step 8: Commit**

Jangan commit — biarkan seluruh perubahan Kelompok 1 di working tree untuk direview & di-commit manual oleh user.

---

## Ringkasan File yang Berubah

| Modul | Dihapus | Diubah |
|---|---|---|
| Support (baru) | — | `app/Support/RecordHelper.php` (baru) |
| Karyawan (patch kompatibilitas) | — | `KaryawanModel.php`, `KaryawanRepository.php`, `Resources/KaryawanResource.php` |
| JenisKendaraan | `JenisKendaraanModel.php` | `Contracts/...Interface.php`, `...Repository.php`, `...Service.php` |
| LokasiKantor | `LokasiKantorModel.php` | `Contracts/...Interface.php`, `...Repository.php`, `...Service.php` |
| Departemen | `DepartemenModel.php` | `Contracts/...Interface.php`, `...Repository.php`, `...Service.php` |
| Jabatan | `JabatanModel.php` | `Contracts/...Interface.php`, `...Repository.php`, `...Service.php` |
| StatusTrip | `StatusTripModel.php` | `Contracts/...Interface.php`, `...Repository.php`, `...Service.php`, `Resources/StatusTripResource.php` |

**Test baru:** `RecordHelperTest.php`, `KaryawanJabatanLokasiTest.php`, `JenisKendaraanTest.php`, `LokasiKantorTest.php`, `DepartemenTest.php`, `JabatanTest.php`, `StatusTripTest.php` (7 file, ~40 test case).

**Tidak disentuh sama sekali:** Controller & Requests di kelima modul (tidak pernah type-hint Model), semua modul di luar Kelompok 1 kecuali patch kompatibilitas Karyawan.
