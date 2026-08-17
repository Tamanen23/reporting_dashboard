<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#080909">
    <title>{{ $title ?? 'Report Automation' }} · Betnabiso</title>
    <link rel="icon" type="image/jpeg" href="{{ asset('betnabiso-logo.jpeg') }}">
    <link rel="apple-touch-icon" href="{{ asset('betnabiso-logo.jpeg') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800,900" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <header class="app-header">
        <div class="nav-shell">
            <a class="brand" href="{{ auth()->check() ? route('reports.create') : route('login') }}">
                <span class="brand-mark"><img src="{{ asset('betnabiso-logo.jpeg') }}" alt=""></span>
                <span><span class="brand-name">BETNABISO</span><span class="brand-product">Reporting workspace</span></span>
            </a>
            @auth
                <div class="nav-side">
                    <nav class="nav-links" aria-label="Primary navigation">
                        <a class="nav-link {{ request()->routeIs('reports.create') ? 'active' : '' }}" href="{{ route('reports.create') }}">Generate</a>
                        <a class="nav-link {{ request()->routeIs('reports.index') || request()->routeIs('reports.show') ? 'active' : '' }}" href="{{ route('reports.index') }}" target="_blank">Reports</a>
                        <a class="nav-link {{ request()->routeIs('reports.pipeline') ? 'active' : '' }}" href="{{ route('reports.pipeline') }}" target="_blank">Pipeline</a>
                        @if(auth()->user()->is_admin)<a class="nav-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}" href="{{ route('admin.users.index') }}" target="_blank">Users</a>@endif
                    </nav>
                    <form method="post" action="{{ route('logout') }}">@csrf<button class="signout" type="submit">Sign out</button></form>
                </div>
            @endauth
        </div>
    </header>
    <main class="page-shell">
        @if(session('success'))<div class="notice success" role="status"><span>✓</span><div>{{ session('success') }}</div></div>@endif
        @if(session('warning'))<div class="notice warning" role="status"><span>!</span><div>{{ session('warning') }}</div></div>@endif
        @if($errors->any())
            <div class="notice error" role="alert"><span>!</span><div><strong>Please review the highlighted information</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>
        @endif
        {{ $slot }}
    </main>
</body>
</html>
