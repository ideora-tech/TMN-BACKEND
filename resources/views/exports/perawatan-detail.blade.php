<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Catatan Perawatan Armada</title>
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
        .judul .garis { width: 280px; border-bottom: 2.5px solid #0e7490; margin: 5px auto 0; }
        .periode { text-align: center; color: #4b5563; margin: 6px 0 16px; font-size: 11px; }

        table.info { width: 100%; margin-bottom: 14px; border-collapse: collapse; }
        table.info td { padding: 2.5px 0; vertical-align: top; }
        td.label { width: 120px; color: #6b7280; }
        td.titik { width: 10px; }

        .subjudul { font-size: 12px; font-weight: bold; color: #1e2a5a; margin: 14px 0 6px; }

        table.rincian { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        table.rincian th { background: #0e7490; color: #ffffff; padding: 6px 8px; text-align: left; font-size: 10px; }
        table.rincian td { padding: 5.5px 8px; border-bottom: 1px solid #e5e7eb; }
        table.rincian .kanan { text-align: right; }
        .ket { color: #9ca3af; font-size: 9.5px; }
        .sumber { font-size: 9px; padding: 1px 6px; border-radius: 3px; }
        .sumber-bengkel { background: #fef3c7; color: #92400e; }
        .sumber-stok { background: #dbeafe; color: #1e40af; }

        table.total { width: 45%; margin-left: 55%; border-collapse: collapse; margin-top: 8px; }
        table.total td { padding: 4px 8px; }
        table.total .kanan { text-align: right; }
        table.total tr.grand td { border-top: 2px solid #0e7490; font-weight: bold; color: #1e2a5a; font-size: 12px; padding-top: 6px; }

        .catatan { margin-top: 14px; padding: 8px 10px; background: #f9fafb; border-left: 3px solid #0e7490; }

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
        $statusLabel = ['terjadwal' => 'Direncanakan', 'dalam_proses' => 'Dalam Proses', 'selesai' => 'Selesai', 'dibatalkan' => 'Dibatalkan'];
        $sparepart = $perawatan->sparepart ?? [];
        $totalSparepart = array_sum(array_map(fn ($sp) => (float) ($sp['subtotal'] ?? 0), $sparepart));
        $biayaJasa = (float) ($perawatan->biaya ?? 0);
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
            <h1>CATATAN PERAWATAN ARMADA</h1>
            <div class="garis"></div>
        </div>
        <p class="periode">{{ $armada->nopol }}{{ $armada->merk ? ' · ' . $armada->merk : '' }}</p>

        <table class="info">
            <tr>
                <td class="label">Tanggal</td><td class="titik">:</td>
                <td><strong>{{ $tgl($perawatan->tanggal) }}</strong></td>
                <td class="label">Status</td><td class="titik">:</td>
                <td>{{ $statusLabel[$perawatan->status] ?? $perawatan->status }}</td>
            </tr>
            <tr>
                <td class="label">Paket Servis</td><td class="titik">:</td>
                <td>{{ $perawatan->interval_label ?? 'Perbaikan' }}</td>
                <td class="label">Bengkel</td><td class="titik">:</td>
                <td>{{ $perawatan->nama_supplier ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">KM Odometer</td><td class="titik">:</td>
                <td>{{ $perawatan->km_odometer !== null ? number_format((int) $perawatan->km_odometer, 0, ',', '.') . ' km' : '-' }}</td>
                <td class="label">Servis Berikutnya</td><td class="titik">:</td>
                <td>{{ $tgl($perawatan->jadwal_servis_berikutnya) }}</td>
            </tr>
        </table>

        <p class="subjudul">Sparepart Digunakan ({{ count($sparepart) }})</p>
        <table class="rincian">
            <thead>
                <tr>
                    <th>NO</th>
                    <th>SUMBER</th>
                    <th>NAMA</th>
                    <th class="kanan">QTY</th>
                    <th class="kanan">HARGA</th>
                    <th class="kanan">SUBTOTAL</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sparepart as $i => $sp)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>
                            @if (($sp['sumber'] ?? 'bengkel') === 'stok_sendiri')
                                <span class="sumber sumber-stok">Stok Sendiri</span>
                            @else
                                <span class="sumber sumber-bengkel">Bengkel</span>
                            @endif
                        </td>
                        <td>{{ $sp['nama_sparepart'] ?? '-' }}</td>
                        <td class="kanan">{{ $sp['qty'] ?? 0 }}</td>
                        <td class="kanan">{{ $rp($sp['harga'] ?? 0) }}</td>
                        <td class="kanan">{{ $rp($sp['subtotal'] ?? 0) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="ket">Tidak ada sparepart yang dicatat.</td></tr>
                @endforelse
            </tbody>
        </table>

        <table class="total">
            <tr>
                <td>Biaya Jasa</td>
                <td class="kanan">{{ $rp($biayaJasa) }}</td>
            </tr>
            <tr>
                <td>Total Sparepart</td>
                <td class="kanan">{{ $rp($totalSparepart) }}</td>
            </tr>
            <tr class="grand">
                <td>TOTAL BIAYA</td>
                <td class="kanan">{{ $rp($biayaJasa + $totalSparepart) }}</td>
            </tr>
        </table>

        @if (!empty($perawatan->keterangan))
            <div class="catatan">
                <strong>Keterangan:</strong> {{ $perawatan->keterangan }}
            </div>
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
