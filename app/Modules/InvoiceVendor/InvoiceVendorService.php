<?php

declare(strict_types=1);

namespace App\Modules\InvoiceVendor;

use App\Modules\InvoiceVendor\Contracts\InvoiceVendorRepositoryInterface;
use App\Support\PenyimpananBerkas;
use Illuminate\Support\Carbon;

class InvoiceVendorService
{
    private const STATUS_BISA_DIUBAH = ['draft', 'ditolak'];

    public function __construct(private readonly InvoiceVendorRepositoryInterface $repo) {}

    public function list(
        string $idPerusahaan,
        int $page = 1,
        int $limit = 10,
        ?string $search = null,
        ?string $status = null,
        ?string $statusPembayaran = null,
        ?string $idVendor = null
    ): array {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search, $status, $statusPembayaran, $idVendor);

        return [
            'data' => $result->items(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    public function findOrFail(string $id, string $idPerusahaan): InvoiceVendorModel
    {
        $record = $this->repo->findByIdUntukPerusahaan($id, $idPerusahaan);
        if ($record === null) {
            abort(404, 'Invoice vendor tidak ditemukan');
        }
        return $record;
    }

    public function detail(string $id, string $idPerusahaan): array
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        $vendor = $this->repo->vendorInfo($record->id_vendor);
        $kontrak = $record->id_kontrak_vendor !== null
            ? $this->repo->findKontrakMilikPerusahaan($record->id_kontrak_vendor, $idPerusahaan)
            : null;
        $totalDibayar = round($this->repo->totalDibayar($record->id_invoice_vendor), 2);

        $pembayaran = array_map(static fn (object $row) => [
            'id_pembayaran_vendor' => $row->id_pembayaran_vendor,
            'tanggal_bayar'        => substr((string) $row->tanggal_bayar, 0, 10),
            'nominal'              => (float) $row->nominal,
            'metode'               => $row->metode,
            'bank_pengirim'        => $row->bank_pengirim,
            'no_referensi'         => $row->no_referensi,
            'url_bukti'            => PenyimpananBerkas::url($row->url_bukti),
            'catatan'              => $row->catatan,
        ], $this->repo->daftarPembayaran($record->id_invoice_vendor));

        return [
            'id_invoice_vendor'  => $record->id_invoice_vendor,
            'id_perusahaan'      => $record->id_perusahaan,
            'id_vendor'          => $record->id_vendor,
            'id_kontrak_vendor'  => $record->id_kontrak_vendor,
            'nomor_invoice'      => $record->nomor_invoice,
            'tanggal_invoice'    => $record->tanggal_invoice?->toDateString(),
            'jatuh_tempo'        => $record->jatuh_tempo?->toDateString(),
            'no_po'              => $record->no_po,
            'no_kontrak'         => $record->no_kontrak,
            'nopol'              => $record->nopol,
            'tipe_kendaraan'     => $record->tipe_kendaraan,
            'tipe_pembayaran'    => $record->tipe_pembayaran,
            'top_hari'           => $record->top_hari,
            'dpp'                => (float) $record->dpp,
            'ppn'                => (float) $record->ppn,
            'pph'                => (float) $record->pph,
            'total'              => (float) $record->total,
            'status'             => $record->status,
            'catatan_verifikasi' => $record->catatan_verifikasi,
            'diverifikasi_oleh'  => $record->diverifikasi_oleh,
            'diverifikasi_pada'  => $record->diverifikasi_pada?->toIso8601String(),
            'status_pembayaran'  => $record->status_pembayaran,
            'keterangan'         => $record->keterangan,
            'dibuat_pada'        => $record->dibuat_pada,
            'diubah_pada'        => $record->diubah_pada,
            'vendor'             => $vendor !== null
                ? ['id_vendor' => $vendor->id_vendor, 'nama_vendor' => $vendor->nama_vendor]
                : null,
            'kontrak'            => $kontrak !== null
                ? ['id_kontrak_vendor' => $kontrak->id_kontrak_vendor, 'nomor_kontrak' => $kontrak->nomor_kontrak]
                : null,
            'total_dibayar'      => $totalDibayar,
            'sisa'               => round(max(0, (float) $record->total - $totalDibayar), 2),
            'pembayaran'         => $pembayaran,
        ];
    }

    public function monitoring(string $idPerusahaan): array
    {
        $rows = $this->repo->outstandingUntukMonitoring($idPerusahaan);
        $today = Carbon::today();

        $aging = [
            'belum_jatuh_tempo' => ['jumlah' => 0, 'nominal' => 0.0],
            'hari_1_30'         => ['jumlah' => 0, 'nominal' => 0.0],
            'hari_31_60'        => ['jumlah' => 0, 'nominal' => 0.0],
            'di_atas_60'        => ['jumlah' => 0, 'nominal' => 0.0],
        ];
        $outstanding = [];
        $totalOutstanding = 0.0;

        foreach ($rows as $row) {
            $total = (float) $row->total;
            $dibayar = (float) $row->dibayar;
            $sisa = round(max(0, $total - $dibayar), 2);

            $hariTerlambat = 0;
            if ($row->jatuh_tempo !== null) {
                $jatuhTempo = Carbon::parse((string) $row->jatuh_tempo)->startOfDay();
                if ($jatuhTempo->lt($today)) {
                    $hariTerlambat = (int) round(abs($jatuhTempo->diffInDays($today)));
                }
            }

            $bucket = match (true) {
                $hariTerlambat <= 0 => 'belum_jatuh_tempo',
                $hariTerlambat <= 30 => 'hari_1_30',
                $hariTerlambat <= 60 => 'hari_31_60',
                default => 'di_atas_60',
            };

            $aging[$bucket]['jumlah']++;
            $aging[$bucket]['nominal'] = round($aging[$bucket]['nominal'] + $sisa, 2);
            $totalOutstanding = round($totalOutstanding + $sisa, 2);

            $outstanding[] = [
                'id_invoice_vendor' => $row->id_invoice_vendor,
                'nomor_invoice'     => $row->nomor_invoice,
                'vendor_nama'       => $row->vendor_nama,
                'tanggal_invoice'   => substr((string) $row->tanggal_invoice, 0, 10),
                'jatuh_tempo'       => $row->jatuh_tempo !== null ? substr((string) $row->jatuh_tempo, 0, 10) : null,
                'total'             => $total,
                'dibayar'           => round($dibayar, 2),
                'sisa'              => $sisa,
                'hari_terlambat'    => $hariTerlambat,
                'status_pembayaran' => $row->status_pembayaran,
            ];
        }

        return [
            'ringkasan' => [
                'total_outstanding' => $totalOutstanding,
                'jumlah_invoice'    => count($rows),
                'aging'             => $aging,
            ],
            'outstanding' => $outstanding,
        ];
    }

    public function create(string $idPerusahaan, array $data): InvoiceVendorModel
    {
        $this->pastikanVendorMilikPerusahaan($data['id_vendor'], $idPerusahaan);
        $kontrak = $this->validasiKontrak($data['id_kontrak_vendor'] ?? null, $data['id_vendor'], $idPerusahaan);

        if ($this->repo->nomorSudahDipakai($data['nomor_invoice'], $idPerusahaan)) {
            abort(409, 'Nomor invoice sudah digunakan');
        }

        $dpp = (float) $data['dpp'];
        $ppn = (float) ($data['ppn'] ?? 0);
        $pph = (float) ($data['pph'] ?? 0);

        $data['dpp'] = $dpp;
        $data['ppn'] = $ppn;
        $data['pph'] = $pph;
        $data['total'] = $this->hitungTotal($dpp, $ppn, $pph);

        if (empty($data['jatuh_tempo']) && $kontrak !== null && !empty($kontrak->termin_pembayaran_hari)) {
            $data['jatuh_tempo'] = Carbon::parse($data['tanggal_invoice'])
                ->addDays((int) $kontrak->termin_pembayaran_hari)
                ->toDateString();
        }

        return $this->repo->create(array_merge($data, [
            'id_perusahaan'     => $idPerusahaan,
            'status'            => 'draft',
            'status_pembayaran' => 'belum',
        ]));
    }

    public function update(string $id, string $idPerusahaan, array $data): InvoiceVendorModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (!in_array($record->status, self::STATUS_BISA_DIUBAH, true)) {
            abort(409, 'Invoice tidak dapat diubah pada status ini');
        }

        $idVendorEfektif = $data['id_vendor'] ?? $record->id_vendor;
        if (array_key_exists('id_vendor', $data)) {
            $this->pastikanVendorMilikPerusahaan($idVendorEfektif, $idPerusahaan);
        }

        $idKontrakEfektif = array_key_exists('id_kontrak_vendor', $data)
            ? $data['id_kontrak_vendor']
            : $record->id_kontrak_vendor;

        // Validasi kontrak hanya saat referensinya diganti — kontrak lama yang
        // sudah terlanjur di-soft-delete tidak boleh memblokir edit invoice.
        $kontrakBerubah = array_key_exists('id_kontrak_vendor', $data)
            && $data['id_kontrak_vendor'] !== $record->id_kontrak_vendor;
        $vendorBerubah = array_key_exists('id_vendor', $data)
            && $data['id_vendor'] !== $record->id_vendor;

        if ($kontrakBerubah || ($vendorBerubah && !empty($idKontrakEfektif))) {
            $kontrak = $this->validasiKontrak($idKontrakEfektif, $idVendorEfektif, $idPerusahaan);
        } else {
            $kontrak = !empty($idKontrakEfektif)
                ? $this->repo->findKontrakMilikPerusahaan($idKontrakEfektif, $idPerusahaan)
                : null;
        }

        if (isset($data['nomor_invoice'])
            && $data['nomor_invoice'] !== $record->nomor_invoice
            && $this->repo->nomorSudahDipakai($data['nomor_invoice'], $idPerusahaan, $record->id_invoice_vendor)
        ) {
            abort(409, 'Nomor invoice sudah digunakan');
        }

        $dpp = array_key_exists('dpp', $data) ? (float) $data['dpp'] : (float) $record->dpp;
        $ppn = array_key_exists('ppn', $data) ? (float) ($data['ppn'] ?? 0) : (float) $record->ppn;
        $pph = array_key_exists('pph', $data) ? (float) ($data['pph'] ?? 0) : (float) $record->pph;

        $data['dpp'] = $dpp;
        $data['ppn'] = $ppn;
        $data['pph'] = $pph;
        $data['total'] = $this->hitungTotal($dpp, $ppn, $pph);

        $jatuhTempoEfektif = array_key_exists('jatuh_tempo', $data)
            ? $data['jatuh_tempo']
            : $record->jatuh_tempo?->toDateString();
        if (empty($jatuhTempoEfektif) && $kontrak !== null && !empty($kontrak->termin_pembayaran_hari)) {
            $tanggalEfektif = $data['tanggal_invoice'] ?? $record->tanggal_invoice->toDateString();
            $data['jatuh_tempo'] = Carbon::parse($tanggalEfektif)
                ->addDays((int) $kontrak->termin_pembayaran_hari)
                ->toDateString();
        }

        if ($record->status === 'ditolak') {
            $data['status'] = 'draft';
            $data['catatan_verifikasi'] = null;
        }

        return $this->repo->update($record, $data);
    }

    public function ajukanApproval(string $id, string $idPengguna, string $idPerusahaan): InvoiceVendorModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($record->status !== 'draft') {
            abort(422, 'Hanya invoice berstatus draft yang bisa diajukan approval');
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($id, $idPerusahaan, $idPengguna) {
            $terkunci = $this->repo->findForUpdate($id);
            if ($terkunci === null || $terkunci->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Invoice vendor tidak ditemukan');
            }
            if ($terkunci->status !== 'draft') {
                abort(422, 'Hanya invoice berstatus draft yang bisa diajukan approval');
            }

            app(\App\Modules\Approval\ApprovalService::class)->ajukan(
                'invoice_vendor',
                $id,
                $idPengguna,
                (float) $terkunci->total,
                $idPerusahaan,
            );

            return $this->repo->update($terkunci, [
                'status'             => 'menunggu_approval',
                'catatan_verifikasi' => null,
            ]);
        });
    }

    public function terapkanKeputusanApproval(string $idInvoice, string $idPerusahaan, string $idPengguna, string $keputusan, ?string $alasanDitolak): void
    {
        $record = $this->repo->findByIdUntukPerusahaan($idInvoice, $idPerusahaan);
        if ($record === null) {
            \Illuminate\Support\Facades\Log::warning("InvoiceVendorApprovalListener: invoice {$idInvoice} tidak ditemukan atau beda perusahaan");
            return;
        }
        if ($record->status !== 'menunggu_approval') {
            return;
        }

        if ($keputusan === 'ditolak') {
            $this->repo->update($record, [
                'status'             => 'ditolak',
                'catatan_verifikasi' => $alasanDitolak,
            ]);
            return;
        }

        $payload = [
            'status'            => 'diverifikasi',
            'diverifikasi_oleh' => $idPengguna,
            'diverifikasi_pada' => now(),
        ];
        if ((float) $record->total <= 0) {
            $payload['status_pembayaran'] = 'lunas';
        }
        $this->repo->update($record, $payload);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (!in_array($record->status, self::STATUS_BISA_DIUBAH, true)) {
            abort(409, 'Invoice tidak dapat dihapus pada status ini');
        }

        $this->repo->delete($record);
    }

    private function pastikanVendorMilikPerusahaan(string $idVendor, string $idPerusahaan): void
    {
        if (!$this->repo->vendorMilikPerusahaan($idVendor, $idPerusahaan)) {
            abort(404, 'Vendor tidak ditemukan');
        }
    }

    private function validasiKontrak(?string $idKontrak, string $idVendor, string $idPerusahaan): ?object
    {
        if (empty($idKontrak)) {
            return null;
        }

        $kontrak = $this->repo->findKontrakMilikPerusahaan($idKontrak, $idPerusahaan);
        if ($kontrak === null) {
            abort(404, 'Kontrak vendor tidak ditemukan');
        }
        if ((string) $kontrak->id_vendor !== (string) $idVendor) {
            abort(422, 'Kontrak tidak dimiliki vendor yang dipilih');
        }

        return $kontrak;
    }

    private function hitungTotal(float $dpp, float $ppn, float $pph): float
    {
        return round(max(0, $dpp + $ppn - $pph), 2);
    }
}
