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


   

    <table width="100%" style="background:#fff;border-left:1px solid #e4e4e4;border-right:1px solid #e4e4e4;border-bottom:1px solid #e4e4e4;font-family:Arial,Helvetica,sans-serif" border="0" cellpadding="0" cellspacing="0" align="center">
        <tbody>
        <tr>
            <td style="width: 50%;">
                <p style="padding: 8px;
    border: 1px solid #ddd;
    padding-top: 12px;
    padding-bottom: 12px;
    text-align: left;
    background-color: #444444;
    color: white;">
                    <strong>Total RVM calls Easify (API Toekn - bc6c)</strong>
                </p>
                <div style="clear:both;padding:11px 7px 12px;margin-bottom:8px;border:1px solid #f5f5f5;border-radius:3px;background:#fff">
                    <table style="font-family:Arial, Helvetica, sans-serif;border-collapse: collapse;width: 100%;">
                        @foreach($data as $values)
                        <tr>
                            <td style="border: 1px solid #ddd;padding: 8px;">{{$values->status}}</td>
                            <td style="border: 1px solid #ddd;padding: 8px;">{{$values->count}}</td>

                           
                        </tr>
                        @endforeach
                       
                    </table>
                </div>
            </td>

           
        </tr>

    </tbody>
</table>










    <table width="100%" style="background:#fff;border-left:1px solid #e4e4e4;border-right:1px solid #e4e4e4;border-bottom:1px solid #e4e4e4;font-family:Arial,Helvetica,sans-serif" border="0" cellpadding="0" cellspacing="0" align="center">
        <tbody>
    
        <tr>
            <td style="border-bottom:4px solid #dddddd;border-top:1px solid #dedede;padding:0 16px">
                <p style="color:#999999;margin:0;font-size:11px;padding:6px 0;font-family:Arial,Helvetica,sans-serif">
                    © Copyright <?php echo date('Y'); ?>  Easify.
                </p>
            </td>
        </tr>
        </tbody>
    </table>
</div>
</body>
</html>




