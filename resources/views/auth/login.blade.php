<x-layouts.app title="Sign in">
    <div class="login-wrap">
        <section class="panel login-card">
            <div class="login-brand"><img src="{{ asset('betnabiso-logo.jpeg') }}" alt="Betnabiso"></div>
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
                <div class="auth-options">
                    <label class="check-option" for="remember">
                        <input id="remember" name="remember" type="checkbox" value="1" @checked(old('remember'))>
                        <span>Remember me</span>
                    </label>
                    <a href="{{ route('password.request') }}">Forgot password?</a>
                </div>
                <div class="actions"><button class="button" type="submit">Sign in securely <span aria-hidden="true">→</span></button></div>
            </form>
            <p class="login-help">Private system · Access is limited to authorised team members</p>
        </section>
    </div>
</x-layouts.app>
