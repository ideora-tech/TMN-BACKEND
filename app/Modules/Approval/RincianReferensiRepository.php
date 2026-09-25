<?php

declare(strict_types=1);

namespace App\Modules\Approval;

use App\Modules\Approval\Contracts\RincianReferensiRepositoryInterface;
use App\Modules\IntervalPerawatan\IntervalLabelBuilder;
use App\Support\LinkReferensiApproval;
use Closure;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class RincianReferensiRepository implements RincianReferensiRepositoryInterface
{
    private const LABEL_MEKANISME = [
        'unit_only'   => 'Unit Only',
        'unit_driver' => 'Unit + Driver',
        'full'        => 'All In',
    ];

    private const LABEL_TIPE_HARGA = [
        'per_rit'     => 'On Call',
        'borongan'    => 'Dedicate',
        'unit_only'   => 'Unit Only',
        'unit_driver' => 'Unit + Driver',
        'all_in'      => 'All In',
    ];

    private const LABEL_TIPE_PERMINTAAN = [
        'umum'      => 'Umum',
        'sparepart' => 'Spare Part',
        'aset'      => 'Aset',
    ];

    public function rincian(string $kode, string $idReferensi, string $idPerusahaan): ?array
    {
        $hasil = in_array($kode, ApprovalRepository::KODE_REFERENSI_PENGELUARAN, true)
            ? $this->rincianPengeluaran($idReferensi, $idPerusahaan)
            : match ($kode) {
                'penawaran'                                         => $this->rincianPenawaran($idReferensi, $idPerusahaan),
                'proyek'                                            => $this->rincianProyek($idReferensi, $idPerusahaan),
                'faktur'                                            => $this->rincianFaktur($idReferensi, $idPerusahaan),
                'invoice_vendor'                                    => $this->rincianInvoiceVendor($idReferensi, $idPerusahaan),
                'kontrak_vendor'                                    => $this->rincianKontrakVendor($idReferensi, $idPerusahaan),
                'permintaan_vendor'                                 => $this->rincianPermintaanVendor($idReferensi, $idPerusahaan),
                'permintaan_pembelian', 'permintaan_pembelian_aset' => $this->rincianPermintaanPembelian($idReferensi, $idPerusahaan),
                default                                             => null,
            };

        if ($hasil === null) {
            return null;
        }

        return [
            'kode'   => $kode,
            'judul'  => $hasil['judul'],
            'info'   => $hasil['info'],
            'bagian' => $hasil['bagian'],
            'link'   => LinkReferensiApproval::untuk($kode, $idReferensi),
        ];
    }

    private function rincianPengeluaran(string $idPengajuan, string $idPerusahaan): ?array
    {
        $pengajuan = DB::table('pengajuan_pengeluaran')
            ->where('id_pengajuan', $idPengajuan)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->first(['nomor_pengajuan', 'kategori', 'penerima', 'nominal', 'tanggal_pengajuan', 'keterangan', 'id_perawatan', 'id_pembelian']);
        if ($pengajuan === null) {
            return null;
        }

        $kategori = (string) $pengajuan->kategori;
        $labelKategori = ucwords(str_replace('_', ' ', $kategori));

        $sumber = match (true) {
            $kategori === 'perawatan' && $pengajuan->id_perawatan !== null => $this->sumberPerawatan((string) $pengajuan->id_perawatan, $idPerusahaan),
            $kategori === 'sparepart' && $pengajuan->id_pembelian !== null => $this->sumberPembelianSparepart((string) $pengajuan->id_pembelian, $idPerusahaan),
            default                                                        => null,
        };

        return [
            'judul'  => $this->gabungJudul($pengajuan->nomor_pengajuan, $this->teksAtauNull($pengajuan->keterangan) ?? $labelKategori),
            'info'   => [
                $this->teks('No. Pengajuan', $pengajuan->nomor_pengajuan),
                $this->teks('Kategori', $labelKategori),
                $this->teks('Penerima', $pengajuan->penerima),
                $this->rupiah('Nominal', $pengajuan->nominal),
                $this->tanggal('Tanggal Pengajuan', $pengajuan->tanggal_pengajuan),
                $this->teks('Keterangan', $pengajuan->keterangan),
                ...($sumber['info'] ?? []),
            ],
            'bagian' => $sumber['bagian'] ?? [],
        ];
    }

    private function sumberPerawatan(string $idPerawatan, string $idPerusahaan): ?array
    {
        $perawatan = DB::table('perawatan_armada as pa')
            ->join('armada as a', function (JoinClause $join) use ($idPerusahaan) {
                $join->on('a.id_armada', '=', 'pa.id_armada')->where('a.id_perusahaan', $idPerusahaan);
            })
            ->leftJoin('supplier as s', $this->milik('s', 's.id_supplier', 'pa.id_supplier', $idPerusahaan))
            ->leftJoin('interval_perawatan as ip', $this->milik('ip', 'ip.id_interval_perawatan', 'pa.id_interval_perawatan', $idPerusahaan))
            ->where('pa.id_perawatan', $idPerawatan)
            ->whereNull('pa.dihapus_pada')
            ->first(['pa.tanggal', 'pa.jenis_perawatan', 'pa.biaya', 'pa.keterangan', 'a.nopol', 'a.merk', 's.nama as nama_supplier', 'ip.interval_km', 'ip.interval_bulan']);
        if ($perawatan === null) {
            return null;
        }

        $paket = IntervalLabelBuilder::buildOrFallback(
            $perawatan->interval_km !== null ? (int) $perawatan->interval_km : null,
            $perawatan->interval_bulan !== null ? (int) $perawatan->interval_bulan : null,
            $this->teksAtauNull($perawatan->jenis_perawatan) ?? 'Perbaikan',
        );

        $sparepart = DB::table('perawatan_sparepart')
            ->where('id_perawatan', $idPerawatan)
            ->whereNull('dihapus_pada')
            ->orderBy('dibuat_pada')
            ->get(['nama_sparepart', 'qty', 'harga']);

        $totalSparepart = 0.0;
        $baris = [];
        foreach ($sparepart as $item) {
            $subtotal = round((int) $item->qty * (float) $item->harga, 2);
            $totalSparepart += $subtotal;
            $baris[] = [
                'nama'     => $item->nama_sparepart,
                'qty'      => (int) $item->qty,
                'harga'    => (float) $item->harga,
                'subtotal' => $subtotal,
            ];
        }

        return [
            'info'   => [
                $this->teks('Armada', $this->gabungJudul($perawatan->nopol, $perawatan->merk)),
                $this->teks('Paket Perawatan', $paket),
                $this->tanggal('Tanggal Perawatan', $perawatan->tanggal),
                $this->teks('Bengkel', $perawatan->nama_supplier),
                $this->rupiah('Biaya Jasa', $perawatan->biaya),
                $this->teks('Keterangan Perawatan', $perawatan->keterangan),
            ],
            'bagian' => $this->bagian('Sparepart Dipakai', [
                $this->kolom('nama', 'Nama'),
                $this->kolom('qty', 'Qty', 'angka'),
                $this->kolom('harga', 'Harga', 'rupiah'),
                $this->kolom('subtotal', 'Subtotal', 'rupiah'),
            ], $baris, (float) $perawatan->biaya + $totalSparepart, 'Total Jasa + Sparepart'),
        ];
    }

    private function sumberPembelianSparepart(string $idPembelian, string $idPerusahaan): ?array
    {
        $pembelian = DB::table('pembelian_sparepart as ps')
            ->leftJoin('supplier as s', $this->milik('s', 's.id_supplier', 'ps.id_supplier', $idPerusahaan))
            ->where('ps.id_pembelian', $idPembelian)
            ->where('ps.id_perusahaan', $idPerusahaan)
            ->whereNull('ps.dihapus_pada')
            ->first(['ps.nomor_pengajuan', 'ps.tanggal_pengajuan', 'ps.total_estimasi', 'ps.keterangan', 's.nama as nama_supplier']);
        if ($pembelian === null) {
            return null;
        }

        $item = DB::table('pembelian_sparepart_item')
            ->where('id_pembelian', $idPembelian)
            ->whereNull('dihapus_pada')
            ->orderBy('dibuat_pada')
            ->get(['nama_sparepart', 'qty', 'harga_estimasi']);

        $total = 0.0;
        $baris = [];
        foreach ($item as $satu) {
            $subtotal = round((int) $satu->qty * (float) $satu->harga_estimasi, 2);
            $total += $subtotal;
            $baris[] = [
                'nama'     => $satu->nama_sparepart,
                'qty'      => (int) $satu->qty,
                'harga'    => (float) $satu->harga_estimasi,
                'subtotal' => $subtotal,
            ];
        }

        return [
            'info'   => [
                $this->teks('No. Pembelian', $pembelian->nomor_pengajuan),
                $this->teks('Supplier', $pembelian->nama_supplier),
                $this->tanggal('Tanggal Pengajuan Pembelian', $pembelian->tanggal_pengajuan),
                $this->rupiah('Total Estimasi', $pembelian->total_estimasi),
                $this->teks('Keterangan Pembelian', $pembelian->keterangan),
            ],
            'bagian' => $this->bagian('Item Pembelian', [
                $this->kolom('nama', 'Nama'),
                $this->kolom('qty', 'Qty', 'angka'),
                $this->kolom('harga', 'Harga Estimasi', 'rupiah'),
                $this->kolom('subtotal', 'Subtotal', 'rupiah'),
            ], $baris, $total, 'Total Estimasi'),
        ];
    }

    private function rincianPenawaran(string $idPenawaran, string $idPerusahaan): ?array
    {
        $penawaran = DB::table('penawaran as p')
            ->leftJoin('klien as k', $this->milik('k', 'k.id_klien', 'p.id_klien', $idPerusahaan))
            ->where('p.id_penawaran', $idPenawaran)
            ->where('p.id_perusahaan', $idPerusahaan)
            ->whereNull('p.dihapus_pada')
            ->first(['p.nomor_penawaran', 'p.judul', 'p.tipe_harga', 'p.nilai_penawaran', 'p.tanggal_penawaran', 'p.jumlah_hari', 'k.nama_klien']);
        if ($penawaran === null) {
            return null;
        }

        $item = DB::table('penawaran_item as pi')
            ->leftJoin('rute as r', $this->milik('r', 'r.id_rute', 'pi.id_rute', $idPerusahaan))
            ->leftJoin('jenis_kendaraan as jk', $this->milik('jk', 'jk.id_jenis_kendaraan', 'pi.id_jenis_kendaraan', $idPerusahaan))
            ->where('pi.id_penawaran', $idPenawaran)
            ->whereNull('pi.dihapus_pada')
            ->orderBy('pi.dibuat_pada')
            ->get(['r.nama_rute', 'jk.nama_jenis', 'pi.estimasi_ritase', 'pi.harga_satuan', 'pi.subtotal']);

        $total = 0.0;
        $adaHarga = false;
        $baris = [];
        foreach ($item as $satu) {
            $berharga = $satu->harga_satuan !== null;
            $adaHarga = $adaHarga || $berharga;
            $total += $berharga ? (float) $satu->subtotal : 0.0;
            $baris[] = [
                'rute'     => $satu->nama_rute,
                'jenis'    => $satu->nama_jenis,
                'ritase'   => $this->angkaAtauNull($satu->estimasi_ritase),
                'harga'    => $this->rupiahAtauNull($satu->harga_satuan),
                'subtotal' => $berharga ? $this->rupiahAtauNull($satu->subtotal) : null,
            ];
        }

        return [
            'judul'  => $this->gabungJudul($penawaran->nomor_penawaran, $penawaran->judul),
            'info'   => [
                $this->teks('Klien', $penawaran->nama_klien),
                $this->teks('Tipe Harga', $this->label(self::LABEL_TIPE_HARGA, $penawaran->tipe_harga)),
                $this->tanggal('Tanggal Penawaran', $penawaran->tanggal_penawaran),
                $this->angka('Jumlah Hari', $penawaran->jumlah_hari),
                $this->rupiah('Nilai Penawaran', $penawaran->nilai_penawaran),
            ],
            'bagian' => $this->bagian('Item Penawaran', [
                $this->kolom('rute', 'Rute'),
                $this->kolom('jenis', 'Jenis Kendaraan'),
                $this->kolom('ritase', 'Ritase', 'angka'),
                $this->kolom('harga', 'Harga', 'rupiah'),
                $this->kolom('subtotal', 'Subtotal', 'rupiah'),
            ], $baris, $adaHarga ? $total : null),
        ];
    }

    private function rincianProyek(string $idProyek, string $idPerusahaan): ?array
    {
        $proyek = DB::table('proyek as pr')
            ->leftJoin('klien as k', $this->milik('k', 'k.id_klien', 'pr.id_klien', $idPerusahaan))
            ->where('pr.id_proyek', $idProyek)
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('pr.dihapus_pada')
            ->first(['pr.kode_proyek', 'pr.nama_proyek', 'pr.tipe_harga', 'pr.tanggal_mulai', 'pr.tanggal_selesai', 'pr.harga_penawaran', 'pr.harga_proyek', 'pr.keterangan', 'k.nama_klien']);
        if ($proyek === null) {
            return null;
        }

        $rute = DB::table('proyek_rute as pru')
            ->leftJoin('rute as r', $this->milik('r', 'r.id_rute', 'pru.id_rute', $idPerusahaan))
            ->leftJoin('jenis_kendaraan as jk', $this->milik('jk', 'jk.id_jenis_kendaraan', 'pru.id_jenis_kendaraan', $idPerusahaan))
            ->where('pru.id_proyek', $idProyek)
            ->whereNull('pru.dihapus_pada')
            ->orderBy('pru.dibuat_pada')
            ->get(['r.nama_rute', 'jk.nama_jenis', 'pru.estimasi_ritase', 'pru.harga_penawaran']);

        $total = 0.0;
        $adaHarga = false;
        $baris = [];
        foreach ($rute as $satu) {
            $berharga = $satu->harga_penawaran !== null;
            $subtotal = $berharga ? round((float) $satu->harga_penawaran * (int) $satu->estimasi_ritase, 2) : null;
            $adaHarga = $adaHarga || $berharga;
            $total += $subtotal ?? 0.0;
            $baris[] = [
                'rute'     => $satu->nama_rute,
                'jenis'    => $satu->nama_jenis,
                'ritase'   => $this->angkaAtauNull($satu->estimasi_ritase),
                'harga'    => $this->rupiahAtauNull($satu->harga_penawaran),
                'subtotal' => $subtotal,
            ];
        }

        return [
            'judul'  => $this->gabungJudul($proyek->kode_proyek, $proyek->nama_proyek),
            'info'   => [
                $this->teks('Klien', $proyek->nama_klien),
                $this->teks('Tipe Harga', $this->label(self::LABEL_TIPE_HARGA, $proyek->tipe_harga)),
                $this->tanggal('Tanggal Mulai', $proyek->tanggal_mulai),
                $this->tanggal('Tanggal Selesai', $proyek->tanggal_selesai),
                $this->rupiah('Harga Penawaran', $proyek->harga_penawaran),
                $this->rupiah('Harga Proyek', $proyek->harga_proyek),
                $this->teks('Keterangan', $proyek->keterangan),
            ],
            'bagian' => $this->bagian('Rute Proyek', [
                $this->kolom('rute', 'Rute'),
                $this->kolom('jenis', 'Jenis Kendaraan'),
                $this->kolom('ritase', 'Ritase', 'angka'),
                $this->kolom('harga', 'Harga', 'rupiah'),
                $this->kolom('subtotal', 'Subtotal', 'rupiah'),
            ], $baris, $adaHarga ? $total : null),
        ];
    }

    private function rincianFaktur(string $idFaktur, string $idPerusahaan): ?array
    {
        $faktur = DB::table('faktur as f')
            ->leftJoin('klien as k', $this->milik('k', 'k.id_klien', 'f.id_klien', $idPerusahaan))
            ->leftJoin('proyek as pr', $this->milik('pr', 'pr.id_proyek', 'f.id_proyek', $idPerusahaan))
            ->where('f.id_faktur', $idFaktur)
            ->where('f.id_perusahaan', $idPerusahaan)
            ->whereNull('f.dihapus_pada')
            ->first(['f.nomor_faktur', 'f.tanggal_faktur', 'f.jatuh_tempo', 'f.total', 'f.nama_pajak', 'f.persen_pajak', 'k.nama_klien', 'pr.nama_proyek']);
        if ($faktur === null) {
            return null;
        }

        $pajak = DB::table('faktur_pajak')
            ->where('id_faktur', $idFaktur)
            ->whereNull('dihapus_pada')
            ->orderBy('urutan')
            ->get(['nama', 'persen'])
            ->map(fn (object $baris) => trim("{$baris->nama} " . $this->persen($baris->persen)))
            ->all();
        if ($pajak === [] && $faktur->persen_pajak !== null) {
            $pajak = [trim("{$faktur->nama_pajak} " . $this->persen($faktur->persen_pajak))];
        }

        $item = DB::table('faktur_item')
            ->where('id_faktur', $idFaktur)
            ->whereNull('dihapus_pada')
            ->orderBy('dibuat_pada')
            ->get(['deskripsi', 'qty', 'harga_satuan', 'subtotal']);

        $total = 0.0;
        $baris = [];
        foreach ($item as $satu) {
            $total += (float) $satu->subtotal;
            $baris[] = [
                'deskripsi' => $satu->deskripsi,
                'qty'       => $this->angkaAtauNull($satu->qty),
                'harga'     => $this->rupiahAtauNull($satu->harga_satuan),
                'subtotal'  => $this->rupiahAtauNull($satu->subtotal),
            ];
        }

        return [
            'judul'  => $this->gabungJudul($faktur->nomor_faktur, $this->teksAtauNull($faktur->nama_proyek) ?? $faktur->nama_klien),
            'info'   => [
                $this->teks('Klien', $faktur->nama_klien),
                $this->teks('Proyek', $faktur->nama_proyek),
                $this->tanggal('Tanggal Faktur', $faktur->tanggal_faktur),
                $this->tanggal('Jatuh Tempo', $faktur->jatuh_tempo),
                $this->teks('Pajak', $pajak !== [] ? implode(', ', $pajak) : null),
                $this->rupiah('Total', $faktur->total),
            ],
            'bagian' => $this->bagian('Item Faktur', [
                $this->kolom('deskripsi', 'Deskripsi'),
                $this->kolom('qty', 'Qty', 'angka'),
                $this->kolom('harga', 'Harga', 'rupiah'),
                $this->kolom('subtotal', 'Subtotal', 'rupiah'),
            ], $baris, $total, 'Subtotal'),
        ];
    }

    private function rincianInvoiceVendor(string $idInvoice, string $idPerusahaan): ?array
    {
        $invoice = DB::table('invoice_vendor as iv')
            ->leftJoin('vendor as v', $this->milik('v', 'v.id_vendor', 'iv.id_vendor', $idPerusahaan))
            ->leftJoin('kontrak_vendor as kv', $this->milik('kv', 'kv.id_kontrak_vendor', 'iv.id_kontrak_vendor', $idPerusahaan))
            ->where('iv.id_invoice_vendor', $idInvoice)
            ->where('iv.id_perusahaan', $idPerusahaan)
            ->whereNull('iv.dihapus_pada')
            ->first([
                'iv.nomor_invoice', 'iv.tanggal_invoice', 'iv.jatuh_tempo', 'iv.periode_dari', 'iv.periode_sampai',
                'iv.no_po', 'iv.no_kontrak', 'iv.nopol', 'iv.tipe_kendaraan', 'iv.dpp', 'iv.ppn', 'iv.pph', 'iv.total',
                'iv.keterangan', 'v.nama_vendor', 'kv.nomor_kontrak',
            ]);
        if ($invoice === null) {
            return null;
        }

        $trip = DB::table('invoice_vendor_trip as ivt')
            ->join('trip as t', 't.id_trip', '=', 'ivt.id_trip')
            ->join('jadwal_keberangkatan as jk', 'jk.id_jadwal', '=', 't.id_jadwal')
            ->join('penugasan as p', 'p.id_penugasan', '=', 'jk.id_penugasan')
            ->join('proyek as pr', $this->milik('pr', 'pr.id_proyek', 'p.id_proyek', $idPerusahaan))
            ->leftJoin('armada_vendor as av', 'av.id_armada_vendor', '=', 'p.id_armada_vendor')
            ->leftJoin('supir_vendor as sv', 'sv.id_supir_vendor', '=', 'p.id_supir_vendor')
            ->leftJoin('rute as r', 'r.id_rute', '=', 'jk.id_rute')
            ->where('ivt.id_invoice_vendor', $idInvoice)
            ->whereNull('ivt.dihapus_pada')
            ->orderByRaw('COALESCE(jk.waktu_berangkat, t.dibuat_pada)')
            ->get([
                DB::raw('COALESCE(jk.waktu_berangkat, t.dibuat_pada) as waktu'),
                DB::raw('COALESCE(r.nama_rute, jk.rute) as rute'),
                'av.nopol', 'sv.nama as driver', 'pr.nama_proyek',
            ]);

        $baris = $trip->map(fn (object $satu) => [
            'tanggal' => $this->tanggalAtauNull($satu->waktu),
            'rute'    => $satu->rute,
            'nopol'   => $satu->nopol,
            'driver'  => $satu->driver,
            'proyek'  => $satu->nama_proyek,
        ])->all();

        return [
            'judul'  => $this->gabungJudul($invoice->nomor_invoice, $invoice->nama_vendor),
            'info'   => [
                $this->teks('Vendor', $invoice->nama_vendor),
                $this->teks('No. Kontrak', $this->teksAtauNull($invoice->nomor_kontrak) ?? $invoice->no_kontrak),
                $this->teks('No. PO', $invoice->no_po),
                $this->tanggal('Tanggal Invoice', $invoice->tanggal_invoice),
                $this->tanggal('Jatuh Tempo', $invoice->jatuh_tempo),
                $this->tanggal('Periode Dari', $invoice->periode_dari),
                $this->tanggal('Periode Sampai', $invoice->periode_sampai),
                $this->teks('Nopol', $invoice->nopol),
                $this->teks('Tipe Kendaraan', $invoice->tipe_kendaraan),
                $this->rupiah('DPP', $invoice->dpp),
                $this->rupiah('PPN', $invoice->ppn),
                $this->rupiah('PPh', $invoice->pph),
                $this->rupiah('Total', $invoice->total),
                $this->teks('Keterangan', $invoice->keterangan),
            ],
            'bagian' => $this->bagian('Trip Ditagihkan', [
                $this->kolom('tanggal', 'Tanggal', 'tanggal'),
                $this->kolom('rute', 'Rute'),
                $this->kolom('nopol', 'Nopol'),
                $this->kolom('driver', 'Driver'),
                $this->kolom('proyek', 'Proyek'),
            ], $baris),
        ];
    }

    private function rincianKontrakVendor(string $idKontrak, string $idPerusahaan): ?array
    {
        $kontrak = DB::table('kontrak_vendor as kv')
            ->leftJoin('vendor as v', $this->milik('v', 'v.id_vendor', 'kv.id_vendor', $idPerusahaan))
            ->leftJoin('proyek as pr', $this->milik('pr', 'pr.id_proyek', 'kv.id_proyek', $idPerusahaan))
            ->where('kv.id_kontrak_vendor', $idKontrak)
            ->where('kv.id_perusahaan', $idPerusahaan)
            ->whereNull('kv.dihapus_pada')
            ->first([
                'kv.nomor_kontrak', 'kv.mekanisme', 'kv.nilai_kontrak', 'kv.rate', 'kv.satuan', 'kv.pajak_persen',
                'kv.termin_pembayaran_hari', 'kv.jumlah_trip', 'kv.jumlah_hari', 'kv.tanggal_mulai', 'kv.tanggal_selesai',
                'v.nama_vendor', 'pr.nama_proyek',
            ]);
        if ($kontrak === null) {
            return null;
        }

        $unit = DB::table('armada_vendor as av')
            ->leftJoin('jenis_kendaraan as jk', $this->milik('jk', 'jk.id_jenis_kendaraan', 'av.id_jenis_kendaraan', $idPerusahaan))
            ->where('av.id_kontrak_vendor', $idKontrak)
            ->whereNull('av.dihapus_pada')
            ->orderBy('av.nopol')
            ->get(['av.nopol', 'av.jenis', 'av.merk', 'jk.nama_jenis'])
            ->map(fn (object $satu) => [
                'nopol' => $satu->nopol,
                'jenis' => $this->teksAtauNull($satu->nama_jenis) ?? $satu->jenis,
                'merk'  => $satu->merk,
            ])->all();

        $supir = DB::table('supir_vendor')
            ->where('id_kontrak_vendor', $idKontrak)
            ->whereNull('dihapus_pada')
            ->orderBy('nama')
            ->get(['nama'])
            ->map(fn (object $satu) => ['nama' => $satu->nama])
            ->all();

        return [
            'judul'  => $this->gabungJudul($kontrak->nomor_kontrak, $kontrak->nama_vendor),
            'info'   => [
                $this->teks('Vendor', $kontrak->nama_vendor),
                $this->teks('Proyek', $kontrak->nama_proyek),
                $this->teks('Mekanisme', $this->label(self::LABEL_MEKANISME, $kontrak->mekanisme)),
                $this->rupiah('Nilai Kontrak', $kontrak->nilai_kontrak),
                $this->rupiah('Rate', $kontrak->rate),
                $this->teks('Satuan', $kontrak->satuan),
                $this->angka('Pajak (%)', $kontrak->pajak_persen),
                $this->angka('Termin Pembayaran (hari)', $kontrak->termin_pembayaran_hari),
                $this->angka('Jumlah Trip', $kontrak->jumlah_trip),
                $this->angka('Jumlah Hari', $kontrak->jumlah_hari),
                $this->tanggal('Tanggal Mulai', $kontrak->tanggal_mulai),
                $this->tanggal('Tanggal Selesai', $kontrak->tanggal_selesai),
            ],
            'bagian' => [
                ...$this->bagian('Unit', [
                    $this->kolom('nopol', 'Nopol'),
                    $this->kolom('jenis', 'Jenis'),
                    $this->kolom('merk', 'Merk'),
                ], $unit),
                ...$this->bagian('Supir', [
                    $this->kolom('nama', 'Nama'),
                ], $supir),
            ],
        ];
    }

    private function rincianPermintaanVendor(string $idPermintaan, string $idPerusahaan): ?array
    {
        $permintaan = DB::table('permintaan_vendor as pv')
            ->leftJoin('proyek as pr', $this->milik('pr', 'pr.id_proyek', 'pv.id_proyek', $idPerusahaan))
            ->leftJoin('jenis_kendaraan as jk', $this->milik('jk', 'jk.id_jenis_kendaraan', 'pv.id_jenis_kendaraan', $idPerusahaan))
            ->where('pv.id_permintaan', $idPermintaan)
            ->where('pv.id_perusahaan', $idPerusahaan)
            ->whereNull('pv.dihapus_pada')
            ->first(['pv.nomor_permintaan', 'pv.mekanisme', 'pv.periode_dari', 'pv.periode_sampai', 'pv.catatan', 'pv.jumlah_unit', 'pr.nama_proyek', 'jk.nama_jenis']);
        if ($permintaan === null) {
            return null;
        }

        $unit = DB::table('permintaan_vendor_unit as pvu')
            ->leftJoin('jenis_kendaraan as jk', $this->milik('jk', 'jk.id_jenis_kendaraan', 'pvu.id_jenis_kendaraan', $idPerusahaan))
            ->where('pvu.id_permintaan', $idPermintaan)
            ->whereNull('pvu.dihapus_pada')
            ->orderBy('pvu.urutan')
            ->get(['jk.nama_jenis', 'pvu.jumlah_unit'])
            ->map(fn (object $satu) => [
                'jenis'  => $satu->nama_jenis,
                'jumlah' => $this->angkaAtauNull($satu->jumlah_unit),
            ])->all();

        if ($unit === []) {
            $unit = [[
                'jenis'  => $permintaan->nama_jenis,
                'jumlah' => $this->angkaAtauNull($permintaan->jumlah_unit),
            ]];
        }

        return [
            'judul'  => $this->gabungJudul($permintaan->nomor_permintaan, $permintaan->nama_proyek),
            'info'   => [
                $this->teks('Proyek', $permintaan->nama_proyek),
                $this->teks('Mekanisme', $this->label(self::LABEL_MEKANISME, $permintaan->mekanisme)),
                $this->tanggal('Periode Dari', $permintaan->periode_dari),
                $this->tanggal('Periode Sampai', $permintaan->periode_sampai),
                $this->teks('Catatan', $permintaan->catatan),
            ],
            'bagian' => $this->bagian('Unit Diminta', [
                $this->kolom('jenis', 'Jenis Kendaraan'),
                $this->kolom('jumlah', 'Jumlah', 'angka'),
            ], $unit),
        ];
    }

    private function rincianPermintaanPembelian(string $idPermintaan, string $idPerusahaan): ?array
    {
        $permintaan = DB::table('permintaan_pembelian as pp')
            ->leftJoin('departemen as d', $this->milik('d', 'd.id_departemen', 'pp.id_departemen', $idPerusahaan))
            ->leftJoin('pengguna as u', 'u.id_pengguna', '=', 'pp.id_pengaju')
            ->where('pp.id_permintaan', $idPermintaan)
            ->where('pp.id_perusahaan', $idPerusahaan)
            ->whereNull('pp.dihapus_pada')
            ->first(['pp.nomor_permintaan', 'pp.judul', 'pp.tipe', 'pp.alasan', 'pp.tanggal_permintaan', 'pp.tanggal_dibutuhkan', 'pp.total_estimasi', 'd.nama_departemen', 'u.username']);
        if ($permintaan === null) {
            return null;
        }

        $aset = (string) $permintaan->tipe === 'aset';

        $item = DB::table('permintaan_pembelian_item as i')
            ->leftJoin('jenis_kendaraan as jk', $this->milik('jk', 'jk.id_jenis_kendaraan', 'i.id_jenis_kendaraan', $idPerusahaan))
            ->where('i.id_permintaan', $idPermintaan)
            ->whereNull('i.dihapus_pada')
            ->orderBy('i.dibuat_pada')
            ->get(['i.nama_item', 'i.merk', 'i.model', 'i.tahun', 'i.qty', 'i.satuan', 'i.harga_estimasi', 'jk.nama_jenis']);

        $totalItem = 0.0;
        $baris = [];
        foreach ($item as $satu) {
            $subtotal = round((int) $satu->qty * (float) $satu->harga_estimasi, 2);
            $totalItem += $subtotal;
            $baris[] = [
                'nama'     => $aset ? $this->namaUnitAset($satu) : $satu->nama_item,
                'qty'      => (int) $satu->qty,
                'satuan'   => $satu->satuan,
                'harga'    => (float) $satu->harga_estimasi,
                'subtotal' => $subtotal,
            ];
        }

        $termin = DB::table('permintaan_pembelian_termin')
            ->where('id_permintaan', $idPermintaan)
            ->whereNull('dihapus_pada')
            ->orderBy('urutan')
            ->get(['urutan', 'nama', 'nominal', 'jatuh_tempo', 'status']);

        $totalTermin = 0.0;
        $barisTermin = [];
        foreach ($termin as $satu) {
            $totalTermin += (float) $satu->nominal;
            $barisTermin[] = [
                'urutan'      => (int) $satu->urutan,
                'nama'        => $satu->nama,
                'nominal'     => (float) $satu->nominal,
                'jatuh_tempo' => $this->tanggalAtauNull($satu->jatuh_tempo),
                'status'      => ucfirst(str_replace('_', ' ', (string) $satu->status)),
            ];
        }

        return [
            'judul'  => $this->gabungJudul($permintaan->nomor_permintaan, $permintaan->judul),
            'info'   => [
                $this->teks('Pengaju', $permintaan->username),
                $this->teks('Departemen', $permintaan->nama_departemen),
                $this->teks('Tipe', $this->label(self::LABEL_TIPE_PERMINTAAN, $permintaan->tipe)),
                $this->tanggal('Tanggal', $permintaan->tanggal_permintaan),
                $this->tanggal('Dibutuhkan', $permintaan->tanggal_dibutuhkan),
                $this->teks('Alasan', $permintaan->alasan),
                $this->rupiah('Total Estimasi', $permintaan->total_estimasi),
            ],
            'bagian' => [
                ...$this->bagian('Item', [
                    $this->kolom('nama', $aset ? 'Unit' : 'Nama'),
                    $this->kolom('qty', 'Qty', 'angka'),
                    $this->kolom('satuan', 'Satuan'),
                    $this->kolom('harga', 'Harga Estimasi', 'rupiah'),
                    $this->kolom('subtotal', 'Subtotal', 'rupiah'),
                ], $baris, $totalItem, 'Total Estimasi'),
                ...$this->bagian('Termin', [
                    $this->kolom('urutan', 'No', 'angka'),
                    $this->kolom('nama', 'Termin'),
                    $this->kolom('nominal', 'Nominal', 'rupiah'),
                    $this->kolom('jatuh_tempo', 'Jatuh Tempo', 'tanggal'),
                    $this->kolom('status', 'Status'),
                ], $barisTermin, $totalTermin, 'Total Termin'),
            ],
        ];
    }

    private function namaUnitAset(object $item): string
    {
        $unit = trim((string) preg_replace('/\s+/', ' ', "{$item->merk} {$item->model} {$item->tahun}"));
        if ($unit === '') {
            $unit = (string) $item->nama_item;
        }

        return $this->gabungJudul($unit, $item->nama_jenis);
    }

    private function milik(string $alias, string $kolomTabel, string $kolomAsal, string $idPerusahaan): Closure
    {
        return static function (JoinClause $join) use ($alias, $kolomTabel, $kolomAsal, $idPerusahaan): void {
            $join->on($kolomTabel, '=', $kolomAsal)->where("{$alias}.id_perusahaan", $idPerusahaan);
        };
    }

    private function bagian(string $judul, array $kolom, array $baris, ?float $total = null, string $labelTotal = 'Total'): array
    {
        if ($baris === []) {
            return [];
        }

        $bagian = ['judul' => $judul, 'kolom' => $kolom, 'baris' => array_values($baris)];
        if ($total !== null) {
            $bagian['total'] = ['label' => $labelTotal, 'value' => round($total, 2)];
        }

        return [$bagian];
    }

    private function kolom(string $key, string $label, string $tipe = 'teks'): array
    {
        $kolom = ['key' => $key, 'label' => $label];
        if ($tipe === 'angka' || $tipe === 'rupiah') {
            $kolom['align'] = 'right';
        }
        if ($tipe !== 'teks') {
            $kolom['tipe'] = $tipe;
        }

        return $kolom;
    }

    private function teks(string $label, mixed $nilai): array
    {
        return ['label' => $label, 'value' => $this->teksAtauNull($nilai)];
    }

    private function rupiah(string $label, mixed $nilai): array
    {
        return ['label' => $label, 'value' => $this->rupiahAtauNull($nilai), 'tipe' => 'rupiah'];
    }

    private function tanggal(string $label, mixed $nilai): array
    {
        return ['label' => $label, 'value' => $this->tanggalAtauNull($nilai), 'tipe' => 'tanggal'];
    }

    private function angka(string $label, mixed $nilai): array
    {
        return ['label' => $label, 'value' => $this->angkaAtauNull($nilai), 'tipe' => 'angka'];
    }

    private function teksAtauNull(mixed $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }

        $teks = trim((string) $nilai);

        return $teks !== '' ? $teks : null;
    }

    private function tanggalAtauNull(mixed $nilai): ?string
    {
        $teks = $this->teksAtauNull($nilai);

        return $teks !== null ? substr($teks, 0, 10) : null;
    }

    private function rupiahAtauNull(mixed $nilai): ?float
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        return round((float) $nilai, 2);
    }

    private function angkaAtauNull(mixed $nilai): int|float|null
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        $angka = (float) $nilai;

        return floor($angka) === $angka ? (int) $angka : $angka;
    }

    private function label(array $peta, mixed $kunci): ?string
    {
        $teks = $this->teksAtauNull($kunci);

        return $teks !== null ? ($peta[$teks] ?? $teks) : null;
    }

    private function persen(mixed $nilai): string
    {
        return rtrim(rtrim(number_format((float) $nilai, 2, '.', ''), '0'), '.') . '%';
    }

    private function gabungJudul(mixed ...$bagian): string
    {
        $terisi = array_filter(
            array_map(fn (mixed $satu) => trim((string) $satu), $bagian),
            fn (string $satu) => $satu !== '',
        );

        return implode(' · ', $terisi);
    }
}
