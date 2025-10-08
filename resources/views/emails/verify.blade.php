<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Email Verification</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
</head>
<body style="margin:0; padding:0; background-color:#f4f6f8; font-family: Arial, sans-serif;">
    <table width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#f4f6f8">
        <tr>
            <td align="center" style="padding: 20px 0;">
                <!-- Outer Container -->
                <table width="600" border="0" cellspacing="0" cellpadding="0" bgcolor="#ffffff" style="border-radius: 10px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.1);">
                    
                    <!-- Header with Logo -->
                    <tr>
                        <td bgcolor="#2d3748" align="center" style="padding: 20px;">
                            <img src="{{env('LINKSWITCH_LOGO')}}" alt="Company Logo" style="display:block; max-width:150px; height:auto;">
                        </td>
                    </tr>
                    
                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 30px; color:#333333; font-size:16px; line-height:1.6;">
                            <h2 style="color:#2d3748; margin-top:0;">Welcome to Our Portal!</h2>
                            <p>Thank you for registering. Please use the verification code below to verify your email address:</p>
                            
                            <!-- Verification Code -->
                            <p style="text-align:center; margin:30px 0;">
                                <span style="display:inline-block; font-size:28px; font-weight:bold; color:#2d3748; letter-spacing:4px; background:#f1f5f9; padding:15px 30px; border-radius:6px;">
                                    {{ $code }}
                                </span>
                            </p>

                            <p>This code will expire in <strong>5 minutes</strong>.</p>
                            <p>If you did not request this, you can safely ignore this email.</p>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td bgcolor="#f9fafb" align="center" style="padding: 20px; font-size:13px; color:#999999;">
                            &copy; {{ date('Y') }} 
                            <a href="{{ env('LINKSWITCH_URL') }}" style="color:#2d3748; text-decoration:none; font-weight:bold;">
                                {{ env('LINKSWITCH_NAME') }}
                            </a> 
                            . All rights reserved.<br>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
