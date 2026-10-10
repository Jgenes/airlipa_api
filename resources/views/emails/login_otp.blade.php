<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Login Verification Code</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; color: #1e293b; padding: 24px; }
        .card { max-width: 520px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 32px; border: 1px solid #e2e8f0; }
        .logo { font-size: 22px; font-weight: bold; color: #0284c7; margin-bottom: 20px; }
        .code-box { font-size: 32px; font-weight: 800; letter-spacing: 6px; text-align: center; background: #f0fdf4; border: 1px dashed #16a34a; color: #15803d; padding: 16px; border-radius: 6px; margin: 24px 0; }
        .footer { margin-top: 32px; font-size: 12px; color: #64748b; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">Airlipa</div>
        <h2>Hello {{ $user->first_name }},</h2>
        <p>A sign-in attempt was initiated for your account. Please use the one-time security code below to complete your login:</p>
        <div class="code-box">{{ $code }}</div>
        <p>This code expires in {{ $expiryMinutes }} minutes. Never share this code with anyone.</p>
        <div class="footer">
            <p>If you did not initiate this sign-in, please change your password immediately or contact support.</p>
            <p>&copy; {{ date('Y') }} Airlipa Technologies. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
