<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\ArusKas\ArusKasService;
use Illuminate\Console\Command;

class MigrasiApprovalPending extends Command
{
    protected $signature = 'arus-kas:migrasi-approval-pending';

    protected $description = 'Sweep pengajuan pengeluaran berstatus diajukan/dicek (legacy pra-deploy) ke engine approval urutan baru';

    public function handle(ArusKasService $service): int
    {
        $hasil = $service->migrasiApprovalPending();

        $this->info("Total baris diproses: {$hasil['total']}");
        foreach ($hasil['ringkasan'] as $status => $jumlah) {
            $this->line("- {$status}: {$jumlah}");
        }

        return self::SUCCESS;
    }
}
