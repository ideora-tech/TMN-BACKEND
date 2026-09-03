<?php

declare(strict_types=1);

namespace App\Modules\Vendor;

use App\Modules\Vendor\Contracts\VendorRepositoryInterface;
use App\Modules\Vendor\Imports\VendorImport;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;

class VendorService
{
    public function __construct(private readonly VendorRepositoryInterface $repo) {}

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

    public function findOrFail(string $id, string $idPerusahaan): VendorModel
    {
        $record = $this->repo->findByIdMilikPerusahaan($id, $idPerusahaan);
        if ($record === null) {
            abort(404, 'Vendor tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): VendorModel
    {
        $idPerusahaan = $data['id_perusahaan'];

        if ($this->repo->findByKode($idPerusahaan, $data['kode_vendor'])) {
            abort(409, 'Kode vendor sudah digunakan');
        }

        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): VendorModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (isset($data['kode_vendor']) && $data['kode_vendor'] !== $record->kode_vendor) {
            if ($this->repo->findByKode($idPerusahaan, $data['kode_vendor'])) {
                abort(409, 'Kode vendor sudah digunakan');
            }
        }

        return $this->repo->update($record, $data);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($this->repo->dipakaiRelasiAktif($id)) {
            abort(422, 'Vendor masih punya kontrak/armada/supir/invoice aktif — hapus atau selesaikan dulu data terkaitnya');
        }

        $this->repo->delete($record);
    }

    /**
     * Import vendor dari file Excel — mode "sebagian masuk + laporan gagal":
     * baris valid tetap di-insert walau ada baris lain yang gagal; baris
     * kosong total dilewati tanpa dihitung.
     *
     * @return array{berhasil: int, gagal: array<int, array{baris: int, kunci: string, alasan: string}>}
     */
    public function import(UploadedFile $file, string $idPerusahaan): array
    {
        $rows = Excel::toArray(new VendorImport(), $file)[0] ?? [];

        $frekuensiKode = [];
        foreach ($rows as $row) {
            $kode = $this->cellToString($row['kode_vendor'] ?? null);
            if ($kode !== null) {
                $frekuensiKode[$kode] = ($frekuensiKode[$kode] ?? 0) + 1;
            }
        }

        $berhasil = 0;
        $gagal = [];

        foreach ($rows as $index => $row) {
            $baris = $index + 2;

            $kode        = $this->cellToString($row['kode_vendor'] ?? null);
            $nama        = $this->cellToString($row['nama_vendor'] ?? null);
            $jenis       = $this->cellToString($row['jenis_vendor'] ?? null);
            $pic         = $this->cellToString($row['pic_nama'] ?? null);
            $email       = $this->cellToString($row['email'] ?? null);
            $telepon     = $this->cellToString($row['telepon'] ?? null);
            $alamat      = $this->cellToString($row['alamat'] ?? null);
            $npwp        = $this->cellToString($row['npwp'] ?? null);
            $tglGabungRaw = $this->cellToString($row['tanggal_bergabung'] ?? null);

            $semuaSel = [$kode, $nama, $jenis, $pic, $email, $telepon, $alamat, $npwp, $tglGabungRaw];
            if (array_filter($semuaSel, static fn ($v) => $v !== null) === []) {
                continue;
            }

            if ($kode === null) {
                $gagal[] = ['baris' => $baris, 'kunci' => '', 'alasan' => 'Kode vendor wajib diisi'];
                continue;
            }

            if ($nama === null) {
                $gagal[] = ['baris' => $baris, 'kunci' => $kode, 'alasan' => 'Nama vendor wajib diisi'];
                continue;
            }

            if ($this->repo->findByKode($idPerusahaan, $kode) !== null) {
                $gagal[] = ['baris' => $baris, 'kunci' => $kode, 'alasan' => 'Kode vendor sudah terdaftar'];
                continue;
            }

            if (($frekuensiKode[$kode] ?? 0) > 1) {
                $gagal[] = ['baris' => $baris, 'kunci' => $kode, 'alasan' => 'Kode vendor duplikat di dalam file'];
                continue;
            }

            if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $gagal[] = ['baris' => $baris, 'kunci' => $kode, 'alasan' => 'Email tidak valid'];
                continue;
            }

            $tanggalBergabung = null;
            if ($tglGabungRaw !== null) {
                $tanggalBergabung = $this->parseTanggal($tglGabungRaw);
                if ($tanggalBergabung === null) {
                    $gagal[] = ['baris' => $baris, 'kunci' => $kode, 'alasan' => 'Tanggal bergabung tidak valid (format YYYY-MM-DD)'];
                    continue;
                }
            }

            $this->repo->create([
                'id_perusahaan'     => $idPerusahaan,
                'kode_vendor'       => $kode,
                'nama_vendor'       => $nama,
                'jenis_vendor'      => $jenis,
                'pic_nama'          => $pic,
                'email'             => $email,
                'telepon'           => $telepon,
                'alamat'            => $alamat,
                'npwp'              => $npwp,
                'tanggal_bergabung' => $tanggalBergabung,
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
