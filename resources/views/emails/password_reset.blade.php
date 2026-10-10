<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Password Reset Request</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; color: #1e293b; padding: 24px; }
        .card { max-width: 520px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 32px; border: 1px solid #e2e8f0; }
        .logo { font-size: 22px; font-weight: bold; color: #0284c7; margin-bottom: 20px; }
        .token-box { font-family: monospace; font-size: 16px; background: #f1f5f9; padding: 12px; border-radius: 6px; word-break: break-all; margin: 20px 0; border: 1px solid #cbd5e1; }
        .footer { margin-top: 32px; font-size: 12px; color: #64748b; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">Airlipa</div>
        <h2>Hello {{ $user->first_name }},</h2>
        <p>You recently requested to reset your password. Use the reset token below with the password reset endpoint to choose a new password:</p>
        <div class="token-box">{{ $token }}</div>
        <p>This token expires in 60 minutes. If you did not request a password reset, no further action is required.</p>
        <div class="footer">
            <p>&copy; {{ date('Y') }} Airlipa Technologies. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
