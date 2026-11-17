<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Reports · {{ $clinic['name'] }}</title>
    <style>
        :root { --ink: #16211c; --muted: #5b6862; --line: #d9dedb; --brand: #1f6f54; --bg: #f4f6f5; --ok: #1f7a4d; --wait: #a15c07; }
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Arial, sans-serif; color: var(--ink); margin: 0; background: var(--bg); font-size: 16px; line-height: 1.5; }
        header { background: #fff; border-bottom: 1px solid var(--line); padding: 14px 16px; }
        header .in { max-width: 520px; margin: 0 auto; display: flex; gap: 12px; align-items: center; }
        header img { max-height: 44px; max-width: 80px; }
        header strong { font-size: 18px; display: block; }
        header small { color: var(--muted); }
        main { max-width: 520px; margin: 24px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
        h1 { font-size: 20px; margin: 0 0 6px; }
        p { margin: 0 0 12px; }
        .muted { color: var(--muted); font-size: 14px; }
        label { display: block; font-weight: 600; margin: 12px 0 4px; }
        input { width: 100%; font-size: 17px; padding: 11px 12px; border: 1px solid var(--line); border-radius: 8px; }
        input:focus { outline: 2px solid var(--brand); border-color: var(--brand); }
        .btn { display: block; width: 100%; text-align: center; background: var(--brand); color: #fff; border: 0; border-radius: 8px; padding: 13px; font-size: 17px; font-weight: 600; margin-top: 16px; text-decoration: none; cursor: pointer; }
        .btn.light { background: #fff; color: var(--brand); border: 1px solid var(--brand); }
        .error { background: #fdecec; color: #8a1c1c; border-radius: 8px; padding: 10px 12px; margin-bottom: 12px; font-size: 15px; }
        ul.list { list-style: none; padding: 0; margin: 8px 0 0; }
        ul.list li { display: flex; justify-content: space-between; gap: 10px; padding: 8px 0; border-top: 1px solid var(--line); }
        .ok { color: var(--ok); font-weight: 600; white-space: nowrap; }
        .wait { color: var(--wait); font-weight: 600; white-space: nowrap; }
        footer { text-align: center; color: var(--muted); font-size: 13px; padding: 8px 16px 32px; }
        button.link { background: none; border: 0; color: var(--muted); text-decoration: underline; font-size: 14px; cursor: pointer; padding: 0; }
    </style>
</head>
<body>
    <header><div class="in">
        @if ($clinic['logo'])<img src="{{ $clinic['logo'] }}" alt="">@endif
        <div><strong>{{ $clinic['name'] }}</strong><small>{{ $clinic['phone'] }}</small></div>
    </div></header>
    <main>@yield('content')</main>
    <footer>{{ $clinic['address'] }}</footer>
</body>
</html>
