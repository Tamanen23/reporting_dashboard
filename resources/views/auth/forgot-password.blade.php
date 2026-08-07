<x-layouts.app title="Forgot password">
    <div class="login-wrap">
        <section class="panel login-card">
            <div class="login-brand"><img src="{{ asset('betnabiso-logo.jpeg') }}" alt="Betnabiso"></div>
            <p class="eyebrow">Account recovery</p>
            <h1>Reset your password</h1>
            <p class="lead">Enter your account email and we will send you a secure reset link.</p>
            <form method="post" action="{{ route('password.email') }}" style="margin-top:28px">
                @csrf
                <div class="field">
                    <label for="email">Email address</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
                </div>
                <div class="actions"><button class="button" type="submit">Send reset link <span aria-hidden="true">→</span></button></div>
            </form>
            <p class="login-help"><a href="{{ route('login') }}">← Return to sign in</a></p>
        </section>
    </div>
</x-layouts.app>
