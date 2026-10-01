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
    private const PESAN_LAPORAN_TERKUNCI = 'Laporan sudah diselesaikan dan terkunci — hubungi kantor untuk koreksi';

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
        $this->pastikanBiayaBbmDiisi($idTrip, $data);

        $hasBiayaTagihan = array_key_exists('biaya_tagihan', $data);
        $biayaTagihan = $data['biaya_tagihan'] ?? [];
        unset($data['biaya_tagihan']);
        if ($hasBiayaTagihan && $this->tripRepo->tripPunyaFakturAktif($idTrip)) {
            abort(422, 'Trip sudah masuk invoice — biaya tagihan tidak dapat diubah');
        }

        $biayaLain = $data['biaya_lain'] ?? [];
        $fotoKeterangan = $data['foto_keterangan'] ?? null;
        unset($data['biaya_lain'], $data['foto'], $data['foto_keterangan']);

        if ($fotoFiles === []) {
            abort(422, 'Laporan wajib menyertakan minimal 1 foto bukti');
        }

        $suratJalan = $this->siapkanSuratJalan($idTrip, $data, null);

        $laporan = $this->repo->create(array_merge($data, [
            'id_trip'       => $idTrip,
            'id_perusahaan' => $idPerusahaan,
            'status'        => 'final',
        ]));
        $this->repo->syncBiayaLain($laporan, $biayaLain);
        if ($hasBiayaTagihan) {
            $this->repo->syncBiayaTagihan($laporan, $biayaTagihan);
        }
        if ($suratJalan !== null) {
            $this->repo->syncSuratJalan($laporan, $suratJalan);
        }
        $this->simpanFotoFiles($laporan, $fotoFiles, null, $fotoKeterangan);

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

    private function pastikanTripMilikSupir(string $idTrip, string $idSupir, string $tipe = 'internal'): object
    {
        $penugasan = $this->tripRepo->findPenugasanDariTrip($idTrip);
        $milik = $penugasan !== null && ($tipe === 'vendor'
            ? (string) $penugasan->id_supir_vendor === $idSupir
            : (string) $penugasan->id_supir === $idSupir);
        if (!$milik) {
            abort(403, 'Trip ini bukan milik Anda');
        }
        return $penugasan;
    }

    public function showUntukSupir(string $idTrip, string $idSupir, string $tipe = 'internal'): ?LaporanPerjalananModel
    {
        $this->pastikanTripMilikSupir($idTrip, $idSupir, $tipe);
        return $this->repo->findByTrip($idTrip);
    }

    /** @param UploadedFile[] $fotoFiles */
    public function upsertUntukSupir(string $idTrip, string $idSupir, array $data, string $idPerusahaan, array $fotoFiles = [], string $tipe = 'internal'): LaporanPerjalananModel
    {
        $this->pastikanTripMilikSupir($idTrip, $idSupir, $tipe);

        $trip = $this->tripRepo->findById($idTrip);
        if ($trip === null) {
            abort(404, 'Trip tidak ditemukan');
        }
        if (!in_array($trip->status, ['berjalan', 'selesai'], true)) {
            abort(422, 'Laporan hanya bisa diisi untuk trip yang sedang berjalan atau sudah selesai');
        }

        $existing = $this->repo->findByTrip($idTrip);
        if ($existing !== null && ($existing->status ?? 'final') === 'final') {
            abort(422, self::PESAN_LAPORAN_TERKUNCI);
        }

        $this->pastikanJenisBbmMilikPerusahaan($data, $idPerusahaan);
        $this->tolakBiayaTanggunganVendor($idTrip, $data);

        if ($existing === null) {
            $this->pastikanBiayaBbmDiisi($idTrip, $data);
        }
        $totalFotoSaatIni = $existing !== null ? $existing->foto()->count() : 0;
        if ($fotoFiles === [] && $totalFotoSaatIni === 0) {
            abort(422, 'Laporan wajib menyertakan minimal 1 foto bukti');
        }

        $hasBiayaLain = array_key_exists('biaya_lain', $data);
        $biayaLain = $data['biaya_lain'] ?? [];
        $fotoKeterangan = $data['foto_keterangan'] ?? null;
        unset($data['biaya_lain'], $data['foto'], $data['foto_keterangan']);

        $suratJalan = $this->siapkanSuratJalan($idTrip, $data, $existing);

        $laporan = $existing === null
            ? $this->repo->create(array_merge($data, ['id_trip' => $idTrip, 'id_perusahaan' => $idPerusahaan]))
            : $this->repo->update($existing, $data);

        if ($hasBiayaLain) {
            $this->repo->syncBiayaLain($laporan, $biayaLain);
        }
        if ($suratJalan !== null) {
            $this->repo->syncSuratJalan($laporan, $suratJalan);
        }
        $this->simpanFotoFiles($laporan, $fotoFiles, null, $fotoKeterangan);

        return $this->repo->reload($laporan);
    }

    /**
     * @param UploadedFile[] $files
     * @return FotoLaporanPerjalananModel[]
     */
    public function addFotoUntukSupir(string $idLaporan, string $idSupir, array $files, ?string $keterangan = null, string $tipe = 'internal', ?array $fotoKeterangan = null): array
    {
        $laporan = $this->findOrFail($idLaporan);
        $this->pastikanTripMilikSupir($laporan->id_trip, $idSupir, $tipe);
        $this->pastikanLaporanTidakTerkunci($laporan);

        return $this->simpanFotoFiles($laporan, $files, $keterangan, $fotoKeterangan);
    }

    public function deleteFotoUntukSupir(string $idLaporan, string $idFoto, string $idSupir, string $tipe = 'internal'): void
    {
        $laporan = $this->findOrFail($idLaporan);
        $this->pastikanTripMilikSupir($laporan->id_trip, $idSupir, $tipe);
        $this->pastikanLaporanTidakTerkunci($laporan);

        $foto = $this->repo->findFotoById($idLaporan, $idFoto);
        if (!$foto) {
            abort(404, 'Foto laporan tidak ditemukan');
        }
        $this->repo->deleteFoto($foto);
    }

    public function selesaikanUntukSupir(string $idTrip, string $idSupir, string $tipe = 'internal'): LaporanPerjalananModel
    {
        $this->pastikanTripMilikSupir($idTrip, $idSupir, $tipe);

        $laporan = $this->repo->findByTrip($idTrip);
        if ($laporan === null) {
            abort(404, 'Laporan perjalanan belum diisi');
        }
        if (($laporan->status ?? 'final') === 'final') {
            return $this->repo->reload($laporan);
        }

        $laporan = $this->repo->update($laporan, ['status' => 'final']);

        return $this->repo->reload($laporan);
    }

    private function pastikanLaporanTidakTerkunci(LaporanPerjalananModel $laporan): void
    {
        if (($laporan->status ?? 'final') === 'final') {
            abort(422, self::PESAN_LAPORAN_TERKUNCI);
        }
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
        $fotoKeterangan = $data['foto_keterangan'] ?? null;
        unset($data['biaya_lain'], $data['foto'], $data['foto_keterangan']);

        $suratJalan = $this->siapkanSuratJalan((string) $record->id_trip, $data, $record);

        $record = $this->repo->update($record, $data);

        if ($hasBiayaLain) {
            $this->repo->syncBiayaLain($record, $biayaLain);
        }
        if ($hasBiayaTagihan) {
            $this->repo->syncBiayaTagihan($record, $biayaTagihan);
        }
        if ($suratJalan !== null) {
            $this->repo->syncSuratJalan($record, $suratJalan);
        }
        $this->simpanFotoFiles($record, $fotoFiles, null, $fotoKeterangan);

        return $this->repo->reload($record);
    }

    public function titikDropUntukSupir(string $idTrip, string $idSupir, string $tipe = 'internal'): array
    {
        $this->pastikanTripMilikSupir($idTrip, $idSupir, $tipe);

        return $this->tripRepo->titikDropTripDetail($idTrip);
    }

    /** @return array<int, array{no_surat_jalan: string, id_titik_drop: ?string}>|null */
    private function siapkanSuratJalan(string $idTrip, array &$data, ?LaporanPerjalananModel $existing): ?array
    {
        if (array_key_exists('surat_jalan', $data)) {
            $idDropValid = array_column($this->tripRepo->titikDropTripDetail($idTrip), 'id_titik_drop');
            $daftar = [];
            foreach ($data['surat_jalan'] ?? [] as $item) {
                $no = trim((string) ($item['no_surat_jalan'] ?? ''));
                if ($no === '') {
                    continue;
                }
                $idDrop = !empty($item['id_titik_drop']) ? (string) $item['id_titik_drop'] : null;
                if ($idDrop !== null && !in_array($idDrop, $idDropValid, true)) {
                    abort(422, 'Titik drop surat jalan tidak ditemukan di trip ini');
                }
                $daftar[] = ['no_surat_jalan' => $no, 'id_titik_drop' => $idDrop];
            }
            unset($data['surat_jalan']);
            $data['no_surat_jalan'] = $this->gabungNoSuratJalan(array_column($daftar, 'no_surat_jalan'));

            return $daftar;
        }

        if (!array_key_exists('no_surat_jalan', $data)) {
            return null;
        }

        $teks = trim((string) ($data['no_surat_jalan'] ?? ''));
        $data['no_surat_jalan'] = $teks === '' ? null : $teks;
        if ($existing !== null && trim((string) ($existing->no_surat_jalan ?? '')) === $teks) {
            return null;
        }

        $sisaLama = collect($existing?->suratJalan ?? [])->values()->all();
        $daftar = [];
        foreach (array_filter(array_map('trim', preg_split('/[,;]/', $teks) ?: []), fn ($no) => $no !== '') as $no) {
            $no = mb_substr($no, 0, 100);
            $idDrop = null;
            foreach ($sisaLama as $i => $lama) {
                if ((string) $lama->no_surat_jalan === $no) {
                    $idDrop = $lama->id_titik_drop;
                    unset($sisaLama[$i]);
                    break;
                }
            }
            $daftar[] = ['no_surat_jalan' => $no, 'id_titik_drop' => $idDrop];
        }

        return $daftar;
    }

    private function gabungNoSuratJalan(array $daftarNo): ?string
    {
        $gabung = '';
        foreach ($daftarNo as $no) {
            $calon = $gabung === '' ? $no : "{$gabung}, {$no}";
            if (mb_strlen($calon) > 500) {
                break;
            }
            $gabung = $calon;
        }

        return $gabung === '' ? null : $gabung;
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

    private function pastikanBiayaBbmDiisi(string $idTrip, array $data): void
    {
        $penugasan = $this->tripRepo->findPenugasanDariTrip($idTrip);
        if ($penugasan !== null && ($penugasan->sumber ?? 'internal') === 'vendor' && !empty($penugasan->id_kontrak_vendor)) {
            $mekanisme = $this->repo->mekanismeKontrak((string) $penugasan->id_kontrak_vendor);
            if ($mekanisme === 'full') {
                return;
            }
        }

        if (empty($data['biaya_bbm']) || (float) $data['biaya_bbm'] <= 0) {
            abort(422, 'Biaya BBM wajib diisi');
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
    public function addFoto(string $idLaporan, array $files, string $idPerusahaan, ?string $keterangan = null, ?array $fotoKeterangan = null): array
    {
        $laporan = $this->findOrFailMilik($idLaporan, $idPerusahaan);

        return $this->simpanFotoFiles($laporan, $files, $keterangan, $fotoKeterangan);
    }

    /**
     * @param UploadedFile[] $files
     * @param string[]|null $fotoKeterangan
     * @return FotoLaporanPerjalananModel[]
     */
    private function simpanFotoFiles(LaporanPerjalananModel $laporan, array $files, ?string $keterangan = null, ?array $fotoKeterangan = null): array
    {
        $hasil = [];
        foreach (array_values($files) as $i => $file) {
            $ket = !empty($fotoKeterangan[$i]) ? $fotoKeterangan[$i] : $keterangan;
            $hasil[] = $this->repo->addFoto($laporan->id_laporan, [
                'url_file'   => PenyimpananBerkas::simpan($file, 'laporan-perjalanan'),
                'keterangan' => $ket,
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
