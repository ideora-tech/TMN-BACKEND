<?php

declare(strict_types=1);

namespace App\Modules\LaporanPengeluaran;

use App\Modules\ArusKas\ArusKasService;
use App\Modules\KasKecil\KasKecilService;
use App\Modules\LaporanPengeluaran\Contracts\LaporanPengeluaranRepositoryInterface;
use App\Support\Exports\SheetTabelTautan;
use App\Support\PenyimpananBerkas;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LaporanPengeluaranService
{
    public const SUMBER_REKENING  = 'rekening';
    public const SUMBER_KAS_KECIL = 'kas_kecil';

    public const LABEL_SUMBER_DANA = [
        self::SUMBER_REKENING  => 'Rekening',
        self::SUMBER_KAS_KECIL => 'Kas Kecil',
    ];

    public const KATEGORI_KAS_KECIL = 'kas_kecil';

    private const RENTANG_MAKS = 366;

    private const AWALAN_SUB_KAS_KECIL = self::KATEGORI_KAS_KECIL . ':';

    private const DOKUMEN = [
        'uang_jalan'           => ['label' => 'Uang Jalan',          'path' => '/uang-jalan/%s'],
        'kasbon'               => ['label' => 'Kasbon',              'path' => '/kasbon/%s'],
        'pengisian_kas_kecil'  => ['label' => 'Pengisian Kas Kecil', 'path' => '/kas-kecil/pengisian/%s'],
        'permintaan_pembelian' => ['label' => 'Permintaan Pembelian', 'path' => '/permintaan-pembelian/%s'],
        'pembelian_sparepart'  => ['label' => 'Pembelian Sparepart', 'path' => '/pembelian-sparepart/%s'],
        'payroll'              => ['label' => 'Payroll',             'path' => '/payroll/%s'],
        'invoice_vendor'       => ['label' => 'Invoice Vendor',      'path' => '/invoice-vendor/%s'],
    ];

    public function __construct(private readonly LaporanPengeluaranRepositoryInterface $repo) {}

    public function laporan(string $idPerusahaan, array $filter, int $page, int $limit): array
    {
        [$dari, $sampai] = $this->rentang($filter['dari'] ?? null, $filter['sampai'] ?? null);
        $filter = $this->rapikanFilter($filter);

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
        $saringan = $this->keteranganSaringan($filter);
        $filter   = $this->rapikanFilter($filter);
        $rekap    = $this->rekap($idPerusahaan, $dari, $sampai, $filter);

        SheetTabelTautan::pastikanBisaDiexport($rekap['rincian']['jumlah']);

        return $rekap + [
            'saringan_ringkasan' => $saringan['ringkasan'],
            'saringan'           => $saringan['rincian'],
            'data'               => $this->repo->semua($idPerusahaan, $dari, $sampai, $filter)
                ->map(fn (object $baris) => $this->susunBaris($baris))
                ->all(),
        ];
    }

    public function opsiKategori(): array
    {
        $opsi = [];
        foreach (ArusKasService::daftarLabelKategori() as $value => $label) {
            $opsi[] = ['value' => $value, 'label' => $label];
        }
        $opsi[] = ['value' => self::KATEGORI_KAS_KECIL, 'label' => 'Kas Kecil — semua kategori'];
        foreach (KasKecilService::KATEGORI as $value => $label) {
            $opsi[] = ['value' => self::AWALAN_SUB_KAS_KECIL . $value, 'label' => 'Kas Kecil — ' . $label];
        }

        return $opsi;
    }

    /** @return array{ringkasan: string, rincian: string} */
    private function keteranganSaringan(array $filter): array
    {
        $ringkasan = [];
        $rincian   = [];

        $kategori = (string) ($filter['kategori'] ?? '');
        if ($kategori !== '') {
            $opsi      = collect($this->opsiKategori())->firstWhere('value', $kategori);
            $rincian[] = 'Kategori: ' . ($opsi['label'] ?? $kategori);
        }

        $sumberDana = (string) ($filter['sumber_dana'] ?? '');
        if ($sumberDana !== '') {
            $teks        = 'Sumber dana: ' . (self::LABEL_SUMBER_DANA[$sumberDana] ?? $sumberDana);
            $ringkasan[] = $teks;
            $rincian[]   = $teks;
        }

        $search = (string) ($filter['search'] ?? '');
        if ($search !== '') {
            $rincian[] = 'Pencarian: "' . $search . '"';
        }

        return ['ringkasan' => implode(' · ', $ringkasan), 'rincian' => implode(' · ', $rincian)];
    }

    private function rapikanFilter(array $filter): array
    {
        $kategori = (string) ($filter['kategori'] ?? '');

        if (str_starts_with($kategori, self::AWALAN_SUB_KAS_KECIL)) {
            $filter['kategori']     = self::KATEGORI_KAS_KECIL;
            $filter['sub_kategori'] = substr($kategori, strlen(self::AWALAN_SUB_KAS_KECIL));
        } else {
            $filter['sub_kategori'] = null;
        }

        return $filter;
    }

    private function rekap(string $idPerusahaan, string $dari, string $sampai, array $filter): array
    {
        $kelompok = $this->repo->rekapKategori($idPerusahaan, $dari, $sampai, [
            'sumber_dana' => $filter['sumber_dana'] ?? null,
        ]);

        $ringkasan = [
            'total_pengeluaran' => 0.0,
            'dari_rekening'     => 0.0,
            'dari_kas_kecil'    => 0.0,
            'mutasi_kas_kecil'  => 0.0,
            'jumlah_transaksi'  => 0,
        ];

        $perKategori = [];
        foreach ($kelompok as $satu) {
            $total  = round((float) $satu->total, 2);
            $jumlah = (int) $satu->jumlah;
            $mutasi = $this->mutasi((string) $satu->kategori);

            if ($mutasi) {
                $ringkasan['mutasi_kas_kecil'] += $total;
            } else {
                $ringkasan['jumlah_transaksi'] += $jumlah;
                $ringkasan['total_pengeluaran'] += $total;
                $kunci = $satu->sumber_dana === self::SUMBER_KAS_KECIL ? 'dari_kas_kecil' : 'dari_rekening';
                $ringkasan[$kunci] += $total;
            }

            $perKategori[] = [
                'kategori'          => $satu->kategori,
                'sub_kategori'      => $satu->sub_kategori,
                'label'             => $this->labelKategori((string) $satu->kategori, $satu->sub_kategori),
                'nilai_filter'      => $satu->kategori === self::KATEGORI_KAS_KECIL && $satu->sub_kategori !== null
                    ? self::AWALAN_SUB_KAS_KECIL . $satu->sub_kategori
                    : (string) $satu->kategori,
                'sumber_dana'       => $satu->sumber_dana,
                'label_sumber_dana' => self::LABEL_SUMBER_DANA[$satu->sumber_dana] ?? $satu->sumber_dana,
                'jumlah'            => $jumlah,
                'total'             => $total,
                'mutasi'            => $mutasi,
            ];
        }

        usort($perKategori, static fn (array $a, array $b) => [$a['mutasi'], $b['total']] <=> [$b['mutasi'], $a['total']]);

        foreach (['total_pengeluaran', 'dari_rekening', 'dari_kas_kecil', 'mutasi_kas_kecil'] as $kunci) {
            $ringkasan[$kunci] = round($ringkasan[$kunci], 2);
        }

        return [
            'periode'      => ['dari' => $dari, 'sampai' => $sampai],
            'ringkasan'    => $ringkasan,
            'per_kategori' => $perKategori,
            'rincian'      => $this->totalRincian($idPerusahaan, $dari, $sampai, $filter),
        ];
    }

    /** @return array{jumlah: int, total: float, mutasi: float} */
    private function totalRincian(string $idPerusahaan, string $dari, string $sampai, array $filter): array
    {
        $hasil = ['jumlah' => 0, 'total' => 0.0, 'mutasi' => 0.0];

        foreach ($this->repo->rekapKategori($idPerusahaan, $dari, $sampai, $filter) as $satu) {
            $hasil['jumlah'] += (int) $satu->jumlah;
            $kunci = $this->mutasi((string) $satu->kategori) ? 'mutasi' : 'total';
            $hasil[$kunci] += (float) $satu->total;
        }

        $hasil['total']  = round($hasil['total'], 2);
        $hasil['mutasi'] = round($hasil['mutasi'], 2);

        return $hasil;
    }

    private function susunBaris(object $baris): array
    {
        $kategori = (string) $baris->kategori;
        $dokumen  = $this->dokumen($baris);

        return [
            'tanggal'           => substr((string) $baris->tanggal, 0, 10),
            'nomor'             => $baris->nomor,
            'kategori'          => $kategori,
            'sub_kategori'      => $baris->sub_kategori,
            'label_kategori'    => $this->labelKategori($kategori, $baris->sub_kategori),
            'sumber_dana'       => $baris->sumber_dana,
            'label_sumber_dana' => self::LABEL_SUMBER_DANA[$baris->sumber_dana] ?? $baris->sumber_dana,
            'penerima'          => $baris->penerima,
            'keterangan'        => $baris->keterangan,
            'nominal'           => round((float) $baris->nominal, 2),
            'mutasi'            => $this->mutasi($kategori),
            'oleh'              => $baris->oleh,
            'url_bukti'         => PenyimpananBerkas::url($baris->url_bukti),
            'label_dokumen'     => $dokumen['label'],
            'link_dokumen'      => $dokumen['path'],
        ];
    }

    /** @return array{label: string, path: string} */
    private function dokumen(object $baris): array
    {
        $tipe = (string) $baris->tipe_dokumen;

        if (isset(self::DOKUMEN[$tipe])) {
            return [
                'label' => self::DOKUMEN[$tipe]['label'],
                'path'  => sprintf(self::DOKUMEN[$tipe]['path'], rawurlencode((string) $baris->id_dokumen)),
            ];
        }

        if ($tipe === 'perawatan') {
            $idArmada = trim((string) ($baris->id_pendukung ?? ''));

            return [
                'label' => 'Perawatan Armada',
                'path'  => $idArmada !== ''
                    ? '/perawatan-armada?armada=' . rawurlencode($idArmada) . '&detail=' . rawurlencode((string) $baris->id_dokumen)
                    : '/perawatan-armada',
            ];
        }

        if ($tipe === 'kas_kecil') {
            return [
                'label' => 'Buku Kas Kecil',
                'path'  => '/kas-kecil?tab=buku&tanggal=' . substr((string) $baris->tanggal, 0, 10) . '&cari=' . rawurlencode((string) $baris->nomor),
            ];
        }

        return ['label' => 'Proses Pembayaran', 'path' => '/proses-pembayaran?tab=ditransfer'];
    }

    private function labelKategori(string $kategori, ?string $subKategori): string
    {
        if ($kategori === self::KATEGORI_KAS_KECIL) {
            return 'Kas Kecil — ' . (KasKecilService::labelKategori($subKategori) ?? 'Tanpa kategori');
        }

        return ArusKasService::labelKategori($kategori);
    }

    private function mutasi(string $kategori): bool
    {
        return $kategori === ArusKasService::KATEGORI_PENGISIAN_KAS_KECIL;
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
