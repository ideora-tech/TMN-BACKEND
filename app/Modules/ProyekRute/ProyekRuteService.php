<?php

declare(strict_types=1);

namespace App\Modules\ProyekRute;

use App\Modules\KomponenUangJalan\KomponenUangJalanService;
use App\Modules\ProyekRute\Contracts\ProyekRuteRepositoryInterface;
use App\Support\TipeHarga;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProyekRuteService
{
    public function __construct(
        private readonly ProyekRuteRepositoryInterface $repo,
        private readonly KomponenUangJalanService $komponenService,
    ) {}

    public function listByProyek(string $idProyek, string $idPerusahaan): Collection
    {
        $this->pastikanProyekMilikPerusahaan($idProyek, $idPerusahaan);

        return $this->denganKomponen($this->repo->listByProyek($idProyek));
    }

    private function pastikanProyekMilikPerusahaan(string $idProyek, string $idPerusahaan): void
    {
        if (!$this->repo->proyekMilikPerusahaan($idProyek, $idPerusahaan)) {
            abort(404, 'Proyek tidak ditemukan');
        }
    }

    public function create(string $idProyek, array $data, string $idPerusahaan, string $kunciKomponen = 'komponen_uang_jalan'): ProyekRuteModel
    {
        $this->pastikanProyekMilikPerusahaan($idProyek, $idPerusahaan);

        if ($this->hargaTerkunci($idProyek)) {
            abort(422, 'Harga terkunci — ubah lewat penawaran revisi');
        }

        $this->pastikanRuteJenisValid($data, $idPerusahaan);

        $idJenisKendaraan = $data['id_jenis_kendaraan'] ?? null;
        if ($this->repo->existsDuplikat($idProyek, $data['id_rute'], $idJenisKendaraan)) {
            abort(409, 'Rute dengan jenis kendaraan ini sudah terdaftar di proyek');
        }

        [$data, $rincian] = $this->pisahKomponen($data, $idPerusahaan, [], $kunciKomponen);

        return DB::transaction(function () use ($data, $rincian, $idProyek, $idPerusahaan) {
            $record = $this->repo->create(array_merge($data, [
                'id_perusahaan' => $idPerusahaan,
                'id_proyek'     => $idProyek,
            ]));

            if ($rincian !== null) {
                $this->repo->simpanKomponen((string) $record->id_proyek_rute, $idPerusahaan, $rincian);
            }

            return $this->detail((string) $record->id_proyek_rute);
        });
    }

    public function update(string $idProyek, string $id, array $data, string $idPerusahaan): ProyekRuteModel
    {
        $this->pastikanProyekMilikPerusahaan($idProyek, $idPerusahaan);

        $record = $this->repo->findById($id);
        if ($record === null || $record->id_proyek !== $idProyek) {
            abort(404, 'Rute proyek tidak ditemukan');
        }

        if ($this->adaPerubahanRuteHarga($record, $data)) {
            abort(422, 'Rute, jenis kendaraan, dan harga penawaran tidak dapat diubah dari sini — ubah lewat penawaran');
        }

        if ($this->adaPerubahanRitase($record, $data) && $this->hargaTerkunci($idProyek)) {
            abort(422, 'Harga terkunci — ubah lewat penawaran revisi');
        }

        [$data, $rincian] = $this->pisahKomponen($data, $idPerusahaan, $this->repo->idKomponenRute($id), 'komponen_uang_jalan');

        return DB::transaction(function () use ($record, $data, $rincian, $id, $idPerusahaan) {
            $this->repo->update($record, $data);

            if ($rincian !== null) {
                $this->repo->simpanKomponen($id, $idPerusahaan, $rincian);
            }

            return $this->detail($id);
        });
    }

    private function pisahKomponen(array $data, string $idPerusahaan, array $idBolehNonaktif, string $kunci): array
    {
        unset($data['uang_jalan']);

        if (!array_key_exists('komponen_uang_jalan', $data)) {
            return [$data, null];
        }

        $rincian = $this->komponenService->rincianValid($data['komponen_uang_jalan'], $idPerusahaan, $idBolehNonaktif, $kunci);
        unset($data['komponen_uang_jalan']);

        $total = round(array_sum(array_column($rincian, 'nominal')), 2);
        $data['uang_jalan'] = $total > 0 ? $total : null;

        return [$data, $rincian];
    }

    private function detail(string $id): ProyekRuteModel
    {
        return $this->denganKomponen(collect([$this->repo->findDetailById($id)]))->first();
    }

    private function denganKomponen(Collection $baris): Collection
    {
        $peta = $this->repo->komponenUntuk($baris->pluck('id_proyek_rute')->map(fn ($id) => (string) $id)->all());

        return $baris->each(function (ProyekRuteModel $r) use ($peta) {
            $r->setAttribute('komponen_uang_jalan', $peta[(string) $r->id_proyek_rute] ?? []);
        });
    }

    public function delete(string $idProyek, string $id, string $idPerusahaan): void
    {
        $this->pastikanProyekMilikPerusahaan($idProyek, $idPerusahaan);

        $record = $this->repo->findById($id);
        if ($record === null || $record->id_proyek !== $idProyek) {
            abort(404, 'Rute proyek tidak ditemukan');
        }

        if ($this->hargaTerkunci($idProyek)) {
            abort(422, 'Harga terkunci — ubah lewat penawaran revisi');
        }

        $this->repo->delete($record);
    }

    private function hargaTerkunci(string $idProyek): bool
    {
        $tipeHarga = $this->repo->tipeHargaProyek($idProyek) ?? 'per_rit';
        return TipeHarga::perRit($tipeHarga) && $this->repo->adaPenawaranDisetujui($idProyek);
    }

    private function adaPerubahanRitase(ProyekRuteModel $record, array $data): bool
    {
        return array_key_exists('estimasi_ritase', $data) && (int) $data['estimasi_ritase'] !== (int) $record->estimasi_ritase;
    }

    private function adaPerubahanRuteHarga(ProyekRuteModel $record, array $data): bool
    {
        if (array_key_exists('harga_penawaran', $data)) {
            $baru = $data['harga_penawaran'];
            $lama = $record->harga_penawaran;
            if (($baru === null) !== ($lama === null)) {
                return true;
            }
            if ($baru !== null && (float) $baru !== (float) $lama) {
                return true;
            }
        }

        if (array_key_exists('id_rute', $data) && $data['id_rute'] !== $record->id_rute) {
            return true;
        }

        if (array_key_exists('id_jenis_kendaraan', $data) && $data['id_jenis_kendaraan'] !== $record->id_jenis_kendaraan) {
            return true;
        }

        return false;
    }

    private function pastikanRuteJenisValid(array $data, string $idPerusahaan): void
    {
        if (isset($data['id_rute']) && $this->repo->ruteMilik($data['id_rute'], $idPerusahaan) === null) {
            abort(404, 'Rute tidak ditemukan');
        }
        if (isset($data['id_jenis_kendaraan']) && $this->repo->jenisKendaraanMilik($data['id_jenis_kendaraan'], $idPerusahaan) === null) {
            abort(404, 'Jenis kendaraan tidak ditemukan');
        }
    }
}
