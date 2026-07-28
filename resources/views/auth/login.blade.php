<x-layouts.app title="Sign in">
    <section class="panel" style="max-width:480px;margin:70px auto">
        <h1>Sign in</h1><p class="muted">Access private Betnabiso report generation.</p>
        <form method="post" action="{{ route('login.store') }}">@csrf
            <div style="margin:20px 0"><label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus></div>
            <div style="margin:20px 0"><label for="password">Password</label><input id="password" name="password" type="password" required></div>
            <button>Sign in</button>
        </form>
    </section>
</x-layouts.app>
