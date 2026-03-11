<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Your Agent Account — {{ $companyName ?? env('SITE_NAME') }}</title>
<style>
  body { margin:0; padding:0; font-family: Arial, Helvetica, sans-serif; background:#f4f6f9; }
  .wrapper { max-width:600px; margin:40px auto; background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.08); }
  .header { background:#1e293b; padding:32px; text-align:center; }
  .header img { height:40px; }
  .header h1 { color:#ffffff; margin:14px 0 4px; font-size:22px; font-weight:700; }
  .header p  { color:#94a3b8; margin:0; font-size:14px; }
  .body { padding:32px 36px; }
  .body p { color:#374151; font-size:15px; line-height:1.7; margin:0 0 14px; }
  .creds-box { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:20px 24px; margin:20px 0; }
  .creds-row { display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #dbeafe; }
  .creds-row:last-child { border-bottom:none; }
  .creds-label { font-size:13px; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:.4px; }
  .creds-value { font-size:14px; color:#1e293b; font-weight:600; font-family: 'Courier New', monospace; }
  .btn { display:inline-block; margin:8px 0 16px; padding:13px 28px; background:#4f46e5; color:#ffffff; text-decoration:none; border-radius:6px; font-size:15px; font-weight:600; }
  .warning { background:#fef3c7; border-left:4px solid #f59e0b; padding:12px 16px; border-radius:0 6px 6px 0; font-size:13px; color:#92400e; margin:16px 0; }
  .footer { background:#f9fafb; padding:20px 32px; text-align:center; border-top:1px solid #e5e7eb; }
  .footer p { font-size:12px; color:#9ca3af; margin:0; line-height:1.6; }
  .footer a { color:#4f46e5; text-decoration:none; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    @if(env('SITE_NAME_LOGO'))
      <img src="{{ env('SITE_NAME_LOGO') }}" alt="{{ env('SITE_NAME') }}">
    @endif
    <h1>Your Agent Account is Ready</h1>
    <p>{{ $companyName ?? env('SITE_NAME') }} — Agent Portal</p>
  </div>

  <div class="body">
    <p>Hello <strong>{{ $agentName ?? 'Agent' }}</strong>,</p>
    <p>
      An agent account has been created for you on the
      <strong>{{ $companyName ?? env('SITE_NAME') }}</strong> platform.
      You can log in using the credentials below.
    </p>

    <div class="creds-box">
      <p style="margin:0 0 12px; font-weight:600; color:#1e293b;">Your Login Credentials</p>
      <div class="creds-row">
        <span class="creds-label">Email / Username</span>
        <span class="creds-value">{{ $username }}</span>
      </div>
      <div class="creds-row">
        <span class="creds-label">Password</span>
        <span class="creds-value">{{ $password }}</span>
      </div>
    </div>

    <div class="warning">
      <strong>Security Notice:</strong> Please change your password immediately after your first login.
      Do not share these credentials with anyone.
    </div>

    <p style="text-align:center;">
      <a href="{{ $loginUrl ?? env('PORTAL_NAME', '#') }}" class="btn">Log In to Your Account</a>
    </p>

    <p>
      If you were not expecting this email or believe this account was created in error,
      please contact your administrator or reach out to us at
      <a href="mailto:{{ $supportEmail ?? env('DEFAULT_EMAIL', 'support@example.com') }}">
        {{ $supportEmail ?? env('DEFAULT_EMAIL', 'support@example.com') }}
      </a>.
    </p>
  </div>

  <div class="footer">
    <p>
      &copy; {{ date('Y') }} {{ env('SITE_NAME') }}. All rights reserved.<br>
      This is an automated message — please do not reply directly to this email.
    </p>
  </div>
</div>
</body>
</html>
