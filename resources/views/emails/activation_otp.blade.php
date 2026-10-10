<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Activate Your Account</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; color: #1e293b; padding: 24px; }
        .card { max-width: 520px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 32px; border: 1px solid #e2e8f0; }
        .logo { font-size: 22px; font-weight: bold; color: #0284c7; margin-bottom: 20px; }
        .code-box { font-size: 32px; font-weight: 800; letter-spacing: 6px; text-align: center; background: #f0f9ff; border: 1px dashed #0284c7; color: #0369a1; padding: 16px; border-radius: 6px; margin: 24px 0; }
        .footer { margin-top: 32px; font-size: 12px; color: #64748b; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">Airlipa</div>
        <h2>Hello {{ $user->first_name }},</h2>
        <p>Thank you for registering. Please use the verification code below to activate your account:</p>
        <div class="code-box">{{ $code }}</div>
        <p>This code will expire in {{ $expiryMinutes }} minutes. If you did not request this, please disregard this email.</p>
        <div class="footer">
            <p>&copy; {{ date('Y') }} Airlipa Technologies. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
