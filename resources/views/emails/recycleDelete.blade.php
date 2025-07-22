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

    <style>
table, td, th {  
  border: 1px solid #ddd;
  text-align: left;
}

table {
  border-collapse: collapse;
  width: 100%;
}

th, td {
  padding: 15px;
}
</style>
</head>
<body>
    <div class="content">
        

        <div style="padding: 20px;">
            @if(isset($data["campaignTitle"]))
            <div class="make_strong">Campaign Name: {{ $data["campaignTitle"] }}</div>
            @endif
            @if(isset($data["listName"]))
            <div class="make_strong">List Name: {{ $data["listName"] }}</div>
            @endif

            <h3>Leads Recycled </h3>

            <table>
                <thead>
                    <th>S.No</th>
                    <th>Disposition</th>
                    <th>Count</th>
                </thead>
                <tbody>
                    @if(!empty($data["disposition_result"]))
                    @php
                    $total = 0;
                    @endphp
                    @foreach($data["disposition_result"] as $key=> $result)
                    <tr>
                        <th>{{$loop->iteration}}</th>
                        <th>{{$key}}</th>
                        <th>{{$result}}</th>
                    </tr>
                    @php
                    $total = $total + $result;
                    $s_no = $loop->iteration;
                    @endphp
                    @endforeach
                    @if(!empty($data["disposition_zero_title"]))
                    <tr>
                        <th>{{++$s_no}}</th>
                        <th>{{ $data["disposition_zero_title"] }}</th>
                        <th>{{ $data["disposition_zero_value"] }}</th>
                    </tr>
                    <tr><th>leads Recycled</th><th></th><th>{{$total+$data["disposition_zero_value"]}}</th></tr>
                    @else
                     <tr><th>leads Recycled</th><th></th><th>{{$total}}</th></tr>
                    @endif
                    @endif
                </tbody>
            </table>
            
            
            
        </div>
    </div>
</body>
</html>
