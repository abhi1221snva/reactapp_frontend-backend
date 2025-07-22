<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{$subject}}</title>
    <style>
         .css {  
  border: 1px solid #ddd;
  text-align: left;
  border-collapse: collapse;
  
}




.css th, td {
  padding: 8px;
}
    </style>
</head>
<body>
  
    
    <div class="content">
        <div style="padding: 0px; width: 100%;">

          <table width="100%">
            <tbody>
            <tr>
            <td>
            <table cellspacing="12" cellpadding="2" bgcolor="#FFFFFF" align="" width="600" border="0">
            <tbody>
            <tr>
            <td style="padding:12px;border-bottom-color:rgb(75,121,147);border-bottom-width:1px;border-bottom-style:solid" bgcolor="#ffffff">
            <table cellspacing="0" cellpadding="0" width="100%" border="0"><tbody><tr><td valign="middle" width="20%"><img src="{{env('SITE_NAME_LOGO')}}" alt="Logo" height="54" class="CToWUd">
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
            <p align="left"><font size="2" face="Arial, Helvetica, sans-serif">Dear {{$firstName}} {{$lastName}}
            <br><br>
            As per your request, we have sent you the password reset link. Click on the following link to reset your password:
            <br>
            <a style="border-radius: 100px;
            font-size: 14px;
            font-weight: bold;
            line-height: 50px;
            padding: 6px 16px;
            text-align: center;
            white-space: nowrap;
            background-color: #1da1f2;
            border: 1px solid #1da1f2;
            color: #fff;
            text-decoration: none;" href="{{ $resetLink }}}">Reset Password</a>
            <br>
         
            Remember this link is valid only for 30 minutes.Please reset your password before it expires.We hope you'll find our service easy and convenient to use.
            <br><br>
            Please do not reply to this message. Replies are routed to an unmonitored mailbox.
            <br><br>
            Thanks<br>
            {{env('INVOICE_COMPANY_NAME')}}<br>
            </font>
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
    </div>
</body>



</html>
