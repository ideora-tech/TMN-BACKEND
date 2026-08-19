<?php

declare(strict_types=1);

namespace App\Modules\SupirVendor;

use App\Modules\SupirVendor\Contracts\SupirVendorRepositoryInterface;
use App\Modules\SupirVendor\Imports\SupirVendorImport;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;

class SupirVendorService
{
    public function __construct(private readonly SupirVendorRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $idVendor = null, ?string $search = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $idVendor, $search);

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

    public function findOrFail(string $id, string $idPerusahaan): SupirVendorModel
    {
        $record = $this->repo->findByIdMilikPerusahaan($id, $idPerusahaan);
        if ($record === null) {
            abort(404, 'Supir vendor tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data, string $idPerusahaan): SupirVendorModel
    {
        if (!$this->repo->vendorMilikPerusahaan($data['id_vendor'], $idPerusahaan)) {
            abort(404, 'Vendor tidak ditemukan');
        }
        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): SupirVendorModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (isset($data['id_vendor']) && $data['id_vendor'] !== $record->id_vendor) {
            if (!$this->repo->vendorMilikPerusahaan($data['id_vendor'], $idPerusahaan)) {
                abort(404, 'Vendor tidak ditemukan');
            }
        }

        return $this->repo->update($record, $data);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->repo->delete($record);
    }

    /**
     * Import supir vendor dari file Excel — vendor dirujuk lewat kode_vendor
     * (bukan UUID) supaya template mudah diisi manusia. Mode "sebagian masuk
     * + laporan gagal"; baris kosong total dilewati tanpa dihitung.
     *
     * @return array{berhasil: int, gagal: array<int, array{baris: int, kunci: string, alasan: string}>}
     */
    public function import(UploadedFile $file, string $idPerusahaan): array
    {
        $rows = Excel::toArray(new SupirVendorImport(), $file)[0] ?? [];

        $frekuensiSim = [];
        foreach ($rows as $row) {
            $noSim = $this->cellToString($row['no_sim'] ?? null);
            if ($noSim !== null) {
                $kunci = mb_strtoupper($noSim);
                $frekuensiSim[$kunci] = ($frekuensiSim[$kunci] ?? 0) + 1;
            }
        }

        $berhasil = 0;
        $gagal = [];

        foreach ($rows as $index => $row) {
            $baris = $index + 2;

            $kodeVendor = $this->cellToString($row['kode_vendor'] ?? null);
            $nama       = $this->cellToString($row['nama'] ?? null);
            $telepon    = $this->cellToString($row['telepon'] ?? null);
            $noSim      = $this->cellToString($row['no_sim'] ?? null);
            $simRaw     = $this->cellToString($row['masa_berlaku_sim'] ?? null);

            $semuaSel = [$kodeVendor, $nama, $telepon, $noSim, $simRaw];
            if (array_filter($semuaSel, static fn ($v) => $v !== null) === []) {
                continue;
            }

            if ($nama === null) {
                $gagal[] = ['baris' => $baris, 'kunci' => '', 'alasan' => 'Nama wajib diisi'];
                continue;
            }

            if ($kodeVendor === null) {
                $gagal[] = ['baris' => $baris, 'kunci' => $nama, 'alasan' => 'Kode vendor wajib diisi'];
                continue;
            }

            $idVendor = $this->repo->findIdVendorByKode($kodeVendor, $idPerusahaan);
            if ($idVendor === null) {
                $gagal[] = ['baris' => $baris, 'kunci' => $nama, 'alasan' => "Vendor dengan kode '{$kodeVendor}' tidak ditemukan"];
                continue;
            }

            if ($noSim !== null) {
                if ($this->repo->noSimTerdaftar($noSim, $idPerusahaan)) {
                    $gagal[] = ['baris' => $baris, 'kunci' => $nama, 'alasan' => 'No SIM sudah terdaftar'];
                    continue;
                }
                if (($frekuensiSim[mb_strtoupper($noSim)] ?? 0) > 1) {
                    $gagal[] = ['baris' => $baris, 'kunci' => $nama, 'alasan' => 'No SIM duplikat di dalam file'];
                    continue;
                }
            }

            $masaSim = null;
            if ($simRaw !== null) {
                $masaSim = $this->parseTanggal($simRaw);
                if ($masaSim === null) {
                    $gagal[] = ['baris' => $baris, 'kunci' => $nama, 'alasan' => 'Masa berlaku SIM tidak valid (format YYYY-MM-DD)'];
                    continue;
                }
            }

            $this->repo->create([
                'id_vendor'        => $idVendor,
                'nama'             => $nama,
                'telepon'          => $telepon,
                'no_sim'           => $noSim,
                'masa_berlaku_sim' => $masaSim,
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
