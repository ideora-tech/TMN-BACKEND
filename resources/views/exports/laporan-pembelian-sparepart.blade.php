<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Pembelian Sparepart</title>
    <style>
        @page { margin: 0; }
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; margin: 0; }

        .kop { position: relative; height: 105px; }
        .kop-band { position: absolute; top: 0; left: 0; right: 0; height: 16px; background: #0e7490; }
        .kop-band-navy { position: absolute; top: 16px; left: 55%; right: 0; height: 7px; background: #1e2a5a; }
        .kop-isi { padding: 30px 45px 0 45px; }
        .kop-isi table { border-collapse: collapse; }
        .kop-isi td { vertical-align: middle; padding: 0; }
        .kop-logo { width: 58px; }
        .kop-logo img { width: 50px; height: 50px; }
        .kop-teks { padding-left: 14px; }
        .logo-utama { font-size: 24px; font-weight: bold; color: #0e7490; letter-spacing: 1px; line-height: 1.1; }
        .logo-sub { font-size: 9px; color: #1e2a5a; letter-spacing: 3px; margin-top: 2px; }

        .konten { padding: 6px 45px 100px 45px; }

        .judul { text-align: center; margin: 14px 0 2px; }
        .judul h1 { font-size: 17px; letter-spacing: 4px; color: #1e2a5a; margin: 0; }
        .judul .garis { width: 300px; border-bottom: 2.5px solid #0e7490; margin: 5px auto 0; }
        .periode { text-align: center; color: #4b5563; margin: 6px 0 16px; font-size: 11px; }

        .section { font-size: 12px; font-weight: bold; color: #1e2a5a; margin: 16px 0 6px; }

        table.ringkasan { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        table.ringkasan td { width: 25%; border: 1px solid #e5e7eb; padding: 8px 10px; vertical-align: top; }
        table.ringkasan .label { color: #6b7280; font-size: 9.5px; text-transform: uppercase; letter-spacing: 1px; }
        table.ringkasan .nilai { font-size: 13px; font-weight: bold; margin-top: 3px; }

        table.rincian { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.rincian th { background: #0e7490; color: #ffffff; padding: 6px 9px; text-align: left; font-size: 10.5px; }
        table.rincian th.jumlah { text-align: right; }
        table.rincian td { padding: 5.5px 9px; border-bottom: 1px solid #e5e7eb; }
        table.rincian td.jumlah { text-align: right; white-space: nowrap; }
        table.rincian tr.subtotal td { font-weight: bold; background: #f0fdfa; border-top: 1.5px solid #0e7490; }
        .ket { color: #9ca3af; font-size: 9.5px; }

        .cetak { margin-top: 14px; color: #9ca3af; font-size: 9px; }

        .footer { position: fixed; bottom: 0; left: 0; right: 0; height: 68px; }
        .footer-band { height: 6px; background: #0e7490; }
        .footer-isi { padding: 10px 45px; font-size: 9.5px; color: #1e2a5a; }
        .footer-isi td { padding-right: 22px; }
    </style>
</head>
<body>
    @php
        $rp = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
        $ringkasan   = $laporan['ringkasan'];
        $perBulan    = collect($laporan['per_bulan']);
        $perKategori = collect($laporan['per_kategori']);
        $perArmada   = collect($laporan['per_armada']);
    @endphp

    <div class="kop">
        <div class="kop-band"></div>
        <div class="kop-band-navy"></div>
        <div class="kop-isi">
            <table>
                <tr>
                    @if ($logoBase64)
                        <td class="kop-logo"><img src="{{ $logoBase64 }}" alt="Logo"></td>
                    @endif
                    <td class="kop-teks">
                        <div class="logo-utama">SULITA</div>
                        <div class="logo-sub">LOGISTIK INDONESIA</div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <div class="konten">
        <div class="judul">
            <h1>LAPORAN PEMBELIAN SPAREPART</h1>
            <div class="garis"></div>
        </div>
        <p class="periode">
            Periode: {{ $dari && $sampai ? date('d/m/Y', strtotime($dari)) . ' — ' . date('d/m/Y', strtotime($sampai)) : 'Semua' }}
        </p>

        <table class="ringkasan">
            <tr>
                <td>
                    <div class="label">Total Estimasi</div>
                    <div class="nilai">{{ $rp($ringkasan['total_estimasi']) }}</div>
                </td>
                <td>
                    <div class="label">Total Aktual</div>
                    <div class="nilai">{{ $rp($ringkasan['total_aktual']) }}</div>
                </td>
                <td>
                    <div class="label">Selisih</div>
                    <div class="nilai">{{ $rp($ringkasan['selisih']) }}</div>
                </td>
                <td>
                    <div class="label">Jumlah Pembelian</div>
                    <div class="nilai">{{ $ringkasan['jumlah'] }}</div>
                </td>
            </tr>
        </table>

        <div class="section">PER BULAN</div>
        <table class="rincian">
            <thead>
                <tr>
                    <th>BULAN</th>
                    <th class="jumlah">TOTAL ESTIMASI</th>
                    <th class="jumlah">TOTAL AKTUAL</th>
                    <th class="jumlah">JUMLAH</th>
                </tr>
            </thead>
            <tbody>
                @forelse($perBulan as $b)
                <tr>
                    <td>{{ date('M Y', strtotime($b->bulan . '-01')) }}</td>
                    <td class="jumlah">{{ $rp($b->total_estimasi) }}</td>
                    <td class="jumlah">{{ $rp($b->total_aktual) }}</td>
                    <td class="jumlah">{{ $b->jumlah }}</td>
                </tr>
                @empty
                <tr><td colspan="4" class="ket" style="text-align:center">Tidak ada data pembelian pada periode ini</td></tr>
                @endforelse
                @if($perBulan->isNotEmpty())
                <tr class="subtotal">
                    <td>TOTAL</td>
                    <td class="jumlah">{{ $rp($perBulan->sum(fn ($b) => (float) $b->total_estimasi)) }}</td>
                    <td class="jumlah">{{ $rp($perBulan->sum(fn ($b) => (float) $b->total_aktual)) }}</td>
                    <td class="jumlah">{{ $perBulan->sum(fn ($b) => (int) $b->jumlah) }}</td>
                </tr>
                @endif
            </tbody>
        </table>

        <div class="section">PER KATEGORI SPAREPART</div>
        <table class="rincian">
            <thead>
                <tr>
                    <th>KATEGORI</th>
                    <th class="jumlah">TOTAL AKTUAL</th>
                </tr>
            </thead>
            <tbody>
                @forelse($perKategori as $k)
                <tr>
                    <td>{{ $k->kategori }}</td>
                    <td class="jumlah">{{ $rp($k->total_aktual) }}</td>
                </tr>
                @empty
                <tr><td colspan="2" class="ket" style="text-align:center">Tidak ada data kategori pada periode ini</td></tr>
                @endforelse
                @if($perKategori->isNotEmpty())
                <tr class="subtotal">
                    <td>TOTAL</td>
                    <td class="jumlah">{{ $rp($perKategori->sum(fn ($k) => (float) $k->total_aktual)) }}</td>
                </tr>
                @endif
            </tbody>
        </table>

        <div class="section">PER ARMADA</div>
        <table class="rincian">
            <thead>
                <tr>
                    <th>NOPOL</th>
                    <th class="jumlah">TOTAL AKTUAL</th>
                    <th class="jumlah">JUMLAH PEMBELIAN</th>
                </tr>
            </thead>
            <tbody>
                @forelse($perArmada as $a)
                <tr>
                    <td><strong>{{ $a->nopol }}</strong></td>
                    <td class="jumlah">{{ $rp($a->total_aktual) }}</td>
                    <td class="jumlah">{{ $a->jumlah }}</td>
                </tr>
                @empty
                <tr><td colspan="3" class="ket" style="text-align:center">Tidak ada pembelian terkait armada pada periode ini</td></tr>
                @endforelse
                @if($perArmada->isNotEmpty())
                <tr class="subtotal">
                    <td>TOTAL</td>
                    <td class="jumlah">{{ $rp($perArmada->sum(fn ($a) => (float) $a->total_aktual)) }}</td>
                    <td class="jumlah">{{ $perArmada->sum(fn ($a) => (int) $a->jumlah) }}</td>
                </tr>
                @endif
            </tbody>
        </table>

        <p class="cetak">Dicetak {{ now()->format('d/m/Y H:i') }} — dokumen ini dihasilkan otomatis oleh sistem.</p>
    </div>

    <div class="footer">
        <div class="footer-band"></div>
        <div class="footer-isi">
            <table>
                <tr>
                    @if ($perusahaan->telepon ?? null)<td>Telp: {{ $perusahaan->telepon }}</td>@endif
                    @if ($perusahaan->email ?? null)<td>{{ $perusahaan->email }}</td>@endif
                    @if ($perusahaan->alamat ?? null)<td>{{ $perusahaan->alamat }}</td>@endif
                </tr>
            </table>
        </div>
    </div>
</body>
</html>
