<?php

declare(strict_types=1);

namespace App\Modules\PenagihanTrip;

use App\Modules\Faktur\Contracts\FakturRepositoryInterface;
use App\Modules\Faktur\FakturModel;
use App\Modules\Faktur\FakturService;
use App\Modules\PenagihanTrip\Contracts\PenagihanTripRepositoryInterface;
use App\Modules\Penawaran\Contracts\PenawaranRepositoryInterface;
use App\Modules\ProyekRute\Contracts\ProyekRuteRepositoryInterface;
use App\Support\ParameterTagihan;
use App\Support\TipeHarga;
use Illuminate\Support\Facades\DB;

class PenagihanTripService
{
    public function __construct(
        private readonly PenagihanTripRepositoryInterface $repo,
        private readonly ProyekRuteRepositoryInterface $proyekRuteRepo,
        private readonly FakturService $fakturService,
        private readonly FakturRepositoryInterface $fakturRepo,
        private readonly PenawaranRepositoryInterface $penawaranRepo,
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
        $borongan = TipeHarga::nilaiTetap($row->tipe_harga ?? null);

        $tarif = null;
        if (!$borongan && $row->id_rute !== null) {
            $baris = $this->proyekRuteRepo->findHarga(
                (string) $row->id_proyek,
                (string) $row->id_rute,
                $idJenisKendaraan !== null ? (string) $idJenisKendaraan : null,
            );
            if ($baris !== null && $baris->harga_penawaran !== null) {
                $tarif = ['harga' => (float) $baris->harga_penawaran, 'perkiraan' => (bool) ($baris->tarif_perkiraan ?? false)];
            }
        }

        $rincian = $borongan ? null : ParameterTagihan::rincian($tarif['harga'] ?? null, $row);

        return [
            'id_trip'         => $row->id_trip,
            'status'          => $row->status ?? 'selesai',
            'tanggal'         => $row->tanggal,
            'id_rute'         => $row->id_rute,
            'rute'            => $row->nama_rute ?? $row->rute_teks,
            'nopol'           => $row->nopol_internal ?? $row->nopol_vendor,
            'supir_nama'      => $row->nama_supir_internal ?? $row->nama_supir_vendor,
            'sumber'          => $row->sumber ?? 'internal',
            'jarak_tempuh_km' => $row->jarak_tempuh_km !== null ? (float) $row->jarak_tempuh_km : null,
            'tarif'           => $tarif,
            'borongan'        => $borongan,
            'bisa_ditagih'    => $rincian !== null && $rincian['harga_dasar'] !== null,
            'harga_dasar'     => $rincian['harga_dasar'] ?? null,
            'parameter'       => [
                'cancellation' => $rincian['cancellation'] ?? false,
                'komponen'     => $rincian['komponen'] ?? [],
                'total'        => $rincian['total_parameter'] ?? 0.0,
            ],
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
                    abort(422, 'Trip proyek selain On Call difakturkan dari halaman proyek');
                }
                if (!$baris['bisa_ditagih']) {
                    abort(422, 'Tarif belum diatur di rute proyek');
                }
                $terpilih[] = $baris;
            }

            $biayaMap = $this->repo->biayaTagihanUntukTrips(array_map(fn ($b) => (string) $b['id_trip'], $terpilih));

            $items = $this->susunItemFaktur($terpilih, $biayaMap, (string) $proyek->nama_proyek, trim((string) ($data['keterangan'] ?? '')));

            $penawaran = $this->penawaranRepo->penawaranDisetujuiTerbaruProyek((string) $proyek->id_proyek);

            $faktur = $this->fakturService->create([
                'id_perusahaan'  => $idPerusahaan,
                'nomor_faktur'   => $this->fakturRepo->nomorBerikutnya($idPerusahaan),
                'id_proyek'      => $proyek->id_proyek,
                'id_klien'       => $proyek->id_klien,
                'id_penawaran'   => $penawaran?->id_penawaran,
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

    /**
     * @param array<int, array<string, mixed>> $terpilih
     * @param array<string, array<int, array{nama_biaya: string, nominal: float}>> $biayaMap
     * @return array<int, array{deskripsi: string, qty: int|float, harga_satuan: float}>
     */
    private function susunItemFaktur(array $terpilih, array $biayaMap, string $namaProyek, string $deskripsiKustom): array
    {
        $jumlahCancel = count(array_filter($terpilih, fn ($b) => $b['parameter']['cancellation']));
        $jumlahRit    = count($terpilih) - $jumlahCancel;

        $items = [];
        if ($jumlahRit > 0) {
            $items[] = [
                'deskripsi'    => $deskripsiKustom !== '' ? $deskripsiKustom : "Jasa angkutan {$namaProyek} — {$jumlahRit} rit",
                'qty'          => 1,
                'harga_satuan' => (float) array_sum(array_map(fn ($b) => (float) $b['harga_dasar'], $terpilih)),
            ];
        }

        $kelompok = [];
        foreach ($terpilih as $baris) {
            foreach ($baris['parameter']['komponen'] as $k) {
                $kunci = $k['kode'] . '|' . number_format((float) $k['tarif'], 2, '.', '');
                $kelompok[$kunci] ??= ['kode' => $k['kode'], 'tarif' => (float) $k['tarif'], 'jumlah' => 0];
                $kelompok[$kunci]['jumlah'] += (int) $k['jumlah'];
            }
        }
        foreach (array_keys(ParameterTagihan::DAFTAR) as $kode) {
            foreach ($kelompok as $g) {
                if ($g['kode'] === $kode) {
                    $items[] = [
                        'deskripsi'    => ParameterTagihan::deskripsiInvoice($kode),
                        'qty'          => $g['jumlah'],
                        'harga_satuan' => $g['tarif'],
                    ];
                }
            }
        }

        $biayaPerNama = [];
        foreach ($biayaMap as $daftarBiaya) {
            foreach ($daftarBiaya as $biaya) {
                $nama  = trim((string) ($biaya['nama_biaya'] ?? ''));
                $nama  = $nama !== '' ? $nama : 'Biaya tambahan';
                $kunci = mb_strtolower($nama);
                $biayaPerNama[$kunci] ??= ['nama' => $nama, 'nominal' => 0.0];
                $biayaPerNama[$kunci]['nominal'] += (float) ($biaya['nominal'] ?? 0);
            }
        }
        foreach ($biayaPerNama as $biaya) {
            $items[] = [
                'deskripsi'    => $biaya['nama'],
                'qty'          => 1,
                'harga_satuan' => $biaya['nominal'],
            ];
        }

        if ($jumlahRit === 0 && $deskripsiKustom !== '' && $items !== []) {
            $items[0]['deskripsi'] = $deskripsiKustom;
        }

        return $items;
    }
}
