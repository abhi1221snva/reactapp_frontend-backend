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
        <h3>Campaign: {{ $data["campaignTitle"] }}</h3>
        <div class="make_strong">Minimum Hopper Setting: {{ $data["min_lead_temp"] }}</div>
        <div class="make_strong">Maximum Hopper Setting: {{ $data["max_lead_temp"] }}</div>
        <div class="make_strong">Leads In Hopper: {{ $data["hopperCount"]["valid"] }}</div>
        @if($data["hopperCount"]["invalid"] > 0)
            <div class="invalid">Note: {{ $data["hopperCount"]["invalid"] }} leads removed from hopper as they failed campaign time validation.</div>
        @endif

        @foreach($data["lists"] as $listId => $listData)
        <div style="padding: 20px;">
            <div class="make_strong">List: {{ $listData["title"] }}</div>
            <div>Total leads in list: {{ $listData["records"] }}</div>
            <div>Leads passing campaign validation: {{ $listData["valid"] }}</div>
            <div>Leads with duplicate value in dialing column: {{ $listData["duplicates"] }}</div>
        </div>
        @endforeach
    </div>
</body>
</html>
