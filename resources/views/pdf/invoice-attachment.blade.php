<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; margin: 0; }
        .attachment-title { font-size: 12px; font-weight: bold; margin: 0 0 8px; text-align: center; }
        .attachment-img {
            display: block;
            max-width: 100%;
            max-height: 255mm;
            margin: 0 auto;
        }
    </style>
</head>
<body>
<div>
    <div class="attachment-title">{{ $page['title'] }}</div>
    @if (!empty($page['data_uri']))
        <img class="attachment-img" src="{{ $page['data_uri'] }}" alt="{{ $page['title'] }}">
    @endif
</div>
</body>
</html>
