<?php

declare(strict_types=1);

namespace App\Modules\FeeSupir;

use App\Modules\FeeSupir\Contracts\FeeSupirRepositoryInterface;
use App\Modules\Supir\Contracts\SupirRepositoryInterface;
use App\Support\Impor\PembacaImpor;
use App\Support\TipeHarga;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;

class FeeSupirService
{
    private const RENTANG_MAKS = 366;

    public function __construct(
        private readonly FeeSupirRepositoryInterface $repo,
        private readonly SupirRepositoryInterface $supirRepo,
    ) {}

    public function sinkronTrip(string $idTrip, bool $saatCheckout = false): void
    {
        $info = $this->repo->infoTrip($idTrip);
        $ada  = $this->repo->findByTrip($idTrip);

        if ($info === null || $info->status !== 'selesai' || !TipeHarga::perRit($info->tipe_harga)) {
            if ($ada !== null) {
                $this->repo->hapusUntukTrip($idTrip);
            }

            return;
        }

        $data = [
            'id_proyek' => (string) $info->id_proyek,
            'id_rute'   => $info->id_rute !== null ? (string) $info->id_rute : null,
            'tanggal'   => $this->tanggalFee($info),
            'nominal'   => $this->nominalFee($info),
        ];

        if ($ada !== null) {
            if ($this->berubah($ada, $data)) {
                $this->repo->perbarui((string) $ada->id_fee_supir_trip, $data);
            }

            return;
        }

        $terhapus = $this->repo->findTerhapusByTrip($idTrip);
        if ($terhapus !== null) {
            $this->repo->pulihkan((string) $terhapus->id_fee_supir_trip, $data);

            return;
        }

        if (!$saatCheckout || empty($info->id_supir)) {
            return;
        }

        $this->repo->create(array_merge([
            'id_perusahaan' => (string) $info->id_perusahaan,
            'id_trip'       => (string) $info->id_trip,
            'id_penugasan'  => (string) $info->id_penugasan,
            'id_supir'      => (string) $info->id_supir,
        ], $data));
    }

    public function sinkronPenugasan(string $idPenugasan): void
    {
        foreach ($this->repo->idTripSelesaiPenugasan($idPenugasan) as $idTrip) {
            $this->sinkronTrip((string) $idTrip);
        }
    }

    public function hapusUntukTrip(string $idTrip): void
    {
        $this->repo->hapusUntukTrip($idTrip);
    }

    public function feeSaya(string $idPengguna, ?string $bulan): array
    {
        $supir = $this->supirRepo->findByPengguna($idPengguna);
        if ($supir === null) {
            abort(404, 'Fee on call hanya untuk supir internal');
        }

        $awal  = $bulan !== null ? Carbon::createFromFormat('!Y-m', $bulan) : Carbon::now()->startOfMonth();
        $dari  = $awal->copy()->startOfMonth()->toDateString();
        $sampai = $awal->copy()->endOfMonth()->toDateString();

        $item = array_map(static fn ($f) => [
            'id_fee_supir_trip' => (string) $f->id_fee_supir_trip,
            'id_trip'           => (string) $f->id_trip,
            'tanggal'           => substr((string) $f->tanggal, 0, 10),
            'waktu_selesai'     => $f->waktu_checkout,
            'kode_proyek'       => $f->kode_proyek,
            'nama_proyek'       => $f->nama_proyek,
            'nama_rute'         => $f->nama_rute,
            'asal'              => $f->asal,
            'tujuan'            => $f->tujuan,
            'nopol'             => $f->nopol,
            'nominal'           => (float) $f->nominal,
        ], $this->repo->daftarUntukSupir((string) $supir->id_supir, $dari, $sampai));

        return [
            'bulan'       => $awal->format('Y-m'),
            'jumlah_trip' => count($item),
            'total'       => round(array_sum(array_column($item, 'nominal')), 2),
            'item'        => $item,
        ];
    }

    public function ringkasan(string $idPerusahaan, array $filter): array
    {
        [$dari, $sampai] = $this->rentang($filter['dari'], $filter['sampai']);
        $baris = $this->repo->ringkasan($idPerusahaan, $dari, $sampai, $filter['search'], $filter['id_supir']);

        return [
            'dari'         => $dari,
            'sampai'       => $sampai,
            'total'        => round((float) $baris->total, 2),
            'jumlah_trip'  => (int) $baris->jumlah_trip,
            'jumlah_supir' => (int) $baris->jumlah_supir,
        ];
    }

    public function rekap(string $idPerusahaan, array $filter, int $page, int $limit): array
    {
        [$dari, $sampai] = $this->rentang($filter['dari'], $filter['sampai']);
        $hasil = $this->repo->paginateRekap($idPerusahaan, $dari, $sampai, $filter['search'], $filter['id_supir'], $page, $limit);

        return ['data' => array_map(fn ($r) => $this->barisRekap($r), $hasil->items()), 'meta' => $this->meta($hasil)];
    }

    public function rincian(string $idPerusahaan, array $filter, int $page, int $limit): array
    {
        [$dari, $sampai] = $this->rentang($filter['dari'], $filter['sampai']);
        $hasil = $this->repo->paginateRincian($idPerusahaan, $dari, $sampai, $filter['search'], $filter['id_supir'], $page, $limit);

        return ['data' => array_map(fn ($r) => $this->barisRincian($r), $hasil->items()), 'meta' => $this->meta($hasil)];
    }

    public function dataExport(string $idPerusahaan, array $filter): array
    {
        [$dari, $sampai] = $this->rentang($filter['dari'], $filter['sampai']);
        $rincian = PembacaImpor::batasiExport(
            $this->repo->semuaRincian($idPerusahaan, $dari, $sampai, $filter['search'], $filter['id_supir'], PembacaImpor::MAKS_EXPORT + 1),
            PembacaImpor::MAKS_EXPORT,
            'fee supir',
        );

        return [
            'dari'    => $dari,
            'sampai'  => $sampai,
            'search'  => $filter['search'],
            'rekap'   => array_map(fn ($r) => $this->barisRekap($r), $this->repo->semuaRekap($idPerusahaan, $dari, $sampai, $filter['search'], $filter['id_supir'])),
            'rincian' => array_map(fn ($r) => $this->barisRincian($r), $rincian),
        ];
    }

    private function barisRekap(object $r): array
    {
        return [
            'id_supir'         => (string) $r->id_supir,
            'nama_supir'       => $r->nama_supir,
            'telepon'          => $r->telepon,
            'jumlah_trip'      => (int) $r->jumlah_trip,
            'total'            => round((float) $r->total, 2),
            'tanggal_terakhir' => $r->tanggal_terakhir !== null ? substr((string) $r->tanggal_terakhir, 0, 10) : null,
        ];
    }

    private function barisRincian(object $f): array
    {
        return [
            'id_fee_supir_trip' => (string) $f->id_fee_supir_trip,
            'id_trip'           => (string) $f->id_trip,
            'id_supir'          => (string) $f->id_supir,
            'nama_supir'        => $f->nama_supir,
            'tanggal'           => substr((string) $f->tanggal, 0, 10),
            'waktu_selesai'     => $f->waktu_checkout,
            'kode_proyek'       => $f->kode_proyek,
            'nama_proyek'       => $f->nama_proyek,
            'nama_rute'         => $f->nama_rute,
            'asal'              => $f->asal,
            'tujuan'            => $f->tujuan,
            'nopol'             => $f->nopol,
            'nominal'           => (float) $f->nominal,
        ];
    }

    private function meta(LengthAwarePaginator $hasil): array
    {
        return [
            'page'       => $hasil->currentPage(),
            'limit'      => $hasil->perPage(),
            'total'      => $hasil->total(),
            'totalPages' => $hasil->lastPage(),
        ];
    }

    private function rentang(?string $dari, ?string $sampai): array
    {
        $mulai = Carbon::parse($dari ?: now()->startOfMonth()->toDateString());
        $akhir = Carbon::parse($sampai ?: now()->endOfMonth()->toDateString());

        if ($mulai->gt($akhir)) {
            abort(422, 'Tanggal dari tidak boleh melebihi tanggal sampai');
        }
        if ($mulai->diffInDays($akhir) > self::RENTANG_MAKS) {
            abort(422, 'Rentang tanggal maksimal ' . self::RENTANG_MAKS . ' hari');
        }

        return [$mulai->toDateString(), $akhir->toDateString()];
    }

    private function berubah(object $ada, array $data): bool
    {
        return abs((float) $ada->nominal - $data['nominal']) > 0.004
            || (string) $ada->id_proyek !== $data['id_proyek']
            || ($ada->id_rute !== null ? (string) $ada->id_rute : null) !== $data['id_rute']
            || substr((string) $ada->tanggal, 0, 10) !== $data['tanggal'];
    }

    public function urutanTrip(array $data, string $idPerusahaan): array
    {
        $proyek = $this->repo->proyekMilikPerusahaan((string) $data['id_proyek'], $idPerusahaan);
        if ($proyek === null) {
            abort(404, 'Proyek tidak ditemukan');
        }

        if (!TipeHarga::perRit($proyek->tipe_harga)) {
            return ['on_call' => false, 'trip_ke' => null];
        }

        return [
            'on_call' => true,
            'trip_ke' => $this->repo->jumlahPenugasanSupirSebelum(
                (string) $data['id_supir'],
                (string) $data['id_proyek'],
                (string) $data['tanggal'],
                $data['id_penugasan'] ?? null,
            ) + 1,
        ];
    }

    private function nominalFee(object $info): float
    {
        $fee = $info->fee_supir !== null ? (float) $info->fee_supir : 0.0;

        return $fee > 0 ? round($fee, 2) : 0.0;
    }

    private function tanggalFee(object $info): string
    {
        if (!empty($info->tanggal_tugas)) {
            return substr((string) $info->tanggal_tugas, 0, 10);
        }

        return !empty($info->waktu_checkout)
            ? Carbon::parse((string) $info->waktu_checkout)->toDateString()
            : now()->toDateString();
    }
}
