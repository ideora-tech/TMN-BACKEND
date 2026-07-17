<?php

declare(strict_types=1);

namespace App\Modules\TarifRute;

use App\Modules\JenisBbm\Contracts\JenisBbmRepositoryInterface;
use App\Modules\ParameterBok\Contracts\ParameterBokRepositoryInterface;
use App\Modules\Rute\Contracts\RuteRepositoryInterface;
use App\Modules\TarifRute\Contracts\TarifRuteRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class TarifRuteService
{
    public function __construct(
        private readonly TarifRuteRepositoryInterface $repo,
        private readonly RuteRepositoryInterface $ruteRepo,
        private readonly ParameterBokRepositoryInterface $parameterBokRepo,
        private readonly JenisBbmRepositoryInterface $jenisBbmRepo,
    ) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, array $filter = []): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $filter);

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

    public function findOrFail(string $id, string $idPerusahaan): TarifRuteModel
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Tarif tidak ditemukan');
        }
        return $record;
    }

    public function findDetailOrFail(string $id, string $idPerusahaan): TarifRuteModel
    {
        $record = $this->repo->findDetailById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Tarif tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): TarifRuteModel
    {
        $idPerusahaan     = $data['id_perusahaan'];
        $data['id_klien'] = $data['id_klien'] ?? null;
        $this->validasiReferensi($data, $idPerusahaan);

        $overlaps = $this->repo->findOverlap(
            $idPerusahaan,
            $data['id_rute'],
            $data['id_jenis_kendaraan'],
            $data['id_klien'],
            $data['tanggal_mulai'],
            $data['tanggal_berakhir'] ?? null,
        );

        $ditutup = [];
        foreach ($overlaps as $existing) {
            $bisaDitutup = $existing->tanggal_berakhir === null
                && $existing->tanggal_mulai < $data['tanggal_mulai'];
            if (!$bisaDitutup) {
                abort(422, 'Periode tarif tumpang tindih dengan tarif yang sudah ada untuk kombinasi rute, jenis kendaraan, dan klien ini');
            }
            $ditutup[] = $existing;
        }

        foreach ($ditutup as $existing) {
            $this->repo->update($existing, [
                'tanggal_berakhir' => Carbon::parse($data['tanggal_mulai'])->subDay()->toDateString(),
            ]);
        }

        $data['id_tarif_rute'] = (string) Str::uuid();
        $created = $this->repo->create($data);

        return $this->repo->findDetailById($created->id_tarif_rute);
    }

    public function update(string $id, array $data, string $idPerusahaan): TarifRuteModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->validasiReferensi($data, $idPerusahaan);

        $idRute        = $data['id_rute'] ?? $record->id_rute;
        $idJenis       = $data['id_jenis_kendaraan'] ?? $record->id_jenis_kendaraan;
        $idKlien       = array_key_exists('id_klien', $data) ? $data['id_klien'] : $record->id_klien;
        $tanggalMulai  = $data['tanggal_mulai'] ?? $record->tanggal_mulai;
        $tanggalAkhir  = array_key_exists('tanggal_berakhir', $data) ? $data['tanggal_berakhir'] : $record->tanggal_berakhir;

        $overlaps = $this->repo->findOverlap(
            $idPerusahaan, $idRute, $idJenis, $idKlien, $tanggalMulai, $tanggalAkhir, $id,
        );
        if ($overlaps->isNotEmpty()) {
            abort(422, 'Periode tarif tumpang tindih dengan tarif yang sudah ada untuk kombinasi rute, jenis kendaraan, dan klien ini');
        }

        $this->repo->update($record, $data);

        return $this->repo->findDetailById($id);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->repo->delete($record);
    }

    public function resolusi(
        string $idPerusahaan,
        string $idRute,
        string $idJenisKendaraan,
        ?string $idKlien = null,
        ?string $tanggal = null,
    ): ?TarifRuteModel {
        $tanggal = $tanggal ?: now()->toDateString();

        if ($idKlien !== null && $idKlien !== '') {
            $kontrak = $this->repo->findBerlaku($idPerusahaan, $idRute, $idJenisKendaraan, $idKlien, $tanggal);
            if ($kontrak !== null) {
                return $kontrak;
            }
        }

        return $this->repo->findBerlaku($idPerusahaan, $idRute, $idJenisKendaraan, null, $tanggal);
    }

    /**
     * Estimasi keuangan (BOK) — murni referensi, mengembalikan null bila
     * parameter/jarak/harga BBM belum lengkap (bukan error).
     */
    public function estimasiBok(
        string $idPerusahaan,
        string $idRute,
        string $idJenisKendaraan,
        ?float $estimasiTol = null,
    ): ?array {
        $rute = $this->ruteRepo->findById($idRute);
        if ($rute === null || $rute->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Rute tidak ditemukan');
        }
        if ($this->repo->jenisKendaraanMilik($idJenisKendaraan, $idPerusahaan) === null) {
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

    private function validasiReferensi(array $data, string $idPerusahaan): void
    {
        if (isset($data['id_rute'])) {
            $rute = $this->ruteRepo->findById($data['id_rute']);
            if ($rute === null || $rute->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Rute tidak ditemukan');
            }
        }
        if (isset($data['id_jenis_kendaraan'])
            && $this->repo->jenisKendaraanMilik($data['id_jenis_kendaraan'], $idPerusahaan) === null) {
            abort(404, 'Jenis kendaraan tidak ditemukan');
        }
        if (!empty($data['id_klien'])
            && $this->repo->klienMilik($data['id_klien'], $idPerusahaan) === null) {
            abort(404, 'Klien tidak ditemukan');
        }
    }
}
