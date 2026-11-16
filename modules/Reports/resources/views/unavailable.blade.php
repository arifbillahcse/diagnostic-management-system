<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Report not available</title>
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; background: #F3F7F5; color: #10231F; display: grid; place-items: center; min-height: 100vh; }
        main { max-width: 30rem; padding: 2rem; text-align: center; }
        h1 { font-size: 1.375rem; margin: 0 0 .75rem; }
        p { color: #4A5F59; line-height: 1.6; margin: 0; }
    </style>
</head>
<body>
<main>
    <h1>The report for {{ $order->order_no }} cannot be printed yet</h1>
    <p>{{ $message }}</p>
</main>
</body>
</html>
