<?php
namespace App\Modules\Rute;
use App\Modules\Rute\Contracts\RuteRepositoryInterface;
use App\Modules\Lokasi\Contracts\LokasiRepositoryInterface;
use App\Modules\JenisBbm\Contracts\JenisBbmRepositoryInterface;
use App\Modules\ParameterBok\Contracts\ParameterBokRepositoryInterface;
use App\Support\KodeOtomatis;
use Illuminate\Support\Str;

class RuteService {
    public function __construct(
        private readonly RuteRepositoryInterface $repo,
        private readonly LokasiRepositoryInterface $lokasiRepo,
        private readonly ParameterBokRepositoryInterface $parameterBokRepo,
        private readonly JenisBbmRepositoryInterface $jenisBbmRepo,
    ) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null): array {
        $paginator = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search);
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

    public function findOrFail(string $id): object {
        $rute = $this->repo->findById($id);
        if (!$rute) abort(404, 'Rute tidak ditemukan');
        return $rute;
    }

    public function create(array $data): object {
        $data['kode_rute'] = KodeOtomatis::berikutnya($data['id_perusahaan'], 'rute');
        if ($this->repo->findByKode($data['id_perusahaan'], $data['kode_rute'])) {
            abort(409, 'Kode rute sudah digunakan');
        }
        $data = $this->resolveLokasi($data, $data['id_perusahaan']);
        $data['id_rute'] = Str::uuid()->toString();
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): object {
        $rute = $this->findOrFail($id);
        if (isset($data['kode_rute']) && $data['kode_rute'] !== $rute->kode_rute) {
            if ($this->repo->findByKode($rute->id_perusahaan, $data['kode_rute'], $id)) {
                abort(409, 'Kode rute sudah digunakan');
            }
        }
        $data = $this->resolveLokasi($data, $rute->id_perusahaan);
        return $this->repo->update($rute, $data);
    }

    public function delete(string $id): void {
        $rute = $this->findOrFail($id);
        $this->repo->delete($rute);
    }

    public function estimasiBok(
        string $idPerusahaan,
        string $idRute,
        string $idJenisKendaraan,
        ?float $estimasiTol = null,
    ): ?array {
        $rute = $this->repo->findById($idRute);
        if ($rute === null || $rute->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Rute tidak ditemukan');
        }
        if ($this->parameterBokRepo->jenisKendaraanMilik($idJenisKendaraan, $idPerusahaan) === null) {
            abort(404, 'Jenis kendaraan tidak ditemukan');
        }

        $param = $this->parameterBokRepo->findByJenisKendaraan($idPerusahaan, $idJenisKendaraan);
        if ($param === null || $rute->estimasi_jarak_km === null) {
            return null;
        }

        $konsumsi  = (float) $param->konsumsi_km_per_liter;
        $utilisasi = (float) $param->utilisasi_km_per_bulan;
        if ($konsumsi <= 0 || $utilisasi <= 0) {
            return null;
        }

        $hargaBbm = $this->jenisBbmRepo->hargaEfektif($param->id_jenis_bbm);
        if ($hargaBbm === null) {
            return null;
        }

        $biayaTetapPerKm = (float) $param->biaya_tetap_bulanan / $utilisasi;
        $biayaBbmPerKm   = $hargaBbm / $konsumsi;
        $bokPerKm        = $biayaTetapPerKm + $biayaBbmPerKm
            + (float) $param->biaya_ban_per_km + (float) $param->biaya_servis_per_km;

        $jarak      = (float) $rute->estimasi_jarak_km;
        $hargaPokok = $bokPerKm * $jarak + ($estimasiTol ?? 0.0);
        $saranHarga = $hargaPokok * (1 + (float) $param->margin_persen / 100);

        return [
            'bok_per_km'            => round($bokPerKm, 2),
            'harga_pokok'           => round($hargaPokok, 2),
            'saran_harga'           => round($saranHarga, 2),
            'margin_persen_default' => (float) $param->margin_persen,
            'komponen'              => [
                'biaya_tetap_per_km'     => round($biayaTetapPerKm, 2),
                'biaya_bbm_per_km'       => round($biayaBbmPerKm, 2),
                'biaya_ban_per_km'       => (float) $param->biaya_ban_per_km,
                'biaya_servis_per_km'    => (float) $param->biaya_servis_per_km,
                'harga_bbm_per_liter'    => $hargaBbm,
                'konsumsi_km_per_liter'  => $konsumsi,
                'utilisasi_km_per_bulan' => $utilisasi,
                'jarak_km'               => $jarak,
                'estimasi_tol'           => $estimasiTol,
            ],
        ];
    }

    /**
     * Bila id_lokasi_asal/id_lokasi_tujuan dikirim, ambil lokasi milik
     * perusahaan terkait lalu isi teks asal/tujuan dari nama_lokasi.
     * 404 kalau lokasi tidak ditemukan atau beda perusahaan.
     */
    private function resolveLokasi(array $data, string $idPerusahaan): array {
        if (!empty($data['id_lokasi_asal'])) {
            $lokasi = $this->lokasiRepo->findById($data['id_lokasi_asal']);
            if ($lokasi === null || $lokasi->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Lokasi tidak ditemukan');
            }
            $data['asal'] = $lokasi->nama_lokasi;
        }
        if (!empty($data['id_lokasi_tujuan'])) {
            $lokasi = $this->lokasiRepo->findById($data['id_lokasi_tujuan']);
            if ($lokasi === null || $lokasi->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Lokasi tidak ditemukan');
            }
            $data['tujuan'] = $lokasi->nama_lokasi;
        }
        return $data;
    }
}