<?php

declare(strict_types=1);

namespace App\Modules\PembayaranVendor;

use App\Modules\KasKecil\BukuKasKecil;
use App\Modules\PembayaranVendor\Contracts\PembayaranVendorRepositoryInterface;
use App\Support\PenyimpananBerkas;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class PembayaranVendorService
{
    public function __construct(
        private readonly PembayaranVendorRepositoryInterface $repo,
        private readonly BukuKasKecil $bukuKasKecil,
    ) {}

    /**
     * Pembayaran invoice vendor tidak lagi dicatat langsung — diajukan dulu ke
     * alur Proses Pembayaran (kategori pembayaran_vendor); baris pembayaran_vendor
     * baru tercipta otomatis saat pengajuan itu ditransfer.
     */
    public function ajukanPembayaran(string $idInvoice, string $idPerusahaan, float $nominal, ?string $catatan): \App\Modules\ArusKas\PengajuanPengeluaranModel
    {
        $info = $this->repo->infoInvoiceUntukPengajuan($idInvoice, $idPerusahaan);
        if ($info === null) {
            abort(404, 'Invoice vendor tidak ditemukan');
        }

        return app(\App\Modules\ArusKas\ArusKasService::class)->buatPengajuanPembayaranInvoiceVendor(
            $idInvoice,
            $idPerusahaan,
            $nominal,
            $catatan,
            (string) ($info->nama_vendor ?? '-'),
            (string) $info->nomor_invoice,
        );
    }

    public function listByInvoice(string $idInvoice, string $idPerusahaan): array
    {
        $this->findInvoiceOrFail($idInvoice, $idPerusahaan);
        return $this->repo->listByInvoice($idInvoice);
    }

    public function create(string $idInvoice, string $idPerusahaan, array $data, ?UploadedFile $file = null): PembayaranVendorModel
    {
        $invoice = $this->findInvoiceOrFail($idInvoice, $idPerusahaan);

        if ($invoice->status !== 'diverifikasi') {
            abort(409, 'Invoice belum diverifikasi');
        }

        if ($file) {
            $data['url_bukti'] = PenyimpananBerkas::simpan($file, 'bukti-pembayaran');
        }
        unset($data['bukti']);

        // Guard sisa tagihan harus atomik: kunci baris invoice supaya dua
        // pembayaran paralel tidak sama-sama lolos dan melebihi total.
        return DB::transaction(function () use ($idInvoice, $idPerusahaan, $data) {
            $invoice = $this->repo->kunciInvoice($idInvoice);
            if ($invoice === null) {
                abort(404, 'Invoice vendor tidak ditemukan');
            }

            $dibayar = $this->repo->totalDibayar($idInvoice);
            $nominal = (float) $data['nominal'];
            if (round($dibayar + $nominal, 2) > round((float) $invoice->total, 2)) {
                abort(409, 'Nominal melebihi sisa tagihan');
            }

            // Pembayaran vendor dibayar dari Kas Kecil (Arus Kas/bank hanya untuk pengisian kas kecil).
            $record = $this->repo->create(array_merge($data, [
                'id_invoice_vendor' => $idInvoice,
                'sumber_dana'       => BukuKasKecil::SUMBER_KAS_KECIL,
            ]));
            $info = $this->repo->infoInvoiceUntukPengajuan($idInvoice, $idPerusahaan);
            $this->bukuKasKecil->catatPembayaran(
                $idPerusahaan,
                (string) $data['tanggal_bayar'],
                $nominal,
                $info->nama_vendor ?? null,
                'Pembayaran invoice vendor ' . ($info->nomor_invoice ?? '') . (!empty($data['catatan']) ? ': ' . $data['catatan'] : ''),
                $record->url_bukti,
                ['id_pembayaran_vendor' => (string) $record->id_pembayaran_vendor],
            );
            $this->repo->recalcStatusPembayaran($idInvoice);

            return $record;
        });
    }

    public function delete(string $id, string $idInvoice, string $idPerusahaan): void
    {
        $this->findInvoiceOrFail($idInvoice, $idPerusahaan);

        $record = $this->repo->findByIdUntukInvoice($id, $idInvoice, $idPerusahaan);
        if ($record === null) {
            abort(404, 'Pembayaran vendor tidak ditemukan');
        }
        if ($this->repo->berasalDariPengajuan($idInvoice, $record->no_referensi)) {
            abort(422, 'Pembayaran ini hasil transfer dari Proses Pembayaran — tidak bisa dihapus');
        }

        DB::transaction(function () use ($record, $idInvoice) {
            $this->repo->kunciInvoice($idInvoice);
            $this->repo->delete($record);
            $this->bukuKasKecil->hapusPembayaranVendor((string) $record->id_pembayaran_vendor);
            $this->repo->recalcStatusPembayaran($idInvoice);
        });
    }

    public function dataCetak(string $id, string $idInvoice, string $idPerusahaan): object
    {
        $this->findInvoiceOrFail($idInvoice, $idPerusahaan);

        $data = $this->repo->dataCetak($id, $idInvoice, $idPerusahaan);
        if ($data === null) {
            abort(404, 'Pembayaran vendor tidak ditemukan');
        }

        $data->nominal = (float) $data->nominal;
        $data->total_invoice = (float) $data->total_invoice;

        return $data;
    }

    public function dataPerusahaan(string $idPerusahaan): ?object
    {
        return $this->repo->getPerusahaan($idPerusahaan);
    }

    private function findInvoiceOrFail(string $idInvoice, string $idPerusahaan): object
    {
        $invoice = $this->repo->findInvoiceUntukPerusahaan($idInvoice, $idPerusahaan);
        if ($invoice === null) {
            abort(404, 'Invoice vendor tidak ditemukan');
        }
        return $invoice;
    }
}
