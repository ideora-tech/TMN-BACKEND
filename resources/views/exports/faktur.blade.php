<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice</title>
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
        .judul .garis { width: 240px; border-bottom: 2.5px solid #0e7490; margin: 5px auto 0; }
        .periode { text-align: center; color: #4b5563; margin: 6px 0 16px; font-size: 11px; }

        table.info { width: 100%; margin-bottom: 14px; border-collapse: collapse; }
        table.info td { padding: 2.5px 0; vertical-align: top; }
        td.label { width: 110px; color: #6b7280; }
        td.titik { width: 10px; }

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
        $tgl = fn ($v) => $v ? date('d/m/Y', strtotime((string) $v)) : '-';
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
            <h1>INVOICE</h1>
            <div class="garis"></div>
        </div>
        <p class="periode">{{ $f->nomor_faktur }}</p>

        <table class="info">
            <tr>
                <td class="label">No. Invoice</td><td class="titik">:</td>
                <td><strong>{{ $f->nomor_faktur }}</strong></td>
                <td class="label">Tanggal Invoice</td><td class="titik">:</td>
                <td>{{ $tgl($f->tanggal_faktur) }}</td>
            </tr>
            <tr>
                <td class="label">Kepada</td><td class="titik">:</td>
                <td>{{ $f->nama_klien ?? '-' }}</td>
                <td class="label">Jatuh Tempo</td><td class="titik">:</td>
                <td>{{ $tgl($f->jatuh_tempo) }}</td>
            </tr>
            <tr>
                <td class="label">Proyek</td><td class="titik">:</td>
                <td>{{ $f->nama_proyek ?? '-' }}</td>
                <td class="label">Status</td><td class="titik">:</td>
                <td>{{ strtoupper((string) $f->status) }}</td>
            </tr>
            @if (!empty($f->nomor_penawaran))
                <tr>
                    <td class="label">Ref. Penawaran</td><td class="titik">:</td>
                    <td colspan="4">{{ $f->nomor_penawaran }}</td>
                </tr>
            @endif
        </table>

        @if (count($items) > 0)
            <table class="rincian">
                <thead>
                    <tr>
                        <th>DESKRIPSI</th>
                        <th class="jumlah">QTY</th>
                        <th class="jumlah">HARGA SATUAN</th>
                        <th class="jumlah">SUBTOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td>{{ $item->deskripsi }}</td>
                            <td class="jumlah">{{ number_format((float) $item->qty, fmod((float) $item->qty, 1) == 0.0 ? 0 : 2, ',', '.') }}</td>
                            <td class="jumlah">{{ $rp($item->harga_satuan) }}</td>
                            <td class="jumlah">{{ $rp($item->subtotal) }}</td>
                        </tr>
                    @endforeach
                    <tr class="subtotal">
                        <td colspan="3">TOTAL TAGIHAN</td>
                        <td class="jumlah">{{ $rp($f->total) }}</td>
                    </tr>
                </tbody>
            </table>
        @else
            <table class="rincian">
                <thead>
                    <tr>
                        <th>DESKRIPSI</th>
                        <th class="jumlah">NILAI</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Tagihan sesuai invoice {{ $f->nomor_faktur }}</td>
                        <td class="jumlah">{{ $rp($f->total) }}</td>
                    </tr>
                    <tr class="subtotal">
                        <td>TOTAL TAGIHAN</td>
                        <td class="jumlah">{{ $rp($f->total) }}</td>
                    </tr>
                </tbody>
            </table>
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
