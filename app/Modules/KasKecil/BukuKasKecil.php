<?php

declare(strict_types=1);

namespace App\Modules\KasKecil;

use App\Modules\KasKecil\Contracts\KasKecilRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pencatatan ke Buku Kas Kecil yang dipakai lintas modul.
 *
 * Aturan sumber dana: Arus Kas = kas bank, yang hanya mengeluarkan dana untuk
 * pengisian Kas Kecil. Semua pengajuan biaya & pembayaran vendor dibayar dari
 * Kas Kecil, jadi saat dibayar dicatat di sini sebagai baris keluar.
 *
 * Dipisah dari KasKecilService karena KasKecilService sudah bergantung pada
 * ArusKasService (pengisian membuat pengajuan); ArusKasService & PembayaranVendor
 * memakai kelas ini tanpa ketergantungan melingkar.
 */
class BukuKasKecil
{
    public const SUMBER_REKENING  = 'rekening';
    public const SUMBER_KAS_KECIL = 'kas_kecil';

    private const TOLERANSI = 0.004;

    public function __construct(private readonly KasKecilRepositoryInterface $repo) {}

    /**
     * Catat pembayaran keluar dari Kas Kecil. Wajib dipanggil di dalam transaksi DB
     * pemanggil: bila saldo tidak cukup dilempar ValidationException dan seluruh
     * transaksi (termasuk perubahan status pengajuan) ikut dibatalkan.
     *
     * @param array{id_pengajuan?: string, id_pembayaran_vendor?: string} $referensi
     */
    public function catatPembayaran(
        string $idPerusahaan,
        string $tanggal,
        float $nominal,
        ?string $penerima,
        string $keterangan,
        ?string $urlBukti,
        array $referensi,
    ): object {
        $tanggal = Carbon::parse($tanggal)->toDateString();

        $this->repo->kunciPerusahaan($idPerusahaan);

        $record = $this->repo->createTransaksi(array_merge([
            'id_perusahaan'   => $idPerusahaan,
            'nomor_transaksi' => $this->repo->nomorTransaksiBerikutnya($idPerusahaan),
            'tanggal'         => $tanggal,
            'arah'            => 'keluar',
            'jenis'           => KasKecilService::JENIS_PEMBAYARAN,
            'nominal'         => round($nominal, 2),
            'penerima'        => $penerima !== null ? Str::limit($penerima, 150, '') : null,
            'keterangan'      => Str::limit($keterangan, 255, ''),
            'url_bukti'       => $urlBukti,
        ], $referensi));

        $this->pastikanSaldoCukup($idPerusahaan, $tanggal);

        return $record;
    }

    /** Pembayaran vendor dihapus → baris Kas Kecil-nya ikut dihapus (saldo kembali). */
    public function hapusPembayaranVendor(string $idPembayaranVendor): void
    {
        $record = $this->repo->findTransaksiByPembayaranVendor($idPembayaranVendor);
        if ($record !== null) {
            $this->repo->deleteTransaksi($record);
        }
    }

    public function pastikanSaldoCukup(string $idPerusahaan, string $dariTanggal): void
    {
        $kurang = $this->titikMinus($idPerusahaan, $dariTanggal);
        if ($kurang === null) {
            return;
        }

        throw ValidationException::withMessages([
            'nominal' => 'Saldo petty cash tidak cukup per ' . Carbon::parse($kurang['tanggal'])->format('d/m/Y')
                . ' — kurang Rp ' . number_format($kurang['kurang'], 0, ',', '.')
                . '. Ajukan pengisian petty cash atau periksa tanggal dan nominalnya.',
        ]);
    }

    /** @return array{tanggal: string, kurang: float}|null */
    public function titikMinus(string $idPerusahaan, string $dariTanggal): ?array
    {
        $saldo = $this->repo->saldoSebelum($idPerusahaan, $dariTanggal, true);

        foreach ($this->repo->mutasiSejak($idPerusahaan, $dariTanggal) as $mutasi) {
            $nominal = (float) $mutasi->nominal;
            $saldo   = round($saldo + ($mutasi->arah === 'masuk' ? $nominal : -$nominal), 2);

            if ($saldo < -self::TOLERANSI) {
                return ['tanggal' => substr((string) $mutasi->tanggal, 0, 10), 'kurang' => abs($saldo)];
            }
        }

        return null;
    }
}
