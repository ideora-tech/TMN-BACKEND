<?php

declare(strict_types=1);

namespace App\Modules\WajahReferensi;

use App\Modules\WajahReferensi\Contracts\WajahReferensiRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Support\Facades\DB;

class WajahReferensiRepository implements WajahReferensiRepositoryInterface
{
    public function findByPengguna(string $idPengguna): ?object
    {
        return DB::table('wajah_referensi')
            ->whereNull('dihapus_pada')
            ->where('id_pengguna', $idPengguna)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_wajah');
        DB::table('wajah_referensi')->insert($data);
        return DB::table('wajah_referensi')->where('id_wajah', $data['id_wajah'])->first();
    }

    public function delete(string $idPengguna): void
    {
        DB::table('wajah_referensi')
            ->whereNull('dihapus_pada')
            ->where('id_pengguna', $idPengguna)
            ->update(RecordHelper::stampDelete());
    }
}
