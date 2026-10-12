<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Purchase Order {{ $kontrak->nomor_po }}</title>
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

        table.info { width: 100%; margin-bottom: 12px; border-collapse: collapse; }
        table.info td { padding: 2.5px 0; vertical-align: top; }
        td.label { width: 110px; color: #6b7280; }
        td.titik { width: 10px; }

        .subjudul { font-size: 12px; font-weight: bold; color: #1e2a5a; margin: 14px 0 6px; }

        .kepada { border: 1px solid #e5e7eb; border-left: 3px solid #0e7490; padding: 8px 12px; margin-bottom: 4px; }
        .kepada-nama { font-size: 12px; font-weight: bold; color: #1e2a5a; }
        .kepada-baris { color: #4b5563; margin-top: 2px; }

        table.rincian { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        table.rincian th { background: #0e7490; color: #ffffff; padding: 6px 8px; text-align: left; font-size: 10px; }
        table.rincian th.angka { text-align: right; }
        table.rincian th.tengah { text-align: center; }
        table.rincian td { padding: 5.5px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        table.rincian td.angka { text-align: right; white-space: nowrap; }
        table.rincian td.tengah { text-align: center; }
        table.rincian tr.ringkasan td { border-bottom: none; padding: 4px 8px; }
        table.rincian tr.ringkasan td.label-ringkasan { text-align: right; color: #4b5563; }
        table.rincian tr.total td { font-weight: bold; font-size: 12px; color: #1e2a5a; background: #f0fdfa; border-top: 1.5px solid #0e7490; }
        table.rincian tr.total td.label-ringkasan { text-align: right; color: #1e2a5a; }
        .nama-item { font-weight: bold; }
        .spesifikasi { color: #6b7280; font-size: 9.5px; margin-top: 2px; }

        .terbilang { margin-top: 8px; font-size: 10.5px; color: #374151; }

        .catatan { margin-top: 14px; }
        .catatan-label { color: #6b7280; }

        table.ttd { width: 100%; margin-top: 26px; border-collapse: collapse; }
        table.ttd td { width: 33%; text-align: center; vertical-align: top; padding: 0 12px; }
        .ttd-judul { color: #4b5563; }
        .ttd-ruang { height: 58px; }
        .ttd-nama { border-top: 1px solid #1f2937; padding-top: 4px; font-weight: bold; }
        .ttd-jabatan { color: #6b7280; font-size: 9.5px; margin-top: 2px; }

        .cetak { margin-top: 18px; color: #9ca3af; font-size: 9px; }

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
        $persen = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
        $mekanismeLabel = ['unit_only' => 'Unit Only', 'unit_driver' => 'Unit + Driver', 'full' => 'All In'];
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
            <h1>PURCHASE ORDER</h1>
            <div class="garis"></div>
        </div>
        <p class="periode">{{ $kontrak->nomor_po }}</p>

        <table class="info">
            <tr>
                <td class="label">No. PO</td><td class="titik">:</td>
                <td><strong>{{ $kontrak->nomor_po }}</strong></td>
                <td class="label">No. Kontrak</td><td class="titik">:</td>
                <td>{{ $kontrak->nomor_kontrak ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Tanggal PO</td><td class="titik">:</td>
                <td>{{ $tgl($kontrak->tanggal_po) }}</td>
                <td class="label">Mekanisme</td><td class="titik">:</td>
                <td>{{ $mekanismeLabel[$kontrak->mekanisme] ?? $kontrak->mekanisme }}</td>
            </tr>
            <tr>
                <td class="label">Periode</td><td class="titik">:</td>
                <td>{{ $tgl($kontrak->tanggal_mulai) }} s/d {{ $tgl($kontrak->tanggal_selesai) }}</td>
                <td class="label">Termin Bayar</td><td class="titik">:</td>
                <td>{{ $kontrak->termin_pembayaran_hari !== null ? $kontrak->termin_pembayaran_hari . ' hari' : '-' }}</td>
            </tr>
            @if ($namaProyek)
                <tr>
                    <td class="label">Proyek</td><td class="titik">:</td>
                    <td colspan="4">{{ $namaProyek }}</td>
                </tr>
            @endif
        </table>

        <p class="subjudul">Kepada</p>
        <div class="kepada">
            <div class="kepada-nama">{{ $vendor->nama_vendor ?? '-' }}</div>
            @if ($vendor->alamat ?? null)
                <div class="kepada-baris">{{ $vendor->alamat }}</div>
            @endif
            @if ($vendor->telepon ?? null)
                <div class="kepada-baris">Telp: {{ $vendor->telepon }}</div>
            @endif
            @if ($vendor->email ?? null)
                <div class="kepada-baris">{{ $vendor->email }}</div>
            @endif
        </div>

        <p class="subjudul">Rincian Pesanan</p>
        <table class="rincian">
            <thead>
                <tr>
                    <th class="tengah">NO</th>
                    <th>URAIAN</th>
                    <th class="tengah">QTY</th>
                    <th>SATUAN</th>
                    <th class="angka">HARGA</th>
                    <th class="angka">JUMLAH</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($baris as $i => $satu)
                    <tr>
                        <td class="tengah">{{ $i + 1 }}</td>
                        <td>
                            <div class="nama-item">{{ $satu['uraian'] }}</div>
                            @if ($satu['keterangan'])
                                <div class="spesifikasi">{{ $satu['keterangan'] }}</div>
                            @endif
                        </td>
                        <td class="tengah">{{ $satu['qty'] }}</td>
                        <td>{{ $satu['satuan'] }}</td>
                        <td class="angka">{{ $rp($satu['harga']) }}</td>
                        <td class="angka">{{ $rp($satu['jumlah']) }}</td>
                    </tr>
                @endforeach
                <tr class="ringkasan">
                    <td colspan="5" class="label-ringkasan">Subtotal</td>
                    <td class="angka">{{ $rp($subtotal) }}</td>
                </tr>
                @if ($pajakPersen > 0)
                    <tr class="ringkasan">
                        <td colspan="5" class="label-ringkasan">Pajak {{ $persen($pajakPersen) }}%</td>
                        <td class="angka">{{ $rp($pajak) }}</td>
                    </tr>
                @endif
                <tr class="total">
                    <td colspan="5" class="label-ringkasan">TOTAL</td>
                    <td class="angka">{{ $rp($total) }}</td>
                </tr>
            </tbody>
        </table>

        <p class="terbilang">Terbilang: <em>{{ \App\Support\Terbilang::rupiah((float) $total) }}</em></p>

        @if ($catatan !== [])
            <p class="catatan"><span class="catatan-label">Catatan:</span> {{ implode(' · ', $catatan) }}</p>
        @endif

        <table class="ttd">
            <tr>
                <td>
                    <div class="ttd-judul">Dibuat oleh</div>
                    <div class="ttd-ruang"></div>
                    <div class="ttd-nama">{{ $pembuat ?? '-' }}</div>
                    <div class="ttd-jabatan">&nbsp;</div>
                </td>
                <td>
                    <div class="ttd-judul">Disetujui oleh</div>
                    <div class="ttd-ruang"></div>
                    <div class="ttd-nama">&nbsp;</div>
                    <div class="ttd-jabatan">&nbsp;</div>
                </td>
                <td>
                    <div class="ttd-judul">Vendor</div>
                    <div class="ttd-ruang"></div>
                    <div class="ttd-nama">&nbsp;</div>
                    <div class="ttd-jabatan">&nbsp;</div>
                </td>
            </tr>
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
