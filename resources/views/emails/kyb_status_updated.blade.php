<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Business Verification Update</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; color: #1e293b; padding: 24px; }
        .card { max-width: 520px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 32px; border: 1px solid #e2e8f0; }
        .logo { font-size: 22px; font-weight: bold; color: #0284c7; margin-bottom: 20px; }
        .status-badge { display: inline-block; padding: 6px 14px; border-radius: 20px; font-weight: 700; font-size: 14px; margin: 12px 0; }
        .badge-verified { background: #dcfce7; color: #15803d; }
        .badge-rejected { background: #fee2e2; color: #b91c1c; }
        .reason-box { background: #fff1f2; border: 1px solid #fecdd3; padding: 14px; border-radius: 6px; color: #9f1239; margin: 16px 0; font-size: 14px; }
        .footer { margin-top: 32px; font-size: 12px; color: #64748b; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">Airlipa</div>
        <h2>Hello {{ $user->first_name }},</h2>
        <p>An update has been made to your business verification status for <strong>{{ $businessName }}</strong>:</p>

        @if($status === 'Verified')
            <div class="status-badge badge-verified">Status: VERIFIED</div>
            <p>Congratulations! Your business details and documentation have been reviewed and successfully approved. You now have full access to all business features.</p>
        @else
            <div class="status-badge badge-rejected">Status: REJECTED</div>
            <p>Your business verification was not approved. Review the notes provided by our compliance team below:</p>
            @if(!empty($reason))
                <div class="reason-box"><strong>Reason:</strong> {{ $reason }}</div>
            @endif
            <p>You may log into your account and resubmit your updated business documents for review.</p>
        @endif

        <div class="footer">
            <p>&copy; {{ date('Y') }} Airlipa Technologies. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
