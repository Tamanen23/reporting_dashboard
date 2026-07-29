<x-layouts.app title="Sign in">
    <div class="login-wrap">
        <section class="panel login-card">
            <div class="login-brand" aria-hidden="true">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><path d="M7 10V8a5 5 0 0 1 10 0v2M6 10h12a1 1 0 0 1 1 1v8H5v-8a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M12 14v2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </div>
            <p class="eyebrow">Secure workspace</p>
            <h1>Welcome back</h1>
            <p class="lead">Sign in to generate, review and download operational reports.</p>
            <form method="post" action="{{ route('login.store') }}" style="margin-top:28px">
                @csrf
                <div class="field" style="margin-bottom:18px">
                    <label for="email">Email address</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                </div>
                <div class="actions"><button class="button" type="submit">Sign in securely <span aria-hidden="true">→</span></button></div>
            </form>
            <p class="login-help">Private system · Access is limited to authorised team members</p>
        </section>
    </div>
</x-layouts.app>
