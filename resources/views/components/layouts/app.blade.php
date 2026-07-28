<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Betnabiso Report Automation' }}</title>
    <style>
        :root{color-scheme:dark;--gold:#d7a928;--bg:#090909;--panel:#131313;--line:#3d3d3d;--muted:#aaa}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:#f4f1e8;font-family:Inter,Arial,sans-serif}
        nav{height:70px;border-bottom:1px solid var(--gold);display:flex;align-items:center;justify-content:space-between;padding:0 5vw;background:#0d0d0d}
        nav a{color:#ddd;text-decoration:none;margin-right:24px}.brand{font-weight:900;color:var(--gold);font-size:22px;letter-spacing:1px}
        main{width:min(1180px,92vw);margin:36px auto}.panel{background:var(--panel);border:1px solid var(--line);border-top:3px solid var(--gold);padding:28px;border-radius:5px}
        h1,h2{margin-top:0}h1{font-size:30px}h2{color:var(--gold)}label{display:block;color:#ccc;font-weight:700;margin-bottom:8px}
        input,select{width:100%;padding:12px;background:#090909;border:1px solid #555;color:#fff;border-radius:4px}
        .grid{display:grid;grid-template-columns:repeat(2,1fr);gap:20px}.full{grid-column:1/-1}.actions{margin-top:24px}
        button,.button{display:inline-block;border:0;background:var(--gold);color:#111;padding:12px 20px;font-weight:800;border-radius:4px;text-decoration:none;cursor:pointer}
        .secondary{background:#333;color:#fff}.notice{padding:14px;margin:0 0 20px;border:1px solid #555;background:#171717}.error{border-color:#b94c4c;color:#ffaaaa}.success{border-color:#338b67;color:#9ce4c2}.warning{border-color:#b28a24;color:#f2d477}
        table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:12px;border-bottom:1px solid #333}th{color:var(--gold)}
        .badge{display:inline-block;padding:5px 9px;border-radius:20px;background:#333;font-size:12px}.muted{color:var(--muted);font-size:14px}
        .progress{height:10px;background:#2c2c2c;border-radius:8px;overflow:hidden}.progress span{display:block;height:100%;background:var(--gold)}
        @media(max-width:700px){.grid{grid-template-columns:1fr}.full{grid-column:auto}nav{padding:0 4vw}}
    </style>
</head>
<body>
<nav><div><a class="brand" href="{{ route('reports.create') }}">BETNABISO REPORTS</a>@auth<a href="{{ route('reports.create') }}">Generate</a><a href="{{ route('reports.index') }}">History</a>@endauth</div>
@auth<form method="post" action="{{ route('logout') }}">@csrf<button class="secondary">Sign out</button></form>@endauth</nav>
<main>
    @if(session('success'))<div class="notice success">{{ session('success') }}</div>@endif
    @if(session('warning'))<div class="notice warning">{{ session('warning') }}</div>@endif
    @if($errors->any())<div class="notice error"><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    {{ $slot }}
</main>
</body>
</html>
