<?php

declare(strict_types=1);

namespace App\Modules\ParameterTagihanTrip;

use App\Modules\ParameterTagihanTrip\Contracts\ParameterTagihanTripRepositoryInterface;
use App\Modules\Penawaran\Contracts\PenawaranRepositoryInterface;
use App\Modules\ProyekRute\Contracts\ProyekRuteRepositoryInterface;
use App\Support\ParameterPenawaran;
use App\Support\ParameterTagihan;
use App\Support\TipeHarga;
use Illuminate\Support\Facades\DB;

class ParameterTagihanTripService
{
    public const MAKS_ADD_DROP = 20;
    public const KETERANGAN_ADD_DROP_OTOMATIS = 'Add Drop otomatis dari titik drop penugasan';

    public function __construct(
        private readonly ParameterTagihanTripRepositoryInterface $repo,
        private readonly ProyekRuteRepositoryInterface $proyekRuteRepo,
        private readonly PenawaranRepositoryInterface $penawaranRepo,
    ) {}

    public function detail(string $idTrip, string $idPerusahaan): array
    {
        $konteks   = $this->konteksAtau404($idTrip, $idPerusahaan);
        $tersimpan = $this->repo->findByTrip($idTrip);
        $mode      = $this->mode((string) $konteks->status);
        $berlaku   = TipeHarga::perRit($konteks->tipe_harga ?? null);
        $terkunci  = $this->repo->tripPunyaFakturAktif($idTrip);
        $hargaDeal = $this->hargaDeal($konteks);
        $tarifPenawaran = $this->tarifPenawaran((string) $konteks->id_proyek);

        $tarif = [];
        foreach (ParameterTagihan::DAFTAR as $kode => $def) {
            $tarif[$kode] = $this->tarifTersimpan($tersimpan, $def) ?? $tarifPenawaran[$kode];
        }

        $rincian = ParameterTagihan::rincian($hargaDeal['harga'] ?? null, $tersimpan);
        if ($mode === 'cancellation' && !$rincian['cancellation']) {
            $rincian = ['cancellation' => false, 'harga_dasar' => null, 'komponen' => [], 'total_parameter' => 0.0];
        }
        $totalTagihan = $berlaku && $rincian['harga_dasar'] !== null
            ? $rincian['harga_dasar'] + $rincian['total_parameter']
            : null;

        return [
            'id_trip'         => $idTrip,
            'status_trip'     => $konteks->status,
            'berlaku'         => $berlaku,
            'mode'            => $mode,
            'terkunci'        => $terkunci,
            'bisa_diatur'     => $berlaku && $mode !== null && !$terkunci,
            'harga_deal'      => $hargaDeal['harga'] ?? null,
            'harga_perkiraan' => $hargaDeal['perkiraan'] ?? false,
            'jumlah_titik_drop' => $this->repo->jumlahTitikDrop($idTrip),
            'add_drop_manual' => (int) ($tersimpan->add_drop_manual ?? 0) === 1,
            'tarif'           => $tarif,
            'nilai'           => [
                'jumlah_overnight' => $mode !== 'cancellation' ? (int) ($tersimpan->jumlah_overnight ?? 0) : 0,
                'jumlah_add_drop'  => $mode !== 'cancellation' ? (int) ($tersimpan->jumlah_add_drop ?? 0) : 0,
                'cross_cluster'    => $mode !== 'cancellation' && (int) ($tersimpan->cross_cluster ?? 0) === 1,
                'cancellation'     => $mode === 'cancellation' && (int) ($tersimpan->cancellation ?? 0) === 1,
                'keterangan'       => $tersimpan->keterangan ?? null,
            ],
            'rincian'         => [
                'cancellation'    => $rincian['cancellation'],
                'harga_dasar'     => $rincian['harga_dasar'],
                'komponen'        => $rincian['komponen'],
                'total_parameter' => $rincian['total_parameter'],
            ],
            'total_tagihan'   => $totalTagihan,
            'diubah_oleh'     => $tersimpan !== null ? $this->repo->namaPengguna($tersimpan->diubah_oleh ?? $tersimpan->dibuat_oleh ?? null) : null,
            'diubah_pada'     => $tersimpan !== null ? ($tersimpan->diubah_pada ?? $tersimpan->dibuat_pada) : null,
        ];
    }

    public function simpan(string $idTrip, array $data, string $idPerusahaan): array
    {
        DB::transaction(function () use ($idTrip, $data, $idPerusahaan) {
            $this->repo->kunciTrip($idTrip);
            $konteks = $this->konteksAtau404($idTrip, $idPerusahaan);

            if (!TipeHarga::perRit($konteks->tipe_harga ?? null)) {
                abort(422, 'Parameter tagihan hanya berlaku untuk proyek per rit');
            }
            $mode = $this->mode((string) $konteks->status);
            if ($mode === null) {
                abort(422, 'Parameter tagihan bisa diatur setelah trip berjalan');
            }
            if ($this->repo->tripPunyaFakturAktif($idTrip)) {
                abort(422, 'Trip sudah masuk invoice — parameter tagihan tidak dapat diubah');
            }

            $normal = $mode === 'normal';
            $jumlah = [
                'overnight'     => $normal ? (int) $data['jumlah_overnight'] : 0,
                'add_drop'      => $normal ? (int) $data['jumlah_add_drop'] : 0,
                'cross_cluster' => $normal && (bool) $data['cross_cluster'] ? 1 : 0,
                'cancellation'  => !$normal && (bool) $data['cancellation'] ? 1 : 0,
            ];
            $keterangan = trim((string) ($data['keterangan'] ?? ''));
            if (array_sum($jumlah) > 0 && $keterangan === '') {
                abort(422, 'Keterangan wajib diisi saat ada parameter tagihan');
            }

            $tersimpan = $this->repo->findByTrip($idTrip);
            if ($tersimpan === null && array_sum($jumlah) === 0) {
                return;
            }

            $tarifPenawaran = $this->tarifPenawaran((string) $konteks->id_proyek);
            $baris = ['keterangan' => $keterangan !== '' ? $keterangan : null];
            foreach (ParameterTagihan::DAFTAR as $kode => $def) {
                $baris[$def['jumlah']] = $jumlah[$kode];
                if ($jumlah[$kode] === 0) {
                    $baris[$def['tarif']] = null;
                    continue;
                }
                $tarif = $this->tarifTersimpan($tersimpan, $def) ?? $tarifPenawaran[$kode];
                if ($tarif === null) {
                    abort(422, 'Tarif ' . ParameterTagihan::label($kode) . ' belum diatur di penawaran proyek');
                }
                $baris[$def['tarif']] = $tarif;
            }

            $addDropDiubah = $jumlah['add_drop'] !== (int) ($tersimpan->jumlah_add_drop ?? 0);
            $baris['add_drop_manual'] = ((int) ($tersimpan->add_drop_manual ?? 0) === 1 || $addDropDiubah) ? 1 : 0;

            $this->repo->simpan($idTrip, $baris);
        });

        return $this->detail($idTrip, $idPerusahaan);
    }

    public function sinkronAddDropOtomatisPenugasan(string $idPenugasan): void
    {
        foreach ($this->repo->idTripBerjalanUntukPenugasan($idPenugasan) as $idTrip) {
            $this->sinkronAddDropOtomatis($idTrip);
        }
    }

    public function sinkronAddDropOtomatis(string $idTrip): void
    {
        $konteks = $this->repo->konteksTrip($idTrip, null);
        if ($konteks === null || !TipeHarga::perRit($konteks->tipe_harga ?? null)) {
            return;
        }
        if (!in_array($konteks->status, ['belum_mulai', 'berjalan'], true) || $this->repo->tripPunyaFakturAktif($idTrip)) {
            return;
        }

        $tersimpan = $this->repo->findByTrip($idTrip);
        if ($tersimpan !== null && (int) ($tersimpan->add_drop_manual ?? 0) === 1) {
            return;
        }

        $jumlah = min($this->repo->jumlahTitikDrop($idTrip), self::MAKS_ADD_DROP);
        if ($jumlah === (int) ($tersimpan->jumlah_add_drop ?? 0)) {
            return;
        }

        $def   = ParameterTagihan::DAFTAR['add_drop'];
        $tarif = null;
        if ($jumlah > 0) {
            $tarif = $this->tarifTersimpan($tersimpan, $def) ?? $this->tarifPenawaran((string) $konteks->id_proyek)['add_drop'];
            if ($tarif === null) {
                return;
            }
        }

        $keterangan = trim((string) ($tersimpan->keterangan ?? ''));
        if ($jumlah > 0 && $keterangan === '') {
            $keterangan = self::KETERANGAN_ADD_DROP_OTOMATIS;
        }
        if ($jumlah === 0 && $keterangan === self::KETERANGAN_ADD_DROP_OTOMATIS) {
            $keterangan = '';
        }

        $this->repo->simpan($idTrip, [
            $def['jumlah']    => $jumlah,
            $def['tarif']     => $tarif,
            'keterangan'      => $keterangan !== '' ? $keterangan : null,
            'add_drop_manual' => 0,
        ]);
    }

    private function konteksAtau404(string $idTrip, string $idPerusahaan): object
    {
        $konteks = $this->repo->konteksTrip($idTrip, $idPerusahaan);
        if ($konteks === null) {
            abort(404, 'Trip tidak ditemukan');
        }
        return $konteks;
    }

    private function mode(string $status): ?string
    {
        return match ($status) {
            'berjalan', 'selesai' => 'normal',
            'dibatalkan'          => 'cancellation',
            default               => null,
        };
    }

    private function tarifTersimpan(?object $tersimpan, array $def): ?float
    {
        if ($tersimpan === null || (int) ($tersimpan->{$def['jumlah']} ?? 0) <= 0) {
            return null;
        }
        $tarif = $tersimpan->{$def['tarif']} ?? null;
        return $tarif !== null && (float) $tarif > 0 ? (float) $tarif : null;
    }

    /** @return array<string, float|null> */
    private function tarifPenawaran(string $idProyek): array
    {
        $nilai = ParameterPenawaran::nilai($this->penawaranRepo->penawaranDisetujuiTerbaruProyek($idProyek));

        $hasil = [];
        foreach (ParameterTagihan::DAFTAR as $kode => $def) {
            $v = $nilai[$def['sumber']] ?? null;
            $hasil[$kode] = $v !== null && $v > 0 ? $v : null;
        }
        return $hasil;
    }

    /** @return array{harga: float, perkiraan: bool}|null */
    private function hargaDeal(object $konteks): ?array
    {
        if ($konteks->id_rute === null) {
            return null;
        }
        $idJenis = $konteks->id_jenis_kendaraan ?? $konteks->id_jenis_kendaraan_vendor ?? null;
        $baris = $this->proyekRuteRepo->findHarga(
            (string) $konteks->id_proyek,
            (string) $konteks->id_rute,
            $idJenis !== null ? (string) $idJenis : null,
        );
        if ($baris === null || $baris->harga_penawaran === null) {
            return null;
        }
        return ['harga' => (float) $baris->harga_penawaran, 'perkiraan' => (bool) ($baris->tarif_perkiraan ?? false)];
    }
}
