<?php
declare(strict_types=1);
namespace App\Modules\Supir;

use App\Modules\Armada\Contracts\ArmadaRepositoryInterface;
use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;
use App\Modules\Supir\Contracts\SupirRepositoryInterface;
use App\Modules\Supir\Imports\SupirImport;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class SupirService
{
    private const STATUS_VALID = ['aktif', 'nonaktif'];
    private const STATUS_DEFAULT = 'aktif';

    public function __construct(
        private readonly SupirRepositoryInterface $repo,
        private readonly ArmadaRepositoryInterface $armadaRepo,
        private readonly KaryawanRepositoryInterface $karyawanRepo,
    ) {}

    /**
     * Bila id_karyawan dikirim & tidak null: karyawan harus ada, satu
     * perusahaan, dan belum ditautkan ke supir lain (1 karyawan = 1 supir).
     */
    private function assertKaryawanRules(array $data, string $idPerusahaan, ?string $excludeIdSupir = null): void
    {
        if (!array_key_exists('id_karyawan', $data) || $data['id_karyawan'] === null) {
            return;
        }

        $karyawan = $this->karyawanRepo->findById($data['id_karyawan']);
        if ($karyawan === null || $karyawan->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Karyawan tidak ditemukan');
        }

        $tertaut = $this->repo->findByKaryawan($data['id_karyawan'], $excludeIdSupir);
        if ($tertaut !== null) {
            abort(422, "Karyawan sudah ditautkan ke supir {$tertaut->nama}");
        }
    }

    /**
     * Bila id_pengguna dikirim & tidak null: akun harus ada di perusahaan yang
     * sama dan belum ditautkan ke supir lain (1 akun = 1 supir). Tautan ini
     * yang dipakai login aplikasi mobile supir (endpoint /supir/me).
     */
    private function assertPenggunaRules(array $data, string $idPerusahaan, ?string $excludeIdSupir = null): void
    {
        if (!array_key_exists('id_pengguna', $data) || $data['id_pengguna'] === null) {
            return;
        }

        $pengguna = $this->repo->findPenggunaMilikPerusahaan((string) $data['id_pengguna'], $idPerusahaan);
        if ($pengguna === null) {
            abort(404, 'Akun pengguna tidak ditemukan');
        }

        $tertaut = $this->repo->findByPengguna((string) $data['id_pengguna'], $excludeIdSupir);
        if ($tertaut !== null) {
            abort(422, "Akun sudah ditautkan ke supir {$tertaut->nama}");
        }
    }

    public function listOpsiPengguna(string $idPerusahaan): array
    {
        return $this->repo->listOpsiPengguna($idPerusahaan);
    }

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $status = null, ?string $search = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $status, $search);
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
        if ($record === null) { abort(404, 'Supir tidak ditemukan'); }
        return $record;
    }

    public function findByPenggunaOrFail(string $idPengguna): object
    {
        $record = $this->repo->findByPengguna($idPengguna);
        if ($record === null) { abort(404, 'Supir tidak ditemukan'); }

        if ($record->id_armada_default !== null) {
            $armada = $this->armadaRepo->findById($record->id_armada_default);
            $record->armada_default = $armada !== null ? [
                'id_armada' => $armada->id_armada,
                'nopol'     => $armada->nopol,
                'merk'      => $armada->merk,
                'model'     => $armada->model,
            ] : null;
        } else {
            $record->armada_default = null;
        }

        return $record;
    }

    public function create(array $data): object
    {
        $this->assertArmadaDefaultRules($data, (string) $data['id_perusahaan']);
        $this->assertKaryawanRules($data, (string) $data['id_perusahaan']);
        $this->assertPenggunaRules($data, (string) $data['id_perusahaan']);
        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id);
        $this->assertArmadaDefaultRules($data, $idPerusahaan, $record->id_supir);
        $this->assertKaryawanRules($data, $idPerusahaan, $record->id_supir);
        $this->assertPenggunaRules($data, $idPerusahaan, $record->id_supir);
        return $this->repo->update($record, $data);
    }

    /**
     * Bila id_armada_default dikirim & tidak null, pastikan armada ada
     * dan milik perusahaan yang sama dengan user (pola guard existing:
     * ArmadaRepository::findById tidak scoped perusahaan, jadi
     * dibandingkan manual di sini). Lalu pastikan armada belum jadi
     * armada default supir lain — aturan "1 armada = 1 supir pegangan".
     * $excludeIdSupir dipakai saat update supaya supir boleh mempertahankan
     * armada default miliknya sendiri.
     */
    private function assertArmadaDefaultRules(array $data, string $idPerusahaan, ?string $excludeIdSupir = null): void
    {
        if (!array_key_exists('id_armada_default', $data) || $data['id_armada_default'] === null) {
            return;
        }

        $idArmada = (string) $data['id_armada_default'];

        $armada = $this->armadaRepo->findById($idArmada);
        if ($armada === null || (string) $armada->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Armada tidak ditemukan');
        }

        $pemegang = $this->repo->findPemegangArmadaDefault($idArmada, $excludeIdSupir);
        if ($pemegang !== null) {
            abort(422, "Armada sudah menjadi armada default supir {$pemegang->nama}");
        }
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }

    /**
     * Import supir dari file Excel (template: nama, no_sim, jenis_sim,
     * tgl_kadaluarsa_sim, telepon, status, nopol_armada_default).
     * Mode "sebagian masuk + laporan gagal" — baris valid tetap di-insert walau
     * ada baris lain yang gagal; baris kosong total dilewati tanpa dihitung.
     * nopol_armada_default diisi → dicari armada milik perusahaan yang sama
     * (case-insensitive) untuk mengisi id_armada_default.
     *
     * @return array{berhasil: int, gagal: array<int, array{baris: int, nama: string, alasan: string}>}
     */
    public function import(UploadedFile $file, string $idPerusahaan): array
    {
        $rows = Excel::toArray(new SupirImport(), $file)[0] ?? [];

        $frekuensiNoSim = [];
        $frekuensiNopol = [];
        foreach ($rows as $row) {
            $noSim = $this->cellToString($row['no_sim'] ?? null);
            if ($noSim !== null) {
                $frekuensiNoSim[$noSim] = ($frekuensiNoSim[$noSim] ?? 0) + 1;
            }
            $nopol = $this->cellToString($row['nopol_armada_default'] ?? null);
            if ($nopol !== null) {
                $frekuensiNopol[$nopol] = ($frekuensiNopol[$nopol] ?? 0) + 1;
            }
        }

        $berhasil = 0;
        $gagal = [];

        foreach ($rows as $index => $row) {
            $baris = $index + 2;

            $nama = $this->cellToString($row['nama'] ?? null);
            $noSim = $this->cellToString($row['no_sim'] ?? null);
            $jenisSim = $this->cellToString($row['jenis_sim'] ?? null);
            $tglRaw = $this->cellToString($row['tgl_kadaluarsa_sim'] ?? null);
            $telepon = $this->cellToString($row['telepon'] ?? null);
            $statusRaw = $this->cellToString($row['status'] ?? null);
            $nopolDefault = $this->cellToString($row['nopol_armada_default'] ?? null);

            if ($nama === null && $noSim === null && $jenisSim === null && $tglRaw === null
                && $telepon === null && $statusRaw === null && $nopolDefault === null) {
                continue;
            }

            if ($nama === null) {
                $gagal[] = ['baris' => $baris, 'nama' => '', 'alasan' => 'Nama wajib diisi'];
                continue;
            }

            if ($noSim === null) {
                $gagal[] = ['baris' => $baris, 'nama' => $nama, 'alasan' => 'No SIM wajib diisi'];
                continue;
            }

            if ($this->repo->findByNoSim($idPerusahaan, $noSim) !== null) {
                $gagal[] = ['baris' => $baris, 'nama' => $nama, 'alasan' => 'No SIM sudah terdaftar'];
                continue;
            }

            if (($frekuensiNoSim[$noSim] ?? 0) > 1) {
                $gagal[] = ['baris' => $baris, 'nama' => $nama, 'alasan' => 'No SIM duplikat di dalam file'];
                continue;
            }

            $tglKadaluarsa = null;
            if ($tglRaw !== null) {
                $tglKadaluarsa = $this->parseTanggal($tglRaw);
                if ($tglKadaluarsa === null) {
                    $gagal[] = ['baris' => $baris, 'nama' => $nama, 'alasan' => 'Tanggal kadaluarsa SIM tidak valid'];
                    continue;
                }
            }

            $status = self::STATUS_DEFAULT;
            if ($statusRaw !== null) {
                if (!in_array($statusRaw, self::STATUS_VALID, true)) {
                    $gagal[] = ['baris' => $baris, 'nama' => $nama, 'alasan' => 'Status tidak valid'];
                    continue;
                }
                $status = $statusRaw;
            }

            $idArmadaDefault = null;
            if ($nopolDefault !== null) {
                $armada = $this->armadaRepo->findByNopolMilikPerusahaan($nopolDefault, $idPerusahaan);
                if ($armada === null) {
                    $gagal[] = ['baris' => $baris, 'nama' => $nama, 'alasan' => 'Nopol armada tidak ditemukan'];
                    continue;
                }

                $pemegang = $this->repo->findPemegangArmadaDefault((string) $armada->id_armada);
                if ($pemegang !== null) {
                    $gagal[] = ['baris' => $baris, 'nama' => $nama, 'alasan' => "Armada sudah menjadi armada default supir {$pemegang->nama}"];
                    continue;
                }

                if (($frekuensiNopol[$nopolDefault] ?? 0) > 1) {
                    $gagal[] = ['baris' => $baris, 'nama' => $nama, 'alasan' => 'Nopol armada duplikat di dalam file'];
                    continue;
                }

                $idArmadaDefault = $armada->id_armada;
            }

            $data = [
                'id_perusahaan'      => $idPerusahaan,
                'nama'               => $nama,
                'no_sim'             => $noSim,
                'tgl_kadaluarsa_sim' => $tglKadaluarsa,
                'telepon'            => $telepon,
                'status'             => $status,
                'id_armada_default'  => $idArmadaDefault,
            ];
            if ($jenisSim !== null) {
                $data['jenis_sim'] = $jenisSim;
            }

            $this->repo->create($data);
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
     * Terima format Y-m-d, atau serial tanggal Excel (numeric) bila cell
     * diformat sebagai tanggal oleh Excel. Return string Y-m-d atau null
     * kalau tidak valid.
     */
    private function parseTanggal(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = \DateTime::createFromFormat('Y-m-d', $value);
            if ($date !== false && $date->format('Y-m-d') === $value) {
                return $value;
            }
            return null;
        }

        if (is_numeric($value)) {
            try {
                $date = ExcelDate::excelToDateTimeObject((float) $value);
                return $date->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
