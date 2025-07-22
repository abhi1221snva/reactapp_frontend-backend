<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
    <style>
        .content {
            padding: 10px;
        }
        .make_strong {
            font-weight: bold;
        }
        .invalid {
            color: darkred;
        }
        .info-label {
            font-weight: bold;
        }
        .info-value {
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="content">
        <h3>{{ $subject }}</h3>
        <div style="padding: 20px; width: 100%;">
            <table>
                <tbody>
                <tr>
                    <td class="info-label">Server:</td>
                    <td class="info-value">{{ $data["server_ip"] }}</td>
                </tr>
                <tr>
                    <td class="info-label">User:</td>
                    <td class="info-value">{{ $data["user"] }}</td>
                </tr>
                <tr>
                    <td class="info-label">IP Address:</td>
                    <td class="info-value">{{ $data["whitelist_ip"] }}</td>
                </tr>
                <tr>
                    <td class="info-label">Location:</td>
                    <td class="info-value">{{ $data["ip_location"] }}</td>
                </tr>
                @if(isset($data["approvedBy"]))
                <tr>
                    <td class="info-label">Approved By:</td>
                    <td class="info-value">{{ $data["approvedBy"] }}</td>
                </tr>
                @endif
                </tbody>
            </table>

            <br/>To open IP setting click <a href="{{ env("APP_URL") }}/ip-setting">here</a>.
        </div>
    </div>
</body>
</html>
