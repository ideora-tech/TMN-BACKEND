<?php

declare(strict_types=1);

namespace App\Modules\Supir;

use App\Modules\Armada\Contracts\ArmadaRepositoryInterface;
use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;
use App\Modules\Supir\Contracts\SupirRepositoryInterface;
use App\Modules\Supir\Requests\StoreSupirRequest;
use App\Support\Impor\BukuTemplateImpor;
use App\Support\Impor\PembacaImpor;
use App\Support\JenisSim;
use Illuminate\Http\UploadedFile;

class SupirImportService
{
    private const STATUS_VALID = ['aktif', 'nonaktif'];

    private const CATATAN = [
        'Driver yang juga karyawan: isi nik_karyawan. Nama dan telepon diambil dari data karyawan bila dikosongkan.',
        'Karyawan berjabatan supir otomatis sudah punya profil supir tanpa SIM. Import ini melengkapi SIM-nya, tidak membuat supir baru.',
        'Profil supir yang sudah punya SIM tidak diubah oleh import. Ubah lewat halaman detail supir.',
        'Daftar karyawan berjabatan supir ada di sheet "Referensi".',
    ];

    public function __construct(
        private readonly SupirService $supirService,
        private readonly SupirRepositoryInterface $repo,
        private readonly ArmadaRepositoryInterface $armadaRepo,
        private readonly KaryawanRepositoryInterface $karyawanRepo,
    ) {}

    public function template(string $idPerusahaan): BukuTemplateImpor
    {
        $karyawan = array_map(
            static fn ($k) => [
                (string) $k->nik,
                (string) $k->nama_karyawan,
                $k->nama_jabatan,
                match (true) {
                    $k->id_supir_tertaut === null             => 'Belum ada',
                    trim((string) $k->no_sim_tertaut) === ''  => 'Ada, SIM belum diisi',
                    default                                   => 'Lengkap',
                },
            ],
            $this->repo->listOpsiKaryawan($idPerusahaan),
        );

        return new BukuTemplateImpor('Supir', KolomExcelSupir::DAFTAR, self::CATATAN, [
            ['judul' => ['NIK Karyawan', 'Nama Karyawan', 'Jabatan', 'Profil Supir'], 'baris' => $karyawan],
        ]);
    }

    public function import(UploadedFile $file, string $idPerusahaan, bool $bolehUbah): array
    {
        $baris = PembacaImpor::baca($file, KolomExcelSupir::judul(), [], 'supir');

        $frekuensiSim   = PembacaImpor::frekuensi($baris, static fn (array $n) => $n['no_sim'] !== null ? mb_strtolower($n['no_sim']) : null);
        $frekuensiNopol = PembacaImpor::frekuensi($baris, static fn (array $n) => PembacaImpor::kecil($n['nopol_armada_default']));
        $frekuensiNik   = PembacaImpor::frekuensi($baris, static fn (array $n) => $n['nik_karyawan'] !== null ? mb_strtolower($n['nik_karyawan']) : null);

        $karyawanSupir = [];
        foreach ($this->repo->listOpsiKaryawan($idPerusahaan) as $k) {
            $karyawanSupir[mb_strtolower((string) $k->nik)] = $k;
        }

        $aturan = (new StoreSupirRequest())->rules();

        return PembacaImpor::jalankan(
            $baris,
            static fn (array $n) => $n['nik_karyawan'] ?? $n['nama'] ?? $n['no_sim'] ?? '',
            fn (array $nilai, array $asli) => $this->prosesBaris(
                $nilai, $asli, $idPerusahaan, $bolehUbah, $karyawanSupir, $aturan, $frekuensiSim, $frekuensiNopol, $frekuensiNik,
            ),
        );
    }

    private function prosesBaris(
        array $nilai,
        array $asli,
        string $idPerusahaan,
        bool $bolehUbah,
        array $karyawanSupir,
        array $aturan,
        array $frekuensiSim,
        array $frekuensiNopol,
        array $frekuensiNik,
    ): ?string {
        if ($nilai['nik_karyawan'] === null && $nilai['nama'] === null) {
            return 'Nama wajib diisi';
        }

        $noSim = $nilai['no_sim'];
        if ($noSim !== null && $this->repo->findByNoSim($idPerusahaan, $noSim) !== null) {
            return 'No SIM sudah terdaftar';
        }
        if ($noSim !== null && ($frekuensiSim[mb_strtolower($noSim)] ?? 0) > 1) {
            return 'No SIM duplikat di dalam file';
        }

        $karyawan = null;
        $profilTanpaSim = null;
        if ($nilai['nik_karyawan'] !== null) {
            [$karyawan, $galat] = $this->cariKaryawan($nilai['nik_karyawan'], $idPerusahaan, $karyawanSupir, $frekuensiNik);
            if ($galat !== null) {
                return $galat;
            }
            if ($karyawan->id_supir_tertaut !== null) {
                if (trim((string) $karyawan->no_sim_tertaut) !== '') {
                    return "Karyawan {$karyawan->nama_karyawan} sudah punya profil supir dengan SIM {$karyawan->no_sim_tertaut} — ubah lewat halaman detail supir";
                }
                if ($noSim === null) {
                    return "Karyawan {$karyawan->nama_karyawan} sudah punya profil supir — isi no_sim untuk melengkapinya";
                }
                if (!$bolehUbah) {
                    return "Karyawan {$karyawan->nama_karyawan} sudah punya profil supir — melengkapinya butuh izin Ubah Supir";
                }
                $profilTanpaSim = $karyawan;
            }
        }

        $tanggal = null;
        if ($nilai['tgl_kadaluarsa_sim'] !== null) {
            $tanggal = PembacaImpor::tanggal($asli['tgl_kadaluarsa_sim'] ?? null);
            if ($tanggal === null) {
                return 'Tanggal habis masa berlaku SIM tidak valid';
            }
        }

        $jenisSim = null;
        if ($nilai['jenis_sim'] !== null) {
            $jenisSim = JenisSim::normalisasi($nilai['jenis_sim']);
            if ($jenisSim === null) {
                return 'Kolom jenis_sim harus ' . JenisSim::teksDaftar();
            }
        }

        $status = null;
        if ($nilai['status'] !== null) {
            $status = mb_strtolower($nilai['status']);
            if (!in_array($status, self::STATUS_VALID, true)) {
                return 'Status tidak valid';
            }
        }

        $idArmada = null;
        if ($nilai['nopol_armada_default'] !== null) {
            $armada = $this->armadaRepo->findByNopolMilikPerusahaan($nilai['nopol_armada_default'], $idPerusahaan);
            if ($armada === null) {
                return 'Nopol armada tidak ditemukan';
            }
            $pemegang = $this->repo->findPemegangArmadaDefault((string) $armada->id_armada, $profilTanpaSim?->id_supir_tertaut);
            if ($pemegang !== null) {
                return "Armada sudah menjadi armada default supir {$pemegang->nama}";
            }
            if (($frekuensiNopol[PembacaImpor::kecil($nilai['nopol_armada_default'])] ?? 0) > 1) {
                return 'Nopol armada duplikat di dalam file';
            }
            $idArmada = (string) $armada->id_armada;
        }

        $dariKaryawan = $profilTanpaSim === null ? $karyawan : null;
        $data = array_filter([
            'nama'               => $profilTanpaSim === null ? ($nilai['nama'] ?? $dariKaryawan?->nama_karyawan) : null,
            'no_sim'             => $noSim,
            'jenis_sim'          => $jenisSim,
            'tgl_kadaluarsa_sim' => $tanggal,
            'telepon'            => $nilai['telepon'] ?? $dariKaryawan?->telepon,
            'status'             => $status,
            'id_armada_default'  => $idArmada,
        ], static fn ($v) => $v !== null && $v !== '');

        $galat = PembacaImpor::validasi(
            $profilTanpaSim !== null ? array_merge(['nama' => $profilTanpaSim->nama_supir_tertaut], $data) : $data,
            $aturan,
        );
        if ($galat !== null) {
            return $galat;
        }

        if ($profilTanpaSim !== null) {
            $this->supirService->update((string) $profilTanpaSim->id_supir_tertaut, $data, $idPerusahaan);

            return null;
        }

        $baru = array_merge(['status' => 'aktif'], $data, ['id_perusahaan' => $idPerusahaan]);
        if ($karyawan !== null) {
            $baru['id_karyawan'] = (string) $karyawan->id_karyawan;
        }
        $this->supirService->create($baru);

        return null;
    }

    private function cariKaryawan(string $nik, string $idPerusahaan, array $karyawanSupir, array $frekuensiNik): array
    {
        $kunci = mb_strtolower($nik);

        if (($frekuensiNik[$kunci] ?? 0) > 1) {
            return [null, 'NIK karyawan duplikat di dalam file'];
        }
        if (isset($karyawanSupir[$kunci])) {
            return [$karyawanSupir[$kunci], null];
        }

        $karyawan = $this->karyawanRepo->findByNik($nik);
        if ($karyawan === null || (string) $karyawan->id_perusahaan !== $idPerusahaan) {
            return [null, "Karyawan dengan NIK {$nik} tidak ditemukan"];
        }
        if ((int) $karyawan->aktif !== 1) {
            return [null, "Karyawan {$karyawan->nama_karyawan} sudah tidak aktif"];
        }

        return [null, "Jabatan karyawan {$karyawan->nama_karyawan} bukan jabatan supir — jabatannya harus bernama Supir/Pengemudi atau diberi tanda \"Jabatan Supir\" di Data Master → Jabatan"];
    }
}
