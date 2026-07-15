<?php

declare(strict_types=1);

namespace App\Modules\LaporanPerjalanan;

use App\Modules\LaporanPerjalanan\Contracts\LaporanPerjalananRepositoryInterface;
use App\Modules\Trip\Contracts\TripRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class LaporanPerjalananService
{
    public function __construct(
        private readonly LaporanPerjalananRepositoryInterface $repo,
        private readonly TripRepositoryInterface $tripRepo,
    ) {}

    public function createForTrip(string $idTrip, array $data, string $idPerusahaan): LaporanPerjalananModel
    {
        $trip = $this->tripRepo->findById($idTrip);
        if (!$trip || !$this->repo->tripMilikPerusahaan($idTrip, $idPerusahaan)) {
            abort(404, 'Trip tidak ditemukan');
        }
        if (!in_array($trip->status, ['berjalan', 'selesai'], true)) {
            abort(422, 'Laporan hanya bisa diisi untuk trip yang sedang berjalan atau sudah selesai');
        }
        if ($this->repo->findByTrip($idTrip)) {
            abort(409, 'Laporan perjalanan untuk trip ini sudah ada');
        }

        $biayaLain = $data['biaya_lain'] ?? [];
        unset($data['biaya_lain']);

        $laporan = $this->repo->create(array_merge($data, [
            'id_trip'       => $idTrip,
            'id_perusahaan' => $idPerusahaan,
        ]));
        $this->repo->syncBiayaLain($laporan, $biayaLain);

        return $this->repo->reload($laporan);
    }

    public function showByTrip(string $idTrip, string $idPerusahaan): LaporanPerjalananModel
    {
        if (!$this->repo->tripMilikPerusahaan($idTrip, $idPerusahaan)) {
            abort(404, 'Laporan perjalanan tidak ditemukan');
        }
        $laporan = $this->repo->findByTrip($idTrip);
        if (!$laporan) {
            abort(404, 'Laporan perjalanan tidak ditemukan');
        }
        return $laporan;
    }

    public function findOrFail(string $id): LaporanPerjalananModel
    {
        $record = $this->repo->findById($id);
        if (!$record) {
            abort(404, 'Laporan perjalanan tidak ditemukan');
        }
        return $record;
    }

    public function findOrFailMilik(string $id, string $idPerusahaan): LaporanPerjalananModel
    {
        $record = $this->repo->findByIdMilik($id, $idPerusahaan);
        if (!$record) {
            abort(404, 'Laporan perjalanan tidak ditemukan');
        }
        return $record;
    }

    public function update(string $id, array $data, string $idPerusahaan): LaporanPerjalananModel
    {
        $record = $this->findOrFailMilik($id, $idPerusahaan);

        $hasBiayaLain = array_key_exists('biaya_lain', $data);
        $biayaLain = $data['biaya_lain'] ?? [];
        unset($data['biaya_lain']);

        $record = $this->repo->update($record, $data);

        if ($hasBiayaLain) {
            $this->repo->syncBiayaLain($record, $biayaLain);
        }

        return $this->repo->reload($record);
    }

    public function addFoto(string $idLaporan, array $data, UploadedFile $file, string $idPerusahaan): FotoLaporanPerjalananModel
    {
        $laporan = $this->findOrFailMilik($idLaporan, $idPerusahaan);

        $path = $file->store('laporan-perjalanan', 'public');
        $data['url_file'] = Storage::disk('public')->url($path);
        unset($data['file']);

        return $this->repo->addFoto($laporan->id_laporan, $data);
    }

    public function deleteFoto(string $idLaporan, string $idFoto, string $idPerusahaan): void
    {
        $this->findOrFailMilik($idLaporan, $idPerusahaan);

        $foto = $this->repo->findFotoById($idLaporan, $idFoto);
        if (!$foto) {
            abort(404, 'Foto laporan tidak ditemukan');
        }
        $this->repo->deleteFoto($foto);
    }
}
