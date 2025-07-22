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
    </style>
</head>
<body>
    <div class="content">
        <h3>Campaign Name: {{ $data["campaignTitle"] }}</h3>

        <div style="padding: 20px;">
            <div class="make_strong">List Name: {{ $data["listName"] }}</div>
            @if(isset($data["records"]))
            <div>Number of leads uploaded: {{ $data["records"] }}</div>
            @endif
            @if(isset($data["columns"]))
            <br/>
            <div style="margin-top: 20px;">
                <div class="make_strong">Columns:</div>
                <ul>
                    @foreach($data["columns"] as $column)
                        <li>{{ $column["header"] }}</li>
                    @endforeach
                </ul>
            </div>
            @endif
        </div>
    </div>
</body>
</html>
