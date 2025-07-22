<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
    <style>
        /*.content {
            padding: 10px;
        }
        .make_strong {
            font-weight: bold;
        }
        .invalid {
            color: darkred;
        }
        .css {
            font-weight: bold;
        }
        .css {
            text-align: right;
        }*/
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
        <!-- <h3>{{ $data["action"] }}</h3> -->
        <div style="padding: 0px; width: 100%;">

          <table cellspacing="12" cellpadding="2" bgcolor="#FFFFFF" align="center" width="100%" border="0">
            <tbody>
            <tr>
            <td style="padding:12px;border-bottom-color:rgb(75,121,147);border-bottom-width:1px;border-bottom-style:solid" bgcolor="#ffffff">
            <table cellspacing="0" cellpadding="0" width="100%" border="0"><tbody><tr><td valign="middle" width="20%"><img src="{{env('PORTAL_NAME')}}logo/logo_white.png" alt="Logo" height="54" class="CToWUd">
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
            <td colspan="2" width="100%">
            <table cellpadding="10" width="100%" border="0">
            <tbody>
            <tr>
            <td>
            <p align="left" style="margin-top: -8px;"><font size="2" face="Arial, Helvetica, sans-serif">Hello Admin,            <br><br>
                 
                @if($data["action"] == "Extension added")
                Extension has been created in your account with below details.
                @else
                Extension has been deleted from your account with below details.
                @endif
                

            
            <table class="css" style="width: 50%;">
                <tbody>
                <tr>
                    <th class="css">Name</th>
                    <td class="css">{{ $data["userInfo"]["first_name"] }} {{ $data["userInfo"]["last_name"] }}</td>
                </tr>
                <tr>
                    <th class="css">Extension</th>
                    <td class="css">{{ $data["userInfo"]["extension"] }}</td>
                </tr>
                <tr>
                    <th class="css">Web Extension</th>
                    <td class="css">{{ $data["userInfo"]["alt_extension"] }}</td>
                </tr>
                <tr>
                    <th class="css">Email</th>
                    <td class="css">{{ $data["userInfo"]["email"] }}</td>
                </tr>
                <tr>
                    <th class="css">SIP Domian</th>
                    <td class="css">{{ $data["userInfo"]["asteriskServer"] }}</td>
                </tr>
                <tr>
                    <th class="css">Follow Me</th>
                    <td class="css">{{ $data["userInfo"]["followMe"] }}</td>
                </tr>
                <tr>
                    <th class="css">Call Forward</th>
                    <td class="css">{{ $data["userInfo"]["callForward"] }}</td>
                </tr>
                <tr>
                    <th class="css">VoiceMail</th>
                    <td class="css">{{ $data["userInfo"]["voicemail"] }}</td>
                </tr>
                <tr>
                    <th class="css">VoiceMail Pin</th>
                    <td class="css">{{ $data["userInfo"]["vm_pin"] }}</td>
                </tr>
                <tr>
                    <th class="css">Send Voicemail to email</th>
                    <td class="css">{{ $data["userInfo"]["voicemailSendToEmail"] }}</td>
                </tr>
                <tr>
                    <th class="css">Twinning</th>
                    <td class="css">{{ $data["userInfo"]["twinning"] }}</td>
                </tr>
                <tr>
                    <th class="css">Mobile</th>
                    <td class="css">{{ $data["userInfo"]["mobile"] }}</td>
                </tr>
                <tr>
                    <th class="css">Group</th>
                    <td class="css">{{ $data["userInfo"]["groups"] }}</td>
                </tr>
                <tr>
                    <th class="css">CLI Setting</th>
                    <td class="css">{{ $data["userInfo"]["cliSetting"] }}</td>
                </tr>
                <tr>
                    <th class="css">Custom CLI</th>
                    <td class="css">
                        @if($data["userInfo"]["cli"]==0)
                        N/A
                        @else
                        {{ $data["userInfo"]["cli"] }}
                        @endif
                    </td>
                </tr>
                <tr>
                    <th class="css">CNAM</th>
                    <td class="css">
                        @if($data["userInfo"]["cnam"]==0 && $data["userInfo"]["cnam"]==NULL )
                        N/A
                        @else
                        {{ $data["userInfo"]["cnam"] }}
                        @endif</td>
                </tr>
                </tbody>
            </table>
            <br><br>
            Thanks<br>
            {{env('INVOICE_COMPANY_NAME')}}
            </font>
            </p>
            </td>
            </tr>
           <!--  <tr>
            <td style="/*border-bottom:4px solid #dddddd;*/border-top:1px solid #dedede;padding:0 16px">
                <p style="color:#999999;margin:0;font-size:11px;padding:6px 0;font-family:Arial,Helvetica,sans-serif">
                    ©Copyright <?php echo date('Y'); ?>  <?php echo env('SITE_NAME'); ?> Inc.
                </p>
            </td>
        </tr> -->
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
