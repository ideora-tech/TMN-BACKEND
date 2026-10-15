<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\KomponenUangJalan\KomponenUangJalanService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class KomponenUangJalanSeeder extends Seeder
{
    public function run(KomponenUangJalanService $service): void
    {
        foreach (DB::table('perusahaan')->whereNull('dihapus_pada')->pluck('id_perusahaan') as $idPerusahaan) {
            $service->pastikanBawaan((string) $idPerusahaan);
        }
    }
}
