<?php

declare(strict_types=1);

namespace App\Modules\Sparepart;

use App\Modules\KategoriSparepart\Contracts\KategoriSparepartRepositoryInterface;
use App\Modules\Sparepart\Contracts\SparepartRepositoryInterface;
use App\Modules\Sparepart\Imports\SparepartImport;
use App\Support\PenyimpananBerkas;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use App\Support\KodeOtomatis;

class SparepartService
{
    public const SATUAN_VALID = ['pcs', 'set', 'liter'];
    private const SATUAN_DEFAULT = 'pcs';
    public const MAKS_FOTO = 10;
    private const TAHUN_MIN = 1900;
    private const PANJANG_MAKS = [
        'kode'          => 50,
        'nama'          => 150,
        'serial_number' => 100,
        'merek'         => 100,
    ];

    public function __construct(
        private readonly SparepartRepositoryInterface $repo,
        private readonly KategoriSparepartRepositoryInterface $kategoriRepo,
    ) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null, ?string $idKategoriSparepart = null): array
    {
        $paginator = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search, $idKategoriSparepart);
        $this->lampirkanHargaBeliTerakhir($paginator->items());
        return $this->toPagedArray($paginator);
    }

    public function listMutasi(string $idSparepart, int $page = 1, int $limit = 10): array
    {
        $this->findOrFail($idSparepart);
        return $this->toPagedArray($this->repo->paginateMutasi($idSparepart, $page, $limit));
    }

    public function listRiwayatHarga(string $idSparepart, string $idPerusahaan, int $page = 1, int $limit = 20): array
    {
        $this->findOrFail($idSparepart, $idPerusahaan);
        return $this->toPagedArray($this->repo->paginateRiwayatHarga($idSparepart, $page, $limit));
    }

    /** @param object[] $records */
    private function lampirkanHargaBeliTerakhir(array $records): void
    {
        if ($records === []) {
            return;
        }
        $ids = array_map(fn (object $r) => (string) $r->id_sparepart, $records);
        $map = $this->repo->hargaBeliTerakhirByIds($ids);
        $foto = $this->repo->fotoByIds($ids);
        foreach ($records as $record) {
            $record->harga_beli_terakhir = $map[$record->id_sparepart] ?? null;
            $record->foto = $foto[$record->id_sparepart] ?? [];
        }
    }

    /** @param UploadedFile[] $files */
    public function tambahFoto(string $id, array $files, string $idPerusahaan): object
    {
        $this->findOrFail($id, $idPerusahaan);

        if ($this->repo->hitungFoto($id) + count($files) > self::MAKS_FOTO) {
            abort(422, 'Maksimal ' . self::MAKS_FOTO . ' foto per spare part');
        }

        DB::transaction(function () use ($id, $files) {
            $urutan = $this->repo->urutanFotoTerakhir($id);

            foreach ($files as $file) {
                $this->repo->insertFoto([
                    'id_sparepart' => $id,
                    'url_file'     => PenyimpananBerkas::simpan($file, 'sparepart'),
                    'nama_asli'    => $file->getClientOriginalName(),
                    'urutan'       => ++$urutan,
                ]);
            }
        });

        return $this->findOrFail($id, $idPerusahaan);
    }

    public function hapusFoto(string $id, string $idFoto, string $idPerusahaan): void
    {
        $this->findOrFail($id, $idPerusahaan);

        if ($this->repo->findFoto($id, $idFoto) === null) {
            abort(404, 'Foto spare part tidak ditemukan');
        }

        $this->repo->softDeleteFoto($idFoto);
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

    public function findOrFail(string $id, ?string $idPerusahaan = null): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || ($idPerusahaan !== null && $record->id_perusahaan !== $idPerusahaan)) {
            abort(404, 'Spare part tidak ditemukan');
        }
        $this->lampirkanHargaBeliTerakhir([$record]);
        return $record;
    }

    public function create(array $data): object
    {
        $data['kode'] = KodeOtomatis::berikutnya((string) $data['id_perusahaan'], 'sparepart');

        if ($this->repo->findByKode($data['id_perusahaan'], $data['kode'])) {
            abort(409, 'Kode spare part sudah digunakan');
        }
        $this->pastikanKategoriMilikPerusahaan($data['id_kategori_sparepart'] ?? null, (string) $data['id_perusahaan']);

        return DB::transaction(function () use ($data) {
            $record = $this->repo->create($data);
            $this->repo->insertRiwayatHarga([
                'id_sparepart' => $record->id_sparepart,
                'harga_lama'   => null,
                'harga_baru'   => (float) ($data['harga_standar'] ?? 0),
                'sumber'       => 'manual',
                'keterangan'   => null,
            ]);
            return $record;
        });
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (isset($data['kode']) && $data['kode'] !== $record->kode) {
            if ($this->repo->findByKode($idPerusahaan, $data['kode'], $id)) {
                abort(409, 'Kode spare part sudah digunakan');
            }
        }
        $this->pastikanKategoriMilikPerusahaan($data['id_kategori_sparepart'] ?? null, $idPerusahaan);

        $keteranganHarga = $data['keterangan_harga'] ?? null;
        unset($data['keterangan_harga']);

        $hargaLama = round((float) $record->harga_standar, 2);
        $hargaBaru = array_key_exists('harga_standar', $data) ? round((float) $data['harga_standar'], 2) : $hargaLama;
        $hargaBerubah = $hargaBaru !== $hargaLama;

        return DB::transaction(function () use ($record, $data, $hargaBerubah, $hargaLama, $hargaBaru, $keteranganHarga) {
            $updated = $this->repo->update($record, $data);
            if ($hargaBerubah) {
                $this->repo->insertRiwayatHarga([
                    'id_sparepart' => $record->id_sparepart,
                    'harga_lama'   => $hargaLama,
                    'harga_baru'   => $hargaBaru,
                    'sumber'       => 'manual',
                    'keterangan'   => $keteranganHarga !== null && $keteranganHarga !== '' ? $keteranganHarga : null,
                ]);
            }
            $this->lampirkanHargaBeliTerakhir([$updated]);
            return $updated;
        });
    }

    private function pastikanKategoriMilikPerusahaan(?string $idKategori, string $idPerusahaan): void
    {
        if ($idKategori === null || $idKategori === '') {
            return;
        }
        $kategori = $this->kategoriRepo->findById($idKategori);
        if ($kategori === null || (string) $kategori->id_perusahaan !== $idPerusahaan) {
            abort(422, 'Kategori tidak ditemukan');
        }
    }

    public function delete(string $id, ?string $idPerusahaan = null): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        $dipakai = $this->repo->countActiveUsage($id);
        if ($dipakai > 0) {
            abort(422, "Spare part masih dipakai di {$dipakai} item catatan servis aktif, tidak bisa dihapus");
        }

        if ($this->repo->dipakaiRelasiLain($id)) {
            abort(422, 'Spare part masih dipakai di pembelian/paket perawatan — nonaktifkan saja');
        }

        $this->repo->delete($record);
    }

    /**
     * Jalur manual hanya untuk jenis 'penyesuaian' (koreksi opname/saldo awal/retur),
     * qty delta bertanda dan hasil akhir boleh minus. Barang masuk normal dicatat
     * lewat realisasi pembelian sparepart.
     */
    public function mutasiStok(string $id, array $data): object
    {
        return DB::transaction(function () use ($id, $data) {
            $record = $this->repo->findByIdForUpdate($id);
            if ($record === null) {
                abort(404, 'Spare part tidak ditemukan');
            }

            $this->repo->setStok($id, (int) $record->stok + (int) $data['qty']);
            $this->repo->insertMutasi([
                'id_sparepart' => $id,
                'jenis'        => $data['jenis'],
                'qty'          => (int) $data['qty'],
                'harga'        => null,
                'keterangan'   => $data['keterangan'] ?? null,
                'tanggal'      => now()->toDateString(),
            ]);

            return $this->findOrFail($id);
        });
    }

    /** @return array{berhasil: int, gagal: array<int, array{baris: int, nama: string, alasan: string}>} */
    public function import(UploadedFile $file, string $idPerusahaan): array
    {
        $rows = Excel::toArray(new SparepartImport(), $file)[0] ?? [];

        $frekuensiKode = [];
        foreach ($rows as $row) {
            $kode = $this->kodeDariCell($row['kode'] ?? null);
            if ($kode !== null) {
                $frekuensiKode[$kode] = ($frekuensiKode[$kode] ?? 0) + 1;
            }
        }

        $berhasil = 0;
        $gagal = [];
        $tahunMaks = now()->year + 1;

        foreach ($rows as $index => $row) {
            $baris = $index + 2;

            $kode = $this->kodeDariCell($row['kode'] ?? null);
            $nama = $this->cellToString($row['nama'] ?? null);
            $serialNumber = $this->cellToString($row['serial_number'] ?? null);
            $merek = $this->cellToString($row['merek'] ?? null);
            $tahunRaw = $this->cellToString($row['tahun'] ?? null);
            $kategoriRaw = $this->cellToString($row['kategori'] ?? null);
            $satuanRaw = $this->cellToString($row['satuan'] ?? null);
            $hargaRaw = $this->cellToString($row['harga_standar'] ?? null);
            $stokRaw = $this->cellToString($row['stok_awal'] ?? null);

            if ($kode === null && $nama === null && $serialNumber === null && $merek === null && $tahunRaw === null
                && $kategoriRaw === null && $satuanRaw === null && $hargaRaw === null && $stokRaw === null) {
                continue;
            }

            $namaLaporan = $nama ?? '';

            if ($kode === null) {
                $gagal[] = ['baris' => $baris, 'nama' => $namaLaporan, 'alasan' => 'Kode wajib diisi'];
                continue;
            }

            if ($nama === null) {
                $gagal[] = ['baris' => $baris, 'nama' => '', 'alasan' => 'Nama wajib diisi'];
                continue;
            }

            if ($serialNumber === null) {
                $gagal[] = ['baris' => $baris, 'nama' => $nama, 'alasan' => 'Serial number wajib diisi'];
                continue;
            }

            $alasanPanjang = $this->cekPanjang([
                'kode'          => $kode,
                'nama'          => $nama,
                'serial_number' => $serialNumber,
                'merek'         => $merek,
            ]);
            if ($alasanPanjang !== null) {
                $gagal[] = ['baris' => $baris, 'nama' => $nama, 'alasan' => $alasanPanjang];
                continue;
            }

            if ($this->repo->findByKode($idPerusahaan, $kode) !== null) {
                $gagal[] = ['baris' => $baris, 'nama' => $nama, 'alasan' => 'Kode spare part sudah digunakan'];
                continue;
            }

            if (($frekuensiKode[$kode] ?? 0) > 1) {
                $gagal[] = ['baris' => $baris, 'nama' => $nama, 'alasan' => 'Kode duplikat di dalam file'];
                continue;
            }

            $idKategori = null;
            if ($kategoriRaw !== null) {
                $kategori = $this->kategoriRepo->findByNama($idPerusahaan, $kategoriRaw);
                if ($kategori === null) {
                    $gagal[] = ['baris' => $baris, 'nama' => $nama, 'alasan' => 'Kategori tidak ditemukan'];
                    continue;
                }
                $idKategori = (string) $kategori->id_kategori_sparepart;
            }

            $satuan = self::SATUAN_DEFAULT;
            if ($satuanRaw !== null) {
                $satuan = mb_strtolower($satuanRaw);
                if (!in_array($satuan, self::SATUAN_VALID, true)) {
                    $gagal[] = ['baris' => $baris, 'nama' => $nama, 'alasan' => 'Satuan harus pcs, set, atau liter'];
                    continue;
                }
            }

            $tahun = null;
            if ($tahunRaw !== null) {
                $tahun = filter_var($tahunRaw, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => self::TAHUN_MIN, 'max_range' => $tahunMaks],
                ]);
                if ($tahun === false) {
                    $gagal[] = ['baris' => $baris, 'nama' => $nama, 'alasan' => 'Tahun tidak valid'];
                    continue;
                }
            }

            $hargaStandar = 0.0;
            if ($hargaRaw !== null) {
                if (!is_numeric($hargaRaw) || (float) $hargaRaw < 0) {
                    $gagal[] = ['baris' => $baris, 'nama' => $nama, 'alasan' => 'Harga standar tidak valid'];
                    continue;
                }
                $hargaStandar = (float) $hargaRaw;
            }

            $stokAwal = 0;
            if ($stokRaw !== null) {
                $stokAwal = filter_var($stokRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($stokAwal === false) {
                    $gagal[] = ['baris' => $baris, 'nama' => $nama, 'alasan' => 'Stok awal tidak valid'];
                    continue;
                }
            }

            DB::transaction(function () use ($idPerusahaan, $kode, $nama, $serialNumber, $merek, $tahun, $idKategori, $satuan, $hargaStandar, $stokAwal) {
                $record = $this->repo->create([
                    'id_perusahaan'         => $idPerusahaan,
                    'kode'                  => $kode,
                    'nama'                  => $nama,
                    'serial_number'         => $serialNumber,
                    'merek'                 => $merek,
                    'tahun'                 => $tahun,
                    'id_kategori_sparepart' => $idKategori,
                    'satuan'                => $satuan,
                    'harga_standar'         => $hargaStandar,
                    'stok'                  => $stokAwal,
                    'aktif'                 => 1,
                ]);

                $this->repo->insertRiwayatHarga([
                    'id_sparepart' => $record->id_sparepart,
                    'harga_lama'   => null,
                    'harga_baru'   => $hargaStandar,
                    'sumber'       => 'import',
                    'keterangan'   => 'Import Excel',
                ]);

                if ($stokAwal > 0) {
                    $this->repo->insertMutasi([
                        'id_sparepart' => $record->id_sparepart,
                        'jenis'        => 'penyesuaian',
                        'qty'          => $stokAwal,
                        'harga'        => $hargaStandar > 0 ? $hargaStandar : null,
                        'keterangan'   => 'Saldo awal (import Excel)',
                        'tanggal'      => now()->toDateString(),
                    ]);
                }
            });
            $berhasil++;
        }

        return ['berhasil' => $berhasil, 'gagal' => $gagal];
    }

    private function cekPanjang(array $nilai): ?string
    {
        $label = [
            'kode'          => 'Kode',
            'nama'          => 'Nama',
            'serial_number' => 'Serial number',
            'merek'         => 'Merek',
        ];

        foreach (self::PANJANG_MAKS as $field => $maks) {
            $teks = $nilai[$field] ?? null;
            if ($teks !== null && mb_strlen($teks) > $maks) {
                return "{$label[$field]} maksimal {$maks} karakter";
            }
        }

        return null;
    }

    private function kodeDariCell(mixed $value): ?string
    {
        $kode = $this->cellToString($value);
        return $kode === null ? null : mb_strtoupper($kode);
    }

    private function cellToString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }
        return (string) $value;
    }
}
