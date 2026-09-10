<?php

declare(strict_types=1);

namespace App\Modules\Supir;

use App\Modules\DokumenArmada\DokumenArmadaService;
use App\Modules\DokumenArmada\Resources\DokumenArmadaResource;
use App\Modules\DokumenKaryawan\Contracts\DokumenKaryawanRepositoryInterface;
use App\Modules\DokumenKaryawan\Resources\DokumenKaryawanResource;
use App\Modules\Supir\Contracts\SupirRepositoryInterface;
use App\Modules\SupirVendor\Contracts\SupirVendorRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DokumenSupirSayaService
{
    private const JENIS_BOLEH_GANDA = ['Sertifikat', 'Lainnya'];

    public function __construct(
        private readonly SupirRepositoryInterface $supirRepo,
        private readonly SupirVendorRepositoryInterface $supirVendorRepo,
        private readonly DokumenKaryawanRepositoryInterface $dokumenKaryawanRepo,
        private readonly DokumenArmadaService $dokumenArmadaService,
    ) {}

    public function dokumenSaya(string $idPengguna): array
    {
        $supir = $this->supirRepo->findByPengguna($idPengguna);
        if ($supir !== null) {
            return [
                'tipe_supir'         => 'internal',
                'terhubung_karyawan' => $supir->id_karyawan !== null,
                'sim'                => [
                    'no_sim'         => $supir->no_sim,
                    'jenis_sim'      => $supir->jenis_sim,
                    'berlaku_sampai' => $supir->tgl_kadaluarsa_sim,
                ],
                'dokumen'            => $supir->id_karyawan !== null ? $this->dokumenKaryawan((string) $supir->id_karyawan) : [],
            ];
        }

        $supirVendor = $this->supirVendorRepo->findByPengguna($idPengguna);
        if ($supirVendor !== null) {
            return [
                'tipe_supir'         => 'vendor',
                'terhubung_karyawan' => false,
                'sim'                => [
                    'no_sim'         => $supirVendor->no_sim,
                    'jenis_sim'      => null,
                    'berlaku_sampai' => $supirVendor->masa_berlaku_sim?->toDateString(),
                ],
                'dokumen'            => [],
            ];
        }

        abort(404, 'Data supir tidak ditemukan untuk pengguna ini');
    }

    public function unitSaya(string $idPengguna): array
    {
        $hariIni = Carbon::today()->toDateString();

        $supir = $this->supirRepo->findByPengguna($idPengguna);
        if ($supir !== null) {
            return $this->unitSupirInternal($supir, $hariIni);
        }

        $supirVendor = $this->supirVendorRepo->findByPengguna($idPengguna);
        if ($supirVendor !== null) {
            return $this->unitSupirVendor((string) $supirVendor->id_supir_vendor, $hariIni);
        }

        abort(404, 'Data supir tidak ditemukan untuk pengguna ini');
    }

    private function dokumenKaryawan(string $idKaryawan): array
    {
        $perJenis = [];
        foreach ($this->dokumenKaryawanRepo->findAllByKaryawan($idKaryawan) as $dokumen) {
            $perJenis[$dokumen->jenis_dokumen][] = $dokumen;
        }
        ksort($perJenis);

        $hasil = [];
        foreach ($perJenis as $jenis => $daftar) {
            usort($daftar, fn ($a, $b) => $this->kunciUrut($b) <=> $this->kunciUrut($a));

            if (in_array($jenis, self::JENIS_BOLEH_GANDA, true)) {
                foreach ($daftar as $dokumen) {
                    $hasil[] = ['jenis_dokumen' => $jenis, 'terbaru' => (new DokumenKaryawanResource($dokumen))->resolve(), 'riwayat' => []];
                }
                continue;
            }

            $terbaru = array_shift($daftar);
            $hasil[] = [
                'jenis_dokumen' => $jenis,
                'terbaru'       => (new DokumenKaryawanResource($terbaru))->resolve(),
                'riwayat'       => DokumenKaryawanResource::collection($daftar)->resolve(),
            ];
        }

        return $hasil;
    }

    private function kunciUrut(object $dokumen): string
    {
        return ($dokumen->berlaku_sampai ?? '0000-00-00') . '|' . $dokumen->dibuat_pada;
    }

    private function unitSupirInternal(object $supir, string $hariIni): array
    {
        $sumberPerUnit = [];
        if ($supir->id_armada_default !== null) {
            $sumberPerUnit[(string) $supir->id_armada_default][] = 'tetap';
        }

        $dariPenugasan = DB::table('penugasan')
            ->whereNull('dihapus_pada')
            ->where('id_supir', $supir->id_supir)
            ->whereDate('tanggal_tugas', $hariIni)
            ->where('status', '!=', 'batal')
            ->whereNotNull('id_armada')
            ->pluck('id_armada');

        $dariPengganti = DB::table('jadwal_shift')
            ->whereNull('dihapus_pada')
            ->where('id_supir', $supir->id_supir)
            ->whereDate('tanggal', $hariIni)
            ->whereNotNull('id_armada_override')
            ->pluck('id_armada_override');

        foreach ($dariPenugasan->merge($dariPengganti)->unique() as $idArmada) {
            $sumberPerUnit[(string) $idArmada][] = 'hari_ini';
        }

        $hasil = [];
        foreach ($sumberPerUnit as $idArmada => $sumber) {
            $armada = DB::table('armada')
                ->whereNull('dihapus_pada')
                ->where('id_armada', $idArmada)
                ->where('id_perusahaan', $supir->id_perusahaan)
                ->first(['id_armada', 'nopol', 'merk', 'model']);
            if ($armada === null) {
                continue;
            }

            $hasil[] = [
                'tipe'      => 'internal',
                'id_armada' => $armada->id_armada,
                'nopol'     => $armada->nopol,
                'merk'      => trim(($armada->merk ?? '') . ' ' . ($armada->model ?? '')) ?: null,
                'sumber'    => array_values(array_unique($sumber)),
                'dokumen'   => DokumenArmadaResource::collection(
                    $this->dokumenArmadaService->dokumenAktifDenganRiwayat($idArmada)
                )->resolve(),
            ];
        }

        return $hasil;
    }

    private function unitSupirVendor(string $idSupirVendor, string $hariIni): array
    {
        $sumberPerUnit = [];

        $unitTetap = DB::table('armada_vendor')
            ->whereNull('dihapus_pada')
            ->where('id_supir_vendor_default', $idSupirVendor)
            ->pluck('id_armada_vendor');
        foreach ($unitTetap as $id) {
            $sumberPerUnit[(string) $id][] = 'tetap';
        }

        $dariPenugasan = DB::table('penugasan')
            ->whereNull('dihapus_pada')
            ->where('id_supir_vendor', $idSupirVendor)
            ->whereDate('tanggal_tugas', $hariIni)
            ->where('status', '!=', 'batal')
            ->whereNotNull('id_armada_vendor')
            ->pluck('id_armada_vendor');
        foreach ($dariPenugasan->unique() as $id) {
            $sumberPerUnit[(string) $id][] = 'hari_ini';
        }

        $hasil = [];
        foreach ($sumberPerUnit as $idArmadaVendor => $sumber) {
            $unit = DB::table('armada_vendor')
                ->whereNull('dihapus_pada')
                ->where('id_armada_vendor', $idArmadaVendor)
                ->first(['id_armada_vendor', 'nopol', 'merk', 'masa_berlaku_stnk', 'masa_berlaku_kir']);
            if ($unit === null) {
                continue;
            }

            $dokumen = [];
            foreach (['STNK' => $unit->masa_berlaku_stnk, 'KIR' => $unit->masa_berlaku_kir] as $jenis => $berlaku) {
                if ($berlaku === null) {
                    continue;
                }
                $dokumen[] = [
                    'id_dokumen_armada' => null,
                    'jenis_dokumen'     => $jenis,
                    'nomor'             => null,
                    'berlaku_sampai'    => $berlaku,
                    'url_file'          => null,
                    'aktif'             => true,
                    'riwayat'           => [],
                ];
            }

            $hasil[] = [
                'tipe'      => 'vendor',
                'id_armada' => $unit->id_armada_vendor,
                'nopol'     => $unit->nopol,
                'merk'      => $unit->merk,
                'sumber'    => array_values(array_unique($sumber)),
                'dokumen'   => $dokumen,
            ];
        }

        return $hasil;
    }
}
