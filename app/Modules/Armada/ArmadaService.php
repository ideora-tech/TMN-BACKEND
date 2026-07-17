<?php

declare(strict_types=1);

namespace App\Modules\Armada;

use App\Modules\Armada\Contracts\ArmadaRepositoryInterface;
use App\Modules\Armada\Imports\ArmadaImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ArmadaService
{
    private const STATUS_VALID = ['tersedia', 'digunakan', 'perawatan', 'tidak_aktif'];
    private const STATUS_DEFAULT = 'tersedia';
    private const TAHUN_MIN = 1950;
    private const TAHUN_MAX = 2100;
    private const BAHAN_BAKAR_VALID = ['solar', 'bensin', 'gas', 'listrik', 'hybrid'];
    private const KONDISI_BELI_VALID = ['baru', 'bekas'];

    public function __construct(private readonly ArmadaRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $status = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $status);

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

    public function findOrFail(string $id): ArmadaModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Armada tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data, ?UploadedFile $foto = null): ArmadaModel
    {
        $existing = $this->repo->findByNopol($data['nopol']);
        if ($existing !== null) {
            abort(409, 'Nomor polisi sudah terdaftar');
        }

        $this->pastikanNomorRangkaUnik($data['nomor_rangka'] ?? null);

        if ($foto !== null) {
            $data['url_foto'] = $this->simpanFoto($foto);
        }
        unset($data['foto']);

        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan, ?UploadedFile $foto = null): ArmadaModel
    {
        $record = $this->findOrFail($id);

        if (isset($data['nopol']) && $data['nopol'] !== $record->nopol) {
            $existing = $this->repo->findByNopol($data['nopol']);
            if ($existing !== null) {
                abort(409, 'Nomor polisi sudah terdaftar');
            }
        }

        if (isset($data['nomor_rangka']) && $data['nomor_rangka'] !== $record->nomor_rangka) {
            $this->pastikanNomorRangkaUnik($data['nomor_rangka']);
        }

        if ($foto !== null) {
            $data['url_foto'] = $this->simpanFoto($foto);
        }
        unset($data['foto']);

        return $this->repo->update($record, $data);
    }

    private function pastikanNomorRangkaUnik(?string $nomorRangka): void
    {
        if ($nomorRangka === null || $nomorRangka === '') {
            return;
        }
        if ($this->repo->findByNomorRangka($nomorRangka) !== null) {
            abort(409, 'Nomor rangka sudah terdaftar');
        }
    }

    private function simpanFoto(UploadedFile $foto): string
    {
        $path = $foto->store('armada', 'public');

        return Storage::disk('public')->url($path);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }

    /**
     * Import armada dari file Excel (template 14 kolom — kolom detail opsional; template lama 5 kolom tetap valid).
     * Mode "sebagian masuk + laporan gagal" — baris valid tetap di-insert walau
     * ada baris lain yang gagal; baris kosong total dilewati tanpa dihitung.
     *
     * @return array{berhasil: int, gagal: array<int, array{baris: int, nopol: string, alasan: string}>}
     */
    public function import(UploadedFile $file, string $idPerusahaan): array
    {
        $rows = Excel::toArray(new ArmadaImport(), $file)[0] ?? [];

        $frekuensiNopol = [];
        foreach ($rows as $row) {
            $nopol = $this->cellToString($row['nopol'] ?? null);
            if ($nopol !== null) {
                $frekuensiNopol[$nopol] = ($frekuensiNopol[$nopol] ?? 0) + 1;
            }
        }

        $frekuensiRangka = [];
        foreach ($rows as $row) {
            $nr = $this->cellToString($row['nomor_rangka'] ?? null);
            if ($nr !== null) {
                $frekuensiRangka[$nr] = ($frekuensiRangka[$nr] ?? 0) + 1;
            }
        }

        $berhasil = 0;
        $gagal = [];

        foreach ($rows as $index => $row) {
            $baris = $index + 2;

            $nopol          = $this->cellToString($row['nopol'] ?? null);
            $merk           = $this->cellToString($row['merk'] ?? null);
            $model          = $this->cellToString($row['model'] ?? null);
            $tahunRaw       = $this->cellToString($row['tahun'] ?? null);
            $statusRaw      = $this->cellToString($row['status'] ?? null);
            $nomorRangka    = $this->cellToString($row['nomor_rangka'] ?? null);
            $nomorMesin     = $this->cellToString($row['nomor_mesin'] ?? null);
            $warna          = $this->cellToString($row['warna'] ?? null);
            $bahanBakar     = $this->cellToString($row['jenis_bahan_bakar'] ?? null);
            $kapasitasRaw   = $this->cellToString($row['kapasitas_muatan_kg'] ?? null);
            $tanggalBeliRaw = $this->cellToString($row['tanggal_beli'] ?? null);
            $hargaBeliRaw   = $this->cellToString($row['harga_beli'] ?? null);
            $kondisiBeli    = $this->cellToString($row['kondisi_beli'] ?? null);
            $keterangan     = $this->cellToString($row['keterangan'] ?? null);

            $semuaSel = [
                $nopol, $merk, $model, $tahunRaw, $statusRaw,
                $nomorRangka, $nomorMesin, $warna, $bahanBakar, $kapasitasRaw,
                $tanggalBeliRaw, $hargaBeliRaw, $kondisiBeli, $keterangan,
            ];
            if (array_filter($semuaSel, static fn ($v) => $v !== null) === []) {
                continue;
            }

            if ($nopol === null) {
                $gagal[] = ['baris' => $baris, 'nopol' => '', 'alasan' => 'Nopol wajib diisi'];
                continue;
            }

            if ($this->repo->findByNopol($nopol) !== null) {
                $gagal[] = ['baris' => $baris, 'nopol' => $nopol, 'alasan' => 'Nopol sudah terdaftar'];
                continue;
            }

            if (($frekuensiNopol[$nopol] ?? 0) > 1) {
                $gagal[] = ['baris' => $baris, 'nopol' => $nopol, 'alasan' => 'Nopol duplikat di dalam file'];
                continue;
            }

            $tahun = null;
            if ($tahunRaw !== null) {
                if (!is_numeric($tahunRaw) || (int) $tahunRaw < self::TAHUN_MIN || (int) $tahunRaw > self::TAHUN_MAX) {
                    $gagal[] = ['baris' => $baris, 'nopol' => $nopol, 'alasan' => 'Tahun tidak valid'];
                    continue;
                }
                $tahun = (int) $tahunRaw;
            }

            $status = self::STATUS_DEFAULT;
            if ($statusRaw !== null) {
                if (!in_array($statusRaw, self::STATUS_VALID, true)) {
                    $gagal[] = ['baris' => $baris, 'nopol' => $nopol, 'alasan' => 'Status tidak valid'];
                    continue;
                }
                $status = $statusRaw;
            }

            if ($nomorRangka !== null) {
                if ($this->repo->findByNomorRangka($nomorRangka) !== null) {
                    $gagal[] = ['baris' => $baris, 'nopol' => $nopol, 'alasan' => 'Nomor rangka sudah terdaftar'];
                    continue;
                }
                if (($frekuensiRangka[$nomorRangka] ?? 0) > 1) {
                    $gagal[] = ['baris' => $baris, 'nopol' => $nopol, 'alasan' => 'Nomor rangka duplikat di dalam file'];
                    continue;
                }
            }

            if ($bahanBakar !== null && !in_array($bahanBakar, self::BAHAN_BAKAR_VALID, true)) {
                $gagal[] = ['baris' => $baris, 'nopol' => $nopol, 'alasan' => 'Jenis bahan bakar tidak valid'];
                continue;
            }

            if ($kondisiBeli !== null && !in_array($kondisiBeli, self::KONDISI_BELI_VALID, true)) {
                $gagal[] = ['baris' => $baris, 'nopol' => $nopol, 'alasan' => 'Kondisi beli tidak valid'];
                continue;
            }

            $kapasitas = null;
            if ($kapasitasRaw !== null) {
                if (!is_numeric($kapasitasRaw) || (int) $kapasitasRaw < 0) {
                    $gagal[] = ['baris' => $baris, 'nopol' => $nopol, 'alasan' => 'Kapasitas muatan tidak valid'];
                    continue;
                }
                $kapasitas = (int) $kapasitasRaw;
            }

            $hargaBeli = null;
            if ($hargaBeliRaw !== null) {
                if (!is_numeric($hargaBeliRaw) || (float) $hargaBeliRaw < 0) {
                    $gagal[] = ['baris' => $baris, 'nopol' => $nopol, 'alasan' => 'Harga beli tidak valid'];
                    continue;
                }
                $hargaBeli = (float) $hargaBeliRaw;
            }

            $tanggalBeli = null;
            if ($tanggalBeliRaw !== null) {
                $tanggalBeli = $this->parseTanggal($tanggalBeliRaw);
                if ($tanggalBeli === null) {
                    $gagal[] = ['baris' => $baris, 'nopol' => $nopol, 'alasan' => 'Tanggal beli tidak valid (format YYYY-MM-DD)'];
                    continue;
                }
            }

            $this->repo->create([
                'id_perusahaan'       => $idPerusahaan,
                'nopol'               => $nopol,
                'merk'                => $merk,
                'model'               => $model,
                'tahun'               => $tahun,
                'status'              => $status,
                'nomor_rangka'        => $nomorRangka,
                'nomor_mesin'         => $nomorMesin,
                'warna'               => $warna,
                'jenis_bahan_bakar'   => $bahanBakar,
                'kapasitas_muatan_kg' => $kapasitas,
                'tanggal_beli'        => $tanggalBeli,
                'harga_beli'          => $hargaBeli,
                'kondisi_beli'        => $kondisiBeli,
                'keterangan'          => $keterangan,
            ]);
            $berhasil++;
        }

        return ['berhasil' => $berhasil, 'gagal' => $gagal];
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

    /**
     * Terima 'YYYY-MM-DD' atau angka serial Excel; kembalikan 'Y-m-d' atau null bila invalid.
     */
    private function parseTanggal(string $raw): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            [$y, $m, $d] = array_map('intval', explode('-', $raw));

            return checkdate($m, $d, $y) ? $raw : null;
        }

        if (is_numeric($raw)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
