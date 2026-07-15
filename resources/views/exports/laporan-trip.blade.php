<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Trip</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; }
        h2 { margin-bottom: 4px; }
        p { margin: 2px 0 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 5px 7px; text-align: left; }
        th { background: #f4f4f4; font-weight: bold; }
        tr:nth-child(even) { background: #fafafa; }
        .amount { text-align: right; }
    </style>
</head>
<body>
    <h2>Laporan Trip</h2>
    <p>Dicetak: {{ now()->format('d M Y H:i') }}</p>
    <table>
        <thead>
            <tr>
                <th>Tanggal Berangkat</th>
                <th>Proyek</th>
                <th>Klien</th>
                <th>Armada</th>
                <th>Supir</th>
                <th>Sumber</th>
                <th>Status</th>
                <th class="amount">Jarak (km)</th>
                <th class="amount">Total Biaya</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
            <tr>
                <td>{{ $item->waktu_berangkat ? \Illuminate\Support\Carbon::parse($item->waktu_berangkat)->format('d/m/Y H:i') : '-' }}</td>
                <td>{{ $item->nama_proyek ?? '-' }}</td>
                <td>{{ $item->nama_klien ?? '-' }}</td>
                <td>{{ $item->nopol ?? '-' }}</td>
                <td>{{ $item->nama_supir ?? '-' }}</td>
                <td>{{ ($item->sumber ?? 'internal') === 'vendor' ? 'Vendor' : 'Internal' }}</td>
                <td>{{ $item->status ?? '-' }}</td>
                <td class="amount">{{ number_format($item->jarak_tempuh_km ?? 0, 2, ',', '.') }}</td>
                <td class="amount">Rp {{ number_format($item->total_biaya ?? 0, 0, ',', '.') }}</td>
            </tr>
            @empty
            <tr><td colspan="9" style="text-align:center">Tidak ada data</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
