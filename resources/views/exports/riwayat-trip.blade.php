<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Riwayat Trip</title>
    <style>
        @page { margin: 0; }
        body { font-family: sans-serif; font-size: 10px; color: #1f2937; margin: 0; }

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
        .judul .garis { width: 290px; border-bottom: 2.5px solid #0e7490; margin: 5px auto 0; }
        .periode { text-align: center; color: #4b5563; margin: 6px 0 16px; font-size: 11px; }

        table.rincian { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.rincian th { background: #0e7490; color: #ffffff; padding: 5px 6px; text-align: left; font-size: 9.5px; }
        table.rincian th.jumlah { text-align: right; }
        table.rincian td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; }
        table.rincian td.jumlah { text-align: right; white-space: nowrap; }
        .ket { color: #9ca3af; font-size: 9.5px; }

        .cetak { margin-top: 14px; color: #9ca3af; font-size: 9px; }

        .footer { position: fixed; bottom: 0; left: 0; right: 0; height: 68px; }
        .footer-band { height: 6px; background: #0e7490; }
        .footer-isi { padding: 10px 45px; font-size: 9.5px; color: #1e2a5a; }
        .footer-isi td { padding-right: 22px; }
    </style>
</head>
<body>
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
            <h1>RIWAYAT TRIP</h1>
            <div class="garis"></div>
        </div>
        <p class="periode">{{ $periode }}</p>

        <table class="rincian">
            <thead>
                <tr>
                    <th>BERANGKAT</th>
                    <th>SELESAI</th>
                    <th>PROYEK</th>
                    <th>KODE PROYEK</th>
                    <th>RUTE</th>
                    <th>SUPIR</th>
                    <th>ARMADA</th>
                    <th>SUMBER</th>
                    <th>STATUS</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                <tr>
                    <td>{{ $item->waktu_berangkat ? \Illuminate\Support\Carbon::parse($item->waktu_berangkat)->format('d/m/Y H:i') : '-' }}</td>
                    <td>{{ $item->waktu_checkout ? \Illuminate\Support\Carbon::parse($item->waktu_checkout)->format('d/m/Y H:i') : '-' }}</td>
                    <td>{{ $item->nama_proyek ?? '-' }}</td>
                    <td>{{ $item->kode_proyek ?? '-' }}</td>
                    <td>{{ $item->rute ?? '-' }}</td>
                    <td>{{ $item->supir_nama ?? '-' }}</td>
                    <td>{{ $item->armada_nopol ?? '-' }}</td>
                    <td>{{ ($item->sumber ?? 'internal') === 'vendor' ? 'Vendor' : 'Internal' }}</td>
                    <td>{{ ucfirst((string) $item->status) }}</td>
                </tr>
                @empty
                <tr><td colspan="9" class="ket" style="text-align:center">Belum ada trip pada periode ini</td></tr>
                @endforelse
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
