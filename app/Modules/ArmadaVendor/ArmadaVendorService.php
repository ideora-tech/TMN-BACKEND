<?php

declare(strict_types=1);

namespace App\Modules\ArmadaVendor;

use App\Modules\ArmadaVendor\Contracts\ArmadaVendorRepositoryInterface;
use App\Modules\ArmadaVendor\Imports\ArmadaVendorImport;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;

class ArmadaVendorService
{
    private const TAHUN_MIN = 1950;
    private const TAHUN_MAX = 2100;

    public function __construct(
        private readonly ArmadaVendorRepositoryInterface $repo,
        private readonly \App\Modules\KontrakVendor\Contracts\KontrakVendorRepositoryInterface $kontrakVendorRepo,
    ) {}

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

    public function findOrFail(string $id, string $idPerusahaan): ArmadaVendorModel
    {
        $record = $this->repo->findByIdMilikPerusahaan($id, $idPerusahaan);
        if ($record === null) {
            abort(404, 'Armada vendor tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data, string $idPerusahaan): ArmadaVendorModel
    {
        if (!$this->repo->vendorMilikPerusahaan($data['id_vendor'], $idPerusahaan)) {
            abort(404, 'Vendor tidak ditemukan');
        }
        $this->pastikanJenisKendaraanMilikPerusahaan($data, $idPerusahaan);
        $this->pastikanKontrakMilikVendor($data, $idPerusahaan, (string) $data['id_vendor']);
        $this->pastikanSupirDefaultSeVendor($data, (string) $data['id_vendor']);

        $record = $this->repo->create($data);

        if (!empty($data['id_kontrak_vendor'])) {
            $this->tarikApprovalKontrak((string) $data['id_kontrak_vendor'], $idPerusahaan);
        }

        return $record;
    }

    public function update(string $id, array $data, string $idPerusahaan): ArmadaVendorModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (array_key_exists('id_supir_vendor_default', $data)) {
            $this->pastikanSupirDefaultSeVendor($data, (string) ($data['id_vendor'] ?? $record->id_vendor));
        }

        if (isset($data['id_vendor']) && $data['id_vendor'] !== $record->id_vendor) {
            if (!$this->repo->vendorMilikPerusahaan($data['id_vendor'], $idPerusahaan)) {
                abort(404, 'Vendor tidak ditemukan');
            }
        }
        $this->pastikanJenisKendaraanMilikPerusahaan($data, $idPerusahaan);
        $this->pastikanKontrakMilikVendor($data, $idPerusahaan, (string) ($data['id_vendor'] ?? $record->id_vendor));

        $kontrakSebelum = $record->id_kontrak_vendor;
        $diperbarui = $this->repo->update($record, $data);

        if (array_key_exists('id_kontrak_vendor', $data) && ($data['id_kontrak_vendor'] ?? null) !== $kontrakSebelum) {
            foreach ([$kontrakSebelum, $data['id_kontrak_vendor'] ?? null] as $idKontrak) {
                if (!empty($idKontrak)) {
                    $this->tarikApprovalKontrak((string) $idKontrak, $idPerusahaan);
                }
            }
        }

        return $diperbarui;
    }

    private function pastikanKontrakMilikVendor(array $data, string $idPerusahaan, string $idVendor): void
    {
        if (empty($data['id_kontrak_vendor'])) {
            return;
        }
        $idVendorKontrak = $this->repo->findIdVendorByKontrak((string) $data['id_kontrak_vendor'], $idPerusahaan);
        if ($idVendorKontrak === null) {
            abort(404, 'Kontrak vendor tidak ditemukan');
        }
        if ($idVendorKontrak !== $idVendor) {
            abort(422, 'Kontrak bukan milik vendor ini');
        }
    }

    private function pastikanJenisKendaraanMilikPerusahaan(array $data, string $idPerusahaan): void
    {
        if (empty($data['id_jenis_kendaraan'])) {
            return;
        }
        if (!$this->repo->jenisKendaraanMilikPerusahaan((string) $data['id_jenis_kendaraan'], $idPerusahaan)) {
            abort(404, 'Jenis kendaraan tidak ditemukan');
        }
    }

    private function pastikanSupirDefaultSeVendor(array $data, string $idVendor): void
    {
        if (empty($data['id_supir_vendor_default'])) {
            return;
        }
        $milik = \App\Modules\SupirVendor\SupirVendorModel::active()
            ->where('id_supir_vendor', $data['id_supir_vendor_default'])
            ->where('id_vendor', $idVendor)
            ->exists();
        if (!$milik) {
            abort(422, 'Supir bawaan harus milik vendor yang sama dengan unitnya');
        }
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($this->kontrakVendorRepo->adaPenugasanNonFinalUntukArmadaVendor($id)) {
            abort(422, 'Unit masih dipakai penugasan aktif — selesaikan atau batalkan penugasannya terlebih dahulu');
        }

        $this->repo->delete($record);

        if (!empty($record->id_kontrak_vendor)) {
            $this->tarikApprovalKontrak((string) $record->id_kontrak_vendor, $idPerusahaan);
        }
    }

    private function tarikApprovalKontrak(string $idKontrak, string $idPerusahaan): void
    {
        $statusSebelum = $this->kontrakVendorRepo->turunkanKeDraftJikaPerluApprovalUlang($idKontrak);
        if ($statusSebelum === 'menunggu_approval') {
            app(\App\Modules\Approval\ApprovalService::class)
                ->batalkanUntukReferensi(['kontrak_vendor'], $idKontrak, $idPerusahaan);
        }
    }

    /**
     * Import armada vendor dari file Excel — vendor dirujuk lewat kode_vendor
     * (bukan UUID) dan jenis kendaraan lewat namanya, supaya template mudah
     * diisi manusia. Mode "sebagian masuk + laporan gagal"; baris kosong
     * total dilewati tanpa dihitung.
     *
     * @return array{berhasil: int, gagal: array<int, array{baris: int, kunci: string, alasan: string}>}
     */
    public function import(UploadedFile $file, string $idPerusahaan): array
    {
        $rows = Excel::toArray(new ArmadaVendorImport(), $file)[0] ?? [];

        $frekuensiNopol = [];
        foreach ($rows as $row) {
            $nopol = $this->cellToString($row['nopol'] ?? null);
            if ($nopol !== null) {
                $kunci = mb_strtoupper($nopol);
                $frekuensiNopol[$kunci] = ($frekuensiNopol[$kunci] ?? 0) + 1;
            }
        }

        $berhasil = 0;
        $gagal = [];

        foreach ($rows as $index => $row) {
            $baris = $index + 2;

            $kodeVendor   = $this->cellToString($row['kode_vendor'] ?? null);
            $nopol        = $this->cellToString($row['nopol'] ?? null);
            $merk         = $this->cellToString($row['merk'] ?? null);
            $jenis        = $this->cellToString($row['jenis'] ?? null);
            $namaJenisKendaraan = $this->cellToString($row['jenis_kendaraan'] ?? null);
            $kapasitas    = $this->cellToString($row['kapasitas'] ?? null);
            $tahunRaw     = $this->cellToString($row['tahun'] ?? null);
            $stnkRaw      = $this->cellToString($row['masa_berlaku_stnk'] ?? null);
            $kirRaw       = $this->cellToString($row['masa_berlaku_kir'] ?? null);

            $semuaSel = [$kodeVendor, $nopol, $merk, $jenis, $namaJenisKendaraan, $kapasitas, $tahunRaw, $stnkRaw, $kirRaw];
            if (array_filter($semuaSel, static fn ($v) => $v !== null) === []) {
                continue;
            }

            if ($nopol === null) {
                $gagal[] = ['baris' => $baris, 'kunci' => '', 'alasan' => 'Nopol wajib diisi'];
                continue;
            }

            if ($kodeVendor === null) {
                $gagal[] = ['baris' => $baris, 'kunci' => $nopol, 'alasan' => 'Kode vendor wajib diisi'];
                continue;
            }

            $idVendor = $this->repo->findIdVendorByKode($kodeVendor, $idPerusahaan);
            if ($idVendor === null) {
                $gagal[] = ['baris' => $baris, 'kunci' => $nopol, 'alasan' => "Vendor dengan kode '{$kodeVendor}' tidak ditemukan"];
                continue;
            }

            if ($this->repo->nopolTerdaftar($nopol, $idPerusahaan)) {
                $gagal[] = ['baris' => $baris, 'kunci' => $nopol, 'alasan' => 'Nopol sudah terdaftar'];
                continue;
            }

            if (($frekuensiNopol[mb_strtoupper($nopol)] ?? 0) > 1) {
                $gagal[] = ['baris' => $baris, 'kunci' => $nopol, 'alasan' => 'Nopol duplikat di dalam file'];
                continue;
            }

            $idJenisKendaraan = null;
            if ($namaJenisKendaraan !== null) {
                $idJenisKendaraan = $this->repo->findIdJenisKendaraanByNama($namaJenisKendaraan, $idPerusahaan);
                if ($idJenisKendaraan === null) {
                    $gagal[] = ['baris' => $baris, 'kunci' => $nopol, 'alasan' => "Jenis kendaraan '{$namaJenisKendaraan}' tidak ditemukan di master"];
                    continue;
                }
            }

            $tahun = null;
            if ($tahunRaw !== null) {
                if (!is_numeric($tahunRaw) || (int) $tahunRaw < self::TAHUN_MIN || (int) $tahunRaw > self::TAHUN_MAX) {
                    $gagal[] = ['baris' => $baris, 'kunci' => $nopol, 'alasan' => 'Tahun tidak valid'];
                    continue;
                }
                $tahun = (int) $tahunRaw;
            }

            $masaStnk = null;
            if ($stnkRaw !== null) {
                $masaStnk = $this->parseTanggal($stnkRaw);
                if ($masaStnk === null) {
                    $gagal[] = ['baris' => $baris, 'kunci' => $nopol, 'alasan' => 'Masa berlaku STNK tidak valid (format YYYY-MM-DD)'];
                    continue;
                }
            }

            $masaKir = null;
            if ($kirRaw !== null) {
                $masaKir = $this->parseTanggal($kirRaw);
                if ($masaKir === null) {
                    $gagal[] = ['baris' => $baris, 'kunci' => $nopol, 'alasan' => 'Masa berlaku KIR tidak valid (format YYYY-MM-DD)'];
                    continue;
                }
            }

            $this->repo->create([
                'id_vendor'          => $idVendor,
                'nopol'              => $nopol,
                'merk'               => $merk,
                'jenis'              => $jenis,
                'id_jenis_kendaraan' => $idJenisKendaraan,
                'kapasitas'          => $kapasitas,
                'tahun'              => $tahun,
                'masa_berlaku_stnk'  => $masaStnk,
                'masa_berlaku_kir'   => $masaKir,
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
