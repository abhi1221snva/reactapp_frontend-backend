<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Welcome to {{ env('SITE_NAME') }}</title>
<style>
  body { margin:0; padding:0; font-family: Arial, Helvetica, sans-serif; background:#f4f6f9; }
  .wrapper { max-width:600px; margin:40px auto; background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.08); }
  .header { background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%); padding:40px 32px; text-align:center; }
  .header img { height:44px; }
  .header h1 { color:#ffffff; margin:16px 0 4px; font-size:26px; font-weight:700; }
  .header p  { color:#e0e7ff; margin:0; font-size:15px; }
  .body { padding:36px 36px 28px; }
  .body p { color:#374151; font-size:15px; line-height:1.7; margin:0 0 16px; }
  .steps { list-style:none; margin:20px 0; padding:0; }
  .steps li { padding:10px 0 10px 36px; position:relative; color:#374151; font-size:14px; border-bottom:1px solid #f3f4f6; }
  .steps li:last-child { border-bottom:none; }
  .steps li::before { content:attr(data-step); position:absolute; left:0; top:10px; background:#4f46e5; color:#fff; width:22px; height:22px; border-radius:50%; font-size:12px; font-weight:700; display:flex; align-items:center; justify-content:center; }
  .btn { display:inline-block; margin:8px 0 20px; padding:13px 28px; background:#4f46e5; color:#ffffff; text-decoration:none; border-radius:6px; font-size:15px; font-weight:600; }
  .creds-box { background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:16px 20px; margin:16px 0; }
  .creds-box p { margin:4px 0; font-size:14px; color:#374151; }
  .creds-box strong { color:#111827; }
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
    <h1>Welcome to {{ env('SITE_NAME') }}!</h1>
    <p>Your account is ready — let's get started</p>
  </div>

  <div class="body">
    <p>Hi <strong>{{ $name ?? 'there' }}</strong>,</p>
    <p>
      Congratulations! Your <strong>{{ env('SITE_NAME') }}</strong> account has been successfully created.
      You now have access to a powerful dialer and CRM platform designed to help your business grow.
    </p>

    @if(!empty($password))
    <div class="creds-box">
      <p><strong>Your login credentials:</strong></p>
      <p>Username / Email: <strong>{{ $email ?? '' }}</strong></p>
      <p>Temporary Password: <strong>{{ $password }}</strong></p>
      <p style="font-size:12px; color:#6b7280; margin-top:8px;">
        Please change your password after your first login.
      </p>
    </div>
    @endif

    <p><strong>Getting started is easy:</strong></p>
    <ol class="steps">
      <li data-step="1">Log in to your account</li>
      <li data-step="2">Complete your profile and verify your phone number</li>
      <li data-step="3">Create your first agent</li>
      <li data-step="4">Configure your lead fields</li>
      <li data-step="5">Launch your first campaign</li>
    </ol>

    <p style="text-align:center;">
      <a href="{{ $loginUrl ?? env('PORTAL_NAME', '#') }}" class="btn">Log In Now</a>
    </p>

    <p>
      If you have any questions or need assistance, our support team is here to help at
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
