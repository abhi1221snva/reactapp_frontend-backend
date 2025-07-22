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
   <table width="100%">
            <tbody>
            <tr>
            <td>
            <table cellspacing="12" cellpadding="2" bgcolor="#FFFFFF" align="center" width="600" border="0">
            <tbody>
            <tr>
            <td style="padding:12px;border-bottom-color:rgb(75,121,147);border-bottom-width:1px;border-bottom-style:solid" bgcolor="#ffffff">
            <table cellspacing="0" cellpadding="0" width="100%" border="0"><tbody><tr><td valign="middle" width="20%"><img src="<?php echo env('SITE_NAME_LOGO'); ?>" alt="Logo" height="54" class="CToWUd">
            </td>
            <td valign="middle" align="right" width="54%">
            </td>
            <td valign="middle" width="26%">
            </td>
            </tr>
            </tbody>
            </table>
            </td>
            </tr>
            <tr>
            <td colspan="2" width="580px">
            <table cellpadding="10" width="580px" border="0">
            <tbody>
            <tr>
            <td>
            <p align="left"><font size="2" face="Arial, Helvetica, sans-serif">Dear User            <br><br>
            Your email OTP for signing up on <?php echo env('SITE_NAME'); ?> is {{$data["Link"]["code"]}}. This OTP is valid only for 15 mins.
            <br>

            Please do not reply to this message. Replies are routed to an unmonitored mailbox.
            <br><br>
            For any queries, please write to <a href="mailto:sales@<?php echo env('SITE_NAME'); ?>.com" target="_blank">sales@<?php echo env('SITE_NAME'); ?>.com</a>
            <br><br>
            Regards<br>
        Support Team<br>
        <?php echo env('SITE_NAME'); ?>
            </font>
            </p>
            </td>
            </tr>
            <tr>
            <td style="/*border-bottom:4px solid #dddddd;*/border-top:1px solid #dedede;padding:0 16px">
                <p style="color:#999999;margin:0;font-size:11px;padding:6px 0;font-family:Arial,Helvetica,sans-serif">
                    ©Copyright <?php echo date('Y'); ?>  <?php echo env('SITE_NAME'); ?> Inc.
                </p>
            </td>
        </tr>
            </tbody>
            </table>
            </td>
            </tr>
            </tbody>
            </table>
            </td>
            </tr>
            </tbody>
            </table>
</div>
</body>
</html>
