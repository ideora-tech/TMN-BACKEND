<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Data contoh servis rutin: catatan perawatan yang TERTAUT paket servis
// (interval_perawatan) supaya Papan Unit menampilkan campuran label
// lewat jatuh tempo / segera / aman. Manual-only, aman diulang.
class PerawatanRutinDummySeeder extends Seeder
{
    private const PENANDA = '[DUMMY SERVIS RUTIN]';

    public function run(): void
    {
        $idPerusahaan = DB::table('armada')
            ->whereNull('dihapus_pada')
            ->whereNotNull('id_jenis_kendaraan')
            ->where('id_perusahaan', '<>', '')
            ->selectRaw('id_perusahaan, count(*) as jml')
            ->groupBy('id_perusahaan')
            ->orderByDesc('jml')
            ->value('id_perusahaan');

        if ($idPerusahaan === null) {
            $this->command->info('PerawatanRutinDummySeeder: tidak ada armada ber-jenis kendaraan — dilewati.');
            return;
        }

        $paketPerJenis = DB::table('interval_perawatan')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->whereNotNull('interval_bulan')
            ->get(['id_interval_perawatan', 'id_jenis_kendaraan', 'interval_km', 'interval_bulan'])
            ->groupBy('id_jenis_kendaraan');

        if ($paketPerJenis->isEmpty()) {
            $this->command->info('PerawatanRutinDummySeeder: belum ada paket servis ber-interval bulan — dilewati.');
            return;
        }

        $armadaList = DB::table('armada')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->where('status', '<>', 'tidak_aktif')
            ->whereNotNull('id_jenis_kendaraan')
            ->whereIn('id_jenis_kendaraan', $paketPerJenis->keys())
            ->orderBy('nopol')
            ->get(['id_armada', 'nopol', 'id_jenis_kendaraan']);

        if ($armadaList->isEmpty()) {
            $this->command->info('PerawatanRutinDummySeeder: tidak ada armada yang jenis kendaraannya punya paket servis — dilewati.');
            return;
        }

        $this->bersihkanDataLama();

        $idSupplier = DB::table('supplier')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->value('id_supplier');

        $skenario = ['lewat', 'lewat', 'lewat', 'segera', 'segera', 'segera', 'aman', 'aman', 'aman'];
        $dibuat = ['lewat' => 0, 'segera' => 0, 'aman' => 0];
        $totalSparepart = 0;
        $km = 45000;

        foreach ($armadaList->take(count($skenario)) as $i => $armada) {
            $mode  = $skenario[$i];
            $paket = $paketPerJenis[$armada->id_jenis_kendaraan]->first();
            $bulan = (int) $paket->interval_bulan;

            $tanggal = match ($mode) {
                'lewat'  => Carbon::today()->subMonths($bulan + 1),
                'segera' => Carbon::today()->subMonths($bulan)->addDays(10),
                default  => Carbon::today()->subDays(7),
            };
            $jadwal = $tanggal->copy()->addMonths($bulan);

            $idPerawatan = (string) Str::uuid();
            $km += 1500;

            DB::table('perawatan_armada')->insert([
                'id_perawatan'             => $idPerawatan,
                'id_armada'                => $armada->id_armada,
                'id_interval_perawatan'    => $paket->id_interval_perawatan,
                'id_supplier'              => $idSupplier,
                'tanggal'                  => $tanggal->toDateString(),
                'biaya'                    => 350000 + ($i * 25000),
                'km_odometer'              => $km,
                'status'                   => 'selesai',
                'jadwal_servis_berikutnya' => $jadwal->toDateString(),
                'keterangan'               => self::PENANDA . ' servis rutin ' . $armada->nopol,
                'dibuat_pada'              => now(),
            ]);

            $totalSparepart += $this->tempelSparepart($idPerawatan, (string) $paket->id_interval_perawatan);
            $dibuat[$mode]++;
        }

        $this->command->info(sprintf(
            'PerawatanRutinDummySeeder: %d catatan servis rutin dibuat (lewat=%d, segera=%d, aman=%d), %d baris sparepart.',
            array_sum($dibuat),
            $dibuat['lewat'],
            $dibuat['segera'],
            $dibuat['aman'],
            $totalSparepart,
        ));
    }

    private function bersihkanDataLama(): void
    {
        $idLama = DB::table('perawatan_armada')
            ->where('keterangan', 'like', self::PENANDA . '%')
            ->pluck('id_perawatan');

        if ($idLama->isEmpty()) {
            return;
        }

        DB::table('perawatan_sparepart')->whereIn('id_perawatan', $idLama)->delete();
        DB::table('perawatan_armada')->whereIn('id_perawatan', $idLama)->delete();
    }

    private function tempelSparepart(string $idPerawatan, string $idInterval): int
    {
        $items = DB::table('interval_perawatan_sparepart as ips')
            ->join('sparepart as s', 's.id_sparepart', '=', 'ips.id_sparepart')
            ->where('ips.id_interval_perawatan', $idInterval)
            ->whereNull('ips.dihapus_pada')
            ->whereNull('s.dihapus_pada')
            ->get(['s.id_sparepart', 's.nama', 's.harga_standar', 'ips.qty_standar']);

        foreach ($items as $item) {
            DB::table('perawatan_sparepart')->insert([
                'id_perawatan_sparepart' => (string) Str::uuid(),
                'id_perawatan'           => $idPerawatan,
                'id_sparepart'           => $item->id_sparepart,
                'nama_sparepart'         => $item->nama,
                'qty'                    => (int) $item->qty_standar,
                'harga'                  => (float) ($item->harga_standar ?? 0),
                'sumber'                 => 'bengkel',
                'dibuat_pada'            => now(),
            ]);
        }

        return $items->count();
    }
}
