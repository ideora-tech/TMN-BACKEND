<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $idMenuSupplier     = 'm0000001-0000-4000-8000-000000000085';
    private string $idMenuPembelian    = 'm0000001-0000-4000-8000-000000000086';
    private string $idGrupPemeliharaan = 'm0000001-0000-4000-8000-000000000080';
    private string $idGrupPengadaan    = 'm0000001-0000-4000-8000-000000000098';
    private string $idMenuPermintaan   = 'm0000001-0000-4000-8000-000000000099';

    private array $aksiDisalin = ['lihat', 'tambah', 'ubah', 'hapus'];

    public function up(): void
    {
        DB::table('menu')->where('id_menu', $this->idMenuSupplier)->update([
            'id_menu_induk' => $this->idGrupPengadaan,
            'urutan'        => 4,
        ]);

        $now = now();
        $sumber = DB::table('izin_peran')
            ->whereNull('dihapus_pada')
            ->where('id_menu', $this->idMenuPembelian)
            ->where('diizinkan', 1)
            ->whereIn('aksi', $this->aksiDisalin)
            ->get(['id_perusahaan', 'kode_peran', 'aksi']);

        $kodePeranUnik = [];
        foreach ($sumber as $baris) {
            $kodePeranUnik[$baris->kode_peran] = true;

            $ada = DB::table('izin_peran')
                ->where('id_menu', $this->idMenuPermintaan)
                ->where('kode_peran', $baris->kode_peran)
                ->where('aksi', $baris->aksi)
                ->where(function ($q) use ($baris) {
                    if ($baris->id_perusahaan === null) {
                        $q->whereNull('id_perusahaan');
                    } else {
                        $q->where('id_perusahaan', $baris->id_perusahaan);
                    }
                })
                ->exists();
            if ($ada) {
                continue;
            }
            DB::table('izin_peran')->insert([
                'id_izin'       => (string) Str::uuid(),
                'id_perusahaan' => $baris->id_perusahaan,
                'kode_peran'    => $baris->kode_peran,
                'id_menu'       => $this->idMenuPermintaan,
                'aksi'          => $baris->aksi,
                'diizinkan'     => 1,
                'dibuat_pada'   => $now,
            ]);
        }

        if ($kodePeranUnik !== []) {
            $menuPeran = [];
            foreach (array_keys($kodePeranUnik) as $kodePeran) {
                $menuPeran[] = ['id_menu' => $this->idMenuPermintaan, 'kode_peran' => $kodePeran];
                $menuPeran[] = ['id_menu' => $this->idGrupPengadaan, 'kode_peran' => $kodePeran];
            }
            DB::table('menu_peran')->insertOrIgnore($menuPeran);
        }
    }

    public function down(): void
    {
        DB::table('menu')->where('id_menu', $this->idMenuSupplier)->update([
            'id_menu_induk' => $this->idGrupPemeliharaan,
            'urutan'        => 10,
        ]);
    }
};
