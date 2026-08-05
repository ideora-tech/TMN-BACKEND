<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Rekap Trip per Supir</title>
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
    <h2>Rekap Trip per Supir</h2>
    <p>{{ $periode }} — Dicetak: {{ now()->format('d M Y H:i') }}</p>
    <table>
        <thead>
            <tr>
                <th>Nama Supir</th>
                <th>Sumber</th>
                <th class="amount">Jumlah Trip</th>
                <th class="amount">Selesai</th>
                <th class="amount">Dibatalkan</th>
                <th class="amount">Total Jarak (km)</th>
                <th class="amount">Total Biaya</th>
                <th>Trip Terakhir</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
            <tr>
                <td>{{ $item->nama_supir }}</td>
                <td>{{ ($item->sumber ?? 'internal') === 'vendor' ? 'Vendor' : 'Internal' }}</td>
                <td class="amount">{{ (int) $item->jumlah_trip }}</td>
                <td class="amount">{{ (int) $item->selesai }}</td>
                <td class="amount">{{ (int) $item->dibatalkan }}</td>
                <td class="amount">{{ number_format($item->total_jarak_km ?? 0, 2, ',', '.') }}</td>
                <td class="amount">Rp {{ number_format($item->total_biaya ?? 0, 0, ',', '.') }}</td>
                <td>{{ $item->trip_terakhir ? \Illuminate\Support\Carbon::parse($item->trip_terakhir)->format('d/m/Y H:i') : '-' }}</td>
            </tr>
            @empty
            <tr><td colspan="8" style="text-align:center">Tidak ada data</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
