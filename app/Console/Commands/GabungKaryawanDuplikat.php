<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Karyawan\KaryawanGabungDuplikatService;
use Illuminate\Console\Command;

class GabungKaryawanDuplikat extends Command
{
    protected $signature = 'karyawan:gabung-duplikat
        {--commit : Jalankan penggabungan. Tanpa opsi ini hanya simulasi, tidak ada data yang berubah}
        {--nama=* : Batasi pada nama tertentu (cocok sebagian, tidak peka huruf besar/kecil, boleh diulang)}
        {--perusahaan= : Batasi pada satu id_perusahaan}
        {--status=tetap : status_kepegawaian untuk record yang dipertahankan}';

    protected $description = 'Gabungkan karyawan dobel ber-NIK tidak lengkap (DRV-xxxx dst) dengan record ber-NIK 16 digit. Default simulasi';

    public function handle(KaryawanGabungDuplikatService $service): int
    {
        $status = (string) $this->option('status');
        if (!in_array($status, KaryawanGabungDuplikatService::STATUS_KEPEGAWAIAN, true)) {
            $this->error('Opsi --status harus salah satu dari: ' . implode(', ', KaryawanGabungDuplikatService::STATUS_KEPEGAWAIAN));

            return self::INVALID;
        }

        $nama       = array_values(array_filter((array) $this->option('nama'), static fn ($n) => trim((string) $n) !== ''));
        $perusahaan = $this->option('perusahaan') !== null && $this->option('perusahaan') !== '' ? (string) $this->option('perusahaan') : null;
        $rencana    = $service->rencana($nama === [] ? null : $nama, $perusahaan);

        foreach ($rencana['pasangan'] as $pasangan) {
            $this->line('');
            $this->info($pasangan['nama']);
            $this->line("  Dipertahankan : {$pasangan['dipertahankan']->nik} → {$pasangan['nik_akhir']}  (relasi: " . $this->relasi($pasangan['relasi_dipertahankan']) . ')');
            $this->line("  Dihapus       : {$pasangan['duplikat']->nik}  (relasi: " . $this->relasi($pasangan['relasi_duplikat']) . ')');
            $this->line('  Status        : ' . $status);
            $this->line('  Data disalin  : ' . ($pasangan['salin'] === [] ? '-' : implode(', ', array_keys($pasangan['salin']))));
            if ($pasangan['nama_baru'] !== null) {
                $this->line("  Nama          : {$pasangan['dipertahankan']->nama_karyawan} → {$pasangan['nama_baru']}");
            }
            foreach ($pasangan['catatan'] as $catatan) {
                $this->warn('  Catatan       : ' . $catatan);
            }
        }

        if ($rencana['dilewati'] !== []) {
            $this->line('');
            $this->warn('Dilewati (perlu ditangani manual):');
            foreach ($rencana['dilewati'] as $lewat) {
                $this->line("  - {$lewat['nama']}: {$lewat['alasan']}");
            }
        }

        $jumlah = count($rencana['pasangan']);
        $this->line('');
        if ($jumlah === 0) {
            $this->info('Tidak ada pasangan yang bisa digabung.');

            return self::SUCCESS;
        }

        if (!$this->option('commit')) {
            $this->info("Simulasi: {$jumlah} pasangan akan digabung. Tidak ada data yang diubah. Jalankan ulang dengan --commit untuk mengeksekusi.");

            return self::SUCCESS;
        }

        $berhasil = 0;
        $gagal    = 0;
        foreach ($rencana['pasangan'] as $pasangan) {
            try {
                $service->gabung($pasangan, $status);
                $berhasil++;
                $this->info("OK    {$pasangan['nama']}");
            } catch (\Throwable $e) {
                $gagal++;
                $this->error("GAGAL {$pasangan['nama']}: {$e->getMessage()}");
            }
        }

        $this->line('');
        $this->info("Selesai: {$berhasil} digabung, {$gagal} gagal.");

        return $gagal === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function relasi(array $relasi): string
    {
        if ($relasi === []) {
            return 'tidak ada';
        }
        $bagian = [];
        foreach ($relasi as $tabel => $jumlah) {
            $bagian[] = "{$tabel} {$jumlah}";
        }

        return implode(', ', $bagian);
    }
}
