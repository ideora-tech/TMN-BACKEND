<?php

declare(strict_types=1);

namespace App\Modules\LaporanPerjalanan;

use App\Modules\JenisBbm\Contracts\JenisBbmRepositoryInterface;
use App\Modules\LaporanPerjalanan\Contracts\LaporanPerjalananRepositoryInterface;
use App\Modules\Trip\Contracts\TripRepositoryInterface;
use App\Support\PenyimpananBerkas;
use Illuminate\Http\UploadedFile;

class LaporanPerjalananService
{
    public function __construct(
        private readonly LaporanPerjalananRepositoryInterface $repo,
        private readonly TripRepositoryInterface $tripRepo,
        private readonly JenisBbmRepositoryInterface $jenisBbmRepo,
    ) {}

    /** @param UploadedFile[] $fotoFiles */
    public function createForTrip(string $idTrip, array $data, string $idPerusahaan, array $fotoFiles = []): LaporanPerjalananModel
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

        $this->pastikanJenisBbmMilikPerusahaan($data, $idPerusahaan);
        $this->tolakBiayaTanggunganVendor($idTrip, $data);

        $hasBiayaTagihan = array_key_exists('biaya_tagihan', $data);
        $biayaTagihan = $data['biaya_tagihan'] ?? [];
        unset($data['biaya_tagihan']);
        if ($hasBiayaTagihan && $this->tripRepo->tripPunyaFakturAktif($idTrip)) {
            abort(422, 'Trip sudah masuk invoice — biaya tagihan tidak dapat diubah');
        }

        $biayaLain = $data['biaya_lain'] ?? [];
        unset($data['biaya_lain'], $data['foto']);

        $laporan = $this->repo->create(array_merge($data, [
            'id_trip'       => $idTrip,
            'id_perusahaan' => $idPerusahaan,
        ]));
        $this->repo->syncBiayaLain($laporan, $biayaLain);
        if ($hasBiayaTagihan) {
            $this->repo->syncBiayaTagihan($laporan, $biayaTagihan);
        }
        $this->simpanFotoFiles($laporan, $fotoFiles);

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

    private function pastikanTripMilikSupir(string $idTrip, string $idSupir): object
    {
        $penugasan = $this->tripRepo->findPenugasanDariTrip($idTrip);
        if ($penugasan === null || (string) $penugasan->id_supir !== $idSupir) {
            abort(403, 'Trip ini bukan milik Anda');
        }
        return $penugasan;
    }

    public function showUntukSupir(string $idTrip, string $idSupir): ?LaporanPerjalananModel
    {
        $this->pastikanTripMilikSupir($idTrip, $idSupir);
        return $this->repo->findByTrip($idTrip);
    }

    /** @param UploadedFile[] $fotoFiles */
    public function upsertUntukSupir(string $idTrip, string $idSupir, array $data, string $idPerusahaan, array $fotoFiles = []): LaporanPerjalananModel
    {
        $this->pastikanTripMilikSupir($idTrip, $idSupir);

        $trip = $this->tripRepo->findById($idTrip);
        if ($trip === null) {
            abort(404, 'Trip tidak ditemukan');
        }
        if ($trip->status !== 'selesai') {
            abort(422, 'Laporan hanya bisa diisi setelah trip selesai');
        }

        $this->pastikanJenisBbmMilikPerusahaan($data, $idPerusahaan);
        $this->tolakBiayaTanggunganVendor($idTrip, $data);

        $existing = $this->repo->findByTrip($idTrip);
        $hasBiayaLain = array_key_exists('biaya_lain', $data);
        $biayaLain = $data['biaya_lain'] ?? [];
        unset($data['biaya_lain'], $data['foto']);

        $laporan = $existing === null
            ? $this->repo->create(array_merge($data, ['id_trip' => $idTrip, 'id_perusahaan' => $idPerusahaan]))
            : $this->repo->update($existing, $data);

        if ($hasBiayaLain) {
            $this->repo->syncBiayaLain($laporan, $biayaLain);
        }
        $this->simpanFotoFiles($laporan, $fotoFiles);

        return $this->repo->reload($laporan);
    }

    /**
     * @param UploadedFile[] $files
     * @return FotoLaporanPerjalananModel[]
     */
    public function addFotoUntukSupir(string $idLaporan, string $idSupir, array $files, ?string $keterangan = null): array
    {
        $laporan = $this->findOrFail($idLaporan);
        $this->pastikanTripMilikSupir($laporan->id_trip, $idSupir);

        return $this->simpanFotoFiles($laporan, $files, $keterangan);
    }

    public function deleteFotoUntukSupir(string $idLaporan, string $idFoto, string $idSupir): void
    {
        $laporan = $this->findOrFail($idLaporan);
        $this->pastikanTripMilikSupir($laporan->id_trip, $idSupir);

        $foto = $this->repo->findFotoById($idLaporan, $idFoto);
        if (!$foto) {
            abort(404, 'Foto laporan tidak ditemukan');
        }
        $this->repo->deleteFoto($foto);
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

    /** @param UploadedFile[] $fotoFiles */
    public function update(string $id, array $data, string $idPerusahaan, array $fotoFiles = []): LaporanPerjalananModel
    {
        $record = $this->findOrFailMilik($id, $idPerusahaan);

        $this->pastikanJenisBbmMilikPerusahaan($data, $idPerusahaan);
        $this->tolakBiayaTanggunganVendor((string) $record->id_trip, $data);

        $hasBiayaTagihan = array_key_exists('biaya_tagihan', $data);
        $biayaTagihan = $data['biaya_tagihan'] ?? [];
        unset($data['biaya_tagihan']);
        if ($hasBiayaTagihan && $this->tripRepo->tripPunyaFakturAktif((string) $record->id_trip)) {
            abort(422, 'Trip sudah masuk invoice — biaya tagihan tidak dapat diubah');
        }

        $hasBiayaLain = array_key_exists('biaya_lain', $data);
        $biayaLain = $data['biaya_lain'] ?? [];
        unset($data['biaya_lain'], $data['foto']);

        $record = $this->repo->update($record, $data);

        if ($hasBiayaLain) {
            $this->repo->syncBiayaLain($record, $biayaLain);
        }
        if ($hasBiayaTagihan) {
            $this->repo->syncBiayaTagihan($record, $biayaTagihan);
        }
        $this->simpanFotoFiles($record, $fotoFiles);

        return $this->repo->reload($record);
    }

    private const LABEL_MEKANISME = ['unit_only' => 'Unit Only', 'unit_driver' => 'Unit + Driver', 'full' => 'Full'];

    private function tolakBiayaTanggunganVendor(string $idTrip, array $data): void
    {
        $penugasan = $this->tripRepo->findPenugasanDariTrip($idTrip);
        if ($penugasan === null || ($penugasan->sumber ?? 'internal') !== 'vendor' || empty($penugasan->id_kontrak_vendor)) {
            return;
        }

        $mekanisme = $this->repo->mekanismeKontrak((string) $penugasan->id_kontrak_vendor);
        if ($mekanisme === null || $mekanisme === 'unit_only') {
            return;
        }

        $label = self::LABEL_MEKANISME[$mekanisme] ?? $mekanisme;
        $terisi = fn (string $kolom) => isset($data[$kolom]) && (float) $data[$kolom] > 0;

        if ($terisi('uang_jalan')) {
            abort(422, "Uang jalan ditanggung vendor (kontrak {$label})");
        }
        if ($mekanisme === 'full'
            && ($terisi('biaya_bbm') || $terisi('jumlah_liter') || $terisi('uang_tol') || !empty($data['id_jenis_bbm']) || !empty($data['biaya_lain']))) {
            abort(422, "Biaya operasional ditanggung vendor (kontrak {$label})");
        }
    }

    private function pastikanJenisBbmMilikPerusahaan(array $data, string $idPerusahaan): void
    {
        if (!array_key_exists('id_jenis_bbm', $data) || $data['id_jenis_bbm'] === null) {
            return;
        }

        if ($this->jenisBbmRepo->findByIdMilik($data['id_jenis_bbm'], $idPerusahaan) === null) {
            abort(404, 'Jenis BBM tidak ditemukan');
        }
    }

    /**
     * @param UploadedFile[] $files
     * @return FotoLaporanPerjalananModel[]
     */
    public function addFoto(string $idLaporan, array $files, string $idPerusahaan, ?string $keterangan = null): array
    {
        $laporan = $this->findOrFailMilik($idLaporan, $idPerusahaan);

        return $this->simpanFotoFiles($laporan, $files, $keterangan);
    }

    /**
     * @param UploadedFile[] $files
     * @return FotoLaporanPerjalananModel[]
     */
    private function simpanFotoFiles(LaporanPerjalananModel $laporan, array $files, ?string $keterangan = null): array
    {
        $hasil = [];
        foreach ($files as $file) {
            $hasil[] = $this->repo->addFoto($laporan->id_laporan, [
                'url_file'   => PenyimpananBerkas::simpan($file, 'laporan-perjalanan'),
                'keterangan' => $keterangan,
            ]);
        }
        return $hasil;
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
