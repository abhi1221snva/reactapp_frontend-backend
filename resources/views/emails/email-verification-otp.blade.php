<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Your Email — {{ env('SITE_NAME') }}</title>
<style>
  body { margin:0; padding:0; font-family: Arial, Helvetica, sans-serif; background:#f4f6f9; }
  .wrapper { max-width:600px; margin:40px auto; background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.08); }
  .header { background:#4f46e5; padding:28px 32px; text-align:center; }
  .header img { height:42px; }
  .header h1 { color:#ffffff; margin:12px 0 0; font-size:22px; font-weight:700; letter-spacing:.3px; }
  .body { padding:32px 36px; }
  .body p { color:#374151; font-size:15px; line-height:1.7; margin:0 0 16px; }
  .otp-box { background:#f0f0ff; border:2px dashed #4f46e5; border-radius:8px; text-align:center; padding:20px 16px; margin:24px 0; }
  .otp-code { font-size:38px; font-weight:700; letter-spacing:12px; color:#4f46e5; font-family: 'Courier New', monospace; }
  .otp-note { font-size:13px; color:#6b7280; margin-top:8px; }
  .footer { background:#f9fafb; padding:20px 32px; text-align:center; border-top:1px solid #e5e7eb; }
  .footer p { font-size:12px; color:#9ca3af; margin:0; }
  .footer a { color:#4f46e5; text-decoration:none; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    @if(env('SITE_NAME_LOGO'))
      <img src="{{ env('SITE_NAME_LOGO') }}" alt="{{ env('SITE_NAME') }}">
    @endif
    <h1>Verify Your Email Address</h1>
  </div>
  <div class="body">
    <p>Hello {{ $data['name'] ?? 'there' }},</p>
    <p>
      Thank you for signing up for <strong>{{ env('SITE_NAME') }}</strong>!
      To complete your registration, please verify your email address using the one-time code below:
    </p>

    <div class="otp-box">
      <div class="otp-code">{{ $data['code'] }}</div>
      <div class="otp-note">This code is valid for <strong>15 minutes</strong></div>
    </div>

    <p>
      If you did not create an account, please ignore this email — no action is required.
    </p>
    <p>
      For support, contact us at
      <a href="mailto:{{ env('SUPPORT_EMAIL', env('DEFAULT_EMAIL', 'support@example.com')) }}">
        {{ env('SUPPORT_EMAIL', env('DEFAULT_EMAIL', 'support@example.com')) }}
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
