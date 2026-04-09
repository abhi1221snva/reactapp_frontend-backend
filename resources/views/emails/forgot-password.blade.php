<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f9;padding:40px 0;">
<tr><td align="center">

    {{-- Card --}}
    <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.06);overflow:hidden;">

        {{-- Header bar --}}
        <tr>
            <td style="background:linear-gradient(135deg,#4f46e5,#6366f1);padding:28px 36px;text-align:center;">
                @if(env('SITE_NAME_LOGO'))
                    <img src="{{ env('SITE_NAME_LOGO') }}" alt="{{ env('SITE_NAME','LinkSwitch') }}" height="38" style="display:inline-block;vertical-align:middle;" />
                @else
                    <span style="color:#ffffff;font-size:22px;font-weight:700;letter-spacing:0.5px;">{{ env('SITE_NAME','LinkSwitch Communications') }}</span>
                @endif
            </td>
        </tr>

        {{-- Body --}}
        <tr>
            <td style="padding:36px 36px 28px;">

                <h2 style="margin:0 0 6px;font-size:20px;font-weight:700;color:#1e293b;">Password Reset Request</h2>
                <p style="margin:0 0 24px;font-size:14px;color:#94a3b8;">We received a request to reset your password.</p>

                <p style="margin:0 0 24px;font-size:14px;line-height:1.6;color:#475569;">
                    Hi <strong>{{ $firstName }} {{ $lastName }}</strong>,
                    <br><br>
                    Click the button below to set a new password. This link will expire in <strong>30 minutes</strong>.
                </p>

                {{-- CTA Button --}}
                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 28px;">
                <tr>
                    <td style="border-radius:8px;background:#4f46e5;">
                        <a href="{{ $resetLink }}" target="_blank"
                           style="display:inline-block;padding:14px 40px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">
                            Reset My Password
                        </a>
                    </td>
                </tr>
                </table>

                <p style="margin:0 0 20px;font-size:13px;line-height:1.5;color:#94a3b8;">
                    If the button doesn't work, copy and paste this link into your browser:
                </p>
                <p style="margin:0 0 28px;font-size:12px;word-break:break-all;color:#6366f1;">
                    {{ $resetLink }}
                </p>

                <hr style="border:none;border-top:1px solid #e2e8f0;margin:0 0 20px;" />

                <p style="margin:0;font-size:12px;line-height:1.5;color:#94a3b8;">
                    If you didn't request a password reset, you can safely ignore this email. Your password will remain unchanged.
                </p>

            </td>
        </tr>

        {{-- Footer --}}
        <tr>
            <td style="background:#f8fafc;padding:20px 36px;text-align:center;border-top:1px solid #e2e8f0;">
                <p style="margin:0;font-size:12px;color:#94a3b8;">
                    {{ env('INVOICE_COMPANY_NAME', env('SITE_NAME', 'LinkSwitch Communications')) }}
                </p>
                <p style="margin:4px 0 0;font-size:11px;color:#cbd5e1;">
                    This is an automated message — please do not reply.
                </p>
            </td>
        </tr>

    </table>

</td></tr>
</table>

</body>
</html>
