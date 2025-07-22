<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
    <style>
        .logo {
            padding: 10px;
            background-color: #f8f8f4;
            display: flex;
        }

        .logo p {
            font-family: sans-serif;
            font-size: 20px;
        }

        .content {
            padding: 10px;
        }

        .make_strong {
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="content">
        {!! $body !!}
    </div>
</body>
</html>
