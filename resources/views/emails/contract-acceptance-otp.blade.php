<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contract acceptance code</title>
</head>
<body style="margin:0;padding:16px;font-family:Arial,Helvetica,sans-serif;background:#f4f6f8;color:#102a33;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;">
    <tr>
        <td style="background:#0d7a72;color:#ffffff;padding:14px 20px;">
            <strong>Airlipa Escrow</strong>
        </td>
    </tr>
    <tr>
        <td style="padding:20px;">
            <p style="margin:0 0 8px;font-size:14px;">Hello {{ $user->full_name ?? $user->name }},</p>
            <p style="margin:0 0 12px;font-size:14px;">
                Use this code to sign and accept the escrow contract / Tumia msimbo huu kusaini na kukubali mkataba wa eskrow:
            </p>
            <p style="margin:0 0 12px;font-size:28px;letter-spacing:6px;font-weight:bold;">{{ $code }}</p>
            <p style="margin:0;font-size:12px;color:#5b6b73;">
                Expires in {{ $expiryMinutes }} minutes / Unaisha baada ya dakika {{ $expiryMinutes }}.
                If you did not request this, ignore this message / Kama hujaomba msimbo huu, puuza ujumbe huu.
            </p>
        </td>
    </tr>
</table>
</body>
</html>
