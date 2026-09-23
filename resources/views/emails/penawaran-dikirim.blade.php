<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        .isi-pesan { color:#1f2937; font-size:14px; line-height:1.7; }
        .isi-pesan p { margin: 0 0 10px; }
        .isi-pesan h1, .isi-pesan h2, .isi-pesan h3, .isi-pesan h4, .isi-pesan h5, .isi-pesan h6 { margin: 14px 0 6px; color:#111827; }
        .isi-pesan h1 { font-size: 20px; }
        .isi-pesan h2 { font-size: 18px; }
        .isi-pesan h3 { font-size: 16px; }
        .isi-pesan ul, .isi-pesan ol { margin: 6px 0 10px; padding-left: 22px; }
        .isi-pesan blockquote { margin: 8px 0; padding-left: 12px; border-left: 3px solid #9ca3af; color:#4b5563; }
        .isi-pesan pre, .isi-pesan code { font-family: 'Courier New', monospace; background:#f3f4f6; }
        .isi-pesan pre { padding: 8px 10px; border-radius: 6px; }
        .isi-pesan hr { border: 0; border-top: 1px solid #d1d5db; margin: 14px 0; }
    </style>
</head>
<body style="margin:0; padding:0; background:#f3f4f6; font-family: Arial, Helvetica, sans-serif; color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:12px; padding:32px;">
                    <tr>
                        <td class="isi-pesan">{!! $pesan !!}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
