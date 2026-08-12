<?php

declare(strict_types=1);

namespace App\Modules\PenagihanTrip;

use App\Modules\Faktur\Contracts\FakturRepositoryInterface;
use App\Modules\Faktur\FakturModel;
use App\Modules\Faktur\FakturService;
use App\Modules\PenagihanTrip\Contracts\PenagihanTripRepositoryInterface;
use App\Modules\TarifRute\TarifRuteService;
use Illuminate\Support\Facades\DB;

class PenagihanTripService
{
    public function __construct(
        private readonly PenagihanTripRepositoryInterface $repo,
        private readonly TarifRuteService $tarifRuteService,
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

        return array_map(fn ($row) => $this->mapBaris($row, $idPerusahaan), $rows);
    }

    private function mapBaris(object $row, string $idPerusahaan): array
    {
        $idJenisKendaraan = $row->id_jenis_kendaraan ?? $row->id_jenis_kendaraan_vendor ?? null;

        $tarif = null;
        if ($row->id_rute !== null && $idJenisKendaraan !== null) {
            $t = $this->tarifRuteService->resolusi(
                $idPerusahaan,
                (string) $row->id_rute,
                (string) $idJenisKendaraan,
                $row->id_klien !== null ? (string) $row->id_klien : null,
                (string) $row->tanggal,
            );
            if ($t !== null) {
                $tarif = ['id_tarif_rute' => $t->id_tarif_rute, 'harga' => (float) $t->harga];
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
            'bisa_ditagih'    => $tarif !== null,
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
                    abort(422, "Trip {$idTrip} tidak valid atau sudah difakturkan");
                }
                $baris = $this->mapBaris($row, $idPerusahaan);
                if ($baris['tarif'] === null) {
                    abort(422, "Trip {$idTrip}: tarif rute belum diatur");
                }
                $terpilih[] = $baris;
            }

            $items = collect($terpilih)
                ->groupBy(fn ($b) => $b['id_rute'] . '|' . $b['tarif']['harga'])
                ->map(fn ($grup) => [
                    'deskripsi'    => "Rute {$grup[0]['rute']} — {$grup->count()} rit",
                    'qty'          => $grup->count(),
                    'harga_satuan' => $grup[0]['tarif']['harga'],
                ])
                ->values()
                ->all();

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
