<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Faktur</title>
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
    <h2>Laporan Faktur</h2>
    <p>Dicetak: {{ now()->format('d M Y H:i') }}</p>
    <table>
        <thead>
            <tr>
                <th>No. Faktur</th>
                <th>Klien</th>
                <th>Status</th>
                <th class="amount">Total</th>
                <th>Tanggal Faktur</th>
                <th>Jatuh Tempo</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
            <tr>
                <td>{{ $item->nomor_faktur }}</td>
                <td>{{ $item->klien_nama ?? '-' }}</td>
                <td>{{ $item->status }}</td>
                <td class="amount">Rp {{ number_format($item->total, 0, ',', '.') }}</td>
                <td>{{ $item->tanggal_faktur ? $item->tanggal_faktur->format('d/m/Y') : '-' }}</td>
                <td>{{ $item->jatuh_tempo ? $item->jatuh_tempo->format('d/m/Y') : '-' }}</td>
            </tr>
            @empty
            <tr><td colspan="6" style="text-align:center">Tidak ada data</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>