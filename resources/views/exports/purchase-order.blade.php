<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Purchase Order {{ $pr->nomor_po }}</title>
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

        table.termin { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.termin th { background: #1e2a5a; color: #ffffff; padding: 6px 8px; text-align: left; font-size: 10px; }
        table.termin th.angka { text-align: right; }
        table.termin td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
        table.termin td.angka { text-align: right; white-space: nowrap; }

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
        $diskon = (float) ($pr->diskon ?? 0);
        $ppnPersen = (float) ($pr->ppn_persen ?? 0);
        $ongkir = (float) ($pr->ongkir ?? 0);
        $termin = $pr->termin ?? [];
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
        <p class="periode">{{ $pr->nomor_po }}</p>

        <table class="info">
            <tr>
                <td class="label">No. PO</td><td class="titik">:</td>
                <td><strong>{{ $pr->nomor_po }}</strong></td>
                <td class="label">No. PR</td><td class="titik">:</td>
                <td>{{ $pr->nomor_permintaan }}</td>
            </tr>
            <tr>
                <td class="label">Tanggal PO</td><td class="titik">:</td>
                <td>{{ $tgl($pr->tanggal_po ?? $pr->tanggal_pembelian) }}</td>
                <td class="label">Departemen</td><td class="titik">:</td>
                <td>{{ $pr->nama_departemen ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Tanggal Dibutuhkan</td><td class="titik">:</td>
                <td>{{ $tgl($pr->tanggal_dibutuhkan) }}</td>
                <td class="label">Pemohon</td><td class="titik">:</td>
                <td>{{ $pr->username_pengaju ?? '-' }}</td>
            </tr>
        </table>

        <p class="subjudul">Kepada</p>
        <div class="kepada">
            <div class="kepada-nama">{{ $supplier->nama ?? ($pr->nama_supplier ?? '-') }}</div>
            @if ($supplier->alamat ?? null)
                <div class="kepada-baris">{{ $supplier->alamat }}</div>
            @endif
            @if ($supplier->telepon ?? null)
                <div class="kepada-baris">Telp: {{ $supplier->telepon }}</div>
            @endif
        </div>

        <p class="subjudul">Rincian Pesanan</p>
        <table class="rincian">
            <thead>
                <tr>
                    <th class="tengah">NO</th>
                    <th>NAMA BARANG / JASA</th>
                    <th class="tengah">QTY</th>
                    <th>SATUAN</th>
                    <th class="angka">HARGA SATUAN</th>
                    <th class="angka">JUMLAH</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pr->items as $i => $item)
                    @php
                        $aset = ($item->jenis ?? null) === 'aset';
                        $namaAset = trim((string) preg_replace('/\s+/', ' ', ($item->merk ?? '') . ' ' . ($item->model ?? '') . ' ' . ($item->tahun ?? '')));
                    @endphp
                    <tr>
                        <td class="tengah">{{ $i + 1 }}</td>
                        <td>
                            <div class="nama-item">{{ $aset && ($item->merk ?? '') !== '' ? $namaAset : $item->nama_item }}</div>
                            @if ($aset && ($item->nama_jenis_kendaraan ?? null))
                                <div class="spesifikasi">{{ $item->nama_jenis_kendaraan }}</div>
                            @endif
                            @if ($item->spesifikasi ?? null)
                                <div class="spesifikasi">{{ $item->spesifikasi }}</div>
                            @endif
                            @if (($pr->sisa_ditutup_pada ?? null) && (int) ($item->qty_diterima ?? 0) < (int) $item->qty)
                                <div class="spesifikasi">Diterima {{ (int) ($item->qty_diterima ?? 0) }} dari {{ (int) $item->qty }} yang dipesan</div>
                            @endif
                        </td>
                        <td class="tengah">{{ \App\Modules\PermintaanPembelian\PermintaanPembelianService::qtyBerlaku($pr, $item) }}</td>
                        <td>{{ $item->satuan }}</td>
                        <td class="angka">{{ $rp($item->harga_aktual) }}</td>
                        <td class="angka">{{ $rp(\App\Modules\PermintaanPembelian\PermintaanPembelianService::qtyBerlaku($pr, $item) * (float) $item->harga_aktual) }}</td>
                    </tr>
                @endforeach
                <tr class="ringkasan">
                    <td colspan="5" class="label-ringkasan">Subtotal</td>
                    <td class="angka">{{ $rp($subtotal) }}</td>
                </tr>
                @if ($diskon > 0)
                    <tr class="ringkasan">
                        <td colspan="5" class="label-ringkasan">Diskon</td>
                        <td class="angka">-{{ $rp($diskon) }}</td>
                    </tr>
                @endif
                @if ($ppnPersen > 0)
                    <tr class="ringkasan">
                        <td colspan="5" class="label-ringkasan">PPN {{ $persen($ppnPersen) }}%</td>
                        <td class="angka">{{ $rp($pr->ppn) }}</td>
                    </tr>
                @endif
                @if ($ongkir > 0)
                    <tr class="ringkasan">
                        <td colspan="5" class="label-ringkasan">Ongkos Kirim</td>
                        <td class="angka">{{ $rp($ongkir) }}</td>
                    </tr>
                @endif
                <tr class="total">
                    <td colspan="5" class="label-ringkasan">TOTAL</td>
                    <td class="angka">{{ $rp($pr->total_aktual) }}</td>
                </tr>
            </tbody>
        </table>

        <p class="terbilang">Terbilang: <em>{{ \App\Support\Terbilang::rupiah((float) $pr->total_aktual) }}</em></p>
        @if ($pr->sisa_ditutup_pada ?? null)
            <p class="terbilang">Sisa pesanan ditutup pada {{ $tgl($pr->sisa_ditutup_pada) }} — jumlah di atas adalah yang diterima. Alasan: {{ $pr->alasan_tutup_sisa }}</p>
        @endif

        @if (count($termin) > 0)
            <p class="subjudul">Termin Pembayaran</p>
            <table class="termin">
                <thead>
                    <tr>
                        <th>NO</th>
                        <th>TERMIN</th>
                        <th>JATUH TEMPO</th>
                        <th class="angka">NOMINAL</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($termin as $t)
                        <tr>
                            <td>{{ (int) $t->urutan }}</td>
                            <td>{{ $t->nama }}</td>
                            <td>{{ $tgl($t->jatuh_tempo) }}</td>
                            <td class="angka">{{ $rp($t->nominal) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <p class="catatan"><span class="catatan-label">Catatan:</span> {{ $pr->judul }}</p>

        <table class="ttd">
            <tr>
                <td>
                    <div class="ttd-judul">Dibuat oleh</div>
                    <div class="ttd-ruang"></div>
                    <div class="ttd-nama">{{ $pembuat ?? '-' }}</div>
                    <div class="ttd-jabatan">Pengadaan</div>
                </td>
                <td>
                    <div class="ttd-judul">Disetujui oleh</div>
                    <div class="ttd-ruang"></div>
                    <div class="ttd-nama">&nbsp;</div>
                    <div class="ttd-jabatan">&nbsp;</div>
                </td>
                <td>
                    <div class="ttd-judul">Supplier</div>
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
