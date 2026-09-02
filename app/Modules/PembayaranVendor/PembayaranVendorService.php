<?php

declare(strict_types=1);

namespace App\Modules\PembayaranVendor;

use App\Modules\PembayaranVendor\Contracts\PembayaranVendorRepositoryInterface;
use App\Support\PenyimpananBerkas;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class PembayaranVendorService
{
    public function __construct(private readonly PembayaranVendorRepositoryInterface $repo) {}

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
        return DB::transaction(function () use ($idInvoice, $data) {
            $invoice = $this->repo->kunciInvoice($idInvoice);
            if ($invoice === null) {
                abort(404, 'Invoice vendor tidak ditemukan');
            }

            $dibayar = $this->repo->totalDibayar($idInvoice);
            $nominal = (float) $data['nominal'];
            if (round($dibayar + $nominal, 2) > round((float) $invoice->total, 2)) {
                abort(409, 'Nominal melebihi sisa tagihan');
            }

            $record = $this->repo->create(array_merge($data, ['id_invoice_vendor' => $idInvoice]));
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

        DB::transaction(function () use ($record, $idInvoice) {
            $this->repo->kunciInvoice($idInvoice);
            $this->repo->delete($record);
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
