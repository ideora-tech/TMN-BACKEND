<?php

declare(strict_types=1);

namespace App\Modules\Klien;

use App\Modules\Klien\Contracts\KlienRepositoryInterface;
use App\Support\KodeOtomatis;
use App\Support\Impor\BukuTemplateImpor;
use App\Support\Impor\LembarDataImpor;
use App\Support\Impor\PembacaImpor;
use Illuminate\Http\UploadedFile;
use App\Modules\Klien\Requests\StoreKlienRequest;

class KlienService
{
    public const KOLOM_EXCEL = [
        ['kunci' => 'nama_klien', 'wajib' => true,  'teks' => true, 'keterangan' => 'Nama klien, maksimal 200 karakter. Nama yang sudah terdaftar ditolak.', 'contoh' => 'PT Maju Bersama'],
        ['kunci' => 'kontak_pic', 'wajib' => false, 'teks' => true, 'keterangan' => 'Nama penanggung jawab di pihak klien.', 'contoh' => 'Ibu Rina'],
        ['kunci' => 'telepon',    'wajib' => false, 'teks' => true, 'keterangan' => 'Maksimal 30 karakter.', 'contoh' => '0215551234'],
        ['kunci' => 'email',      'wajib' => false, 'teks' => true, 'keterangan' => 'Alamat email yang valid.', 'contoh' => 'rina@majubersama.co.id'],
        ['kunci' => 'alamat',     'wajib' => false, 'teks' => true, 'keterangan' => 'Alamat kantor klien.', 'contoh' => 'Jl. Sudirman No. 10, Jakarta'],
        ['kunci' => 'aktif',      'wajib' => false, 'teks' => true, 'keterangan' => 'ya atau tidak. Kosong berarti ya.', 'contoh' => 'ya'],
    ];

    public function __construct(private readonly KlienRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null, ?string $aktif = null, ?string $urut = null, ?string $arah = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search, $aktif, $urut, $arah);

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

    public function findOrFail(string $id, ?string $idPerusahaan = null): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || ($idPerusahaan !== null && $record->id_perusahaan !== $idPerusahaan)) {
            abort(404, 'Klien tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        $idPerusahaan = $data['id_perusahaan'];
        $data['kode_klien'] = KodeOtomatis::berikutnya($idPerusahaan, 'klien');

        if ($this->repo->findByKode($idPerusahaan, $data['kode_klien'])) {
            abort(409, 'Kode klien sudah digunakan');
        }

        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (isset($data['kode_klien']) && $data['kode_klien'] !== $record->kode_klien) {
            if ($this->repo->findByKode($idPerusahaan, $data['kode_klien'])) {
                abort(409, 'Kode klien sudah digunakan');
            }
        }

        return $this->repo->update($record, $data);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($this->repo->punyaRelasiAktif($id)) {
            abort(422, 'Klien masih memiliki penawaran/proyek/invoice — nonaktifkan saja klien ini');
        }

        $this->repo->delete($record);
    }

    public function riwayatProyek(string $idKlien, string $idPerusahaan, int $page = 1, int $limit = 10): array
    {
        $klien = $this->repo->findById($idKlien);
        if ($klien === null || $klien->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Klien tidak ditemukan');
        }

        $result = $this->repo->paginateProyek($idKlien, $page, $limit);

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

    public function templateImpor(string $idPerusahaan): BukuTemplateImpor
    {
        return new BukuTemplateImpor('Klien', self::KOLOM_EXCEL, [
            'Import hanya menambah klien baru. Kode klien dibuat otomatis.',
            'Klien dengan nama yang sudah terdaftar ditolak dan tidak mengubah data lama.',
        ]);
    }

    public function exportExcel(string $idPerusahaan): LembarDataImpor
    {
        $data = PembacaImpor::batasiExport(
            $this->repo->semuaUntukExport($idPerusahaan, PembacaImpor::MAKS_EXPORT + 1),
            PembacaImpor::MAKS_EXPORT,
            'klien',
        );

        $baris = array_map(static fn ($k) => [
            (string) $k->nama_klien, $k->kontak_pic, $k->telepon, $k->email, $k->alamat,
            (int) $k->aktif === 1 ? 'ya' : 'tidak',
        ], $data);

        return new LembarDataImpor('Klien', self::KOLOM_EXCEL, $baris);
    }

    public function import(UploadedFile $file, string $idPerusahaan): array
    {
        $baris = PembacaImpor::baca($file, array_column(self::KOLOM_EXCEL, 'kunci'), ['nama_klien'], 'klien');

        $frekuensi = PembacaImpor::frekuensi($baris, static fn (array $nilai) => PembacaImpor::kecil($nilai['nama_klien']));
        $terpakai  = array_flip(array_map(
            static fn ($nama) => PembacaImpor::kecil((string) $nama),
            $this->repo->namaTerpakai($idPerusahaan),
        ));
        $aturan = (new StoreKlienRequest())->rules();

        return PembacaImpor::jalankan(
            $baris,
            static fn (array $nilai) => $nilai['nama_klien'] ?? '',
            function (array $nilai) use ($frekuensi, $terpakai, $aturan, $idPerusahaan): ?string {
                if ($nilai['nama_klien'] === null) {
                    return 'Kolom nama_klien wajib diisi';
                }
                $kunci = PembacaImpor::kecil($nilai['nama_klien']);
                if (($frekuensi[$kunci] ?? 0) > 1) {
                    return 'Nama klien duplikat di dalam file';
                }
                if (isset($terpakai[$kunci])) {
                    return 'Nama klien sudah terdaftar';
                }

                $aktif = PembacaImpor::yaTidakAtau($nilai['aktif'], true);
                if ($aktif === null) {
                    return 'Kolom aktif harus ya atau tidak';
                }

                $data = [
                    'nama_klien' => $nilai['nama_klien'],
                    'kontak_pic' => $nilai['kontak_pic'],
                    'telepon'    => $nilai['telepon'],
                    'email'      => $nilai['email'],
                    'alamat'     => $nilai['alamat'],
                    'aktif'      => $aktif,
                ];
                $galat = PembacaImpor::validasi($data, $aturan);
                if ($galat !== null) {
                    return $galat;
                }

                PembacaImpor::denganKodeBaru(fn () => $this->create(array_merge($data, ['id_perusahaan' => $idPerusahaan])));

                return null;
            },
        );
    }
}
