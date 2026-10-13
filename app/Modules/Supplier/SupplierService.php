<?php
declare(strict_types=1);

namespace App\Modules\Supplier;

use App\Modules\Supplier\Contracts\SupplierRepositoryInterface;
use App\Support\Impor\BukuTemplateImpor;
use App\Support\Impor\LembarDataImpor;
use App\Support\Impor\PembacaImpor;
use Illuminate\Http\UploadedFile;
use App\Modules\Supplier\Requests\StoreSupplierRequest;

class SupplierService
{
    public const KOLOM_EXCEL = [
        ['kunci' => 'nama',    'wajib' => true,  'teks' => true, 'keterangan' => 'Nama toko atau supplier, maksimal 150 karakter. Nama yang sudah terdaftar ditolak.', 'contoh' => 'Toko Sumber Jaya'],
        ['kunci' => 'telepon', 'wajib' => false, 'teks' => true, 'keterangan' => 'Maksimal 30 karakter.', 'contoh' => '081234567890'],
        ['kunci' => 'alamat',  'wajib' => false, 'teks' => true, 'keterangan' => 'Alamat supplier.', 'contoh' => 'Jl. Raya Bekasi KM 21'],
        ['kunci' => 'aktif',   'wajib' => false, 'teks' => true, 'keterangan' => 'ya atau tidak. Kosong berarti ya.', 'contoh' => 'ya'],
    ];

    public function __construct(private readonly SupplierRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null, ?int $aktif = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search, $aktif);

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

    public function findOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Supplier tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        return $this->repo->update($record, $data);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($this->repo->dipakaiPembelian($id)) {
            abort(422, 'Supplier masih dipakai di pengajuan pembelian sparepart — nonaktifkan saja');
        }

        $this->repo->delete($record);
    }

    public function templateImpor(string $idPerusahaan): BukuTemplateImpor
    {
        return new BukuTemplateImpor('Supplier', self::KOLOM_EXCEL, [
            'Import hanya menambah supplier baru.',
            'Supplier dengan nama yang sudah terdaftar ditolak dan tidak mengubah data lama.',
        ]);
    }

    public function exportExcel(string $idPerusahaan): LembarDataImpor
    {
        $data = PembacaImpor::batasiExport(
            $this->repo->semuaUntukExport($idPerusahaan, PembacaImpor::MAKS_EXPORT + 1),
            PembacaImpor::MAKS_EXPORT,
            'supplier',
        );

        $baris = array_map(static fn ($s) => [
            (string) $s->nama, $s->telepon, $s->alamat, (int) $s->aktif === 1 ? 'ya' : 'tidak',
        ], $data);

        return new LembarDataImpor('Supplier', self::KOLOM_EXCEL, $baris);
    }

    public function import(UploadedFile $file, string $idPerusahaan): array
    {
        $baris = PembacaImpor::baca($file, array_column(self::KOLOM_EXCEL, 'kunci'), ['nama'], 'supplier');

        $frekuensi = PembacaImpor::frekuensi($baris, static fn (array $nilai) => PembacaImpor::kecil($nilai['nama']));
        $terpakai  = array_flip(array_map(
            static fn ($nama) => PembacaImpor::kecil((string) $nama),
            $this->repo->namaTerpakai($idPerusahaan),
        ));
        $aturan = (new StoreSupplierRequest())->rules();

        return PembacaImpor::jalankan(
            $baris,
            static fn (array $nilai) => $nilai['nama'] ?? '',
            function (array $nilai) use ($frekuensi, $terpakai, $aturan, $idPerusahaan): ?string {
                if ($nilai['nama'] === null) {
                    return 'Kolom nama wajib diisi';
                }
                $kunci = PembacaImpor::kecil($nilai['nama']);
                if (($frekuensi[$kunci] ?? 0) > 1) {
                    return 'Nama supplier duplikat di dalam file';
                }
                if (isset($terpakai[$kunci])) {
                    return 'Nama supplier sudah terdaftar';
                }

                $aktif = PembacaImpor::yaTidakAtau($nilai['aktif'], true);
                if ($aktif === null) {
                    return 'Kolom aktif harus ya atau tidak';
                }

                $data = [
                    'nama'    => $nilai['nama'],
                    'telepon' => $nilai['telepon'],
                    'alamat'  => $nilai['alamat'],
                    'aktif'   => $aktif,
                ];
                $galat = PembacaImpor::validasi($data, $aturan);
                if ($galat !== null) {
                    return $galat;
                }

                $this->create(array_merge($data, ['id_perusahaan' => $idPerusahaan]));

                return null;
            },
        );
    }
}
