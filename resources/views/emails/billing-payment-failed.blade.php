<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payment Failed</title>
<style>
  body { margin: 0; padding: 0; background: #f1f5f9; font-family: 'Segoe UI', Arial, sans-serif; }
  .wrapper { max-width: 620px; margin: 32px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
  .header { background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%); padding: 36px 40px; text-align: center; }
  .header h1 { margin: 0; color: #ffffff; font-size: 22px; font-weight: 700; letter-spacing: -0.3px; }
  .header p { margin: 6px 0 0; color: rgba(255,255,255,0.80); font-size: 14px; }
  .body { padding: 36px 40px; }
  .body p { margin: 0 0 18px; color: #374151; font-size: 15px; line-height: 1.65; }
  .highlight-box { background: #fee2e2; border-left: 4px solid #dc2626; border-radius: 0 8px 8px 0; padding: 16px 20px; margin: 20px 0; }
  .highlight-box p { margin: 0; color: #991b1b; font-size: 14px; font-weight: 500; }
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
    <h1>Payment Failed</h1>
    <p>Rocket Dialer</p>
  </div>

  <div class="body">
    <p>Hello {{ $recipientName }},</p>

    <p>We were unable to process your subscription payment for <strong>{{ $companyName }}</strong>.</p>

    <div class="highlight-box">
      <div class="detail-row">
        <span class="detail-label">Plan:</span>
        <span class="detail-value">{{ $notificationData['plan_name'] ?? 'N/A' }}</span>
      </div>
      <div class="detail-row">
        <span class="detail-label">Amount Due:</span>
        <span class="detail-value">${{ number_format(($notificationData['amount_due'] ?? 0) / 100, 2) }}</span>
      </div>
      @if(!empty($notificationData['invoice_id']))
      <div class="detail-row">
        <span class="detail-label">Invoice:</span>
        <span class="detail-value">{{ $notificationData['invoice_id'] }}</span>
      </div>
      @endif
    </div>

    <p>Please update your payment method to avoid service interruption. Stripe will automatically retry the payment, but if all retries fail, your subscription will be suspended.</p>

    @if(!empty($notificationData['billing_url']))
    <p>
      <a href="{{ $notificationData['billing_url'] }}" class="cta-btn">Update Payment Method</a>
    </p>
    @endif

    <p>If you believe this is an error, please contact our support team.</p>

    <p>
      Best regards,<br>
      <em style="color:#94a3b8;font-size:13px;">Rocket Dialer Team</em>
    </p>
  </div>

  <div class="footer">
    <p>
      This is an automated notification from Rocket Dialer.<br>
      You received this because a subscription payment could not be processed.
    </p>
  </div>

</div>
</body>
</html>
