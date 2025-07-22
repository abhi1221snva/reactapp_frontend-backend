<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
<style>
</style>
</head>
<body>
<div class="content">
    <table width="100%" style="max-width:800px;background:#fff;border-left:1px solid #e4e4e4;border-right:1px solid #e4e4e4;border-bottom:1px solid #e4e4e4;font-family:Arial,Helvetica,sans-serif" border="0" cellpadding="0" cellspacing="0" align="center">
        <tbody>
        <tr>
            <td style="border-top:solid 4px #dddddd;line-height:1">
                <table width="100%" border="0" cellspacing="0" cellpadding="0" style="border-bottom:1px solid #e4e4e4;border-top:none">
                    <tbody>
                    <tr>
                        <td align="left" valign="top">
                            <div style="padding: 7px 12px 8px 8px;">
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </td>
        </tr>
        <tr>
            <td align="left" valign="top">
                <div style="padding: 7px 12px 8px 8px;">
                    Your email OTP for signing up on Cafmotel is {{$data["code"]}}. This OTP is valid only for 15 mins.
                </div>
            </td>
        </tr>
        <tr>
            <td style="border-bottom:4px solid #dddddd;border-top:1px solid #dedede;padding:0 16px">
                <p style="color:#999999;margin:0;font-size:11px;padding:6px 0;font-family:Arial,Helvetica,sans-serif">
                    ©Copyright <?php echo date('Y'); ?>  Cafmotel Inc.
                </p>
            </td>
        </tr>
        </tbody>
    </table>
</div>
</body>
</html>
