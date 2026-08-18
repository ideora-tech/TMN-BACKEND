<?php

declare(strict_types=1);

namespace App\Modules\PenagihanTrip;

use App\Modules\Faktur\Contracts\FakturRepositoryInterface;
use App\Modules\Faktur\FakturModel;
use App\Modules\Faktur\FakturService;
use App\Modules\PenagihanTrip\Contracts\PenagihanTripRepositoryInterface;
use App\Modules\ProyekRute\Contracts\ProyekRuteRepositoryInterface;
use Illuminate\Support\Facades\DB;

class PenagihanTripService
{
    public function __construct(
        private readonly PenagihanTripRepositoryInterface $repo,
        private readonly ProyekRuteRepositoryInterface $proyekRuteRepo,
        private readonly FakturService $fakturService,
        private readonly FakturRepositoryInterface $fakturRepo,
    ) {}

    public function daftar(string $idProyek, string $idPerusahaan, ?string $dari, ?string $sampai): array
    {
        $proyek = $this->repo->proyekInfo($idProyek, $idPerusahaan);
        if ($proyek === null) {
            abort(404, 'Proyek tidak ditemukan');
        }

        $rows = $this->repo->tripSiapTagih($idPerusahaan, $idProyek, $dari, $sampai);

        $biayaMap = $this->repo->biayaTagihanUntukTrips(array_map(fn ($row) => (string) $row->id_trip, $rows));

        return array_map(fn ($row) => $this->mapBaris($row, $biayaMap[$row->id_trip] ?? []), $rows);
    }

    private function mapBaris(object $row, array $biayaTagihan = []): array
    {
        $idJenisKendaraan = $row->id_jenis_kendaraan ?? $row->id_jenis_kendaraan_vendor ?? null;
        $borongan = ($row->tipe_harga ?? 'per_rit') === 'borongan';

        $tarif = null;
        if (!$borongan && $row->id_rute !== null) {
            $baris = $this->proyekRuteRepo->findHarga(
                (string) $row->id_proyek,
                (string) $row->id_rute,
                $idJenisKendaraan !== null ? (string) $idJenisKendaraan : null,
            );
            if ($baris !== null && $baris->harga_penawaran !== null) {
                $tarif = ['harga' => (float) $baris->harga_penawaran];
            }
        }

        return [
            'id_trip'         => $row->id_trip,
            'tanggal'         => $row->tanggal,
            'id_rute'         => $row->id_rute,
            'rute'            => $row->nama_rute ?? $row->rute_teks,
            'nopol'           => $row->nopol_internal ?? $row->nopol_vendor,
            'supir_nama'      => $row->nama_supir_internal ?? $row->nama_supir_vendor,
            'sumber'          => $row->sumber ?? 'internal',
            'jarak_tempuh_km' => $row->jarak_tempuh_km !== null ? (float) $row->jarak_tempuh_km : null,
            'tarif'           => $tarif,
            'borongan'        => $borongan,
            'bisa_ditagih'    => $tarif !== null && !$borongan,
            'biaya_tagihan'       => $biayaTagihan,
            'total_biaya_tagihan' => array_sum(array_column($biayaTagihan, 'nominal')),
        ];
    }

    public function buatDraftFaktur(array $data, string $idPerusahaan): FakturModel
    {
        return DB::transaction(function () use ($data, $idPerusahaan) {
            $proyek = $this->repo->proyekInfo((string) $data['id_proyek'], $idPerusahaan);
            if ($proyek === null) {
                abort(404, 'Proyek tidak ditemukan');
            }

            $siapTagih = collect($this->repo->tripSiapTagih($idPerusahaan, (string) $data['id_proyek'], null, null, true))
                ->keyBy('id_trip');

            $terpilih = [];
            foreach (array_unique($data['trip_ids']) as $idTrip) {
                $row = $siapTagih->get($idTrip);
                if ($row === null) {
                    abort(422, "Trip {$idTrip} tidak valid atau sudah masuk invoice");
                }
                $baris = $this->mapBaris($row);
                if ($baris['borongan']) {
                    abort(422, 'Trip proyek borongan difakturkan dari halaman proyek');
                }
                if ($baris['tarif'] === null) {
                    abort(422, 'Tarif belum diatur di rute proyek');
                }
                $terpilih[] = $baris;
            }

            $biayaMap = $this->repo->biayaTagihanUntukTrips(array_map(fn ($b) => (string) $b['id_trip'], $terpilih));
            $totalBiaya = 0.0;
            foreach ($biayaMap as $daftarBiaya) {
                $totalBiaya += array_sum(array_column($daftarBiaya, 'nominal'));
            }
            $totalTarif = array_sum(array_map(fn ($b) => (float) $b['tarif']['harga'], $terpilih));

            $deskripsi = trim((string) ($data['keterangan'] ?? ''));
            if ($deskripsi === '') {
                $deskripsi = 'Jasa angkutan ' . $proyek->nama_proyek . ' — ' . count($terpilih) . ' rit';
            }

            $items = [[
                'deskripsi'    => $deskripsi,
                'qty'          => 1,
                'harga_satuan' => $totalTarif + $totalBiaya,
            ]];

            $faktur = $this->fakturService->create([
                'id_perusahaan'  => $idPerusahaan,
                'nomor_faktur'   => $this->fakturRepo->nomorBerikutnya($idPerusahaan),
                'id_proyek'      => $proyek->id_proyek,
                'id_klien'       => $proyek->id_klien,
                'status'         => 'draft',
                'tanggal_faktur' => $data['tanggal_faktur'],
                'jatuh_tempo'    => $data['jatuh_tempo'] ?? null,
                'items'          => $items,
            ]);

            foreach ($terpilih as $baris) {
                $this->repo->insertFakturTrip((string) $faktur->id_faktur, (string) $baris['id_trip']);
            }

            return $faktur;
        });
    }
}
