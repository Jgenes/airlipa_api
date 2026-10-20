<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Airlipa Escrow Contract</title>
</head>
<body style="margin:0;padding:16px;font-family:Arial,Helvetica,sans-serif;background:#f4f6f8;color:#102a33;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;">
    <tr>
        <td style="background:#0d7a72;color:#ffffff;padding:16px 20px;">
            <strong>Airlipa Escrow</strong> &mdash; Malipo Yanayolindwa
        </td>
    </tr>
    <tr>
        <td style="padding:20px;">
            <h2 style="margin:0 0 8px;font-size:18px;">{{ $contract->title }}</h2>
            <p style="margin:0 0 12px;font-size:14px;">
                Reference: <strong>{{ $contract->reference }}</strong><br>
                Amount: <strong>TZS {{ number_format(((int) $contract->amount_minor) / 100, 2) }}</strong><br>
                Your role: <strong>{{ $contract->initiator_role === 'BUYER' ? 'SELLER' : 'BUYER' }}</strong>
            </p>
            <p style="margin:0 0 16px;font-size:14px;">{{ $message }}</p>
            <p style="margin:0;">
                <a href="{{ $url }}" style="background:#0d7a72;color:#ffffff;padding:10px 18px;border-radius:6px;text-decoration:none;font-size:14px;">
                    Review &amp; accept / Kagua na kubali
                </a>
            </p>
            <p style="margin:16px 0 0;font-size:12px;color:#5b6b73;">
                Mkataba huu unashikiliwa kwa amana (escrow). Fedha hutolewa tu mkataba unapotekelezwa.
                <br>This agreement is held in escrow. Funds release only when the agreement is fulfilled.
            </p>
        </td>
    </tr>
</table>
</body>
</html>
