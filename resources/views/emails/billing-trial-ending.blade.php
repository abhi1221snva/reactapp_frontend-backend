<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Your Trial is Ending Soon</title>
<style>
  body { margin: 0; padding: 0; background: #f1f5f9; font-family: 'Segoe UI', Arial, sans-serif; }
  .wrapper { max-width: 620px; margin: 32px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
  .header { background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%); padding: 36px 40px; text-align: center; }
  .header h1 { margin: 0; color: #ffffff; font-size: 22px; font-weight: 700; letter-spacing: -0.3px; }
  .header p { margin: 6px 0 0; color: rgba(255,255,255,0.80); font-size: 14px; }
  .body { padding: 36px 40px; }
  .body p { margin: 0 0 18px; color: #374151; font-size: 15px; line-height: 1.65; }
  .highlight-box { background: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 0 8px 8px 0; padding: 16px 20px; margin: 20px 0; }
  .highlight-box p { margin: 0; color: #78350f; font-size: 14px; font-weight: 500; }
  .detail-row { margin: 6px 0; }
  .detail-label { color: #6b7280; font-size: 13px; display: inline-block; min-width: 120px; }
  .detail-value { color: #1e293b; font-size: 14px; font-weight: 600; }
  .cta-btn { display: inline-block; background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%); color: #ffffff !important; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-size: 14px; font-weight: 600; margin: 8px 0 20px; }
  .footer { background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 24px 40px; text-align: center; }
  .footer p { margin: 0; color: #94a3b8; font-size: 12px; line-height: 1.6; }
</style>
</head>
<body>
<div class="wrapper">

  <div class="header">
    <h1>Your Trial is Ending Soon</h1>
    <p>Rocket Dialer</p>
  </div>

  <div class="body">
    <p>Hello {{ $recipientName }},</p>

    <p>Your free trial for <strong>{{ $companyName }}</strong> is ending in <strong>{{ $notificationData['days_remaining'] }} {{ $notificationData['days_remaining'] == 1 ? 'day' : 'days' }}</strong>.</p>

    <div class="highlight-box">
      <div class="detail-row">
        <span class="detail-label">Current Plan:</span>
        <span class="detail-value">{{ $notificationData['plan_name'] ?? 'Starter' }}</span>
      </div>
      <div class="detail-row">
        <span class="detail-label">Expires On:</span>
        <span class="detail-value">{{ $notificationData['expires_at'] }}</span>
      </div>
      <div class="detail-row">
        <span class="detail-label">Wallet Balance:</span>
        <span class="detail-value">${{ number_format($notificationData['wallet_balance'] ?? 0, 2) }}</span>
      </div>
    </div>

    <p>To continue using all features without interruption, please add a payment method and choose a plan before your trial ends.</p>

    @if(!empty($notificationData['billing_url']))
    <p>
      <a href="{{ $notificationData['billing_url'] }}" class="cta-btn">Choose a Plan</a>
    </p>
    @endif

    <p>After your trial expires, you'll have a 3-day grace period with read-only access to your data.</p>

    <p>
      Best regards,<br>
      <em style="color:#94a3b8;font-size:13px;">Rocket Dialer Team</em>
    </p>
  </div>

  <div class="footer">
    <p>
      This is an automated notification from Rocket Dialer.<br>
      You received this because your trial period is ending soon.
    </p>
  </div>

</div>
</body>
</html>
