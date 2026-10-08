<?php

declare(strict_types=1);

namespace App\Modules\PermintaanPembelian\Resources;

use App\Modules\PermintaanPembelian\PermintaanPembelianService;
use Illuminate\Http\Resources\Json\JsonResource;

class PermintaanPembelianResource extends JsonResource
{
    public function toArray($request): array
    {
        $items = collect($this->items ?? [])->map(fn ($i) => [
            'id_item'           => $i->id_item,
            'jenis'             => $i->jenis,
            'id_barang'         => $i->id_barang,
            'kode_barang'       => $i->kode_barang ?? null,
            'nama_barang'       => $i->nama_barang ?? null,
            'id_sparepart'      => $i->id_sparepart ?? null,
            'kode_sparepart'    => $i->kode_sparepart ?? null,
            'nama_sparepart'    => $i->nama_sparepart ?? null,
            'stok_sparepart'    => isset($i->stok_sparepart) ? (int) $i->stok_sparepart : null,
            'id_jenis_kendaraan' => $i->id_jenis_kendaraan ?? null,
            'nama_jenis_kendaraan' => $i->nama_jenis_kendaraan ?? null,
            'merk'              => $i->merk ?? null,
            'model'             => $i->model ?? null,
            'tahun'             => isset($i->tahun) ? (int) $i->tahun : null,
            'armada_terdaftar'  => $i->armada_terdaftar ?? [],
            'nama_item'         => $i->nama_item,
            'spesifikasi'       => $i->spesifikasi,
            'qty'               => (int) $i->qty,
            'satuan'            => $i->satuan,
            'harga_estimasi'    => (float) $i->harga_estimasi,
            'harga_aktual'      => $i->harga_aktual !== null ? (float) $i->harga_aktual : null,
            'subtotal_estimasi' => (int) $i->qty * (float) $i->harga_estimasi,
            'subtotal_aktual'   => $i->harga_aktual !== null ? PermintaanPembelianService::qtyBerlaku($this->resource, $i) * (float) $i->harga_aktual : null,
            'qty_diterima'      => $i->qty_diterima !== null ? (int) $i->qty_diterima : null,
            'keterangan'        => $i->keterangan,
        ])->all();

        $semuaHargaAktual = $items !== [] && collect($items)->every(fn ($i) => $i['harga_aktual'] !== null);
        $subtotalAktual = $semuaHargaAktual ? (float) collect($items)->sum('subtotal_aktual') : null;

        $ps = $this->pembelian_sparepart ?? null;

        $termin = collect($this->termin ?? [])->map(fn ($t) => [
            'id_termin'        => $t->id_termin,
            'urutan'           => (int) $t->urutan,
            'nama'             => $t->nama,
            'nominal'          => (float) $t->nominal,
            'jatuh_tempo'      => $t->jatuh_tempo,
            'status'           => $t->status,
            'tanggal_transfer' => $t->tanggal_transfer,
            'pengajuan'        => $t->pengajuan ?? null,
        ])->all();

        return [
            'id_permintaan'      => $this->id_permintaan,
            'nomor_permintaan'   => $this->nomor_permintaan,
            'tipe'               => $this->tipe ?? 'umum',
            'id_judul_permintaan' => $this->id_judul_permintaan ?? null,
            'nama_kategori'      => $this->nama_kategori ?? null,
            'judul'              => $this->judul,
            'prioritas'          => $this->prioritas ?? 'normal',
            'alasan'             => $this->alasan,
            'status'             => $this->status,
            'id_pengaju'         => $this->id_pengaju,
            'username_pengaju'   => $this->username_pengaju ?? null,
            'id_departemen'      => $this->id_departemen,
            'nama_departemen'    => $this->nama_departemen ?? null,
            'id_supplier'        => $this->id_supplier,
            'nama_supplier'      => $this->nama_supplier ?? null,
            'metode_pembelian'   => $this->metode_pembelian ?? null,
            'nama_toko'          => $this->nama_toko ?? null,
            'nama_penalang'      => $this->nama_penalang ?? null,
            'syarat_pembayaran'  => $this->syarat_pembayaran ?? 'setelah_terima',
            'kelebihan_bayar'    => isset($this->kelebihan_bayar) ? (float) $this->kelebihan_bayar : null,
            'batas_beli_tunai'   => isset($this->batas_beli_tunai) ? (float) $this->batas_beli_tunai : null,
            'id_perawatan'       => $this->id_perawatan ?? null,
            'nopol_perawatan'    => $this->nopol_perawatan ?? null,
            'tanggal_perawatan'  => $this->tanggal_perawatan ?? null,
            'tanggal_permintaan' => $this->tanggal_permintaan,
            'tanggal_dibutuhkan' => $this->tanggal_dibutuhkan,
            'tanggal_pembelian'  => $this->tanggal_pembelian,
            'nomor_po'           => $this->nomor_po ?? null,
            'tanggal_po'         => $this->tanggal_po ?? null,
            'tanggal_diterima'   => $this->tanggal_diterima,
            'keterangan_penerimaan' => $this->keterangan_penerimaan ?? null,
            'tanggal_pembayaran' => $this->tanggal_pembayaran,
            'total_estimasi'     => (float) $this->total_estimasi,
            'subtotal_aktual'    => $subtotalAktual,
            'diskon'             => (float) ($this->diskon ?? 0),
            'ppn_persen'         => (float) ($this->ppn_persen ?? 0),
            'ppn'                => (float) ($this->ppn ?? 0),
            'ongkir'             => (float) ($this->ongkir ?? 0),
            'total_aktual'       => $this->total_aktual !== null ? (float) $this->total_aktual : null,
            'boleh_realisasi_mandiri' => (bool) ($this->boleh_realisasi_mandiri ?? false),
            'batas_mandiri'      => isset($this->batas_mandiri) ? (float) $this->batas_mandiri : null,
            'alasan_ditolak'     => $this->alasan_ditolak,
            'alasan_batal'       => $this->alasan_batal,
            'diproses_pada'      => $this->diproses_pada,
            'dibeli_pada'        => $this->dibeli_pada,
            'dibeli_oleh'        => $this->dibeli_oleh ?? null,
            'diterima_pada'      => $this->diterima_pada,
            'sisa_ditutup_pada'  => $this->sisa_ditutup_pada ?? null,
            'alasan_tutup_sisa'  => $this->alasan_tutup_sisa ?? null,
            'penerimaan'         => collect($this->penerimaan ?? [])->map(fn ($t) => [
                'id_penerimaan'    => $t->id_penerimaan,
                'tanggal_diterima' => $t->tanggal_diterima,
                'keterangan'       => $t->keterangan,
                'diterima_oleh'    => $t->diterima_oleh,
                'dibuat_pada'      => $t->dibuat_pada,
                'items'            => collect($t->items ?? [])->map(fn ($i) => [
                    'id_item'   => $i->id_item,
                    'nama_item' => $i->nama_item,
                    'satuan'    => $i->satuan,
                    'qty'       => (int) $i->qty,
                ])->all(),
            ])->all(),
            'items'              => $items,
            'termin'             => $termin,
            'termin_lunas'       => (bool) ($this->termin_lunas ?? false),
            'bukti'              => $this->bukti ?? [],
            'pengajuan_keuangan' => $this->pengajuan_keuangan ?? null,
            'pembayaran_ditolak' => (bool) ($this->pembayaran_ditolak ?? false),
            'pembelian_sparepart' => $ps !== null ? [
                'id_pembelian'    => $ps->id_pembelian,
                'nomor_pengajuan' => $ps->nomor_pengajuan,
                'status'          => $ps->status,
            ] : null,
            'dibuat_pada'        => $this->dibuat_pada,
        ];
    }
}
