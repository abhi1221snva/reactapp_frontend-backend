<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error</title>
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
            <td colspan="2" width="100%">
            <table cellpadding="10" width="100%" border="0">
            <tbody>
            <tr>
            <td>
            <p align="left" style="margin-top: -8px;"><font size="2" face="Arial, Helvetica, sans-serif">Hello Admin,            <br><br>
                 
                Error On Email Or SMS .
                
                

            
            <table class="css" style="width: 100%;">
                <tbody>
                @foreach($context as $field => $value)
                                    <tr>
                                        <td class="css"> {{ $field }}</td>
                                        @if( is_array($value))
                                            <td class="css"><pre>{{ json_encode($value, JSON_PRETTY_PRINT) }}</pre></td>
                                        @else
                                            <td class="css"> {{ $value }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                
                
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
