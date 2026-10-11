<?php

declare(strict_types=1);

namespace App\Modules\LaporanPemasukan;

use App\Modules\ArusKas\ArusKasService;
use App\Modules\LaporanPemasukan\Contracts\LaporanPemasukanRepositoryInterface;
use App\Support\Exports\SheetTabelTautan;
use App\Support\PenyimpananBerkas;
use Carbon\Carbon;

class LaporanPemasukanService
{
    public const KATEGORI_INVOICE = 'pembayaran_invoice';
    public const LABEL_INVOICE    = 'Pembayaran Invoice Klien';

    private const RENTANG_MAKS = 366;

    public function __construct(private readonly LaporanPemasukanRepositoryInterface $repo) {}

    public function laporan(string $idPerusahaan, array $filter, int $page, int $limit): array
    {
        [$dari, $sampai] = $this->rentang($filter['dari'] ?? null, $filter['sampai'] ?? null);

        $halaman = $this->repo->paginate($idPerusahaan, $dari, $sampai, $filter, $page, $limit);

        return $this->rekap($idPerusahaan, $dari, $sampai, $filter) + [
            'data' => array_map(fn (object $baris) => $this->susunBaris($baris), $halaman->items()),
            'meta' => [
                'page'       => $halaman->currentPage(),
                'limit'      => $halaman->perPage(),
                'total'      => $halaman->total(),
                'totalPages' => $halaman->lastPage(),
            ],
        ];
    }

    public function untukExport(string $idPerusahaan, array $filter): array
    {
        [$dari, $sampai] = $this->rentang($filter['dari'] ?? null, $filter['sampai'] ?? null);
        $rekap = $this->rekap($idPerusahaan, $dari, $sampai, $filter);

        SheetTabelTautan::pastikanBisaDiexport($rekap['rincian']['jumlah']);

        return $rekap + [
            'saringan' => $this->keteranganSaringan($filter),
            'data'     => $this->repo->semua($idPerusahaan, $dari, $sampai, $filter)
                ->map(fn (object $baris) => $this->susunBaris($baris))
                ->all(),
        ];
    }

    public function opsiKategori(): array
    {
        $opsi = [['value' => self::KATEGORI_INVOICE, 'label' => self::LABEL_INVOICE]];
        foreach (ArusKasService::daftarLabelKategoriPemasukan() as $value => $label) {
            $opsi[] = ['value' => $value, 'label' => $this->labelKategori('manual', $value)];
        }

        return $opsi;
    }

    private function keteranganSaringan(array $filter): string
    {
        $bagian = [];

        $kategori = (string) ($filter['kategori'] ?? '');
        if ($kategori !== '') {
            $bagian[] = 'Kategori: ' . $this->labelKategori('manual', $kategori);
        }

        $search = (string) ($filter['search'] ?? '');
        if ($search !== '') {
            $bagian[] = 'Pencarian: "' . $search . '"';
        }

        return implode(' · ', $bagian);
    }

    private function rekap(string $idPerusahaan, string $dari, string $sampai, array $filter): array
    {
        $ringkasan = [
            'total_pemasukan'  => 0.0,
            'dari_invoice'     => 0.0,
            'pemasukan_lain'   => 0.0,
            'potongan_klien'   => 0.0,
            'jumlah_transaksi' => 0,
        ];

        $perKategori = [];
        foreach ($this->repo->rekapKategori($idPerusahaan, $dari, $sampai, []) as $satu) {
            $total   = round((float) $satu->total, 2);
            $invoice = $satu->sumber === 'invoice';

            $ringkasan['total_pemasukan']  += $total;
            $ringkasan['jumlah_transaksi'] += (int) $satu->jumlah;
            $ringkasan['potongan_klien']   += (float) $satu->potongan;
            $ringkasan[$invoice ? 'dari_invoice' : 'pemasukan_lain'] += $total;

            $perKategori[] = [
                'kategori' => $satu->kategori,
                'label'    => $this->labelKategori((string) $satu->sumber, (string) $satu->kategori),
                'jumlah'   => (int) $satu->jumlah,
                'total'    => $total,
                'potongan' => round((float) $satu->potongan, 2),
            ];
        }

        usort($perKategori, static fn (array $a, array $b) => $b['total'] <=> $a['total']);

        foreach (['total_pemasukan', 'dari_invoice', 'pemasukan_lain', 'potongan_klien'] as $kunci) {
            $ringkasan[$kunci] = round($ringkasan[$kunci], 2);
        }

        $terbit = $this->repo->invoiceTerbit($idPerusahaan, $dari, $sampai);
        $ringkasan['invoice_terbit'] = [
            'jumlah' => (int) $terbit->jumlah,
            'total'  => round((float) $terbit->total, 2),
        ];

        $rincian = ['jumlah' => 0, 'total' => 0.0, 'potongan' => 0.0];
        foreach ($this->repo->rekapKategori($idPerusahaan, $dari, $sampai, $filter) as $satu) {
            $rincian['jumlah']   += (int) $satu->jumlah;
            $rincian['total']    += (float) $satu->total;
            $rincian['potongan'] += (float) $satu->potongan;
        }
        $rincian['total']    = round($rincian['total'], 2);
        $rincian['potongan'] = round($rincian['potongan'], 2);

        return [
            'periode'      => ['dari' => $dari, 'sampai' => $sampai],
            'ringkasan'    => $ringkasan,
            'per_kategori' => $perKategori,
            'rincian'      => $rincian,
        ];
    }

    private function susunBaris(object $baris): array
    {
        $dokumen = $this->dokumen($baris);

        return [
            'tanggal'             => substr((string) $baris->tanggal, 0, 10),
            'nomor'               => $baris->nomor,
            'kategori'            => $baris->kategori,
            'label_kategori'      => $this->labelKategori((string) $baris->sumber, (string) $baris->kategori),
            'pihak'               => $baris->pihak,
            'keterangan'          => $baris->keterangan,
            'no_referensi'        => $baris->no_referensi,
            'nominal'             => round((float) $baris->nominal, 2),
            'potongan'            => round((float) $baris->potongan, 2),
            'keterangan_potongan' => $baris->keterangan_potongan,
            'oleh'                => $baris->oleh,
            'url_bukti'           => PenyimpananBerkas::url($baris->url_bukti),
            'label_dokumen'       => $dokumen['label'],
            'link_dokumen'        => $dokumen['path'],
        ];
    }

    /** @return array{label: string, path: string} */
    private function dokumen(object $baris): array
    {
        if ($baris->sumber === 'invoice') {
            return ['label' => 'Invoice', 'path' => '/faktur/' . rawurlencode((string) $baris->id_dokumen)];
        }

        $idKasbon = trim((string) ($baris->id_kasbon ?? ''));
        if ($idKasbon !== '') {
            return ['label' => 'Kasbon', 'path' => '/kasbon/' . rawurlencode($idKasbon)];
        }

        return ['label' => 'Pemasukan Manual', 'path' => '/arus-kas?tab=pemasukan'];
    }

    private function labelKategori(string $sumber, string $kategori): string
    {
        return $sumber === 'invoice' || $kategori === self::KATEGORI_INVOICE
            ? self::LABEL_INVOICE
            : ArusKasService::labelKategoriPemasukan($kategori);
    }

    /** @return array{0: string, 1: string} */
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
}
