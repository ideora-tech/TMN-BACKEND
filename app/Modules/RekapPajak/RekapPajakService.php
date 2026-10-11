<?php

declare(strict_types=1);

namespace App\Modules\RekapPajak;

use App\Modules\RekapPajak\Contracts\RekapPajakRepositoryInterface;
use Carbon\Carbon;

class RekapPajakService
{
    public const PPN_KELUARAN       = 'ppn_keluaran';
    public const PPN_MASUKAN        = 'ppn_masukan';
    public const PPH_VENDOR         = 'pph_vendor';
    public const PPH21              = 'pph21';
    public const PPH_DIPOTONG_KLIEN = 'pph_dipotong_klien';
    public const PAJAK_LAIN         = 'pajak_lain';

    public const LABEL_JENIS = [
        self::PPN_KELUARAN       => 'PPN Keluaran',
        self::PPN_MASUKAN        => 'PPN Masukan',
        self::PPH_VENDOR         => 'PPh Dipotong dari Vendor',
        self::PPH21              => 'PPh 21 Karyawan',
        self::PPH_DIPOTONG_KLIEN => 'PPh Dipotong oleh Klien',
        self::PAJAK_LAIN         => 'Pajak Lain di Invoice',
    ];

    private const MASA_MAKS = 12;

    public function __construct(private readonly RekapPajakRepositoryInterface $repo) {}

    public function rekap(string $idPerusahaan, ?string $dari, ?string $sampai): array
    {
        [$mulai, $akhir] = $this->masa($dari, $sampai);
        $tglDari   = $mulai->toDateString();
        $tglSampai = $akhir->toDateString();

        $faktur   = $this->barisFaktur($idPerusahaan, $tglDari, $tglSampai);
        $potongan = $this->barisPotonganKlien($idPerusahaan, $tglDari, $tglSampai);

        $baris = [
            self::PPN_KELUARAN       => $faktur['ppn'],
            self::PPN_MASUKAN        => $this->barisPpnMasukan($idPerusahaan, $tglDari, $tglSampai),
            self::PPH_VENDOR         => $this->barisPphVendor($idPerusahaan, $tglDari, $tglSampai),
            self::PPH21              => $this->barisPph21($idPerusahaan, $tglDari, $tglSampai),
            self::PPH_DIPOTONG_KLIEN => $potongan['pph'],
            self::PAJAK_LAIN         => $faktur['lain'],
        ];

        $ringkasan = [];
        foreach ($baris as $jenis => $daftar) {
            $ringkasan[$jenis] = $this->total($daftar);
        }
        $ringkasan['ppn_kurang_bayar']    = round($ringkasan[self::PPN_KELUARAN]['pajak'] - $ringkasan[self::PPN_MASUKAN]['pajak'], 2);
        $ringkasan['invoice_tanpa_pajak'] = $faktur['tanpa'];
        $ringkasan['potongan_lain_klien'] = $potongan['lain'];

        return [
            'periode'   => [
                'dari'           => $mulai->format('Y-m'),
                'sampai'         => $akhir->format('Y-m'),
                'tanggal_dari'   => $tglDari,
                'tanggal_sampai' => $tglSampai,
                'label'          => $this->labelPeriode($mulai, $akhir),
            ],
            'ringkasan' => $ringkasan,
            'per_masa'  => $this->perMasa($baris, $mulai, $akhir),
            'baris'     => $baris,
        ];
    }

    public function rincian(string $idPerusahaan, string $jenis, ?string $dari, ?string $sampai, ?string $search, int $page, int $limit): array
    {
        [$mulai, $akhir] = $this->masa($dari, $sampai);

        $baris = $this->saring($this->barisJenis($idPerusahaan, $jenis, $mulai->toDateString(), $akhir->toDateString()), $search);
        $total = count($baris);

        return [
            'jenis'  => $jenis,
            'label'  => self::LABEL_JENIS[$jenis],
            'total'  => $this->total($baris),
            'data'   => array_slice(array_reverse($baris), ($page - 1) * $limit, $limit),
            'meta'   => [
                'page'       => $page,
                'limit'      => $limit,
                'total'      => $total,
                'totalPages' => max(1, (int) ceil($total / $limit)),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function barisJenis(string $idPerusahaan, string $jenis, string $dari, string $sampai): array
    {
        return match ($jenis) {
            self::PPN_KELUARAN       => $this->barisFaktur($idPerusahaan, $dari, $sampai)['ppn'],
            self::PAJAK_LAIN         => $this->barisFaktur($idPerusahaan, $dari, $sampai)['lain'],
            self::PPN_MASUKAN        => $this->barisPpnMasukan($idPerusahaan, $dari, $sampai),
            self::PPH_VENDOR         => $this->barisPphVendor($idPerusahaan, $dari, $sampai),
            self::PPH21              => $this->barisPph21($idPerusahaan, $dari, $sampai),
            self::PPH_DIPOTONG_KLIEN => $this->barisPotonganKlien($idPerusahaan, $dari, $sampai)['pph'],
        };
    }

    /** @return array{ppn: list<array<string, mixed>>, lain: list<array<string, mixed>>, tanpa: array{jumlah: int, dpp: float}} */
    private function barisFaktur(string $idPerusahaan, string $dari, string $sampai): array
    {
        $dpp   = $this->repo->dppFaktur($idPerusahaan, $dari, $sampai);
        $pajak = $this->repo->pajakFaktur($idPerusahaan, $dari, $sampai);

        $hasil = ['ppn' => [], 'lain' => [], 'tanpa' => ['jumlah' => 0, 'dpp' => 0.0]];

        foreach ($this->repo->fakturKlien($idPerusahaan, $dari, $sampai) as $faktur) {
            $nilaiDpp = round((float) ($dpp[$faktur->id_faktur] ?? 0), 2);

            $daftar = [];
            if (isset($pajak[$faktur->id_faktur])) {
                foreach ($pajak[$faktur->id_faktur] as $satu) {
                    $daftar[] = ['nama' => $satu->nama, 'persen' => (float) $satu->persen];
                }
            } elseif ((float) $faktur->persen_pajak > 0) {
                $daftar[] = ['nama' => $faktur->nama_pajak, 'persen' => (float) $faktur->persen_pajak];
            }
            $daftar = array_values(array_filter($daftar, static fn (array $p) => $p['persen'] > 0));

            if ($daftar === []) {
                $hasil['tanpa']['jumlah']++;
                $hasil['tanpa']['dpp'] = round($hasil['tanpa']['dpp'] + $nilaiDpp, 2);
                continue;
            }

            foreach ($daftar as $satu) {
                $nama  = trim((string) $satu['nama']);
                $kunci = $this->pajakAdalahPpn($nama) ? 'ppn' : 'lain';

                $hasil[$kunci][] = $this->baris(
                    (string) $faktur->tanggal,
                    (string) $faktur->nomor_faktur,
                    $faktur->nama_klien,
                    null,
                    $nama !== '' ? $nama : 'Pajak',
                    $nilaiDpp,
                    $satu['persen'],
                    round($nilaiDpp * $satu['persen'] / 100, 2),
                    'Invoice',
                    '/faktur/' . $faktur->id_faktur,
                );
            }
        }

        return $hasil;
    }

    /** @return list<array<string, mixed>> */
    private function barisPpnMasukan(string $idPerusahaan, string $dari, string $sampai): array
    {
        $baris = [];

        foreach ($this->repo->ppnInvoiceVendor($idPerusahaan, $dari, $sampai) as $iv) {
            $baris[] = $this->baris(
                (string) $iv->tanggal, (string) $iv->nomor_invoice, $iv->nama_vendor, $iv->npwp, null,
                (float) $iv->dpp, $this->tarif((float) $iv->ppn, (float) $iv->dpp), (float) $iv->ppn,
                'Invoice Vendor', '/invoice-vendor/' . $iv->id_invoice_vendor,
            );
        }

        foreach ($this->repo->ppnPermintaanPembelian($idPerusahaan, $dari, $sampai) as $pr) {
            $dpp = max(0.0, (float) $pr->total_aktual - (float) $pr->ppn - (float) $pr->ongkir);
            $baris[] = $this->baris(
                (string) $pr->tanggal,
                (string) ($pr->nomor_po ?: $pr->nomor_permintaan),
                $pr->nama_supplier ?: $pr->nama_toko,
                null,
                $pr->nomor_po ? 'PR ' . $pr->nomor_permintaan : null,
                $dpp,
                (float) $pr->ppn_persen > 0 ? (float) $pr->ppn_persen : $this->tarif((float) $pr->ppn, $dpp),
                (float) $pr->ppn,
                'Permintaan Pembelian',
                '/permintaan-pembelian/' . $pr->id_permintaan,
            );
        }

        foreach ($this->repo->ppnPembelianSparepart($idPerusahaan, $dari, $sampai) as $ps) {
            $dpp = max(0.0, (float) $ps->total_aktual - (float) $ps->ppn - (float) $ps->ongkir);
            $baris[] = $this->baris(
                (string) $ps->tanggal, (string) $ps->nomor_pengajuan, $ps->nama_supplier, null, null,
                $dpp,
                (float) $ps->ppn_persen > 0 ? (float) $ps->ppn_persen : $this->tarif((float) $ps->ppn, $dpp),
                (float) $ps->ppn,
                'Pembelian Sparepart',
                '/pembelian-sparepart/' . $ps->id_pembelian,
            );
        }

        usort($baris, static fn (array $a, array $b) => [$a['tanggal'], $a['nomor']] <=> [$b['tanggal'], $b['nomor']]);

        return $baris;
    }

    /** @return list<array<string, mixed>> */
    private function barisPphVendor(string $idPerusahaan, string $dari, string $sampai): array
    {
        $baris = [];
        foreach ($this->repo->pphInvoiceVendor($idPerusahaan, $dari, $sampai) as $iv) {
            $baris[] = $this->baris(
                (string) $iv->tanggal, (string) $iv->nomor_invoice, $iv->nama_vendor, $iv->npwp, null,
                (float) $iv->dpp, $this->tarif((float) $iv->pph, (float) $iv->dpp), (float) $iv->pph,
                'Invoice Vendor', '/invoice-vendor/' . $iv->id_invoice_vendor,
            );
        }

        return $baris;
    }

    /** @return list<array<string, mixed>> */
    private function barisPph21(string $idPerusahaan, string $dari, string $sampai): array
    {
        $baris = [];
        foreach ($this->repo->pph21($idPerusahaan, $dari, $sampai) as $slip) {
            $keterangan = array_filter([
                trim((string) $slip->nik_ktp) !== '' ? 'NIK ' . $slip->nik_ktp : null,
                trim((string) $slip->status_ptkp) !== '' ? 'PTKP ' . $slip->status_ptkp : null,
            ]);

            $baris[] = $this->baris(
                (string) $slip->tanggal, (string) $slip->nama_periode, $slip->nama_karyawan, $slip->npwp,
                $keterangan !== [] ? implode(' · ', $keterangan) : null,
                (float) $slip->total_bruto, $this->tarif((float) $slip->pph21, (float) $slip->total_bruto), (float) $slip->pph21,
                'Payroll', '/payroll/' . $slip->id_periode,
            );
        }

        return $baris;
    }

    /** @return array{pph: list<array<string, mixed>>, lain: array{jumlah: int, nominal: float}} */
    private function barisPotonganKlien(string $idPerusahaan, string $dari, string $sampai): array
    {
        $hasil = ['pph' => [], 'lain' => ['jumlah' => 0, 'nominal' => 0.0]];

        foreach ($this->repo->potonganKlien($idPerusahaan, $dari, $sampai) as $bayar) {
            $keterangan = trim((string) $bayar->keterangan_potongan);

            if (!str_contains(mb_strtolower($keterangan), 'pph')) {
                $hasil['lain']['jumlah']++;
                $hasil['lain']['nominal'] = round($hasil['lain']['nominal'] + (float) $bayar->potongan, 2);
                continue;
            }

            $hasil['pph'][] = $this->baris(
                (string) $bayar->tanggal, (string) $bayar->nomor_faktur, $bayar->nama_klien, null, $keterangan,
                (float) $bayar->nominal, null, (float) $bayar->potongan,
                'Pembayaran Invoice', '/faktur/' . $bayar->id_faktur,
            );
        }

        return $hasil;
    }

    /** @return array<string, mixed> */
    private function baris(
        string $tanggal,
        string $nomor,
        ?string $pihak,
        ?string $npwp,
        ?string $keterangan,
        float $dpp,
        ?float $tarif,
        float $pajak,
        string $sumber,
        string $link,
    ): array {
        $tanggal = substr($tanggal, 0, 10);
        $npwp    = trim((string) $npwp);

        return [
            'tanggal'    => $tanggal,
            'masa'       => substr($tanggal, 0, 7),
            'nomor'      => $nomor,
            'pihak'      => $pihak,
            'npwp'       => $npwp !== '' ? $npwp : null,
            'keterangan' => $keterangan,
            'dpp'        => round($dpp, 2),
            'tarif'      => $tarif,
            'pajak'      => round($pajak, 2),
            'sumber'     => $sumber,
            'link'       => $link,
        ];
    }

    /**
     * @param list<array<string, mixed>> $baris
     * @return array{dpp: float, pajak: float, jumlah: int}
     */
    private function total(array $baris): array
    {
        return [
            'dpp'    => round(array_sum(array_column($baris, 'dpp')), 2),
            'pajak'  => round(array_sum(array_column($baris, 'pajak')), 2),
            'jumlah' => count($baris),
        ];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $baris
     * @return list<array<string, mixed>>
     */
    private function perMasa(array $baris, Carbon $mulai, Carbon $akhir): array
    {
        $jumlah = [];
        foreach ($baris as $jenis => $daftar) {
            foreach ($daftar as $satu) {
                $jumlah[$satu['masa']][$jenis] = ($jumlah[$satu['masa']][$jenis] ?? 0) + $satu['pajak'];
            }
        }

        $hasil  = [];
        $kursor = $mulai->copy();
        while ($kursor->lte($akhir)) {
            $masa = $kursor->format('Y-m');
            $satu = ['masa' => $masa, 'label' => $kursor->copy()->locale('id')->translatedFormat('F Y')];
            foreach (array_keys(self::LABEL_JENIS) as $jenis) {
                $satu[$jenis] = round((float) ($jumlah[$masa][$jenis] ?? 0), 2);
            }
            $satu['ppn_kurang_bayar'] = round($satu[self::PPN_KELUARAN] - $satu[self::PPN_MASUKAN], 2);

            $hasil[] = $satu;
            $kursor->addMonthNoOverflow();
        }

        return $hasil;
    }

    /**
     * @param list<array<string, mixed>> $baris
     * @return list<array<string, mixed>>
     */
    private function saring(array $baris, ?string $search): array
    {
        $kata = $search !== null ? mb_strtolower(trim($search)) : '';
        if ($kata === '') {
            return $baris;
        }

        return array_values(array_filter($baris, static function (array $satu) use ($kata) {
            $teks = mb_strtolower(implode(' ', [
                (string) $satu['nomor'], (string) ($satu['pihak'] ?? ''), (string) ($satu['npwp'] ?? ''), (string) ($satu['keterangan'] ?? ''),
            ]));

            return str_contains($teks, $kata);
        }));
    }

    private function pajakAdalahPpn(string $nama): bool
    {
        $nama = mb_strtolower($nama);

        return $nama === ''
            || str_contains($nama, 'ppn')
            || str_contains($nama, 'vat')
            || str_contains($nama, 'pertambahan nilai');
    }

    private function tarif(float $pajak, float $dasar): ?float
    {
        return $dasar > 0 ? round($pajak / $dasar * 100, 2) : null;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function masa(?string $dari, ?string $sampai): array
    {
        $mulai = $dari ? Carbon::createFromFormat('!Y-m', $dari) : now()->startOfMonth();
        $akhir = $sampai ? Carbon::createFromFormat('!Y-m', $sampai) : $mulai->copy();

        $mulai = $mulai->copy()->startOfMonth();
        $akhir = $akhir->copy()->endOfMonth();

        if ($mulai->gt($akhir)) {
            abort(422, 'Masa pajak awal tidak boleh melebihi masa pajak akhir');
        }
        if ($mulai->diffInMonths($akhir->copy()->startOfMonth()) >= self::MASA_MAKS) {
            abort(422, 'Rentang masa pajak maksimal ' . self::MASA_MAKS . ' bulan');
        }

        return [$mulai, $akhir];
    }

    private function labelPeriode(Carbon $mulai, Carbon $akhir): string
    {
        $awal  = $mulai->copy()->locale('id')->translatedFormat('F Y');
        $ujung = $akhir->copy()->locale('id')->translatedFormat('F Y');

        return $awal === $ujung ? 'Masa ' . $awal : 'Masa ' . $awal . ' s/d ' . $ujung;
    }
}
