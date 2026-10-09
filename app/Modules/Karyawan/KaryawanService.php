<?php

declare(strict_types=1);

namespace App\Modules\Karyawan;

use App\Modules\Jabatan\Contracts\JabatanRepositoryInterface;
use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;
use App\Modules\Supir\Contracts\SupirRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class KaryawanService
{
    public const JENIS_JABATAN_AWAL      = 'awal';
    public const JENIS_PERUBAHAN_JABATAN = ['promosi', 'mutasi', 'demosi', 'penyesuaian'];

    public function __construct(
        private readonly KaryawanRepositoryInterface $repo,
        private readonly JabatanRepositoryInterface $jabatanRepo,
        private readonly SupirRepositoryInterface $supirRepo,
    ) {}

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

    public function findOrFail(string $id, ?string $idPerusahaan = null): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || ($idPerusahaan !== null && (string) $record->id_perusahaan !== $idPerusahaan)) {
            abort(404, 'Karyawan tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        $existing = $this->repo->findByNik($data['nik']);
        if ($existing !== null) {
            abort(422, 'NIK sudah terdaftar');
        }

        $record = $this->repo->create($data);
        $this->buatSupirJikaPerlu($record);

        return $record;
    }

    public function update(string $id, array $data, ?string $idPerusahaan = null): object
    {
        $this->findOrFail($id, $idPerusahaan);

        return DB::transaction(function () use ($id, $data, $idPerusahaan) {
            $this->repo->kunci($id);
            $record = $this->findOrFail($id, $idPerusahaan);

            $jabatanBerubah = array_key_exists('id_jabatan', $data)
                && ($data['id_jabatan'] ?? null) !== $record->id_jabatan;

            $updated = $this->repo->update($record, $data);

            if ($jabatanBerubah) {
                $this->catatPerubahanJabatan($record, $data['id_jabatan'] ?? null, [
                    'tanggal_efektif' => now()->toDateString(),
                    'jenis'           => $record->id_jabatan === null ? self::JENIS_JABATAN_AWAL : null,
                ]);
                $this->buatSupirJikaPerlu($updated);
            }

            return $updated;
        });
    }

    public function ubahJabatan(string $id, array $data, string $idPerusahaan): object
    {
        $this->findOrFail($id, $idPerusahaan);

        return DB::transaction(function () use ($id, $data, $idPerusahaan) {
            $this->repo->kunci($id);
            $record = $this->findOrFail($id, $idPerusahaan);

            $jabatan = $this->jabatanRepo->findAktifMilikPerusahaan((string) $data['id_jabatan'], $idPerusahaan);
            if ($jabatan === null) {
                abort(422, 'Jabatan tidak ditemukan atau tidak aktif');
            }
            $idJabatanBaru = (string) $jabatan->id_jabatan;
            if ($idJabatanBaru === (string) ($record->id_jabatan ?? '')) {
                abort(422, 'Karyawan sudah memegang jabatan ini');
            }

            $riwayat  = $this->repo->riwayatJabatan($id);
            $terakhir = $riwayat !== [] ? $riwayat[count($riwayat) - 1] : null;
            $tanggal  = (string) $data['tanggal_efektif'];
            $this->pastikanTanggalEfektif(
                $tanggal,
                $this->tanggalSaja($record->tanggal_masuk ?? null),
                $terakhir !== null ? $this->tanggalCatatan($terakhir) : null,
                null,
            );

            $updated = $this->repo->update($record, ['id_jabatan' => $idJabatanBaru]);

            $this->catatPerubahanJabatan($record, $idJabatanBaru, [
                'tanggal_efektif' => $tanggal,
                'jenis'           => $this->jenisPerubahan($record->id_jabatan === null, $data['jenis'] ?? null),
                'nomor_sk'        => $this->teksAtauNull($data['nomor_sk'] ?? null),
                'keterangan'      => $this->teksAtauNull($data['keterangan'] ?? null),
            ]);
            $this->buatSupirJikaPerlu($updated);

            return $updated;
        });
    }

    public function koreksiRiwayatJabatan(string $id, string $idRiwayat, array $data, string $idPerusahaan): array
    {
        $this->findOrFail($id, $idPerusahaan);

        DB::transaction(function () use ($id, $idRiwayat, $data, $idPerusahaan) {
            $this->repo->kunci($id);
            $record  = $this->findOrFail($id, $idPerusahaan);
            $riwayat = $this->repo->riwayatJabatan($id);

            $indeks = null;
            foreach ($riwayat as $i => $baris) {
                if ((string) $baris->id_riwayat === $idRiwayat) {
                    $indeks = $i;
                    break;
                }
            }
            if ($indeks === null) {
                abort(404, 'Catatan jabatan tidak ditemukan');
            }

            $tanggal = (string) $data['tanggal_efektif'];
            if ($tanggal !== $this->tanggalCatatan($riwayat[$indeks])) {
                $this->pastikanTanggalEfektif(
                    $tanggal,
                    $this->tanggalSaja($record->tanggal_masuk ?? null),
                    isset($riwayat[$indeks - 1]) ? $this->tanggalCatatan($riwayat[$indeks - 1]) : null,
                    isset($riwayat[$indeks + 1]) ? $this->tanggalCatatan($riwayat[$indeks + 1]) : null,
                );
            }

            $this->repo->updateRiwayatJabatan($idRiwayat, [
                'tanggal_efektif' => $tanggal,
                'jenis'           => $this->jenisPerubahan($riwayat[$indeks]->id_jabatan_lama === null, $data['jenis'] ?? null),
                'nomor_sk'        => $this->teksAtauNull($data['nomor_sk'] ?? null),
                'keterangan'      => $this->teksAtauNull($data['keterangan'] ?? null),
            ]);
        });

        return $this->riwayatJabatan($id, $idPerusahaan);
    }

    public function riwayatJabatan(string $id, ?string $idPerusahaan = null): array
    {
        $record       = $this->findOrFail($id, $idPerusahaan);
        $riwayat      = $this->repo->riwayatJabatan($id);
        $tanggalMasuk = $this->tanggalSaja($record->tanggal_masuk ?? null);
        $periode      = [];

        if ($riwayat === []) {
            if ($record->id_jabatan !== null) {
                $info = $this->repo->infoJabatan((string) $record->id_jabatan);
                $periode[] = $this->barisPeriode([
                    'id_jabatan'      => $record->id_jabatan,
                    'nama_jabatan'    => $info->nama_jabatan ?? null,
                    'nama_departemen' => $info->nama_departemen ?? null,
                    'tanggal_mulai'   => $tanggalMasuk,
                    'sedang_dijabat'  => true,
                    'jenis'           => self::JENIS_JABATAN_AWAL,
                ]);
            }

            return $periode;
        }

        $pertama        = $riwayat[0];
        $tanggalPertama = $this->tanggalCatatan($pertama);
        if ($pertama->id_jabatan_lama !== null) {
            $mulaiAwal = $tanggalMasuk !== null && $tanggalPertama !== null && $tanggalMasuk <= $tanggalPertama ? $tanggalMasuk : null;

            $periode[] = $this->barisPeriode([
                'id_jabatan'      => $pertama->id_jabatan_lama,
                'nama_jabatan'    => $pertama->nama_jabatan_lama,
                'nama_departemen' => $pertama->nama_departemen_lama,
                'tanggal_mulai'   => $mulaiAwal,
                'tanggal_selesai' => $this->sehariSebelum($tanggalPertama, $mulaiAwal),
                'jenis'           => self::JENIS_JABATAN_AWAL,
            ]);
        }

        $jumlah = count($riwayat);
        foreach ($riwayat as $i => $baris) {
            $mulai    = $this->tanggalCatatan($baris);
            $terakhir = $i === $jumlah - 1;

            $periode[] = $this->barisPeriode([
                'id_riwayat'      => $baris->id_riwayat,
                'id_jabatan'      => $baris->id_jabatan_baru,
                'nama_jabatan'    => $baris->nama_jabatan_baru,
                'nama_departemen' => $baris->nama_departemen_baru,
                'tanggal_mulai'   => $mulai,
                'tanggal_selesai' => $terakhir ? null : $this->sehariSebelum($this->tanggalCatatan($riwayat[$i + 1]), $mulai),
                'sedang_dijabat'  => $terakhir && $baris->id_jabatan_baru !== null
                    && (string) $baris->id_jabatan_baru === (string) ($record->id_jabatan ?? ''),
                'jenis'           => $baris->jenis,
                'nomor_sk'        => $baris->nomor_sk,
                'keterangan'      => $baris->keterangan,
                'dicatat_oleh'    => $baris->dicatat_oleh,
                'dicatat_pada'    => $baris->dibuat_pada,
            ]);
        }

        return array_reverse($periode);
    }

    private function barisPeriode(array $data): array
    {
        return array_merge([
            'id_riwayat'      => null,
            'id_jabatan'      => null,
            'nama_jabatan'    => null,
            'nama_departemen' => null,
            'tanggal_mulai'   => null,
            'tanggal_selesai' => null,
            'sedang_dijabat'  => false,
            'jenis'           => null,
            'nomor_sk'        => null,
            'keterangan'      => null,
            'dicatat_oleh'    => null,
            'dicatat_pada'    => null,
        ], $data);
    }

    private function catatPerubahanJabatan(object $karyawan, ?string $idJabatanBaru, array $rincian): void
    {
        $lama = $karyawan->id_jabatan !== null ? $this->repo->infoJabatan((string) $karyawan->id_jabatan) : null;
        $baru = $idJabatanBaru !== null ? $this->repo->infoJabatan($idJabatanBaru) : null;

        $this->repo->insertRiwayatJabatan(array_merge([
            'id_perusahaan'        => (string) $karyawan->id_perusahaan,
            'id_karyawan'          => $karyawan->id_karyawan,
            'id_jabatan_lama'      => $karyawan->id_jabatan,
            'id_jabatan_baru'      => $idJabatanBaru,
            'nama_jabatan_lama'    => $lama->nama_jabatan ?? null,
            'nama_jabatan_baru'    => $baru->nama_jabatan ?? null,
            'nama_departemen_lama' => $lama->nama_departemen ?? null,
            'nama_departemen_baru' => $baru->nama_departemen ?? null,
        ], $rincian));
    }

    private function jenisPerubahan(bool $penempatanAwal, mixed $jenis): string
    {
        if ($penempatanAwal) {
            return self::JENIS_JABATAN_AWAL;
        }
        if (!in_array($jenis, self::JENIS_PERUBAHAN_JABATAN, true)) {
            abort(422, 'Jenis perubahan jabatan wajib dipilih');
        }

        return (string) $jenis;
    }

    private function pastikanTanggalEfektif(string $tanggal, ?string $tanggalMasuk, ?string $sebelumnya, ?string $berikutnya): void
    {
        $palingAkhir = max(now()->toDateString(), $tanggalMasuk ?? '');
        if ($tanggal > $palingAkhir) {
            abort(422, 'Tanggal efektif tidak boleh melewati hari ini');
        }
        if ($tanggalMasuk !== null && $tanggal < $tanggalMasuk) {
            abort(422, 'Tanggal efektif tidak boleh sebelum tanggal masuk karyawan (' . $this->tanggalTampil($tanggalMasuk) . ')');
        }
        if ($sebelumnya !== null && $tanggal < $sebelumnya) {
            abort(422, 'Tanggal efektif tidak boleh sebelum perubahan jabatan sebelumnya (' . $this->tanggalTampil($sebelumnya) . ')');
        }
        if ($berikutnya !== null && $tanggal > $berikutnya) {
            abort(422, 'Tanggal efektif tidak boleh setelah perubahan jabatan berikutnya (' . $this->tanggalTampil($berikutnya) . ')');
        }
    }

    private function sehariSebelum(?string $tanggal, ?string $palingAwal): ?string
    {
        if ($tanggal === null) {
            return null;
        }
        $hasil = Carbon::parse($tanggal)->subDay()->toDateString();

        return $palingAwal !== null && $hasil < $palingAwal ? $palingAwal : $hasil;
    }

    private function tanggalCatatan(object $catatan): ?string
    {
        return $this->tanggalSaja($catatan->tanggal_efektif ?? $catatan->dibuat_pada ?? null);
    }

    private function tanggalSaja(mixed $nilai): ?string
    {
        return $nilai !== null && $nilai !== '' ? substr((string) $nilai, 0, 10) : null;
    }

    private function tanggalTampil(string $tanggal): string
    {
        return Carbon::parse($tanggal)->format('d/m/Y');
    }

    private function teksAtauNull(mixed $nilai): ?string
    {
        $teks = trim((string) ($nilai ?? ''));

        return $teks !== '' ? $teks : null;
    }

    public function delete(string $id, ?string $idPerusahaan = null): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($this->repo->dipakaiRelasiAktif($id)) {
            abort(422, 'Karyawan masih punya akun/riwayat kepegawaian (kontrak, absensi, payroll, cuti) — ubah statusnya saja, jangan dihapus');
        }

        $this->repo->delete($record);
    }

    public function exitHistory(string $id, ?string $idPerusahaan = null): array
    {
        $this->findOrFail($id, $idPerusahaan);
        return $this->repo->exitHistory($id);
    }

    private function buatSupirJikaPerlu(object $karyawan): void
    {
        if (($karyawan->id_jabatan ?? null) === null) {
            return;
        }

        $jabatan = $this->jabatanRepo->findById((string) $karyawan->id_jabatan);
        if ($jabatan === null || (int) $jabatan->is_supir !== 1) {
            return;
        }

        if ((string) $jabatan->id_perusahaan !== (string) $karyawan->id_perusahaan) {
            return;
        }

        if ($this->supirRepo->findByKaryawan($karyawan->id_karyawan) !== null) {
            return;
        }

        $this->supirRepo->create([
            'id_perusahaan' => (string) $karyawan->id_perusahaan,
            'id_karyawan'   => $karyawan->id_karyawan,
            'nama'          => $karyawan->nama_karyawan,
            'telepon'       => $karyawan->telepon ?? null,
            'no_sim'        => null,
            'status'        => 'aktif',
        ]);
    }
}
