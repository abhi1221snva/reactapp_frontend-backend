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
            <p align="left" style="margin-top: -8px;"><font size="2" face="Arial, Helvetica, sans-serif">Hello Super Admin,            <br><br>
                 
                Our Artificial Intelligent System has detected {{$data['percentage_label']}} in drop ratio to {{$data['percentage']}}% for campaign : {{$data['campaignTitle']}}. In Order to lower the drop ratio, predictive campaign has been automatically set to the best calibrated values for the campaign.

                Our Artificial Intelligent System has detected {{$data['percentage_label']}} in drop ratio of {{$data['percentage']}}% for campaign : {{$data['campaignTitle']}}. In Order to lower the drop ratio, predictive campaign has been automatically set to the best calibration values (Time delay in dialing next leads - {{$data['duration']}} sec).
                
            <br><br>
            Thanks<br>
            Dialler Team
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
            
        </div>
    </div>
</body>



</html>
