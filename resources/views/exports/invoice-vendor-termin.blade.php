<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Kwitansi Pembayaran Vendor</title>
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
        .judul h1 { font-size: 17px; letter-spacing: 3px; color: #1e2a5a; margin: 0; }
        .judul .garis { width: 240px; border-bottom: 2.5px solid #0e7490; margin: 5px auto 0; }
        .periode { text-align: center; color: #4b5563; margin: 6px 0 16px; font-size: 11px; }

        table.info { width: 100%; margin-bottom: 14px; border-collapse: collapse; }
        table.info td { padding: 2.5px 0; vertical-align: top; }
        td.label { width: 120px; color: #6b7280; }
        td.titik { width: 10px; }

        .nominal-box { margin: 18px 0; padding: 16px 20px; background: #f0fdfa; border: 1.5px solid #0e7490; border-radius: 4px; text-align: center; }
        .nominal-box .label { font-size: 10px; color: #6b7280; letter-spacing: 2px; text-transform: uppercase; }
        .nominal-box .nilai { font-size: 22px; font-weight: bold; color: #0e7490; margin-top: 4px; }

        .terbilang { margin-top: 10px; font-size: 10.5px; color: #374151; }

        .cetak { margin-top: 24px; color: #9ca3af; font-size: 9px; }

        .footer { position: fixed; bottom: 0; left: 0; right: 0; height: 68px; }
        .footer-band { height: 6px; background: #0e7490; }
        .footer-isi { padding: 10px 45px; font-size: 9.5px; color: #1e2a5a; }
        .footer-isi td { padding-right: 22px; }
    </style>
</head>
<body>
    @php
        $rp = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
        $tgl = fn ($v) => $v ? date('d/m/Y', strtotime((string) $v)) : '-';
        $metodeLabel = ['transfer' => 'Transfer', 'tunai' => 'Tunai', 'giro' => 'Giro'];
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
            <h1>KWITANSI PEMBAYARAN VENDOR</h1>
            <div class="garis"></div>
        </div>
        <p class="periode">{{ $p->nomor_invoice }}</p>

        <table class="info">
            <tr>
                <td class="label">No. Invoice</td><td class="titik">:</td>
                <td><strong>{{ $p->nomor_invoice }}</strong></td>
                <td class="label">Tanggal Bayar</td><td class="titik">:</td>
                <td>{{ $tgl($p->tanggal_bayar) }}</td>
            </tr>
            <tr>
                <td class="label">Vendor</td><td class="titik">:</td>
                <td>{{ $p->nama_vendor ?? '-' }}</td>
                <td class="label">Metode</td><td class="titik">:</td>
                <td>{{ $metodeLabel[$p->metode] ?? $p->metode }}</td>
            </tr>
            <tr>
                <td class="label">Kontrak</td><td class="titik">:</td>
                <td>{{ $p->nomor_kontrak ?? '-' }}</td>
                <td class="label">Bank Pengirim</td><td class="titik">:</td>
                <td>{{ $p->bank_pengirim ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Total Invoice</td><td class="titik">:</td>
                <td>{{ $rp($p->total_invoice) }}</td>
                <td class="label">No. Referensi</td><td class="titik">:</td>
                <td>{{ $p->no_referensi ?? '-' }}</td>
            </tr>
        </table>

        <div class="nominal-box">
            <div class="label">Nominal Diterima</div>
            <div class="nilai">{{ $rp($p->nominal) }}</div>
        </div>

        <p class="terbilang">Terbilang: <em>{{ \App\Support\Terbilang::rupiah((float) $p->nominal) }}</em></p>

        @if (!empty($p->catatan))
            <p class="terbilang">Catatan: {{ $p->catatan }}</p>
        @endif

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
