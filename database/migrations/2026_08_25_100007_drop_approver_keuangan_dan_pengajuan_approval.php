<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pengajuan_approval');
        Schema::dropIfExists('approver_keuangan');
    }

    public function down(): void
    {
        // Searah — data sudah dimigrasi ke tabel approval generik di migration
        // 2026_08_25_100006 sebelum tabel ini di-drop, jadi tidak ada yang perlu
        // dikembalikan di sini.
    }
};
