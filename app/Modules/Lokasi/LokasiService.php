<?php

declare(strict_types=1);

namespace App\Modules\Lokasi;

use App\Modules\Lokasi\Contracts\LokasiRepositoryInterface;
use App\Support\Impor\BukuTemplateImpor;
use App\Support\Impor\LembarDataImpor;
use App\Support\Impor\PembacaImpor;
use Illuminate\Http\UploadedFile;
use App\Modules\Lokasi\Requests\StoreLokasiRequest;

class LokasiService
{
    public const KOLOM_EXCEL = [
        ['kunci' => 'nama_lokasi', 'wajib' => true,  'teks' => true, 'keterangan' => 'Nama lokasi, maksimal 150 karakter. Nama dan kota yang sama dengan data terdaftar ditolak.', 'contoh' => 'Gudang Cikarang'],
        ['kunci' => 'kota',        'wajib' => false, 'teks' => true, 'keterangan' => 'Maksimal 100 karakter.', 'contoh' => 'Bekasi'],
        ['kunci' => 'alamat',      'wajib' => false, 'teks' => true, 'keterangan' => 'Alamat lengkap lokasi.', 'contoh' => 'Kawasan Industri Jababeka Blok A-1'],
        ['kunci' => 'aktif',       'wajib' => false, 'teks' => true, 'keterangan' => 'ya atau tidak. Kosong berarti ya.', 'contoh' => 'ya'],
    ];

    public function __construct(private readonly LokasiRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search);

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

    public function findOrFail(string $id, string $idPerusahaan): LokasiModel
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Lokasi tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): LokasiModel
    {
        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): LokasiModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        return $this->repo->update($record, $data);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($this->repo->dipakaiRute($id)) {
            abort(422, 'Lokasi masih dipakai sebagai asal/tujuan rute — nonaktifkan saja');
        }

        $this->repo->delete($record);
    }

    public function templateImpor(string $idPerusahaan): BukuTemplateImpor
    {
        return new BukuTemplateImpor('Lokasi', self::KOLOM_EXCEL, [
            'Import hanya menambah lokasi baru.',
            'Lokasi dengan nama dan kota yang sama dengan data terdaftar ditolak dan tidak mengubah data lama.',
        ]);
    }

    public function exportExcel(string $idPerusahaan): LembarDataImpor
    {
        $data = PembacaImpor::batasiExport(
            $this->repo->semuaMilikPerusahaan($idPerusahaan, PembacaImpor::MAKS_EXPORT + 1),
            PembacaImpor::MAKS_EXPORT,
            'lokasi',
        );

        $baris = array_map(static fn ($l) => [
            (string) $l->nama_lokasi, $l->kota, $l->alamat, (int) $l->aktif === 1 ? 'ya' : 'tidak',
        ], $data);

        return new LembarDataImpor('Lokasi', self::KOLOM_EXCEL, $baris);
    }

    public function import(UploadedFile $file, string $idPerusahaan): array
    {
        $baris = PembacaImpor::baca($file, array_column(self::KOLOM_EXCEL, 'kunci'), ['nama_lokasi'], 'lokasi');

        $kunciLokasi = static fn (?string $nama, ?string $kota) => $nama === null
            ? null
            : PembacaImpor::kecil($nama) . '|' . (PembacaImpor::kecil($kota) ?? '');

        $frekuensi = PembacaImpor::frekuensi($baris, static fn (array $nilai) => $kunciLokasi($nilai['nama_lokasi'], $nilai['kota']));
        $terpakai  = [];
        foreach ($this->repo->semuaMilikPerusahaan($idPerusahaan, PHP_INT_MAX) as $lokasi) {
            $terpakai[$kunciLokasi((string) $lokasi->nama_lokasi, $lokasi->kota !== null ? (string) $lokasi->kota : null)] = true;
        }
        $aturan = (new StoreLokasiRequest())->rules();

        return PembacaImpor::jalankan(
            $baris,
            static fn (array $nilai) => $nilai['nama_lokasi'] ?? '',
            function (array $nilai) use ($frekuensi, $terpakai, $aturan, $kunciLokasi, $idPerusahaan): ?string {
                if ($nilai['nama_lokasi'] === null) {
                    return 'Kolom nama_lokasi wajib diisi';
                }
                $kunci = $kunciLokasi($nilai['nama_lokasi'], $nilai['kota']);
                if (($frekuensi[$kunci] ?? 0) > 1) {
                    return 'Nama lokasi dan kota duplikat di dalam file';
                }
                if (isset($terpakai[$kunci])) {
                    return 'Lokasi dengan nama dan kota ini sudah terdaftar';
                }

                $aktif = PembacaImpor::yaTidakAtau($nilai['aktif'], true);
                if ($aktif === null) {
                    return 'Kolom aktif harus ya atau tidak';
                }

                $data = [
                    'nama_lokasi' => $nilai['nama_lokasi'],
                    'kota'        => $nilai['kota'],
                    'alamat'      => $nilai['alamat'],
                    'aktif'       => $aktif,
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
