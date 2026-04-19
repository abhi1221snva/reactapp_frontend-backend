<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New Funding Application</title>
<style>
  body { margin: 0; padding: 0; background: #f1f5f9; font-family: 'Segoe UI', Arial, sans-serif; }
  .wrapper { max-width: 620px; margin: 32px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
  .header { background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%); padding: 36px 40px; text-align: center; }
  .header h1 { margin: 0; color: #ffffff; font-size: 22px; font-weight: 700; letter-spacing: -0.3px; }
  .header p { margin: 6px 0 0; color: rgba(255,255,255,0.80); font-size: 14px; }
  .body { padding: 36px 40px; }
  .body p { margin: 0 0 18px; color: #374151; font-size: 15px; line-height: 1.65; }
  .highlight-box { background: #f5f3ff; border-left: 4px solid #6366f1; border-radius: 0 8px 8px 0; padding: 16px 20px; margin: 20px 0; }
  .highlight-box p { margin: 0; color: #4338ca; font-size: 14px; font-weight: 500; }
  .note-box { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 16px 20px; margin: 20px 0; }
  .note-box p { margin: 0; color: #92400e; font-size: 14px; }
  .attachment-note { display: flex; align-items: center; gap: 10px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px 18px; margin: 20px 0; }
  .attachment-note span { color: #166534; font-size: 13px; font-weight: 500; }
  .footer { background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 24px 40px; text-align: center; }
  .footer p { margin: 0; color: #94a3b8; font-size: 12px; line-height: 1.6; }
</style>
</head>
<body>
<div class="wrapper">

  <div class="header">
    <h1>New Funding Application</h1>
    <p>Submitted by {{ $companyName }}</p>
  </div>

  <div class="body">
    <p>Hello,</p>

    <p>
      Please find attached a funding application from <strong>{{ $businessName }}</strong>,
      submitted by <strong>{{ $senderName }}</strong>.
    </p>

    <div class="highlight-box">
      <p>Business Name: {{ $businessName }}</p>
    </div>

    @if($customNote)
    <div class="note-box">
      <p><strong>Note from submitter:</strong><br>{{ $customNote }}</p>
    </div>
    @endif

    <div class="attachment-note">
      <span>📎 The completed funding application PDF is attached to this email.</span>
    </div>

    <p>
      Please review the application at your earliest convenience. If you have any questions
      or require additional documentation, please reply to this email.
    </p>

    <p>Thank you for your continued partnership.</p>

    <p>
      Best regards,<br>
      <strong>{{ $senderName }}</strong><br>
      <em style="color:#94a3b8;font-size:13px;">{{ $companyName }}</em>
    </p>
  </div>

  <div class="footer">
    <p>
      This message was sent automatically by {{ $companyName }}.<br>
      Please do not reply to this automated message unless directed to do so.
    </p>
  </div>

</div>
</body>
</html>
