<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <title>Welcome to Voiptella</title>
    <style>
      body {
        font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        background-color: #f4f4f4;
        margin: 0;
        padding: 0;
        color: #333333;
      }
      .container {
        max-width: 600px;
        margin: 40px auto;
        background-color: #ffffff;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
      }
      .header {
        background-color: #eef0ff;
        padding: 10px!important;
        text-align: center;
        border-bottom: 1px solid #dddddd;
      }
      .header img {
        height: 75px;
        display: block;
        margin: 0 auto;
      }
      .content {
        padding: 30px!important;
      }
      .content h1 {
        color: #0e0c5c;
        font-size: 24px;
        margin-top: 0;
      }
      .content p {
        font-size: 16px;
        line-height: 1.6;
        color: #333333;
      }
      .features {
        margin: 20px 0;
        padding-left: 20px;
      }
      .features li {
        font-size: 15px;
        margin-bottom: 8px;
      }
      .button {
        display: inline-block;
        margin: 25px 0;
        padding: 14px 24px;
        background-color: #1d4ed8;
        color: #ffffff !important;
        text-decoration: none;
        border-radius: 6px;
        font-weight: 600;
      }
      .footer {
        background-color: #eef0ff;
        text-align: center;
        padding: 20px !important;
        font-size: 13px;
        color: #666666;
        width:630px!important;
        border-top: 1px solid #dddddd;
      }
      .footer a {
        color: #0e0c5c;
        text-decoration: none;
        font-weight: 500;
      }
    </style>
  </head>
  <body>
    <div class="container">
      <!-- Header -->
      <div class="header">
    
        <img src="{{ url('logo/logo_white.png') }}" alt="Voiptella" />

      </div>

      <!-- Email Content -->
      <div class="content">
        <h1>Welcome to Voiptella, {{ $user->name }}! 🎉</h1>
        <p>Thanks for joining us!</p>

        <p>
          <strong>Voiptella</strong> uses <strong>AI-powered call analysis</strong> to help you track, understand,
          and improve your team's performance on every call. From detecting key behaviors to identifying training
          opportunities, you're now equipped with the insights that drive better customer conversations.
        </p>

     

        <div style="text-align: center;">
          <a href="{{ url('/') }}" class="button">Start Exploring Voiptella</a>
        </div>

        <p>
          If you have any questions or need assistance, just reply to this email — we’re here to help!
        </p>

        <p>
          Thanks again for choosing Voiptella,<br />
          <strong>– The Voiptella Team</strong>
        </p>
      </div>

      <!-- Footer -->
      <div class="footer">
        &copy; {{ date('Y') }} CallChex. All rights reserved.<br />
        <a href="https://cafmotel.com/">Visit our website</a> 
        You are receiving this email because you signed up for a Dialer account.
      </div>
    </div>
  </body>
</html>
