<x-layouts.app title="Choose a new password">
    <div class="login-wrap">
        <section class="panel login-card">
            <div class="login-brand"><img src="{{ asset('betnabiso-logo.jpeg') }}" alt="Betnabiso"></div>
            <p class="eyebrow">Account recovery</p>
            <h1>Choose a new password</h1>
            <p class="lead">Use at least 12 characters with uppercase, lowercase, a number and a symbol.</p>
            <form method="post" action="{{ route('password.update') }}" style="margin-top:28px">
                @csrf
                <input name="token" type="hidden" value="{{ $token }}">
                <div class="field" style="margin-bottom:18px">
                    <label for="email">Email address</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="email" required autofocus>
                </div>
                <div class="field" style="margin-bottom:18px">
                    <label for="password">New password</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" required>
                </div>
                <div class="field">
                    <label for="password_confirmation">Confirm new password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
                </div>
                <div class="actions"><button class="button" type="submit">Reset password <span aria-hidden="true">→</span></button></div>
            </form>
            <p class="login-help">Reset links expire after 60 minutes and can be used only once.</p>
        </section>
    </div>
</x-layouts.app>
