<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idMenuApprovalKeuangan = 'm0000001-0000-4000-8000-000000000091';
    private string $idPengaturan           = 'm0000001-0000-4000-8000-000000000040';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu'       => $this->idMenuApprovalKeuangan,
                'nama_menu'     => 'Konfigurasi Approval',
                'path'          => '/konfigurasi-approval',
                'icon'          => 'userCheck',
                'id_menu_induk' => $this->idPengaturan,
                'urutan'        => 7,
                'aktif'         => 1,
                'dibuat_pada'   => $now,
                'dibuat_oleh'   => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);
    }

    public function down(): void
    {
        DB::table('menu')->where('id_menu', $this->idMenuApprovalKeuangan)->update([
            'nama_menu' => 'Approval Keuangan',
            'path'      => '/approval-keuangan',
        ]);
    }
};
